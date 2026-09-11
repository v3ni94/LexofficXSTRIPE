<?php
/**
 * E-Mail-Versand (Einladungen, Verifizierung, Sicherheitshinweise).
 *
 * Nutzt ausschließlich die PHP-Standardfunktion mail() (Transport 'mail')
 * oder schreibt die Mail zu Test- und Entwicklungszwecken in eine Logdatei
 * (Transport 'log'). Keine externen Bibliotheken, kein Composer, keine
 * Datenbankzugriffe. Konfiguration über config('mail'), siehe
 * config.example.php.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Mailversand grundsätzlich aktiv (config('mail')['enabled'] === true)? */
function mail_enabled(): bool
{
    $cfg = config('mail');
    return is_array($cfg) && !empty($cfg['enabled']);
}

/** Entfernt \r und \n aus einem Header-Wert (Schutz vor Header-Injection). */
function mail_sanitize_header(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

/** Produktname für Vorlagen (Standard SmartEinzug; product_name() aus bootstrap.php, falls geladen). */
function mail_product_name(): string
{
    if (function_exists('product_name')) {
        return product_name();
    }
    $name = trim((string)config('product_name', ''));
    return $name !== '' ? $name : 'SmartEinzug';
}

/**
 * Kopfzeilenwert (Betreff, Anzeigename) nach RFC 2047 kodieren (4.64). Reiner ASCII-Text bleibt unveraendert. Sonst wird der
 * Text an Wortgrenzen in Base64-Teile zerlegt, jeder Teil hoechstens 75 Zeichen lang, gefaltet mit CRLF und Leerzeichen.
 * Anlass: mb_encode_mimeheader() (Q-Kodierung) teilte den Betreff „Bitte E-Mail-Adresse bestaetigen“ mitten im Wort in zwei
 * kodierte Teile („best=C3=A4tige“ und „n“); korrekt dekodierbar, aber unsauber und ein vermeidbares Merkmal fuer Filter.
 * Multibyte-Zeichen werden nie getrennt (Zerlegung nach Woertern, ein zu langes Wort nach Zeichen).
 */
function mail_encode_header_value(string $value): string
{
    $value = mail_sanitize_header($value);
    if ($value === '' || !preg_match('/[^\x20-\x7E]/', $value)) {
        return $value;
    }
    $maxBytes = 45; // base64(45 Byte) = 60 Zeichen + 12 Zeichen Rahmen "=?UTF-8?B?" und "?=" = 72 <= 75
    $chunks = [];
    $current = '';
    foreach (preg_split('/(?<= )/u', $value) ?: [] as $word) {
        if ($current !== '' && strlen($current) + strlen($word) > $maxBytes) {
            $chunks[] = $current;
            $current = '';
        }
        while (strlen($word) > $maxBytes) {
            // ueberlanges Wort zeichenweise fuellen, nie innerhalb eines Multibyte-Zeichens trennen
            $take = '';
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                if (strlen($current) + strlen($take) + strlen($ch) > $maxBytes) {
                    break;
                }
                $take .= $ch;
            }
            $chunks[] = $current . $take;
            $current = '';
            $word = substr($word, strlen($take));
        }
        $current .= $word;
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return implode("\r\n ", array_map(static fn(string $c): string => '=?UTF-8?B?' . base64_encode($c) . '?=', $chunks));
}

/** Hostname für die Message-ID, abgeleitet aus der Basisadresse der Anwendung. */
function mail_message_id_host(): string
{
    $base = function_exists('app_base_url') ? app_base_url() : (string)config('base_url', '');
    $host = parse_url($base, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : 'localhost';
}

/** Erzeugt eine uuid-ähnliche, zufällige Message-ID inkl. spitzer Klammern. */
function mail_generate_message_id(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    return sprintf('<%s@%s>', $uuid, mail_message_id_host());
}

/**
 * Baut den MIME-Body. Ohne $htmlBody wird nur ein einzelner
 * text/plain-Teil erzeugt (kein multipart); mit $htmlBody ein
 * multipart/alternative mit Text- und HTML-Teil. Gibt [Content-Type-Wert
 * (ohne Header-Namen), Body] zurück.
 */
function mail_build_body(string $textBody, ?string $htmlBody): array
{
    if ($htmlBody === null) {
        return [
            'text/plain; charset=UTF-8',
            quoted_printable_encode($textBody),
        ];
    }

    $boundary = 'b_' . bin2hex(random_bytes(16));
    $parts = [];
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/plain; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: quoted-printable';
    $parts[] = '';
    $parts[] = quoted_printable_encode($textBody);
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/html; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: quoted-printable';
    $parts[] = '';
    $parts[] = quoted_printable_encode($htmlBody);
    $parts[] = '--' . $boundary . '--';

    return [
        'multipart/alternative; boundary="' . $boundary . '"',
        implode("\r\n", $parts),
    ];
}

/**
 * Versendet eine E-Mail über den in config('mail') hinterlegten Transport.
 * Liefert false ohne Versandversuch, wenn der Mailversand nicht aktiviert
 * ist oder die Empfängeradresse ungültig ist.
 */
/**
 * E-Mail versenden. Ist die Warteschlange aktiv (Feature queue) und läuft dieser Aufruf nicht selbst im
 * Worker, wird die Nachricht als Job eingereiht und sofort true geliefert (Webanfragen warten nicht auf
 * SMTP). Andernfalls direkte Übergabe an den Versandweg.
 */
/**
 * Wie mail_send(), bevorzugt aber auch innerhalb eines Workers die Warteschlange (Pool mail mit Ratenbegrenzung und
 * Circuit Breaker) statt des Direktversands. Fuer Nachsendungen aus dem Wartungsjob gedacht.
 */
function mail_send_queued(string $to, string $subject, string $textBody, ?string $htmlBody = null, array $options = []): bool
{
    if (!mail_enabled()) {
        return false;
    }
    try {
        require_once __DIR__ . '/queue.php';
        if (queue_enabled()) {
            $to = mail_sanitize_header($to);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            queue_push('mail', mail_queue_payload($to, $subject, $textBody, $htmlBody, $options), ['priority' => 'normal']);
            return true;
        }
    } catch (Throwable $e) {
    }
    return mail_send($to, $subject, $textBody, $htmlBody, $options);
}

/**
 * @param array $options Versandoptionen, siehe mail_header_lines(): 'unsubscribe_url' (Abmeldeadresse fuer die Kopfzeilen
 *                       List-Unsubscribe und List-Unsubscribe-Post, nur fuer Nachrichten mit eigener Abmeldung wie die
 *                       Vormerkung). Werden bei Warteschlangenbetrieb im Payload mitgefuehrt (job_mail).
 */
function mail_send(string $to, string $subject, string $textBody, ?string $htmlBody = null, array $options = []): bool
{
    if (!mail_enabled()) {
        return false;
    }
    if (!defined('IN_WORKER')) {
        try {
            require_once __DIR__ . '/queue.php';
            if (queue_enabled()) {
                $to = mail_sanitize_header($to);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    return false;
                }
                queue_push('mail', mail_queue_payload($to, $subject, $textBody, $htmlBody, $options), ['priority' => 'normal']);
                return true;
            }
        } catch (Throwable $e) {
            // Queue nicht verfügbar: direkt versenden
        }
    }
    return mail_send_direct($to, $subject, $textBody, $htmlBody, $options);
}

/** Payload des Jobtyps mail; Optionen nur mitfuehren, wenn gesetzt (kompakter Payload, unveraenderte Altjobs). */
function mail_queue_payload(string $to, string $subject, string $textBody, ?string $htmlBody, array $options): array
{
    $payload = ['to' => $to, 'subject' => mb_substr($subject, 0, 255), 'text' => $textBody, 'html' => $htmlBody];
    $options = mail_options_normalize($options);
    if ($options !== []) {
        $payload['options'] = $options;
    }
    return $payload;
}

/** Bekannte Versandoptionen bereinigen; unbekannte Schluessel werden verworfen (kein Durchreichen beliebiger Kopfzeilen). */
function mail_options_normalize(array $options): array
{
    $out = [];
    $url = isset($options['unsubscribe_url']) ? mail_sanitize_header((string)$options['unsubscribe_url']) : '';
    if ($url !== '' && preg_match('~^https?://[^\s<>"]+$~', $url) === 1) {
        $out['unsubscribe_url'] = $url;
    }
    if (($options['profile'] ?? '') === 'marketing') {
        $out['profile'] = 'marketing';
    }
    return $out;
}

/**
 * Versandprofil (seit 4.63): 'system' = config('mail') fuer alle Nachrichten der Anwendung (Bestaetigungen, Sicherheit,
 * Vorabankuendigung); 'marketing' = config('mail_marketing') fuer Werbenachrichten des Marketingmoduls (eigener Absender
 * auf eigener Subdomain, eigener SMTP-Weg, eigene Reputation). Fehlt der Block, ist das Profil nicht aktiv.
 */
function mail_profile_config(string $profile): array
{
    if ($profile === 'marketing') {
        $cfg = config('mail_marketing');
        return is_array($cfg) ? $cfg : ['enabled' => false];
    }
    $cfg = config('mail');
    return is_array($cfg) ? $cfg : ['enabled' => false];
}

/** Profil aktiv (enabled === true)? */
function mail_profile_enabled(string $profile): bool
{
    return !empty(mail_profile_config($profile)['enabled']);
}

/**
 * Wirksame Antwortadresse (Zustellbarkeit, 4.61): Der Header Reply-To wird nur gesetzt, wenn die konfigurierte Adresse
 * gueltig ist, sich vom Absender unterscheidet und zur registrierbaren Domain des Absenders gehoert. Eine Antwortadresse
 * auf fremder Domain (Vorgabe bis 4.60: info@mueller-holding.ag bei Absender smart-einzug.de) ist ein bekanntes Merkmal
 * fuer Spamfilter und widerspricht dem Fusstext der Vorlagen; sie wird nicht gesetzt und einmal je Prozess protokolliert.
 * Antworten laufen dann auf den Absender selbst.
 */
function mail_reply_to_effective(array $cfg, string $fromAddress): ?string
{
    $replyTo = isset($cfg['reply_to']) && $cfg['reply_to'] !== null ? mail_sanitize_header((string)$cfg['reply_to']) : '';
    if ($replyTo === '') {
        return null;
    }
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        mail_log_once('reply_to_invalid', 'mail_send: mail.reply_to ist keine gueltige Adresse und wird nicht gesetzt.');
        return null;
    }
    if (strcasecmp($replyTo, $fromAddress) === 0) {
        return null;
    }
    require_once __DIR__ . '/mail_dns.php';
    $fromDomain = mail_dns_registrable(mail_dns_domain_of($fromAddress));
    $replyDomain = mail_dns_registrable(mail_dns_domain_of($replyTo));
    if ($fromDomain === '' || $replyDomain !== $fromDomain) {
        mail_log_once('reply_to_foreign', 'mail_send: mail.reply_to liegt auf einer anderen Domain als der Absender ('
            . $replyDomain . ' statt ' . $fromDomain . ') und wird nicht gesetzt (Zustellbarkeit); Antworten erreichen den Absender.');
        return null;
    }
    return $replyTo;
}

/** Schreibt eine Meldung genau einmal je Prozess ins Fehlerprotokoll (Konfigurationshinweise ohne Wiederholung je Mail). */
function mail_log_once(string $key, string $message): void
{
    static $seen = [];
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    error_log($message);
}

/**
 * Kopfzeilen einer Nachricht (ohne To und Subject, die je Transport getrennt uebergeben werden).
 *
 * Feste Zeilen: From, Reply-To (nur wirksam, siehe mail_reply_to_effective), MIME-Version, Date, Message-ID,
 * Auto-Submitted: auto-generated (RFC 3834, alle Nachrichten der Anwendung sind Systemnachrichten; Abwesenheitsnotizen
 * und Autoresponder antworten dann nicht) und Content-Type. Mit Option 'unsubscribe_url' zusaetzlich List-Unsubscribe
 * und List-Unsubscribe-Post: List-Unsubscribe=One-Click (RFC 8058): Grosse Postfachanbieter zeigen dann eine eigene
 * Abmeldefunktion und werten die Nachricht nicht als unerwuenschte Werbung; die Abmeldung fuehrt vormerken.php aus.
 *
 * @return string[] Zeilen ohne Zeilenumbruch
 */
function mail_header_lines(array $cfg, string $contentType, bool $plainOnly, array $options = []): array
{
    $fromAddress = mail_sanitize_header((string)($cfg['from_address'] ?? ''));
    $fromName = mail_sanitize_header((string)($cfg['from_name'] ?? mail_product_name()));
    $encodedFromName = mail_encode_header_value($fromName);
    $fromHeader = $fromAddress !== '' ? sprintf('%s <%s>', $encodedFromName, $fromAddress) : $encodedFromName;
    $replyTo = mail_reply_to_effective($cfg, $fromAddress);
    $options = mail_options_normalize($options);

    $lines = [];
    $lines[] = 'From: ' . $fromHeader;
    if ($replyTo !== null) {
        $lines[] = 'Reply-To: ' . $replyTo;
    }
    $lines[] = 'MIME-Version: 1.0';
    $lines[] = 'Date: ' . date('r');
    $lines[] = 'Message-ID: ' . mail_generate_message_id();
    if (($options['profile'] ?? 'system') === 'marketing') {
        // Werbenachricht: Massenversand kennzeichnen (Precedence: bulk), kein Auto-Submitted (RFC 3834 gilt fuer
        // automatische Antworten und Systemmeldungen, nicht fuer redaktionelle Nachrichten an viele Empfaenger).
        $lines[] = 'Precedence: bulk';
    } else {
        $lines[] = 'Auto-Submitted: auto-generated';
    }
    if (isset($options['unsubscribe_url'])) {
        $lines[] = 'List-Unsubscribe: <' . $options['unsubscribe_url'] . '>';
        $lines[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $lines[] = 'Content-Type: ' . $contentType;
    if ($plainOnly) {
        $lines[] = 'Content-Transfer-Encoding: quoted-printable';
    }
    return $lines;
}

/**
 * Kennung einer Adresse für Protokolle ohne personenbezogene Daten: gekürzter Hash plus Domain
 * (reicht zur Zuordnung im Supportfall über die Datenbank, ohne die Adresse selbst zu protokollieren).
 */
function mail_addr_ref(string $to): string
{
    $lower = strtolower(trim($to));
    $domain = strrchr($lower, '@');
    return 'ref ' . substr(hash('sha256', $lower), 0, 12) . ($domain !== false ? ' ' . $domain : '');
}

/**
 * Direkte Übergabe an den Versandweg (mail(), SMTP oder Testprotokoll). $options siehe mail_send(); zusaetzlich
 * 'profile' => 'marketing' fuer das Versandprofil des Marketingmoduls (config('mail_marketing'), seit 4.63): eigener
 * Absender und SMTP-Weg, keine Monitoring-Marken des Systemversands.
 */
function mail_send_direct(string $to, string $subject, string $textBody, ?string $htmlBody = null, array $options = []): bool
{
    $options = mail_options_normalize($options);
    $profile = $options['profile'] ?? 'system';
    if (!mail_profile_enabled($profile)) {
        return false;
    }

    $to = mail_sanitize_header($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('mail_send: Empfängeradresse ungültig, Versand abgebrochen (' . mail_addr_ref($to) . ').');
        return false;
    }

    $cfg = mail_profile_config($profile);
    $monitor = $profile === 'system';
    $fromAddress = mail_sanitize_header((string)($cfg['from_address'] ?? ''));

    $subject = mail_sanitize_header($subject);
    $encodedSubject = mail_encode_header_value($subject);

    [$contentType, $body] = mail_build_body($textBody, $htmlBody);
    $headers = implode("\r\n", mail_header_lines((array)$cfg, $contentType, $htmlBody === null, $options));

    $transport = $cfg['transport'] ?? 'mail';

    if ($transport === 'log') {
        $logFile = (string)($cfg['log_file'] ?? (APP_ROOT . '/mail.log'));
        $entry = '==== ' . date('Y-m-d H:i:s') . ' ====' . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . $encodedSubject . "\r\n"
            . $headers . "\r\n\r\n"
            . $body . "\r\n\r\n";
        $written = @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('mail_send: Schreiben der Mail-Logdatei fehlgeschlagen: ' . $logFile);
            if ($monitor) { mail_monitor_mark(false, 'log_write_failed'); }
            $GLOBALS['mail_last_error'] = ['kind' => 'transport', 'text' => 'Logdatei nicht beschreibbar'];
            return false;
        }
        if ($monitor) { mail_monitor_mark(true, null); }
        $GLOBALS['mail_last_error'] = null;
        return true;
    }

    if ($transport === 'smtp') {
        try {
            mail_smtp_send((array)($cfg['smtp'] ?? []), $fromAddress, $to,
                'To: ' . $to . "\r\n" . 'Subject: ' . $encodedSubject . "\r\n" . $headers, $body);
            if ($monitor) { mail_monitor_mark(true, null); }
            $GLOBALS['mail_last_error'] = null;
            return true;
        } catch (Throwable $e) {
            if (class_exists('WorkerShutdownException') && $e instanceof WorkerShutdownException) {
                // Notbremse des Worker-Shutdowns (app/worker_signals.php): kein Transportfehler, keine
                // Monitoring-Zählung, kein Circuit-Breaker-Fehlschlag; unverändert nach oben durchreichen.
                throw $e;
            }
            error_log('mail_send: SMTP-Versand fehlgeschlagen, Empfänger ' . mail_addr_ref($to) . ': ' . (function_exists('log_sanitize') ? log_sanitize($e->getMessage()) : $e->getMessage()));
            $msg = $e->getMessage();
            // Endgültige Ablehnung des Empfängers oder der Nachricht (5xx auf RCPT TO oder DATA) ist kein Transportproblem
            $rejected = (bool)preg_match('/(RCPT TO|abgelehnt)[^\d]*5\d\d|"RCPT TO[^"]*": 5\d\d/i', $msg);
            $GLOBALS['mail_last_error'] = ['kind' => $rejected ? 'rejected' : 'transport', 'text' => $msg];
            if ($monitor) { mail_monitor_mark(false, $rejected ? null : $e); }
            return false;
        }
    }

    $result = @mail($to, $encodedSubject, $body, $headers);
    if (!$result) {
        error_log('mail_send: Versand über mail() fehlgeschlagen, Empfänger ' . mail_addr_ref($to) . '.');
        $GLOBALS['mail_last_error'] = ['kind' => 'transport', 'text' => 'mail() hat die Nachricht nicht angenommen'];
    } else {
        $GLOBALS['mail_last_error'] = null;
    }
    if ($monitor) { mail_monitor_mark((bool)$result, $result ? null : 'mail_function_false'); }
    return $result;
}

/**
 * Monitoring-Marker des Versandwegs: Zeitpunkt der letzten Übergabe (Annahme durch mail() oder
 * SMTP-Server, kein Zustellnachweis) und des letzten technischen Fehlers mit bereinigter Kategorie.
 */
function mail_monitor_mark(bool $ok, $error): void
{
    try {
        require_once __DIR__ . '/monitor.php';
        if ($ok) {
            monitor_mark('mail_last_ok_at', mon_utc(monitor_now()));
            monitor_event('mail_send', 'ok', null, null, 'instrumented', 3600);
        } else {
            monitor_mark('mail_last_fail_at', mon_utc(monitor_now()));
            monitor_mark('mail_last_fail_category', $error === null ? 'send_failed' : monitor_category($error));
            monitor_event('mail_send', 'fail', null, $error === null ? 'send_failed' : monitor_category($error), 'instrumented', 3600);
        }
    } catch (Throwable $e) {
        // Diagnose darf den Versand nicht stören
    }
}

/**
 * Minimaler SMTP-Client (AUTH LOGIN, STARTTLS oder SSL), ohne Bibliotheken.
 * Konfiguration: host, port (587 STARTTLS oder 465 SSL), encryption
 * ('tls' | 'ssl' | 'none'), user, pass. Wirft RuntimeException bei Fehlern.
 */
function mail_smtp_send(array $smtp, string $from, string $to, string $headers, string $body): void
{
    $host = (string)($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $enc = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $user = (string)($smtp['user'] ?? '');
    $pass = (string)($smtp['pass'] ?? '');
    if ($host === '' || $from === '') {
        throw new RuntimeException('SMTP-Host oder Absenderadresse fehlt.');
    }

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Verbindung zu $host:$port fehlgeschlagen: $errstr ($errno)");
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $lines = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $lines .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($lines === '') {
            throw new RuntimeException('Keine Antwort vom SMTP-Server.');
        }
        return $lines;
    };
    $cmd = function (string $command, array $okCodes) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        $resp = $read();
        if (!in_array((int)substr($resp, 0, 3), $okCodes, true)) {
            throw new RuntimeException('SMTP-Fehler auf "' . preg_replace('/^(AUTH LOGIN|[A-Za-z0-9+\/=]{8,})$/', '***', $command) . '": ' . trim($resp));
        }
        return $resp;
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException('SMTP-Begrüßung fehlgeschlagen: ' . trim($greeting));
    }
    $ehloHost = mail_message_id_host();
    $cmd('EHLO ' . $ehloHost, [250]);

    if ($enc === 'tls') {
        $cmd('STARTTLS', [220]);
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            throw new RuntimeException('STARTTLS-Verschlüsselung konnte nicht aufgebaut werden.');
        }
        $cmd('EHLO ' . $ehloHost, [250]);
    }

    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }

    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    // Zeilen, die mit "." beginnen, gemäß SMTP verdoppeln
    $data = preg_replace('/^\./m', '..', $headers . "\r\n\r\n" . $body);
    fwrite($fp, $data . "\r\n.\r\n");
    $resp = $read();
    if ((int)substr($resp, 0, 3) !== 250) {
        throw new RuntimeException('SMTP-Server hat die Nachricht abgelehnt: ' . trim($resp));
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}

/**
 * Erzeugt aus Überschrift, Absätzen und optionalem Button eine schlichte
 * HTML-Mail samt passender Textfassung. Enthält in jeder Ausgabe die
 * verbindliche Fußzeile mit Betreiberhinweis und Lexware-Office-Disclaimer.
 *
 * @param string[] $paragraphs
 * @param array{label:string,url:string}|null $button
 * @return array{text:string,html:string}
 */
function mail_layout(string $title, array $paragraphs, ?array $button = null, ?string $footerNote = null, ?array $secondaryLink = null): array
{
    // Gestaltungssprache "Goldpunkt" der Müller Holding AG (Skill mhag-ci): Kopfnaht mit Goldsegment, Wortmarke,
    // Goldbalken unter der Überschrift, Fließtext Anthrazit, Gold nur als Akzent, Fußband mit den Pflichtangaben
    // nach § 80 AktG (Rechtsform und Sitz, Registergericht, HRB, Vorstand, Aufsichtsratsvorsitzender).
    $productName = mail_product_name();
    $font = "Carlito, Calibri, 'Segoe UI', sans-serif";
    $publicBase = function_exists('public_base_url') ? public_base_url() : 'https://smart-einzug.de';
    $logoUrl = $publicBase . '/assets/img/logo-horizontal.png';
    $productLine = $productName . ' ist ein Angebot der Müller Holding AG. Unabhängige Softwarelösung mit Schnittstelle zu '
        . 'Lexware Office. Kein Produkt der Haufe-Lexware GmbH & Co. KG.';
    $footer1 = 'Müller Holding AG · Rheinpromenade 13 · 40789 Monheim am Rhein · kontakt@mueller-holding.ag · mueller-holding.ag';
    $footer2 = 'Sitz: Monheim am Rhein · Registergericht: Amtsgericht Düsseldorf · HRB 104291 · Vorstand: Timo Müller · Aufsichtsratsvorsitzender: Jan Walprecht';
    $autoNote = 'Diese E-Mail wurde automatisch von ' . $productName . ' erzeugt. Antworten erreichen uns über die Adresse im Absender.';

    $hasButton = $button !== null && !empty($button['label']) && !empty($button['url']);
    $hasSecondary = $secondaryLink !== null && !empty($secondaryLink['label']) && !empty($secondaryLink['url']);

    // --- Textfassung ---
    $textParts = [$title];
    foreach ($paragraphs as $paragraph) {
        $textParts[] = (string)$paragraph;
    }
    if ($hasButton) {
        $textParts[] = $button['label'] . ': ' . $button['url'];
    }
    if ($hasSecondary) {
        $textParts[] = $secondaryLink['label'] . ': ' . $secondaryLink['url'];
    }
    if ($footerNote !== null && trim($footerNote) !== '') {
        $textParts[] = $footerNote;
    }
    $textParts[] = $autoNote;
    $textParts[] = $productLine;
    $textParts[] = $footer1 . "\n" . $footer2;
    $text = implode("\n\n", $textParts) . "\n";

    // --- HTML-Fassung (Tabellenlayout, Inline-Stile, keine externen Stylesheets) ---
    $html = '<!DOCTYPE html>' . "\n"
        . '<html lang="de">' . "\n"
        . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . e($title) . '</title></head>' . "\n"
        . '<body style="margin:0;padding:0;background-color:#FBF6EC;">' . "\n"
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#FBF6EC;">' . "\n"
        . '<tr><td align="center" style="padding:24px 12px;">' . "\n"
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#FFFFFF;">' . "\n"
        // Kopfnaht: Haarlinie mit Goldsegment
        . '<tr><td style="padding:0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="120" style="height:3px;line-height:3px;font-size:0;background-color:#E3AC48;">&nbsp;</td>'
        . '<td style="height:3px;line-height:3px;font-size:0;background-color:#DDDBD6;">&nbsp;</td>'
        . '</tr></table></td></tr>' . "\n"
        // Wortmarke
        . '<tr><td style="padding:24px 32px 0 32px;">'
        . '<img src="' . e($logoUrl) . '" alt="' . e($productName) . '" width="185" height="42" style="display:block;border:0;width:185px;height:auto;">'
        . '</td></tr>' . "\n"
        . '<tr><td style="padding:28px 32px 0 32px;">' . "\n"
        . '<h1 style="margin:0;font-family:' . $font . ';font-size:22px;line-height:1.3;color:#2E2D2E;font-weight:bold;">' . e($title) . '</h1>' . "\n"
        . '<div style="margin:14px 0 0 0;width:56px;height:4px;line-height:4px;font-size:0;background-color:#E3AC48;">&nbsp;</div>' . "\n"
        . '</td></tr>' . "\n"
        . '<tr><td style="padding:20px 32px 4px 32px;font-family:' . $font . ';font-size:15px;line-height:1.6;color:#2E2D2E;">' . "\n";
    foreach ($paragraphs as $paragraph) {
        $html .= '<p style="margin:0 0 16px 0;">' . nl2br(e((string)$paragraph), false) . '</p>' . "\n";
    }
    $html .= '</td></tr>' . "\n";
    if ($hasButton) {
        $html .= '<tr><td style="padding:8px 32px 12px 32px;">' . "\n"
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>' . "\n"
            . '<td style="border-radius:4px;background-color:#E3AC48;">' . "\n"
            . '<a href="' . e($button['url']) . '" style="display:inline-block;padding:12px 24px;font-family:' . $font . ';font-size:15px;font-weight:bold;color:#2E2D2E;text-decoration:none;border-radius:4px;">' . e($button['label']) . '</a>' . "\n"
            . '</td></tr></table>' . "\n"
            . '</td></tr>' . "\n";
    }
    if ($hasSecondary) {
        $html .= '<tr><td style="padding:4px 32px 8px 32px;font-family:' . $font . ';font-size:13px;line-height:1.5;color:#5F5E5F;">'
            . e($secondaryLink['label']) . ': <a href="' . e($secondaryLink['url']) . '" style="color:#8A5A00;text-decoration:underline;">' . e($secondaryLink['url']) . '</a>'
            . '</td></tr>' . "\n";
    }
    if ($footerNote !== null && trim($footerNote) !== '') {
        $html .= '<tr><td style="padding:4px 32px 0 32px;font-family:' . $font . ';font-size:13px;line-height:1.5;color:#5F5E5F;">' . e($footerNote) . '</td></tr>' . "\n";
    }
    $html .= '<tr><td style="padding:24px 32px 20px 32px;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#9F9F9F;">' . e($autoNote) . '<br>' . e($productLine) . '</td></tr>' . "\n"
        // Fußband: Anthrazit mit Goldhaarlinie als Oberkante
        . '<tr><td style="padding:0;"><div style="height:2px;line-height:2px;font-size:0;background-color:#E3AC48;">&nbsp;</div></td></tr>' . "\n"
        . '<tr><td style="padding:16px 32px 18px 32px;background-color:#2E2D2E;font-family:' . $font . ';font-size:11px;line-height:1.7;color:#CFCDC8;">'
        . '<span style="color:#FFFFFF;font-weight:bold;">Müller Holding AG</span> · Rheinpromenade 13 · 40789 Monheim am Rhein · '
        . '<a href="mailto:kontakt@mueller-holding.ag" style="color:#CFCDC8;text-decoration:none;">kontakt@mueller-holding.ag</a> · '
        . '<a href="https://mueller-holding.ag" style="color:#E3AC48;text-decoration:none;">mueller-holding.ag</a><br>'
        . e($footer2)
        . '</td></tr>' . "\n"
        . '</table>' . "\n"
        . '</td></tr>' . "\n"
        . '</table>' . "\n"
        . '</body></html>';

    return ['text' => $text, 'html' => $html];
}

// ---------------------------------------------------------------------------
// Vorlagen
// ---------------------------------------------------------------------------

/** Einladung eines neuen Mitarbeiters zu einem Firmenaccount. */
function mail_tpl_invitation(string $orgName, ?string $inviterName, string $acceptUrl, string $expiresAtFormatted): array
{
    $productName = mail_product_name();
    $subject = sprintf('%s hat Sie zu %s eingeladen', $orgName, $productName);
    $title = sprintf('Einladung zu %s', $productName);

    if ($inviterName !== null && trim($inviterName) !== '') {
        $intro = sprintf(
            '%s hat Sie zum Firmenaccount "%s" bei %s eingeladen.',
            $inviterName,
            $orgName,
            $productName
        );
    } else {
        $intro = sprintf(
            'Sie wurden zum Firmenaccount "%s" bei %s eingeladen.',
            $orgName,
            $productName
        );
    }

    $paragraphs = [
        $intro,
        'Sie erhalten die Rolle Mitarbeiter und können sich nach Annahme der Einladung mit Ihrer E-Mail-Adresse anmelden.',
    ];
    $button = ['label' => 'Einladung annehmen', 'url' => $acceptUrl];
    $footerNote = sprintf(
        'Diese Einladung ist gültig bis %s. Falls Sie diese Einladung nicht erwartet haben, ignorieren Sie diese E-Mail.',
        $expiresAtFormatted
    );

    $layout = mail_layout($title, $paragraphs, $button, $footerNote);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Bestätigung einer E-Mail-Adresse nach der Registrierung. */
function mail_tpl_verify_email(string $verifyUrl): array
{
    $subject = 'E-Mail-Adresse bestätigen';
    $paragraphs = [
        'Bitte bestätigen Sie Ihre E-Mail-Adresse, um Ihr Konto vollständig nutzen zu können.',
    ];
    $button = ['label' => 'E-Mail-Adresse bestätigen', 'url' => $verifyUrl];
    $footerNote = 'Der Bestätigungslink ist 24 Stunden gültig. Falls Sie diese E-Mail nicht erwartet haben, können Sie sie ignorieren.';

    $layout = mail_layout($subject, $paragraphs, $button, $footerNote);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Bestätigung einer Vormerkung (Double-Opt-in) für eine angekündigte Integration, siehe app/interest.php.
 */
function mail_tpl_interest_confirm(string $providerName, string $confirmUrl, ?string $unsubscribeUrl = null, ?string $registeredAt = null, ?string $sourceDomain = null): array
{
    $subject = 'Bitte bestätigen Sie Ihre ' . $providerName . '-Vormerkung bei ' . mail_product_name();
    $paragraphs = [];
    if ($registeredAt !== null) {
        $paragraphs[] = 'Ihre Eintragung vom ' . $registeredAt . ($sourceDomain ? ' über ' . $sourceDomain : '') . ' konnte erst jetzt bestätigt werden; bitte entschuldigen Sie die Verzögerung.';
    }
    $paragraphs[] = (
        'Sie haben sich für Informationen zur geplanten ' . $providerName . '-Anbindung von ' . mail_product_name() . ' eingetragen. '
        . 'Bitte bestätigen Sie Ihre E-Mail-Adresse über den folgenden Link. Durch die Bestätigung entsteht kein kostenpflichtiges Abonnement. '
        . 'Wenn Sie diese Vormerkung nicht angefordert haben, müssen Sie nichts tun.');
    $button = ['label' => 'E-Mail-Adresse bestätigen', 'url' => $confirmUrl];
    $footerNote = 'Der Bestätigungslink ist 7 Tage gültig. Ohne Bestätigung wird der Eintrag nach spätestens 30 Tagen automatisch gelöscht. '
        . 'Die Vormerkung ist kostenlos und unverbindlich.';
    $secondary = $unsubscribeUrl !== null ? ['label' => 'Abmelden oder Eintrag löschen lassen', 'url' => $unsubscribeUrl] : null;
    $layout = mail_layout($subject, $paragraphs, $button, $footerNote, $secondary);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Nach bestätigter Vorregistrierung (Masterplan 7): Bestätigung, nächste Schritte, Abmeldelink.
 */
function mail_tpl_interest_confirmed(string $providerName, string $unsubscribeUrl, string $infoUrl): array
{
    $subject = 'Ihre ' . $providerName . '-Vormerkung bei ' . mail_product_name() . ' ist bestätigt';
    $paragraphs = [
        'Vielen Dank, Ihre Vormerkung ist bestätigt. Wir informieren Sie über die ' . $providerName . '-Anbindung und den geplanten Start.',
        'Derzeit müssen Sie noch kein ' . $providerName . '- oder Stripe-Konto verbinden. Durch die Vormerkung entsteht kein Abonnement und keine Zahlungspflicht.',
        'Den aktuellen Stand finden Sie jederzeit auf der Produktseite.',
    ];
    $button = ['label' => 'Zum aktuellen Stand', 'url' => $infoUrl];
    $footerNote = 'Sie erhalten Nachrichten zu Entwicklungsstand und Start dieser Anbindung, gegebenenfalls eine Einladung zum Betatest. '
        . 'Ihre Angaben werden 30 Tage nach der Startnachricht oder nach einer Abmeldung gelöscht.';
    $layout = mail_layout($subject, $paragraphs, $button, $footerNote, ['label' => 'Abmelden', 'url' => $unsubscribeUrl]);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Willkommen nach der Registrierung eines Firmenaccounts, mit Bestätigungslink für die E-Mail-Adresse.
 */
function mail_tpl_welcome(string $orgName, ?string $verifyUrl): array
{
    $product = mail_product_name();
    $subject = $verifyUrl !== null ? 'Willkommen bei ' . $product . ': Bitte E-Mail-Adresse bestätigen' : 'Willkommen bei ' . $product;
    $paragraphs = [
        $verifyUrl !== null
            ? 'Ihr Firmenaccount „' . $orgName . '“ ist angelegt. Bitte bestätigen Sie zunächst Ihre E-Mail-Adresse über den folgenden Link.'
            : 'Ihr Firmenaccount „' . $orgName . '“ ist angelegt; Ihre E-Mail-Adresse gilt als bestätigt.',
        'Die nächsten Schritte in der Anwendung: Zwei-Faktor-Anmeldung einrichten, Ihr Buchhaltungssystem verbinden, Ihr eigenes Stripe-Konto verbinden. '
        . 'Danach stehen offene Rechnungen zum SEPA-Einzug bereit.',
        'Bei Fragen hilft das Hilfe-Center in der Anwendung oder eine Antwort auf diese E-Mail.',
    ];
    $button = $verifyUrl !== null ? ['label' => 'E-Mail-Adresse bestätigen', 'url' => $verifyUrl] : ['label' => 'Zur Anwendung', 'url' => app_base_url() . '/login.php'];
    $footerNote = ($verifyUrl !== null ? 'Der Bestätigungslink ist 24 Stunden gültig; in der Anwendung können Sie ihn jederzeit erneut anfordern. ' : '')
        . 'Falls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail; es entsteht kein Vertrag.';
    $layout = mail_layout($subject, $paragraphs, $button, $footerNote);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Generische Sicherheitsbenachrichtigung. $lines werden unverändert als
 * Absätze übernommen, danach folgt ein Standardhinweis.
 *
 * @param string[] $lines
 */
function mail_tpl_security(string $headline, array $lines, ?string $actionUrl = null, ?string $actionLabel = null): array
{
    $subject = 'Sicherheitshinweis: ' . $headline;

    $paragraphs = [];
    foreach ($lines as $line) {
        $paragraphs[] = (string)$line;
    }
    $paragraphs[] = 'Wenn Sie diese Änderung nicht veranlasst haben, melden Sie sich umgehend an, prüfen Sie '
        . 'Ihre Zugangsdaten und wenden Sie sich an den Inhaber Ihres Firmenaccounts.';

    $button = null;
    if ($actionUrl !== null && trim($actionUrl) !== '') {
        $button = ['label' => $actionLabel ?? 'Jetzt anmelden', 'url' => $actionUrl];
    }

    $layout = mail_layout($headline, $paragraphs, $button);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Vorabankündigung (Pre-Notification) einer SEPA-Lastschrift an den Kunden der Firma.
 * Inhalt: Rechnung, Betrag, Einzugstermin, Zahlungsempfänger, Mandatsreferenz, Gläubiger-Identifikationsnummer,
 * Hinweis auf den Zahlungsdienstleister. Einzige Quelle der Vorlage; genutzt von _send_prenotification()
 * (app/collections.php) sowie vom Musterversand (admin-system.php, bin/mail-check.php --vorabankuendigung).
 */
function mail_tpl_prenotification(array $org, array $invoice, array $mandate, int $amountCents, string $dueDate, ?string $footerNote = null): array
{
    $orgName = (string)($org['name'] ?? '');
    $voucher = (string)($invoice['voucher_number'] ?? '');
    $creditorId = trim((string)($org['creditor_identifier'] ?? ''));
    $lines = [
        'Sehr geehrte Damen und Herren, wir kündigen hiermit den Einzug folgender Lastschrift an:',
        sprintf('Rechnung %s über %s, Fälligkeit/Einzug am %s.', $voucher, format_eur_cents($amountCents), format_date($dueDate)),
        sprintf('Zahlungsempfänger: %s. Mandatsreferenz: %s.%s', $orgName, (string)($mandate['mandate_reference'] ?? ''),
            $creditorId !== '' ? ' Gläubiger-Identifikationsnummer: ' . $creditorId . '.' : ''),
        'Der Einzug erfolgt über den Zahlungsdienstleister Stripe. Bitte sorgen Sie für ausreichende Kontodeckung.',
    ];
    $note = $orgName;
    if ($footerNote !== null && trim($footerNote) !== '') {
        $note = trim($footerNote) . ' ' . $orgName;
    }
    $layout = mail_layout('Vorabankündigung SEPA-Lastschrift', $lines, null, $note);
    return ['subject' => 'Vorabankündigung SEPA-Lastschrift ' . $voucher, 'text' => $layout['text'], 'html' => $layout['html']];
}

/**
 * Musterdaten für den Testversand der Vorabankündigung (kein echter Kunde, keine echte Rechnung).
 * Firmenname und Gläubiger-ID kommen aus der übergebenen Firma, damit der Betreiber die Mail so sieht,
 * wie sie ein Kunde dieser Firma erhielte.
 */
function mail_prenotification_sample(array $org): array
{
    $due = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+14 days')->format('Y-m-d');
    return [
        'org' => ['name' => (string)($org['name'] ?? 'Muster GmbH'), 'creditor_identifier' => (string)($org['creditor_identifier'] ?? 'DE98ZZZ09999999999')],
        'invoice' => ['voucher_number' => 'RE-MUSTER-0001'],
        'mandate' => ['mandate_reference' => 'MUSTER-MANDAT-0001'],
        'amount_cents' => 123456,
        'due_date' => $due,
    ];
}

/** Wiederherstellungscodes wurden neu erzeugt. */
function mail_tpl_recovery_codes_regenerated(string $when): array
{
    return mail_tpl_security(
        'Wiederherstellungscodes neu erzeugt',
        [
            sprintf('Am %s wurden neue Wiederherstellungscodes für Ihr Konto erzeugt.', $when),
            'Die bisherigen Wiederherstellungscodes sind damit ungültig geworden.',
        ]
    );
}

/** Zwei-Faktor-Authentifizierung wurde zurückgesetzt. */
function mail_tpl_2fa_reset(string $when, bool $byAdmin): array
{
    $lines = [
        sprintf('Am %s wurde die Zwei-Faktor-Authentifizierung für Ihr Konto zurückgesetzt.', $when),
    ];
    if ($byAdmin) {
        $lines[] = 'Der Reset wurde durch den Inhaber Ihres Firmenaccounts veranlasst. Bei Rückfragen wenden Sie '
            . 'sich bitte an diesen.';
    } else {
        $lines[] = 'Der Reset erfolgte mit einem Wiederherstellungscode. Bitte richten Sie die '
            . 'Zwei-Faktor-Authentifizierung bei der nächsten Anmeldung erneut ein.';
    }

    return mail_tpl_security('Zwei-Faktor-Authentifizierung zurückgesetzt', $lines);
}

/** Die Inhaberschaft eines Firmenaccounts wurde übertragen. */
function mail_tpl_ownership_transferred(string $orgName, string $newOwnerEmail): array
{
    $subject = sprintf('Inhaberwechsel bei %s', $orgName);
    $paragraphs = [
        sprintf('Die Inhaberschaft des Firmenaccounts "%s" wurde auf %s übertragen.', $orgName, $newOwnerEmail),
        'Der neue Inhaber verfügt ab sofort über alle Rechte zur Verwaltung des Firmenaccounts, '
            . 'einschließlich Team, Einstellungen und Integrationen.',
    ];

    $layout = mail_layout($subject, $paragraphs);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Ein Mitglied wurde aus einem Firmenaccount entfernt. */
function mail_tpl_member_removed(string $orgName, string $memberEmail, string $byEmail): array
{
    $subject = sprintf('Mitglied aus %s entfernt', $orgName);
    $paragraphs = [
        sprintf('%s wurde von %s aus dem Firmenaccount "%s" entfernt.', $memberEmail, $byEmail, $orgName),
        'Der Zugriff des entfernten Mitglieds auf den Firmenaccount ist damit beendet.',
    ];

    $layout = mail_layout($subject, $paragraphs);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Ein Mitglied ist einem Firmenaccount beigetreten. */
function mail_tpl_member_joined(string $orgName, string $memberEmail): array
{
    $subject = sprintf('Neues Mitglied bei %s', $orgName);
    $paragraphs = [
        sprintf('%s ist dem Firmenaccount "%s" beigetreten.', $memberEmail, $orgName),
    ];

    $layout = mail_layout($subject, $paragraphs);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Eine Integration (z. B. Lexware Office oder Stripe) wurde geändert. */
function mail_tpl_integration_changed(string $orgName, string $what, string $byEmail): array
{
    $subject = sprintf('Änderung an einer Integration bei %s', $orgName);
    $paragraphs = [
        sprintf('%s hat am Firmenaccount "%s" folgende Änderung vorgenommen: %s.', $byEmail, $orgName, $what),
    ];

    $layout = mail_layout($subject, $paragraphs);
    return ['subject' => $subject, 'text' => $layout['text'], 'html' => $layout['html']];
}

/** Letzter Fehler von mail_send_direct: ['kind' => 'transport'|'rejected', 'text' => ...] oder null. */
function mail_last_error(): ?array
{
    return $GLOBALS['mail_last_error'] ?? null;
}
