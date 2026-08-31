<?php
/**
 * SEPA-Mandate: vorhandenes aktives Mandat verwenden oder neues anlegen.
 * Referenzformat: Stammkunde "<Präfix><Kundennummer>", Laufkunde
 * "<Präfix><JJJJMMTT><lfd. Nr.>". Das Präfix ist je Firma (Organisation)
 * individuell (organizations.mandate_prefix), damit z.B. "HVM10045" für
 * die Hausverwaltung Müller GmbH und "TM10045" für eine andere Firma im
 * selben Portal entstehen. Portiert aus mandate_service.py.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

function get_or_create_mandate(string $tenantId, string $customerId, string $customerIbanId): array
{
    $pdo = db();

    // 1. Vorhandenes aktives Mandat?
    $stmt = $pdo->prepare(
        'SELECT * FROM sepa_mandates WHERE tenant_id = ? AND customer_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$tenantId, $customerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['customer_iban_id'] !== $customerIbanId) {
            // IBAN gewechselt: Zahlungsmethode muss neu erstellt werden
            $pdo->prepare(
                'UPDATE sepa_mandates SET customer_iban_id = ?, stripe_payment_method_id = NULL WHERE id = ?'
            )->execute([$customerIbanId, $existing['id']]);
            $existing['customer_iban_id'] = $customerIbanId;
            $existing['stripe_payment_method_id'] = null;
        }
        return $existing;
    }

    // 2. Kunde laden für Referenzformat
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden');
    }

    // 2b. Mandatspräfix der Firma laden (z.B. "HVM", "TM")
    $stmt = $pdo->prepare('SELECT mandate_prefix FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $orgPrefix = (string)($stmt->fetchColumn() ?: 'FIRMA');

    // 3. Mandatsreferenz erzeugen
    if (!(int)$customer['is_walk_in']) {
        $mandateRef = $orgPrefix . $customer['customer_number'];
    } else {
        $prefix = $orgPrefix . date('Ymd');
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM sepa_mandates WHERE tenant_id = ? AND mandate_reference LIKE ?'
        );
        $stmt->execute([$tenantId, $prefix . '%']);
        $count = (int)$stmt->fetch()['c'];
        $mandateRef = $prefix . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
    }

    // 4. Neues Mandat anlegen
    $id = uuid4();
    $pdo->prepare(
        'INSERT INTO sepa_mandates
            (id, tenant_id, customer_id, customer_iban_id, mandate_reference, mandate_date, is_active)
         VALUES (?, ?, ?, ?, ?, CURDATE(), 1)'
    )->execute([$id, $tenantId, $customerId, $customerIbanId, $mandateRef]);

    $stmt = $pdo->prepare('SELECT * FROM sepa_mandates WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}
