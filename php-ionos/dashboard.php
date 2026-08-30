<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();
if (!(int)$ctx['onboarding_completed']) {
    redirect('onboarding.php');
}
$tenantId = $ctx['org_id'];
$pdo = db();

// Kennzahlen
$stats = [];
$stmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN lexoffice_status IN ('open','overdue') THEN 1 ELSE 0 END) AS open_invoices,
        SUM(CASE WHEN lexoffice_status IN ('open','overdue') THEN total_gross_amount ELSE 0 END) AS open_amount,
        SUM(CASE WHEN collection_status = 'in_collection' THEN 1 ELSE 0 END) AS in_collection,
        SUM(CASE WHEN collection_status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled
     FROM invoices WHERE tenant_id = ?"
);
$stmt->execute([$tenantId]);
$stats = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN stripe_status = 'succeeded' THEN amount_cents ELSE 0 END) AS collected_cents,
        SUM(CASE WHEN stripe_status IN ('failed','disputed') THEN 1 ELSE 0 END) AS failed_count
     FROM payment_collections WHERE tenant_id = ?"
);
$stmt->execute([$tenantId]);
$collStats = $stmt->fetch();

// Letzte Einzüge
$stmt = $pdo->prepare(
    'SELECT pc.*, i.voucher_number, i.contact_name
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     WHERE pc.tenant_id = ?
     ORDER BY pc.created_at DESC
     LIMIT 8'
);
$stmt->execute([$tenantId]);
$recent = $stmt->fetchAll();

layout_header('Dashboard', $ctx);
?>
<h1>Dashboard</h1>
<p class="page-sub"><?= e($ctx['org_name']) ?></p>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['open_invoices'] ?? 0) ?></div>
        <div class="stat-label">Offene Rechnungen
            (<?= format_eur((string)($stats['open_amount'] ?? 0)) ?>)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['in_collection'] ?? 0) ?></div>
        <div class="stat-label">Im Einzug</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)($stats['scheduled'] ?? 0) ?></div>
        <div class="stat-label">Terminierte Einzüge</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= format_eur_cents((int)($collStats['collected_cents'] ?? 0)) ?></div>
        <div class="stat-label">Erfolgreich eingezogen (gesamt)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int)($collStats['failed_count'] ?? 0) ?></div>
        <div class="stat-label">Fehlgeschlagene Einzüge</div>
    </div>
</div>

<div class="card">
    <h2>Letzte Einzüge</h2>
    <?php if (!$recent): ?>
        <p class="hint">Noch keine Einzüge vorhanden.
            <a href="invoices.php">Zu den offenen Rechnungen</a></p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Rechnung</th><th>Kunde</th><th class="num">Betrag</th>
                    <th>Status</th><th>Eingereicht</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $c): ?>
                <tr>
                    <td><?= e($c['voucher_number']) ?></td>
                    <td><?= e($c['contact_name']) ?></td>
                    <td class="num"><?= format_eur_cents((int)$c['amount_cents']) ?></td>
                    <td><?= status_badge((string)$c['stripe_status'], $c['scheduled_date']) ?></td>
                    <td><?= format_datetime($c['submitted_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer(); ?>
