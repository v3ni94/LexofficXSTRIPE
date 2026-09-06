-- Migration 015: Multiaccount je Benutzer und zwischengespeicherte Registrierungsvorgänge
--  * users.multiaccount_enabled: manuell aktivierter Schalter "Multiaccount aktivieren".
--    Wirksam ist Multiaccount außerdem automatisch, sobald ein Benutzer mehreren aktiven
--    Firmen zugeordnet ist (wird zur Laufzeit aus organization_members ermittelt).
--  * registration_requests: Firmendaten einer Registrierung mit bereits bekannter E-Mail-Adresse,
--    kurzzeitig serverseitig aufbewahrt, bis sich der bestehende Benutzer angemeldet und die
--    Firmenanlage bewusst abgeschlossen hat. Enthält keine Passwörter und keine Geheimnisse.
--  * Bestehende Benutzer mit mehreren Firmen erhalten den Schalter automatisch.
--
-- Wird ausschließlich über den Migrationsendpunkt (deploy.yml) eingespielt. Wiederholbar.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS multiaccount_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_superadmin;

CREATE TABLE IF NOT EXISTS registration_requests (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    user_id        CHAR(36)     NOT NULL,
    org_name       VARCHAR(255) NOT NULL,
    mandate_prefix VARCHAR(10)  NOT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending | completed | expired | discarded
    created_org_id CHAR(36)     NULL,
    ip             VARCHAR(45)  NULL,
    expires_at     DATETIME     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at   DATETIME     NULL,
    KEY ix_regreq_user_status (user_id, status),
    KEY ix_regreq_expires (expires_at),
    CONSTRAINT fk_regreq_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE users u
   SET u.multiaccount_enabled = 1
 WHERE u.multiaccount_enabled = 0
   AND (SELECT COUNT(*) FROM organization_members m
          JOIN organizations o ON o.id = m.organization_id
         WHERE m.user_id = u.id AND m.status = 'active' AND o.deleted_at IS NULL) > 1;
