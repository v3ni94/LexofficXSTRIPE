<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_role(['owner', 'admin']);
$tenantId = $ctx['org_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'rename_org') {
            $name = trim($_POST['org_name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Name darf nicht leer sein.');
            }
            $pdo->prepare('UPDATE organizations SET name = ? WHERE id = ?')->execute([$name, $tenantId]);
            flash_set('success', 'Organisationsname aktualisiert.');

        } elseif ($action === 'invite') {
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            $role = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'member';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
            }

            // Bereits Mitglied?
            $stmt = $pdo->prepare(
                'SELECT 1 FROM organization_members m JOIN users u ON u.id = m.user_id
                 WHERE m.organization_id = ? AND u.email = ?'
            );
            $stmt->execute([$tenantId, $email]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Diese Person ist bereits Mitglied der Organisation.');
            }

            // Bestehende offene Einladung ersetzen
            $pdo->prepare('DELETE FROM invitations WHERE organization_id = ? AND email = ?')
                ->execute([$tenantId, $email]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                'INSERT INTO invitations (id, organization_id, email, role, token, invited_by_user_id, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
            )->execute([uuid4(), $tenantId, $email, $role, $token, $ctx['user_id'], 'pending']);

            flash_set('success', 'Einladung erstellt. Der Einladungslink wird unten angezeigt und kann '
                . 'per E-Mail weitergegeben werden (Gültigkeit 7 Tage).');

        } elseif ($action === 'revoke_invite') {
            $pdo->prepare('DELETE FROM invitations WHERE id = ? AND organization_id = ?')
                ->execute([$_POST['invitation_id'] ?? '', $tenantId]);
            flash_set('success', 'Einladung zurückgezogen.');

        } elseif ($action === 'change_role') {
            if ($ctx['role'] !== 'owner') {
                throw new RuntimeException('Nur der Inhaber kann Rollen ändern.');
            }
            $memberId = $_POST['member_id'] ?? '';
            $newRole = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'member';

            $stmt = $pdo->prepare('SELECT * FROM organization_members WHERE id = ? AND organization_id = ?');
            $stmt->execute([$memberId, $tenantId]);
            $member = $stmt->fetch();
            if (!$member) {
                throw new RuntimeException('Mitglied nicht gefunden.');
            }
            if ($member['role'] === 'owner') {
                throw new RuntimeException('Die Rolle des Inhabers kann hier nicht geändert werden.');
            }
            $pdo->prepare('UPDATE organization_members SET role = ? WHERE id = ?')
                ->execute([$newRole, $memberId]);
            flash_set('success', 'Rolle aktualisiert.');

        } elseif ($action === 'remove_member') {
            $memberId = $_POST['member_id'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM organization_members WHERE id = ? AND organization_id = ?');
            $stmt->execute([$memberId, $tenantId]);
            $member = $stmt->fetch();
            if (!$member) {
                throw new RuntimeException('Mitglied nicht gefunden.');
            }
            if ($member['role'] === 'owner') {
                throw new RuntimeException('Der Inhaber kann nicht entfernt werden.');
            }
            if ($member['user_id'] === $ctx['user_id']) {
                throw new RuntimeException('Sie können sich nicht selbst entfernen.');
            }
            $pdo->prepare('DELETE FROM organization_members WHERE id = ?')->execute([$memberId]);
            flash_set('success', 'Mitglied entfernt.');

        } elseif ($action === 'transfer_ownership') {
            if ($ctx['role'] !== 'owner') {
                throw new RuntimeException('Nur der Inhaber kann die Inhaberschaft übertragen.');
            }
            $memberId = $_POST['member_id'] ?? '';
            $stmt = $pdo->prepare('SELECT * FROM organization_members WHERE id = ? AND organization_id = ?');
            $stmt->execute([$memberId, $tenantId]);
            $member = $stmt->fetch();
            if (!$member || $member['role'] === 'owner') {
                throw new RuntimeException('Ungültiges Zielmitglied.');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE organization_members SET role = 'admin' WHERE organization_id = ? AND user_id = ?"
            )->execute([$tenantId, $ctx['user_id']]);
            $pdo->prepare("UPDATE organization_members SET role = 'owner' WHERE id = ?")
                ->execute([$memberId]);
            $pdo->commit();
            flash_set('success', 'Inhaberschaft übertragen.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }

    redirect('team.php');
}

// Mitglieder laden
$stmt = $pdo->prepare(
    'SELECT m.id, m.role, m.created_at, u.email, u.display_name, u.id AS user_id
     FROM organization_members m
     JOIN users u ON u.id = m.user_id
     WHERE m.organization_id = ?
     ORDER BY FIELD(m.role, "owner", "admin", "member"), u.email'
);
$stmt->execute([$tenantId]);
$members = $stmt->fetchAll();

// Offene Einladungen
$stmt = $pdo->prepare(
    "SELECT * FROM invitations WHERE organization_id = ? AND status = 'pending' AND expires_at > NOW()
     ORDER BY created_at DESC"
);
$stmt->execute([$tenantId]);
$invitations = $stmt->fetchAll();

$baseUrl = rtrim((string)config('base_url'), '/');

layout_header('Team', $ctx);
?>
<h1>Team</h1>
<p class="page-sub">Mitglieder und Einladungen für <?= e($ctx['org_name']) ?></p>

<div class="card">
    <h2>Organisation</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rename_org">
        <input type="text" name="org_name" value="<?= e($ctx['org_name']) ?>" required style="max-width: 320px;">
        <button type="submit" class="btn btn-sm">Umbenennen</button>
    </form>
</div>

<div class="card">
    <h2>Mitglieder</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>E-Mail</th><th>Name</th><th>Rolle</th><th>Seit</th><th>Aktionen</th></tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['email']) ?><?= $m['user_id'] === $ctx['user_id'] ? ' <span class="hint">(Sie)</span>' : '' ?></td>
                    <td><?= e($m['display_name'] ?? '-') ?></td>
                    <td><span class="badge badge-<?= $m['role'] === 'owner' ? 'info' : 'neutral' ?>">
                        <?= e(role_label($m['role'])) ?></span></td>
                    <td><?= format_date($m['created_at']) ?></td>
                    <td>
                        <?php if ($m['role'] !== 'owner' && $m['user_id'] !== $ctx['user_id']): ?>
                            <?php if ($ctx['role'] === 'owner'): ?>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <select name="role" style="max-width: 140px; padding: 5px 8px; font-size: 13px;">
                                    <option value="member" <?= $m['role'] === 'member' ? 'selected' : '' ?>>Mitglied</option>
                                    <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-secondary">Rolle ändern</button>
                            </form>
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm('Inhaberschaft wirklich an <?= e($m['email']) ?> übertragen?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="transfer_ownership">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">Inhaberschaft übertragen</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm('Mitglied <?= e($m['email']) ?> wirklich entfernen?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Entfernen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Neues Mitglied einladen</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite">
        <label for="invite_email">E-Mail-Adresse</label>
        <input type="email" id="invite_email" name="email" required>
        <label for="invite_role">Rolle</label>
        <select id="invite_role" name="role" style="max-width: 200px;">
            <option value="member">Mitglied</option>
            <option value="admin">Administrator</option>
        </select>
        <div class="form-actions">
            <button type="submit" class="btn">Einladung erstellen</button>
        </div>
        <p class="hint">Es wird ein Einladungslink erzeugt, der manuell weitergegeben wird.
            Das Portal versendet keine E-Mails.</p>
    </form>

    <?php if ($invitations): ?>
    <h2 style="margin-top: 24px;">Offene Einladungen</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>E-Mail</th><th>Rolle</th><th>Gültig bis</th><th>Einladungslink</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($invitations as $inv): ?>
                <tr>
                    <td><?= e($inv['email']) ?></td>
                    <td><?= e(role_label($inv['role'])) ?></td>
                    <td><?= format_date($inv['expires_at']) ?></td>
                    <td><code class="copy"><?= e($baseUrl . '/invite.php?token=' . $inv['token']) ?></code></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="revoke_invite">
                            <input type="hidden" name="invitation_id" value="<?= e($inv['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Zurückziehen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer(); ?>
