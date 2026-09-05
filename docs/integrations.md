# Integrationen und Adaptergrenze (Paket F), Stand 05.09.2026

## Architektur

Die Anwendung liest Kunden, offene Rechnungen und Zahlungsstände ausschließlich über das Interface `InvoiceSource` (`php-ionos/app/invoice_source.php`). Zahlungen laufen über `StripeClient` (`app/stripe.php`) mit dem eigenen Stripe-Konto der Firma.

| Baustein | Aufgabe |
|---|---|
| `interface InvoiceSource` | `code()`, `capabilities()`, `getProfile()`, `getOpenInvoices()`, `getInvoiceVouchersPage()`, `getInvoiceDetail()`, `getContact()`, `getPayment()` |
| `LexwareOfficeSource` | Dünner Wrapper um `LexofficeClient`, Verhalten identisch (Drosselung, Retries, Ausweich-URL bleiben im Client) |
| `invoice_source_for_tenant($tenantId)` | Factory: liest `integrations.invoice_source` (Standard `lexware_office`) und den verschlüsselten Schlüssel; wirft bei nicht freigegebener Quelle, fehlender Verbindung oder fehlendem Schlüssel. Testhaken `lexsepa_lex_client_factory` (nur CLI) akzeptiert `LexofficeClient` oder `InvoiceSource` |
| `invoice_source_from_key($code, $apiKey)` | Verbindungstest beim Einrichten (Schlüssel noch nicht gespeichert) |
| `integration_provider($code)`, `integration_providers($kind)` | Registry aus `integration_providers` |

Umgestellte Aufrufer: `app/sync.php` (alle Signaturen `InvoiceSource`), `app/sync_state.php` (`sync_invoice_source()`), `reconcile.php`, `app/integrations.php` (Verbindungstest), `app/collections.php` (Restbetrag über `getPayment()`). `sync_lex_client()` bleibt für die reine Verbindungsprüfung in `invoices.php` erhalten.

Datenformat: Die Rückgaben entsprechen dem Lexware-Format (`voucherlist`, `invoices`, `contacts`, `payments`). Ein neuer Adapter übersetzt seine Daten in dieses Format; `app/sync.php` bleibt unverändert.

## Registry `integration_providers`

| code | Art | Status | Fähigkeiten | API | Hinweis |
|---|---|---|---|---|---|
| `lexware_office` | Rechnungssystem | released | read_customers, read_open_invoices, read_open_amount, detect_changes | v1 | Public API, nach Angaben von Lexware Tarif XL erforderlich (im eigenen Konto prüfen). Kein Schreibzugriff auf Zahlungen, Zuordnung in Lexware Office manuell. |
| `sevdesk` | Rechnungssystem | planned | keine | v2 | In Planung. Voraussetzung laut Anbieter voraussichtlich Tarif Buchhaltung Pro. Ungeprüft, keine Freigabe, kein Angebot, kein Kaufbutton. |
| `stripe` | Zahlungsdienstleister | released | sepa_debit, payment_intents, setup_checkout, mandates, webhooks | 2024-06-20 | Eigenes Stripe-Konto des Kunden, SEPA-Lastschrift dort freigeschaltet. Mandatsreferenz-Präfix optional ab API 2024-12-18. |

Statuswerte: `planned` (nur Absicht), `development` (Adapter in Arbeit, nicht wählbar), `closed_test` (ausgewählte Firmen im Testmodus), `released` (frei wählbar). Die Factory lässt nur `released` zu; alles andere liefert eine verständliche Fehlermeldung.

## Fähigkeitentabelle (Anforderungen an einen Adapter)

| Fähigkeit | Bedeutung | Pflicht für Freigabe |
|---|---|---|
| read_customers | Kontakt mit Kundennummer, Name, E-Mail | ja |
| read_open_invoices | offene und überfällige Rechnungen mit ID, Nummer, Bruttobetrag, Fälligkeit, Positionen | ja |
| read_open_amount | offener Restbetrag je Rechnung (Teilzahlungen) | ja, sonst kein Einzug |
| detect_changes | Erkennen, dass eine Rechnung bezahlt oder storniert wurde | ja |
| write_payment | Zahlung im Rechnungssystem zurückschreiben | nein (Lexware Office bietet keinen dokumentierten Endpunkt; Anleitung zur manuellen Zuordnung im Ratgeber) |

## Eine Rechnungsquelle je Firma

`integrations` bleibt die Verbindungs-Tabelle (Schlüssel verschlüsselt, Prüfzeitpunkte, Kontodaten). Neue Spalte `integrations.invoice_source VARCHAR(32) DEFAULT 'lexware_office'`. Ein Wechsel der Quelle ist nur vorgesehen, wenn keine Rechnung im Einzug ist, und ist derzeit nicht in der Oberfläche.

## Freigabekriterien sevdesk

Erst wenn alle Punkte erfüllt sind, wechselt der Status auf `closed_test`, danach auf `released`:

1. Tarifvoraussetzung des Anbieters (voraussichtlich Buchhaltung Pro, API v2) schriftlich geprüft, Formulierung auf den Webseiten vorsichtig ("nach Angaben des Anbieters").
2. Adapter `SevdeskSource implements InvoiceSource` mit allen Pflichtfähigkeiten, insbesondere `read_open_amount` (Teilzahlungen) und `detect_changes`.
3. Feldzuordnung dokumentiert: Kundennummer, Bruttobetrag, Fälligkeit, Rechnungsstatus, Storno.
4. Ratenbegrenzung und Paginierung des Anbieters im Adapter umgesetzt (vergleichbar mit der Drosselung im Lexware-Client).
5. CLI-Test mit Ersatz-Client (wie `test_rules_sync.php`) und E2E-Lauf, alle Prüfungen aus `docs/payment-safety.md` bestehen unverändert.
6. Geschlossener Test mit mindestens einer Firma im Stripe-Testmodus über eine volle Vierwochenperiode ohne Fehleinzug.
7. Rechtstexte (AGB, Datenschutz, Markenhinweise) um sevdesk ergänzt und geprüft.
8. Freigabe durch die Geschäftsführung der Müller Holding AG.

Bis dahin erscheint sevdesk in allen Texten nur als "in Planung", ohne Preis, ohne Kaufbutton und ohne Registrierungsmöglichkeit.
