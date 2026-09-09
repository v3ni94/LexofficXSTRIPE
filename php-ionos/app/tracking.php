<?php
/**
 * Google-Tag der Anwendung (app.smart-einzug.de).
 *
 * Vorgabe des Betreibers vom 10.09.2026: Die Google-Ads-Kennung soll auch in der
 * Anwendung hinterlegt sein, damit eine Registrierung als Conversion messbar wird.
 *
 * Bewusste Einschraenkung, siehe docs/entwickler/sicherheit.md:
 * Das Tag laedt AUSSCHLIESSLICH auf oeffentlichen Seiten ohne Anmeldung, die keine
 * Kennungen in der Adresse tragen (derzeit register.php und vormerken.php).
 * Auf angemeldeten Seiten wuerde der Seitenpfad Kunden-, Rechnungs- und
 * Mandatskennungen an Google uebertragen. Diese Daten verarbeitet die Mueller
 * Holding AG als Auftragsverarbeiterin fuer ihre Kunden; eine Uebermittlung an
 * Google waere ein neuer Unterauftragsverarbeiter und ein Verstoss gegen den
 * Auftragsverarbeitungsvertrag. Ebenso ausgeschlossen sind Seiten mit Token in
 * der Adresse (reset-password.php, invite.php, twofa-verify.php, support-login.php).
 *
 * Eine einzige, eng begrenzte Ausnahme (seit 4.58): die Bestaetigungsseite einer abgeschlossenen
 * Bestellung, subscription.php mit genau dem Parameter bestellt=1. Sie ist angemeldet, traegt aber
 * keine Kennung in der Adresse und ist der einzige Ort, an dem eine Conversion entsteht. Ohne sie
 * zaehlt Google Ads nur den Aufruf der Registrierung, nicht das bezahlte Abonnement.
 *
 * Ohne Einwilligung wird kein Google-Skript geladen und kein Cookie gesetzt;
 * die Einwilligung holt assets/js/consent.js ein.
 */
declare(strict_types=1);

/** Seiten, die das Tag einbinden duerfen. Bewusst eine feste Liste, keine Regel. */
const TRACKING_PUBLIC_PAGES = ['register.php', 'vormerken.php'];

/**
 * Eng begrenzte Ausnahme fuer die abgeschlossene Bestellung (seit 4.58).
 *
 * Ohne sie zaehlt Google Ads nur, dass jemand die Registrierung erreicht hat, nicht, dass daraus ein
 * bezahltes Abonnement wurde; genau diese Zahl entscheidet aber ueber die Bewertung einer Anzeige.
 *
 * Die Ausnahme gilt AUSSCHLIESSLICH fuer subscription.php mit genau einem Parameter "bestellt=1"
 * (tracking_conversion_page()). Damit bleibt der Grund der obigen Einschraenkung gewahrt: Diese Adresse
 * traegt keine Kunden-, Rechnungs- oder Mandatskennung. Jede andere Adresse der angemeldeten Anwendung,
 * auch subscription.php selbst ohne diesen Parameter, bleibt ohne Tag.
 */
const TRACKING_CONVERSION_PAGE = 'subscription.php';
const TRACKING_CONVERSION_PARAM = 'bestellt';

/**
 * Ist die aktuelle Anfrage die Bestaetigungsseite einer abgeschlossenen Bestellung?
 * Streng: richtige Seite, genau ein Parameter, genau der erwartete Wert.
 */
function tracking_conversion_page(?string $script = null, ?array $query = null): bool
{
    $script = $script ?? (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $query = $query ?? $_GET;
    if (basename($script) !== TRACKING_CONVERSION_PAGE) {
        return false;
    }
    return array_keys($query) === [TRACKING_CONVERSION_PARAM]
        && (string)($query[TRACKING_CONVERSION_PARAM] ?? '') === '1';
}

/**
 * Conversion-Label der Google-Ads-Aktion (config analytics.ads_conversion_label).
 * Ohne Label meldet die Anwendung keine Conversion; erfunden wird es nie.
 */
function tracking_conversion_label(): string
{
    $cfg = config('analytics', []);
    if (!is_array($cfg) || empty($cfg['enabled'])) {
        return '';
    }
    $label = trim((string)($cfg['ads_conversion_label'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{5,40}$/', $label) === 1 ? $label : '';
}

/**
 * Konfigurierte Kennungen. Leere Werte bedeuten: kein Tag.
 *
 * @return array{ga: string, ads: string}
 */
function tracking_ids(): array
{
    $cfg = config('analytics', []);
    if (!is_array($cfg) || empty($cfg['enabled'])) {
        return ['ga' => '', 'ads' => ''];
    }
    $ga = trim((string)($cfg['ga_id'] ?? ''));
    $ads = trim((string)($cfg['ads_id'] ?? ''));
    // Nur die von Google vergebenen Formate zulassen, damit keine fremde Kennung
    // ueber eine falsch gepflegte Konfiguration in die Seite gelangt.
    if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{6,15}$/', $ga)) {
        $ga = '';
    }
    if ($ads !== '' && !preg_match('/^AW-\d{6,15}$/', $ads)) {
        $ads = '';
    }
    return ['ga' => $ga, 'ads' => $ads];
}

/** Ist fuer diese Seite ein Tag vorgesehen und konfiguriert? */
function tracking_allowed_for(string $script): bool
{
    $oeffentlich = in_array(basename($script), TRACKING_PUBLIC_PAGES, true);
    // Die Bestaetigungsseite der Bestellung darf das Tag laden, obwohl sie angemeldet ist: Sie traegt keine
    // Kennung in der Adresse und ist der einzige Ort, an dem eine Conversion entsteht. Fuer sie ist zudem
    // eine Ads-Kennung noetig; eine reine Analytics-Kennung rechtfertigt die Ausnahme nicht.
    $ids = tracking_ids();
    if (!$oeffentlich) {
        return tracking_conversion_page($script) && $ids['ads'] !== '';
    }
    return $ids['ga'] !== '' || $ids['ads'] !== '';
}

/**
 * HTML fuer den <head>. Bindet nur die eigene Datei ein; das Google-Skript laedt
 * consent.js erst nach ausdruecklicher Einwilligung nach.
 */
function tracking_head_html(?string $script = null): string
{
    $ids = tracking_ids();
    if ($ids['ga'] === '' && $ids['ads'] === '') {
        return '';
    }
    $privacy = rtrim(trim((string)config('marketing_url', '')), '/');
    $privacy = $privacy !== '' ? $privacy . '/datenschutz' : '';
    // Auf der Bestaetigungsseite der Bestellung wird ausschliesslich die Ads-Kennung mitgegeben: Dort geht es
    // um die Conversion, nicht um Reichweitenmessung des angemeldeten Bereichs.
    $konversion = tracking_conversion_page($script);
    return sprintf(
        '<script src="%s" data-ga="%s" data-ads="%s" data-privacy="%s"%s defer></script>',
        e(asset_url('assets/js/consent.js')),
        e($konversion ? '' : $ids['ga']),
        e($ids['ads']),
        e($privacy),
        $konversion && tracking_conversion_label() !== ''
            ? ' data-conversion-label="' . e(tracking_conversion_label()) . '"'
            : ''
    );
}
