-- Migration 004: Verbindungsprüfung und Kontoinformationen der Integrationen
-- Speichert je Firma die geprüften Stammdaten des Stripe-Kontos (Business Name,
-- Konto-ID, Modus test/live), den Zeitpunkt der letzten erfolgreichen Prüfung
-- beider Verbindungen sowie den Zeitpunkt einer Trennung. Historische Daten
-- (Rechnungen, Einzüge, Mandate) bleiben beim Trennen unverändert erhalten.
--
-- Einmalig über phpMyAdmin ausführen (nach 003). Wiederholbar (IF NOT EXISTS).

SET NAMES utf8mb4;

ALTER TABLE integrations
    ADD COLUMN IF NOT EXISTS stripe_account_id            VARCHAR(64)  NULL AFTER stripe_connected,
    ADD COLUMN IF NOT EXISTS stripe_business_name         VARCHAR(255) NULL AFTER stripe_account_id,
    ADD COLUMN IF NOT EXISTS stripe_mode                  VARCHAR(8)   NULL AFTER stripe_business_name, -- test | live
    ADD COLUMN IF NOT EXISTS stripe_last_verified_at      DATETIME     NULL AFTER stripe_mode,
    ADD COLUMN IF NOT EXISTS stripe_disconnected_at       DATETIME     NULL AFTER stripe_last_verified_at,
    ADD COLUMN IF NOT EXISTS lexoffice_company_name       VARCHAR(255) NULL AFTER lexoffice_connected,
    ADD COLUMN IF NOT EXISTS lexoffice_last_verified_at   DATETIME     NULL AFTER lexoffice_company_name,
    ADD COLUMN IF NOT EXISTS lexoffice_disconnected_at    DATETIME     NULL AFTER lexoffice_last_verified_at;
