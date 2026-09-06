# Auftrag III: Migration IONOS Webhosting zu hybrider Webhosting-/VPS-Architektur, Abschlussbericht

Stand: 06.09.2026, Version 4.0. Branch claude/setup-lexsepa-monorepo-v5ZcZ. Alle Tests liefen ausschließlich gegen die Testdatenbank und den lokalen Testserver. Es wurde kein VPS bestellt, kein DNS geändert, keine produktive Migration ausgeführt und kein bestehendes Zugangsdatum verändert. Der Docker-Stack wurde syntaktisch geprüft (docker compose config), aber nicht gestartet, weil in der Entwicklungsumgebung kein Docker-Daemon läuft.

## Was wurde umgesetzt

| Bereich | Ergebnis | Details |
|---|---|---|
| Analyse (Phase 1) | Mandantentrennung ohne Cross-Tenant-Befund, Sync ohne offene N+1-Muster, Inventar, Deployment-Risiken | Vier Leseagenten, Ergebnisse in scratchpad/analyse_auftrag3.json, Zusammenfassung in docs/queue-worker.md |
| Job-Queue, Worker, Scheduler | Tabelle jobs mit Prioritäten, Backoff 1/5/15/60 min, Dead Letter, Heartbeats, Circuit Breaker, Feature-Flags, Wartungsmodus je Firma, Sync-Historie, Live-Fortschritt, Mail über Queue, strukturiertes Logging mit Correlation-ID | php-ionos/app/queue.php, jobs.php, features.php, log.php, redis.php, bin/*.php, Migration 018, docs/queue-worker.md |
| Docker-Stack VPS | Caddy (Let's Encrypt automatisch), PHP-FPM, Scheduler, fünf Worker-Pools, Host-Metriken, MariaDB (intern), Redis (intern), Backup mit Verschlüsselung und externem Ziel, Log-Rotation, Ressourcenlimits, Staging-Override | deploy/vps/, Begründung Caddy statt nginx in deploy/vps/README.md |
| Serverskripte | setup-vps.sh, deploy.sh (Sperre, Symlink-Release, Migration per CLI, Reload, Worker-Neustart, Health Check, Rollback), rollback.sh, db-import.sh, db-verify.php, maintenance.sh, backup.sh, restore-test.sh | deploy/vps/scripts, deploy/vps/backup |
| GitHub-Workflow | Jobs changes, test, deploy-webhosting (unverändert im Kern), deploy-vps (nur mit Variable VPS_DEPLOY_ENABLED), Path-Filter, Docs-Build als Artefakt, build.txt mit Version | .github/workflows/deploy.yml |
| Adminbereich | Reiter Jobs, Server, Versionen, Dokumentation; Versionsnummer in der Fußzeile; admin-doc.php | php-ionos/admin-system.php, admin-doc.php, app/layout.php |
| Firmenbereich | Synchronisationen (Historie mit Details), Fortschrittsbalken bei aktiver Queue | php-ionos/synchronisationen.php, sync-status.php, invoices.php |
| Dokumentation | Sieben Kapitel unter docs/vps, Generator tools/build-docs.py erzeugt HTML, fünf SVG-Diagramme und SmartEinzug_Technische_Dokumentation.pdf (54 Seiten) mit Version, Datum, Commit | docs/vps/*.md, tools/build-docs.py, Ausgabe php-ionos/app/docs-build (nicht im Repository, beim Deployment erzeugt) |
| Versionsverlauf | APP_VERSION 4.0, Änderungsverlauf 1.0 bis 4.0 | php-ionos/app/version.php, Admin System, Versionen |

## Geänderte und neue Dateien

Anwendung: app/queue.php (neu), app/jobs.php (neu), app/features.php (neu), app/log.php (neu), app/redis.php (neu), app/version.php (neu), bin/_cli.php, bin/worker.php, bin/scheduler.php, bin/migrate.php, bin/healthcheck.php, bin/host-metrics.php, bin/backup-record.php (neu), sync-status.php (neu), synchronisationen.php (neu), admin-doc.php (neu), admin-system.php, app/layout.php, app/monitor_view.php, app/help_content.php, app/bootstrap.php (Proxy-Erkennung, Correlation-ID, Wartungsmodus, Konfigurationspfad, storage_dir), app/audit.php, app/mailer.php, app/lexoffice.php, app/stripe.php, app/sync.php, app/sync_state.php, app/mandate_files.php, app/profile.php, app/migrate.php, app/config.example.php, cron.php, invoices.php, setup-check.php, assets/js/app.js, assets/css/style.css, sql/schema.sql, sql/migrations/018_queue_worker.sql, ANLEITUNG-IONOS.md.

Infrastruktur und Dokumentation: deploy/vps/** (neu), .github/workflows/deploy.yml, .gitignore, tools/build-docs.py (neu), docs/vps/01 bis 07 (neu), docs/queue-worker.md (neu), CLAUDE.md.

## Tabellen und Migrationen

Migration 018: jobs, worker_heartbeats, sync_runs, api_circuits, organizations.sync_paused, sync_paused_reason, feature_flags. Wiederholbar, über den vorhandenen Runner (migrate.php auf dem Webhosting, bin/migrate.php auf dem VPS). Keine Doppelstrukturen: Versuche in job_runs, Monitoring in monitor_checks, Audit in audit_log.

## Docker-Dienste

caddy, php, scheduler, worker-lexware-1, worker-lexware-2 (in Staging über Profil abgeschaltet), worker-stripe, worker-mail, worker-maintenance, metrics, mariadb, redis, backup. Nur Caddy veröffentlicht Ports 80 und 443. Netze edge und smarteinzug_internal (internal). Container binden nur /opt/smarteinzug/releases lesend ein und arbeiten mit /opt/smarteinzug/releases/current (Symlink zeigt in denselben Mount und wird im Container aufgelöst; deploy/.env, Backups und Logs sind für keinen Container sichtbar); Konfiguration über SMARTEINZUG_CONFIG=/opt/smarteinzug/shared/config.php, Speicher über storage_dir.

## Benötigte Secrets und Variablen (GitHub)

Bestehend: SFTP_HOST, SFTP_USER, SFTP_PASSWORD, SFTP_PORT, SFTP_PATH, MIGRATION_TOKEN. Neu für den VPS: Secrets VPS_HOST, VPS_SSH_USER, VPS_SSH_PORT, VPS_SSH_PRIVATE_KEY, VPS_SSH_KNOWN_HOSTS, optional VPS_DEPLOY_PATH; Variablen VPS_DEPLOY_ENABLED (Standard nicht gesetzt, Job inaktiv), WEBHOSTING_APP_DEPLOY (Standard true), VPS_APP_DOMAIN, VPS_HEALTH_STRICT. Die Datenbank wird nicht an GitHub geöffnet; Migrationen laufen auf dem VPS über deploy.sh. Details docs/vps/03-github-deployment.md.

## DNS-Änderungen (erst nach Test, dokumentiert in docs/vps/05-dns-ssl.md)

app, admin, api, status und optional staging als A-Einträge auf die VPS-Adresse; TTL vorher auf 300 Sekunden senken. Marketingdomains bleiben auf dem Webhosting.

## Manuelle Schritte, die noch ausstehen

1. VPS einrichten: überholt, da bereits ein Hostinger-VPS (Tarif KVM 8, Vorlage „Ubuntu 24.04 with Coolify“) beschafft wurde, siehe Abschnitt „Nachtrag Hostinger KVM 8 (Coolify)“ unten; Einrichtung nach docs/vps/08-hostinger-coolify.md (setup-vps.sh, .env aus .env.example, shared/config.php).
2. GitHub-Secrets und Variablen setzen, ersten Lauf mit VPS_DEPLOY_ENABLED=true über workflow_dispatch prüfen (docs/vps/03).
3. Datenbank nach Runbook docs/vps/04 übertragen und mit db-verify.php beidseitig vergleichen; Cutover mit Wartungsmodus.
4. Feature-Flag queue zuerst für eine Testfirma aktivieren (Admin, System, Jobs), dann für alle.
5. HSTS nach Freigabe durch die Geschäftsführung aktivieren, ufw-Docker-Kopplung bestätigen, nach dem Cutover HEALTH_STRICT=true in deploy/.env setzen (deploy/vps/README.md, Offene Punkte).
6. Nach dem Cutover WEBHOSTING_APP_DEPLOY=false setzen, alte Datenbank einige Tage als Referenz belassen, dann Zugangsdaten entfernen.

## Adversariale Abnahme: Befunde und Korrekturen

Vier unabhängige Prüfagenten (Fable, hoher Aufwand) haben die Bereiche Warteschlange und Idempotenz, Mandantentrennung und Sperren, Deployment und Container sowie Bootstrap, Proxy, Logging und Mailer mit dem Auftrag geprüft, konkrete Fehlerszenarien zu konstruieren. Alle Befunde mit Schwere mittel oder höher wurden behoben, die niedrigen ebenfalls, soweit sie ohne Architekturänderung lösbar waren.

| Bereich | Befund | Korrektur |
|---|---|---|
| Queue | Wiederholung aus der Dead-Letter-Ansicht nicht atomar, Fortsetzungen ohne Obergrenze, Mail-Jobs behielten bei endgültigem Fehlschlag Inhalt inklusive Links | queue_retry_now mit Statusbedingung, Fortsetzungsgrenze 500, Mail-Payload bei failed reduziert, queue_prune räumt geschlossene failed-Jobs |
| Queue | Fortsetzungsjob konnte denselben Einzug erneut versuchen | skip_ids und handled_ids in process_scheduled_collections, Audit ohne interne Kennungen |
| Queue | Abgelehnte Empfänger führten zu Circuit-Breaker-Fehlern | Unterscheidung rejected (fachlich, kein Retry) und transport (Retry, Breaker) |
| Mandanten | Admin-Systemseiten auf dem Kundenhost erreichbar | Host-Regeln um admin-system, admin-system-data, admin-doc ergänzt |
| Deployment | Container sahen das gesamte /opt/smarteinzug einschließlich deploy/.env; Metrik-Sammler mit Wurzeldateisystem des Hosts und Root | Nur releases/ (lesend), shared/config.php (lesend), shared/storage und shared/sessions eingebunden; Metrik-Sammler ohne Root und ohne Wurzeldateisystem |
| Deployment | source .env im Skript, Rollback aus deploy.sh scheiterte an der eigenen Sperre, kein Stop-Grace für laufende Jobs, Rollback ohne Schemaprüfung, Image-Prüfsumme nach Rollback veraltet | envval ohne source, Sperre vor Rollback freigegeben, stop_grace_period und restart -t 660, Verträglichkeitsprüfung des Migrationsstands (FORCE_ROLLBACK), Prüfsumme nach Build geschrieben |
| Deployment | Statusseite im Release, Caddy suchte sie außerhalb; Workflow rief das alte deploy.sh auf; rsync mit --delete konnte deploy/ und status/ im Release löschen | Caddy liefert releases/current/status, Workflow ruft deploy.sh aus dem neuen Release, rsync-Ausschlüsse |
| Deployment | Zugangsdaten im Wiederherstellungstest auf der Kommandozeile | defaults-extra-file mit Rechten 600 |
| Bootstrap | Wartungsmodus und Adminhost-Trennung über /assets/../seite.php umgehbar | Ausnahmen richten sich nur nach dem ausgeführten Skript |
| Bootstrap | Wartungsmodus wirkte nicht auf Scheduler und Worker | Beide pausieren bei aktivem Marker (Heartbeat läuft weiter), maintenance.sh dokumentiert die Wirkung, Admin zeigt Beginn und Dauer, Warnung ab 12 Stunden |
| Bootstrap | X-Forwarded-For: linkester Eintrag ungeprüft übernommen | Auswertung von rechts, vertrauenswürdige Hops übersprungen, IP-Validierung, sonst REMOTE_ADDR unverändert |
| Logging | Correlation-ID aus dem Header von jedem Client übernommen; whsec_ und API-Schlüssel nicht maskiert | Nur hinter vertrauenswürdigem Proxy, erweiterte Maskierung |
| Mailer | Empfängeradressen im Fehlerprotokoll | Gekürzter Hash plus Domain |
| Ratenbegrenzung | Kontingent im Zielfenster nicht reserviert, Durchlass nach 5 Sekunden | Schleife mit erneuter Reservierung, Deckel 30 s (Worker, danach Wiederholung) bzw. 2 s (Web) |

Bewusst nicht geändert: Webhooks erhalten im Wartungsmodus 503 (gewollt, damit nichts mehr in die alte Datenbank schreibt); die Cutover-Checkliste enthält jetzt das erneute Senden fehlgeschlagener Stripe-Ereignisse.

## Durchgeführte Tests

Finaler Lauf am 06.09.2026 auf frischer Testdatenbank (sql/schema.sql) gegen den lokalen PHP-Testserver und lokalen Redis, in der festgelegten Reihenfolge. Kein Test berührt Produktivsysteme.

| Suite | Prüfungen | Ergebnis |
|---|---|---|
| e2e_saas.php (Abschnitte 1 bis 30, neu: 30 Härtung Proxy-Header, Wartungsmodus, Scheduler/Worker) | 404 | bestanden |
| test_monitor.php | 49 | bestanden |
| test_queue.php (neu: Maskierung, Adresskennung, Ratenbegrenzung) | 73 | bestanden |
| test_payment_safety.php | 140 | bestanden |
| test_rules_sync.php | 36 | bestanden |
| test_sync_perf.php | 17 | bestanden |
| test_sync_lock.php | 14 | bestanden |
| test_migrate_endpoint.php | 29 | bestanden |

Lauf für Version 4.3 (Coolify-MariaDB statt eigener Datenbank im Stack) am 06.09.2026 auf frischer Testdatenbank: e2e_saas.php 410, test_monitor.php 49, test_queue.php 77, test_payment_safety.php 140, test_rules_sync.php 60, test_sync_perf.php 17, test_sync_lock.php 14, test_migrate_endpoint.php 29, alle bestanden. Zusätzlich docker compose config für prod und staging ohne mariadb- und backup-Dienst, bash -n aller Skripte, Funktionsprobe des Backup-Scans in bin/host-metrics.php (schreibt backup-status.json aus einem Testverzeichnis).

Lauf für Version 4.2 (Ratenbegrenzung je Firma) am 06.09.2026 auf frischer Testdatenbank: e2e_saas.php 410, test_monitor.php 49, test_queue.php 77 (neu: Kontingente je API-Schlüssel unabhängig, Obergrenze insgesamt), test_payment_safety.php 140, test_rules_sync.php 60, test_sync_perf.php 17, test_sync_lock.php 14, test_migrate_endpoint.php 29, alle bestanden.

Lauf für Version 4.1 (Paket 4b und Hostinger-Anpassung) am 06.09.2026, wieder auf frischer Testdatenbank: e2e_saas.php 410 (neu Abschnitt 31), test_monitor.php 49, test_queue.php 73, test_payment_safety.php 140, test_rules_sync.php 60 (neu Abschnitt 5 Tarifwechsel und Upsell), test_sync_perf.php 17, test_sync_lock.php 14, test_migrate_endpoint.php 29, alle bestanden. Zusätzlich docker compose config für prod und staging mit den Traefik-Labels, bash -n, php -l.

Im ersten Lauf nach den Korrekturen (Version 4.0) schlugen zwei der neuen Prüfungen fehl: die Anmeldeseite wurde mit angemeldeter Sitzung geprüft (Weiterleitung zum gesperrten Dashboard, Testfehler) und mail_addr_ref gab die Domain nicht kleingeschrieben zurück (Funktion angepasst). Zweiter Lauf vollständig grün.

Zusätzlich: docker compose config für prod und staging, bash -n für alle Skripte, php -l für alle geänderten PHP-Dateien, YAML-Prüfung des Workflows, Bau der Dokumentation (tools/build-docs.py, PDF mit Version und Commit).

## Nicht geprüft

Start der Container und echte Health Checks (kein Docker-Daemon in der Entwicklungsumgebung), Let's Encrypt, SSH-Deployment auf einen echten VPS, Datenbankimport mit Produktionsdaten, Verhalten mehrerer Worker unter Last, echte Lexware- und Stripe-Störungen, Backup auf ein externes Ziel, rclone-Installation im Backup-Image.

## Version 4.2: Ratenbegrenzung je Firma

Befund aus der Rückfrage zur Skalierung: Die zentrale Ratenbegrenzung aus Auftrag III zählte alle Lexware- und Stripe-Aufrufe über alle Firmen zusammen (2 bzw. 20 je Sekunde). Da jede Firma ihr eigenes Lexware-Office-Konto und ihr eigenes Stripe-Konto mit eigenem API-Schlüssel nutzt und die Grenzen der Anbieter je Schlüssel gelten, war das eine selbst gesetzte Bremse, die bei vielen Firmen den Durchsatz begrenzt hätte.

Korrektur: `api_call_gate()` zählt je Anbieter und API-Schlüssel (kurze, nicht rückrechenbare Kennung des Schlüssels, nie der Schlüssel selbst) und zusätzlich gegen eine konfigurierbare Obergrenze insgesamt (`lexoffice_global_per_second` 50, `stripe_global_per_second` 200, Schutz der eigenen Worker und Absenderadresse). Ein Rate-Limit (429) einer einzelnen Firma zählt nicht mehr für den Circuit Breaker des Anbieters; der Breaker reagiert nur noch auf Verbindungsfehler und Serverfehler. Der Durchsatz der Synchronisation wächst damit mit der Zahl der Worker. Die Annahme 2 Aufrufe je Sekunde je Schlüssel für Lexware bleibt zu verifizieren.

## Paket 4b: Tarifwechsel und Upsell (Version 4.1)

Umgesetzt am 06.09.2026, wirksam nur, wenn `billing.enabled` gesetzt ist und mindestens zwei Tarife aktiv und öffentlich sind (Tabelle `plans`). Mit nur einem Tarif ändert sich für Kunden nichts.

| Baustein | Umsetzung |
|---|---|
| Kandidatenermittlung | `plan_upgrade_candidate()` in app/plans.php: günstigster aktiver, öffentlicher Tarif mit höherem Preis, der den Bedarf deckt (Benutzer oder Einzüge); Starttarif ohne Grenze braucht kein Upgrade |
| Upsell-Hinweise | Firmendaten > Mitarbeiter einladen (Benutzerlimit oder Tarif ohne Einladungen), Rechnungsseite ab 80 Prozent und bei ausgeschöpftem Kontingent, Fehlermeldung beim Vormerken eines Einzugs; jeweils mit Link auf Firma > Abonnement |
| E-Mail an den Inhaber | `plan_quota_warning_maybe_send()` einmal je Abrechnungsperiode (Spalte `quota_warning_period_start`, Migration 019), Audit `quota_warning_sent` |
| Tarifwechsel | `billing_change_plan()` in app/billing.php: bestehendes Stripe-Abo wird auf den neuen Preis umgestellt. Upgrade: `proration_behavior=always_invoice`, `payment_behavior=error_if_incomplete` (scheitert die Zahlung, bleibt der alte Tarif). Downgrade: `create_prorations` (Gutschrift auf die nächste Rechnung), Downgrade-Schutz über `plan_change_allowed`. Bestellbestätigung (AGB, Unternehmer) wird wie beim Abschluss protokolliert, Wechsel im Audit `subscription_plan_changed`, Sicherheits-E-Mail an den Inhaber |
| Tarifwahl vor Abschluss | `billing_choose_plan()`: setzt nur `plan_code`, danach Checkout mit diesem Tarif |
| Oberfläche | Firma > Abonnement, Kasten „Tarif wechseln“ mit allen anderen öffentlichen Tarifen, Richtung (Upgrade/Downgrade), Grenzen, Preis netto plus USt-Hinweis; Leistungen dynamisch aus dem Tarif |
| Tests | test_rules_sync.php Abschnitt 5 (Kandidaten, Sitz- und Kontingentmeldungen, E-Mail einmal je Periode, Upgrade und Downgrade mit Ersatz-Stripe-Client, Downgrade-Schutz, Tarifwahl), E2E Abschnitt 31 (ohne Abrechnung keine Hinweise, Wechsel abgelehnt) |

Voraussetzungen vor dem Scharfschalten: Stripe-Preis-IDs für alle aktiven Tarife in Admin > Tarife, Testkauf und Testwechsel im Stripe-Testmodus, Webhook `customer.subscription.updated` aktiv (ANLEITUNG-IONOS.md, Abschnitt 6, Punkt 6).

## Nachtrag Hostinger KVM 8 (Coolify)

Stand: 06.09.2026. Nach Abschluss von Auftrag III wurde tatsächlich kein IONOS VPS bestellt,
sondern ein Hostinger-VPS beschafft: Tarif KVM 8 (8 vCPU, 32 GB RAM, 400 GB NVMe), Vorlage
„Ubuntu 24.04 with Coolify“, Coolify bereits installiert und laufend. Hostname
`srv1960492.hstgr.cloud`, IPv4 `72.61.80.67`, SSH-Zugang zunächst als `root` (Passwort aus dem
Hostinger-Kundenbereich). Dieser Nachtrag beschreibt die daraus folgenden Entscheidungen und
Dokumentationsänderungen; er ersetzt die Annahme eines IONOS VPS ohne vorinstallierte Software in
den Kapiteln `docs/vps/01` bis `docs/vps/07`.

### Entscheidungen

- Coolify läuft auf demselben Server und wird ausschließlich als Reverse Proxy (Traefik auf
  80/443, automatisches TLS über Let's Encrypt) und als Serverübersicht genutzt. Für SmartEinzug
  wird in Coolify AUSDRÜCKLICH keine Anwendung/Ressource angelegt, kein Coolify-Autodeploy
  eingerichtet und keine GitHub-App in Coolify verbunden. Der einzige Deploymentweg für den VPS
  bleibt der bestehende GitHub-Workflow (`.github/workflows/deploy.yml`, Job `deploy-vps`).
- Die Coolify-Oberfläche (Port 8000) wird nicht öffentlich freigegeben, sondern ausschließlich per
  SSH-Tunnel erreicht.
- Der Docker-Stack (`deploy/vps/docker-compose*.yml`, `Caddyfile`, `Caddyfile.staging`,
  `.env.example`) wurde bereits vor diesem Nachtrag auf die neue Proxykette umgestellt: Der
  `caddy`-Container veröffentlicht keine Ports mehr (`auto_https off`, reines HTTP intern),
  Traefik-Labels binden ihn an das Docker-Netz `COOLIFY_NETWORK` (Standardname `coolify`).
  `LETSENCRYPT_EMAIL` entfällt in `.env`, `COOLIFY_NETWORK` ist neu (eine gleichnamige Variable
  wurde im Nachtrag Coolify-MariaDB unten einheitlich benannt). Diese Dateien waren zum
  Zeitpunkt dieses Dokumentationsnachtrags bereits angepasst und dienten als Quelle für die
  Aktualisierung von `docs/vps/01` bis `docs/vps/08`.
- Neu erstellt: `docs/vps/08-hostinger-coolify.md`, eine vollständige Schritt-für-Schritt-Anleitung
  für den tatsächlichen Weg (Coolify-Assistent, Root-Zugang, `setup-vps.sh`,
  Coolify-Proxy-Prüfung, `shared/config.php` mit `trusted_proxies`, `.env`, erstes Deployment,
  Datenbankimport, Test ohne DNS-Änderung).
- `docs/vps/01` bis `docs/vps/07` wurden dort angepasst, wo sie IONOS-VPS-Annahmen oder eine
  TLS-Terminierung durch Caddy selbst voraussetzten (Proxykette, Firewall, `.env`-Variablen,
  Zertifikatsprüfung, DNS-Zielwert, GitHub-Secrets, Fingerabdruckprüfung); der übrige Inhalt bleibt
  unverändert gültig.
- Produktive DNS-Einträge wurden während dieser Umstellung NICHT geändert; die Umschaltung folgt
  erst nach vollständigem Test gemäß `docs/vps/05-dns-ssl.md` und
  `docs/vps/07-cutover-checkliste.md`.

### setup-vps.sh: Coolify-Erkennung

`deploy/vps/scripts/setup-vps.sh` erkennt eine bereits laufende Coolify-Installation (Container
mit Namen `coolify*`) und verhält sich dann abweichend von der Einrichtung eines Servers ohne
Coolify: Docker wird nur installiert, wenn es fehlt; ein bereits aktives `ufw` wird nicht
zurückgesetzt, sondern nur um `allow 22/tcp`, `allow 80/tcp`, `allow 443/tcp` ergänzt; Port 8000
(Coolify-Oberfläche) sperrt das Skript dabei standardmäßig ausdrücklich nach außen
(`ufw deny 8000/tcp`), optional lässt sich mit der Umgebungsvariable
`COOLIFY_UI_ALLOW_FROM=<eigene IP>` eine einzelne Adresse freigeben. Die in
`docs/vps/08-hostinger-coolify.md` beschriebene Anleitung verwendet unabhängig davon durchgängig
den SSH-Tunnel als Zugriffsweg auf die Coolify-Oberfläche, damit sie auch ohne eine feste eigene
IP-Adresse funktioniert. Dieses Verhalten ist im Repository vorbereitet; ob es auf dem
tatsächlichen Server wie beschrieben greift (insbesondere die Erkennung des laufenden
Coolify-Containers und der Zustand von `ufw` vor dem ersten Lauf), ist beim ersten Durchlauf von
`docs/vps/08-hostinger-coolify.md`, Schritt 3, auf dem Server zu prüfen.

### Statusstufen

| Baustein | Stand |
|---|---|
| Hostinger-VPS beschafft, Coolify installiert und laufend | produktiv eingerichtet (vom Nutzer bestätigt) |
| Docker-Stack auf Coolify/Traefik-Proxykette umgestellt (Compose, Caddyfile, `.env.example`) | vorbereitet (im Repository) |
| `setup-vps.sh` erkennt Coolify (Firewall wird ergänzt statt zurückgesetzt, Port 8000 gesperrt bzw. optional per `COOLIFY_UI_ALLOW_FROM` freigegeben) | vorbereitet (im Repository); Wirkung auf dem tatsächlichen Server noch nicht bestätigt |
| Dokumentation `docs/vps/01` bis `docs/vps/08` auf den Hostinger-VPS aktualisiert | vorbereitet (im Repository) |
| Ersteinrichtung nach `docs/vps/08-hostinger-coolify.md` (Schritte 1 bis 13) | offen |
| Erstes Deployment über den GitHub-Workflow auf den Hostinger-VPS | offen |
| Datenbankimport von Bestandsdaten | offen |
| Produktive DNS-Umstellung, Cutover | offen, ausdrücklich noch nicht vorgenommen |

## Nachtrag Coolify-MariaDB (Version 4.3)

Stand: 06.09.2026. Nach dem Nachtrag Hostinger KVM 8 (Coolify) hat der Betreiber die Datenbank
tatsächlich eingerichtet: nicht als Dienst im SmartEinzug-Docker-Stack, sondern als eigene, private
Coolify-Datenbankressource. Dieser Nachtrag beschreibt die daraus folgenden Entscheidungen; er
ersetzt in `docs/vps/01` bis `docs/vps/08` die vorherige Annahme eines `mariadb`-Dienstes und eines
eigenen Backup-Containers im Stack.

### Bestätigter Stand (vom Betreiber)

MariaDB Version 11.8.9, Datenbankname `smarteinzug`, Port 3306 nicht öffentlich, persistenter
Speicher, Healthcheck erfolgreich, Lesen und Schreiben mit dem normalen Datenbankbenutzer getestet,
tägliche Sicherung in Coolify eingerichtet, zusätzlich externer Upload in einen
Hetzner-Object-Storage-Bucket, Restore aus dem externen Backup erfolgreich getestet. Der Betreiber
hat außerdem eine Coolify-GitHub-App für SmartEinzug angelegt.

### Entscheidungen

- **Keine doppelte Datenbank:** `deploy/vps/docker-compose.yml` enthält keinen Dienst `mariadb` und
  kein zugehöriges Volume mehr. Die Anwendung verbindet sich ausschließlich mit der als
  Coolify-Ressource eingerichteten MariaDB, erreichbar über das Docker-Netz `coolify` unter ihrem
  Containernamen (`db.host` in `shared/config.php`, Platzhalter
  `<containername-der-coolify-mariadb>` in der Dokumentation, abzulesen in Coolify bei der
  Datenbankressource in der internen Verbindungsadresse oder mit `docker ps`).
- **Kein zweiter Backupweg:** `deploy/vps/docker-compose.yml` enthält keinen Dienst `backup` mehr.
  Die Sicherung übernimmt ausschließlich Coolify (täglich, externer Upload nach Hetzner Object
  Storage, Restore getestet). Der Metrik-Sammler (`metrics`) bindet den lokalen Sicherungspfad
  (`COOLIFY_BACKUP_DIR`) nur lesend ein und meldet Zeitpunkt und Größe der neuesten Sicherung als
  `backup-status.json`, damit der Adminbereich System die Komponente „Sicherungen“ weiterhin zeigt.
  `deploy/vps/backup/backup.sh` und das zugehörige `Dockerfile` bleiben nur als Ausweichlösung ohne
  Coolify bestehen, sind aber nicht Teil des Stacks; `restore-test.sh` bleibt als Werkzeug für
  zusätzliche Wiederherstellungstests eines heruntergeladenen Coolify-Dumps in einem kurzlebigen
  Client-Container im Netz `coolify`.
- **Kein öffentlicher Port:** „Public Port“ bleibt in Coolify bei der Datenbankressource aus; von
  außen muss `nc -zv 72.61.80.67 3306` fehlschlagen (auf dem Server zu prüfen).
- **Netzzuordnung:** Die zuvor anders benannte Umgebungsvariable für das Docker-Netz des
  Coolify-Proxys heißt in `.env` jetzt einheitlich `COOLIFY_NETWORK` (Standardwert `coolify`);
  Caddy, PHP, Scheduler, alle Worker und `metrics`
  hängen an diesem Netz, weil sie darüber sowohl die Coolify-MariaDB als auch das Internet
  erreichen. Liegt die Datenbank in einem anderen Docker-Netz als der Coolify-Proxy (mit
  `docker inspect <containername>` zu prüfen), ist entweder `COOLIFY_NETWORK` auf dieses Netz zu
  setzen, oder die Datenbankressource ist in Coolify im Standardziel (Server localhost, Netz
  `coolify`) neu anzulegen; ein manuelles `docker network connect` wird nicht empfohlen, da Coolify
  den Container jederzeit neu erzeugen kann.
- **GitHub-App entfernbar:** Die bereits angelegte Coolify-GitHub-App für SmartEinzug wird in diesem
  Architekturmodell nicht benötigt (kein Coolify-Autodeploy, keine Coolify-Application). Solange
  keine Application in Coolify daran gebunden ist, löst sie keinen Autodeploy aus; nach
  erfolgreicher Einrichtung des bestehenden SSH-Deploymentwegs kann sie sowohl in Coolify (Bereich
  „Sources“) als auch in GitHub (Settings > Applications) wieder entfernt werden.
- **Datenimport:** `scripts/db-import.sh` spielt einen Dump per `docker exec -i` in den Container
  der Coolify-MariaDB ein (nutzt die von Coolify gesetzten Umgebungsvariablen `MARIADB_USER`,
  `MARIADB_PASSWORD`, `MARIADB_DATABASE` im Container), prüft vorher die Prüfsumme und fragt nach
  Bestätigung. Frühere Aufrufe der Form „docker compose exec mariadb ...“ in der Dokumentation
  wurden entsprechend auf „docker exec <containername-der-coolify-mariadb> ...“ umgestellt.
  Migrationen laufen unverändert ausschließlich über `deploy.sh` (`bin/migrate.php` im
  `php`-Container).
- **Healthchecks:** Der `php`-Container prüft die Datenbank mit `bin/healthcheck.php --db`
  (verbindet über `config.php` zur Coolify-MariaDB); es gibt keinen wartenden `mariadb`-Healthcheck
  mehr im Stack. Coolify zeigt die Datenbank als `healthy`, der `php`-Container wird `healthy`,
  sobald die Verbindung steht.
- **Ressourcen:** `docker-compose.prod.yml` begrenzt nur noch die Dienste des SmartEinzug-Stacks
  (rund 6 GB RAM). Das Speicherlimit der Coolify-MariaDB wird in Coolify gesetzt (Empfehlung 4 GB,
  `innodb-buffer-pool-size` rund 2,5 GB, sofern Coolify das Setzen erlaubt, auf dem Server zu
  prüfen).

### Statusstufen

| Baustein | Stand |
|---|---|
| Coolify-MariaDB als eigene, private Datenbankressource (Version 11.8.9, Datenbank `smarteinzug`) mit persistentem Speicher | produktiv eingerichtet (vom Betreiber bestätigt) |
| Kein öffentlicher Port der Datenbank, Healthcheck erfolgreich, Lesen/Schreiben mit dem normalen Benutzer getestet | produktiv eingerichtet (vom Betreiber bestätigt) |
| Tägliche Coolify-Sicherung, externer Upload nach Hetzner Object Storage, Restore aus dem externen Backup | produktiv eingerichtet und getestet (vom Betreiber bestätigt) |
| `deploy/vps/` (Compose, `.env.example`, Skripte) ohne `mariadb`- und `backup`-Dienst umgestellt | vorbereitet (im Repository) |
| Dokumentation `docs/vps/01` bis `docs/vps/08` und dieser Nachtrag auf die Coolify-MariaDB aktualisiert | vorbereitet (im Repository) |
| Netzzuordnung von Coolify-Proxy und Coolify-MariaDB (`docker inspect`, dasselbe Docker-Netz) | auf dem Server zu prüfen |
| Coolify-GitHub-App für SmartEinzug entfernt | offen; erst nach erfolgreicher Einrichtung des SSH-Deployments vorgesehen |
| Datenbankimport von Bestandsdaten, produktive DNS-Umstellung, Cutover | offen, wie im vorherigen Nachtrag |

### Offene Prüfpunkte auf dem Server

- Containername der Coolify-MariaDB tatsächlich per `docker ps` bzw. Coolify ablesen und in
  `shared/config.php` (`db.host`) sowie in `.env` (`DB_CONTAINER`) eintragen.
- Netzzuordnung von Coolify-Proxy und Coolify-MariaDB mit `docker inspect <containername>` prüfen;
  bei Abweichung `COOLIFY_NETWORK` anpassen oder die Datenbankressource neu anlegen.
- Von außen bestätigen, dass Port 3306 tatsächlich unerreichbar ist (`nc -zv 72.61.80.67 3306`).
- Hostpfad der lokalen Coolify-Sicherungskopien (`COOLIFY_BACKUP_DIR`) bestätigen, damit die
  Komponente „Sicherungen“ im Adminbereich System nicht veraltet oder „nicht eingerichtet“ zeigt.
- Testverbindung aus einem kurzlebigen Client-Container im Netz `coolify` gegen die Coolify-MariaDB
  ausführen (siehe `docs/vps/08-hostinger-coolify.md`, Schritt 5a).

## Verbleibende Risiken

- Ein VPS ohne Hochverfügbarkeit: Ausfall bedeutet Nichtverfügbarkeit bis zur Wiederherstellung aus Backup (bewusst, Auftrag Abschnitt 97).
- Rollback stellt nur den Code zurück, nicht das Schema; Migrationen sind additiv, ein schemabrechender Schritt bräuchte einen manuellen Plan.
- Der Wert 2 Aufrufe je Sekunde für Lexware ist eine Annahme; die Lexware-Dokumentation war nicht erreichbar.
- E-Mail-Jobs können bei Absturz zwischen Übergabe und Statusspeicherung doppelt zustellen (höchstens drei Versuche).
- Die Queue ist auf dem Webhosting bis zur Aktivierung des Flags ohne Wirkung; die Hybridverarbeitung im Cron ist mit einer Testfirma zu erproben, bevor der VPS übernimmt.
