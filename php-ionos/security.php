<?php
/**
 * Sicherheit des eigenen Kontos: Passwort ändern, Recovery-Codes neu
 * erzeugen, 2FA zurücksetzen (jeweils mit Passwort und aktuellem Code),
 * letzte Anmeldeversuche einsehen.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();
$user = user_load($ctx['user_id']);
$pdo = db();

$newCodes = $_SESSION['recovery_codes_show_security'] ?? null;
unset($_SESSION['recovery_codes_show_security']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'change_password') {
            if (($_POST['new_password'] ?? '') !== ($_POST['new_password2'] ?? '')) {
                throw new RuntimeException('Die neuen Passwörter stimmen nicht überein.');
            }
            if ($err = password_change($user, $_POST['current_password'] ?? '', $_POST['new_password'] ?? '')) {
                throw new RuntimeException($err);
            }
            flash_set('success', 'Passwort geändert.');

        } elseif ($action === 'regenerate_codes') {
            if ($err = verify_password_and_2fa($user, $_POST['password'] ?? '', $_POST['code'] ?? '')) {
                throw new RuntimeException($err);
            }
            $_SESSION['recovery_codes_show_security'] = recovery_codes_regenerate($user['id'], true);
            flash_set('success', 'Neue Recovery-Codes erzeugt. Alle bisherigen Codes sind ungültig.');

        } elseif ($action === 'reset_2fa') {
            if ($err = verify_password_and_2fa($user, $_POST['password'] ?? '', $_POST['code'] ?? '')) {
                throw new RuntimeException($err);
            }
            twofa_reset($user, false, ['user_id' => $user['id'], 'email' => $user['email']]);
            auth_logout();
            session_start();
            flash_set('info', 'Die Zwei-Faktor-Authentifizierung wurde zurückgesetzt. Bitte melden Sie sich an und richten Sie sie neu ein.');
            redirect('login.php');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('security.php');
}

$remaining = recovery_codes_remaining($user['id']);
$stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 15');
$stmt->execute([$user['email']]);
$attempts = $stmt->fetchAll();

layout_header('Sicherheit', $ctx);
?>
<h1>Sicherheit</h1>
<p class="page-sub">Konto <?= e($user['email']) ?></p>

<?php if ($newCodes): ?>
<div class="card">
    <h2>Neue Recovery-Codes</h2>
    <p>Bitte jetzt speichern. Diese Codes werden nicht erneut angezeigt.</p>
    <div class="recovery-codes">
        <?php foreach ($newCodes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card-grid">
    <div class="stat-card">
        <div class="stat-value"><?= (int)$user['totp_enabled'] ? 'Aktiv' : 'Nicht eingerichtet' ?></div>
        <div class="stat-label">Zwei-Faktor-Authentifizierung</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $remaining ?></div>
        <div class="stat-label">Verbleibende Recovery-Codes</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size: 18px;"><?= format_datetime($user['last_login_at']) ?></div>
        <div class="stat-label">Letzte Anmeldung</div>
    </div>
</div>

<div class="card">
    <h2>Passwort ändern</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label for="current_password">Aktuelles Passwort</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        <label for="new_password">Neues Passwort (mindestens 10 Zeichen)</label>
        <input type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
        <label for="new_password2">Neues Passwort wiederholen</label>
        <input type="password" id="new_password2" name="new_password2" required minlength="10" autocomplete="new-password">
        <div class="form-actions"><button type="submit" class="btn">Passwort ändern</button></div>
    </form>
</div>

<div class="card">
    <h2>Recovery-Codes neu erzeugen</h2>
    <p class="hint">Erzeugt zehn neue Einmal-Codes. Alle bisherigen Codes werden ungültig. Sie erhalten eine Sicherheits-E-Mail.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="regenerate_codes">
        <label for="rc_password">Passwort</label>
        <input type="password" id="rc_password" name="password" required autocomplete="current-password">
        <label for="rc_code">Aktueller Code aus der Authenticator-App (oder Recovery-Code)</label>
        <input type="text" id="rc_code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code">
        <div class="form-actions"><button type="submit" class="btn btn-secondary">Neue Codes erzeugen</button></div>
    </form>
</div>

<div class="card">
    <h2>Zwei-Faktor-Authentifizierung zurücksetzen</h2>
    <p class="hint">Zum Wechsel des Geräts. Nach dem Zurücksetzen werden Sie abgemeldet und richten die 2FA bei der
        nächsten Anmeldung neu ein. Ohne Passwort und aktuellen Code (oder Recovery-Code) ist kein Zurücksetzen möglich.
        Haben Sie beides verloren, wenden Sie sich an den Inhaber Ihres Firmenaccounts bzw. den Support.</p>
    <form method="post" onsubmit="return confirm('2FA wirklich zurücksetzen? Sie werden abgemeldet.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_2fa">
        <label for="rs_password">Passwort</label>
        <input type="password" id="rs_password" name="password" required autocomplete="current-password">
        <label for="rs_code">Aktueller Code (oder Recovery-Code)</label>
        <input type="text" id="rs_code" name="code" class="code-input" required inputmode="numeric" autocomplete="one-time-code">
        <div class="form-actions"><button type="submit" class="btn btn-danger">2FA zurücksetzen</button></div>
    </form>
</div>

<div class="card">
    <h2>Letzte Anmeldeversuche</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Zeitpunkt</th><th>Stufe</th><th>Ergebnis</th><th>IP-Adresse</th></tr></thead>
            <tbody>
            <?php foreach ($attempts as $a): ?>
                <tr>
                    <td><?= format_datetime($a['created_at']) ?></td>
                    <td><?= e(['password' => 'Passwort', 'totp' => 'Authenticator', 'recovery' => 'Recovery-Code'][$a['stage']] ?? $a['stage']) ?></td>
                    <td><?= (int)$a['success'] ? '<span class="badge badge-success">Erfolgreich</span>' : '<span class="badge badge-danger">Fehlgeschlagen</span>' ?></td>
                    <td><?= e($a['ip'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$attempts): ?><tr><td colspan="4" class="hint">Keine Einträge.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_footer($ctx); ?>
