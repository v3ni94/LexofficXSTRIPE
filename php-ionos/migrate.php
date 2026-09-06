<?php
/**
 * Ausstehende Datenbankmigrationen einspielen.
 *
 * Zwei Aufrufarten:
 *  1. Manuell im Browser: migrate.php?token=<cron_token aus config.php>
 *     Antwort als Text mit dem Stand aller Migrationen.
 *  2. Automatisch nach dem Upload (GitHub Actions, deploy.yml): POST mit dem
 *     Header "X-Migration-Token: <migration_token aus config.php>" (Rückfall:
 *     cron_token). Antwort als JSON, HTTP 200 nur bei vollständigem Erfolg:
 *     {"success": true, "applied": [...], "pending": [...]}.
 *
 * Der Cron spielt Migrationen zusätzlich bei jedem Lauf ein, dies hier dient
 * dem sofortigen Einspielen direkt nach einem Upload.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/audit.php';
require_once __DIR__ . '/app/migrate.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$wantsJson = $isPost || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$cronToken = (string)config('cron_token');
$migrationToken = trim((string)config('migration_token', ''));
$headerToken = trim((string)($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? ''));
$queryToken = (string)($_GET['token'] ?? '');

$authorized = false;
if ($headerToken !== '') {
    $authorized = ($migrationToken !== '' && strlen($migrationToken) >= 16 && hash_equals($migrationToken, $headerToken))
        || (strlen($cronToken) >= 16 && hash_equals($cronToken, $headerToken));
} elseif ($queryToken !== '' && !$isPost) {
    $authorized = strlen($cronToken) >= 16 && hash_equals($cronToken, $queryToken);
}

if (!$authorized) {
    http_response_code(403);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Zugriff verweigert.']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zugriff verweigert.\n";
    }
    exit;
}

$by = $isPost ? 'deploy' : 'migrate.php';
try {
    $res = migrations_apply($by);
    $status = migrations_status();
} catch (Throwable $e) {
    $res = ['applied' => [], 'failed' => 'migrate.php', 'error' => $e->getMessage(), 'skipped' => []];
    $status = [];
}
$pending = array_values(array_map(static fn(array $m): string => $m['filename'], array_filter($status, static fn(array $m): bool => $m['state'] !== 'applied')));
$success = $res['failed'] === null && !$pending;

header('Cache-Control: no-store');
if ($wantsJson) {
    http_response_code($success ? 200 : 500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'applied' => $res['applied'],
        'skipped' => $res['skipped'],
        'pending' => $pending,
        'failed'  => $res['failed'],
        'error'   => $res['error'],
        'time'    => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Datenbankmigrationen, Stand " . date('d.m.Y H:i:s') . "\n\n";
foreach ($res['applied'] as $f) { echo "EINGESPIELT  $f\n"; }
foreach ($res['skipped'] as $f) { echo "ÜBERSPRUNGEN $f (kein Marker hinterlegt, manuell einspielen)\n"; }
if ($res['failed']) { echo "FEHLER       {$res['failed']}: {$res['error']}\n"; }
echo "\nGesamtstand:\n";
foreach ($status as $m) {
    echo sprintf("  %s  %s\n", $m['state'] === 'applied' ? 'OK      ' : ($m['state'] === 'pending' ? 'OFFEN   ' : 'UNKLAR  '), $m['filename']);
}
