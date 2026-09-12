<?php
/**
 * Funktionsprueffaelle fuer den Geldfluss (app/collections.php) gegen eine temporaere MariaDB und den lokalen
 * Stripe-Stub (tools/lib/stripe-stub.php). Aufrufer: tools/collections-check.sh. Kein Zugriff auf Stripe oder
 * Lexware: der zentrale Test-Schutz (tools/lib/test-guard.php) bricht sonst vor jeder Nebenwirkung ab.
 *
 *   SMARTEINZUG_CONFIG=<sandbox-config> LEX_FAKE_FILE=<json> php tools/lib/collections-sim.php <repo> <fall> [argumente]
 *
 * Faelle:
 *   seed                         Firmen A und B mit Kunden, IBAN, unterschriebenem Mandat und offenen Rechnungen anlegen
 *   submit <tenant> <invoice> [confirm_cents|-] [date]   submit_collection (Sofort oder terminiert)
 *   process [tenant] [now]       process_scheduled_collections mit ignore_window (now = Zeitpunkt fuer collections_now)
 *   process_window <now>         wie process, aber MIT Fensterpruefung
 *   resolve <tenant>             collection_attempts_resolve
 *   sync_status <tenant>         sync_collection_statuses (laufende Einzuege, Rueckschau auf Ruecklastschrift/Erstattung)
 *   state <invoice>              Zustand der Rechnung, ihrer Einzuege und Versuche
 *   sign <secret> <payload-datei> Stripe-Signature-Header fuer einen Webhook-Testaufruf
 *   cancel <tenant> <collection> cancel_scheduled_collection
 *
 * Lexware wird ueber den CLI-Testhaken (lexsepa_lex_client_factory) durch eine Quelle ersetzt, deren offene
 * Betraege aus LEX_FAKE_FILE stammen (JSON: lexoffice_invoice_id => openAmount; fehlt eine Rechnung, gilt der
 * Rechnungsbetrag; der Wert "fail" erzwingt einen Abruf-Fehler).
 * Ausgabe: Zeilen "schluessel=wert".
 */
declare(strict_types=1);
define('LOG_SERVICE', 'cli');
require $argv[1] . '/php-ionos/bin/_cli.php';
require $argv[1] . '/tools/lib/test-guard.php';
require_once $argv[1] . '/php-ionos/app/collections.php';
require_once $argv[1] . '/php-ionos/app/crypto.php';
require_once $argv[1] . '/php-ionos/app/invoice_source.php';
require_once $argv[1] . '/php-ionos/app/mandates.php';
require_once $argv[1] . '/php-ionos/app/queue.php'; // Circuit Breaker und Ratenbegrenzung wie im Worker

final class FakeLexSource implements InvoiceSource
{
    public function __construct(private PDO $pdo) {}
    public function code(): string { return 'lexware_office'; }
    public function capabilities(): array { return ['read_open_amount']; }
    public function getProfile(): array { return ['companyName' => 'Fake']; }
    public function getOpenInvoices(): array { throw new RuntimeException('nicht im Test'); }
    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array { throw new RuntimeException('nicht im Test'); }
    public function getInvoiceDetail(string $invoiceId): array { throw new RuntimeException('nicht im Test'); }
    public function getContact(string $contactId): array { throw new RuntimeException('nicht im Test'); }
    public function getPayment(string $invoiceId): array
    {
        $file = (string)getenv('LEX_FAKE_FILE');
        $map = $file !== '' && is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        if (array_key_exists($invoiceId, $map)) {
            if ($map[$invoiceId] === 'fail') {
                throw new RuntimeException('Lexware nicht erreichbar (Fake)');
            }
            $open = (float)$map[$invoiceId];
        } else {
            $st = $this->pdo->prepare('SELECT total_gross_amount FROM invoices WHERE lexoffice_invoice_id = ?');
            $st->execute([$invoiceId]);
            $open = (float)$st->fetchColumn();
        }
        return ['open_amount' => $open, 'currency' => 'EUR', 'payment_status' => $open > 0 ? 'openRevenue' : 'paid', 'voucher_status' => $open > 0 ? 'open' : 'paid', 'paid_date' => null, 'raw' => []];
    }
}

$pdo = db();
test_guard_assert_db($pdo);
$GLOBALS['lexsepa_lex_client_factory'] = static fn(string $tenantId): InvoiceSource => new FakeLexSource(db());

$case = $argv[2] ?? '';
$out = static function (string $k, $v): void { echo $k, '=', is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE), "\n"; };
$A = 'aaaaaaaa-0000-0000-0000-00000000000a';
$B = 'bbbbbbbb-0000-0000-0000-00000000000b';

switch ($case) {
    case 'seed':
        $pdo->exec("INSERT INTO organizations (id, name, mandate_prefix, onboarding_completed, require_signed_mandate, subscription_status, billing_exempt)
                    VALUES ('$A', 'Firma A', 'FA', 1, 1, 'active', 1), ('$B', 'Firma B', 'FB', 1, 1, 'active', 1)");
        $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?), (?, ?, ?)')
            ->execute(['aaaaaaaa-1111-0000-0000-000000000001', 'a@test.local', 'x', 'bbbbbbbb-1111-0000-0000-000000000001', 'b@test.local', 'x']);
        foreach ([$A => 'FA', $B => 'FB'] as $t => $p) {
            $pdo->prepare('INSERT INTO integrations (id, tenant_id, invoice_source, lexoffice_connected, lexoffice_api_key_encrypted, stripe_connected, stripe_secret_key_encrypted, stripe_webhook_secret_encrypted, stripe_mode)
                           VALUES (UUID(), ?, ?, 1, ?, 1, ?, ?, ?)')
                ->execute([$t, 'lexware_office', encrypt_value('lex-' . $p), encrypt_value('sk_test_stub_' . $p), encrypt_value('whsec_stub_' . $p), 'test']);
            for ($i = 1; $i <= 6; $i++) {
                $cust = sprintf('%s-2222-0000-0000-%012d', substr($t, 0, 8), $i);
                $iban = sprintf('%s-3333-0000-0000-%012d', substr($t, 0, 8), $i);
                $inv  = sprintf('%s-4444-0000-0000-%012d', substr($t, 0, 8), $i);
                $lexi = sprintf('%s-5555-0000-0000-%012d', substr($t, 0, 8), $i);
                $pdo->prepare('INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email) VALUES (?, ?, UUID(), ?, ?, ?)')
                    ->execute([$cust, $t, 'K' . $i, 'Kunde ' . $p . $i, 'k' . $i . '@' . strtolower($p) . '.test.local']);
                $pdo->prepare('INSERT INTO customer_ibans (id, tenant_id, customer_id, iban, account_holder_name, is_active) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$iban, $t, $cust, 'DE02120300000000202051', 'Kunde ' . $p . $i]);
                $pdo->prepare("INSERT INTO sepa_mandates (id, tenant_id, customer_id, customer_iban_id, mandate_reference, mandate_date, is_active, status, mandate_type, signed_date, signed_place)
                               VALUES (UUID(), ?, ?, ?, ?, CURDATE(), 1, 'active', 'recurrent', CURDATE(), 'Teststadt')")
                    ->execute([$t, $cust, $iban, $p . 'K' . $i]);
                $pdo->prepare("INSERT INTO invoices (id, tenant_id, lexoffice_invoice_id, voucher_number, customer_id, contact_name, total_gross_amount, currency, due_date, lexoffice_status, collection_status)
                               VALUES (?, ?, ?, ?, ?, ?, 100.00, 'EUR', CURDATE(), 'open', 'none')")
                    ->execute([$inv, $t, $lexi, 'RE-' . $p . '-' . $i, $cust, 'Kunde ' . $p . $i]);
            }
        }
        $out('seed', 'ok');
        break;

    case 'submit':
        [$tenant, $invoice] = [(string)$argv[3], (string)$argv[4]];
        $confirm = isset($argv[5]) && $argv[5] !== '-' ? (int)$argv[5] : null;
        $date = $argv[6] ?? null;
        if (isset($argv[7]) && $argv[7] !== '') {
            $GLOBALS['lexsepa_now_override'] = new DateTimeImmutable($argv[7]);
        }
        try {
            $id = submit_collection($tenant, $invoice, $date, ['user_id' => null, 'email' => 'sim'], ['confirm_amount_cents' => $confirm]);
            $out('result', 'ok');
            $out('collection_id', $id);
        } catch (Throwable $e) {
            $out('result', 'error');
            $out('error_class', get_class($e));
            $out('error', str_replace("\n", ' ', $e->getMessage()));
        }
        break;

    case 'process':
    case 'process_window':
        $tenant = isset($argv[3]) && $argv[3] !== '-' ? (string)$argv[3] : null;
        if (isset($argv[4]) && $argv[4] !== '') {
            $GLOBALS['lexsepa_now_override'] = new DateTimeImmutable($argv[4]);
        }
        $r = process_scheduled_collections($tenant, null, $case === 'process' ? ['ignore_window' => true] : []);
        unset($r['handled_ids']);
        foreach ($r as $k => $v) { $out($k, $v); }
        break;

    case 'resolve':
        $r = collection_attempts_resolve((string)$argv[3], ['user_id' => null, 'email' => 'sim']);
        foreach ($r as $k => $v) { $out($k, $v); }
        break;

    case 'sync_status':
        $r = sync_collection_statuses((string)$argv[3], ['user_id' => null, 'email' => 'sim']);
        foreach ($r as $k => $v) { $out($k, is_bool($v) ? (int)$v : $v); }
        break;

    case 'refund':
        $st = $pdo->prepare('SELECT * FROM payment_collections WHERE id = ? AND tenant_id = ?');
        $st->execute([(string)$argv[4], (string)$argv[3]]);
        $c = $st->fetch();
        if (!$c) { $out('result', 'fehlt'); break; }
        $changed = collection_apply_refund((string)$argv[3], $c, (int)$argv[5], 'ch_test', ['user_id' => null, 'email' => 'sim'], 'sim');
        $out('result', $changed ? 'changed' : 'unchanged');
        break;

    case 'review_clear':
        try {
            invoice_review_clear((string)$argv[3], (string)$argv[4], ['user_id' => 'aaaaaaaa-1111-0000-0000-000000000001', 'email' => 'sim', 'role' => 'owner']);
            $out('result', 'ok');
        } catch (Throwable $e) {
            $out('result', 'error');
            $out('error', str_replace("\n", ' ', $e->getMessage()));
        }
        break;

    case 'pause':
        // Not-Stopp der Firma setzen (D-05: darf nicht auf einen laufenden Einzug warten); misst die Dauer
        $t0 = microtime(true);
        collections_set_paused((string)$argv[3], (string)$argv[4] === '1', ['user_id' => 'aaaaaaaa-1111-0000-0000-000000000001', 'email' => 'sim', 'role' => 'owner'], 'Test');
        $out('result', 'ok');
        $out('dauer_ms', (int)round((microtime(true) - $t0) * 1000));
        break;

    case 'cancel':
        try {
            cancel_scheduled_collection((string)$argv[3], (string)$argv[4], ['user_id' => null, 'email' => 'sim']);
            $out('result', 'ok');
        } catch (Throwable $e) {
            $out('result', 'error');
            $out('error', str_replace("\n", ' ', $e->getMessage()));
        }
        break;

    case 'state':
        $inv = (string)$argv[3];
        $st = $pdo->prepare('SELECT collection_status, requires_review, review_reason, lexoffice_status, open_amount FROM invoices WHERE id = ?');
        $st->execute([$inv]);
        $i = $st->fetch() ?: [];
        $out('invoice_status', $i['collection_status'] ?? '(fehlt)');
        $out('requires_review', (int)($i['requires_review'] ?? 0));
        $out('review_reason', $i['review_reason'] ?? '');
        $st = $pdo->prepare('SELECT id, stripe_status, stripe_payment_intent_id, amount_cents, scheduled_submitted, is_scheduled, failure_reason, note, refunded_cents FROM payment_collections WHERE invoice_id = ? ORDER BY created_at, id');
        $st->execute([$inv]);
        $cols = $st->fetchAll();
        $out('collections', count($cols));
        foreach ($cols as $n => $c) {
            $out('c' . $n, $c['stripe_status'] . '|' . ($c['stripe_payment_intent_id'] ?: '-') . '|' . $c['amount_cents'] . '|' . $c['id']);
            $out('c' . $n . '_reason', (string)($c['failure_reason'] ?? ''));
            $out('c' . $n . '_note', (string)($c['note'] ?? ''));
            $out('c' . $n . '_refunded', (string)($c['refunded_cents'] ?? '0'));
        }
        $st = $pdo->prepare('SELECT status, stripe_payment_intent_id, idempotency_key, collection_id FROM collection_attempts WHERE invoice_id = ? ORDER BY created_at, id');
        $st->execute([$inv]);
        $atts = $st->fetchAll();
        $out('attempts', count($atts));
        foreach ($atts as $n => $a) {
            $out('a' . $n, $a['status'] . '|' . ($a['stripe_payment_intent_id'] ?: '-') . '|' . ($a['collection_id'] ?: '-'));
            $out('a' . $n . '_key', $a['idempotency_key']);
        }
        break;

    case 'sign':
        $secret = (string)$argv[3];
        $payload = (string)file_get_contents((string)$argv[4]);
        $t = time();
        $out('header', 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret));
        break;

    default:
        fwrite(STDERR, "Unbekannter Fall: $case\n");
        exit(2);
}
