-- Migration 011: Karenzzeit und Einreichfenster für SEPA-Einzüge
--  * payment_collections.submit_not_before: frühester Einreichzeitpunkt (Karenzzeit,
--    Einreichfenster). Terminierte Einzüge ohne Wert werden wie bisher ab dem
--    Fälligkeitstag eingereicht (im Fenster).
--  * payment_collections.queued_immediate: 1 = Sofort-Einzug, der wegen der
--    Karenzzeit vorgemerkt wurde ("Vorgemerkt" statt "Terminiert").
--
-- Einmalig über phpMyAdmin ausführen (nach 010) oder automatisch über den Cron
-- bzw. migrate.php. Wiederholbar (IF NOT EXISTS). Für Neuinstallationen ist
-- alles bereits in sql/schema.sql enthalten.

ALTER TABLE payment_collections
    ADD COLUMN IF NOT EXISTS submit_not_before DATETIME   NULL AFTER scheduled_date,
    ADD COLUMN IF NOT EXISTS queued_immediate  TINYINT(1) NOT NULL DEFAULT 0 AFTER submit_not_before;
