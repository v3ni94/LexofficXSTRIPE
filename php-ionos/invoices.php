<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/sync_state.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/customer_settings.php';

// require_subscription() statt require_onboarded(): Diese Seite führt selbst den
// letzten Onboarding-Schritt (erste Synchronisation) aus.
$ctx = require_subscription();
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync') {
            // Lauf serverseitig starten; Browser und Cron setzen ihn schrittweise fort.
            sync_lex_client($tenantId); // prüft Verbindung und Schlüssel
            sync_state_start($tenantId, $ctx);
            redirect('invoices.php?syncing=1');

        } elseif ($action === 'sync_continue') {
            $step = sync_state_step($tenantId);
            if ($step['done'] && !$step['skipped']) {
                $r = $step['result'];
                flash_set('success', sprintf(
                    'Synchronisation abgeschlossen: %d Rechnungen geprüft, %d neu, %d aktualisiert, %d abgeschlossen.',
                    $r['synced'], $r['new'], $r['updated'], $r['removed']
                ));
                $pdo->prepare("UPDATE sync_state SET status = 'idle' WHERE tenant_id = ? AND status = 'done'")->execute([$tenantId]);
                redirect('invoices.php');
            }
            redirect('invoices.php?syncing=1');

        } elseif ($action === 'sync_cancel') {
            sync_state_cancel($tenantId, $ctx);
            flash_set('info', 'Synchronisation abgebrochen.');

        } elseif ($action === 'collect') {
            $opts = ['confirm_amount_cents' => ($_POST['confirm_amount_cents'] ?? '') !== '' ? (int)$_POST['confirm_amount_cents'] : null];
            $cid = submit_collection($tenantId, $_POST['invoice_id'] ?? '', null, $ctx, $opts);
            $s = $pdo->prepare('SELECT amount_cents, note, queued_immediate, submit_not_before FROM payment_collections WHERE id = ?');
            $s->execute([$cid]);
            $c = $s->fetch();
            if ((int)($c['queued_immediate'] ?? 0) === 1) {
                flash_set('success', 'Lastschrift über ' . format_eur_cents((int)$c['amount_cents']) . ' wurde vorgemerkt. Einreichung bei Stripe ab '
                    . date('d.m.Y H:i', strtotime((string)$c['submit_not_before'])) . ' Uhr; bis dahin können Sie den Einzug unter "Einzüge" stornieren.'
                    . ($c['note'] ? ' Vermerk: ' . $c['note'] : ''));
            } else {
                flash_set('success', 'Lastschrift über ' . format_eur_cents((int)$c['amount_cents']) . ' wurde bei Stripe eingereicht.'
                    . ($c['note'] ? ' Vermerk: ' . $c['note'] : ''));
            }

        } elseif ($action === 'schedule') {
            $date = $_POST['scheduled_date'] ?? '';
            $opts = ['confirm_amount_cents' => ($_POST['confirm_amount_cents'] ?? '') !== '' ? (int)$_POST['confirm_amount_cents'] : null];
            submit_collection($tenantId, $_POST['invoice_id'] ?? '', $date, $ctx, $opts);
            flash_set('success', 'Lastschrift wurde für den ' . format_date($date) . ' terminiert.');

        } elseif ($action === 'review_clear') {
            if (!can_manage_settings($ctx)) {
                throw new RuntimeException('Die Klärung dürfen nur Inhaber und Administratoren abschließen.');
            }
            $inv = invoice_review_clear($tenantId, (string)($_POST['invoice_id'] ?? ''), $ctx);
            flash_set('success', 'Klärung für Rechnung ' . $inv['voucher_number'] . ' abgeschlossen. Die Rechnung kann wieder eingezogen werden; ein Einzug erfolgt nur manuell.');

        } elseif ($action === 'sepa_disable' || $action === 'sepa_enable') {
            $updated = set_customer_sepa_debit($tenantId, $_POST['customer_id'] ?? '', $action === 'sepa_enable', $ctx);
            flash_set('success', sprintf(
                'SEPA-Einzug für Kundennummer %s auf "%s" gesetzt. Gilt für alle Rechnungen dieses Kunden.',
                $updated['customer_number'],
                $action === 'sepa_enable' ? 'Ja' : 'Nein'
            ));
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    $backStatus = $_POST['back_status'] ?? '';
    $backSepa = $_POST['back_sepa'] ?? '';
    $backParams = array_filter(['status' => $backStatus, 'sepa' => $backSepa]);
    redirect('invoices.php' . ($backParams ? '?' . http_build_query($backParams) : ''));
}

// --- Laufende Synchronisation: Fortschritt anzeigen und automatisch fortsetzen ---
$syncState = sync_state_get($tenantId);
if (!empty($_GET['syncing'])) {
    if ($syncState && $syncState['status'] === 'error') {
        flash_set('error', 'Synchronisation abgebrochen: ' . ($syncState['last_error'] ?? 'unbekannter Fehler'));
        $pdo->prepare("UPDATE sync_state SET status = 'idle' WHERE tenant_id = ?")->execute([$tenantId]);
        redirect('invoices.php');
    }
    if ($syncState && $syncState['status'] === 'done') {
        $r = $syncState['result'] ?? ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        flash_set('success', sprintf(
            'Synchronisation abgeschlossen: %d Rechnungen geprüft, %d neu, %d aktualisiert, %d abgeschlossen.',
            $r['synced'], $r['new'], $r['updated'], $r['removed']
        ));
        $pdo->prepare("UPDATE sync_state SET status = 'idle' WHERE tenant_id = ?")->execute([$tenantId]);
        redirect('invoices.php');
    }
    if (sync_state_is_running($syncState)) {
        $progress = $syncState['result'] ?? ($syncState['cursor']['result'] ?? ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0]);
        $phase = $syncState['cursor']['phase'] ?? 'listing';
        layout_header('Rechnungen', $ctx);
        ?>
        <h1>Rechnungen</h1>
        <p class="page-sub">Synchronisation mit Lexware Office läuft ...</p>
        <div class="card">
            <p>Die Rechnungen werden serverseitig in kleinen Schritten übernommen. Sie können diese Seite
                schließen: Der Lauf wird im Hintergrund fortgesetzt (mit eingerichtetem Cron auch ohne geöffneten
                Browser) und der aktuelle Stand ist beim nächsten Öffnen sichtbar.</p>
            <p><strong><?= (int)$progress['synced'] ?></strong> Rechnungen geprüft,
                <strong><?= (int)$progress['new'] ?></strong> neu,
                <strong><?= (int)$progress['updated'] ?></strong> aktualisiert
                <span class="hint">(Phase: <?= e(['listing' => 'Liste abrufen', 'processing' => 'Rechnungen übernehmen', 'recheck' => 'Abgeschlossene prüfen'][$phase] ?? $phase) ?>)</span></p>
            <form method="post" id="sync-continue-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_continue">
                <noscript><button type="submit" class="btn">Weiter</button></noscript>
            </form>
            <form method="post" class="inline-form" style="margin-top: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_cancel">
                <button type="submit" class="btn btn-secondary btn-sm">Abbrechen</button>
            </form>
            <a class="btn btn-ghost btn-sm" href="dashboard.php" style="margin-top: 12px;">Im Hintergrund weiterlaufen lassen</a>
        </div>
        <script>
            setTimeout(function () {
                document.getElementById('sync-continue-form').submit();
            }, 400);
        </script>
        <?php
        layout_footer($ctx);
        exit;
    }
}

// Filter
$filter = $_GET['status'] ?? 'open';
$sepaFilter = $_GET['sepa'] ?? 'active'; // active (Standard) | disabled | all
if (!in_array($sepaFilter, ['active', 'disabled', 'all'], true)) {
    $sepaFilter = 'active';
}

$where = 'i.tenant_id = ?';
if ($filter === 'open') {
    $where .= " AND i.lexoffice_status IN ('open','overdue')";
}
if ($sepaFilter === 'active') {
    $where .= ' AND (c.sepa_debit_enabled IS NULL OR c.sepa_debit_enabled = 1)';
} elseif ($sepaFilter === 'disabled') {
    $where .= ' AND c.sepa_debit_enabled = 0';
}

$stmt = $pdo->prepare(
    "SELECT i.*, c.customer_number, c.sepa_debit_enabled, c.is_walk_in,
            (SELECT COUNT(*) FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1) AS has_iban
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE $where
     ORDER BY i.due_date IS NULL, i.due_date ASC, i.voucher_number ASC
     LIMIT 500"
);
$stmt->execute([$tenantId]);
$invoices = $stmt->fetchAll();

function invoices_url(string $status, string $sepa): string
{
    return 'invoices.php?' . http_build_query(['status' => $status, 'sepa' => $sepa]);
}

// Terminierung: frühester Termin (Werktag, ggf. Vorabankündigungsfrist)
$preNotify = (int)$ctx['send_pre_notification'] === 1;
$minLead = $preNotify ? max(1, (int)$ctx['pre_notification_days']) : 1;
$suggest = (new DateTimeImmutable('today'))->modify('+' . $minLead . ' days');
while ((int)$suggest->format('N') >= 6) {
    $suggest = $suggest->modify('+1 day');
}

$quota = collections_quota_check($tenantId);
$pauseReason = collections_pause_reason($tenantId);
$stmt = $pdo->prepare('SELECT lexoffice_last_sync FROM integrations WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$lastSync = $stmt->fetchColumn();

layout_header('Rechnungen', $ctx);
?>
<h1>Rechnungen</h1>
<p class="page-sub">Offene und überfällige Rechnungen aus Lexware Office · letzte Synchronisation: <?= format_datetime($lastSync ?: null) ?>
    <?php if ($quota['limit'] !== null): ?> · Einzüge in dieser Periode: <?= (int)$quota['used'] ?> von <?= (int)$quota['limit'] ?><?php endif; ?></p>

<div class="card">
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn">Mit Lexware Office synchronisieren</button>
        </form>
        <?php if ($filter === 'open'): ?>
            <a class="btn btn-secondary" href="<?= e(invoices_url('all', $sepaFilter)) ?>">Alle anzeigen</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= e(invoices_url('open', $sepaFilter)) ?>">Nur offene anzeigen</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="reconcile.php">Mit Lexware Office abgleichen</a>
    </div>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <span class="hint" style="align-self: center;">SEPA-deaktivierte Kunden:</span>
        <a class="btn btn-sm <?= $sepaFilter === 'active' ? '' : 'btn-secondary' ?>"
           href="<?= e(invoices_url($filter, 'active')) ?>">Ausblenden</a>
        <a class="btn btn-sm <?= $sepaFilter === 'disabled' ? '' : 'btn-secondary' ?>"
           href="<?= e(invoices_url($filter, 'disabled')) ?>">Nur deaktivierte</a>
        <a class="btn btn-sm <?= $sepaFilter === 'all' ? '' : 'btn-secondary' ?>"
           href="<?= e(invoices_url($filter, 'all')) ?>">Alle anzeigen</a>
    </div>
    <?php if ($pauseReason): ?>
        <div class="flash flash-error"><?= e($pauseReason) ?> <?php if (can_manage_settings($ctx)): ?><a href="notstopp.php">Not-Stopp verwalten</a><?php endif; ?></div>
    <?php endif; ?>
    <p class="hint">Vor jeder Einreichung wird der offene Restbetrag der Rechnung bei Lexware Office abgerufen. Weicht er vom Rechnungsbetrag ab
        (Teilzahlung), wird nur nach ausdrücklicher Bestätigung der Restbetrag eingezogen; ist die Rechnung bezahlt, wird nichts eingereicht.
        Rechnungen mit Klärungsbedarf (z. B. nach einer Erstattung über Stripe) werden nicht eingezogen, bis ein Inhaber oder Administrator die Klärung abgeschlossen hat.</p>
    <?php if ($preNotify): ?>
        <p class="hint">Vorabankündigung per E-Mail ist aktiv (<?= (int)$ctx['pre_notification_days'] ?> Tage): Einzüge werden
            terminiert, frühester Termin <?= $suggest->format('d.m.Y') ?>. Die Ankündigung geht beim Terminieren an den Kunden.</p>
    <?php endif; ?>

    <?php if (!$invoices): ?>
        <p class="hint">Keine Rechnungen vorhanden. Bitte zunächst synchronisieren.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Nr.</th><th>Kunde</th><th class="num">Betrag</th><th>Fällig</th>
                    <th>Stichwort</th><th>Status</th><th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv):
                    $hasCustomer = (bool)$inv['customer_id'];
                    $isWalkIn = $hasCustomer && (int)($inv['is_walk_in'] ?? 0) === 1;
                    $sepaDisabled = $hasCustomer && (int)($inv['sepa_debit_enabled'] ?? 1) === 0;
                    $canToggleSepa = $hasCustomer && !$isWalkIn;
                    $needsReview = (int)($inv['requires_review'] ?? 0) === 1;
                    $collectable = in_array($inv['lexoffice_status'], ['open', 'overdue'], true)
                        && !in_array($inv['collection_status'], ['in_collection', 'scheduled'], true)
                        && $hasCustomer
                        && !$sepaDisabled
                        && !$needsReview;
                ?>
                <tr>
                    <td><?= e($inv['voucher_number']) ?></td>
                    <td>
                        <?php if ($hasCustomer): ?>
                            <a href="customer.php?id=<?= e($inv['customer_id']) ?>"><?= e($inv['contact_name']) ?></a>
                        <?php else: ?>
                            <?= e($inv['contact_name']) ?>
                        <?php endif; ?>
                        <?php if ($inv['customer_number']): ?>
                            <span class="hint">KD <?= e($inv['customer_number']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= format_eur($inv['total_gross_amount']) ?>
                        <?php if ($inv['open_amount'] !== null && $inv['open_amount_fetched_at']): ?>
                            <div class="hint" title="Offener Betrag laut Lexware Office (Payments-Endpunkt)">Rest laut Lexware: <?= format_eur($inv['open_amount']) ?><br>Stand <?= format_datetime($inv['open_amount_fetched_at']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= format_date($inv['due_date']) ?></td>
                    <td><?= e($inv['keyword'] ?? '-') ?></td>
                    <td><?= status_badge($inv['collection_status']) ?><?php if ($needsReview): ?> <span class="badge badge-warn">Klärung offen</span><?php endif; ?></td>
                    <td>
                        <?php if ($needsReview): ?>
                            <div class="hint"><strong>Klärungsbedarf:</strong> <?= e((string)($inv['review_reason'] ?: 'Grund nicht vermerkt')) ?> Kein Einzug bis zum Abschluss der Klärung.</div>
                            <?php if (can_manage_settings($ctx)): ?>
                            <form method="post" class="inline-form" style="margin-top: 4px;"
                                  onsubmit="return confirm(<?= e(json_encode('Klärung für Rechnung ' . $inv['voucher_number'] . ' abschließen? Die Rechnung wird danach wieder einziehbar (nur manuell).', JSON_UNESCAPED_UNICODE)) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="review_clear">
                                <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                                <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                                <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">Klärung abgeschlossen</button>
                            </form>
                            <?php else: ?>
                            <span class="hint">Abschluss der Klärung durch Inhaber oder Administrator.</span>
                            <?php endif; ?>
                        <?php elseif ($collectable && (int)$inv['has_iban'] === 0): ?>
                            <span class="hint">Keine IBAN hinterlegt: <a href="customer.php?id=<?= e($inv['customer_id']) ?>">Kundendetails</a></span>
                        <?php elseif ($collectable):
                            $totalCents = (int)round((float)$inv['total_gross_amount'] * 100);
                            $cachedOpen = invoice_open_amount_cached($inv);
                            $restCents = $cachedOpen !== null ? $cachedOpen - invoice_own_collections_cents($tenantId, $inv['id']) : null;
                            $partial = $restCents !== null && $restCents > 0 && $restCents < $totalCents;
                            $nothingOpen = $restCents !== null && $restCents <= 0;
                        ?>
                        <?php if ($nothingOpen): ?>
                            <span class="hint">Laut Lexware Office kein Restbetrag offen (Stand <?= format_datetime($inv['open_amount_fetched_at']) ?>). Bitte synchronisieren.</span>
                        <?php else: ?>
                        <?php if ($partial): ?>
                            <div class="hint">Teilzahlung erkannt: Es wird nur der Restbetrag von <strong><?= format_eur_cents($restCents) ?></strong> eingezogen.</div>
                        <?php endif; ?>
                        <?php if (!$preNotify): ?>
                        <form method="post" class="inline-form"
                              onsubmit="return confirm(<?= e(json_encode('Lastschrift für Rechnung ' . $inv['voucher_number'] . ($partial ? ' über den Restbetrag ' . format_eur_cents($restCents) : '') . (collections_grace_active() ? ' vormerken? Einreichung bei Stripe ab ' . collections_earliest_submit()->format('d.m.Y H:i') . ' Uhr, bis dahin stornierbar.' : ' jetzt einreichen?'), JSON_UNESCAPED_UNICODE)) ?>)">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="collect">
                            <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                            <?php if ($partial): ?><input type="hidden" name="confirm_amount_cents" value="<?= $restCents ?>"><?php endif; ?>
                            <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                            <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                            <button type="submit" class="btn btn-sm"<?= $pauseReason ? ' disabled title="Not-Stopp aktiv"' : '' ?>><?= $partial ? (collections_grace_active() ? 'Restbetrag vormerken' : 'Restbetrag einziehen') : (collections_grace_active() ? 'Einzug vormerken' : 'Einziehen') ?></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="schedule">
                            <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                            <?php if ($partial): ?><input type="hidden" name="confirm_amount_cents" value="<?= $restCents ?>"><?php endif; ?>
                            <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                            <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                            <input type="date" name="scheduled_date" required
                                   min="<?= $suggest->format('Y-m-d') ?>"
                                   value="<?= $suggest->format('Y-m-d') ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"<?= $pauseReason ? ' disabled title="Not-Stopp aktiv"' : '' ?>>Terminieren</button>
                        </form>
                        <?php endif; ?>
                        <?php elseif (!$needsReview && !in_array($inv['lexoffice_status'], ['open', 'overdue'], true)): ?>
                            <span class="hint">Nicht mehr einziehbar (Lexware Office: <?= e(lexoffice_status_label($inv['lexoffice_status'])) ?>)</span>
                        <?php elseif (!$hasCustomer): ?>
                            <span class="hint">Kein Kunde verknüpft</span>
                        <?php elseif ($sepaDisabled): ?>
                            <span class="hint">SEPA-Einzug für diesen Kunden deaktiviert</span>
                        <?php endif; ?>

                        <?php if ($canToggleSepa): ?>
                        <form method="post" class="inline-form" style="margin-top: 4px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $sepaDisabled ? 'sepa_enable' : 'sepa_disable' ?>">
                            <input type="hidden" name="customer_id" value="<?= e($inv['customer_id']) ?>">
                            <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                            <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                            <button type="submit" class="btn btn-sm <?= $sepaDisabled ? '' : 'btn-danger' ?>">
                                <?= $sepaDisabled ? 'SEPA: Ja' : 'SEPA: Nein' ?>
                            </button>
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
