<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';

$ctx = current_user();
if ($ctx) {
    audit_log($ctx['org_id'], $ctx, 'logout', 'user', $ctx['user_id']);
}
auth_logout();
redirect('login.php');
