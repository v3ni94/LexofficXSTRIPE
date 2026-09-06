<?php
/**
 * Host-Metriken für den Adminbereich System (nur auf dem VPS sinnvoll). Liest /proc des Hosts
 * (im Container unter /hostproc eingebunden) und das Dateisystem unter HOST_ROOT (Standard: Ordner der
 * Releases; das Wurzeldateisystem des Hosts wird bewusst nicht eingebunden) und schreibt
 * gemessene Werte als Monitoring-Ereignisse: host_cpu (%), host_mem (%), host_disk (%), host_load1,
 * db_connections, db_qps, redis_mem (MB). Keine Schätzungen: fehlt eine Quelle, wird nichts geschrieben.
 *
 *   php bin/host-metrics.php [--interval=60] [--once]
 */
define('LOG_SERVICE', 'metrics');
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/monitor.php';
require_once dirname(__DIR__) . '/app/redis.php';

$opts = cli_opts($argv);
$interval = max(15, (int)($opts['interval'] ?? 60));
$proc = rtrim((string)(getenv('HOST_PROC') ?: (is_dir('/hostproc') ? '/hostproc' : '/proc')), '/');
$root = (string)(getenv('HOST_ROOT') ?: (is_dir('/hostroot') ? '/hostroot' : '/'));

function cpu_sample(string $proc): ?array
{
    $line = @file($proc . '/stat')[0] ?? '';
    if (!preg_match('/^cpu\s+(.*)$/', trim($line), $m)) { return null; }
    $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
    $idle = ($v[3] ?? 0) + ($v[4] ?? 0);
    return ['idle' => $idle, 'total' => array_sum($v)];
}

$prev = cpu_sample($proc);
$prevQ = null;
while (true) {
    sleep($interval);
    try {
        $cur = cpu_sample($proc);
        if ($prev && $cur && $cur['total'] > $prev['total']) {
            $pct = 100 * (1 - ($cur['idle'] - $prev['idle']) / ($cur['total'] - $prev['total']));
            monitor_event('host_cpu', 'ok', null, 'proc_stat', 'internal', $interval * 3, round($pct, 1), '%');
        }
        $prev = $cur;
        $mem = @file_get_contents($proc . '/meminfo');
        if ($mem && preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a) && (int)$t[1] > 0) {
            monitor_event('host_mem', 'ok', null, 'proc_meminfo', 'internal', $interval * 3, round(100 * (1 - (int)$a[1] / (int)$t[1]), 1), '%');
        }
        $load = @file_get_contents($proc . '/loadavg');
        if ($load && preg_match('/^([\d.]+)/', $load, $l)) {
            monitor_event('host_load1', 'ok', null, 'proc_loadavg', 'internal', $interval * 3, (float)$l[1], 'load');
        }
        $total = @disk_total_space($root); $free = @disk_free_space($root);
        if ($total && $free !== false) {
            monitor_event('host_disk', 'ok', null, 'statvfs', 'internal', $interval * 3, round(100 * (1 - $free / $total), 1), '%');
        }
        $st = db()->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Questions')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($st['Threads_connected'])) {
            monitor_event('db_connections', 'ok', null, 'show_status', 'internal', $interval * 3, (float)$st['Threads_connected'], 'conn');
        }
        if (isset($st['Questions'])) {
            $q = (int)$st['Questions'];
            if ($prevQ !== null && $q >= $prevQ) {
                monitor_event('db_qps', 'ok', null, 'show_status', 'internal', $interval * 3, round(($q - $prevQ) / $interval, 2), 'q/s');
            }
            $prevQ = $q;
        }
        $slow = db()->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'")->fetch();
        if ($slow) {
            monitor_event('db_slow_queries', 'ok', null, 'show_status', 'internal', $interval * 3, (float)$slow['Value'], 'gesamt');
        }
        if ($r = redis_client()) {
            $info = $r->info('memory');
            if (isset($info['used_memory'])) {
                monitor_event('redis_mem', 'ok', null, 'info_memory', 'internal', $interval * 3, round((int)$info['used_memory'] / 1048576, 1), 'MB');
            }
        }
    } catch (Throwable $e) {
        app_log('warning', 'Host-Metriken teilweise nicht erfasst', ['error_code' => monitor_category($e)]);
    }
    if (isset($opts['once'])) {
        break;
    }
}
