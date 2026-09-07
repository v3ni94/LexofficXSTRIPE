<?php
/**
 * Prueffaelle der Vormerkung (app/interest.php) gegen eine echte, temporaere Datenbank.
 * Aufruf durch tools/interest-check.sh:  php tools/lib/interest-sim.php <repo>
 * Gibt Zeilen "key=wert" aus. Mailversand laeuft ueber die Warteschlange (features.queue), die
 * Bestaetigungsmail landet als Job vom Typ mail in der Tabelle jobs; daraus wird der Token gelesen.
 */
declare(strict_types=1);
$root = $argv[1] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/interest.php';
require_once $root . '/php-ionos/app/queue.php';
$pdo = db();
$pdo->exec('DELETE FROM interest_registrations'); $pdo->exec('DELETE FROM jobs');
$out = static function (string $k, $v): void { echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . "\n"; };

$base = ['provider' => 'sevdesk', 'email' => 'Kunde@Example.test', 'company' => '  Muster  GmbH ', 'consent' => '1', 'website' => ''];
$r = interest_register($base, 'www.smart-einzug.de');
$out('neu_ok', $r['ok']); $out('neu_state', (string)$r['state']);
$row = $pdo->query("SELECT * FROM interest_registrations")->fetch();
$out('zeilen', (int)$pdo->query("SELECT COUNT(*) FROM interest_registrations")->fetchColumn());
$out('email_normalisiert', $row['email']); $out('company_bereinigt', $row['company']); $out('herkunft', $row['source_domain']);
$out('status_nach_anlage', $row['status']); $out('consent', $row['consent_text']);
$job = $pdo->query("SELECT payload FROM jobs WHERE type='mail' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$payload = $job ? json_decode((string)$job, true) : null;
$out('mail_job', $payload ? 1 : 0);
$out('mail_an', (string)($payload['to'] ?? ''));
preg_match('~vormerken\.php\?token=([a-f0-9]{64})~', (string)($payload['text'] ?? ''), $m);
$token = $m[1] ?? '';
$out('token_im_text', $token !== '' ? 1 : 0);
$out('abmeldelink_in_mail', str_contains((string)($payload['text'] ?? ''), 'token=' . $token . '&aktion=abmelden') ? 1 : 0);
$out('consent_at', $row['consent_at'] ? 1 : 0); $out('last_mail_at', $row['last_mail_at'] ? 1 : 0); $out('mail_count', (int)$row['mail_count']);
$out('token_nicht_gespeichert', ($token !== '' && !str_contains(json_encode($row), $token)) ? 1 : 0);
$out('token_hash_passt', ($token !== '' && hash('sha256', $token) === $row['token_hash']) ? 1 : 0);

// Wiederholung innerhalb der Wartezeit: keine zweite Zeile, keine zweite Mail, gleiche Antwortklasse
$r2 = interest_register($base, 'smart-einzug.de');
$out('wdh_state', (string)$r2['state']);
$out('wdh_zeilen', (int)$pdo->query("SELECT COUNT(*) FROM interest_registrations")->fetchColumn());
$out('wdh_mails', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='mail'")->fetchColumn());

// Tagesgrenze: nach Ablauf der 10-Minuten-Sperre hoechstens 3 Mails in 24 Stunden
for ($i = 0; $i < 4; $i++) {
    $pdo->exec("UPDATE interest_registrations SET last_mail_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 11 MINUTE)");
    interest_register($base, null);
}
$out('tagesgrenze_mails', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='mail'")->fetchColumn());
$out('tagesgrenze_zaehler', (int)$pdo->query("SELECT mail_count FROM interest_registrations")->fetchColumn());
// Der letzte Token gilt (neuer Token je Versand)
$hashAktuell = (string)$pdo->query("SELECT token_hash FROM interest_registrations")->fetchColumn();
$gefunden = '';
foreach ($pdo->query("SELECT payload FROM jobs WHERE type='mail'")->fetchAll(PDO::FETCH_COLUMN) as $pl) {
    if (preg_match('~vormerken\.php\?token=([a-f0-9]{64})~', (string)(json_decode((string)$pl, true)['text'] ?? ''), $mm) && hash('sha256', $mm[1]) === $hashAktuell) {
        $gefunden = $mm[1];
    }
}
$out('token_erneuert', ($gefunden !== '' && $gefunden !== $token) ? 1 : 0);
$token = $gefunden !== '' ? $gefunden : $token;

// Ungueltige Eingaben
$out('err_email', (string)interest_register(['provider'=>'sevdesk','email'=>'kein-mail','consent'=>'1'])['error']);
$out('err_consent', (string)interest_register(['provider'=>'sevdesk','email'=>'a@b.test'])['error']);
$out('err_honeypot', (string)interest_register(['provider'=>'sevdesk','email'=>'a@b.test','consent'=>'1','website'=>'x'])['error']);
$out('err_provider_frei', (string)interest_register(['provider'=>'lexware_office','email'=>'a@b.test','consent'=>'1'])['error']);
$out('err_provider_fremd', (string)interest_register(['provider'=>'gibtsnicht','email'=>'a@b.test','consent'=>'1'])['error']);
$out('err_zeilen', (int)$pdo->query("SELECT COUNT(*) FROM interest_registrations")->fetchColumn());

// Bestaetigen
$out('confirm_falsch', interest_confirm(str_repeat('0', 64)));
$out('confirm_muell', interest_confirm('abc'));
$out('confirm', interest_confirm($token));
$out('confirm_erneut', interest_confirm($token));
$row = $pdo->query("SELECT * FROM interest_registrations")->fetch();
$out('status_bestaetigt', $row['status']); $out('confirmed_at', $row['confirmed_at'] ? 1 : 0); $out('ablauf_entfernt', $row['token_expires_at'] === null ? 1 : 0);
// Erneute Anmeldung nach Bestaetigung: gleiche Antwort, keine Mail
$r3 = interest_register($base, null);
$out('nach_bestaetigung_state', (string)$r3['state']);
$out('nach_bestaetigung_mails', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='mail'")->fetchColumn());
// Abmelden
$out('unsub', interest_unsubscribe($token));
$out('status_abgemeldet', $pdo->query("SELECT status FROM interest_registrations")->fetchColumn());
$out('confirm_nach_abmeldung', interest_confirm($token));
// Wartung: abgemeldet nach 30 Tagen loeschen
$out('cleanup_abgemeldet_frueh', interest_cleanup());
$pdo->exec("UPDATE interest_registrations SET unsubscribed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY)");
$out('cleanup_abgemeldet_spaet', interest_cleanup());
// Abgelaufener Link
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, token_hash, token_expires_at, created_at) VALUES (?, 'sevdesk', 'alt@x.test', 'pending', 'v', ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY))")->execute([uuid4(), hash('sha256', $token)]);
$out('confirm_abgelaufen', interest_confirm($token));
$out('cleanup_pending_frueh', interest_cleanup());
$pdo->exec("UPDATE interest_registrations SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY)");
$out('cleanup_pending_spaet', interest_cleanup());
// Bestaetigt + benachrichtigt: 30 Tage nach Startnachricht loeschen
$pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, confirmed_at, notified_at, created_at) VALUES (?, 'sevdesk', 'fertig@x.test', 'confirmed', 'v', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY), UTC_TIMESTAMP())")->execute([uuid4()]);
$out('cleanup_benachrichtigt', interest_cleanup());
// Herkunftspruefung ohne Datenbank
$erl = ['smart-einzug.de', 'lexware-einzug.de', 'app.smart-einzug.de'];
$out('origin_ok', interest_origin_allowed('https://www.smart-einzug.de', null, $erl) ? 1 : 0);
$out('origin_fremd', interest_origin_allowed('https://boese.example', 'https://smart-einzug.de/x', $erl) ? 1 : 0);
$out('origin_referer', interest_origin_allowed(null, 'https://lexware-einzug.de/seite/', $erl) ? 1 : 0);
$out('origin_leer', interest_origin_allowed(null, null, $erl) ? 1 : 0);
$out('origin_null', interest_origin_allowed('null', null, $erl) ? 1 : 0);
// Statistik + Obergrenze je Minute
for ($i = 0; $i < INTEREST_MAX_PER_MINUTE; $i++) {
    $pdo->prepare("INSERT INTO interest_registrations (id, provider_code, email, status, consent_text, created_at) VALUES (?, 'sevdesk', ?, 'pending', 'v', UTC_TIMESTAMP())")->execute([uuid4(), "m$i@x.test"]);
}
$out('limit_error', (string)interest_register(['provider'=>'sevdesk','email'=>'neu@x.test','consent'=>'1'])['error']);
$s = interest_stats();
$out('stats_pending', (int)($s['sevdesk']['pending'] ?? -1)); $out('stats_name', (string)($s['sevdesk']['name'] ?? ''));
