<?php
/**
 * Regressionstest der Inbetriebnahme-Logik der Plattform-Abrechnung (php-ionos/app/billing_setup.php),
 * ohne Stripe-Konto, ohne Netz und ohne Datenbank: geprüft werden die reinen Funktionen, die
 * bin/billing-check.php (nur lesend) und bin/billing-setup-stripe.php (Anlage) verwenden.
 *
 * Hintergrund: Vor dem Scharfschalten des Abonnements muss belegt sein, dass ein in Stripe angelegter
 * Preis wirklich zum Tarif der Tabelle "plans" passt (Betrag, Währung, 28-Tage-Periode, Nettopreis) und
 * dass der Webhook alle Ereignisse abonniert, die app/billing.php verarbeitet. Ein falscher Preis würde
 * echtes Geld betreffen (zu wenig eingezogen oder Umsatzsteuer aus dem Netto herausgerechnet).
 *
 * Aufruf: php tools/billing-setup-check.php     Exit 0 = alle Fälle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/php-ionos/app/billing_setup.php';

$pass = 0;
$fail = 0;
function ok(string $t): void { global $pass; $pass++; echo "  OK    $t\n"; }
function bad(string $t): void { global $fail; $fail++; echo "  FAIL  $t\n"; }
/** Prüft, dass genau die erwartete Anzahl Fehler auftrat und eine Meldung den Suchtext enthält. */
function expect(array $r, int $errors, string $needle, string $title): void
{
    $found = $needle === '' || (bool)array_filter($r['errors'], fn($l) => stripos($l, $needle) !== false);
    if (count($r['errors']) === $errors && $found) {
        ok($title . ' (' . count($r['errors']) . ' Fehler)');
    } else {
        bad($title . ': ' . count($r['errors']) . ' Fehler, erwartet ' . $errors
            . ($needle !== '' ? ", Text \"$needle\"" : '') . '; gemeldet: ' . implode(' | ', $r['errors']));
    }
}

// Tarif wie in der ausgelieferten Tabelle "plans": UNLIMITED START, 25,00 EUR netto je 28 Tage.
$plan = ['code' => 'unlimited_start', 'name' => 'UNLIMITED START', 'price_cents' => 2500, 'period_days' => 28,
         'active' => 1, 'public_visible' => 1, 'stripe_price_id' => 'price_123'];
$goodPrice = [
    'object' => 'price', 'id' => 'price_123', 'active' => true, 'currency' => 'eur', 'unit_amount' => 2500,
    'type' => 'recurring', 'tax_behavior' => 'exclusive', 'lookup_key' => 'lexsepa_unlimited_start',
    'recurring' => ['interval' => 'day', 'interval_count' => 28, 'usage_type' => 'licensed'],
];

echo "A) Preisprüfung gegen den Tarif\n";
expect(billing_check_price($goodPrice, $plan), 0, '', 'passender Preis wird akzeptiert');
expect(billing_check_price(['unit_amount' => 2000] + $goodPrice, $plan), 1, '20,00 EUR', 'abweichender Betrag wird erkannt');
expect(billing_check_price(['unit_amount' => 5000] + $goodPrice, $plan), 1, '50,00 EUR', 'zu hoher Betrag wird erkannt');
expect(billing_check_price(['currency' => 'usd'] + $goodPrice, $plan), 1, 'USD', 'falsche Währung wird erkannt');
expect(billing_check_price(['active' => false] + $goodPrice, $plan), 1, 'archiviert', 'archivierter Preis wird erkannt');
expect(billing_check_price(['tax_behavior' => 'inclusive'] + $goodPrice, $plan), 1, 'Bruttopreis', 'Bruttopreis (inclusive) wird als Fehler erkannt');
expect(billing_check_price(['recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']] + $goodPrice, $plan),
    1, 'Monats', 'Monatsintervall statt 28 Tage wird erkannt');
expect(billing_check_price(['recurring' => ['interval' => 'day', 'interval_count' => 30, 'usage_type' => 'licensed']] + $goodPrice, $plan),
    1, '30 Tage', 'falsche Tageszahl wird erkannt');
expect(billing_check_price(['recurring' => ['interval' => 'week', 'interval_count' => 4, 'usage_type' => 'licensed']] + $goodPrice, $plan),
    0, '', 'vier Wochen gelten als 28 Tage');
expect(billing_check_price(['recurring' => ['interval' => 'day', 'interval_count' => 28, 'usage_type' => 'metered']] + $goodPrice, $plan),
    1, 'Verbrauch', 'Verbrauchspreis wird erkannt');
expect(billing_check_price(['type' => 'one_time', 'recurring' => null] + $goodPrice, $plan), 1, 'kein Abonnementpreis', 'Einmalpreis wird erkannt');
$noAmount = $goodPrice; $noAmount['unit_amount'] = null;
expect(billing_check_price($noAmount, $plan), 1, 'keinen festen Betrag', 'Preis ohne festen Betrag wird erkannt');
expect(billing_check_price(['object' => 'product'] + $goodPrice, $plan), 1, 'kein Preis', 'Produkt-ID statt Preis-ID wird erkannt');
$r = billing_check_price(['tax_behavior' => 'unspecified'] + $goodPrice, $plan);
count($r['warnings']) === 1 && !$r['errors'] ? ok('tax_behavior unspecified ist nur eine Warnung') : bad('unspecified falsch bewertet');
$r = billing_check_price(['lookup_key' => 'anderer_key'] + $goodPrice, $plan);
!$r['errors'] && count($r['info']) === 1 ? ok('fremder lookup_key ist nur ein Hinweis') : bad('lookup_key falsch bewertet');

echo "\nB) Parameter für die Anlage stammen aus dem Tarif\n";
$pp = billing_setup_price_params($plan, 'prod_1');
$pp['unit_amount'] === 2500 && $pp['currency'] === 'eur' && $pp['tax_behavior'] === 'exclusive'
    && $pp['recurring'] === ['interval' => 'day', 'interval_count' => 28, 'usage_type' => 'licensed']
    && $pp['lookup_key'] === 'lexsepa_unlimited_start' && $pp['product'] === 'prod_1'
    ? ok('Preisparameter: 2500 Cent, EUR, exclusive, 28 Tage, lookup_key, Produkt')
    : bad('Preisparameter falsch: ' . json_encode($pp, JSON_UNESCAPED_UNICODE));
billing_setup_product_params($plan)['name'] === 'SmartEinzug UNLIMITED START'
    ? ok('Produktname aus dem Tarifnamen gebildet') : bad('Produktname falsch');
$angelegt = billing_check_price(array_merge($goodPrice, ['unit_amount' => $pp['unit_amount'], 'recurring' => $pp['recurring'],
    'tax_behavior' => $pp['tax_behavior'], 'currency' => $pp['currency'], 'lookup_key' => $pp['lookup_key']]), $plan);
!$angelegt['errors'] ? ok('ein mit diesen Parametern angelegter Preis besteht die Prüfung') : bad('Anlage und Prüfung widersprechen sich: ' . implode(' | ', $angelegt['errors']));
try {
    billing_setup_price_params(['code' => 'x', 'name' => 'X', 'price_cents' => 2500, 'period_days' => 0], 'prod_1');
    bad('Tarif ohne Periode wurde akzeptiert');
} catch (InvalidArgumentException $e) { ok('Tarif ohne period_days wird abgelehnt'); }
try {
    billing_setup_price_params(['code' => 'x', 'name' => 'X', 'price_cents' => 0, 'period_days' => 28], 'prod_1');
    bad('Tarif ohne Betrag wurde akzeptiert');
} catch (InvalidArgumentException $e) { ok('Tarif ohne price_cents wird abgelehnt'); }

echo "\nC) Webhook-Prüfung\n";
$url = 'https://app.smart-einzug.de/billing-webhook.php';
billing_expected_webhook_url('https://app.smart-einzug.de') === $url ? ok('erwartete Webhook-Adresse') : bad('Webhook-Adresse falsch');
$full = [['url' => $url, 'status' => 'enabled', 'enabled_events' => BILLING_REQUIRED_WEBHOOK_EVENTS]];
expect(billing_check_webhook($full, $url), 0, '', 'vollständiger Endpunkt wird akzeptiert');
expect(billing_check_webhook([], $url), 1, 'Kein Webhook-Endpunkt', 'fehlender Endpunkt wird erkannt');
expect(billing_check_webhook([['url' => 'https://app.smart-einzug.de/stripe-webhook.php', 'status' => 'enabled',
    'enabled_events' => BILLING_REQUIRED_WEBHOOK_EVENTS]], $url), 1, 'Kein Webhook-Endpunkt', 'Endpunkt der Firmen-Webhooks zählt nicht');
expect(billing_check_webhook([['url' => $url, 'status' => 'disabled', 'enabled_events' => BILLING_REQUIRED_WEBHOOK_EVENTS]], $url),
    1, 'deaktiviert', 'deaktivierter Endpunkt wird erkannt');
$partial = [['url' => $url, 'status' => 'enabled', 'enabled_events' => ['checkout.session.completed']]];
$r = billing_check_webhook($partial, $url);
count($r['errors']) === 1 && str_contains($r['errors'][0], 'invoice.payment_failed') && str_contains($r['errors'][0], 'customer.subscription.deleted')
    ? ok('fehlende Ereignisse werden einzeln benannt') : bad('fehlende Ereignisse falsch gemeldet: ' . implode(' | ', $r['errors']));
expect(billing_check_webhook([['url' => $url, 'status' => 'enabled', 'enabled_events' => ['*']]], $url), 0, '', 'Abonnement aller Ereignisse (*) genügt');
expect(billing_check_webhook($full, ''), 1, 'Basisadresse', 'fehlende Basisadresse wird erkannt');
count(BILLING_REQUIRED_WEBHOOK_EVENTS) === 5 ? ok('fünf benötigte Ereignisse definiert') : bad('Ereignisliste unerwartet');

echo "\nD) Konfigurationsprüfung\n";
$base = 'https://app.smart-einzug.de';
$good = ['enabled' => true, 'stripe_secret_key' => 'sk_live_' . str_repeat('x', 24), 'stripe_webhook_secret' => 'whsec_' . str_repeat('y', 24), 'automatic_tax' => true, 'vat_rate_percent' => 19];
$r = billing_check_config($good, $base);
!$r['errors'] && $r['mode'] === 'live' ? ok('vollständige Konfiguration, Betriebsart live erkannt') : bad('gute Konfiguration bemängelt: ' . implode(' | ', $r['errors']));
billing_check_config(['stripe_secret_key' => 'sk_test_abc123456789'] + $good, $base)['mode'] === 'test' ? ok('Testschlüssel erkannt') : bad('Testschlüssel nicht erkannt');
expect(billing_check_config(['stripe_secret_key' => ''] + $good, $base), 1, 'stripe_secret_key fehlt', 'fehlender Schlüssel');
$r = billing_check_config(['stripe_secret_key' => 'pk_live_' . str_repeat('z', 24)] + $good, $base);
count($r['errors']) === 2 ? ok('öffentlicher Schlüssel: zwei Fehler (kein Geheimschlüssel, pk_)') : bad('pk_-Schlüssel falsch bewertet: ' . implode(' | ', $r['errors']));
expect(billing_check_config(['stripe_webhook_secret' => ''] + $good, $base), 1, 'webhook_secret fehlt', 'fehlendes Signaturgeheimnis');
expect(billing_check_config(['stripe_webhook_secret' => 'sk_live_falsch_hier_eingetragen'] + $good, $base), 1, 'whsec_', 'falsches Signaturgeheimnis');
expect(billing_check_config($good, ''), 1, 'app_base_url fehlt', 'fehlende Basisadresse');
expect(billing_check_config($good, 'http://app.smart-einzug.de'), 1, 'nicht HTTPS', 'Basisadresse ohne HTTPS');
$r = billing_check_config(['enabled' => false] + $good, $base);
!$r['errors'] && (bool)array_filter($r['warnings'], fn($l) => str_contains($l, 'nicht scharf geschaltet')) ? ok('nicht freigeschaltet ist kein Fehler, sondern eine Warnung') : bad('enabled=false falsch bewertet');
$r = billing_check_config(['automatic_tax' => false] + $good, $base);
(bool)array_filter($r['warnings'], fn($l) => str_contains($l, 'automatic_tax')) ? ok('abgeschaltete Steuerautomatik warnt') : bad('automatic_tax=false ohne Warnung');

echo "\nE) Hilfsfunktionen\n";
billing_mask_key('sk_live_1234567890abcdefghij') === 'sk_live_......ghij' ? ok('Schlüssel maskiert (Anfang, Ende, kein Geheimnis)') : bad('Maskierung falsch: ' . billing_mask_key('sk_live_1234567890abcdefghij'));
!str_contains(billing_mask_key('sk_live_1234567890abcdefghij'), '567890') ? ok('maskierter Schlüssel enthält den Mittelteil nicht') : bad('Maskierung gibt Geheimnis preis');
billing_mask_key('') === '(leer)' ? ok('leerer Schlüssel') : bad('leerer Schlüssel falsch');
billing_format_cents(2500) === '25,00 EUR' && billing_format_cents(123456) === '1.234,56 EUR' ? ok('Betragsformat 1.234,56 EUR') : bad('Betragsformat falsch');
billing_plan_lookup_key('UNLIMITED start') === 'lexsepa_unlimitedstart' ? ok('lookup_key normalisiert') : bad('lookup_key falsch: ' . billing_plan_lookup_key('UNLIMITED start'));
billing_recurring_days(['interval' => 'day', 'interval_count' => 28]) === 28
    && billing_recurring_days(['interval' => 'week', 'interval_count' => 4]) === 28
    && billing_recurring_days(['interval' => 'month', 'interval_count' => 1]) === null
    && billing_recurring_days(['interval' => 'day', 'interval_count' => 0]) === null
    ? ok('Umrechnung des Intervalls in Tage') : bad('Intervallumrechnung falsch');
$m = billing_merge_findings(['errors' => ['a'], 'warnings' => ['b']], ['errors' => ['c'], 'info' => ['d']]);
$m['errors'] === ['a', 'c'] && $m['warnings'] === ['b'] && $m['info'] === ['d'] ? ok('Ergebnisse werden zusammengeführt') : bad('Zusammenführung falsch');

echo "\nF) Client der Werkzeuge ist unabhängig vom Schalter billing.enabled\n";
// Die Prüfung und die Anlage der Artikel finden VOR dem Scharfschalten statt. billing_client()
// (app/billing.php) verweigert dann jeden Aufruf; billing_setup_client() darf das nicht.
billing_setup_client(['stripe_secret_key' => '']) === null ? ok('ohne Schlüssel kein Client') : bad('leerer Schlüssel liefert einen Client');
billing_setup_client(['stripe_secret_key' => 'pk_live_' . str_repeat('x', 24)]) === null ? ok('öffentlicher Schlüssel liefert keinen Client') : bad('pk_-Schlüssel liefert einen Client');
$GLOBALS['lexsepa_billing_client_factory'] = static function () { return new StripeClient('sk_test_' . str_repeat('x', 24)); };
require_once $root . '/php-ionos/app/stripe.php';
$c1 = billing_setup_client(['enabled' => false, 'stripe_secret_key' => 'sk_live_' . str_repeat('x', 24)]);
$c1 instanceof StripeClient ? ok('mit Schlüssel und enabled=false wird ein Client geliefert (Prüfung vor dem Scharfschalten)') : bad('enabled=false verhindert den Client');
$c2 = billing_setup_client([]);
$c2 instanceof StripeClient ? ok('Testhaken greift wie bei billing_client()') : bad('Testhaken greift nicht');
unset($GLOBALS['lexsepa_billing_client_factory']);
billing_setup_client([]) === null ? ok('ohne Testhaken und ohne Schlüssel wieder null') : bad('Testhaken nicht zurückgesetzt');
// Nur echte Aufrufe zählen, keine Erwähnungen in Kommentaren (dort wird der Unterschied erklärt).
$codeOnly = static function (string $file): string {
    $out = [];
    foreach (explode("\n", (string)file_get_contents($file)) as $line) {
        $s = ltrim($line);
        if ($s === '' || str_starts_with($s, '//') || str_starts_with($s, '*') || str_starts_with($s, '/*')) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
};
$calls = 0;
foreach (['/php-ionos/bin/billing-check.php', '/php-ionos/bin/billing-setup-stripe.php'] as $f) {
    $calls += preg_match_all('/(?<![_a-z])billing_client\s*\(/i', $codeOnly($root . $f));
}
$calls === 0
    ? ok('beide Werkzeuge rufen billing_setup_client() auf, nirgends billing_client()')
    : bad($calls . ' Aufruf(e) von billing_client() in den Werkzeugen: sie scheitern damit vor dem Scharfschalten');

echo "\nF) Übereinstimmung mit dem Code, der die Ereignisse verarbeitet\n";
$billing = file_get_contents($root . '/php-ionos/app/billing.php');
$missing = [];
foreach (BILLING_REQUIRED_WEBHOOK_EVENTS as $ev) {
    if (!str_contains($billing, "'" . $ev . "'")) {
        $missing[] = $ev;
    }
}
$missing ? bad('in app/billing.php nicht behandelt: ' . implode(', ', $missing)) : ok('alle benötigten Ereignisse werden in app/billing.php behandelt');
$hookFile = $root . '/php-ionos' . BILLING_WEBHOOK_PATH;
is_file($hookFile) ? ok('Webhook-Datei vorhanden (' . BILLING_WEBHOOK_PATH . ')') : bad('Webhook-Datei fehlt: ' . $hookFile);
$schema = file_get_contents($root . '/php-ionos/sql/schema.sql');
preg_match("/\('unlimited_start',\s*'UNLIMITED START',\s*(\d+),\s*(\d+)/", $schema, $mm)
    && (int)$mm[1] === 2500 && (int)$mm[2] === 28
    ? ok('Tarif UNLIMITED START im Schema: 2500 Cent je 28 Tage (Grundlage der Preisprüfung)')
    : bad('Tarifzeile im Schema nicht wie erwartet gefunden');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
