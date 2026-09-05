# Google Ads und Conversion-Tracking, SmartEinzug (Paket E), Stand 05.09.2026

Hinweis zur Grundlage: Der Auftrag verweist auf „Abschnitt 25" für die Keyword-Liste und „Abschnitt 34" (siehe `docs/smarteinzug-qa.md`) für die Abnahmefälle. Der zugehörige Auftragstext lag mir bei der Erstellung dieses Dokuments nicht als Datei im Repository vor. Die folgenden Keywords, Titel und Beschreibungen sind auf dieser Grundlage als fachlich plausibler Entwurf erstellt und vor Schaltung der Kampagne gegenzuprüfen, insbesondere auf Übereinstimmung mit dem tatsächlichen Auftragstext.

## 1. Kampagnenstruktur

**Eine Suchkampagne, Zielmarkt Deutschland** (DE), mit folgenden Anzeigengruppen:

| Anzeigengruppe | Ziel-Landingpage | Status |
|---|---|---|
| Lexware Office Lastschrift | https://smart-einzug.de/lp/lexware-lastschrift/ | aktiv |
| lexoffice Lastschrift | https://smart-einzug.de/lp/lexoffice-lastschrift/ | aktiv |
| Stripe mit Einzugsbedarf | https://smart-einzug.de/lp/lexware-lastschrift/ | aktiv |
| Wettbewerber SEPAHeld | https://smart-einzug.de/vergleich/sepaheld-gocardless/ | pausiert, erst nach Freigabe schalten |
| Wettbewerber GoCardless | https://smart-einzug.de/vergleich/sepaheld-gocardless/ | pausiert, erst nach Freigabe schalten |

Die beiden Wettbewerber-Anzeigengruppen bleiben bis zur ausdrücklichen Freigabe durch die Geschäftsführung auf „Pausiert" gestellt, da Wettbewerbernamen im Kampagnennamen beziehungsweise Targeting rechtlich sensibel sind (siehe Abschnitt 4).

## 2. Keywords je Anzeigengruppe

Hinweis: Diese Liste ist ein Entwurf (siehe Vorbemerkung). Vor Schaltung Suchvolumen und tatsächliche Nutzeranfragen prüfen, da Suchvolumina im Repository nicht nachweisbar sind (siehe Bestandsmatrix, Abschnitt „Nicht prüfbar aus dem Repository").

**Lexware Office Lastschrift** (Exakt `[...]` und Wortgruppe `"..."`):
- `[lexware office lastschrift]`, `"lexware office lastschrift"`
- `[lexware office sepa lastschrift]`, `"lexware office sepa lastschrift"`
- `[lexware office lastschrifteinzug]`, `"lexware office lastschrifteinzug"`
- `[lexware office sepa mandat]`, `"lexware office sepa mandat"`
- `[offene rechnungen lastschrift einziehen]`, `"offene rechnungen per lastschrift einziehen"`

**lexoffice Lastschrift** (Exakt und Wortgruppe):
- `[lexoffice lastschrift]`, `"lexoffice lastschrift"`
- `[lexoffice sepa lastschrift]`, `"lexoffice sepa lastschrift"`
- `[lexoffice lastschrifteinzug]`, `"lexoffice lastschrifteinzug"`
- `[lexoffice sepa mandat]`, `"lexoffice sepa mandat"`
- `[lexoffice lastschrift einrichten]`, `"lexoffice lastschrift einrichten"`

**Stripe mit Einzugsbedarf** (Exakt und Wortgruppe):
- `[stripe sepa lastschrift]`, `"stripe sepa lastschrift"`
- `[lexware office stripe]`, `"lexware office stripe lastschrift"`
- `[stripe lastschrift einziehen]`, `"stripe lastschrift für rechnungen"`
- `[eigenes stripe konto sepa]`, `"eigenes stripe konto für lastschrift"`

**Wettbewerber SEPAHeld / GoCardless** (pausiert, nur zur Vorbereitung, erst nach Freigabe aktivieren):
- `[sepaheld alternative]`, `"sepaheld alternative"`
- `[gocardless alternative]`, `"gocardless alternative"`
- `[gocardless lexware office]`, `"gocardless für lexware office"`

## 3. Negativkeywords

| Negativkeyword | Begründung |
|---|---|
| `[lexoffice login]` | Navigationsanfrage zum Login von lexoffice/Lexware Office selbst, kein Interesse an SmartEinzug |
| `[lexware office login]` | wie vorstehend, Navigationsanfrage zum Drittanbieter |
| `[gocardless login]` | Navigationsanfrage zum Login eines Wettbewerbers, keine Kaufabsicht für SmartEinzug |
| `[sepaheld login]` | wie vorstehend |
| `kostenlos` | Preissensible Anfragen ohne Bezug zum kostenpflichtigen Angebot, hohe Streuverluste |
| `crack` | Anfragen nach illegal umgangenen Softwarelizenzen, kein passender Traffic und Reputationsrisiko |
| `download` | überwiegend Anfragen nach Software-Downloads, nicht nach einem SaaS-Lastschriftdienst |
| `jobs` | Stellenanfragen, kein Produktinteresse |
| `gehalt` | Anfragen zu Gehaltsvergleichen, kein Produktinteresse |
| `ausbildung` | Anfragen zu Ausbildungsplätzen, kein Produktinteresse |
| `sevdesk` | sevdesk-Anbindung ist laut Produktstand „in Planung", noch kein Kaufbutton, daher keine Bewerbung dieses Suchbegriffs bis zur Verfügbarkeit |

## 4. Anzeigentexte

Regeln: keine Wettbewerbernamen im Anzeigentext (auch nicht in der Wettbewerbergruppe, dort wird über die Vergleichsseite ohne Namensnennung im Anzeigentext geworben), keine Superlative, keine Gedankenstriche. Zeichenlängen mit einem Python-Skript geprüft (`/tmp/claude-0/-home-user-LexofficXSTRIPE/cfb09bc5-d061-5b4d-aaa4-c0f059f381a0/scratchpad/check_lengths.py`, lokale Prüfdatei, nicht Teil des Repositorys).

### 4.1 Titel (maximal 30 Zeichen), Längenprüfung

| # | Lexware Office Lastschrift | Länge | lexoffice Lastschrift | Länge | Stripe mit Einzugsbedarf | Länge |
|---|---|---|---|---|---|---|
| 1 | Lastschrift für Lexware Office | 30 | Lastschrift für lexoffice | 25 | Stripe-Konto für SEPA-Einzug | 28 |
| 2 | SEPA-Einzug in Lexware Office | 29 | SEPA-Einzug in lexoffice | 24 | SEPA-Lastschrift über Stripe | 28 |
| 3 | Rechnungen automatisch holen | 28 | lexoffice, nun Lexware Office | 30 | Eigenes Stripe-Konto nutzen | 27 |
| 4 | Rechnung per Lastschrift holen | 30 | Rechnungen automatisch holen | 28 | Lastschrift für Lexware Office | 30 |
| 5 | SmartEinzug für Lexware Office | 30 | Rechnung per Lastschrift holen | 30 | Rechnungen automatisch holen | 28 |
| 6 | Einzug aus Lexware Office | 25 | SmartEinzug für lexoffice | 25 | SmartEinzug für Lexware Office | 30 |
| 7 | Stripe-Lastschrift, Lexware | 27 | Einzug direkt aus lexoffice | 27 | Rechnung per Lastschrift holen | 30 |
| 8 | Weniger Mahnungen schreiben | 27 | Stripe-Lastschrift, lexoffice | 29 | Einzug aus Lexware Office | 25 |
| 9 | SEPA-Mandat digital einholen | 28 | Weniger Mahnungen schreiben | 27 | Weniger Mahnungen schreiben | 27 |
| 10 | Rechnungen automatisch bezahlt | 30 | SEPA-Mandat digital einholen | 28 | SEPA-Mandat digital einholen | 28 |
| 11 | Einzug ohne manuellen Aufwand | 29 | Rechnungen automatisch bezahlt | 30 | Rechnungen automatisch bezahlt | 30 |
| 12 | Lastschrift statt Überweisung | 29 | Einzug ohne manuellen Aufwand | 29 | Einzug ohne manuellen Aufwand | 29 |
| 13 | 25 EUR netto je 4 Wochen | 24 | Lastschrift statt Überweisung | 29 | Lastschrift statt Überweisung | 29 |
| 14 | Ohne Jahresbindung kündbar | 26 | 25 EUR netto je 4 Wochen | 24 | 25 EUR netto je 4 Wochen | 24 |
| 15 | Für Lexware Office UNLIMITED | 28 | Ohne Jahresbindung kündbar | 26 | Ohne Jahresbindung kündbar | 26 |

lexoffice-Gruppe zusätzlich (16. Position, sofern vom System zugelassen): „Für lexoffice-Kunden geeignet" (29 Zeichen); die Skriptprüfung bestätigt alle Titel als innerhalb der 30-Zeichen-Grenze.

### 4.2 Beschreibungen (maximal 90 Zeichen, je Gruppe 4), Längenprüfung

| Gruppe | Beschreibung | Länge |
|---|---|---|
| Lexware Office Lastschrift | SEPA-Lastschrift für offene Rechnungen aus Lexware Office, automatisiert. | 73 |
| Lexware Office Lastschrift | Einführungspreis 25,00 EUR netto je 4 Wochen, ohne Jahresbindung. | 65 |
| Lexware Office Lastschrift | Voraussetzung: Lexware Office XL und eigenes SEPA-Stripe-Konto. | 63 |
| Lexware Office Lastschrift | Jetzt registrieren und Lastschrifteinzug in wenigen Schritten einrichten. | 73 |
| lexoffice Lastschrift | SEPA-Lastschrift für offene Rechnungen aus lexoffice, automatisiert. | 68 |
| lexoffice Lastschrift | Einführungspreis 25,00 EUR netto je 4 Wochen, ohne Jahresbindung. | 65 |
| lexoffice Lastschrift | Voraussetzung: Lexware Office XL und eigenes SEPA-Stripe-Konto. | 63 |
| lexoffice Lastschrift | Jetzt registrieren und Lastschrifteinzug in wenigen Schritten einrichten. | 73 |
| Stripe mit Einzugsbedarf | SEPA-Lastschrift über Ihr eigenes Stripe-Konto, automatisiert eingezogen. | 73 |
| Stripe mit Einzugsbedarf | Einführungspreis 25,00 EUR netto je 4 Wochen, ohne Jahresbindung. | 65 |
| Stripe mit Einzugsbedarf | Voraussetzung: Lexware Office XL und eigenes SEPA-Stripe-Konto. | 63 |
| Stripe mit Einzugsbedarf | Jetzt registrieren und Lastschrifteinzug in wenigen Schritten einrichten. | 73 |

Alle Beschreibungen liegen laut Skriptprüfung innerhalb der 90-Zeichen-Grenze. Keine Angabe erwähnt einen Wettbewerbernamen oder einen Superlativ, keine Angabe enthält einen Gedankenstrich.

## 5. Conversion-Plan

### 5.1 Ereignisse

| Ereignis | Bedeutung | Nachweis im Repository |
|---|---|---|
| `landingpage_view` | Aufruf einer Landingpage | `funnel_events`, `track.php` (vorhanden) |
| `cta_click` | Klick auf Registrierungs-Call-to-Action | `funnel_events`, `track.php` (vorhanden) |
| `registration_start` | Registrierungsformular begonnen | zu ergänzen |
| `registration_complete` | Firmenaccount angelegt | zu ergänzen |
| `email_verified` | E-Mail-Adresse bestätigt (primäres Ziel) | laut Bestandsmatrix zu ergänzen in `funnel_events` |
| `connections_ready` | Lexware Office und Stripe erfolgreich verbunden | zu ergänzen |
| `first_live_collection` | Erster tatsächlicher Einzug im Live-Modus | laut Bestandsmatrix zu ergänzen in `funnel_events` |
| `subscription_paid` | Erste Zahlung des Plattform-Abos (SmartEinzug-Gebühr) | zu ergänzen, abhängig vom Aktivierungsstand des Plattform-Billings (laut Bestandsmatrix „vorhanden, aus") |

### 5.2 Zuordnung GA4 vs. Google Ads vs. serverseitig

- **GA4**: `landingpage_view`, `cta_click` als clientseitige Ereignisse über `assets/js/site.js`, ausschließlich nach erteilter Einwilligung (Consent Mode), siehe CLAUDE.md „Google-Tags nur hinter der Einwilligung".
- **Google Ads (Conversion-Import)**: `email_verified` als primäres Conversion-Ziel, ergänzend `registration_complete` und `first_live_collection` als sekundäre Ziele. Der Import erfolgt aus GA4 beziehungsweise über den serverseitigen Kanal (siehe unten), nicht durch ein direktes Google-Ads-Tag auf der Registrierungsseite der App, da die App auf einer anderen Domain als die Marketingseite läuft.
- **Serverseitig (`funnel_events`, `track.php`)**: Alle App-seitigen Ereignisse ab `registration_start` bis `subscription_paid` werden serverseitig erfasst, da sie erst nach Weiterleitung auf die App-Domain entstehen und dort nicht zwingend an clientseitiges GA4-Tracking gebunden sind. Von dort erfolgt der Import in Google Ads über die Google-Ads-API beziehungsweise den GA4-Datenimport.

### 5.3 Deduplizierung

Jedes Ereignis benötigt eine stabile, einmalige Kennung je Vorgang (zum Beispiel Klick-ID beziehungsweise Sitzungs-ID aus der Landingpage, weitergereicht bis zur Registrierung), damit ein und derselbe Vorgang nicht sowohl über GA4 als auch über den serverseitigen Kanal doppelt als Conversion gezählt wird. Die konkrete technische Umsetzung (Übergabeparameter von der Landingpage zur App-Domain, Speicherung bis zur Registrierung) ist Teil der noch ausstehenden Implementierung und hier nicht als bereits vorhanden zu verstehen.

### 5.4 Consent Mode v2, Status

Laut Bestandsmatrix ist Consent Mode auf den bestehenden Marketingseiten vorhanden. Für `smart-einzug.de` ist die Google-Tag-Einbindung vorbereitet, jedoch ohne hinterlegte Konto- beziehungsweise Tag-IDs (siehe Bestandsmatrix „smart-einzug.de ohne IDs vorbereitet"). Google-Tags dürfen weiterhin ausschließlich hinter erteilter Einwilligung laden (`site.js`), niemals direkt im HTML, siehe CLAUDE.md.

## 6. Offene Punkte

- Der Auftragstext zu „Abschnitt 25" (verbindliche Keyword-Liste) lag mir nicht vor. Die oben aufgeführten Keywords sind ein fachlich plausibler Entwurf und vor Schaltung gegenzuprüfen.
- Google-Ads-Conversion-ID und Conversion-Label fehlen noch und sind vor Kampagnenstart bei Google Ads anzulegen.
- Der Wechsel der App-Domain (`app.smart-einzug.de`, siehe `docs/smarteinzug-rollout.md`) wirkt sich auf die Landingpage-zu-App-Weiterleitung und damit auf die Deduplizierung der Conversion-Ereignisse aus und ist vor Kampagnenstart abzustimmen.
- Suchvolumina und tatsächliche Wettbewerberpreise sind laut Bestandsmatrix nicht aus dem Repository prüfbar.
- Freigabe der Wettbewerber-Anzeigengruppen (SEPAHeld, GoCardless) durch die Geschäftsführung steht aus, diese bleiben bis dahin pausiert.
