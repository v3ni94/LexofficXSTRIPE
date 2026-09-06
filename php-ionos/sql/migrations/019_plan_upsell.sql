-- Migration 019: Tarifwechsel und Upsell (Paket 4b)
--  * organizations.quota_warning_period_start: Beginn der Abrechnungsperiode, für die der Inhaber bereits
--    einen Hinweis auf ein zu 80 % ausgeschöpftes Einzugskontingent erhalten hat (eine E-Mail je Periode).
--  * organizations.plan_changed_at: Zeitpunkt des letzten Tarifwechsels durch den Inhaber (Anzeige, Audit
--    bleibt in audit_log).
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS quota_warning_period_start DATETIME NULL AFTER feature_flags,
    ADD COLUMN IF NOT EXISTS plan_changed_at            DATETIME NULL AFTER quota_warning_period_start;
