<?php
/**
 * Auslieferung eines hochgeladenen Mandatsdokuments nach Mandantenprüfung.
 * Nur angemeldete Nutzer der Firma, zu der die Datei gehört. Kein Caching,
 * kein Sniffing; PDF und Bilder werden inline angezeigt, ?download=1 erzwingt Download.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/mandate_files.php';

$ctx = require_login();
$file = mandate_file_load($ctx['org_id'], (string)($_GET['id'] ?? ''));
if (!$file) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}
$path = mandate_file_path($file);
if (!is_file($path)) {
    http_response_code(410);
    exit('Die Datei ist auf dem Server nicht mehr vorhanden.');
}
audit_log($ctx['org_id'], $ctx, 'mandate_file_viewed', 'mandate_file', $file['id'], ['name' => $file['original_name']]);

$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']) ?: 'mandat';
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'; sandbox');
readfile($path);
