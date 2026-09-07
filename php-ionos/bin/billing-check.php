<?php
/**
 * Prüft die Plattform-Abrechnung (Abonnements der Firmen über das Stripe-Konto der Müller Holding AG),
 * OHNE etwas zu ändern: Konfiguration, Schlüssel, Preise, Webhook, Kundenportal, Steuer und die Lage in
 * der eigenen Datenbank.
 *
 *   php bin/billing-check.php            Bericht, Exit 0 nur wenn kein Fehler gefunden wurde
 *   php bin/billing-check.php --alle     auch nicht öffentliche und inaktive Tarife prüfen
 *
 * Vor dem Scharfschalten (config billing.enabled = true) ausführen, danach erneut. Es werden
 * ausschließlich lesende Stripe-Aufrufe verwendet; Schlüssel erscheinen nur maskiert.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/billing.php';
require_once dirname(__DIR__) . '/app/billing_setup.php';

$opts = cli_opts($argv);
$all = isset($opts['alle']);
$b = (array)config('billing', []);
$baseUrl = app_base_url();
$errors = [];
$warnings = [];
$infos = [];

$collect = static function (array $r) use (&$errors, &$warnings, &$infos): void {
    foreach ((array)($r['errors'] ?? []) as $l) { $errors[] = (string)$l; }
    foreach ((array)($r['warnings'] ?? []) as $l) { $warnings[] = (string)$l; }
    foreach ((array)($r['info'] ?? []) as $l) { $infos[] = (string)$l; }
};

echo "SmartEinzug: Prüfung der Plattform-Abrechnung\n";
echo str_repeat('=', 78) . "\n\n";

// --- 1. Konfiguration (ohne Netzzugriff) ------------------------------------------------------
echo "1. Konfiguration\n";
$cfg = billing_check_config($b, $baseUrl);
$collect($cfg);
printf("   Umgebung: %s, Basisadresse: %s\n", (string)config('environment', '?'), $baseUrl !== '' ? $baseUrl : '(fehlt)');
printf("   Abrechnung freigeschaltet (billing.enabled): %s\n", !empty($b['enabled']) ? 'ja' : 'nein');
printf("   Betriebsart des Schlüssels: %s\n", $cfg['mode']);
if ($cfg['mode'] === 'test' && !empty($b['enabled']) && (string)config('environment', '') === 'prod') {
    $warnings[] = 'In der Produktionsumgebung ist ein TEST-Schlüssel hinterlegt und die Abrechnung ist freigeschaltet: Es entstehen keine echten Zahlungen.';
}

// --- 2. Stripe-Konto --------------------------------------------------------------------------
echo "\n2. Stripe-Konto\n";
$client = null;
try {
    $client = billing_client();
} catch (Throwable $e) {
    $errors[] = 'Kein Stripe-Client: ' . $e->getMessage();
    echo "   nicht prüfbar (" . $e->getMessage() . ")\n";
}
if ($client !== null) {
    try {
        $acc = $client->call('GET', '/account');
        printf("   Konto: %s (%s), Land %s, Standardwährung %s\n",
            (string)($acc['settings']['dashboard']['display_name'] ?? $acc['business_profile']['name'] ?? '(ohne Namen)'),
            (string)($acc['id'] ?? '?'), strtoupper((string)($acc['country'] ?? '?')), strtoupper((string)($acc['default_currency'] ?? '?')));
        if (strtolower((string)($acc['default_currency'] ?? 'eur')) !== 'eur') {
            $warnings[] = sprintf('Standardwährung des Kontos ist %s. Die Tarifpreise lauten auf EUR (kein Fehler, aber prüfen).', strtoupper((string)$acc['default_currency']));
        }
        if (array_key_exists('charges_enabled', $acc) && empty($acc['charges_enabled'])) {
            $errors[] = 'Das Stripe-Konto kann derzeit keine Zahlungen einziehen (charges_enabled=false). Kontoprüfung in Stripe abschließen.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Konto nicht abrufbar (GET /account): ' . $e->getMessage();
        echo "   Fehler: " . $e->getMessage() . "\n";
    }
}

// --- 3. Tarife und Stripe-Preise ---------------------------------------------------------------
echo "\n3. Tarife und Stripe-Preise\n";
$plans = plan_list(true);
$checked = 0;
foreach ($plans as $plan) {
    $bookable = (int)($plan['active'] ?? 0) === 1 && (int)($plan['public_visible'] ?? 0) === 1;
    if (!$bookable && !$all) {
        continue;
    }
    $code = (string)$plan['code'];
    $priceId = (string)($plan['stripe_price_id'] ?? '');
    printf("   %-16s %-22s %10s je %d Tage  %s\n", $code, (string)$plan['name'],
        billing_format_cents((int)$plan['price_cents']), (int)$plan['period_days'],
        $bookable ? 'buchbar' : 'nicht öffentlich');
    if ($priceId === '') {
        if ($bookable) {
            $errors[] = sprintf('Tarif %s ist buchbar, hat aber keine Stripe-Preis-ID. Checkout und Tarifwechsel scheitern (bin/billing-setup-stripe.php anlegen, oder Superadmin > Tarife).', $code);
        } else {
            $infos[] = sprintf('Tarif %s hat keine Stripe-Preis-ID (nicht öffentlich, daher unkritisch).', $code);
        }
        continue;
    }
    if ($client === null) {
        continue;
    }
    $checked++;
    try {
        $price = $client->call('GET', '/prices/' . rawurlencode($priceId), ['expand' => ['product']]);
        $collect(billing_check_price($price, $plan));
        $prod = (array)($price['product'] ?? []);
        printf("        Stripe: %s, %s, %s\n", $priceId,
            isset($price['unit_amount']) ? billing_format_cents((int)$price['unit_amount']) : 'ohne festen Betrag',
            'Produkt "' . (string)($prod['name'] ?? '?') . '"' . (isset($prod['active']) && !$prod['active'] ? ' (archiviert)' : ''));
        if (isset($prod['active']) && !$prod['active']) {
            $errors[] = sprintf('Tarif %s: das Stripe-Produkt ist archiviert. Ein Checkout mit diesem Preis schlägt fehl.', $code);
        }
    } catch (Throwable $e) {
        $errors[] = sprintf('Tarif %s: Preis %s nicht abrufbar: %s', $code, $priceId, $e->getMessage());
        echo "        Fehler: " . $e->getMessage() . "\n";
    }
}
if ($checked === 0 && $client !== null) {
    $warnings[] = 'Kein Tarif mit Stripe-Preis-ID geprüft.';
}

// --- 4. Webhook -------------------------------------------------------------------------------
echo "\n4. Webhook des Plattformkontos\n";
$expectedHook = billing_expected_webhook_url($baseUrl);
printf("   Erwartet: %s\n", $expectedHook !== '' ? $expectedHook : '(Basisadresse fehlt)');
printf("   Benötigte Ereignisse: %s\n", implode(', ', BILLING_REQUIRED_WEBHOOK_EVENTS));
if ($client !== null) {
    try {
        $eps = $client->call('GET', '/webhook_endpoints', ['limit' => 100]);
        $collect(billing_check_webhook((array)($eps['data'] ?? []), $expectedHook));
        foreach ((array)($eps['data'] ?? []) as $ep) {
            printf("   vorhanden: %s (%s, %d Ereignisse)\n", (string)($ep['url'] ?? '?'), (string)($ep['status'] ?? '?'), count((array)($ep['enabled_events'] ?? [])));
        }
    } catch (Throwable $e) {
        $errors[] = 'Webhook-Endpunkte nicht abrufbar (GET /webhook_endpoints): ' . $e->getMessage();
        echo "   Fehler: " . $e->getMessage() . "\n";
    }
}

// --- 5. Kundenportal und Steuer ----------------------------------------------------------------
echo "\n5. Kundenportal und Umsatzsteuer\n";
if ($client !== null) {
    try {
        $confs = $client->call('GET', '/billing_portal/configurations', ['limit' => 10, 'active' => 'true']);
        $list = (array)($confs['data'] ?? []);
        if (!$list) {
            $warnings[] = 'Keine aktive Konfiguration des Stripe-Kundenportals gefunden. Die Anwendung verlinkt es (Abonnement, Rechnungen, Zahlungsmethode); ohne Konfiguration scheitert der Aufruf.';
            echo "   keine aktive Portal-Konfiguration\n";
        } else {
            printf("   Kundenportal: %d aktive Konfiguration(en), Standard vorhanden: %s\n", count($list),
                array_reduce($list, fn($c, $x) => $c || !empty($x['is_default']), false) ? 'ja' : 'nein');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Kundenportal nicht prüfbar (GET /billing_portal/configurations): ' . $e->getMessage();
        echo "   nicht prüfbar: " . $e->getMessage() . "\n";
    }
    if (!array_key_exists('automatic_tax', $b) || !empty($b['automatic_tax'])) {
        try {
            $tax = $client->call('GET', '/tax/settings');
            $status = (string)($tax['status'] ?? '?');
            printf("   Stripe Tax: Status %s\n", $status);
            if ($status !== 'active') {
                $errors[] = sprintf('Stripe Tax ist nicht aktiv (Status %s), automatic_tax ist aber eingeschaltet. Checkout mit automatischer Steuer schlägt dann fehl.', $status);
            }
        } catch (Throwable $e) {
            $warnings[] = 'Stripe Tax nicht prüfbar (GET /tax/settings): ' . $e->getMessage() . '. Einstellung im Stripe-Dashboard prüfen.';
            echo "   Stripe Tax nicht prüfbar: " . $e->getMessage() . "\n";
        }
    }
}

// --- 6. Lage in der eigenen Datenbank -----------------------------------------------------------
echo "\n6. Firmen in der Datenbank\n";
try {
    $rows = db()->query(
        "SELECT subscription_status, billing_exempt, COUNT(*) AS anzahl
         FROM organizations WHERE deleted_at IS NULL
         GROUP BY subscription_status, billing_exempt ORDER BY anzahl DESC"
    )->fetchAll();
    $locked = 0;
    foreach ($rows as $r) {
        printf("   %-10s befreit=%d: %d\n", (string)$r['subscription_status'], (int)$r['billing_exempt'], (int)$r['anzahl']);
        if ((int)$r['billing_exempt'] !== 1 && !in_array((string)$r['subscription_status'], ['active', 'exempt'], true)) {
            $locked += (int)$r['anzahl'];
        }
    }
    if ($locked > 0) {
        $line = sprintf('%d Firma/Firmen ohne nutzbares Abonnement und ohne Befreiung. Sobald billing.enabled gesetzt ist, werden sie gesperrt (Inhaber landet auf der Abo-Seite, Mitarbeiter sehen einen Hinweis).', $locked);
        if (!empty($b['enabled'])) {
            $errors[] = $line . ' Die Abrechnung ist bereits freigeschaltet.';
        } else {
            $warnings[] = $line . ' Vor dem Scharfschalten entweder Abonnement abschließen lassen oder billing_exempt setzen (Superadmin > Firmen).';
        }
        $names = db()->query(
            "SELECT id, name, subscription_status FROM organizations
             WHERE deleted_at IS NULL AND billing_exempt <> 1 AND subscription_status NOT IN ('active','exempt')
             ORDER BY created_at LIMIT 20"
        )->fetchAll();
        foreach ($names as $n) {
            printf("     betroffen: %s (%s, %s)\n", (string)$n['name'], (string)$n['id'], (string)$n['subscription_status']);
        }
    }
} catch (Throwable $e) {
    $warnings[] = 'Firmenübersicht nicht lesbar: ' . $e->getMessage();
    echo "   nicht prüfbar: " . $e->getMessage() . "\n";
}

// --- Ergebnis ------------------------------------------------------------------------------------
echo "\n" . str_repeat('=', 78) . "\n";
foreach ($infos as $l) { echo "HINWEIS:  $l\n"; }
foreach ($warnings as $l) { echo "WARNUNG:  $l\n"; }
foreach ($errors as $l) { echo "FEHLER:   $l\n"; }
printf("\n%d Fehler, %d Warnungen\n", count($errors), count($warnings));
exit($errors ? 1 : 0);
