# Mess- und Pflegekonzept für die Marketingseiten

Stand: 07.09.2026. Grundlage: Masterprompt SEO, Abschnitte 17 und 18. Alle Angaben zum Ist-Zustand stammen aus dem Repository (`websites/*/assets/js/site.js`, `php-ionos/track.php`, `php-ionos/register.php`, `php-ionos/subscription.php`). Angaben zu Google-Konten sind nicht prüfbar und als Lücke gekennzeichnet.

## 1. Erfolgskette

Passender Besuch → Klick auf eine Handlungsaufforderung → Registrierung → E-Mail bestätigt → Lexware Office verbunden → Stripe verbunden → erster erfolgreicher Einzug → Abonnement abgeschlossen → Kunde bleibt.

| Schritt | Ereignis | Erfasst heute durch | Lücke |
|---|---|---|---|
| Besuch | `page_view` je Domain und Pfad | `site.js` an `track.php` (cookielos, ohne IP) und GA4 nach Einwilligung (drei getrennte Properties) | Kein Bezug zwischen Besuch und späterer Registrierung |
| CTA-Klick | `cta_click` mit Position (`data-cta`) und Pfad | `track.php` und GA4-Ereignis | Ein Klick ist noch keine Registrierung |
| Übergang zur Anwendung | Link auf `app.smart-einzug.de/register.php?src=<domain>` plus weitergereichte UTM-Parameter | `site.js` hängt UTM an App-Links; die Anwendung liest `src`, UTM und Referrer in `signup_attribution_capture()` (`app/auth.php`) und speichert die Herkunftsdomain der Firma (`organizations.signup_domain`) | GA4 zählt den Wechsel als neue Sitzung (kein Cross-Domain-Linker, unterschiedliche Properties); die eigene Zählung deckt den Übergang ab |
| Registrierung, Bestätigung, Verbindungen, Einzug, Abonnement | `funnel_events` je Herkunftsdomain: registration_started, registration_completed, 2fa_enabled, lexware_connected, stripe_connected, onboarding_completed, first_sync, first_collection, subscription_active (`app/audit.php`, Anzeige in `admin.php`) | Adminbereich der Anwendung, Trichter je Domain | Keine Zuordnung zu Zielseite oder Themencluster (nur Domain), keine Verknüpfung mit GA4 |

## 2. Empfehlungen (Backend-Abstimmung nötig, nicht Teil dieses Frontend-Pakets)

1. Herkunft verfeinern: Die Anwendung speichert bereits Herkunftsdomain, UTM-Parameter und Referrer je Registrierung und zählt den Trichter je Domain. Für die Auswertung je Zielseite und Themencluster fehlt der Pfad der Ausgangsseite; `site.js` könnte ihn als `utm_content` oder eigenen Parameter an den App-Link hängen (kein Personenbezug, keine IP). Entscheidung mit dem Backend.
2. Eine gemeinsame GA4-Property für alle Marketingdomains und die Anwendung mit Cross-Domain-Linker (`linker: {domains: [...]}`), oder bewusst bei getrennten Properties bleiben und die Erfolgskette ausschließlich über die eigene, cookielose Zählung plus die Herkunft aus Punkt 1 messen. Die zweite Variante ist datenschutzfreundlicher und für die Fragestellung ausreichend, solange keine Kampagnenoptimierung in Google Ads auf Conversion-Basis nötig ist.
3. Conversion-Ereignis für Google Ads (nur lexware-einzug.de) auf der Bestätigungsseite der Registrierung setzen, nicht auf dem Klick; Einwilligung bleibt Voraussetzung.
4. Keine IBANs, Rechnungsinhalte, API-Schlüssel oder Daten der zahlenden Endkunden an Marketingwerkzeuge übergeben. Serverseitige Messung ändert daran nichts.

## 3. Messgrenzen

- Ohne Search Console keine Aussagen zu Indexierung, Rankings und Klicks. Empfehlung: Search Console für alle vier Domains einrichten (Domain-Property), Sitemaps einreichen, Berichte „Seitenindexierung“ und „Leistung“ monatlich exportieren und im Adminbereich ablegen.
- Geringe Besucherzahlen: keine Prozentwerte mit Scheingenauigkeit, Vergleich nur über mindestens vier Wochen.
- Mehrfachzählung: cookielose Zählung zählt Seitenaufrufe, nicht Personen; GA4 zählt nach Einwilligung nur einen Teil der Besucher. Beide Zahlen nie addieren.

## 4. Redaktions- und Pflegeplan

Zustände jeder Seite: `Entwurf → Faktenprüfung → redaktionelle Prüfung → technische Prüfung → freigegeben → veröffentlicht → überprüfungsbedürftig`. Der Zustand wird je Cluster in `docs/seo/keyword-map.json` geführt (Felder `status`, `prueftermin`, `faktenquellen`).

Prüfanlässe, die eine Seite auf „überprüfungsbedürftig“ setzen:

| Anlass | Betroffene Inhalte | Verantwortlich |
|---|---|---|
| Neue Version der Anwendung mit Änderung an Synchronisation, Einzug, Mandat, Status oder Sicherheit (`APP_VERSION`, `app_changelog()`) | Funktions-, Ablauf- und Anleitungsseiten, Faktenregister | Frontend-Pflege nach Hinweis des Backends |
| Änderung der Tarife in der Tabelle `plans` oder Freigabe der Preisdarstellung | Preisseiten, FAQ, JSON-LD, `tools/pricing-check.php` Abschnitt D | Geschäftsführung gibt frei, Frontend setzt um |
| sevdesk-Freigabe (`platform_settings`, `sevdesk_*`) | Integrationsseiten, Startseiten-Teaser, Vormerkformular | Backend meldet, Frontend setzt um |
| Änderung der Lexware-Office-Oberfläche oder Tarifvoraussetzungen | Anleitungen, Voraussetzungen, Screenshots | vierteljährliche Sichtprüfung |
| Änderung der Stripe-Dokumentation zu SEPA (Core, Fristen, Rückmeldung) | Wissens- und Ratgeberseiten | vierteljährliche Sichtprüfung |
| Änderung der Rechtsdokumente (AGB, Datenschutz, AVV) | Rechtsseiten aller Domains | Geschäftsführung, anwaltliche Prüfung |

Automatische Prüfungen vor jedem Commit: `python3 tools/site-qa.py`, `php tools/pricing-check.php`, `python3 tools/seo-map-check.py`, `python3 tools/asset-version.py --check`, danach `python3 tools/build-sitemaps.py`. Automatisch erkannte Probleme führen nie zu Massenänderungen an Weiterleitungen oder Indexierungsregeln; jede solche Änderung steht vorher im Maßnahmenplan.

## 5. Social Media und externe Erwähnungen

Kein Kanal ist im Repository dokumentiert. Bis eine verantwortliche Person und Kanäle benannt sind, gilt: Beiträge entstehen nur aus veröffentlichten, faktengeprüften Seiten (Anleitung, Wissensartikel, konkrete Antwort auf eine Kundenfrage), verweisen auf die passende Detailseite und nennen keine Preise, solange die Preisdarstellung nicht freigegeben ist. Keine automatisierten Foren- oder Kommentarbeiträge, keine gekauften Links, keine erfundenen Bewertungen.
