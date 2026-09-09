<?php
/**
 * Benutzerhandbuch fuer angemeldete Kunden: liefert ausschliesslich das Dokument mit Zugriffsstufe "customer"
 * aus app/docs-build (manifest.json). Keine Adminunterlagen, keine Entwicklerdokumentation, kein Archiv.
 * Aufruf: handbuch.php (Uebersicht), handbuch.php/kunden/index.html (Webansicht), handbuch.php?f=kunden.pdf (PDF).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/docs.php';

$ctx = require_login();
$baseDir = docs_build_dir();
$manifest = $baseDir ? docs_manifest($baseDir) : null;
$doc = null;
foreach ((array)($manifest['documents'] ?? []) as $d) {
    if (($d['access'] ?? '') === 'customer') {
        $doc = $d;
        break;
    }
}

$requested = (string)($_GET['f'] ?? '');
if ($requested === '' && !empty($_SERVER['PATH_INFO'])) {
    $requested = ltrim((string)$_SERVER['PATH_INFO'], '/');
}
if ($requested !== '') {
    if ($manifest === null || $doc === null || str_contains($requested, '..') || str_contains($requested, "\0")) {
        docs_fail(404, 'Handbuch nicht verfuegbar.');
    }
    $entry = docs_manifest_entry($manifest, $requested);
    if ($entry === null || ($entry['access'] ?? '') !== 'customer' || ($entry['doc'] ?? '') !== ($doc['code'] ?? '')) {
        docs_fail(404, 'Diese Datei gehoert nicht zum Benutzerhandbuch.');
    }
    docs_serve($baseDir, $manifest, $requested, $ctx, 'handbuch_download');
}

layout_header('Benutzerhandbuch', $ctx);
?>
<h1>Benutzerhandbuch</h1>
<p class="page-sub">Einrichtung und tägliche Nutzung von <?= e(product_name()) ?>: Registrierung, Verbindungen, Synchronisation, Mandate, Lastschriften, Status, Abonnement, Sicherheit.</p>
<?php if ($doc === null): ?>
<div class="card"><p class="hint">Das Handbuch ist derzeit nicht verfügbar. Bitte nutzen Sie das <a href="hilfe.php">Hilfe-Center</a>.</p></div>
<?php else: ?>
<div class="card">
    <h2><?= e($doc['title']) ?></h2>
    <dl class="legal-data">
        <dt>Softwarestand</dt><dd>Version <?= e((string)($manifest['version'] ?? '')) ?></dd>
        <dt>Dokumentrevision</dt><dd><?= e((string)$doc['revision']) ?> vom <?= e((string)$doc['revision_date']) ?></dd>
        <dt>Kapitel</dt><dd><?= count((array)($doc['chapters'] ?? [])) ?></dd>
    </dl>
    <p><a class="btn btn-primary" href="handbuch.php/<?= e($doc['html']) ?>" target="_blank" rel="noopener">Im Browser lesen</a>
       <a class="btn" href="handbuch.php?f=<?= e(rawurlencode((string)$doc['pdf'])) ?>">Als PDF herunterladen</a></p>
    <?php if (!empty($doc['chapters'])): ?>
    <h3>Kapitel als PDF</h3>
    <ul>
    <?php foreach ((array)$doc['chapters'] as $ch): ?>
        <li><a href="handbuch.php?f=<?= e(rawurlencode((string)$ch['pdf'])) ?>"><?= e((string)$ch['title']) ?></a></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
