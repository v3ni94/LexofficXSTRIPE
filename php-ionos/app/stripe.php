<?php
/**
 * Stripe-API-Client (cURL, ohne Composer-Abhängigkeiten).
 *
 * Wird zweifach verwendet:
 *  1. mit dem Stripe-Schlüssel der jeweiligen Firma für SEPA-Einzüge bei
 *     deren Kunden (Zahlungsmethoden, PaymentIntents),
 *  2. mit dem Plattform-Schlüssel der Müller Holding AG für das Abonnement
 *     der Firmen (Checkout, Billing Portal, Subscriptions), siehe billing.php.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

class StripeException extends RuntimeException
{
    public ?string $stripeCode = null;
    /** true, wenn nicht feststeht, ob Stripe die Anfrage verarbeitet hat (Timeout, Netzwerk, kein JSON). */
    public bool $outcomeUnknown = false;
}

class StripeClient
{
    private const BASE_URL = 'https://api.stripe.com/v1';
    /** Standard-API-Version aller Aufrufe (Antwortformate sind darauf abgestimmt). */
    public const API_VERSION = '2024-06-20';
    /** Version, ab der mandate_options.reference_prefix für SEPA verfügbar ist. */
    public const API_VERSION_MANDATE_PREFIX = '2024-12-18.acacia';

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * @param string $method GET|POST|DELETE
     * @param array  $params Formular-Parameter (verschachtelt erlaubt)
     */
    private function request(string $method, string $endpoint, array $params = [], ?string $apiVersion = null, ?string $idempotencyKey = null): array
    {
        if (function_exists('api_call_gate')) {
            // Circuit Breaker und optionale zentrale Ratenbegrenzung (Auftrag III)
            // Kontingent je Stripe-Konto (API-Schlüssel der Firma bzw. Plattformkonto), dazu Obergrenze insgesamt.
            $q = (array)config('queue', []);
            api_call_gate('stripe', (int)($q['stripe_per_second'] ?? 20), api_scope_for_key($this->secretKey), (int)($q['stripe_global_per_second'] ?? 200));
        }
        $url = self::BASE_URL . $endpoint;
        $ch = curl_init();

        $headers = ['Stripe-Version: ' . ($apiVersion ?? self::API_VERSION)];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            // Stripe führt einen POST mit demselben Schlüssel innerhalb von 24 Stunden
            // nicht erneut aus, sondern liefert die gespeicherte Antwort (Doppelbelastung
            // bei Wiederholung nach Zeitüberschreitung ausgeschlossen).
            $headers[] = 'Idempotency-Key: ' . substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $idempotencyKey) ?? '', 0, 255);
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->secretKey . ':',
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } else {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        $opts[CURLOPT_URL] = $url;
        curl_setopt_array($ch, $opts);

        $t0 = microtime(true);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($body === false) {
            self::monitor('fail', $ms, $err);
            $ex = new StripeException("Verbindungsfehler zu Stripe: $err");
            $ex->outcomeUnknown = true;
            throw $ex;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::monitor('fail', $ms, 'http_' . $status);
            $ex = new StripeException('Ungültige Antwort von Stripe.');
            // Kein JSON (z. B. Gateway-Fehlerseite): Ergebnis des Aufrufs ist unbekannt.
            $ex->outcomeUnknown = $status === 0 || $status >= 500;
            throw $ex;
        }

        // Technische Sicht für das Monitoring: 5xx und 429 sind Störungen der Anbindung, fachliche
        // Ablehnungen (4xx: Karte/Mandat/Parameter) sind kein Ausfall.
        self::monitor($status >= 500 || $status === 429 ? 'fail' : 'ok', $ms, $status >= 500 ? 'http_5xx' : ($status === 429 ? 'throttled' : null));

        if ($status >= 400) {
            $message = $data['error']['message'] ?? "Stripe-Fehler (HTTP $status)";
            $ex = new StripeException($message);
            $ex->stripeCode = $data['error']['code'] ?? null;
            throw $ex;
        }

        return $data;
    }

    /** Monitoring-Ereignis der Stripe-API (Fehler der Diagnose werden verworfen). */
    private static function monitor(string $status, int $ms, ?string $category): void
    {
        try {
            require_once __DIR__ . '/monitor.php';
            $cat = $category !== null ? (str_starts_with($category, 'http_') || $category === 'throttled' ? $category : monitor_category($category)) : null;
            monitor_event('stripe_api', $status, $ms, $cat, 'instrumented', 3600);
            if (function_exists('circuit_failure')) {
                if ($status === 'fail' && $cat !== 'throttled') {
                    circuit_failure('stripe', $cat ?? 'connection');
                } elseif ($status !== 'fail') {
                    circuit_success('stripe');
                }
                // 429 (throttled): Rate-Limit des einzelnen Stripe-Kontos, keine Störung des Anbieters, kein Breaker-Fehler.
            }
        } catch (Throwable $e) {
            // ignorieren
        }
    }

    /** Generischer Aufruf (für Plattform-Abrechnung in billing.php). */
    public function call(string $method, string $endpoint, array $params = []): array
    {
        return $this->request($method, $endpoint, $params);
    }

    /** Verbindungstest: Konto abrufen. */
    public function getAccount(): array
    {
        return $this->request('GET', '/account');
    }

    /** Aktuellen Status eines PaymentIntent abrufen (reiner Lesezugriff). */
    public function getPaymentIntent(string $paymentIntentId): array
    {
        return $this->request('GET', '/payment_intents/' . rawurlencode($paymentIntentId));
    }

    /** Charge abrufen (enthält payment_method_details.sepa_debit.mandate). */
    public function getCharge(string $chargeId): array
    {
        return $this->request('GET', '/charges/' . rawurlencode($chargeId));
    }

    /** Stripe-Mandat abrufen (payment_method_details.sepa_debit.reference = Mandatsreferenz bei Stripe). */
    public function getMandate(string $mandateId): array
    {
        return $this->request('GET', '/mandates/' . rawurlencode($mandateId));
    }

    /** Zahlungsmethode abrufen (sepa_debit.last4, country, bank_code; billing_details.name). */
    public function getPaymentMethod(string $paymentMethodId): array
    {
        return $this->request('GET', '/payment_methods/' . rawurlencode($paymentMethodId));
    }

    /** SetupIntent abrufen (payment_method, mandate nach erfolgreichem Checkout mode=setup). */
    public function getSetupIntent(string $setupIntentId): array
    {
        return $this->request('GET', '/setup_intents/' . rawurlencode($setupIntentId));
    }

    /** Checkout Session abrufen. */
    public function getCheckoutSession(string $sessionId): array
    {
        return $this->request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
    }

    /**
     * PaymentIntents seitenweise auflisten (reiner Lesezugriff), ab einem
     * Zeitpunkt, mit eingebetteter letzter Charge (Erstattung, Rücklastschrift).
     * Für den Einmal-Import bestehender Einzüge aus einer früheren Installation.
     * @return array{data:array,has_more:bool}
     */
    public function listPaymentIntents(int $createdGte, ?string $startingAfter = null, int $limit = 100): array
    {
        $params = ['created' => ['gte' => $createdGte], 'limit' => max(1, min(100, $limit)), 'expand' => ['data.latest_charge']];
        if ($startingAfter !== null && $startingAfter !== '') {
            $params['starting_after'] = $startingAfter;
        }
        $res = $this->request('GET', '/payment_intents', $params);
        return ['data' => (array)($res['data'] ?? []), 'has_more' => !empty($res['has_more'])];
    }

    /**
     * PaymentIntents per Suche finden (Stripe Search API, Index mit bis zu
     * etwa einer Minute Verzögerung). Für die Klärung unbekannter Versuche.
     */
    public function searchPaymentIntents(string $query): array
    {
        return $this->request('GET', '/payment_intents/search', ['query' => $query, 'limit' => 10]);
    }

    /**
     * Stripe Checkout Session im Modus "setup" für ein SEPA-Mandat anlegen.
     * Es wird keine Zahlung ausgelöst; der Kunde gibt seine IBAN bei Stripe
     * ein und bestätigt dort das Mandat.
     */
    public function createSetupCheckoutSession(
        string $customerId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        ?string $customerEmail = null
    ): array {
        $params = [
            'mode'                 => 'setup',
            'payment_method_types' => ['sepa_debit'],
            'customer'             => $customerId,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'locale'               => 'de',
            'metadata'             => $metadata,
            'setup_intent_data'    => ['metadata' => $metadata],
        ];
        return $this->request('POST', '/checkout/sessions', $params);
    }

    /** Kunde per Metadaten suchen, sonst anlegen. */
    public function findOrCreateCustomer(string $name, ?string $email, array $metadata): array
    {
        $tenantId = $metadata['tenant_id'] ?? '';
        $customerId = $metadata['customer_id'] ?? '';

        $query = sprintf(
            "metadata['tenant_id']:'%s' AND metadata['customer_id']:'%s'",
            $tenantId,
            $customerId
        );
        $results = $this->request('GET', '/customers/search', ['query' => $query]);
        if (!empty($results['data'])) {
            return $results['data'][0];
        }

        $params = ['name' => $name, 'metadata' => $metadata];
        if ($email) {
            $params['email'] = $email;
        }
        return $this->request('POST', '/customers', $params);
    }

    public function createSepaPaymentMethod(string $iban, string $name, string $email): array
    {
        return $this->request('POST', '/payment_methods', [
            'type'            => 'sepa_debit',
            'sepa_debit'      => ['iban' => $iban],
            'billing_details' => ['name' => $name, 'email' => $email],
        ]);
    }

    public function attachPaymentMethod(string $paymentMethodId, string $customerId): array
    {
        return $this->request('POST', '/payment_methods/' . rawurlencode($paymentMethodId) . '/attach', [
            'customer' => $customerId,
        ]);
    }

    /**
     * SEPA-Lastschrift auslösen. Optional beginnt die von Stripe erzeugte
     * Mandatsreferenz mit dem Firmenpräfix (mandate_options.reference_prefix,
     * ab API-Version 2024-12-18). Lehnt Stripe das Präfix ab, wird der
     * Einzug ohne Präfix wiederholt, damit kein Einzug daran scheitert.
     */
    public function createPaymentIntent(
        int $amountCents,
        string $customerId,
        string $paymentMethodId,
        string $description,
        array $metadata,
        ?string $mandateReferencePrefix = null,
        ?string $idempotencyKey = null
    ): array {
        $params = [
            'amount'               => $amountCents,
            'currency'             => 'eur',
            'customer'             => $customerId,
            'payment_method'       => $paymentMethodId,
            'payment_method_types' => ['sepa_debit'],
            'confirm'              => 'true',
            'mandate_data'         => [
                'customer_acceptance' => [
                    'type'    => 'offline',
                    // Stripe verlangt bei "offline" keinen weiteren Inhalt
                ],
            ],
            'description'          => $description,
            'metadata'             => $metadata,
        ];

        $prefix = self::normalizeMandatePrefix($mandateReferencePrefix);
        if ($prefix !== null) {
            $withPrefix = $params;
            $withPrefix['payment_method_options'] = [
                'sepa_debit' => ['mandate_options' => ['reference_prefix' => $prefix]],
            ];
            try {
                // Abgeleiteter Schlüssel: Stripe verlangt je Parametersatz einen eigenen Idempotenz-Schlüssel.
                return $this->request('POST', '/payment_intents', $withPrefix, self::API_VERSION_MANDATE_PREFIX,
                    $idempotencyKey !== null ? $idempotencyKey . '-p' : null);
            } catch (StripeException $e) {
                if ($e->stripeCode !== 'invalid_mandate_reference_prefix_format'
                    && !str_contains(strtolower($e->getMessage()), 'reference_prefix')) {
                    throw $e;
                }
                error_log('Stripe lehnt Mandatsreferenz-Präfix "' . $prefix . '" ab, Einzug ohne Präfix: ' . $e->getMessage());
            }
        }

        return $this->request('POST', '/payment_intents', $params, null, $idempotencyKey);
    }

    /**
     * Präfix für Stripe vorbereiten: nur Großbuchstaben und Ziffern, maximal
     * 12 Zeichen, nicht mit "STRIPE" beginnend. Sonst null (kein Präfix).
     */
    public static function normalizeMandatePrefix(?string $prefix): ?string
    {
        if ($prefix === null) {
            return null;
        }
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');
        if ($p === '' || strlen($p) > 12 || str_starts_with($p, 'STRIPE')) {
            return null;
        }
        return $p;
    }
}

/**
 * Stripe-Webhook-Signatur prüfen (Header "Stripe-Signature": t=...,v1=...).
 * Rückgabe true, wenn die Signatur gültig und der Zeitstempel frisch ist.
 */
function stripe_verify_webhook_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $timestamp = (int)$kv[1];
        } elseif ($kv[0] === 'v1') {
            $signatures[] = $kv[1];
        }
    }

    if (!$timestamp || !$signatures) {
        return false;
    }
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}
