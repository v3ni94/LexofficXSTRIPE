<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Einfache Bremse gegen Brute-Force
    $attempts = &$_SESSION['login_attempts'];
    $attempts = ($attempts ?? 0) + 1;
    if ($attempts > 10) {
        sleep(3);
    }

    if (auth_login($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        unset($_SESSION['login_attempts']);
        redirect('dashboard.php');
    }
    $error = 'E-Mail-Adresse oder Passwort ist falsch.';
}

layout_header('Anmelden');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Anmelden</h1>
        <p class="auth-sub">SEPA-Portal der Hausverwaltung Müller GmbH</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="login.php">
            <?= csrf_field() ?>
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= e($_POST['email'] ?? '') ?>">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required>
            <button type="submit" class="btn">Anmelden</button>
        </form>
        <?php if (config('allow_registration')): ?>
        <p class="auth-links"><a href="register.php">Neue Organisation registrieren</a></p>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
