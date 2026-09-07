# Fehlersuche und Fehlerhandbuch

Stand 07.09.2026. Bezieht sich auf die PHP-Anwendung SmartEinzug (`php-ionos/`), den Hostinger-VPS-Stack (`deploy/vps/`) und den GitHub-Workflow (`.github/workflows/deploy.yml`). Alle Aussagen sind mit Datei und Funktion oder Zeile belegt; wo im Repository keine Angabe gefunden wurde, steht ausdrücklich „nicht gefunden“. Befehle sind mit Zielumgebung (lokal, IONOS-Webhosting, VPS) und Wirkung gekennzeichnet; destruktive Befehle tragen die Markierung DESTRUKTIV.

Grundregel für alle Fälle: Zuerst lesend prüfen (Protokolle, Statusdatei, Adminbereich), erst danach eingreifen. Bei geldrelevanten Vorgängen (Einzüge, Mandate, Abrechnung) nie „auf Verdacht“ wiederholen, siehe Fall 7.

## 1. HTTP 500 der Anwendung

**Symptom:** Aufruf einer Seite oder eines Endpunkts liefert HTTP 500 (leere oder generische Fehlerseite).

**Mögliche Ursachen:**
- PHP-Syntaxfehler oder fehlende Datei nach einem unvollständigen Deployment.
- `config.php` fehlt, ist nicht lesbar oder enthält einen Syntaxfehler (auf dem VPS als Einzeldatei-Bind-Mount eingebunden, siehe Fall 10).
- Datenbank nicht erreichbar (siehe Fall 2) oder ein unbehandelter `Throwable` in der aufgerufenen Seite.
- Auf dem VPS: `working_dir` des `php`-Dienstes zeigt auf ein nicht vollständiges Release (`RELEASE_SHA`, `deploy/vps/docker-compose.yml`).
- Wartungsmodus aktiv (`maintenance.flag`, `docs/vps/06-betrieb.md`, Abschnitt „Wartungsmodus je Firma“) liefert bewusst 503, nicht 500; bei 500 also nicht die Ursache.

**Prüfung:**
```bash
# VPS: aktuelle Fehlerprotokolle des php-Dienstes, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs --tail 200 php

# VPS: Gesundheit der Kernkomponenten, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all

# VPS/lokal: reine Syntaxprüfung der Konfigurationsdatei, keine Änderung
php -l /opt/smarteinzug/shared/config.php     # VPS
php -l php-ionos/app/config.php               # IONOS-Webhosting/lokal

# VPS: Release-Bindung des laufenden php-Containers prüfen, keine Änderung
docker inspect --format '{{.Config.WorkingDir}}' $(docker compose -f docker-compose.yml -f docker-compose.prod.yml ps -q php)

# IONOS-Webhosting: schreibgeschützter Prüfendpunkt (nur bei Neuinstallation vorhanden), keine Änderung
curl -s "https://<domain>/setup-check.php?token=<cron_token>"
```
Quellen: `php-ionos/bin/healthcheck.php` (Optionen `--db`, `--all`), `docs/vps/06-betrieb.md` Abschnitt „Systemstatus prüfen“, `deploy/vps/docker-compose.yml` (`RELEASE_SHA`, Zeile mit `working_dir`).

**Sichere Maßnahme:**
- Syntaxfehler in `config.php`: Datei korrigieren, dann `bash /opt/smarteinzug/deploy/scripts/restart-workers.sh` (VPS) bzw. Datei auf IONOS erneut hochladen; keine Container-Neuerzeugung ohne vorherige Syntaxprüfung.
- Unvollständiges Release: kein manuelles Nachbessern einzelner Dateien; stattdessen ein neues Deployment auslösen oder `bash /opt/smarteinzug/deploy/scripts/rollback.sh previous` (siehe Fall 9).
- Datenbank nicht erreichbar: siehe Fall 2, HTTP 500 klärt sich mit der Datenbankverbindung von selbst.

**Erfolgskontrolle:**
```bash
curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/health.php
```
Erwartet: `200` und `{"php":true, ...}` im Body (`php-ionos/health.php`, geprüft auch extern im Workflow, `.github/workflows/deploy.yml`, Schritt „Health-Check von außen“).

**Eskalation:** Hält der Fehler nach Rollback und Neustart der Hintergrunddienste an, oder betrifft er eine geldrelevante Seite (Einzüge, Abrechnung), Geschäftsführung informieren, bevor weitere Eingriffe vorgenommen werden (Freigabe nach den organisationsweiten Vorgaben für haftungsrelevante Vorgänge).

## 2. Datenbank nicht erreichbar (Coolify-MariaDB)

**Symptom:** `bin/healthcheck.php --db` meldet „ungesund“, Anwendung zeigt Datenbankfehler, Kategorie `database` in `monitor_category()`.

**Mögliche Ursachen:**
- Coolify-MariaDB-Ressource neu gestartet, migriert oder kurzzeitig nicht erreichbar.
- Falscher Containername/Zugangsdaten in `shared/config.php` (`db.host`, `db.user`, `db.pass`), z. B. nach einer Neuinstallation der Coolify-Ressource.
- Netzproblem zwischen PHP-Containern und der Coolify-MariaDB (beide im Netz `coolify`, siehe `deploy/vps/docker-compose.yml`).
- Zu viele gleichzeitige Verbindungen (Hostmetriken im Adminbereich, Reiter Server, zeigen Datenbankverbindungen laut `docs/vps/06-betrieb.md`, Abschnitt „Systemstatus prüfen“).

**Prüfung:**
```bash
# VPS: reiner Lesetest, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --db

# VPS: liegt die Datenbank im selben Netz, ist sie per Hostname erreichbar? keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php getent hosts <containername-der-coolify-mariadb>

# VPS: Zustand der Coolify-Ressource selbst (im Coolify-Dashboard oder)
docker ps --filter name=<containername-der-coolify-mariadb>
```
Quelle: `php-ionos/app/monitor.php` Funktion `_mon_check_db()` (Latenzschwelle `latency_warn_ms.db`, Standard 500 ms), `php-ionos/bin/healthcheck.php` Zeile 44.

**Sichere Maßnahme:**
- Ressource in Coolify prüfen und bei Bedarf dort neu starten (Coolify verwaltet diese Ressource, nicht der SmartEinzug-Stack, `docs/vps/06-betrieb.md` Zeile 8 ff.).
- Zugangsdaten in `shared/config.php` korrigieren, danach zwingend `bash /opt/smarteinzug/deploy/scripts/restart-workers.sh` (Konfigurationsänderung wirkt sonst nicht, siehe Fall 10).
- Kein manuelles `docker restart` einzelner PHP-Container während eines laufenden Deployments (Sperre `.deploy.lock`, `docs/vps/06-betrieb.md` Abschnitt „Konfigurationsänderungen erreichen Dauerprozesse nur nach Neustart“).

**Erfolgskontrolle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --db
```
Erwartet: `OK` (Exit 0).

**Eskalation:** Bleibt die Datenbank länger als wenige Minuten nicht erreichbar, sind Einzüge und Synchronisation systemweit betroffen; Geschäftsführung informieren, insbesondere wenn das Einreichfenster für Lastschriften (`collections_window_open()`, `php-ionos/app/collections.php:178`) in diesem Zeitraum liegt.

## 3. Redis nicht erreichbar

**Symptom:** `bin/healthcheck.php --redis` meldet eine Fehlerkategorie (`alias_missing`, `dns`, `network_mismatch`, `alias_ambiguous`, `connection_refused`, `timeout`, `network_unreachable`, `redis_protected_mode`, `auth`, `protocol`).

**Wichtig:** Redis ist in dieser Anwendung ausdrücklich optional. Sperren und Ratenbegrenzung fallen ohne Redis automatisch auf die Datenbank zurück (`docs/vps/06-betrieb.md` Zeile 271 ff., `app/redis.php`). Ein Ausfall von Redis ist kein fachlicher Fehler, aber ein Betriebsrisiko (höhere Datenbanklast).

**Mögliche Ursachen und ihre Kategorie** (`redis_probe()`, referenziert in `php-ionos/bin/healthcheck.php` Zeile 47 ff., Diagnosepfad ausführlich in `docs/vps/06-betrieb.md`, Abschnitte „Störung: Candidate-Prüfung meldet redis: other“ bis „Nachtrag Version 4.10“):

| Kategorie | Ursache |
|---|---|
| `alias_missing` | Der Docker-Alias `smarteinzug-redis` fehlt im internen Netz (Compose-Definition unvollständig übernommen). |
| `dns` | Der konfigurierte Hostname lässt sich nicht auflösen. |
| `network_mismatch` | Der Name löst in ein anderes Netz auf als erwartet (`SMARTEINZUG_REDIS_EXPECTED_CIDR`); häufigste bestätigte Ursache war die Namenskollision mit Coolifys eigenem Redis-Dienst, siehe unten. |
| `alias_ambiguous` | Der Name löst zu mehreren Adressen auf. |
| `connection_refused`, `timeout`, `network_unreachable` | TCP-Verbindung scheitert (Dienst nicht gestartet, Firewall, falsches Netz). |
| `redis_protected_mode` | Redis' eigener „protected mode“ ohne gesetztes Passwort lehnt jeden Nicht-Loopback-Zugriff ab. |
| `auth` | Redis verlangt ein Passwort, das nicht (korrekt) übergeben wurde. |
| `protocol` | Gegenstelle antwortet nicht im Redis-Protokoll (falscher Dienst am Port). |

**Prüfung:**
```bash
# VPS: stufenweise Diagnose mit DIAGNOSE-Zeile (Passwörter maskiert), keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --redis

# VPS: löst der eindeutige Alias tatsächlich in das interne Netz auf? keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php getent hosts smarteinzug-redis

# VPS: NICHT verwenden zur Diagnose, da es fälschlich Gesundheit vortäuscht
#   (läuft über die Loopback-Adresse des redis-Containers selbst, siehe docs/vps/06-betrieb.md,
#   Abschnitt "Nachtrag Version 4.8"):
# docker exec smarteinzug-redis-1 redis-cli ping   # NICHT als alleinigen Nachweis verwenden
```

**Sichere Maßnahme:**
- `alias_missing`/`network_mismatch`: `deploy/vps/docker-compose.yml` prüfen (Alias `smarteinzug-redis` nur im internen Netz, `SMARTEINZUG_REDIS_HOST`/`SMARTEINZUG_REDIS_EXPECTED_CIDR` in jedem PHP-Dienst gesetzt); Korrektur nur über ein reguläres Deployment, das `tools/redis-deploy-check.sh`- und `tools/staging-isolation-check.py`-geprüfte Reihenfolge (Validierung, gezieltes `--force-recreate redis`, Netzwerktest aus einem anderen Container) durchläuft, siehe Fall 9.
- `redis_protected_mode`: `deploy/vps/redis/redis.conf` muss `protected-mode no` enthalten (nur vertretbar, weil Redis ausschließlich am internen Netz ohne veröffentlichten Port hängt); Änderung ebenfalls nur über ein Deployment, niemals durch manuelles `redis-cli CONFIG SET` am laufenden Container (würde beim nächsten Deployment wieder überschrieben und ist nicht dokumentiert).
- **DESTRUKTIV, nur mit Bedacht:** Ein gezielter Neustart ausschließlich des Redis-Dienstes (`docker compose ... up -d --no-deps --force-recreate redis`) leert den Inhalt von Redis (Sperren, Ratenbegrenzungszähler); unkritisch, da reiner Cache-/Sperrzustand ohne Kundendaten, aber während des Neustarts kurzzeitig ohne Redis-gestützte Sperren (Fallback Datenbank greift automatisch).

**Erfolgskontrolle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --redis
```
Erwartet: `OK`, DIAGNOSE-Zeile mit `kategorie=ok` und der aufgelösten Adresse aus dem erwarteten Subnetz.

**Eskalation:** Bei wiederholtem `redis_protected_mode` oder `network_mismatch` nach einem Deployment: nicht selbst an `redis.conf` oder Compose-Netzwerken herumprobieren, sondern `docs/vps/06-betrieb.md` (Abschnitte zu den Versionen 4.6 bis 4.10) und `tools/healthcheck-redis-check.php` konsultieren; bei Unsicherheit Geschäftsführung/technische Leitung einbeziehen, bevor produktiv am Netzwerk geändert wird.

## 4. Langsame oder hängende Synchronisation

**Symptom:** Rechnungen aktualisieren sich nicht, `sync_state` einer Firma bleibt lange auf `running`, Adminbereich zeigt „Wartende Aufgaben“ mit offenem Synchronisationslauf.

**Mögliche Ursachen:**
- Hart beendeter Worker (SIGKILL, Absturz) hinterlässt einen `running`-Lauf ohne Fortschritt (`sync_state.php:222` `_sync_lock_lost()`; Zeitgrenze `SYNC_STALE_MINUTES = 30`, `sync_state.php:27`).
- Lexware-Office-API langsam oder gestört (Circuit Breaker öffnet, siehe `docs/vps/06-betrieb.md` Abschnitt „Circuit Breaker“).
- Großer Rechnungsbestand: `sync_invoices_step()`/`sync_invoices()` (`php-ionos/app/sync.php:94`, `:333`) paginieren vollständig ohne Datumsfilter (`docs/payment-safety.md` Abschnitt 5d), ein Erstimport kann entsprechend lange dauern.
- Firma pausiert (`organizations.sync_paused`), dann läuft absichtlich keine Synchronisation (`docs/vps/06-betrieb.md` Abschnitt „Wartungsmodus je Firma“).
- Auf dem VPS: Scheduler reiht die Fortsetzung eines verwaisten Laufs seit der Behebung des entsprechenden Vorfalls sofort ein, nicht erst zur nächsten regulären Fälligkeit (`app/jobs.php:358` `scheduler_auto_sync()`, Regressionstest `tools/scheduler-sync-check.sh`).

**Prüfung:**
```bash
# VPS: Zustand des Synchronisationslaufs einer Firma, keine Änderung (SQL nur lesend)
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php -r '
require "/opt/smarteinzug/releases/current/app/bootstrap.php";
require_once "/opt/smarteinzug/releases/current/app/sync_state.php";
var_export(sync_state_get("<tenant_id>"));'

# VPS: offene Läufe systemweit, keine Änderung
# Adminbereich System > Jobs > Wartende Aufgaben (2FA-geschützte Aktionen dort, kein SQL nötig)

# VPS: Circuit Breaker der Lexware-Anbindung, keine Änderung
# Adminbereich System > Jobs > Anbindungen
```
Quellen: `php-ionos/app/sync_state.php` (`sync_state_get()`, `sync_state_is_running()` Zeile 45, `sync_open_runs()` Zeile 441), `docs/vps/06-betrieb.md` Abschnitt „Kennzahlen im Adminbereich und ihre Aufschlüsselung“.

**Sichere Maßnahme:**
- Adminbereich System > Jobs > Wartende Aufgaben: Aktion „Fortsetzung einreihen“ setzt den offenen Lauf sofort auf fällig, ohne den bisherigen Zwischenstand zu verlieren (`docs/vps/06-betrieb.md` Zeile 763 f.); für pausierte Firmen ist die Aktion gesperrt.
- „Reservierung freigeben“ nur, wenn der Heartbeat des zugehörigen Workers tatsächlich abgelaufen ist (die Anwendung verweigert die Freigabe sonst selbst); die automatische Freigabe (`queue_release_stale()`) zählt im Unterschied dazu einen Fehlversuch.
- Nie manuell `sync_state` per SQL auf `idle` zurücksetzen, während ein Worker noch tatsächlich läuft (Race mit dem laufenden Prozess); stattdessen den Heartbeat abwarten oder den betroffenen Worker gezielt neu starten (`docs/vps/06-betrieb.md` Abschnitt „Worker skalieren und neu starten“).

**Erfolgskontrolle:** Adminbereich System > Jobs zeigt den Lauf wieder als `running` mit fortschreitendem Zeitstempel, danach als `success`; `sync_progress()` (`app/sync_state.php:325`) liefert einen aktuellen Fortschrittswert.

**Eskalation:** Bleibt eine Firma über mehrere Stunden ohne Fortschritt und der Circuit Breaker der Lexware-Anbindung ist offen, externe Störung bei Lexware Office prüfen, nicht nur die eigene Konfiguration (`docs/vps/06-betrieb.md` Abschnitt „Circuit Breaker“).

## 5. Festhängende Jobs

**Symptom:** Ein Job bleibt lange im Zustand `processing`/`running`, ohne dass sich der Heartbeat aktualisiert; Adminbereich zeigt ihn unter „Ausführung unbestätigt“.

**Mögliche Ursachen:**
- Worker hart beendet (SIGKILL nach Ablauf der Notbremse `WORKER_STOP_JOB_SECONDS`, höchstens 30 s, oder nach der Docker-Grace-Period von 75 s, siehe `docs/vps/06-betrieb.md` Abschnitt „Signalmodell der Worker“).
- Externer Aufruf (Lexware, Stripe) hängt ohne Rückmeldung über die konfigurierten Timeouts hinaus.
- Datenbankverbindung während des Jobs verloren (siehe Fall 2).
- Wartungsmodus wurde während eines laufenden Jobs aktiviert; der Job wird regulär zu Ende gebracht, reserviert aber keinen neuen (`docs/vps/06-betrieb.md` Abschnitt „Wartungsmodus je Firma“).

**Prüfung:**
```bash
# VPS: Warteschlange auf Jobs mit abgelaufenem Heartbeat, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --queue

# VPS: Heartbeat/PID 1 eines konkreten Worker-Containers, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec worker-stripe php bin/healthcheck.php --heartbeat

# VPS: Protokoll des betroffenen Jobs über seine Correlation-ID, keine Änderung
docker compose logs php scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance 2>&1 \
  | grep '"correlation_id":"<id-aus-dem-adminbereich>"'
```
Quellen: `php-ionos/bin/healthcheck.php` Zeile 147 ff. (`--queue`), `docs/vps/06-betrieb.md` Abschnitt „Logs“ und „Kennzahlen im Adminbereich und ihre Aufschlüsselung“.

**Sichere Maßnahme:**
- Adminbereich System > Jobs > Wartende Aufgaben: „Reservierung freigeben“ (ohne Fehlversuch) nur bei tatsächlich abgelaufenem Heartbeat, sonst wird ein noch laufender Job dem Worker entzogen.
- Adminbereich System > Jobs > Fehlgeschlagen (Dead Letter): „Erneut versuchen“ (`queue_retry_now`) erst NACH Klärung der Ursache (`last_error`, Protokoll über die `correlation_id`); ein wiederholter Versuch ohne Ursachenklärung führt in der Regel zum selben Fehler (`docs/vps/06-betrieb.md` Abschnitt „Dead Letter behandeln“).
- „Abbrechen“ (`queue_cancel`) setzt den Job auf `cancelled`, der fachliche Vorgang gilt dann als nicht abgeschlossen; „Dauerhaft schließen“ (`queue_close`) nur, wenn der Vorgang anderweitig erledigt wurde.
- Bei geldbewegenden Jobtypen (`collections_due`, `unclear_attempts`) und `mail`: Diese sind bewusst nicht kooperativ unterbrechbar (`docs/vps/06-betrieb.md` Zeile 502 ff.); ein hängender Job dieser Typen niemals per SQL beenden, sondern die Heartbeat-Freigabe abwarten oder erst nach Prüfung im Stripe-Dashboard eingreifen (siehe Fall 7).

**Erfolgskontrolle:** Job verschwindet aus „Ausführung unbestätigt“ bzw. erscheint mit `status = success` in der Jobhistorie.

**Eskalation:** Häufung festhängender Jobs eines bestimmten Typs deutet auf eine strukturelle Störung der externen Anbindung hin (Circuit Breaker prüfen, Fall 6/7); bei geldbewegenden Jobtypen vor jedem manuellen Eingriff die Freigabe durch die Geschäftsführung einholen.

## 6. Abgewiesene Webhooks

**Symptom:** Stripe zeigt fehlgeschlagene Zustellversuche für den Webhook-Endpunkt einer Firma oder der Plattform-Abrechnung.

**Mögliche Ursachen:**
- Fehlende Signatur oder fehlerhaftes JSON (`stripe-webhook.php:46` ff. `webhook_exit('fehlende Signatur')`, `:52` `webhook_exit('ungültiges JSON')`).
- Firma nicht ermittelbar aus den Metadaten oder über `payment_intent`/`charge` (`stripe-webhook.php:99` f.).
- Keine aktive Stripe-Integration oder kein Webhook-Secret für die Firma hinterlegt (`stripe-webhook.php:110`, `:115`).
- Signaturprüfung schlägt fehl, weil das in Stripe hinterlegte Secret nicht mit `integrations.stripe_webhook_secret_encrypted` übereinstimmt (`stripe-webhook.php:119`, Funktion `stripe_verify_webhook_signature()`).
- Wartungsmodus aktiv: Stripe-Webhooks erhalten dann bewusst 503 (`docs/vps/06-betrieb.md` Abschnitt „Wartungsmodus je Firma“); Stripe wiederholt automatisch, aber nicht unbegrenzt.
- Bei der Plattform-Abrechnung (`billing-webhook.php`): fehlendes Ereignis aus `BILLING_REQUIRED_WEBHOOK_EVENTS` (`php-ionos/app/billing_setup.php:21`) im Stripe-Dashboard nicht abonniert.

**Wichtig:** Der Firmen-Webhook (`stripe-webhook.php`) antwortet in jedem Fall mit HTTP 200 (`stripe-webhook.php:24`), damit Stripe nicht endlos wiederholt; ein „abgewiesener“ Webhook zeigt sich deshalb nicht als Fehlerstatus bei Stripe, sondern als Eintrag in `error_log`/den Container-Protokollen und als `monitor_event('stripe_webhook', ...)` mit `status = fail` (`stripe-webhook.php:27` ff.).

**Prüfung:**
```bash
# VPS: Protokollzeilen des Webhooks, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs --since 2h php | grep "Stripe-Webhook"

# Stripe-Dashboard (pro Firma bzw. Plattformkonto): Entwickler > Webhooks > Endpunkt > "Ereignisse
# erneut senden" für ein konkretes fehlgeschlagenes Ereignis; erst NACH Klärung der Ursache
```
Quelle: `php-ionos/stripe-webhook.php` (vollständig gelesen, Zeilen 1 bis 299), `php-ionos/app/monitor.php` Funktion `monitor_event()`.

**Sichere Maßnahme:**
- Fehlendes/falsches Webhook-Secret: in den Firmeneinstellungen (Integrationen) das Secret aus dem Stripe-Dashboard erneut hinterlegen; kein Workaround über eine Signaturprüfung, die deaktiviert wird.
- Firma nicht ermittelbar: prüfen, ob `metadata.tenant_id` beim Erzeugen des PaymentIntents/der Checkout Session gesetzt wurde (`_execute_stripe_collection()`, `php-ionos/app/collections.php:1108`); kein manuelles Nachtragen von Metadaten bei Stripe.
- Wartungsmodus: Fenster kurz halten (Adminbereich warnt ab 12 Stunden, `docs/vps/06-betrieb.md` Zeile 895), nach Ende des Wartungsmodus fehlgeschlagene Ereignisse im Stripe-Dashboard gezielt erneut senden.
- Plattform-Abrechnung: fehlendes Ereignis im Stripe-Dashboard nachtragen; Sollzustand mit `bin/billing-check.php` (nur lesend) abgleichen.

**Erfolgskontrolle:** Stripe-Dashboard zeigt den erneuten Zustellversuch als erfolgreich (HTTP 200); in den Anwendungsprotokollen erscheint die zugehörige Verarbeitungsmeldung (z. B. „Erstattung übernommen“, „Mandat digital erteilt“) statt eines `webhook_exit()`-Grunds.

**Eskalation:** Häufung von „Firma nicht ermittelbar“ oder Signaturfehlern über mehrere Firmen deutet auf ein strukturelles Problem hin (z. B. nach einer Datenmigration); vor einem erneuten Massen-Zustellversuch in Stripe Rücksprache mit der Geschäftsführung, da dies bereits verarbeitete Zahlungsereignisse erneut auslösen kann.

## 7. Uneindeutige Zahlungszustände (unklare Einzugsversuche)

**Symptom:** Ein Einzugsversuch bleibt im Zustand `pending` oder `unknown` in `collection_attempts`; die Oberfläche zeigt „Unklare Versuche“.

**Grundsatz (verbindlich, siehe `docs/payment-safety.md` Abschnitt 3): Bei einem unklaren Versuch NIE blind erneut einreichen.** Ein zweiter Stripe-Aufruf ohne Klärung kann einen Doppeleinzug auslösen. Die Eindeutigkeitsregel verhindert bereits auf Anwendungsebene einen weiteren Versuch, solange ein Versuch `pending` oder `unknown` ist oder ein `succeeded`-Versuch ohne zugehörigen Einzugsdatensatz existiert (`docs/payment-safety.md` Abschnitt 3, Absatz „Eindeutigkeitsregel“).

**Mögliche Ursachen:**
- Zeitüberschreitung oder Netzwerkfehler beim Stripe-Aufruf, Ergebnis unbekannt (`docs/payment-safety.md` Abschnitt 3, Zustand `unknown`).
- Worker während eines laufenden Einzugs hart beendet (Notbremse greift bei Geldfluss-Jobs bewusst nicht, siehe `docs/vps/06-betrieb.md` Zeile 503).
- Terminierter Einzug wurde parallel von Cron und manuellem Button ausgelöst (durch atomares Setzen auf `submitting` verhindert, `docs/payment-safety.md` Abschnitt 3, vorletzter Absatz).

**Prüfung:**
```bash
# Adminbereich/Rechnungsansicht der Firma: Button "Unklare Versuche prüfen"
#   -> ruft collection_attempts_resolve() auf (app/collections.php:731), reiner Lesezugriff bei
#      Stripe über die Search API bzw. GET auf den PaymentIntent; kein neuer Einzug wird ausgelöst.

# Stripe-Dashboard (read-only): PaymentIntent anhand des attempt_key in den Metadaten suchen,
# bevor irgendetwas in der Anwendung verändert wird.
```
Quelle: `php-ionos/app/collections.php` Funktionen `collection_attempts_open()` (Zeile 503), `collection_attempts_resolve()` (Zeile 731), `collection_attempt_recover()` (Zeile 709); `docs/payment-safety.md` Abschnitt 3.

**Sichere Maßnahme:**
- Ausschließlich über den Button „Unklare Versuche prüfen“ (`collection_attempts_resolve()`) klären; er trägt einen gefundenen Einzug nach oder setzt einen nicht auffindbaren, älter als 10 Minuten alten Versuch auf `failed` (Rechnung wieder einziehbar).
- Versuche `pending` unter 15 Minuten bleiben bewusst unangetastet (Suchindex bei Stripe braucht Zeit); vor Ablauf dieser Frist nicht manuell eingreifen.
- **DESTRUKTIV, nur nach Prüfung im Stripe-Dashboard:** Manuelle SQL-Klärung ausschließlich in der in `docs/payment-safety.md` Abschnitt 3 genannten Form:
  ```sql
  UPDATE collection_attempts SET status = 'failed', error_text = 'manuell geprüft, kein PaymentIntent' WHERE id = '<id>';
  ```
  Nur ausführen, wenn im Stripe-Dashboard bestätigt wurde, dass zu diesem Versuch tatsächlich kein PaymentIntent existiert; sonst droht ein Doppeleinzug beim nächsten regulären Versuch.
- Nach einer Rücklastschrift (`charge.dispute.created`) ist ein Neu-Einzug nur manuell möglich und durchläuft erneut alle Prüfungen aus `docs/payment-safety.md` Abschnitt 1, insbesondere den Live-Restbetrag bei Lexware Office.

**Erfolgskontrolle:** Der Versuch verschwindet aus der Liste „Unklare Versuche“; Audit-Log zeigt `collection_attempt_recovered` oder `collection_attempt_cleared` (`docs/payment-safety.md` Abschnitt 3).

**Eskalation:** Jede manuelle SQL-Klärung eines Zahlungsversuchs sowie jeder Neu-Einzug nach Rücklastschrift ist eine haftungsrelevante, geldbewegende Erklärung im Sinne der organisationsweiten Eskalationsregeln; Freigabe durch die Geschäftsführung einholen, bevor der Versuch als geklärt geschlossen wird.

## 8. E-Mail-Fehler

**Symptom:** Bestätigungs-, Willkommens-, Sicherheits- oder Vorabankündigungsmails werden nicht zugestellt; SMTP meldet z. B. Code 535 (Authentifizierung fehlgeschlagen).

**Mögliche Ursachen:**
- `mail.enabled = false`: Die Anwendung sendet dann grundsätzlich nichts; Registrierungen und Vormerkungen werden gespeichert und als wartend markiert (`users.welcome_mail_pending`, `interest_registrations.mail_pending`, Migration 021).
- Falsche SMTP-Zugangsdaten oder falscher Port/Verschlüsselungstyp in `mail.smtp` (`shared/config.php`).
- `reply_to`/`from_address` keine gültige E-Mail-Adresse (`bin/mail-check.php:37` ff., gibt eine Warnung aus).
- Konfigurationsänderung an `mail.*` wurde vorgenommen, aber die Hintergrunddienste wurden nicht neu erzeugt (siehe Fall 10); `worker-mail` liest `mail.enabled` weiterhin mit dem alten Stand.

**Prüfung:**
```bash
# VPS: Konfiguration und Betriebspfad anzeigen, keine Änderung, keine Zugangsdaten im Klartext
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php

# VPS: Testmail direkt über den Transport senden (ohne Warteschlange), sendet TATSÄCHLICH eine E-Mail
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php --send=<empfänger-adresse>
```
Quelle: `php-ionos/bin/mail-check.php` (vollständig gelesen), `php-ionos/app/mailer.php` Funktionen `mail_enabled()` (Zeile 20), `mail_send_queued()` (Zeile 109), `mail_last_error()` (Zeile 688).

**Sichere Maßnahme:**
- SMTP-Zugangsdaten korrigieren (z. B. Code 535: Postfachpasswort oder Benutzername prüfen), danach `bash /opt/smarteinzug/deploy/scripts/restart-workers.sh` (VPS) ausführen, weil `worker-mail` die Konfiguration nur beim Start liest.
- `mail.enabled` einschalten, sobald der Versand eingerichtet ist: Wartende Nachrichten werden automatisch nachgesendet, kein manueller Eingriff je Datensatz nötig.
  - Willkommensmails: `auth_send_pending_welcome_mails()` (`php-ionos/app/auth.php:1137`), aufgerufen aus `job_maintenance()` (`php-ionos/app/jobs.php:295`).
  - Vormerkungsbestätigungen: `interest_send_pending()` (`php-ionos/app/interest.php:242`), aufgerufen aus `job_maintenance()` (`php-ionos/app/jobs.php:294`).
  - Auf dem VPS läuft `job_maintenance` stündlich über den Scheduler (`docs/vps/06-betrieb.md`, Tabelle „Braucht der VPS Cron-Jobs?“); ohne Warteschlange (IONOS-Webhosting) übernimmt das `cron.php`.
- Nie eine „stille“ Bestätigung ohne E-Mail programmieren oder vortäuschen: Der markierte Zustand (`mail_pending`/`welcome_mail_pending`) ist genau dafür da, dass nichts verloren geht, bis der Versand tatsächlich gelingt.

**Erfolgskontrolle:**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php --send=<eigene-testadresse>
```
Erwartet: Exit 0, „Testmail an ... übergeben“; danach Posteingang und Spam-Ordner der Testadresse prüfen. Im Adminbereich zeigt die Komponente „E-Mail“ (`monitor_component_defs()['mail']`, `php-ionos/app/monitor.php:709`) den Zustand `ok`.

**Eskalation:** Hält der Fehler nach korrigierten Zugangsdaten an oder betrifft er Sicherheitsbenachrichtigungen (2FA-Reset, Eigentümerwechsel), IT-/Geschäftsführung informieren, da Betroffene sonst ohne Kenntnis sicherheitsrelevanter Vorgänge bleiben.

## 9. Fehlgeschlagene Deployments

**Symptom:** GitHub-Workflow-Job `deploy-vps` meldet Fehler, oder `deploy-status.sh` zeigt `phase: "failed"`.

**Mögliche Ursachen (mit Bezug auf die dokumentierten Vorfälle):**
- Candidate-Prüfung schlägt fehl (Datenbank oder Redis im neuen Code nicht erreichbar) → laufende Container unverändert, kein Rollback nötig (`docs/vps/06-betrieb.md` Abschnitt „Migrationsreihenfolge“).
- Migration schlägt fehl → ebenfalls kein Cutover, siehe `docs/migrations.md`.
- SSH-Verbindungsabbruch während der Übertragung oder beim Auslösen (`docs/vps/06-betrieb.md` Abschnitt „SSH-Fehler des Deployments“); seit Version 4.17 automatisch bis zu vier Wiederholungsversuche über `.github/scripts/vps-ssh-retry.sh`.
- Fehlender Vollständigkeitsnachweis `.release-complete` (unvollständiger rsync); `deploy.sh` verweigert die Auslieferung ohne diese Datei (`docs/vps/06-betrieb.md` Abschnitt „Nachweis eines vollständigen Release“).
- Health-Check nach der Aktivierung schlägt fehl → automatisches Rollback.

**Prüfung:**
```bash
# VPS: aktueller Deploy-Status samt Protokollauszug, keine Änderung
bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 80

# VPS: laufen alle PHP-Container mit dem erwarteten Release? keine Änderung
export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/healthcheck.php --all

# GitHub: vollständiges Protokoll des Workflow-Laufs (Job deploy-vps), keine Änderung
```
Quellen: `deploy/vps/scripts/deploy-status.sh`, `deploy/vps/scripts/deploy-runner.sh` (vollständig gelesen), `deploy/vps/scripts/deploy.sh` (Schritte `candidate`, `migration`, `cutover`, Zeilen 493 ff., 507 ff., 523 ff.), `docs/vps/06-betrieb.md` Abschnitt „Deployment: Ablauf und Ausfallsicherheit“.

**Sichere Maßnahme:**
- Bei fehlgeschlagener Candidate-Prüfung oder Migration: Ursache im Protokoll klären (Redis-Diagnose, Datenbankverbindung, Migrationsfehler laut `docs/migrations.md`); die laufende Anwendung ist unverändert, kein Zeitdruck für einen Rollback.
- Bei fehlgeschlagenem Health-Check nach dem Cutover: `deploy.sh` löst automatisch ein Rollback aus; Erfolg dieses automatischen Rollbacks über `deploy-status.sh --tail` prüfen.
- **Manuelles Rollback (planvoller Eingriff, kein Datenverlust, da additive Migrationen):**
  ```bash
  bash /opt/smarteinzug/deploy/scripts/rollback.sh previous
  # oder gezielt auf ein bestimmtes, noch vorhandenes Release:
  bash /opt/smarteinzug/deploy/scripts/rollback.sh <git-sha>
  ```
  Wirkung: Wechselt nur den Anwendungscode zurück, spielt keine Migrationen ein und rollt die Datenbank nicht zurück (`deploy/vps/scripts/rollback.sh` Zeile 9 ff.); bricht ab, wenn die Datenbank Migrationen enthält, die das Zielrelease nicht kennt, außer mit `FORCE_ROLLBACK=1` nach bewusster Prüfung.
- Bei SSH-Erreichbarkeitsproblemen: Prüfschritte aus `docs/vps/06-betrieb.md` Abschnitt „SSH-Fehler des Deployments“ (Firewall, fail2ban, Hostinger-Firewall im hPanel, Hostkey) abarbeiten; die Wartefrist des Pollings zu erhöhen ist ausdrücklich NICHT die Lösung (dokumentiert ebenda).
- Nie während eines laufenden Deployments manuell Container neu erzeugen oder `restart-workers.sh` ausführen (beide teilen sich die Sperre `.deploy.lock`; ein gleichzeitiger Zugriff wurde bereits einmal zur Ursache einer falschen Release-Bindung, `docs/vps/06-betrieb.md` Zeile 636 f.).

**Erfolgskontrolle:**
```bash
bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 20
curl -s https://<app-domain>/health.php
```
Erwartet: `phase: "success"`, Health-Check `200`/`php:true`.

**Eskalation:** Bleibt ein Rollback ohne Erfolg oder ist die Datenbank durch eine fehlgeschlagene Migration in einem `failed`/`unknown`-Zustand, Geschäftsführung/technische Leitung vor jedem weiteren Eingriff informieren; kein `FORCE_ROLLBACK=1` ohne vorherige Prüfung laut `docs/migrations.md`.

## 10. Konfigurationsänderung wirkt nicht

**Symptom:** Eine Änderung an `shared/config.php` (z. B. `mail.enabled`, `status_publish`, `billing`) zeigt sich in der Weboberfläche, aber nicht im Verhalten von Scheduler/Worker/Metrik-Sammler, oder gar nicht.

**Ursache (bestätigter Vorfall 07.09.2026, `docs/vps/06-betrieb.md` Abschnitt „Konfigurationsänderungen erreichen Dauerprozesse nur nach Neustart“):**
- `shared/config.php` ist als Einzeldatei per Bind-Mount eingebunden; Docker bindet dabei den Inode. Werkzeuge wie `sed -i`, die meisten Editoren und `cp neu config.php` schreiben eine neue Datei mit neuem Inode; laufende UND lediglich neu gestartete Container sehen weiterhin den alten Inhalt.
- Scheduler, Worker und Metrik-Sammler lesen `config.php` nur einmal beim Containerstart (`app/bootstrap.php`); php-fpm hält sie zusätzlich wegen `opcache.validate_timestamps=0` (`deploy/vps/php/php.ini`) bis zu einem Reload im Cache.

**Prüfung:**
```bash
# VPS: Inode auf dem Host mit dem Inode im php-Container vergleichen, keine Änderung
stat -c %i /opt/smarteinzug/shared/config.php
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php stat -c %i /opt/smarteinzug/shared/config.php

# VPS: Gegenprobe, wie der php-Container die Konfiguration tatsächlich liest, keine Änderung
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T php php bin/mail-check.php
```

**Sichere Maßnahme:**
```bash
# VPS: einziger vorgesehener Weg, Wirkung siehe unten
bash /opt/smarteinzug/deploy/scripts/restart-workers.sh
```
Ablauf des Skripts (`deploy/vps/scripts/restart-workers.sh`, vollständig gelesen): Syntaxprüfung in einem frischen Container → Hintergrunddienste neu erzeugen (`up -d --force-recreate --no-deps`) → Inode-Vergleich → bei Abweichung zusätzlich den `php`-Container neu erzeugen, sonst nur `php-fpm` per SIGUSR2 neu laden → Gegenprobe. Das Skript hält dieselbe Sperre wie `deploy-runner.sh` (`.deploy.lock`): Läuft ein Deployment, bricht es mit Exit 2 ab; läuft es selbst, weist es ein gleichzeitig ausgelöstes Deployment ab.

**Nie verwenden:** `docker compose restart` allein (löst weder den Inode- noch den OPcache-Zustand); manuelles `sed -i` an `config.php` ohne anschließenden Lauf von `restart-workers.sh`.

**Erfolgskontrolle:** Ausgabe von `restart-workers.sh` zeigt am Ende die Gegenprobe (`mail.enabled`, `reply_to`, `status_publish`) mit dem neuen, erwarteten Stand.

**Eskalation:** Nicht erforderlich bei planmäßiger Ausführung; bei wiederholtem Scheitern der Syntaxprüfung technische Leitung einbeziehen, bevor die Konfigurationsdatei erneut bearbeitet wird.

## 11. Statusseite zeigt „Status unbekannt“

**Symptom:** `status.smart-einzug.de` zeigt durchgehend „Status unbekannt“ statt tatsächlicher Werte.

**Mögliche Ursachen:**
- `status_publish` in `shared/config.php` nicht gesetzt: Ohne diese Einstellung veröffentlicht `monitor_collect()` nichts (`php-ionos/app/monitor.php:640`, `docs/vps/06-betrieb.md` Abschnitt „Störungsanzeige für Benutzer“).
- Statische Seite liegt zwar im Release (`websites/status.smart-einzug.de` → `releases/<sha>/status/`), aber `status.json` unter `/opt/smarteinzug/shared/status/status.json` fehlt oder ist veraltet (Caddy bindet diesen Ordner nur lesend ein).
- Sammler (`monitor_collect()`) lief lange nicht (Cron/Scheduler gestört, siehe Fall 4/5).
- DNS für `status.smart-einzug.de` noch nicht auf den ausliefernden Server umgestellt (`docs/status-page.md`).

**Prüfung:**
```bash
# VPS: liegt eine aktuelle status.json vor? keine Änderung
ls -la /opt/smarteinzug/shared/status/status.json
cat /opt/smarteinzug/shared/status/status.json | head -20

# VPS: läuft der Sammler regelmäßig? keine Änderung (Alter des letzten Laufs)
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec worker-maintenance php bin/healthcheck.php --heartbeat
```
Quelle: `php-ionos/app/monitor.php` Funktionen `monitor_collect()` (Zeile 554, `status_publish()`-Aufruf Zeile 640), `monitor_public_snapshot()` (referenziert in `docs/status-page.md`); `docs/vps/06-betrieb.md` Abschnitt „Störungsanzeige für Benutzer“.

**Sichere Maßnahme:**
- `status_publish` in `shared/config.php` gemäß `docs/status-page.md` eintragen, danach `restart-workers.sh` ausführen (Fall 10).
- Prüfen, dass `worker-maintenance` regelmäßig `monitor_collect()` ausführt (Scheduler-Intervall 240 s laut Tabelle in `docs/vps/06-betrieb.md`, Abschnitt „Braucht der VPS Cron-Jobs?“).
- DNS-Eintrag für `status.smart-einzug.de` prüfen, sofern die Seite grundsätzlich unerreichbar bleibt.

**Erfolgskontrolle:** `status.json` erhält einen aktuellen `generated_at`-Zeitstempel (gültig laut `valid_for_seconds`, Standard 900 s laut `docs/status-page.md`); die öffentliche Seite zeigt reale Zustände statt „Status unbekannt“.

**Eskalation:** Nicht sicherheitskritisch (reine Anzeige); bei anhaltendem Ausfall trotz korrekter Konfiguration technische Leitung informieren, da dies auf eine tiefere Störung des Monitorings (Fall 4/5) hindeuten kann.

## Offene Prüfpunkte

- Für Fall 7 (Rücklastschrift) ist der externe Test mit einer echten Erstattung im Stripe-Testmodus laut `docs/payment-safety.md` Abschnitt 10 noch offen; die Webhook-Endpunkte der Firmen müssen dafür `charge.refunded` und `charge.refund.updated` liefern.
- Für Fall 9 wurde kein Bericht über einen tatsächlich durchgeführten Rollback nach einem produktiven Fehlschlag der Migration gefunden (nur die Abläufe für Candidate-Fehler und Health-Check-Fehler sind mit realen Vorfällen belegt); ein reiner Migrationsfehlschlag in Produktion ist laut Repository bislang nicht dokumentiert aufgetreten.
- Für Fall 3 (Redis) ist die tatsächliche Docker-DNS-Auflösung des Alias und die Netzmitgliedschaft eines echten `compose run`-Containers laut `docs/vps/06-betrieb.md` (Abschnitt „Nachtrag Version 4.10“) in der Entwicklungsumgebung nicht nachstellbar (kein Docker-Daemon); die dortige Herleitung stützt sich auf zitierte Primärquellen, nicht auf einen eigenen Testlauf in diesem Repository.
- Ob die in `docs/vps/06-betrieb.md` erwähnte manuelle Löschung des alten IONOS-Cronjobs (Abschnitt „Braucht der VPS Cron-Jobs?“, Zeile 610 ff.) bereits erledigt wurde, ist im Repository nicht nachvollziehbar (organisatorische Aufgabe außerhalb des Codes); Stand in `docs/vps/07-cutover-checkliste.md`, Punkt 12, zu prüfen.

---

Umfang dieser Datei: 11 Fehlerfälle, rund 260 Zeilen.
