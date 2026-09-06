-- Migration 016: Gerätefreigaben für die Zwei-Faktor-Authentifizierung ("Gerät für 90 Tage merken")
--  * Je Freigabe ein Datensatz mit Benutzer, Anwendungsbereich (app | admin), Hash des geheimen
--    Tokenteils (kein Klartext), Freigabezeitpunkt, festem Ablauf (Freigabe + 90 Tage, UTC),
--    letzter Verwendung, Rotation und Widerruf.
--  * Zeitangaben dieser Tabelle werden von der Anwendung in UTC geschrieben und gelesen.
--  * Bestehende Sitzungen werden nicht in Freigaben umgewandelt; die erste Freigabe setzt eine
--    echte Authenticator-Bestätigung und die bewusste Auswahl der Checkbox voraus.
--
-- Wird ausschließlich über den Migrationsendpunkt (deploy.yml) eingespielt. Wiederholbar.

CREATE TABLE IF NOT EXISTS trusted_devices (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    user_id        CHAR(36)     NOT NULL,
    scope          VARCHAR(20)  NOT NULL DEFAULT 'app',   -- app | admin
    token_hash     CHAR(64)     NOT NULL,                 -- HMAC-SHA256 des geheimen Tokenteils
    label          VARCHAR(120) NULL,                     -- grobe Browser-/Systembezeichnung
    created_at     DATETIME     NOT NULL,                 -- Freigabezeitpunkt (UTC)
    expires_at     DATETIME     NOT NULL,                 -- created_at + 90 Tage (UTC), wird nie verlängert
    last_used_at   DATETIME     NULL,                     -- UTC
    rotated_at     DATETIME     NULL,                     -- UTC
    revoked_at     DATETIME     NULL,                     -- UTC
    revoked_reason VARCHAR(40)  NULL,
    ip_created     VARCHAR(45)  NULL,
    UNIQUE KEY uq_trusted_token (token_hash),
    KEY ix_trusted_user (user_id, revoked_at, expires_at),
    CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
