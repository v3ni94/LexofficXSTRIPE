<?php
/**
 * Regelautomatik für Einzüge: NUR GERÜST.
 *
 * Die Tabelle collection_rules beschreibt, welche offenen Rechnungen eine
 * Firma künftig regelbasiert einziehen möchte (Kundenkreis, Höchstbetrag,
 * Höchstzahl je Lauf, Vier-Augen-Freigabe). Es gibt bewusst keine
 * Verarbeitung, keinen Cron-Anschluss und keine Oberfläche: Die einzige
 * Funktion ist eine Vorschau, die passende Rechnungen auflistet, ohne etwas
 * einzureichen. Freigabe der Automatik nur nach den Kriterien in
 * docs/payment-safety.md (Abschnitt Regelautomatik).
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Regel laden (mandantensicher) oder null. */
function collection_rule_load(string $tenantId, string $ruleId): ?array
{
    $stmt = db()->prepare('SELECT * FROM collection_rules WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$ruleId, $tenantId]);
    $rule = $stmt->fetch();
    if ($rule) {
        $rule['customer_ids'] = json_decode((string)($rule['customer_ids_json'] ?? '[]'), true) ?: [];
    }
    return $rule ?: null;
}

/**
 * Vorschau: Rechnungen, die diese Regel heute einreichen WÜRDE. Reiner
 * Lesezugriff, es wird nichts eingereicht, terminiert oder verändert.
 *
 * Kriterien: offen oder überfällig in Lexware Office, nicht im Einzug,
 * SEPA-Einzug beim Kunden gewünscht, aktive IBAN vorhanden, aktives Mandat
 * mit erfasstem Nachweis (sofern die Firma ihn verlangt), Fälligkeit ab
 * start_date, Betrag höchstens max_amount_cents, höchstens max_per_run
 * Rechnungen (älteste Fälligkeit zuerst). Rechnungen mit frischem
 * Restbetrag 0 werden ausgeschlossen.
 *
 * @return array{rule:array,invoices:array,excluded:int,note:string}
 */
function collection_rules_preview(string $tenantId, string $ruleId): array
{
    $rule = collection_rule_load($tenantId, $ruleId);
    if (!$rule) {
        throw new RuntimeException('Regel nicht gefunden.');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT require_signed_mandate FROM organizations WHERE id = ?');
    $stmt->execute([$tenantId]);
    $requireSigned = (int)$stmt->fetchColumn() === 1;

    $sql = "SELECT i.id, i.voucher_number, i.contact_name, i.total_gross_amount, i.open_amount, i.open_amount_fetched_at,
                   i.due_date, i.customer_id, c.customer_number, m.mandate_reference, m.signed_date
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            JOIN sepa_mandates m ON m.tenant_id = i.tenant_id AND m.customer_id = c.id AND m.is_active = 1 AND m.status = 'active'
            WHERE i.tenant_id = ?
              AND i.lexoffice_status IN ('open', 'overdue')
              AND i.collection_status NOT IN ('in_collection', 'scheduled', 'collected')
              AND c.sepa_debit_enabled = 1 AND c.is_walk_in = 0
              AND EXISTS (SELECT 1 FROM customer_ibans ci WHERE ci.customer_id = c.id AND ci.is_active = 1)";
    $params = [$tenantId];
    if (!empty($rule['start_date'])) {
        $sql .= ' AND (i.due_date IS NULL OR i.due_date >= ?)';
        $params[] = $rule['start_date'];
    }
    if ($rule['max_amount_cents'] !== null) {
        $sql .= ' AND i.total_gross_amount <= ?';
        $params[] = number_format(((int)$rule['max_amount_cents']) / 100, 2, '.', '');
    }
    if (($rule['customer_scope'] ?? 'selected') === 'selected') {
        $ids = array_values(array_filter(array_map('strval', $rule['customer_ids'])));
        if (!$ids) {
            return ['rule' => $rule, 'invoices' => [], 'excluded' => 0, 'note' => 'Regel ohne ausgewählte Kunden: keine Treffer.'];
        }
        $sql .= ' AND c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    $sql .= ' ORDER BY i.due_date IS NULL, i.due_date ASC, i.voucher_number ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    $excluded = 0;
    $max = $rule['max_per_run'] !== null ? max(0, (int)$rule['max_per_run']) : PHP_INT_MAX;
    foreach ($stmt->fetchAll() as $row) {
        if ($requireSigned && empty($row['signed_date'])) {
            $excluded++;
            continue;
        }
        if ($row['open_amount'] !== null && $row['open_amount_fetched_at']
            && strtotime((string)$row['open_amount_fetched_at']) >= time() - 24 * 3600
            && (float)$row['open_amount'] <= 0) {
            $excluded++;
            continue;
        }
        if (count($result) >= $max) {
            $excluded++;
            continue;
        }
        $result[] = $row;
    }
    $note = (int)$rule['is_active'] === 1
        ? 'Hinweis: Auch aktive Regeln werden derzeit nicht automatisch verarbeitet (Gerüst).'
        : 'Regel ist inaktiv. Vorschau ohne Einreichung.';
    if ((int)$rule['require_second_approval'] === 1) {
        $note .= ' Jeder Lauf würde eine zweite Freigabe erfordern.';
    }
    return ['rule' => $rule, 'invoices' => $result, 'excluded' => $excluded, 'note' => $note];
}
