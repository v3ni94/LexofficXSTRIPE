# Abschlussbericht SEO-, Content- und Landingpage-Ausbau

Stand: 08.09.2026, Branch `claude/frontend-smart-einzug-egsouk`. Dieser Bericht fasst die Ergebnisse des Masterprompts vom 07.09.2026 zusammen und unterscheidet je Punkt zwischen **empfohlen** (Vorschlag, Entscheidung offen), **umgesetzt** (im Branch vorhanden), **getestet** (mit den Prüfwerkzeugen des Repositorys geprüft) und **produktiv veröffentlicht** (auf den Live-Domains sichtbar). Kein Punkt dieses Berichts ist produktiv veröffentlicht: Der Frontend-Branch löst kein Deployment aus; live geht der Stand erst nach Merge in den Backend-Branch und dem dadurch ausgelösten Webhosting-Upload. Indexierung und Rankingwirkung sind nicht nachgewiesen und werden nicht behauptet.

## 1. Bestands- und Maßnahmenübersicht

| Gegenstand | Was bleibt | Was wurde verbessert | Was wurde neu erstellt | Was wurde nicht umgesetzt | Status |
|---|---|---|---|---|---|
| smart-einzug.de (Hauptdomain) | Seitenstruktur, URLs, Design, Kampagnenseiten (noindex), Vergleichsseite (noindex, Entwurf) | 12 Seiten bereinigt (Preise entfernt, 33 Befunde, Leserbefunde), Integrationsseiten ausgebaut, Fußzeile mit Anleitungen, Wissen, Sicherheit | Anleitungen (4 Seiten), Wissen (4 Seiten), Sicherheit (1 Seite) | Verlagerung der Leaddomain-Inhalte per 301 (M4), Screenshots der echten Anwendung (Medienbriefing offen) | umgesetzt, getestet |
| lexoffice-einzug.de (Leadseite) | Alle URLs, Ratgeber, Design | 13 Seiten bereinigt (Preise, 31 Befunde, 6 übersehene Punkte, Ablauf gegliedert), DETM als Anbieter, Textwortmarke | logofreie Bildassets | Zusammenführung der Spiegelseiten (M4), Impressumsangaben DETM | umgesetzt, getestet |
| lexware-einzug.de (Leadseite) | Alle URLs, Anleitungen, Ratgeber, Design | 14 Seiten bereinigt (Preise, 35 Befunde, Einzugsautomatik-Abschnitt auf echte Einstellungen umgeschrieben, H1 ohne Zeitversprechen), DETM als Anbieter, verwaiste Seite verlinkt | logofreie Bildassets | wie vor | umgesetzt, getestet |
| sevdesk-einzug.de, sevdesk-sepa.de | | | zwei Leaddomains mit getrennten Inhalten (Vormerkung, SEPA-Wissen), Impressum, Datenschutz, 404, Assets | Domainregistrierung, IONOS-Zuordnung, `signup_domains` in Produktion (Backend) | umgesetzt, getestet |
| lastschrift-einfach.de | Ordner unverändert | | | Bereinigung von Ordner und Upload (Empfehlung an das Backend, Weiterleitung auf smart-abrechnen.de) | empfohlen |
| Werkzeuge | | `pricing-check.php` Abschnitt D (keine Produktpreise), `build-sitemaps.py` (lastmod aus Git), `sync-chrome.py` Domainliste | `seo-inventory.py`, `seo-map-check.py` (mit CSV-Export), `lead-assets.py` | | umgesetzt, getestet |
| Dokumentation | | `CLAUDE.md` (Preisregel, Leadseiten, Keyword-Map), `ARBEITSSTAND.md` Abschnitt 7, Admin-Dokumentation um `docs/seo/` erweitert | `docs/seo/` (README, 01 bis 07, Inventar, Keyword-Map) | | umgesetzt, getestet |

## 2. Gemeinsame Themen- und URL-Zuordnung

Umgesetzt in `keyword-map.json` und `04-keyword-map.md`: 28 Suchintentionen mit Zielgruppe, Hauptbegriff, Varianten, bevorzugter Zielseite, Rolle jeder Seite, Indexierungsentscheidung, Konfliktart, Empfehlung, Freigabevorbehalt, Faktenquellen und Prüftermin. `python3 tools/seo-map-check.py` prüft, dass jede indexierbare Seite genau einmal zugeordnet ist, jedes Cluster genau eine Primärseite hat, Indexierung und Sitemap zum Dateibestand passen und Kampagnenseiten noindex sind. Status: umgesetzt, getestet (0 Fehler).

## 3. Umgesetzte Seiten und Änderungen

- Faktenregister: 191 belegte Einträge aus dem Code (`02-faktenregister.md`), davon 166 bestätigt, 9 geplant, 7 nicht vorhanden, 9 ungeklärt. Umgesetzt.
- Aussagenprüfung: 241 Befunde, 58 durch adversariale Gegenprüfung verworfen, 121 bestätigt und umgesetzt, 62 ohne Gegenprüfung (Preise über die Preisregel erledigt, ungeklärte Aussagen unverändert). Umgesetzt, getestet.
- Preisdarstellung: alle Produktpreise von den Marketingseiten entfernt, JSON-LD ohne `Offer`; AGB unverändert (Vertragstext). Umgesetzt, getestet (`pricing-check.php` 14 von 14).
- Noch offene Fakten (nicht behauptet, siehe `02-faktenregister.md`, Abschnitt Offene Fragen): Lexware-Tarifvoraussetzung, Produktionswerte von `features.queue`, `plans`, `mail.enabled`, Standort von Anwendung und Sicherungen, AVV-Veröffentlichung, Stripe-Gläubiger-ID auf dem Kontoauszug.

## 4. Prüf- und Messkonzept

- Technische Tests je Commit: `site-qa.py` (0 Fehler, 4 bekannte Warnungen), `pricing-check.php`, `seo-map-check.py`, `asset-version.py`, `build-sitemaps.py`, `seo-inventory.py`, `docs-build-check.py`. Umgesetzt, getestet.
- Conversion-Ereignisse: vorhandene Trichter-Ereignisse der Anwendung je Herkunftsdomain (page_view, cta_click, registration_started, registration_completed, 2fa_enabled, lexware_connected, stripe_connected, first_sync, first_collection, subscription_active). Dokumentiert in `06-mess-und-pflegekonzept.md`. Empfohlen: Ausgangsseite als Parameter mitführen, Entscheidung zur domainübergreifenden GA4-Messung (Backend).
- Datenlücken: keine Search-Console-, Analytics- oder Ads-Daten im Repository; Live-Abruf der Domains aus der Arbeitsumgebung nicht möglich. Alle Indexierungsaussagen sind „technisch indexierbar“, nicht „indexiert“.

## 5. Pflege- und Redaktionsplan

`06-mess-und-pflegekonzept.md`, Abschnitte 4 und 5: Zustände je Seite, Prüfanlässe (Versionswechsel, Tarifänderung, sevdesk-Freigabe, Lexware- oder Stripe-Änderungen, Rechtsdokumente), Verantwortlichkeiten, Prüftermin 08.12.2026 in der Keyword-Map. Social Media: nur aus veröffentlichten, faktengeprüften Seiten, keine Preise bis zur Freigabe. Umgesetzt als Plan; Kanäle und verantwortliche Person offen.

## 6. Entscheidungen der Geschäftsführung, die vor dem Merge nötig sind

1. DETM Management Consulting FZCO: Anschrift, Registerangaben, vertretungsberechtigte Person, E-Mail, Telefon für Impressum und Datenschutz der vier Leaddomains. Ohne diese Angaben darf der Stand nicht live gehen.
2. AGB-Preisangaben (25,00 EUR, 50,00 EUR) auf drei Domains nach anwaltlicher Prüfung anpassen oder belassen; Zulässigkeit eines Streichpreises prüfen, falls Preise wieder veröffentlicht werden.
3. Freigabe der künftigen Preisdarstellung (Betrag, Aktionslogik) und danach Anpassung von `pricing-check.php` Abschnitt D.
4. M4 Gruppe A und B: Zusammenführung der Spiegelseiten und Weiterleitung der Leaddomain-Inhalte auf die neuen Hauptdomain-Seiten, jeweils erst nach 16 Wochen Search-Console-Daten.
5. Backend: sevdesk-Domains in `signup_domains`, lastschrift-einfach.de bereinigen, `APP_VERSION` und Changelog beim Merge um die Website-Änderungen ergänzen (in diesem Branch bewusst nicht angefasst, um Konflikte mit den Backend-Arbeiten zu vermeiden).
6. Medien: Screenshots der echten Anwendung mit gekennzeichneten Demodaten für Anleitungen und Startseiten (Medienbriefing in `05-massnahmenplan.md` vorzusehen); bis dahin bleiben die HTML-Mockups mit sichtbarer Kennzeichnung „Beispielansicht mit Beispieldaten“.
