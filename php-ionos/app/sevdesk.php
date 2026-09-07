<?php
/**
 * sevdesk: HTTP-Client-Gerüst und Adapter SevdeskSource (InvoiceSource).
 *
 * STAND: Gerüst ohne Freigabe. Es gibt noch kein sevdesk-Testkonto im Projekt; Endpunkte, Felder, Statuscodes und
 * das Verhalten bei Drosselung sind NICHT verifiziert. Deshalb liefert der Adapter keine Fähigkeiten
 * (capabilities = []) und jede fachliche Methode wirft eine RuntimeException, solange der Schalter
 * sevdesk_connect nicht gesetzt ist UND die Endpunkte nicht gegen die offizielle Dokumentation bestätigt wurden.
 *
 * Gesichert (offizielle API-News von sevdesk, Februar 2025): Authentifizierung über den HTTP-Header Authorization;
 * der frühere Token als URL-Parameter ist abgekündigt und wird hier nicht verwendet. Kein OAuth-Ablauf erfunden.
 * Basisadresse und Headerform kommen aus config('sevdesk') und sind mit dem Testkonto zu verifizieren.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/integration_state.php';

final class SevdeskClient
{
    public const CODE = 'sevdesk';
    private string $apiKey;
    private string $baseUrl;
    private string $authPrefix;
    public int $requestCount = 0;

    public function __construct(string $apiKey)
    {
        $cfg = (array)config('sevdesk', []);
        $this->apiKey = $apiKey;
        // Zu verifizieren mit dem Testkonto; bis dahin nur Konfigurationswert, keine Behauptung.
        $this->baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $this->authPrefix = (string)($cfg['auth_prefix'] ?? ''); // z. B. '' oder 'Bearer '
    }

    /**
     * Einzelner GET-Aufruf mit Authorization-Header, Circuit Breaker und Ratenbegrenzung über api_call_gate().
     * Kein Token in der URL. Wirft bei fehlender Basisadresse oder fehlender Freigabe.
     */
    public function get(string $endpoint, array $params = []): array
    {
        if (!integration_switch(self::CODE, 'connect')) {
            throw new RuntimeException('sevdesk-Anbindung ist nicht freigegeben (Schalter sevdesk_connect).');
        }
        if ($this->baseUrl === '') {
            throw new RuntimeException('sevdesk-Basisadresse nicht konfiguriert (config sevdesk.base_url).');
        }
        if (function_exists('api_call_gate')) {
            $q = (array)config('queue', []);
            api_call_gate('sevdesk', (int)($q['sevdesk_per_second'] ?? 2), api_scope_for_key($this->apiKey), (int)($q['sevdesk_global_per_second'] ?? 20));
        }
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . ($params ? '?' . http_build_query($params) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $this->authPrefix . $this->apiKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $this->requestCount++;
        if ($body === false || $err !== '') {
            if (function_exists('circuit_failure')) {
                circuit_failure('sevdesk', 'connection');
            }
            throw new RuntimeException('sevdesk nicht erreichbar: ' . $err);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('sevdesk: Zugriff verweigert (Schlüssel oder Tarif ohne API-Berechtigung).');
        }
        if ($status === 429 || $status >= 500) {
            if (function_exists('circuit_failure')) {
                circuit_failure('sevdesk', $status === 429 ? 'rate_limit' : 'server');
            }
            throw new RuntimeException('sevdesk: vorübergehend nicht verfügbar (HTTP ' . $status . ').');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new RuntimeException('sevdesk: unerwartete Antwort (HTTP ' . $status . ').');
        }
        return $data;
    }
}

/** Adapter ohne Fähigkeiten, bis Endpunkte und Felder mit einem Testkonto verifiziert sind. */
final class SevdeskSource implements InvoiceSource
{
    public const CODE = 'sevdesk';

    public function __construct(private SevdeskClient $client)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): array
    {
        return [];
    }

    private function nichtVerifiziert(string $was): RuntimeException
    {
        return new RuntimeException('sevdesk: ' . $was . ' ist noch nicht gegen die offizielle Dokumentation verifiziert und daher gesperrt.');
    }

    public function getProfile(): array
    {
        throw $this->nichtVerifiziert('Verbindungstest und Kontoidentifikation');
    }

    public function getOpenInvoices(): array
    {
        throw $this->nichtVerifiziert('Abruf offener Rechnungen');
    }

    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array
    {
        throw $this->nichtVerifiziert('Seitennavigation der Rechnungsliste');
    }

    public function getInvoiceDetail(string $invoiceId): array
    {
        throw $this->nichtVerifiziert('Rechnungsdetail');
    }

    public function getContact(string $contactId): array
    {
        throw $this->nichtVerifiziert('Kontaktabruf');
    }

    public function getPayment(string $invoiceId): array
    {
        throw $this->nichtVerifiziert('Zahlungsstand und offener Restbetrag');
    }
}
