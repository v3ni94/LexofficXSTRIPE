<?php
/**
 * Bootstrap: Konfiguration, Datenbank, Session, Basis-Helfer.
 * Wird von jeder Seite als erstes eingebunden.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ROOT', dirname(__DIR__));

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    die('Konfiguration fehlt: app/config.php anlegen (Vorlage: app/config.example.php).');
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Europe/Berlin');

// Fehler nie im Browser anzeigen (Produktion), aber loggen
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

// ---------------------------------------------------------------------------
// Basisadressen und Host-Prüfung
// ---------------------------------------------------------------------------

/** Produktname (Standard SmartEinzug). Technische Kennungen bleiben unverändert. */
function product_name(): string
{
    $name = trim((string)config('product_name', ''));
    return $name !== '' ? $name : 'SmartEinzug';
}

/**
 * Basisadresse der Kundenanwendung ohne Slash am Ende. Reihenfolge:
 * app_base_url, dann base_url. Alle absoluten Links (Verifizierung, Passwort,
 * Einladung, Rückkehr aus dem Checkout, Webhook-Anzeige) nutzen diese Funktion.
 */
function app_base_url(): string
{
    foreach (['app_base_url', 'base_url'] as $key) {
        $v = rtrim(trim((string)config($key, '')), '/');
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/** Öffentliche Produktwebsite ohne Slash am Ende (public_base_url, sonst marketing_url). */
function public_base_url(): string
{
    foreach (['public_base_url', 'marketing_url'] as $key) {
        $v = rtrim(trim((string)config($key, '')), '/');
        if ($v !== '') {
            return $v;
        }
    }
    return 'https://smart-einzug.de';
}

/** Basisadresse des Adminbereichs ohne Slash am Ende; leer = Übergangsmodus (gleicher Host). */
function admin_base_url(): string
{
    return rtrim(trim((string)config('admin_base_url', '')), '/');
}

/** True, wenn ein Admin-Host konfiguriert ist und die aktuelle Anfrage darüber kommt. */
function on_admin_host(): bool
{
    $adminHost = base_url_host(admin_base_url());
    return $adminHost !== '' && request_host() === $adminHost;
}

/** Hostname aus einer Basisadresse (klein geschrieben) oder leerer String. */
function base_url_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/**
 * Hostname der aktuellen Anfrage aus HTTP_HOST, ohne Port, klein geschrieben
 * und per Regex validiert. Liefert leeren String bei fehlendem oder
 * ungültigem Host (z. B. CLI).
 */
function request_host(): string
{
    $raw = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($raw === '') {
        return '';
    }
    // IPv6 in eckigen Klammern, sonst Port abtrennen
    if ($raw[0] === '[') {
        $end = strpos($raw, ']');
        $raw = $end === false ? '' : substr($raw, 1, $end - 1);
        return preg_match('/^[0-9a-f:.]{2,45}$/', $raw) ? $raw : '';
    }
    $raw = preg_replace('/:\d{1,5}$/', '', $raw) ?? '';
    return preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $raw) ? $raw : '';
}

/**
 * true, wenn keine Allowlist konfiguriert ist (Übergangsmodus) oder der
 * Host der Anfrage in allowed_hosts steht.
 */
function host_allowed(): bool
{
    $allowed = array_values(array_filter(array_map(
        fn($h) => strtolower(trim((string)$h)),
        (array)config('allowed_hosts', [])
    ), fn($h) => $h !== ''));
    if (!$allowed) {
        return true;
    }
    $host = request_host();
    return $host !== '' && in_array($host, $allowed, true);
}

/** Antwortet mit HTTP 404 und kurzer Textmeldung, beendet das Skript. */
function host_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nicht gefunden.');
}

/**
 * Host-Prüfung für die aktuelle Anfrage (nicht in der CLI).
 *  1. Allowlist: Host nicht enthalten, dann 404. Ausgenommen sind Endpunkte,
 *     die sich selbst über Token oder Signatur schützen.
 *  2. Admin-Trennung (nur wenn admin_base_url gesetzt): auf dem Adminhost sind
 *     nur Anmeldung, 2FA, Passwort, Sicherheit, Abmelden und Assets erreichbar;
 *     admin.php auf jedem anderen Host liefert 404.
 */
function enforce_host_rules(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $selfProtected = ['cron.php', 'stripe-webhook.php', 'billing-webhook.php', 'track.php'];

    if (!in_array($script, $selfProtected, true) && !host_allowed()) {
        host_not_found();
    }

    $adminHost = base_url_host(admin_base_url());
    if ($adminHost === '') {
        return; // Übergangsmodus: Admin auf demselben Host erlaubt
    }
    $host = request_host();
    $appHost = base_url_host(app_base_url());
    $onAdminHost = $host !== '' && $host === $adminHost && $adminHost !== $appHost;

    if ($onAdminHost) {
        $adminAllowed = ['admin.php', 'login.php', 'twofa-verify.php', 'twofa-setup.php', 'logout.php',
            'security.php', 'forgot-password.php', 'reset-password.php'];
        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $isAsset = str_starts_with($uri, '/assets/') || $uri === '/favicon.ico';
        if ($script === 'index.php' || $script === 'dashboard.php') {
            // Startseite und Ziel nach der Anmeldung: auf dem Adminhost zum Adminbereich
            header('Location: /admin.php', true, 302);
            exit;
        }
        if (!$isAsset && !in_array($script, $adminAllowed, true)) {
            host_not_found();
        }
    } elseif ($script === 'admin.php') {
        host_not_found();
    }
}

/** PDO-Verbindung (Singleton) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** UUID v4 */
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** HTML-Escaping */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Betrag in Cent als "1.234,56 EUR" formatieren */
function format_eur_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' EUR';
}

/** Betrag (Dezimalstring) als "1.234,56 EUR" formatieren */
function format_eur(string|float $amount): string
{
    return number_format((float)$amount, 2, ',', '.') . ' EUR';
}

/** Datum als TT.MM.JJJJ */
function format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '-';
}

function format_datetime(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    $ts = strtotime($dt);
    return $ts ? date('d.m.Y H:i', $ts) : '-';
}

// Host-Prüfung vor dem Start der Session (kein Cookie für abgewiesene Hosts)
enforce_host_rules();

// ---------------------------------------------------------------------------
// Session (nicht für Webhook/Cron, die binden bootstrap ohne Session-Bedarf ein,
// stören sich aber nicht daran)
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');   // keine fremd vorgegebenen Session-IDs annehmen
    ini_set('session.use_only_cookies', '1');
    session_name('LXEINZUGSESSID');
    session_start();
}

// ---------------------------------------------------------------------------
// Flash-Meldungen
// ---------------------------------------------------------------------------
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

// ---------------------------------------------------------------------------
// CSRF-Schutz
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Bricht mit 403 ab, wenn das CSRF-Token eines POST-Requests nicht stimmt. */
function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Ungültiges Formular-Token. Bitte Seite neu laden und erneut versuchen.');
    }
}
