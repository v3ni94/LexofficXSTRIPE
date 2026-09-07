-- Migration 024: Wechsel des Buchhaltungssystems je Firma (Lexware Office <-> sevdesk) mit Sperrfrist.
--  * integrations.invoice_source_changed_at: Zeitpunkt des letzten Wechsels (UTC); vier Wochen Sperre bis zum naechsten.
--  * integrations.invoice_source_switches: Zaehler der Wechsel (Auswertung, Missbrauchserkennung).
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
ALTER TABLE integrations
    ADD COLUMN IF NOT EXISTS invoice_source_changed_at DATETIME NULL AFTER invoice_source,
    ADD COLUMN IF NOT EXISTS invoice_source_switches   INT      NOT NULL DEFAULT 0 AFTER invoice_source_changed_at;
