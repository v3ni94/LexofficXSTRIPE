<?php
/**
 * Mailversand pruefen (nur lesend) und optional eine Testmail senden.
 *
 *   php bin/mail-check.php                    Konfiguration und Betriebspfad anzeigen (Passwoerter nie im Klartext)
 *   php bin/mail-check.php --send=ADRESSE     Testmail im CI direkt ueber den Transport senden (ohne Warteschlange)
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
cli_out('Wirkung bei NICHT AKTIV: keine Bestaetigungs-, Willkommens-, Sicherheits- und Vorabankuendigungsmails; vormerken.php antwortet 503; Statusseite zeigt E-Mail als unbekannt.');

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
$layout = mail_layout('Testnachricht von ' . mail_product_name(), [
    'Diese Testnachricht bestaetigt, dass der Mailversand von ' . mail_product_name() . ' konfiguriert ist und Nachrichten im Corporate Design zugestellt werden.',
    'Gesendet am ' . date('d.m.Y') . ' um ' . date('H:i') . ' Uhr ueber den Transport ' . (string)($cfg['transport'] ?? '') . '.',
], ['label' => 'Zur Anwendung', 'url' => app_base_url() . '/login.php'], 'Diese Nachricht wurde von einem Administrator ausgeloest und dient nur der Pruefung.');
try {
    $ok = mail_send_direct($to, 'Testnachricht von ' . mail_product_name(), $layout['text'], $layout['html']);
} catch (Throwable $e) {
    cli_out('Versand fehlgeschlagen: ' . get_class($e) . ' ' . $e->getMessage());
    exit(1);
}
cli_out($ok ? 'Testmail an ' . mail_addr_ref($to) . ' uebergeben. Bitte Posteingang und Spam-Ordner pruefen.' : 'Versand fehlgeschlagen (siehe mail.log beziehungsweise Fehlerprotokoll).');
exit($ok ? 0 : 1);
