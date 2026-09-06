<?php
/**
 * Minimale dynamische Gesundheitsantwort für das Monitoring (intern und für externe Prüfer).
 * Liefert ausschließlich: PHP läuft (dynamische Antwort mit aktuellem UTC-Zeitstempel), Datenbank
 * per SELECT 1 lesbar. Keine Versionen, Pfade, Hostnamen oder Zähler. Kein Login, keine Migration,
 * keine Sitzung, kein Cache. HTTP 200 bei PHP und Datenbank in Ordnung, sonst 503.
 */
declare(strict_types=1);
define('SKIP_SESSION', true);
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$dbOk = false;
try {
    $stmt = db()->query('SELECT 1');
    $dbOk = (int)$stmt->fetchColumn() === 1;
} catch (Throwable $e) {
    $dbOk = false;
}

http_response_code($dbOk ? 200 : 503);
echo json_encode([
    'status' => $dbOk ? 'ok' : 'degraded',
    'php'    => true,
    'db'     => $dbOk,
    'time'   => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_UNESCAPED_SLASHES);
