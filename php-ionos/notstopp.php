<?php
/**
 * Not-Stopp: Alle SEPA-Einzüge der Firma sofort anhalten oder wieder freigeben.
 * Nur Inhaber und Administratoren. Wirkt auf Sofort-Einzug, Sammel-Einzug und
 * die Einreichung fälliger terminierter Einzüge (Button und Cron). Terminierte
 * Einzüge bleiben bestehen und werden nach Aufhebung beim nächsten Lauf
 * eingereicht, sofern sie dann fällig sind.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/collections.php';

$ctx = require_login();
if (!can_manage_settings($ctx)) {
    forbidden_page($ctx, 'Den Not-Stopp dürfen nur Inhaber und Administratoren der Firma bedienen.');
}
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'pause') {
            collections_set_paused($tenantId, true, $ctx, (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Not-Stopp aktiviert. Es werden keine Lastschriften mehr eingereicht, bis Sie den Not-Stopp aufheben.');
        } elseif ($action === 'resume') {
            if (($_POST['confirm'] ?? '') !== '1') {
                throw new RuntimeException('Bitte bestätigen Sie die Freigabe der Einzüge.');
            }
            collections_set_paused($tenantId, false, $ctx, (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Not-Stopp aufgehoben. Einzüge sind wieder möglich; fällige terminierte Einzüge werden beim nächsten Lauf eingereicht.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('notstopp.php');
}

$org = _collection_org($tenantId);
$paused = (int)($org['collections_paused'] ?? 0) === 1;
$platformPaused = platform_collections_paused();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_cents), 0) AS total FROM payment_collections
     WHERE tenant_id = ? AND is_scheduled = 1 AND scheduled_submitted = 0 AND stripe_status = 'scheduled'"
);
$stmt->execute([$tenantId]);
$scheduled = $stmt->fetch();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_collections WHERE tenant_id = ? AND stripe_status = 'processing'");
$stmt->execute([$tenantId]);
$processing = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     WHERE a.tenant_id = ? AND a.action IN ('collections_paused', 'collections_resumed') ORDER BY a.id DESC LIMIT 10"
);
$stmt->execute([$tenantId]);
$history = $stmt->fetchAll();

layout_header('Not-Stopp', $ctx);
?>
<h1>Not-Stopp für SEPA-Einzüge</h1>
<p class="page-sub">Hält alle Einreichungen dieser Firma sofort an: Sofort-Einzug, Sammel-Einzug und fällige terminierte Einzüge (auch per Cron).</p>

<?php if ($platformPaused): ?>
<div class="flash flash-error"><strong>Plattformweiter Not-Stopp.</strong> Der Betreiber hat alle Einzüge vorübergehend angehalten. Diese Sperre kann nur der Betreiber aufheben.</div>
<?php endif; ?>

<div class="card">
    <h2>Aktueller Zustand</h2>
    <?php if ($paused): ?>
        <p><span class="badge badge-danger">Not-Stopp aktiv</span>
            seit <?= format_datetime($org['collections_paused_at'] ?? null) ?>. Es werden keine Lastschriften eingereicht.</p>
        <form method="post" onsubmit="return confirm('Einzüge wieder freigeben? Fällige terminierte Einzüge werden beim nächsten Lauf bei Stripe eingereicht.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resume">
            <label for="reason">Grund der Freigabe (optional)</label>
            <input type="text" id="reason" name="reason" maxlength="255" placeholder="z. B. Ursache geklärt">
            <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;">
                <input type="checkbox" name="confirm" value="1"> Ich habe geprüft, dass Einzüge wieder eingereicht werden dürfen.
            </label>
            <div class="form-actions"><button type="submit" class="btn">Not-Stopp aufheben</button></div>
        </form>
    <?php else: ?>
        <p><span class="badge badge-success">Einzüge freigegeben</span></p>
        <p class="hint">Derzeit terminiert: <?= (int)$scheduled['cnt'] ?> Einzug/Einzüge über <?= format_eur_cents((int)$scheduled['total']) ?>,
            in Bearbeitung bei Stripe: <?= $processing ?>. Bereits eingereichte Lastschriften kann der Not-Stopp nicht zurückholen.</p>
        <form method="post" onsubmit="return confirm('Not-Stopp aktivieren? Es werden sofort keine Lastschriften mehr eingereicht.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pause">
            <label for="reason">Grund (optional, wird protokolliert)</label>
            <input type="text" id="reason" name="reason" maxlength="255" placeholder="z. B. Verdacht auf fehlerhafte Beträge">
            <div class="form-actions"><button type="submit" class="btn btn-danger">Not-Stopp jetzt aktivieren</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Was der Not-Stopp bewirkt</h2>
    <ul class="check-list plain">
        <li>Kein Sofort-Einzug und kein Sammel-Einzug über die Rechnungsseite oder die Einzugsübersicht.</li>
        <li>Fällige terminierte Einzüge werden nicht eingereicht, weder per Button noch per Cron; sie bleiben terminiert und werden protokolliert übersprungen.</li>
        <li>Statusabgleich, Rechnungssynchronisation und Webhooks laufen weiter (nur Lesezugriff bzw. Rückmeldungen von Stripe).</li>
        <li>Bereits bei Stripe eingereichte Lastschriften werden nicht gestoppt; Rücklastschriften erkennt der Webhook.</li>
        <li>Aktivierung und Aufhebung werden mit Benutzer und Zeitpunkt im Protokoll festgehalten.</li>
    </ul>
</div>

<?php if ($history): ?>
<div class="card">
    <h2>Protokoll</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Zeitpunkt</th><th>Aktion</th><th>Benutzer</th><th>Grund</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): $d = $h['details_json'] ? (json_decode($h['details_json'], true) ?: []) : []; ?>
                <tr>
                    <td><?= format_datetime($h['created_at']) ?></td>
                    <td><?= e(audit_action_label($h['action'])) ?></td>
                    <td class="hint"><?= e($h['user_email'] ?: ($h['email'] ?: '-')) ?></td>
                    <td class="hint"><?= e((string)($d['reason'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<p class="hint"><a href="dashboard.php">Zurück zum Dashboard</a> · <a href="collections.php">Zu den Einzügen</a></p>
<?php layout_footer($ctx); ?>
