<?php
/**
 * Worker: reserviert Jobs seines Pools aus der Warteschlange und verarbeitet sie nacheinander.
 *
 *   php bin/worker.php --pool=lexware|stripe|mail|maintenance|all [--max-jobs=500] [--max-memory-mb=256] [--once] [--sleep=1]
 *
 * Beendet sich sauber bei SIGTERM/SIGINT (laufender Job wird zu Ende gebracht), nach --max-jobs Jobs oder
 * wenn der Speicher die Grenze überschreitet; Docker startet den Container dann neu (restart: always).
 * Schreibt je Durchlauf einen Heartbeat in die Datenbank und in eine Datei für den Docker-Healthcheck.
 */
define('LOG_SERVICE', 'worker');
define('IN_WORKER', true);
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/jobs.php';

$opts = cli_opts($argv);
$pool = (string)($opts['pool'] ?? 'all');
$pools = jobs_pools();
if (!isset($pools[$pool])) {
    fwrite(STDERR, "Unbekannter Pool '$pool'. Erlaubt: " . implode(', ', array_keys($pools)) . "\n");
    exit(2);
}
if (!queue_available()) {
    fwrite(STDERR, "Warteschlange nicht verfügbar (Migration 018 fehlt).\n");
    exit(3);
}
$types = $pools[$pool];
$maxJobs = max(1, (int)($opts['max-jobs'] ?? 500));
$maxMemory = max(64, (int)($opts['max-memory-mb'] ?? 256)) * 1048576;
$sleep = max(1, (int)($opts['sleep'] ?? 1));
$once = isset($opts['once']);
$workerId = sprintf('%s-%s-%d-%s', $pool, substr((string)gethostname(), 0, 20), getmypid(), substr(bin2hex(random_bytes(3)), 0, 6));
$heartbeatFile = (string)(getenv('WORKER_HEARTBEAT_FILE') ?: sys_get_temp_dir() . '/smarteinzug-worker-heartbeat');

$stopping = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use (&$stopping, $workerId) {
            $stopping = true;
            app_log('info', 'Worker beendet nach dem aktuellen Job (Signal)', ['worker' => $workerId]);
        });
    }
}

worker_register($workerId, $pool);
app_log('info', 'Worker gestartet', ['worker' => $workerId, 'pool' => $pool, 'types' => implode(',', $types)]);
cli_out("Worker $workerId gestartet (Pool $pool)");

$done = 0;
$failed = 0;
$lastBeat = 0;
$beat = function (string $status, ?string $jobId) use ($workerId, &$done, &$failed, $heartbeatFile, &$lastBeat): void {
    if (time() - $lastBeat >= 10 || $status !== 'idle') {
        worker_heartbeat($workerId, $status, $jobId, $done, $failed);
        @file_put_contents($heartbeatFile, (string)time());
        $lastBeat = time();
    }
};

$maintenanceLogged = false;
while (!$stopping) {
    // Wartungsmodus (Cutover): keine Jobs reservieren (kein Einzug, kein Sync, keine Mail gegen die
    // Datenbank, die gerade gesichert oder umgezogen wird); Heartbeat weiter schreiben.
    if (maintenance_active()) {
        if (!$maintenanceLogged) {
            app_log('warning', 'Wartungsmodus aktiv, Worker pausiert', ['worker' => $workerId, 'pool' => $pool]);
            cli_out('Wartungsmodus aktiv, Worker pausiert.');
            $maintenanceLogged = true;
        }
        $beat('idle', null);
        if ($once) {
            break;
        }
        sleep(5);
        continue;
    }
    if ($maintenanceLogged) {
        app_log('info', 'Wartungsmodus beendet, Worker nimmt die Arbeit wieder auf', ['worker' => $workerId]);
        $maintenanceLogged = false;
    }
    try {
        $job = queue_reserve($workerId, $types);
    } catch (Throwable $e) {
        app_log('error', 'Reservierung fehlgeschlagen, Datenbank nicht erreichbar?', ['worker' => $workerId, 'error_code' => monitor_category($e)]);
        sleep(5);
        continue;
    }
    if (!$job) {
        $beat('idle', null);
        if ($once) {
            break;
        }
        sleep($sleep);
        continue;
    }
    $beat('busy', (string)$job['id']);
    cli_out(sprintf('Job %s (%s, Firma %s) Versuch %d', $job['id'], $job['type'], $job['tenant_id'] ?? '-', (int)$job['attempts']));
    $outcome = job_execute($job, $workerId);
    if (in_array($outcome, ['failed', 'retry'], true)) {
        $failed++;
    } else {
        $done++;
    }
    cli_out(sprintf('Job %s: %s', $job['id'], $outcome));
    $beat('idle', null);
    if ($done + $failed >= $maxJobs || memory_get_usage(true) > $maxMemory) {
        app_log('info', 'Worker beendet planmäßig (Jobgrenze oder Speicher)', ['worker' => $workerId, 'done' => $done, 'failed' => $failed, 'memory' => memory_get_usage(true)]);
        break;
    }
    if ($once) {
        break;
    }
}
worker_stop($workerId);
cli_out("Worker $workerId beendet: $done erledigt, $failed fehlgeschlagen");
exit(0);
