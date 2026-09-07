<?php
/**
 * Legt für die buchbaren Tarife Produkt und Preis im Stripe-Konto der Plattform an (Müller Holding AG)
 * und trägt die Preis-ID in die Tabelle "plans" ein.
 *
 *   php bin/billing-setup-stripe.php                  Trockenlauf: zeigt nur, was angelegt würde
 *   php bin/billing-setup-stripe.php --apply          legt an (Testschlüssel)
 *   php bin/billing-setup-stripe.php --apply --live-bestaetigt   legt an, auch mit Live-Schlüssel
 *   php bin/billing-setup-stripe.php --tarif=code     nur diesen Tarif
 *
 * Wiederholbar: Vor jeder Anlage wird über den lookup_key (lexsepa_<tarifcode>) geprüft, ob der Preis
 * bereits existiert; dann wird er nur verwendet, nicht neu erzeugt. Beträge und Perioden stammen
 * ausschließlich aus der Tabelle "plans"; dieses Werkzeug erfindet keine Preise. Bestehende Stripe-Preise
 * werden nie geändert (in Stripe sind Betrag und Intervall eines Preises unveränderlich): Ein Tarif mit
 * anderem Betrag braucht einen neuen Preis, den bin/billing-check.php dann als Abweichung meldet.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/billing.php';
require_once dirname(__DIR__) . '/app/billing_setup.php';

$opts = cli_opts($argv);
$apply = isset($opts['apply']);
$onlyPlan = isset($opts['tarif']) && is_string($opts['tarif']) ? (string)$opts['tarif'] : '';
$b = (array)config('billing', []);
$mode = billing_key_mode((string)($b['stripe_secret_key'] ?? ''));

echo "SmartEinzug: Stripe-Artikel der Plattform-Abrechnung anlegen\n";
echo str_repeat('=', 78) . "\n";
printf("Umgebung: %s   Schlüssel: %s (%s)   Modus: %s\n\n",
    (string)config('environment', '?'), billing_mask_key((string)($b['stripe_secret_key'] ?? '')), $mode,
    $apply ? 'ANLEGEN' : 'Trockenlauf');

if ($apply && $mode === 'live' && !isset($opts['live-bestaetigt'])) {
    fwrite(STDERR, "Abbruch: Mit einem LIVE-Schlüssel ist zusätzlich --live-bestaetigt erforderlich.\n"
        . "Damit wird im echten Konto ein Produkt samt Preis angelegt (kundenwirksam).\n");
    exit(2);
}
if ($apply && $mode === 'unbekannt') {
    fwrite(STDERR, "Abbruch: billing.stripe_secret_key ist kein Stripe-Geheimschlüssel.\n");
    exit(2);
}

try {
    $client = billing_client();
} catch (Throwable $e) {
    // Trockenlauf ohne Schlüssel bleibt möglich: dann werden nur die geplanten Parameter gezeigt.
    if ($apply) {
        fwrite(STDERR, 'Abbruch: ' . $e->getMessage() . "\n");
        exit(2);
    }
    $client = null;
    echo "Hinweis: Kein nutzbarer Stripe-Schlüssel, es werden nur die geplanten Parameter gezeigt.\n\n";
}

$plans = plan_list(true);
$done = 0;
$created = 0;
$errors = 0;

foreach ($plans as $plan) {
    $code = (string)$plan['code'];
    if ($onlyPlan !== '' && $code !== $onlyPlan) {
        continue;
    }
    $bookable = (int)($plan['active'] ?? 0) === 1 && (int)($plan['public_visible'] ?? 0) === 1;
    if (!$bookable && $onlyPlan === '') {
        continue; // nur buchbare Tarife brauchen einen Stripe-Preis
    }
    $done++;
    $lookup = billing_plan_lookup_key($code);
    printf("Tarif %s (%s): %s je %d Tage, lookup_key %s\n", $code, (string)$plan['name'],
        billing_format_cents((int)$plan['price_cents']), (int)$plan['period_days'], $lookup);

    if (!empty($plan['stripe_price_id'])) {
        printf("  bereits hinterlegt: %s (keine Anlage; Prüfung über bin/billing-check.php)\n\n", (string)$plan['stripe_price_id']);
        continue;
    }
    if ($client === null) {
        $params = billing_setup_price_params($plan, '<neues Produkt>');
        printf("  würde anlegen: Produkt \"%s\", Preis %s\n\n", billing_setup_product_params($plan)['name'], json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        continue;
    }

    try {
        // 1. Gibt es den Preis schon (Wiederholung des Aufrufs, oder von Hand angelegt)?
        $found = $client->call('GET', '/prices', ['lookup_keys' => [$lookup], 'limit' => 1, 'expand' => ['data.product']]);
        $existing = (array)($found['data'][0] ?? []);
        if ($existing) {
            printf("  Preis mit diesem lookup_key existiert bereits in Stripe: %s\n", (string)$existing['id']);
            $check = billing_check_price($existing, $plan);
            foreach ($check['errors'] as $l) {
                printf("  ABWEICHUNG: %s\n", $l);
            }
            if ($check['errors']) {
                printf("  Preis-ID wird NICHT eingetragen, solange sie nicht zum Tarif passt.\n\n");
                $errors++;
                continue;
            }
            if ($apply) {
                billing_setup_store_price_id($code, (string)$existing['id']);
                printf("  Preis-ID in plans eingetragen.\n\n");
            } else {
                printf("  Trockenlauf: würde diese Preis-ID in plans eintragen.\n\n");
            }
            continue;
        }

        // 2. Produkt suchen (Metadatensuche) oder anlegen.
        $productId = '';
        try {
            $search = $client->call('GET', '/products/search', ['query' => sprintf("active:'true' AND metadata['lexsepa_plan']:'%s'", $code), 'limit' => 1]);
            $productId = (string)($search['data'][0]['id'] ?? '');
        } catch (Throwable $e) {
            printf("  Hinweis: Produktsuche nicht möglich (%s), es wird ein neues Produkt angelegt.\n", $e->getMessage());
        }
        $pParams = billing_setup_product_params($plan);
        if ($productId !== '') {
            printf("  Produkt vorhanden: %s\n", $productId);
        } elseif ($apply) {
            $prod = $client->call('POST', '/products', $pParams);
            $productId = (string)$prod['id'];
            $created++;
            printf("  Produkt angelegt: %s (\"%s\")\n", $productId, $pParams['name']);
        } else {
            printf("  würde Produkt anlegen: \"%s\"\n", $pParams['name']);
            $productId = '<neues Produkt>';
        }

        // 3. Preis anlegen.
        $priceParams = billing_setup_price_params($plan, $productId);
        if (!$apply) {
            printf("  würde Preis anlegen: %s\n\n", json_encode($priceParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            continue;
        }
        $price = $client->call('POST', '/prices', $priceParams);
        $created++;
        printf("  Preis angelegt: %s (%s, tax_behavior %s)\n", (string)$price['id'],
            billing_format_cents((int)$price['unit_amount']), (string)($price['tax_behavior'] ?? '?'));
        $post = billing_check_price($price, $plan);
        foreach ($post['errors'] as $l) {
            printf("  ABWEICHUNG nach der Anlage: %s\n", $l);
            $errors++;
        }
        if (!$post['errors']) {
            billing_setup_store_price_id($code, (string)$price['id']);
            printf("  Preis-ID in plans eingetragen.\n\n");
        }
    } catch (Throwable $e) {
        $errors++;
        printf("  FEHLER: %s\n\n", $e->getMessage());
    }
}

if ($done === 0) {
    echo "Kein passender Tarif gefunden (buchbar heißt active=1 und public_visible=1).\n";
}
printf("%d Tarif(e) betrachtet, %d Stripe-Objekt(e) angelegt, %d Abweichung(en)/Fehler.\n", $done, $created, $errors);
if (!$apply) {
    echo "Trockenlauf beendet. Für die Anlage: --apply (mit Live-Schlüssel zusätzlich --live-bestaetigt).\n";
} else {
    echo "Zur Kontrolle: php bin/billing-check.php\n";
}
exit($errors ? 1 : 0);

/**
 * Preis-ID eines Tarifs speichern, den Tarif-Cache leeren und den Vorgang protokollieren.
 * Betreiberebene ohne Firma, deshalb audit_log mit tenant_id null (wie admin.php bei Tarifänderungen).
 */
function billing_setup_store_price_id(string $planCode, string $priceId): void
{
    db()->prepare('UPDATE plans SET stripe_price_id = ? WHERE code = ?')->execute([$priceId, $planCode]);
    plan_get(''); // Cache leeren (siehe app/plans.php)
    audit_log(null, ['email' => 'cli:billing-setup-stripe'], 'admin_plan_changed', 'plan', $planCode,
        ['aenderungen' => ['stripe_price_id' => ['alt' => null, 'neu' => $priceId]]]);
    app_log('warning', 'Stripe-Preis-ID eines Tarifs gesetzt', ['plan' => $planCode, 'price_id' => $priceId, 'source' => 'bin/billing-setup-stripe.php']);
}
