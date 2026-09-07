# sevdesk-Erweiterung: Bestandsaufnahme, Zielarchitektur, Freigaben und Betrieb

Stand: 07.09.2026. Grundlage ist der Masterplan „SmartEinzug: sevdesk-Erweiterung“ (Planungsstand 07.09.2026).
Ende September 2026 ist ein Planungsziel, kein Freigabekriterium: Ohne erfolgreiche Abnahme bleibt die Anbindung
angekündigt oder in einer begrenzten Betaphase.

## 1. Bestandsaufnahme (gegen den Code geprüft am 07.09.2026)

| Bereich | Stand | Bewertung | Umsetzungsschritt |
|---|---|---|---|
| Adaptergrenze Buchhaltung | vorhanden: Interface `InvoiceSource`, `LexwareOfficeSource`, Registry `integration_providers` (lexware_office released, sevdesk planned), `integrations.invoice_source` | tragfähig | `SevdeskSource` als Gerüst ohne Fähigkeiten angelegt (`app/sevdesk.php`); Freigabe erst nach Verifikation mit Testkonto |
| Stripe-Anbindung | vorhanden: eigener geheimer Schlüssel je Firma, verschlüsselt (`integrations.stripe_secret_key_encrypted`), optional `stripe_account_id`; kein Connect | passt zum Zielbild (Einzug über eigenes Konto des Kunden, kein Sammelkonto) | keine Änderung |
| Plattform-Abrechnung getrennt vom Forderungseinzug | vorhanden: `app/billing.php` (Stripe-Konto der Müller Holding AG) getrennt von `app/collections.php` | erfüllt | keine Änderung |
| Benutzer, Firmen, Mitgliedschaften, Rollen, 2FA, Gerätevertrauen 90 Tage | vorhanden (`organization_members`, `trusted_devices`, `require_recent_totp`) | erfüllt | unverändert lassen |
| Registrierungspfade | vorhanden: `register.php` mit `src=` (Marketingherkunft) | fehlte: Anbieter-Vorauswahl | `integration=` aus fester Liste (`lexware_office`, `sevdesk`) getrennt in `$_SESSION['signup']['integration']`; sevdesk führt vor Freigabe zur Vorregistrierung |
| Öffentliche sevdesk-Seite | vorhanden `/integrationen/sevdesk/` (bis 4.17 noindex, Kurztext) | ausgebaut | Landingpage nach Masterplan 6 mit zwei Formularen, Voraussetzungen, Abgrenzung, Fragen; Startseiten-Teaser; Übersicht |
| Vorregistrierung | seit 4.18 vorhanden, 4.19 erweitert | erfüllt Masterplan 7 | getrennte Token (Bestätigung 7 Tage, Abmeldung dauerhaft), Name optional, freiwillige Angaben nach Bestätigung, Sperrvermerk, Double-Opt-in per Button |
| Adminverwaltung Interessenten | 4.19 | erfüllt Masterplan 8 in Teilen | Suche, Filter, CSV (formelsicher), Einwilligungsnachweis, Abmelden, Sperren, Löschen, Betaeinladung, Kennzahlen, Audit. Offen: Versandwerkzeug für Startnachricht |
| Freigabeschalter | fehlte | angelegt | `platform_settings`: `sevdesk_public_state`, `sevdesk_waitlist`, `sevdesk_connect`, `sevdesk_collections`, `sevdesk_writeback` (`app/integration_state.php`) |
| Hintergrundjobs | vorhanden: Warteschlange, Workertypen `lexware`, `stripe`, `mail`, `maintenance`, Circuit Breaker je API | Erweiterung nötig | Workertyp `sevdesk` und `api_call_gate('sevdesk', ...)` beim Adapter (Gerüst nutzt bereits das Gate) |
| Tarife | vorhanden: Tabelle `plans`, Einführungspreis mit rollierendem Stichtag (`intro_price_deadline()`) | Repository einheitlich; **kritisch**: live zeigte lexoffice-einzug.de am 07.09.2026 noch „31.12.2026“ | Im Repository gibt es kein festes Datum mehr (`php tools/pricing-check.php`). Zu prüfen: Lauf des Jobs `deploy-webhosting` und Stand auf dem IONOS-Webhosting; keine neue sevdesk-Preisgarantie |
| sevdesk-Testkonto, API-Verifikation | **fehlt** | **Blocker** für Adapter, Rechnungsabgleich, Einzug | Testkonto mit API-Zugang beschaffen (nach sevdesk-Hilfe Tarif Buchhaltung Pro, Systemversion 2.0), Endpunkte und Felder verifizieren |
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

`app/sevdesk.php`: `SevdeskClient` authentifiziert über den HTTP-Header Authorization (die frühere Übergabe des Tokens in
der URL ist laut sevdesk-API-News vom Februar 2025 abgekündigt und wird nicht verwendet; kein OAuth erfunden). Basisadresse
und Headerform kommen aus `config('sevdesk')` und sind mit dem Testkonto zu verifizieren. `SevdeskSource` meldet keine
Fähigkeiten und wirft in jeder fachlichen Methode, bis Endpunkte, Felder (Rechnungsarten, Fälligkeit, offener Restbetrag,
Teilzahlungen, Stornos, Gutschriften, Kontaktbezug, Filter, Seitennavigation) und das Verhalten bei Drosselung gegen die
offizielle Dokumentation und ein Testkonto bestätigt sind. **Blocker: Es gibt kein sevdesk-Testkonto im Projekt.**

## 5a. Checkliste sevdesk-Testkonto (Betreiber)

1. sevdesk-Konto anlegen oder bestehendes nutzen; nach der offiziellen sevdesk-Hilfe wird der API-Zugang für die
   Systemversion 2.0 im Tarif Buchhaltung Pro angeboten (aktuelle Bedingungen dort prüfen).
2. API-Token nach der sevdesk-Hilfe erzeugen; Übergabe ausschließlich an den Entwickler über einen sicheren Kanal
   (nie per Chat, nie ins Repository), Ablage nur verschlüsselt in `integrations` beziehungsweise für Tests in
   `shared/config.php` des Staging-Servers.
3. Im Testkonto Beispieldaten anlegen: mindestens drei Kontakte, offene Rechnungen in Euro mit unterschiedlicher
   Fälligkeit, eine teilbezahlte, eine stornierte, eine Gutschrift.
4. Danach verifiziert der Entwickler Basisadresse, Headerform (`config('sevdesk')`), Endpunkte und Felder gegen die
   offizielle Dokumentation und füllt `SevdeskSource`; erst dann wird `sevdesk_connect` für Pilotfirmen gesetzt.

## 6. Launch und Rollback

Launch: `sevdesk_public_state` auf `verfuegbar`, Seite von Vormerkung auf Einrichtung umstellen (URL bleibt), Startnachricht
nur an bestätigte, nicht gesperrte Kontakte, keine Firmenaccounts oder Abonnements aus der Warteliste. Rollback: Schalter
zurücksetzen (`sevdesk_collections = 0` stoppt neue Einzüge sofort), Code-Rollback über `deploy/vps/scripts/rollback.sh`;
Migration 020 ist additiv. Tests: `bash tools/interest-check.sh`, `python3 tools/site-qa.py`, `php tools/pricing-check.php`.
