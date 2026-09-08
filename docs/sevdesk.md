# sevdesk-Erweiterung: Bestandsaufnahme, Zielarchitektur, Freigaben und Betrieb

Stand: 07.09.2026. Grundlage ist der Masterplan „SmartEinzug: sevdesk-Erweiterung“ (Planungsstand 07.09.2026).
Ende September 2026 ist ein Planungsziel, kein Freigabekriterium: Ohne erfolgreiche Abnahme bleibt die Anbindung
angekündigt oder in einer begrenzten Betaphase.

## 1. Bestandsaufnahme (gegen den Code geprüft am 07.09.2026)

| Bereich | Stand | Bewertung | Umsetzungsschritt |
|---|---|---|---|
| Adaptergrenze Buchhaltung | vorhanden: Interface `InvoiceSource`, `LexwareOfficeSource`, `SevdeskSource` (Version 4.38, `app/sevdesk.php`), Registry `integration_providers` (lexware_office released, sevdesk seit Migration 028 development statt planned), `integrations.invoice_source` | tragfähig, sevdesk-Adapter gebaut | Adapter liest bereits (Fähigkeiten je nach Freigabe, siehe Abschnitt 5); Endpunkte und Felder beruhen auf Sekundärquellen (Endpunktregister Abschnitt 5b) und sind noch nicht mit einem Testkonto bestätigt |
| Stripe-Anbindung | vorhanden: eigener geheimer Schlüssel je Firma, verschlüsselt (`integrations.stripe_secret_key_encrypted`), optional `stripe_account_id`; kein Connect | passt zum Zielbild (Einzug über eigenes Konto des Kunden, kein Sammelkonto) | keine Änderung |
| Plattform-Abrechnung getrennt vom Forderungseinzug | vorhanden: `app/billing.php` (Stripe-Konto der Müller Holding AG) getrennt von `app/collections.php` | erfüllt | keine Änderung |
| Benutzer, Firmen, Mitgliedschaften, Rollen, 2FA, Gerätevertrauen 90 Tage | vorhanden (`organization_members`, `trusted_devices`, `require_recent_totp`) | erfüllt | unverändert lassen |
| Registrierungspfade | vorhanden: `register.php` mit `src=` (Marketingherkunft) | fehlte: Anbieter-Vorauswahl | `integration=` aus fester Liste (`lexware_office`, `sevdesk`) getrennt in `$_SESSION['signup']['integration']`; sevdesk führt vor Freigabe zur Vorregistrierung |
| Öffentliche sevdesk-Seite | vorhanden `/integrationen/sevdesk/` (bis 4.17 noindex, Kurztext) | ausgebaut | Landingpage nach Masterplan 6 mit zwei Formularen, Voraussetzungen, Abgrenzung, Fragen; Startseiten-Teaser; Übersicht |
| Vorregistrierung | seit 4.18 vorhanden, 4.19 erweitert | erfüllt Masterplan 7 | getrennte Token (Bestätigung 7 Tage, Abmeldung dauerhaft), Name optional, freiwillige Angaben nach Bestätigung, Sperrvermerk, Double-Opt-in per Button |
| Adminverwaltung Interessenten | 4.19 | erfüllt Masterplan 8 in Teilen | Suche, Filter, CSV (formelsicher), Einwilligungsnachweis, Abmelden, Sperren, Löschen, Betaeinladung, Kennzahlen, Audit. Offen: Versandwerkzeug für Startnachricht |
| Freigabeschalter | fehlte | angelegt | `platform_settings`: `sevdesk_public_state`, `sevdesk_waitlist`, `sevdesk_connect`, `sevdesk_collections`, `sevdesk_writeback` (`app/integration_state.php`) |
| Hintergrundjobs | vorhanden: Warteschlange, Workertypen `lexware`, `sevdesk` (Version 4.38, Jobtyp `sync_run_sevdesk`, Container `worker-sevdesk`), `stripe`, `mail`, `maintenance`, Circuit Breaker je API (`api_call_gate('sevdesk', ...)`) | erledigt | keine weitere Umsetzung nötig; der Scheduler reiht sevdesk-Firmen nur ein, wenn verbunden und die Anbindung freigegeben ist |
| Tarife | vorhanden: Tabelle `plans`, Einführungspreis mit rollierendem Stichtag (`intro_price_deadline()`) | Repository einheitlich; **kritisch**: live zeigte lexoffice-einzug.de am 07.09.2026 noch „31.12.2026“ | Im Repository gibt es kein festes Datum mehr (`php tools/pricing-check.php`). Zu prüfen: Lauf des Jobs `deploy-webhosting` und Stand auf dem IONOS-Webhosting; keine neue sevdesk-Preisgarantie |
| sevdesk-Testkonto, API-Verifikation | **fehlt weiterhin** | **Blocker** bleibt bestehen, jetzt für die Bestätigung: Der Adapter ist gebaut, aber ungeprüft; `sevdesk_api_verified` bleibt unbesetzt, `getPayment()` liefert dadurch immer `open_amount = null`, also kein Einzug | Testkonto mit API-Zugang beschaffen (nach sevdesk-Hilfe Tarif Buchhaltung Pro, Systemversion 2.0), Endpunktregister (Abschnitt 5b) Punkt für Punkt verifizieren, danach `sevdesk_api_verified` setzen |
| Rückschreibung nach sevdesk | fehlt, bewusst nicht zugesagt | getrennte Funktion mit eigener Abnahme | erst nach Prüfung des API-Ablaufs (Verrechnungskonten, Teilzahlungen, Stornos) |
| Dokumentation | dieses Kapitel, `docs/integrations.md`, `docs/einwilligungen.md`, Admin-Doku über `tools/build-docs.py` | vorhanden | fortschreiben |

## 2. Zielarchitektur

Eine Anwendung, eine Codebasis, getrennte Buchhaltungsadapter. Die Wahl des Buchhaltungssystems gehört zur Firma
(`integrations.invoice_source`), nicht zum Benutzer und nicht zur Herkunftsseite. Zum ersten sevdesk-Release genau eine
aktive Rechnungsquelle je Firma; die Datenstruktur (Registry, Adaptergrenze) lässt spätere Erweiterung zu, ohne sie
freizuschalten. Zahlungsdienst, Mandatsprüfung, Einzugsfreigabe und Historie bleiben gemeinsam. Marketing je
Rechnungssystem getrennt (eigene Landingpage, optional Leaddomain), Herkunft über `signup_domain` messbar. Der Wechsel
des Systems je Firma (Einstellungen, Vier-Wochen-Sperre, gleiche Tarife) ist in `docs/integrations.md`, Abschnitt „Eine
Rechnungsquelle je Firma“, beschrieben.

## 3. Freigabeschalter (Masterplan 12)

Setzen nur serverseitig, wie der plattformweite Not-Stopp:

```sql
INSERT INTO platform_settings (`key`, `value`) VALUES ('sevdesk_public_state', 'beta')
  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
-- Schalter: sevdesk_waitlist (Standard 1), sevdesk_connect (0), sevdesk_collections (0), sevdesk_writeback (0)
```

`sevdesk_collections = 0` wirkt als anbieterbezogener Not-Aus für NEUE Einzüge; laufende Vorgänge, Webhooks und
Abstimmung laufen weiter, Lexware bleibt unberührt. Vor öffentlicher Freigabe führt `register.php?integration=sevdesk`
zur Vorregistrierung; erst `sevdesk_connect = 1` öffnet die normale Konto- und Firmeneinrichtung (Pilotfirmen).

## 4. Vorregistrierung (Masterplan 7 und 8)

Siehe `docs/integrations.md`, Abschnitt Vormerkung, und `app/interest.php` (Kopfkommentar). Kurz: Token A (Bestätigung,
7 Tage, nach Bestätigung gelöscht) und Token B (Abmeldung und freiwillige Angaben, dauerhaft, nur die jüngste Mail
gültig), beide nur als SHA-256. Bestätigung und Abmeldung erst per Button (POST). Einwilligungsfassung v3, Zweck
`launch_info`. Sperrvermerk behält nur die E-Mail-Adresse. Kennzahlen getrennt: Formularabsendungen (funnel_events
`interest_submitted`), bestätigt, Betatest-Interesse, eingeladen, aktiviert, verbundene sevdesk-Firmen, erste Einzüge.
Ein Versandwerkzeug für die Startnachricht fehlt noch; es darf nur `status = confirmed AND blocked_at IS NULL`
adressieren und muss `notified_at` setzen.

## 5. Adapter (Masterplan 9), Stand und Blocker

Seit Version 4.38 (Masterplan Phase 2, Baustein B) ist der Adapter gebaut: `app/sevdesk.php` enthält `SevdeskClient`
(HTTP-Client mit Authentifizierung über den HTTP-Header Authorization, die frühere Übergabe des Tokens in der URL ist laut
sevdesk-API-News vom Februar 2025 abgekündigt und wird nicht verwendet, kein OAuth) und `SevdeskSource` als vollständige
Umsetzung von `InvoiceSource`. Grundlage sind ausschließlich Sekundärquellen (GitHub-Spiegel einer sevdesk-OpenAPI-
Beschreibung „j-mastr/sevdesk-api", Community-SDKs, Recherche vom 07.09.2026), weil weder api.sevdesk.de noch
tech.sevdesk.com aus der Entwicklungsumgebung erreichbar waren und es kein sevdesk-Testkonto gibt. Jede Annahme zu
Endpunkten, Parametern und Feldern ist im Endpunktregister (Abschnitt 5b) mit Quelle und Prüffrage geführt und im Code mit
„ANNAHME" markiert.

Fähigkeiten (`SevdeskSource::capabilities()`): `read_customers`, `read_open_invoices` und `detect_changes` sind aktiv,
sobald die Verbindung freigegeben ist (Schalter `sevdesk_connect` oder automatischer Freigabetermin, siehe Abschnitt 5c
und `app/integration_state.php`). `read_open_amount` kommt erst hinzu, wenn `platform_settings.sevdesk_api_verified` auf
`'1'` gesetzt ist (`SevdeskSource::paymentsVerified()`).

Geldsicherheit: `getPayment()` liefert den offenen Restbetrag (sumGross minus paidAmount, ANNAHME der Feldnamen)
ausschließlich, wenn `sevdesk_api_verified` gesetzt ist; ohne diese Bestätigung liefert die Methode `open_amount = null`,
und null bedeutet nach dem Vertrag von `app/invoice_source.php`: kein Einzug. Zusätzlich bleibt `sevdesk_collections`
(Standard 0, `app/integration_state.php`) der anbieterbezogene Not-Aus für neue Einzüge, unabhängig von
`sevdesk_api_verified`. Ein Irrtum bei einer der Annahmen im Endpunktregister kann dadurch höchstens zu falsch gelesenen
Rechnungsdaten führen, nie zu einem unbeabsichtigten Einzug.

**Blocker (unverändert): Es gibt kein sevdesk-Testkonto im Projekt.** Ohne ein echtes Konto ist keine Annahme des
Endpunktregisters bestätigt; entsprechend bleibt `sevdesk_api_verified` unbesetzt und der Einzug gesperrt.

## 5a. Checkliste sevdesk-Testkonto (Betreiber)

1. sevdesk-Konto anlegen oder bestehendes nutzen; nach der offiziellen sevdesk-Hilfe wird der API-Zugang für die
   Systemversion 2.0 im Tarif Buchhaltung Pro angeboten (aktuelle Bedingungen dort prüfen).
2. API-Token nach der sevdesk-Hilfe erzeugen; Übergabe ausschließlich an den Entwickler über einen sicheren Kanal
   (nie per Chat, nie ins Repository), Ablage nur verschlüsselt in `integrations` beziehungsweise für Tests in
   `shared/config.php` des Staging-Servers.
3. Im Testkonto Beispieldaten anlegen: mindestens drei Kontakte, offene Rechnungen in Euro mit unterschiedlicher
   Fälligkeit, eine teilbezahlte, eine stornierte, eine Gutschrift.
4. Danach verifiziert der Entwickler Basisadresse, Headerform (`config('sevdesk')`), Endpunkte und Felder des bereits
   gebauten Adapters (Endpunktregister, Abschnitt 5b) gegen die offizielle Dokumentation und das Testkonto; erst danach
   setzt der Betreiber `sevdesk_api_verified` (Ablauf: Abschnitt 5c).

## 5b. Endpunktregister (Stand 07.09.2026, Sekundärquellen)

Grundlage aller Zeilen: GitHub-Spiegel einer sevdesk-OpenAPI-Beschreibung („j-mastr/sevdesk-api"), Community-SDKs und
Recherche vom 07.09.2026, weil api.sevdesk.de und tech.sevdesk.com aus der Entwicklungsumgebung nicht erreichbar waren.
Jede Zeile gilt als Annahme mit Prüffrage, bis ein Testkonto sie bestätigt (Abschnitt 5a). Alle Aufrufe sind lesend (GET).

| Zweck | Endpunkt und Parameter | verwendete Felder | Normalisierung | Quelle/Status |
|---|---|---|---|---|
| Authentifizierung (alle Aufrufe) | Header `Authorization: <Token>` ohne Präfix, optional `X-Version` aus `config('sevdesk')['x_version']`; Basisadresse `config('sevdesk')['base_url']` | keine (HTTP-Header) | kein „Bearer"-Präfix, kein OAuth | Header-Form nach sevdesk-API-News Februar 2025 gesichert (nur Titel einsehbar, Details nicht geprüft); Basisadresse ANNAHME `https://my.sevdesk.de/api/v1`. Prüffrage: Ist die Basisadresse vollständig und stabil (Region, Versionierung)? |
| Verbindungstest (`getProfile()`) | `GET Contact?limit=1&countAll=true` | `total` | `companyName` bleibt `null` (kein Firmenname übermittelt), `reachable = true` bei HTTP 200 | ANNAHME: kein Endpunkt für die Kontoidentität belegt. Prüffrage: Gibt es einen Endpunkt, der den Firmennamen liefert? |
| Offene Rechnungen, Seite „open" | `GET Invoice?status=200&limit=100&offset=N&countAll=true` | `id`, `invoiceNumber`, `invoiceType`, `update` | nur `invoiceType = RE`; Status 200 → `open` oder `overdue` je Fälligkeit | ANNAHME Statuscode 200 = offen (Spiegel-OpenAPI). Prüffrage: Gilt 200 als „offen" bei allen Rechnungsarten (Mahnung, Teil-, Anzahlungs-, Schlussrechnung, wiederkehrend)? |
| Offene Rechnungen, Seite „overdue" (teilbezahlt) | `GET Invoice?status=750&limit=100&offset=N&countAll=true` | wie vor | Status 750 → `open` (erscheint als offen; Einzug erst mit verifiziertem Restbetrag, sonst gesperrt) | ANNAHME Statuscode 750 = teilbezahlt. Prüffrage: Ist 750 wirklich der Teilzahlungsstatus, nicht z. B. „angemahnt"? |
| Rechnungsdetail (`getInvoiceDetail()`) | `GET Invoice/{id}?embed=contact` | `id`, `invoiceNumber`, `status`, `invoiceDate`, `payDate`, `timeToPay`, `update`, `currency`, `sumGross`, `paidAmount`, `contact{id,...}`, `addressName` | `voucherStatus` über `mapStatus()`, `dueDate` über `payDate` oder `invoiceDate + timeToPay` Tage, `totalGrossAmount = sumGross` | ANNAHME Parameter `embed=contact` liefert den eingebetteten Kontakt. Prüffrage: Existiert `embed` wirklich, oder muss der Kontakt separat abgerufen werden? |
| Rechnungspositionen | `GET InvoicePos?invoice[id]=..&invoice[objectName]=Invoice&limit=200` | `name`, `text`, `quantity`, `price`, `taxRate` | `lineItems` (Bezeichnung, Beschreibung, Menge, Preis, Steuersatz), `type = custom`; schlägt der Aufruf fehl, bleibt die Rechnung ohne Positionen verwendbar | ANNAHME Filterform `invoice[id]`/`invoice[objectName]` (Spiegel-OpenAPI). Prüffrage: Liefert dieser Filter zuverlässig nur die Positionen der angefragten Rechnung? |
| Kontakt (`getContact()`) | `GET Contact/{id}` | `name`, `surename`, `familyname`, `customerNumber` | `company.name` beziehungsweise `person.firstName`/`lastName`, Kundennummer; ohne Kundennummer Laufkundennummer 10001 (wie bei Lexware) | ANNAHME: `surename` = Vorname (unübliche Schreibweise laut Spiegel-OpenAPI). Prüffrage: Ist „surename" tatsächlich der Vorname und nicht, wie der englische Wortsinn nahelegt, der Nachname? |
| E-Mail-Adresse | `GET CommunicationWay?contact[id]=..&contact[objectName]=Contact&type=EMAIL&limit=5` | `value`, `type` | erste gültige E-Mail-Adresse als geschäftliche Adresse; schlägt der Aufruf fehl, bleibt die E-Mail leer | ANNAHME Typwert `EMAIL` (Spiegel-OpenAPI). Prüffrage: Heißt der Typwert wirklich „EMAIL", und liefert der Filter zuverlässig alle Adressen des Kontakts? |
| Zahlungsstand (`getPayment()`) | `GET Invoice/{id}` | `status`, `sumGross`, `paidAmount`, `currency` | offener Betrag = `sumGross minus paidAmount`, nur wenn `sevdesk_api_verified = '1'` und Status 200 oder 750 | ANNAHME Feldnamen `sumGross`/`paidAmount` (Spiegel-OpenAPI). Prüffrage: Heißen die Felder wirklich so, und ist `paidAmount` bei Teilzahlungen zuverlässig gepflegt? |

## 5c. Freigabe und Betrieb (4.38)

**Schalterlogik (`platform_settings`, `app/integration_state.php`):**

- **`sevdesk_connect = 'pilot'`** (seit 4.42, Migration 030, Entscheidung 08.09.2026): Pilotphase. Verbinden und Wechseln zu
  sevdesk dürfen nur Firmen, die ein aktives Mitglied mit Administratorrecht der Plattform haben (`users.is_superadmin = 1`
  oder `platform_role = 'admin'`), sowie Firmen aus der Liste `sevdesk_pilot_orgs` (kommagetrennte Firmenkennungen).
  Registrierung mit `integration=sevdesk` führt im Pilot zur Vormerkung. Der Scheduler reiht nur Pilotfirmen ein. Mit dem
  Freigabetermin `sevdesk_release_at` endet der Pilot von selbst, danach gilt die Verbindung für alle; `1` öffnet sofort,
  `0` sperrt. Prüfung je Firma: `integration_connect_allowed('sevdesk', $tenantId)` (`app/integration_state.php`).
- **`sevdesk_connect`** (Verbindung und Lesen): Ein ausdrücklich gesetzter Wert (`0`, `1` oder `pilot`) hat immer Vorrang. Ohne
  einen solchen Wert greift automatisch der Freigabetermin `sevdesk_release_at` (von Migration 028 mit `2026-09-30`
  angelegt, geprüft als Kalendertag 00:00 Uhr Europe/Berlin, `integration_release_reached()`); ab diesem Tag ist die
  Verbindung ohne weiteres Zutun freigegeben. Der Termin ist ein interner Standardwert, jederzeit per SQL änderbar, und
  gibt ausschließlich das Verbinden und Lesen frei (Kontakte, offene Rechnungen, Änderungserkennung), niemals den Einzug.
- **`sevdesk_api_verified`**: Erst wenn dieser Schalter auf `'1'` gesetzt ist, liefert `getPayment()` einen offenen
  Restbetrag; ohne ihn bleibt `open_amount` immer `null`, was nach dem Vertrag von `app/invoice_source.php` keinen Einzug
  erlaubt. Migration 028 legt diesen Schalter bewusst nicht an; er hat keinen automatischen Freigabetermin und wird
  ausschließlich vom Betreiber gesetzt, nachdem die Felder `sumGross`/`paidAmount` an einem echten Konto bestätigt sind.
- **`sevdesk_collections`**: Standard 0 (`INTEGRATION_SWITCHES`, `app/integration_state.php`), unabhängig von den beiden
  vorgenannten Schaltern; wirkt als eigener anbieterbezogener Not-Aus für neue Einzüge über sevdesk, ohne Lexware oder
  laufende Vorgänge zu berühren.

**Reihenfolge für den Betreiber:**

1. sevdesk-Testkonto beschaffen (Abschnitt 5a).
2. Endpunkte am echten Konto prüfen (Endpunktregister, Abschnitt 5b, jede Prüffrage einzeln beantworten).
3. `sevdesk_api_verified` setzen, sobald die Zahlungsfelder bestätigt sind.
4. Pilotfirmen: gezielt `sevdesk_connect` für ausgewählte Firmen setzen oder den automatischen Freigabetermin abwarten.
5. `sevdesk_collections` erst danach für den breiten Einzug öffnen.

**Betrieb:** Hintergrundverarbeitung über den eigenen Jobtyp `sync_run_sevdesk` (gleicher Handler `job_sync_run()` wie
`sync_run`, `app/jobs.php`), eigener Worker-Pool `sevdesk`, Container `worker-sevdesk` (`deploy/vps/docker-compose.yml`,
Staging `mem_limit: 256m`, `cpus: 0.15`, `docker-compose.staging.yml`). Der Scheduler (`scheduler_auto_sync()`,
`app/jobs.php`) reiht sevdesk-Firmen nur ein, wenn `integrations.sevdesk_connected = 1` **und** die Verbindung
freigegeben ist (`integration_switch('sevdesk', 'connect')`); gemeinsamer `dedupe_key` `sync:<firma>` mit Lexware
verhindert doppelte gleichzeitige Synchronisationsläufe derselben Firma. Drosselung konservativ 2 Anfragen je Sekunde und
Konto (`config('queue')['sevdesk_per_second']`, ANNAHME, sevdesk veröffentlicht keine feste Grenze), Circuit Breaker
`api_call_gate('sevdesk')`, Monitoring-Komponente `sevdesk_api` (instrumentierte Aufrufe). Test: `bash
tools/sevdesk-check.sh` (Stub-Server `tools/lib/sevdesk-stub.php`, Simulation `tools/lib/sevdesk-sim.php`, gegen
temporäre MariaDB).

## 6. Launch und Rollback

Launch: `sevdesk_public_state` auf `verfuegbar`, Seite von Vormerkung auf Einrichtung umstellen (URL bleibt), Startnachricht
nur an bestätigte, nicht gesperrte Kontakte, keine Firmenaccounts oder Abonnements aus der Warteliste. Rollback: Schalter
zurücksetzen (`sevdesk_collections = 0` stoppt neue Einzüge sofort), Code-Rollback über `deploy/vps/scripts/rollback.sh`;
Migration 020 ist additiv. Tests: `bash tools/interest-check.sh`, `python3 tools/site-qa.py`, `php tools/pricing-check.php`.
