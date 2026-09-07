<?php
/**
 * Adminbereich "System": technische Betriebsübersicht (Auftrag II, Abschnitt 7).
 * Zugriff nur für Plattformadministratoren (require_superadmin). Ändern von Überwachungseinstellungen,
 * Veröffentlichen von Störungsmeldungen und Testversand zusätzlich nur für konfigurierte Bearbeiter
 * (monitoring.editors) mit frischer 2FA-Bestätigung. Seitenaufrufe lösen keine neuen Prüfungen aus;
 * "Jetzt prüfen" führt ausschließlich die freigegebenen, begrenzten Diagnosen aus.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/admin_charts.php';
require_once __DIR__ . '/app/monitor_view.php';
require_once __DIR__ . '/app/queue.php';
require_once __DIR__ . '/app/version.php';

/** Badge für einen Änderungsverlauf-Eintrag (Neu, Geändert, Behoben). */
function admin_changelog_badge(string $type): string
{
    $cls = ['Neu' => 'badge-success', 'Geändert' => 'badge-info', 'Behoben' => 'badge-warn'][$type] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . e($type) . '</span>';
}

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_superadmin();
$cfg = monitor_config();
$canEdit = monitor_can_edit($ctx);
$available = monitor_available();

$tabs = ['uebersicht' => 'Übersicht', 'dienste' => 'Dienste', 'aktivitaet' => 'Aktivität', 'jobs' => 'Jobs', 'server' => 'Server',
         'verfuegbarkeit' => 'Verfügbarkeit', 'stoerungen' => 'Störungen und Wartung', 'versionen' => 'Versionen & Dokumentation'];
$tabParam = is_string($_GET['tab'] ?? null) ? (string)$_GET['tab'] : '';
if ($tabParam === 'dokumentation') { $tabParam = 'versionen'; } // alter Reiter, Links bleiben gueltig
$tab = isset($tabs[$tabParam]) ? $tabParam : 'uebersicht';
$windows = monitor_windows();
$wParam = is_string($_GET['w'] ?? null) ? (string)$_GET['w'] : '';
$w = isset($windows[$wParam]) ? $wParam : '1h';
$d = is_scalar($_GET['d'] ?? null) && in_array((int)$_GET['d'], [7, 30, 90], true) ? (int)$_GET['d'] : 30;
$back = 'admin-system.php?tab=' . $tab . '&w=' . $w . '&d=' . $d;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$available) {
            throw new RuntimeException('Für das Monitoring fehlt noch die Datenbankmigration 017.');
        }
        if ($action === 'collect_now') {
            $r = monitor_collect(['force' => true, 'budget' => 8.0, 'source' => 'admin', 'publish' => false]);
            audit_log(null, $ctx, 'monitor_collect_manual', 'monitor', null, ['checks' => count($r['checks'] ?? [])]);
            flash_set('success', isset($r['skipped']) ? 'Prüfung übersprungen (' . $r['skipped'] . ').' : sprintf('%d Prüfungen ausgeführt, %d wegen Zeitbudget ausgelassen.', count($r['checks'] ?? []), count($r['skipped_checks'] ?? [])));
        } elseif (!$canEdit) {
            throw new RuntimeException('Für diese Aktion fehlt die Bearbeitungsberechtigung (monitoring.editors).');
        } elseif ($action === 'incident_create') {
            $id = monitor_incident_create($ctx, ['kind' => $_POST['kind'] ?? 'incident', 'title' => $_POST['title'] ?? '', 'components' => (array)($_POST['components'] ?? []),
                'public_message' => $_POST['public_message'] ?? '', 'internal_notes' => $_POST['internal_notes'] ?? '', 'started_at' => $_POST['started_at'] ?? '', 'scheduled_end_at' => $_POST['scheduled_end_at'] ?? '']);
            flash_set('success', 'Eintrag angelegt (unveröffentlicht). Vorschau prüfen und mit 2FA-Code veröffentlichen.');
            $back = 'admin-system.php?tab=stoerungen#inc-' . $id;
        } elseif ($action === 'incident_update') {
            monitor_incident_update($ctx, (string)($_POST['incident_id'] ?? ''), ['phase' => $_POST['phase'] ?? '', 'public_text' => $_POST['public_text'] ?? '', 'internal_note' => $_POST['internal_note'] ?? '']);
            flash_set('success', 'Verlauf ergänzt.');
            $back = 'admin-system.php?tab=stoerungen#inc-' . (string)($_POST['incident_id'] ?? '');
        } elseif ($action === 'incident_publish' || $action === 'incident_unpublish') {
            if ($action === 'incident_publish') {
                // Veröffentlichen einer Störungs- oder Wartungsmeldung ("Wartung aktivieren") mit Zweitbestätigung;
                // Zurückziehen ohne (Vorstand 07.09.2026, Zweitbestätigung nur für Wichtiges).
                require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            }
            monitor_incident_publish($ctx, (string)($_POST['incident_id'] ?? ''), $action === 'incident_publish');
            if ($cfg['publish']) {
                status_publish(monitor_public_snapshot());
            }
            flash_set('success', $action === 'incident_publish' ? 'Meldung veröffentlicht.' : 'Meldung zurückgezogen.');
            $back = 'admin-system.php?tab=stoerungen';
        } elseif ($action === 'publish_now') {
            // Überträgt bereits öffentliche Kennzahlen; keine Zweitbestätigung (seit 4.36), CSRF und Audit bleiben.
            $r = status_publish(monitor_public_snapshot());
            audit_log(null, $ctx, 'status_published_manual', 'monitor', null, $r);
            flash_set('success', 'Statusdaten übertragen: ' . ($r ? http_build_query($r, '', ', ') : 'kein Ziel konfiguriert'));
        } elseif ($action === 'test_mail') {
            // Diagnosefunktion ohne Wirkung auf Kunden oder Geld; keine Zweitbestätigung (seit 4.36).
            if ($cfg['test_mail_to'] === '') {
                throw new RuntimeException('Keine Testadresse konfiguriert (monitoring.test_mail_to).');
            }
            require_once __DIR__ . '/app/mailer.php';
            $tpl = mail_tpl_security('Testversand Systemmonitoring', ['Dies ist ein manueller Testversand aus dem Adminbereich System vom ' . date('d.m.Y H:i:s T') . '.']);
            $ok = mail_send($cfg['test_mail_to'], $tpl['subject'], $tpl['text'], $tpl['html']);
            audit_log(null, $ctx, 'monitor_test_mail', 'monitor', null, ['accepted' => $ok]);
            flash_set($ok ? 'success' : 'error', $ok ? 'Testnachricht an den Versandweg übergeben (Annahme, kein Zustellnachweis).' : 'Der Versandweg hat die Testnachricht nicht angenommen.');
        } elseif ($action === 'job_retry_now' || $action === 'job_cancel' || $action === 'job_close') {
            if (!queue_available()) {
                throw new RuntimeException('Für die Warteschlange fehlt noch die Datenbankmigration 018.');
            }
            $jobId = (string)($_POST['job_id'] ?? '');
            $jobRow = queue_get($jobId);
            if ($jobRow === null) {
                throw new RuntimeException('Job nicht gefunden.');
            }
            if (queue_type_is_money($jobRow['type'] ?? null)) {
                // Eingriff in einen geldbewegenden Job (Einreichung, Klärung): Zweitbestätigung (Geldfluss).
                require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            }
            if ($action === 'job_retry_now') {
                $r = queue_retry_now($jobId, $ctx);
                flash_set($r['ok'] ? 'success' : 'error', $r['message']);
            } elseif ($action === 'job_cancel') {
                $ok = queue_cancel($jobId, $ctx);
                flash_set($ok ? 'success' : 'error', $ok ? 'Job abgebrochen.' : 'Job konnte nicht abgebrochen werden (falscher Status).');
            } else {
                $ok = queue_close($jobId, $ctx);
                flash_set($ok ? 'success' : 'error', $ok ? 'Job dauerhaft geschlossen.' : 'Job konnte nicht geschlossen werden (falscher Status).');
            }
            $back = 'admin-system.php?tab=jobs#wartend';
        } elseif ($action === 'job_release') {
            // Reservierung eines Jobs freigeben, dessen Worker sich nicht mehr meldet: zurueck in die
            // Warteschlange OHNE Fehlversuch. Nur bei abgelaufenem Heartbeat zulaessig (siehe queue.php).
            if (!queue_available()) {
                throw new RuntimeException('Für die Warteschlange fehlt noch die Datenbankmigration 018.');
            }
            $jobRow = queue_get((string)($_POST['job_id'] ?? ''));
            if ($jobRow === null) {
                throw new RuntimeException('Job nicht gefunden.');
            }
            if (queue_type_is_money($jobRow['type'] ?? null)) {
                require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            }
            $r = queue_release_one((string)($_POST['job_id'] ?? ''), $ctx);
            flash_set($r['ok'] ? 'success' : 'error', $r['message']);
            $back = 'admin-system.php?tab=jobs#wartend';
        } elseif ($action === 'sync_enqueue') {
            // Offenen Synchronisationslauf fortsetzen: Job einreihen (dedupe_key verhindert Doppeleintraege).
            // Nur Jobtyp sync_run (Lesevorgang gegenüber Lexware Office, kein Geldfluss): keine Zweitbestätigung (seit 4.36).
            if (!queue_available()) {
                throw new RuntimeException('Für die Warteschlange fehlt noch die Datenbankmigration 018.');
            }
            $orgId = (string)($_POST['org_id'] ?? '');
            $chk = db()->prepare('SELECT name, sync_paused FROM organizations WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$orgId]);
            $orgRow = $chk->fetch();
            if (!$orgRow) {
                throw new RuntimeException('Firma nicht gefunden.');
            }
            if ((int)$orgRow['sync_paused'] === 1) {
                throw new RuntimeException('Für diese Firma ist die Synchronisation pausiert (Wartungsmodus).');
            }
            $r = queue_push('sync_run', ['triggered_by' => 'admin'], ['tenant_id' => $orgId, 'user_id' => $ctx['user_id'] ?? null, 'priority' => 'normal', 'dedupe_key' => 'sync:' . $orgId]);
            audit_log($orgId, $ctx, 'sync_enqueued_admin', 'organization', $orgId, ['job_id' => $r['id'], 'created' => (bool)$r['created']]);
            flash_set('success', $r['created'] ? 'Fortsetzung der Synchronisation eingereiht.' : 'Für diese Firma ist bereits ein Synchronisationsjob aktiv.');
            $back = 'admin-system.php?tab=jobs#wartend';
        } elseif ($action === 'org_sync_pause' || $action === 'org_sync_resume') {
            $orgId = (string)($_POST['org_id'] ?? '');
            $pause = $action === 'org_sync_pause';
            if ($pause) {
                // Wartungsmodus einer Firma aktivieren: Zweitbestätigung ("Wartung nur mit 2FA aktivieren");
                // Fortsetzen ohne (Vorstand 07.09.2026).
                require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($pause && $reason === '') {
                throw new RuntimeException('Bitte einen Grund für die Wartung angeben.');
            }
            $chk = db()->prepare('SELECT id FROM organizations WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$orgId]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException('Firma nicht gefunden.');
            }
            db()->prepare('UPDATE organizations SET sync_paused = ?, sync_paused_reason = ? WHERE id = ? AND deleted_at IS NULL')
                ->execute([$pause ? 1 : 0, $pause ? mb_substr($reason, 0, 160) : null, $orgId]);
            audit_log($orgId, $ctx, $pause ? 'sync_paused' : 'sync_resumed', 'organization', $orgId, $pause ? ['reason' => $reason] : []);
            flash_set('success', $pause ? 'Synchronisation für diese Firma pausiert.' : 'Synchronisation für diese Firma wieder freigegeben.');
            $back = 'admin-system.php?tab=jobs';
        } elseif ($action === 'org_queue_flag_on' || $action === 'org_queue_flag_off') {
            // Technische Betriebskonfiguration ohne Geldfluss; keine Zweitbestätigung (seit 4.36), Audit in tenant_feature_set().
            $orgId = (string)($_POST['org_id'] ?? '');
            $chk = db()->prepare('SELECT id FROM organizations WHERE id = ? AND deleted_at IS NULL');
            $chk->execute([$orgId]);
            if (!$chk->fetchColumn()) {
                throw new RuntimeException('Firma nicht gefunden.');
            }
            tenant_feature_set($orgId, 'queue', $action === 'org_queue_flag_on', $ctx);
            flash_set('success', $action === 'org_queue_flag_on' ? 'Warteschlange für diese Firma aktiviert.' : 'Warteschlange für diese Firma deaktiviert.');
            $back = 'admin-system.php?tab=jobs';
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect($back);
}

$now = monitor_now();
$winSeconds = (int)$windows[$w]['seconds'];
$from = $now - $winSeconds;

layout_header('System', $ctx);
?>
<h1>System</h1>
<p class="page-sub">Technische Betriebsübersicht der eigenen SmartEinzug-Jobs und Dienste. Zeiten in <?= e(date_default_timezone_get()) ?>, gespeichert in UTC.
    Anzeige aktualisiert gespeicherte Ergebnisse alle 30 Sekunden (pausiert in inaktiven Tabs); es werden dabei keine neuen Prüfungen ausgelöst.</p>

<?php if (!$available): ?>
<div class="flash flash-error">Für das Monitoring fehlt noch die Datenbankmigration 017 (sql/migrations/017_monitoring.sql, Einspielen über den Migrationsendpunkt beim nächsten Deployment).</div>
<?php endif; ?>

<?php if (maintenance_active()):
    $mFlag = storage_dir() . '/maintenance.flag';
    $mSinceTs = false;
    if (is_file($mFlag)) {
        $mRaw = trim((string)@file_get_contents($mFlag));
        $mSinceTs = $mRaw !== '' ? strtotime($mRaw) : false;
        if ($mSinceTs === false) { $mSinceTs = @filemtime($mFlag); }
    }
    $mDur = $mSinceTs ? max(0, time() - (int)$mSinceTs) : null;
    $mLong = $mDur !== null && $mDur > 43200;
?>
<div class="flash <?= $mLong ? 'flash-error' : 'flash-warn' ?>">
    <strong>Wartungsmodus aktiv</strong><?php if ($mSinceTs): ?> seit <?= e(date('d.m.Y H:i', (int)$mSinceTs)) ?> (Dauer <?= e(sprintf('%d Std. %02d Min.', intdiv((int)$mDur, 3600), intdiv((int)$mDur % 3600, 60))) ?>)<?php endif; ?>.
    Kundenseiten, Stripe-Webhooks und cron.php antworten mit 503, Scheduler und Worker pausieren.
    <?php if ($mLong): ?>Das Fenster dauert länger als 12 Stunden: Stripe wiederholt Ereignisse nur begrenzt, nach dem Ende fehlgeschlagene Webhook-Ereignisse im Stripe-Dashboard erneut senden.<?php else: ?>Nach dem Ende fehlgeschlagene Webhook-Ereignisse im Stripe-Dashboard prüfen (Cutover-Checkliste).<?php endif; ?>
    Ausschalten: <code>maintenance.sh off</code> auf dem Server bzw. Markerdatei entfernen<?= !empty($GLOBALS['config']['maintenance_mode']) ? ', zusätzlich ist maintenance_mode in der Konfiguration gesetzt' : '' ?>.
</div>
<?php endif; ?>

<?= monitor_render_head() ?>

<div class="form-actions" style="margin: 10px 0 16px; flex-wrap: wrap;">
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="collect_now"><button type="submit" class="btn btn-secondary" title="Führt nur die freigegebenen, begrenzten Diagnosen aus. Keine Migration, Synchronisation, Lastschrift oder Testmail.">Jetzt prüfen</button></form>
    <?php if ($cfg['status_page_url'] !== ''): ?><a class="btn btn-secondary" href="<?= e($cfg['status_page_url']) ?>" target="_blank" rel="noopener">Öffentliche Statusseite</a><?php endif; ?>
</div>

<?php $subnavItems = []; foreach ($tabs as $k => $label) { $subnavItems[$k] = ['label' => $label, 'href' => 'admin-system.php?tab=' . $k . '&w=' . $w . '&d=' . $d]; }
echo layout_subnav($subnavItems, $tab, 'Systembereiche'); ?>

<?php if ($tab === 'uebersicht' || $tab === 'aktivitaet'): ?>
<div class="mon-windows">Zeitfenster:
    <?php foreach ($windows as $k => $win): ?>
        <a href="admin-system.php?tab=<?= e($tab) ?>&amp;w=<?= e($k) ?>&amp;d=<?= $d ?>"<?= $k === $w ? ' class="active"' : '' ?>><?= e($win['label']) ?></a>
    <?php endforeach; ?>
    <span class="hint">Rollierend bis <?= e(date('d.m.Y H:i:s T', $now)) ?>. Dienstprüfungen laufen etwa alle <?= (int)round($cfg['collect_interval_seconds'] / 60) ?> Minuten; das Fenster "1 Minute" zeigt deshalb nur ereignisbasierte Jobdaten in Minutenauflösung, keine erfundenen Zwischenmessungen.</span>
</div>
<?php endif; ?>

<?php if ($tab === 'uebersicht'): ?>
<?php $js = monitor_job_stats($from, $now); $rq = monitor_request_stats($from, $now); ?>
<div class="card">
    <h2>Kennzahlen <?= e($windows[$w]['label']) ?></h2>
    <div class="card-grid stat-row">
        <div class="stat-card"><div class="stat-value"><?= (int)$js['started'] ?></div><div class="stat-label">Gestartete Ausführungen<span class="stat-sub">(Start im Fenster)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$js['finished_success'] ?> / <?= (int)$js['finished_failed'] ?></div><div class="stat-label">Abschlüsse erfolgreich / fehlgeschlagen<span class="stat-sub">(Abschluss im Fenster<?= $js['finished_unknown'] ? ', ' . (int)$js['finished_unknown'] . ' unbestätigt' : '' ?>)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$js['attempts'] ?> / <?= (int)$js['unique_jobs'] ?></div><div class="stat-label">Versuche / eindeutige Aufträge</div></div>
        <div class="stat-card"><div class="stat-value"><?= number_format((int)$js['items'], 0, ',', '.') ?></div><div class="stat-label">Verarbeitete Datensätze<span class="stat-sub">(nur erfolgreich abgeschlossene Läufe, Zuwachs je Schritt)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$js['api_calls'] ?> / <?= (int)$js['api_errors'] ?></div><div class="stat-label">API-Aufrufe / technische Fehler<span class="stat-sub">(instrumentierte Lexware-Aufrufe der Sync-Schritte)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$js['skipped_starts'] ?></div><div class="stat-label">Übersprungene Doppelstarts</div></div>
        <div class="stat-card"><div class="stat-value"><?= $js['durations_n'] ? monitor_ms($js['duration_avg_ms']) : 'Keine Daten' ?></div><div class="stat-label">Laufzeit Durchschnitt<span class="stat-sub">(n = <?= (int)$js['durations_n'] ?>)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= $js['durations_n'] ? monitor_ms($js['duration_p95_ms']) : 'Keine Daten' ?></div><div class="stat-label">Laufzeit 95. Perzentil<span class="stat-sub">(aus Einzelwerten, n = <?= (int)$js['durations_n'] ?>)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= $js['concurrency_max'] === null ? 'Nicht erfasst' : (int)$js['concurrency_max'] ?></div><div class="stat-label">Parallelität Höchstwert<span class="stat-sub">(aus Start-/Endereignissen)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= $rq['per_minute'] === null ? 'Keine Daten' : number_format((float)$rq['per_minute'], 1, ',', '.') ?></div><div class="stat-label">PHP-Anfragen je Minute<span class="stat-sub">(<?= (int)$rq['requests'] ?> instrumentierte Anfragen, ohne statische Dateien)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= $rq['requests'] > 0 ? (int)$rq['errors_5xx'] : 'Keine Daten' ?></div><div class="stat-label">HTTP-5xx-Antworten<span class="stat-sub">(instrumentierte PHP-Anfragen)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= monitor_ms($rq['avg_ms']) ?></div><div class="stat-label">Antwortzeit Durchschnitt<span class="stat-sub">(max. <?= monitor_ms($rq['max_ms']) ?>)</span></div></div>
        <div class="stat-card"><div class="stat-value"><?= monitor_bytes($js['peak_memory_max']) ?></div><div class="stat-label">PHP-Spitzenspeicher der erfassten Jobs<span class="stat-sub">(je Job, keine Server-RAM-Auslastung)</span></div></div>
    </div>
    <?php
    $bucket = $winSeconds <= 600 ? 60 : ($winSeconds <= 3600 ? 300 : 3600);
    $series = monitor_request_series($from, $now, $bucket);
    $rows = [];
    foreach ($series as $ts => $v) {
        $rows[] = ['label' => date($bucket >= 3600 ? 'H:i' : 'H:i', $ts), 'value' => $v === null ? 0 : $v, 'gap' => $v === null];
    }
    $gaps = count(array_filter($rows, fn($r) => $r['gap']));
    ?>
    <h3 class="mon-h3">Instrumentierte PHP-Anfragen je <?= $bucket >= 3600 ? 'Stunde' : ($bucket >= 300 ? '5 Minuten' : 'Minute') ?> (Anzahl)</h3>
    <?= chart_bars(array_map(fn($r) => ['label' => $r['label'], 'value' => $r['value']], $rows), '', fn($v) => number_format((int)$v, 0, ',', '.')) ?>
    <p class="hint">Zeitachse von <?= e(date('d.m.Y H:i', $from)) ?> bis <?= e(date('d.m.Y H:i T', $now)) ?>. <?= $gaps ?> Intervall(e) ohne Messdaten (Lücken werden als 0 gezeichnet, zählen aber nicht als Erfolg).</p>
</div>

<div class="card">
    <h2>Herkunft und Grenzen der Messwerte</h2>
    <p class="hint">Was gemessen wird, hängt von der Umgebung ab: Auf dem VPS liest der Metrik-Sammler
    (<code>bin/host-metrics.php</code>) Kennzahlen des Hosts, auf dem IONOS Webhosting ist das ohne
    Root-Zugang nicht möglich. Diese Übersicht sagt je Messwert, ob aktuell Daten vorliegen; die Werte
    selbst stehen im Reiter <a href="admin-system.php?tab=server">Server</a>.</p>
    <?php
    // Je Zeile: vorhandene Messung (monitor_latest) entscheidet, ob der Messwert als erfasst gilt.
    // "metric" leer bedeutet: technisch nicht erfassbar, unabhaengig von der Umgebung.
    $messGrenzen = [
        ['label' => 'CPU-Auslastung des Servers', 'metric' => 'host_cpu',
         'da' => 'Erfasst vom Metrik-Sammler auf dem VPS (/proc/stat).',
         'fehlt' => 'Keine Messung: nur auf dem VPS mit laufendem Metrik-Sammler verfügbar, auf dem IONOS Webhosting ohne Root-Zugang nicht bereitgestellt.'],
        ['label' => 'Gesamt-RAM-Auslastung', 'metric' => 'host_mem',
         'da' => 'Erfasst vom Metrik-Sammler auf dem VPS (/proc/meminfo).',
         'fehlt' => 'Keine Messung: ohne Metrik-Sammler misst memory_get_peak_usage() nur den eigenen PHP-Job.'],
        ['label' => 'Systemlast', 'metric' => 'host_load1',
         'da' => 'Erfasst vom Metrik-Sammler auf dem VPS (/proc/loadavg, 1 Minute).',
         'fehlt' => 'Keine Messung: nur auf dem VPS verfügbar.'],
        ['label' => 'Festplatten- und Speicherbelegung', 'metric' => 'host_disk',
         'da' => 'Erfasst vom Metrik-Sammler auf dem VPS (Dateisystem der Releases).',
         'fehlt' => 'Keine Messung: das Webspace-Kontingent des Hostings wird nicht bereitgestellt; gemessen werden dann nur eigene Größen (Datenbank, Mandatsspeicher).'],
        ['label' => 'Sicherungen (Zeitpunkt und Größe der neuesten Datenbanksicherung)', 'metric' => 'backup',
         'da' => 'Erfasst aus der lokalen Kopie der Coolify-Sicherungen (backup-status.json). Der externe Upload nach Hetzner Object Storage und der Wiederherstellungstest werden in Coolify geprüft, nicht hier.',
         'fehlt' => 'Keine Messung: ohne backup-status.json nicht eingerichtet.'],
        ['label' => 'Belegte PHP-Worker und Prozesse', 'metric' => '',
         'fehlt' => 'Technisch nicht erfassbar: php-fpm liefert der Anwendung keine belastbare aktuelle Anzahl, eine erlaubte Höchstzahl wäre keine Messung.'],
        ['label' => 'Externe Erreichbarkeitsprüfung (Sicht von außen)', 'metric' => '',
         'fehlt' => $cfg['publish']
             ? 'Statusveröffentlichung ist konfiguriert; ein unabhängiger externer Prüfer ist damit noch nicht eingerichtet (siehe docs/status-page.md).'
             : 'Nicht eingerichtet, und die Statusveröffentlichung ist noch nicht konfiguriert (siehe docs/status-page.md und docs/vps/06-betrieb.md).'],
    ];
    ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Messwert</th><th>Stand</th><th>Erläuterung</th></tr></thead>
        <tbody>
        <?php foreach ($messGrenzen as $mg):
            $latest = $mg['metric'] !== '' ? monitor_latest((string)$mg['metric']) : null;
            $hat = $latest !== null;
        ?>
            <tr>
                <td><?= e($mg['label']) ?></td>
                <td><?= $hat ? monitor_state_badge('ok', 'erfasst') . ' <span class="hint">' . e(mon_age_label($now - (mon_ts($latest['checked_at']) ?? $now))) . '</span>' : monitor_state_badge('unknown', 'keine Messung') ?></td>
                <td class="hint"><?= e((string)($hat ? ($mg['da'] ?? '') : $mg['fehlt'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if ($cfg['tariff_limits']): ?>
    <h3 class="mon-h3">Manuell hinterlegte Tariflimits (Konfigurationswerte, keine Messung)</h3>
    <table class="table-plain"><tbody>
        <?php foreach ($cfg['tariff_limits'] as $k => $l): ?><tr><td><?= e((string)$k) ?></td><td><?= e((string)($l['value'] ?? '')) ?></td><td>Quelle: <?= e((string)($l['source'] ?? '-')) ?>, Stand <?= e((string)($l['date'] ?? '-')) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'dienste'): ?>
<?php $ov = monitor_components_overview(); ?>
<div class="card">
    <h2>Dienste</h2>
    <p class="hint">Zustand aus der letzten Messung; ist sie älter als die Frischegrenze, gilt der Zustand als unbekannt. Technische Erreichbarkeit, geprüfte Funktion und veraltete Messung werden unterschieden.</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Dienst</th><th>Zustand</th><th>Letzte Prüfung</th><th>Letzte erfolgreiche Prüfung</th><th>Messwert</th><th>Datenquelle</th><th>Hinweis</th></tr></thead>
        <tbody>
        <?php if (!$ov): ?><tr><td colspan="7" class="hint">Noch keine Messdaten. Der Sammler läuft mit dem Cron oder über "Jetzt prüfen".</td></tr><?php endif; ?>
        <?php foreach ($ov as $c): $l = $c['latest']; ?>
            <tr>
                <td><?= e($c['name']) ?></td>
                <td><?= monitor_state_badge($c['state'], $c['stale'] ? 'Status unbekannt (veraltet)' : null) ?><?php if ($c['reason'] && $c['state'] !== 'ok'): ?><div class="hint"><?= monitor_category_label($c['reason']) ?></div><?php endif; ?></td>
                <td><?= $l ? e(mon_local($l['checked_at'])) . '<div class="hint">' . e(mon_age_label($c['age'])) . '</div>' : 'Noch keine Messdaten' ?></td>
                <td><?= $c['last_ok'] ? e(mon_local($c['last_ok']['checked_at'])) : 'Keine erfolgreiche Prüfung erfasst' ?></td>
                <td><?php if ($l && $l['latency_ms'] !== null): ?><?= monitor_ms((int)$l['latency_ms']) ?><?php elseif ($l && $l['value_num'] !== null): ?><?= e(number_format((float)$l['value_num'], 2, ',', '.')) ?> <?= e((string)$l['unit']) ?><?php else: ?>-<?php endif; ?></td>
                <td class="hint"><?= e($c['source']) ?></td>
                <td class="hint"><?= e($c['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><td>Sicherungen</td><td><?= monitor_state_badge('unknown', 'Nicht überwacht') ?></td><td colspan="5" class="hint">Keine verifizierbare Quelle für Sicherungszeitpunkte oder Wiederherstellungstests.</td></tr>
        <tr><td>CPU / Gesamt-RAM / PHP-Worker</td><td><?= monitor_state_badge('unknown', 'Vom Hosting nicht bereitgestellt') ?></td><td colspan="5" class="hint">Eigene Job- und Laufzeitdaten stehen unter Übersicht und Aktivität.</td></tr>
        </tbody>
    </table>
    </div>
</div>
<div class="card">
    <h2>Deployment und Migrationen (nur lesend)</h2>
    <dl class="kv">
        <dt>Letzter erfolgreicher Migrationsaufruf</dt><dd><?= e(mon_local(monitor_mark_get('deploy_last_migration_ok_at'))) ?> <?= monitor_mark_get('deploy_last_migration_result') ? '(' . e((string)monitor_mark_get('deploy_last_migration_result')) . ')' : '' ?></dd>
        <dt>Letzter bekannter Upload</dt><dd>Nur indirekt über den Migrationsaufruf bekannt; ein erfolgreicher Upload allein gilt nicht als vollständiges Deployment.</dd>
        <dt>Aktive Version</dt><dd><?php $v = @file_get_contents(__DIR__ . '/app/build.txt'); echo $v ? e(trim((string)$v)) : 'Nicht hinterlegt (app/build.txt wird vom Deployment geschrieben, sobald der Workflow den Schritt enthält)'; ?></dd>
        <dt>Migrationsstand</dt><dd><?php require_once __DIR__ . '/app/migrate.php'; try { $ms = migrations_status(); $cnt = array_count_values(array_map(fn($m) => (string)$m['state'], $ms)); echo e(http_build_query($cnt, '', ', ') ?: 'keine Migrationen'); } catch (Throwable $e) { echo 'Nicht lesbar'; } ?> (Monitoring startet oder wiederholt keine Migration)</dd>
    </dl>
</div>
<div class="card">
    <h2>Alarmierung und Testversand</h2>
    <dl class="kv">
        <dt>Empfänger bei bestätigten Störungen</dt><dd><?= $cfg['alert_emails'] ? count($cfg['alert_emails']) . ' konfiguriert' : 'Nicht eingerichtet (monitoring.alert_emails)' ?>; Warnung nach <?= (int)$cfg['alert_fail_streak'] ?> Fehlprüfungen, Entwarnung nach <?= (int)$cfg['alert_ok_streak'] ?> erfolgreichen Prüfungen, je Komponente zusammengefasst.</dd>
        <dt>Unabhängiger Alarmkanal</dt><dd>Nicht aktiv (vorbereitet, siehe docs/monitoring.md). Ein ausgefallener Mailversand kann nicht über sich selbst alarmieren.</dd>
        <dt>Testversand</dt><dd><?= $cfg['test_mail_to'] !== '' ? 'Feste Testadresse konfiguriert' : 'Nicht eingerichtet (monitoring.test_mail_to)' ?>. Ergebnis ist die Annahme durch den Versandweg, kein Zustellnachweis.</dd>
    </dl>
    <?php if ($canEdit && $cfg['test_mail_to'] !== ''): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="test_mail">
        <button type="submit" class="btn btn-secondary">Testnachricht senden</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'aktivitaet'): ?>
<?php $js = monitor_job_stats($from, $now); $pdo = db(); ?>
<div class="card">
    <h2>Aktivität <?= e($windows[$w]['label']) ?></h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Jobtyp</th><th>Versuche</th><th>Erfolgreich</th><th>Fehlgeschlagen</th><th>Datensätze</th><th>API-Aufrufe</th><th>Letzter Abschluss</th><th>Letzter Erfolg</th></tr></thead>
        <tbody>
        <?php if (!$js['by_type']): ?><tr><td colspan="8" class="hint">Keine Ausführungen im Zeitfenster. Null Aktivität ist keine Störung.</td></tr><?php endif; ?>
        <?php foreach ($js['by_type'] as $t => $r): ?>
            <tr><td><?= e(['cron' => 'Cron-Lauf', 'sync' => 'Synchronisationsschritt', 'collections' => 'Einzugsverarbeitung', 'monitor' => 'Monitoring-Sammler'][$t] ?? $t) ?></td>
                <td><?= (int)$r['n'] ?></td><td><?= (int)$r['ok'] ?></td><td><?= (int)$r['failed'] ?></td><td><?= (int)$r['items'] ?></td><td><?= (int)$r['calls'] ?></td>
                <td><?= e(mon_local($r['last_finished'])) ?></td><td><?= e(mon_local($r['last_success'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<div class="card" id="laufende">
    <h2>Laufende und unbestätigte Ausführungen</h2>
    <?php $runs = $available ? $pdo->query("SELECT r.*, o.name AS org_name FROM job_runs r LEFT JOIN organizations o ON o.id = r.tenant_id WHERE r.status IN ('running','unknown') AND r.heartbeat_at >= '" . mon_utc($now - 86400) . "' ORDER BY r.started_at DESC LIMIT 50")->fetchAll() : []; ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Typ</th><th>Firma</th><th>Start</th><th>Heartbeat</th><th>Zustand</th></tr></thead>
        <tbody>
        <?php if (!$runs): ?><tr><td colspan="5" class="hint">Keine laufenden oder unbestätigten Ausführungen.</td></tr><?php endif; ?>
        <?php foreach ($runs as $r): ?>
            <tr><td><?= e($r['job_type']) ?></td><td><?= e($r['org_name'] ?? '-') ?></td><td><?= e(mon_local($r['started_at'])) ?></td><td><?= e(mon_age_label($now - (mon_ts($r['heartbeat_at']) ?? $now))) ?></td>
                <td><?= $r['status'] === 'running' ? monitor_state_badge('ok', 'Läuft') : monitor_state_badge('unknown', 'Ausführung unbestätigt, möglicherweise abgebrochen') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <p class="hint">Die Kennzeichnung ändert keine fachliche Sperre und startet keinen Job erneut.</p>
</div>
<div class="card">
    <h2>Letzte fehlgeschlagene Ausführungen</h2>
    <?php $fails = $available ? $pdo->query("SELECT r.*, o.name AS org_name FROM job_runs r LEFT JOIN organizations o ON o.id = r.tenant_id WHERE r.status = 'failed' ORDER BY r.finished_at DESC LIMIT 20")->fetchAll() : []; ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Typ</th><th>Firma</th><th>Abschluss</th><th>Laufzeit</th><th>Fehlerkategorie</th></tr></thead>
        <tbody>
        <?php if (!$fails): ?><tr><td colspan="5" class="hint">Keine fehlgeschlagenen Ausführungen gespeichert.</td></tr><?php endif; ?>
        <?php foreach ($fails as $r): ?>
            <tr><td><?= e($r['job_type']) ?></td><td><?= e($r['org_name'] ?? '-') ?></td><td><?= e(mon_local($r['finished_at'])) ?></td><td><?= monitor_ms($r['duration_ms'] !== null ? (int)$r['duration_ms'] : null) ?></td><td><?= monitor_category_label($r['error_category']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<?php if ($tab === 'jobs'): ?>
<?php
$queueOk = queue_available();
$queueGlobalOn = feature_enabled('queue');
?>
<div class="card">
    <h2>Warteschlange</h2>
    <p class="hint">Globaler Status: <?= $queueGlobalOn ? monitor_state_badge('ok', 'Global aktiv') : monitor_state_badge('unknown', 'Global inaktiv (Feature-Flag "queue")') ?>
        <?php if (!$queueOk): ?> · Migration 018 fehlt, alle Kennzahlen sind leer. Der bestehende Cron arbeitet unverändert weiter.<?php endif; ?></p>
<?php if ($queueOk):
    $qWindows = monitor_windows();
    $qStats = [];
    foreach ($qWindows as $wk => $win) {
        $qStats[$wk] = queue_stats($now - (int)$win['seconds'], $now);
    }
    $qNow = $qStats['24h']['now'];
    $qOldestAge = $qStats['24h']['oldest_waiting_age'];
    $qOldestType = $qStats['24h']['oldest_waiting_type'];
?>
    <div class="card-grid stat-row">
        <div class="stat-card"><div class="stat-value"><?= (int)$qNow['queued'] ?></div><div class="stat-label">Wartend</div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$qNow['processing'] ?></div><div class="stat-label">Aktiv (in Bearbeitung)</div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$qNow['retry'] ?></div><div class="stat-label">Erneuter Versuch geplant</div></div>
        <div class="stat-card"><div class="stat-value"><?= (int)$qNow['failed'] ?></div><div class="stat-label">Fehlgeschlagen (Dead Letter)</div></div>
        <div class="stat-card"><div class="stat-value"><?= $qOldestAge === null ? 'Keiner' : mon_age_label($qOldestAge) ?></div><div class="stat-label">Ältester wartender Job<?= $qOldestType ? '<span class="stat-sub">' . e(queue_type_label($qOldestType)) . '</span>' : '' ?></div></div>
    </div>
    <h3 class="mon-h3">Durchsatz und Dauer je Zeitfenster</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Zeitfenster</th><th>Abgeschlossen</th><th>Fehlgeschlagen</th><th>Jobs/min</th><th>Jobs/Std</th><th>Dauer Durchschnitt</th><th>Dauer 95. Perzentil</th></tr></thead>
        <tbody>
        <?php foreach ($qWindows as $wk => $win): $s = $qStats[$wk]['window']; ?>
            <tr>
                <td><?= e($win['label']) ?></td>
                <td><?= (int)$s['completed'] ?></td>
                <td><?= (int)$s['failed'] ?></td>
                <td><?= $s['per_minute'] === null ? '-' : e(number_format((float)$s['per_minute'], 2, ',', '.')) ?></td>
                <td><?= $s['per_minute'] === null ? '-' : e(number_format((float)$s['per_minute'] * 60, 1, ',', '.')) ?></td>
                <td><?= $s['n'] ? monitor_ms($s['avg_ms']) . ' (n=' . (int)$s['n'] . ')' : 'Keine Daten' ?></td>
                <td><?= $s['n'] ? monitor_ms($s['p95_ms']) . ' (n=' . (int)$s['n'] . ')' : 'Keine Daten' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card" id="aktive-jobs">
    <h2>Aktive Jobs</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Typ</th><th>Fortschritt</th><th>Worker</th><th>Start</th><th>Laufzeit</th><th>Status</th></tr></thead>
        <tbody>
        <?php $activeJobs = queue_active_jobs(50); if (!$activeJobs): ?><tr><td colspan="7" class="hint">Keine aktiven Jobs.</td></tr><?php endif; ?>
        <?php foreach ($activeJobs as $j): $jStarted = queue_ts($j['started_at']); ?>
            <tr>
                <td><?= e($j['org_name'] ?? ($j['tenant_id'] ? (string)$j['tenant_id'] : 'Plattform')) ?></td>
                <td><?= e(queue_type_label((string)$j['type'])) ?></td>
                <td>
                    <?php if ($j['progress'] !== null): ?>
                        <div class="job-bar"><span style="width:<?= (int)$j['progress'] ?>%"></span></div>
                        <div class="hint"><?= (int)$j['progress'] ?> %<?= $j['progress_text'] ? ' · ' . e((string)$j['progress_text']) : '' ?></div>
                    <?php else: ?>
                        <span class="hint"><?= e((string)($j['progress_text'] ?: '-')) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e((string)($j['locked_by'] ?: '-')) ?></td>
                <td><?= $jStarted !== null ? e(mon_local($j['started_at'])) : '-' ?></td>
                <td><?= $jStarted !== null ? e(mon_age_label($now - $jStarted)) : '-' ?></td>
                <td><?= e(QUEUE_STATE_LABELS[$j['status']] ?? (string)$j['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Worker</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Pool</th><th>Host</th><th>Status</th><th>Heartbeat</th><th>Aktueller Job</th><th>Erledigt</th><th>Fehlgeschlagen</th><th>Lebend</th></tr></thead>
        <tbody>
        <?php $workerRows = workers_list(); if (!$workerRows): ?><tr><td colspan="8" class="hint">Kein Worker und kein Scheduler gemeldet.</td></tr><?php endif; ?>
        <?php foreach ($workerRows as $wr): ?>
            <tr>
                <td><?= e($wr['pool']) ?></td>
                <td><?= e((string)($wr['hostname'] ?: '-')) ?><?= $wr['pid'] ? ' (PID ' . (int)$wr['pid'] . ')' : '' ?></td>
                <td><?= e(['idle' => 'Bereit', 'busy' => 'Beschäftigt', 'stopping' => 'Wird beendet', 'stopped' => 'Gestoppt'][$wr['status']] ?? (string)$wr['status']) ?></td>
                <td><?= e(mon_age_label($wr['age'])) ?></td>
                <td><?= e((string)($wr['current_job_id'] ?: '-')) ?></td>
                <td><?= (int)$wr['jobs_done'] ?></td>
                <td><?= (int)$wr['jobs_failed'] ?></td>
                <td><?= $wr['alive'] ? monitor_state_badge('ok', 'Ja') : monitor_state_badge('fail', 'Nein') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Circuit Breaker</h2>
    <p class="hint">Pausiert automatisch Aufrufe an eine Anbindung nach wiederholten technischen Fehlern und testet nach einer Wartezeit erneut.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Anbindung</th><th>Zustand</th><th>Fehlerzähler</th><th>Letzter Fehler</th><th>Nächster Testaufruf</th></tr></thead>
        <tbody>
        <?php foreach (['lexoffice' => 'Lexware Office', 'stripe' => 'Stripe', 'mail' => 'E-Mail'] as $api => $apiLabel): $cs = circuit_state($api); $badgeState = ['closed' => 'ok', 'half_open' => 'degraded', 'open' => 'fail'][$cs['state']] ?? 'unknown'; ?>
            <tr>
                <td><?= e($apiLabel) ?></td>
                <td><?= monitor_state_badge($badgeState, circuit_label((string)$cs['state'])) ?></td>
                <td><?= (int)$cs['failures'] ?></td>
                <td><?= $cs['last_failure_at'] ? e(mon_local($cs['last_failure_at'])) . ' (' . monitor_category_label($cs['last_failure_category']) . ')' : '-' ?></td>
                <td><?= $cs['state'] === 'open' && $cs['next_probe_at'] ? e(mon_local($cs['next_probe_at'])) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card" id="wartend">
    <h2>Wartende Aufgaben</h2>
    <p class="hint">Aufschlüsselung der Kennzahl aus der Übersicht: Jobs in der Warteschlange, Reservierungen
    ohne Lebenszeichen des Workers, offene Synchronisationsläufe und fällige Einzüge. Die Aktionen wirken
    nur auf die Warteschlange, sie lösen selbst keinen Einzug aus.</p>

    <h3 class="mon-h3">Jobs in der Warteschlange</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Typ</th><th>Eingereiht von</th><th>Eingereiht</th><th>Nächster Versuch</th><th>Versuche</th><th>Letzter Fehler</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php
        $waitingJobs = queue_waiting_jobs(100);
        if (!$waitingJobs): ?><tr><td colspan="8" class="hint">Keine wartenden Jobs.</td></tr><?php endif; ?>
        <?php foreach ($waitingJobs as $j):
            $avail = queue_ts($j['available_at']);
            $created = queue_ts($j['created_at']);
            // "Eingereiht von": Benutzer, sonst der Scheduler (automatische Aufgabe ohne Benutzerbezug).
            $von = trim((string)($j['user_name'] ?? '')) !== '' ? (string)$j['user_name']
                 : (trim((string)($j['user_email'] ?? '')) !== '' ? (string)$j['user_email'] : 'Automatisch (Scheduler)');
        ?>
            <tr>
                <td><?= e($j['org_name'] ?? ($j['tenant_id'] ? (string)$j['tenant_id'] : 'Plattform')) ?></td>
                <td><?= e(queue_type_label((string)$j['type'])) ?></td>
                <td><?= e($von) ?></td>
                <td><?= e(mon_local($j['created_at'])) ?><?= $created !== null ? ' <span class="hint">(' . e(mon_age_label($now - $created)) . ')</span>' : '' ?></td>
                <td><?php if ($avail === null): ?>-<?php elseif ($avail <= $now): ?><span class="hint">sofort möglich</span><?php else: ?><?= e(mon_local($j['available_at'])) ?> <span class="hint">(in <?= e(mon_age_label($avail - $now)) ?>)</span><?php endif; ?></td>
                <td><?= (int)$j['attempts'] ?> / <?= (int)$j['max_attempts'] ?><?= $j['status'] === 'retry' ? ' <span class="hint">(Wiederholung)</span>' : '' ?></td>
                <td class="hint"><?= e((string)($j['last_error'] ?: '-')) ?></td>
                <td>
                    <?php if ($canEdit): ?>
                    <?php $money = queue_type_is_money($j['type'] ?? null); ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_retry_now"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if ($money): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-secondary">Jetzt ausführen</button></form>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_cancel"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if ($money): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-secondary">Abbrechen</button></form>
                    <?php else: ?><span class="hint">Nur mit Bearbeitungsrecht</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <h3 class="mon-h3">Reservierungen ohne Lebenszeichen des Workers</h3>
    <p class="hint">Der Job ist reserviert, sein Worker meldet sich aber länger als die Frist des Jobtyps nicht
    mehr (Heartbeat). Die Wartung gibt solche Jobs automatisch als Fehlversuch frei; hier lassen sie sich
    vorher ohne Fehlversuch freigeben.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Typ</th><th>Worker</th><th>Start</th><th>Läuft seit</th><th>Letztes Lebenszeichen</th><th>Frist</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php $staleJobs = queue_stale_reservations(50); if (!$staleJobs): ?><tr><td colspan="8" class="hint">Keine Reservierung ohne Lebenszeichen.</td></tr><?php endif; ?>
        <?php foreach ($staleJobs as $j): $sStart = queue_ts($j['started_at']); ?>
            <tr>
                <td><?= e($j['org_name'] ?? ($j['tenant_id'] ? (string)$j['tenant_id'] : 'Plattform')) ?></td>
                <td><?= e(queue_type_label((string)$j['type'])) ?></td>
                <td><?= e((string)($j['locked_by'] ?: '-')) ?></td>
                <td><?= $sStart !== null ? e(mon_local($j['started_at'])) : '-' ?></td>
                <td><?= $sStart !== null ? e(mon_age_label($now - $sStart)) : '-' ?></td>
                <td><?= $j['heartbeat_age'] === null ? '<span class="hint">nie</span>' : e(mon_age_label((int)$j['heartbeat_age'])) . ' <span class="hint">her</span>' ?></td>
                <td><?= (int)$j['heartbeat_ttl'] ?> s</td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_release"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if (queue_type_is_money($j['type'] ?? null)): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-secondary">Reservierung freigeben</button></form>
                    <?php else: ?><span class="hint">Nur mit Bearbeitungsrecht</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <h3 class="mon-h3">Offene Synchronisationsläufe</h3>
    <p class="hint">Ein Lauf gilt als offen, solange er nicht abgeschlossen ist. "Hängt" bedeutet: keine
    aktive Sperre und kein Job, der ihn fortsetzt. Der Scheduler schließt solche Läufe und reiht die
    Fortsetzung sofort ein; mit der Aktion geht das ohne Wartezeit. Die Fortsetzung beginnt am gespeicherten
    Zwischenstand, nicht von vorn.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Gestartet</th><th>Letzter Schritt</th><th>Fortschritt</th><th>Zustand</th><th>Letzter Fehler</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php $openRuns = sync_open_runs(50); if (!$openRuns): ?><tr><td colspan="7" class="hint">Kein offener Synchronisationslauf.</td></tr><?php endif; ?>
        <?php foreach ($openRuns as $r): $prog = sync_progress($r); ?>
            <tr>
                <td><?= e($r['org_name'] ?? (string)$r['tenant_id']) ?><?= (int)($r['sync_paused'] ?? 0) === 1 ? ' <span class="hint">(pausiert)</span>' : '' ?></td>
                <td><?= e(mon_local($r['started_at'])) ?></td>
                <td><?= $r['last_step_at'] ? e(mon_local($r['last_step_at'])) : '<span class="hint">noch keiner</span>' ?><?= $r['state_age_seconds'] !== null ? ' <span class="hint">(' . e(mon_age_label((int)$r['state_age_seconds'])) . ' ohne Fortschritt)</span>' : '' ?></td>
                <td><?= $prog['percent'] !== null ? (int)$prog['percent'] . ' %' : '<span class="hint">unbekannt</span>' ?><span class="stat-sub"><?= e((string)$prog['text']) ?></span></td>
                <td><?php if (!empty($r['lock_active'])): ?><?= monitor_state_badge('ok', 'Wird bearbeitet') ?><?php elseif ($r['job'] !== null): ?><?= monitor_state_badge('ok', 'Job eingereiht') ?><?php else: ?><?= monitor_state_badge('unknown', 'Hängt (kein Job)') ?><?php endif; ?></td>
                <td class="hint"><?= e((string)($r['last_error'] ?: '-')) ?></td>
                <td>
                    <?php if ($canEdit && $r['job'] === null && (int)($r['sync_paused'] ?? 0) !== 1): ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="sync_enqueue"><input type="hidden" name="org_id" value="<?= e((string)$r['tenant_id']) ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Fortsetzung einreihen</button></form>
                    <?php elseif ($r['job'] !== null): ?><span class="hint">Job wartet bereits</span>
                    <?php elseif (!$canEdit): ?><span class="hint">Nur mit Bearbeitungsrecht</span>
                    <?php else: ?><span class="hint">Synchronisation pausiert</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <h3 class="mon-h3">Fällige Einzüge und Einreichfenster</h3>
    <?php
    $qz = monitor_queue();
    $rulesCfg = collections_rules_config();
    $winOpen = collections_window_open();
    $nextOpen = collections_window_next_open();
    ?>
    <dl class="kv">
        <dt>Fällige, noch nicht eingereichte Einzüge</dt>
        <dd><?= (int)$qz['collections_due'] ?><?= $qz['collections_oldest_age'] !== null ? ' (ältester seit ' . e(mon_age_label((int)$qz['collections_oldest_age'])) . ')' : '' ?></dd>
        <dt>Einreichfenster</dt>
        <dd><?php if (!$rulesCfg['window_enabled']): ?>Nicht eingeschränkt (rund um die Uhr)
            <?php elseif ($winOpen): ?><?= monitor_state_badge('ok', 'offen') ?> <?= e($rulesCfg['window_start']) ?> bis <?= e($rulesCfg['window_end']) ?>
            <?php else: ?><?= monitor_state_badge('unknown', 'geschlossen') ?> <?= e($rulesCfg['window_start']) ?> bis <?= e($rulesCfg['window_end']) ?>, nächste Öffnung <?= e($nextOpen->format('d.m.Y H:i')) ?><?php endif; ?></dd>
    </dl>
    <p class="hint">Das Einreichfenster begrenzt ausschließlich das Einreichen von Lastschriften. Synchronisation,
    Klärung unklarer Versuche, Statusabrufe bei Stripe, Monitoring und E-Mail laufen unabhängig davon rund um die Uhr.</p>
</div>

<div class="card">
    <h2>Dead Letter (dauerhaft fehlgeschlagene Jobs)</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Typ</th><th>Versuche</th><th>Letzter Fehler</th><th>Abschluss</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php $failedJobs = queue_failed_jobs(50); if (!$failedJobs): ?><tr><td colspan="6" class="hint">Keine offenen Dead-Letter-Einträge.</td></tr><?php endif; ?>
        <?php foreach ($failedJobs as $j): ?>
            <tr>
                <td><?= e($j['org_name'] ?? ($j['tenant_id'] ? (string)$j['tenant_id'] : 'Plattform')) ?></td>
                <td><?= e(queue_type_label((string)$j['type'])) ?></td>
                <td><?= (int)$j['attempts'] ?> / <?= (int)$j['max_attempts'] ?></td>
                <td class="hint"><?= e((string)($j['last_error'] ?: '-')) ?></td>
                <td><?= e(mon_local($j['finished_at'])) ?></td>
                <td>
                    <?php if ($canEdit): ?>
                    <?php $money = queue_type_is_money($j['type'] ?? null); ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_retry_now"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if ($money): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-secondary">Erneut versuchen</button></form>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_cancel"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if ($money): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-secondary">Abbrechen</button></form>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_close"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <?php if ($money): ?><input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA" title="Geldbewegender Job: Zweitbestätigung"><?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-danger">Dauerhaft schließen</button></form>
                    <?php else: ?>
                        <span class="hint">Nur mit Bearbeitungsrecht (monitoring.editors)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Wartungsmodus je Firma (Synchronisation pausieren)</h2>
    <p class="hint">Pausiert ausschließlich die automatische und manuell ausgelöste Synchronisation dieser Firma, keine Einzüge.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Zustand</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php $orgsForJobs = db()->query('SELECT id, name, sync_paused, sync_paused_reason FROM organizations WHERE deleted_at IS NULL ORDER BY name')->fetchAll(); ?>
        <?php if (!$orgsForJobs): ?><tr><td colspan="3" class="hint">Keine Firmen vorhanden.</td></tr><?php endif; ?>
        <?php foreach ($orgsForJobs as $o): $oPaused = (int)$o['sync_paused'] === 1; ?>
            <tr>
                <td><?= e($o['name']) ?></td>
                <td><?= $oPaused ? monitor_state_badge('maintenance', 'Pausiert') : monitor_state_badge('ok', 'Aktiv') ?><?php if ($oPaused && $o['sync_paused_reason']): ?><div class="hint"><?= e((string)$o['sync_paused_reason']) ?></div><?php endif; ?></td>
                <td>
                    <?php if (!$canEdit): ?>
                        <span class="hint">Nur mit Bearbeitungsrecht</span>
                    <?php elseif ($oPaused): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="org_sync_resume"><input type="hidden" name="org_id" value="<?= e($o['id']) ?>">
                            <button type="submit" class="btn btn-sm">Fortsetzen</button></form>
                    <?php else: ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="org_sync_pause"><input type="hidden" name="org_id" value="<?= e($o['id']) ?>">
                            <input type="text" name="reason" placeholder="Grund" maxlength="160" required>
                            <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
                            <button type="submit" class="btn btn-sm btn-secondary">Pausieren</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Feature-Flag "Warteschlange" je Firma</h2>
    <p class="hint">Global <?= $queueGlobalOn ? 'aktiv für alle Firmen' : 'inaktiv' ?>. Ohne Freischaltung läuft eine Firma unverändert über den bestehenden Cron.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Firma</th><th>Zustand</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($orgsForJobs as $o): $tenantOn = in_array('queue', tenant_feature_flags((string)$o['id']), true); $effective = feature_enabled('queue', (string)$o['id']); ?>
            <tr>
                <td><?= e($o['name']) ?></td>
                <td><?= $effective ? monitor_state_badge('ok', $tenantOn ? 'Aktiv (Firma)' : 'Aktiv (global)') : monitor_state_badge('unknown', 'Inaktiv') ?></td>
                <td>
                    <?php if (!$canEdit): ?>
                        <span class="hint">Nur mit Bearbeitungsrecht</span>
                    <?php elseif ($queueGlobalOn): ?>
                        <span class="hint">Durch globales Flag festgelegt</span>
                    <?php else: ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $tenantOn ? 'org_queue_flag_off' : 'org_queue_flag_on' ?>"><input type="hidden" name="org_id" value="<?= e($o['id']) ?>">
                            <button type="submit" class="btn btn-sm <?= $tenantOn ? 'btn-secondary' : '' ?>"><?= $tenantOn ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php else: ?>
<div class="card">
    <p class="hint">Wartungsmodus und Feature-Flag je Firma benötigen die Datenbankmigration 018 (organizations.sync_paused, organizations.feature_flags).</p>
</div>
<?php endif; // queueOk ?>
<?php endif; ?>

<?php if ($tab === 'server'): ?>
<?php
$hostMetrics = [
    'host_cpu'        => ['label' => 'CPU-Auslastung (Server)', 'unit' => '%', 'missing' => 'Vom Hosting nicht bereitgestellt'],
    'host_mem'        => ['label' => 'RAM-Auslastung (Server)', 'unit' => '%', 'missing' => 'Vom Hosting nicht bereitgestellt'],
    'host_disk'       => ['label' => 'Festplattenauslastung', 'unit' => '%', 'missing' => 'Vom Hosting nicht bereitgestellt'],
    'host_load1'      => ['label' => 'Systemlast (1 Minute)', 'unit' => '', 'missing' => 'Vom Hosting nicht bereitgestellt'],
    'db_connections'  => ['label' => 'Datenbankverbindungen', 'unit' => '', 'missing' => 'Noch keine Messdaten'],
    'db_qps'          => ['label' => 'Datenbank-Abfragen je Sekunde', 'unit' => 'q/s', 'missing' => 'Noch keine Messdaten'],
    'db_slow_queries' => ['label' => 'Langsame Datenbankabfragen (gesamt)', 'unit' => '', 'missing' => 'Noch keine Messdaten'],
    'redis_mem'       => ['label' => 'Redis-Speichernutzung', 'unit' => 'MB', 'missing' => 'Noch keine Messdaten'],
];
?>
<div class="card">
    <h2>Server- und Infrastrukturkennzahlen</h2>
    <p class="hint">Erfasst durch bin/host-metrics.php auf dem VPS (Host-/proc lesend, Plattenbelegung des Release-Dateisystems). Auf dem IONOS Webhosting oder ohne diesen Sammler bleiben die host_*-Werte "Vom Hosting nicht bereitgestellt".</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Messwert</th><th>Aktueller Wert</th><th>Zeitpunkt</th></tr></thead>
        <tbody>
        <?php foreach ($hostMetrics as $hmKey => $hmDef): $hmLatest = monitor_latest($hmKey); ?>
            <tr>
                <td><?= e($hmDef['label']) ?></td>
                <td><?= $hmLatest && $hmLatest['value_num'] !== null ? e(number_format((float)$hmLatest['value_num'], 1, ',', '.')) . ($hmDef['unit'] !== '' ? ' ' . e($hmDef['unit']) : '') : $hmDef['missing'] ?></td>
                <td><?= $hmLatest ? e(mon_local($hmLatest['checked_at'])) . ' (' . e(mon_age_label($now - (mon_ts($hmLatest['checked_at']) ?? $now))) . ')' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php foreach ($hostMetrics as $hmKey => $hmDef): $hmSeries = monitor_view_series($hmKey, $now - 3600, $now, 300); if (!$hmSeries) { continue; } ?>
        <?= chart_bars($hmSeries, $hmDef['label'] . ' (letzte Stunde)', fn($v) => number_format($v, 1, ',', '.') . ($hmDef['unit'] !== '' ? ' ' . $hmDef['unit'] : '')) ?>
    <?php endforeach; ?>
</div>

<?php
$compOverviewSrv = monitor_components_overview();
$queueGlobalOn = feature_enabled('queue');
try {
    $srvOrgFlagRow = queue_available() ? db()->query("SELECT 1 FROM organizations WHERE deleted_at IS NULL AND feature_flags LIKE '%queue%' LIMIT 1")->fetchColumn() : false;
} catch (Throwable $e) {
    $srvOrgFlagRow = false;
}
$queueActiveAnywhere = $queueGlobalOn || (bool)$srvOrgFlagRow;
$overallState = 'ok';
$srvReasons = [];
foreach ($compOverviewSrv as $c) {
    if (in_array($c['key'], ['php_app', 'db', 'cron'], true) && $c['state'] === 'fail') {
        $overallState = 'fail';
        $srvReasons[] = $c['name'] . ': Störung';
    }
}
if ($queueActiveAnywhere) {
    if (workers_alive('scheduler') <= 0) { $overallState = 'fail'; $srvReasons[] = 'Kein lebender Scheduler trotz aktiver Warteschlange'; }
    if (workers_alive() <= 0) { $overallState = 'fail'; $srvReasons[] = 'Kein lebender Worker trotz aktiver Warteschlange'; }
}
if ($overallState !== 'fail') {
    foreach ($compOverviewSrv as $c) {
        if ($c['state'] === 'degraded') { $overallState = 'degraded'; $srvReasons[] = $c['name'] . ': Eingeschränkt'; }
        elseif ($c['stale']) { $overallState = 'degraded'; $srvReasons[] = $c['name'] . ': Messung veraltet (' . mon_age_label($c['age']) . ')'; }
    }
    if (queue_available()) {
        foreach (['lexoffice', 'stripe', 'mail'] as $api) {
            $csState = circuit_state($api);
            if ($csState['state'] !== 'closed') { $overallState = 'degraded'; $srvReasons[] = 'Circuit ' . $api . ': ' . circuit_label((string)$csState['state']); }
        }
        $srvFailedNow = (int)(queue_stats($now - 86400, $now)['now']['failed'] ?? 0);
        if ($srvFailedNow > 0) { $overallState = 'degraded'; $srvReasons[] = $srvFailedNow . ' fehlgeschlagene(r) Job(s) offen'; }
    }
    $diskLatestSrv = monitor_latest('host_disk');
    if ($diskLatestSrv && $diskLatestSrv['value_num'] !== null && (float)$diskLatestSrv['value_num'] > 85) {
        $overallState = 'degraded'; $srvReasons[] = 'Festplattenauslastung über 85 %';
    }
}
$srvStateLabels = ['ok' => 'System OK', 'degraded' => 'System Warning', 'fail' => 'System Critical'];
?>
<div class="card">
    <h2>Betriebsstatus</h2>
    <p><?= monitor_state_badge($overallState, $srvStateLabels[$overallState]) ?></p>
    <?php if ($srvReasons): ?><ul class="mon-warnings"><?php foreach ($srvReasons as $sr): ?><li>▲ <?= e($sr) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="hint">Keine Auffälligkeiten festgestellt.</p><?php endif; ?>
</div>

<div class="card">
    <h2>Versionen</h2>
    <dl class="kv">
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
        <dt>MariaDB</dt><dd><?php try { echo e((string)db()->query('SELECT VERSION()')->fetchColumn()); } catch (Throwable $e) { echo 'Nicht ermittelbar'; } ?></dd>
        <dt>Redis</dt><dd><?php
            $rc = redis_client();
            if ($rc) {
                try { $rInfo = $rc->info('server'); echo e((string)($rInfo['redis_version'] ?? 'erreichbar, Version unbekannt')); }
                catch (Throwable $e) { echo 'Erreichbar, Version nicht ermittelbar'; }
            } else { echo 'Nicht erreichbar oder nicht konfiguriert'; }
        ?></dd>
        <dt>Docker-Image</dt><dd><?php
            $imgTag = trim((string)(getenv('DOCKER_IMAGE_TAG') ?: ''));
            if ($imgTag === '') {
                $bf = APP_ROOT . '/build.txt';
                $imgTag = is_file($bf) ? trim((string)@file_get_contents($bf)) : '';
            }
            echo $imgTag !== '' ? e($imgTag) : 'Nicht erfasst';
        ?></dd>
        <dt>Anwendungsversion</dt><dd><?= e(APP_VERSION) ?><?= ($appBi = app_build_info()) ? ' · Build ' . e($appBi) : '' ?></dd>
        <dt>Warteschlangenmodus</dt><dd><?= $queueGlobalOn ? 'Global aktiv' : 'Global inaktiv (ggf. je Firma freigeschaltet)' ?></dd>
    </dl>
</div>

<div class="card">
    <h2>Datenbank</h2>
    <?php
    try {
        $dbTableRows = db()->query('SELECT table_name, (data_length + index_length) AS bytes, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY bytes DESC')->fetchAll();
    } catch (Throwable $e) {
        $dbTableRows = [];
    }
    $dbTotalBytes = array_sum(array_column($dbTableRows, 'bytes'));
    ?>
    <p>Gesamtgröße: <?= $dbTableRows ? e(number_format($dbTotalBytes / 1048576, 1, ',', '.')) . ' MB' : 'Nicht ermittelbar' ?></p>
    <h3 class="mon-h3">Größte Tabellen</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Tabelle</th><th>Größe</th><th>Zeilen (geschätzt)</th></tr></thead>
        <tbody>
        <?php if (!$dbTableRows): ?><tr><td colspan="3" class="hint">Nicht ermittelbar.</td></tr><?php endif; ?>
        <?php foreach (array_slice($dbTableRows, 0, 15) as $tr): ?>
            <tr><td><?= e((string)$tr['table_name']) ?></td><td><?= e(number_format(((int)$tr['bytes']) / 1048576, 2, ',', '.')) ?> MB</td><td><?= number_format((int)$tr['table_rows'], 0, ',', '.') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Sicherung</h2>
    <?php $backupLatest = monitor_latest('backup'); $backupOkAt = monitor_mark_get('backup_last_ok_at'); ?>
    <dl class="kv">
        <dt>Letzte gemeldete Sicherung</dt><dd><?= $backupLatest ? e(mon_local($backupLatest['checked_at'])) . ' · ' . monitor_state_badge((string)$backupLatest['status']) : 'Nicht eingerichtet' ?></dd>
        <dt>Letzte erfolgreiche Sicherung</dt><dd><?= $backupOkAt ? e(mon_local($backupOkAt)) : 'Nicht eingerichtet' ?></dd>
    </dl>
</div>
<?php endif; ?>

<?php if ($tab === 'verfuegbarkeit'): ?>
<div class="mon-windows">Zeitraum:
    <?php foreach ([7, 30, 90] as $dd): ?><a href="admin-system.php?tab=verfuegbarkeit&amp;d=<?= $dd ?>"<?= $dd === $d ? ' class="active"' : '' ?>><?= $dd ?> Tage</a><?php endforeach; ?>
    <span class="hint">Zeitgewichtet aus periodischen Prüfungen (Gültigkeit je Messung begrenzt). Formel: Verfügbarkeit = T_ok / (T_ok + T_ausfall); Messabdeckung = (T_ok + T_ausfall) / Fenster. Unbekannte Zeit zählt weder als Erfolg noch als Ausfall. Wartung wird nicht herausgerechnet.</span>
</div>
<?php
$firstRaw = $available ? mon_ts(db()->query('SELECT MIN(checked_at) FROM monitor_checks')->fetchColumn() ?: null) : null;
$firstDay = $available ? (db()->query('SELECT MIN(day) FROM monitor_daily')->fetchColumn() ?: null) : null;
$since = $firstRaw !== null ? mon_local(mon_utc($firstRaw)) : ($firstDay ? $firstDay : 'noch keine Daten');
$winFrom = $now - $d * 86400;
?>
<div class="card">
    <h2>Nutzerfunktionen (öffentliche Komponenten)</h2>
    <p class="hint">Beginn der Datenerfassung: <?= e($since) ?>. Prozentwerte erscheinen öffentlich nur ab <?= e(number_format((float)$cfg['public_min_coverage_pct'], 0, ',', '.')) ?> % Messabdeckung (Produkteinstellung).</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Funktion</th><th>Verfügbarkeit (beobachtet)</th><th>Messabdeckung</th><th>Verfügbare Stunden</th><th>Ausfall</th><th>Unbekannt</th><th>Verlauf <?= $d ?> Tage</th></tr></thead>
        <tbody>
        <?php foreach (monitor_public_components() as $key => $def): $a = monitor_public_availability($key, $d); $hist = monitor_public_daily_history($key, $d); $worst = null;
            foreach ($def['internal'] as $ic) { $u = monitor_uptime($ic, $winFrom, $now); if ($worst === null || ($u['availability_pct'] ?? 101) < ($worst['availability_pct'] ?? 101)) { $worst = $u; } } ?>
            <tr><td><?= e($def['name']) ?><div class="hint"><?= e(implode(', ', $def['internal'])) ?></div></td>
                <td><?= $worst ? monitor_pct($worst['availability_pct']) : 'Keine Daten' ?><?php if ($worst && $worst['availability_pct'] !== null): ?><div class="hint">konservativ inkl. unbekannter Zeit: <?= e(number_format((float)$worst['conservative_min_pct'], 3, ',', '.')) ?> %</div><?php endif; ?></td>
                <td><?= $worst ? e(number_format((float)$worst['coverage_pct'], 2, ',', '.')) . ' %' : '-' ?></td>
                <td><?= $worst ? e(number_format((float)$worst['available_hours'], 2, ',', '.')) . ' h' : '-' ?></td>
                <td><?= $worst ? e($worst['downtime_label']) : '-' ?></td>
                <td><?= $worst ? e($worst['unknown_label']) : '-' ?></td>
                <td><?= monitor_history_bar($hist) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<div class="card">
    <h2>Interne Komponenten</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Komponente</th><th>Verfügbarkeit</th><th>Messabdeckung</th><th>Prüfungen / Fehlprüfungen</th><th>Letzte erkannte Störung</th><th>Seit letztem bestätigten Ausfall</th></tr></thead>
        <tbody>
        <?php foreach (monitor_components_overview() as $c): if (in_array($c['key'], ['db_size', 'storage', 'sftp'], true)) continue; $u = monitor_uptime($c['key'], $winFrom, $now);
            $lastFail = $available ? (db()->prepare("SELECT checked_at FROM monitor_checks WHERE component = ? AND status = 'fail' ORDER BY checked_at DESC LIMIT 1")) : null;
            $lf = null; if ($lastFail) { $lastFail->execute([$c['key']]); $lf = $lastFail->fetchColumn() ?: null; } ?>
            <tr><td><?= e($c['name']) ?></td><td><?= monitor_pct($u['availability_pct']) ?></td><td><?= e(number_format((float)$u['coverage_pct'], 2, ',', '.')) ?> %</td><td><?= (int)$u['checks'] ?> / <?= (int)$u['fails'] ?></td>
                <td><?= $lf ? e(mon_local($lf)) : 'Keine im Zeitraum erfasst' ?></td>
                <td><?= $lf ? e(mon_duration_label($now - (mon_ts($lf) ?? $now))) . ($u['t_unknown'] > 0 ? ' (unbekannte Zeiträume nicht als störungsfrei bestätigt)' : '') : ($u['checks'] > 0 ? 'Kein Ausfall erfasst seit Erfassungsbeginn' : 'Keine Daten') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <p class="hint">Serverlaufzeit seit Neustart und PHP-Prozesslaufzeit sind nicht verfügbar und werden nicht durch die Zeit seit dem letzten Deployment ersetzt.</p>
</div>
<?php endif; ?>

<?php if ($tab === 'stoerungen'): ?>
<?php $incidents = monitor_incidents_list(false, 50); $pubComps = monitor_public_components(); ?>
<div class="card">
    <h2>Störungen und Wartungen</h2>
    <p class="hint">Öffentliche Texte und interne Notizen sind getrennt. Veröffentlichung nur mit Bearbeitungsrecht und frischer 2FA-Bestätigung; Texte werden von HTML und Skript bereinigt. Eine Meldung ändert keine Messhistorie.</p>
    <?php if (!$incidents): ?><p class="hint">Keine Einträge.</p><?php endif; ?>
    <?php foreach ($incidents as $inc): $ups = monitor_incident_updates($inc['id'], false); $comps = json_decode((string)$inc['components'], true) ?: []; ?>
    <div class="mon-incident" id="inc-<?= e($inc['id']) ?>">
        <h3 class="mon-h3"><?= e($inc['kind'] === 'maintenance' ? 'Wartung' : 'Störung') ?>: <?= e($inc['title']) ?>
            <?= monitor_state_badge($inc['kind'] === 'maintenance' ? 'maintenance' : (in_array($inc['status'], ['resolved', 'completed'], true) ? 'ok' : 'fail'), monitor_phase_label($inc['status'])) ?>
            <?= (int)$inc['published'] ? '<span class="badge badge-success">Veröffentlicht</span>' : '<span class="badge badge-neutral">Entwurf</span>' ?></h3>
        <dl class="kv">
            <dt>Betroffen</dt><dd><?= e(implode(', ', array_map(fn($k) => $pubComps[$k]['name'] ?? $k, $comps)) ?: 'keine Angabe') ?></dd>
            <dt>Beginn / Ende</dt><dd><?= e(mon_local($inc['started_at'])) ?> / <?= e(mon_local($inc['ended_at'])) ?><?= $inc['scheduled_end_at'] ? ' (geplant bis ' . e(mon_local($inc['scheduled_end_at'])) . ')' : '' ?></dd>
            <dt>Öffentliche Vorschau</dt><dd class="mon-preview"><?= nl2br(e((string)$inc['public_message'])) ?: '<span class="hint">kein Text</span>' ?></dd>
            <?php if ($inc['internal_notes']): ?><dt>Interne Notizen</dt><dd class="hint"><?= nl2br(e((string)$inc['internal_notes'])) ?></dd><?php endif; ?>
        </dl>
        <?php if ($ups): ?><ul class="mon-updates"><?php foreach ($ups as $u): ?><li><strong><?= e(monitor_phase_label($u['phase'])) ?></strong> <?= e(mon_local($u['created_at'])) ?><?= $u['public_text'] ? ': ' . nl2br(e((string)$u['public_text'])) : '' ?><?= $u['internal_note'] ? ' <span class="hint">[intern: ' . e((string)$u['internal_note']) . ']</span>' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($canEdit): ?>
        <div class="form-row" style="align-items: flex-end; gap: 12px;">
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="incident_update"><input type="hidden" name="incident_id" value="<?= e($inc['id']) ?>">
                <label>Phase <select name="phase"><?php foreach ($inc['kind'] === 'maintenance' ? ['scheduled', 'active', 'completed'] : ['investigating', 'identified', 'monitoring', 'resolved'] as $ph): ?><option value="<?= $ph ?>"<?= $ph === $inc['status'] ? ' selected' : '' ?>><?= e(monitor_phase_label($ph)) ?></option><?php endforeach; ?></select></label>
                <label>Öffentlicher Text <input type="text" name="public_text" maxlength="2000" placeholder="in Kundensprache"></label>
                <label>Interne Notiz <input type="text" name="internal_note" maxlength="2000"></label>
                <button type="submit" class="btn btn-sm btn-secondary">Verlauf ergänzen</button></form>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= (int)$inc['published'] ? 'incident_unpublish' : 'incident_publish' ?>"><input type="hidden" name="incident_id" value="<?= e($inc['id']) ?>">
                <?php if (!(int)$inc['published']): ?><label>2FA-Code <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code"></label><?php endif; ?>
                <button type="submit" class="btn btn-sm <?= (int)$inc['published'] ? 'btn-secondary' : '' ?>"><?= (int)$inc['published'] ? 'Zurückziehen' : 'Veröffentlichen' ?></button></form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($canEdit): ?>
<div class="card">
    <h2>Neue Störung oder Wartung</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="incident_create">
        <div class="form-row">
            <div><label for="inc_kind">Art</label><select id="inc_kind" name="kind"><option value="incident">Störung</option><option value="maintenance">Wartung</option></select></div>
            <div><label for="inc_title">Titel</label><input type="text" id="inc_title" name="title" required maxlength="160"></div>
        </div>
        <fieldset><legend>Betroffene öffentliche Komponenten</legend>
            <?php foreach ($pubComps as $k => $def): ?><label class="checkbox-label"><input type="checkbox" name="components[]" value="<?= e($k) ?>"> <span><?= e($def['name']) ?></span></label><?php endforeach; ?>
        </fieldset>
        <div class="form-row">
            <div><label for="inc_start">Beginn (lokal, leer = jetzt)</label><input type="datetime-local" id="inc_start" name="started_at"></div>
            <div><label for="inc_end">Geplantes Ende (nur Wartung)</label><input type="datetime-local" id="inc_end" name="scheduled_end_at"></div>
        </div>
        <label for="inc_public">Öffentlicher Text (Kundensprache, ohne technische Interna)</label>
        <textarea id="inc_public" name="public_message" rows="3" maxlength="2000"></textarea>
        <label for="inc_internal">Interne Notizen (werden nie veröffentlicht)</label>
        <textarea id="inc_internal" name="internal_notes" rows="2" maxlength="5000"></textarea>
        <div class="form-actions"><button type="submit" class="btn">Als Entwurf anlegen</button></div>
    </form>
</div>
<div class="card">
    <h2>Statusveröffentlichung</h2>
    <dl class="kv">
        <dt>Ziel</dt><dd><?= $cfg['publish'] ? e(implode(', ', array_keys($cfg['publish']))) : 'Nicht eingerichtet (status_publish in config.php)' ?></dd>
        <dt>Letzte erfolgreiche Übertragung</dt><dd>Datei: <?= e(mon_local(monitor_mark_get('publish_file_last_ok_at'))) ?> · GitHub: <?= e(mon_local(monitor_mark_get('publish_github_last_ok_at'))) ?></dd>
        <dt>Öffentliche Seite</dt><dd><?= $cfg['status_page_url'] !== '' ? '<a href="' . e($cfg['status_page_url']) . '" target="_blank" rel="noopener">' . e($cfg['status_page_url']) . '</a>' : 'Nicht konfiguriert (status_page_url)' ?></dd>
    </dl>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="publish_now">
        <button type="submit" class="btn btn-secondary">Snapshot jetzt übertragen</button></form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'versionen'): require_once __DIR__ . '/app/docs.php';
    // Dokumentationsstand je Softwareversion: aktueller Build plus Archiv
    $docsRevByVersion = [];
    $mCur = docs_manifest();
    if (is_array($mCur)) { $docsRevByVersion[(string)$mCur['version']] = implode(', ', array_map(static fn($d) => (string)$d['code'] . ' ' . (string)$d['revision'], (array)$mCur['documents'])); }
    foreach (docs_archive_list() as $ar) { $docsRevByVersion[$ar['version']] = $docsRevByVersion[$ar['version']] ?? implode(', ', array_map(static fn($d) => (string)$d['code'] . ' ' . (string)$d['revision'], $ar['documents'])); }
?>
<div class="card">
    <h2>Anwendungsversion</h2>
    <p><strong><?= e(product_name()) ?> <?= e(APP_VERSION) ?></strong><?= ($verBi = app_build_info()) ? ' · Build ' . e($verBi) : ' · Build nicht hinterlegt (app/build.txt wird vom Deployment geschrieben)' ?></p>
</div>
<div class="card">
    <h2>Änderungsverlauf</h2>
    <?php foreach (app_changelog() as $rel): ?>
    <div class="mon-incident">
        <h3 class="mon-h3">Version <?= e($rel['version']) ?> · <?= e($rel['date']) ?> · <?= e($rel['title']) ?><?php if (isset($docsRevByVersion[$rel['version']])): ?> <span class="hint">· Dokumentation: <?= e($docsRevByVersion[$rel['version']]) ?></span><?php endif; ?></h3>
        <ul class="mon-updates">
            <?php foreach ($rel['entries'] as $entry): ?>
            <li><?= admin_changelog_badge($entry['type']) ?> <?= e($entry['text']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'versionen'): require_once __DIR__ . '/app/docs.php';
    $docsManifest = docs_manifest(); $docsArchive = docs_archive_list(); $docsReaders = docs_technical_readers();
    $docsVersionMismatch = is_array($docsManifest) && (string)($docsManifest['version'] ?? '') !== APP_VERSION;
    $docsLabels = ['technical' => 'streng vertraulich, nur technische Leser', 'admin' => 'intern, Plattformadministratoren', 'customer' => 'kundenbezogen, freigegeben für angemeldete Kunden'];
?>
<div class="card" id="dokumentation">
    <h2>Dokumentation</h2>
    <?php if (!is_array($docsManifest)): ?>
        <p class="hint">Noch nicht erzeugt. Die Dokumentation entsteht im GitHub-Workflow (Job test, <code>tools/build-docs.py</code>) und wird mit dem Release ausgeliefert. Erzeugungsstatus und Neustart: Workflow-Lauf in GitHub Actions prüfen beziehungsweise erneut starten (Re-run); die Anwendung erzeugt keine PDFs zur Laufzeit.</p>
    <?php else: ?>
        <?php if ($docsVersionMismatch): ?>
            <div class="flash flash-warn"><strong>Dokumentationsstand passt nicht zur laufenden Version.</strong> Manifest Version <?= e((string)$docsManifest['version']) ?>, Anwendung <?= e(APP_VERSION) ?>. Die Dokumentation gilt als veraltet, bis ein Deployment mit neuem Build erfolgt.</div>
        <?php endif; ?>
        <?php if (($docsManifest['status'] ?? '') !== 'complete'): ?>
            <div class="flash flash-warn"><strong>Erzeugung unvollständig:</strong> <?= e(implode(', ', (array)($docsManifest['missing'] ?? []))) ?>. Letzter vollständiger Stand: siehe Archiv unten.</div>
        <?php endif; ?>
        <p class="hint">Softwarestand <?= e((string)$docsManifest['version']) ?> · Commit <?= e((string)$docsManifest['commit']) ?> · erzeugt <?= e(str_replace('T', ' ', substr((string)$docsManifest['generated_at'], 0, 16))) ?> UTC · Erzeugung <?= ($docsManifest['status'] ?? '') === 'complete' ? 'vollständig' : 'unvollständig' ?>.
        Entwickler- und Unternehmensdokumentation sehen Plattformadministratoren; Mitarbeiter- und Supportrollen nicht.</p>
        <div class="doc-cards">
        <?php foreach ((array)$docsManifest['documents'] as $d): $allowed = docs_can_access($ctx, (string)$d['access']); ?>
            <div class="doc-card">
                <h3><?= e((string)$d['title']) ?></h3>
                <dl class="doc-meta">
                    <dt>Zielgruppe</dt><dd><?= e((string)$d['audience']) ?></dd>
                    <dt>Vertraulichkeit</dt><dd><?= e((string)$d['classification']) ?><br><span class="hint"><?= e($docsLabels[$d['access']] ?? (string)$d['access']) ?></span></dd>
                    <dt>Softwarestand</dt><dd>Version <?= e((string)$docsManifest['version']) ?>, Commit <?= e((string)$docsManifest['commit']) ?></dd>
                    <dt>Revision</dt><dd><?= e((string)$d['revision']) ?> vom <?= e((string)$d['revision_date']) ?><?= !empty($d['revision_summary']) ? '<br><span class="hint">' . e((string)$d['revision_summary']) . '</span>' : '' ?></dd>
                    <dt>Prüfdatum</dt><dd><?= e(substr((string)$docsManifest['generated_at'], 0, 10)) ?> (Erzeugung)<br><span class="hint">fachliche Prüfung: siehe Revisionsvermerk</span></dd>
                    <dt>Status</dt><dd><?= $docsVersionMismatch ? '<span class="badge badge-danger">veraltet</span>' : '<span class="badge badge-success">aktuell</span>' ?></dd>
                    <dt>Umfang</dt><dd><?= count((array)$d['chapters']) ?> Kapitel, PDF <?= monitor_bytes((int)($d['pdf_bytes'] ?? 0)) ?></dd>
                </dl>
                <?php if ($allowed): ?>
                    <div class="doc-actions">
                        <a class="btn" href="admin-doc.php/<?= e((string)$d['html']) ?>" target="_blank" rel="noopener">Lesen</a>
                        <a class="btn btn-secondary" href="admin-doc.php?f=<?= e(rawurlencode((string)$d['pdf'])) ?>">Gesamt-PDF</a>
                    </div>
                    <details><summary>Kapitel als PDF (<?= count((array)$d['chapters']) ?>)</summary><ol>
                    <?php foreach ((array)$d['chapters'] as $ch): ?><li><a href="admin-doc.php?f=<?= e(rawurlencode((string)$ch['pdf'])) ?>"><?= e((string)$ch['title']) ?></a></li><?php endforeach; ?>
                    </ol></details>
                <?php else: ?>
                    <p class="doc-locked">Keine Leseberechtigung (<?= e((string)$d['access']) ?>).</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <h3>Anlagen und Schaubilder</h3>
        <ul>
        <?php foreach ((array)$docsManifest['files'] as $df): if (!empty($df['doc']) || !str_ends_with((string)$df['name'], '.pdf')) { continue; } ?>
            <li><a href="admin-doc.php?f=<?= e(rawurlencode((string)$df['name'])) ?>"><?= e((string)($df['title'] ?: $df['name'])) ?></a> (<?= monitor_bytes((int)$df['bytes']) ?>)</li>
        <?php endforeach; ?>
            <li>Schaubilder (SVG und PNG) sind in den Dokumenten eingebettet; Quellen im Repository unter <code>docs/diagramme/</code>.</li>
        </ul>
        <p class="hint">Jeder Abruf wird im Protokoll erfasst. Kunden erhalten das Benutzerhandbuch in der Kundenanwendung unter „Handbuch“ (Hilfe-Center). Regeln, Erzeugung und Rechte: Entwicklerdokumentation, Kapitel Dokumentationssystem.</p>
    <?php endif; ?>
</div>
<div class="card" id="dokumentation-archiv">
    <h2>Historische Fassungen</h2>
    <?php if (!$docsArchive): ?>
        <p class="hint">Kein Archiv vorhanden. Das Deployment legt ab Version 4.32 jeden ausgelieferten Dokumentationsstand unter <code>shared/docs-archive/&lt;Version&gt;_&lt;Commit&gt;/</code> ab.</p>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Stand</th><th>Version</th><th>Commit</th><th>Erzeugt (UTC)</th><th>Dokumente (Revision)</th><th>Abruf</th></tr></thead><tbody>
        <?php foreach ($docsArchive as $ar): ?>
            <tr><td><?= e($ar['id']) ?></td><td><?= e($ar['version']) ?></td><td><?= e($ar['commit']) ?></td><td><?= e($ar['generated_at']) ?></td>
                <td><?= e(implode(', ', array_map(static fn($d) => (string)$d['code'] . ' ' . (string)$d['revision'], $ar['documents']))) ?></td>
                <td><?php foreach ($ar['documents'] as $d): if (!docs_can_access($ctx, (string)$d['access'])) { continue; } ?>
                    <a href="admin-doc.php?archiv=<?= e(rawurlencode($ar['id'])) ?>&amp;f=<?= e(rawurlencode((string)$d['pdf'])) ?>"><?= e((string)$d['code']) ?>.pdf</a>
                <?php endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="hint">Änderungsübersicht je Fassung: Revisionsvermerk im Manifest (Feld revision_summary) und Änderungsverlauf oben. Zurückgezogene Fassungen werden nicht gelöscht, sondern im Manifest gekennzeichnet.</p>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
