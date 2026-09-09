# Repository, Ordner und Dateien

Stand 07.09.2026. Repository `v3ni94/LexofficXSTRIPE` (GitHub), Entwicklungsbranch laut Auftrag. Zuordnung Repository-Pfad, Build-Ergebnis, Zielserver, Zielverzeichnis, erreichbare Anwendung.

@@diagramm 04-repository-deployment

## Ordner und Zweck

| Pfad | Zweck | Wichtige Dateien | Deployment-Ziel |
|---|---|---|---|
| `php-ionos/` | Anwendung (Seiten, Webhooks, CLI) | `*.php` Seiten; `app/` Bibliotheken; `bin/` CLI; `sql/schema.sql`, `sql/migrations/`; `assets/` | VPS `releases/<sha>/` (rsync); optional Webhosting (Job `deploy-webhosting`, `WEBHOSTING_APP_DEPLOY`) |
| `php-ionos/app/` | Fachlogik: `auth.php` (Konten, 2FA, Rollen), `collections.php` (Einzüge), `mandates.php`, `mandate_requests.php`, `sync.php`, `sync_state.php`, `lexoffice.php`, `stripe.php`, `billing.php`, `plans.php`, `pricing.php`, `queue.php`, `jobs.php`, `worker_signals.php`, `monitor.php`, `alerts.php`, `mailer.php`, `audit.php`, `crypto.php`, `interest.php`, `integration_state.php`, `invoice_source.php`, `invoice_source_switch.php`, `sevdesk.php`, `legal.php`, `legal_drafts.php`, `layout.php`, `bootstrap.php` | siehe Kapitel Geschäftslogik, Sicherheit | Teil des Release |
| `php-ionos/bin/` | CLI: `scheduler.php`, `worker.php`, `migrate.php`, `healthcheck.php`, `host-metrics.php`, `mail-check.php`, `billing-check.php`, `billing-setup-stripe.php`, `backup-record.php` | Container-Kommandos in `docker-compose.yml` | Teil des Release |
| `php-ionos/app/docs-build/` | erzeugte Dokumentation (gitignored) | `manifest.json`, `<code>.pdf`, `<code>/index.html` | vom Workflow erzeugt, mit dem Release ausgeliefert, Archiv unter `shared/docs-archive/` |
| `deploy/vps/` | Docker-Stack und Serverskripte | `docker-compose.yml`, `docker-compose.prod.yml`, `docker-compose.staging.yml`, `Caddyfile`, `Caddyfile.staging`, `php/Dockerfile`, `php/php.ini`, `redis/redis.conf`, `.env.example`, `scripts/deploy-runner.sh`, `deploy.sh`, `rollback.sh`, `restart-workers.sh`, `maintenance.sh`, `db-import.sh`, `setup-vps.sh`, `README.md` | VPS `/opt/smarteinzug/deploy/` (rsync, Laufzeitdateien ausgeschlossen) |
| `websites/` | statische Websites je Domain: `smart-einzug.de`, `lexoffice-einzug.de`, `lexware-einzug.de`, `lastschrift-einfach.de`, `status.smart-einzug.de`, `aliases` | `index.html`, `.htaccess`, `sitemap.xml`, `assets/` | IONOS per SFTP; `status.smart-einzug.de` zusätzlich in das Release (`status/`) |
| `tools/` | Prüf- und Erzeugungswerkzeuge | `build-docs.py`, `render-mermaid.py`, `gen-datenwoerterbuch.py`, `docs-build-check.py`, `site-qa.py`, `compose-check.py`, `*-check.sh`, `lib/` (MariaDB-Sandbox, Simulationen) | nur Entwicklung und Workflow |
| `docs/` | Dokumentationsquellen (Markdown), Diagramme, Anlagen, CI-Assets, Regeln | siehe Kapitel Dokumentationssystem | Build-Eingabe |
| `.github/workflows/` | `deploy.yml` (Jobs `changes`, `test`, `deploy-webhosting`, `deploy-vps`), `.github/scripts/vps-*.sh` | | GitHub Actions |
| `CLAUDE.md`, `docs/ARBEITSSTAND.md` | Projektregeln und Arbeitsstand für Entwicklungsassistenten und Übernehmer | | nur Repository |

## Drittanbieterbestandteile (Laufzeit)

| Bestandteil | Version | Zweck | Lizenz |
|---|---|---|---|
| PHP | 8.4 (Image `php:8.4-fpm-alpine`) | Laufzeit | PHP License 3.01 |
| MariaDB (Coolify-Ressource) | Version auf dem Server prüfen (**offen**: `SELECT VERSION()`) | Datenbank | GPLv2 |
| Redis | 7 (Image `redis:7-alpine`) | Warteschlange, Sperren | RSALv2/SSPLv1 ab 7.4, BSD-3 bis 7.2 (**offen**: genaue Image-Version) |
| Caddy | 2 (Image `caddy:2-alpine`) | interner Webserver | Apache 2.0 |
| Coolify, Traefik | vom Server (**offen**) | Proxy, Verwaltung, Backups | Apache 2.0 (Coolify), MIT (Traefik) |
| reportlab (Build) | im Workflow per pip | PDF-Erzeugung | BSD |
| mermaid (Build) | 11 (npm, nur zum Rendern) | Diagramme | MIT |
| Playwright/Chromium (Build) | lokal | Rendern der Diagramme | Apache 2.0 / BSD |

Die Anwendung nutzt keine PHP-Pakete über Composer; eigene Bibliotheken liegen unter `php-ionos/app/` (u. a. TOTP, Verschlüsselung, HTTP-Clients ohne SDK).

## Zuordnung Quelle zu Anwendung

`php-ionos/dashboard.php` wird als `releases/<sha>/dashboard.php` ausgeliefert und über Caddy (`{$DOMAIN_APP}`) unter `https://app.smart-einzug.de/dashboard.php` erreichbar; `admin*.php` nur über `{$DOMAIN_ADMIN}` (zusätzlich `enforce_host_rules()` in `app/bootstrap.php`); `stripe-webhook.php`, `billing-webhook.php`, `health.php`, `track.php` über `{$DOMAIN_API}`; `websites/status.smart-einzug.de/` als `releases/<sha>/status/` über `{$DOMAIN_STATUS}`, dessen `status.json` aus `shared/status/`.

Quellcodeverweise dieser Dokumentation beziehen sich auf den im Manifest genannten Commit (Deckblatt der PDF).
