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
    private function request(string $method, string $endpoint, array $params = [], ?string $apiVersion = null): array
    {
        $url = self::BASE_URL . $endpoint;
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->secretKey . ':',
            CURLOPT_HTTPHEADER     => ['Stripe-Version: ' . ($apiVersion ?? self::API_VERSION)],
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

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new StripeException("Verbindungsfehler zu Stripe: $err");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new StripeException('Ungültige Antwort von Stripe.');
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? "Stripe-Fehler (HTTP $status)";
            $ex = new StripeException($message);
            $ex->stripeCode = $data['error']['code'] ?? null;
            throw $ex;
        }

        return $data;
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
        ?string $mandateReferencePrefix = null
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
                return $this->request('POST', '/payment_intents', $withPrefix, self::API_VERSION_MANDATE_PREFIX);
            } catch (StripeException $e) {
                if ($e->stripeCode !== 'invalid_mandate_reference_prefix_format'
                    && !str_contains(strtolower($e->getMessage()), 'reference_prefix')) {
                    throw $e;
                }
                error_log('Stripe lehnt Mandatsreferenz-Präfix "' . $prefix . '" ab, Einzug ohne Präfix: ' . $e->getMessage());
            }
        }

        return $this->request('POST', '/payment_intents', $params);
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
