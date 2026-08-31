<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/sync.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/customer_settings.php';

// require_login() statt require_onboarded(): Diese Seite führt selbst den
// letzten Onboarding-Schritt (erste Synchronisation) aus und muss daher auch
// vor Abschluss des Onboardings erreichbar sein.
$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();

$syncCursorKey = 'sync_cursor_' . $tenantId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync' || $action === 'sync_continue') {
            // Läuft in kleinen Schritten (siehe app/sync.php), damit ein
            // einzelner Request nicht am Zeitlimit des Hostings scheitert.
            // Der API-Key wird bewusst bei jedem Schritt neu aus der
            // Datenbank gelesen statt in der Session zwischengespeichert.
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

            $cursor = $action === 'sync_continue' ? ($_SESSION[$syncCursorKey] ?? null) : null;
            $step = sync_invoices_step($tenantId, new LexofficeClient($apiKey), $cursor);

            if ($step['done']) {
                unset($_SESSION[$syncCursorKey]);
                $result = $step['result'];
                flash_set('success', sprintf(
                    'Synchronisation abgeschlossen: %d Rechnungen geprüft, %d neu, %d aktualisiert, %d abgeschlossen.',
                    $result['synced'], $result['new'], $result['updated'], $result['removed']
                ));
                redirect('invoices.php');
            }

            $_SESSION[$syncCursorKey] = $step['cursor'];
            redirect('invoices.php?syncing=1');

        } elseif ($action === 'sync_cancel') {
            unset($_SESSION[$syncCursorKey]);
            flash_set('info', 'Synchronisation abgebrochen.');

        } elseif ($action === 'collect') {
            $collectionId = submit_collection($tenantId, $_POST['invoice_id'] ?? '');
            flash_set('success', 'Lastschrift wurde bei Stripe eingereicht.');

        } elseif ($action === 'schedule') {
            $date = $_POST['scheduled_date'] ?? '';
            submit_collection($tenantId, $_POST['invoice_id'] ?? '', $date);
            flash_set('success', 'Lastschrift wurde für den ' . format_date($date) . ' terminiert.');

        } elseif ($action === 'sepa_disable' || $action === 'sepa_enable') {
            $updated = set_customer_sepa_debit($tenantId, $_POST['customer_id'] ?? '', $action === 'sepa_enable');
            flash_set('success', sprintf(
                'SEPA-Einzug für Kundennummer %s auf "%s" gesetzt. Gilt für alle Rechnungen dieses Kunden.',
                $updated['customer_number'],
                $action === 'sepa_enable' ? 'Ja' : 'Nein'
            ));
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    // Aktuellen Filter beibehalten, damit ein Klick auf einen Aktions-Button
    // nicht aus der gefilterten Ansicht herausspringt.
    $backStatus = $_POST['back_status'] ?? '';
    $backSepa = $_POST['back_sepa'] ?? '';
    $backParams = array_filter(['status' => $backStatus, 'sepa' => $backSepa]);
    redirect('invoices.php' . ($backParams ? '?' . http_build_query($backParams) : ''));
}

// --- Laufende Synchronisation: Fortschritt anzeigen und automatisch fortsetzen ---
if (!empty($_GET['syncing']) && isset($_SESSION[$syncCursorKey])) {
    $progress = $_SESSION[$syncCursorKey]['result'] ?? ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
    layout_header('Rechnungen', $ctx);
    ?>
    <h1>Rechnungen</h1>
    <p class="page-sub">Synchronisation mit Lexoffice läuft ...</p>
    <div class="card">
        <p>Bitte warten, die Rechnungen werden in kleinen Schritten übernommen,
            damit die Verbindung nicht durch ein Zeitlimit des Servers abbricht.</p>
        <p><strong><?= (int)$progress['synced'] ?></strong> Rechnungen geprüft,
            <strong><?= (int)$progress['new'] ?></strong> neu,
            <strong><?= (int)$progress['updated'] ?></strong> aktualisiert.</p>
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
    </div>
    <script>
        setTimeout(function () {
            document.getElementById('sync-continue-form').submit();
        }, 300);
    </script>
    <?php
    layout_footer();
    exit;
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
    "SELECT i.*, c.customer_number, c.sepa_debit_enabled, c.is_walk_in
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE $where
     ORDER BY i.due_date IS NULL, i.due_date ASC, i.voucher_number ASC
     LIMIT 500"
);
$stmt->execute([$tenantId]);
$invoices = $stmt->fetchAll();

// Zum Filter-Wechsel den jeweils anderen Filter beibehalten
function invoices_url(string $status, string $sepa): string
{
    return 'invoices.php?' . http_build_query(['status' => $status, 'sepa' => $sepa]);
}

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
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn">Mit Lexoffice synchronisieren</button>
        </form>
        <?php if ($filter === 'open'): ?>
            <a class="btn btn-secondary" href="<?= e(invoices_url('all', $sepaFilter)) ?>">Alle anzeigen</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= e(invoices_url('open', $sepaFilter)) ?>">Nur offene anzeigen</a>
        <?php endif; ?>
        <?php if (can_manage($ctx)): ?>
            <a class="btn btn-secondary" href="reconcile.php">Mit Lexoffice abgleichen</a>
        <?php endif; ?>
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
                    $collectable = in_array($inv['lexoffice_status'], ['open', 'overdue'], true)
                        && !in_array($inv['collection_status'], ['in_collection', 'scheduled'], true)
                        && $hasCustomer
                        && !$sepaDisabled;
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
                            <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                            <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                            <button type="submit" class="btn btn-sm">Einziehen</button>
                        </form>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="schedule">
                            <input type="hidden" name="invoice_id" value="<?= e($inv['id']) ?>">
                            <input type="hidden" name="back_status" value="<?= e($filter) ?>">
                            <input type="hidden" name="back_sepa" value="<?= e($sepaFilter) ?>">
                            <input type="date" name="scheduled_date" required
                                   min="<?= $suggest->format('Y-m-d') ?>"
                                   value="<?= $suggest->format('Y-m-d') ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Terminieren</button>
                        </form>
                        <?php elseif (!$hasCustomer): ?>
                            <span class="hint">Kein Kunde verknüpft</span>
                        <?php elseif ($sepaDisabled): ?>
                            <span class="hint">SEPA-Einzug für diesen Kunden deaktiviert</span>
                        <?php endif; ?>

                        <?php if ($canToggleSepa): ?>
                        <form method="post" class="inline-form" style="margin-top: 4px;"
                              onsubmit="return confirm('SEPA-Einzug für Kundennummer <?= e($inv['customer_number']) ?> wirklich <?= $sepaDisabled ? 'wieder aktivieren' : 'deaktivieren' ?>? Dies gilt für ALLE Rechnungen dieses Kunden.')">
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
<?php layout_footer(); ?>
