<?php
/**
 * Auslieferung der Dokumentation an Plattformadministratoren (Adminbereich, Versionen & Dokumentation).
 *
 * Liefert ausschliesslich Dateien aus app/docs-build/manifest.json (Allowlist) beziehungsweise aus einem
 * archivierten Stand unter shared/docs-archive/<id>/ (?archiv=<id>, eigenes Manifest). Zugriff je Datei nach
 * Klassifizierung (app/docs.php: technical nur fuer docs.technical_readers, admin fuer alle Plattformadministratoren).
 * Der Dateiname wird nur gegen das Manifest geprueft (exakter Abgleich), der aufgeloeste Pfad per realpath()
 * gegen das Verzeichnis (kein Directory Traversal). Jeder Abruf wird im Audit protokolliert.
 *
 * Relative Verweise innerhalb der HTML-Ansicht (suche.json, Kapitel-PDFs, Gesamt-PDF) laufen ueber den
 * Pfadparameter: admin-doc.php/<code>/index.html laedt "suche.json" als admin-doc.php/<code>/suche.json.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/docs.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_superadmin();

// Dateiname aus ?f= oder aus PATH_INFO (fuer relative Verweise der HTML-Ansicht)
$requested = (string)($_GET['f'] ?? '');
if ($requested === '' && !empty($_SERVER['PATH_INFO'])) {
    $requested = ltrim((string)$_SERVER['PATH_INFO'], '/');
}
if ($requested === '' || str_contains($requested, "\0") || str_contains($requested, '..')) {
    docs_fail(400, 'Kein gueltiger Dateiname angegeben.');
}

$archivId = (string)($_GET['archiv'] ?? '');
if ($archivId !== '') {
    $baseDir = docs_archive_path($archivId);
    if ($baseDir === null) {
        docs_fail(404, 'Archivstand nicht gefunden.');
    }
    $manifest = docs_manifest($baseDir);
    if ($manifest === null) {
        docs_fail(404, 'Archivstand ohne Manifest.');
    }
    docs_serve($baseDir, $manifest, $requested, $ctx, 'admin_doc_archive_download');
}

$baseDir = docs_build_dir();
if ($baseDir === null) {
    docs_fail(404, 'Noch nicht erzeugt (tools/build-docs.py, wird beim Deployment ausgefuehrt).');
}
$manifest = docs_manifest($baseDir);
if ($manifest === null) {
    docs_fail(404, 'Manifest der Dokumentation nicht gefunden.');
}
docs_serve($baseDir, $manifest, $requested, $ctx, 'admin_doc_download');
