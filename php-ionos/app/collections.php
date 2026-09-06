<?php
/**
 * SEPA-Einzüge: sofortiger Einzug, Terminierung, Stornierung, Umterminierung
 * sowie Verarbeitung fälliger terminierter Einzüge (Cron).
 *
 * Jeder Einzug wird der auslösenden Person zugeordnet (created_by_user_id
 * und Audit-Log), geprüft gegen Tarifkontingent, Mandatsstatus (Unterschrift,
 * 36-Monats-Verfall) und Vorabankündigungsregel der Firma.
 *
 * Sicherheitsnetz vor jedem Stripe-Aufruf (Reihenfolge, siehe docs/payment-safety.md):
 *  1. Not-Stopp (plattformweit und je Firma),
 *  2. Kontingent, Rechnungs-, Kunden- und Mandatsprüfungen,
 *  3. Restbetrag laut Lexware Office (Payments-Endpunkt), Einzug nur über den
 *     offenen Betrag abzüglich eigener laufender oder erfolgreicher Einzüge,
 *  4. Versuchsjournal mit Idempotenz-Schlüssel (collection_attempts), kein
 *     zweiter Versuch, solange ein Versuch offen oder unklar ist.
 * Erstattungen aus Stripe (collection_apply_refund) setzen die Rechnung auf
 * Klärungsbedarf (invoices.requires_review); es gibt keinen automatischen Neu-Einzug.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/keywords.php';
require_once __DIR__ . '/mandates.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/invoice_source.php';

class CollectionException extends RuntimeException {}

/** Mandat nicht verwendbar (z.B. verfallen); trägt die Mandats-ID für die Nachbehandlung außerhalb der Transaktion. */
class MandateUnusableException extends CollectionException
{
    public ?string $mandateId = null;
}

/**
 * Einzug wurde zurückgestellt, nicht abgelehnt: Der terminierte Einzug bleibt
 * unverändert und wird beim nächsten Lauf erneut geprüft (z. B. Not-Stopp,
 * Restbetrag nicht abrufbar, Versuch läuft bereits).
 */
class CollectionDeferredException extends CollectionException {}

/** Stripe hat nicht geantwortet: Ergebnis unbekannt, Versuch als "unknown" vermerkt, keine Wiederholung ohne Klärung. */
class CollectionUnknownOutcomeException extends CollectionException {}

/** Gültigkeit eines gespeicherten Restbetrags für die Vorprüfung ohne API-Aufruf. */
const OPEN_AMOUNT_CACHE_HOURS = 24;

/**
 * Platzhalter-Kontakt-E-Mail für Stripe, falls der Kunde keine E-Mail
 * hinterlegt hat (Stripe verlangt für das SEPA-Mandat eine E-Mail-Adresse).
 */
function _fallback_contact_email(): string
{
    $base = function_exists('app_base_url') ? app_base_url() : (string)config('base_url', '');
    $host = parse_url($base, PHP_URL_HOST);
    return 'noreply@' . ($host ?: 'example.invalid');
}

function _collection_org(string $tenantId): array
{
    $stmt = db()->prepare('SELECT * FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $org = $stmt->fetch();
    if (!$org) {
        throw new CollectionException('Firma nicht gefunden.');
    }
    return $org;
}

// ---------------------------------------------------------------------------
// Not-Stopp (plattformweit und je Firma)
// ---------------------------------------------------------------------------

/** Plattformweite Einstellung lesen (Tabelle platform_settings). Fehlt die Tabelle, gilt der Standardwert. */
function platform_setting(string $key, ?string $default = null): ?string
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM platform_settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return $value === false ? $default : ($value === null ? null : (string)$value);
}

/** Plattformweite Einstellung setzen (nur serverseitig, z. B. Not-Stopp per SQL oder Skript). */
function platform_setting_set(string $key, ?string $value): void
{
    db()->prepare(
        'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute([mb_substr($key, 0, 64), $value === null ? null : mb_substr($value, 0, 255)]);
}

/** true, wenn alle Einzüge plattformweit angehalten sind. */
function platform_collections_paused(): bool
{
    return platform_setting('collections_paused', '0') === '1';
}

/**
 * Grund, warum für diese Firma aktuell keine Lastschriften eingereicht
 * werden dürfen, oder null. Wird in jedem Einreichpfad geprüft.
 */
function collections_pause_reason(string $tenantId): ?string
{
    if (platform_collections_paused()) {
        return 'Not-Stopp: Der Betreiber hat alle SEPA-Einzüge vorübergehend angehalten. Es werden keine Lastschriften eingereicht.';
    }
    $stmt = db()->prepare('SELECT collections_paused FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    if ((int)$stmt->fetchColumn() === 1) {
        return 'Not-Stopp: Die Einzüge dieser Firma sind angehalten. Es werden keine Lastschriften eingereicht, bis der Not-Stopp unter "Not-Stopp" aufgehoben wird.';
    }
    return null;
}

/** Not-Stopp der Firma setzen oder aufheben (Inhaber und Administratoren, Audit). */
function collections_set_paused(string $tenantId, bool $paused, ?array $actor = null, string $reason = ''): void
{
    db()->prepare(
        'UPDATE organizations SET collections_paused = ?, collections_paused_at = ' . ($paused ? 'NOW()' : 'NULL') . ' WHERE id = ?'
    )->execute([$paused ? 1 : 0, $tenantId]);
    audit_log($tenantId, $actor, $paused ? 'collections_paused' : 'collections_resumed', 'organization', $tenantId, [
        'reason' => mb_substr(trim($reason), 0, 255),
    ]);
}

// ---------------------------------------------------------------------------
// Karenzzeit und Einreichfenster (Plattformregel, config 'collections')
//
// Ein Sofort-Einzug wird nicht mehr direkt an Stripe übergeben, sondern als
// "vorgemerkt" gespeichert (is_scheduled = 1, queued_immediate = 1) und
// frühestens nach der Karenzzeit innerhalb des Einreichfensters (Standard
// 23:00 bis 06:00) vom Cron eingereicht. Bis dahin ist er stornierbar.
// Terminierte Einzüge werden am Fälligkeitstag ebenfalls nur im Fenster
// eingereicht. Termine, die länger als overdue_days zurückliegen, werden nicht
// automatisch nachgeholt, sondern als überfällig angezeigt.
// ---------------------------------------------------------------------------

/** Einreichregeln mit Standardwerten. */
function collections_rules_config(): array
{
    $c = (array)config('collections', []);
    $time = static fn($v, string $default): string => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? (string)$v : $default;
    return [
        'grace_hours'    => max(0, min(72, (int)($c['grace_hours'] ?? 4))),
        'window_enabled' => (bool)($c['window_enabled'] ?? true),
        'window_start'   => $time($c['window_start'] ?? null, '23:00'),
        'window_end'     => $time($c['window_end'] ?? null, '06:00'),
        'overdue_days'   => max(1, min(30, (int)($c['overdue_days'] ?? 3))),
    ];
}

/** Aktuelle Zeit; in Tests über $GLOBALS['lexsepa_now_override'] setzbar. */
function collections_now(): DateTimeImmutable
{
    $override = $GLOBALS['lexsepa_now_override'] ?? null;
    return $override instanceof DateTimeImmutable ? $override : new DateTimeImmutable('now');
}

function _collections_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

/** True, wenn der Zeitpunkt im Einreichfenster liegt (Fenster darf über Mitternacht gehen). Ohne Fenster immer true. */
function collections_window_open(?DateTimeImmutable $at = null): bool
{
    $r = collections_rules_config();
    if (!$r['window_enabled']) {
        return true;
    }
    $at = $at ?? collections_now();
    $min = (int)$at->format('G') * 60 + (int)$at->format('i');
    $s = _collections_minutes($r['window_start']);
    $e = _collections_minutes($r['window_end']);
    if ($s === $e) {
        return true;
    }
    return $s < $e ? ($min >= $s && $min < $e) : ($min >= $s || $min < $e);
}

/** Beginn des nächsten Einreichfensters ab $at, oder $at selbst, wenn das Fenster offen ist. */
function collections_window_next_open(?DateTimeImmutable $at = null): DateTimeImmutable
{
    $at = $at ?? collections_now();
    if (collections_window_open($at)) {
        return $at;
    }
    [$h, $m] = array_map('intval', explode(':', collections_rules_config()['window_start']));
    $start = $at->setTime($h, $m, 0);
    if ($start <= $at) {
        $start = $start->modify('+1 day');
    }
    return $start;
}

/** Frühester Einreichzeitpunkt für einen jetzt ausgelösten Einzug: erst Karenzzeit, dann Einreichfenster. */
function collections_earliest_submit(?DateTimeImmutable $at = null): DateTimeImmutable
{
    $at = $at ?? collections_now();
    $r = collections_rules_config();
    return collections_window_next_open($at->modify('+' . $r['grace_hours'] . ' hours'));
}

/** True, wenn Sofort-Einzüge vorgemerkt statt direkt eingereicht werden (Karenzzeit oder Fenster aktiv). */
function collections_grace_active(): bool
{
    $r = collections_rules_config();
    return $r['grace_hours'] > 0 || $r['window_enabled'];
}

/** Lesbare Beschreibung der Regel für Hinweistexte. */
function collections_rules_text(): string
{
    $r = collections_rules_config();
    $parts = [];
    if ($r['grace_hours'] > 0) {
        $parts[] = sprintf('frühestens %d Stunden nach dem Auslösen', $r['grace_hours']);
    }
    if ($r['window_enabled']) {
        $parts[] = sprintf('nur im Einreichfenster %s bis %s Uhr', $r['window_start'], $r['window_end']);
    }
    return $parts ? 'Einreichung bei Stripe ' . implode(', ', $parts) . '. Bis zur Einreichung kann jeder Einzug storniert werden.' : 'Einzüge werden sofort bei Stripe eingereicht.';
}

/**
 * Überblick über noch nicht eingereichte Einzüge einer Firma.
 * @return array{total:int,total_cents:int,queued:int,due:int,overdue:int,future:int}
 */
function collections_pending_overview(string $tenantId): array
{
    $r = collections_rules_config();
    $now = collections_now();
    $cutoff = $now->modify('-' . $r['overdue_days'] . ' days')->format('Y-m-d');
    $nowStr = $now->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(amount_cents), 0) AS total_cents,
                COALESCE(SUM(queued_immediate = 1), 0) AS queued,
                COALESCE(SUM(scheduled_date < ?), 0) AS overdue,
                COALESCE(SUM(scheduled_date >= ? AND COALESCE(submit_not_before, scheduled_date) <= ?), 0) AS due,
                COALESCE(SUM(COALESCE(submit_not_before, scheduled_date) > ?), 0) AS future
         FROM payment_collections
         WHERE tenant_id = ? AND is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled'"
    );
    $stmt->execute([$cutoff, $cutoff, $nowStr, $nowStr, $tenantId]);
    $row = $stmt->fetch() ?: [];
    return [
        'total' => (int)($row['total'] ?? 0), 'total_cents' => (int)($row['total_cents'] ?? 0),
        'queued' => (int)($row['queued'] ?? 0), 'due' => (int)($row['due'] ?? 0),
        'overdue' => (int)($row['overdue'] ?? 0), 'future' => (int)($row['future'] ?? 0),
    ];
}

/** True, wenn ein noch nicht eingereichter Einzug als überfällig gilt (Termin älter als overdue_days). */
function collection_is_overdue(array $collection): bool
{
    if (!(int)$collection['is_scheduled'] || (int)$collection['scheduled_submitted'] || $collection['stripe_status'] !== 'scheduled') {
        return false;
    }
    $cutoff = collections_now()->modify('-' . collections_rules_config()['overdue_days'] . ' days')->format('Y-m-d');
    return (string)$collection['scheduled_date'] < $cutoff;
}

/**
 * Alle vorgemerkten und terminierten Einzüge einer Firma stornieren (z.B. beim
 * Not-Stopp). Bereits eingereichte oder gerade beanspruchte Einzüge bleiben unberührt.
 * @return array{cancelled:int,amount_cents:int}
 */
function collections_cancel_all_pending(string $tenantId, ?array $actor = null, string $reason = ''): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, invoice_id, amount_cents FROM payment_collections
         WHERE tenant_id = ? AND is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled'"
    );
    $stmt->execute([$tenantId]);
    $cancelled = 0;
    $cents = 0;
    foreach ($stmt->fetchAll() as $c) {
        $upd = $pdo->prepare("UPDATE payment_collections SET stripe_status = 'cancelled' WHERE id = ? AND tenant_id = ? AND stripe_status = 'scheduled' AND scheduled_submitted = 0");
        $upd->execute([$c['id'], $tenantId]);
        if ($upd->rowCount() !== 1) {
            continue; // inzwischen beansprucht oder eingereicht
        }
        $pdo->prepare("UPDATE invoices SET collection_status = 'open' WHERE id = ? AND tenant_id = ? AND collection_status = 'scheduled'")
            ->execute([$c['invoice_id'], $tenantId]);
        $cancelled++;
        $cents += (int)$c['amount_cents'];
    }
    if ($cancelled > 0) {
        audit_log($tenantId, $actor, 'collections_bulk_cancelled', 'organization', $tenantId, [
            'cancelled' => $cancelled, 'amount_cents' => $cents, 'reason' => mb_substr(trim($reason), 0, 255),
        ]);
    }
    return ['cancelled' => $cancelled, 'amount_cents' => $cents];
}

// ---------------------------------------------------------------------------
// Restbetrag laut Lexware Office
// ---------------------------------------------------------------------------

/**
 * Gespeicherter Restbetrag in Cent, sofern er innerhalb der letzten
 * OPEN_AMOUNT_CACHE_HOURS Stunden abgerufen wurde, sonst null.
 */
function invoice_open_amount_cached(array $invoice): ?int
{
    if (!isset($invoice['open_amount']) || $invoice['open_amount'] === null || empty($invoice['open_amount_fetched_at'])) {
        return null;
    }
    $fetched = strtotime((string)$invoice['open_amount_fetched_at']);
    if (!$fetched || $fetched < time() - OPEN_AMOUNT_CACHE_HOURS * 3600) {
        return null;
    }
    return (int)round((float)$invoice['open_amount'] * 100);
}

/**
 * Restbetrag der Rechnung live bei Lexware Office abrufen und speichern.
 * Wirft CollectionException, wenn der Abruf scheitert oder kein Betrag
 * geliefert wird (dann darf nicht eingezogen werden).
 *
 * @return array{open_cents:int,fetched_at:string,payment_status:?string}
 */
function invoice_fetch_open_amount(string $tenantId, array $invoice): array
{
    try {
        $source = invoice_source_for_tenant($tenantId);
        $payment = $source->getPayment((string)$invoice['lexoffice_invoice_id']);
    } catch (Throwable $e) {
        throw new CollectionException(
            'Der Restbetrag der Rechnung ' . $invoice['voucher_number'] . ' konnte nicht bei Lexware Office abgerufen werden ('
            . $e->getMessage() . '). Ohne bestätigten Restbetrag wird keine Lastschrift eingereicht.'
        );
    }
    if ($payment['open_amount'] === null) {
        throw new CollectionException(
            'Lexware Office hat für Rechnung ' . $invoice['voucher_number'] . ' keinen offenen Betrag geliefert. '
            . 'Ohne bestätigten Restbetrag wird keine Lastschrift eingereicht.'
        );
    }
    if ($payment['currency'] !== null && strtoupper($payment['currency']) !== 'EUR') {
        throw new CollectionException('Rechnung ' . $invoice['voucher_number'] . ' ist nicht in EUR; SEPA-Einzug nicht möglich.');
    }
    $openCents = (int)round($payment['open_amount'] * 100);
    $now = date('Y-m-d H:i:s');
    _persist_open_amount((string)$invoice['id'], $openCents, $now);
    // Merken: Wird die Einzugs-Transaktion anschließend zurückgerollt (z. B.
    // Teilzahlung ohne Bestätigung), schreibt submit_collection() den Wert erneut,
    // damit die Rechnungsseite Restbetrag und Abrufzeit anzeigen kann.
    $GLOBALS['lexsepa_open_amount_pending'][(string)$invoice['id']] = [$openCents, $now];
    return ['open_cents' => $openCents, 'fetched_at' => $now, 'payment_status' => $payment['payment_status']];
}

function _persist_open_amount(string $invoiceId, int $openCents, string $fetchedAt): void
{
    db()->prepare('UPDATE invoices SET open_amount = ?, open_amount_fetched_at = ? WHERE id = ?')
        ->execute([number_format($openCents / 100, 2, '.', ''), $fetchedAt, $invoiceId]);
}

/** Nach einem Rollback gemerkte Restbeträge erneut speichern (außerhalb der Transaktion). */
function _persist_pending_open_amounts(): void
{
    $pending = $GLOBALS['lexsepa_open_amount_pending'] ?? [];
    $GLOBALS['lexsepa_open_amount_pending'] = [];
    foreach ($pending as $invoiceId => [$cents, $at]) {
        try {
            _persist_open_amount((string)$invoiceId, (int)$cents, (string)$at);
        } catch (Throwable $e) {
            error_log('Restbetrag konnte nach Rollback nicht gespeichert werden: ' . $e->getMessage());
        }
    }
}

/**
 * Summe eigener Einzüge dieser Rechnung, die laufen, terminiert sind oder
 * erfolgreich waren (Cent). Diese Beträge sind bei Lexware Office unter
 * Umständen noch nicht als Zahlung verbucht und werden vom Restbetrag abgezogen.
 */
function invoice_own_collections_cents(string $tenantId, string $invoiceId, ?string $excludeCollectionId = null): int
{
    $sql = "SELECT COALESCE(SUM(amount_cents), 0) FROM payment_collections
            WHERE tenant_id = ? AND invoice_id = ? AND stripe_status IN ('processing', 'succeeded', 'scheduled', 'submitting')";
    $params = [$tenantId, $invoiceId];
    if ($excludeCollectionId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeCollectionId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** Vorprüfung ohne API-Aufruf: frischer gespeicherter Restbetrag von 0 blockiert sofort. */
function _precheck_stored_open_amount(array $invoice): void
{
    $cached = invoice_open_amount_cached($invoice);
    if ($cached !== null && $cached <= 0) {
        throw new CollectionException(sprintf(
            'Rechnung %s hat laut Lexware Office keinen offenen Restbetrag mehr (Stand %s). Es wird keine Lastschrift eingereicht. '
            . 'Bitte mit Lexware Office synchronisieren.',
            $invoice['voucher_number'], format_datetime($invoice['open_amount_fetched_at'])
        ));
    }
}

/**
 * Einzugsbetrag aus dem Live-Restbetrag bestimmen: Restbetrag abzüglich eigener
 * laufender oder erfolgreicher Einzüge. Weicht das Ergebnis vom bisherigen
 * Betrag ab, ist eine ausdrückliche Bestätigung über genau diesen Betrag nötig.
 *
 * @return array{amount_cents:int,note:?string,open_cents:int,fetched_at:string}
 */
function _determine_collection_amount(string $tenantId, array $invoice, int $expectedCents, ?int $confirmedCents, ?string $excludeCollectionId = null): array
{
    $live = invoice_fetch_open_amount($tenantId, $invoice);
    $own = invoice_own_collections_cents($tenantId, $invoice['id'], $excludeCollectionId);
    $amount = $live['open_cents'] - $own;

    if ($live['open_cents'] <= 0) {
        throw new CollectionException(sprintf(
            'Rechnung %s ist laut Lexware Office vollständig bezahlt (offener Betrag %s). Es wird keine Lastschrift eingereicht.',
            $invoice['voucher_number'], format_eur_cents($live['open_cents'])
        ));
    }
    if ($amount <= 0) {
        throw new CollectionException(sprintf(
            'Für Rechnung %s laufen bereits Einzüge über %s; der offene Betrag laut Lexware Office beträgt %s. '
            . 'Es bleibt kein Restbetrag für eine weitere Lastschrift.',
            $invoice['voucher_number'], format_eur_cents($own), format_eur_cents($live['open_cents'])
        ));
    }
    if ($amount > $expectedCents) {
        throw new CollectionException(sprintf(
            'Der offene Betrag laut Lexware Office (%s) ist höher als der Rechnungsbetrag im Portal (%s). '
            . 'Bitte zuerst mit Lexware Office synchronisieren.',
            format_eur_cents($amount), format_eur_cents($expectedCents)
        ));
    }
    $note = null;
    if ($amount !== $expectedCents) {
        if ($confirmedCents !== $amount) {
            throw new CollectionException(sprintf(
                'Für Rechnung %s ist laut Lexware Office nur noch ein Restbetrag von %s offen (Rechnungsbetrag %s%s). '
                . 'Bitte den Einzug über den Restbetrag ausdrücklich bestätigen.',
                $invoice['voucher_number'], format_eur_cents($amount), format_eur_cents($expectedCents),
                $own > 0 ? ', eigene Einzüge ' . format_eur_cents($own) : ''
            ));
        }
        $note = sprintf('Restbetrag laut Lexware Office %s vom %s (Rechnungsbetrag %s)',
            format_eur_cents($live['open_cents']), format_datetime($live['fetched_at']), format_eur_cents($expectedCents));
    }
    return ['amount_cents' => $amount, 'note' => $note, 'open_cents' => $live['open_cents'], 'fetched_at' => $live['fetched_at']];
}

// ---------------------------------------------------------------------------
// Versuchsjournal (collection_attempts) mit Idempotenz-Schlüssel
// ---------------------------------------------------------------------------

/**
 * Eigene Datenbankverbindung mit Autocommit: Der Versuch wird VOR dem
 * Stripe-Aufruf dauerhaft geschrieben und bleibt erhalten, auch wenn die
 * umgebende Einzugs-Transaktion zurückgerollt wird oder PHP abbricht.
 */
function _attempts_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Offene Versuche einer Firma, optional je Rechnung:
 *  - pending (Aufruf läuft) und unknown (Ergebnis bei Stripe unbekannt),
 *  - succeeded ohne zugehörigen Einzugsdatensatz ("verwaist"): Stripe hat den
 *    PaymentIntent angelegt, aber die Einzugs-Transaktion wurde danach
 *    zurückgerollt oder abgebrochen. Ohne diese Regel wäre die Rechnung
 *    sofort wieder einziehbar (Doppelbelastung).
 * Die Rechnung wird per LEFT JOIN geladen, damit ein Versuch auch dann
 * blockiert, wenn die Rechnung inzwischen aus der Liste entfernt wurde.
 */
function collection_attempts_open(string $tenantId, ?string $invoiceId = null): array
{
    $sql = "SELECT a.*, COALESCE(i.voucher_number, '(Rechnung nicht mehr vorhanden)') AS voucher_number
            FROM collection_attempts a
            LEFT JOIN invoices i ON i.id = a.invoice_id AND i.tenant_id = a.tenant_id
            WHERE a.tenant_id = ?
              AND (a.status IN ('pending', 'unknown')
                   OR (a.status = 'succeeded' AND a.stripe_payment_intent_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM payment_collections pc
                                       WHERE pc.tenant_id = a.tenant_id
                                         AND pc.stripe_payment_intent_id = a.stripe_payment_intent_id)))";
    $params = [$tenantId];
    if ($invoiceId !== null) {
        $sql .= ' AND a.invoice_id = ?';
        $params[] = $invoiceId;
    }
    $stmt = _attempts_db()->prepare($sql . ' ORDER BY a.created_at DESC');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Lesbarer Status eines offenen Versuchs (unbekannt | offen | verwaist). */
function collection_attempt_open_label(array $attempt): string
{
    return match ($attempt['status']) {
        'unknown'   => 'unbekannt',
        'succeeded' => 'ohne Einzugsdatensatz',
        default     => 'offen',
    };
}

/**
 * Versuch anlegen. Schlüssel = SHA-256 aus Firma, Rechnung, Betrag und
 * Versuchsnummer. Blockiert, wenn zu dieser Rechnung ein Versuch offen,
 * unklar oder verwaist ist (Eindeutigkeitsregel). Für terminierte Einzüge
 * wird die Einzugs-ID sofort vermerkt, damit die Klärung den bestehenden
 * Datensatz vervollständigt statt einen zweiten anzulegen.
 */
function collection_attempt_begin(string $tenantId, string $invoiceId, int $amountCents, ?string $userId, ?string $collectionId = null): array
{
    $adb = _attempts_db();
    $open = collection_attempts_open($tenantId, $invoiceId);
    if ($open) {
        $a = $open[0];
        throw new CollectionException(sprintf(
            'Für Rechnung %s ist ein Einzugsversuch vom %s mit Status "%s" nicht abgeschlossen. '
            . 'Bis zur Klärung (Einzüge > "Unklare Versuche prüfen") wird kein weiterer Versuch eingereicht.',
            $a['voucher_number'], format_datetime($a['created_at']), collection_attempt_open_label($a)
        ));
    }
    $stmt = $adb->prepare('SELECT COUNT(*) FROM collection_attempts WHERE tenant_id = ? AND invoice_id = ?');
    $stmt->execute([$tenantId, $invoiceId]);
    $attemptNo = (int)$stmt->fetchColumn() + 1;
    $key = hash('sha256', $tenantId . '|' . $invoiceId . '|' . $amountCents . '|' . $attemptNo);
    $id = uuid4();
    try {
        $adb->prepare(
            'INSERT INTO collection_attempts (id, tenant_id, invoice_id, collection_id, idempotency_key, amount_cents, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $tenantId, $invoiceId, $collectionId, $key, $amountCents, 'pending', $userId]);
    } catch (PDOException $e) {
        // Gleicher Schlüssel bereits vorhanden: paralleler Versuch, nicht doppelt einreichen.
        throw new CollectionDeferredException('Für diese Rechnung läuft bereits ein Einzugsversuch.');
    }
    return ['id' => $id, 'idempotency_key' => $key, 'attempt_no' => $attemptNo, 'collection_id' => $collectionId];
}

/** Ergebnis eines Versuchs festhalten (succeeded | failed | unknown). */
function collection_attempt_finish(string $attemptId, string $status, ?string $paymentIntentId = null, ?string $error = null, ?string $collectionId = null): void
{
    try {
        _attempts_db()->prepare(
            'UPDATE collection_attempts SET status = ?, stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id),
                    error_text = ?, collection_id = COALESCE(?, collection_id) WHERE id = ?'
        )->execute([$status, $paymentIntentId, $error !== null ? mb_substr($error, 0, 2000) : null, $collectionId, $attemptId]);
    } catch (Throwable $e) {
        error_log('Versuchsjournal konnte nicht aktualisiert werden (' . $attemptId . '): ' . $e->getMessage());
    }
}

/**
 * Stripe-Aufruf mit Versuchsjournal ausführen. Zeitüberschreitung oder
 * Netzwerkfehler setzen den Versuch auf "unknown" (Ergebnis bei Stripe
 * unbekannt, keine Wiederholung ohne Klärung); Ablehnungen durch Stripe auf
 * "failed" (neuer Versuch mit neuem Schlüssel erlaubt).
 *
 * @return array{attempt:array,result:array} result = [stripeCustomer, paymentMethod, paymentIntent]
 */
function _execute_with_attempt(
    StripeClient $stripe,
    string $tenantId,
    array $invoice,
    array $customer,
    array $iban,
    array $mandate,
    string $description,
    int $amountCents,
    ?string $userId,
    ?string $collectionId = null
): array {
    $attempt = collection_attempt_begin($tenantId, $invoice['id'], $amountCents, $userId, $collectionId);
    try {
        $result = _execute_stripe_collection(
            $stripe, $tenantId, $invoice, $customer, $iban, $mandate, $description, $amountCents,
            $attempt['idempotency_key']
        );
    } catch (StripeException $e) {
        if ($e->outcomeUnknown) {
            collection_attempt_finish($attempt['id'], 'unknown', null, $e->getMessage());
            throw new CollectionUnknownOutcomeException(
                'Die Verbindung zu Stripe wurde unterbrochen, bevor eine Antwort vorlag (' . $e->getMessage() . '). '
                . 'Ob die Lastschrift für Rechnung ' . $invoice['voucher_number'] . ' eingereicht wurde, ist unklar. '
                . 'Der Versuch ist als "unbekannt" vermerkt; bitte unter Einzüge > "Unklare Versuche prüfen" klären. '
                . 'Bis dahin wird diese Rechnung nicht erneut eingereicht.'
            );
        }
        collection_attempt_finish($attempt['id'], 'failed', null, $e->getMessage());
        throw $e;
    } catch (Throwable $e) {
        collection_attempt_finish($attempt['id'], 'failed', null, $e->getMessage());
        throw $e;
    }
    collection_attempt_finish($attempt['id'], 'succeeded', $result[2]['id'] ?? null);
    return ['attempt' => $attempt, 'result' => $result];
}

/**
 * Einzugsdatensatz zu einem bei Stripe gefundenen PaymentIntent herstellen:
 * Existiert bereits ein Einzug mit dieser PaymentIntent-ID, wird er
 * zurückgegeben. Trägt der Versuch eine Einzugs-ID (terminierter Einzug), wird
 * dieser Datensatz vervollständigt (PaymentIntent, Status processing). Sonst
 * wird ein neuer Einzug angelegt. Gibt die Einzugs-ID oder null zurück.
 */
function _attempt_backfill_collection(string $tenantId, array $attempt, array $pi): ?string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM payment_collections WHERE stripe_payment_intent_id = ? AND tenant_id = ?');
    $stmt->execute([$pi['id'], $tenantId]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (string)$existing;
    }

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$attempt['invoice_id'], $tenantId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        return null;
    }
    $amount = (int)($pi['amount'] ?? $attempt['amount_cents']);
    $note = 'Nachgetragen aus unklarem Versuch vom ' . format_datetime($attempt['created_at']);

    // Terminierter Einzug: bestehenden Datensatz vervollständigen
    if (!empty($attempt['collection_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$attempt['collection_id'], $tenantId]);
        $existingRow = $stmt->fetch();
        if ($existingRow && empty($existingRow['stripe_payment_intent_id'])) {
            $pdo->prepare(
                "UPDATE payment_collections
                 SET stripe_payment_intent_id = ?, stripe_status = 'processing', scheduled_submitted = IF(is_scheduled = 1, 1, scheduled_submitted),
                     amount_cents = ?, failure_reason = NULL, submitted_at = COALESCE(submitted_at, ?),
                     note = ?
                 WHERE id = ?"
            )->execute([
                $pi['id'], $amount, date('Y-m-d H:i:s', (int)($pi['created'] ?? time())),
                mb_substr(trim(($existingRow['note'] ?? '') . ' ' . $note), 0, 255), $existingRow['id'],
            ]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")->execute([$invoice['id']]);
            return (string)$existingRow['id'];
        }
    }

    $mandate = null;
    if ($invoice['customer_id']) {
        $stmt = $pdo->prepare(
            'SELECT * FROM sepa_mandates WHERE tenant_id = ? AND customer_id = ? ORDER BY is_active DESC, created_at DESC LIMIT 1'
        );
        $stmt->execute([$tenantId, $invoice['customer_id']]);
        $mandate = $stmt->fetch() ?: null;
    }
    if (!$mandate || empty($mandate['customer_iban_id'])) {
        return null;
    }
    $collectionId = uuid4();
    $pdo->prepare(
        'INSERT INTO payment_collections
            (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
             stripe_payment_intent_id, stripe_status, description, note, submitted_at, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $collectionId, $tenantId, $invoice['id'], $mandate['id'], $mandate['customer_iban_id'],
        $amount, 'EUR', $pi['id'], 'processing',
        mb_substr((string)($pi['description'] ?? ''), 0, 140), $note,
        date('Y-m-d H:i:s', (int)($pi['created'] ?? time())), $attempt['created_by_user_id'],
    ]);
    $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")->execute([$invoice['id']]);
    return $collectionId;
}

/**
 * Einen offenen Versuch mit einem bei Stripe vorliegenden PaymentIntent
 * abschließen (Einzug nachtragen, Versuch succeeded, Audit). Wird von der
 * Klärung und vom Webhook (PaymentIntent mit metadata.attempt_key, aber ohne
 * Einzugsdatensatz) verwendet.
 */
function collection_attempt_recover(string $tenantId, array $attempt, array $pi, ?array $actor = null): ?string
{
    $collectionId = _attempt_backfill_collection($tenantId, $attempt, $pi);
    collection_attempt_finish($attempt['id'], 'succeeded', (string)$pi['id'], null, $collectionId);
    audit_log($tenantId, $actor, 'collection_attempt_recovered', 'collection', $collectionId ?: $attempt['id'], [
        'attempt_id' => $attempt['id'], 'payment_intent' => $pi['id'], 'amount_cents' => (int)$attempt['amount_cents'],
    ]);
    return $collectionId;
}

/**
 * Unklare Versuche klären (reiner Lesezugriff bei Stripe):
 *  - "unknown" und "pending" älter als 15 Minuten: Suche bei Stripe nach einem
 *    PaymentIntent mit dem Schlüssel des Versuchs (metadata.attempt_key).
 *    Gefunden: Einzug nachtragen, Versuch succeeded. Nicht gefunden und Versuch
 *    älter als 10 Minuten (Suchindex): Versuch failed, neuer Einzug möglich.
 *  - "succeeded" ohne Einzugsdatensatz (verwaist): PaymentIntent direkt per ID
 *    abrufen und Einzug nachtragen.
 *
 * @return array{checked:int,recovered:int,cleared:int,pending:int}
 */
function collection_attempts_resolve(string $tenantId, ?array $actor = null): array
{
    $pdo = db();
    $result = ['checked' => 0, 'recovered' => 0, 'cleared' => 0, 'pending' => 0];
    $open = collection_attempts_open($tenantId);
    if (!$open) {
        return $result;
    }
    $stripe = _get_stripe_client($tenantId);
    foreach ($open as $a) {
        $ageSec = time() - (int)strtotime($a['created_at']);
        if ($a['status'] === 'pending' && $ageSec < 15 * 60) {
            $result['pending']++;
            continue;
        }
        $result['checked']++;
        try {
            if ($a['status'] === 'succeeded' && !empty($a['stripe_payment_intent_id'])) {
                $pi = $stripe->getPaymentIntent((string)$a['stripe_payment_intent_id']);
            } else {
                $found = $stripe->searchPaymentIntents(sprintf("metadata['attempt_key']:'%s'", $a['idempotency_key']));
                $pi = $found['data'][0] ?? null;
            }
        } catch (Throwable $e) {
            error_log('Klärung Versuch ' . $a['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            $result['pending']++;
            continue;
        }
        if ($pi && !empty($pi['id'])) {
            collection_attempt_recover($tenantId, $a, $pi, $actor);
            $result['recovered']++;
        } elseif ($a['status'] !== 'succeeded' && $ageSec >= 10 * 60) {
            collection_attempt_finish($a['id'], 'failed', null, 'Kein PaymentIntent bei Stripe gefunden (Klärung ' . date('d.m.Y H:i') . ')');
            audit_log($tenantId, $actor, 'collection_attempt_cleared', 'invoice', $a['invoice_id'], [
                'attempt_id' => $a['id'], 'amount_cents' => (int)$a['amount_cents'],
            ]);
            $result['cleared']++;
        } else {
            $result['pending']++;
        }
    }
    // Terminierte Einzüge, die beim Einreichen hängen geblieben sind (Status submitting,
    // kein offener Versuch mehr), zurück auf terminiert setzen.
    $pdo->prepare(
        "UPDATE payment_collections pc
         SET pc.stripe_status = 'scheduled', pc.note = CONCAT_WS(' ', pc.note, 'Einreichung abgebrochen, erneut terminiert')
         WHERE pc.tenant_id = ? AND pc.stripe_status = 'submitting' AND pc.stripe_payment_intent_id IS NULL
           AND pc.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND NOT EXISTS (SELECT 1 FROM collection_attempts a
                           WHERE a.tenant_id = pc.tenant_id AND a.invoice_id = pc.invoice_id
                             AND (a.status IN ('pending', 'unknown')
                                  OR (a.status = 'succeeded' AND a.stripe_payment_intent_id IS NOT NULL
                                      AND NOT EXISTS (SELECT 1 FROM payment_collections p2
                                                      WHERE p2.tenant_id = a.tenant_id
                                                        AND p2.stripe_payment_intent_id = a.stripe_payment_intent_id))))"
    )->execute([$tenantId]);
    return $result;
}

// ---------------------------------------------------------------------------
// Erstattungen aus Stripe (charge.refunded, charge.refund.updated)
// ---------------------------------------------------------------------------

/**
 * Erstattungsstand eines Einzugs übernehmen. $refundedCents ist der von Stripe
 * gemeldete Gesamtbetrag der Erstattungen zur Charge (amount_refunded).
 *
 *  - Vollerstattung (>= Einzugsbetrag): stripe_status 'refunded'; die Rechnung
 *    geht von 'collected' zurück auf 'open', aber NUR mit Vermerk: sie erhält
 *    requires_review = 1 und wird nicht automatisch erneut eingereicht.
 *  - Teilerstattung: refunded_cents wird gesetzt, stripe_status bleibt
 *    'succeeded', Rechnung requires_review = 1.
 *  - Unveränderter Stand (Wiederholung des Webhooks): keine Änderung, kein Audit.
 *
 * Es gibt keinen automatischen Neu-Einzug. Gibt true zurück, wenn sich der
 * Stand geändert hat.
 */
function collection_apply_refund(string $tenantId, array $collection, int $refundedCents, ?string $chargeId = null, ?array $actor = null, string $source = 'webhook'): bool
{
    $pdo = db();
    $refundedCents = max(0, $refundedCents);
    if ((int)($collection['refunded_cents'] ?? 0) === $refundedCents) {
        return false;
    }
    $amount = (int)$collection['amount_cents'];
    $full = $refundedCents >= $amount;
    $when = date('d.m.Y H:i');
    $note = $full
        ? sprintf('Vollständig erstattet über Stripe am %s (%s)', $when, format_eur_cents($refundedCents))
        : sprintf('Teilerstattung über Stripe am %s: %s von %s', $when, format_eur_cents($refundedCents), format_eur_cents($amount));
    if ($refundedCents === 0) {
        $note = sprintf('Erstattung bei Stripe zurückgenommen am %s', $when);
    }

    $pdo->prepare(
        "UPDATE payment_collections
         SET refunded_cents = ?, refunded_at = NOW(), refund_note = ?,
             stripe_status = CASE WHEN ? = 1 THEN 'refunded' WHEN stripe_status = 'refunded' THEN 'succeeded' ELSE stripe_status END,
             stripe_charge_id = COALESCE(stripe_charge_id, ?)
         WHERE id = ? AND tenant_id = ?"
    )->execute([$refundedCents, mb_substr($note, 0, 255), $full ? 1 : 0, $chargeId, $collection['id'], $tenantId]);

    // Rechnung: Klärungsbedarf setzen, bei Vollerstattung Einzugsstatus zurück auf offen.
    $stmt = $pdo->prepare('SELECT voucher_number, collection_status FROM invoices WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$collection['invoice_id'], $tenantId]);
    $invoice = $stmt->fetch();
    if ($invoice) {
        $reason = $full
            ? sprintf('Einzug über %s am %s vollständig erstattet. Rechnung wieder offen, kein automatischer Neu-Einzug.', format_eur_cents($amount), $when)
            : sprintf('Einzug über %s am %s teilweise erstattet (%s). Bitte Sachverhalt prüfen.', format_eur_cents($amount), $when, format_eur_cents($refundedCents));
        if ($refundedCents === 0) {
            $reason = sprintf('Erstattung zum Einzug über %s am %s bei Stripe zurückgenommen. Bitte Sachverhalt prüfen.', format_eur_cents($amount), $when);
        }
        $pdo->prepare(
            "UPDATE invoices
             SET requires_review = 1, review_reason = ?,
                 collection_status = CASE WHEN ? = 1 AND collection_status = 'collected' THEN 'open' ELSE collection_status END
             WHERE id = ? AND tenant_id = ?"
        )->execute([mb_substr($reason, 0, 255), $full ? 1 : 0, $collection['invoice_id'], $tenantId]);
    }

    audit_log($tenantId, $actor, 'collection_refunded', 'collection', $collection['id'], [
        'amount_cents' => $amount, 'refunded_cents' => $refundedCents, 'full' => $full,
        'charge' => $chargeId, 'payment_intent' => $collection['stripe_payment_intent_id'] ?? null,
        'voucher_number' => $invoice['voucher_number'] ?? null, 'source' => $source,
    ]);
    return true;
}

/**
 * Klärung einer Rechnung abschließen (Inhaber oder Administrator): Flag
 * requires_review zurücksetzen. Der Grund bleibt im Audit-Log erhalten.
 */
function invoice_review_clear(string $tenantId, string $invoiceId, array $actor): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        throw new CollectionException('Rechnung nicht gefunden.');
    }
    if ((int)$invoice['requires_review'] !== 1) {
        throw new CollectionException('Für diese Rechnung ist keine Klärung offen.');
    }
    $pdo->prepare('UPDATE invoices SET requires_review = 0, review_reason = NULL WHERE id = ? AND tenant_id = ?')
        ->execute([$invoiceId, $tenantId]);
    audit_log($tenantId, $actor, 'invoice_review_cleared', 'invoice', $invoiceId, [
        'voucher_number' => $invoice['voucher_number'], 'reason' => $invoice['review_reason'],
    ]);
    return $invoice;
}

// ---------------------------------------------------------------------------
// Stripe-Mandatsdaten
// ---------------------------------------------------------------------------

/**
 * Nach erfolgreichem Einzug die Stripe-Mandatsdaten (Mandats-ID und die von
 * Stripe erzeugte Mandatsreferenz) am SEPA-Mandat speichern und die Charge am
 * Einzug vermerken. Reiner Lesezugriff; Fehler brechen nichts ab.
 */
function store_stripe_mandate_data(StripeClient $stripe, array $collection, array $paymentIntent): void
{
    try {
        $latest = $paymentIntent['latest_charge'] ?? ($paymentIntent['charges']['data'][0] ?? null);
        $chargeId = is_array($latest) ? ($latest['id'] ?? null) : $latest;
        if (!$chargeId) {
            return;
        }
        $charge = is_array($latest) && isset($latest['payment_method_details']) ? $latest : $stripe->getCharge((string)$chargeId);
        db()->prepare('UPDATE payment_collections SET stripe_charge_id = ? WHERE id = ?')->execute([(string)$chargeId, $collection['id']]);

        $stripeMandateId = $charge['payment_method_details']['sepa_debit']['mandate'] ?? null;
        if (!$stripeMandateId || empty($collection['mandate_id'])) {
            return;
        }
        $reference = null;
        try {
            $m = $stripe->getMandate((string)$stripeMandateId);
            $reference = $m['payment_method_details']['sepa_debit']['reference'] ?? null;
        } catch (Throwable $e) {
            error_log('Stripe-Mandat ' . $stripeMandateId . ' nicht abrufbar: ' . $e->getMessage());
        }
        db()->prepare(
            'UPDATE sepa_mandates SET stripe_mandate_id = ?, stripe_mandate_reference = COALESCE(?, stripe_mandate_reference) WHERE id = ?'
        )->execute([(string)$stripeMandateId, $reference !== null ? mb_substr((string)$reference, 0, 64) : null, $collection['mandate_id']]);
    } catch (Throwable $e) {
        error_log('Stripe-Mandatsdaten konnten nicht gespeichert werden: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------

/**
 * Kunde und IBAN sofort bei Stripe registrieren (SEPA-Zahlungsmethode
 * anlegen und anhängen), OHNE eine Zahlung auszulösen.
 *
 * @return array{registered:bool,reason:?string}
 */
function register_iban_with_stripe(string $tenantId, string $customerId, string $customerIbanId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        return ['registered' => false, 'reason' => 'Kunde nicht gefunden.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerIbanId, $tenantId]);
    $iban = $stmt->fetch();
    if (!$iban) {
        return ['registered' => false, 'reason' => 'IBAN nicht gefunden.'];
    }
    if (($iban['source'] ?? 'manual') !== 'manual') {
        // Digital erteiltes Mandat: Zahlungsmethode liegt bereits bei Stripe, IBAN nur maskiert bekannt.
        return ['registered' => true, 'reason' => null];
    }

    try {
        $stripe = _get_stripe_client($tenantId);
    } catch (Throwable $e) {
        return ['registered' => false, 'reason' => $e->getMessage()];
    }

    $mandate = get_or_create_mandate($tenantId, $customerId, $customerIbanId);

    if (!empty($mandate['stripe_customer_id']) && !empty($mandate['stripe_payment_method_id'])) {
        return ['registered' => true, 'reason' => null];
    }

    $contactEmail = $customer['email'] ?: _fallback_contact_email();

    $stripeCustomer = $stripe->findOrCreateCustomer(
        $customer['name'],
        $customer['email'] ?: null,
        [
            'tenant_id'       => $tenantId,
            'customer_id'     => $customer['id'],
            'customer_number' => $customer['customer_number'],
        ]
    );
    $paymentMethod = $stripe->createSepaPaymentMethod(
        $iban['iban'],
        $iban['account_holder_name'],
        $contactEmail
    );
    $stripe->attachPaymentMethod($paymentMethod['id'], $stripeCustomer['id']);

    $pdo->prepare(
        'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
    )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);

    return ['registered' => true, 'reason' => null];
}

function validate_scheduled_date(string $scheduledDate, int $minLeadDays = 1): void
{
    $today = new DateTimeImmutable('today');
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $scheduledDate);
    if (!$date || $date->format('Y-m-d') !== $scheduledDate) {
        throw new CollectionException('Ungültiges Datum.');
    }
    $date = $date->setTime(0, 0);

    if ($date <= $today) {
        throw new CollectionException('Terminiertes Datum muss in der Zukunft liegen (mindestens morgen).');
    }
    if ($minLeadDays > 1 && $date < $today->modify('+' . $minLeadDays . ' days')) {
        throw new CollectionException(sprintf(
            'Wegen der Vorabankündigungsfrist von %d Tagen ist der früheste Termin der %s.',
            $minLeadDays,
            $today->modify('+' . $minLeadDays . ' days')->format('d.m.Y')
        ));
    }
    if ((int)$today->diff($date)->days > 365) {
        throw new CollectionException('Terminiertes Datum darf maximal 365 Tage in der Zukunft liegen.');
    }
    if ((int)$date->format('N') >= 6) { // 6 = Samstag, 7 = Sonntag
        throw new CollectionException('SEPA-Einzüge können nur an Werktagen (Mo-Fr) terminiert werden.');
    }
}

function _get_stripe_client(string $tenantId): StripeClient
{
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();

    if (!$integration || !(int)$integration['stripe_connected']) {
        throw new CollectionException('Stripe ist nicht verbunden.');
    }
    $secretKey = decrypt_value($integration['stripe_secret_key_encrypted']);
    if (!$secretKey) {
        throw new CollectionException('Stripe Secret Key fehlt.');
    }
    return new StripeClient($secretKey);
}

/** Rechnung, Kunde und aktive IBAN laden und für Einzug validieren. */
function _load_and_validate(string $tenantId, string $invoiceId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $stmt->execute([$invoiceId, $tenantId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        throw new CollectionException('Rechnung nicht gefunden.');
    }
    if (in_array($invoice['collection_status'], ['in_collection', 'scheduled'], true)) {
        throw new CollectionException('Rechnung befindet sich bereits im Einzugsverfahren.');
    }
    if ((int)($invoice['requires_review'] ?? 0) === 1) {
        throw new CollectionException(sprintf(
            'Rechnung %s ist zur Klärung markiert (%s). Es wird keine Lastschrift eingereicht, bis ein Inhaber oder '
            . 'Administrator die Klärung unter "Rechnungen" mit "Klärung abgeschlossen" beendet hat.',
            $invoice['voucher_number'], (string)($invoice['review_reason'] ?: 'Grund nicht vermerkt')
        ));
    }
    if (!in_array($invoice['lexoffice_status'], ['open', 'overdue'], true)) {
        throw new CollectionException(
            'Rechnung kann nicht eingezogen werden (Status in Lexware Office: ' . $invoice['lexoffice_status'] . ').'
        );
    }
    if (!$invoice['customer_id']) {
        throw new CollectionException('Rechnung hat keinen verknüpften Kunden.');
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$invoice['customer_id'], $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new CollectionException('Kunde nicht gefunden.');
    }
    if ((int)($customer['sepa_debit_enabled'] ?? 1) === 0) {
        throw new CollectionException('SEPA-Einzug ist für diesen Kunden deaktiviert.');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$customer['id'], $tenantId]);
    $iban = $stmt->fetch();
    if (!$iban) {
        throw new CollectionException('Für diesen Kunden ist keine IBAN hinterlegt.');
    }

    return [$invoice, $customer, $iban];
}

function _build_collection_description(string $tenantId, array $invoice, array $customer): string
{
    $keywordSepa = $invoice['keyword_sepa'];
    if (!$keywordSepa && $invoice['line_items_json']) {
        $lineItems = json_decode($invoice['line_items_json'], true) ?: [];
        [, $keywordSepa] = extract_keyword($lineItems);
    }
    if (!$keywordSepa) {
        $keywordSepa = 'Sonstiges';
    }
    $stmt = db()->prepare('SELECT name FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $orgName = (string)($stmt->fetchColumn() ?: 'SEPA-Einzug');
    return build_description($invoice['voucher_number'], $customer['customer_number'], $keywordSepa, $orgName);
}

/** Stripe-Aufrufe für einen Einzug ausführen. Gibt [customer, paymentMethod, paymentIntent] zurück. */
function _execute_stripe_collection(
    StripeClient $stripe,
    string $tenantId,
    array $invoice,
    array $customer,
    array $iban,
    array $mandate,
    string $description,
    int $amountCents,
    ?string $idempotencyKey = null
): array {
    $contactEmail = $customer['email'] ?: _fallback_contact_email();

    if (!empty($mandate['stripe_customer_id']) && !empty($mandate['stripe_payment_method_id'])) {
        $stripeCustomer = ['id' => $mandate['stripe_customer_id']];
        $paymentMethod = ['id' => $mandate['stripe_payment_method_id']];
    } else {
        if (($iban['source'] ?? 'manual') !== 'manual') {
            throw new CollectionException('Für das digital erteilte Mandat fehlt die Zahlungsmethode bei Stripe. Bitte das Mandat erneut anfordern.');
        }
        $stripeCustomer = $stripe->findOrCreateCustomer(
            $customer['name'],
            $customer['email'] ?: null,
            [
                'tenant_id'       => $tenantId,
                'customer_id'     => $customer['id'],
                'customer_number' => $customer['customer_number'],
            ]
        );

        $paymentMethod = $stripe->createSepaPaymentMethod(
            $iban['iban'],
            $iban['account_holder_name'],
            $contactEmail
        );
        $stripe->attachPaymentMethod($paymentMethod['id'], $stripeCustomer['id']);
    }

    // Optional: Stripe-Mandatsreferenz mit dem Firmenpräfix beginnen lassen
    $prefix = null;
    if (config('stripe_mandate_reference_prefix', false)) {
        $stmt = db()->prepare('SELECT mandate_prefix FROM organizations WHERE id = ?');
        $stmt->execute([$tenantId]);
        $prefix = (string)($stmt->fetchColumn() ?: '') ?: null;
    }

    $metadata = [
        'tenant_id'         => $tenantId,
        'invoice_id'        => $invoice['id'],
        'mandate_reference' => $mandate['mandate_reference'],
        'voucher_number'    => $invoice['voucher_number'],
        'customer_number'   => $customer['customer_number'],
    ];
    if ($idempotencyKey !== null) {
        $metadata['attempt_key'] = $idempotencyKey;
    }

    $paymentIntent = $stripe->createPaymentIntent(
        $amountCents,
        $stripeCustomer['id'],
        $paymentMethod['id'],
        $description,
        $metadata,
        $prefix,
        $idempotencyKey
    );

    return [$stripeCustomer, $paymentMethod, $paymentIntent];
}

/**
 * Vorabankündigung (Pre-Notification) per E-Mail an den Kunden senden, sofern
 * die Firma das aktiviert hat und eine E-Mail-Adresse vorliegt.
 * Inhalt: Betrag, Fälligkeit, Mandatsreferenz, Gläubiger-ID, Zahlungsempfänger.
 */
function _send_prenotification(array $org, array $customer, array $invoice, array $mandate, int $amountCents, string $dueDate): bool
{
    require_once __DIR__ . '/mailer.php';
    if (!(int)($org['send_pre_notification'] ?? 0) || !mail_enabled() || empty($customer['email'])) {
        return false;
    }
    $lines = [
        sprintf('Sehr geehrte Damen und Herren, wir kündigen hiermit den Einzug folgender Lastschrift an:'),
        sprintf('Rechnung %s über %s, Fälligkeit/Einzug am %s.', $invoice['voucher_number'], format_eur_cents($amountCents), format_date($dueDate)),
        sprintf('Zahlungsempfänger: %s. Mandatsreferenz: %s.%s', $org['name'], $mandate['mandate_reference'],
            !empty($org['creditor_identifier']) ? ' Gläubiger-Identifikationsnummer: ' . $org['creditor_identifier'] . '.' : ''),
        'Der Einzug erfolgt über den Zahlungsdienstleister Stripe. Bitte sorgen Sie für ausreichende Kontodeckung.',
    ];
    $tpl = mail_layout('Vorabankündigung SEPA-Lastschrift', $lines, null, $org['name']);
    return mail_send($customer['email'], 'Vorabankündigung SEPA-Lastschrift ' . $invoice['voucher_number'], $tpl['text'], $tpl['html']);
}

/**
 * Einzug einreichen. Mit $scheduledDate wird nur terminiert (kein Stripe-Aufruf),
 * ohne wird sofort bei Stripe eingereicht. Gibt die Collection-ID zurück.
 *
 * $options['confirm_amount_cents']: ausdrückliche Bestätigung eines vom
 * Rechnungsbetrag abweichenden Restbetrags (Teilzahlung). Ohne Bestätigung
 * wird bei Abweichung nicht eingereicht.
 */
function submit_collection(string $tenantId, string $invoiceId, ?string $scheduledDate = null, ?array $actor = null, array $options = []): string
{
    $pdo = db();
    // Support-Modus des Plattformbetreibers: keine Einzüge im Namen der Firma
    // (vor der Transaktion, damit der Protokolleintrag erhalten bleibt)
    if (function_exists('support_mode') && support_mode()) {
        audit_log($tenantId, $actor, 'support_access_blocked', 'invoice', $invoiceId, ['aktion' => 'submit_collection']);
        throw new CollectionException('Im Support-Modus sind Einzüge gesperrt. Diese Aktion muss die Firma selbst ausführen.');
    }
    // Firmenzeile sperren: verhindert doppelte Einzüge derselben Rechnung und
    // Kontingentüberschreitungen durch parallele Anfragen. Die Sperre gilt bis
    // zum Commit am Ende (auch der Stripe-Aufruf liegt darin, er dauert nur
    // wenige Sekunden).
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE')->execute([$tenantId]);
        $collectionId = _submit_collection_locked($tenantId, $invoiceId, $scheduledDate, $actor, $options);
        if ($ownTransaction) {
            $pdo->commit();
        }
        $GLOBALS['lexsepa_open_amount_pending'] = [];
        return $collectionId;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$pdo->inTransaction()) {
            _persist_pending_open_amounts();
        }
        // Verfall eines Mandats dauerhaft festhalten, auch wenn die Transaktion
        // des Einzugs zurückgerollt wurde.
        if ($e instanceof MandateUnusableException && $e->mandateId && !$pdo->inTransaction()) {
            $m = mandate_load($tenantId, $e->mandateId);
            if ($m) {
                mandate_check_usable($m, _collection_org($tenantId));
            }
        }
        throw $e;
    }
}

function _submit_collection_locked(string $tenantId, string $invoiceId, ?string $scheduledDate, ?array $actor, array $options = []): string
{
    $pdo = db();

    // 1. Not-Stopp
    if ($pause = collections_pause_reason($tenantId)) {
        throw new CollectionException($pause);
    }

    $org = _collection_org($tenantId);
    $preDays = max(0, (int)($org['pre_notification_days'] ?? 14));
    $preNotify = (int)($org['send_pre_notification'] ?? 0) === 1;

    if ($scheduledDate !== null) {
        validate_scheduled_date($scheduledDate, $preNotify ? $preDays : 1);
    } elseif ($preNotify && $preDays > 0) {
        throw new CollectionException(sprintf(
            'Die Vorabankündigung per E-Mail ist für Ihre Firma aktiviert (%d Tage). Bitte den Einzug terminieren; '
            . 'die Ankündigung wird beim Terminieren versendet.',
            $preDays
        ));
    }

    // 2. Kontingent, Rechnung, Kunde, IBAN, Mandat
    $quota = collections_quota_check($tenantId);
    if (!$quota['allowed']) {
        throw new CollectionException($quota['reason']);
    }

    [$invoice, $customer, $iban] = _load_and_validate($tenantId, $invoiceId);
    if (mandate_requires_manual_renewal($tenantId, $customer['id'])) {
        throw new CollectionException(
            'Das bisherige SEPA-Mandat dieses Kunden ist widerrufen oder verfallen. Bitte ein neues Mandat einholen '
            . 'und unter Kundendetails erfassen (Mandatsdokument erzeugen, Nachweis erfassen).'
        );
    }
    $mandate = get_or_create_mandate($tenantId, $customer['id'], $iban['id']);
    if ($problem = mandate_check_usable($mandate, $org)) {
        $ex = new MandateUnusableException($problem);
        $ex->mandateId = $mandate['id'];
        throw $ex;
    }
    if ($preNotify && $scheduledDate !== null) {
        require_once __DIR__ . '/mailer.php';
        if (!mail_enabled()) {
            throw new CollectionException('Die Vorabankündigung per E-Mail ist aktiviert, aber der E-Mail-Versand der Anwendung ist nicht eingerichtet.');
        }
        if (empty($customer['email'])) {
            throw new CollectionException('Die Vorabankündigung per E-Mail ist aktiviert, aber für diesen Kunden ist keine E-Mail-Adresse hinterlegt.');
        }
    }

    // 3. Restbetrag: Vorprüfung ohne API-Aufruf (frischer gespeicherter Wert 0 blockiert)
    _precheck_stored_open_amount($invoice);

    $description = _build_collection_description($tenantId, $invoice, $customer);
    $amountCents = (int)round((float)$invoice['total_gross_amount'] * 100);
    $note = null;
    $userId = $actor['user_id'] ?? null;
    $confirmed = isset($options['confirm_amount_cents']) && $options['confirm_amount_cents'] !== null && $options['confirm_amount_cents'] !== ''
        ? (int)$options['confirm_amount_cents'] : null;

    $collectionId = uuid4();

    // Sofort-Einzug mit Karenzzeit: als vorgemerkt speichern (is_scheduled), Einreichung
    // frühestens nach der Karenzzeit im Einreichfenster durch den Cron. Die Stripe-
    // Verbindung muss jetzt schon bestehen, damit der Fehler sofort sichtbar wird.
    $queuedImmediate = false;
    $submitNotBefore = null;
    if ($scheduledDate === null && collections_grace_active()) {
        _get_stripe_client($tenantId);
        // Kein Vormerken, solange ein früherer Versuch für diese Rechnung offen oder unklar ist
        $openAttempts = collection_attempts_open($tenantId, $invoice['id']);
        if ($openAttempts) {
            $a = $openAttempts[0];
            throw new CollectionException(sprintf(
                'Für Rechnung %s ist ein Einzugsversuch vom %s mit Status "%s" nicht abgeschlossen. '
                . 'Bis zur Klärung (Einzüge > "Unklare Versuche prüfen") wird kein weiterer Einzug vorgemerkt.',
                $a['voucher_number'], format_datetime($a['created_at']), collection_attempt_open_label($a)
            ));
        }
        $earliest = collections_earliest_submit();
        $scheduledDate = $earliest->format('Y-m-d');
        $submitNotBefore = $earliest->format('Y-m-d H:i:s');
        $queuedImmediate = true;
    }

    if ($scheduledDate !== null) {
        // --- Terminiert oder vorgemerkt: noch kein Stripe-Aufruf; der Restbetrag wird bei der Einreichung live geprüft ---
        $cached = invoice_open_amount_cached($invoice);
        if ($cached !== null && $cached < $amountCents) {
            $own = invoice_own_collections_cents($tenantId, $invoice['id']);
            $rest = $cached - $own;
            if ($rest <= 0) {
                throw new CollectionException(sprintf(
                    'Für Rechnung %s bleibt nach eigenen Einzügen kein Restbetrag (offen laut Lexware Office %s).',
                    $invoice['voucher_number'], format_eur_cents($cached)
                ));
            }
            if ($confirmed !== $rest) {
                throw new CollectionException(sprintf(
                    'Für Rechnung %s ist laut Lexware Office nur noch ein Restbetrag von %s offen (Rechnungsbetrag %s). '
                    . 'Bitte die Terminierung über den Restbetrag ausdrücklich bestätigen.',
                    $invoice['voucher_number'], format_eur_cents($rest), format_eur_cents($amountCents)
                ));
            }
            $note = sprintf('Restbetrag laut Lexware Office %s vom %s (Rechnungsbetrag %s)',
                format_eur_cents($cached), format_datetime($invoice['open_amount_fetched_at']), format_eur_cents($amountCents));
            $amountCents = $rest;
        }

        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_status, description, note, is_scheduled, scheduled_date, scheduled_submitted, submit_not_before, queued_immediate, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?)'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', 'scheduled', $description, $note, $scheduledDate,
            $submitNotBefore, $queuedImmediate ? 1 : 0, $userId,
        ]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'scheduled' WHERE id = ?")
            ->execute([$invoice['id']]);

        if ($preNotify && !$queuedImmediate) {
            if (!_send_prenotification($org, $customer, $invoice, $mandate, $amountCents, $scheduledDate)) {
                throw new CollectionException('Die Vorabankündigung konnte nicht versendet werden; der Einzug wurde nicht terminiert.');
            }
            $pdo->prepare('UPDATE payment_collections SET prenotified_at = NOW() WHERE id = ?')->execute([$collectionId]);
        }

        audit_log($tenantId, $actor, $queuedImmediate ? 'collection_queued' : 'collection_scheduled', 'collection', $collectionId, [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => $amountCents,
            'scheduled_date' => $scheduledDate, 'submit_not_before' => $submitNotBefore,
            'customer_number' => $customer['customer_number'], 'note' => $note,
        ]);
    } else {
        // --- Sofort: Stripe jetzt aufrufen ---
        $stripe = _get_stripe_client($tenantId);

        // 3. Restbetrag live bei Lexware Office (unmittelbar vor dem Stripe-Aufruf)
        $decision = _determine_collection_amount($tenantId, $invoice, $amountCents, $confirmed);
        $amountCents = $decision['amount_cents'];
        $note = $decision['note'];

        // 4. Versuchsjournal mit Idempotenz-Schlüssel, dann Stripe
        $exec = _execute_with_attempt($stripe, $tenantId, $invoice, $customer, $iban, $mandate, $description, $amountCents, $userId, $collectionId);
        [$stripeCustomer, $paymentMethod, $paymentIntent] = $exec['result'];

        $pdo->prepare(
            'INSERT INTO payment_collections
                (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency,
                 stripe_payment_intent_id, stripe_status, description, note, submitted_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $collectionId, $tenantId, $invoice['id'], $mandate['id'], $iban['id'],
            $amountCents, 'EUR', $paymentIntent['id'], 'processing', $description, $note, $userId,
        ]);
        collection_attempt_finish($exec['attempt']['id'], 'succeeded', $paymentIntent['id'], null, $collectionId);
        $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
            ->execute([$invoice['id']]);
        $pdo->prepare(
            'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
        )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);
        mandate_touch_used($mandate['id']);

        audit_log($tenantId, $actor, 'collection_submitted', 'collection', $collectionId, [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => $amountCents,
            'customer_number' => $customer['customer_number'], 'payment_intent' => $paymentIntent['id'],
            'open_amount_cents' => $decision['open_cents'], 'attempt_id' => $exec['attempt']['id'], 'note' => $note,
        ]);
        funnel_event_once($tenantId, 'first_collection', $userId);
    }

    return $collectionId;
}

function cancel_scheduled_collection(string $tenantId, string $collectionId, ?array $actor = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$collectionId, $tenantId]);
    $collection = $stmt->fetch();

    if (!$collection) {
        throw new CollectionException('Einzug nicht gefunden.');
    }
    if (!(int)$collection['is_scheduled']) {
        throw new CollectionException('Nur vorgemerkte oder terminierte Einzüge können storniert werden.');
    }
    if ($collection['stripe_status'] === 'cancelled') {
        throw new CollectionException('Einzug ist bereits storniert.');
    }
    if ($collection['stripe_status'] === 'failed') {
        throw new CollectionException('Einzug ist fehlgeschlagen und muss nicht storniert werden.');
    }
    if ((int)$collection['scheduled_submitted'] || $collection['stripe_status'] !== 'scheduled') {
        throw new CollectionException('Einzug wurde bereits bei Stripe eingereicht und kann nicht mehr storniert werden.');
    }

    // Atomar: ein parallel laufender Cron darf denselben Einzug nicht gleichzeitig einreichen
    $upd = $pdo->prepare("UPDATE payment_collections SET stripe_status = 'cancelled' WHERE id = ? AND stripe_status = 'scheduled' AND scheduled_submitted = 0");
    $upd->execute([$collectionId]);
    if ($upd->rowCount() !== 1) {
        throw new CollectionException('Einzug wird gerade eingereicht und kann nicht mehr storniert werden.');
    }
    $pdo->prepare("UPDATE invoices SET collection_status = 'open' WHERE id = ?")
        ->execute([$collection['invoice_id']]);
    audit_log($tenantId, $actor, 'collection_cancelled', 'collection', $collectionId, [
        'amount_cents' => (int)$collection['amount_cents'], 'queued_immediate' => (int)($collection['queued_immediate'] ?? 0) === 1,
    ]);
}

function reschedule_collection(string $tenantId, string $collectionId, string $newDate, ?array $actor = null): void
{
    if (function_exists('support_mode') && support_mode()) {
        throw new CollectionException('Im Support-Modus sind Einzüge gesperrt. Diese Aktion muss die Firma selbst ausführen.');
    }
    $org = _collection_org($tenantId);
    $preNotify = (int)($org['send_pre_notification'] ?? 0) === 1;
    validate_scheduled_date($newDate, $preNotify ? max(1, (int)$org['pre_notification_days']) : 1);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$collectionId, $tenantId]);
    $collection = $stmt->fetch();

    if (!$collection) {
        throw new CollectionException('Einzug nicht gefunden.');
    }
    if (!(int)$collection['is_scheduled']) {
        throw new CollectionException('Nur terminierte Einzüge können umterminiert werden.');
    }
    if ($collection['stripe_status'] === 'cancelled') {
        throw new CollectionException('Einzug ist bereits storniert und kann nicht umterminiert werden.');
    }
    if ($collection['stripe_status'] === 'failed') {
        throw new CollectionException('Einzug ist fehlgeschlagen und kann nicht umterminiert werden; bitte die Rechnung neu einziehen.');
    }
    if ((int)$collection['scheduled_submitted'] || $collection['stripe_status'] !== 'scheduled') {
        throw new CollectionException('Einzug wurde bereits bei Stripe eingereicht.');
    }

    // Umterminieren macht aus einem vorgemerkten Sofort-Einzug einen regulären terminierten
    // Einzug: die Vormerkung (submit_not_before, queued_immediate) wird zurückgesetzt, sonst
    // bliebe der alte Einreichzeitpunkt maßgeblich. Atomar gegen parallelen Cron-Claim.
    $upd = $pdo->prepare(
        "UPDATE payment_collections SET scheduled_date = ?, submit_not_before = NULL, queued_immediate = 0
         WHERE id = ? AND stripe_status = 'scheduled' AND scheduled_submitted = 0"
    );
    $upd->execute([$newDate, $collectionId]);
    if ($upd->rowCount() !== 1) {
        throw new CollectionException('Einzug wird gerade eingereicht oder wurde inzwischen storniert und kann nicht umterminiert werden.');
    }

    // Vorabankündigung mit neuem Termin wiederholen
    if ($preNotify) {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$collection['invoice_id']]);
        $invoice = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$invoice['customer_id'] ?? '']);
        $customer = $stmt->fetch();
        $mandate = mandate_load($tenantId, $collection['mandate_id']);
        if ($invoice && $customer && $mandate) {
            if (_send_prenotification($org, $customer, $invoice, $mandate, (int)$collection['amount_cents'], $newDate)) {
                $pdo->prepare('UPDATE payment_collections SET prenotified_at = NOW() WHERE id = ?')->execute([$collectionId]);
            } else {
                $pdo->prepare('UPDATE payment_collections SET scheduled_date = ?, submit_not_before = ?, queued_immediate = ? WHERE id = ?')
                    ->execute([$collection['scheduled_date'], $collection['submit_not_before'] ?? null, (int)($collection['queued_immediate'] ?? 0), $collectionId]);
                throw new CollectionException('Die Vorabankündigung für den neuen Termin konnte nicht versendet werden; der Termin bleibt unverändert.');
            }
        }
    }
    audit_log($tenantId, $actor, 'collection_rescheduled', 'collection', $collectionId, [
        'old_date' => $collection['scheduled_date'], 'new_date' => $newDate,
        'old_submit_not_before' => $collection['submit_not_before'] ?? null, 'was_queued' => (int)($collection['queued_immediate'] ?? 0) === 1,
    ]);
}

/**
 * Status laufender Einzüge ("processing") bei Stripe abfragen und lokal
 * nachziehen. Reiner Lesezugriff, löst keine Zahlung aus. Bei Erfolg werden
 * Charge und Stripe-Mandatsdaten gespeichert.
 *
 * Eine spätere SEPA-Rücklastschrift (bis zu 8 Wochen nach Belastung) wird
 * nur über den Stripe-Webhook (charge.dispute.created) erkannt.
 *
 * @return array{checked:int,succeeded:int,failed:int,unchanged:int}
 */
function sync_collection_statuses(string $tenantId, ?array $actor = null): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM payment_collections
         WHERE tenant_id = ? AND stripe_status = 'processing' AND stripe_payment_intent_id IS NOT NULL"
    );
    $stmt->execute([$tenantId]);
    $pending = $stmt->fetchAll();

    $result = ['checked' => count($pending), 'succeeded' => 0, 'failed' => 0, 'unchanged' => 0];
    if (!$pending) {
        return $result;
    }

    $stripe = _get_stripe_client($tenantId);

    foreach ($pending as $collection) {
        try {
            $pi = $stripe->getPaymentIntent($collection['stripe_payment_intent_id']);
        } catch (Throwable $e) {
            error_log(
                'Statusabgleich für PaymentIntent ' . $collection['stripe_payment_intent_id']
                . ' fehlgeschlagen: ' . $e->getMessage()
            );
            $result['unchanged']++;
            continue;
        }

        $piStatus = $pi['status'] ?? '';

        if ($piStatus === 'succeeded') {
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE id = ?"
            )->execute([$collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'collected' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            store_stripe_mandate_data($stripe, $collection, $pi);
            $result['succeeded']++;
        } elseif (in_array($piStatus, ['canceled', 'requires_payment_method'], true)) {
            $reason = $pi['last_payment_error']['message'] ?? 'Lastschrift konnte nicht eingezogen werden.';
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'failed', failure_reason = ?, completed_at = NOW() WHERE id = ?"
            )->execute([$reason, $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            $result['failed']++;
        } else {
            $result['unchanged']++;
        }
    }

    audit_log($tenantId, $actor, 'collection_status_sync', 'organization', $tenantId, $result);
    return $result;
}

/**
 * Alle offenen Rechnungen mit aktiver IBAN und gewünschtem SEPA-Einzug
 * sofort bei Stripe einreichen (Sammel-Einzug). Bei Not-Stopp wird nichts
 * eingereicht (CollectionException).
 *
 * @return array{submitted:int,failed:int,candidates:int,amount_cents:int,errors:array}
 */
function submit_all_ready_collections(string $tenantId, ?array $actor = null): array
{
    if ($pause = collections_pause_reason($tenantId)) {
        throw new CollectionException($pause);
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT i.id, i.voucher_number FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.tenant_id = ?
           AND i.lexoffice_status IN ('open', 'overdue')
           AND i.collection_status NOT IN ('in_collection', 'scheduled', 'collected')
           AND i.requires_review = 0
           AND c.sepa_debit_enabled = 1
           AND EXISTS (
               SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
           )"
    );
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();

    $submitted = 0;
    $failed = 0;
    $amount = 0;
    $errors = [];
    foreach ($rows as $row) {
        try {
            $collectionId = submit_collection($tenantId, $row['id'], null, $actor);
            $submitted++;
            $s = $pdo->prepare('SELECT amount_cents FROM payment_collections WHERE id = ?');
            $s->execute([$collectionId]);
            $amount += (int)$s->fetchColumn();
        } catch (Throwable $e) {
            error_log('Sammel-Einzug für Rechnung ' . $row['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            $failed++;
            if (count($errors) < 10) {
                $errors[] = $row['voucher_number'] . ': ' . $e->getMessage();
            }
            if (collections_pause_reason($tenantId)) {
                // Not-Stopp während des Laufs gesetzt: sofort abbrechen
                $errors[] = 'Not-Stopp aktiv, Sammel-Einzug abgebrochen.';
                break;
            }
        }
    }

    audit_log($tenantId, $actor, 'collections_bulk', 'organization', $tenantId, [
        'submitted' => $submitted, 'failed' => $failed, 'candidates' => count($rows), 'amount_cents' => $amount,
    ]);
    return ['submitted' => $submitted, 'failed' => $failed, 'candidates' => count($rows), 'amount_cents' => $amount, 'errors' => $errors];
}

/**
 * Anzahl und Summe der Rechnungen ermitteln, die submit_all_ready_collections()
 * jetzt einreichen würde.
 *
 * @return array{count:int,amount:string}
 */
function count_ready_for_collection(string $tenantId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(i.total_gross_amount), 0) AS total
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.tenant_id = ?
           AND i.lexoffice_status IN ('open', 'overdue')
           AND i.collection_status NOT IN ('in_collection', 'scheduled', 'collected')
           AND i.requires_review = 0
           AND c.sepa_debit_enabled = 1
           AND EXISTS (
               SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
           )"
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    return ['count' => (int)$row['cnt'], 'amount' => (string)$row['total']];
}

/**
 * Fällige vorgemerkte und terminierte Einzüge bei Stripe einreichen.
 * Ohne $tenantId (cron.php): alle Firmen. Mit $tenantId (Button): nur die eigene.
 * Firmen mit Not-Stopp werden übersprungen und protokolliert; zurückgestellte
 * Einzüge (CollectionDeferredException) bleiben unverändert terminiert.
 *
 * Regeln (collections_rules_config): Einreichung nur im Einreichfenster
 * (Option ignore_window = true umgeht das Fenster, nur nach Zweitbestätigung),
 * nur wenn der früheste Einreichzeitpunkt erreicht ist, und nur, wenn der
 * Termin nicht länger als overdue_days zurückliegt (sonst überfällig, manuell
 * neu terminieren). Option deadline (microtime) begrenzt die Laufzeit je Aufruf;
 * der Rest folgt beim nächsten Lauf.
 *
 * @param array{ignore_window?:bool,deadline?:float} $options
 * @return array{submitted:int,failed:int,deferred:int,unknown:int,skipped_paused:int,outside_window:int,overdue_skipped:int,remaining:int}
 */
function process_scheduled_collections(?string $tenantId = null, ?array $actor = null, array $options = []): array
{
    $pdo = db();
    $result = ['submitted' => 0, 'failed' => 0, 'deferred' => 0, 'unknown' => 0, 'skipped_paused' => 0,
        'outside_window' => 0, 'overdue_skipped' => 0, 'remaining' => 0];
    $rules = collections_rules_config();
    $now = collections_now();
    $nowStr = $now->format('Y-m-d H:i:s');
    $cutoff = $now->modify('-' . $rules['overdue_days'] . ' days')->format('Y-m-d');
    $tenantSql = $tenantId !== null ? ' AND tenant_id = ?' : '';
    $tenantParams = $tenantId !== null ? [$tenantId] : [];

    // Fällig: frühester Einreichzeitpunkt erreicht, Termin nicht überfällig
    $dueWhere = "is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled'
                 AND COALESCE(submit_not_before, scheduled_date) <= ? AND scheduled_date >= ?" . $tenantSql;
    $dueParams = array_merge([$nowStr, $cutoff], $tenantParams);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payment_collections WHERE is_scheduled = 1 AND scheduled_submitted = 0
           AND stripe_status = 'scheduled' AND scheduled_date < ?" . $tenantSql
    );
    $stmt->execute(array_merge([$cutoff], $tenantParams));
    $result['overdue_skipped'] = (int)$stmt->fetchColumn();

    if (platform_collections_paused()) {
        error_log('Not-Stopp (Plattform) aktiv: keine terminierten Einzüge eingereicht.');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_collections WHERE $dueWhere");
        $stmt->execute($dueParams);
        $result['skipped_paused'] = (int)$stmt->fetchColumn();
        if ($result['skipped_paused'] > 0) {
            audit_log($tenantId, $actor, 'collections_due_skipped_paused', 'organization', $tenantId, [
                'count' => $result['skipped_paused'], 'scope' => 'platform', 'source' => $actor ? 'button' : 'cron',
            ]);
        }
        return $result;
    }

    if (!collections_window_open($now) && empty($options['ignore_window'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_collections WHERE $dueWhere");
        $stmt->execute($dueParams);
        $result['outside_window'] = (int)$stmt->fetchColumn();
        return $result;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM payment_collections WHERE $dueWhere ORDER BY COALESCE(submit_not_before, scheduled_date) ASC, created_at ASC"
    );
    $stmt->execute($dueParams);
    $due = $stmt->fetchAll();
    $deadline = isset($options['deadline']) ? (float)$options['deadline'] : null;

    $pausedTenants = [];
    foreach ($due as $index => $collection) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            $result['remaining'] = count($due) - $index;
            break;
        }
        $t = $collection['tenant_id'];
        if (!array_key_exists($t, $pausedTenants)) {
            $pausedTenants[$t] = collections_pause_reason($t);
        }
        if ($pausedTenants[$t] !== null) {
            error_log('Not-Stopp aktiv für Firma ' . $t . ': terminierter Einzug ' . $collection['id'] . ' übersprungen.');
            $result['skipped_paused']++;
            continue;
        }
        try {
            _submit_single_scheduled($collection);
            $result['submitted']++;
        } catch (CollectionUnknownOutcomeException $e) {
            // Ergebnis bei Stripe unbekannt: Einzug bleibt beansprucht (submitting), kein
            // "failed" (sonst wäre die Rechnung wieder einziehbar), Klärung über
            // collection_attempts_resolve().
            error_log('Terminierte Lastschrift ' . $collection['id'] . ' mit unbekanntem Ergebnis: ' . $e->getMessage());
            $pdo->prepare('UPDATE payment_collections SET note = ? WHERE id = ?')
                ->execute([mb_substr('Ergebnis unbekannt am ' . date('d.m.Y H:i') . ', Klärung erforderlich: ' . $e->getMessage(), 0, 255), $collection['id']]);
            $result['unknown']++;
        } catch (CollectionDeferredException $e) {
            error_log('Terminierte Lastschrift ' . $collection['id'] . ' zurückgestellt: ' . $e->getMessage());
            $pdo->prepare('UPDATE payment_collections SET note = ? WHERE id = ?')
                ->execute([mb_substr('Zurückgestellt am ' . date('d.m.Y H:i') . ': ' . $e->getMessage(), 0, 255), $collection['id']]);
            $result['deferred']++;
        } catch (Throwable $e) {
            error_log('Terminierte Lastschrift ' . $collection['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            $pdo->prepare(
                "UPDATE payment_collections SET stripe_status = 'failed', failure_reason = ? WHERE id = ?"
            )->execute([$e->getMessage(), $collection['id']]);
            $pdo->prepare("UPDATE invoices SET collection_status = 'failed' WHERE id = ?")
                ->execute([$collection['invoice_id']]);
            $result['failed']++;
        }
    }

    foreach ($pausedTenants as $t => $reason) {
        if ($reason !== null) {
            audit_log($t, $actor, 'collections_due_skipped_paused', 'organization', $t, [
                'source' => $actor ? 'button' : 'cron',
            ]);
        }
    }
    if ($due) {
        audit_log($tenantId, $actor, 'collections_due_processed', 'organization', $tenantId, $result + [
            'source' => $actor ? 'button' : 'cron',
        ]);
    }
    return $result;
}

function _submit_single_scheduled(array $collection): void
{
    $pdo = db();
    $tenantId = $collection['tenant_id'];

    if ($pause = collections_pause_reason($tenantId)) {
        throw new CollectionDeferredException($pause);
    }

    // Einzug atomar beanspruchen: parallele Läufe (Cron und Button) dürfen
    // dieselbe terminierte Lastschrift nicht zweimal einreichen.
    $claim = $pdo->prepare(
        "UPDATE payment_collections SET stripe_status = 'submitting'
         WHERE id = ? AND stripe_status = 'scheduled' AND scheduled_submitted = 0"
    );
    $claim->execute([$collection['id']]);
    if ($claim->rowCount() !== 1) {
        throw new CollectionDeferredException('Einzug wird bereits von einem anderen Lauf verarbeitet.');
    }
    $release = function () use ($pdo, $collection): void {
        $pdo->prepare("UPDATE payment_collections SET stripe_status = 'scheduled' WHERE id = ? AND stripe_status = 'submitting'")
            ->execute([$collection['id']]);
    };

    try {
        $stripe = _get_stripe_client($tenantId);

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$collection['invoice_id'], $tenantId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new RuntimeException('Rechnung nicht gefunden');
        }
        if (!in_array($invoice['lexoffice_status'], ['open', 'overdue'], true)) {
            throw new RuntimeException('Rechnung ist in Lexware Office nicht mehr offen (' . $invoice['lexoffice_status'] . ')');
        }

        $stmt = $pdo->prepare('SELECT * FROM sepa_mandates WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$collection['mandate_id'], $tenantId]);
        $mandate = $stmt->fetch();
        if (!$mandate) {
            throw new RuntimeException('Mandat nicht gefunden');
        }
        if ($problem = mandate_check_usable($mandate, _collection_org($tenantId))) {
            throw new RuntimeException($problem);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$invoice['customer_id'], $tenantId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Kunde nicht gefunden');
        }
        if ((int)($customer['sepa_debit_enabled'] ?? 1) === 0) {
            throw new RuntimeException('SEPA-Einzug wurde für diesen Kunden inzwischen deaktiviert');
        }

        // Immer die aktuell aktive IBAN verwenden: Wurde die Bankverbindung seit der
        // Terminierung ersetzt oder deaktiviert, darf die alte nicht belastet werden.
        $stmt = $pdo->prepare(
            'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$customer['id'], $tenantId]);
        $iban = $stmt->fetch();
        if (!$iban) {
            throw new RuntimeException('Für diesen Kunden ist keine aktive IBAN mehr hinterlegt');
        }
        if ($iban['id'] !== $collection['customer_iban_id']) {
            if (mandate_requires_manual_renewal($tenantId, $customer['id'])) {
                throw new RuntimeException('Das SEPA-Mandat ist widerrufen oder verfallen; ein neues Mandat muss erfasst werden');
            }
            $mandate = get_or_create_mandate($tenantId, $customer['id'], $iban['id']);
            if ($problem = mandate_check_usable($mandate, _collection_org($tenantId))) {
                throw new RuntimeException($problem);
            }
            $pdo->prepare('UPDATE payment_collections SET customer_iban_id = ?, mandate_id = ? WHERE id = ?')
                ->execute([$iban['id'], $mandate['id'], $collection['id']]);
        }

        // Restbetrag live prüfen: nicht abrufbar = zurückstellen, bezahlt = fehlgeschlagen,
        // Teilzahlung seit Terminierung = nur den Restbetrag einziehen (weniger ist zulässig).
        $amountCents = (int)$collection['amount_cents'];
        $note = $collection['note'];
        try {
            $live = invoice_fetch_open_amount($tenantId, $invoice);
        } catch (CollectionException $e) {
            throw new CollectionDeferredException($e->getMessage());
        }
        $own = invoice_own_collections_cents($tenantId, $invoice['id'], $collection['id']);
        $rest = $live['open_cents'] - $own;
        if ($live['open_cents'] <= 0 || $rest <= 0) {
            throw new RuntimeException(sprintf(
                'Rechnung ist laut Lexware Office bezahlt oder durch eigene Einzüge abgedeckt (offen %s, eigene Einzüge %s); nicht eingereicht',
                format_eur_cents($live['open_cents']), format_eur_cents($own)
            ));
        }
        if ($rest < $amountCents) {
            $note = sprintf('Restbetrag laut Lexware Office %s vom %s (terminiert waren %s)',
                format_eur_cents($live['open_cents']), format_datetime($live['fetched_at']), format_eur_cents($amountCents));
            $amountCents = $rest;
            $pdo->prepare('UPDATE payment_collections SET amount_cents = ?, note = ? WHERE id = ?')
                ->execute([$amountCents, mb_substr($note, 0, 255), $collection['id']]);
        }

        $exec = _execute_with_attempt(
            $stripe, $tenantId, $invoice, $customer, $iban, $mandate,
            $collection['description'] ?? '', $amountCents, $collection['created_by_user_id'], $collection['id']
        );
    } catch (CollectionDeferredException $e) {
        $release();
        throw $e;
    } catch (Throwable $e) {
        // Bei "unbekannt" bleibt der Einzug beansprucht (submitting), damit er nicht
        // erneut eingereicht wird; die Klärung erfolgt über collection_attempts_resolve().
        if (!($e instanceof CollectionUnknownOutcomeException)) {
            $release();
        }
        throw $e;
    }
    [$stripeCustomer, $paymentMethod, $paymentIntent] = $exec['result'];

    $pdo->prepare(
        "UPDATE payment_collections
         SET scheduled_submitted = 1, stripe_payment_intent_id = ?, stripe_status = 'processing', submitted_at = NOW()
         WHERE id = ?"
    )->execute([$paymentIntent['id'], $collection['id']]);
    collection_attempt_finish($exec['attempt']['id'], 'succeeded', $paymentIntent['id'], null, $collection['id']);

    $pdo->prepare(
        'UPDATE sepa_mandates SET stripe_payment_method_id = ?, stripe_customer_id = ? WHERE id = ?'
    )->execute([$paymentMethod['id'], $stripeCustomer['id'], $mandate['id']]);
    mandate_touch_used($mandate['id']);

    $pdo->prepare("UPDATE invoices SET collection_status = 'in_collection' WHERE id = ?")
        ->execute([$invoice['id']]);

    audit_log($tenantId, $collection['created_by_user_id'] ? ['user_id' => $collection['created_by_user_id']] : null,
        'collection_submitted', 'collection', $collection['id'], [
            'voucher_number' => $invoice['voucher_number'], 'amount_cents' => $amountCents,
            'scheduled' => true, 'payment_intent' => $paymentIntent['id'],
            'open_amount_cents' => $live['open_cents'], 'attempt_id' => $exec['attempt']['id'],
        ]);
    funnel_event_once($tenantId, 'first_collection', $collection['created_by_user_id']);
}
