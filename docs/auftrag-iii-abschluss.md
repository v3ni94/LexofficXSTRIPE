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

1. IONOS VPS bestellen (Ubuntu 24.04) und mit docs/vps/02-einrichtung-vps.md einrichten (setup-vps.sh, .env aus .env.example, shared/config.php).
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

Im ersten Lauf nach den Korrekturen schlugen zwei der neuen Prüfungen fehl: die Anmeldeseite wurde mit angemeldeter Sitzung geprüft (Weiterleitung zum gesperrten Dashboard, Testfehler) und mail_addr_ref gab die Domain nicht kleingeschrieben zurück (Funktion angepasst). Zweiter Lauf vollständig grün.

Zusätzlich: docker compose config für prod und staging, bash -n für alle Skripte, php -l für alle geänderten PHP-Dateien, YAML-Prüfung des Workflows, Bau der Dokumentation (tools/build-docs.py, PDF mit Version und Commit).

## Nicht geprüft

Start der Container und echte Health Checks (kein Docker-Daemon in der Entwicklungsumgebung), Let's Encrypt, SSH-Deployment auf einen echten VPS, Datenbankimport mit Produktionsdaten, Verhalten mehrerer Worker unter Last, echte Lexware- und Stripe-Störungen, Backup auf ein externes Ziel, rclone-Installation im Backup-Image.

## Verbleibende Risiken

- Ein VPS ohne Hochverfügbarkeit: Ausfall bedeutet Nichtverfügbarkeit bis zur Wiederherstellung aus Backup (bewusst, Auftrag Abschnitt 97).
- Rollback stellt nur den Code zurück, nicht das Schema; Migrationen sind additiv, ein schemabrechender Schritt bräuchte einen manuellen Plan.
- Der Wert 2 Aufrufe je Sekunde für Lexware ist eine Annahme; die Lexware-Dokumentation war nicht erreichbar.
- E-Mail-Jobs können bei Absturz zwischen Übergabe und Statusspeicherung doppelt zustellen (höchstens drei Versuche).
- Die Queue ist auf dem Webhosting bis zur Aktivierung des Flags ohne Wirkung; die Hybridverarbeitung im Cron ist mit einer Testfirma zu erproben, bevor der VPS übernimmt.
