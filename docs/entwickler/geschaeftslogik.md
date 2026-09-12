# Geschäftslogik bis auf Implementierungsebene

Stand: 07.09.2026. Betreiber der Anwendung Lexware-Einzug (SmartEinzug): Müller Holding AG. Dieses Kapitel beschreibt
die wesentlichen fachlichen Abläufe der Anwendung `php-ionos/` ausschließlich anhand des vorgefundenen Codes. Jede
Aussage ist mit Datei und, soweit sinnvoll ermittelbar, Zeilennummer belegt. Wo eine im Auftrag genannte Regel im
Code nicht auffindbar war, ist das ausdrücklich als "nicht gefunden" vermerkt statt angenommen.

Alle Geldbeträge werden intern in Cent geführt (`amount_cents`, Ganzzahl) und für die Anzeige in EUR umgerechnet.
In diesem Kapitel werden Beträge zur besseren Lesbarkeit im Format 1.234,56 EUR wiedergegeben.

---

## 1. Rechnungssynchronisation Lexware Office

@@diagramm 07-rechnungssynchronisation

### Zweck
Offene und überfällige Rechnungen sowie die zugehörigen Kunden aus Lexware Office in die lokalen Tabellen
`invoices` und `customers` übernehmen, damit Auswahl, Stichworterkennung und SEPA-Einzug auf einem lokalen,
mandantengetrennten Datenbestand arbeiten können, ohne bei jeder Seitenansicht live bei Lexware Office
nachzufragen.

### Auslöser
- Manuell: Button "Mit Lexware Office synchronisieren" auf `invoices.php` (`invoices.php:21`, Aktion `sync`).
- Automatisch (Delta-Sync): `scheduler_auto_sync()` reiht alle `auto_sync_hours` Stunden (Standard 6, `app/jobs.php:44`)
  je Firma mit aktiver Lexware-Verbindung einen Job `sync_run` ein (`app/jobs.php:358-419`).
- Automatisch (Vollabgleich): einmal täglich zur Stunde `full_sync_hour` (Standard 3 Uhr, `app/jobs.php:45`) mit
  `full = true` im Job-Payload (`app/jobs.php:401-410`); erzwingt Detailabruf jeder Rechnung
  (`cursor['force_full']`, ausgewertet in `app/sync.php:115-117`).
- Fortsetzung eines liegen gebliebenen Laufs: `scheduler_auto_sync()` schließt einen `sync_state`-Eintrag mit
  Status `running`, der weder frischen Fortschritt noch einen aktiven Job hat, sofort als Fehler und reiht die
  Fortsetzung ohne Wartezeit erneut ein (`app/jobs.php:380-398`, Testabsicherung `tools/scheduler-sync-check.sh`).
- Ohne Warteschlange (`features.queue` aus): Browser-Polling über `sync_continue` (`invoices.php:49-71`) und der
  Cron-Endpunkt `cron.php:132` (`sync_run_pending()`).
- Onboarding: `require_subscription()` statt `require_onboarded()` auf `invoices.php:12`, weil diese Seite selbst
  den letzten Onboarding-Schritt (erste Synchronisation) ausführt.

### Voraussetzungen und Berechtigungen
- Angemeldeter Nutzer mit nutzbarem Abonnement der Firma (`require_subscription()`, `app/auth.php:235-254`); jede
  eingeloggte Rolle darf synchronisieren, es gibt keine rollenspezifische Sperre für den Sync-Button selbst.
- Firma darf nicht im Wartungsmodus für die Synchronisation stehen (`organizations.sync_paused`,
  geprüft in `invoices.php:24-32` und in `job_sync_run()`, `app/jobs.php:87-90`).
- Lexware-Office-Verbindung muss bestehen: `integrations.lexoffice_connected = 1` und entschlüsselbarer API-Key
  (`sync_lex_client()`, `app/sync_state.php:110-127`; Adaptergrenze `invoice_source_for_tenant()` in
  `app/invoice_source.php`).
- Nicht doppelt gleichzeitig: `sync_state_start()` legt keinen zweiten Lauf an, wenn bereits einer mit Fortschritt
  in den letzten `SYNC_STALE_MINUTES` (30, `app/sync_state.php:27`) läuft (`sync_state_is_running()`,
  `app/sync_state.php:45-58`).

### Eingabedaten und Validierung
- Kein Formular-Eingabefeld; einziger Parameter ist die Firma (`tenantId`) aus dem Sitzungskontext.
- Aus Lexware Office gelesene Felder werden defensiv behandelt: Zeitstempel nur übernommen, wenn sie sich als
  `DateTimeImmutable` parsen lassen (`_sync_parse_datetime()`, `app/sync.php:53-63`), Fälligkeitsdatum nur bei
  gültigem `YYYY-MM-DD`-Muster (`_sync_parse_date()`, `app/sync.php:642-649`), Rechnungsnummer notfalls durch die
  Lexware-ID ersetzt (`app/sync.php:171`).
- Konfigurationswerte (`config('sync', [])`) werden auf sinnvolle Grenzen begrenzt: `step_seconds` 2 bis 25,
  `step_max` 1 bis 200, `step_max_api_calls` 5 bis 500, `contact_refresh_hours` 0 bis 720
  (`sync_rules_config()`, `app/sync.php:40-50`).

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/sync.php` | `sync_rules_config()` | 40 |
| `app/sync.php` | `sync_invoices_step()` (schrittweiser, cursorbasierter Lauf) | 94 |
| `app/sync.php` | `sync_invoices()` (blockierender Volllauf, nur CLI/kleine Bestände) | 333 |
| `app/sync.php` | `_sync_process_voucher()` (eine Rechnung übernehmen) | 431 |
| `app/sync.php` | `_sync_upsert_customer()` (Kunde/Kontakt abgleichen, Cache) | 532 |
| `app/sync.php` | `_voucher_sort_key()` (Sortierschlüssel Rechnungsnummer) | 408 |
| `app/sync_state.php` | `sync_state_start()` (Lauf eröffnen, Doppelstart-Schutz) | 64 |
| `app/sync_state.php` | `sync_state_step()` (ein Schritt mit Datenbank-Sperre) | 133 |
| `app/sync_state.php` | `sync_state_cancel()` | 89 |
| `app/sync_state.php` | `sync_run_pending()` (Cron: alle laufenden Firmen fortsetzen, Round-Robin) | 267 |
| `app/sync_state.php` | `sync_progress()` / `sync_progress_fragment()` (Fortschrittsanzeige) | 325 / 493 |
| `app/jobs.php` | `job_sync_run()` (Warteschlangen-Handler) | 71 |
| `app/jobs.php` | `scheduler_tick()` | 323 |
| `app/jobs.php` | `scheduler_auto_sync()` (Delta- und Vollabgleich-Planung, verwaiste Läufe) | 358 |
| `invoices.php` | Aktionen `sync`, `sync_continue`, `sync_cancel` | 21, 49, 73 |
| `cron.php` | Aufruf `sync_run_pending()` | 132 |

### Gelesene und veränderte Tabellen
- Gelesen: `organizations` (Name, `sync_paused`), `integrations` (Verbindungsstatus, API-Key verschlüsselt),
  `sync_state`, `invoices` (Recheck-Kandidaten), `customers`.
- Verändert: `sync_state` (Status, `cursor_json`, `lock_until`, `lock_owner`, `result_json`, `skipped_starts`),
  `sync_runs` (Historie je Lauf, siehe `sync_run_open()`/`sync_run_finish()`, `app/sync_state.php:379-434`),
  `invoices` (Insert/Update: Betrag, Fälligkeit, Status, Stichwort, `collection_status` nur, wenn dort noch
  `open` steht, siehe unten), `customers` (Insert/Update: Name, Kundennummer, E-Mail, `is_walk_in`), `integrations`
  (`lexoffice_last_sync`).

### Externe Schnittstellenaufrufe
Über `InvoiceSource` (Adaptergrenze, `app/invoice_source.php:27-52`), aktuell ausschließlich
`LexwareOfficeSource`/`LexofficeClient` (`app/lexoffice.php`):
- `getInvoiceVouchersPage($status, $page)`: günstige Liste (Phase `listing`).
- `getInvoiceDetail($invoiceId)`: Detailabruf je Rechnung (Phase `processing`/`recheck`).
- `getContact($contactId)`: Kontaktdaten, mit Lauf-Cache (`contact_cache`) und zeitbasiertem Wiederverwendungs-
  fenster (`contact_refresh_hours`).
Jeder Aufruf läuft über `api_call_gate('lexoffice', ...)` (`app/lexoffice.php:89-93`), also mit Ratenbegrenzung
(Standard 2/s je Schlüssel, 50/s global, `app/queue.php:766ff.`) und Circuit Breaker (`api_circuits`).

### Verarbeitungsschritte (nummeriert)
1. `sync_state_start()` prüft, ob bereits ein Lauf mit Fortschritt läuft; falls ja, wird nur `skipped_starts`
   hochgezählt und die Aktion protokolliert (`sync_start_skipped`), sonst wird `sync_state` auf `running` gesetzt
   und ein Historieneintrag in `sync_runs` eröffnet (`sync_run_open()`).
2. `sync_state_step()` holt eine Datenbanksperre (`lock_until`/`lock_owner`, `SYNC_LOCK_SECONDS = 180`) für genau
   einen Aufrufer; gelingt das nicht, kommt der Schritt als `skipped` zurück (kein Doppelschritt).
3. Phase `listing`: alle Seiten der offenen, danach der überfälligen Rechnungsliste abrufen (nur Nummer/ID/Status/
   `updatedDate`, kein Detailabruf), je Lauf einmal je Seite (`lex_page_content`-Cache verhindert doppelte Seiten-
   abrufe innerhalb eines Schritts).
4. Nach vollständiger Liste: absteigend nach numerischem Anteil der Rechnungsnummer sortieren
   (`_voucher_sort_key()`), damit bei Abbruch mitten im Lauf die neuesten Rechnungen zuerst aktuell sind.
5. Phase `processing`: je Rechnung `_sync_process_voucher()` aufrufen. Ist `skip_unchanged` aktiv und `updatedDate`
   sowie `voucherStatus` gegenüber dem gespeicherten Stand unverändert, wird nur `last_synced_at` aktualisiert
   (kein Detailabruf, zählt als `skipped_unchanged`, nicht gegen das Zeit-/Aufrufbudget). Sonst Detailabruf,
   Kunde/Kontakt auflösen (`_sync_upsert_customer()`, mit Cache und `contact_refresh_hours`), Stichwort aus den
   Positionen ableiten (`extract_keyword()`), Insert oder Update von `invoices`.
6. Nach vollständiger Verarbeitung: Mengendifferenz bilden, lokale Rechnungen mit Status `open`/`overdue`, deren
   Lexware-ID in der aktuellen Liste NICHT vorkam, werden sofort in einem SQL-Befehl auf `lexoffice_status =
   'not_open'` gesetzt; `collection_status` wird dabei nur von `open` auf `none` verändert (laufende Stripe-
   Vorgänge wie `in_collection`/`scheduled` bleiben unberührt, `app/sync.php:253-270`).
7. Phase `recheck`: für jede so gefundene Rechnung wird per Detailabruf der exakte neue Status ermittelt
   (`paid` → `collection_status = collected`; `voided`/`cancelled` → `none`).
8. Nach Abschluss der Recheck-Liste: `integrations.lexoffice_last_sync = NOW()`, Lauf wird als `done` markiert,
   `sync_runs` erhält den Endstatus, `audit_log` erhält `sync_completed`, und (einmalig je Firma) das Funnel-
   Ereignis `first_sync` wird vermerkt.
9. Cron/Warteschlange: `job_sync_run()` ruft `sync_state_step()` wiederholt auf, bis `done` oder das Zeitbudget
   je Versuch (`sync_attempt_seconds`, Standard 600 s) bzw. die Schrittzahl (`sync_max_steps_attempt`, 60) erreicht
   ist; danach wird der Job mit `JobRequeueException` sofort fortgesetzt (kein Fehlversuch).

### Statusänderungen
- `sync_state.status`: `idle` → `running` → `done`/`error`; nach Anzeige wird `done` wieder auf `idle` gesetzt.
- `invoices.lexoffice_status`: laut Lexware Office (`open`, `overdue`, `paid`, `voided`, `cancelled`, `not_open`
  als Zwischenwert bis zur Einzelprüfung).
- `invoices.collection_status`: durch den Sync nur in Richtung `none`/`collected` verändert, nie in Richtung
  `in_collection`/`scheduled` (das ist Sache der Einzugslogik).

### Ergebnis und Nebenwirkungen
Ergebnisobjekt `{synced, new, updated, removed}` plus Messwerte (`metrics`: Schritte, API-Aufrufe, Wartezeit durch
Drosselung, Wiederholungen, übersprungene unveränderte Rechnungen, wiederverwendete Kontakte). Wird auf
`invoices.php` und in `synchronisationen.php` (Historie aus `sync_runs`) angezeigt.

### Fehlerfälle
- Verbindung/API-Key fehlt oder ungültig: `sync_lex_client()` wirft, im Job-Pfad wird das als `auth`-Kategorie
  erkannt und der Job endet endgültig (`JobFailedException`, kein Wiederholungsversuch,
  `app/jobs.php:130-135`).
- Netzwerk-/Zeitüberschreitungsfehler: außerhalb des Workers (`IN_WORKER` nicht definiert) endet der Lauf mit
  Status `error`; innerhalb des Workers bleibt der Cursor erhalten und der nächste Versuch setzt am Checkpoint
  fort (`app/sync_state.php:206-217`).
- Einzelne Rechnung beim Recheck nicht abrufbar: wird geloggt (`error_log`) und übersprungen, bricht den Lauf
  nicht ab (`app/sync.php:309-311`).
- Sperre während des Schritts verloren (Zeitüberschreitung): `_sync_lock_lost()` verwirft den Cursor dieses
  Schritts, protokolliert `sync_lock_lost` im Audit-Log; die bereits geschriebenen Datenbankänderungen bleiben
  bestehen (Upserts sind idempotent), nur der Cursor-Fortschritt dieses einen Schritts geht verloren.

### Wiederholungs- und Abbruchverhalten
- Kooperativer Abbruchpunkt nach jedem abgeschlossenen Schritt (`worker_stop_requested()`,
  `app/jobs.php:165-167`): bei Worker-Stop (Deployment) wird der Job ohne Fehlversuch sofort mit dem
  gespeicherten Cursor fortgesetzt (`WorkerShutdownException`, siehe Abschnitt "Schutz vor Doppelverarbeitung").
- Verwaiste Läufe (hart beendeter Worker, kein Fortschritt mehr, kein aktiver Job): werden vom Scheduler erkannt,
  als `failed` geschlossen und ihre Fortsetzung sofort neu eingereiht (`scheduler_auto_sync()`,
  `app/jobs.php:380-398`), nicht erst zur nächsten regulären Fälligkeit.
- Rechnungssynchronisation ist an keine Uhrzeit gebunden (im Unterschied zum Einreichfenster für Einzüge); das
  Einreichfenster prüft ausschließlich `app/collections.php`.

### Protokollierung (audit_log-Aktionen)
`sync_start_skipped`, `sync_requested`, `sync_cancelled`, `sync_completed`, `sync_lock_lost`.

### Zugehörige Tests
- `tools/scheduler-sync-check.sh`: Regressionstest des automatischen Sync-Plans (verwaiste Läufe, sofortige
  Fortsetzung, Einreichfenster wirkt NICHT auf die Synchronisation) gegen eine echte, temporäre MariaDB.
- `tools/worker-signal-check.sh`: Signalmodell (SIGTERM/SIGQUIT/SIGINT, Notbremse) für unterbrechbare Jobtypen
  wie `sync_run`.
- Im Auftrag CLAUDE.md genannte Dateien `scratchpad/e2e_saas.php`, `test_sync_perf.php`, `test_sync_lock.php`
  sind im vorliegenden Repository-Stand nicht vorhanden (nicht gefunden im Code); sie werden dort als
  Arbeitsanweisung für künftige Testläufe erwähnt, existieren aber nicht als Datei unter `php-ionos/` oder
  `tools/`.
- `docs/sync-performance.md` (Fachdokumentation, nicht Teil des Testlaufs) beschreibt den Leistungshintergrund
  der Schrittlogik.

---

## 2. Auswahl einziehbarer Rechnungen und Stichwortregeln

### Zweck
Aus allen synchronisierten Rechnungen diejenigen bestimmen, die aktuell per SEPA-Lastschrift eingezogen werden
dürfen, und aus den Rechnungspositionen ein Stichwort sowie einen SEPA-konformen Verwendungszweck ableiten.

### Auslöser
- Aufbau der Rechnungsliste `invoices.php` (jede Anzeige, kein Nutzeraktion nötig).
- Sammel-Einzug: Aktion `submit_all_ready` auf `collections.php` (`collections.php:67`).
- Anzeige der Kandidatenzahl vor dem Sammel-Einzug: `count_ready_for_collection()`.
- Stichwortableitung läuft immer während der Synchronisation (`_sync_process_voucher()`, s. Abschnitt 1), nur bei
  geänderten Positionen erneut berechnet (`app/sync.php:485-493`).

### Voraussetzungen und Berechtigungen
Keine gesonderte Berechtigung über `require_subscription()` hinaus; Anzeige der Einzugs-Aktionen hängt von den
unten genannten Bedingungen ab, nicht von der Rolle. Auslösen eines Einzugs selbst unterliegt den Regeln aus
Abschnitt 4 (Not-Stopp, Kontingent, Mandat).

### Eingabedaten und Validierung
Keine Nutzereingabe; die Auswahl ist eine reine SQL-/PHP-Bedingung auf bereits synchronisierten Daten.
`extract_keyword()` erhält die JSON-dekodierten `lineItems` aus Lexware Office; fehlt `name`/`description`, wird
mit leerem Text weitergerechnet (kein Fehler).

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/keywords.php` | `KEYWORD_CATALOG` (9 Kategorien inkl. "Sonstiges") | 14 |
| `app/keywords.php` | `extract_keyword()` (Positionen → Stichwort) | 94 |
| `app/keywords.php` | `sanitize_for_sepa()` (SEPA-Zeichensatz) | 67 |
| `app/keywords.php` | `build_description()` (Verwendungszweck, max. 140 Zeichen) | 151 |
| `invoices.php` | Berechnung `$collectable` je Zeile | 299 |
| `app/collections.php` | `submit_all_ready_collections()` (Kandidaten-Query) | 1611 |
| `app/collections.php` | `count_ready_for_collection()` | 1669 |
| `app/sync.php` | Stichwort-Neuberechnung nur bei geänderten Positionen | 485 |

### Gelesene und veränderte Tabellen
Gelesen: `invoices`, `customers`, `customer_ibans` (nur `EXISTS`-Prüfung auf aktive IBAN). Verändert werden in
diesem Abschnitt nur `invoices.keyword`/`invoices.keyword_sepa` als Nebenprodukt der Synchronisation (Abschnitt 1);
die Auswahl selbst schreibt nichts.

### Externe Schnittstellenaufrufe
Keine (reine Auswertung bereits synchronisierter, lokaler Daten).

### Verarbeitungsschritte (nummeriert)
1. Je Position (`lineItems`) Name und Beschreibung kleingeschrieben zusammenführen.
2. Für jede Kategorie im Katalog (außer "Sonstiges") mit Wortgrenzen-Regex (`\b`, unicode-fähig über `(*UCP)`)
   prüfen, ob einer der Suchbegriffe vorkommt; bei Treffer den höchsten Positionsbetrag je Kategorie merken.
3. Kein Treffer: Ergebnis `["Sonstiges", "Sonstiges"]`.
4. Genau ein Treffer: dessen Anzeige- und SEPA-Name.
5. Genau zwei Treffer: beide Namen alphabetisch sortiert, mit `/` verbunden (Anzeige und SEPA-Name getrennt).
6. Drei oder mehr Treffer: Kategorie mit dem betragsmäßig höchsten Treffer gewinnt.
7. `build_description()` setzt den Firmennamen voran, dann `SEPA <Rechnungsnummer> KD <Kundennummer> -
   <Stichwort>`, saniert auf den erlaubten SEPA-Zeichensatz und kürzt bei Bedarf zuerst das Stichwort, damit die
   Gesamtlänge 140 Zeichen nicht überschreitet.
8. Auswahlbedingung `$collectable` (`invoices.php:299-303`): Lexware-Status `open` oder `overdue` UND
   `collection_status` NICHT `in_collection`/`scheduled` UND ein verknüpfter Kunde vorhanden UND SEPA-Einzug für
   den Kunden nicht deaktiviert UND kein offener Klärungsbedarf (`requires_review`).
9. Sammel-Einzug (`submit_all_ready_collections()`) verschärft das um: Kunde hat mindestens eine aktive IBAN
   (`EXISTS`-Unterabfrage auf `customer_ibans`).

### Statusänderungen
Keine (reine Selektion); Statusänderungen entstehen erst durch den nachfolgenden Einzug (Abschnitt 4).

### Ergebnis und Nebenwirkungen
Anzeige-Flags je Rechnungszeile (`$collectable`, `$partial`, `$nothingOpen`, Hinweistexte); bei Sammel-Einzug eine
Liste von Kandidaten-IDs, die anschließend einzeln durch `submit_collection()` laufen (mit allen dortigen Prüfungen,
siehe Abschnitt 4; die Selektion ist also nur eine Vorauswahl, keine Garantie für einen erfolgreichen Einzug).

### Fehlerfälle
Kein eigener Fehlerfall in der Selektion selbst; ein leeres `lineItems`-Array liefert unverändert `["Sonstiges",
"Sonstiges"]`.

### Wiederholungs- und Abbruchverhalten
Nicht zutreffend (zustandslose Berechnung je Aufruf).

### Protokollierung (audit_log-Aktionen)
Keine eigene; Protokollierung erfolgt beim nachfolgenden Einzug (Abschnitt 4).

### Zugehörige Tests
Im Repository kein eigenständiges `tools/*`-Skript für `app/keywords.php` gefunden (nicht gefunden im Code). Im
Auftrag CLAUDE.md genannte Datei `scratchpad/e2e_saas.php` (deckt laut Projektbeschreibung auch Stichwort- und
Auswahllogik ab) ist im vorliegenden Stand nicht vorhanden.

---

## 3. Mandatszuordnung und Mandatsarten

### Zweck
Jedem Kunden ein gültiges, eindeutig referenziertes SEPA-Basislastschriftmandat zuordnen, bevor ein Einzug
möglich ist, wahlweise über eine im Portal erfasste IBAN, eine digital über Stripe Checkout erteilte
Einwilligung oder ein hochgeladenes Dokument eines papierhaft unterschriebenen Mandats.

### Auslöser
- IBAN-Erfassung im Portal: Aktion `add_iban` auf `customer.php:39`.
- Mandatsdokument erzeugen: Aktion `create_mandate` auf `customer.php:54`.
- Unterschrift erfassen: Aktion `mark_signed` auf `customer.php:69`.
- Mandatsdatei hochladen: Aktion `upload_mandate_file` auf `customer.php:76`.
- Digitale Mandatsanforderung senden: Aktion `request_mandate_digital` auf `customer.php:87` (nur, wenn
  `config('features')['mandate_request']` aktiv ist, geprüft in `mandate_request_feature_enabled()`,
  `app/mandate_requests.php:39-43`).
- Öffentliche Kundenseite `mandat.php`: POST mit akzeptierter Checkbox startet die Stripe Checkout Session
  (`mandat.php:31-39`).
- Stripe-Webhook `checkout.session.completed` (Modus `setup`): schließt die digitale Erteilung ab
  (`stripe-webhook.php:123-155`, ruft `mandate_request_grant()`).
- Widerruf: Aktion `cancel_mandate` (`customer.php:107`) bzw. `revoke_mandate_request` (`customer.php:103`).
- Automatisch bei jedem Einzugsversuch: `get_or_create_mandate()` wird aus `submit_collection()` und
  `_submit_single_scheduled()` aufgerufen, um ein bestehendes Mandat wiederzuverwenden oder (nur mit IBAN) einen
  Entwurf anzulegen.
- Erinnerungen an offene digitale Anforderungen: Jobtyp `mandate_reminders`
  (`app/jobs.php:275-282`, stündlich über `scheduler_tick()`), ruft `mandate_request_remind()` auf.

### Voraussetzungen und Berechtigungen
- Alle Aktionen auf `customer.php` erfordern `require_subscription()`; Löschen eines Mandatsdokuments zusätzlich
  `can_manage_settings()` (Inhaber/Administrator, `customer.php:80-82`).
- Für Laufkunden (`customers.is_walk_in = 1`, Sammel-Kundennummer) ist weder ein personenbezogenes Mandat noch
  eine digitale Anforderung zulässig (`customer.php:55-57`, `mandate_request_create()`,
  `app/mandate_requests.php:114-116`).
- Digitale Anforderung setzt eine hinterlegte Kunden-E-Mail-Adresse voraus (`customer.php:91-93`) und eine
  bestehende Stripe-Verbindung der Firma (`_get_stripe_client()` in `mandate_request_create()`,
  `app/mandate_requests.php:121-122`).
- Öffentliche Mandatsseite `mandat.php` verlangt keine Anmeldung, aber ein gültiges Token (64 Hex-Zeichen,
  `mandate_request_load_by_token()`, `app/mandate_requests.php:170-195`) und schützt sich über
  Referrer-Policy, `X-Robots-Tag`, `X-Frame-Options: DENY` und eine enge Content-Security-Policy
  (`mandat.php:14-18`).

### Eingabedaten und Validierung
- IBAN: `validate_iban()` (in `app/iban.php`, hier nur referenziert), BIC-Format `^[A-Z]{6}[A-Z0-9]{2}
  ([A-Z0-9]{3})?$` (`app/customer_settings.php:81-83`), Kontoinhaber darf nicht leer sein.
- Mandatsreferenz: 1 bis 35 Zeichen, eingeschränkter SEPA-Zeichensatz
  `^[A-Za-z0-9+?/\-:().,' ]+$` (`validate_mandate_reference()`, `app/mandates.php:39-48`); Vergabe automatisch
  als `<Firmenpräfix><Kundennummer>` bzw. bei Kollision mit laufender Endung `-2`, `-3`, ... (`mandate_next_
  reference()`, `app/mandates.php:96-130`), für Laufkunden `<Präfix><JJJJMMTT><laufende Nummer>`.
- Gläubiger-Identifikationsnummer: Format `^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$`, für `DE` exakt 18
  Stellen, Prüfsumme nach ISO 7064 Mod 97-10 (`validate_creditor_identifier()`, `app/mandates.php:55-81`).
- Unterschriftsdatum: gültiges Kalenderdatum, nicht in der Zukunft (`mandate_mark_signed()`,
  `app/mandates.php:248-254`); Ort darf nicht leer sein.
- Hochgeladene Datei: nur `application/pdf`, `image/jpeg`, `image/png`, geprüft am Dateiinhalt (nicht an der
  Endung) über `finfo`, PDF zusätzlich am Signaturkopf `%PDF-` (`mandate_file_store()`, `app/mandate_files.php:
  48-79`), maximal 10 MB (`MANDATE_FILE_MAX_BYTES`, `app/mandate_files.php:17`).

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/mandates.php` | `validate_mandate_reference()` | 39 |
| `app/mandates.php` | `validate_creditor_identifier()` | 55 |
| `app/mandates.php` | `mandate_next_reference()` | 96 |
| `app/mandates.php` | `get_or_create_mandate()` | 137 |
| `app/mandates.php` | `mandate_requires_manual_renewal()` | 196 |
| `app/mandates.php` | `mandate_mark_signed()` | 242 |
| `app/mandates.php` | `mandate_cancel()` | 268 |
| `app/mandates.php` | `mandate_check_usable()` (36-Monats-Regel, IBAN, Unterschriftspflicht) | 290 |
| `app/mandates.php` | `mandate_texts()` (Mandatswortlaut, PSP-Hinweis Stripe) | 325 |
| `app/mandate_requests.php` | `mandate_request_create()` | 109 |
| `app/mandate_requests.php` | `mandate_request_start_checkout()` | 201 |
| `app/mandate_requests.php` | `mandate_request_grant()` | 247 |
| `app/mandate_requests.php` | `mandate_request_remind()` | 368 |
| `app/customer_settings.php` | `set_customer_iban()` | 59 |
| `app/customer_settings.php` | `set_customer_sepa_debit()` | 23 |
| `app/customer_settings.php` | `deactivate_customer_iban()` | 169 |
| `app/mandate_files.php` | `mandate_file_store()` | 48 |
| `app/mandate_files.php` | `mandate_file_handle_upload()` | 184 |
| `customer.php` | Aktionen `add_iban`, `create_mandate`, `mark_signed`, `upload_mandate_file`,
  `request_mandate_digital`, `cancel_mandate` | 39, 54, 69, 76, 87, 107 |
| `mandat.php` | öffentliche Erteilungsseite | gesamte Datei |
| `stripe-webhook.php` | Zweig `checkout.session.completed` | 123-155 |

### Gelesene und veränderte Tabellen
- Gelesen: `customers`, `customer_ibans`, `sepa_mandates`, `mandate_requests`, `organizations` (Präfix,
  Gläubiger-ID, Anschrift, `require_signed_mandate`, `pre_notification_days`).
- Verändert: `customer_ibans` (Insert, alte aktive IBAN deaktivieren), `iban_history` (Verlauf jeder Änderung),
  `sepa_mandates` (Insert/Update: Referenz, Status `draft`/`active`/`cancelled`/`expired`, Unterschrift, Stripe-
  Zahlungsmethode/-Kunde/-Mandat), `mandate_requests` (Status `requested`→`pending`→`granted`/`unusable`/
  `revoked`/`expired`), `mandate_files` (Metadaten hochgeladener Dokumente), `customers.sepa_debit_enabled`.

### Externe Schnittstellenaufrufe
- Stripe: `findOrCreateCustomer()`, `createSepaPaymentMethod()`, `attachPaymentMethod()` (sofortige Registrierung
  einer manuell erfassten IBAN, `register_iban_with_stripe()`, `app/collections.php:937-994`).
- Stripe Checkout (Modus `setup`, keine Zahlung): `createSetupCheckoutSession()`
  (`mandate_request_start_checkout()`, `app/mandate_requests.php:218-229`).
- Stripe (nach Abschluss): `getPaymentMethod()`, `getMandate()` (Referenz-String von Stripe),
  `getSetupIntent()` (im Webhook, zur Prüfung, dass der SetupIntent `succeeded` ist).

### Verarbeitungsschritte (nummeriert)
**Portal-IBAN (manuell):**
1. `set_customer_iban()` validiert IBAN/BIC/Kontoinhaber.
2. Bisherige aktive IBAN des Kunden wird deaktiviert, Historieneintrag geschrieben, neue IBAN aktiv gesetzt
   (alles in einer Transaktion, `app/customer_settings.php:94-131`).
3. Bei Nicht-Laufkunden wird `sepa_debit_enabled = 1` gesetzt.
4. Nach dem Commit: vorhandenes Mandat an die neue IBAN binden bzw. neu anlegen (`get_or_create_mandate()`),
   Fehler dabei brechen die IBAN-Speicherung nicht ab (nur geloggt).
5. Direkt bei Stripe registrieren (`register_iban_with_stripe()`), ebenfalls fehlertolerant; die Registrierung
   holt die Anwendung sonst beim ersten Einzug automatisch nach.

**Digitale Erteilung (Stripe Checkout):**
1. `mandate_request_create()` erzeugt ein 32-Byte-Zufallstoken, speichert nur dessen SHA-256-Hash, Gültigkeit
   14 Tage (`MANDATE_REQUEST_DAYS`), widerruft eine zuvor bestehende aktive Anforderung desselben Kunden.
2. E-Mail mit Link `mandat.php?t=<token>` wird versendet, sofern Mailversand eingerichtet ist; sonst wird der
   Link einmalig im Portal angezeigt (nicht gespeichert).
3. Kunde öffnet den Link, bestätigt eine Checkbox, POST startet `mandate_request_start_checkout()`: Stripe-Kunde
   sicherstellen, Checkout-Session im Modus `setup` anlegen, Status der Anforderung auf `pending`.
4. Kunde gibt die IBAN direkt bei Stripe ein (Anwendung bekommt sie nie im Klartext).
5. Stripe sendet `checkout.session.completed`; der Webhook lädt den SetupIntent, prüft `status === succeeded`
   und ruft `mandate_request_grant()` auf.
6. `mandate_request_grant()` schaltet die Anforderung atomar von `requested`/`pending` auf `granted`
   (`UPDATE ... WHERE status IN (...)`, verhindert Doppelverarbeitung bei wiederholten Webhooks).
7. Zahlungsmethode und ggf. Stripe-Mandat werden abgerufen; passende bereits aktive IBAN (gleiches Land, gleiche
   letzte 4 Ziffern) wird weiterverwendet, sonst eine maskierte Bankverbindung (`source = stripe_digital`)
   angelegt und die bisherige aktive deaktiviert.
8. Lokales SEPA-Mandat wird aktiviert, `signed_place = 'digital (Stripe)'`, `signed_date`/`mandate_date` auf das
   heutige Datum (Zeitzone der Anwendung, nicht die Datenbankzeitzone, siehe Kommentar
   `app/mandate_requests.php:342-344`).

**Papierhaftes Mandat (Upload):**
1. `create_mandate` erzeugt (falls nötig) ein Mandat als Entwurf und leitet zum Dokument `mandate-print.php`
   weiter, `mandate_mark_document_generated()` vermerkt Zeitpunkt/Nutzer.
2. Nutzer lädt das unterschriebene Dokument hoch; `mandate_file_store()` prüft Größe/Typ/Signatur, speichert
   außerhalb des Webzugriffs unter `app/storage/mandates/<tenant_id>/<Zufallsname>` mit SHA-256-Prüfsumme.
3. Optional in einem Schritt: `mark_signed`/`mandate_file_handle_upload()` mit `mark_signed=1` erfasst zugleich
   Unterschriftsdatum und -ort (`mandate_mark_signed()`), aktiviert einen Entwurf mit vorhandener IBAN.

**Wiederverwendung/Erneuerung:**
1. `get_or_create_mandate()` verwendet ein bestehendes Mandat mit Status `draft`/`active` weiter; ändert sich die
   IBAN, wird die Stripe-Zahlungsmethode zurückgesetzt (muss neu angelegt werden) und ein Entwurf auf `active`
   gehoben.
2. `mandate_requires_manual_renewal()` verhindert eine automatische Neuanlage, wenn ein früheres Mandat
   widerrufen oder verfallen ist; in diesem Fall muss ein neues Mandat ausdrücklich über die Kundendetails
   erfasst werden (nicht automatisch beim nächsten Einzugsversuch).

**Widerruf:**
1. `mandate_cancel()` setzt `is_active = 0`, `status = cancelled`, `cancelled_at`, `cancel_reason`; das Mandat
   bleibt zur Aufbewahrung in der Datenbank erhalten (nie Löschung).
2. `mandate_request_revoke()` widerruft eine offene digitale Anforderung, der Link wird sofort ungültig.

### Statusänderungen
`sepa_mandates.status`: `draft` → `active` → `cancelled`/`expired`. `mandate_requests.status`: `requested` →
`pending` → `granted`/`unusable`/`revoked`/`expired`.

### Ergebnis und Nebenwirkungen
Ein einsatzbereites Mandat (mit oder ohne Stripe-Zahlungsmethode) bzw. eine dem Kunden zugestellte
Erteilungsanforderung. Bei digitaler Erteilung liegt die IBAN der Anwendung nur maskiert vor (Land, Bankleitzahl,
letzte vier Stellen).

### Fehlerfälle
- Ungültige Mandatsreferenz/Gläubiger-ID/IBAN/BIC: `RuntimeException` mit Fehlertext, keine Datenbankänderung.
- SetupIntent nicht erfolgreich oder Zahlungsmethode fehlt: `mandate_request_grant()` setzt Status `unusable`
  (`app/mandate_requests.php:264-268`).
- Stripe-Zahlungsmethode/-Mandat beim Nachladen nicht abrufbar: wird geloggt, Verarbeitung läuft ohne diese
  Zusatzdaten weiter (kein Abbruch).
- Upload mit falschem Dateityp, zu groß, leer oder beschädigt: `RuntimeException` vor jeder Speicherung.

### Wiederholungs- und Abbruchverhalten
- `mandate_request_grant()` ist idempotent gegen Webhook-Wiederholungen (atomares `UPDATE ... WHERE status IN
  ('requested','pending')`, zweiter Aufruf ändert `rowCount()` auf 0 und liefert `false`).
- Erinnerungen: höchstens `MANDATE_REQUEST_MAX_REMINDERS` (2), frühestens nach
  `MANDATE_REQUEST_REMIND_AFTER_DAYS` (4) Tagen; jede Erinnerung erzeugt ein neues Token, das alte wird ungültig
  (`mandate_request_remind()`, `app/mandate_requests.php:368-400`).
- 36-Monats-Verfall: bei jeder Prüfung (`mandate_check_usable()`) wird ein abgelaufenes Mandat sofort dauerhaft
  als `expired` markiert, auch außerhalb einer laufenden Einzugstransaktion (`submit_collection()`,
  `app/collections.php:1240-1247`).

### Protokollierung (audit_log-Aktionen)
`mandate_request_sent`, `mandate_request_started`, `mandate_request_revoked`, `mandate_request_reminded`,
`mandate_granted_digital`, `mandate_document`, `mandate_signed`, `mandate_file_uploaded`, `mandate_file_deleted`,
`mandate_cancelled`, `iban_saved`, `iban_deactivated`, `sepa_toggle`.

### Zugehörige Tests
Kein spezifisches `tools/*`-Skript für Mandatszuordnung im Repository gefunden (nicht gefunden im Code). Im
Auftrag CLAUDE.md genannte Datei `scratchpad/e2e_saas.php` deckt laut Projektbeschreibung auch Mandatsszenarien
ab, ist aber im vorliegenden Stand nicht vorhanden.

---

## 4. Lastschriftanlage und Einreichung

@@diagramm 08-lastschrift-zustaende

### Zweck
Für eine ausgewählte Rechnung eine SEPA-Lastschrift bei Stripe anlegen bzw. terminieren, unter Einhaltung von
Not-Stopp, Kontingent, Mandatsgültigkeit, Karenzzeit, Einreichfenster und einer Restbetragsprüfung unmittelbar
vor dem Stripe-Aufruf.

### Auslöser
- Sofort-Einzug: Aktion `collect` auf `invoices.php:77` bzw. `submit_collection($scheduledDate = null)`.
- Terminierter Einzug: Aktion `schedule` auf `invoices.php:92`.
- Sammel-Einzug: Aktion `submit_all_ready` auf `collections.php:67` (`submit_all_ready_collections()`).
- Fällige terminierte/vorgemerkte Einzüge einreichen: Cron (`cron.php:81`, `process_scheduled_collections()`),
  Warteschlangen-Job `collections_due` (`app/jobs.php:176-210`, alle 300 s geplant, `app/jobs.php:332`), Button
  "Fällige jetzt einreichen" (`collections.php:28`, mit `process_due_now` als erzwungene Ausnahme vom
  Einreichfenster).
- Stornierung/Umterminierung: Aktionen `cancel`/`reschedule` auf `collections.php:20/23`.

### Voraussetzungen und Berechtigungen
- `require_subscription()` für alle Nutzeraktionen; erzwungene Einreichung außerhalb des Fensters
  (`process_due_now`) zusätzlich `can_manage_settings()` und ein frischer 2FA-Code
  (`require_recent_totp()`, `collections.php:31-36`).
- Support-Modus des Plattformbetreibers sperrt jede Einreichung (`support_mode()`,
  `app/collections.php:1211-1216`, `collections.php:29/53`, `support_guard()`).
- Not-Stopp plattformweit (`platform_settings.collections_paused`) oder je Firma
  (`organizations.collections_paused`) blockiert jede Einreichung, geprüft an mehreren Stellen
  (`collections_pause_reason()`, `app/collections.php:114-125`; genutzt in `_submit_collection_locked()`,
  `_submit_single_scheduled()`, `submit_all_ready_collections()`, `process_scheduled_collections()`).
- Kontingent der Abrechnungsperiode (`collections_quota_check()`, `app/plans.php:175-206`): zählt alle Einzüge
  außer stornierten seit Periodenbeginn; ab 80 % Warnung mit Tarifempfehlung, bei Erreichen des Limits Ablehnung.
- Mandat muss verwendbar sein (`mandate_check_usable()`, siehe Abschnitt 3).

### Eingabedaten und Validierung
- `invoice_id` muss zur Firma gehören, Status `open`/`overdue`, `collection_status` nicht bereits
  `in_collection`/`scheduled`, `requires_review` nicht gesetzt, verknüpfter Kunde mit aktiver IBAN
  (`_load_and_validate()`, `app/collections.php:1040-1089`).
- Terminierungsdatum: gültiges Datum, in der Zukunft, bei aktiver Vorabankündigung mindestens
  `pre_notification_days` voraus, höchstens 365 Tage voraus, nur Werktage Mo-Fr
  (`validate_scheduled_date()`, `app/collections.php:996-1021`).
- `confirm_amount_cents`: nur akzeptiert, wenn er exakt dem live ermittelten Restbetrag entspricht (siehe unten).

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/collections.php` | `collections_rules_config()` (Karenzzeit, Fenster, Überfälligkeitsgrenze) | 151 |
| `app/collections.php` | `collections_window_open()` / `collections_earliest_submit()` | 178 / 210 |
| `app/collections.php` | `invoice_fetch_open_amount()` (Restbetrag live bei Lexware Office) | 337 |
| `app/collections.php` | `_determine_collection_amount()` (Restbetrag abzüglich eigener Einzüge) | 426 |
| `app/collections.php` | `submit_collection()` / `_submit_collection_locked()` | 1208 / 1252 |
| `app/collections.php` | `cancel_scheduled_collection()` | 1435 |
| `app/collections.php` | `reschedule_collection()` | 1471 |
| `app/collections.php` | `submit_all_ready_collections()` | 1611 |
| `app/collections.php` | `process_scheduled_collections()` (Cron/Job-Einreichung) | 1705 |
| `app/collections.php` | `_submit_single_scheduled()` | 1826 |
| `app/collections.php` | `_send_prenotification()` (Vorabankündigung per E-Mail) | 1183 |
| `app/collections.php` | `_execute_stripe_collection()` (Stripe-Aufrufe eines Einzugs) | 1108 |
| `invoices.php` | Aktionen `collect`, `schedule` | 77, 92 |
| `collections.php` | Aktionen `cancel`, `reschedule`, `process_due(_now)`, `submit_all_ready` | 20, 23, 28, 67 |
| `notstopp.php` | Not-Stopp je Firma setzen/aufheben | gesamte Datei |

### Gelesene und veränderte Tabellen
- Gelesen: `organizations` (Not-Stopp, Vorabankündigung, Präfix), `platform_settings`, `invoices`, `customers`,
  `customer_ibans`, `sepa_mandates`, `collection_attempts` (offene Versuche), `payment_collections` (eigene
  laufende/erfolgreiche Einzüge derselben Rechnung).
- Verändert: `payment_collections` (Insert bei jedem Einzug; Update von `stripe_status`, `amount_cents`, `note`,
  `scheduled_submitted`, `submit_not_before`), `invoices.collection_status`
  (`open`→`scheduled`/`in_collection`→`collected`/`failed`), `sepa_mandates`
  (`stripe_payment_method_id`, `stripe_customer_id`, `last_used_at`), `collection_attempts` (Versuchsjournal,
  siehe Abschnitt "Schutz vor Doppelverarbeitung").

### Externe Schnittstellenaufrufe
- Lexware Office: `getPayment($invoiceId)` unmittelbar vor jedem tatsächlichen Stripe-Aufruf
  (`invoice_fetch_open_amount()`), liefert `open_amount`/`currency`/`payment_status`.
- Stripe: `findOrCreateCustomer()`, `createSepaPaymentMethod()`, `attachPaymentMethod()` (falls noch keine
  Zahlungsmethode hinterlegt), `createPaymentIntent()` mit `confirm=true`, `mandate_data.customer_acceptance.type
  = offline` und einem Idempotency-Key aus dem Versuchsjournal (`_execute_stripe_collection()`,
  `app/collections.php:1108-1176`).

### Verarbeitungsschritte (nummeriert)
1. Not-Stopp prüfen (`collections_pause_reason()`).
2. Bei Terminierung: Datum validieren; bei Sofort-Einzug mit aktivierter Vorabankündigung ohne Termin: Ablehnung
   (Vorabankündigung erfordert eine Terminierung).
3. Kontingent prüfen (`collections_quota_check()`).
4. Rechnung/Kunde/IBAN laden und validieren (`_load_and_validate()`), Mandat holen oder anlegen
   (`get_or_create_mandate()`), auf Verwendbarkeit prüfen (`mandate_check_usable()`).
5. Bei geplanter Vorabankündigung: Mailversand muss eingerichtet sein und der Kunde eine E-Mail-Adresse haben,
   sonst Ablehnung vor jeder Datenbankänderung.
6. Vorprüfung ohne API-Aufruf: ein innerhalb von `OPEN_AMOUNT_CACHE_HOURS` (24 h) gespeicherter Restbetrag von 0
   blockiert sofort (`_precheck_stored_open_amount()`).
7. **Sofort-Einzug mit aktiver Karenzzeit/Einreichfenster** (`collections_grace_active()`): Stripe-Verbindung wird
   geprüft (Fehler soll sofort sichtbar sein), aber noch kein PaymentIntent angelegt; der Einzug wird als
   `payment_collections` mit `is_scheduled=1`, `queued_immediate=1`, `submit_not_before = frühester
   Einreichzeitpunkt` gespeichert und `invoices.collection_status = scheduled` gesetzt. Ein bereits offener
   Versuch für dieselbe Rechnung blockiert das Vormerken.
8. **Terminierter Einzug**: wie 7, aber `queued_immediate=0`, `scheduled_date` = gewähltes Datum; bei aktiver
   Vorabankündigung wird die E-Mail sofort versendet (`_send_prenotification()`); scheitert der Versand, wird
   die Terminierung nicht gespeichert (Exception vor dem Insert: tatsächlich erfolgt der Insert vorher, siehe
   Code; bei Fehlschlag der Ankündigung wird eine Exception geworfen, die äußere Transaktion rollt zurück).
9. **Sofort-Einzug ohne Karenzzeit/Fenster** (`grace_hours=0` und Fenster deaktiviert): Restbetrag live abrufen
   (`_determine_collection_amount()` = `invoice_fetch_open_amount()` minus eigener laufender/erfolgreicher
   Einzüge derselben Rechnung); weicht das Ergebnis vom bisherigen Rechnungsbetrag ab, ist eine ausdrückliche
   Bestätigung über exakt diesen Betrag erforderlich, sonst Ablehnung. Danach Versuchsjournal-Eintrag
   (`_execute_with_attempt()`), dann `createPaymentIntent()`; bei Erfolg Insert `payment_collections` mit
   `stripe_status = processing`, `invoices.collection_status = in_collection`, `mandate_touch_used()`.
10. **Einreichung fälliger vorgemerkter/terminierter Einzüge** (`process_scheduled_collections()`): nur, wenn
    weder plattformweiter noch Firmen-Not-Stopp aktiv ist, das Einreichfenster offen ist (außer erzwungen) und
    `submit_not_before`/`scheduled_date` erreicht ist; Termine älter als `overdue_days` (Standard 3) gelten als
    überfällig und werden NICHT automatisch nachgeholt (`overdue_skipped`).
11. Je fälligem Einzug: `_submit_single_scheduled()` beansprucht ihn atomar (`stripe_status: scheduled →
    submitting`, verhindert Doppelbehandlung durch parallelen Cron/Button), prüft Rechnung/Mandat/Kunde/IBAN
    erneut (kann sich seit Terminierung geändert haben), ermittelt den Restbetrag erneut live und reicht bei
    Stripe ein; bei Erfolg `scheduled_submitted=1`, `stripe_status=processing`.

### Statusänderungen
`payment_collections.stripe_status`: `scheduled` → `submitting` → `processing` → `succeeded`/`failed`
(weitere Übergänge in Abschnitt 5/6). `invoices.collection_status`: `open` → `scheduled`/`in_collection` →
`collected`/`failed`.

### Ergebnis und Nebenwirkungen
Bei Sofort-Einzug ohne Karenzzeit: unmittelbar ein PaymentIntent bei Stripe. Bei aktiver Karenzzeit/Fenster (der
in dieser Anwendung übliche Fall) zunächst nur ein vorgemerkter Datensatz, stornierbar bis zur tatsächlichen
Einreichung. Bei Kontingentwarnung (≥ 80 %) wird `plan_quota_warning_maybe_send()` aufgerufen (höchstens einmal
je Abrechnungsperiode).

### Fehlerfälle
- `CollectionException`: fachliche Ablehnung (z. B. Not-Stopp, Kontingent, Mandat ungültig, Restbetrag 0,
  fehlende Bestätigung): keine Wiederholung, Nutzer muss reagieren.
- `MandateUnusableException`: wie oben, zusätzlich wird das Mandat dauerhaft als `expired` markiert, auch wenn
  die Transaktion zurückgerollt wird (Abschnitt 3).
- `CollectionDeferredException`: Einzug bleibt unverändert terminiert, wird beim nächsten Lauf erneut versucht
  (z. B. Not-Stopp während der Verarbeitung, Restbetrag nicht abrufbar, Versuch bereits von anderem Prozess
  beansprucht).
- `CollectionUnknownOutcomeException`: siehe Abschnitt "Wiederholung fehlgeschlagener Zahlungsvorgänge".
- Sonstiger `Throwable` beim terminierten Einzug: `stripe_status = failed`, `invoices.collection_status =
  failed`, Fehlertext gespeichert.

### Wiederholungs- und Abbruchverhalten
- Kooperativer Abbruchpunkt zwischen zwei Einzügen im Cron-/Job-Lauf (Zeitbudget oder Worker-Stop,
  `app/collections.php:1763-1767`); bereits behandelte IDs werden im Job-Payload gemerkt (`_seen`, bis 5000
  Einträge), damit die Fortsetzung sie nicht doppelt versucht (`app/jobs.php:196-200`).
- `collections_due`-Job wird IMMER als Fortsetzung eingeplant, wenn `remaining > 0`, auch wenn noch kein Einzug
  behandelt wurde (Stop-Signal vor dem ersten Einzug), damit der nächste Lauf sofort weitermacht statt bis zum
  nächsten Scheduler-Intervall zu warten (`app/jobs.php:192-208`).
- Terminierte Einzüge mit hängendem `submitting`-Status ohne offenen Versuch werden nach 15 Minuten von
  `collection_attempts_resolve()` automatisch zurück auf `scheduled` gesetzt (`app/collections.php:778-792`).

### Protokollierung (audit_log-Aktionen)
`collection_queued`, `collection_scheduled`, `collection_submitted`, `collection_cancelled`,
`collection_rescheduled`, `collections_bulk`, `collections_due_processed`, `collections_due_skipped_paused`,
`collections_due_forced`, `collections_paused`, `collections_resumed`, `collections_bulk_cancelled`,
`quota_warning_sent`/`quota_warning_not_sent`, `support_access_blocked`.

### Zugehörige Tests
- `tools/worker-signal-check.sh`: prüft ausdrücklich, dass Geldfluss-Jobtypen (`collections_due`,
  `unclear_attempts`) NICHT durch die Notbremse hart unterbrochen werden, sondern nur an kooperativen Punkten.
- Im Auftrag CLAUDE.md genannte Dateien `test_payment_safety.php`, `scratchpad/e2e_saas.php` sind im
  vorliegenden Repository-Stand nicht vorhanden (nicht gefunden im Code).
- `docs/payment-safety.md` (Fachdokumentation) beschreibt dieselbe Reihenfolge (Not-Stopp → Kontingent/Rechnung/
  Kunde/Mandat → Restbetrag → Versuchsjournal) wie der Dateikopf von `app/collections.php:10-18`.

---

## 5. Statusabgleich über Webhooks und Klärung unklarer Versuche

@@diagramm 09-webhook-verarbeitung

### Zweck
Den bei Stripe tatsächlich erreichten Status eines Einzugs (in Bearbeitung, erfolgreich, fehlgeschlagen) in
`payment_collections`/`invoices` nachziehen, und Versuche klären, deren Ergebnis wegen einer unterbrochenen
Verbindung lokal unbekannt geblieben ist.

### Auslöser
- Stripe-Webhook `stripe-webhook.php` (Ereignisse `payment_intent.processing`/`succeeded`/`payment_failed`,
  `charge.dispute.created`, `charge.refunded`, `charge.refund.updated`, `checkout.session.completed`).
- Manueller Statusabgleich: Aktion `sync_status` auf `collections.php` (`sync_collection_statuses()`); prüft laufende
  Einzüge einzeln und seit 4.73 zusätzlich abgeschlossene Einzüge (`succeeded`, `refunded`) der letzten
  `collections.sync_lookback_days` Tage (Vorgabe 70) über die Liste der PaymentIntents mit eingebetteter Charge auf
  Rücklastschrift (`collection_apply_dispute()`) und Erstattungsstand (`collection_apply_refund()`).
- Manuelle Klärung: Aktion `resolve_attempts` auf `collections.php:52` (`collection_attempts_resolve()`).
- Automatisch: Cron (`cron.php:100-111`) und Warteschlangen-Job `unclear_attempts`
  (`app/jobs.php:213-243`, alle 600 s geplant, `app/jobs.php:333`), jeweils für Firmen mit Versuchen älter als
  5 Minuten in Status `unknown`/`pending`.

### Voraussetzungen und Berechtigungen
- Webhook: keine Anmeldung, aber Signaturprüfung mit dem je Firma hinterlegten Webhook-Secret
  (`stripe_verify_webhook_signature()`, `stripe-webhook.php:119-121`); antwortet in JEDEM Fall mit HTTP 200
  (`webhook_exit()`), damit Stripe nicht endlos wiederholt.
- Manuelle Aktionen: `require_subscription()`, keine weitergehende Rolle nötig; `support_guard()` sperrt sie im
  Support-Modus des Betreibers (`collections.php:53`, `collections.php:29`).

### Eingabedaten und Validierung
- Firma (`tenant_id`) wird primär aus `metadata.tenant_id` des Stripe-Objekts gelesen; für Charge-Ereignisse
  ohne diese Metadaten wird sie über `stripe_payment_intent_id`/`stripe_charge_id` in `payment_collections`
  nachgeschlagen (`stripe-webhook.php:60-97`). Ohne ermittelbare Firma wird mit `webhook_exit()` abgebrochen
  (kein Fehler-Statuscode, damit Stripe nicht wiederholt).
- `checkout.session.completed` wird nur für `mode = setup` verarbeitet (digitale Mandatserteilung, Abschnitt 3).

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `stripe-webhook.php` | gesamter Endpunkt | 1-299 |
| `app/collections.php` | `sync_collection_statuses()` | 1548 |
| `app/collections.php` | `collection_attempts_open()` | 503 |
| `app/collections.php` | `collection_attempt_begin()` | 541 |
| `app/collections.php` | `collection_attempt_finish()` | 571 |
| `app/collections.php` | `_execute_with_attempt()` | 591 |
| `app/collections.php` | `_attempt_backfill_collection()` | 636 |
| `app/collections.php` | `collection_attempt_recover()` | 709 |
| `app/collections.php` | `collection_attempts_resolve()` | 731 |
| `app/jobs.php` | `job_unclear_attempts()` | 213 |

### Gelesene und veränderte Tabellen
Gelesen: `integrations` (Webhook-Secret), `payment_collections`, `collection_attempts`, `invoices`. Verändert:
`payment_collections.stripe_status`/`completed_at`/`failure_reason`, `invoices.collection_status`,
`collection_attempts.status`/`stripe_payment_intent_id`/`error_text`.

### Externe Schnittstellenaufrufe
- `getPaymentIntent()` (Statusabgleich laufender Einzüge, `sync_collection_statuses()`).
- `listPaymentIntents()` mit `expand[]=data.latest_charge` (Rückschau des Statusabgleichs auf Rücklastschrift und
  Erstattung, `sync_collection_statuses()`, seit 4.73; höchstens 20 Seiten je Aufruf).
- `searchPaymentIntents("metadata['attempt_key']:'<key>'")` (Klärung nach Idempotenzschlüssel,
  `collection_attempts_resolve()`, `app/collections.php:757`).
- `getCharge()` (Ladung der Charge zur Erstattungssumme bei `charge.refund.updated`, da das Refund-Objekt selbst
  keinen verbindlichen Gesamtstand trägt).
- `getSetupIntent()` (nur für die digitale Mandatserteilung, Abschnitt 3).

### Verarbeitungsschritte (nummeriert)
1. Webhook liest Rohkörper und `Stripe-Signature`-Header, lehnt ohne Signatur sofort ab.
2. Firma über Metadaten oder Rückwärtssuche (PaymentIntent-/Charge-ID) ermitteln.
3. Webhook-Secret der Firma entschlüsseln und die Signatur damit prüfen (mandantenspezifisches Secret, nicht ein
   globales).
4. Je nach Ereignistyp:
   - `payment_intent.processing`: `stripe_status = processing`, außer bereits `succeeded`.
   - `payment_intent.succeeded`: `stripe_status = succeeded`, `invoices.collection_status = collected`,
     Charge- und Stripe-Mandatsdaten nachladen (`store_stripe_mandate_data()`, Abschnitt 4).
   - `payment_intent.payment_failed`: `stripe_status = failed`, `invoices.collection_status = failed`,
     Fehlermeldung aus `last_payment_error.message`.
   - `charge.dispute.created`: Rücklastschrift, `stripe_status = disputed`, `invoices.collection_status =
     failed`, Audit `collection_disputed` (Abschnitt 6).
   - `charge.refunded`/`charge.refund.updated`: siehe Abschnitt 6.
5. Findet der Webhook zu einem `payment_intent.*`-Ereignis keinen lokalen Datensatz, aber die Metadaten enthalten
   einen `attempt_key`: es wird geprüft, ob ein zugehöriger, noch nicht abgeschlossener Versuch existiert
   (`pending`/`unknown`/`succeeded` ohne Collection); ist er `unknown` ODER mindestens 120 Sekunden alt, wird der
   Einzug nachgetragen (`collection_attempt_recover()`). Das verhindert, dass ein Webhook, der VOR dem Commit
   der laufenden Einzugstransaktion eintrifft, fälschlich einen zweiten Datensatz erzeugt.
6. `collection_attempts_resolve()` (Klärung, rein lesend bei Stripe): für jeden offenen Versuch (`pending` seit
   ≥ 15 Minuten, `unknown`, oder `succeeded` ohne zugehörige `payment_collections`-Zeile) wird entweder per ID
   (verwaister `succeeded`-Versuch) oder per `searchPaymentIntents` nach dem Idempotenzschlüssel gesucht; Fund →
   nachtragen und `recovered`; kein Fund und Versuch ≥ 10 Minuten alt → `failed` setzen (`cleared`, neuer Versuch
   für diese Rechnung wieder erlaubt); sonst weiter `pending`.

### Statusänderungen
Siehe Schritt 4 oben; zusätzlich `collection_attempts.status`: `pending` → `succeeded`/`failed`/`unknown`.

### Ergebnis und Nebenwirkungen
Lokaler Stand nähert sich dem tatsächlichen Stripe-Stand an, ohne dass eine zweite Zahlung ausgelöst wird
(Klärung ist ausschließlich lesend gegenüber Stripe).

### Fehlerfälle
- Fehlende/ungültige Signatur, unbekannte Firma, fehlende Stripe-Verbindung, fehlendes Webhook-Secret: jeweils
  `webhook_exit()` mit Diagnosetext im Serverlog, HTTP-Antwort bleibt 200.
- Charge/PaymentIntent bei der Klärung nicht abrufbar: Versuch bleibt `pending`, wird beim nächsten Lauf erneut
  geprüft (kein Abbruch der übrigen Versuche).
- `checkout.session.completed` ohne `mode=setup`, ohne `mandate_request_id` oder unbekannte/bereits verarbeitete
  Anforderung: jeweils `webhook_exit()` mit Begründung, kein Fehler-Statuscode.

### Wiederholungs- und Abbruchverhalten
- Kooperativer Abbruchpunkt VOR jedem Stripe-Aufruf innerhalb der Klärung (`worker_stop_requested()`,
  `app/collections.php:743-746`): ein begonnener Versuch (Lesen, dann Nachbuchen) wird nie mitten im Ablauf
  unterbrochen; `stopped=true` löst im Job eine sofortige Fortsetzung aus (`app/jobs.php:230-240`).
- Webhook selbst kennt keine Wiederholungssteuerung durch die Anwendung; Stripe wiederholt bei Nicht-200
  automatisch, hier aber immer 200 (siehe Abschnitt "Schutz vor Doppelverarbeitung" zur Frage der
  Mehrfachzustellung).

### Protokollierung (audit_log-Aktionen)
`collection_attempt_recovered`, `collection_attempt_cleared`, `collection_disputed`, `collection_status_sync`.

### Zugehörige Tests
Kein spezifisches `tools/*`-Skript für den Stripe-Webhook oder die Versuchsklärung im Repository gefunden (nicht
gefunden im Code). Im Auftrag CLAUDE.md genannte Datei `scratchpad/e2e_saas.php` deckt laut
Projektbeschreibung auch Webhook-Szenarien ab, ist aber im vorliegenden Stand nicht vorhanden.

---

## 6. Rückgaben, Rücklastschriften, Erstattungen, Stornierungen

### Zweck
Nachträgliche Geldbewegungen zu einem bereits eingereichten oder bereits erfolgreichen Einzug (Rücklastschrift
durch den Zahlungspflichtigen, Erstattung durch die Firma über Stripe) sowie die Stornierung eines noch nicht
eingereichten Einzugs korrekt in `payment_collections`/`invoices` abbilden, ohne automatisch einen neuen Einzug
auszulösen.

### Auslöser
- Stripe-Webhook `charge.dispute.created` (Rücklastschrift/SEPA-Widerspruch).
- Stripe-Webhook `charge.refunded` bzw. `charge.refund.updated` (Erstattung, auch Teilerstattung oder Rücknahme
  einer Erstattung).
- Storno eines noch nicht eingereichten Einzugs: Aktion `cancel` auf `collections.php:20`
  (`cancel_scheduled_collection()`), auch als Sammelvorgang bei Aktivierung des Not-Stopps
  (`collections_cancel_all_pending()`, aufrufbar von `notstopp.php:29-31`).
- Abschluss der durch eine Erstattung ausgelösten Klärung: Aktion `review_clear` auf `invoices.php:98`
  (`invoice_review_clear()`).

### Voraussetzungen und Berechtigungen
- Webhook-Ereignisse: wie Abschnitt 5 (Signaturprüfung je Firma).
- Storno: `require_subscription()`, keine weitergehende Rolle.
- Klärung abschließen (`review_clear`): nur Inhaber/Administrator (`can_manage_settings()`,
  `invoices.php:99-101`).

### Eingabedaten und Validierung
- Erstattungsbetrag kommt ausschließlich von Stripe (`amount_refunded` der Charge); die Anwendung berechnet ihn
  nicht selbst.
- Storno nur möglich, solange `is_scheduled=1`, `stripe_status='scheduled'`, `scheduled_submitted=0`
  (`cancel_scheduled_collection()`, `app/collections.php:1435-1469`); atomare Bedingung im `UPDATE` verhindert,
  dass ein paralleler Cron-Lauf denselben Einzug zeitgleich einreicht.

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/collections.php` | `collection_apply_refund()` | 814 |
| `app/collections.php` | `invoice_review_clear()` | 870 |
| `app/collections.php` | `cancel_scheduled_collection()` | 1435 |
| `app/collections.php` | `collections_cancel_all_pending()` | 281 |
| `stripe-webhook.php` | Zweige `charge.dispute.created`, `charge.refunded`, `charge.refund.updated` | 157-200, 276-288 |
| `invoices.php` | Aktion `review_clear` | 98 |
| `notstopp.php` | Checkbox "zusätzlich alle vorgemerkten Einzüge stornieren" | 29-31 |

### Gelesene und veränderte Tabellen
Gelesen: `payment_collections`, `invoices`. Verändert: `payment_collections`
(`refunded_cents`, `refunded_at`, `refund_note`, `stripe_status`, `stripe_charge_id`), `invoices`
(`requires_review`, `review_reason`, `collection_status`).

### Externe Schnittstellenaufrufe
`getCharge()` bei `charge.refund.updated` (verbindlicher Gesamtstand der Erstattung wird aus der Charge gelesen,
nicht aus dem Refund-Objekt selbst, da dieses nur eine einzelne Teilerstattung abbildet).

### Verarbeitungsschritte (nummeriert)
1. Rücklastschrift (`charge.dispute.created`): `payment_collections.stripe_status = disputed`,
   `invoices.collection_status = failed`, Audit `collection_disputed`. Kein automatischer erneuter Einzug.
2. Erstattung: Einzug über PaymentIntent- oder Charge-ID zuordnen; ohne Zuordnung `webhook_exit()` ohne
   Datenänderung.
3. `collection_apply_refund()`: unveränderter Erstattungsstand (`refunded_cents` identisch zum gespeicherten
   Wert, z. B. wiederholter Webhook) → keine Änderung, kein Audit-Eintrag, Rückgabe `false`.
4. Vollerstattung (`refunded_cents >= amount_cents`): `stripe_status = refunded`; War die Rechnung `collected`,
   geht `invoices.collection_status` zurück auf `open`, ABER `invoices.requires_review = 1` wird gesetzt: es
   gibt keinen automatischen Neu-Einzug, ein Mensch muss die Klärung ausdrücklich abschließen.
5. Teilerstattung: `stripe_status` bleibt `succeeded`, `refunded_cents` wird gesetzt,
   `invoices.requires_review = 1`.
6. Rücknahme einer Erstattung bei Stripe (`refunded_cents` sinkt auf 0): eigener Hinweistext, ebenfalls
   `requires_review = 1` (Sachverhalt muss geprüft werden).
7. Solange `invoices.requires_review = 1` gesetzt ist, blockiert `_load_and_validate()` jeden neuen Einzugsversuch
   für diese Rechnung (Abschnitt 4).
8. `invoice_review_clear()`: setzt `requires_review = 0`, löscht `review_reason`; nur möglich, wenn tatsächlich
   ein Klärungsbedarf offen war (sonst `CollectionException`). Der ursprüngliche Grund bleibt im Audit-Log
   erhalten.
9. Storno (`cancel_scheduled_collection()`): atomarer Statuswechsel `scheduled → cancelled`; Rechnung zurück auf
   `collection_status = open`. Fehlgeschlagene, bereits eingereichte oder bereits stornierte Einzüge können
   nicht (erneut) storniert werden (jeweils eigene Fehlermeldung).
10. Sammel-Storno beim Aktivieren des Not-Stopps (`collections_cancel_all_pending()`): iteriert alle
    vorgemerkten/terminierten Einzüge der Firma, storniert jeden einzeln atomar (überspringt bereits
    beanspruchte/eingereichte), setzt die zugehörigen Rechnungen zurück auf `open`, ein Audit-Eintrag
    `collections_bulk_cancelled` mit Summe.

### Statusänderungen
`payment_collections.stripe_status`: `succeeded` → `refunded` (Vollerstattung) bzw. bleibt `succeeded` mit
`refunded_cents > 0` (Teilerstattung); `processing`/`succeeded` → `disputed`; `scheduled` → `cancelled`.
`invoices.collection_status`: `collected` → `open` (nur bei Vollerstattung, mit `requires_review=1`);
`scheduled`/`in_collection` → `open` (Storno); → `failed` (Dispute).

### Ergebnis und Nebenwirkungen
Jede Erstattung erzeugt Klärungsbedarf statt einer automatischen Reaktion; die Rechnung verschwindet damit
bewusst nicht kommentarlos aus der Sicht "erledigt", sondern verlangt eine Entscheidung eines Menschen.

### Fehlerfälle
- Erstattung ohne zuordenbaren Einzug: `webhook_exit()`, keine Datenänderung, Diagnose im Log.
- Refund-Charge nicht abrufbar: `webhook_exit()` mit Fehlertext.
- Charge gehört laut `payment_intent`-Feld nicht zum vermuteten Einzug: `webhook_exit()`, keine Änderung
  (Schutz vor Fehlzuordnung).
- `review_clear` ohne offenen Klärungsbedarf oder auf fremde Rechnung: `CollectionException`.

### Wiederholungs- und Abbruchverhalten
Erstattungsverarbeitung ist bereits durch den Vergleich mit dem gespeicherten `refunded_cents` gegen
Mehrfachzustellung des Webhooks abgesichert (Schritt 3 oben); ein separates Idempotenzjournal ist dafür nicht nötig, da Stripe
den kumulierten Stand liefert, nicht ein Einzelereignis.

### Protokollierung (audit_log-Aktionen)
`collection_refunded`, `collection_disputed`, `invoice_review_cleared`, `collection_cancelled`,
`collections_bulk_cancelled`.

### Zugehörige Tests
Kein spezifisches `tools/*`-Skript für Erstattungen/Rücklastschriften im Repository gefunden (nicht gefunden im
Code).

---

## 7. Plattform-Abrechnung getrennt vom Kundeneinzug

### Zweck
Das Abonnement der Firmen für die Nutzung der Anwendung selbst abrechnen (aktuell Tarif UNLIMITED START, laut
`app/plans.php`/Tabelle `plans` konfiguriert), über ein eigenes Stripe-Konto der Müller Holding AG, getrennt vom
Stripe-Konto jeder Firma, über das diese ihre eigenen Kunden per SEPA einzieht (Abschnitt 4).

### Auslöser
- Tarifabschluss ohne laufendes Abonnement: `billing_choose_plan()` + `billing_checkout_url()`
  (`subscription.php`, hier nicht im Detail geladen, aber referenziert über `app/billing.php:108-176`).
- Tarifwechsel mit laufendem Abonnement: `billing_change_plan()` (`app/billing.php:47-102`).
- Kündigung zum Periodenende: `billing_set_cancel_at_period_end()` (`app/billing.php:192-206`).
- Stripe-Webhook `billing-webhook.php` (eigener Endpunkt, eigenes Secret, getrennt von `stripe-webhook.php`):
  `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.payment_failed`.
- Zugriffsprüfung bei jedem Seitenaufruf: `subscription_allows_operation()` (`app/plans.php:362-375`), verwendet
  in `require_subscription()` (Abschnitt 1/4 setzen hierauf auf).

### Voraussetzungen und Berechtigungen
- Nur aktiv, wenn `config('billing')['enabled']` UND ein Plattform-Stripe-Schlüssel gesetzt sind
  (`billing_enabled()`, `app/plans.php:351-355`); sonst hat jede Firma uneingeschränkten Zugriff.
- `organizations.billing_exempt = 1` schaltet eine einzelne Firma dauerhaft von der Abrechnung frei
  (`subscription_allows_operation()`, nur per Superadmin über `admin.php:155-163` setzbar).
- Tarifwechsel nur, wenn mindestens zwei aktive, öffentlich sichtbare Tarife existieren
  (`plan_upsell_available()`, `app/plans.php:222-226`) und der Zieltarif eine Stripe-Preis-ID trägt.
- Bestellbestätigung (AGB, Unternehmereigenschaft) ist Pflicht vor jedem zahlungspflichtigen Abschluss/Wechsel
  (`billing_record_consent()`, `app/billing.php:377-390`).

### Eingabedaten und Validierung
- Tarifcode muss zu einem aktiven (`active=1`) und öffentlich sichtbaren (`public_visible=1`) Eintrag in `plans`
  gehören; ohne hinterlegte `stripe_price_id` ist weder Abschluss noch Wechsel möglich.
- Downgrade unterliegt zusätzlich `plan_change_allowed()` (in `app/plans.php`, hier nicht im Detail geladen,
  aber referenziert in `billing_change_plan()`/`billing_choose_plan()`), das z. B. die Benutzerzahl gegen die
  Obergrenze des Zieltarifs prüft.

### Beteiligte Dateien/Funktionen
| Datei | Funktion | Zeile |
|---|---|---|
| `app/billing.php` | `billing_client()` (eigener Stripe-Client mit Plattformschlüssel) | 22 |
| `app/billing.php` | `billing_change_plan()` | 47 |
| `app/billing.php` | `billing_choose_plan()` | 108 |
| `app/billing.php` | `billing_ensure_customer()` | 129 |
| `app/billing.php` | `billing_checkout_url()` | 145 |
| `app/billing.php` | `billing_portal_url()` | 179 |
| `app/billing.php` | `billing_set_cancel_at_period_end()` | 192 |
| `app/billing.php` | `billing_apply_subscription()` | 209 |
| `app/billing.php` | `billing_event_claim()` | 254 |
| `app/billing.php` | `billing_handle_event()` | 265 |
| `app/billing.php` | `billing_record_consent()` | 377 |
| `app/plans.php` | `billing_enabled()` | 351 |
| `app/plans.php` | `subscription_allows_operation()` | 362 |
| `app/plans.php` | `collections_quota_check()` (Kontingent aus dem Tarif, s. Abschnitt 4) | 175 |
| `app/pricing.php` | `intro_price_deadline()` / `intro_price_deadline_sentence()` (rollierender Stichtag) | 27 / 34 |
| `billing-webhook.php` | Endpunkt (eigenes Secret, eigene Signaturprüfung) | 1-40 |

### Gelesene und veränderte Tabellen
Gelesen: `organizations` (Abo-Felder, `billing_exempt`), `plans`. Verändert: `organizations`
(`subscription_status`, `subscription_period_end`, `cancel_at_period_end`, `platform_stripe_subscription_id`,
`platform_stripe_customer_id`, `plan_code`, `plan_changed_at`), `webhook_events` (Idempotenz, siehe unten).

### Externe Schnittstellenaufrufe
Generischer Stripe-Aufruf `call()` (`app/stripe.php:141-144`) mit dem Plattform-Schlüssel: `/customers`,
`/checkout/sessions` (Modus `subscription`, `automatic_tax` aktivierbar, `tax_id_collection`,
`billing_address_collection: required`), `/billing_portal/sessions`, `/subscriptions/{id}` (GET zum Lesen der
aktuellen Position, POST zum Wechsel/zur Kündigung), `/invoices` (Rechnungsarchiv, nur Lesezugriff).

### Verarbeitungsschritte (nummeriert)
1. Firma ohne laufendes Abonnement wählt einen Tarif (`billing_choose_plan()`): nur `plan_code` wird geändert,
   noch kein Stripe-Vorgang.
2. Checkout-Session (`billing_checkout_url()`): Stripe-Kunde sicherstellen (`billing_ensure_customer()`),
   Session mit Nettopreis, `automatic_tax` (Stripe Tax berechnet die USt anhand der Rechnungsadresse), USt-IdNr.-
   Abfrage (Reverse Charge im EU-Ausland) anlegen; Audit `subscription_checkout`.
3. Nach Zahlungsabschluss meldet Stripe `checkout.session.completed`; `billing_handle_event()` liest das
   Subscription-Objekt nach und ruft `billing_apply_subscription()` auf.
4. Tarifwechsel mit laufendem Abonnement (`billing_change_plan()`): Upgrade (höherer Preis) wird sofort wirksam,
   anteilige Berechnung sofort in Rechnung gestellt und eingezogen (`proration_behavior = always_invoice`,
   `payment_behavior = error_if_incomplete`, scheitert die Zahlung, bleibt der alte Tarif bestehen); Downgrade
   ebenfalls sofort wirksam, aber mit Gutschrift auf die nächste Rechnung (`create_prorations`).
5. `billing_apply_subscription()` bildet Stripe-Status auf lokale Werte ab (`active`/`trialing` → `active`,
   `past_due`/`unpaid` → `past_due`, `canceled` → `canceled`, `incomplete` → `pending`,
   `incomplete_expired` → `canceled`, `paused` → `past_due`); bei erstmaligem `active` wird das Funnel-Ereignis
   `subscription_active` vermerkt.
6. Kündigung (`billing_set_cancel_at_period_end()`): setzt bei Stripe `cancel_at_period_end`, übernimmt den
   aktualisierten Stand, Audit `subscription_cancelled`/`subscription_changed`.
7. `subscription_allows_operation()` entscheidet bei jedem Aufruf von `require_subscription()`: ohne aktive
   Abrechnung oder bei Befreiung immer ja; sonst nur bei Status `active`/`exempt`, oder bei `past_due` bis zum
   Ende der laufenden Periode (Zugriff bleibt trotz Zahlungsverzugs bis dahin erhalten).
8. Preisangabe des Einführungspreises: `intro_price_deadline()` berechnet den letzten Tag des LAUFENDEN
   Kalendermonats zur Laufzeit (kein festes Datum im Code), damit sich der Stichtag von selbst verschiebt;
   Beträge und Perioden selbst stehen ausschließlich in der Tabelle `plans`.

### Statusänderungen
`organizations.subscription_status`: `pending` → `active` → `past_due`/`canceled` (Zuordnung siehe Schritt 5).

### Ergebnis und Nebenwirkungen
Zugriff auf die operativen Funktionen der Firma hängt am Abo-Status, ist aber vollständig getrennt von den
Geldflüssen der Firma selbst (Abschnitt 4-6): ein Zahlungsproblem beim Plattform-Abo blockiert nicht rückwirkend
bereits eingereichte Kundeneinzüge, und umgekehrt hat ein Not-Stopp der Firma keinen Einfluss auf ihr eigenes
Abonnement.

### Fehlerfälle
- Fehlkonfiguration (`billing_enabled()` false trotz Aufruf einer `billing_*`-Funktion): `RuntimeException`
  "Die Plattform-Abrechnung ist nicht konfiguriert." (`billing_client()`, `app/billing.php:32-35`).
- Zieltarif ohne Stripe-Preis-ID: `RuntimeException`, Verweis auf Superadmin-Bereich.
- Downgrade unzulässig (`plan_change_allowed()` liefert `allowed=false`): `RuntimeException` mit Begründung.
- Bestellbestätigung fehlt (AGB oder Unternehmerbestätigung nicht gesetzt): `RuntimeException`, kein Stripe-
  Aufruf.

### Wiederholungs- und Abbruchverhalten
- `billing_event_claim()` verhindert doppelte Verarbeitung über die Tabelle `webhook_events`
  (`INSERT IGNORE`, `source='billing'`; `rowCount()===1` heißt "neu"), siehe Abschnitt "Schutz vor
  Doppelverarbeitung".
- Für `customer.subscription.*`-Ereignisse zusätzlicher Reihenfolgeschutz: ein älteres Ereignis (`event.created`
  kleiner als der zuletzt für dasselbe Stripe-Objekt gespeicherte Wert) wird ignoriert, damit ein verspätet
  zugestelltes altes Ereignis einen neueren Stand nicht überschreibt (`app/billing.php:290-297`).

### Protokollierung (audit_log-Aktionen)
`subscription_plan_changed`, `plan_selected`, `subscription_checkout`, `subscription_changed`,
`subscription_cancelled`, `subscription_consent`.

### Zugehörige Tests
- `tools/billing-setup-check.php`: Prüf- und Anlagelogik der Plattform-Abrechnung (Preisanlage bei Stripe, ohne
  echtes Stripe-Konto, ohne Netz, ohne Datenbank).
- `tools/pricing-check.php`: erzwingt den rollierenden Stichtag des Einführungspreises (kein festes Datum in
  Preisangaben, Übereinstimmung von `app/pricing.php` und den statischen Marketingseiten).

---

## Schutz vor Doppelverarbeitung

Die Anwendung verwendet mehrere, je nach Ablauf unterschiedliche Mechanismen, um zu verhindern, dass derselbe
fachliche Vorgang zweimal ausgeführt wird:

1. **Datenbanksperren mit Inhaberkennung** (`sync_state.lock_until`/`lock_owner`,
   `app/sync_state.php:140-149`): ein zufälliger 16-Byte-Inhaberwert wird atomar in einem `UPDATE ... WHERE
   status='running' AND (lock_until IS NULL OR lock_until < NOW())` reserviert; nur wer `rowCount()===1` erhält,
   darf das Ergebnis dieses Schritts später speichern. Eine gleichzeitige zweite Anfrage bekommt `skipped=true`.
2. **Atomare Statusübergänge mit Bedingung** statt separatem Lock: `_submit_single_scheduled()`
   (`UPDATE payment_collections SET stripe_status='submitting' WHERE ... stripe_status='scheduled' AND
   scheduled_submitted=0`, `app/collections.php:1837-1844`) und `cancel_scheduled_collection()`
   (`app/collections.php:1459-1463`) verwenden dasselbe Muster: gewinnt der `UPDATE` keine Zeile, war ein
   anderer Prozess schneller.
3. **Idempotenzschlüssel je Stripe-Aufruf** (`collection_attempts.idempotency_key`, `UNIQUE KEY
   uq_attempt_key`, `sql/schema.sql:685`): `collection_attempt_begin()` berechnet den Schlüssel aus Firma,
   Rechnung, Betrag und laufender Versuchsnummer (`hash('sha256', ...)`, `app/collections.php:556`) und schreibt
   ihn VOR dem Stripe-Aufruf in einer eigenen, sofort committenden Datenbankverbindung
   (`_attempts_db()`, `app/collections.php:477-491`); bleibt die umgebende Transaktion oder der PHP-Prozess
   danach hängen, bleibt der Versuchseintrag trotzdem erhalten. Derselbe Schlüssel wird als Stripe-
   `Idempotency-Key`-Header mitgegeben (`app/stripe.php:57-62`); Stripe selbst liefert innerhalb von 24 Stunden
   bei gleichem Schlüssel die gespeicherte Antwort statt einer zweiten Belastung.
4. **Blockade offener Versuche je Rechnung**: `collection_attempts_open()`
   (`app/collections.php:503-522`) verhindert einen zweiten Versuch für dieselbe Rechnung, solange ein Versuch
   `pending`, `unknown` ist oder `succeeded`, aber ohne zugehörigen `payment_collections`-Datensatz ("verwaist").
5. **`webhook_events`-Tabelle** (`sql/schema.sql:446-454`): wird ausschließlich von der Plattform-Abrechnung
   verwendet (`billing_event_claim()`, `app/billing.php:254-262`, `INSERT IGNORE` als Idempotenzsperre je
   Stripe-Ereignis-ID). Für den Kundeneinzug (`stripe-webhook.php`) ist KEINE Verwendung von `webhook_events`
   im Code gefunden (nicht gefunden im Code); die Mehrfachverarbeitung wird dort stattdessen über die oben
   genannten atomaren `UPDATE`-Bedingungen auf `payment_collections`/`collection_attempts` sowie über die
   Tatsache verhindert, dass ein bereits verarbeitetes Ereignis (z. B. `succeeded` bei bereits `succeeded`) den
   `UPDATE` wiederholt, aber keinen fachlichen Unterschied erzeugt (siehe Referenzfall 8).
6. **`UNIQUE`-Indizes als letzte Instanz** gegen doppelte fachliche Datensätze: `invoices` (`tenant_id`,
   `lexoffice_invoice_id`), `customers` (`tenant_id`, `lexoffice_contact_id`), `sepa_mandates` (`tenant_id`,
   `mandate_reference`), `jobs.dedupe_key` (verhindert z. B. zwei gleichzeitige `sync_run`- oder
   `collections_due`-Jobs derselben Firma, `app/jobs.php:344/394/404/412`, Schlüssel `sync:<tenant_id>` bzw.
   `collections:due`), `mandate_requests.token_hash`, `collection_attempts.idempotency_key`.
7. **Kooperative Abbruchpunkte statt hartem Abbruch** bei Geldfluss-Jobtypen (`collections_due`,
   `unclear_attempts`, `mail`): `app/worker_signals.php` (hier nur referenziert) sorgt dafür, dass die Notbremse
   (`WORKER_STOP_JOB_SECONDS`) für diese Typen NICHT greift, ein laufender Stripe-Aufruf also nie mitten im
   Ablauf durch ein Deployment unterbrochen wird.

## Wiederholung fehlgeschlagener Zahlungsvorgänge

Die Anwendung unterscheidet ausdrücklich zwei Klassen von Fehlern:

**A. Sicher wiederholbare Schritte** (kein PaymentIntent bei Stripe angelegt, oder das Ergebnis ist zweifelsfrei
bekannt):
- Fachliche Ablehnungen VOR dem Stripe-Aufruf (`CollectionException`, `MandateUnusableException`: Not-Stopp,
  Kontingent, Mandat ungültig, Restbetrag 0 oder unbestätigt abweichend): Stripe wurde hier nie kontaktiert, ein
  erneuter Versuch nach Behebung der Ursache ist ohne Weiteres sicher.
- Stripe hat den Aufruf technisch abgelehnt (`StripeException` ohne `outcomeUnknown`, z. B. HTTP 4xx mit klarer
  Fehlermeldung): der Versuch wird `failed` markiert (`collection_attempt_finish()`), ein neuer Versuch mit
  NEUEM Idempotenzschlüssel (nächste `attempt_no`) ist zulässig, da die Antwort eindeutig "nicht ausgeführt" war.
- `CollectionDeferredException` (Not-Stopp während der Verarbeitung, Restbetrag aktuell nicht abrufbar, Einzug
  bereits von einem parallelen Lauf beansprucht): der terminierte Einzug bleibt unverändert `scheduled` und wird
  beim nächsten reguären Lauf erneut versucht, ohne dass vorher etwas bei Stripe geschehen wäre.

**B. Schritte, die zuerst einen Abgleich mit Stripe erfordern** (ein PaymentIntent könnte angelegt worden sein,
die Antwort kam aber nicht zurück):
- `CollectionUnknownOutcomeException` (`outcomeUnknown = true` in `StripeException`: Verbindungsabbruch,
  Zeitüberschreitung, kein JSON, HTTP-Status 0 oder ≥ 500, `app/stripe.php:90-104`): der Versuch wird als
  `unknown` markiert; **es findet KEIN automatischer neuer Versuch statt**, solange nicht geklärt ist, ob Stripe
  die Lastschrift bereits angelegt hat (`collection_attempt_begin()` blockiert jeden weiteren Versuch für diese
  Rechnung über `collection_attempts_open()`).
- Klärung ausschließlich lesend: `collection_attempts_resolve()` sucht bei Stripe nach einem PaymentIntent mit
  dem gespeicherten Idempotenzschlüssel (`searchPaymentIntents`); erst danach entscheidet sich, ob der Einzug
  nachgetragen wird (gefunden → `collection_attempt_recover()`, kein neuer Stripe-Aufruf nötig, da Stripe selbst
  bei gleichem Idempotency-Key ohnehin keine zweite Belastung ausgeführt hätte) oder ob nach zusätzlicher
  Wartezeit (10 Minuten, damit der Stripe-Suchindex sicher aktuell ist) `failed` gesetzt und damit ein neuer
  Versuch mit neuem Schlüssel erlaubt wird.
- Terminierte Einzüge, die beim Einreichen im Status `submitting` hängen geblieben sind, ohne dass noch ein
  offener Versuch existiert (z. B. Prozessabsturz zwischen Beanspruchung und Stripe-Antwort): werden nach 15
  Minuten automatisch zurück auf `scheduled` gesetzt (`app/collections.php:778-792`) und damit für einen neuen
  reinen Einreichversuch freigegeben; dieser Fall gilt als geklärt (keine offene `collection_attempts`-Zeile
  mehr), nicht als "unknown".
- Grundsatz aus dem Dateikopf `app/collections.php:1-19`: kein automatischer Neu-Einzug nach einer Erstattung
  (Abschnitt 6): auch dort gilt dieselbe Regel, ein möglicherweise bereits ausgeführter Geldfluss
  wird nie blind ein zweites Mal ausgelöst, sondern verlangt zuerst eine Klärung (Abgleich mit Stripe bzw.
  Entscheidung eines Menschen).

---

## Referenzfälle

Acht synthetische Eingaben mit dem laut Code zu erwartenden Ergebnis (keine echten Daten, nur zur
Nachvollziehbarkeit der Regeln):

1. **Rechnung 1.000,00 EUR, Lexware Office meldet Restbetrag 850,00 EUR (Teilzahlung), keine Bestätigung
   mitgeschickt.** `_determine_collection_amount()` ermittelt `amount=85000` Cent, das weicht vom erwarteten
   Betrag `100000` ab; ohne `confirm_amount_cents === 85000` wirft `submit_collection()` eine
   `CollectionException` mit dem Hinweis, den Restbetrag ausdrücklich zu bestätigen. Kein Stripe-Aufruf erfolgt
   (`app/collections.php:453-461`).
2. **Dieselbe Rechnung, jetzt mit `confirm_amount_cents = 85000`.** Der Einzug wird über 850,00 EUR eingereicht
   (bzw. vorgemerkt, falls Karenzzeit/Fenster aktiv sind), `note` vermerkt den abweichenden Restbetrag und den
   Zeitpunkt der Prüfung.
3. **Terminierung eines Sofort-Einzugs um 22:59 Uhr, Einreichfenster 23:00 bis 06:00, `grace_hours=4`.**
   `collections_earliest_submit()` addiert zunächst 4 Stunden (→ 02:59 Uhr des Folgetags), das liegt bereits im
   offenen Fenster (23:00-06:00 über Mitternacht), also wird `submit_not_before` auf 02:59 Uhr desselben
   Kalenderlaufs gesetzt; der Einzug wird als vorgemerkt (`queued_immediate=1`) gespeichert, keine sofortige
   Stripe-Anfrage (`app/collections.php:210-215`).
4. **Terminierung eines Sofort-Einzugs um 23:05 Uhr, gleiche Konfiguration.** Karenzzeit ergibt 03:05 Uhr, das
   liegt ebenfalls im Fenster; Ergebnis wie oben, nur der Zeitpunkt verschiebt sich entsprechend. (Hinweis: Ein
   Fall exakt "um 22:59 Uhr direkt einreichen ohne jede Karenzzeit" setzt `grace_hours=0` UND
   `window_enabled=false` voraus; dann prüft `collections_window_open()` gar nicht erst, da die Funktion bei
   deaktiviertem Fenster immer `true` liefert, s. `app/collections.php:181-183`.)
5. **Mandat eines Kunden ist widerrufen (`sepa_mandates.status='cancelled'`), Sofort-Einzug wird ausgelöst.**
   `get_or_create_mandate()` findet kein Mandat mit Status `draft`/`active` mehr; da zugleich ein früheres
   Mandat mit Status `cancelled` existiert, meldet `mandate_requires_manual_renewal()` `true`, und
   `_submit_collection_locked()` lehnt mit der Meldung "Das bisherige SEPA-Mandat dieses Kunden ist widerrufen
   oder verfallen..." ab, BEVOR ein neues Mandat automatisch angelegt würde (`app/collections.php:1282-1287`).
6. **Zweiter Einzug derselben Rechnung, während der erste noch `in_collection`/`scheduled` ist.**
   `_load_and_validate()` lehnt sofort ab: "Rechnung befindet sich bereits im Einzugsverfahren."
   (`app/collections.php:1050-1052`), unabhängig vom Versuchsjournal.
7. **Webhook `payment_intent.succeeded` wird von Stripe zweimal zugestellt.** Der zweite Aufruf findet den
   Einzug weiterhin über `stripe_payment_intent_id`, setzt `stripe_status='succeeded'` und
   `invoices.collection_status='collected'` erneut (idempotent, keine sichtbare Änderung), lädt
   `store_stripe_mandate_data()` erneut (rein lesend, überschreibt dieselben Werte); kein Doppel-Einzug, keine
   doppelte Buchung, da keine neue Lastschrift ausgelöst wird.
8. **Stripe-Aufruf für einen Sofort-Einzug bricht durch Zeitüberschreitung ab, BEVOR die Antwort ankommt (ein
   PaymentIntent könnte bereits angelegt worden sein).** `_execute_stripe_collection()` wirft eine
   `StripeException` mit `outcomeUnknown=true`; `_execute_with_attempt()` markiert den Versuch als `unknown`
   und `submit_collection()` wirft `CollectionUnknownOutcomeException` mit dem Hinweis auf die Klärung unter
   "Unklare Versuche prüfen". Kein `payment_collections`-Datensatz wird angelegt; ein erneuter Sofort-Einzug für
   dieselbe Rechnung wird durch `collection_attempts_open()` blockiert, bis die Klärung (`collection_attempts_
   resolve()`, Abschnitt 5) den tatsächlichen Ausgang bei Stripe ermittelt hat.
9. **Not-Stopp der Firma wird aktiviert, während `process_scheduled_collections()` gerade läuft und bereits
   drei von zehn fälligen Einzügen eingereicht hat.** Für die verbleibenden sieben wird
   `collections_pause_reason()` je Firma erst innerhalb der Schleife ausgewertet und zwischengespeichert
   (`$pausedTenants`, `app/collections.php:1772-1780`); die verbleibenden Einzüge dieser Firma werden ab dem
   Moment der Auswertung als `skipped_paused` übersprungen und protokolliert, nicht storniert; sie bleiben
   `scheduled` und werden erst nach Aufhebung des Not-Stopps neu bewertet.
10. **Firma mit Tarif, dessen Einzugskontingent 100 Einzüge je Periode beträgt, hat in der laufenden Periode
    bereits 100 nicht stornierte Einzüge.** `collections_quota_check()` liefert `allowed=false`; jeder weitere
    Aufruf von `submit_collection()` (Sofort-, Termin- oder Sammel-Einzug) wird mit der Meldung "Das
    Einzugskontingent Ihres Tarifs (100 je Abrechnungsperiode) ist ausgeschöpft." abgelehnt, ergänzt um einen
    Tarifvorschlag, sofern ein passender öffentlicher, teurerer Tarif existiert (`app/plans.php:198-203`).

## Zusammenfassung: im Code nicht auffindbare Abläufe

- Die im Auftrag genannten Testdateien `scratchpad/e2e_saas.php`, `test_monitor.php`, `test_queue.php`,
  `test_payment_safety.php`, `test_rules_sync.php`, `test_sync_perf.php`, `test_sync_lock.php`,
  `test_migrate_endpoint.php` und `test_healthcheck.php` sind im vorliegenden Stand des Repositories NICHT
  vorhanden (weder unter `php-ionos/`, noch unter `tools/`, noch an anderer Stelle). Sie werden in
  `CLAUDE.md` als Bestandteil eines Testlaufs beschrieben, existieren im geprüften Codebestand aber nicht als
  Datei.
- Für den Stripe-Webhook der Kundeneinzüge (`stripe-webhook.php`) ist keine Verwendung der Tabelle
  `webhook_events` im Code auffindbar; diese Tabelle wird ausschließlich von der Plattform-Abrechnung
  (`app/billing.php`) genutzt. Die Mehrfachverarbeitungssicherheit des Kundeneinzugs beruht stattdessen auf
  atomaren `UPDATE`-Bedingungen und dem Versuchsjournal `collection_attempts` (siehe Abschnitt "Schutz vor
  Doppelverarbeitung").
- `app/plans.php`-Funktionen `plan_change_allowed()` und `seats_limit()` werden von `billing_change_plan()`
  bzw. `plan_limit()` verwendet, ihr genauer Regelinhalt wurde für dieses Kapitel nicht im Detail nachvollzogen
  (außerhalb des angeforderten Umfangs); sie sind nicht als "nicht vorhanden" zu verstehen, sondern lediglich
  nicht Gegenstand dieser vertieften Beschreibung.
- Die Tabelle `collection_rules` (automatische Einzugsregeln) ist laut Kommentar in `sql/schema.sql:757-760`
  bewusst nur ein Gerüst: `is_active` bleibt stets 0, es gibt keine automatische Verarbeitung, nur eine Vorschau
  ohne Einreichung. Ein produktiver Ablauf dazu ist im Code nicht vorhanden.
- `reconcile.php` (schneller, rein lesender Abgleich der Rechnungsnummern zwischen Lexware Office und der
  lokalen Datenbank) wurde nur kurz gesichtet; er verändert keine der in diesem Kapitel beschriebenen Tabellen
  und war nicht Teil des angeforderten Umfangs.
- Die genaue Kündigungs- und Rückerstattungslogik des PLATTFORM-Abonnements bei Downgrade
  (`plan_change_allowed()`) sowie das Kundenportal-Verhalten von Stripe selbst (z. B. genaue Texte im Billing
  Portal) liegen außerhalb der Anwendung bei Stripe und sind im Code nicht nachvollziehbar.
