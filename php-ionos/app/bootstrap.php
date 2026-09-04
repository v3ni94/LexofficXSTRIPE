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

/** PDO-Verbindung (Singleton) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4'
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
