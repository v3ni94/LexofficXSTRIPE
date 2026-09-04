<?php
/**
 * Cookielose Reichweitenmessung der Marketingseiten (Funnel je Domain).
 * Nimmt per POST ein kleines JSON {d: Domain, e: Ereignis, p: Pfad, c: CTA}
 * entgegen und zählt es. Es werden keine IP-Adressen, Cookies oder
 * Nutzerkennungen gespeichert. Nur erlaubte Domains und Ereignisse.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/audit.php';

$allowedDomains = array_map('strtolower', (array)config('signup_domains', []));
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
if ($originHost !== '' && (in_array($originHost, $allowedDomains, true) || in_array(preg_replace('/^www\./', '', $originHost), $allowedDomains, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode(substr($raw, 0, 2000), true);
if (!is_array($data)) {
    http_response_code(204);
    exit;
}

$domain = strtolower(preg_replace('/^www\./', '', trim((string)($data['d'] ?? ''))));
$event = (string)($data['e'] ?? '');
$allowedEvents = ['page_view', 'cta_click'];
if (!in_array($domain, $allowedDomains, true) || !in_array($event, $allowedEvents, true)) {
    http_response_code(204);
    exit;
}
// Obergrenze je Domain und Minute gegen Verfälschung der Kennzahlen und
// unbegrenztes Tabellenwachstum (ohne IP-Speicherung).
try {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM funnel_events WHERE domain = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $stmt->execute([$domain]);
    if ((int)$stmt->fetchColumn() >= 300) {
        http_response_code(204);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(204);
    exit;
}
$path = mb_substr(preg_replace('/[^\w\-\/.]/u', '', (string)($data['p'] ?? '')), 0, 200);
if ($event === 'cta_click') {
    $path .= '#' . mb_substr(preg_replace('/[^\w\-]/u', '', (string)($data['c'] ?? '')), 0, 40);
}

funnel_event($domain, $event, null, null, $path);
http_response_code(204);
