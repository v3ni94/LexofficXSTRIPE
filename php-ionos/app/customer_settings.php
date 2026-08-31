<?php
/**
 * Kunden-Einstellungen, die von mehreren Seiten aus geändert werden können
 * (customers.php und invoices.php), daher als gemeinsame Funktion ausgelagert.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * SEPA-Einzug für einen Kunden ein- oder ausschalten. Wirkt auf alle
 * Datensätze mit derselben Kundennummer (und damit auf alle Rechnungen
 * dieses Kunden), nicht nur auf den einzelnen Kontakt-Datensatz.
 *
 * @return array{customer_number:string}
 */
function set_customer_sepa_debit(string $tenantId, string $customerId, bool $enabled): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden.');
    }
    if ((int)$customer['is_walk_in']) {
        throw new RuntimeException(
            'Für Laufkunden (Sammel-Kundennummer) kann der SEPA-Einzug hier nicht '
            . 'ein- oder ausgeschaltet werden, da diese Nummer von mehreren Personen geteilt wird.'
        );
    }

    $pdo->prepare(
        'UPDATE customers SET sepa_debit_enabled = ? WHERE tenant_id = ? AND customer_number = ?'
    )->execute([$enabled ? 1 : 0, $tenantId, $customer['customer_number']]);

    return ['customer_number' => $customer['customer_number']];
}
