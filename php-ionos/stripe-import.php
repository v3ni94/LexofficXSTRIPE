<?php
/**
 * Bestehende Einzüge aus Stripe übernehmen (Einmal-Import), siehe app/stripe_import.php.
 * Nur Inhaber und Administratoren. Übernahme mit 2FA-Zweitbestätigung, im
 * Support-Modus gesperrt. Reiner Lesezugriff auf Stripe, kein Geld bewegt sich.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/stripe_import.php';

$ctx = require_login();
if (!can_manage_settings($ctx)) {
    forbidden_page($ctx, 'Nur Inhaber und Administratoren dürfen Einzüge aus Stripe übernehmen.');
}
$tenantId = $ctx['org_id'];
$pdo = db();
$budget = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        support_guard();
        if ($action === 'start') {
            $months = (int)($_POST['months'] ?? 6);
            if (!in_array($months, [3, 6, 12, 24], true)) {
                throw new RuntimeException('Bitte einen Zeitraum von 3, 6, 12 oder 24 Monaten wählen.');
            }
            $stripe = _get_stripe_client($tenantId); // prüft, dass Stripe verbunden ist
            $import = stripe_import_start($tenantId, $months, $ctx);
            $st = stripe_import_fetch($import, $stripe, $budget);
            flash_set($st['done'] ? 'success' : 'info', $st['done']
                ? sprintf('%d Zahlung(en) aus Stripe geladen. Bitte die Vorschau prüfen.', $st['items'])
                : sprintf('%d Zahlung(en) geladen, es gibt weitere Seiten. Bitte "Weiter laden" klicken.', $st['items']));
        } elseif ($action === 'continue') {
            $import = stripe_import_load($tenantId, (string)($_POST['import_id'] ?? ''));
            if (!$import || $import['status'] !== 'loading') {
                throw new RuntimeException('Kein Import zum Fortsetzen.');
            }
            $st = stripe_import_fetch($import, _get_stripe_client($tenantId), $budget);
            flash_set($st['done'] ? 'success' : 'info', $st['done'] ? 'Alle Zahlungen geladen. Bitte die Vorschau prüfen.' : sprintf('%d weitere Zahlung(en) geladen, es gibt weitere Seiten.', $st['items']));
        } elseif ($action === 'apply') {
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $import = stripe_import_load($tenantId, (string)($_POST['import_id'] ?? ''));
            if (!$import || $import['status'] !== 'preview') {
                throw new RuntimeException('Kein Import in der Vorschau.');
            }
            $res = stripe_import_apply($import, $ctx);
            flash_set('success', sprintf('%d Einzug/Einzüge aus Stripe übernommen, %d übersprungen, %d mit Erstattung übernommen. Die Rechnungen wurden entsprechend markiert.', $res['imported'], $res['skipped'], $res['refunded']));
        } elseif ($action === 'discard') {
            stripe_import_discard($tenantId, (string)($_POST['import_id'] ?? ''), $ctx);
            flash_set('success', 'Import verworfen. Es wurde nichts übernommen.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('stripe-import.php');
}

$integration = $pdo->prepare('SELECT stripe_connected, stripe_business_name, stripe_mode FROM integrations WHERE tenant_id = ?');
$integration->execute([$tenantId]);
$integration = $integration->fetch() ?: ['stripe_connected' => 0];
$current = stripe_import_current($tenantId);
$items = $current ? stripe_import_items($tenantId, (string)$current['id']) : [];
$summary = $current ? stripe_import_summary($tenantId, (string)$current['id']) : [];
$recent = stripe_import_recent($tenantId, 5);
$matched = $summary['matched'] ?? ['count' => 0, 'cents' => 0];

layout_header('Bestehende Einzüge aus Stripe übernehmen', $ctx);
?>
<h1>Bestehende Einzüge aus Stripe übernehmen</h1>
<p class="page-sub">Einmaliger Abgleich für Lastschriften, die eine frühere Installation über dasselbe Stripe-Konto eingereicht hat · <a href="settings.php">Zurück zu den Einstellungen</a></p>

<div class="card">
    <h2>Wozu dient der Import</h2>
    <p>Wurde die Anwendung neu aufgesetzt oder die Firma neu verknüpft, kennt sie bereits eingereichte Lastschriften nicht. Die betroffenen
        Rechnungen stehen dann als offen und könnten erneut eingezogen werden. Der Import liest die Zahlungen Ihres Stripe-Kontos für den gewählten
        Zeitraum (nur Lesezugriff, nichts wird bei Stripe verändert), ordnet sie über die Rechnungsnummer und den Betrag den Rechnungen zu und
        übernimmt eindeutige Treffer als Einzüge mit Herkunft "Import". Die Rechnung erhält den passenden Status (eingezogen, in Bearbeitung,
        fehlgeschlagen, zurückgebucht). Bereits bekannte Zahlungen werden übersprungen, der Import kann gefahrlos wiederholt werden.</p>
    <p class="hint">Nicht übernommen werden Mandate und IBANs: Stripe gibt die IBAN nicht vollständig heraus. Für neue Einzüge hinterlegen Sie IBAN und Mandat je Kunde unter "SEPA Pflegen".</p>
</div>

<?php if (!(int)$integration['stripe_connected']): ?>
<div class="card"><p class="flash flash-warn" style="margin:0">Stripe ist nicht verbunden. Bitte zuerst unter <a href="settings.php">Einstellungen</a> den Stripe-Schlüssel hinterlegen.</p></div>
<?php elseif (!$current): ?>
<div class="card">
    <h2>Import starten</h2>
    <p class="hint">Konto: <?= e((string)($integration['stripe_business_name'] ?: 'unbekannt')) ?> · Modus: <?= ($integration['stripe_mode'] ?? '') === 'test' ? 'Test' : 'Live' ?>. Der Zeitraum bezieht sich auf das Anlagedatum der Zahlung bei Stripe.</p>
    <form method="post" class="inline-form" style="flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start">
        <label for="months">Zeitraum</label>
        <select id="months" name="months" style="max-width: 220px;">
            <option value="3">letzte 3 Monate</option>
            <option value="6" selected>letzte 6 Monate</option>
            <option value="12">letzte 12 Monate</option>
            <option value="24">letzte 24 Monate</option>
        </select>
        <button type="submit" class="btn">Zahlungen aus Stripe laden (Vorschau)</button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h2><?= $current['status'] === 'loading' ? 'Zahlungen werden geladen' : 'Vorschau' ?></h2>
    <p class="hint">Zeitraum: letzte <?= (int)$current['period_months'] ?> Monate (ab <?= e(date('d.m.Y', strtotime((string)$current['created_gte']))) ?>) · <?= (int)$current['fetched_count'] ?> Zahlung(en) in <?= (int)$current['pages_fetched'] ?> Seite(n) geladen.</p>
    <?php if ($current['last_error']): ?><p class="flash flash-error"><?= e((string)$current['last_error']) ?></p><?php endif; ?>
    <?php if ($current['status'] === 'loading'): ?>
        <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="import_id" value="<?= e($current['id']) ?>">
            <button type="submit" name="action" value="continue" class="btn">Weiter laden</button>
            <button type="submit" name="action" value="discard" class="btn btn-secondary">Abbrechen</button>
        </form>
    <?php else: ?>
        <div class="card-grid" style="margin-top: 12px;">
            <div class="stat-card"><div class="stat-value"><?= (int)$matched['count'] ?></div><div class="stat-label">werden übernommen (<?= format_eur_cents((int)$matched['cents']) ?>)</div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)($summary['already_known']['count'] ?? 0) ?></div><div class="stat-label">bereits bekannt</div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)($summary['invoice_missing']['count'] ?? 0) + (int)($summary['amount_mismatch']['count'] ?? 0) + (int)($summary['invoice_has_collection']['count'] ?? 0) ?></div><div class="stat-label">nicht zuordenbar (nur Anzeige)</div></div>
            <div class="stat-card"><div class="stat-value"><?= (int)($summary['not_ours']['count'] ?? 0) ?></div><div class="stat-label">ohne Rechnungsnummer, ignoriert</div></div>
        </div>
        <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px; margin-bottom: 8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="import_id" value="<?= e($current['id']) ?>">
            <?php if ((int)$matched['count'] > 0): ?>
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" required class="code-input" style="max-width: 160px;">
                <button type="submit" name="action" value="apply" class="btn">Jetzt <?= (int)$matched['count'] ?> Einzug/Einzüge übernehmen</button>
            <?php else: ?>
                <span class="hint">Keine übernehmbaren Zahlungen gefunden.</span>
            <?php endif; ?>
            <button type="submit" name="action" value="discard" class="btn btn-secondary">Verwerfen</button>
        </form>
        <p class="hint">Zweitbestätigung: Die Übernahme ändert den Status von Rechnungen und erfordert den aktuellen Code aus Ihrer Authenticator-App. Bei Stripe ändert sich nichts.</p>
    <?php endif; ?>
</div>

<?php if ($items): ?>
<div class="card">
    <h2>Gefundene Zahlungen (<?= count($items) ?>)</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Datum</th><th>Rechnungsnummer</th><th>Kunde</th><th class="num">Betrag</th><th>Stripe-Status</th><th>Zuordnung</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= e(date('d.m.Y', strtotime((string)$it['stripe_created_at']))) ?></td>
                    <td><?= e((string)($it['voucher_number'] ?? '')) ?><?php if ($it['customer_number']): ?><div class="hint">Kd.-Nr. <?= e($it['customer_number']) ?></div><?php endif; ?></td>
                    <td><?= e((string)($it['contact_name'] ?? '')) ?></td>
                    <td class="num"><?= format_eur_cents((int)$it['amount_cents']) ?><?php if ((int)$it['amount_refunded_cents'] > 0): ?><div class="hint">erstattet <?= format_eur_cents((int)$it['amount_refunded_cents']) ?></div><?php endif; ?></td>
                    <td><?= status_badge(stripe_import_map_status((string)$it['pi_status'], (int)$it['disputed'] === 1)) ?></td>
                    <td><?= $it['match_state'] === 'matched' ? '<span class="badge badge-success">' : '<span class="badge badge-neutral">' ?><?= e(stripe_import_state_label((string)$it['match_state'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($recent): ?>
<div class="card">
    <h2>Bisherige Importläufe</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Datum</th><th>Zeitraum</th><th>Geladen</th><th>Übernommen</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= e(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
                    <td><?= (int)$r['period_months'] ?> Monate</td>
                    <td><?= (int)$r['fetched_count'] ?></td>
                    <td><?= (int)$r['imported_count'] ?></td>
                    <td><?= e(['loading' => 'lädt', 'preview' => 'Vorschau', 'done' => 'abgeschlossen', 'discarded' => 'verworfen'][$r['status']] ?? $r['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer($ctx); ?>
