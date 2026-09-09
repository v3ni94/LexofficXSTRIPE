# SEO-Projektkontext

Stand: 10.09.2026. Erhoben aus dem Repository (kein Live-Abruf: der Netzzugang der Arbeitsumgebung erreicht die Domains nicht). Alle Angaben sind aus Dateien belegt, nichts ist geschätzt.

## 1. Technischer Rahmen

| Merkmal | Befund | Beleg |
|---|---|---|
| Framework | keines. Kein `package.json`, kein `composer.json`, kein Build-Schritt für die Marketingseiten | Wurzelverzeichnis |
| Rendering | statisches HTML, vollständig im Auslieferungszustand vorhanden | `websites/<domain>/**/*.html` |
| Routing | Dateisystem. Apache entfernt die `.html`-Endung per 301 und löst extensionslose Adressen intern auf | `.htaccess` je Domain |
| Metadaten | je Datei im `<head>`, synchronisiert über `tools/sync-chrome.py` | Kopf- und Fußzeilenabgleich |
| Sitemap | statisch je Domain, erzeugt von `tools/build-sitemaps.py`, `lastmod` aus der Git-Historie | `websites/*/sitemap.xml` |
| robots.txt | je Domain, `Allow: /` plus Sitemap-Verweis | `websites/*/robots.txt` |
| Hosting | IONOS Webhosting, Upload per SFTP im GitHub-Job `deploy-webhosting` | `.github/workflows/deploy.yml` |
| Serverzugriff | mittelbar vorhanden: `.htaccess` liegt im Repository und wird mit ausgeliefert, echte 301 sind also möglich | Upload-Zuordnung im Workflow |
| CI/CD | GitHub Actions, Jobs `changes`, `test`, `deploy-webhosting`, `deploy-vps`; `site-qa.py` ist Pflichtprüfung | `.github/workflows/deploy.yml` |
| Analytics | GA4 je Domain und Google Ads (AW-18431688840) auf lexware-einzug.de und smart-einzug.de, Laden erst nach Einwilligung | `assets/js/site.js` |
| Sprache | ausschließlich `de`, 69 von 69 HTML-Dateien mit `<html lang="de">` | Auszählung |
| hreflang | nicht vorhanden und nicht erforderlich, es gibt keine zweite Sprach- oder Regionalfassung | Auszählung: 0 Treffer |

## 2. Domains und ihre Rolle

| Domain | Rolle | Indexierbare Seiten | Betreiber der Seite |
|---|---|---|---|
| smart-einzug.de | Hauptdomain, Produkt, Anleitungen, Wissen, Sicherheit | 29 | Müller Holding AG |
| lexware-einzug.de | Leadseite Lexware Office | 12 | DETM Management Consulting FZCO |
| lexoffice-einzug.de | Leadseite lexoffice-Umbenennung | 5 | DETM Management Consulting FZCO |
| sevdesk-einzug.de | Leadseite sevdesk, Vormerkung | 3 | DETM Management Consulting FZCO |
| sevdesk-sepa.de | Leadseite sevdesk, SEPA-Wissen | 3 | DETM Management Consulting FZCO |
| status.smart-einzug.de | Statusseite, bewusst `noindex, follow` | 0 | Müller Holding AG |
| lastschrift-einfach.de | reine 301-Weiterleitung auf smart-abrechnen.de | 0 | Müller Holding AG |
| Alias-Domains (5) | 301 auf smart-einzug.de, kein eigener Inhalt | 0 | Müller Holding AG |

## 3. Was bereits erledigt ist

Die Search Console ist eingerichtet, Sitemaps sind eingereicht, GA4 läuft. Aus früheren Arbeitspaketen dieses Repositorys liegen außerdem vor: Faktenregister, Aussagenprüfung, Themen- und URL-Zuordnung (`keyword-map.json`), Maßnahmenplan und Abschlussbericht in `docs/seo/`. Dieses Audit setzt darauf auf und wiederholt es nicht.

## 4. Nicht ermittelbar

- Live-HTTP-Status, tatsächliche Auslieferungs-Header und Antwortzeiten. Der Proxy der Arbeitsumgebung blockiert die Domains.
- Search-Console-, Analytics- und Ads-Kennzahlen. Keine Exporte im Repository.
- Lighthouse-Messwerte. Kein Node und kein Browser-Messlauf gegen die Live-Domains möglich; Befehle stehen in `SEO_QA_COMMANDS.md`.
