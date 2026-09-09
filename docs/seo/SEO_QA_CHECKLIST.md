# SEO-Prüfliste für Änderungen an den Marketingseiten

Stand: 10.09.2026. Abzuarbeiten vor jedem Commit, der `websites/` berührt. Die Befehle stehen in `SEO_QA_COMMANDS.md`.

## Vor dem Commit

- [ ] `python3 tools/site-qa.py` liefert 0 Fehler.
- [ ] `python3 tools/seo-linkcheck.py` liefert 0 Fehler und möglichst 0 Warnungen.
- [ ] `python3 tools/seo-map-check.py` liefert 0 Fehler. Neue Seiten sind vorher in `keyword-map.json` eingetragen.
- [ ] `php tools/pricing-check.php` besteht alle Prüfungen. Kein Preisbetrag auf einer statischen Seite, auch nicht in Metadaten oder JSON-LD.
- [ ] `python3 tools/asset-version.py`, danach `python3 tools/build-sitemaps.py`, danach `python3 tools/seo-inventory.py`.

## Bei einer neuen Seite

- [ ] Eintrag in `docs/seo/keyword-map.json` mit Rolle, Indexierungsentscheidung und Begründung, bevor die Seite entsteht.
- [ ] Genau eine H1, die den Seiteninhalt benennt.
- [ ] Titel und Beschreibung sind über alle fünf Domains eindeutig.
- [ ] Absolutes Self-Canonical.
- [ ] Mindestens zwei eingehende interne Links aus thematisch passenden Seiten, im Fließtext, mit beschreibendem Ankertext.
- [ ] In der Sitemap der Domain enthalten, falls indexierbar.
- [ ] Textähnlichkeit zu allen anderen Seiten unter 35 Prozent.
- [ ] Strukturierte Daten nur für Angaben, die sichtbar auf der Seite stehen.

## Bei einer gelöschten oder verschobenen Seite

- [ ] 301 in der `.htaccess` der betroffenen Domain, vor der Regel zum Entfernen der `.html`-Endung.
- [ ] Alle internen Links auf die alte Adresse umgeschrieben, nicht auf die Weiterleitung zeigen lassen.
- [ ] `python3 tools/seo-linkcheck.py` prüfen: die Zusammenführung vom 08.09.2026 hat genau hier eine Seite verwaist zurückgelassen.
- [ ] Eintrag in `keyword-map.json` entfernen oder auf die neue Adresse umschreiben.
- [ ] Nach dem Upload prüfen, ob die Altdatei auf dem Hoster entfernt wurde. Der Upload spiegelt die Website-Ordner mit `--delete`.

## Bei einem Bild

- [ ] `width` und `height` gesetzt, damit kein Layoutsprung entsteht.
- [ ] Alt-Text, der beschreibt, was zu sehen ist; bei rein schmückenden Bildern `alt=""`.
- [ ] Unterhalb des sichtbaren Bereichs `loading="lazy"` und `decoding="async"`.
- [ ] Oberhalb des sichtbaren Bereichs **kein** `loading="lazy"`.
- [ ] Bei Logos die 2x-Fassung über `srcset` ausliefern.

## Bei Google-Tags

- [ ] Kennungen ausschließlich in `assets/js/site.js`, nie als Skript direkt im HTML.
- [ ] Laden erst nach ausdrücklicher Einwilligung.
- [ ] Datenschutzerklärung der betroffenen Domain beschreibt den tatsächlichen Zustand.
- [ ] Content-Security-Policy der Domain erlaubt die nötigen Google-Adressen.

## Nach dem Upload

- [ ] Search Console: Index-Abdeckung auf neue Fehler prüfen, besonders nach Zusammenführungen.
- [ ] Stichprobe der Weiterleitungen mit `curl -sSIL`, Ziel muss mit einem Sprung erreichbar sein.
- [ ] Bei neuen Domains: Zuordnung beim Hoster und SSL prüfen.
