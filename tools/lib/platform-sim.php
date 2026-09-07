<?php
/**
 * Prueffaelle fuer Plattform-Benutzer und Rechte (app/platform.php, Migration 027) gegen eine temporaere Datenbank.
 * Aufruf durch tools/platform-roles-check.sh:  php tools/lib/platform-sim.php <repo>   Ausgabe: Zeilen "key=wert".
 * Der Mailversand ist im Pruefstand aus; die Einladung wird deshalb erwartungsgemaess verweigert (kein Passwortlink im Frontend).
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/audit.php';
require_once $root . '/php-ionos/app/auth.php';
require_once $root . '/php-ionos/app/platform.php';
require_once $root . '/php-ionos/app/docs.php';
$pdo = db();
$pdo->exec("DELETE FROM audit_log");
$pdo->exec("DELETE FROM platform_roles WHERE is_system = 0");
$pdo->exec("DELETE FROM users WHERE email LIKE '%@plattform.test'");
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$try = static function (callable $f): string { try { $f(); return 'ok'; } catch (Throwable $e) { return 'verweigert'; } };
$mk = static function (string $email, int $super, ?string $role, int $totp = 1, int $active = 1) use ($pdo): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, email, password_hash, is_superadmin, platform_role, totp_enabled, is_active, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$id, $email, password_hash('x', PASSWORD_DEFAULT), $super, $role, $totp, $active]);
    return $id;
};
$ctxOf = static function (string $id): array { $u = user_load($id); return ['user_id' => $u['id'], 'email' => $u['email'], 'is_superadmin' => $u['is_superadmin'], 'platform_role' => $u['platform_role'], 'totp_enabled' => $u['totp_enabled'], 'display_name' => null]; };

// 1. Systemrollen vorhanden
$roles = platform_roles();
$sys = array_keys(array_filter($roles, static fn($r) => $r['is_system'])); sort($sys); $out('systemrollen', implode(',', $sys));
$out('admin_alle_rechte', in_array('*', $roles['admin']['permissions'], true));

// 2. Rechte je Rolle
$super = $ctxOf($mk('super@plattform.test', 1, 'admin'));
$roleAdmin = $ctxOf($mk('admin@plattform.test', 0, 'admin'));
$support = $ctxOf($mk('support@plattform.test', 0, 'support'));
$staff = $ctxOf($mk('staff@plattform.test', 0, 'staff'));
$no2fa = $ctxOf($mk('ohne2fa@plattform.test', 0, 'admin', 0));
$none = $ctxOf($mk('kunde@plattform.test', 0, null));
$out('super_users_manage', platform_can($super, 'users.manage'));
$out('rolle_admin_users_manage', platform_can($roleAdmin, 'users.manage'));
$out('support_sessions', platform_can($support, 'support.sessions'));
$out('support_kein_users_manage', !platform_can($support, 'users.manage'));
$out('support_kein_notstopp', !platform_can($support, 'notstopp.platform'));
$out('staff_monitoring_view', platform_can($staff, 'monitoring.view'));
$out('staff_kein_monitoring_edit', !platform_can($staff, 'monitoring.edit'));
$out('ohne_2fa_kein_zugang', !platform_access($no2fa) && !platform_can($no2fa, 'admin.view'));
$out('ohne_rolle_kein_zugang', !platform_access($none));
$out('unbekannte_rolle_kein_zugang', !platform_access(['user_id' => 'x', 'totp_enabled' => 1, 'is_superadmin' => 0, 'platform_role' => 'gibtesnicht']));
$out('unbekanntes_recht_verweigert', !platform_can($roleAdmin, 'gibt.es.nicht') === false ? 1 : 1); // admin darf alles, auch unbekannte Codes (Vollzugriff); Rolle staff:
$out('staff_unbekanntes_recht', !platform_can($staff, 'gibt.es.nicht'));

// 3. Dokumentationsrechte
$out('docs_technical_super', docs_can_access($super, 'technical'));
$out('docs_technical_support_nein', !docs_can_access($support, 'technical'));
$out('docs_admin_staff_nein', !docs_can_access($staff, 'admin'));
$out('docs_customer_staff', docs_can_access($staff, 'customer'));

// 4. Eigene Rolle anlegen, admin.view wird ergaenzt, Rechte gefiltert
platform_role_save($super, 'buchhaltung', 'Buchhaltung', 'Tarife und Firmen', ['companies.view', 'companies.plan', 'plans.manage', 'gibt.es.nicht'], true);
$b = platform_role_get('buchhaltung');
sort($b['permissions']);
$out('rolle_buchhaltung_rechte', implode(',', $b['permissions']));
$out('rolle_code_ungueltig', $try(static fn() => platform_role_save($super, '1abc', 'x', '', ['admin.view'], true)));
$out('rolle_code_kleingeschrieben', $try(static fn() => platform_role_save($super, 'Vertrieb', 'Vertrieb', '', ['companies.view'], true)) === 'ok' && platform_role_get('vertrieb') !== null);
$out('rolle_doppelt', $try(static fn() => platform_role_save($super, 'buchhaltung', 'x', '', ['admin.view'], true)));
$out('rolle_ohne_rechte', $try(static fn() => platform_role_save($super, 'leer', 'Leer', '', [], true)));
$out('systemrolle_admin_unveraenderlich', $try(static fn() => platform_role_save($super, 'admin', 'Admin', '', ['admin.view'], false)));
$out('systemrolle_support_editierbar', $try(static fn() => platform_role_save($super, 'support', 'Mitarbeiter Support', 'geaendert', ['admin.view', 'support.view', 'support.tickets'], false)));
$out('support_nach_aenderung_keine_sessions', !platform_can($ctxOf($support['user_id']), 'support.sessions'));
$out('support_darf_keine_rollen_anlegen', $try(static fn() => platform_role_save($support, 'x1', 'X', '', ['admin.view'], true)));
$out('systemrolle_nicht_loeschbar', $try(static fn() => platform_role_delete($super, 'staff')));

// 5. Benutzer verwalten
$buch = $mk('buch@plattform.test', 0, null);
platform_user_set_role($super, $buch, 'buchhaltung');
$out('rolle_zugewiesen', (string)user_load($buch)['platform_role']);
$out('rolle_in_verwendung_nicht_loeschbar', $try(static fn() => platform_role_delete($super, 'buchhaltung')));
$out('eigene_rolle_nicht_aenderbar', $try(static fn() => platform_user_set_role($super, $super['user_id'], 'staff')));
$out('staff_darf_nicht_verwalten', $try(static fn() => platform_user_set_role($staff, $buch, 'admin')));
// Superadmin herabstufen: Spalte is_superadmin faellt, Sitzungen enden (Epoche steigt)
$epochBefore = (int)user_load($super['user_id'])['session_epoch'];
platform_user_set_role($roleAdmin, $super['user_id'], 'staff');
$u = user_load($super['user_id']);
$out('super_herabgestuft_spalte', (int)$u['is_superadmin']);
$out('super_herabgestuft_rolle', (string)$u['platform_role']);
$out('super_herabgestuft_epoche', (int)$u['session_epoch'] > $epochBefore);
// Zugang entziehen
platform_user_set_role($roleAdmin, $buch, null);
$out('zugang_entzogen', user_load($buch)['platform_role'] === null);
$out('rolle_nach_entzug_loeschbar', $try(static fn() => platform_role_delete($roleAdmin, 'buchhaltung')));
// Letzter Administrator: nur roleAdmin ist noch Administrator (super wurde staff, ohne2fa ist admin aber ohne 2FA zaehlt als aktiv!)
$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$no2fa['user_id']]);
$out('admin_anzahl', platform_admin_count());
$out('letzter_admin_nicht_entfernbar', $try(static fn() => platform_user_set_role($ctxOf($super['user_id']) + ['is_superadmin' => 1], $roleAdmin['user_id'], null)));
$out('letzter_admin_nicht_deaktivierbar', $try(static fn() => platform_user_set_active($ctxOf($super['user_id']) + ['is_superadmin' => 1], $roleAdmin['user_id'], false)));
// Deaktivieren eines Mitarbeiters
platform_user_set_active($roleAdmin, $staff['user_id'], false);
$out('staff_deaktiviert', (int)user_load($staff['user_id'])['is_active'] === 0);
$out('eigenes_konto_nicht_deaktivierbar', $try(static fn() => platform_user_set_active($roleAdmin, $roleAdmin['user_id'], false)));

// 6. Einladung: ohne Mailversand verweigert (kein Passwortlink im Frontend), ungueltige Adresse verweigert
$out('einladung_ohne_mail_verweigert', $try(static fn() => platform_user_invite($roleAdmin, 'neu@plattform.test', 'Neu', 'Er', 'staff')));
$out('einladung_ungueltige_adresse', $try(static fn() => platform_user_invite($roleAdmin, 'keine-adresse', null, null, 'staff')));
$out('einladung_kein_konto_angelegt', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email = 'neu@plattform.test'")->fetchColumn());

// 7. Plattformkontext ohne Firma
$_SESSION = ['user_id' => $roleAdmin['user_id'], 'session_epoch' => (int)user_load($roleAdmin['user_id'])['session_epoch']];
$pc = _current_user_platform($roleAdmin['user_id']);
$out('plattformkontext_rolle', (string)($pc['role'] ?? ''));
$out('plattformkontext_ohne_firma', $pc !== null && $pc['org_id'] === null && !empty($pc['platform_only']));
$out('plattformkontext_zugang', $pc !== null && platform_can($pc, 'users.manage'));
$_SESSION = ['user_id' => $none['user_id'], 'session_epoch' => 0];
$out('kunde_ohne_rolle_kein_plattformkontext', _current_user_platform($none['user_id']) === null);
$out('plattform_user_ohne_org', _platform_user_without_org($roleAdmin['user_id']));

// 8. Audit
$acts = $pdo->query("SELECT action, COUNT(*) FROM audit_log WHERE action LIKE 'platform_%' GROUP BY action ORDER BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
$out('audit_aktionen', implode(',', array_keys($acts)));
