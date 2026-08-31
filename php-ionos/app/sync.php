<?php
/**
 * Lexoffice-Synchronisation: offene/überfällige Rechnungen und Kontakte
 * in die lokale Datenbank übernehmen. Portiert aus sync_service.py.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/lexoffice.php';
require_once __DIR__ . '/keywords.php';

/**
 * @return array{synced:int,new:int,updated:int,removed:int}
 */
function sync_invoices(string $tenantId, LexofficeClient $lex): array
{
    // Eine große Synchronisation kann bei vielen Rechnungen dauern
    @set_time_limit(300);

    $pdo = db();
    $result = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
    $seenIds = [];

    foreach ($lex->getOpenInvoices() as $voucher) {
        $voucherId = $voucher['id'] ?? null;
        if (!$voucherId) {
            continue;
        }
        $seenIds[$voucherId] = true;
        $result['synced']++;

        $detail = $lex->getInvoiceDetail($voucherId);

        // --- Kunde auflösen ---
        $customerId = null;
        $contactName = _sync_extract_contact_name($detail);
        $contactId = $detail['address']['contactId'] ?? null;
        if ($contactId) {
            $customerId = _sync_upsert_customer($tenantId, $contactId, $contactName, $lex);
        }

        // --- Rechnungsfelder ---
        $voucherNumber = $detail['voucherNumber'] ?? ($voucher['voucherNumber'] ?? '');
        $totalAmount = (string)($detail['totalPrice']['totalGrossAmount'] ?? '0');
        $currency = $detail['totalPrice']['currency'] ?? 'EUR';
        $dueDate = _sync_parse_date($detail['dueDate'] ?? null);
        $lexStatus = $detail['voucherStatus'] ?? ($voucher['voucherStatus'] ?? 'open');

        // --- Stichwort aus Positionen ---
        $lineItems = $detail['lineItems'] ?? [];
        $lineItemsJson = $lineItems ? json_encode($lineItems, JSON_UNESCAPED_UNICODE) : null;
        [$kwDisplay, $kwSepa] = extract_keyword($lineItems);

        // --- Upsert Rechnung ---
        $stmt = $pdo->prepare(
            'SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_invoice_id = ?'
        );
        $stmt->execute([$tenantId, $voucherId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $newCollectionStatus = $existing['collection_status'];
            if ($lexStatus === 'paid' && $existing['collection_status'] !== 'collected') {
                $newCollectionStatus = 'collected';
            }

            // Stichwort nur neu berechnen, wenn sich die Positionen geändert haben
            $keyword = $existing['keyword'];
            $keywordSepa = $existing['keyword_sepa'];
            $itemsJson = $existing['line_items_json'];
            if ($lineItemsJson !== $existing['line_items_json']) {
                $itemsJson = $lineItemsJson;
                $keyword = $kwDisplay;
                $keywordSepa = $kwSepa;
            }

            $pdo->prepare(
                'UPDATE invoices SET voucher_number = ?, customer_id = ?, contact_name = ?,
                    total_gross_amount = ?, currency = ?, due_date = ?, lexoffice_status = ?,
                    collection_status = ?, line_items_json = ?, keyword = ?, keyword_sepa = ?,
                    last_synced_at = NOW()
                 WHERE id = ?'
            )->execute([
                $voucherNumber, $customerId, $contactName, $totalAmount, $currency,
                $dueDate, $lexStatus, $newCollectionStatus, $itemsJson, $keyword, $keywordSepa,
                $existing['id'],
            ]);
            $result['updated']++;
        } else {
            $pdo->prepare(
                'INSERT INTO invoices
                    (id, tenant_id, lexoffice_invoice_id, voucher_number, customer_id, contact_name,
                     total_gross_amount, currency, due_date, lexoffice_status, collection_status,
                     line_items_json, keyword, keyword_sepa, last_synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                uuid4(), $tenantId, $voucherId, $voucherNumber, $customerId, $contactName,
                $totalAmount, $currency, $dueDate, $lexStatus, 'open',
                $lineItemsJson, $kwDisplay, $kwSepa,
            ]);
            $result['new']++;
        }
    }

    // --- Lokale Rechnungen prüfen, die nicht mehr offen/überfällig sind ---
    $stmt = $pdo->prepare(
        "SELECT * FROM invoices WHERE tenant_id = ? AND lexoffice_status IN ('open', 'overdue')"
    );
    $stmt->execute([$tenantId]);
    $localOpen = $stmt->fetchAll();

    foreach ($localOpen as $inv) {
        if (isset($seenIds[$inv['lexoffice_invoice_id']])) {
            continue;
        }
        try {
            $detail = $lex->getInvoiceDetail($inv['lexoffice_invoice_id']);
            $newStatus = $detail['voucherStatus'] ?? 'unknown';
            $collectionStatus = $inv['collection_status'];

            if ($newStatus === 'paid') {
                $collectionStatus = 'collected';
                $result['removed']++;
            } elseif (in_array($newStatus, ['voided', 'cancelled'], true)) {
                $collectionStatus = 'none';
                $result['removed']++;
            }

            $pdo->prepare(
                'UPDATE invoices SET lexoffice_status = ?, collection_status = ?, last_synced_at = NOW() WHERE id = ?'
            )->execute([$newStatus, $collectionStatus, $inv['id']]);
            $result['updated']++;
        } catch (Throwable $e) {
            error_log('Konnte Rechnung ' . $inv['lexoffice_invoice_id'] . ' nicht prüfen: ' . $e->getMessage());
        }
    }

    $pdo->prepare('UPDATE integrations SET lexoffice_last_sync = NOW() WHERE tenant_id = ?')
        ->execute([$tenantId]);

    return $result;
}

// ---------------------------------------------------------------------------

function _sync_upsert_customer(string $tenantId, string $contactId, string $fallbackName, LexofficeClient $lex): string
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM customers WHERE tenant_id = ? AND lexoffice_contact_id = ?'
    );
    $stmt->execute([$tenantId, $contactId]);
    $existing = $stmt->fetch();

    try {
        $contact = $lex->getContact($contactId);
    } catch (Throwable $e) {
        $contact = [];
    }

    $name = _sync_extract_customer_name($contact) ?: $fallbackName;
    $customerNumber = (string)($contact['roles']['customer']['number'] ?? '10001');
    $email = _sync_extract_email($contact);
    $isWalkIn = $customerNumber === '10001' ? 1 : 0;

    if ($existing) {
        $pdo->prepare(
            'UPDATE customers SET name = ?, customer_number = ?, email = ?, is_walk_in = ? WHERE id = ?'
        )->execute([$name, $customerNumber, $email, $isWalkIn, $existing['id']]);
        return $existing['id'];
    }

    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO customers (id, tenant_id, lexoffice_contact_id, customer_number, name, email, is_walk_in)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $tenantId, $contactId, $customerNumber, $name, $email, $isWalkIn]);
    return $id;
}

function _sync_extract_contact_name(array $detail): string
{
    $address = $detail['address'] ?? [];
    if (!empty($address['name'])) {
        return $address['name'];
    }
    if (!empty($address['supplement'])) {
        return $address['supplement'];
    }
    return 'Unbekannt';
}

function _sync_extract_customer_name(array $contact): ?string
{
    if (!empty($contact['company']['name'])) {
        return $contact['company']['name'];
    }
    $person = $contact['person'] ?? [];
    $full = trim(($person['firstName'] ?? '') . ' ' . ($person['lastName'] ?? ''));
    return $full !== '' ? $full : null;
}

function _sync_extract_email(array $contact): ?string
{
    $emails = $contact['emailAddresses'] ?? [];
    foreach (['business', 'office', 'private', 'other'] as $key) {
        if (!empty($emails[$key][0])) {
            return $emails[$key][0];
        }
    }
    return null;
}

function _sync_parse_date($value): ?string
{
    if (!$value || !is_string($value)) {
        return null;
    }
    $date = substr($value, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
}
