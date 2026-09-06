<?php
/**
 * SmartEinzug (SEPA-Portal, Betreiber Müller Holding AG), Konfiguration
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
    // Token für den Migrationsaufruf nach dem Upload: GitHub Actions ruft migrate.php per
    // POST mit dem Header X-Migration-Token auf. Eigener Wert (mind. 32 Zufallszeichen),
    // unabhängig von cron_token und allen anderen Schlüsseln; identisch mit dem
    // GitHub-Secret MIGRATION_TOKEN. Leer = Endpunkt ist gesperrt (server_configuration_error).
    'migration_token' => 'HIER-MIGRATIONSTOKEN-64-ZUFALLSZEICHEN',
    // Empfängeradresse für Support-Anfragen aus dem Hilfe-Center (leer = operator.email)
    'support_email' => '',
    // Zeitbudget je Cron-Aufruf in Sekunden. Externe Cron-Dienste (cron-job.org) brechen oft nach 30 s ab,
    // deshalb Standard 20 (ein laufender Schritt darf kurz überziehen). Die Synchronisation setzt beim nächsten Aufruf fort.
    'cron_time_budget_seconds' => 20,

    // --- Basis-URL der Anwendung (ohne Slash am Ende) ---
    // Bisheriger Schlüssel, bleibt als Rückfallwert für app_base_url erhalten.
    'base_url' => 'https://app.smart-einzug.de',

    // --- Getrennte Basisadressen (alle ohne Slash am Ende) ---
    // app_base_url: Adresse der Kundenanwendung. Wird in allen absoluten Links
    //   verwendet (E-Mail-Bestätigung, Passwort-Zurücksetzen, Einladungen,
    //   Rückkehr aus dem Stripe Checkout, Anzeige der Webhook-Adresse).
    //   Leer = base_url wird verwendet (Hilfsfunktion app_base_url()).
    // admin_base_url: Adresse des Adminbereichs (admin.php). Leer = Übergangsmodus,
    //   admin.php ist auf demselben Host wie die Anwendung erreichbar. Gesetzt =
    //   admin.php antwortet nur auf diesem Host; auf dem Adminhost sind nur
    //   Anmeldung, 2FA, Passwort-Zurücksetzen, Sicherheit, Abmelden und die
    //   Assets erreichbar, alle Kundenseiten liefern dort 404.
    // public_base_url: öffentliche Produktwebsite (Marketing, Preise, Hilfe).
    'app_base_url'    => '',
    'admin_base_url'  => '',
    'public_base_url' => 'https://smart-einzug.de',

    // --- Erlaubte Hostnamen (Allowlist) ---
    // Leer = keine Prüfung (Übergangsmodus). Gefüllt = jede Anfrage, deren
    // Host-Header nicht in der Liste steht, erhält HTTP 404. Ausgenommen sind
    // cron.php, stripe-webhook.php, billing-webhook.php und track.php, die sich
    // selbst über Token oder Signatur absichern. Nur Hostnamen ohne Schema und
    // ohne Port eintragen.
    //
    // Migrationsreihenfolge beim Umzug auf neue Hosts (siehe ANLEITUNG-IONOS.md):
    //   1. DNS und SSL der neuen Hosts bei IONOS einrichten und beide Hosts auf
    //      diesen App-Ordner zeigen lassen.
    //   2. Die neuen Hosts hier in allowed_hosts ERGÄNZEN, die alten Hosts
    //      stehen lassen. Erst prüfen, dass alte und neue Adresse antworten.
    //   3. Danach app_base_url (bzw. base_url) auf die neue Adresse umstellen.
    //   4. Stripe-Webhook-Endpunkte zusätzlich für die neue Adresse anlegen,
    //      die alten Endpunkte bleiben aktiv.
    //   5. Zuletzt admin_base_url setzen; erst dann ist der Adminbereich vom
    //      Kundenhost getrennt.
    // Beispiel im Zielzustand:
    //   'allowed_hosts' => ['app.smart-einzug.de', 'admin.smart-einzug.de'],
    'allowed_hosts' => [],

    // --- Produktname (erscheint in Titel, E-Mails, 2FA-App) ---
    'product_name' => 'SmartEinzug',

    // --- Marketing-Webseite (für Links auf Impressum/Datenschutz/AGB, ohne Slash) ---
    // Wird als Rückfallwert für public_base_url weiter ausgewertet.
    'marketing_url' => 'https://smart-einzug.de',

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
    'signup_domains' => ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de', 'lastschrift-einfach.de'],

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
        'from_name'    => 'SmartEinzug',
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
        'automatic_tax'          => true,    // Stripe Tax berechnet die USt (Stripe Tax im Konto aktivieren, Preis mit tax_behavior exclusive)
        'vat_rate_percent'       => 19,      // nur für die Anzeige des Bruttobetrags in der Anwendung
    ],

    // --- Einreichregeln für SEPA-Einzüge (Plattformregel, gilt für alle Firmen) ---
    // grace_hours: Karenzzeit in Stunden zwischen Auslösen und Einreichung bei Stripe
    //   (Sofort-Einzüge werden "vorgemerkt" und sind bis zur Einreichung stornierbar).
    // window_enabled/window_start/window_end: Einreichung nur im Zeitfenster (darf über
    //   Mitternacht gehen). Der Cron reicht im Fenster bei jedem Lauf einen Teil ein.
    // overdue_days: Termine, die länger zurückliegen, werden nicht automatisch nachgeholt,
    //   sondern als überfällig angezeigt (neu terminieren oder stornieren).
    'collections' => [
        'grace_hours'    => 4,
        'window_enabled' => true,
        'window_start'   => '23:00',
        'window_end'     => '06:00',
        'overdue_days'   => 3,
    ],

    // --- Synchronisation mit Lexware Office ---
    // step_seconds: Zeitbudget je Synchronisationsschritt (ein Browser- oder Cron-Aufruf
    //   verarbeitet so viele Rechnungen, wie in dieser Zeit möglich sind, höchstens step_max).
    // skip_unchanged: Rechnungen, deren updatedDate laut Voucherliste unverändert ist,
    //   werden ohne Detailabruf übersprungen (false = altes Verhalten, jede Rechnung einzeln).
    // contact_refresh_hours: Kontaktdaten bekannter Kunden höchstens so oft neu laden (0 = immer).
    // Systemmonitoring (Adminbereich System, Auftrag II Abschnitt 7). Alle Werte optional.
    'monitoring' => [
        'cron_interval_seconds' => 300,       // Sollintervall des externen Cron-Aufrufs (cron-job.org)
        'collect_interval_seconds' => 240,    // Mindestabstand der Dienstprüfungen (laufen im Cron)
        'tls_warn_days' => 14,                // Warnung vor Zertifikatsablauf
        'tls_hosts' => [],                    // leer = Hosts aus app_base_url, admin_base_url, public_base_url
        'alert_emails' => [],                 // Plattformadministratoren für Störungs- und Entwarnungsmails; leer = nicht eingerichtet
        'editors' => [],                      // E-Mail-Adressen der Superadmins mit Bearbeitungsrecht (Meldungen, Testversand); leer = alle Superadmins
        'test_mail_to' => '',                 // feste eigene Testadresse für den manuellen Testversand; niemals Kundenadressen
        'public_min_coverage_pct' => 99,      // Mindest-Messabdeckung für öffentliche Prozentwerte (Produkteinstellung)
        'tariff_limits' => [],                // manuell hinterlegte Tariflimits mit Quelle und Datum, z.B. ['php_memory_mb' => ['value' => 512, 'source' => 'IONOS Tarifübersicht', 'date' => '06.09.2026']]
    ],
    // --- Hintergrundverarbeitung (Auftrag III): Warteschlange, Worker, Scheduler ---
    // Die Warteschlange ist ein Feature-Flag (siehe 'features' => ['queue' => ...]). Auf dem Webhosting bleibt
    // sie aus; der Cron arbeitet wie bisher. Auf dem VPS laufen Scheduler- und Worker-Container.
    'queue' => [
        'sync_attempt_seconds'   => 600,  // Zeitbudget je Verarbeitungsversuch einer Synchronisation, danach Fortsetzung
        'sync_max_steps_attempt' => 60,
        'collections_seconds'    => 120,  // Zeitbudget je Durchlauf der Einzugsverarbeitung
        'auto_sync_hours'        => 6,    // regelmäßiger Delta-Abgleich je Firma (NORMAL)
        'full_sync_hour'         => 3,    // Stunde des nächtlichen Vollabgleichs (LOW), lokale Zeit
        'prune_days'             => 30,   // abgeschlossene Jobs aufbewahren
        'lexoffice_per_second'   => 2,    // Aufrufe je Sekunde JE API-Schlüssel, also je Firma (Annahme zur Lexware-API, zu verifizieren)
        'lexoffice_global_per_second' => 50, // Obergrenze über alle Firmen zusammen (Schutz der eigenen Worker), 0 = keine
        'stripe_per_second'      => 20,   // Aufrufe je Sekunde je Stripe-Konto (Firma bzw. Plattformkonto)
        'stripe_global_per_second' => 200, // Obergrenze über alle Konten zusammen, 0 = keine
        'stripe_per_second'      => 20,
        'circuit' => ['threshold' => 5, 'open_seconds' => 300, 'probe_seconds' => 60],
    ],
    // Redis (optional): Sperren, Ratenbegrenzung, Cache. Ohne Angabe läuft alles über MariaDB.
    'redis' => null, // z.B. ['host' => 'redis', 'port' => 6379, 'password' => null, 'prefix' => 'se:']
    // Strukturiertes Logging: 'stderr' (Docker), 'file' (app/storage/logs) oder 'error_log'
    'log' => ['target' => 'file'],
    // Vertrauenswürdige Reverse Proxys: nur Anfragen von diesen Adressen dürfen X-Forwarded-Proto/-For setzen.
    // Hostinger-VPS mit Coolify: TLS endet am Coolify-Proxy (Traefik), der über das Docker-Netz an Caddy und
    // php-fpm weiterreicht. Dort die Docker-Netzbereiche eintragen, z.B. ['172.16.0.0/12', '10.0.0.0/8']
    // (mit "docker network inspect" prüfen). IONOS Webhosting: leer lassen.
    'trusted_proxies' => [],
    // Schreibbares Speicherverzeichnis außerhalb des Codes (VPS: /opt/smarteinzug/shared/storage); leer = app/storage
    'storage_dir' => '',
    // Wartungsmodus für den Cutover (alternativ Datei <storage_dir>/maintenance.flag anlegen)
    'maintenance_mode' => false,
    // Öffentliche Statusseite (Abschnitt 8): Adresse für Links; leer = keine Links anzeigen
    'status_page_url' => '',
    // Veröffentlichung des Status-Snapshots (nur erlaubte Felder). Ziele optional:
    //   'file'   => absoluter Pfad zu status.json auf demselben Webspace (nicht unabhängig von IONOS)
    //   'github' => ['owner' => '', 'repo' => '', 'path' => 'status.json', 'branch' => 'main', 'token' => 'HIER-FINE-GRAINED-TOKEN-NUR-CONTENTS-WRITE']
    'status_publish' => [],
    'sync' => [
        'step_seconds'          => 8,
        'step_max'              => 40,
        'step_max_api_calls'    => 60,   // höchstens so viele Lexware-Aufrufe je Schritt
        'skip_unchanged'        => true,
        'contact_refresh_hours' => 24,
    ],

    // --- Feature-Schalter (Standard: aus) ---
    // mandate_request: digitale Mandatsanforderung (Link per E-Mail, Stripe
    // Checkout im Modus setup, öffentliche Seite mandat.php). Erst nach
    // erfolgreichem Test im Stripe-Testmodus aktivieren, siehe docs/payment-safety.md.
    'features' => [
        'mandate_request' => false,
        // queue: Hintergrundverarbeitung über Warteschlange und Worker. true = alle Firmen,
        // Liste von Firmen-IDs = nur diese Firmen (zusätzlich je Firma über organizations.feature_flags), false = aus.
        'queue' => false,
    ],

    // --- Zeitzone ---
    'timezone' => 'Europe/Berlin',
];
