<?php
/**
 * Prueffaelle der Vorregistrierung (app/interest.php) gegen eine echte, temporaere Datenbank.
 * Aufruf durch tools/interest-check.sh:  php tools/lib/interest-sim.php <repo>
 * Mailversand laeuft ueber die Warteschlange (features.queue); Bestaetigungsmails landen als Jobs vom Typ mail,
 * daraus werden die Token A (Bestaetigung) und B (Abmeldung) gelesen. Ausgabe: Zeilen "key=wert".
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/interest.php';
require_once $root . '/php-ionos/app/queue.php';
$pdo = db();
foreach (['interest_registrations', 'jobs', 'funnel_events', 'platform_settings'] as $t) { $pdo->exec("DELETE FROM $t"); }
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };
$mails = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='mail'")->fetchColumn();
$zeilen = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM interest_registrations")->fetchColumn();
$row = static function (string $email = 'kunde@example.test') use ($pdo) { $s = $pdo->prepare('SELECT * FROM interest_registrations WHERE email = ?'); $s->execute([$email]); $r = $s->fetch(); return $r ?: null; };
/** Token aus allen Mailjobs lesen, deren Hash zur Zeile passt. */
$tokens = static function (array $r) use ($pdo): array {
    $a = ''; $b = '';
    foreach ($pdo->query("SELECT payload FROM jobs WHERE type='mail'")->fetchAll(PDO::FETCH_COLUMN) as $pl) {
        $text = (string)(json_decode((string)$pl, true)['text'] ?? '');
        if (preg_match('~vormerken\.php\?token=([a-f0-9]{64})~', $text, $m) && hash('sha256', $m[1]) === (string)$r['token_hash']) { $a = $m[1]; }
        if (preg_match('~vormerken\.php\?abmelden=([a-f0-9]{64})~', $text, $m) && hash('sha256', $m[1]) === (string)$r['manage_token_hash']) { $b = $m[1]; }
    }
    return ['a' => $a, 'b' => $b];
};

$base = ['provider' => 'sevdesk', 'email' => 'Kunde@Example.test', 'name' => ' Erika  Muster ', 'company' => '  Muster  GmbH ', 'consent' => '1', 'website' => ''];
$r1 = interest_register($base, 'www.smart-einzug.de');
$out('neu_state', (string)$r1['state']); $out('zeilen', $zeilen()); $out('mails', $mails());
$r = $row();
$out('email_normalisiert', $r['email']); $out('name_bereinigt', $r['name']); $out('company_bereinigt', $r['company']); $out('herkunft', $r['source_domain']);
$out('status_nach_anlage', $r['status']); $out('consent', $r['consent_text']); $out('consent_at', $r['consent_at'] ? 1 : 0); $out('purpose', $r['purpose']);
$tk = $tokens($r);
$out('token_a_in_mail', $tk['a'] !== '' ? 1 : 0); $out('token_b_in_mail', $tk['b'] !== '' ? 1 : 0); $out('tokens_verschieden', $tk['a'] !== $tk['b'] ? 1 : 0);
$out('klartext_nicht_gespeichert', ($tk['a'] !== '' && (str_contains(json_encode($r), $tk['a']) || str_contains(json_encode($r), $tk['b']))) ? 0 : 1);
$erste = (string)(json_decode((string)$pdo->query("SELECT payload FROM jobs WHERE type='mail' LIMIT 1")->fetchColumn(), true)['text'] ?? '');
$out('mail_text_kein_abo', str_contains($erste, 'kein kostenpflichtiges Abonnement') ? 1 : 0);
$out('funnel_submitted', (int)$pdo->query("SELECT COUNT(*) FROM funnel_events WHERE event='interest_submitted'")->fetchColumn());

$r2 = interest_register($base, 'smart-einzug.de');
$out('wdh_state', (string)$r2['state']); $out('wdh_zeilen', $zeilen()); $out('wdh_mails', $mails());
for ($i = 0; $i < 4; $i++) { $pdo->exec("UPDATE interest_registrations SET last_mail_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 11 MINUTE)"); interest_register($base, null); }
$out('tagesgrenze_mails', $mails()); $out('tagesgrenze_zaehler', (int)$row()['mail_count']);
$r = $row(); $tk = $tokens($r);
$out('token_a_erneuert', $tk['a'] !== '' ? 1 : 0); $out('token_b_gueltig', $tk['b'] !== '' ? 1 : 0);

$out('err_email', (string)interest_register(['provider'=>'sevdesk','email'=>'kein-mail','consent'=>'1'])['error']);
$out('err_consent', (string)interest_register(['provider'=>'sevdesk','email'=>'a@b.test'])['error']);
$out('err_honeypot', (string)interest_register(['provider'=>'sevdesk','email'=>'a@b.test','consent'=>'1','website'=>'x'])['error']);
$out('err_provider_frei', (string)interest_register(['provider'=>'lexware_office','email'=>'a@b.test','consent'=>'1'])['error']);
$out('err_provider_fremd', (string)interest_register(['provider'=>'gibtsnicht','email'=>'a@b.test','consent'=>'1'])['error']);
$out('err_zeilen', $zeilen());
$pdo->exec("INSERT INTO platform_settings (`key`, `value`) VALUES ('sevdesk_waitlist', '0')");
$out('err_waitlist_zu', (string)interest_register(['provider'=>'sevdesk','email'=>'neu@b.test','consent'=>'1'])['error']);
$pdo->exec("DELETE FROM platform_settings");

$out('confirm_falsch', interest_confirm(str_repeat('0', 64))); $out('confirm_muell', interest_confirm('abc'));
$manageNeu = null;
$out('confirm', interest_confirm($tk['a'], $manageNeu));
$r = $row();
$out('manage_neu_passt', ($manageNeu !== null && hash('sha256', $manageNeu) === (string)$r['manage_token_hash']) ? 1 : 0);
$letzte = (string)(json_decode((string)$pdo->query("SELECT payload FROM jobs WHERE type='mail' ORDER BY created_at DESC LIMIT 1")->fetchColumn(), true)['text'] ?? '');
$alleMails = implode(' ', array_map(static fn($pl) => (string)(json_decode((string)$pl, true)['text'] ?? ''), $pdo->query("SELECT payload FROM jobs WHERE type='mail'")->fetchAll(PDO::FETCH_COLUMN)));
$out('bestaetigt_mail', (str_contains($alleMails, 'ist bestätigt') && str_contains($alleMails, 'abmelden=' . $manageNeu)) ? 1 : 0);
$out('mails_nach_confirm', $mails());
$tk['b'] = $manageNeu;
$out('status_bestaetigt', $r['status']); $out('confirmed_at', $r['confirmed_at'] ? 1 : 0); $out('token_a_geloescht', $r['token_hash'] === null ? 1 : 0);
$out('confirm_erneut', interest_confirm($tk['a']));
$out('funnel_confirmed', (int)$pdo->query("SELECT COUNT(*) FROM funnel_events WHERE event='interest_confirmed'")->fetchColumn());
$out('nach_bestaetigung_state', (string)interest_register($base, null)['state']); $out('nach_bestaetigung_mails', $mails());
$out('angaben', interest_optional_update($tk['b'], ['beta_interest' => '1', 'invoices_per_month' => '21_100', 'has_stripe' => '1', 'has_api_access' => '0']) ? 1 : 0);
$r = $row(); $out('beta', (int)$r['beta_interest']); $out('ipm', (string)$r['invoices_per_month']); $out('has_stripe', (string)$r['has_stripe']); $out('has_api', (string)$r['has_api_access']);
interest_optional_update($tk['b'], ['invoices_per_month' => 'boese']); $out('ipm_ungueltig_null', $row()['invoices_per_month'] === null ? 1 : 0);
$out('invite', interest_invite_id((string)$r['id']) ? 1 : 0);
$out('unsub_falsch', interest_unsubscribe(str_repeat('a', 64)));
$out('unsub', interest_unsubscribe($tk['b']));
$out('status_abgemeldet', $row()['status']);
$out('angaben_nach_abmeldung', interest_optional_update($tk['b'], ['beta_interest' => '1']) ? 1 : 0);
$pdo->exec("UPDATE interest_registrations SET last_mail_at = NULL, mail_count = 0, mail_window_at = NULL");
$r3 = interest_register($base, null);
$out('erneut_state', (string)$r3['state']); $out('erneut_status', $row()['status']); $out('erneut_zeilen', $zeilen());
$out('block', interest_block_id((string)$r['id']) ? 1 : 0);
$r = $row(); $out('block_status', $r['status']); $out('block_name_null', ($r['name'] === null && $r['company'] === null && $r['source_domain'] === null) ? 1 : 0); $out('block_email_bleibt', $r['email']);
$pdo->exec("UPDATE interest_registrations SET last_mail_at = NULL, mail_count = 0, mail_window_at = NULL");
$mailsVor = $mails(); $out('block_register_state', (string)interest_register($base, null)['state']); $out('block_keine_mail', $mails() === $mailsVor ? 1 : 0);
$out('block_invite', interest_invite_id((string)$r['id']) ? 1 : 0);
$pdo->exec("UPDATE interest_registrations SET unsubscribed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY), created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY)");
$out('cleanup_gesperrt_bleibt', interest_cleanup() === 0 ? 1 : 0);
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at) VALUES (?, 'sevdesk', 'alt-pending@x.test', 'pending', 'v', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY))")->execute([uuid4()]);
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at, unsubscribed_at) VALUES (?, 'sevdesk', 'alt-unsub@x.test', 'unsubscribed', 'v', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY))")->execute([uuid4()]);
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at, notified_at) VALUES (?, 'sevdesk', 'alt-fertig@x.test', 'confirmed', 'v', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY))")->execute([uuid4()]);
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at) VALUES (?, 'sevdesk', 'jung-pending@x.test', 'pending', 'v', UTC_TIMESTAMP())")->execute([uuid4()]);
$out('cleanup', interest_cleanup()); $out('jung_bleibt', $row('jung-pending@x.test') ? 1 : 0);
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, name, company, source_domain, status, consent_text, created_at, beta_interest) VALUES (?, 'sevdesk', 'such@x.test', '=1+1', 'Firma \"Q\"', 'lexware-einzug.de', 'confirmed', 'v', UTC_TIMESTAMP(), 1)")->execute([uuid4()]);
$m = interest_metrics('sevdesk');
$out('metrik_submitted', (int)$m['submitted']); $out('metrik_confirmed', (int)$m['confirmed']); $out('metrik_beta', (int)$m['beta']); $out('metrik_connected', (int)$m['connected']);
$out('suche_q', count(interest_search(['q' => 'such@'])));
$out('suche_status', count(interest_search(['status' => 'confirmed'])));
$out('suche_source', count(interest_search(['source' => 'lexware-einzug.de'])));
$out('suche_blocked', count(interest_search(['status' => 'blocked'])));
$csv = interest_export_csv(interest_search(['q' => 'such@']));
$out('csv_bom', str_starts_with($csv, "\xEF\xBB\xBF") ? 1 : 0);
$out('csv_formel_entschaerft', str_contains($csv, '"\'=1+1"') ? 1 : 0);
$out('csv_quote', str_contains($csv, '"Firma ""Q"""') ? 1 : 0);
for ($i = 0; $i < INTEREST_MAX_PER_MINUTE; $i++) { $pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at) VALUES (?, 'sevdesk', ?, 'pending', 'v', UTC_TIMESTAMP())")->execute([uuid4(), "m$i@x.test"]); }
$out('limit_error', (string)interest_register(['provider'=>'sevdesk','email'=>'neu@x.test','consent'=>'1'])['error']);
$erl = ['smart-einzug.de', 'lexware-einzug.de', 'app.smart-einzug.de'];
$out('origin_ok', interest_origin_allowed('https://www.smart-einzug.de', null, $erl) ? 1 : 0);
$out('origin_fremd', interest_origin_allowed('https://boese.example', 'https://smart-einzug.de/x', $erl) ? 1 : 0);
$out('origin_referer', interest_origin_allowed(null, 'https://lexware-einzug.de/seite/', $erl) ? 1 : 0);
$out('origin_leer', interest_origin_allowed(null, null, $erl) ? 1 : 0);
$out('origin_null', interest_origin_allowed('null', null, $erl) ? 1 : 0);
