<?php
/**
 * Journal-Export der Einzüge als CSV (UTF-8 mit BOM, Semikolon, Excel-tauglich).
 * Inhaber, Administratoren und Mitarbeiter der Firma; Login-Pflicht, GET-Download.
 * Formelschutz: Zellen, die mit = + - @ beginnen, erhalten ein führendes
 * Apostroph, damit Tabellenprogramme keine Formeln ausführen.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/audit.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();

$filter = (string)($_GET['status'] ?? '');
$allowed = ['scheduled', 'processing', 'succeeded', 'refunded', 'failed', 'disputed', 'cancelled'];
$where = 'pc.tenant_id = ?';
$params = [$tenantId];
if (in_array($filter, $allowed, true)) {
    $where .= ' AND pc.stripe_status = ?';
    $params[] = $filter;
}

$stmt = $pdo->prepare(
    "SELECT pc.*, i.voucher_number, i.lexoffice_invoice_id, i.contact_name, i.open_amount, i.open_amount_fetched_at,
            c.customer_number, c.name AS customer_name,
            m.mandate_reference, m.stripe_mandate_reference,
            u.email AS created_by_email, u.display_name AS created_by_name
     FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     LEFT JOIN customers c ON c.id = i.customer_id
     LEFT JOIN sepa_mandates m ON m.id = pc.mandate_id
     LEFT JOIN users u ON u.id = pc.created_by_user_id
     WHERE $where
     ORDER BY pc.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

audit_log($tenantId, $ctx, 'collections_exported', 'organization', $tenantId, [
    'rows' => count($rows), 'filter' => $filter !== '' ? $filter : 'alle',
]);

/** Zelle für CSV vorbereiten: Formelschutz, Anführungszeichen, Zeilenumbrüche. */
function csv_cell(?string $value): string
{
    $v = (string)$value;
    $v = str_replace(["\r", "\n"], ' ', $v);
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

function csv_amount(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
}

function csv_status(?string $status): string
{
    return match ($status) {
        'scheduled'  => 'Terminiert',
        'submitting' => 'Wird eingereicht',
        'processing' => 'In Bearbeitung',
        'succeeded'  => 'Erfolgreich',
        'failed'     => 'Fehlgeschlagen',
        'disputed'   => 'Rücklastschrift',
        'refunded'   => 'Erstattet',
        'cancelled'  => 'Storniert',
        default      => (string)$status,
    };
}

$filename = 'einzugsjournal_' . preg_replace('/[^A-Za-z0-9]+/', '-', $ctx['org_name']) . '_' . date('Ymd_Hi') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$header = [
    'Rechnungsnummer', 'Lexware-Rechnungs-ID', 'Kunde', 'Kundennummer', 'Einzugs-ID', 'Stripe PaymentIntent', 'Stripe Charge',
    'Betrag EUR', 'Status', 'Eingereicht am', 'Erfolgreich am', 'Rücklastschrift am', 'Erstattet EUR', 'Erstattet am',
    'Mandatsreferenz', 'Stripe-Mandatsreferenz', 'Herkunft',
    'Restbetrag laut Lexware', 'Restbetrag abgerufen am', 'Ausgelöst von', 'Termin', 'Vermerk', 'Fehlergrund',
];
fwrite($out, implode(';', array_map('csv_cell', $header)) . "\r\n");
foreach ($rows as $r) {
    $succeededAt = $r['stripe_status'] === 'succeeded' ? $r['completed_at'] : null;
    $disputedAt = $r['stripe_status'] === 'disputed' ? $r['completed_at'] : null;
    $line = [
        $r['voucher_number'],
        $r['lexoffice_invoice_id'],
        $r['customer_name'] ?: $r['contact_name'],
        $r['customer_number'],
        $r['id'],
        $r['stripe_payment_intent_id'],
        $r['stripe_charge_id'],
        csv_amount((int)$r['amount_cents']),
        csv_status($r['stripe_status']),
        $r['submitted_at'] ? format_datetime($r['submitted_at']) : '',
        $succeededAt ? format_datetime($succeededAt) : '',
        $disputedAt ? format_datetime($disputedAt) : '',
        (int)($r['refunded_cents'] ?? 0) > 0 ? csv_amount((int)$r['refunded_cents']) : '',
        !empty($r['refunded_at']) ? format_datetime($r['refunded_at']) : '',
        $r['mandate_reference'] ?? $r['imported_mandate_reference'] ?? '',
        $r['stripe_mandate_reference'],
        ($r['source'] ?? 'app') === 'import' ? 'Import aus Stripe' : 'Anwendung',
        $r['open_amount'] !== null ? number_format((float)$r['open_amount'], 2, ',', '') : '',
        $r['open_amount_fetched_at'] ? format_datetime($r['open_amount_fetched_at']) : '',
        $r['created_by_name'] ?: ($r['created_by_email'] ?: 'System/Cron'),
        (int)$r['is_scheduled'] ? format_date($r['scheduled_date']) : '',
        trim((string)$r['note'] . (!empty($r['refund_note']) ? ' | ' . $r['refund_note'] : '')),
        $r['failure_reason'],
    ];
    fwrite($out, implode(';', array_map(fn($v) => csv_cell($v === null ? null : (string)$v), $line)) . "\r\n");
}
fclose($out);
exit;
