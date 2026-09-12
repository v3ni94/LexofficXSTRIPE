<?php
/**
 * Zustellbarkeit ausgehender E-Mails: Auswertung von SPF, DKIM, DMARC und der Absenderkonsistenz.
 *
 * Diese Datei wertet NUR aus und fragt selbst kein DNS ab. Die Abfrage liegt in bin/mail-check.php
 * (Aufruf mit --zustellbarkeit), damit die Auswertung ohne Netz prüfbar bleibt (tools/mail-dns-check.php).
 *
 * Grenze der Aussage (wichtig, damit kein falsches Vertrauen entsteht): Ein vorhandener DKIM-Eintrag im DNS
 * beweist NICHT, dass ausgehende Nachrichten signiert werden. Bei Anbietern wie IONOS zeigen die Einträge
 * s1-ionos._domainkey und s2-ionos._domainkey als CNAME auf einen gemeinsamen Schluessel des Anbieters; sie
 * werden bereits beim Anlegen der Mailkonfiguration gesetzt, unabhaengig davon, ob die Signatur im
 * Kundenbereich eingeschaltet ist. Ob wirklich signiert wird, steht ausschliesslich im Kopf einer empfangenen
 * Nachricht (Authentication-Results: dkim=pass header.d=<domain>).
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

/** Domain einer E-Mail-Adresse in Kleinbuchstaben, leer bei ungueltiger Adresse. */
function mail_dns_domain_of(string $address): string
{
    $at = strrpos($address, '@');
    return $at === false ? '' : mb_strtolower(trim(substr($address, $at + 1)));
}

/** Grobe registrierbare Domain (letzte zwei Labels). Reicht fuer den Vergleich Anbieter gegen SPF-Inhalt. */
function mail_dns_registrable(string $host): string
{
    $parts = array_values(array_filter(explode('.', mb_strtolower(trim($host, ". \t\n")))));
    $n = count($parts);
    return $n < 2 ? implode('.', $parts) : $parts[$n - 2] . '.' . $parts[$n - 1];
}

/** Ein Prüfergebnis. $status: ok | warnung | fehler | unklar. */
function mail_dns_ergebnis(string $status, string $titel, string $hinweis = '', string $beleg = ''): array
{
    return ['status' => $status, 'titel' => $titel, 'hinweis' => $hinweis, 'beleg' => $beleg];
}

/**
 * SPF der Absenderdomain bewerten.
 *
 * @param string[] $txt      TXT-Einträge der Absenderdomain (Apex)
 * @param string   $transport mail | smtp | log
 * @param string   $smtpHost  Hostname des SMTP-Relays (nur bei transport = smtp bedeutsam)
 */
function mail_dns_spf(array $txt, string $transport, string $smtpHost): array
{
    $spf = array_values(array_filter($txt, static fn(string $t): bool => stripos(trim($t), 'v=spf1') === 0));
    if (!$spf) {
        return mail_dns_ergebnis('fehler', 'SPF fehlt',
            'Ohne SPF-Eintrag bewertet der Empfaenger jede Nachricht als nicht authentifiziert. Beim Mailanbieter den vorgegebenen Include-Wert abrufen und als TXT auf die Absenderdomain setzen.');
    }
    if (count($spf) > 1) {
        return mail_dns_ergebnis('fehler', 'Mehrere SPF-Eintraege',
            'Mehr als ein v=spf1-Eintrag ist ungueltig; die Pruefung des Empfaengers schlaegt fehl (permerror). Die Eintraege zu einem einzigen zusammenfassen.',
            implode(' | ', $spf));
    }
    $record = trim($spf[0]);
    $qualifier = preg_match('/([-~?+])all\s*$/', $record, $m) ? $m[1] : '';

    if ($transport === 'mail') {
        return mail_dns_ergebnis('warnung', 'SPF vorhanden, Versandweg aber nicht abgedeckt',
            'Die Anwendung uebergibt Nachrichten an die lokale Funktion mail(); sie verlassen den Server dann mit dessen eigener Adresse. Diese Adresse muss im SPF stehen, sonst schlaegt SPF fehl. Empfehlung: Versand ueber das Postfach des Mailanbieters (transport = smtp).',
            $record);
    }

    $anbieter = mail_dns_registrable($smtpHost);
    $anbieterKern = explode('.', $anbieter)[0] ?? '';
    $passt = $anbieter !== '' && (stripos($record, $anbieter) !== false || ($anbieterKern !== '' && stripos($record, $anbieterKern) !== false));
    if (!$passt) {
        return mail_dns_ergebnis('warnung', 'SPF gibt den verwendeten Versandweg moeglicherweise nicht frei',
            'Der Eintrag nennt den Anbieter des SMTP-Relays (' . $smtpHost . ') nicht. Pruefen, ob dessen Include enthalten ist; sonst schlaegt SPF fehl.',
            $record);
    }
    $hinweis = $qualifier === '-'
        ? 'Strikte Ablehnung (-all) ist gesetzt.'
        : 'Der Eintrag endet auf ' . ($qualifier !== '' ? $qualifier . 'all' : 'keinem all-Mechanismus')
          . '. Das ist beim Anbieter ueblich und zulaessig; nach nachgewiesener DKIM-Signatur kann auf -all verschaerft werden.';
    return mail_dns_ergebnis('ok', 'SPF vorhanden und passt zum Versandweg', $hinweis, $record);
}

/**
 * DMARC bewerten.
 *
 * @param string[] $txt          TXT-Einträge unter _dmarc.<domain> (nach CNAME-Aufloesung)
 * @param bool     $ueberCname   true, wenn _dmarc als CNAME auf einen Anbieter zeigt
 * @param string   $eigeneDomain Absenderdomain, fuer die Bewertung des Berichtsempfaengers
 */
function mail_dns_dmarc(array $txt, bool $ueberCname, string $eigeneDomain): array
{
    $rec = array_values(array_filter($txt, static fn(string $t): bool => stripos(trim($t), 'v=DMARC1') === 0));
    if (!$rec) {
        return mail_dns_ergebnis('fehler', 'DMARC fehlt oder ist nicht aufloesbar',
            $ueberCname
                ? 'Unter _dmarc steht ein CNAME auf den Anbieter, dahinter liegt aber keine auswertbare Richtlinie. Grosse Anbieter verlangen fuer regelmaessige Absender eine DMARC-Richtlinie. Eigenen TXT-Eintrag setzen: v=DMARC1; p=none; rua=mailto:dmarc@' . $eigeneDomain . '; fo=1'
                : 'Eigenen TXT-Eintrag unter _dmarc setzen: v=DMARC1; p=none; rua=mailto:dmarc@' . $eigeneDomain . '; fo=1');
    }
    $record = trim($rec[0]);
    $policy = preg_match('/\bp\s*=\s*([a-z]+)/i', $record, $m) ? mb_strtolower($m[1]) : '(ohne p)';
    $rua = preg_match('/\brua\s*=\s*([^;]+)/i', $record, $m) ? trim($m[1]) : '';
    $ruaFremd = $rua !== '' && stripos($rua, '@' . $eigeneDomain) === false;

    if ($ueberCname || $ruaFremd) {
        return mail_dns_ergebnis('warnung', 'DMARC vorhanden, aber nicht in eigener Hand (Richtlinie p=' . $policy . ')',
            ($ueberCname ? 'Die Richtlinie liegt als CNAME beim Anbieter. ' : '')
            . ($ruaFremd ? 'Die Berichte (rua) gehen nicht an eine eigene Adresse; Sie sehen damit nicht, welcher Versandweg scheitert. ' : '')
            . 'Empfehlung: CNAME entfernen und einen eigenen TXT setzen (v=DMARC1; p=none; rua=mailto:dmarc@' . $eigeneDomain . '; fo=1). Ein Name kann nicht gleichzeitig CNAME und TXT tragen.',
            $record);
    }
    return mail_dns_ergebnis('ok', 'DMARC vorhanden (Richtlinie p=' . $policy . ')',
        'Berichte laufen auf eine eigene Adresse. Nach zwei Wochen ohne Beanstandung kann p=quarantine gesetzt werden.',
        $record);
}

/**
 * DKIM bewerten.
 *
 * @param array<string,array{txt:string[],cname:string}> $treffer Selektor => gefundene Eintraege
 */
function mail_dns_dkim(array $treffer): array
{
    $mitSchluessel = [];
    $nurCname = [];
    foreach ($treffer as $selektor => $eintrag) {
        $hatSchluessel = false;
        foreach ((array)($eintrag['txt'] ?? []) as $t) {
            if (stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false) {
                $hatSchluessel = true;
            }
        }
        if ($hatSchluessel) {
            $mitSchluessel[] = $selektor;
        } elseif (trim((string)($eintrag['cname'] ?? '')) !== '') {
            $nurCname[] = $selektor;
        }
    }
    $nachweis = 'Ein Eintrag im DNS beweist nicht, dass ausgehende Nachrichten signiert werden. Massgeblich ist der Kopf einer empfangenen Nachricht: Authentication-Results mit dkim=pass und header.d der Absenderdomain.';
    if ($mitSchluessel) {
        return mail_dns_ergebnis('ok', 'DKIM-Schluessel im DNS gefunden (' . implode(', ', $mitSchluessel) . ')', $nachweis);
    }
    if ($nurCname) {
        return mail_dns_ergebnis('unklar', 'DKIM-Verweis vorhanden, aber kein Schluessel abrufbar (' . implode(', ', $nurCname) . ')',
            'Der CNAME zeigt auf den Anbieter, dahinter liegt kein auswertbarer Schluessel. Haeufigste Ursache: Die Signatur ist im Kundenbereich des Mailanbieters fuer diese Domain nicht eingeschaltet. ' . $nachweis);
    }
    return mail_dns_ergebnis('fehler', 'Kein DKIM-Eintrag gefunden',
        'Ohne DKIM fehlt die Unterschrift, die grosse Anbieter von regelmaessigen Absendern erwarten. Selektor und Schluessel beim Mailanbieter abrufen und setzen. ' . $nachweis);
}

/** Absenderkonsistenz: From gegen SMTP-Postfach und gegen Reply-To. */
function mail_dns_alignment(string $fromAddress, string $smtpUser, ?string $replyTo, string $transport): array
{
    $ergebnisse = [];
    $fromDomain = mail_dns_domain_of($fromAddress);
    if ($fromDomain === '') {
        $ergebnisse[] = mail_dns_ergebnis('fehler', 'Absenderadresse fehlt oder ist ungueltig',
            'Ohne gueltige Absenderadresse ist keine Authentifizierung moeglich (config mail.from_address).');
        return $ergebnisse;
    }
    if ($transport === 'smtp') {
        $userDomain = mail_dns_domain_of($smtpUser);
        if ($userDomain === '') {
            $ergebnisse[] = mail_dns_ergebnis('warnung', 'SMTP-Benutzer ist keine E-Mail-Adresse',
                'Viele Anbieter verlangen die vollstaendige Adresse des Postfachs als Benutzernamen (config mail.smtp.user).');
        } elseif ($userDomain !== $fromDomain) {
            $ergebnisse[] = mail_dns_ergebnis('fehler', 'Absender und Versandpostfach gehoeren zu verschiedenen Domains',
                'Der Absender lautet auf ' . $fromDomain . ', versendet wird ueber ein Postfach von ' . $userDomain
                . '. Der Anbieter signiert und autorisiert dann fuer die falsche Domain; SPF und DKIM passen nicht zum sichtbaren Absender (Alignment). Ein Postfach der Absenderdomain verwenden.');
        } else {
            $ergebnisse[] = mail_dns_ergebnis('ok', 'Absender und Versandpostfach gehoeren zur selben Domain (' . $fromDomain . ')');
        }
    }
    $replyDomain = $replyTo !== null && trim($replyTo) !== '' ? mail_dns_domain_of($replyTo) : '';
    if ($replyDomain !== '' && $replyDomain !== $fromDomain) {
        $ergebnisse[] = mail_dns_ergebnis('warnung', 'Antwortadresse liegt auf einer anderen Domain',
            'From lautet auf ' . $fromDomain . ', Reply-To auf ' . $replyDomain
            . '. Filter werten das als schwaches Merkmal fuer Missbrauch. Entweder Reply-To leer lassen oder eine Adresse der Absenderdomain verwenden (config mail.reply_to).');
    }
    return $ergebnisse;
}

/** Formatiert die Prüfergebnisse als Textblock mit Zeichen je Status. */
function mail_dns_bericht(array $ergebnisse): string
{
    $zeichen = ['ok' => '[ OK     ]', 'warnung' => '[ PRUEFEN]', 'fehler' => '[ FEHLT  ]', 'unklar' => '[ UNKLAR ]'];
    $zeilen = [];
    foreach ($ergebnisse as $e) {
        $zeilen[] = ($zeichen[$e['status']] ?? '[        ]') . ' ' . $e['titel'];
        if (trim((string)$e['beleg']) !== '') {
            $zeilen[] = '            Eintrag: ' . $e['beleg'];
        }
        if (trim((string)$e['hinweis']) !== '') {
            foreach (explode("\n", wordwrap((string)$e['hinweis'], 96, "\n", false)) as $z) {
                $zeilen[] = '            ' . $z;
            }
        }
    }
    return implode("\n", $zeilen);
}

/** Schlechtester Status einer Liste (fehler > unklar > warnung > ok). */
function mail_dns_gesamtstatus(array $ergebnisse): string
{
    $rang = ['ok' => 0, 'warnung' => 1, 'unklar' => 2, 'fehler' => 3];
    $max = 'ok';
    foreach ($ergebnisse as $e) {
        if (($rang[$e['status']] ?? 0) > ($rang[$max] ?? 0)) {
            $max = $e['status'];
        }
    }
    return $max;
}
