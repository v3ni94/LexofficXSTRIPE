<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}
signup_attribution_capture();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($result['status'] === '2fa') {
        redirect('twofa-verify.php');
    }
    if ($result['status'] === 'ok') {
        redirect('dashboard.php');
    }
    $error = $result['message'];
}

layout_header('Anmelden');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Anmelden</h1>
        <p class="auth-sub"><?= e(product_name()) ?>: SEPA-Einzug für Lexware Office</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?= csrf_field() ?>
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="username"
                   value="<?= e($_POST['email'] ?? '') ?>">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <button type="submit" class="btn">Weiter</button>
        </form>
        <p class="auth-links">
            <a href="forgot-password.php">Passwort vergessen?</a>
            <?php if (config('allow_registration')): ?>
                · <a href="register.php">Firmenaccount registrieren</a>
            <?php endif; ?>
        </p>
        <p class="hint" style="text-align:center;">Nach dem Passwort folgt die Eingabe des Codes aus Ihrer Authenticator-App.</p>
    </div>
</div>
<?php layout_footer(); ?>
