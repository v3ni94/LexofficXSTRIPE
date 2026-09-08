# SEO, Inhalte und Landingpages: Arbeitsordner

Stand: 07.09.2026. Dieser Ordner bündelt die Arbeitsergebnisse zum Masterprompt „SEO-, Content- und Landingpage-Ausbau für SmartEinzug“ (Auftrag vom 07.09.2026, Frontend-Branch `claude/frontend-smart-einzug-egsouk`). Betroffen sind die statischen Marketingseiten unter `websites/`. Anwendung (`app.smart-einzug.de`) und Adminbereich (`admin.smart-einzug.de`) bleiben unverändert.

| Dokument | Inhalt | Status |
|---|---|---|
| `url-inventar.md`, `url-inventar.json` | URL-Inventar der Marketingdomains, erzeugt von `tools/seo-inventory.py` (Title, H1, Canonical, Indexierung, Sitemap, Verlinkung, Preise, Prüfwörter) | erzeugt (72 Seiten, sechs Domains), bei jeder Änderung neu ausführen |
| `01-bestandsaufnahme.md` | Bestandsaufnahme: Domainrollen, technische Prüfung, Leserperspektive, Datenlücken | erstellt, Stand 08.09.2026 |
| `02-faktenregister.md` | Faktenregister: belegte Tatsachen über die Software mit Quelle, Status (bestätigt, geplant, ungeklärt, veraltet) und zulässiger Formulierung | erstellt, Stand 08.09.2026 |
| `03-aussagenpruefung.md` | Abgleich der Werbeaussagen je Domain mit Verdikt, Korrektur und Verifikation | erstellt, Stand 08.09.2026 |
| `keyword-map.json`, `04-keyword-map.md` | Gemeinsame Themen- und URL-Zuordnung über alle Domains (SEO_KEYWORD_MAP), geprüft durch `tools/seo-map-check.py` | erstellt, Stand 08.09.2026 |
| `05-massnahmenplan.md` | Größere Eingriffe, Entscheidungen (DETM, sevdesk-Domains), die eine Freigabe der Geschäftsführung brauchen (Zusammenführungen, Weiterleitungen, Indexierung, AGB, Preisdarstellung, vierte Domain) | erstellt, Stand 08.09.2026 |
| `06-mess-und-pflegekonzept.md` | Erfolgskette, Ereignisse, Datenlücken, Redaktions- und Prüfplan, Freigabezustände | erstellt, Stand 08.09.2026 |

## Werkzeuge

- `python3 tools/seo-inventory.py` schreibt das Inventar neu.
- `python3 tools/seo-map-check.py` prüft die Keyword-Map gegen den Dateibestand (jede indexierbare Seite genau einmal zugeordnet, eine Primärseite je Cluster, Indexierung stimmt mit dem robots-Meta überein).
- `php tools/pricing-check.php` erzwingt seit dem 07.09.2026 zusätzlich, dass die Marketingseiten keine Produktpreise nennen (Abschnitt D); AGB-Seiten werden nur gemeldet.
- `python3 tools/site-qa.py`, `python3 tools/asset-version.py`, `python3 tools/build-sitemaps.py` (lastmod aus der Git-Historie) wie bisher.

## Verbindliche Vorgaben des Betreibers (07.09.2026)

- Öffentliche Preisbeträge werden vorerst nicht ausgespielt, auch nicht in Metadaten, strukturierten Daten oder Textbausteinen. Konditionen zeigt der Buchungsprozess der Anwendung. Bestehende Verträge und die tatsächliche Abrechnung ändern sich dadurch nicht.
- Entscheidung vom 07.09.2026: lexoffice-einzug.de und lexware-einzug.de sind Leadseiten der DETM Management Consulting FZCO (Domaininhaber, Anbieter der Leadseiten, Abrechnung mit der Müller Holding AG). Kein SmartEinzug-Logo auf diesen Domains, Farben und Gestaltung bleiben; Impressum und Datenschutz nennen DETM, offene Pflichtangaben bleiben leer bis zur Lieferung. Die Seiten bewerben weiterhin das Produkt SmartEinzug der Müller Holding AG und dürfen nicht als eigener Softwareanbieter erscheinen.
- sevdesk bleibt bis zur nachgewiesenen Freigabe „geplant“, angestrebter Start Ende September 2026, Vorregistrierung ist keine nutzbare Integration.
- Keine Partnerschafts- oder Zertifizierungsbehauptung zu Lexware, sevdesk oder Stripe.
- PDF-Dokumente im CI der Müller Holding AG tragen das Logo mindestens klein auf jeder Seite, auf dem Deckblatt mittelgroß bis groß (gerne mittig) und schließen immer mit einem Abschlussblatt. Derzeit gibt es keine PDFs im Frontend.
- Größere Zusammenführungen, URL-Wechsel und Indexierungsänderungen nur nach Freigabe, dokumentiert im Maßnahmenplan.
