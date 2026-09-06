<?php
/**
 * Lexware Office Public-API-Client (cURL, ohne Composer-Abhängigkeiten).
 *
 * Lexware Office hieß bis 2024 lexoffice; die Public API ist heute unter
 * https://api.lexware.io/v1 dokumentiert. Die frühere Domain
 * api.lexoffice.io funktioniert weiterhin und wird bei Verbindungsfehlern
 * automatisch als Ausweichadresse verwendet. Interne Bezeichner (Klassen,
 * Spaltennamen "lexoffice_*") behalten aus Kompatibilitätsgründen den
 * alten Namen; alle sichtbaren Texte sprechen von "Lexware Office".
 *
 * Drosselung auf < 2 Requests/Sekunde, Retries bei 429 und 5xx.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

class LexofficeException extends RuntimeException {}

class LexofficeClient
{
    public const DEFAULT_BASE_URL = 'https://api.lexware.io/v1';
    public const LEGACY_BASE_URL  = 'https://api.lexoffice.io/v1';
    private const MIN_REQUEST_INTERVAL_US = 600000; // 0,6 s

    private string $apiKey;
    private string $baseUrl;
    private ?string $fallbackUrl;
    private bool $fallbackTried = false;
    private float $lastRequestTime = 0.0;

    /** Messwerte dieser Client-Instanz (Instrumentierung der Synchronisation, keine Inhalte). */
    public int $requestCount = 0;
    public float $requestMs = 0.0;
    public float $throttleMs = 0.0;
    public int $retryCount = 0;

    public function __construct(string $apiKey, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;
        if ($baseUrl === null && function_exists('config')) {
            $baseUrl = (string)config('lexware_api_base_url', self::DEFAULT_BASE_URL);
        }
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
        $this->fallbackUrl = $this->baseUrl === self::LEGACY_BASE_URL ? self::DEFAULT_BASE_URL : self::LEGACY_BASE_URL;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function throttle(): void
    {
        $elapsedUs = (microtime(true) - $this->lastRequestTime) * 1_000_000;
        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            $this->throttleMs += (self::MIN_REQUEST_INTERVAL_US - $elapsedUs) / 1000;
            usleep((int)(self::MIN_REQUEST_INTERVAL_US - $elapsedUs));
        }
    }

    private function request(string $endpoint, array $params = []): array
    {
        $query = $params ? '?' . http_build_query($params) : '';

        $retries429 = 0;
        $retries5xx = 0;

        while (true) {
            $this->throttle();
            $this->lastRequestTime = microtime(true);

            $url = $this->baseUrl . $endpoint . $query;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                ],
            ]);
            $t0 = microtime(true);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $this->requestCount++;
            $this->requestMs += (microtime(true) - $t0) * 1000;
            if ($status === 429 || in_array($status, [500, 502, 503], true)) {
                $this->retryCount++;
            }

            if ($body === false) {
                // Verbindungsfehler (DNS, TLS, Timeout): einmal auf die
                // jeweils andere API-Domain ausweichen.
                if (!$this->fallbackTried && $this->fallbackUrl) {
                    $this->fallbackTried = true;
                    error_log("Lexware Office API: {$this->baseUrl} nicht erreichbar ($err), Ausweich-URL {$this->fallbackUrl} wird versucht.");
                    $this->baseUrl = $this->fallbackUrl;
                    continue;
                }
                throw new LexofficeException("Verbindungsfehler zu Lexware Office: $err");
            }

            if ($status === 200) {
                $data = json_decode($body, true);
                if (!is_array($data)) {
                    throw new LexofficeException('Ungültige Antwort von Lexware Office.');
                }
                return $data;
            }

            if ($status === 401) {
                throw new LexofficeException('Lexware Office API-Key ungültig oder abgelaufen.');
            }

            if ($status === 429) {
                $retries429++;
                if ($retries429 > 3) {
                    throw new LexofficeException('Lexware Office Rate-Limit nach 3 Versuchen überschritten.');
                }
                sleep(2 ** $retries429); // 2, 4, 8 s
                continue;
            }

            if (in_array($status, [500, 502, 503], true)) {
                $retries5xx++;
                if ($retries5xx > 2) {
                    throw new LexofficeException("Lexware Office Serverfehler $status nach Retries.");
                }
                sleep(2 ** $retries5xx);
                continue;
            }

            throw new LexofficeException(
                "Unerwarteter Lexware Office Status $status: " . substr($body, 0, 200)
            );
        }
    }

    /** Verbindungstest */
    public function getProfile(): array
    {
        return $this->request('/profile');
    }

    /** Alle offenen und überfälligen Rechnungs-Voucher über alle Seiten. */
    public function getOpenInvoices(): array
    {
        $vouchers = [];
        foreach (['open', 'overdue'] as $voucherStatus) {
            $page = 0;
            while (true) {
                $data = $this->getInvoiceVouchersPage($voucherStatus, $page);
                foreach ($data['content'] ?? [] as $voucher) {
                    $vouchers[] = $voucher;
                }
                $totalPages = (int)($data['totalPages'] ?? 1);
                $page++;
                if ($page >= $totalPages) {
                    break;
                }
            }
        }
        return $vouchers;
    }

    /**
     * Eine einzelne Seite der Voucherliste abrufen (für die schrittweise
     * Synchronisation, damit ein HTTP-Request nicht durch viele
     * gedrosselte API-Aufrufe das Zeitlimit des Hostings überschreitet).
     */
    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array
    {
        return $this->request('/voucherlist', [
            'voucherType'   => 'invoice',
            'voucherStatus' => $voucherStatus,
            'size'          => 100,
            'page'          => $page,
        ]);
    }

    public function getInvoiceDetail(string $invoiceId): array
    {
        return $this->request('/invoices/' . rawurlencode($invoiceId));
    }

    public function getContact(string $contactId): array
    {
        return $this->request('/contacts/' . rawurlencode($contactId));
    }

    /**
     * Zahlungsstand eines Belegs (GET /v1/payments/{voucherId}).
     *
     * Die Antwort enthält nach der Lexware-Dokumentation u. a. openAmount
     * (offener Restbetrag), currency, paymentStatus (z. B. balanced,
     * openRevenue), voucherStatus, paidDate und paymentItems. Da die
     * Dokumentation beim Bau nicht online geprüft werden konnte, wird die
     * Antwort defensiv normalisiert: open_amount ist null, wenn kein
     * numerischer Wert vorliegt. Der Aufrufer darf dann NICHT einziehen.
     *
     * @return array{open_amount:?float,currency:?string,payment_status:?string,voucher_status:?string,paid_date:?string,raw:array}
     */
    public function getPayment(string $voucherId): array
    {
        $data = $this->request('/payments/' . rawurlencode($voucherId));
        $open = $data['openAmount'] ?? null;
        if (is_string($open) && is_numeric(str_replace(',', '.', $open))) {
            $open = (float)str_replace(',', '.', $open);
        }
        return [
            'open_amount'    => is_int($open) || is_float($open) ? (float)$open : null,
            'currency'       => isset($data['currency']) ? (string)$data['currency'] : null,
            'payment_status' => isset($data['paymentStatus']) ? (string)$data['paymentStatus'] : null,
            'voucher_status' => isset($data['voucherStatus']) ? (string)$data['voucherStatus'] : null,
            'paid_date'      => isset($data['paidDate']) ? substr((string)$data['paidDate'], 0, 10) : null,
            'raw'            => $data,
        ];
    }
}
