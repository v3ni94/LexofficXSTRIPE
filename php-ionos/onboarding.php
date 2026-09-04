<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
$stmt->execute([$tenantId]);
$integration = $stmt->fetch() ?: ['lexoffice_connected' => 0, 'stripe_connected' => 0, 'lexoffice_last_sync' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'complete') {
        $pdo->prepare('UPDATE organizations SET onboarding_completed = 1, onboarding_step = 5 WHERE id = ?')
            ->execute([$tenantId]);
        audit_log($tenantId, $ctx, 'onboarding_completed', 'organization', $tenantId);
        funnel_event_for_org($tenantId, 'onboarding_completed', $ctx['user_id']);
        flash_set('success', 'Einrichtung abgeschlossen. Willkommen bei ' . product_name() . '.');
        redirect('dashboard.php');
    }
}

$needsSubscription = billing_enabled() && !subscription_allows_operation($ctx);
$hasCompanyData = !empty($ctx['creditor_identifier']) && !empty($ctx['street']) && !empty($ctx['city']);

$steps = [
    [
        'title' => 'Firmenaccount angelegt',
        'desc'  => 'Konto und Firma "' . $ctx['org_name'] . '" wurden erstellt, Zwei-Faktor-Authentifizierung ist aktiv.',
        'done'  => true,
        'link'  => null,
    ],
];
if (billing_enabled() && !(int)$ctx['billing_exempt']) {
    $steps[] = [
        'title' => 'Abonnement aktivieren',
        'desc'  => 'UNLIMITED START, 25 EUR je 4 Wochen. Nur der Inhaber kann das Abonnement abschließen.',
        'done'  => !$needsSubscription,
        'link'  => $ctx['role'] === 'owner' ? 'subscription.php' : null,
    ];
}
$steps[] = [
    'title' => 'Lexware Office verbinden',
    'desc'  => 'API-Key aus Lexware Office (Public API) hinterlegen, damit offene Rechnungen abgerufen werden können.',
    'done'  => (bool)(int)$integration['lexoffice_connected'],
    'link'  => can_manage_settings($ctx) ? 'settings.php' : null,
];
$steps[] = [
    'title' => 'Stripe verbinden',
    'desc'  => 'Eigenes Stripe-Konto anbinden (Secret Key und Webhook-Secret) für den SEPA-Einzug.',
    'done'  => (bool)(int)$integration['stripe_connected'],
    'link'  => can_manage_settings($ctx) ? 'settings.php' : null,
];
$steps[] = [
    'title' => 'Firmendaten für SEPA-Mandate',
    'desc'  => 'Anschrift und Gläubiger-Identifikationsnummer hinterlegen (Pflichtangaben auf dem Mandatsdokument). Kann später ergänzt werden.',
    'done'  => $hasCompanyData,
    'link'  => can_manage_settings($ctx) ? 'team.php' : null,
    'optional' => true,
];
$steps[] = [
    'title' => 'Erste Synchronisation',
    'desc'  => 'Offene Rechnungen und Kunden aus Lexware Office übernehmen.',
    'done'  => !empty($integration['lexoffice_last_sync']),
    'link'  => $needsSubscription ? null : 'invoices.php',
];

$allDone = array_reduce($steps, fn($carry, $s) => $carry && ($s['done'] || !empty($s['optional'])), true);

layout_header('Einrichtung', $ctx);
?>
<h1>Willkommen bei <?= e($ctx['org_name']) ?></h1>
<p class="page-sub"><?= e(product_name()) ?> verbindet Ihre Rechnungs- und Kundendaten aus Lexware Office mit Ihrem
    Stripe-Konto für den SEPA-Lastschrifteinzug. Bitte die folgenden Schritte abschließen.</p>

<div class="card">
    <ul class="steps">
        <?php foreach ($steps as $i => $step): ?>
        <li>
            <span class="step-status <?= $step['done'] ? 'step-done' : '' ?>">
                <?= $step['done'] ? '&#10003;' : $i + 1 ?>
            </span>
            <div>
                <strong><?= e($step['title']) ?></strong><?= !empty($step['optional']) ? ' <span class="hint">(optional, empfohlen)</span>' : '' ?>
                <?php if ($step['link'] && !$step['done']): ?>
                    &nbsp;<a href="<?= e($step['link']) ?>">Jetzt erledigen</a>
                <?php elseif (!$step['done'] && !$step['link']): ?>
                    &nbsp;<span class="hint">(durch Inhaber bzw. Administrator)</span>
                <?php endif; ?>
                <div class="hint"><?= e($step['desc']) ?></div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($allDone): ?>
    <form method="post" class="form-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="complete">
        <button type="submit" class="btn">Einrichtung abschließen</button>
    </form>
    <?php endif; ?>
</div>

<?php if ((int)$ctx['onboarding_completed']): ?>
<p><a class="btn btn-secondary" href="dashboard.php">Zum Dashboard</a></p>
<?php endif; ?>
<?php layout_footer($ctx); ?>
