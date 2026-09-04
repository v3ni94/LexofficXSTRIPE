<?php
/**
 * Authentifizierung, Zwei-Faktor-Authentifizierung (Pflicht), Rollen,
 * Registrierung mit Herkunft, Rate-Limiting und Session-Widerruf.
 *
 * Rollen je Firma:
 *   owner  = Inhaber (registrierende Person). Nur er verwaltet Mitarbeiter,
 *            Rollen, Inhaberschaft, Abonnement und Löschung der Firma.
 *   admin  = wie member, darf zusätzlich die API-Verbindungen (Lexware Office,
 *            Stripe) ändern.
 *   member = voller operativer Zugriff (Sync, Rechnungen, Einzüge, Kunden,
 *            SEPA pflegen), keine Mitglieder-/Abo-Verwaltung.
 *
 * Ablauf Anmeldung: Passwort -> (2FA-Code oder Recovery-Code) -> Session.
 * Ohne eingerichtete 2FA wird der Benutzer nach dem Passwort zwingend zur
 * 2FA-Einrichtung geführt und kann die Anwendung vorher nicht nutzen.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/plans.php';

const LOGIN_MAX_FAILS_EMAIL = 5;    // Fehlversuche je E-Mail in 15 Minuten
const LOGIN_MAX_FAILS_IP    = 30;   // Fehlversuche je IP in 15 Minuten
const LOGIN_LOCK_MINUTES    = 15;
const TWOFA_MAX_FAILS       = 5;    // Codeversuche je Anmeldung
const PENDING_2FA_TTL       = 600;  // Sekunden zwischen Passwort und 2FA-Code

// ---------------------------------------------------------------------------
// Kontext
// ---------------------------------------------------------------------------

/** Aktuellen Benutzerkontext laden (User + Firma + Rolle) oder null. */
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
        'SELECT u.id AS user_id, u.email, u.display_name, u.first_name, u.last_name,
                u.totp_enabled, u.email_verified_at, u.is_superadmin, u.session_epoch, u.last_login_at,
                o.id AS org_id, o.name AS org_name, o.mandate_prefix, o.use_hvm_ci,
                o.onboarding_completed, o.onboarding_step,
                o.plan_code, o.subscription_status, o.subscription_period_end, o.cancel_at_period_end,
                o.billing_exempt, o.signup_domain, o.street, o.zip, o.city, o.country,
                o.creditor_identifier, o.pre_notification_days, o.send_pre_notification,
                o.require_signed_mandate,
                m.role, m.status AS member_status
         FROM users u
         JOIN organization_members m ON m.user_id = u.id AND m.organization_id = :org
         JOIN organizations o ON o.id = m.organization_id
         WHERE u.id = :uid AND u.is_active = 1 AND o.deleted_at IS NULL'
    );
    $stmt->execute(['uid' => $userId, 'org' => $orgId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    // Session-Widerruf: Wurde der Benutzer entfernt/gesperrt oder sein
    // Passwort/2FA zurückgesetzt, ist die Session-Epoche veraltet.
    if ((int)($_SESSION['session_epoch'] ?? -1) !== (int)$row['session_epoch']) {
        auth_logout();
        return null;
    }
    if ($row['member_status'] !== 'active') {
        return null;
    }

    $ctx = $row;
    return $ctx;
}

/** Dateiname des aktuell ausgeführten Skripts (z.B. "login.php"). */
function current_script(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
}

/**
 * Erzwingt Login. Erzwingt außerdem, in dieser Reihenfolge, die
 * E-Mail-Bestätigung (nur bei aktivem Mailversand) und die 2FA-Einrichtung.
 */
function require_login(): array
{
    $ctx = current_user();
    if (!$ctx) {
        redirect('login.php');
    }

    $script = current_script();
    $exempt = ['logout.php', 'twofa-setup.php', 'verify-email.php', 'impressum.php'];

    if (!in_array($script, $exempt, true)) {
        require_once __DIR__ . '/mailer.php';
        if (mail_enabled() && empty($ctx['email_verified_at'])) {
            redirect('verify-email.php');
        }
        if (config('require_2fa', true) && !(int)$ctx['totp_enabled']) {
            redirect('twofa-setup.php');
        }
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

/**
 * Erzwingt Login und ein nutzbares Abonnement der Firma (nur relevant, wenn
 * die Plattform-Abrechnung aktiv ist). Inhaber werden zur Abo-Seite geführt,
 * Mitarbeiter sehen einen Hinweis.
 */
function require_subscription(): array
{
    $ctx = require_login();
    if (!subscription_allows_operation($ctx)) {
        if ($ctx['role'] === 'owner') {
            flash_set('info', 'Bitte schließen Sie zunächst das Abonnement für diese Firma ab.');
            redirect('subscription.php');
        }
        require_once __DIR__ . '/layout.php';
        http_response_code(403);
        layout_header('Abonnement erforderlich', $ctx);
        echo '<div class="card"><h1>Abonnement erforderlich</h1>'
           . '<p>Für diese Firma liegt derzeit kein aktives Abonnement vor. Bitte wenden Sie sich an den '
           . 'Inhaber des Firmenaccounts.</p>'
           . '<p><a class="btn" href="dashboard.php">Zurück zum Dashboard</a></p></div>';
        layout_footer($ctx);
        exit;
    }
    return $ctx;
}

/** Erzwingt eine der angegebenen Rollen. */
function require_role(array $roles): array
{
    $ctx = require_login();
    if (!in_array($ctx['role'], $roles, true)) {
        forbidden_page($ctx, 'Diese Funktion steht nur dem Inhaber bzw. Administratoren der Firma zur Verfügung.');
    }
    return $ctx;
}

/** Nur der Inhaber der Firma. */
function require_owner(): array
{
    $ctx = require_login();
    if ($ctx['role'] !== 'owner') {
        forbidden_page($ctx, 'Diese Funktion steht ausschließlich dem Inhaber des Firmenaccounts zur Verfügung.');
    }
    return $ctx;
}

/** Nur Plattform-Administratoren (users.is_superadmin = 1) mit aktiver 2FA. */
function require_superadmin(): array
{
    $ctx = require_login();
    if (!(int)$ctx['is_superadmin'] || !(int)$ctx['totp_enabled']) {
        forbidden_page($ctx, 'Kein Zugriff.');
    }
    return $ctx;
}

function forbidden_page(array $ctx, string $message): void
{
    http_response_code(403);
    require_once __DIR__ . '/layout.php';
    layout_header('Kein Zugriff', $ctx);
    echo '<div class="card"><h1>Kein Zugriff</h1><p>' . e($message) . '</p>'
       . '<p><a class="btn" href="dashboard.php">Zurück zum Dashboard</a></p></div>';
    layout_footer($ctx);
    exit;
}

function is_owner(array $ctx): bool
{
    return $ctx['role'] === 'owner';
}

/** Mitglieder, Rollen, Inhaberschaft, Abo: nur Inhaber. */
function can_manage_members(array $ctx): bool
{
    return $ctx['role'] === 'owner';
}

/** API-Verbindungen und Firmendaten: Inhaber und Administratoren. */
function can_manage_settings(array $ctx): bool
{
    return in_array($ctx['role'], ['owner', 'admin'], true);
}

/** Abwärtskompatibler Name (Einstellungen). */
function can_manage(array $ctx): bool
{
    return can_manage_settings($ctx);
}

function user_display_name(array $u): string
{
    $name = trim((string)($u['display_name'] ?? ''));
    if ($name === '') {
        $name = trim(trim((string)($u['first_name'] ?? '')) . ' ' . trim((string)($u['last_name'] ?? '')));
    }
    return $name !== '' ? $name : (string)($u['email'] ?? '');
}

// ---------------------------------------------------------------------------
// Passwort-Regeln und Rate-Limiting
// ---------------------------------------------------------------------------

function validate_password(string $password, string $email = ''): ?string
{
    if (strlen($password) < 10) {
        return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    }
    if (strlen($password) > 200) {
        return 'Das Passwort ist zu lang.';
    }
    if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
        return 'Das Passwort darf nicht der E-Mail-Adresse entsprechen.';
    }
    return null;
}

/** Protokolliert einen Anmeldeversuch (E-Mail, IP, Erfolg, Stufe). */
function login_record(string $email, bool $success, string $stage = 'password'): void
{
    try {
        db()->prepare('INSERT INTO login_attempts (email, ip, success, stage) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr(mb_strtolower(trim($email)), 0, 255), client_ip(), $success ? 1 : 0, $stage]);
    } catch (Throwable $e) {
        error_log('login_attempts: ' . $e->getMessage());
    }
}

/** Liefert eine Fehlermeldung, wenn zu viele Fehlversuche vorliegen, sonst null. */
function login_throttle_check(string $email): ?string
{
    $pdo = db();
    $email = mb_strtolower(trim($email));

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE email = ? AND success = 0 AND stage <> 'reset' AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$email, LOGIN_LOCK_MINUTES]);
    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS_EMAIL) {
        return sprintf('Zu viele Fehlversuche. Bitte warten Sie %d Minuten und versuchen Sie es erneut.', LOGIN_LOCK_MINUTES);
    }

    $ip = client_ip();
    if ($ip) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND success = 0 AND stage <> 'reset' AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$ip, LOGIN_LOCK_MINUTES]);
        if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS_IP) {
            return 'Zu viele Anmeldeversuche von Ihrer Verbindung. Bitte später erneut versuchen.';
        }
    }

    $stmt = $pdo->prepare('SELECT locked_until FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $locked = $stmt->fetchColumn();
    if ($locked && new DateTimeImmutable((string)$locked) > new DateTimeImmutable('now')) {
        return 'Dieses Konto ist vorübergehend gesperrt. Bitte später erneut versuchen.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Anmeldung (Stufe 1: Passwort, Stufe 2: 2FA)
// ---------------------------------------------------------------------------

/**
 * Stufe 1: Passwort prüfen.
 * @return array{status:string,message:?string}  status: ok | 2fa | error
 */
function auth_login(string $email, string $password): array
{
    $email = mb_strtolower(trim($email));
    if ($msg = login_throttle_check($email)) {
        return ['status' => 'error', 'message' => $msg];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        login_record($email, false, 'password');
        if ($user) {
            $pdo->prepare(
                'UPDATE users SET failed_login_count = failed_login_count + 1,
                        locked_until = IF(failed_login_count + 1 >= 10, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
                 WHERE id = ?'
            )->execute([LOGIN_LOCK_MINUTES, $user['id']]);
            if ((int)$user['failed_login_count'] + 1 >= 10) {
                audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'login_locked');
            }
        }
        return ['status' => 'error', 'message' => 'E-Mail-Adresse oder Passwort ist falsch.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    login_record($email, true, 'password');

    if ((int)$user['totp_enabled'] === 1) {
        // Stufe 2 erforderlich: Benutzer noch NICHT anmelden
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['user_id' => $user['id'], 'at' => time(), 'fails' => 0];
        return ['status' => '2fa', 'message' => null];
    }

    // Keine 2FA vorhanden: anmelden, require_login() erzwingt die Einrichtung.
    if (!session_finish_login($user)) {
        return ['status' => 'error', 'message' => 'Ihr Zugang ist keiner Firma zugeordnet. Bitte wenden Sie sich an den Inhaber Ihres Firmenaccounts.'];
    }
    return ['status' => 'ok', 'message' => null];
}

/** Benutzer aus der 2FA-Wartestufe laden oder null (abgelaufen / nicht vorhanden). */
function pending_2fa_user(): ?array
{
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || (time() - (int)$p['at']) > PENDING_2FA_TTL) {
        unset($_SESSION['pending_2fa']);
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$p['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['pending_2fa']);
        return null;
    }
    return $user;
}

/**
 * Stufe 2: TOTP-Code oder Recovery-Code prüfen und Anmeldung abschließen.
 * @return array{status:string,message:?string,used_recovery:bool}
 */
function auth_login_2fa(string $code): array
{
    $user = pending_2fa_user();
    if (!$user) {
        return ['status' => 'error', 'message' => 'Die Anmeldung ist abgelaufen. Bitte erneut anmelden.', 'used_recovery' => false];
    }
    if ((int)($_SESSION['pending_2fa']['fails'] ?? 0) >= TWOFA_MAX_FAILS) {
        unset($_SESSION['pending_2fa']);
        login_record($user['email'], false, 'totp');
        audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'login_locked', 'user', $user['id'], ['reason' => '2fa_fails']);
        return ['status' => 'error', 'message' => 'Zu viele falsche Codes. Bitte erneut anmelden.', 'used_recovery' => false];
    }

    $usedRecovery = false;
    $ok = twofa_verify_user($user, $code);
    if (!$ok) {
        $ok = recovery_code_consume($user['id'], $code);
        $usedRecovery = $ok;
    }

    if (!$ok) {
        $_SESSION['pending_2fa']['fails'] = (int)($_SESSION['pending_2fa']['fails'] ?? 0) + 1;
        login_record($user['email'], false, 'totp');
        return ['status' => 'error', 'message' => 'Der Code ist ungültig.', 'used_recovery' => false];
    }

    login_record($user['email'], true, $usedRecovery ? 'recovery' : 'totp');
    unset($_SESSION['pending_2fa']);

    if (!session_finish_login($user)) {
        return ['status' => 'error', 'message' => 'Ihr Zugang ist keiner Firma zugeordnet. Bitte wenden Sie sich an den Inhaber Ihres Firmenaccounts.', 'used_recovery' => $usedRecovery];
    }

    if ($usedRecovery) {
        audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'recovery_code_used', 'user', $user['id']);
        security_notify_user($user, 'Recovery-Code verwendet', [
            'Bei der Anmeldung zu Ihrem Konto wurde soeben ein Recovery-Code anstelle des Authenticator-Codes verwendet.',
            'Falls Sie keinen Zugriff mehr auf Ihre Authenticator-App haben, richten Sie die Zwei-Faktor-Authentifizierung unter "Sicherheit" neu ein.',
        ]);
    }
    return ['status' => 'ok', 'message' => null, 'used_recovery' => $usedRecovery];
}

/**
 * Session nach erfolgreicher Prüfung aller Faktoren aufbauen.
 * Wählt die erste aktive Firmenmitgliedschaft. false, wenn keine vorhanden.
 */
function session_finish_login(array $user, ?string $preferredOrgId = null): bool
{
    $pdo = db();
    $orgId = null;
    if ($preferredOrgId) {
        $stmt = $pdo->prepare(
            "SELECT organization_id FROM organization_members WHERE user_id = ? AND organization_id = ? AND status = 'active'"
        );
        $stmt->execute([$user['id'], $preferredOrgId]);
        $orgId = $stmt->fetchColumn() ?: null;
    }
    if (!$orgId) {
        $stmt = $pdo->prepare(
            "SELECT m.organization_id FROM organization_members m
             JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ? AND m.status = 'active' ORDER BY m.created_at ASC LIMIT 1"
        );
        $stmt->execute([$user['id']]);
        $orgId = $stmt->fetchColumn() ?: null;
    }
    if (!$orgId) {
        return false;
    }

    $pdo->prepare('UPDATE users SET last_login_at = NOW(), failed_login_count = 0, locked_until = NULL WHERE id = ?')
        ->execute([$user['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['org_id'] = $orgId;
    $_SESSION['session_epoch'] = (int)$user['session_epoch'];
    $_SESSION['login_at'] = time();
    unset($_SESSION['csrf_token']);

    audit_log($orgId, ['user_id' => $user['id'], 'email' => $user['email']], 'login_success', 'user', $user['id']);
    return true;
}

/** Alle Sitzungen eines Benutzers ungültig machen (Session-Epoche erhöhen). */
function user_revoke_sessions(string $userId): void
{
    db()->prepare('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?')->execute([$userId]);
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies') && PHP_SAPI !== 'cli') {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ---------------------------------------------------------------------------
// Zwei-Faktor-Authentifizierung
// ---------------------------------------------------------------------------

/** Neues Geheimnis für die Einrichtung erzeugen (bis zur Bestätigung nur in der Session). */
function twofa_begin_setup(): string
{
    if (empty($_SESSION['totp_setup_secret'])) {
        $_SESSION['totp_setup_secret'] = totp_generate_secret();
    }
    return $_SESSION['totp_setup_secret'];
}

/** otpauth-URI für die Authenticator-App. */
function twofa_setup_uri(string $secret, string $email): string
{
    return totp_otpauth_uri((string)config('product_name', 'Lexware-Einzug'), $email, $secret);
}

/**
 * Einrichtung bestätigen: Code gegen das Sitzungsgeheimnis prüfen, Geheimnis
 * verschlüsselt speichern, Recovery-Codes erzeugen (Klartext nur einmal zurück).
 * @return array|null Recovery-Codes im Klartext oder null bei falschem Code
 */
function twofa_confirm_setup(array $user, string $code): ?array
{
    $secret = $_SESSION['totp_setup_secret'] ?? null;
    if (!$secret) {
        return null;
    }
    $step = totp_verify($secret, $code);
    if ($step === false) {
        return null;
    }

    $pdo = db();
    $pdo->prepare(
        'UPDATE users SET totp_secret_encrypted = ?, totp_enabled = 1, totp_confirmed_at = NOW(), totp_last_step = ? WHERE id = ?'
    )->execute([encrypt_value($secret), $step, $user['id']]);
    unset($_SESSION['totp_setup_secret']);

    $codes = recovery_codes_regenerate($user['id'], false);
    audit_log($_SESSION['org_id'] ?? null, ['user_id' => $user['id'], 'email' => $user['email']], '2fa_enabled', 'user', $user['id']);
    return $codes;
}

/** TOTP-Code eines Benutzers prüfen (mit Replay-Schutz). */
function twofa_verify_user(array $user, string $code): bool
{
    if (!(int)($user['totp_enabled'] ?? 0) || empty($user['totp_secret_encrypted'])) {
        return false;
    }
    $secret = decrypt_value($user['totp_secret_encrypted']);
    if (!$secret) {
        return false;
    }
    $lastStep = $user['totp_last_step'] !== null ? (int)$user['totp_last_step'] : null;
    $step = totp_verify($secret, $code, null, 1, $lastStep);
    if ($step === false) {
        return false;
    }
    db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
    return true;
}

/** Passwort UND aktuellen 2FA-/Recovery-Code eines Benutzers prüfen (für kritische Aktionen). */
function verify_password_and_2fa(array $user, string $password, string $code): ?string
{
    if (!password_verify($password, $user['password_hash'])) {
        return 'Das Passwort ist falsch.';
    }
    if ((int)$user['totp_enabled'] === 1) {
        if (!twofa_verify_user($user, $code) && !recovery_code_consume($user['id'], $code)) {
            return 'Der Bestätigungscode ist ungültig.';
        }
    }
    return null;
}

/** Recovery-Codes neu erzeugen (alte werden ungültig). Gibt Klartext-Codes zurück. */
function recovery_codes_regenerate(string $userId, bool $notify = true): array
{
    $pdo = db();
    $codes = recovery_codes_generate(10);
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO user_recovery_codes (id, user_id, code_hash) VALUES (?, ?, ?)');
    foreach ($codes as $c) {
        $ins->execute([uuid4(), $userId, recovery_code_hash($c, (string)config('app_secret'))]);
    }
    if ($notify) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($u = $stmt->fetch()) {
            audit_log($_SESSION['org_id'] ?? null, ['user_id' => $u['id'], 'email' => $u['email']], 'recovery_codes_regenerated', 'user', $u['id']);
            require_once __DIR__ . '/mailer.php';
            if (mail_enabled()) {
                $tpl = mail_tpl_recovery_codes_regenerated(date('d.m.Y H:i'));
                mail_send($u['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
            }
        }
    }
    return $codes;
}

/** Recovery-Code prüfen und verbrauchen (genau einmal verwendbar). */
function recovery_code_consume(string $userId, string $code): bool
{
    $normalized = recovery_code_normalize($code);
    if (!preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $normalized)) {
        return false;
    }
    $hash = recovery_code_hash($normalized, (string)config('app_secret'));
    $stmt = db()->prepare(
        'UPDATE user_recovery_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
    );
    $stmt->execute([$userId, $hash]);
    return $stmt->rowCount() === 1;
}

/** Anzahl noch nicht verwendeter Recovery-Codes. */
function recovery_codes_remaining(string $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * 2FA zurücksetzen (nach Sicherheitsprüfung durch den Aufrufer). Der
 * Benutzer muss 2FA anschließend neu einrichten; alle Sitzungen enden.
 */
function twofa_reset(array $user, bool $byAdmin, ?array $actor = null): void
{
    $pdo = db();
    $pdo->prepare(
        'UPDATE users SET totp_secret_encrypted = NULL, totp_enabled = 0, totp_confirmed_at = NULL, totp_last_step = NULL WHERE id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$user['id']]);
    user_revoke_sessions($user['id']);

    audit_log(
        $_SESSION['org_id'] ?? null,
        $actor ?? ['user_id' => $user['id'], 'email' => $user['email']],
        $byAdmin ? '2fa_admin_reset' : '2fa_reset',
        'user',
        $user['id'],
        ['target_email' => $user['email']]
    );
    require_once __DIR__ . '/mailer.php';
    if (mail_enabled()) {
        $tpl = mail_tpl_2fa_reset(date('d.m.Y H:i'), $byAdmin);
        mail_send($user['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
    }
}

/** Sicherheitsbenachrichtigung an einen Benutzer (nur wenn Mailversand aktiv). */
function security_notify_user(array $user, string $headline, array $lines): void
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled() || empty($user['email'])) {
        return;
    }
    $tpl = mail_tpl_security($headline, $lines);
    mail_send($user['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
}

/** Sicherheitsbenachrichtigung an den Inhaber einer Firma. */
function security_notify_owner(string $tenantId, string $headline, array $lines): void
{
    $stmt = db()->prepare(
        "SELECT u.* FROM organization_members m JOIN users u ON u.id = m.user_id
         WHERE m.organization_id = ? AND m.role = 'owner' LIMIT 1"
    );
    $stmt->execute([$tenantId]);
    if ($owner = $stmt->fetch()) {
        security_notify_user($owner, $headline, $lines);
    }
}

// ---------------------------------------------------------------------------
// E-Mail-Bestätigung und Passwort-Zurücksetzung (Token nur als Hash gespeichert)
// ---------------------------------------------------------------------------

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Neuen Bestätigungslink erzeugen und (falls Mailversand aktiv) versenden. */
function email_verification_send(array $user): bool
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled()) {
        return false;
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'UPDATE users SET email_verify_token_hash = ?, email_verify_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?'
    )->execute([token_hash($token), $user['id']]);
    $url = rtrim((string)config('base_url'), '/') . '/verify-email.php?token=' . $token;
    $tpl = mail_tpl_verify_email($url);
    return mail_send($user['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
}

/** Bestätigungslink einlösen. Gibt den Benutzer zurück oder null. */
function email_verification_consume(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE email_verify_token_hash = ? AND email_verify_expires_at > NOW()'
    );
    $stmt->execute([token_hash($token)]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $pdo->prepare(
        'UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), email_verify_token_hash = NULL, email_verify_expires_at = NULL WHERE id = ?'
    )->execute([$user['id']]);
    audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'email_verified', 'user', $user['id']);
    return $user;
}

/** Passwort-Zurücksetzung anstoßen (antwortet für Angreifer nicht unterscheidbar). */
function password_reset_request(string $email): void
{
    require_once __DIR__ . '/mailer.php';
    $email = mb_strtolower(trim($email));

    // Drossel je IP-Adresse und je Adresse (unabhängig von Cookies): höchstens
    // 5 Anforderungen in 15 Minuten, sonst stille Ablehnung.
    $ip = client_ip();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE stage = 'reset' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND (email = ? OR (ip IS NOT NULL AND ip = ?))"
    );
    $stmt->execute([$email, $ip ?? '']);
    if ((int)$stmt->fetchColumn() >= 5) {
        return;
    }
    login_record($email, false, 'reset');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !mail_enabled()) {
        return;
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'UPDATE users SET password_reset_token_hash = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?'
    )->execute([token_hash($token), $user['id']]);
    $url = rtrim((string)config('base_url'), '/') . '/reset-password.php?token=' . $token;
    $tpl = mail_tpl_security('Passwort zurücksetzen', [
        'Für Ihr Konto wurde das Zurücksetzen des Passworts angefordert.',
        'Der Link ist eine Stunde gültig. Nach dem Zurücksetzen bleibt Ihre Zwei-Faktor-Authentifizierung unverändert bestehen.',
    ], $url, 'Neues Passwort festlegen');
    mail_send($user['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
    audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'password_reset_requested', 'user', $user['id']);
}

/** Passwort mit Token setzen. Gibt Fehlermeldung oder null zurück. */
function password_reset_complete(string $token, string $newPassword): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return 'Der Link ist ungültig oder abgelaufen.';
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE password_reset_token_hash = ? AND password_reset_expires_at > NOW()'
    );
    $stmt->execute([token_hash($token)]);
    $user = $stmt->fetch();
    if (!$user) {
        return 'Der Link ist ungültig oder abgelaufen.';
    }
    if ($err = validate_password($newPassword, $user['email'])) {
        return $err;
    }
    $pdo->prepare(
        'UPDATE users SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires_at = NULL,
                failed_login_count = 0, locked_until = NULL, session_epoch = session_epoch + 1 WHERE id = ?'
    )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'password_reset_done', 'user', $user['id']);
    security_notify_user($user, 'Passwort geändert', [
        'Das Passwort Ihres Kontos wurde soeben über den Zurücksetzen-Link neu festgelegt. Alle bestehenden Sitzungen wurden beendet.',
    ]);
    return null;
}

/** Passwort im angemeldeten Zustand ändern (altes Passwort erforderlich). */
function password_change(array $user, string $current, string $new): ?string
{
    if (!password_verify($current, $user['password_hash'])) {
        return 'Das aktuelle Passwort ist falsch.';
    }
    if ($err = validate_password($new, $user['email'])) {
        return $err;
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    audit_log($_SESSION['org_id'] ?? null, ['user_id' => $user['id'], 'email' => $user['email']], 'password_changed', 'user', $user['id']);
    security_notify_user($user, 'Passwort geändert', ['Das Passwort Ihres Kontos wurde soeben geändert.']);
    return null;
}

// ---------------------------------------------------------------------------
// Registrierung und Firmen
// ---------------------------------------------------------------------------

/**
 * Herkunft der Registrierung aus der URL lesen und in der Session merken
 * (src=<domain>, utm_*). Nur erlaubte Domains werden als signup_domain
 * übernommen; alles andere zählt als "direkt".
 */
function signup_attribution_capture(): void
{
    $allowed = array_map('strtolower', (array)config('signup_domains', []));
    $src = mb_strtolower(trim((string)($_GET['src'] ?? '')));
    if ($src !== '' && preg_match('/^[a-z0-9.-]{3,100}$/', $src)) {
        $_SESSION['signup']['domain'] = in_array($src, $allowed, true) || !$allowed ? $src : 'sonstige:' . $src;
    }
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $k) {
        $v = trim((string)($_GET[$k] ?? ''));
        if ($v !== '') {
            $_SESSION['signup'][$k] = mb_substr(preg_replace('/[^\w\-. ]/u', '', $v), 0, 100);
        }
    }
    if (empty($_SESSION['signup']['referrer']) && !empty($_SERVER['HTTP_REFERER'])) {
        $host = parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        $ownHost = parse_url((string)config('base_url'), PHP_URL_HOST);
        if ($host && $host !== $ownHost) {
            $_SESSION['signup']['referrer'] = mb_substr((string)$host, 0, 500);
        }
    }
}

/**
 * Registrierung: legt Benutzer (Inhaber), Firma, Mitgliedschaft und
 * Integrations-Datensatz an. Gibt Fehlermeldung oder null zurück.
 */
function auth_register(
    string $email,
    string $password,
    string $orgName,
    ?string $firstName,
    ?string $lastName,
    string $mandatePrefix
): ?string {
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    if ($err = validate_password($password, $email)) {
        return $err;
    }
    if (trim($orgName) === '') {
        return 'Bitte einen Firmennamen angeben.';
    }
    [$mandatePrefix, $prefixError] = validate_mandate_prefix($mandatePrefix);
    if ($prefixError) {
        return $prefixError;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        return 'Diese E-Mail-Adresse ist bereits registriert. Bitte melden Sie sich an.';
    }
    $stmt = $pdo->prepare('SELECT 1 FROM organizations WHERE mandate_prefix = ?');
    $stmt->execute([$mandatePrefix]);
    if ($stmt->fetch()) {
        return "Das Mandatspräfix \"$mandatePrefix\" wird bereits verwendet. Bitte ein anderes wählen.";
    }

    $firstName = trim((string)$firstName) ?: null;
    $lastName = trim((string)$lastName) ?: null;
    $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: null;
    $attr = $_SESSION['signup'] ?? [];

    $pdo->beginTransaction();
    try {
        $userId = uuid4();
        $orgId  = uuid4();

        $pdo->prepare(
            'INSERT INTO users (id, email, password_hash, display_name, first_name, last_name, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $email, password_hash($password, PASSWORD_DEFAULT), $displayName, $firstName, $lastName,
            // Ohne Mailversand kann nicht verifiziert werden: dann gilt die Adresse als bestätigt.
            (function () { require_once __DIR__ . '/mailer.php'; return mail_enabled() ? null : date('Y-m-d H:i:s'); })(),
        ]);

        $pdo->prepare(
            'INSERT INTO organizations
                (id, name, mandate_prefix, use_hvm_ci, plan_code, subscription_status,
                 signup_domain, utm_source, utm_medium, utm_campaign, utm_content, referrer)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orgId, trim($orgName), $mandatePrefix, 'unlimited_start', 'pending',
            $attr['domain'] ?? null, $attr['utm_source'] ?? null, $attr['utm_medium'] ?? null,
            $attr['utm_campaign'] ?? null, $attr['utm_content'] ?? null, $attr['referrer'] ?? null,
        ]);

        $pdo->prepare('INSERT INTO organization_members (id, organization_id, user_id, role, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([uuid4(), $orgId, $userId, 'owner', 'active']);

        $pdo->prepare('INSERT INTO integrations (id, tenant_id) VALUES (?, ?)')
            ->execute([uuid4(), $orgId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Registrierung fehlgeschlagen: ' . $e->getMessage());
        return 'Registrierung fehlgeschlagen. Bitte später erneut versuchen.';
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    session_finish_login($user, $orgId);
    unset($_SESSION['signup']);

    audit_log($orgId, ['user_id' => $userId, 'email' => $email], 'register', 'organization', $orgId, [
        'signup_domain' => $attr['domain'] ?? null,
    ]);
    funnel_event($attr['domain'] ?? null, 'registration_completed', $orgId, $userId);
    email_verification_send($user);
    return null;
}

/**
 * Neue Firma für einen angemeldeten Nutzer anlegen (er wird Inhaber).
 * @return array{org_id:?string,error:?string}
 */
function create_company(string $userId, string $orgName, string $mandatePrefix): array
{
    $orgName = trim($orgName);
    if ($orgName === '') {
        return ['org_id' => null, 'error' => 'Bitte einen Firmennamen angeben.'];
    }
    [$mandatePrefix, $prefixError] = validate_mandate_prefix($mandatePrefix);
    if ($prefixError) {
        return ['org_id' => null, 'error' => $prefixError];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM organizations WHERE mandate_prefix = ?');
    $stmt->execute([$mandatePrefix]);
    if ($stmt->fetch()) {
        return ['org_id' => null, 'error' => "Das Mandatspräfix \"$mandatePrefix\" wird bereits verwendet."];
    }

    // Herkunft der ersten Firma des Nutzers übernehmen (für die Auswertung)
    $stmt = $pdo->prepare(
        "SELECT o.signup_domain FROM organization_members m JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.role = 'owner' ORDER BY o.created_at ASC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $domain = $stmt->fetchColumn() ?: null;

    $pdo->beginTransaction();
    try {
        $orgId = uuid4();
        $pdo->prepare(
            'INSERT INTO organizations (id, name, mandate_prefix, use_hvm_ci, plan_code, subscription_status, signup_domain)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([$orgId, $orgName, $mandatePrefix, 'unlimited_start', 'pending', $domain]);
        $pdo->prepare('INSERT INTO organization_members (id, organization_id, user_id, role, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([uuid4(), $orgId, $userId, 'owner', 'active']);
        $pdo->prepare('INSERT INTO integrations (id, tenant_id) VALUES (?, ?)')
            ->execute([uuid4(), $orgId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Firma anlegen fehlgeschlagen: ' . $e->getMessage());
        return ['org_id' => null, 'error' => 'Firma konnte nicht angelegt werden.'];
    }

    audit_log($orgId, ['user_id' => $userId], 'company_created', 'organization', $orgId, ['name' => $orgName]);
    return ['org_id' => $orgId, 'error' => null];
}

/** Mandatspräfix normalisieren und prüfen (2-10 Zeichen, nur Großbuchstaben/Ziffern). */
function validate_mandate_prefix(string $raw): array
{
    $prefix = strtoupper(trim($raw));
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $prefix)) {
        return [$prefix, 'Mandatspräfix muss 2 bis 10 Buchstaben/Ziffern enthalten (z.B. "HVM" oder "TM").'];
    }
    return [$prefix, null];
}

/** In eine andere Firma wechseln, sofern der Nutzer dort aktives Mitglied ist. */
function switch_company(string $userId, string $orgId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM organization_members m JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.organization_id = ? AND m.status = 'active' AND o.deleted_at IS NULL"
    );
    $stmt->execute([$userId, $orgId]);
    if (!$stmt->fetch()) {
        return false;
    }
    $_SESSION['org_id'] = $orgId;
    return true;
}

/** Alle Firmen auflisten, in denen der Nutzer aktives Mitglied ist. */
function list_user_companies(string $userId): array
{
    $stmt = db()->prepare(
        "SELECT o.id, o.name, o.mandate_prefix, o.subscription_status, m.role
         FROM organization_members m
         JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.status = 'active' AND o.deleted_at IS NULL
         ORDER BY o.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Vollständigen Benutzerdatensatz laden. */
function user_load(string $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}
