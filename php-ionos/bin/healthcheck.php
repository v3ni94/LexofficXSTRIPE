<?php
/**
 * Gesundheitsprüfung für Container und Deployment.
 *   php bin/healthcheck.php --db            Datenbank SELECT 1
 *   php bin/healthcheck.php --redis         Redis PING (nur wenn konfiguriert)
 *   php bin/healthcheck.php --heartbeat     Heartbeat-Datei dieses Containers jünger als 90 s (Worker/Scheduler)
 *   php bin/healthcheck.php --workers=lexware,stripe   je Pool mindestens ein lebender Worker (DB)
 *   php bin/healthcheck.php --scheduler     Scheduler-Heartbeat in der DB jünger als 120 s
 *   php bin/healthcheck.php --queue         Warteschlange lesbar, keine Jobs mit abgelaufenem Heartbeat > 10
 *   php bin/healthcheck.php --all           db, redis, workers (alle Pools), scheduler, queue
 * Exit 0 = gesund, 1 = ungesund. Gibt nur Kurztexte aus, keine Geheimnisse.
 */
define('LOG_SERVICE', 'cli');
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/queue.php';

$opts = cli_opts($argv);
$fails = [];
$check = function (string $name, callable $fn) use (&$fails): void {
    try {
        $r = $fn();
        if ($r !== true) {
            $fails[] = $name . ': ' . (is_string($r) ? $r : 'fehlgeschlagen');
        }
    } catch (Throwable $e) {
        $fails[] = $name . ': ' . monitor_category($e);
    }
};
$all = isset($opts['all']);
if ($all || isset($opts['db'])) {
    $check('db', fn() => (int)db()->query('SELECT 1')->fetchColumn() === 1);
}
if ($all || isset($opts['redis'])) {
    $check('redis', function () {
        if (!config('redis')) { return true; }
        $r = redis_client();
        return $r && $r->ping() ? true : 'nicht erreichbar';
    });
}
if (isset($opts['heartbeat'])) {
    $check('heartbeat', function () {
        $f = (string)(getenv('WORKER_HEARTBEAT_FILE') ?: sys_get_temp_dir() . '/smarteinzug-worker-heartbeat');
        if (!is_file($f)) { $f = sys_get_temp_dir() . '/smarteinzug-scheduler-heartbeat'; }
        return is_file($f) && time() - (int)file_get_contents($f) < 90 ? true : 'kein frischer Heartbeat';
    });
}
if ($all || isset($opts['workers'])) {
    $pools = $all ? ['lexware', 'stripe', 'mail', 'maintenance'] : array_filter(explode(',', (string)$opts['workers']));
    foreach ($pools as $p) {
        $check('worker-' . $p, fn() => workers_alive($p) > 0 || workers_alive('all') > 0 ? true : 'kein lebender Worker');
    }
}
if ($all || isset($opts['scheduler'])) {
    $check('scheduler', fn() => workers_alive('scheduler', 120) > 0 ? true : 'kein Scheduler-Heartbeat');
}
if ($all || isset($opts['queue'])) {
    $check('queue', function () {
        if (!queue_available()) { return 'Tabelle jobs fehlt'; }
        $n = (int)db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'processing' AND heartbeat_at < '" . queue_utc(queue_now() - 900) . "'")->fetchColumn();
        return $n <= 10 ? true : $n . ' Jobs ohne Heartbeat';
    });
}
if ($fails) {
    fwrite(STDERR, 'UNGESUND: ' . implode('; ', $fails) . "\n");
    exit(1);
}
echo "OK\n";
exit(0);
