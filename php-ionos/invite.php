<?php
/**
 * Einladung annehmen. Der Link enthält ein zufälliges Token, gespeichert ist
 * nur dessen SHA-256-Hash. Die Einladung ist serverseitig fest an Firma,
 * E-Mail-Adresse und Rolle gebunden und kann nicht für eine andere Firma
 * verwendet werden. Neue Nutzer legen ein persönliches Passwort fest und
 * richten anschließend zwingend 2FA ein; bestehende Nutzer bestätigen mit
 * Passwort (und 2FA-Code, falls eingerichtet).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/mailer.php';

$pdo = db();
$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));

$invitation = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = $pdo->prepare(
        "SELECT inv.*, o.name AS org_name FROM invitations inv
         JOIN organizations o ON o.id = inv.organization_id
         WHERE inv.token = ? AND inv.status = 'pending' AND inv.expires_at > NOW() AND o.deleted_at IS NULL"
    );
    $stmt->execute([token_hash($token)]);
    $invitation = $stmt->fetch();
}

if (!$invitation) {
    layout_header('Einladung');
    echo '<div class="auth-wrap"><div class="card"><h1 class="auth-title">Einladung ungültig</h1>'
       . '<p class="auth-sub">Diese Einladung ist abgelaufen, wurde widerrufen oder bereits verwendet. '
       . 'Bitte den Inhaber Ihres Firmenaccounts um eine neue Einladung bitten.</p>'
       . '<p class="auth-links"><a href="login.php">Zur Anmeldung</a></p></div></div>';
    layout_footer();
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$invitation['email']]);
$existingUser = $stmt->fetch();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if ($msg = login_throttle_check($invitation['email'])) {
            throw new RuntimeException($msg);
        }

        if ($existingUser) {
            if (!password_verify($_POST['password'] ?? '', $existingUser['password_hash'])) {
                login_record($invitation['email'], false, 'password');
                throw new RuntimeException('Passwort ist falsch.');
            }
            if ((int)$existingUser['totp_enabled'] === 1
                && !twofa_verify_user($existingUser, $_POST['code'] ?? '')
                && !recovery_code_consume($existingUser['id'], $_POST['code'] ?? '')) {
                login_record($invitation['email'], false, 'totp');
                throw new RuntimeException('Der Bestätigungscode ist ungültig.');
            }
            $userId = $existingUser['id'];
        } else {
            $password = $_POST['password'] ?? '';
            if ($password !== ($_POST['password2'] ?? '')) {
                throw new RuntimeException('Die Passwörter stimmen nicht überein.');
            }
            if ($err = validate_password($password, $invitation['email'])) {
                throw new RuntimeException($err);
            }
            $firstName = trim((string)($_POST['first_name'] ?? '')) ?: ($invitation['first_name'] ?: null);
            $lastName = trim((string)($_POST['last_name'] ?? '')) ?: ($invitation['last_name'] ?: null);
            $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: null;
            $userId = uuid4();
            $pdo->prepare(
                'INSERT INTO users (id, email, password_hash, display_name, first_name, last_name, email_verified_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $userId, $invitation['email'], password_hash($password, PASSWORD_DEFAULT),
                $displayName, $firstName, $lastName,
            ]);
            // Der Einladungslink wurde an genau diese Adresse gesendet: sie gilt damit als bestätigt.
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM organization_members WHERE organization_id = ? AND user_id = ?');
        $stmt->execute([$invitation['organization_id'], $userId]);
        if ($existing = $stmt->fetch()) {
            $pdo->prepare("UPDATE organization_members SET role = ?, status = 'active' WHERE id = ?")
                ->execute([$invitation['role'], $existing['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO organization_members (id, organization_id, user_id, role, status) VALUES (?, ?, ?, ?, 'active')"
            )->execute([uuid4(), $invitation['organization_id'], $userId, $invitation['role']]);
        }
        $pdo->prepare("UPDATE invitations SET status = 'accepted', accepted_at = NOW(), token = ? WHERE id = ?")
            ->execute([token_hash(bin2hex(random_bytes(32))), $invitation['id']]);
        $pdo->commit();

        login_record($invitation['email'], true, 'password');
        $user = user_load($userId);
        session_finish_login($user, $invitation['organization_id']);

        audit_log($invitation['organization_id'], ['user_id' => $userId, 'email' => $invitation['email']],
            'invite_accepted', 'invitation', $invitation['id'], ['role' => $invitation['role']]);
        security_notify_owner($invitation['organization_id'], 'Einladung angenommen', [
            sprintf('%s hat die Einladung zu %s angenommen und ist jetzt Mitarbeiter des Firmenaccounts.', $invitation['email'], $invitation['org_name']),
        ]);
        if (mail_enabled()) {
            $tpl = mail_tpl_member_joined($invitation['org_name'], $invitation['email']);
            $stmt = $pdo->prepare("SELECT u.email FROM organization_members m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.role = 'owner'");
            $stmt->execute([$invitation['organization_id']]);
            foreach ($stmt->fetchAll() as $o) {
                mail_send($o['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
            }
        }

        flash_set('success', 'Willkommen bei ' . $invitation['org_name'] . '.');
        redirect('dashboard.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

layout_header('Einladung annehmen');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Einladung annehmen</h1>
        <p class="auth-sub">Sie wurden zu <strong><?= e($invitation['org_name']) ?></strong>
            als <?= e(role_label($invitation['role'])) ?> eingeladen. Die Einladung gilt für
            <strong><?= e($invitation['email']) ?></strong>.</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="invite.php">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php if ($existingUser): ?>
                <p class="hint">Zu dieser E-Mail-Adresse existiert bereits ein Konto. Bestätigen Sie den Beitritt mit Ihrem Passwort.</p>
                <label for="password">Passwort Ihres bestehenden Kontos</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
                <?php if ((int)$existingUser['totp_enabled'] === 1): ?>
                    <label for="code">Code aus Ihrer Authenticator-App</label>
                    <input type="text" id="code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code">
                <?php endif; ?>
            <?php else: ?>
                <div class="form-row">
                    <div>
                        <label for="first_name">Vorname</label>
                        <input type="text" id="first_name" name="first_name" maxlength="100"
                               value="<?= e($_POST['first_name'] ?? $invitation['first_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="last_name">Nachname</label>
                        <input type="text" id="last_name" name="last_name" maxlength="100"
                               value="<?= e($_POST['last_name'] ?? $invitation['last_name'] ?? '') ?>">
                    </div>
                </div>
                <label for="password">Persönliches Passwort wählen (mindestens 10 Zeichen)</label>
                <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
                <label for="password2">Passwort wiederholen</label>
                <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">
                <p class="hint">Im nächsten Schritt richten Sie die verpflichtende Zwei-Faktor-Authentifizierung ein.
                    Lexware Office und Stripe müssen Sie nicht erneut verbinden.</p>
            <?php endif; ?>
            <button type="submit" class="btn">Beitreten</button>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
