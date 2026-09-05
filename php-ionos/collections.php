<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/collections.php';

$ctx = require_subscription();
if (!(int)$ctx['onboarding_completed']) {
    redirect('onboarding.php');
}
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $collectionId = $_POST['collection_id'] ?? '';

    try {
        if ($action === 'cancel') {
            cancel_scheduled_collection($tenantId, $collectionId, $ctx);
            flash_set('success', 'Terminierter Einzug wurde storniert. Die Rechnung ist wieder offen.');
        } elseif ($action === 'reschedule') {
            $newDate = $_POST['new_date'] ?? '';
            reschedule_collection($tenantId, $collectionId, $newDate, $ctx);
            flash_set('success', 'Einzug wurde auf den ' . format_date($newDate) . ' umterminiert.');

        } elseif ($action === 'process_due') {
            $result = process_scheduled_collections($tenantId, $ctx);
            flash_set(($result['skipped_paused'] > 0 || $result['unknown'] > 0) ? 'info' : 'success', sprintf(
                'Fällige terminierte Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen, %d zurückgestellt, %d mit unbekanntem Ergebnis (Klärung erforderlich), %d wegen Not-Stopp übersprungen.',
                $result['submitted'], $result['failed'], $result['deferred'], $result['unknown'], $result['skipped_paused']
            ));

        } elseif ($action === 'resolve_attempts') {
            $result = collection_attempts_resolve($tenantId, $ctx);
            flash_set('success', sprintf(
                'Unklare Versuche geprüft: %d geprüft, %d Einzug/Einzüge bei Stripe gefunden und nachgetragen, %d ohne Einzug freigegeben, %d noch offen (Prüfung später wiederholen).',
                $result['checked'], $result['recovered'], $result['cleared'], $result['pending']
            ));

        } elseif ($action === 'sync_status') {
            $result = sync_collection_statuses($tenantId, $ctx);
            flash_set('success', sprintf(
                'Statusabgleich: %d geprüft, %d eingezogen, %d fehlgeschlagen, %d unverändert.',
                $result['checked'], $result['succeeded'], $result['failed'], $result['unchanged']
            ));

        } elseif ($action === 'submit_all_ready') {
            $result = submit_all_ready_collections($tenantId, $ctx);
            flash_set($result['failed'] > 0 ? 'info' : 'success', sprintf(
                'Sammel-Einzug: %d von %d Rechnungen eingereicht (%s), %d fehlgeschlagen.%s',
                $result['submitted'], $result['candidates'], format_eur_cents($result['amount_cents']), $result['failed'],
                $result['errors'] ? ' Gründe: ' . implode(' | ', $result['errors']) : ''
            ));
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('collections.php');
}

$filter = $_GET['status'] ?? '';
$where = 'pc.tenant_id = ?';
$params = [$tenantId];
$allowedFilters = ['scheduled', 'processing', 'succeeded', 'refunded', 'failed', 'disputed', 'cancelled'];
if (in_array($filter, $allowedFilters, true)) {
    $where .= ' AND pc.stripe_status = ?';
    $params[] = $filter;
}

$stmt = $pdo->prepare(
    "SELECT pc.*, i.voucher_number, i.contact_name, i.customer_id, m.mandate_reference, m.stripe_mandate_reference,
            u.email AS created_by_email, u.display_name AS created_by_name
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     JOIN sepa_mandates m ON m.id = pc.mandate_id
     LEFT JOIN users u ON u.id = pc.created_by_user_id
     WHERE $where
     ORDER BY pc.created_at DESC
     LIMIT 500"
);
$stmt->execute($params);
$collections = $stmt->fetchAll();

$preNotify = (int)$ctx['send_pre_notification'] === 1;
$minLead = $preNotify ? max(1, (int)$ctx['pre_notification_days']) : 1;
$suggest = (new DateTimeImmutable('today'))->modify('+' . $minLead . ' days');
while ((int)$suggest->format('N') >= 6) {
    $suggest = $suggest->modify('+1 day');
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payment_collections
     WHERE tenant_id = ? AND is_scheduled = 1 AND scheduled_submitted = 0
       AND stripe_status = 'scheduled' AND scheduled_date <= CURDATE()"
);
$stmt->execute([$tenantId]);
$dueCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payment_collections WHERE tenant_id = ? AND stripe_status = 'processing'"
);
$stmt->execute([$tenantId]);
$processingCount = (int)$stmt->fetchColumn();

$ready = count_ready_for_collection($tenantId);
$pauseReason = collections_pause_reason($tenantId);
$openAttempts = collection_attempts_open($tenantId);

layout_header('Einzüge', $ctx);
?>
<h1>SEPA-Einzüge</h1>
<p class="page-sub">Alle eingereichten, terminierten und abgeschlossenen Lastschriften mit auslösender Person</p>

<?php if ($pauseReason): ?>
<div class="flash flash-error"><?= e($pauseReason) ?> <?php if (can_manage_settings($ctx)): ?><a href="notstopp.php">Not-Stopp verwalten</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($openAttempts): ?>
<div class="card">
    <h2>Unklare Einzugsversuche</h2>
    <p class="hint">Bei diesen Versuchen hat Stripe nicht geantwortet (Zeitüberschreitung oder Netzwerkfehler) oder der Aufruf wurde unterbrochen.
        Ob eine Lastschrift entstanden ist, wird per Lesezugriff bei Stripe geprüft; bis dahin wird die Rechnung nicht erneut eingereicht.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Rechnung</th><th class="num">Betrag</th><th>Status</th><th>Zeitpunkt</th><th>Fehler</th></tr></thead>
            <tbody>
            <?php foreach ($openAttempts as $a): ?>
                <tr>
                    <td><?= e($a['voucher_number']) ?></td>
                    <td class="num"><?= format_eur_cents((int)$a['amount_cents']) ?></td>
                    <td><?= $a['status'] === 'unknown' ? '<span class="badge badge-warn">Unbekannt</span>' : ($a['status'] === 'succeeded' ? '<span class="badge badge-warn">Ohne Einzugsdatensatz</span>' : '<span class="badge badge-info">Offen</span>') ?></td>
                    <td><?= format_datetime($a['created_at']) ?></td>
                    <td class="hint"><?= e(mb_substr((string)$a['error_text'], 0, 120)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="post" style="margin-top: 12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resolve_attempts">
        <button type="submit" class="btn">Unklare Versuche prüfen</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_due">
            <button type="submit" class="btn"<?= ($dueCount === 0 || $pauseReason) ? ' disabled' : '' ?>>
                Fällige terminierte Einzüge jetzt einreichen<?= $dueCount > 0 ? " ($dueCount)" : '' ?>
            </button>
        </form>
        <a class="btn btn-secondary" href="export.php<?= $filter !== '' ? '?status=' . e($filter) : '' ?>">Journal als CSV exportieren</a>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_status">
            <button type="submit" class="btn btn-secondary"<?= $processingCount === 0 ? ' disabled' : '' ?>>
                Status mit Stripe abgleichen<?= $processingCount > 0 ? " ($processingCount)" : '' ?>
            </button>
        </form>
    </div>
    <?php if (!$preNotify): ?>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post"
              onsubmit="return confirm('Achtung: Löst echte SEPA-Lastschriften aus.\n\n<?= $ready['count'] ?> Rechnung(en) mit insgesamt <?= e(format_eur($ready['amount'])) ?> werden jetzt bei Stripe eingezogen.\n\nWirklich fortfahren?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_all_ready">
            <button type="submit" class="btn btn-danger"<?= ($ready['count'] === 0 || $pauseReason) ? ' disabled' : '' ?>>
                Alle bereiten Einzüge jetzt einreichen<?= $ready['count'] > 0 ? " ({$ready['count']}, " . format_eur($ready['amount']) . ')' : '' ?>
            </button>
        </form>
    </div>
    <?php else: ?>
        <p class="hint">Vorabankündigung per E-Mail ist aktiv: Einzüge werden über "Rechnungen" terminiert (frühester Termin <?= $suggest->format('d.m.Y') ?>) und hier am Fälligkeitstag eingereicht.</p>
    <?php endif; ?>
    <p class="hint">"Status mit Stripe abgleichen" prüft laufende Einzüge (nur Lesezugriff, kein Geld
        bewegt sich). Eine spätere Rücklastschrift oder Erstattung erkennt nur der Stripe-Webhook (siehe Einstellungen).
        Nach einer Erstattung wird die Rechnung zur Klärung markiert und nicht automatisch erneut eingezogen.</p>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <a class="btn <?= $filter === '' ? '' : 'btn-secondary' ?> btn-sm" href="collections.php">Alle</a>
        <?php foreach ($allowedFilters as $f): ?>
            <a class="btn <?= $filter === $f ? '' : 'btn-secondary' ?> btn-sm"
               href="collections.php?status=<?= e($f) ?>"><?= status_badge($f) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$collections): ?>
        <p class="hint">Keine Einzüge gefunden.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Rechnung</th><th>Kunde</th><th class="num">Betrag</th>
                    <th>Mandat</th><th>Status</th><th>Termin</th><th>Eingereicht</th><th>Ausgelöst von</th><th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($collections as $c):
                    $cancellable = (int)$c['is_scheduled'] && !(int)$c['scheduled_submitted']
                        && $c['stripe_status'] === 'scheduled';
                ?>
                <tr>
                    <td><?= e($c['voucher_number']) ?></td>
                    <td><?php if ($c['customer_id']): ?><a href="customer.php?id=<?= e($c['customer_id']) ?>"><?= e($c['contact_name']) ?></a><?php else: ?><?= e($c['contact_name']) ?><?php endif; ?></td>
                    <td class="num"><?= format_eur_cents((int)$c['amount_cents']) ?><?php if (!empty($c['note'])): ?><div class="hint"><?= e(mb_substr($c['note'], 0, 120)) ?></div><?php endif; ?>
                        <?php if ((int)($c['refunded_cents'] ?? 0) > 0): ?><div class="hint">Erstattet: <?= format_eur_cents((int)$c['refunded_cents']) ?> am <?= format_datetime($c['refunded_at'] ?? null) ?></div><?php endif; ?></td>
                    <td><?= e($c['mandate_reference']) ?><?php if (!empty($c['stripe_mandate_reference'])): ?><div class="hint">Stripe: <?= e($c['stripe_mandate_reference']) ?></div><?php endif; ?></td>
                    <td>
                        <?= status_badge((string)$c['stripe_status']) ?>
                        <?php if ($c['failure_reason']): ?>
                            <div class="hint"><?= e(mb_substr($c['failure_reason'], 0, 120)) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c['refund_note'])): ?>
                            <div class="hint"><?= e(mb_substr($c['refund_note'], 0, 120)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$c['is_scheduled'] ? format_date($c['scheduled_date']) : '-' ?></td>
                    <td><?= format_datetime($c['submitted_at']) ?></td>
                    <td class="hint"><?= e($c['created_by_name'] ?: ($c['created_by_email'] ?: 'System/Cron')) ?></td>
                    <td>
                        <?php if ($cancellable): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reschedule">
                            <input type="hidden" name="collection_id" value="<?= e($c['id']) ?>">
                            <input type="date" name="new_date" required
                                   min="<?= $suggest->format('Y-m-d') ?>"
                                   value="<?= e($c['scheduled_date']) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Umterminieren</button>
                        </form>
                        <form method="post" class="inline-form"
                              onsubmit="return confirm('Terminierten Einzug wirklich stornieren?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="collection_id" value="<?= e($c['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Stornieren</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
