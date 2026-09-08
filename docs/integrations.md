# Integrationen und Adaptergrenze (Paket F), Stand 07.09.2026

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

| Fähigkeit | Bedeutung | Pflicht für Freigabe | sevdesk (Stand 4.38) |
|---|---|---|---|
| read_customers | Kontakt mit Kundennummer, Name, E-Mail | ja | aktiv, sobald die Verbindung freigegeben ist |
| read_open_invoices | offene und überfällige Rechnungen mit ID, Nummer, Bruttobetrag, Fälligkeit, Positionen | ja | aktiv, sobald die Verbindung freigegeben ist |
| read_open_amount | offener Restbetrag je Rechnung (Teilzahlungen) | ja, sonst kein Einzug | erst nach Bestätigung (`sevdesk_api_verified`); bis dahin liefert `getPayment()` `open_amount = null`, also kein Einzug |
| detect_changes | Erkennen, dass eine Rechnung bezahlt oder storniert wurde | ja | aktiv, sobald die Verbindung freigegeben ist |
| write_payment | Zahlung im Rechnungssystem zurückschreiben | nein (Lexware Office bietet keinen dokumentierten Endpunkt; Anleitung zur manuellen Zuordnung im Ratgeber) | nicht vorhanden, keine Zusage |

`SevdeskSource::capabilities()` (`app/sevdesk.php`) meldet die drei aktiven Fähigkeiten nur, wenn `integration_switch('sevdesk', 'connect')` wahr ist (Schalter `sevdesk_connect` oder automatischer Freigabetermin `sevdesk_release_at`, siehe `docs/sevdesk.md`, Abschnitt 5c); ohne Freigabe liefert die Methode eine leere Liste.

## Eine Rechnungsquelle je Firma

`integrations` bleibt die Verbindungs-Tabelle (Schlüssel verschlüsselt, Prüfzeitpunkte, Kontodaten). Spalte `integrations.invoice_source VARCHAR(32) DEFAULT 'lexware_office'`, seit Migration 024 dazu `invoice_source_changed_at` und `invoice_source_switches`.

**Anzeige und Wechsel (Version 4.31, `app/invoice_source_switch.php`):** Der Kunde sieht immer genau das System seiner Firma (Dashboard, Einstellungen); Lexware Office und sevdesk erscheinen nie nebeneinander. Bei der Registrierung wird das System über `register.php?integration=` vorgewählt (sevdesk nur, wenn `sevdesk_connect` gesetzt ist, sonst Vorregistrierung). In den Einstellungen, Abschnitt Buchhaltungssystem, können Inhaber und Administratoren mit 2FA-Code wechseln. Regeln:

- Das Abonnement ist für beide Systeme identisch (Tabelle `plans`); ein Wechsel ändert nichts am Tarif.
- Nach einem Wechsel gilt eine Sperre von vier Wochen (`INVOICE_SOURCE_LOCK_DAYS = 28`, entspricht der Abrechnungsperiode). Damit lässt sich nicht mit einem Abonnement zwischen zwei Buchhaltungen hin- und herschalten; wer zwei Buchhaltungen führt, legt einen zweiten Firmenaccount an.
- Gesperrt ist der Wechsel außerdem, solange Einzüge vorgemerkt, terminiert oder in Verarbeitung sind (`stripe_status` scheduled, submitting, processing), eine Synchronisation läuft oder das Zielsystem nicht freigegeben ist.
- Wirkung: Die Verbindung zum bisherigen System wird getrennt (Schlüssel gelöscht, `lexoffice_connected = 0`, damit der Scheduler keine Synchronisation mehr einreiht). Rechnungen, Kunden, Mandate und Einzüge bleiben als Historie erhalten. Audit `invoice_source_switched` mit Herkunft, Ziel, Grund.
- Betreiber (seit Version 4.38): Adminaktion „Wechselsperre aufheben" (`admin.php`, Firmenliste, Berechtigung `companies.manage`, Pflichtgrund 3 bis 200 Zeichen, kein 2FA-Code, da kein Geldfluss). Sie setzt `integrations.invoice_source_changed_at = NULL` und `invoice_source_lock_reset_at = UTC_TIMESTAMP()` (Migration 028), protokolliert im Audit als `invoice_source_lock_reset` mit Firma, Grund, bisherigem System und Sperrende. Der Wechselzähler (`invoice_source_switches`) bleibt unverändert; die nächste Vier-Wochen-Sperre beginnt erst mit dem nächsten tatsächlichen Wechsel (`app/invoice_source_switch.php`, Funktion `invoice_source_lock_reset()`).

Prüfung: `bash tools/invoice-source-check.sh`.

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

Stand 4.38 zu Punkt 2: Der Adapter (`SevdeskSource implements InvoiceSource`, `app/sevdesk.php`) ist gebaut, aber
ungeprüft (kein Testkonto); `read_open_amount` ist im Code vorgesehen, aber technisch gesperrt, bis der Betreiber ihn
freischaltet (siehe unten). Das ersetzt Punkt 2 nicht, sondern ist eine zusätzliche, rein technische Absicherung.

Unabhängig von diesen produktseitigen Freigabekriterien setzt die Anwendung seit Version 4.38 rein technische Schalter,
die verhindern, dass ein Irrtum bei einer Annahme des Adapters Geld bewegt (`platform_settings`,
`app/integration_state.php`, ausführlich `docs/sevdesk.md`, Abschnitt 5c):

- `sevdesk_connect` gibt nur das Verbinden und Lesen frei (Kontakte, offene Rechnungen, Änderungserkennung). Ein
  ausdrücklich gesetzter Wert hat Vorrang; ohne ihn greift automatisch der Freigabetermin `sevdesk_release_at`
  (Migration 028 legt ihn mit `2026-09-30` an, Kalendertag Europe/Berlin, jederzeit per SQL änderbar). Dieser Termin ist
  ein interner Standardwert für Pilotfirmen, keine der oben genannten Freigabekriterien und keine öffentliche Zusage.
- `sevdesk_api_verified` schaltet ausschließlich `read_open_amount` frei, nachdem die Zahlungsfelder an einem echten
  Konto bestätigt sind; ohne diesen Schalter liefert `getPayment()` immer `open_amount = null`.
- `sevdesk_collections` bleibt der anbieterbezogene Not-Aus für den eigentlichen Einzug.

Bis dahin erscheint sevdesk in allen Texten nur als "in Planung", ohne Preis, ohne Kaufbutton und ohne Firmenaccount. Zulässig ist seit Version 4.18 ausschließlich eine kostenlose, unverbindliche **Vormerkung** (Warteliste, Double-Opt-in), siehe unten; der Starttermin wird als "geplant zum 30.09.2026" genannt, nie als Zusage.

## Produkttrennung Lexware Office und sevdesk (Empfehlung, Stand 07.09.2026)

Frage des Betreibers: die beiden Produkte komplett trennen? Empfehlung: **eine Plattform, getrennte Anbindungen und getrennter Marktauftritt**, keine zweite Anwendung.

- Technik: Die Trennung existiert bereits als Adaptergrenze (`InvoiceSource`, Registry `integration_providers`, `integrations.invoice_source`). sevdesk wird ein zweiter Adapter (`SevdeskSource`), die Firma wählt ihre Rechnungsquelle bei der Einrichtung. Mandate, Einzüge, Vorabankündigung, Abrechnung, Admin und Monitoring bleiben gemeinsam. Für die Warteschlange kommt ein eigener Workertyp (`worker-sevdesk`) hinzu, damit API-Grenzen und Störungen des einen Systems das andere nicht bremsen (Circuit Breaker je Anbieter, bereits vorhanden).
- Markt und SEO: je Rechnungssystem eine eigene Landingpage unter `smart-einzug.de/integrationen/<anbieter>/` und optional eigene Leaddomains (wie lexware-einzug.de / lexoffice-einzug.de). Herkunft je Firma wird über `signup_domain` gemessen, so lässt sich auch eine Provision je Leadquelle abrechnen.
- Preise: gemeinsam über die Tabelle `plans`; ein eigener Tarif für sevdesk ist möglich, ohne den Code zu ändern.
- Gegen zwei getrennte Anwendungen sprechen doppelte Infrastruktur, doppelte Sicherheitspflege, doppelte Abrechnung und doppelte Rechtstexte bei identischem Kern (Mandat, Einzug, Stripe).

## Vormerkung (Warteliste) für angekündigte Integrationen (Version 4.18)

- Formular auf `smart-einzug.de/integrationen/sevdesk/` (indexierbar), Ziel `app.smart-einzug.de/vormerken.php` (CSP `form-action` erlaubt den Host).
- Tabelle `interest_registrations` (Migration 020): eine Zeile je Anbieter und E-Mail, Status `pending`, `confirmed`, `unsubscribed` (plus Sperrvermerk `blocked_at`); getrennte Token für Bestätigung (`token_hash`, 7 Tage) und Abmeldung/Angaben (`manage_token_hash`), beide SHA-256; Fassung und Zeitpunkt der Einwilligung, Zweck `launch_info`; freiwillige Angaben nach Bestätigung; keine IP-Adressen. Details: `docs/sevdesk.md`.
- Double-Opt-in: Link 7 Tage gültig, Bestätigung und Abmeldung erst per Button (POST), damit Linkvorschauen nichts auslösen. Löschfristen 30 Tage (unbestätigt nach Eintragung, abgemeldet nach Widerruf, bestätigt nach Startnachricht) über `interest_cleanup()` in `job_maintenance` UND `cron.php`. Ohne funktionierenden Mailversand keine Vormerkung. Einwilligungsfassungen in `docs/einwilligungen.md`.
- Schutz ohne Personenbezug: Origin/Referer aus `signup_domains` (nur gegen Einbettung fremder Seiten), Honeypot, Wiederversand frühestens nach 10 Minuten je Adresse (auch nach Fehlversuch), höchstens 3 Mails je Adresse und 24 Stunden, höchstens 30 neue Einträge je Minute; identische Antwort für neu, unbestätigt und bereits bestätigt. Restrisiko: ein Skript ohne Header kann die globale Grenze ausschöpfen (Denial of Service der Vormerkung, kein Datenabfluss); Gegenmaßnahme bei Bedarf: Grenze je Quelle über kurzlebige Hashes im Redis.
- Adminbereich: Karte "Vormerkungen für angekündigte Integrationen" (Zählung je Anbieter, letzte 200 Einträge, Aktionen Abmelden und Löschen mit Audit für Widerruf oder Löschverlangen per Nachricht; seit 4.36 ohne 2FA-Code, Löschen mit Bestätigungsdialog). Zum Start dürfen nur bestätigte Adressen angeschrieben werden; ein Versandwerkzeug existiert noch nicht (muss `notified_at` setzen).
- Test: `bash tools/interest-check.sh` (temporäre MariaDB, 47 Fälle).
