<?php
/**
 * Oeffentliche Produktfakten als JSON (Version 4.76, Datenvertrag docs/contracts/smarteinzug-contract.md Abschnitt 3).
 *
 *   GET /fakten.php   ->  { schema, version, app_version, generated_at, facts: [...], integrationen: {...} }
 *
 * Quelle ist das Register in app/product_facts.php (nur freigegebene, nicht sensible Aussagen). Laufzeitwerte, die der
 * Betreiber ueber platform_settings steuert (oeffentlicher Stand der sevdesk-Anbindung), werden ergaenzt; schlaegt die
 * Datenbank fehl, bleibt der Registerwert stehen und "live" ist false. Kein Login, keine Sitzung, kein Google-Skript,
 * kein Aufruf an Stripe oder Lexware. Antwort ist fuer fuenf Minuten oeffentlich zwischenspeicherbar. CORS nur fuer die
 * eigenen Marketingdomains (signup_domains), damit statische Seiten den Stand lesen koennen. Nicht indexieren.
 */
declare(strict_types=1);
define('SKIP_SESSION', true);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/product_facts.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    header('Cache-Control: no-store');
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    $erlaubt = [];
    foreach ((array)config('signup_domains', []) as $d) {
        $d = strtolower(trim((string)$d));
        if ($d !== '') {
            $erlaubt[] = $d;
            $erlaubt[] = 'www.' . $d;
        }
    }
    if (parse_url($origin, PHP_URL_SCHEME) === 'https' && in_array($host, $erlaubt, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET');
    }
}

$snapshot = product_facts_snapshot();
$integrationen = ['live' => false];
try {
    require_once __DIR__ . '/app/integration_state.php';
    $integrationen = [
        'live' => true,
        'lexware_office' => ['public_state' => 'verfuegbar'],
        'sevdesk' => ['public_state' => integration_public_state('sevdesk'), 'freigabetermin' => integration_release_at('sevdesk')],
    ];
    foreach ($snapshot['facts'] as $i => $fact) {
        if ($fact['key'] === 'integration.sevdesk.status') {
            $snapshot['facts'][$i]['wert'] = $integrationen['sevdesk']['public_state'];
        }
    }
} catch (Throwable $e) {
    // Registerwert bleibt stehen; keine Fehlerdetails nach aussen
}
$snapshot['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
$snapshot['integrationen'] = $integrationen;

header('Cache-Control: public, max-age=300');
echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
