# Bestandsmatrix SmartEinzug (Paket A), Stand 05.09.2026

Grundlage: Code im Repository (php-ionos, websites, tools), keine Geldflussprüfung im Produktivsystem. Zustände: vorhanden, teilweise, nicht vorhanden, nicht prüfbar.

## Plattform und Hosts

| Funktion | Nachweis | Zustand | Änderung (Paket) | Abnahme |
|---|---|---|---|---|
| App unter app.smart-einzug.de, `base_url` in config | `app/config.php`, alle Links über `config('base_url')` | vorhanden | Getrennte `public_base_url`, `app_base_url`, `admin_base_url`, `allowed_hosts` (C) | Setup-Check, E2E |
| Host-Allowlist | keine | nicht vorhanden | `bootstrap.php` prüft Host gegen Allowlist, sonst 404 (C) | E2E mit fremdem Host-Header |
| Adminbereich | `admin.php`, `require_superadmin()` (is_superadmin + 2FA) | vorhanden, gleicher Host | Nur auf `admin.smart-einzug.de` ausliefern, sonst 404; Kundenrouten auf Adminhost sperren (C) | E2E |
| Session-Cookie | `LXEINZUGSESSID`, hostgebunden, strict | vorhanden | keine Änderung (kein Domain-Cookie) | E2E |
| Redirect-Domains (4 Aliase) | keine | nicht vorhanden | `.htaccess` je Alias, 301 auf https://smart-einzug.de, Pfad-Mapping, 404 sonst (B) | curl-Matrix nach DNS |
| Webhooks alt/neu | `stripe-webhook.php`, `billing-webhook.php`, `webhook_events` idempotent | vorhanden | Alte Endpunkte bleiben; keine 301 auf POST (C) | E2E Webhook |
| Cron | `cron.php?token`, 4x täglich IONOS | vorhanden | unverändert, nur eine Instanz | manuell |

## Marke

| Funktion | Nachweis | Zustand | Änderung | Abnahme |
|---|---|---|---|---|
| Produktname | `config('product_name')` = Lexware-Einzug, Wortmarke auf Seiten | vorhanden | SmartEinzug in App, E-Mails, Seiten; technische IDs bleiben (B, C) | Sichtprüfung, QA |
| Bildmarke Euro auf Anthrazit | Logo-Paket, Favicons | vorhanden | Wortmarke SmartEinzug, Assets neu (B) | Screenshots |
| Signatur-Kommentar | alle HTML-Seiten | vorhanden | beibehalten | QA |

## Zahlungskern

| Funktion | Nachweis | Zustand | Änderung (Paket D) | Abnahme |
|---|---|---|---|---|
| Rechnungsimport offen/überfällig, inkrementell, paginiert | `app/lexoffice.php`, `sync_state` | vorhanden | Bestandsrechnungen vor Registrierung: prüfen, nicht automatisch freigeben | Test mit Altrechnung |
| Restbetrag vor Einreichung | `_load_and_validate` prüft nur Status, nutzt `total_gross_amount` | nicht vorhanden | Lexware Payments-Endpunkt `openAmount` vor Einreichung, `invoices.open_amount`/`open_amount_fetched_at`, Blockade bei Abweichung | E2E Teilzahlung |
| Idempotenz Stripe | `webhook_events` idempotent; PaymentIntent ohne Idempotency-Key, kein Versuchs-Journal | teilweise | Tabelle `collection_attempts` mit persistiertem Key vor Aufruf, Wiederholung mit gleichem Key, Status unklar | E2E Doppelklick |
| Mandat, Unterschrift, Verfall 36 Monate, Prenotification | `app/mandates.php`, `collections.php` | vorhanden | Einstellung umbenennen "Handschriftlicher Nachweis erforderlich"; Stripe-Mandats-ID speichern | Regeltests |
| Stripe-Mandatsobjekt gespeichert | `stripe_payment_method_id`, `stripe_customer_id` | teilweise | `stripe_mandate_id`, `stripe_mandate_reference` beim Einzug speichern | E2E |
| Digitale Mandatsanforderung | keine | nicht vorhanden | Tabelle `mandate_requests`, öffentliche Seite ohne Session/Tags, Stripe Checkout mode=setup, Feature-Schalter aus | E2E Token, Scanner |
| Regelbasierte Automatik | keine, Cron reicht nur terminierte Einzüge ein | nicht vorhanden | Tabelle `collection_rules`, Schalter aus, Vorschau, keine Verarbeitung ohne Freigabe | Unit |
| Not-Stopp | keine | nicht vorhanden | `organizations.collections_paused`, `platform_settings.collections_paused`, in allen Einreichpfaden geprüft | E2E |
| Rückgaben, Erstattungen | Dispute-Webhook setzt Status, Rechnung wieder offen | teilweise | Erstattungen aus Stripe übernehmen, kein Neu-Einzug ohne Freigabe | E2E Webhook |
| Journal, CSV-Export | keine | nicht vorhanden | `export.php` CSV (Rechnung, Herkunfts-ID, Einzug, Stripe-Referenzen, Beträge, Status, Gebühr falls bekannt), Formelschutz | E2E |
| Rückschreibung nach Lexware | keine (kein dokumentierter Schreibendpunkt) | nicht vorhanden | nicht bauen, Anleitung zur manuellen Zuordnung (Ratgeber vorhanden) | Doku |
| Adaptergrenze Rechnungssystem | direkte Aufrufe `LexofficeClient` | nicht vorhanden | `app/invoice_source.php` Interface + Lexware-Adapter, Registry `integration_providers` (F) | php -l, E2E |
| Firmenlastschrift (B2B) | nicht angeboten | korrekt | keine Änderung, nicht bewerben | Texte |

## Registrierung, Abo, Messung

| Funktion | Nachweis | Zustand | Änderung | Abnahme |
|---|---|---|---|---|
| Registrierung, E-Mail-Verifikation, Pflicht-2FA, Rollen | `register.php`, `auth.php` | vorhanden | Voraussetzungstext, Zahlungspflicht-Hinweis (E) | E2E |
| Plattform-Billing | `billing.php`, Stripe Tax, deaktiviert | vorhanden, aus | keine Aktivierung | Doku |
| Consent, GA4, Ads auf Marketingseiten | `site.js`, Consent Mode | vorhanden | smart-einzug.de ohne IDs vorbereitet, Ads-Conversion in App nach Verifikation, einwilligungsgebunden (E) | Browsertest |
| Funnel-Ereignisse serverseitig | `funnel_events`, `track.php` | vorhanden | Ereignis `email_verified`, `first_live_collection` ergänzen | E2E |

## Nicht prüfbar aus dem Repository

DNS und Zertifikate der neuen Domains, IONOS-Ordnerzuordnung, tatsächlicher Lexware-Tarif der Kunden, Stripe-Kontofreischaltung SEPA, Suchvolumina, Wettbewerberpreise.
