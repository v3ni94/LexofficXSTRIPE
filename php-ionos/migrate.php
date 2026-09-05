<?php
/**
 * Ausstehende Datenbankmigrationen einspielen (manueller Aufruf).
 * Aufruf: migrate.php?token=<cron_token aus config.php>. Der Cron macht das
 * bei jedem Lauf automatisch; diese Seite dient dem sofortigen Einspielen
 * direkt nach einem Upload und zeigt den Stand aller Migrationen.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/audit.php';
require_once __DIR__ . '/app/migrate.php';

header('Content-Type: text/plain; charset=utf-8');
$expected = (string)config('cron_token');
if (strlen($expected) < 16 || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit("Zugriff verweigert.\n");
}
$res = migrations_apply('migrate.php');
echo "Datenbankmigrationen, Stand " . date('d.m.Y H:i:s') . "\n\n";
foreach ($res['applied'] as $f) { echo "EINGESPIELT  $f\n"; }
foreach ($res['skipped'] as $f) { echo "ÜBERSPRUNGEN $f (kein Marker hinterlegt, manuell einspielen)\n"; }
if ($res['failed']) { echo "FEHLER       {$res['failed']}: {$res['error']}\n"; }
echo "\nGesamtstand:\n";
foreach (migrations_status() as $m) {
    echo sprintf("  %s  %s\n", $m['state'] === 'applied' ? 'OK      ' : ($m['state'] === 'pending' ? 'OFFEN   ' : 'UNKLAR  '), $m['filename']);
}
