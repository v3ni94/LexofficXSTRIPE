<?php
/**
 * Datenendpunkt des Adminbereichs "System": liefert das gespeicherte Kopf-Fragment (HTML) für die
 * Aktualisierung alle 30 Sekunden. Gleiche serverseitige Autorisierung wie die Seite; keine neuen
 * Prüfungen, keine Geheimnisse.
 */
define('SKIP_REQUEST_METRICS', true); // Polling-Abrufe verfälschen die Anfragekennzahlen nicht
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/monitor_view.php';

$ctx = current_user();
if (!$ctx || !(int)$ctx['is_superadmin'] || !(int)$ctx['totp_enabled']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kein Zugriff.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo monitor_render_head();
