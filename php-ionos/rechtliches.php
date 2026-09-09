<?php
/**
 * Firma: Rechtliches. Vertragsdokumente mit Zustimmungsnachweis: Auftragsverarbeitungsvertrag (Art. 28 DSGVO),
 * Verschwiegenheitsvereinbarung fuer Berufsgeheimnistraeger (§ 203 StGB) und weitere veroeffentlichte Dokumente.
 * Sichtbar fuer alle Mitglieder; akzeptieren und die Angabe zur Verschwiegenheitspflicht aendern duerfen Inhaber
 * und Administratoren. Jede Zustimmung wird mit Benutzer, Zeitpunkt und Fassung gespeichert und protokolliert.
 * ?dok=<id> zeigt den vollstaendigen Text einer veroeffentlichten oder bereits akzeptierten Fassung (druckbar).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/legal.php';

$ctx = require_login();
$tenantId = (string)$ctx['org_id'];
$canEdit = can_manage_settings($ctx);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'accept') {
            if (($_POST['confirm'] ?? '') !== '1') {
                throw new RuntimeException('Bitte bestätigen Sie, dass Sie das Dokument gelesen haben und für die Firma abschließen.');
            }
            legal_accept($ctx, (string)($_POST['document_id'] ?? ''), 'backend');
            flash_set('success', 'Dokument akzeptiert. Der Nachweis ist gespeichert.');
        } elseif ($action === 'secrecy') {
            legal_set_secrecy($ctx, !empty($_POST['professional_secrecy']), (string)($_POST['professional_secrecy_kind'] ?? ''));
            flash_set('success', 'Angabe zur Verschwiegenheitspflicht gespeichert.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('rechtliches.php');
}

$status = legal_status_for_org($tenantId);
$acceptances = legal_acceptances_for_org($tenantId);
$orgSt = db()->prepare('SELECT professional_secrecy, professional_secrecy_kind FROM organizations WHERE id = ?');
$orgSt->execute([$tenantId]);
$org = $orgSt->fetch() ?: ['professional_secrecy' => 0, 'professional_secrecy_kind' => null];

// Einzelansicht: nur veroeffentlichte Fassungen oder Fassungen, die diese Firma akzeptiert hat.
$view = null;
if (isset($_GET['dok'])) {
    $doc = legal_document_load((string)$_GET['dok']);
    if ($doc) {
        $accepted = false;
        foreach ($acceptances as $a) {
            if ($a['document_id'] === $doc['id']) {
                $accepted = true;
            }
        }
        if ($doc['published_at'] !== null || $accepted) {
            $view = $doc;
        }
    }
    if ($view === null) {
        http_response_code(404);
    }
}

layout_header($view ? $view['title'] : 'Rechtliches', $ctx);
if ($view): ?>
<p><a href="rechtliches.php">Zurück zu Rechtliches</a> · <a href="#" onclick="window.print(); return false;">Drucken</a></p>
<h1><?= e($view['title']) ?></h1>
<p class="page-sub">Fassung <?= e($view['version']) ?><?= $view['published_at'] ? ', veröffentlicht am ' . e(format_date($view['published_at'])) : '' ?>
<?php foreach ($acceptances as $a): if ($a['document_id'] === $view['id']): ?>
    · Akzeptiert am <?= e(format_datetime($a['accepted_at'])) ?> durch <?= e($a['user_email']) ?>
<?php endif; endforeach; ?></p>
<div class="card legal-text"><?= legal_render_md((string)$view['body_md']) ?></div>
<?php else: ?>
<h1>Rechtliches</h1>
<p class="page-sub">Vertragsdokumente zwischen <?= e($ctx['org_name']) ?> und der Müller Holding AG als Betreiberin von <?= e(product_name()) ?>. Jede Zustimmung wird mit Fassung, Zeitpunkt und Benutzer nachgewiesen.</p>

<?php if (!$status): ?>
<div class="card"><p class="hint">Derzeit sind keine Dokumente zur Zustimmung veröffentlicht. Es gelten die bei der Registrierung akzeptierten
    <a href="<?= e(marketing_url('/agb')) ?>" target="_blank" rel="noopener">AGB</a> und die
    <a href="<?= e(marketing_url('/datenschutz')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>.</p></div>
<?php endif; ?>

<?php foreach ($status as $code => $s): $doc = $s['doc']; $acc = $s['acceptance']; ?>
<div class="card" id="dok-<?= e($code) ?>">
    <h2><?= e($doc['title']) ?></h2>
    <?php if ($doc['summary']): ?><p><?= e($doc['summary']) ?></p><?php endif; ?>
    <dl class="legal-data">
        <dt>Fassung</dt><dd><?= e($doc['version']) ?>, veröffentlicht am <?= e(format_date($doc['published_at'])) ?> · <a href="rechtliches.php?dok=<?= e($doc['id']) ?>">Vollständigen Text anzeigen</a></dd>
        <dt>Geltung</dt><dd><?= $doc['required_for'] === 'all' ? 'Für jede Firma erforderlich' : ($doc['required_for'] === 'secrecy' ? 'Erforderlich für Firmen mit beruflicher Verschwiegenheitspflicht (§ 203 StGB)' : 'Zur Information') ?></dd>
        <dt>Status</dt>
        <dd>
        <?php if ($acc): ?>
            <span class="badge badge-success">Akzeptiert am <?= e(format_datetime($acc['accepted_at'])) ?> durch <?= e($acc['user_email']) ?></span>
        <?php elseif ($s['accepted_other_version']): ?>
            <span class="badge badge-danger">Neue Fassung, Zustimmung erforderlich</span>
            <span class="hint">Zuletzt akzeptiert: Fassung <?= e($s['accepted_other_version']['doc_version']) ?> am <?= e(format_datetime($s['accepted_other_version']['accepted_at'])) ?> durch <?= e($s['accepted_other_version']['user_email']) ?>.</span>
        <?php elseif ($s['required']): ?>
            <span class="badge badge-danger">Zustimmung erforderlich</span>
        <?php else: ?>
            <span class="badge badge-neutral">Nicht erforderlich</span>
        <?php endif; ?>
        </dd>
    </dl>
    <?php if (!$acc && $canEdit && $doc['required_for'] !== 'none'): ?>
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="accept">
        <input type="hidden" name="document_id" value="<?= e($doc['id']) ?>">
        <label class="checkbox-label"><input type="checkbox" name="confirm" value="1" required>
            <span>Ich habe die Fassung <?= e($doc['version']) ?> gelesen und schließe das Dokument im Namen von <?= e($ctx['org_name']) ?> ab.</span></label>
        <button type="submit" class="btn btn-primary">Akzeptieren</button>
    </form>
    <?php elseif (!$acc && !$canEdit): ?>
    <p class="hint">Akzeptieren können nur Inhaber und Administratoren der Firma.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card" id="verschwiegenheit">
    <h2>Berufliche Verschwiegenheitspflicht (§ 203 StGB)</h2>
    <p>Unterliegt Ihre Firma einer beruflichen Verschwiegenheitspflicht, etwa als Rechtsanwalt, Steuerberater, Wirtschaftsprüfer, Notar, Arzt oder Apotheker, benötigt die Einbindung eines externen Dienstleisters, der Zugang zu geschützten Daten erhalten kann, eine Verpflichtung des Dienstleisters zur Verschwiegenheit. Mit dieser Angabe wird Ihnen die entsprechende Vereinbarung zur Zustimmung angezeigt.</p>
    <?php if ($canEdit): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="secrecy">
        <label class="checkbox-label"><input type="checkbox" name="professional_secrecy" value="1" <?= (int)$org['professional_secrecy'] === 1 ? 'checked' : '' ?>>
            <span>Unsere Firma unterliegt einer beruflichen Verschwiegenheitspflicht nach § 203 StGB.</span></label>
        <label>Berufsgruppe (optional)
            <input type="text" name="professional_secrecy_kind" maxlength="80" value="<?= e((string)($org['professional_secrecy_kind'] ?? '')) ?>" placeholder="z. B. Steuerberatung"></label>
        <button type="submit" class="btn">Speichern</button>
    </form>
    <?php else: ?>
    <p class="hint">Aktuelle Angabe: <?= (int)$org['professional_secrecy'] === 1 ? 'Verschwiegenheitspflicht angegeben' . ($org['professional_secrecy_kind'] ? ' (' . e((string)$org['professional_secrecy_kind']) . ')' : '') : 'keine Verschwiegenheitspflicht angegeben' ?>. Ändern können Inhaber und Administratoren.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/app/consent.php'; $consents = consent_list_for_org($tenantId); ?>
<div class="card" id="zustimmungen">
    <h2>Zustimmungen zu AGB und Datenschutzerklärung</h2>
    <?php if (!$consents): ?>
        <p class="hint">Für diese Firma liegt noch kein gespeicherter Zustimmungsnachweis vor (Registrierungen vor Version 4.34 haben AGB und Datenschutzerklärung im Formular bestätigt; der Zeitpunkt ist der der Registrierung).</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Gegenstand</th><th>Fassung</th><th>Akzeptiert am</th><th>Durch</th><th>Weg</th></tr></thead>
            <tbody>
            <?php foreach ($consents as $c): ?>
                <tr><td><?= e(consent_subject_label((string)$c['subject'])) ?></td><td><?= e((string)$c['version']) ?></td><td><?= e(format_datetime($c['accepted_at'])) ?> UTC</td><td><?= e((string)$c['user_email']) ?></td><td><?= e(consent_method_label((string)$c['method'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">Die Fassungen sind in der Dokumentation der Einwilligungstexte archiviert. Ihre persönlichen Zustimmungen sehen Sie auch unter Sicherheit.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Weitere Dokumente</h2>
    <ul>
        <li><a href="<?= e(marketing_url('/agb')) ?>" target="_blank" rel="noopener">Allgemeine Geschäftsbedingungen</a>, bei der Registrierung akzeptiert</li>
        <li><a href="<?= e(marketing_url('/datenschutz')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a></li>
        <li><a href="<?= e(marketing_url('/impressum')) ?>" target="_blank" rel="noopener">Impressum der Betreiberin</a></li>
    </ul>
</div>

<?php if ($acceptances): ?>
<div class="card">
    <h2>Nachweise</h2>
    <table class="table">
        <thead><tr><th>Dokument</th><th>Fassung</th><th>Akzeptiert am</th><th>Durch</th><th>Weg</th></tr></thead>
        <tbody>
        <?php foreach ($acceptances as $a): ?>
            <tr><td><a href="rechtliches.php?dok=<?= e($a['document_id']) ?>"><?= e($a['title']) ?></a></td><td><?= e($a['version']) ?></td><td><?= e(format_datetime($a['accepted_at'])) ?></td><td><?= e($a['user_email']) ?></td>
                <td><?= $a['method'] === 'registration' ? 'Registrierung' : ($a['method'] === 'admin' ? 'Betreiber' : 'Backend') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php layout_footer($ctx); ?>
