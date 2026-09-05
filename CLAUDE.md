# Lexware-Einzug (LexSEPA) – Projektregeln für Claude

Betreiber: Müller Holding AG. Produkt: Lexware-Einzug, SEPA-Lastschriften für Rechnungen aus Lexware Office über Stripe.
Struktur: `php-ionos/` (PHP 8, MariaDB, IONOS Shared Hosting, kein Composer), `websites/lexware-einzug.de`, `websites/lexoffice-einzug.de` (statisches HTML), `tools/` (QA-Skripte).

## Arbeitsweise und Wirtschaftlichkeit (verbindlich)

- Kosten bewusst steuern. Bei Multi-Agent-Workflows Modell und Denkaufwand je Arbeitspaket wählen, nicht alles mit dem teuersten Modell:
  - `haiku` oder `sonnet`, Aufwand `low` bis `medium`: mechanische Arbeit (Texte nach Vorlage, Formatierung, Umbenennungen, Sitemaps, Datensammlung, Screenshots, einfache Tests).
  - `sonnet`, Aufwand `medium`: Inhaltsseiten, Ratgeberartikel, Standard-Implementierung nach klarer Vorgabe.
  - Hauptmodell (Fable) mit Aufwand `high` nur für Architektur, Sicherheitsprüfung, Geldfluss (Einzüge, Mandate, Abrechnung), Rechtstexte, Abnahme und adversariale Verifikation.
- Prüfpanels mit mehreren Agenten nur dort, wo Fehler teuer sind (Sicherheit, Zahlungen, Recht). Routinearbeit: ein Agent plus automatische Tests (`tools/site-qa.py`, E2E-Suite, `php -l`).
- Vor einem Workflow den Aufwand kurz abschätzen; wenn eine Aufgabe in wenigen Schritten direkt erledigt werden kann, keinen Workflow starten.
- Ergebnisse immer mit den vorhandenen Tests absichern, bevor committet wird.

## Fachliche Regeln

- Sprache Deutsch, Sie-Form, kaufmännisch, keine Gedankenstriche, Datum TT.MM.JJJJ, Beträge 1.234,56 EUR.
- Keine erfundenen Fakten, Fristen, Paragraphen oder Zahlen. Lexware-Aussagen (z. B. Tarif XL für Public API) vorsichtig formulieren.
- Preise: UNLIMITED START, Einführungspreis 25,00 EUR netto je 4 Wochen (bisher 50,00 EUR) für Accounts bis 31.12.2026, alle Preise netto zzgl. USt.
- Marketingseiten: Inhalte der beiden Domains dürfen sich nicht gleichen (QA-Grenze 35 %), nur Self-Canonicals, `python3 tools/site-qa.py` muss 0 Fehler liefern, danach `tools/build-sitemaps.py` ausführen.
- App: Zugangsdaten nur verschlüsselt, nie protokollieren oder im Frontend zeigen; jede geldrelevante Aktion in `audit_log`; Mandantentrennung in jeder Abfrage.
- Google-Tags (GA4, Ads) nur hinter der Einwilligung in `assets/js/site.js`, niemals direkt im HTML.

## Abläufe

- Tests: E2E `scratchpad/e2e_saas.php` gegen lokale Kopie mit frischer Datenbank (`sql/schema.sql`), Regeln `test_rules_sync.php`, TOTP `test_totp.php`.
- Auslieferung: `websites.zip` (beide Domain-Ordner inkl. `.htaccess`) und `lexware-einzug-deploy.zip` (App ohne `config.php`, SQL, README) nach `/mnt/user-data/outputs/`, Migrationen zusätzlich einzeln als `.sql`.
- Commits mit Autor Timo Müller, Push auf den vorgegebenen Branch.
