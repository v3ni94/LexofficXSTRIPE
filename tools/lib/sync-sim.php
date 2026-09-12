<?php
/**
 * Prueffaelle der Synchronisation (app/sync.php) gegen eine temporaere MariaDB mit einer Fake-Rechnungsquelle ueber den
 * CLI-Testhaken lexsepa_lex_client_factory. Aufrufer: tools/sync-check.sh. Kein Zugriff auf Lexware Office.
 *
 *   SMARTEINZUG_CONFIG=<sandbox> php tools/lib/sync-sim.php <repo> <fall>
 *
 * Faelle (Audit 10.09.2026):
 *   kontaktfehler   B-01: technischer Fehler beim Kontaktabruf darf bestehende Kundendaten nicht ueberschreiben
 *   kontaktfehlt    B-01: fachlich fehlender Kontakt fuer einen NEUEN Kunden -> Ersatzkunde wie bisher
 *   recheck-fehler  B-07: Fehler bei der Nachpruefung -> Rechnung wird im naechsten Lauf erneut geprueft (kein Dauerzustand not_open)
 *   bezahlt-terminiert B-08: Rechnung in Lexware bezahlt -> terminierter Einzug storniert, Rechnung nicht "fehlgeschlagen"
 *   auth            B-03: 401-Meldung wird als Kategorie auth erkannt (kein endloser Retry)
 * Ausgabe: Zeilen "schluessel=wert".
 */
declare(strict_types=1);
define('LOG_SERVICE', 'cli');
require $argv[1] . '/php-ionos/bin/_cli.php';
require $argv[1] . '/tools/lib/test-guard.php';
require_once $argv[1] . '/php-ionos/app/queue.php';
require_once $argv[1] . '/php-ionos/app/sync_state.php';
require_once $argv[1] . '/php-ionos/app/sync.php';
require_once $argv[1] . '/php-ionos/app/collections.php';
require_once $argv[1] . '/php-ionos/app/lexoffice.php';

require $argv[1] . '/tools/lib/sync-sim-source.php';

$pdo = db();
test_guard_assert_db($pdo);
$fake = new FakeSyncSource();
$GLOBALS['lexsepa_lex_client_factory'] = static fn(string $tenantId): InvoiceSource => $fake;
$out = static function (string $k, $v): void { echo $k, '=', is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE), "\n"; };
$T = 'cccccccc-0000-0000-0000-00000000000c';
foreach (['payment_collections', 'collection_attempts', 'invoices', 'customers', 'sync_state', 'sync_runs', 'integrations', 'organizations'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec("INSERT INTO organizations (id, name, mandate_prefix, onboarding_completed) VALUES ('$T', 'Firma Sync', 'FS', 1)");
$pdo->prepare('INSERT INTO integrations (id, tenant_id, invoice_source, lexoffice_connected, lexoffice_api_key_encrypted) VALUES (UUID(), ?, ?, 1, ?)')
    ->execute([$T, 'lexware_office', encrypt_value('lex-fake')]);
$K1 = 'dddddddd-1111-0000-0000-000000000001';
$R1 = 'dddddddd-2222-0000-0000-000000000001';
$R2 = 'dddddddd-2222-0000-0000-000000000002';
$upd = '2026-09-09T10:00:00.000+02:00';

/** Vollstaendige Synchronisation in Schritten (wie der Worker), hoechstens 20 Schritte. */
$runSync = static function (string $tenant) use ($fake): array {
    sync_state_start($tenant, ['user_id' => null, 'email' => 'sim']);
    $last = [];
    for ($i = 0; $i < 20; $i++) {
        try {
            $last = sync_state_step($tenant);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        if (!empty($last['done']) || !empty($last['skipped'])) {
            break;
        }
    }
    return $last;
};
$kunde = static function (string $tenant, string $contactId) use ($pdo): array {
    $st = $pdo->prepare('SELECT customer_number, name, email, is_walk_in FROM customers WHERE tenant_id = ? AND lexoffice_contact_id = ?');
    $st->execute([$tenant, $contactId]);
    return $st->fetch() ?: [];
};
$rechnung = static function (string $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT lexoffice_status, collection_status FROM invoices WHERE id = ? OR lexoffice_invoice_id = ?');
    $st->execute([$id, $id]);
    return $st->fetch() ?: [];
};

switch ($argv[2] ?? '') {
    case 'kontaktfehler':
        $pdo->prepare("INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email, is_walk_in, lexoffice_synced_at) VALUES (UUID(), ?, ?, '20017', 'Bestandskunde GmbH', 'buchhaltung@bestand.test', 0, DATE_SUB(NOW(), INTERVAL 2 DAY))")->execute([$T, $K1]);
        $fake->invoices[$R1] = ['number' => 'RE-1', 'voucherStatus' => 'open', 'contactId' => $K1, 'amount' => 100.0, 'updatedDate' => $upd];
        $fake->contacts[$K1] = new LexofficeException('Lexware Office Serverfehler 503 nach Retries.');
        $r = $runSync($T);
        $out('status', isset($r['error']) ? 'fehler' : ($r['status'] ?? '?'));
        $k = $kunde($T, $K1);
        $out('kunde_nummer', $k['customer_number'] ?? '(fehlt)');
        $out('kunde_email', $k['email'] ?? '(fehlt)');
        $out('kunde_walkin', (int)($k['is_walk_in'] ?? -1));
        break;

    case 'kontaktfehlt':
        $fake->invoices[$R1] = ['number' => 'RE-1', 'voucherStatus' => 'open', 'contactId' => $K1, 'amount' => 100.0, 'updatedDate' => $upd];
        // kein Kontakt hinterlegt -> 404 (fachlich fehlend)
        $r = $runSync($T);
        $out('status', $r['status'] ?? '?');
        $k = $kunde($T, $K1);
        $out('kunde_nummer', $k['customer_number'] ?? '(fehlt)');
        $out('kunde_walkin', (int)($k['is_walk_in'] ?? -1));
        $out('rechnung_status', $rechnung($R1)['lexoffice_status'] ?? '(fehlt)');
        break;

    case 'recheck-fehler':
        $fake->contacts[$K1] = ['roles' => ['customer' => ['number' => '20017']], 'company' => ['name' => 'Bestandskunde GmbH'], 'emailAddresses' => ['business' => ['buchhaltung@bestand.test']]];
        $fake->invoices[$R1] = ['number' => 'RE-1', 'voucherStatus' => 'open', 'contactId' => $K1, 'amount' => 100.0, 'updatedDate' => $upd];
        $fake->invoices[$R2] = ['number' => 'RE-2', 'voucherStatus' => 'open', 'contactId' => $K1, 'amount' => 50.0, 'updatedDate' => $upd];
        $runSync($T);
        $out('lauf1_r1', $rechnung($R1)['lexoffice_status'] ?? '?');
        // R1 verschwindet aus der Liste (bezahlt), Detailabruf scheitert einmal technisch
        $fake->invoices[$R1]['voucherStatus'] = 'paid';
        $fake->detailErrors[$R1] = new LexofficeException('Lexware Office Serverfehler 503 nach Retries.');
        $r2 = $runSync($T);
        $out('lauf2_status', $r2['status'] ?? '?');
        $out('lauf2_r1', $rechnung($R1)['lexoffice_status'] ?? '?');
        // dritter Lauf: Abruf funktioniert wieder
        unset($fake->detailErrors[$R1]);
        $runSync($T);
        $out('lauf3_r1', $rechnung($R1)['lexoffice_status'] ?? '?');
        $out('lauf3_r1_collection', $rechnung($R1)['collection_status'] ?? '?');
        break;

    case 'bezahlt-terminiert':
        $fake->contacts[$K1] = ['roles' => ['customer' => ['number' => '20017']], 'company' => ['name' => 'Bestandskunde GmbH'], 'emailAddresses' => ['business' => ['buchhaltung@bestand.test']]];
        $fake->invoices[$R1] = ['number' => 'RE-1', 'voucherStatus' => 'open', 'contactId' => $K1, 'amount' => 100.0, 'updatedDate' => $upd];
        $runSync($T);
        $st = $pdo->prepare('SELECT id, customer_id FROM invoices WHERE tenant_id = ? AND lexoffice_invoice_id = ?');
        $st->execute([$T, $R1]);
        $inv = $st->fetch();
        // terminierter Einzug (direkt eingefuegt: Mandat und IBAN sind fuer diesen Fall unerheblich)
        $pdo->prepare("INSERT INTO payment_collections (id, tenant_id, invoice_id, amount_cents, currency, stripe_status, is_scheduled, scheduled_date, scheduled_submitted) VALUES ('eeeeeeee-0000-0000-0000-000000000001', ?, ?, 10000, 'EUR', 'scheduled', 1, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 0)")
            ->execute([$T, $inv['id']]);
        $pdo->prepare("UPDATE invoices SET collection_status = 'scheduled' WHERE id = ?")->execute([$inv['id']]);
        // Rechnung wird in Lexware bezahlt
        $fake->invoices[$R1]['voucherStatus'] = 'paid';
        $runSync($T);
        $st = $pdo->prepare('SELECT stripe_status, note FROM payment_collections WHERE id = ?');
        $st->execute(['eeeeeeee-0000-0000-0000-000000000001']);
        $c = $st->fetch();
        $out('einzug_status', $c['stripe_status'] ?? '?');
        $out('einzug_note', $c['note'] ?? '');
        $out('rechnung_lex', $rechnung($inv['id'])['lexoffice_status'] ?? '?');
        $out('rechnung_collection', $rechnung($inv['id'])['collection_status'] ?? '?');
        // Faelligkeitslauf darf den stornierten Einzug nicht anfassen
        $pdo->prepare("UPDATE payment_collections SET scheduled_date = CURDATE() WHERE id = ?")->execute(['eeeeeeee-0000-0000-0000-000000000001']);
        $r = process_scheduled_collections($T, null, ['ignore_window' => true]);
        $out('faellig_failed', (int)$r['failed']);
        $out('rechnung_collection_nach_lauf', $rechnung($inv['id'])['collection_status'] ?? '?');
        break;

    case 'auth':
        require_once $argv[1] . '/php-ionos/app/monitor.php';
        $out('kategorie_401', monitor_category(new LexofficeException('Lexware Office API-Key ungültig oder abgelaufen (HTTP 401).')));
        $out('kategorie_alt', monitor_category(new LexofficeException('Lexware Office API-Key ungültig oder abgelaufen.')));
        break;

    default:
        fwrite(STDERR, "Unbekannter Fall\n");
        exit(2);
}
