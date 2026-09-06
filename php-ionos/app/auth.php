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
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/devices.php';

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

    // Beruht die Anmeldung auf einer Gerätefreigabe, muss diese weiterhin gelten (nicht
    // abgelaufen, nicht widerrufen). Sonst endet die Sitzung; die Anmeldung ist mit
    // Authenticator-Code zu wiederholen (Abschnitt 5.4 und 5.8).
    if (!empty($_SESSION['trusted_device_id']) && !device_session_valid((string)$_SESSION['trusted_device_id'])) {
        auth_logout();
        if (PHP_SAPI !== 'cli') {
            session_start();
            flash_set('info', 'Die Gerätefreigabe für diesen Browser ist abgelaufen oder wurde widerrufen. Bitte melden Sie sich erneut an und bestätigen Sie die Anmeldung mit Ihrem Authenticator-Code.');
        }
        return null;
    }

    // Support-Modus: Superadmin arbeitet zeitlich begrenzt in einer fremden Firma
    if (!empty($_SESSION['support_session_id'])) {
        $ctx = _current_user_support((string)$userId, (string)$orgId, (string)$_SESSION['support_session_id']);
        return $ctx;
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

/**
 * Kontext im Support-Modus: kein Mitgliedschaftseintrag nötig, Rolle Administrator,
 * Sitzung muss aktiv, nicht abgelaufen und dem angemeldeten Superadmin zugeordnet sein.
 * Abgelaufene Sitzungen werden beendet; danach gilt wieder die eigene Firma
 * (falls vorhanden) oder die Abmeldung.
 */
function _current_user_support(string $userId, string $orgId, string $supportId): ?array
{
    $support = support_session_load_active($supportId);
    $valid = $support && $support['admin_user_id'] === $userId && $support['organization_id'] === $orgId && $support['redeemed_at'] !== null;
    if ($valid) {
        $stmt = db()->prepare(
            'SELECT u.id AS user_id, u.email, u.display_name, u.first_name, u.last_name,
                    u.totp_enabled, u.email_verified_at, u.is_superadmin, u.session_epoch, u.last_login_at,
                    o.id AS org_id, o.name AS org_name, o.mandate_prefix, o.use_hvm_ci,
                    o.onboarding_completed, o.onboarding_step,
                    o.plan_code, o.subscription_status, o.subscription_period_end, o.cancel_at_period_end,
                    o.billing_exempt, o.signup_domain, o.street, o.zip, o.city, o.country,
                    o.creditor_identifier, o.pre_notification_days, o.send_pre_notification,
                    o.require_signed_mandate
             FROM users u, organizations o
             WHERE u.id = ? AND u.is_active = 1 AND u.is_superadmin = 1 AND u.totp_enabled = 1
               AND o.id = ? AND o.deleted_at IS NULL'
        );
        $stmt->execute([$userId, $orgId]);
        $row = $stmt->fetch();
        if ($row && (int)($_SESSION['session_epoch'] ?? -1) === (int)$row['session_epoch']) {
            $row['role'] = 'admin';
            $row['member_status'] = 'active';
            $row['support_mode'] = true;
            $row['support_session_id'] = $support['id'];
            $row['support_expires_at'] = $support['expires_at'];
            return $row;
        }
    }
    // Sitzung ungültig oder abgelaufen: beenden und zur eigenen Firma zurück
    if ($support && $support['ended_at'] === null) {
        support_session_end($supportId, 'expired');
    }
    $prevOrg = (string)($_SESSION['support_prev_org_id'] ?? '');
    unset($_SESSION['support_session_id'], $_SESSION['support_prev_org_id']);
    if ($prevOrg !== '' && switch_company($userId, $prevOrg)) {
        return current_user_reload();
    }
    auth_logout();
    return null;
}

/** Kontext nach einem Firmenwechsel innerhalb derselben Anfrage neu laden. */
function current_user_reload(): ?array
{
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
         WHERE u.id = :uid AND u.is_active = 1 AND o.deleted_at IS NULL AND m.status = \'active\''
    );
    $stmt->execute(['uid' => $userId, 'org' => $orgId]);
    return $stmt->fetch() ?: null;
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
         WHERE email = ? AND success = 0 AND stage NOT IN ('reset', 'register') AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$email, LOGIN_LOCK_MINUTES]);
    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS_EMAIL) {
        return sprintf('Zu viele Fehlversuche. Bitte warten Sie %d Minuten und versuchen Sie es erneut.', LOGIN_LOCK_MINUTES);
    }

    $ip = client_ip();
    if ($ip) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND success = 0 AND stage NOT IN ('reset', 'register') AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
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
        // Erst nach geprüftem Passwort entscheidet die Gerätefreigabe, ob die Codeabfrage entfällt.
        // Ein Geräte-Token allein ermöglicht nie eine Anmeldung.
        $trust = device_trust_check((string)$user['id'], device_scope());
        if ($trust['status'] === 'valid') {
            device_trust_rotate($trust['device']);
            login_record($email, true, 'device');
            if (!session_finish_login($user, null, ['method' => 'device', 'device_id' => (string)$trust['device']['id']])) {
                return ['status' => 'error', 'message' => 'Ihr Zugang ist keiner Firma zugeordnet. Bitte wenden Sie sich an den Inhaber Ihres Firmenaccounts.'];
            }
            return ['status' => 'ok', 'message' => null];
        }
        // Stufe 2 erforderlich: Benutzer noch NICHT anmelden
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = ['user_id' => $user['id'], 'at' => time(), 'fails' => 0, 'device_expired' => $trust['status'] === 'expired'];
        return ['status' => '2fa', 'message' => null];
    }

    // Keine 2FA vorhanden: anmelden, require_login() erzwingt die Einrichtung.
    if (!session_finish_login($user, null, ['method' => 'password'])) {
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
function auth_login_2fa(string $code, bool $rememberDevice = false): array
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

    if (!session_finish_login($user, null, ['method' => $usedRecovery ? 'recovery' : 'totp'])) {
        return ['status' => 'error', 'message' => 'Ihr Zugang ist keiner Firma zugeordnet. Bitte wenden Sie sich an den Inhaber Ihres Firmenaccounts.', 'used_recovery' => $usedRecovery];
    }
    $deviceTrusted = null;
    $deviceRefused = false;
    if (!$usedRecovery) {
        totp_mark_fresh();
        if ($rememberDevice) {
            // Nur nach echter Authenticator-Bestätigung und bewusster Auswahl (Abschnitt 5.2)
            $deviceTrusted = device_trust_create($user, device_scope(), $_SESSION['org_id'] ?? null);
        }
    } elseif ($rememberDevice) {
        $deviceRefused = true; // Recovery-Code stellt keine Gerätefreigabe aus (Abschnitt 5.9)
    }

    if ($usedRecovery) {
        audit_log(null, ['user_id' => $user['id'], 'email' => $user['email']], 'recovery_code_used', 'user', $user['id']);
        security_notify_user($user, 'Recovery-Code verwendet', [
            'Bei der Anmeldung zu Ihrem Konto wurde soeben ein Recovery-Code anstelle des Authenticator-Codes verwendet.',
            'Falls Sie keinen Zugriff mehr auf Ihre Authenticator-App haben, richten Sie die Zwei-Faktor-Authentifizierung unter "Sicherheit" neu ein.',
        ]);
    }
    return ['status' => 'ok', 'message' => null, 'used_recovery' => $usedRecovery, 'device_trusted' => $deviceTrusted, 'device_refused' => $deviceRefused];
}

/**
 * Session nach erfolgreicher Prüfung aller Faktoren aufbauen.
 * Wählt die erste aktive Firmenmitgliedschaft. false, wenn keine vorhanden.
 */
function session_finish_login(array $user, ?string $preferredOrgId = null, array $auth = []): bool
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
    $_SESSION['auth_method'] = (string)($auth['method'] ?? 'password');
    unset($_SESSION['csrf_token'], $_SESSION['trusted_device_id'], $_SESSION['totp_verified_at']);
    if (!empty($auth['device_id'])) {
        $_SESSION['trusted_device_id'] = (string)$auth['device_id']; // Sitzung beruht auf einer Gerätefreigabe
    }

    audit_log($orgId, ['user_id' => $user['id'], 'email' => $user['email']], 'login_success', 'user', $user['id'], ['method' => $_SESSION['auth_method']]);
    return true;
}

/** Alle Sitzungen eines Benutzers ungültig machen (Session-Epoche erhöhen). */
function user_revoke_sessions(string $userId): void
{
    db()->prepare('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?')->execute([$userId]);
}

function auth_logout(bool $forgetDevice = false): void
{
    if ($forgetDevice) {
        $cookie = device_cookie_read();
        if ($cookie && !empty($_SESSION['user_id'])) {
            device_revoke((string)$_SESSION['user_id'], $cookie['id'], 'user_forget', $_SESSION['org_id'] ?? null);
        }
        device_cookie_clear();
    }
    if (!empty($_SESSION['support_session_id'])) {
        try { support_session_end((string)$_SESSION['support_session_id'], 'logout'); } catch (Throwable $e) { /* Abmeldung nie blockieren */ }
    }
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
    return totp_otpauth_uri(product_name(), $email, $secret);
}

/**
 * Einrichtung bestätigen: Code gegen das Sitzungsgeheimnis prüfen, Geheimnis
 * verschlüsselt speichern, Recovery-Codes erzeugen (Klartext nur einmal zurück).
 * @return array|null Recovery-Codes im Klartext oder null bei falschem Code
 */
function twofa_confirm_setup(array $user, string $code, bool $rememberDevice = false): ?array
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
    // Änderung der 2FA-Konfiguration: bisherige Gerätefreigaben verfallen (Abschnitt 5.9)
    devices_revoke_all((string)$user['id'], '2fa_changed', $_SESSION['org_id'] ?? null);
    totp_mark_fresh();
    $_SESSION['auth_method'] = 'totp';
    if ($rememberDevice) {
        device_trust_create($user, device_scope(), $_SESSION['org_id'] ?? null);
    }
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

/**
 * Zweitbestätigung kritischer Aktionen: prüft den aktuellen TOTP-Code des
 * angemeldeten Nutzers (wie bei der Anmeldung, mit Replay-Schutz über
 * users.totp_last_step; ein Code gilt nur einmal). Recovery-Codes werden hier
 * bewusst nicht akzeptiert. Wirft RuntimeException bei Fehler.
 */
function require_recent_totp(array $ctx, string $code, bool $allowFreshWindow = false): void
{
    $code = trim($code);
    if ($code === '' && $allowFreshWindow && totp_is_fresh()) {
        // Frisch eingegebener und geprüfter Authenticator-Code innerhalb der letzten 5 Minuten
        // (Abschnitt 5.7). Eine Anmeldung über die Gerätefreigabe setzt dieses Fenster nicht.
        return;
    }
    if ($code === '') {
        throw new RuntimeException('Bitte geben Sie zur Bestätigung den aktuellen 2FA-Code aus Ihrer Authenticator-App ein.');
    }
    $user = user_load((string)($ctx['user_id'] ?? ''));
    if (!$user || (int)($user['totp_enabled'] ?? 0) !== 1) {
        throw new RuntimeException('Für diese Aktion ist eine eingerichtete Zwei-Faktor-Authentifizierung erforderlich.');
    }
    if (!twofa_verify_user($user, $code)) {
        audit_log($ctx['org_id'] ?? ($_SESSION['org_id'] ?? null), $ctx, 'twofa_reauth_failed', 'user', $user['id'], [
            'script' => current_script(),
        ]);
        throw new RuntimeException('Der 2FA-Code ist ungültig oder wurde bereits verwendet. Bitte den aktuellen Code aus der Authenticator-App eingeben.');
    }
    totp_mark_fresh();
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
    devices_revoke_all((string)$user['id'], $byAdmin ? '2fa_admin_reset' : '2fa_reset', $_SESSION['org_id'] ?? null);

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
function security_notify_user(array $user, string $headline, array $lines, ?string $actionUrl = null, ?string $actionLabel = null): void
{
    require_once __DIR__ . '/mailer.php';
    if (!mail_enabled() || empty($user['email'])) {
        return;
    }
    $tpl = mail_tpl_security($headline, $lines, $actionUrl, $actionLabel);
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
    $url = app_base_url() . '/verify-email.php?token=' . $token;
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
    try {
        $orgStmt = $pdo->prepare('SELECT organization_id FROM organization_members WHERE user_id = ? ORDER BY created_at ASC LIMIT 1');
        $orgStmt->execute([$user['id']]);
        $orgId = (string)($orgStmt->fetchColumn() ?: '');
        if ($orgId !== '') {
            funnel_event_for_org($orgId, 'email_verified', (string)$user['id']);
        }
    } catch (Throwable $e) { /* Messung darf nie blockieren */ }
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
    $url = app_base_url() . '/reset-password.php?token=' . $token;
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
    devices_revoke_all((string)$user['id'], 'password_reset');
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
    $revoked = devices_revoke_all((string)$user['id'], 'password_changed', $_SESSION['org_id'] ?? null);
    audit_log($_SESSION['org_id'] ?? null, ['user_id' => $user['id'], 'email' => $user['email']], 'password_changed', 'user', $user['id']);
    security_notify_user($user, 'Passwort geändert', array_filter([
        'Das Passwort Ihres Kontos wurde soeben geändert.',
        $revoked > 0 ? 'Alle gemerkten Geräte wurden dabei vergessen; bei der nächsten Anmeldung ist wieder der Authenticator-Code erforderlich.' : null,
    ]));
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
        $ownHost = parse_url(app_base_url(), PHP_URL_HOST);
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
    // Gleichzeitige Registrierungen serialisieren (Abschnitt 6.2). Zusätzlich schützt der
    // eindeutige Index auf users.email; dessen Verletzung wird unten abgefangen.
    if (!registration_lock_acquire($pdo)) {
        return 'Die Registrierung ist derzeit belegt. Bitte in wenigen Sekunden erneut versuchen.';
    }
    try {
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
        return _auth_register_create($pdo, $email, $password, $orgName, $firstName, $lastName, $mandatePrefix);
    } finally {
        registration_lock_release($pdo);
    }
}

/** Benutzer, Firma, Mitgliedschaft und Integrationsdatensatz anlegen (unter gehaltener Registrierungssperre). */
function _auth_register_create(PDO $pdo, string $email, string $password, string $orgName, ?string $firstName, ?string $lastName, string $mandatePrefix): ?string
{
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
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            // Eindeutiger Index (users.email) hat eine gleichzeitige Doppelregistrierung verhindert
            return 'Diese E-Mail-Adresse ist bereits registriert. Bitte melden Sie sich an.';
        }
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
    // Alle Firmenanlagen laufen nacheinander (Abschnitt 6.2): Präfix- und Namensprüfung gelten
    // damit auch bei gleichzeitig abgeschickten Anfragen. Die Sperre ist unabhängig von Transaktionen.
    if (!registration_lock_acquire($pdo)) {
        return ['org_id' => null, 'error' => 'Die Firmenanlage ist derzeit belegt. Bitte in wenigen Sekunden erneut versuchen.'];
    }
    $outer = $pdo->inTransaction(); // z.B. Abschluss eines Registrierungsvorgangs (registration_request_complete)
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM organizations WHERE mandate_prefix = ?');
        $stmt->execute([$mandatePrefix]);
        if ($stmt->fetch()) {
            return ['org_id' => null, 'error' => "Das Mandatspräfix \"$mandatePrefix\" wird bereits verwendet."];
        }
        // Gleicher Firmenname ist kein Berechtigungsnachweis und wird nicht global eindeutig gemacht;
        // je Benutzer darf derselbe Name aber nur einmal zugeordnet sein (Abschnitt 6.1).
        if (user_has_company_named($userId, $orgName, $pdo)) {
            return ['org_id' => null, 'error' => 'Eine Firma mit diesem Namen ist Ihrem Benutzerkonto bereits zugeordnet. Bitte wechseln Sie über die Firmenübersicht dorthin oder wählen Sie einen anderen Namen.'];
        }

        // Herkunft der ersten Firma des Nutzers übernehmen (für die Auswertung)
        $stmt = $pdo->prepare(
            "SELECT o.signup_domain FROM organization_members m JOIN organizations o ON o.id = m.organization_id
             WHERE m.user_id = ? AND m.role = 'owner' ORDER BY o.created_at ASC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $domain = $stmt->fetchColumn() ?: null;

        if (!$outer) {
            $pdo->beginTransaction();
        }
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
            if (!$outer) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$outer && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Firma anlegen fehlgeschlagen: ' . $e->getMessage());
            if ($outer) {
                throw $e; // äußere Transaktion entscheidet
            }
            return ['org_id' => null, 'error' => 'Firma konnte nicht angelegt werden.'];
        }
    } finally {
        registration_lock_release($pdo);
    }

    // Mehrere Firmen zugeordnet: Multiaccount automatisch aktivieren (Abschnitt 3.2)
    user_multiaccount_autoenable($userId);

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

// ---------------------------------------------------------------------------
// Multiaccount (mehrere Firmen je Benutzerkonto) und Registrierung mit
// bestehender E-Mail-Adresse (Auftrag II, Abschnitte 3, 4 und 6)
// ---------------------------------------------------------------------------

const REGISTRATION_REQUEST_MINUTES = 30; // Gültigkeit eines zwischengespeicherten Registrierungsvorgangs
const REGISTER_MAX_ATTEMPTS_IP     = 10; // Registrierungsversuche mit bekannter E-Mail je IP in LOGIN_LOCK_MINUTES
const REGISTRATION_LOCK_NAME       = 'smarteinzug_registration';

/** Datenbankweite Sperre für Benutzer- und Firmenanlage (unabhängig von Transaktionen). */
function registration_lock_acquire(PDO $pdo, int $waitSeconds = 5): bool
{
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([REGISTRATION_LOCK_NAME, $waitSeconds]);
        $got = (int)$stmt->fetchColumn() === 1;
        $stmt->closeCursor();
        return $got;
    } catch (Throwable $e) {
        error_log('Registrierungssperre: ' . $e->getMessage());
        return false;
    }
}

function registration_lock_release(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([REGISTRATION_LOCK_NAME]);
        $stmt->closeCursor();
    } catch (Throwable $e) {
        // Verbindung endet ohnehin mit dem Request; die Sperre fällt dann weg.
    }
}

/**
 * Firmennamen für den Dublettenvergleich normalisieren: Kleinschreibung, Leerzeichen
 * zusammenfassen und abschneiden. Rechtsformen oder Namensbestandteile werden nicht entfernt.
 */
function org_name_normalize(string $name): string
{
    $n = mb_strtolower(trim($name));
    return preg_replace('/\s+/u', ' ', $n) ?? $n;
}

/** Anzahl der aktiven, zugänglichen Firmen eines Benutzers. */
function user_company_count(string $userId, ?PDO $pdo = null): int
{
    $stmt = ($pdo ?? db())->prepare(
        "SELECT COUNT(*) FROM organization_members m JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.status = 'active' AND o.deleted_at IS NULL"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/** Ist der Benutzer bereits einer aktiven Firma mit diesem (normalisierten) Namen zugeordnet? */
function user_has_company_named(string $userId, string $orgName, ?PDO $pdo = null): bool
{
    $stmt = ($pdo ?? db())->prepare(
        "SELECT o.name FROM organization_members m JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = ? AND m.status = 'active' AND o.deleted_at IS NULL"
    );
    $stmt->execute([$userId]);
    $needle = org_name_normalize($orgName);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (org_name_normalize((string)$name) === $needle) {
            return true;
        }
    }
    return false;
}

/**
 * Multiaccount-Zustand eines Benutzers.
 *  active:    Firmenübersicht anzeigen (manuell aktiviert oder mehrere Firmen zugeordnet)
 *  locked:    kann nicht deaktiviert werden, weil mehrere Firmen zugeordnet sind (Abschnitt 3.2)
 *  manual:    gespeicherter Schalter users.multiaccount_enabled
 *  companies: Anzahl zugänglicher Firmen
 *  migrated:  Migration 015 vorhanden; ohne sie bleibt die Firmenübersicht wie bisher sichtbar
 */
function user_multiaccount_state(string $userId): array
{
    $count = user_company_count($userId);
    try {
        $stmt = db()->prepare('SELECT multiaccount_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $manual = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return ['active' => true, 'locked' => $count > 1, 'manual' => false, 'companies' => $count, 'migrated' => false];
    }
    $locked = $count > 1;
    return ['active' => $manual || $locked, 'locked' => $locked, 'manual' => $manual, 'companies' => $count, 'migrated' => true];
}

/** Manuellen Multiaccount-Schalter setzen. Bei mehreren Firmen ist Deaktivieren nicht möglich. */
function user_multiaccount_set(string $userId, bool $enabled, ?string $tenantId = null): ?string
{
    $state = user_multiaccount_state($userId);
    if (!$state['migrated']) {
        return 'Für Multiaccount fehlt noch die Datenbankmigration 015. Bitte den Betreiber informieren.';
    }
    if (!$enabled && $state['locked']) {
        return 'Multiaccount ist aktiviert, weil Ihrem Benutzerkonto mehrere Firmen zugeordnet sind. Es kann daher nicht deaktiviert werden.';
    }
    if ($state['manual'] === $enabled) {
        return null;
    }
    db()->prepare('UPDATE users SET multiaccount_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $userId]);
    audit_log($tenantId, ['user_id' => $userId], $enabled ? 'multiaccount_enabled' : 'multiaccount_disabled', 'user', $userId);
    return null;
}

/** Multiaccount automatisch aktivieren, sobald mehrere Firmen zugeordnet sind (Abschnitt 3.2). */
function user_multiaccount_autoenable(string $userId): void
{
    try {
        if (user_company_count($userId) > 1) {
            db()->prepare('UPDATE users SET multiaccount_enabled = 1 WHERE id = ? AND multiaccount_enabled = 0')->execute([$userId]);
        }
    } catch (Throwable $e) {
        // Spalte fehlt bis Migration 015; die Firmenübersicht bleibt dann ohnehin sichtbar.
    }
}

/**
 * Registrierung einordnen (Abschnitt 4): 'new' (E-Mail unbekannt), 'existing_other' (Benutzer
 * vorhanden, gleichnamige Firma noch nicht zugeordnet), 'existing_same' (Benutzer ist einer
 * Firma dieses Namens bereits zugeordnet).
 */
function registration_classify(string $email, string $orgName): array
{
    $email = mb_strtolower(trim($email));
    $stmt = db()->prepare('SELECT id, email, is_active FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['case' => 'new', 'user' => null];
    }
    return [
        'case' => user_has_company_named($user['id'], $orgName) ? 'existing_same' : 'existing_other',
        'user' => $user,
    ];
}

/**
 * Ratenbegrenzung für Registrierungsversuche mit bereits bekannter E-Mail-Adresse (je IP).
 * Diese Versuche zählen bewusst nicht gegen die Anmeldesperre des echten Kontoinhabers.
 * Die Rückmeldung "Konto vorhanden" bleibt eine bewusste Offenlegung; die Begrenzung
 * verlangsamt das Ausprobieren, beseitigt es aber nicht (siehe docs/multiaccount.md).
 */
function register_throttle_check(): ?string
{
    $ip = client_ip();
    if (!$ip) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip = ? AND stage = 'register' AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$ip, LOGIN_LOCK_MINUTES]);
    if ((int)$stmt->fetchColumn() >= REGISTER_MAX_ATTEMPTS_IP) {
        return 'Zu viele Registrierungsversuche von Ihrer Verbindung. Bitte versuchen Sie es später erneut oder melden Sie sich mit Ihrem bestehenden Konto an.';
    }
    return null;
}

/** Firmendaten einer Registrierung mit bekannter E-Mail-Adresse zwischenspeichern (kein Passwort). */
function registration_request_create(string $userId, string $orgName, string $mandatePrefix): string
{
    $id = uuid4();
    db()->prepare(
        "INSERT INTO registration_requests (id, user_id, org_name, mandate_prefix, status, ip, expires_at)
         VALUES (?, ?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
    )->execute([$id, $userId, mb_substr(trim($orgName), 0, 255), $mandatePrefix, client_ip(), REGISTRATION_REQUEST_MINUTES]);
    return $id;
}

/**
 * Offenen Registrierungsvorgang aus der Sitzung laden. Gehört er nicht dem angemeldeten
 * Benutzer oder ist er nicht mehr gültig, wird der Sitzungsverweis entfernt und null geliefert.
 * Rückgabe enthält 'foreign' => true, wenn der Vorgang einem anderen Benutzer gehört.
 */
function registration_request_pending(string $userId): ?array
{
    $id = $_SESSION['register_continue'] ?? null;
    if (!$id) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            "SELECT *, (expires_at > NOW()) AS still_valid FROM registration_requests WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = false;
    }
    if (!$row || (int)$row['still_valid'] !== 1) {
        unset($_SESSION['register_continue']);
        return null;
    }
    if ($row['user_id'] !== $userId) {
        // Sitzung eines anderen Benutzers übernimmt den Vorgang nicht (Abschnitt 4.2)
        unset($_SESSION['register_continue']);
        return ['foreign' => true];
    }
    return $row;
}

/**
 * Registrierungsvorgang abschließen: Zeilensperre, Statuswechsel und Firmenanlage in einer
 * Transaktion, damit die Firma genau einmal entsteht (auch bei Doppelklick oder Wiederholung).
 */
function registration_request_complete(string $requestId, string $userId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT *, (expires_at > NOW()) AS still_valid FROM registration_requests WHERE id = ? AND user_id = ? FOR UPDATE"
        );
        $stmt->execute([$requestId, $userId]);
        $req = $stmt->fetch();
        if (!$req || $req['status'] !== 'pending') {
            $pdo->rollBack();
            return ['org_id' => null, 'error' => 'Dieser Registrierungsvorgang wurde bereits abgeschlossen oder ist nicht mehr gültig.'];
        }
        if ((int)$req['still_valid'] !== 1) {
            $pdo->prepare("UPDATE registration_requests SET status = 'expired' WHERE id = ?")->execute([$requestId]);
            $pdo->commit();
            unset($_SESSION['register_continue']);
            return ['org_id' => null, 'error' => 'Der Registrierungsvorgang ist abgelaufen. Bitte legen Sie die Firma über die Firmenübersicht erneut an.'];
        }
        $result = create_company($userId, (string)$req['org_name'], (string)$req['mandate_prefix']);
        if ($result['error']) {
            $pdo->rollBack();
            return $result;
        }
        $pdo->prepare("UPDATE registration_requests SET status = 'completed', completed_at = NOW(), created_org_id = ? WHERE id = ?")
            ->execute([$result['org_id'], $requestId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Registrierungsfortsetzung fehlgeschlagen: ' . $e->getMessage());
        return ['org_id' => null, 'error' => 'Die Firma konnte nicht angelegt werden. Bitte später erneut versuchen.'];
    }
    unset($_SESSION['register_continue']);
    audit_log($result['org_id'], ['user_id' => $userId], 'registration_continued', 'organization', $result['org_id'], ['request_id' => $requestId]);
    return $result;
}

/** Offenen Vorgang bewusst verwerfen. */
function registration_request_discard(string $requestId, string $userId): void
{
    try {
        db()->prepare("UPDATE registration_requests SET status = 'discarded' WHERE id = ? AND user_id = ? AND status = 'pending'")
            ->execute([$requestId, $userId]);
    } catch (Throwable $e) {
        // Tabelle fehlt bis Migration 015
    }
    unset($_SESSION['register_continue']);
}

/** Regelmäßige Bereinigung (Cron): abgelaufene Vorgänge markieren, alte Einträge löschen. */
function registration_requests_cleanup(): void
{
    $pdo = db();
    $pdo->exec("UPDATE registration_requests SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");
    $pdo->exec("DELETE FROM registration_requests WHERE status <> 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
}

/** Ziel nach erfolgreicher Anmeldung: offener Registrierungsvorgang oder Dashboard. */
function post_login_target(): string
{
    return !empty($_SESSION['register_continue']) ? 'register-fortsetzen.php' : 'dashboard.php';
}
