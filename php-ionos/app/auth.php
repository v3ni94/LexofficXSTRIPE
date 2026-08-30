<?php
/**
 * Authentifizierung und Rollen (Session-basiert).
 * Rollen: owner > admin > member
 */

declare(strict_types=1);

/** Aktuellen Benutzerkontext laden (User + Organisation + Rolle) oder null. */
function current_user(): ?array
{
    static $ctx = null;
    static $loaded = false;

    if ($loaded) {
        return $ctx;
    }
    $loaded = true;

    $userId = $_SESSION['user_id'] ?? null;
    $orgId  = $_SESSION['org_id'] ?? null;
    if (!$userId || !$orgId) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.id AS user_id, u.email, u.display_name,
                o.id AS org_id, o.name AS org_name,
                o.onboarding_completed, o.onboarding_step,
                m.role
         FROM users u
         JOIN organization_members m ON m.user_id = u.id AND m.organization_id = :org
         JOIN organizations o ON o.id = m.organization_id
         WHERE u.id = :uid AND u.is_active = 1'
    );
    $stmt->execute(['uid' => $userId, 'org' => $orgId]);
    $row = $stmt->fetch();
    $ctx = $row ?: null;
    return $ctx;
}

/** Erzwingt Login, leitet sonst auf login.php um. */
function require_login(): array
{
    $ctx = current_user();
    if (!$ctx) {
        redirect('login.php');
    }
    return $ctx;
}

/** Erzwingt Login und leitet zum Onboarding, solange es nicht abgeschlossen ist. */
function require_onboarded(): array
{
    $ctx = require_login();
    if (!(int)$ctx['onboarding_completed']) {
        redirect('onboarding.php');
    }
    return $ctx;
}

/** Erzwingt eine der angegebenen Rollen. */
function require_role(array $roles): array
{
    $ctx = require_login();
    if (!in_array($ctx['role'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/layout.php';
        layout_header('Kein Zugriff', $ctx);
        echo '<div class="card"><h1>Kein Zugriff</h1>'
           . '<p>Diese Funktion steht nur Administratoren zur Verfügung.</p>'
           . '<p><a class="btn" href="dashboard.php">Zurück zum Dashboard</a></p></div>';
        layout_footer();
        exit;
    }
    return $ctx;
}

function can_manage(array $ctx): bool
{
    return in_array($ctx['role'], ['owner', 'admin'], true);
}

/** Login. Gibt true bei Erfolg zurück. */
function auth_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = :email');
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Erste Mitgliedschaft als aktiven Organisationskontext wählen
    $stmt = db()->prepare(
        'SELECT organization_id FROM organization_members WHERE user_id = :uid ORDER BY created_at ASC LIMIT 1'
    );
    $stmt->execute(['uid' => $user['id']]);
    $member = $stmt->fetch();
    if (!$member) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['org_id']  = $member['organization_id'];
    return true;
}

/**
 * Registrierung: legt User, Organisation, Owner-Mitgliedschaft und
 * leeren Integrations-Datensatz an. Gibt Fehlermeldung oder null zurück.
 */
function auth_register(string $email, string $password, string $orgName, ?string $displayName): ?string
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    if (strlen($password) < 10) {
        return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    }
    if (trim($orgName) === '') {
        return 'Bitte einen Organisationsnamen angeben.';
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        return 'Diese E-Mail-Adresse ist bereits registriert.';
    }

    $pdo->beginTransaction();
    try {
        $userId = uuid4();
        $orgId  = uuid4();

        $pdo->prepare('INSERT INTO users (id, email, password_hash, display_name) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $email, password_hash($password, PASSWORD_DEFAULT), $displayName ?: null]);

        $pdo->prepare('INSERT INTO organizations (id, name) VALUES (?, ?)')
            ->execute([$orgId, trim($orgName)]);

        $pdo->prepare('INSERT INTO organization_members (id, organization_id, user_id, role) VALUES (?, ?, ?, ?)')
            ->execute([uuid4(), $orgId, $userId, 'owner']);

        $pdo->prepare('INSERT INTO integrations (id, tenant_id) VALUES (?, ?)')
            ->execute([uuid4(), $orgId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Registrierung fehlgeschlagen: ' . $e->getMessage());
        return 'Registrierung fehlgeschlagen. Bitte später erneut versuchen.';
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['org_id']  = $orgId;
    return null;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
