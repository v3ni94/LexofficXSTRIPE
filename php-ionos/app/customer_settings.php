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

/**
 * Neue aktive IBAN für einen Kunden hinterlegen (bisherige aktive IBAN wird
 * deaktiviert, Historie wird geschrieben). Setzt bei Nicht-Laufkunden
 * automatisch sepa_debit_enabled = 1, da das Hinterlegen einer IBAN den
 * Wunsch nach SEPA-Einzug ausdrückt.
 *
 * @return string die bereinigte, gespeicherte IBAN
 */
function set_customer_iban(
    string $tenantId,
    string $customerId,
    string $userId,
    string $ibanRaw,
    string $holderRaw,
    ?string $bicRaw
): string {
    require_once __DIR__ . '/iban.php';

    [$ok, $result] = validate_iban($ibanRaw);
    if (!$ok) {
        throw new RuntimeException($result);
    }
    $iban = $result;
    $holder = trim($holderRaw);
    if ($holder === '') {
        throw new RuntimeException('Bitte den Kontoinhaber angeben.');
    }
    $bic = $bicRaw ? strtoupper(trim($bicRaw)) : null;

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Kunde nicht gefunden.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1'
        );
        $stmt->execute([$customerId, $tenantId]);
        foreach ($stmt->fetchAll() as $old) {
            $pdo->prepare('UPDATE customer_ibans SET is_active = 0 WHERE id = ?')->execute([$old['id']]);
            $pdo->prepare(
                'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, old_iban, new_iban, changed_by, change_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                uuid4(), $tenantId, $old['id'], 'deactivated', $old['iban'], $iban,
                $userId, 'Ersetzt durch neue IBAN',
            ]);
        }

        $newId = uuid4();
        $pdo->prepare(
            'INSERT INTO customer_ibans (id, tenant_id, customer_id, iban, bic, account_holder_name, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([$newId, $tenantId, $customerId, $iban, $bic, $holder]);
        $pdo->prepare(
            'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, new_iban, changed_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([uuid4(), $tenantId, $newId, 'created', $iban, $userId]);

        if (!(int)$customer['is_walk_in']) {
            $pdo->prepare(
                'UPDATE customers SET sepa_debit_enabled = 1 WHERE tenant_id = ? AND customer_number = ?'
            )->execute([$tenantId, $customer['customer_number']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $iban;
}
