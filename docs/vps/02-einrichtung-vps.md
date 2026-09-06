# Einrichtung des IONOS VPS: Schritt für Schritt

Stand: 06.09.2026 (Auftrag III). Richtet sich an Administratoren ohne tiefe Linux-Erfahrung. Jeder
Schritt nennt Zweck, Befehle, das erwartete Ergebnis, ein Prüfkommando und mögliche Fehler. Schritte
der Reihe nach abarbeiten, jeden Schritt erst abschließen, wenn die Prüfung bestanden ist.

Platzhalter in diesem Dokument: `HIER-VPS-IP` (IPv4-Adresse des VPS aus dem IONOS Kundenbereich),
`HIER-ADMIN-ADRESSE` (E-Mail-Adresse für Zertifikatswarnungen und Systembenachrichtigungen). Echte
Werte nie in dieses Repository schreiben, nur in `.env` und `app/config.php` auf dem Server.

Grundlage: `deploy/vps/` (Docker-Stack und Skripte, siehe `deploy/vps/README.md`),
`docs/vps/01-architektur.md` (Zielbild).

## 1. IONOS VPS bestellen

**Zweck:** Server-Ressource beschaffen.
**Vorgehen:** IONOS Kundenbereich > Server > VPS bestellen. Betriebssystem Ubuntu 24.04 LTS,
Ressourcen nach erwarteter Firmenzahl wählen (Empfehlung als Startpunkt: 4 vCPU, 8 GB RAM, 80 GB
SSD; bei wenigen Firmen genügt weniger, vor der Bestellung mit der Geschäftsführung abstimmen).
**Erwartetes Ergebnis:** Server ist aktiv, IPv4-Adresse (`HIER-VPS-IP`) und root-Zugangsdaten liegen
vor (E-Mail von IONOS oder Kundenbereich).
**Prüfkommando:** `ping HIER-VPS-IP` (Server antwortet).
**Mögliche Fehler:** Bestellung noch nicht abgeschlossen (Provisionierung dauert laut IONOS bis zu
einigen Minuten); falsches Betriebssystem gewählt (Neuinstallation über den Kundenbereich möglich,
löscht alle Daten).

## 2. Erste Anmeldung als root

**Zweck:** Zugang zum Server prüfen, root-Passwort sofort ändern.
**Befehle:**
```bash
ssh root@HIER-VPS-IP
passwd
```
**Erwartetes Ergebnis:** Anmeldung gelingt, neues root-Passwort gesetzt (nur als Rückfall gedacht,
root-Anmeldung wird in Schritt 6 abgeschaltet).
**Prüfkommando:** erneute Anmeldung mit dem neuen Passwort.
**Mögliche Fehler:** „Connection refused“ (Server noch nicht bereit, einige Minuten warten);
„Host key verification failed“ bei erneuter Installation desselben Servers (alten Eintrag mit
`ssh-keygen -R HIER-VPS-IP` entfernen, danach den neuen Host-Key bewusst bestätigen).

## 3. Eigenes SSH-Schlüsselpaar erzeugen

**Zweck:** Persönlicher Schlüssel des Administrators für die spätere Anmeldung als Benutzer
`deploy` (kein root-Zugriff mehr per SSH nach Schritt 6).
**Befehle (auf dem eigenen Rechner, nicht auf dem VPS):**
```bash
ssh-keygen -t ed25519 -C "admin@muellerhv.de-vps" -f ~/.ssh/smarteinzug_vps_admin
```
**Erwartetes Ergebnis:** Zwei Dateien, `smarteinzug_vps_admin` (privat, niemals weitergeben) und
`smarteinzug_vps_admin.pub` (öffentlich, wird in Schritt 5 auf den Server übertragen).
**Prüfkommando:** `ls -l ~/.ssh/smarteinzug_vps_admin*`.
**Mögliche Fehler:** Datei existiert bereits (anderen Dateinamen wählen, alte Schlüssel nicht
überschreiben, falls sie noch verwendet werden).

## 4. Öffentlichen Schlüssel auf den Server übertragen

**Zweck:** `setup-vps.sh` benötigt die Datei lokal auf dem Server, um sie dem Benutzer `deploy`
zuzuordnen.
**Befehle:**
```bash
scp ~/.ssh/smarteinzug_vps_admin.pub root@HIER-VPS-IP:/root/admin_key.pub
```
**Erwartetes Ergebnis:** Datei liegt unter `/root/admin_key.pub` auf dem Server.
**Prüfkommando:** `ssh root@HIER-VPS-IP cat /root/admin_key.pub` zeigt den Schlüssel.
**Mögliche Fehler:** Falscher lokaler Pfad (Tippfehler im Dateinamen).

## 5. Grundeinrichtung mit setup-vps.sh ausführen

**Zweck:** System aktualisieren, Grundwerkzeuge installieren, Benutzer `deploy` mit dem
übertragenen Schlüssel anlegen, Docker installieren, Firewall (ufw) und `fail2ban` einrichten,
Verzeichnisstruktur `/opt/smarteinzug` anlegen. Das Skript ist idempotent (mehrfaches Ausführen
schadet nicht).
**Befehle (auf dem Server als root):**
```bash
# Repository-Inhalt deploy/vps/ vorher auf den Server übertragen, z. B.:
scp -r deploy/vps root@HIER-VPS-IP:/root/deploy-vps
ssh root@HIER-VPS-IP
cd /root/deploy-vps/scripts
bash setup-vps.sh /root/admin_key.pub
```
**Erwartetes Ergebnis:** Ausgabe mit Schritten 1 bis 9 (Systemaktualisierung, Grundwerkzeuge,
Benutzer `deploy`, Docker, ufw, fail2ban, unattended-upgrades, Verzeichnisstruktur, Hinweis zur
SSH-Härtung). Das Skript ändert `sshd_config` in diesem Schritt noch NICHT automatisch ab (siehe
Kopfkommentar im Skript); es bereitet nur vor.
**Prüfkommando:** `id deploy` (Benutzer existiert), `docker --version`, `ls /opt/smarteinzug`
(Ordner `releases`, `shared`, `deploy`, `status`, `backups` vorhanden).
**Mögliche Fehler:** „Datei sieht nicht wie ein öffentlicher SSH-Schlüssel aus“ (falsche Datei
übergeben, `.pub`-Datei prüfen); Skript bricht bei fehlenden Paketquellen ab (Internetzugang des
Servers prüfen, `apt-get update` von Hand testen).

## 6. Zugang mit dem neuen Schlüssel testen, dann SSH härten

**Zweck:** Sicherstellen, dass der Zugang als `deploy` funktioniert, bevor root-Anmeldung und
Passwort-Anmeldung abgeschaltet werden. Dieser Schritt verhindert eine Aussperrung.
**Befehle:**
```bash
# Von einem ZWEITEN Terminal aus testen, die root-Sitzung offen lassen:
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
```
Erst wenn diese Anmeldung sicher gelingt, in der root-Sitzung fortfahren:
```bash
sudo sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sudo systemctl reload sshd
```
**Erwartetes Ergebnis:** Anmeldung als `deploy` mit dem Schlüssel gelingt ohne Passwort;
Passwort-Anmeldung und direkte root-Anmeldung sind danach nicht mehr möglich.
**Prüfkommando:** `ssh root@HIER-VPS-IP` (muss abgelehnt werden), `ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP` (muss gelingen).
**Mögliche Fehler:** Wird die Härtung ausgeführt, bevor der Zugang als `deploy` bestätigt ist,
droht vollständige Aussperrung (nur über die IONOS-Notfallkonsole des Kundenbereichs behebbar,
dort sind Neuinstallation oder Rettungssystem möglich, aber aufwendig). Deshalb unbedingt zuerst
im zweiten Terminal testen.

## 7. Firewall prüfen

**Zweck:** Sicherstellen, dass nur die notwendigen Ports offen sind.
**Befehle:** `sudo ufw status verbose` (als `deploy` mit `sudo`).
**Erwartetes Ergebnis:** Nur SSH (Port aus Schritt 6, Standard 22), HTTP (80) und HTTPS (443)
erlaubt; alles andere abgelehnt.
**Prüfkommando:** von einem fremden Rechner `nc -zv HIER-VPS-IP 3306` (MariaDB) muss fehlschlagen.
**Mögliche Fehler:** `ufw` inaktiv (`sudo ufw enable`, danach Regeln erneut prüfen, SSH-Port zuerst
erlauben, sonst Aussperrung).

## 8. Verzeichnisstruktur prüfen

**Zweck:** Sicherstellen, dass die von `deploy.sh` erwartete Struktur vorhanden ist.
**Befehle:** `ls -la /opt/smarteinzug /opt/smarteinzug/shared`.
**Erwartetes Ergebnis:** Unterordner `releases`, `shared` (mit `storage`), `deploy`, `status`,
`backups`; Eigentümer `deploy`.
**Prüfkommando:** `stat -c '%U %a' /opt/smarteinzug/shared/storage`.
**Mögliche Fehler:** Falscher Eigentümer (`sudo chown -R deploy:deploy /opt/smarteinzug`).

## 9. Konfigurationsdatei anlegen

**Zweck:** `app/config.php` außerhalb jedes Release ablegen, damit ein Deployment sie nie
überschreibt.
**Befehle:**
```bash
scp php-ionos/app/config.example.php deploy@HIER-VPS-IP:/opt/smarteinzug/shared/config.php
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
nano /opt/smarteinzug/shared/config.php
```
Mindestens auszufüllen: Datenbankzugangsdaten (identisch mit der `.env` aus Schritt 10),
`app_secret` (`openssl rand -hex 32`), `cron_token`, `migration_token`, `app_base_url`,
`admin_base_url`, `public_base_url`, `allowed_hosts`, `operator`, `mail`.
**Erwartetes Ergebnis:** Vollständig ausgefüllte Konfigurationsdatei ohne Platzhalter aus der
Vorlage.
**Prüfkommando:** `php -l /opt/smarteinzug/shared/config.php` (lokal mit installiertem PHP oder im
Container aus Schritt 13/14).
**Mögliche Fehler:** Vergessene Platzhalter (`HIER-...`) in Produktion; unterschiedliche
Datenbankzugangsdaten zwischen `config.php` und `.env` (Anmeldung an MariaDB schlägt fehl).

## 10. .env für den Docker-Stack anlegen

**Zweck:** Umgebungsvariablen für `docker-compose.yml` (Datenbank, Domains, UID/GID, Backup-Ziel).
**Befehle:**
```bash
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
mkdir -p /opt/smarteinzug/deploy
cd /opt/smarteinzug/deploy
cp /root/deploy-vps/.env.example .env   # oder aus dem übertragenen deploy/vps
id deploy   # UID/GID für APP_UID/APP_GID ablesen
nano .env
```
**Erwartetes Ergebnis:** `.env` mit echten Werten (siehe `deploy/vps/.env.example` für die
vollständige Liste: `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `TZ`, `DOMAIN_APP`,
`DOMAIN_ADMIN`, `DOMAIN_API`, `DOMAIN_STATUS`, `LETSENCRYPT_EMAIL`, `APP_UID`, `APP_GID`,
`PM_MAX_CHILDREN`, `WORKER_MEMORY_MB`, `BACKUP_REMOTE`, `BACKUP_AGE_RECIPIENT`,
`BACKUP_RETENTION_DAYS`). Datei niemals einchecken.
**Prüfkommando:** `grep -c HIER .env` muss `0` liefern (keine Platzhalter mehr).
**Mögliche Fehler:** `APP_UID`/`APP_GID` weichen vom tatsächlichen Benutzer `deploy` ab (Container
kann `app/storage` dann nicht beschreiben, sichtbar an „Permission denied“ in den PHP-Logs).

## 11. Deploy-Skripte und erstes Release bereitstellen

**Zweck:** `docker-compose*.yml`, `Caddyfile` und `scripts/` liegen unter
`/opt/smarteinzug/deploy` (feste Position, unabhängig vom jeweils aktiven Release); ein erstes
Release liegt unter `/opt/smarteinzug/releases/<git-sha>/`, damit `current` gesetzt werden kann.
**Befehle:**
```bash
rsync -az /pfad/lokal/deploy/vps/ deploy@HIER-VPS-IP:/opt/smarteinzug/deploy/
rsync -az /pfad/lokal/php-ionos/ deploy@HIER-VPS-IP:/opt/smarteinzug/releases/erstinstallation/
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
ln -sfn /opt/smarteinzug/releases/erstinstallation /opt/smarteinzug/releases/current
```
Ab dem nächsten Push übernimmt der GitHub-Workflow diesen Schritt automatisch (siehe
`docs/vps/03-github-deployment.md`); dieser manuelle Schritt ist nur für die Ersteinrichtung nötig,
bevor der Workflow zum ersten Mal läuft.
**Erwartetes Ergebnis:** `/opt/smarteinzug/releases/current` zeigt auf ein vollständiges Release.
**Prüfkommando:** `ls -la /opt/smarteinzug/releases/current/bin`.
**Mögliche Fehler:** Vergessener führender Slash oder Tippfehler im Zielpfad (rsync legt dann einen
falschen Ordner an, `ls /opt/smarteinzug/releases` prüfen).

## 12. GitHub-Secrets und -Variablen für künftige Deployments einrichten

**Zweck:** Ab jetzt automatisch statt manuell deployen.
**Vorgehen:** vollständige Anleitung in `docs/vps/03-github-deployment.md` (Secrets `VPS_HOST`,
`VPS_SSH_USER`, `VPS_SSH_PORT`, `VPS_SSH_PRIVATE_KEY`, `VPS_SSH_KNOWN_HOSTS`; Variablen
`VPS_DEPLOY_ENABLED`, `VPS_DEPLOY_PATH`, `VPS_APP_DOMAIN`, `VPS_HEALTH_STRICT`).
**Erwartetes Ergebnis:** Ein manuell ausgelöster Workflow-Lauf (`workflow_dispatch`) erreicht den
Job „deploy-vps“ und schlägt frühestens beim Health-Check fehl (weil DNS noch nicht umgestellt
ist, siehe Schritt 16).
**Prüfkommando:** GitHub Actions, Log des Jobs „deploy-vps“.
**Mögliche Fehler:** siehe `docs/vps/03-github-deployment.md`, Abschnitt Fehlerbilder.

## 13. Docker-Stack erstmalig hochfahren

**Zweck:** Alle Container starten.
**Befehle:**
```bash
cd /opt/smarteinzug/deploy
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env up -d
```
**Erwartetes Ergebnis:** Alle Dienste aus `docker-compose.yml`/`docker-compose.prod.yml` starten
(`caddy`, `php`, `scheduler`, `worker-lexware-1`, `worker-lexware-2`, `worker-stripe`,
`worker-mail`, `worker-maintenance`, `metrics`, `mariadb`, `redis`, `backup`).
**Prüfkommando:** `docker compose -f docker-compose.yml -f docker-compose.prod.yml ps` (alle
Dienste `running`, MariaDB und PHP zusätzlich `healthy`, sobald deren Healthcheck einmal
durchgelaufen ist).
**Mögliche Fehler:** `mariadb` bleibt `starting` (Datenverzeichnis wird beim ersten Start
initialisiert, einige Minuten abwarten); `php` startet nicht, weil `config.php` fehlt oder
fehlerhaft ist (`docker compose logs php`).

## 14. Auf gesunden Zustand warten

**Zweck:** Sicherstellen, dass Datenbank und Anwendung tatsächlich bereit sind, bevor migriert
wird.
**Befehle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --db
```
**Erwartetes Ergebnis:** Exit-Code 0, keine Fehlerzeile.
**Prüfkommando:** `echo $?` nach dem Befehl.
**Mögliche Fehler:** Exit-Code 1 mit „db: ...“ (Datenbank noch nicht bereit oder falsche
Zugangsdaten zwischen `.env` und `config.php`; einige Sekunden warten und wiederholen).

## 15. Datenbankschema einspielen

**Zweck:** Bei einer Neuinstallation (kein Umzug bestehender Daten) das leere Schema anlegen.
Handelt es sich um einen Umzug von Bestandsdaten aus dem Webhosting, stattdessen
`docs/vps/04-datenbankmigration.md` befolgen (Dump statt leerem Schema).
**Befehle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mariadb \
  sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  < php-ionos/sql/schema.sql
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/migrate.php
```
**Erwartetes Ergebnis:** `bin/migrate.php` meldet „Migrationen: 0 eingespielt, 0 offen“ (Schema
enthält bereits alle Tabellen der aktuellen Version) oder spielt verbleibende Migrationsdateien
ein.
**Prüfkommando:** `bin/migrate.php --status` zeigt nur `success`-Zeilen.
**Mögliche Fehler:** Tabelle existiert bereits (Schema wurde versehentlich zweimal eingespielt;
`CREATE TABLE IF NOT EXISTS` verhindert einen Abbruch, prüfen, ob unerwartet Testdaten vorhanden
sind).

## 16. DNS auf den VPS umstellen

**Zweck:** Die Anwendungshosts erreichen ab jetzt den VPS statt (sofern vorher schon vorhanden)
das Webhosting.
**Vorgehen:** vollständige Anleitung in `docs/vps/05-dns-ssl.md`. TTL vorher auf 300 Sekunden
senken (siehe auch `docs/vps/04-datenbankmigration.md` für den zeitlichen Zusammenhang mit dem
Cutover).
**Erwartetes Ergebnis:** `app.smart-einzug.de`, `admin.smart-einzug.de`, `api.smart-einzug.de`,
`status.smart-einzug.de` lösen auf `HIER-VPS-IP` auf.
**Prüfkommando:** `dig +short app.smart-einzug.de`.
**Mögliche Fehler:** DNS-Propagierung dauert trotz niedriger TTL vereinzelt länger (Resolver-Cache
prüfen, z. B. mit `dig @1.1.1.1`).

## 17. TLS-Zertifikate prüfen

**Zweck:** Caddy hat automatisch gültige Let's-Encrypt-Zertifikate bezogen.
**Befehle:** `curl -vI https://app.smart-einzug.de/health.php 2>&1 | grep -i "SSL certificate"`.
**Erwartetes Ergebnis:** Gültiges Zertifikat, Aussteller „Let's Encrypt“ oder „(STAGING)“ nur
während eines bewussten Tests mit dem Let's-Encrypt-Staging-Endpunkt.
**Prüfkommando:** siehe oben; zusätzlich `docker compose logs caddy | grep -i certificate`.
**Mögliche Fehler:** Caddy erhält kein Zertifikat, weil DNS noch nicht auf den Server zeigt oder
Port 80/443 nicht erreichbar ist (`docker compose logs caddy`, Firewall aus Schritt 7 prüfen).

## 18. Health-Check von außen

**Zweck:** Bestätigen, dass die Anwendung über die neue Adresse erreichbar ist.
**Befehle:** `curl -s https://app.smart-einzug.de/health.php | jq .`.
**Erwartetes Ergebnis:** JSON mit `"php": true` und aktuellem UTC-Zeitstempel.
**Prüfkommando:** siehe oben; derselbe Aufruf, den auch der GitHub-Workflow ausführt
(`VPS_HEALTH_STRICT`, siehe `docs/vps/03-github-deployment.md`).
**Mögliche Fehler:** HTTP 404 (Host nicht in `allowed_hosts`, siehe Schritt 9); Zeitstempel fehlt
oder ist veraltet (`app/bootstrap.php` prüfen, PHP-Fehlerprotokoll ansehen).

## 19. Anmeldung, 2FA, Registrierung testen

**Zweck:** Den kompletten Anmeldeweg einmal durchspielen, bevor produktive Firmen umziehen.
**Vorgehen:** Test-Registrierung über `https://app.smart-einzug.de/register.php`, 2FA einrichten,
abmelden, erneut anmelden.
**Erwartetes Ergebnis:** Vollständiger Durchlauf ohne Fehler, E-Mail-Versand funktioniert (sofern
`mail.enabled = true`).
**Prüfkommando:** Eintrag in `audit_log` für die Testregistrierung.
**Mögliche Fehler:** Mailversand schlägt fehl (Absenderadresse gehört nicht zu einer Domain des
Hosting-Pakets, siehe `php-ionos/ANLEITUNG-IONOS.md`, Abschnitt 5).

## 20. Parallelbetrieb mit dem Webhosting bewusst planen

**Zweck:** Solange nicht alle Kundenfirmen umgezogen sind, bleibt das Webhosting mit seinem
eigenen Cron aktiv bedienbar; beide Umgebungen dürfen NICHT gleichzeitig auf dieselbe Datenbank
schreiben.
**Vorgehen:** Feature-Flag `features.queue` auf dem VPS zunächst auf eine kurze Liste von
Test-Firmen-IDs setzen, nicht auf `true`; das Webhosting bedient in dieser Phase weiterhin die
übrigen Firmen über seine eigene, getrennte Datenbank (kein gemeinsamer Datenbestand während der
Testphase).
**Erwartetes Ergebnis:** Klar getrennte Zuständigkeit je Firma, dokumentiert für das Team.
**Prüfkommando:** `tenant_feature_flags()` einer Testfirma zeigt `queue: true`.
**Mögliche Fehler:** Eine Firma versehentlich auf beiden Umgebungen gleichzeitig aktiv (führt zu
widersprüchlichen Daten, unbedingt vermeiden; siehe `docs/vps/04-datenbankmigration.md`,
„kein paralleles Schreiben in zwei Datenbanken“).

## 21. Backup einrichten und ersten Testlauf ausführen

**Zweck:** Sicherstellen, dass ab dem ersten Produktivtag ein wiederherstellbares Backup existiert.
**Befehle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec backup bash backup.sh
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec backup bash restore-test.sh
```
**Erwartetes Ergebnis:** Dump unter `/opt/smarteinzug/backups`, Prüfsumme in Ordnung,
Wiederherstellungstest erfolgreich, Ergebnis im Adminbereich System (Reiter Server) sichtbar
(Ergebnisdatei `backup-status.json` im gemeinsamen Speicher, sichtbar unter Admin, System, Dienste als Sicherungen).
**Prüfkommando:** `ls -la /opt/smarteinzug/backups`.
**Mögliche Fehler:** `BACKUP_REMOTE` nicht gesetzt (nur lokale Aufbewahrung, kein externer Upload,
bewusst zulässig, aber als Risiko dokumentieren); Docker-Socket nicht erreichbar (siehe
`deploy/vps/README.md`, Abschnitt Offene Punkte).

## 22. Host-Metriken und Monitoring prüfen

**Zweck:** Sicherstellen, dass der Adminbereich System auf dem VPS zusätzliche Kennzahlen zeigt,
die das Webhosting nicht liefern konnte (CPU, RAM, Platte, Load, Datenbankverbindungen).
**Vorgehen:** Adminbereich > System > Server aufrufen.
**Erwartetes Ergebnis:** Werte `host_cpu`, `host_mem`, `host_disk`, `host_load1` erscheinen mit
aktuellem Zeitstempel.
**Prüfkommando:** `docker compose logs metrics | tail -20`.
**Mögliche Fehler:** Werte fehlen dauerhaft (Bind-Mount `/hostproc` und Variable `HOST_ROOT` des
`metrics`-Containers prüfen, siehe `docker-compose.yml`; der Container sieht bewusst nicht das
Wurzeldateisystem des Hosts).

## 23. Log-Rotation prüfen

**Zweck:** Sicherstellen, dass Container-Logs die Festplatte nicht füllen.
**Befehle:** `docker inspect caddy --format '{{json .HostConfig.LogConfig}}'`.
**Erwartetes Ergebnis:** `json-file` mit Rotation (20 MB / 5 Dateien je Dienst, siehe
`docs/vps/06-betrieb.md`).
**Prüfkommando:** `df -h /var/lib/docker` nach einigen Betriebstagen.
**Mögliche Fehler:** Fehlende Log-Optionen in einer künftigen Änderung an `docker-compose.yml`
(vor jeder Änderung an diesem Kern-Bestandteil prüfen, ob die Rotation erhalten bleibt).

## 24. Wartungsmodus testen

**Zweck:** Sicherstellen, dass der Wartungsmodus vor dem eigentlichen Cutover (Schritt 30)
zuverlässig funktioniert.
**Befehle:**
```bash
bash scripts/maintenance.sh on
curl -s https://app.smart-einzug.de/health.php
bash scripts/maintenance.sh off
```
**Erwartetes Ergebnis:** Während „on“ zeigen Kundenseiten einen Wartungshinweis, `health.php`,
`migrate.php` und der Adminbereich bleiben erreichbar; nach „off“ ist alles wie zuvor.
**Prüfkommando:** siehe Befehle.
**Mögliche Fehler:** Wartungsmodus wirkt nicht sofort (prüfen, ob `app/storage` tatsächlich als
gemeinsamer Bind-Mount `/opt/smarteinzug/shared/storage` eingebunden ist, nicht als Kopie je
Container).

## 25. Staging-Umgebung einrichten (empfohlen vor jeder größeren Änderung)

**Zweck:** Änderungen an Docker-Stack, Migrationen und Deployment risikofrei testen.
**Vorgehen:** Empfehlung: eigener, kleinerer VPS mit eigener `.env` (nur `DOMAIN_STAGING` gesetzt),
`docker-compose.staging.yml` und `Caddyfile.staging` statt der Produktionsdateien (siehe
`deploy/vps/README.md`, Abschnitt „Start“). Keine produktiven Kundendaten auf Staging verwenden.
**Erwartetes Ergebnis:** `staging.smart-einzug.de` erreichbar, eigene, leere oder mit Testdaten
gefüllte Datenbank.
**Prüfkommando:** `curl -s https://staging.smart-einzug.de/health.php`.
**Mögliche Fehler:** Staging und Produktion teilen sich versehentlich dieselbe Datenbank oder
denselben `app_secret` (unbedingt vermeiden, getrennte `.env`/`config.php` verwenden).

## 26. Feature-Flag Queue schrittweise aktivieren

**Zweck:** Von der bisherigen Cron-Verarbeitung zur Warteschlange wechseln, ohne alle Firmen auf
einmal umzustellen.
**Vorgehen:** `features.queue` zunächst mit einer kurzen Liste von Firmen-IDs befüllen, im
Adminbereich System > Jobs beobachten, danach schrittweise erweitern, zuletzt auf `true` setzen.
**Erwartetes Ergebnis:** Job-Warteschlange füllt sich für die freigeschalteten Firmen, Worker
verarbeiten die Jobs, Dead-Letter-Liste bleibt leer.
**Prüfkommando:** Adminbereich System > Jobs, `bin/healthcheck.php --queue`.
**Mögliche Fehler:** Häufige Fehlversuche einer Firma (Circuit Breaker öffnet, siehe
`docs/vps/06-betrieb.md`, Abschnitt Circuit Breaker).

## 27. Skalierung testen

**Zweck:** Sicherstellen, dass sich Worker-Pools bei Bedarf hochskalieren lassen (z. B. während
eines großen Nachhol-Abgleichs vieler Firmen).
**Befehle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker-mail=2
```
**Erwartetes Ergebnis:** Zwei Container des Dienstes `worker-mail` laufen gleichzeitig, beide
melden sich in `worker_heartbeats`.
**Prüfkommando:** `docker compose ps | grep worker-mail`.
**Mögliche Fehler:** Skalierung eines Dienstes mit festem Containernamen schlägt fehl (betrifft nur
Dienste ohne `container_name`, siehe `docker-compose.yml`).

## 28. Rollback testen

**Zweck:** Sicherstellen, dass ein Rollback im Ernstfall tatsächlich funktioniert, bevor er
gebraucht wird.
**Befehle (auf Staging, nicht auf Produktion):**
```bash
bash /opt/smarteinzug/deploy/rollback.sh previous
```
**Erwartetes Ergebnis:** Vorheriges Release wieder aktiv, Anwendung erreichbar; siehe Hinweis in
`deploy/vps/README.md` zu Migrationen, die ein Rollback NICHT zurückbaut.
**Prüfkommando:** `readlink -f /opt/smarteinzug/releases/current`, Versionsanzeige im Adminbereich.
**Mögliche Fehler:** Kein `.previous_sha` vorhanden (noch kein zweites Release deployt; Ziel-SHA
dann ausdrücklich angeben, siehe `docs/vps/06-betrieb.md`).

## 29. Datenbankmigration von Bestandsdaten (falls zutreffend)

**Zweck:** Bestehende Firmendaten vom Webhosting übernehmen, statt mit einer leeren Datenbank zu
starten.
**Vorgehen:** vollständiges Runbook in `docs/vps/04-datenbankmigration.md`.
**Erwartetes Ergebnis:** Daten auf dem VPS entsprechen dem Stand des Webhostings zum
Migrationszeitpunkt, durch `db-verify.php` bestätigt.
**Prüfkommando:** siehe `docs/vps/04-datenbankmigration.md`.
**Mögliche Fehler:** siehe dort.

## 30. Produktions-Cutover

**Zweck:** Den VPS zur maßgeblichen Umgebung für die betroffenen Firmen machen.
**Vorgehen:** vollständige Checkliste in `docs/vps/07-cutover-checkliste.md` abarbeiten
(Reihenfolge: Wartungsmodus an, letzter Datenabgleich, DNS bereits umgestellt, Wartungsmodus aus,
Prüfpunkte der Checkliste, danach `admin_base_url` endgültig setzen, alte Webhosting-Zugangsdaten
nach der dort genannten Frist entfernen).
**Erwartetes Ergebnis:** Betroffene Firmen arbeiten ausschließlich über den VPS, das Webhosting
bedient nur noch Marketingseiten (und, sofern noch nicht umgezogene Firmen bestehen, weiterhin
deren Anwendung).
**Prüfkommando:** vollständige Checkliste in `docs/vps/07-cutover-checkliste.md`.
**Mögliche Fehler:** siehe dort; bei Zweifeln Cutover verschieben, nicht unter Zeitdruck
durchführen.
