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

$ctx = require_onboarded();
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
                $_POST['iban'] ?? '', $_POST['account_holder_name'] ?? '', $_POST['bic'] ?? null
            );
            $msg = 'IBAN hinterlegt, SEPA-Einzug ist damit aktiviert.';
            $msg .= $result['stripe_registered']
                ? ' Zahlungsmethode wurde direkt bei Stripe registriert.'
                : ' Stripe-Registrierung erfolgt automatisch beim ersten Einzug'
                    . ($result['stripe_reason'] ? ' (' . $result['stripe_reason'] . ')' : '') . '.';
            flash_set('success', $msg);
        } elseif ($action === 'disable_sepa') {
            set_customer_sepa_debit($tenantId, $customerId, false);
            flash_set('success', 'SEPA-Einzug für diesen Kunden deaktiviert.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('sepa-pflegen.php');
}

// Warteschlange: Stammkunden ohne aktive IBAN, bei denen SEPA-Einzug noch
// nicht abgelehnt wurde (Laufkunden ausgeschlossen, siehe customer_settings.php).
$stmt = $pdo->prepare(
    "SELECT c.*,
        (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id
            AND i.lexoffice_status IN ('open','overdue')) AS open_invoices
     FROM customers c
     WHERE c.tenant_id = ? AND c.is_walk_in = 0 AND c.sepa_debit_enabled = 1
       AND NOT EXISTS (
           SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1
       )
     ORDER BY c.name ASC
     LIMIT 1"
);
$stmt->execute([$tenantId]);
$customer = $stmt->fetch();

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
    <?php else: ?>
        <p class="hint">Noch <?= $remaining ?> Kunde(n) offen.</p>
        <h2><?= e($customer['name']) ?></h2>
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
            </div>
        </form>

        <form method="post" style="margin-top: 16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disable_sepa">
            <input type="hidden" name="customer_id" value="<?= e($customer['id']) ?>">
            <button type="submit" class="btn btn-danger">Kein SEPA</button>
        </form>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
