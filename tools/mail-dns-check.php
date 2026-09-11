<?php
/**
 * Prueft die Auswertungslogik der Zustellbarkeit (app/mail_dns.php) ohne Netz, ohne Datenbank, ohne Versand.
 * Die Faelle bilden reale Konstellationen ab, darunter die DNS-Zone von smart-einzug.de am 11.09.2026
 * (SPF mit IONOS-Include und ~all, DMARC als CNAME auf den Anbieter, DKIM s1/s2 als CNAME).
 *
 * Aufruf: php tools/mail-dns-check.php     Exit 0 = alle Faelle bestanden
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok  = static function (string $m) use (&$pass): void { $pass++; echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  FAIL  $m\n"; };
$erw = static function (string $name, array $e, string $status, string $titelTeil = '') use ($ok, $bad): void {
    if ($e['status'] !== $status) { $bad("$name: Status {$e['status']}, erwartet $status ({$e['titel']})"); return; }
    if ($titelTeil !== '' && stripos($e['titel'] . ' ' . $e['hinweis'], $titelTeil) === false) { $bad("$name: Text ohne \"$titelTeil\" ({$e['titel']})"); return; }
    $ok($name);
};

// Die Datei verweigert den Direktaufruf; hier ueber einen Wrapper einbinden, damit get_included_files()[0] nicht sie selbst ist.
require $root . '/php-ionos/app/mail_dns.php';

echo "A) Hilfsfunktionen\n";
mail_dns_domain_of('Kontakt@Smart-Einzug.de ') === 'smart-einzug.de' ? $ok('Domain aus Adresse, kleingeschrieben') : $bad('Domain aus Adresse');
mail_dns_domain_of('keine-adresse') === '' ? $ok('ungueltige Adresse liefert leer') : $bad('ungueltige Adresse');
mail_dns_registrable('smtp.ionos.de') === 'ionos.de' ? $ok('registrierbare Domain smtp.ionos.de') : $bad('registrierbare Domain');
mail_dns_registrable('mail.example.co.uk') === 'co.uk' ? $ok('zweistufige Endung bewusst grob (dokumentierte Grenze)') : $bad('registrierbare Domain co.uk');

echo "\nB) SPF\n";
$erw('kein SPF', mail_dns_spf(['google-site-verification=x'], 'smtp', 'smtp.ionos.de'), 'fehler', 'SPF fehlt');
$erw('zwei SPF-Eintraege sind ungueltig', mail_dns_spf(['v=spf1 include:a ~all', 'v=spf1 include:b ~all'], 'smtp', 'smtp.ionos.de'), 'fehler', 'Mehrere');
$erw('IONOS-Include passt zu smtp.ionos.de (Zone smart-einzug.de)', mail_dns_spf(['google-site-verification=x', 'v=spf1 include:_spf-eu.ionos.com ~all', 'stripe-verification=y'], 'smtp', 'smtp.ionos.de'), 'ok', '~all');
$erw('-all wird als strikt erkannt', mail_dns_spf(['v=spf1 include:_spf-eu.ionos.com -all'], 'smtp', 'smtp.ionos.de'), 'ok', 'Strikte');
$erw('SPF nennt den SMTP-Anbieter nicht', mail_dns_spf(['v=spf1 include:_spf.google.com ~all'], 'smtp', 'smtp.ionos.de'), 'warnung', 'nicht frei');
$erw('Versand ueber mail(): Server-Adresse muss im SPF stehen', mail_dns_spf(['v=spf1 include:_spf-eu.ionos.com ~all'], 'mail', ''), 'warnung', 'mail()');

echo "\nC) DMARC\n";
$erw('kein DMARC', mail_dns_dmarc([], false, 'smart-einzug.de'), 'fehler', 'p=none; rua=mailto:dmarc@smart-einzug.de');
$erw('CNAME auf Anbieter ohne aufloesbare Richtlinie', mail_dns_dmarc([], true, 'smart-einzug.de'), 'fehler', 'CNAME');
$erw('CNAME auf Anbieter mit Richtlinie: nicht in eigener Hand (Zone smart-einzug.de)', mail_dns_dmarc(['v=DMARC1; p=none; rua=mailto:dmarc@ionos.de'], true, 'smart-einzug.de'), 'warnung', 'CNAME');
$erw('eigener TXT, Berichte an Dritte', mail_dns_dmarc(['v=DMARC1; p=quarantine; rua=mailto:x@dienstleister.example'], false, 'smart-einzug.de'), 'warnung', 'rua');
$erw('eigener TXT mit eigener Berichtsadresse', mail_dns_dmarc(['v=DMARC1; p=none; rua=mailto:dmarc@smart-einzug.de; fo=1'], false, 'smart-einzug.de'), 'ok', 'p=none');

echo "\nD) DKIM\n";
$erw('kein Eintrag', mail_dns_dkim([]), 'fehler', 'Kein DKIM');
$erw('CNAME auf Anbieter ohne Schluessel dahinter', mail_dns_dkim(['s1-ionos' => ['txt' => [], 'cname' => 's1.dkim.ionos.com']]), 'unklar', 'nicht eingeschaltet');
$erw('Schluessel abrufbar', mail_dns_dkim(['s1-ionos' => ['txt' => ['v=DKIM1; k=rsa; p=MIIBIjAN'], 'cname' => 's1.dkim.ionos.com']]), 'ok', 'beweist nicht');
$e = mail_dns_dkim(['s1-ionos' => ['txt' => ['v=DKIM1; p=abc'], 'cname' => '']]);
stripos($e['hinweis'], 'Authentication-Results') !== false ? $ok('Hinweis auf den Kopf der empfangenen Nachricht') : $bad('Hinweis auf Authentication-Results fehlt');

echo "\nE) Absenderkonsistenz\n";
$r = mail_dns_alignment('kontakt@smart-einzug.de', 'kontakt@smart-einzug.de', null, 'smtp');
count($r) === 1 && $r[0]['status'] === 'ok' ? $ok('gleiche Domain, kein Reply-To: ok') : $bad('gleiche Domain: ' . json_encode($r));
$r = mail_dns_alignment('kontakt@smart-einzug.de', 'noreply@lexware-einzug.de', null, 'smtp');
$erw('Absender und Versandpostfach auf verschiedenen Domains', $r[0], 'fehler', 'Alignment');
$r = mail_dns_alignment('kontakt@smart-einzug.de', 'kontakt@smart-einzug.de', 'info@mueller-holding.ag', 'smtp');
count($r) === 2 ? $erw('Reply-To auf fremder Domain ist eine Warnung (Vorlage config.example.php)', $r[1], 'warnung', 'Reply-To') : $bad('Reply-To-Fall liefert ' . count($r) . ' Ergebnisse');
$r = mail_dns_alignment('kontakt@smart-einzug.de', 'kontakt', null, 'smtp');
$erw('SMTP-Benutzer ohne Domain', $r[0], 'warnung', 'vollstaendige Adresse');
$r = mail_dns_alignment('', '', null, 'smtp');
$erw('leere Absenderadresse', $r[0], 'fehler', 'Absenderadresse');
$r = mail_dns_alignment('kontakt@smart-einzug.de', '', null, 'mail');
count($r) === 0 ? $ok('Transport mail(): kein Postfachvergleich') : $bad('mail(): unerwartete Ergebnisse');

echo "\nF) Bericht und Gesamtstatus\n";
$liste = [mail_dns_ergebnis('ok', 'A'), mail_dns_ergebnis('warnung', 'B', 'Hinweis B', 'v=spf1 x'), mail_dns_ergebnis('unklar', 'C')];
mail_dns_gesamtstatus($liste) === 'unklar' ? $ok('Gesamtstatus nimmt den schlechtesten Wert') : $bad('Gesamtstatus');
mail_dns_gesamtstatus([mail_dns_ergebnis('ok', 'A'), mail_dns_ergebnis('fehler', 'B')]) === 'fehler' ? $ok('fehler schlaegt unklar') : $bad('Gesamtstatus fehler');
$bericht = mail_dns_bericht($liste);
(str_contains($bericht, '[ OK     ] A') && str_contains($bericht, '[ PRUEFEN] B') && str_contains($bericht, 'Eintrag: v=spf1 x') && str_contains($bericht, '[ UNKLAR ] C'))
    ? $ok('Bericht enthaelt Status, Titel, Eintrag und Hinweis') : $bad('Bericht: ' . $bericht);
!str_contains(file_get_contents($root . '/php-ionos/app/mail_dns.php'), "\u{2014}") && !str_contains(file_get_contents($root . '/php-ionos/bin/mail-check.php'), "\u{2014}")
    ? $ok('keine Gedankenstriche in den Texten') : $bad('Gedankenstrich gefunden');
str_contains(file_get_contents($root . '/php-ionos/bin/mail-check.php'), "isset(\$opts['zustellbarkeit'])") ? $ok('bin/mail-check.php kennt --zustellbarkeit') : $bad('Option fehlt in bin/mail-check.php');

echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
