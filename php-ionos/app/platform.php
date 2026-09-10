<?php
/**
 * Plattform-Benutzer und Rechte (Version 4.37).
 *
 * Wer den Adminbereich betreten darf, entscheidet nicht mehr allein users.is_superadmin, sondern eine
 * Plattformrolle (users.platform_role -> platform_roles.code) mit einer Liste von Berechtigungen aus dem
 * festen Katalog PLATFORM_PERMISSIONS. Der Vorstand vergibt Rollen und legt eigene Rollen an
 * (admin-users.php). Regeln:
 *   - users.is_superadmin = 1 bleibt der Vollzugriff (entspricht der Systemrolle "admin"); die Spalte wird
 *     weiter gelesen, damit bestehende Konten ohne Migration der Daten funktionieren.
 *   - Jede Plattformrolle setzt aktive 2FA voraus (wie bisher der Superadmin). Ohne 2FA kein Adminbereich.
 *   - Plattform-Benutzer brauchen KEINE Firmenmitgliedschaft: ohne Firma arbeiten sie in einem
 *     Plattformkontext (org_id NULL, Rolle "platform"), der nur Adminseiten und Kontoseiten erreicht.
 *   - Rechte werden serverseitig bei jeder Aktion geprueft (platform_can), nie nur ueber ausgeblendete Links.
 *   - Systemrollen (admin, support, staff) sind nicht loeschbar; "admin" ist nicht editierbar.
 *   - Niemand kann sich selbst den Zugang entziehen; der letzte Administrator kann nicht entfernt werden.
 *   - Jede Aenderung an Benutzern und Rollen steht im audit_log.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

/** Berechtigungskatalog: code => [Bereich, Beschreibung]. Neue Rechte hier ergaenzen und in der Doku (sicherheit.md) nachtragen. */
const PLATFORM_PERMISSIONS = [
    'admin.view'         => ['Adminbereich',    'Adminbereich betreten (Startseite)'],
    'companies.view'     => ['Firmen',          'Firmenaccounts, Kennzahlen und Diagramme einsehen'],
    'companies.plan'     => ['Firmen',          'Tarif einer Firma zuweisen, Abrechnungsbefreiung setzen'],
    'companies.manage'   => ['Firmen',          'In Firmeneinstellungen eingreifen: Wechselsperre des Buchhaltungssystems aufheben'],
    'plans.manage'       => ['Tarife',          'Tarife bearbeiten (Preise, Limits, Sichtbarkeit)'],
    'notstopp.platform'  => ['Not-Stopp',       'Plattformweiten Not-Stopp aktivieren und aufheben'],
    'interest.view'      => ['Vormerkungen',    'Vormerkungen einsehen und als CSV exportieren'],
    'interest.manage'    => ['Vormerkungen',    'Vormerkungen abmelden, sperren, einladen, löschen'],
    'support.view'       => ['Support',         'Supportbereich, Anfragen und Sitzungen einsehen'],
    'support.tickets'    => ['Support',         'Support-Anfragen beantworten und schließen'],
    'support.sessions'   => ['Support',         'Auf Firmenaccounts wechseln (Support-Modus, mit 2FA-Code)'],
    'support.users'      => ['Support',         'Konten entsperren und 2FA zurücksetzen'],
    'monitoring.view'    => ['System',          'Systemübersicht, Dienste, Jobs, Störungen einsehen'],
    'monitoring.edit'    => ['System',          'Störungen und Wartung, Jobs, Synchronisation bearbeiten'],
    'legal.view'         => ['Rechtsdokumente', 'Rechtsdokumente und Fassungen einsehen'],
    'legal.manage'       => ['Rechtsdokumente', 'Fassungen anlegen, veröffentlichen (2FA), zurückziehen (2FA)'],
    'docs.admin'         => ['Dokumentation',   'Unternehmens- und Verkaufsdokumentation lesen (intern)'],
    'docs.technical'     => ['Dokumentation',   'Entwickler- und Betriebsdokumentation lesen (streng vertraulich)'],
    'users.manage'       => ['Benutzer',        'Plattform-Benutzer einladen, Rollen vergeben, Rollen anlegen'],
];

/** Systemrollen (werden von Migration 027 angelegt und hier als Rueckfall gefuehrt). '*' = alle Rechte. */
const PLATFORM_SYSTEM_ROLES = [
    'admin'   => ['name' => 'Administrator', 'description' => 'Vollzugriff auf alle Bereiche, entspricht dem bisherigen Superadmin.', 'permissions' => '*'],
    'support' => ['name' => 'Mitarbeiter Support', 'description' => 'Support-Anfragen, Firmenzugriff, Konten entsperren; Systemübersicht nur lesend.',
                  'permissions' => ['admin.view', 'companies.view', 'support.view', 'support.tickets', 'support.sessions', 'support.users', 'monitoring.view', 'interest.view']],
    'staff'   => ['name' => 'Mitarbeiter', 'description' => 'Lesender Zugriff auf Firmen, Vormerkungen und Systemübersicht.',
                  'permissions' => ['admin.view', 'companies.view', 'monitoring.view', 'interest.view']],
];

/** Gueltigkeit der Einladung (Link zum Festlegen des Passworts). */
const PLATFORM_INVITE_DAYS = 3;

/** Alle Rollen (Datenbank; ohne Tabelle die Systemrollen), Schluessel = code. */
function platform_roles(bool $reset = false): array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
        return [];
    }
    if ($cache !== null) {
        return $cache;
    }
    $rows = [];
    try {
        $rows = db()->query('SELECT code, name, description, permissions, is_system, created_at, updated_at FROM platform_roles ORDER BY is_system DESC, name')->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['code']] = platform_role_normalize($r);
    }
    foreach (PLATFORM_SYSTEM_ROLES as $code => $def) {
        if (!isset($out[$code])) {
            $out[$code] = platform_role_normalize(['code' => $code, 'name' => $def['name'], 'description' => $def['description'],
                'permissions' => json_encode($def['permissions']), 'is_system' => 1, 'created_at' => null, 'updated_at' => null]);
        }
    }
    return $cache = $out;
}

/** Zeile normalisieren: permissions als Liste (oder ['*']). */
function platform_role_normalize(array $r): array
{
    $perm = json_decode((string)($r['permissions'] ?? '[]'), true);
    if ($perm === '*') {
        $perm = ['*'];
    }
    if (!is_array($perm)) {
        $perm = [];
    }
    $perm = array_values(array_unique(array_filter(array_map('strval', $perm), static fn(string $p): bool => $p === '*' || isset(PLATFORM_PERMISSIONS[$p]))));
    return ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'description' => (string)($r['description'] ?? ''),
            'permissions' => $perm, 'is_system' => (int)($r['is_system'] ?? 0) === 1, 'created_at' => $r['created_at'] ?? null, 'updated_at' => $r['updated_at'] ?? null];
}

function platform_role_get(?string $code): ?array
{
    if ($code === null || $code === '') {
        return null;
    }
    return platform_roles()[$code] ?? null;
}

/** Hat der Kontext ueberhaupt Zugang zum Adminbereich (Superadmin oder Plattformrolle) und aktive 2FA? */
function platform_access(array $ctx): bool
{
    if (empty($ctx['user_id']) || (int)($ctx['totp_enabled'] ?? 0) !== 1) {
        return false;
    }
    if ((int)($ctx['is_superadmin'] ?? 0) === 1) {
        return true;
    }
    $role = platform_role_get(isset($ctx['platform_role']) ? (string)$ctx['platform_role'] : null);
    return $role !== null && $role['permissions'] !== [];
}

/** Darf der Kontext diese Berechtigung ausueben? Superadmin und Rolle "admin" duerfen alles. */
function platform_can(array $ctx, string $permission): bool
{
    if (!platform_access($ctx)) {
        return false;
    }
    if ((int)($ctx['is_superadmin'] ?? 0) === 1) {
        return true;
    }
    $role = platform_role_get((string)$ctx['platform_role']);
    if ($role === null) {
        return false;
    }
    return in_array('*', $role['permissions'], true) || in_array($permission, $role['permissions'], true);
}

/** Alle Berechtigungen des Kontexts (fuer Anzeige), '*' aufgeloest. */
function platform_permissions(array $ctx): array
{
    if (!platform_access($ctx)) {
        return [];
    }
    if ((int)($ctx['is_superadmin'] ?? 0) === 1) {
        return array_keys(PLATFORM_PERMISSIONS);
    }
    $role = platform_role_get((string)$ctx['platform_role']);
    if ($role === null) {
        return [];
    }
    return in_array('*', $role['permissions'], true) ? array_keys(PLATFORM_PERMISSIONS) : $role['permissions'];
}

/** Anzeigename der Rolle eines Benutzers. */
function platform_role_label(array $user): string
{
    if ((int)($user['is_superadmin'] ?? 0) === 1) {
        return 'Administrator (Superadmin)';
    }
    $r = platform_role_get(isset($user['platform_role']) ? (string)$user['platform_role'] : null);
    return $r['name'] ?? 'Kein Zugang';
}

/** Alle Plattform-Benutzer (Superadmins und Benutzer mit Rolle). */
function platform_users(): array
{
    return db()->query(
        'SELECT id, email, display_name, first_name, last_name, is_active, totp_enabled, is_superadmin, platform_role, last_login_at,
                created_at, password_reset_expires_at, email_verified_at,
                (SELECT COUNT(*) FROM organization_members m WHERE m.user_id = users.id AND m.status = \'active\') AS memberships
         FROM users WHERE is_superadmin = 1 OR platform_role IS NOT NULL ORDER BY is_superadmin DESC, email'
    )->fetchAll();
}

/** Anzahl der Konten mit Vollzugriff (aktiv, Superadmin oder Rolle admin). */
function platform_admin_count(): int
{
    $st = db()->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND (is_superadmin = 1 OR platform_role = 'admin')");
    return (int)$st->fetchColumn();
}

/** Rolle pruefen, die vergeben werden soll. */
function platform_role_assert(string $code): array
{
    $role = platform_role_get($code);
    if ($role === null) {
        throw new RuntimeException('Unbekannte Rolle.');
    }
    return $role;
}

/**
 * Benutzer in den Adminbereich einladen: legt das Konto an (falls neu) mit zufaelligem Passwort, setzt die Rolle
 * und sendet einen Link zum Festlegen des Passworts (PLATFORM_INVITE_DAYS Tage gueltig). Besteht das Konto
 * bereits (z. B. Inhaber einer Firma), wird nur die Rolle gesetzt und eine Hinweismail geschickt.
 * Ohne aktiven Mailversand wird die Einladung verweigert: ein Passwortlink darf nie im Frontend erscheinen.
 */
/** Vollzugriff des Handelnden: Spalte is_superadmin oder Systemrolle admin. */
function platform_actor_is_admin(array $actor): bool
{
    return (int)($actor['is_superadmin'] ?? 0) === 1 || (string)($actor['platform_role'] ?? '') === 'admin';
}

/**
 * Rollen, deren Vergabe dem Vollzugriff gleichkommt: admin selbst und jede Rolle mit Benutzerverwaltung oder allen Rechten.
 * Befund C-01 (Audit 10.09.2026): Ein Benutzer mit users.manage konnte ein Zweitkonto als admin einladen oder seiner eigenen
 * Rolle alle Rechte geben. Solche Rollen vergibt und bearbeitet nur ein Administrator.
 */
function platform_role_is_privileged(array $role): bool
{
    $perms = (array)($role['permissions'] ?? []);
    return ($role['code'] ?? '') === 'admin' || in_array('*', $perms, true) || in_array('users.manage', $perms, true)
        || array_filter($perms, static fn($p): bool => is_string($p) && str_starts_with($p, 'docs.')) !== [];
}

function platform_user_invite(array $actor, string $email, ?string $firstName, ?string $lastName, string $roleCode): array
{
    require_once __DIR__ . '/mailer.php';
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Benutzer zu verwalten.');
    }
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    }
    $role = platform_role_assert($roleCode);
    if (platform_role_is_privileged($role) && !platform_actor_is_admin($actor)) {
        throw new RuntimeException('Die Rolle Administrator sowie Rollen mit Benutzerverwaltung oder Dokumentationsrechten dürfen nur Administratoren vergeben.');
    }
    if (!mail_enabled()) {
        throw new RuntimeException('Der Mailversand ist nicht aktiv. Ohne Versand kann kein Einladungslink zugestellt werden; ein Passwortlink wird nie im Adminbereich angezeigt.');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();
    $created = false;
    $token = bin2hex(random_bytes(32));
    if (!$user) {
        $firstName = trim((string)$firstName) ?: null;
        $lastName = trim((string)$lastName) ?: null;
        $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: null;
        $userId = uuid4();
        $pdo->prepare(
            'INSERT INTO users (id, email, password_hash, display_name, first_name, last_name, platform_role, email_verified_at,
                                password_reset_token_hash, password_reset_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        )->execute([$userId, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $displayName, $firstName, $lastName,
            $role['code'], token_hash($token), PLATFORM_INVITE_DAYS]);
        // email_verified_at = NOW(): Der Einladungslink beweist die Adresse; sonst verlangte require_login() nach dem
        // Passwort noch eine zweite Bestaetigungsmail, die die Einladung nicht ankuendigt (Review 4.41).
        $created = true;
        $user = user_load($userId);
    } else {
        if ((int)$user['is_active'] !== 1) {
            throw new RuntimeException('Dieses Konto ist deaktiviert. Bitte zuerst reaktivieren.');
        }
        // Bestehendes Konto: dieselben Schutzregeln wie bei jeder Rollenaenderung (nicht die eigene Rolle, nicht den
        // letzten Administrator herabstufen, is_superadmin faellt bei einer anderen Rolle, Sitzungen enden). Ohne diesen
        // Weg konnte ein Benutzer mit users.manage sich selbst per Einladung zum Administrator machen (Review 4.41).
        platform_user_set_role($actor, (string)$user['id'], $role['code']);
        $userId = (string)$user['id'];
    }
    audit_log(null, $actor, 'platform_user_invited', 'user', $userId, ['email' => $email, 'rolle' => $role['code'], 'neu' => $created]);

    $inviter = trim((string)($actor['display_name'] ?? '')) ?: (string)($actor['email'] ?? 'Plattformbetreiber');
    if ($created) {
        $url = app_base_url() . '/reset-password.php?token=' . $token;
        $tpl = mail_tpl_security('Einladung in den Adminbereich', [
            sprintf('%s hat Sie als "%s" für den Adminbereich von %s eingeladen.', $inviter, $role['name'], product_name()),
            sprintf('Bitte legen Sie über den Link Ihr Passwort fest (gültig %d Tage). Danach melden Sie sich an und richten die Zwei-Faktor-Authentifizierung mit einer Authenticator-App ein; ohne sie ist der Adminbereich nicht erreichbar.', PLATFORM_INVITE_DAYS),
            'Wenn Sie diese Einladung nicht erwartet haben, ignorieren Sie diese Nachricht; der Link verfällt von selbst.',
        ], $url, 'Passwort festlegen');
    } else {
        $adminUrl = admin_base_url() !== '' ? admin_base_url() . '/admin.php' : app_base_url() . '/admin.php';
        $tpl = mail_tpl_security('Zugang zum Adminbereich', [
            sprintf('%s hat Ihrem bestehenden Konto die Rolle "%s" für den Adminbereich von %s zugewiesen.', $inviter, $role['name'], product_name()),
            'Melden Sie sich wie gewohnt an; der Adminbereich ist über den Eintrag "Admin" beziehungsweise die Adminadresse erreichbar. Voraussetzung ist die aktive Zwei-Faktor-Authentifizierung Ihres Kontos.',
        ], $adminUrl, 'Zum Adminbereich');
    }
    $sent = mail_send($email, $tpl['subject'], $tpl['text'], $tpl['html']);
    if (!$sent) {
        // Konto und Rolle bleiben; die Einladung kann ueber "Einladung erneut senden" wiederholt werden.
        throw new RuntimeException('Der Versandweg hat die Einladung nicht angenommen. Konto und Rolle wurden gespeichert; bitte die Einladung erneut senden.');
    }
    return ['user_id' => $userId, 'created' => $created, 'role' => $role['code']];
}

/** Einladung erneut senden (neuer Link), nur fuer Konten, die sich noch nie angemeldet haben. */
function platform_user_reinvite(array $actor, string $userId): void
{
    require_once __DIR__ . '/mailer.php';
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Benutzer zu verwalten.');
    }
    if (!mail_enabled()) {
        throw new RuntimeException('Der Mailversand ist nicht aktiv.');
    }
    $user = user_load($userId);
    if (!$user || (int)$user['is_active'] !== 1 || ($user['platform_role'] ?? null) === null) {
        throw new RuntimeException('Benutzer nicht gefunden oder ohne Plattformrolle.');
    }
    if ($user['last_login_at'] !== null) {
        throw new RuntimeException('Dieses Konto hat sich bereits angemeldet. Für ein vergessenes Passwort nutzt der Benutzer "Passwort vergessen".');
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET password_reset_token_hash = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')
        ->execute([token_hash($token), PLATFORM_INVITE_DAYS, $userId]);
    $role = platform_role_get((string)$user['platform_role']);
    $url = app_base_url() . '/reset-password.php?token=' . $token;
    $tpl = mail_tpl_security('Einladung in den Adminbereich (erneut)', [
        sprintf('Ihre Einladung als "%s" für den Adminbereich von %s wurde erneuert.', $role['name'] ?? (string)$user['platform_role'], product_name()),
        sprintf('Bitte legen Sie über den Link Ihr Passwort fest (gültig %d Tage) und richten danach die Zwei-Faktor-Authentifizierung ein.', PLATFORM_INVITE_DAYS),
    ], $url, 'Passwort festlegen');
    mail_send((string)$user['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
    audit_log(null, $actor, 'platform_user_reinvited', 'user', $userId, ['email' => $user['email']]);
}

/**
 * Rolle eines Benutzers setzen oder den Zugang entziehen (null). Schutz: nicht sich selbst, nicht den letzten
 * Administrator; ein Superadmin (Spalte) behaelt seinen Vollzugriff, solange die Spalte gesetzt ist.
 */
function platform_user_set_role(array $actor, string $userId, ?string $roleCode): void
{
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Benutzer zu verwalten.');
    }
    $user = user_load($userId);
    if (!$user) {
        throw new RuntimeException('Benutzer nicht gefunden.');
    }
    if ((string)($actor['user_id'] ?? '') === $userId) {
        throw new RuntimeException('Die eigene Rolle kann nicht geändert werden. Bitte einen anderen Administrator darum bitten.');
    }
    $role = $roleCode !== null && $roleCode !== '' ? platform_role_assert($roleCode) : null;
    if ($role !== null && platform_role_is_privileged($role) && !platform_actor_is_admin($actor)) {
        throw new RuntimeException('Die Rolle Administrator sowie Rollen mit Benutzerverwaltung oder Dokumentationsrechten dürfen nur Administratoren vergeben.');
    }
    $wasAdmin = (int)$user['is_superadmin'] === 1 || ($user['platform_role'] ?? null) === 'admin';
    $staysAdmin = $role !== null && $role['code'] === 'admin';
    if ($wasAdmin && !$staysAdmin && (int)$user['is_active'] === 1 && platform_admin_count() <= 1) {
        throw new RuntimeException('Der letzte Administrator kann nicht herabgestuft oder entfernt werden.');
    }
    // Die Spalte is_superadmin bleibt nur bestehen, wenn der Benutzer Administrator bleibt; jede andere Rolle
    // und der Entzug setzen sie zurueck, sonst wuerde der Vollzugriff die Rolle unterlaufen.
    $newSuper = $staysAdmin && (int)$user['is_superadmin'] === 1 ? 1 : 0;
    db()->prepare('UPDATE users SET platform_role = ?, is_superadmin = ? WHERE id = ?')
        ->execute([$role['code'] ?? null, $newSuper, $userId]);
    if ($role === null || ($wasAdmin && !$staysAdmin)) {
        // Zugang entzogen oder Vollzugriff reduziert: Sitzungen beenden, damit die Aenderung sofort greift.
        user_revoke_sessions($userId);
    }
    audit_log(null, $actor, $role === null ? 'platform_user_access_removed' : 'platform_user_role_changed', 'user', $userId,
        ['email' => $user['email'], 'alt' => $user['platform_role'] ?? ((int)$user['is_superadmin'] === 1 ? 'admin' : null), 'neu' => $role['code'] ?? null]);
}

/** Konto deaktivieren oder reaktivieren (gilt fuer den gesamten Benutzer, nicht nur den Adminzugang). */
function platform_user_set_active(array $actor, string $userId, bool $active): void
{
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Benutzer zu verwalten.');
    }
    $user = user_load($userId);
    if (!$user) {
        throw new RuntimeException('Benutzer nicht gefunden.');
    }
    if ((string)($actor['user_id'] ?? '') === $userId) {
        throw new RuntimeException('Das eigene Konto kann hier nicht deaktiviert werden.');
    }
    $isAdmin = (int)$user['is_superadmin'] === 1 || ($user['platform_role'] ?? null) === 'admin';
    if (!$active && $isAdmin && (int)$user['is_active'] === 1 && platform_admin_count() <= 1) {
        throw new RuntimeException('Der letzte Administrator kann nicht deaktiviert werden.');
    }
    // Deaktivierung trifft den ganzen Benutzer, auch seine Firmenmitgliedschaften (Anzeige warnt davor).
    db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
    if (!$active) {
        user_revoke_sessions($userId);
    }
    audit_log(null, $actor, $active ? 'platform_user_activated' : 'platform_user_deactivated', 'user', $userId, ['email' => $user['email']]);
}

/** Eigene Rolle anlegen oder aendern (Systemrolle "admin" ist unveraenderlich; Systemrollen behalten Code und Kennzeichen). */
function platform_role_save(array $actor, string $code, string $name, string $description, array $permissions, bool $isNew): void
{
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Rollen zu verwalten.');
    }
    $code = mb_strtolower(trim($code));
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $code)) {
        throw new RuntimeException('Der Rollencode darf 2 bis 32 Zeichen lang sein: Kleinbuchstaben, Ziffern, Bindestrich, Unterstrich, beginnend mit einem Buchstaben.');
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) {
        throw new RuntimeException('Bitte einen Rollennamen angeben (höchstens 100 Zeichen).');
    }
    $description = mb_substr(trim($description), 0, 255);
    $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions), static fn(string $p): bool => isset(PLATFORM_PERMISSIONS[$p]))));
    if ($permissions === []) {
        throw new RuntimeException('Bitte mindestens eine Berechtigung auswählen.');
    }
    if (!in_array('admin.view', $permissions, true)) {
        $permissions[] = 'admin.view'; // ohne Zutritt zum Adminbereich ist keine Berechtigung nutzbar
    }
    $existing = platform_role_get($code);
    if ($code === 'admin') {
        throw new RuntimeException('Die Systemrolle Administrator ist nicht veränderbar.');
    }
    if ($code === (string)($actor['platform_role'] ?? '')) {
        throw new RuntimeException('Die eigene Rolle kann nicht bearbeitet werden. Bitte einen Administrator darum bitten.');
    }
    if (!platform_actor_is_admin($actor)
        && (platform_role_is_privileged(['code' => $code, 'permissions' => $permissions]) || ($existing !== null && platform_role_is_privileged($existing)))) {
        throw new RuntimeException('Rollen mit Benutzerverwaltung oder Dokumentationsrechten dürfen nur Administratoren anlegen oder ändern.');
    }
    if ($isNew && $existing !== null) {
        throw new RuntimeException('Eine Rolle mit diesem Code existiert bereits.');
    }
    if (!$isNew && $existing === null) {
        throw new RuntimeException('Rolle nicht gefunden.');
    }
    $isSystem = $existing !== null && $existing['is_system'];
    if ($isSystem) {
        // Systemrollen Mitarbeiter und Mitarbeiter Support duerfen nie Dokumentationsrechte oder die Benutzerverwaltung
        // erhalten (Dokumentationsregel: Entwickler- und Unternehmensdokumentation nie fuer Mitarbeiter- oder Supportrollen).
        // Eigene Rollen bleiben frei gestaltbar; das ist eine bewusste Entscheidung des Administrators.
        $verboten = array_values(array_filter($permissions, static fn(string $p): bool => str_starts_with($p, 'docs.') || $p === 'users.manage'));
        if ($verboten !== []) {
            throw new RuntimeException('Die Systemrolle ' . $existing['name'] . ' darf diese Berechtigungen nicht erhalten: ' . implode(', ', $verboten) . '. Dafür eine eigene Rolle anlegen.');
        }
    }
    if ($isNew) {
        db()->prepare('INSERT INTO platform_roles (code, name, description, permissions, is_system) VALUES (?, ?, ?, ?, 0)')
            ->execute([$code, $name, $description, json_encode($permissions)]);
    } else {
        db()->prepare('UPDATE platform_roles SET name = ?, description = ?, permissions = ?, updated_at = UTC_TIMESTAMP() WHERE code = ?')
            ->execute([$name, $description, json_encode($permissions), $code]);
    }
    audit_log(null, $actor, $isNew ? 'platform_role_created' : 'platform_role_changed', 'platform_role', $code,
        ['name' => $name, 'rechte' => $permissions, 'system' => $isSystem, 'vorher' => $existing['permissions'] ?? null]);
    platform_roles_reset_cache();
}

/** Eigene Rolle loeschen (nicht Systemrollen, nicht in Verwendung). */
function platform_role_delete(array $actor, string $code): void
{
    if (!platform_can($actor, 'users.manage')) {
        throw new RuntimeException('Keine Berechtigung, Rollen zu verwalten.');
    }
    $role = platform_role_get($code);
    if ($role === null) {
        throw new RuntimeException('Rolle nicht gefunden.');
    }
    if ($role['is_system']) {
        throw new RuntimeException('Systemrollen können nicht gelöscht werden.');
    }
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE platform_role = ?');
    $st->execute([$code]);
    if ((int)$st->fetchColumn() > 0) {
        throw new RuntimeException('Die Rolle ist noch Benutzern zugewiesen. Bitte zuerst eine andere Rolle vergeben.');
    }
    db()->prepare('DELETE FROM platform_roles WHERE code = ? AND is_system = 0')->execute([$code]);
    audit_log(null, $actor, 'platform_role_deleted', 'platform_role', $code, ['name' => $role['name']]);
    platform_roles_reset_cache();
}

/** Cache der Rollen verwerfen (nach Aenderungen). */
function platform_roles_reset_cache(): void
{
    platform_roles(true);
}
