<?php
/**
 * Stripe-API-Client (cURL, ohne Composer-Abhängigkeiten).
 * Umfasst genau die Funktionen, die für SEPA-Lastschriften benötigt werden.
 * Portiert aus stripe_service.py.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

class StripeException extends RuntimeException {}

class StripeClient
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * @param string $method GET|POST
     * @param array  $params Formular-Parameter (verschachtelt erlaubt)
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint;
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $this->secretKey . ':',
            CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        ];

        if ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
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
            throw new StripeException($message);
        }

        return $data;
    }

    /** Verbindungstest: Konto abrufen. */
    public function getAccount(): array
    {
        return $this->request('GET', '/account');
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

    public function createPaymentIntent(
        int $amountCents,
        string $customerId,
        string $paymentMethodId,
        string $description,
        array $metadata
    ): array {
        return $this->request('POST', '/payment_intents', [
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
        ]);
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
