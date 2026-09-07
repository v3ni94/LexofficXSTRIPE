<?php
/**
 * Adminbereich: Plattform-Benutzer und Rechte (Version 4.37).
 * Mitarbeiter und Administratoren des Betreibers einladen, Rollen vergeben, eigene Rollen mit
 * Berechtigungen anlegen. Zugriff nur mit Berechtigung users.manage (Systemrolle Administrator).
 * Jede Aenderung wird protokolliert; die Regeln stehen in app/platform.php.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/platform.php';
require_once __DIR__ . '/app/mailer.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_platform('users.manage');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $back = 'admin-users.php';
    try {
        if ($action === 'invite') {
            $r = platform_user_invite($ctx, (string)($_POST['email'] ?? ''), (string)($_POST['first_name'] ?? ''), (string)($_POST['last_name'] ?? ''), (string)($_POST['role'] ?? ''));
            flash_set('success', $r['created']
                ? 'Einladung versendet. Der Benutzer legt sein Passwort über den Link fest und richtet danach die Zwei-Faktor-Authentifizierung ein.'
                : 'Dem bestehenden Konto wurde die Rolle zugewiesen und eine Hinweismail gesendet.');
        } elseif ($action === 'reinvite') {
            platform_user_reinvite($ctx, (string)($_POST['user_id'] ?? ''));
            flash_set('success', 'Einladung erneut versendet.');
        } elseif ($action === 'set_role') {
            platform_user_set_role($ctx, (string)($_POST['user_id'] ?? ''), (string)($_POST['role'] ?? ''));
            flash_set('success', 'Rolle geändert. Bestehende Sitzungen des Benutzers wurden bei reduzierten Rechten beendet.');
        } elseif ($action === 'remove_access') {
            platform_user_set_role($ctx, (string)($_POST['user_id'] ?? ''), null);
            flash_set('success', 'Zugang zum Adminbereich entzogen. Das Benutzerkonto und etwaige Firmenmitgliedschaften bleiben bestehen.');
        } elseif ($action === 'deactivate' || $action === 'activate') {
            platform_user_set_active($ctx, (string)($_POST['user_id'] ?? ''), $action === 'activate');
            flash_set('success', $action === 'activate' ? 'Konto reaktiviert.' : 'Konto deaktiviert; alle Sitzungen beendet.');
        } elseif ($action === 'role_save') {
            $isNew = ($_POST['is_new'] ?? '') === '1';
            platform_role_save($ctx, (string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), (array)($_POST['perm'] ?? []), $isNew);
            flash_set('success', $isNew ? 'Rolle angelegt.' : 'Rolle gespeichert. Die Rechte gelten für alle Benutzer dieser Rolle ab der nächsten Seitenanfrage.');
            $back = 'admin-users.php#rollen';
        } elseif ($action === 'role_delete') {
            platform_role_delete($ctx, (string)($_POST['code'] ?? ''));
            flash_set('success', 'Rolle gelöscht.');
            $back = 'admin-users.php#rollen';
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect($back);
}

$users = platform_users();
$roles = platform_roles();
$editCode = is_string($_GET['rolle'] ?? null) ? (string)$_GET['rolle'] : '';
$editRole = $editCode !== '' ? ($roles[$editCode] ?? null) : null;
$groups = [];
foreach (PLATFORM_PERMISSIONS as $code => [$area, $label]) {
    $groups[$area][$code] = $label;
}
$adminCount = platform_admin_count();

layout_header('Plattform-Benutzer', $ctx);
?>
<h1>Plattform-Benutzer und Rechte</h1>
<p class="page-sub">Mitarbeiter und Administratoren des Betreibers mit Zugang zum Adminbereich. Kundenkonten (Inhaber, Administratoren und Mitarbeiter der Firmen) werden hier nicht verwaltet.</p>
<?= layout_subnav(admin_subnav_items($ctx), 'users', 'Adminbereiche') ?>

<?php if (!mail_enabled()): ?>
<div class="flash flash-warn"><strong>Mailversand nicht aktiv.</strong> Einladungen werden per E-Mail zugestellt; ohne Versand können keine neuen Benutzer eingeladen werden. Ein Passwortlink wird nie im Adminbereich angezeigt.</div>
<?php endif; ?>

<div class="card" id="benutzer">
    <h2>Benutzer mit Zugang zum Adminbereich</h2>
    <p class="hint">Jeder Zugang setzt aktive Zwei-Faktor-Authentifizierung voraus; ohne sie sieht der Benutzer nur die Einrichtungsseite. Aktive Administratoren: <?= $adminCount ?>. Der letzte Administrator kann weder herabgestuft noch deaktiviert werden; die eigene Rolle ändert immer ein anderer Administrator.</p>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>E-Mail</th><th>Name</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th>Firmen</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $self = (string)$u['id'] === (string)$ctx['user_id']; $invited = $u['last_login_at'] === null && $u['password_reset_expires_at'] !== null; ?>
            <tr>
                <td><?= e($u['email']) ?><?= $self ? ' <span class="hint">(Sie)</span>' : '' ?></td>
                <td><?= e(trim((string)$u['display_name']) !== '' ? (string)$u['display_name'] : '-') ?></td>
                <td><?= e(platform_role_label($u)) ?></td>
                <td>
                    <?php if ((int)$u['is_active'] !== 1): ?><span class="badge badge-danger">deaktiviert</span>
                    <?php elseif ($invited): ?><span class="badge">eingeladen<?= $u['password_reset_expires_at'] ? ', Link bis ' . e(format_datetime($u['password_reset_expires_at'])) : '' ?></span>
                    <?php elseif ((int)$u['totp_enabled'] !== 1): ?><span class="badge badge-warn">2FA fehlt</span>
                    <?php else: ?><span class="badge badge-success">aktiv</span><?php endif; ?>
                </td>
                <td><?= $u['last_login_at'] ? e(format_datetime($u['last_login_at'])) : '<span class="hint">noch nie</span>' ?></td>
                <td><?= (int)$u['memberships'] > 0 ? (int)$u['memberships'] . ' <span class="hint">(auch Kundenkonto)</span>' : '<span class="hint">keine</span>' ?></td>
                <td>
                    <?php if ($self): ?><span class="hint">Eigenes Konto</span>
                    <?php else: ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                        <select name="role" aria-label="Rolle">
                            <?php foreach ($roles as $r): ?><option value="<?= e($r['code']) ?>" <?= ($u['platform_role'] ?? ((int)$u['is_superadmin'] === 1 ? 'admin' : '')) === $r['code'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-secondary">Rolle setzen</button></form>
                    <?php if ($invited && (int)$u['is_active'] === 1): ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reinvite"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Einladung erneut senden</button></form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Zugang zum Adminbereich entziehen? Das Konto bleibt bestehen.');"><?= csrf_field() ?><input type="hidden" name="action" value="remove_access"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Zugang entziehen</button></form>
                    <?php if ((int)$u['is_active'] === 1): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Konto deaktivieren? Der Benutzer kann sich nirgends mehr anmelden<?= (int)$u['memberships'] > 0 ? ', auch nicht in seinen Firmen' : '' ?>.');"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Deaktivieren</button></form>
                    <?php else: ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn btn-sm">Reaktivieren</button></form>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card" id="einladen">
    <h2>Benutzer einladen</h2>
    <p class="hint">Neue Konten erhalten einen Link zum Festlegen des Passworts (<?= PLATFORM_INVITE_DAYS ?> Tage gültig) und richten danach die Zwei-Faktor-Authentifizierung ein. Bestehende Konten (etwa Inhaber einer Kundenfirma) erhalten nur die Rolle und eine Hinweismail.</p>
    <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="invite">
        <label>E-Mail-Adresse <input type="email" name="email" required autocomplete="off"></label>
        <label>Vorname <input type="text" name="first_name" maxlength="100"></label>
        <label>Nachname <input type="text" name="last_name" maxlength="100"></label>
        <label>Rolle <select name="role" required>
            <?php foreach ($roles as $r): ?><option value="<?= e($r['code']) ?>" <?= $r['code'] === 'staff' ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select></label>
        <div class="form-actions"><button type="submit" class="btn" <?= mail_enabled() ? '' : 'disabled title="Mailversand nicht aktiv"' ?>>Einladung senden</button></div>
    </form>
</div>

<div class="card" id="rollen">
    <h2>Rollen und Berechtigungen</h2>
    <p class="hint">Systemrollen (Administrator, Mitarbeiter Support, Mitarbeiter) sind nicht löschbar; Administrator ist unveränderlich. Eigene Rollen legen Sie mit einer Auswahl aus dem Berechtigungskatalog an. Rechte wirken serverseitig bei jeder Aktion, nicht nur auf ausgeblendete Menüpunkte.</p>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Rolle</th><th>Code</th><th>Beschreibung</th><th>Berechtigungen</th><th>Benutzer</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE platform_role = ?" . ($r['code'] === 'admin' ? ' OR is_superadmin = 1' : '')); $st->execute([$r['code']]); $n = (int)$st->fetchColumn(); ?>
            <tr>
                <td><strong><?= e($r['name']) ?></strong><?= $r['is_system'] ? ' <span class="hint">(System)</span>' : '' ?></td>
                <td><code><?= e($r['code']) ?></code></td>
                <td class="hint"><?= e($r['description']) ?></td>
                <td class="hint"><?= in_array('*', $r['permissions'], true) ? 'alle (' . count(PLATFORM_PERMISSIONS) . ')' : count($r['permissions']) . ' von ' . count(PLATFORM_PERMISSIONS) . ': ' . e(implode(', ', $r['permissions'])) ?></td>
                <td><?= $n ?></td>
                <td>
                    <?php if ($r['code'] !== 'admin'): ?><a class="btn btn-sm btn-secondary" href="admin-users.php?rolle=<?= e($r['code']) ?>#rolle-bearbeiten">Bearbeiten</a><?php endif; ?>
                    <?php if (!$r['is_system']): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Rolle löschen?');"><?= csrf_field() ?><input type="hidden" name="action" value="role_delete"><input type="hidden" name="code" value="<?= e($r['code']) ?>">
                        <button type="submit" class="btn btn-sm btn-danger" <?= $n > 0 ? 'disabled title="Noch Benutzern zugewiesen"' : '' ?>>Löschen</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card" id="rolle-bearbeiten">
    <h2><?= $editRole ? 'Rolle bearbeiten: ' . e($editRole['name']) : 'Neue Rolle anlegen' ?></h2>
    <?php if ($editRole && $editRole['code'] === 'admin'): ?>
        <p class="hint">Die Systemrolle Administrator ist nicht veränderbar.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="role_save"><input type="hidden" name="is_new" value="<?= $editRole ? '0' : '1' ?>">
        <div class="form-grid">
            <label>Code <input type="text" name="code" required pattern="[a-z][a-z0-9_-]{1,31}" maxlength="32" value="<?= e($editRole['code'] ?? '') ?>" <?= $editRole ? 'readonly' : '' ?> placeholder="z. B. buchhaltung"></label>
            <label>Name <input type="text" name="name" required maxlength="100" value="<?= e($editRole['name'] ?? '') ?>"></label>
            <label>Beschreibung <input type="text" name="description" maxlength="255" value="<?= e($editRole['description'] ?? '') ?>"></label>
        </div>
        <?php $selected = $editRole ? (in_array('*', $editRole['permissions'], true) ? array_keys(PLATFORM_PERMISSIONS) : $editRole['permissions']) : ['admin.view']; ?>
        <div class="perm-matrix">
        <?php foreach ($groups as $area => $perms): ?>
            <fieldset class="perm-group"><legend><?= e($area) ?></legend>
            <?php foreach ($perms as $code => $label): ?>
                <label class="inline-check"><input type="checkbox" name="perm[]" value="<?= e($code) ?>" <?= in_array($code, $selected, true) ? 'checked' : '' ?> <?= $code === 'admin.view' ? 'disabled checked' : '' ?>> <strong><?= e($code) ?></strong> <span class="hint"><?= e($label) ?></span></label>
            <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <p class="hint">admin.view (Adminbereich betreten) ist in jeder Rolle enthalten. Berechtigungen für Geldfluss und Kundenzugriff (Not-Stopp, Support-Modus, Konten) nur an Personen mit entsprechender Verantwortung vergeben; die Zweitbestätigung per 2FA-Code bleibt davon unberührt.</p>
        <div class="form-actions">
            <button type="submit" class="btn"><?= $editRole ? 'Rolle speichern' : 'Rolle anlegen' ?></button>
            <?php if ($editRole): ?><a class="btn btn-ghost" href="admin-users.php#rollen">Abbrechen</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
