# Produktfaktenquelle: Nachweise und veröffentlichbarer Stand (13.09.2026)

Register: `php-ionos/app/product_facts.php` (Version 1.0, geprüft 13.09.2026, 46 Aussagen: 36 technisch getestet,
5 öffentlich behauptet, 2 geplant, 3 ungeklärt; 39 öffentlich). Snapshot: `docs/contracts/product-facts.snapshot.json`.
Endpunkt: `php-ionos/fakten.php`. Prüfung: `php tools/product-facts-check.php` (33/0), im Workflow-Job `test`.

## Zuständigkeit

Backend führt technische Funktionen, Integrationsstatus, Tarif- und Abrechnungsdaten und überprüfbare Sicherheitsmerkmale.
Juristische und kaufmännische Freigaben (AGB, AVV, Preise, Kündigungsregeln der AGB) werden als Status geführt, nie aus dem
Code abgeleitet: `ungeklaert` bleibt intern, `oeffentlich_behauptet` darf nur mit dem Registerwortlaut verwendet werden.

## Pflege

1. Aussage in `product_facts()` ändern oder ergänzen (Schlüssel, Bereich, Wert, Status, Quelle, Prüfdatum, Veröffentlichen).
2. `php php-ionos/bin/product-facts.php --export` ausführen, Snapshot mit committen.
3. `php tools/product-facts-check.php` muss grün sein (Snapshot aktuell, keine Geheimnisse, Konsistenz mit `plans`,
   `pricing.php`, Webhook-Ereignissen, Mandatsvorgaben).
4. Bei geändertem Wortlaut einer öffentlichen Aussage den Frontend-Chat über die Vertragsversion informieren
   (`docs/contracts/smarteinzug-contract.md`).

## Nachweise je Bereich

| Bereich | Nachweis | Grenze |
|---|---|---|
| Produkt, Anbieter | `product_name()`, Fußband in `mail_layout()`, Skill mhag-ci | Registerdaten aus Betreibervorgabe |
| Lexware Office | `app/lexoffice.php`, `tools/sync-check.sh`, Faktenregister SYNC-01, SYNC-16 | Tarifvoraussetzung ungeklärt (Primärquelle nicht abgerufen) |
| sevdesk | `app/integration_state.php`, `docs/sevdesk.md` | geplant; kein Testkonto |
| Zahlung | `app/collections.php`, `app/integrations.php`, `app/stripe.php`, collections-check 14, 14d, 16 | SEPA-Schema (Core) nur öffentlich behauptet, Stripe-Dokumentation nicht abgerufen |
| Mandat, Einzug | `schema.sql` Vorgaben, `app/mandates.php`, `app/mandate_requests.php`, collections-check 5, 9 | Produktionswerte der Konfiguration nicht aus dem Repository belegbar |
| Sicherheit | `app/auth.php`, `tools/auth-check.sh`, `app/audit.php` | Rechenzentrumsstandort ungeklärt |
| Tarif | `plans`-Seed, `bin/billing-setup-stripe.php`, `pricing.php`, `billing.php` | Preisbeträge bis zur Freigabe nicht öffentlich; regulärer Preis 50,00 EUR nur Projektvorgabe |
| Recht | `consent.php`, `legal.php` | Freigabe durch Rechtsberatung offen |

## Veröffentlichbarer Stand (Auszug)

Öffentlich sind unter anderem: Produktname und Anbieter, Domains, Kategorie und Zielgruppe, Lexware Office verfügbar mit
Lesezugriff, sevdesk angekündigt, Stripe-Kontomodell ohne Connect, Schlüsselschutz, Kontoprüfung mit SEPA-Fähigkeit,
sepa_debit ohne B2B, Trennung Abonnement gegen Einzüge, sieben Webhook-Ereignisse, Rücklastschrift-Erkennung, Mandat mit
handschriftlichem Nachweis als Vorgabe, optionale Vorabankündigung, Restbetragsprüfung, Doppelschutz, Karenz und Fenster,
Not-Stopp, 2FA-Pflicht, Mandantentrennung, Protokoll, Tarifname, 28-Tage-Periode, rollierender Stichtag, keine Begrenzung,
Kündigung zum Periodenende, keine Rückschreibung, kein B2B.

Nicht öffentlich: Preisbeträge (Freigabe), Lexware-Tarif, sevdesk-Freigabedetails, Hosting-Standort, Rechtsdokumente,
WISO-Abgrenzung, Conversion-Ereignisse.
