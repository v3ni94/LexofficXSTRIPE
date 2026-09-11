<?php
/**
 * Prueft die E-Mail-Gestaltung (app/mailer.php) gegen das CI der Mueller Holding AG: Pflichtangaben nach § 80 AktG,
 * Farben (Gold #E3AC48, Anthrazit #2E2D2E, Beige #FBF6EC), Wortmarke, keine Gedankenstriche, Text- und HTML-Fassung,
 * neue Vorlagen (Willkommen, Vormerkung bestaetigt) mit Links, Kopfzeilen der Zustellbarkeit (4.61: Reply-To nur auf der
 * Absenderdomain, Auto-Submitted, List-Unsubscribe mit One-Click nur fuer Nachrichten mit Abmeldeadresse, Weitergabe der
 * Optionen ueber den Warteschlangen-Payload und Transport log). Ohne Datenbank, ohne Netz.
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
echo "4) Kopfzeilen der Zustellbarkeit (4.61)\n";
$cfgMail = ['from_address' => 'kontakt@smart-einzug.de', 'from_name' => 'SmartEinzug', 'reply_to' => 'kontakt@smart-einzug.de'];
$hdr = static fn(array $cfg, array $opt = [], bool $plain = false): array => mail_header_lines($cfg, 'text/plain; charset=UTF-8', $plain, $opt);
$has = static fn(array $lines, string $prefix): bool => count(array_filter($lines, static fn($l) => str_starts_with($l, $prefix))) === 1;
$h = $hdr($cfgMail);
(!$has($h, 'Reply-To:')) ? $ok('Reply-To identisch mit Absender: kein eigener Header') : $bad('Reply-To redundant gesetzt');
$has($h, 'From: SmartEinzug <kontakt@smart-einzug.de>') ? $ok('From mit Anzeigename und Adresse') : $bad('From: ' . implode(' | ', $h));
$has($h, 'Auto-Submitted: auto-generated') ? $ok('Auto-Submitted: auto-generated auf jeder Nachricht') : $bad('Auto-Submitted fehlt');
(!$has($h, 'List-Unsubscribe:') && !$has($h, 'List-Unsubscribe-Post:')) ? $ok('ohne Abmeldeadresse kein List-Unsubscribe') : $bad('List-Unsubscribe ohne Anlass');
($has($h, 'Message-ID: <') && $has($h, 'Date: ') && $has($h, 'MIME-Version: 1.0') && $has($h, 'Content-Type: text/plain')) ? $ok('Message-ID, Date, MIME-Version, Content-Type') : $bad('Grundkopfzeilen');
$h = $hdr($cfgMail + [], [], true);
$has($h, 'Content-Transfer-Encoding: quoted-printable') ? $ok('reine Textmail traegt Content-Transfer-Encoding') : $bad('CTE fehlt');
$h = $hdr(['from_address' => 'kontakt@smart-einzug.de', 'reply_to' => 'support@smart-einzug.de']);
$has($h, 'Reply-To: support@smart-einzug.de') ? $ok('Reply-To auf derselben Domain wird gesetzt') : $bad('Reply-To gleiche Domain fehlt');
$h = $hdr(['from_address' => 'kontakt@smart-einzug.de', 'reply_to' => 'hilfe@service.smart-einzug.de']);
$has($h, 'Reply-To: hilfe@service.smart-einzug.de') ? $ok('Reply-To auf Subdomain der Absenderdomain wird gesetzt') : $bad('Reply-To Subdomain fehlt');
$h = $hdr(['from_address' => 'kontakt@smart-einzug.de', 'reply_to' => 'info@mueller-holding.ag']);
(!$has($h, 'Reply-To:')) ? $ok('Reply-To auf fremder Domain wird NICHT gesetzt (Alignment, Vorgabe bis 4.60)') : $bad('Reply-To fremde Domain gesetzt');
$h = $hdr(['from_address' => 'kontakt@smart-einzug.de', 'reply_to' => 'kein-postfach']);
(!$has($h, 'Reply-To:')) ? $ok('ungueltiges reply_to wird ignoriert') : $bad('ungueltiges Reply-To gesetzt');
$h = $hdr(['from_address' => 'kontakt@smart-einzug.de', 'reply_to' => "a@smart-einzug.de\r\nBcc: x@evil.test"]);
(!in_array('Bcc: x@evil.test', $h, true) && $has($h, 'Reply-To: a@smart-einzug.deBcc: x@evil.test') === false && count(array_filter($h, static fn($l) => str_contains($l, 'evil'))) === 0) ? $ok('Header-Injection ueber reply_to unmoeglich') : $bad('Header-Injection: ' . implode(' | ', $h));
$u = 'https://app.smart-einzug.de/vormerken.php?abmelden=' . str_repeat('ab', 32);
$h = $hdr($cfgMail, ['unsubscribe_url' => $u]);
($has($h, 'List-Unsubscribe: <' . $u . '>') && $has($h, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click')) ? $ok('List-Unsubscribe und List-Unsubscribe-Post (One-Click, RFC 8058) mit Abmeldeadresse') : $bad('List-Unsubscribe: ' . implode(' | ', $h));
$idxLU = array_search('List-Unsubscribe: <' . $u . '>', $h, true); $idxCT = null;
foreach ($h as $i => $l) { if (str_starts_with($l, 'Content-Type:')) { $idxCT = $i; } }
($idxLU !== false && $idxCT !== null && $idxLU < $idxCT) ? $ok('Listenkopfzeilen stehen vor Content-Type') : $bad('Reihenfolge Kopfzeilen');
foreach (['javascript:alert(1)', 'mailto:x@y.test', "https://a.test/x\r\nBcc: y", 'https://a.test/x y', 'ftp://a.test/', ''] as $badUrl) {
    $h = $hdr($cfgMail, ['unsubscribe_url' => $badUrl]);
    (!$has($h, 'List-Unsubscribe:') && !$has($h, 'List-Unsubscribe-Post:')) ? $ok('unzulaessige Abmeldeadresse verworfen: ' . json_encode($badUrl)) : $bad('Abmeldeadresse angenommen: ' . json_encode($badUrl));
}
$norm = mail_options_normalize(['unsubscribe_url' => $u, 'headers' => ['Bcc: x'], 'fremd' => 1]);
($norm === ['unsubscribe_url' => $u]) ? $ok('mail_options_normalize verwirft unbekannte Optionen') : $bad('Optionen: ' . json_encode($norm));
$pl = mail_queue_payload('k@x.test', 'S', 'T', null, []);
(!array_key_exists('options', $pl)) ? $ok('Warteschlangen-Payload ohne Optionen unveraendert (Altjobs)') : $bad('Payload traegt leere Optionen');
$pl = mail_queue_payload('k@x.test', 'S', 'T', '<p>H</p>', ['unsubscribe_url' => $u]);
(($pl['options']['unsubscribe_url'] ?? null) === $u && $pl['html'] === '<p>H</p>') ? $ok('Warteschlangen-Payload fuehrt unsubscribe_url mit') : $bad('Payload: ' . json_encode($pl));
$jobs = file_get_contents($root . '/php-ionos/app/jobs.php');
(str_contains($jobs, "is_array(\$p['options'] ?? null) ? \$p['options'] : []")) ? $ok('job_mail reicht Optionen an mail_send_direct durch') : $bad('job_mail ohne Optionen');
$int = file_get_contents($root . '/php-ionos/app/interest.php');
(substr_count($int, "['unsubscribe_url' => \$unsubscribeUrl]") === 3) ? $ok('alle drei Vormerkungsmails (Bestaetigung, Nachsendung, bestaetigt) mit Abmeldeadresse') : $bad('interest.php: ' . substr_count($int, "['unsubscribe_url' => \$unsubscribeUrl]") . ' Aufrufe');
$auth = file_get_contents($root . '/php-ionos/app/auth.php');
(!str_contains($auth, 'unsubscribe_url')) ? $ok('Willkommens- und Sicherheitsmails ohne List-Unsubscribe (keine Abmeldung moeglich)') : $bad('auth.php mit unsubscribe_url');
require_once $root . '/php-ionos/app/interest.php';
(interest_is_one_click_unsubscribe('POST', ['List-Unsubscribe' => 'One-Click'])) ? $ok('One-Click-POST wird erkannt') : $bad('One-Click nicht erkannt');
(!interest_is_one_click_unsubscribe('GET', ['List-Unsubscribe' => 'One-Click']) && !interest_is_one_click_unsubscribe('POST', []) && !interest_is_one_click_unsubscribe('POST', ['List-Unsubscribe' => 'one-click'])) ? $ok('One-Click nur per POST mit exaktem Feldwert') : $bad('One-Click zu weit');
$vm = file_get_contents($root . '/php-ionos/vormerken.php');
(str_contains($vm, "interest_is_one_click_unsubscribe(\$method, \$_POST)") && str_contains($vm, "\$aktion = 'abmelden';")) ? $ok('vormerken.php fuehrt One-Click wie „Jetzt abmelden“ aus') : $bad('vormerken.php ohne One-Click');
// Ende-zu-Ende ueber Transport log: echte Kopfzeilen im Protokoll
$logFile = sys_get_temp_dir() . '/mail-ci-check-' . bin2hex(random_bytes(4)) . '.log';
$GLOBALS['config']['mail'] = ['enabled' => true, 'transport' => 'log', 'log_file' => $logFile, 'from_address' => 'kontakt@smart-einzug.de', 'from_name' => 'SmartEinzug', 'reply_to' => 'info@mueller-holding.ag'];
$sent = mail_send_direct('empfaenger@example.test', 'Test Ümlaut', "Textfassung\n", '<p>HTML</p>', ['unsubscribe_url' => $u]);
$log = $sent ? (string)@file_get_contents($logFile) : '';
@unlink($logFile);
($sent && str_contains($log, "\r\nList-Unsubscribe: <" . $u . ">\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n") && str_contains($log, "\r\nAuto-Submitted: auto-generated\r\n") && !str_contains($log, 'Reply-To:')) ? $ok('Transport log: Kopfzeilen vollstaendig, fremdes Reply-To unterdrueckt') : $bad('Transport log: ' . substr($log, 0, 600));
$sent = mail_send_direct('empfaenger@example.test', 'Ohne Abmeldung', "Text\n");
$log = $sent ? (string)@file_get_contents($logFile) : '';
@unlink($logFile);
($sent && !str_contains($log, 'List-Unsubscribe') && str_contains($log, 'Content-Transfer-Encoding: quoted-printable')) ? $ok('Transport log: Textmail ohne Abmeldeadresse ohne Listenkopfzeilen') : $bad('Transport log Textmail');
unset($GLOBALS['config']['mail']['enabled']);
echo "\nErgebnis: $pass bestanden, $fail fehlgeschlagen\n";
exit($fail === 0 ? 0 : 1);
