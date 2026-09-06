<?php
/**
 * Systemmonitoring (Auftrag II, Abschnitt 7) und Datenquelle der öffentlichen Statusseite (Abschnitt 8).
 *
 * Grundsätze:
 *  - Es werden ausschließlich tatsächlich erhobene Werte gespeichert und angezeigt. Fehlende
 *    Messmöglichkeiten erscheinen als "Vom Hosting nicht bereitgestellt", "Nicht eingerichtet"
 *    oder "Noch keine Messdaten", nie als 0 %, 100 % oder "Betriebsbereit".
 *  - Erfasst werden die eigenen SmartEinzug-Jobs (Cron, Synchronisationsschritte, Einzugsverarbeitung)
 *    und instrumentierte PHP-Anfragen, nicht sämtliche Prozesse des Hosts (IONOS Webhosting ohne
 *    Root-Zugang: CPU-, Gesamt-RAM- und Prozessdaten stehen nicht zur Verfügung).
 *  - Der Sammler (monitor_collect) läuft im Cron unter eigener Sperre mit Zeitbudget und kurzen
 *    Timeouts. Seitenaufrufe im Admin oder auf der Statusseite lösen keine neuen Prüfungen aus.
 *  - Monitoring startet nie Synchronisierungen, Lastschriften oder Migrationen und gibt keine
 *    fachlichen Sperren frei.
 *  - Alle Zeiten der Monitoringtabellen sind UTC; Anzeige in Europe/Berlin mit Zeitzonenangabe.
 */
declare(strict_types=1);

require_once __DIR__ . '/collections.php'; // platform_setting()

const MONITOR_LOCK_NAME     = 'smarteinzug_monitor';
const MONITOR_RAW_DAYS      = 14;   // Rohmessungen
const MONITOR_REQ_DAYS      = 30;   // Minutenzähler der Anfragen
const MONITOR_DAILY_DAYS    = 400;  // Tagesaggregate
const MONITOR_JOBRUNS_DAYS  = 30;   // Ausführungsversuche

const MONITOR_STATES = ['ok' => 'Betriebsbereit', 'degraded' => 'Eingeschränkt', 'fail' => 'Störung', 'maintenance' => 'Wartung', 'unknown' => 'Status unbekannt'];
const MONITOR_STATE_SYMBOLS = ['ok' => '✔', 'degraded' => '▲', 'fail' => '✖', 'maintenance' => '⚒', 'unknown' => '?'];

/** Konfiguration mit Ausgangswerten (config.php: 'monitoring' => [...]). */
function monitor_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $c = (array)config('monitoring', []);
    $cfg = [
        'cron_interval_seconds'   => max(60, (int)($c['cron_interval_seconds'] ?? 300)),
        'collect_interval_seconds'=> max(60, (int)($c['collect_interval_seconds'] ?? 240)),
        'budget_seconds'          => max(2.0, min(15.0, (float)($c['budget_seconds'] ?? 8.0))),
        'tls_warn_days'           => max(1, (int)($c['tls_warn_days'] ?? 14)),
        'tls_enabled'             => (bool)($c['tls_enabled'] ?? true),
        'tls_hosts'               => array_values(array_filter((array)($c['tls_hosts'] ?? []))),
        'alert_emails'            => array_values(array_filter((array)($c['alert_emails'] ?? []))),
        'alert_fail_streak'       => max(2, (int)($c['alert_fail_streak'] ?? 3)),
        'alert_ok_streak'         => max(1, (int)($c['alert_ok_streak'] ?? 2)),
        'public_min_coverage_pct' => (float)($c['public_min_coverage_pct'] ?? 99.0),
        'latency_warn_ms'         => array_merge(['db' => 500, 'php_app' => 3000, 'web_ui' => 3000, 'admin_ui' => 3000], (array)($c['latency_warn_ms'] ?? [])),
        'error_rate_warn_pct'     => (float)($c['error_rate_warn_pct'] ?? 10.0),
        'min_sample'              => max(1, (int)($c['min_sample'] ?? 20)),
        'freshness'               => array_merge(['php_app' => 900, 'db' => 900, 'web_ui' => 900, 'admin_ui' => 900, 'cron' => 900, 'mail' => 3600, 'lexoffice' => 7200, 'stripe' => 7200, 'deploy' => 7200, 'sftp' => 86400, 'db_size' => 86400, 'storage' => 86400], (array)($c['freshness'] ?? [])),
        'test_mail_to'            => trim((string)($c['test_mail_to'] ?? '')),
        'app_url_override'        => isset($c['app_url_override']) ? rtrim((string)$c['app_url_override'], '/') : null,
        'editors'                 => array_map('mb_strtolower', array_values(array_filter((array)($c['editors'] ?? [])))),
        'tariff_limits'           => (array)($c['tariff_limits'] ?? []), // manuell hinterlegt: ['php_memory_mb' => ['value' => 512, 'source' => '...', 'date' => 'TT.MM.JJJJ']]
        'status_page_url'         => rtrim(trim((string)config('status_page_url', '')), '/'),
        'publish'                 => (array)config('status_publish', []),
    ];
    return $cfg;
}

function monitor_available(): bool
{
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM monitor_checks LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

function monitor_now(): int
{
    return time();
}

function mon_utc(int $ts): string
{
    return gmdate('Y-m-d H:i:s', $ts);
}

function mon_ts(?string $utc): ?int
{
    if (!$utc) {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

/** UTC-Wert lokal (Europe/Berlin, laut config timezone) mit Zeitzonenkürzel. */
function mon_local(?string $utc, bool $withZone = true): string
{
    $ts = mon_ts($utc);
    if ($ts === null) {
        return '-';
    }
    return date('d.m.Y H:i:s', $ts) . ($withZone ? ' ' . date('T', $ts) : '');
}

function mon_iso(?int $ts): ?string
{
    return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function mon_age_label(?int $seconds): string
{
    if ($seconds === null) {
        return 'keine Messung';
    }
    if ($seconds < 60) {
        return 'vor ' . $seconds . ' s';
    }
    if ($seconds < 3600) {
        return 'vor ' . intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        return 'vor ' . round($seconds / 3600, 1) . ' h';
    }
    return 'vor ' . round($seconds / 86400, 1) . ' Tagen';
}

function mon_duration_label(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%d h %02d min', $h, $m) : sprintf('%d min', $m);
}

// ---------------------------------------------------------------------------
// Instrumentierung: Jobs, Ereignisse, Marker
// ---------------------------------------------------------------------------

/** Ausführungsversuch beginnen. Liefert die Kennung oder null (Tabelle fehlt). Fehler blockieren nie den Job. */
function job_run_start(string $type, ?string $key = null, ?string $tenantId = null, string $source = 'cron'): ?string
{
    if (!monitor_available()) {
        return null;
    }
    try {
        $id = uuid4();
        $now = mon_utc(monitor_now());
        db()->prepare('INSERT INTO job_runs (id, job_type, job_key, tenant_id, source, status, started_at, heartbeat_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, mb_substr($type, 0, 30), $key !== null ? mb_substr($key, 0, 120) : null, $tenantId, mb_substr($source, 0, 20), 'running', $now, $now]);
        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

function job_run_heartbeat(?string $id, array $progress = []): void
{
    if ($id === null) {
        return;
    }
    try {
        db()->prepare('UPDATE job_runs SET heartbeat_at = ?, items_processed = ?, api_calls = ? WHERE id = ? AND status = ?')
            ->execute([mon_utc(monitor_now()), (int)($progress['items'] ?? 0), (int)($progress['api_calls'] ?? 0), $id, 'running']);
    } catch (Throwable $e) {
        // Diagnose darf den Job nicht stören
    }
}

/**
 * Ausführungsversuch abschließen. $m: items, api_calls, api_errors, throttle_ms, retries, skipped_starts.
 * $category ist eine bereinigte Fehlerkategorie (kein Rohtext).
 */
function job_run_finish(?string $id, string $status, array $m = [], ?string $category = null): void
{
    if ($id === null) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT started_at FROM job_runs WHERE id = ?');
        $stmt->execute([$id]);
        $started = mon_ts((string)$stmt->fetchColumn()) ?? monitor_now();
        $now = monitor_now();
        db()->prepare(
            'UPDATE job_runs SET status = ?, finished_at = ?, heartbeat_at = ?, duration_ms = ?, items_processed = ?, api_calls = ?, api_errors = ?,
                    throttle_ms = ?, retries = ?, skipped_starts = ?, peak_memory_bytes = ?, error_category = ? WHERE id = ?'
        )->execute([
            in_array($status, ['success', 'failed', 'unknown'], true) ? $status : 'unknown',
            mon_utc($now), mon_utc($now), max(0, ($now - $started) * 1000 + (int)($m['extra_ms'] ?? 0)),
            (int)($m['items'] ?? 0), (int)($m['api_calls'] ?? 0), (int)($m['api_errors'] ?? 0),
            (int)($m['throttle_ms'] ?? 0), (int)($m['retries'] ?? 0), (int)($m['skipped_starts'] ?? 0),
            memory_get_peak_usage(true), $category !== null ? mb_substr($category, 0, 60) : null, $id,
        ]);
    } catch (Throwable $e) {
        // Diagnose darf den Job nicht stören
    }
}

/** Messung oder instrumentiertes Ereignis speichern. */
function monitor_event(string $component, string $status, ?int $latencyMs = null, ?string $category = null, string $source = 'instrumented', int $validSeconds = 300, ?float $value = null, ?string $unit = null): void
{
    if (!monitor_available()) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO monitor_checks (component, source, checked_at, status, latency_ms, value_num, unit, category, valid_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr($component, 0, 40), mb_substr($source, 0, 20), mon_utc(monitor_now()),
            in_array($status, ['ok', 'degraded', 'fail', 'unknown'], true) ? $status : 'unknown',
            $latencyMs, $value, $unit !== null ? mb_substr($unit, 0, 12) : null,
            $category !== null ? mb_substr($category, 0, 60) : null, max(30, $validSeconds),
        ]);
    } catch (Throwable $e) {
        // Diagnose darf die Anwendung nicht stören
    }
}

function monitor_mark(string $key, ?string $value): void
{
    try {
        platform_setting_set('mon_' . mb_substr($key, 0, 58), $value !== null ? mb_substr($value, 0, 255) : null);
    } catch (Throwable $e) {
        // platform_settings fehlt bis Migration 006
    }
}

function monitor_mark_get(string $key): ?string
{
    try {
        return platform_setting('mon_' . mb_substr($key, 0, 58));
    } catch (Throwable $e) {
        return null;
    }
}

/** Bereinigte Fehlerkategorie aus Ausnahme oder Text (keine Rohmeldungen, keine Geheimnisse). */
function monitor_category($e): string
{
    $msg = mb_strtolower($e instanceof Throwable ? $e->getMessage() : (string)$e);
    if (preg_match('/timeout|timed out|zeitüberschreitung/', $msg)) return 'timeout';
    if (preg_match('/could not resolve|dns|name or service/', $msg)) return 'dns';
    if (preg_match('/ssl|tls|certificate|zertifikat/', $msg)) return 'tls';
    if (preg_match('/401|403|unauthori|forbidden|api key|api-schl|ungültiger schl/', $msg)) return 'auth';
    if (preg_match('/429|rate limit|too many|drossel/', $msg)) return 'throttled';
    if (preg_match('/50\d|gateway|unavailable/', $msg)) return 'http_5xx';
    if (preg_match('/connect|connection|verbindung/', $msg)) return 'connection';
    if (preg_match('/sqlstate|database|datenbank|deadlock/', $msg)) return 'database';
    return 'other';
}

// ---------------------------------------------------------------------------
// Sammler
// ---------------------------------------------------------------------------

/** Basisadresse der Anwendung für Selbstprüfungen (Tests können sie umbiegen). */
function monitor_app_url(): string
{
    $o = monitor_config()['app_url_override'];
    return $o !== null && $o !== '' ? $o : rtrim(app_base_url(), '/');
}

/** Begrenzter HTTP-Abruf nur für serverseitig festgelegte Ziele (kein URL-Prüfer für Benutzer). */
function _mon_http_get(string $url, int $timeout = 5): array
{
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['X-SmartEinzug-Monitor: 1', 'Cache-Control: no-cache'],
        CURLOPT_RANGE          => '0-65535',
        CURLOPT_USERAGENT      => 'SmartEinzug-Monitor',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = (string)curl_error($ch);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [
        'ok' => $body !== false, 'status' => $status, 'ms' => (int)round((microtime(true) - $t0) * 1000),
        'body' => $body === false ? '' : (string)$body, 'error' => $err, 'content_type' => $ctype,
        'category' => $body === false ? monitor_category($err) : ($status >= 500 ? 'http_5xx' : ($status >= 400 ? 'http_4xx' : ($status >= 300 ? 'redirect' : null))),
    ];
}

function _mon_check_db(array $cfg): array
{
    $t0 = microtime(true);
    try {
        db()->query('SELECT 1')->fetchColumn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return ['status' => $ms > (int)$cfg['latency_warn_ms']['db'] ? 'degraded' : 'ok', 'ms' => $ms, 'category' => $ms > (int)$cfg['latency_warn_ms']['db'] ? 'slow' : null];
    } catch (Throwable $e) {
        return ['status' => 'fail', 'ms' => null, 'category' => monitor_category($e)];
    }
}

function _mon_check_php_app(array $cfg): array
{
    $r = _mon_http_get(monitor_app_url() . '/health.php', 5);
    if (!$r['ok']) {
        return ['status' => 'fail', 'ms' => null, 'category' => $r['category']];
    }
    $data = json_decode($r['body'], true);
    if ($r['status'] !== 200 && $r['status'] !== 503) {
        return ['status' => 'fail', 'ms' => $r['ms'], 'category' => $r['category'] ?? ('http_' . $r['status'])];
    }
    if (!is_array($data) || empty($data['php']) || empty($data['time'])) {
        // Statische oder gecachte Antwort beweist keine PHP-Ausführung
        return ['status' => 'fail', 'ms' => $r['ms'], 'category' => 'not_dynamic'];
    }
    $age = abs(monitor_now() - (mon_ts(str_replace(['T', 'Z'], [' ', ''], (string)$data['time'])) ?? 0));
    if ($age > 120) {
        return ['status' => 'fail', 'ms' => $r['ms'], 'category' => 'stale_response'];
    }
    if (empty($data['db'])) {
        return ['status' => 'degraded', 'ms' => $r['ms'], 'category' => 'health_db'];
    }
    return ['status' => $r['ms'] > (int)$cfg['latency_warn_ms']['php_app'] ? 'degraded' : 'ok', 'ms' => $r['ms'], 'category' => $r['ms'] > (int)$cfg['latency_warn_ms']['php_app'] ? 'slow' : null];
}

function _mon_check_web_ui(array $cfg, string $base, string $component): array
{
    $r = _mon_http_get($base . '/login.php', 5);
    if (!$r['ok'] || $r['status'] !== 200) {
        return ['status' => 'fail', 'ms' => $r['ms'] ?: null, 'category' => $r['category'] ?? ('http_' . $r['status'])];
    }
    if (!str_contains($r['body'], 'name="password"') || !str_contains($r['body'], '<form')) {
        return ['status' => 'fail', 'ms' => $r['ms'], 'category' => 'marker_missing'];
    }
    $assetsOk = true;
    foreach (['assets/css/style.css', 'assets/js/app.js'] as $asset) {
        $a = _mon_http_get($base . '/' . $asset, 5);
        if (!$a['ok'] || ($a['status'] !== 200 && $a['status'] !== 206) || trim($a['body']) === '') {
            $assetsOk = false;
        }
    }
    if (!$assetsOk) {
        return ['status' => 'degraded', 'ms' => $r['ms'], 'category' => 'asset'];
    }
    return ['status' => $r['ms'] > (int)$cfg['latency_warn_ms'][$component] ? 'degraded' : 'ok', 'ms' => $r['ms'], 'category' => $r['ms'] > (int)$cfg['latency_warn_ms'][$component] ? 'slow' : null];
}

function _mon_tls_hosts(array $cfg): array
{
    if (!$cfg['tls_enabled']) {
        return [];
    }
    $hosts = $cfg['tls_hosts'];
    if (!$hosts) {
        foreach ([app_base_url(), admin_base_url(), public_base_url()] as $u) {
            if (str_starts_with(strtolower($u), 'https://')) {
                $h = base_url_host($u);
                if ($h !== '') {
                    $hosts[] = $h;
                }
            }
        }
    }
    return array_values(array_unique(array_filter($hosts, fn($h) => preg_match('/^[a-z0-9.-]+$/i', $h) && !filter_var($h, FILTER_VALIDATE_IP))));
}

function _mon_check_tls(string $host, array $cfg): array
{
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $t0 = microtime(true);
    $sock = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if (!$sock) {
        return ['status' => 'fail', 'ms' => null, 'category' => monitor_category((string)$errstr ?: 'tls'), 'value' => null];
    }
    $params = stream_context_get_params($sock);
    fclose($sock);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    $info = $cert ? openssl_x509_parse($cert) : null;
    if (!$info || empty($info['validTo_time_t'])) {
        return ['status' => 'fail', 'ms' => $ms, 'category' => 'cert_unreadable', 'value' => null];
    }
    $days = (int)floor(((int)$info['validTo_time_t'] - monitor_now()) / 86400);
    if ($days < 0) {
        return ['status' => 'fail', 'ms' => $ms, 'category' => 'cert_expired', 'value' => $days];
    }
    return ['status' => $days < (int)$cfg['tls_warn_days'] ? 'degraded' : 'ok', 'ms' => $ms, 'category' => $days < (int)$cfg['tls_warn_days'] ? 'cert_expiring' : null, 'value' => $days];
}

/** Zustand des Mailversands aus den Markern (Übergabe an den Versandweg, kein Zustellnachweis). */
function _mon_check_mail(): array
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled()) {
        return ['status' => 'unknown', 'category' => 'not_configured'];
    }
    $ok = mon_ts(monitor_mark_get('mail_last_ok_at'));
    $fail = mon_ts(monitor_mark_get('mail_last_fail_at'));
    if ($ok === null && $fail === null) {
        return ['status' => 'unknown', 'category' => 'no_data'];
    }
    if ($fail !== null && ($ok === null || $fail > $ok)) {
        return ['status' => 'degraded', 'category' => monitor_mark_get('mail_last_fail_category') ?: 'send_failed'];
    }
    return ['status' => 'ok', 'category' => null];
}

function _mon_check_cron(array $cfg): array
{
    try {
        $stmt = db()->query("SELECT started_at FROM job_runs WHERE job_type = 'cron' ORDER BY started_at DESC LIMIT 1");
        $last = mon_ts((string)$stmt->fetchColumn());
    } catch (Throwable $e) {
        $last = null;
    }
    if ($last === null) {
        return ['status' => 'unknown', 'category' => 'no_data', 'value' => null];
    }
    $age = monitor_now() - $last;
    $iv = (int)$cfg['cron_interval_seconds'];
    if ($age <= $iv * 2 + 60) {
        return ['status' => 'ok', 'category' => null, 'value' => $age];
    }
    return ['status' => $age <= $iv * 4 ? 'degraded' : 'fail', 'category' => 'cron_late', 'value' => $age];
}

/** Laufende Versuche mit abgelaufenem Heartbeat als "Ausführung unbestätigt" kennzeichnen (nur Monitoringdaten). */
function _mon_mark_stale_runs(): int
{
    $ttl = ['sync' => 300, 'collections' => 600, 'cron' => 300, 'monitor' => 120];
    $n = 0;
    foreach ($ttl as $type => $seconds) {
        try {
            $stmt = db()->prepare("UPDATE job_runs SET status = 'unknown', error_category = 'heartbeat_stale' WHERE job_type = ? AND status = 'running' AND heartbeat_at < ?");
            $stmt->execute([$type, mon_utc(monitor_now() - $seconds)]);
            $n += $stmt->rowCount();
        } catch (Throwable $e) {
            // ignorieren
        }
    }
    return $n;
}

/** Technische Fehlerquote einer Anbindung aus instrumentierten Daten der letzten 24 Stunden. */
function _mon_check_integration(string $component, array $cfg): array
{
    $from = mon_utc(monitor_now() - 86400);
    try {
        if ($component === 'lexoffice') {
            $stmt = db()->prepare("SELECT COALESCE(SUM(api_calls),0) AS calls, COALESCE(SUM(api_errors),0) AS errors,
                                          SUM(CASE WHEN error_category = 'auth' THEN 1 ELSE 0 END) AS auth_errors
                                   FROM job_runs WHERE job_type = 'sync' AND finished_at >= ?");
            $stmt->execute([$from]);
            $r = $stmt->fetch();
            $calls = (int)$r['calls']; $errors = (int)$r['errors'];
        } else {
            $stmt = db()->prepare("SELECT SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS oks, SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) AS fails
                                   FROM monitor_checks WHERE component = ? AND source = 'instrumented' AND checked_at >= ?");
            $stmt->execute([$component . '_api', $from]);
            $r = $stmt->fetch();
            $calls = (int)$r['oks'] + (int)$r['fails']; $errors = (int)$r['fails'];
        }
    } catch (Throwable $e) {
        return ['status' => 'unknown', 'category' => 'database', 'value' => null];
    }
    if ($calls === 0) {
        return ['status' => 'unknown', 'category' => 'no_data', 'value' => null];
    }
    $rate = 100.0 * $errors / $calls;
    if ($calls >= (int)$cfg['min_sample'] && $rate >= (float)$cfg['error_rate_warn_pct']) {
        return ['status' => 'degraded', 'category' => 'api_errors', 'value' => round($rate, 2)];
    }
    if ($calls < (int)$cfg['min_sample'] && $errors > 0 && $errors === $calls) {
        return ['status' => 'degraded', 'category' => 'api_errors_small_sample', 'value' => round($rate, 2)];
    }
    return ['status' => 'ok', 'category' => null, 'value' => round($rate, 2)];
}

/**
 * Sicherungen: liest die vom Backup-Container geschriebene Ergebnisdatei (storage_dir()/backup-status.json).
 * Ohne Datei "Nicht eingerichtet"; älter als 26 Stunden gilt als veraltet; status fail aus der Datei als Störung.
 */
function _mon_check_backup(): array
{
    $file = (function_exists('storage_dir') ? storage_dir() : APP_ROOT . '/app/storage') . '/backup-status.json';
    if (!is_file($file)) {
        return ['status' => 'unknown', 'category' => 'not_configured', 'value' => null];
    }
    $d = json_decode((string)@file_get_contents($file), true);
    if (!is_array($d) || empty($d['finished_at'])) {
        return ['status' => 'unknown', 'category' => 'unreadable', 'value' => null];
    }
    $ts = mon_ts(str_replace(['T', 'Z'], [' ', ''], (string)$d['finished_at']));
    $mb = isset($d['bytes']) ? round((float)$d['bytes'] / 1048576, 2) : null;
    if (($d['status'] ?? '') !== 'ok') {
        return ['status' => 'fail', 'category' => 'backup_failed', 'value' => $mb];
    }
    if ($ts === null || monitor_now() - $ts > 26 * 3600) {
        return ['status' => 'degraded', 'category' => 'backup_stale', 'value' => $mb];
    }
    monitor_mark('backup_last_ok_at', mon_utc($ts));
    return ['status' => 'ok', 'category' => empty($d['remote']) ? 'local_only' : null, 'value' => $mb];
}

function _mon_check_deploy(): array
{
    require_once __DIR__ . '/migrate.php';
    try {
        $st = migrations_status();
    } catch (Throwable $e) {
        return ['status' => 'unknown', 'category' => 'database', 'value' => null];
    }
    $states = array_count_values(array_map(fn($m) => (string)$m['state'], $st));
    if (!empty($states['failed']) || !empty($states['unknown'])) {
        return ['status' => 'degraded', 'category' => !empty($states['failed']) ? 'migration_failed' : 'migration_unknown', 'value' => (int)($states['pending'] ?? 0)];
    }
    if (!empty($states['running'])) {
        return ['status' => 'degraded', 'category' => 'migration_running', 'value' => (int)($states['pending'] ?? 0)];
    }
    return ['status' => 'ok', 'category' => null, 'value' => (int)($states['pending'] ?? 0)];
}

/**
 * Alle freigegebenen Prüfungen ausführen. Läuft unter eigener Sperre mit Zeitbudget; jede
 * Prüfung hat einen eigenen kurzen Timeout, damit eine langsame Komponente die übrigen nicht blockiert.
 * $opts: force (Intervall ignorieren), budget (Sekunden), source (cron|admin), publish (bool)
 */
function monitor_collect(array $opts = []): array
{
    if (!monitor_available()) {
        return ['skipped' => 'not_available'];
    }
    $cfg = monitor_config();
    $pdo = db();
    $force = !empty($opts['force']);
    $budget = (float)($opts['budget'] ?? $cfg['budget_seconds']);
    $start = microtime(true);
    $deadline = $start + $budget;

    if (!$force) {
        $last = mon_ts(monitor_mark_get('collect_last_at'));
        if ($last !== null && monitor_now() - $last < (int)$cfg['collect_interval_seconds'] - 30) {
            return ['skipped' => 'recent', 'last_at' => $last];
        }
    }
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([MONITOR_LOCK_NAME]);
    $got = (int)$stmt->fetchColumn() === 1;
    $stmt->closeCursor();
    if (!$got) {
        return ['skipped' => 'locked'];
    }
    $runId = job_run_start('monitor', 'monitor:collect', null, (string)($opts['source'] ?? 'cron'));
    $summary = ['checks' => [], 'stale_runs' => 0, 'skipped_checks' => [], 'alerts' => [], 'publish' => null];
    try {
        $plan = [
            ['db',       fn() => _mon_check_db($cfg), 900],
            ['php_app',  fn() => _mon_check_php_app($cfg), 900],
            ['web_ui',   fn() => _mon_check_web_ui($cfg, monitor_app_url(), 'web_ui'), 900],
            ['cron',     fn() => _mon_check_cron($cfg), 900],
            ['mail',     fn() => _mon_check_mail(), 3600],
            ['lexoffice',fn() => _mon_check_integration('lexoffice', $cfg), 7200],
            ['stripe',   fn() => _mon_check_integration('stripe', $cfg), 7200],
            ['deploy',   fn() => _mon_check_deploy(), 7200],
            ['backup',   fn() => _mon_check_backup(), 86400],
        ];
        if (admin_base_url() !== '' && base_url_host(admin_base_url()) !== base_url_host(app_base_url())) {
            $plan[] = ['admin_ui', fn() => _mon_check_web_ui($cfg, rtrim(admin_base_url(), '/'), 'admin_ui'), 900];
        }
        foreach (_mon_tls_hosts($cfg) as $host) {
            $plan[] = ['tls:' . $host, fn() => _mon_check_tls($host, $cfg), 21600];
        }
        foreach ($plan as [$component, $fn, $valid]) {
            if (microtime(true) > $deadline) {
                $summary['skipped_checks'][] = $component; // Budget erschöpft: keine Messung, keine Erfindung
                continue;
            }
            try {
                $r = $fn();
            } catch (Throwable $e) {
                $r = ['status' => 'unknown', 'ms' => null, 'category' => monitor_category($e)];
            }
            monitor_event($component, (string)$r['status'], isset($r['ms']) ? (int)$r['ms'] : null, $r['category'] ?? null, 'internal', $valid,
                isset($r['value']) && $r['value'] !== null ? (float)$r['value'] : null,
                str_starts_with($component, 'tls:') ? 'Tage' : ($component === 'cron' ? 's' : ($component === 'backup' ? 'MB' : (in_array($component, ['lexoffice', 'stripe'], true) ? '%' : null))));
            $summary['checks'][$component] = $r['status'];
        }
        // SFTP kann aus der Anwendung nicht geprüft werden (keine Deployment-Zugangsdaten in der Web-App)
        monitor_event('sftp', 'unknown', null, 'not_checked', 'internal', 86400);
        $summary['checks']['sftp'] = 'unknown';

        // Seltene Größenmessungen (stündlich): Datenbank und Mandatsspeicher aus verifizierten Quellen
        $sizeAt = mon_ts(monitor_mark_get('size_last_at'));
        if (($sizeAt === null || monitor_now() - $sizeAt >= 3600) && microtime(true) < $deadline) {
            try {
                $bytes = (float)$pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
                monitor_event('db_size', 'ok', null, 'information_schema', 'internal', 86400, round($bytes / 1048576, 2), 'MB');
            } catch (Throwable $e) {
                monitor_event('db_size', 'unknown', null, 'not_available', 'internal', 86400);
            }
            try {
                $bytes = (float)$pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM mandate_files')->fetchColumn();
                monitor_event('storage', 'ok', null, 'mandate_files', 'internal', 86400, round($bytes / 1048576, 2), 'MB');
            } catch (Throwable $e) {
                monitor_event('storage', 'unknown', null, 'not_available', 'internal', 86400);
            }
            monitor_mark('size_last_at', mon_utc(monitor_now()));
        }

        $summary['stale_runs'] = _mon_mark_stale_runs();
        monitor_aggregate_daily([gmdate('Y-m-d'), gmdate('Y-m-d', monitor_now() - 86400)]);
        monitor_cleanup();
        $summary['alerts'] = monitor_alerts_evaluate();
        if (($opts['publish'] ?? true) && $cfg['publish']) {
            $summary['publish'] = status_publish(monitor_public_snapshot());
        }
        monitor_mark('collect_last_at', mon_utc(monitor_now()));
        monitor_mark('collect_last_duration_ms', (string)(int)round((microtime(true) - $start) * 1000));
        job_run_finish($runId, 'success', ['items' => count($summary['checks'])]);
    } catch (Throwable $e) {
        job_run_finish($runId, 'failed', [], monitor_category($e));
        error_log('Monitoring: Sammler abgebrochen (' . monitor_category($e) . ')');
    } finally {
        try {
            $s = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute([MONITOR_LOCK_NAME]);
            $s->closeCursor();
        } catch (Throwable $e) {
            // Verbindung endet mit dem Request
        }
    }
    return $summary;
}

// ---------------------------------------------------------------------------
// Auswertung: Komponenten, Zeitfenster, Verfügbarkeit
// ---------------------------------------------------------------------------

/** Letzte Messung einer Komponente. */
function monitor_latest(string $component, ?PDO $pdo = null): ?array
{
    if (!monitor_available()) {
        return null;
    }
    $stmt = ($pdo ?? db())->prepare('SELECT * FROM monitor_checks WHERE component = ? ORDER BY checked_at DESC, id DESC LIMIT 1');
    $stmt->execute([$component]);
    return $stmt->fetch() ?: null;
}

/** Letzte erfolgreiche Messung. */
function monitor_last_ok(string $component): ?array
{
    if (!monitor_available()) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM monitor_checks WHERE component = ? AND status = 'ok' ORDER BY checked_at DESC, id DESC LIMIT 1");
    $stmt->execute([$component]);
    return $stmt->fetch() ?: null;
}

/** Zustand aus letzter Messung und Frischegrenze: veraltete Messungen gelten als unbekannt. */
function monitor_component_state(?array $row, int $freshness): array
{
    if (!$row) {
        return ['state' => 'unknown', 'reason' => 'no_data', 'age' => null, 'stale' => false];
    }
    $age = monitor_now() - (mon_ts($row['checked_at']) ?? 0);
    if ($age > $freshness) {
        return ['state' => 'unknown', 'reason' => 'stale', 'age' => $age, 'stale' => true];
    }
    return ['state' => (string)$row['status'], 'reason' => $row['category'], 'age' => $age, 'stale' => false];
}

/** Beschreibung der internen Komponenten (Bezeichnung, Datenquelle, Hinweis zur Abgrenzung). */
function monitor_component_defs(): array
{
    return [
        'php_app'  => ['name' => 'PHP / Anwendungsantwort', 'source' => 'HTTP-Abruf von health.php (dynamische Antwort mit Zeitstempel, ohne Cache)', 'note' => 'Eine statische HTTP-200-Seite gilt nicht als PHP-Erfolg.'],
        'db'       => ['name' => 'Datenbank', 'source' => 'SELECT 1 über die bestehende Verbindung, Latenz in ms', 'note' => 'Lesetest; Schreibfähigkeit wird nicht behauptet.'],
        'web_ui'   => ['name' => 'Weboberfläche (Kundenanwendung)', 'source' => 'login.php mit Inhaltsmerkmal, style.css und app.js aus dem Assetbestand', 'note' => 'Kein vollständiger Browser-Funktionstest.'],
        'admin_ui' => ['name' => 'Weboberfläche (Administration)', 'source' => 'login.php des Admin-Hosts mit Inhaltsmerkmal', 'note' => 'Nur bei getrenntem Admin-Host.'],
        'cron'     => ['name' => 'Cronjobs / Aufgabenverarbeitung', 'source' => 'Startzeit des letzten Cron-Laufs (job_runs) gegen Sollintervall', 'note' => 'Verspätung nach Sollintervall mit Toleranz.'],
        'mail'     => ['name' => 'E-Mail', 'source' => 'Marker der letzten Übergabe an den Versandweg und des letzten Fehlers', 'note' => 'Übergabe an den Versandweg, kein Zustellnachweis.'],
        'lexoffice'=> ['name' => 'Lexware-Anbindung', 'source' => 'API-Zähler der Synchronisationsschritte der letzten 24 Stunden', 'note' => 'Ein ungültiger Schlüssel einer Firma zählt nicht als Plattformstörung.'],
        'stripe'   => ['name' => 'Stripe-Anbindung', 'source' => 'Instrumentierte API-Aufrufe und Webhook-Verarbeitung der letzten 24 Stunden', 'note' => 'Fachlich abgelehnte Zahlungen zählen nicht als Ausfall.'],
        'deploy'   => ['name' => 'Deployment / Migrationen', 'source' => 'schema_migrations und Marker des Migrationsendpunkts', 'note' => 'Nur lesend, keine Ausführung.'],
        'backup'   => ['name' => 'Sicherungen', 'source' => 'Ergebnisdatei backup-status.json des Backup-Containers (Status, Größe, Prüfsumme, Zeitpunkt)', 'note' => 'Ohne Datei nicht eingerichtet; ein gemeldetes Backup ist kein Wiederherstellungstest.'],
        'sftp'     => ['name' => 'SFTP / Deployment-Zugang', 'source' => 'Keine Prüfmöglichkeit aus der Anwendung', 'note' => 'Deployment-Zugangsdaten liegen nur in GitHub; Zustand "Nicht geprüft".'],
        'db_size'  => ['name' => 'Datenbankgröße', 'source' => 'information_schema (stündlich)', 'note' => 'Kontingent des Tarifs wird vom Hosting nicht bereitgestellt.'],
        'storage'  => ['name' => 'Mandatsspeicher', 'source' => 'Summe der Dateigrößen in mandate_files (stündlich)', 'note' => 'Webspace-Kontingent nicht bereitgestellt; Sicherungen nicht überwacht.'],
    ];
}

/** Übersicht aller internen Komponenten mit Zustand, letzter und letzter erfolgreicher Prüfung. */
function monitor_components_overview(): array
{
    $cfg = monitor_config();
    $defs = monitor_component_defs();
    $out = [];
    if (!monitor_available()) {
        return $out;
    }
    $components = array_keys($defs);
    $rows = db()->query("SELECT DISTINCT component FROM monitor_checks WHERE component LIKE 'tls:%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $c) {
        $components[] = $c;
    }
    foreach (array_unique($components) as $c) {
        $latest = monitor_latest($c);
        $fresh = (int)($cfg['freshness'][$c] ?? (str_starts_with($c, 'tls:') ? 43200 : 900));
        $state = monitor_component_state($latest, $fresh);
        $def = $defs[$c] ?? ['name' => 'HTTPS / Zertifikat ' . substr($c, 4), 'source' => 'TLS-Handshake mit Zertifikatsprüfung, Ablauf in Tagen', 'note' => 'TLS-Prüfung wird nie deaktiviert.'];
        $out[$c] = [
            'key' => $c, 'name' => $def['name'], 'source' => $def['source'], 'note' => $def['note'],
            'state' => $state['state'], 'reason' => $state['reason'], 'stale' => $state['stale'], 'age' => $state['age'],
            'latest' => $latest, 'last_ok' => monitor_last_ok($c), 'freshness' => $fresh,
        ];
    }
    return $out;
}

/**
 * Zeitgewichtete Verfügbarkeit aus Rohmessungen für [from, to).
 * Jede Messung gilt ab ihrem Zeitpunkt bis zur nächsten Messung, höchstens valid_seconds.
 * Nicht abgedeckte Zeit ist unbekannt (weder Erfolg noch Ausfall).
 */
function monitor_timeline(string $component, int $from, int $to): array
{
    $res = ['t_ok' => 0, 't_degraded' => 0, 't_fail' => 0, 't_unknown' => 0, 'checks' => 0, 'fails' => 0, 'window' => max(0, $to - $from)];
    if (!monitor_available() || $to <= $from) {
        return $res;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT checked_at, status, valid_seconds FROM monitor_checks WHERE component = ? AND checked_at < ? ORDER BY checked_at DESC, id DESC LIMIT 1');
    $stmt->execute([$component, mon_utc($from)]);
    $rows = $stmt->fetch() ? [$stmt->fetch() ?: null] : [];
    $stmt = $pdo->prepare('SELECT checked_at, status, valid_seconds FROM monitor_checks WHERE component = ? AND checked_at < ? ORDER BY checked_at DESC, id DESC LIMIT 1');
    $stmt->execute([$component, mon_utc($from)]);
    $prev = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT checked_at, status, valid_seconds FROM monitor_checks WHERE component = ? AND checked_at >= ? AND checked_at < ? ORDER BY checked_at ASC, id ASC');
    $stmt->execute([$component, mon_utc($from), mon_utc($to)]);
    $rows = $stmt->fetchAll();
    if ($prev) {
        array_unshift($rows, $prev);
    }
    $covered = 0;
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $r = $rows[$i];
        $ts = mon_ts($r['checked_at']) ?? $from;
        $segStart = max($ts, $from);
        $segEnd = min($ts + (int)$r['valid_seconds'], $to);
        if ($i + 1 < $n) {
            $segEnd = min($segEnd, mon_ts($rows[$i + 1]['checked_at']) ?? $segEnd);
        }
        if ($ts >= $from) {
            $res['checks']++;
            if ($r['status'] === 'fail') {
                $res['fails']++;
            }
        }
        if ($segEnd <= $segStart) {
            continue;
        }
        $len = $segEnd - $segStart;
        switch ($r['status']) {
            case 'ok': $res['t_ok'] += $len; break;
            case 'degraded': $res['t_ok'] += $len; $res['t_degraded'] += $len; break;
            case 'fail': $res['t_fail'] += $len; break;
            default: continue 2; // unknown: nicht abgedeckt
        }
        $covered += $len;
    }
    $res['t_unknown'] = max(0, $res['window'] - $covered);
    return $res;
}

/** Verfügbarkeit aus Tagesaggregaten plus Rohdaten des aktuellen Tages (für 7/30/90 Tage). */
function monitor_uptime(string $component, int $from, int $to): array
{
    $sum = ['t_ok' => 0, 't_degraded' => 0, 't_fail' => 0, 't_unknown' => 0, 'checks' => 0, 'fails' => 0, 'window' => max(0, $to - $from)];
    if (!monitor_available() || $to <= $from) {
        return monitor_uptime_finish($sum, $from, $to);
    }
    $todayStart = strtotime(gmdate('Y-m-d', $to) . ' 00:00:00 UTC');
    if ($to - $from <= 86400 * 2 || $from >= $todayStart) {
        $tl = monitor_timeline($component, $from, $to);
        return monitor_uptime_finish($tl, $from, $to);
    }
    // Volle Tage vor heute aus monitor_daily, Rest aus Rohdaten
    $dayFrom = gmdate('Y-m-d', $from);
    $dayTo = gmdate('Y-m-d', $todayStart - 1);
    $stmt = db()->prepare('SELECT * FROM monitor_daily WHERE component = ? AND day >= ? AND day <= ?');
    $stmt->execute([$component, $dayFrom, $dayTo]);
    $days = [];
    foreach ($stmt->fetchAll() as $d) {
        $days[$d['day']] = $d;
    }
    for ($d = strtotime($dayFrom . ' 00:00:00 UTC'); $d < $todayStart; $d += 86400) {
        $key = gmdate('Y-m-d', $d);
        $segStart = max($d, $from);
        $segEnd = min($d + 86400, $todayStart);
        $len = $segEnd - $segStart;
        if (isset($days[$key]) && $segStart === $d && $segEnd === $d + 86400) {
            foreach (['t_ok', 't_degraded', 't_fail', 't_unknown', 'checks', 'fails'] as $k) {
                $sum[$k] += (int)$days[$key][$k];
            }
        } elseif (isset($days[$key])) {
            $tl = monitor_timeline($component, $segStart, $segEnd); // Teiltag aus Rohdaten, falls vorhanden
            foreach (['t_ok', 't_degraded', 't_fail', 't_unknown', 'checks', 'fails'] as $k) {
                $sum[$k] += (int)$tl[$k];
            }
        } else {
            $sum['t_unknown'] += $len;
        }
    }
    $tl = monitor_timeline($component, max($from, $todayStart), $to);
    foreach (['t_ok', 't_degraded', 't_fail', 't_unknown', 'checks', 'fails'] as $k) {
        $sum[$k] += (int)$tl[$k];
    }
    return monitor_uptime_finish($sum, $from, $to);
}

/** Kennzahlen aus den Zeitsummen ableiten (Formel aus Abschnitt 7.7). */
function monitor_uptime_finish(array $t, int $from, int $to): array
{
    $observed = $t['t_ok'] + $t['t_fail'];
    $window = max(1, $to - $from);
    $t['availability_pct'] = $observed > 0 ? round(100 * $t['t_ok'] / $observed, 3) : null;
    $t['coverage_pct'] = round(100 * $observed / $window, 2);
    $t['available_hours'] = round($t['t_ok'] / 3600, 2);
    $t['downtime_label'] = mon_duration_label((int)$t['t_fail']);
    $t['unknown_label'] = mon_duration_label((int)$t['t_unknown']);
    $t['conservative_min_pct'] = round(100 * $t['t_ok'] / $window, 3); // unbekannte Zeit als nicht bestätigt
    $t['from'] = $from;
    $t['to'] = $to;
    return $t;
}

/** Tagesaggregate für die genannten UTC-Tage aus den Rohdaten neu berechnen. */
function monitor_aggregate_daily(array $days): void
{
    if (!monitor_available()) {
        return;
    }
    $pdo = db();
    $components = $pdo->query('SELECT DISTINCT component FROM monitor_checks')->fetchAll(PDO::FETCH_COLUMN);
    $now = monitor_now();
    foreach (array_unique($days) as $day) {
        $from = strtotime($day . ' 00:00:00 UTC');
        if ($from === false) {
            continue;
        }
        $to = min($from + 86400, $now);
        if ($to <= $from) {
            continue;
        }
        foreach ($components as $c) {
            $tl = monitor_timeline((string)$c, $from, $to);
            // Nicht erhobener Rest des Tages bleibt unbekannt (bis zum Tagesende)
            $tl['t_unknown'] += max(0, ($from + 86400) - $to);
            $pdo->prepare(
                'INSERT INTO monitor_daily (component, day, t_ok, t_degraded, t_fail, t_unknown, checks, fails, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE t_ok = VALUES(t_ok), t_degraded = VALUES(t_degraded), t_fail = VALUES(t_fail), t_unknown = VALUES(t_unknown), checks = VALUES(checks), fails = VALUES(fails), updated_at = VALUES(updated_at)'
            )->execute([$c, $day, $tl['t_ok'], $tl['t_degraded'], $tl['t_fail'], $tl['t_unknown'], $tl['checks'], $tl['fails'], mon_utc($now)]);
        }
    }
}

/** Bereinigung ausschließlich der Monitoringdaten (keine Journale, Audit- oder Zahlungsdaten). */
function monitor_cleanup(): void
{
    if (!monitor_available()) {
        return;
    }
    $pdo = db();
    $now = monitor_now();
    $pdo->prepare('DELETE FROM monitor_checks WHERE checked_at < ?')->execute([mon_utc($now - MONITOR_RAW_DAYS * 86400)]);
    $pdo->prepare('DELETE FROM monitor_requests WHERE minute < ?')->execute([mon_utc($now - MONITOR_REQ_DAYS * 86400)]);
    $pdo->prepare('DELETE FROM monitor_daily WHERE day < ?')->execute([gmdate('Y-m-d', $now - MONITOR_DAILY_DAYS * 86400)]);
    $pdo->prepare("DELETE FROM job_runs WHERE started_at < ? AND status <> 'running'")->execute([mon_utc($now - MONITOR_JOBRUNS_DAYS * 86400)]);
}

/** Zeitfenster für die Auswertung. */
function monitor_windows(): array
{
    return ['1m' => ['label' => 'Letzte 1 Minute', 'seconds' => 60], '10m' => ['label' => 'Letzte 10 Minuten', 'seconds' => 600],
            '1h' => ['label' => 'Letzte 1 Stunde', 'seconds' => 3600], '24h' => ['label' => 'Letzte 24 Stunden', 'seconds' => 86400]];
}

/** Kennzahlen der eigenen Jobs für [from, to) nach den Zählregeln aus Abschnitt 7.4. */
function monitor_job_stats(int $from, int $to): array
{
    $empty = ['started' => 0, 'finished_success' => 0, 'finished_failed' => 0, 'finished_unknown' => 0, 'attempts' => 0, 'unique_jobs' => 0,
        'items' => 0, 'api_calls' => 0, 'api_errors' => 0, 'skipped_starts' => 0, 'durations_n' => 0, 'duration_avg_ms' => null, 'duration_p95_ms' => null,
        'peak_memory_max' => null, 'active_now' => 0, 'unconfirmed_now' => 0, 'concurrency_max' => null, 'by_type' => []];
    if (!monitor_available()) {
        return $empty;
    }
    $pdo = db();
    $f = mon_utc($from); $t = mon_utc($to);
    $st = $pdo->prepare('SELECT COUNT(*) AS n, COUNT(DISTINCT COALESCE(job_key, id)) AS uniq FROM job_runs WHERE started_at >= ? AND started_at < ?');
    $st->execute([$f, $t]);
    $r = $st->fetch();
    $empty['started'] = (int)$r['n'];
    $empty['attempts'] = (int)$r['n'];
    $empty['unique_jobs'] = (int)$r['uniq'];
    $st = $pdo->prepare("SELECT status, COUNT(*) AS n, COALESCE(SUM(items_processed),0) AS items, COALESCE(SUM(api_calls),0) AS calls, COALESCE(SUM(api_errors),0) AS errs,
                                COALESCE(SUM(skipped_starts),0) AS skipped, MAX(peak_memory_bytes) AS mem
                         FROM job_runs WHERE finished_at >= ? AND finished_at < ? GROUP BY status");
    $st->execute([$f, $t]);
    foreach ($st->fetchAll() as $row) {
        $key = 'finished_' . $row['status'];
        if (isset($empty[$key])) {
            $empty[$key] = (int)$row['n'];
        }
        if ($row['status'] === 'success') {
            $empty['items'] += (int)$row['items']; // nur bestätigt erfolgreich verarbeitete Mengen
        }
        $empty['api_calls'] += (int)$row['calls'];
        $empty['api_errors'] += (int)$row['errs'];
        $empty['skipped_starts'] += (int)$row['skipped'];
        $empty['peak_memory_max'] = max((int)$empty['peak_memory_max'], (int)$row['mem']) ?: null;
    }
    $st = $pdo->prepare("SELECT duration_ms FROM job_runs WHERE finished_at >= ? AND finished_at < ? AND status IN ('success','failed') AND duration_ms IS NOT NULL ORDER BY duration_ms ASC");
    $st->execute([$f, $t]);
    $durations = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $empty['durations_n'] = count($durations);
    if ($durations) {
        $empty['duration_avg_ms'] = (int)round(array_sum($durations) / count($durations));
        $empty['duration_p95_ms'] = $durations[max(0, (int)ceil(0.95 * count($durations)) - 1)];
    }
    $st = $pdo->prepare("SELECT job_type, COUNT(*) AS n, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
                                COALESCE(SUM(items_processed),0) AS items, COALESCE(SUM(api_calls),0) AS calls, MAX(finished_at) AS last_finished,
                                MAX(CASE WHEN status='success' THEN finished_at END) AS last_success
                         FROM job_runs WHERE (started_at >= ? AND started_at < ?) OR (finished_at >= ? AND finished_at < ?) GROUP BY job_type");
    $st->execute([$f, $t, $f, $t]);
    foreach ($st->fetchAll() as $row) {
        $empty['by_type'][$row['job_type']] = $row;
    }
    $now = mon_utc(monitor_now());
    $empty['active_now'] = (int)$pdo->query("SELECT COUNT(*) FROM job_runs WHERE status = 'running'")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM job_runs WHERE status = 'unknown' AND error_category = 'heartbeat_stale' AND started_at >= ?");
    $st->execute([$f]);
    $empty['unconfirmed_now'] = (int)$st->fetchColumn();
    // Parallelität aus Start-/Endereignissen (belegbar, weil beide Zeitpunkte gespeichert sind)
    $st = $pdo->prepare("SELECT started_at, COALESCE(finished_at, heartbeat_at) AS ended FROM job_runs WHERE started_at < ? AND COALESCE(finished_at, heartbeat_at) >= ?");
    $st->execute([$t, $f]);
    $events = [];
    foreach ($st->fetchAll() as $row) {
        $events[] = [max($from, mon_ts($row['started_at']) ?? $from), 1];
        $events[] = [min($to, (mon_ts($row['ended']) ?? $to) + 1), -1];
    }
    if ($events) {
        usort($events, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $cur = 0; $max = 0;
        foreach ($events as $e) {
            $cur += $e[1];
            $max = max($max, $cur);
        }
        $empty['concurrency_max'] = $max;
    }
    return $empty;
}

/** Instrumentierte PHP-Anfragen für [from, to) aus den Minutenzählern. */
function monitor_request_stats(int $from, int $to): array
{
    $out = ['requests' => 0, 'errors_5xx' => 0, 'avg_ms' => null, 'max_ms' => null, 'minutes' => 0, 'per_minute' => null];
    if (!monitor_available()) {
        return $out;
    }
    $st = db()->prepare('SELECT COUNT(*) AS minutes, COALESCE(SUM(requests),0) AS r, COALESCE(SUM(errors_5xx),0) AS e, COALESCE(SUM(sum_ms),0) AS s, MAX(max_ms) AS m FROM monitor_requests WHERE minute >= ? AND minute < ?');
    $st->execute([mon_utc($from), mon_utc($to)]);
    $r = $st->fetch();
    $out['minutes'] = (int)$r['minutes'];
    $out['requests'] = (int)$r['r'];
    $out['errors_5xx'] = (int)$r['e'];
    $out['avg_ms'] = $out['requests'] > 0 ? (int)round((int)$r['s'] / $out['requests']) : null;
    $out['max_ms'] = $r['m'] !== null ? (int)$r['m'] : null;
    $out['per_minute'] = $to > $from ? round($out['requests'] / max(1, ($to - $from) / 60), 2) : null;
    return $out;
}

/** Minutenreihe der Anfragen (für Diagramme), Lücken bleiben null. */
function monitor_request_series(int $from, int $to, int $bucketSeconds): array
{
    $series = [];
    for ($t = $from; $t < $to; $t += $bucketSeconds) {
        $series[$t] = null;
    }
    if (!monitor_available()) {
        return $series;
    }
    $st = db()->prepare('SELECT minute, requests, errors_5xx FROM monitor_requests WHERE minute >= ? AND minute < ?');
    $st->execute([mon_utc($from), mon_utc($to)]);
    foreach ($st->fetchAll() as $r) {
        $ts = mon_ts($r['minute']) ?? $from;
        $bucket = $from + intdiv($ts - $from, $bucketSeconds) * $bucketSeconds;
        if (array_key_exists($bucket, $series)) {
            $series[$bucket] = ($series[$bucket] ?? 0) + (int)$r['requests'];
        }
    }
    return $series;
}

/** Warteschlangen: wartende Synchronisationsschritte und fällige Einzüge. */
function monitor_queue(): array
{
    $q = ['sync_waiting' => 0, 'sync_oldest_age' => null, 'collections_due' => 0, 'collections_oldest_age' => null, 'incidents_open' => 0];
    try {
        $pdo = db();
        $r = $pdo->query("SELECT COUNT(*) AS n, MIN(started_at) AS oldest FROM sync_state WHERE status = 'running' AND (lock_until IS NULL OR lock_until < NOW())")->fetch();
        $q['sync_waiting'] = (int)$r['n'];
        $q['sync_oldest_age'] = $r['oldest'] ? max(0, time() - strtotime((string)$r['oldest'])) : null;
        $r = $pdo->query("SELECT COUNT(*) AS n, MIN(COALESCE(submit_not_before, scheduled_date)) AS oldest FROM payment_collections WHERE is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled' AND COALESCE(submit_not_before, scheduled_date) <= NOW()")->fetch();
        $q['collections_due'] = (int)$r['n'];
        $q['collections_oldest_age'] = $r['oldest'] ? max(0, time() - strtotime((string)$r['oldest'])) : null;
        if (monitor_available()) {
            $q['incidents_open'] = (int)$pdo->query("SELECT COUNT(*) FROM monitor_incidents WHERE status NOT IN ('resolved','completed')")->fetchColumn();
        }
    } catch (Throwable $e) {
        // Teilwerte
    }
    return $q;
}

// ---------------------------------------------------------------------------
// Öffentliche Sicht: Nutzerfunktionen, Gesamtzustand, Snapshot
// ---------------------------------------------------------------------------

/** Öffentliche Komponenten (Nutzerfunktionen) und ihre internen Bestandteile. */
function monitor_public_components(): array
{
    return [
        'web'   => ['name' => 'Webanwendung', 'internal' => ['php_app', 'web_ui'], 'critical' => true],
        'login' => ['name' => 'Anmeldung', 'internal' => ['php_app', 'db'], 'critical' => true],
        'sync'  => ['name' => 'Datenabgleich (Lexware Office)', 'internal' => ['cron', 'lexoffice'], 'critical' => false, 'activity' => ['lexoffice']],
        'debit' => ['name' => 'Einzugsverarbeitung', 'internal' => ['cron', 'stripe', 'db'], 'critical' => true, 'activity' => ['stripe'], 'pause_aware' => true],
        'mail'  => ['name' => 'E-Mail-Benachrichtigungen', 'internal' => ['mail'], 'critical' => false, 'activity' => ['mail']],
    ];
}

/** Zustand einer öffentlichen Komponente aus den internen Messungen (schlechtester Zustand zählt). */
function monitor_public_state(): array
{
    $cfg = monitor_config();
    $defs = monitor_public_components();
    $rank = ['ok' => 0, 'degraded' => 1, 'unknown' => 2, 'fail' => 3];
    $out = ['components' => [], 'overall' => 'unknown', 'overall_reason' => 'no_data', 'checked_at' => null, 'maintenance' => false];
    $overallRank = -1;
    $anyUnknownCritical = false;
    $paused = false;
    try {
        $paused = platform_collections_paused();
    } catch (Throwable $e) {
    }
    $oldest = null;
    foreach ($defs as $key => $def) {
        $state = 'ok';
        $reasons = [];
        $checked = null;
        foreach ($def['internal'] as $ic) {
            $latest = monitor_latest($ic);
            $s = monitor_component_state($latest, (int)($cfg['freshness'][$ic] ?? 900));
            $st = $s['state'];
            // "Keine Aktivität" einer Anbindung ist keine Störung (kein Job geplant, keine Aufrufe)
            if ($st === 'unknown' && in_array($ic, $def['activity'] ?? [], true) && ($s['reason'] === 'no_data') && !$s['stale']) {
                $st = 'ok';
                $reasons[] = 'no_activity';
            }
            if ($latest) {
                $ts = mon_ts($latest['checked_at']);
                $checked = $checked === null ? $ts : min($checked, $ts);
            }
            if ($rank[$st] > $rank[$state]) {
                $state = $st;
            }
            if ($s['reason'] && $st !== 'ok') {
                $reasons[] = $ic . ':' . $s['reason'];
            }
        }
        if (!empty($def['pause_aware']) && $paused && $state === 'ok') {
            $state = 'degraded';
            $reasons[] = 'platform_paused';
        }
        if ($state === 'unknown' && !empty($def['critical'])) {
            $anyUnknownCritical = true;
        }
        $out['components'][$key] = ['key' => $key, 'name' => $def['name'], 'state' => $state, 'label' => MONITOR_STATES[$state], 'checked_at' => $checked, 'reasons' => $reasons];
        // Gesamtzustand: Störung oder Einschränkung jeder Funktion zählt; "unbekannt" senkt den Gesamtzustand
        // nur bei kritischen Funktionen (nicht eingerichtete Zusatzfunktionen wie E-Mail bleiben sichtbar unbekannt).
        $effective = $state === 'unknown' && empty($def['critical']) ? 'ok' : $state;
        if ($rank[$effective] > $overallRank) {
            $overallRank = $rank[$effective];
            $out['overall'] = $effective;
        }
        if ($checked !== null) {
            $oldest = $oldest === null ? $checked : min($oldest, $checked);
        }
    }
    $out['checked_at'] = $oldest;
    $out['uncertain'] = $anyUnknownCritical;
    if (monitor_available()) {
        try {
            $st = db()->query("SELECT COUNT(*) FROM monitor_incidents WHERE kind = 'maintenance' AND published = 1 AND status = 'active'");
            $out['maintenance'] = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
        }
    }
    if ($out['maintenance'] && $out['overall'] === 'ok') {
        $out['overall'] = 'maintenance';
    }
    $out['overall_label'] = $out['overall'] === 'ok' && !$out['uncertain'] ? 'Alle Systeme betriebsbereit' : MONITOR_STATES[$out['overall']];
    return $out;
}

/** Tageszustand einer öffentlichen Komponente aus den Aggregaten ihrer Bestandteile (konservativ). */
function monitor_public_daily_history(string $publicKey, int $days): array
{
    $defs = monitor_public_components();
    $internal = $defs[$publicKey]['internal'] ?? [];
    $out = [];
    $today = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
    $rows = [];
    if (monitor_available() && $internal) {
        $in = implode(',', array_fill(0, count($internal), '?'));
        $st = db()->prepare("SELECT * FROM monitor_daily WHERE component IN ($in) AND day >= ?");
        $st->execute(array_merge($internal, [gmdate('Y-m-d', $today - ($days - 1) * 86400)]));
        foreach ($st->fetchAll() as $r) {
            $rows[$r['day']][$r['component']] = $r;
        }
    }
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', $today - $i * 86400);
        $state = 'nodata';
        if (!empty($rows[$day])) {
            $state = 'ok';
            foreach ($rows[$day] as $r) {
                $observed = (int)$r['t_ok'] + (int)$r['t_fail'];
                if ($observed === 0) {
                    $s = 'unknown';
                } elseif ((int)$r['t_fail'] >= 300) {
                    $s = 'fail';
                } elseif ((int)$r['t_fail'] > 0 || (int)$r['t_degraded'] >= 300) {
                    $s = 'degraded';
                } elseif ($observed < 43200) {
                    $s = 'unknown'; // weniger als die Hälfte des Tages beobachtet
                } else {
                    $s = 'ok';
                }
                $rank = ['ok' => 0, 'degraded' => 1, 'unknown' => 2, 'fail' => 3];
                if ($rank[$s] > $rank[$state === 'nodata' ? 'ok' : $state]) {
                    $state = $s;
                }
            }
        }
        $out[] = ['day' => $day, 'state' => $state];
    }
    return $out;
}

/** Verfügbarkeit einer öffentlichen Komponente über N Tage: konservativ das Minimum der Bestandteile. */
function monitor_public_availability(string $publicKey, int $days): array
{
    $cfg = monitor_config();
    $defs = monitor_public_components();
    $to = monitor_now();
    $from = $to - $days * 86400;
    $best = null;
    foreach ($defs[$publicKey]['internal'] ?? [] as $ic) {
        $u = monitor_uptime($ic, $from, $to);
        if ($best === null || ($u['availability_pct'] ?? 101) < ($best['availability_pct'] ?? 101)) {
            $best = $u;
        }
    }
    $firstTs = null;
    if (monitor_available()) {
        try {
            $st = db()->query('SELECT MIN(day) FROM monitor_daily');
            $firstDay = $st->fetchColumn();
            $st = db()->query('SELECT MIN(checked_at) FROM monitor_checks');
            $firstRaw = mon_ts($st->fetchColumn() ?: null);
            $firstTs = $firstDay ? strtotime($firstDay . ' 00:00:00 UTC') : $firstRaw;
            if ($firstRaw !== null && ($firstTs === null || $firstRaw < $firstTs)) {
                $firstTs = $firstRaw;
            }
        } catch (Throwable $e) {
        }
    }
    $coverage = $best ? (float)$best['coverage_pct'] : 0.0;
    $pct = $best && $coverage >= (float)$cfg['public_min_coverage_pct'] ? $best['availability_pct'] : null;
    return [
        'pct' => $pct,
        'coverage_pct' => round($coverage, 2),
        'observed_from' => mon_iso($firstTs !== null ? max($firstTs, $from) : null),
        'observed_to' => mon_iso($to),
        'label' => $pct === null ? 'Unvollständige Messdaten' : number_format((float)$pct, 2, ',', '.') . ' %',
        'min_coverage_pct' => (float)$cfg['public_min_coverage_pct'],
    ];
}

/** Öffentliche Statusdaten: ausschließlich positiv aufgezählte Felder (Abschnitt 8.4). */
function monitor_public_snapshot(): array
{
    $state = monitor_public_state();
    $now = monitor_now();
    $components = [];
    foreach ($state['components'] as $c) {
        $components[] = [
            'key' => $c['key'],
            'name' => $c['name'],
            'state' => $c['state'],
            'label' => MONITOR_STATES[$c['state']],
            'checked_at' => mon_iso($c['checked_at']),
        ];
    }
    $incidents = [];
    if (monitor_available()) {
        foreach (monitor_incidents_list(true, 30) as $inc) {
            if (in_array($inc['status'], ['resolved', 'completed'], true) && ($inc['ended_at'] === null || (mon_ts($inc['ended_at']) ?? 0) < $now - 90 * 86400)) {
                continue;
            }
            $updates = [];
            foreach (monitor_incident_updates($inc['id'], true) as $u) {
                $updates[] = ['phase' => $u['phase'], 'phase_label' => monitor_phase_label($u['phase']), 'text' => (string)$u['public_text'], 'at' => mon_iso(mon_ts($u['created_at']))];
            }
            $incidents[] = [
                'id' => $inc['id'], 'kind' => $inc['kind'], 'title' => $inc['title'], 'status' => $inc['status'],
                'status_label' => monitor_phase_label($inc['status']),
                'components' => array_values(array_intersect(json_decode((string)$inc['components'], true) ?: [], array_keys(monitor_public_components()))),
                'started_at' => mon_iso(mon_ts($inc['started_at'])), 'ended_at' => mon_iso(mon_ts($inc['ended_at'])),
                'scheduled_end_at' => mon_iso(mon_ts($inc['scheduled_end_at'])),
                'message' => (string)$inc['public_message'], 'updates' => $updates,
            ];
        }
    }
    $availability = [];
    $history = [];
    foreach (array_keys(monitor_public_components()) as $key) {
        $availability[$key] = ['days30' => monitor_public_availability($key, 30), 'days90' => monitor_public_availability($key, 90)];
        $history[$key] = monitor_public_daily_history($key, 90);
    }
    return [
        'schema' => 1,
        'generated_at' => mon_iso($now),
        'valid_for_seconds' => 900,
        'checked_at' => mon_iso($state['checked_at']),
        'overall' => ['state' => $state['overall'], 'label' => $state['overall_label'], 'uncertain' => (bool)$state['uncertain']],
        'components' => $components,
        'incidents' => $incidents,
        'availability' => $availability,
        'history' => $history,
        'notes' => [
            'Einzugsverarbeitung beschreibt den überwachten technischen Prozess, nicht den Erfolg einzelner Zahlungen oder Bearbeitungszeiten von Banken.',
            'Verfügbarkeit wird zeitgewichtet aus periodischen Prüfungen berechnet; nicht beobachtete Zeit erscheint als Keine Daten.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Störungen und Wartungen
// ---------------------------------------------------------------------------

function monitor_phase_label(string $phase): string
{
    return ['investigating' => 'Wird untersucht', 'identified' => 'Ursache identifiziert', 'monitoring' => 'Wird beobachtet', 'resolved' => 'Behoben',
            'scheduled' => 'Geplant', 'active' => 'Wartung läuft', 'completed' => 'Abgeschlossen'][$phase] ?? $phase;
}

/** Öffentliche Texte bereinigen: kein HTML, keine Steuerzeichen, begrenzte Länge. */
function monitor_incident_sanitize(?string $text, int $max = 2000): string
{
    $t = strip_tags(html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? $t;
    $t = preg_replace('/\s{3,}/u', "\n\n", trim($t)) ?? $t;
    return mb_substr($t, 0, $max);
}

function monitor_incidents_list(bool $publishedOnly, int $limit = 50): array
{
    if (!monitor_available()) {
        return [];
    }
    $sql = 'SELECT * FROM monitor_incidents' . ($publishedOnly ? ' WHERE published = 1' : '') . ' ORDER BY started_at DESC LIMIT ' . (int)$limit;
    return db()->query($sql)->fetchAll();
}

function monitor_incident_load(string $id): ?array
{
    if (!monitor_available()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM monitor_incidents WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function monitor_incident_updates(string $incidentId, bool $publicOnly): array
{
    if (!monitor_available()) {
        return [];
    }
    $st = db()->prepare('SELECT * FROM monitor_incident_updates WHERE incident_id = ?' . ($publicOnly ? " AND public_text IS NOT NULL AND public_text <> ''" : '') . ' ORDER BY created_at ASC');
    $st->execute([$incidentId]);
    return $st->fetchAll();
}

/** Störung oder Wartung anlegen (unveröffentlicht). */
function monitor_incident_create(array $ctx, array $in): string
{
    $kind = ($in['kind'] ?? 'incident') === 'maintenance' ? 'maintenance' : 'incident';
    $title = monitor_incident_sanitize($in['title'] ?? '', 160);
    if ($title === '') {
        throw new RuntimeException('Bitte einen Titel angeben.');
    }
    $components = array_values(array_intersect((array)($in['components'] ?? []), array_keys(monitor_public_components())));
    $status = $kind === 'maintenance' ? 'scheduled' : 'investigating';
    $startedAt = !empty($in['started_at']) ? strtotime((string)$in['started_at']) : monitor_now();
    if ($startedAt === false) {
        throw new RuntimeException('Der Beginn ist ungültig.');
    }
    $endAt = !empty($in['scheduled_end_at']) ? strtotime((string)$in['scheduled_end_at']) : null;
    $id = uuid4();
    $now = mon_utc(monitor_now());
    db()->prepare(
        'INSERT INTO monitor_incidents (id, kind, title, status, components, started_at, scheduled_end_at, public_message, internal_notes, published, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([$id, $kind, $title, $status, json_encode($components), mon_utc((int)$startedAt), $endAt ? mon_utc((int)$endAt) : null,
        monitor_incident_sanitize($in['public_message'] ?? ''), mb_substr(trim((string)($in['internal_notes'] ?? '')), 0, 5000), $ctx['user_id'] ?? null, $now, $now]);
    audit_log(null, $ctx, 'incident_created', 'incident', $id, ['kind' => $kind, 'title' => $title]);
    return $id;
}

/** Statusverlauf ergänzen (Phase, öffentlicher Text, interne Notiz); schließt bei Behoben/Abgeschlossen. */
function monitor_incident_update(array $ctx, string $id, array $in): void
{
    $inc = monitor_incident_load($id);
    if (!$inc) {
        throw new RuntimeException('Störung nicht gefunden.');
    }
    $allowed = $inc['kind'] === 'maintenance' ? ['scheduled', 'active', 'completed'] : ['investigating', 'identified', 'monitoring', 'resolved'];
    $phase = (string)($in['phase'] ?? $inc['status']);
    if (!in_array($phase, $allowed, true)) {
        throw new RuntimeException('Ungültige Phase.');
    }
    $publicText = monitor_incident_sanitize($in['public_text'] ?? '');
    $internal = mb_substr(trim((string)($in['internal_note'] ?? '')), 0, 5000);
    $now = mon_utc(monitor_now());
    $pdo = db();
    $pdo->prepare('INSERT INTO monitor_incident_updates (id, incident_id, phase, public_text, internal_note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([uuid4(), $id, $phase, $publicText !== '' ? $publicText : null, $internal !== '' ? $internal : null, $ctx['user_id'] ?? null, $now]);
    $ended = in_array($phase, ['resolved', 'completed'], true) ? $now : null;
    $pdo->prepare('UPDATE monitor_incidents SET status = ?, ended_at = COALESCE(?, ended_at), public_message = CASE WHEN ? <> \'\' THEN ? ELSE public_message END, updated_at = ? WHERE id = ?')
        ->execute([$phase, $ended, $publicText, $publicText, $now, $id]);
    audit_log(null, $ctx, 'incident_updated', 'incident', $id, ['phase' => $phase, 'public' => $publicText !== '']);
}

/** Veröffentlichen oder zurückziehen (Aufrufer prüft Berechtigung und frische 2FA). */
function monitor_incident_publish(array $ctx, string $id, bool $publish): void
{
    $inc = monitor_incident_load($id);
    if (!$inc) {
        throw new RuntimeException('Störung nicht gefunden.');
    }
    $now = mon_utc(monitor_now());
    db()->prepare('UPDATE monitor_incidents SET published = ?, published_at = CASE WHEN ? = 1 THEN ? ELSE published_at END, updated_at = ? WHERE id = ?')
        ->execute([$publish ? 1 : 0, $publish ? 1 : 0, $now, $now, $id]);
    audit_log(null, $ctx, $publish ? 'incident_published' : 'incident_unpublished', 'incident', $id, ['title' => $inc['title']]);
}

/** Darf dieser Plattformadministrator Überwachungseinstellungen ändern und Meldungen veröffentlichen? */
function monitor_can_edit(array $ctx): bool
{
    if (!(int)($ctx['is_superadmin'] ?? 0)) {
        return false;
    }
    $editors = monitor_config()['editors'];
    return !$editors || in_array(mb_strtolower((string)($ctx['email'] ?? '')), $editors, true);
}

// ---------------------------------------------------------------------------
// Alarmierung
// ---------------------------------------------------------------------------

/**
 * Bestätigte Störungen kritischer Komponenten melden (nach N aufeinanderfolgenden Fehlprüfungen)
 * und nach M erfolgreichen Prüfungen entwarnen. Zusammengefasst je Komponente, ohne Mailflut.
 */
function monitor_alerts_evaluate(): array
{
    $cfg = monitor_config();
    $out = [];
    if (!monitor_available()) {
        return $out;
    }
    $pdo = db();
    foreach (['php_app', 'db', 'web_ui', 'cron'] as $c) {
        $st = $pdo->prepare('SELECT status FROM monitor_checks WHERE component = ? ORDER BY checked_at DESC, id DESC LIMIT ?');
        $st->bindValue(1, $c);
        $st->bindValue(2, max((int)$cfg['alert_fail_streak'], (int)$cfg['alert_ok_streak']), PDO::PARAM_INT);
        $st->execute();
        $last = $st->fetchAll(PDO::FETCH_COLUMN);
        $open = monitor_mark_get('alert_open_' . $c) !== null;
        $failStreak = count($last) >= (int)$cfg['alert_fail_streak'] && count(array_filter(array_slice($last, 0, (int)$cfg['alert_fail_streak']), fn($s) => $s === 'fail')) === (int)$cfg['alert_fail_streak'];
        $okStreak = count($last) >= (int)$cfg['alert_ok_streak'] && count(array_filter(array_slice($last, 0, (int)$cfg['alert_ok_streak']), fn($s) => $s === 'ok')) === (int)$cfg['alert_ok_streak'];
        if (!$open && $failStreak) {
            monitor_mark('alert_open_' . $c, mon_utc(monitor_now()));
            monitor_alert_send($c, true);
            monitor_event('alert', 'fail', null, $c, 'internal', 60);
            $out[] = ['component' => $c, 'action' => 'opened'];
        } elseif ($open && $okStreak) {
            monitor_mark('alert_open_' . $c, null);
            monitor_alert_send($c, false);
            monitor_event('alert', 'ok', null, $c, 'internal', 60);
            $out[] = ['component' => $c, 'action' => 'closed'];
        }
    }
    return $out;
}

/** Alarm oder Entwarnung an die konfigurierten Plattformadministratoren (nur bei aktivem Mailversand). */
function monitor_alert_send(string $component, bool $opened): void
{
    $cfg = monitor_config();
    if (!$cfg['alert_emails']) {
        return; // Nicht eingerichtet; wird im Adminbereich so angezeigt
    }
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled()) {
        return; // Ein ausgefallener Mailversand kann nicht über sich selbst alarmieren (unabhängiger Kanal nicht aktiv)
    }
    $defs = monitor_component_defs();
    $name = $defs[$component]['name'] ?? $component;
    $tpl = mail_tpl_security($opened ? 'Störung: ' . $name : 'Entwarnung: ' . $name, [
        $opened
            ? sprintf('Die Komponente "%s" hat %d aufeinanderfolgende Prüfungen nicht bestanden (Stand %s).', $name, (int)$cfg['alert_fail_streak'], date('d.m.Y H:i:s T'))
            : sprintf('Die Komponente "%s" hat wieder %d aufeinanderfolgende Prüfungen bestanden (Stand %s).', $name, (int)$cfg['alert_ok_streak'], date('d.m.Y H:i:s T')),
        'Details im Adminbereich unter System. Diese Meldung wird je Komponente nur einmal je Störung versendet.',
    ], admin_base_url() !== '' ? admin_base_url() . '/admin-system.php' : app_base_url() . '/admin-system.php', 'System öffnen');
    foreach ($cfg['alert_emails'] as $to) {
        mail_send((string)$to, $tpl['subject'], $tpl['text'], $tpl['html']);
    }
}

// ---------------------------------------------------------------------------
// Veröffentlichung des Snapshots (Statusseite)
// ---------------------------------------------------------------------------

/**
 * Snapshot an die konfigurierten Ziele übertragen. Ein älterer Snapshot überschreibt nie einen neueren.
 * config status_publish: ['file' => '/pfad/status.json', 'github' => ['owner','repo','path','branch','token']]
 */
function status_publish(array $snapshot): array
{
    $cfg = monitor_config()['publish'];
    $out = [];
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return ['error' => 'encode'];
    }
    $newTs = mon_ts(str_replace(['T', 'Z'], [' ', ''], (string)$snapshot['generated_at'])) ?? 0;

    if (!empty($cfg['file'])) {
        $path = (string)$cfg['file'];
        try {
            $existing = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            $oldTs = is_array($existing) ? (mon_ts(str_replace(['T', 'Z'], [' ', ''], (string)($existing['generated_at'] ?? ''))) ?? 0) : 0;
            if ($oldTs > $newTs) {
                $out['file'] = 'skipped_older';
            } else {
                $tmp = $path . '.tmp';
                if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
                    throw new RuntimeException('write');
                }
                $out['file'] = 'ok';
                monitor_mark('publish_file_last_ok_at', mon_utc(monitor_now()));
            }
        } catch (Throwable $e) {
            $out['file'] = 'error';
            monitor_mark('publish_file_last_error_at', mon_utc(monitor_now()));
        }
    }
    if (!empty($cfg['github']) && is_array($cfg['github'])) {
        $out['github'] = _status_publish_github($cfg['github'], $json, $newTs);
    }
    return $out;
}

/** Datei im Status-Repository über die GitHub-Contents-API aktualisieren (Token nur in config.php). */
function _status_publish_github(array $g, string $json, int $newTs): string
{
    $owner = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($g['owner'] ?? ''));
    $repo = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($g['repo'] ?? ''));
    $path = ltrim((string)($g['path'] ?? 'status.json'), '/');
    $branch = (string)($g['branch'] ?? 'main');
    $token = (string)($g['token'] ?? '');
    if ($owner === '' || $repo === '' || $token === '') {
        return 'not_configured';
    }
    $api = sprintf('https://api.github.com/repos/%s/%s/contents/%s', $owner, $repo, rawurlencode($path));
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'User-Agent: SmartEinzug-Status', 'X-GitHub-Api-Version: 2022-11-28'];
    $call = function (string $method, string $url, ?array $body) use ($headers): array {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true];
        if ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($res) ? (json_decode($res, true) ?: []) : []];
    };
    try {
        [$code, $data] = $call('GET', $api . '?ref=' . rawurlencode($branch), null);
        $sha = null;
        if ($code === 200 && !empty($data['content'])) {
            $sha = $data['sha'] ?? null;
            $existing = json_decode((string)base64_decode(str_replace("\n", '', (string)$data['content'])), true);
            $oldTs = is_array($existing) ? (mon_ts(str_replace(['T', 'Z'], [' ', ''], (string)($existing['generated_at'] ?? ''))) ?? 0) : 0;
            if ($oldTs > $newTs) {
                return 'skipped_older';
            }
        } elseif ($code !== 404) {
            monitor_mark('publish_github_last_error_at', mon_utc(monitor_now()));
            return 'error_read_' . $code;
        }
        $body = ['message' => 'Status aktualisiert ' . gmdate('Y-m-d H:i:s') . ' UTC', 'content' => base64_encode($json), 'branch' => $branch];
        if ($sha) {
            $body['sha'] = $sha;
        }
        [$code] = $call('PUT', $api, $body);
        if ($code === 200 || $code === 201) {
            monitor_mark('publish_github_last_ok_at', mon_utc(monitor_now()));
            return 'ok';
        }
        monitor_mark('publish_github_last_error_at', mon_utc(monitor_now()));
        return 'error_write_' . $code;
    } catch (Throwable $e) {
        monitor_mark('publish_github_last_error_at', mon_utc(monitor_now()));
        return 'error';
    }
}
