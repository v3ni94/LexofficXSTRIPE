# GitHub-Deployment: Secrets, Variablen, Einrichtung, Test, Rollback

Stand: 07.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`, zuletzt ausfallsicheres VPS-Deployment, Version 4.5). Bezieht sich
auf `.github/workflows/deploy.yml`, Job `deploy-vps` (der bestehende Job `deploy-webhosting` ist
unverändert, siehe `docs/migrations.md`).

**Einziger Deploymentweg für den VPS:** Dieser GitHub-Workflow (SSH und rsync nach
`/opt/smarteinzug/releases/<git-sha>/`, danach serverseitig `deploy/vps/scripts/deploy-runner.sh
<git-sha>`, der wiederum `deploy.sh` aus dem neuen Release ausführt, siehe Abschnitt „Ablauf des
Jobs deploy-vps“ unten) bleibt der einzige Weg, mit dem Code auf den Hostinger-VPS gelangt. Auf dem VPS ist
zusätzlich Coolify installiert; dort wird für SmartEinzug ausdrücklich KEINE Anwendung/Ressource
angelegt und KEIN Coolify-Autodeploy eingerichtet. Ein Ziel, ein Deploymentweg: Coolify dient
ausschließlich als Proxy (siehe `docs/vps/01-architektur.md`) und als Serverübersicht (zusätzlich
als eigenständige Datenbankressource, siehe `docs/vps/08-hostinger-coolify.md`), nicht als zweiter
Auslöser für Deployments.

### Coolify-GitHub-App: in diesem Modell nicht benötigt

Der Betreiber hat für SmartEinzug bereits eine Coolify-GitHub-App angelegt. In der hier
beschriebenen Architektur (kein Coolify-Autodeploy, keine Coolify-Application für SmartEinzug) wird
diese App NICHT benötigt. Bis zur erfolgreichen Einrichtung des vorliegenden SSH-Deploymentwegs darf
sie keinen Autodeploy auslösen: keine Application in Coolify an sie binden. Sobald das
SSH-Deployment nachweislich funktioniert (siehe Abschnitt „Deployment testen“ unten), kann die
GitHub-App wieder entfernt werden, sowohl in Coolify (Bereich „Sources“) als auch in GitHub
(Settings > Applications bzw. Installed GitHub Apps), da sie in diesem Modell keine Funktion trägt.

## Unabhängigkeit von Webhosting- und VPS-Deployment

Der Job `deploy-vps` hängt über `needs` ausschließlich von `changes` und `test` ab, nicht von
`deploy-webhosting`; ein Fehler des Webhosting-Jobs blockiert einen gültigen VPS-Deploy also nicht.
Beide Jobs laufen zudem in getrennten Nebenläufigkeitsgruppen (`production-sftp` für
`deploy-webhosting`, `production-vps` für `deploy-vps`), sodass ein wartender oder fehlgeschlagener
Lauf des einen Jobs den anderen nicht verzögert. `deploy-vps` läuft weiterhin nur, wenn die
Variable `VPS_DEPLOY_ENABLED` auf `true` steht.

## Ablauf des Jobs deploy-vps: zwei Schritte statt eines langen SSH-Kanals

Nach der Übertragung von Anwendung, Deploy-Skripten und Statusseite per rsync besteht das
eigentliche Deployment aus zwei Schritten, nicht mehr aus einem einzigen, über die gesamte
Deploymentdauer offenen SSH-Aufruf von `deploy.sh`:

1. **„Deployment auf dem VPS auslösen“** (Timeout 2 Minuten): ruft
   `deploy/vps/scripts/deploy-runner.sh <git-sha>` auf. Das Skript entkoppelt das eigentliche
   Deployment serverseitig per `setsid` von dieser SSH-Sitzung und kehrt innerhalb weniger Sekunden
   zurück. Ausgabe „TRIGGERED“ (Exit-Code 0): dieser Lauf ist für den ausgelösten Git-SHA
   zuständig. Ausgabe „REJECTED“ (Exit-Code 3): es lief bereits ein Deployment oder Rollback, dieser
   Lauf hat nichts neu ausgelöst, sondern wartet im nächsten Schritt auf dessen Abschluss.
2. **„Auf Abschluss des Deployments warten“** (Timeout 15 Minuten, interne Deadline 12 Minuten):
   fragt wiederholt, alle 10 Sekunden, über kurze, unabhängige SSH-Verbindungen
   `deploy/vps/scripts/deploy-status.sh` ab (JSON, ausgewertet mit `jq`), bis die Phase `success`
   oder `failed` erreicht ist. Bei `success` und einem selbst ausgelösten Lauf wird zusätzlich
   geprüft, dass der abgeschlossene SHA tatsächlich dem erwarteten entspricht.

Der gesamte Job `deploy-vps` hat ein Timeout von 25 Minuten. Hintergrund dieser Aufteilung: Ein
direkter, minutenlanger `ssh ... deploy.sh <sha>`-Aufruf brach einmal durch einen kurzen
SSH-Verbindungsabbruch mitten im Container-Neustart ab („client_loop: send disconnect: Broken
pipe“); seitdem hängt die Korrektheit des Deployments nicht mehr davon ab, dass eine einzelne
SSH-Verbindung die gesamte Dauer übersteht (Einzelheiten:
`deploy/vps/scripts/deploy-runner.sh`, `docs/vps/06-betrieb.md`, Abschnitt „Deployment: Ablauf und
Ausfallsicherheit“).

Zusätzlich setzt der Workflow für alle SSH-/rsync-Aufrufe dieses Jobs einheitlich SSH-Keepalive
(`ServerAliveInterval=30`, `ServerAliveCountMax=10`, `TCPKeepAlive=yes`), damit eine kurzzeitig
instabile Netzwerkverbindung seltener zum Abbruch führt. Das ersetzt die serverseitige Entkopplung
nicht, sondern ergänzt sie: Auch mit Keepalive kann eine einzelne SSH-Verbindung abbrechen.

## Secrets und Variablen im Überblick

GitHub-Repository > Settings > Secrets and variables > Actions. Zwei getrennte Bereiche: Secrets
(verschlüsselt, nie im Log sichtbar) und Variables (Klartext, für unkritische Schalter).

### Bestehend, IONOS-Webhosting (unverändert, zur Abgrenzung mit aufgeführt)

| Name | Art | Zweck |
|---|---|---|
| `SFTP_HOST` | Secret | Servername des Webhosting-Pakets |
| `SFTP_USER` | Secret | SFTP-Benutzer |
| `SFTP_PASSWORD` | Secret | SFTP-Passwort |
| `SFTP_PORT` | Secret | SFTP-Port |
| `SFTP_PATH` | Secret | Zielpfad im Webspace |
| `MIGRATION_TOKEN` | Secret | Header `X-Migration-Token` für `migrate.php`, muss zusätzlich in `app/config.php` des Webhostings stehen |
| `WEBHOSTING_APP_DEPLOY` | Variable | `false`, sobald das Webhosting nur noch Marketingseiten ausliefert |
| `WEBHOSTING_MIGRATE_URL` | Variable | Vollständige Adresse des Migrationsendpunkts des Webhostings, z. B. `https://<technisch eindeutige Adresse des Webhostings>/migrate.php`. Pflicht, solange `WEBHOSTING_APP_DEPLOY` nicht `false` ist; bewusst kein Vorgabewert. Geprüft durch `tools/check-migrate-url.sh` vor dem Upload und unmittelbar vor dem Aufruf (https, Pfad endet auf `/migrate.php`, keine Zugangsdaten oder Parameter, kein zum VPS gehörender Name); enthält kein Geheimnis, der Token bleibt im Header |

### Neu, VPS

| Name | Art | Zweck | Woher der Wert kommt |
|---|---|---|---|
| `VPS_HOST` | Secret | IPv4-Adresse oder Hostname des VPS | Hostinger-Kundenbereich (Server-Übersicht); für den beschafften Server `72.61.80.67` (Hostname `srv1960492.hstgr.cloud`) |
| `VPS_SSH_USER` | Secret | SSH-Benutzer für Deployments | `deploy` (angelegt in `docs/vps/08-hostinger-coolify.md` bzw. `docs/vps/02-einrichtung-vps.md`, Schritt 5) |
| `VPS_SSH_PORT` | Secret | SSH-Port | Standard `22`, sofern nicht bewusst geändert |
| `VPS_SSH_PRIVATE_KEY` | Secret | Privater Schlüssel für den GitHub-Workflow (eigenes Schlüsselpaar, NICHT der persönliche Administratorschlüssel aus Schritt 3/4 der Einrichtung) | selbst erzeugt, siehe unten |
| `VPS_SSH_KNOWN_HOSTS` | Secret | Eine bereits verifizierte Host-Key-Zeile für `known_hosts` | `ssh-keyscan`, siehe unten |
| `VPS_DEPLOY_PATH` | Variable | Zielverzeichnis auf dem VPS | Standard `/opt/smarteinzug` |
| `VPS_DEPLOY_ENABLED` | Variable | Muss `true` sein, sonst läuft der Job überhaupt nicht | bewusst gesetzt, sobald der VPS bereit ist |
| `VPS_APP_DOMAIN` | Variable | Domain für den externen Health-Check nach dem Deployment | Standard `app.smart-einzug.de` |
| `VPS_HEALTH_STRICT` | Variable | `true` = ein fehlgeschlagener Health-Check bricht den Job ab; jeder andere Wert erzeugt nur eine Warnung | `false`/leer, solange DNS noch nicht auf den VPS zeigt; auf `true` setzen, sobald der Cutover abgeschlossen ist |

Der Workflow verwendet ausschließlich diese Namen (siehe Kopfkommentar in `.github/workflows/deploy.yml`); ein abweichender Name führt zu `Secret ... fehlt` im Log.

## Eigenes Schlüsselpaar für den Workflow erzeugen

Aus Sicherheitsgründen einen eigenen Schlüssel für GitHub Actions verwenden, nicht den
persönlichen Administratorschlüssel aus der Einrichtung (Trennung der Zugänge, einzeln
widerrufbar):

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy@smart-einzug.de" -f ~/.ssh/smarteinzug_vps_deploy -N ""
```

`-N ""` setzt keine Passphrase, da der Schlüssel nicht interaktiv entsperrt werden kann, wenn der
Workflow läuft. Der private Schlüssel verlässt danach den eigenen Rechner nur einmal, beim
Einfügen in GitHub Secrets (Schritt unten); danach lokal löschen oder sicher verwahren.

## Öffentlichen Schlüssel auf dem VPS hinterlegen

```bash
ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo "ssh-ed25519 AAAA... github-actions-deploy@smart-einzug.de" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Den öffentlichen Schlüsselinhalt (`cat ~/.ssh/smarteinzug_vps_deploy.pub`) einfügen, nicht den
privaten. Test von einem beliebigen Rechner mit dem privaten Schlüssel:

```bash
ssh -i ~/.ssh/smarteinzug_vps_deploy -p 22 deploy@HIER-VPS-IP "echo Zugang erfolgreich"
```

## Host-Key holen und prüfen

```bash
ssh-keyscan -t ed25519 72.61.80.67 > /tmp/vps_hostkey
cat /tmp/vps_hostkey
ssh-keygen -E sha256 -lf /tmp/vps_hostkey
```

Den Befehl aus der Hostinger-Webkonsole (im Hostinger-Kundenbereich, VNC-/Browser-Terminal des
Servers) oder aus einer anderen bereits als vertrauenswürdig bestätigten Sitzung heraus ausführen,
NICHT blind über eine neue, noch nicht verifizierte Verbindung. Anschließend den ausgegebenen
Fingerabdruck (`SHA256:...`) mit dem im Hostinger-Kundenbereich angezeigten SSH-Fingerabdruck des
Servers abgleichen, sofern dort angezeigt (auf dem Server bzw. im Kundenbereich zu prüfen).
Stimmen die Fingerabdrücke nicht überein oder lässt sich kein Referenzwert finden, den Schlüssel
NICHT verwenden (möglicher Man-in-the-Middle oder falscher Server) und den Fehler zuerst klären.
Auf keinen Fall Fingerabdrücke des früher vorgesehenen IONOS-VPS übernehmen: Diese gehören zu
einem anderen, nicht beschafften Server und sind für den tatsächlichen Hostinger-VPS bedeutungslos.
Der Inhalt von `/tmp/vps_hostkey` (die vollständige Zeile, nicht nur der Fingerabdruck) wird
unverändert als `VPS_SSH_KNOWN_HOSTS` hinterlegt.

`StrictHostKeyChecking=yes` bleibt im Workflow immer aktiv (siehe Kopfkommentar in `deploy.yml`);
ein fehlender oder nicht passender Eintrag lässt den Job mit einem SSH-Fehler abbrechen, statt die
Prüfung stillschweigend zu umgehen.

## Secrets und Variablen eintragen

GitHub-Repository > Settings > Secrets and variables > Actions:

1. Reiter „Secrets“ > „New repository secret“ für jeden der sechs VPS-Secrets aus der Tabelle
   oben (`VPS_HOST`, `VPS_SSH_USER`, `VPS_SSH_PORT`, `VPS_SSH_PRIVATE_KEY`,
   `VPS_SSH_KNOWN_HOSTS`, sowie das bereits vorhandene `MIGRATION_TOKEN` bleibt unverändert).
   Bei `VPS_SSH_PRIVATE_KEY` den gesamten Inhalt der privaten Schlüsseldatei einfügen, inklusive
   der Kopf- und Fußzeile (`-----BEGIN OPENSSH PRIVATE KEY-----` … `-----END OPENSSH PRIVATE
   KEY-----`).
2. Reiter „Variables“ > „New repository variable“ für `VPS_DEPLOY_ENABLED` (zunächst `false`,
   erst nach erfolgreichem Test auf `true`), `VPS_DEPLOY_PATH`, `VPS_APP_DOMAIN`,
   `VPS_HEALTH_STRICT` (zunächst `false`).

## Deployment testen

Zunächst mit `VPS_DEPLOY_ENABLED=false` sicherstellen, dass der Job „deploy-vps“ übersprungen
wird (Bedingung in `deploy.yml`: `vars.VPS_DEPLOY_ENABLED == 'true'`). Danach:

1. `VPS_DEPLOY_ENABLED` auf `true` setzen.
2. GitHub Actions > Workflow „Deployment IONOS-Webhosting und VPS“ > „Run workflow“
   (`workflow_dispatch`) auf dem gewünschten Branch auslösen.
3. Ablauf beobachten: Job „changes“ (bei `workflow_dispatch` gilt alles als geändert), Job „test“
   (PHP-Lint, gegebenenfalls Website-QA, Dokumentation, zusätzlich `python3 tools/compose-check.py`,
   siehe unten), Job „deploy-vps“ (rsync von `php-ionos/`, `deploy/vps/` und der Statusseite, danach
   die zwei Schritte „Deployment auf dem VPS auslösen“ und „Auf Abschluss des Deployments warten“,
   siehe Abschnitt „Ablauf des Jobs deploy-vps“ oben, Health-Check).
4. Bei `VPS_HEALTH_STRICT=false` (empfohlen, solange DNS noch nicht auf den VPS zeigt) endet der
   Job auch bei fehlgeschlagenem externen Health-Check mit einer Warnung, nicht mit einem Abbruch;
   der eigentliche Deploy-Erfolg zeigt sich am Exit-Code von `deploy.sh` auf dem Server.

`tools/compose-check.py` im Job „test“ prüft die Compose-Dateien unter `deploy/vps/` ohne laufenden
Docker-Daemon (Details und Aufruf: `deploy/vps/README.md`) und verhindert dadurch bereits vor dem
Deployment, dass ein Dienst ohne eigenen oder mit einem zum Prozess nicht passenden Healthcheck auf
den VPS gelangt, so wie es beim ersten Deployment mit dem Dienst `metrics` der Fall war (siehe
`docs/vps/06-betrieb.md`, Abschnitt „Healthchecks der Container“). Ein Fehler dieses Skripts lässt
den Job „test“ und damit den gesamten Workflow-Lauf fehlschlagen, bevor ein Deployment überhaupt
versucht wird.
5. Ergebnis prüfen: `ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP "readlink -f /opt/smarteinzug/releases/current"` zeigt den neuen Git-SHA; `docker compose ... ps` zeigt neu gestartete Container; zusätzlich
   `ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP "bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 50"`
   zeigt Phase, SHA und die letzten Protokollzeilen des serverseitigen Deploy-Runners.
6. Erst nach einem erfolgreichen Testlauf `VPS_HEALTH_STRICT` auf `true` setzen (siehe
   `docs/vps/02-einrichtung-vps.md`, Schritt 18 ff.).

## Fehlerbilder

| Meldung im Workflow-Log | Wahrscheinliche Ursache | Behebung |
|---|---|---|
| „Permission denied (publickey)“ | Öffentlicher Schlüssel nicht in `authorized_keys` des Servers, oder `VPS_SSH_PRIVATE_KEY` unvollständig eingefügt | Schlüsselzuordnung erneut prüfen (siehe oben) |
| „Host key verification failed“ | `VPS_SSH_KNOWN_HOSTS` fehlt, falsch oder Server-Schlüssel hat sich geändert | Host-Key neu holen (siehe oben, aus vertrauenswürdiger Sitzung), Fingerabdruck erneut prüfen, Secret aktualisieren |
| Job „deploy-vps“ läuft gar nicht | `VPS_DEPLOY_ENABLED` nicht `true`, oder weder `app` noch `vps` als geändert erkannt | Variable prüfen; bei gezieltem Test `workflow_dispatch` verwenden (gilt als „alles geändert“) |
| „deploy.sh: Release-Ordner fehlt“ | rsync-Schritt vor `deploy.sh` fehlgeschlagen oder `GITHUB_SHA` weicht ab | Log des Schritts „Anwendung per rsync übertragen“ prüfen |
| „REJECTED“ im Schritt „Deployment auf dem VPS auslösen“ | Es lief bereits ein Deployment oder Rollback auf dem Server (Sperre `deploy/.deploy.lock` belegt), kein Fehler dieses Laufs | Nächster Schritt wartet automatisch auf den Abschluss des laufenden Vorgangs; Status manuell mit `deploy-status.sh` prüfen |
| Zeitüberschreitung im Schritt „Auf Abschluss des Deployments warten“ | Deployment auf dem Server läuft ungewöhnlich lange oder die Statusdatei ist nicht erreichbar | `bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 80` direkt auf dem Server ausführen |
| Health-Check „HTTP 000“ oder Timeout | DNS zeigt noch nicht auf den VPS, oder Firewall/Caddy blockiert | bei aktivem Cutover: DNS prüfen (`docs/vps/05-dns-ssl.md`); vor dem Cutover: `VPS_HEALTH_STRICT=false` lassen |
| „Health-Check-Antwort enthält kein "php":true“ | `health.php` liefert unerwarteten Inhalt (Anwendungsfehler, falsche Konfiguration) | `docker compose logs php`, `bin/healthcheck.php --all` direkt auf dem Server |

## Rollback

Der Workflow selbst führt kein automatisches Rollback aus (siehe Kopfkommentar in `deploy.yml`).
Zwei Wege:

1. **Erneuten Workflow-Lauf auf einem älteren, funktionierenden Commit auslösen**
   (`workflow_dispatch` auf dem gewünschten Branch/Tag, sofern der gewünschte Stand als Commit
   vorliegt).
2. **Direktes Rollback auf dem Server** (schneller, kein neuer Build nötig):
   ```bash
   ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP
   cd /opt/smarteinzug/deploy
   bash rollback.sh previous
   ```
   `previous` verwendet den von `deploy.sh` zuletzt hinterlegten Stand
   (`/opt/smarteinzug/deploy/.previous_sha`); alternativ einen bestimmten Git-SHA angeben
   (`bash rollback.sh <git-sha>`), sofern dessen Release-Ordner noch unter
   `/opt/smarteinzug/releases/` vorhanden ist (die letzten fünf Releases werden aufbewahrt).
   Ein Rollback ändert ausschließlich den Anwendungscode, keine Datenbankmigration wird
   zurückgebaut (siehe `deploy/vps/README.md`, Abschnitt „Offene Punkte“); bei einer
   schemabrechenden Änderung ist zusätzlich ein manueller Datenbankeingriff nötig.

Nach jedem Rollback: Health-Check von außen wiederholen (`docs/vps/02-einrichtung-vps.md`,
Schritt 18) und Version im Adminbereich System > Versionen mit dem erwarteten Stand vergleichen.

## Offener Punkt: Node-Warnung bei Upload/Download-Artifact

`actions/upload-artifact@v4` und `actions/download-artifact@v4` melden im Workflow-Log eine
Node-20-Warnung. Diese Warnung ist nicht die Ursache eines Fehlers und wurde bewusst nicht durch
eine Versionsanhebung behoben, da sich die aktuelle offizielle Nachfolgeversion aus dieser
Arbeitsumgebung heraus nicht überprüfen ließ und ein falscher Versionsverweis den gesamten
Workflow unbrauchbar machen würde. Vor einer Anhebung die aktuelle offizielle Version auf der
Seite der jeweiligen Action prüfen (zu prüfen).
