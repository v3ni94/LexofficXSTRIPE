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

## Nachtrag Erst-Deployment: Healthcheck des Metrik-Sammlers (Version 4.4)

Stand: 06.09.2026. Der manuelle Erst-Deploy auf dem Hostinger-VPS KVM 8 ist erfolgreich
abgeschlossen: `/opt/smarteinzug/releases/current` zeigt auf `releases/manual-initial`; `php`,
`redis`, `scheduler`, `worker-lexware-1`, `worker-lexware-2`, `worker-stripe`, `worker-mail`,
`worker-maintenance` und `metrics` sind `healthy`, Caddy läuft; genutzt wird die bestehende
Coolify-MariaDB (Containername `fywft1vc4rr5uyy3mw7lgy4s`, Docker-Netz `coolify`, Datenbank
`smarteinzug`); die IONOS-Bestandsdaten wurden importiert; `bin/migrate.php` meldet
„0 eingespielt, 0 offen“. Bei diesem ersten Deployment trat ein Fehler auf, dessen endgültige
Lösung dieser Nachtrag beschreibt.

### Fehlerbild

Der Container `smarteinzug-metrics-1` wurde als `unhealthy` gemeldet, und `deploy.sh` brach ab,
obwohl der Metrik-Prozess tatsächlich lief. Meldung: „UNGESUND: heartbeat: kein frischer
Heartbeat“.

### Ursache

Der Dienst `metrics` hatte in `deploy/vps/docker-compose.yml` keinen eigenen Healthcheck und
übernahm deshalb den Standard-Healthcheck des PHP-Images (`HEALTHCHECK` im Dockerfile:
`php bin/healthcheck.php --heartbeat`). Dieser Healthcheck prüft den Worker-Heartbeat, den
`bin/host-metrics.php` bewusst nicht schreibt.

Als getestete Zwischenlösung auf dem Server diente ein `CMD-SHELL`-Healthcheck mit einem
PHP-Einzeiler, der `/proc/1/cmdline` liest; dabei zeigte sich eine zweite Falle: In Docker Compose
muss `$$c` statt `$c` geschrieben werden, sonst wertet Compose `$c` als eigene Variable aus („The
"c" variable is not set. Defaulting to a blank string.“), und im Container entsteht ein
beschädigter PHP-Befehl.

### Endgültige Lösung im Repository

1. Neuer dedizierter Modus `php bin/healthcheck.php --metrics`. Er prüft zweistufig, ob
   `bin/host-metrics.php` als PID 1 läuft (gelesen aus `/proc/1/cmdline`, Argumente dort durch
   Nullbytes getrennt) und ob die Sammelschleife zuletzt innerhalb von
   `METRICS_MAX_AGE_SECONDS` (Standard 300 Sekunden) einen Durchlauf beendet hat. Letzteres beruht
   auf einer eigenen Heartbeat-Datei (`METRICS_HEARTBEAT_FILE`), die `bin/host-metrics.php` beim
   Start und nach jedem Durchlauf rein lokal schreibt, bewusst außerhalb der Fehlerbehandlung,
   damit eine kurzzeitig nicht erreichbare Datenbank den Container nicht fälschlich als ungesund
   markiert; sie belegt „die Schleife läuft“, nicht „alle Messwerte liegen vor“.
2. `deploy/vps/php/Dockerfile` enthält jetzt `HEALTHCHECK NONE`. Jeder Dienst definiert seinen
   Healthcheck in `docker-compose.yml` selbst (`php`: `--db`, `scheduler` und alle `worker-*`:
   `--heartbeat`, `metrics`: `--metrics`); ein vergessener Eintrag fällt dadurch als „kein
   Healthcheck“ auf und nicht als falsches Ergebnis eines fremden Checks.
3. Neuer Regressionstest `tools/compose-check.py` (ohne Docker-Daemon lauffähig, benötigt
   PyYAML). Er prüft unter anderem, dass jeder Dienst aus dem Image `smarteinzug-php:local` einen
   eigenen, zum tatsächlichen Kommando passenden Healthcheck hat, dass kein Healthcheck ein
   unescaptes `$` enthält, dass jede Compose-Variable einen Vorgabewert hat oder in
   `.env.example` steht, und dass das Dockerfile `HEALTHCHECK NONE` enthält. Der Test ist im
   GitHub-Workflow im Job „test“ eingebunden und wurde mit vier Negativproben verifiziert (`metrics`
   ohne Healthcheck, `metrics` mit geerbtem Worker-Heartbeat, Healthcheck mit unescaptem `$c`,
   Dockerfile mit vererbbarem Standard-Healthcheck). Lokaler Aufruf: `python3 tools/compose-check.py`.
4. Neuer Test `scratchpad/test_healthcheck.php` mit 11 Prüfungen, alle bestanden (unter anderem:
   richtiger Prozess mit frischem Lebenszeichen gesund, ohne Lebenszeichen ungesund, Lebenszeichen
   400 Sekunden alt ungesund, `METRICS_MAX_AGE_SECONDS` hebt die Grenze an, php-fpm oder ein Worker
   als PID 1 ungesund, `--heartbeat` unverändert, Lebenszeichen liegt binnen 1,5 Sekunden nach dem
   Start vor, weshalb `start_period` von 20 Sekunden genügt).

### Begründung der Architekturentscheidung

Die Prüflogik liegt damit in versioniertem, testbarem PHP-Code statt in YAML; der Healthcheck
enthält kein Dollarzeichen mehr, die Escaping-Falle der Zwischenlösung entfällt also strukturell.
Ein hängender Prozess wird zusätzlich erkannt (über die Heartbeat-Datei), während eine reine
Prozessprüfung ihn fälschlich als gesund gemeldet hätte; der Mechanismus der Prozessprüfung selbst
(`/proc/1/cmdline`) ist derselbe wie in der auf dem Server getesteten Zwischenlösung, die
Zuverlässigkeit also mindestens gleich. Caddy hat weiterhin keinen Container-Healthcheck (das
Basisimage `caddy:2-alpine` bringt keinen mit); `deploy.sh` wartet nur auf Container mit
Healthcheck und prüft die Kette Coolify-Proxy, Caddy, php-fpm anschließend funktional über den
HTTPS-Aufruf von `health.php`. Ein zusätzlicher Caddy-Healthcheck wurde bewusst nicht eingeführt,
weil er ungeprüft in den deploy-blockierenden Pfad eingreifen würde.

### Sysctl-Ergänzung: vm.overcommit_memory

Unabhängig vom Healthcheck-Fehler setzt `setup-vps.sh` seit dieser Version in Schritt 5 von 10
idempotent `vm.overcommit_memory = 1` über `/etc/sysctl.d/99-smarteinzug.conf` (Redis benötigt
Memory-Overcommit für zuverlässige Hintergrund-Speicherabzüge, sonst die Warnung „Memory
overcommit must be enabled“); eine vorhandene Einstellung in dieser Datei wird nicht
überschrieben. Die Schrittnummerierung von `setup-vps.sh` lautet jetzt 1/10 bis 10/10 (neu: 5/10
Kernel-Einstellung für Redis; die früheren Schritte 5 bis 9 sind jetzt 6 bis 10). Auf dem Server
ist die Einstellung bereits persistent gesetzt (auf dem Server zu prüfen: `sysctl
vm.overcommit_memory`).

### Statusstufen

| Baustein | Stand |
|---|---|
| Erster, manueller Deploy auf dem Hostinger-VPS (`releases/manual-initial`, alle Dienste `healthy`, Coolify-MariaDB genutzt) | produktiv abgeschlossen (vom Betreiber bestätigt) |
| Datenbankimport der IONOS-Bestandsdaten, Migrationsstand „0 eingespielt, 0 offen“ | produktiv abgeschlossen (vom Betreiber bestätigt) |
| Eigener Healthcheck-Modus `--metrics`, `HEALTHCHECK NONE` im Dockerfile, je Dienst passender Healthcheck in `docker-compose.yml` | umgesetzt (im Repository) |
| Regressionstest `tools/compose-check.py`, eingebunden im GitHub-Workflow (Job „test“) | umgesetzt und mit vier Negativproben verifiziert (im Repository) |
| Test `scratchpad/test_healthcheck.php` (11 Prüfungen) | umgesetzt, alle Prüfungen bestanden |
| `vm.overcommit_memory = 1` über `setup-vps.sh` (neuer Schritt 5 von 10) | umgesetzt (im Repository); auf dem Server bereits persistent gesetzt (vom Betreiber bestätigt) |
| Erstes Deployment über den GitHub-Workflow (`workflow_dispatch`) | offen, siehe `docs/vps/08-hostinger-coolify.md`, Schritt 9 |


### Nebenbefund aus dem Testlauf: Datum aus der Datenbank statt aus der Anwendung

Der vollständige Testlauf zu Version 4.4 fiel in das Zeitfenster zwischen 00:00 und 02:00 Uhr deutscher Zeit und deckte dadurch einen bis dahin unentdeckten Fehler auf: Drei Abfragen verwendeten `CURDATE()`, also das Datum des Datenbankservers. Die Testdatenbank läuft in UTC, die Anwendung rechnet mit Europe/Berlin; in diesem Zeitfenster liegen beide Daten einen Tag auseinander.

Auswirkung in der Produktion: Ein digital über Stripe erteiltes SEPA-Mandat hätte zwischen 00:00 und 02:00 Uhr (Sommerzeit, im Winter zwischen 01:00 und 02:00 Uhr) als Unterschrifts- und Mandatsdatum den Vortag getragen. Das Mandatsdatum ist auf dem Mandat ausgewiesen und rechtlich erheblich. Ebenso wurden terminierte Einzüge in diesem Fenster gegen ein um einen Tag abweichendes Datum auf Überfälligkeit geprüft.

Behoben in `app/mandate_requests.php` (digitale Mandatserteilung), `app/mandates.php` (Anlage eines Mandats) und `app/alerts.php` (Überfälligkeit): Das Vergleichs- und Speicherdatum stammt jetzt aus der Anwendung, deren Zeitzone `app/bootstrap.php` aus der Konfiguration setzt. Die verbleibenden `CURDATE()`-Aufrufe in `admin.php` betreffen ausschließlich die Zeitachse zweier Diagramme über 13 Wochen und sind unkritisch. Der zuvor fehlschlagende Test (`test_payment_safety.php`, Prüfung "Mandat digital erteilt") besteht seitdem; die Suite liefert wieder 140 von 140.

Auf dem VPS ist dieser Punkt besonders relevant, weil die Zeitzone der Coolify-MariaDB nicht von der Anwendung gesetzt wird (auf dem Server zu prüfen: `SELECT @@global.time_zone, @@session.time_zone;`). Mit der Korrektur ist das Verhalten unabhängig von der Einstellung der Datenbank.

## Nachtrag Cutover: fehlgeschlagener Migrationsaufruf

Stand: 06.09.2026. Während des laufenden Umzugs von IONOS auf den Hostinger-VPS ist ein Lauf des
Workflows „Deployment IONOS-Webhosting und VPS“ im Job `deploy-webhosting` fehlgeschlagen.

### Fehlerbild

Meldung: „curl: (60) SSL certificate problem: self-signed certificate“ und „Verbindungsfehler oder
Timeout beim Migrationsaufruf“, danach die Warnung „Dateien sind bereits hochgeladen. Es erfolgt
kein automatischer Rollback.“

### Ursache

Der Migrationsaufruf war im Workflow zu diesem Zeitpunkt fest auf
`https://app.smart-einzug.de/migrate.php` verdrahtet (diese Adresse ist als Anweisung überholt,
siehe Korrektur unten). Im laufenden Umzug zeigt dieser Name inzwischen auf den Hostinger-VPS. Dort
beantwortet der Coolify-Proxy (Traefik) die Anfrage mit seinem Standardzertifikat, das nicht
öffentlich vertrauenswürdig ist, weil für diesen Namen noch kein Let's-Encrypt-Zertifikat vorlag.
curl bricht deshalb bei der Zertifikatsprüfung ab, also beim TLS-Handshake und damit bevor die
HTTP-Anfrage überhaupt übertragen wird. Es wurde dadurch keine Migration gestartet, weder auf dem
Webhosting noch auf dem VPS: Die Zertifikatsprüfung hat verhindert, dass der Migrationsaufruf den
falschen Server und die falsche Datenbank erreicht.

### Korrektur

1. Die Adresse ist nicht mehr fest verdrahtet, sondern kommt aus der neuen GitHub-Repository-
   Variablen `WEBHOSTING_MIGRATE_URL` (kein Secret, enthält kein Geheimnis, der Token bleibt im
   Header), zum Beispiel `https://<technisch eindeutige Adresse des Webhostings>/migrate.php`.
   Bewusst kein Vorgabewert, damit nie wieder ein Name verwendet wird, der im Umzug den Besitzer
   wechselt.
2. Neues Prüfskript `tools/check-migrate-url.sh`. Es weist eine leere Adresse ab, `http` statt
   `https`, einen Pfad, der nicht auf `/migrate.php` endet, Parameter oder Anker in der Adresse,
   Zugangsdaten in der Adresse sowie die fünf Namen, die zum VPS gehören (`app.smart-einzug.de`,
   `admin.smart-einzug.de`, `api.smart-einzug.de`, `status.smart-einzug.de`,
   `staging.smart-einzug.de`). Bei anderen Namen unterhalb von smart-einzug.de erzeugt es eine
   Warnung, keinen Abbruch.
3. Die Prüfung läuft jetzt zweimal: einmal im Schritt „Voraussetzungen vor dem Upload prüfen“, also
   vor dem SFTP-Upload, und einmal unmittelbar vor dem Aufruf. Dadurch werden keine Dateien mehr
   hochgeladen, wenn die Migrationsadresse fehlt oder unbrauchbar ist, genau der Fall, der hier
   eingetreten war: Die Dateien lagen bereits auf dem Server, als der Aufruf scheiterte.
4. Die Fehlermeldung im Workflow nennt jetzt die verwendete Adresse, erklärt die Umzugsursache und
   weist ausdrücklich darauf hin, dass keine Migration gestartet wurde und die Zertifikatsprüfung
   nicht abgeschaltet wird.
5. Nicht geändert und ausdrücklich so gewollt: Die Zertifikatsprüfung bleibt aktiv, es wird kein
   `curl -k`/`--insecure` verwendet.

Der Job `deploy-vps` hängt über `needs` nur von `changes` und `test` ab, nicht von
`deploy-webhosting`; ein Fehler des Webhosting-Jobs blockiert also keinen gültigen VPS-Deploy. Beide
Jobs laufen in getrennten Nebenläufigkeitsgruppen (`production-sftp` und `production-vps`), und
`deploy-vps` läuft weiterhin nur, wenn `VPS_DEPLOY_ENABLED` auf `true` steht.

### Auswirkung auf den Datenbankstand

Es wurde keine Migration gestartet. Seit Version 4.1 (Migration 019) wurde keine weitere
Migrationsdatei ergänzt; die Versionen 4.2, 4.3 und 4.4 enthalten keine Datenbankänderung. Der
fehlgeschlagene Aufruf hätte also voraussichtlich nichts einzuspielen gehabt (zu prüfen über
`setup-check.php` mit dem `cron_token`, Zeile „Migrationen (Stand)“).

Empfohlene Reihenfolge beim weiteren Umzug: Solange die Anwendung noch auf dem Webhosting läuft,
`WEBHOSTING_MIGRATE_URL` vor der DNS-Umstellung auf eine technisch eindeutige, vom Umzug nicht
betroffene Adresse des Webhostings setzen. Sobald die Anwendung vollständig auf dem VPS läuft,
`WEBHOSTING_APP_DEPLOY` auf `false` setzen; dann entfallen App-Ordner und Migrationsaufruf
vollständig, das ist der Zielzustand. Auf dem VPS laufen Migrationen ausschließlich über
`deploy.sh` mit `php bin/migrate.php` im php-Container, nie über HTTP (siehe
`docs/vps/07-cutover-checkliste.md`, `docs/migrations.md`).

### Statusstufen

| Baustein | Stand |
|---|---|
| Ursache geklärt (Zertifikatsprüfung des VPS-Proxys, kein fest verdrahteter Name mehr geeignet) | geklärt |
| GitHub-Variable `WEBHOSTING_MIGRATE_URL`, Prüfskript `tools/check-migrate-url.sh`, doppelte Prüfung im Workflow | umgesetzt (im Repository) |
| Auswirkung auf den Datenbankstand (keine Migration gestartet, keine Migrationsdatei seit Version 4.1) | geprüft, unauffällig |
| Wert von `WEBHOSTING_MIGRATE_URL` produktiv gesetzt | zu prüfen (auf GitHub-Repository-Ebene zu prüfen) |
| `WEBHOSTING_APP_DEPLOY` auf `false` nach Abschluss des Umzugs | offen, erst nach vollständigem Cutover |

## Nachtrag: ausfallsicheres VPS-Deployment (Version 4.5)

Stand: 07.09.2026. Während des laufenden Betriebs des GitHub-Workflows auf dem Hostinger-VPS ist
ein Deployment einmal an einem kurzen SSH-Verbindungsabbruch gescheitert. Dieser Nachtrag beschreibt
Fehlerbild, Ursache und die drei zusammenhängenden Lösungsbausteine, die diese Fehlerklasse
strukturell ausschließen.

### Fehlerbild

Der GitHub-Workflow rief den mehrminütigen `deploy.sh` bisher über einen einzigen, während der
gesamten Deploymentdauer (Image-Build, `docker compose up`, Migration) offenen SSH-Kanal auf.
Dieser Kanal brach einmal kurz ab, Meldung „client_loop: send disconnect: Broken pipe“. Der
entfernte `deploy.sh`-Prozess hing am selben Kanal und starb dadurch mitten im Container-Neustart;
mehrere Container blieben im Zustand `created` (nie gestartet) hängen, während andere weiterliefen.

### Ursache

Nicht die bestehende Reihenfolge im Deployment war die Ursache (der Symlink `current` war zu diesem
Zeitpunkt noch nicht umgestellt und die Migration noch nicht erreicht, das entsprach der damals
bereits vorgesehenen, sicheren Abfolge). Die eigentliche Ursache lag darin, dass diese Logik gar
nicht zu Ende laufen konnte: Der SSH-Kanal selbst gab dem entfernten Prozess das Signal zum
Absturz, bevor die bestehende Schutzlogik (Warten auf gesunde Container, automatisches Rollback bei
einem Fehler) überhaupt greifen konnte.

### Lösungsbaustein A: serverseitige Entkopplung von der SSH-Sitzung

Der GitHub-Workflow ruft nicht mehr direkt `deploy.sh` über einen langen SSH-Kanal auf, sondern
`deploy/vps/scripts/deploy-runner.sh`. Dieses Skript prüft nicht blockierend, ob bereits ein
Deployment oder Rollback läuft (dieselbe Sperrdatei wie `deploy.sh`/`rollback.sh`); läuft bereits
eines, wird nichts gestartet (kein doppeltes Deployment durch einen GitHub-Retry oder einen zweiten
Workflow-Lauf). Andernfalls startet es sich per `setsid` selbst in einer neuen, von der SSH-Sitzung
unabhängigen Sitzung neu und vererbt dabei den bereits gehaltenen Sperr-Dateideskriptor. Ein
Abbruch der SSH-Verbindung sendet danach kein SIGHUP mehr an den laufenden Deploy-Prozess. Der
Workflow fragt den Fortschritt anschließend über wiederholte, kurze, unabhängige SSH-Verbindungen
ab (`deploy-status.sh`); jede einzelne Abfrage darf abbrechen, ohne das laufende Deployment zu
gefährden. Ergänzend setzt der Workflow für alle SSH-/rsync-Aufrufe dieses Jobs SSH-Keepalive
(`ServerAliveInterval=30`, `ServerAliveCountMax=10`, `TCPKeepAlive=yes`); das verringert die
Wahrscheinlichkeit eines Abbruchs, ersetzt aber nicht die Entkopplung, da die Korrektheit nicht von
einer einzelnen durchgehenden SSH-Verbindung abhängen darf.

### Lösungsbaustein B: Release-Bindung über RELEASE_SHA statt mutable Symlink

`working_dir` aller PHP-Container und Caddys Dokumentenstamm waren bisher an den Symlink
`releases/current` gebunden, der erst nach dem Hochfahren der Container umgestellt wurde. Das barg
ein eigenständiges architektonisches Risiko: Ein frisch erzeugter Container mit neuer
Compose-Konfiguration (zum Beispiel einem neuen Healthcheck-Modus) konnte über den noch nicht
umgestellten Symlink auf älteren Anwendungscode treffen, der diesen Modus noch nicht unterstützte.
`working_dir` ist jetzt an die Umgebungsvariable `RELEASE_SHA` gebunden, ausschließlich als
Pflichtwert (`${RELEASE_SHA:?...}`, kein Vorgabewert), die `deploy.sh`/`rollback.sh` vor jedem
`docker compose`-Aufruf exportieren. Damit gehören Compose-Konfiguration, Image und Anwendungscode
bei jedem Containerstart garantiert zum selben Release. Der Symlink `releases/current` bleibt
bestehen, ist aber nur noch Buchführung für Menschen und Werkzeuge.

### Lösungsbaustein C: Candidate prüfen, dann migrieren, dann erst Cutover

Migrationen laufen jetzt nicht mehr gegen die bereits neu erzeugten Live-Container, sondern vorher,
in einem zusätzlichen, isolierten Container (`docker compose run --rm --no-deps`), der die
laufenden Container nicht berührt: Image bauen (falls nötig) → Candidate isoliert prüfen (mit dem
neuen Code, laufende Anwendung unberührt) → Migrationen isoliert mit dem neuen Code einspielen →
erst danach der eigentliche Cutover (`docker compose up -d`, jetzt sicher, weil das Schema bereits
migriert ist) → auf gesunde Container warten → Symlink umstellen → Health-Check → bei einem Fehler
ab dem Cutover automatisches Rollback. Schlagen Candidate-Prüfung oder Migration fehl, wurde an den
laufenden Containern nichts verändert, ein Rollback ist dann nicht nötig. Ein abgebrochener
vorheriger Lauf, der Container im Zustand `created` hinterlassen hat, wird von `docker compose up
-d` beim nächsten Versuch von selbst aufgelöst (idempotent).

### Neue Regressionstests

- `tools/compose-check.py`: geprüft wird zusätzlich, dass `RELEASE_SHA` ausschließlich als
  Pflichtwert referenziert wird, dass keine Compose-Datei einen Dienst `mariadb` oder `backup`
  definiert, und dass `/opt/smarteinzug/releases/current` nirgends mehr als tatsächlicher
  Laufzeitpfad (`working_dir`, `root`, Bind-Mount-Ziel) vorkommt.
- `tools/deploy-runner-check.sh`: simuliert `/opt/smarteinzug` in einem temporären Ordner (kein
  Docker, kein echter Server nötig) und prüft: erfolgreicher Lauf, fehlgeschlagener Lauf, ein
  paralleler zweiter Versuch wird abgelehnt, ein simulierter SSH-Abbruch (hartes Beenden des
  auslösenden Prozesses) stoppt den bereits entkoppelten Hintergrundlauf nicht, ein erneuter Lauf
  nach Abschluss ist wieder möglich (idempotent), es werden keine Geheimnisse protokolliert.

### Statusstufen

| Baustein | Stand |
|---|---|
| `deploy/vps/scripts/deploy-runner.sh` und `deploy-status.sh` (serverseitige Entkopplung, Sperre, Status-/Protokolldatei) | umgesetzt (im Repository) |
| `docker-compose.yml`/`Caddyfile`/`Caddyfile.staging`: `RELEASE_SHA` als Pflichtwert für `working_dir` und Dokumentenstamm | umgesetzt (im Repository) |
| `deploy.sh`: Candidate-Prüfung und Migration isoliert vor dem Cutover, `rollback.sh`: `RELEASE_SHA` und `SMARTEINZUG_LOCK_HELD` | umgesetzt (im Repository) |
| `.github/workflows/deploy.yml`: zwei Schritte (Auslösen, Warten), SSH-Keepalive, Timeout des Jobs 25 Minuten | umgesetzt (im Repository) |
| `tools/compose-check.py` (Prüfpunkte 7 bis 9) und `tools/deploy-runner-check.sh` | umgesetzt und offline (ohne Docker-Daemon beziehungsweise mit simulierter Umgebung) verifiziert |
| Vollständiger Lauf mit echtem Docker-Daemon und einem tatsächlichen SSH-Abbruch während eines echten Deployments auf dem produktiven oder einem Staging-VPS | **nicht getestet.** Ein echter Server mit laufendem Docker-Daemon stand für diesen Nachtrag nicht zur Verfügung; die Prüfung beschränkte sich auf die simulierte, isolierte Testumgebung von `tools/deploy-runner-check.sh`. Vor dem eigentlichen Cutover mindestens ein Testdeployment auf Staging oder mit einem unkritischen Push durchführen (siehe `docs/vps/07-cutover-checkliste.md`, Abschnitt „Deployment“) |

## Verbleibende Risiken

- Ein VPS ohne Hochverfügbarkeit: Ausfall bedeutet Nichtverfügbarkeit bis zur Wiederherstellung aus Backup (bewusst, Auftrag Abschnitt 97).
- Rollback stellt nur den Code zurück, nicht das Schema; Migrationen sind additiv, ein schemabrechender Schritt bräuchte einen manuellen Plan.
- Der Wert 2 Aufrufe je Sekunde für Lexware ist eine Annahme; die Lexware-Dokumentation war nicht erreichbar.
- E-Mail-Jobs können bei Absturz zwischen Übergabe und Statusspeicherung doppelt zustellen (höchstens drei Versuche).
- Die Queue ist auf dem Webhosting bis zur Aktivierung des Flags ohne Wirkung; die Hybridverarbeitung im Cron ist mit einer Testfirma zu erproben, bevor der VPS übernimmt.
