# Datenvertrag SmartEinzug (Backend an Frontend)

Vertragsversion 1.0, Stand 13.09.2026. Basis-Commit e29e5d8 (4.75), Arbeitsbranch `backend/masterprompt-2026-09-13` (4.79).
Verantwortlich für diese Datei ist der Backend-Chat. Der Frontend-Chat liest sie nur und trägt Änderungswünsche in
`docs/frontend/backend-anfragen.md` ein. Änderungen an Feldern, Zustandswerten oder Endpunkten geschehen additiv und
rückwärtskompatibel mit Erhöhung der Nebenversion (1.1, 1.2); ein Entfernen oder Umbenennen erhöht die Hauptversion und
wird mindestens ein Release vorher hier angekündigt. Nichts in diesem Vertrag ist verfügbar, bevor es auf dem Zielsystem
ausgerollt ist; der Stand 4.79 liegt bis zur Freigabe nur im Arbeitsbranch.

Alle Beispiele sind synthetisch. Keine Angabe in diesem Vertrag ersetzt die serverseitige Berechtigungsprüfung: Das
Frontend darf Aktionen ausblenden, der Server prüft jede Aktion selbst.

## 1. Dateibesitz

| Bereich | Backend (dieser Vertrag) | Frontend |
|---|---|---|
| Geschäftslogik, Serverseiten `php-ionos/*.php`, `php-ionos/app/`, `php-ionos/bin/`, `php-ionos/sql/` | ja | nur Ansichtsanteile nach Absprache, gebündelt über den Backend-Chat |
| Marketingseiten `websites/<domain>/**/*.html`, `robots.txt`, `sitemap.xml` (statisch), Texte, JSON-LD, Bilder | nein | ja |
| `websites/<domain>/assets/js/site.js`, `assets/css` | nein | ja |
| `websites/<domain>/.htaccess` (Redirects, Header, Canonical, HTTPS) | ja, auf Anfrage des Frontends | nein |
| Generatoren `tools/build-sitemaps.py`, `tools/lead-assets.py`, `tools/site-*.py`, `tools/seo-*.py` | ja | nein; Ausgabe wird vom Backend zusammen mit dem Generator erzeugt |
| `deploy/`, `.github/workflows/`, `CLAUDE.md`, `docs/contracts/`, `docs/backend/`, `docs/audit/` | ja | nein |
| `docs/frontend/`, `docs/seo/` (Faktenregister, Aussagenprüfung, Keyword-Map) | nein | ja |
| `php-ionos/app/product_facts.php`, `docs/contracts/product-facts.snapshot.json` | ja | liest |

Statische `robots.txt` und `sitemap.xml` gelten als Frontend-Inhalt, solange sie nicht generiert werden; `sitemap.xml`
wird über `tools/build-sitemaps.py` erzeugt und deshalb nur zusammen mit dem Generatorlauf geändert.

## 2. Öffentliche Produktfakten

Quelle ist das versionierte Register `php-ionos/app/product_facts.php` (Register 1.0). Zwei Übergabewege:

1. **Datei** `docs/contracts/product-facts.snapshot.json`, erzeugt mit `php php-ionos/bin/product-facts.php --export`,
   geprüft in `tools/product-facts-check.php` (Workflow-Job `test`). Für statische Seiten ist die Datei die Quelle; sie
   enthält keine Laufzeitwerte.
2. **Endpunkt** `GET https://app.smart-einzug.de/fakten.php` (Version 4.79, öffentlich, ohne Login, ohne Sitzung, kein
   Google-Skript, kein Aufruf an Stripe oder Lexware). Antwort `application/json; charset=utf-8`, `Cache-Control: public,
   max-age=300`, `X-Robots-Tag: noindex, nofollow`. Andere Methoden als GET: 405 mit `Allow: GET`. CORS: `Access-Control-
   Allow-Origin` nur für `https://<domain>` und `https://www.<domain>` aus `signup_domains` (smart-einzug.de,
   lexware-einzug.de, lexoffice-einzug.de, sevdesk-einzug.de, sevdesk-sepa.de). Auf dem Adminhost antwortet der Endpunkt
   mit 404.

Schema (beide Wege identisch, der Endpunkt ergänzt `generated_at` und `integrationen`):

```json
{
  "schema": "smarteinzug-product-facts",
  "version": "1.0",
  "app_version": "4.79",
  "geprueft_am": "13.09.2026",
  "hinweis": "…",
  "facts": [
    {"key": "produkt.name", "bereich": "produkt", "wert": "SmartEinzug", "status": "technisch_getestet", "geprueft_am": "13.09.2026"},
    {"key": "zahlung.webhook_ereignisse", "bereich": "zahlung", "wert": ["payment_intent.processing", "…"], "status": "technisch_getestet", "geprueft_am": "13.09.2026"},
    {"key": "integration.sevdesk.status", "bereich": "integration", "wert": "angekuendigt", "status": "geplant", "geprueft_am": "13.09.2026"}
  ],
  "generated_at": "2026-09-13T10:00:00Z",
  "integrationen": {"live": true, "lexware_office": {"public_state": "verfuegbar"}, "sevdesk": {"public_state": "angekuendigt", "freigabetermin": "2026-09-30"}}
}
```

Felder je Aussage: `key` (Pflicht, Bereich.Name), `bereich` (produkt, integration, zahlung, mandat, einzug, sicherheit,
tarif, grenzen), `wert` (Zeichenkette oder Liste), `status` (`technisch_getestet`, `oeffentlich_behauptet`, `geplant`),
`geprueft_am` (TT.MM.JJJJ oder null, wenn die Primärquelle noch nicht geprüft wurde). Der Zustand `ungeklaert` erscheint
nie öffentlich. `integrationen.live = false` heißt: Laufzeitwerte nicht abrufbar, `facts` trägt die Registerwerte.

Regeln: Der Produktpreis ist öffentlich (Vorgabe Betreiber 13.09.2026) und steht in `tarif.preis` immer mit Betrag,
Steuerhinweis und Periode; Vergleichs- und Streichpreise („bisher 50,00 EUR“) sind gesperrt (TARIF-07 ungeklärt) und
erscheinen nie im Snapshot. Ein fehlender Preis wäre nie als 0 EUR oder kostenlos darzustellen. `tarif.periode` (28 Tage,
nicht Kalendermonat) und `tarif.stichtag` (rollierender Satz aus `pricing.php`) sind öffentlich. Aussagen mit `status = oeffentlich_behauptet` dürfen nur mit der
Formulierung des Registers verwendet werden. Neue Aussagen beantragt das Frontend über `docs/frontend/backend-anfragen.md`.

## 3. Integrationsverfügbarkeit und Vormerkung

Öffentlicher Stand je Anbindung: `public_state` aus `angekuendigt`, `beta`, `verfuegbar`, `eingeschraenkt`
(`INTEGRATION_PUBLIC_STATES`). Quelle für sevdesk ist `platform_settings.sevdesk_public_state` (Vorgabe `angekuendigt`),
ausgeliefert über `fakten.php`. Öffentliche Formulierung für sevdesk: „in Vorbereitung, Start für Ende September 2026
geplant“, nie als Zusage; keine Preise, kein Kaufbutton, kein Firmenaccount vor Freigabe.

Vormerkung (`POST https://app.smart-einzug.de/vormerken.php`, Formular von den Marketingseiten):

| Feld | Typ | Pflicht | Regel |
|---|---|---|---|
| `email` | Zeichenkette | ja | gültige Adresse |
| `company` | Zeichenkette | nein | Name oder Firmenname, begrenzte Länge |
| `provider` | Zeichenkette | ja | Anbindungskennung, derzeit nur `sevdesk` zulässig |
| `consent` | Wert `1` | ja | Einwilligung zur Benachrichtigung (`INTEREST_CONSENT_VERSION` vormerkung-v3) |
| `src` | Zeichenkette | nein | Herkunftsdomain aus `signup_domains`, sonst aus Origin oder Referer |
| Honeypot-Feld | leer | ja | jeder Inhalt führt zur Ablehnung |

Antwort ist eine servergerenderte Seite (HTML), kein JSON: 200 mit „Bitte bestätigen Sie Ihre E-Mail-Adresse“ oder
„Vormerkung gespeichert, Bestätigungs-E-Mail folgt“; 405 bei anderer Methode als POST; Ablehnungsseite mit Grund
(`email`, `consent`, `company`, `provider`, `busy`, `honeypot`). Origin oder Referer muss eine bekannte Domain sein, sonst
„Anfrage nicht angenommen“. Double-Opt-in per Link (7 Tage gültig), keine IP-Speicherung. Ratenbegrenzung 30 Einträge je
Minute insgesamt (`busy`).

## 4. Reichweitenmessung und Conversion-Ereignisse

`POST https://app.smart-einzug.de/track.php` mit JSON `{"d": "<domain>", "e": "page_view|cta_click", "p": "<pfad>",
"c": "<cta>"}`: nur Domains aus `signup_domains`, nur die zwei Ereignisse, ohne Cookies, ohne IP. Antwort 204 oder 400.

Fachliche Ereignisse je Firma (genau einmal, `funnel_event_once`): `lexware_connected`, `sevdesk_connected`,
`stripe_connected`, `first_sync`, `first_collection`, `subscription_active`. `subscription_active` entsteht nur mit aktivem,
bezahltem Stripe-Abonnement der Firma beim Betreiber (Abo-Zahlung A); Einzüge der Firmen bei ihren Kunden (Zahlung B) sind
kein Umsatz von SmartEinzug und nie ein Conversion-Ereignis. Kostenlose Phasen, Null-Euro-Rechnungen und fehlgeschlagene
Abo-Zahlungen zählen nicht.

Google Ads: Die Conversion „Kauf (1)“ wird auf smart-einzug.de und lexware-einzug.de beim Klick auf Registrieren durch das
Frontend (`site.js`) gemeldet. Solange das so ist, bleibt `analytics.ads_conversion_label` der Anwendung leer, sonst zählt
derselbe Vorgang doppelt. Die Anwendung bindet Google-Skripte nur auf `register.php` und `vormerken.php` sowie auf
`subscription.php?bestellt=1` ein, nie im angemeldeten Bereich, nie auf Seiten mit Kennungen in der Adresse. Der Export von
Ereignissen an Werbeplattformen aus der Anwendung findet derzeit nicht statt; ein solcher Export bräuchte Rechtsgrundlage,
Consent-Status je Ereignis und Freigabe.

## 5. Zustandsbereiche (servergerendert)

Die Anwendung ist servergerendert (PHP, keine REST-Schicht). Zustände erscheinen als Text und Kennzeichen in den Seiten;
die folgenden Werte sind die verbindlichen Codes. Sie dürfen nicht zu einem pauschalen „erfolgreich“ zusammengezogen werden.

**Stripe-Verbindung der Firma** (`stripe_connection_state()`, seit 4.79): `not_connected`, `disconnected`, `auth_failed`,
`permission_missing`, `degraded`, `charges_disabled`, `sepa_unavailable`, `sepa_pending`, `unverified`, `ready`. Neue
Einzüge, Vormerkungen, IBAN-Registrierung und digitale Mandate sind nur in `ready`, `sepa_pending` und `unverified`
möglich; Statusabgleich, Webhook und Import lesen in jedem Zustand. `unverified` heißt: verbunden vor 4.79, Fähigkeiten
noch nicht geprüft. Ein gespeicherter Schlüssel oder eine Rückleitung allein ist nie „bereit“.

**Lexware Office**: `lexoffice_connected` 0/1, `lexoffice_last_verified_at`, `lexoffice_last_sync`; Fehler des
Verbindungstests erscheinen als Meldung, nicht als Zustandswert.

**Onboarding** (`onboarding.php`): Schritte in fester Reihenfolge „Konto erstellt“, „Abonnement aktivieren“ (nur mit
Plattform-Abrechnung), „Buchhaltungssystem verbinden“, „Stripe verbinden“, „Verbindungen prüfen“, „Firmendaten für
SEPA-Mandate“, „Einrichtung abgeschlossen“; `organizations.onboarding_completed` 0/1.

**Mandat** (`sepa_mandates.status`): `draft`, `active`, `cancelled`, `expired`; `mandate_type` `recurrent` oder `one_off`;
`signed_date` gesetzt = handschriftlicher Nachweis erfasst. Digitale Mandatsanforderung (`mandate_requests.status`):
`requested`, `pending`, `granted`, `expired`, `revoked`, `unusable`. Eine IBAN oder ein Dateiupload allein ist kein
wirksames Mandat.

**Einzug** (`payment_collections.stripe_status`): `scheduled` (terminiert oder vorgemerkt, stornierbar), `submitting`,
`processing` (bei Stripe eingereicht), `succeeded`, `failed`, `disputed` (Rücklastschrift), `refunded`, `cancelled`.
HTTP 200, Buttonklick und Return-URL sind keine Zahlungsbestätigung; maßgeblich sind verifizierte Stripe-Ereignisse oder
der Statusabgleich. Ein Erfolg kann bis zu acht Wochen später zu `disputed` werden; der alte Erfolgseintrag bleibt
nachvollziehbar (Audit `collection_disputed`, Feld `source`).

**Rechnung** (`invoices.collection_status`): `none`, `open`, `scheduled`, `in_collection`, `collected`, `failed`;
`requires_review` 1 = Klärungsbedarf, kein automatischer Neu-Einzug.

**Abonnement der Firma** (`organizations.subscription_status`): `pending`, `active`, `past_due`, `canceled`, `exempt`
(`billing_exempt`). `cancel_at_period_end` 1 = Kündigung zum Periodenende vorgemerkt.

**Auszahlung**: nicht abgebildet. Stripe-Auszahlungen (payouts) an die Firma werden von SmartEinzug weder gelesen noch
angezeigt; die Website darf keine Auszahlungs- oder Zahlungseingangstage nennen.

## 6. Berechtigungen und zulässige Nutzeraktionen

Rollen je Firma (`organization_members.role`): `owner`, `admin`, `member`. Verbindungen, Firmendaten, Not-Stopp aufheben,
Buchhaltungssystem wechseln: `owner` und `admin`; Einladungen und Inhaberwechsel: `owner`. Zweitbestätigung per 2FA-Code für
die in `docs/entwickler/sicherheit.md` gelisteten Aktionen. Support-Modus des Betreibers sperrt Einzüge, IBAN, Mandate,
Not-Stopp aufheben, Firmendaten mit Geldbezug, Wechsel des Buchhaltungssystems und Zustimmungen; Storno eines terminierten
Einzugs bleibt möglich (Schutzrichtung, kein Geldabfluss). Plattformrechte des Adminbereichs: `PLATFORM_PERMISSIONS`.

## 7. Fehlerverhalten und öffentliche technische Endpunkte

| Endpunkt | Methode | Antwort | Hinweis |
|---|---|---|---|
| `health.php` | GET | 200 `{"status":"ok"}` oder 503 `degraded` | kein Cache, keine Versionen |
| `fakten.php` | GET | 200 JSON, 405 sonst | 300 s Cache, noindex |
| `vormerken.php` | GET, POST | HTML | siehe Abschnitt 3 |
| `track.php` | POST | 204 oder 400 | nur erlaubte Domains und Ereignisse |
| `stripe-webhook.php`, `billing-webhook.php`, `marketing-webhook.php` | POST | 200, 400, 403, 500 (Wiederholung) | nur mit Signatur; nicht für das Frontend |
| `version.txt` | GET | Text | veraltet (Stand 06.09.2026), nicht als Versionsquelle nutzen; maßgeblich ist `APP_VERSION` |

Meldungen an angemeldete Nutzer sind Flash-Meldungen der Typen `success`, `info`, `error` in deutscher Sprache.

## 8. Serverseitige SEO-Regeln (auf Anfrage)

Canonical-, Host- und HTTPS-Regeln, Redirects, 404/410, `X-Robots-Tag`, WAF-Regeln und Header liegen in
`websites/<domain>/.htaccess` beziehungsweise `deploy/vps/Caddyfile` und werden vom Backend nach konkreter Anfrage geändert.
`noindex` und `robots.txt` sind kein Zugriffsschutz. Eine öffentliche Faktenseite unter `/fakten/` auf smart-einzug.de ist
Frontend-Inhalt; ihre Daten kommen aus dem Snapshot oder `fakten.php` (Abschnitt 2), niemals aus Live-Aufrufen an Stripe.

## 9. Offene Punkte für die gemeinsame Abnahme

- Ausrollen von 4.79 (Migration 035) auf Staging und Abnahme des Verbindungszustands mit einem echten Stripe-Testkonto
  (Konto ohne SEPA-Fähigkeit, eingeschränkter Schlüssel ohne Kontorecht).
- Prüfung von `fakten.php` gegen die Marketingdomains (CORS) nach dem Ausrollen.
- Vergleichspreis (`tarif.vergleichspreis`): bleibt gesperrt, bis die wettbewerbsrechtliche Zulässigkeit geklärt ist.

## 10. Antworten auf die Anfragen des Frontends (`docs/frontend/backend-anfragen.md`, Stand 13.09.2026)

Vorbemerkung: Der dort vermisste Datenvertrag ist diese Datei; sie lag am 13.09.2026 noch im Arbeitsbranch. Das
Faktenregister `docs/seo/02-faktenregister.md` bleibt die Quelle für Wortlaute mit Zeilenbeleg; die maschinenlesbare
Quelle für Website und Endpunkt ist `app/product_facts.php` (Abschnitt 2). Beide dürfen sich nicht widersprechen; bei
Abweichung gilt der Code, und das Backend zieht das Register nach.

| Nr. | Anfrage | Antwort |
|---|---|---|
| 1 | RUECK-01 überholt | Erledigt: RUECK-01 nennt jetzt Webhook und Statusabgleich (4.73). STATUS-03 und EINZUG-17 waren bereits in 4.73 nachgezogen. Eine Gesamtdurchsicht des Registers seit abd5d26 steht aus; neue Aussagen bitte gegen `product_facts.php` prüfen. |
| 2 | Rechenzentrumsstandort | Nicht aus dem Repository belegbar (`sicherheit.hosting` im Register ungeklärt, nicht öffentlich). Nachweis nur über die Vertragsunterlagen des Hosters; Aufgabe des Betreibers. |
| 3 | IBAN im Klartext | Bestätigt: `customer_ibans.iban VARCHAR(34)` ohne Verschlüsselung; nur API-Schlüssel sind verschlüsselt. Als P2 in `docs/backend/audit.md` (MP-10) aufgenommen. Umsetzung (Verschlüsselung plus Migration mit Umschlüsselung des Bestands, Suche über maskierte Spalte) nur nach Entscheidung des Betreibers, weil sie Einzugspfad und Anzeige berührt. Bis dahin bleibt die Aussage der Sicherheitsseite. |
| 4 | Content-Security-Policy der Anwendung | Offen (MP-11). Vor einer Aktivierung erhält das Frontend die Richtlinie zur Prüfung; `consent.js` und die Google-Hosts würden in `script-src`, `img-src` und `connect-src` aufgenommen (Befund 4.71). |
| 5 | Conversion für den Vertragsabschluss | Backend-seitig vorbereitet (`subscription.php?bestellt=1`, `analytics.ads_conversion_label`). Aktivierung nur mit einer zweiten, in Google Ads angelegten Conversion-Aktion und Umwidmung der Klick-Conversion, sonst Doppelzählung. Entscheidung des Betreibers, danach Eintrag des Labels in `shared/config.php` und `restart-workers.sh`. |
