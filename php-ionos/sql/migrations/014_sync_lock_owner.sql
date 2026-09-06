-- Migration 014: Synchronisierungssperre mit Lockinhaber, Zähler übersprungener Doppelstarts
--  * sync_state.lock_owner: zufällige Kennung des Prozesses, der den aktuellen Schritt hält.
--    Cursor-Updates gelten nur, wenn der Inhaber noch stimmt (kein Weiterschreiben nach
--    verlorener Sperre).
--  * sync_state.skipped_starts: übersprungene Doppelstarts (Nachvollziehbarkeit).
--  * sync_state.last_step_at: Zeitpunkt des letzten erfolgreich gespeicherten Schritts.
--
-- Wird ausschließlich über den Migrationsendpunkt (deploy.yml) eingespielt. Wiederholbar.

ALTER TABLE sync_state
    ADD COLUMN IF NOT EXISTS lock_owner     VARCHAR(64) NULL AFTER lock_until,
    ADD COLUMN IF NOT EXISTS skipped_starts INT         NOT NULL DEFAULT 0 AFTER lock_owner,
    ADD COLUMN IF NOT EXISTS last_step_at   DATETIME    NULL AFTER skipped_starts;
