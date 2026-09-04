<?php
/**
 * Plattform-Abrechnung: Abonnement der Firmen (25 EUR je 4 Wochen) über das
 * Stripe-Konto der Müller Holding AG (Checkout, Billing Portal, Webhook).
 *
 * Getrennt von den Stripe-Konten der Firmen, über die diese ihre eigenen
 * SEPA-Einzüge abwickeln. Aktiv nur, wenn config('billing')['enabled']
 * gesetzt ist und ein Plattform-Schlüssel vorliegt.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/audit.php';

function billing_client(): StripeClient
{
    $b = config('billing', []);
    if (empty($b['enabled']) || empty($b['stripe_secret_key'])) {
        throw new RuntimeException('Die Plattform-Abrechnung ist nicht konfiguriert.');
    }
    return new StripeClient((string)$b['stripe_secret_key']);
}

/** Stripe-Kunden der Firma im Plattform-Konto sicherstellen. */
function billing_ensure_customer(array $org, array $owner): string
{
    if (!empty($org['platform_stripe_customer_id'])) {
        return (string)$org['platform_stripe_customer_id'];
    }
    $customer = billing_client()->call('POST', '/customers', [
        'name'     => $org['name'],
        'email'    => $owner['email'],
        'metadata' => ['tenant_id' => $org['id'], 'signup_domain' => (string)($org['signup_domain'] ?? '')],
    ]);
    db()->prepare('UPDATE organizations SET platform_stripe_customer_id = ? WHERE id = ?')
        ->execute([$customer['id'], $org['id']]);
    return (string)$customer['id'];
}

/** Checkout-Session für den Abo-Abschluss erzeugen; gibt die Weiterleitungs-URL zurück. */
function billing_checkout_url(array $org, array $plan, array $owner): string
{
    if (empty($plan['stripe_price_id'])) {
        throw new RuntimeException('Für diesen Tarif ist noch keine Stripe-Preis-ID hinterlegt (Superadmin > Tarife).');
    }
    if (!empty($org['platform_stripe_subscription_id']) && in_array($org['subscription_status'], ['active', 'past_due'], true)) {
        throw new RuntimeException('Für diese Firma besteht bereits ein Abonnement. Änderungen bitte über das Kundenportal.');
    }
    $customerId = billing_ensure_customer($org, $owner);
    $base = rtrim((string)config('base_url'), '/');
    $session = billing_client()->call('POST', '/checkout/sessions', [
        'mode'                => 'subscription',
        'customer'            => $customerId,
        'client_reference_id' => $org['id'],
        'locale'              => 'de',
        'line_items'          => [['price' => $plan['stripe_price_id'], 'quantity' => 1]],
        'success_url'         => $base . '/subscription.php?checkout=success',
        'cancel_url'          => $base . '/subscription.php?checkout=cancel',
        'metadata'            => ['tenant_id' => $org['id'], 'plan_code' => $plan['code']],
        'subscription_data'   => ['metadata' => ['tenant_id' => $org['id'], 'plan_code' => $plan['code']]],
        'allow_promotion_codes' => 'true',
        // Umsatzsteuer: Preise sind Nettopreise. Stripe Tax berechnet den gültigen Satz
        // (derzeit 19 % in Deutschland) anhand der Rechnungsadresse und weist ihn auf der
        // Rechnung aus. USt-IdNr. wird abgefragt (Reverse Charge im EU-Ausland).
        'automatic_tax'       => ['enabled' => config('billing', [])['automatic_tax'] ?? true ? 'true' : 'false'],
        'tax_id_collection'   => ['enabled' => 'true'],
        'billing_address_collection' => 'required',
        'customer_update'     => ['address' => 'auto', 'name' => 'auto'],
    ]);
    audit_log($org['id'], $owner, 'subscription_checkout', 'organization', $org['id'], ['plan' => $plan['code']]);
    return (string)$session['url'];
}

/** Billing-Portal (Zahlungsmethode ändern, Rechnungen, Kündigung). */
function billing_portal_url(array $org): string
{
    if (empty($org['platform_stripe_customer_id'])) {
        throw new RuntimeException('Für diese Firma existiert noch kein Abonnement.');
    }
    $session = billing_client()->call('POST', '/billing_portal/sessions', [
        'customer'   => $org['platform_stripe_customer_id'],
        'return_url' => rtrim((string)config('base_url'), '/') . '/subscription.php',
    ]);
    return (string)$session['url'];
}

/** Kündigung zum Periodenende vormerken oder zurücknehmen. */
function billing_set_cancel_at_period_end(array $org, bool $cancel, array $actor): void
{
    if (empty($org['platform_stripe_subscription_id'])) {
        throw new RuntimeException('Für diese Firma existiert kein aktives Abonnement.');
    }
    $sub = billing_client()->call(
        'POST',
        '/subscriptions/' . rawurlencode($org['platform_stripe_subscription_id']),
        ['cancel_at_period_end' => $cancel ? 'true' : 'false']
    );
    billing_apply_subscription($org['id'], $sub);
    audit_log($org['id'], $actor, $cancel ? 'subscription_cancelled' : 'subscription_changed', 'organization', $org['id'], [
        'cancel_at_period_end' => $cancel,
    ]);
}

/** Abo-Daten aus einem Stripe-Subscription-Objekt in die Firma übernehmen. */
function billing_apply_subscription(string $tenantId, array $sub): void
{
    $status = (string)($sub['status'] ?? '');
    $map = [
        'active'   => 'active',
        'trialing' => 'active',
        'past_due' => 'past_due',
        'unpaid'   => 'past_due',
        'canceled' => 'canceled',
        'incomplete' => 'pending',
        'incomplete_expired' => 'canceled',
        'paused'   => 'past_due',
    ];
    $local = $map[$status] ?? 'pending';
    $periodEnd = null;
    if (!empty($sub['current_period_end'])) {
        $periodEnd = date('Y-m-d H:i:s', (int)$sub['current_period_end']);
    } elseif (!empty($sub['items']['data'][0]['current_period_end'])) {
        $periodEnd = date('Y-m-d H:i:s', (int)$sub['items']['data'][0]['current_period_end']);
    }
    $planCode = $sub['metadata']['plan_code'] ?? null;

    $sql = 'UPDATE organizations SET subscription_status = ?, subscription_period_end = ?, cancel_at_period_end = ?,
                   platform_stripe_subscription_id = ?, platform_stripe_customer_id = COALESCE(?, platform_stripe_customer_id)';
    $params = [
        $local, $periodEnd, !empty($sub['cancel_at_period_end']) ? 1 : 0,
        $sub['id'] ?? null, is_string($sub['customer'] ?? null) ? $sub['customer'] : null,
    ];
    if ($planCode) {
        $sql .= ', plan_code = ?';
        $params[] = $planCode;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $tenantId;
    db()->prepare($sql)->execute($params);

    if ($local === 'active') {
        funnel_event_once($tenantId, 'subscription_active');
    }
}

/**
 * Webhook-Ereignis nur einmal verarbeiten (Stripe wiederholt Zustellungen).
 * true, wenn das Ereignis neu ist und verarbeitet werden soll.
 */
function billing_event_claim(string $eventId, string $type): bool
{
    if ($eventId === '') {
        return true;
    }
    $stmt = db()->prepare('INSERT IGNORE INTO webhook_events (id, source, event_type) VALUES (?, ?, ?)');
    $stmt->execute([mb_substr($eventId, 0, 255), 'billing', mb_substr($type, 0, 60)]);
    return $stmt->rowCount() === 1;
}

/** Webhook-Ereignis des Plattform-Kontos verarbeiten. */
function billing_handle_event(array $event): string
{
    $type = (string)($event['type'] ?? '');
    $obj = $event['data']['object'] ?? [];
    if (!billing_event_claim((string)($event['id'] ?? ''), $type)) {
        return 'bereits verarbeitet';
    }

    switch ($type) {
        case 'checkout.session.completed':
            $tenantId = $obj['metadata']['tenant_id'] ?? ($obj['client_reference_id'] ?? null);
            if (!$tenantId) {
                return 'checkout ohne tenant_id';
            }
            if (!empty($obj['subscription']) && is_string($obj['subscription'])) {
                $sub = billing_client()->call('GET', '/subscriptions/' . rawurlencode($obj['subscription']));
                billing_apply_subscription($tenantId, $sub);
            }
            audit_log($tenantId, null, 'subscription_changed', 'organization', $tenantId, ['event' => $type]);
            return 'ok';

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $tenantId = $obj['metadata']['tenant_id'] ?? null;
            // Reihenfolgeschutz: ein älteres Ereignis darf einen neueren Stand nicht überschreiben
            $stmtEv = db()->prepare("SELECT MAX(event_created) FROM webhook_events WHERE source = 'billing' AND object_id = ?");
            $stmtEv->execute([(string)($obj['id'] ?? '')]);
            $latest = (int)($stmtEv->fetchColumn() ?: 0);
            $created = (int)($event['created'] ?? 0);
            if ($latest > $created) {
                return 'veraltetes Ereignis ignoriert';
            }
            db()->prepare('UPDATE webhook_events SET object_id = ?, event_created = ? WHERE id = ?')
                ->execute([(string)($obj['id'] ?? ''), $created, mb_substr((string)($event['id'] ?? ''), 0, 255)]);
            if (!$tenantId) {
                $stmt = db()->prepare('SELECT id FROM organizations WHERE platform_stripe_subscription_id = ? OR platform_stripe_customer_id = ?');
                $stmt->execute([$obj['id'] ?? '', is_string($obj['customer'] ?? null) ? $obj['customer'] : '']);
                $tenantId = $stmt->fetchColumn() ?: null;
            }
            if (!$tenantId) {
                return 'subscription ohne Firma';
            }
            billing_apply_subscription($tenantId, $obj);
            audit_log($tenantId, null, $type === 'customer.subscription.deleted' ? 'subscription_cancelled' : 'subscription_changed', 'organization', $tenantId, ['event' => $type]);
            return 'ok';

        case 'invoice.payment_failed':
            $customerId = is_string($obj['customer'] ?? null) ? $obj['customer'] : '';
            if ($customerId !== '') {
                db()->prepare("UPDATE organizations SET subscription_status = 'past_due' WHERE platform_stripe_customer_id = ? AND subscription_status = 'active'")
                    ->execute([$customerId]);
            }
            return 'ok';

        default:
            return 'ignoriert';
    }
}
