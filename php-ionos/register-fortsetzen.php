<?php
/**
 * Registrierung mit bereits bekannter E-Mail-Adresse fortsetzen (Auftrag II, Abschnitt 4.2):
 * Der bestehende Benutzer hat sich regulär angemeldet (Passwort und 2FA) und schließt hier die
 * Anlage der weiteren Firma bewusst ab. Die zwischengespeicherten Firmendaten sind an den
 * vorgesehenen Benutzer gebunden; ein anderer Benutzer kann den Vorgang nicht übernehmen.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();
if (!empty($ctx['support_mode'])) {
    flash_set('error', 'Im Support-Modus können keine Firmen angelegt werden.');
    redirect('dashboard.php');
}

$req = registration_request_pending($ctx['user_id']);
if ($req !== null && !empty($req['foreign'])) {
    flash_set('error', 'Der offene Registrierungsvorgang gehört zu einem anderen Benutzerkonto und wurde verworfen. Melden Sie sich mit dem Konto an, dessen E-Mail-Adresse Sie bei der Registrierung angegeben haben.');
    redirect('dashboard.php');
}
if ($req === null) {
    flash_set('info', 'Es liegt kein offener Registrierungsvorgang vor. Weitere Firmen legen Sie über die Firmenübersicht an.');
    redirect('companies.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        registration_request_discard($req['id'], $ctx['user_id']);
        flash_set('info', 'Der Registrierungsvorgang wurde verworfen. Es wurde keine Firma angelegt.');
        redirect('dashboard.php');
    }
    if ($action === 'create') {
        $result = registration_request_complete($req['id'], $ctx['user_id']);
        if ($result['error']) {
            flash_set('error', 'Fehler: ' . $result['error']);
            redirect(!empty($_SESSION['register_continue']) ? 'register-fortsetzen.php' : 'companies.php');
        }
        switch_company($ctx['user_id'], $result['org_id']);
        flash_set('success', 'Die neue Firma wurde Ihrem bestehenden Benutzerkonto hinzugefügt. Über die Firmenübersicht können Sie zwischen Ihren Firmen wechseln.');
        redirect('companies.php');
    }
    redirect('register-fortsetzen.php');
}

layout_header('Weitere Firma anlegen', $ctx);
?>
<h1>Weitere Firma anlegen</h1>
<p class="page-sub">Sie sind als <?= e($ctx['email']) ?> angemeldet. Die folgenden Angaben stammen aus Ihrer Registrierung
    und werden erst mit Ihrer Bestätigung als neue Firma angelegt.</p>

<div class="card">
    <h2>Firmendaten aus der Registrierung</h2>
    <dl class="kv">
        <dt>Firmenname</dt><dd><?= e($req['org_name']) ?></dd>
        <dt>Mandatspräfix</dt><dd><?= e($req['mandate_prefix']) ?></dd>
        <dt>Ihre Rolle</dt><dd>Inhaber der neuen Firma. Ihre Rollen in anderen Firmen bleiben unverändert.</dd>
        <dt>Gültig bis</dt><dd><?= format_datetime($req['expires_at']) ?></dd>
    </dl>
    <p class="hint">Die neue Firma erhält vollständig getrennte Kunden, Rechnungen, Einzüge, Lexware Office- und Stripe-Zugänge
        sowie ein eigenes Abonnement. Ihr Passwort und Ihre Zwei-Faktor-Einrichtung bleiben unverändert.</p>
    <form method="post" action="register-fortsetzen.php" class="form-actions" style="flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn">Firma jetzt anlegen</button>
    </form>
    <form method="post" action="register-fortsetzen.php" style="margin-top: 8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="linklike">Abbrechen, keine Firma anlegen</button>
    </form>
</div>
<?php layout_footer($ctx); ?>
