<?php
/**
 * sevdesk: HTTP-Client und Adapter SevdeskSource (InvoiceSource), Version 4.38 (Masterplan Phase 2, Baustein B).
 *
 * STAND DER VERIFIKATION (07.09.2026): Es gibt kein sevdesk-Testkonto im Projekt, und die offizielle Dokumentation
 * (api.sevdesk.de, tech.sevdesk.com) war aus der Entwicklungsumgebung nicht erreichbar. Endpunkte, Parameter und
 * Felder stammen aus Sekundaerquellen (GitHub-Spiegel einer sevdesk-OpenAPI-Beschreibung, Community-SDKs, Recherche
 * vom 07.09.2026 in docs/sevdesk.md, Abschnitt "Endpunktregister"). Der Adapter ist deshalb so gebaut, dass ein
 * Irrtum NIE Geld bewegt:
 *   - Lesen von Rechnungen und Kontakten laeuft, sobald der Schalter sevdesk_connect gesetzt ist (Freigabe des
 *     Betreibers oder automatisch ab sevdesk_release_at, siehe app/integration_state.php).
 *   - Der offene Restbetrag (getPayment) liefert null, solange platform_settings.sevdesk_api_verified nicht '1' ist.
 *     null bedeutet nach dem Vertrag in app/invoice_source.php: KEIN Einzug. Erst wenn der Betreiber die Felder
 *     sumGross/paidAmount mit einem echten Konto bestaetigt hat, setzt er den Schalter; zusaetzlich bleibt der
 *     anbieterbezogene Not-Aus sevdesk_collections bestehen (app/collections.php).
 *   - Jede Annahme ist im Code mit "ANNAHME" markiert und im Endpunktregister mit Quelle und Prueffrage gefuehrt.
 *
 * Gesichert (API-News von sevdesk, Februar 2025, nur Titel einsehbar): Authentifizierung ueber den HTTP-Header
 * Authorization mit dem rohen Token; der Token in der URL ist abgekuendigt und wird nicht verwendet. Kein OAuth.
 * Basisadresse (ANNAHME https://my.sevdesk.de/api/v1) und optionaler Header X-Version kommen aus config('sevdesk').
 *
 * Datenminimierung wie bei Lexware (AVV Anlage 1): abgerufen werden nur Rechnungskopf, Positionen (Bezeichnung,
 * Beschreibung, Menge, Preis), Kontaktname, Kundennummer und eine E-Mail-Adresse. Anschriften, Telefonnummern,
 * Steuernummern und Bankverbindungen werden weder abgefragt noch gespeichert.
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
    /** ANNAHME (Sekundaerquellen 07.09.2026): Basisadresse der sevdesk-API v1. Ueberschreibbar in config sevdesk.base_url. */
    public const DEFAULT_BASE_URL = 'https://my.sevdesk.de/api/v1';
    /** Seitengroesse fuer Listen (limit); sevdesk erlaubt laut Spiegel-OpenAPI 1 bis 1000. Konservativ. */
    public const PAGE_SIZE = 100;
    /** Rechnungsstatus laut Spiegel-OpenAPI (ANNAHME): 100 Entwurf, 200 offen, 750 teilbezahlt, 1000 bezahlt. */
    public const STATUS_DRAFT = 100;
    public const STATUS_OPEN = 200;
    public const STATUS_PARTIAL = 750;
    public const STATUS_PAID = 1000;

    private string $apiKey;
    private string $baseUrl;
    private string $authPrefix;
    private string $version;
    private int $timeout;
    public int $requestCount = 0;
    public float $requestMs = 0.0;
    public float $throttleMs = 0.0;
    public float $requestMsMax = 0.0;
    public int $retryCount = 0;
    /** Letzter HTTP-Status (Diagnose ohne Geheimnisse). */
    public int $lastStatus = 0;

    public function __construct(string $apiKey)
    {
        $cfg = (array)config('sevdesk', []);
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim((string)($cfg['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->authPrefix = (string)($cfg['auth_prefix'] ?? ''); // '' laut Sekundaerquellen (kein "Bearer ")
        $this->version = trim((string)($cfg['x_version'] ?? ''));  // optionaler Header X-Version (ANNAHME, nur wenn gesetzt)
        $this->timeout = max(5, min(60, (int)($cfg['timeout_seconds'] ?? 20)));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Einzelner GET-Aufruf mit Authorization-Header, Ratenbegrenzung und Circuit Breaker ueber api_call_gate().
     * Kein Token in der URL, kein Token im Fehlertext. Wirft RuntimeException mit Klartext.
     */
    public function get(string $endpoint, array $params = []): array
    {
        if (!integration_switch(self::CODE, 'connect')) {
            throw new RuntimeException('Die sevdesk-Anbindung ist noch nicht freigegeben (Schalter sevdesk_connect beziehungsweise Freigabetermin).');
        }
        if ($this->apiKey === '') {
            throw new RuntimeException('sevdesk: kein API-Token hinterlegt.');
        }
        if ($this->baseUrl === '') {
            throw new RuntimeException('sevdesk-Basisadresse nicht konfiguriert (config sevdesk.base_url).');
        }
        if (function_exists('api_call_gate')) {
            $q = (array)config('queue', []);
            // ANNAHME: sevdesk veroeffentlicht keine feste Grenze; konservativ 2 Anfragen je Sekunde und Konto.
            api_call_gate('sevdesk', (int)($q['sevdesk_per_second'] ?? 2), api_scope_for_key($this->apiKey), (int)($q['sevdesk_global_per_second'] ?? 20));
        }
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/') . ($params ? '?' . http_build_query($params) : '');
        $headers = ['Authorization: ' . $this->authPrefix . $this->apiKey, 'Accept: application/json'];
        if ($this->version !== '') {
            $headers[] = 'X-Version: ' . $this->version;
        }
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $this->requestCount++;
        $elapsedMs = (microtime(true) - $t0) * 1000;
        $this->requestMs += $elapsedMs;
        $this->requestMsMax = max($this->requestMsMax, $elapsedMs);
        $this->lastStatus = $status;
        $instrument = static function (string $state, ?string $category) {
            if (function_exists('monitor_event')) {
                try { monitor_event('sevdesk_api', $state, null, $category, 'instrumented', 3600); } catch (Throwable $e) { /* Monitoring darf den Abruf nie stoeren */ }
            }
        };
        if ($body === false || $err !== '') {
            if (function_exists('circuit_failure')) {
                circuit_failure('sevdesk', 'connection');
            }
            $instrument('fail', 'connection');
            throw new RuntimeException('sevdesk nicht erreichbar: ' . $err);
        }
        if ($status === 401 || $status === 403) {
            // Fachliche Ablehnung des Schluessels: kein Circuit-Breaker-Fehler (betrifft nur diese Firma).
            $instrument('ok', null);
            throw new RuntimeException('sevdesk: Zugriff verweigert (HTTP ' . $status . '). Token ungültig oder der Tarif erlaubt keinen API-Zugriff (nach der offiziellen sevdesk-Hilfe Tarif Buchhaltung Pro, Systemversion 2.0).');
        }
        if ($status === 429) {
            if (function_exists('circuit_failure')) {
                circuit_failure('sevdesk', 'rate_limit');
            }
            $instrument('fail', 'rate_limit');
            throw new RuntimeException('sevdesk: Anfragegrenze erreicht (HTTP 429). Die Synchronisation wird später fortgesetzt.');
        }
        if ($status >= 500) {
            if (function_exists('circuit_failure')) {
                circuit_failure('sevdesk', 'server');
            }
            $instrument('fail', 'server');
            throw new RuntimeException('sevdesk: vorübergehend nicht verfügbar (HTTP ' . $status . ').');
        }
        if ($status === 404) {
            $instrument('ok', null);
            throw new RuntimeException('sevdesk: Datensatz nicht gefunden (HTTP 404).');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            $instrument('fail', 'protocol');
            throw new RuntimeException('sevdesk: unerwartete Antwort (HTTP ' . $status . ').');
        }
        $instrument('ok', null);
        return $data;
    }

    /** Objekte einer Listenantwort ({"objects": [...]}) oder leere Liste. */
    public static function objects(array $data): array
    {
        $o = $data['objects'] ?? null;
        return is_array($o) ? array_values(array_filter($o, 'is_array')) : [];
    }

    /** Gesamtzahl aus "total" (nur mit countAll=true gefuellt, ANNAHME) oder null. */
    public static function total(array $data): ?int
    {
        return isset($data['total']) && is_numeric($data['total']) ? (int)$data['total'] : null;
    }
}

/**
 * Adapter: bildet sevdesk-Objekte auf die Strukturen ab, die app/sync.php von Lexware Office kennt (Voucherliste,
 * Rechnungsdetail mit address/totalPrice/lineItems, Kontakt mit company/person/roles/emailAddresses, Zahlungsstand).
 * Dadurch bleibt der Kern unveraendert. Jede Normalisierung ist im Endpunktregister (docs/sevdesk.md) dokumentiert.
 */
final class SevdeskSource implements InvoiceSource
{
    public const CODE = 'sevdesk';

    public function __construct(private SevdeskClient $client)
    {
    }

    public function client(): SevdeskClient
    {
        return $this->client;
    }

    public function code(): string
    {
        return self::CODE;
    }

    /** Ist der offene Restbetrag durch den Betreiber mit einem echten Konto bestaetigt (Schalter sevdesk_api_verified)? */
    public static function paymentsVerified(): bool
    {
        return integration_setting('sevdesk_api_verified') === '1';
    }

    /**
     * Faehigkeiten: Lesen von Kunden und offenen Rechnungen sowie Aenderungserkennung sind aktiv, sobald die Anbindung
     * freigegeben ist. read_open_amount kommt erst mit der Bestaetigung der Zahlungsfelder (sevdesk_api_verified).
     */
    public function capabilities(): array
    {
        if (!integration_switch(self::CODE, 'connect')) {
            return [];
        }
        $caps = ['read_customers', 'read_open_invoices', 'detect_changes'];
        if (self::paymentsVerified()) {
            $caps[] = 'read_open_amount';
        }
        return $caps;
    }

    /**
     * Verbindungstest. ANNAHME: Es ist kein Endpunkt fuer die Kontoidentitaet belegt; geprueft wird die
     * Erreichbarkeit und Berechtigung ueber GET /Contact?limit=1&countAll=true (HTTP 200 mit gueltigem Token).
     * companyName bleibt null, bis ein Testkonto den passenden Endpunkt bestaetigt (Anzeige "Firmenname nicht uebermittelt").
     */
    public function getProfile(): array
    {
        $data = $this->client->get('Contact', ['limit' => 1, 'countAll' => 'true']);
        return [
            'companyName'    => null,
            'reachable'      => true,
            'contacts_total' => SevdeskClient::total($data),
            'raw'            => ['keys' => array_keys($data)],
        ];
    }

    public function getOpenInvoices(): array
    {
        $vouchers = [];
        foreach (['open', 'overdue'] as $status) {
            $page = 0;
            while (true) {
                $data = $this->getInvoiceVouchersPage($status, $page);
                foreach ($data['content'] as $v) {
                    $vouchers[] = $v;
                }
                $page++;
                if ($page >= (int)$data['totalPages']) {
                    break;
                }
            }
        }
        return $vouchers;
    }

    /**
     * Seite der offenen Rechnungen. sevdesk kennt keinen Status "ueberfaellig"; die Anwendung leitet ihn aus dem
     * Faelligkeitsdatum ab. Abbildung der Lexware-Statusseiten:
     *   'open'    -> GET /Invoice?status=200 (offen, ANNAHME), voucherStatus je Beleg open|overdue nach Faelligkeit
     *   'overdue' -> GET /Invoice?status=750 (teilbezahlt, ANNAHME): erscheint als offen; Einzug erst mit verifiziertem
     *                Restbetrag (getPayment), sonst gesperrt
     * Entwuerfe (100) und bezahlte Rechnungen (1000) werden nie abgerufen. Nur Belege des Typs RE (Rechnung); andere
     * Typen (Mahnung, Teil-, Anzahlungs-, Schlussrechnung, wiederkehrend) bleiben ausgeschlossen, bis ihre Bedeutung
     * mit einem Testkonto geklaert ist (Endpunktregister).
     */
    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array
    {
        $status = $voucherStatus === 'overdue' ? SevdeskClient::STATUS_PARTIAL : SevdeskClient::STATUS_OPEN;
        $page = max(0, $page);
        $data = $this->client->get('Invoice', [
            'status'   => $status,
            'limit'    => SevdeskClient::PAGE_SIZE,
            'offset'   => $page * SevdeskClient::PAGE_SIZE,
            'countAll' => 'true',
        ]);
        $objects = SevdeskClient::objects($data);
        $content = [];
        foreach ($objects as $inv) {
            $type = (string)($inv['invoiceType'] ?? 'RE');
            if ($type !== 'RE' || !isset($inv['id'])) {
                continue;
            }
            $content[] = [
                'id'            => (string)$inv['id'],
                'voucherNumber' => (string)($inv['invoiceNumber'] ?? $inv['id']),
                'voucherStatus' => self::mapStatus($inv),
                'updatedDate'   => self::mapDateTime($inv['update'] ?? null),
            ];
        }
        $total = SevdeskClient::total($data);
        if ($total !== null) {
            $totalPages = max(1, (int)ceil($total / SevdeskClient::PAGE_SIZE));
        } else {
            // Ohne Gesamtzahl: eine volle Seite laesst eine weitere vermuten, eine unvollstaendige ist die letzte.
            $totalPages = count($objects) >= SevdeskClient::PAGE_SIZE ? $page + 2 : $page + 1;
        }
        return ['content' => $content, 'totalPages' => $totalPages, 'page' => $page, 'size' => SevdeskClient::PAGE_SIZE];
    }

    /**
     * Rechnungsdetail in Lexware-Struktur. GET /Invoice/{id}?embed=contact (ANNAHME embed) plus Positionen ueber
     * GET /InvoicePos?invoice[id]=..&invoice[objectName]=Invoice (ANNAHME Filterform der sevdesk-API).
     */
    public function getInvoiceDetail(string $invoiceId): array
    {
        $data = $this->client->get('Invoice/' . rawurlencode($invoiceId), ['embed' => 'contact']);
        $inv = SevdeskClient::objects($data)[0] ?? (isset($data['id']) ? $data : null);
        if ($inv === null) {
            throw new RuntimeException('sevdesk: Rechnung ' . $invoiceId . ' nicht gefunden oder unerwartete Antwort.');
        }
        $contact = is_array($inv['contact'] ?? null) ? $inv['contact'] : [];
        $contactId = isset($contact['id']) ? (string)$contact['id'] : null;
        $contactName = self::contactName($contact);

        $lineItems = [];
        try {
            $pos = $this->client->get('InvoicePos', ['invoice[id]' => $invoiceId, 'invoice[objectName]' => 'Invoice', 'limit' => 200]);
            foreach (SevdeskClient::objects($pos) as $p) {
                $lineItems[] = [
                    'type'        => 'custom',
                    'name'        => (string)($p['name'] ?? ''),
                    'description' => (string)($p['text'] ?? ''),
                    'quantity'    => isset($p['quantity']) ? (float)$p['quantity'] : null,
                    'unitPrice'   => ['grossAmount' => isset($p['price']) ? (float)$p['price'] : null, 'taxRatePercentage' => isset($p['taxRate']) ? (float)$p['taxRate'] : null],
                ];
            }
        } catch (Throwable $e) {
            // Positionen sind nur fuer das Stichwort noetig; ohne sie bleibt die Rechnung verwendbar.
            $lineItems = [];
        }

        $gross = self::number($inv['sumGross'] ?? null);
        return [
            'id'            => (string)$inv['id'],
            'voucherNumber' => (string)($inv['invoiceNumber'] ?? $inv['id']),
            'voucherStatus' => self::mapStatus($inv),
            'voucherDate'   => self::mapDate($inv['invoiceDate'] ?? null),
            'dueDate'       => self::dueDate($inv),
            'updatedDate'   => self::mapDateTime($inv['update'] ?? null),
            'address'       => ['contactId' => $contactId, 'name' => $contactName ?? (string)($inv['addressName'] ?? ''), 'supplement' => null],
            'totalPrice'    => ['currency' => (string)($inv['currency'] ?? 'EUR'), 'totalGrossAmount' => $gross ?? 0.0],
            'lineItems'     => $lineItems,
            'sevdesk'       => ['status' => (int)($inv['status'] ?? 0), 'invoiceType' => (string)($inv['invoiceType'] ?? ''), 'paidAmount' => self::number($inv['paidAmount'] ?? null)],
        ];
    }

    /**
     * Kontakt in Lexware-Struktur: company.name ODER person.firstName/lastName, roles.customer.number, emailAddresses.
     * E-Mail ueber GET /CommunicationWay?contact[id]=..&contact[objectName]=Contact&type=EMAIL (ANNAHME Typwert);
     * schlaegt der Aufruf fehl, bleibt die E-Mail leer (der Kunde erhaelt dann bei Stripe eine Platzhalteradresse).
     */
    public function getContact(string $contactId): array
    {
        $data = $this->client->get('Contact/' . rawurlencode($contactId));
        $c = SevdeskClient::objects($data)[0] ?? (isset($data['id']) ? $data : []);
        $out = ['id' => (string)($c['id'] ?? $contactId), 'roles' => ['customer' => ['number' => (string)($c['customerNumber'] ?? '')]], 'emailAddresses' => []];
        $orgName = trim((string)($c['name'] ?? ''));
        $first = trim((string)($c['surename'] ?? '')); // sevdesk-Schreibweise laut Spiegel-OpenAPI: surename = Vorname
        $last = trim((string)($c['familyname'] ?? ''));
        if ($orgName !== '' && $first === '' && $last === '') {
            $out['company'] = ['name' => $orgName];
        } elseif ($first !== '' || $last !== '') {
            $out['person'] = ['firstName' => $first, 'lastName' => $last];
            if ($orgName !== '') {
                $out['company'] = ['name' => $orgName];
            }
        } elseif ($orgName !== '') {
            $out['company'] = ['name' => $orgName];
        }
        if ($out['roles']['customer']['number'] === '') {
            unset($out['roles']['customer']['number']); // sync.php setzt dann die Laufkundennummer 10001
        }
        try {
            $cw = $this->client->get('CommunicationWay', ['contact[id]' => $contactId, 'contact[objectName]' => 'Contact', 'type' => 'EMAIL', 'limit' => 5]);
            foreach (SevdeskClient::objects($cw) as $w) {
                $val = trim((string)($w['value'] ?? ''));
                if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL) && strtoupper((string)($w['type'] ?? 'EMAIL')) === 'EMAIL') {
                    $out['emailAddresses']['business'][] = $val;
                }
            }
        } catch (Throwable $e) {
            // ohne E-Mail weiterarbeiten
        }
        return $out;
    }

    /**
     * Zahlungsstand. Ohne Bestaetigung durch den Betreiber (sevdesk_api_verified = 1) ist open_amount IMMER null:
     * kein Einzug. Mit Bestaetigung: offener Betrag = sumGross minus paidAmount (ANNAHME der Feldnamen), nur wenn
     * beide Werte numerisch sind und der Beleg nicht bezahlt/storniert ist; sonst null.
     */
    public function getPayment(string $invoiceId): array
    {
        $data = $this->client->get('Invoice/' . rawurlencode($invoiceId));
        $inv = SevdeskClient::objects($data)[0] ?? (isset($data['id']) ? $data : []);
        $status = (int)($inv['status'] ?? 0);
        $gross = self::number($inv['sumGross'] ?? null);
        $paid = self::number($inv['paidAmount'] ?? null);
        $open = null;
        if (self::paymentsVerified() && $gross !== null && $paid !== null && in_array($status, [SevdeskClient::STATUS_OPEN, SevdeskClient::STATUS_PARTIAL], true)) {
            $open = round(max(0.0, $gross - $paid), 2);
        }
        return [
            'open_amount'    => $open,
            'currency'       => isset($inv['currency']) ? (string)$inv['currency'] : null,
            'payment_status' => $status === SevdeskClient::STATUS_PAID ? 'paid' : ($status === SevdeskClient::STATUS_PARTIAL ? 'partially_paid' : ($status === SevdeskClient::STATUS_OPEN ? 'open' : null)),
            'voucher_status' => self::mapStatus($inv),
            'paid_date'      => null,
            'raw'            => ['status' => $status, 'verified' => self::paymentsVerified()],
        ];
    }

    /** sevdesk-Status -> Lexware-Wortlaut (open|overdue|paid|voided|draft|unknown). */
    public static function mapStatus(array $inv): string
    {
        $status = (int)($inv['status'] ?? 0);
        if ($status === SevdeskClient::STATUS_PAID) {
            return 'paid';
        }
        if ($status === SevdeskClient::STATUS_DRAFT) {
            return 'draft';
        }
        if ($status === SevdeskClient::STATUS_OPEN || $status === SevdeskClient::STATUS_PARTIAL) {
            $due = self::dueDate($inv);
            return $due !== null && $due < date('Y-m-d') ? 'overdue' : 'open';
        }
        return 'unknown';
    }

    /** Faelligkeit: payDate (ANNAHME), sonst invoiceDate + timeToPay Tage (ANNAHME), sonst null. */
    public static function dueDate(array $inv): ?string
    {
        $pay = self::mapDate($inv['payDate'] ?? null);
        if ($pay !== null) {
            return $pay;
        }
        $date = self::mapDate($inv['invoiceDate'] ?? null);
        if ($date !== null && isset($inv['timeToPay']) && is_numeric($inv['timeToPay'])) {
            try {
                return (new DateTimeImmutable($date))->modify('+' . (int)$inv['timeToPay'] . ' days')->format('Y-m-d');
            } catch (Throwable $e) {
                return null;
            }
        }
        return $date;
    }

    public static function mapDate($value): ?string
    {
        $dt = self::mapDateTime($value);
        return $dt !== null ? substr($dt, 0, 10) : null;
    }

    /** Zeitstempel (ISO 8601, "Y-m-d H:i:s" oder Unixzeit) -> ISO 8601 UTC oder null. */
    public static function mapDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_int($value) || (is_string($value) && preg_match('/^\d{9,11}$/', $value))) {
                return (new DateTimeImmutable('@' . (int)$value))->format(DATE_ATOM);
            }
            if (is_string($value)) {
                return (new DateTimeImmutable($value))->format(DATE_ATOM);
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    public static function number($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            return (float)str_replace(',', '.', trim($value));
        }
        return null;
    }

    private static function contactName(array $contact): ?string
    {
        $org = trim((string)($contact['name'] ?? ''));
        $person = trim(trim((string)($contact['surename'] ?? '')) . ' ' . trim((string)($contact['familyname'] ?? '')));
        if ($org !== '') {
            return $org;
        }
        return $person !== '' ? $person : null;
    }
}
