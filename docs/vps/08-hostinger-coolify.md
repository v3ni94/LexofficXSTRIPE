# Hostinger-VPS mit Coolify: Einrichtung Schritt für Schritt

Stand: 06.09.2026 (Auftrag III, Nachtrag, siehe `docs/auftrag-iii-abschluss.md`). Betreiber:
Müller Holding AG. Richtet sich an einen nicht technischen Anwender und beschreibt den
tatsächlichen Weg vom ersten Login bis zum ersten Deployment und Datenbankimport auf dem
tatsächlich beschafften Server. Diese Datei ersetzt für den Hostinger-VPS die allgemeinen
Annahmen in `docs/vps/02-einrichtung-vps.md`, die von einem VPS ohne vorinstallierte Software
ausgingen; das Zielbild der Anwendung selbst (Dienste, Netze, Datenflüsse) steht unverändert in
`docs/vps/01-architektur.md`.

**Server (bestätigt):** Hostinger, Tarif KVM 8 (8 vCPU, 32 GB RAM, 400 GB NVMe), Vorlage
„Ubuntu 24.04 with Coolify“ (Coolify war bei Übernahme bereits installiert und lief). Hostname
`srv1960492.hstgr.cloud`, IPv4 `72.61.80.67`, SSH-Benutzer `root` (Passwort aus dem
Hostinger-Kundenbereich).

## Was läuft wo

| Bereich | Zuständigkeit | Bis wann/wobei |
|---|---|---|
| IONOS-Webhosting | Marketingseiten, Domainverwaltung (DNS-Zonen), E-Mail-Postfächer | dauerhaft; zusätzlich die Anwendung (app/admin/api), solange der Cutover (`docs/vps/07-cutover-checkliste.md`) noch nicht erfolgt ist |
| Hostinger-VPS | Anwendung (app/admin/api/status), Scheduler, Worker, Redis, zusätzlich Coolify selbst (Proxy, Serverübersicht und die als eigene Ressource eingerichtete MariaDB mit Sicherung) | ab erfolgreichem Test dieses Kapitels; produktiv erst nach Cutover |
| Coolify | Reverse Proxy (Traefik), Serverübersicht, private Datenbankressource MariaDB (Sicherung inklusive externem Upload) | produktiv eingerichtet, siehe Status unten; kein Autodeploy für SmartEinzug |
| GitHub | Quelle des Codes, einziger Deploymentweg für den VPS (`.github/workflows/deploy.yml`, Job `deploy-vps`) sowie unverändert der Upload zum IONOS-Webhosting (Job `deploy-webhosting`) | dauerhaft |

## Status

| Baustein | Stand |
|---|---|
| Hostinger-VPS gekauft, Coolify läuft | produktiv eingerichtet (durch den Nutzer bestätigt) |
| Coolify-MariaDB als eigene, private Datenbankressource (Version 11.8.9, Datenbank `smarteinzug`, Port 3306 nicht öffentlich, persistenter Speicher, Healthcheck erfolgreich, Lesen/Schreiben getestet) | produktiv eingerichtet (vom Betreiber bestätigt) |
| Tägliche Coolify-Sicherung der Datenbank, zusätzlicher externer Upload nach Hetzner Object Storage, Restore aus dem externen Backup erfolgreich getestet | produktiv eingerichtet (vom Betreiber bestätigt) |
| Coolify-GitHub-App für SmartEinzug angelegt | vom Betreiber angelegt; in diesem Architekturmodell nicht benötigt, siehe Schritt 8a und `docs/vps/03-github-deployment.md` |
| `deploy/vps/` (Compose, Caddyfile, `.env.example`) auf Coolify/Traefik-Proxy und Coolify-MariaDB umgestellt (kein `mariadb`-, kein `backup`-Dienst mehr im Stack) | vorbereitet (im Repository) |
| `setup-vps.sh` erkennt Coolify (Firewall wird ergänzt statt zurückgesetzt, Port 8000 gesperrt bzw. optional per `COOLIFY_UI_ALLOW_FROM` freigegeben) | vorbereitet (im Repository); Wirkung beim ersten Lauf auf dem Server zu prüfen |
| Diese Anleitung (Schritte 1 bis 13) | vorbereitet, noch nicht vollständig durchgeführt |
| Erstes Deployment über den GitHub-Workflow | offen |
| Datenbankimport von Bestandsdaten | offen |
| Produktive DNS-Umstellung, Cutover | offen, ausdrücklich noch nicht vorgenommen |

Alles, was sich nur durch tatsächliches Ausführen auf dem Server bestätigen lässt, ist in den
Schritten unten als „(auf dem Server zu prüfen)“ gekennzeichnet.

---

## 1. Coolify-Assistent abschließen

**Zweck:** Coolify ist vorinstalliert und startet beim ersten Aufruf der Weboberfläche einen
Einrichtungsassistenten. SmartEinzug benötigt Coolify ausschließlich als Proxy (Traefik auf
80/443 mit Let's Encrypt) und als Serverübersicht, keine eigene Coolify-Anwendung.

**Vorgehen:**
1. Coolify-Oberfläche im Browser öffnen (zunächst direkt über die Server-IP und Port 8000,
   solange noch keine Firewall eingerichtet ist, siehe Warnung unten) und den mitgelieferten
   Administrator-Zugang anlegen.
2. Im Schritt „Choose Server Type“ **„This machine“ (localhost)** wählen, da Coolify auf
   demselben Server läuft, den SmartEinzug später nutzt. NICHT „Remote Server“ wählen.
3. Den Assistenten danach beenden, OHNE eine Anwendung oder Applikation für SmartEinzug in Coolify
   anzulegen: Der einzige Deploymentweg bleibt der GitHub-Workflow
   (`docs/vps/03-github-deployment.md`). Die private Datenbankressource MariaDB (Schritt 5a) ist
   davon unabhängig und wird bewusst in Coolify eingerichtet. Eine Coolify-GitHub-App für dieses
   Repository wurde bereits angelegt; solange keine Application in Coolify daran gebunden ist,
   löst sie keinen Autodeploy aus (siehe Schritt 8a und `docs/vps/03-github-deployment.md`).
4. Proxy-Status in Coolify prüfen (Bereich „Servers“ > „localhost“ > „Proxy“): Der Proxy soll als
   laufend angezeigt werden.

**Erwartetes Ergebnis:** Coolify zeigt den lokalen Server als verbunden, der Proxy läuft, keine
SmartEinzug-Ressource ist in Coolify angelegt.

**Prüfkommando (auf dem Server zu prüfen):** `docker ps | grep -i coolify` zeigt laufende
Coolify-Container, darunter einen Proxy-Container (üblicherweise `coolify-proxy`).

**Mögliche Fehler:** Assistent bietet nur „Remote Server“ an, wenn versehentlich ein SSH-Schlüssel
für einen fremden Server hinterlegt wurde (Schritt abbrechen, „This machine“ erneut wählen);
Proxy-Status zeigt „gestoppt“ (in Coolify unter „Servers“ > „localhost“ > „Proxy“ manuell starten).

**Warnung zur Reihenfolge:** Solange Port 8000 noch nicht abgesichert ist (Schritt 4), ist die
Coolify-Oberfläche über die reine IP erreichbar. Diesen Schritt zügig abschließen und danach mit
Schritt 2 fortfahren, statt die Oberfläche dauerhaft offen zu lassen.

## 2. Root-Passwort ändern, eigenes SSH-Schlüsselpaar erzeugen

**Zweck:** Das von Hostinger vergebene root-Passwort ist nur ein Rückfall bis zur Schlüsselanmeldung.

**Befehle:**
```bash
ssh root@72.61.80.67
passwd
```
Anschließend auf dem eigenen Rechner (nicht auf dem Server) ein persönliches Schlüsselpaar
erzeugen:
```bash
ssh-keygen -t ed25519 -C "admin@muellerhv.de-vps" -f ~/.ssh/smarteinzug_vps_admin
```

**Erwartetes Ergebnis:** Anmeldung als root gelingt, neues Passwort gesetzt; zwei lokale Dateien
`smarteinzug_vps_admin` (privat) und `smarteinzug_vps_admin.pub` (öffentlich).

**Prüfkommando:** erneute Anmeldung mit dem neuen root-Passwort; `ls -l ~/.ssh/smarteinzug_vps_admin*`.

**Mögliche Fehler:** Login über die Hostinger-Weboberfläche statt SSH nötig, falls das
ursprüngliche Passwort nicht mehr vorliegt (Hostinger-Kundenbereich, Passwort zurücksetzen); Datei
existiert bereits (anderen Dateinamen wählen, keinen bestehenden Schlüssel überschreiben).

## 3. setup-vps.sh auf den Server bringen und ausführen

**Zweck:** Benutzer `deploy` mit dem übertragenen Schlüssel anlegen, Docker installieren (nur
falls es fehlt), Firewall (`ufw`) und `fail2ban` einrichten, Verzeichnisstruktur
`/opt/smarteinzug` anlegen.

**Befehle:**
```bash
scp ~/.ssh/smarteinzug_vps_admin.pub root@72.61.80.67:/root/admin_key.pub
scp -r deploy/vps root@72.61.80.67:/root/deploy-vps
ssh root@72.61.80.67
cd /root/deploy-vps/scripts
sudo bash setup-vps.sh /root/admin_key.pub
```

**Erwartetes Ergebnis:** Ausgabe der neun Schritte des Skripts (Systemaktualisierung,
Grundwerkzeuge, Benutzer `deploy`, Docker, `ufw`, `fail2ban`, `unattended-upgrades`,
Verzeichnisstruktur, Hinweis zur SSH-Härtung). `setup-vps.sh` erkennt eine bereits laufende
Coolify-Installation (Container mit Namen `coolify*`) und verhält sich dann abweichend vom
Verhalten auf einem Server ohne Coolify:

- Docker wird nur installiert, wenn `docker --version` vorher fehlschlägt; auf der Hostinger-Vorlage
  „Ubuntu 24.04 with Coolify“ ist Docker bereits vorhanden, das Skript installiert hier nichts neu.
- Der Firewall-Schritt setzt `ufw` NICHT zurück, wenn `ufw` bereits aktiv ist (erkannt an
  `ufw status` = „Status: active“), sondern ergänzt nur `allow 22/tcp`, `allow 80/tcp`,
  `allow 443/tcp`. Port 8000 (Coolify-Oberfläche) wird dabei standardmäßig ausdrücklich nach außen
  gesperrt (`ufw deny 8000/tcp`); wird die Umgebungsvariable `COOLIFY_UI_ALLOW_FROM=<eigene IP>`
  beim Aufruf gesetzt, gibt das Skript Port 8000 stattdessen nur für diese eine Adresse frei. Für
  diese Anleitung wird KEINE dieser beiden Varianten vorausgesetzt: Der Zugriff erfolgt in Schritt
  4 über einen SSH-Tunnel, unabhängig davon, ob Port 8000 zusätzlich per
  `COOLIFY_UI_ALLOW_FROM` freigegeben wurde.

**Prüfkommando:** `id deploy`, `docker --version`, `ls /opt/smarteinzug` (Ordner `releases`,
`shared`, `deploy`, `logs`, `backups` vorhanden).

**Mögliche Fehler:** „Datei sieht nicht wie ein öffentlicher SSH-Schlüssel aus“ (falsche Datei
übergeben, `.pub`-Datei prüfen); Skript bricht bei fehlenden Paketquellen ab (Internetzugang des
Servers prüfen).

## 4. Zugang als deploy testen, sshd härten, Coolify-Oberfläche per SSH-Tunnel öffnen

**Zweck:** Sicherstellen, dass die Anmeldung als `deploy` funktioniert, bevor root-Anmeldung und
Passwort-Anmeldung abgeschaltet werden, und die Coolify-Oberfläche fortan ausschließlich über
einen SSH-Tunnel statt direkt über die IP erreichen.

**Befehle:**
```bash
# Von einem ZWEITEN Terminal aus testen, die root-Sitzung offen lassen:
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@72.61.80.67
```
Erst danach in der root-Sitzung die Bestätigungsfrage von `setup-vps.sh` mit „ja“ beantworten
(härtet `sshd`: `PasswordAuthentication no`, `PermitRootLogin no`).

Coolify-Oberfläche danach ausschließlich per Tunnel öffnen, nicht mehr direkt über die IP:
```bash
ssh -L 8000:127.0.0.1:8000 deploy@72.61.80.67
```
Anschließend im eigenen Browser `http://127.0.0.1:8000` aufrufen.

**Erwartetes Ergebnis:** Anmeldung als `deploy` gelingt ohne Passwort; root-Anmeldung und
Passwort-Anmeldung sind danach gesperrt; die Coolify-Oberfläche öffnet sich über den Tunnel exakt
wie vorher direkt über Port 8000.

**Prüfkommando:** `ssh root@72.61.80.67` (muss abgelehnt werden); `ssh -i
~/.ssh/smarteinzug_vps_admin deploy@72.61.80.67` (muss gelingen); Tunnel-Aufruf öffnet die
Coolify-Anmeldeseite.

**Mögliche Fehler:** Wird die Härtung bestätigt, bevor der Zugang als `deploy` erfolgreich
getestet wurde, droht eine vollständige Aussperrung (nur über den Hostinger-Kundenbereich
behebbar, dort Neuinstallation oder ein Rettungssystem/VNC-Zugriff, aufwendig). Tunnel öffnet sich
nicht: lokaler Port 8000 bereits belegt (anderen lokalen Port wählen, z. B. `-L 18000:127.0.0.1:8000`
und `http://127.0.0.1:18000` aufrufen).

**Alternative statt Tunnel (nicht Teil dieser Anleitung, nur als Hinweis):** Wird
`setup-vps.sh` mit gesetzter Umgebungsvariable `COOLIFY_UI_ALLOW_FROM=<eigene feste IP>`
aufgerufen (Schritt 3), gibt das Skript Port 8000 zusätzlich für genau diese Adresse frei
(`ufw allow from <IP> to any port 8000 proto tcp`) und die Oberfläche wäre direkt über
`http://72.61.80.67:8000` erreichbar. Diese Anleitung setzt das NICHT voraus und verwendet
durchgängig den SSH-Tunnel, der unabhängig von einer festen eigenen IP funktioniert; ohne
`COOLIFY_UI_ALLOW_FROM` sperrt das Skript Port 8000 ausdrücklich nach außen
(`ufw deny 8000/tcp`), der Tunnel bleibt dann der einzige Zugriffsweg.

## 5. Coolify-Proxy prüfen

**Zweck:** Bestätigen, welches Docker-Netz der Coolify-Proxy tatsächlich verwendet und mit
welchen Entrypoint-/Certresolver-Namen, damit die Traefik-Labels in
`deploy/vps/docker-compose.yml` dazu passen.

**Befehle (auf dem Server, als `deploy`):**
```bash
docker network ls
docker ps | grep -i coolify
cat /data/coolify/proxy/docker-compose.yml
```

**Erwartetes Ergebnis (auf dem Server zu prüfen, hier nicht unterstellt):** Ein Docker-Netz mit dem
Namen `coolify` (Standardname, in `.env` als `COOLIFY_NETWORK` zu hinterlegen, falls abweichend); ein
laufender Proxy-Container (üblicherweise `coolify-proxy`); in
`/data/coolify/proxy/docker-compose.yml` die Entrypoint-Namen `http`/`https` und der
Certresolver-Name `letsencrypt`, die in `deploy/vps/docker-compose.yml` als Traefik-Labels bereits
hinterlegt sind (`traefik.http.routers.smarteinzug-https.entrypoints: https`,
`tls.certresolver: letsencrypt`).

**Prüfkommando:** siehe Befehle oben; bei abweichenden Namen `COOLIFY_NETWORK` in `.env` anpassen
bzw. die Labels in `deploy/vps/docker-compose.yml` mit der Entwicklung abstimmen (Abweichung an
dieser Stelle ist eine Code-Änderung, keine reine Konfiguration).

**Mögliche Fehler:** Datei `/data/coolify/proxy/docker-compose.yml` fehlt oder liegt an anderer
Stelle (Coolify-Version geprüft werden, Pfad kann sich zwischen Versionen unterscheiden, auf dem
Server zu prüfen); Netzname weicht von `coolify` ab (in `.env` unter `COOLIFY_NETWORK` hinterlegen).

## 5a. Coolify-MariaDB und Netz prüfen

**Zweck:** Bestätigen, dass die bereits als eigene, private Coolify-Datenbankressource eingerichtete
MariaDB (Version 11.8.9, Datenbank `smarteinzug`) im selben Docker-Netz liegt wie der Coolify-Proxy,
keinen öffentlichen Port hat und für die Anwendungscontainer unter ihrem Containernamen erreichbar
ist.

**Befehle (auf dem Server, als `deploy`):**
```bash
docker ps
docker inspect <containername-der-coolify-mariadb> \
  --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
```
Den Containernamen liefert entweder die Ausgabe von `docker ps` (Spalte NAMES, üblicherweise mit
einem an die Datenbankressource erinnernden Namen) oder Coolify selbst: bei der Datenbankressource
steht die interne Verbindungsadresse in der Form
`mysql://benutzer:passwort@<containername>:3306/smarteinzug`, deren mittlerer Teil der
Containername ist.

**Erwartetes Ergebnis:** `docker inspect` zeigt unter den Netzen des Containers das Netz `coolify`
(oder den in `COOLIFY_NETWORK` hinterlegten Namen, siehe Schritt 5); in Coolify ist bei der
Datenbankressource kein „Public Port“ gesetzt.

**Prüfkommando:** Von außen muss die Datenbank unerreichbar sein: `nc -zv 72.61.80.67 3306` muss
fehlschlagen. Testverbindung aus einem kurzlebigen Client-Container im Netz `coolify` (kein
veröffentlichter Port nötig):
```bash
docker run --rm -it --network coolify mariadb:11 \
  mariadb -h <containername-der-coolify-mariadb> -u <benutzer> -p smarteinzug -e "SELECT 1"
```

**Mögliche Fehler:** Die Datenbank liegt in einem anderen Docker-Netz als der Coolify-Proxy (Ausgabe
von `docker inspect` zeigt ein abweichendes Netz); dann entweder `COOLIFY_NETWORK` in `.env` auf
dieses Netz setzen oder die Datenbankressource in Coolify im Standardziel (Server localhost, Netz
`coolify`) neu anlegen, da Proxy und Datenbank im selben Netz liegen müssen. Coolify bietet je
Ressource eine Zieleinstellung („Destination“), die das verwendete Docker-Netz bestimmt (auf dem
Server zu prüfen). Ein manuelles `docker network connect` wird NICHT empfohlen: Es wirkt nicht
dauerhaft, weil Coolify den Container jederzeit neu erzeugen kann und die manuelle Zuordnung dabei
verloren geht.

**Hinweis:** Die Anwendungscontainer (`caddy`, `php`, `scheduler`, alle Worker, `metrics`) hängen
deshalb am Netz `coolify` und haben darüber zugleich den Weg ins Internet (Lexware Office, Stripe,
Mail); das interne Netz `smarteinzug_internal` hat keine Außenverbindung.

## 6. shared/config.php anlegen

**Zweck:** `app/config.php` liegt außerhalb jedes Release unter
`/opt/smarteinzug/shared/config.php` und wird nie durch ein Deployment überschrieben.

**Befehle:**
```bash
scp php-ionos/app/config.example.php deploy@72.61.80.67:/opt/smarteinzug/shared/config.php
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@72.61.80.67
nano /opt/smarteinzug/shared/config.php
```

Auszufüllen, mit Besonderheiten gegenüber der Vorlage:

- `trusted_proxies`: NICHT leer lassen (die Vorlage geht von Caddy als direktem, einstufigem
  Proxy aus). Auf dem Hostinger-VPS steht Traefik (Coolify-Proxy) vor Caddy; Docker-Netzbereiche
  eintragen, z. B. `['172.16.0.0/12', '10.0.0.0/8']`, tatsächliche Bereiche mit
  `docker network inspect <netzname>` gegenprüfen (auf dem Server zu prüfen). Siehe
  `docs/vps/01-architektur.md`, Abschnitt „Proxykette“.
- `storage_dir`: `/opt/smarteinzug/shared/storage`.
- `db.host`: Containername der bereits als eigene, private Coolify-Datenbankressource
  eingerichteten MariaDB (nicht die IP des VPS, kein fester Dienstname). Der Containername steht in
  Coolify bei der Datenbankressource in der internen Verbindungsadresse
  (`mysql://benutzer:passwort@<containername>:3306/smarteinzug`) und ist auf dem Server mit
  `docker ps` sichtbar (siehe Schritt 5a). `db.port`: `3306`, `db.name`: `smarteinzug`, `db.user`
  und `db.pass`: wie in Coolify bei der Datenbankressource hinterlegt.
- `redis`: `['host' => 'redis', 'port' => 6379, 'password' => null, 'prefix' => 'se:']` (Dienstname
  im Docker-Netz).
- `migration_token`, `cron_token`: neu erzeugen (`openssl rand -hex 32`), auf dem VPS eigene,
  vom Webhosting unabhängige Werte verwenden; `migration_token` fließt zusätzlich in das
  GitHub-Secret bzw. wird beim VPS-Deployment gar nicht per HTTP aufgerufen (Migrationen laufen
  dort über `bin/migrate.php` in `deploy.sh`, siehe `docs/vps/03-github-deployment.md`).
- `app_secret`: AUSDRÜCKLICH NICHT neu erzeugen. Denselben Wert aus der produktiven
  Webhosting-Konfiguration übernehmen, sonst sind bereits verschlüsselte Zugangsdaten (Lexware-
  und Stripe-API-Keys, 2FA-Geheimnisse) nach einem späteren Umzug der Firmendaten nicht mehr
  entschlüsselbar.

**Erwartetes Ergebnis:** Vollständig ausgefüllte Konfigurationsdatei ohne Platzhalter aus der
Vorlage, `trusted_proxies` gefüllt.

**Prüfkommando:** `php -l /opt/smarteinzug/shared/config.php` (lokal mit installiertem PHP oder
später über den `php`-Container).

**Mögliche Fehler:** `app_secret` versehentlich neu erzeugt statt übernommen (macht bestehende
verschlüsselte Daten nach dem Datenumzug unlesbar, vor dem produktiven Einzug unbedingt
gegenprüfen); `trusted_proxies` leer gelassen (X-Forwarded-Proto/-For werden dann nicht ausgewertet,
die Anwendung hält jede Anfrage für HTTP statt HTTPS).

## 7. deploy/.env anlegen

**Zweck:** Umgebungsvariablen für `docker-compose.yml` (Domains, Docker-Netz des Coolify-Proxys,
UID/GID, Umgebung). Die Datenbankzugangsdaten stehen NICHT in dieser Datei, sondern ausschließlich
in `/opt/smarteinzug/shared/config.php` (Schritt 6).

**Befehle:**
```bash
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@72.61.80.67
mkdir -p /opt/smarteinzug/deploy
cd /opt/smarteinzug/deploy
cp /root/deploy-vps/.env.example .env
id deploy
nano .env
```

Mindestens auszufüllen (vollständige Liste: `deploy/vps/.env.example`): `TZ`, `DOMAIN_APP`,
`DOMAIN_ADMIN`, `DOMAIN_API`, `DOMAIN_STATUS`, `COOLIFY_NETWORK` (Wert aus Schritt 5, Standard
`coolify`), `DB_CONTAINER` (Containername der Coolify-MariaDB aus Schritt 5a; wird nur von
`scripts/db-import.sh` und für Wiederherstellungstests verwendet), `COOLIFY_BACKUP_DIR` (Hostpfad
der lokalen Kopien der Coolify-Datenbanksicherungen, Standard `/data/coolify/backups`, auf dem
Server zu prüfen), `DEPLOY_ENV=prod`, `HEALTH_STRICT=false` (bis zum Cutover, siehe
`docs/vps/07-cutover-checkliste.md`), `APP_UID` und `APP_GID` (Ausgabe von `id deploy`),
`PM_MAX_CHILDREN`, `WORKER_MEMORY_MB`.

**Erwartetes Ergebnis:** `.env` ohne verbliebene Platzhalter, `COOLIFY_NETWORK` entspricht dem in
Schritt 5 geprüften tatsächlichen Netznamen, `DB_CONTAINER` entspricht dem in Schritt 5a
ermittelten Containernamen.

**Prüfkommando:** `grep -c HIER .env` liefert `0`.

**Mögliche Fehler:** `COOLIFY_NETWORK` weicht vom tatsächlichen Namen ab (Caddy startet, Traefik
findet den Container aber nicht, keine Zertifikatsausstellung möglich, und die PHP-Container
erreichen die Coolify-MariaDB nicht); `APP_UID`/`APP_GID` weichen vom Benutzer `deploy` ab
(`app/storage` dann nicht beschreibbar, „Permission denied“ in den PHP-Logs); `DB_CONTAINER` falsch
oder nicht gesetzt (`scripts/db-import.sh` bricht mit einer entsprechenden Fehlermeldung ab).

## 8. GitHub-Secrets und -Variablen für Hostinger setzen

**Zweck:** Ab jetzt automatisch statt manuell deployen. Vollständige Anleitung mit allen Feldern:
`docs/vps/03-github-deployment.md`.

**Werte für den beschafften Server:**

| Secret/Variable | Wert |
|---|---|
| `VPS_HOST` | `72.61.80.67` |
| `VPS_SSH_USER` | `deploy` |
| `VPS_SSH_PORT` | `22` |
| `VPS_SSH_PRIVATE_KEY` | eigener, NEUER Schlüssel nur für GitHub Actions (nicht der persönliche Administratorschlüssel aus Schritt 2), siehe `docs/vps/03-github-deployment.md` |
| `VPS_SSH_KNOWN_HOSTS` | Ausgabe von `ssh-keyscan -t ed25519 72.61.80.67`, ausgeführt aus der Hostinger-Webkonsole oder einer bereits vertrauenswürdigen Sitzung (KEINE Fingerabdrücke des früher vorgesehenen IONOS-VPS übernehmen, dieser wurde nie beschafft) |
| `VPS_DEPLOY_ENABLED` | zunächst `false`, erst nach erfolgreichem Test in Schritt 9 auf `true` |

**Erwartetes Ergebnis:** Alle Felder aus `docs/vps/03-github-deployment.md` gesetzt.

**Prüfkommando:** GitHub-Repository > Settings > Secrets and variables > Actions, Liste der
angelegten Einträge.

**Mögliche Fehler:** siehe `docs/vps/03-github-deployment.md`, Abschnitt Fehlerbilder.

## 8a. Coolify-GitHub-App: nach erfolgreicher Einrichtung entfernen

**Zweck:** Der Betreiber hat bereits eine Coolify-GitHub-App für dieses Repository angelegt. In der
hier beschriebenen Architektur wird sie nicht benötigt (kein Coolify-Autodeploy, keine
Coolify-Application für SmartEinzug), siehe `docs/vps/03-github-deployment.md`.

**Vorgehen:** Solange keine Application in Coolify an die GitHub-App gebunden ist, löst sie keinen
Autodeploy aus und kann bis zum Nachweis des funktionierenden SSH-Deploymentwegs (Schritt 9)
unverändert bestehen bleiben. Nach erfolgreichem Test des SSH-Deployments kann die App entfernt
werden: in Coolify (Bereich „Sources“) und in GitHub (Settings > Applications bzw. Installed GitHub
Apps).

**Erwartetes Ergebnis:** Genau ein Deploymentweg für den VPS bleibt bestehen (GitHub-Workflow per
SSH).

**Prüfkommando (auf dem Server bzw. in Coolify/GitHub zu prüfen):** Coolify, Bereich „Sources“,
zeigt keine SmartEinzug-Application, die an die GitHub-App gebunden ist.

**Mögliche Fehler:** Eine Application wird versehentlich in Coolify an die GitHub-App gebunden
(Coolify-Autodeploy würde dann parallel zum GitHub-Workflow eingreifen); in diesem Fall die Bindung
sofort wieder entfernen.

## 9. Erstes Deployment per workflow_dispatch

**Zweck:** Ersten automatischen Durchlauf beobachten, bevor DNS umgestellt wird.

**Vorgehen:**
1. `VPS_DEPLOY_ENABLED` auf `true` setzen.
2. GitHub Actions > Workflow „Deployment IONOS-Webhosting und VPS“ > „Run workflow“
   (`workflow_dispatch`) auf dem gewünschten Branch auslösen.
3. Ablauf beobachten: Job „changes“, Job „test“ (PHP-Lint, ggf. Website-QA, Dokumentation), Job
   „deploy-vps“ (rsync von `php-ionos/`, `deploy/vps/` und der Statusseite, Ausführung von
   `deploy/vps/scripts/deploy.sh <git-sha>` aus dem neuen Release auf dem Server, Health-Check).

**Erwartetes Ergebnis:** Job „deploy-vps“ läuft durch bis zum Health-Check; bei
`VPS_HEALTH_STRICT=false` (empfohlen, solange DNS noch nicht umgestellt ist) endet ein
fehlgeschlagener externer Health-Check nur mit einer Warnung, nicht mit einem Abbruch.

**Prüfkommando:** GitHub Actions, Log des Jobs „deploy-vps“.

**Mögliche Fehler:** siehe `docs/vps/03-github-deployment.md`, Abschnitt Fehlerbilder.

## 10. Auf dem Server prüfen

**Zweck:** Bestätigen, dass Container laufen, das Release aktiv ist und die Anwendung antwortet,
BEVOR DNS umgestellt wird.

**Befehle:**
```bash
cd /opt/smarteinzug/deploy
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all
readlink -f /opt/smarteinzug/releases/current
```

Externer Health-Check vor der DNS-Umstellung (Zertifikat existiert noch nicht, deshalb schlägt der
naheliegende Aufruf über HTTPS erwartungsgemäß fehl):
```bash
curl --resolve app.smart-einzug.de:443:127.0.0.1 https://app.smart-einzug.de/health.php
```
Alternative, die direkt gegen den Coolify-Proxy testet, ohne TLS vorauszusetzen:
```bash
curl -H "Host: app.smart-einzug.de" http://127.0.0.1/health.php
```

**Erwartetes Ergebnis:** Alle Dienste des SmartEinzug-Stacks `running`, `php` zusätzlich `healthy`
(kein Dienst `mariadb` in diesem Stack: Die Datenbank ist die bereits eingerichtete
Coolify-Ressource, in Coolify selbst als `healthy` angezeigt), `healthcheck.php --all` liefert
Exit-Code 0, `readlink -f .../current` zeigt auf den soeben deployten Git-SHA. Der erste
`curl`-Befehl (HTTPS, `--resolve`) scheitert an einem fehlenden oder ungültigen Zertifikat, solange
DNS noch nicht auf den VPS zeigt; DAS IST ERWARTBAR und kein Fehler dieses Schritts. Der zweite
`curl`-Befehl (reines HTTP gegen `127.0.0.1`, Host-Header gesetzt) liefert `"php": true`, sofern der
Coolify-Proxy Anfragen auf Port 80 lokal entgegennimmt; schlägt dieser Weg ebenfalls fehl, ist eher
die Traefik-Konfiguration selbst zu prüfen (Schritt 5), nicht die Anwendung.

**Prüfkommando:** siehe Befehle oben.

**Mögliche Fehler:** `php` bleibt `unhealthy` oder startet nicht, weil `config.php` fehlt, fehlerhaft
ist, oder die Verbindung zur Coolify-MariaDB nicht steht (falscher Containername, falsches Netz,
falsche Zugangsdaten; siehe Schritt 5a und 6, `docker compose logs php`); `readlink` zeigt auf ein
älteres Release (Deployment im Job „deploy-vps“ genauer prüfen, siehe
`docs/vps/03-github-deployment.md`).

## 11. Datenbank: Schema und Bestandsdaten

**Zweck:** Datenbankschema ist bereits während des Deployments eingespielt worden
(`deploy/vps/scripts/deploy.sh` ruft `bin/migrate.php` mit dem neuen Code auf); für tatsächliche
Bestandsdaten (bestehende Firmen vom IONOS-Webhosting) ist zusätzlich ein Datenimport nötig.

**Vorgehen:**
- Migrationsstand prüfen: `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec
  php php bin/migrate.php --status` zeigt ausschließlich `success`.
- Bestandsdaten übernehmen: vollständiges Runbook `docs/vps/04-datenbankmigration.md`
  (`scripts/db-import.sh`, Prüfsumme, anschließend `scripts/db-verify.php` für den Abgleich
  Alt/Neu). Dieser Schritt ist unabhängig von Schritt 9/10 und erst zum eigentlichen Cutover
  nötig, nicht bereits beim ersten Test-Deployment. Kein produktiver Import im Rahmen dieser
  Ersteinrichtung.
- `scripts/db-import.sh` spielt den Dump per `docker exec -i` direkt in den Container der
  Coolify-MariaDB ein (Containername aus `DB_CONTAINER` in `.env`, siehe Schritt 7), nicht über
  `docker compose exec`, weil die Datenbank kein Dienst dieses Compose-Projekts ist; ein
  veröffentlichter Port ist dafür nicht nötig.
- Sicherung der Datenbank: übernimmt ausschließlich Coolify (tägliche Sicherung, externer Upload
  nach Hetzner Object Storage, Restore laut Betreiber getestet). Der Metrik-Sammler liest nur die
  lokale Kopie (`COOLIFY_BACKUP_DIR`) und meldet sie als Komponente „Sicherungen“ im Adminbereich
  System, Reiter Server (siehe `docs/vps/06-betrieb.md`).

**Erwartetes Ergebnis:** `bin/migrate.php --status` ausschließlich `success`; nach einem
Bestandsimport identische Zeilenzahlen und Prüfsummen zwischen Webhosting und VPS
(`docs/vps/04-datenbankmigration.md`, Schritte 5/6).

**Prüfkommando:** siehe `docs/vps/04-datenbankmigration.md`.

**Mögliche Fehler:** siehe dort.

## 12. Anmeldung testen ohne DNS zu ändern

**Zweck:** Den kompletten Anmeldeweg einmal durchspielen, bevor DNS umgestellt wird, ohne die
produktiven DNS-Einträge zu berühren.

**Vorgehen:** Auf dem eigenen Rechner (nicht auf dem Server) einen Eintrag in der lokalen
hosts-Datei ergänzen, der ausschließlich für diesen einen Rechner gilt:
```
72.61.80.67 app.smart-einzug.de
```
(unter Linux/macOS `/etc/hosts`, unter Windows
`C:\Windows\System32\drivers\etc\hosts`, Administratorrechte nötig). Anschließend
`https://app.smart-einzug.de` im Browser aufrufen und Registrierung/Anmeldung/2FA testen.

**Erwartetes Ergebnis:** Die Anwendung ist erreichbar wie nach einer echten DNS-Umstellung, nur
für diesen einen Rechner. Der Browser zeigt eine Zertifikatswarnung, solange kein gültiges
Let's-Encrypt-Zertifikat für `app.smart-einzug.de` vorliegt (das Zertifikat kann erst nach der
echten DNS-Umstellung erfolgreich bezogen werden, siehe `docs/vps/05-dns-ssl.md`); diese Warnung
ist an dieser Stelle erwartbar und kein Fehler.

**Prüfkommando:** vollständiger Anmeldedurchlauf ohne Anwendungsfehler; Eintrag in `audit_log` für
eine Testregistrierung.

**Mögliche Fehler:** hosts-Datei-Eintrag wirkt nicht sofort (lokalen DNS-Cache leeren, Browser
neu starten); Zertifikatswarnung lässt sich nicht wegklicken (in den meisten Browsern über
„Erweitert“/„Trotzdem fortfahren“ möglich, nur für diesen bewussten Test vertretbar, niemals bei
echten Kundenzugriffen); hosts-Datei-Eintrag nach dem Test wieder entfernen, damit der eigene
Rechner nach der echten DNS-Umstellung nicht weiter auf eine feste Adresse zeigt.

## 13. Weiteres Vorgehen

Nach erfolgreichem Abschluss der Schritte 1 bis 12 (vorbereitet und in der eigenen Testumgebung
geprüft, siehe Tabelle „Status“ oben, noch NICHT produktiv):

- DNS-Umstellung und TLS-Zertifikate über den Coolify-Proxy: `docs/vps/05-dns-ssl.md`.
- Vollständiger, phasenweiser Produktions-Cutover mit Prüfpunkten:
  `docs/vps/07-cutover-checkliste.md`.

Eine offene, noch nicht entschiedene Frage (keine Bestellung, keine Zusatzkosten zwingend nötig):
Für eine vom Hostinger-VPS unabhängige Statusseite müsste `status.smart-einzug.de` beim
IONOS-Webhosting statt auf dem VPS liegen; das ist ohne Zusatzkosten möglich, aber eine
Entscheidung des Betreibers, die hier nicht vorweggenommen wird.
