<?php
/**
 * Kunden-Einstellungen, die von mehreren Seiten aus geändert werden können
 * (customers.php, customer.php, invoices.php, sepa-pflegen.php).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/audit.php';

/**
 * SEPA-Einzug für einen Kunden ein- oder ausschalten. Wirkt auf alle
 * Datensätze mit derselben Kundennummer (und damit auf alle Rechnungen
 * dieses Kunden).
 *
 * @return array{customer_number:string}
 */
function set_customer_sepa_debit(string $tenantId, string $customerId, bool $enabled, ?array $actor = null): array
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

    audit_log($tenantId, $actor, 'sepa_toggle', 'customer', $customerId, [
        'customer_number' => $customer['customer_number'], 'enabled' => $enabled,
    ]);

    return ['customer_number' => $customer['customer_number']];
}

/**
 * Neue aktive IBAN für einen Kunden hinterlegen (bisherige aktive IBAN wird
 * deaktiviert, Historie wird geschrieben). Setzt bei Nicht-Laufkunden
 * automatisch sepa_debit_enabled = 1 und registriert die Zahlungsmethode
 * direkt bei Stripe (ohne Zahlung auszulösen), falls Stripe verbunden ist.
 *
 * @return array{iban:string,stripe_registered:bool,stripe_reason:?string,iban_id:string}
 */
function set_customer_iban(
    string $tenantId,
    string $customerId,
    string $userId,
    string $ibanRaw,
    string $holderRaw,
    ?string $bicRaw,
    ?array $actor = null
): array {
    require_once __DIR__ . '/iban.php';
    require_once __DIR__ . '/collections.php';

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
    if ($bic !== null && $bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
        throw new RuntimeException('BIC hat ein ungültiges Format (8 oder 11 Zeichen).');
    }
    $bic = $bic ?: null;

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

    audit_log($tenantId, $actor ?? ['user_id' => $userId], 'iban_saved', 'customer', $customerId, [
        'customer_number' => $customer['customer_number'], 'iban_masked' => mask_iban($iban),
    ]);

    // Vorhandenes Mandat (z.B. Entwurf aus dem Mandatsdokument) an die neue IBAN
    // binden bzw. Mandat anlegen. Unabhängig davon, ob Stripe verbunden ist.
    if (!(int)$customer['is_walk_in']) {
        require_once __DIR__ . '/mandates.php';
        try {
            get_or_create_mandate($tenantId, $customerId, $newId, $userId);
        } catch (Throwable $e) {
            error_log('Mandat konnte nicht an IBAN gebunden werden: ' . $e->getMessage());
        }
    }

    // Direkt bei Stripe registrieren (Zahlungsmethode anlegen, keine Zahlung).
    // Absichtlich NACH dem Commit und ohne die IBAN-Speicherung fehlschlagen
    // zu lassen, falls Stripe nicht verbunden oder nicht erreichbar ist.
    $stripeResult = ['registered' => false, 'reason' => 'Laufkunde (Sammel-Kundennummer)'];
    if (!(int)$customer['is_walk_in']) {
        try {
            $stripeResult = register_iban_with_stripe($tenantId, $customerId, $newId);
        } catch (Throwable $e) {
            $stripeResult = ['registered' => false, 'reason' => $e->getMessage()];
        }
    }

    return [
        'iban' => $iban,
        'iban_id' => $newId,
        'stripe_registered' => $stripeResult['registered'],
        'stripe_reason' => $stripeResult['reason'],
    ];
}

/** IBAN deaktivieren (Historie, Audit). */
function deactivate_customer_iban(string $tenantId, string $customerId, string $ibanId, string $userId, ?array $actor = null): void
{
    require_once __DIR__ . '/iban.php';
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE id = ? AND tenant_id = ? AND customer_id = ?');
    $stmt->execute([$ibanId, $tenantId, $customerId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('IBAN nicht gefunden.');
    }
    $pdo->prepare('UPDATE customer_ibans SET is_active = 0 WHERE id = ?')->execute([$ibanId]);
    $pdo->prepare(
        'INSERT INTO iban_history (id, tenant_id, customer_iban_id, action, old_iban, changed_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([uuid4(), $tenantId, $ibanId, 'deactivated', $row['iban'], $userId]);
    audit_log($tenantId, $actor ?? ['user_id' => $userId], 'iban_deactivated', 'customer', $customerId, [
        'iban_masked' => mask_iban($row['iban']),
    ]);
}
