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
    return $v === null ? $default : $v === '1';
}

/** Alle Schalter eines Anbieters für die Anzeige. */
function integration_switches(string $code): array
{
    $out = ['public_state' => integration_public_state($code)];
    foreach (array_keys(INTEGRATION_SWITCHES) as $n) {
        $out[$n] = integration_switch($code, $n);
    }
    return $out;
}
