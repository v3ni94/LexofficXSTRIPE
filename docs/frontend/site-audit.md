# Frontend-Audit: Seiteninventar, Befunde und Maßnahmen

Stand 13.09.2026, Branch `claude/frontend-smart-einzug-egsouk`, Basis APP_VERSION 4.75.
Auftrag: Masterprompt Frontend SmartEinzug vom 13.09.2026 (Grounding Page, GEO und SEO, vollständiger
Seitencheck, Onboarding, Conversion).

## 1. Was geprüft wurde und womit

Grundlage sind das Repository, die Sitemaps, die interne Verlinkung und der Programmcode der Anwendung.
Erhoben wurde mit den vorhandenen Werkzeugen des Projekts, nicht mit neu eingeführten Diensten:

| Werkzeug | Zweck |
|---|---|
| `tools/seo-inventory.py` | Seiteninventar je URL mit Titel, Description, Canonical, Robots, JSON-LD, Bildern, Wortzahl |
| `tools/site-qa.py` | Redaktionelle und technische Regeln je Seite, Dublettengrenze zwischen den Domains |
| `tools/seo-linkcheck.py` | Defekte interne Links, verwaiste Seiten, schwach angebundene Seiten |
| `tools/seo-map-check.py` | Eine Suchintention, eine bevorzugte Zielseite über alle Domains |
| `tools/site-tag-check.py` | Google-Tag, Einwilligungsvoreinstellung, Rückkanal in der Sicherheitsrichtlinie |
| `tools/app-tracking-check.php` | Messung in der Anwendung, feste Seitenliste, keine Messung im Firmenaccount |
| `tools/pricing-check.php` | Keine Preisbeträge auf den statischen Seiten |

Nicht verfügbar und deshalb nicht behauptet: Search-Console- und Analytics-Daten, Felddaten zu den
Web Vitals, ein Abruf der Live-Seiten aus dieser Arbeitsumgebung (der Ausgangsproxy verweigert fremde
Domains mit HTTP 403), physische Endgeräte für Browsertests.

## 2. Bestand

63 Seiten auf fünf Domains, davon 53 indexierbar. Dazu 58 PHP-Oberflächen der Anwendung.

| Domain | Seiten | Rolle |
|---|---|---|
| smart-einzug.de | 34 | Hauptdomain, Produkt und Wissen |
| lexware-einzug.de | 14 | Leadseite (DETM Management Consulting FZCO) |
| lexoffice-einzug.de | 7 | Leadseite (DETM Management Consulting FZCO) |
| sevdesk-einzug.de | 4 | Leadseite, Vormerkung |
| sevdesk-sepa.de | 4 | Leadseite, SEPA-Wissen |

Die Zeilendaten je URL mit allen geforderten Feldern stehen in `docs/seo/SEO_PAGE_MATRIX.csv`
(Trennzeichen Semikolon, 63 Datenzeilen). Sie wird hier nicht doppelt geführt.

### Technischer Grundzustand

Vier Prüfungen, bei denen erfahrungsgemäß die meisten Befunde entstehen, sind im Bestand bereits sauber:

| Prüfung | Ergebnis |
|---|---|
| Indexierbare Seiten ohne Meta-Description | 0 |
| Indexierbare Seiten ohne selbstreferenziellen Canonical | 0 |
| Indexierbare Seiten, die in keiner Sitemap stehen | 0 |
| Bilder ohne Alternativtext | 0 |

Das ist das Ergebnis der vorangegangenen Arbeitspakete und wird hier nur bestätigt, nicht neu erarbeitet.

## 3. Befunde dieses Durchgangs

### P0: Zahlungszustände und Geheimnisse

Geprüft wurde, ob die Oberfläche eine Lastschrift als abgeschlossen darstellt, bevor der Server das
bestätigt, und ob Zugangsdaten in den Browser gelangen.

**Kein Befund.** Die Statusdarstellung unterscheidet sauber zwischen „Wird eingereicht“, „In Bearbeitung“,
„Erfolgreich“, „Fehlgeschlagen“, „Rücklastschrift“ und „Erstattet“ (`app/layout.php`, `status_badge()`).
Ein eingereichter Einzug wird nie als bezahlt bezeichnet. Der Storno sagt ausdrücklich, dass nichts bei
Stripe eingereicht wurde. Ein unbekanntes Ergebnis führt sichtbar in die Klärung statt in eine stille
Wiederholung.

Zugangsdaten: Alle Felder für Schlüssel und Passwörter sind `type="password"` mit `autocomplete="off"`
(`settings.php`). Kein Zugangsdatum wird in `localStorage` oder `sessionStorage` abgelegt; die einzigen
Browser-Speicher-Zugriffe betreffen die Einwilligung. Ein gespeicherter Schlüssel wird nie zurückgegeben,
die Einstellungen zeigen nur noch an, dass ein Schlüssel hinterlegt ist.

Die Marketingseiten enthalten keine Sofortzahlungs- oder Erfolgsversprechen und keine Garantien. Sie sagen
an mehreren Stellen ausdrücklich das Gegenteil („Warum ist eine Lastschrift nicht sofort bezahlt?“).

### P1-1: Veraltete Aussage zur Erkennung von Rücklastschriften (behoben)

`websites/smart-einzug.de/wissen/ruecklastschrift/index.html` behauptete: „Rücklastschriften und
Erstattungen erkennt ausschließlich der Webhook.“ Das war bis 4.72 richtig. Seit 4.73 prüft der
Statusabgleich zusätzlich abgeschlossene Einzüge der zurückliegenden Wochen auf Rücklastschrift und
Erstattung (`app/collections.php`, `collection_apply_dispute()`).

Die Aussage war damit nicht nur veraltet, sondern in der Wirkung schädlich: Sie legte nahe, ohne
eingerichteten Webhook bleibe eine Rücklastschrift dauerhaft unentdeckt. Korrigiert auf eine Beschreibung
beider Wege mit dem Hinweis, dass der Abgleich ein reiner Lesezugriff ist.

**Folgebefund:** `docs/seo/02-faktenregister.md` trägt unter RUECK-01 dieselbe überholte Aussage. Das
Register steht auf dem Stand vom 07.09.2026. Es ist die zentrale Faktenquelle des Projekts und sollte im
nächsten Durchgang nachgezogen werden, damit eine geprüfte Quelle nicht zur Fehlerquelle wird.

### P1-2: Grounding Page angelegt

Neu unter `https://smart-einzug.de/fakten/`. Inhalte und Herleitung stehen in
`docs/frontend/grounding-seo.md`.

### Geprüft und nicht zu beanstanden

- **Stripe-Anleitung gegen Implementierung.** Die Anleitung beschreibt die Verbindung über Secret Key oder
  eingeschränkten Schlüssel. Das entspricht `settings.php`. Ein Connect- oder OAuth-Verfahren wird nirgends
  beworben, es existiert auch nicht.
- **Anzahl der Webhook-Ereignisse.** Die Seiten nennen sieben Ereignisse. Der Kunden-Webhook verarbeitet
  genau diese sieben, einschließlich `checkout.session.completed` für die digitale Mandatserteilung. Der
  erste Augenschein legte sechs nahe; die Gegenprüfung am Code hat das widerlegt.
- **Trennung der beiden Stripe-Konten.** Die Seiten vermischen an keiner Stelle das Stripe-Konto der Firma
  (Einzüge) mit dem Stripe-Konto des Anbieters (Abonnement).

## 4. Nicht geprüft, mit Begründung

| Bereich | Grund |
|---|---|
| Angemeldete Oberflächen im Browser | Kein Zugang zu einer laufenden Instanz aus dieser Umgebung. Geprüft wurde der Quelltext der Seiten, nicht ihr Verhalten im Betrieb. |
| Web Vitals als Felddaten | Keine Felddaten verfügbar. Laborwerte ohne Feldbezug wären kein Nachweis und werden nicht ausgewiesen. |
| Browser- und Gerätetests | Keine realen Endgeräte verfügbar. Eine Emulation wird nicht als Gerätetest ausgegeben. |
| Live-Statuscodes, Redirects, robots.txt am Server | Abruf fremder Domains aus dieser Umgebung nicht möglich (HTTP 403 am Ausgangsproxy). Geprüft wurden die Regeln in den `.htaccess`-Dateien, nicht ihre Wirkung im Betrieb. |

## 5. Offene Punkte für den Betreiber

1. `docs/seo/02-faktenregister.md` auf den Stand 4.75 nachziehen, mindestens RUECK-01.
2. Entscheidung, ob die Faktenseite Preisbeträge nennen darf. Derzeit nicht, wegen der Vorgabe vom
   07.09.2026; die Seite verweist stattdessen auf den Bestellvorgang.
3. Die beiden Kampagnenseiten unter `lp/` führen bewusst einen reduzierten Footer und haben deshalb keinen
   Link auf die Faktenseite erhalten. Falls dort ein Link gewünscht ist, bitte melden.
