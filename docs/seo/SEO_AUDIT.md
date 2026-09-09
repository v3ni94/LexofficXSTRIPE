# Technisches und inhaltliches SEO-Audit

Stand: 10.09.2026, Branch `claude/frontend-smart-einzug-egsouk`, Ausgangsstand 1162b87. Geprüft wurden 62 indexierbare und noindex-Seiten auf fünf Inhaltsdomains, dazu Statusseite, Weiterleitungsdomain und fünf Alias-Domains. Grundlage ist ausschließlich der Code im Repository; ein Live-Abruf war nicht möglich.

## Executive Summary

Der technische Zustand ist überdurchschnittlich. Es gibt keine defekten internen Links, keine fehlenden oder fremden Canonicals, keine noindex-Seite in einer Sitemap, genau eine H1 je Seite, durchgängig semantisches HTML und vollständige Alt-Texte. Titel und Beschreibungen liegen in sinnvollen Längen. Damit fehlen die üblichen groben Fehler.

Die verbleibenden Punkte sind zweiter Ordnung und wurden, soweit risikoarm, in diesem Durchgang behoben: eine durch die Seitenzusammenführung neu entstandene verwaiste Seite, doppelte Titel und Beschreibung zwischen Haupt- und Leaddomain, ein nicht verzögert geladenes Fußzeilenbild auf 33 Seiten sowie acht ungenutzte Logodateien auf den beiden Leaddomains. Offen bleiben Punkte, die entweder Messdaten oder eine Entscheidung der Geschäftsführung brauchen.

Es gibt keine Blocker. Die Seitenmatrix führt nach den Korrekturen für 50 von 62 Seiten keinen einzigen Befund; die verbleibenden zwölf sind ausschließlich als LOW eingestuft (kurze Rechts- und Kontaktseiten, ein fehlendes Canonical auf einer noindex-Seite).

## Blockers

Keine. Kein versehentliches `noindex`, keine Googlebot-Sperre, keine fehlerhafte robots.txt, kein fehlendes Canonical, keine Weiterleitungsschleife.

## Quick Wins (in diesem Durchgang umgesetzt)

| Nr. | Befund | Wirkung | Risiko |
|---|---|---|---|
| F1 | Fußzeilenlogo ohne `loading="lazy"` auf 33 Seiten, nachweislich unterhalb des sichtbaren Bereichs (Zeile 331 von 359 auf der Startseite) | ein Bildabruf weniger im kritischen Ladepfad je Seitenaufruf | gering |
| F2 | Acht ungenutzte SmartEinzug-Logodateien auf lexware-einzug.de und lexoffice-einzug.de, null Referenzen in HTML und CSS | rund 117 KB je Domain weniger im Upload; zugleich Umsetzung der Leadseiten-Entscheidung, dort kein SmartEinzug-Logo vorzuhalten | gering |
| F3 | `lexware-einzug.de/lexware-office-lastschrifteinzug` war ohne eingehenden internen Link, entstanden durch die Zusammenführung vom 08.09.2026 | Seite ist wieder aus der Startseite und aus `lexware-sepa-einzug` erreichbar | gering |
| F4 | Identischer Titel und identische Beschreibung von `smart-einzug.de/agb/` und `lexware-einzug.de/agb`, identischer Titel der beiden Funktionsseiten | eindeutige Suchergebnisse je Domain, keine Selbstkonkurrenz im Snippet | gering |
| F5 | Drei Wissensbeiträge mit nur einem eingehenden internen Link | jeder Beitrag hat jetzt mindestens zwei thematisch passende Einstiege | gering |

## Technical SEO

**Crawlability.** Die robots.txt aller fünf Inhaltsdomains erlaubt alles und nennt die Sitemap. Kein Asset ist gesperrt. Die Navigation besteht aus echten `<a href>`-Links, kein Menüpunkt hängt an JavaScript. Von 62 Seiten hat keine einen defekten internen Link.

**Indexability.** Zehn Seiten stehen bewusst auf `noindex, follow`: fünf Kampagnenseiten unter `lp/`, die Vergleichsseite als Entwurf, die vier 404-Seiten und die Statusseite. Alle übrigen 52 Seiten sind indexierbar und in der Sitemap ihrer Domain.

**Sitemaps.** Fünf Sitemaps, alle Einträge kanonisch und indexierbar, keine 3xx- und keine 4xx-Adresse, `lastmod` aus der Git-Historie statt aus dem Erzeugungszeitpunkt. Das ist die richtige Wahl, weil ein Neuaufbau der Seiten sonst falsche Änderungsdaten meldet.

**Canonical.** Alle 52 indexierbaren Seiten tragen ein absolutes Self-Canonical, das exakt der Sitemap-Adresse entspricht. Kein Canonical zeigt auf eine Weiterleitung oder eine fremde Domain.

**Weiterleitungen.** 115 Regeln, alle als 301. Die Reihenfolge stimmt: Host- und Protokollkanonisierung zuerst, dann die Zusammenführungen vom 08.09.2026, erst danach das Entfernen der `.html`-Endung. Dadurch entsteht bei den zusammengeführten Adressen nur ein Sprung. Zwei Sprünge treten nur auf, wenn eine alte Adresse zusätzlich über `www` oder `http` aufgerufen wird; das ist unvermeidbar und unschädlich.

**JavaScript-SEO.** Der gesamte Inhalt einschließlich Titel, Beschreibung, Canonical und strukturierten Daten steht im ausgelieferten HTML. `site.js` ist mit `defer` eingebunden und erzeugt keinen indexierbaren Inhalt und keine internen Links. Es gibt keine Seite, die auf clientseitiges Rendern angewiesen ist.

**International SEO.** Einsprachig deutsch, keine Regionalfassungen. hreflang wäre hier falsch und wurde bewusst nicht ergänzt.

## On-Page SEO

Titel zwischen 17 und 63 Zeichen, Beschreibungen zwischen 70 und 165 Zeichen, nach der Korrektur ohne Dubletten über alle Domains. Jede Seite hat genau eine H1, die den Seiteninhalt benennt, darunter eine durchgehende H2- und H3-Gliederung. `header`, `nav`, `main` und `footer` sind auf allen 62 Seiten vorhanden, `article` auf 46, `section` auf 53.

Offen bleibt eine bewusste Überschneidung: Die Startseiten von smart-einzug.de und lexware-einzug.de tragen ähnliche H2-Überschriften, ebenso smart-einzug.de und lexoffice-einzug.de. Die Prüfung meldet das als Warnung. Weil die Seiten unterschiedliche Zielgruppen ansprechen und die Textähnlichkeit insgesamt unter der Grenze von 35 Prozent liegt, ist das vertretbar. Eine Änderung würde die Aussage der Startseiten verwässern.

## Content und Search Intent

Die Zuordnung von Suchabsicht zu Zielseite liegt bereits in `keyword-map.json` vor: 28 Suchintentionen, je genau eine bevorzugte Zielseite über alle Domains, geprüft von `tools/seo-map-check.py`. Dieses Audit hat daran nichts geändert.

Inhaltliche Lücken sind in `SEO_CONTENT_OPPORTUNITIES.md` beschrieben. Sie sind ausdrücklich Vorschläge. Keiner davon wurde umgesetzt, weil dafür Angaben nötig sind, die im Repository nicht belegt sind.

Die kürzesten Seiten sind Kontakt mit 68 Wörtern und die vier Impressen mit rund 200 Wörtern. Das ist bei Rechts- und Kontaktseiten normal und kein Mangel.

## Internal Linking

Vor der Korrektur hatte eine indexierbare Seite null und hatten drei Seiten einen eingehenden internen Link. Nach der Korrektur hat jede indexierbare Seite mindestens zwei. Die einzige Seite ohne eingehenden Link ist die Vergleichsseite, die als Entwurf bewusst auf `noindex` steht und nicht verlinkt werden soll.

Alle ergänzten Links sind kontextuelle Fließtextlinks mit beschreibendem Ankertext. Es wurden keine Linklisten in Fußzeilen angelegt und keine Ankertexte vervielfacht.

## Structured Data

Alle 52 indexierbaren Seiten tragen JSON-LD. Verteilung und Bewertung stehen in `SEO_SCHEMA_REPORT.md`. Kurz: `Organization` und `WebSite` auf den Einstiegsseiten, `BreadcrumbList` auf 43 Seiten, `Article` auf den elf Wissensbeiträgen, `SoftwareApplication` auf fünf Produktseiten, `FAQPage` genau einmal. Es wurde kein Schema-Typ ergänzt, weil für Bewertungen, Preise, Standorte und Personen keine belegten Angaben vorliegen.

## Image SEO

66 Bildeinbindungen in 33 Dateien, ausschließlich Logos und Bildmarken. Es gibt keine Inhaltsbilder. Alle 66 haben `width` und `height`, alle haben einen Alt-Text, die Logos liefern über `srcset` eine 2x-Fassung aus. Nach der Korrektur laden alle 33 Fußzeilenlogos verzögert; das Kopfzeilenlogo bleibt bewusst ohne Verzögerung, weil es oberhalb des sichtbaren Bereichs steht.

Nicht umgesetzt: `fetchpriority="high"` auf dem Kopfzeilenlogo. Ob das Logo oder die Überschrift das größte Element im sichtbaren Bereich ist, lässt sich ohne Messung nicht entscheiden. Eine falsche Priorisierung verschlechtert den Wert, statt ihn zu verbessern. Der Punkt steht in der Vorschlagsliste.

## Core Web Vitals

Ohne Messung sind keine Werte behauptbar. Belegbar sind die strukturellen Voraussetzungen:

- **LCP.** Kein Framework, kein Hydrieren, ein einziges Stylesheet mit 33 KB, ein JavaScript mit 9 KB und `defer`, keine Webfonts. Der wahrscheinlich größte Inhaltsbereich ist die Überschrift im Hero, auf 33 Seiten alternativ das Kopfzeilenlogo mit 23 KB.
- **INP.** `site.js` enthält keine langlaufenden Aufgaben, kein Framework und keine Drittanbieterskripte im Startpfad. Google-Skripte laden erst nach ausdrücklicher Einwilligung, also nie im ersten Ladevorgang.
- **CLS.** Alle Bilder haben feste Abmessungen. Es gibt keine nachgeladenen Einbettungen und keine Webfonts, die einen Umbruch auslösen könnten. Das Einwilligungsbanner wird über dem Inhalt eingeblendet und schiebt ihn nicht.

Der Serveranteil (TTFB) liegt beim IONOS-Webhosting und ist aus dem Frontend nicht beeinflussbar.

## Mobile

Alle Seiten setzen `width=device-width, initial-scale=1.0`. Das Layout arbeitet mit relativen Einheiten, es gibt eine eigene mobile Handlungsleiste. Feste Pixelbreiten, die einen waagerechten Bildlauf erzwingen, sind im Stylesheet nicht angelegt.

## Redirects

Vollständig in `SEO_REDIRECT_MAP.csv`. Alle Regeln sind bereits als echte 301 in den jeweiligen `.htaccess` umgesetzt und werden mit dem Upload ausgeliefert. Es gibt keine JavaScript-Weiterleitung als Ersatz für einen HTTP-Status.

## Monitoring

Search Console und GA4 laufen. Ergänzend empfohlen, aber nicht eingerichtet, weil dafür Zugänge außerhalb des Repositorys nötig sind: Property je Domain in der Search Console, Beobachtung des Index-Abdeckungsberichts nach dem nächsten Upload wegen der 27 zusammengeführten Adressen, und ein Blick auf den Bericht zu Weiterleitungen.

## Unspecified Items

- Live-HTTP-Status und Antwortzeiten je Seite.
- Search-Console-, Analytics- und Ads-Kennzahlen, damit auch jede Aussage zu Rankings, Klicks und Suchvolumen.
- Lighthouse- und Feldwerte zu LCP, INP und CLS.
- Pflichtangaben der DETM Management Consulting FZCO, weiterhin als `[wird ergänzt]` markiert.
