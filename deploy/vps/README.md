# SmartEinzug: Docker-Stack fuer den IONOS VPS

Betreiber: Müller Holding AG. Dieser Ordner enthaelt den vollstaendigen Docker-Stack und die
Serverskripte fuer den Betrieb von SmartEinzug auf einem eigenen VPS (Ubuntu 24.04, Docker aus dem
offiziellen Repository, kein Plesk). Das bisherige IONOS-Webhosting mit Apache/.htaccess und Cron
bleibt daneben bestehen (siehe php-ionos/.htaccess, .github/workflows/deploy.yml) und wird durch
diesen Stack nicht ersetzt, solange die Migration nicht abgeschlossen ist.

## Dateien

| Datei/Ordner | Zweck |
|---|---|
| `docker-compose.yml` | Basisdienste (Caddy, php, scheduler, vier Worker-Pools, metrics, mariadb, redis, backup) |
| `docker-compose.prod.yml` | Override Produktion: Ressourcenlimits, zweiter Lexware-Worker (`worker-lexware-2`) |
| `docker-compose.staging.yml` | Override Staging: kleinere Ressourcenlimits, eigene Caddyfile, nur ein Lexware-Worker |
| `Caddyfile` / `Caddyfile.staging` | Reverse Proxy, ersetzt `php-ionos/.htaccess` vollstaendig |
| `php/Dockerfile`, `php/php.ini`, `php/www.conf` | gemeinsames PHP-Image fuer Web, Scheduler, alle Worker |
| `mariadb/my.cnf` | Zusatzeinstellungen fuer den MariaDB-Container |
| `redis/redis.conf` | Redis-Konfiguration (kein persistenter Datenbestand) |
| `.env.example` | Vorlage fuer `.env` (Domains, Passwoerter-Platzhalter, UID/GID, Worker-Speicher, Backup-Ziel) |
| `scripts/setup-vps.sh` | Einmalige Grundeinrichtung eines frischen VPS |
| `scripts/deploy.sh` / `scripts/rollback.sh` | Aktivieren bzw. Zuruecknehmen eines Release |
| `scripts/db-import.sh` | Dump mit Pruefsumme in den mariadb-Container einspielen |
| `scripts/db-verify.php` | Tabellen, Zeilenzahlen, CHECKSUM TABLE als JSON (Alt/Neu-Abgleich) |
| `scripts/maintenance.sh` | Wartungsmodus (`app/storage/maintenance.flag`) ein-/ausschalten |
| `backup/Dockerfile`, `backup/backup.sh`, `backup/restore-test.sh` | taeglicher Datenbank-Dump und Wiederherstellungstest |

## Start

```bash
cd /opt/smarteinzug/deploy      # oder dieser Ordner beim ersten manuellen Einrichten
cp .env.example .env
# .env mit echten Werten fuellen (Passwoerter, Domains, PROXY_NETWORK, APP_UID/APP_GID, ...)

docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env up -d
```

Staging entsprechend mit `docker-compose.staging.yml` und einer eigenen `.env` (eigener Server,
eigene Datenbank, nur `DOMAIN_STAGING` gesetzt). Voraussetzung fuer beide Faelle: `/opt/smarteinzug`
ist bereits eingerichtet (`scripts/setup-vps.sh`), `/opt/smarteinzug/shared/config.php` enthaelt eine
vollstaendige Konfiguration (Vorlage `php-ionos/app/config.example.php`) und `/opt/smarteinzug/releases/current`
zeigt bereits auf ein Release (legt `scripts/deploy.sh` bei der Erstinstallation selbst an).

Regulaere Deployments laufen ueber `scripts/deploy.sh <git-sha>`, ausgeloest durch den
GitHub-Workflow (VPS-Job per SSH). Der Aufruf von `docker compose ... up -d` von Hand ist nur fuer
die Ersteinrichtung und fuer gezielte Eingriffe gedacht.

## Betrieb

Logs eines Dienstes ansehen (alle Dienste: json-file mit Rotation 20 MB / 5 Dateien):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f worker-lexware-1
```

Einen einzelnen Worker neu starten (SIGTERM, laufender Job wird zu Ende gebracht):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart worker-stripe
```

Zusaetzliche Worker eines Pools kurzfristig hochskalieren (z.B. waehrend eines grossen
Nachhol-Abgleichs; `worker-mail`, `worker-stripe`, `worker-maintenance` sind namentlich einzelne
Dienste, "--scale" erzeugt zusaetzliche, gleichlautende Instanzen desselben Dienstes):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker-mail=2
```

Stand der Warteschlange und der Worker pruefen:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all
```

Wartungsmodus fuer den Cutover: `scripts/maintenance.sh on` / `off` (wirkt sofort auf alle
Container, da `app/storage` gemeinsam eingebunden ist).

## Hostinger KVM 8 mit Coolify: Rolle des Proxys

Der produktive VPS ist ein Hostinger KVM 8 (8 vCPU, 32 GB RAM, 400 GB NVMe) mit der Vorlage
"Ubuntu 24.04 with Coolify". Coolify bringt einen eigenen Reverse Proxy (Traefik) mit, der die Ports
80/443 haelt und Let's-Encrypt-Zertifikate bezieht. Deshalb veroeffentlicht unser Caddy KEINE Ports
mehr: Er haengt zusaetzlich am Docker-Netz des Coolify-Proxys (`PROXY_NETWORK`, Standard `coolify`)
und wird von Traefik ueber die Labels am `caddy`-Dienst angesprochen (Hostnamen, HTTPS-Umleitung,
Zertifikat). Caddy bleibt der interne HTTP-Server vor php-fpm mit den Sicherheitsregeln aus der
`.htaccess`. Coolify selbst verwaltet unsere Anwendung NICHT (kein Autodeploy, keine Coolify-Ressource);
der einzige Deploymentweg bleibt der GitHub-Workflow ueber SSH (`scripts/deploy.sh`). Einrichtung
Schritt fuer Schritt: `docs/vps/08-hostinger-coolify.md`.

Folge fuer die Anwendung: TLS endet am Coolify-Proxy, PHP sieht die Anfrage als HTTP von der
Proxy-Adresse. `trusted_proxies` in `shared/config.php` muss deshalb die Docker-Netzbereiche enthalten
(z.B. `['172.16.0.0/12', '10.0.0.0/8']`, mit `docker network inspect` pruefen); `app/bootstrap.php`
wertet X-Forwarded-Proto und X-Forwarded-For dann von rechts aus.

## Warum Caddy statt nginx (als interner HTTP-Server)

- Ohne Coolify wuerde Caddy zusaetzlich Let's-Encrypt-Zertifikate ohne certbot beziehen und erneuern;
  mit Coolify-Proxy uebernimmt das Traefik (`auto_https off` in den Caddyfiles).
- Die Caddyfile-Syntax bildet die vorhandenen `.htaccess`-Regeln (verbotene Pfade/Endungen,
  Cache-Header, Sicherheits-Header) knapp und lesbar ab, ohne die in nginx uebliche
  Doppelpflege von `location`-Bloecken fuer denselben Sachverhalt.
- `php_fastcgi` ist ein eingebauter, gut getesteter Shortcut fuer die FastCGI-Anbindung an
  php-fpm; die in nginx haeufigen, fehleranfaelligen `fastcgi_split_path_info`-Regeln entfallen.
- Ein VPS mit uebersichtlicher Anzahl Hosts profitiert staerker von Caddys geringerem
  Konfigurationsaufwand als von nginx' groesserem Oekosystem an Spezialmodulen, die hier nicht
  gebraucht werden.

## Architekturentscheidungen, die dieser Ordner voraussetzt

- Code liegt pro Release unter `/opt/smarteinzug/releases/<git-sha>/` (Inhalt von `php-ionos/`,
  inklusive dieses `deploy/vps`-Ordners); `/opt/smarteinzug/releases/current` ist ein Symlink auf
  das aktive Release. Container binden `/opt/smarteinzug/releases` nur LESEND ein (der Symlink zeigt
  in denselben Mount und wird im Container aufgeloest); `deploy/`, `backups/` und `logs/` sind fuer
  keinen Anwendungscontainer sichtbar.
- `app/config.php` und `app/storage` liegen ausserhalb jedes Release unter
  `/opt/smarteinzug/shared/` und werden separat eingebunden (config.php read-only, storage
  beschreibbar).
- `worker-lexware-2` ist in der Basis-Datei definiert und wird in `docker-compose.staging.yml` ueber
  ein nie aktiviertes Profil abgeschaltet; Produktion setzt nur Ressourcenlimits. Die Anzahl der
  Lexware-Worker unterscheidet sich damit allein durch die verwendete Override-Datei.
- Scheduler und Worker haben `stop_grace_period: 660s`; `deploy.sh` und `rollback.sh` starten sie mit
  `restart -t 660`, damit ein laufender Sync-Abschnitt (bis 600 s) sauber beendet wird.
- Die statische Statusseite (`websites/status.smart-einzug.de`) wird vom GitHub-Workflow je Release
  unter `releases/<git-sha>/status/` abgelegt; Caddy liefert `releases/current/status` aus. Sie
  gehoert damit zum Release und wechselt mit ihm (auch beim Rollback).

Der Backup-Container schreibt sein Ergebnis als `backup-status.json` in den gemeinsamen Speicher
`/opt/smarteinzug/shared/storage`; der Monitoring-Sammler der Anwendung liest die Datei (Komponente
Sicherungen). Es wird kein Docker-Socket eingebunden.

## Grenzen (bewusst, ein VPS)

- Keine Hochverfuegbarkeit: ein Ausfall des VPS (Hardware, Netz, versehentliches
  `docker compose down`) legt die gesamte Anwendung inklusive Datenbank lahm. Ein Failover auf
  einen zweiten Server ist nicht eingerichtet.
- MariaDB und Redis laufen als einzelne Instanz ohne Replikation. Backups (`backup/backup.sh`)
  und der Wiederherstellungstest (`backup/restore-test.sh`) sind die einzige Absicherung gegen
  Datenverlust, kein Ersatz fuer echte Hochverfuegbarkeit.
- Skalierung ist auf die Kapazitaet des einen VPS begrenzt (`docker compose up -d --scale`
  erhoeht die Anzahl Worker-Container, nicht die Anzahl Server).
- Automatisches TLS setzt eingehende Verbindungen auf Port 80/443 direkt aus dem Internet voraus
  (HTTP-01- bzw. TLS-ALPN-Challenge); hinter einem zusaetzlichen externen Load Balancer oder CDN
  waere die Caddy-Konfiguration anzupassen.

## Offene Punkte nach der adversarialen Abnahme (Auftrag III)

Erledigt im Rahmen der Abnahme: Backup-Container ohne Docker-Socket (Ergebnisdatei `backup-status.json`),
Container-Mounts auf `releases/`, `shared/config.php`, `shared/storage`, `shared/sessions` begrenzt,
Metrik-Sammler ohne Root und ohne Wurzeldateisystem, Rollback prueft den Migrationsstand,
Statusseite wird aus dem Release ausgeliefert, Zugangsdaten im Wiederherstellungstest ueber
`--defaults-extra-file`.

Weiterhin offen und vor dem produktiven Betrieb zu entscheiden:

- **DOCKER-USER/ufw-Zusammenspiel**: `scripts/setup-vps.sh` weist auf das Verhalten von Docker
  gegenueber ufw hin, richtet aber kein `ufw-docker` oder eigene `DOCKER-USER`-Regeln ein. Da nur
  Caddy Ports veroeffentlicht und Datenbank/Redis dies bewusst nicht tun, ist das Restrisiko
  gering, sollte aber vor dem ersten produktiven Einzug bestaetigt werden (`docker ps` gegen die
  Liste veroeffentlichter Ports pruefen).
- **Rollback ohne Migrations-Rueckbau**: `scripts/rollback.sh` wechselt nur den Anwendungscode,
  nicht das Datenbankschema (Migrationen sind additiv angelegt, siehe `docs/migrations.md`). Das
  Skript bricht ab, wenn die Datenbank Migrationen enthaelt, die das Zielrelease nicht kennt;
  `FORCE_ROLLBACK=1` uebersteuert das nur nach bewusster Pruefung. Ein tatsaechlicher Rueckbau
  des Schemas bleibt ein manueller Eingriff.
- **rclone-Verfuegbarkeit im Alpine-Image**: `deploy/vps/backup/Dockerfile` versucht, `rclone` aus
  dem Alpine-Community-Repository zu installieren, faellt beim Scheitern aber nur auf den
  `curl`-Ausweichpfad zurueck (setzt ein HTTPS-Ziel voraus, das PUT/Upload beherrscht). Vor dem
  produktiven Einsatz mit dem tatsaechlich vorgesehenen Backup-Ziel (`BACKUP_REMOTE`) pruefen,
  welcher der beiden Wege tatsaechlich genutzt wird.
- **HSTS**: bewusst auskommentiert in beiden Caddyfiles, bis app-, admin- und api-Host dauerhaft
  ausschliesslich unter gueltigem HTTPS erreichbar sind. Freischaltung erst nach ausdruecklicher
  Bestaetigung (siehe Kommentar in `Caddyfile`) und, laut Eskalationsregel, nach Abstimmung mit der
  Geschaeftsfuehrung, da eine falsche HSTS-Einstellung sich wegen des Browser-Caches nicht
  kurzfristig zuruecknehmen laesst.
- **HEALTH_STRICT**: in `.env` bis zum Cutover auf `false` lassen (ohne DNS kein Zertifikat, der
  lokale HTTPS-Check ueber Caddy kann noch nicht bestehen); nach erfolgreichem Cutover auf `true`
  setzen, damit ein fehlgeschlagener Health-Check das automatische Rollback ausloest.
- **Stack nie gestartet**: `docker compose config`, `bash -n` und `php -l` wurden ausgefuehrt, ein
  Start der Container mit Health Checks, Let's Encrypt und SSH-Deployment war in der
  Entwicklungsumgebung ohne Docker-Daemon nicht moeglich und ist Teil der Staging-Erprobung.

## Pruefungen, die dieser Ordner ohne laufenden Docker-Daemon besteht

```bash
docker compose -f deploy/vps/docker-compose.yml -f deploy/vps/docker-compose.prod.yml \
    --env-file deploy/vps/.env.example config > /dev/null

docker compose -f deploy/vps/docker-compose.yml -f deploy/vps/docker-compose.staging.yml \
    --env-file deploy/vps/.env.example config > /dev/null

bash -n deploy/vps/scripts/*.sh deploy/vps/backup/*.sh
php -l deploy/vps/scripts/db-verify.php
```
