# Betrieb

Stand: 06.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`). Laufender Betrieb des VPS-Stacks nach abgeschlossener
Einrichtung (`docs/vps/02-einrichtung-vps.md`, tatsächlicher Weg: `docs/vps/08-hostinger-coolify.md`).
Alle Befehle im Verzeichnis `/opt/smarteinzug/deploy` ausführen, sofern nicht anders angegeben.
Auf dem Server läuft neben diesem Stack auch Coolify selbst (Proxy und Serverübersicht, siehe
`docs/vps/01-architektur.md`); Coolifys eigene Postgres-/Redis-Instanz und der Proxy-Container
gehören nicht zu diesem Compose-Projekt und werden hier nicht verwaltet.

## Logs

Alle Container schreiben nach `stdout`/`stderr` (Docker-Treiber `json-file`, Rotation 20 MB je
Datei, 5 Dateien je Dienst, siehe `docker-compose.yml`). Die Anwendung selbst schreibt
strukturierte JSON-Zeilen (`app/log.php`, `config('log.target') = 'stderr'` auf dem VPS).

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f php
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f worker-lexware-1
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs --since 1h scheduler
```

Eine Log-Zeile ist ein JSON-Objekt mit mindestens Zeitstempel, Dienst (`LOG_SERVICE`, z. B.
`worker`, `scheduler`, `cli`), Correlation-ID (`correlation_id`) und Nachricht. Einen fachlichen
Vorgang über mehrere Container hinweg verfolgen (z. B. eine Web-Anfrage, die einen Job anlegt, und
den Worker, der ihn später verarbeitet):

```bash
docker compose logs php scheduler worker-lexware-1 worker-lexware-2 2>&1 \
  | grep '"correlation_id":"<hier-die-id-einfuegen>"'
```

Die Correlation-ID einer Anfrage steht im Adminbereich System (Jobs, Details eines Jobs) und in
den Antwort-Headern der Anwendung, sofern dort ausgegeben.

## Systemstatus prüfen

**Im Adminbereich:** `admin.smart-einzug.de` > System. Reiter Jobs (Warteschlange, Worker,
Circuit Breaker, fehlgeschlagene Jobs), Server (Versionen, auf dem VPS zusätzlich Host-Metriken:
CPU, RAM, Platte, Load, Datenbankverbindungen), Versionen (Änderungsverlauf), Dokumentation
(erzeugte technische Dokumentation, siehe `tools/build-docs.py`).

**Auf dem Server:**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all
```

Exit-Code 0 bedeutet: Datenbank erreichbar, Redis erreichbar (sofern konfiguriert), je Pool
mindestens ein lebender Worker, Scheduler-Heartbeat aktuell, Warteschlange lesbar ohne Jobs mit
abgelaufenem Heartbeat. Einzelprüfungen: `--db`, `--redis`, `--workers=lexware,stripe`,
`--scheduler`, `--queue`.

## Worker skalieren und neu starten

```bash
# Einen einzelnen Worker neu starten (SIGTERM, laufender Job wird zu Ende gebracht):
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart worker-stripe

# Zusätzliche Worker eines Pools kurzfristig hochskalieren:
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker-mail=2

# Danach wieder zurückskalieren:
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --scale worker-mail=1
```

`worker-lexware-1`/`worker-lexware-2` sind feste, einzelne Dienste (zwei Container in
`docker-compose.prod.yml`, siehe `deploy/vps/README.md`); `--scale` eignet sich für die übrigen,
namentlich einzelnen Pools (`worker-mail`, `worker-stripe`, `worker-maintenance`).

## Dead Letter behandeln

Adminbereich System > Jobs > Fehlgeschlagen. Je Job:

- **Erneut versuchen** (`queue_retry_now`): setzt den Job zurück in den Zustand `queued`,
  verwendbar nachdem die Ursache behoben wurde (z. B. Lexware-API wieder erreichbar).
- **Abbrechen** (`queue_cancel`): setzt den Job auf `cancelled`, kein weiterer Versuch, der
  fachliche Vorgang gilt als nicht abgeschlossen und muss gegebenenfalls anders angestoßen werden.
- **Dauerhaft schließen** (`queue_close`): markiert den Job endgültig als geklärt
  (`closed_at` gesetzt), ohne ihn erneut zu versuchen; für Fälle, in denen der fachliche Vorgang
  anderweitig erledigt wurde.

Vor „Erneut versuchen“ immer die Fehlerursache klären (`last_error`, Log über die
`correlation_id` des Jobs); ein wiederholter Versuch ohne Ursachenklärung führt in der Regel zum
selben Fehler.

## Circuit Breaker

Adminbereich System > Jobs > Anbindungen. Zeigt je Anbindung (`lexoffice`, `stripe`, `mail`) den
Zustand (`closed`, `open`, `half_open`), Anzahl Fehlversuche und Zeitpunkt der letzten
Störung/des letzten Erfolgs. Ein geöffneter Breaker ist kein Fehler der Anwendung, sondern ein
Schutzmechanismus: Jobs dieser Anbindung schlagen bewusst sofort fehl (mit regulärem Backoff),
statt eine bereits gestörte externe Anbindung weiter zu belasten. Wirkt sich der Breaker
ungewöhnlich lange aus, die externe Anbindung selbst prüfen (Lexware-Office-Status, Stripe-Status),
nicht nur die eigene Konfiguration.

## Wartungsmodus je Firma

Adminbereich System (Superadmin) oder direkt in der Firmenverwaltung:
`organizations.sync_paused` pausiert ausschließlich die Synchronisation einer einzelnen Firma
(`sync_paused_reason` als Freitext für den Grund); die Anwendung selbst bleibt für diese Firma
uneingeschränkt nutzbar, nur der Scheduler reiht keine neuen Synchronisationsjobs für sie ein.
Für einen vollständigen Wartungsmodus (alle Firmen, gesamte Anwendung):

```bash
bash scripts/maintenance.sh on
bash scripts/maintenance.sh off
```

Wirkung des Wartungsmodus (Markerdatei `maintenance.flag` im gemeinsamen Speicher, gilt sofort für
alle Container):

- Webseiten antworten mit 503; erreichbar bleiben `health.php`, `migrate.php`, Anmeldung und
  Adminbereich. Die Ausnahmen richten sich nach dem ausgeführten Skript, nicht nach der URL.
- Scheduler reiht keine neuen Jobs ein, Worker reservieren keine Jobs mehr; ein bereits laufender
  Job wird zu Ende gebracht. Heartbeats laufen weiter, die Container bleiben gesund.
- Stripe-Webhooks, `cron.php` und `track.php` erhalten ebenfalls 503. Stripe wiederholt Ereignisse
  mit steigendem Abstand, aber nicht unbegrenzt: Nach dem Fenster fehlgeschlagene Ereignisse im
  Stripe-Dashboard erneut senden (Cutover-Checkliste). Das Fenster deshalb kurz halten; der
  Adminbereich System zeigt Beginn und Dauer an und warnt ab 12 Stunden.
- Ein- und Ausschalten werden mit Zeitstempel in `/opt/smarteinzug/logs/maintenance.log` festgehalten.

## Backups und Restore-Test

Täglicher automatischer Lauf über den `backup`-Container (Cron innerhalb des Containers, siehe
`deploy/vps/backup/backup.sh`): `mariadb-dump --single-transaction`, Komprimierung, Prüfsumme,
optionale Verschlüsselung (`BACKUP_AGE_RECIPIENT`), lokale Aufbewahrung
(`BACKUP_RETENTION_DAYS`, Standard 14 Tage), optionaler externer Upload (`BACKUP_REMOTE`),
Rückmeldung an die Anwendung über die Ergebnisdatei `backup-status.json` (sichtbar im Adminbereich System, Dienste und Server).

Manueller Lauf und Wiederherstellungstest:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec backup bash backup.sh
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec backup bash restore-test.sh
```

`restore-test.sh` spielt den letzten Dump in eine isolierte Testdatenbank ein und prüft, dass der
Import ohne Fehler durchläuft, ohne die produktive Datenbank zu berühren. Diesen Test regelmäßig
wiederholen (empfohlen: monatlich), nicht nur einmalig bei der Einrichtung.

## Updates

**Betriebssystem:** `unattended-upgrades` ist über `scripts/setup-vps.sh` bereits eingerichtet
(automatische Sicherheitsaktualisierungen). Reguläre, nicht sicherheitskritische Updates weiterhin
von Hand und zu einem geplanten Zeitpunkt:

```bash
sudo apt-get update && sudo apt-get upgrade -y
```

Nach einem Kernel-Update einen Neustart des Servers einplanen (außerhalb der Geschäftszeiten,
vorher Wartungsmodus aktivieren).

**Docker-Images:** Basis-Images (PHP, MariaDB, Redis, Caddy) regelmäßig aktualisieren:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Ein Deployment baut das PHP-Image nur neu, wenn `deploy/vps/php/Dockerfile` geändert wurde (siehe
`deploy/vps/scripts/deploy.sh`); ein reines `pull` der Basis-Images ist unabhängig davon jederzeit
möglich und empfohlen (Sicherheitsaktualisierungen der Basis-Images).

## Log-Rotation

Bereits über den Docker-Log-Treiber je Dienst konfiguriert (20 MB / 5 Dateien, siehe
`docker-compose.yml`). Prüfen:

```bash
docker inspect php --format '{{json .HostConfig.LogConfig}}'
df -h /var/lib/docker
```

Anwendungsseitige Datei-Logs (nur relevant, falls `log.target = 'file'` versehentlich auf dem VPS
gesetzt wurde, statt `stderr`) liegen unter `/opt/smarteinzug/shared/storage/logs`; dort greift
keine automatische Rotation durch Docker, gegebenenfalls `logrotate` auf dem Host ergänzen.

## Ressourcenlimits

`docker-compose.prod.yml` setzt CPU- und Speichergrenzen je Dienst. Prüfen:

```bash
docker stats --no-stream
```

Ein Dienst nahe an seiner Speichergrenze (sichtbar an wiederholten Neustarts, `OOMKilled` in
`docker compose ps` bzw. `docker inspect <container> --format '{{.State.OOMKilled}}'`) deutet auf
zu knapp bemessene Limits oder ein echtes Speicherproblem hin (z. B. eine Firma mit ungewöhnlich
großem Rechnungsbestand); Grenzwert in `docker-compose.prod.yml` anpassen oder Ursache im
Anwendungscode klären, nicht kommentarlos immer weiter erhöhen.

## Störungsanzeige für Benutzer

Störungen und Wartungen werden im Adminbereich System angelegt (`monitor_incidents`,
`monitor_incident_updates`) und über den Snapshot (`status_publish()`) auf
`status.smart-einzug.de` veröffentlicht (siehe `docs/status-page.md`). Bei einer Störung, die den
VPS selbst betrifft (z. B. geplante Wartung mit Neustart): Wartung im Adminbereich anlegen, BEVOR
der Wartungsmodus aktiviert wird, damit Benutzer die Information rechtzeitig auf der Statusseite
sehen. Nach Abschluss der Wartung die Meldung im Adminbereich auf „Abgeschlossen“ setzen.
