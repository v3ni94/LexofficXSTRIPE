<?php
/**
 * Strukturiertes Logging (JSON je Zeile) mit Correlation-ID.
 *
 * Felder: timestamp (UTC), level, service (web|worker|scheduler|cron|cli), company_id, user_id, job_id,
 * correlation_id, duration_ms, status, error_code, message plus frei wählbare Zusatzfelder.
 * Ziel: config log.target = 'stderr' (Docker: docker logs) oder 'file' (app/storage/logs/app-YYYY-MM-DD.log).
 * Tokens, Passwörter und Schlüssel werden vor dem Schreiben maskiert; personenbezogene Daten gehören
 * nicht in Logzeilen (nur Kennungen).
 */
declare(strict_types=1);

function log_service(): string
{
    if (defined('LOG_SERVICE')) {
        return (string)LOG_SERVICE;
    }
    return PHP_SAPI === 'cli' ? 'cli' : 'web';
}

/** Correlation-ID der aktuellen Verarbeitung (Webanfrage, Job oder CLI-Lauf). */
function correlation_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    // Eine Correlation-ID aus dem Anfrage-Header wird nur übernommen, wenn die Anfrage über einen
    // konfigurierten vertrauenswürdigen Proxy kam (app/bootstrap.php setzt TRUSTED_PROXY_HOP); sonst
    // könnte jeder Client fremde Vorgänge in Audit und Logs mit seinen Aktionen korrelieren lassen.
    $h = (string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? '');
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['TRUSTED_PROXY_HOP']) && preg_match('/^[0-9a-f-]{36}$/', $h)) {
        $id = $h;
    } else {
        $id = uuid4();
    }
    return $id;
}

/** Correlation-ID explizit setzen (Worker übernimmt die ID des Jobs). */
function correlation_id_set(?string $id): void
{
    // statische Variable kann nicht direkt gesetzt werden: über globalen Override
    $GLOBALS['__correlation_override'] = $id;
}

function correlation_current(): string
{
    $o = $GLOBALS['__correlation_override'] ?? null;
    return is_string($o) && $o !== '' ? $o : correlation_id();
}

/** Geheimnisse in freiem Text maskieren (Token, Schlüssel, Bearer, lange Hex-Werte). */
function log_sanitize(string $text): string
{
    $t = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[maskiert]', $text) ?? $text;
    $t = preg_replace('/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]+/', '[stripe-schluessel]', $t) ?? $t;
    // Webhook-Signaturschlüssel haben kein live/test-Segment (whsec_...)
    $t = preg_replace('/\bwhsec_[A-Za-z0-9]+/', '[stripe-webhook-schluessel]', $t) ?? $t;
    $t = preg_replace('/\b[0-9a-f]{40,}\b/i', '[hex]', $t) ?? $t;
    // Lexware-Office-Schlüssel sind UUIDs: nur im Kontext einer Schlüsselangabe maskieren (sonst würden
    // auch Datensatzkennungen unkenntlich).
    $t = preg_replace('/\b(api[-_ ]?key|apikey|x-api-key|authorization)(["\']?\s*[:=]\s*)["\']?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '$1$2[api-schluessel]', $t) ?? $t;
    $t = preg_replace('/(pass(word|wort)?|token|secret|schl(ü|ue)ssel)(["\']?\s*[:=]\s*)["\']?[^\s"\',;]+/i', '$1$4[maskiert]', $t) ?? $t;
    return mb_substr($t, 0, 2000);
}

/** Eine Logzeile schreiben. $ctx darf company_id, user_id, job_id, duration_ms, status, error_code enthalten. */
function app_log(string $level, string $message, array $ctx = []): void
{
    static $target = null;
    static $cfg = [];
    try {
        if ($target === null) {
            $cfg = (array)config('log', []);
            $target = (string)($cfg['target'] ?? (PHP_SAPI === 'cli' ? 'stderr' : 'file'));
        }
        $row = [
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'          => in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true) ? $level : 'info',
            'service'        => log_service(),
            'company_id'     => $ctx['company_id'] ?? ($ctx['tenant_id'] ?? null),
            'user_id'        => $ctx['user_id'] ?? null,
            'job_id'         => $ctx['job_id'] ?? null,
            'correlation_id' => $ctx['correlation_id'] ?? correlation_current(),
            'duration_ms'    => isset($ctx['duration_ms']) ? (int)$ctx['duration_ms'] : null,
            'status'         => $ctx['status'] ?? null,
            'error_code'     => $ctx['error_code'] ?? null,
            'message'        => log_sanitize($message),
        ];
        foreach ($ctx as $k => $v) {
            if (!array_key_exists($k, $row) && $k !== 'tenant_id' && is_scalar($v)) {
                $row[$k] = is_string($v) ? log_sanitize($v) : $v;
            }
        }
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if ($target === 'stderr') {
            fwrite(STDERR, $line);
        } elseif ($target === 'file') {
            $dir = rtrim((string)($cfg['dir'] ?? ''), '/') ?: (function_exists('storage_dir') ? storage_dir() . '/logs' : APP_ROOT . '/app/storage/logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } elseif ($target === 'error_log') {
            error_log(rtrim($line));
        }
    } catch (Throwable $e) {
        // Logging darf die Anwendung nie stören
    }
}
