<?php
/**
 * SEPA Pflegen: schlanke Arbeitsseite für alle Teammitglieder. Zeigt immer
 * genau einen Kunden ohne aktive IBAN und noch nicht getroffener SEPA-
 * Entscheidung. Entweder IBAN hinterlegen (setzt SEPA-Einzug automatisch
 * auf Ja) oder direkt "Kein SEPA" wählen. Danach erscheint automatisch
 * der nächste Kunde in der Liste.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/customer_settings.php';
require_once __DIR__ . '/app/mandates.php';
require_once __DIR__ . '/app/mandate_files.php';

$ctx = require_subscription();
if (!(int)$ctx['onboarding_completed']) {
    redirect('onboarding.php');
}
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $customerId = $_POST['customer_id'] ?? '';

    try {
        if ($action === 'save_iban') {
            $result = set_customer_iban(
                $tenantId, $customerId, $ctx['user_id'],
                $_POST['iban'] ?? '', $_POST['account_holder_name'] ?? '', $_POST['bic'] ?? null, $ctx
            );
            $msg = 'IBAN hinterlegt, SEPA-Einzug ist damit aktiviert.';
            $msg .= $result['stripe_registered']
                ? ' Zahlungsmethode wurde direkt bei Stripe registriert.'
                : ' Stripe-Registrierung erfolgt automatisch beim ersten Einzug'
                    . ($result['stripe_reason'] ? ' (' . $result['stripe_reason'] . ')' : '') . '.';
            if ((int)$ctx['require_signed_mandate'] === 1) {
                $msg .= ' Bitte in den Kundendetails das unterschriebene Mandat erfassen.';
            }
            flash_set('success', $msg);
        } elseif ($action === 'upload_mandate_file') {
            flash_set('success', mandate_file_handle_upload($ctx, $customerId, $_POST, $_FILES));
            redirect('sepa-pflegen.php?customer=' . urlencode($customerId));
        } elseif ($action === 'disable_sepa') {
            set_customer_sepa_debit($tenantId, $customerId, false, $ctx);
            flash_set('success', 'SEPA-Einzug für diesen Kunden deaktiviert.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('sepa-pflegen.php');
}

$pinned = (string)($_GET['customer'] ?? '');
$stmt = $pdo->prepare(
    "SELECT c.*,
        (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id
            AND i.lexoffice_status IN ('open','overdue')) AS open_invoices
     FROM customers c
     WHERE c.tenant_id = ? AND c.is_walk_in = 0 AND c.sepa_debit_enabled = 1
       AND NOT EXISTS (
           SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
       )
     ORDER BY (c.id = ?) DESC, c.name ASC
     LIMIT 1"
);
$stmt->execute([$tenantId, $pinned]);
$customer = $stmt->fetch();
$customerMandates = $customer ? mandates_for_customer($tenantId, $customer['id']) : [];
$activeMandate = null;
foreach ($customerMandates as $m) { if ((int)$m['is_active'] === 1) { $activeMandate = $m; break; } }
$fileCount = $customer ? mandate_file_count_for_customer($tenantId, $customer['id']) : 0;

// Kunden mit aktivem Mandat, aber ohne hochgeladenes Dokument (Nachweis fehlt)
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.customer_number, m.mandate_reference, m.signed_date
     FROM sepa_mandates m JOIN customers c ON c.id = m.customer_id
     WHERE m.tenant_id = ? AND m.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM mandate_files f WHERE f.customer_id = c.id AND f.tenant_id = m.tenant_id)
     ORDER BY c.name ASC LIMIT 25"
);
$stmt->execute([$tenantId]);
$withoutFile = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM customers c
     WHERE c.tenant_id = ? AND c.is_walk_in = 0 AND c.sepa_debit_enabled = 1
       AND NOT EXISTS (
           SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
       )"
);
$stmt->execute([$tenantId]);
$remaining = (int)$stmt->fetchColumn();

layout_header('SEPA Pflegen', $ctx);
?>
<h1>SEPA Pflegen</h1>
<p class="page-sub">Für jeden Kunden ohne hinterlegte IBAN: entweder Bankverbindung
    eintragen (aktiviert SEPA-Einzug automatisch) oder SEPA-Einzug ablehnen.</p>

<div class="card">
    <?php if (!$customer): ?>
        <p class="flash flash-success">Alles erledigt: Für alle Kunden liegt entweder eine
            IBAN vor oder SEPA-Einzug wurde abgelehnt.</p>
        <p class="hint"><a href="customers.php?only=no_mandate">Kunden mit Mandat ohne erfasste Unterschrift anzeigen</a></p>
    <?php else: ?>
        <p class="hint">Noch <?= $remaining ?> Kunde(n) offen.</p>
        <h2><a href="customer.php?id=<?= e($customer['id']) ?>"><?= e($customer['name']) ?></a></h2>
        <p>
            Kundennummer: <strong><?= e($customer['customer_number']) ?></strong><br>
            E-Mail: <?= e($customer['email'] ?? '-') ?><br>
            Offene Rechnungen: <?= (int)$customer['open_invoices'] ?>
        </p>

        <form method="post" style="margin-top: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_iban">
            <input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>">
            <label>IBAN</label>
            <input type="text" name="iban" required placeholder="DE89 3704 0044 0532 0130 00" autofocus>
            <label>Kontoinhaber</label>
            <input type="text" name="account_holder_name" required value="<?= e($customer['name']) ?>">
            <label>BIC (optional)</label>
            <input type="text" name="bic" maxlength="11">
            <div class="form-actions">
                <button type="submit" class="btn">IBAN speichern (SEPA: Ja)</button>
                <a class="btn btn-secondary" href="customer.php?id=<?= e($customer['id']) ?>">Kundendetails / Mandat</a>
            </div>
        </form>

        <form method="post" style="margin-top: 16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disable_sepa">
            <input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>">
            <button type="submit" class="btn btn-danger">Kein SEPA</button>
        </form>

        <hr style="margin: 24px 0; border: 0; border-top: 1px solid #e5e2dc;">
        <h3>Unterschriebenes Mandat hochladen</h3>
        <p class="hint">Liegt das unterschriebene SEPA-Mandat als Scan oder Foto vor (PDF, JPG, PNG, bis 10 MB), hier hochladen. Es wird mit dem Kunden
            <?= $activeMandate ? 'und dem Mandat ' . e($activeMandate['mandate_reference']) : '' ?> verknüpft.
            <?= $fileCount ? 'Bereits hochgeladen: ' . $fileCount . ' Dokument(e).' : '' ?></p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_mandate_file">
            <input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MANDATE_FILE_MAX_BYTES ?>">
            <label>Datei</label>
            <input type="file" name="mandate_file" required accept="application/pdf,image/jpeg,image/png">
            <?php if ($activeMandate && !$activeMandate['signed_date']): ?>
            <div class="inline-form" style="margin-top: 10px; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
                    <input type="checkbox" name="mark_signed" value="1" checked> Unterschrift gleich erfassen
                </label>
                <input type="date" name="signed_date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="max-width: 170px;">
                <input type="text" name="signed_place" placeholder="Ort der Unterschrift" style="max-width: 200px;">
            </div>
            <?php elseif (!$activeMandate): ?>
            <p class="hint">Für diesen Kunden gibt es noch kein Mandat. Die Datei wird dem Kunden zugeordnet; das Mandat erzeugen Sie in den Kundendetails.</p>
            <?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn btn-secondary">Dokument hochladen</button></div>
        </form>
    <?php endif; ?>
</div>

<?php if ($withoutFile): ?>
<div class="card">
    <h2>Mandate ohne hochgeladenes Dokument</h2>
    <p class="hint">Aktive Mandate, zu denen noch kein Scan oder Foto des unterschriebenen Mandats hinterlegt ist. Der Upload erfolgt in den Kundendetails.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Kunde</th><th>Kundennummer</th><th>Mandat</th><th>Unterschrift</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($withoutFile as $w): ?>
                <tr>
                    <td><?= e($w['name']) ?></td>
                    <td><?= e($w['customer_number']) ?></td>
                    <td><?= e($w['mandate_reference']) ?></td>
                    <td><?= $w['signed_date'] ? format_date($w['signed_date']) : '<span class="badge badge-warn">nicht erfasst</span>' ?></td>
                    <td><a class="btn btn-sm btn-secondary" href="customer.php?id=<?= e($w['id']) ?>#mandatsdokumente">Dokument hochladen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
