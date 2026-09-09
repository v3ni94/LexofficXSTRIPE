# Server, Hosting, Domains und Netzwerk

Stand 07.09.2026. Kennzeichnung je Angabe: **nachgewiesen** (Betreiberausgaben oder Deployments vom 07.09.2026), **konfiguriert** (Repository), **offen** (Prüffrage mit Verfahren). Keine Zugangsdaten.

## Datenblatt Hostinger-VPS (Produktion)

| Merkmal | Wert | Nachweis |
|---|---|---|
| Anbieter, Tarif | Hostinger, KVM 8 | Projektangabe; Tarif im Hostinger-Kundenbereich prüfen (**offen**) |
| Aufgabe | Kundenanwendung, Adminbereich, Webhook-Host, Statusseite, Scheduler, Worker, Redis, Coolify mit privater MariaDB | konfiguriert (`deploy/vps/`), Deployments nachgewiesen |
| Hostname | srv1960492 (Terminalausgabe), Hostinger-Name srv1960492.hstgr.cloud (Projektangabe) | nachgewiesen (Prompt der Shell), Cloud-Name **offen** |
| Öffentliche Adressen | IPv4 72.61.80.67, IPv6 2a02:4780:41:765c::1 | nachgewiesen (Anmeldebanner 07.09.2026) |
| Betriebssystem | Ubuntu 24.04.4 LTS, Kernel 6.8.0-139-generic | nachgewiesen (Anmeldebanner) |
| Speicher | Wurzeldateisystem 386,42 GB, 1,6 % belegt; Arbeitsspeicher 3 % belegt | nachgewiesen (Anmeldebanner); absolute RAM-Größe und CPU-Zahl **offen** (`nproc`, `free -h`) |
| Verwaltung | Coolify (Proxy Traefik, MariaDB-Ressource, Backups) | konfiguriert und Deployments nachgewiesen |
| Docker-Stack | Projekt `smarteinzug`: caddy (`caddy:2-alpine`), php (`smarteinzug-php:local` aus `php:8.4-fpm-alpine`), scheduler, worker-lexware-1, worker-lexware-2, worker-stripe, worker-mail, worker-maintenance, metrics (alle PHP-Image), redis (`redis:7-alpine`) | konfiguriert (`docker-compose.yml`), laufende Container nachgewiesen (`restart-workers.sh` 07.09.2026) |
| Ressourcen je Container (Produktion) | caddy 256 MB / 0,50 CPU; php 1536 MB / 2,00; scheduler 192 MB / 0,25; worker-lexware-1 und -2 je 768 MB / 0,75; worker-stripe 512 MB / 0,50; worker-mail 256 MB / 0,25; worker-maintenance 384 MB / 0,25; metrics 128 MB / 0,25; redis 512 MB / 0,25 | konfiguriert (`docker-compose.prod.yml`) |
| PHP | 8.4 (Basisimage), Erweiterungen pdo_mysql, gd, intl, opcache, zip, bcmath, pcntl; `opcache.validate_timestamps=0`, `opcache.enable_cli=0` | konfiguriert (`deploy/vps/php/Dockerfile`, `php.ini`) |
| Webserver | Caddy intern (kein veröffentlichter Port), TLS am Coolify-Proxy | konfiguriert (`Caddyfile`, `docker-compose.prod.yml` Traefik-Labels, certresolver letsencrypt) |
| Domains | `DOMAIN_APP=app.smart-einzug.de`, `DOMAIN_ADMIN=admin.smart-einzug.de`, `DOMAIN_API=api.smart-einzug.de`, `DOMAIN_STATUS=status.smart-einzug.de`; Staging `DOMAIN_STAGING` | konfiguriert (`.env.example`); tatsächliche `.env` und DNS **offen** (`dig +short`) |
| Ports und Firewall | ufw: 22, 80, 443 offen, 8000 (Coolify) gesperrt; fail2ban aktiv, Jail sshd | nachgewiesen (`ufw status`, `fail2ban-client status` 07.09.2026); Hostinger-Firewall im hPanel **offen** |
| Netze | `smarteinzug_internal` (Redis-Alias `smarteinzug-redis`), Coolify-Netz `coolify` für Proxy und MariaDB | konfiguriert |
| Verzeichnisse | `/opt/smarteinzug/releases/<sha>` (Code, Marker `.release-complete`), `releases/current` (Symlink), `/opt/smarteinzug/shared/config.php`, `shared/storage` (Mandate, Avatare, Logs), `shared/sessions`, `shared/status/status.json`, `/opt/smarteinzug/deploy` (Compose, Skripte, `.env`, `.deploy-status.json`, `.deploy.lock`), `/opt/smarteinzug/logs/deploy-runner-*.log` | konfiguriert, Pfade nachgewiesen |
| Volumes | Bind-Mounts der genannten Pfade; `shared/config.php` als Einzeldatei (Inode-Verhalten, siehe Betrieb) | konfiguriert |
| Systembenutzer | Betrieb als root (Anmeldebanner), Deploy-Benutzer laut Einrichtung `deploy` mit sudo; Container mit `APP_UID`/`APP_GID` | nachgewiesen (root), Einrichtung **konfiguriert** |
| Zeitzone | Container `TZ=Europe/Berlin`; Host UTC (Anmeldebanner in UTC) | nachgewiesen |
| Start, Stopp, Healthcheck | `deploy.sh`, `rollback.sh`, `restart-workers.sh`, `maintenance.sh`, `deploy-status.sh`; Healthchecks je Container (`bin/healthcheck.php --db`, `--heartbeat`, `--metrics`) | konfiguriert, Läufe nachgewiesen |
| Backups | Coolify-Backup der MariaDB nach Hetzner Object Storage (Doku), lokale Kopien unter `/data/coolify/backups`, `backup-status.json` für das Monitoring | konfiguriert laut `docs/betrieb-migration-vps.md`; Wiederherstellungstest **offen** (kein Protokoll im Repository) |

## Datenblatt IONOS-Webhosting

| Merkmal | Wert | Nachweis |
|---|---|---|
| Aufgabe | statische Websites (`websites/*`), DNS-Zonen, E-Mail-Postfächer (SMTP `smtp.ionos.de:587`, Absender `kontakt@smart-einzug.de`) | Websites konfiguriert (`deploy-webhosting`), SMTP nachgewiesen (`mail-check.php` 07.09.2026) |
| Zugang | SFTP (Secrets `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_PATH`), kein Root, keine Container | konfiguriert (`deploy.yml`) |
| Anwendung auf dem Webhosting | historisch (`sepa.muellerhv.de`, `cron.php` alle fünf Minuten laut Doku); Variable `WEBHOSTING_APP_DEPLOY` steuert den Job | **offen**: ob die Instanz noch läuft und der Cronjob entfernt ist |
| Grenzen | keine Root-Rechte, keine eigenen Dienste, kein Redis; Funktionen außerhalb des Hosting-Tarifs sind nicht verfügbar | Aussage aus der Hostingart, Tarif **offen** |

## SFTP-Aufbau des Webhosting-Deployments

Der Job `deploy-webhosting` in `.github/workflows/deploy.yml` überträgt die statischen Websites per SFTP mit `lftp` auf das IONOS-Webhosting. Aufbau:

| Merkmal | Wert | Quelle |
|---|---|---|
| Verbindung | `sftp://$SFTP_HOST`, Port `$SFTP_PORT`, Benutzer `$SFTP_USER`, Passwort über Umgebungsvariable (`open --env-password`), nie in Protokollen | `deploy.yml`, Secrets `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_PATH` |
| Hostkey-Prüfung | `ssh -o StrictHostKeyChecking=yes` mit vorab verifizierter `known_hosts`-Datei, `sftp:auto-confirm no`; ein geänderter Hostkey bricht den Job ab | `deploy.yml` |
| Wurzelpfad | `$SFTP_PATH` (Webspace-Wurzel); darunter ein Ordner je Domain | `deploy.yml` |
| Zuordnung | `websites/smart-einzug.de` → `smart-einzug.de`; `websites/lexware-einzug.de` → `lexware-einzug.de`; `websites/lexoffice-einzug.de` → `lexoffice-einzug.de`; `websites/lastschrift-einfach.de` → `lastschrift-einfach.de`; `websites/aliases/smarteinzug.de` → `alias-smarteinzug`; `websites/aliases/smart-lastschrift.de` → `alias-smart-lastschrift`; `websites/aliases/einzug-direkt.de` → `alias-einzug-direkt`; `websites/status.smart-einzug.de` → `status.smart-einzug.de` | `deploy.yml` |
| Verfahren | `mirror --reverse --no-perms --parallel=2` je Ordner (Spiegelung Repository → Server) | `deploy.yml` |
| Ausschlüsse | `.git*`, `node_modules`, `storage`, `logs`, `cache`, `backups`, `config.php`, `mail.log`, `.env*`, `*.sql`, `*.sqlite`, `*.db`, `*.log`, `*.pem`, `*.key`, `*.bak` | `deploy.yml` |
| Anwendung auf dem Webhosting | nur wenn Variable `WEBHOSTING_APP_DEPLOY` nicht `false`: `php-ionos/` nach `$root/app` (ohne `config.php`, `storage`), Migrationsdateien nach `$root/app/sql/migrations`, anschließend Migrationsaufruf über `WEBHOSTING_MIGRATE_URL` mit `MIGRATION_TOKEN` | `deploy.yml`, `tools/check-migrate-url.sh` |
| Zuordnung der Domains zu Ordnern | im IONOS-Kundenbereich (Domain auf Zielordner zeigen lassen), nicht im Repository | **offen**: Nachweis aus dem Kundenbereich |

## Domains und DNS

| Domain | Ziel | Nachweis |
|---|---|---|
| smart-einzug.de | IONOS-Webhosting, statisch | Job `deploy-webhosting` |
| app., admin., api., status.smart-einzug.de | VPS (Traefik) | konfiguriert; DNS **offen** |
| lexoffice-einzug.de, lexware-einzug.de, lastschrift-einfach.de | IONOS-Webhosting, statisch | Job `deploy-webhosting` |
| status.muellerhv.de | nicht vorgesehen (kein Eintrag im Repository) | Prüfung 07.09.2026 |

TLS: Let's Encrypt über den Coolify-Proxy (Traefik, certresolver `letsencrypt`), Erneuerung automatisch durch Traefik. Für das Webhosting stellt IONOS die Zertifikate (**offen**, im Kundenbereich prüfen).

## Staging

Eigener Server mit eigener Coolify-MariaDB, `environment => 'staging'`, Compose-Projekt `smarteinzug-staging` (`docker-compose.staging.yml`, `Caddyfile.staging`). Ob ein Staging-Server derzeit betrieben wird, ist im Repository nicht belegt (**offen**).
