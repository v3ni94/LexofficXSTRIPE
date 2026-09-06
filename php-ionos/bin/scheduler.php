<?php
/**
 * Scheduler: prüft alle 30 Sekunden fällige wiederkehrende Aufgaben und reiht sie als Jobs ein
 * (Einzugsverarbeitung, Klärung, Monitoring, Alarmierung, Wartung, automatische und nächtliche
 * Synchronisation je Firma). Er verarbeitet selbst keine Jobs.
 *
 *   php bin/scheduler.php [--once] [--interval=30]
 *
 * Es läuft nur ein Scheduler je Datenbank (GET_LOCK). Mit --once eignet er sich für einen klassischen Cron.
 */
define('LOG_SERVICE', 'scheduler');
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/jobs.php';

$opts = cli_opts($argv);
$once = isset($opts['once']);
$interval = max(10, (int)($opts['interval'] ?? 30));
if (!queue_available()) {
    fwrite(STDERR, "Warteschlange nicht verfügbar (Migration 018 fehlt).\n");
    exit(3);
}
$pdo = db();
$st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$st->execute(['smarteinzug_scheduler']);
if ((int)$st->fetchColumn() !== 1) {
    cli_out('Ein anderer Scheduler läuft bereits, dieser Prozess beendet sich.');
    exit(0);
}
$st->closeCursor();

$workerId = 'scheduler-' . substr((string)gethostname(), 0, 20) . '-' . getmypid();
$heartbeatFile = (string)(getenv('WORKER_HEARTBEAT_FILE') ?: sys_get_temp_dir() . '/smarteinzug-scheduler-heartbeat');
$stopping = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use (&$stopping) { $stopping = true; });
    }
}
worker_register($workerId, 'scheduler');
cli_out("Scheduler $workerId gestartet (Intervall $interval s)");
$ticks = 0;
$maintenanceLogged = false;
while (!$stopping) {
    try {
        // Wartungsmodus (Cutover): keine neuen Jobs einreihen, Heartbeat weiter schreiben.
        if (maintenance_active()) {
            if (!$maintenanceLogged) {
                app_log('warning', 'Wartungsmodus aktiv, Scheduler reiht keine Jobs ein', ['worker' => $workerId]);
                cli_out('Wartungsmodus aktiv, keine neuen Jobs.');
                $maintenanceLogged = true;
            }
            worker_heartbeat($workerId, 'idle', null, $ticks, 0);
            @file_put_contents($heartbeatFile, (string)time());
        } else {
            if ($maintenanceLogged) {
                app_log('info', 'Wartungsmodus beendet, Scheduler nimmt die Arbeit wieder auf', ['worker' => $workerId]);
                $maintenanceLogged = false;
            }
            $queued = scheduler_tick();
            worker_heartbeat($workerId, 'busy', null, ++$ticks, 0);
            @file_put_contents($heartbeatFile, (string)time());
            if ($queued) {
                cli_out('Eingereiht: ' . implode(', ', $queued));
            }
        }
    } catch (Throwable $e) {
        app_log('error', 'Scheduler-Tick fehlgeschlagen', ['error_code' => monitor_category($e)]);
    }
    if ($once) {
        break;
    }
    for ($i = 0; $i < $interval && !$stopping; $i++) {
        sleep(1);
    }
}
worker_stop($workerId);
cli_out('Scheduler beendet.');
