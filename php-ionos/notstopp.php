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
            $reason = (string)($_POST['reason'] ?? '');
            collections_set_paused($tenantId, true, $ctx, $reason);
            $msg = 'Not-Stopp aktiviert. Es werden keine Lastschriften mehr eingereicht, bis Sie den Not-Stopp aufheben.';
            if (!empty($_POST['cancel_pending'])) {
                $res = collections_cancel_all_pending($tenantId, $ctx, $reason);
                $msg .= sprintf(' %d vorgemerkte und terminierte Einzüge über %s wurden storniert, die Rechnungen sind wieder offen.', $res['cancelled'], format_eur_cents($res['amount_cents']));
            }
            flash_set('success', $msg);
        } elseif ($action === 'resume') {
            if (($_POST['confirm'] ?? '') !== '1') {
                throw new RuntimeException('Bitte bestätigen Sie die Freigabe der Einzüge.');
            }
            // Zweitbestätigung: aktueller 2FA-Code (Replay-Schutz, kein Recovery-Code)
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            collections_set_paused($tenantId, false, $ctx, (string)($_POST['reason'] ?? ''));
            $ov = collections_pending_overview($tenantId);
            flash_set('success', sprintf(
                'Not-Stopp aufgehoben. Einzüge sind wieder möglich. Fällig und im nächsten Einreichfenster automatisch: %d, noch nicht fällig: %d, überfällig und nicht automatisch: %d (bitte unter Einzüge neu terminieren oder stornieren).',
                $ov['due'], $ov['future'], $ov['overdue']
            ));
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('notstopp.php');
}

$org = _collection_org($tenantId);
$paused = (int)($org['collections_paused'] ?? 0) === 1;
$platformPaused = platform_collections_paused();

$pending = collections_pending_overview($tenantId);
$scheduled = ['cnt' => $pending['total'], 'total' => $pending['total_cents']];
$rules = collections_rules_config();
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
        <p class="hint">Was nach dem Aufheben passiert: <?= $pending['due'] ?> fällige(r) Einzug/Einzüge werden im nächsten Einreichfenster automatisch eingereicht,
            <?= $pending['future'] ?> liegen noch in der Zukunft und laufen normal weiter,
            <?= $pending['overdue'] ?> sind überfällig (Termin älter als <?= (int)$rules['overdue_days'] ?> Tage) und werden nicht automatisch nachgeholt.
            Überfällige und noch nicht eingereichte Einzüge können Sie unter <a href="collections.php">Einzüge</a> einzeln stornieren oder neu terminieren.</p>
        <form method="post" onsubmit="return confirm('Einzüge wieder freigeben? Fällige Einzüge werden im nächsten Einreichfenster bei Stripe eingereicht.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resume">
            <label for="reason">Grund der Freigabe (optional)</label>
            <input type="text" id="reason" name="reason" maxlength="255" placeholder="z. B. Ursache geklärt">
            <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;">
                <input type="checkbox" name="confirm" value="1"> Ich habe geprüft, dass Einzüge wieder eingereicht werden dürfen.
            </label>
            <label for="code" style="margin-top: 10px;">Aktueller 2FA-Code</label>
            <input type="text" id="code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" style="max-width: 160px;">
            <p class="hint">Zweitbestätigung: Das Aufheben des Not-Stopps erfordert den aktuellen Code aus Ihrer Authenticator-App.</p>
            <div class="form-actions"><button type="submit" class="btn">Not-Stopp aufheben</button></div>
        </form>
    <?php else: ?>
        <p><span class="badge badge-success">Einzüge freigegeben</span></p>
        <p class="hint">Noch nicht bei Stripe: <?= (int)$scheduled['cnt'] ?> Einzug/Einzüge über <?= format_eur_cents((int)$scheduled['total']) ?>
            (<?= $pending['queued'] ?> vorgemerkt, <?= $pending['total'] - $pending['queued'] ?> terminiert). In Bearbeitung bei Stripe: <?= $processing ?>.
            Bereits eingereichte Lastschriften kann der Not-Stopp nicht zurückholen.</p>
        <form method="post" onsubmit="return confirm('Not-Stopp aktivieren? Es werden sofort keine Lastschriften mehr eingereicht.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pause">
            <label for="reason">Grund (optional, wird protokolliert)</label>
            <input type="text" id="reason" name="reason" maxlength="255" placeholder="z. B. Verdacht auf fehlerhafte Beträge">
            <?php if ((int)$scheduled['cnt'] > 0): ?>
            <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 10px;">
                <input type="checkbox" name="cancel_pending" value="1"> Zusätzlich alle <?= (int)$scheduled['cnt'] ?> vorgemerkten und terminierten Einzüge über <?= format_eur_cents((int)$scheduled['total']) ?> stornieren (Rechnungen werden wieder offen, nichts geht verloren, nur die Termine entfallen).
            </label>
            <?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn btn-danger">Not-Stopp jetzt aktivieren</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Was der Not-Stopp bewirkt</h2>
    <ul class="check-list plain">
        <li>Kein Sofort-Einzug und kein Sammel-Einzug über die Rechnungsseite oder die Einzugsübersicht.</li>
        <li>Vorgemerkte und fällige terminierte Einzüge werden nicht eingereicht, weder per Button noch per Cron; sie bleiben bestehen und werden protokolliert übersprungen. Beim Aktivieren können Sie sie wahlweise gesammelt stornieren.</li>
        <li>Nach dem Aufheben werden nur Einzüge automatisch eingereicht, deren Termin nicht länger als <?= (int)$rules['overdue_days'] ?> Tage zurückliegt. Ältere gelten als überfällig und müssen neu terminiert oder storniert werden, damit keine Lastschrift mit veralteter Ankündigung läuft.</li>
        <li>Statusabgleich, Rechnungssynchronisation und Webhooks laufen weiter (nur Lesezugriff bzw. Rückmeldungen von Stripe).</li>
        <li>Bereits bei Stripe eingereichte Lastschriften werden nicht gestoppt; Rücklastschriften erkennt der Webhook.</li>
        <li>Aktivierung und Aufhebung werden mit Benutzer und Zeitpunkt im Protokoll festgehalten. Die Aufhebung erfordert zusätzlich den aktuellen 2FA-Code.</li>
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
