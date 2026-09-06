-- Migration 013: Schnellere Lexware-Office-Synchronisation
--  * invoices.lexoffice_updated_at: Änderungszeitpunkt laut Lexware-Voucherliste
--    (updatedDate). Unveränderte Rechnungen werden beim nächsten Lauf ohne
--    Detailabruf übersprungen (config sync.skip_unchanged).
--  * customers.lexoffice_synced_at: Zeitpunkt des letzten Kontaktabrufs. Kontakte
--    werden höchstens alle sync.contact_refresh_hours Stunden neu geladen.
--
-- Einmalig über phpMyAdmin ausführen (nach 012) oder automatisch über den Cron
-- bzw. migrate.php. Wiederholbar (IF NOT EXISTS). Für Neuinstallationen ist
-- alles bereits in sql/schema.sql enthalten.

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS lexoffice_updated_at DATETIME NULL AFTER lexoffice_status;

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS lexoffice_synced_at DATETIME NULL AFTER is_walk_in;
