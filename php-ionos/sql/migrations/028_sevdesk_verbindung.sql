-- Migration 028: sevdesk-Anbindung je Firma (Masterplan Phase 2, Version 4.38) und Ruecksetzen der Wechselsperre.
--  * integrations.sevdesk_*: verschluesselter API-Token, Verbindungsstatus, Kontoname, Pruef- und Trennzeitpunkte,
--    letzte Synchronisation; spiegelbildlich zu den lexoffice_*-Spalten.
--  * integrations.invoice_source_lock_reset_at: Zeitpunkt, an dem der Betreiber die Vier-Wochen-Sperre aufgehoben hat
--    (Anzeige in den Einstellungen der Firma; Audit invoice_source_lock_reset).
--  * integration_providers.sevdesk: Status development, Faehigkeiten und Hinweis auf den Verifikationsstand.
--  * platform_settings.sevdesk_release_at: Freigabetermin fuer sevdesk_connect (automatisch ab diesem Tag, Europe/Berlin);
--    ein ausdrueckliches sevdesk_connect = 0 oder 1 hat Vorrang. sevdesk_api_verified bleibt bewusst ungesetzt (kein Einzug).
-- Wiederholbar (IF NOT EXISTS, INSERT IGNORE), rein additiv, kein Datenverlust.
ALTER TABLE integrations
    ADD COLUMN IF NOT EXISTS sevdesk_api_key_encrypted    TEXT         NULL AFTER lexoffice_last_sync,
    ADD COLUMN IF NOT EXISTS sevdesk_connected            TINYINT(1)   NOT NULL DEFAULT 0 AFTER sevdesk_api_key_encrypted,
    ADD COLUMN IF NOT EXISTS sevdesk_company_name         VARCHAR(255) NULL AFTER sevdesk_connected,
    ADD COLUMN IF NOT EXISTS sevdesk_last_verified_at     DATETIME     NULL AFTER sevdesk_company_name,
    ADD COLUMN IF NOT EXISTS sevdesk_disconnected_at      DATETIME     NULL AFTER sevdesk_last_verified_at,
    ADD COLUMN IF NOT EXISTS sevdesk_last_sync            DATETIME     NULL AFTER sevdesk_disconnected_at,
    ADD COLUMN IF NOT EXISTS invoice_source_lock_reset_at DATETIME     NULL AFTER invoice_source_switches;

UPDATE integration_providers
   SET status = 'development',
       capabilities_json = '["read_customers","read_open_invoices","detect_changes"]',
       api_version = 'v1 (Systemversion 2.0)',
       notes = 'Adapter nach Sekundaerquellen gebaut (07.09.2026), nicht mit Testkonto verifiziert. Lesen ab Freigabe (sevdesk_connect oder sevdesk_release_at); offener Restbetrag und Einzug erst nach Bestaetigung (sevdesk_api_verified, sevdesk_collections). Kein Schreibzugriff.'
 WHERE code = 'sevdesk' AND status = 'planned';

INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('sevdesk_release_at', '2026-09-30');
