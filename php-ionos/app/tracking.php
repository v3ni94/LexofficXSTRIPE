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
 * Ohne Einwilligung wird kein Google-Skript geladen und kein Cookie gesetzt;
 * die Einwilligung holt assets/js/consent.js ein.
 */
declare(strict_types=1);

/** Seiten, die das Tag einbinden duerfen. Bewusst eine feste Liste, keine Regel. */
const TRACKING_PUBLIC_PAGES = ['register.php', 'vormerken.php'];

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
    if (!in_array(basename($script), TRACKING_PUBLIC_PAGES, true)) {
        return false;
    }
    $ids = tracking_ids();
    return $ids['ga'] !== '' || $ids['ads'] !== '';
}

/**
 * HTML fuer den <head>. Bindet nur die eigene Datei ein; das Google-Skript laedt
 * consent.js erst nach ausdruecklicher Einwilligung nach.
 */
function tracking_head_html(): string
{
    $ids = tracking_ids();
    if ($ids['ga'] === '' && $ids['ads'] === '') {
        return '';
    }
    $privacy = rtrim(trim((string)config('marketing_url', '')), '/');
    $privacy = $privacy !== '' ? $privacy . '/datenschutz' : '';
    return sprintf(
        '<script src="%s" data-ga="%s" data-ads="%s" data-privacy="%s" defer></script>',
        e(asset_url('assets/js/consent.js')),
        e($ids['ga']),
        e($ids['ads']),
        e($privacy)
    );
}
