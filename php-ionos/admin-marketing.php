<?php
/**
 * Adminbereich: Marketing (Version 4.63). Empfaengerlisten (Systemliste aus Firmenaccounts, CSV-Import), Sperrliste,
 * Kampagnen im Design der Systemmails mit Vorschau, Testversand und Freigabe des Massenversands (2FA-Code),
 * Ratenbegrenzung je Sekunde und je 24 Stunden, Versandstatistik und SES-Ereignisse. Logik: app/marketing.php.
 *
 * Rechte: marketing.view (lesen, Export), marketing.manage (alles andere). Jede POST-Aktion prueft ihr Recht serverseitig,
 * CSRF und Audit ueberall; der Massenversand verlangt zusaetzlich einen frischen 2FA-Code (campaign_start).
 *
 *   admin-marketing.php                    Uebersicht, Einstellungen, Listen, Sperrliste, Kampagnen
 *   admin-marketing.php?liste=<id>         Empfaenger einer Liste       ?export=<id>   CSV der Liste
 *   admin-marketing.php?kampagne=<id>      Kampagne bearbeiten, Vorschau, Test, Freigabe, Versandzeilen
 *   admin-marketing.php?vorschau=<id>      HTML-Fassung fuer das Vorschaufenster (sandbox-iframe)
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/platform.php';
require_once __DIR__ . '/app/marketing.php';
require_once __DIR__ . '/app/queue.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_platform('marketing.view');
$canManage = platform_can($ctx, 'marketing.manage');
$pdo = db();

// --- Vorschau (nur HTML der Nachricht, im sandbox-iframe angezeigt) -------------------------------------------------
if (($_GET['vorschau'] ?? '') !== '') {
    $c = marketing_campaign_load((string)$_GET['vorschau']);
    if ($c === null) {
        http_response_code(404);
        exit('Kampagne nicht gefunden.');
    }
    $m = marketing_campaign_preview($c);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Security-Policy: default-src \'none\'; img-src https: data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    echo $m['html'];
    exit;
}

// --- CSV-Export einer Liste ----------------------------------------------------------------------------------------
if (($_GET['export'] ?? '') !== '') {
    $l = marketing_list_load((string)$_GET['export']);
    if ($l === null) {
        flash_set('error', 'Liste nicht gefunden.');
        redirect('admin-marketing.php');
    }
    $csv = marketing_list_export_csv($ctx, (string)$l['id']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="marketing-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$l['name']) . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $listId = (string)($_POST['list_id'] ?? '');
    $campaignId = (string)($_POST['campaign_id'] ?? '');
    $back = 'admin-marketing.php';
    if ($listId !== '' && !in_array($action, ['list_delete'], true)) {
        $back = 'admin-marketing.php?liste=' . urlencode($listId);
    }
    if ($campaignId !== '' && !in_array($action, ['campaign_delete'], true)) {
        $back = 'admin-marketing.php?kampagne=' . urlencode($campaignId);
    }
    try {
        if ($action !== '' && !platform_can($ctx, 'marketing.manage')) {
            throw new RuntimeException('Ihre Rolle hat für diese Aktion keine Berechtigung (marketing.manage).');
        }
        if ($action === 'rates_save') {
            $r = marketing_rates_save($ctx, (int)($_POST['per_second'] ?? 0), (int)($_POST['per_day'] ?? 0));
            flash_set('success', 'Ratenbegrenzung gespeichert: ' . $r['per_second'] . ' je Sekunde, ' . number_format($r['per_day'], 0, ',', '.') . ' je 24 Stunden.');
        } elseif ($action === 'list_create') {
            $source = (string)($_POST['source'] ?? 'import');
            $id = marketing_list_create($ctx, (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), $source, (string)($_POST['system_scope'] ?? '') ?: null);
            if ($source === 'system') {
                $z = marketing_list_sync_system($ctx, $id);
                flash_set('success', 'Systemliste angelegt und aufgebaut: ' . $z['neu'] . ' Empfänger, ' . $z['gesperrt'] . ' davon gesperrt.');
            } else {
                flash_set('success', 'Liste angelegt. Jetzt Empfänger per CSV importieren.');
            }
            $back = 'admin-marketing.php?liste=' . urlencode($id);
        } elseif ($action === 'list_import') {
            $csv = '';
            if (isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'] ?? '')) {
                if ((int)$_FILES['csv']['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('Die CSV-Datei ist größer als 5 MB.');
                }
                $csv = (string)file_get_contents($_FILES['csv']['tmp_name']);
            } elseif (trim((string)($_POST['csv_text'] ?? '')) !== '') {
                $csv = (string)$_POST['csv_text'];
            } else {
                throw new RuntimeException('Bitte eine CSV-Datei wählen oder Zeilen einfügen.');
            }
            $z = marketing_list_import($ctx, $listId, $csv, (string)($_POST['legal_basis'] ?? ''), (string)($_POST['legal_note'] ?? ''));
            flash_set('success', sprintf('Import abgeschlossen: %d Zeilen, %d neu, %d bereits vorhanden, %d ungültig, %d auf der Sperrliste (werden nie angeschrieben).',
                $z['zeilen'], $z['importiert'], $z['doppelt'], $z['ungueltig'], $z['gesperrt']));
        } elseif ($action === 'list_sync') {
            $z = marketing_list_sync_system($ctx, $listId);
            flash_set('success', sprintf('Systemliste aktualisiert: %d neu, %d bestehend, %d gesperrt, %d entfernt.', $z['neu'], $z['bestehend'], $z['gesperrt'], $z['entfernt']));
        } elseif ($action === 'list_delete') {
            marketing_list_delete($ctx, $listId);
            flash_set('success', 'Liste gelöscht.');
        } elseif ($action === 'recipient_remove') {
            marketing_recipient_remove($ctx, (string)($_POST['recipient_id'] ?? ''));
            flash_set('success', 'Empfänger aus der Liste entfernt. Soll die Adresse nie wieder angeschrieben werden, zusätzlich auf die Sperrliste setzen.');
        } elseif ($action === 'suppress_add') {
            marketing_suppress_manual($ctx, (string)($_POST['email'] ?? ''), (string)($_POST['note'] ?? ''));
            flash_set('success', 'Adresse auf die Sperrliste gesetzt. Sie erhält keine Werbenachrichten mehr, auch nicht nach erneutem Import.');
            $back = 'admin-marketing.php#sperrliste';
        } elseif ($action === 'suppress_remove') {
            marketing_unsuppress($ctx, (string)($_POST['email'] ?? ''), (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Sperre aufgehoben (protokolliert).');
            $back = 'admin-marketing.php#sperrliste';
        } elseif ($action === 'campaign_save') {
            $id = marketing_campaign_save($ctx, $campaignId !== '' ? $campaignId : null, $_POST);
            flash_set('success', 'Kampagne gespeichert. Vor der Freigabe: Vorschau prüfen und einen Testversand durchführen.');
            $back = 'admin-marketing.php?kampagne=' . urlencode($id);
        } elseif ($action === 'campaign_test') {
            marketing_campaign_test_send($ctx, $campaignId, (string)($_POST['test_to'] ?? ''));
            flash_set('success', 'Testnachricht übergeben (Betreff mit Vorsatz TEST). Bitte im Postfach prüfen, auch den Spam-Ordner und „Original anzeigen“.');
        } elseif ($action === 'campaign_start') {
            // Massenversand: aussenwirksam und nicht rueckholbar, deshalb Zweitbestaetigung per 2FA-Code (wie Support-Modus)
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $z = marketing_campaign_start($ctx, $campaignId);
            flash_set('success', sprintf('Kampagne freigegeben: %d Adressen, davon %d wegen Sperrliste übersprungen. Der Versand läuft im Hintergrund mit der eingestellten Ratenbegrenzung.', $z['total'], $z['skipped']));
        } elseif ($action === 'campaign_pause') {
            marketing_campaign_set_status($ctx, $campaignId, 'paused');
            flash_set('success', 'Kampagne angehalten.');
        } elseif ($action === 'campaign_resume') {
            marketing_campaign_set_status($ctx, $campaignId, 'queued');
            flash_set('success', 'Kampagne fortgesetzt.');
        } elseif ($action === 'campaign_cancel') {
            marketing_campaign_set_status($ctx, $campaignId, 'cancelled');
            flash_set('success', 'Kampagne abgebrochen; offene Adressen werden nicht mehr angeschrieben.');
        } elseif ($action === 'campaign_delete') {
            marketing_campaign_delete($ctx, $campaignId);
            flash_set('success', 'Kampagne gelöscht.');
            $back = 'admin-marketing.php#kampagnen';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect($back);
}

$profil = marketing_profile_status();
$rates = marketing_rates();
$heute = marketing_sent_last_24h();
$lists = marketing_lists();
$campaigns = marketing_campaigns();
$listById = [];
foreach ($lists as $l) {
    $listById[$l['id']] = $l;
}
$viewList = ($_GET['liste'] ?? '') !== '' ? marketing_list_load((string)$_GET['liste']) : null;
$viewCampaign = ($_GET['kampagne'] ?? '') !== '' ? (($_GET['kampagne'] === 'neu') ? ['id' => '', 'status' => 'draft', 'list_ids_arr' => []] : marketing_campaign_load((string)$_GET['kampagne'])) : null;
$statusBadge = static function (string $s): string {
    $cls = ['draft' => 'badge-neutral', 'queued' => 'badge-info', 'sending' => 'badge-info', 'paused' => 'badge-warn', 'sent' => 'badge-success', 'cancelled' => 'badge-danger'][$s] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . e(MARKETING_CAMPAIGN_STATUS[$s] ?? $s) . '</span>';
};

layout_header('Marketing', $ctx);
?>
<h1>Marketing</h1>
<p class="page-sub">Werbe- und Informationsnachrichten an eigene Kunden und importierte Geschäftskontakte im Design der Systemmails, über ein eigenes Versandprofil (Amazon SES). Die Kunden der Firmen (Rechnungsempfänger, Mandatsinhaber) sind keine Quelle.<?= $canManage ? '' : ' Ihre Rolle: nur lesend.' ?></p>
<?php $sub = ['uebersicht' => ['label' => 'Übersicht', 'href' => 'admin-marketing.php'], 'listen' => ['label' => 'Listen', 'href' => 'admin-marketing.php#listen'], 'sperrliste' => ['label' => 'Sperrliste', 'href' => 'admin-marketing.php#sperrliste'], 'kampagnen' => ['label' => 'Kampagnen', 'href' => 'admin-marketing.php#kampagnen']];
foreach (admin_subnav_items($ctx) as $k => $it) { if ($k !== 'marketing') { $sub[$k] = $it + ['ext' => true]; } }
echo layout_subnav($sub, 'uebersicht', 'Adminbereiche'); ?>

<?php if (!$profil['enabled']): ?>
<div class="flash flash-warn"><strong>Versandprofil mail_marketing nicht aktiv.</strong> Kampagnen lassen sich anlegen und in der Vorschau prüfen, aber weder testen noch versenden. Einrichtung in shared/config.php (Block mail_marketing) und Anleitung in der Dokumentation, Kapitel Marketing; danach <code>scripts/restart-workers.sh</code>.</div>
<?php elseif (!$profil['smtp_ok']): ?>
<div class="flash flash-warn"><strong>SMTP-Zugangsdaten des Marketingprofils unvollständig</strong> (Platzhalter in host, user oder pass). Ein Versand würde fehlschlagen.</div>
<?php endif; ?>

<?php if ($viewList): ?>
<?php $recips = marketing_recipients((string)$viewList['id'], (string)($_GET['q'] ?? '')); ?>
<div class="card" id="liste">
    <h2>Liste: <?= e($viewList['name']) ?> <small class="hint"><?= $viewList['source'] === 'system' ? 'Systemliste (' . ($viewList['system_scope'] === 'owners_admins' ? 'Inhaber und Administratoren' : 'Inhaber') . ' aktiver Firmen)' : 'Importliste' ?></small></h2>
    <p class="hint"><?= e((string)($viewList['description'] ?? '')) ?> <a href="admin-marketing.php#listen">Zurück zu den Listen</a> · <a href="admin-marketing.php?export=<?= e($viewList['id']) ?>">CSV-Export</a></p>
    <?php if ($canManage && $viewList['source'] === 'import'): ?>
    <form method="post" enctype="multipart/form-data" class="card" style="background: var(--color-neutral-bg);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="list_import">
        <input type="hidden" name="list_id" value="<?= e($viewList['id']) ?>">
        <h3>CSV importieren</h3>
        <p class="hint">Spalten werden an der Kopfzeile erkannt (E-Mail, Name oder Vorname und Nachname, Firma); ohne Kopfzeile gilt: Spalte 1 E-Mail, 2 Name, 3 Firma. Trennzeichen Semikolon, Komma oder Tabulator. Höchstens <?= number_format(MARKETING_IMPORT_MAX_ROWS, 0, ',', '.') ?> Zeilen, 5 MB. Adressen auf der Sperrliste werden aufgenommen, aber nie angeschrieben.</p>
        <div class="form-row">
            <div><label for="csv">CSV-Datei</label><input type="file" id="csv" name="csv" accept=".csv,text/csv,text/plain"></div>
            <div><label for="legal_basis">Rechtsgrundlage dieses Imports (Pflicht)</label>
                <select id="legal_basis" name="legal_basis" required>
                    <option value="">bitte wählen</option>
                    <?php foreach (MARKETING_LEGAL_BASES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <div class="form-row">
            <div><label for="legal_note">Vermerk zur Rechtsgrundlage (Herkunft der Adressen, Datum, Nachweis; bei „Sonstiges“ Pflicht)</label><input type="text" id="legal_note" name="legal_note" maxlength="255" placeholder="z. B. Kundenliste Buchhaltung Stand 01.09.2026"></div>
        </div>
        <div class="form-row"><div><label for="csv_text">oder Zeilen direkt einfügen</label><textarea id="csv_text" name="csv_text" rows="4" placeholder="email;name;firma"></textarea></div></div>
        <p class="hint">Hinweis zur Rechtslage: Werbung per E-Mail setzt nach dem Wettbewerbsrecht grundsätzlich eine vorherige Einwilligung voraus, auch gegenüber Unternehmen; ohne Einwilligung ist sie nur gegenüber eigenen Kunden für ähnliche Leistungen unter engen Bedingungen zulässig. Die Bewertung je Import trifft der Betreiber und dokumentiert sie hier; im Zweifel anwaltlich prüfen lassen.</p>
        <div class="form-actions"><button type="submit" class="btn">Importieren</button></div>
    </form>
    <?php elseif ($canManage): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="list_sync"><input type="hidden" name="list_id" value="<?= e($viewList['id']) ?>"><button type="submit" class="btn btn-sm btn-secondary">Aus Firmenaccounts aktualisieren</button></form>
    <?php endif; ?>
    <form method="get" class="inline-form" style="margin: 12px 0;"><input type="hidden" name="liste" value="<?= e($viewList['id']) ?>"><input type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="E-Mail, Name, Firma" style="max-width: 280px;"><button type="submit" class="btn btn-sm btn-secondary">Suchen</button></form>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>E-Mail</th><th>Name</th><th>Firma</th><th>Herkunft</th><th>Rechtsgrundlage</th><th>Status</th><th>Aufgenommen</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recips as $r): ?>
                <tr>
                    <td><?= e($r['email']) ?></td><td><?= e((string)($r['name'] ?? '')) ?></td><td><?= e((string)($r['company'] ?? '')) ?></td>
                    <td><?= e($r['source']) ?></td>
                    <td><?= e(MARKETING_LEGAL_BASES[$r['legal_basis']] ?? (string)$r['legal_basis']) ?><?= $r['legal_note'] ? '<br><small class="hint">' . e((string)$r['legal_note']) . '</small>' : '' ?></td>
                    <td><?= $r['status'] === 'active' ? '<span class="badge badge-success">aktiv</span>' : ($r['status'] === 'suppressed' ? '<span class="badge badge-danger">gesperrt</span>' : '<span class="badge badge-neutral">' . e((string)$r['status']) . '</span>') ?></td>
                    <td><?= format_datetime($r['created_at']) ?></td>
                    <td><?php if ($canManage): ?><form method="post" class="inline-form" onsubmit="return confirm('Empfänger aus der Liste entfernen?');"><?= csrf_field() ?><input type="hidden" name="action" value="recipient_remove"><input type="hidden" name="list_id" value="<?= e($viewList['id']) ?>"><input type="hidden" name="recipient_id" value="<?= e($r['id']) ?>"><button type="submit" class="btn btn-sm btn-ghost">Entfernen</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recips): ?><tr><td colspan="8" class="hint">Keine Empfänger<?= ($_GET['q'] ?? '') !== '' ? ' zur Suche' : '' ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Angezeigt werden bis zu 500 Einträge; der CSV-Export enthält alle.</p>
</div>
<?php endif; ?>

<?php if ($viewCampaign !== null): ?>
<?php $c = $viewCampaign; $isNew = $c['id'] === ''; $editable = $c['status'] === 'draft'; ?>
<div class="card" id="kampagne">
    <h2><?= $isNew ? 'Neue Kampagne' : 'Kampagne: ' . e($c['name']) . ' ' . $statusBadge((string)$c['status']) ?></h2>
    <p class="hint"><a href="admin-marketing.php#kampagnen">Zurück zu den Kampagnen</a>. Gestaltung übernimmt die zentrale Vorlage der Systemmails (Wortmarke, Goldakzent, Fußband mit Pflichtangaben, Abmeldelink). Platzhalter <code>{{name}}</code> und <code>{{firma}}</code> werden je Empfänger ersetzt.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="campaign_save">
        <?php if (!$isNew): ?><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><?php endif; ?>
        <div class="form-row">
            <div><label for="name">Interner Name</label><input type="text" id="name" name="name" value="<?= e((string)($c['name'] ?? '')) ?>" maxlength="120" required <?= $editable && $canManage ? '' : 'readonly' ?>></div>
            <div><label for="subject">Betreff</label><input type="text" id="subject" name="subject" value="<?= e((string)($c['subject'] ?? '')) ?>" maxlength="200" required <?= $editable && $canManage ? '' : 'readonly' ?>></div>
        </div>
        <div class="form-row">
            <div><label for="title">Überschrift in der Nachricht</label><input type="text" id="title" name="title" value="<?= e((string)($c['title'] ?? '')) ?>" maxlength="200" required <?= $editable && $canManage ? '' : 'readonly' ?>></div>
        </div>
        <div class="form-row">
            <div><label for="body_text">Text (Absätze durch Leerzeilen, kein HTML)</label><textarea id="body_text" name="body_text" rows="10" required <?= $editable && $canManage ? '' : 'readonly' ?>><?= e((string)($c['body_text'] ?? '')) ?></textarea></div>
        </div>
        <div class="form-row">
            <div><label for="button_label">Schaltfläche: Beschriftung (optional)</label><input type="text" id="button_label" name="button_label" value="<?= e((string)($c['button_label'] ?? '')) ?>" maxlength="80" <?= $editable && $canManage ? '' : 'readonly' ?>></div>
            <div><label for="button_url">Schaltfläche: Adresse (https)</label><input type="url" id="button_url" name="button_url" value="<?= e((string)($c['button_url'] ?? '')) ?>" maxlength="500" placeholder="https://smart-einzug.de/..." <?= $editable && $canManage ? '' : 'readonly' ?>></div>
        </div>
        <div class="form-row">
            <div><label for="footer_note">Fußnote (optional, vor dem festen Hinweis zur Abmeldung)</label><input type="text" id="footer_note" name="footer_note" value="<?= e((string)($c['footer_note'] ?? '')) ?>" maxlength="500" <?= $editable && $canManage ? '' : 'readonly' ?>></div>
        </div>
        <div class="form-row">
            <div><label>Empfängerlisten</label>
                <?php foreach ($lists as $l): ?>
                    <label class="inline-check"><input type="checkbox" name="list_ids[]" value="<?= e($l['id']) ?>" <?= in_array($l['id'], $c['list_ids_arr'], true) ? 'checked' : '' ?> <?= $editable && $canManage ? '' : 'disabled' ?>> <?= e($l['name']) ?> <span class="hint">(<?= (int)$l['active_count'] ?> aktiv, <?= (int)$l['suppressed_count'] ?> gesperrt)</span></label><br>
                <?php endforeach; ?>
                <?php if (!$lists): ?><span class="hint">Noch keine Liste angelegt.</span><?php endif; ?>
            </div>
        </div>
        <?php if ($editable && $canManage): ?><div class="form-actions"><button type="submit" class="btn">Entwurf speichern</button></div><?php endif; ?>
    </form>
</div>

<?php if (!$isNew): ?>
<div class="card" id="vorschau">
    <h2>Vorschau (Musterdaten Erika Muster, Muster GmbH)</h2>
    <p class="hint">Betreff: <strong><?= e(marketing_campaign_preview($c)['subject']) ?></strong> · Absender: <?= e($profil['from_name']) ?> &lt;<?= e($profil['from']) ?>&gt;<?= $profil['reply_to'] !== '' ? ' · Antworten an ' . e($profil['reply_to']) : '' ?></p>
    <iframe src="admin-marketing.php?vorschau=<?= e($c['id']) ?>" sandbox="" title="Vorschau der Nachricht" style="width: 100%; height: 720px; border: 1px solid var(--color-border, #ddd); background: #fff;"></iframe>
    <details style="margin-top: 10px;"><summary>Textfassung</summary><pre style="white-space: pre-wrap; font-size: 13px;"><?= e(marketing_campaign_preview($c)['text']) ?></pre></details>
</div>

<div class="card" id="freigabe">
    <h2>Test und Freigabe</h2>
    <?php if ($editable): ?>
        <p class="hint">Testversand: <?= $c['test_sent_at'] ? 'zuletzt ' . format_datetime($c['test_sent_at']) . ' (' . e((string)$c['test_sent_ref']) . ')' : '<strong>noch nicht erfolgt</strong>' ?>. Jede Änderung am Inhalt verlangt einen neuen Testversand vor der Freigabe. Tagesstand: <?= $heute ?> von <?= number_format($rates['per_day'], 0, ',', '.') ?> Nachrichten in den letzten 24 Stunden.</p>
        <?php if ($canManage): ?>
        <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
            <?= csrf_field() ?><input type="hidden" name="action" value="campaign_test"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>">
            <input type="email" name="test_to" placeholder="Testempfänger (eigene Adresse)" required style="max-width: 320px;" value="<?= e((string)($ctx['email'] ?? '')) ?>">
            <button type="submit" class="btn btn-sm btn-secondary" <?= $profil['enabled'] ? '' : 'disabled' ?>>Testnachricht senden</button>
        </form>
        <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px;" onsubmit="return confirm('Massenversand an alle aktiven Empfänger der gewählten Listen freigeben? Der Versand lässt sich anhalten, aber gesendete Nachrichten nicht zurückholen.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="campaign_start"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>">
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" required class="code-input" style="max-width: 160px;">
            <button type="submit" class="btn btn-danger" <?= $profil['enabled'] && $c['test_sent_at'] ? '' : 'disabled' ?>>Massenversand freigeben</button>
            <span class="hint">Freigabe nur nach Testversand; Zweitbestätigung per 2FA-Code, protokolliert.</span>
        </form>
        <form method="post" class="inline-form" style="margin-top: 14px;" onsubmit="return confirm('Entwurf löschen?');"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_delete"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><button type="submit" class="btn btn-sm btn-ghost">Entwurf löschen</button></form>
        <?php endif; ?>
    <?php else: ?>
        <p>Freigegeben von <?= e((string)$c['started_by']) ?> am <?= format_datetime($c['started_at']) ?><?= $c['finished_at'] ? ', beendet ' . format_datetime($c['finished_at']) : '' ?>.
            <strong><?= (int)$c['sent_count'] ?></strong> gesendet, <?= (int)$c['failed_count'] ?> fehlgeschlagen, <?= (int)$c['skipped_count'] ?> übersprungen (Sperrliste oder Abbruch), <?= max(0, (int)$c['total_count'] - (int)$c['sent_count'] - (int)$c['failed_count'] - (int)$c['skipped_count']) ?> offen von <?= (int)$c['total_count'] ?>.</p>
        <?php if ($canManage): ?>
        <div class="inline-form" style="gap: 8px; flex-wrap: wrap;">
            <?php if (in_array($c['status'], ['queued', 'sending'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_pause"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><button type="submit" class="btn btn-sm btn-secondary">Anhalten</button></form><?php endif; ?>
            <?php if ($c['status'] === 'paused'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_resume"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><button type="submit" class="btn btn-sm">Fortsetzen</button></form><?php endif; ?>
            <?php if (in_array($c['status'], ['queued', 'sending', 'paused'], true)): ?><form method="post" onsubmit="return confirm('Kampagne abbrechen? Offene Adressen werden nicht mehr angeschrieben.');"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_cancel"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><button type="submit" class="btn btn-sm btn-danger">Abbrechen</button></form><?php endif; ?>
            <?php if ($c['status'] === 'cancelled'): ?><form method="post" onsubmit="return confirm('Abgebrochene Kampagne löschen?');"><?= csrf_field() ?><input type="hidden" name="action" value="campaign_delete"><input type="hidden" name="campaign_id" value="<?= e($c['id']) ?>"><button type="submit" class="btn btn-sm btn-ghost">Löschen</button></form><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php $fails = marketing_campaign_sends($c['id'], 'failed', 100); ?>
        <?php if ($fails): ?>
        <h3 style="margin-top: 14px;">Fehlgeschlagen (<?= count($fails) ?>)</h3>
        <div class="table-wrap"><table class="table-sm"><thead><tr><th>E-Mail</th><th>Fehler</th></tr></thead><tbody>
            <?php foreach ($fails as $s): ?><tr><td><?= e($s['email']) ?></td><td class="hint"><?= e((string)$s['error']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$viewList && $viewCampaign === null): ?>
<div class="card" id="uebersicht">
    <h2>Versandprofil und Ratenbegrenzung</h2>
    <div class="table-wrap">
        <table class="table-sm"><tbody>
            <tr><th style="width: 260px;">Profil mail_marketing</th><td><?= $profil['enabled'] ? '<span class="badge badge-success">aktiv</span>' : '<span class="badge badge-warn">nicht aktiv</span>' ?> · Transport <?= e($profil['transport']) ?><?= $profil['ses'] ? ' (Amazon SES)' : '' ?> · SMTP <?= $profil['smtp_ok'] ? 'konfiguriert' : '<strong>unvollständig</strong>' ?> · Webhook-Token <?= $profil['webhook_ok'] ? 'gesetzt' : '<strong>fehlt</strong>' ?></td></tr>
            <tr><th>Absender</th><td><?= e($profil['from_name']) ?> &lt;<?= e($profil['from']) ?>&gt;<?= $profil['reply_to'] !== '' ? ' · Antworten an ' . e($profil['reply_to']) : '' ?></td></tr>
            <tr><th>Letzte 24 Stunden</th><td><?= $heute ?> von <?= number_format($rates['per_day'], 0, ',', '.') ?> Nachrichten (Kampagnen und Tests) · Sperrliste <?= marketing_suppression_count() ?> Adressen · offene Adressen <?= marketing_sends_remaining() ?><?= marketing_campaigns_pending() ? ' · Versandjob eingeplant (Pool mail)' : '' ?></td></tr>
        </tbody></table>
    </div>
    <?php if ($canManage): ?>
    <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px; margin-top: 12px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="rates_save">
        <label for="per_second">Nachrichten je Sekunde</label><input type="number" id="per_second" name="per_second" min="1" max="100" value="<?= (int)$rates['per_second'] ?>" style="max-width: 100px;">
        <label for="per_day">je 24 Stunden</label><input type="number" id="per_day" name="per_day" min="1" max="1000000" value="<?= (int)$rates['per_day'] ?>" style="max-width: 140px;">
        <button type="submit" class="btn btn-sm btn-secondary">Speichern</button>
        <span class="hint">Vorgabe 1 je Sekunde und 200 je 24 Stunden (Grenzen der SES-Sandbox). Erst nach Produktionsfreigabe des SES-Kontos erhöhen; die Kontingente stehen in der SES-Konsole.</span>
    </form>
    <?php endif; ?>
</div>

<div class="card" id="listen">
    <h2>Empfängerlisten (<?= count($lists) ?>)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Liste</th><th>Art</th><th>Aktiv</th><th>Gesperrt</th><th>Gesamt</th><th>Geändert</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lists as $l): ?>
                <tr>
                    <td><a href="admin-marketing.php?liste=<?= e($l['id']) ?>"><strong><?= e($l['name']) ?></strong></a><?= $l['description'] ? '<br><small class="hint">' . e((string)$l['description']) . '</small>' : '' ?></td>
                    <td><?= $l['source'] === 'system' ? 'Systemliste (' . ($l['system_scope'] === 'owners_admins' ? 'Inhaber und Administratoren' : 'Inhaber') . ')' : 'Import' ?></td>
                    <td><?= (int)$l['active_count'] ?></td><td><?= (int)$l['suppressed_count'] ?></td><td><?= (int)$l['total_count'] ?></td>
                    <td><?= format_datetime($l['updated_at']) ?></td>
                    <td><a class="btn btn-sm btn-secondary" href="admin-marketing.php?export=<?= e($l['id']) ?>">CSV</a>
                        <?php if ($canManage): ?><form method="post" class="inline-form" onsubmit="return confirm('Liste mit allen Empfängern löschen? Die Sperrliste bleibt unberührt.');"><?= csrf_field() ?><input type="hidden" name="action" value="list_delete"><input type="hidden" name="list_id" value="<?= e($l['id']) ?>"><button type="submit" class="btn btn-sm btn-ghost">Löschen</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lists): ?><tr><td colspan="7" class="hint">Noch keine Liste.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canManage): ?>
    <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px; margin-top: 12px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="list_create">
        <input type="text" name="name" placeholder="Name der Liste" required maxlength="120" style="max-width: 240px;">
        <input type="text" name="description" placeholder="Beschreibung (optional)" maxlength="500" style="max-width: 300px;">
        <select name="source" onchange="this.form.system_scope.disabled = this.value !== 'system';">
            <option value="import">Importliste (CSV)</option>
            <option value="system">Systemliste (Kunden aus dem System)</option>
        </select>
        <select name="system_scope" disabled>
            <option value="owners">nur Inhaber</option>
            <option value="owners_admins">Inhaber und Administratoren</option>
        </select>
        <button type="submit" class="btn btn-sm">Liste anlegen</button>
    </form>
    <p class="hint">Systemlisten enthalten die Inhaber (und wahlweise Administratoren) aktiver Firmenaccounts als Bestandskunden; Aktualisierung über die Liste. Vormerkungen (sevdesk) sind bewusst keine Quelle: Ihre Einwilligung gilt nur für Nachrichten zur vorgemerkten Anbindung.</p>
    <?php endif; ?>
</div>

<div class="card" id="sperrliste">
    <h2>Sperrliste (<?= marketing_suppression_count() ?>)</h2>
    <p class="hint">Dauerhaft und listenübergreifend: Abmeldungen (Link und One-Click), Beschwerden und harte Rückläufer aus SES sowie von Hand gesperrte Adressen. Eine gesperrte Adresse wird bei Import, Freigabe und unmittelbar vor dem Senden geprüft und nie angeschrieben. Abmeldungen und Beschwerden lassen sich nicht aufheben.</p>
    <?php if ($canManage): ?>
    <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="suppress_add">
        <input type="email" name="email" placeholder="E-Mail-Adresse sperren" required style="max-width: 300px;">
        <input type="text" name="note" placeholder="Vermerk (z. B. Bitte per Telefon am ...)" maxlength="255" style="max-width: 320px;">
        <button type="submit" class="btn btn-sm btn-secondary">Sperren</button>
    </form>
    <?php endif; ?>
    <form method="get" class="inline-form" style="margin-bottom: 10px;"><input type="search" name="sq" value="<?= e((string)($_GET['sq'] ?? '')) ?>" placeholder="Adresse suchen" style="max-width: 280px;"><button type="submit" class="btn btn-sm btn-secondary">Suchen</button></form>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>E-Mail</th><th>Grund</th><th>Vermerk</th><th>Seit</th><th>Durch</th><th></th></tr></thead>
            <tbody>
            <?php foreach (marketing_suppressions((string)($_GET['sq'] ?? ''), 200) as $s): ?>
                <tr>
                    <td><?= e($s['email_norm']) ?></td><td><?= e(MARKETING_SUPPRESSION_REASONS[$s['reason']] ?? (string)$s['reason']) ?></td><td class="hint"><?= e((string)($s['note'] ?? '')) ?></td>
                    <td><?= format_datetime($s['created_at']) ?></td><td class="hint"><?= e((string)($s['created_by'] ?? 'Empfänger oder SES')) ?></td>
                    <td><?php if ($canManage && !in_array($s['reason'], MARKETING_SUPPRESSION_PERMANENT, true)): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="suppress_remove"><input type="hidden" name="email" value="<?= e($s['email_norm']) ?>"><input type="text" name="reason" placeholder="Grund (Pflicht)" required minlength="5" maxlength="255" style="max-width: 180px; padding: 5px 8px; font-size: 13px;"><button type="submit" class="btn btn-sm btn-ghost">Aufheben</button></form>
                    <?php else: ?><span class="hint">dauerhaft</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="kampagnen">
    <h2>Kampagnen (<?= count($campaigns) ?>)</h2>
    <?php if ($canManage): ?><p><a class="btn btn-sm" href="admin-marketing.php?kampagne=neu">Neue Kampagne</a></p><?php endif; ?>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Kampagne</th><th>Betreff</th><th>Listen</th><th>Status</th><th>Gesendet</th><th>Test</th><th>Geändert</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $c): $ids = array_values(array_filter(array_map('strval', (array)json_decode((string)$c['list_ids'], true)))); ?>
                <tr>
                    <td><a href="admin-marketing.php?kampagne=<?= e($c['id']) ?>"><strong><?= e($c['name']) ?></strong></a></td>
                    <td><?= e($c['subject']) ?></td>
                    <td class="hint"><?= e(implode(', ', array_map(static fn(string $id): string => (string)($listById[$id]['name'] ?? 'gelöscht'), $ids))) ?></td>
                    <td><?= $statusBadge((string)$c['status']) ?></td>
                    <td><?= (int)$c['sent_count'] ?><?= (int)$c['total_count'] ? ' / ' . (int)$c['total_count'] : '' ?><?= (int)$c['failed_count'] ? ', ' . (int)$c['failed_count'] . ' Fehler' : '' ?></td>
                    <td><?= $c['test_sent_at'] ? format_datetime($c['test_sent_at']) : '<span class="hint">offen</span>' ?></td>
                    <td><?= format_datetime($c['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?><tr><td colspan="7" class="hint">Noch keine Kampagne.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="ereignisse">
    <h2>Ereignisse (Abmeldungen, Rückläufer, Beschwerden, Tests)</h2>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Zeit</th><th>Quelle</th><th>Ereignis</th><th>E-Mail</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach (marketing_events_recent(50) as $ev): ?>
                <tr><td><?= format_datetime($ev['created_at']) ?></td><td><?= e($ev['source']) ?></td><td><code><?= e($ev['event_type']) ?></code></td><td><?= e((string)($ev['email_norm'] ?? '')) ?></td><td class="hint"><?= e(mb_substr((string)($ev['details_json'] ?? ''), 0, 160)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Rückläufer und Beschwerden liefert Amazon SES über SNS an <code>marketing-webhook.php</code> (Signaturprüfung und Token). Ohne eingerichtetes Thema bleibt die Tabelle auf Abmeldungen und Tests beschränkt; Einrichtung in der Dokumentation, Kapitel Marketing.</p>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
