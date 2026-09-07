<?php
/**
 * Prueffaelle Wechsel des Buchhaltungssystems (app/invoice_source_switch.php) gegen eine temporaere Datenbank.
 * Aufruf durch tools/invoice-source-check.sh:  php tools/lib/invoice-source-sim.php <repo>   Ausgabe: "key=wert".
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/invoice_source_switch.php';
$pdo = db();
$org = '33333333-3333-3333-3333-333333333333';
$pdo->exec("DELETE FROM audit_log"); $pdo->exec("DELETE FROM platform_settings WHERE `key` LIKE 'sevdesk_%'");
$pdo->exec("DELETE FROM organizations WHERE id = '$org'");
$pdo->exec("INSERT INTO organizations (id, name, mandate_prefix) VALUES ('$org','Firma C','FC')");
$pdo->exec("INSERT INTO integrations (id, tenant_id, lexoffice_api_key_encrypted, lexoffice_connected) VALUES ('44444444-4444-4444-4444-444444444444','$org','geheim',1)");
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$owner = ['user_id' => null, 'email' => 'inhaber@firma-c.test', 'role' => 'owner', 'org_id' => $org];
$member = ['user_id' => null, 'email' => 'mitarbeiter@firma-c.test', 'role' => 'member', 'org_id' => $org];

$cur = invoice_source_current($org);
$out('start_code', $cur['code']); $out('start_label', $cur['label']);
// 1. sevdesk ohne Freigabe: blockiert
$out('blocker_ohne_freigabe', invoice_source_switch_blocker($org, 'sevdesk') !== null ? 1 : 0);
$out('blocker_gleiches_system', invoice_source_switch_blocker($org, 'lexware_office') !== null ? 1 : 0);
$out('blocker_unbekannt', invoice_source_switch_blocker($org, 'datev') !== null ? 1 : 0);
// Registrierungsvorwahl ohne Freigabe wirkt nicht
invoice_source_apply_signup($org, 'sevdesk');
$out('signup_ohne_freigabe_ignoriert', invoice_source_current($org)['code'] === 'lexware_office' ? 1 : 0);
// 2. Freigabe setzen
$pdo->exec("INSERT INTO platform_settings (`key`, `value`) VALUES ('sevdesk_connect', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
$out('freigabe_aktiv', invoice_source_available('sevdesk') ? 1 : 0);
$out('blocker_mit_freigabe_frei', invoice_source_switch_blocker($org, 'sevdesk') === null ? 1 : 0);
// 3. Mitarbeiter darf nicht
try { invoice_source_switch($member, 'sevdesk'); $out('member_verweigert', 0); } catch (Throwable $e) { $out('member_verweigert', 1); }
// 4. Offener Einzug blockiert
$pdo->exec("INSERT INTO invoices (id, tenant_id, lexoffice_invoice_id, voucher_number, contact_name, total_gross_amount, lexoffice_status) VALUES ('55555555-5555-5555-5555-555555555555','$org','lx-1','RE-1','Kunde',100.00,'open')");
$pdo->exec("INSERT INTO payment_collections (id, tenant_id, invoice_id, amount_cents, stripe_status) VALUES ('66666666-6666-6666-6666-666666666666','$org','55555555-5555-5555-5555-555555555555',10000,'processing')");
$out('blocker_offener_einzug', invoice_source_switch_blocker($org, 'sevdesk') !== null ? 1 : 0);
try { invoice_source_switch($owner, 'sevdesk'); $out('switch_trotz_einzug_verweigert', 0); } catch (Throwable $e) { $out('switch_trotz_einzug_verweigert', 1); }
$pdo->exec("UPDATE payment_collections SET stripe_status = 'succeeded' WHERE id = '66666666-6666-6666-6666-666666666666'");
$out('abgeschlossener_einzug_kein_hindernis', invoice_source_switch_blocker($org, 'sevdesk') === null ? 1 : 0);
// 5. Wechsel durch Inhaber
invoice_source_switch($owner, 'sevdesk', 'Umstieg');
$cur = invoice_source_current($org);
$out('nach_wechsel_code', $cur['code']); $out('nach_wechsel_zaehler', $cur['switches']); $out('nach_wechsel_zeit', $cur['changed_at'] ? 1 : 0);
$row = $pdo->query("SELECT lexoffice_connected, lexoffice_api_key_encrypted FROM integrations WHERE tenant_id = '$org'")->fetch();
$out('alte_verbindung_getrennt', ((int)$row['lexoffice_connected'] === 0 && $row['lexoffice_api_key_encrypted'] === null) ? 1 : 0);
$out('historie_bleibt', (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE tenant_id = '$org'")->fetchColumn());
$a = $pdo->query("SELECT details_json FROM audit_log WHERE action = 'invoice_source_switched'")->fetchAll(PDO::FETCH_COLUMN);
$d = $a ? json_decode((string)$a[0], true) : [];
$out('audit_wechsel', count($a)); $out('audit_from_to', (($d['from'] ?? '') . '>' . ($d['to'] ?? '')));
// 6. Sperre vier Wochen
$lock = invoice_source_lock($org);
$out('sperre_aktiv', $lock['locked'] ? 1 : 0); $out('sperre_tage', $lock['days_left']);
try { invoice_source_switch($owner, 'lexware_office'); $out('rueckwechsel_gesperrt', 0); } catch (Throwable $e) { $out('rueckwechsel_gesperrt', str_contains($e->getMessage(), 'vier Wochen') ? 1 : 0); }
// 27 Tage spaeter: noch gesperrt; 29 Tage: frei
$pdo->exec("UPDATE integrations SET invoice_source_changed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 27 DAY) WHERE tenant_id = '$org'");
$out('tag27_gesperrt', invoice_source_lock($org)['locked'] ? 1 : 0);
$pdo->exec("UPDATE integrations SET invoice_source_changed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 29 DAY) WHERE tenant_id = '$org'");
$out('tag29_frei', invoice_source_lock($org)['locked'] ? 0 : 1);
invoice_source_switch($owner, 'lexware_office');
$cur = invoice_source_current($org);
$out('rueckwechsel_code', $cur['code']); $out('rueckwechsel_zaehler', $cur['switches']);
$out('rueckwechsel_verbindung_offen', (int)$pdo->query("SELECT lexoffice_connected FROM integrations WHERE tenant_id = '$org'")->fetchColumn() === 0 ? 1 : 0);
// 7. Registrierungsvorwahl mit Freigabe wirkt
$pdo->exec("UPDATE integrations SET invoice_source_changed_at = NULL WHERE tenant_id = '$org'");
invoice_source_apply_signup($org, 'sevdesk');
$out('signup_mit_freigabe', invoice_source_current($org)['code']);
invoice_source_apply_signup($org, 'datev');
$out('signup_unbekannt_ignoriert', invoice_source_current($org)['code']);
