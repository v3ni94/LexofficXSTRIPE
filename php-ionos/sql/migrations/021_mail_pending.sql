-- Migration 021: Nachsenden von E-Mails, die bei nicht aktivem oder gestoertem Mailversand nicht erzeugt werden konnten.
--  * interest_registrations.mail_pending: Bestaetigungsmail der Vorregistrierung steht aus (Wartung sendet sie nach,
--    sobald mail.enabled gesetzt ist; Token werden dabei neu erzeugt).
--  * users.welcome_mail_pending: Willkommensmail mit Bestaetigungslink nach der Registrierung steht aus.
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
ALTER TABLE interest_registrations
    ADD COLUMN IF NOT EXISTS mail_pending       TINYINT(1) NOT NULL DEFAULT 0 AFTER mail_window_at,
    ADD COLUMN IF NOT EXISTS mail_pending_since DATETIME   NULL AFTER mail_pending;
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS welcome_mail_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at;
