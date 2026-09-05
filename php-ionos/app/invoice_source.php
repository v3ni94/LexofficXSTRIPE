<?php
/**
 * Adaptergrenze Rechnungssystem (Paket F).
 *
 * Die Anwendung liest Kunden, offene Rechnungen und Zahlungsstände über das
 * Interface InvoiceSource. Heute gibt es genau eine Implementierung,
 * LexwareOfficeSource, ein dünner Wrapper um LexofficeClient mit identischem
 * Verhalten. Weitere Rechnungssysteme (z. B. sevdesk, Status "in Planung")
 * würden dieses Interface implementieren; welche Quelle eine Firma nutzt,
 * steht in integrations.invoice_source. Die Registry der Anbieter mit ihren
 * Fähigkeiten liegt in der Tabelle integration_providers.
 *
 * Datenformat: Die Rückgaben entsprechen dem Lexware-Format (voucherlist,
 * invoices, contacts), damit app/sync.php unverändert arbeitet. Ein neuer
 * Adapter muss seine Daten in dieses Format übersetzen.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/lexoffice.php';
require_once __DIR__ . '/crypto.php';

interface InvoiceSource
{
    /** Anbieter-Code laut integration_providers (z. B. lexware_office). */
    public function code(): string;

    /** Fähigkeiten dieses Adapters (read_customers, read_open_invoices, read_open_amount, detect_changes). */
    public function capabilities(): array;

    /** Verbindungstest; liefert mindestens ['companyName' => ...] falls verfügbar. */
    public function getProfile(): array;

    /** Alle offenen und überfälligen Rechnungen (Listenformat mit id, voucherNumber, voucherStatus). */
    public function getOpenInvoices(): array;

    /** Eine Seite der Rechnungsliste: ['content' => [...], 'totalPages' => n]. */
    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array;

    /** Rechnungsdetail (voucherNumber, voucherStatus, address.contactId, totalPrice.totalGrossAmount, dueDate, lineItems). */
    public function getInvoiceDetail(string $invoiceId): array;

    /** Kontakt (roles.customer.number, company.name oder person, emailAddresses). */
    public function getContact(string $contactId): array;

    /**
     * Zahlungsstand: ['open_amount' => ?float, 'currency', 'payment_status', 'voucher_status', 'paid_date', 'raw'].
     * open_amount null bedeutet: nicht ermittelbar, kein Einzug.
     */
    public function getPayment(string $invoiceId): array;
}

/** Lexware Office: dünner Wrapper, Verhalten identisch zu LexofficeClient. */
final class LexwareOfficeSource implements InvoiceSource
{
    public const CODE = 'lexware_office';

    private LexofficeClient $client;

    public function __construct(LexofficeClient $client)
    {
        $this->client = $client;
    }

    public function client(): LexofficeClient
    {
        return $this->client;
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function capabilities(): array
    {
        return ['read_customers', 'read_open_invoices', 'read_open_amount', 'detect_changes'];
    }

    public function getProfile(): array
    {
        return $this->client->getProfile();
    }

    public function getOpenInvoices(): array
    {
        return $this->client->getOpenInvoices();
    }

    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array
    {
        return $this->client->getInvoiceVouchersPage($voucherStatus, $page);
    }

    public function getInvoiceDetail(string $invoiceId): array
    {
        return $this->client->getInvoiceDetail($invoiceId);
    }

    public function getContact(string $contactId): array
    {
        return $this->client->getContact($contactId);
    }

    public function getPayment(string $invoiceId): array
    {
        return $this->client->getPayment($invoiceId);
    }
}

/**
 * Rechnungsquelle einer Firma. Liest integrations.invoice_source (Standard
 * lexware_office) und den verschlüsselten Schlüssel. Wirft RuntimeException,
 * wenn die Quelle nicht verbunden, der Schlüssel fehlt oder der Anbieter nicht
 * freigegeben ist.
 *
 * Testhaken (nur CLI): $GLOBALS['lexsepa_lex_client_factory'] darf einen
 * LexofficeClient oder ein InvoiceSource liefern.
 */
function invoice_source_for_tenant(string $tenantId): InvoiceSource
{
    if (PHP_SAPI === 'cli' && isset($GLOBALS['lexsepa_lex_client_factory']) && is_callable($GLOBALS['lexsepa_lex_client_factory'])) {
        $obj = ($GLOBALS['lexsepa_lex_client_factory'])($tenantId);
        if ($obj instanceof InvoiceSource) {
            return $obj;
        }
        if ($obj instanceof LexofficeClient) {
            return new LexwareOfficeSource($obj);
        }
        throw new RuntimeException('Testhaken liefert keinen gültigen Rechnungs-Client.');
    }

    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();
    $code = (string)($integration['invoice_source'] ?? LexwareOfficeSource::CODE);

    if ($code !== LexwareOfficeSource::CODE) {
        $provider = integration_provider($code);
        $name = $provider['name'] ?? $code;
        throw new RuntimeException(sprintf(
            'Das Rechnungssystem "%s" ist noch nicht freigegeben (Status: %s).',
            $name, $provider['status'] ?? 'unbekannt'
        ));
    }
    if (!$integration || !(int)$integration['lexoffice_connected']) {
        throw new RuntimeException('Lexware Office ist nicht verbunden.');
    }
    $apiKey = decrypt_value($integration['lexoffice_api_key_encrypted']);
    if (!$apiKey) {
        throw new RuntimeException('Lexware Office API-Key fehlt.');
    }
    return new LexwareOfficeSource(new LexofficeClient($apiKey));
}

/** Rechnungsquelle aus einem noch nicht gespeicherten Schlüssel (Verbindungstest beim Einrichten). */
function invoice_source_from_key(string $code, string $apiKey): InvoiceSource
{
    if ($code !== LexwareOfficeSource::CODE) {
        throw new RuntimeException('Rechnungssystem nicht freigegeben: ' . $code);
    }
    return new LexwareOfficeSource(new LexofficeClient($apiKey));
}

/** Eintrag der Anbieter-Registry (integration_providers) oder null. */
function integration_provider(string $code): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM integration_providers WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $row['capabilities'] = json_decode((string)($row['capabilities_json'] ?? '[]'), true) ?: [];
    return $row;
}

/** Alle Anbieter einer Art (invoice_system | payment_provider), sortiert nach Name. */
function integration_providers(?string $kind = null): array
{
    try {
        if ($kind !== null) {
            $stmt = db()->prepare('SELECT * FROM integration_providers WHERE kind = ? ORDER BY name');
            $stmt->execute([$kind]);
        } else {
            $stmt = db()->query('SELECT * FROM integration_providers ORDER BY kind, name');
        }
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$row) {
        $row['capabilities'] = json_decode((string)($row['capabilities_json'] ?? '[]'), true) ?: [];
    }
    return $rows;
}
