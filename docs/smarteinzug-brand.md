# Markensteckbrief SmartEinzug (Paket E), Stand 05.09.2026

Hinweis zur Grundlage: Der Auftrag nennt als Referenz `/mnt/user-data/outputs/logo-paket.zip`. In diesem Ordner liegt zusätzlich `/mnt/user-data/outputs/logo-paket-smarteinzug.zip` mit der aktuellen Ordnerstruktur für die Marke SmartEinzug (Unterordner `svg`, `pdf`, `png`, `jpg`, `favicon`, `social`). Die folgende Beschreibung bezieht sich auf diese aktuelle SmartEinzug-Struktur, da `logo-paket.zip` noch die älteren Bezeichnungen (Lexware-Einzug, SEPA-Einzug) enthält. Bei Unklarheit zur maßgeblichen Datei bitte Rücksprache halten.

## 1. Marke

Produktname: **SmartEinzug**. Bildmarke: stilisiertes Euro-Zeichen (€) auf einer Anthrazit-Kachel. Wortmarke in Varianten mit und ohne Zusatzzeile „Für Lexware Office".

## 2. Farben

| Farbe | Hex |
|---|---|
| Gold | #E3AC48 |
| Anthrazit | #2E2D2E |
| Beige | #FBF6EC |
| Grau | #9F9F9F |

Diese vier Farben bilden die Kernpalette. Gold und Anthrazit sind die primären Markenfarben (siehe Kombinationen „gold_anthrazit" und „anthrazit_gold" im Logo-Paket), Beige dient als heller Hintergrund, Grau als neutraler Zwischenton für Text und sekundäre Elemente.

## 3. Schriftstapel

Fließtext und Wortmarke setzen auf den Schriftstapel: **Carlito, Calibri, Segoe UI, Arial** (in dieser Reihenfolge als Fallback-Kette). Kein Laden externer Webfonts erforderlich, da alle vier Schriften auf gängigen Systemen verfügbar sind beziehungsweise Carlito als metrikkompatibler Ersatz für Calibri dient.

## 4. Varianten im Logo-Paket

Struktur laut `logopaket_se/` (siehe `logo-paket-smarteinzug.zip`):

| Ordner | Inhalt |
|---|---|
| `svg/` | Bildmarken (`bildmarke_beige_gold`, `bildmarke_anthrazit_gold`, `bildmarke_gold_anthrazit`, `bildmarke_schwarz_1c`, `bildmarke_weiss_1c`, transparente Euro-Varianten in Gold, Weiß und Anthrazit) sowie Wortmarken (`wortmarke_dunkel`, `wortmarke_hell`, `wortmarke_beige`, `wortmarke_1c_schwarz`, `wortmarke_1c_weiss`, gestapelte Varianten hell/dunkel, Varianten mit Zusatzzeile „Für Lexware Office" beziehungsweise „Für lexoffice", Varianten mit Web-Adresse) |
| `pdf/` | Druckfähige Vektorvarianten der Logo-Dateien |
| `png/` | Rasterexporte der Bild- und Wortmarken in mehreren Auflösungen |
| `jpg/` | Rasterexporte mit weißem beziehungsweise farbigem Hintergrund für Anwendungen ohne Transparenzunterstützung |
| `favicon/` | `favicon.svg`, `favicon.ico`, sowie PNG-Größen 16, 48, 180, 192 px |
| `social/` | Open-Graph- beziehungsweise Profilgrafiken je Domain (`og-smart-einzug`, `og-lexware-einzug`, `og-lexoffice-einzug`) |

## 5. Schutzraum

Um die Bildmarke (Euro-Kachel) und um die Wortmarke ist mindestens ein Schutzraum in Höhe der Kachelbreite beziehungsweise der Zeilenhöhe des Wortmarken-Schriftzugs freizuhalten. Innerhalb dieses Schutzraums dürfen keine weiteren grafischen Elemente, Texte oder Bedienelemente platziert werden. Bei beengtem Platz (zum Beispiel in der Kopfzeile mobiler Ansichten) ist die kompakte Bildmarke ohne Zusatzzeile zu verwenden, nicht eine verkleinerte Wortmarke mit Zusatzzeile.

## 6. Mindestgrößen

- **Favicon**: mindestens 16 px (Browser-Tab), darüber hinaus 32, 48, 180 und 192 px für unterschiedliche Endgeräte gemäß den vorliegenden Favicon-Dateien.
- **Header (Website/App)**: mindestens 24 px Höhe der Bildmarke, damit das Euro-Symbol auf der Anthrazit-Kachel erkennbar bleibt.
- Unterhalb dieser Größen ist ausschließlich die reine Bildmarke (Kachel mit Euro-Zeichen) einzusetzen, keine Wortmarke und keine Variante mit Zusatzzeile.

## 7. Alternativtexte

Für Bildmarke und Wortmarke ist im `alt`-Attribut jeweils der volle Produktname zu hinterlegen, zum Beispiel `alt="SmartEinzug"` beziehungsweise, wo die Zusatzzeile transportiert werden soll, `alt="SmartEinzug – Für Lexware Office"` als Textinhalt des Alternativtexts (kein Gedankenstrich im sichtbaren Fließtext der Website, im technischen `alt`-Attribut als reiner Metadatentext unkritisch). Rein dekorative Einbindungen (zum Beispiel eine zusätzliche Bildmarke neben einer bereits textlich vorhandenen Überschrift) erhalten `alt=""` und `aria-hidden="true"`, wie in den bestehenden Seiten (`<span class="mark" aria-hidden="true">€</span>`) bereits umgesetzt.

## 8. Hinweis zur Markenrechtsfreigabe

**Dieses Dokument stellt keine Markenrechtsfreigabe dar.** Es beschreibt ausschließlich die gestalterische Verwendung der vorhandenen Grafikdateien (Farben, Formate, Mindestgrößen, Schutzraum). Ob der Name „SmartEinzug" als Marke eingetragen, eintragbar oder mit Rechten Dritter kollisionsfrei ist, wurde nicht geprüft. Eine markenrechtliche Prüfung und gegebenenfalls Anmeldung ist gesondert durch einen Rechtsanwalt vorzunehmen, bevor die Marke im großen Umfang beworben wird. Ebenso ist der bestehende Markenhinweis zu Lexware, Lexware Office, lexoffice und Stripe (siehe Impressum) bei jeder Verwendung dieser Fremdbezeichnungen zu beachten, siehe `websites/lexware-einzug.de/impressum.html`, Abschnitt „Hinweis zu Marken".

## Offene Punkte

- Referenzdatei laut Auftrag (`logo-paket.zip`) und tatsächlich aktuelle SmartEinzug-Struktur (`logo-paket-smarteinzug.zip`) weichen im Namen voneinander ab, bitte klarstellen, welche Datei künftig maßgeblich ist.
- Markenrechtliche Prüfung des Namens SmartEinzug steht aus.
