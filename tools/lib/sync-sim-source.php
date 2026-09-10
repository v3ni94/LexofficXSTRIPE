<?php
/** Fake-Rechnungsquelle fuer tools/lib/sync-sim.php und tools/lib/perf-sim.php (kein Netz). */
declare(strict_types=1);
require_once dirname(__DIR__, 1) . '/../php-ionos/app/invoice_source.php';
require_once dirname(__DIR__, 1) . '/../php-ionos/app/lexoffice.php';

final class FakeSyncSource implements InvoiceSource
{
    /** @var array<string,array> Rechnungen: id => detail (voucherStatus, contactId, amount, updatedDate) */
    public array $invoices = [];
    /** @var array<string,array|Throwable> Kontakte: id => contact oder Ausnahme */
    public array $contacts = [];
    /** @var array<string,Throwable> Detailabruf wirft fuer diese Rechnungs-IDs */
    public array $detailErrors = [];
    public function code(): string { return 'lexware_office'; }
    public function capabilities(): array { return ['read_customers', 'read_open_invoices', 'read_open_amount', 'detect_changes']; }
    public function getProfile(): array { return ['companyName' => 'Fake']; }
    public function getOpenInvoices(): array { return array_values(array_filter(array_map(fn($id, $d) => ['id' => $id, 'voucherNumber' => $d['number'], 'voucherStatus' => $d['voucherStatus'], 'updatedDate' => $d['updatedDate']], array_keys($this->invoices), $this->invoices), fn($v) => in_array($v['voucherStatus'], ['open', 'overdue'], true))); }
    public function getInvoiceVouchersPage(string $voucherStatus, int $page): array
    {
        $rows = [];
        foreach ($this->invoices as $id => $d) {
            if ($d['voucherStatus'] === $voucherStatus) {
                $rows[] = ['id' => $id, 'voucherNumber' => $d['number'], 'voucherStatus' => $d['voucherStatus'], 'updatedDate' => $d['updatedDate'], 'voucherType' => 'invoice'];
            }
        }
        return ['content' => $page === 0 ? $rows : [], 'totalPages' => 1, 'last' => true];
    }
    public function getInvoiceDetail(string $invoiceId): array
    {
        if (isset($this->detailErrors[$invoiceId])) { throw $this->detailErrors[$invoiceId]; }
        $d = $this->invoices[$invoiceId] ?? null;
        if (!$d) { throw new LexofficeException('Unerwarteter Lexware Office Status 404: nicht gefunden'); }
        return ['id' => $invoiceId, 'voucherNumber' => $d['number'], 'voucherStatus' => $d['voucherStatus'], 'voucherDate' => '2026-09-01T00:00:00.000+02:00',
            'address' => ['contactId' => $d['contactId'], 'name' => 'Adresse ' . $d['number']],
            'totalPrice' => ['totalGrossAmount' => $d['amount'], 'currency' => 'EUR'], 'dueDate' => '2026-09-15T00:00:00.000+02:00',
            'updatedDate' => $d['updatedDate'], 'lineItems' => []];
    }
    public function getContact(string $contactId): array
    {
        $c = $this->contacts[$contactId] ?? null;
        if ($c instanceof Throwable) { throw $c; }
        if ($c === null) { throw new LexofficeException('Unerwarteter Lexware Office Status 404: Kontakt nicht gefunden'); }
        return $c;
    }
    public function getPayment(string $invoiceId): array
    {
        $d = $this->invoices[$invoiceId] ?? null;
        $open = $d && $d['voucherStatus'] !== 'paid' ? (float)$d['amount'] : 0.0;
        return ['open_amount' => $open, 'currency' => 'EUR', 'payment_status' => $open > 0 ? 'openRevenue' : 'paid', 'voucher_status' => $d['voucherStatus'] ?? 'paid', 'paid_date' => null, 'raw' => []];
    }
}
