<?php
/**
 * Versionsstand der Anwendung und Änderungsverlauf (Versionsübersicht im Adminbereich).
 *
 * Schema: erste Stelle für große Ausbaustufen (1.0, 2.0, 3.0 ...), zweite Stelle für kleinere
 * Ergänzungen und Korrekturen (2.1, 2.2 ...). Jeder Eintrag nennt Datum, Art (Neu, Geändert, Behoben)
 * und kurze Erklärung. Bei jedem Release die Konstante APP_VERSION und die Liste ergänzen.
 */
declare(strict_types=1);

const APP_VERSION = '4.1';

/** Änderungsverlauf, neueste Version zuerst. */
function app_changelog(): array
{
    return [
        ['version' => '4.1', 'date' => '06.09.2026', 'title' => 'Tarifwechsel, Upsell und Hostinger-VPS',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Tarifwechsel durch den Inhaber unter Firma > Abonnement (Upgrade sofort mit anteiliger Berechnung, Downgrade mit Gutschrift, Downgrade-Schutz für Benutzer), Bestellbestätigung und Protokoll wie beim Abschluss (Migration 019).'],
            ['type' => 'Neu', 'text' => 'Upsell bei erreichten Grenzen: Hinweis auf den nächsthöheren Tarif beim Benutzerlimit, ab 80 Prozent und bei ausgeschöpftem Einzugskontingent, einmal je Periode auch per E-Mail an den Inhaber. Erscheint nur, wenn mindestens zwei Tarife aktiv sind.'],
            ['type' => 'Geändert', 'text' => 'VPS-Stack auf Hostinger KVM 8 mit Coolify ausgerichtet: TLS am Coolify-Proxy, Caddy als interner HTTP-Server, Ressourcenlimits für 8 vCPU und 32 GB, Einrichtungsanleitung Kapitel 08.'],
         ]],
        ['version' => '4.0', 'date' => '06.09.2026', 'title' => 'Hintergrundverarbeitung und VPS-Migration vorbereitet',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Zentrale Job-Queue mit Prioritäten, Wiederholungen mit gestaffeltem Backoff, Dead-Letter-Ansicht im Admin, Worker mit Heartbeat, Scheduler, Circuit Breaker je Anbindung (Migration 018).'],
            ['type' => 'Neu', 'text' => 'Synchronisationshistorie je Firma mit Details (Dauer, Mengen, API-Aufrufe, Fehler) und Live-Fortschritt.'],
            ['type' => 'Neu', 'text' => 'E-Mail-Versand über die Warteschlange, Wartungsmodus je Firma für die Synchronisation, Feature-Flags je Firma.'],
            ['type' => 'Neu', 'text' => 'Strukturiertes Logging mit Correlation-ID über Webanfrage, Job, Worker und Audit.'],
            ['type' => 'Neu', 'text' => 'Docker-Stack für den IONOS VPS (Caddy, PHP-FPM, Worker, Scheduler, MariaDB, Redis, Backup, Host-Metriken), Deployment über SSH mit Rollback, Staging-Konfiguration.'],
            ['type' => 'Neu', 'text' => 'Adminbereich System: Reiter Jobs, Server, Versionen, Dokumentation; technische Dokumentation mit Diagrammen als PDF.'],
            ['type' => 'Geändert', 'text' => 'Bestehende Cron-Verarbeitung bleibt auf dem Webhosting erhalten; die Queue ist dort standardmäßig ausgeschaltet.'],
            ['type' => 'Behoben', 'text' => 'Adversariale Abnahme: X-Forwarded-For wird von rechts ausgewertet und validiert; Wartungsmodus und Adminhost-Trennung richten sich nach dem ausgeführten Skript, nicht nach der URL; Wartungsmodus pausiert Scheduler und Worker; Correlation-ID nur von vertrauenswürdigen Proxys.'],
            ['type' => 'Behoben', 'text' => 'Ratenbegrenzung reserviert Kontingent im Zielfenster; Maskierung von Webhook- und API-Schlüsseln in Protokollen; keine Empfängeradressen im Fehlerprotokoll; fehlgeschlagene Mail-Jobs ohne Nachrichteninhalt.'],
            ['type' => 'Behoben', 'text' => 'Container sehen nur Releases, Konfiguration und Speicher (kein Wurzeldateisystem, kein deploy/.env); Rollback prüft die Verträglichkeit mit dem Migrationsstand; kontrolliertes Beenden laufender Jobs beim Deployment (660 s).'],
         ]],
        ['version' => '3.4', 'date' => '06.09.2026', 'title' => 'Gerätefreigabe, Systemmonitoring, Statusseite',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Zwei-Faktor: Gerät für 90 Tage merken mit fester Gültigkeit, Verwaltung unter Sicherheit, Widerruf bei Passwort- und 2FA-Änderungen (Migration 016).'],
            ['type' => 'Neu', 'text' => 'Adminbereich System mit ehrlichen Messwerten, Zeitfenstern, Verfügbarkeit und Störungsverwaltung (Migration 017).'],
            ['type' => 'Neu', 'text' => 'Öffentliche Statusseite vorbereitet (status.smart-einzug.de), Snapshot mit Positivliste.'],
         ]],
        ['version' => '3.3', 'date' => '06.09.2026', 'title' => 'Multiaccount und Registrierung',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Multiaccount-Schalter im Profil, Registrierung mit bereits bekannter E-Mail-Adresse, Dublettenprüfung mit Sperren (Migration 015).'],
         ]],
        ['version' => '3.2', 'date' => '06.09.2026', 'title' => 'Navigation bereinigt',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Kopfbereich ohne Dubletten zum Profilmenü, Team wird Firmendaten, Firmen wird Firmenübersicht, Exportbutton geprüft.'],
         ]],
        ['version' => '3.1', 'date' => '06.09.2026', 'title' => 'Synchronisierung gegen Doppelstarts',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Sperre mit Inhaber je Schritt, Zähler übersprungener Doppelstarts, API-Aufrufbudget je Schritt, Backoff mit Retry-After (Migration 014).'],
         ]],
        ['version' => '3.0', 'date' => '06.09.2026', 'title' => 'Abgesicherter Migrationsaufruf durch GitHub',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'migrate.php nur per POST mit Header X-Migration-Token, gemeinsame Sperre, Fehler werden nie automatisch wiederholt, Cron migriert nicht mehr.'],
            ['type' => 'Neu', 'text' => 'Abonnement vorbereitet: Bestellbestätigung mit AGB-Zustimmung, Rechnungsarchiv aus Stripe.'],
         ]],
        ['version' => '2.4', 'date' => '06.09.2026', 'title' => 'Synchronisation beschleunigt',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Änderungserkennung über updatedDate, seltenere Kontaktabrufe, zeitbasierte Schritte, Messwerte (Migration 013).'],
         ]],
        ['version' => '2.3', 'date' => '06.09.2026', 'title' => 'Hilfe-Center',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Anleitungen, häufige Fragen und Support-Anfragen mit Tickets (Migration 012).'],
         ]],
        ['version' => '2.2', 'date' => '06.09.2026', 'title' => 'Karenzzeit und Einreichfenster',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Karenzzeit vor dem Einzug, Nachtfenster, Storno im Status Vorgemerkt, Not-Stopp mit Sammelstorno (Migration 011).'],
            ['type' => 'Behoben', 'text' => 'Umterminieren setzt die Vormerkung zurück, Support-Sperre für Einreichungen, Asset-Versionierung gegen Browser-Cache.'],
         ]],
        ['version' => '2.1', 'date' => '05.09.2026', 'title' => 'Profil, Dashboard, Stripe-Import',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Profilmenü mit Bild und Telefonnummern, fünf Dashboard-Karten, Gläubiger-ID optional (Migration 010).'],
            ['type' => 'Neu', 'text' => 'Bestehende Einzüge aus Stripe übernehmen (Migration 009), Tarifeditor, Support-Bereich mit Firmenzugriff (Migration 008), automatischer Upload über GitHub.'],
         ]],
        ['version' => '2.0', 'date' => '05.09.2026', 'title' => 'SmartEinzug: Marke, Websites, Host-Trennung',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Hauptwebsite smart-einzug.de, Alias-Weiterleitungen, eigenständige Inhalte je Domain, app. und admin. als getrennte Hosts.'],
            ['type' => 'Neu', 'text' => 'Zahlungsqualität: Erstattungen aus Stripe, Klärung unklarer Versuche, Alarmierung, Zweitbestätigung kritischer Aktionen, Mandatsdokumente.'],
         ]],
        ['version' => '1.1', 'date' => '04.09.2026', 'title' => 'SaaS-Ausbau',
         'entries' => [
            ['type' => 'Neu', 'text' => '2FA-Pflicht, Rollen, Tarife, Audit, Plattform-Abrechnung mit Stripe Tax, Marketingseiten mit Einwilligungsbanner.'],
            ['type' => 'Behoben', 'text' => 'Sicherheitskorrekturen nach adversarialer Prüfung.'],
         ]],
        ['version' => '1.0', 'date' => '31.08.2026', 'title' => 'Erste Fassung für IONOS Webhosting',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Lexware-Office-Synchronisation in fortsetzbaren Schritten, SEPA-Einzug je Kunde, Sammel-Einzug, mehrere Firmen je Konto, HVM-CI, Setup-Prüfung.'],
         ]],
    ];
}

/** Build-Informationen aus app/build.txt (vom Deployment geschrieben) oder null. */
function app_build_info(): ?string
{
    $f = __DIR__ . '/build.txt';
    $v = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    return $v !== '' ? $v : null;
}
