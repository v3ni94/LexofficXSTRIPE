# Änderungsverlauf der SEO-Arbeiten

## 12.09.2026, Version 4.71: Google Ads fand das Tag nicht, Ursache war die Sicherheitsrichtlinie

Google Ads meldete nach dem Deployment weiterhin, es finde kein Tag, obwohl der Loader im Quelltext der
Startseite nachweislich stand. Die Ursache lag nicht am Tag, sondern an der Content-Security-Policy der
Domains: `www.googleadservices.com` war nur in `script-src` erlaubt, also zum Laden des Skripts. Die
Conversion meldet das Tag aber als Bild oder `fetch` an denselben Host, und dafür gelten `img-src` und
`connect-src`. Beide führten den Host nicht, der Browser blockierte die Meldung.

Der Fehler hat kein sichtbares Symptom: Die Seite lädt vollständig, das Tag steht im Quelltext, die
Tag-Prüfung von Google bleibt trotzdem erfolglos.

| Nr. | Änderung | Dateien | Wirkung |
|---|---|---|---|
| G1 | `www.googleadservices.com`, `stats.g.doubleclick.net` und `td.doubleclick.net` in `img-src` und `connect-src` ergänzt | `.htaccess` aller fünf Marketingdomains | der Conversion-Ping erreicht Google; keine Platzhalter, kein `unsafe-inline` |
| G2 | Prüfung des Rückkanals in `tools/site-tag-check.py` | `tools/site-tag-check.py` | jede Domain mit Ads-Kennung (Seitenkopf oder Zuordnung in `site.js`) muss die Ziel-Hosts auch in `img-src` und `connect-src` führen |

Gegenprobe: Mit dem alten Zustand meldet `site-tag-check.py` den Fehler und endet mit Exit 1, mit dem
neuen Zustand 0 Fehler. Übrige Prüfungen nach der Änderung: `site-qa.py` 0 Fehler und 2 bekannte
Warnungen, `seo-linkcheck.py` 0 Fehler, `seo-map-check.py` 0 Fehler, `app-tracking-check.php` 57 von 57,
`pricing-check.php` 14 von 14.

An der Einwilligung ändert sich nichts. Ohne Zustimmung bleiben alle vier Speicherarten auf `denied`,
Google setzt keine Cookies und nutzt keine Werbekennungen.

## 10.09.2026, Version 4.56: Technisches Audit und risikoarme Korrekturen

Grundlage: vollständiges technisches Audit aller 62 Seiten (`SEO_AUDIT.md`). Umgesetzt wurden ausschließlich Änderungen mit geringem Risiko; alles Weitere steht als Vorschlag in `SEO_CONTENT_OPPORTUNITIES.md` und `SEO_SCHEMA_REPORT.md`.

| Nr. | Änderung | Dateien | Wirkung |
|---|---|---|---|
| F1 | Fußzeilenlogo lädt verzögert (`loading="lazy"`, `decoding="async"`) | 33 HTML-Dateien auf smart-einzug.de | ein Bildabruf weniger im kritischen Ladepfad; das Kopfzeilenlogo bleibt bewusst unverändert |
| F2 | Acht ungenutzte SmartEinzug-Logodateien entfernt | `lexware-einzug.de/assets/img/`, `lexoffice-einzug.de/assets/img/` | rund 117 KB je Domain weniger; setzt zugleich die Leadseiten-Entscheidung um, dort kein SmartEinzug-Logo vorzuhalten |
| F3 | Verwaiste Seite wieder verlinkt | `lexware-einzug.de/index.html`, `lexware-sepa-einzug.html` | `lexware-office-lastschrifteinzug` hat wieder zwei eingehende Links |
| F4 | Doppelte Titel und Beschreibung aufgelöst | `lexware-einzug.de/agb.html`, `funktionen.html` | keine Dubletten mehr über alle fünf Domains |
| F5 | Vier kontextuelle Links auf schwach angebundene Beiträge | Anleitungen und Wissensbeiträge auf smart-einzug.de, `lexware-einzug.de/funktionen.html` | jede indexierbare Seite hat mindestens zwei eingehende interne Links |
| F6 | Neuer Prüfer `tools/seo-linkcheck.py` | `tools/`, Test-Job im Workflow | defekte Links und verwaiste Seiten fallen künftig im Arbeitsablauf auf, nicht erst im Audit |

Prüfergebnis nach den Änderungen: `site-qa.py` 0 Fehler und 2 bekannte Warnungen, `seo-linkcheck.py` 0 Fehler und 0 Warnungen, `seo-map-check.py` 0 Fehler, `pricing-check.php` 14 von 14, `docs-build-check.py` 0 Fehler.

Nicht umgesetzt und bewusst als Vorschlag belassen: `fetchpriority` auf dem Kopfzeilenlogo (ohne Messung nicht entscheidbar), automatische Pflege von `dateModified`, Ausbau der Funktionsseite der Leaddomain, neue Wissensbeiträge.

## Frühere Arbeitspakete

Bestandsaufnahme, Faktenregister, Aussagenprüfung, Themen- und URL-Zuordnung, Maßnahmenplan und Abschlussbericht stehen in `01-bestandsaufnahme.md` bis `07-abschlussbericht.md`. Die Zusammenführung der Leaddomain-Inhalte vom 08.09.2026 ist in `05-massnahmenplan.md` unter M4 beschrieben.
