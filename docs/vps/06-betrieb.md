# Betrieb

Stand: 07.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`, zuletzt ausfallsicheres VPS-Deployment, Version 4.5). Laufender
Betrieb des VPS-Stacks nach abgeschlossener
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

## Healthchecks der Container

Jeder Dienst aus dem gemeinsamen PHP-Image (`smarteinzug-php:local`) definiert seinen Healthcheck
in `docker-compose.yml` selbst; das Image selbst gibt keinen Vorgabewert vor (`HEALTHCHECK NONE` in
`deploy/vps/php/Dockerfile`), weil dasselbe Image Web, Scheduler, Worker und den Metrik-Sammler
trägt und ein gemeinsamer Vorgabewert für mindestens eine dieser Rollen falsch wäre. Ein vergessener
Healthcheck fällt dadurch als „kein Healthcheck“ auf, nicht als falsches Ergebnis eines fremden
Checks. `tools/compose-check.py` prüft das ohne laufenden Docker-Daemon (siehe unten).

| Dienst | Prozess | Healthcheck | Bedeutung |
|---|---|---|---|
| `php` | php-fpm (Web) | `bin/healthcheck.php --db` | Datenbank über `SELECT 1` erreichbar |
| `scheduler` | `bin/scheduler.php` | `bin/healthcheck.php --heartbeat` | Heartbeat-Datei des Containers jünger als 90 Sekunden |
| `worker-lexware-1`, `worker-lexware-2`, `worker-stripe`, `worker-mail`, `worker-maintenance` | `bin/worker.php --pool=...` | `bin/healthcheck.php --heartbeat` | Heartbeat-Datei des jeweiligen Worker-Containers jünger als 90 Sekunden |
| `metrics` | `bin/host-metrics.php` | `bin/healthcheck.php --metrics` | `bin/host-metrics.php` läuft als PID 1 UND die Sammelschleife hat zuletzt innerhalb von `METRICS_MAX_AGE_SECONDS` (Standard 300 Sekunden) einen Durchlauf beendet |
| `redis` | `redis-server` | `redis-cli ping` | Redis antwortet |
| `caddy` | `caddy` | keiner | Das Basisimage `caddy:2-alpine` bringt keinen eigenen Healthcheck mit; kein Mangel, siehe unten |

Zu `caddy`: `deploy/vps/scripts/deploy.sh` wartet beim Deployment nur auf Container, die einen
Healthcheck besitzen, und prüft die Kette Coolify-Proxy, Caddy, php-fpm anschließend funktional über
den HTTPS-Aufruf von `health.php`. Ein zusätzlicher Container-Healthcheck für Caddy wurde bewusst
nicht eingeführt, weil er ungeprüft in den deploy-blockierenden Pfad eingreifen würde.

### Störung: metrics meldet unhealthy

**Symptom:** `docker compose ... ps` zeigt den Dienst `metrics` als `unhealthy`; ein laufendes
Deployment bricht ab, obwohl der Metrik-Sammler-Prozess selbst läuft.

**Ursache:** Der Dienst `metrics` hatte in einer früheren Fassung von
`deploy/vps/docker-compose.yml` keinen eigenen Healthcheck und übernahm dadurch den
Standard-Healthcheck des PHP-Images (`bin/healthcheck.php --heartbeat`). Dieser Healthcheck prüft
den Worker-Heartbeat, den `bin/host-metrics.php` bewusst nicht schreibt (Meldung
„UNGESUND: heartbeat: kein frischer Heartbeat“). Seit der Einführung des eigenen Modus
`bin/healthcheck.php --metrics` (siehe Tabelle oben) ist dieser Fehler strukturell ausgeschlossen;
`tools/compose-check.py` verhindert ein Wiederauftreten dauerhaft.

**Heutige Prüfung:**

```bash
docker inspect --format '{{.State.Health.Status}}' smarteinzug-metrics-1
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec metrics php bin/healthcheck.php --metrics
```

Der zweite Befehl gibt bei einer echten Störung eine Kurzmeldung aus (zum Beispiel „bin/host-metrics.php
läuft nicht als PID 1“ oder „letzter Durchlauf vor ... s“) und hilft, zwischen einem hängenden
Prozess und einer zu kurzen Wartezeit nach dem Start zu unterscheiden. Ein dauerhaft ungesunder
`metrics`-Container bricht ein Deployment ab, da `deploy.sh` auf den gesunden Zustand aller
Container mit Healthcheck wartet.

## Deployment: Ablauf und Ausfallsicherheit

Reguläre Deployments laufen ausschließlich über den GitHub-Workflow
(`.github/workflows/deploy.yml`, Job `deploy-vps`, siehe `docs/vps/03-github-deployment.md`). Der
Workflow ruft per SSH zunächst `deploy/vps/scripts/deploy-runner.sh <git-sha>` auf dem Server auf
und fragt den Fortschritt danach über `deploy/vps/scripts/deploy-status.sh` ab. Der direkte Aufruf
von `deploy.sh` bleibt für die Ersteinrichtung und für gezielte manuelle Eingriffe möglich, dann mit
eigener Sperre (siehe `deploy/vps/README.md`, Abschnitt „Start“).

### Serverseitige Entkopplung von der SSH-Sitzung

`deploy-runner.sh` prüft zunächst nicht blockierend, ob bereits ein Deployment oder Rollback läuft
(dieselbe Sperrdatei `deploy/.deploy.lock` wie `deploy.sh`/`rollback.sh`). Läuft bereits eines, wird
nichts gestartet, die Ausgabe lautet „REJECTED“ mit Exit-Code 3, kein doppeltes Deployment durch
einen GitHub-Retry oder einen zweiten Workflow-Lauf. Andernfalls startet sich das Skript per
`setsid` selbst in einer neuen, von der SSH-Sitzung unabhängigen Sitzung neu; der bereits gehaltene
Sperr-Dateideskriptor wird an den neuen Prozess vererbt, es entsteht also keine Race Condition
zwischen Prüfung und Übernahme der Sperre. Ein Abbruch der SSH-Verbindung sendet danach kein SIGHUP
mehr an den laufenden Deploy-Prozess. Der im Vordergrund laufende Teil, den die SSH-Sitzung sieht,
kehrt innerhalb weniger Sekunden mit „TRIGGERED“ zurück; der eigentliche Vorgang (Image-Build,
Candidate-Prüfung, Migration, Cutover) läuft danach im Hintergrund weiter und ruft `deploy.sh` mit
`SMARTEINZUG_LOCK_HELD=1` auf, sodass `deploy.sh` nicht versucht, dieselbe Sperre ein zweites Mal zu
erwerben.

Der GitHub-Workflow fragt den Fortschritt danach über wiederholte, kurze, unabhängige
SSH-Verbindungen ab (`deploy-status.sh`, alle 10 Sekunden, interne Deadline 12 Minuten, siehe
`docs/vps/03-github-deployment.md`); jede einzelne Abfrage darf abbrechen, ohne das laufende
Deployment zu gefährden.

### Statusdatei

`deploy-runner.sh` schreibt eine PID-Datei, ein eigenes Protokoll unter `logs/` und eine
JSON-Statusdatei (`deploy/.deploy-status.json`) mit den Feldern `phase` (`running`, `success`,
`failed`), `sha`, `pid`, `started_at`, `updated_at`, `exit_code`, `log_file` und `message`. Die
Datei enthält keine Geheimnisse. Manueller Statusabruf auf dem Server, mit Protokollauszug:

```bash
bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 50
```

### Release-Bindung ohne mutable Symlink

`working_dir` aller PHP-Container und Caddys Dokumentenstamm sind an die Umgebungsvariable
`RELEASE_SHA` gebunden (Pflichtwert, `${RELEASE_SHA:?...}` in `docker-compose.yml`), nicht mehr an
den Symlink `releases/current`. `deploy.sh`/`rollback.sh` exportieren die Variable vor jedem
`docker compose`-Aufruf auf das jeweils gemeinte Release. Damit gehören Compose-Konfiguration
(einschließlich Healthchecks), Image und Anwendungscode bei jedem Containerstart garantiert zum
selben Release; zuvor bestand das Risiko, dass ein frisch erzeugter Container mit neuer
Compose-Konfiguration über den noch nicht umgestellten Symlink auf älteren Anwendungscode traf. Der
Symlink `releases/current` bleibt bestehen, dient aber nur noch als Buchführung für Menschen und
Werkzeuge (`readlink`, `scripts/db-import.sh`); für die Korrektheit der Container hat er keine
Bedeutung mehr.

### Migrationsreihenfolge: Candidate prüfen, dann migrieren, dann erst Cutover

`deploy.sh` prüft den neuen Code und spielt Migrationen jetzt VOR dem Cutover in einem
zusätzlichen, isolierten Container ein (`docker compose run --rm --no-deps`), der die laufenden
Container `php`/`scheduler`/`worker-*` nicht berührt:

Image bauen (falls nötig) → Candidate isoliert prüfen (`bin/healthcheck.php --db --redis` mit dem
neuen Code, laufende Anwendung unberührt) → Migrationen isoliert mit dem neuen Code einspielen →
erst danach der eigentliche Cutover (`docker compose up -d`) → auf gesunde Container warten →
`current`-Symlink umstellen (Buchführung) → php-fpm neu laden → Worker/Scheduler kontrolliert neu
starten → Health-Check → bei einem Fehler ab dem Cutover automatisches Rollback.

Schlagen Candidate-Prüfung oder Migration fehl, wurde an den laufenden Containern nichts verändert;
ein Rollback ist dann nicht nötig, die alte Version läuft mit dem alten Code und dem alten
Datenbankstand unverändert weiter. Ein abgebrochener vorheriger Lauf, der Container im Zustand
`created` hinterlassen hat, wird von `docker compose up -d` beim nächsten Versuch von selbst
aufgelöst (idempotent); `deploy.sh` protokolliert den Containerzustand vor und nach diesem Schritt.
Ausschließlich Ressourcen des Compose-Projekts `smarteinzug` werden dabei angefasst, niemals der
Coolify-Proxy oder die Coolify-MariaDB.

`rollback.sh` exportiert ebenfalls `RELEASE_SHA` (auf das Zielrelease des Rollbacks) und
unterstützt `SMARTEINZUG_LOCK_HELD=1`, damit ein automatischer Rollback aus `deploy.sh` heraus (das
seinerseits von `deploy-runner.sh` mit gehaltener Sperre aufgerufen wurde) nicht an der bereits
gehaltenen Sperre scheitert.

### Störung: Deployment nach SSH-Abbruch

**Symptom früher:** Die SSH-Verbindung des GitHub-Workflows brach während des mehrminütigen
Vorgangs (Image-Build, Container-Neustart, Migration) einmal kurz ab („client_loop: send
disconnect: Broken pipe“). Der entfernte `deploy.sh`-Prozess hing am selben SSH-Kanal und starb
mitten im Container-Neustart; mehrere Container blieben im Zustand `created` (nie gestartet)
hängen, während andere weiterliefen.

**Heutiges Verhalten:** Das Deployment läuft serverseitig entkoppelt weiter (siehe oben), ein
SSH-Abbruch beendet den laufenden Deploy-Prozess nicht mehr. Der Fortschritt lässt sich jederzeit
über `deploy-status.sh` abrufen, auf dem Server oder über den nächsten Workflow-Lauf.

**Was der Betreiber nicht mehr manuell tun muss:** Container von Hand auf den Zustand `created`
prüfen und einzeln nachstarten, den Deployment-Fortschritt aus Protokollen rekonstruieren, oder nach
einem Verbindungsabbruch ein neues Deployment auf Verdacht auslösen und dabei riskieren, ein noch
laufendes doppelt zu starten. Ein zweiter Auslöseversuch, während ein Deployment noch läuft, wird
jetzt von `deploy-runner.sh` selbst abgelehnt („REJECTED“, siehe oben).

### Störung: Candidate-Prüfung meldet „redis: other“

**Symptom:** Der erste produktive Lauf der neuen Candidate-Prüfung (siehe oben) scheiterte mit
„UNGESUND: redis: other“ in der Phase „Pruefe den Candidaten isoliert“. Der Image-Build war
vollständig erfolgreich, die Datenbankprüfung des Candidaten war unauffällig; es scheiterte
ausschließlich die Redis-Teilprüfung. Die laufenden Container wurden dabei nicht verändert, ein
Rollback war nicht nötig.

**Ursache (damaliger Kenntnisstand, siehe Nachtrag unten für die inzwischen bestätigte tatsächliche
Ursache):** `monitor_category()` erkannte die tatsächliche Fehlermeldung nicht und fiel auf den
unbrauchbaren Sammelbegriff „other“ zurück. Das PHP-Image ist Alpine-/musl-basiert; musl formuliert
DNS-Fehler anders als die bisher erkannte glibc-Formulierung (z. B. „Try again“ oder „Name does not
resolve“ statt „Temporary failure in name resolution“ bzw. „Name or service not known“). Als **nicht
bestätigte Arbeitshypothese** für den Redis-Teil wurde damals eine rein transiente Verzögerung beim
Anheften des zweiten Docker-Netzes (`smarteinzug_internal`, in dem Redis liegt) an einen frisch per
`docker compose run` erzeugten Einwegcontainer vermutet. Diese Hypothese ist durch den Nachtrag unten
**widerlegt**: Die tatsächliche Ursache war deterministisch (kein Timing, kein Netzwerk-Race) und lag in
Redis' eigener Konfiguration.

**Behoben (Version 4.6):**

- `monitor_category()` (`app/monitor.php`) erkennt zusätzlich musl-typische DNS-Fehlertexte, „Connection
  refused“/„No route to host“ als eigene Kategorie `connection_refused`, sowie vom Server beendete
  Verbindungen („went away“, „reset by peer“) und Redis-Authentifizierungsfehler („NOAUTH“, „WRONGPASS“).
- `redis_client()` (`app/redis.php`) merkt sich den letzten Fehlschlaggrund (`redis_last_error()`, keine
  Geheimnisse) und erhält einen Parameter `forceRetry`, der den sonst einmaligen Verbindungsversuch je
  Prozess für einen erneuten Versuch umgeht.
- `bin/healthcheck.php --redis` unternimmt jetzt bis zu drei Versuche mit kurzer Pause (0,7 s) und meldet
  bei einem endgültigen Fehlschlag die konkrete Kategorie (z. B. `redis: dns`, `redis: connection_refused`)
  statt `redis: other`/`redis: nicht erreichbar`. Die Candidate-Isolation selbst (eigener, wegwerfbarer
  Container über `docker compose run --rm --no-deps`, laufende Anwendung unberührt) bleibt unverändert
  bestehen; Redis bleibt Teil der Prüfung.
- `deploy.sh` protokolliert bei einem Fehlschlag der Candidate-Prüfung, der Migration, des
  Warteschritts auf gesunde Container oder des Health-Checks nach der Aktivierung zusätzlich Phase,
  fehlgeschlagenen Befehl, Exitcode, Release-SHA und den aktuellen Containerzustand, ohne jemals
  Zugangsdaten auszugeben.
- Regressionstests: `php tools/healthcheck-redis-check.php` (Fehlerklassen, Diagnose gegen einen nicht
  auflösbaren Hostnamen und einen geschlossenen Port, sofortiger Erfolg ohne Redis) und
  `python3 tools/compose-check.py` (bestätigt statisch, dass `deploy.sh` die Reihenfolge
  Candidate-Prüfung, Migration, Cutover einhält und beide isolierten Schritte ausschließlich über
  `docker compose run --rm --no-deps` laufen).

### Nachtrag (Version 4.8): tatsächliche Ursache bestätigt – Redis' eigener „protected mode“

**Symptom:** Auf dem produktiven VPS meldete die neue, isolierte Candidate-Prüfung weiterhin einen
Redis-Fehlschlag, jetzt als „UNGESUND: redis: auth“ statt „redis: other“ (unterschiedliche
Beobachtungen zu unterschiedlichen Zeitpunkten, siehe unten). Dabei bestand kein Widerspruch zu den
eigenen Prüfungen: `shared/config.php` enthielt `'redis' => ['host' => 'redis', 'password' => null,
...]` (kein Passwort konfiguriert), `redis.conf` enthielt kein `requirepass`/`masterauth`, und
`docker exec smarteinzug-redis-1 redis-cli ping` lieferte anstandslos `PONG` – nach diesen drei
Prüfungen allein hätte alles unauffällig wirken müssen.

**Tatsächliche Ursache (durch einen echten, temporären Redis-Server direkt reproduziert, keine
Vermutung):** `redis.conf` setzte `protected-mode yes` (Redis' Standardwert), aber **ohne** ein
Passwort. In genau dieser Kombination lehnt Redis **jeden Befehl** (nicht die TCP-Verbindung selbst,
die gelingt) eines Clients ab, der **nicht über die Loopback-Adresse** (127.0.0.1/::1) verbindet – mit
der Fehlermeldung „DENIED Redis is running in protected mode … no password is set for the default
user … In this mode connections are only accepted from the loopback interface.“ Das betrifft
ausnahmslos **jeden** Zugriff aus einem anderen Container (`php`, `scheduler`, jeder `worker-*`, die
isolierte Candidate-Prüfung) – unabhängig davon, dass `bind 0.0.0.0 -::1` explizit gesetzt war (ein
gesetzter `bind` hebt den Schutz entgegen einer verbreiteten Annahme NICHT auf, nur ein gesetztes
Passwort oder `protected-mode no` tun das). Der Grund, warum `docker exec … redis-cli ping` trotzdem
`PONG` lieferte: Dieser Befehl läuft **innerhalb** des `redis`-Containers selbst und verbindet damit
zwangsläufig über dessen eigene Loopback-Adresse – genau der eine Fall, den protected-mode ausdrücklich
ausnimmt. Er täuschte damit eine Gesundheit vor, die für keinen anderen Container galt. Die
unterschiedlichen Kategorien „other“ und „auth“ waren beides nur Symptome derselben, vorher nicht
erkannten DENIED-Meldung, je nachdem, welche Fassung von `monitor_category()` gerade lief; keines der
beiden Wörter deutete auf ein falsches Passwort in `config.php` hin (dort war ja korrekt keines
gesetzt) – die eigentliche Ursache war ausschließlich Redis' eigene `protected-mode`-Einstellung.

**Praktische Auswirkung, bevor dies auffiel:** Da Redis in dieser Anwendung ausdrücklich optional ist
(Sperren und Ratenbegrenzung fallen ohne Redis automatisch auf die Datenbank zurück, siehe
`app/redis.php`), führte dies zu keinem fachlichen Fehler, aber vermutlich dazu, dass Redis seit der
Einrichtung des VPS-Stacks für Sperren/Ratenbegrenzung aus anderen Containern nie tatsächlich
funktioniert hat, ohne dass dies bislang bemerkt wurde.

**Behoben (Version 4.8):**

- `deploy/vps/redis/redis.conf`: `protected-mode no` (statt `yes`). Sicher, weil dieser Dienst ohnehin
  ausschließlich über das interne, nicht öffentlich erreichbare Docker-Netz erreichbar ist (`internal:
  true`, kein veröffentlichter Port) und kein Passwort vorgesehen ist – protected-mode böte hier keinen
  zusätzlichen Schutz, verhindert aber den vorgesehenen Betrieb. Redis' eigene Fehlermeldung nennt genau
  diese Lösung selbst („just disable protected mode … however MAKE SURE Redis is not publicly
  accessible from the internet if you do so“).
- `monitor_category()` erkennt die tatsächliche Redis-protected-mode-Meldung jetzt als eigene Kategorie
  `redis_protected_mode` (nicht als `auth`, das fälschlich ein falsches Passwort in der eigenen
  Konfiguration vermuten ließe, obwohl die Ursache eine Servereinstellung von Redis selbst ist).
- Regressionstest `tools/healthcheck-redis-check.php` erweitert: startet testweise einen echten,
  temporären Redis-Server (protected-mode yes bzw. no, kein Passwort) und prüft den Zugriff über eine
  echte, nicht-Loopback-Adresse dieses Hosts (entspricht dem Zugriffsweg eines anderen Containers) –
  bestätigt sowohl die neue Diagnosekategorie als auch, dass `protected-mode no` den Zugriff tatsächlich
  ermöglicht; zusätzlich eine statische Prüfung, dass `redis.conf` `protected-mode no` enthält.

### Nachtrag (Version 4.9): Der Fix konnte sich nicht selbst deployen (Bootstrap-Problem)

**Symptom:** Der erste produktive Deployment-Versuch von Version 4.8 (`redis.conf` auf
`protected-mode no`) scheiterte selbst wieder in der Candidate-Prüfung mit „UNGESUND: redis: auth“,
obwohl `docker exec smarteinzug-redis-1 redis-cli CONFIG GET protected-mode` bestätigte, dass der
laufende Redis-Container weiterhin `protected-mode yes` meldete, und `redis-cli ping` (Loopback)
weiterhin `PONG` lieferte. Der produktive Stack blieb dabei vollständig `healthy`, der Deploy scheiterte
VOR dem Cutover.

**Ursache:** Ein Bootstrap-/Reihenfolgeproblem, kein erneuter Diagnosefehler. Die Candidate-Prüfung
kommuniziert mit dem bereits laufenden `redis`-Dienst – der wird aber, weil `redis.conf` per Bind-Mount
eingebunden ist, von `docker compose up -d` NICHT automatisch neu erzeugt, nur weil sich der
Dateiinhalt geändert hat (die Dienstdefinition selbst – Image, Mount-Quelle, Umgebung – bleibt
gleich; Compose erkennt reine Inhaltsänderungen einer bind-gemounteten Datei nicht von selbst). Der
Candidate mit dem NEUEN Code prüfte also weiterhin gegen den ALTEN, alten Redis-Container mit der alten
Konfiguration. Ein Fix an `redis.conf` konnte sich damit strukturell nicht selbst deployen: Erst ein
manuell auf dem Server ausgeführter, gezielter Neustart des `redis`-Dienstes hätte ihn wirksam werden
lassen.

**Behoben (Version 4.9):** `deploy.sh` stellt jetzt VOR der Candidate-Prüfung fest, ob sich
`deploy/vps/redis/redis.conf` inhaltlich gegenüber dem laufenden Release geändert hat (Byte-Vergleich
der beiden Release-Ordner, nicht Compose's eigene, für Bind-Mounts unzureichende Änderungserkennung):

- **Unverändert:** Redis wird nicht angefasst, es geht direkt mit der gewohnten Candidate-Prüfung
  weiter.
- **Geändert:** Die neue Konfiguration wird zuerst in einem eigenständigen Wegwerfcontainer (kein
  Compose-Projekt, kein Netz) vorab validiert; erst danach wird **ausschließlich** der `redis`-Dienst
  gezielt neu erzeugt (`docker compose up -d --no-deps --force-recreate redis`, betrifft nachweislich
  keinen anderen Dienst), auf `healthy` gewartet und die Erreichbarkeit **aus einem anderen Container
  über das interne Docker-Netz** geprüft (`bin/healthcheck.php --redis` in einem eigenen, per
  `docker compose run --rm --no-deps` gestarteten Container – ausdrücklich NICHT
  `docker exec redis redis-cli ping`, das genau das protected-mode-Problem verborgen hatte). Erst wenn
  dieser netzwerkbasierte Test erfolgreich ist, beginnt die eigentliche Candidate-Prüfung.
- **Schlägt einer dieser Schritte fehl** (ungültige Konfiguration, Recreate schlägt fehl, Redis wird
  nicht healthy, oder Redis ist zwar healthy aber über das Netz weiterhin nicht erreichbar): Die
  Redis-Infrastruktur (nicht das gesamte Release) wird gezielt zurückgesetzt – `deploy/vps/redis/`
  wird aus dem vorherigen Release wiederhergestellt, der `redis`-Dienst erneut gezielt neu erzeugt, auf
  `healthy` gewartet und die Erreichbarkeit erneut über das interne Netz bestätigt. Das Deployment
  bricht danach ab: **keine Migration, kein Cutover**, die übrige laufende Anwendung bleibt unverändert.
- Vor jeder Redis-Änderung wird zusätzlich geprüft, dass Redis keinen veröffentlichten Host-Port hat,
  ausschließlich am internen Netz `smarteinzug_internal` hängt (nicht am öffentlichen Coolify-Netz) und
  keine Traefik-Labels trägt – Voraussetzung dafür, dass `protected-mode no` vertretbar bleibt; verletzt
  eine dieser Bedingungen, bricht das Deployment ab, bevor irgendetwas an Redis geändert wird.
- Regressionstest `tools/redis-deploy-check.sh` (neu, kein Docker-Daemon nötig: simuliertes
  `/opt/smarteinzug`, `deploy.sh` läuft unverändert gegen einen steuerbaren Fake-„docker“, dessen
  Netzwerktest-Antwort vom tatsächlichen Inhalt der jeweils aktiven `redis.conf` abhängt): bestätigt u. a.
  unveränderte `redis.conf` → kein Recreate; geänderte `redis.conf` mit erfolgreichem Netzwerktest →
  Candidate/Migration/Cutover laufen durch; `healthy`, aber über das Netz blockiert → Abbruch mit
  bestätigtem Rollback der Redis-Infrastruktur, keine Migration, kein Cutover; ungültige neue `redis.conf`
  → schon die Vorab-Validierung bricht ab, der laufende Redis-Dienst bleibt unberührt; verletzte
  Netzwerk-Isolationsvorgaben → Abbruch vor jeder Änderung; eine Wiederholung bleibt idempotent.
  `tools/staging-isolation-check.py` bestätigt zusätzlich, dass Redis in Produktion UND Staging jeweils
  keinen Host-Port hat, ausschließlich am internen Netz hängt und keine Traefik-Labels trägt.

### Nachtrag (Version 4.10): Redis-Alias-Kollision mit Coolifys eigenem Redis – die tatsächliche Root Cause

**Symptom (Run #43):** Der neue Redis-Dienst wurde mit `protected-mode no` gestartet und war `healthy`,
trotzdem scheiterte der netzwerkbasierte Test aus einem anderen Container weiterhin, jetzt mit
„redis: other“. Der Redis-Rollback lief technisch korrekt durch (alte `redis.conf`, Dienst neu erzeugt,
`healthy`, `CONFIG GET protected-mode` wieder `yes`), alle produktiven Container blieben `healthy`. Damit
war klar: `protected-mode` allein erklärt den Fehler nicht.

**Root Cause (aus Primärquellen belegt, adversarial gegengeprüft):** Der Hostname `redis` ist auf einem
Coolify-Server **mehrdeutig**. Unsere PHP-Container hängen an zwei Netzen: `coolify` (Coolify-MariaDB,
Internet) und `smarteinzug_internal` (Caddy, unser Redis). Coolifys eigener Stack
(`coollabsio/coolify`, `docker-compose.yml` + `docker-compose.prod.yml`) definiert ebenfalls einen Dienst
mit dem Namen `redis` (Container `coolify-redis`, Netz `coolify`, gestartet mit
`redis-server … --requirepass ${REDIS_PASSWORD}`). Compose trägt den Dienstnamen als DNS-Alias in jedes
Netz ein, das der Dienst nutzt, also existiert der Alias `redis` **in beiden** Netzen unserer
PHP-Container. Dockers eingebetteter DNS (`moby/moby`, `daemon/libnetwork/sandbox.go`,
`Sandbox.resolveName`) fragt die Netze eines Containers in einer festen Reihenfolge ab und liefert den
**ersten Treffer**, ohne die anderen Netze noch zu betrachten. Die Reihenfolge (`Endpoint.Less`) sortiert
Netze **ohne** `internal: true` vor internen Netzen; `coolify` (nicht intern) kommt damit vor
`smarteinzug_internal` (intern). Ergebnis: `redis` löste aus jedem unserer PHP-Container zu **Coolifys**
Redis auf. Die TCP-Verbindung gelang, der erste Befehl (`PING`) erhielt `NOAUTH Authentication required.`

Das erklärt **alle** bisherigen Beobachtungen lückenlos und wurde lokal gegen einen echten,
passwortgeschützten Redis nachgestellt:

| Beobachtung | Erklärung |
|---|---|
| Run #39: „redis: other“ | `NOAUTH …` traf auf die damalige `monitor_category()` (kein `noauth`-Muster) → `other`. Die protected-mode-Meldung „DENIED …“ hätte dagegen wegen des Wortes „connections“ bereits damals `connection` ergeben, nie `other`. |
| danach: „redis: auth“ | dieselbe `NOAUTH`-Meldung mit der erweiterten `monitor_category()` (Version 4.6) → `auth`. Die Klassifizierung war **richtig**: Die Gegenstelle verlangte tatsächlich ein Passwort, nur war es nicht unser Redis. |
| Run #43: „redis: other“ trotz `protected-mode no` und `healthy` | Der netzwerkbasierte Test lief absichtlich mit dem **alten** Release-Code (`PREV_SHA`, dort noch die alte `monitor_category()`) → `NOAUTH` → `other`. Der neue Redis war nie die Gegenstelle. |
| `docker exec smarteinzug-redis-1 redis-cli ping` → `PONG` | läuft im Redis-Container selbst, ohne DNS-Auflösung von `redis`; sagt nichts über die Auflösung aus anderen Containern aus. |
| Datenbankprüfung immer unauffällig | Der Coolify-MariaDB-Containername ist eindeutig, es gibt keinen zweiten Träger dieses Namens. |

**Was von Version 4.8 bestehen bleibt:** `protected-mode yes` ohne Passwort blockiert Zugriffe von
Nicht-Loopback-Clients nachweislich (lokal reproduziert). Es war ein zweiter, latenter Fehler, der
gegriffen hätte, sobald `redis` tatsächlich zu unserem Redis aufgelöst wäre; `protected-mode no` bleibt
deshalb korrekt und notwendig. Er war aber **nicht** die Ursache der beobachteten Meldungen.

**Behoben (Version 4.10):**

- Unser Redis-Dienst trägt in `docker-compose.yml` zusätzlich den eindeutigen Alias `smarteinzug-redis`
  (nur im internen Netz; Coolifys Container hängen dort nicht). Alle Dienste aus dem PHP-Image erhalten
  `SMARTEINZUG_REDIS_HOST=smarteinzug-redis` und `SMARTEINZUG_REDIS_EXPECTED_CIDR=172.28.0.0/24` (Subnetz
  von `smarteinzug_internal`) aus dem Stack. `app/redis.php` (`redis_effective_host()`) bevorzugt diese
  Variable vor `config('redis')['host']`; **`shared/config.php` muss dafür nicht geändert werden**
  (`'host' => 'redis'` bleibt als Fallback für IONOS/lokale Tests). Bewusst kein Verlass auf die
  Sortierreihenfolge des Docker-DNS (etwa über `com.docker.network.priority`): Sie ist ein
  Implementationsdetail, der eindeutige Alias ist ein dokumentierter Compose-Vertrag.
- `bin/healthcheck.php --redis` diagnostiziert stufenweise (`redis_probe()`): Auflösung des Hostnamens
  (`alias_missing` für einen fehlenden Docker-Alias, `dns` für einen voll qualifizierten Namen), Netz
  (`network_mismatch`, wenn der Name in ein anderes Netz auflöst als vom Stack erwartet, genau der
  Coolify-Fall; `alias_ambiguous` bei mehreren Adressen), TCP (`connection_refused`, `timeout`,
  `network_unreachable`), Redis-Protokoll (`redis_protected_mode`, `auth`, `protocol`). Bei einem
  Fehlschlag steht eine `DIAGNOSE redis: host=… aufgeloest=… erwartet=… stufe=… kategorie=… meldung="…"`-Zeile
  im Deployment-Protokoll (Passwörter maskiert, Originalmeldung gekürzt). Nur transiente Stufen werden
  wiederholt; `auth`, `network_mismatch`, `protected_mode`, `protocol` sofort gemeldet.
- `deploy.sh` führt den netzwerkbasierten Redis-Test jetzt **immer mit dem Code des neuen Release** aus
  (nur er kennt `SMARTEINZUG_REDIS_HOST` und liefert die DIAGNOSE-Zeile); ein älteres Release würde
  weiter den mehrdeutigen Namen `redis` verwenden und damit erneut Coolifys Redis treffen.
- **Rollback-Bewertung getrennt:** Technische Wiederherstellung (alte `redis.conf` zurückkopiert, Dienst
  neu erzeugt, `healthy`) sind die harten Kriterien; der erneute Netzwerktest gegen den alten Zustand ist
  nur eine **Warnung**, denn der alte Zustand kann bekanntermaßen scheitern (genau deshalb wurde ja
  ausgeliefert). Der Rollback nach Run #43 war damit technisch erfolgreich; die Anwendung arbeitet in
  diesem Zustand wie zuvor mit dem Datenbank-Fallback ohne Redis.

**Was auf dem VPS nach dem Deployment zu erwarten ist:** Die Candidate-Prüfung und der Netzwerktest
melden `OK`; `docker compose … exec php getent hosts smarteinzug-redis` liefert eine Adresse aus
`172.28.0.0/24`, während `getent hosts redis` aus einem PHP-Container weiterhin Coolifys Redis liefern
kann – das ist jetzt unerheblich, weil kein Code diesen Namen mehr verwendet.

**Regressionstests (was echt ist und was simuliert):** `tools/healthcheck-redis-check.php` Teil 9 prüft
gegen **echte** lokale Redis-Server: passwortgeschütztes fremdes Redis ohne Passwort → `auth`
(DIAGNOSE `stufe=redis`, `NOAUTH`, Passwortwert nirgends in der Ausgabe), korrektes Passwort → `OK`,
Auflösung außerhalb des erwarteten CIDR → `network_mismatch`, im CIDR → `OK`, fehlender einteiliger Alias
→ `alias_missing`, `SMARTEINZUG_REDIS_HOST` schlägt `config.php`. `tools/staging-isolation-check.py`
bestätigt per `docker compose config` für Produktion und Staging: Alias `smarteinzug-redis` nur im
internen Netz, jeder PHP-Dienst mit passendem `SMARTEINZUG_REDIS_HOST` und CIDR, PHP-Dienste und Redis im
selben internen Netz, kein Alias im Coolify-Netz. `tools/redis-deploy-check.sh` (Fake-`docker`, echte
`deploy.sh`) prüft: fehlender Alias → Abbruch mit `alias_missing`-Diagnose und **technisch
erfolgreichem** Rollback auf den bekannt defekten Altzustand (nur Warnung), Netzwerktest läuft mit
`RELEASE_SHA` des neuen Release, alle Compose-Aufrufe treffen dasselbe Projekt, jeder `run` verwendet
`--no-deps`, Reihenfolge Recreate → Netzwerktest → Candidate → Migration → Cutover. **Nicht** lokal
prüfbar (kein Docker-Daemon in der Entwicklungsumgebung): die tatsächliche Docker-DNS-Auflösung des
Alias und die Netzmitgliedschaft eines echten `compose run`-Containers; beides ist über die zitierten
Primärquellen belegt und wird beim nächsten Deployment durch die DIAGNOSE-Zeile sichtbar.

## Staging- und Produktionsisolation

Eine Prüfung auf dem produktiven VPS (`docker compose config`, ohne einen tatsächlichen Staging-Start)
ergab, dass Staging und Produktion denselben Compose-Projektnamen erbten (`name: smarteinzug` nur in
der Basis-Datei gesetzt) und dadurch dieselben Container-, Netz- und Volume-Namen erhalten hätten
(z. B. `smarteinzug_smarteinzug_internal`, `smarteinzug_caddy_data`); ein versehentlicher
`docker compose up -d` für Staging auf demselben Host hätte diese produktiven Ressourcen neu erzeugen
oder überschreiben können. Zusätzlich verwendeten beide Umgebungen identische Traefik-Router-,
Middleware- und Dienstnamen (nur der Wert der Host()-Regel unterschied sich) – auf demselben
Coolify-Proxy hätte der zuletzt gestartete Container (Produktion oder Staging) den Router des jeweils
anderen überschrieben, unabhängig vom Compose-Projektnamen (Traefik kennt keine Compose-Projekte).

**Behoben:**

- `docker-compose.staging.yml` setzt jetzt einen eigenen Projektnamen (`name: smarteinzug-staging`).
  Compose leitet daraus automatisch eigene Namen für jede verwaltete Ressource ab: Container
  (`smarteinzug-staging-caddy-1` statt `smarteinzug-caddy-1` usw.), das interne Netz
  (`smarteinzug-staging_smarteinzug_internal`) und die Volumes (`smarteinzug-staging_caddy_data`,
  `smarteinzug-staging_caddy_config`). Das externe Coolify-Netz (`coolify`) bleibt bewusst geteilt
  (beide Umgebungen brauchen den Coolify-Proxy), ist aber `external: true` und wird von keiner
  Umgebung verwaltet oder verändert.
- Die Traefik-Labels des `caddy`-Dienstes stehen nicht mehr in der gemeinsamen `docker-compose.yml`,
  sondern ausschließlich in `docker-compose.prod.yml` (Namen `smarteinzug-*`, unverändert) bzw.
  `docker-compose.staging.yml` (umbenannt auf `smarteinzug-staging-*`). Keine der beiden Umgebungen
  kann dadurch mehr die Router-Definition der anderen überschreiben, selbst wenn beide zufällig am
  selben Coolify-Proxy hängen.
- **Besonders kritisch: Datenbank.** Die Compose-Ebene kann eine externe Coolify-MariaDB-Ressource
  nicht technisch von einer anderen unterscheiden (der Containername steht in `shared/config.php`,
  einer Host-Datei außerhalb des Git-Releases). Die primäre Absicherung bleibt deshalb organisatorisch:
  Staging läuft auf einem eigenen, physisch getrennten Server mit eigener Coolify-MariaDB und eigener
  `shared/config.php` (siehe `docs/vps/02-einrichtung-vps.md`, Kapitel 25). Als zusätzliches,
  technisches Sicherheitsnetz prüft die ohnehin schon isolierte Candidate-Prüfung jedes Deployments
  (siehe oben) und jeder Rollback zusätzlich `bin/healthcheck.php --expect-env=$DEPLOY_ENV`: Ein
  Staging-Deploy VERLANGT dafür zwingend `'environment' => 'staging'` in `config.php` (siehe
  `app/config.example.php`) und bricht sonst ab – auch wenn Staging versehentlich dieselbe `config.php`
  wie Produktion einbinden würde (z. B. bei irrtümlicher Co-Lokation auf demselben Host), fiele das
  hier auf, bevor Migrationen oder ein Cutover stattfinden. Ein Produktions-Deploy bleibt ohne dieses
  Feld rückwärtskompatibel unauffällig (bestehende Installationen müssen `config.php` nicht sofort
  ändern); nur ein expliziter Widerspruch (Konfiguration meldet „staging“) lässt einen
  Produktions-Deploy ebenfalls fehlschlagen.
- **Worker-Sicherheit.** Da ein Staging-Deploy mit fehlender oder falscher Umgebungskennzeichnung
  bereits in der Candidate-Prüfung abbricht, kann Staging strukturell nicht unbemerkt gegen die
  produktive Datenbank laufen – und dort liegen die verschlüsselten, produktiven
  Stripe-/Lexware-Zugangsdaten der Firmen. Ergänzend prüft `--expect-env=staging`, dass die
  Plattform-Abrechnung (`billing.stripe_secret_key`, das Stripe-Konto der Müller Holding AG für das
  Abo selbst) in Staging keinen Live-Schlüssel (`sk_live_...`) verwendet.
- Regressionstest `tools/staging-isolation-check.py` (kein Docker-Daemon nötig, nur
  `docker compose ... config`): prüft anhand der tatsächlich aufgelösten Konfiguration, dass
  Projektname, Volume-Namen und Netzname von Produktion und Staging sich unterscheiden, dass sich die
  Traefik-Router-/Middleware-/Dienstnamen NICHT überschneiden, dass die Host()-Regeln nicht die Domain
  der jeweils anderen Umgebung enthalten, und dass `bin/healthcheck.php`, `deploy.sh` und
  `rollback.sh` den `--expect-env`-Schutz tatsächlich verwenden.

**Was das NICHT ersetzt:** Ein physisch getrennter Server für Staging bleibt die empfohlene und
sicherste Vorgehensweise (siehe `docs/vps/02-einrichtung-vps.md`, Kapitel 25). Die hier beschriebenen
Maßnahmen sind zusätzliche, technisch erzwungene Sicherheitsnetze für den Fall menschlicher Fehler
(falsche `.env`, falsche `config.php`, versehentliche Co-Lokation), kein Ersatz für die Servertrennung.

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

Die tägliche Sicherung übernimmt ausschließlich Coolify, produktiv eingerichtet und vom Betreiber
bestätigt: tägliche Sicherung der Coolify-MariaDB-Ressource mit zusätzlichem externem Upload in
einen Hetzner-Object-Storage-Bucket, Restore laut Betreiber getestet. Es gibt keinen zweiten
Dump-Container im SmartEinzug-Stack. Der Metrik-Sammler (`metrics`) liest nur die lokale Kopie der
Coolify-Sicherungen (`COOLIFY_BACKUP_DIR`, lesend eingebunden) und schreibt Zeitpunkt und Größe der
neuesten Sicherung als `backup-status.json` in den gemeinsamen Speicher; darüber zeigt der
Adminbereich System (Reiter Server) die Komponente „Sicherungen“ (älter als 26 Stunden gilt als
eingeschränkt). Löscht Coolify lokale Kopien nach dem externen Upload, zeigt die Komponente „nicht
eingerichtet“ beziehungsweise veraltet an; dann in Coolify die lokale Aufbewahrung aktivieren (auf
dem Server zu prüfen). Externer Upload und Restore werden ausschließlich in Coolify geprüft, nicht
in der Anwendung selbst.

Zusätzlicher Wiederherstellungstest gegen einen aus Coolify heruntergeladenen Dump:

```bash
docker run --rm -it --network coolify \
  -v /pfad/zum/dump:/dump:ro -v "$PWD/deploy/vps/backup:/tools:ro" \
  -e DB_HOST=<containername-der-coolify-mariadb> -e DB_ROOT_PASSWORD=<root-passwort-aus-coolify> \
  mariadb:11 bash /tools/restore-test.sh /dump/<datei>.sql.gz
```

`restore-test.sh` läuft dafür in einem kurzlebigen Client-Container im Netz `coolify` (kein
veröffentlichter Port nötig), spielt den Dump in eine isolierte, temporäre Testdatenbank ein und
prüft, dass der Import ohne Fehler durchläuft, ohne die produktive Datenbank zu berühren. Diesen
Test regelmäßig wiederholen (empfohlen: monatlich), nicht nur einmalig bei der Einrichtung.
`deploy/vps/backup/backup.sh` und das zugehörige `Dockerfile` sind ausschließlich eine
Ausweichlösung ohne Coolify und nicht Teil dieses Stacks.

## Updates

**Betriebssystem:** `unattended-upgrades` ist über `scripts/setup-vps.sh` bereits eingerichtet
(automatische Sicherheitsaktualisierungen). Reguläre, nicht sicherheitskritische Updates weiterhin
von Hand und zu einem geplanten Zeitpunkt:

```bash
sudo apt-get update && sudo apt-get upgrade -y
```

Nach einem Kernel-Update einen Neustart des Servers einplanen (außerhalb der Geschäftszeiten,
vorher Wartungsmodus aktivieren).

**Docker-Images:** Basis-Images des SmartEinzug-Stacks (PHP, Redis, Caddy) regelmäßig aktualisieren:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Die Coolify-MariaDB ist kein Dienst dieses Stacks; ihre Versionsaktualisierung erfolgt in Coolify
selbst (Bereich der Datenbankressource), nicht über diesen `pull`/`up`-Zyklus.

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
