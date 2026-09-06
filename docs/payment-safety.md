# Zahlungsqualität SmartEinzug (Paket D), Stand 05.09.2026

Gilt für `php-ionos/app/collections.php`, `stripe-webhook.php`, `notstopp.php`, `export.php`, `mandat.php`, `app/mandate_requests.php`, `app/collection_rules.php`, `app/alerts.php`, `app/auth.php` (Zweitbestätigung). Datenbank: `sql/migrations/006_payment_safety.sql` und `sql/migrations/007_refunds_alerts.sql` (beide wiederholbar, identisch in `sql/schema.sql`).

Grundsatz: Jede Prüfung ist standardmäßig sicher. Im Zweifel wird nicht eingereicht, und der Grund steht als Klartext in der Oberfläche und im Audit-Log.

## 1. Prüfreihenfolge vor jedem Stripe-Aufruf

`_submit_collection_locked()` (Sofort-Einzug und Terminierung) und `_submit_single_scheduled()` (fällige terminierte Einzüge) prüfen in dieser Reihenfolge:

| Nr. | Prüfung | Ergebnis bei Verstoß |
|---|---|---|
| 1 | Not-Stopp plattformweit (`platform_settings.collections_paused`) und je Firma (`organizations.collections_paused`) | `CollectionException`, bei terminierten Einzügen Überspringen mit Protokoll |
| 2 | Vorabankündigungsregel, Kontingent des Tarifs | `CollectionException` |
| 3 | Rechnung offen oder überfällig, nicht im Einzug, kein Klärungsbedarf (`invoices.requires_review = 0`, siehe Abschnitt 5a), Kunde mit SEPA-Einzug, aktive IBAN | `CollectionException` |
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

* Je Firma: `notstopp.php` (Inhaber und Administratoren, CSRF, Bestätigungspflicht und Zweitbestätigung per 2FA-Code beim Aufheben, Abschnitt 5c), Spalten `organizations.collections_paused`, `collections_paused_at`, Audit `collections_paused` und `collections_resumed` mit Grund. Hinweis-Box auf Dashboard, Rechnungen und Einzügen; Buttons zum Einreichen sind deaktiviert.
* Plattformweit: `platform_settings` mit Schlüssel `collections_paused` (`0` oder `1`), Lesefunktion `platform_setting()`, Schalter in `admin.php` (Zweitbestätigung per 2FA-Code) oder Setzen mit `platform_setting_set()` bzw. direkt per SQL:
  * Aktivieren: `UPDATE platform_settings SET \`value\` = '1' WHERE \`key\` = 'collections_paused';`
  * Aufheben: `UPDATE platform_settings SET \`value\` = '0' WHERE \`key\` = 'collections_paused';`
* Wirkung: `submit_collection`, `submit_all_ready_collections` (Abbruch mit Meldung, auch wenn der Not-Stopp während des Laufs gesetzt wird), `process_scheduled_collections` (fällige Einzüge werden übersprungen und mit `collections_due_skipped_paused` protokolliert, bleiben terminiert) und `_submit_single_scheduled`. Statusabgleich, Synchronisation und Webhooks laufen weiter. Bereits eingereichte Lastschriften sind nicht betroffen.

## 5. Rückgaben und Statuspflege

* `payment_intent.succeeded` (Webhook) und "Status mit Stripe abgleichen" setzen `succeeded`, die Rechnung auf `collected`, speichern die Charge (`payment_collections.stripe_charge_id`) und die Stripe-Mandatsdaten (`sepa_mandates.stripe_mandate_id`, `stripe_mandate_reference` aus `GET /v1/mandates/{id}`, Feld `payment_method_details.sepa_debit.reference`). Die interne Mandatsreferenz (Portal) und die Stripe-Referenz (Kontoauszug des Kunden) werden in Kundendetails und Einzugsübersicht getrennt angezeigt.
* `payment_intent.payment_failed` setzt `failed` mit Grund; `charge.dispute.created` setzt `disputed`, Rechnung `failed`, Audit `collection_disputed`. Ein Neu-Einzug nach Rücklastschrift ist nur manuell möglich und läuft wieder durch alle Prüfungen (Restbetrag laut Lexware Office bleibt dabei maßgeblich; eine `disputed`-Collection zählt nicht als eigener Einzug).
* Erstattungen aus Stripe werden übernommen, siehe Abschnitt 5a.

## 5a. Erstattungen

Ereignisse `charge.refunded` (Objekt Charge, Feld `amount_refunded` = Gesamtstand) und `charge.refund.updated` (Objekt Refund; der Gesamtstand wird per `GET /v1/charges/{id}` nachgelesen, reiner Lesezugriff). Zuordnung zur Firma und zum Einzug über `payment_intent` bzw. `charge` gegen `payment_collections.stripe_payment_intent_id` und `stripe_charge_id`, Signaturprüfung mit dem Webhook-Secret der Firma wie bei allen anderen Ereignissen. Verarbeitung in `collection_apply_refund()`:

| Fall | Einzug (`payment_collections`) | Rechnung (`invoices`) |
|---|---|---|
| Vollerstattung (`amount_refunded` >= `amount_cents`) | `stripe_status = 'refunded'`, `refunded_cents`, `refunded_at`, `refund_note` | `collection_status` von `collected` zurück auf `open`, NUR mit Vermerk: `requires_review = 1`, `review_reason` mit Betrag, Zeitpunkt und dem Hinweis "kein automatischer Neu-Einzug" |
| Teilerstattung | `refunded_cents` gesetzt, `stripe_status` bleibt `succeeded` | `requires_review = 1`, `review_reason` |
| Gleicher Stand erneut gemeldet | keine Änderung, kein Audit (Webhook-Wiederholungen) | keine Änderung |
| Erstattung bei Stripe zurückgenommen (`amount_refunded` sinkt) | Stand nachgezogen, `refunded` wird wieder `succeeded` | `requires_review = 1` |

* Es gibt keinen automatischen Neu-Einzug. `_load_and_validate()` lehnt Rechnungen mit `requires_review = 1` ab ("zur Klärung markiert"); Sammel-Einzug (`submit_all_ready_collections`, `count_ready_for_collection`) und Regelvorschau schließen sie aus.
* Klärung abschließen: Button "Klärung abgeschlossen" in `invoices.php` (nur Inhaber und Administratoren, CSRF), `invoice_review_clear()`, Audit `invoice_review_cleared` mit dem bisherigen Grund. Danach ist die Rechnung wieder manuell einziehbar und läuft durch alle Prüfungen (Restbetrag laut Lexware Office bleibt maßgeblich).
* Ein vollständig erstatteter Einzug (`refunded`) zählt nicht mehr als eigener Einzug in `invoice_own_collections_cents()`; ein teilweise erstatteter Einzug zählt weiterhin mit dem vollen Betrag (konservativ, führt eher zu einer Blockade als zu einem Doppeleinzug).
* Anzeige: Einzugsübersicht (Spalte Betrag "Erstattet: ... am ...", Filter `refunded`, Vermerk), CSV-Export mit Spalten "Erstattet EUR" und "Erstattet am". Audit `collection_refunded` mit Einzugsbetrag, Erstattungsbetrag, Charge und PaymentIntent.
* Voraussetzung: Der Webhook-Endpunkt der Firma bei Stripe muss die Ereignisse `charge.refunded` und `charge.refund.updated` liefern (Einstellungen prüfen). Ohne Webhook-Secret werden Erstattungen nicht erkannt; die Alarmierung weist darauf hin.

## 5b. Alarmierung

`app/alerts.php`, reine Leseprüfungen, keine Geheimnisse in der Ausgabe.

* `alerts_for_tenant(tenantId)` liefert `[{level, text, link}]`: letzte Synchronisation älter als 48 Stunden oder nie erfolgt bei verbundenem Lexware Office (hoch), unklare Einzugsversuche (hoch), Not-Stopp aktiv für Firma oder Plattform (mittel), Stripe verbunden ohne Webhook-Secret (mittel), terminierte Einzüge mit Fälligkeit in der Vergangenheit und Status `scheduled` (mittel), Rechnungen mit `requires_review` (mittel). Anzeige als Box oben im Dashboard (`flash flash-warn`), Stufe "hoch" mit "Wichtig:".
* `alerts_platform()` für `admin.php`: Firmen mit Synchronisation älter 48 Stunden, offene unklare Versuche gesamt (`pending`, `unknown`), Plattform-Not-Stopp. Webhook-Ereignisse mit Fehlern werden nur ausgewertet, wenn `webhook_events` eine Fehlerspalte (`error`, `error_text` oder `last_error`) hat; derzeit gibt es keine, der Punkt entfällt.
* Cron (`alerts_cron_notify()` in `cron.php`): je Firma höchstens einmal je Kalendertag eine E-Mail an den aktiven Inhaber, wenn Alarme der Stufe "hoch" vorliegen. Merker `platform_settings.alerts_sent_<tenant> = JJJJ-MM-TT`; wird nur nach erfolgreichem Versand gesetzt. Ohne aktiven Mailversand (`mail.enabled = false`) wird nichts versendet und kein Merker gesetzt. Text über `mail_layout`, Button "Zum Dashboard", Audit `alerts_mailed`.

## 5c. Zweitbestätigung kritischer Aktionen

`require_recent_totp(array $ctx, string $code)` in `app/auth.php` prüft den aktuellen TOTP-Code des angemeldeten Nutzers wie bei der Anmeldung (`twofa_verify_user`, Fenster plus/minus ein Zeitschritt, Replay-Schutz über `users.totp_last_step`: jeder Code gilt nur einmal, auch nach einer Anmeldung mit demselben Code). Recovery-Codes werden nicht akzeptiert. Bei Fehler `RuntimeException` mit Klartext, fehlgeschlagene Versuche werden mit `twofa_reauth_failed` protokolliert. Pflicht bei:

| Seite | Aktion | Formularfeld |
|---|---|---|
| `admin.php` | `platform_pause` (Plattform-Not-Stopp setzen und aufheben) | "Aktueller 2FA-Code" |
| `admin.php` | `org_plan` (Tarif einer Firma ändern) | "Aktueller 2FA-Code" je Zeile |
| `notstopp.php` | `resume` (Not-Stopp der Firma aufheben; zusätzlich Bestätigungshäkchen) | "Aktueller 2FA-Code" |
| `team.php` | `transfer_ownership` (zusätzlich Passwort) | "Aktueller 2FA-Code" |

Die Aktivierung des Not-Stopps je Firma bleibt bewusst ohne Zweitbestätigung, damit im Notfall keine Hürde besteht.

## 5d. Bestandsrechnungen (Befund `app/sync.php`)

Der Rechnungsimport hat weder einen Datumsfilter noch ein Limit: `sync_invoices_step()` und `sync_invoices()` holen über `getInvoiceVouchersPage()` alle Seiten der Voucherliste (`voucherType=invoice`, `voucherStatus=open` und `overdue`, `size=100`, Schleife bis `totalPages`) und verarbeiten jede Rechnung einzeln (`_sync_process_voucher`). Rechnungen aus der Zeit vor der Registrierung werden daher vollständig importiert, sofern sie in Lexware Office noch offen oder überfällig sind. Kein Code geändert (vollständige Paginierung vorhanden).

Bewertung: Ein Import allein löst keinen Einzug aus. Jede Einreichung erfolgt manuell (Einzelrechnung, Sammel-Einzug oder Terminierung) und durchläuft die Prüfreihenfolge aus Abschnitt 1, insbesondere den Live-Restbetrag bei Lexware Office; die Regelautomatik ist nur ein Gerüst ohne Verarbeitung. Empfehlung für den Sammel-Einzug bei großem Altbestand: Kunden ohne aktuelles Mandat vorab per "SEPA: Nein" ausnehmen oder Altrechnungen einzeln prüfen. Der Testfall "Test mit Altrechnung" aus der Bestandsmatrix bleibt als externer Test offen (echtes Lexware-Konto erforderlich).

## 6. Journal-Export

`export.php` (Login-Pflicht, alle Rollen der Firma, GET-Download): CSV UTF-8 mit BOM, Semikolon, Spalten Rechnungsnummer, Lexware-Rechnungs-ID, Kunde, Kundennummer, Einzugs-ID, Stripe PaymentIntent, Stripe Charge, Betrag EUR (Komma), Status, Eingereicht am, Erfolgreich am, Rücklastschrift am, Erstattet EUR, Erstattet am, Mandatsreferenz, Stripe-Mandatsreferenz, Restbetrag laut Lexware, Restbetrag abgerufen am, Ausgelöst von, Termin, Vermerk, Fehlergrund. Formelschutz: Zellen, die mit `=`, `+`, `-`, `@` oder Tabulator beginnen, erhalten ein führendes Apostroph. Jeder Export wird mit `collections_exported` protokolliert. Link in `collections.php` (übernimmt den Statusfilter).

## 7. Digitale Mandatsanforderung (Feature-Schalter, Standard aus)

`config.php`: `'features' => ['mandate_request' => true]`. Ohne Schalter fehlt der Button, `customer.php` lehnt die Aktion serverseitig ab, `mandat.php` liefert 404.

* Kundendetails: "Mandat digital anfordern" erzeugt ein Token (32 Byte, nur SHA-256-Hash in `mandate_requests.token_hash`), 14 Tage gültig, versendet den Link per `mail_send`. Ist der Mailversand nicht eingerichtet, wird der Link einmalig angezeigt. Eine neue Anforderung widerruft die bisherige; Widerruf jederzeit möglich. Audit `mandate_request_sent`, `mandate_request_revoked`.
* `mandat.php`: ohne Anmeldung, ohne Tracking- oder Drittskripte, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, restriktive CSP. GET zeigt nur Zahlungsempfänger, Zahlungspflichtigen und `mandate_texts()`. Erst POST mit CSRF-Token und Bestätigungshäkchen startet die Stripe Checkout Session (`mode=setup`, `payment_method_types=[sepa_debit]`, Kunde aus `findOrCreateCustomer`, Metadaten `tenant_id`, `mandate_request_id`, `customer_id`, `success_url` mit `&done=1`, `cancel_url` ohne). Status `pending`, Audit `mandate_request_started`.
* `stripe-webhook.php` bei `checkout.session.completed` mit `mode=setup`: Firma aus `metadata.tenant_id`, Signaturprüfung mit dem Webhook-Secret der Firma, Anforderung per ID und Session-ID geprüft, SetupIntent abgerufen (`status=succeeded`), dann `mandate_request_grant()`: Status atomar auf `granted` (verhindert Mehrfachverarbeitung), Zahlungsmethode, Stripe-Mandat und Stripe-Referenz gespeichert, lokales Mandat über `get_or_create_mandate` aktiviert (`signed_date` = heute, `signed_place` "digital (Stripe)"), Audit `mandate_granted_digital`.
* Bankverbindung: Stripe liefert nur Land, Bankleitzahl und letzte vier Stellen. Passt eine aktive lokale IBAN (Land und letzte vier Stellen), wird sie weiterverwendet; sonst entsteht eine maskierte Bankverbindung (`customer_ibans.source = stripe_digital`, z. B. `DE****************3000`), bisherige aktive IBANs werden mit Historie deaktiviert. Einzüge nutzen die gespeicherte Zahlungsmethode bei Stripe; `register_iban_with_stripe` und `_execute_stripe_collection` erzeugen für digitale Bankverbindungen keine neue Zahlungsmethode aus der IBAN.
* Erinnerung: `mandate_request_remind()` (höchstens 2, frühestens nach 4 Tagen, Token wird dabei rotiert, alter Link ungültig). Noch nicht an `cron.php` angebunden.
* Datenschutzhinweis auf `mandat.php` (vor dem Bestätigen, Entwurf): Verantwortlicher ist die anfordernde Firma, Stripe Payments Europe Ltd. wird im Auftrag der Firma tätig, die Plattform verarbeitet im Auftrag und erhält die Bankverbindung nur maskiert, keine Weitergabe an sonstige Dritte, Link auf `public_base_url()/datenschutz/`.
* Freigabekriterien vor Aktivierung: kompletter Durchlauf im Stripe-Testmodus (Testkonto, Webhook-Endpunkt mit `checkout.session.completed`), Prüfung der Mandatstexte und des Datenschutzhinweises durch Rechtsberatung.

## 8. Regelautomatik (nur Gerüst)

Tabelle `collection_rules` (`is_active` Standard 0, `customer_scope` all oder selected, `max_amount_cents`, `max_per_run`, `require_second_approval` Standard 1). Einzige Funktion: `collection_rules_preview(tenantId, ruleId)` liefert die Rechnungen, die eine Regel heute einreichen würde (offen, SEPA gewünscht, IBAN, aktives Mandat mit Nachweis, Fälligkeit ab `start_date`, Höchstbetrag, Höchstzahl je Lauf, frischer Restbetrag 0 ausgeschlossen). Es gibt keine Verarbeitung, keinen Cron und keine Oberfläche. Freigabe erst nach: Vier-Augen-Freigabe je Lauf in der Oberfläche, Höchstbetrag je Lauf, Not-Stopp-Prüfung, Live-Restbetrag je Rechnung, Audit je Regel und Lauf, E2E-Test.

## 9. Audit-Aktionen (neu)

`collections_paused`, `collections_resumed`, `collections_due_skipped_paused`, `collection_attempt_recovered`, `collection_attempt_cleared`, `collections_exported`, `mandate_request_sent`, `mandate_request_reminded`, `mandate_request_revoked`, `mandate_request_started`, `mandate_granted_digital`, `collection_refunded`, `invoice_review_cleared`, `alerts_mailed`, `twofa_reauth_failed`. Labels in `app/audit.php`.

## 10. Offene Punkte

1. Lexware Payments-Endpunkt mit echtem Beleg verifizieren (Feldnamen `openAmount`, `paymentStatus`, `paidDate`; Dokumentation beim Bau nicht erreichbar).
2. Erledigt: `team.php` Beschriftung "Handschriftlicher Nachweis erforderlich", Link auf `notstopp.php` in der Navigation.
3. Erledigt: `admin.php` Plattform-Not-Stopp als Schalter (mit Zweitbestätigung), Plattform-Alarme (Abschnitt 5b).
4. Erledigt: Cron-Anbindung für `mandate_request_remind()` (nur bei aktivem Feature-Schalter) und `collection_attempts_resolve()`.
5. Erledigt: Erstattungen aus Stripe (Abschnitt 5a). Offen bleibt der externe Test mit einer echten Erstattung im Stripe-Testmodus; die Webhook-Endpunkte der Firmen müssen `charge.refunded` und `charge.refund.updated` liefern.
6. Stripe Search API: Verfügbarkeit im Konto prüfen (Suchindex, Ratenbegrenzung); ohne Search bleibt die manuelle Klärung per Stripe-Dashboard.
7. Restbetrag im Rahmen der Synchronisation optional mitführen (ein zusätzlicher API-Aufruf je Rechnung).
8. Alarm-E-Mail setzt aktiven Mailversand voraus (`mail.enabled`); produktiv verifizieren, sobald SMTP eingerichtet ist.
9. Datenschutzhinweis auf `mandat.php` und Bundesbank-Hinweis zur Vorabankündigungsfrist in `team.php` sind Entwürfe und durch Rechtsberatung zu prüfen.

## Karenzzeit und Einreichfenster (Paket 2, Migration 011)

- Sofort-Einzüge werden als vorgemerkt gespeichert (`payment_collections.is_scheduled = 1`, `queued_immediate = 1`, `submit_not_before`) und erst nach der Karenzzeit (`collections.grace_hours`, Standard 4 Stunden) im Einreichfenster (`collections.window_start` bis `window_end`, Standard 23:00 bis 06:00) vom Cron eingereicht. Bis dahin sind sie stornierbar; die Rechnung geht dann auf offen zurück.
- Beim Vormerken werden Stripe-Verbindung, Mandat, Restbetrag (gespeicherter Wert) und offene Einzugsversuche geprüft; der Restbetrag wird bei der Einreichung erneut live abgefragt (`_submit_single_scheduled`).
- `process_scheduled_collections` reicht nur im Fenster ein (`ignore_window` nur nach Zweitbestätigung durch Inhaber oder Administrator, Audit `collections_due_forced`), nur wenn `submit_not_before` erreicht ist, nur wenn der Termin nicht älter als `collections.overdue_days` ist, und höchstens bis zur übergebenen Deadline (Cron: halbes Zeitbudget). Überfällige Termine bleiben zur manuellen Entscheidung (neu terminieren oder stornieren).
- Storno ist atomar (`UPDATE ... WHERE stripe_status = 'scheduled' AND scheduled_submitted = 0`), sodass ein parallel laufender Cron denselben Einzug nicht mehr beansprucht. Sammelstorno beim Not-Stopp: `collections_cancel_all_pending`, Audit `collections_bulk_cancelled`.
- Zeitvergleiche laufen mit der Anwendungszeit (`collections_now()`, in Tests überschreibbar), nicht mit `NOW()` der Datenbank, damit Zeitzonenunterschiede zwischen PHP und MariaDB keine Rolle spielen.
