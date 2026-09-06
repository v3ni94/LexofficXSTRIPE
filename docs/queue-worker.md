# Hintergrundverarbeitung: Queue, Worker, Scheduler, Circuit Breaker

Stand: 06.09.2026, Version 4.0 (Auftrag III, Abschnitte 25 bis 50, 64 bis 70). Datenbankänderung: Migration 018. Code: app/queue.php, app/jobs.php, app/redis.php, app/features.php, app/log.php, bin/worker.php, bin/scheduler.php, bin/migrate.php, bin/healthcheck.php, bin/host-metrics.php, Anpassungen in app/sync_state.php, app/sync.php, app/mailer.php, app/lexoffice.php, app/stripe.php, app/bootstrap.php, app/audit.php, cron.php, invoices.php, sync-status.php.

## Entscheidungen

| Frage | Entscheidung | Begründung |
|---|---|---|
| Speicherort der Warteschlange | MariaDB, Tabelle jobs | Dauerhaft, transaktional, ein Migrationssystem. Reservierung mit SELECT ... FOR UPDATE SKIP LOCKED, mehrere Worker greifen ohne Doppelreservierung zu. Redis bleibt ergänzend (Sperren, Ratenbegrenzung) und ist kein Pflichtbestandteil. |
| Versuche | Tabelle job_runs (vorhanden) | Jeder Verarbeitungsversuch ist eine Zeile mit job_key = Job-ID. Auftrag und Versuch bleiben getrennt, keine zweite Struktur. |
| Doppelstarts | dedupe_key, nur bei aktiven Jobs gesetzt, eindeutiger Index | Ein Sync je Firma (sync:<firma>), eine Einzugsverarbeitung (collections:due). Datenbank verhindert den Doppelstart, nicht nur die Anwendung. |
| Backoff | 60, 300, 900, 3600 Sekunden nach dem 1. bis 4. Fehlversuch, danach failed | Entspricht der Vorgabe (1, 5, 15, 60 Minuten). Fortsetzungen nach Zeitbudget sind keine Fehlversuche (queue_requeue). |
| Fehlerkategorien | Bereinigte Kategorien (timeout, dns, tls, connection, http_5xx, throttled, auth, business) | Keine Rohmeldungen, keine Geheimnisse in last_error (log_sanitize). |
| Circuit Breaker | Tabelle api_circuits je Anbindung, geteilt über alle Worker | Nach 5 technischen Fehlern offen für 300 Sekunden, dann genau ein Testaufruf (half_open), Erfolg schließt. Fachliche Fehler (401, Kartenablehnung) öffnen den Kreis nicht. |
| Ratenbegrenzung | Redis-Zähler je Sekunde (api_call_gate), zusätzlich die vorhandene Drosselung im Client | Ohne Redis greift nur die Drosselung je Prozess. Der Wert 2 Aufrufe je Sekunde für Lexware ist eine zu verifizierende Annahme. |
| Feature-Flag | features.queue (global oder Firmenliste) und organizations.feature_flags je Firma | Schrittweise Aktivierung, zuerst Testfirma. Ohne Flag arbeitet der Cron wie bisher. |
| Sync-Fortschritt | Aus dem vorhandenen Cursor (Seite von Seiten, verarbeitet von gesamt) | Kein zweiter Zähler; Prozent 0 bis 10 Listing, 10 bis 95 Verarbeitung, 97 Nachprüfung. |
| Historie | Tabelle sync_runs je Firma | Start, Ende, Dauer, Auslöser, Benutzer, Mengen, API-Zähler, Worker, Fehler. Laufender Zustand bleibt in sync_state. |

## Ablauf

```
Scheduler (bin/scheduler.php, 30 s)      Benutzer klickt "Jetzt synchronisieren"
   fällige Aufgaben -> queue_push          -> sync_state_start + queue_push(sync_run, HIGH)
                    \                      /
                     Tabelle jobs (queued, priority, available_at, dedupe_key)
                              |
        Worker-Pools (bin/worker.php --pool=lexware|stripe|mail|maintenance)
              queue_reserve (SKIP LOCKED) -> job_execute -> Handler
                              |
           completed | partially_completed | requeue | retry (Backoff) | failed (Dead Letter)
```

Jobtypen und Pools: sync_run (lexware), collections_due und unclear_attempts (stripe), mail, alerts, mandate_reminders (mail), monitor_collect und maintenance (maintenance). Pool all bearbeitet alles (Hybridbetrieb).

Scheduler-Intervalle: Einzugsverarbeitung 5 Minuten, Klärung 10 Minuten, Monitoring 4 Minuten, Alarmierung, Wartung und Mandats-Erinnerungen stündlich. Automatischer Delta-Sync je verbundener Firma alle queue.auto_sync_hours (Standard 6) mit Priorität NORMAL, nächtlicher Vollabgleich zur Stunde queue.full_sync_hour (Standard 3) mit Priorität LOW und Änderungserkennung aus. Pausierte Firmen (sync_paused) und Firmen ohne Queue-Flag werden übersprungen.

## Idempotenz und Mandantentrennung

- Synchronisation: Upserts über (tenant_id, lexoffice_invoice_id) und Kundennummer; eine Wiederholung erzeugt keine doppelten Rechnungen oder Kunden (Test 8 in test_queue.php). Der Cursor in sync_state ist der Checkpoint; nach Absturz setzt der nächste Versuch dort fort.
- Einzüge: der vorhandene Einreichmechanismus mit Stripe-Idempotenzschlüsseln und lokaler Zustandsführung bleibt unverändert; der Job ruft nur process_scheduled_collections mit Zeitbudget.
- E-Mail: nach Übergabe wird der Inhalt aus dem Job entfernt. Ein Absturz zwischen Übergabe und Statusspeicherung kann eine Nachricht doppelt zustellen; deshalb höchstens drei Versuche.
- Jeder Handler arbeitet nur mit der Firma aus dem Job. Die Analyse der Mandantentrennung (Auftrag III Phase 1) fand keine Zugriffe über Firmengrenzen; die Job-Handler übergeben tenant_id an bestehende Funktionen, die intern filtern.
- Ein hängender Job (Heartbeat älter als die Toleranz je Typ) wird vom Scheduler als Fehlversuch freigegeben und nach max_attempts zu failed. Monitoring startet nichts neu.

## Nutzeranzeige

invoices.php?syncing=1 zeigt bei aktiver Queue einen Fortschrittsbalken mit Prozent und Text (sync-status.php, alle 3 Sekunden, Polling nur im aktiven Tab). Texte: "Rechnungsliste wird geladen (Seite 3 von 12)", "1.248 von 3.874 Rechnungen verarbeitet", "Warte auf Lexware Office, automatischer neuer Versuch läuft". Nach Abschluss lädt die Seite die Rechnungsliste. Die Historie steht unter Synchronisationen (synchronisationen.php).

## Logging und Nachvollziehbarkeit

app_log schreibt JSON-Zeilen (timestamp UTC, level, service, company_id, user_id, job_id, correlation_id, duration_ms, status, error_code, message) nach stderr (Docker) oder app/storage/logs. Die Correlation-ID entsteht je Webanfrage (Header X-Correlation-Id wird übernommen, sonst erzeugt) und wird in Jobs, Worker und audit_log.details_json weitergereicht. Geheimnisse werden maskiert.

## Betrieb ohne Worker (Webhosting)

Ist features.queue aktiv, aber kein Worker vorhanden, verarbeitet cron.php im Hybridbetrieb: Scheduler-Tick und Jobs im Zeitbudget (queue_run_inline). Damit kann die Queue auf dem Webhosting mit einer Testfirma erprobt werden, bevor der VPS übernimmt.

## Konfiguration

config.php: 'queue' (Zeitbudgets, Auto-Sync, Vollabgleich, Aufbewahrung, Raten, circuit), 'redis', 'log', 'trusted_proxies', 'features' => ['queue' => ...]. Vorlage in app/config.example.php.

## Tests

scratchpad/test_queue.php (67 Prüfungen, Testdatenbank und lokaler Redis): Dedupe, Prioritäten, Reservierung, Heartbeat-Sperre, Backoff je Versuch, Dead Letter mit Admin-Aktionen, Wiederaufnahme nur ohne aktiven Auftrag, Fortsetzung ohne Fehlversuch, hängende Jobs, Statistik, Worker-Heartbeats, Circuit Breaker (Schwelle, offen, Testaufruf, erneut offen, geschlossen), Redis-Sperren und Ratenbegrenzung, Scheduler-Tick ohne Doppelungen, automatischer Sync je Firma, Synchronisation als Job mit Fortschritt, Historie und Wiederholung ohne Dubletten, technischer Fehler mit Wartetext, Mail über die Queue mit Inhaltsentfernung, Worker-CLI, Scheduler-CLI, Healthcheck-CLI, Migrate-CLI (wiederholbar), Inline-Betrieb, Bereinigung.

Nicht geprüft (keine Testumgebung): Verhalten mehrerer Worker-Container auf einem echten VPS unter Last, echte Lexware- und Stripe-Störungen, Redis-Ausfall im Betrieb (Fallback ist implementiert, aber nur ohne Redis getestet).
