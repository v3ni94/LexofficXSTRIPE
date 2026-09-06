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
    // Testhaken (nur CLI): Ersatz-Client ohne echte Stripe-Aufrufe.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['lexsepa_billing_client_factory']) && is_callable($GLOBALS['lexsepa_billing_client_factory'])) {
        $c = ($GLOBALS['lexsepa_billing_client_factory'])();
        if ($c instanceof StripeClient) {
            return $c;
        }
    }
    $b = config('billing', []);
    if (empty($b['enabled']) || empty($b['stripe_secret_key'])) {
        throw new RuntimeException('Die Plattform-Abrechnung ist nicht konfiguriert.');
    }
    return new StripeClient((string)$b['stripe_secret_key']);
}

/**
 * Tarifwechsel eines Inhabers mit bestehendem Stripe-Abonnement (Paket 4b).
 * Upgrade (höherer Preis): sofort wirksam, anteilige Berechnung wird sofort in Rechnung gestellt und
 * eingezogen (proration_behavior always_invoice, payment_behavior error_if_incomplete: scheitert die
 * Zahlung, bleibt der alte Tarif bestehen). Downgrade: sofort wirksam, anteilige Gutschrift auf die
 * nächste Rechnung (create_prorations); Downgrade-Schutz über plan_change_allowed (Benutzer).
 * Bestellbestätigung (AGB, Unternehmer) wird wie beim Abschluss protokolliert, der Wechsel im Audit.
 * @return array{direction:string,plan:array,old_plan:array}
 */
function billing_change_plan(array $org, array $newPlan, array $actor, array $post): array
{
    if (!billing_enabled()) {
        throw new RuntimeException('Die Plattform-Abrechnung ist nicht freigeschaltet; ein Tarifwechsel ist derzeit nicht möglich.');
    }
    if ((int)($newPlan['active'] ?? 0) !== 1 || (int)($newPlan['public_visible'] ?? 0) !== 1) {
        throw new RuntimeException('Dieser Tarif steht nicht zur Auswahl.');
    }
    if (empty($newPlan['stripe_price_id'])) {
        throw new RuntimeException('Für diesen Tarif ist noch keine Stripe-Preis-ID hinterlegt. Bitte wenden Sie sich an den Support.');
    }
    $old = plan_for_org($org);
    if ($old['code'] === $newPlan['code']) {
        throw new RuntimeException('Dieser Tarif ist bereits aktiv.');
    }
    if (empty($org['platform_stripe_subscription_id']) || !in_array((string)($org['subscription_status'] ?? ''), ['active', 'past_due'], true)) {
        throw new RuntimeException('Für diese Firma besteht kein laufendes Abonnement. Bitte zuerst den Tarif wählen und das Abonnement abschließen.');
    }
    $chk = plan_change_allowed($org['id'], $newPlan);
    if (!$chk['allowed']) {
        throw new RuntimeException($chk['reason']);
    }
    $direction = plan_change_direction($old, $newPlan);
    billing_record_consent($org, $newPlan, $actor, $post, $direction === 'upgrade' ? 'Zahlungspflichtig auf höheren Tarif wechseln' : 'Tarif wechseln');

    $client = billing_client();
    $subId = (string)$org['platform_stripe_subscription_id'];
    $sub = $client->call('GET', '/subscriptions/' . rawurlencode($subId));
    $itemId = (string)($sub['items']['data'][0]['id'] ?? '');
    if ($itemId === '') {
        throw new RuntimeException('Das Abonnement bei Stripe enthält keine Position; bitte wenden Sie sich an den Support.');
    }
    $params = [
        'items[0][id]'         => $itemId,
        'items[0][price]'      => (string)$newPlan['stripe_price_id'],
        'proration_behavior'   => $direction === 'upgrade' ? 'always_invoice' : 'create_prorations',
        'metadata[plan_code]'  => (string)$newPlan['code'],
        'metadata[tenant_id]'  => (string)$org['id'],
    ];
    if ($direction === 'upgrade') {
        $params['payment_behavior'] = 'error_if_incomplete';
    }
    $updated = $client->call('POST', '/subscriptions/' . rawurlencode($subId), $params);
    if (!isset($updated['metadata']['plan_code'])) {
        $updated['metadata']['plan_code'] = (string)$newPlan['code'];
    }
    billing_apply_subscription($org['id'], $updated);
    db()->prepare('UPDATE organizations SET plan_code = ?, plan_changed_at = NOW() WHERE id = ?')->execute([$newPlan['code'], $org['id']]);
    plan_get('');
    audit_log($org['id'], $actor, 'subscription_plan_changed', 'organization', $org['id'], [
        'from' => $old['code'], 'to' => $newPlan['code'], 'direction' => $direction,
        'price_cents_net_from' => (int)$old['price_cents'], 'price_cents_net_to' => (int)$newPlan['price_cents'],
        'proration' => $params['proration_behavior'],
    ]);
    return ['direction' => $direction, 'plan' => $newPlan, 'old_plan' => $old];
}

/**
 * Tarif für eine Firma OHNE laufendes Stripe-Abonnement wählen (vor dem Abschluss oder nach Kündigung).
 * Ändert nur plan_code; der Abschluss erfolgt anschließend über den Checkout mit diesem Tarif.
 */
function billing_choose_plan(array $org, array $newPlan, array $actor): void
{
    if (!billing_enabled()) {
        throw new RuntimeException('Die Plattform-Abrechnung ist nicht freigeschaltet; die Tarifwahl ist derzeit nicht möglich.');
    }
    if ((int)($newPlan['active'] ?? 0) !== 1 || (int)($newPlan['public_visible'] ?? 0) !== 1) {
        throw new RuntimeException('Dieser Tarif steht nicht zur Auswahl.');
    }
    if (!empty($org['platform_stripe_subscription_id']) && in_array((string)($org['subscription_status'] ?? ''), ['active', 'past_due'], true)) {
        throw new RuntimeException('Für diese Firma läuft bereits ein Abonnement; bitte den Tarifwechsel verwenden.');
    }
    $chk = plan_change_allowed($org['id'], $newPlan);
    if (!$chk['allowed']) {
        throw new RuntimeException($chk['reason']);
    }
    $old = plan_for_org($org);
    db()->prepare('UPDATE organizations SET plan_code = ?, plan_changed_at = NOW() WHERE id = ?')->execute([$newPlan['code'], $org['id']]);
    audit_log($org['id'], $actor, 'plan_selected', 'organization', $org['id'], ['from' => $old['code'], 'to' => $newPlan['code']]);
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
    $base = app_base_url();
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
        'return_url' => app_base_url() . '/subscription.php',
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

/**
 * Rechnungen des Abonnements aus Stripe (nur Lesezugriff, Kundenfilter über die
 * Stripe-Kundennummer der Firma). Für das Rechnungsarchiv unter Abonnement.
 * @return array<int, array{id:string,number:?string,status:string,total_cents:int,currency:string,created:int,period_start:?int,period_end:?int,hosted_url:?string,pdf_url:?string}>
 */
function billing_list_invoices(array $org, int $limit = 24): array
{
    if (!billing_enabled() || empty($org['platform_stripe_customer_id'])) {
        return [];
    }
    $res = billing_client()->call('GET', '/invoices', [
        'customer' => (string)$org['platform_stripe_customer_id'],
        'limit'    => max(1, min(100, $limit)),
    ]);
    $out = [];
    foreach ((array)($res['data'] ?? []) as $inv) {
        if (($inv['status'] ?? '') === 'draft') {
            continue;
        }
        $out[] = [
            'id'           => (string)($inv['id'] ?? ''),
            'number'       => isset($inv['number']) ? (string)$inv['number'] : null,
            'status'       => (string)($inv['status'] ?? ''),
            'total_cents'  => (int)($inv['total'] ?? 0),
            'currency'     => strtoupper((string)($inv['currency'] ?? 'eur')),
            'created'      => (int)($inv['created'] ?? 0),
            'period_start' => isset($inv['period_start']) ? (int)$inv['period_start'] : null,
            'period_end'   => isset($inv['period_end']) ? (int)$inv['period_end'] : null,
            'hosted_url'   => isset($inv['hosted_invoice_url']) ? (string)$inv['hosted_invoice_url'] : null,
            'pdf_url'      => isset($inv['invoice_pdf']) ? (string)$inv['invoice_pdf'] : null,
        ];
    }
    return $out;
}

/** Lesbarer Status einer Stripe-Rechnung. */
function billing_invoice_status_label(string $status): string
{
    return match ($status) {
        'paid' => 'Bezahlt',
        'open' => 'Offen',
        'void' => 'Storniert',
        'uncollectible' => 'Uneinbringlich',
        default => $status,
    };
}

/**
 * Bestellbestätigung vor dem Stripe-Checkout prüfen und protokollieren (AGB-Zustimmung,
 * Unternehmerbestätigung). Wirft bei fehlender Zustimmung. Der Audit-Eintrag hält
 * Zeitpunkt, IP (über audit_log), AGB-Fassung und Preis fest.
 */
function billing_record_consent(array $org, array $plan, array $actor, array $post, string $button = 'Zahlungspflichtig abonnieren'): void
{
    if (($post['agb'] ?? '') !== '1') {
        throw new RuntimeException('Bitte bestätigen Sie die Allgemeinen Geschäftsbedingungen, um das Abonnement abzuschließen.');
    }
    if (($post['unternehmer'] ?? '') !== '1') {
        throw new RuntimeException('Bitte bestätigen Sie, dass Sie das Abonnement als Unternehmen abschließen.');
    }
    audit_log($org['id'], $actor, 'subscription_consent', 'organization', $org['id'], [
        'plan' => $plan['code'], 'price_cents_net' => (int)$plan['price_cents'], 'period_days' => (int)$plan['period_days'],
        'agb_version' => (string)config('agb_version', 'AGB smart-einzug.de, Stand ' . date('d.m.Y')),
        'agb_url' => public_base_url() . '/agb', 'button' => $button,
    ]);
}
