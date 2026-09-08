<?php
/**
 * Freigabeschalter je angekündigter Integration (zuerst sevdesk), gespeichert in platform_settings.
 *
 * Ein öffentlicher Status plus getrennte technische Freigaben, die ihn ergänzen müssen (Masterplan, Abschnitt 12):
 *   <code>_public_state   angekuendigt | beta | verfuegbar | eingeschraenkt   (Standard angekuendigt)
 *   <code>_waitlist       1 = Vormerkung möglich (Standard 1)
 *   <code>_connect        1 = Verbindung für freigegebene Firmen möglich (Standard 0)
 *   <code>_collections    1 = neue Einzüge für diese Integration (Standard 0); 0 wirkt wie ein Not-Aus nur für diesen
 *                         Anbieter: laufende Vorgänge, Webhooks und Abstimmung laufen weiter (siehe collections.php)
 *   <code>_writeback      1 = Rückschreibung in das Buchhaltungssystem (Standard 0)
 * Setzen nur serverseitig (SQL oder Skript), wie der plattformweite Not-Stopp; Anzeige im Adminbereich.
 */
if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

const INTEGRATION_PUBLIC_STATES = ['angekuendigt', 'beta', 'verfuegbar', 'eingeschraenkt'];
const INTEGRATION_SWITCHES = ['waitlist' => true, 'connect' => false, 'collections' => false, 'writeback' => false];

/** Roher Wert aus platform_settings ohne die schwere collections.php einzubinden. */
function integration_setting(string $key, ?string $default = null): ?string
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM platform_settings WHERE `key` = ?');
        $stmt->execute([mb_substr($key, 0, 64)]);
        $v = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return $v === false || $v === null ? $default : (string)$v;
}

function integration_public_state(string $code): string
{
    $v = (string)integration_setting($code . '_public_state', 'angekuendigt');
    return in_array($v, INTEGRATION_PUBLIC_STATES, true) ? $v : 'angekuendigt';
}

function integration_switch(string $code, string $name): bool
{
    $default = INTEGRATION_SWITCHES[$name] ?? false;
    $v = integration_setting($code . '_' . $name);
    if ($v === null && $name === 'connect') {
        // Automatische Freigabe der Verbindung ab dem Freigabetermin (<code>_release_at, Kalendertag Europe/Berlin);
        // ein ausdruecklich gesetzter Wert 0 oder 1 hat immer Vorrang. Der Termin gibt NUR das Verbinden und Lesen
        // frei, nie den Einzug: sevdesk_collections bleibt ein eigener Schalter (Standard 0).
        $release = integration_release_at($code);
        return $release !== null && integration_release_reached($release);
    }
    return $v === null ? $default : $v === '1';
}

/** Freigabetermin (JJJJ-MM-TT) aus platform_settings <code>_release_at oder null. */
function integration_release_at(string $code): ?string
{
    $v = trim((string)integration_setting($code . '_release_at', ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/** Ist der Freigabetermin (00:00 Uhr Europe/Berlin) erreicht? Testhaken: $GLOBALS['integration_now'] (Unixzeit). */
function integration_release_reached(string $date, ?int $now = null): bool
{
    $now = $now ?? (isset($GLOBALS['integration_now']) ? (int)$GLOBALS['integration_now'] : time());
    try {
        $release = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Berlin'));
    } catch (Throwable $e) {
        return false;
    }
    return $now >= $release->getTimestamp();
}

/** Wortlaut des Freigabezustands fuer Anzeigen (Adminbereich, Einstellungen). */
function integration_connect_state_text(string $code): string
{
    $v = integration_setting($code . '_connect');
    $release = integration_release_at($code);
    if ($v === '1') {
        return 'freigegeben (Schalter ' . $code . '_connect = 1)';
    }
    if ($v === '0') {
        return 'gesperrt (Schalter ' . $code . '_connect = 0, Freigabetermin ausgesetzt)';
    }
    if ($release === null) {
        return 'nicht freigegeben (kein Schalter, kein Freigabetermin)';
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $release);
    $txt = $d ? $d->format('d.m.Y') : $release;
    return integration_release_reached($release) ? 'automatisch freigegeben seit ' . $txt : 'automatische Freigabe am ' . $txt;
}

/** Alle Schalter eines Anbieters für die Anzeige. */
function integration_switches(string $code): array
{
    $out = ['public_state' => integration_public_state($code)];
    foreach (array_keys(INTEGRATION_SWITCHES) as $n) {
        $out[$n] = integration_switch($code, $n);
    }
    $out['release_at'] = integration_release_at($code);
    $out['connect_text'] = integration_connect_state_text($code);
    $out['api_verified'] = integration_setting($code . '_api_verified') === '1';
    return $out;
}
