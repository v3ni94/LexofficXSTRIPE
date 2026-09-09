<?php
/**
 * Legt für die buchbaren Tarife Produkt und Preis im Stripe-Konto der Plattform an (Müller Holding AG)
 * und trägt die Preis-ID in die Tabelle "plans" ein.
 *
 *   php bin/billing-setup-stripe.php                  Trockenlauf: zeigt nur, was angelegt würde
 *   php bin/billing-setup-stripe.php --apply          legt an (Testschlüssel)
 *   php bin/billing-setup-stripe.php --apply --live-bestaetigt   legt an, auch mit Live-Schlüssel
 *   php bin/billing-setup-stripe.php --tarif=code     nur diesen Tarif (auch nicht oeffentliche Tarife)
 *   php bin/billing-setup-stripe.php --tarif=code --preis-neu --apply
 *                                                     Ersatzpreis nach einer Preisaenderung anlegen
 *
 * Wiederholbar: Vor jeder Anlage wird über den lookup_key (lexsepa_<tarifcode>) geprüft, ob der Preis
 * bereits existiert; dann wird er nur verwendet, nicht neu erzeugt. Beträge und Perioden stammen
 * ausschließlich aus der Tabelle "plans"; dieses Werkzeug erfindet keine Preise. Bestehende Stripe-Preise
 * werden nie geändert (in Stripe sind Betrag und Intervall eines Preises unveränderlich): Ein Tarif mit
 * anderem Betrag braucht einen neuen Preis, den bin/billing-check.php dann als Abweichung meldet.
 *
 * Preisaenderung (--preis-neu, nur zusammen mit --tarif): Weicht der hinterlegte Stripe-Preis in Betrag,
 * Periode oder Waehrung vom Tarif ab, wird auf demselben Stripe-Produkt ein NEUER Preis mit dem Betrag aus
 * der Tabelle "plans" angelegt, der lookup_key vom alten Preis uebernommen (transfer_lookup_key), der alte
 * Preis archiviert und die neue Preis-ID in "plans" eingetragen. Wirkung: Neue Bestellungen laufen ab
 * sofort ueber den neuen Betrag. LAUFENDE Abonnements behalten den alten Preis, bis sie in Stripe oder
 * ueber einen Tarifwechsel in der Anwendung umgestellt werden; das ist eine kaufmaennische Entscheidung
 * (Preisanpassung gegenueber Bestandskunden) und geschieht bewusst nicht automatisch.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/billing.php';
require_once dirname(__DIR__) . '/app/billing_setup.php';

$opts = cli_opts($argv);
$apply = isset($opts['apply']);
$onlyPlan = isset($opts['tarif']) && is_string($opts['tarif']) ? (string)$opts['tarif'] : '';
$replace = isset($opts['preis-neu']);
if ($replace && $onlyPlan === '') {
    fwrite(STDERR, "Abbruch: --preis-neu gilt immer genau einem Tarif und verlangt --tarif=CODE.\n");
    exit(2);
}
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

// Bewusst NICHT billing_client(): Produkt und Preis müssen VOR dem Scharfschalten angelegt werden,
// billing_client() verlangt aber billing.enabled. Der Trockenlauf funktioniert auch ohne Schlüssel.
$client = billing_setup_client($b);
if ($client === null) {
    if ($apply) {
        fwrite(STDERR, "Abbruch: kein brauchbarer Stripe-Geheimschlüssel in config billing.stripe_secret_key.\n");
        exit(2);
    }
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
        if (!$replace) {
            printf("  bereits hinterlegt: %s (keine Anlage; Prüfung über bin/billing-check.php)\n", (string)$plan['stripe_price_id']);
            printf("  Betrag oder Periode geändert? Ersatzpreis anlegen mit --tarif=%s --preis-neu\n\n", $code);
            continue;
        }
        if ($client === null) {
            printf("  Trockenlauf ohne Schlüssel: Ersatzpreis %s wäre auf demselben Produkt anzulegen.\n\n",
                billing_format_cents((int)$plan['price_cents']));
            continue;
        }
        try {
            $errors += billing_setup_replace_price($client, $plan, (string)$plan['stripe_price_id'], $apply, $created);
        } catch (Throwable $e) {
            $errors++;
            printf("  FEHLER: %s\n\n", $e->getMessage());
        }
        continue;
    }
    if ($replace) {
        printf("  Kein Stripe-Preis hinterlegt: --preis-neu ist hier gegenstandslos, es wird regulär angelegt.\n");
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

/**
 * Ersatzpreis nach einer Preisaenderung anlegen (siehe Kopfkommentar, --preis-neu).
 * Reihenfolge bewusst: neuen Preis MIT transfer_lookup_key anlegen, erst danach den alten archivieren und
 * die Preis-ID eintragen. Bricht ein Schritt ab, bleibt der alte, funktionierende Preis eingetragen.
 * @return int Anzahl Fehler
 */
function billing_setup_replace_price(object $client, array $plan, string $oldId, bool $apply, int &$created): int
{
    $code = (string)$plan['code'];
    $old = (array)$client->call('GET', '/prices/' . rawurlencode($oldId), ['expand' => ['product']]);
    $product = (array)($old['product'] ?? []);
    $productId = (string)($product['id'] ?? '');
    printf("  hinterlegt: %s (%s, Produkt \"%s\")\n", $oldId,
        isset($old['unit_amount']) ? billing_format_cents((int)$old['unit_amount']) : 'ohne festen Betrag',
        (string)($product['name'] ?? '?'));

    if (!billing_setup_price_needs_replacement($old, $plan)) {
        $check = billing_check_price($old, $plan);
        foreach ($check['errors'] as $l) {
            printf("  ABWEICHUNG: %s\n", $l);
        }
        printf("  Betrag, Periode und Währung stimmen bereits mit dem Tarif überein: kein Ersatzpreis nötig.\n\n");
        return $check['errors'] ? 1 : 0;
    }
    if ($productId === '') {
        printf("  FEHLER: Zum hinterlegten Preis ist kein Produkt lesbar. Preis-ID im Adminbereich prüfen.\n\n");
        return 1;
    }

    $params = billing_setup_price_params($plan, $productId, true);
    if (!$apply) {
        printf("  würde Ersatzpreis anlegen: %s\n", json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        printf("  würde danach %s archivieren und die neue Preis-ID in plans eintragen.\n\n", $oldId);
        return 0;
    }

    $new = (array)$client->call('POST', '/prices', $params);
    $created++;
    printf("  Ersatzpreis angelegt: %s (%s)\n", (string)$new['id'], billing_format_cents((int)$new['unit_amount']));
    $post = billing_check_price($new, $plan);
    if ($post['errors']) {
        foreach ($post['errors'] as $l) {
            printf("  ABWEICHUNG nach der Anlage: %s\n", $l);
        }
        printf("  Der alte Preis bleibt eingetragen und aktiv. Bitte den neuen Preis in Stripe prüfen.\n\n");
        return count($post['errors']);
    }
    billing_setup_store_price_id($code, (string)$new['id']);
    printf("  Preis-ID in plans eingetragen.\n");
    try {
        $client->call('POST', '/prices/' . rawurlencode($oldId), ['active' => 'false']);
        printf("  Alter Preis %s archiviert.\n", $oldId);
    } catch (Throwable $e) {
        printf("  HINWEIS: Alter Preis %s konnte nicht archiviert werden (%s). Das ist unkritisch, er wird nicht mehr verwendet.\n",
            $oldId, $e->getMessage());
    }
    printf("  WICHTIG: Neue Bestellungen laufen über den neuen Betrag. Laufende Abonnements behalten den alten\n");
    printf("           Preis, bis sie in Stripe oder über einen Tarifwechsel umgestellt werden.\n\n");
    return 0;
}
