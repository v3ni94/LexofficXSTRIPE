-- Migration 007: Erstattungen, Klärungsbedarf je Rechnung, Alarmierung
--  * Erstattungen aus Stripe (charge.refunded, charge.refund.updated) am Einzug:
--    payment_collections.refunded_cents, refunded_at, refund_note
--  * Klärungsbedarf je Rechnung nach Erstattung (kein automatischer Neu-Einzug):
--    invoices.requires_review, review_reason
--  * Alarmierung per Cron: Merker alerts_sent_<tenant> in platform_settings
--    (Tabelle besteht seit Migration 006, keine Strukturänderung nötig)
--  * Vorabankündigungsfrist: Standardwert 14 Tage bestätigt (bereits seit
--    Migration 003 so gesetzt, Bestandsdaten werden nicht geändert)
--
-- Einmalig über phpMyAdmin ausführen (nach 006). Alle Befehle sind wiederholbar
-- (IF NOT EXISTS). Für Neuinstallationen ist alles bereits in sql/schema.sql enthalten.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Erstattungen am Einzug. stripe_status 'refunded' = vollständig erstattet;
-- bei Teilerstattung bleibt stripe_status 'succeeded' und refunded_cents > 0.
-- ---------------------------------------------------------------------------
ALTER TABLE payment_collections
    ADD COLUMN IF NOT EXISTS refunded_cents INT          NOT NULL DEFAULT 0 AFTER failure_reason,
    ADD COLUMN IF NOT EXISTS refunded_at    DATETIME     NULL AFTER refunded_cents,
    ADD COLUMN IF NOT EXISTS refund_note    VARCHAR(255) NULL AFTER refunded_at;

-- ---------------------------------------------------------------------------
-- Klärungsbedarf je Rechnung: requires_review = 1 blockiert jede Einreichung,
-- bis Inhaber oder Administrator die Klärung abschließt (invoices.php).
-- ---------------------------------------------------------------------------
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS requires_review TINYINT(1)   NOT NULL DEFAULT 0 AFTER collection_status,
    ADD COLUMN IF NOT EXISTS review_reason   VARCHAR(255) NULL AFTER requires_review;

-- Standardwert der Vorabankündigungsfrist (nur DEFAULT, keine Bestandsdaten)
ALTER TABLE organizations
    ALTER COLUMN pre_notification_days SET DEFAULT 14;
