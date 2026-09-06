<?php
/**
 * Datenbankmigrationen in der Kommandozeile (VPS: im PHP-Container nach dem Deployment).
 * Verwendet denselben Runner wie migrate.php (app/migrate.php): gemeinsame Sperre, Zustände,
 * keine automatische Wiederholung fehlgeschlagener Migrationen.
 *
 *   php bin/migrate.php            offene Migrationen einspielen
 *   php bin/migrate.php --status   nur Stand anzeigen
 * Exit-Codes: 0 ok, 1 fehlgeschlagen oder blockiert, 2 Sperre belegt
 */
define('LOG_SERVICE', 'cli');
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/migrate.php';

$opts = cli_opts($argv);
try {
    if (isset($opts['status'])) {
        foreach (migrations_status() as $m) {
            cli_out(sprintf('%s  %-10s %s', $m['version'], $m['state'], $m['filename'] ?? ''));
        }
        exit(0);
    }
    $r = migrations_run('cli');
    cli_out(sprintf('Migrationen: %d eingespielt, %d offen', count($r['applied'] ?? []), count($r['pending'] ?? [])));
    foreach ($r['applied'] ?? [] as $a) {
        cli_out('  eingespielt: ' . (is_array($a) ? ($a['version'] ?? json_encode($a)) : $a));
    }
    try {
        require_once dirname(__DIR__) . '/app/monitor.php';
        monitor_mark('deploy_last_migration_ok_at', mon_utc(monitor_now()));
        monitor_mark('deploy_last_migration_result', 'success:' . count($r['applied'] ?? []));
    } catch (Throwable $e) {
    }
    exit(0);
} catch (MigrationLockedException $e) {
    fwrite(STDERR, "Migration läuft bereits (Sperre belegt).\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration fehlgeschlagen oder blockiert: ' . $e->getMessage() . "\n");
    exit(1);
}
