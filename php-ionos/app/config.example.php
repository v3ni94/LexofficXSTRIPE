<?php
/**
 * Lexware-Einzug (SEPA-Portal) – Konfiguration
 *
 * Diese Datei nach config.php kopieren und Werte eintragen.
 * config.php wird per .htaccess vor Webzugriff geschützt und darf
 * NICHT ins Git eingecheckt werden.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    // --- MariaDB (Zugangsdaten aus dem IONOS Kundenbereich: Hosting > Datenbanken) ---
    'db' => [
        'host'    => 'db5001234567.hosting-data.io', // IONOS DB-Hostname
        'port'    => 3306,                           // Standard-Port, nur ändern wenn der Anbieter einen anderen nennt
        'name'    => 'dbs1234567',                   // Datenbankname
        'user'    => 'dbu1234567',                   // Datenbank-Benutzer
        'pass'    => 'HIER-DB-PASSWORT',
        'charset' => 'utf8mb4',
    ],

    // --- Anwendungs-Secret (mind. 64 Zufallszeichen) ---
    // Dient zur Verschlüsselung der API-Keys und 2FA-Geheimnisse in der Datenbank.
    // Generieren z.B. mit: bash -c "openssl rand -hex 32"
    // ACHTUNG: Nach Inbetriebnahme nicht mehr ändern, sonst sind gespeicherte
    // API-Keys und 2FA-Geheimnisse nicht mehr entschlüsselbar.
    'app_secret' => 'HIER-64-ZUFALLSZEICHEN-EINTRAGEN',

    // --- Cron-Token (Schutz für cron.php, mind. 32 Zufallszeichen) ---
    'cron_token' => 'HIER-32-ZUFALLSZEICHEN-EINTRAGEN',

    // --- Basis-URL der Anwendung (ohne Slash am Ende) ---
    'base_url' => 'https://app.lexware-einzug.de',

    // --- Produktname (erscheint in Titel, E-Mails, 2FA-App) ---
    'product_name' => 'Lexware-Einzug',

    // --- Marketing-Webseite (für Links auf Impressum/Datenschutz/AGB, ohne Slash) ---
    'marketing_url' => 'https://lexware-einzug.de',

    // --- Betreiber der Plattform (Impressum in der Anwendung) ---
    // Verbindliche Stammdaten der Müller Holding AG. Platzhalter in eckigen
    // Klammern vor Inbetriebnahme ersetzen oder leer lassen.
    'operator' => [
        'name'        => 'Müller Holding AG',
        'street'      => 'Rheinpromenade 13',
        'zip_city'    => '40789 Monheim am Rhein',
        'email'       => 'info@mueller-holding.ag',
        'phone'       => '',                         // z.B. '+49 2173 ...' (Platzhalter, vor Veröffentlichung eintragen)
        'register'    => 'Amtsgericht Düsseldorf, HRB 104291',
        'board'       => 'Timo Müller',              // Vorstand
        'supervisory' => 'Jan Walprecht',            // Aufsichtsratsvorsitzender
        'vat_id'      => '',                         // USt-IdNr., falls vorhanden
        'web'         => 'mueller-holding.ag',
    ],

    // --- Erlaubte Herkunftsdomains für die Registrierung (signup_domain) ---
    'signup_domains' => ['lexware-einzug.de', 'lexoffice-einzug.de'],

    // --- Registrierung neuer Firmen erlauben? ---
    'allow_registration' => true,

    // --- Zwei-Faktor-Authentifizierung für alle Benutzer erzwingen (Pflicht) ---
    // Nur für Notfälle auf false setzen (z.B. Authenticator aller Nutzer verloren).
    'require_2fa' => true,

    // --- E-Mail-Versand (Einladungen, Verifizierung, Sicherheitshinweise) ---
    // transport 'mail' nutzt die PHP-Funktion mail() des Hostings (bei IONOS
    // muss die Absenderadresse zu einer Domain des Pakets gehören).
    // transport 'log' schreibt E-Mails nur in eine Datei (Test).
    // enabled=false: es werden keine E-Mails versendet; Einladungslinks werden
    // dann im Portal angezeigt und die E-Mail-Verifizierung entfällt.
    // transport 'smtp' versendet über ein Postfach mit Benutzername und Passwort
    // (IONOS: smtp.ionos.de, Port 587, encryption 'tls'; Benutzer = volle E-Mail-Adresse).
    'mail' => [
        'enabled'      => false,
        'transport'    => 'smtp',
        'from_address' => 'noreply@lexware-einzug.de',   // muss zum SMTP-Postfach passen
        'from_name'    => 'Lexware-Einzug',
        'reply_to'     => 'info@mueller-holding.ag',
        'log_file'     => __DIR__ . '/../mail.log',
        'smtp' => [
            'host'       => 'smtp.ionos.de',
            'port'       => 587,
            'encryption' => 'tls',                       // 'tls' (Port 587) | 'ssl' (Port 465)
            'user'       => 'noreply@lexware-einzug.de',
            'pass'       => 'HIER-POSTFACH-PASSWORT',
        ],
    ],

    // --- Lexware Office Public API ---
    // Kanonische Basis-URL laut Lexware-Dokumentation. Die frühere Domain
    // api.lexoffice.io wird bei Verbindungsfehlern automatisch als Ausweich-
    // adresse versucht.
    'lexware_api_base_url' => 'https://api.lexware.io/v1',

    // --- Stripe-Mandatsreferenz mit Firmenpräfix beginnen lassen ---
    // Nutzt die Stripe-Option mandate_options.reference_prefix (verfügbar ab
    // Stripe-API-Version 2024-12-18). Nur aktivieren, nachdem ein Test-Einzug
    // erfolgreich war.
    'stripe_mandate_reference_prefix' => false,

    // --- Plattform-Abrechnung (Abo 25 EUR je 4 Wochen über Stripe Checkout) ---
    // Schlüssel des Plattform-Stripe-Kontos der Müller Holding AG, NICHT die
    // Kundenschlüssel. Solange enabled=false ist, arbeiten alle Firmen ohne
    // Abo-Prüfung (Status 'pending' wird nicht erzwungen).
    'billing' => [
        'enabled'                => false,
        'stripe_secret_key'      => '',      // sk_live_... des Plattform-Kontos
        'stripe_webhook_secret'  => '',      // whsec_... für billing-webhook.php
    ],

    // --- Zeitzone ---
    'timezone' => 'Europe/Berlin',
];
