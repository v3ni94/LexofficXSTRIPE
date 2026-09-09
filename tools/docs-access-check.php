<?php
/**
 * Zugriffsregeln des Dokumentationssystems (app/docs.php) ohne Webserver und ohne Datenbank pruefen:
 * Stufen technical/admin/customer, Standard "verweigern" fuer technical ohne Leserliste, Manifest-Allowlist,
 * Ablehnung von Pfadmanipulationen im Archiv. Aufruf: php tools/docs-access-check.php   (Exit 0 = alles gruen)
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$GLOBALS['config'] = ['docs' => ['technical_readers' => ['Tech@Example.test'], 'archive_dir' => sys_get_temp_dir() . '/docs-archive-test-' . getmypid()]];
require $root . '/php-ionos/app/docs.php';
$pass = 0; $fail = 0;
$ok = static function (string $n, bool $c) use (&$pass, &$fail): void { $c ? $pass++ : $fail++; echo ($c ? '  OK    ' : '  FAIL  ') . $n . "\n"; };

$super = ['user_id' => 'u1', 'email' => 'admin@example.test', 'is_superadmin' => 1, 'totp_enabled' => 1];
$superNo2fa = ['user_id' => 'u1', 'email' => 'admin@example.test', 'is_superadmin' => 1, 'totp_enabled' => 0];
$tech = ['user_id' => 'u2', 'email' => 'tech@example.test', 'is_superadmin' => 1, 'totp_enabled' => 1];
$techNoSuper = ['user_id' => 'u3', 'email' => 'tech@example.test', 'is_superadmin' => 0, 'totp_enabled' => 1];
$owner = ['user_id' => 'u4', 'email' => 'inhaber@firma.test', 'is_superadmin' => 0, 'totp_enabled' => 1, 'role' => 'owner'];
$orgAdmin = ['user_id' => 'u5', 'email' => 'admin@firma.test', 'is_superadmin' => 0, 'totp_enabled' => 1, 'role' => 'admin'];
$anon = [];

echo "1) Zugriffsstufen\n";
$ok('technical: Plattformadministrator mit 2FA erlaubt (Entscheidung 07.09.2026)', docs_can_access($super, 'technical'));
$ok('technical: eingetragener Superadmin erlaubt (Gross-/Kleinschreibung egal)', docs_can_access($tech, 'technical'));
$ok('technical: eingetragene Adresse ohne Superadmin und ohne Plattformadminrolle verweigert', !docs_can_access($techNoSuper, 'technical'));
$ok('technical: eingetragene Adresse mit Plattformadminrolle erlaubt', docs_can_access($techNoSuper + ['platform_admin' => true], 'technical'));
$ok('technical: Firmenadministrator verweigert', !docs_can_access($orgAdmin, 'technical'));
$ok('technical: Inhaber verweigert', !docs_can_access($owner, 'technical'));
$ok('admin: Superadmin mit 2FA erlaubt', docs_can_access($super, 'admin'));
$ok('admin: Superadmin ohne 2FA verweigert', !docs_can_access($superNo2fa, 'admin'));
$ok('admin: Inhaber verweigert', !docs_can_access($owner, 'admin'));
$ok('customer: angemeldeter Inhaber erlaubt', docs_can_access($owner, 'customer'));
$ok('customer: Superadmin erlaubt', docs_can_access($super, 'customer'));
$ok('customer: nicht angemeldet verweigert', !docs_can_access($anon, 'customer'));
$ok('unbekannte Stufe verweigert', !docs_can_access($super, 'sonstwas'));
$GLOBALS['config']['docs']['technical_readers'] = [];
$ok('technical: leere Leserliste, Plattformadministratoren weiterhin erlaubt, Rolle ohne Eintrag verweigert', docs_can_access($super, 'technical') && !docs_can_access($techNoSuper + ['platform_admin' => true], 'technical'));
$ok('technical: Superadmin ohne 2FA verweigert', !docs_can_access($superNo2fa, 'technical'));

echo "2) Manifest-Allowlist\n";
$m = ['files' => [['name' => 'kunden.pdf', 'kind' => 'pdf', 'access' => 'customer', 'doc' => 'kunden'], ['name' => 'entwickler/index.html', 'kind' => 'html', 'access' => 'technical', 'doc' => 'entwickler']]];
$ok('gelistete Datei gefunden', (docs_manifest_entry($m, 'kunden.pdf')['access'] ?? '') === 'customer');
$ok('nicht gelistete Datei abgelehnt', docs_manifest_entry($m, 'entwickler.pdf') === null);
$ok('Pfadmanipulation nicht im Manifest', docs_manifest_entry($m, '../config.php') === null && docs_manifest_entry($m, 'entwickler/../../app/config.php') === null);
$ok('manifest.json selbst nur admin', (docs_manifest_entry($m, 'manifest.json')['access'] ?? '') === 'admin');

echo "3) Archiv\n";
$dir = $GLOBALS['config']['docs']['archive_dir'];
@mkdir($dir, 0700, true); @mkdir($dir . '/4.32_abc123', 0700);
file_put_contents($dir . '/4.32_abc123/manifest.json', json_encode(['schema' => 2, 'version' => '4.32', 'commit' => 'abc123', 'generated_at' => 'x', 'documents' => [], 'files' => []]));
$ok('Archivstand gelistet', count(docs_archive_list()) === 1 && docs_archive_list()[0]['id'] === '4.32_abc123');
$ok('gueltige Kennung aufgeloest', docs_archive_path('4.32_abc123') !== null);
$ok('Pfadmanipulation in Kennung abgelehnt', docs_archive_path('../') === null && docs_archive_path('..') === null && docs_archive_path('4.32_abc123/../..') === null);
$ok('unbekannte Kennung abgelehnt', docs_archive_path('gibt-es-nicht') === null);
@unlink($dir . '/4.32_abc123/manifest.json'); @rmdir($dir . '/4.32_abc123'); @rmdir($dir);

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
