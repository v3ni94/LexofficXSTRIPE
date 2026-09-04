<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $error = password_reset_complete($token, $_POST['password'] ?? '');
        if ($error === null) {
            auth_logout();
            session_start();
            flash_set('success', 'Ihr Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.');
            redirect('login.php');
        }
    }
}

layout_header('Neues Passwort festlegen');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Neues Passwort festlegen</h1>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (!preg_match('/^[a-f0-9]{64}$/', $token)): ?>
            <p class="auth-sub">Der Link ist ungültig.</p>
        <?php else: ?>
        <form method="post" action="reset-password.php">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="password">Neues Passwort (mindestens 10 Zeichen)</label>
            <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password" autofocus>
            <label for="password2">Neues Passwort wiederholen</label>
            <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">
            <button type="submit" class="btn">Passwort speichern</button>
        </form>
        <?php endif; ?>
        <p class="auth-links"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
</div>
<?php layout_footer(); ?>
