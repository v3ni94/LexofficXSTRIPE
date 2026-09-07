# Dokumentationssystem: Quellen, Erzeugung, Rechte, Pflege

Stand 07.09.2026 (Version 4.32). Dieses Kapitel beschreibt das Dokumentationssystem selbst, damit es ohne mündliche Einführung gepflegt werden kann.

## Drei Dokumentationen aus einer Quelle

| Code | Dokument | Zielgruppe | Klassifizierung | Zugriff |
|---|---|---|---|---|
| `unternehmen` | Unternehmens- und Verkaufsdokumentation | Kaufinteressenten, Prüfer, Übernehmer | intern | Plattformadministratoren (Adminbereich), nicht für Mitarbeiter- oder Supportrollen; externe Weitergabe nur als freigegebene Fassung |
| `entwickler` | Entwickler- und Betriebsdokumentation | Programmierer, Administratoren | streng vertraulich | Plattformadministratoren (Entscheidung des Vorstands vom 07.09.2026); zusätzlich Adressen aus `docs.technical_readers` mit Plattformadminrolle; nie Mitarbeiter- oder Supportrollen |
| `kunden` | Benutzerhandbuch | Kunden | kundenbezogen | angemeldete Benutzer der Kundenanwendung über `handbuch.php`, Plattformadministratoren im Adminbereich |

Alle drei Dokumente entstehen aus Markdown-Quellen unter `docs/`. Die Zuordnung Kapitel zu Dokument steht ausschließlich in `DOCUMENTS` in `tools/build-docs.py`. Webansicht (HTML mit Inhaltsverzeichnis, Kapitelnavigation, Suche), Gesamt-PDF und Kapitel-PDFs werden aus denselben Quellen erzeugt; es gibt keine getrennt gepflegten Fassungen.

Ordner: `docs/unternehmen/`, `docs/entwickler/`, `docs/kunden/`, `docs/vps/` und weitere Fachkapitel auf `docs/`-Ebene (Betrieb), `docs/diagramme/` (Mermaid-Quellen und gerenderte SVG/PNG in `build/`), `docs/anlagen/` (unveränderte Originalunterlagen), `docs/ci/` (Logo und Wasserzeichen der Müller Holding AG für die PDF), `docs/dokumentationsregeln/` (dieses Kapitel, `revisionen.json`).

## Erzeugung

1. Diagramme: `python3 tools/render-mermaid.py` rendert jede `docs/diagramme/*.mmd` nach `build/<name>.svg` und `.png` (Playwright-Chromium, lokales mermaid.min.js, kein Netzabruf beim Rendern). Die gerenderten Dateien werden mit eingecheckt; `build/hashes.json` hält den Quellstand fest, `--check` prüft ihn.
2. Datenwörterbuch: `python3 tools/gen-datenwoerterbuch.py` erzeugt `docs/entwickler/datenwoerterbuch.md` aus `php-ionos/sql/schema.sql` und `docs/entwickler/tabellen-beschreibungen.json`; `--check` prüft die Aktualität.
3. Dokumente: `python3 tools/build-docs.py` schreibt nach `php-ionos/app/docs-build/` (gitignored): je Dokument `<code>/index.html`, `<code>/suche.json`, `<code>/kapitel-NN-<slug>.pdf`, `<code>.pdf`, dazu `diagramme/*`, die Anlagen und `manifest.json` (Schema 2). PDFs im CI der Müller Holding AG: Deckblatt mit Klassifizierung, Softwarestand, Dokumentrevision, klickbares Inhaltsverzeichnis, Kopfzeile, Fußband mit Pflichtangaben, Seitenzahlen, Querformatseiten für breite Tabellen und Schaubilder. Schrift Carlito (im Workflow installiert), sonst Ersatzschrift mit Vermerk im Manifest.
4. Prüfung: `python3 tools/docs-build-check.py` (Kapitelquellen vorhanden, genau eine Überschrift Ebene 1 je Quelle, Diagramme gerendert und aktuell, Datenwörterbuch aktuell, Revisionen gepflegt, Manifest vollständig, keine Geheimnisse in den Ausgaben, Versionsmetadaten stimmen).

Der GitHub-Workflow (`.github/workflows/deploy.yml`, Job `test`) führt die Prüfung und den Build aus und liefert das Ergebnis mit dem Release aus; das Deployment (`deploy/vps/scripts/deploy.sh`) archiviert den ausgelieferten Stand zusätzlich nach `/opt/smarteinzug/shared/docs-archive/<version>_<commit>/` (Historie, unabhängig von der Aufbewahrung der GitHub-Artefakte). PDFs werden nie beim Seitenaufruf erzeugt.

## Auslieferung und Rechte

- `php-ionos/admin-doc.php` liefert ausschließlich Dateien aus `manifest.json` (Allowlist, realpath-Prüfung), prüft bei jedem Abruf serverseitig `require_platform('admin.view')` (Plattformrolle mit 2FA) und zusätzlich je Datei den Zugriff nach `access`: `technical` und `admin` für Plattformadministratoren (`technical` zusätzlich für Adressen in `docs.technical_readers` mit Plattformadminrolle), `customer` für Plattformadministratoren; Suchindizes und Diagramme unterliegen derselben Prüfung. Historische Fassungen aus dem Archiv werden über `?archiv=<id>` mit denselben Regeln ausgeliefert. Jeder Abruf wird im Audit protokolliert (`admin_doc_download`).
- `php-ionos/handbuch.php` liefert angemeldeten Benutzern der Kundenanwendung nur das Benutzerhandbuch (`access = customer`): HTML-Ansicht und PDF. Keine anderen Dateien.
- Verkaufsfassungen für Externe entstehen durch Weitergabe der PDF `unternehmen.pdf` nach Freigabe der Geschäftsführung; vertrauliche Inhalte stehen dort nicht, weil das Kapitel `docs/unternehmen/` keine Zugangsdaten, Serveradressen oder Sicherheitsbefunde enthält (Prüfung durch `tools/docs-build-check.py`, Muster für Geheimnisse).

## Revisionen und Historie

Softwareversion (`APP_VERSION`) und Dokumentrevision sind getrennt. `docs/dokumentationsregeln/revisionen.json` führt je Dokument `revision`, `date`, `summary`. Regel: Jede inhaltliche Änderung an einem Kapitel eines Dokuments erhöht dessen Revision im selben Änderungssatz; eine reine redaktionelle Korrektur erhöht die Revision, ohne eine Softwareversion vorzutäuschen. Der Adminbereich zeigt je Dokument Softwarestand, Commit, Revision, Datum und Erzeugungszeit; das Archiv auf dem Server hält frühere Fassungen mit ihrem Manifest. Veröffentlichte Fassungen werden nicht überschrieben, sondern durch ein neues Release mit neuer Revision ergänzt.

## Pflichten bei jedem Entwicklungsauftrag

Verankert in `CLAUDE.md`: Nach jeder Änderung prüfen, welche Kapitel, Diagramme, Datenwörterbuch, Handbuchabschnitte und Versionshinweise betroffen sind, und sie im selben Änderungssatz nachziehen. Ergibt die Prüfung keine Auswirkung, wird das kurz in `docs/ARBEITSSTAND.md` vermerkt. Strukturprüfungen ersetzen keine fachliche Inhaltsprüfung.

## Wiederherstellung des Dokumentationssystems

Alle Quellen liegen im Repository. Erzeugung benötigt Python 3, reportlab, für Diagramme Playwright mit Chromium und npm (mermaid). Ohne Browser bleiben die eingecheckten SVG/PNG gültig. Das Betriebshandbuch steht im Notfall außerhalb der Anwendung als PDF im Repository-Build (GitHub-Artefakt des Workflows, 30 Tage) und im Serverarchiv unter `shared/docs-archive/`; eine zusätzliche Ablage außerhalb des Servers (Sicherungskopie der PDF) ist Aufgabe des Betreibers und in der Übergabecheckliste vermerkt.
