<?php
/**
 * Support-Sitzung beenden: zurück zur eigenen Firma (falls vorhanden) oder Abmeldung,
 * danach Weiterleitung in den Adminbereich.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';

$target = admin_base_url() !== '' ? admin_base_url() . '/admin-support.php' : 'admin-support.php';
$ctx = current_user();
if (!$ctx || empty($ctx['support_mode'])) {
    redirect($ctx ? 'dashboard.php' : 'login.php');
}
support_session_end((string)$ctx['support_session_id'], 'admin', $ctx);
$prevOrg = (string)($_SESSION['support_prev_org_id'] ?? '');
unset($_SESSION['support_session_id'], $_SESSION['support_prev_org_id']);
if ($prevOrg !== '' && switch_company((string)$ctx['user_id'], $prevOrg)) {
    flash_set('success', 'Support-Modus beendet.');
} else {
    auth_logout();
}
redirect($target);
