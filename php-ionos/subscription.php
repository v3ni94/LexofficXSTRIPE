<?php
/**
 * Abonnement der Firma (nur Inhaber): Tarif, Status, Abschluss über Stripe
 * Checkout, Zahlungsmethode/Rechnungen über das Billing Portal, Kündigung
 * zum Periodenende. Ohne aktive Plattform-Abrechnung nur Anzeige.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/billing.php';

$ctx = require_owner();
$tenantId = $ctx['org_id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$tenantId]);
$org = $stmt->fetch();
$plan = plan_for_org($org);
$owner = user_load($ctx['user_id']);

if (($_GET['checkout'] ?? '') === 'success') {
    // Webhook aktualisiert den Status; hier zusätzlich direkt nachziehen, falls schon vorhanden
    try {
        if (billing_enabled() && !empty($org['platform_stripe_customer_id'])) {
            $subs = billing_client()->call('GET', '/subscriptions', ['customer' => $org['platform_stripe_customer_id'], 'limit' => 1, 'status' => 'all']);
            if (!empty($subs['data'][0])) {
                billing_apply_subscription($tenantId, $subs['data'][0]);
            }
        }
    } catch (Throwable $e) {
        error_log('Abo-Abgleich nach Checkout: ' . $e->getMessage());
    }
    flash_set('success', 'Vielen Dank. Ihr Abonnement wird eingerichtet; der Status aktualisiert sich in Kürze.');
    redirect('subscription.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'checkout') {
            redirect(billing_checkout_url($org, $plan, $owner));
        } elseif ($action === 'portal') {
            redirect(billing_portal_url($org));
        } elseif ($action === 'cancel' || $action === 'resume') {
            $me = user_load($ctx['user_id']);
            if ($err = verify_password_and_2fa($me, $_POST['password'] ?? '', $_POST['code'] ?? '')) {
                throw new RuntimeException($err);
            }
            billing_set_cancel_at_period_end($org, $action === 'cancel', $ctx);
            security_notify_owner($tenantId, $action === 'cancel' ? 'Abonnement gekündigt' : 'Kündigung zurückgenommen', [
                sprintf('Das Abonnement der Firma %s wurde %s.', $org['name'], $action === 'cancel' ? 'zum Ende der laufenden Abrechnungsperiode gekündigt' : 'wieder aktiviert'),
            ]);
            flash_set('success', $action === 'cancel'
                ? 'Kündigung zum Ende der laufenden Abrechnungsperiode vorgemerkt.'
                : 'Kündigung zurückgenommen, das Abonnement läuft weiter.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('subscription.php');
}

$publicPlans = array_filter(plan_list(true), fn($p) => (int)$p['public_visible'] === 1);

layout_header('Abonnement', $ctx);
?>
<h1>Abonnement</h1>
<p class="page-sub"><?= e($org['name']) ?></p>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= e($plan['name']) ?></div>
        <div class="stat-label">Tarif · <?= format_eur_cents((int)$plan['price_cents']) ?> netto je <?= (int)$plan['period_days'] ?> Tage<?= billing_vat_hint((int)$plan['price_cents']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= e(subscription_status_label((string)$org['subscription_status'])) ?></div>
        <div class="stat-label">Status<?= (int)$org['cancel_at_period_end'] ? ' · Kündigung vorgemerkt' : '' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= $org['subscription_period_end'] ? format_date($org['subscription_period_end']) : '-' ?></div>
        <div class="stat-label">Ende der laufenden Abrechnungsperiode</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= $plan['max_users'] === null || (int)$plan['unlimited_users'] ? 'unbegrenzt' : (int)$plan['max_users'] ?></div>
        <div class="stat-label">Benutzer · Einzüge je Periode: <?= $plan['max_collections_per_period'] === null ? 'unbegrenzt' : (int)$plan['max_collections_per_period'] ?></div>
    </div>
</div>

<div class="card">
    <h2>Leistungen</h2>
    <ul class="check-list">
        <li>Unbegrenzt SEPA-Einzüge und unbegrenzt Mitarbeiter im Tarif UNLIMITED START</li>
        <li>Lexware-Office-Anbindung, Stripe-Anbindung, Hintergrundsynchronisation</li>
        <li>SEPA-Verwaltung mit Mandatsdokument, Einzugshistorie, Statussynchronisation, Rechnungsarchiv, Support</li>
    </ul>
    <p class="hint">Abrechnung alle vier Wochen. Bestandskunden behalten die Konditionen ihres Tarifs, solange dieser
        administrativ nicht geändert wird.</p>
</div>

<?php if ((int)$org['billing_exempt'] === 1): ?>
<div class="card"><p>Diese Firma ist von der Plattform-Abrechnung befreit.</p></div>
<?php elseif (!billing_enabled()): ?>
<div class="card"><p class="hint">Die Online-Abrechnung ist noch nicht freigeschaltet. Bis dahin entstehen keine Einschränkungen.</p></div>
<?php else: ?>
    <?php if (in_array($org['subscription_status'], ['pending', 'canceled'], true) && empty($org['platform_stripe_subscription_id'])): ?>
    <div class="card">
        <h2>Abonnement abschließen</h2>
        <p>Sie werden zu Stripe weitergeleitet, um die Zahlungsmethode zu hinterlegen. Der Firmenaccount ist danach sofort nutzbar.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="checkout">
            <button type="submit" class="btn">Jetzt für <?= format_eur_cents((int)$plan['price_cents']) ?> netto je 4 Wochen abschließen</button>
            <p class="hint">Alle Preise netto<?= billing_vat_hint((int)$plan['price_cents']) ?>. Die Umsatzsteuer wird auf der Stripe-Bezahlseite anhand Ihrer Rechnungsadresse berechnet und auf der Rechnung ausgewiesen.</p>
        </form>
    </div>
    <?php else: ?>
    <div class="card">
        <h2>Zahlungsmethode und Rechnungen</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="portal">
            <button type="submit" class="btn btn-secondary">Zahlungsmethode ändern / Rechnungen ansehen</button>
        </form>
        <p class="hint">Öffnet das gesicherte Stripe-Kundenportal. Änderungen am Abonnement meldet Stripe an die Anwendung zurück und werden dort protokolliert; Zahlungsdaten selbst verbleiben bei Stripe.</p>
    </div>
    <div class="card">
        <h2><?= (int)$org['cancel_at_period_end'] ? 'Kündigung zurücknehmen' : 'Abonnement kündigen' ?></h2>
        <p class="hint">Die Kündigung wirkt zum Ende der laufenden Abrechnungsperiode
            (<?= $org['subscription_period_end'] ? format_date($org['subscription_period_end']) : 'siehe Stripe' ?>). Bis dahin bleibt der Zugriff bestehen.
            Zur Bestätigung sind Passwort und 2FA-Code erforderlich.</p>
        <form method="post" onsubmit="return confirm('Wirklich fortfahren?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= (int)$org['cancel_at_period_end'] ? 'resume' : 'cancel' ?>">
            <div class="form-row">
                <div><label for="c_pw">Passwort</label><input type="password" id="c_pw" name="password" required autocomplete="current-password"></div>
                <div><label for="c_code">2FA-Code</label><input type="text" id="c_code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code"></div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn <?= (int)$org['cancel_at_period_end'] ? '' : 'btn-danger' ?>">
                    <?= (int)$org['cancel_at_period_end'] ? 'Kündigung zurücknehmen' : 'Zum Periodenende kündigen' ?>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (count($publicPlans) > 1): ?>
<div class="card">
    <h2>Weitere Tarife</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarif</th><th>Preis</th><th>Einzüge je Periode</th><th>Benutzer</th></tr></thead>
            <tbody>
            <?php foreach ($publicPlans as $p): ?>
                <tr>
                    <td><?= e($p['name']) ?><?= $p['code'] === $plan['code'] ? ' <span class="badge badge-success">Aktuell</span>' : '' ?></td>
                    <td><?= format_eur_cents((int)$p['price_cents']) ?></td>
                    <td><?= $p['max_collections_per_period'] === null ? 'unbegrenzt' : (int)$p['max_collections_per_period'] ?></td>
                    <td><?= $p['max_users'] === null || (int)$p['unlimited_users'] ? 'unbegrenzt' : (int)$p['max_users'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Ein Tarifwechsel erfolgt über den Support. Ein Wechsel auf einen Tarif mit weniger Benutzern ist erst möglich,
        wenn die Anzahl der Benutzer und offenen Einladungen das neue Limit nicht überschreitet.</p>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
