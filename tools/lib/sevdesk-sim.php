<?php
/**
 * Prueffaelle fuer den sevdesk-Adapter (app/sevdesk.php), Freigabetermin (app/integration_state.php), Verbindung je Firma,
 * Wechselsperre-Reset und Scheduler-Auswahl gegen eine temporaere MariaDB und den lokalen HTTP-Stub (tools/lib/sevdesk-stub.php).
 * Aufruf durch tools/sevdesk-check.sh:  php tools/lib/sevdesk-sim.php <repo> <stub-url>   Ausgabe: Zeilen "key=wert".
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
$stub = rtrim($argv[2] ?? '', '/');
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/audit.php';
require_once $root . '/php-ionos/app/auth.php';
require_once $root . '/php-ionos/app/collections.php';
require_once $root . '/php-ionos/app/integrations.php';
require_once $root . '/php-ionos/app/invoice_source.php';
require_once $root . '/php-ionos/app/invoice_source_switch.php';
require_once $root . '/php-ionos/app/integration_state.php';
require_once $root . '/php-ionos/app/sevdesk.php';
require_once $root . '/php-ionos/app/queue.php';
require_once $root . '/php-ionos/app/jobs.php';
$pdo = db();
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$try = static function (callable $f): string { try { $f(); return 'ok'; } catch (Throwable $e) { return 'fehler:' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 90)); } };
$setting = static function (string $k, ?string $v) use ($pdo): void {
    if ($v === null) { $pdo->prepare('DELETE FROM platform_settings WHERE `key` = ?')->execute([$k]); return; }
    $pdo->prepare('INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
};
foreach (['audit_log', 'jobs', 'sync_state', 'invoices', 'customers'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec("DELETE FROM organizations WHERE id IN ('aaaaaaaa-0000-0000-0000-000000000001','aaaaaaaa-0000-0000-0000-000000000002')");
$pdo->exec("INSERT INTO organizations (id, name, mandate_prefix, onboarding_completed) VALUES ('aaaaaaaa-0000-0000-0000-000000000001','Firma Lex','FL',1), ('aaaaaaaa-0000-0000-0000-000000000002','Firma Sev','FS',1)");
$pdo->exec("INSERT INTO integrations (id, tenant_id, invoice_source, lexoffice_connected, lexoffice_api_key_encrypted) VALUES (UUID(), 'aaaaaaaa-0000-0000-0000-000000000001', 'lexware_office', 1, '" . encrypt_value('lex-key') . "')");
$pdo->exec("INSERT INTO integrations (id, tenant_id, invoice_source) VALUES (UUID(), 'aaaaaaaa-0000-0000-0000-000000000002', 'sevdesk')");
$lex = 'aaaaaaaa-0000-0000-0000-000000000001'; $sev = 'aaaaaaaa-0000-0000-0000-000000000002';
$admin = ['user_id' => 'admin-1', 'email' => 'admin@example.test', 'is_superadmin' => 1, 'totp_enabled' => 1, 'platform_role' => 'admin'];
$owner = ['user_id' => 'owner-2', 'email' => 'inhaber@sev.test', 'role' => 'owner', 'org_id' => $sev];

// ---------------------------------------------------------------- 1. Freigabetermin
$setting('sevdesk_connect', null); $setting('sevdesk_release_at', null);
$out('connect_ohne_alles', integration_switch('sevdesk', 'connect'));
$setting('sevdesk_release_at', '2026-09-30');
$GLOBALS['integration_now'] = (new DateTimeImmutable('2026-09-29 23:59:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
$out('connect_vor_termin', integration_switch('sevdesk', 'connect'));
$out('text_vor_termin', integration_connect_state_text('sevdesk'));
$GLOBALS['integration_now'] = (new DateTimeImmutable('2026-09-30 00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
$out('connect_am_termin', integration_switch('sevdesk', 'connect'));
$out('text_am_termin', integration_connect_state_text('sevdesk'));
$setting('sevdesk_connect', '0');
$out('connect_explizit_0_trotz_termin', integration_switch('sevdesk', 'connect'));
$setting('sevdesk_connect', '1');
$GLOBALS['integration_now'] = (new DateTimeImmutable('2026-09-01', new DateTimeZone('Europe/Berlin')))->getTimestamp();
$out('connect_explizit_1_vor_termin', integration_switch('sevdesk', 'connect'));
$out('collections_bleibt_zu', !integration_switch('sevdesk', 'collections'));
$out('sevdesk_verfuegbar_fuer_wechsel', invoice_source_available('sevdesk'));
unset($GLOBALS['integration_now']);

// ---------------------------------------------------------------- 2. Client und Adapter gegen den Stub
$setting('sevdesk_api_verified', null);
$src = invoice_source_from_key('sevdesk', 'TOKEN-OK');
$out('faehigkeiten_ohne_verifikation', implode(',', $src->capabilities()));
$p = $src->getProfile();
$out('profil_erreichbar', !empty($p['reachable']) && $p['companyName'] === null && $p['contacts_total'] === 3);
$page = $src->getInvoiceVouchersPage('open', 0);
$ids = array_column($page['content'], 'id'); sort($ids);
$out('liste_offen_ids', implode(',', $ids)); // 5001, 5002, 5007 (5003 MA ausgeschlossen)
$byId = []; foreach ($page['content'] as $v) { $byId[$v['id']] = $v; }
$out('liste_5001_status', $byId['5001']['voucherStatus'] ?? '');
$out('liste_5002_ueberfaellig', $byId['5002']['voucherStatus'] ?? '');
$out('liste_5001_updated', (string)($byId['5001']['updatedDate'] ?? ''));
$out('liste_totalpages', (int)$page['totalPages']);
$part = $src->getInvoiceVouchersPage('overdue', 0);
$out('liste_teilbezahlt_ids', implode(',', array_column($part['content'], 'id')));
$d = $src->getInvoiceDetail('5001');
$out('detail_nummer', $d['voucherNumber']);
$out('detail_brutto', (string)$d['totalPrice']['totalGrossAmount']);
$out('detail_waehrung', $d['totalPrice']['currency']);
$out('detail_faellig', (string)$d['dueDate']);
$out('detail_kontakt', $d['address']['contactId'] . '|' . $d['address']['name']);
$out('detail_positionen', count($d['lineItems']) . '|' . ($d['lineItems'][0]['name'] ?? ''));
$d2 = $src->getInvoiceDetail('5002');
// Erwartung aus dem Rechnungsdatum der Antwort (Stub und Pruefstand koennen in verschiedenen Zeitzonen "heute" bestimmen)
$out('detail_5002_faellig_aus_timeToPay', (string)$d2['dueDate'] === (new DateTimeImmutable((string)$d2['voucherDate']))->modify('+14 days')->format('Y-m-d'));
$out('detail_5002_status', $d2['voucherStatus']);
$out('detail_bezahlt_status', $src->getInvoiceDetail('5005')['voucherStatus']);
$out('detail_entwurf_status', $src->getInvoiceDetail('5006')['voucherStatus']);
$c = $src->getContact('100');
$out('kontakt_firma', ($c['company']['name'] ?? '') . '|' . ($c['roles']['customer']['number'] ?? '') . '|' . ($c['emailAddresses']['business'][0] ?? ''));
$c2 = $src->getContact('101');
$out('kontakt_person', ($c2['person']['firstName'] ?? '') . ' ' . ($c2['person']['lastName'] ?? '') . '|' . ($c2['emailAddresses']['business'][0] ?? ''));
$c3 = $src->getContact('102');
$out('kontakt_ohne_nummer', isset($c3['roles']['customer']['number']) ? 'nummer' : 'keine');
$pay = $src->getPayment('5001');
$out('restbetrag_ohne_verifikation', $pay['open_amount'] === null ? 'null' : (string)$pay['open_amount']);
$setting('sevdesk_api_verified', '1');
$out('faehigkeiten_mit_verifikation', implode(',', $src->capabilities()));
$out('restbetrag_offen', (string)$src->getPayment('5001')['open_amount']);
$out('restbetrag_teilbezahlt', (string)$src->getPayment('5004')['open_amount']);
$out('restbetrag_bezahlt', $src->getPayment('5005')['open_amount'] === null ? 'null' : 'zahl');
$out('restbetrag_ohne_paidAmount', $src->getPayment('5007')['open_amount'] === null ? 'null' : 'zahl');
$out('zahlstatus_teilbezahlt', (string)$src->getPayment('5004')['payment_status']);
$setting('sevdesk_api_verified', null);
// Seitennavigation: zweiter Stub mit 205 offenen Rechnungen (SEVDESK_STUB_MANY=1), 3 Seiten je 100
$stubMany = $argv[3] ?? '';
if ($stubMany !== '') {
    $GLOBALS['config']['sevdesk']['base_url'] = rtrim($stubMany, '/');
    $many = invoice_source_from_key('sevdesk', 'TOKEN-OK');
    $p0 = $many->getInvoiceVouchersPage('open', 0); $p2 = $many->getInvoiceVouchersPage('open', 2);
    $out('seiten_gesamt', (int)$p0['totalPages']);
    $out('seite0_anzahl', count($p0['content']));
    $out('seite2_anzahl', count($p2['content']));
    $out('seite2_erste_id', (string)($p2['content'][0]['id'] ?? ''));
    $out('alle_offenen', count($many->getOpenInvoices()));
    $GLOBALS['config']['sevdesk']['base_url'] = $stub;
}
// Fehlerfaelle
$out('fehler_401', $try(static fn() => invoice_source_from_key('sevdesk', 'TOKEN-401')->getProfile()));
$out('fehler_429', $try(static fn() => invoice_source_from_key('sevdesk', 'TOKEN-429')->getProfile()));
$out('fehler_500', $try(static fn() => invoice_source_from_key('sevdesk', 'TOKEN-500')->getProfile()));
$out('fehler_html', $try(static fn() => invoice_source_from_key('sevdesk', 'TOKEN-HTML')->getProfile()));
$out('fehler_404', $try(static fn() => $src->getInvoiceDetail('9999')));
$st = $pdo->query("SELECT COUNT(*) FROM monitor_checks WHERE component = 'sevdesk_api' AND status = 'fail'")->fetchColumn();
$out('monitoring_fehler_gezaehlt', (int)$st >= 3);
// Freigabe gesperrt: kein Aufruf
$setting('sevdesk_connect', '0');
$out('gesperrt_kein_aufruf', $try(static fn() => invoice_source_from_key('sevdesk', 'TOKEN-OK')->getProfile()));
$out('faehigkeiten_gesperrt', implode(',', (new SevdeskSource(new SevdeskClient('TOKEN-OK')))->capabilities()));
$setting('sevdesk_connect', '1');

// ---------------------------------------------------------------- 3. Verbindung je Firma
$out('quelle_sev_nicht_verbunden', $try(static fn() => invoice_source_for_tenant($sev)));
$info = integration_verify_sevdesk($sev, 'TOKEN-OK');
$pdo->prepare('UPDATE integrations SET sevdesk_api_key_encrypted = ?, sevdesk_connected = 1 WHERE tenant_id = ?')->execute([encrypt_value('TOKEN-OK'), $sev]);
$out('quelle_sev_verbunden', invoice_source_for_tenant($sev) instanceof SevdeskSource);
$out('quelle_lex_bleibt_lexware', invoice_source_for_tenant($lex) instanceof LexwareOfficeSource);
$out('verifiziert_gespeichert', (bool)$pdo->query("SELECT sevdesk_last_verified_at FROM integrations WHERE tenant_id = '$sev'")->fetchColumn());
$out('jobtyp_sev', invoice_source_sync_job_type($sev));
$out('jobtyp_lex', invoice_source_sync_job_type($lex));
$out('pool_sevdesk', implode(',', jobs_pools()['sevdesk']));
$out('pool_lexware_ohne_sevdesk', !in_array('sync_run_sevdesk', jobs_pools()['lexware'], true));

// ---------------------------------------------------------------- 4. Scheduler: Auswahl nach Buchhaltungssystem
$cfg = ['auto_sync_hours' => 6, 'full_sync_hour' => 99];
$q = scheduler_auto_sync($cfg, time());
sort($q);
$out('scheduler_eingereiht', implode(',', $q));
$types = $pdo->query("SELECT tenant_id, type FROM jobs ORDER BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$out('scheduler_typ_lex', (string)($types[$lex] ?? ''));
$out('scheduler_typ_sev', (string)($types[$sev] ?? ''));
$out('tenant_active_beide_typen', queue_tenant_active($sev, QUEUE_SYNC_TYPES) !== null && queue_tenant_active($sev, 'sync_run') === null);
$pdo->exec('DELETE FROM jobs');
$setting('sevdesk_connect', '0');
$q2 = scheduler_auto_sync($cfg, time());
$out('scheduler_sev_gesperrt', implode(',', $q2));
$setting('sevdesk_connect', '1');

// ---------------------------------------------------------------- 5. Wechselsperre: Reset durch Betreiber, Trennung beim Wechsel
$pdo->prepare('UPDATE integrations SET invoice_source_changed_at = UTC_TIMESTAMP(), invoice_source_switches = 2 WHERE tenant_id = ?')->execute([$sev]);
$out('sperre_aktiv', invoice_source_lock($sev)['locked']);
$out('reset_ohne_grund', $try(static fn() => invoice_source_lock_reset($admin, $sev, '')));
$out('reset_ok', $try(static fn() => invoice_source_lock_reset($admin, $sev, 'Ticket 4711, Kunde hat versehentlich gewechselt')));
$cur = invoice_source_current($sev);
$out('sperre_nach_reset', invoice_source_lock($sev)['locked']);
$out('zaehler_unveraendert', $cur['switches']);
$out('reset_zeitpunkt_gesetzt', !empty($cur['lock_reset_at']));
$out('reset_ohne_sperre', $try(static fn() => invoice_source_lock_reset($admin, $sev, 'nochmal')));
$out('audit_reset', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice_source_lock_reset'")->fetchColumn());
// Wechsel sevdesk -> lexware: Token weg, Sperre neu
invoice_source_switch($owner, 'lexware_office', 'Test');
$row = $pdo->query("SELECT sevdesk_api_key_encrypted, sevdesk_connected, invoice_source, invoice_source_switches FROM integrations WHERE tenant_id = '$sev'")->fetch();
$out('wechsel_trennt_sevdesk', $row['sevdesk_api_key_encrypted'] === null && (int)$row['sevdesk_connected'] === 0 && $row['invoice_source'] === 'lexware_office');
$out('wechsel_zaehler', (int)$row['invoice_source_switches']);
$out('sperre_nach_wechsel', invoice_source_lock($sev)['locked']);
$out('quelle_nach_wechsel', $try(static fn() => invoice_source_for_tenant($sev)));
