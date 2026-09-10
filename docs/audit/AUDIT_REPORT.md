# Auditbericht SmartEinzug (Qualität, Sicherheit, Funktion, Performance)

Zeitraum: 09.09.2026 bis 10.09.2026. Prüfbranch `audit/2026-09-09-gesamtpruefung`, Ausgangscommit 66c59d5 (Version 4.58,
zuletzt produktiv ausgerollt mit GitHub-Lauf #95). Kein Push, kein Merge, kein Deployment innerhalb des Audits.

## 1. Umfang und Arbeitsgrenzen

Geprüft wurden das Repository (php-ionos, deploy, tools, docs), die Testwerkzeuge und die Konfigurationsvorlage. Nicht
zugänglich waren die produktive `shared/config.php`, die Zeitzone und Version der Coolify-MariaDB, Zugriffsprotokolle des
Proxys und die Lexware- und Stripe-Dokumentation (kein Netzzugriff aus der Prüfumgebung). Aussagen dazu sind als „nicht
verifizierbar“ gekennzeichnet.

Sicherheitsgrenzen der Prüfung: temporäre MariaDB je Suite (eigener Port, Datenbank `se_test`), lokaler Stripe-Stub, Lexware
über CLI-Testhaken, kein Mailversand, kein Redis erforderlich. Der neue zentrale Test-Schutz `tools/lib/test-guard.php`
(Selbsttest `tools/test-guard-check.sh`, 18 Fälle) bricht jede Suite vor der ersten Nebenwirkung ab, wenn die Konfiguration
auf Produktion, Standardport 3306, Mailversand, Plattform-Abrechnung, Live-Schlüssel oder externe Anbieteradressen zeigt.

## 2. Bestandsaufnahme

| Bereich | Stand | Kennzeichnung |
|---|---|---|
| Sprache, Laufzeit | PHP 8.4 (Container `php:8.4-fpm-alpine`, lokal 8.4.19), kein Framework, kein Composer, kein Laravel | im Code vorhanden |
| Datenbank | MariaDB; Produktion laut Doku 11.8 (Coolify-Dienst), Prüfumgebung 10.11.14 | Produktion nicht geprüft |
| Struktur | 54 Seiten in `php-ionos/*.php`, 59 Module in `php-ionos/app/` (21.085 Zeilen), 46 Tabellen in `sql/schema.sql`, 31 Migrationen | im Code vorhanden |
| Hintergrundverarbeitung | Cron (`cron.php`) ohne Warteschlange; mit `features.queue` Tabelle `jobs`, Scheduler und Worker-Pools lexware (2), sevdesk, stripe, mail, maintenance (`deploy/vps/docker-compose.yml`) | im Code vorhanden |
| Externe Anbindungen | Lexware Office (`app/lexoffice.php`, nur GET), sevdesk (`app/sevdesk.php`, Annahmen markiert, Einzüge gesperrt), Stripe je Firma (`app/stripe.php`), Stripe Plattform (`app/billing.php`) | im Code vorhanden |
| Webhooks | `stripe-webhook.php` (Firmen), `billing-webhook.php` (Plattform), Ereignisregister `webhook_events` | im Code vorhanden |
| Authentifizierung | Sitzung, Passwort-Hash, TOTP mit Recovery-Codes, vertraute Geräte 90 Tage, Plattformrollen, Support-Modus | im Code vorhanden |
| Deployment | GitHub-Workflow `deploy.yml` (Push auf `main` und `claude/setup-lexsepa-monorepo-v5ZcZ` löst Deployment aus), `deploy-runner.sh`, `deploy.sh` mit Candidate-Prüfung, Migration, Cutover, Rollback | im Code vorhanden |
| Tests | 27 Prüfsuiten unter `tools/` (statisch, gegen temporäre MariaDB, gegen Stubs). Die in CLAUDE.md genannte E2E-Suite `scratchpad/e2e_saas.php` ist NICHT im Repository | Lücke |

Datenfluss und Trennung der Plattform-Abrechnung: siehe `PAYMENT_INVARIANTS.md`, Abschnitt 1.

## 3. Ausgangslage (Baseline auf 66c59d5, unverändert ausgeführt)

Alle 27 vorhandenen Suiten grün, `php -l` über alle PHP-Dateien ohne Fehler (Protokolle im Prüfstand, Zusammenfassung in
`TEST_MATRIX.md`). Befund der Ausgangslage: Für den Geldfluss existierte keine funktionale Prüfung im Repository;
`tools/payment-safety-check.php` prüft nur statisch (Textmuster im Quelltext).

## 4. Befunde

Rohbefunde der Prüfrollen: A Zahlungslogik 14, B Lexware 15, C Sicherheit 12, D Backend 18, E Frontend 8, eigene Befunde 3
(insgesamt 70, davon 6 Überschneidungen). Jeder Befund wurde vom Leitagenten gegen den Code verifiziert. Priorität nach dem
Schema des Auftrags (P0 doppelte oder unberechtigte Zahlung, Vermischung; P1 falscher Zustand, verlorener Vorgang, umgehbare
Rechte; P2 Betrieb und Bedienung; P3 klein). Kein P0-Befund wurde bestätigt: Die bestehenden Sperren (Firmenzeile, Rechnungszeile,
atomare Beanspruchung, Versuchsjournal mit UNIQUE-Schlüssel) verhinderten in allen geprüften Pfaden eine zweite Lastschrift
im Normalbetrieb; A-01 und A-04 hätten sie nur in Kombination mit einer Stripe-Störung und einem hängenden Suchindex erlaubt.

### 4.1 Behoben (Testnachweis je Zeile: Suite und Abschnitt, siehe TEST_MATRIX.md)

| ID | Prio | Beleg (vorher) | Auswirkung | Ursache | Korrektur | Nachweis | Rest |
|---|---|---|---|---|---|---|---|
| A-01 | P1 | `app/collections.php` Klärung 792 bis 810 | Freigabe eines unklaren Versuchs allein nach leerer Stripe-Suche (eventuell konsistent); danach neuer Schlüssel, zweite Lastschrift möglich | Suche statt konsistenter Abfrage; Webhook trug `failed` nicht nach | Listenprüfung `_stripe_find_payment_intent_by_attempt_key` vor jeder Freigabe; Webhook trägt auch `failed` nach; Warnprotokoll | collections 7a, 7b | keiner |
| A-02 | P1 | Klärung 772 bis 775 | Einzug in `submitting` ohne Versuch blieb für immer hängen | Rücksetzung nur nach der Schleife, frühe Rückkehr | `_collections_release_stuck_submitting()` bei jeder Klärung | collections 10 | keiner |
| A-03 | P1 | `stripe-webhook.php` 91 bis 107 | Datenbankfehler bei Firmenzuordnung mit 200 quittiert, Ereignis verloren | `webhook_exit` vor dem `try` | `webhook_retry()` mit 500 | collections 15 (statisch) | keiner |
| A-04, D-03 | P1 | Klärung 784, Webhook 261 | Alter aus PHP-Zeit gegen DB-`CURRENT_TIMESTAMP`; bei DB in UTC Fristen um 1 bis 2 h falsch, junger Versuch sofort freigegeben | gemischte Zeitquellen | `age_seconds` aus `TIMESTAMPDIFF` in Klärung und Webhook; `collection_attempt_finish` degradiert nie `succeeded` | collections gesamt (DB in UTC), 7a, 7b | Anzeige historischer Zeitstempel weiter serverzonenabhängig |
| A-05, D-04 | P2 | Backfill 673 bis 737 | zwei Einzugsdatensätze je PaymentIntent bei gleichzeitigem Webhook und Klärung | Prüfen-dann-Einfügen ohne Constraint | Transaktion mit `FOR UPDATE` auf der Rechnung; Migration 032 UNIQUE (nur ohne Dubletten); Dublettenfehler abgefangen | migrations 12/0; collections 7a | Index fehlt, falls Bestand Dubletten hat (Checkliste) |
| A-06, D-01 | P2 | `_submit_single_scheduled` catch Throwable 1832 | Schutzschaltung offen oder Ratenbegrenzung: Einzug endgültig `failed`, Rechnung `failed`, Versuch verbrannt | Ausnahmen nicht als Zurückstellung erkannt | `CircuitOpenException`, `JobRetryException`, HTTP 429 werden `CollectionDeferredException`, Versuch verworfen | collections 11 | keiner |
| A-07 | P2 | Webhook 272 | Ereignis zu noch nicht festgeschriebenem Einzug mit 200 quittiert, verloren | Freigabe ohne Wiederholungsauslöser | 500 für Versuche jünger als 300 s ohne Einzug | collections 14c | Stripe kann Endpunkt bei dauerhaft 500 deaktivieren (nur bei Datenbankausfall) |
| A-08 | P2 | Webhook 298 bis 306 | nach `payment_failed` mit Mandats- oder Kontocode sofort wieder Kandidat für Sammel-Einzug | kein `decline_code` ausgewertet | `stripe_sepa_decline_needs_review()`, `requires_review` | collections 14b | Codeliste Annahme |
| A-09, B-12 | P2 | `_load_and_validate` 1080 bis 1112 | Fremdwährungsrechnung als EUR einziehbar | Währung nicht geprüft | EUR erzwungen (beide Pfade) | collections 5 | keiner |
| A-10 | P3 | Terminierung 1372, Storno 1499 | bezahlte Rechnung erneut terminierbar, Storno setzte `open` | eigene Einzüge nur bei frischem Restbetrag abgezogen | eigene Einzüge immer abgezogen, `_invoice_restore_collection_status` | collections 12, 12a | keiner |
| A-11 | P3 | 1835 | Fehlermarkierung ohne Zustandsbedingung | fehlendes WHERE | `AND stripe_status IN ('submitting','scheduled')` | collections 15 | keiner |
| A-12 | P3 | `settings.php` save_stripe | Kontowechsel ließ alle Einzüge mit `resource_missing` scheitern | gespeicherte Stripe-IDs nicht zurückgesetzt | Reset bei geändertem Konto oder Modus, Audit, Hinweis | statisch (Rolle F) | keiner |
| A-14 | P3 | `collection_apply_refund` 888 | Vollerstattung öffnete Rechnung nur aus `collected` | CASE zu eng | auch `in_collection` | collections 13 | keiner |
| eigen 1 | P2 | `app/stripe.php` non-JSON | 2xx ohne JSON galt als endgültiger Fehlschlag | Statusgrenze | nur klare 4xx endgültig | payment-safety B, collections 15 | keiner |
| eigen 2 | P2 | `app/stripe.php` Gate | Schutzschaltung nur aktiv, wenn Seite `queue.php` lud (Einzugsseite, Webhook nicht) | `function_exists` | `stripe.php` lädt `queue.php` | collections 11 | keiner |
| eigen 3 | P1 (Abdeckung) | Repository | kein funktionaler Test des Geldflusses im Repository | E2E-Suite fehlt | `tools/collections-check.sh` 126 Fälle, Test-Schutz | Abschnitt 2 TEST_MATRIX | Browsertests fehlen |
| B-01 | P1 | `app/sync.php` 581 bis 606 | Kontaktfehler überschrieb Kundendaten mit 10001 | Fehler als „kein Kontakt“ behandelt | bestehende Kunden unverändert, technische Fehler weitergereicht | sync 1, 2 | keiner |
| B-02 | P1 | Verarbeitungsphase 205 bis 220 | ein fehlerhafter Beleg blockierte den Lauf endlos | keine Isolierung | fachlicher Fehler je Beleg gezählt und übersprungen, technische wiederholt | sync (Struktur), statisch | Fehlerzähler noch ohne Anzeige |
| B-03 | P1 | `lexoffice.php` 158 | 401 endlos wiederholt | Text passte nicht zur Kategorie | „(HTTP 401)“ im Text | sync 5 | keiner |
| B-05 | P2 | `invoices.php` 35 | sevdesk-Firma konnte nicht manuell synchronisieren | Lexware-Prüffunktion | `sync_invoice_source()` | auth (statisch) | keiner |
| B-07 | P2 | Nachprüfung 295 bis 325 | Rechnung dauerhaft `not_open` nach einem Fehler | ID aus Liste entfernt, Kandidaten ohne `not_open` | `not_open` Kandidat, Notbremse weitergereicht, Fehler gezählt | sync 3 | Fehlerkosten je Lauf für dauerhaft fehlende Belege |
| B-08 | P2 | Nachprüfung 308 bis 318 | bezahlte Rechnung mit terminiertem Einzug endete `failed` | Sync kannte Einzüge nicht | `_sync_cancel_scheduled_collections`, Status nur ohne aktiven Einzug | sync 4 | keiner |
| B-09 | P2 | `settings.php` disconnect | Trennen ließ Lauf und Jobs weiterlaufen | keine Kopplung | `sync_state_cancel`, `queue_cancel` | statisch | Organisationsvergleich beim Neuverbinden offen |
| C-01 | P1 | `app/platform.php` 200 bis 412 | Selbsterhöhung über Zweitkonto oder eigene Rolle | keine Stufenlogik | privilegierte Rollen nur durch Administratoren, eigene Rolle nicht bearbeitbar | platform-roles C-01 (9 Fälle) | keiner |
| C-02 | P2 | `auth.php` 449 bis 482 | IP-Sperren plattformweit hinter unkonfiguriertem Proxy | Proxy-IP als Client-IP | `client_ip_is_unresolved_proxy()` setzt IP-Grenze aus | statisch | `trusted_proxies` setzen (Checkliste) |
| C-03 | P2 | `devices.php` 59 | Gerätecookie ohne Secure hinter Proxy | eigene Ableitung | `request_is_https()` gemeinsam | auth statisch | keiner |
| C-05 | P3 | `auth.php` 60 bis 76 | Gerätewiderruf wirkte nicht für Plattform-Benutzer ohne Firma | Reihenfolge | Prüfung vor Plattformkontext | auth statisch | keiner |
| C-06 | P3 | `auth.php` 756 bis 771 | TOTP-Replay bei parallelen Anfragen | Lesen-dann-Schreiben | bedingtes UPDATE, `rowCount` | auth 2 (Wettlauf nicht reproduzierbar) | keiner |
| C-07 | P3 | `webhook_events.php` | Reihenfolge durch fremde Firma vergiftbar | Objekt ohne Firma | Schlüssel `firma:objekt` | collections 14a (auch vorher grün: Freigabe löschte die Marke) | keiner |
| C-09 | P3 | `login_attempts` | unbegrenzte Aufbewahrung | kein Job | `login_attempts_cleanup()` 30 Tage | auth 3 | keiner |
| C-10 | P3 | `verify-email.php` 36 | zwei Mails, erster Link ungültig | Doppelaufruf | einmal senden | auth statisch | keiner |
| D-02 | P1 | `bin/worker.php` 56 bis 109 | Container `unhealthy`, Pool „ohne Worker“ bei Jobs über 90 s, Deploy-Rollback möglich | kein Lebenszeichen im Job | `worker_beat` aus `queue_heartbeat()` | auth statisch | keiner |
| D-05 | P2 | `submit_collection` 1247 | Not-Stopp wartete auf Firmenzeile während Stripe-Aufruf | `FOR UPDATE` über externe Aufrufe | `GET_LOCK` je Firma | collections 7e (SLOW) | keiner |
| D-06 | P2 | Fälligkeitslauf ohne Heartbeat | Stale-Freigabe laufender Geldjobs | fehlende Fortschrittsmeldung | Ticks je Einzug und Versuch, `collections_seconds` höchstens 480 | statisch | keiner |
| D-07 | P2 | `queue_requeue` | Fairness bei HIGH wirkungslos | Priorität unverändert | `yield`: Priorität normal, `available_at` später | statisch | keiner |
| D-08 | P2 | `cron.php` | überlappende Läufe | keine Sperre | `GET_LOCK('smarteinzug_cron')` | auth statisch | keiner |
| D-09 | P2 | `queue_type_defaults` | Doppelzustellung Mail nach Stale-Freigabe | TTL unter SMTP-Zeitlimits | TTL 300 | statisch | keiner |
| D-13 | P3 | `jobs`, `job_runs` | Tabellenscans der Statistik | Indizes fehlten | Migration 032 | migrations | Perzentile weiter in PHP |
| E-01 | P1 | `app.js` 82 bis 89 | Anzeige „läuft“ für immer, Login-Seite eingebettet | Redirect als Inhalt | `redirect: manual`, Kopfzeile `X-Sync-Fragment`, Abbruch nach 5 Fehlern | `node --check`; manuell nicht ausgeführt | Browsertest fehlt |
| E-02 | P1 | `invoices.php` 300 | offener Versuch in Rechnungsliste unsichtbar, Button aktiv | keine Abfrage | Unterabfrage `open_attempts`, Badge, Sperre | statisch | keiner |
| E-04, E-05, E-07 | P2/P3 | `collections.php`, `invoices.php` Formulare | Geldaktionen ohne Rückfrage, ungültiges Datum | fehlendes `confirm` | Bestätigungen, gültige Vorbelegung | statisch | keiner |

### 4.2 Nicht behoben (dokumentiert, mit Empfehlung)

| ID | Prio | Inhalt | Empfehlung |
|---|---|---|---|
| B-04 | P1 | Dasselbe Lexware-Konto in zwei Firmenaccounts: Schutz eigener Einzüge ist mandantenbezogen, zwei Lastschriften auf dieselbe Rechnung möglich (setzt doppelte Einrichtung durch den Kunden voraus) | Schlüssel-Hash (`api_scope_for_key`) und, falls `/profile` eine Organisationskennung liefert (nicht verifiziert), diese in `integrations` speichern und über Firmen hinweg ablehnen oder mit Audit und Warnung zulassen; Entscheidung des Betreibers |
| B-06 | P2 | Nach Wechsel des Buchhaltungssystems bleiben Altrechnungen offen und einziehbar (Einzug scheitert am Restbetragsabruf) | Spalte Herkunftssystem je Rechnung und Kunde, Altbestand beim Wechsel kennzeichnen |
| E-03 | P2 | Stripe-Rohtexte in Kundenmeldungen | deutsche `CollectionException` mit Code, Originaltext nur ins Audit |
| E-06 | P2 | Bestätigung über Restbetrag nur mit frischem Cache | Fehlermeldung mit Hinweis auf den zweiten Versuch oder Live-Wert vor dem Redirect |
| A-13 | P3 | Import-Übernahme prüft aktuelle App-Einzüge nicht | Neuprüfung je Position in `stripe_import_apply` |
| C-04, C-08, C-11, C-12 | P3 | Support-Token per GET, Sitzungsablauf, Logout per GET, Cron-Token in URL | siehe Rolle C |
| D-10 bis D-12, D-14 bis D-18 | P3 | Fortsetzungszähler, Sperrdauer, `queue_fail` ohne `rowCount`, Marker 022/026/030, dedupe-Kollision, `sync_run_open`, `migrations_status` schreibt, `NOT IN`-Grenze | siehe `docs/queue-worker.md` Nachtrag |
| E-08 | P3 | Terminiert-Badge ohne Datum | Datum aus `payment_collections` mitladen |

## 5. Endlauf (Version 4.59, Prüfbranch)

Alle 27 Bestandssuiten grün, platform-roles 97/0 (neue Fälle), payment-safety 69/0 (Fall B angepasst). Neu: test-guard 18/0,
collections 126/0 mit SLOW, sync 17/0, auth 13/0, migrations 12/0 mit 032, docs-build 0 Fehler, `php -l` fehlerfrei,
`node --check app.js` fehlerfrei. Leistung: PERFORMANCE_REPORT.md.

## 6. Unabhängige Gegenprüfung (Rolle F)

Ergebnis wird nach Abschluss der Gegenprüfung ergänzt.

## 7. Gesamturteil

**Bereit für weitere Staging-Prüfung.** Nicht zur Freigabe empfohlen, weil (a) B-04 (P1) offen ist und eine Entscheidung des
Betreibers braucht, (b) kein Browser- oder Staging-Lauf gegen echte Stripe- und Lexware-Testkonten stattfand, (c) die
Datenbankzeitzone und `trusted_proxies` der Produktion nicht geprüft wurden. Die Korrekturen selbst sind durch 126 funktionale
Fälle mit echten parallelen Prozessen, 17 Synchronisationsfälle und die Bestandssuiten abgesichert. Kein produktives Deployment
erfolgt; Übergabestand siehe HANDOVER.md.

## 8. Quellen und Abrufdatum

Kein Netzzugriff in der Prüfumgebung. Nicht abgerufen: Claude-Code-Dokumentation, Lexware Developers, Stripe (SEPA Debit,
Webhooks, Idempotent Requests, Fehler), OWASP ASVS. Aussagen zu Stripe-Idempotenz (24 Stunden), Suchindex (eventuell konsistent),
SEPA-Fehlercodes und Lexware-Limits (2 Anfragen je Sekunde) stammen aus Code, Repository-Dokumentation und dem Kenntnisstand
des Prüfers und sind als Annahmen gekennzeichnet. Claude Code 2.1.267 (Prüfumgebung), Subagenten über das Agent-Werkzeug
(fünf lesende Rollen, eine Gegenprüfung), Modelle: Standardmodell der Sitzung für A bis D und F, Sonnet für E.
