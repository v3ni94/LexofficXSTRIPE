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
- Produktsteuercode im Dashboard prüfen (Software als Dienstleistung). Der Code wird bewusst nicht vom
  Werkzeug gesetzt, damit keine falsche Einstufung entsteht.
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

## Rücknahme

`billing.enabled = false` schaltet die Abrechnung sofort wieder aus: Keine Sperre, kein Hinweisbalken,
keine Abo-Seite; bestehende Stripe-Abonnements laufen davon unberührt weiter und müssten im
Stripe-Dashboard gekündigt werden. Die Preis-IDs in `plans` bleiben erhalten.

## Laufender Betrieb

- `bin/billing-check.php` nach jeder Änderung an Tarifen, Schlüsseln oder Webhooks ausführen.
- Das Feld `environment` in `shared/config.php` sollte auf `'prod'` stehen. In Produktion ist es aus
  Rückwärtskompatibilität nicht zwingend (`bin/healthcheck.php --expect-env=prod` beanstandet sein Fehlen
  nicht), für Staging dagegen Pflicht. Gesetzt ist es eindeutiger und die Prüfberichte nennen die Umgebung.
- `invoice.payment_failed` setzt den Status auf `past_due`; die Firma arbeitet bis zum Ende der bezahlten
  Periode weiter (`subscription_period_end`), danach greift die Sperre.
- Tarifwechsel wirken sofort: Upgrade mit sofortiger anteiliger Berechnung, Downgrade mit anteiliger
  Gutschrift auf die nächste Rechnung. Upsell und Wechsel erscheinen nur, wenn mindestens zwei Tarife
  aktiv und öffentlich sind.
