-- Migration 010: Benutzerprofil (Profilbild, Telefonnummern)
--  * users.avatar_path: Dateiname des Profilbilds unter app/storage/avatars/
--  * users.phone_private, users.phone_business: freiwillige Telefonnummern
--
-- Einmalig über phpMyAdmin ausführen (nach 009). Wiederholbar (IF NOT EXISTS).
-- Für Neuinstallationen ist alles bereits in sql/schema.sql enthalten.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS avatar_path    VARCHAR(255) NULL AFTER last_name,
    ADD COLUMN IF NOT EXISTS phone_private  VARCHAR(40)  NULL AFTER avatar_path,
    ADD COLUMN IF NOT EXISTS phone_business VARCHAR(40)  NULL AFTER phone_private;
