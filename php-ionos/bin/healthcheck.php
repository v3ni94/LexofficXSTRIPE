<?php
/**
 * Gesundheitsprüfung für Container und Deployment.
 *   php bin/healthcheck.php --db            Datenbank SELECT 1
 *   php bin/healthcheck.php --redis         Redis PING (nur wenn konfiguriert)
 *   php bin/healthcheck.php --heartbeat     Heartbeat-Datei dieses Containers jünger als 90 s (Worker/Scheduler)
 *   php bin/healthcheck.php --metrics       Metrik-Sammler: bin/host-metrics.php läuft als PID 1 und hat zuletzt
 *                                           innerhalb von METRICS_MAX_AGE_SECONDS (Standard 300 s) einen Durchlauf beendet
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
if (isset($opts['metrics'])) {
    // Metrik-Sammler (bin/host-metrics.php): Er erzeugt bewusst KEINEN Worker-Heartbeat in der Datenbank,
    // der Heartbeat-Check der Worker passt hier also nicht (er meldete sonst dauerhaft "ungesund", obwohl
    // der Prozess läuft, siehe docs/vps/06-betrieb.md). Geprüft wird deshalb zweistufig:
    //  1. Läuft im Container tatsächlich host-metrics.php als PID 1 (kein anderer oder beendeter Prozess)?
    //  2. Hat die Schleife zuletzt innerhalb der erlaubten Zeit einen Durchlauf abgeschlossen (eigene
    //     Heartbeat-Datei, rein lokal geschrieben)? Damit fällt auch ein hängender Prozess auf, ohne dass
    //     eine kurzzeitig nicht erreichbare Datenbank den Container fälschlich als ungesund markiert.
    $check('metrics', function () {
        // Testhaken (nur CLI): Datei mit dem zu prüfenden Kommandozeileninhalt statt /proc/1/cmdline.
        $cmdlineFile = (string)(getenv('HEALTHCHECK_PID1_FILE') ?: '/proc/1/cmdline');
        $cmdline = @file_get_contents($cmdlineFile);
        if ($cmdline === false) {
            return 'Kommandozeile von PID 1 nicht lesbar (' . $cmdlineFile . ')';
        }
        // /proc/<pid>/cmdline trennt Argumente mit Nullbytes.
        if (!str_contains(str_replace("\0", ' ', $cmdline), 'bin/host-metrics.php')) {
            return 'bin/host-metrics.php läuft nicht als PID 1';
        }
        $file = metrics_heartbeat_file();
        if (!is_file($file)) {
            return 'noch kein Durchlauf abgeschlossen';
        }
        $maxAge = max(60, (int)(getenv('METRICS_MAX_AGE_SECONDS') ?: 300));
        $age = time() - (int)@file_get_contents($file);
        return $age < $maxAge ? true : 'letzter Durchlauf vor ' . $age . ' s (erlaubt: ' . $maxAge . ' s)';
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
