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

/** Hostname für die Message-ID, abgeleitet aus config('base_url'). */
function mail_message_id_host(): string
{
    $host = parse_url((string)config('base_url', ''), PHP_URL_HOST);
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
function mail_send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
{
    if (!mail_enabled()) {
        return false;
    }

    $to = mail_sanitize_header($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('mail_send: Empfängeradresse ungültig, Versand abgebrochen: ' . $to);
        return false;
    }

    $cfg = config('mail');
    $fromAddress = mail_sanitize_header((string)($cfg['from_address'] ?? ''));
    $fromName = mail_sanitize_header((string)($cfg['from_name'] ?? config('product_name', 'Lexware-Einzug')));
    $replyTo = isset($cfg['reply_to']) && $cfg['reply_to'] !== null
        ? mail_sanitize_header((string)$cfg['reply_to'])
        : null;

    $subject = mail_sanitize_header($subject);
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n");
    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8', 'Q', "\r\n");
    $fromHeader = $fromAddress !== '' ? sprintf('%s <%s>', $encodedFromName, $fromAddress) : $encodedFromName;

    [$contentType, $body] = mail_build_body($textBody, $htmlBody);

    $headerLines = [];
    $headerLines[] = 'From: ' . $fromHeader;
    if ($replyTo !== null && $replyTo !== '') {
        $headerLines[] = 'Reply-To: ' . $replyTo;
    }
    $headerLines[] = 'MIME-Version: 1.0';
    $headerLines[] = 'Date: ' . date('r');
    $headerLines[] = 'Message-ID: ' . mail_generate_message_id();
    $headerLines[] = 'Content-Type: ' . $contentType;
    if ($htmlBody === null) {
        $headerLines[] = 'Content-Transfer-Encoding: quoted-printable';
    }
    $headers = implode("\r\n", $headerLines);

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
            return false;
        }
        return true;
    }

    if ($transport === 'smtp') {
        try {
            mail_smtp_send((array)($cfg['smtp'] ?? []), $fromAddress, $to,
                'To: ' . $to . "\r\n" . 'Subject: ' . $encodedSubject . "\r\n" . $headers, $body);
            return true;
        } catch (Throwable $e) {
            error_log('mail_send: SMTP-Versand fehlgeschlagen, Empfänger: ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    $result = @mail($to, $encodedSubject, $body, $headers);
    if (!$result) {
        error_log('mail_send: Versand über mail() fehlgeschlagen, Empfänger: ' . $to);
    }
    return $result;
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
function mail_layout(string $title, array $paragraphs, ?array $button = null, ?string $footerNote = null): array
{
    $productName = (string)config('product_name', 'Lexware-Einzug');
    $font = "Carlito, Calibri, 'Segoe UI', sans-serif";
    $mandatoryFooter = 'Lexware-Einzug ist ein Dienst der Müller Holding AG, Rheinpromenade 13, '
        . '40789 Monheim am Rhein. Unabhängige Softwarelösung mit Schnittstelle zu Lexware Office. '
        . 'Kein Produkt der Haufe-Lexware GmbH & Co. KG.';
    $autoNote = $productName . '. Diese E-Mail wurde automatisch erzeugt.';

    $hasButton = $button !== null && !empty($button['label']) && !empty($button['url']);

    // --- Textfassung ---
    $textParts = [$title];
    foreach ($paragraphs as $paragraph) {
        $textParts[] = (string)$paragraph;
    }
    if ($hasButton) {
        $textParts[] = $button['label'] . ': ' . $button['url'];
    }
    if ($footerNote !== null && trim($footerNote) !== '') {
        $textParts[] = $footerNote;
    }
    $textParts[] = $autoNote;
    $textParts[] = $mandatoryFooter;
    $text = implode("\n\n", $textParts) . "\n";

    // --- HTML-Fassung ---
    $html = '<!DOCTYPE html>' . "\n"
        . '<html lang="de">' . "\n"
        . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>' . "\n"
        . '<body style="margin:0;padding:0;background-color:#F4F4F4;">' . "\n"
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F4F4F4;">' . "\n"
        . '<tr><td align="center" style="padding:24px 12px;">' . "\n"
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
        . 'style="max-width:600px;width:100%;background-color:#FFFFFF;">' . "\n"
        . '<tr><td style="padding:32px 32px 0 32px;">' . "\n"
        . '<h1 style="margin:0;font-family:' . $font . ';font-size:21px;line-height:1.3;color:#2E2D2E;">'
        . e($title) . '</h1>' . "\n"
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
            . '<a href="' . e($button['url']) . '" style="display:inline-block;padding:12px 24px;'
            . 'font-family:' . $font . ';font-size:15px;font-weight:bold;color:#2E2D2E;text-decoration:none;'
            . 'border-radius:4px;">' . e($button['label']) . '</a>' . "\n"
            . '</td></tr></table>' . "\n"
            . '</td></tr>' . "\n";
    }

    if ($footerNote !== null && trim($footerNote) !== '') {
        $html .= '<tr><td style="padding:4px 32px 0 32px;font-family:' . $font . ';font-size:13px;line-height:1.5;color:#6B6A69;">'
            . e($footerNote) . '</td></tr>' . "\n";
    }

    $html .= '<tr><td style="padding:24px 32px 32px 32px;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#9F9F9F;">' . "\n"
        . e($autoNote) . '<br><br>' . "\n"
        . e($mandatoryFooter) . "\n"
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
    $productName = (string)config('product_name', 'Lexware-Einzug');
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
