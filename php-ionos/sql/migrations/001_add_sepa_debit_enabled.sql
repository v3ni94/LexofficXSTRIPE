-- Migration 001: SEPA-Einzug pro Kunde ein-/ausschaltbar machen.
--
-- Für bereits laufende Installationen über phpMyAdmin ausführen
-- (Reiter "SQL", diese Datei einfügen, ausführen). Neuinstallationen
-- bekommen die Spalte bereits über sql/schema.sql.
--
-- IF NOT EXISTS macht die Migration gefahrlos wiederholbar.

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS sepa_debit_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_walk_in;
