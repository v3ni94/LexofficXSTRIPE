<?php
/**
 * SEPA-Lastschriftmandat als Druckansicht (A4, "Drucken / Als PDF speichern").
 * Zahlungsempfänger ist die jeweilige Firma; die Mandatsreferenz wurde vom
 * Portal vergeben. Fehlende Angaben werden als Platzhalter angezeigt.
 * Bei der Hausverwaltung Müller GmbH wird deren CI (Kennlinie, Pflichtangaben)
 * verwendet, sonst ein neutraler Aufbau.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/iban.php';
require_once __DIR__ . '/app/mandates.php';

$ctx = require_subscription();
$tenantId = $ctx['org_id'];
$pdo = db();

$mandate = mandate_load($tenantId, (string)($_GET['mandate'] ?? ''));
if (!$mandate) {
    flash_set('error', 'Mandat nicht gefunden.');
    redirect('customers.php');
}
$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND tenant_id = ?');
$stmt->execute([$mandate['customer_id'], $tenantId]);
$customer = $stmt->fetch();
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$tenantId]);
$org = $stmt->fetch();
$iban = null;
if ($mandate['customer_iban_id']) {
    $stmt = $pdo->prepare('SELECT * FROM customer_ibans WHERE id = ?');
    $stmt->execute([$mandate['customer_iban_id']]);
    $iban = $stmt->fetch() ?: null;
}
$texts = mandate_texts($org);
$useHvm = (int)$org['use_hvm_ci'] === 1;
$ph = fn(?string $v, string $label) => ($v !== null && trim($v) !== '') ? e($v) : '<span class="ph">[' . e($label) . ']</span>';
$ibanFormatted = $iban ? format_iban($iban['iban']) : '';
$ibanBoxes = $iban ? str_split(str_replace(' ', '', $ibanFormatted)) : array_fill(0, 22, '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>SEPA-Lastschriftmandat <?= e($mandate['mandate_reference']) ?></title>
<style>
    :root { --ink: #1A1A1A; --muted: #6b6c6e; --line: #9C9D9F; --accent: <?= $useHvm ? '#E6A83C' : '#2E2D2E' ?>; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: <?= $useHvm ? 'system-ui, "Helvetica Neue", Arial, sans-serif' : "Carlito, Calibri, 'Segoe UI', sans-serif" ?>; color: var(--ink); background: #f0f0f0; font-size: 11pt; }
    .toolbar { background: #fff; border-bottom: 1px solid #ddd; padding: 10px 16px; display: flex; gap: 12px; align-items: center; font-size: 14px; }
    .toolbar button, .toolbar a { padding: 8px 14px; border-radius: 6px; border: 1px solid var(--accent); background: var(--accent); color: <?= $useHvm ? '#1A1A1A' : '#fff' ?>; font-weight: 700; cursor: pointer; text-decoration: none; font-family: inherit; }
    .toolbar a.secondary { background: #fff; color: var(--ink); border-color: var(--line); font-weight: 600; }
    .page { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 18mm 20mm 20mm 25mm; position: relative; }
    .kennlinie { height: 4px; background: linear-gradient(to right, #87888A 0%, #87888A 40%, #9C9D9F 40%, #9C9D9F 60%, #E6A83C 60%, #E6A83C 67.5%, #D7D8DA 67.5%, #D7D8DA 100%); margin: -18mm -20mm 10mm -25mm; }
    h1 { font-size: 17pt; margin: 0 0 2mm; }
    .sub { color: var(--muted); font-size: 9.5pt; margin: 0 0 8mm; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm 10mm; margin-bottom: 6mm; }
    .box { border: 1px solid var(--line); padding: 3mm 4mm; border-radius: 2px; }
    .box h2 { font-size: 9pt; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin: 0 0 2mm; font-weight: 700; }
    .box .big { font-size: 12.5pt; font-weight: 700; }
    .field { margin: 2.5mm 0; }
    .field label { display: block; font-size: 8.5pt; color: var(--muted); }
    .line { border-bottom: 1px solid var(--ink); min-height: 7mm; display: block; padding-top: 1.5mm; }
    .boxes { display: flex; gap: 1.2mm; flex-wrap: wrap; margin-top: 1.5mm; }
    .boxes span { width: 6.2mm; height: 8mm; border: 1px solid var(--ink); display: inline-flex; align-items: center; justify-content: center; font-family: "Courier New", monospace; font-size: 11pt; }
    .boxes span:nth-child(4n) { margin-right: 1.5mm; }
    .text { font-size: 10pt; line-height: 1.45; margin: 3mm 0; text-align: justify; }
    .check { display: inline-block; width: 4mm; height: 4mm; border: 1px solid var(--ink); vertical-align: middle; margin-right: 2mm; text-align: center; line-height: 3.6mm; font-size: 9pt; }
    .sig { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 12mm; }
    .sig .line { min-height: 12mm; }
    .ph { color: #a12622; font-weight: 700; }
    .small { font-size: 8.5pt; color: var(--muted); }
    .footer { position: absolute; left: 25mm; right: 20mm; bottom: 12mm; font-size: 8pt; color: var(--muted); border-top: 1px solid var(--line); padding-top: 2mm; display: flex; justify-content: space-between; gap: 6mm; }
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .page { margin: 0; width: auto; min-height: auto; padding: 14mm 18mm 18mm 22mm; }
        .kennlinie { margin: -14mm -18mm 8mm -22mm; }
        @page { size: A4; margin: 0; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Drucken / Als PDF speichern</button>
    <a class="secondary" href="customer.php?id=<?= e($customer['id']) ?>">Zurück zum Kunden</a>
    <span class="small">Mandatsreferenz <?= e($mandate['mandate_reference']) ?> · erzeugt aus <?= e((string)config('product_name', 'Lexware-Einzug')) ?>. Vor dem ersten Einsatz den Wortlaut mit Ihrer Rechtsberatung und den aktuellen Vorgaben Ihres Zahlungsdienstleisters abstimmen.</span>
</div>

<div class="page">
    <?php if ($useHvm): ?><div class="kennlinie"></div><?php endif; ?>
    <h1>SEPA-Lastschriftmandat</h1>
    <p class="sub">SEPA-Basislastschrift · <?= $mandate['mandate_type'] === 'one_off' ? 'Einmalige Zahlung' : 'Wiederkehrende Zahlungen' ?></p>

    <div class="grid">
        <div class="box">
            <h2>Zahlungsempfänger (Gläubiger)</h2>
            <div class="big"><?= e($org['name']) ?></div>
            <div><?= $ph($org['street'] ?? null, 'Straße und Hausnummer') ?></div>
            <div><?= $ph(trim(($org['zip'] ?? '') . ' ' . ($org['city'] ?? '')) ?: null, 'PLZ und Ort') ?><?= ($org['country'] ?? 'DE') !== 'DE' ? ', ' . e($org['country']) : '' ?></div>
            <div class="field"><label>Gläubiger-Identifikationsnummer</label>
                <span class="big"><?= $ph($mandate['creditor_identifier'] ?: ($org['creditor_identifier'] ?? null), 'Gläubiger-ID eintragen') ?></span></div>
        </div>
        <div class="box">
            <h2>Mandatsreferenz</h2>
            <div class="big"><?= e($mandate['mandate_reference']) ?></div>
            <div class="small">Vom Zahlungsempfänger vergeben. Bitte bei Rückfragen angeben.</div>
            <div class="field"><label>Zahlungsart</label>
                <span class="check"><?= $mandate['mandate_type'] === 'one_off' ? '' : 'X' ?></span>Wiederkehrende Zahlungen&nbsp;&nbsp;&nbsp;
                <span class="check"><?= $mandate['mandate_type'] === 'one_off' ? 'X' : '' ?></span>Einmalige Zahlung</div>
        </div>
    </div>

    <div class="box">
        <h2>Zahlungspflichtiger (Kontoinhaber)</h2>
        <div class="field"><label>Name, Vorname / Firma</label><span class="line"><?= e($iban ? $iban['account_holder_name'] : $customer['name']) ?></span></div>
        <div class="field"><label>Straße und Hausnummer</label><span class="line"></span></div>
        <div class="field"><label>PLZ und Ort</label><span class="line"></span></div>
        <div class="field"><label>Kundennummer beim Zahlungsempfänger</label><span class="line"><?= e($customer['customer_number']) ?></span></div>
        <div class="field"><label>IBAN</label>
            <div class="boxes"><?php foreach ($ibanBoxes as $ch): ?><span><?= e($ch) ?></span><?php endforeach; ?></div>
        </div>
        <div class="field"><label>BIC (optional)</label><span class="line"><?= e($iban && $iban['bic'] ? $iban['bic'] : '') ?></span></div>
    </div>

    <p class="text"><?= e($texts['authorization']) ?></p>
    <p class="text"><?= e($texts['refund']) ?></p>
    <p class="text"><?= e($texts['psp']) ?></p>
    <p class="text small"><?= e($texts['prenotification']) ?> <?= e($texts['expiry']) ?></p>

    <div class="sig">
        <div class="field"><label>Ort, Datum</label><span class="line"><?= $mandate['signed_place'] ? e($mandate['signed_place'] . ', ' . format_date($mandate['signed_date'])) : '' ?></span></div>
        <div class="field"><label>Unterschrift des Zahlungspflichtigen</label><span class="line"></span></div>
    </div>

    <div class="footer">
        <span><?= $useHvm
            ? 'Hausverwaltung Müller GmbH | Rheinpromenade 13 | 40789 Monheim am Rhein | Amtsgericht Düsseldorf, HRB 104762 | Geschäftsführer: Timo Müller | www.muellerhv.de'
            : e($org['name']) . ' · ' . e(trim(($org['street'] ?? '') . ', ' . ($org['zip'] ?? '') . ' ' . ($org['city'] ?? ''), ' ,')) ?></span>
        <span>Mandat <?= e($mandate['mandate_reference']) ?> · Stand <?= date('d.m.Y') ?></span>
    </div>
</div>
</body>
</html>
