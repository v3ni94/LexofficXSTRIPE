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

// Konfigurationsdatei: standardmäßig app/config.php neben dem Code; auf dem VPS über die Umgebungsvariable
// SMARTEINZUG_CONFIG auf eine Datei außerhalb des Release-Verzeichnisses (z.B. /opt/smarteinzug/shared/config.php).
$configFile = (string)(getenv('SMARTEINZUG_CONFIG') ?: (__DIR__ . '/config.php'));
if (!is_file($configFile)) {
    http_response_code(500);
    die('Konfiguration fehlt: app/config.php anlegen (Vorlage: app/config.example.php).');
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Europe/Berlin');

// Hinter einem Reverse Proxy (VPS: Caddy/nginx) kommt TLS am Proxy an. Nur wenn die Anfrage von einer
// konfigurierten vertrauenswürdigen Proxy-Adresse stammt (config trusted_proxies), gilt X-Forwarded-Proto.
if (PHP_SAPI !== 'cli' && empty($_SERVER['HTTPS'])) {
    $trusted = array_map('trim', (array)($GLOBALS['config']['trusted_proxies'] ?? []));
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $proxied = false;
    foreach ($trusted as $t) {
        if ($t === '' ) { continue; }
        if ($t === $remote) { $proxied = true; break; }
        if (str_contains($t, '/') && function_exists('inet_pton')) {
            [$net, $bits] = explode('/', $t, 2);
            $r = @inet_pton($remote); $n = @inet_pton($net);
            if ($r !== false && $n !== false && strlen($r) === strlen($n)) {
                $bits = (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
                $ok = substr($r, 0, $bytes) === substr($n, 0, $bytes);
                if ($ok && $rest > 0) { $mask = 0xFF & (0xFF << (8 - $rest)); $ok = ((ord($r[$bytes]) & $mask) === (ord($n[$bytes]) & $mask)); }
                if ($ok) { $proxied = true; break; }
            }
        }
    }
    if ($proxied && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';
    }
    if ($proxied) {
        // Kennzeichen für nachgelagerte Prüfungen (z.B. Übernahme einer Correlation-ID aus dem Header).
        $_SERVER['TRUSTED_PROXY_HOP'] = $remote;
    }
    if ($proxied && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // X-Forwarded-For von RECHTS auswerten: Proxys hängen die Adresse des jeweils vorherigen Hops
        // rechts an, der linkeste Eintrag stammt vom Client selbst und ist frei wählbar. Übernommen wird
        // die erste Adresse von rechts, die nicht selbst ein vertrauenswürdiger Proxy ist, und nur,
        // wenn sie eine gültige IP-Adresse ist; sonst bleibt REMOTE_ADDR unverändert.
        $hops = array_values(array_filter(array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])), 'strlen'));
        $isTrusted = static function (string $ip) use ($trusted): bool {
            foreach ($trusted as $t) {
                if ($t === '') { continue; }
                if ($t === $ip) { return true; }
                if (str_contains($t, '/') && function_exists('inet_pton')) {
                    [$net, $bits] = explode('/', $t, 2);
                    $r = @inet_pton($ip); $n = @inet_pton($net);
                    if ($r === false || $n === false || strlen($r) !== strlen($n)) { continue; }
                    $bits = (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
                    $ok = substr($r, 0, $bytes) === substr($n, 0, $bytes);
                    if ($ok && $rest > 0) { $mask = 0xFF & (0xFF << (8 - $rest)); $ok = ((ord($r[$bytes]) & $mask) === (ord($n[$bytes]) & $mask)); }
                    if ($ok) { return true; }
                }
            }
            return false;
        };
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $candidate = $hops[$i];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break; // ungültiger Eintrag: Header nicht vertrauenswürdig, REMOTE_ADDR bleibt der Proxy
            }
            if ($isTrusted($candidate)) {
                continue; // weiterer eigener Proxy in der Kette
            }
            $_SERVER['REMOTE_ADDR_PROXY'] = $remote;
            $_SERVER['REMOTE_ADDR'] = $candidate;
            break;
        }
    }
}

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
    $selfProtected = ['cron.php', 'stripe-webhook.php', 'billing-webhook.php', 'track.php', 'migrate.php'];

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
        $adminAllowed = ['admin.php', 'admin-support.php', 'admin-system.php', 'admin-system-data.php', 'admin-doc.php', 'login.php', 'twofa-verify.php', 'twofa-setup.php', 'logout.php',
            'security.php', 'forgot-password.php', 'reset-password.php'];
        // Keine Ausnahme für /assets/ anhand der REQUEST_URI: statische Dateien erreichen PHP nie (liefert der
        // Webserver direkt aus), und ein Präfixvergleich auf die rohe URI wäre mit /assets/../seite.php umgehbar.
        if ($script === 'index.php' || $script === 'dashboard.php') {
            // Startseite und Ziel nach der Anmeldung: auf dem Adminhost zum Adminbereich
            header('Location: /admin.php', true, 302);
            exit;
        }
        if (!in_array($script, $adminAllowed, true)) {
            host_not_found();
        }
    } elseif (in_array($script, ['admin.php', 'admin-support.php', 'admin-system.php', 'admin-system-data.php', 'admin-doc.php'], true)) {
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

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/log.php';
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Correlation-Id: ' . correlation_id());
}

/**
 * Wartungsmodus (Cutover): existiert app/storage/maintenance.flag oder ist config maintenance_mode true,
 * antworten alle Seiten mit 503, ausgenommen health.php, migrate.php und der Adminbereich zur Kontrolle.
 */
/** Schreibbares Speicherverzeichnis (Mandate, Avatare, Logs, Wartungsmarker); config storage_dir oder app/storage. */
function storage_dir(): string
{
    $d = rtrim((string)($GLOBALS['config']['storage_dir'] ?? ''), '/');
    return $d !== '' ? $d : APP_ROOT . '/app/storage';
}

function maintenance_active(): bool
{
    return !empty($GLOBALS['config']['maintenance_mode']) || is_file(storage_dir() . '/maintenance.flag');
}
if (PHP_SAPI !== 'cli' && maintenance_active()) {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Ausnahmen nur anhand des tatsächlich ausgeführten Skripts (SCRIPT_NAME), nicht anhand der rohen
    // REQUEST_URI (wäre mit /assets/../seite.php umgehbar). Webhooks (stripe-webhook.php, billing-webhook.php),
    // cron.php und track.php erhalten im Wartungsmodus bewusst 503: während des Cutovers darf nichts mehr in die
    // alte Datenbank schreiben; Stripe wiederholt Ereignisse, nach dem Cutover fehlgeschlagene Ereignisse im
    // Stripe-Dashboard erneut senden (siehe docs/vps/07-cutover-checkliste.md). Das Fenster kurz halten.
    if (!in_array($script, ['health.php', 'migrate.php', 'admin.php', 'admin-system.php', 'admin-system-data.php', 'login.php', 'twofa-verify.php', 'logout.php'], true)) {
        http_response_code(503);
        header('Retry-After: 300');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wartung</title>'
           . '<style>body{font-family:system-ui,Arial,sans-serif;background:#f6f6f6;color:#1a1a1a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
           . '.box{background:#fff;padding:32px 36px;border-radius:12px;max-width:520px;box-shadow:0 2px 12px rgba(0,0,0,.06)}h1{font-size:22px;margin:0 0 12px}p{line-height:1.5}</style></head>'
           . '<body><div class="box"><h1>Kurze Wartung</h1><p>Die Anwendung wird gerade gewartet und ist in wenigen Minuten wieder erreichbar. Laufende Einzüge und Daten sind davon nicht betroffen.</p>'
           . '<p>Aktuelle Hinweise finden Sie auf der Statusseite, falls eingerichtet.</p></div></body></html>';
        exit;
    }
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

/**
 * Pfad einer statischen Datei (CSS, JS, Bilder) mit Versionsparameter aus dem
 * Änderungszeitpunkt der Datei. Nach jedem Upload lädt der Browser die neue
 * Fassung, ohne dass jemand Strg+F5 drücken muss; zugleich dürfen die Dateien
 * lange gecacht werden (.htaccess).
 */
function asset_url(string $relativePath): string
{
    static $cache = [];
    if (!isset($cache[$relativePath])) {
        $mtime = @filemtime(dirname(__DIR__) . '/' . ltrim($relativePath, '/'));
        $cache[$relativePath] = $relativePath . '?v=' . substr(md5((string)($mtime ?: 0)), 0, 8);
    }
    return $cache[$relativePath];
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
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli' && !defined('SKIP_SESSION')) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');   // keine fremd vorgegebenen Session-IDs annehmen
    ini_set('session.use_only_cookies', '1');
    session_cache_limiter('nocache');          // Seiten mit Kundendaten nie im Browser- oder Proxy-Cache (unabhängig von der Hoster-INI)
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

// ---------------------------------------------------------------------------
// Monitoring: Minutenzähler der instrumentierten PHP-Anfragen (Auftrag II, Abschnitt 7.4).
// Ein einziger, kleiner Upsert am Ende jeder Web-Anfrage; Fehler werden verworfen. Erfasst
// werden nur PHP-Anfragen dieser Anwendung, keine statischen Dateien. Ohne Migration 017 passiert nichts.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !defined('SKIP_REQUEST_METRICS')) {
    $GLOBALS['__req_started'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    register_shutdown_function(static function (): void {
        try {
            if (!empty($_SERVER['HTTP_X_SMARTEINZUG_MONITOR'])) {
                return; // Selbstprüfungen des Sammlers nicht mitzählen
            }
            $ms = (int)round((microtime(true) - (float)$GLOBALS['__req_started']) * 1000);
            $code = http_response_code();
            $is5xx = is_int($code) && $code >= 500 ? 1 : 0;
            db()->prepare(
                'INSERT INTO monitor_requests (minute, requests, errors_5xx, sum_ms, max_ms) VALUES (?, 1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE requests = requests + 1, errors_5xx = errors_5xx + VALUES(errors_5xx), sum_ms = sum_ms + VALUES(sum_ms), max_ms = GREATEST(max_ms, VALUES(max_ms))'
            )->execute([gmdate('Y-m-d H:i:00'), $is5xx, $ms, $ms]);
        } catch (Throwable $e) {
            // Diagnose darf die Anwendung nie stören
        }
    });
}
