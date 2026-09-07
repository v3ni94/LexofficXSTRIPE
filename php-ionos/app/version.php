<?php
/**
 * Versionsstand der Anwendung und Änderungsverlauf (Versionsübersicht im Adminbereich).
 *
 * Schema: erste Stelle für große Ausbaustufen (1.0, 2.0, 3.0 ...), zweite Stelle für kleinere
 * Ergänzungen und Korrekturen (2.1, 2.2 ...). Jeder Eintrag nennt Datum, Art (Neu, Geändert, Behoben)
 * und kurze Erklärung. Bei jedem Release die Konstante APP_VERSION und die Liste ergänzen.
 */
declare(strict_types=1);

const APP_VERSION = '4.11';

/** Änderungsverlauf, neueste Version zuerst. */
function app_changelog(): array
{
    return [
        ['version' => '4.11', 'date' => '07.09.2026', 'title' => 'Deploymentstatus sichtbar, Worker-Shutdown in Sekunden statt 11 Minuten',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'GitHub meldete fälschlich eine Zeitüberschreitung, obwohl der serverseitige Deploy lief und erfolgreich endete: deploy-status.sh lieferte durchgehend "phase: unknown". Ursache: deploy.sh (und rollback.sh) übernehmen deploy/vps per rsync --delete nach /opt/smarteinzug/deploy und schlossen die Laufzeitdateien .deploy-status.json und .deploy.pid nicht aus; die vom Deploy-Runner Sekunden zuvor geschriebene Statusdatei wurde gelöscht und erst nach dem Ende neu geschrieben. Die Excludes umfassen jetzt alle Laufzeitdateien (/.deploy*, /.release*, /.previous_sha, /.php-image.sha256, /.env); deploy.sh trägt zusätzlich den aktuellen Schritt atomar in die Statusdatei ein (Feld step), das GitHub-Polling zeigt jeden Phasen-/Schrittwechsel.'],
            ['type' => 'Behoben', 'text' => 'Scheduler- und Worker-Container brauchten beim Deployment bis zu 11 Minuten ("Container failed to exit within 11m0s of signal 3"). Ursache: Das Basisimage php:8.4-fpm-alpine setzt STOPSIGNAL SIGQUIT; Worker und Scheduler behandelten nur SIGTERM/SIGINT, und ein PID-1-Prozess ohne Handler bekommt das Signal vom Kernel verworfen; stop_grace_period 660 s ließ Docker dann 11 Minuten warten, und deploy.sh startete die Worker danach nochmals mit derselben Frist neu. Jetzt: stop_signal SIGTERM und stop_grace_period 75 s für Scheduler/Worker (20 s für den Metrik-Sammler), php läuft direkt als PID 1 (kein sh -c), die Prozesse behandeln SIGTERM, SIGINT und SIGQUIT.'],
            ['type' => 'Neu', 'text' => 'Kontrollierter Worker-Shutdown (app/worker_signals.php): Nach dem Stop-Signal wird kein neuer Job mehr angenommen; ein laufender Job endet am nächsten kooperativen Abbruchpunkt (Synchronisation nach jedem Schritt, Einzüge zwischen zwei Einzügen) als Fortsetzung ohne Fehlversuch; für unterbrechbare Jobtypen zieht nach 30 s eine Notbremse (SIGALRM, kontrollierte Fortsetzung). Geldbewegende Jobs (Einzüge, unklare Versuche) werden nie mitten im Ablauf unterbrochen. Ein dennoch hart beendeter Job wird nach heartbeat_ttl automatisch wieder freigegeben. Offene Datenbanktransaktionen werden vor der Statusänderung zurückgerollt.'],
            ['type' => 'Geändert', 'text' => 'Kein zweiter Neustart von Scheduler/Workern nach dem Cutover mehr: working_dir ist Teil des Compose-Konfigurations-Hash, "up -d" erzeugt die Container bei jedem Releasewechsel zwingend neu. Statt des Neustarts verifiziert deploy.sh (und rollback.sh) per docker inspect, dass jeder PHP-Container läuft und das neue Release als working_dir trägt; bei Abweichung Rollback. Typische Deploymentdauer damit wenige Minuten statt über 20.'],
            ['type' => 'Geändert', 'text' => 'Redis-Infrastruktur: Neben redis.conf löst jetzt auch eine geänderte Compose-Definition des redis-Dienstes (Konfigurations-Hash, z. B. neuer Alias) die kontrollierte Aktualisierung vor der Candidate-Prüfung aus; die Gesundheitsprüfung verlangt mindestens einen Container mit Health "healthy" (leere Ausgabe gilt nicht mehr als gesund). Redis-Diagnose: IPv6-Adressen und Unix-Sockets korrekt, CIDR-Prüfung lehnt ungültige Angaben ab, Lese-Timeout 1,5 s, DIAGNOSE-Zeile auch bei Erfolg, Kategorien loading/busy/protocol, Circuit Breaker berücksichtigt protocol/connection_refused.'],
            ['type' => 'Neu', 'text' => 'GitHub-Polling in .github/scripts/vps-wait-status.sh ausgelagert (Frist unverändert 12 Minuten, Phase/Schritt protokolliert, Hinweis auf deploy-status.sh --tail bei Zeitüberschreitung). Regressionstests: tools/deploy-runner-check.sh (echte deploy.sh unter dem Runner: Status sofort running, überlebt den rsync, gültiges JSON bei jeder Abfrage, success/failed, --tail, keine Geheimnisse), tools/github-poll-check.sh (running/success/failed/unknown/SSH-Abbruch/sha-Abweichung/Frist), tools/worker-signal-check.sh (echte PHP-Prozesse und echte bin/worker.php gegen eine temporäre MariaDB: Stop-Signale, kein neuer Job, Notbremse, Geldfluss-Jobs unangetastet, Queue-Semantik), tools/compose-check.py (stop_signal, Grace-Period, php als PID 1, rsync-Excludes, kein restart -t).'],
            ['type' => 'Behoben', 'text' => 'Adversariale Prüfung des Signalmodells: Die Notbremse bei einer laufenden Synchronisation wurde durch catch-Throwable-Schichten (sync_state_step, job_sync_run) in einen Fehlversuch mit Backoff (bis zu 1 h) und einen sichtbaren last_error umgedeutet. Jetzt reichen diese Stellen die WorkerShutdownException durch (sync_state_step gibt nur die Sperre frei, kein fehlgeschlagener Lauf), und job_execute() bestimmt die Ergebnisklasse zentral über worker_job_exception_outcome(): Nach einer ausgelösten Notbremse zählt jede Ausnahme des Jobs als Fortsetzung ohne Fehlversuch. Wartung reicht die Notbremse zwischen Teilaufgaben durch, mail_send_direct wertet sie nicht als Transportfehler.'],
            ['type' => 'Behoben', 'text' => 'Erstes Deployment ab 4.11 hätte nochmals 11 Minuten gedauert: Docker stoppt einen Container beim Neuerzeugen mit der Stop-Konfiguration des laufenden Containers (SIGQUIT, 660 s bei allen Containern bis 4.10). deploy.sh prüft vor dem Cutover per docker inspect StopSignal/StopTimeout der Hintergrund-Container und beendet veraltete vorab gezielt mit docker stop --signal SIGTERM --timeout 90 (idempotent, danach wirkungslos; greift erneut nach einem Rollback auf ein älteres Release).'],
            ['type' => 'Behoben', 'text' => 'Statusdatei: deploy-status.sh --tail fand während des Laufs die Protokolldatei nicht (deploy_step formatierte per jq mehrzeilig, das sed-Muster erwartete das kompakte Format); jetzt jq -c und eine formatunabhängige Auswertung. deploy-runner.sh belegt die Statusdatei bereits im Vordergrund unter der Sperre mit running und dem neuen sha vor, damit das GitHub-Polling nie das Ergebnis des vorherigen Laufs (alter sha, alter Fehlstatus) als aktuelles Ergebnis liest.'],
            ['type' => 'Behoben', 'text' => 'deploy.sh/rollback.sh: Zuweisungen mit Compose-Pipelines (config --hash, ps -q, config --images) beendeten das Skript unter set -euo pipefail bei einem Fehler des Docker-CLI still, ohne Meldung und ohne Rollback, auch in der Bindungsprüfung nach dem Cutover; jetzt || true mit regulärer Fehlerbehandlung. deploy_step() kann das Deployment nicht mehr durch ein fehlgeschlagenes mv beenden. rollback.sh prüft --expect-env und den Migrationsstand in einem isolierten Wegwerfcontainer, wenn der php-Container nicht läuft (automatischer Rollback aus der Bindungsprüfung). Meldung des Redis-Rückbaus ehrlich, wenn nur die Compose-Definition geändert war.'],
            ['type' => 'Geändert', 'text' => 'Signalmodell nachgeschärft: unclear_attempts erhält kooperative Abbruchpunkte (zwischen Firmen und vor jedem Stripe-Aufruf) und endet damit innerhalb der Grace-Period; mail ist nicht mehr erzwungen unterbrechbar (SMTP-Dialog zwischen Annahme und Rückkehr); Wartung prüft das Stop-Signal zwischen Teilaufgaben; Signalhandler werden vor dem ersten Datenbankzugriff installiert (kein verworfenes SIGTERM während eines blockierten Verbindungsaufbaus); WORKER_STOP_JOB_SECONDS ist auf 30 begrenzt; Herleitung der 75 s ehrlich als Normalfall dokumentiert, heartbeat_ttl-Spanne korrigiert (120 bis 1800 s).'],
            ['type' => 'Geändert', 'text' => 'Regressionstests erweitert: worker-signal-check.sh beendet die temporäre MariaDB zuverlässig (zuvor blieb je Lauf ein Prozess zurück) und prüft die Ergebnisklasse nach umgedeuteter Notbremse sowie den Schutz von mail; redis-deploy-check.sh prüft Redis, das nie healthy wird oder ohne Container erscheint, den Vorab-Stopp veralteter Container und den ehrlichen Rückbau-Hinweis; deploy-runner-check.sh prüft --tail während des Laufs und die Vorbelegung gegen einen veralteten Status; Sandbox-Verzeichnisse werden auch bei Abbruch aufgeräumt.'],
         ]],
        ['version' => '4.10', 'date' => '07.09.2026', 'title' => 'Root Cause Redis: Alias-Kollision mit Coolifys eigenem Redis',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der Hostname "redis" war auf dem Coolify-Server mehrdeutig: Coolifys eigener Stack führt einen Dienst "redis" (Container coolify-redis, mit Passwort) im gemeinsam genutzten Netz "coolify", an dem unsere PHP-Container für Datenbank und Internet hängen. Dockers eingebetteter DNS liefert den Alias aus dem Netz ohne internal: true zuerst, also Coolifys Redis; der erste Befehl erhielt "NOAUTH Authentication required" (gemeldet als "redis: other" mit alten bzw. "redis: auth" mit neuen Kategorien), obwohl unser Redis kein Passwort verlangt. Aus Primärquellen (Coolify-Compose-Dateien, Docker-Engine-Quelltext) belegt und lokal gegen einen echten passwortgeschützten Redis nachgestellt. Unser Redis trägt jetzt den eindeutigen Alias "smarteinzug-redis" (nur im internen Netz); alle PHP-Dienste erhalten SMARTEINZUG_REDIS_HOST und SMARTEINZUG_REDIS_EXPECTED_CIDR aus dem Stack, app/redis.php bevorzugt diese Variable vor config.php. shared/config.php muss nicht geändert werden. protected-mode no (Version 4.8) bleibt als Behebung eines zweiten, latenten Fehlers bestehen.'],
            ['type' => 'Geändert', 'text' => 'bin/healthcheck.php --redis diagnostiziert stufenweise (Auflösung, erwartetes Netz, TCP, Redis-Protokoll) und meldet statt "other" eine konkrete Kategorie: alias_missing, dns, network_mismatch, alias_ambiguous, connection_refused, timeout, network_unreachable, redis_protected_mode, auth, protocol. Bei einem Fehlschlag steht eine DIAGNOSE-Zeile (Host, aufgelöste Adressen, erwartetes Netz, Stufe, Kategorie, gekürzte Originalmeldung, Passwörter maskiert) im Deployment-Protokoll. Nur transiente Stufen werden wiederholt.'],
            ['type' => 'Geändert', 'text' => 'deploy.sh führt den netzwerkbasierten Redis-Test immer mit dem Code des neuen Release aus (nur er kennt den eindeutigen Hostnamen). Der Rollback der Redis-Infrastruktur wird getrennt bewertet: technische Wiederherstellung (redis.conf zurück, Dienst neu erzeugt, healthy) als harte Kriterien, der Netzwerktest gegen den alten Zustand nur als Warnung, da der alte Zustand bekanntermaßen scheitern kann.'],
            ['type' => 'Neu', 'text' => 'Regressionstests: tools/healthcheck-redis-check.php stellt die Alias-Kollision gegen einen echten passwortgeschützten Redis nach (auth, NOAUTH, kein Passwort in der Ausgabe) und prüft network_mismatch, alias_missing sowie den Vorrang von SMARTEINZUG_REDIS_HOST; tools/staging-isolation-check.py bestätigt Alias, Stack-Variablen und gemeinsames internes Netz für Produktion und Staging; tools/redis-deploy-check.sh prüft fehlenden Alias mit technisch erfolgreichem Rollback auf den alten Zustand, Netzwerktest mit dem neuen Release, dasselbe Compose-Projekt für alle Aufrufe, --no-deps und die Reihenfolge Recreate, Netzwerktest, Candidate, Migration, Cutover.'],
         ]],
        ['version' => '4.9', 'date' => '07.09.2026', 'title' => 'Redis-Infrastruktur deploybar machen (Bootstrap-Problem behoben)',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der Redis-protected-mode-Fix (Version 4.8) konnte sich nicht selbst deployen: Die Candidate-Prüfung kommuniziert mit dem bereits laufenden redis-Dienst, der aber wegen des Bind-Mounts von redis.conf nicht automatisch neu erzeugt wird, nur weil sich der Dateiinhalt geändert hat. Der Candidate mit neuem Code prüfte deshalb weiterhin gegen den alten, unveränderten Redis-Container. deploy.sh stellt jetzt vor der Candidate-Prüfung fest, ob sich redis.conf gegenüber dem laufenden Release geändert hat: unverändert bleibt Redis unberührt; geändert wird ausschließlich der redis-Dienst vorab validiert, gezielt neu erzeugt (kein anderer Dienst betroffen), auf healthy geprüft und die Erreichbarkeit aus einem ANDEREN Container über das interne Netz bestätigt (nicht per "docker exec redis redis-cli ping", das genau das protected-mode-Problem verborgen hatte) - erst danach beginnt die eigentliche Candidate-Prüfung.'],
            ['type' => 'Neu', 'text' => 'Schlägt die Redis-Aktualisierung fehl (ungültige Konfiguration, Recreate schlägt fehl, nicht healthy, oder healthy aber über das Netz blockiert), wird ausschließlich die Redis-Infrastruktur auf die vorherige Konfiguration zurückgesetzt und deren Erreichbarkeit erneut bestätigt; das Deployment bricht danach ab, ohne Migration und ohne Cutover, die übrige laufende Anwendung bleibt unverändert.'],
            ['type' => 'Neu', 'text' => 'Vor jeder Redis-Änderung wird zusätzlich geprüft, dass Redis keinen veröffentlichten Host-Port hat, ausschließlich am internen Netz smarteinzug_internal hängt (nicht am öffentlichen Coolify-Netz) und keine Traefik-Labels trägt - Voraussetzung dafür, dass protected-mode no vertretbar bleibt; verletzt eine dieser Bedingungen, bricht das Deployment ab, bevor irgendetwas an Redis geändert wird.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/redis-deploy-check.sh (kein Docker-Daemon nötig): simuliert /opt/smarteinzug mit einem steuerbaren Fake-"docker", gegen den die tatsächliche deploy.sh unverändert läuft; bestätigt u. a. kein Recreate bei unveränderter redis.conf, kontrollierte Aktualisierung vor der Candidate-Prüfung bei geänderter redis.conf, Abbruch mit bestätigtem Rollback bei über das Netz blockiertem Redis, Erkennung einer ungültigen Konfiguration bereits in der Vorab-Validierung, Abbruch bei verletzten Netzwerk-Isolationsvorgaben, Idempotenz bei Wiederholung. tools/staging-isolation-check.py bestätigt zusätzlich die Redis-Netzwerk-Isolation in Produktion und Staging.'],
         ]],
        ['version' => '4.8', 'date' => '07.09.2026', 'title' => 'Tatsächliche Ursache des Redis-Fehlschlags: protected mode',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Die Candidate-Prüfung meldete weiterhin einen Redis-Fehlschlag ("redis: auth" bzw. zuvor "redis: other"), obwohl config.php korrekt kein Passwort und redis.conf kein requirepass enthielt und "docker exec smarteinzug-redis-1 redis-cli ping" PONG lieferte. Tatsächliche, durch einen echten temporären Redis-Server bestätigte Ursache: redis.conf setzte protected-mode yes ohne Passwort; in dieser Kombination lehnt Redis jeden Befehl (nicht die TCP-Verbindung) eines NICHT über Loopback verbindenden Clients ab, also jeden Zugriff aus einem anderen Container. "docker exec ... redis-cli ping" lief dagegen selbst über Loopback und täuschte deshalb Gesundheit vor, die für keinen anderen Container galt. redis.conf setzt jetzt protected-mode no (sicher, da Redis ohnehin nur im internen, nicht öffentlich erreichbaren Docker-Netz erreichbar ist).'],
            ['type' => 'Geändert', 'text' => 'monitor_category() erkennt die Redis-protected-mode-Meldung als eigene Kategorie redis_protected_mode statt sie fälschlich als auth (falsches Passwort in der eigenen Konfiguration vermutet, was hier nie die Ursache war) oder other zu melden.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/healthcheck-redis-check.php erweitert: startet testweise einen echten, temporären Redis-Server (protected-mode yes/no) und prüft den Zugriff über eine echte, nicht-Loopback-Adresse dieses Hosts; bestätigt sowohl die neue Diagnosekategorie als auch, dass protected-mode no den Zugriff tatsächlich ermöglicht, sowie eine statische Prüfung, dass redis.conf protected-mode no enthält.'],
         ]],
        ['version' => '4.7', 'date' => '07.09.2026', 'title' => 'Staging- und Produktionsisolation im VPS-Stack',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Eine Prüfung auf dem produktiven VPS (nur docker compose config, kein Start) ergab, dass Staging und Produktion denselben Compose-Projektnamen erbten und dadurch dieselben Container-, Netz- und Volume-Namen erhalten hätten (z. B. "smarteinzug_smarteinzug_internal", "smarteinzug_caddy_data"); zusätzlich verwendeten beide Umgebungen identische Traefik-Router-/Middleware-/Dienstnamen. docker-compose.staging.yml setzt jetzt einen eigenen Projektnamen ("smarteinzug-staging") und eigene Traefik-Namen ("smarteinzug-staging-*"); die Traefik-Labels von Produktion stehen dafür nicht mehr in der gemeinsamen docker-compose.yml, sondern ausschließlich in docker-compose.prod.yml.'],
            ['type' => 'Neu', 'text' => 'Zusätzliches technisches Sicherheitsnetz gegen einen versehentlichen Staging-Deploy oder -Rollback gegen die Produktionskonfiguration: shared/config.php erhält ein Feld "environment" (prod/staging, siehe app/config.example.php); die isolierte Candidate-Prüfung jedes Deployments und jeder Rollback rufen bin/healthcheck.php --expect-env=$DEPLOY_ENV auf und brechen ab, bevor Migrationen oder ein Cutover stattfinden, wenn die Konfiguration nicht zur erwarteten Umgebung passt (ein Staging-Aufruf verlangt das Feld zwingend). Zusätzlich prüft dieser Schritt, dass die Plattform-Abrechnung in Staging keinen Live-Stripe-Schlüssel verwendet.'],
            ['type' => 'Geändert', 'text' => 'Die primäre Absicherung bleibt die Servertrennung (Staging auf einem eigenen, physisch getrennten VPS mit eigener Coolify-MariaDB, niemals auf dem Produktions-VPS); die sichere Vorgehensweise zur Ersteinrichtung ist in docs/vps/02-einrichtung-vps.md, Kapitel 25, dokumentiert.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/staging-isolation-check.py (kein Docker-Daemon nötig, nur docker compose ... config): bestätigt getrennte Projekt-/Volume-/Netz-/Traefik-Namen zwischen Produktion und Staging sowie das Vorhandensein des --expect-env-Schutzes in bin/healthcheck.php, deploy.sh und rollback.sh.'],
         ]],
        ['version' => '4.6', 'date' => '07.09.2026', 'title' => 'Verwertbare Fehlerdiagnose der Candidate-Prüfung',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der erste produktive Einsatz der neuen Candidate-Prüfung (Version 4.5) scheiterte mit der unbrauchbaren Meldung "redis: other": Der Fehlertext eines fehlgeschlagenen Redis-Zugriffs im Alpine/musl-basierten PHP-Image wich von der bisher erkannten glibc-Formulierung ab und wurde nicht erkannt. bin/healthcheck.php --redis erkennt jetzt zusätzliche Fehlerklassen (DNS, Verbindung abgelehnt, Authentifizierung, vom Server beendete Verbindung) und versucht bei einem Fehlschlag bis zu dreimal mit kurzer Pause erneut, um eine rein transiente Störung beim Netzwerkaufbau eines frisch erzeugten Containers abzufedern.'],
            ['type' => 'Geändert', 'text' => 'deploy.sh meldet bei einem Fehlschlag der Candidate-Prüfung, der Migration, des Warteschritts auf gesunde Container oder des Health-Checks nach der Aktivierung zusätzlich Phase, fehlgeschlagenen Befehl, Exitcode, Release-SHA und den aktuellen Containerzustand in die persistente Logdatei, ohne jemals Zugangsdaten auszugeben. Die Reihenfolge Candidate-Prüfung vor Migration vor Cutover aus Version 4.5 bleibt unverändert bestehen; es wurde nichts an der bereits funktionierenden serverseitigen Entkopplung zurückgebaut.'],
            ['type' => 'Neu', 'text' => 'Regressionstest tools/healthcheck-redis-check.php: prüft monitor_category() gegen glibc- und musl-typische Fehlertexte, sowie bin/healthcheck.php --redis gegen einen nicht auflösbaren Hostnamen und einen geschlossenen Port; tools/compose-check.py bestätigt zusätzlich statisch, dass deploy.sh die Reihenfolge Candidate-Prüfung, Migration, Cutover einhält und beide isolierten Schritte ausschließlich über "docker compose run --rm --no-deps" laufen, ohne laufende Container anzufassen.'],
         ]],
        ['version' => '4.5', 'date' => '07.09.2026', 'title' => 'Ausfallsicheres VPS-Deployment, Release-Bindung ohne Symlink',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Ein VPS-Deployment brach ab, wenn die SSH-Verbindung des GitHub-Workflows waehrend des mehrminuetigen Container-Neustarts kurz abriss ("client_loop: send disconnect: Broken pipe"); Container blieben im Zustand "created" haengen. Das Deployment laeuft jetzt serverseitig entkoppelt (deploy/vps/scripts/deploy-runner.sh, setsid) und uebersteht einen SSH-Abbruch; der Workflow fragt den Fortschritt ueber kurze, unabhaengige Verbindungen ab.'],
            ['type' => 'Geändert', 'text' => 'working_dir aller Container und Caddys Dokumentenstamm sind jetzt an die Umgebungsvariable RELEASE_SHA gebunden (Pflichtwert), nicht mehr an den mutable Symlink "releases/current". Damit gehoeren Compose-Konfiguration, Healthchecks und Anwendungscode bei jedem Containerstart garantiert zum selben Release.'],
            ['type' => 'Geändert', 'text' => 'Datenbankmigrationen laufen jetzt in einem isolierten, zusaetzlichen Container mit dem neuen Code, BEVOR die laufenden Container angefasst werden (Candidate-Pruefung, dann Migration, dann Cutover). Schlagen Candidate-Pruefung oder Migration fehl, bleiben die laufenden Container unveraendert, ein Rollback ist dann nicht noetig.'],
            ['type' => 'Neu', 'text' => 'Regressionstests tools/compose-check.py (Release-Bindung als Pflichtwert, keine Datenbank-/Backup-Dienste im Stack) und tools/deploy-runner-check.sh (uebersteht simulierten SSH-Abbruch, lehnt parallele Deployments ab, idempotent).'],
         ]],
        ['version' => '4.4', 'date' => '06.09.2026', 'title' => 'Healthcheck des Metrik-Sammlers',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Der Container des Metrik-Sammlers galt auf dem VPS dauerhaft als ungesund und brach das Deployment ab, weil er den Healthcheck der Worker erbte, aber keinen Worker-Heartbeat schreibt. Er hat jetzt einen eigenen Healthcheck (bin/healthcheck.php --metrics: Prozessprüfung und eigenes Lebenszeichen der Sammelschleife).'],
            ['type' => 'Geändert', 'text' => 'Das PHP-Image gibt keinen Standard-Healthcheck mehr vor; jeder Dienst legt seinen passenden Healthcheck selbst fest. Eine automatische Prüfung (tools/compose-check.py) verhindert künftig, dass ein Dienst einen unpassenden Healthcheck erbt oder ein Dollarzeichen in einem Healthcheck falsch ausgewertet wird.'],
            ['type' => 'Geändert', 'text' => 'Die Servereinrichtung setzt die von Redis empfohlene Kernel-Einstellung vm.overcommit_memory dauerhaft und wiederholbar.'],
            ['type' => 'Behoben', 'text' => 'Datum von SEPA-Mandaten und die Prüfung überfälliger Termine richten sich nach der Zeitzone der Anwendung statt nach dem Datum des Datenbankservers. Läuft die Datenbank in UTC, trug ein zwischen Mitternacht und 02:00 Uhr digital erteiltes Mandat bisher das Datum des Vortages.'],
         ]],
        ['version' => '4.3', 'date' => '06.09.2026', 'title' => 'VPS-Stack nutzt die Coolify-Datenbank',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Der Docker-Stack auf dem Hostinger-VPS startet keine eigene MariaDB und keinen eigenen Backup-Container mehr; genutzt wird die bereits eingerichtete private Coolify-MariaDB 11.8 (kein öffentlicher Port), gesichert durch Coolify mit externem Ziel Hetzner Object Storage.'],
            ['type' => 'Geändert', 'text' => 'PHP, Scheduler, Worker und Metrik-Sammler erreichen die Datenbank über das Coolify-Netz unter dem Containernamen; der Metrik-Sammler meldet die neueste lokale Coolify-Sicherung an das Monitoring (Komponente Sicherungen).'],
         ]],
        ['version' => '4.2', 'date' => '06.09.2026', 'title' => 'Ratenbegrenzung je Firma',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Die zentrale Ratenbegrenzung für Lexware Office und Stripe zählt je API-Schlüssel (also je Firma) statt über alle Firmen zusammen; zusätzlich eine konfigurierbare Obergrenze insgesamt. Der Durchsatz wächst damit mit der Zahl der Worker.'],
            ['type' => 'Behoben', 'text' => 'Ein Rate-Limit einer einzelnen Firma öffnet nicht mehr den Circuit Breaker des Anbieters; der Breaker reagiert nur noch auf echte Störungen (Verbindungsfehler, Serverfehler).'],
         ]],
        ['version' => '4.1', 'date' => '06.09.2026', 'title' => 'Tarifwechsel, Upsell und Hostinger-VPS',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Tarifwechsel durch den Inhaber unter Firma > Abonnement (Upgrade sofort mit anteiliger Berechnung, Downgrade mit Gutschrift, Downgrade-Schutz für Benutzer), Bestellbestätigung und Protokoll wie beim Abschluss (Migration 019).'],
            ['type' => 'Neu', 'text' => 'Upsell bei erreichten Grenzen: Hinweis auf den nächsthöheren Tarif beim Benutzerlimit, ab 80 Prozent und bei ausgeschöpftem Einzugskontingent, einmal je Periode auch per E-Mail an den Inhaber. Erscheint nur, wenn mindestens zwei Tarife aktiv sind.'],
            ['type' => 'Geändert', 'text' => 'VPS-Stack auf Hostinger KVM 8 mit Coolify ausgerichtet: TLS am Coolify-Proxy, Caddy als interner HTTP-Server, Ressourcenlimits für 8 vCPU und 32 GB, Einrichtungsanleitung Kapitel 08.'],
         ]],
        ['version' => '4.0', 'date' => '06.09.2026', 'title' => 'Hintergrundverarbeitung und VPS-Migration vorbereitet',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Zentrale Job-Queue mit Prioritäten, Wiederholungen mit gestaffeltem Backoff, Dead-Letter-Ansicht im Admin, Worker mit Heartbeat, Scheduler, Circuit Breaker je Anbindung (Migration 018).'],
            ['type' => 'Neu', 'text' => 'Synchronisationshistorie je Firma mit Details (Dauer, Mengen, API-Aufrufe, Fehler) und Live-Fortschritt.'],
            ['type' => 'Neu', 'text' => 'E-Mail-Versand über die Warteschlange, Wartungsmodus je Firma für die Synchronisation, Feature-Flags je Firma.'],
            ['type' => 'Neu', 'text' => 'Strukturiertes Logging mit Correlation-ID über Webanfrage, Job, Worker und Audit.'],
            ['type' => 'Neu', 'text' => 'Docker-Stack für den IONOS VPS (Caddy, PHP-FPM, Worker, Scheduler, MariaDB, Redis, Backup, Host-Metriken), Deployment über SSH mit Rollback, Staging-Konfiguration.'],
            ['type' => 'Neu', 'text' => 'Adminbereich System: Reiter Jobs, Server, Versionen, Dokumentation; technische Dokumentation mit Diagrammen als PDF.'],
            ['type' => 'Geändert', 'text' => 'Bestehende Cron-Verarbeitung bleibt auf dem Webhosting erhalten; die Queue ist dort standardmäßig ausgeschaltet.'],
            ['type' => 'Behoben', 'text' => 'Adversariale Abnahme: X-Forwarded-For wird von rechts ausgewertet und validiert; Wartungsmodus und Adminhost-Trennung richten sich nach dem ausgeführten Skript, nicht nach der URL; Wartungsmodus pausiert Scheduler und Worker; Correlation-ID nur von vertrauenswürdigen Proxys.'],
            ['type' => 'Behoben', 'text' => 'Ratenbegrenzung reserviert Kontingent im Zielfenster; Maskierung von Webhook- und API-Schlüsseln in Protokollen; keine Empfängeradressen im Fehlerprotokoll; fehlgeschlagene Mail-Jobs ohne Nachrichteninhalt.'],
            ['type' => 'Behoben', 'text' => 'Container sehen nur Releases, Konfiguration und Speicher (kein Wurzeldateisystem, kein deploy/.env); Rollback prüft die Verträglichkeit mit dem Migrationsstand; kontrolliertes Beenden laufender Jobs beim Deployment (660 s).'],
         ]],
        ['version' => '3.4', 'date' => '06.09.2026', 'title' => 'Gerätefreigabe, Systemmonitoring, Statusseite',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Zwei-Faktor: Gerät für 90 Tage merken mit fester Gültigkeit, Verwaltung unter Sicherheit, Widerruf bei Passwort- und 2FA-Änderungen (Migration 016).'],
            ['type' => 'Neu', 'text' => 'Adminbereich System mit ehrlichen Messwerten, Zeitfenstern, Verfügbarkeit und Störungsverwaltung (Migration 017).'],
            ['type' => 'Neu', 'text' => 'Öffentliche Statusseite vorbereitet (status.smart-einzug.de), Snapshot mit Positivliste.'],
         ]],
        ['version' => '3.3', 'date' => '06.09.2026', 'title' => 'Multiaccount und Registrierung',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Multiaccount-Schalter im Profil, Registrierung mit bereits bekannter E-Mail-Adresse, Dublettenprüfung mit Sperren (Migration 015).'],
         ]],
        ['version' => '3.2', 'date' => '06.09.2026', 'title' => 'Navigation bereinigt',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Kopfbereich ohne Dubletten zum Profilmenü, Team wird Firmendaten, Firmen wird Firmenübersicht, Exportbutton geprüft.'],
         ]],
        ['version' => '3.1', 'date' => '06.09.2026', 'title' => 'Synchronisierung gegen Doppelstarts',
         'entries' => [
            ['type' => 'Behoben', 'text' => 'Sperre mit Inhaber je Schritt, Zähler übersprungener Doppelstarts, API-Aufrufbudget je Schritt, Backoff mit Retry-After (Migration 014).'],
         ]],
        ['version' => '3.0', 'date' => '06.09.2026', 'title' => 'Abgesicherter Migrationsaufruf durch GitHub',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'migrate.php nur per POST mit Header X-Migration-Token, gemeinsame Sperre, Fehler werden nie automatisch wiederholt, Cron migriert nicht mehr.'],
            ['type' => 'Neu', 'text' => 'Abonnement vorbereitet: Bestellbestätigung mit AGB-Zustimmung, Rechnungsarchiv aus Stripe.'],
         ]],
        ['version' => '2.4', 'date' => '06.09.2026', 'title' => 'Synchronisation beschleunigt',
         'entries' => [
            ['type' => 'Geändert', 'text' => 'Änderungserkennung über updatedDate, seltenere Kontaktabrufe, zeitbasierte Schritte, Messwerte (Migration 013).'],
         ]],
        ['version' => '2.3', 'date' => '06.09.2026', 'title' => 'Hilfe-Center',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Anleitungen, häufige Fragen und Support-Anfragen mit Tickets (Migration 012).'],
         ]],
        ['version' => '2.2', 'date' => '06.09.2026', 'title' => 'Karenzzeit und Einreichfenster',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Karenzzeit vor dem Einzug, Nachtfenster, Storno im Status Vorgemerkt, Not-Stopp mit Sammelstorno (Migration 011).'],
            ['type' => 'Behoben', 'text' => 'Umterminieren setzt die Vormerkung zurück, Support-Sperre für Einreichungen, Asset-Versionierung gegen Browser-Cache.'],
         ]],
        ['version' => '2.1', 'date' => '05.09.2026', 'title' => 'Profil, Dashboard, Stripe-Import',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Profilmenü mit Bild und Telefonnummern, fünf Dashboard-Karten, Gläubiger-ID optional (Migration 010).'],
            ['type' => 'Neu', 'text' => 'Bestehende Einzüge aus Stripe übernehmen (Migration 009), Tarifeditor, Support-Bereich mit Firmenzugriff (Migration 008), automatischer Upload über GitHub.'],
         ]],
        ['version' => '2.0', 'date' => '05.09.2026', 'title' => 'SmartEinzug: Marke, Websites, Host-Trennung',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Hauptwebsite smart-einzug.de, Alias-Weiterleitungen, eigenständige Inhalte je Domain, app. und admin. als getrennte Hosts.'],
            ['type' => 'Neu', 'text' => 'Zahlungsqualität: Erstattungen aus Stripe, Klärung unklarer Versuche, Alarmierung, Zweitbestätigung kritischer Aktionen, Mandatsdokumente.'],
         ]],
        ['version' => '1.1', 'date' => '04.09.2026', 'title' => 'SaaS-Ausbau',
         'entries' => [
            ['type' => 'Neu', 'text' => '2FA-Pflicht, Rollen, Tarife, Audit, Plattform-Abrechnung mit Stripe Tax, Marketingseiten mit Einwilligungsbanner.'],
            ['type' => 'Behoben', 'text' => 'Sicherheitskorrekturen nach adversarialer Prüfung.'],
         ]],
        ['version' => '1.0', 'date' => '31.08.2026', 'title' => 'Erste Fassung für IONOS Webhosting',
         'entries' => [
            ['type' => 'Neu', 'text' => 'Lexware-Office-Synchronisation in fortsetzbaren Schritten, SEPA-Einzug je Kunde, Sammel-Einzug, mehrere Firmen je Konto, HVM-CI, Setup-Prüfung.'],
         ]],
    ];
}

/** Build-Informationen aus app/build.txt (vom Deployment geschrieben) oder null. */
function app_build_info(): ?string
{
    $f = __DIR__ . '/build.txt';
    $v = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    return $v !== '' ? $v : null;
}
