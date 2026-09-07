<?php
/**
 * Versionsstand der Anwendung und Änderungsverlauf (Versionsübersicht im Adminbereich).
 *
 * Schema: erste Stelle für große Ausbaustufen (1.0, 2.0, 3.0 ...), zweite Stelle für kleinere
 * Ergänzungen und Korrekturen (2.1, 2.2 ...). Jeder Eintrag nennt Datum, Art (Neu, Geändert, Behoben)
 * und kurze Erklärung. Bei jedem Release die Konstante APP_VERSION und die Liste ergänzen.
 */
declare(strict_types=1);

const APP_VERSION = '4.9';

/** Änderungsverlauf, neueste Version zuerst. */
function app_changelog(): array
{
    return [
        ['version' => '4.9', 'date' => '07.09.2026', 'title' => 'Redis-Infrastruktur deploybar machen (Bootstrap-Problem behoben)',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der Redis-protected-mode-Fix (Version 4.8) konnte sich nicht selbst deployen: Die Candidate-Prüfung kommuniziert mit dem bereits laufenden redis-Dienst, der aber wegen des Bind-Mounts von redis.conf nicht automatisch neu erzeugt wird, nur weil sich der Dateiinhalt geändert hat. Der Candidate mit neuem Code prüfte deshalb weiterhin gegen den alten, unveränderten Redis-Container. deploy.sh stellt jetzt vor der Candidate-Prüfung fest, ob sich redis.conf gegenüber dem laufenden Release geändert hat: unverändert bleibt Redis unberührt; geändert wird ausschließlich der redis-Dienst vorab validiert, gezielt neu erzeugt (kein anderer Dienst betroffen), auf healthy geprüft und die Erreichbarkeit aus einem ANDEREN Container über das interne Netz bestätigt (nicht per "docker exec redis redis-cli ping", das genau das protected-mode-Problem verborgen hatte) - erst danach beginnt die eigentliche Candidate-Prüfung.'],
            ['type' => 'Neu', 'text' => 'Schlägt die Redis-Aktualisierung fehl (ungültige Konfiguration, Recreate schlägt fehl, nicht healthy, oder healthy aber über das Netz blockiert), wird ausschließlich die Redis-Infrastruktur auf die vorherige Konfiguration zurückgesetzt und deren Erreichbarkeit erneut bestätigt; das Deployment bricht danach ab, ohne Migration und ohne Cutover, die übrige laufende Anwendung bleibt unverändert.'],
            ['type' => 'Neu', 'text' => 'Vor jeder Redis-Änderung wird zusätzlich geprüft, dass Redis keinen veröffentlichten Host-Port hat, ausschließlich am internen Netz smarteinzug_internal hängt (nicht am öffentlichen Coolify-Netz) und keine Traefik-Labels trägt - Voraussetzung dafür, dass protected-mode no vertretbar bleibt; verletzt eine dieser Bedingungen, bricht das Deployment ab, bevor irgendetwas an Redis geändert wird.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/redis-deploy-check.sh (kein Docker-Daemon nötig): simuliert /opt/smarteinzug mit einem steuerbaren Fake-"docker", gegen den die tatsächliche deploy.sh unverändert läuft; bestätigt u. a. kein Recreate bei unveränderter redis.conf, kontrollierte Aktualisierung vor der Candidate-Prüfung bei geänderter redis.conf, Abbruch mit bestätigtem Rollback bei über das Netz blockiertem Redis, Erkennung einer ungültigen Konfiguration bereits in der Vorab-Validierung, Abbruch bei verletzten Netzwerk-Isolationsvorgaben, Idempotenz bei Wiederholung. tools/staging-isolation-check.py bestätigt zusätzlich die Redis-Netzwerk-Isolation in Produktion und Staging.'],
         ]],
        ['version' => '4.8', 'date' => '07.09.2026', 'title' => 'Tatsächliche Ursache des Redis-Fehlschlags: protected mode',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Die Candidate-Prüfung meldete weiterhin einen Redis-Fehlschlag ("redis: auth" bzw. zuvor "redis: other"), obwohl config.php korrekt kein Passwort und redis.conf kein requirepass enthielt und "docker exec smarteinzug-redis-1 redis-cli ping" PONG lieferte. Tatsächliche, durch einen echten temporären Redis-Server bestätigte Ursache: redis.conf setzte protected-mode yes ohne Passwort; in dieser Kombination lehnt Redis jeden Befehl (nicht die TCP-Verbindung) eines NICHT über Loopback verbindenden Clients ab, also jeden Zugriff aus einem anderen Container. "docker exec ... redis-cli ping" lief dagegen selbst über Loopback und täuschte deshalb Gesundheit vor, die für keinen anderen Container galt. redis.conf setzt jetzt protected-mode no (sicher, da Redis ohnehin nur im internen, nicht öffentlich erreichbaren Docker-Netz erreichbar ist).'],
            ['type' => 'Geändert', 'text' => 'monitor_category() erkennt die Redis-protected-mode-Meldung als eigene Kategorie redis_protected_mode statt sie fälschlich als auth (falsches Passwort in der eigenen Konfiguration vermutet, was hier nie die Ursache war) oder other zu melden.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/healthcheck-redis-check.php erweitert: startet testweise einen echten, temporären Redis-Server (protected-mode yes/no) und prüft den Zugriff über eine echte, nicht-Loopback-Adresse dieses Hosts; bestätigt sowohl die neue Diagnosekategorie als auch, dass protected-mode no den Zugriff tatsächlich ermöglicht, sowie eine statische Prüfung, dass redis.conf protected-mode no enthält.'],
         ]],
        ['version' => '4.7', 'date' => '07.09.2026', 'title' => 'Staging- und Produktionsisolation im VPS-Stack',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Eine Prüfung auf dem produktiven VPS (nur docker compose config, kein Start) ergab, dass Staging und Produktion denselben Compose-Projektnamen erbten und dadurch dieselben Container-, Netz- und Volume-Namen erhalten hätten (z. B. "smarteinzug_smarteinzug_internal", "smarteinzug_caddy_data"); zusätzlich verwendeten beide Umgebungen identische Traefik-Router-/Middleware-/Dienstnamen. docker-compose.staging.yml setzt jetzt einen eigenen Projektnamen ("smarteinzug-staging") und eigene Traefik-Namen ("smarteinzug-staging-*"); die Traefik-Labels von Produktion stehen dafür nicht mehr in der gemeinsamen docker-compose.yml, sondern ausschließlich in docker-compose.prod.yml.'],
            ['type' => 'Neu', 'text' => 'Zusätzliches technisches Sicherheitsnetz gegen einen versehentlichen Staging-Deploy oder -Rollback gegen die Produktionskonfiguration: shared/config.php erhält ein Feld "environment" (prod/staging, siehe app/config.example.php); die isolierte Candidate-Prüfung jedes Deployments und jeder Rollback rufen bin/healthcheck.php --expect-env=$DEPLOY_ENV auf und brechen ab, bevor Migrationen oder ein Cutover stattfinden, wenn die Konfiguration nicht zur erwarteten Umgebung passt (ein Staging-Aufruf verlangt das Feld zwingend). Zusätzlich prüft dieser Schritt, dass die Plattform-Abrechnung in Staging keinen Live-Stripe-Schlüssel verwendet.'],
            ['type' => 'Geändert', 'text' => 'Die primäre Absicherung bleibt die Servertrennung (Staging auf einem eigenen, physisch getrennten VPS mit eigener Coolify-MariaDB, niemals auf dem Produktions-VPS); die sichere Vorgehensweise zur Ersteinrichtung ist in docs/vps/02-einrichtung-vps.md, Kapitel 25, dokumentiert.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/staging-isolation-check.py (kein Docker-Daemon nötig, nur docker compose ... config): bestätigt getrennte Projekt-/Volume-/Netz-/Traefik-Namen zwischen Produktion und Staging sowie das Vorhandensein des --expect-env-Schutzes in bin/healthcheck.php, deploy.sh und rollback.sh.'],
         ]],
        ['version' => '4.6', 'date' => '07.09.2026', 'title' => 'Verwertbare Fehlerdiagnose der Candidate-Prüfung',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der erste produktive Einsatz der neuen Candidate-Prüfung (Version 4.5) scheiterte mit der unbrauchbaren Meldung "redis: other": Der Fehlertext eines fehlgeschlagenen Redis-Zugriffs im Alpine/musl-basierten PHP-Image wich von der bisher erkannten glibc-Formulierung ab und wurde nicht erkannt. bin/healthcheck.php --redis erkennt jetzt zusätzliche Fehlerklassen (DNS, Verbindung abgelehnt, Authentifizierung, vom Server beendete Verbindung) und versucht bei einem Fehlschlag bis zu dreimal mit kurzer Pause erneut, um eine rein transiente Störung beim Netzwerkaufbau eines frisch erzeugten Containers abzufedern.'],
            ['type' => 'Geändert', 'text' => 'deploy.sh meldet bei einem Fehlschlag der Candidate-Prüfung, der Migration, des Warteschritts auf gesunde Container oder des Health-Checks nach der Aktivierung zusätzlich Phase, fehlgeschlagenen Befehl, Exitcode, Release-SHA und den aktuellen Containerzustand in die persistente Logdatei, ohne jemals Zugangsdaten auszugeben. Die Reihenfolge Candidate-Prüfung vor Migration vor Cutover aus Version 4.5 bleibt unverändert bestehen; es wurde nichts an der bereits funktionierenden serverseitigen Entkopplung zurückgebaut.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/healthcheck-redis-check.php: prüft monitor_category() gegen glibc- und musl-typische Fehlertexte, sowie bin/healthcheck.php --redis gegen einen nicht auflösbaren Hostnamen und einen geschlossenen Port; tools/compose-check.py bestätigt zusätzlich statisch, dass deploy.sh die Reihenfolge Candidate-Prüfung, Migration, Cutover einhält und beide isolierten Schritte ausschließlich über "docker compose run --rm --no-deps" laufen, ohne laufende Container anzufassen.'],
         ]],
        ['version' => '4.5', 'date' => '07.09.2026', 'title' => 'Ausfallsicheres VPS-Deployment, Release-Bindung ohne Symlink',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Ein VPS-Deployment brach ab, wenn die SSH-Verbindung des GitHub-Workflows waehrend des mehrminuetigen Container-Neustarts kurz abriss ("client_loop: send disconnect: Broken pipe"); Container blieben im Zustand "created" haengen. Das Deployment laeuft jetzt serverseitig entkoppelt (deploy/vps/scripts/deploy-runner.sh, setsid) und uebersteht einen SSH-Abbruch; der Workflow fragt den Fortschritt ueber kurze, unabhaengige Verbindungen ab.'],
            ['type' => 'Geändert', 'text' => 'working_dir aller Container und Caddys Dokumentenstamm sind jetzt an die Umgebungsvariable RELEASE_SHA gebunden (Pflichtwert), nicht mehr an den mutable Symlink "releases/current". Damit gehoeren Compose-Konfiguration, Healthchecks und Anwendungscode bei jedem Containerstart garantiert zum selben Release.'],
            ['type' => 'Geändert', 'text' => 'Datenbankmigrationen laufen jetzt in einem isolierten, zusaetzlichen Container mit dem neuen Code, BEVOR die laufenden Container angefasst werden (Candidate-Pruefung, dann Migration, dann Cutover). Schlagen Candidate-Pruefung oder Migration fehl, bleiben die laufenden Container unveraendert, ein Rollback ist dann nicht noetig.'],
            ['type' => 'Neu', 'text' => 'Regressionstests tools/compose-check.py (Release-Bindung als Pflichtwert, keine Datenbank-/Backup-Dienste im Stack) und tools/deploy-runner-check.sh (uebersteht simulierten SSH-Abbruch, lehnt parallele Deployments ab, idempotent).'],
         ]],
        ['version' => '4.4', 'date' => '06.09.2026', 'title' => 'Healthcheck des Metrik-Sammlers',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der Container des Metrik-Sammlers galt auf dem VPS dauerhaft als ungesund und brach das Deployment ab, weil er den Healthcheck der Worker erbte, aber keinen Worker-Heartbeat schreibt. Er hat jetzt einen eigenen Healthcheck (bin/healthcheck.php --metrics: Prozessprüfung und eigenes Lebenszeichen der Sammelschleife).'],
            ['type' => 'Geändert', 'text' => 'Das PHP-Image gibt keinen Standard-Healthcheck mehr vor; jeder Dienst legt seinen passenden Healthcheck selbst fest. Eine automatische Prüfung (tools/compose-check.py) verhindert künftig, dass ein Dienst einen unpassenden Healthcheck erbt oder ein Dollarzeichen in einem Healthcheck falsch ausgewertet wird.'],
            ['type' => 'Geändert', 'text' => 'Die Servereinrichtung setzt die von Redis empfohlene Kernel-Einstellung vm.overcommit_memory dauerhaft und wiederholbar.'],
            ['type' => 'Behoben', 'text' => 'Datum von SEPA-Mandaten und die Prüfung überfälliger Termine richten sich nach der Zeitzone der Anwendung statt nach dem Datum des Datenbankservers. Läuft die Datenbank in UTC, trug ein zwischen Mitternacht und 02:00 Uhr digital erteiltes Mandat bisher das Datum des Vortages.'],
         ]],
        ['version' => '4.3', 'date' => '06.09.2026', 'title' => 'VPS-Stack nutzt die Coolify-Datenbank',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Der Docker-Stack auf dem Hostinger-VPS startet keine eigene MariaDB und keinen eigenen Backup-Container mehr; genutzt wird die bereits eingerichtete private Coolify-MariaDB 11.8 (kein öffentlicher Port), gesichert durch Coolify mit externem Ziel Hetzner Object Storage.'],
            ['type' => 'Geändert', 'text' => 'PHP, Scheduler, Worker und Metrik-Sammler erreichen die Datenbank über das Coolify-Netz unter dem Containernamen; der Metrik-Sammler meldet die neueste lokale Coolify-Sicherung an das Monitoring (Komponente Sicherungen).'],
         ]],
        ['version' => '4.2', 'date' => '06.09.2026', 'title' => 'Ratenbegrenzung je Firma',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Die zentrale Ratenbegrenzung für Lexware Office und Stripe zählt je API-Schlüssel (also je Firma) statt über alle Firmen zusammen; zusätzlich eine konfigurierbare Obergrenze insgesamt. Der Durchsatz wächst damit mit der Zahl der Worker.'],
            ['type' => 'Behoben', 'text' => 'Ein Rate-Limit einer einzelnen Firma öffnet nicht mehr den Circuit Breaker des Anbieters; der Breaker reagiert nur noch auf echte Störungen (Verbindungsfehler, Serverfehler).'],
         ]],
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
