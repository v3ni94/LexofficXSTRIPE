<?php
/**
 * Serverseitiger Zustand der Lexware-Office-Synchronisation.
 *
 * Der Cursor eines laufenden Syncs liegt in der Datenbank (Tabelle
 * sync_state), nicht mehr nur in der Browser-Session. Dadurch kann der Lauf
 * sowohl vom Browser (automatisches Nachladen auf der Rechnungsseite) als
 * auch vom Cron (cron.php) fortgesetzt werden: Der Nutzer muss die Seite
 * nicht geöffnet lassen. Ein Sperrzeitfenster verhindert, dass zwei Aufrufe
 * denselben Schritt doppelt ausführen.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit.php';

const SYNC_LOCK_SECONDS = 180;    // maximale Dauer eines Schritts (Zeitbudget plus Wiederholungen)
const SYNC_STALE_MINUTES = 30;    // danach gilt ein "running" ohne Fortschritt als abgebrochen

function sync_state_get(string $tenantId): ?array
{
    // Zeitvergleiche in der Datenbank rechnen (Datenbank- und PHP-Zeitzone können abweichen)
    $stmt = db()->prepare('SELECT s.*, TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) AS age_seconds,
                                  (s.lock_until IS NOT NULL AND s.lock_until > NOW()) AS lock_active
                           FROM sync_state s WHERE s.tenant_id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if ($row) {
        $row['cursor'] = $row['cursor_json'] ? json_decode($row['cursor_json'], true) : null;
        $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true) : null;
    }
    return $row ?: null;
}

/** Läuft für die Firma gerade eine Synchronisation (mit Fortschritt in den letzten Minuten)? */
function sync_state_is_running(?array $state): bool
{
    if (!$state || $state['status'] !== 'running') {
        return false;
    }
    if (isset($state['age_seconds'])) {
        return (int)$state['age_seconds'] < SYNC_STALE_MINUTES * 60;
    }
    if (empty($state['updated_at'])) {
        return true; // synthetischer Zustand ohne Zeitstempel (z.B. Fortschrittsberechnung)
    }
    $updated = new DateTimeImmutable($state['updated_at']);
    return $updated > (new DateTimeImmutable('now'))->modify('-' . SYNC_STALE_MINUTES . ' minutes');
}

/**
 * Neuen Lauf starten. Läuft bereits einer, wird kein zweiter angelegt: der bestehende
 * Zustand kommt mit already_running = true zurück und der Doppelstart wird gezählt.
 */
function sync_state_start(string $tenantId, ?array $actor): array
{
    $pdo = db();
    $state = sync_state_get($tenantId);
    if (sync_state_is_running($state)) {
        $pdo->prepare('UPDATE sync_state SET skipped_starts = skipped_starts + 1 WHERE tenant_id = ?')->execute([$tenantId]);
        monitor_event('sync_skipped_start', 'ok', null, 'double_start', 'instrumented', 60);
        audit_log($tenantId, $actor, 'sync_start_skipped', 'organization', $tenantId);
        $state['already_running'] = true;
        return $state;
    }
    $pdo->prepare(
        'INSERT INTO sync_state (tenant_id, status, cursor_json, requested_by_user_id, lock_until, lock_owner, skipped_starts, started_at, finished_at, last_error, result_json)
         VALUES (?, "running", NULL, ?, NULL, NULL, 0, NOW(), NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE status = "running", cursor_json = NULL, requested_by_user_id = VALUES(requested_by_user_id),
             lock_until = NULL, lock_owner = NULL, skipped_starts = 0, started_at = NOW(), finished_at = NULL, last_error = NULL, result_json = NULL'
    )->execute([$tenantId, $actor['user_id'] ?? null]);

    audit_log($tenantId, $actor, 'sync_requested', 'organization', $tenantId, [
        'requested_by_user_id' => $actor['user_id'] ?? null,
    ]);
    sync_run_open($tenantId, $actor);
    return sync_state_get($tenantId);
}

function sync_state_cancel(string $tenantId, ?array $actor): void
{
    db()->prepare(
        "UPDATE sync_state SET status = 'idle', cursor_json = NULL, lock_until = NULL, finished_at = NOW() WHERE tenant_id = ?"
    )->execute([$tenantId]);
    audit_log($tenantId, $actor, 'sync_cancelled', 'organization', $tenantId);
    sync_run_finish($tenantId, 'cancelled', [], 'Vom Benutzer abgebrochen', 'cancelled');
}

/**
 * Rechnungsquelle einer Firma für die Synchronisation (Schlüssel wird je
 * Schritt neu gelesen). Kapselt invoice_source_for_tenant() aus
 * app/invoice_source.php; der Testhaken lexsepa_lex_client_factory wird dort
 * ausgewertet.
 */
function sync_invoice_source(string $tenantId): InvoiceSource
{
    return invoice_source_for_tenant($tenantId);
}

/** Lexware-Office-Client für eine Firma (Verbindungsprüfung, Abgleich). Bevorzugt sync_invoice_source() verwenden. */
function sync_lex_client(string $tenantId): LexofficeClient
{
    // Testhaken: erlaubt automatisierten Tests, einen Ersatz-Client ohne echte API zu liefern.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['lexsepa_lex_client_factory']) && is_callable($GLOBALS['lexsepa_lex_client_factory'])) {
        return ($GLOBALS['lexsepa_lex_client_factory'])($tenantId);
    }
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();
    if (!$integration || !(int)$integration['lexoffice_connected']) {
        throw new RuntimeException('Lexware Office ist nicht verbunden.');
    }
    $apiKey = decrypt_value($integration['lexoffice_api_key_encrypted']);
    if (!$apiKey) {
        throw new RuntimeException('Lexware Office API-Key fehlt.');
    }
    return new LexofficeClient($apiKey);
}

/**
 * Einen Schritt des laufenden Syncs ausführen (mit Sperre).
 * @return array{done:bool,skipped:bool,result:array}
 */
function sync_state_step(string $tenantId, int $batchSize = 0): array
{
    $pdo = db();
    $empty = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

    // Sperre atomar holen: nur ein Aufrufer je Firma gleichzeitig. Der Inhaber wird mit
    // einer zufälligen Kennung vermerkt; nur er darf den Schritt später speichern.
    $owner = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        "UPDATE sync_state SET lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND), lock_owner = ?
         WHERE tenant_id = ? AND status = 'running' AND (lock_until IS NULL OR lock_until < NOW())"
    );
    $stmt->execute([SYNC_LOCK_SECONDS, $owner, $tenantId]);
    if ($stmt->rowCount() !== 1) {
        $state = sync_state_get($tenantId);
        return ['done' => !sync_state_is_running($state), 'skipped' => true, 'result' => $state['result'] ?? ($state['cursor']['result'] ?? $empty)];
    }

    $state = sync_state_get($tenantId);
    $runId = job_run_start('sync', 'sync:' . $tenantId . ':' . (string)($state['started_at'] ?? ''), $tenantId, PHP_SAPI === 'cli' ? 'cli' : (defined('CRON_CONTEXT') ? 'cron' : 'web'));
    try {
        $lex = sync_invoice_source($tenantId);
        $step = sync_invoices_step($tenantId, $lex, $state['cursor'], $batchSize);
        $mx = (array)($step['result']['metrics'] ?? []);
        $mPrev = (array)($state['result']['metrics'] ?? ($state['cursor']['result']['metrics'] ?? []));
        // Nur den Zuwachs dieses Schritts zählen (Metriken im Cursor sind kumuliert)
        $jobMetrics = [
            'items' => max(0, (int)($step['result']['synced'] ?? 0) - (int)($state['result']['synced'] ?? ($state['cursor']['result']['synced'] ?? 0))),
            'api_calls' => max(0, (int)($mx['api_calls'] ?? 0) - (int)($mPrev['api_calls'] ?? 0)),
            'throttle_ms' => max(0, (int)($mx['throttle_ms'] ?? 0) - (int)($mPrev['throttle_ms'] ?? 0)),
            'retries' => max(0, (int)($mx['retries'] ?? 0) - (int)($mPrev['retries'] ?? 0)),
        ];

        if ($step['done']) {
            $upd = $pdo->prepare(
                "UPDATE sync_state SET status = 'done', cursor_json = NULL, lock_until = NULL, lock_owner = NULL, finished_at = NOW(), last_step_at = NOW(), result_json = ?
                 WHERE tenant_id = ? AND lock_owner = ?"
            );
            $upd->execute([json_encode($step['result']), $tenantId, $owner]);
            if ($upd->rowCount() !== 1) {
                job_run_finish($runId, 'unknown', $jobMetrics, 'lock_lost');
                return _sync_lock_lost($tenantId, $owner, $step['result']);
            }
            $actor = $state['requested_by_user_id'] ? ['user_id' => $state['requested_by_user_id']] : null;
            audit_log($tenantId, $actor, 'sync_completed', 'organization', $tenantId, $step['result']);
            funnel_event_once($tenantId, 'first_sync', $state['requested_by_user_id']);
            sync_run_finish($tenantId, 'success', $step['result']);
        } else {
            // Fortschritt nur speichern, wenn die Sperre noch uns gehört. Wurde sie in der
            // Zwischenzeit von einem anderen Prozess übernommen (Zeitüberschreitung), wird der
            // Cursor dieses Schritts verworfen; die Datenänderungen selbst sind idempotente Upserts.
            $upd = $pdo->prepare(
                'UPDATE sync_state SET cursor_json = ?, lock_until = NULL, lock_owner = NULL, last_step_at = NOW(), result_json = ? WHERE tenant_id = ? AND lock_owner = ?'
            );
            $upd->execute([json_encode($step['cursor']), json_encode($step['result']), $tenantId, $owner]);
            if ($upd->rowCount() !== 1) {
                job_run_finish($runId, 'unknown', $jobMetrics, 'lock_lost');
                return _sync_lock_lost($tenantId, $owner, $step['result']);
            }
        }
        job_run_finish($runId, 'success', $jobMetrics);
        return ['done' => $step['done'], 'skipped' => false, 'result' => $step['result']];
    } catch (Throwable $e) {
        // Im Worker bleibt der Lauf mit Cursor bestehen, damit der nächste Versuch am Checkpoint fortsetzt;
        // im Browser-/Cron-Pfad endet der Lauf mit Fehler. Fehlertexte werden bereinigt gespeichert.
        $newStatus = defined('IN_WORKER') ? 'running' : 'error';
        $pdo->prepare(
            "UPDATE sync_state SET status = ?, lock_until = NULL, lock_owner = NULL, finished_at = IF(? = 'error', NOW(), finished_at), last_error = ? WHERE tenant_id = ? AND lock_owner = ?"
        )->execute([$newStatus, $newStatus, mb_substr(log_sanitize($e->getMessage()), 0, 500), $tenantId, $owner]);
        // Technischer Fehler des Schritts (api_errors = 1); Konfigurationsfehler einer Firma erhalten die Kategorie auth
        job_run_finish($runId, 'failed', ['api_errors' => 1], monitor_category($e));
        if (!defined('IN_WORKER')) {
            sync_run_finish($tenantId, 'failed', (array)($state['cursor']['result'] ?? []), $e->getMessage(), monitor_category($e));
        }
        throw $e;
    }
}

/** Sperre während des Schritts verloren: nichts speichern, protokollieren, als übersprungen melden. */
function _sync_lock_lost(string $tenantId, string $owner, array $result): array
{
    error_log('Sync für Firma ' . $tenantId . ': Sperre während des Schritts verloren (Inhaber ' . substr($owner, 0, 8) . '), Cursor verworfen.');
    audit_log($tenantId, null, 'sync_lock_lost', 'organization', $tenantId, ['owner' => substr($owner, 0, 8)]);
    return ['done' => false, 'skipped' => true, 'lock_lost' => true, 'result' => $result];
}

/**
 * Verständlicher Zustand eines Laufs für die Oberfläche.
 * Wartet | Wird synchronisiert | Teilweise verarbeitet | Abgeschlossen | Fehler | Kein Lauf
 */
function sync_state_label(?array $state): string
{
    if (!$state) {
        return 'Kein Lauf';
    }
    switch ($state['status']) {
        case 'done':
            return 'Abgeschlossen';
        case 'error':
            return 'Fehler';
        case 'running':
            if (!sync_state_is_running($state)) {
                return 'Abgebrochen (kein Fortschritt seit ' . SYNC_STALE_MINUTES . ' Minuten)';
            }
            if ((int)($state['lock_active'] ?? 0) === 1) {
                return 'Wird synchronisiert';
            }
            return !empty($state['cursor']) ? 'Teilweise verarbeitet, wartet auf den nächsten Schritt' : 'Wartet';
        default:
            return 'Kein Lauf';
    }
}

/**
 * Für den Cron: alle laufenden Synchronisationen im Zeitbudget fortsetzen.
 *
 * Fairness: Die Firmen werden im Round-Robin bedient, die am längsten nicht
 * bearbeitete zuerst. Je Runde erhält jede Firma höchstens $stepsPerRound
 * Schritte, dann kommt die nächste Firma dran; solange Zeit bleibt, beginnt
 * eine neue Runde. So blockiert ein Erstimport mit vielen Rechnungen nicht die
 * übrigen Firmen, auch wenn der Aufruf (z.B. cron-job.org, 30 s) knapp ist.
 *
 * @return array{tenants:int,steps:int,finished:int,errors:int,rounds:int,open:int}
 */
function sync_run_pending(int $maxSeconds = 50, int $stepsPerRound = 2): array
{
    $deadline = microtime(true) + $maxSeconds;
    $stats = ['tenants' => 0, 'steps' => 0, 'finished' => 0, 'errors' => 0, 'rounds' => 0, 'open' => 0];

    $queue = db()->query(
        "SELECT tenant_id FROM sync_state WHERE status = 'running' AND (lock_until IS NULL OR lock_until < NOW())
         ORDER BY updated_at ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $stats['tenants'] = count($queue);

    while ($queue && microtime(true) < $deadline) {
        $stats['rounds']++;
        $next = [];
        foreach ($queue as $tenantId) {
            if (microtime(true) >= $deadline) {
                $next[] = $tenantId;
                continue;
            }
            $keep = true;
            for ($i = 0; $i < max(1, $stepsPerRound) && microtime(true) < $deadline; $i++) {
                try {
                    $r = sync_state_step((string)$tenantId);
                } catch (Throwable $e) {
                    error_log('Sync-Cron für Firma ' . $tenantId . ' fehlgeschlagen: ' . $e->getMessage());
                    $stats['errors']++;
                    $keep = false;
                    break;
                }
                if ($r['skipped']) { // fremde Sperre (z.B. Nutzer synchronisiert gerade selbst)
                    $keep = false;
                    break;
                }
                $stats['steps']++;
                if ($r['done']) {
                    $stats['finished']++;
                    $keep = false;
                    break;
                }
            }
            if ($keep) {
                $next[] = $tenantId;
            }
        }
        $queue = $next;
    }
    $stats['open'] = count($queue);
    return $stats;
}

// ---------------------------------------------------------------------------
// Fortschritt und dauerhafte Historie je Firma (Auftrag III, Abschnitte 47 bis 50)
// ---------------------------------------------------------------------------

/**
 * Fortschritt eines laufenden Zustands aus dem Cursor: Prozent (null = unbekannt) und verständlicher Text.
 * Listing zählt bis 10 %, Verarbeitung von 10 bis 95 %, Nachprüfung bis 100 %.
 */
function sync_progress(?array $state): array
{
    $out = ['percent' => null, 'text' => sync_state_label($state), 'processed' => 0, 'total' => null, 'phase' => null];
    if (!$state || $state['status'] !== 'running') {
        if ($state && $state['status'] === 'done') {
            $out['percent'] = 100;
        }
        return $out;
    }
    $c = $state['cursor'] ?? null;
    if (!$c) {
        $out['text'] = 'Synchronisierung eingeplant, Verbindung wird geprüft';
        $out['percent'] = 0;
        return $out;
    }
    $out['phase'] = (string)($c['phase'] ?? '');
    if ($out['phase'] === 'listing') {
        $pages = max(1, (int)($c['lex_total_pages'] ?? 1));
        $page = (int)($c['lex_page'] ?? 0);
        $out['percent'] = (int)min(10, round(10 * $page / $pages));
        $out['text'] = sprintf('Rechnungsliste wird geladen (Seite %d von %d, %s)', min($pages, $page + 1), $pages,
            ($c['listing_status'] ?? 'open') === 'overdue' ? 'überfällige Rechnungen' : 'offene Rechnungen');
    } elseif ($out['phase'] === 'processing') {
        $total = count((array)($c['collected'] ?? []));
        $idx = (int)($c['proc_index'] ?? 0);
        $out['processed'] = $idx;
        $out['total'] = $total;
        $out['percent'] = $total > 0 ? (int)(10 + round(85 * min($idx, $total) / $total)) : 95;
        $out['text'] = sprintf('%s von %s Rechnungen verarbeitet', number_format($idx, 0, ',', '.'), number_format($total, 0, ',', '.'));
    } elseif ($out['phase'] === 'recheck') {
        $out['percent'] = 97;
        $out['text'] = 'Abschluss: Status nicht mehr offener Rechnungen wird geprüft';
    }
    if (!empty($state['queue_waiting'])) {
        $out['text'] = 'Warte auf Lexware Office, automatischer neuer Versuch läuft';
    }
    return $out;
}

function sync_runs_available(): bool
{
    static $a = null;
    if ($a === null) {
        try {
            db()->query('SELECT 1 FROM sync_runs LIMIT 1');
            $a = true;
        } catch (Throwable $e) {
            $a = false;
        }
    }
    return $a;
}

/** Historieneintrag eröffnen (ein laufender Eintrag je Firma; ein noch offener wird als abgebrochen geschlossen). */
function sync_run_open(string $tenantId, ?array $actor, ?string $jobId = null): ?string
{
    if (!sync_runs_available()) {
        return null;
    }
    try {
        $pdo = db();
        $pdo->prepare("UPDATE sync_runs SET status = 'cancelled', finished_at = NOW(), error_category = 'superseded', error_text = 'Durch neuen Lauf ersetzt' WHERE tenant_id = ? AND status = 'running'")
            ->execute([$tenantId]);
        $id = uuid4();
        $trigger = (string)($actor['trigger'] ?? (isset($actor['user_id']) && $actor['user_id'] ? 'manual' : 'auto'));
        $pdo->prepare('INSERT INTO sync_runs (id, tenant_id, job_id, correlation_id, triggered_by, user_id, status, started_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$id, $tenantId, $jobId, function_exists('correlation_current') ? correlation_current() : null, mb_substr($trigger, 0, 20), $actor['user_id'] ?? null, 'running']);
        return $id;
    } catch (Throwable $e) {
        return null;
    }
}

/** Laufenden Historieneintrag mit Job und Worker verknüpfen. */
function sync_run_attach(string $tenantId, ?string $jobId, ?string $workerId): void
{
    if (!sync_runs_available()) {
        return;
    }
    try {
        db()->prepare("UPDATE sync_runs SET job_id = COALESCE(?, job_id), worker_id = COALESCE(?, worker_id) WHERE tenant_id = ? AND status = 'running'")
            ->execute([$jobId, $workerId, $tenantId]);
    } catch (Throwable $e) {
    }
}

/** Historieneintrag abschließen (success | partial | failed | cancelled) mit Mengen und Kennzahlen. */
function sync_run_finish(string $tenantId, string $status, array $result, ?string $errorText = null, ?string $category = null): void
{
    if (!sync_runs_available()) {
        return;
    }
    try {
        $m = (array)($result['metrics'] ?? []);
        db()->prepare(
            "UPDATE sync_runs SET status = ?, finished_at = NOW(), duration_ms = TIMESTAMPDIFF(SECOND, started_at, NOW()) * 1000,
                    steps = ?, checked = ?, created = ?, updated = ?, removed = ?, skipped = ?, errors = ?, retries = ?, api_calls = ?, api_ms = ?, throttle_ms = ?,
                    error_category = ?, error_text = ?
             WHERE tenant_id = ? AND status = 'running'"
        )->execute([
            in_array($status, ['success', 'partial', 'failed', 'cancelled'], true) ? $status : 'partial',
            (int)($m['steps'] ?? 0), (int)($result['synced'] ?? 0), (int)($result['new'] ?? 0), (int)($result['updated'] ?? 0), (int)($result['removed'] ?? 0),
            (int)($m['skipped_unchanged'] ?? 0), $status === 'failed' ? 1 : 0, (int)($m['retries'] ?? 0), (int)($m['api_calls'] ?? 0), (int)($m['api_ms'] ?? 0), (int)($m['throttle_ms'] ?? 0),
            $category !== null ? mb_substr($category, 0, 60) : null,
            $errorText !== null ? mb_substr(function_exists('log_sanitize') ? log_sanitize($errorText) : $errorText, 0, 500) : null,
            $tenantId,
        ]);
    } catch (Throwable $e) {
    }
}

/** Historie einer Firma (mandantengefiltert), neueste zuerst. */
function sync_runs_list(string $tenantId, int $limit = 50): array
{
    if (!sync_runs_available()) {
        return [];
    }
    $st = db()->prepare('SELECT r.*, u.display_name AS user_name, u.email AS user_email FROM sync_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.tenant_id = ? ORDER BY r.started_at DESC LIMIT ' . (int)$limit);
    $st->execute([$tenantId]);
    return $st->fetchAll();
}

function sync_run_load(string $tenantId, string $id): ?array
{
    if (!sync_runs_available()) {
        return null;
    }
    $st = db()->prepare('SELECT r.*, u.display_name AS user_name, u.email AS user_email FROM sync_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.tenant_id = ? AND r.id = ?');
    $st->execute([$tenantId, $id]);
    return $st->fetch() ?: null;
}

function sync_run_status_label(string $status): string
{
    return ['running' => 'Läuft', 'success' => 'Erfolgreich', 'partial' => 'Teilweise erfolgreich', 'failed' => 'Fehlgeschlagen', 'cancelled' => 'Abgebrochen'][$status] ?? $status;
}

function sync_trigger_label(string $t): string
{
    return ['manual' => 'Manuell', 'auto' => 'Automatisch', 'full' => 'Vollabgleich (automatisch)', 'admin' => 'Betreiber', 'cron' => 'Automatisch (Cron)'][$t] ?? $t;
}

/** HTML-Fragment mit Fortschrittsbalken und Text für die Synchronisierungsansicht (mandantenbezogen). */
function sync_progress_fragment(string $tenantId): string
{
    $state = sync_state_get($tenantId);
    if (function_exists('queue_tenant_active')) {
        $job = queue_tenant_active($tenantId, 'sync_run');
        if ($job && $state) {
            $state['queue_waiting'] = $job['status'] === 'retry';
            $state['job_status'] = $job['status'];
            $state['job_text'] = $job['progress_text'];
        }
    }
    $p = sync_progress($state);
    $done = !$state || $state['status'] !== 'running';
    $pct = $p['percent'];
    $text = $p['text'];
    if (!empty($state['job_status']) && $state['job_status'] === 'queued' && empty($state['cursor'])) {
        $text = 'Auftrag eingereiht, ein Worker übernimmt in Kürze';
    } elseif (!empty($state['job_status']) && $state['job_status'] === 'retry') {
        $text = 'Warte auf Lexware Office, automatischer neuer Versuch läuft';
    } elseif (!empty($state['job_text']) && !empty($state['queue_waiting'])) {
        $text = (string)$state['job_text'];
    }
    $h = '<div class="sync-progress" data-done="' . ($done ? '1' : '0') . '" role="status" aria-live="polite">';
    $h .= '<div class="sync-bar" aria-hidden="true"><span style="width:' . ($pct === null ? 0 : (int)$pct) . '%"' . ($pct === null ? ' class="sync-bar-unknown"' : '') . '></span></div>';
    $h .= '<div class="sync-text">' . ($pct === null ? '' : '<strong>' . (int)$pct . ' %</strong> ') . e($text) . '</div>';
    if ($p['total'] !== null) {
        $h .= '<div class="hint">' . e(number_format((int)$p['processed'], 0, ',', '.')) . ' von ' . e(number_format((int)$p['total'], 0, ',', '.')) . ' Rechnungen verarbeitet</div>';
    }
    $h .= '</div>';
    return $h;
}
