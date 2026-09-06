# GitHub-Deployment: Secrets, Variablen, Einrichtung, Test, Rollback

Stand: 06.09.2026 (Auftrag III). Bezieht sich auf `.github/workflows/deploy.yml`, Job
`deploy-vps` (der bestehende Job `deploy-webhosting` ist unverändert, siehe `docs/migrations.md`).

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

### Neu, VPS

| Name | Art | Zweck | Woher der Wert kommt |
|---|---|---|---|
| `VPS_HOST` | Secret | IPv4-Adresse oder Hostname des VPS | IONOS Kundenbereich (Server-Übersicht) |
| `VPS_SSH_USER` | Secret | SSH-Benutzer für Deployments | `deploy` (angelegt in `docs/vps/02-einrichtung-vps.md`, Schritt 5) |
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

## Host-Key holen und gegen die IONOS-Konsole prüfen

```bash
ssh-keyscan -p 22 -t ed25519 HIER-VPS-IP > /tmp/vps_hostkey
cat /tmp/vps_hostkey
ssh-keygen -E sha256 -lf /tmp/vps_hostkey
```

Den ausgegebenen Fingerabdruck (`SHA256:...`) gegen den im IONOS Kundenbereich angezeigten
SSH-Fingerabdruck des Servers prüfen (Server-Übersicht, Details des VPS). Stimmen die
Fingerabdrücke nicht überein, den Schlüssel NICHT verwenden (möglicher Man-in-the-Middle oder
falscher Server) und den Fehler zuerst klären. Der Inhalt von `/tmp/vps_hostkey` (die vollständige
Zeile, nicht nur der Fingerabdruck) wird unverändert als `VPS_SSH_KNOWN_HOSTS` hinterlegt.

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
   (PHP-Lint, gegebenenfalls Website-QA, Dokumentation), Job „deploy-vps“ (rsync von `php-ionos/`,
   `deploy/vps/` und der Statusseite, Ausführung von `deploy/vps/scripts/deploy.sh <git-sha>` aus dem neuen Release auf dem Server,
   Health-Check).
4. Bei `VPS_HEALTH_STRICT=false` (empfohlen, solange DNS noch nicht auf den VPS zeigt) endet der
   Job auch bei fehlgeschlagenem externen Health-Check mit einer Warnung, nicht mit einem Abbruch;
   der eigentliche Deploy-Erfolg zeigt sich am Exit-Code von `deploy.sh` auf dem Server.
5. Ergebnis prüfen: `ssh -i ~/.ssh/smarteinzug_vps_admin deploy@HIER-VPS-IP "readlink -f /opt/smarteinzug/releases/current"` zeigt den neuen Git-SHA; `docker compose ... ps` zeigt neu gestartete Container.
6. Erst nach einem erfolgreichen Testlauf `VPS_HEALTH_STRICT` auf `true` setzen (siehe
   `docs/vps/02-einrichtung-vps.md`, Schritt 18 ff.).

## Fehlerbilder

| Meldung im Workflow-Log | Wahrscheinliche Ursache | Behebung |
|---|---|---|
| „Permission denied (publickey)“ | Öffentlicher Schlüssel nicht in `authorized_keys` des Servers, oder `VPS_SSH_PRIVATE_KEY` unvollständig eingefügt | Schlüsselzuordnung erneut prüfen (siehe oben) |
| „Host key verification failed“ | `VPS_SSH_KNOWN_HOSTS` fehlt, falsch oder Server-Schlüssel hat sich geändert | Host-Key neu holen, Fingerabdruck erneut gegen die IONOS-Konsole prüfen, Secret aktualisieren |
| Job „deploy-vps“ läuft gar nicht | `VPS_DEPLOY_ENABLED` nicht `true`, oder weder `app` noch `vps` als geändert erkannt | Variable prüfen; bei gezieltem Test `workflow_dispatch` verwenden (gilt als „alles geändert“) |
| „deploy.sh: Release-Ordner fehlt“ | rsync-Schritt vor `deploy.sh` fehlgeschlagen oder `GITHUB_SHA` weicht ab | Log des Schritts „Anwendung per rsync übertragen“ prüfen |
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
