-- Migration 027: Plattform-Benutzer und Rechte (Version 4.37).
--  * platform_roles: Rollen des Adminbereichs mit Berechtigungsliste (JSON-Array aus PLATFORM_PERMISSIONS,
--    '*' fuer Vollzugriff). Systemrollen admin, support, staff werden angelegt (is_system = 1, nicht loeschbar).
--  * users.platform_role: zugewiesene Rolle (NULL = kein Adminzugang). users.is_superadmin bleibt als Vollzugriff
--    bestehen und wird weiter gelesen; bestehende Superadmins erhalten zusaetzlich die Rolle admin.
-- Wiederholbar (IF NOT EXISTS, INSERT IGNORE), rein additiv, kein Datenverlust.
CREATE TABLE IF NOT EXISTS platform_roles (
    code         VARCHAR(32)  NOT NULL PRIMARY KEY,   -- z. B. admin, support, staff, eigene Rollen
    name         VARCHAR(100) NOT NULL,
    description  VARCHAR(255) NULL,
    permissions  TEXT         NOT NULL,               -- JSON-Array von Berechtigungscodes oder ["*"]
    is_system    TINYINT(1)   NOT NULL DEFAULT 0,     -- Systemrolle: nicht loeschbar, admin nicht editierbar
    created_at   DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    updated_at   DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO platform_roles (code, name, description, permissions, is_system) VALUES
    ('admin',   'Administrator',        'Vollzugriff auf alle Bereiche, entspricht dem bisherigen Superadmin.', '["*"]', 1),
    ('support', 'Mitarbeiter Support',  'Support-Anfragen, Firmenzugriff, Konten entsperren; Systemübersicht nur lesend.',
        '["admin.view","companies.view","support.view","support.tickets","support.sessions","support.users","monitoring.view","interest.view"]', 1),
    ('staff',   'Mitarbeiter',          'Lesender Zugriff auf Firmen, Vormerkungen und Systemübersicht.',
        '["admin.view","companies.view","monitoring.view","interest.view"]', 1);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS platform_role VARCHAR(32) NULL AFTER is_superadmin,
    ADD KEY IF NOT EXISTS ix_users_platform_role (platform_role);

UPDATE users SET platform_role = 'admin' WHERE is_superadmin = 1 AND platform_role IS NULL;
