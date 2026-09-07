# Cronjobs, Warteschlangen und Hintergrundprozesse

Stand: 07.09.2026. Jobverzeichnis der Hintergrundverarbeitung von SmartEinzug, ausgewertet aus
`php-ionos/app/jobs.php`, `php-ionos/app/queue.php`, `php-ionos/app/worker_signals.php`,
`php-ionos/bin/scheduler.php`, `php-ionos/bin/worker.php`, `php-ionos/bin/host-metrics.php`,
`php-ionos/cron.php` und `deploy/vps/docker-compose.yml`. Alle Aussagen sind mit Datei:Zeile bzw.
Datei:Funktion belegt; nicht Auffindbares ist als „nicht gefunden" gekennzeichnet.

## Zwei Betriebsarten: IONOS-Cron vs. VPS-Warteschlange

- **IONOS-Webhosting:** Feature-Flag `features.queue` steht auf `false`
  (`app/config.example.php:279`). `cron.php` wird extern (IONOS-Kundenbereich oder externer
  Cron-Dienst) alle 5 Minuten aufgerufen (Kommentarkopf `cron.php:11-16`) und verarbeitet inline:
  fällige Einzüge (`process_scheduled_collections()`, `cron.php:81`), unklare Einzugsversuche
  (`collection_attempts_resolve()`, `cron.php:105`), Mandats-Erinnerungen (`cron.php:115`),
  Alarmierung (`alerts_cron_notify()`, `cron.php:124`), Synchronisation (`sync_run_pending()`,
  `cron.php:132`), Monitoring (`monitor_collect()`, `cron.php:147`). Zeitbudget je Aufruf
  `config('cron_time_budget_seconds')`, Standard 20 s (`app/config.example.php:61`, Begründung
  „externe Cron-Dienste brechen oft nach 30 s ab", `cron.php:41-43`).
- **VPS (Coolify/Docker):** `features.queue` ist gesetzt; `cron.php` bleibt funktional erhalten, aber
  wenn `queue_any_enabled()` wahr ist, verarbeitet es nur noch **inline als Notpfad ohne laufende
  Worker** (`cron.php:53-64`, `queue_run_inline()`), läuft mindestens ein Worker
  (`workers_alive() > 0`), überspringt `queue_run_inline()` die Inline-Verarbeitung vollständig
  (`app/jobs.php:433-436`, Kommentar „laufende Worker übernehmen; kein doppelter Pfad"). Auf dem VPS
  reiht stattdessen der Scheduler-Container fällige Aufgaben ein, fünf Worker-Container verarbeiten sie
  (siehe Cron-Matrix unten, referenziert aus `docs/vps/06-betrieb.md:588-613`).
- Abdeckungstabelle laut `docs/vps/06-betrieb.md:595-604` (dort geprüft am 07.09.2026):

  | Aufgabe (Webhosting: `cron.php`) | VPS: eingereiht durch | Jobtyp | Worker |
  |---|---|---|---|
  | Fällige Einzüge einreichen | Scheduler alle 300 s | `collections_due` | worker-stripe |
  | Unklare Einzugsversuche klären | Scheduler alle 600 s | `unclear_attempts` | worker-stripe |
  | Lexware-Synchronisation je Firma | Scheduler (Plan je Firma, Waisen sofort) | `sync_run` | worker-lexware-1, -2 |
  | Monitoring und Statusseite | Scheduler alle 240 s | `monitor_collect` | worker-maintenance |
  | Alarme, Mandatserinnerungen | Scheduler stündlich | `alerts`, `mandate_reminders` | worker-mail |
  | E-Mail-Versand | bei Bedarf aus der Anwendung | `mail` | worker-mail |
  | Bereinigungen, Nachsenden wartender Mails, Pruning | Scheduler stündlich | `maintenance` | worker-maintenance |
  | Hängende Jobs freigeben | Scheduler alle 30 s (`queue_release_stale()`) | (keiner) | Scheduler |

  Systemseitig braucht der VPS laut derselben Quelle ebenfalls keinen eigenen Cron (Backups über
  Coolify, TLS über den Coolify-Proxy, Log-Rotation über Docker, Betriebssystem-Updates über
  `unattended-upgrades`).

## Zeitzone

`TZ=Europe/Berlin` (`deploy/vps/.env.example:12`), an alle PHP-Container über
`environment.TZ: ${TZ:-Europe/Berlin}` durchgereicht (`deploy/vps/docker-compose.yml:104`,
`279`); `app/bootstrap.php:25` setzt zusätzlich `date_default_timezone_set(config('timezone') ??
'Europe/Berlin')` (Standard laut `app/config.example.php:283`).

## Jobverzeichnis (Warteschlange, VPS)

@@diagramm 10-hintergrundprozesse

Job-Handler zentral in `job_handle()` (`app/jobs.php:51-65`); Pools/Zuordnung in `jobs_pools()`
(`app/jobs.php:26-35`).

### `sync_run`, Lexware-Office-Synchronisation je Firma

- **Aufgabe:** eine Firma mit Lexware Office synchronisieren (Rechnungen, Kontakte, Zahlungsstände);
  Handler `job_sync_run()` (`app/jobs.php:71-173`).
- **Ausführungsort:** Container `worker-lexware-1`, `worker-lexware-2`
  (`deploy/vps/docker-compose.yml:242-249`, Pool `lexware`).
- **Auslöser/Scheduler:** `scheduler_auto_sync()` (`app/jobs.php:358-419`) reiht ein bei:
  regelmäßigem Delta-Sync (`auto_sync_hours`, Standard 6 h, `app/config.example.php:224`), nächtlichem
  Vollabgleich zur konfigurierten Stunde (`full_sync_hour`, Standard 3 Uhr lokal,
  `app/config.example.php:225`, geprüft über `date('G', $now)`, `app/jobs.php:374`), sowie sofort bei
  einem verwaisten Lauf ohne Fortschritt (`stale`-Fortsetzung, `app/jobs.php:387-398`, kein Warten bis
  zur nächsten regulären Fälligkeit). `dedupe_key` `sync:<tenant_id>` verhindert Doppelstarts
  (`app/jobs.php:394, 404, 412`).
- **Zeitbudget je Versuch:** `queue.sync_attempt_seconds`, Standard 600 s
  (`app/config.example.php:221`), zusätzlich begrenzt auf `queue.sync_max_steps_attempt`, Standard 60
  Schritte (`app/config.example.php:222`, geprüft `app/jobs.php:41-42, 168`).
- **Verarbeitete Daten/Batchgröße:** Schrittmechanismus `sync_state_step()` (in `app/sync_state.php`,
  hier nicht erneut ausgewertet); je Rechnung Voucherliste-Seite, Detailabruf, Kontaktabgleich.
- **Sperre gegen Parallelität:** `sync_state`-Tabelle mit Status `running`/`error`/`done`
  (`app/jobs.php:92-107`), zusätzlich `sync_run_attach()` (`app/jobs.php:108`); `dedupe_key`
  verhindert einen zweiten `sync_run`-Job derselben Firma in der Warteschlange gleichzeitig.
- **Timeouts/Wiederholungen:** `max_attempts = 6`, `heartbeat_ttl = 300` s
  (`queue_type_defaults()`, `app/queue.php:99`). Ein Circuit-Breaker-Fehler (`CircuitOpenException`)
  wird als `JobRetryException` weitergereicht (`app/jobs.php:123-125`); ein Authentifizierungsfehler
  gegen Lexware Office (`monitor_category($e) === 'auth'`) wird sofort als `JobFailedException`
  eingestuft, kein weiterer Versuch (`app/jobs.php:130-135`).
- **Fehlerablage:** `queue_fail()` (`app/queue.php:278-311`), bei endgültigem Fehlschlag zusätzlich
  Schließen von `sync_state` (`status = 'error'`) und `sync_run_finish()` (`app/queue.php:297-305`);
  `job_runs` je Verarbeitungsversuch (`job_run_start()`/`job_run_finish()`, `app/jobs.php:457-501`).
- **Kooperativer Abbruch:** nach jedem abgeschlossenen Schritt (`worker_stop_requested()`,
  `app/jobs.php:165-167`) und bei belegter Sperre nach 5 Wiederholungen (`app/jobs.php:139-145`),   Fortsetzung über `JobRequeueException`, kein Fehlversuch, Cursor bleibt in `sync_state` erhalten.

### `collections_due`, fällige SEPA-Einzüge einreichen

- **Aufgabe:** Handler `job_collections_due()` (`app/jobs.php:176-210`), ruft
  `process_scheduled_collections()` (`app/collections.php:1705`) auf.
- **Ausführungsort:** Container `worker-stripe` (`deploy/vps/docker-compose.yml:251-253`, Pool
  `stripe`).
- **Auslöser/Scheduler:** `scheduler_tick()`, Intervall 300 s, `dedupe_key = 'collections:due'`,
  Priorität `normal` (`app/jobs.php:332`).
- **Voraussetzung:** `platform_collections_paused()` überspringt den Lauf vollständig, wenn die
  Plattform-Einreichung angehalten ist (`app/jobs.php:179-181`); Einreichfenster
  (`collections.window_start`/`window_end`, Standard 23:00 bis 06:00,
  `app/config.example.php:191-197`) wird **ausschließlich** innerhalb von
  `process_scheduled_collections()`/`collections_window_open()` geprüft (`app/collections.php:178`),
  nicht in `app/jobs.php` selbst (siehe projektweite Regel im CLAUDE.md).
- **Zeitbudget:** `queue.collections_seconds`, Standard 120 s (`app/config.example.php:223`).
- **Batchgröße/verarbeitete Daten:** alle fälligen terminierten und vorgemerkten Einzüge einer Firma
  bzw. aller Firmen (`tenant_id` optional); bereits behandelte IDs werden bei Fortsetzung über
  `payload['_seen']` übersprungen, gekappt auf die letzten 5000 Einträge
  (`app/jobs.php:187, 199-200`).
- **Sperre:** kein zusätzlicher Lock-Mechanismus über die Job-Reservierung hinaus gefunden;
  Idempotenz über Stripe-Idempotenzschlüssel je Einzug (siehe `docs/entwickler/schnittstellen.md`).
- **Timeouts/Wiederholungen:** `max_attempts = 5`, `heartbeat_ttl = 600` s (`app/queue.php:100`).
- **Fehlerbehandlung:** bleiben nach Zeitbudget oder Stop-Signal Einzüge übrig
  (`remaining > 0`), wird **immer** eine Fortsetzung eingeplant (`JobRequeueException`,
  `app/jobs.php:192-207`), auch wenn noch kein Einzug behandelt wurde, sonst gälte der Job
  fälschlich als erledigt (Kommentar Zeilen 193-195).
- **Nicht unterbrechbar:** dieser Typ steht in `WORKER_NO_FORCED_ABORT_TYPES`
  (`app/worker_signals.php:61`), die Notbremse (SIGALRM) wirft hier **nie** eine
  `WorkerShutdownException`; nur der kooperative Abbruchpunkt zwischen zwei Einzügen gilt.

### `unclear_attempts`, unklare Einzugsversuche klären

- **Aufgabe:** Handler `job_unclear_attempts()` (`app/jobs.php:213-243`), ruft
  `collection_attempts_resolve()` (`app/collections.php:731`) je betroffener Firma auf; reiner
  Lesezugriff bei Stripe, keine neue Lastschrift (Kommentar `app/jobs.php:212`).
- **Ausführungsort:** Container `worker-stripe` (Pool `stripe`).
- **Auslöser/Scheduler:** `scheduler_tick()`, Intervall 600 s, `dedupe_key = 'unclear:all'`, Priorität
  `low` (`app/jobs.php:333`). Ohne `tenant_id` im Job werden alle Firmen mit offenen/unbekannten
  Versuchen älter als 5 Minuten ermittelt (`app/jobs.php:215`).
- **Timeouts/Wiederholungen:** `max_attempts = 5`, `heartbeat_ttl = 300` s (`app/queue.php:101`).
- **Sperre:** keine explizite Sperre; kooperativer Abbruch zwischen zwei Firmen bzw. zwischen zwei
  Versuchen (`app/jobs.php:221-223, 238-240`).
- **Nicht unterbrechbar:** ebenfalls in `WORKER_NO_FORCED_ABORT_TYPES`
  (`app/worker_signals.php:61`), Begründung: „Stripe-Lesezugriff und Nachbuchung gehören zusammen"
  (`app/jobs.php:219-220`).
- **Fehlerablage:** einzelne Firmenfehler werden gezählt (`sum['errors']`), Gesamtergebnis
  `partially_completed` bei mindestens einem Fehler, sonst `completed` (`app/jobs.php:242`).

### `mail`, E-Mail-Versand

- **Aufgabe:** Handler `job_mail()` (`app/jobs.php:250-273`), sendet über `mail_send_direct()`.
- **Ausführungsort:** Container `worker-mail` (`deploy/vps/docker-compose.yml:255-257`, Pool
  `mail`).
- **Auslöser:** aus der Anwendung heraus bei Bedarf über `mail_send()`/`mail_send_queued()`
  (`app/mailer.php:109, 129-150`), sobald `queue_enabled()` wahr ist, kein Scheduler-Takt, sondern
  ereignisgesteuert (Registrierung, Sicherheitsereignisse, Support-Tickets usw., siehe
  `docs/entwickler/email-system.md`).
- **Voraussetzung:** gültige, per `filter_var(..., FILTER_VALIDATE_EMAIL)` geprüfte
  Empfängeradresse (`app/jobs.php:254`); leerer oder bereits bereinigter Inhalt wird sofort als
  `JobFailedException` abgelehnt (`app/jobs.php:257-259`).
- **Drosselung:** `api_call_gate('mail', 20)` (`app/jobs.php:260`, 20 Versuche/Sekunde).
- **Timeouts/Wiederholungen:** `max_attempts = 3`, `heartbeat_ttl = 120` s (`app/queue.php:102`),   bewusst niedrig, weil eine Wiederholung nach einem Absturz zwischen Übergabe und
  Statusspeicherung eine Nachricht doppelt zustellen kann (Kommentar `app/jobs.php:247-249`).
- **Fehlerklassen:** endgültige Ablehnung durch den Mailserver (`mail_last_error()['kind'] ===
  'rejected'`) wird als `JobFailedException` gewertet, kein Fehlversuch mehr sinnvoll
  (`app/jobs.php:264-267`); ein nicht angenommener Versandweg löst `circuit_failure('mail',
  'connection')` und `JobRetryException` aus (`app/jobs.php:268-269`).
- **Datensparsamkeit:** nach erfolgreicher Übergabe wird der Nachrichteninhalt aus dem Job entfernt
  (`'prune' => true`, `app/jobs.php:272`, umgesetzt in `queue_complete()`,
  `app/queue.php:225-237`); bei endgültigem Fehlschlag ebenfalls über `queue_prune_payload()`
  (`app/queue.php:294-296`).
- **Nicht unterbrechbar:** dritter Eintrag in `WORKER_NO_FORCED_ABORT_TYPES`
  (`app/worker_signals.php:61`), ein SMTP-Dialog zwischen Annahme der Nachricht und Rückkehr darf
  nicht abgebrochen werden (Doppelzustellung, Kommentar Zeilen 24-27).

### `alerts`, tägliche Alarm-E-Mails

- **Aufgabe:** `alerts_cron_notify()` (`app/alerts.php`, aufgerufen `app/jobs.php:58`); eine E-Mail je
  Kalendertag und Firma an den Inhaber bei Alarmen der Stufe „hoch" (Kommentarkopf `cron.php:8-9`,
  Umsetzung `app/alerts.php:190-220`).
- **Ausführungsort:** Container `worker-mail` (Pool `mail`).
- **Auslöser/Scheduler:** stündlich (`app/jobs.php:335`, `dedupe_key = 'alerts:daily'`, Priorität
  `low`).
- **Timeouts:** `max_attempts = 2`, `heartbeat_ttl = 1800` s (`app/queue.php:105`).
- **Wiederholungsschutz:** `platform_setting()`-Marke je Firma und Tag verhindert Mehrfachversand
  (`app/alerts.php:190`, Prüfung gegen `$today`).

### `mandate_reminders`, digitale Mandatserinnerungen

- **Aufgabe:** Handler `job_mandate_reminders()` (`app/jobs.php:275-282`), ruft
  `mandate_request_remind()` (`app/mandate_requests.php`) auf; nur aktiv, wenn Feature-Flag
  `features.mandate_request` gesetzt ist (`app/jobs.php:277`, Standard `false`,
  `app/config.example.php:276`).
- **Ausführungsort:** Container `worker-mail` (Pool `mail`).
- **Auslöser/Scheduler:** stündlich (`app/jobs.php:337`, `dedupe_key = 'mandates:remind'`, Priorität
  `low`).
- **Timeouts:** `max_attempts = 3`, `heartbeat_ttl = 1800` s (`app/queue.php:106`).

### `monitor_collect`, Monitoring-Sammler

- **Aufgabe:** `monitor_collect(['source' => 'worker', 'budget' => 8.0])` (`app/jobs.php:60`);
  Diagnosen für die Statusseite und den Adminbereich (Details in `app/monitor.php`, nicht Gegenstand
  dieses Dokuments).
- **Ausführungsort:** Container `worker-maintenance` (Pool `maintenance`).
- **Auslöser/Scheduler:** alle 240 s (`app/jobs.php:334`, `dedupe_key = 'monitor:collect'`, Priorität
  `low`).
- **Timeouts:** `max_attempts = 2`, `heartbeat_ttl = 120` s (`app/queue.php:103`).

### `maintenance`, Wartungsaufgaben

- **Aufgabe:** Handler `job_maintenance()` (`app/jobs.php:285-316`), führt nacheinander aus:
  `support_sessions_expire()`, `registration_requests_cleanup()`, `interest_cleanup()`,
  `audit_cleanup()`, `interest_send_pending()`, `auth_send_pending_welcome_mails()`,
  `devices_cleanup()`, `queue_prune($cfg['prune_days'])`, `queue_release_stale()`,
  `workers_prune()` (`app/jobs.php:289-299`).
- **Ausführungsort:** Container `worker-maintenance` (Pool `maintenance`).
- **Auslöser/Scheduler:** stündlich (`app/jobs.php:336`, `dedupe_key = 'maintenance:hourly'`,
  Priorität `low`).
- **Batchgröße:** `queue.prune_days`, Standard 30 Tage abgeschlossene Jobs
  (`app/config.example.php:226`, `jobs_config()` `app/jobs.php:46`); `interest_send_pending()`,
  `auth_send_pending_welcome_mails()` je höchstens 50 Datensätze je Lauf (Standardparameter
  `int $limit = 50`, `app/interest.php:242`, `app/auth.php:1137`).
- **Timeouts:** `max_attempts = 2`, `heartbeat_ttl = 300` s (`app/queue.php:104`).
- **Kooperativer Abbruch:** zwischen zwei Teilaufgaben (`app/jobs.php:303-305`), jede Teilaufgabe ist
  eine idempotente Bereinigung, der Scheduler reiht den Typ ohnehin stündlich neu ein.
- **Fehlerbehandlung je Teilaufgabe:** ein Fehler einer einzelnen Teilaufgabe wird als
  `'fehler:' . monitor_category($e)` im Ergebnis vermerkt, bricht aber nicht die übrigen Teilaufgaben
  ab (`app/jobs.php:306-312`); eine `JobRequeueException` (Notbremse) wird dagegen unverändert
  durchgereicht (Zeile 308-309).

## `bin/scheduler.php`, Scheduler

- **Aufgabe:** prüft alle 30 s (Standard, `--interval` änderbar) fällige wiederkehrende Aufgaben und
  reiht sie über `scheduler_tick()` ein (`app/jobs.php:323-355`); verarbeitet selbst keine Jobs
  (Kommentarkopf `bin/scheduler.php:1-10`).
- **Startbefehl/Arbeitsverzeichnis:** `php bin/scheduler.php [--once] [--interval=30]`
  (`deploy/vps/docker-compose.yml:235`: `command: ["php", "bin/scheduler.php"]`), Arbeitsverzeichnis
  `/opt/smarteinzug/releases/${RELEASE_SHA}` (`docker-compose.yml:102, x-php-common`).
- **Benutzer:** `${APP_UID:-33}:${APP_GID:-33}` (`docker-compose.yml:94`, kein Root).
- **Voraussetzungen:** Warteschlangentabelle `jobs` vorhanden (Migration 018,
  `queue_available()`), sonst Abbruch mit Exit 3 (`bin/scheduler.php:23-26`).
- **Sperre gegen Parallelität:** genau ein Scheduler je Datenbank über MariaDB `GET_LOCK('smarteinzug_scheduler', 0)`
  (`bin/scheduler.php:27-33`); ein zweiter Prozess beendet sich sofort ohne Fehler.
- **Wartungsmodus:** reiht während `maintenance_active()` keine neuen Jobs ein, schreibt aber
  weiter Heartbeat (`bin/scheduler.php:44-56`).
- **Logpfad/Überwachung:** Heartbeat in die Datenbank (`worker_register()`/`worker_heartbeat()`,
  `app/queue.php:589-612`) und in eine Datei `WORKER_HEARTBEAT_FILE` bzw.
  `sys_get_temp_dir()/smarteinzug-scheduler-heartbeat` (`bin/scheduler.php:37, 52, 60`); geprüft über
  `bin/healthcheck.php --heartbeat` (jünger als 90 s, `bin/healthcheck.php:103-108`) bzw.
  `--scheduler` (DB-Heartbeat jünger als 120 s, `bin/healthcheck.php:144-146`).
- **Sicheres manuelles Starten:** `docker compose exec -T scheduler php bin/scheduler.php --once`
  (abgeleitet aus dem allgemeinen Muster in `docs/vps/06-betrieb.md`, Abschnitt „Worker skalieren und
  neu starten", Zeilen 828-846, dort explizit für Worker demonstriert, für den Scheduler analog, da
  derselbe Anker `x-php-background` gilt).
- **Sicheres Stoppen:** SIGTERM/SIGINT/SIGQUIT (`worker_signals_install()`, `app/worker_signals.php:72-113`,
  installiert `bin/scheduler.php:22` **vor** dem ersten Datenbankzugriff); die Schleife endet nach dem
  laufenden Tick, die 1-Sekunden-Warteschleife prüft das Signal jede Sekunde
  (`bin/scheduler.php:71-73`). `stop_signal: SIGTERM`, `stop_grace_period: 75s`
  (`docker-compose.yml:137, 149`, Anker `x-php-background`).
- **Verhalten nach Neustart:** `shared/config.php` wird nur beim Containerstart gelesen; eine reine
  Konfigurationsänderung wirkt erst nach `deploy/vps/scripts/restart-workers.sh`
  (`docs/vps/06-betrieb.md:615-643`, nicht erneut ausgewertet in diesem Dokument). Verwaiste
  Sync-Läufe schließt der Scheduler selbst und reiht die Fortsetzung sofort ein
  (`scheduler_auto_sync()`, `app/jobs.php:380-398`).
- **Verpasste Läufe/Zeitumstellung:** kein expliziter Umgang mit Sommer-/Winterzeit-Sprüngen im Code
  gefunden; `full_sync_hour` bezieht sich auf `date('G', $now)` in der konfigurierten Zeitzone
  (`app/jobs.php:374`), bei der Umstellung kann eine Stunde doppelt oder gar nicht als
  `full_sync_hour` auftreten (**nicht gefunden**, ob dies bewusst in Kauf genommen wird oder eine
  Lücke ist, siehe Offene Prüfpunkte).

## `bin/worker.php`, Worker

- **Aufgabe:** reserviert Jobs seines Pools und verarbeitet sie nacheinander über `job_execute()`
  (`app/jobs.php:454-506`).
- **Startbefehl/Arbeitsverzeichnis:** `php bin/worker.php --pool=lexware|stripe|mail|maintenance|all
  [--max-jobs=500] [--max-memory-mb=256] [--once] [--sleep=1]`
  (Kommentarkopf `bin/worker.php:1-11`); tatsächliche Startzeilen mit `memory_limit` per CLI-Flag,
  z. B. `["php", "-d", "memory_limit=${WORKER_MEMORY_MB:-512}M", "bin/worker.php",
  "--pool=lexware"]` (`docker-compose.yml:244`). Fünf Dienste: `worker-lexware-1`,
  `worker-lexware-2`, `worker-stripe`, `worker-mail`, `worker-maintenance`
  (`docker-compose.yml:242-261`); `worker-lexware-2` ist in Staging über ein Compose-Profil
  deaktiviert (Kommentar Zeile 36-37, Datei `docker-compose.staging.yml` nicht Gegenstand dieses
  Dokuments).
- **Benutzer:** `${APP_UID:-33}:${APP_GID:-33}`, PID 1 ohne Shell-Wrapper (`["php", ...]`, kein `sh
  -c`, Kommentar `docker-compose.yml:240-241`).
- **Voraussetzungen:** `queue_available()`, sonst Exit 3 (`bin/worker.php:44-47`); Pool muss in
  `jobs_pools()` bekannt sein, sonst Exit 2 (`bin/worker.php:21-24`).
- **Verarbeitete Daten/Batchgrößen:** ein Job je Reservierung (`queue_reserve()`,
  `app/queue.php:173-214`, `SELECT ... FOR UPDATE SKIP LOCKED`, Rückfall auf einfache Zeilensperre bei
  älterer MariaDB ohne `SKIP LOCKED`, Zeilen 184-191); Worker beendet sich planmäßig nach
  `--max-jobs` (Standard 500) oder wenn `memory_get_usage(true)` `--max-memory-mb` (Standard 256 MB)
  überschreitet (`bin/worker.php:110-113`), Docker startet den Container danach neu (`restart:
  unless-stopped`).
- **Sperre gegen Parallelität:** `SELECT ... FOR UPDATE SKIP LOCKED` lässt mehrere Worker
  gleichzeitig ohne Kollision aus derselben Warteschlange reservieren (`app/queue.php:182-191`);
  `dedupe_key` je fachlichem Auftrag verhindert doppelte gleichzeitige Jobs desselben Typs/Firma
  (`queue_push()`, `app/queue.php:117-152`).
- **Timeouts/Wiederholungen:** siehe Tabelle je Jobtyp oben (`queue_type_defaults()`,
  `app/queue.php:96-109`); gestaffelter Backoff nach Fehlversuch: 60 s, 300 s, 900 s, 3600 s
  (`QUEUE_BACKOFF`, `app/queue.php:26`), danach `failed` (Dead Letter).
- **Fehlerablage:** Tabelle `jobs` (Status `failed`, `last_error` bereinigt über
  `queue_sanitize_error()`, `app/queue.php:90-93`, keine Geheimnisse, max. 255 Zeichen), zusätzlich
  `job_runs` je Versuch; im Adminbereich einsehbar über `queue_failed_jobs()`
  (`app/queue.php:553-562`).
- **Logpfad/Überwachung:** strukturierte Logs über `app_log()` (`app/log.php`, Ziel `config('log')`:
  `stderr`/`file`/`error_log`, `app/config.example.php:243`); Heartbeat wie beim Scheduler in `jobs`/
  `worker_heartbeats` und in `WORKER_HEARTBEAT_FILE` (`bin/worker.php:31, 59`); Healthcheck
  `bin/healthcheck.php --heartbeat` (`docker-compose.yml:151`, Anker `heartbeat-healthcheck`) bzw.
  `--workers=<pool>` (mindestens ein lebender Worker je Pool, `bin/healthcheck.php:138-142`).
- **Sicheres manuelles Starten:** `docker compose exec -T worker-stripe php bin/worker.php --pool=stripe
  --once` für einen Einzeldurchlauf; regulär läuft der Worker als Dauerprozess über den
  Compose-Dienst. Skalieren zusätzlicher Worker eines Pools und Zurückskalieren: siehe
  `docs/vps/06-betrieb.md:828-846` („Worker skalieren und neu starten", dort mit den genauen Befehlen
  `docker compose up -d --scale ...`, hier nicht dupliziert, da wörtlich in dieser Datei
  dokumentiert).
- **Sicheres Stoppen/Wiederaufnehmen (SIGTERM-Modell):** `worker_signals_install()` installiert
  Handler für SIGTERM/SIGINT/SIGQUIT **vor** dem ersten Datenbankzugriff (`bin/worker.php:40-42`,
  Begründung `app/worker_signals.php:11-15`: das Basisimage `php:*-fpm` setzt `STOPSIGNAL SIGQUIT`,
  ein PID-1-Prozess ohne Handler verwirft dieses Signal und lief früher bis zum Ablauf der
  Grace-Period). Nach dem Signal wird kein neuer Job reserviert (`worker_stop_requested()`,
  `while`-Schleifenbedingung `bin/worker.php:65`); ein laufender Job endet am nächsten kooperativen
  Abbruchpunkt als Fortsetzung (`JobRequeueException`, kein Fehlversuch) oder, nur für
  unterbrechbare Typen, nach `WORKER_STOP_JOB_SECONDS` (Standard 30 s, Obergrenze 30,
  `app/worker_signals.php:53-59, 147-161`) über eine SIGALRM-Notbremse
  (`worker_arm_stop_alarm()`/`WorkerShutdownException`, `app/worker_signals.php:96-112`). Nie
  erzwungen abgebrochen werden `collections_due`, `unclear_attempts`, `mail`
  (`WORKER_NO_FORCED_ABORT_TYPES`, `app/worker_signals.php:61`). `stop_grace_period: 75s`
  (`docker-compose.yml:149`), hergeleitet als 30 s Notbremse + 30 s längster externer Aufruf
  (Stripe) + 15 s Reserve (Kommentar Zeilen 138-149).
- **Verhalten nach Neustart:** Reservierungen ohne Heartbeat (Worker hart beendet) werden von
  `queue_release_stale()` als Fehlversuch freigegeben (`app/queue.php:317-338`, aufgerufen alle 30 s
  vom Scheduler, `app/jobs.php:353`); ein bereits fertiger, aber durch die Notbremse mitten in der
  letzten Datenänderung unterbrochener Job kann als Fortsetzung erneut ausgeführt werden, laut
  Kommentar bewusst in Kauf genommenes Restrisiko, da nur fachlich unschädlich wiederholbare Typen
  unterbrechbar sind (`app/worker_signals.php:41-46`).
- **Verpasste Läufe:** kein spezieller Umgang über den allgemeinen Backoff- und
  `queue_release_stale()`-Mechanismus hinaus gefunden.

## `bin/host-metrics.php`, Metrik-Sammler

- **Aufgabe:** liest `/proc` des Hosts (unter `/hostproc` eingebunden) und das Dateisystem
  (`HOST_ROOT`) und schreibt `host_cpu`, `host_mem`, `host_disk`, `host_load1`, `db_connections`,
  `db_qps`, `db_slow_queries`, `redis_mem` als Monitoring-Ereignisse (`bin/host-metrics.php:3-9,
  27-85`); zusätzlich `host_metrics_backup_scan()` (Zeilen 119-153) liest lokale Kopien der
  Coolify-Datenbanksicherungen (`COOLIFY_BACKUP_DIR`, nur lesend) und schreibt
  `backup-status.json` in `storage_dir()`.
- **Ausführungsort:** eigener Container `metrics` (`docker-compose.yml:267-318`, kein Pool,
  kein Worker-Heartbeat in der Datenbank).
- **Auslöser/Zeitplan:** eigene Schleife, Standardintervall 60 s (`--interval`,
  `bin/host-metrics.php:23`, Kommandozeile `["php", "bin/host-metrics.php", "--interval=60"]`,
  `docker-compose.yml:272`); kein Scheduler-Bezug.
- **Startbefehl/Arbeitsverzeichnis:** wie oben, Arbeitsverzeichnis ebenfalls an `RELEASE_SHA`
  gebunden (`docker-compose.yml:302`).
- **Benutzer:** `${APP_UID:-33}:${APP_GID:-33}` (`docker-compose.yml:271`), kein Root, kein
  Zugriff auf das Wurzeldateisystem des Hosts (nur `/proc` read-only als `/hostproc`, Kommentar
  Zeilen 263-266).
- **Sicheres Stoppen:** SIGTERM (`stop_signal: SIGTERM`, `stop_grace_period: 20s`,
  `docker-compose.yml:275-276`); die Messschleife endet nach dem aktuellen Durchlauf bzw. sofort
  während des `sleep()` (durch das Signal unterbrochen, `bin/host-metrics.php:41-45, 94-97`).
- **Logpfad/Überwachung:** eigene Heartbeat-Datei (`metrics_heartbeat_touch()`,
  `bin/host-metrics.php:103-110`, kein DB-Heartbeat); Healthcheck `bin/healthcheck.php --metrics`
  prüft zweistufig: läuft `bin/host-metrics.php` tatsächlich als PID 1 (`/proc/1/cmdline`) und war der
  letzte Durchlauf innerhalb von `METRICS_MAX_AGE_SECONDS` (Standard 300 s) abgeschlossen
  (`bin/healthcheck.php:110-136`).
- **Verpasste Läufe:** einzelne fehlgeschlagene Messungen (`app_log('warning', ...)`,
  `bin/host-metrics.php:87-89`) beenden die Schleife nicht; das Lebenszeichen wird laut Kommentar
  „auch dann erneuert, wenn einzelne Messungen fehlschlagen" (Zeilen 90-93).

## `app/worker_signals.php`, gemeinsames Signalmodell

Zentrale Datei ohne eigenen Prozess, von `scheduler.php`, `worker.php`, `host-metrics.php` genutzt:

- Stop-Signale: SIGTERM (Docker `stop_signal`), SIGINT (Konsole), SIGQUIT (Altlast des
  Basisimages, `app/worker_signals.php:6-13`).
- Notbremse: `WORKER_STOP_JOB_SECONDS` (Standard 30, Umgebungsvariable oder
  `config('queue')['stop_job_seconds']`, `worker_stop_job_seconds()`, Zeilen 147-161), begrenzt auf 1
  bis `WORKER_STOP_JOB_SECONDS_MAX = 30` (Zeile 59).
- `WORKER_NO_FORCED_ABORT_TYPES = ['collections_due', 'unclear_attempts', 'mail']` (Zeile 61),   Geldfluss- und Mailzustellungs-Jobs enden ausschließlich an ihrem kooperativen Punkt.
- `worker_job_exception_outcome()` (Zeilen 202-217): jede `JobRequeueException` sowie jede
  Ausnahme, während bereits `worker_shutdown_interrupted()` gilt, zählt als Fortsetzung ohne
  Fehlversuch, auch wenn eine fremde Zwischenschicht die eigentliche
  `WorkerShutdownException` in einen anderen Fehlertyp umgedeutet hat (Kommentar Zeilen 28-33, 196-199).

## Abgrenzung Fachschema vs. Umsetzung

Diese Datei dokumentiert die technische Job-Architektur. Die betriebliche Cron-Matrix mit Prüfdatum
und die vollständige Erläuterung von Wartungsmodus, Redis-Deployment und Deployment-Ablauf stehen in
`docs/vps/06-betrieb.md` (Abschnitte „Braucht der VPS Cron-Jobs?", Zeilen 588-613, und
„Konfigurationsänderungen erreichen Dauerprozesse nur nach Neustart", Zeilen 615-643) und werden hier
nur referenziert, nicht dupliziert.

## Offene Prüfpunkte

1. **Frage:** Verhält sich `full_sync_hour` bei der Umstellung zwischen Sommer- und Winterzeit
   korrekt (weder doppelter noch ausgelassener Vollabgleich)? **Quelle:** `app/jobs.php:374,
   401-410` (Vergleich gegen `date('G', $now)` und `date('Y-m-d', $now)`, keine erkennbare
   Sonderbehandlung der Zeitumstellung). **Prüfverfahren:** Testlauf mit `test_time_offset_seconds`
   (`app/devices.php:30-33`, dort für 2FA-Fristen genutzt, gleiches Prinzip anwendbar) über eine
   simulierte Umstellungsnacht, Beobachtung von `monitor_mark_get('sched_full_<tenant_id>')`.
2. **Frage:** Ist ein manueller Neustart nur eines einzelnen Worker-Containers (statt aller fünf)
   dokumentiert und sicher, ohne dass parallel ein Deployment läuft? **Quelle:**
   `docs/vps/06-betrieb.md:828-846`. **Prüfverfahren:** Vier-Augen-Test auf dem Staging-Server:
   `docker compose stop worker-stripe && docker compose up -d worker-stripe`, danach
   `bin/healthcheck.php --workers=stripe` und Sichtprüfung, dass kein Job doppelt verarbeitet wurde.
3. **Frage:** Wie lange bleibt ein `sync_run`-Job nach einer erzwungenen Fortsetzung
   (`_continuations`) in der Warteschlange, bevor `queue_requeue()` ihn als `too_many_continuations`
   endgültig anhält? **Quelle:** `app/queue.php:240-249` (`max(10,
   config('queue')['max_continuations'] ?? 500)`, kein konkreter Wert in
   `app/config.example.php` gesetzt, Vorgabewert 500). **Prüfverfahren:** `queue_failed_jobs()` im
   Adminbereich nach Einträgen mit dieser Fehlerkategorie durchsuchen; bei Häufung
   `sync_max_steps_attempt`/`sync_attempt_seconds` gegen die tatsächliche Firmengröße prüfen.
