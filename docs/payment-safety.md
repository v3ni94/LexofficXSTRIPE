# Zahlungsqualität SmartEinzug (Paket D), Stand 05.09.2026

Gilt für `php-ionos/app/collections.php`, `stripe-webhook.php`, `notstopp.php`, `export.php`, `mandat.php`, `app/mandate_requests.php`, `app/collection_rules.php`. Datenbank: `sql/migrations/006_payment_safety.sql` (wiederholbar, identisch in `sql/schema.sql`).

Grundsatz: Jede Prüfung ist standardmäßig sicher. Im Zweifel wird nicht eingereicht, und der Grund steht als Klartext in der Oberfläche und im Audit-Log.

## 1. Prüfreihenfolge vor jedem Stripe-Aufruf

`_submit_collection_locked()` (Sofort-Einzug und Terminierung) und `_submit_single_scheduled()` (fällige terminierte Einzüge) prüfen in dieser Reihenfolge:

| Nr. | Prüfung | Ergebnis bei Verstoß |
|---|---|---|
| 1 | Not-Stopp plattformweit (`platform_settings.collections_paused`) und je Firma (`organizations.collections_paused`) | `CollectionException`, bei terminierten Einzügen Überspringen mit Protokoll |
| 2 | Vorabankündigungsregel, Kontingent des Tarifs | `CollectionException` |
| 3 | Rechnung offen oder überfällig, nicht im Einzug, Kunde mit SEPA-Einzug, aktive IBAN | `CollectionException` |
| 4 | Mandat aktiv, nicht verfallen (36 Monate), Nachweis erfasst, wenn "Handschriftlicher Nachweis erforderlich" aktiv ist | `MandateUnusableException` |
| 5 | Restbetrag: gespeicherter Wert 0 aus den letzten 24 Stunden blockiert sofort (ohne API-Aufruf) | `CollectionException` |
| 6 | Stripe-Verbindung der Firma vorhanden (nur Schlüsselprüfung, kein Aufruf) | `CollectionException` |
| 7 | Restbetrag live bei Lexware Office (`GET /v1/payments/{voucherId}`), Einzugsbetrag = offener Betrag minus eigene Einzüge dieser Rechnung mit Status `processing`, `succeeded`, `scheduled`, `submitting` | siehe Abschnitt 2 |
| 8 | Versuchsjournal: kein Versuch `pending` oder `unknown` zu dieser Rechnung, Schlüssel persistieren | `CollectionException` |
| 9 | Stripe-Aufruf mit `Idempotency-Key` | siehe Abschnitt 3 |

Die E2E-Suite prüft die Reihenfolge 4 vor 5 vor 6 (`scratchpad/e2e_saas.php`, Abschnitt 14) und die Live-Prüfung 7 vor 8 (`scratchpad/test_payment_safety.php`).

## 2. Restbetrag laut Lexware Office

* Spalten `invoices.open_amount`, `invoices.open_amount_fetched_at`; Anzeige in `invoices.php` ("Rest laut Lexware ... Stand ...") und im CSV-Export.
* `LexofficeClient::getPayment()` normalisiert `openAmount`, `currency`, `paymentStatus`, `voucherStatus`, `paidDate`. Fehlt ein numerischer `openAmount`, wird nicht eingezogen. Die Lexware-Dokumentation konnte beim Bau nicht online geprüft werden (Egress gesperrt); Feldnamen entsprechen dem bekannten Stand, bitte vor Produktivstart mit einem echten Beleg im Testkonto verifizieren.
* Entscheidungslogik (`_determine_collection_amount`):
  * Abruf fehlgeschlagen oder ohne Betrag: keine Einreichung.
  * Offener Betrag 0 oder negativ: Rechnung gilt als bezahlt, keine Einreichung, Hinweis zur Synchronisation.
  * Offener Betrag minus eigene Einzüge kleiner oder gleich 0: keine Einreichung (Doppeleinzug verhindert, auch wenn die Zahlung in Lexware Office noch nicht verbucht ist).
  * Ergebnis größer als der Rechnungsbetrag im Portal: keine Einreichung, zuerst synchronisieren.
  * Ergebnis kleiner als der Rechnungsbetrag (Teilzahlung): nur mit ausdrücklicher Bestätigung über genau diesen Betrag (`confirm_amount_cents`). Die Rechnungsseite bietet dann "Restbetrag einziehen" an. Der Einzug erhält den Vermerk `payment_collections.note` mit Restbetrag, Abrufzeit und Rechnungsbetrag.
* Terminierte Einzüge: Am Fälligkeitstag wird der Restbetrag erneut live geprüft. Nicht abrufbar: Einzug wird zurückgestellt (bleibt terminiert, Vermerk "Zurückgestellt", nächster Lauf prüft erneut). Bezahlt: Einzug wird als fehlgeschlagen mit Grund markiert, kein Stripe-Aufruf. Teilzahlung seit Terminierung: es wird nur der Restbetrag eingezogen (weniger als angekündigt ist zulässig), Vermerk am Einzug.
* Die Synchronisation ruft den Payments-Endpunkt nicht auf (würde die Laufzeit verdoppeln). Der gespeicherte Restbetrag stammt ausschließlich aus Einzugsversuchen.

## 3. Idempotenz und Versuchsjournal

Tabelle `collection_attempts` (eigene Datenbankverbindung mit Autocommit, damit der Eintrag einen Rollback der Einzugs-Transaktion oder einen PHP-Abbruch überlebt).

* Schlüssel: `sha256(tenant_id|invoice_id|amount_cents|Versuchsnummer)`, Versuchsnummer = Anzahl bisheriger Versuche zur Rechnung plus 1. Der Schlüssel geht als HTTP-Header `Idempotency-Key` an Stripe und zusätzlich als Metadatum `attempt_key` in den PaymentIntent. Für den Zweitversuch mit Mandatsreferenz-Präfix wird `<key>-p` verwendet (Stripe verlangt je Parametersatz einen eigenen Schlüssel).
* Zustände: `pending` (Aufruf läuft), `succeeded` (PaymentIntent-ID gespeichert), `failed` (Stripe hat abgelehnt, neuer Versuch mit neuer Nummer erlaubt), `unknown` (Zeitüberschreitung, Netzwerkfehler oder keine JSON-Antwort mit Status 0 oder 5xx: Ergebnis unbekannt).
* Eindeutigkeitsregel: Solange zu einer Rechnung ein Versuch `pending` oder `unknown` ist oder ein Versuch `succeeded` ohne Einzugsdatensatz zu seiner PaymentIntent-ID existiert (verwaist, z. B. Stripe hat angelegt, danach wurde die Einzugs-Transaktion zurückgerollt), wird kein weiterer Versuch angelegt. Die Oberfläche (Dashboard, Einzüge) zeigt die offenen Versuche. Der Versuch trägt die Einzugs-ID bereits beim Anlegen, damit die Klärung einen terminierten Einzug vervollständigt statt einen zweiten Datensatz anzulegen.
* Klärung: Button "Unklare Versuche prüfen" (`collection_attempts_resolve`) sucht per Stripe Search API nach `metadata['attempt_key']`; verwaiste `succeeded`-Versuche werden direkt über ihre PaymentIntent-ID abgerufen. Gefunden: Einzug wird nachgetragen (bestehender terminierter Datensatz wird vervollständigt, sonst neuer Datensatz, Status `processing`), Versuch `succeeded`, Audit `collection_attempt_recovered`. Nicht gefunden und Versuch älter als 10 Minuten (Suchindex): Versuch `failed`, Rechnung wieder einziehbar, Audit `collection_attempt_cleared`. Versuche `pending` unter 15 Minuten bleiben unangetastet. Zusätzlich trägt der Webhook einen PaymentIntent mit `metadata.attempt_key` ohne Einzugsdatensatz selbst nach (`collection_attempt_recover`), sofern der Versuch `unknown` oder älter als zwei Minuten ist (sonst legt die laufende Einzugs-Transaktion den Datensatz an).
* Terminierte Einzüge werden vor dem Aufruf atomar auf `submitting` gesetzt (Schutz gegen parallele Läufe von Cron und Button). Bei unbekanntem Ergebnis bleibt `submitting` stehen (Zähler `unknown` im Lauf, Vermerk am Einzug, kein `failed`), bis die Klärung erfolgt ist; hängende `submitting` ohne PaymentIntent und ohne offenen Versuch werden nach 15 Minuten durch die Klärung auf `scheduled` zurückgesetzt.
* Manuelle Klärung per SQL nur, wenn das Stripe-Dashboard geprüft wurde: `UPDATE collection_attempts SET status = 'failed', error_text = 'manuell geprüft, kein PaymentIntent' WHERE id = '<id>'`.

## 4. Not-Stopp

* Je Firma: `notstopp.php` (Inhaber und Administratoren, CSRF, Bestätigungspflicht beim Aufheben), Spalten `organizations.collections_paused`, `collections_paused_at`, Audit `collections_paused` und `collections_resumed` mit Grund. Hinweis-Box auf Dashboard, Rechnungen und Einzügen; Buttons zum Einreichen sind deaktiviert.
* Plattformweit: `platform_settings` mit Schlüssel `collections_paused` (`0` oder `1`), Lesefunktion `platform_setting()`, Setzen mit `platform_setting_set()` oder direkt per SQL, weil `admin.php` in diesem Paket nicht geändert wurde:
  * Aktivieren: `UPDATE platform_settings SET \`value\` = '1' WHERE \`key\` = 'collections_paused';`
  * Aufheben: `UPDATE platform_settings SET \`value\` = '0' WHERE \`key\` = 'collections_paused';`
* Wirkung: `submit_collection`, `submit_all_ready_collections` (Abbruch mit Meldung, auch wenn der Not-Stopp während des Laufs gesetzt wird), `process_scheduled_collections` (fällige Einzüge werden übersprungen und mit `collections_due_skipped_paused` protokolliert, bleiben terminiert) und `_submit_single_scheduled`. Statusabgleich, Synchronisation und Webhooks laufen weiter. Bereits eingereichte Lastschriften sind nicht betroffen.

## 5. Rückgaben und Statuspflege

* `payment_intent.succeeded` (Webhook) und "Status mit Stripe abgleichen" setzen `succeeded`, die Rechnung auf `collected`, speichern die Charge (`payment_collections.stripe_charge_id`) und die Stripe-Mandatsdaten (`sepa_mandates.stripe_mandate_id`, `stripe_mandate_reference` aus `GET /v1/mandates/{id}`, Feld `payment_method_details.sepa_debit.reference`). Die interne Mandatsreferenz (Portal) und die Stripe-Referenz (Kontoauszug des Kunden) werden in Kundendetails und Einzugsübersicht getrennt angezeigt.
* `payment_intent.payment_failed` setzt `failed` mit Grund; `charge.dispute.created` setzt `disputed`, Rechnung `failed`, Audit `collection_disputed`. Ein Neu-Einzug nach Rücklastschrift ist nur manuell möglich und läuft wieder durch alle Prüfungen (Restbetrag laut Lexware Office bleibt dabei maßgeblich; eine `disputed`-Collection zählt nicht als eigener Einzug).
* Erstattungen aus Stripe (`charge.refunded`) werden noch nicht übernommen (offener Punkt).

## 6. Journal-Export

`export.php` (Login-Pflicht, alle Rollen der Firma, GET-Download): CSV UTF-8 mit BOM, Semikolon, Spalten Rechnungsnummer, Lexware-Rechnungs-ID, Kunde, Kundennummer, Einzugs-ID, Stripe PaymentIntent, Stripe Charge, Betrag EUR (Komma), Status, Eingereicht am, Erfolgreich am, Rücklastschrift am, Mandatsreferenz, Stripe-Mandatsreferenz, Restbetrag laut Lexware, Restbetrag abgerufen am, Ausgelöst von, Termin, Vermerk, Fehlergrund. Formelschutz: Zellen, die mit `=`, `+`, `-`, `@` oder Tabulator beginnen, erhalten ein führendes Apostroph. Jeder Export wird mit `collections_exported` protokolliert. Link in `collections.php` (übernimmt den Statusfilter).

## 7. Digitale Mandatsanforderung (Feature-Schalter, Standard aus)

`config.php`: `'features' => ['mandate_request' => true]`. Ohne Schalter fehlt der Button, `customer.php` lehnt die Aktion serverseitig ab, `mandat.php` liefert 404.

* Kundendetails: "Mandat digital anfordern" erzeugt ein Token (32 Byte, nur SHA-256-Hash in `mandate_requests.token_hash`), 14 Tage gültig, versendet den Link per `mail_send`. Ist der Mailversand nicht eingerichtet, wird der Link einmalig angezeigt. Eine neue Anforderung widerruft die bisherige; Widerruf jederzeit möglich. Audit `mandate_request_sent`, `mandate_request_revoked`.
* `mandat.php`: ohne Anmeldung, ohne Tracking- oder Drittskripte, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, restriktive CSP. GET zeigt nur Zahlungsempfänger, Zahlungspflichtigen und `mandate_texts()`. Erst POST mit CSRF-Token und Bestätigungshäkchen startet die Stripe Checkout Session (`mode=setup`, `payment_method_types=[sepa_debit]`, Kunde aus `findOrCreateCustomer`, Metadaten `tenant_id`, `mandate_request_id`, `customer_id`, `success_url` mit `&done=1`, `cancel_url` ohne). Status `pending`, Audit `mandate_request_started`.
* `stripe-webhook.php` bei `checkout.session.completed` mit `mode=setup`: Firma aus `metadata.tenant_id`, Signaturprüfung mit dem Webhook-Secret der Firma, Anforderung per ID und Session-ID geprüft, SetupIntent abgerufen (`status=succeeded`), dann `mandate_request_grant()`: Status atomar auf `granted` (verhindert Mehrfachverarbeitung), Zahlungsmethode, Stripe-Mandat und Stripe-Referenz gespeichert, lokales Mandat über `get_or_create_mandate` aktiviert (`signed_date` = heute, `signed_place` "digital (Stripe)"), Audit `mandate_granted_digital`.
* Bankverbindung: Stripe liefert nur Land, Bankleitzahl und letzte vier Stellen. Passt eine aktive lokale IBAN (Land und letzte vier Stellen), wird sie weiterverwendet; sonst entsteht eine maskierte Bankverbindung (`customer_ibans.source = stripe_digital`, z. B. `DE****************3000`), bisherige aktive IBANs werden mit Historie deaktiviert. Einzüge nutzen die gespeicherte Zahlungsmethode bei Stripe; `register_iban_with_stripe` und `_execute_stripe_collection` erzeugen für digitale Bankverbindungen keine neue Zahlungsmethode aus der IBAN.
* Erinnerung: `mandate_request_remind()` (höchstens 2, frühestens nach 4 Tagen, Token wird dabei rotiert, alter Link ungültig). Noch nicht an `cron.php` angebunden.
* Freigabekriterien vor Aktivierung: kompletter Durchlauf im Stripe-Testmodus (Testkonto, Webhook-Endpunkt mit `checkout.session.completed`), Prüfung der Mandatstexte durch Rechtsberatung, Datenschutzhinweis auf `mandat.php` ergänzen (Stripe als Auftragsverarbeiter des Kunden).

## 8. Regelautomatik (nur Gerüst)

Tabelle `collection_rules` (`is_active` Standard 0, `customer_scope` all oder selected, `max_amount_cents`, `max_per_run`, `require_second_approval` Standard 1). Einzige Funktion: `collection_rules_preview(tenantId, ruleId)` liefert die Rechnungen, die eine Regel heute einreichen würde (offen, SEPA gewünscht, IBAN, aktives Mandat mit Nachweis, Fälligkeit ab `start_date`, Höchstbetrag, Höchstzahl je Lauf, frischer Restbetrag 0 ausgeschlossen). Es gibt keine Verarbeitung, keinen Cron und keine Oberfläche. Freigabe erst nach: Vier-Augen-Freigabe je Lauf in der Oberfläche, Höchstbetrag je Lauf, Not-Stopp-Prüfung, Live-Restbetrag je Rechnung, Audit je Regel und Lauf, E2E-Test.

## 9. Audit-Aktionen (neu)

`collections_paused`, `collections_resumed`, `collections_due_skipped_paused`, `collection_attempt_recovered`, `collection_attempt_cleared`, `collections_exported`, `mandate_request_sent`, `mandate_request_reminded`, `mandate_request_revoked`, `mandate_request_started`, `mandate_granted_digital`. Labels in `app/audit.php`.

## 10. Offene Punkte

1. Lexware Payments-Endpunkt mit echtem Beleg verifizieren (Feldnamen `openAmount`, `paymentStatus`, `paidDate`; Dokumentation beim Bau nicht erreichbar).
2. `team.php` (gesperrt): Beschriftung der Einstellung `require_signed_mandate` auf "Handschriftlicher Nachweis erforderlich" nachziehen; Link auf `notstopp.php` in der Navigation (`app/layout.php`, gesperrt) ergänzen.
3. `admin.php` (gesperrt): Plattform-Not-Stopp als Schalter statt SQL, Anzeige offener Versuche über alle Firmen.
4. Cron-Anbindung für `mandate_request_remind()` und optional für `collection_attempts_resolve()` je Firma mit Stripe-Verbindung.
5. Erstattungen (`charge.refunded`) aus Stripe übernehmen.
6. Stripe Search API: Verfügbarkeit im Konto prüfen (Suchindex, Ratenbegrenzung); ohne Search bleibt die manuelle Klärung per Stripe-Dashboard.
7. Restbetrag im Rahmen der Synchronisation optional mitführen (ein zusätzlicher API-Aufruf je Rechnung).
