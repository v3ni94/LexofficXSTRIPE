<?php
/**
 * Firma: Firmendaten (Anschrift, Gläubiger-ID, SEPA-Regeln), Mitarbeiter,
 * Einladungen, Inhaberschaft, Abonnement-Übersicht und Protokoll.
 *
 * Sichtbar für alle Mitglieder. Mitarbeiter verwalten (einladen, entfernen,
 * sperren, Rolle ändern, Inhaberschaft übertragen) darf ausschließlich der
 * Inhaber. Firmendaten dürfen Inhaber und Administratoren ändern.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/mailer.php';
require_once __DIR__ . '/app/mandates.php';
require_once __DIR__ . '/app/profile.php';

$ctx = require_login();
$tenantId = $ctx['org_id'];
$pdo = db();
$isOwner = is_owner($ctx);
$plan = plan_for_org($ctx);

function require_owner_action(array $ctx): void
{
    if (!is_owner($ctx)) {
        throw new RuntimeException('Diese Aktion ist ausschließlich dem Inhaber des Firmenaccounts vorbehalten.');
    }
}

function load_member(PDO $pdo, string $memberId, string $tenantId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, u.email, u.display_name, u.first_name, u.last_name FROM organization_members m
         JOIN users u ON u.id = m.user_id WHERE m.id = ? AND m.organization_id = ?'
    );
    $stmt->execute([$memberId, $tenantId]);
    $member = $stmt->fetch();
    if (!$member) {
        throw new RuntimeException('Mitglied nicht gefunden.');
    }
    return $member;
}

function send_invitation_mail(array $ctx, string $email, string $rawToken, string $expiresAt): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $url = app_base_url() . '/invite.php?token=' . $rawToken;
    $tpl = mail_tpl_invitation($ctx['org_name'], user_display_name($ctx), $url, format_date($expiresAt));
    return mail_send($email, $tpl['subject'], $tpl['text'], $tpl['html']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            try {
                db()->query('SELECT phone_business FROM users LIMIT 1');
            } catch (Throwable $e) {
                throw new RuntimeException('Für das Profil fehlt noch die Datenbankmigration 010. Bitte den Betreiber informieren.');
            }
            profile_update($ctx, (string)($_POST['display_name'] ?? ''), (string)($_POST['phone_private'] ?? ''), (string)($_POST['phone_business'] ?? ''));
            if (!empty($_POST['remove_avatar'])) {
                profile_avatar_delete($ctx);
            } elseif (!empty($_FILES['avatar']['name'])) {
                profile_avatar_store($ctx, $_FILES['avatar']);
            }
            flash_set('success', 'Profil gespeichert.');
        } elseif ($action === 'update_org') {
            if (!can_manage_settings($ctx)) {
                throw new RuntimeException('Nur Inhaber und Administratoren können Firmendaten ändern.');
            }
            $name = trim($_POST['org_name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Der Firmenname darf nicht leer sein.');
            }
            $ci = trim($_POST['creditor_identifier'] ?? '');
            if ($ci !== '') {
                [$ok, $res] = validate_creditor_identifier($ci);
                if (!$ok) {
                    throw new RuntimeException($res);
                }
                $ci = $res;
            }
            $preDays = (int)($_POST['pre_notification_days'] ?? 14);
            if ($preDays < 1 || $preDays > 30) {
                throw new RuntimeException('Die Vorabankündigungsfrist muss zwischen 1 und 30 Tagen liegen.');
            }
            $pdo->prepare(
                'UPDATE organizations SET name = ?, street = ?, zip = ?, city = ?, country = ?, creditor_identifier = ?,
                        pre_notification_days = ?, send_pre_notification = ?, require_signed_mandate = ? WHERE id = ?'
            )->execute([
                mb_substr($name, 0, 255),
                mb_substr(trim($_POST['street'] ?? ''), 0, 255) ?: null,
                mb_substr(trim($_POST['zip'] ?? ''), 0, 20) ?: null,
                mb_substr(trim($_POST['city'] ?? ''), 0, 100) ?: null,
                preg_match('/^[A-Za-z]{2}$/', $_POST['country'] ?? '') ? strtoupper($_POST['country']) : 'DE',
                $ci ?: null,
                $preDays,
                !empty($_POST['send_pre_notification']) ? 1 : 0,
                !empty($_POST['require_signed_mandate']) ? 1 : 0,
                $tenantId,
            ]);
            audit_log($tenantId, $ctx, 'org_updated', 'organization', $tenantId, [
                'name' => $name, 'creditor_identifier' => $ci ?: null, 'pre_notification_days' => $preDays,
                'send_pre_notification' => !empty($_POST['send_pre_notification']),
                'require_signed_mandate' => !empty($_POST['require_signed_mandate']),
            ]);
            if (!$isOwner) {
                security_notify_owner($tenantId, 'Firmendaten geändert', [
                    sprintf('%s hat die Firmendaten bzw. SEPA-Einstellungen der Firma %s geändert.', $ctx['email'], $name),
                ]);
            }
            flash_set('success', 'Firmendaten gespeichert.');

        } elseif ($action === 'invite') {
            require_owner_action($ctx);
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
            }
            $role = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'member';

            $stmt = $pdo->prepare(
                'SELECT 1 FROM organization_members m JOIN users u ON u.id = m.user_id
                 WHERE m.organization_id = ? AND u.email = ?'
            );
            $stmt->execute([$tenantId, $email]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Diese Person ist bereits Mitglied der Firma.');
            }

            // Sitzlimit des Tarifs (Inhaber + Mitglieder + offene Einladungen). Die
            // Firmenzeile wird gesperrt, damit parallele Einladungen das Limit
            // nicht durch gleichzeitige Prüfungen überschreiten können.
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE')->execute([$tenantId]);
            $seats = seats_can_invite($tenantId, $plan);
            $stmt = $pdo->prepare("SELECT 1 FROM invitations WHERE organization_id = ? AND email = ? AND status = 'pending' AND expires_at > NOW()");
            $stmt->execute([$tenantId, $email]);
            $replacesPending = (bool)$stmt->fetch();
            if (!$seats['allowed'] && !$replacesPending) {
                throw new RuntimeException($seats['reason']);
            }

            $pdo->prepare('DELETE FROM invitations WHERE organization_id = ? AND email = ?')
                ->execute([$tenantId, $email]);

            $rawToken = bin2hex(random_bytes(32));
            $invId = uuid4();
            $pdo->prepare(
                'INSERT INTO invitations (id, organization_id, email, first_name, last_name, role, token, invited_by_user_id, status, expires_at, last_sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())'
            )->execute([
                $invId, $tenantId, $email,
                mb_substr(trim($_POST['first_name'] ?? ''), 0, 100) ?: null,
                mb_substr(trim($_POST['last_name'] ?? ''), 0, 100) ?: null,
                $role, token_hash($rawToken), $ctx['user_id'], 'pending',
            ]);
            $stmt = $pdo->prepare('SELECT expires_at FROM invitations WHERE id = ?');
            $stmt->execute([$invId]);
            $expiresAt = (string)$stmt->fetchColumn();
            $pdo->commit();

            audit_log($tenantId, $ctx, 'invite_created', 'invitation', $invId, ['email' => $email, 'role' => $role]);

            if (send_invitation_mail($ctx, $email, $rawToken, $expiresAt)) {
                flash_set('success', 'Einladung an ' . $email . ' gesendet (7 Tage gültig).');
            } else {
                // Ohne Mailversand: Link einmalig anzeigen
                $_SESSION['invite_link_show'] = [
                    'email' => $email,
                    'url' => app_base_url() . '/invite.php?token=' . $rawToken,
                ];
                flash_set('success', 'Einladung erstellt. Der Einladungslink wird unten einmalig angezeigt und ist 7 Tage gültig.');
            }

        } elseif ($action === 'resend_invite') {
            require_owner_action($ctx);
            $stmt = $pdo->prepare('SELECT * FROM invitations WHERE id = ? AND organization_id = ?');
            $stmt->execute([$_POST['invitation_id'] ?? '', $tenantId]);
            $inv = $stmt->fetch();
            if (!$inv || $inv['status'] === 'accepted') {
                throw new RuntimeException('Einladung nicht gefunden.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE')->execute([$tenantId]);
            if ($inv['status'] !== 'pending' || strtotime($inv['expires_at']) < time()) {
                // Abgelaufen oder widerrufen: neuer Sitz muss frei sein
                $seats = seats_can_invite($tenantId, $plan);
                if (!$seats['allowed']) {
                    throw new RuntimeException($seats['reason']);
                }
            }
            $rawToken = bin2hex(random_bytes(32));
            $pdo->prepare(
                "UPDATE invitations SET token = ?, status = 'pending', revoked_at = NULL,
                        expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY), last_sent_at = NOW() WHERE id = ?"
            )->execute([token_hash($rawToken), $inv['id']]);
            $stmt = $pdo->prepare('SELECT expires_at FROM invitations WHERE id = ?');
            $stmt->execute([$inv['id']]);
            $expiresAt = (string)$stmt->fetchColumn();
            $pdo->commit();
            audit_log($tenantId, $ctx, 'invite_resent', 'invitation', $inv['id'], ['email' => $inv['email']]);
            if (send_invitation_mail($ctx, $inv['email'], $rawToken, $expiresAt)) {
                flash_set('success', 'Einladung erneut an ' . $inv['email'] . ' gesendet.');
            } else {
                $_SESSION['invite_link_show'] = [
                    'email' => $inv['email'],
                    'url' => app_base_url() . '/invite.php?token=' . $rawToken,
                ];
                flash_set('success', 'Neuer Einladungslink erzeugt (wird unten einmalig angezeigt).');
            }

        } elseif ($action === 'revoke_invite') {
            require_owner_action($ctx);
            $stmt = $pdo->prepare("SELECT * FROM invitations WHERE id = ? AND organization_id = ? AND status = 'pending'");
            $stmt->execute([$_POST['invitation_id'] ?? '', $tenantId]);
            $inv = $stmt->fetch();
            if (!$inv) {
                throw new RuntimeException('Einladung nicht gefunden.');
            }
            $pdo->prepare("UPDATE invitations SET status = 'revoked', revoked_at = NOW(), token = ? WHERE id = ?")
                ->execute([token_hash(bin2hex(random_bytes(32))), $inv['id']]);
            audit_log($tenantId, $ctx, 'invite_revoked', 'invitation', $inv['id'], ['email' => $inv['email']]);
            flash_set('success', 'Einladung widerrufen.');

        } elseif ($action === 'change_role') {
            require_owner_action($ctx);
            $member = load_member($pdo, $_POST['member_id'] ?? '', $tenantId);
            $newRole = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'member';
            if ($member['role'] === 'owner') {
                throw new RuntimeException('Die Rolle des Inhabers kann hier nicht geändert werden.');
            }
            $pdo->prepare('UPDATE organization_members SET role = ? WHERE id = ?')->execute([$newRole, $member['id']]);
            audit_log($tenantId, $ctx, 'role_changed', 'member', $member['user_id'], [
                'email' => $member['email'], 'old' => $member['role'], 'new' => $newRole,
            ]);
            flash_set('success', 'Rolle aktualisiert.');

        } elseif ($action === 'suspend_member' || $action === 'unsuspend_member') {
            require_owner_action($ctx);
            $member = load_member($pdo, $_POST['member_id'] ?? '', $tenantId);
            if ($member['role'] === 'owner' || $member['user_id'] === $ctx['user_id']) {
                throw new RuntimeException('Der Inhaber kann nicht gesperrt werden.');
            }
            $suspend = $action === 'suspend_member';
            $pdo->prepare('UPDATE organization_members SET status = ? WHERE id = ?')
                ->execute([$suspend ? 'suspended' : 'active', $member['id']]);
            if ($suspend) {
                user_revoke_sessions($member['user_id']);
            }
            audit_log($tenantId, $ctx, $suspend ? 'member_suspended' : 'member_unsuspended', 'member', $member['user_id'], ['email' => $member['email']]);
            flash_set('success', $suspend ? 'Zugang gesperrt, alle Sitzungen beendet.' : 'Zugang wieder freigegeben.');

        } elseif ($action === 'remove_member') {
            require_owner_action($ctx);
            $member = load_member($pdo, $_POST['member_id'] ?? '', $tenantId);
            if ($member['role'] === 'owner') {
                throw new RuntimeException('Der Inhaber kann nicht entfernt werden. Übertragen Sie zuerst die Inhaberschaft.');
            }
            if ($member['user_id'] === $ctx['user_id']) {
                throw new RuntimeException('Sie können sich nicht selbst entfernen.');
            }
            $pdo->prepare('DELETE FROM organization_members WHERE id = ?')->execute([$member['id']]);
            user_revoke_sessions($member['user_id']);
            audit_log($tenantId, $ctx, 'member_removed', 'member', $member['user_id'], ['email' => $member['email']]);
            if (mail_enabled()) {
                $tpl = mail_tpl_member_removed($ctx['org_name'], $member['email'], $ctx['email']);
                mail_send($ctx['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
                mail_send($member['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
            }
            flash_set('success', 'Zugriff von ' . $member['email'] . ' entfernt. Alle Sitzungen dieser Person wurden beendet. Protokolleinträge bleiben erhalten.');

        } elseif ($action === 'transfer_ownership') {
            require_owner_action($ctx);
            $me = user_load($ctx['user_id']);
            if (!password_verify((string)($_POST['password'] ?? ''), $me['password_hash'])) {
                throw new RuntimeException('Das Passwort ist falsch.');
            }
            // Zweitbestätigung: aktueller 2FA-Code (Replay-Schutz, kein Recovery-Code)
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $member = load_member($pdo, $_POST['member_id'] ?? '', $tenantId);
            if ($member['role'] === 'owner' || $member['status'] !== 'active') {
                throw new RuntimeException('Ungültiges Zielmitglied.');
            }
            $stmt = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
            $stmt->execute([$member['user_id']]);
            if (!(int)$stmt->fetchColumn()) {
                throw new RuntimeException('Der neue Inhaber muss zuvor die Zwei-Faktor-Authentifizierung eingerichtet haben.');
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE organization_members SET role = 'member' WHERE organization_id = ? AND user_id = ?")
                ->execute([$tenantId, $ctx['user_id']]);
            $pdo->prepare("UPDATE organization_members SET role = 'owner' WHERE id = ?")->execute([$member['id']]);
            $pdo->commit();
            audit_log($tenantId, $ctx, 'ownership_transferred', 'member', $member['user_id'], [
                'from' => $ctx['email'], 'to' => $member['email'],
            ]);
            if (mail_enabled()) {
                $tpl = mail_tpl_ownership_transferred($ctx['org_name'], $member['email']);
                mail_send($member['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
                mail_send($ctx['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
            }
            flash_set('success', 'Inhaberschaft an ' . $member['email'] . ' übertragen. Sie sind jetzt Mitarbeiter dieser Firma.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('team.php');
}

// --- Daten für die Anzeige ---
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$tenantId]);
$org = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT m.id, m.role, m.status, m.created_at, u.email, u.display_name, u.first_name, u.last_name,
            u.id AS user_id, u.totp_enabled, u.last_login_at
     FROM organization_members m
     JOIN users u ON u.id = m.user_id
     WHERE m.organization_id = ?
     ORDER BY FIELD(m.role, "owner", "admin", "member"), u.email'
);
$stmt->execute([$tenantId]);
$members = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT * FROM invitations WHERE organization_id = ? AND status IN ('pending', 'revoked')
     ORDER BY created_at DESC LIMIT 50"
);
$stmt->execute([$tenantId]);
$invitations = $stmt->fetchAll();

$inviteLink = $_SESSION['invite_link_show'] ?? null;
unset($_SESSION['invite_link_show']);

$seatsUsed = seats_used($tenantId);
$seatLimit = seats_limit($plan);
$audit = $isOwner ? audit_recent($tenantId, 40) : [];

layout_header('Firmendaten', $ctx);
?>
<h1>Firmendaten</h1>
<p class="page-sub"><?= e($org['name']) ?> · Tarif <?= e($plan['name']) ?> ·
    <?= $seatLimit === null ? $seatsUsed . ' Benutzer (unbegrenzt)' : $seatsUsed . ' von ' . $seatLimit . ' Sitzen belegt' ?>
    <?php if ($isOwner): ?> · <a href="subscription.php">Abonnement</a><?php endif; ?></p>

<?php $me = user_load((string)$ctx['user_id']) ?? $ctx; ?>
<div class="card" id="profil">
    <h2>Mein Profil</h2>
    <form method="post" enctype="multipart/form-data" class="profile-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-row" style="align-items: flex-start;">
            <div style="flex: 0 0 auto;">
                <span class="avatar avatar-lg"><?php if (!empty($me['avatar_path'])): ?><img src="avatar.php?u=<?= e($ctx['user_id']) ?>&amp;v=<?= e(substr(md5((string)$me['avatar_path'] . time()), 0, 6)) ?>" alt="Profilbild"><?php else: ?><span class="avatar-initials"><?= e(profile_initials($me)) ?></span><?php endif; ?></span>
            </div>
            <div style="flex: 1 1 260px;">
                <label for="avatar">Profilbild oder Logo (JPG, PNG, WebP, GIF, bis 2 MB, freiwillig)</label>
                <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif">
                <?php if (!empty($me['avatar_path'])): ?><label class="checkbox" style="margin-top: 6px;"><input type="checkbox" name="remove_avatar" value="1"> Bild entfernen</label><?php endif; ?>
                <p class="hint">Das Bild erscheint rechts oben im Kopf der Anwendung und ist nur für Mitglieder Ihrer Firma sichtbar. Es wird quadratisch beschnitten und auf 256 Pixel verkleinert.</p>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label for="display_name">Anzeigename</label>
                <input type="text" id="display_name" name="display_name" value="<?= e(user_display_name($me)) ?>" maxlength="100" required>
            </div>
            <div>
                <label for="phone_business">Telefon geschäftlich (freiwillig)</label>
                <input type="text" id="phone_business" name="phone_business" value="<?= e((string)($me['phone_business'] ?? '')) ?>" maxlength="40" placeholder="+49 ...">
            </div>
            <div>
                <label for="phone_private">Telefon privat (freiwillig)</label>
                <input type="text" id="phone_private" name="phone_private" value="<?= e((string)($me['phone_private'] ?? '')) ?>" maxlength="40" placeholder="+49 ...">
            </div>
        </div>
        <p class="hint">Telefonnummern sind nur für Sie und den Inhaber der Firma sichtbar und dienen der Erreichbarkeit bei Rückfragen, zum Beispiel beim Support.</p>
        <div class="form-actions"><button type="submit" class="btn">Profil speichern</button></div>
    </form>
</div>

<div class="card">
    <h2>Firmendaten und SEPA-Einstellungen</h2>
    <?php if (can_manage_settings($ctx)): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_org">
        <label for="org_name">Firmenname (Zahlungsempfänger)</label>
        <input type="text" id="org_name" name="org_name" required value="<?= e($org['name']) ?>">
        <div class="form-row">
            <div>
                <label for="street">Straße und Hausnummer</label>
                <input type="text" id="street" name="street" value="<?= e($org['street'] ?? '') ?>">
            </div>
            <div>
                <label for="zip">PLZ</label>
                <input type="text" id="zip" name="zip" value="<?= e($org['zip'] ?? '') ?>" style="max-width: 120px;">
            </div>
            <div>
                <label for="city">Ort</label>
                <input type="text" id="city" name="city" value="<?= e($org['city'] ?? '') ?>">
            </div>
            <div>
                <label for="country">Land</label>
                <input type="text" id="country" name="country" value="<?= e($org['country'] ?? 'DE') ?>" maxlength="2" style="max-width: 80px;">
            </div>
        </div>
        <label for="creditor_identifier">Gläubiger-Identifikationsnummer (von der Deutschen Bundesbank, freiwillig)</label>
        <input type="text" id="creditor_identifier" name="creditor_identifier" placeholder="DE98ZZZ09999999999"
               value="<?= e($org['creditor_identifier'] ?? '') ?>" maxlength="35">
        <p class="hint">Kein Pflichtfeld. Wenn hinterlegt, erscheint sie auf dem Mandatsdokument und wird mit Prüfziffer validiert. Da der
            technische Einzug über Stripe läuft, erscheint auf dem Kontoauszug des Kunden in der Regel die Gläubiger-ID von Stripe.</p>
        <div class="form-row">
            <div>
                <label for="pre_notification_days">Vorabankündigungsfrist (Tage vor Fälligkeit)</label>
                <input type="number" id="pre_notification_days" name="pre_notification_days" min="1" max="30"
                       value="<?= (int)$org['pre_notification_days'] ?>" style="max-width: 120px;">
                <p class="hint">Ohne abweichende Vereinbarung mit dem Zahler gilt nach Angaben der Deutschen Bundesbank eine Frist von 14 Kalendertagen.
                    Eine kürzere Frist setzt eine Vereinbarung mit Ihren Kunden voraus (zum Beispiel in AGB oder Mandat). Der eingestellte Wert steht so im erzeugten Mandatsdokument.</p>
            </div>
        </div>
        <label class="checkbox-label">
            <input type="checkbox" name="send_pre_notification" value="1" <?= (int)$org['send_pre_notification'] ? 'checked' : '' ?>>
            <span>Vorabankündigung per E-Mail durch das Portal senden. Sofort-Einzüge sind dann gesperrt, Einzüge werden
                terminiert und die Ankündigung wird beim Terminieren versendet (Kunde braucht eine E-Mail-Adresse).</span>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="require_signed_mandate" value="1" <?= (int)$org['require_signed_mandate'] ? 'checked' : '' ?> Handschriftlicher Nachweis erforderlich (Einzug erst nach erfasster Unterschrift oder hochgeladenem Mandat)<span>Handschriftlicher Nachweis erforderlich: Einzüge nur mit erfasster Unterschrift oder hochgeladenem Mandat zulassen (in den Kundendetails erfassen).</span>
        </label>
        <div class="form-actions"><button type="submit" class="btn">Speichern</button></div>
    </form>
    <?php else: ?>
        <p><?= e($org['name']) ?><br><?= e($org['street'] ?? '') ?><br><?= e(trim(($org['zip'] ?? '') . ' ' . ($org['city'] ?? ''))) ?></p>
        <p class="hint">Gläubiger-ID: <?= e($org['creditor_identifier'] ?: 'nicht hinterlegt') ?> · Vorabankündigung: <?= (int)$org['pre_notification_days'] ?> Tage
            · Unterschriebenes Mandat erforderlich: <?= (int)$org['require_signed_mandate'] ? 'Ja' : 'Nein' ?></p>
        <p class="hint">Änderungen an den Firmendaten nehmen Inhaber oder Administratoren vor.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Mitarbeiter</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Benutzer</th><th>E-Mail</th><th>Status</th><th>2FA</th><th>Letzter Login</th><th>Rolle</th><?php if ($isOwner): ?><th>Aktionen</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e(user_display_name($m)) ?><?= $m['user_id'] === $ctx['user_id'] ? ' <span class="hint">(Sie)</span>' : '' ?></td>
                    <td><?= e($m['email']) ?></td>
                    <td><?= $m['status'] === 'active' ? '<span class="badge badge-success">Aktiv</span>' : status_badge($m['status']) ?></td>
                    <td><?= (int)$m['totp_enabled'] ? '<span class="badge badge-success">Aktiv</span>' : '<span class="badge badge-warn">Ausstehend</span>' ?></td>
                    <td><?= format_datetime($m['last_login_at']) ?></td>
                    <td><span class="badge badge-<?= $m['role'] === 'owner' ? 'info' : 'neutral' ?>"><?= e(role_label($m['role'])) ?></span></td>
                    <?php if ($isOwner): ?>
                    <td>
                        <?php if ($m['role'] !== 'owner' && $m['user_id'] !== $ctx['user_id']): ?>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <select name="role" style="max-width: 150px; padding: 5px 8px; font-size: 13px;">
                                    <option value="member" <?= $m['role'] === 'member' ? 'selected' : '' ?>>Mitarbeiter</option>
                                    <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-secondary">Rolle</button>
                            </form>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $m['status'] === 'active' ? 'suspend_member' : 'unsuspend_member' ?>">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary"><?= $m['status'] === 'active' ? 'Sperren' : 'Entsperren' ?></button>
                            </form>
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm(<?= e(json_encode('Möchten Sie den Zugriff von ' . user_display_name($m) . ' wirklich entfernen? Alle Sitzungen werden beendet.', JSON_UNESCAPED_UNICODE)) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Entfernen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isOwner): ?>
<div class="card">
    <h2>Mitarbeiter einladen</h2>
    <?php $seats = seats_can_invite($tenantId, $plan); ?>
    <?php if (!$seats['allowed']): ?>
        <div class="flash flash-info"><?= e($seats['reason']) ?></div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite">
        <div class="form-row">
            <div><label for="inv_first">Vorname (optional)</label><input type="text" id="inv_first" name="first_name" maxlength="100"></div>
            <div><label for="inv_last">Nachname (optional)</label><input type="text" id="inv_last" name="last_name" maxlength="100"></div>
        </div>
        <label for="invite_email">E-Mail-Adresse (persönlich)</label>
        <input type="email" id="invite_email" name="email" required>
        <label for="invite_role">Rolle</label>
        <select id="invite_role" name="role" style="max-width: 240px;">
            <option value="member">Mitarbeiter (voller operativer Zugriff)</option>
            <option value="admin">Administrator (zusätzlich API-Verbindungen)</option>
        </select>
        <div class="form-actions">
            <button type="submit" class="btn" <?= $seats['allowed'] ? '' : 'disabled' ?>>Einladung senden</button>
        </div>
        <p class="hint">Der Mitarbeiter erhält einen 7 Tage gültigen Link, setzt ein eigenes Passwort und richtet zwingend
            2FA ein. Keine Firmenpasswörter weitergeben. <?= mail_enabled() ? '' : 'Der E-Mail-Versand ist nicht aktiv, der Link wird hier angezeigt.' ?></p>
    </form>

    <?php if ($inviteLink): ?>
        <div class="flash flash-info">Einladungslink für <?= e($inviteLink['email']) ?> (nur jetzt sichtbar, bitte sicher übermitteln):<br>
            <code class="copy"><?= e($inviteLink['url']) ?></code></div>
    <?php endif; ?>

    <?php if ($invitations): ?>
    <h2 style="margin-top: 24px;">Einladungen</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Gültig bis</th><th>Aktionen</th></tr></thead>
            <tbody>
                <?php foreach ($invitations as $inv):
                    $expired = strtotime($inv['expires_at']) < time();
                    $state = $inv['status'] === 'revoked' ? 'revoked' : ($expired ? 'expired' : 'pending');
                ?>
                <tr>
                    <td><?= e($inv['email']) ?><?= $inv['first_name'] || $inv['last_name'] ? ' <span class="hint">' . e(trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? ''))) . '</span>' : '' ?></td>
                    <td><?= e(role_label($inv['role'])) ?></td>
                    <td><?= $state === 'pending' ? '<span class="badge badge-warn">Einladung ausstehend</span>' : ($state === 'expired' ? '<span class="badge badge-neutral">Einladung abgelaufen</span>' : '<span class="badge badge-neutral">Widerrufen</span>') ?></td>
                    <td><?= format_date($inv['expires_at']) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resend_invite">
                            <input type="hidden" name="invitation_id" value="<?= e($inv['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Erneut senden</button>
                        </form>
                        <?php if ($state === 'pending'): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="revoke_invite">
                            <input type="hidden" name="invitation_id" value="<?= e($inv['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Widerrufen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Inhaberschaft übertragen</h2>
    <p class="hint">Der neue Inhaber muss aktives Mitglied mit eingerichteter 2FA sein. Sie werden danach Mitarbeiter
        dieser Firma. Zur Bestätigung sind Ihr Passwort und der aktuelle 2FA-Code aus Ihrer Authenticator-App erforderlich (kein Recovery-Code).</p>
    <?php $candidates = array_filter($members, fn($m) => $m['role'] !== 'owner' && $m['status'] === 'active'); ?>
    <?php if (!$candidates): ?>
        <p class="hint">Derzeit gibt es kein aktives Mitglied, an das die Inhaberschaft übertragen werden könnte.</p>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('Inhaberschaft wirklich übertragen? Sie verlieren die Inhaberrechte dieser Firma.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="transfer_ownership">
        <label for="to_member">Neuer Inhaber</label>
        <select id="to_member" name="member_id" style="max-width: 360px;">
            <?php foreach ($candidates as $m): ?>
                <option value="<?= e($m['id']) ?>"><?= e($m['email']) ?><?= (int)$m['totp_enabled'] ? '' : ' (2FA fehlt)' ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-row">
            <div><label for="to_pw">Ihr Passwort</label><input type="password" id="to_pw" name="password" required autocomplete="current-password"></div>
            <div><label for="to_code">Aktueller 2FA-Code</label><input type="text" id="to_code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code" placeholder="123 456"></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-danger">Inhaberschaft übertragen</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Protokoll (letzte Einträge)</h2>
    <p class="hint">Sicherheits- und geldrelevante Aktionen mit Person und Zeitpunkt. Einträge werden nie gelöscht.</p>
    <div class="table-wrap">
        <table class="table-sm">
            <thead><tr><th>Zeitpunkt</th><th>Person</th><th>Aktion</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($audit as $a): $d = $a['details_json'] ? json_decode($a['details_json'], true) : []; ?>
                <tr>
                    <td><?= format_datetime($a['created_at']) ?></td>
                    <td><?= e($a['user_email'] ?? 'System') ?></td>
                    <td><?= e(audit_action_label($a['action'])) ?></td>
                    <td class="hint"><?php
                        $parts = [];
                        foreach ((array)$d as $k => $v) {
                            if (is_scalar($v) && $v !== '' && $v !== null) {
                                $parts[] = $k === 'amount_cents' ? format_eur_cents((int)$v) : $k . ': ' . (is_bool($v) ? ($v ? 'ja' : 'nein') : $v);
                            }
                        }
                        echo e(mb_substr(implode(', ', $parts), 0, 160));
                    ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$audit): ?><tr><td colspan="4" class="hint">Noch keine Einträge.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <p class="hint">Mitarbeiter einladen, entfernen oder sperren, Rollen ändern, die Inhaberschaft übertragen sowie das
        Abonnement verwalten kann ausschließlich der Inhaber des Firmenaccounts.</p>
</div>
<?php endif; ?>

<?php
$subAllowed = subscription_allows_operation($org);
$subStatus = (string)($org['subscription_status'] ?? 'pending');
?>
<div class="card" id="abonnement">
    <h2>Abonnement</h2>
    <dl class="kv">
        <dt>Registriert am</dt><dd><?= format_datetime($org['created_at'] ?? null) ?></dd>
        <dt>Tarif</dt><dd><?= e($plan['name']) ?> · <?= format_eur_cents((int)$plan['price_cents']) ?> netto je <?= (int)$plan['period_days'] ?> Tage<?= billing_vat_hint((int)$plan['price_cents']) ?></dd>
        <dt>Status</dt><dd>
            <?php if ((int)$org['billing_exempt'] === 1): ?><span class="badge badge-success">Befreit</span>
            <?php elseif (!billing_enabled()): ?><span class="badge badge-neutral">Abrechnung noch nicht freigeschaltet</span> <span class="hint">Bis dahin entstehen keine Einschränkungen und keine Kosten.</span>
            <?php else: ?><span class="badge <?= $subAllowed ? 'badge-success' : 'badge-danger' ?>"><?= e(subscription_status_label($subStatus)) ?></span><?php endif; ?>
        </dd>
        <?php if (!empty($org['subscription_period_end'])): ?>
        <dt><?= (int)$org['cancel_at_period_end'] ? 'Vertrag endet am' : 'Laufende Periode bis' ?></dt><dd><?= format_date($org['subscription_period_end']) ?><?= (int)$org['cancel_at_period_end'] ? ' <span class="badge badge-warn">Kündigung vorgemerkt</span>' : '' ?></dd>
        <?php endif; ?>
    </dl>
    <?php if ($isOwner && billing_enabled() && (int)$org['billing_exempt'] !== 1): ?>
    <div class="form-actions" style="flex-wrap: wrap;">
        <?php if (!$subAllowed): ?>
            <a class="btn" href="subscription.php?bestellen=1"><?= $subStatus === 'canceled' ? 'Vertrag aktivieren' : 'Abonnement abschließen' ?></a>
        <?php elseif ((int)$org['cancel_at_period_end'] === 1): ?>
            <a class="btn btn-secondary" href="subscription.php#kuendigung">Kündigung zurücknehmen</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="subscription.php#kuendigung">Abo kündigen</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="subscription.php">Rechnungen und Zahlungsmethode</a>
    </div>
    <?php elseif ($isOwner): ?>
    <p class="hint"><a href="subscription.php">Details zum Abonnement</a></p>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
