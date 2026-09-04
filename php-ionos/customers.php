<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_subscription();
if (!(int)$ctx['onboarding_completed']) {
    redirect('onboarding.php');
}
$tenantId = $ctx['org_id'];
$pdo = db();

$search = trim($_GET['q'] ?? '');
$only = $_GET['only'] ?? ''; // '' | no_iban | no_mandate | disabled
$where = 'c.tenant_id = ?';
$params = [$tenantId];
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.customer_number LIKE ? OR c.email LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1) AS active_ibans,
            (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id
                AND i.lexoffice_status IN ('open','overdue')) AS open_invoices,
            (SELECT m.status FROM sepa_mandates m WHERE m.customer_id = c.id AND m.is_active = 1
                ORDER BY m.created_at DESC LIMIT 1) AS mandate_status,
            (SELECT m.signed_date FROM sepa_mandates m WHERE m.customer_id = c.id AND m.is_active = 1
                ORDER BY m.created_at DESC LIMIT 1) AS mandate_signed
     FROM customers c
     WHERE $where
     ORDER BY c.name ASC
     LIMIT 1000"
);
$stmt->execute($params);
$customers = $stmt->fetchAll();

if ($only === 'no_iban') {
    $customers = array_filter($customers, fn($c) => !(int)$c['is_walk_in'] && (int)$c['sepa_debit_enabled'] && (int)$c['active_ibans'] === 0);
} elseif ($only === 'no_mandate') {
    $customers = array_filter($customers, fn($c) => !(int)$c['is_walk_in'] && (int)$c['sepa_debit_enabled'] && (int)$c['active_ibans'] > 0 && empty($c['mandate_signed']));
} elseif ($only === 'disabled') {
    $customers = array_filter($customers, fn($c) => !(int)$c['sepa_debit_enabled']);
}

layout_header('Kunden', $ctx);
?>
<h1>Kunden</h1>
<p class="page-sub">Kundenstamm aus Lexware Office mit Bankverbindungen und SEPA-Mandaten. Klicken Sie auf einen Kunden für Details und das Mandatsdokument.</p>

<div class="card">
    <form method="get" class="inline-form" style="margin-bottom: 12px; flex-wrap: wrap;">
        <input type="text" name="q" placeholder="Name, Kundennummer oder E-Mail suchen"
               value="<?= e($search) ?>" style="max-width: 320px;">
        <input type="hidden" name="only" value="<?= e($only) ?>">
        <button type="submit" class="btn btn-sm btn-secondary">Suchen</button>
    </form>
    <div class="form-actions" style="margin: 0 0 16px; flex-wrap: wrap;">
        <a class="btn btn-sm <?= $only === '' ? '' : 'btn-secondary' ?>" href="customers.php?q=<?= urlencode($search) ?>">Alle</a>
        <a class="btn btn-sm <?= $only === 'no_iban' ? '' : 'btn-secondary' ?>" href="customers.php?only=no_iban&q=<?= urlencode($search) ?>">Ohne IBAN</a>
        <a class="btn btn-sm <?= $only === 'no_mandate' ? '' : 'btn-secondary' ?>" href="customers.php?only=no_mandate&q=<?= urlencode($search) ?>">Mandat ohne Unterschrift</a>
        <a class="btn btn-sm <?= $only === 'disabled' ? '' : 'btn-secondary' ?>" href="customers.php?only=disabled&q=<?= urlencode($search) ?>">SEPA: Nein</a>
    </div>

    <?php if (!$customers): ?>
        <p class="hint">Keine Kunden gefunden. Kunden werden bei der Rechnungs-Synchronisation
            automatisch aus Lexware Office übernommen.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Kundennr.</th><th>Name</th><th>E-Mail</th>
                    <th>Offene Rechnungen</th><th>IBAN</th><th>Mandat</th><th>SEPA-Einzug</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?= e($c['customer_number']) ?>
                        <?php if ((int)$c['is_walk_in']): ?><span class="badge badge-neutral">Laufkunde</span><?php endif; ?>
                    </td>
                    <td><a href="customer.php?id=<?= e($c['id']) ?>"><?= e($c['name']) ?></a></td>
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
                        <?php elseif (!$c['mandate_status']): ?>
                            <span class="badge badge-neutral">Kein Mandat</span>
                        <?php elseif ($c['mandate_signed']): ?>
                            <span class="badge badge-success">Unterschrieben</span>
                        <?php else: ?>
                            <span class="badge badge-warn">Ohne Unterschrift</span>
                        <?php endif; ?>
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
                    <td><a class="btn btn-sm btn-secondary" href="customer.php?id=<?= e($c['id']) ?>">Details</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
