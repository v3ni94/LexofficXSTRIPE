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
         'verfuegbarkeit' => 'Verfügbarkeit', 'stoerungen' => 'Störungen und Wartung', 'versionen' => 'Versionen', 'dokumentation' => 'Dokumentation'];
$tabParam = is_string($_GET['tab'] ?? null) ? (string)$_GET['tab'] : '';
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
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            monitor_incident_publish($ctx, (string)($_POST['incident_id'] ?? ''), $action === 'incident_publish');
            if ($cfg['publish']) {
                status_publish(monitor_public_snapshot());
            }
            flash_set('success', $action === 'incident_publish' ? 'Meldung veröffentlicht.' : 'Meldung zurückgezogen.');
            $back = 'admin-system.php?tab=stoerungen';
        } elseif ($action === 'publish_now') {
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            $r = status_publish(monitor_public_snapshot());
            audit_log(null, $ctx, 'status_published_manual', 'monitor', null, $r);
            flash_set('success', 'Statusdaten übertragen: ' . ($r ? http_build_query($r, '', ', ') : 'kein Ziel konfiguriert'));
        } elseif ($action === 'test_mail') {
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            if ($cfg['test_mail_to'] === '') {
                throw new RuntimeException('Keine Testadresse konfiguriert (monitoring.test_mail_to).');
            }
            require_once __DIR__ . '/app/mailer.php';
            $tpl = mail_tpl_security('Testversand Systemmonitoring', ['Dies ist ein manueller Testversand aus dem Adminbereich System vom ' . date('d.m.Y H:i:s T') . '.']);
            $ok = mail_send($cfg['test_mail_to'], $tpl['subject'], $tpl['text'], $tpl['html']);
            audit_log(null, $ctx, 'monitor_test_mail', 'monitor', null, ['accepted' => $ok]);
            flash_set($ok ? 'success' : 'error', $ok ? 'Testnachricht an den Versandweg übergeben (Annahme, kein Zustellnachweis).' : 'Der Versandweg hat die Testnachricht nicht angenommen.');
        } elseif ($action === 'job_retry_now' || $action === 'job_cancel' || $action === 'job_close') {
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            if (!queue_available()) {
                throw new RuntimeException('Für die Warteschlange fehlt noch die Datenbankmigration 018.');
            }
            $jobId = (string)($_POST['job_id'] ?? '');
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
            $back = 'admin-system.php?tab=jobs';
        } elseif ($action === 'org_sync_pause' || $action === 'org_sync_resume') {
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
            $orgId = (string)($_POST['org_id'] ?? '');
            $pause = $action === 'org_sync_pause';
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
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''), true);
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

<nav class="admin-subnav" aria-label="Systembereiche">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="admin-system.php?tab=<?= e($k) ?>&amp;w=<?= e($w) ?>&amp;d=<?= $d ?>"<?= $k === $tab ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a><?= array_key_last($tabs) === $k ? '' : ' · ' ?>
    <?php endforeach; ?>
</nav>

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
    <h2>Nicht verfügbare Messwerte</h2>
    <table class="table-plain">
        <tbody>
        <tr><td>CPU-Auslastung des Servers</td><td>Vom Hosting nicht bereitgestellt (IONOS Webhosting ohne Root-Zugang, kein hostweiter Load-Wert als eigene Auslastung).</td></tr>
        <tr><td>Gesamt-RAM-Auslastung</td><td>Vom Hosting nicht bereitgestellt. memory_get_peak_usage() misst nur den eigenen PHP-Job.</td></tr>
        <tr><td>Belegte PHP-Worker / Prozesse</td><td>Vom Hosting nicht bereitgestellt. Eine erlaubte Höchstzahl wäre keine aktuelle Anzahl.</td></tr>
        <tr><td>Webspace- und Datenbankkontingent</td><td>Vom Hosting nicht bereitgestellt; gemessen werden nur eigene Größen (Dienste).</td></tr>
        <tr><td>Sicherungen / Wiederherstellungstest</td><td>Nicht überwacht (keine verifizierbare Quelle).</td></tr>
        <tr><td>Externe Erreichbarkeitsprüfung</td><td><?= $cfg['publish'] ? 'Statusveröffentlichung konfiguriert; externer Prüfer siehe docs/status-page.md.' : 'Nicht eingerichtet (siehe docs/status-page.md).' ?></td></tr>
        </tbody>
    </table>
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
        <label for="tm_code">2FA-Code</label> <input type="text" id="tm_code" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code">
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
<div class="card">
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

<div class="card">
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
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_retry_now"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
                        <button type="submit" class="btn btn-sm btn-secondary">Erneut versuchen</button></form>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_cancel"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
                        <button type="submit" class="btn btn-sm btn-secondary">Abbrechen</button></form>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="job_close"><input type="hidden" name="job_id" value="<?= e($j['id']) ?>">
                        <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
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
                            <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
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
                            <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="2FA">
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
                <label>2FA-Code <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code"></label>
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
        <label>2FA-Code <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code"></label>
        <button type="submit" class="btn btn-secondary">Snapshot jetzt übertragen</button></form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'versionen'): ?>
<div class="card">
    <h2>Anwendungsversion</h2>
    <p><strong><?= e(product_name()) ?> <?= e(APP_VERSION) ?></strong><?= ($verBi = app_build_info()) ? ' · Build ' . e($verBi) : ' · Build nicht hinterlegt (app/build.txt wird vom Deployment geschrieben)' ?></p>
</div>
<div class="card">
    <h2>Änderungsverlauf</h2>
    <?php foreach (app_changelog() as $rel): ?>
    <div class="mon-incident">
        <h3 class="mon-h3">Version <?= e($rel['version']) ?> · <?= e($rel['date']) ?> · <?= e($rel['title']) ?></h3>
        <ul class="mon-updates">
            <?php foreach ($rel['entries'] as $entry): ?>
            <li><?= admin_changelog_badge($entry['type']) ?> <?= e($entry['text']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'dokumentation'): ?>
<div class="card">
    <h2>Technische Dokumentation</h2>
    <?php
    $docsManifestPath = __DIR__ . '/app/docs-build/manifest.json';
    $docsManifest = is_file($docsManifestPath) ? json_decode((string)@file_get_contents($docsManifestPath), true) : null;
    ?>
    <?php if (!is_array($docsManifest)): ?>
        <p class="hint">Noch nicht erzeugt (tools/build-docs.py, wird beim Deployment ausgeführt).</p>
    <?php else: ?>
        <dl class="kv">
            <dt>Version</dt><dd><?= e((string)($docsManifest['version'] ?? '-')) ?></dd>
            <dt>Erzeugt</dt><dd><?= e((string)($docsManifest['generated_at'] ?? '-')) ?> (UTC)</dd>
            <dt>Commit</dt><dd><?= e((string)($docsManifest['commit'] ?? 'unbekannt')) ?></dd>
        </dl>
        <div class="table-wrap"><table>
            <thead><tr><th>Datei</th><th>Art</th><th>Größe</th><th>Download</th></tr></thead>
            <tbody>
            <?php if (empty($docsManifest['files'])): ?><tr><td colspan="4" class="hint">Keine Dateien im Manifest.</td></tr><?php endif; ?>
            <?php foreach ((array)($docsManifest['files'] ?? []) as $df): ?>
                <tr>
                    <td><?= e((string)($df['name'] ?? '-')) ?></td>
                    <td><?= e(strtoupper((string)($df['kind'] ?? '-'))) ?></td>
                    <td><?= isset($df['bytes']) ? monitor_bytes((int)$df['bytes']) : '-' ?></td>
                    <td><a href="admin-doc.php?f=<?= e(rawurlencode((string)($df['name'] ?? ''))) ?>">Öffnen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Markdown-Dokumente im Repository</h2>
    <p class="hint">Nur als Pfadangabe (im Repository unter docs/, nicht mit ausgeliefert und über den Webserver nicht erreichbar).</p>
    <?php
    $docsDir = dirname(APP_ROOT) . '/docs';
    $mdFiles = is_dir($docsDir) ? array_map('basename', glob($docsDir . '/*.md') ?: []) : [];
    sort($mdFiles);
    ?>
    <?php if (!$mdFiles): ?>
        <p class="hint">Verzeichnis docs/ liegt außerhalb des ausgelieferten Codes und ist von hier aus nicht einsehbar.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($mdFiles as $mdF): ?><li><code>docs/<?= e($mdF) ?></code></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
