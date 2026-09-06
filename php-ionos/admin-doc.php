<?php
/**
 * Auslieferung der technischen Dokumentation (Auftrag III) an Plattformadministratoren.
 *
 * Liefert ausschließlich Dateien aus app/docs-build/, die im dortigen manifest.json gelistet
 * sind (Allowlist). Der angeforderte Dateiname wird nur gegen die Einträge des Manifests
 * geprüft (exakter Abgleich, kein Aufbau eines Pfades aus Nutzereingaben); zusätzlich wird der
 * aufgelöste Pfad per realpath() auf das docs-build-Verzeichnis geprüft (kein Directory Traversal).
 * Der Ordner app/docs-build ist per .gitignore ausgeschlossen und liegt unterhalb von app/, also
 * ohnehin nicht über die .htaccess-Regeln erreichbar; dieses Skript ist der einzige vorgesehene Weg.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

require_superadmin();

function admin_doc_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$docsDir = realpath(__DIR__ . '/app/docs-build');
if ($docsDir === false) {
    admin_doc_fail(404, 'Noch nicht erzeugt (tools/build-docs.py, wird beim Deployment ausgeführt).');
}

$manifestPath = $docsDir . '/manifest.json';
if (!is_file($manifestPath)) {
    admin_doc_fail(404, 'Manifest der Dokumentation nicht gefunden.');
}
$manifest = json_decode((string)@file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    admin_doc_fail(500, 'Manifest der Dokumentation ist nicht lesbar.');
}

$requested = (string)($_GET['f'] ?? '');
if ($requested === '' || str_contains($requested, "\0")) {
    admin_doc_fail(400, 'Kein Dateiname angegeben.');
}

// Das Manifest selbst darf zusätzlich zu den gelisteten Dateien abgerufen werden (kind "json").
if ($requested === 'manifest.json') {
    $entry = ['name' => 'manifest.json', 'kind' => 'json'];
} else {
    $entry = null;
    foreach ((array)($manifest['files'] ?? []) as $f) {
        if (($f['name'] ?? '') === $requested) {
            $entry = $f;
            break;
        }
    }
}
if ($entry === null) {
    admin_doc_fail(404, 'Diese Datei ist nicht im Manifest der Dokumentation gelistet.');
}

$name = (string)$entry['name'];
$path = realpath($docsDir . '/' . $name);
if ($path === false || !str_starts_with($path, $docsDir . DIRECTORY_SEPARATOR) || basename($path) !== basename($name)) {
    admin_doc_fail(404, 'Datei nicht gefunden.');
}
if (!is_file($path)) {
    admin_doc_fail(404, 'Datei nicht gefunden.');
}

$kind = (string)($entry['kind'] ?? '');
if ($kind === '') {
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $kind = ['pdf' => 'pdf', 'html' => 'html', 'svg' => 'svg', 'json' => 'json'][$ext] ?? '';
}
$contentTypes = [
    'pdf'  => 'application/pdf',
    'html' => 'text/html; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'json' => 'application/json; charset=utf-8',
];
if (!isset($contentTypes[$kind])) {
    admin_doc_fail(415, 'Dieser Dateityp wird nicht ausgeliefert.');
}

audit_log(null, current_user(), 'admin_doc_download', 'doc', $name, []);

header('Content-Type: ' . $contentTypes[$kind]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($kind === 'html' || $kind === 'svg') {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src data:");
}
if ($kind === 'html' || $kind === 'pdf') {
    header('Content-Disposition: inline; filename="' . str_replace(['"', '/', '\\'], '', basename($name)) . '"');
}
header('Content-Length: ' . (string)filesize($path));
readfile($path);
