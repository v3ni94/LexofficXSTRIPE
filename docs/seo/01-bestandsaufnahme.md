# Bestandsaufnahme der Marketingseiten

Stand: 07.09.2026, Repository-Stand abd5d26, Branch `claude/frontend-smart-einzug-egsouk`. Grundlage sind das Repository, `tools/seo-inventory.py`, `tools/site-qa.py`, die Faktenprüfung des Programmcodes (`02-faktenregister.md`), die Prüfung der Werbeaussagen (`03-aussagenpruefung.md`) und die Überschneidungsanalyse (`04-keyword-map.md`). Live-Abrufe und Google-Daten waren nicht verfügbar (siehe Datenlücken).

## 1. Ziel und Vorgehen

Der Masterprompt verlangt zuerst Bestandsaufnahme und Faktenprüfung, dann die Bereinigung falscher oder nicht freigegebener Aussagen, danach die Verbesserung der wichtigsten Seiten und eine kleine Gruppe neuer Inhalte. Größere Zusammenführungen, URL-Wechsel und Indexierungsänderungen stehen im Maßnahmenplan (`05-massnahmenplan.md`) und warten auf Freigabe. Leitregel: eine hilfreiche Seite verbessern ist wertvoller als drei ähnliche Seiten veröffentlichen.

## 2. Domains und Rollen

| Domain | Rolle laut Masterprompt | Ist-Zustand |
|---|---|---|
| smart-einzug.de | Produktmarke, Hauptwebsite, organischer Schwerpunkt für Produkt-, Integrations-, Wissens-, Anleitungs- und Vertrauensinhalte | Produktseiten (Funktionen, Preise, So funktioniert's, Integrationen, Hilfe, Kontakt), sevdesk-Vorankündigung mit Vormerkung, zwei Kampagnenseiten (noindex), Vergleichsseite (noindex, Entwurf). Keine Anleitungen, keine Wissensseiten, keine Sicherheitsseite. |
| lexoffice-einzug.de | Einstiegswebsite für Suchende mit dem Begriff „lexoffice“, erklärt die Beziehung zu Lexware Office und SmartEinzug | Startseite, sechs Keyword-Seiten, Anleitung, Umbenennungsseite, Public-API-Seite, sieben Ratgeberartikel (allgemeines SEPA-Wissen), Kampagnenseite (noindex). SmartEinzug-Logo und Fußzeile vorhanden. |
| lexware-einzug.de | Einstiegswebsite für Interessenten mit konkretem Einzugsbedarf aus Lexware Office | Startseite, Funktionen, Preise, Sicherheit, Hilfe, FAQ, acht Keyword-Seiten, zwei Anleitungen, vier Ratgeberartikel, Kampagnenseite (noindex). Google-Ads-Tag nur hier. |
| lastschrift-einfach.de | im Auftrag nicht genannt; laut `docs/seo-url-map.csv` Alias | tatsächlich eigene Inhaltsseite „Lastschrift einfach“ mit sechs Wissensseiten für Zahlungspflichtige und Einsteiger (Maßnahmenplan M2) |
| smarteinzug.de, smart-lastschrift.de, einzug-direkt.de | technische Aliase | 301 auf smart-einzug.de, unbekannte Pfade 404, UTM bleibt erhalten |

Die Kundenanwendung läuft unter `app.smart-einzug.de`, der Adminbereich unter `admin.smart-einzug.de`; beide sind nicht Teil dieses Pakets.

## 3. URL-Inventar (Kurzfassung)

Vollständig in `url-inventar.md` und `url-inventar.json`.

| Domain | HTML-Dateien | technisch indexierbar | in Sitemap | verwaist (indexierbar ohne eingehenden Link) | Seiten mit Produktpreis | Seitentypen der indexierbaren Seiten |
|---|---|---|---|---|---|---|
| smart-einzug.de | 16 | 12 | 12 | 0 | 8 | integration 3, produktseite 4, rechtliches_kontakt 4, startseite 1 |
| lexoffice-einzug.de | 22 | 20 | 20 | 0 | 4 | anleitung 1, keyword_landingpage 7, ratgeber 7, ratgeber_uebersicht 1, rechtliches_kontakt 3, startseite 1 |
| lexware-einzug.de | 26 | 24 | 24 | 1 | 6 | anleitung 2, keyword_landingpage 8, produktseite 5, ratgeber 4, ratgeber_uebersicht 1, rechtliches_kontakt 3, startseite 1 |
| lastschrift-einfach.de | 10 | 9 | 9 | 0 | 0 | rechtliches_kontakt 2, startseite 1, wissen 6 |

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
| T8 | `lastschrift-einfach.de` ist entgegen `docs/seo-url-map.csv` (dort als 301-Alias geführt) eine eigene Inhaltsseite mit 8 indexierbaren Seiten (Grundlagen, Mandat, Ablauf, Rücklastschrift, Glossar, Für wen) unter eigener Marke „Lastschrift einfach“, ohne SmartEinzug-Logo, mit Organisation Müller Holding AG. Sie wird im Deployment mit hochgeladen. | Vierte Inhaltsdomain, vom Betreiber im Auftrag nicht genannt; überschneidet sich thematisch mit den geplanten Wissensseiten (Mandat, Rücklastschrift). | Entscheidung des Betreibers nötig: als Wissensdomain für Zahlungspflichtige behalten, auf smart-einzug.de verlagern oder wie dokumentiert zum Alias machen. In der Keyword-Map geführt, keine Änderung ohne Freigabe. |
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

(wird nach Abschluss der Leserprüfung ergänzt)

## 6. Überschneidungen

(Kurzfassung folgt aus `04-keyword-map.md`)
