<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$integration = $stmt->fetch() ?: ['lexoffice_connected' => 0, 'stripe_connected' => 0, 'lexoffice_last_sync' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'complete' && can_manage($ctx)) {
        $pdo->prepare('UPDATE organizations SET onboarding_completed = 1, onboarding_step = 5 WHERE id = ?')
            ->execute([$tenantId]);
        flash_set('success', 'Einrichtung abgeschlossen. Willkommen im SEPA-Portal.');
        redirect('dashboard.php');
    }
}

$steps = [
    [
        'title' => 'Organisation angelegt',
        'desc'  => 'Konto und Organisation "' . $ctx['org_name'] . '" wurden erstellt.',
        'done'  => true,
        'link'  => null,
    ],
    [
        'title' => 'Lexoffice verbinden',
        'desc'  => 'API-Key aus Lexoffice hinterlegen, damit offene Rechnungen abgerufen werden können.',
        'done'  => (bool)(int)$integration['lexoffice_connected'],
        'link'  => 'settings.php',
    ],
    [
        'title' => 'Stripe verbinden',
        'desc'  => 'Stripe Secret Key und Webhook-Secret hinterlegen für den SEPA-Einzug.',
        'done'  => (bool)(int)$integration['stripe_connected'],
        'link'  => 'settings.php',
    ],
    [
        'title' => 'Erste Synchronisation',
        'desc'  => 'Offene Rechnungen aus Lexoffice in das Portal übernehmen.',
        'done'  => !empty($integration['lexoffice_last_sync']),
        'link'  => 'invoices.php',
    ],
];

$allDone = array_reduce($steps, fn($carry, $s) => $carry && $s['done'], true);

layout_header('Einrichtung', $ctx);
?>
<h1>Willkommen bei <?= e($ctx['org_name']) ?></h1>
<p class="page-sub">Das SEPA-Portal verbindet Lexoffice mit Stripe für den automatisierten
Lastschrifteinzug offener Rechnungen. Bitte die folgenden Schritte abschließen.</p>

<div class="card">
    <ul class="steps">
        <?php foreach ($steps as $i => $step): ?>
        <li>
            <span class="step-status <?= $step['done'] ? 'step-done' : '' ?>">
                <?= $step['done'] ? '&#10003;' : $i + 1 ?>
            </span>
            <div>
                <strong><?= e($step['title']) ?></strong>
                <?php if ($step['link'] && !$step['done']): ?>
                    &nbsp;<a href="<?= e($step['link']) ?>">Jetzt erledigen</a>
                <?php endif; ?>
                <div class="hint"><?= e($step['desc']) ?></div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($allDone && can_manage($ctx)): ?>
    <form method="post" class="form-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="complete">
        <button type="submit" class="btn">Einrichtung abschließen</button>
    </form>
    <?php elseif ($allDone): ?>
    <p class="hint">Alle Schritte sind erledigt. Ein Administrator kann die Einrichtung abschließen.</p>
    <?php endif; ?>
</div>

<?php if ((int)$ctx['onboarding_completed']): ?>
<p><a class="btn btn-secondary" href="dashboard.php">Zum Dashboard</a></p>
<?php endif; ?>
<?php layout_footer(); ?>
