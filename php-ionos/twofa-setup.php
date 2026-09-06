<?php
/**
 * Verpflichtende Einrichtung der Zwei-Faktor-Authentifizierung.
 *
 * Schritt 1: QR-Code scannen (oder Schlüssel manuell eingeben) und Code bestätigen.
 * Schritt 2: Recovery-Codes anzeigen, Speicherung bestätigen.
 * Erst danach ist die Anwendung nutzbar (require_login leitet sonst hierher).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();
$user = user_load($ctx['user_id']);

// Bereits eingerichtet und keine Codes mehr anzuzeigen: zurück
if ((int)$user['totp_enabled'] === 1 && empty($_SESSION['recovery_codes_show'])) {
    redirect((int)$ctx['onboarding_completed'] ? 'dashboard.php' : 'onboarding.php');
}

$error = null;
$step = !empty($_SESSION['recovery_codes_show']) ? 2 : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_code' && $step === 1) {
        $codes = twofa_confirm_setup($user, $_POST['code'] ?? '', !empty($_POST['remember_device']));
        if ($codes === null) {
            $error = 'Der Code ist ungültig. Bitte prüfen Sie die Uhrzeit Ihres Geräts und versuchen Sie es erneut.';
        } else {
            $_SESSION['recovery_codes_show'] = $codes;
            funnel_event_for_org($ctx['org_id'], '2fa_enabled', $ctx['user_id']);
            security_notify_user($user, 'Zwei-Faktor-Authentifizierung eingerichtet', [
                'Für Ihr Konto wurde soeben die Zwei-Faktor-Authentifizierung mit einer Authenticator-App eingerichtet.',
            ]);
            redirect('twofa-setup.php');
        }
    } elseif ($action === 'codes_saved' && $step === 2) {
        if (empty($_POST['confirm'])) {
            $error = 'Bitte bestätigen Sie, dass Sie die Recovery-Codes sicher gespeichert haben.';
        } else {
            unset($_SESSION['recovery_codes_show']);
            flash_set('success', 'Zwei-Faktor-Authentifizierung ist aktiv. Recovery-Codes wurden als gespeichert bestätigt.');
            redirect((int)$ctx['onboarding_completed'] ? 'dashboard.php' : 'onboarding.php');
        }
    }
}

$secret = $step === 1 ? twofa_begin_setup() : '';
$uri = $step === 1 ? twofa_setup_uri($secret, $user['email']) : '';
$head = $step === 1
    ? '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>'
    : '';

layout_header('Zwei-Faktor-Authentifizierung einrichten', $ctx, ['head' => $head]);
?>
<div class="auth-wrap auth-wide">
    <div class="card">
        <h1 class="auth-title">Zwei-Faktor-Authentifizierung einrichten</h1>
        <p class="auth-sub">Jeder Zugang zu <?= e(product_name()) ?> ist zwingend mit einem zweiten Faktor gesichert.
            Ohne diesen Schritt kann die Anwendung nicht genutzt werden.</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <ol class="setup-steps">
            <li><strong>Authenticator-App öffnen</strong> (z.B. Microsoft Authenticator, Google Authenticator, Aegis, 1Password).</li>
            <li><strong>QR-Code scannen</strong> oder den Schlüssel manuell eingeben.
                <div id="qr" class="qr-box" aria-label="QR-Code für die Authenticator-App"></div>
                <div class="hint">Manuelle Eingabe (Zeitbasiert, 6 Stellen, 30 Sekunden):
                    <code class="copy"><?= e(implode(' ', str_split($secret, 4))) ?></code></div>
            </li>
            <li><strong>6-stelligen Code eingeben</strong>, den die App jetzt anzeigt.</li>
        </ol>
        <form method="post" action="twofa-setup.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_code">
            <label for="code">Code aus der App</label>
            <input type="text" id="code" name="code" class="code-input" required autofocus
                   inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" maxlength="8">
            <?php if (devices_available()): ?>
            <label class="checkbox-label" style="margin-top: 10px;">
                <input type="checkbox" name="remember_device" value="1">
                <span>Dieses Gerät für 90 Tage merken</span>
            </label>
            <p class="hint">Auf diesem Gerät und in diesem Browser müssen Sie bei der Anmeldung 90 Tage lang keinen zusätzlichen 2FA-Code eingeben.
                Ihr Passwort bleibt erforderlich. Bei sicherheitsrelevanten Änderungen kann eine erneute Bestätigung notwendig sein.
                Aktivieren Sie diese Option nur auf einem eigenen, nicht gemeinsam genutzten Gerät.</p>
            <?php endif; ?>
            <button type="submit" class="btn">Code überprüfen</button>
        </form>
        <script>
            (function () {
                var el = document.getElementById('qr');
                if (window.QRCode && el) {
                    new QRCode(el, { text: <?= json_encode($uri, JSON_UNESCAPED_SLASHES) ?>, width: 196, height: 196, correctLevel: QRCode.CorrectLevel.M });
                } else if (el) {
                    el.textContent = 'QR-Code konnte nicht geladen werden. Bitte den Schlüssel manuell eingeben.';
                }
            })();
        </script>

        <?php else: ?>
        <h2>Recovery-Codes</h2>
        <p>Diese Codes ersetzen die Authenticator-App, falls Sie Ihr Gerät verlieren. Jeder Code ist genau einmal
            gültig. Sie werden nur jetzt angezeigt. Bitte ausdrucken oder in einem Passwort-Manager speichern.</p>
        <div class="recovery-codes">
            <?php foreach ($_SESSION['recovery_codes_show'] as $c): ?>
                <code><?= e($c) ?></code>
            <?php endforeach; ?>
        </div>
        <form method="post" action="twofa-setup.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="codes_saved">
            <label class="checkbox-label">
                <input type="checkbox" name="confirm" value="1" required>
                Recovery-Codes sicher gespeichert.
            </label>
            <button type="submit" class="btn">Weiter</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer($ctx); ?>
