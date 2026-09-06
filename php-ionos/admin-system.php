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

$tabs = ['uebersicht' => 'Übersicht', 'dienste' => 'Dienste', 'aktivitaet' => 'Aktivität', 'verfuegbarkeit' => 'Verfügbarkeit', 'stoerungen' => 'Störungen und Wartung'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'uebersicht';
$windows = monitor_windows();
$w = isset($windows[$_GET['w'] ?? '']) ? (string)$_GET['w'] : '1h';
$d = in_array((int)($_GET['d'] ?? 30), [7, 30, 90], true) ? (int)$_GET['d'] : 30;
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
<?php layout_footer($ctx); ?>
