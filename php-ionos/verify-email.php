<?php
/**
 * E-Mail-Adresse bestätigen (nur relevant, wenn der Mailversand aktiv ist).
 * Mit ?token=... wird der Link eingelöst; ohne Token wird der Hinweis
 * angezeigt und die Mail kann erneut angefordert werden.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/mailer.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token !== '') {
    $user = email_verification_consume($token);
    if ($user) {
        flash_set('success', 'Ihre E-Mail-Adresse ist bestätigt.');
        redirect(current_user() ? 'dashboard.php' : 'login.php');
    }
    flash_set('error', 'Der Bestätigungslink ist ungültig oder abgelaufen.');
}

$ctx = require_login();
$user = user_load($ctx['user_id']);
if (!mail_enabled() || !empty($user['email_verified_at'])) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $last = (int)($_SESSION['verify_mail_sent_at'] ?? 0);
    if (time() - $last < 60) {
        flash_set('info', 'Bitte warten Sie eine Minute, bevor Sie die E-Mail erneut anfordern.');
    } else {
        $_SESSION['verify_mail_sent_at'] = time();
        flash_set(email_verification_send($user) ? 'success' : 'error',
            email_verification_send($user) ? 'Bestätigungs-E-Mail wurde erneut gesendet.' : 'E-Mail konnte nicht gesendet werden.');
    }
    redirect('verify-email.php');
}

layout_header('E-Mail-Adresse bestätigen', $ctx);
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">E-Mail-Adresse bestätigen</h1>
        <p class="auth-sub">Wir haben einen Bestätigungslink an <strong><?= e($user['email']) ?></strong> gesendet.
            Bitte klicken Sie auf den Link in der E-Mail, um fortzufahren.</p>
        <form method="post" action="verify-email.php">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">E-Mail erneut senden</button>
        </form>
        <p class="auth-links"><a href="logout.php">Abmelden</a></p>
    </div>
</div>
<?php layout_footer($ctx); ?>
