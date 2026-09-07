-- Migration 025: Zustimmungsnachweis fuer AGB und Datenschutzerklaerung bei der Registrierung (und weitere Einwilligungen).
-- Je Zustimmung eine Zeile mit Gegenstand, Fassung, Zeitpunkt (UTC), Weg, Benutzer und E-Mail (bleibt lesbar, auch wenn
-- der Benutzer geloescht wird); keine IP-Speicherung. Vertragsdokumente mit Volltext (AVV, Verschwiegenheit) bleiben in
-- legal_acceptances; die Vorregistrierung fuehrt ihre Einwilligung in interest_registrations. Wiederholbar, rein additiv.
CREATE TABLE IF NOT EXISTS consent_records (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    user_id         CHAR(36)     NULL,
    organization_id CHAR(36)     NULL,
    user_email      VARCHAR(255) NOT NULL,
    subject         VARCHAR(40)  NOT NULL,               -- agb | datenschutz | ...
    version         VARCHAR(60)  NOT NULL,               -- Fassung, archiviert in docs/einwilligungen.md
    method          VARCHAR(20)  NOT NULL DEFAULT 'registration', -- registration | backend | import
    source_url      VARCHAR(255) NULL,                   -- Seite, deren Text akzeptiert wurde
    accepted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_consent_user (user_id, accepted_at),
    KEY ix_consent_org (organization_id, accepted_at),
    KEY ix_consent_subject (subject, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
