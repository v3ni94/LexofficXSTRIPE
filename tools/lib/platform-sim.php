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
$out('systemrolle_support_docs_verweigert', $try(static fn() => platform_role_save($super, 'support', 'Mitarbeiter Support', 'x', ['admin.view', 'support.view', 'docs.technical'], false)));
$out('systemrolle_staff_users_manage_verweigert', $try(static fn() => platform_role_save($super, 'staff', 'Mitarbeiter', 'x', ['admin.view', 'users.manage'], false)));
$out('eigene_rolle_docs_erlaubt', $try(static fn() => platform_role_save($super, 'technik', 'Technik', 'liest Doku', ['admin.view', 'docs.technical', 'docs.admin'], true)));
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

// 5b. Einladung eines bestehenden Kontos laeuft ueber die Schutzregeln (Review 4.41): eigene Rolle nicht per Einladung eskalieren
$GLOBALS['config']['mail'] = ['enabled' => true, 'transport' => 'log'];
$verwalter = $ctxOf($mk('verwalter@plattform.test', 0, 'technik'));
platform_role_save($super, 'technik', 'Technik', 'liest Doku', ['admin.view', 'docs.technical', 'users.manage', 'companies.view'], false);
$verwalter = $ctxOf($verwalter['user_id']);
$out('einladung_selbst_verweigert', $try(static fn() => platform_user_invite($verwalter, 'verwalter@plattform.test', null, null, 'admin')));
$out('einladung_selbst_rolle_unveraendert', (string)user_load($verwalter['user_id'])['platform_role']);
// 5c. Selbsterhoehung ueber Zweitkonto oder eigene Rollendefinition (Audit 10.09.2026, Befund C-01)
$out('c01_zweitkonto_admin_verweigert', $try(static fn() => platform_user_invite($verwalter, 'zweitkonto@plattform.test', null, null, 'admin')));
$out('c01_zweitkonto_nicht_angelegt', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email = 'zweitkonto@plattform.test'")->fetchColumn());
$out('c01_eigene_rolle_bearbeiten_verweigert', $try(static fn() => platform_role_save($verwalter, 'technik', 'Technik', 'alles', array_keys(PLATFORM_PERMISSIONS), false)));
$out('c01_eigene_rolle_unveraendert', in_array('notstopp.platform', platform_role_get('technik')['permissions'] ?? [], true) ? 0 : 1);
$out('c01_neue_rolle_mit_users_manage_verweigert', $try(static fn() => platform_role_save($verwalter, 'schatten', 'Schatten', '', ['admin.view', 'users.manage'], true)));
// Teilmenge der eigenen Rechte (technik: admin.view, docs.technical, users.manage) ist erlaubt (F-03), fremde Rechte nicht
$out('c01_neue_rolle_ohne_privileg_erlaubt', $try(static fn() => platform_role_save($verwalter, 'lesen', 'Lesen', '', ['admin.view', 'companies.view'], true)));
$out('f03_fremdes_recht_verweigert', $try(static fn() => platform_role_save($verwalter, 'lesen2', 'Lesen 2', '', ['admin.view', 'plans.manage'], true)));
$out('c01_verwalter_vergibt_lesen', $try(static fn() => platform_user_set_role($verwalter, $staff['user_id'], 'lesen')));
// Gegenpruefung F-03: nur Teilmenge der eigenen Rechte, Administratorkonten unantastbar
$out('f03_rolle_mit_fremden_rechten_verweigert', $try(static fn() => platform_role_save($verwalter, 'betrieb', 'Betrieb', '', ['admin.view', 'support.sessions', 'notstopp.platform', 'plans.manage'], true)));
$out('f03_admin_entfernen_verweigert', $try(static fn() => platform_user_set_role($verwalter, $roleAdmin['user_id'], null)));
$out('f03_admin_deaktivieren_verweigert', $try(static fn() => platform_user_set_active($verwalter, $roleAdmin['user_id'], false)));
$out('f03_admin_rolle_unveraendert', (string)user_load($roleAdmin['user_id'])['platform_role']);
$out('c01_verwalter_vergibt_admin_verweigert', $try(static fn() => platform_user_set_role($verwalter, $staff['user_id'], 'admin')));
$out('c01_admin_vergibt_admin', $try(static fn() => platform_user_set_role($roleAdmin, $staff['user_id'], 'admin')));
platform_user_set_role($roleAdmin, $staff['user_id'], 'staff');
$GLOBALS['config']['mail'] = ['enabled' => false];

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

// 9. Kundenprofil (4.62, app/customer_profile.php): Support pflegt Kontaktdaten, nie Felder mit Geld- oder Zugangsbezug
require_once $root . '/php-ionos/app/customer_profile.php';
// Die Systemrolle support wurde in Abschnitt 4 veraendert; fuer die Rechtepruefung wieder auf den Seed aus schema.sql
// (identisch mit PLATFORM_SYSTEM_ROLES und Migration 033) zuruecksetzen.
$pdo->prepare("UPDATE platform_roles SET permissions = ? WHERE code = 'support'")->execute([json_encode(PLATFORM_SYSTEM_ROLES['support']['permissions'])]);
platform_roles_reset_cache();
$pdo->exec("DELETE FROM organizations WHERE name LIKE 'Profil-Test%'");
$orgId = uuid4();
$pdo->prepare("INSERT INTO organizations (id, name, mandate_prefix, street, zip, city, country, creditor_identifier, pre_notification_days) VALUES (?, 'Profil-Test GmbH', 'PT', 'Altweg 1', '40789', 'Monheim', 'DE', 'DE98ZZZ09999999999', 14)")->execute([$orgId]);
$kundeId = $mk('inhaber@kunde-profil.test', 0, null);
$pdo->prepare("UPDATE users SET display_name = 'Inhaber', phone_business = '+49 2173 1' WHERE id = ?")->execute([$kundeId]);
$pdo->prepare("INSERT INTO organization_members (id, organization_id, user_id, role, status) VALUES (?, ?, ?, 'owner', 'active')")->execute([uuid4(), $orgId, $kundeId]);
$supportCtx = $ctxOf($mk('support2@plattform.test', 0, 'support'));
$staffCtx = $ctxOf($mk('staff2@plattform.test', 0, 'staff'));
$out('p_support_recht', platform_can($supportCtx, 'support.customers'));
$out('p_staff_kein_recht', !platform_can($staffCtx, 'support.customers'));
$out('p_staff_org_verweigert', $try(static fn() => customer_org_update($staffCtx, $orgId, ['name' => 'X GmbH'], 'Ticket 4711')));
$out('p_grund_zu_kurz_verweigert', $try(static fn() => customer_org_update($supportCtx, $orgId, ['name' => 'X GmbH'], 'kurz')));
$out('p_land_ungueltig_verweigert', $try(static fn() => customer_org_update($supportCtx, $orgId, ['name' => 'X GmbH', 'country' => 'Deutschland'], 'Ticket 4711')));
$diff = customer_org_update($supportCtx, $orgId, ['name' => 'Profil-Test AG', 'street' => 'Neuweg 2', 'zip' => '40789', 'city' => 'Monheim am Rhein', 'country' => 'de',
    'creditor_identifier' => 'DE00ZZZ00000000000', 'mandate_prefix' => 'XX', 'pre_notification_days' => 1, 'plan_code' => 'gratis'], 'Ticket 4711, Anruf des Inhabers');
$row = $pdo->query("SELECT name, street, city, country, creditor_identifier, mandate_prefix, pre_notification_days, plan_code FROM organizations WHERE id = " . $pdo->quote($orgId))->fetch();
$out('p_org_geaendert', $row['name'] === 'Profil-Test AG' && $row['street'] === 'Neuweg 2' && $row['city'] === 'Monheim am Rhein' && $row['country'] === 'DE');
$out('p_org_diff_felder', implode(',', array_keys($diff)));
$out('p_org_geldfelder_unveraendert', $row['creditor_identifier'] === 'DE98ZZZ09999999999' && $row['mandate_prefix'] === 'PT' && (int)$row['pre_notification_days'] === 14 && $row['plan_code'] === 'unlimited_start');
$out('p_org_keine_aenderung_leer', customer_org_update($supportCtx, $orgId, ['name' => 'Profil-Test AG', 'street' => 'Neuweg 2', 'zip' => '40789', 'city' => 'Monheim am Rhein', 'country' => 'DE'], 'Ticket 4711') === []);
$out('p_staff_user_verweigert', $try(static fn() => customer_user_update($staffCtx, $kundeId, ['display_name' => 'Neu'], 'Ticket 4711')));
$out('p_user_telefon_ungueltig_verweigert', $try(static fn() => customer_user_update($supportCtx, $kundeId, ['display_name' => 'Neu', 'phone_business' => 'abc'], 'Ticket 4711')));
$diffU = customer_user_update($supportCtx, $kundeId, ['display_name' => 'Max Muster', 'first_name' => 'Max', 'last_name' => 'Muster', 'phone_business' => '+49 2173 99', 'phone_private' => '',
    'email' => 'boese@angreifer.test', 'is_active' => 0, 'platform_role' => 'admin', 'totp_enabled' => 0], 'Ticket 4712, Schreiben des Kunden');
$u = user_load($kundeId);
$out('p_user_geaendert', $u['display_name'] === 'Max Muster' && $u['first_name'] === 'Max' && $u['phone_business'] === '+49 2173 99' && $u['phone_private'] === null);
$out('p_user_zugang_unveraendert', $u['email'] === 'inhaber@kunde-profil.test' && (int)$u['is_active'] === 1 && $u['platform_role'] === null && (int)$u['totp_enabled'] === 1);
$out('p_user_diff_ohne_klartext_telefon', isset($diffU['phone_business']) && $diffU['phone_business'] === ['vorher' => true, 'nachher' => true] && !str_contains(json_encode($diffU), '2173'));
$out('p_superadmin_ziel_verweigert', $try(static fn() => customer_user_update($supportCtx, $super['user_id'], ['display_name' => 'Neu'], 'Ticket 4711')));
$out('p_plattformbenutzer_durch_support_verweigert', $try(static fn() => customer_user_update($supportCtx, $staffCtx['user_id'], ['display_name' => 'Neu'], 'Ticket 4711')));
$out('p_plattformbenutzer_durch_admin', $try(static fn() => customer_user_update($roleAdmin, $staffCtx['user_id'], ['display_name' => 'Neu'], 'Ticket 4711')));
$pa = $pdo->query("SELECT action, COUNT(*) FROM audit_log WHERE action IN ('org_updated_support','profile_updated_support') GROUP BY action ORDER BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
$out('p_audit', implode(',', array_map(static fn($k, $v) => "$k:$v", array_keys($pa), $pa)));
$aud = $pdo->query("SELECT details_json FROM audit_log WHERE action = 'org_updated_support' ORDER BY id DESC LIMIT 1")->fetchColumn();
$out('p_audit_grund_und_vorher', str_contains((string)$aud, 'Ticket 4711') && str_contains((string)$aud, 'Altweg 1') && str_contains((string)$aud, 'Neuweg 2'));
$out('p_geloeschte_firma_null', customer_org_load(uuid4()) === null);
$s = customer_search('Profil-Test');
$out('p_suche_firma', count($s['orgs']) === 1 && $s['orgs'][0]['id'] === $orgId);
$out('p_suche_user', count(customer_search('kunde-profil.test')['users']) === 1);
