# Zahlungsinvarianten SmartEinzug (Audit 09.09.2026 bis 10.09.2026)

Stand: Prüfbranch `audit/2026-09-09-gesamtpruefung`, Ausgangscommit 66c59d5 (Version 4.58). Dieses Dokument leitet aus dem
vorhandenen Code die fachlichen Regeln des Geldflusses ab, benennt die Zustandsmaschine und ordnet jeder Regel die Stelle im
Code und den automatisierten Nachweis zu. Kennzeichnung je Regel: automatisiert getestet (Suite und Fall), statisch geprüft
(nur Codeanalyse), nicht geprüft.

## 1. Datenfluss (tatsächlich implementiert)

1. Lexware-Rechnung: `app/sync.php` (`sync_invoices_step`) liest Belegliste, Detail und Kontakt über die Adaptergrenze
   `InvoiceSource` (`app/invoice_source.php`) und schreibt `invoices` (UNIQUE `tenant_id, lexoffice_invoice_id`) und
   `customers` (UNIQUE `tenant_id, lexoffice_contact_id`). Die Synchronisation löst niemals einen Einzug aus.
2. Einzugsfähigkeit: `_load_and_validate()` (`app/collections.php`) verlangt Rechnung der Firma, `lexoffice_status` open oder
   overdue, `collection_status` nicht in_collection oder scheduled, kein `requires_review`, Kunde mit `sepa_debit_enabled`,
   aktive IBAN. Seit dem Audit zusätzlich: Währung EUR.
3. Mandat: `get_or_create_mandate()` und `mandate_check_usable()` (`app/mandates.php`): aktiv, nicht widerrufen oder verfallen,
   IBAN gebunden, 36-Monats-Regel, Unterschriftsnachweis je Firmeneinstellung. Mandate sind je Firma und Kunde gebunden.
4. Betrag: serverseitig aus `invoices.total_gross_amount` und dem Live-Restbetrag aus Lexware (`invoice_fetch_open_amount`)
   abzüglich eigener aktiver Einzüge (`invoice_own_collections_cents`); ein abweichender Betrag braucht eine ausdrückliche
   Bestätigung über genau diesen Betrag (`confirm_amount_cents`). Cent-Integer, Rundung `round(x * 100)`.
5. Vorbereitung: Versuchsjournal `collection_attempts` mit Idempotenzschlüssel `sha256(firma|rechnung|betrag|versuchsnummer)`,
   geschrieben VOR dem Stripe-Aufruf über eine eigene Autocommit-Verbindung (`_attempts_db`), UNIQUE auf den Schlüssel.
6. Stripe-Aufruf: `StripeClient::createPaymentIntent()` mit `Idempotency-Key` und `metadata.attempt_key`.
7. Ereignisverarbeitung: `stripe-webhook.php` (Signatur je Firma, Beanspruchung je Ereignis, Reihenfolge je Objekt).
8. Abgleich: `sync_collection_statuses()` (manuell; seit 4.73 auch Rückschau auf Rücklastschrift und Erstattung abgeschlossener Einzüge über `collection_apply_dispute()`/`collection_apply_refund()`), `collection_attempts_resolve()` (Klärung unklarer Versuche).
9. Anzeige und Journal: `collections.php`, `invoices.php`, `audit_log`, Export.

Die Plattform-Abrechnung (Abonnements der Firmen, Stripe-Konto der Müller Holding AG) läuft über `app/billing.php` und
`billing-webhook.php` mit eigenem Schlüssel, eigenem Webhook-Secret und eigener Quelle `webhook_events.source = 'billing'`.
Kein Codepfad verwendet den Plattformschlüssel für Kundeneinzüge (statisch geprüft, Rolle A).

## 2. Zustandsmaschine

### 2.1 Einzug (`payment_collections.stripe_status`)

| Zustand | Bedeutung | Erlaubte Übergänge (Quelle) |
|---|---|---|
| scheduled | terminiert oder vorgemerkt, noch kein Stripe-Aufruf | submitting (Beanspruchung durch den Fälligkeitslauf), cancelled (Storno durch Nutzer, durch Synchronisation bei bezahlter Rechnung, durch den Fälligkeitslauf bei nicht mehr offener Rechnung), failed (Prüfung vor dem Aufruf scheitert, z. B. Mandat) |
| submitting | von genau einem Lauf beansprucht, Aufruf läuft oder Ergebnis unbekannt | processing (Erfolg), scheduled (Zurückstellung, Freigabe), failed (endgültige Ablehnung) |
| processing | von Stripe angenommen, Bankeinzug läuft (mehrere Tage) | succeeded, failed (Webhook oder Abgleich), disputed |
| succeeded | Bankeinzug bestätigt | disputed (Rücklastschrift), refunded (Vollerstattung); Teilerstattung bleibt succeeded mit `refunded_cents` |
| failed | endgültig gescheitert (Stripe-Ablehnung oder Fehlschlag der Bank) | keine automatische Wiederholung desselben Einzugs; neue Rechnung nur über neuen Einzug |
| disputed | Rücklastschrift | Endzustand |
| refunded | vollständig erstattet | Endzustand |
| cancelled | vom Nutzer storniert | Endzustand |

Eine erfolgreiche API-Annahme (processing) ist kein abgeschlossener Bankeinzug. Nur `payment_intent.succeeded`
(Webhook oder Abgleich) führt zu succeeded.

### 2.2 Versuch (`collection_attempts.status`)

pending (Aufruf läuft) → succeeded (PaymentIntent bekannt) | failed (klare Ablehnung, neuer Versuch mit neuem Schlüssel
erlaubt) | unknown (Ergebnis bei Stripe unbekannt: Timeout, Verbindungsfehler, Antwort ohne lesbares JSON, HTTP 5xx, HTTP 409).
unknown → succeeded (Klärung findet den PaymentIntent) | failed (Klärung findet nach Frist und konsistenter Listenprüfung
nichts). Ein Versuch pending, unknown oder succeeded ohne Einzugsdatensatz blockiert jeden weiteren Versuch der Rechnung.

### 2.3 Rechnung (`invoices.collection_status`)

none/open → scheduled (Terminierung) → in_collection (eingereicht) → collected (bestätigt) | failed. Nach Rücklastschrift oder
Erstattung: failed bzw. open mit `requires_review = 1`; Klärung nur durch Inhaber oder Administrator (`invoice_review_clear`).

## 3. Invarianten und Nachweise

| Nr. | Invariante | Umsetzung | Nachweis |
|---|---|---|---|
| I1 | Höchstens ein PaymentIntent je fachlich erlaubtem Versuch; parallele Anfragen (Browser, Sammel-Einzug, Cron, Worker) erzeugen keine zweite Lastschrift | benannte Sperre `GET_LOCK('smarteinzug_collect_<firma>')` im Sofortpfad (seit Audit, vorher Firmenzeile `FOR UPDATE`), Rechnungszeile `FOR UPDATE`, atomare Beanspruchung `UPDATE ... WHERE stripe_status = 'scheduled'`, UNIQUE Idempotenzschlüssel | automatisiert: `tools/collections-check.sh` Abschnitte 2, 4 (sechs echte parallele Prozesse), 8 (drei parallele Fälligkeitsläufe) |
| I2 | Idempotenzschlüssel wird vor dem Aufruf persistiert und bei technischer Wiederholung nie neu vergeben | `collection_attempt_begin` vor `_execute_stripe_collection`; unknown blockiert | automatisiert: Abschnitt 7, 7a, 7b, 7d |
| I3 | Ein unbekanntes Ergebnis wird nie als „nicht ausgeführt“ behandelt; Freigabe nur nach Frist UND konsistenter Prüfung gegen Stripe (Liste im Zeitfenster um den Versuch, nicht nur Suchindex); ist die Liste nicht vollständig lesbar, bleibt der Versuch offen | `collection_attempts_resolve`, `_stripe_find_payment_intent_by_attempt_key` (Ausnahme statt Freigabe bei erschöpftem Seitenlimit, F-01) | automatisiert: 7a (Suchindex hängt, Liste findet), 7a2 (mehrere Seiten), 7b (nichts angelegt) |
| I4 | Mandant, Kunde, Mandat, IBAN und Stripe-Konto gehören zusammen | alle Abfragen mit `tenant_id`, Mandat je Firma und Kunde, Schlüssel je Firma aus `integrations` | automatisiert: Abschnitt 3, 14a; statisch: Rolle C |
| I5 | Betrag serverseitig, nie größer als Rechnungsbetrag oder Restbetrag, Teilzahlung nur mit Bestätigung, bezahlt blockiert | `_determine_collection_amount`, Live-Prüfung im Fälligkeitslauf | automatisiert: Abschnitt 5, 12a |
| I6 | Nur EUR | Währung der Rechnung und des Zahlungsstands | automatisiert: Abschnitt 5 (CHF abgewiesen) |
| I7 | Mandat widerrufen, verfallen, nicht unterschrieben: kein Einzug | `mandate_check_usable`, `mandate_requires_manual_renewal` | automatisiert: Abschnitte 3, 6 |
| I8 | Rücklastschrift und Erstattung setzen Klärungsbedarf, kein automatischer Neu-Einzug | Webhook dispute, `collection_apply_refund` | automatisiert: Abschnitt 13, 14 |
| I9 | Veraltete oder doppelte Webhook-Ereignisse überschreiben keinen neueren Zustand; Ereignisse fremder Firmen wirken nicht | `webhook_event_claim`, `webhook_event_is_stale` (seit Audit mandantenbezogen) | automatisiert: Abschnitt 14, 14a |
| I10 | Ein Verarbeitungsfehler im Webhook wird nie mit 200 quittiert | HTTP 500 im Hauptblock und (seit Audit) bei Datenbankfehlern der Firmenzuordnung, vorläufige Fälle mit jungem Versuch | automatisiert: 14c; statisch: Abschnitt 15 |
| I11 | Anbieterstörung (Circuit Breaker, Ratenbegrenzung) ist kein fachlicher Fehlschlag | (seit Audit) Zurückstellung statt failed | automatisiert: Abschnitt 11 |
| I12 | Ein beanspruchter Einzug ohne Versuch bleibt nicht dauerhaft hängen | Rücksetzung nach 15 Minuten unabhängig von offenen Versuchen (seit Audit) | automatisiert: Abschnitt 10 |
| I13 | Einreichfenster begrenzt nur das Einreichen | `collections_window_open` nur in `process_scheduled_collections` | automatisiert: Abschnitt 9; `tools/scheduler-sync-check.sh` |
| I14 | Fristen des Versuchsjournals sind unabhängig von der Zeitzone der Datenbank | (seit Audit) Alter in SQL berechnet | automatisiert: gesamte Suite läuft mit Datenbank in UTC und PHP in Europe/Berlin |

## 4. Verbleibende systemübergreifende Zeitfenster (dokumentiert, nicht behebbar ohne Anbieter)

- Zwischen dem Restbetragsabruf bei Lexware und dem Stripe-Aufruf können Sekunden liegen; eine in diesem Fenster in Lexware
  gebuchte Zahlung wird nicht mehr erkannt. Es gibt keine atomare Transaktion zwischen Lexware, Stripe und der Datenbank.
- Eigene Einzüge werden bei Lexware erst nach Zahlungseingang (mehrere Bankarbeitstage) verbucht; bis dahin schützt nur die
  lokale Summe eigener Einzüge (`invoice_own_collections_cents`). Dasselbe Lexware-Konto in zwei Firmenaccounts umgeht diesen
  Schutz (Befund B-04, siehe AUDIT_REPORT.md).
- Stripe-Idempotenzschlüssel gelten 24 Stunden. Eine Klärung nach Ablauf stützt sich auf `metadata.attempt_key` in Liste und
  Suche; ein PaymentIntent ohne diese Metadaten (nur bei Aufrufen außerhalb dieser Anwendung) wird nicht zugeordnet.
- Der Stripe-Suchindex ist nur eventuell konsistent; die Liste `GET /v1/payment_intents` ist die maßgebliche Prüfung.

## 5. Wiederherstellung

- Ein Code-Rollback macht eingereichte Lastschriften nicht rückgängig. Nach einem Rollback bleiben `collection_attempts`
  und `payment_collections` maßgeblich; der nächste Fälligkeitslauf reicht nur `scheduled`-Einzüge ein.
- Nach Wiederherstellung eines älteren Datenbankstands MUSS vor dem Start der Worker die Klärung laufen
  (`collection_attempts_resolve` je Firma über den Job `unclear_attempts`) und die Liste der PaymentIntents bei Stripe seit dem
  Sicherungszeitpunkt gegen `payment_collections` abgeglichen werden (`stripe-import.php`, Lesezugriff). Bis dahin Not-Stopp
  der Plattform (`platform_settings.collections_paused = 1`). Siehe RELEASE_CHECKLIST.md.
