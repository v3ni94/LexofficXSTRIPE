-- Migration 029: Messpunkte fuer die Performance-Ueberarbeitung der Rechnungssynchronisation (Version 4.39).
--  * job_runs.queue_wait_ms: Wartezeit eines Warteschlangenjobs zwischen Faelligkeit (available_at) und Reservierung
--    (locked_at); bisher nicht erfasst (Bestandsaufnahme 07.09.2026, Luecke 1).
--  * sync_runs.detail_calls / contact_calls: Einzelabrufe je Lauf (bisher nur im Cursor, nicht dauerhaft).
--  * sync_runs.cursor_bytes_max: groesster gespeicherter Cursor (JSON) eines Laufs (Luecke 6).
--  * sync_runs.api_ms_max: laengster einzelner API-Aufruf des Laufs (Naeherung an eine Latenzverteilung, Luecke 2).
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
ALTER TABLE job_runs
    ADD COLUMN IF NOT EXISTS queue_wait_ms INT NULL AFTER skipped_starts;

ALTER TABLE sync_runs
    ADD COLUMN IF NOT EXISTS detail_calls     INT NOT NULL DEFAULT 0 AFTER api_calls,
    ADD COLUMN IF NOT EXISTS contact_calls    INT NOT NULL DEFAULT 0 AFTER detail_calls,
    ADD COLUMN IF NOT EXISTS api_ms_max       INT NOT NULL DEFAULT 0 AFTER throttle_ms,
    ADD COLUMN IF NOT EXISTS cursor_bytes_max INT NOT NULL DEFAULT 0 AFTER api_ms_max;
