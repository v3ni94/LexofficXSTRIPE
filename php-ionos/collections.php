<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/collections.php';

$ctx = require_onboarded();
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $collectionId = $_POST['collection_id'] ?? '';

    try {
        if ($action === 'cancel') {
            cancel_scheduled_collection($tenantId, $collectionId);
            flash_set('success', 'Terminierter Einzug wurde storniert. Die Rechnung ist wieder offen.');
        } elseif ($action === 'reschedule') {
            $newDate = $_POST['new_date'] ?? '';
            reschedule_collection($tenantId, $collectionId, $newDate);
            flash_set('success', 'Einzug wurde auf den ' . format_date($newDate) . ' umterminiert.');

        } elseif ($action === 'process_due') {
            // Ersetzt den Cronjob: fällige terminierte Einzüge manuell auslösen.
            $result = process_scheduled_collections($tenantId);
            flash_set('success', sprintf(
                'Fällige terminierte Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen.',
                $result['submitted'], $result['failed']
            ));

        } elseif ($action === 'sync_status') {
            $result = sync_collection_statuses($tenantId);
            flash_set('success', sprintf(
                'Statusabgleich: %d geprüft, %d eingezogen, %d fehlgeschlagen, %d unverändert.',
                $result['checked'], $result['succeeded'], $result['failed'], $result['unchanged']
            ));

        } elseif ($action === 'submit_all_ready') {
            $result = submit_all_ready_collections($tenantId);
            flash_set('success', sprintf(
                'Sammel-Einzug: %d von %d Rechnungen eingereicht, %d fehlgeschlagen.',
                $result['submitted'], $result['candidates'], $result['failed']
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
$allowedFilters = ['scheduled', 'processing', 'succeeded', 'failed', 'disputed', 'cancelled'];
if (in_array($filter, $allowedFilters, true)) {
    $where .= ' AND pc.stripe_status = ?';
    $params[] = $filter;
}

$stmt = $pdo->prepare(
    "SELECT pc.*, i.voucher_number, i.contact_name, m.mandate_reference
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     JOIN sepa_mandates m ON m.id = pc.mandate_id
     WHERE $where
     ORDER BY pc.created_at DESC
     LIMIT 500"
);
$stmt->execute($params);
$collections = $stmt->fetchAll();

$suggest = new DateTimeImmutable('tomorrow');
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

layout_header('Einzüge', $ctx);
?>
<h1>SEPA-Einzüge</h1>
<p class="page-sub">Alle eingereichten, terminierten und abgeschlossenen Lastschriften</p>

<div class="card">
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_due">
            <button type="submit" class="btn"<?= $dueCount === 0 ? ' disabled' : '' ?>>
                Fällige terminierte Einzüge jetzt einreichen<?= $dueCount > 0 ? " ($dueCount)" : '' ?>
            </button>
        </form>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_status">
            <button type="submit" class="btn btn-secondary"<?= $processingCount === 0 ? ' disabled' : '' ?>>
                Status mit Stripe abgleichen<?= $processingCount > 0 ? " ($processingCount)" : '' ?>
            </button>
        </form>
    </div>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post"
              onsubmit="return confirm('Achtung: Löst echte SEPA-Lastschriften aus.\n\n<?= $ready['count'] ?> Rechnung(en) mit insgesamt <?= e(format_eur($ready['amount'])) ?> werden jetzt bei Stripe eingezogen.\n\nWirklich fortfahren?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_all_ready">
            <button type="submit" class="btn btn-danger"<?= $ready['count'] === 0 ? ' disabled' : '' ?>>
                Alle bereiten Einzüge jetzt einreichen<?= $ready['count'] > 0 ? " ({$ready['count']}, " . format_eur($ready['amount']) . ')' : '' ?>
            </button>
        </form>
    </div>
    <p class="hint">"Status mit Stripe abgleichen" prüft laufende Einzüge (nur Lesezugriff, kein Geld
        bewegt sich) und aktualisiert Erfolg/Fehlschlag. Erkennt keine spätere Rücklastschrift (dafür
        muss der Stripe-Webhook eingerichtet sein, siehe Einstellungen). "Alle bereiten Einzüge jetzt
        einreichen" löst echte Lastschriften für alle offenen Rechnungen mit hinterlegter IBAN aus.</p>
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
                    <th>Mandat</th><th>Status</th><th>Termin</th><th>Eingereicht</th><th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($collections as $c):
                    $cancellable = (int)$c['is_scheduled'] && !(int)$c['scheduled_submitted']
                        && $c['stripe_status'] === 'scheduled';
                ?>
                <tr>
                    <td><?= e($c['voucher_number']) ?></td>
                    <td><?= e($c['contact_name']) ?></td>
                    <td class="num"><?= format_eur_cents((int)$c['amount_cents']) ?></td>
                    <td><?= e($c['mandate_reference']) ?></td>
                    <td>
                        <?= status_badge((string)$c['stripe_status']) ?>
                        <?php if ($c['failure_reason']): ?>
                            <div class="hint"><?= e(mb_substr($c['failure_reason'], 0, 120)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$c['is_scheduled'] ? format_date($c['scheduled_date']) : '-' ?></td>
                    <td><?= format_datetime($c['submitted_at']) ?></td>
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
<?php layout_footer(); ?>
