<?php
/**
 * Öffentliche Seite zur digitalen Mandatserteilung (Link aus der E-Mail).
 *
 * Keine Anmeldung, keine Tracking- oder Drittskripte, Referrer-Policy
 * no-referrer, kein Suchmaschinenindex. GET zeigt nur Zahlungsempfänger und
 * Mandatstext; erst ein POST mit CSRF-Token startet die Stripe Checkout
 * Session (Modus setup, SEPA-Lastschrift, keine Zahlung). Damit lösen
 * Link-Scanner von E-Mail-Programmen nichts aus.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/mandate_requests.php';

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; form-action 'self' https://checkout.stripe.com; base-uri 'none'");

$product = product_name();
$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
$done = ($_GET['done'] ?? '') === '1';
$featureOn = mandate_request_feature_enabled();
$req = $featureOn && $token !== '' ? mandate_request_load_by_token($token) : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$req) {
        http_response_code(404);
    } elseif (($_POST['action'] ?? '') === 'start' && ($_POST['accept'] ?? '') === '1') {
        try {
            $url = mandate_request_start_checkout($req, $token);
            header('Location: ' . $url, true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Mandatsanforderung ' . $req['id'] . ': Checkout konnte nicht gestartet werden: ' . $e->getMessage());
            $error = 'Die Weiterleitung zum Zahlungsdienstleister ist derzeit nicht möglich. Bitte versuchen Sie es später erneut oder wenden Sie sich an ' . $req['org_name'] . '.';
        }
    } else {
        $error = 'Bitte bestätigen Sie den Mandatstext, um fortzufahren.';
    }
}

if (!$req) {
    http_response_code(404);
}
$texts = $req ? mandate_texts([
    'name' => $req['org_name'],
    'pre_notification_days' => $req['org_pre_notification_days'],
]) : [];
$title = $req ? 'SEPA-Lastschriftmandat für ' . $req['org_name'] : 'Link ungültig';
$op = (array)config('operator', []);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title) ?> | <?= e($product) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root { --brand-accent: #E3AC48; --brand-dark: #2E2D2E; }
        .mandat-wrap { max-width: 760px; margin: 32px auto; padding: 0 16px; }
        .mandat-text p { margin: 0 0 12px; line-height: 1.5; }
        .mandat-meta dt { font-weight: 600; margin-top: 8px; }
        .mandat-meta dd { margin: 0; }
    </style>
</head>
<body class="theme-product">
<main class="site-main">
<div class="mandat-wrap">
    <p class="hint"><?= e($product) ?> · digitale Mandatserteilung</p>
    <h1><?= e($title) ?></h1>

    <?php if (!$req): ?>
        <div class="card">
            <p>Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet. Bitte wenden Sie sich an den Zahlungsempfänger, der Sie um das Mandat gebeten hat.</p>
        </div>
    <?php elseif ($req['status'] === 'granted' || $done): ?>
        <div class="card">
            <?php if ($req['status'] === 'granted'): ?>
                <p><span class="badge badge-success">Mandat erteilt</span> Vielen Dank. Ihr SEPA-Lastschriftmandat für <?= e($req['org_name']) ?> wurde am <?= format_datetime($req['granted_at']) ?> digital erteilt.</p>
            <?php else: ?>
                <p><span class="badge badge-info">Bestätigung eingegangen</span> Vielen Dank. Die Bestätigung beim Zahlungsdienstleister wird verarbeitet; <?= e($req['org_name']) ?> erhält die Mandatsdaten automatisch.</p>
            <?php endif; ?>
            <p class="hint">Sie können dieses Fenster jetzt schließen.</p>
        </div>
    <?php elseif (!in_array($req['status'], ['requested', 'pending'], true)): ?>
        <div class="card">
            <p>Diese Mandatsanforderung ist nicht mehr gültig (<?= e(mandate_request_status_label($req['status'])) ?>). Bitte wenden Sie sich an <?= e($req['org_name']) ?>.</p>
        </div>
    <?php else: ?>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <div class="card">
            <h2>Zahlungsempfänger</h2>
            <dl class="mandat-meta">
                <dt>Name</dt><dd><?= e($req['org_name']) ?></dd>
                <?php if ($req['org_street'] || $req['org_zip'] || $req['org_city']): ?>
                <dt>Anschrift</dt><dd><?= e(trim($req['org_street'] . ', ' . trim($req['org_zip'] . ' ' . $req['org_city']), ', ')) ?></dd>
                <?php endif; ?>
                <?php if ($req['org_creditor_identifier']): ?>
                <dt>Gläubiger-Identifikationsnummer</dt><dd><?= e($req['org_creditor_identifier']) ?></dd>
                <?php endif; ?>
                <dt>Zahlungspflichtiger</dt><dd><?= e($req['customer_name']) ?> (Kundennummer <?= e($req['customer_number']) ?>)</dd>
                <dt>Zahlungsart</dt><dd>Wiederkehrende Zahlungen (SEPA-Basislastschrift)</dd>
            </dl>
        </div>
        <div class="card mandat-text">
            <h2>SEPA-Lastschriftmandat</h2>
            <p><?= e($texts['authorization']) ?></p>
            <p><?= e($texts['refund']) ?></p>
            <p><?= e($texts['psp']) ?></p>
            <p><?= e($texts['prenotification']) ?></p>
            <p><?= e($texts['expiry']) ?></p>
            <p class="hint">Die Mandatsreferenz wird Ihnen nach der Bestätigung mitgeteilt und erscheint auf der Vorabankündigung. Ihre Bankverbindung geben Sie im nächsten Schritt direkt beim Zahlungsdienstleister Stripe ein; <?= e($req['org_name']) ?> und <?= e($product) ?> erhalten davon nur eine maskierte Fassung.</p>
        </div>
        <div class="card">
            <h2>Hinweis zum Datenschutz</h2>
            <?php
            // Entwurf: Formulierung vor Produktivstart durch Rechtsberatung prüfen lassen
            // (Rollenverteilung Verantwortlicher / Auftragsverarbeiter, Verweis auf Datenschutzerklärung).
            $dsUrl = public_base_url() . '/datenschutz/';
            ?>
            <p class="hint">Verantwortlich für die Verarbeitung Ihrer Daten im Rahmen dieses Mandats ist <?= e($req['org_name']) ?> als Zahlungsempfänger.
                Die Bankverbindung geben Sie direkt beim Zahlungsdienstleister Stripe Payments Europe Ltd. ein, der im Auftrag von <?= e($req['org_name']) ?> tätig wird.
                <?= e($product) ?> verarbeitet die Mandatsdaten im Auftrag des Zahlungsempfängers und erhält Ihre Bankverbindung nur in maskierter Form.
                Eine Weitergabe an sonstige Dritte findet nicht statt. Weitere Informationen zur Plattform finden Sie in der
                <a href="<?= e($dsUrl) ?>" rel="noopener">Datenschutzerklärung</a>.</p>
        </div>
        <div class="card">
            <form method="post" action="mandat.php?t=<?= e($token) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="start">
                <label style="display: inline-flex; align-items: flex-start; gap: 8px;">
                    <input type="checkbox" name="accept" value="1" required style="margin-top: 4px;">
                    <span>Ich habe den Mandatstext gelesen und möchte <?= e($req['org_name']) ?> das SEPA-Lastschriftmandat erteilen. Die Bankverbindung gebe ich im nächsten Schritt bei Stripe ein.</span>
                </label>
                <div class="form-actions"><button type="submit" class="btn">Weiter zur Bestätigung</button></div>
            </form>
            <p class="hint">Der Link ist gültig bis <?= format_datetime($req['expires_at']) ?>. Wenn Sie kein Mandat erteilen möchten, schließen Sie diese Seite einfach.</p>
        </div>
    <?php endif; ?>

    <p class="hint" style="margin-top: 24px;"><?= e($product) ?> ist ein Dienst der <?= e($op['name'] ?? 'Müller Holding AG') ?>, <?= e($op['street'] ?? '') ?>, <?= e($op['zip_city'] ?? '') ?>.
        <a href="impressum.php">Impressum</a>. Diese Seite verwendet keine Analyse- oder Werbedienste.</p>
</div>
</main>
</body>
</html>
