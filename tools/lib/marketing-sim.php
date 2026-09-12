<?php
/**
 * Prueffaelle des Marketingmoduls (app/marketing.php, admin-marketing.php, abmelden.php, marketing-webhook.php) gegen
 * eine temporaere MariaDB. Aufruf durch tools/marketing-check.sh:
 *   SMARTEINZUG_CONFIG=<sandbox-config> php tools/lib/marketing-sim.php <repo> <logdatei>
 * Versand ueber das Marketingprofil mit Transport log (kein Netz); der zentrale Test-Schutz laeuft mit. Ausgabe "key=wert".
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
$logFile = $argv[2] ?? '';
define('LOG_SERVICE', 'cli');
require $root . '/php-ionos/bin/_cli.php';
require $root . '/tools/lib/test-guard.php';
require_once $root . '/php-ionos/app/audit.php';
require_once $root . '/php-ionos/app/auth.php';
require_once $root . '/php-ionos/app/platform.php';
require_once $root . '/php-ionos/app/queue.php';
require_once $root . '/php-ionos/app/jobs.php';
require_once $root . '/php-ionos/app/marketing.php';
$pdo = db();
foreach (['marketing_events', 'marketing_sends', 'marketing_campaigns', 'marketing_recipients', 'marketing_lists', 'marketing_suppressions', 'jobs', 'audit_log'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec("DELETE FROM platform_settings WHERE `key` LIKE 'marketing_%'");
$pdo->exec("DELETE FROM users WHERE email LIKE '%@mk.test'");
$pdo->exec("DELETE FROM organizations WHERE name LIKE 'MK-%'");
@unlink($logFile);
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$try = static function (callable $f): string { try { $f(); return 'ok'; } catch (Throwable $e) { return 'verweigert'; } };
$mk = static function (string $email, int $super, ?string $role, int $active = 1) use ($pdo): string {
    $id = uuid4();
    $pdo->prepare('INSERT INTO users (id, email, password_hash, is_superadmin, platform_role, totp_enabled, is_active, email_verified_at, first_name, last_name) VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), ?, ?)')
        ->execute([$id, $email, password_hash('x', PASSWORD_DEFAULT), $super, $role, $active, 'Vor' . substr($email, 0, 3), 'Nach']);
    return $id;
};
$ctxOf = static function (string $id): array { $u = user_load($id); return ['user_id' => $u['id'], 'email' => $u['email'], 'is_superadmin' => $u['is_superadmin'], 'platform_role' => $u['platform_role'], 'totp_enabled' => $u['totp_enabled'], 'display_name' => null]; };
$log = static fn(): string => is_file($logFile) ? (string)file_get_contents($logFile) : '';
$admin = $ctxOf($mk('admin@mk.test', 0, 'admin'));
$staff = $ctxOf($mk('staff@mk.test', 0, 'staff'));
$support = $ctxOf($mk('support@mk.test', 0, 'support'));

// 1. Rechte und Ratenbegrenzung
$out('admin_manage', platform_can($admin, 'marketing.manage'));
$out('staff_kein_view', !platform_can($staff, 'marketing.view'));
$out('support_kein_manage', !platform_can($support, 'marketing.manage'));
$r = marketing_rates();
$out('rate_default', $r['per_second'] . '/' . $r['per_day']);
$out('rate_ungueltig', $try(static fn() => marketing_rates_save($admin, 0, 200)));
$out('rate_staff_verweigert', $try(static fn() => marketing_rates_save($staff, 2, 300)));
$r = marketing_rates_save($admin, 5, 3);
$out('rate_gesetzt', $r['per_second'] . '/' . $r['per_day']);

// 2. Listen anlegen, CSV erkennen und importieren
$out('liste_staff_verweigert', $try(static fn() => marketing_list_create($staff, 'X', '')));
$imp = marketing_list_create($admin, 'Messe 2026', 'Kontakte vom Stand');
$out('liste_doppelt_verweigert', $try(static fn() => marketing_list_create($admin, 'Messe 2026', '')));
$p = marketing_csv_parse("\xEF\xBB\xBFFirma;E-Mail;Vorname;Nachname\nMuster GmbH;Erika@Example.test;Erika;Muster\n\"Beispiel, AG\";max@example.test;Max;Beispiel\n");
$out('csv_kopf_semikolon', count($p['rows']) === 2 && $p['rows'][0]['email'] === 'Erika@Example.test' && $p['rows'][0]['name'] === 'Erika Muster' && $p['rows'][1]['company'] === 'Beispiel, AG' && $p['delimiter'] === ';');
$p = marketing_csv_parse("email,name,company\na@x.test,A,Firma A\n");
$out('csv_kopf_komma', count($p['rows']) === 1 && $p['rows'][0]['company'] === 'Firma A' && $p['delimiter'] === ',');
$p = marketing_csv_parse("ohne@x.test;Ohne Kopf;Firma O\n");
$out('csv_ohne_kopf', count($p['rows']) === 1 && $p['rows'][0]['email'] === 'ohne@x.test' && $p['rows'][0]['name'] === 'Ohne Kopf' && $p['header'] === null);
marketing_suppress('gesperrt@example.test', 'manual', 'Test', null, $admin);
$out('import_ohne_rechtsgrundlage_verweigert', $try(static fn() => marketing_list_import($admin, $imp, "email\na@x.test\n", '', '')));
$out('import_sonstiges_ohne_vermerk_verweigert', $try(static fn() => marketing_list_import($admin, $imp, "email\na@x.test\n", 'sonstiges', '')));
$z = marketing_list_import($admin, $imp, "email;name;firma\nerika@example.test;Erika Muster;Muster GmbH\nERIKA@example.test;Doppelt;X\nkeine-adresse;Falsch;Y\ngesperrt@example.test;Gesperrt;Z\nmax@example.test;Max Beispiel;Beispiel AG\n", 'b2b_kontakt', 'Messe Stand 12, 03.09.2026');
$out('import_zaehler', implode(',', [$z['zeilen'], $z['importiert'], $z['doppelt'], $z['ungueltig'], $z['gesperrt']]));
$z2 = marketing_list_import($admin, $imp, "email\nmax@example.test\n", 'b2b_kontakt', 'erneut');
$out('import_wiederholung_doppelt', $z2['doppelt'] === 1 && $z2['importiert'] === 0);
$rc = marketing_recipients($imp);
$out('import_status_gesperrt', count(array_filter($rc, static fn($r) => $r['email_norm'] === 'gesperrt@example.test' && $r['status'] === 'suppressed')) === 1);
$out('import_email_norm', count(array_filter($rc, static fn($r) => $r['email_norm'] === 'erika@example.test' && $r['legal_basis'] === 'b2b_kontakt' && $r['legal_note'] === 'Messe Stand 12, 03.09.2026')) === 1);

// 3. Systemliste aus Firmenaccounts (nie aus customers)
$orgA = uuid4(); $orgB = uuid4();
$pdo->prepare("INSERT INTO organizations (id, name, mandate_prefix) VALUES (?, 'MK-Alpha GmbH', 'MA'), (?, 'MK-Beta AG', 'MB')")->execute([$orgA, $orgB]);
$ownerA = $mk('inhaber-a@mk.test', 0, null); $adminA = $mk('admin-a@mk.test', 0, null); $memberA = $mk('mitarbeiter-a@mk.test', 0, null);
$ownerB = $mk('inhaber-b@mk.test', 0, null); $inaktiv = $mk('inaktiv@mk.test', 0, null, 0);
$pdo->prepare("INSERT INTO organization_members (id, organization_id, user_id, role, status) VALUES (?, ?, ?, 'owner', 'active'), (?, ?, ?, 'admin', 'active'), (?, ?, ?, 'member', 'active'), (?, ?, ?, 'owner', 'active'), (?, ?, ?, 'admin', 'active')")
    ->execute([uuid4(), $orgA, $ownerA, uuid4(), $orgA, $adminA, uuid4(), $orgA, $memberA, uuid4(), $orgB, $ownerB, uuid4(), $orgB, $inaktiv]);
$pdo->prepare("INSERT INTO customers (id, tenant_id, customer_number, name, email) VALUES (?, ?, '10001', 'Endkunde', 'endkunde@example.test')")->execute([uuid4(), $orgA]);
$out('systemliste_ohne_umfang_verweigert', $try(static fn() => marketing_list_create($admin, 'Sys', '', 'system', null)));
$sysOwners = marketing_list_create($admin, 'Kunden (Inhaber)', '', 'system', 'owners');
$z = marketing_list_sync_system($admin, $sysOwners);
$out('systemliste_inhaber', $z['neu']);
$sysAll = marketing_list_create($admin, 'Kunden (Inhaber und Admins)', '', 'system', 'owners_admins');
$z = marketing_list_sync_system($admin, $sysAll);
$out('systemliste_inhaber_admins', $z['neu']);
$mails = array_column(marketing_recipients($sysAll), 'email_norm');
$out('systemliste_kein_endkunde', !in_array('endkunde@example.test', $mails, true) && !in_array('mitarbeiter-a@mk.test', $mails, true) && !in_array('inaktiv@mk.test', $mails, true));
$out('systemliste_import_verweigert', $try(static fn() => marketing_list_import($admin, $sysAll, "email\nx@x.test\n", 'bestandskunde', '')));
$out('systemliste_rechtsgrundlage', count(array_filter(marketing_recipients($sysAll), static fn($r) => $r['legal_basis'] === 'bestandskunde')) === 3);
$pdo->prepare('DELETE FROM organization_members WHERE user_id = ?')->execute([$adminA]);
$z = marketing_list_sync_system($admin, $sysAll);
$out('systemliste_entfernt', $z['entfernt'] === 1 && $z['bestehend'] === 2);

// 4. Sperrliste
$out('sperre_manuell_doppelt', !marketing_suppress('gesperrt@example.test', 'manual', 'nochmal', null, $admin));
$out('sperre_aufheben_manuell', $try(static fn() => marketing_unsuppress($admin, 'gesperrt@example.test', 'Kunde bittet um Aufnahme')));
$out('sperre_aufheben_kurzer_grund_verweigert', $try(static fn() => marketing_unsuppress($admin, 'x@x.test', 'kurz')));
$out('sperre_recipient_wieder_aktiv', count(array_filter(marketing_recipients($imp), static fn($r) => $r['email_norm'] === 'gesperrt@example.test' && $r['status'] === 'active')) === 1);
marketing_suppress('gesperrt@example.test', 'unsubscribe', 'per Link', null, null);
$out('sperre_unsubscribe_ueberschreibt_nicht_durch_bounce', !marketing_suppress('gesperrt@example.test', 'bounce', 'SES', null, null));
$out('sperre_unsubscribe_aufheben_verweigert', $try(static fn() => marketing_unsuppress($admin, 'gesperrt@example.test', 'bitte trotzdem')));
$out('sperre_staff_verweigert', $try(static fn() => marketing_suppress_manual($staff, 'y@x.test', 'x')));

// 5. Kampagne: Entwurf, Pruefungen, Vorschau
$basis = ['name' => 'Herbst 2026', 'subject' => 'Neu bei {{firma}}: SEPA-Einzug für Lexware Office', 'title' => 'Guten Tag {{name}}',
    'body_text' => "Erster Absatz für {{firma}}.\n\nZweiter Absatz.\n\n\nDritter Absatz.", 'button_label' => 'Mehr erfahren', 'button_url' => 'https://smart-einzug.de/', 'footer_note' => 'Sie haben uns auf der Messe kennengelernt.', 'list_ids' => [$imp, $sysOwners]];
$out('kampagne_staff_verweigert', $try(static fn() => marketing_campaign_save($staff, null, $basis)));
$out('kampagne_html_verweigert', $try(static fn() => marketing_campaign_save($admin, null, ['body_text' => 'Hallo <b>fett</b>'] + $basis)));
$out('kampagne_gedankenstrich_verweigert', $try(static fn() => marketing_campaign_save($admin, null, ['subject' => "A \u{2014} B"] + $basis)));
$out('kampagne_button_unvollstaendig_verweigert', $try(static fn() => marketing_campaign_save($admin, null, ['button_url' => ''] + $basis)));
$out('kampagne_button_http_verweigert', $try(static fn() => marketing_campaign_save($admin, null, ['button_url' => 'http://smart-einzug.de/'] + $basis)));
$out('kampagne_ohne_liste_verweigert', $try(static fn() => marketing_campaign_save($admin, null, ['list_ids' => []] + $basis)));
$cid = marketing_campaign_save($admin, null, $basis);
$c = marketing_campaign_load($cid);
$out('kampagne_entwurf', $c['status'] === 'draft' && count($c['list_ids_arr']) === 2);
$m = marketing_campaign_render($c, 'Erika Muster', 'Muster GmbH', 'https://app.example.test/abmelden.php?t=abc');
$out('render_platzhalter', $m['subject'] === 'Neu bei Muster GmbH: SEPA-Einzug für Lexware Office' && str_contains($m['text'], 'Guten Tag Erika Muster') && str_contains($m['text'], 'Erster Absatz für Muster GmbH.'));
$out('render_absaetze', substr_count($m['html'], '<p') >= 3 && str_contains($m['html'], 'Dritter Absatz.'));
$out('render_abmeldelink', str_contains($m['html'], 'abmelden.php?t=abc') && str_contains($m['text'], 'Keine weiteren Nachrichten: https://app.example.test/abmelden.php?t=abc'));
$out('render_fusstext_grund', str_contains($m['text'], 'Sie haben uns auf der Messe kennengelernt.') && str_contains($m['text'], 'dauerhaft berücksichtigt'));
$out('render_pflichtangaben', str_contains($m['html'], 'HRB 104291') && str_contains($m['html'], '#E3AC48'));
$m0 = marketing_campaign_render($c, null, null, 'https://x/y');
$out('render_leere_platzhalter', str_contains($m0['text'], 'Guten Tag') && !str_contains($m0['text'], '{{') && str_contains($m0['subject'], 'Neu bei: SEPA-Einzug'));
$out('preview_muster', str_contains(marketing_campaign_preview($c)['text'], 'Erika Muster'));

// 6. Testversand ueber Profil marketing (Transport log)
$out('start_ohne_test_verweigert', $try(static fn() => marketing_campaign_start($admin, $cid)));
$out('test_ungueltige_adresse_verweigert', $try(static fn() => marketing_campaign_test_send($admin, $cid, 'nix')));
marketing_campaign_test_send($admin, $cid, 'pruefer@example.test');
$l = $log();
$out('test_log_absender', str_contains($l, 'From: Test Marketing <kontakt@mail.example.test>'));
preg_match('/^Subject: (.*(?:\r\n[ \t].*)*)/m', $l, $sm);
$out('test_log_betreff_test', isset($sm[1]) && str_contains(mb_decode_mimeheader($sm[1]), 'TEST: Neu bei Muster GmbH'));
$out('test_log_precedence_bulk', str_contains($l, "\r\nPrecedence: bulk\r\n") && !str_contains($l, 'Auto-Submitted'));
$out('test_log_list_unsubscribe', str_contains($l, 'List-Unsubscribe: <https://app.example.test/abmelden.php?t=TEST>') && str_contains($l, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'));
$out('test_log_reply_to', str_contains($l, "Reply-To: kontakt@example.test\r\n"));
$out('test_event', (int)$pdo->query("SELECT COUNT(*) FROM marketing_events WHERE source='app' AND event_type='test_send'")->fetchColumn());
$out('test_sent_at', marketing_campaign_load($cid)['test_sent_at'] !== null);
marketing_campaign_save($admin, $cid, ['subject' => 'Geänderter Betreff'] + $basis);
$out('test_nach_aenderung_zurueck', marketing_campaign_load($cid)['test_sent_at'] === null);
marketing_campaign_save($admin, $cid, ['name' => 'Herbst 2026 (v2)', 'subject' => 'Geänderter Betreff'] + $basis);
marketing_campaign_test_send($admin, $cid, 'pruefer@example.test');
$out('test_nach_namensaenderung_bleibt', marketing_campaign_load($cid)['test_sent_at'] !== null);

// 7. Freigabe: Versandzeilen, Dublette ueber zwei Listen, Sperrliste, Job
marketing_list_import($admin, $imp, "email\ninhaber-a@mk.test\n", 'bestandskunde', 'auch in der Systemliste');
$z = marketing_campaign_start($admin, $cid);
$out('start_total_skipped', $z['total'] . '/' . $z['skipped']);   // erika, max, gesperrt(skipped), inhaber-a (dedupe), inhaber-b -> 5 / 1
$out('start_status', marketing_campaign_load($cid)['status']);
$out('start_job', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type = 'marketing_send' AND status IN ('queued','processing')")->fetchColumn());
$out('start_doppelt_verweigert', $try(static fn() => marketing_campaign_start($admin, $cid)));
$out('start_entwurf_nicht_aenderbar', $try(static fn() => marketing_campaign_save($admin, $cid, $basis)));

// 8. Versand mit Tagesgrenze (3, davon 2 Tests verbraucht) und Sekundenrate
$st = marketing_send_process(30.0);
$out('versand_tagesgrenze', $st['sent'] . '/' . ($st['daily_limit'] ? 1 : 0) . '/' . $st['remaining']);   // 1 gesendet, Grenze, 3 offen
$out('versand_status_sending', marketing_campaign_load($cid)['status']);
marketing_rates_save($admin, 5, 200);
$t0 = microtime(true);
$st = marketing_send_process(30.0);
$dauer = microtime(true) - $t0;
$out('versand_rest', $st['sent'] . '/' . $st['remaining'] . '/' . ($st['daily_limit'] ? 1 : 0));
$out('versand_sekundenrate_eingehalten', $dauer >= 2 / 5 * 0.9);   // drei Nachrichten, zwei Abstaende von 0,2 s
$c = marketing_campaign_load($cid);
$out('versand_abgeschlossen', $c['status'] . '/' . $c['sent_count'] . '/' . $c['skipped_count'] . '/' . $c['failed_count']);
$l = $log();
preg_match_all('~abmelden\.php\?t=([a-f0-9]{48})~', $l, $mm);
$tokens = array_values(array_unique($mm[1]));
$out('versand_tokens_eindeutig', count($tokens));
$out('versand_kein_klartext_token', (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE unsubscribe_token_hash IN ('" . implode("','", $tokens) . "')")->fetchColumn() === 0);
$out('versand_personalisiert', str_contains(quoted_printable_decode($l), 'Erster Absatz für Beispiel AG.') && str_contains(quoted_printable_decode($l), 'Erster Absatz für MK-Beta AG.'));
$out('versand_gesperrt_nicht_gesendet', !str_contains($l, 'To: gesperrt@example.test'));
$out('versand_audit_sent', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'marketing_campaign_sent'")->fetchColumn());

// 9. Abmeldung ueber Token (Link und One-Click)
$s = marketing_send_by_token($tokens[0]);
$out('token_aufloesbar', $s !== null && $s['campaign_id'] === $cid);
$out('token_ungueltig', marketing_unsubscribe('0000') . '/' . marketing_unsubscribe(str_repeat('f', 48)));
$out('abmeldung', marketing_unsubscribe($tokens[0], 'link'));
$out('abmeldung_wiederholt', marketing_unsubscribe($tokens[0], 'link'));
$out('abmeldung_sperre', (string)$pdo->query('SELECT reason FROM marketing_suppressions WHERE email_norm = ' . $pdo->quote((string)$s['email_norm']))->fetchColumn());
$out('abmeldung_event', (int)$pdo->query("SELECT COUNT(*) FROM marketing_events WHERE event_type = 'unsubscribe'")->fetchColumn());
$out('one_click_erkannt', marketing_is_one_click('POST', ['List-Unsubscribe' => 'One-Click']) && !marketing_is_one_click('GET', ['List-Unsubscribe' => 'One-Click']) && !marketing_is_one_click('POST', []));
$out('abmeldung_one_click', marketing_unsubscribe($tokens[1], 'one-click'));

// 10. Zweite Kampagne: gesperrte Adresse wird bei Freigabe uebersprungen; anhalten, fortsetzen, abbrechen
$cid2 = marketing_campaign_save($admin, null, ['name' => 'Zweite'] + $basis);
marketing_campaign_test_send($admin, $cid2, 'pruefer@example.test');
$z = marketing_campaign_start($admin, $cid2);
$out('zweite_skipped', $z['skipped']);   // gesperrt + zwei abgemeldete = 3
marketing_campaign_set_status($admin, $cid2, 'paused');
$out('zweite_pausiert', marketing_campaign_load($cid2)['status']);
$st = marketing_send_process(5.0);
$out('zweite_pause_kein_versand', $st['sent']);
$out('zweite_falscher_wechsel_verweigert', $try(static fn() => marketing_campaign_set_status($admin, $cid2, 'sent')));
marketing_campaign_set_status($admin, $cid2, 'queued');
marketing_campaign_set_status($admin, $cid2, 'cancelled');
$c2 = marketing_campaign_load($cid2);
$out('zweite_abgebrochen', $c2['status'] . '/' . (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE campaign_id = " . $pdo->quote($cid2) . " AND status = 'skipped' AND skip_reason = 'cancelled'")->fetchColumn());
$out('zweite_loeschen_versendete_verweigert', $try(static fn() => marketing_campaign_delete($admin, $cid)));
$out('zweite_loeschen_abgebrochene', $try(static fn() => marketing_campaign_delete($admin, $cid2)));
$out('liste_in_verwendung_loeschbar_nach_ende', $try(static fn() => marketing_list_delete($admin, $sysOwners)));

// 11. Job-Handler und Scheduler
$job = ['id' => uuid4(), 'type' => 'marketing_send', 'locked_by' => 'sim', 'payload_data' => []];
$r = job_marketing_send($job);
$out('job_ohne_offene', $r['status'] . '/' . (int)$r['result']['remaining']);
$out('scheduler_ohne_kampagne', !marketing_campaigns_pending());
$out('pool_mail_enthaelt_marketing', in_array('marketing_send', jobs_pools()['mail'], true) && in_array('marketing_send', jobs_pools()['all'], true));

// 12. SES: Bounce, Complaint, Signatur
$ev = marketing_ses_handle(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => 'Max@example.test', 'status' => '5.1.1', 'diagnosticCode' => 'smtp; 550 user unknown']]], 'mail' => ['messageId' => 'ses-1', 'destination' => ['max@example.test']]]);
$out('ses_bounce_hart_gesperrt', implode(',', $ev['suppressed']) . '/' . (string)$pdo->query("SELECT reason FROM marketing_suppressions WHERE email_norm = 'max@example.test'")->fetchColumn());
$ev = marketing_ses_handle(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MailboxFull', 'bouncedRecipients' => [['emailAddress' => 'inhaber-b@mk.test']]], 'mail' => ['messageId' => 'ses-2']]);
$out('ses_bounce_weich_nicht_gesperrt', $ev['suppressed'] === [] && !marketing_is_suppressed('inhaber-b@mk.test') && $ev['logged'] === 1);
$ev = marketing_ses_handle(['notificationType' => 'Complaint', 'complaint' => ['complaintFeedbackType' => 'abuse', 'complainedRecipients' => [['emailAddress' => 'inhaber-b@mk.test']]], 'mail' => ['messageId' => 'ses-3']]);
$out('ses_complaint_gesperrt', (string)$pdo->query("SELECT reason FROM marketing_suppressions WHERE email_norm = 'inhaber-b@mk.test'")->fetchColumn());
$ev = marketing_ses_handle(['notificationType' => 'Delivery', 'mail' => ['messageId' => 'ses-4', 'destination' => ['erika@example.test']]]);
$out('ses_delivery_nur_protokoll', $ev['suppressed'] === [] && (int)$pdo->query("SELECT COUNT(*) FROM marketing_events WHERE source='ses' AND event_type='delivery'")->fetchColumn() === 1);
// Signatur mit selbst erzeugtem Zertifikat
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'sns.eu-central-1.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
$x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($x509, $pem);
$GLOBALS['marketing_sns_cert_fetcher'] = static fn(string $url): ?string => $url === 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem' ? $pem : null;
$msg = ['Type' => 'Notification', 'MessageId' => 'm-1', 'TopicArn' => 'arn:aws:sns:eu-central-1:123:ses', 'Message' => '{"notificationType":"Delivery"}', 'Timestamp' => '2026-09-11T12:00:00.000Z',
    'SignatureVersion' => '1', 'SigningCertURL' => 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem'];
openssl_sign(marketing_sns_string_to_sign($msg), $sig, $key, OPENSSL_ALGO_SHA1);
$msg['Signature'] = base64_encode($sig);
$out('sns_signatur_v1', marketing_sns_verify($msg));
$msg2 = $msg; $msg2['SignatureVersion'] = '2';
openssl_sign(marketing_sns_string_to_sign($msg2), $sig2, $key, OPENSSL_ALGO_SHA256);
$msg2['Signature'] = base64_encode($sig2);
$out('sns_signatur_v2', marketing_sns_verify($msg2));
$bad = $msg; $bad['Message'] = '{"notificationType":"Bounce"}';
$out('sns_signatur_manipuliert', !marketing_sns_verify($bad));
$bad = $msg; $bad['SigningCertURL'] = 'https://boese.example.test/cert.pem';
$out('sns_fremdes_zertifikat', !marketing_sns_verify($bad));
$sub = ['Type' => 'SubscriptionConfirmation', 'MessageId' => 'm-2', 'Token' => 'tok', 'TopicArn' => 'arn:aws:sns:eu-central-1:123:ses', 'Message' => 'You have chosen to subscribe', 'SubscribeURL' => 'https://sns.eu-central-1.amazonaws.com/?Action=ConfirmSubscription', 'Timestamp' => '2026-09-11T12:00:00.000Z', 'SignatureVersion' => '1', 'SigningCertURL' => $msg['SigningCertURL']];
openssl_sign(marketing_sns_string_to_sign($sub), $sig3, $key, OPENSSL_ALGO_SHA1);
$sub['Signature'] = base64_encode($sig3);
$out('sns_subscription_signatur', marketing_sns_verify($sub));
$out('sns_unbekannter_typ', marketing_sns_string_to_sign(['Type' => 'Other']) === null);
unset($GLOBALS['marketing_sns_cert_fetcher']);
$out('sns_zertifikat_nur_amazon', marketing_sns_certificate('https://sns.eu-central-1.amazonaws.com.evil.test/x.pem') === null && marketing_sns_certificate('http://sns.eu-central-1.amazonaws.com/x.pem') === null);

// 13. Export und Audit
$csv = marketing_list_export_csv($admin, $imp);
$out('export_bom_kopf', str_starts_with($csv, "\xEF\xBB\xBF\"email\";\"name\"") && str_contains($csv, '"erika@example.test"'));
$out('export_staff_verweigert', $try(static fn() => marketing_list_export_csv($staff, $imp)));
$acts = $pdo->query("SELECT DISTINCT action FROM audit_log WHERE action LIKE 'marketing_%' ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$out('audit_aktionen', implode(',', $acts));
$out('audit_keine_klartext_adresse', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('marketing_suppressed','marketing_unsuppressed','marketing_campaign_test_sent','marketing_recipient_removed') AND (details_json LIKE '%erika@%' OR details_json LIKE '%max@%' OR details_json LIKE '%gesperrt@%' OR details_json LIKE '%pruefer@%' OR details_json LIKE '%inhaber-%' OR target_id LIKE '%erika@%' OR target_id LIKE '%gesperrt@%' OR target_id LIKE '%inhaber-%')")->fetchColumn() === 0);
