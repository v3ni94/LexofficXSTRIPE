<?php
/**
 * Hilfsfaelle fuer tools/perf-probe.sh (Leistungsmessung gegen temporaere MariaDB und Stubs, kein Netz).
 *   seed-collections <n>   Firma A mit n Kunden, Mandaten, Rechnungen und faelligen terminierten Einzuegen anlegen (ersetzt Bestand)
 *   seed-jobs <m>          m Jobs vom Typ maintenance einreihen
 *   reserve-all <worker>   Jobs reservieren und sofort abschliessen, bis keiner mehr da ist; gibt reserviert= und ids= aus
 *   sync <v>               Synchronisation mit Fake-Quelle (v Belege, v/5 Kontakte) in Schritten; Dauer und Schritte
 */
declare(strict_types=1);
define('LOG_SERVICE', 'cli');
define('IN_WORKER', true);
require $argv[1] . '/php-ionos/bin/_cli.php';
require $argv[1] . '/tools/lib/test-guard.php';
require_once $argv[1] . '/php-ionos/app/queue.php';
require_once $argv[1] . '/php-ionos/app/jobs.php';
require_once $argv[1] . '/php-ionos/app/sync_state.php';
require_once $argv[1] . '/php-ionos/app/sync.php';
require_once $argv[1] . '/php-ionos/app/collections.php';
require_once $argv[1] . '/php-ionos/app/crypto.php';
$pdo = db();
test_guard_assert_db($pdo);
$A = 'aaaaaaaa-0000-0000-0000-00000000000a';
switch ($argv[2] ?? '') {
    case 'seed-collections':
        $n = max(1, (int)($argv[3] ?? 60));
        foreach (['collection_attempts', 'payment_collections', 'invoices', 'sepa_mandates', 'customer_ibans', 'customers', 'integrations', 'organizations'] as $t) { $pdo->exec("DELETE FROM $t"); }
        $pdo->exec("INSERT INTO organizations (id, name, mandate_prefix, onboarding_completed, require_signed_mandate, subscription_status, billing_exempt) VALUES ('$A', 'Perf A', 'PA', 1, 1, 'active', 1)");
        $pdo->prepare('INSERT INTO integrations (id, tenant_id, invoice_source, lexoffice_connected, lexoffice_api_key_encrypted, stripe_connected, stripe_secret_key_encrypted, stripe_mode) VALUES (UUID(), ?, ?, 1, ?, 1, ?, ?)')
            ->execute([$A, 'lexware_office', encrypt_value('lex-PA'), encrypt_value('sk_test_stub_PA'), 'test']);
        $pdo->beginTransaction();
        for ($i = 1; $i <= $n; $i++) {
            $cust = sprintf('aaaaaaaa-2222-0000-0000-%012d', $i); $iban = sprintf('aaaaaaaa-3333-0000-0000-%012d', $i);
            $inv = sprintf('aaaaaaaa-4444-0000-0000-%012d', $i); $lexi = sprintf('aaaaaaaa-5555-0000-0000-%012d', $i); $man = sprintf('aaaaaaaa-6666-0000-0000-%012d', $i);
            $pdo->prepare('INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email) VALUES (?, ?, UUID(), ?, ?, ?)')->execute([$cust, $A, 'K' . $i, 'Kunde ' . $i, 'k' . $i . '@perf.test']);
            $pdo->prepare('INSERT INTO customer_ibans (id, tenant_id, customer_id, iban, account_holder_name, is_active) VALUES (?, ?, ?, ?, ?, 1)')->execute([$iban, $A, $cust, 'DE02120300000000202051', 'Kunde ' . $i]);
            $pdo->prepare("INSERT INTO sepa_mandates (id, tenant_id, customer_id, customer_iban_id, mandate_reference, mandate_date, is_active, status, mandate_type, signed_date, signed_place) VALUES (?, ?, ?, ?, ?, CURDATE(), 1, 'active', 'recurrent', CURDATE(), 'Teststadt')")->execute([$man, $A, $cust, $iban, 'PAK' . $i]);
            $pdo->prepare("INSERT INTO invoices (id, tenant_id, lexoffice_invoice_id, voucher_number, customer_id, contact_name, total_gross_amount, currency, due_date, lexoffice_status, collection_status) VALUES (?, ?, ?, ?, ?, ?, 100.00, 'EUR', CURDATE(), 'open', 'scheduled')")->execute([$inv, $A, $lexi, 'RE-' . $i, $cust, 'Kunde ' . $i]);
            $pdo->prepare("INSERT INTO payment_collections (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency, stripe_status, is_scheduled, scheduled_date, scheduled_submitted) VALUES (UUID(), ?, ?, ?, ?, 10000, 'EUR', 'scheduled', 1, CURDATE(), 0)")->execute([$A, $inv, $man, $iban]);
        }
        $pdo->commit();
        echo "seed=$n\n";
        break;
    case 'seed-jobs':
        $m = max(1, (int)($argv[3] ?? 400));
        $pdo->exec('DELETE FROM jobs');
        for ($i = 0; $i < $m; $i++) { queue_push('maintenance', ['perf' => $i]); }
        echo "jobs=$m\n";
        break;
    case 'reserve-all':
        $ids = []; $n = 0;
        while (($job = queue_reserve((string)$argv[3], ['maintenance'])) !== null) { $ids[] = $job['id']; $n++; queue_complete($job, 'completed', []); }
        echo "reserviert=$n\nids=", implode(',', $ids), "\n";
        break;
    case 'sync':
        $v = max(1, (int)($argv[3] ?? 300));
        foreach (['collection_attempts', 'payment_collections', 'sepa_mandates', 'customer_ibans', 'invoices', 'customers', 'sync_state', 'sync_runs', 'integrations', 'organizations'] as $t) { $pdo->exec("DELETE FROM $t"); }
        $T = 'cccccccc-0000-0000-0000-00000000000c';
        $pdo->exec("INSERT INTO organizations (id, name, mandate_prefix, onboarding_completed) VALUES ('$T', 'Perf Sync', 'PS', 1)");
        $pdo->prepare('INSERT INTO integrations (id, tenant_id, invoice_source, lexoffice_connected, lexoffice_api_key_encrypted) VALUES (UUID(), ?, ?, 1, ?)')->execute([$T, 'lexware_office', encrypt_value('lex-fake')]);
        require $argv[1] . '/tools/lib/sync-sim-source.php';
        $fake = new FakeSyncSource();
        for ($i = 1; $i <= $v; $i++) {
            $c = 'kontakt-' . (($i % max(1, intdiv($v, 5))) + 1);
            $fake->contacts[$c] = ['roles' => ['customer' => ['number' => (string)(20000 + $i % 60)]], 'company' => ['name' => 'Kunde ' . $c], 'emailAddresses' => ['business' => [$c . '@perf.test']]];
            $fake->invoices[sprintf('perf-%06d', $i)] = ['number' => 'RE-' . $i, 'voucherStatus' => $i % 7 === 0 ? 'overdue' : 'open', 'contactId' => $c, 'amount' => 10.0 + $i, 'updatedDate' => '2026-09-09T10:00:00.000+02:00'];
        }
        $GLOBALS['lexsepa_lex_client_factory'] = static fn(string $tenantId): InvoiceSource => $fake;
        $t0 = microtime(true);
        sync_state_start($T, ['user_id' => null, 'email' => 'perf']);
        $steps = 0;
        for ($i = 0; $i < 500; $i++) { $steps++; $r = sync_state_step($T); if (!empty($r['done']) || !empty($r['skipped'])) { break; } }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE tenant_id = '$T'")->fetchColumn();
        echo "c_belege=$v\nc_importiert=$cnt\nc_schritte=$steps\nc_dauer_ms=$ms\nc_pro_beleg_ms=", $v > 0 ? (int)round($ms / $v, 0) : 0, "\nc_speicher_mb=", (int)round(memory_get_peak_usage(true) / 1048576), "\n";
        // zweiter Lauf: unveraendert -> Delta ueber updatedDate
        $t0 = microtime(true); sync_state_start($T, ['user_id' => null, 'email' => 'perf']);
        for ($i = 0; $i < 500; $i++) { $r = sync_state_step($T); if (!empty($r['done']) || !empty($r['skipped'])) { break; } }
        echo "c_zweiter_lauf_unveraendert_ms=", (int)round((microtime(true) - $t0) * 1000), "\n";
        break;
    default:
        exit(2);
}
