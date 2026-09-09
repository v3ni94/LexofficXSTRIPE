# Bestandsaufnahme der Marketingseiten

Nachtrag 08.09.2026: Diese Bestandsaufnahme beschreibt den Stand vom 07.09.2026 vor der Bereinigung. Seit dem 08.09.2026 sind die Maßnahmen M2 und M4 umgesetzt (lastschrift-einfach.de nur noch Weiterleitung, Spiegelseiten und Ratgeber der Leaddomains zusammengeführt beziehungsweise nach smart-einzug.de/wissen/ verlagert); der aktuelle Bestand steht in `url-inventar.md` und `04-keyword-map.md`, der Umsetzungsstand in `05-massnahmenplan.md` und `07-abschlussbericht.md`.

Stand: 07.09.2026, Repository-Stand abd5d26, Branch `claude/frontend-smart-einzug-egsouk`. Grundlage sind das Repository, `tools/seo-inventory.py`, `tools/site-qa.py`, die Faktenprüfung des Programmcodes (`02-faktenregister.md`), die Prüfung der Werbeaussagen (`03-aussagenpruefung.md`) und die Überschneidungsanalyse (`04-keyword-map.md`). Live-Abrufe und Google-Daten waren nicht verfügbar (siehe Datenlücken).

## 1. Ziel und Vorgehen

Der Masterprompt verlangt zuerst Bestandsaufnahme und Faktenprüfung, dann die Bereinigung falscher oder nicht freigegebener Aussagen, danach die Verbesserung der wichtigsten Seiten und eine kleine Gruppe neuer Inhalte. Größere Zusammenführungen, URL-Wechsel und Indexierungsänderungen stehen im Maßnahmenplan (`05-massnahmenplan.md`) und warten auf Freigabe. Leitregel: eine hilfreiche Seite verbessern ist wertvoller als drei ähnliche Seiten veröffentlichen.

## 2. Domains und Rollen

| Domain | Rolle laut Masterprompt | Ist-Zustand |
|---|---|---|
| smart-einzug.de | Produktmarke, Hauptwebsite, organischer Schwerpunkt für Produkt-, Integrations-, Wissens-, Anleitungs- und Vertrauensinhalte | Produktseiten (Funktionen, Preise, So funktioniert's, Integrationen, Hilfe, Kontakt), sevdesk-Vorankündigung mit Vormerkung, zwei Kampagnenseiten (noindex), Vergleichsseite (noindex, Entwurf). Keine Anleitungen, keine Wissensseiten, keine Sicherheitsseite. |
| lexoffice-einzug.de | Einstiegswebsite für Suchende mit dem Begriff „lexoffice“, erklärt die Beziehung zu Lexware Office und SmartEinzug | Startseite, sechs Keyword-Seiten, Anleitung, Umbenennungsseite, Public-API-Seite, sieben Ratgeberartikel (allgemeines SEPA-Wissen), Kampagnenseite (noindex). SmartEinzug-Logo und Fußzeile vorhanden. |
| lexware-einzug.de | Einstiegswebsite für Interessenten mit konkretem Einzugsbedarf aus Lexware Office | Startseite, Funktionen, Preise, Sicherheit, Hilfe, FAQ, acht Keyword-Seiten, zwei Anleitungen, vier Ratgeberartikel, Kampagnenseite (noindex). Google-Ads-Tag nur hier. |
| lastschrift-einfach.de | keine (Weiterleitung auf smart-abrechnen.de laut Betreiber, 07.09.2026) | Ordner im Repository ist Altbestand mit acht Inhaltsseiten, siehe Maßnahmenplan M2; nicht Teil der Keyword-Map |
| smarteinzug.de, smart-lastschrift.de, einzug-direkt.de | technische Aliase | 301 auf smart-einzug.de, unbekannte Pfade 404, UTM bleibt erhalten |

Die Kundenanwendung läuft unter `app.smart-einzug.de`, der Adminbereich unter `admin.smart-einzug.de`; beide sind nicht Teil dieses Pakets.

## 3. URL-Inventar (Kurzfassung)

Vollständig in `url-inventar.md` und `url-inventar.json`.

| Domain | HTML-Dateien | technisch indexierbar | in Sitemap | verwaist (indexierbar ohne eingehenden Link) | Seiten mit Produktpreis | Seitentypen der indexierbaren Seiten |
|---|---|---|---|---|---|---|
| smart-einzug.de | 16 | 12 | 12 | 0 | 8 | integration 3, produktseite 4, rechtliches_kontakt 4, startseite 1 |
| lexoffice-einzug.de | 22 | 20 | 20 | 0 | 4 | anleitung 1, keyword_landingpage 7, ratgeber 7, ratgeber_uebersicht 1, rechtliches_kontakt 3, startseite 1 |
| lexware-einzug.de | 26 | 24 | 24 | 1 | 6 | anleitung 2, keyword_landingpage 8, produktseite 5, ratgeber 4, ratgeber_uebersicht 1, rechtliches_kontakt 3, startseite 1 |

Alle Seiten haben Title, Description, Self-Canonical (indexierbare Seiten), genau eine H1, `lang="de"`, Open Graph und den Markenhinweis „Kein Produkt der Haufe-Lexware GmbH & Co. KG“; `site-qa.py` meldet 0 Fehler. „Indexierbar“ heißt technisch indexierbar; ob Google die Seiten indexiert hat, ist ohne Search Console nicht bestätigt.

## 4. Technische Prüfung (eigene Prüfung, Stand 07.09.2026)

Grundlage: Repository-Stand abd5d26, `python3 tools/site-qa.py` (0 Fehler, 4 Warnungen), `tools/seo-inventory.py`, Sichtprüfung von `.htaccess`, `robots.txt`, `sitemap.xml`, JSON-LD und `assets/js/site.js`. Live-Abrufe der Domains waren aus dieser Umgebung nicht möglich (Proxy antwortet mit 403), deshalb sind Angaben zum tatsächlichen Live-Stand nicht bestätigt.

| Nr. | Befund | Bewertung | Behandlung |
|---|---|---|---|
| T1 | Alle vier Sitemaps tragen für jede URL dasselbe `lastmod` (Datum des letzten Sitemap-Laufs, faktisch das Deploy-Datum). | Widerspricht der Vorgabe, `lastmod` nur bei echten Inhaltsänderungen zu setzen. | Behoben in `tools/build-sitemaps.py`: Datum des letzten Commits je Datei, heutiges Datum nur bei ungespeicherten Änderungen. |
| T2 | Drei Startseiten und zwei Kampagnenseiten führen im JSON-LD `SoftwareApplication` ein `Offer` mit `price 25.00 EUR`. | Nicht freigegebene Preisangabe in strukturierten Daten. | Entfernen in Phase 2; Regression durch `tools/pricing-check.php` Abschnitt D. |
| T3 | `Organization` ist auf allen Domains einheitlich die Müller Holding AG mit `https://mueller-holding.ag`; `WebSite`-Namen sind je Domain verschieden (SmartEinzug, lexoffice-einzug.de, lexware-einzug.de, Lastschrift einfach). | In Ordnung: eine Organisation, keine angeblich unabhängigen Anbieter. Der `WebSite`-Name der Leadseiten könnte den Bezug zu SmartEinzug tragen. | Beibehalten; Namensbezug in Phase 3 prüfen. |
| T4 | Keine domainübergreifende Messung: drei getrennte GA4-Properties (eine je Domain), kein `linker` in `gtag('config')`, `app.smart-einzug.de` nicht als Zieldomain konfiguriert. Der Übergang zur Anwendung wird in GA4 als neuer Ursprung gezählt; die Anwendung reicht die Herkunft nur über `?src=` und UTM-Parameter durch. | Datenlücke für die Erfolgskette Besuch bis Registrierung. | Empfehlung im Messkonzept: eine gemeinsame GA4-Property oder Cross-Domain-Linker plus dieselbe Property auf `app.smart-einzug.de` (Backend-Aufgabe, Einwilligung beachten). Keine Änderung ohne Abstimmung mit dem Backend. |
| T5 | Kampagnenseiten (`lp/`) und die Vergleichsseite sind `noindex, follow`, nicht in den Sitemaps, ohne eingehende interne Links. Die Canonicals der Leadseiten-Kampagnen nennen die URL ohne Schrägstrich, obwohl die Datei ein Ordner-Index ist. | Verhalten entspricht der Vorgabe (Anzeigenvarianten nicht indexieren). Canonical-Inkonsistenz ist bei noindex unkritisch. | Canonicals in Phase 2 vereinheitlichen; Indexierungsstatus in der Keyword-Map führen. |
| T6 | `lexware-einzug.de/lexware-office-lastschrifteinzug` ist indexierbar und in der Sitemap, hat aber keinen eingehenden internen Link (verwaist). | Verstoß gegen die Regel, dass jede organische Seite über andere Seiten erreichbar sein muss. | In Phase 3 verlinken oder im Maßnahmenplan zur Zusammenführung vorsehen (gleiche Intention wie `lexware-office-lastschrift`). |
| T7 | Kanonischer Host: http und www werden je Domain mit genau einem 301 auf https ohne www geleitet; `.html`-Endungen werden auf den Leadseiten extern per 301 entfernt und intern aufgelöst; smart-einzug.de arbeitet mit Ordner-URLs. Drei Alias-Domains leiten bekannte Pfade mit 301 auf smart-einzug.de und beantworten unbekannte Pfade mit 404, UTM-Parameter bleiben erhalten. | In Ordnung. | Keine Änderung. |
| T8 | `lastschrift-einfach.de` ist laut Betreiber eine Weiterleitung auf smart-abrechnen.de. Im Repository ist sie entgegen `docs/seo-url-map.csv` (dort als 301-Alias geführt) eine eigene Inhaltsseite mit 8 indexierbaren Seiten (Grundlagen, Mandat, Ablauf, Rücklastschrift, Glossar, Für wen) unter eigener Marke „Lastschrift einfach“, ohne SmartEinzug-Logo, mit Organisation Müller Holding AG. Sie wird im Deployment mit hochgeladen. | Vierte Inhaltsdomain, vom Betreiber im Auftrag nicht genannt; überschneidet sich thematisch mit den geplanten Wissensseiten (Mandat, Rücklastschrift). | Geklärt am 07.09.2026: nicht im SEO-Geltungsbereich; Bereinigung von Ordner und Upload als Empfehlung an das Backend (Maßnahmenplan M2). |
| T9 | H1 „Lexware-Office-Rechnungen per SEPA-Lastschrift einziehen.“ und fünf H2 sind auf smart-einzug.de und lexware-einzug.de identisch; die Startseiten von lexware-einzug.de und lexoffice-einzug.de teilen 132 gemeinsame Wortfolgen von 8 Wörtern (Ähnlichkeit 15,8 Prozent, unter der Grenze von 35 Prozent). | Kein technischer Duplikatfall, aber sichtbarer Hinweis auf die Konkurrenz um dieselbe Suchintention. | Überschneidungsanalyse und Keyword-Map. |
| T10 | Sicherheits-Header und CSP sind auf allen Domains gesetzt (HSTS mit preload, CSP nur Google-Tag-Hosts, `frame-ancestors 'none'`); Assets tragen inhaltsbasierte Versionsparameter (`tools/asset-version.py`) mit einjährigem Cache. HTML wird nicht gecacht. | In Ordnung. | Keine Änderung. |
| T11 | Google-Tags laden ausschließlich nach Einwilligung über `site.js`; die Datei ist auf allen Domains byteidentisch, Kennungen je Hostname. Google Ads Conversion-Tag nur auf lexware-einzug.de. | Entspricht der Projektregel. | Keine Änderung. |
| T12 | `robots.txt` erlaubt alles und verweist auf die Sitemap; keine Sperre von Ressourcen. Kein `noindex` per robots.txt (richtig, weil `noindex` im HTML lesbar bleiben muss). | In Ordnung. | Keine Änderung. |
| T13 | FAQPage-Markup ist auf mehreren Seiten vorhanden. Google zeigt FAQ-Rich-Results laut Dokumentation seit 07.05.2026 nicht mehr an. | Markup ist nicht schädlich, aber ohne Nutzen für die Darstellung; Inhalt der FAQ bleibt wertvoll. | Beibehalten, keine Strategie darauf aufbauen; bei Überarbeitung der Seiten entfernen oder belassen (keine Priorität). |
| T14 | Es gibt keine PDF-Dokumente im Frontend. Die Anwendung erzeugt nur eine Druckansicht des SEPA-Mandats der einziehenden Firma und die interne Admin-Dokumentation. | Vorgabe des Betreibers vom 07.09.2026 für künftige PDFs im CI der Müller Holding AG: Logo mindestens klein auf jeder Seite, auf dem Deckblatt mittelgroß bis groß, gerne mittig, immer ein Abschlussblatt. | Als Regel in `docs/seo/04-massnahmenplan.md` festgehalten; ein Infoblatt vor Vertragsschluss ist erst sinnvoll, wenn die Preisdarstellung freigegeben ist, weil vorvertragliche Information ohne Konditionen unvollständig wäre. |
| T15 | Deployment: `deploy-webhosting` lädt alle vier Inhaltsdomains, drei Alias-Ordner und die Statusseite per lftp hoch; `site-qa.py` läuft im Testjob nur, wenn `websites/` geändert wurde. Auslöser sind ausschließlich Pushes auf `main` oder den Backend-Branch. | In Ordnung. Änderungen dieses Frontend-Branches gehen erst nach Merge live. | Keine Änderung. |

### Datenlücken (Abschnitt 4 und 17 des Masterprompts)

- Search Console, Google Analytics, Google Ads: keine Daten im Repository und kein Zugriff aus dieser Umgebung. Tatsächliche Indexierung, Rankings, Klicks, Conversions je Domain sind unbekannt. Alle Aussagen zur Indexierung sind deshalb „technisch indexierbar“, nicht „indexiert“.
- Live-Stand der IONOS-Seiten: nicht abrufbar (Proxy 403). Laut `docs/ARBEITSSTAND.md` zeigte lexoffice-einzug.de am 07.09.2026 noch das feste Datum 31.12.2026, obwohl das Repository rollierend formuliert. Der Job `deploy-webhosting` ist vom Betreiber zu prüfen.
- Suchvolumen: keine Werkzeugdaten; `docs/ads-conversions.md` nennt die Keyword-Liste ausdrücklich als Entwurf ohne Volumenprüfung.


## 5. Leserperspektive

Ein Agent hat die Startseiten und drei Schlüsselseiten als potenzieller Kunde gelesen (Inhaber eines kleinen Unternehmens mit Lexware Office, ohne Stripe-Konto, ohne SEPA-Vorwissen). Bewertung 1 bis 5 (5 = sehr gut). Vollständig in der Arbeitsdatei `leser.json` (Workflow vom 07.09.2026).

| Seite | Note | Verstanden in zwei Bildschirmen | Wichtigste Verbesserung |
|---|---|---|---|
| smart-einzug.de/index.html | 4 | ja | Auf der Startseite kurz erklären, wie ein SEPA-Mandat je Kunde tatsächlich angelegt wird (IBAN, Unterschrift), statt das nur als Datenfeld zu erwähnen. |
| lexoffice-einzug.de/index.html | 4 | ja | Die Voraussetzungen (eigenes Stripe-Konto, Tarif Lexware Office XL) bereits im Hero nennen statt erst im Abschnitt 'Voraussetzungen' weiter unten. |
| lexware-einzug.de/index.html | 5 | ja | Erklären, wie und bis wann eingereichte Lastschriften tatsächlich beim Kunden abgebucht werden (Zeitfenster, Banklaufzeit bis zum Geldeingang). |
| smart-einzug.de/so-funktionierts/index.html | 4 | ja | Auf dieser Seite ebenfalls die Tarifvoraussetzung Lexware Office XL nennen, da Besucher hier direkt über Suchmaschinen einsteigen können, ohne vorher die Startseite gesehen zu haben. |
| smart-einzug.de/integrationen/lexware-office/index.html | 4 | ja | Kurz erwähnen, dass zusätzlich ein eigenes Stripe-Konto für den Einzug nötig ist, da diese Seite isoliert über Suchmaschinen aufgerufen werden kann. |
| lexoffice-einzug.de/lexoffice-lastschrift-einrichten.html | 5 | ja | Klarstellen, dass die Einrichtung des Stripe-Webhooks (Schritt 9) technisches Verständnis erfordert, statt an anderer Stelle durchgängig 'ohne Vorwissen' zu versprechen. |

Widersprüche zwischen den Domains (in Phase 2 bereinigt):

- Zeile 172 (smart-einzug.de/index.html): 'Alle drei Schritte durchlaufen Sie direkt im Anschluss an die Registrierung, geführt und ohne Vorwissen.' ↔ lexoffice-einzug.de/lexoffice-lastschrift-einrichten.html Zeile 547: Einrichtung eines Stripe-Webhooks mit sieben konkreten Ereignissen und Signing Secret wird 
- Zeile 175-177 (smart-einzug.de/index.html): Ablauf in drei Schritten. ↔ lexoffice-einzug.de/index.html Zeile 165-177 beschreibt denselben Ablauf in acht Schritten, lexoffice-einzug.de/lexoffice-lastschrift-einrichten.html Zeile 65-7
- Zeile 165-177 (lexoffice-einzug.de/index.html): Ablauf in acht Schritten. ↔ smart-einzug.de/index.html Zeile 170-177 und lexware-einzug.de/index.html Zeile 168-177 beschreiben denselben Vorgang in drei Schritten.
- Zeile 168-177 (lexware-einzug.de/index.html): Ablauf in drei Schritten. ↔ lexoffice-einzug.de/index.html Zeile 165-177 beschreibt denselben Ablauf in acht Schritten.
- Zeile 547 dieser Seite: Manuelle Einrichtung eines Stripe-Webhooks mit sieben konkreten Ereignistypen und Signing Secret als notwendiger Schritt für zeitnahe St ↔ smart-einzug.de/index.html Zeile 172: 'Alle drei Schritte durchlaufen Sie direkt im Anschluss an die Registrierung, geführt und ohne Vorwissen.' Der Webhook-Sch

Offene Fragen eines Interessenten, die die Seiten nicht beantworten (in Phase 2 und 3 adressiert):

- smart-einzug.de/index.html: Wie und wo wird ein SEPA-Mandat mit IBAN und Unterschrift des Kunden angelegt? Auf dieser Seite nur als vorhandenes Datenfeld erwähnt (Zeile 208), kein Ablauf beschrieben.
- smart-einzug.de/index.html: Gibt es ein Zeitfenster, in dem Lastschriften tatsächlich eingereicht werden, und wie lange dauert es bis zum Geldeingang? Nicht erwähnt.
- lexoffice-einzug.de/index.html: Warum wird die Voraussetzung Lexware Office XL nicht schon im Hero genannt, sondern erst im Abschnitt 'Voraussetzungen' bzw. der FAQ (Zeile 291)?
- lexoffice-einzug.de/index.html: Wie unterscheidet sich der Ablauf in acht Schritten hier von der Drei-Schritte-Darstellung auf smart-einzug.de für dasselbe Produkt?
- lexware-einzug.de/index.html: Wie wird das SEPA-Mandat inhaltlich erzeugt (Unterschrift des Kunden, Papier oder digital)? Nur 'Unterschrift und Gültigkeit im Blick behalten' (Zeile 323) erwähnt, kein Ablauf beschrieben.
- lexware-einzug.de/index.html: Gibt es ein festes Zeitfenster für die tatsächliche Einreichung der Lastschrift bei der Bank? Nicht erwähnt.
- lastschrift-einfach.de/index.html: Wird beim Klick auf den Link 'Mehr auf smart-einzug.de' (Zeile 437) klar, dass es sich um dasselbe Unternehmen und dieselbe Anwendung wie bei den anderen drei Domains handelt? Im Footer (Zeile 443) fe
- smart-einzug.de/so-funktionierts/index.html: Warum fehlt auf dieser 'So funktioniert's'-Seite die Tarifvoraussetzung Lexware Office XL, obwohl sie auf der Startseite und der Integrationsseite genannt wird?
- smart-einzug.de/so-funktionierts/index.html: Der Unterschied zwischen 'Freigeben' und dem tatsächlichen Einreichen der Lastschrift bei der Bank wird auch hier nicht angesprochen.
- smart-einzug.de/integrationen/lexware-office/index.html: Diese Seite erwähnt Stripe nicht, obwohl ein eigenes Stripe-Konto für den eigentlichen Einzug zwingend nötig ist (laut Startseite Zeile 77 und FAQ Zeile 272). Wer nur diese Integrationsseite liest, kö

## 6. Überschneidungen

Die Clusteranalyse (zwei Agenten: Zuordnung und Kritik) ordnet alle 72 indexierbaren und nicht indexierbaren Seiten der fünf Domains 28 Suchintentionen zu (`04-keyword-map.md`, `keyword-map.json`). Verteilung der Konflikte: 11 gleiche_intention_zwei_domains, 1 gleiche_intention_eine_domain, 1 duenn_und_doppelt, 9 falsche_domain_fuer_dauerinhalt, 6 keine.

Kernbefunde:

- Spiegelmuster zwischen lexware-einzug.de und lexoffice-einzug.de: sieben Seitenpaare bedienen dieselbe Intention mit demselben Angebot und unterscheiden sich im Wesentlichen durch die Schreibweise „lexoffice“ gegenüber „Lexware Office“. Das ist die größte strukturelle Schwäche des Bestands und der Kern des Maßnahmenplans M4.
- Doorway-Muster innerhalb von lexware-einzug.de: Startseite plus drei indexierbare Keyword-Seiten mit gleicher Gliederung und gleichem CTA für „Lexware Office Lastschrift“.
- Die Hauptdomain smart-einzug.de hat keine eigenen Wissens- und Anleitungsseiten (Mandat, Rücklastschrift, Vorabankündigung, Fristen, Sicherheit, Stripe-Anleitung); diese Inhalte liegen auf den Leaddomains. Nach Masterprompt gehören dauerhafte Inhalte auf die Hauptdomain, eine Verlagerung braucht aber Rankingdaten und Freigabe.
- Auf smart-einzug.de bedienen so-funktionierts und hilfe dieselbe Einrichtungsintention.
- Die Primärkandidaten für „Lexware Office verbinden“ und „Einrichtung bis zum ersten Einzug“ sind auf der Hauptdomain die dünnsten Seiten ihres Clusters; hier liegt der Ausbaubedarf von Phase 3.

Konfliktcluster (Auszug, Entscheidung nach Search-Console-Daten, Maßnahmenplan M4):

| Cluster | Konflikt | Bevorzugte Zielseite | Empfehlung | Freigabe |
|---|---|---|---|---|
| C01_lexware_office_lastschrift_einziehen | gleiche_intention_zwei_domains | https://smart-einzug.de/ | zusammenfuehren_pruefen | ja |
| C02_lexoffice_lastschrift | gleiche_intention_eine_domain | https://lexoffice-einzug.de/ | zusammenfuehren_pruefen | ja |
| C03_lastschrifteinzug_automatisieren | gleiche_intention_zwei_domains | https://lexware-einzug.de/lexware-office-lastschrifteinzug | ausbauen | nein |
| C04_lexware_office_verbinden_api | duenn_und_doppelt | https://smart-einzug.de/integrationen/lexware-office/ | ausbauen | nein |
| C05_stripe_rolle_verbinden | gleiche_intention_zwei_domains | https://lexware-einzug.de/lexware-office-stripe | zusammenfuehren_pruefen | ja |
| C06_stripe_verbinden_anleitung | falsche_domain_fuer_dauerinhalt | https://lexware-einzug.de/anleitung/stripe-verbinden | verlagern_pruefen | ja |
| C07_einrichtung_erster_einzug | gleiche_intention_zwei_domains | https://smart-einzug.de/so-funktionierts/ | ausbauen | nein |
| C08_hilfe_faq_hub | gleiche_intention_zwei_domains | https://smart-einzug.de/hilfe/ | zusammenfuehren_pruefen | ja |
| C09_funktionen | gleiche_intention_zwei_domains | https://smart-einzug.de/funktionen/ | zusammenfuehren_pruefen | ja |
| C10_preise | gleiche_intention_zwei_domains | https://smart-einzug.de/preise/ | zusammenfuehren_pruefen | ja |
| C11_sicherheit | falsche_domain_fuer_dauerinhalt | https://lexware-einzug.de/sicherheit | verlagern_pruefen | ja |
| C13_sevdesk | gleiche_intention_zwei_domains | https://smart-einzug.de/integrationen/sevdesk/ | behalten | nein |
| C15_sepa_mandat_verwalten_produkt | gleiche_intention_zwei_domains | https://lexware-einzug.de/lexware-office-sepa-mandat | zusammenfuehren_pruefen | ja |
| C16_sepa_mandat_wissen | falsche_domain_fuer_dauerinhalt | https://lexoffice-einzug.de/ratgeber/wie-funktioniert-ein-sepa-mandat | neu_auf_hauptdomain | ja |
| C17_mandat_einholen_bestandskunden | falsche_domain_fuer_dauerinhalt | https://lexoffice-einzug.de/ratgeber/sepa-mandat-einholen-bestandskunden | verlagern_pruefen | ja |
| C18_ruecklastschrift | falsche_domain_fuer_dauerinhalt | https://lexoffice-einzug.de/ratgeber/was-passiert-bei-einer-ruecklastschrift | neu_auf_hauptdomain | ja |
| C19_ablauf_fristen_zahlungsstatus | falsche_domain_fuer_dauerinhalt | https://lexware-einzug.de/ratgeber/sepa-lastschrift-fristen-vorlaufzeiten | verlagern_pruefen | ja |
| C20_vorabankuendigung | falsche_domain_fuer_dauerinhalt | https://lexware-einzug.de/ratgeber/vorabankuendigung-sepa-lastschrift | verlagern_pruefen | ja |
| C21_lastschrift_oder_ueberweisung | gleiche_intention_zwei_domains | https://lexoffice-einzug.de/ratgeber/lexoffice-lastschrift-oder-ueberweisung | behalten | nein |
| C22_lastschrift_verbuchen_lexware_office | falsche_domain_fuer_dauerinhalt | https://lexware-einzug.de/ratgeber/sepa-lastschrift-buchen-lexware-office | verlagern_pruefen | ja |
| C23_kunden_rechnungen_zuordnen | falsche_domain_fuer_dauerinhalt | https://lexoffice-einzug.de/ratgeber/kunden-und-rechnungen-richtig-zuordnen | verlagern_pruefen | ja |
| C24_ratgeber_uebersicht | gleiche_intention_zwei_domains | https://lexware-einzug.de/ratgeber/ | neu_auf_hauptdomain | ja |
