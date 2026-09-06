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
