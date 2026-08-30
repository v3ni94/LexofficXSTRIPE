<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/sync.php';
require_once __DIR__ . '/app/collections.php';

$ctx = require_onboarded();
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync') {
            $stmt = $pdo->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
            $stmt->execute([$tenantId]);
            $integration = $stmt->fetch();
            if (!$integration || !(int)$integration['lexoffice_connected']) {
                throw new RuntimeException('Lexoffice ist nicht verbunden.');
            }
            $apiKey = decrypt_value($integration['lexoffice_api_key_encrypted']);
            if (!$apiKey) {
                throw new RuntimeException('Lexoffice API-Key fehlt.');
            }
            $result = sync_invoices($tenantId, new LexofficeClient($apiKey));
            flash_set('success', sprintf(
                'Synchronisation abgeschlossen: %d Rechnungen geprüft, %d neu, %d aktualisiert, %d abgeschlossen.',
                $result['synced'], $result['new'], $result['updated'], $result['removed']
            ));

        } elseif ($action === 'collect') {
            $collectionId = submit_collection($tenantId, $_POST['invoice_id'] ?? '');
            flash_set('success', 'Lastschrift wurde bei Stripe eingereicht.');

        } elseif ($action === 'schedule') {
            $date = $_POST['scheduled_date'] ?? '';
            submit_collection($tenantId, $_POST['invoice_id'] ?? '', $date);
            flash_set('success', 'Lastschrift wurde für den ' . format_date($date) . ' terminiert.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('invoices.php');
}

// Filter
$filter = $_GET['status'] ?? 'open';
$where = 'i.tenant_id = ?';
if ($filter === 'open') {
    $where .= " AND i.lexoffice_status IN ('open','overdue')";
}

$stmt = $pdo->prepare(
    "SELECT i.*, c.customer_number
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE $where
     ORDER BY i.due_date IS NULL, i.due_date ASC, i.voucher_number ASC
     LIMIT 500"
);
$stmt->execute([$tenantId]);
$invoices = $stmt->fetchAll();

// Für die Terminierung: nächster Werktag als Vorgabewert
$suggest = new DateTimeImmutable('tomorrow');
while ((int)$suggest->format('N') >= 6) {
    $suggest = $suggest->modify('+1 day');
}

layout_header('Rechnungen', $ctx);
?>
<h1>Rechnungen</h1>
<p class="page-sub">Offene und überfällige Rechnungen aus Lexoffice</p>

<div class="card">
    <div class="form-actions" style="margin: 0 0 16px;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn">Mit Lexoffice synchronisieren</button>
        </form>
        <?php if ($filter === 'open'): ?>
            <a class="btn btn-secondary" href="invoices.php?status=all">Alle anzeigen</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="invoices.php">Nur offene anzeigen</a>
        <?php endif; ?>
    </div>

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
                    $collectable = in_array($inv['lexoffice_status'], ['open', 'overdue'], true)
                        && !in_array($inv['collection_status'], ['in_collection', 'scheduled'], true)
                        && $inv['customer_id'];
                ?>
                <tr>
                    <td><?= e($inv['voucher_number']) ?></td>
                    <td><?= e($inv['contact_name']) ?>
                        <?php if ($inv['customer_number']): ?>
                            <span class="hint">KD <?= e($inv['customer_number']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= format_eur($inv['total_gross_amount']) ?></td>
                    <td><?= format_date($inv['due_date']) ?></td>
                    <td><?= e($inv['keyword'] ?? '-') ?></td>
                    <td><?= status_badge($inv['collection_status']) ?></td>
                    <td>
                        <?php if ($collectable): ?>
                        <form method="post" class="inline-form"
                              onsubmit="return confirm('Lastschrift für Rechnung <?= e($inv['voucher_number']) ?> jetzt einreichen?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="collect">
                            <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                            <button type="submit" class="btn btn-sm">Einziehen</button>
                        </form>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="schedule">
                            <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                            <input type="date" name="scheduled_date" required
                                   min="<?= $suggest->format('Y-m-d') ?>"
                                   value="<?= $suggest->format('Y-m-d') ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Terminieren</button>
                        </form>
                        <?php elseif (!$inv['customer_id']): ?>
                            <span class="hint">Kein Kunde verknüpft</span>
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
