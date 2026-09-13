# Grounding Page, strukturierte Daten und GEO-Prüfplan

Stand 13.09.2026.

## 1. Die Seite

| | |
|---|---|
| URL | `https://smart-einzug.de/fakten/` |
| Quelldatei | `websites/smart-einzug.de/fakten/index.html` |
| Titel | Produktfakten zu SmartEinzug |
| H1 | SmartEinzug: Produktfakten, Voraussetzungen und Integrationen |
| Canonical | selbstreferenziell |
| Indexierung | index, follow |
| Rendering | vollständiges HTML, ohne JavaScript lesbar |
| Keyword-Map | Cluster `C31_smarteinzug_produktfakten` |

Eine gleichwertige Faktenseite bestand nicht, deshalb wurde neu angelegt statt verbessert. Konkurrierende
Varianten unter `/geo/`, `/ai/` oder `/grounding/` gibt es nicht und sollen nicht entstehen.

**Abweichung von der Vorlage:** Die H1 lautet „SmartEinzug: Produktfakten …“ statt „SmartEinzug –
Produktfakten …“. Der Gedankenstrich ist nach den Projektregeln in deutschen Texten ausgeschlossen und
wird von `tools/site-qa.py` als Fehler gemeldet. Der Sinn bleibt unverändert.

## 2. Verlinkung

- Footer auf 32 Seiten von smart-einzug.de mit dem Text „Fakten zu SmartEinzug“.
- Kontextuell aus `/funktionen/` (Verweis auf die nicht enthaltenen Leistungen), `/integrationen/`
  (Verweis auf Status und Voraussetzungen) und `/hilfe/` (Verweis auf nachprüfbare Angaben).
- Ausgenommen: die beiden Kampagnenseiten unter `lp/`. Sie führen bewusst einen reduzierten Footer, damit
  die Seite nur ein Ziel hat. Eine Aufnahme wäre eine inhaltliche Entscheidung des Betreibers.

## 3. Faktenquellen je Abschnitt

Aussagen über die eigene Software sind gegen den Code geprüft. Quelle ist das Faktenregister
`docs/seo/02-faktenregister.md` mit Gegenprüfung am Code unter `php-ionos/`. Aussagen über Lexware
Office und Stripe geben den Kenntnisstand wieder; verbindlich sind allein die Angaben dieser Anbieter
(siehe Nachtrag 3b).

| Abschnitt | Wesentliche Quelle |
|---|---|
| Was SmartEinzug ist | `app/lexoffice.php` (nur GET), `app/collections.php` (PaymentIntent im Konto der Firma) |
| Rollen | `settings.php` (eigenes Stripe-Konto), `app/mandates.php` (Stripe als Zahlungsdienstleister im Mandatstext) |
| Nicht enthalten | Kein Neu-Einzug nach Fehlschlag (`app/collections.php`), kein Schreibzugriff nach Lexware (`app/lexoffice.php`), nur Basislastschrift (`app/stripe.php`), keine Gebührenberechnung (kein Treffer im Code) |
| Voraussetzungen | `settings.php` (Schlüsseleingabe für beide Anbieter), Tarifhinweis als Zitat von Lexware |
| Integrationen | `docs/integrations.md`, `sql/schema.sql` (Registry `integration_providers`), `app/sevdesk.php` (gesperrter Adapter) |
| Ablauf | `app/collections.php` (Karenzzeit, Einreichfenster, Restbetragsprüfung), `stripe-webhook.php` |
| Vertragsmodell | `app/billing_setup.php` (`interval = day`, `interval_count = 28`), `app/billing.php` (Kündigung zum Periodenende) |
| Sicherheit | `app/crypto.php` (AES-256-GCM), `app/auth.php` (2FA-Pflicht), Audit-Aufbewahrung 90 Tage |
| Abgrenzung | Keine Partnerschaft im Code oder in den Unterlagen belegt; Hinweis auf Haufe-Lexware bereits im Bestand |

### Bewusst nicht aufgenommen

| Angabe | Grund |
|---|---|
| Rechenzentrumsstandort der Anwendung | Nicht belegbar. Weder der Standort des VPS noch die Region des Sicherungsspeichers sind dokumentiert. Eine Aussage wie „Hosting in Deutschland“ wäre unbelegt. |
| Preisbeträge | Vorgabe des Betreibers vom 07.09.2026. Die Seite beschreibt das Modell und verweist für Konditionen auf den Bestellvorgang. |
| Verschlüsselung der IBAN | Trifft nicht zu. Die Seite behauptet deshalb nichts dazu und verweist für die Grenzen auf `/sicherheit/`, wo der Punkt offen benannt ist. |
| Zwingender Lexware-Tarif als Tatsache | Nur als Angabe von Lexware zitiert, mit der Bitte, im eigenen Konto zu prüfen. Der Code prüft keinen Tarif. |
| Zusage eines sevdesk-Starttermins | Der genannte Termin ist als Planung ohne Zusage gekennzeichnet. |

## 3b. Nachtrag 13.09.2026: externe Durchsicht

Eine externe Durchsicht der veröffentlichten Seite hat zwei berechtigte Befunde ergeben, beide umgesetzt.

**Geldfluss war falsch beschrieben.** Die Seite sagte „Belastet wird das Stripe-Konto Ihres eigenen
Unternehmens“. Bei einer Lastschrift wird das Bankkonto des Zahlers belastet; über das Stripe-Konto der
Firma wird die Zahlung lediglich abgewickelt, und dort geht das Geld ein. Der Satz vermengte den belasteten
mit dem abwickelnden Kontostand. Jetzt getrennt formuliert.

**Quellenangabe war nicht nachvollziehbar.** „Jede Angabe ist gegen den Programmcode geprüft“ ist für einen
Leser von außen nicht überprüfbar und vermischte zwei verschiedene Quellenarten. Die Seite unterscheidet
jetzt zwischen Aussagen über die eigene Software (gegen den Programmstand geprüft, verantwortlich die
Müller Holding AG als Anbieterin, im Testkonto nachvollziehbar) und Aussagen über Lexware Office und Stripe
(deren Bedingungen sind verbindlich, mit ausdrücklicher Bitte, Tarif, Gebühren und Auszahlungsdauer dort
nachzufragen).

Bewusst nicht ergänzt wurden Verweise auf die Dokumentationsseiten von Stripe und Lexware. Im Projekt sind
nur die API-Basisadressen belegt, keine Dokumentationsadressen. Ein geratener Deep-Link, der ins Leere
zeigt, wäre schlechter als die Nennung des Anbieters ohne Link.

**Offen geblieben:** der dritte Befund der Durchsicht, die öffentliche Nennung des Preises. Das kehrt die
Betreibervorgabe vom 07.09.2026 um und betrifft nicht nur diese Seite. Entscheidung liegt beim Betreiber,
siehe `release-checklist.md`.

## 4. Strukturierte Daten

Die Seite führt zwei Blöcke: eine `BreadcrumbList` und einen `@graph` mit vier Entitäten.

| Entität | Stabile Kennung |
|---|---|
| Organization (Anbieter) | `https://smart-einzug.de/#anbieter` |
| WebSite | `https://smart-einzug.de/#website` |
| SoftwareApplication | `https://smart-einzug.de/#software` |
| AboutPage | `https://smart-einzug.de/fakten/#seite` |

Anbieter, Website und Software bleiben getrennte Entitäten und werden über `@id` verknüpft statt
ineinander verschachtelt. Bestehende Blöcke auf anderen Seiten wurden nicht umgestellt: Sie tragen bisher
keine Kennungen, und eine Umstellung ohne Anlass wäre ein Eingriff mit Risiko und ohne Nutzen.

Bewusst nicht verwendet:

- **`sameAs`** auf Lexware, sevdesk oder Stripe. Das würde Identität behaupten, nicht Verbindung.
- **`offers` und Preisangaben.** Ohne freigegebene Beträge auf der Seite wären sie nicht belegt.
- **`aggregateRating` und `Review`.** Es gibt keine echten Bewertungen. `tools/site-qa.py` weist solches
  Markup ohnehin als Fehler zurück.
- **`FAQPage`.** Die Seite hat einen Frageabschnitt, führt aber bereits `AboutPage` und
  `SoftwareApplication`. Ein dritter Typ auf derselben Seite bringt keinen belegbaren Vorteil und erhöht
  nur die Wahrscheinlichkeit einer Fehlinterpretation.

`dateModified` steht auf dem Tag der tatsächlichen fachlichen Prüfung, nicht auf dem Zeitpunkt der
Auslieferung. Es wird nur bei einer echten inhaltlichen Prüfung fortgeschrieben.

## 5. GEO-Prüfplan

Der Plan ist bewusst klein und wiederholbar. **Er wurde noch nicht ausgeführt**, weil aus dieser
Arbeitsumgebung kein Zugriff auf die betreffenden Systeme besteht. Es werden daher keine Ergebnisse
berichtet.

Vorgehen je Durchgang: System, Datum, Frage und Antwort im Wortlaut festhalten. Getrennt vermerken, ob
SmartEinzug genannt wurde, ob die Seite als Quelle zitiert wurde und ob daraus ein Besuch entstand. Diese
drei Dinge sind verschiedene Sachverhalte und dürfen nicht zu einer Kennzahl vermengt werden.

Neutrale Produktfragen, ohne Markennennung:

1. Wie kann ich Rechnungen aus Lexware Office per SEPA-Lastschrift einziehen?
2. Welche Software verbindet Lexware Office mit Stripe für Lastschriften?
3. Brauche ich für SEPA-Lastschriften über Stripe ein eigenes Stripe-Konto?

Markenbezogene Faktenfragen:

4. Was ist SmartEinzug und wer steht dahinter?
5. Übernimmt SmartEinzug das Mahnwesen?
6. Unterstützt SmartEinzug sevdesk?
7. Schreibt SmartEinzug Zahlungen nach Lexware Office zurück?

Die Fragen 5 bis 7 sind die eigentliche Probe: Auf alle drei lautet die belegte Antwort Nein. Wird das
falsch wiedergegeben, ist das ein Hinweis darauf, dass die Abgrenzung auf den Seiten nicht deutlich genug
steht.

Keine Zusage auf Indexierung, auf Nennung in KI-Antworten oder auf eine Wirkung dieser Seite. Die
Spezifikation von groundingpage.com diente als Orientierung für den Aufbau; sie ist kein Standard von
Google, OpenAI oder W3C und wird hier auch nicht als solcher dargestellt.

## 6. Nicht umgesetzt

`llms.txt` wurde bewusst nicht angelegt. Die Datei hat keine belegte Wirkung, ersetzt weder reguläres HTML
noch die Sitemap, und sie erzeugt eine zweite Faktenquelle, die mit der Website auseinanderlaufen kann.
Vor einer Einführung wäre zu klären, wer sie pflegt. Das ist eine Entscheidung des Betreibers, keine
technische Notwendigkeit.
