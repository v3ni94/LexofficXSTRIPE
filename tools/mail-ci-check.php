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
$w0 = mail_tpl_welcome('Muster GmbH', null);
(!str_contains($w0['html'], 'verify-email') && str_contains($w0['text'], 'gilt als bestätigt') && str_contains($w0['html'], 'login.php')) ? $ok('Willkommensmail ohne Bestaetigungslink (Adresse gilt als bestaetigt)') : $bad('Willkommensmail ohne Link');
$c2 = mail_tpl_interest_confirm('sevdesk', 'https://app.example.test/vormerken.php?token=a', 'https://app.example.test/vormerken.php?abmelden=b', '07.09.2026', 'smart-einzug.de');
(str_contains($c2['text'], 'Ihre Eintragung vom 07.09.2026 über smart-einzug.de') && str_contains($c2['text'], 'müssen Sie nichts tun')) ? $ok('Nachgesendete Bestaetigungsmail nennt Datum und Herkunft') : $bad('Nachsendung ohne Datum');
echo "3) Vorabankuendigung (mail_tpl_prenotification, Musterdaten)\n";
$pn = mail_tpl_prenotification(['name' => 'Muster GmbH', 'creditor_identifier' => 'DE98ZZZ09999999999'], ['voucher_number' => 'RE-2026-0042'], ['mandate_reference' => 'MG-000042'], 123456, '2026-09-22');
($pn['subject'] === 'Vorabankündigung SEPA-Lastschrift RE-2026-0042') ? $ok('Betreff mit Rechnungsnummer') : $bad('Betreff: ' . $pn['subject']);
foreach (['Rechnung RE-2026-0042 über 1.234,56 EUR, Fälligkeit/Einzug am 22.09.2026.', 'Zahlungsempfänger: Muster GmbH.', 'Mandatsreferenz: MG-000042.', 'Gläubiger-Identifikationsnummer: DE98ZZZ09999999999.', 'Zahlungsdienstleister Stripe', 'ausreichende Kontodeckung'] as $sTxt) {
    (str_contains($pn['text'], $sTxt) && str_contains($pn['html'], htmlspecialchars($sTxt, ENT_QUOTES, 'UTF-8'))) ? $ok("Pflichtinhalt: $sTxt") : $bad("Pflichtinhalt fehlt: $sTxt");
}
$pn0 = mail_tpl_prenotification(['name' => 'Muster GmbH', 'creditor_identifier' => ''], ['voucher_number' => 'RE-1'], ['mandate_reference' => 'M-1'], 100, '2026-09-22');
(!str_contains($pn0['text'], 'Gläubiger-Identifikationsnummer')) ? $ok('ohne Gläubiger-ID kein leerer Satz') : $bad('Gläubiger-ID leer ausgegeben');
$smp = mail_prenotification_sample(['name' => 'Firma X', 'creditor_identifier' => '']);
($smp['org']['name'] === 'Firma X' && $smp['invoice']['voucher_number'] === 'RE-MUSTER-0001' && $smp['mandate']['mandate_reference'] === 'MUSTER-MANDAT-0001' && $smp['amount_cents'] === 123456 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $smp['due_date']) === 1) ? $ok('Musterdaten: Firma uebernommen, Rechnung und Mandat als Muster gekennzeichnet') : $bad('Musterdaten');
$pnS = mail_tpl_prenotification($smp['org'], $smp['invoice'], $smp['mandate'], $smp['amount_cents'], $smp['due_date'], 'Musterversand ohne echten Einzug.');
str_contains($pnS['text'], 'Musterversand ohne echten Einzug. Firma X') ? $ok('Musterhinweis in der Fussnote') : $bad('Musterhinweis fehlt');
$coll = file_get_contents($root . '/php-ionos/app/collections.php');
(str_contains($coll, 'mail_tpl_prenotification($org, $invoice, $mandate, $amountCents, $dueDate)') && !str_contains($coll, "mail_layout('Vorabankündigung")) ? $ok('_send_prenotification() nutzt die zentrale Vorlage') : $bad('collections.php baut die Vorlage noch selbst');
$mc = file_get_contents($root . '/php-ionos/bin/mail-check.php');
(str_contains($mc, "isset(\$opts['vorabankuendigung'])") && str_contains($mc, 'mail_prenotification_sample(') && str_contains($mc, "'MUSTER ' . \$tpl['subject']")) ? $ok('bin/mail-check.php --vorabankuendigung mit MUSTER-Betreff') : $bad('mail-check ohne Musterversand');
$as = file_get_contents($root . '/php-ionos/admin-system.php');
(str_contains($as, "\$action === 'test_prenotification'") && str_contains($as, "\$ownEmail = (string)(\$ctx['email']") && str_contains($as, "mail_send(\$ownEmail, 'MUSTER '") && !str_contains($as, "\$_POST['prenotification_to']")) ? $ok('admin-system: Muster nur an eigene Adresse') : $bad('admin-system Musterversand');
foreach ([$w, $c, $d, $w0, $c2, $pn] as $tpl) { str_contains($tpl['html'], 'HRB 104291') ? $ok('Pflichtangaben in Vorlage: ' . $tpl['subject']) : $bad('Pflichtangaben fehlen: ' . $tpl['subject']); }
echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
