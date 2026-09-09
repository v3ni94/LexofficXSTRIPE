# Einrichtung, Deployment und Wiederherstellung

Stand 07.09.2026. Bezieht sich auf die PHP-Anwendung SmartEinzug (`php-ionos/`), das IONOS-Webhosting (`php-ionos/ANLEITUNG-IONOS.md`), den Hostinger-VPS-Stack (`deploy/vps/`, `docs/vps/`) und den GitHub-Workflow (`.github/workflows/deploy.yml`). Jeder administrative Befehl ist mit Zielumgebung, Voraussetzungen, Auswirkung und erwartetem Ergebnis versehen. Alle Aussagen sind mit Datei und Funktion/Zeile belegt; wo im Repository keine Angabe gefunden wurde, steht „nicht gefunden“.

## (a) Neue Entwicklungsumgebung

**Hinweis vorab:** Ein eigenes, zusammenhängendes Dokument für die lokale Entwicklungsumgebung wurde im Repository nicht gefunden (`php-ionos/ANLEITUNG-IONOS.md` beschreibt IONOS-Webhosting, `docs/vps/02-einrichtung-vps.md` den VPS). Die folgenden Schritte sind aus `php-ionos/app/bootstrap.php`, `php-ionos/app/config.example.php`, `php-ionos/sql/schema.sql` und `tools/lib/mariadb-sandbox.sh` abgeleitet, nicht wörtlich einem Einrichtungsdokument entnommen.

**Voraussetzungen:** PHP 8.1 oder neuer (CLI, ohne Composer, `php-ionos/ANLEITUNG-IONOS.md` Zeile 4), lokale MariaDB, Redis optional (`redis` in `config.php` kann `null` sein, `app/redis.php`, Fallback auf die Datenbank).

**Schritte:**

1. **Datenbank anlegen.**
   ```bash
   mariadb -uroot -e "CREATE DATABASE smarteinzug_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mariadb -uroot smarteinzug_dev < php-ionos/sql/schema.sql
   ```
   Zielumgebung: lokal. Wirkung: legt alle 38 Tabellen aus `php-ionos/sql/schema.sql` an (vollständiges, wiederholbares Schema, kein Migrationslauf nötig). Erwartetes Ergebnis: `SHOW TABLES` zeigt u. a. `users`, `organizations`, `invoices`, `payment_collections`, `plans`.

2. **Konfiguration anlegen.**
   ```bash
   cp php-ionos/app/config.example.php php-ionos/app/config.php
   ```
   Mindestens auszufüllen (siehe `php-ionos/app/config.example.php`, vollständig gelesen): `db.host`/`db.port`/`db.name`/`db.user`/`db.pass`, `app_secret` (`openssl rand -hex 32`), `cron_token`, `migration_token`, `app_base_url` (z. B. `http://localhost:8000`), `admin_base_url`, `public_base_url`, `allowed_hosts`, `mail` (mit `enabled => false` für den ersten Start ausreichend, siehe Fehlerhandbuch Fall 8). `'environment'` kann entfallen (Standard `'prod'`, für lokale Zwecke unerheblich, siehe `app/config.example.php` Zeile 18 ff.). Zielumgebung: lokal. Wirkung: keine (reine Textdatei, von `.gitignore` ausgeschlossen). Erwartetes Ergebnis: `php -l php-ionos/app/config.php` ohne Fehler.

3. **Eingebauten PHP-Server starten.**
   ```bash
   php -S localhost:8000 -t php-ionos
   ```
   Zielumgebung: lokal. Wirkung: startet einen Entwicklungsserver ohne Docker. **Kein Router-Skript für den eingebauten Server im Repository gefunden**; das ist unkritisch, weil die Anwendung keine sprechenden URLs verwendet (`php-ionos/.htaccess`, keine `RewriteRule` auf einzelne Endpunkte außer der HTTPS-Erzwingung) und jede Seite eine eigene `.php`-Datei ist (`register.php`, `login.php`, `dashboard.php` usw.), die der eingebaute Server direkt findet. Erwartetes Ergebnis: `curl -s http://localhost:8000/health.php` liefert JSON mit `"php":true`.

4. **Registrieren und anmelden.** `http://localhost:8000/register.php` aufrufen, Firma anlegen, 2FA einrichten (Pflicht, siehe `app/auth.php:616` ff.). Erwartetes Ergebnis: Dashboard erreichbar.

5. **Tests mit temporärer MariaDB ausführen.** Für Tests, die eine echte Datenbank brauchen (`tools/interest-check.sh`, `tools/legal-check.sh`, `tools/invoice-source-check.sh`, `tools/scheduler-sync-check.sh`, Teil B von `tools/worker-signal-check.sh`):
   ```bash
   bash tools/interest-check.sh
   ```
   Zielumgebung: lokal. Voraussetzung: `mariadbd`, `mariadb-install-db`, `mariadb` im Pfad (Paket `mariadb-server`/`mariadb-client` bzw. `mysql-server`, je nach Distribution). Wirkung: `tools/lib/mariadb-sandbox.sh` (vollständig gelesen) baut unter einem temporären Verzeichnis eine eigene MariaDB-Instanz auf einem zufälligen Port auf, spielt `php-ionos/sql/schema.sql` ein und setzt `SMARTEINZUG_CONFIG` auf eine eigens erzeugte `config.php` (keine Berührung mit der unter Schritt 2 angelegten Datenbank). Erwartetes Ergebnis: Zeilen `OK ...` je Prüffall, am Ende `PASS`-Zusammenfassung ohne `FAIL`. Andere Werkzeuge dieser Art werden in `docs/entwickler/tests-und-nachverfolgbarkeit.md` vollständig aufgeführt.

**Nicht auffindbar:** Die in `CLAUDE.md` genannte E2E-Suite `scratchpad/e2e_saas.php` sowie die dort genannten `test_*.php`-Dateien existieren nicht im Repository (siehe `docs/entwickler/tests-und-nachverfolgbarkeit.md`, Abschnitt „Wichtiger Befund“). Ein lokaler Lauf dieser Suiten lässt sich mit den vorhandenen Mitteln nicht durchführen.

## (b) Test-/Staging-Umgebung

Vollständige Anleitung: `docs/vps/02-einrichtung-vps.md`, Kapitel 25 „Staging-Umgebung einrichten“ (vollständig gelesen). Kurzfassung:

**Zielumgebung:** eigener, physisch getrennter (kleinerer) VPS, NIEMALS der Produktions-VPS (`docs/vps/02-einrichtung-vps.md` Zeile 473 ff., `docs/vps/06-betrieb.md` Abschnitt „Staging- und Produktionsisolation“).

**Voraussetzungen:** eigene, leere Coolify-MariaDB-Instanz auf diesem Server, eigene Coolify-Proxy-Einrichtung (Kapitel 1 bis 24 der Anleitung wiederholt), niemals ein Datenbank-Dump aus Produktion mit echten Vertrags-/Zahlungsdaten.

**Schritte und Wirkung:**

1. `deploy/vps/.env.example` nach `/opt/smarteinzug/deploy/.env` kopieren, `DEPLOY_ENV=staging` setzen, nur `DOMAIN_STAGING` füllen (die vier Produktionsdomains leer lassen).
2. `app/config.example.php` nach `/opt/smarteinzug/shared/config.php` kopieren, **zwingend** `'environment' => 'staging'` setzen. Wirkung bei Unterlassen: Jede isolierte Candidate-Prüfung (`deploy.sh`) und jeder Rollback ruft `bin/healthcheck.php --expect-env=staging` auf (`php-ionos/bin/healthcheck.php` Zeile 70 ff.) und bricht ohne dieses Feld ab; das ist beabsichtigt, kein produktiver Schaden.
3. `billing.enabled` auf Staging nur mit einem Stripe-**Test**-Schlüssel (`sk_test_...`); ein Live-Schlüssel lässt `--expect-env=staging` ebenfalls fehlschlagen (`bin/healthcheck.php` Zeile 88 ff.).
4. Start ausschließlich mit `docker-compose.staging.yml`/`Caddyfile.staging` (niemals `docker-compose.prod.yml`); eigener Compose-Projektname `smarteinzug-staging` sorgt automatisch für getrennte Container-/Netz-/Volume-Namen und getrennte Traefik-Router (`docs/vps/06-betrieb.md` Abschnitt „Staging- und Produktionsisolation“).

**Prüfkommando:**
```bash
python3 tools/staging-isolation-check.py
curl -s https://staging.smart-einzug.de/health.php
```
Zielumgebung: kann ohne laufenden Docker-Daemon ausgeführt werden (reine `docker compose ... config`-Auswertung). Erwartetes Ergebnis: Exit 0, keine überlappenden Projekt-/Netz-/Volume-/Traefik-Namen zwischen `docker-compose.prod.yml` und `docker-compose.staging.yml`.

**Mögliche Fehler:** Staging und Produktion teilen sich versehentlich dieselbe Datenbank oder denselben `app_secret` (unbedingt vermeiden); fehlendes `'environment' => 'staging'` (Candidate-Prüfung bricht ab, kein produktiver Schaden, siehe `docs/vps/02-einrichtung-vps.md` Zeile 515 ff.).

## (c) Produktion

### IONOS-Webhosting (bestehend)

Vollständige Anleitung: `php-ionos/ANLEITUNG-IONOS.md` (vollständig gelesen). Kurzfassung der Neuinstallation (Abschnitt 2):

1. Subdomain anlegen, SSL aktivieren (IONOS-Kundenbereich).
2. Datenbank anlegen, `sql/schema.sql` per phpMyAdmin importieren. Zielumgebung: IONOS-Webhosting. Wirkung: legt das vollständige Schema an.
3. `app/config.example.php` nach `app/config.php` kopieren und ausfüllen (Datenbank, `app_secret`, `cron_token`, `base_url`).
4. Inhalt von `php-ionos/` per FTP hochladen (versteckte Dateien anzeigen, sonst fehlt `.htaccess`).
5. `setup-check.php` aufrufen (prüft schreibgeschützt, ob alle Tabellen vorhanden sind), danach **vom Server löschen** (Sicherheitsmaßnahme laut Anleitung, Schritt 5).
6. Registrieren, 2FA einrichten, Onboarding durchlaufen.

**Cronjob** (Abschnitt 7 der Anleitung): externer Dienst oder IONOS-Kundenbereich, Intervall 5 Minuten, `https://<domain>/cron.php?token=<cron_token>`. Wirkung: reicht fällige terminierte Lastschriften ein, klärt unklare Einzugsversuche, versendet Alarm-E-Mails, räumt abgelaufene Support-Sitzungen auf, setzt laufende Synchronisationen fort. Zeitbudget `cron_time_budget_seconds` (Standard 20 s, externe Cron-Dienste brechen nach 30 s ab).

**Datenbankmigrationen** (Abschnitt 7b): laufen ausschließlich über den GitHub-Workflow, der nach vollständig erfolgreichem SFTP-Upload einmalig `POST` mit Header `X-Migration-Token` auf `WEBHOSTING_MIGRATE_URL` sendet und `HTTP 200` mit `{"success":true}` erwartet (`.github/workflows/deploy.yml` Zeile 423 ff.). `cron.php` führt keine Migrationen mehr aus, `GET` auf `migrate.php` liefert 405 (`docs/migrations.md`).

**Plattform-Abrechnung** (Abschnitt 6): Produkt „UNLIMITED START“ mit wiederkehrendem Preis in Stripe anlegen, Preis-ID unter Admin > Tarife eintragen; Webhook-Endpunkt `https://app.smart-einzug.de/billing-webhook.php` mit den fünf Ereignissen aus `BILLING_REQUIRED_WEBHOOK_EVENTS` (`php-ionos/app/billing_setup.php:21` ff.: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`) anlegen; erst danach `billing.enabled = true`.

### Hostinger-VPS (neu, per Coolify)

Vollständige Anleitung: `docs/vps/01-architektur.md` bis `docs/vps/08-hostinger-coolify.md`; Betrieb: `docs/vps/06-betrieb.md` (beide vollständig gelesen). Kurzfassung der Startreihenfolge und Funktionsprüfung:

**Startreihenfolge beim Deployment** (`docs/vps/06-betrieb.md` Abschnitt „Migrationsreihenfolge“): Image bauen (falls geändert) → Candidate isoliert prüfen (`bin/healthcheck.php --db --redis` mit dem neuen Code, laufende Anwendung unberührt) → Migrationen isoliert mit dem neuen Code einspielen → Cutover (`docker compose up -d`) → auf gesunde Container warten → `current`-Symlink umstellen → php-fpm neu laden → Release-Bindung aller PHP-Container per `docker inspect` verifizieren → Health-Check → bei Fehler ab dem Cutover automatisches Rollback.

**Scheduler:** Auf dem VPS ersetzt `bin/scheduler.php` den klassischen Cron vollständig (`features.queue` gesetzt); die Tabelle „Braucht der VPS Cron-Jobs?“ in `docs/vps/06-betrieb.md` listet jede wiederkehrende Aufgabe mit Intervall und Worker-Pool (z. B. fällige Einzüge alle 300 s über `worker-stripe`, Synchronisation über `worker-lexware-1`/`-2`, Monitoring alle 240 s über `worker-maintenance`).

**Webhooks:**
- Stripe-Endpunkt je Firma: `stripe-webhook.php`, Secret je Firma verschlüsselt in `integrations.stripe_webhook_secret_encrypted` (siehe Fehlerhandbuch Fall 6).
- Plattform-Abrechnung (Konto der Müller Holding AG, getrennt von den Firmenkonten): `billing-webhook.php`, Secret in `billing.stripe_webhook_secret` (`shared/config.php`), Pflichtereignisse `BILLING_REQUIRED_WEBHOOK_EVENTS` (siehe oben).

**Funktionsprüfung nach der Einrichtung:**
```bash
# VPS: Gesamtprüfung (Datenbank, Redis, alle Worker-Pools, Scheduler, Warteschlange), keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all

# VPS: Mailversand prüfen (keine Änderung ohne --send), optional Testmail senden (sendet tatsächlich)
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php --send=<adresse>

# VPS: Plattform-Abrechnung nur lesend prüfen (Schlüssel maskiert), keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/billing-check.php
```
Erwartetes Ergebnis je Befehl: `OK`/Exit 0 (`healthcheck.php`), „Mailversand: AKTIV“ mit anschließend übergebener Testmail, unauffälliger Abgleich Stripe-Preis gegen Tabelle `plans` (`billing-check.php`, nur lesend, `php-ionos/bin/billing-check.php`).

**bin/healthcheck.php, vollständige Optionsliste** (`php-ionos/bin/healthcheck.php`, vollständig gelesen): `--db`, `--redis`, `--heartbeat`, `--metrics`, `--workers=<pool1,pool2,...>`, `--scheduler`, `--queue`, `--expect-env=prod|staging`, `--all` (kombiniert db, redis, alle Worker-Pools, scheduler, queue).

## (d) GitHub-Deployment

@@diagramm 12-github-deployment

Workflow: `.github/workflows/deploy.yml` (vollständig gelesen), Jobs `changes` (ermittelt geänderte Bereiche per `git diff`), `test` (siehe `docs/entwickler/tests-und-nachverfolgbarkeit.md`, Abschnitt b), `deploy-webhosting`, `deploy-vps`.

### Job `deploy-webhosting` (IONOS)

**Dateizuordnung** (Zeile 370 ff.): `websites/smart-einzug.de` → `smart-einzug.de`, `websites/lexware-einzug.de` → `lexware-einzug.de`, `websites/lexoffice-einzug.de` → `lexoffice-einzug.de`, `websites/lastschrift-einfach.de` → `lastschrift-einfach.de`, `websites/aliases/*` → jeweils eigener Alias-Ordner, `websites/status.smart-einzug.de` → `status.smart-einzug.de`; zusätzlich `php-ionos` → `app`, aber nur wenn die Repository-Variable `WEBHOSTING_APP_DEPLOY` nicht `false` ist.

**Übertragungsweg:** SFTP über `lftp` (`mirror --reverse`), danach ein einzelner `POST` mit `X-Migration-Token` auf `migrate.php` (Zeile 423 ff.).

**Ausschlüsse beim Upload** (Zeile 401 ff.): `.git*`, `node_modules`, `storage`, `logs`, `cache`, `backups`, `config.php`, `mail.log`, `.env*`, `*.sql(.gz)?`, `*.sqlite*`, `*.db`, `*.log`, `*.pem`, `*.key`, `*.bak`; Migrationsdateien (`sql/migrations/`) werden gesondert danach übertragen.

**Secret-/Variablennamen (keine Werte, aus `deploy.yml` Zeile 35 ff. und 289 ff.):**
| Name | Art | Zweck |
|---|---|---|
| `SFTP_HOST` | Secret | Servername des IONOS-Webhostings |
| `SFTP_USER` | Secret | SFTP-Benutzer |
| `SFTP_PASSWORD` | Secret | SFTP-Passwort (als `LFTP_PASSWORD` an `lftp` übergeben) |
| `SFTP_PORT` | Secret | SFTP-Port |
| `SFTP_PATH` | Secret | Zielverzeichnis auf dem Webspace |
| `MIGRATION_TOKEN` | Secret | Header `X-Migration-Token` für `migrate.php`; muss mit `migration_token` in `app/config.php` übereinstimmen |
| `WEBHOSTING_APP_DEPLOY` | Variable | Standard `true`; `false` = nur `websites/`-Ordner übertragen, kein App-Ordner, kein Migrationsaufruf |
| `WEBHOSTING_MIGRATE_URL` | Variable | Vollständige Adresse des Migrationsendpunkts, Pflicht solange `WEBHOSTING_APP_DEPLOY` nicht `false` ist; kein Vorgabewert (siehe unten) |

**Prüfung der Migrations-URL vor JEDER Verwendung:** `bash tools/check-migrate-url.sh "<url>"` (einmal vor dem Upload, einmal unmittelbar vor dem `POST`, Zeile 228 ff. und 423 ff.); weist u. a. Namen ab, die auf den VPS zeigen (Hintergrund: Während des Umzugs zeigen die smart-einzug.de-Namen zunehmend auf den VPS, ein fest verdrahteter Name hätte während der Umstellung die Migration gegen den falschen Server ausgelöst).

**Teilfehler „Frontend aktualisiert, Backend fehlgeschlagen“:** Schritt „Hinweis bei Fehler nach dem Upload“ (Zeile 490 ff.): Scheitert der Migrationsaufruf NACH erfolgreichem SFTP-Upload, meldet der Workflow ausdrücklich „Dateien sind bereits hochgeladen. Es erfolgt kein automatischer Rollback. Vor erneutem Deployment Anwendung und Datenbankzustand prüfen“ (keine automatische Gegenmaßnahme, bewusst manuelle Prüfung erforderlich).

### Job `deploy-vps` (Hostinger)

**Ablauf** (Zeile 495 ff., vollständig gelesen): Zielverzeichnis anlegen → Anwendung per `rsync` übertragen (Ausschlüsse `.git`, `app/config.php`, `app/storage/`, `mail.log`, `.env*`, `/deploy/`, `/status/`; `sql/migrations/**` eingeschlossen, sonstige `*.sql` ausgeschlossen) → Deploy-Skripte per `rsync` übertragen → Statusseite per `rsync` übertragen → **Release als vollständig kennzeichnen** (`.release-complete`, erst nach ALLEN erfolgreichen Übertragungen) → Deployment auslösen (`deploy-runner.sh`, entkoppelt von der SSH-Sitzung, kehrt innerhalb von Sekunden mit `TRIGGERED` zurück) → auf Abschluss warten (`.github/scripts/vps-wait-status.sh`, Polling alle 10 s, Frist 12 Minuten) → Health-Check von außen (`curl` auf `/health.php`).

**Candidate-Prüfung, Migration, Cutover, Rollback:** siehe `docs/vps/06-betrieb.md` Abschnitt „Migrationsreihenfolge: Candidate prüfen, dann migrieren, dann erst Cutover“ (oben unter (c) zusammengefasst) und `deploy/vps/scripts/deploy.sh` (Schritte `candidate` Zeile 493, `migration` Zeile 507, `cutover` Zeile 523, vollständig gelesen für diese Zeilen).

**Secret-/Variablennamen (keine Werte, aus `deploy.yml` Zeile 54 ff. und 506 ff.):**
| Name | Art | Zweck |
|---|---|---|
| `VPS_HOST` | Secret | Servername des VPS |
| `VPS_SSH_USER` | Secret | SSH-Benutzer (z. B. `deploy`) |
| `VPS_SSH_PORT` | Secret | SSH-Port |
| `VPS_SSH_PRIVATE_KEY` | Secret | privater SSH-Schlüssel für das Deployment |
| `VPS_SSH_KNOWN_HOSTS` | Secret | eine einzelne, bereits verifizierte Host-Key-Zeile; `StrictHostKeyChecking` bleibt aktiv |
| `VPS_DEPLOY_ENABLED` | Variable | muss `true` sein, sonst läuft der Job gar nicht |
| `VPS_DEPLOY_PATH` | Variable | Standard `/opt/smarteinzug` |
| `VPS_APP_DOMAIN` | Variable | Standard `app.smart-einzug.de`, für den externen Health-Check |
| `VPS_HEALTH_STRICT` | Variable | `true` = fehlgeschlagener Health-Check bricht den Job ab; sonst nur Warnung (solange DNS noch nicht umgestellt ist) |

**Absicherung der SSH-Schritte:** Jeder ssh-/rsync-Aufruf läuft über `.github/scripts/vps-ssh-retry.sh` (bis zu vier Versuche, wachsende Pause 5/10/20 s), jeder dieser Schritte ist idempotent; das Auslösen selbst ist zusätzlich durch die serverseitige Sperre (`deploy/.deploy.lock`) geschützt, ein zweiter gleichzeitiger Versuch wird mit `REJECTED` abgelehnt (siehe Fehlerhandbuch Fall 9).

**Teilfehler „Backend fehlgeschlagen nach Übertragung“:** Schlägt Candidate-Prüfung oder Migration fehl, wurden die laufenden Container laut `docs/vps/06-betrieb.md` NICHT verändert; kein Rollback nötig, die alte Version läuft unverändert weiter. Schlägt der Health-Check NACH dem Cutover fehl, löst `deploy.sh` automatisch ein Rollback aus.

## (e) Wiederherstellungshandbuch

@@diagramm 13-backup-wiederherstellung

**Wichtige Unterscheidung (verbindlich für diesen Abschnitt):** „Sicherung vorhanden“ (eine Sicherung existiert nachweislich) ist etwas anderes als „Wiederherstellung erfolgreich getestet“ (ein tatsächlicher Rücklese-/Einspielversuch mit dokumentiertem Ergebnis liegt vor). Beide werden unten getrennt bewertet.

### Sicherungsumfang

| Bestandteil | Speicherort | Sicherungsweg | Beleg im Repository |
|---|---|---|---|
| Produktive Datenbank (Coolify-MariaDB) | Coolify-verwaltete Ressource | Täglicher Coolify-Backupplan (`0 3 * * *` laut `docs/betrieb-migration-vps.md` Abschnitt 29), zusätzlicher externer Upload nach Hetzner Object Storage (Bucket `smarteinzug`, Endpoint `https://fsn1.your-objectstorage.com`, laut Abschnitt 29) | `docs/vps/06-betrieb.md` Abschnitt „Backups und Restore-Test“, `docs/betrieb-migration-vps.md` Abschnitte 28 bis 29 |
| Lokale Kopie der Coolify-Sicherungen | `COOLIFY_BACKUP_DIR` auf dem VPS (Standard `/data/coolify/backups`, nur lesend für den Metrik-Sammler) | wird von Coolify selbst gepflegt, nicht vom SmartEinzug-Stack | `deploy/vps/.env.example`, `docs/vps/06-betrieb.md` |
| `shared/storage` (Mandatsdateien, Avatare, Sitzungen) | `/opt/smarteinzug/shared/storage` auf dem VPS | **Kein eigener Sicherungsweg im SmartEinzug-Stack gefunden**; laut `docs/betrieb-migration-vps.md` Abschnitt 29 ist „Sicherung von Dateien und Schlüsseln“ ein separater, als offen gekennzeichneter Nachweis | `docs/betrieb-migration-vps.md` Abschnitt 29, letzter Absatz |
| `config.php`/`shared/config.php` | außerhalb des Release-Verzeichnisses (`app/bootstrap.php:17` f.) | **Kein dokumentierter, automatisierter Sicherungsweg gefunden**; auf dem VPS liegt sie als Einzeldatei-Bind-Mount außerhalb von Git | nicht gefunden |
| Datenbank-Verschlüsselungsschlüssel (`app_secret`, verschlüsselte API-Schlüssel der Firmen) | in `config.php`/`shared/config.php` | siehe oben, kein gesonderter Schlüsselsicherungsweg gefunden | `docs/betrieb-migration-vps.md` Abschnitt 29: „Sicherung von Dateien und Schlüsseln“ ausdrücklich als offener Nachweis geführt |

**Es gibt keinen zweiten Dump-Container im SmartEinzug-Stack** (`docs/vps/06-betrieb.md` Zeile 900 ff.); `deploy/vps/backup/backup.sh` und `deploy/vps/backup/Dockerfile` sind ausdrücklich nur eine Ausweichlösung ohne Coolify, nicht Teil des aktiven Stacks (bestätigt auch in `docs/betrieb-migration-vps.md` Abschnitt 28: „Das Skript selbst ist eine Ausweichlösung und nicht Bestandteil des aktiven Appstacks“).

### Verschlüsselung

**Nur teilweise belegt.** Für den tatsächlich produktiv eingesetzten Coolify-Backupplan wurde im Repository keine Aussage zur Verschlüsselung der Sicherungsdateien gefunden. Für den ausweichenden, nicht aktiven Weg (`deploy/vps/backup/restore-test.sh`) ist eine **optionale** age-Verschlüsselung vorgesehen (Parameter `BACKUP_AGE_IDENTITY`, `restore-test.sh` Zeile 30 ff., vollständig gelesen); `docs/betrieb-migration-vps.md` Abschnitt 29 stellt ausdrücklich klar, dass „optionale age-Verschlüsselung“ eine Eigenschaft des Ausweichskripts ist und **keine nachgewiesene Einstellung des tatsächlich eingesetzten Coolify-Backupplans**. Die Verschlüsselung der produktiv verwendeten Sicherungen ist damit nicht gefunden, nicht widerlegt.

### Aufbewahrung

**Nicht abschließend belegt.** `docs/betrieb-migration-vps.md` Abschnitt 29 nennt „konkrete Aufbewahrungsregeln“ ausdrücklich als offenen Nachweis; die im Ausweichskript `backup.sh` vorgesehene 14-Tage-Rotation gilt laut derselben Quelle nicht automatisch für den produktiven Coolify-Backupplan.

### Wiederherstellungsreihenfolge

**Datenbank (Coolify-MariaDB):**
1. Aktuellen oder gewünschten Dump aus dem Coolify-Dashboard bzw. aus dem Hetzner-Object-Storage-Bucket beziehen.
2. **Wiederherstellungstest in einer isolierten, temporären Datenbank** (nie direkt gegen die produktive Datenbank):
   ```bash
   docker run --rm -it --network coolify \
     -v /pfad/zum/dump:/dump:ro -v "$PWD/deploy/vps/backup:/tools:ro" \
     -e DB_HOST=<containername-der-coolify-mariadb> -e DB_ROOT_PASSWORD=<root-passwort-aus-coolify> \
     mariadb:11 bash /tools/restore-test.sh /dump/<datei>.sql.gz
   ```
   Zielumgebung: VPS (Netz `coolify`, kein veröffentlichter Port nötig). Voraussetzung: `DB_HOST`, `DB_ROOT_PASSWORD`, für `.age`-Dateien zusätzlich `BACKUP_AGE_IDENTITY`. Wirkung (`deploy/vps/backup/restore-test.sh`, vollständig gelesen): legt eine Datenbank `restore_test_<zeitstempel>` an, spielt den Dump ein, zählt die Zeilen je Tabelle, entfernt die temporäre Datenbank danach wieder (`DROP DATABASE`, im `trap`); **berührt die produktive Datenbank zu keinem Zeitpunkt**. Zugangsdaten laufen über eine Optionsdatei mit Rechten 600, nie über die Kommandozeile. Erwartetes Ergebnis: „Wiederherstellungstest erfolgreich“ und eine Tabelle mit Zeilenzahlen je Tabelle.
3. Erst nach erfolgreichem, geprüftem Testlauf und nach ausdrücklicher Freigabe der Geschäftsführung (haftungsrelevante, geldbewegende Daten) eine **DESTRUKTIVE** tatsächliche Wiederherstellung der produktiven Datenbank über die entsprechende Coolify-Funktion durchführen; ein Vorgehen dafür wurde im Repository nicht dokumentiert (Coolify-Bedienung liegt außerhalb dieses Repositorys).
4. Nach einer tatsächlichen Wiederherstellung: `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --db` und eine stichprobenartige Prüfung im Adminbereich (Kennzahlen, Firmenliste).

**Anwendungscode:** über `bash /opt/smarteinzug/deploy/scripts/rollback.sh <git-sha>|previous` (siehe Abschnitt (d) und Fehlerhandbuch Fall 9); ausdrücklich getrennt von einem Datenrestore, spielt keine Migrationen ein und rollt die Datenbank nicht zurück (`deploy/vps/scripts/rollback.sh` Zeile 9 ff.).

**`shared/storage` und `config.php`:** Da kein dokumentierter Sicherungsweg gefunden wurde, gibt es dafür keine belegte Wiederherstellungsreihenfolge; dies ist ein offener Punkt (siehe unten).

### Sicherung vorhanden vs. Wiederherstellung erfolgreich getestet

| Aussage | Bewertung | Beleg |
|---|---|---|
| Tägliche Sicherung der Coolify-MariaDB existiert | **Sicherung vorhanden**, belegt | `docs/betrieb-migration-vps.md` Abschnitt 29: konkreter Dump mit Dateiname, Größe (1.274.269 Byte) und Zeitpunkt (07.09.2026 03:00:06) |
| Externer Upload nach Hetzner Object Storage ist eingerichtet | **Sicherung vorhanden**, belegt (Konfiguration) | `docs/betrieb-migration-vps.md` Abschnitt 29: `save_s3=yes`, `s3_storage_id=1`, Bucket und Endpoint benannt |
| Externer Upload wurde tatsächlich erfolgreich hochgeladen und ist im Bucket auffindbar | **Nicht belegt im Repository** | `docs/betrieb-migration-vps.md` Abschnitt 28: „Objektliste/Download aus S3 und verwendete Restore-Datei nicht vorgelegt“ |
| Ein Restore aus dieser Sicherung wurde erfolgreich getestet | **NUTZERBESTÄTIGT, nicht durch ein im Repository dokumentiertes Protokoll belegt** | `docs/betrieb-migration-vps.md` Abschnitt 28 (Kennzeichnungsschema Zeile 19: „NUTZERBESTÄTIGT: Vom Auftraggeber ausdrücklich mitgeteilt“); wörtlich: „Nicht mitgeliefert wurden Testdatum, Zielinstanz, geprüfte Tabellen/Zeilenzahlen, Prüfsummen und die Information, ob die Datei direkt aus dem Hetzner-Bucket zurückgelesen wurde“ |
| Ein wiederholbares Prüfwerkzeug für Wiederherstellungstests existiert im Repository | **Ja, vorhanden**, aber die regelmäßige tatsächliche Ausführung ist nicht dokumentiert | `deploy/vps/backup/restore-test.sh` (vollständig gelesen); `docs/vps/06-betrieb.md` empfiehlt eine monatliche Wiederholung, ohne einen bereits erfolgten monatlichen Lauf zu belegen |
| Sicherung von `shared/storage`, `config.php` und Verschlüsselungsschlüsseln | **Weder Sicherung noch Wiederherstellung belegt** | `docs/betrieb-migration-vps.md` Abschnitt 29: ausdrücklich als offener Nachweis benannt |

**Fazit dieses Abschnitts:** Die Datenbanksicherung selbst ist mit einem konkreten, benannten Dump belegt. Der externe Upload ist konfiguriert, aber nicht durch eine tatsächliche Objektliste im Bucket nachgewiesen. Ein Restore-Test wurde vom Auftraggeber als erfolgreich mitgeteilt, aber ohne Protokoll, Zeitstempel oder geprüfte Tabellen im Repository hinterlegt; er gilt deshalb hier als NUTZERBESTÄTIGT, nicht als im Repository nachvollziehbar getestet. Die Sicherung von Anwendungsdateien (`shared/storage`) und der Konfigurationsdatei mit den Verschlüsselungsschlüsseln ist nicht belegt.

## Offene Prüfpunkte

- Ein eigenständiges Dokument für die lokale Entwicklungsumgebung fehlt; Abschnitt (a) dieser Datei ist aus Einzelbausteinen abgeleitet und sollte bei Gelegenheit in ein eigenes, offizielles Einrichtungsdokument überführt werden.
- Sicherung von `shared/storage` (Mandatsdateien), `config.php`/`shared/config.php` und den darin enthaltenen Verschlüsselungsschlüsseln ist weder als eingerichtet noch als getestet belegt; hier besteht eine Lücke gegenüber dem sonst dokumentierten Datenbank-Backup.
- Die tatsächliche Objektliste im Hetzner-Bucket sowie ein mit Zeitstempel, Zieltabellen und Zeilenzahlen dokumentierter Restore-Test stehen laut `docs/betrieb-migration-vps.md` noch aus; ein solcher Test mit `deploy/vps/backup/restore-test.sh` sollte nachgeholt und protokolliert werden, bevor er als vollständig geprüft gilt.
- Verschlüsselung der produktiv verwendeten Coolify-Sicherungen (nicht nur des optionalen Ausweichwegs) ist im Repository nicht belegt; vor einer verbindlichen Aussage dazu Rücksprache mit dem Betreiber/Coolify-Administrator nötig.
- Aufbewahrungsfristen des produktiven Coolify-Backupplans sind im Repository nicht belegt (nur die abweichende, nicht aktive 14-Tage-Rotation des Ausweichskripts ist bekannt).

---

Umfang dieser Datei: 5 Hauptabschnitte (a bis e) plus offene Prüfpunkte, rund 155 Zeilen.
