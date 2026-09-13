<?php
/**
 * Zentrale Produktfaktenquelle (Version 4.76, Masterprompt Backend 13.09.2026).
 *
 * Ein versioniertes Register der Aussagen ueber das Produkt, die Website, Grounding Page, Hilfe und JSON-LD verwenden
 * duerfen. Jede Aussage traegt Status, Quelle im Repository und Pruefdatum:
 *   technisch_getestet   im Code vorhanden und durch einen Pruefstand oder Testlauf belegt (Quelle nennt Suite oder Datei)
 *   oeffentlich_behauptet vom Betreiber oeffentlich so formuliert, im Code nicht oder nicht vollstaendig belegbar
 *   geplant              in Vorbereitung, keine Zusage
 *   ungeklaert           Nachweis fehlt (Freigabe, Vertrag, Primaerquelle); darf nicht veroeffentlicht werden
 * "veroeffentlichen" steuert den oeffentlichen Snapshot (bin/product-facts.php --export, fakten.php). Der Snapshot enthaelt
 * nie Quellen, Serverpfade, Zugangsdaten oder interne Kundendaten. Preise erscheinen erst nach Freigabe des Betreibers
 * (Vorgabe 07.09.2026); ein unbestaetigter Preis wird weder als 0 noch als kostenlos ausgegeben, sondern weggelassen.
 *
 * Bewusst ohne Datenbank und ohne Bootstrap, damit tools/product-facts-check.php und der Workflow die Datei ohne
 * Konfiguration laden koennen. Laufzeitwerte (Freigabestand sevdesk) ergaenzt fakten.php aus platform_settings.
 * Juristische und kaufmaennische Freigaben (AGB, AVV, Preise) werden hier nur als Status gefuehrt, nie aus Code abgeleitet.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/pricing.php';

const PRODUCT_FACTS_VERSION = '1.0';
const PRODUCT_FACTS_SCHEMA = 'smarteinzug-product-facts';
const PRODUCT_FACT_STATES = ['technisch_getestet', 'oeffentlich_behauptet', 'geplant', 'ungeklaert'];
/** Zustaende, die im oeffentlichen Snapshot erscheinen duerfen (ungeklaert nie). */
const PRODUCT_FACT_PUBLIC_STATES = ['technisch_getestet', 'oeffentlich_behauptet', 'geplant'];
const PRODUCT_FACTS_CHECKED = '13.09.2026';

/**
 * Vollstaendiges internes Register. Reihenfolge ist stabil (Snapshot-Vergleich im Pruefwerkzeug).
 * @return list<array{key:string,bereich:string,wert:string|array,status:string,quelle:list<string>,geprueft_am:?string,veroeffentlichen:bool,hinweis:string}>
 */
function product_facts(): array
{
    $f = static fn(string $key, string $bereich, string|array $wert, string $status, array $quelle, bool $publish, string $hinweis = '', ?string $geprueft = PRODUCT_FACTS_CHECKED): array
        => ['key' => $key, 'bereich' => $bereich, 'wert' => $wert, 'status' => $status, 'quelle' => $quelle, 'geprueft_am' => $geprueft, 'veroeffentlichen' => $publish, 'hinweis' => $hinweis];

    return [
        // --- Produkt und Anbieter ---
        $f('produkt.name', 'produkt', 'SmartEinzug', 'technisch_getestet', ['php-ionos/app/bootstrap.php product_name()'], true, 'Konfigurierbar (product_name), Vorgabe SmartEinzug.'),
        $f('produkt.anbieter', 'produkt', 'Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein, Amtsgericht Düsseldorf HRB 104291, Vorstand Timo Müller', 'oeffentlich_behauptet', ['php-ionos/app/mailer.php mail_layout() Fußband', 'Skill mhag-ci Abschnitt 1'], true, 'Handelsregisterangaben aus der Vorgabe des Betreibers, im Code als Pflichtangabe der E-Mails.'),
        $f('produkt.domain_website', 'produkt', 'https://smart-einzug.de', 'technisch_getestet', ['php-ionos/app/bootstrap.php public_base_url() Vorgabe'], true),
        $f('produkt.domain_anwendung', 'produkt', 'https://app.smart-einzug.de', 'oeffentlich_behauptet', ['php-ionos/app/config.example.php base_url'], true, 'Produktionswert steht in shared/config.php auf dem Server, im Repository nur die Vorgabe.'),
        $f('produkt.kategorie', 'produkt', 'SEPA-Lastschrifteinzug für offene Rechnungen aus Lexware Office über das eigene Stripe-Konto des Unternehmens', 'technisch_getestet', ['php-ionos/app/collections.php', 'php-ionos/app/sync.php'], true),
        $f('produkt.zielgruppe', 'produkt', 'Unternehmen und Selbstständige mit Lexware Office und eigenem Stripe-Konto (geschäftliches Angebot)', 'oeffentlich_behauptet', ['websites/smart-einzug.de/index.html', 'docs/seo/02-faktenregister.md'], true),
        $f('produkt.leadseiten', 'produkt', ['lexware-einzug.de', 'lexoffice-einzug.de', 'sevdesk-einzug.de', 'sevdesk-sepa.de'], 'technisch_getestet', ['php-ionos/app/config.example.php signup_domains', 'CLAUDE.md Leadseiten'], true, 'Leadseiten der DETM Management Consulting FZCO; Anbieter der Software bleibt die Müller Holding AG.'),

        // --- Integrationen ---
        $f('integration.lexware_office.name', 'integration', 'Lexware Office (ehemals lexoffice)', 'technisch_getestet', ['php-ionos/app/lexoffice.php'], true, 'Ein Produkt mit zwei Namen, nie als zwei Buchhaltungen darstellen.'),
        $f('integration.lexware_office.status', 'integration', 'verfuegbar', 'technisch_getestet', ['tools/sync-check.sh', 'tools/collections-check.sh'], true),
        $f('integration.lexware_office.zugriff', 'integration', 'Nur lesend über die Lexware Office Public API (GET /profile, /voucherlist, /invoices/{id}, /contacts/{id}); keine Buchung, keine Rückschreibung von Zahlungen', 'technisch_getestet', ['php-ionos/app/lexoffice.php', 'docs/seo/02-faktenregister.md SYNC-01'], true),
        $f('integration.lexware_office.voraussetzung', 'integration', 'API-Schlüssel der Lexware Office Public API (wird beim Speichern über GET /profile geprüft, verschlüsselt gespeichert, nie angezeigt)', 'technisch_getestet', ['php-ionos/settings.php save_lexoffice', 'php-ionos/app/integrations.php integration_verify_lexoffice()'], true),
        $f('integration.lexware_office.tarif', 'integration', 'Lexware bindet die Public API an bestimmte Tarife; die Anwendung prüft keinen Tarif', 'ungeklaert', ['docs/seo/02-faktenregister.md SYNC-16'], false, 'Primärquelle (developers.lexware.io) in dieser Sitzung nicht abrufbar; Formulierung nur vorsichtig, keine Tarifbezeichnung nennen.', null),
        $f('integration.sevdesk.status', 'integration', 'angekuendigt', 'geplant', ['php-ionos/app/integration_state.php INTEGRATION_PUBLIC_STATES', 'docs/sevdesk.md'], true, 'Laufzeitwert aus platform_settings (sevdesk_public_state) liefert fakten.php; Snapshot enthält die Vorgabe. Öffentlich gilt „in Vorbereitung, Start für Ende September 2026 geplant“, nie als Zusage.'),
        $f('integration.sevdesk.freigabe', 'integration', 'Verbinden und Lesen ab dem Freigabetermin (Vorgabe 30.09.2026, Europe/Berlin) nur für Pilotfirmen; Einzüge erst nach Prüfung mit einem sevdesk-Testkonto (sevdesk_api_verified, sevdesk_collections)', 'geplant', ['php-ionos/app/integration_state.php', 'docs/sevdesk.md', 'CLAUDE.md sevdesk'], false, 'Adapter nach Sekundärquellen ohne Testkonto gebaut (ANNAHME-Marken im Code).'),
        $f('integration.buchhaltungssystem_je_firma', 'integration', 'Genau ein Buchhaltungssystem je Firmenaccount; Wechsel nur durch Inhaber oder Administrator mit 2FA, danach Sperre von 28 Tagen', 'technisch_getestet', ['php-ionos/app/invoice_source_switch.php INVOICE_SOURCE_LOCK_DAYS', 'tools/invoice-source-check.sh'], true),

        // --- Zahlung und Kontomodell ---
        $f('zahlung.anbieter', 'zahlung', 'Stripe. Jede Firma verbindet ihr eigenes Stripe-Konto; Lastschriften und Gutschriften laufen ausschließlich über dieses Konto. SmartEinzug hält kein Kundengeld.', 'technisch_getestet', ['php-ionos/app/collections.php _get_stripe_client()', 'php-ionos/app/integrations.php'], true),
        $f('zahlung.kontomodell', 'zahlung', 'API-Schlüssel je Firma (Secret Key oder Restricted Key); kein Stripe Connect, keine Stripe App, kein OAuth', 'technisch_getestet', ['php-ionos/settings.php save_stripe', 'docs/backend/stripe-review.md'], true),
        $f('zahlung.schluessel_schutz', 'zahlung', 'Schlüssel werden AES-256-GCM verschlüsselt gespeichert, nie angezeigt, nie protokolliert; empfohlen ist ein eingeschränkter Schlüssel mit minimalen Rechten', 'technisch_getestet', ['php-ionos/app/crypto.php', 'php-ionos/settings.php Hinweistext Restricted keys'], true),
        $f('zahlung.kontopruefung', 'zahlung', 'Beim Verbinden und bei „Verbindung prüfen“ werden Konto, Zahlungsfreischaltung (charges_enabled) und SEPA-Fähigkeit (capabilities.sepa_debit_payments) geprüft; ohne aktive SEPA-Fähigkeit werden keine Einzüge eingereicht', 'technisch_getestet', ['php-ionos/app/integrations.php stripe_connection_state()', 'tools/collections-check.sh Abschnitt 16'], true),
        $f('zahlung.verfahren', 'zahlung', 'SEPA-Lastschrift über Stripe (Zahlungsmethode sepa_debit); die Anwendung erzeugt keine Firmenlastschrift (B2B)', 'technisch_getestet', ['php-ionos/app/collections.php createPaymentIntent', 'php-ionos/app/stripe.php'], true),
        $f('zahlung.verfahren_schema', 'zahlung', 'SEPA-Basislastschrift (Core) laut Stripe-Dokumentation', 'oeffentlich_behauptet', ['CLAUDE.md sevdesk (Regel Stripe unterstützt Core, nicht B2B)', 'https://docs.stripe.com/payments/sepa-debit'], true, 'Stripe-Dokumentation in dieser Sitzung nicht abrufbar (Netzsperre); Prüfdatum offen.', null),
        $f('zahlung.abonnement_trennung', 'zahlung', 'Das Abonnement der Firma für SmartEinzug läuft über das Stripe-Konto der Müller Holding AG und ist von den Einzügen der Firma gegenüber ihren Kunden vollständig getrennt', 'technisch_getestet', ['php-ionos/app/billing.php', 'php-ionos/billing-webhook.php', 'tools/billing-setup-check.php'], true),
        $f('zahlung.webhook_ereignisse', 'zahlung', ['payment_intent.processing', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.dispute.created', 'charge.refunded', 'charge.refund.updated', 'checkout.session.completed'], 'technisch_getestet', ['php-ionos/stripe-webhook.php', 'tools/collections-check.sh Abschnitt 14'], true),
        $f('zahlung.ruecklastschrift', 'zahlung', 'Rücklastschriften meldet der Stripe-Webhook; zusätzlich prüft der manuelle Statusabgleich abgeschlossene Einzüge der letzten 70 Tage (Vorgabe). Die Rechnung erhält Klärungsbedarf, ein automatischer Neu-Einzug findet nicht statt.', 'technisch_getestet', ['php-ionos/app/collections.php collection_apply_dispute()', 'tools/collections-check.sh Abschnitt 14d'], true),
        $f('zahlung.zahlungseingang', 'zahlung', 'Erfolg, spätere Rückgabe und Auszahlung sind getrennte Zustände; SmartEinzug nennt keine garantierten Zahlungseingangstage', 'oeffentlich_behauptet', ['docs/contracts/smarteinzug-contract.md Abschnitt Zustände'], true),

        // --- Mandate und Einzug ---
        $f('mandat.nachweis', 'mandat', 'Eigenes SEPA-Mandatsdokument je Kunde mit Mandatsreferenz; der handschriftliche Nachweis ist standardmäßig erforderlich (Firma kann die Einstellung ändern); digitales Mandat über Stripe Checkout (Setup) möglich', 'technisch_getestet', ['php-ionos/sql/schema.sql organizations.require_signed_mandate', 'php-ionos/app/mandates.php', 'php-ionos/app/mandate_requests.php'], true),
        $f('mandat.vorabankuendigung', 'mandat', 'Vorabankündigung per E-Mail durch SmartEinzug optional (Vorgabe aus), Frist Vorgabe 14 Tage; Stripe versendet eigene Benachrichtigungen', 'technisch_getestet', ['php-ionos/sql/schema.sql organizations.send_pre_notification, pre_notification_days', 'docs/kunden/handbuch.md 8.4'], true, 'Ob eine Vorabankündigung im konkreten Verfahren nötig ist, ist rechtlich vom Unternehmen zu beurteilen.'),
        $f('einzug.restbetragspruefung', 'einzug', 'Unmittelbar vor jeder Einreichung wird der offene Betrag der Rechnung bei Lexware Office abgerufen: bezahlte Rechnungen werden nicht eingezogen, Teilzahlungen nur nach Bestätigung des Restbetrags', 'technisch_getestet', ['php-ionos/app/collections.php _determine_collection_amount()', 'tools/collections-check.sh Abschnitt 5'], true),
        $f('einzug.doppelschutz', 'einzug', 'Versuchsjournal mit Idempotenzschlüssel je Versuch, höchstens ein PaymentIntent je Einzug, Einzüge je Firma serialisiert; unbekannte Ergebnisse gehen in die Klärung statt in eine Wiederholung', 'technisch_getestet', ['docs/audit/PAYMENT_INVARIANTS.md', 'tools/collections-check.sh Abschnitte 4, 7, 8'], true),
        $f('einzug.karenz_und_fenster', 'einzug', 'Vorgabe: Karenzzeit 4 Stunden vor der Einreichung (bis dahin stornierbar), Einreichfenster 23:00 bis 06:00 Uhr; je Installation konfigurierbar', 'technisch_getestet', ['php-ionos/app/collections.php collections_rules_config()', 'tools/collections-check.sh Abschnitt 9'], true, 'Produktionswerte in shared/config.php, nicht aus dem Repository belegbar.'),
        $f('einzug.not_stopp', 'einzug', 'Not-Stopp je Firma und für die Plattform hält alle Einreichungen an; Aufheben nur mit 2FA-Code', 'technisch_getestet', ['php-ionos/app/collections.php collections_pause_reason()', 'tools/collections-check.sh'], true),

        // --- Sicherheit ---
        $f('sicherheit.zwei_faktor', 'sicherheit', 'Zwei-Faktor-Authentifizierung (TOTP, Authenticator-App) für jeden Benutzer verpflichtend, Recovery-Codes nur als Hash, Gerätefreigabe 90 Tage', 'technisch_getestet', ['php-ionos/app/auth.php require_login()', 'tools/auth-check.sh', 'docs/seo/02-faktenregister.md KONTO-01 bis KONTO-03'], true),
        $f('sicherheit.mandantentrennung', 'sicherheit', 'Jede Abfrage ist an die Firma (tenant_id) gebunden; Zugriffe über Firmengrenzen sind im Prüfstand ausgeschlossen', 'technisch_getestet', ['tools/collections-check.sh Abschnitte 3, 14a, 16'], true),
        $f('sicherheit.protokoll', 'sicherheit', 'Geldrelevante und sicherheitsrelevante Aktionen werden protokolliert; Aufbewahrung Vorgabe 90 Tage', 'technisch_getestet', ['php-ionos/app/audit.php audit_retention_days()'], true),
        $f('sicherheit.hosting', 'sicherheit', 'Anwendung in Docker auf einem VPS mit MariaDB; Rechenzentrumsstandort im Repository nicht belegt', 'ungeklaert', ['deploy/vps/', 'docs/vps/01-architektur.md'], false, 'Standortangabe erst nach Nachweis des Hosters.', null),

        // --- Tarif und Abrechnung ---
        $f('tarif.name', 'tarif', 'UNLIMITED START', 'technisch_getestet', ['php-ionos/sql/schema.sql plans'], true),
        $f('tarif.periode', 'tarif', 'Abrechnung je 28 Tage (nicht je Kalendermonat) über ein Stripe-Abonnement der Müller Holding AG', 'technisch_getestet', ['php-ionos/sql/schema.sql plans.period_days', 'php-ionos/bin/billing-setup-stripe.php interval day x 28'], true),
        $f('tarif.preis', 'tarif', 'Einführungspreis 25,00 EUR netto je 28 Tage (plans.price_cents 2500); regulärer Preis laut Projektvorgabe 50,00 EUR netto; alle Preise netto zzgl. USt', 'technisch_getestet', ['php-ionos/sql/schema.sql plans', 'CLAUDE.md Preise'], false, 'Veröffentlichung der Beträge auf den Marketingseiten bis zur Freigabe des Betreibers gesperrt (Vorgabe 07.09.2026, tools/pricing-check.php Abschnitt D). Konditionen zeigt nur der Bestellprozess der Anwendung.'),
        $f('tarif.stichtag', 'tarif', intro_price_deadline_sentence(), 'technisch_getestet', ['php-ionos/app/pricing.php intro_price_deadline_sentence()', 'tools/pricing-check.php'], true, 'Rollierender Stichtag, nie ein festes Datum.'),
        $f('tarif.begrenzung', 'tarif', 'UNLIMITED START ohne Begrenzung der Einzüge je Periode und ohne Begrenzung der Benutzer', 'technisch_getestet', ['php-ionos/sql/schema.sql plans (max_collections_per_period NULL, unlimited_users 1)'], true),
        $f('tarif.kuendigung', 'tarif', 'Kündigung zum Ende der laufenden Abrechnungsperiode über die Abo-Seite (Stripe cancel_at_period_end), Rücknahme bis zum Periodenende möglich', 'technisch_getestet', ['php-ionos/app/billing.php billing_set_cancel_at_period_end()', 'docs/abrechnung.md'], true, 'Vertragliche Kündigungsregeln der AGB sind gesondert freizugeben.'),
        $f('recht.dokumente', 'recht', 'AGB (agb-2026-09), Datenschutzerklärung (datenschutz-2026-09) und Auftragsverarbeitungsvertrag nach Art. 28 DSGVO liegen versioniert vor; Veröffentlichung des AVV erst nach anwaltlicher Prüfung', 'ungeklaert', ['php-ionos/app/consent.php', 'php-ionos/app/legal.php', 'docs/rechtsdokumente.md'], false, 'Juristische Freigabe steht aus; nichts aus Code ableiten.', null),

        // --- Grenzen ---
        $f('grenzen.rueckschreibung', 'grenzen', 'Keine Rückschreibung von Zahlungen nach Lexware Office; der Zahlungsstatus wird aus Lexware Office gelesen und die Rechnung nach Zahlungseingang aus der Einzugsliste entfernt', 'technisch_getestet', ['php-ionos/app/sync.php recheck', 'php-ionos/app/integration_state.php INTEGRATION_SWITCHES writeback false'], true),
        $f('grenzen.kein_b2b', 'grenzen', 'Kein SEPA-Firmenlastschriftverfahren (B2B)', 'technisch_getestet', ['php-ionos/app/collections.php createPaymentIntent (sepa_debit ohne B2B-Kennzeichen)'], true),
        $f('grenzen.kein_wiso', 'grenzen', 'WISO MeinBüro ist nicht Bestandteil des Produkts', 'technisch_getestet', ['php-ionos/app/invoice_source_switch.php INVOICE_SOURCE_CODES'], false),

        // --- Fachliche Conversion-Ereignisse (intern, nicht veroeffentlichen) ---
        $f('conversion.ereignisse', 'conversion', ['lexware_connected', 'sevdesk_connected', 'stripe_connected', 'first_sync', 'first_collection', 'subscription_active'], 'technisch_getestet', ['php-ionos/app/funnel.php funnel_event_once()', 'php-ionos/settings.php', 'php-ionos/app/billing.php'], false, 'Je Firma und Ereignis genau einmal (funnel_event_once). subscription_active gilt erst mit aktivem, bezahltem Stripe-Abonnement; Einzüge der Firmen sind kein Umsatz von SmartEinzug.'),
    ];
}

/** Oeffentlicher Teil des Registers: ohne Quellen und Hinweise, nur freigegebene Zustaende. */
function product_facts_public(): array
{
    $out = [];
    foreach (product_facts() as $fact) {
        if (!$fact['veroeffentlichen'] || !in_array($fact['status'], PRODUCT_FACT_PUBLIC_STATES, true)) {
            continue;
        }
        $out[] = ['key' => $fact['key'], 'bereich' => $fact['bereich'], 'wert' => $fact['wert'], 'status' => $fact['status'], 'geprueft_am' => $fact['geprueft_am']];
    }
    return $out;
}

/**
 * Veroeffentlichungs-Snapshot (docs/contracts/product-facts.snapshot.json, Vertrag Abschnitt 3). Ohne Zeitstempel der
 * Erzeugung, damit der Vergleich im Pruefwerkzeug stabil bleibt; fakten.php ergaenzt generated_at und Laufzeitwerte.
 */
function product_facts_snapshot(): array
{
    return [
        'schema' => PRODUCT_FACTS_SCHEMA,
        'version' => PRODUCT_FACTS_VERSION,
        'app_version' => APP_VERSION,
        'geprueft_am' => PRODUCT_FACTS_CHECKED,
        'hinweis' => 'Freigegebene, nicht sensible Produktfakten. Status je Aussage: technisch_getestet, oeffentlich_behauptet oder geplant. Preise erscheinen erst nach Freigabe des Betreibers.',
        'facts' => product_facts_public(),
    ];
}

/** JSON-Darstellung mit stabiler Formatierung (UTF-8, ohne Escapes, Zeilenende am Schluss). */
function product_facts_snapshot_json(array $snapshot): string
{
    return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
