# Änderungsverlauf der SEO-Arbeiten

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
