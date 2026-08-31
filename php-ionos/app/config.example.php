<?php
/**
 * HVM SEPA-Portal – Konfiguration
 *
 * Diese Datei nach config.php kopieren und Werte eintragen.
 * config.php wird per .htaccess vor Webzugriff geschützt und darf
 * NICHT ins Git eingecheckt werden.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    // --- MariaDB (Zugangsdaten aus dem IONOS Kundenbereich: Hosting > Datenbanken) ---
    'db' => [
        'host'    => 'db5001234567.hosting-data.io', // IONOS DB-Hostname
        'name'    => 'dbs1234567',                   // Datenbankname
        'user'    => 'dbu1234567',                   // Datenbank-Benutzer
        'pass'    => 'HIER-DB-PASSWORT',
        'charset' => 'utf8mb4',
    ],

    // --- Anwendungs-Secret (mind. 64 Zufallszeichen) ---
    // Dient zur Verschlüsselung der API-Keys in der Datenbank.
    // Generieren z.B. mit: bash -c "openssl rand -hex 32"
    // ACHTUNG: Nach Inbetriebnahme nicht mehr ändern, sonst sind
    // gespeicherte API-Keys nicht mehr entschlüsselbar.
    'app_secret' => 'HIER-64-ZUFALLSZEICHEN-EINTRAGEN',

    // --- Cron-Token (Schutz für cron.php, mind. 32 Zufallszeichen) ---
    'cron_token' => 'HIER-32-ZUFALLSZEICHEN-EINTRAGEN',

    // --- Basis-URL der Anwendung (ohne Slash am Ende) ---
    'base_url' => 'https://sepa.muellerhv.de',

    // --- Registrierung neuer Organisationen erlauben? ---
    // Nach Einrichtung des eigenen Kontos auf false setzen,
    // damit sich keine Fremden registrieren können.
    'allow_registration' => true,

    // --- Zeitzone ---
    'timezone' => 'Europe/Berlin',
];
