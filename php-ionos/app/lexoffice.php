<?php
/**
 * Lexoffice-API-Client (cURL, ohne Composer-Abhängigkeiten).
 * Drosselung auf < 2 Requests/Sekunde, Retries bei 429 und 5xx.
 * Portiert aus lexoffice_service.py.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

class LexofficeException extends RuntimeException {}

class LexofficeClient
{
    private const BASE_URL = 'https://api.lexoffice.io/v1';
    private const MIN_REQUEST_INTERVAL_US = 600000; // 0,6 s

    private string $apiKey;
    private float $lastRequestTime = 0.0;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    private function throttle(): void
    {
        $elapsedUs = (microtime(true) - $this->lastRequestTime) * 1_000_000;
        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep((int)(self::MIN_REQUEST_INTERVAL_US - $elapsedUs));
        }
    }

    private function request(string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $retries429 = 0;
        $retries5xx = 0;

        while (true) {
            $this->throttle();
            $this->lastRequestTime = microtime(true);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                ],
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new LexofficeException("Verbindungsfehler zu Lexoffice: $err");
            }

            if ($status === 200) {
                $data = json_decode($body, true);
                if (!is_array($data)) {
                    throw new LexofficeException('Ungültige Antwort von Lexoffice.');
                }
                return $data;
            }

            if ($status === 401) {
                throw new LexofficeException('Lexoffice API-Key ungültig oder abgelaufen.');
            }

            if ($status === 429) {
                $retries429++;
                if ($retries429 > 3) {
                    throw new LexofficeException('Lexoffice Rate-Limit nach 3 Versuchen überschritten.');
                }
                sleep(2 ** $retries429); // 2, 4, 8 s
                continue;
            }

            if (in_array($status, [500, 502, 503], true)) {
                $retries5xx++;
                if ($retries5xx > 2) {
                    throw new LexofficeException("Lexoffice Serverfehler $status nach Retries.");
                }
                sleep(2 ** $retries5xx);
                continue;
            }

            throw new LexofficeException(
                "Unerwarteter Lexoffice-Status $status: " . substr($body, 0, 200)
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
                $data = $this->request('/voucherlist', [
                    'voucherType'   => 'invoice',
                    'voucherStatus' => $voucherStatus,
                    'size'          => 100,
                    'page'          => $page,
                ]);
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

    public function getInvoiceDetail(string $invoiceId): array
    {
        return $this->request('/invoices/' . rawurlencode($invoiceId));
    }

    public function getContact(string $contactId): array
    {
        return $this->request('/contacts/' . rawurlencode($contactId));
    }
}
