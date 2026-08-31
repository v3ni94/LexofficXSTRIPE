<?php
/**
 * Schneller Abgleich: Welche Rechnungsnummern zeigt Lexoffice aktuell als
 * offen/überfällig, welche stehen lokal als offen/überfällig? Nutzt nur die
 * Voucherliste (Nummer + Status, keine Einzelabrufe), daher auch bei
 * mehreren hundert Rechnungen in wenigen Sekunden fertig.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/crypto.php';
require_once __DIR__ . '/app/lexoffice.php';

$ctx = require_role(['owner', 'admin']);
$tenantId = $ctx['org_id'];
$pdo = db();

$error = null;
$lexList = null; // ['voucherNumber' => lexoffice_invoice_id, ...]
$lexSet = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $stmt = $pdo->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $integration = $stmt->fetch();
        if (!$integration || !(int)$integration['lexoffice_connected']) {
            throw new RuntimeException('Lexoffice ist nicht verbunden.');
        }
        $apiKey = decrypt_value($integration['lexoffice_api_key_encrypted']);
        if (!$apiKey) {
            throw new RuntimeException('Lexoffice API-Key fehlt.');
        }

        @set_time_limit(60);
        $lex = new LexofficeClient($apiKey);
        $lexList = [];
        foreach (['open', 'overdue'] as $status) {
            $page = 0;
            while (true) {
                $data = $lex->getInvoiceVouchersPage($status, $page);
                foreach ($data['content'] ?? [] as $voucher) {
                    if (!empty($voucher['id'])) {
                        $lexList[$voucher['id']] = $voucher['voucherNumber'] ?? $voucher['id'];
                    }
                }
                $totalPages = (int)($data['totalPages'] ?? 1);
                $page++;
                if ($page >= $totalPages) {
                    break;
                }
            }
        }
        $lexSet = array_keys($lexList);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Lokaler Stand
$stmt = $pdo->prepare(
    "SELECT lexoffice_invoice_id, voucher_number, total_gross_amount
     FROM invoices WHERE tenant_id = ? AND lexoffice_status IN ('open','overdue')"
);
$stmt->execute([$tenantId]);
$localRows = $stmt->fetchAll();
$localSet = array_column($localRows, 'lexoffice_invoice_id');
$localByLexId = array_combine($localSet, $localRows);

$localCount = count($localRows);
$localSum = 0.0;
foreach ($localRows as $r) {
    $localSum += (float)$r['total_gross_amount'];
}

$missingLocally = [];   // in Lexoffice, aber nicht lokal als offen/ueberfaellig
$staleLocally = [];     // lokal offen/ueberfaellig, aber laut Lexoffice-Liste nicht (mehr) offen

if ($lexList !== null) {
    foreach ($lexList as $lexId => $voucherNumber) {
        if (!isset($localByLexId[$lexId])) {
            $missingLocally[] = $voucherNumber;
        }
    }
    foreach ($localRows as $r) {
        if (!in_array($r['lexoffice_invoice_id'], $lexSet, true)) {
            $staleLocally[] = $r;
        }
    }
}

layout_header('Abgleich', $ctx);
?>
<h1>Abgleich mit Lexoffice</h1>
<p class="page-sub">Vergleicht die Rechnungsnummern aus Lexoffice (aktuell offen/überfällig) mit dem
    lokalen Datenbestand. Prüft nur Nummern und Status, keine Beträge (dafür wäre ein Einzelabruf je
    Rechnung nötig, das würde zu lange dauern).</p>

<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Jetzt mit Lexoffice abgleichen</button>
    </form>

    <?php if ($error): ?>
        <div class="flash flash-error" style="margin-top: 16px;">Fehler: <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($lexList !== null): ?>
    <div class="card-grid" style="margin-top: 20px;">
        <div class="stat-card">
            <div class="stat-value"><?= count($lexList) ?></div>
            <div class="stat-label">Offene Posten laut Lexoffice (gerade abgerufen)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $localCount ?></div>
            <div class="stat-label">Offene Rechnungen im Portal</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= format_eur((string)$localSum) ?></div>
            <div class="stat-label">Bruttosumme im Portal (nur zum Vergleich mit Ihrer Lexoffice-Anzeige)</div>
        </div>
    </div>

    <?php if (!$missingLocally && !$staleLocally): ?>
        <p class="flash flash-success" style="margin-top: 16px;">
            Kein Unterschied gefunden: Alle Rechnungsnummern stimmen überein.</p>
    <?php else: ?>

        <?php if ($missingLocally): ?>
        <h2 style="margin-top: 24px;">In Lexoffice offen, aber nicht im Portal
            (<?= count($missingLocally) ?>)</h2>
        <p class="hint">Diese Rechnungen fehlen lokal oder haben einen anderen Status.
            Empfehlung: auf "Rechnungen" erneut synchronisieren.</p>
        <p><?= e(implode(', ', array_slice($missingLocally, 0, 100))) ?>
            <?= count($missingLocally) > 100 ? ' … und weitere' : '' ?></p>
        <?php endif; ?>

        <?php if ($staleLocally): ?>
        <h2 style="margin-top: 24px;">Im Portal offen, laut Lexoffice aber nicht mehr
            (<?= count($staleLocally) ?>)</h2>
        <p class="hint">Diese Rechnungen sind vermutlich zwischenzeitlich bezahlt oder storniert
            worden, das Portal hat es noch nicht mitbekommen. Empfehlung: auf "Rechnungen" erneut
            synchronisieren, das aktualisiert den Status automatisch.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nr.</th><th class="num">Betrag im Portal</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($staleLocally, 0, 100) as $r): ?>
                    <tr>
                        <td><?= e($r['voucher_number']) ?></td>
                        <td class="num"><?= format_eur($r['total_gross_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($staleLocally) > 100): ?><p class="hint">… und weitere.</p><?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>
    <?php endif; ?>
</div>
<?php layout_footer(); ?>
