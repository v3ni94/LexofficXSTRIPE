<?php
/**
 * Support-Zugriff des Plattformbetreibers auf Firmenaccounts ("Auf Firma wechseln").
 *
 * Ablauf: Ein Superadmin startet im Adminbereich (Adminhost) eine Support-Sitzung
 * für eine Firma mit Begründung und aktuellem 2FA-Code. Dabei entsteht ein
 * Einmal-Token (nur als Hash gespeichert, 5 Minuten einlösbar), das auf dem
 * Kundenhost über support-login.php eingelöst wird. Die Sitzung läuft höchstens
 * 60 Minuten, wird dem Inhaber per Sicherheits-E-Mail angezeigt und trägt im
 * audit_log den Vermerk support_session. Im Support-Modus gilt die Rolle
 * Administrator; Einzüge, IBAN-Änderungen und Zugangsdaten sind gesperrt.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

const SUPPORT_REDEEM_MINUTES = 5;
const SUPPORT_SESSION_MINUTES = 60;

/** Support-Sitzung anlegen und Einlöse-URL auf dem Kundenhost liefern. */
function support_session_create(array $adminCtx, string $orgId, string $reason): string
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason));
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        throw new RuntimeException('Bitte einen Grund für den Zugriff angeben (5 bis 255 Zeichen, z.B. Ticketnummer).');
    }
    $stmt = db()->prepare('SELECT id, name FROM organizations WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$orgId]);
    $org = $stmt->fetch();
    if (!$org) {
        throw new RuntimeException('Firma nicht gefunden.');
    }
    $token = bin2hex(random_bytes(32));
    $id = uuid4();
    db()->prepare(
        'INSERT INTO support_sessions (id, admin_user_id, admin_email, organization_id, reason, token_hash, redeem_expires_at, expires_at, ip)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    )->execute([
        $id, (string)$adminCtx['user_id'], (string)$adminCtx['email'], $orgId, $reason, token_hash($token),
        SUPPORT_REDEEM_MINUTES, SUPPORT_SESSION_MINUTES, client_ip(),
    ]);
    audit_log($orgId, $adminCtx, 'support_access_created', 'support_session', $id, ['grund' => $reason, 'firma' => $org['name']]);
    return app_base_url() . '/support-login.php?token=' . $token;
}

/**
 * Einmal-Token einlösen: liefert die Sitzung oder null. Der Token wird dabei
 * gelöscht, eine zweite Einlösung ist unmöglich.
 */
function support_session_redeem(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT s.*, u.is_superadmin, u.is_active, u.totp_enabled, u.session_epoch, u.email
         FROM support_sessions s JOIN users u ON u.id = s.admin_user_id
         WHERE s.token_hash = ? AND s.redeemed_at IS NULL AND s.ended_at IS NULL AND s.redeem_expires_at > NOW()'
    );
    $stmt->execute([token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['is_superadmin'] !== 1 || (int)$row['is_active'] !== 1 || (int)$row['totp_enabled'] !== 1) {
        return null;
    }
    $upd = $pdo->prepare('UPDATE support_sessions SET redeemed_at = NOW(), token_hash = NULL WHERE id = ? AND redeemed_at IS NULL');
    $upd->execute([$row['id']]);
    if ($upd->rowCount() !== 1) {
        return null; // paralleler Zugriff
    }
    return $row;
}

/** Sitzung aus der Datenbank laden (null, wenn beendet oder abgelaufen). */
function support_session_load_active(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM support_sessions WHERE id = ? AND ended_at IS NULL AND expires_at > NOW()');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Sitzung beenden (durch Admin, Ablauf oder Widerruf aus dem Adminbereich). */
function support_session_end(string $id, string $endedBy, ?array $actor = null): void
{
    $stmt = db()->prepare('SELECT * FROM support_sessions WHERE id = ? AND ended_at IS NULL');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    db()->prepare('UPDATE support_sessions SET ended_at = NOW(), ended_by = ?, token_hash = NULL WHERE id = ?')
        ->execute([mb_substr($endedBy, 0, 20), $id]);
    audit_log($row['organization_id'], $actor ?? ['user_id' => $row['admin_user_id'], 'email' => $row['admin_email']],
        'support_access_ended', 'support_session', $id, ['beendet_durch' => $endedBy]);
}

/** True, wenn die aktuelle Anfrage im Support-Modus läuft. */
function support_mode(): bool
{
    return !empty($_SESSION['support_session_id']);
}

/** Sperre für geldrelevante Aktionen und Zugangsdaten im Support-Modus. */
function support_guard(): void
{
    if (support_mode()) {
        throw new RuntimeException('Im Support-Modus sind Einzüge, IBAN-Änderungen und Zugangsdaten gesperrt. Diese Aktion muss die Firma selbst ausführen.');
    }
}

/** Aktive Support-Sitzungen (für den Adminbereich). */
function support_sessions_active(): array
{
    return db()->query(
        'SELECT s.*, o.name AS org_name FROM support_sessions s JOIN organizations o ON o.id = s.organization_id
         WHERE s.ended_at IS NULL AND s.expires_at > NOW() ORDER BY s.created_at DESC'
    )->fetchAll();
}

/** Letzte Support-Sitzungen (Protokoll). */
function support_sessions_recent(int $limit = 30): array
{
    return db()->query(
        'SELECT s.*, o.name AS org_name FROM support_sessions s JOIN organizations o ON o.id = s.organization_id
         ORDER BY s.created_at DESC LIMIT ' . max(1, min(200, $limit))
    )->fetchAll();
}

/** Abgelaufene, noch offene Sitzungen als beendet markieren (Aufräumen, Cron oder Adminaufruf). */
function support_sessions_expire(): void
{
    db()->exec("UPDATE support_sessions SET ended_at = NOW(), ended_by = 'expired', token_hash = NULL
                WHERE ended_at IS NULL AND (expires_at <= NOW() OR (redeemed_at IS NULL AND redeem_expires_at <= NOW()))");
}
