# Lexware-Office-Synchronisation: Analyse und Beschleunigung (Paket 5), Stand 06.09.2026

Grundlage: Code im Repository (php-ionos/app/sync.php, sync_state.php, lexoffice.php, invoices.php, cron.php), Git-Historie, Messläufe mit einer Fake-Quelle ohne Netz (test_sync_perf.php). Die Lexware-Dokumentation war aus der Arbeitsumgebung nicht abrufbar; alle Aussagen zur API sind daher als Annahme aus dem Code gekennzeichnet.

## 1. Nachgewiesene Ursachen (aus dem Code)

| Nr. | Befund | Beleg | Bewertung |
|---|---|---|---|
| 1 | Jeder Lauf lud jede offene und überfällige Rechnung einzeln (GET /invoices/{id}), auch wenn sich nichts geändert hatte. | sync.php, `_sync_process_voucher` (vor Paket 5) | nachgewiesen, Hauptursache |
| 2 | Jeder Kontakt wurde je Lauf erneut geladen (GET /contacts/{id}), nur innerhalb eines Laufs zwischengespeichert. | sync.php, `_sync_upsert_customer` | nachgewiesen |
| 3 | Drosselung 0,6 s je Aufruf (unter 2 Aufrufe je Sekunde), dazu Netzlatenz. Ein Schritt mit fester Größe 6 kostete 6 bis 12 Aufrufe, also 4 bis 10 s. | lexoffice.php `MIN_REQUEST_INTERVAL_US`, sync.php `batchSize = 6` | nachgewiesen |
| 4 | Feste Schrittgröße 6 statt Zeitbudget: viele kurze HTTP-Umläufe (Browser-Redirect je Schritt, Cron mit 20 s Budget schaffte 1 bis 2 Schritte je Lauf, also 6 bis 12 Rechnungen je 5 Minuten). | sync_state.php, cron.php | nachgewiesen |
| 5 | Der Cursor in `sync_state.cursor_json` enthielt vollständige Kontaktobjekte (contact_cache) und wurde je Schritt gelesen und geschrieben. | sync.php `contact_cache` | nachgewiesen, Nebenwirkung |
| 6 | Der ursprüngliche Absturz war die Zeitgrenze des Hostings für einen einzelnen HTTP-Aufruf ("Page temporarily unavailable") bei einem Komplettlauf mit gedrosselten Aufrufen. | Commit 8e03863 vom 31.08.2026 | nachgewiesen (Commit-Text) |
| 7 | Rate-Limit-Behandlung vorhanden: 429 mit Backoff 2, 4, 8 s (3 Versuche), 5xx mit Backoff (2 Versuche), Ausweichdomain bei Verbindungsfehler. Kein Retry-After-Header ausgewertet. | lexoffice.php `request()` | nachgewiesen |
| 8 | Datenbank: eindeutige Schlüssel (tenant_id, lexoffice_invoice_id) und (tenant_id, lexoffice_contact_id) vorhanden, keine Transaktion über HTTP-Wartezeiten, je Rechnung 2 bis 3 Abfragen. Nicht der Engpass. | schema.sql, sync.php | nachgewiesen |

Vermutet, nicht belegt: Rate-Limit-Scope je API-Key, maximale Seitengröße der Voucherliste (Code nutzt 100), Feldumfang der Voucherliste (der Code verwendet `updatedDate` nur, wenn es in der Antwort vorhanden ist).

## 2. Änderungen

1. **Unveränderte Rechnungen ohne Detailabruf** (`sync.skip_unchanged`, Standard an): Die Voucherliste liefert je Eintrag `updatedDate`; der Wert wird in `invoices.lexoffice_updated_at` gespeichert. Stimmen `updatedDate` und Status mit dem gespeicherten Stand überein, wird nur `last_synced_at` gesetzt. Fehlt `updatedDate` in der Antwort, verhält sich der Lauf wie bisher (jede Rechnung einzeln), es wird nichts stillschweigend übersprungen.
2. **Kontakte höchstens alle 24 Stunden** (`sync.contact_refresh_hours`): bekannte Kunden mit frischem `customers.lexoffice_synced_at` werden ohne Aufruf wiederverwendet; neue Kunden und abgelaufene Fristen werden geladen. Der Lauf-Cache enthält nur noch Name, Kundennummer und E-Mail statt des vollständigen Kontakts.
3. **Zeitbasierte Schritte** (`sync.step_seconds` 8, `sync.step_max` 40): ein Browser- oder Cron-Aufruf arbeitet, bis das Zeitbudget erschöpft ist. Übersprungene Rechnungen zählen nicht gegen `step_max`. Listenseiten werden im selben Schritt nacheinander geholt. Fortschritt wird weiterhin nach jedem Schritt gespeichert, die Sperre (`lock_until`) und der Wiederanlauf sind unverändert.
4. **Messwerte je Lauf**: Schritte, Lexware-Aufrufe, Antwortzeit, Drosselwartezeit, Wiederholungen, Detail- und Kontaktabrufe, übersprungene Rechnungen, wiederverwendete Kontakte, Dauer. Gespeichert in `sync_state.result_json`, im Audit-Eintrag `sync_completed` und in der Abschlussmeldung auf der Rechnungsseite. Keine Inhalte, keine Schlüssel.
5. Unverändert: Recheck lokal offener Rechnungen, die nicht mehr in der Liste stehen (ein Detailabruf je Rechnung, nötig, um bezahlt oder storniert zu erkennen), Sperren, Cron-Round-Robin, Zahlungslogik (Restbetrag wird vor jedem Einzug live geprüft, unabhängig vom Sync).

## 3. Messwerte (Fake-Quelle, 200 Rechnungen, 40 Kunden, ohne Netz)

| Lauf | Vorher | Nachher |
|---|---|---|
| Erstimport | 243 Aufrufe, 36 Schritte | 243 Aufrufe, 7 Schritte |
| Folgelauf ohne Änderungen | 243 Aufrufe, 36 Schritte | 3 Aufrufe, 3 Schritte |
| Folgelauf, 1 Rechnung geändert, 1 Statuswechsel | 243 Aufrufe | 5 Aufrufe |

Hochgerechnet mit der Drosselung von 0,6 s je Aufruf (ohne Netzlatenz) bedeutet der Folgelauf rund 2 s statt rund 146 s reine Wartezeit. Der Erstimport bleibt durch den nötigen Detailabruf je Rechnung begrenzt (etwa 0,6 bis 1,2 s je Rechnung), läuft aber in deutlich weniger Schritten und damit mit weniger Overhead je Browser-Umlauf und je Cron-Aufruf.

Live-Messung: nach dem Ausrollen liefert jede abgeschlossene Synchronisation die Werte in der Abschlussmeldung und im Protokoll (Firma > Protokoll, Eintrag "Synchronisation abgeschlossen"). Diese Werte bitte für den ersten Erstimport und die ersten Folgeläufe notieren.

## 4. Konfiguration und Migration

- Migration 013 (`sql/migrations/013_sync_performance.sql`): `invoices.lexoffice_updated_at`, `customers.lexoffice_synced_at`. Wird automatisch vom Cron oder über `migrate.php` eingespielt.
- `app/config.php`, Block `sync` (optional, Standardwerte greifen ohne Eintrag): `step_seconds` 8, `step_max` 40, `skip_unchanged` true, `contact_refresh_hours` 24.
- Rückrollen ohne Codeänderung: `skip_unchanged` auf false und `contact_refresh_hours` auf 0 setzen, dann verhält sich der Lauf wie vor Paket 5 (nur mit zeitbasierten Schritten). `step_max` auf 6 stellt zusätzlich die alte Schrittgröße her.

## 5. Grenzen und offene Punkte

- Die Änderungserkennung hängt am Feld `updatedDate` der Voucherliste. Das Feld gehört nach unserem Stand zur Antwort der Lexware Public API, konnte aber aus der Arbeitsumgebung nicht gegen die Dokumentation verifiziert werden. Fehlt es, greift der alte Pfad. Ob Zahlungseingänge an einer Rechnung `updatedDate` verändern, entscheidet Lexware; für den Einzug ist das unerheblich, weil der Restbetrag vor jeder Einreichung live über den Payments-Endpunkt geprüft wird.
- Ein Erstimport mit 500 Rechnungen braucht weiterhin etwa 500 Detailabrufe (5 bis 10 Minuten reine Aufrufzeit bei offenem Browser; nur über den Cron mit 20 s je 5 Minuten deutlich länger). Wer den Erstimport beschleunigen will, lässt die Rechnungsseite geöffnet oder verkürzt das Cron-Intervall.
- Gleichzeitige Synchronisationen mehrerer Firmen: jede Firma nutzt ihren eigenen API-Schlüssel und einen eigenen PHP-Prozess (Browser) beziehungsweise den fairen Round-Robin im Cron. Datenbankverbindungen: eine je laufender Anfrage, kurzlebig.
- Webhooks von Lexware Office für Rechnungsänderungen wären der nächste Schritt, um den Folgelauf ganz zu vermeiden; das setzt eine geprüfte Dokumentation der Ereignisse und ihrer Verifikation voraus und ist hier nicht umgesetzt.

## Nachtrag 06.09.2026: Sperre, Budgets, Wiederholungen (Auftrag II, Abschnitt 1)

- **Sperre mit Inhaber** (`sync_state.lock_owner`, Migration 014): Jeder Schritt holt die Sperre atomar (`UPDATE ... WHERE lock_until IS NULL OR lock_until < NOW()`) und vermerkt eine zufällige Kennung. Cursor und Ergebnis werden nur gespeichert, wenn die Kennung noch stimmt. Übernimmt ein anderer Prozess nach Ablauf der Sperrfrist (180 s), verwirft der alte Prozess seinen Schritt (`sync_lock_lost` im Protokoll); seine Datenänderungen sind idempotente Upserts und damit unschädlich. Die Sperre gilt je Firma, Firmen blockieren sich nicht gegenseitig.
- **Doppelstart**: `sync_state_start` legt bei laufendem Sync keinen zweiten an, zählt `skipped_starts` und protokolliert `sync_start_skipped`; die Oberfläche meldet "Die Synchronisierung läuft bereits. Ein weiterer Start ist nicht erforderlich."
- **Budget je Schritt**: `sync.step_seconds` (8 s), `sync.step_max` (40 Detailabrufe), neu `sync.step_max_api_calls` (60 Lexware-Aufrufe). Fortschritt wird erst nach erfolgreichem Speichern des Schritts weitergesetzt. Ein wegen Budget beendeter Schritt lässt den Lauf im Zustand "Teilweise verarbeitet, wartet auf den nächsten Schritt".
- **Zustände** (`sync_state_label`): Wartet, Wird synchronisiert, Teilweise verarbeitet, Abgeschlossen, Fehler, Abgebrochen (kein Fortschritt seit 30 Minuten).
- **Wiederholungen** im Lexware-Client: bei 429 und 5xx höchstens drei bzw. zwei Versuche, Wartezeit aus `Retry-After` (1 bis 30 s) oder 2, 4, 8 s, jeweils plus Zufallsanteil bis 500 ms. Keine Wiederholung bei 401 und Validierungsfehlern. Die Drosselung (0,6 s Abstand, unter 2 Anfragen je Sekunde) beruht auf der früheren Lexware-Dokumentation und konnte am 06.09.2026 nicht online verifiziert werden (developers.lexware.io aus der Arbeitsumgebung nicht erreichbar); bitte gegen die aktuelle Dokumentation prüfen.
- **Stripe**: Idempotenzschlüssel und Versuchsjournal (`collection_attempts`, docs/payment-safety.md) unverändert; ein Datenabgleich löst keine Zahlung aus.
- Tests: `scratchpad/test_sync_lock.php` (Doppelstart, Budget, verlorene Sperre, Fortsetzung) und `test_sync_perf.php`.

## Nachtrag 07.09.2026: Performance-Überarbeitung, Phase 1 (Version 4.39)

Grundlage ist die Bestandsaufnahme vom 07.09.2026 (Engpassliste mit 14 Punkten, Zielbild 100 bis 500 aktive Firmen). Phase 1
setzt die Maßnahmen um, die ohne Verifikation der Lexware-Dokumentation sicher sind: messen, entzerren, fair verteilen und im
Adminbereich sichtbar machen. Verhalten, das von unbestätigten API-Eigenschaften abhängt, bleibt bewusst unverändert.

### Umgesetzt

| Nr. | Maßnahme | Umsetzung | Wirkung |
|---|---|---|---|
| 1 | Baseline-Messpunkte (Lücken 1, 2, 6 der Bestandsaufnahme) | Migration 029: `job_runs.queue_wait_ms` (Fälligkeit bis Reservierung, gesetzt in `job_execute()`), `sync_runs.detail_calls`, `contact_calls`, `api_ms_max` (längster Einzelaufruf, `LexofficeClient::$requestMsMax`, ebenso `SevdeskClient`), `cursor_bytes_max` (größter Cursor, gemessen in `sync_state_step()` beim Speichern) | erstmals Wartezeit in der Warteschlange, Einzelabrufe je Lauf und Cursorgröße dauerhaft messbar |
| 2 | Vollabgleich entzerren (Engpass 6) | `queue.full_sync_window_hours` (Vorgabe 4): `scheduler_full_sync_hour()` verteilt Firmen über ein Fenster ab `full_sync_hour` mit stabilem Versatz (`crc32` der Firmenkennung); Fenster 1 = altes Verhalten; Umbruch über Mitternacht berücksichtigt | keine gemeinsame Nachtstunde für alle Firmen, jede Firma behält ihre Stunde (im Adminbereich angezeigt) |
| 3 | Fairness zwischen Firmen (Engpass 8) | `queue.sync_fair_seconds` (Vorgabe 120, 0 = aus): läuft ein Sync-Job länger und warten fällige Sync-Jobs ANDERER Firmen (`queue_waiting_count(QUEUE_SYNC_TYPES, Firma)`), gibt er den Worker per `JobRequeueException` ab (Fortsetzung ohne Fehlversuch, Cursor bleibt) | eine Firma mit großem Bestand kann einen Worker nicht mehr bis zu 10 Minuten am Stück belegen, solange andere warten |
| 4 | Seitengröße konfigurierbar (Engpass 5) | `sync.page_size` (Vorgabe 100, Obergrenze 250 im Code, `LexofficeClient::pageSize()`) | Erhöhung möglich, sobald das Maximum der Lexware-API am Primärtext bestätigt ist; bis dahin bleibt 100 |
| 5 | Adminbereich System, Reiter „Synchronisation & Performance“ | `app/sync_perf.php`: Läufe, Dauer, Aufrufe, Detail- und Kontaktabrufe, übersprungene Rechnungen, Antwortzeit je Aufruf, längster Aufruf, Drosselung, Wiederholungen, Wartezeit in der Warteschlange, Cursorgröße (24 Stunden und 7 Tage); Worker je Pool; Firmen mit dem größten Aufwand; wirksame Konfiguration mit Quelle; Verteilung des Vollabgleichs; Circuit Breaker | Betreiber sieht Engpässe ohne Serverzugriff; Grundlage für die Bemessung weiterer Worker |

Prüfungen: `bash tools/scheduler-sync-check.sh` (Fälle 10a bis 10c: Verteilung, Fairness, Reiter) und `bash tools/sevdesk-check.sh`.
Seit 4.43 zeigt der Reiter den gewählten Zeitraum und den gleich langen Vorzeitraum statt fester 24 Stunden und 7 Tage
(`app/admin_period.php`, Auswahlleiste mit Voreinstellungen und freiem Bereich).

### Bewusst nicht umgesetzt (offene Prüffragen, Stand 07.09.2026)

- **Lexware-Webhooks (Event Subscriptions):** Ereigniskatalog, Nutzdatenformat, Signaturkopf und Verfahren (RSA-SHA512 gegenüber
  HMAC-SHA256 in einer Fundstelle) sind nicht am Primärtext verifiziert; eine Signaturprüfung auf Annahmen wäre ein
  Sicherheitsrisiko. Vorbereitung: Skizze in `scratchpad/recherche/lexware-limits.md` (nicht im Repository), Umsetzung erst
  nach Prüfung der Dokumentation mit ungehindertem Netzzugang.
- **Sammelabrufe für Rechnungsdetails:** kein belegter Endpunkt. Der Detailabruf je Rechnung (Engpass 1) bleibt der größte
  Kostenblock beim Erstimport.
- **Seitengröße über 100:** Sekundärquellen nennen 100 oder 250 als Maximum (Widerspruch). Vorgabe bleibt 100.
- **Rate-Limit-Wert:** 2 Anfragen je Sekunde und Schlüssel ist weiterhin eine Annahme; der Client hält 0,6 s Mindestabstand
  plus `api_call_gate()` (2/s je Firma, 50/s gesamt).
- **Worker-Anzahl:** zwei Lexware-Worker sind keine bemessene Größe. Bemessung erst nach Auswertung der neuen Kennzahlen
  (Wartezeit in der Warteschlange, Auslastung je Pool) über mindestens eine Woche Produktionsbetrieb; Skalierung über
  zusätzliche Dienste in `deploy/vps/docker-compose.yml` unter Beachtung der globalen Drosselung.
- **Cursor-Auslagerung, N+1-Abfragen, Transaktionen während HTTP** (Engpässe 10, 11, 14): erst nach Messung; die neue
  Cursorgröße zeigt, ob eine Auslagerung nötig ist.

### Konfiguration

`shared/config.php`, Block `queue`: `full_sync_window_hours` (1 bis 12, Vorgabe 4), `sync_fair_seconds` (0 = aus, Vorgabe 120);
Block `sync`: `page_size` (1 bis 250, Vorgabe 100). Änderungen ohne Deployment erreichen Scheduler und Worker erst nach
`deploy/vps/scripts/restart-workers.sh`. Rückrollen ohne Codeänderung: `full_sync_window_hours = 1`, `sync_fair_seconds = 0`.

## Nachtrag 09.09.2026 (Version 4.52): Der nächtliche Vollabgleich lief ins Leere

**Befund der Gesamtprüfung.** `sync_state_start()` setzt `cursor_json = NULL`. Unmittelbar danach markiert
`job_sync_run()` einen Vollabgleich mit `JSON_SET(COALESCE(cursor_json, '{}'), '$.force_full', true)`. Der Cursor ist
damit nicht mehr leer, sondern enthält genau einen Schlüssel. `sync_invoices_step()` legte seine Grundstruktur aber
nur im Zweig `if ($cursor === null)` an. Beim Vollabgleich lief der Schritt deshalb ohne `phase`, `listing_status`,
`lex_page`, `collected`, `proc_index`, `result` und `recheck_ids` und endete in einem Typfehler. Betroffen war jede
Firma zu ihrer individuellen Vollabgleichsstunde; die Synchronisation blieb danach stehen.

**Behebung.** Die Vorgabewerte werden jetzt immer ergänzt: `$cursor = is_array($cursor) ? $cursor + $vorgabe :
$vorgabe;`. Der Additionsoperator behält vorhandene Schlüssel (also `force_full` und jeden Fortschritt einer
Fortsetzung) und füllt nur fehlende auf; `array_merge` wäre falsch, weil es numerische Schlüssel neu vergibt.
Geprüft von `php tools/payment-safety-check.php`, Abschnitt D.
