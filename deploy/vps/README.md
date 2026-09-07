# SmartEinzug: Docker-Stack fuer den IONOS VPS

Betreiber: Müller Holding AG. Dieser Ordner enthaelt den vollstaendigen Docker-Stack und die
Serverskripte fuer den Betrieb von SmartEinzug auf einem eigenen VPS (Ubuntu 24.04, Docker aus dem
offiziellen Repository, kein Plesk). Das bisherige IONOS-Webhosting mit Apache/.htaccess und Cron
bleibt daneben bestehen (siehe php-ionos/.htaccess, .github/workflows/deploy.yml) und wird durch
diesen Stack nicht ersetzt, solange die Migration nicht abgeschlossen ist.

## Dateien

| Datei/Ordner | Zweck |
|---|---|
| `docker-compose.yml` | Basisdienste (Caddy, php, scheduler, Worker-Pools, metrics, redis); KEINE eigene MariaDB und KEIN Backup-Container: beides uebernimmt Coolify |
| `docker-compose.prod.yml` | Override Produktion: Ressourcenlimits, zweiter Lexware-Worker (`worker-lexware-2`) |
| `docker-compose.staging.yml` | Override Staging: eigener Compose-Projektname (`smarteinzug-staging`, eigene Container-/Netz-/Volume-Namen), eigene Traefik-Namen (`smarteinzug-staging-*`), kleinere Ressourcenlimits, eigene Caddyfile, nur ein Lexware-Worker |
| `Caddyfile` / `Caddyfile.staging` | Reverse Proxy, ersetzt `php-ionos/.htaccess` vollstaendig |
| `php/Dockerfile`, `php/php.ini`, `php/www.conf` | gemeinsames PHP-Image fuer Web, Scheduler, alle Worker |
| `redis/redis.conf` | Redis-Konfiguration (kein persistenter Datenbestand) |
| `.env.example` | Vorlage fuer `.env` (Domains, Coolify-Netz, Containername der Coolify-MariaDB, Backup-Pfad, UID/GID, Worker-Speicher) |
| `scripts/setup-vps.sh` | Einmalige Grundeinrichtung eines frischen VPS |
| `scripts/deploy-runner.sh` | Serverseitiger Einstiegspunkt fuer den GitHub-Workflow: Sperre, Entkopplung von der SSH-Sitzung (`setsid`), Status-/Protokolldatei, ruft danach `deploy.sh` auf |
| `scripts/deploy-status.sh` | Aktuellen Deployment-Status (JSON) und optional die letzten Protokollzeilen ausgeben |
| `scripts/deploy.sh` / `scripts/rollback.sh` | Aktivieren bzw. Zuruecknehmen eines Release |
| `scripts/db-import.sh` | Dump mit Pruefsumme per docker exec in die Coolify-MariaDB einspielen (kein Port noetig) |
| `scripts/db-verify.php` | Tabellen, Zeilenzahlen, CHECKSUM TABLE als JSON (Alt/Neu-Abgleich) |
| `scripts/maintenance.sh` | Wartungsmodus (`app/storage/maintenance.flag`) ein-/ausschalten |
| `backup/restore-test.sh` | Wiederherstellungstest eines Coolify-Dumps in einer temporaeren Datenbank (Client-Container im Coolify-Netz); `backup.sh`/`Dockerfile` nur Ausweichloesung ohne Coolify, nicht im Stack |
| `tools/compose-check.py` (liegt unter `tools/`, nicht unter diesem Ordner, gehoert aber zur Pruefung dieses Ordners) | Prueft die Compose-Dateien und `php/Dockerfile` ohne laufenden Docker-Daemon: jeder Dienst aus dem PHP-Image hat einen eigenen, zum Prozess passenden Healthcheck, kein Healthcheck enthaelt ein unescaptes "$", Variablen haben einen Vorgabewert oder stehen in `.env.example`, Candidate-Pruefung vor Migration vor Cutover |
| `tools/staging-isolation-check.py` (liegt unter `tools/`) | Prueft anhand von `docker compose ... config` (kein Docker-Daemon noetig), dass Produktion und Staging eigene Projekt-/Volume-/Netz-/Traefik-Namen erhalten, redis in beiden Umgebungen keinen Host-Port/kein Coolify-Netz/keine Traefik-Labels hat und der `--expect-env`-Schutz vorhanden ist |
| `tools/redis-deploy-check.sh` (liegt unter `tools/`) | Simuliert `/opt/smarteinzug` mit einem Fake-"docker" und fuehrt die tatsaechliche `deploy.sh` aus: prueft die kontrollierte Aktualisierung/das Rollback der Redis-Infrastruktur VOR der Candidate-Pruefung (kein Docker-Daemon noetig, aber `jq` erforderlich, siehe `scripts/setup-vps.sh`) |

## Start

```bash
cd /opt/smarteinzug/deploy      # oder dieser Ordner beim ersten manuellen Einrichten
cp .env.example .env
# .env mit echten Werten fuellen (Passwoerter, Domains, COOLIFY_NETWORK, APP_UID/APP_GID, ...)

# RELEASE_SHA steht bewusst NICHT in .env: working_dir aller PHP-Container und der Caddy-Dokumentenstamm
# haengen davon ab (siehe docker-compose.yml), deploy.sh/rollback.sh exportieren die Variable automatisch
# vor jedem Aufruf. Fuer einen manuellen "docker compose"-Befehl vorher setzen, z.B.:
export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env up -d
```

Staging entsprechend mit `docker-compose.staging.yml` und einer eigenen `.env` (eigener Server,
eigene Datenbank, nur `DOMAIN_STAGING` gesetzt). Voraussetzung fuer beide Faelle: `/opt/smarteinzug`
ist bereits eingerichtet (`scripts/setup-vps.sh`), `/opt/smarteinzug/shared/config.php` enthaelt eine
vollstaendige Konfiguration (Vorlage `php-ionos/app/config.example.php`) und `/opt/smarteinzug/releases/current`
zeigt bereits auf ein Release (legt `scripts/deploy.sh` bei der Erstinstallation selbst an). Fuer
Staging ist in dieser `config.php` zwingend `'environment' => 'staging'` zu setzen (siehe unten,
Abschnitt "Staging- und Produktionsisolation", sowie `docs/vps/02-einrichtung-vps.md`, Kapitel 25 fuer
die vollstaendige, sichere Vorgehensweise bei der Ersteinrichtung).

Regulaere Deployments laufen ueber `scripts/deploy-runner.sh <git-sha>` (haelt die Sperre, entkoppelt
den mehrminuetigen Vorgang von der SSH-Sitzung des GitHub-Workflows, siehe Abschnitt "Deployment: Ablauf
und Ausfallsicherheit" unten), ausgeloest durch den GitHub-Workflow (VPS-Job per SSH). Der direkte Aufruf
von `scripts/deploy.sh <git-sha>` oder von Hand `docker compose ... up -d` ist nur fuer die
Ersteinrichtung und fuer gezielte Eingriffe gedacht (dann ohne den Runner, mit eigener Sperre).

## Deployment: Ablauf und Ausfallsicherheit

Hintergrund (behobene Stoerung): Ein direkter, lang laufender `ssh ... deploy.sh <sha>`-Aufruf aus dem
GitHub-Workflow wurde einmal durch einen kurzen SSH-Verbindungsabbruch mitten im `docker compose up`
beendet ("client_loop: send disconnect: Broken pipe"). Der entfernte Prozess erhielt dadurch ein Signal
und starb, WAEHREND neue Container erzeugt wurden - der Symlink `current` war zu diesem Zeitpunkt noch
nicht umgestellt und die Migration noch nicht erreicht (die bestehende Reihenfolge war also nicht die
Ursache), aber mehrere Container blieben im Zustand `created` (nie gestartet) haengen, waehrend andere
weiterliefen. Seitdem laeuft das eigentliche Deployment serverseitig entkoppelt von der SSH-Sitzung:

1. GitHub Actions ruft **`scripts/deploy-runner.sh <git-sha>`** auf. Das Skript prueft nicht-blockierend
   die Sperre (`deploy/.deploy.lock`, dieselbe wie bei `deploy.sh`/`rollback.sh`); laeuft bereits ein
   Deployment oder Rollback, wird NICHTS gestartet ("REJECTED", Exit-Code 3, kein Doppel-Deploy durch
   einen GitHub-Retry oder einen zweiten Workflow-Lauf). Andernfalls startet es sich selbst per `setsid`
   in einer neuen, von der SSH-Sitzung unabhaengigen Sitzung neu (kein SIGHUP mehr bei einem
   Verbindungsabbruch), vererbt dabei den bereits gehaltenen Sperr-Deskriptor und kehrt selbst sofort
   zurueck ("TRIGGERED"). PID-Datei, Statusdatei (`deploy/.deploy-status.json`, JSON: `phase`, `sha`,
   `pid`, Zeitstempel, `exit_code`, Protokolldateiname, kurze Meldung - keine Geheimnisse) und ein
   eigenes Protokoll unter `logs/` machen den Vorgang jederzeit nachvollziehbar.
2. GitHub Actions fragt danach wiederholt ueber KURZE, unabhaengige SSH-Verbindungen
   **`scripts/deploy-status.sh`** ab, bis die Phase `success` oder `failed` erreicht ist. Jede einzelne
   Abfrage darf abbrechen, ohne das laufende Deployment zu gefaehrden; nach einem eigenen Abbruch fragt
   der naechste Workflow-Lauf denselben Status erneut ab, statt blind einen zweiten Deploy zu starten.
3. Innerhalb dieser Huelle laeuft `deploy.sh` inhaltlich wie zuvor, mit einer wichtigen Ergaenderung:
   working_dir aller Container ist ueber `RELEASE_SHA` an das konkrete Release gebunden (nicht an den
   mutable Symlink `current`, siehe "Architekturentscheidungen"), und Candidate-Pruefung sowie Migration
   laufen ueber `docker compose run --rm --no-deps` in einem ZUSAETZLICHEN, isolierten Container, BEVOR
   die eigentlichen Live-Container ("php", "scheduler", "worker-*") ueberhaupt angefasst werden:
   Image bauen (falls noetig) -> Candidate isoliert pruefen (`bin/healthcheck.php --db --redis` mit dem
   NEUEN Code, laufende Anwendung unberuehrt) -> Migrationen isoliert mit dem neuen Code einspielen (noch
   VOR dem Cutover) -> **Cutover** (`docker compose up -d`, jetzt sicher: Schema bereits migriert, Code
   und Healthchecks garantiert aus demselben Release) -> auf gesunde Container warten -> `current`
   umstellen (Buchfuehrung) -> Health-Check -> automatisches Rollback bei einem Fehler ab dem Cutover.
   Schlagen Candidate-Pruefung oder Migration fehl, wurde an den laufenden Containern noch NICHTS
   veraendert; ein Rollback ist dann nicht noetig, die alte Version laeuft unveraendert weiter.
4. Ein abgebrochener vorheriger Lauf, der Container im Zustand `created` hinterlassen hat, wird von
   `docker compose up -d` beim naechsten Versuch von selbst aufgeloest (idempotent: es wird nur erzeugt
   oder gestartet, was von der Zieldefinition abweicht); `deploy.sh` protokolliert den Zustand vor und
   nach diesem Schritt zur Nachvollziehbarkeit. Ausschliesslich Ressourcen des Compose-Projekts
   `smarteinzug` (siehe `name:` am Kopf von `docker-compose.yml`) werden dabei angefasst; Coolify-Proxy
   und Coolify-MariaDB gehoeren zu anderen Projekten und werden nie beruehrt.
5. Manuelle Statusabfrage auf dem Server: `bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 50`.

SSH-Keepalive: Der GitHub-Workflow setzt fuer alle SSH-/rsync-Verbindungen einheitlich
`ServerAliveInterval=30 ServerAliveCountMax=10 TCPKeepAlive=yes`, damit eine kurzzeitig instabile
Netzwerkverbindung waehrend einer laufenden Abfrage seltener zum Abbruch fuehrt. Das ersetzt aber NICHT
die Entkopplung durch `deploy-runner.sh`: Selbst mit Keepalive kann eine SSH-Verbindung abbrechen (Runner-
Neustart, Netzwerkstoerung); die Korrektheit haengt deshalb bewusst nicht davon ab, dass eine einzelne
SSH-Verbindung die gesamte Deploymentdauer uebersteht.

## Staging- und Produktionsisolation

Kurzfassung (Details und die vollstaendige, sichere Vorgehensweise zur Ersteinrichtung: siehe
`docs/vps/06-betrieb.md`, Abschnitt "Staging- und Produktionsisolation", und
`docs/vps/02-einrichtung-vps.md`, Kapitel 25):

- `docker-compose.staging.yml` setzt einen eigenen Compose-Projektnamen (`name: smarteinzug-staging`):
  eigene Container-, Netz- und Volume-Namen, unabhaengig von Produktion.
- Die Traefik-Labels des `caddy`-Dienstes stehen ausschliesslich in `docker-compose.prod.yml`
  (Namen `smarteinzug-*`) bzw. `docker-compose.staging.yml` (`smarteinzug-staging-*`), nie in der
  gemeinsamen `docker-compose.yml`.
- Jede Candidate-Pruefung (`deploy.sh`) und jeder Rollback (`rollback.sh`) ruft zusaetzlich
  `bin/healthcheck.php --expect-env=$DEPLOY_ENV` auf: Ein Staging-Deploy VERLANGT dafuer
  `'environment' => 'staging'` in `shared/config.php` (siehe `php-ionos/app/config.example.php`) und
  bricht sonst ab, bevor irgendetwas an laufenden Containern oder der Datenbank veraendert wird.
- Die primaere Absicherung bleibt trotzdem die Servertrennung: Staging auf einem eigenen, physisch
  getrennten VPS mit eigener Coolify-MariaDB betreiben, niemals auf dem Produktions-VPS.
- Regressionstest: `python3 tools/staging-isolation-check.py` (kein Docker-Daemon noetig).

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

Datenbank: MariaDB 11.8 laeuft bereits als private Coolify-Datenbankressource (Datenbank `smarteinzug`,
kein oeffentlicher Port, taegliche Coolify-Backups mit externem Ziel Hetzner Object Storage, Restore
getestet). Der Stack startet deshalb KEINE eigene MariaDB und KEINEN Backup-Container. PHP, Scheduler,
Worker und Metrics haengen zusaetzlich am Coolify-Netz (`COOLIFY_NETWORK`) und erreichen den
Datenbank-Container unter seinem Containernamen (`db.host` in `shared/config.php`; Name in Coolify in der
internen Verbindungsadresse oder mit `docker ps`). Dasselbe Netz gibt den Anwendungscontainern den Weg ins
Internet (Lexware Office, Stripe, Mail). Liegt die Coolify-Datenbank in einem anderen Netz als der Proxy
(`docker inspect <db-container>`), `COOLIFY_NETWORK` auf dieses Netz setzen; Proxy und Datenbank muessen
im selben Netz liegen, sonst die Datenbankressource in Coolify im Standardziel (localhost, Netz `coolify`)
neu anlegen. Einrichtung: `docs/vps/08-hostinger-coolify.md`.

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
  inklusive dieses `deploy/vps`-Ordners). Container binden `/opt/smarteinzug/releases` nur LESEND ein
  (alle vorgehaltenen Releases), ihr `working_dir` zeigt aber gezielt auf
  `/opt/smarteinzug/releases/${RELEASE_SHA}` (Umgebungsvariable, von `deploy.sh`/`rollback.sh` vor jedem
  Compose-Aufruf gesetzt) - NICHT auf einen mutable Symlink. Damit gehoeren Compose-Konfiguration
  (Healthchecks eingeschlossen), Image und Code bei jedem Containerstart garantiert zum selben Release.
  `/opt/smarteinzug/releases/current` bleibt als Symlink bestehen, ist aber reine Buchfuehrung fuer
  Menschen und Werkzeuge (z.B. `readlink -f`, `scripts/db-import.sh`); `deploy/`, `backups/` und `logs/`
  sind fuer keinen Anwendungscontainer sichtbar.
- `app/config.php` und `app/storage` liegen ausserhalb jedes Release unter
  `/opt/smarteinzug/shared/` und werden separat eingebunden (config.php read-only, storage
  beschreibbar).
- `worker-lexware-2` ist in der Basis-Datei definiert und wird in `docker-compose.staging.yml` ueber
  ein nie aktiviertes Profil abgeschaltet; Produktion setzt nur Ressourcenlimits. Die Anzahl der
  Lexware-Worker unterscheidet sich damit allein durch die verwendete Override-Datei.
- Scheduler und Worker haben `stop_grace_period: 660s`; `deploy.sh` und `rollback.sh` starten sie mit
  `restart -t 660`, damit ein laufender Sync-Abschnitt (bis 600 s) sauber beendet wird.
- Die statische Statusseite (`websites/status.smart-einzug.de`) wird vom GitHub-Workflow je Release
  unter `releases/<git-sha>/status/` abgelegt; Caddy liefert `releases/${RELEASE_SHA}/status` aus (siehe
  Caddyfile, `{$RELEASE_SHA}`). Sie gehoert damit zum Release und wechselt mit ihm (auch beim Rollback).

Der Metrik-Sammler liest die lokale Kopie der Coolify-Backups (`COOLIFY_BACKUP_DIR`, read-only) und schreibt
Zeitpunkt und Groesse der neuesten Sicherung als `backup-status.json` in den gemeinsamen Speicher
`/opt/smarteinzug/shared/storage`; der Monitoring-Sammler der Anwendung liest die Datei (Komponente
Sicherungen). Es wird kein Docker-Socket eingebunden.

## Grenzen (bewusst, ein VPS)

- Keine Hochverfuegbarkeit: ein Ausfall des VPS (Hardware, Netz, versehentliches
  `docker compose down`) legt die gesamte Anwendung inklusive Datenbank lahm. Ein Failover auf
  einen zweiten Server ist nicht eingerichtet.
- MariaDB (Coolify-Ressource) und Redis laufen als einzelne Instanz ohne Replikation. Die Coolify-Backups
  (taeglich, Hetzner Object Storage, Restore getestet) und `backup/restore-test.sh` sind die einzige Absicherung gegen
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
- **Backup-Skripte ohne Coolify**: `backup/backup.sh` und `backup/Dockerfile` sind nicht Teil des Stacks
  (Sicherung durch Coolify). Sie bleiben als Ausweichloesung fuer einen Server ohne Coolify-Backups
  erhalten; vor einem solchen Einsatz `rclone`-Verfuegbarkeit im Alpine-Image und das Ziel `BACKUP_REMOTE`
  pruefen.
- **Lokale Kopien der Coolify-Backups**: Die Komponente Sicherungen im Admin liest `COOLIFY_BACKUP_DIR`
  (Standard `/data/coolify/backups`). Pfad und Aufbewahrung lokaler Kopien sind in Coolify zu pruefen;
  ohne lokale Kopien zeigt die Komponente "nicht eingerichtet".
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

## Healthchecks

Jeder Dienst aus dem gemeinsamen PHP-Image definiert seinen Healthcheck in `docker-compose.yml`
selbst (`php`: `--db`, `scheduler` und alle `worker-*`: `--heartbeat`, `metrics`: `--metrics`);
`php/Dockerfile` setzt bewusst `HEALTHCHECK NONE`, damit ein vergessener Eintrag als "kein
Healthcheck" auffaellt und nicht als falsches Ergebnis eines fuer die jeweilige Rolle unpassenden,
vererbten Standard-Healthchecks. Genau dieser Fall (Dienst `metrics` erbte den
Worker-Heartbeat-Healthcheck, obwohl `bin/host-metrics.php` keinen Worker-Heartbeat schreibt) fuehrte
beim ersten Deployment zu einem dauerhaft "unhealthy" gemeldeten Container und einem abgebrochenen
`deploy.sh`; Einzelheiten, die Tabelle aller Healthchecks und die Stoerungspruefung stehen in
`docs/vps/06-betrieb.md`, Abschnitt "Healthchecks der Container". `redis` prueft ueber
`redis-cli ping`; `caddy` hat bewusst keinen Container-Healthcheck (das Basisimage bringt keinen mit,
`deploy.sh` wartet nur auf Container mit Healthcheck und prueft die Proxykette anschliessend
funktional ueber `health.php`).

## Pruefungen, die dieser Ordner ohne laufenden Docker-Daemon besteht

```bash
# RELEASE_SHA ist jetzt ein Pflichtwert (":?" in docker-compose.yml); ein beliebiger Platzhalter
# genuegt fuer die reine Konfigurationspruefung.
export RELEASE_SHA=pruefung

docker compose -f deploy/vps/docker-compose.yml -f deploy/vps/docker-compose.prod.yml \
    --env-file deploy/vps/.env.example config > /dev/null

docker compose -f deploy/vps/docker-compose.yml -f deploy/vps/docker-compose.staging.yml \
    --env-file deploy/vps/.env.example config > /dev/null

bash -n deploy/vps/scripts/*.sh deploy/vps/backup/*.sh
php -l deploy/vps/scripts/db-verify.php
python3 tools/compose-check.py
bash tools/deploy-runner-check.sh
php tools/healthcheck-redis-check.php
python3 tools/staging-isolation-check.py
bash tools/redis-deploy-check.sh
```

`tools/deploy-runner-check.sh` prueft `deploy-runner.sh` gegen ein simuliertes `/opt/smarteinzug` in
einem temporaeren Ordner (kein echter Server, kein Docker noetig): Sperre wird bei einem parallelen
zweiten Versuch abgelehnt (kein Doppel-Deploy), ein simulierter SSH-Abbruch (SIGHUP/Beenden des
ausloesenden Vordergrundprozesses) stoppt den bereits per `setsid` entkoppelten Hintergrundlauf NICHT,
Erfolg und Fehlschlag landen korrekt in der Statusdatei, und ein erneuter Lauf nach Abschluss ist
wieder moeglich (idempotent).

`python3 tools/compose-check.py` prueft zusaetzlich statisch (per Textsuche in `deploy.sh`, kein
Docker noetig), dass die Reihenfolge Candidate-Pruefung vor Migration vor Cutover eingehalten wird
und beide isolierten Schritte ausschliesslich ueber `docker compose run --rm --no-deps` laufen (nie
`up`/`restart`, ruehren also nie die laufenden Container an). `tools/healthcheck-redis-check.php`
prueft `monitor_category()` gegen glibc- und musl-typische Fehlertexte (Hintergrund: das PHP-Image
ist Alpine/musl-basiert, siehe `docs/vps/06-betrieb.md`, Abschnitt "Candidate-Pruefung meldet
`redis: other`") sowie `bin/healthcheck.php --redis` gegen einen tatsaechlich nicht aufloesbaren
Hostnamen und einen tatsaechlich geschlossenen Port (echte Netzwerkebene, kein Mock): Beide Faelle
muessen eine eindeutige Kategorie liefern (z. B. `dns`, `connection_refused`), nie mehr `other` oder
`nicht erreichbar`; ausserdem startet dieser Test testweise einen echten, temporaeren Redis-Server
(protected-mode yes/no) und bestaetigt die Kategorie `redis_protected_mode` sowie, dass `redis.conf`
tatsaechlich `protected-mode no` enthaelt (siehe `docs/vps/06-betrieb.md`, Abschnitt "Redis'
eigener protected mode").

`bash tools/redis-deploy-check.sh` prueft den Redis-Infrastruktur-Teil von `deploy.sh` (siehe
`docs/vps/06-betrieb.md`, Abschnitt "Der Fix konnte sich nicht selbst deployen"): simuliert
`/opt/smarteinzug` in einem temporaeren Ordner und ersetzt "docker" durch einen steuerbaren Fake,
gegen den die tatsaechliche `deploy.sh` (unveraendert, nur `BASE` umgeschrieben) laeuft. Bestaetigt
u. a.: unveraenderte `redis.conf` fuehrt zu keinem Recreate; eine geaenderte `redis.conf` wird
AUSSCHLIESSLICH ueber den `redis`-Dienst (kein anderer Dienst) aktualisiert, BEVOR die
Candidate-Pruefung beginnt; erst ein erfolgreicher Netzwerktest aus einem ANDEREN Container (nicht
per `docker exec`) erlaubt der Candidate-Pruefung zu folgen; ein trotz "healthy" ueber das Netz
blockierter Redis-Dienst fuehrt zu einem bestaetigten Rollback der Redis-Infrastruktur (nicht des
gesamten Releases) und einem Abbruch OHNE Migration/Cutover; eine ungueltige neue `redis.conf` wird
bereits in der Vorab-Validierung erkannt, bevor der laufende Dienst angefasst wird; eine Verletzung
der Netzwerk-Isolationsvorgaben (Host-Port, Coolify-Netz, Traefik-Labels) bricht vor jeder Aenderung
ab; eine Wiederholung bleibt idempotent.
