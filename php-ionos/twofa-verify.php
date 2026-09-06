<?php
/**
 * Zweite Anmeldestufe: 6-stelliger Code aus der Authenticator-App oder ein
 * Recovery-Code. Erreichbar nur nach erfolgreicher Passwortprüfung.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}
$pending = pending_2fa_user();
if (!$pending) {
    flash_set('info', 'Bitte melden Sie sich erneut an.');
    redirect('login.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['pending_2fa']);
        redirect('login.php');
    }
    $result = auth_login_2fa($_POST['code'] ?? '');
    if ($result['status'] === 'ok') {
        if ($result['used_recovery']) {
            flash_set('info', 'Sie haben sich mit einem Recovery-Code angemeldet. Jeder Code ist nur einmal gültig. Prüfen Sie unter "Sicherheit", wie viele Codes verbleiben.');
        }
        redirect(post_login_target());
    }
    $error = $result['message'];
    if (!pending_2fa_user()) {
        flash_set('error', $error);
        redirect('login.php');
    }
}

layout_header('Bestätigungscode');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Bestätigungscode</h1>
        <p class="auth-sub">Angemeldet als <?= e($pending['email']) ?></p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="twofa-verify.php">
            <?= csrf_field() ?>
            <label for="code">Code aus der Authenticator-App</label>
            <input type="text" id="code" name="code" class="code-input" required autofocus
                   inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" maxlength="20">
            <p class="hint">Kein Zugriff auf die App? Geben Sie hier einen Ihrer Recovery-Codes ein (Format XXXX-XXXX-XXXX).</p>
            <button type="submit" class="btn">Anmelden</button>
        </form>
        <form method="post" action="twofa-verify.php" style="margin-top: 12px; text-align: center;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="linklike">Abbrechen und zurück zur Anmeldung</button>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
