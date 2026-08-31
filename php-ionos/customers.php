<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/iban.php';
require_once __DIR__ . '/app/customer_settings.php';

$ctx = require_onboarded();
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $customerId = $_POST['customer_id'] ?? '';

    try {
        // Kunde muss zum Mandanten gehören
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$customerId, $tenantId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Kunde nicht gefunden.');
        }

        if ($action === 'add_iban') {
            $result = set_customer_iban(
                $tenantId, $customerId, $ctx['user_id'],
                $_POST['iban'] ?? '', $_POST['account_holder_name'] ?? '', $_POST['bic'] ?? null
            );
            $msg = 'IBAN für ' . $customer['name'] . ' hinterlegt.';
            $msg .= $result['stripe_registered']
                ? ' Zahlungsmethode wurde direkt bei Stripe registriert.'
                : ' Stripe-Registrierung erfolgt automatisch beim ersten Einzug'
                    . ($result['stripe_reason'] ? ' (' . $result['stripe_reason'] . ')' : '') . '.';
            flash_set('success', $msg);

        } elseif ($action === 'set_sepa_debit') {
            $enabled = ($_POST['sepa_debit_enabled'] ?? '1') === '1';
            $updated = set_customer_sepa_debit($tenantId, $customerId, $enabled);

            flash_set('success', sprintf(
                'SEPA-Einzug für Kundennummer %s auf "%s" gesetzt. Gilt automatisch für alle '
                . 'aktuellen und künftigen Rechnungen dieses Kunden.',
                $updated['customer_number'],
                $enabled ? 'Ja' : 'Nein'
            ));

        } elseif ($action === 'deactivate_iban') {
            $ibanId = $_POST['iban_id'] ?? '';
            $stmt = $pdo->prepare(
                'SELECT * FROM customer_ibans WHERE id = ? AND tenant_id = ? AND customer_id = ?'
            );
            $stmt->execute([$ibanId, $tenantId, $customerId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('IBAN nicht gefunden.');
            }
            $pdo->prepare('UPDATE customer_ibans SET is_active = 0 WHERE id = ?')->execute([$ibanId]);
            $pdo->prepare(
                'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, old_iban, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([uuid4(), $tenantId, $ibanId, 'deactivated', $row['iban'], $ctx['user_id']]);
            flash_set('success', 'IBAN deaktiviert.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('customers.php' . ($customerId ? '?open=' . urlencode($customerId) : ''));
}

$search = trim($_GET['q'] ?? '');
$where = 'c.tenant_id = ?';
$params = [$tenantId];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.customer_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1) AS active_ibans,
            (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id
                AND i.lexoffice_status IN ('open','overdue')) AS open_invoices
     FROM customers c
     WHERE $where
     ORDER BY c.name ASC
     LIMIT 500"
);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$openId = $_GET['open'] ?? null;
$openIbans = [];
if ($openId) {
    $stmt = $pdo->prepare(
        'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? ORDER BY is_active DESC, created_at DESC'
    );
    $stmt->execute([$openId, $tenantId]);
    $openIbans = $stmt->fetchAll();
}

layout_header('Kunden', $ctx);
?>
<h1>Kunden</h1>
<p class="page-sub">Kundenstamm aus Lexoffice mit hinterlegten Bankverbindungen für den SEPA-Einzug</p>

<div class="card">
    <form method="get" class="inline-form" style="margin-bottom: 16px;">
        <input type="text" name="q" placeholder="Name oder Kundennummer suchen"
               value="<?= e($search) ?>" style="max-width: 320px;">
        <button type="submit" class="btn btn-sm btn-secondary">Suchen</button>
    </form>

    <?php if (!$customers): ?>
        <p class="hint">Keine Kunden gefunden. Kunden werden bei der Rechnungs-Synchronisation
            automatisch aus Lexoffice übernommen.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Kundennr.</th><th>Name</th><th>E-Mail</th>
                    <th>Offene Rechnungen</th><th>IBAN</th><th>SEPA-Einzug</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?= e($c['customer_number']) ?>
                        <?php if ((int)$c['is_walk_in']): ?><span class="badge badge-neutral">Laufkunde</span><?php endif; ?>
                    </td>
                    <td><?= e($c['name']) ?></td>
                    <td><?= e($c['email'] ?? '-') ?></td>
                    <td><?= (int)$c['open_invoices'] ?></td>
                    <td>
                        <?= (int)$c['active_ibans'] > 0
                            ? '<span class="badge badge-success">Hinterlegt</span>'
                            : '<span class="badge badge-danger">Fehlt</span>' ?>
                    </td>
                    <td>
                        <?php if ((int)$c['is_walk_in']): ?>
                            <span class="hint">-</span>
                        <?php else: ?>
                            <?= (int)$c['sepa_debit_enabled']
                                ? '<span class="badge badge-success">Ja</span>'
                                : '<span class="badge badge-danger">Nein</span>' ?>
                        <?php endif; ?>
                    </td>
                    <td><a href="customers.php?open=<?= e($c['id']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">Bankverbindung</a></td>
                </tr>
                <?php if ($openId === $c['id']): ?>
                <tr>
                    <td colspan="7" style="background: #fafbfc;">
                        <h2>SEPA-Einzug: <?= e($c['name']) ?></h2>
                        <?php if ((int)$c['is_walk_in']): ?>
                            <p class="hint">Laufkunde (Sammel-Kundennummer <?= e($c['customer_number']) ?>):
                                SEPA-Einzug kann hier nicht pro Person ein-/ausgeschaltet werden,
                                da die Nummer von mehreren Personen geteilt wird.</p>
                        <?php else: ?>
                        <form method="post" class="inline-form" style="margin-bottom: 20px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_sepa_debit">
                            <input type="hidden" name="customer_id" value="<?= e($c['id']) ?>">
                            <select name="sepa_debit_enabled" style="max-width: 160px;">
                                <option value="1" <?= (int)$c['sepa_debit_enabled'] ? 'selected' : '' ?>>Ja</option>
                                <option value="0" <?= !(int)$c['sepa_debit_enabled'] ? 'selected' : '' ?>>Nein</option>
                            </select>
                            <button type="submit" class="btn btn-sm">Speichern</button>
                            <span class="hint">Gilt für alle Rechnungen mit Kundennummer <?= e($c['customer_number']) ?>.</span>
                        </form>
                        <?php endif; ?>

                        <h2>Bankverbindung: <?= e($c['name']) ?></h2>

                        <?php if ($openIbans): ?>
                        <table style="margin-bottom: 16px;">
                            <thead>
                                <tr><th>IBAN</th><th>Kontoinhaber</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($openIbans as $ib): ?>
                                <tr>
                                    <td><?= e(mask_iban($ib['iban'])) ?></td>
                                    <td><?= e($ib['account_holder_name']) ?></td>
                                    <td><?= (int)$ib['is_active']
                                        ? '<span class="badge badge-success">Aktiv</span>'
                                        : '<span class="badge badge-neutral">Inaktiv</span>' ?></td>
                                    <td>
                                        <?php if ((int)$ib['is_active']): ?>
                                        <form method="post" class="inline-form"
                                              onsubmit="return confirm('IBAN wirklich deaktivieren?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="deactivate_iban">
                                            <input type="hidden" name="customer_id" value="<?= e($c['id']) ?>">
                                            <input type="hidden" name="iban_id" value="<?= e($ib['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Deaktivieren</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_iban">
                            <input type="hidden" name="customer_id" value="<?= e($c['id']) ?>">
                            <label>Neue IBAN</label>
                            <input type="text" name="iban" required placeholder="DE89 3704 0044 0532 0130 00">
                            <label>Kontoinhaber</label>
                            <input type="text" name="account_holder_name" required
                                   value="<?= e($c['name']) ?>">
                            <label>BIC (optional)</label>
                            <input type="text" name="bic" maxlength="11">
                            <p class="hint">Hinweis: Für den SEPA-Einzug muss ein unterschriebenes
                                SEPA-Lastschriftmandat des Kunden vorliegen.</p>
                            <div class="form-actions">
                                <button type="submit" class="btn">IBAN speichern</button>
                                <a class="btn btn-secondary" href="customers.php">Schließen</a>
                            </div>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
