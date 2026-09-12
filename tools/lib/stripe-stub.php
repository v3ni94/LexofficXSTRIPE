<?php
/**
 * Lokaler Stripe-Stub fuer Pruefsuiten (php -S 127.0.0.1:PORT tools/lib/stripe-stub.php). Kein Zugriff auf Stripe.
 *
 * Zustand liegt im Ordner STRIPE_STUB_DIR (Pflicht): customers.json, pms.json, pis.json, idem/<key>.json,
 * pi.log (eine Zeile je NEU angelegtem PaymentIntent, Grundlage der Invariante "genau eine Lastschrift").
 *
 * Steuerdatei STRIPE_STUB_DIR/mode wirkt nur auf POST /v1/payment_intents:
 *   ok (Vorgabe)  PaymentIntent anlegen, Status processing
 *   http500       JSON-Fehler mit HTTP 500 (Stripe koennte den Vorgang angelegt haben): der Stub legt den
 *                 PaymentIntent TROTZDEM an (worst case), antwortet aber mit 500
 *   http502html   HTML-Fehlerseite ohne JSON mit HTTP 502, PaymentIntent ebenfalls angelegt (Proxy-Fall)
 *   http402       fachliche Ablehnung (card_declined-artig), nichts angelegt
 *   http409       Idempotenzschluessel in Bearbeitung, nichts angelegt
 *   timeout       schlaeft laenger als CURLOPT_TIMEOUT (31 s) und legt den PaymentIntent an
 * Steuerdatei STRIPE_STUB_DIR/search_lag: Suche liefert leer (Suchindex haengt), Liste liefert weiterhin alles.
 * Steuerdatei STRIPE_STUB_DIR/pi_status: Status neu angelegter PaymentIntents (Vorgabe processing).
 * Zustandsdatei STRIPE_STUB_DIR/charges.json: Ueberschreibungen je Charge-ID ({"ch_x": {"disputed": true,
 *   "amount_refunded": 4000}}) fuer GET /charges/{id} und die eingebettete Charge der Liste (expand=data.latest_charge).
 *
 * Authentifizierung: Basic mit Benutzer sk_test_... (Live-Praefixe werden mit 401 abgewiesen, damit die Suite
 * niemals mit einem Live-Schluessel laeuft). Idempotenz: gleicher Schluessel liefert die gespeicherte Antwort
 * (auch bei geaenderten Parametern liefert der Stub den alten Datensatz; Stripe wuerde 400 melden).
 */
declare(strict_types=1);

$dir = (string)getenv('STRIPE_STUB_DIR');
if ($dir === '' || !is_dir($dir)) {
    http_response_code(500);
    exit(json_encode(['error' => ['message' => 'STRIPE_STUB_DIR fehlt']]));
}
@mkdir($dir . '/idem', 0777, true);
$lock = fopen($dir . '/.lock', 'c');
flock($lock, LOCK_EX);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/v1#', '', $path) ?? $path;
$query = $_GET;
$body = $_POST; // php -S fuellt $_POST bei application/x-www-form-urlencoded

function stub_json(int $code, array $data): never
{
    global $lock;
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    flock($lock, LOCK_UN);
    exit;
}
function stub_load(string $name): array
{
    global $dir;
    $f = "$dir/$name.json";
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}
function stub_save(string $name, array $data): void
{
    global $dir;
    file_put_contents("$dir/$name.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function stub_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}
function stub_mode(): string
{
    global $dir;
    return is_file("$dir/mode") ? trim((string)file_get_contents("$dir/mode")) : 'ok';
}
function stub_error(int $code, string $type, string $stripeCode, string $message): never
{
    stub_json($code, ['error' => ['type' => $type, 'code' => $stripeCode, 'message' => $message]]);
}
/** Charge-Objekt zu einem PaymentIntent, Felder disputed/amount_refunded/dispute aus charges.json ueberschreibbar. */
function stub_charge(array $pi): array
{
    $id = (string)($pi['latest_charge'] ?? '');
    $over = stub_load('charges')[$id] ?? [];
    $disputed = (bool)($over['disputed'] ?? false);
    return [
        'id' => $id, 'object' => 'charge', 'payment_intent' => $pi['id'], 'amount' => $pi['amount'],
        'amount_refunded' => (int)($over['amount_refunded'] ?? 0), 'refunded' => (int)($over['amount_refunded'] ?? 0) >= (int)$pi['amount'] && (int)($over['amount_refunded'] ?? 0) > 0,
        'disputed' => $disputed, 'dispute' => $disputed ? ($over['dispute'] ?? 'dp_' . substr($id, 3)) : null,
        'payment_method_details' => ['type' => 'sepa_debit', 'sepa_debit' => ['mandate' => 'mandate_stub', 'last4' => '0000']],
    ];
}

// Auth: nur Testschluessel
$user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
if ($user === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Basic ')) {
    $user = (string)strtok((string)base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)), ':');
}
if (!preg_match('/^(sk|rk)_test_/', $user)) {
    stub_error(401, 'invalid_request_error', 'api_key_invalid', 'Stub akzeptiert nur Testschluessel (sk_test_/rk_test_).');
}
file_put_contents("$dir/requests.log", date('c') . " $method $path\n", FILE_APPEND);

// --- Konto ---
if ($method === 'GET' && $path === '/account') {
    stub_json(200, ['id' => 'acct_stub', 'business_profile' => ['name' => 'Stub-Konto'], 'charges_enabled' => true, 'payouts_enabled' => true, 'country' => 'DE', 'default_currency' => 'eur']);
}

// --- Kunden ---
if ($method === 'GET' && $path === '/customers/search') {
    $q = (string)($query['query'] ?? '');
    preg_match_all("/metadata\['(\w+)'\]:'([^']*)'/", $q, $m, PREG_SET_ORDER);
    $want = [];
    foreach ($m as $mm) { $want[$mm[1]] = $mm[2]; }
    $hits = [];
    foreach (stub_load('customers') as $c) {
        $okAll = true;
        foreach ($want as $k => $v) { if ((string)($c['metadata'][$k] ?? '') !== $v) { $okAll = false; } }
        if ($okAll && $want) { $hits[] = $c; }
    }
    stub_json(200, ['object' => 'search_result', 'data' => $hits, 'has_more' => false]);
}
if ($method === 'POST' && $path === '/customers') {
    $customers = stub_load('customers');
    $c = ['id' => stub_id('cus'), 'object' => 'customer', 'name' => $body['name'] ?? null, 'email' => $body['email'] ?? null, 'metadata' => (array)($body['metadata'] ?? [])];
    $customers[$c['id']] = $c;
    stub_save('customers', $customers);
    stub_json(200, $c);
}

// --- Zahlungsmethoden ---
if ($method === 'POST' && $path === '/payment_methods') {
    $pms = stub_load('pms');
    $iban = (string)($body['sepa_debit']['iban'] ?? '');
    $pm = ['id' => stub_id('pm'), 'object' => 'payment_method', 'type' => 'sepa_debit', 'customer' => null,
        'sepa_debit' => ['last4' => substr($iban, -4), 'country' => substr($iban, 0, 2), 'bank_code' => substr($iban, 4, 8)],
        'billing_details' => (array)($body['billing_details'] ?? [])];
    $pms[$pm['id']] = $pm;
    stub_save('pms', $pms);
    stub_json(200, $pm);
}
if ($method === 'POST' && preg_match('#^/payment_methods/([^/]+)/attach$#', $path, $m)) {
    $pms = stub_load('pms');
    if (!isset($pms[$m[1]])) { stub_error(404, 'invalid_request_error', 'resource_missing', 'No such payment_method'); }
    $pms[$m[1]]['customer'] = $body['customer'] ?? null;
    stub_save('pms', $pms);
    stub_json(200, $pms[$m[1]]);
}
if ($method === 'GET' && preg_match('#^/payment_methods/([^/]+)$#', $path, $m)) {
    $pms = stub_load('pms');
    isset($pms[$m[1]]) ? stub_json(200, $pms[$m[1]]) : stub_error(404, 'invalid_request_error', 'resource_missing', 'No such payment_method');
}

// --- PaymentIntents ---
if ($method === 'POST' && $path === '/payment_intents') {
    $idem = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    $idemFile = $idem !== '' ? "$dir/idem/" . preg_replace('/[^A-Za-z0-9_\-]/', '', $idem) . '.json' : null;
    if ($idemFile !== null && is_file($idemFile)) {
        $stored = json_decode((string)file_get_contents($idemFile), true);
        file_put_contents("$dir/idem_replay.log", $idem . "\n", FILE_APPEND);
        stub_json((int)$stored['code'], $stored['data']);
    }
    $mode = stub_mode();
    if ($mode === 'http402') {
        stub_error(402, 'card_error', 'payment_method_not_available', 'Die Zahlungsmethode wurde abgelehnt (Stub).');
    }
    if ($mode === 'http409') {
        stub_error(409, 'idempotency_error', 'idempotency_key_in_use', 'Idempotenzschluessel wird gerade verarbeitet (Stub).');
    }
    $pis = stub_load('pis');
    $pi = [
        'id' => stub_id('pi'), 'object' => 'payment_intent', 'amount' => (int)($body['amount'] ?? 0),
        'currency' => (string)($body['currency'] ?? 'eur'), 'customer' => $body['customer'] ?? null,
        'payment_method' => $body['payment_method'] ?? null, 'description' => $body['description'] ?? null,
        'metadata' => (array)($body['metadata'] ?? []), 'created' => time(),
        'status' => is_file("$dir/pi_status") ? trim((string)file_get_contents("$dir/pi_status")) : 'processing',
        'latest_charge' => stub_id('ch'), 'livemode' => false,
    ];
    $pis[$pi['id']] = $pi;
    stub_save('pis', $pis);
    file_put_contents("$dir/pi.log", $pi['id'] . ' ' . $pi['amount'] . ' ' . (string)($pi['metadata']['invoice_id'] ?? '-') . ' ' . (string)($pi['metadata']['attempt_key'] ?? '-') . "\n", FILE_APPEND);
    if ($idemFile !== null) {
        file_put_contents($idemFile, json_encode(['code' => 200, 'data' => $pi]));
    }
    if ($mode === 'http500') {
        stub_error(500, 'api_error', 'internal', 'Interner Fehler (Stub): der Vorgang wurde trotzdem angelegt.');
    }
    if ($mode === 'http502html') {
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html><body>502 Bad Gateway (Stub)</body></html>';
        flock($lock, LOCK_UN);
        exit;
    }
    if ($mode === 'timeout') {
        flock($lock, LOCK_UN);
        sleep(31);
        stub_json(200, $pi);
    }
    if ($mode === 'slow5') {
        flock($lock, LOCK_UN);
        sleep(5); // langsamer Aufruf ohne Zeitueberschreitung (Not-Stopp waehrend eines laufenden Einzugs, D-05)
        stub_json(200, $pi);
    }
    stub_json(200, $pi);
}
if ($method === 'GET' && $path === '/payment_intents/search') {
    if (is_file("$dir/search_lag")) {
        stub_json(200, ['object' => 'search_result', 'data' => [], 'has_more' => false]);
    }
    $q = (string)($query['query'] ?? '');
    $hits = [];
    if (preg_match("/metadata\['attempt_key'\]:'([^']+)'/", $q, $m)) {
        foreach (stub_load('pis') as $pi) {
            if ((string)($pi['metadata']['attempt_key'] ?? '') === $m[1]) { $hits[] = $pi; }
        }
    }
    stub_json(200, ['object' => 'search_result', 'data' => $hits, 'has_more' => false]);
}
if ($method === 'GET' && $path === '/payment_intents') {
    // Liste wie Stripe: absteigend nach created, starting_after als Cursor, limit je Seite (Testdatei pi_page_size begrenzt
    // die Seitengroesse zusaetzlich, damit die Paginierung mit wenigen Datensaetzen geprueft wird)
    $gte = (int)($query['created']['gte'] ?? 0);
    $lte = isset($query['created']['lte']) ? (int)$query['created']['lte'] : PHP_INT_MAX;
    $limit = max(1, min(100, (int)($query['limit'] ?? 10)));
    if (is_file("$dir/pi_page_size")) { $limit = min($limit, max(1, (int)file_get_contents("$dir/pi_page_size"))); }
    $all = [];
    foreach (stub_load('pis') as $pi) {
        if ((int)$pi['created'] >= $gte && (int)$pi['created'] <= $lte) { $all[] = $pi; }
    }
    usort($all, static fn($a, $b) => [$b['created'], $b['id']] <=> [$a['created'], $a['id']]);
    $start = 0;
    if (!empty($query['starting_after'])) {
        foreach ($all as $i => $pi) { if ($pi['id'] === $query['starting_after']) { $start = $i + 1; break; } }
    }
    $page = array_slice($all, $start, $limit);
    $expand = (array)($query['expand'] ?? []);
    if (in_array('data.latest_charge', $expand, true)) {
        foreach ($page as $i => $pi) { $page[$i]['latest_charge'] = stub_charge($pi); }
    }
    file_put_contents("$dir/list.log", 'page start=' . $start . ' n=' . count($page) . (in_array('data.latest_charge', $expand, true) ? ' expand=latest_charge' : '') . "\n", FILE_APPEND);
    stub_json(200, ['object' => 'list', 'data' => $page, 'has_more' => $start + $limit < count($all)]);
}
if ($method === 'GET' && preg_match('#^/payment_intents/([^/]+)$#', $path, $m)) {
    $pis = stub_load('pis');
    isset($pis[$m[1]]) ? stub_json(200, $pis[$m[1]]) : stub_error(404, 'invalid_request_error', 'resource_missing', 'No such payment_intent');
}

// --- Charges, Mandate (Lesezugriffe nach Erfolg) ---
if ($method === 'GET' && preg_match('#^/charges/([^/]+)$#', $path, $m)) {
    foreach (stub_load('pis') as $pi) {
        if (($pi['latest_charge'] ?? '') === $m[1]) {
            stub_json(200, stub_charge($pi));
        }
    }
    stub_error(404, 'invalid_request_error', 'resource_missing', 'No such charge');
}
if ($method === 'GET' && preg_match('#^/mandates/([^/]+)$#', $path, $m)) {
    stub_json(200, ['id' => $m[1], 'object' => 'mandate', 'status' => 'active', 'payment_method_details' => ['sepa_debit' => ['reference' => 'STUB-REF', 'url' => 'https://example.invalid/mandate']]]);
}

stub_error(404, 'invalid_request_error', 'unknown_endpoint', "Stub kennt $method $path nicht");
