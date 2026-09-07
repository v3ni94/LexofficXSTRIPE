<?php
/**
 * Adminbereich: Rechtsdokumente (Auftragsverarbeitungsvertrag, Verschwiegenheitsvereinbarung, weitere).
 * Nur Plattformadministratoren mit aktiver 2FA. Anlegen neuer Fassungen (aus Entwurfsvorlage oder eigenem Text),
 * Veroeffentlichen und Zurueckziehen verlangen einen aktuellen 2FA-Code und schreiben ins Audit. Veroeffentlichen
 * ist gesperrt, solange der Text "[Platzhalter" enthaelt. Veroeffentlichte Fassungen werden nie geloescht.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/legal.php';
require_once __DIR__ . '/app/legal_drafts.php';

$ctx = require_superadmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
        if ($action === 'import_draft') {
            $idx = (int)($_POST['draft'] ?? -1);
            $drafts = legal_drafts();
            if (!isset($drafts[$idx])) {
                throw new RuntimeException('Unbekannte Vorlage.');
            }
            $d = $drafts[$idx];
            $id = legal_document_create($ctx, $d['code'], $d['version'], $d['title'], $d['summary'], $d['body_md'], $d['required_for']);
            flash_set('success', 'Vorlage als unveröffentlichte Fassung übernommen. Vor der Veröffentlichung Text prüfen und Platzhalter ersetzen.');
            redirect('admin-legal.php?dok=' . $id);
        } elseif ($action === 'create') {
            $id = legal_document_create($ctx, (string)($_POST['doc_code'] ?? ''), (string)($_POST['version'] ?? ''), (string)($_POST['title'] ?? ''),
                (string)($_POST['summary'] ?? ''), (string)($_POST['body_md'] ?? ''), (string)($_POST['required_for'] ?? 'all'));
            flash_set('success', 'Neue Fassung angelegt (unveröffentlicht).');
            redirect('admin-legal.php?dok=' . $id);
        } elseif ($action === 'publish' || $action === 'retire') {
            legal_document_publish($ctx, (string)($_POST['document_id'] ?? ''), $action === 'publish');
            flash_set('success', $action === 'publish' ? 'Fassung veröffentlicht. Ältere Fassungen desselben Dokuments sind zurückgezogen; Firmen sehen die neue Fassung zur Zustimmung.' : 'Fassung zurückgezogen.');
        } elseif ($action === 'delete') {
            legal_document_delete($ctx, (string)($_POST['document_id'] ?? ''));
            flash_set('success', 'Unveröffentlichte Fassung gelöscht.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('admin-legal.php' . (!empty($_POST['document_id']) && $action !== 'delete' ? '?dok=' . (string)$_POST['document_id'] : ''));
}

$docs = legal_documents_all();
$active = legal_active_documents();
$missing = legal_missing_counts();
$view = isset($_GET['dok']) ? legal_document_load((string)$_GET['dok']) : null;
$editBase = $view; // Vorbelegung des Formulars "neue Fassung" aus einer bestehenden

layout_header('Rechtsdokumente', $ctx);
?>
<h1>Rechtsdokumente</h1>
<p class="page-sub">Auftragsverarbeitungsvertrag, Verschwiegenheitsvereinbarung und weitere Dokumente mit Zustimmungsnachweis je Firma. Veröffentlichte Fassungen sehen die Firmen unter Rechtliches; Pflichtdokumente werden im Dashboard angemahnt und der AVV bei der Registrierung abgeschlossen.</p>
<?= layout_subnav(['admin' => ['label' => 'Plattform-Administration', 'href' => 'admin.php', 'ext' => true], 'support' => ['label' => 'Support', 'href' => 'admin-support.php', 'ext' => true], 'system' => ['label' => 'System', 'href' => 'admin-system.php', 'ext' => true], 'legal' => ['label' => 'Rechtsdokumente', 'href' => 'admin-legal.php']], 'legal', 'Adminbereiche') ?>

<div class="card">
    <h2>Aktuelle Fassungen</h2>
    <table class="table">
        <thead><tr><th>Dokument</th><th>Fassung</th><th>Geltung</th><th>Veröffentlicht</th><th>Firmen ohne Zustimmung</th></tr></thead>
        <tbody>
        <?php foreach (LEGAL_CODES as $code => $label): $d = $active[$code] ?? null; ?>
            <tr><td><?= e($label) ?></td>
                <td><?= $d ? '<a href="admin-legal.php?dok=' . e($d['id']) . '">' . e($d['version']) . '</a>' : '<span class="badge badge-danger">keine veröffentlicht</span>' ?></td>
                <td><?= $d ? e($d['required_for']) : '' ?></td>
                <td><?= $d ? e(format_datetime($d['published_at'])) : '' ?></td>
                <td><?= $d ? (int)($missing[$code] ?? 0) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint">Ohne veröffentlichten AVV fragt die Registrierung keinen AVV ab und die Firmen sehen unter Rechtliches nur AGB und Datenschutzerklärung. Entwurfstexte sind Vorlagen für die anwaltliche Prüfung; veröffentlichen erst nach Freigabe.</p>
</div>

<div class="card">
    <h2>Vorlagen übernehmen</h2>
    <p class="hint">Aus <code>app/legal_drafts.php</code>. Die Übernahme legt eine unveröffentlichte Fassung an; Platzhalter müssen vor der Veröffentlichung ersetzt werden (ggf. über „Neue Fassung“ mit angepasstem Text).</p>
    <?php foreach (legal_drafts() as $i => $d): ?>
    <form method="post" class="form-inline" style="margin-bottom:8px">
        <?= csrf_field() ?><input type="hidden" name="action" value="import_draft"><input type="hidden" name="draft" value="<?= (int)$i ?>">
        <span><strong><?= e($d['title']) ?></strong> (<?= e($d['code']) ?>, Fassung <?= e($d['version']) ?>, <?= strpos($d['body_md'], '[Platzhalter') !== false ? 'enthält Platzhalter' : 'ohne Platzhalter' ?>)</span>
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA-Code" style="width:110px">
        <button type="submit" class="btn">Als Fassung übernehmen</button>
    </form>
    <?php endforeach; ?>
</div>

<?php if ($view): ?>
<div class="card" id="dok">
    <h2><?= e($view['title']) ?> <span class="badge <?= $view['published_at'] && !$view['retired_at'] ? 'badge-success' : ($view['retired_at'] ? 'badge-neutral' : 'badge-info') ?>"><?= $view['published_at'] && !$view['retired_at'] ? 'veröffentlicht' : ($view['retired_at'] ? 'zurückgezogen' : 'unveröffentlicht') ?></span></h2>
    <dl class="legal-data">
        <dt>Code / Fassung</dt><dd><?= e($view['code']) ?> / <?= e($view['version']) ?></dd>
        <dt>Geltung</dt><dd><?= e($view['required_for']) ?></dd>
        <dt>Angelegt</dt><dd><?= e(format_datetime($view['created_at'])) ?> durch <?= e((string)$view['created_by']) ?></dd>
        <?php if ($view['published_at']): ?><dt>Veröffentlicht</dt><dd><?= e(format_datetime($view['published_at'])) ?></dd><?php endif; ?>
        <?php if ($view['retired_at']): ?><dt>Zurückgezogen</dt><dd><?= e(format_datetime($view['retired_at'])) ?></dd><?php endif; ?>
    </dl>
    <form method="post" class="form-inline">
        <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= e($view['id']) ?>">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA-Code" style="width:110px">
        <?php if (!$view['published_at'] || $view['retired_at']): ?>
            <button type="submit" name="action" value="publish" class="btn btn-primary" <?= strpos($view['body_md'], '[Platzhalter') !== false ? 'disabled title="Text enthält Platzhalter"' : '' ?>>Veröffentlichen</button>
        <?php else: ?>
            <button type="submit" name="action" value="retire" class="btn btn-danger" onclick="return confirm('Fassung zurückziehen? Firmen sehen dann kein Dokument dieses Typs mehr, bis eine neue Fassung veröffentlicht ist.');">Zurückziehen</button>
        <?php endif; ?>
        <?php if (!$view['published_at']): ?>
            <button type="submit" name="action" value="delete" class="btn btn-secondary" onclick="return confirm('Unveröffentlichte Fassung löschen?');">Löschen</button>
        <?php endif; ?>
    </form>
    <h3>Text</h3>
    <div class="legal-text"><?= legal_render_md((string)$view['body_md']) ?></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Neue Fassung anlegen</h2>
    <p class="hint">Eingeschränktes Markdown: Zeilen mit <code>#</code>, <code>##</code>, <code>###</code> als Überschriften, <code>- </code> für Aufzählungen, <code>1. </code> für Nummerierungen, Leerzeile trennt Absätze, <code>**fett**</code>. Kein HTML, keine Links. Text mit <code>[Platzhalter: ...]</code> lässt sich nicht veröffentlichen.</p>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <label>Code <input type="text" name="doc_code" required value="<?= e($editBase['code'] ?? 'avv') ?>" placeholder="avv"></label>
            <label>Fassung <input type="text" name="version" required value="<?= e($editBase ? $editBase['version'] . '-neu' : date('Y-m')) ?>"></label>
            <label>Geltung <select name="required_for">
                <?php foreach (['all' => 'Jede Firma', 'secrecy' => 'Nur Berufsgeheimnisträger', 'none' => 'Nur Information'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= ($editBase['required_for'] ?? 'all') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?></select></label>
        </div>
        <label>Titel <input type="text" name="title" required maxlength="200" value="<?= e($editBase['title'] ?? '') ?>"></label>
        <label>Kurzbeschreibung <input type="text" name="summary" maxlength="500" value="<?= e((string)($editBase['summary'] ?? '')) ?>"></label>
        <label>Text <textarea name="body_md" rows="24" required><?= e((string)($editBase['body_md'] ?? '')) ?></textarea></label>
        <label>2FA-Code <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" style="width:110px"></label>
        <button type="submit" class="btn btn-primary">Fassung anlegen (unveröffentlicht)</button>
    </form>
</div>

<div class="card">
    <h2>Alle Fassungen</h2>
    <table class="table">
        <thead><tr><th>Code</th><th>Fassung</th><th>Titel</th><th>Status</th><th>Angelegt</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
            <tr><td><?= e($d['code']) ?></td><td><a href="admin-legal.php?dok=<?= e($d['id']) ?>"><?= e($d['version']) ?></a></td><td><?= e($d['title']) ?></td>
                <td><?= $d['published_at'] && !$d['retired_at'] ? 'veröffentlicht' : ($d['retired_at'] ? 'zurückgezogen' : 'unveröffentlicht') ?></td><td><?= e(format_datetime($d['created_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="5" class="hint">Noch keine Fassungen. Oben eine Vorlage übernehmen.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php layout_footer($ctx); ?>
