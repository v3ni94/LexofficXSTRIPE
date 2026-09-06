<?php
/**
 * Gerätefreigaben für die Zwei-Faktor-Authentifizierung: "Dieses Gerät für 90 Tage merken"
 * (Auftrag II, Abschnitt 5).
 *
 * Prinzip: Nach einer tatsächlich erfolgreichen Authenticator-Bestätigung und ausdrücklicher
 * Auswahl der Checkbox erhält der Browser ein Cookie "<id>.<geheim>". Serverseitig liegt nur
 * der HMAC-SHA256 des geheimen Teils. Die Freigabe ersetzt innerhalb von 90 Tagen ausschließlich
 * die 2FA-Codeabfrage bei der regulären Anmeldung in diesem Browser; Passwort, Kontosperren,
 * Rollen und Firmenmitgliedschaften werden unverändert geprüft. Der Ablauf ist fest
 * (Freigabe + 90 Tage, UTC) und wird durch Anmeldungen oder Rotationen nie verschoben.
 *
 * Grenzen: Die Wiedererkennung ist browserbezogen (Cookie). Ein entwendetes Cookie kann bis zum
 * Widerruf zusammen mit dem Passwort verwendet werden; eine Hardwarebindung besteht nicht.
 * Alle Zeitangaben dieser Tabelle sind UTC.
 */
declare(strict_types=1);

const DEVICE_TRUST_DAYS   = 90;
const DEVICE_COOKIE_BASE  = 'lxeinzug_device';
const TOTP_FRESH_SECONDS  = 300; // Bestätigungsfenster für sicherheitskritische Aktionen (5.7)

/** Anwendungsbereich der aktuellen Anfrage: Kundenanwendung oder Administrationsbereich. */
function device_scope(): string
{
    return on_admin_host() ? 'admin' : 'app';
}

/** Kontrollierbare Uhr für Ablaufprüfungen (Tests: config test_time_offset_seconds). */
function auth_now(): int
{
    return time() + (int)config('test_time_offset_seconds', 0);
}

function device_utc(int $ts): string
{
    return gmdate('Y-m-d H:i:s', $ts);
}

function device_ts(?string $utc): ?int
{
    if (!$utc) {
        return null;
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

/** UTC-Zeit der Tabelle in lokaler Darstellung TT.MM.JJJJ HH:MM. */
function device_format(?string $utc): string
{
    $ts = device_ts($utc);
    return $ts === null ? '-' : date('d.m.Y H:i', $ts);
}

function device_cookie_secure(): bool
{
    return !empty($_SERVER['HTTPS']);
}

/** Host-only-Cookie ohne Domain-Attribut; mit __Host-Präfix, sobald es über HTTPS läuft. */
function device_cookie_name(): string
{
    return (device_cookie_secure() ? '__Host-' : '') . DEVICE_COOKIE_BASE;
}

function device_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Cookie lesen und formal prüfen. Liefert ['id' => ..., 'secret' => ...] oder null. */
function device_cookie_read(): ?array
{
    $raw = (string)($_COOKIE[device_cookie_name()] ?? '');
    if ($raw === '' || !preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/', $raw, $m)) {
        return null;
    }
    return ['id' => $m[1], 'secret' => $m[2]];
}

function device_cookie_write(string $id, string $secret, int $expiresTs): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    // Cookie-Laufzeit nie länger als die serverseitige Gültigkeit
    setcookie(device_cookie_name(), $id . '.' . $secret, device_cookie_options($expiresTs));
}

function device_cookie_clear(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie(device_cookie_name(), '', device_cookie_options(time() - 86400));
    unset($_COOKIE[device_cookie_name()]);
}

function device_token_hash(string $secret): string
{
    return hash_hmac('sha256', $secret, (string)config('app_secret'));
}

/** Grobe Browser-/Systembezeichnung aus dem User-Agent; keine erfundenen Hardwaredetails. */
function device_label_from_ua(?string $ua): string
{
    $ua = (string)$ua;
    if (trim($ua) === '') {
        return 'Unbekannter Browser';
    }
    $browser = 'Browser';
    if (str_contains($ua, 'Edg/')) { $browser = 'Microsoft Edge'; }
    elseif (str_contains($ua, 'OPR/')) { $browser = 'Opera'; }
    elseif (str_contains($ua, 'Chrome/')) { $browser = 'Chrome'; }
    elseif (str_contains($ua, 'Firefox/')) { $browser = 'Firefox'; }
    elseif (str_contains($ua, 'Safari/')) { $browser = 'Safari'; }
    elseif (stripos($ua, 'curl/') !== false) { $browser = 'Kommandozeile (curl)'; }
    $os = '';
    if (str_contains($ua, 'Windows')) { $os = 'Windows'; }
    elseif (preg_match('/iPhone|iPad/', $ua)) { $os = 'iOS'; }
    elseif (str_contains($ua, 'Android')) { $os = 'Android'; }
    elseif (str_contains($ua, 'Mac OS X')) { $os = 'macOS'; }
    elseif (str_contains($ua, 'Linux')) { $os = 'Linux'; }
    return mb_substr(trim($browser . ($os !== '' ? ' auf ' . $os : '')), 0, 120);
}

/** Tabelle vorhanden (Migration 016)? Ohne sie gibt es keine Gerätefreigaben, aber auch keinen Fehler. */
function devices_available(): bool
{
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT 1 FROM trusted_devices LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

/**
 * Neue Gerätefreigabe nach erfolgreicher Authenticator-Bestätigung anlegen, Cookie setzen,
 * Benutzer benachrichtigen. Gibt den Datensatz oder null (Tabelle fehlt) zurück.
 */
function device_trust_create(array $user, string $scope, ?string $tenantId = null): ?array
{
    if (!devices_available()) {
        return null;
    }
    $now = auth_now();
    $id = uuid4();
    $secret = bin2hex(random_bytes(32));
    $label = device_label_from_ua($_SERVER['HTTP_USER_AGENT'] ?? null);
    $expires = $now + DEVICE_TRUST_DAYS * 86400;
    db()->prepare(
        'INSERT INTO trusted_devices (id, user_id, scope, token_hash, label, created_at, expires_at, ip_created)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$id, $user['id'], $scope, device_token_hash($secret), $label, device_utc($now), device_utc($expires), client_ip()]);
    device_cookie_write($id, $secret, $expires);

    audit_log($tenantId, ['user_id' => $user['id'], 'email' => $user['email']], 'device_trusted', 'trusted_device', $id, [
        'scope' => $scope, 'label' => $label, 'expires_at_utc' => device_utc($expires),
    ]);
    security_notify_user($user, 'Gerät für 90 Tage gemerkt', [
        sprintf('Am %s wurde für Ihr Konto eine Gerätefreigabe eingerichtet: %s (%s).', date('d.m.Y H:i', $now), $label,
            $scope === 'admin' ? 'Administrationsbereich' : 'Kundenanwendung'),
        sprintf('In diesem Browser entfällt bis zum %s die Eingabe des Authenticator-Codes bei der Anmeldung. Ihr Passwort bleibt erforderlich.', date('d.m.Y H:i', $expires)),
        'Unter Sicherheit, Gemerkte Geräte, können Sie diese Freigabe jederzeit widerrufen. Wenn Sie diese Freigabe nicht selbst eingerichtet haben, widerrufen Sie sie sofort und ändern Sie Ihr Passwort.',
    ], app_base_url() . '/security.php', 'Gemerkte Geräte verwalten');
    return ['id' => $id, 'label' => $label, 'expires_at' => device_utc($expires), 'scope' => $scope];
}

/**
 * Cookie gegen die Datenbank prüfen. Ergebnis:
 *  none     kein oder formal ungültiges Cookie
 *  invalid  Datensatz fehlt, Hash passt nicht, widerrufen, anderer Bereich (Cookie wird gelöscht)
 *  foreign  Freigabe gehört einem anderen Benutzer (Cookie bleibt, keine Übernahme)
 *  expired  gültig gewesen, aber Frist abgelaufen (Cookie wird gelöscht)
 *  valid    Freigabe gilt; 'device' enthält den Datensatz
 * Technische Fehler gelten nie als Erfolg.
 */
function device_trust_check(string $userId, string $scope): array
{
    $cookie = device_cookie_read();
    if (!$cookie) {
        return ['status' => 'none', 'device' => null];
    }
    if (!devices_available()) {
        return ['status' => 'invalid', 'device' => null];
    }
    try {
        $stmt = db()->prepare('SELECT * FROM trusted_devices WHERE id = ?');
        $stmt->execute([$cookie['id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Gerätefreigabe prüfen: ' . $e->getMessage());
        return ['status' => 'invalid', 'device' => null];
    }
    if (!$row || !hash_equals((string)$row['token_hash'], device_token_hash($cookie['secret']))) {
        device_cookie_clear();
        return ['status' => 'invalid', 'device' => null];
    }
    if ($row['user_id'] !== $userId) {
        return ['status' => 'foreign', 'device' => null];
    }
    if ($row['revoked_at'] !== null || $row['scope'] !== $scope) {
        device_cookie_clear();
        return ['status' => 'invalid', 'device' => null];
    }
    $expires = device_ts($row['expires_at']);
    if ($expires === null || $expires <= auth_now()) {
        device_cookie_clear();
        return ['status' => 'expired', 'device' => null];
    }
    return ['status' => 'valid', 'device' => $row];
}

/**
 * Geheimen Token bei erfolgreicher Anmeldung über die Freigabe rotieren. Atomar über den alten
 * Hash; verliert diese Anfrage das Rennen gegen eine parallele, bleibt deren Cookie gültig.
 * Der Ablaufzeitpunkt wird nicht verändert.
 */
function device_trust_rotate(array $device): void
{
    $secret = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'UPDATE trusted_devices SET token_hash = ?, rotated_at = ?, last_used_at = ? WHERE id = ? AND token_hash = ? AND revoked_at IS NULL'
    );
    $now = device_utc(auth_now());
    $stmt->execute([device_token_hash($secret), $now, $now, $device['id'], $device['token_hash']]);
    if ($stmt->rowCount() === 1) {
        device_cookie_write((string)$device['id'], $secret, (int)device_ts($device['expires_at']));
    }
}

/** Gilt die Freigabe, auf der eine laufende Sitzung beruht, noch? */
function device_session_valid(string $deviceId): bool
{
    if (!devices_available()) {
        return false;
    }
    try {
        $stmt = db()->prepare('SELECT revoked_at, expires_at FROM trusted_devices WHERE id = ?');
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
    if (!$row || $row['revoked_at'] !== null) {
        return false;
    }
    $expires = device_ts($row['expires_at']);
    return $expires !== null && $expires > auth_now();
}

/** Einzelne Freigabe widerrufen (nur eigene). */
function device_revoke(string $userId, string $deviceId, string $reason, ?string $tenantId = null): bool
{
    if (!devices_available()) {
        return false;
    }
    $stmt = db()->prepare('UPDATE trusted_devices SET revoked_at = ?, revoked_reason = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
    $stmt->execute([device_utc(auth_now()), mb_substr($reason, 0, 40), $deviceId, $userId]);
    $ok = $stmt->rowCount() === 1;
    if ($ok) {
        audit_log($tenantId, ['user_id' => $userId], 'device_revoked', 'trusted_device', $deviceId, ['reason' => $reason]);
    }
    return $ok;
}

/** Alle Freigaben eines Benutzers widerrufen (Passwortwechsel, 2FA-Änderung, Überall abmelden). */
function devices_revoke_all(string $userId, string $reason, ?string $tenantId = null): int
{
    if (!devices_available()) {
        return 0;
    }
    $stmt = db()->prepare('UPDATE trusted_devices SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->execute([device_utc(auth_now()), mb_substr($reason, 0, 40), $userId]);
    $n = $stmt->rowCount();
    if ($n > 0) {
        audit_log($tenantId, ['user_id' => $userId], 'devices_revoked_all', 'user', $userId, ['reason' => $reason, 'count' => $n]);
    }
    return $n;
}

/** Aktive Freigaben eines Benutzers für die Anzeige (neueste zuerst). */
function devices_list(string $userId): array
{
    if (!devices_available()) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT id, scope, label, created_at, expires_at, last_used_at FROM trusted_devices
         WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $now = auth_now();
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $exp = device_ts($r['expires_at']);
        if ($exp === null || $exp <= $now) {
            continue; // abgelaufen: nicht mehr verwendbar, Bereinigung über Cron
        }
        $rows[] = $r;
    }
    return $rows;
}

/** Regelmäßige Bereinigung (Cron): widerrufene und abgelaufene Freigaben nach 30 Tagen löschen. */
function devices_cleanup(): void
{
    if (!devices_available()) {
        return;
    }
    $cut = device_utc(auth_now() - 30 * 86400);
    db()->prepare('DELETE FROM trusted_devices WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?')->execute([$cut, $cut]);
}

/** Frische Authenticator-Bestätigung merken (nur nach tatsächlich geprüftem Code, nie bei Gerätefreigabe). */
function totp_mark_fresh(): void
{
    $_SESSION['totp_verified_at'] = time();
}

function totp_is_fresh(): bool
{
    $at = (int)($_SESSION['totp_verified_at'] ?? 0);
    return $at > 0 && (time() - $at) < TOTP_FRESH_SECONDS;
}
