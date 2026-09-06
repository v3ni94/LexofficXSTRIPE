# Systemmonitoring: Adminbereich "System"

Stand: 06.09.2026 (Auftrag II, Abschnitt 7). Datenbankänderung: Migration 017. Code: app/monitor.php (Sammler, Auswertung, Snapshot, Alarmierung), app/monitor_view.php (Darstellung), admin-system.php (Seite), admin-system-data.php (Kopf-Fragment für die Aktualisierung), health.php (dynamische Gesundheitsantwort), Instrumentierung in cron.php, app/sync_state.php, app/mailer.php, app/stripe.php, stripe-webhook.php, migrate.php, app/bootstrap.php.

## Einstieg und Rechte

Adminnavigation (admin.php), letzter Eintrag "System". Zugriff nur mit users.is_superadmin und aktiver 2FA (require_superadmin); die Rolle Administrator einer Kundenfirma reicht nicht. Der Datenendpunkt admin-system-data.php prüft dieselbe Berechtigung serverseitig und liefert nur gespeicherte Daten.

Leserecht: alle Plattformadministratoren. Änderungsrecht (Störungsmeldungen anlegen, veröffentlichen, Testversand, manuelle Veröffentlichung des Snapshots): zusätzlich Eintrag in config monitoring.editors (E-Mail-Adressen); leer bedeutet alle Plattformadministratoren. Veröffentlichen, Testversand und Snapshot-Übertragung verlangen einen aktuellen Authenticator-Code oder eine Codeeingabe innerhalb der letzten 5 Minuten (require_recent_totp mit Bestätigungsfenster, Abschnitt 5.7).

Die Seite aktualisiert das Kopf-Fragment alle 30 Sekunden (assets/js/app.js), pausiert in inaktiven Tabs und löst keine Prüfungen aus. "Jetzt prüfen" startet nur den Sammler mit Zeitbudget; keine Migration, Synchronisation, Lastschrift oder Testmail.

## Was tatsächlich gemessen wird

Umfeld: IONOS Webhosting, PHP ohne Root-Zugang, externer Cron (cron-job.org, Sollintervall 5 Minuten, 30 Sekunden Timeout). Es gibt keine Einsicht in Prozesse, CPU, Gesamtspeicher oder PHP-Worker des Hosts.

| Kennzahl | Quelle | Verfügbar |
|---|---|---|
| PHP / Anwendungsantwort | HTTP-Abruf von health.php: dynamische JSON-Antwort mit aktuellem UTC-Zeitstempel und SELECT 1. Statische 200-Antworten, fehlender Zeitstempel oder ein Zeitstempel älter als 120 s gelten als Störung (Kategorie not_dynamic, stale_response). | Ja |
| Datenbank | SELECT 1 über die bestehende Verbindung, Latenz in ms. Lesetest, keine Schreibprüfung, keine Testschreibvorgänge in Fachtabellen. | Ja |
| Weboberfläche | login.php mit Inhaltsmerkmal (Formular, Passwortfeld) sowie assets/css/style.css und assets/js/app.js. Kein Browser-Funktionstest. | Ja |
| Administration | login.php des Admin-Hosts, nur bei getrenntem Host. | Ja, wenn admin_base_url gesetzt |
| HTTPS / Zertifikate | TLS-Handshake mit Zertifikatsprüfung (verify_peer), Ablauf in Tagen, Warnschwelle monitoring.tls_warn_days. Hosts aus monitoring.tls_hosts oder aus app_base_url, admin_base_url, public_base_url. TLS-Prüfung wird nie deaktiviert. | Ja |
| Cronjobs | Startzeit des letzten Cron-Laufs (job_runs) gegen monitoring.cron_interval_seconds: in Ordnung bis 2 Intervalle plus 60 s, eingeschränkt bis 4 Intervalle, danach Störung. | Ja |
| E-Mail | Marker der letzten Übergabe an den Versandweg (mail(), SMTP oder Testprotokoll) und des letzten Fehlers mit Kategorie. Übergabe bedeutet Annahme, nicht Zustellung. Keine Versandwarteschlange vorhanden (Direktversand). Testversand nur an monitoring.test_mail_to. | Ja (Marker) |
| Lexware-Anbindung | API-Zähler und technische Fehler der Synchronisationsschritte (job_runs, letzte 24 h). Schwelle monitoring.error_rate_warn_pct bei Mindeststichprobe min_sample. Anmeldefehler einer Firma (Kategorie auth) sind keine Plattformstörung. Keine zusätzlichen Bestandsabfragen. | Ja (instrumentiert) |
| Stripe-Anbindung | Instrumentierte API-Aufrufe (monitor_checks, Komponente stripe_api): 5xx und 429 sind technische Fehler, fachliche 4xx-Ablehnungen nicht. Webhook-Verarbeitung als Ereignis (Signaturfehler einer Firma ist Konfiguration). | Ja (instrumentiert) |
| Deployment / Migrationen | schema_migrations (pending, running, failed, unknown), Marker des letzten erfolgreichen Migrationsaufrufs, app/build.txt (Commit und Zeitpunkt, vom Workflow geschrieben). Nur lesend. Upload-Zeitpunkt nur indirekt bekannt. | Ja |
| SFTP | Nicht prüfbar aus der Anwendung (Deployment-Zugangsdaten nur in GitHub). Zustand "Nicht geprüft". | Nein |
| Datenbankgröße, Mandatsspeicher | information_schema und Summe mandate_files.size_bytes, stündlich, mit Erhebungszeitpunkt. Kontingente werden vom Hosting nicht bereitgestellt. | Ja (Größe), Nein (Grenze) |
| Sicherungen | Keine verifizierbare Quelle. "Nicht überwacht". | Nein |
| CPU, Gesamt-RAM, PHP-Worker, Webspace-Kontingent | Vom Hosting nicht bereitgestellt. Es werden keine Prozentwerte berechnet. Manuelle Tariflimits können in monitoring.tariff_limits mit Quelle und Datum hinterlegt werden und werden als Konfigurationswert gekennzeichnet. | Nein |
| PHP-Spitzenspeicher | memory_get_peak_usage() je Job in job_runs, beschriftet als Spitzenspeicher des Jobs. | Ja |
| PHP-Anfragen | Minutenzähler in monitor_requests (Anzahl, 5xx, Summe und Maximum der Antwortzeit) über einen Upsert am Ende jeder PHP-Anfrage. Nur PHP-Anfragen dieser Anwendung, keine statischen Dateien. Selbstprüfungen des Sammlers werden nicht gezählt. | Ja |

## Jobs zählen (7.4)

Tabelle job_runs: eine Zeile je Ausführungsversuch (id), job_key bündelt den fachlichen Auftrag (zum Beispiel sync:<firma>:<start der synchronisation>). Instrumentiert sind Cron-Läufe (cron), jeder Synchronisationsschritt (sync, Zuwachs der Metriken je Schritt), die Einzugsverarbeitung im Cron (collections) und der Sammler (monitor). Übersprungene Doppelstarts werden als eigenes Ereignis (sync_skipped_start) gezählt.

Zählregeln je Fenster (1 Minute, 10 Minuten, 1 Stunde, 24 Stunden, rollierend): Starts nach started_at, Abschlüsse nach finished_at getrennt nach Status, Versuche und eindeutige Aufträge getrennt, Datensätze nur aus erfolgreichen Abschlüssen, Laufzeiten als Durchschnitt und 95. Perzentil aus Einzelwerten mit Stichprobengröße, Parallelität aus Start- und Endereignissen (belegbar, weil beide gespeichert sind). Ohne Beobachtungen wird "Keine Daten" gezeigt.

Laufende Versuche mit abgelaufenem Heartbeat (sync 300 s, collections 600 s, cron 300 s, monitor 120 s) werden vom Sammler als "Ausführung unbestätigt" (Status unknown, Kategorie heartbeat_stale) markiert. Das ändert keine fachliche Sperre (sync_state) und startet nichts neu.

## Messrhythmus und Eigenlast

Der Sammler läuft am Ende jedes Cron-Aufrufs (Reserve etwa 4 Sekunden, Budget höchstens 8 Sekunden, eigene Sperre GET_LOCK smarteinzug_monitor, Mindestabstand monitoring.collect_interval_seconds, Standard 240 s). Jede Prüfung hat einen eigenen Timeout (HTTP 5 s, Verbindungsaufbau 3 s). Ist das Budget erschöpft, werden die restlichen Prüfungen ausgelassen und nicht erfunden (Zustand veraltet, später unbekannt).

Ein 1-Minuten-Fenster wertet ereignisbasierte Jobdaten minutengenau aus; Dienstprüfungen liegen nur im Cron-Takt vor. Die Seite weist darauf hin.

Ein unabhängiger externer Prüfer ist nicht eingerichtet (siehe docs/status-page.md). GitHub Actions ist wegen des Mindestintervalls von 5 Minuten und möglicher Verzögerungen nicht als Minutenüberwachung vorgesehen.

## Verfügbarkeit (7.7)

Zeitgewichtet aus den Rohmessungen: jede Messung gilt ab ihrem Zeitpunkt bis zur nächsten Messung, höchstens valid_seconds (Dienstprüfungen 900 s, Anbindungen 3600 bis 7200 s, TLS 21600 s). Nicht abgedeckte Zeit ist unbekannt.

```
T_ok        beobachtete Dauer nutzbar (inklusive eingeschränkt, separat als T_degraded ausgewiesen)
T_ausfall   beobachtete Dauer nicht nutzbar
T_unbekannt Fenster minus (T_ok + T_ausfall)
Verfügbarkeit   = 100 * T_ok / (T_ok + T_ausfall)      Nenner 0: Keine ausreichenden Messdaten
Messabdeckung   = 100 * (T_ok + T_ausfall) / Fenster
Konservativ     = 100 * T_ok / Fenster                 unbekannte Zeit als nicht bestätigt
```

Für 7, 30 und 90 Tage werden volle Tage aus monitor_daily (Tagesaggregate, vom Sammler für heute und gestern neu berechnet) und der aktuelle Tag aus Rohdaten kombiniert. Tage ohne Aggregat zählen vollständig als unbekannt. Öffentliche Prozentwerte erscheinen erst ab monitoring.public_min_coverage_pct (Standard 99 %, Produkteinstellung), sonst "Unvollständige Messdaten". Wartung wird nicht herausgerechnet. Serverlaufzeit und PHP-Prozesslaufzeit sind nicht verfügbar und werden nicht ersetzt.

Nutzerfunktionen (öffentliche Komponenten) und ihre Bestandteile: Webanwendung (php_app, web_ui), Anmeldung (php_app, db), Datenabgleich (cron, lexoffice), Einzugsverarbeitung (cron, stripe, db, zusätzlich Not-Stopp der Plattform als eingeschränkt), E-Mail-Benachrichtigungen (mail). Fehlende Aktivität einer Anbindung (keine Aufrufe) ist keine Störung. Der schlechteste Bestandteil bestimmt den Zustand; veraltete Messungen gelten als unbekannt. Der Gesamtzustand weist Unsicherheit aus, wenn eine kritische Funktion nicht geprüft werden kann.

## Alarmierung (7.8)

Kritische Komponenten php_app, db, web_ui, cron: Störung nach monitoring.alert_fail_streak (Standard 3) aufeinanderfolgenden Fehlprüfungen, Entwarnung nach alert_ok_streak (Standard 2) erfolgreichen Prüfungen. Je Komponente eine Meldung je Störung (Marker mon_alert_open_<komponente>). Empfänger monitoring.alert_emails; leer bedeutet nicht eingerichtet. Versand über den regulären Mailweg; ein unabhängiger Alarmkanal ist vorbereitet, aber nicht aktiv und wird so angezeigt. Rohe Fehlprüfungen bleiben vollständig in der Historie.

Störungen und Wartungen (monitor_incidents, monitor_incident_updates): Titel, betroffene öffentliche Komponenten, Beginn, Verlauf mit Phasen (Wird untersucht, Ursache identifiziert, Wird beobachtet, Behoben; Wartung: Geplant, Wartung läuft, Abgeschlossen), öffentlicher Text und interne Notizen getrennt. Öffentliche Texte werden von HTML und Steuerzeichen bereinigt und in der Ausgabe escaped. Änderungen werden mit Urheber und Zeitpunkt protokolliert (audit_log incident_created, incident_updated, incident_published). Eine Meldung ändert keine Messhistorie.

## Datenhaltung und Bereinigung

| Tabelle | Inhalt | Aufbewahrung |
|---|---|---|
| monitor_checks | Rohmessungen und instrumentierte Ereignisse | 14 Tage |
| monitor_requests | Minutenzähler der PHP-Anfragen | 30 Tage |
| monitor_daily | Tagesaggregate je Komponente | 400 Tage |
| job_runs | Ausführungsversuche | 30 Tage (laufende bleiben) |
| monitor_incidents, monitor_incident_updates | Störungen, Wartungen, Verlauf | unbegrenzt (nicht Teil der Bereinigung) |

Minuten- und Stundenaggregate werden nicht gespeichert: bei etwa 12 bis 15 Prüfungen je Sammellauf und einem Lauf je 5 Minuten entstehen rund 4.000 Rohzeilen je Tag (etwa 60.000 in 14 Tagen), was die 1-Minuten- bis 24-Stunden-Fenster direkt aus Rohdaten abdeckt. Die Bereinigung (monitor_cleanup) läuft mit dem Sammler und betrifft ausschließlich Monitoringtabellen.

Alle Zeitstempel in UTC, Anzeige in der konfigurierten Zeitzone (Europe/Berlin) mit Zeitzonenkürzel. "Letzte 24 Stunden" ist rollierend.

## Sicherheit und Grenzen

- Probe-Ziele sind ausschließlich serverseitig festgelegt (health.php, login.php, Assets, konfigurierte TLS-Hosts). Kein URL-Prüfer für Benutzer. Weiterleitungen werden nicht gefolgt und als Kategorie redirect gemeldet.
- Fehlerkategorien sind bereinigt (timeout, dns, tls, auth, http_5xx, ...); keine Rohmeldungen, Pfade, Tokens oder Verbindungsdaten in der Datenbank oder der Anzeige.
- Ein Fehler der Diagnose (Tabelle fehlt, Datenbank kurz nicht erreichbar) wird verworfen und blockiert weder Anmeldung, Synchronisation, Einzüge noch Migrationen.
- MIGRATION_TOKEN wird nirgends im Monitoring verwendet. Für externe Prüfer und die Statusveröffentlichung sind eigene Zugangsdaten vorgesehen (docs/status-page.md).
- Messgenauigkeit: periodische Prüfungen im Cron-Takt sind keine sekundengenaue Dauerbeobachtung; kurze Ausfälle zwischen zwei Prüfungen werden nicht erfasst.

## Konfiguration (config.php)

Siehe app/config.example.php, Abschnitt monitoring, status_page_url, status_publish. Ohne Angaben gelten die Standardwerte; Alarmmails, Testversand und Statusveröffentlichung sind dann nicht eingerichtet und werden so angezeigt.

## Tests

scratchpad/test_monitor.php (Testdatenbank und lokaler Testserver): Zeitgewichtung mit Lücken und begrenzter Gültigkeit, Nenner null, Tagesaggregate und 7-Tage-Fenster, Zähler an Fenstergrenzen, Versuche gegen Aufträge, veralteter Heartbeat, Perzentil mit Stichprobe, Parallelität, Keine Daten ohne Beobachtungen, Anfragenreihe mit Lücken, Sammler gegen den Testserver, Intervallsperre, öffentliche Zustände inklusive Not-Stopp und veralteter Messung, Stripe-Fehlerquote (fachliche Ablehnungen zählen nicht), PHP-Ausfall bei erreichbarer statischer Datei (Kindprozess), Alarm- und Entwarnungsstreaks, Bereinigung nur der Monitoringdaten, Snapshot-Positivliste, Störungsbereinigung, ältere Snapshots überschreiben nicht.

scratchpad/e2e_saas.php, Abschnitt 28: Zugriffsschutz für Seite und Datenendpunkt, health.php ohne Sitzung und Versionen, Eintrag System in der Adminnavigation, Jetzt prüfen ohne Nebenwirkungen, Dienste-Anzeige, Störung mit Skriptbereinigung, Veröffentlichung nur mit gültigem 2FA-Code.

Nicht geprüft (keine Testumgebung): TLS-Prüfung gegen echte Hosts, SMTP-Fehler, Stripe- und Lexware-Störungen mit echten Diensten, Alarmmails mit echtem Versand, Eigenlast auf dem IONOS-Server.
