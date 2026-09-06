<?php
/**
 * Lexware-Office-Synchronisation: offene/überfällige Rechnungen und Kontakte
 * in die lokale Datenbank übernehmen. Portiert aus sync_service.py.
 *
 * sync_invoices_step() verarbeitet nur eine kleine, feste Anzahl Rechnungen
 * pro Aufruf und liefert einen Cursor zum Fortsetzen zurück. Das ist nötig,
 * weil Shared-Hosting-Umgebungen (z.B. IONOS) das Zeitlimit für einen
 * einzelnen HTTP-Request strikt begrenzen, ein kompletter Durchlauf über
 * viele Rechnungen mit gedrosselten Lexware-Office-Aufrufen (max. ca. 2/s) das
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
require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/keywords.php';

/**
 * Einstellungen der Synchronisation (config 'sync') mit Standardwerten.
 *  - step_seconds: Zeitbudget je Schritt (Browser- oder Cron-Aufruf), höchstens step_max Rechnungen
 *  - skip_unchanged: Rechnungen mit unverändertem updatedDate ohne Detailabruf übernehmen
 *  - contact_refresh_hours: Kontaktdaten bekannter Kunden höchstens so oft neu laden (0 = immer)
 */
function sync_rules_config(): array
{
    $c = (array)config('sync', []);
    return [
        'step_seconds'          => max(2, min(25, (int)($c['step_seconds'] ?? 8))),
        'step_max'              => max(1, min(200, (int)($c['step_max'] ?? 40))),
        'step_max_api_calls'    => max(5, min(500, (int)($c['step_max_api_calls'] ?? 60))),
        'skip_unchanged'        => (bool)($c['skip_unchanged'] ?? true),
        'contact_refresh_hours' => max(0, min(720, (int)($c['contact_refresh_hours'] ?? 24))),
    ];
}

/** Lexware-Zeitstempel (z.B. 2026-09-05T15:15:09.447+02:00) als UTC-DATETIME-String, sonst null. */
function _sync_parse_datetime($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/** Leere Messwerte eines Laufs. */
function _sync_empty_metrics(): array
{
    return ['steps' => 0, 'api_calls' => 0, 'api_ms' => 0, 'throttle_ms' => 0, 'retries' => 0,
        'detail_calls' => 0, 'contact_calls' => 0, 'skipped_unchanged' => 0, 'contacts_reused' => 0, 'started_at' => time()];
}

/** Messwerte des Clients (nur beim echten Lexware-Client verfügbar) in die Laufmetrik übernehmen. */
function _sync_collect_client_metrics(InvoiceSource $lex, array &$metrics, int $calls0, float $ms0, float $thr0, int $ret0): void
{
    $client = $lex instanceof LexwareOfficeSource ? $lex->client() : ($lex instanceof LexofficeClient ? $lex : null);
    if ($client === null) {
        return;
    }
    $metrics['api_calls'] += $client->requestCount - $calls0;
    $metrics['api_ms'] += (int)round($client->requestMs - $ms0);
    $metrics['throttle_ms'] += (int)round($client->throttleMs - $thr0);
    $metrics['retries'] += $client->retryCount - $ret0;
}

/**
 * Einen Synchronisations-Schritt ausführen.
 *
 * @param array|null $cursor Cursor aus dem vorherigen Aufruf, oder null für
 *                           einen neuen Durchlauf.
 * @param int $batchSize 0 = zeitbasiert nach config sync.step_seconds (höchstens
 *                       step_max Rechnungen), sonst feste Anzahl je Schritt (Tests).
 * @return array{done:bool,cursor:array|null,result:array{synced:int,new:int,updated:int,removed:int}}
 */
function sync_invoices_step(string $tenantId, InvoiceSource $lex, ?array $cursor, int $batchSize = 0): array
{
    if ($cursor === null) {
        $cursor = [
            'phase'            => 'listing',
            'listing_status'   => 'open',
            'lex_page'         => 0,
            'lex_page_content' => null, // gecachter Inhalt der aktuellen Lexware-Office-Seite
            'lex_total_pages'  => 1,
            'collected'        => [], // gesammelte {id, voucherNumber, voucherStatus, updatedDate} aus 'listing'
            'proc_index'       => 0,
            'contact_cache'    => [], // contactId => extrahierte Kontaktfelder, vermeidet Mehrfachabrufe im selben Lauf
            'result'           => ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0],
            'recheck_ids'      => null,
            'metrics'          => _sync_empty_metrics(),
        ];
    }
    if (!isset($cursor['metrics'])) {
        $cursor['metrics'] = _sync_empty_metrics();
    }
    $rules = sync_rules_config();
    if (!empty($cursor['force_full'])) {
        $rules['skip_unchanged'] = false; // Vollabgleich: jede Rechnung mit Detailabruf prüfen
    }
    $limit = $batchSize > 0 ? $batchSize : $rules['step_max'];
    $deadline = $batchSize > 0 ? null : microtime(true) + $rules['step_seconds'];
    $client = $lex instanceof LexwareOfficeSource ? $lex->client() : ($lex instanceof LexofficeClient ? $lex : null);
    $calls0 = $client ? $client->requestCount : 0;
    $ms0 = $client ? $client->requestMs : 0.0;
    $thr0 = $client ? $client->throttleMs : 0.0;
    $ret0 = $client ? $client->retryCount : 0;
    $cursor['metrics']['steps']++;
    $finish = function (array $cursor, bool $done) use ($lex, $calls0, $ms0, $thr0, $ret0): array {
        _sync_collect_client_metrics($lex, $cursor['metrics'], $calls0, $ms0, $thr0, $ret0);
        $result = $cursor['result'] + ['metrics' => $cursor['metrics']];
        return ['done' => $done, 'cursor' => $done ? null : $cursor, 'result' => $result];
    };
    // Innerhalb des Zeit- und Aufrufbudgets weiterarbeiten; mindestens eine Rechnung je Schritt
    $maxCalls = $rules['step_max_api_calls'];
    $mayContinue = static function (int $processed) use ($deadline, $client, $calls0, $maxCalls): bool {
        if ($processed === 0) {
            return true;
        }
        if ($deadline !== null && microtime(true) >= $deadline) {
            return false;
        }
        return $client === null || ($client->requestCount - $calls0) < $maxCalls;
    };

    $pdo = db();
    $processed = 0;

    // --- Phase 'listing': günstig (nur Nummer/ID, keine Einzelabrufe) die
    //     komplette offene/überfällige Liste holen, dann absteigend nach
    //     Rechnungsnummer sortieren ---
    if ($cursor['phase'] === 'listing') {
        // Mehrere Listenseiten je Schritt, solange das Zeitbudget reicht: eine Seite
        // ist ein einziger günstiger Aufruf für bis zu 100 Rechnungen.
        $pages = 0;
        do {
            $voucherStatus = $cursor['listing_status'];

            // Eine Lexware-Office-Seite nur EINMAL abrufen und zwischenspeichern.
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
                        'updatedDate'   => $voucher['updatedDate'] ?? null,
                    ];
                }
            }

            $cursor['lex_page_content'] = null;
            $cursor['lex_page']++;
            $pages++;
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
        } while ($cursor['phase'] === 'listing' && $deadline !== null && microtime(true) < $deadline && $pages < 50);

        return $finish($cursor, false);
    }

    // --- Phase 'processing': die sortierte Liste abarbeiten (neueste
    //     Rechnungsnummer zuerst), hier passieren die teuren Einzelabrufe ---
    if ($cursor['phase'] === 'processing') {
        $list = $cursor['collected'];

        // step_max zählt nur Rechnungen mit Detailabruf; übersprungene (unveränderte)
        // kosten nur eine Datenbankabfrage und laufen innerhalb des Zeitbudgets durch.
        $iterations = 0;
        while ($processed < $limit && $cursor['proc_index'] < count($list) && $mayContinue($iterations)) {
            $voucher = $list[$cursor['proc_index']];
            $skippedBefore = $cursor['metrics']['skipped_unchanged'];
            $isNew = _sync_process_voucher($tenantId, $voucher, $lex, $cursor['contact_cache'], $rules, $cursor['metrics']);
            $cursor['result']['synced']++;
            $cursor['result'][$isNew ? 'new' : 'updated']++;
            $cursor['proc_index']++;
            $iterations++;
            if ($cursor['metrics']['skipped_unchanged'] === $skippedBefore) {
                $processed++;
            }
        }

        if ($cursor['proc_index'] >= count($list)) {
            // Kandidaten für den Recheck jetzt bestimmen: lokale offene/
            // überfällige Rechnungen, deren Lexware-Office-ID NICHT in der gerade
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
            // ein Lexware-Office-Aufruf pro Rechnung) überhaupt beginnt: Für
            // jede der oben gefundenen Rechnungen steht schon jetzt fest,
            // dass sie in Lexware Office nicht mehr offen/überfällig ist – auch
            // ohne den genauen neuen Status zu kennen. Damit Dashboard und
            // "Rechnungen" sofort korrekte Zahlen zeigen (statt erst nach
            // der oft minutenlangen Einzelprüfung bei großen Beständen),
            // wird der Sammelstatus 'not_open' in EINEM SQL-Befehl gesetzt.
            // collection_status nur anfassen, wenn dort noch der
            // Standardwert 'open' steht – laufende Stripe-Vorgänge
            // (in_collection/scheduled/...) werden dadurch nicht angetastet,
            // deren Status verwaltet die Einzugslogik bzw. der Stripe-
            // Webhook. Die anschließende Einzelprüfung (unten) ergänzt
            // danach in Ruhe den genauen Lexware-Office-Status (bezahlt,
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
            $cursor['contact_cache'] = [];
        }

        return $finish($cursor, false);
    }

    // --- Phase 'recheck': lokale offene/überfällige Rechnungen prüfen,
    //     die in diesem Durchlauf nicht in Lexware Office gesehen wurden
    //     (recheck_ids wurde beim Verlassen der 'processing'-Phase bereits
    //     bestimmt, siehe oben) ---
    while ($processed < $limit && $cursor['recheck_ids'] && $mayContinue($processed)) {
        $invoiceId = array_shift($cursor['recheck_ids']);
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();

        if ($inv) {
            try {
                $detail = $lex->getInvoiceDetail($inv['lexoffice_invoice_id']);
                $cursor['metrics']['detail_calls']++;
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
                    'UPDATE invoices SET lexoffice_status = ?, collection_status = ?, lexoffice_updated_at = ?, last_synced_at = NOW() WHERE id = ?'
                )->execute([$newStatus, $collectionStatus, _sync_parse_datetime($detail['updatedDate'] ?? null), $inv['id']]);
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
        $cursor['metrics']['duration_s'] = max(0, time() - (int)($cursor['metrics']['started_at'] ?? time()));
        return $finish($cursor, true);
    }

    return $finish($cursor, false);
}

/**
 * Vollständiger, blockierender Durchlauf ohne Cursor. Nur für CLI-Aufrufe
 * oder sehr kleine Bestände geeignet, für den interaktiven Web-Aufruf
 * sync_invoices_step() verwenden (siehe Datei-Kommentar oben).
 *
 * @return array{synced:int,new:int,updated:int,removed:int}
 */
function sync_invoices(string $tenantId, InvoiceSource $lex): array
{
    @set_time_limit(0);

    $pdo = db();
    $result = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
    $seenLexIds = [];
    $contactCache = [];

    foreach ($lex->getOpenInvoices() as $voucher) {
        if (empty($voucher['id'])) {
            continue;
        }
        $seenLexIds[] = $voucher['id'];
        $isNew = _sync_process_voucher($tenantId, $voucher, $lex, $contactCache);
        $result['synced']++;
        $result[$isNew ? 'new' : 'updated']++;
    }

    // Lokale offene/überfällige Rechnungen, deren Lexware-Office-ID NICHT in der
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

/**
 * Einen Voucher aus der Lexware-Office-Liste in die Datenbank übernehmen. Gibt
 * true zurück, wenn neu angelegt. $contactCache spart wiederholte
 * Lexware-Office-Kontaktabrufe, wenn mehrere Rechnungen im selben Lauf zum
 * gleichen Kunden gehören (z.B. mehrere offene Monate desselben Mieters).
 *
 * Unveränderte Rechnungen: Liefert die Voucherliste ein updatedDate und
 * stimmt es mit dem gespeicherten lexoffice_updated_at überein (gleicher
 * Status), entfällt der Detailabruf (config sync.skip_unchanged). Fehlt das
 * Feld, wird wie bisher jede Rechnung einzeln geladen (keine Annahme über die
 * API ohne Beleg in der Antwort).
 */
function _sync_process_voucher(string $tenantId, array $voucher, InvoiceSource $lex, array &$contactCache, ?array $rules = null, ?array &$metrics = null): bool
{
    $pdo = db();
    $rules = $rules ?? sync_rules_config();
    $voucherId = $voucher['id'];
    $listUpdated = _sync_parse_datetime($voucher['updatedDate'] ?? null);

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_invoice_id = ?');
    $stmt->execute([$tenantId, $voucherId]);
    $existing = $stmt->fetch();

    if ($existing && $rules['skip_unchanged'] && $listUpdated !== null
        && !empty($existing['lexoffice_updated_at']) && $existing['lexoffice_updated_at'] === $listUpdated
        && ($voucher['voucherStatus'] ?? null) === $existing['lexoffice_status']) {
        // Unverändert seit dem letzten Lauf: nur den Prüfzeitpunkt setzen, kein Detailabruf
        $pdo->prepare('UPDATE invoices SET last_synced_at = NOW() WHERE id = ?')->execute([$existing['id']]);
        if ($metrics !== null) {
            $metrics['skipped_unchanged']++;
        }
        return false;
    }

    $detail = $lex->getInvoiceDetail($voucherId);
    if ($metrics !== null) {
        $metrics['detail_calls']++;
    }
    $detailUpdated = _sync_parse_datetime($detail['updatedDate'] ?? null) ?? $listUpdated;

    // --- Kunde auflösen ---
    $customerId = null;
    $contactName = _sync_extract_contact_name($detail);
    $contactId = $detail['address']['contactId'] ?? null;
    if ($contactId) {
        $customerId = _sync_upsert_customer($tenantId, $contactId, $contactName, $lex, $contactCache, $rules, $metrics);
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
                lexoffice_updated_at = ?, last_synced_at = NOW()
             WHERE id = ?'
        )->execute([
            $voucherNumber, $customerId, $contactName, $totalAmount, $currency,
            $dueDate, $lexStatus, $newCollectionStatus, $itemsJson, $keyword, $keywordSepa,
            $detailUpdated, $existing['id'],
        ]);
        return false;
    }

    $pdo->prepare(
        'INSERT INTO invoices
            (id, tenant_id, lexoffice_invoice_id, voucher_number, customer_id, contact_name,
             total_gross_amount, currency, due_date, lexoffice_status, collection_status,
             line_items_json, keyword, keyword_sepa, lexoffice_updated_at, last_synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        uuid4(), $tenantId, $voucherId, $voucherNumber, $customerId, $contactName,
        $totalAmount, $currency, $dueDate, $lexStatus, 'open',
        $lineItemsJson, $kwDisplay, $kwSepa, $detailUpdated,
    ]);
    return true;
}

/**
 * Kunde aus dem Lexware-Kontakt anlegen oder aktualisieren.
 *
 * Kontaktabrufe sind teuer (ein API-Aufruf je Kontakt). Bekannte Kunden, deren
 * Kontakt innerhalb von sync.contact_refresh_hours bereits geladen wurde, werden
 * ohne Abruf wiederverwendet. Der Lauf-Cache speichert nur die extrahierten
 * Felder (Name, Kundennummer, E-Mail), nicht den vollständigen Kontakt, damit
 * der Cursor in sync_state klein bleibt.
 */
function _sync_upsert_customer(
    string $tenantId,
    string $contactId,
    string $fallbackName,
    InvoiceSource $lex,
    array &$contactCache,
    ?array $rules = null,
    ?array &$metrics = null
): string {
    $pdo = db();
    $rules = $rules ?? sync_rules_config();
    $stmt = $pdo->prepare(
        'SELECT * FROM customers WHERE tenant_id = ? AND lexoffice_contact_id = ?'
    );
    $stmt->execute([$tenantId, $contactId]);
    $existing = $stmt->fetch();

    // Bekannter Kunde, kürzlich abgeglichen: kein erneuter Kontaktabruf
    if ($existing && $rules['contact_refresh_hours'] > 0 && !empty($existing['lexoffice_synced_at'])
        && !array_key_exists($contactId, $contactCache)) {
        $syncedAt = strtotime((string)$existing['lexoffice_synced_at']);
        if ($syncedAt !== false && $syncedAt > time() - $rules['contact_refresh_hours'] * 3600) {
            if ($metrics !== null) {
                $metrics['contacts_reused']++;
            }
            return $existing['id'];
        }
    }

    if (array_key_exists($contactId, $contactCache)) {
        // Dieser Kontakt wurde in diesem Lauf bereits abgerufen (z.B. weil
        // eine andere Rechnung desselben Kunden vorher verarbeitet wurde).
        $fields = $contactCache[$contactId];
        if ($existing) {
            return $existing['id'];
        }
    } else {
        try {
            $contact = $lex->getContact($contactId);
        } catch (Throwable $e) {
            $contact = [];
        }
        if ($metrics !== null) {
            $metrics['contact_calls']++;
        }
        $fields = [
            'name'   => _sync_extract_customer_name($contact),
            'number' => (string)($contact['roles']['customer']['number'] ?? '10001'),
            'email'  => _sync_extract_email($contact),
        ];
        $contactCache[$contactId] = $fields;
    }

    $name = $fields['name'] ?: $fallbackName;
    $customerNumber = (string)$fields['number'];
    $email = $fields['email'];
    $isWalkIn = $customerNumber === '10001' ? 1 : 0;

    if ($existing) {
        if ($existing['name'] !== $name || $existing['customer_number'] !== $customerNumber || $existing['email'] !== $email || (int)$existing['is_walk_in'] !== $isWalkIn) {
            $pdo->prepare(
                'UPDATE customers SET name = ?, customer_number = ?, email = ?, is_walk_in = ?, lexoffice_synced_at = NOW() WHERE id = ?'
            )->execute([$name, $customerNumber, $email, $isWalkIn, $existing['id']]);
        } else {
            $pdo->prepare('UPDATE customers SET lexoffice_synced_at = NOW() WHERE id = ?')->execute([$existing['id']]);
        }
        return $existing['id'];
    }

    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email, is_walk_in, lexoffice_synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
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
