<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/sync_state.php';
require_once __DIR__ . '/app/collections.php';

$ctx = require_login();
if (!(int)$ctx['onboarding_completed']) {
    redirect('onboarding.php');
}
$tenantId = $ctx['org_id'];
$pdo = db();

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

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM customers c WHERE c.tenant_id = ? AND c.is_walk_in = 0 AND c.sepa_debit_enabled = 1
       AND NOT EXISTS (SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1)"
);
$stmt->execute([$tenantId]);
$missingIban = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sepa_mandates m WHERE m.tenant_id = ? AND m.is_active = 1 AND m.customer_iban_id IS NOT NULL AND m.signed_date IS NULL"
);
$stmt->execute([$tenantId]);
$unsignedMandates = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT pc.*, i.voucher_number, i.contact_name, u.email AS created_by_email
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     LEFT JOIN users u ON u.id = pc.created_by_user_id
     WHERE pc.tenant_id = ?
     ORDER BY pc.created_at DESC
     LIMIT 8'
);
$stmt->execute([$tenantId]);
$recent = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT lexoffice_last_sync, lexoffice_connected, stripe_connected FROM integrations WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$integration = $stmt->fetch() ?: ['lexoffice_last_sync' => null, 'lexoffice_connected' => 0, 'stripe_connected' => 0];
$syncState = sync_state_get($tenantId);
$syncRunning = sync_state_is_running($syncState);
$pauseReason = collections_pause_reason($tenantId);
$openAttempts = collection_attempts_open($tenantId);

layout_header('Dashboard', $ctx);
?>
<h1>Dashboard</h1>
<p class="page-sub"><?= e($ctx['org_name']) ?> · Lexware Office: <?= (int)$integration['lexoffice_connected'] ? 'verbunden' : 'nicht verbunden' ?>
    · Stripe: <?= (int)$integration['stripe_connected'] ? 'verbunden' : 'nicht verbunden' ?>
    · letzte Synchronisation: <?= format_datetime($integration['lexoffice_last_sync']) ?>
    <?php if ($syncRunning): ?> · <a href="invoices.php?syncing=1">Synchronisation läuft</a><?php endif; ?></p>

<?php if ($pauseReason): ?>
<div class="flash flash-error"><strong>Not-Stopp aktiv.</strong> <?= e($pauseReason) ?>
    <?php if (can_manage_settings($ctx)): ?><a href="notstopp.php">Not-Stopp verwalten</a><?php endif; ?></div>
<?php elseif (can_manage_settings($ctx)): ?>
<p class="hint">Einzüge sind freigegeben. Im Zweifel: <a href="notstopp.php">Not-Stopp</a> hält alle Einreichungen dieser Firma sofort an.</p>
<?php endif; ?>
<?php if ($openAttempts): ?>
<div class="flash flash-warn"><strong><?= count($openAttempts) ?> Einzugsversuch(e) mit unklarem Ergebnis.</strong> Die betroffenen Rechnungen werden nicht erneut eingereicht, bis der Versuch geklärt ist.
    <a href="collections.php">Zu den Einzügen (Unklare Versuche prüfen)</a></div>
<?php endif; ?>

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
        <div class="stat-label">Fehlgeschlagene Einzüge / Rücklastschriften</div>
    </div>
</div>

<?php if ($missingIban > 0 || $unsignedMandates > 0): ?>
<div class="card">
    <h2>Zu erledigen</h2>
    <ul class="check-list plain">
        <?php if ($missingIban > 0): ?>
            <li><a href="sepa-pflegen.php"><?= $missingIban ?> Kunde(n) mit SEPA-Einzug, aber ohne IBAN</a></li>
        <?php endif; ?>
        <?php if ($unsignedMandates > 0): ?>
            <li><a href="customers.php?only=no_mandate"><?= $unsignedMandates ?> Mandat(e) ohne erfasste Unterschrift</a></li>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

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
                    <th>Status</th><th>Eingereicht</th><th>Ausgelöst von</th>
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
                    <td class="hint"><?= e($c['created_by_email'] ?: 'System/Cron') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
