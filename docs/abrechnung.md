# Plattform-Abrechnung scharf schalten (Abonnement der Firmen)

Betreiber: Müller Holding AG. Diese Anleitung beschreibt die Inbetriebnahme des Abonnements, mit dem
Firmenaccounts SmartEinzug bezahlen (Tarif UNLIMITED START, Einführungspreis 25,00 EUR netto je 4 Wochen für Firmenaccounts, die bis zum Ende des laufenden Kalendermonats angelegt werden, alle Preise netto zzgl. USt.; der Stichtag rollt monatlich weiter, siehe `php-ionos/app/pricing.php` und `tools/pricing-check.php`). Sie ist getrennt von den
Stripe-Konten der Firmen, über die diese ihre eigenen SEPA-Einzüge abwickeln.

Rechtliche und steuerliche Fragen (Umsatzsteuer, Reverse Charge, AGB, Widerruf) sind mit Steuerberater
bzw. Rechtsanwalt abzustimmen; die Angaben hier sind eine technische Einschätzung, keine Beratung.

## Was in der Anwendung bereits vorhanden ist

| Bestandteil | Ort |
|---|---|
| Abo-Seite des Inhabers (Tarif, Status, Bestellbestätigung, Rechnungen, Kündigung, Tarifwechsel) | `php-ionos/subscription.php` |
| Hinweisbalken „Jetzt freischalten“ bzw. „Vertrag aktivieren“ | `php-ionos/app/layout.php` |
| Checkout, Kundenportal, Tarifwechsel, Ereignisverarbeitung | `php-ionos/app/billing.php` |
| Webhook-Endpunkt des Plattformkontos | `php-ionos/billing-webhook.php` |
| Tarife samt Stripe-Preis-ID (Pflege im Adminbereich) | Tabelle `plans`, `php-ionos/admin.php` |
| Sperre ohne nutzbares Abonnement | `subscription_allows_operation()`, `require_subscription()` |
| Prüfung der Inbetriebnahme (nur lesend) | `php-ionos/bin/billing-check.php` |
| Anlage von Produkt und Preis in Stripe (wiederholbar) | `php-ionos/bin/billing-setup-stripe.php` |
| Regressionstest der Prüf- und Anlagelogik | `tools/billing-setup-check.php` |

## Reihenfolge der Inbetriebnahme

Alle Befehle laufen im Anwendungscontainer des VPS, zum Beispiel:

```bash
cd /opt/smarteinzug/deploy
export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env \
  exec -T php php bin/billing-check.php
```

### Schritt 1: Zuerst mit Testschlüsseln, nicht im Live-Konto

In `shared/config.php` den Abschnitt `billing` mit den **Test**schlüsseln des Stripe-Kontos füllen
(`sk_test_...`), `enabled` zunächst auf `false` lassen. Beide Werkzeuge arbeiten bewusst unabhängig von
diesem Schalter (`billing_setup_client()`), damit Prüfung und Anlage vor dem Scharfschalten möglich sind;
die Anwendung selbst rechnet ohne `enabled` weiterhin nichts ab. Danach `bin/billing-check.php`: Es prüft
Schlüsselart, Signaturgeheimnis, Basisadresse, Konto, Preise, Webhook, Kundenportal, Stripe Tax und
meldet, wie viele Firmen beim Scharfschalten gesperrt würden. Schlüssel erscheinen nur maskiert.

### Schritt 2: Stripe Tax und Kundenportal im Stripe-Dashboard einrichten

- Stripe Tax aktivieren und die eigene Steuerregistrierung (Deutschland) eintragen. Die Tarifpreise sind
  Nettopreise; Stripe rechnet die Umsatzsteuer anhand der Rechnungsadresse zusätzlich auf
  (`tax_behavior = exclusive`, `automatic_tax` in der Konfiguration).
- **Art der Registrierung beachten.** Für Umsätze im eigenen Land ist eine Standardregistrierung nötig. Eine
  One-Stop-Shop-Registrierung (OSS) gilt ausschließlich für grenzüberschreitende Umsätze in andere EU-Staaten;
  liegt nur sie vor, weist Stripe auf dem Beleg „Steuerpflicht: nicht registriert“ und 0,00 EUR aus.
  `bin/billing-check.php` nennt seit 4.49 die Art je Registrierung und meldet diesen Fall als Fehler.
- **Steuerregistrierung eintragen (entscheidend).** Stripe berechnet Umsatzsteuer nur für Länder, in denen
  eine aktive Registrierung hinterlegt ist. Fehlt sie, bleibt der Checkout beim Nettobetrag, obwohl Stripe Tax
  den Status „active“ meldet und `automatic_tax` eingeschaltet ist (Vorfall 09.09.2026: erster echter Kauf
  über 25,00 EUR statt 29,75 EUR). Im Dashboard unter Steuern, Registrierungen die deutsche Registrierung
  anlegen. `bin/billing-check.php` liest seit 4.47 `/tax/registrations` mit und meldet eine fehlende
  Registrierung als Fehler.
- Produktsteuercode im Dashboard prüfen (Software als Dienstleistung). Der Code wird bewusst nicht vom
  Werkzeug gesetzt, damit keine falsche Einstufung entsteht; es gilt dann der Standard-Steuercode aus den
  Stripe-Tax-Einstellungen. Ist dort ein nicht steuerbarer Code hinterlegt, weist der Checkout 0,00 EUR
  Steuer aus, obwohl Registrierung und Rechnungsadresse stimmen. `bin/billing-check.php` nennt den Code seit
  4.48 und warnt, wenn keiner gesetzt ist.
- Die Steuer erscheint im Checkout erst, wenn der Kunde seine Rechnungsadresse eingegeben hat. Vor diesem
  Schritt zeigt Stripe die Zeile „Steuer 0,00 EUR“; das ist kein Fehler.
- Kundenportal konfigurieren und speichern; die Anwendung verlinkt es für Rechnungen, Zahlungsmethode
  und Kündigung. Ohne gespeicherte Konfiguration scheitert der Aufruf des Portals.

### Schritt 3: Produkt und Preis anlegen

```bash
# Trockenlauf: zeigt genau, was angelegt würde
docker compose ... exec -T php php bin/billing-setup-stripe.php
# Anlage im Testkonto
docker compose ... exec -T php php bin/billing-setup-stripe.php --apply
```

Das Werkzeug liest Betrag, Periode und Bezeichnung ausschließlich aus der Tabelle `plans` und legt je
buchbarem Tarif (`active = 1` und `public_visible = 1`) an:

- Produkt `SmartEinzug <Tarifname>` mit `metadata[lexsepa_plan]`
- Preis in EUR, `unit_amount` gleich `price_cents`, wiederkehrend `interval = day` mit
  `interval_count = period_days` (28 Tage), `tax_behavior = exclusive`, `lookup_key = lexsepa_<code>`

Anschließend trägt es die Preis-ID in `plans.stripe_price_id` ein und protokolliert das im Audit. Der
Aufruf ist wiederholbar: Über den `lookup_key` erkennt er einen bereits vorhandenen Preis und legt
keinen zweiten an. Beträge bestehender Stripe-Preise sind unveränderlich; ein geänderter Tarifpreis
braucht deshalb einen neuen Preis, was `bin/billing-check.php` als Abweichung meldet.

### Schritt 4: Webhook einrichten

Im Stripe-Dashboard einen Endpunkt auf `https://app.smart-einzug.de/billing-webhook.php` anlegen und
genau diese Ereignisse abonnieren:

```
checkout.session.completed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.payment_failed
```

Das Signaturgeheimnis (`whsec_...`) in `shared/config.php` als `billing.stripe_webhook_secret` eintragen.
Ohne Webhook bleibt ein abgeschlossenes Abonnement in der Anwendung unbekannt (Status `pending`), die
Firma bliebe gesperrt, obwohl sie bezahlt hat. `bin/billing-check.php` prüft Adresse, Zustand und die
fünf Ereignisse.

### Schritt 5: Vollständiger Durchlauf im Testmodus

Mit einem Testfirmenaccount: Hinweisbalken, `subscription.php?bestellen=1`, Bestellbestätigung,
Checkout mit einer Stripe-Testzahlungsmethode, Rückkehr, Status `active`, Rechnungsliste, Kundenportal,
Kündigung vormerken und zurücknehmen. Danach `bin/billing-check.php` erneut: keine Fehler.

### Schritt 6: Bestehende Firmen vor dem Scharfschalten schützen

Sobald `billing.enabled` gesetzt ist, wird jede Firma ohne nutzbares Abonnement gesperrt: Der Inhaber
landet auf der Abo-Seite, Mitarbeiter sehen einen Hinweis. Das betrifft auch bestehende, produktiv
arbeitende Firmen mit Status `pending`. Vorher entscheiden:

- Firmen, die weiterarbeiten sollen, im Adminbereich auf `billing_exempt = 1` setzen (dauerhaft befreit,
  zum Beispiel eigene Gesellschaften), oder
- ihnen vorher ein Abonnement abschließen lassen, oder
- den Zeitpunkt so wählen, dass niemand unvorbereitet gesperrt wird.

`bin/billing-check.php` nennt die betroffenen Firmen mit Namen und Status (bis zu 20) und gibt die Zahl
aus. Solange `enabled` aus ist, ist das eine Warnung; ist `enabled` gesetzt und es sind noch Firmen
betroffen, ist es ein Fehler.

### Schritt 7: Live schalten

Live-Schlüssel (`sk_live_...`) und das Live-Signaturgeheimnis eintragen, Produkt und Preis im Live-Konto
anlegen (`--apply --live-bestaetigt`, die zweite Bestätigung ist bei Live-Schlüsseln Pflicht), Webhook im
Live-Konto einrichten, `bin/billing-check.php` ohne Fehler, dann `billing.enabled = true` setzen. Danach
sofort erneut prüfen und den Hinweisbalken sowie eine Bestellung mit einem eigenen Account kontrollieren.

## Weitere Tarife anlegen

Tarife entstehen ausschließlich in der Tabelle `plans` (Adminbereich, Tarife). Ausgeliefert werden neben
UNLIMITED START vier inaktive Entwürfe (BASIC, PLUS, PRO, UNLIMITED) mit Platzhalterbeträgen aus der
Erstinstallation. Reihenfolge für einen weiteren Tarif:

1. Betrag, Periode, Grenzen und Bezeichnung im Adminbereich festlegen. Erst danach in Stripe anlegen: Betrag
   und Intervall eines Stripe-Preises sind unveränderlich, ein zu früh angelegter Preis muss später ersetzt
   werden.
2. Produkt und Preis anlegen. Ohne `--tarif` erfasst das Werkzeug nur buchbare Tarife (`active = 1` und
   `public_visible = 1`); ein noch nicht öffentlicher Tarif wird gezielt benannt:

```bash
docker compose ... exec -T php php bin/billing-setup-stripe.php --tarif=plus
docker compose ... exec -T php php bin/billing-setup-stripe.php --tarif=plus --apply --live-bestaetigt
```

3. Tarif im Adminbereich auf aktiv und öffentlich setzen. Ab zwei aktiven, öffentlichen Tarifen zeigt die
   Anwendung Upsell und Tarifwechsel (`billing_change_plan`, `plan_upgrade_candidate`).
4. `bin/billing-check.php` ohne Fehler.

## Preis eines Tarifs ändern

**Eine Preisänderung wird nicht automatisch an Stripe weitergegeben.** In Stripe sind Betrag und Intervall
eines Preises unveränderlich. Bliebe dieselbe Preis-ID stehen, zeigte die Anwendung den neuen Betrag, Stripe
berechnete aber weiter den alten, auch bei NEUEN Bestellungen. Deshalb gilt seit 4.46:

- Der Adminbereich **speichert** eine Änderung von Betrag oder Periode **nicht**, solange dieselbe
  Stripe-Preis-ID eingetragen bleibt, und nennt die beiden zulässigen Wege.
- Der Ersatzpreis entsteht mit `--preis-neu`: neuer Preis auf demselben Produkt mit dem Betrag aus `plans`,
  Übernahme des `lookup_key` (`transfer_lookup_key`), Eintrag der neuen Preis-ID, danach Archivierung des
  alten Preises. Schlägt ein Schritt fehl, bleibt der alte, funktionierende Preis eingetragen.

```bash
# 1. Neuen Betrag im Adminbereich eintragen scheitert noch (gewollt). Zuerst den Ersatzpreis anlegen:
docker compose ... exec -T php php bin/billing-setup-stripe.php --tarif=unlimited_start --preis-neu
docker compose ... exec -T php php bin/billing-setup-stripe.php --tarif=unlimited_start --preis-neu --apply --live-bestaetigt
docker compose ... exec -T php php bin/billing-check.php
```

Reihenfolge in der Praxis: Betrag zuerst in `plans` ändern ist nicht möglich, solange die Preis-ID steht.
Entweder die Preis-ID im Formular leeren (Tarif ist dann bis zur Neuanlage nicht buchbar) und anschließend
`bin/billing-setup-stripe.php --tarif=CODE --apply` aufrufen, oder den Betrag über die Datenbank
setzen und sofort `--preis-neu` ausführen. Der zweite Weg lässt keinen Zeitraum entstehen, in dem der Tarif
nicht buchbar ist.

**Laufende Abonnements behalten den alten Preis.** Stripe rechnet bestehende Abonnements über den Preis ab,
mit dem sie angelegt wurden, auch wenn dieser archiviert ist. Eine Preisanpassung gegenüber Bestandskunden
ist eine kaufmännische und vertragliche Entscheidung (Ankündigungsfrist, Zustimmung, AGB) und geschieht
bewusst nicht automatisch; sie wird in Stripe je Abonnement oder über einen Tarifwechsel in der Anwendung
vollzogen.

## Rücknahme

`billing.enabled = false` schaltet die Abrechnung sofort wieder aus: Keine Sperre, kein Hinweisbalken,
keine Abo-Seite; bestehende Stripe-Abonnements laufen davon unberührt weiter und müssten im
Stripe-Dashboard gekündigt werden. Die Preis-IDs in `plans` bleiben erhalten.

## Laufender Betrieb

- `bin/billing-check.php` nach jeder Änderung an Tarifen, Schlüsseln oder Webhooks ausführen. Es meldet einen
  abweichenden Betrag als Fehler (`Stripe berechnet X, der Tarif nennt Y`).
- Das Feld `environment` in `shared/config.php` sollte auf `'prod'` stehen. In Produktion ist es aus
  Rückwärtskompatibilität nicht zwingend (`bin/healthcheck.php --expect-env=prod` beanstandet sein Fehlen
  nicht), für Staging dagegen Pflicht. Gesetzt ist es eindeutiger und die Prüfberichte nennen die Umgebung.
- `invoice.payment_failed` setzt den Status auf `past_due`; die Firma arbeitet bis zum Ende der bezahlten
  Periode weiter (`subscription_period_end`), danach greift die Sperre.
- Tarifwechsel wirken sofort: Upgrade mit sofortiger anteiliger Berechnung, Downgrade mit anteiliger
  Gutschrift auf die nächste Rechnung. Upsell und Wechsel erscheinen nur, wenn mindestens zwei Tarife
  aktiv und öffentlich sind.
