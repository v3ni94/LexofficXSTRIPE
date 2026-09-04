<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/mailer.php';

if (current_user()) {
    redirect('security.php');
}

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $last = (int)($_SESSION['pw_reset_requested_at'] ?? 0);
    if (time() - $last >= 30) {
        $_SESSION['pw_reset_requested_at'] = time();
        password_reset_request($_POST['email'] ?? '');
    }
    $sent = true;
}

layout_header('Passwort vergessen');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Passwort vergessen</h1>
        <?php if (!mail_enabled()): ?>
            <p class="auth-sub">Der E-Mail-Versand ist auf dieser Installation nicht aktiv. Bitte wenden Sie sich an
                den Inhaber Ihres Firmenaccounts oder an den Betreiber (siehe Impressum).</p>
        <?php elseif ($sent): ?>
            <p class="auth-sub">Falls zu dieser E-Mail-Adresse ein Konto existiert, haben wir einen Link zum
                Zurücksetzen gesendet. Der Link ist eine Stunde gültig.</p>
        <?php else: ?>
            <p class="auth-sub">Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link zum Festlegen eines neuen
                Passworts. Ihre Zwei-Faktor-Authentifizierung bleibt bestehen.</p>
            <form method="post" action="forgot-password.php">
                <?= csrf_field() ?>
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" name="email" required autofocus autocomplete="username">
                <button type="submit" class="btn">Link anfordern</button>
            </form>
        <?php endif; ?>
        <p class="auth-links"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
</div>
<?php layout_footer(); ?>
