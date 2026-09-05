<?php
/**
 * Profilbild ausliefern: nur für angemeldete Benutzer, nur eigenes Bild oder
 * Bilder von Mitgliedern derselben Firma (siehe app/profile.php).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/profile.php';

$ctx = current_user();
if (!$ctx) {
    http_response_code(403);
    exit;
}
$path = profile_avatar_path_for($ctx, (string)($_GET['u'] ?? $ctx['user_id']));
if ($path === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
