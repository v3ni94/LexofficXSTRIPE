<?php
/**
 * Synchronisationshistorie einer Firma (Auftrag III): abgeschlossene und laufende Läufe der
 * Lexware-Office-Synchronisation, mandantengefiltert über $ctx['org_id']. Der laufende Zustand
 * bleibt in sync_state (siehe app/sync_state.php); diese Seite liest nur die dauerhafte Historie
 * aus sync_runs und zeigt zusätzlich den aktuellen Fortschritt, solange ein Lauf läuft.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/sync_state.php';
require_once __DIR__ . '/app/monitor_view.php'; // monitor_category_label() für die Fehlerkategorie

$ctx = require_onboarded();
$tenantId = (string)$ctx['org_id'];

/** Badge für den Status eines Synchronisationslaufs. */
function sync_run_badge(string $status): string
{
    $cls = ['running' => 'badge-info', 'success' => 'badge-success', 'partial' => 'badge-warn', 'failed' => 'badge-danger', 'cancelled' => 'badge-neutral'][$status] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . e(sync_run_status_label($status)) . '</span>';
}

/** Dauer in mm:ss, oder "-" ohne Wert. */
function sync_duration_label(?int $ms): string
{
    if ($ms === null || $ms < 0) {
        return '-';
    }
    $total = (int)round($ms / 1000);
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

$runId = isset($_GET['id']) ? (string)$_GET['id'] : '';
$run = $runId !== '' ? sync_run_load($tenantId, $runId) : null;
if ($runId !== '' && !$run) {
    flash_set('error', 'Dieser Synchronisationslauf wurde nicht gefunden.');
    redirect('synchronisationen.php');
}

$state = sync_state_get($tenantId);
$showLive = sync_state_is_running($state);

layout_header('Synchronisationen', $ctx);
?>
<h1>Synchronisationen</h1>
<p class="page-sub">Verlauf der Abgleiche mit Lexware Office für diese Firma.
    <a href="invoices.php">Zu den Rechnungen</a></p>

<?php if ($showLive): ?>
<div class="card">
    <h2>Aktueller Lauf</h2>
    <?= sync_progress_fragment($tenantId) ?>
</div>
<?php endif; ?>

<?php if ($run): ?>
<div class="card">
    <p><a href="synchronisationen.php">&larr; Zurück zur Übersicht</a></p>
    <h2>Lauf vom <?= e(format_datetime($run['started_at'])) ?></h2>
    <dl class="kv">
        <dt>Status</dt><dd><?= sync_run_badge((string)$run['status']) ?></dd>
        <dt>Auslöser</dt><dd><?= e(sync_trigger_label((string)$run['triggered_by'])) ?></dd>
        <dt>Benutzer</dt><dd><?= e((string)($run['user_name'] ?: ($run['user_email'] ?: 'Automatisch/System'))) ?></dd>
        <dt>Start</dt><dd><?= e(format_datetime($run['started_at'])) ?></dd>
        <dt>Ende</dt><dd><?= e(format_datetime($run['finished_at'])) ?></dd>
        <dt>Dauer</dt><dd><?= sync_duration_label($run['duration_ms'] !== null ? (int)$run['duration_ms'] : null) ?></dd>
        <dt>Geprüft</dt><dd><?= number_format((int)$run['checked'], 0, ',', '.') ?></dd>
        <dt>Neu</dt><dd><?= number_format((int)$run['created'], 0, ',', '.') ?></dd>
        <dt>Geändert</dt><dd><?= number_format((int)$run['updated'], 0, ',', '.') ?></dd>
        <dt>Abgeschlossen</dt><dd><?= number_format((int)$run['removed'], 0, ',', '.') ?></dd>
        <dt>Übersprungen (unverändert)</dt><dd><?= number_format((int)$run['skipped'], 0, ',', '.') ?></dd>
        <dt>Fehlerhaft</dt><dd><?= number_format((int)$run['errors'], 0, ',', '.') ?></dd>
        <dt>Wiederholungen</dt><dd><?= number_format((int)$run['retries'], 0, ',', '.') ?></dd>
        <dt>API-Aufrufe</dt><dd><?= number_format((int)$run['api_calls'], 0, ',', '.') ?></dd>
        <dt>API-Laufzeit</dt><dd><?= number_format(((int)$run['api_ms']) / 1000, 1, ',', '.') ?> s</dd>
        <dt>Drosselung</dt><dd><?= number_format(((int)$run['throttle_ms']) / 1000, 1, ',', '.') ?> s</dd>
        <dt>Schritte</dt><dd><?= (int)$run['steps'] ?></dd>
        <dt>Worker</dt><dd><?= e((string)($run['worker_id'] ?: '-')) ?></dd>
        <dt>Fehlerkategorie</dt><dd><?= $run['error_category'] ? monitor_category_label((string)$run['error_category']) : '-' ?></dd>
        <dt>Fehlertext</dt><dd><?= $run['error_text'] ? e((string)$run['error_text']) : '-' ?></dd>
    </dl>
</div>
<?php else: ?>
<div class="card">
    <?php $runs = sync_runs_list($tenantId, 100); ?>
    <?php if (!$runs): ?>
        <p class="hint">Noch keine Synchronisation für diese Firma aufgezeichnet.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Datum</th><th>Status</th><th>Dauer</th><th>Auslöser</th><th>Benutzer</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
            <tr>
                <td><a href="synchronisationen.php?id=<?= e($r['id']) ?>"><?= e(format_datetime($r['started_at'])) ?></a></td>
                <td><?= sync_run_badge((string)$r['status']) ?></td>
                <td><?= sync_duration_label($r['duration_ms'] !== null ? (int)$r['duration_ms'] : null) ?></td>
                <td><?= e(sync_trigger_label((string)$r['triggered_by'])) ?></td>
                <td><?= e((string)($r['user_name'] ?: ($r['user_email'] ?: '-'))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
