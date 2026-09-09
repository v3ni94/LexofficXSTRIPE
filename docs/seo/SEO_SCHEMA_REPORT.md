# Bericht zu strukturierten Daten

Stand: 10.09.2026. Erhoben aus dem ausgelieferten HTML aller 62 Seiten. Bewertet wird, ob die ausgezeichneten Angaben durch sichtbaren Seiteninhalt oder belegte Projektdaten gedeckt sind.

## 1. Bestand

| Typ | Seiten | Beispiel |
|---|---|---|
| `BreadcrumbList` mit `ListItem` | 43 | https://smart-einzug.de/agb/ |
| `Organization` | 24 | https://smart-einzug.de/ |
| `WebSite` | 11 | https://smart-einzug.de/ |
| `Article` | 11 | https://smart-einzug.de/wissen/fristen-und-vorlaufzeiten/ |
| `SoftwareApplication` | 5 | https://smart-einzug.de/ |
| `PostalAddress` | 1 | https://smart-einzug.de/ |
| `FAQPage` mit `Question` und `Answer` | 1 | https://smart-einzug.de/integrationen/sevdesk/ |

Alle 52 indexierbaren Seiten tragen mindestens einen Block. Es gibt keine Seite ohne strukturierte Daten und keinen widersprüchlichen Doppelblock.

## 2. Bewertung je Typ

**Organization.** Auf der Hauptdomain die Müller Holding AG mit Anschrift als `PostalAddress`; auf den vier Leaddomains die DETM Management Consulting FZCO ohne Anschrift, weil die Pflichtangaben noch fehlen. Das ist korrekt: Eine erfundene Anschrift wäre schlimmer als eine fehlende. Sobald die DETM-Angaben vorliegen, gehört `address` dort ergänzt.

**WebSite.** Vorhanden auf den Einstiegsseiten. `SearchAction` ist nicht ausgezeichnet und darf es auch nicht sein, weil keine der Domains eine eigene Suchfunktion hat.

**BreadcrumbList.** Auf 43 Seiten, die Pfade entsprechen der sichtbaren Brotkrumennavigation. Auf den Einstiegsseiten der Domains fehlt sie richtigerweise.

**Article.** Auf den elf Wissensbeiträgen mit `headline`, `inLanguage`, `author`, `publisher`, `datePublished`, `dateModified` und `mainEntityOfPage`. Autor und Herausgeber sind die Müller Holding AG als Organisation, keine erfundene Person. `datePublished` und `dateModified` stehen beide auf dem Erstellungsdatum.

*Offener Punkt:* `dateModified` wird derzeit nicht mitgeführt. Wird ein Beitrag inhaltlich überarbeitet, muss das Datum von Hand angehoben werden, sonst meldet die Auszeichnung einen falschen Stand. Vorschlag in Abschnitt 4.

**SoftwareApplication.** Auf fünf Produktseiten mit Name, Anbieter und Kategorie, ausdrücklich **ohne** `offers`. Das ist die Umsetzung der Preisvorgabe vom 07.09.2026: Solange keine Preisbeträge veröffentlicht werden, darf auch kein `Offer` mit Preis ausgezeichnet werden. `tools/pricing-check.php` Abschnitt D erzwingt das.

**FAQPage.** Genau einmal, auf der sevdesk-Integrationsseite. Die ausgezeichneten Fragen und Antworten stehen dort sichtbar auf der Seite. Weitere Seiten enthalten zwar Frageblöcke, sind aber nicht als `FAQPage` ausgezeichnet. Das ist vertretbar, weil Google FAQ-Auszeichnungen nur noch eingeschränkt darstellt und eine breitere Auszeichnung kein verlässlicher Gewinn ist.

## 3. Bewusst nicht ergänzt

| Typ | Warum nicht |
|---|---|
| `AggregateRating`, `Review` | Es liegen keine echten Kundenbewertungen vor. Eine Auszeichnung ohne Bewertungen ist ein Verstoß gegen Googles Richtlinien und abmahnfähig. |
| `Product` mit `offers` | Preisbeträge sind bis zur Freigabe nicht öffentlich. Ein `Offer` ohne Preis bringt nichts, mit Preis verstieße es gegen die Vorgabe. |
| `LocalBusiness` | Das Angebot ist rein digital, es gibt kein Ladengeschäft und keine Öffnungszeiten. |
| `Person` als Autor | Es ist keine namentliche Autorenschaft festgelegt. Ein erfundener Name wäre eine Falschangabe. |
| `Service`, `HowTo` | `HowTo` wird von Google in den Ergebnissen nicht mehr ausgespielt. `Service` brächte ohne Preis- und Gebietsangaben keinen Mehrwert. |

## 4. Vorschläge, nicht umgesetzt

1. **`dateModified` pflegen.** Eine Prüfung ergänzen, die für jede Datei unter `websites/smart-einzug.de/wissen/` das `dateModified` im JSON-LD mit dem Datum des letzten Commits dieser Datei vergleicht und bei Abweichung warnt. Das Muster gibt es bereits in `tools/build-sitemaps.py`, das `lastmod` genauso aus der Git-Historie zieht. Aufwand gering, Nutzen dauerhaft.
2. **`Organization.address` auf den Leaddomains**, sobald die DETM-Pflichtangaben vorliegen. Bis dahin unverändert lassen.
3. **Validierung im Arbeitsablauf.** Der Test-Job prüft strukturierte Daten bisher nicht. Ein einfacher Prüfer, der jeden JSON-LD-Block auf gültiges JSON, vorhandenen `@context` und `@type` prüft, ließe sich zu `tools/site-qa.py` ergänzen. Eine Prüfung gegen Googles Rich-Results-Test ist aus dem Arbeitsablauf heraus nicht möglich, weil dafür ein Netzzugang zu Google nötig wäre.
