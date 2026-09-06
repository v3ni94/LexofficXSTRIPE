<?php
/**
 * Zentrale Job-Queue (Auftrag III, Abschnitte 26 bis 37).
 *
 * MariaDB hält die Warteschlange dauerhaft (Tabelle jobs, Reservierung mit SELECT ... FOR UPDATE SKIP LOCKED),
 * jeder Verarbeitungsversuch wird in der vorhandenen Tabelle job_runs erfasst. Redis ergänzt optional
 * (Sperren, Ratenbegrenzung), ersetzt aber nichts: ohne Redis läuft alles über die Datenbank.
 *
 * Grundsätze:
 *  - Ein fachlicher Auftrag steht über dedupe_key nur einmal gleichzeitig in der Warteschlange
 *    (z.B. sync:<firma>); der Schlüssel wird beim Abschluss gelöscht, damit spätere Aufträge möglich sind.
 *  - Wiederholung mit gestaffeltem Backoff (1, 5, 15, 60 Minuten), danach failed (Dead Letter) mit
 *    Admin-Aktionen erneut versuchen, abbrechen, dauerhaft schließen.
 *  - Ein Job darf keine Geheimnisse in payload oder last_error tragen; Fehlertexte werden bereinigt.
 *  - Die Queue ist über Feature-Flag 'queue' aktivierbar (global oder je Firma); auf dem Webhosting bleibt
 *    sie ausgeschaltet und der bestehende Cron arbeitet wie bisher.
 */
declare(strict_types=1);

require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/redis.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/log.php';

const QUEUE_PRIORITY      = ['high' => 10, 'normal' => 50, 'low' => 90];
const QUEUE_BACKOFF       = [60, 300, 900, 3600];            // Sekunden nach dem 1., 2., 3., 4. Fehlversuch
const QUEUE_ACTIVE_STATES = ['queued', 'processing', 'retry'];
const QUEUE_STATE_LABELS  = ['queued' => 'Wartend', 'processing' => 'In Bearbeitung', 'retry' => 'Erneuter Versuch geplant', 'completed' => 'Abgeschlossen',
                             'partially_completed' => 'Teilweise abgeschlossen', 'failed' => 'Fehlgeschlagen', 'cancelled' => 'Abgebrochen'];

class JobRetryException extends RuntimeException {}      // technischer Fehler, erneuter Versuch sinnvoll
class JobFailedException extends RuntimeException {}     // fachlicher Fehler, kein erneuter Versuch
class JobRequeueException extends RuntimeException {}    // Zeitbudget des Versuchs erreicht, sofort weiter (kein Fehlversuch)
class CircuitOpenException extends RuntimeException {}   // externe Anbindung vorübergehend gesperrt

function queue_available(): bool
{
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM jobs LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

/** Queue für eine Firma (oder global) aktiv? Ohne Migration 018 immer aus. */
function queue_enabled(?string $tenantId = null): bool
{
    return queue_available() && feature_enabled('queue', $tenantId);
}

/** Ist die Queue global oder für mindestens eine Firma aktiv (für Cron und Scheduler)? */
function queue_any_enabled(): bool
{
    if (!queue_available()) {
        return false;
    }
    $global = (array)config('features', []);
    $g = $global['queue'] ?? false;
    if ($g === true || (is_array($g) && $g)) {
        return true;
    }
    try {
        return (int)db()->query("SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL AND feature_flags LIKE '%\"queue\"%'")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function queue_now(): int
{
    return time();
}

function queue_utc(int $ts): string
{
    return gmdate('Y-m-d H:i:s', $ts);
}

function queue_ts(?string $utc): ?int
{
    return mon_ts($utc);
}

/** Fehlertext für die Speicherung bereinigen (Geheimnisse, Länge). */
function queue_sanitize_error(string $text): string
{
    return mb_substr(log_sanitize(trim(preg_replace('/\s+/', ' ', $text) ?? $text)), 0, 255);
}

/** Standardwerte je Jobtyp: maximale Versuche und Heartbeat-Toleranz in Sekunden. */
function queue_type_defaults(string $type): array
{
    $map = [
        'sync_run'         => ['max_attempts' => 6, 'heartbeat_ttl' => 300],
        'collections_due'  => ['max_attempts' => 5, 'heartbeat_ttl' => 600],
        'unclear_attempts' => ['max_attempts' => 5, 'heartbeat_ttl' => 300],
        'mail'             => ['max_attempts' => 3, 'heartbeat_ttl' => 120],
        'monitor_collect'  => ['max_attempts' => 2, 'heartbeat_ttl' => 120],
        'maintenance'      => ['max_attempts' => 2, 'heartbeat_ttl' => 300],
        'alerts'           => ['max_attempts' => 2, 'heartbeat_ttl' => 1800],
        'mandate_reminders'=> ['max_attempts' => 3, 'heartbeat_ttl' => 1800],
    ];
    return $map[$type] ?? ['max_attempts' => 5, 'heartbeat_ttl' => 300];
}

/**
 * Job einreihen. $opts: tenant_id, user_id, priority (high|normal|low), available_at (Unixzeit),
 * dedupe_key, max_attempts, correlation_id.
 * Liefert ['id' => ..., 'created' => bool]. Existiert bereits ein aktiver Job mit demselben dedupe_key,
 * wird dessen Kennung mit created=false geliefert (kein Doppelstart).
 */
function queue_push(string $type, array $payload = [], array $opts = []): array
{
    if (!queue_available()) {
        throw new RuntimeException('Die Warteschlange ist nicht verfügbar (Migration 018 fehlt).');
    }
    $id = uuid4();
    $defaults = queue_type_defaults($type);
    $priority = QUEUE_PRIORITY[(string)($opts['priority'] ?? 'normal')] ?? QUEUE_PRIORITY['normal'];
    $dedupe = isset($opts['dedupe_key']) && $opts['dedupe_key'] !== '' ? mb_substr((string)$opts['dedupe_key'], 0, 120) : null;
    if ($dedupe !== null) {
        $payload['_dedupe_key'] = $dedupe; // für Wiederaufnahme nach failed
    }
    $correlation = (string)($opts['correlation_id'] ?? correlation_current());
    try {
        db()->prepare(
            'INSERT INTO jobs (id, tenant_id, user_id, type, priority, payload, status, available_at, created_at, attempts, max_attempts, correlation_id, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $id, $opts['tenant_id'] ?? null, $opts['user_id'] ?? null, mb_substr($type, 0, 40), $priority,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'queued',
            queue_utc((int)($opts['available_at'] ?? queue_now())), queue_utc(queue_now()),
            (int)($opts['max_attempts'] ?? $defaults['max_attempts']), $correlation, $dedupe,
        ]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000' && $dedupe !== null) {
            $st = db()->prepare('SELECT id FROM jobs WHERE dedupe_key = ? LIMIT 1');
            $st->execute([$dedupe]);
            $existing = (string)$st->fetchColumn();
            app_log('info', 'Job nicht erneut eingereiht, Auftrag bereits aktiv', ['job_id' => $existing, 'type' => $type, 'company_id' => $opts['tenant_id'] ?? null]);
            return ['id' => $existing, 'created' => false];
        }
        throw $e;
    }
    app_log('info', 'Job eingereiht', ['job_id' => $id, 'type' => $type, 'company_id' => $opts['tenant_id'] ?? null, 'user_id' => $opts['user_id'] ?? null, 'priority' => $priority, 'correlation_id' => $correlation]);
    return ['id' => $id, 'created' => true];
}

/** Job laden (ohne Mandantenfilter, nur für Worker und Plattformadministration). */
function queue_get(string $id): ?array
{
    if (!queue_available()) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $row['payload_data'] = json_decode((string)$row['payload'], true) ?: [];
    }
    return $row ?: null;
}

/**
 * Nächsten fälligen Job für diesen Worker reservieren (atomar, konkurrierende Worker überspringen
 * gesperrte Zeilen). Liefert die Jobzeile mit bereits erhöhtem Versuchszähler oder null.
 */
function queue_reserve(string $workerId, array $types): ?array
{
    if (!queue_available() || !$types) {
        return null;
    }
    $pdo = db();
    $in = implode(',', array_fill(0, count($types), '?'));
    $pdo->beginTransaction();
    try {
        $sql = "SELECT * FROM jobs WHERE status IN ('queued','retry') AND available_at <= ? AND type IN ($in)
                ORDER BY priority ASC, available_at ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED";
        try {
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([queue_utc(queue_now())], $types));
        } catch (PDOException $e) {
            // Ältere MariaDB ohne SKIP LOCKED: normale Zeilensperre
            $st = $pdo->prepare(str_replace(' SKIP LOCKED', '', $sql));
            $st->execute(array_merge([queue_utc(queue_now())], $types));
        }
        $job = $st->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }
        $now = queue_utc(queue_now());
        $pdo->prepare(
            "UPDATE jobs SET status = 'processing', locked_by = ?, locked_at = ?, heartbeat_at = ?, started_at = COALESCE(started_at, ?), attempts = attempts + 1, last_error = NULL
             WHERE id = ?"
        )->execute([$workerId, $now, $now, $now, $job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $job['status'] = 'processing';
    $job['locked_by'] = $workerId;
    $job['attempts'] = (int)$job['attempts'] + 1;
    $job['payload_data'] = json_decode((string)$job['payload'], true) ?: [];
    return $job;
}

/** Heartbeat und Fortschritt melden. false, wenn die Reservierung nicht mehr diesem Worker gehört. */
function queue_heartbeat(array $job, ?int $progress = null, ?string $text = null): bool
{
    $st = db()->prepare("UPDATE jobs SET heartbeat_at = ?, progress = COALESCE(?, progress), progress_text = COALESCE(?, progress_text) WHERE id = ? AND locked_by = ? AND status = 'processing'");
    $st->execute([queue_utc(queue_now()), $progress !== null ? max(0, min(100, $progress)) : null, $text !== null ? mb_substr($text, 0, 160) : null, $job['id'], $job['locked_by']]);
    return $st->rowCount() === 1;
}

/** Job erfolgreich (oder teilweise) abschließen. */
function queue_complete(array $job, string $status = 'completed', array $result = [], bool $prunePayload = false): void
{
    $status = in_array($status, ['completed', 'partially_completed', 'cancelled'], true) ? $status : 'completed';
    $st = db()->prepare(
        "UPDATE jobs SET status = ?, finished_at = ?, heartbeat_at = ?, progress = CASE WHEN ? = 'completed' THEN 100 ELSE progress END,
                result_json = ?, dedupe_key = NULL, locked_by = NULL" . ($prunePayload ? ", payload = '{}'" : '') . "
         WHERE id = ? AND locked_by = ?"
    );
    $now = queue_utc(queue_now());
    $st->execute([$status, $now, $now, $status, $result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $job['id'], $job['locked_by']]);
    app_log('info', 'Job abgeschlossen', ['job_id' => $job['id'], 'type' => $job['type'], 'company_id' => $job['tenant_id'], 'status' => $status, 'correlation_id' => $job['correlation_id'],
        'duration_ms' => isset($job['locked_at']) ? (queue_now() - (queue_ts($job['locked_at']) ?? queue_now())) * 1000 : null]);
}

/** Job ohne Fehlversuch sofort wieder einreihen (Fortsetzung nach Zeitbudget je Versuch). */
function queue_requeue(array $job, int $delaySeconds = 0, ?string $text = null): void
{
    // Fortsetzungen zählen nicht als Fehlversuche, sind aber begrenzt (Schutz vor Endlosschleifen)
    $payload = is_array($job['payload_data'] ?? null) ? $job['payload_data'] : (json_decode((string)($job['payload'] ?? ''), true) ?: []);
    $payload['_continuations'] = (int)($payload['_continuations'] ?? 0) + 1;
    $max = max(10, (int)(config('queue', [])['max_continuations'] ?? 500));
    if ($payload['_continuations'] > $max) {
        queue_fail($job, 'Zu viele Fortsetzungen (' . $payload['_continuations'] . '), Auftrag angehalten', 'too_many_continuations', false);
        return;
    }
    db()->prepare("UPDATE jobs SET status = 'queued', available_at = ?, locked_by = NULL, attempts = GREATEST(0, attempts - 1), payload = ?, progress_text = COALESCE(?, progress_text) WHERE id = ? AND locked_by = ?")
        ->execute([queue_utc(queue_now() + max(0, $delaySeconds)), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $text !== null ? mb_substr($text, 0, 160) : null, $job['id'], $job['locked_by']]);
}

/** Payload eines reservierten Jobs aktualisieren (z.B. Zwischenstand für Fortsetzungen). */
function queue_update_payload(array $job, array $payload): void
{
    db()->prepare('UPDATE jobs SET payload = ? WHERE id = ? AND locked_by = ?')
        ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $job['id'], $job['locked_by']]);
}

/** Inhalt eines Mail-Jobs auf Empfänger und Betreff reduzieren (Links und Texte nicht dauerhaft aufbewahren). */
function queue_prune_payload(string $id): void
{
    try {
        $job = queue_get($id);
        if ($job && $job['type'] === 'mail') {
            $p = $job['payload_data'];
            db()->prepare('UPDATE jobs SET payload = ? WHERE id = ?')->execute([json_encode(['to' => $p['to'] ?? null, 'subject' => $p['subject'] ?? null, '_pruned' => true], JSON_UNESCAPED_UNICODE), $id]);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Fehlversuch verbuchen: erneuter Versuch mit gestaffeltem Backoff oder failed (Dead Letter).
 * Liefert den neuen Status.
 */
function queue_fail(array $job, string $error, string $category, bool $retryable = true): string
{
    $attempts = (int)$job['attempts'];
    $max = (int)$job['max_attempts'];
    $clean = queue_sanitize_error($category . ': ' . $error);
    $now = queue_utc(queue_now());
    if ($retryable && $attempts < $max) {
        $delay = QUEUE_BACKOFF[min(count(QUEUE_BACKOFF) - 1, max(0, $attempts - 1))];
        db()->prepare("UPDATE jobs SET status = 'retry', available_at = ?, locked_by = NULL, last_error = ?, heartbeat_at = ? WHERE id = ? AND locked_by = ?")
            ->execute([queue_utc(queue_now() + $delay), $clean, $now, $job['id'], $job['locked_by']]);
        app_log('warning', 'Job fehlgeschlagen, erneuter Versuch geplant', ['job_id' => $job['id'], 'type' => $job['type'], 'company_id' => $job['tenant_id'], 'error_code' => $category, 'attempt' => $attempts, 'retry_in_s' => $delay, 'correlation_id' => $job['correlation_id']]);
        monitor_event('queue_job_retry', 'degraded', null, $category, 'instrumented', 3600);
        return 'retry';
    }
    db()->prepare("UPDATE jobs SET status = 'failed', finished_at = ?, locked_by = NULL, last_error = ?, dedupe_key = NULL, heartbeat_at = ? WHERE id = ? AND locked_by = ?")
        ->execute([$now, $clean, $now, $job['id'], $job['locked_by']]);
    if (($job['type'] ?? '') === 'mail') {
        queue_prune_payload((string)$job['id']);
    }
    if (($job['type'] ?? '') === 'sync_run' && !empty($job['tenant_id'])) {
        // Synchronisation endgültig gescheitert: Lauf schließen, damit kein verwaister Zustand bleibt
        try {
            require_once __DIR__ . '/sync_state.php';
            db()->prepare("UPDATE sync_state SET status = 'error', lock_until = NULL, lock_owner = NULL, finished_at = NOW(), last_error = ? WHERE tenant_id = ? AND status = 'running'")
                ->execute([$clean, $job['tenant_id']]);
            sync_run_finish((string)$job['tenant_id'], 'failed', [], $clean, $category);
        } catch (Throwable $e) {
        }
    }
    app_log('error', 'Job endgültig fehlgeschlagen', ['job_id' => $job['id'], 'type' => $job['type'], 'company_id' => $job['tenant_id'], 'error_code' => $category, 'attempt' => $attempts, 'correlation_id' => $job['correlation_id']]);
    monitor_event('queue_job_failed', 'fail', null, $category, 'instrumented', 3600);
    audit_log($job['tenant_id'], null, 'job_failed', 'job', $job['id'], ['type' => $job['type'], 'category' => $category]);
    return 'failed';
}

/**
 * Jobs in Bearbeitung ohne Heartbeat (Worker abgestürzt) wieder freigeben. Zählt als Fehlversuch,
 * damit ein dauerhaft abstürzender Job nach max_attempts als failed endet. Liefert die Anzahl.
 */
function queue_release_stale(): int
{
    if (!queue_available()) {
        return 0;
    }
    $n = 0;
    $st = db()->prepare("SELECT * FROM jobs WHERE status = 'processing' AND heartbeat_at < ?");
    $st->execute([queue_utc(queue_now() - 60)]);
    foreach ($st->fetchAll() as $job) {
        $ttl = (int)queue_type_defaults((string)$job['type'])['heartbeat_ttl'];
        if ((queue_ts($job['heartbeat_at']) ?? 0) > queue_now() - $ttl) {
            continue;
        }
        queue_fail($job, 'Heartbeat des Workers abgelaufen, Ausführung unbestätigt', 'heartbeat_stale', true);
        try {
            db()->prepare("UPDATE job_runs SET status = 'unknown', error_category = 'heartbeat_stale' WHERE job_key = ? AND status = 'running'")->execute([$job['id']]);
        } catch (Throwable $e) {
        }
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Administration (Dead Letter)
// ---------------------------------------------------------------------------

function queue_cancel(string $id, ?array $actor): bool
{
    $st = db()->prepare("UPDATE jobs SET status = 'cancelled', finished_at = ?, dedupe_key = NULL, locked_by = NULL WHERE id = ? AND status IN ('queued','retry','failed')");
    $st->execute([queue_utc(queue_now()), $id]);
    $ok = $st->rowCount() === 1;
    if ($ok) {
        $job = queue_get($id);
        audit_log($job['tenant_id'] ?? null, $actor, 'job_cancelled', 'job', $id, ['type' => $job['type'] ?? null]);
        if (($job['type'] ?? '') === 'mail') {
            queue_prune_payload($id);
        }
        if (($job['type'] ?? '') === 'sync_run' && !empty($job['tenant_id'])) {
            try {
                require_once __DIR__ . '/sync_state.php';
                $s = sync_state_get((string)$job['tenant_id']);
                if ($s && $s['status'] === 'running') {
                    sync_state_cancel((string)$job['tenant_id'], $actor);
                }
            } catch (Throwable $e) {
            }
        }
    }
    return $ok;
}

/** Fehlgeschlagenen oder wartenden Job sofort erneut versuchen (Versuchszähler zurückgesetzt). */
function queue_retry_now(string $id, ?array $actor): array
{
    $job = queue_get($id);
    if (!$job || !in_array($job['status'], ['failed', 'retry', 'queued'], true)) {
        return ['ok' => false, 'message' => 'Dieser Job kann nicht erneut versucht werden.'];
    }
    $dedupe = $job['payload_data']['_dedupe_key'] ?? null;
    try {
        $st = db()->prepare("UPDATE jobs SET status = 'queued', available_at = ?, attempts = 0, last_error = NULL, finished_at = NULL, closed_at = NULL, locked_by = NULL, locked_at = NULL, heartbeat_at = NULL, dedupe_key = ? WHERE id = ? AND status IN ('failed','retry','queued')");
        $st->execute([queue_utc(queue_now()), $dedupe, $id]);
        if ($st->rowCount() !== 1) {
            return ['ok' => false, 'message' => 'Der Job wird gerade verarbeitet oder wurde inzwischen geändert.'];
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            return ['ok' => false, 'message' => 'Für diesen Auftrag ist bereits ein Job aktiv.'];
        }
        throw $e;
    }
    audit_log($job['tenant_id'], $actor, 'job_retried', 'job', $id, ['type' => $job['type']]);
    return ['ok' => true, 'message' => 'Job erneut eingereiht.'];
}

/** Fehlgeschlagenen Job dauerhaft schließen (bleibt sichtbar, keine Wiederholung). */
function queue_close(string $id, ?array $actor): bool
{
    $st = db()->prepare("UPDATE jobs SET closed_at = ?, dedupe_key = NULL WHERE id = ? AND status = 'failed' AND closed_at IS NULL");
    $st->execute([queue_utc(queue_now()), $id]);
    $ok = $st->rowCount() === 1;
    if ($ok) {
        $job = queue_get($id);
        audit_log($job['tenant_id'] ?? null, $actor, 'job_closed', 'job', $id, ['type' => $job['type'] ?? null]);
        queue_prune_payload($id);
    }
    return $ok;
}

/** Abgeschlossene und abgebrochene Jobs nach $days Tagen löschen (nur Queue-Daten). */
function queue_prune(int $days = 30): int
{
    if (!queue_available()) {
        return 0;
    }
    $cut = queue_utc(queue_now() - $days * 86400);
    $st = db()->prepare("DELETE FROM jobs WHERE (status IN ('completed','cancelled','partially_completed') AND finished_at < ?) OR (status = 'failed' AND closed_at IS NOT NULL AND closed_at < ?)");
    $st->execute([$cut, $cut]);
    return $st->rowCount();
}

// ---------------------------------------------------------------------------
// Auswertung für den Adminbereich
// ---------------------------------------------------------------------------

function queue_stats(int $from, int $to): array
{
    $out = ['now' => ['queued' => 0, 'processing' => 0, 'retry' => 0, 'failed' => 0], 'window' => ['finished' => 0, 'completed' => 0, 'failed' => 0, 'per_minute' => null, 'avg_ms' => null, 'p95_ms' => null, 'n' => 0],
            'oldest_waiting_age' => null, 'oldest_waiting_type' => null];
    if (!queue_available()) {
        return $out;
    }
    $pdo = db();
    foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM jobs WHERE status IN ('queued','processing','retry') OR (status = 'failed' AND closed_at IS NULL) GROUP BY status")->fetchAll() as $r) {
        $out['now'][$r['status']] = (int)$r['n'];
    }
    $st = $pdo->prepare("SELECT status, COUNT(*) AS n FROM jobs WHERE finished_at >= ? AND finished_at < ? GROUP BY status");
    $st->execute([queue_utc($from), queue_utc($to)]);
    foreach ($st->fetchAll() as $r) {
        $out['window']['finished'] += (int)$r['n'];
        if ($r['status'] === 'completed' || $r['status'] === 'partially_completed') {
            $out['window']['completed'] += (int)$r['n'];
        } elseif ($r['status'] === 'failed') {
            $out['window']['failed'] += (int)$r['n'];
        }
    }
    $out['window']['per_minute'] = $to > $from ? round($out['window']['finished'] / max(1, ($to - $from) / 60), 2) : null;
    $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, locked_at, finished_at) AS s FROM jobs WHERE finished_at >= ? AND finished_at < ? AND locked_at IS NOT NULL AND status IN ('completed','partially_completed','failed') ORDER BY s ASC");
    $st->execute([queue_utc($from), queue_utc($to)]);
    $d = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $out['window']['n'] = count($d);
    if ($d) {
        $out['window']['avg_ms'] = (int)round(array_sum($d) / count($d) * 1000);
        $out['window']['p95_ms'] = $d[max(0, (int)ceil(0.95 * count($d)) - 1)] * 1000;
    }
    $r = $pdo->prepare("SELECT type, available_at FROM jobs WHERE status IN ('queued','retry') AND available_at <= ? ORDER BY available_at ASC LIMIT 1");
    $r->execute([queue_utc(queue_now())]);
    if ($o = $r->fetch()) {
        $out['oldest_waiting_age'] = max(0, queue_now() - (queue_ts($o['available_at']) ?? queue_now()));
        $out['oldest_waiting_type'] = $o['type'];
    }
    return $out;
}

function queue_active_jobs(int $limit = 50): array
{
    if (!queue_available()) {
        return [];
    }
    $st = db()->prepare("SELECT j.*, o.name AS org_name FROM jobs j LEFT JOIN organizations o ON o.id = j.tenant_id
                         WHERE j.status IN ('processing','queued','retry') ORDER BY FIELD(j.status,'processing','queued','retry'), j.priority ASC, j.available_at ASC LIMIT " . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

function queue_failed_jobs(int $limit = 50, bool $includeClosed = false): array
{
    if (!queue_available()) {
        return [];
    }
    $st = db()->prepare("SELECT j.*, o.name AS org_name FROM jobs j LEFT JOIN organizations o ON o.id = j.tenant_id
                         WHERE j.status = 'failed'" . ($includeClosed ? '' : ' AND j.closed_at IS NULL') . " ORDER BY j.finished_at DESC LIMIT " . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

/** Aktiver Job eines Typs für eine Firma (für die Nutzeranzeige, mandantengefiltert). */
function queue_tenant_active(string $tenantId, string $type): ?array
{
    if (!queue_available()) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM jobs WHERE tenant_id = ? AND type = ? AND status IN ('queued','processing','retry') ORDER BY created_at DESC LIMIT 1");
    $st->execute([$tenantId, $type]);
    $row = $st->fetch();
    if ($row) {
        $row['payload_data'] = json_decode((string)$row['payload'], true) ?: [];
    }
    return $row ?: null;
}

function queue_type_label(string $type): string
{
    return ['sync_run' => 'Synchronisation Lexware Office', 'collections_due' => 'Einzugsverarbeitung', 'unclear_attempts' => 'Klärung unklarer Einzugsversuche', 'mail' => 'E-Mail-Versand',
            'monitor_collect' => 'Monitoring-Sammler', 'maintenance' => 'Wartungsaufgaben', 'alerts' => 'Alarmierung', 'mandate_reminders' => 'Mandats-Erinnerungen'][$type] ?? $type;
}

// ---------------------------------------------------------------------------
// Worker-Heartbeats
// ---------------------------------------------------------------------------

function worker_register(string $workerId, string $pool): void
{
    if (!queue_available()) {
        return;
    }
    $now = queue_utc(queue_now());
    db()->prepare(
        'INSERT INTO worker_heartbeats (worker_id, pool, hostname, pid, status, started_at, heartbeat_at, version)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE pool = VALUES(pool), hostname = VALUES(hostname), pid = VALUES(pid), status = VALUES(status), started_at = VALUES(started_at), heartbeat_at = VALUES(heartbeat_at), version = VALUES(version), jobs_done = 0, jobs_failed = 0'
    )->execute([$workerId, $pool, mb_substr((string)gethostname(), 0, 100), getmypid() ?: null, 'idle', $now, $now, defined('APP_VERSION') ? APP_VERSION : null]);
}

function worker_heartbeat(string $workerId, string $status, ?string $jobId, int $done, int $failed): void
{
    if (!queue_available()) {
        return;
    }
    try {
        db()->prepare('UPDATE worker_heartbeats SET status = ?, current_job_id = ?, heartbeat_at = ?, jobs_done = ?, jobs_failed = ? WHERE worker_id = ?')
            ->execute([$status, $jobId, queue_utc(queue_now()), $done, $failed, $workerId]);
    } catch (Throwable $e) {
    }
}

function worker_stop(string $workerId): void
{
    if (!queue_available()) {
        return;
    }
    try {
        db()->prepare("UPDATE worker_heartbeats SET status = 'stopped', current_job_id = NULL, heartbeat_at = ? WHERE worker_id = ?")->execute([queue_utc(queue_now()), $workerId]);
    } catch (Throwable $e) {
    }
}

/** Worker mit Lebendkennzeichen (Heartbeat jünger als $staleAfter Sekunden). */
function workers_list(int $staleAfter = 90): array
{
    if (!queue_available()) {
        return [];
    }
    $rows = db()->query('SELECT * FROM worker_heartbeats ORDER BY pool, started_at')->fetchAll();
    $now = queue_now();
    foreach ($rows as &$r) {
        $age = $now - (queue_ts($r['heartbeat_at']) ?? 0);
        $r['age'] = $age;
        $r['alive'] = $r['status'] !== 'stopped' && $age <= $staleAfter;
    }
    return $rows;
}

function workers_alive(?string $pool = null, int $staleAfter = 90): int
{
    return count(array_filter(workers_list($staleAfter), fn($w) => $w['alive'] && ($pool === null || $w['pool'] === $pool)));
}

/** Alte Worker-Einträge (gestoppt oder seit 24 h ohne Heartbeat) entfernen. */
function workers_prune(): void
{
    if (!queue_available()) {
        return;
    }
    db()->prepare("DELETE FROM worker_heartbeats WHERE heartbeat_at < ?")->execute([queue_utc(queue_now() - 86400)]);
}

// ---------------------------------------------------------------------------
// Circuit Breaker je externer Anbindung
// ---------------------------------------------------------------------------

function circuit_config(): array
{
    $c = (array)(config('queue', [])['circuit'] ?? []);
    return ['threshold' => max(2, (int)($c['threshold'] ?? 5)), 'open_seconds' => max(5, (int)($c['open_seconds'] ?? 300)), 'probe_seconds' => max(2, (int)($c['probe_seconds'] ?? 60))];
}

function circuit_available(): bool
{
    static $a = null;
    if ($a === null) {
        try {
            db()->query('SELECT 1 FROM api_circuits LIMIT 1');
            $a = true;
        } catch (Throwable $e) {
            $a = false;
        }
    }
    return $a;
}

function circuit_state(string $api): array
{
    $default = ['api' => $api, 'state' => 'closed', 'failures' => 0, 'opened_at' => null, 'next_probe_at' => null, 'last_failure_category' => null, 'last_failure_at' => null, 'last_success_at' => null];
    if (!circuit_available()) {
        return $default;
    }
    $st = db()->prepare('SELECT * FROM api_circuits WHERE api = ?');
    $st->execute([$api]);
    return $st->fetch() ?: $default;
}

/** Darf ein Aufruf an die Anbindung gehen? Bei offenem Kreis nach Ablauf genau ein Testaufruf (half_open). */
function circuit_allow(string $api): bool
{
    if (!circuit_available()) {
        return true;
    }
    $s = circuit_state($api);
    if ($s['state'] === 'closed') {
        return true;
    }
    $now = queue_now();
    if ((queue_ts($s['next_probe_at']) ?? 0) <= $now) {
        // genau ein Prozess gewinnt den Testaufruf
        $st = db()->prepare("UPDATE api_circuits SET state = 'half_open', next_probe_at = ?, updated_at = ? WHERE api = ? AND next_probe_at <= ?");
        $st->execute([queue_utc($now + circuit_config()['probe_seconds']), queue_utc($now), $api, queue_utc($now)]);
        return $st->rowCount() === 1;
    }
    return false;
}

function circuit_success(string $api): void
{
    if (!circuit_available()) {
        return;
    }
    $now = queue_utc(queue_now());
    $st = db()->prepare("SELECT state FROM api_circuits WHERE api = ?");
    $st->execute([$api]);
    $prev = $st->fetchColumn();
    db()->prepare(
        "INSERT INTO api_circuits (api, state, failures, opened_at, next_probe_at, last_success_at, updated_at) VALUES (?, 'closed', 0, NULL, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE state = 'closed', failures = 0, opened_at = NULL, next_probe_at = NULL, last_success_at = VALUES(last_success_at), updated_at = VALUES(updated_at)"
    )->execute([$api, $now, $now]);
    if ($prev && $prev !== 'closed') {
        app_log('info', 'Circuit geschlossen, Anbindung wieder erreichbar', ['api' => $api]);
        monitor_event('circuit_' . $api, 'ok', null, 'closed', 'instrumented', 3600);
    }
}

/** Technischen Fehler melden (Kategorien timeout, dns, tls, connection, http_5xx, throttled). Fachliche Fehler nicht melden. */
function circuit_failure(string $api, string $category): void
{
    if (!circuit_available() || !in_array($category, ['timeout', 'dns', 'tls', 'connection', 'http_5xx', 'throttled', 'other'], true)) {
        return;
    }
    $cfg = circuit_config();
    $now = queue_now();
    $pdo = db();
    $pdo->prepare("INSERT IGNORE INTO api_circuits (api, state, failures, updated_at) VALUES (?, 'closed', 0, ?)")->execute([$api, queue_utc($now)]);
    $pdo->prepare('UPDATE api_circuits SET failures = failures + 1, last_failure_category = ?, last_failure_at = ?, updated_at = ? WHERE api = ?')
        ->execute([$category, queue_utc($now), queue_utc($now), $api]);
    $s = circuit_state($api);
    if ($s['state'] === 'half_open' || (int)$s['failures'] >= $cfg['threshold']) {
        $pdo->prepare("UPDATE api_circuits SET state = 'open', opened_at = COALESCE(opened_at, ?), next_probe_at = ?, updated_at = ? WHERE api = ?")
            ->execute([queue_utc($now), queue_utc($now + $cfg['open_seconds']), queue_utc($now), $api]);
        if ($s['state'] !== 'open') {
            app_log('error', 'Circuit geöffnet, Aufrufe an Anbindung pausiert', ['api' => $api, 'error_code' => $category, 'failures' => (int)$s['failures']]);
            monitor_event('circuit_' . $api, 'fail', null, 'open', 'instrumented', 3600);
        }
    }
}

function circuit_label(string $state): string
{
    return ['closed' => 'Geschlossen (normal)', 'open' => 'Offen (Aufrufe pausiert)', 'half_open' => 'Testaufruf läuft'][$state] ?? $state;
}

/**
 * Zentrale Wartezeit vor einem Aufruf: Circuit prüfen und Ratenbegrenzung (Redis, falls vorhanden).
 *
 * $scope kennzeichnet das Kontingent des Anbieters, gegen das gezählt wird: bei Lexware Office und Stripe
 * ist das der API-Schlüssel der jeweiligen Firma (jede Firma nutzt ihr eigenes Konto, die Grenzen des
 * Anbieters gelten je Schlüssel). Ohne $scope wird je Anbieter insgesamt gezählt. $globalPerSecond ist eine
 * zusätzliche Obergrenze über alle Firmen (Schutz der eigenen Worker und Absenderadresse), 0 = keine.
 * Der Circuit Breaker bleibt je Anbieter ($api): er beschreibt Störungen des Anbieters, nicht einer Firma.
 */
function api_call_gate(string $api, int $perSecond, ?string $scope = null, int $globalPerSecond = 0): void
{
    if (!circuit_allow($api)) {
        throw new CircuitOpenException('Die Verbindung zu ' . ($api === 'lexoffice' ? 'Lexware Office' : ucfirst($api)) . ' ist derzeit pausiert (Circuit Breaker offen). Automatischer neuer Versuch folgt.');
    }
    // Schleife: nach dem Warten erneut ein Kontingent im neuen Sekundenfenster reservieren, bis die
    // Reservierung gelingt (0). Ein einmaliges Schlafen ohne erneute Prüfung würde das Limit im Zielfenster
    // nicht durchsetzen. Gesamtdeckel: Worker 30 s (danach Wiederholung des Jobs mit Backoff), Webanfragen
    // 2 s (danach Durchlass; der Client behandelt ein etwaiges 429 mit eigenen Wiederholungen).
    $cap = defined('IN_WORKER') ? 30000 : 2000;
    $spent = 0;
    $keys = [];
    if ($perSecond > 0) {
        $keys[] = [$scope !== null && $scope !== '' ? $api . ':' . $scope : $api, $perSecond];
    }
    if ($globalPerSecond > 0 && $scope !== null && $scope !== '') {
        $keys[] = [$api, $globalPerSecond];
    }
    while (true) {
        $wait = 0;
        foreach ($keys as [$key, $limit]) {
            $wait = max($wait, redis_rate_wait_ms($key, $limit));
        }
        if ($wait <= 0) {
            return;
        }
        if ($spent + $wait > $cap) {
            if (defined('IN_WORKER')) {
                throw new JobRetryException('Ratenbegrenzung für ' . $api . ' dauerhaft ausgeschöpft, Job wird später wiederholt.');
            }
            return;
        }
        usleep($wait * 1000);
        $spent += $wait;
    }
}

/** Kurze, nicht rückrechenbare Kennung eines API-Schlüssels als Kontingentschlüssel (nie der Schlüssel selbst). */
function api_scope_for_key(string $secret): string
{
    return substr(hash('sha256', $secret), 0, 16);
}
