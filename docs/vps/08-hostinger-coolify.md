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
| Hostinger-VPS | Anwendung (app/admin/api/status), MariaDB, Redis, Scheduler, Worker, Backup, zusätzlich Coolify selbst (Proxy und Serverübersicht) | ab erfolgreichem Test dieses Kapitels; produktiv erst nach Cutover |
| GitHub | Quelle des Codes, einziger Deploymentweg für den VPS (`.github/workflows/deploy.yml`, Job `deploy-vps`) sowie unverändert der Upload zum IONOS-Webhosting (Job `deploy-webhosting`) | dauerhaft |

## Status

| Baustein | Stand |
|---|---|
| Hostinger-VPS gekauft, Coolify läuft | produktiv eingerichtet (durch den Nutzer bestätigt) |
| `deploy/vps/` (Compose, Caddyfile, `.env.example`) auf Coolify/Traefik umgestellt | vorbereitet (im Repository) |
| `setup-vps.sh` erkennt Coolify (Firewall wird ergänzt statt zurückgesetzt, Port 8000 gesperrt bzw. optional per `COOLIFY_UI_ALLOW_FROM` freigegeben) | vorbereitet (im Repository); Wirkung beim ersten Lauf auf dem Server zu prüfen |
| Diese Anleitung (Schritte 1 bis 13) | vorbereitet, noch nicht durchgeführt |
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
3. Den Assistenten danach beenden, OHNE eine Anwendung oder Ressource für SmartEinzug anzulegen.
   Kein Projekt, keine Applikation, keine verbundene GitHub-App für dieses Repository in Coolify
   einrichten: Der einzige Deploymentweg bleibt der GitHub-Workflow
   (`docs/vps/03-github-deployment.md`).
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
Namen `coolify` (Standardname, in `.env` als `PROXY_NETWORK` zu hinterlegen, falls abweichend); ein
laufender Proxy-Container (üblicherweise `coolify-proxy`); in
`/data/coolify/proxy/docker-compose.yml` die Entrypoint-Namen `http`/`https` und der
Certresolver-Name `letsencrypt`, die in `deploy/vps/docker-compose.yml` als Traefik-Labels bereits
hinterlegt sind (`traefik.http.routers.smarteinzug-https.entrypoints: https`,
`tls.certresolver: letsencrypt`).

**Prüfkommando:** siehe Befehle oben; bei abweichenden Namen `PROXY_NETWORK` in `.env` anpassen
bzw. die Labels in `deploy/vps/docker-compose.yml` mit der Entwicklung abstimmen (Abweichung an
dieser Stelle ist eine Code-Änderung, keine reine Konfiguration).

**Mögliche Fehler:** Datei `/data/coolify/proxy/docker-compose.yml` fehlt oder liegt an anderer
Stelle (Coolify-Version geprüft werden, Pfad kann sich zwischen Versionen unterscheiden, auf dem
Server zu prüfen); Netzname weicht von `coolify` ab (in `.env` unter `PROXY_NETWORK` hinterlegen).

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
- `db.host`: `mariadb` (Dienstname im Docker-Netz, nicht die IP des VPS).
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

**Zweck:** Umgebungsvariablen für `docker-compose.yml` (Datenbank, Domains, Docker-Netz des
Coolify-Proxys, UID/GID, Umgebung).

**Befehle:**
```bash
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@72.61.80.67
mkdir -p /opt/smarteinzug/deploy
cd /opt/smarteinzug/deploy
cp /root/deploy-vps/.env.example .env
id deploy
nano .env
```

Mindestens auszufüllen (vollständige Liste: `deploy/vps/.env.example`): `DB_NAME`, `DB_USER`,
`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `TZ`, `DOMAIN_APP`, `DOMAIN_ADMIN`, `DOMAIN_API`,
`DOMAIN_STATUS`, `PROXY_NETWORK` (Wert aus Schritt 5, Standard `coolify`), `DEPLOY_ENV=prod`,
`HEALTH_STRICT=false` (bis zum Cutover, siehe `docs/vps/07-cutover-checkliste.md`), `APP_UID` und
`APP_GID` (Ausgabe von `id deploy`), `PM_MAX_CHILDREN`, `WORKER_MEMORY_MB`, `BACKUP_REMOTE`,
`BACKUP_AGE_RECIPIENT`, `BACKUP_RETENTION_DAYS`.

**Erwartetes Ergebnis:** `.env` ohne verbliebene Platzhalter, `PROXY_NETWORK` entspricht dem in
Schritt 5 geprüften tatsächlichen Netznamen.

**Prüfkommando:** `grep -c HIER .env` liefert `0`.

**Mögliche Fehler:** `PROXY_NETWORK` weicht vom tatsächlichen Namen ab (Caddy startet, Traefik
findet den Container aber nicht, keine Zertifikatsausstellung möglich); `APP_UID`/`APP_GID`
weichen vom Benutzer `deploy` ab (`app/storage` dann nicht beschreibbar, „Permission denied“ in
den PHP-Logs).

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

**Erwartetes Ergebnis:** Alle Dienste `running` (MariaDB und PHP zusätzlich `healthy`),
`healthcheck.php --all` liefert Exit-Code 0, `readlink -f .../current` zeigt auf den soeben
deployten Git-SHA. Der erste `curl`-Befehl (HTTPS, `--resolve`) scheitert an einem fehlenden oder
ungültigen Zertifikat, solange DNS noch nicht auf den VPS zeigt; DAS IST ERWARTBAR und kein Fehler
dieses Schritts. Der zweite `curl`-Befehl (reines HTTP gegen `127.0.0.1`, Host-Header gesetzt)
liefert `"php": true`, sofern der Coolify-Proxy Anfragen auf Port 80 lokal entgegennimmt; schlägt
dieser Weg ebenfalls fehl, ist eher die Traefik-Konfiguration selbst zu prüfen (Schritt 5), nicht
die Anwendung.

**Prüfkommando:** siehe Befehle oben.

**Mögliche Fehler:** `mariadb` bleibt `starting` (Datenverzeichnis wird beim ersten Start
initialisiert, einige Minuten abwarten); `php` startet nicht, weil `config.php` fehlt oder
fehlerhaft ist (`docker compose logs php`); `readlink` zeigt auf ein älteres Release (Deployment
im Job „deploy-vps“ genauer prüfen, siehe `docs/vps/03-github-deployment.md`).

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
  nötig, nicht bereits beim ersten Test-Deployment.

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
