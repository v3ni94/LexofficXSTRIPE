# VPS-Architektur

Stand: 06.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`). Betreiber: Müller Holding AG. Diese Datei beschreibt das
Zielbild der Infrastruktur, nachdem die Anwendung SmartEinzug (php-ionos) zusätzlich zum
bestehenden IONOS-Webhosting auf einem eigenen VPS betrieben wird. Der Umzug ist optional und
schrittweise: das Webhosting bleibt nutzbar, solange nicht ausdrücklich umgestellt wird (siehe
`docs/vps/07-cutover-checkliste.md`).

**Server:** Tatsächlich beschafft wurde ein Hostinger-VPS, Tarif KVM 8 (8 vCPU, 32 GB RAM, 400 GB
NVMe), mit der Vorlage „Ubuntu 24.04 with Coolify“. Coolify war damit bereits installiert und lief
bei der Einrichtung bereits; die Ersteinrichtung erfolgt nach `docs/vps/08-hostinger-coolify.md`,
nicht über einen IONOS-Bestellvorgang. Frühere Fassungen dieser Datei gingen von einem IONOS VPS
ohne vorinstallierte Software aus; diese Annahme ist überholt und unten durch die tatsächliche
Proxykette ersetzt.

## Zielbild in einem Satz

Die beiden Marketingdomains und ihre Geschwisterdomains bleiben auf dem bestehenden IONOS-Webhosting;
die eigentliche Anwendung (Kundenportal, Adminbereich, API-Endpunkte, Statusseite) läuft auf dem
Hostinger-VPS in Docker-Containern hinter Caddy, mit einer zentralen Job-Queue statt des bisherigen
30-Sekunden-Cron-Takts.

## Proxykette: Coolify (Traefik) vor Caddy

Auf dem Hostinger-VPS ist Coolify bereits als Verwaltungsoberfläche für den Server installiert.
Coolify betreibt einen eigenen Reverse Proxy (Traefik), der die Ports 80/443 hält und die
TLS-Zertifikate über Let's Encrypt bezieht. Für SmartEinzug wird darin KEINE eigene
Coolify-Anwendung angelegt und kein Coolify-Autodeploy verwendet; Coolify dient ausschließlich als
Proxy und als Serverübersicht. Eine Anfrage durchläuft damit zwei Stufen:

1. **Traefik (Coolify-Proxy):** terminiert TLS auf 80/443, ermittelt anhand des Hostnamens
   (`Host(...)`-Regel) den Ziel-Container und reicht die Anfrage über das Docker-Netz
   `PROXY_NETWORK` (Standardname `coolify`, auf dem Server mit `docker network ls` zu prüfen) an
   den `caddy`-Container weiter. Traefik findet diesen Container über Labels am `caddy`-Dienst
   (`deploy/vps/docker-compose.yml`), nicht über eine eigene Konfigurationsdatei.
2. **Caddy (intern, nur HTTP):** veröffentlicht selbst keine Ports mehr, `auto_https off`, spricht
   ausschließlich HTTP auf Port 80 innerhalb des Docker-Netzes. Caddy bleibt weiterhin die
   Instanz, die `php-ionos/.htaccess` ablöst (verbotene Pfade/Endungen, Cache-Header,
   Sicherheits-Header) und per `php_fastcgi` an php-fpm weiterreicht.

Weil TLS bereits bei Traefik endet, sieht Caddy die Anfrage nur noch unverschlüsselt und muss
`X-Forwarded-Proto`/`X-Forwarded-For` von Traefik übernehmen. Dafür muss `trusted_proxies` in
`app/config.php` die Docker-Netzbereiche des Servers enthalten (Beispielwerte
`['172.16.0.0/12', '10.0.0.0/8']`, mit `docker network inspect` gegen die tatsächlichen Netze zu
prüfen, auf dem Server zu prüfen); `app/bootstrap.php` wertet `X-Forwarded-For` von rechts aus und
überspringt dabei nur als vertrauenswürdig erkannte Hops. Der Beispielkommentar in
`app/config.example.php` geht noch von einer einstufigen Anordnung aus („VPS mit Caddy
php_fastcgi: leer lassen“); auf dem Hostinger-VPS mit vorgeschaltetem Coolify-Proxy ist dieser
Kommentar überholt, `trusted_proxies` muss gefüllt werden.

Netze (siehe `deploy/vps/docker-compose.yml`): `PROXY_NETWORK` (extern, von Coolify
bereitgestellt, Standardname `coolify`, nur der Dienst `caddy` hängt daran) und
`smarteinzug_internal` (intern, `internal: true`, fester Adressbereich `172.28.0.0/24` für Caddy,
php, Scheduler, Worker, MariaDB, Redis, Backup, Metrics).

## Dienste im Überblick

| Dienst | Aufgabe | Läuft wo |
|---|---|---|
| Coolify (Traefik) | Reverse Proxy auf 80/443, automatisches TLS (Let's Encrypt); von SmartEinzug nur genutzt, nicht selbst als Anwendung betrieben | Hostinger-VPS, bereits vorinstalliert (Vorlage „Ubuntu 24.04 with Coolify“) |
| Caddy | Interner HTTP-Server hinter Traefik, löst `php-ionos/.htaccess` ab, veröffentlicht selbst keinen Port | VPS, Container `caddy` |
| PHP-FPM | Anwendung (Web-Anfragen: Login, Dashboard, Rechnungen, Admin, Webhooks) | VPS, Container `php` |
| Scheduler | Prüft alle 30 Sekunden fällige wiederkehrende Aufgaben und reiht sie als Jobs ein; verarbeitet selbst keine Jobs | VPS, Container `scheduler` (`bin/scheduler.php`) |
| Worker | Reservieren und verarbeiten Jobs aus der Warteschlange, je Pool ein oder mehrere Container | VPS, Container `worker-lexware`(-2 in Produktion)`, worker-stripe, worker-mail, worker-maintenance` (`bin/worker.php --pool=...`) |
| MariaDB | Anwendungsdatenbank, ausschließlich intern erreichbar | VPS, Container `mariadb` |
| Redis | Ergänzt die Warteschlange (Sperren, Ratenbegrenzung); ohne Redis läuft alles über MariaDB weiter | VPS, Container `redis`, optional |
| Backup | Täglicher Datenbank-Dump mit Prüfsumme, optionalem externem Upload, Wiederherstellungstest | VPS, Container `backup` |
| Host-Metriken | Liest CPU, Speicher, Platte, Load des VPS-Hosts, schreibt sie als Monitoring-Ereignisse | VPS, Container `metrics` (`bin/host-metrics.php`) |
| Marketingseiten | Statische HTML-Seiten beider Domains | IONOS-Webhosting, unverändert |
| Statusseite | Statische Seite `status.smart-einzug.de`, liest `status.json` | VPS, von Caddy read-only unter `/opt/smarteinzug/releases/current/status` ausgeliefert, oder Webhosting (siehe `docs/status-page.md`) |

## Hosts und Zuständigkeit

| Host | Zweck | Erreichbare Endpunkte |
|---|---|---|
| `app.smart-einzug.de` | Kundenanwendung | alle Kundenseiten (Login, Dashboard, Rechnungen, Einzüge, Kunden, Firma, Einstellungen) |
| `admin.smart-einzug.de` | Plattformadministration | `admin.php`, Anmeldung, 2FA, Passwort-Zurücksetzen, Sicherheit, Abmelden, Assets; keine Kundenseiten |
| `api.smart-einzug.de` | Nur maschinelle Endpunkte | ausschließlich `stripe-webhook.php`, `billing-webhook.php`, `health.php`, `track.php`; alles andere liefert 403 |
| `status.smart-einzug.de` | Öffentliche Statusseite | statisches HTML und `status.json`, kein PHP |
| `staging.smart-einzug.de` | Testumgebung | wie `app`, nur in der Staging-Konfiguration (eigener Server, eigene Datenbank), niemals produktive Kundendaten |

Ein Server bedient alle Anwendungshosts über dasselbe PHP-Image und dieselbe Datenbank; die
Trennung erfolgt in `app/config.php` (`allowed_hosts`, `app_base_url`, `admin_base_url`) und in der
Caddy-Konfiguration (erlaubte Pfade je Host), nicht durch getrennte Installationen.

## Datenflüsse

1. Ein Browser ruft `app.smart-einzug.de` auf. Caddy terminiert TLS, reicht die Anfrage per
   FastCGI an PHP-FPM weiter. PHP liest und schreibt in MariaDB, verschlüsselte Zugangsdaten über
   `app_secret`, optional Redis für Sperren.
2. Eine Kundenaktion (Synchronisation anstoßen, Einzug auslösen) legt bei aktivem Feature-Flag
   `queue` einen Job in der Tabelle `jobs` an (`dedupe_key` verhindert Doppelaufträge). Ohne das
   Flag läuft die Aktion wie bisher synchron beziehungsweise über `cron.php`.
3. Der Scheduler-Container prüft alle 30 Sekunden fällige wiederkehrende Aufgaben (automatischer
   Abgleich, nächtlicher Vollabgleich, fällige Einzüge, Klärung, Monitoring, Wartung) und reiht sie
   ebenfalls als Jobs ein.
4. Worker-Container reservieren Jobs ihres Pools (`SELECT ... FOR UPDATE SKIP LOCKED`), rufen bei
   Bedarf Lexware Office oder Stripe auf (über den jeweiligen Circuit Breaker), schreiben das
   Ergebnis in die Fachtabellen (`invoices`, `customers`, `sepa_mandates`, `payment_collections`, ...)
   sowie in `job_runs` beziehungsweise `sync_runs`.
5. Stripe- und Plattform-Webhooks laufen ausschließlich über `api.smart-einzug.de` und werden über
   `webhook_events` genau einmal verarbeitet, unabhängig vom Warteschlangenstatus.
6. Der Backup-Container zieht täglich einen MariaDB-Dump, verschlüsselt ihn optional und meldet
   das Ergebnis als `backup-status.json` in den gemeinsamen Speicher (Monitoring-Komponente Sicherungen) (Anzeige im Adminbereich
   System, Reiter Server).
7. Der Metrics-Container liest Host-Kennzahlen (CPU, RAM, Platte, Load, Datenbankverbindungen,
   Redis-Speicher) und schreibt sie als `monitor_checks`-Ereignisse; nur auf dem VPS verfügbar, auf
   dem Webhosting liefert das Hosting diese Werte nicht.

## Was wo gespeichert wird

| Daten | Ort | Hinweis |
|---|---|---|
| Anwendungscode | `/opt/smarteinzug/releases/<git-sha>/`, `current` zeigt per Symlink auf das aktive Release | read-only in den Containern eingebunden |
| Konfiguration (`app/config.php`) | `/opt/smarteinzug/shared/config.php`, read-only in die Container eingebunden | liegt außerhalb jedes Release, wird nie überschrieben |
| Anwendungsdaten (Mandate, Avatare, Logs, `maintenance.flag`) | `/opt/smarteinzug/shared/storage`, beschreibbar eingebunden | gemeinsam für alle Container, daher wirkt der Wartungsmodus sofort überall |
| Datenbank | MariaDB-Volume des Containers `mariadb` | nur intern erreichbar, kein veröffentlichter Port |
| Sitzungen | eigenes Volume für `/var/lib/php/sessions` | überlebt einen Container-Neustart |
| Backups | `/opt/smarteinzug/backups` (lokal, 14 Tage), optional externes Ziel über `BACKUP_REMOTE` | Prüfsumme je Dump, optionale Verschlüsselung mit `age` |
| Statusseite | `/opt/smarteinzug/releases/current/status`, read-only in Caddy eingebunden (Dokumentenstamm `/opt/smarteinzug/releases/current/status`) | nicht Teil des Anwendungs-Release |

## MariaDB: nur intern

MariaDB veröffentlicht keinen Port nach außen; erreichbar ist sie ausschließlich aus dem
Docker-Netz der Anwendung (PHP, Scheduler, Worker, Backup, Metrics). Zugriff von außen (z. B.
phpMyAdmin, ein Datenbank-Client vom Arbeitsplatz) erfordert einen SSH-Tunnel auf den VPS; ein
öffentlicher Datenbankport wird nicht eingerichtet.

## Redis: nur ergänzend

Redis ist optional (`config('redis')`, siehe `app/redis.php`). Es beschleunigt Sperren und
Ratenbegrenzung gegenüber Lexware Office und Stripe (`queue.lexoffice_per_second`,
`queue.stripe_per_second`) und hält keinen dauerhaften Datenbestand, der nicht auch aus MariaDB
rekonstruierbar wäre. Fällt Redis aus, läuft die Warteschlange weiter, ausschließlich über die
Sperren und Zähler in MariaDB; ein Ausfall von Redis ist also keine Störung der Kernfunktion.

## Queue, Worker, Scheduler

Kern in `php-ionos/app/queue.php` und `php-ionos/app/jobs.php` (bereits umgesetzt, Migration 018,
siehe `docs/queue-worker.md`). Kurzfassung für den Betrieb:

- Ein fachlicher Auftrag steht über `dedupe_key` nur einmal gleichzeitig in der Warteschlange
  (z. B. `sync:<firma>`); der Schlüssel wird beim Abschluss wieder freigegeben.
- Prioritäten: HIGH 10, NORMAL 50, LOW 90 (kleinere Zahl zuerst).
- Wiederholung mit gestaffeltem Backoff (60, 300, 900, 3600 Sekunden) nach einem technischen
  Fehler (`JobRetryException`, `CircuitOpenException`); ein fachlicher Fehler
  (`JobFailedException`) wird nicht wiederholt. Nach dem letzten Fehlversuch landet der Job im
  Dead Letter (`status = failed`) mit den Admin-Aktionen Erneut versuchen, Abbrechen, Dauerhaft
  schließen.
- Worker-Pools: `lexware` (Synchronisation), `stripe` (fällige Einzüge, Klärung unklarer
  Versuche), `mail` (E-Mail-Versand, Alarme, Mandats-Erinnerungen), `maintenance`
  (Monitoring-Sammler, Wartungsaufgaben). Ein Pool `all` bearbeitet alle Jobtypen (Staging,
  kleine Umgebungen).
- Jeder Worker und der Scheduler schreiben einen Heartbeat (Tabelle `worker_heartbeats`) und, für
  den Docker-Healthcheck, in eine Datei (`WORKER_HEARTBEAT_FILE`).

## Circuit Breaker

`api_circuits` (je Anbindung `lexoffice`, `stripe`, `mail`): nach `queue.circuit.threshold`
aufeinanderfolgenden technischen Fehlern öffnet der Breaker (`state = open`), weitere Jobs dieser
Anbindung schlagen sofort mit `CircuitOpenException` fehl und werden regulär mit Backoff
wiederholt, statt die Anbindung weiter zu belasten. Nach `queue.circuit.open_seconds` folgt ein
Halbtest (`half_open`, ein Versuch alle `queue.circuit.probe_seconds`); gelingt er, schließt der
Breaker wieder. Fachliche Ablehnungen (4xx) zählen nicht als Fehler des Breakers.

## Rate Limits

Zentrale Ratenbegrenzung über Redis (fällt Redis aus: über MariaDB-Zähler), konfiguriert in
`queue.lexoffice_per_second` und `queue.stripe_per_second` (Startwerte, siehe `app/config.example.php`;
Lexware-Grenzwert vorsichtig zu verifizieren, siehe `docs/sync-performance.md`). Ziel: mehrere
Worker eines Pools dürfen eine externe Anbindung nicht gemeinsam über deren Grenzwert hinaus
belasten.

## Checkpoints und Idempotenz

- Synchronisation: Fortschritt steht in `sync_state` beziehungsweise, mit aktivierter Queue, im Job
  selbst (`progress`, `progress_text`) und in `sync_runs`; ein Zeitbudget je Versuch
  (`queue.sync_attempt_seconds`) beendet den Versuch geordnet (`JobRequeueException`), der nächste
  Versuch setzt am gespeicherten Zeiger fort. Kein Datensatz wird beim Fortsetzen doppelt erzeugt.
- Einzugsverarbeitung: `payment_collections` und `collection_attempts` verhindern doppelte
  Einreichung bei Stripe; ein wiederholter Versuch prüft zuerst den bekannten Zustand.
- Webhooks: `webhook_events` verarbeitet jedes Ereignis genau einmal, unabhängig von Warteschlange
  oder Neustart eines Containers.
- Migrationen: `schema_migrations` verhindert doppeltes Einspielen; Migrationsdateien sind mit
  `IF NOT EXISTS` wiederholbar formuliert (siehe `docs/migrations.md`).
- Jeder Handler ist so gebaut, dass ein Wiederholungsversuch nach einem Abbruch keine doppelten
  Buchungen, Lastschriften oder Datensätze erzeugt (siehe Kopfkommentar `app/jobs.php`).

## Logging mit Correlation-ID

Strukturiertes JSON-Logging (`app/log.php`), Ziel konfigurierbar (`stderr` für Docker, `file` für
das Webhosting, `error_log`). Jede Web-Anfrage, jeder Job und jeder Worker-Durchlauf tragen eine
`correlation_id`; sie verbindet die Log-Zeilen eines fachlichen Vorgangs über mehrere Container
hinweg (z. B. Web-Anfrage, die einen Job anlegt, und der Worker, der ihn später verarbeitet). Siehe
`docs/vps/06-betrieb.md` für die Suche in den Logs.

## Audit

`audit_log` protokolliert jede geldrelevante und sicherheitsrelevante Aktion (Einzüge, Mandate,
IBAN-Änderungen, Support-Zugriffe, Zugangsdaten geändert, Störungsmeldungen) mit Urheber und
Zeitpunkt, unabhängig davon, ob die Aktion synchron oder über die Warteschlange ausgelöst wurde.

## Feature-Flags

`app/features.php`: `feature_enabled()`, `tenant_feature_flags()`, `tenant_feature_set()`. Die
Warteschlange selbst ist über `features.queue` steuerbar: `false` (Standard auf dem Webhosting),
`true` (global) oder eine Liste einzelner Firmen-IDs (schrittweise Aktivierung auf dem VPS). Weitere
Flags lassen sich je Firma in `feature_flags` hinterlegen, ohne Code-Änderung.

## Wartungsmodus je Firma

Zusätzlich zum globalen Wartungsmodus (`maintenance_mode` in der Konfiguration oder
`app/storage/maintenance.flag`, siehe `deploy/vps/scripts/maintenance.sh`) lässt sich die
Synchronisation je Firma pausieren (`organizations.sync_paused`,
`organizations.sync_paused_reason`, Migration 018): der Scheduler reiht für eine pausierte Firma
keine neuen Synchronisationsjobs ein, bestehende Einzüge und die Anwendung selbst bleiben
uneingeschränkt nutzbar.

## Ressourcen des Hostinger-VPS

Server: Hostinger KVM 8, 8 vCPU, 32 GB RAM, 400 GB NVMe. `docker-compose.prod.yml` setzt
Ressourcenlimits bewusst deutlich unter dieser Kapazität an (Summe rund 12 GB RAM): Reserve für
das Betriebssystem, für Coolify selbst (eigene Postgres- und Redis-Instanz sowie den
Traefik-Proxy, zusammen rund 2 GB) und für Backups und Lastspitzen. MariaDB erhält 4 GB
(`innodb-buffer-pool-size=2560M`), `PM_MAX_CHILDREN=16` für php-fpm, zwei Lexware-Worker (mehr
bringt wegen der Ratenbegrenzung gegenüber Lexware Office und Stripe nichts) sowie je ein Worker
für Stripe, Mail und Wartung. Einzelwerte je Dienst: `deploy/vps/docker-compose.prod.yml`.

## Grenzen: ein VPS, keine Hochverfügbarkeit

- Ein einzelner Server trägt Anwendung, Datenbank, Redis UND Coolify (Proxy und
  Serververwaltung). Ein Ausfall des VPS (Hardware, Netz, ein versehentliches
  `docker compose down`) legt die gesamte Anwendung lahm; es gibt keinen automatischen Failover
  auf einen zweiten Server.
- MariaDB und Redis laufen ohne Replikation. Tägliche Backups mit Prüfsumme und ein
  Wiederherstellungstest (`deploy/vps/backup/`) sind die Absicherung gegen Datenverlust, kein
  Ersatz für echte Hochverfügbarkeit.
- Skalierung bedeutet zusätzliche Worker-Container auf demselben Server
  (`docker compose up -d --scale`), nicht zusätzliche Server.
- Automatisches TLS setzt erreichbare Ports 80/443 aus dem Internet voraus; diese hält der
  Coolify-Proxy (Traefik), nicht Caddy selbst. Ein vorgeschalteter Load Balancer oder CDN würde
  zusätzlich zur Caddy-Konfiguration auch die Traefik-Labels in `deploy/vps/docker-compose.yml`
  betreffen.
- Coolifys Weboberfläche (Port 8000) wird nicht öffentlich freigegeben, sondern ausschließlich per
  SSH-Tunnel erreicht (siehe `docs/vps/08-hostinger-coolify.md`).

Weitere technische Grenzen und offene Punkte der Infrastruktur: `deploy/vps/README.md`, Abschnitt
"Offene Punkte".

## Pfade in den Containern

Alle PHP-Container und Caddy binden nur das Verzeichnis `/opt/smarteinzug/releases` lesend ein und arbeiten mit dem Pfad `/opt/smarteinzug/releases/current`. Der Symlink `current` zeigt auf `/opt/smarteinzug/releases/<git-sha>`, also in denselben Mount, und wird im Container bei jedem Dateizugriff neu aufgelöst; ein Release-Wechsel wirkt deshalb ohne Container-Neustart, es genügt der Reload von php-fpm. Ein direkter Bind des Symlinks würde beim Containerstart fest auf das damalige Release aufgelöst. Die Ordner `deploy/` (mit `.env`), `backups/` und `logs/` sind für keinen Anwendungscontainer sichtbar; der Metrik-Sammler sieht nur `/proc` des Hosts lesend, nicht dessen Wurzeldateisystem.

Konfiguration und Speicher liegen außerhalb der Releases: `SMARTEINZUG_CONFIG=/opt/smarteinzug/shared/config.php` (Umgebungsvariable, gelesen in `app/bootstrap.php`) und `storage_dir` in der config.php auf `/opt/smarteinzug/shared/storage` (Mandate, Avatare, Logs, `maintenance.flag`). Nur dieses Verzeichnis und `/opt/smarteinzug/shared/sessions` (PHP-Sitzungen, gemeinsam für alle Container) sind schreibbar eingebunden.
