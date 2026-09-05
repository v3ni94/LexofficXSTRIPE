<?php
/**
 * Einlösen eines Support-Tokens auf dem Kundenhost: startet die Support-Sitzung
 * des Plattformbetreibers in der gewählten Firma (siehe app/support.php).
 * Der Token ist einmalig und 5 Minuten gültig; der Inhaber der Firma erhält
 * eine Sicherheits-E-Mail.
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$row = support_session_redeem((string)($_GET['token'] ?? ''));
$user = $row ? user_load((string)$row['admin_user_id']) : null;
if (!$row || !$user) {
    http_response_code(400);
    layout_header('Support-Zugriff', null);
    echo '<div class="card"><h1>Support-Zugriff nicht möglich</h1>'
       . '<p>Der Link ist ungültig, bereits verwendet oder abgelaufen (5 Minuten). Bitte starten Sie den Zugriff im Adminbereich erneut.</p>'
       . '<p><a class="btn" href="' . e(admin_base_url() !== '' ? admin_base_url() . '/admin-support.php' : 'admin-support.php') . '">Zum Adminbereich</a></p></div>';
    layout_footer(null);
    exit;
}

$stmt = db()->prepare('SELECT id, name FROM organizations WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$row['organization_id']]);
$org = $stmt->fetch();
if (!$org) {
    support_session_end((string)$row['id'], 'revoked');
    flash_set('error', 'Die Firma existiert nicht mehr.');
    redirect('login.php');
}

// Eigene Firma merken, wenn derselbe Benutzer hier bereits regulär angemeldet war
$prevOrg = null;
if (($_SESSION['user_id'] ?? null) === $user['id'] && empty($_SESSION['support_session_id'])) {
    $prevOrg = $_SESSION['org_id'] ?? null;
}
if (!empty($_SESSION['support_session_id'])) {
    support_session_end((string)$_SESSION['support_session_id'], 'admin');
}

session_regenerate_id(true);
$_SESSION = [
    'user_id' => $user['id'],
    'org_id' => $org['id'],
    'session_epoch' => (int)$user['session_epoch'],
    'login_at' => time(),
    'support_session_id' => $row['id'],
    'support_prev_org_id' => $prevOrg,
];
$actor = ['user_id' => $user['id'], 'email' => $user['email']];
audit_log($org['id'], $actor, 'support_access_started', 'support_session', $row['id'], ['grund' => $row['reason'], 'firma' => $org['name']]);
security_notify_owner($org['id'], 'Support-Zugriff auf Ihren Firmenaccount', [
    sprintf('Der Plattformbetreiber (%s) hat am %s einen zeitlich begrenzten Support-Zugriff auf den Firmenaccount "%s" begonnen.',
        product_name(), date('d.m.Y \u\m H:i'), $org['name']),
    'Grund: ' . $row['reason'],
    'Im Support-Modus sind Einzüge, IBAN-Änderungen und Zugangsdaten gesperrt. Alle Aktionen werden im Protokoll Ihrer Firma mit Support-Vermerk aufgeführt (Menü Firma, Abschnitt Protokoll).',
    'Wenn Sie diesen Zugriff nicht erwartet haben, antworten Sie bitte auf diese E-Mail.',
]);
flash_set('info', 'Support-Modus für die Firma ' . $org['name'] . ' aktiv (höchstens ' . SUPPORT_SESSION_MINUTES . ' Minuten). Beenden über den Link im gelben Hinweis.');
redirect('dashboard.php');
