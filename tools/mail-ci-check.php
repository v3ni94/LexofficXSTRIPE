<?php
/**
 * Prueft die E-Mail-Gestaltung (app/mailer.php) gegen das CI der Mueller Holding AG: Pflichtangaben nach § 80 AktG,
 * Farben (Gold #E3AC48, Anthrazit #2E2D2E, Beige #FBF6EC), Wortmarke, keine Gedankenstriche, Text- und HTML-Fassung,
 * neue Vorlagen (Willkommen, Vormerkung bestaetigt) mit Links. Ohne Datenbank, ohne Netz.
 *   php tools/mail-ci-check.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
putenv('SMARTEINZUG_CONFIG=' . $root . '/php-ionos/app/config.example.php');
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/mailer.php';
$pass = 0; $fail = 0;
$ok = static function (string $m) use (&$pass): void { $pass++; echo "  OK    $m\n"; };
$bad = static function (string $m) use (&$fail): void { $fail++; echo "  FAIL  $m\n"; };

$l = mail_layout('Titel', ['Absatz eins', 'Absatz <zwei> & drei'], ['label' => 'Knopf', 'url' => 'https://app.example.test/x?a=1&b=2'], 'Fussnote', ['label' => 'Abmelden', 'url' => 'https://app.example.test/vormerken.php?abmelden=abc']);
echo "1) Layout\n";
foreach (['Müller Holding AG', 'Rheinpromenade 13', '40789 Monheim am Rhein', 'kontakt@mueller-holding.ag', 'Sitz: Monheim am Rhein', 'Registergericht: Amtsgericht Düsseldorf', 'HRB 104291', 'Vorstand: Timo Müller', 'Aufsichtsratsvorsitzender: Jan Walprecht', 'Kein Produkt der Haufe-Lexware'] as $s) {
    (str_contains($l['html'], $s) && str_contains($l['text'], $s)) ? $ok("Pflichtangabe in Text und HTML: $s") : $bad("Pflichtangabe fehlt: $s");
}
foreach (['#E3AC48', '#2E2D2E', '#FBF6EC', '#DDDBD6'] as $c) { str_contains($l['html'], $c) ? $ok("Farbe $c") : $bad("Farbe fehlt $c"); }
str_contains($l['html'], '/assets/img/logo-horizontal.png') ? $ok('Wortmarke eingebunden') : $bad('Wortmarke fehlt');
str_contains($l['html'], 'Carlito, Calibri') ? $ok('Hausschrift Carlito/Calibri') : $bad('Schrift fehlt');
str_contains($l['html'], 'Absatz &lt;zwei&gt; &amp; drei') ? $ok('HTML wird escaped') : $bad('Escaping fehlt');
str_contains($l['html'], 'href="https://app.example.test/x?a=1&amp;b=2"') ? $ok('Button-URL escaped') : $bad('Button-URL');
(str_contains($l['html'], 'abmelden=abc') && str_contains($l['text'], 'Abmelden: https://app.example.test/vormerken.php?abmelden=abc')) ? $ok('Zweitlink (Abmelden) in HTML und Text') : $bad('Zweitlink fehlt');
(!str_contains($l['text'], '—') && !str_contains($l['html'], '—')) ? $ok('keine Gedankenstriche') : $bad('Gedankenstrich');
str_contains($l['html'], '<!DOCTYPE html>') && str_contains($l['html'], 'lang="de"') ? $ok('HTML-Dokument deutsch') : $bad('HTML-Rahmen');

echo "2) Vorlagen\n";
$w = mail_tpl_welcome('Muster GmbH', 'https://app.example.test/verify-email.php?token=t');
(str_contains($w['subject'], 'Willkommen') && str_contains($w['html'], 'Muster GmbH') && str_contains($w['text'], 'verify-email.php?token=t')) ? $ok('Willkommensmail mit Firma und Bestaetigungslink') : $bad('Willkommensmail');
str_contains($w['text'], 'kein Vertrag') ? $ok('Willkommensmail: kein Vertrag ohne Registrierung') : $bad('Hinweis fehlt');
$c = mail_tpl_interest_confirm('sevdesk', 'https://app.example.test/vormerken.php?token=a', 'https://app.example.test/vormerken.php?abmelden=b');
(str_contains($c['subject'], 'Bitte bestätigen Sie Ihre sevdesk-Vormerkung') && str_contains($c['text'], 'kein kostenpflichtiges Abonnement') && str_contains($c['html'], 'abmelden=b')) ? $ok('Vormerkungs-Bestaetigungsmail nach Masterplan mit Abmeldelink') : $bad('Bestaetigungsmail');
$d = mail_tpl_interest_confirmed('sevdesk', 'https://app.example.test/vormerken.php?abmelden=b', 'https://smart-einzug.de/integrationen/sevdesk/');
(str_contains($d['subject'], 'ist bestätigt') && str_contains($d['text'], 'noch kein sevdesk- oder Stripe-Konto verbinden') && str_contains($d['html'], 'abmelden=b')) ? $ok('Mail nach Bestaetigung mit Abmeldelink') : $bad('Mail nach Bestaetigung');
foreach ([$w, $c, $d] as $tpl) { str_contains($tpl['html'], 'HRB 104291') ? $ok('Pflichtangaben in Vorlage: ' . $tpl['subject']) : $bad('Pflichtangaben fehlen: ' . $tpl['subject']); }
echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
