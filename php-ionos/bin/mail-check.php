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
 *   php bin/mail-check.php --zustellbarkeit   SPF, DKIM, DMARC der Absenderdomain per DNS pruefen und die Absenderkonsistenz
 *                                             (From, SMTP-Postfach, Reply-To) bewerten; kein Versand, kein Zugriff auf Stripe
 *                                             oder Lexware. Auf dem VPS im php-Container ausfuehren (dort ist DNS erreichbar).
 *                                             Zusatz --dkim-selektoren=s1-ionos,s2-ionos ergaenzt die Liste der geprueften Selektoren.
 *   php bin/mail-check.php --marketing        Versandprofil des Marketingmoduls (config mail_marketing, Amazon SES) anzeigen:
 *                                             aktiv, Absender, SMTP-Host (Region), Zugangsdaten gesetzt, Webhook-Token, Ratenbegrenzung.
 *   php bin/mail-check.php --marketing --send=ADRESSE
 *                                             Testnachricht ueber das MARKETINGprofil senden (Betreff mit Vorsatz TEST, Kopfzeilen
 *                                             List-Unsubscribe und Precedence: bulk); zaehlt zur Tagesgrenze. In der SES-Sandbox nur
 *                                             an verifizierte Adressen.
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
function mail_dns_domain_of_safe(string $a): string { $p = strrpos($a, '@'); return $p === false ? $a : substr($a, $p + 1); }
if (isset($opts['marketing'])) {
    // Marketingprofil (4.67): eigener Absender und SMTP-Weg des Marketingmoduls, getrennt vom Systemversand.
    require_once dirname(__DIR__) . '/app/marketing.php';
    $mc = mail_profile_config('marketing');
    $ms = (array)($mc['smtp'] ?? []);
    $st = marketing_profile_status();
    $maskM = static fn(?string $v): string => $v === null || $v === '' ? '(leer)' : (str_contains((string)$v, 'HIER-') ? '(Platzhalter)' : (mb_strlen($v) <= 4 ? '****' : mb_substr($v, 0, 2) . str_repeat('*', max(4, mb_strlen($v) - 4)) . mb_substr($v, -2)));
    cli_out('Marketingprofil (mail_marketing): ' . ($st['enabled'] ? 'AKTIV' : 'NICHT AKTIV (enabled fehlt oder false)'));
    cli_out('Transport:   ' . $st['transport'] . ($st['ses'] ? ' (Amazon SES)' : ''));
    cli_out('Absender:    ' . $st['from_name'] . ' <' . $st['from'] . '>');
    cli_out('Antwort an:  ' . ($st['reply_to'] !== '' ? $st['reply_to'] : '(leer)') . ' (Header Reply-To: ' . (mail_reply_to_effective($mc, $st['from']) ?? 'nicht gesetzt') . ')');
    cli_out('SMTP:        ' . ($st['smtp_host'] !== '' ? $st['smtp_host'] : '(leer)') . ':' . (string)($ms['port'] ?? '') . ' ' . (string)($ms['encryption'] ?? ''));
    cli_out('SMTP-Nutzer: ' . $maskM((string)($ms['user'] ?? '')) . ', Passwort: ' . (empty($ms['pass']) || str_contains((string)$ms['pass'], 'HIER-') ? 'FEHLT' : 'gesetzt'));
    cli_out('Webhook-Token: ' . ($st['webhook_ok'] ? 'gesetzt' : 'FEHLT oder Platzhalter') . ' (marketing-webhook.php?token=...)');
    try {
        $rates = marketing_rates();
        cli_out('Ratenbegrenzung: ' . $rates['per_second'] . ' je Sekunde, ' . $rates['per_day'] . ' je 24 Stunden; letzte 24 h: ' . marketing_sent_last_24h() . ' Nachrichten');
    } catch (Throwable $e) {
        cli_out('Ratenbegrenzung: nicht lesbar (Migration 034 fehlt?): ' . $e->getMessage());
    }
    if (!$st['enabled'] || !$st['smtp_ok']) {
        cli_out('Ergebnis: Profil nicht einsatzbereit. Block mail_marketing in shared/config.php pruefen (docs/marketing.md), danach scripts/restart-workers.sh.');
        exit(1);
    }
    $toM = (string)($opts['send'] ?? '');
    if ($toM === '') {
        cli_out('Ergebnis: Profil einsatzbereit. Testversand mit --marketing --send=ADRESSE.');
        exit(0);
    }
    if (!filter_var($toM, FILTER_VALIDATE_EMAIL)) {
        cli_out('Ungueltige Testadresse.');
        exit(1);
    }
    $unsub = app_base_url() . '/abmelden.php?t=TEST';
    $layoutM = mail_layout('Testnachricht des Marketingprofils', [
        'Diese Testnachricht bestaetigt, dass das Versandprofil mail_marketing von ' . mail_product_name() . ' konfiguriert ist.',
        'Gesendet am ' . date('d.m.Y') . ' um ' . date('H:i') . ' Uhr ueber ' . $st['smtp_host'] . '.',
    ], null, 'Diese Nachricht wurde von einem Administrator ausgeloest und dient nur der Pruefung.', ['label' => 'Keine weiteren Nachrichten', 'url' => $unsub]);
    try {
        $okM = mail_send_direct($toM, 'TEST: Marketingprofil von ' . mail_product_name(), $layoutM['text'], $layoutM['html'], ['profile' => 'marketing', 'unsubscribe_url' => $unsub]);
    } catch (Throwable $e) {
        cli_out('Versand fehlgeschlagen: ' . get_class($e) . ' ' . $e->getMessage());
        exit(1);
    }
    if ($okM) {
        cli_out('Testnachricht an ' . $toM . ' uebergeben. Im Postfach "Original anzeigen": SPF und DKIM PASS fuer ' . mail_dns_domain_of_safe($st['from']) . ', Kopfzeilen List-Unsubscribe und Precedence: bulk.');
        exit(0);
    }
    $err = mail_last_error();
    cli_out('Versand fehlgeschlagen: ' . (string)($err['text'] ?? 'Versandweg hat die Nachricht nicht angenommen') . ' (Art: ' . (string)($err['kind'] ?? '?') . ').');
    cli_out('Haeufige Ursachen bei SES: Absenderdomain nicht verifiziert (554 Message rejected: Email address is not verified), Sandbox ohne verifizierten Empfaenger, falsche Region im SMTP-Host, SMTP-Zugangsdaten statt IAM-Schluessel.');
    exit(1);
}
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
$replyEff = mail_reply_to_effective($cfg, trim((string)($cfg['from_address'] ?? '')));
cli_out('Antwort an:  ' . (string)($cfg['reply_to'] ?? '(leer)') . ' (Header Reply-To: ' . ($replyEff === null
    ? (trim((string)($cfg['reply_to'] ?? '')) === '' || strcasecmp(trim((string)($cfg['reply_to'] ?? '')), trim((string)($cfg['from_address'] ?? ''))) === 0
        ? 'nicht gesetzt, Antworten gehen an den Absender' : 'NICHT GESETZT, Adresse ungueltig oder auf fremder Domain; Antworten gehen an den Absender')
    : $replyEff) . ')');
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

if (isset($opts['zustellbarkeit'])) {
    // Zustellbarkeit (Audit 11.09.2026): notwendige DNS-Voraussetzungen und Absenderkonsistenz. Aussagegrenze siehe
    // app/mail_dns.php: Ob wirklich signiert wird, zeigt nur der Kopf einer empfangenen Nachricht.
    require_once dirname(__DIR__) . '/app/mail_dns.php';
    $transport = (string)($cfg['transport'] ?? 'mail');
    $fromAddr = trim((string)($cfg['from_address'] ?? ''));
    $domain = mail_dns_domain_of($fromAddr);
    $ergebnisse = mail_dns_alignment($fromAddr, (string)($smtp['user'] ?? ''), $cfg['reply_to'] ?? null, $transport);
    if ($domain === '') {
        cli_out(mail_dns_bericht($ergebnisse));
        exit(1);
    }
    $txtOf = static function (string $name): array {
        $out = [];
        foreach ((array)(@dns_get_record($name, DNS_TXT) ?: []) as $r) {
            if (isset($r['txt'])) {
                $out[] = (string)$r['txt'];
            } elseif (isset($r['entries']) && is_array($r['entries'])) {
                $out[] = implode('', $r['entries']);
            }
        }
        return $out;
    };
    $cnameOf = static function (string $name): string {
        foreach ((array)(@dns_get_record($name, DNS_CNAME) ?: []) as $r) {
            if (!empty($r['target'])) {
                return (string)$r['target'];
            }
        }
        return '';
    };
    $ergebnisse[] = mail_dns_spf($txtOf($domain), $transport, (string)($smtp['host'] ?? ''));
    $dmarcName = '_dmarc.' . $domain;
    $ergebnisse[] = mail_dns_dmarc($txtOf($dmarcName), $cnameOf($dmarcName) !== '', $domain);
    $selektoren = ['s1-ionos', 's2-ionos', 'default', 'mail', 'dkim', 'selector1', 'selector2', 's1', 's2', 'k1', 'smtp'];
    foreach (array_filter(array_map('trim', explode(',', (string)($opts['dkim-selektoren'] ?? '')))) as $s) {
        $selektoren[] = $s;
    }
    $treffer = [];
    foreach (array_unique($selektoren) as $sel) {
        $name = $sel . '._domainkey.' . $domain;
        $txt = $txtOf($name);
        $cname = $cnameOf($name);
        if ($txt || $cname !== '') {
            $treffer[$sel] = ['txt' => $txt, 'cname' => $cname];
        }
    }
    $ergebnisse[] = mail_dns_dkim($treffer);
    cli_out('Zustellbarkeit fuer Absender ' . $fromAddr . ' (Transport ' . $transport . ($transport === 'smtp' ? ' ueber ' . (string)($smtp['host'] ?? '?') : '') . ')');
    echo mail_dns_bericht($ergebnisse), "\n";
    $gesamt = mail_dns_gesamtstatus($ergebnisse);
    cli_out('Gesamt: ' . strtoupper($gesamt) . '. Abschliessender Nachweis: Testmail an ein Gmail-Postfach senden (--send=ADRESSE) und dort'
        . ' "Original anzeigen" oeffnen; SPF, DKIM und DMARC muessen jeweils PASS zeigen, header.d muss ' . $domain . ' sein.');
    exit(in_array($gesamt, ['fehler', 'unklar'], true) ? 1 : 0);
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
