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
            flash_set('success', 'Einzug wurde storniert, es wurde nichts bei Stripe eingereicht. Die Rechnung ist wieder offen und kann korrigiert oder erneut eingezogen werden.');
        } elseif ($action === 'reschedule') {
            $newDate = $_POST['new_date'] ?? '';
            reschedule_collection($tenantId, $collectionId, $newDate, $ctx);
            flash_set('success', 'Einzug wurde auf den ' . format_date($newDate) . ' umterminiert.');

        } elseif ($action === 'process_due' || $action === 'process_due_now') {
            $force = $action === 'process_due_now';
            if ($force) {
                if (!can_manage_settings($ctx)) {
                    throw new RuntimeException('Die Einreichung außerhalb des Einreichfensters dürfen nur Inhaber und Administratoren auslösen.');
                }
                support_guard();
                require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
                audit_log($tenantId, $ctx, 'collections_due_forced', 'organization', $tenantId);
            }
            $result = process_scheduled_collections($tenantId, $ctx, ['ignore_window' => $force]);
            if ($result['outside_window'] > 0) {
                flash_set('info', sprintf(
                    '%d fällige(r) Einzug/Einzüge warten auf das Einreichfenster (%s bis %s Uhr) und werden dann automatisch eingereicht. Nichts wurde jetzt eingereicht.',
                    $result['outside_window'], collections_rules_config()['window_start'], collections_rules_config()['window_end']
                ));
            } else {
                flash_set(($result['skipped_paused'] > 0 || $result['unknown'] > 0) ? 'info' : 'success', sprintf(
                    'Fällige Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen, %d zurückgestellt, %d mit unbekanntem Ergebnis (Klärung erforderlich), %d wegen Not-Stopp übersprungen%s.',
                    $result['submitted'], $result['failed'], $result['deferred'], $result['unknown'], $result['skipped_paused'],
                    $result['overdue_skipped'] > 0 ? sprintf(', %d überfällig und nicht automatisch eingereicht (bitte neu terminieren oder stornieren)', $result['overdue_skipped']) : ''
                ));
            }

        } elseif ($action === 'resolve_attempts') {
            support_guard();
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
                'Sammel-Einzug: %d von %d Rechnungen %s (%s), %d fehlgeschlagen.%s%s',
                $result['submitted'], $result['candidates'], collections_grace_active() ? 'vorgemerkt' : 'eingereicht',
                format_eur_cents($result['amount_cents']), $result['failed'],
                collections_grace_active() && $result['submitted'] > 0 ? ' Einreichung bei Stripe ab ' . collections_earliest_submit()->format('d.m.Y H:i') . ' Uhr, bis dahin stornierbar.' : '',
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
$rules = collections_rules_config();
$overdueCutoff = collections_now()->modify('-' . $rules['overdue_days'] . ' days')->format('Y-m-d');
if (in_array($filter, $allowedFilters, true)) {
    $where .= ' AND pc.stripe_status = ?';
    $params[] = $filter;
} elseif ($filter === 'overdue') {
    $where .= " AND pc.is_scheduled = 1 AND pc.scheduled_submitted = 0 AND pc.stripe_status = 'scheduled' AND pc.scheduled_date < ?";
    $params[] = $overdueCutoff;
} else {
    $filter = '';
}

$stmt = $pdo->prepare(
    "SELECT pc.*, i.voucher_number, i.contact_name, i.customer_id,
            COALESCE(m.mandate_reference, pc.imported_mandate_reference) AS mandate_reference, m.stripe_mandate_reference,
            u.email AS created_by_email, u.display_name AS created_by_name
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     LEFT JOIN sepa_mandates m ON m.id = pc.mandate_id
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

$pending = collections_pending_overview($tenantId);
$dueCount = $pending['due'];
$windowOpen = collections_window_open();
$nextWindow = collections_window_next_open();

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
<?php if ($pending['overdue'] > 0): ?>
<div class="flash flash-warn"><strong><?= $pending['overdue'] ?> Einzug/Einzüge überfällig.</strong> Der Termin liegt mehr als <?= (int)$rules['overdue_days'] ?> Tage zurück
    (zum Beispiel nach einem Not-Stopp). Diese Einzüge werden nicht automatisch nachgeholt. Bitte je Einzug neu terminieren oder stornieren.
    <a href="collections.php?status=overdue">Überfällige anzeigen</a></div>
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
            <button type="submit" class="btn"<?= ($dueCount === 0 || $pauseReason || !$windowOpen) ? ' disabled' : '' ?>
                    title="<?= $windowOpen ? 'Fällige Einzüge jetzt bei Stripe einreichen' : 'Einreichung nur im Einreichfenster' ?>">
                Fällige Einzüge jetzt einreichen<?= $dueCount > 0 ? " ($dueCount)" : '' ?>
            </button>
        </form>
        <?php if (!$windowOpen && $dueCount > 0 && !$pauseReason): ?>
            <span class="hint" style="align-self: center;">Einreichfenster geschlossen, nächste automatische Einreichung ab <?= e($nextWindow->format('d.m.Y H:i')) ?> Uhr.</span>
            <?php if (can_manage_settings($ctx) && empty($ctx['support_mode'])): ?>
            <details class="inline-details">
                <summary class="btn btn-secondary btn-sm">Ausnahmsweise jetzt einreichen</summary>
                <form method="post" class="inline-form" style="margin-top: 8px;" onsubmit="return confirm('Fällige Einzüge jetzt außerhalb des Einreichfensters bei Stripe einreichen? Das ist nicht mehr rückgängig zu machen.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="process_due_now">
                    <input type="text" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" style="max-width: 160px;">
                    <button type="submit" class="btn btn-danger btn-sm">Jetzt einreichen (<?= $dueCount ?>)</button>
                </form>
                <p class="hint">Nur für Ausnahmen, zum Beispiel wenn der Cron ausgefallen ist. Die Karenzzeit der Einzüge muss abgelaufen sein.</p>
            </details>
            <?php endif; ?>
        <?php endif; ?>
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
    <p class="hint"><strong>Karenzzeit:</strong> <?= e(collections_rules_text()) ?>
        Vorgemerkt: <?= $pending['queued'] ?>, terminiert: <?= $pending['total'] - $pending['queued'] ?>, davon fällig: <?= $pending['due'] ?>, überfällig: <?= $pending['overdue'] ?>.</p>
    <p class="hint">"Status mit Stripe abgleichen" prüft laufende Einzüge (nur Lesezugriff, kein Geld
        bewegt sich). Eine spätere Rücklastschrift oder Erstattung erkennt nur der Stripe-Webhook (siehe Einstellungen).
        Nach einer Erstattung wird die Rechnung zur Klärung markiert und nicht automatisch erneut eingezogen.</p>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <a class="btn <?= $filter === '' ? '' : 'btn-secondary' ?> btn-sm" href="collections.php">Alle</a>
        <?php foreach ($allowedFilters as $f): ?>
            <a class="btn <?= $filter === $f ? '' : 'btn-secondary' ?> btn-sm"
               href="collections.php?status=<?= e($f) ?>"><?= status_badge($f) ?></a>
        <?php endforeach; ?>
        <a class="btn <?= $filter === 'overdue' ? '' : 'btn-secondary' ?> btn-sm" href="collections.php?status=overdue"><?= status_badge('overdue') ?></a>
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
                    $isOverdue = $cancellable && collection_is_overdue($c);
                    $isQueued = $cancellable && (int)($c['queued_immediate'] ?? 0) === 1;
                    $earliest = $c['submit_not_before'] ?? null;
                ?>
                <tr>
                    <td><?= e($c['voucher_number']) ?></td>
                    <td><?php if ($c['customer_id']): ?><a href="customer.php?id=<?= e($c['customer_id']) ?>"><?= e($c['contact_name']) ?></a><?php else: ?><?= e($c['contact_name']) ?><?php endif; ?></td>
                    <td class="num"><?= format_eur_cents((int)$c['amount_cents']) ?><?php if (!empty($c['note'])): ?><div class="hint"><?= e(mb_substr($c['note'], 0, 120)) ?></div><?php endif; ?>
                        <?php if ((int)($c['refunded_cents'] ?? 0) > 0): ?><div class="hint">Erstattet: <?= format_eur_cents((int)$c['refunded_cents']) ?> am <?= format_datetime($c['refunded_at'] ?? null) ?></div><?php endif; ?></td>
                    <td><?= e((string)$c['mandate_reference']) ?><?php if (!empty($c['stripe_mandate_reference'])): ?><div class="hint">Stripe: <?= e($c['stripe_mandate_reference']) ?></div><?php endif; ?>
                        <?php if (($c['source'] ?? 'app') === 'import'): ?><div><span class="badge badge-neutral" title="Aus Stripe übernommen, frühere Installation">Import</span></div><?php endif; ?></td>
                    <td>
                        <?php if ($isOverdue): ?>
                            <?= status_badge('overdue') ?>
                            <div class="hint">Termin <?= format_date($c['scheduled_date']) ?> liegt zu lange zurück, bitte neu terminieren oder stornieren.</div>
                        <?php elseif ($isQueued): ?>
                            <?= status_badge('queued') ?>
                            <div class="hint">Einreichung bei Stripe ab <?= $earliest ? e(date('d.m.Y H:i', strtotime((string)$earliest))) . ' Uhr' : format_date($c['scheduled_date']) ?>, bis dahin stornierbar.</div>
                        <?php else: ?>
                            <?= status_badge((string)$c['stripe_status']) ?>
                        <?php endif; ?>
                        <?php if ($c['failure_reason']): ?>
                            <div class="hint"><?= e(mb_substr($c['failure_reason'], 0, 120)) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c['refund_note'])): ?>
                            <div class="hint"><?= e(mb_substr($c['refund_note'], 0, 120)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$c['is_scheduled'] ? ($isQueued && $earliest ? e(date('d.m.Y H:i', strtotime((string)$earliest))) : format_date($c['scheduled_date'])) : '-' ?></td>
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
                              onsubmit="return confirm('Einzug wirklich stornieren? Es wird nichts bei Stripe eingereicht, die Rechnung ist danach wieder offen.')">
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
