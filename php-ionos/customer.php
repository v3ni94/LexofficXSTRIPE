<?php
/**
 * Kundendetails: Stammdaten, SEPA-Einzug ja/nein, Bankverbindung, SEPA-Mandate
 * (Dokument erzeugen, Unterschrift erfassen, widerrufen), offene Rechnungen
 * und Einzugshistorie dieses Kunden.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/iban.php';
require_once __DIR__ . '/app/customer_settings.php';
require_once __DIR__ . '/app/mandates.php';
require_once __DIR__ . '/app/mandate_files.php';

$ctx = require_subscription();
$tenantId = $ctx['org_id'];
$pdo = db();

$customerId = (string)($_GET['id'] ?? ($_POST['customer_id'] ?? ''));
$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
$stmt->execute([$customerId, $tenantId]);
$customer = $stmt->fetch();
if (!$customer) {
    flash_set('error', 'Kunde nicht gefunden.');
    redirect('customers.php');
}
$isWalkIn = (int)$customer['is_walk_in'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'set_sepa_debit') {
            $enabled = ($_POST['sepa_debit_enabled'] ?? '1') === '1';
            set_customer_sepa_debit($tenantId, $customerId, $enabled, $ctx);
            flash_set('success', 'SEPA-Einzug auf "' . ($enabled ? 'Ja' : 'Nein') . '" gesetzt (gilt für alle Rechnungen mit dieser Kundennummer).');

        } elseif ($action === 'add_iban') {
            $result = set_customer_iban(
                $tenantId, $customerId, $ctx['user_id'],
                $_POST['iban'] ?? '', $_POST['account_holder_name'] ?? '', $_POST['bic'] ?? null, $ctx
            );
            $msg = 'IBAN hinterlegt.';
            $msg .= $result['stripe_registered']
                ? ' Zahlungsmethode wurde bei Stripe registriert.'
                : ' Stripe-Registrierung erfolgt beim ersten Einzug' . ($result['stripe_reason'] ? ' (' . $result['stripe_reason'] . ')' : '') . '.';
            flash_set('success', $msg);

        } elseif ($action === 'deactivate_iban') {
            deactivate_customer_iban($tenantId, $customerId, $_POST['iban_id'] ?? '', $ctx['user_id'], $ctx);
            flash_set('success', 'IBAN deaktiviert.');

        } elseif ($action === 'create_mandate') {
            if ($isWalkIn) {
                throw new RuntimeException('Für Laufkunden (Sammel-Kundennummer) kann kein personenbezogenes Mandat erzeugt werden.');
            }
            $stmt = $pdo->prepare('SELECT id FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$customerId, $tenantId]);
            $ibanId = $stmt->fetchColumn() ?: null;
            // Ausdrückliche Neuanlage über die Kundendetails (auch nach Widerruf/Verfall zulässig)
            $mandate = get_or_create_mandate($tenantId, $customerId, $ibanId ?: null, $ctx['user_id']);
            mandate_mark_document_generated($mandate['id'], $ctx['user_id']);
            audit_log($tenantId, $ctx, 'mandate_document', 'mandate', $mandate['id'], [
                'mandate_reference' => $mandate['mandate_reference'], 'customer_number' => $customer['customer_number'],
            ]);
            redirect('mandate-print.php?mandate=' . urlencode($mandate['id']));

        } elseif ($action === 'mark_signed') {
            $mandate = mandate_mark_signed($tenantId, $_POST['mandate_id'] ?? '', $_POST['signed_date'] ?? '', $_POST['signed_place'] ?? '');
            audit_log($tenantId, $ctx, 'mandate_signed', 'mandate', $mandate['id'], [
                'mandate_reference' => $mandate['mandate_reference'], 'signed_date' => $mandate['signed_date'],
            ]);
            flash_set('success', 'Unterschrift erfasst. Das Mandat ' . $mandate['mandate_reference'] . ' ist einsatzbereit.');

        } elseif ($action === 'upload_mandate_file') {
            flash_set('success', mandate_file_handle_upload($ctx, $customerId, $_POST, $_FILES));

        } elseif ($action === 'delete_mandate_file') {
            if (!can_manage_settings($ctx)) {
                throw new RuntimeException('Mandatsdokumente dürfen nur Inhaber und Administratoren löschen.');
            }
            $file = mandate_file_delete($tenantId, (string)($_POST['file_id'] ?? ''));
            audit_log($tenantId, $ctx, 'mandate_file_deleted', 'mandate_file', $file['id'], ['name' => $file['original_name'], 'customer_id' => $customerId]);
            flash_set('success', 'Mandatsdokument "' . $file['original_name'] . '" gelöscht.');

        } elseif ($action === 'cancel_mandate') {
            $mandate = mandate_load($tenantId, $_POST['mandate_id'] ?? '');
            if (!$mandate) {
                throw new RuntimeException('Mandat nicht gefunden.');
            }
            mandate_cancel($tenantId, $mandate['id'], $_POST['reason'] ?? '');
            audit_log($tenantId, $ctx, 'mandate_cancelled', 'mandate', $mandate['id'], [
                'mandate_reference' => $mandate['mandate_reference'], 'reason' => trim($_POST['reason'] ?? ''),
            ]);
            flash_set('success', 'Mandat ' . $mandate['mandate_reference'] . ' widerrufen. Es bleibt zur Aufbewahrung gespeichert.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('customer.php?id=' . urlencode($customerId));
}

$stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE customer_id = ? AND tenant_id = ? ORDER BY is_active DESC, created_at DESC');
$stmt->execute([$customerId, $tenantId]);
$ibans = $stmt->fetchAll();
$activeIban = null;
foreach ($ibans as $ib) {
    if ((int)$ib['is_active']) {
        $activeIban = $ib;
        break;
    }
}

$mandates = mandates_for_customer($tenantId, $customerId);
$mandateFiles = mandate_files_for_customer($tenantId, $customerId);

$stmt = $pdo->prepare(
    "SELECT * FROM invoices WHERE customer_id = ? AND tenant_id = ? ORDER BY lexoffice_status IN ('open','overdue') DESC, due_date DESC LIMIT 100"
);
$stmt->execute([$customerId, $tenantId]);
$invoices = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT pc.*, i.voucher_number, u.email AS created_by_email FROM payment_collections pc
     JOIN invoices i ON i.id = pc.invoice_id
     LEFT JOIN users u ON u.id = pc.created_by_user_id
     WHERE i.customer_id = ? AND pc.tenant_id = ? ORDER BY pc.created_at DESC LIMIT 50'
);
$stmt->execute([$customerId, $tenantId]);
$collections = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$tenantId]);
$org = $stmt->fetch();
$missing = mandate_document_missing($org, $customer);

layout_header('Kunde ' . $customer['name'], $ctx);
?>
<p class="breadcrumb"><a href="customers.php">Kunden</a> › <?= e($customer['name']) ?></p>
<h1><?= e($customer['name']) ?></h1>
<p class="page-sub">Kundennummer <?= e($customer['customer_number']) ?><?= $isWalkIn ? ' · Laufkunde (Sammel-Kundennummer)' : '' ?>
    · E-Mail: <?= e($customer['email'] ?: 'nicht hinterlegt') ?></p>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= $isWalkIn ? '-' : ((int)$customer['sepa_debit_enabled'] ? 'Ja' : 'Nein') ?></div>
        <div class="stat-label">SEPA-Einzug gewünscht</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= $activeIban ? e(mask_iban($activeIban['iban'])) : 'Fehlt' ?></div>
        <div class="stat-label">Aktive IBAN</div>
    </div>
    <div class="stat-card">
        <?php $act = null; foreach ($mandates as $m) { if ((int)$m['is_active']) { $act = $m; break; } } ?>
        <div class="stat-value" style="font-size: 20px;"><?= $act ? e($act['mandate_reference']) : 'Kein Mandat' ?></div>
        <div class="stat-label">Mandat · <?= $act ? ($act['signed_date'] ? 'unterschrieben am ' . format_date($act['signed_date']) : 'Unterschrift nicht erfasst') : 'noch nicht erzeugt' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 20px;"><?= count($mandateFiles) ?></div>
        <div class="stat-label">Hochgeladene Mandatsdokumente</div>
    </div>
</div>

<?php if (!$isWalkIn): ?>
<div class="card">
    <h2>SEPA-Einzug</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_sepa_debit">
        <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
        <select name="sepa_debit_enabled" style="max-width: 160px;">
            <option value="1" <?= (int)$customer['sepa_debit_enabled'] ? 'selected' : '' ?>>Ja</option>
            <option value="0" <?= !(int)$customer['sepa_debit_enabled'] ? 'selected' : '' ?>>Nein</option>
        </select>
        <button type="submit" class="btn btn-sm">Speichern</button>
        <span class="hint">Gilt für alle Rechnungen mit Kundennummer <?= e($customer['customer_number']) ?>.</span>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>SEPA-Mandate</h2>
    <?php if ($isWalkIn): ?>
        <p class="hint">Für Laufkunden wird kein personenbezogenes Mandatsdokument erzeugt, da die Kundennummer von mehreren Personen geteilt wird.</p>
    <?php else: ?>
        <?php if ($missing): ?>
            <div class="flash flash-info">Für ein vollständiges Mandatsdokument fehlen noch: <?= e(implode('; ', $missing)) ?>.
                Das Dokument kann trotzdem erzeugt werden; fehlende Angaben erscheinen als Platzhalter.</div>
        <?php endif; ?>
        <form method="post" class="inline-form" style="margin-bottom: 16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_mandate">
            <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
            <button type="submit" class="btn"><?= $act ? 'Mandatsdokument anzeigen / drucken' : 'SEPA-Mandat erzeugen (Dokument)' ?></button>
            <span class="hint">Die Mandatsreferenz wird automatisch vergeben (Präfix <?= e($org['mandate_prefix']) ?> + Kundennummer) und im Dokument eingetragen.</span>
        </form>
        <?php if ($mandates): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Referenz</th><th>Status</th><th>Art</th><th>Unterschrift</th><th>IBAN</th><th>Letzte Nutzung</th><th>Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($mandates as $m): ?>
                    <tr>
                        <td><strong><?= e($m['mandate_reference']) ?></strong>
                            <?php if ($m['document_generated_at']): ?><div class="hint">Dokument: <?= format_datetime($m['document_generated_at']) ?></div><?php endif; ?></td>
                        <td><?= status_badge($m['status']) ?><?php if ($m['cancel_reason']): ?><div class="hint"><?= e($m['cancel_reason']) ?></div><?php endif; ?></td>
                        <td><?= $m['mandate_type'] === 'one_off' ? 'Einmalig' : 'Wiederkehrend' ?></td>
                        <td><?= $m['signed_date'] ? format_date($m['signed_date']) . ($m['signed_place'] ? ', ' . e($m['signed_place']) : '') : '<span class="badge badge-warn">nicht erfasst</span>' ?></td>
                        <td><?= $m['iban'] ? e(mask_iban($m['iban'])) : '<span class="hint">offen</span>' ?></td>
                        <td><?= format_datetime($m['last_used_at']) ?></td>
                        <td>
                            <?php if ((int)$m['is_active']): ?>
                                <a class="btn btn-sm btn-secondary" href="mandate-print.php?mandate=<?= e($m['id']) ?>" target="_blank" rel="noopener">Dokument</a>
                                <?php if (!$m['signed_date']): ?>
                                <form method="post" class="inline-form" style="margin-top: 6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_signed">
                                    <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
                                    <input type="hidden" name="mandate_id" value="<?= e($m['id']) ?>">
                                    <input type="date" name="signed_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                                    <input type="text" name="signed_place" required placeholder="Ort" style="max-width: 140px; padding: 5px 8px; font-size: 13px;">
                                    <button type="submit" class="btn btn-sm">Unterschrift erfassen</button>
                                </form>
                                <?php endif; ?>
                                <form method="post" class="inline-form" style="margin-top: 6px;"
                                      onsubmit="return confirm(<?= e(json_encode('Mandat ' . $m['mandate_reference'] . ' wirklich widerrufen? Danach ist ein neues Mandat erforderlich.', JSON_UNESCAPED_UNICODE)) ?>)">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_mandate">
                                    <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
                                    <input type="hidden" name="mandate_id" value="<?= e($m['id']) ?>">
                                    <input type="text" name="reason" placeholder="Grund (optional)" style="max-width: 160px; padding: 5px 8px; font-size: 13px;">
                                    <button type="submit" class="btn btn-sm btn-danger">Widerrufen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <p class="hint">Regeln: Mandatsreferenz max. 35 Zeichen, je Firma eindeutig. Ein Mandat erlischt nach 36 Monaten ohne Einzug.
            Unterschriebene Mandate sind aufzubewahren (Empfehlung mindestens 36 Monate nach der letzten Nutzung); das Portal löscht Mandate nie, sondern widerruft sie.
            <?= (int)$org['require_signed_mandate'] ? 'Einzüge sind erst nach erfasster Unterschrift möglich.' : 'Einzüge sind ohne erfasste Unterschrift möglich (Einstellung unter "Firma").' ?></p>
    <?php endif; ?>
</div>

<?php if (!$isWalkIn): ?>
<div class="card" id="mandatsdokumente">
    <h2>Unterschriebenes Mandat hochladen</h2>
    <p class="hint">Scan oder Foto des vom Kunden unterschriebenen SEPA-Mandats (PDF, JPG oder PNG, bis 10 MB). Die Datei wird dem Kunden
        <?= $act ? 'und dem aktiven Mandat ' . e($act['mandate_reference']) : '' ?> zugeordnet und außerhalb des Webzugriffs gespeichert.
        Sie ersetzt nicht die Aufbewahrungspflicht des Originals, erleichtert aber Nachweis und Prüfung.</p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_mandate_file">
        <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= MANDATE_FILE_MAX_BYTES ?>">
        <label for="mandate_file">Datei (PDF, JPG, PNG)</label>
        <input type="file" id="mandate_file" name="mandate_file" required accept="application/pdf,image/jpeg,image/png">
        <?php $unsigned = array_values(array_filter($mandates, fn($m) => (int)$m['is_active'] === 1)); ?>
        <?php if (count($unsigned) > 1): ?>
        <label for="mandate_id">Zuordnen zu Mandat</label>
        <select id="mandate_id" name="mandate_id">
            <?php foreach ($unsigned as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['mandate_reference']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label for="note">Notiz (optional)</label>
        <input type="text" id="note" name="note" maxlength="255" placeholder="z. B. per Post erhalten am ...">
        <?php if ($act && !$act['signed_date']): ?>
        <div class="inline-form" style="margin-top: 10px; align-items: center; gap: 10px; flex-wrap: wrap;">
            <label style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
                <input type="checkbox" name="mark_signed" value="1" checked> Unterschrift gleich erfassen
            </label>
            <input type="date" name="signed_date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="max-width: 170px;">
            <input type="text" name="signed_place" placeholder="Ort der Unterschrift" style="max-width: 200px;">
        </div>
        <p class="hint">Mit erfasster Unterschrift ist das Mandat <?= e($act['mandate_reference']) ?> sofort für Einzüge nutzbar.</p>
        <?php endif; ?>
        <div class="form-actions"><button type="submit" class="btn">Dokument hochladen</button></div>
    </form>

    <?php if ($mandateFiles): ?>
    <div class="table-wrap" style="margin-top: 18px;">
        <table>
            <thead><tr><th>Datei</th><th>Mandat</th><th>Typ</th><th class="num">Größe</th><th>Hochgeladen</th><th>Von</th><th>Notiz</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mandateFiles as $f): ?>
                <tr>
                    <td><a href="mandate-file.php?id=<?= e($f['id']) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a></td>
                    <td><?= $f['mandate_reference'] ? e($f['mandate_reference']) : '<span class="hint">ohne Zuordnung</span>' ?></td>
                    <td><?= e(MANDATE_FILE_TYPES[$f['mime_type']] ?? $f['mime_type']) ?></td>
                    <td class="num"><?= e(format_bytes((int)$f['size_bytes'])) ?></td>
                    <td><?= format_datetime($f['created_at']) ?></td>
                    <td class="hint"><?= e($f['uploaded_by_email'] ?: '-') ?></td>
                    <td class="hint"><?= e($f['note'] ?: '') ?></td>
                    <td>
                        <a class="btn btn-sm btn-secondary" href="mandate-file.php?id=<?= e($f['id']) ?>&amp;download=1">Herunterladen</a>
                        <?php if (can_manage_settings($ctx)): ?>
                        <form method="post" class="inline-form" style="margin-top: 6px;"
                              onsubmit="return confirm(<?= e(json_encode('Dokument "' . $f['original_name'] . '" wirklich löschen?', JSON_UNESCAPED_UNICODE)) ?>)">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_mandate_file">
                            <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
                            <input type="hidden" name="file_id" value="<?= e($f['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Bankverbindung</h2>
    <?php if ($ibans): ?>
    <div class="table-wrap" style="margin-bottom: 16px;">
        <table>
            <thead><tr><th>IBAN</th><th>BIC</th><th>Kontoinhaber</th><th>Status</th><th>Seit</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ibans as $ib): ?>
                <tr>
                    <td><?= e(mask_iban($ib['iban'])) ?></td>
                    <td><?= e($ib['bic'] ?: '-') ?></td>
                    <td><?= e($ib['account_holder_name']) ?></td>
                    <td><?= (int)$ib['is_active'] ? '<span class="badge badge-success">Aktiv</span>' : '<span class="badge badge-neutral">Inaktiv</span>' ?></td>
                    <td><?= format_date($ib['created_at']) ?></td>
                    <td>
                        <?php if ((int)$ib['is_active']): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('IBAN wirklich deaktivieren?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="deactivate_iban">
                            <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
                            <input type="hidden" name="iban_id" value="<?= e($ib['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Deaktivieren</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_iban">
        <input type="hidden" name="customer_id" value="<?= e($customerId) ?>">
        <label for="iban">Neue IBAN</label>
        <input type="text" id="iban" name="iban" required placeholder="DE89 3704 0044 0532 0130 00">
        <label for="holder">Kontoinhaber</label>
        <input type="text" id="holder" name="account_holder_name" required value="<?= e($customer['name']) ?>">
        <label for="bic">BIC (optional)</label>
        <input type="text" id="bic" name="bic" maxlength="11">
        <p class="hint">Das Hinterlegen einer IBAN setzt SEPA-Einzug auf "Ja", registriert die Zahlungsmethode bei Stripe und bindet ein vorhandenes Mandat an diese IBAN.</p>
        <div class="form-actions"><button type="submit" class="btn">IBAN speichern</button></div>
    </form>
</div>

<div class="card">
    <h2>Rechnungen</h2>
    <?php if (!$invoices): ?><p class="hint">Keine Rechnungen synchronisiert.</p><?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nr.</th><th class="num">Betrag</th><th>Fällig</th><th>Lexware Office</th><th>Einzug</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= e($inv['voucher_number']) ?></td>
                    <td class="num"><?= format_eur($inv['total_gross_amount']) ?></td>
                    <td><?= format_date($inv['due_date']) ?></td>
                    <td><?= e(lexoffice_status_label($inv['lexoffice_status'])) ?></td>
                    <td><?= status_badge($inv['collection_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint"><a href="invoices.php">Zu den Rechnungen (Einziehen / Terminieren)</a></p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Einzüge</h2>
    <?php if (!$collections): ?><p class="hint">Noch keine Einzüge.</p><?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Rechnung</th><th class="num">Betrag</th><th>Status</th><th>Eingereicht</th><th>Ausgelöst von</th></tr></thead>
            <tbody>
            <?php foreach ($collections as $c): ?>
                <tr>
                    <td><?= e($c['voucher_number']) ?></td>
                    <td class="num"><?= format_eur_cents((int)$c['amount_cents']) ?></td>
                    <td><?= status_badge((string)$c['stripe_status'], $c['scheduled_date']) ?></td>
                    <td><?= format_datetime($c['submitted_at']) ?></td>
                    <td class="hint"><?= e($c['created_by_email'] ?: 'System/Cron') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
