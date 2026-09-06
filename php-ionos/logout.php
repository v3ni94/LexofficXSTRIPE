<?php
/**
 * Abmelden. GET: Sitzung beenden, eine eingerichtete Gerätefreigabe bleibt bestehen.
 * POST action=forget (mit CSRF): Sitzung beenden und die Freigabe dieses Browsers widerrufen
 * ("Abmelden und Gerät vergessen", Abschnitt 5.9).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';

$forget = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $forget = ($_POST['action'] ?? '') === 'forget';
}

$ctx = current_user();
if ($ctx) {
    audit_log($ctx['org_id'], $ctx, 'logout', 'user', $ctx['user_id'], $forget ? ['forget_device' => true] : []);
}
auth_logout($forget);
if ($forget) {
    session_start();
    flash_set('info', 'Sie wurden abgemeldet. Die Gerätefreigabe für diesen Browser wurde widerrufen.');
}
redirect('login.php');
