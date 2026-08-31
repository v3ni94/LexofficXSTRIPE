<?php
/**
 * Lexoffice-Synchronisation: offene/überfällige Rechnungen und Kontakte
 * in die lokale Datenbank übernehmen. Portiert aus sync_service.py.
 *
 * sync_invoices_step() verarbeitet nur eine kleine, feste Anzahl Rechnungen
 * pro Aufruf und liefert einen Cursor zum Fortsetzen zurück. Das ist nötig,
 * weil Shared-Hosting-Umgebungen (z.B. IONOS) das Zeitlimit für einen
 * einzelnen HTTP-Request strikt begrenzen, ein kompletter Durchlauf über
 * viele Rechnungen mit gedrosselten Lexoffice-Aufrufen (max. ca. 2/s) das
 * aber leicht überschreitet ("Page temporarily unavailable"). Der
 * aufrufende Code (invoices.php) ruft diese Funktion wiederholt auf, bis
 * done=true zurückkommt.
 *
 * Reihenfolge: Zuerst wird in der Phase 'listing' günstig (nur Rechnungs-
 * nummer/ID, keine Einzelabrufe) die komplette offene/überfällige Liste
 * geholt und nach Rechnungsnummer absteigend sortiert. Erst danach beginnt
 * die eigentliche Verarbeitung (Phase 'processing') mit den teuren
 * Einzelabrufen pro Rechnung. So stehen bei einem Abbruch mitten im Lauf
 * bereits die neuesten Rechnungen aktuell in der Datenbank.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/lexoffice.php';
require_once __DIR__ . '/keywords.php';

/**
 * Einen Synchronisations-Schritt ausführen.
 *
 * @param array|null $cursor Cursor aus dem vorherigen Aufruf, oder null für
 *                           einen neuen Durchlauf.
 * @return array{done:bool,cursor:array|null,result:array{synced:int,new:int,updated:int,removed:int}}
 */
function sync_invoices_step(string $tenantId, LexofficeClient $lex, ?array $cursor, int $batchSize = 6): array
{
    if ($cursor === null) {
        $cursor = [
            'phase'            => 'listing',
            'listing_status'   => 'open',
            'lex_page'         => 0,
            'lex_page_content' => null, // gecachter Inhalt der aktuellen Lexoffice-Seite
            'lex_total_pages'  => 1,
            'collected'        => [], // gesammelte {id, voucherNumber, voucherStatus} aus 'listing'
            'proc_index'       => 0,
            'result'           => ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0],
            'recheck_ids'      => null,
        ];
    }

    $pdo = db();
    $processed = 0;

    // --- Phase 'listing': günstig (nur Nummer/ID, keine Einzelabrufe) die
    //     komplette offene/überfällige Liste holen, dann absteigend nach
    //     Rechnungsnummer sortieren ---
    if ($cursor['phase'] === 'listing') {
        $voucherStatus = $cursor['listing_status'];

        // Eine Lexoffice-Seite nur EINMAL abrufen und zwischenspeichern.
        // Würde man dieselbe Seite bei jedem Batch erneut abrufen, könnten
        // sich Position/Inhalt zwischen den Aufrufen verschieben (z.B. weil
        // zwischenzeitlich eine Rechnung bezahlt wurde) und Einträge würden
        // übersprungen oder doppelt gezählt.
        if ($cursor['lex_page_content'] === null) {
            $page = $lex->getInvoiceVouchersPage($voucherStatus, $cursor['lex_page']);
            $cursor['lex_page_content'] = $page['content'] ?? [];
            $cursor['lex_total_pages'] = (int)($page['totalPages'] ?? 1);
        }

        foreach ($cursor['lex_page_content'] as $voucher) {
            if (!empty($voucher['id'])) {
                $cursor['collected'][] = [
                    'id'            => $voucher['id'],
                    'voucherNumber' => $voucher['voucherNumber'] ?? $voucher['id'],
                    'voucherStatus' => $voucher['voucherStatus'] ?? $voucherStatus,
                ];
            }
        }

        $cursor['lex_page_content'] = null;
        $cursor['lex_page']++;
        if ($cursor['lex_page'] >= $cursor['lex_total_pages']) {
            $cursor['lex_page'] = 0;
            if ($voucherStatus === 'open') {
                $cursor['listing_status'] = 'overdue';
            } else {
                // Beide Status vollständig aufgelistet: sortieren und mit
                // der eigentlichen Verarbeitung beginnen.
                usort($cursor['collected'], function (array $a, array $b): int {
                    return _voucher_sort_key($b['voucherNumber']) <=> _voucher_sort_key($a['voucherNumber']);
                });
                $cursor['phase'] = 'processing';
            }
        }

        return ['done' => false, 'cursor' => $cursor, 'result' => $cursor['result']];
    }

    // --- Phase 'processing': die sortierte Liste abarbeiten (neueste
    //     Rechnungsnummer zuerst), hier passieren die teuren Einzelabrufe ---
    if ($cursor['phase'] === 'processing') {
        $list = $cursor['collected'];

        while ($processed < $batchSize && $cursor['proc_index'] < count($list)) {
            $voucher = $list[$cursor['proc_index']];
            $isNew = _sync_process_voucher($tenantId, $voucher, $lex);
            $cursor['result']['synced']++;
            $cursor['result'][$isNew ? 'new' : 'updated']++;
            $cursor['proc_index']++;
            $processed++;
        }

        if ($cursor['proc_index'] >= count($list)) {
            // Kandidaten für den Recheck jetzt bestimmen: lokale offene/
            // überfällige Rechnungen, deren Lexoffice-ID NICHT in der gerade
            // aktuell abgerufenen Liste auftauchte. Ein reiner Zeitstempel-
            // Vergleich (frühere Version) konnte bei zwei sehr schnell
            // aufeinanderfolgenden Durchläufen innerhalb derselben Sekunde
            // eine Statusänderung übersehen, da MySQL-Zeitstempel nur
            // Sekundengenauigkeit haben. Die Mengendifferenz ist eindeutig
            // und zeitunabhängig korrekt.
            $seenLexIds = array_column($list, 'id');
            if ($seenLexIds) {
                $placeholders = implode(',', array_fill(0, count($seenLexIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT id FROM invoices
                     WHERE tenant_id = ? AND lexoffice_status IN ('open', 'overdue')
                       AND lexoffice_invoice_id NOT IN ($placeholders)"
                );
                $stmt->execute(array_merge([$tenantId], $seenLexIds));
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id FROM invoices WHERE tenant_id = ? AND lexoffice_status IN ('open', 'overdue')"
                );
                $stmt->execute([$tenantId]);
            }
            $cursor['recheck_ids'] = array_column($stmt->fetchAll(), 'id');

            // Sofortkorrektur, BEVOR die langsame Einzelprüfung (unten,
            // ein Lexoffice-Aufruf pro Rechnung) überhaupt beginnt: Für
            // jede der oben gefundenen Rechnungen steht schon jetzt fest,
            // dass sie in Lexoffice nicht mehr offen/überfällig ist – auch
            // ohne den genauen neuen Status zu kennen. Damit Dashboard und
            // "Rechnungen" sofort korrekte Zahlen zeigen (statt erst nach
            // der oft minutenlangen Einzelprüfung bei großen Beständen),
            // wird der Sammelstatus 'not_open' in EINEM SQL-Befehl gesetzt.
            // collection_status nur anfassen, wenn dort noch der
            // Standardwert 'open' steht – laufende Stripe-Vorgänge
            // (in_collection/scheduled/...) werden dadurch nicht angetastet,
            // deren Status verwaltet die Einzugslogik bzw. der Stripe-
            // Webhook. Die anschließende Einzelprüfung (unten) ergänzt
            // danach in Ruhe den genauen Lexoffice-Status (bezahlt,
            // storniert, ...) für die Anzeige.
            if ($cursor['recheck_ids']) {
                $placeholders2 = implode(',', array_fill(0, count($cursor['recheck_ids']), '?'));
                $pdo->prepare(
                    "UPDATE invoices
                     SET lexoffice_status = 'not_open',
                         collection_status = IF(collection_status = 'open', 'none', collection_status),
                         last_synced_at = NOW()
                     WHERE tenant_id = ? AND id IN ($placeholders2)"
                )->execute(array_merge([$tenantId], $cursor['recheck_ids']));
            }

            $cursor['phase'] = 'recheck';
            $cursor['collected'] = []; // Session klein halten, wird nicht mehr gebraucht
        }

        return ['done' => false, 'cursor' => $cursor, 'result' => $cursor['result']];
    }

    // --- Phase 'recheck': lokale offene/überfällige Rechnungen prüfen,
    //     die in diesem Durchlauf nicht in Lexoffice gesehen wurden
    //     (recheck_ids wurde beim Verlassen der 'processing'-Phase bereits
    //     bestimmt, siehe oben) ---
    while ($processed < $batchSize && $cursor['recheck_ids']) {
        $invoiceId = array_shift($cursor['recheck_ids']);
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();

        if ($inv) {
            try {
                $detail = $lex->getInvoiceDetail($inv['lexoffice_invoice_id']);
                $newStatus = $detail['voucherStatus'] ?? 'unknown';
                $collectionStatus = $inv['collection_status'];

                if ($newStatus === 'paid') {
                    $collectionStatus = 'collected';
                    $cursor['result']['removed']++;
                } elseif (in_array($newStatus, ['voided', 'cancelled'], true)) {
                    $collectionStatus = 'none';
                    $cursor['result']['removed']++;
                }

                $pdo->prepare(
                    'UPDATE invoices SET lexoffice_status = ?, collection_status = ?, last_synced_at = NOW() WHERE id = ?'
                )->execute([$newStatus, $collectionStatus, $inv['id']]);
                $cursor['result']['updated']++;
            } catch (Throwable $e) {
                error_log('Konnte Rechnung ' . $inv['lexoffice_invoice_id'] . ' nicht pruefen: ' . $e->getMessage());
            }
        }
        $processed++;
    }

    if (!$cursor['recheck_ids']) {
        $pdo->prepare('UPDATE integrations SET lexoffice_last_sync = NOW() WHERE tenant_id = ?')
            ->execute([$tenantId]);
        return ['done' => true, 'cursor' => null, 'result' => $cursor['result']];
    }

    return ['done' => false, 'cursor' => $cursor, 'result' => $cursor['result']];
}

/**
 * Vollständiger, blockierender Durchlauf ohne Cursor. Nur für CLI-Aufrufe
 * oder sehr kleine Bestände geeignet, für den interaktiven Web-Aufruf
 * sync_invoices_step() verwenden (siehe Datei-Kommentar oben).
 *
 * @return array{synced:int,new:int,updated:int,removed:int}
 */
function sync_invoices(string $tenantId, LexofficeClient $lex): array
{
    @set_time_limit(0);

    $pdo = db();
    $result = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
    $seenLexIds = [];

    foreach ($lex->getOpenInvoices() as $voucher) {
        if (empty($voucher['id'])) {
            continue;
        }
        $seenLexIds[] = $voucher['id'];
        $isNew = _sync_process_voucher($tenantId, $voucher, $lex);
        $result['synced']++;
        $result[$isNew ? 'new' : 'updated']++;
    }

    // Lokale offene/überfällige Rechnungen, deren Lexoffice-ID NICHT in der
    // gerade abgerufenen Liste auftauchte, erneut prüfen (bezahlt, storniert,
    // ...). Mengendifferenz statt Zeitstempelvergleich, siehe
    // sync_invoices_step() für die Begründung.
    if ($seenLexIds) {
        $placeholders = implode(',', array_fill(0, count($seenLexIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_status IN ('open', 'overdue')
               AND lexoffice_invoice_id NOT IN ($placeholders)"
        );
        $stmt->execute(array_merge([$tenantId], $seenLexIds));
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_status IN ('open', 'overdue')"
        );
        $stmt->execute([$tenantId]);
    }
    $localOpen = $stmt->fetchAll();

    foreach ($localOpen as $inv) {
        try {
            $detail = $lex->getInvoiceDetail($inv['lexoffice_invoice_id']);
            $newStatus = $detail['voucherStatus'] ?? 'unknown';
            $collectionStatus = $inv['collection_status'];

            if ($newStatus === 'paid') {
                $collectionStatus = 'collected';
                $result['removed']++;
            } elseif (in_array($newStatus, ['voided', 'cancelled'], true)) {
                $collectionStatus = 'none';
                $result['removed']++;
            }

            $pdo->prepare(
                'UPDATE invoices SET lexoffice_status = ?, collection_status = ?, last_synced_at = NOW() WHERE id = ?'
            )->execute([$newStatus, $collectionStatus, $inv['id']]);
            $result['updated']++;
        } catch (Throwable $e) {
            error_log('Konnte Rechnung ' . $inv['lexoffice_invoice_id'] . ' nicht pruefen: ' . $e->getMessage());
        }
    }

    $pdo->prepare('UPDATE integrations SET lexoffice_last_sync = NOW() WHERE tenant_id = ?')
        ->execute([$tenantId]);

    return $result;
}

// ---------------------------------------------------------------------------

/**
 * Vergleichbaren Sortierschlüssel aus einer Rechnungsnummer extrahieren
 * (alle enthaltenen Ziffern zusammengenommen), damit "RE04878" > "RE12"
 * und "RE-2026-045" > "RE-2025-999" korrekt als neuer eingestuft werden,
 * statt nur alphabetisch zu vergleichen.
 */
function _voucher_sort_key(string $voucherNumber): int
{
    $digits = preg_replace('/\D/', '', $voucherNumber);
    if ($digits === '') {
        return 0;
    }
    // Auf eine handhabbare Länge begrenzen, falls ungewöhnlich viele Ziffern
    // vorkommen (verhindert Überlauf bei (int)-Cast).
    return (int)substr($digits, 0, 15);
}

/** Einen Voucher aus der Lexoffice-Liste in die Datenbank übernehmen. Gibt true zurück, wenn neu angelegt. */
function _sync_process_voucher(string $tenantId, array $voucher, LexofficeClient $lex): bool
{
    $pdo = db();
    $voucherId = $voucher['id'];
    $detail = $lex->getInvoiceDetail($voucherId);

    // --- Kunde auflösen ---
    $customerId = null;
    $contactName = _sync_extract_contact_name($detail);
    $contactId = $detail['address']['contactId'] ?? null;
    if ($contactId) {
        $customerId = _sync_upsert_customer($tenantId, $contactId, $contactName, $lex);
    }

    // --- Rechnungsfelder ---
    $voucherNumber = $detail['voucherNumber'] ?? ($voucher['voucherNumber'] ?? '');
    $totalAmount = (string)($detail['totalPrice']['totalGrossAmount'] ?? '0');
    $currency = $detail['totalPrice']['currency'] ?? 'EUR';
    $dueDate = _sync_parse_date($detail['dueDate'] ?? null);
    $lexStatus = $detail['voucherStatus'] ?? ($voucher['voucherStatus'] ?? 'open');

    // --- Stichwort aus Positionen ---
    $lineItems = $detail['lineItems'] ?? [];
    $lineItemsJson = $lineItems ? json_encode($lineItems, JSON_UNESCAPED_UNICODE) : null;
    [$kwDisplay, $kwSepa] = extract_keyword($lineItems);

    // --- Upsert Rechnung ---
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_invoice_id = ?');
    $stmt->execute([$tenantId, $voucherId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $newCollectionStatus = $existing['collection_status'];
        if ($lexStatus === 'paid' && $existing['collection_status'] !== 'collected') {
            $newCollectionStatus = 'collected';
        }

        // Stichwort nur neu berechnen, wenn sich die Positionen geändert haben
        $keyword = $existing['keyword'];
        $keywordSepa = $existing['keyword_sepa'];
        $itemsJson = $existing['line_items_json'];
        if ($lineItemsJson !== $existing['line_items_json']) {
            $itemsJson = $lineItemsJson;
            $keyword = $kwDisplay;
            $keywordSepa = $kwSepa;
        }

        $pdo->prepare(
            'UPDATE invoices SET voucher_number = ?, customer_id = ?, contact_name = ?,
                total_gross_amount = ?, currency = ?, due_date = ?, lexoffice_status = ?,
                collection_status = ?, line_items_json = ?, keyword = ?, keyword_sepa = ?,
                last_synced_at = NOW()
             WHERE id = ?'
        )->execute([
            $voucherNumber, $customerId, $contactName, $totalAmount, $currency,
            $dueDate, $lexStatus, $newCollectionStatus, $itemsJson, $keyword, $keywordSepa,
            $existing['id'],
        ]);
        return false;
    }

    $pdo->prepare(
        'INSERT INTO invoices
            (id, tenant_id, lexoffice_invoice_id, voucher_number, customer_id, contact_name,
             total_gross_amount, currency, due_date, lexoffice_status, collection_status,
             line_items_json, keyword, keyword_sepa, last_synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        uuid4(), $tenantId, $voucherId, $voucherNumber, $customerId, $contactName,
        $totalAmount, $currency, $dueDate, $lexStatus, 'open',
        $lineItemsJson, $kwDisplay, $kwSepa,
    ]);
    return true;
}

function _sync_upsert_customer(string $tenantId, string $contactId, string $fallbackName, LexofficeClient $lex): string
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM customers WHERE tenant_id = ? AND lexoffice_contact_id = ?'
    );
    $stmt->execute([$tenantId, $contactId]);
    $existing = $stmt->fetch();

    try {
        $contact = $lex->getContact($contactId);
    } catch (Throwable $e) {
        $contact = [];
    }

    $name = _sync_extract_customer_name($contact) ?: $fallbackName;
    $customerNumber = (string)($contact['roles']['customer']['number'] ?? '10001');
    $email = _sync_extract_email($contact);
    $isWalkIn = $customerNumber === '10001' ? 1 : 0;

    if ($existing) {
        $pdo->prepare(
            'UPDATE customers SET name = ?, customer_number = ?, email = ?, is_walk_in = ? WHERE id = ?'
        )->execute([$name, $customerNumber, $email, $isWalkIn, $existing['id']]);
        return $existing['id'];
    }

    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email, is_walk_in)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $tenantId, $contactId, $customerNumber, $name, $email, $isWalkIn]);
    return $id;
}

function _sync_extract_contact_name(array $detail): string
{
    $address = $detail['address'] ?? [];
    if (!empty($address['name'])) {
        return $address['name'];
    }
    if (!empty($address['supplement'])) {
        return $address['supplement'];
    }
    return 'Unbekannt';
}

function _sync_extract_customer_name(array $contact): ?string
{
    if (!empty($contact['company']['name'])) {
        return $contact['company']['name'];
    }
    $person = $contact['person'] ?? [];
    $full = trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? ''));
    return $full !== '' ? $full : null;
}

function _sync_extract_email(array $contact): ?string
{
    $emails = $contact['emailAddresses'] ?? [];
    foreach (['business', 'office', 'private', 'other'] as $key) {
        if (!empty($emails[$key][0])) {
            return $emails[$key][0];
        }
    }
    return null;
}

function _sync_parse_date($value): ?string
{
    if (!$value || !is_string($value)) {
        return null;
    }
    $date = substr($value, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
}
