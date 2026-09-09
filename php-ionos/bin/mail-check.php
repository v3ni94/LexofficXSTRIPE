<?php
/**
 * Mailversand pruefen (nur lesend) und optional eine Testmail senden.
 *
 *   php bin/mail-check.php                    Konfiguration und Betriebspfad anzeigen (Passwoerter nie im Klartext)
 *   php bin/mail-check.php --send=ADRESSE     Testmail im CI direkt ueber den Transport senden (ohne Warteschlange)
 *   php bin/mail-check.php --vorabankuendigung --send=ADRESSE
 *                                             Muster der Vorabankuendigung (Musterrechnung, Mustermandat) an ADRESSE senden
 *   php bin/mail-check.php --vorabankuendigung --html=DATEI
 *                                             HTML-Fassung des Musters in DATEI schreiben (Vorschau ohne Versand, auch ohne mail.enabled)
 *
 * Exit 0 = Mailversand aktiv und (bei --send) erfolgreich uebergeben; 1 = nicht aktiv oder Fehler.
 * Auf dem VPS im php-Container ausfuehren (docker compose ... exec -T php php bin/mail-check.php), weil dort
 * shared/config.php eingebunden ist. Nach Aenderungen an config.php: worker-mail neu starten (liest die
 * Konfiguration nur beim Start).
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';
require_once dirname(__DIR__) . '/app/mailer.php';

$opts = cli_opts($argv);
$cfg = (array)config('mail', []);
$smtp = (array)($cfg['smtp'] ?? []);
$queueOn = false;
try {
    require_once dirname(__DIR__) . '/app/queue.php';
    $queueOn = queue_enabled();
} catch (Throwable $e) {
}
$mask = static fn(?string $v): string => $v === null || $v === '' ? '(leer)' : (mb_strlen($v) <= 4 ? '****' : mb_substr($v, 0, 2) . str_repeat('*', max(4, mb_strlen($v) - 4)) . mb_substr($v, -2));

cli_out('Mailversand: ' . (mail_enabled() ? 'AKTIV' : 'NICHT AKTIV (mail.enabled = false)'));
cli_out('Transport:   ' . (string)($cfg['transport'] ?? '(leer)'));
cli_out('Absender:    ' . (string)($cfg['from_name'] ?? '') . ' <' . (string)($cfg['from_address'] ?? '(leer)') . '>');
cli_out('Antwort an:  ' . (string)($cfg['reply_to'] ?? '(leer)'));
cli_out('SMTP:        ' . (string)($smtp['host'] ?? '(leer)') . ':' . (string)($smtp['port'] ?? '') . ' ' . (string)($smtp['encryption'] ?? ''));
cli_out('SMTP-Nutzer: ' . $mask((string)($smtp['user'] ?? '')));
cli_out('SMTP-Passwort gesetzt: ' . (empty($smtp['pass']) || $smtp['pass'] === 'HIER-POSTFACH-PASSWORT' ? 'NEIN' : 'ja'));
cli_out('Betriebspfad: ' . ($queueOn ? 'Warteschlange (Jobtyp mail, Container worker-mail)' : 'direkt beim Aufruf'));
cli_out('Wirkung bei NICHT AKTIV: keine Bestaetigungs-, Willkommens-, Sicherheits- und Vorabankuendigungsmails; Vormerkungen und Registrierungen werden gespeichert und als wartend markiert (Nachsendung durch die Wartung nach dem Einschalten); Statusseite zeigt E-Mail als unbekannt.');
$replyTo = trim((string)($cfg['reply_to'] ?? ''));
if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    cli_out('WARNUNG: reply_to ist keine gueltige E-Mail-Adresse (' . $replyTo . '); Antworten der Empfaenger gingen ins Leere. In shared/config.php korrigieren.');
}
$fromAddr = trim((string)($cfg['from_address'] ?? ''));
if ($fromAddr !== '' && !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
    cli_out('WARNUNG: from_address ist keine gueltige E-Mail-Adresse (' . $fromAddr . ').');
}

$sample = isset($opts['vorabankuendigung']);
$htmlOut = (string)($opts['html'] ?? '');
if ($sample && $htmlOut !== '') {
    // Vorschau ohne Versand: braucht weder mail.enabled noch Datenbank.
    $d = mail_prenotification_sample(['name' => 'Muster GmbH']);
    $tpl = mail_tpl_prenotification($d['org'], $d['invoice'], $d['mandate'], $d['amount_cents'], $d['due_date'], 'Musterversand ohne echten Einzug.');
    if (@file_put_contents($htmlOut, $tpl['html']) === false) {
        cli_out('Vorschau konnte nicht geschrieben werden: ' . $htmlOut);
        exit(1);
    }
    cli_out('Vorschau der Vorabankuendigung geschrieben: ' . $htmlOut . ' (Betreff: ' . $tpl['subject'] . ')');
    if (!isset($opts['send'])) {
        exit(0);
    }
}
if (!mail_enabled()) {
    exit(1);
}
$to = (string)($opts['send'] ?? '');
if ($to === '') {
    exit(0);
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    cli_out('Ungueltige Testadresse.');
    exit(1);
}
if ($sample) {
    // Muster der Vorabankuendigung: Musterrechnung und Mustermandat, kein echter Kunde, kein Einzug, kein Eintrag in
    // payment_collections. Firmenname der Musterfirma, damit kein echter Kunde verwechselt werden kann.
    $d = mail_prenotification_sample(['name' => 'Muster GmbH']);
    $tpl = mail_tpl_prenotification($d['org'], $d['invoice'], $d['mandate'], $d['amount_cents'], $d['due_date'],
        'Musterversand aus bin/mail-check.php vom ' . date('d.m.Y H:i') . ' Uhr, kein echter Einzug.');
    $subject = 'MUSTER ' . $tpl['subject'];
    $layout = ['text' => $tpl['text'], 'html' => $tpl['html']];
} else {
    $subject = 'Testnachricht von ' . mail_product_name();
    $layout = mail_layout($subject, [
        'Diese Testnachricht bestaetigt, dass der Mailversand von ' . mail_product_name() . ' konfiguriert ist und Nachrichten im Corporate Design zugestellt werden.',
        'Gesendet am ' . date('d.m.Y') . ' um ' . date('H:i') . ' Uhr ueber den Transport ' . (string)($cfg['transport'] ?? '') . '.',
    ], ['label' => 'Zur Anwendung', 'url' => app_base_url() . '/login.php'], 'Diese Nachricht wurde von einem Administrator ausgeloest und dient nur der Pruefung.');
}
try {
    $ok = mail_send_direct($to, $subject, $layout['text'], $layout['html']);
} catch (Throwable $e) {
    cli_out('Versand fehlgeschlagen: ' . get_class($e) . ' ' . $e->getMessage());
    exit(1);
}
cli_out($ok ? ($sample ? 'Muster der Vorabankuendigung' : 'Testmail') . ' an ' . mail_addr_ref($to) . ' uebergeben. Bitte Posteingang und Spam-Ordner pruefen.' : 'Versand fehlgeschlagen (siehe mail.log beziehungsweise Fehlerprotokoll).');
exit($ok ? 0 : 1);
