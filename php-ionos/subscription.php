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
            // Bestellbestätigung (AGB, Unternehmerbestätigung) wird protokolliert, dann Stripe Checkout
            billing_record_consent($org, $plan, $ctx, $_POST);
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
$subAllowed = subscription_allows_operation($org);
$needsContract = billing_enabled() && (int)$org['billing_exempt'] !== 1 && !$subAllowed;
$ordering = $needsContract && !empty($_GET['bestellen']);
$invoices = [];
$invoiceError = null;
if (billing_enabled() && !empty($org['platform_stripe_customer_id'])) {
    try {
        $invoices = billing_list_invoices($org);
    } catch (Throwable $e) {
        $invoiceError = 'Die Rechnungsliste konnte gerade nicht von Stripe geladen werden. Bitte später erneut versuchen oder das Kundenportal öffnen.';
        error_log('Rechnungsarchiv: ' . $e->getMessage());
    }
}

layout_header('Abonnement', $ctx);
?>
<h1>Abonnement</h1>
<p class="page-sub"><?= e($org['name']) ?> · registriert am <?= format_datetime($org['created_at'] ?? null) ?></p>

<?php if ($ordering): ?>
<div class="card" id="bestellen">
    <h2><?= $org['subscription_status'] === 'canceled' ? 'Vertrag aktivieren' : 'Abonnement abschließen' ?>: Bestellübersicht</h2>
    <dl class="kv">
        <dt>Leistung</dt><dd><?= e(product_name()) ?>, Tarif <?= e($plan['name']) ?>: SEPA-Einzug für Rechnungen aus Lexware Office über das eigene Stripe-Konto, Mandatsverwaltung, Einzugshistorie, Support</dd>
        <dt>Preis</dt><dd><?= format_eur_cents((int)$plan['price_cents']) ?> netto je <?= (int)$plan['period_days'] ?> Tage<?= billing_vat_hint((int)$plan['price_cents']) ?>. Die Umsatzsteuer wird auf der Rechnung ausgewiesen; bei gültiger USt-IdNr. außerhalb Deutschlands gilt das Reverse-Charge-Verfahren.</dd>
        <dt>Laufzeit</dt><dd>Abrechnungsperiode <?= (int)$plan['period_days'] ?> Tage, verlängert sich automatisch um jeweils <?= (int)$plan['period_days'] ?> Tage, bis Sie kündigen.</dd>
        <dt>Kündigung</dt><dd>Jederzeit zum Ende der laufenden Abrechnungsperiode, ohne Frist, über Firma > Abonnement. Der Zugriff bleibt bis zum Periodenende bestehen.</dd>
        <dt>Zahlung</dt><dd>Über Stripe (SEPA-Lastschrift oder Karte). Die erste Abbuchung erfolgt mit Abschluss, danach je Periode. Rechnungen finden Sie hier im Archiv und im Stripe-Kundenportal.</dd>
        <dt>Vertragspartner</dt><dd><?= e((string)(config('operator')['name'] ?? 'Müller Holding AG')) ?>, <?= e((string)(config('operator')['street'] ?? '')) ?>, <?= e((string)(config('operator')['zip_city'] ?? '')) ?></dd>
    </dl>
    <form method="post" id="order-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checkout">
        <label style="display: flex; gap: 8px; align-items: flex-start; margin-top: 8px;">
            <input type="checkbox" name="unternehmer" value="1" required>
            <span>Ich schließe das Abonnement als Unternehmen bzw. in Ausübung meiner gewerblichen oder selbständigen Tätigkeit ab. Das Angebot richtet sich nicht an Verbraucher.</span>
        </label>
        <label style="display: flex; gap: 8px; align-items: flex-start; margin-top: 8px;">
            <input type="checkbox" name="agb" value="1" required>
            <span>Ich habe die <a href="<?= e(marketing_url('/agb')) ?>" target="_blank" rel="noopener">Allgemeinen Geschäftsbedingungen</a> gelesen und akzeptiere sie. Die <a href="<?= e(marketing_url('/datenschutz')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a> habe ich zur Kenntnis genommen.</span>
        </label>
        <div class="form-actions" style="margin-top: 16px;">
            <button type="submit" class="btn">Zahlungspflichtig abonnieren</button>
            <a class="btn btn-ghost" href="subscription.php">Abbrechen</a>
        </div>
        <p class="hint">Nach dem Klick werden Sie zur gesicherten Bezahlseite von Stripe weitergeleitet. Der Vertrag kommt mit Abschluss der Zahlung dort zustande. Zeitpunkt, AGB-Fassung und Preis Ihrer Bestätigung werden protokolliert.</p>
    </form>
</div>
<?php endif; ?>

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
    <?php if ($needsContract): ?>
    <div class="card">
        <h2><?= $org['subscription_status'] === 'canceled' ? 'Vertrag aktivieren' : 'Abonnement abschließen' ?></h2>
        <p><?= $org['subscription_status'] === 'canceled' ? 'Ihr Vertrag ist beendet. Aktivieren Sie ihn neu, um Einzüge, Synchronisation und Kundenpflege wieder zu nutzen.' : 'Der Firmenaccount wird mit aktivem Abonnement sofort freigeschaltet.' ?></p>
        <?php if (!$ordering): ?>
        <a class="btn" href="subscription.php?bestellen=1#bestellen"><?= $org['subscription_status'] === 'canceled' ? 'Vertrag aktivieren' : 'Jetzt abschließen' ?>: <?= format_eur_cents((int)$plan['price_cents']) ?> netto je <?= (int)$plan['period_days'] ?> Tage</a>
        <p class="hint">Alle Preise netto<?= billing_vat_hint((int)$plan['price_cents']) ?>. Vor der Weiterleitung zu Stripe sehen Sie eine Bestellübersicht mit Preis, Laufzeit und Kündigungsregel.</p>
        <?php endif; ?>
    </div>
    <?php if (!empty($org['platform_stripe_customer_id'])): ?>
    <div class="card">
        <h2>Zahlungsmethode und frühere Rechnungen</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="portal"><button type="submit" class="btn btn-secondary">Stripe-Kundenportal öffnen</button></form>
    </div>
    <?php endif; ?>
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
    <div class="card" id="kuendigung">
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

<?php if (billing_enabled() && (int)$org['billing_exempt'] !== 1 && (!empty($org['platform_stripe_customer_id']) || $invoiceError)): ?>
<div class="card" id="rechnungen">
    <h2>Rechnungsarchiv</h2>
    <?php if ($invoiceError): ?>
        <p class="flash flash-warn"><?= e($invoiceError) ?></p>
    <?php elseif (!$invoices): ?>
        <p class="hint">Noch keine Rechnungen vorhanden. Die erste Rechnung entsteht mit dem Abschluss des Abonnements.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Datum</th><th>Rechnungsnummer</th><th>Zeitraum</th><th class="num">Betrag</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= $inv['created'] ? e(date('d.m.Y', $inv['created'])) : '-' ?></td>
                    <td><?= e((string)($inv['number'] ?? '')) ?></td>
                    <td><?= $inv['period_start'] && $inv['period_end'] ? e(date('d.m.Y', $inv['period_start']) . ' bis ' . date('d.m.Y', $inv['period_end'])) : '-' ?></td>
                    <td class="num"><?= format_eur_cents($inv['total_cents']) ?></td>
                    <td><span class="badge <?= $inv['status'] === 'paid' ? 'badge-success' : ($inv['status'] === 'open' ? 'badge-warn' : 'badge-neutral') ?>"><?= e(billing_invoice_status_label($inv['status'])) ?></span></td>
                    <td>
                        <?php if ($inv['hosted_url']): ?><a class="btn btn-sm btn-secondary" href="<?= e($inv['hosted_url']) ?>" target="_blank" rel="noopener">Ansehen</a><?php endif; ?>
                        <?php if ($inv['pdf_url']): ?><a class="btn btn-sm btn-ghost" href="<?= e($inv['pdf_url']) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Die Rechnungen stellt Stripe im Namen des Betreibers aus; sie sind dauerhaft hier und im Stripe-Kundenportal abrufbar.</p>
    <?php endif; ?>
</div>
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
