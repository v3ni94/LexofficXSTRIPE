<?php
/**
 * Job-Handler der Warteschlange (Auftrag III). Ein Handler erhält den reservierten Job und liefert
 * ['status' => completed|partially_completed|cancelled, 'result' => [...], 'prune' => bool] oder wirft:
 *  - JobRequeueException: Zeitbudget des Versuchs erreicht, sofort fortsetzen (kein Fehlversuch)
 *  - JobRetryException oder CircuitOpenException: technischer Fehler, erneuter Versuch mit Backoff
 *  - JobFailedException: fachlicher Fehler, kein erneuter Versuch
 * Jeder Handler arbeitet ausschließlich mit der Firma aus dem Job (Mandantentrennung) und ist so gebaut,
 * dass eine Wiederholung keine doppelten Buchungen, Lastschriften oder Datensätze erzeugt.
 */
declare(strict_types=1);

require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/sync_state.php';
require_once __DIR__ . '/collections.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

/** Worker-Pools und die Jobtypen, die sie bearbeiten. */
function jobs_pools(): array
{
    return [
        'lexware'     => ['sync_run'],
        'stripe'      => ['collections_due', 'unclear_attempts'],
        'mail'        => ['mail', 'alerts', 'mandate_reminders'],
        'maintenance' => ['monitor_collect', 'maintenance'],
        'all'         => ['sync_run', 'collections_due', 'unclear_attempts', 'mail', 'alerts', 'mandate_reminders', 'monitor_collect', 'maintenance'],
    ];
}

function jobs_config(): array
{
    $c = (array)config('queue', []);
    return [
        'sync_attempt_seconds'    => max(30, (int)($c['sync_attempt_seconds'] ?? 600)),   // Zeitbudget je Versuch, danach Fortsetzung
        'sync_max_steps_attempt'  => max(1, (int)($c['sync_max_steps_attempt'] ?? 60)),
        'collections_seconds'     => max(20, (int)($c['collections_seconds'] ?? 120)),
        'auto_sync_hours'         => max(1, (int)($c['auto_sync_hours'] ?? 6)),
        'full_sync_hour'          => max(0, min(23, (int)($c['full_sync_hour'] ?? 3))),
        'prune_days'              => max(7, (int)($c['prune_days'] ?? 30)),
    ];
}

/** Zentrale Verteilung. */
function job_handle(array $job, string $workerId): array
{
    switch ((string)$job['type']) {
        case 'sync_run':          return job_sync_run($job, $workerId);
        case 'collections_due':   return job_collections_due($job);
        case 'unclear_attempts':  return job_unclear_attempts($job);
        case 'mail':              return job_mail($job);
        case 'alerts':            return ['status' => 'completed', 'result' => alerts_cron_notify()];
        case 'mandate_reminders': return job_mandate_reminders($job);
        case 'monitor_collect':   return ['status' => 'completed', 'result' => monitor_collect(['source' => 'worker', 'budget' => 8.0])];
        case 'maintenance':       return job_maintenance($job);
        default:
            throw new JobFailedException('Unbekannter Jobtyp ' . $job['type']);
    }
}

/**
 * Synchronisation einer Firma: setzt den vorhandenen Schrittmechanismus (sync_state_step) fort, meldet
 * Fortschritt und Heartbeat, unterbricht beim Zeitbudget je Versuch mit sofortiger Fortsetzung.
 */
function job_sync_run(array $job, string $workerId): array
{
    $tenantId = (string)($job['tenant_id'] ?? '');
    if ($tenantId === '') {
        throw new JobFailedException('Synchronisation ohne Firma.');
    }
    $p = $job['payload_data'];
    $cfg = jobs_config();
    $pdo = db();

    $org = $pdo->prepare('SELECT id, name, sync_paused, deleted_at FROM organizations WHERE id = ?');
    $org->execute([$tenantId]);
    $o = $org->fetch();
    if (!$o || $o['deleted_at'] !== null) {
        throw new JobFailedException('Firma nicht vorhanden.');
    }
    if ((int)($o['sync_paused'] ?? 0) === 1) {
        queue_heartbeat($job, null, 'Synchronisation für diese Firma vom Betreiber pausiert');
        return ['status' => 'cancelled', 'result' => ['reason' => 'sync_paused']];
    }

    $state = sync_state_get($tenantId);
    if ($state && $state['status'] === 'error' && !empty($state['cursor'])) {
        // Vorheriger Versuch technisch gescheitert: am gespeicherten Checkpoint fortsetzen statt neu zu beginnen
        $pdo->prepare("UPDATE sync_state SET status = 'running', lock_until = NULL, lock_owner = NULL, finished_at = NULL WHERE tenant_id = ? AND status = 'error'")->execute([$tenantId]);
        $state = sync_state_get($tenantId);
    }
    if (!$state || $state['status'] !== 'running') {
        // Lauf noch nicht eröffnet (z.B. automatischer Sync): jetzt starten
        try {
            sync_invoice_source($tenantId); // prüft Verbindung und Schlüssel, ohne API-Aufruf
        } catch (Throwable $e) {
            throw new JobFailedException('Lexware Office ist für diese Firma nicht eingerichtet: ' . $e->getMessage());
        }
        $actor = ['user_id' => $job['user_id'] ?? null, 'trigger' => (string)($p['triggered_by'] ?? 'auto')];
        $state = sync_state_start($tenantId, $actor);
    }
    sync_run_attach($tenantId, (string)$job['id'], $workerId);
    if (!empty($p['full'])) {
        // Vollabgleich: Änderungserkennung für diesen Lauf ausschalten (Cursor-Flag wird von sync.php gelesen)
        $pdo->prepare("UPDATE sync_state SET cursor_json = JSON_SET(COALESCE(cursor_json, '{}'), '$.force_full', true) WHERE tenant_id = ? AND status = 'running'")->execute([$tenantId]);
    }

    $deadline = microtime(true) + $cfg['sync_attempt_seconds'];
    if (isset($job['_deadline'])) {
        $deadline = min($deadline, (float)$job['_deadline']); // Inline-Betrieb: Zeitbudget des Cron-Aufrufs
    }
    $steps = 0;
    $skips = 0;
    while (true) {
        try {
            $step = sync_state_step($tenantId);
        } catch (CircuitOpenException $e) {
            queue_heartbeat($job, null, 'Warte auf Lexware Office, automatischer neuer Versuch läuft');
            throw new JobRetryException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $cat = monitor_category($e);
            if ($cat === 'auth') {
                throw new JobFailedException('Lexware Office lehnt den API-Schlüssel dieser Firma ab. Bitte Schlüssel in den Einstellungen prüfen.', 0, $e);
            }
            throw new JobRetryException($e->getMessage(), 0, $e);
        }
        if ($step['skipped']) {
            // Sperre liegt gerade bei einem anderen Prozess (z.B. Nutzer im Browser): kurz warten, dann erneut
            if (++$skips > 5) {
                throw new JobRequeueException('Sperre der Firma anderweitig belegt, Fortsetzung später');
            }
            sleep(2);
            $st = sync_state_get($tenantId);
            if (!$st || $st['status'] !== 'running') {
                break; // anderweitig abgeschlossen oder abgebrochen
            }
            continue;
        }
        $steps++;
        $state = sync_state_get($tenantId);
        $prog = sync_progress($state);
        if (!queue_heartbeat($job, $prog['percent'], $prog['text'])) {
            throw new JobRequeueException('Reservierung verloren, Fortsetzung durch anderen Worker');
        }
        if ($step['done']) {
            $r = $step['result'];
            $pdo->prepare("UPDATE sync_state SET status = 'idle' WHERE tenant_id = ? AND status = 'done'")->execute([$tenantId]);
            return ['status' => 'completed', 'result' => ['synced' => (int)($r['synced'] ?? 0), 'new' => (int)($r['new'] ?? 0), 'updated' => (int)($r['updated'] ?? 0), 'removed' => (int)($r['removed'] ?? 0), 'steps' => $steps, 'api_calls' => (int)($r['metrics']['api_calls'] ?? 0)]];
        }
        if (microtime(true) >= $deadline || $steps >= $cfg['sync_max_steps_attempt']) {
            throw new JobRequeueException('Zeitbudget je Versuch erreicht, Fortsetzung eingeplant');
        }
    }
    return ['status' => 'partially_completed', 'result' => ['steps' => $steps, 'note' => 'Lauf wurde anderweitig beendet']];
}

/** Fällige Einzüge einreichen (idempotent über den vorhandenen Einreichmechanismus mit Idempotenzschlüsseln). */
function job_collections_due(array $job): array
{
    $tenantId = $job['tenant_id'] ?? null;
    if (platform_collections_paused()) {
        return ['status' => 'completed', 'result' => ['skipped' => 'platform_paused']];
    }
    $cfg = jobs_config();
    $deadline = microtime(true) + $cfg['collections_seconds'];
    if (isset($job['_deadline'])) {
        $deadline = min($deadline, (float)$job['_deadline']);
    }
    $seen = array_values(array_filter((array)($job['payload_data']['_seen'] ?? []), 'is_string'));
    $r = process_scheduled_collections($tenantId ? (string)$tenantId : null, ['user_id' => null, 'email' => 'worker'], ['deadline' => $deadline, 'skip_ids' => $seen]);
    $handled = (array)($r['handled_ids'] ?? []);
    unset($r['handled_ids']);
    queue_heartbeat($job, 100, sprintf('%d eingereicht, %d fehlgeschlagen, %d zurückgestellt', (int)$r['submitted'], (int)$r['failed'], (int)$r['deferred']));
    if ((int)($r['remaining'] ?? 0) > 0 && $handled) {
        // Zwischenstand merken: bereits behandelte (auch zurückgestellte) Einzüge werden in der Fortsetzung
        // übersprungen, damit ein zurückgestellter Einzug nicht alle folgenden blockiert
        $payload = $job['payload_data'];
        $payload['_seen'] = array_slice(array_values(array_unique(array_merge($seen, array_map('strval', $handled)))), -5000);
        queue_update_payload($job, $payload);
        $job['payload_data'] = $payload;
        throw new JobRequeueException(sprintf('%d fällige Einzüge folgen im nächsten Durchlauf', (int)$r['remaining']));
    }
    return ['status' => 'completed', 'result' => $r];
}

/** Unklare Einzugsversuche je Firma klären (nur Lesen bei Stripe, keine neue Lastschrift). */
function job_unclear_attempts(array $job): array
{
    $tenantIds = $job['tenant_id'] ? [(string)$job['tenant_id']] : db()->query("SELECT DISTINCT tenant_id FROM collection_attempts WHERE status IN ('unknown','pending') AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchAll(PDO::FETCH_COLUMN);
    $sum = ['checked' => 0, 'recovered' => 0, 'cleared' => 0, 'pending' => 0, 'errors' => 0];
    foreach ($tenantIds as $tid) {
        try {
            $r = collection_attempts_resolve((string)$tid, ['user_id' => null, 'email' => 'worker']);
            foreach (['checked', 'recovered', 'cleared', 'pending'] as $k) {
                $sum[$k] += (int)($r[$k] ?? 0);
            }
        } catch (Throwable $e) {
            $sum['errors']++;
            app_log('warning', 'Klärung unklarer Versuche fehlgeschlagen', ['company_id' => (string)$tid, 'job_id' => $job['id'], 'error_code' => monitor_category($e)]);
        }
        queue_heartbeat($job);
    }
    return ['status' => $sum['errors'] > 0 ? 'partially_completed' : 'completed', 'result' => $sum];
}

/**
 * E-Mail zustellen. Der Versandweg nimmt die Nachricht an oder nicht; nach erfolgreicher Übergabe wird
 * der Inhalt aus dem Job entfernt (Datensparsamkeit). Eine Wiederholung nach einem Absturz zwischen
 * Übergabe und Statusspeicherung kann eine Nachricht doppelt zustellen; deshalb höchstens drei Versuche.
 */
function job_mail(array $job): array
{
    $p = $job['payload_data'];
    $to = (string)($p['to'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new JobFailedException('Ungültige Empfängeradresse.');
    }
    api_call_gate('mail', 20);
    $ok = mail_send_direct($to, (string)($p['subject'] ?? ''), (string)($p['text'] ?? ''), isset($p['html']) ? (string)$p['html'] : null);
    if (!$ok) {
        $err = mail_last_error();
        if ($err && ($err['kind'] ?? '') === 'rejected') {
            // Endgültige Ablehnung durch den Server (Empfänger unbekannt, Nachricht abgewiesen): kein Transportproblem
            throw new JobFailedException('Der Mailserver hat die Nachricht endgültig abgelehnt.');
        }
        circuit_failure('mail', 'connection');
        throw new JobRetryException('Der Versandweg hat die Nachricht nicht angenommen.');
    }
    circuit_success('mail');
    return ['status' => 'completed', 'result' => ['accepted' => true], 'prune' => true];
}

function job_mandate_reminders(array $job): array
{
    if (empty(config('features', [])['mandate_request'])) {
        return ['status' => 'completed', 'result' => ['skipped' => 'feature_off']];
    }
    require_once __DIR__ . '/mandate_requests.php';
    return ['status' => 'completed', 'result' => mandate_request_remind()];
}

/** Wartungsaufgaben: Bereinigungen und Freigabe hängender Jobs. Keine fachlichen Daten. */
function job_maintenance(array $job): array
{
    $cfg = jobs_config();
    $r = [];
    foreach ([
        'support_sessions' => fn() => support_sessions_expire(),
        'registration_requests' => fn() => registration_requests_cleanup(),
        'devices' => fn() => devices_cleanup(),
        'jobs_pruned' => fn() => queue_prune($cfg['prune_days']),
        'stale_jobs_released' => fn() => queue_release_stale(),
        'workers_pruned' => fn() => workers_prune(),
    ] as $k => $fn) {
        try {
            $r[$k] = $fn() ?? true;
        } catch (Throwable $e) {
            $r[$k] = 'fehler:' . monitor_category($e);
        }
        queue_heartbeat($job);
    }
    return ['status' => 'completed', 'result' => $r];
}

// ---------------------------------------------------------------------------
// Scheduler: fällige Aufgaben als Jobs einreihen (Abschnitt 45)
// ---------------------------------------------------------------------------

/** Fällige wiederkehrende Aufgaben einreihen. Liefert die Liste der eingereihten Typen. */
function scheduler_tick(): array
{
    if (!queue_available()) {
        return [];
    }
    $cfg = jobs_config();
    $now = queue_now();
    $queued = [];
    $tasks = [
        ['collections_due', 300, 'normal', 'collections:due'],
        ['unclear_attempts', 600, 'low', 'unclear:all'],
        ['monitor_collect', 240, 'low', 'monitor:collect'],
        ['alerts', 3600, 'low', 'alerts:daily'],
        ['maintenance', 3600, 'low', 'maintenance:hourly'],
        ['mandate_reminders', 3600, 'low', 'mandates:remind'],
    ];
    foreach ($tasks as [$type, $interval, $prio, $dedupe]) {
        $last = queue_ts(monitor_mark_get('sched_' . $type));
        if ($last !== null && $now - $last < $interval) {
            continue;
        }
        $r = queue_push($type, [], ['priority' => $prio, 'dedupe_key' => $dedupe]);
        monitor_mark('sched_' . $type, queue_utc($now));
        if ($r['created']) {
            $queued[] = $type;
        }
    }
    // Automatische Synchronisation je Firma mit Lexware-Verbindung und aktiver Queue
    $queued = array_merge($queued, scheduler_auto_sync($cfg, $now));
    // Hängende Jobs freigeben (Heartbeat abgelaufen)
    queue_release_stale();
    return $queued;
}

/** Regelmäßiger Delta-Sync (NORMAL) und nächtlicher Vollabgleich (LOW) je Firma. */
function scheduler_auto_sync(array $cfg, int $now): array
{
    $queued = [];
    try {
        $rows = db()->query(
            "SELECT o.id, o.sync_paused, s.status AS sync_status,
                    TIMESTAMPDIFF(SECOND, COALESCE(s.finished_at, s.updated_at), NOW()) AS age_seconds,
                    TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) AS state_age_seconds
             FROM organizations o
             JOIN integrations i ON i.tenant_id = o.id
             LEFT JOIN sync_state s ON s.tenant_id = o.id
             WHERE o.deleted_at IS NULL AND o.onboarding_completed = 1 AND i.lexoffice_connected = 1"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $hourLocal = (int)date('G', $now);
    foreach ($rows as $o) {
        $tid = (string)$o['id'];
        if ((int)$o['sync_paused'] === 1 || !queue_enabled($tid)) {
            continue;
        }
        if (($o['sync_status'] ?? null) === 'running') {
            // Läuft wirklich (Fortschritt in den letzten Minuten) oder hängt ein aktiver Job daran: nichts einreihen.
            // Ein verwaister Lauf ohne Job wird als Fehler geschlossen, damit die Firma nicht dauerhaft ausfällt.
            $fresh = $o['state_age_seconds'] !== null && (int)$o['state_age_seconds'] < SYNC_STALE_MINUTES * 60;
            if ($fresh || queue_tenant_active($tid, 'sync_run')) {
                continue;
            }
            db()->prepare("UPDATE sync_state SET status = 'error', lock_until = NULL, lock_owner = NULL, finished_at = NOW(), last_error = 'Lauf ohne Fortschritt vom Scheduler geschlossen' WHERE tenant_id = ? AND status = 'running'")->execute([$tid]);
            sync_run_finish($tid, 'failed', [], 'Lauf ohne Fortschritt vom Scheduler geschlossen', 'stale');
        }
        $ageSeconds = $o['age_seconds'] !== null ? (int)$o['age_seconds'] : null;
        $fullMark = monitor_mark_get('sched_full_' . $tid);
        $today = date('Y-m-d', $now);
        if ($hourLocal === $cfg['full_sync_hour'] && $fullMark !== $today) {
            $r = queue_push('sync_run', ['triggered_by' => 'full', 'full' => true], ['tenant_id' => $tid, 'priority' => 'low', 'dedupe_key' => 'sync:' . $tid]);
            if ($r['created']) {
                monitor_mark('sched_full_' . $tid, $today); // Marker nur, wenn der Vollabgleich wirklich eingereiht wurde
                $queued[] = 'sync_run:full:' . $tid;
            }
            continue;
        }
        if ($ageSeconds === null || $ageSeconds >= $cfg['auto_sync_hours'] * 3600) {
            $r = queue_push('sync_run', ['triggered_by' => 'auto'], ['tenant_id' => $tid, 'priority' => 'normal', 'dedupe_key' => 'sync:' . $tid]);
            if ($r['created']) {
                $queued[] = 'sync_run:auto:' . $tid;
            }
        }
    }
    return $queued;
}

/**
 * Inline-Verarbeitung ohne Worker (Hybridbetrieb auf dem Webhosting): Scheduler-Tick und danach
 * so viele Jobs wie ins Zeitbudget passen. Für den VPS laufen stattdessen Worker-Container.
 */
function queue_run_inline(float $budgetSeconds, string $workerId = 'inline'): array
{
    $stats = ['queued' => [], 'processed' => 0, 'failed' => 0, 'requeued' => 0];
    if (!queue_available()) {
        return $stats;
    }
    $deadline = microtime(true) + $budgetSeconds;
    $stats['queued'] = scheduler_tick();
    if (workers_alive() > 0) {
        $stats['note'] = 'Worker aktiv, Inline-Verarbeitung übersprungen';
        return $stats; // laufende Worker übernehmen; kein doppelter Pfad
    }
    $types = jobs_pools()['all'];
    while (microtime(true) < $deadline) {
        $job = queue_reserve($workerId, $types);
        if (!$job) {
            break;
        }
        $job['_deadline'] = $deadline;
        $outcome = job_execute($job, $workerId);
        $stats[$outcome === 'requeued' ? 'requeued' : ($outcome === 'failed' || $outcome === 'retry' ? 'failed' : 'processed')]++;
    }
    return $stats;
}

/**
 * Einen reservierten Job ausführen und das Ergebnis verbuchen (gemeinsam für Worker und Inline).
 * Liefert completed | partially_completed | cancelled | requeued | retry | failed.
 */
function job_execute(array $job, string $workerId): string
{
    correlation_id_set($job['correlation_id'] ?: null);
    $runId = job_run_start('queue:' . $job['type'], (string)$job['id'], $job['tenant_id'] ?? null, PHP_SAPI === 'cli' ? 'worker' : 'cron');
    $t0 = microtime(true);
    try {
        $out = job_handle($job, $workerId);
        $status = (string)($out['status'] ?? 'completed');
        queue_complete($job, $status, (array)($out['result'] ?? []), !empty($out['prune']));
        job_run_finish($runId, 'success', ['items' => (int)($out['result']['synced'] ?? ($out['result']['submitted'] ?? 0)), 'api_calls' => (int)($out['result']['api_calls'] ?? 0)]);
        return $status;
    } catch (JobRequeueException $e) {
        queue_requeue($job, 0, $e->getMessage());
        job_run_finish($runId, 'success', [], 'requeued');
        return 'requeued';
    } catch (JobFailedException $e) {
        $st = queue_fail($job, $e->getMessage(), 'business', false);
        job_run_finish($runId, 'failed', [], 'business');
        return $st;
    } catch (CircuitOpenException $e) {
        $st = queue_fail($job, $e->getMessage(), 'circuit_open', true);
        job_run_finish($runId, 'failed', [], 'circuit_open');
        return $st;
    } catch (Throwable $e) {
        $cat = $e instanceof JobRetryException && $e->getPrevious() ? monitor_category($e->getPrevious()) : monitor_category($e);
        $st = queue_fail($job, $e->getMessage(), $cat, true);
        job_run_finish($runId, 'failed', ['api_errors' => 1], $cat);
        return $st;
    } finally {
        correlation_id_set(null);
        app_log('debug', 'Job verarbeitet', ['job_id' => $job['id'], 'type' => $job['type'], 'duration_ms' => (int)round((microtime(true) - $t0) * 1000)]);
    }
}
