<?php
/**
 * Superadmin, Bereich Support: Zugriff auf Firmenaccounts ("Auf Firma wechseln",
 * zeitlich begrenzt, protokolliert, mit Begründung und 2FA-Code), aktive
 * Support-Sitzungen, Konten entsperren, 2FA zurücksetzen, Protokoll.
 * Zugriff nur für users.is_superadmin = 1 mit aktiver 2FA.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

if (PHP_SAPI !== 'cli' && admin_base_url() !== '') {
    $adminHost = base_url_host(admin_base_url());
    if ($adminHost !== '' && $adminHost !== base_url_host(app_base_url()) && request_host() !== $adminHost) {
        host_not_found();
    }
}

$ctx = require_superadmin();
$pdo = db();
support_sessions_expire();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'support_start') {
            if (!empty($ctx['support_mode'])) {
                throw new RuntimeException('Bitte zuerst die laufende Support-Sitzung beenden.');
            }
            require_recent_totp($ctx, (string)($_POST['code'] ?? ''));
            $url = support_session_create($ctx, (string)($_POST['org_id'] ?? ''), (string)($_POST['reason'] ?? ''));
            header('Location: ' . $url, true, 302);
            exit;
        } elseif ($action === 'support_revoke') {
            $id = (string)($_POST['session_id'] ?? '');
            support_session_end($id, 'revoked', $ctx);
            flash_set('success', 'Support-Sitzung beendet. Der Zugriff endet mit der nächsten Seitenanfrage.');
        } elseif ($action === 'user_reset_2fa' || $action === 'user_unlock') {
            $me = user_load($ctx['user_id']);
            if ($err = verify_password_and_2fa($me, $_POST['password'] ?? '', $_POST['code'] ?? '')) {
                throw new RuntimeException($err);
            }
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                throw new RuntimeException('Benutzer nicht gefunden.');
            }
            if ($action === 'user_reset_2fa') {
                twofa_reset($user, true, $ctx);
                flash_set('success', '2FA für ' . $email . ' zurückgesetzt (Support-Reset, protokolliert). Der Benutzer richtet 2FA bei der nächsten Anmeldung neu ein.');
            } else {
                $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);
                $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND success = 0')->execute([$email]);
                audit_log(null, $ctx, 'login_locked', 'user', $user['id'], ['unlocked_by_admin' => true, 'email' => $email]);
                flash_set('success', 'Konto ' . $email . ' entsperrt.');
            }
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('admin-support.php');
}

$orgs = $pdo->query(
    "SELECT o.id, o.name, o.plan_code, o.subscription_status, o.created_at, o.onboarding_completed, o.collections_paused,
            (SELECT u.email FROM organization_members m JOIN users u ON u.id = m.user_id WHERE m.organization_id = o.id AND m.role = 'owner' LIMIT 1) AS owner_email,
            (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active') AS members,
            i.lexoffice_connected, i.stripe_connected, i.lexoffice_last_sync
     FROM organizations o LEFT JOIN integrations i ON i.tenant_id = o.id
     WHERE o.deleted_at IS NULL ORDER BY o.name"
)->fetchAll();
$active = support_sessions_active();
$recent = support_sessions_recent(30);
$locked = $pdo->query("SELECT email, locked_until, failed_login_count FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW() ORDER BY locked_until DESC LIMIT 20")->fetchAll();
$supportQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($supportQuery !== '') {
    $needle = mb_strtolower($supportQuery);
    $orgs = array_values(array_filter($orgs, static fn(array $o): bool =>
        str_contains(mb_strtolower((string)$o['name']), $needle) || str_contains(mb_strtolower((string)($o['owner_email'] ?? '')), $needle)));
}

layout_header('Support', $ctx);
?>
<h1>Support</h1>
<p class="page-sub">Plattform <?= e(product_name()) ?> · <a href="admin.php">Zur Übersicht (Kennzahlen, Tarife, Firmen)</a></p>

<?php if (!empty($ctx['support_mode'])): ?>
<div class="card">
    <h2>Laufende Support-Sitzung</h2>
    <p>Sie arbeiten gerade in der Firma <strong><?= e($ctx['org_name']) ?></strong>. <a class="btn btn-sm btn-secondary" href="support-end.php">Support beenden</a></p>
</div>
<?php endif; ?>

<div class="card" id="firmenzugriff">
    <h2>Auf Firma wechseln (Support-Zugriff)</h2>
    <p class="hint">Sie arbeiten dann <?= SUPPORT_SESSION_MINUTES ?> Minuten in der Kundenanwendung dieser Firma mit der Rolle Administrator.
        Einzüge, IBAN-Änderungen und Zugangsdaten sind im Support-Modus gesperrt, jede Aktion wird mit Support-Vermerk protokolliert und der
        Inhaber erhält eine Sicherheits-E-Mail. Grund (z.B. Ticketnummer) und aktueller 2FA-Code sind Pflicht.</p>
    <form method="get" class="inline-form" style="margin-bottom: 12px;">
        <input type="search" name="q" value="<?= e($supportQuery) ?>" placeholder="Firma oder Inhaber suchen" style="max-width: 320px;">
        <button type="submit" class="btn btn-sm btn-secondary">Suchen</button>
        <?php if ($supportQuery !== ''): ?><a class="btn btn-sm btn-ghost" href="admin-support.php">Alle anzeigen</a><?php endif; ?>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Firma</th><th>Inhaber</th><th>Tarif / Abo</th><th>Verbindungen</th><th>Letzter Sync</th><th>Grund</th><th>2FA-Code</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orgs as $o): ?>
                <tr>
                    <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="support_start">
                    <input type="hidden" name="org_id" value="<?= e($o['id']) ?>">
                    <td><strong><?= e($o['name']) ?></strong><?= (int)$o['collections_paused'] ? ' <span class="badge badge-warn">Not-Stopp</span>' : '' ?><br><small class="hint"><?= (int)$o['members'] ?> Benutzer, seit <?= e(date('d.m.Y', strtotime((string)$o['created_at']))) ?></small></td>
                    <td><?= e((string)($o['owner_email'] ?? '')) ?></td>
                    <td><?= e((string)$o['plan_code']) ?><br><small class="hint"><?= e((string)$o['subscription_status']) ?><?= (int)$o['onboarding_completed'] ? '' : ', Einrichtung offen' ?></small></td>
                    <td><?= (int)($o['lexoffice_connected'] ?? 0) ? 'Lexware' : '<span class="hint">kein Lexware</span>' ?> · <?= (int)($o['stripe_connected'] ?? 0) ? 'Stripe' : '<span class="hint">kein Stripe</span>' ?></td>
                    <td><?= $o['lexoffice_last_sync'] ? e(date('d.m.Y H:i', strtotime((string)$o['lexoffice_last_sync']))) : '-' ?></td>
                    <td><input type="text" name="reason" placeholder="Ticket / Grund" required minlength="5" maxlength="255" style="min-width: 160px; padding: 5px 8px; font-size: 13px;"></td>
                    <td><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Aktueller 2FA-Code" required class="code-input" style="max-width: 130px; padding: 5px 8px; font-size: 13px;"></td>
                    <td><button type="submit" class="btn btn-sm">Auf Firma wechseln</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orgs): ?><tr><td colspan="8" class="hint">Keine Firma gefunden.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="sitzungen">
    <h2>Aktive Support-Sitzungen (<?= count($active) ?>)</h2>
    <?php if (!$active): ?>
        <p class="hint">Derzeit läuft keine Support-Sitzung.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Firma</th><th>Administrator</th><th>Grund</th><th>Begonnen</th><th>Endet</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($active as $s): ?>
                <tr>
                    <td><?= e($s['org_name']) ?></td>
                    <td><?= e($s['admin_email']) ?></td>
                    <td><?= e($s['reason']) ?></td>
                    <td><?= e(date('d.m.Y H:i', strtotime((string)$s['created_at']))) ?></td>
                    <td><?= e(date('H:i', strtotime((string)$s['expires_at']))) ?></td>
                    <td><?= $s['redeemed_at'] ? 'aktiv' : 'Link noch nicht eingelöst' ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="support_revoke">
                            <input type="hidden" name="session_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Beenden</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card" id="benutzer">
    <h2>Benutzer entsperren, 2FA zurücksetzen</h2>
    <p class="hint">2FA-Reset nur nach eindeutiger Identitätsprüfung des Nutzers (z.B. Rückruf über bekannte Firmennummer). Wird als
        Support-Reset besonders protokolliert; der Nutzer erhält eine Sicherheits-E-Mail. Zur Bestätigung sind Ihr Passwort und 2FA-Code nötig.</p>
    <?php if ($locked): ?>
        <p class="hint">Derzeit gesperrte Konten: <?php foreach ($locked as $l): ?><code><?= e($l['email']) ?></code> (bis <?= e(date('H:i', strtotime((string)$l['locked_until']))) ?>) <?php endforeach; ?></p>
    <?php endif; ?>
    <form method="post" class="inline-form" style="flex-wrap: wrap; gap: 10px;">
        <?= csrf_field() ?>
        <input type="email" name="email" placeholder="E-Mail des Benutzers" required style="max-width: 280px;">
        <input type="password" name="password" placeholder="Ihr Passwort" required autocomplete="current-password" style="max-width: 200px;">
        <input type="text" name="code" placeholder="Ihr 2FA-Code" required class="code-input" style="max-width: 140px;" autocomplete="one-time-code">
        <button type="submit" name="action" value="user_unlock" class="btn btn-sm btn-secondary">Konto entsperren</button>
        <button type="submit" name="action" value="user_reset_2fa" class="btn btn-sm btn-danger"
                onclick="return confirm('2FA dieses Benutzers wirklich zurücksetzen?')">2FA zurücksetzen (Support)</button>
    </form>
</div>

<div class="card" id="protokoll">
    <h2>Protokoll der Support-Zugriffe</h2>
    <?php if (!$recent): ?>
        <p class="hint">Noch keine Support-Zugriffe.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Datum</th><th>Firma</th><th>Administrator</th><th>Grund</th><th>Eingelöst</th><th>Beendet</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $s): ?>
                <tr>
                    <td><?= e(date('d.m.Y H:i', strtotime((string)$s['created_at']))) ?></td>
                    <td><?= e($s['org_name']) ?></td>
                    <td><?= e($s['admin_email']) ?></td>
                    <td><?= e($s['reason']) ?></td>
                    <td><?= $s['redeemed_at'] ? e(date('H:i', strtotime((string)$s['redeemed_at']))) : 'nein' ?></td>
                    <td><?= $s['ended_at'] ? e(date('d.m.Y H:i', strtotime((string)$s['ended_at']))) . ' (' . e((string)$s['ended_by']) . ')' : 'läuft' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_footer($ctx); ?>
