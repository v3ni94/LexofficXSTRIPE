<?php
/**
 * Einladung annehmen: Bestehende Nutzer treten der Organisation direkt bei,
 * neue Nutzer legen ein Konto an.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$pdo = db();
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

$stmt = $pdo->prepare(
    "SELECT inv.*, o.name AS org_name FROM invitations inv
     JOIN organizations o ON o.id = inv.organization_id
     WHERE inv.token = ? AND inv.status = 'pending' AND inv.expires_at > NOW()"
);
$stmt->execute([$token]);
$invitation = $stmt->fetch();

if (!$invitation) {
    layout_header('Einladung');
    echo '<div class="auth-wrap"><div class="card"><h1 class="auth-title">Einladung ungültig</h1>'
       . '<p class="auth-sub">Diese Einladung ist abgelaufen oder wurde bereits verwendet.</p>'
       . '<p class="auth-links"><a href="login.php">Zur Anmeldung</a></p></div></div>';
    layout_footer();
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Existiert bereits ein Konto mit der eingeladenen E-Mail?
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$invitation['email']]);
    $user = $stmt->fetch();

    try {
        if ($user) {
            // Bestehendes Konto: Passwort prüfen
            if (!password_verify($_POST['password'] ?? '', $user['password_hash'])) {
                throw new RuntimeException('Passwort ist falsch.');
            }
            $userId = $user['id'];
        } else {
            // Neues Konto anlegen
            $password = $_POST['password'] ?? '';
            if (strlen($password) < 10) {
                throw new RuntimeException('Das Passwort muss mindestens 10 Zeichen lang sein.');
            }
            $userId = uuid4();
            $pdo->prepare('INSERT INTO users (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)')
                ->execute([
                    $userId, $invitation['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    trim($_POST['display_name'] ?? '') ?: null,
                ]);
        }

        // Mitgliedschaft anlegen (falls nicht schon vorhanden)
        $stmt = $pdo->prepare(
            'SELECT 1 FROM organization_members WHERE organization_id = ? AND user_id = ?'
        );
        $stmt->execute([$invitation['organization_id'], $userId]);
        if (!$stmt->fetch()) {
            $pdo->prepare(
                'INSERT INTO organization_members (id, organization_id, user_id, role) VALUES (?, ?, ?, ?)'
            )->execute([uuid4(), $invitation['organization_id'], $userId, $invitation['role']]);
        }

        $pdo->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ?")
            ->execute([$invitation['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['org_id']  = $invitation['organization_id'];

        flash_set('success', 'Willkommen bei ' . $invitation['org_name'] . '.');
        redirect('dashboard.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Existiert das Konto schon? (steuert das Formular)
$stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
$stmt->execute([$invitation['email']]);
$accountExists = (bool)$stmt->fetch();

layout_header('Einladung annehmen');
?>
<div class="auth-wrap">
    <div class="card">
        <h1 class="auth-title">Einladung annehmen</h1>
        <p class="auth-sub">Sie wurden zu <strong><?= e($invitation['org_name']) ?></strong>
            als <?= e(role_label($invitation['role'])) ?> eingeladen
            (<?= e($invitation['email']) ?>).</p>
        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php if ($accountExists): ?>
                <label for="password">Passwort Ihres bestehenden Kontos</label>
                <input type="password" id="password" name="password" required>
            <?php else: ?>
                <label for="display_name">Ihr Name (optional)</label>
                <input type="text" id="display_name" name="display_name">
                <label for="password">Passwort wählen (mindestens 10 Zeichen)</label>
                <input type="password" id="password" name="password" required minlength="10">
            <?php endif; ?>
            <button type="submit" class="btn">Beitreten</button>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
