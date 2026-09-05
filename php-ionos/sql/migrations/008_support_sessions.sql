-- Migration 008: Support-Zugriff des Plattformbetreibers auf Firmenaccounts
--  * Tabelle support_sessions: zeitlich begrenzter, protokollierter Zugriff eines
--    Superadmins auf eine Firma ("Auf Firma wechseln"). Der Einmal-Token wird nur
--    als SHA-256-Hash gespeichert, ist 5 Minuten einlösbar, die Sitzung endet
--    spätestens nach 60 Minuten. Im Support-Modus sind Einzüge, IBAN-Änderungen
--    und Zugangsdaten gesperrt; alle Aktionen tragen im audit_log den Vermerk
--    support_session.
--
-- Einmalig über phpMyAdmin ausführen (nach 007). Alle Befehle sind wiederholbar
-- (IF NOT EXISTS). Für Neuinstallationen ist alles bereits in sql/schema.sql enthalten.

CREATE TABLE IF NOT EXISTS support_sessions (
    id                  CHAR(36)     NOT NULL PRIMARY KEY,
    admin_user_id       CHAR(36)     NOT NULL,
    admin_email         VARCHAR(255) NOT NULL,
    organization_id     CHAR(36)     NOT NULL,
    reason              VARCHAR(255) NOT NULL,
    token_hash          CHAR(64)     NULL,
    redeem_expires_at   DATETIME     NOT NULL,
    redeemed_at         DATETIME     NULL,
    expires_at          DATETIME     NOT NULL,
    ended_at            DATETIME     NULL,
    ended_by            VARCHAR(20)  NULL,   -- admin|expired|revoked|logout
    ip                  VARCHAR(45)  NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_support_org (organization_id, created_at),
    KEY ix_support_token (token_hash),
    KEY ix_support_admin (admin_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
