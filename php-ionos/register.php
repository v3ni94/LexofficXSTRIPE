<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (!config('allow_registration')) {
    flash_set('info', 'Die Registrierung ist deaktiviert. Bitte an den Administrator wenden.');
    redirect('login.php');
}
if (current_user()) {
    redirect('dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $error = auth_register(
        $_POST['email'] ?? '',
        $_POST['password'] ?? '',
        $_POST['org_name'] ?? '',
        trim($_POST['display_name'] ?? '') ?: null,
        $_POST['mandate_prefix'] ?? ''
    );
    if ($error === null) {
        redirect('onboarding.php');
    }
}

layout_header('Registrieren');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Registrieren</h1>
        <p class="auth-sub">Neue Organisation im SEPA-Portal anlegen</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="register.php">
            <?= csrf_field() ?>
            <label for="org_name">Organisation / Gesellschaft</label>
            <input type="text" id="org_name" name="org_name" required
                   value="<?= e($_POST['org_name'] ?? '') ?>">
            <label for="mandate_prefix">Mandatspräfix (2-10 Zeichen, z.B. "HVM" oder "TM")</label>
            <input type="text" id="mandate_prefix" name="mandate_prefix" required maxlength="10"
                   pattern="[A-Za-z0-9]{2,10}" style="text-transform: uppercase;"
                   value="<?= e($_POST['mandate_prefix'] ?? '') ?>">
            <p class="hint">Wird als Anfang der SEPA-Mandatsreferenz Ihrer Kunden verwendet
                (z.B. "HVM10045"). Nach der Einrichtung nicht mehr änderbar.</p>
            <label for="display_name">Ihr Name (optional)</label>
            <input type="text" id="display_name" name="display_name"
                   value="<?= e($_POST['display_name'] ?? '') ?>">
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email" required
                   value="<?= e($_POST['email'] ?? '') ?>">
            <label for="password">Passwort (mindestens 10 Zeichen)</label>
            <input type="password" id="password" name="password" required minlength="10">
            <button type="submit" class="btn">Organisation anlegen</button>
        </form>
        <p class="auth-links"><a href="login.php">Zurück zur Anmeldung</a></p>
    </div>
</div>
<?php layout_footer(); ?>
