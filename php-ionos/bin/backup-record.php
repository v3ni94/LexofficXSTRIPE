<?php
/**
 * Nimmt das Ergebnis eines Datenbank-Backups entgegen (Aufruf durch deploy/vps/backup/backup.sh
 * per "docker exec <php-container> php bin/backup-record.php ...") und schreibt es als
 * Monitoring-Ereignis, damit der Adminbereich System (Reiter Server) den letzten Sicherungslauf
 * anzeigen kann.
 *
 *   php bin/backup-record.php <ok|fail> <bytes> <sha256>
 *
 * bytes: Groesse der gesicherten (ggf. verschluesselten) Datei in Byte, 0 wenn unbekannt (fail).
 * sha256: Pruefsumme der gesicherten Datei, "-" wenn keine gebildet wurde (fail).
 * Es werden keine Dateipfade, Zugangsdaten oder Hostnamen entgegengenommen oder gespeichert.
 */
define('LOG_SERVICE', 'cli');
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/monitor.php';

$opts = cli_opts($argv);
// Positionsargumente stehen nach dem Scriptnamen; cli_opts() wertet nur --name=wert/--flag aus,
// daher hier direkt aus $argv lesen (argv[0] = Scriptname).
$status = $argv[1] ?? '';
$bytesArg = $argv[2] ?? '0';
$sha256 = $argv[3] ?? '-';

if (!in_array($status, ['ok', 'fail'], true)) {
    fwrite(STDERR, "Nutzung: php bin/backup-record.php <ok|fail> <bytes> <sha256>\n");
    exit(2);
}

$bytes = max(0, (int)$bytesArg);
$megabytes = round($bytes / 1048576, 2);
$sha256Short = preg_match('/^[a-f0-9]{64}$/i', (string)$sha256) ? substr($sha256, 0, 12) : null;

monitor_event(
    'backup',
    $status === 'ok' ? 'ok' : 'fail',
    null,
    $sha256Short,          // Kategorie: nur die ersten 12 Zeichen der Pruefsumme, zur Wiedererkennung
    'internal',
    (24 + 6) * 3600,       // gueltig bis deutlich nach dem naechsten taeglichen Lauf (Karenz bei Verzoegerung)
    $megabytes,
    'MB'
);

if ($status === 'ok') {
    monitor_mark('backup_last_ok_at', mon_utc(monitor_now()));
}

cli_out(sprintf('Backup-Ergebnis erfasst: %s, %.2f MB', $status, $megabytes));
exit(0);
