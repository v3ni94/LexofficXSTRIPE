<?php
/**
 * Inbetriebnahme und Prüfung der Plattform-Abrechnung (Stripe-Konto der Müller Holding AG).
 *
 * Reine Prüf- und Aufbaulogik ohne Datenbank und ohne Netzzugriff, damit sie in
 * tools/billing-setup-check.php ohne Stripe-Konto geprüft werden kann. Verwendet von
 * bin/billing-check.php (nur lesen) und bin/billing-setup-stripe.php (Produkt und Preis anlegen).
 *
 * Grundsatz: Quelle der Wahrheit für Preis, Periode und Bezeichnung ist ausschließlich die Tabelle
 * "plans" (app/plans.php). Diese Datei erfindet keine Beträge und keine Tarife; sie prüft nur, ob das,
 * was in Stripe liegt, dazu passt, und baut daraus die Parameter für die Anlage.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Vom Webhook (app/billing.php, billing_handle_event) tatsächlich verarbeitete Ereignisse. */
const BILLING_REQUIRED_WEBHOOK_EVENTS = [
    'checkout.session.completed',
    'customer.subscription.created',
    'customer.subscription.updated',
    'customer.subscription.deleted',
    'invoice.payment_failed',
];

/** Pfad des Webhook-Endpunkts der Plattform-Abrechnung (getrennt von stripe-webhook.php der Firmen). */
const BILLING_WEBHOOK_PATH = '/billing-webhook.php';

/**
 * Eindeutiger Wiedererkennungsschlüssel eines Tarifpreises in Stripe (Feld lookup_key). Stripe erlaubt
 * ihn nur einmal je Konto; damit ist die Anlage wiederholbar, ohne einen zweiten Preis zu erzeugen.
 */
function billing_plan_lookup_key(string $planCode): string
{
    return 'lexsepa_' . preg_replace('/[^a-z0-9_]/', '', strtolower($planCode));
}

/** Schlüssel für Protokoll und Anzeige maskieren: Art, erste vier und letzte vier Zeichen. */
function billing_mask_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '(leer)';
    }
    if (strlen($key) <= 12) {
        return substr($key, 0, 3) . str_repeat('.', 6);
    }
    return substr($key, 0, 8) . str_repeat('.', 6) . substr($key, -4);
}

/** Betriebsart eines Stripe-Schlüssels: live | test | unbekannt. */
function billing_key_mode(string $key): string
{
    if (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_')) {
        return 'live';
    }
    if (str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_')) {
        return 'test';
    }
    return 'unbekannt';
}

/**
 * Wiederkehrendes Intervall eines Stripe-Preises in Tagen, sofern in Tagen ausdrückbar.
 * Monats- und Jahresintervalle haben keine feste Tageszahl und liefern deshalb null.
 */
function billing_recurring_days(array $recurring): ?int
{
    $count = (int)($recurring['interval_count'] ?? 0);
    if ($count < 1) {
        return null;
    }
    switch ((string)($recurring['interval'] ?? '')) {
        case 'day':
            return $count;
        case 'week':
            return $count * 7;
        default:
            return null;
    }
}

/**
 * Parameter für das Stripe-Produkt eines Tarifs (POST /products). Der Steuercode bleibt bewusst offen:
 * Stripe verwendet dann den im Konto eingestellten Standard, der im Dashboard gepflegt wird.
 */
function billing_setup_product_params(array $plan): array
{
    return [
        'name'     => 'SmartEinzug ' . (string)$plan['name'],
        'metadata' => ['lexsepa_plan' => (string)$plan['code']],
    ];
}

/**
 * Parameter für den Stripe-Preis eines Tarifs (POST /prices).
 *  - unit_amount und Periode kommen unverändert aus der Tabelle "plans"
 *  - tax_behavior "exclusive": Der Betrag ist ein NETTOpreis, Stripe Tax rechnet die Umsatzsteuer
 *    zusätzlich auf. Mit "inclusive" würde die Steuer aus dem Betrag herausgerechnet, der Nettoerlös
 *    wäre also niedriger als der Tarifpreis.
 *  - lookup_key macht die Anlage wiederholbar (siehe billing_plan_lookup_key)
 */
function billing_setup_price_params(array $plan, string $productId, bool $transferLookupKey = false): array
{
    $days = (int)($plan['period_days'] ?? 0);
    if ($days < 1) {
        throw new InvalidArgumentException('Tarif ' . (string)($plan['code'] ?? '?') . ': period_days fehlt oder ist ungültig.');
    }
    if ((int)($plan['price_cents'] ?? 0) < 1) {
        throw new InvalidArgumentException('Tarif ' . (string)($plan['code'] ?? '?') . ': price_cents fehlt oder ist ungültig.');
    }
    $params = [
        'product'      => $productId,
        'currency'     => 'eur',
        'unit_amount'  => (int)$plan['price_cents'],
        'nickname'     => (string)$plan['name'],
        'lookup_key'   => billing_plan_lookup_key((string)$plan['code']),
        'tax_behavior' => 'exclusive',
        'recurring'    => ['interval' => 'day', 'interval_count' => $days, 'usage_type' => 'licensed'],
        'metadata'     => ['lexsepa_plan' => (string)$plan['code']],
    ];
    if ($transferLookupKey) {
        // Ersatzpreis nach einer Preisaenderung: Der lookup_key ist je Konto eindeutig und muss vom alten
        // Preis uebernommen werden, sonst lehnt Stripe die Anlage ab. Der alte Preis behaelt seinen Betrag
        // (in Stripe unveraenderlich) und rechnet laufende Abonnements weiter ab, bis diese umgestellt sind.
        $params['transfer_lookup_key'] = 'true';
    }
    return $params;
}

/**
 * Weicht ein in Stripe vorhandener Preis so vom Tarif ab, dass er durch einen NEUEN Preis ersetzt werden
 * muss? Das ist genau bei Betrag, Periode und Waehrung der Fall (in Stripe unveraenderliche Felder);
 * ein archivierter oder falsch besteuerter Preis ist ein anderer Fall und wird nicht hier entschieden.
 */
function billing_setup_price_needs_replacement(array $price, array $plan): bool
{
    $amount = array_key_exists('unit_amount', $price) && $price['unit_amount'] !== null ? (int)$price['unit_amount'] : null;
    if ($amount === null || $amount !== (int)($plan['price_cents'] ?? 0)) {
        return true;
    }
    if (strtolower((string)($price['currency'] ?? '')) !== 'eur') {
        return true;
    }
    $days = is_array($price['recurring'] ?? null) ? billing_recurring_days((array)$price['recurring']) : null;
    return $days === null || $days !== (int)($plan['period_days'] ?? 0);
}

/**
 * Einen aus Stripe gelesenen Preis gegen den Tarif prüfen.
 * @return array{errors:string[],warnings:string[],info:string[]}
 */
function billing_check_price(array $price, array $plan): array
{
    $r = ['errors' => [], 'warnings' => [], 'info' => []];
    $code = (string)($plan['code'] ?? '?');

    if (($price['object'] ?? 'price') !== 'price') {
        $r['errors'][] = sprintf('Tarif %s: hinterlegte Kennung ist kein Preis (object=%s).', $code, (string)($price['object'] ?? '?'));
        return $r;
    }
    if (empty($price['active'])) {
        $r['errors'][] = sprintf('Tarif %s: Preis %s ist in Stripe archiviert (active=false). Checkout schlägt fehl.', $code, (string)($price['id'] ?? '?'));
    }
    $currency = strtolower((string)($price['currency'] ?? ''));
    if ($currency !== 'eur') {
        $r['errors'][] = sprintf('Tarif %s: Preis lautet auf %s, erwartet EUR.', $code, strtoupper($currency !== '' ? $currency : '?'));
    }
    $amount = array_key_exists('unit_amount', $price) && $price['unit_amount'] !== null ? (int)$price['unit_amount'] : null;
    $expected = (int)($plan['price_cents'] ?? 0);
    if ($amount === null) {
        $r['errors'][] = sprintf('Tarif %s: Preis hat keinen festen Betrag (unit_amount fehlt, z.B. Staffelpreis). Nicht unterstützt.', $code);
    } elseif ($amount !== $expected) {
        $r['errors'][] = sprintf('Tarif %s: Stripe berechnet %s, der Tarif nennt %s. Beträge müssen übereinstimmen.',
            $code, billing_format_cents($amount), billing_format_cents($expected));
    }
    if (($price['type'] ?? '') !== 'recurring' || empty($price['recurring'])) {
        $r['errors'][] = sprintf('Tarif %s: Preis ist kein Abonnementpreis (type=%s).', $code, (string)($price['type'] ?? '?'));
    } else {
        $days = billing_recurring_days((array)$price['recurring']);
        $planDays = (int)($plan['period_days'] ?? 0);
        if ($days === null) {
            $r['errors'][] = sprintf('Tarif %s: Preis verwendet das Intervall %s (%s), der Tarif rechnet in Perioden von %d Tagen. Ein Monats- oder Jahresintervall hat keine feste Tageszahl.',
                $code, (string)($price['recurring']['interval'] ?? '?'), 'nicht in Tagen ausdrückbar', $planDays);
        } elseif ($days !== $planDays) {
            $r['errors'][] = sprintf('Tarif %s: Preis läuft über %d Tage, der Tarif über %d Tage.', $code, $days, $planDays);
        }
        $usage = (string)($price['recurring']['usage_type'] ?? 'licensed');
        if ($usage !== 'licensed') {
            $r['errors'][] = sprintf('Tarif %s: Preis rechnet nach Verbrauch (usage_type=%s), erwartet licensed.', $code, $usage);
        }
    }
    $tax = (string)($price['tax_behavior'] ?? 'unspecified');
    if ($tax === 'inclusive') {
        $r['errors'][] = sprintf('Tarif %s: Preis ist als Bruttopreis angelegt (tax_behavior=inclusive). Die Umsatzsteuer würde aus dem Betrag herausgerechnet, der Nettoerlös läge unter dem Tarifpreis. Erwartet: exclusive.', $code);
    } elseif ($tax !== 'exclusive') {
        $r['warnings'][] = sprintf('Tarif %s: tax_behavior ist "%s". Für Nettopreise mit Stripe Tax ist "exclusive" erforderlich; Stripe lässt sich das je Preis nur einmal setzen.', $code, $tax);
    }
    $lookup = (string)($price['lookup_key'] ?? '');
    $expectedLookup = billing_plan_lookup_key($code);
    if ($lookup === '') {
        $r['info'][] = sprintf('Tarif %s: Preis ohne lookup_key. Empfehlung "%s", damit die Anlage wiederholbar bleibt.', $code, $expectedLookup);
    } elseif ($lookup !== $expectedLookup) {
        $r['info'][] = sprintf('Tarif %s: Preis trägt den lookup_key "%s", erwartet würde "%s" (nur Wiedererkennung, kein Fehler).', $code, $lookup, $expectedLookup);
    }
    return $r;
}

/** Betrag in Cent als deutscher Betrag mit Währung. */
function billing_format_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' EUR';
}

/**
 * Webhook-Endpunkte des Kontos gegen den benötigten Endpunkt und die benötigten Ereignisse prüfen.
 * @param array $endpoints Liste aus GET /webhook_endpoints (Feld data)
 * @return array{errors:string[],warnings:string[],info:string[]}
 */
function billing_check_webhook(array $endpoints, string $expectedUrl, array $requiredEvents = BILLING_REQUIRED_WEBHOOK_EVENTS): array
{
    $r = ['errors' => [], 'warnings' => [], 'info' => []];
    if ($expectedUrl === '') {
        $r['errors'][] = 'Basisadresse der Anwendung unbekannt (config app_base_url), der erwartete Webhook-Endpunkt lässt sich nicht bestimmen.';
        return $r;
    }
    $match = null;
    foreach ($endpoints as $ep) {
        if (rtrim((string)($ep['url'] ?? ''), '/') === rtrim($expectedUrl, '/')) {
            $match = $ep;
            break;
        }
    }
    if ($match === null) {
        $r['errors'][] = sprintf('Kein Webhook-Endpunkt für %s im Stripe-Konto. Ohne ihn bleibt ein abgeschlossenes Abonnement in der Anwendung unbekannt (Status pending).', $expectedUrl);
        return $r;
    }
    if ((string)($match['status'] ?? 'enabled') !== 'enabled') {
        $r['errors'][] = sprintf('Webhook-Endpunkt %s ist in Stripe deaktiviert (status=%s).', $expectedUrl, (string)$match['status']);
    }
    $events = array_map('strval', (array)($match['enabled_events'] ?? []));
    if (in_array('*', $events, true)) {
        $r['info'][] = 'Webhook-Endpunkt ist für alle Ereignisse abonniert (*). Die benötigten sind damit enthalten.';
        return $r;
    }
    $missing = array_values(array_diff($requiredEvents, $events));
    if ($missing) {
        $r['errors'][] = sprintf('Webhook-Endpunkt %s abonniert %d benötigte Ereignisse nicht: %s.', $expectedUrl, count($missing), implode(', ', $missing));
    }
    $extra = array_values(array_diff($events, $requiredEvents));
    if ($extra) {
        $r['info'][] = sprintf('Zusätzlich abonnierte Ereignisse (werden ignoriert): %s.', implode(', ', array_slice($extra, 0, 8)));
    }
    return $r;
}

/**
 * Konfiguration der Plattform-Abrechnung prüfen (ohne Netzzugriff).
 * @param array $b config('billing')
 * @return array{errors:string[],warnings:string[],info:string[],mode:string}
 */
function billing_check_config(array $b, string $baseUrl): array
{
    $r = ['errors' => [], 'warnings' => [], 'info' => [], 'mode' => 'unbekannt'];
    $key = (string)($b['stripe_secret_key'] ?? '');
    $r['mode'] = billing_key_mode($key);

    if ($key === '') {
        $r['errors'][] = 'config billing.stripe_secret_key fehlt. Ohne Schlüssel des Plattformkontos ist keine Abrechnung möglich.';
    } elseif ($r['mode'] === 'unbekannt') {
        $r['errors'][] = sprintf('config billing.stripe_secret_key (%s) ist kein Stripe-Geheimschlüssel (erwartet sk_live_, sk_test_, rk_live_ oder rk_test_).', billing_mask_key($key));
    } else {
        $r['info'][] = sprintf('Plattform-Schlüssel: %s (Betriebsart %s).', billing_mask_key($key), $r['mode']);
    }
    if (str_starts_with($key, 'pk_')) {
        $r['errors'][] = 'In billing.stripe_secret_key steht ein öffentlicher Schlüssel (pk_). Erforderlich ist der Geheimschlüssel.';
    }
    $whsec = (string)($b['stripe_webhook_secret'] ?? '');
    if ($whsec === '') {
        $r['errors'][] = 'config billing.stripe_webhook_secret fehlt. billing-webhook.php weist dann jede Meldung von Stripe ab (Signaturprüfung).';
    } elseif (!str_starts_with($whsec, 'whsec_')) {
        $r['errors'][] = sprintf('config billing.stripe_webhook_secret (%s) sieht nicht wie ein Stripe-Signaturgeheimnis aus (erwartet whsec_).', billing_mask_key($whsec));
    }
    if (empty($b['enabled'])) {
        $r['warnings'][] = 'config billing.enabled ist nicht gesetzt: Die Abrechnung ist noch nicht scharf geschaltet (Abo-Seite und Hinweisbalken bleiben aus, keine Firma wird gesperrt).';
    }
    if ($baseUrl === '') {
        $r['errors'][] = 'config app_base_url fehlt. Checkout-Rückkehr, Kundenportal und Webhook-Adresse lassen sich nicht bilden.';
    } elseif (!str_starts_with($baseUrl, 'https://')) {
        $r['errors'][] = sprintf('config app_base_url (%s) ist nicht HTTPS. Stripe verlangt für Webhook und Rückkehradressen HTTPS.', $baseUrl);
    }
    if (!array_key_exists('automatic_tax', $b) || !empty($b['automatic_tax'])) {
        $r['info'][] = 'Umsatzsteuer über Stripe Tax (automatic_tax aktiv): Preise sind Nettopreise, Stripe ermittelt den Satz aus der Rechnungsadresse.';
    } else {
        $r['warnings'][] = 'automatic_tax ist abgeschaltet: Stripe weist dann keine Umsatzsteuer aus. Nur zulässig, wenn die Steuer anderweitig korrekt abgebildet wird (Steuerberater einbeziehen).';
    }
    $vat = (float)($b['vat_rate_percent'] ?? 19);
    if ($vat <= 0 || $vat > 30) {
        $r['warnings'][] = sprintf('vat_rate_percent ist %s. Der Wert dient nur der Bruttoanzeige in der Anwendung.', (string)$vat);
    }
    return $r;
}

/**
 * Stripe-Client der Werkzeuge, UNABHAENGIG von config('billing')['enabled'].
 *
 * billing_client() (app/billing.php) verweigert bewusst jeden Aufruf, solange die Abrechnung nicht
 * freigeschaltet ist: Im laufenden Betrieb darf ohne Freischaltung nichts abgerechnet werden. Prüfung und
 * Anlage finden aber genau VOR dem Scharfschalten statt und brauchen deshalb einen Client, sobald ein
 * brauchbarer Geheimschlüssel vorliegt. Liefert null, wenn der Schlüssel fehlt oder keiner ist.
 * Voraussetzung: app/stripe.php ist geladen (beide Werkzeuge laden app/billing.php).
 */
function billing_setup_client(array $b): ?StripeClient
{
    // Testhaken (nur CLI, wie billing_client()): Ersatz-Client ohne echte Stripe-Aufrufe.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['lexsepa_billing_client_factory']) && is_callable($GLOBALS['lexsepa_billing_client_factory'])) {
        $c = ($GLOBALS['lexsepa_billing_client_factory'])();
        if ($c instanceof StripeClient) {
            return $c;
        }
    }
    $key = (string)($b['stripe_secret_key'] ?? '');
    if (billing_key_mode($key) === 'unbekannt' || !class_exists('StripeClient')) {
        return null;
    }
    return new StripeClient($key);
}

/** Erwartete Webhook-Adresse der Plattform-Abrechnung. */
function billing_expected_webhook_url(string $baseUrl): string
{
    return $baseUrl === '' ? '' : rtrim($baseUrl, '/') . BILLING_WEBHOOK_PATH;
}

/** Mehrere Prüfergebnisse zusammenfassen. */
function billing_merge_findings(array ...$results): array
{
    $out = ['errors' => [], 'warnings' => [], 'info' => []];
    foreach ($results as $r) {
        foreach (['errors', 'warnings', 'info'] as $k) {
            foreach ((array)($r[$k] ?? []) as $line) {
                $out[$k][] = (string)$line;
            }
        }
    }
    return $out;
}
