# Abnahmevorlage SmartEinzug (Paket E), Stand 05.09.2026

Hinweis zur Grundlage: Der Auftrag verweist für die Abnahmefälle auf „Abschnitt 34". Dieser Auftragstext lag mir bei der Erstellung dieser Vorlage nicht als Datei im Repository vor. Die folgende Tabelle leitet die Abnahmefälle stattdessen aus `docs/bestandsmatrix.md` (Spalten „Änderung" und „Abnahme") ab und ist gegen den tatsächlichen Auftragstext „Abschnitt 34" zu prüfen und bei Abweichungen zu ergänzen.

Statuswerte: **offen**, **implementiert**, **lokal getestet**, **extern getestet**, **produktiv verifiziert**. Status ist standardmäßig „offen", außer die Bestandsmatrix nennt den Zustand „vorhanden" (dann „implementiert", da laut Bestandsmatrix im Code bereits umgesetzt, aber ohne dokumentierten Testnachweis in diesem Repository).

| Bereich | Fall | Status | Datum | Hinweis |
|---|---|---|---|---|
| Plattform/Hosts | App unter getrennten Basis-URLs (`public_base_url`, `app_base_url`, `admin_base_url`, `allowed_hosts`) | offen | | Änderung laut Bestandsmatrix Paket C, noch nicht umgesetzt |
| Plattform/Hosts | Host-Allowlist in `bootstrap.php`, Fremd-Host liefert 404 | offen | | Bestandsmatrix „nicht vorhanden" |
| Plattform/Hosts | Adminbereich nur auf `admin.smart-einzug.de`, Kundenrouten dort gesperrt | offen | | Bestandsmatrix „vorhanden, gleicher Host" als Ausgangszustand, Trennung noch offen |
| Plattform/Hosts | Session-Cookie hostgebunden, strict | implementiert | | Bestandsmatrix „vorhanden", keine Änderung vorgesehen |
| Plattform/Hosts | Redirect-Aliase (4 Domains), `.htaccess` je Alias, 301, Pfad-Mapping, 404 sonst | offen | | Bestandsmatrix „nicht vorhanden", `.htaccess`-Dateien liegen im Repository vor, DNS/Produktivschaltung bei IONOS offen |
| Plattform/Hosts | Webhooks alt/neu bleiben nebeneinander erreichbar, keine 301 auf POST | implementiert | | Bestandsmatrix „vorhanden", Idempotenz über `webhook_events` |
| Plattform/Hosts | Cron weiterhin nur eine Instanz nach Domain-Umzug | implementiert | | Bestandsmatrix „vorhanden, unverändert" |
| Marke | Produktname SmartEinzug in App, E-Mails, Seiten; technische IDs bleiben unverändert | offen | | Umbenennung laut Bestandsmatrix Paket B/C, Umsetzungsstand außerhalb dieses Repositorys zu prüfen |
| Marke | Bildmarke Euro auf Anthrazit, neue Wortmarke SmartEinzug, neue Assets | implementiert | | Logo-Paket liegt vor (`logo-paket-smarteinzug.zip`), Einbindung in Website vorhanden (`websites/smart-einzug.de`) |
| Marke | Signatur-Kommentar auf allen HTML-Seiten | implementiert | | Bestandsmatrix „vorhanden", unverändert beizubehalten |
| Zahlungskern | Bestandsrechnungen vor Registrierung geprüft, nicht automatisch freigegeben | offen | | Bestandsmatrix Paket D, Testfall „Test mit Altrechnung" |
| Zahlungskern | Restbetrag (`openAmount`) vor Einreichung geprüft, Blockade bei Abweichung | offen | | Bestandsmatrix „nicht vorhanden", Testfall „E2E Teilzahlung" |
| Zahlungskern | Idempotenz Stripe über `collection_attempts` mit persistiertem Key, Wiederholung mit gleichem Key | offen | | Bestandsmatrix „teilweise", Testfall „E2E Doppelklick" |
| Zahlungskern | Mandat: Einstellung „Handschriftlicher Nachweis erforderlich", Stripe-Mandats-ID gespeichert | offen | | Bestandsmatrix „vorhanden" als Ausgangszustand, Umbenennung und Speicherung neu, Testfall „Regeltests" |
| Zahlungskern | Stripe-Mandatsobjekt: `stripe_mandate_id`, `stripe_mandate_reference` beim Einzug gespeichert | offen | | Bestandsmatrix „teilweise", Testfall „E2E" |
| Zahlungskern | Digitale Mandatsanforderung (`mandate_requests`, öffentliche Seite ohne Session, Stripe Checkout mode=setup, Feature-Schalter aus) | offen | | Bestandsmatrix „nicht vorhanden", Testfall „E2E Token, Scanner" |
| Zahlungskern | Regelbasierte Automatik (`collection_rules`, Schalter aus, Vorschau, keine Verarbeitung ohne Freigabe) | offen | | Bestandsmatrix „nicht vorhanden", Testfall „Unit" |
| Zahlungskern | Not-Stopp (`organizations.collections_paused`, `platform_settings.collections_paused`) in allen Einreichpfaden geprüft | offen | | Bestandsmatrix „nicht vorhanden", Testfall „E2E" |
| Zahlungskern | Rückgaben/Erstattungen aus Stripe übernommen, kein Neu-Einzug ohne Freigabe | offen | | Bestandsmatrix „teilweise", Testfall „E2E Webhook" |
| Zahlungskern | Journal-/CSV-Export (`export.php`) mit Formelschutz | offen | | Bestandsmatrix „nicht vorhanden", Testfall „E2E" |
| Zahlungskern | Rückschreibung nach Lexware Office | offen (bewusst nicht gebaut) | | Bestandsmatrix „nicht vorhanden, nicht bauen", stattdessen Ratgeber-Anleitung zur manuellen Zuordnung, dieser Ratgeber ist laut Bestandsmatrix bereits vorhanden |
| Zahlungskern | Adaptergrenze Rechnungssystem (`invoice_source.php`, Registry `integration_providers`) | offen | | Bestandsmatrix Paket F, Testfall „php -l, E2E" |
| Zahlungskern | Firmenlastschrift (B2B) weiterhin nicht angeboten und nicht beworben | implementiert | | Bestandsmatrix „korrekt", keine Änderung vorgesehen |
| Registrierung/Abo/Messung | Voraussetzungstext und Zahlungspflicht-Hinweis auf Registrierungsseite | offen | | Bestandsmatrix Paket E, Testfall „E2E" |
| Registrierung/Abo/Messung | Plattform-Billing bleibt deaktiviert | implementiert | | Bestandsmatrix „vorhanden, aus", keine Aktivierung vorgesehen |
| Registrierung/Abo/Messung | Consent/GA4/Ads: smart-einzug.de ohne IDs vorbereitet, Ads-Conversion in App nach Verifikation, einwilligungsgebunden | offen | | Bestandsmatrix Paket E, Testfall „Browsertest"; siehe auch `docs/ads-conversions.md`, offener Punkt fehlende Conversion-ID/Label |
| Registrierung/Abo/Messung | Funnel-Ereignis `email_verified` ergänzt | offen | | Bestandsmatrix „zu ergänzen", Testfall „E2E" |
| Registrierung/Abo/Messung | Funnel-Ereignis `first_live_collection` ergänzt | offen | | Bestandsmatrix „zu ergänzen", Testfall „E2E" |
| Domains/SEO | Alle neun Hosts bei IONOS angelegt, Ordner zugeordnet, SSL aktiv | offen | | Siehe `docs/smarteinzug-rollout.md`, außerhalb des Repositorys zu prüfen |
| Domains/SEO | Aktivierungsreihenfolge eingehalten (Website, App-Hosts, `base_url`-Wechsel, Admin-Host) | offen | | Siehe `docs/smarteinzug-rollout.md` |
| Domains/SEO | Redirect-Matrix der Aliase per curl geprüft | offen | | Testfall laut Bestandsmatrix „curl-Matrix nach DNS" |
| Domains/SEO | `seo-url-map.csv` mit Indexierungsstatus je Host/Pfad gepflegt | implementiert | 05.09.2026 | Diese Abnahmevorlage selbst, siehe `docs/seo-url-map.csv` |
| Marketing/Ads | Anzeigengruppen, Keywords, Negativkeywords, Anzeigentexte gemäß Zeichenlängen geprüft | implementiert | 05.09.2026 | Siehe `docs/ads-conversions.md`, Entwurf, gegen tatsächlichen Auftragstext zu prüfen |
| Marketing/Ads | Wettbewerber-Anzeigengruppen erst nach Freigabe aktiv geschaltet | offen | | Freigabe durch Geschäftsführung steht laut `docs/ads-conversions.md` aus |

## Offene Punkte

- Der tatsächliche Auftragstext „Abschnitt 34" mit der verbindlichen Liste der Abnahmefälle lag mir nicht vor; diese Vorlage ist daher aus der Bestandsmatrix abgeleitet und muss gegen den Originaltext abgeglichen werden.
- Datumsspalte ist für die meisten Zeilen leer zu lassen, bis der jeweilige Test tatsächlich durchgeführt wurde; nicht rückwirkend befüllen.
- Diese Vorlage ersetzt keine geldflussrelevante Abnahme (Zahlungskern), diese bleibt laut CLAUDE.md dem Hauptmodell mit hohem Prüfaufwand vorbehalten.
