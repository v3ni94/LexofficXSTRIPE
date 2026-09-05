<?php
/**
 * Bestehende Einzüge aus Stripe übernehmen (Einmal-Import).
 *
 * Zweck: Wurde die Anwendung neu aufgesetzt oder eine Firma neu verknüpft,
 * kennt sie die Lastschriften nicht, die eine frühere Installation bereits über
 * dasselbe Stripe-Konto eingereicht hat. Die betroffenen Rechnungen stünden als
 * offen und könnten erneut eingezogen werden. Der Import liest die PaymentIntents
 * des Stripe-Kontos für einen Zeitraum (nur Lesezugriff), ordnet sie über die
 * Rechnungsnummer aus den Stripe-Metadaten (voucher_number) und den Betrag den
 * Rechnungen zu und legt für eindeutige Treffer Einzugsdatensätze mit Herkunft
 * 'import' an. Nichts wird bei Stripe verändert, kein Geld bewegt sich.
 *
 * Ablauf: start (Lauf anlegen) -> fetch (seitenweise laden, Zeitbudget, Cursor)
 * -> Vorschau -> apply (nur match_state 'matched', mit 2FA-Zweitbestätigung).
 * Bekannte PaymentIntents werden übersprungen, der Import ist wiederholbar.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/collections.php';

const STRIPE_IMPORT_MAX_MONTHS = 24;

/** Laufenden Import der Firma (loading oder preview) liefern. */
function stripe_import_current(string $tenantId): ?array
{
    $stmt = db()->prepare("SELECT * FROM stripe_imports WHERE tenant_id = ? AND status IN ('loading', 'preview') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

function stripe_import_load(string $tenantId, string $importId): ?array
{
    $stmt = db()->prepare('SELECT * FROM stripe_imports WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$importId, $tenantId]);
    return $stmt->fetch() ?: null;
}

/** Letzte Importläufe der Firma (Protokoll). */
function stripe_import_recent(string $tenantId, int $limit = 5): array
{
    $stmt = db()->prepare('SELECT * FROM stripe_imports WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/** Neuen Import anlegen; ein noch offener Lauf derselben Firma wird verworfen. */
function stripe_import_start(string $tenantId, int $months, ?array $actor = null): array
{
    $months = max(1, min(STRIPE_IMPORT_MAX_MONTHS, $months));
    $pdo = db();
    $pdo->prepare("UPDATE stripe_imports SET status = 'discarded', finished_at = NOW() WHERE tenant_id = ? AND status IN ('loading', 'preview')")
        ->execute([$tenantId]);
    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO stripe_imports (id, tenant_id, status, period_months, created_gte, created_by_user_id)
         VALUES (?, ?, \'loading\', ?, DATE_SUB(NOW(), INTERVAL ? MONTH), ?)'
    )->execute([$id, $tenantId, $months, $months, $actor['user_id'] ?? null]);
    audit_log($tenantId, $actor, 'collections_import_started', 'stripe_import', $id, ['zeitraum_monate' => $months]);
    return stripe_import_load($tenantId, $id);
}

/** Offenen Lauf verwerfen. */
function stripe_import_discard(string $tenantId, string $importId, ?array $actor = null): void
{
    $upd = db()->prepare("UPDATE stripe_imports SET status = 'discarded', finished_at = NOW() WHERE id = ? AND tenant_id = ? AND status IN ('loading', 'preview')");
    $upd->execute([$importId, $tenantId]);
    if ($upd->rowCount() === 1) {
        audit_log($tenantId, $actor, 'collections_import_discarded', 'stripe_import', $importId);
    }
}

/**
 * Seiten von Stripe laden und Positionen klassifizieren, bis alles geladen ist
 * oder das Zeitbudget erschöpft ist. Der Cursor bleibt gespeichert, ein
 * weiterer Aufruf setzt fort.
 * @return array{pages:int,items:int,done:bool}
 */
function stripe_import_fetch(array $import, StripeClient $stripe, int $budgetSeconds = 15): array
{
    $pdo = db();
    $deadline = microtime(true) + max(2, $budgetSeconds);
    $stats = ['pages' => 0, 'items' => 0, 'done' => false];
    if ($import['status'] !== 'loading') {
        $stats['done'] = true;
        return $stats;
    }
    $cursor = $import['cursor_pi'] ?: null;
    $createdGte = (int)strtotime((string)$import['created_gte']);
    try {
        do {
            $page = $stripe->listPaymentIntents($createdGte, $cursor, 100);
            $stats['pages']++;
            $last = null;
            foreach ($page['data'] as $pi) {
                stripe_import_store_item($import, $pi);
                $stats['items']++;
                $last = (string)($pi['id'] ?? '');
            }
            $cursor = $last ?: $cursor;
            $hasMore = $page['has_more'] && $last !== null;
            $pdo->prepare('UPDATE stripe_imports SET cursor_pi = ?, pages_fetched = pages_fetched + 1, fetched_count = fetched_count + ?, last_error = NULL WHERE id = ?')
                ->execute([$cursor, count($page['data']), $import['id']]);
            if (!$hasMore) {
                $pdo->prepare("UPDATE stripe_imports SET status = 'preview' WHERE id = ? AND status = 'loading'")->execute([$import['id']]);
                $stats['done'] = true;
                break;
            }
        } while (microtime(true) < $deadline);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE stripe_imports SET last_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 2000), $import['id']]);
        throw $e;
    }
    return $stats;
}

/** Stripe-Zeitstempel (Unix) als DATETIME-String. */
function _stripe_import_dt(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Eine Stripe-Zahlung klassifizieren und als Position speichern (idempotent je Lauf).
 * Zuordnung ausschließlich über metadata.voucher_number und den Betrag.
 */
function stripe_import_store_item(array $import, array $pi): array
{
    $pdo = db();
    $tenantId = (string)$import['tenant_id'];
    $piId = (string)($pi['id'] ?? '');
    if ($piId === '') {
        return ['match_state' => 'not_ours'];
    }
    $meta = (array)($pi['metadata'] ?? []);
    $voucher = trim((string)($meta['voucher_number'] ?? ''));
    $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : null;
    $chargeId = is_string($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : (string)($charge['id'] ?? '');
    $amount = (int)($pi['amount'] ?? 0);
    $currency = strtoupper((string)($pi['currency'] ?? 'eur'));
    $refunded = (int)($charge['amount_refunded'] ?? 0);
    $disputed = !empty($charge['disputed']) ? 1 : 0;
    $failure = $pi['last_payment_error']['message'] ?? ($charge['failure_message'] ?? null);

    $state = 'matched';
    $invoiceId = null;
    if ($voucher === '') {
        $state = 'not_ours';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM payment_collections WHERE tenant_id = ? AND stripe_payment_intent_id = ? LIMIT 1');
        $stmt->execute([$tenantId, $piId]);
        if ($stmt->fetchColumn()) {
            $state = 'already_known';
        } else {
            $stmt = $pdo->prepare('SELECT id, total_gross_amount, currency FROM invoices WHERE tenant_id = ? AND voucher_number = ? ORDER BY created_at DESC LIMIT 2');
            $stmt->execute([$tenantId, $voucher]);
            $invoices = $stmt->fetchAll();
            if (count($invoices) !== 1) {
                $state = 'invoice_missing';
            } else {
                $inv = $invoices[0];
                $invoiceId = (string)$inv['id'];
                $expected = (int)round((float)$inv['total_gross_amount'] * 100);
                if ($currency !== 'EUR' || $amount !== $expected) {
                    $state = 'amount_mismatch';
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT id FROM payment_collections WHERE tenant_id = ? AND invoice_id = ? AND source = 'app'
                           AND stripe_status IN ('scheduled', 'submitting', 'processing', 'succeeded') LIMIT 1"
                    );
                    $stmt->execute([$tenantId, $invoiceId]);
                    if ($stmt->fetchColumn()) {
                        $state = 'invoice_has_collection';
                    }
                }
            }
        }
    }
    $pdo->prepare(
        'INSERT IGNORE INTO stripe_import_items
            (id, import_id, tenant_id, payment_intent_id, stripe_created_at, amount_cents, currency, pi_status, charge_id,
             amount_refunded_cents, disputed, failure_message, voucher_number, customer_number, mandate_reference, description,
             match_state, invoice_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        uuid4(), $import['id'], $tenantId, $piId, _stripe_import_dt((int)($pi['created'] ?? time())), $amount, substr($currency, 0, 3),
        mb_substr((string)($pi['status'] ?? 'unknown'), 0, 40), $chargeId !== '' ? mb_substr($chargeId, 0, 255) : null,
        $refunded, $disputed, $failure !== null ? mb_substr((string)$failure, 0, 255) : null,
        $voucher !== '' ? mb_substr($voucher, 0, 50) : null,
        isset($meta['customer_number']) ? mb_substr((string)$meta['customer_number'], 0, 50) : null,
        isset($meta['mandate_reference']) ? mb_substr((string)$meta['mandate_reference'], 0, 35) : null,
        isset($pi['description']) ? mb_substr((string)$pi['description'], 0, 255) : null,
        $state, $invoiceId,
    ]);
    return ['match_state' => $state, 'invoice_id' => $invoiceId];
}

/** Positionen eines Laufs, Treffer zuerst, dann nach Datum. */
function stripe_import_items(string $tenantId, string $importId, bool $includeNotOurs = false): array
{
    $sql = "SELECT it.*, i.voucher_number AS invoice_voucher, i.contact_name, i.collection_status AS invoice_collection_status
            FROM stripe_import_items it LEFT JOIN invoices i ON i.id = it.invoice_id
            WHERE it.import_id = ? AND it.tenant_id = ?" . ($includeNotOurs ? '' : " AND it.match_state <> 'not_ours'") . "
            ORDER BY FIELD(it.match_state, 'matched', 'amount_mismatch', 'invoice_has_collection', 'invoice_missing', 'already_known', 'not_ours'), it.stripe_created_at DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$importId, $tenantId]);
    return $stmt->fetchAll();
}

/** Zusammenfassung je Zuordnungsergebnis. */
function stripe_import_summary(string $tenantId, string $importId): array
{
    $stmt = db()->prepare('SELECT match_state, COUNT(*) AS cnt, SUM(amount_cents) AS cents FROM stripe_import_items WHERE import_id = ? AND tenant_id = ? GROUP BY match_state');
    $stmt->execute([$importId, $tenantId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['match_state']] = ['count' => (int)$r['cnt'], 'cents' => (int)$r['cents']];
    }
    return $out;
}

function stripe_import_state_label(string $state): string
{
    return [
        'matched' => 'Zuordnung gefunden, wird übernommen',
        'already_known' => 'Bereits bekannt, wird übersprungen',
        'invoice_missing' => 'Rechnung nicht im Programm (Rechnungsnummer unbekannt oder mehrdeutig)',
        'amount_mismatch' => 'Betrag oder Währung passt nicht zur Rechnung',
        'invoice_has_collection' => 'Rechnung hat bereits einen eigenen Einzug',
        'not_ours' => 'Ohne Rechnungsnummer (nicht aus dieser Anwendung)',
    ][$state] ?? $state;
}

/** Stripe-Status eines PaymentIntent auf den lokalen Einzugsstatus abbilden. */
function stripe_import_map_status(string $piStatus, bool $disputed): string
{
    if ($disputed) {
        return 'disputed';
    }
    return match ($piStatus) {
        'succeeded' => 'succeeded',
        'processing' => 'processing',
        'canceled' => 'cancelled',
        'requires_payment_method' => 'failed',
        default => 'processing',
    };
}

/**
 * Vorschau übernehmen: für jede Position 'matched' einen Einzugsdatensatz mit
 * Herkunft 'import' anlegen und den Rechnungsstatus setzen (zeitlich letzte
 * Zahlung je Rechnung entscheidet). Erstattungen werden wie beim Webhook
 * verarbeitet (Rechnung zur Klärung markiert). Idempotent: bereits übernommene
 * Positionen und bekannte PaymentIntents werden übersprungen.
 * @return array{imported:int,skipped:int,refunded:int}
 */
function stripe_import_apply(array $import, ?array $actor = null): array
{
    $pdo = db();
    $tenantId = (string)$import['tenant_id'];
    if (!in_array($import['status'], ['preview'], true)) {
        throw new RuntimeException('Der Import ist nicht im Zustand Vorschau.');
    }
    $stmt = $pdo->prepare("SELECT * FROM stripe_import_items WHERE import_id = ? AND tenant_id = ? AND match_state = 'matched' AND collection_id IS NULL ORDER BY stripe_created_at ASC");
    $stmt->execute([$import['id'], $tenantId]);
    $items = $stmt->fetchAll();
    $result = ['imported' => 0, 'skipped' => 0, 'refunded' => 0];
    $lastPerInvoice = [];

    foreach ($items as $it) {
        $known = $pdo->prepare('SELECT id FROM payment_collections WHERE tenant_id = ? AND stripe_payment_intent_id = ? LIMIT 1');
        $known->execute([$tenantId, $it['payment_intent_id']]);
        if ($known->fetchColumn() || $it['invoice_id'] === null) {
            $result['skipped']++;
            continue;
        }
        $status = stripe_import_map_status((string)$it['pi_status'], (int)$it['disputed'] === 1);
        $collectionId = uuid4();
        $completed = in_array($status, ['succeeded', 'failed', 'cancelled', 'disputed'], true) ? $it['stripe_created_at'] : null;
        $pdo->prepare(
            "INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency, stripe_payment_intent_id, stripe_charge_id,
                 stripe_status, submitted_at, completed_at, failure_reason, description, note, source, imported_mandate_reference, created_by_user_id, created_at)
             VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?, ?, ?)"
        )->execute([
            $collectionId, $tenantId, $it['invoice_id'], (int)$it['amount_cents'], $it['currency'], $it['payment_intent_id'], $it['charge_id'],
            $status, $it['stripe_created_at'], $completed,
            $status === 'failed' || $status === 'disputed' ? ($it['failure_message'] ?: ($status === 'disputed' ? 'Rücklastschrift (aus Stripe übernommen)' : 'Lastschrift fehlgeschlagen (aus Stripe übernommen)')) : null,
            $it['description'] !== null ? mb_substr((string)$it['description'], 0, 140) : null,
            'Aus Stripe übernommen am ' . date('d.m.Y') . ' (frühere Installation)',
            $it['mandate_reference'], $actor['user_id'] ?? null, $it['stripe_created_at'],
        ]);
        $pdo->prepare('UPDATE stripe_import_items SET collection_id = ? WHERE id = ?')->execute([$collectionId, $it['id']]);
        $lastPerInvoice[$it['invoice_id']] = ['status' => $status, 'collection_id' => $collectionId, 'refunded' => (int)$it['amount_refunded_cents'], 'charge' => $it['charge_id']];
        $result['imported']++;
    }

    foreach ($lastPerInvoice as $invoiceId => $last) {
        $invStatus = match ($last['status']) {
            'succeeded' => 'collected',
            'processing' => 'in_collection',
            'failed', 'disputed' => 'failed',
            default => 'open',
        };
        $pdo->prepare('UPDATE invoices SET collection_status = ? WHERE id = ? AND tenant_id = ?')->execute([$invStatus, $invoiceId, $tenantId]);
        if ($last['refunded'] > 0 && $last['status'] === 'succeeded') {
            $col = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ?');
            $col->execute([$last['collection_id']]);
            if ($row = $col->fetch()) {
                collection_apply_refund($tenantId, $row, $last['refunded'], $last['charge'] ?: null, $actor, 'import');
                $result['refunded']++;
            }
        }
    }

    $pdo->prepare("UPDATE stripe_imports SET status = 'done', imported_count = imported_count + ?, finished_at = NOW() WHERE id = ?")
        ->execute([$result['imported'], $import['id']]);
    audit_log($tenantId, $actor, 'collections_imported', 'stripe_import', (string)$import['id'], $result + ['zeitraum_monate' => (int)$import['period_months']]);
    return $result;
}
