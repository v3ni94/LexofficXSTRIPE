-- Migration 020: Vormerkungen (Warteliste) für angekündigte Integrationen, zuerst sevdesk.
--  * Eine Zeile je Anbieter und E-Mail-Adresse. Double-Opt-in: pending bis der Bestätigungslink aus der
--    E-Mail eingelöst ist, danach confirmed; unsubscribed nach Abmeldung über den Link.
--  * Keine IP-Adressen, keine Nutzerkennungen: nur E-Mail, optional Firmenname, Herkunftsdomain und die
--    Fassung des Einwilligungstextes (consent_text), der zugestimmt wurde.
--  * token_hash ist der SHA-256 des Links (der Klartext steht nur in der E-Mail).
--  * Alle Zeitstempel in UTC (UTC_TIMESTAMP()), Anzeige rechnet um.
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
CREATE TABLE IF NOT EXISTS interest_registrations (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    provider_code    VARCHAR(32)  NOT NULL,
    email            VARCHAR(255) NOT NULL,
    name             VARCHAR(120) NULL,
    company          VARCHAR(160) NULL,
    source_domain    VARCHAR(100) NULL,
    purpose          VARCHAR(40)  NOT NULL DEFAULT 'launch_info', -- Zweck der Einwilligung (Start- und Entwicklungsinformationen, Betaeinladung)
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending | confirmed | unsubscribed
    consent_text     VARCHAR(80)  NOT NULL,
    consent_at       DATETIME     NULL,           -- Zeitpunkt der letzten Einwilligung (Absenden des Formulars, UTC)
    mail_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- Bestaetigungsmails im laufenden 24-Stunden-Fenster
    mail_window_at   DATETIME     NULL,           -- Beginn dieses Fensters (UTC)
    token_hash       CHAR(64)     NULL,           -- Bestaetigungstoken (SHA-256), 7 Tage gueltig, nach Bestaetigung geloescht
    token_expires_at DATETIME     NULL,
    manage_token_hash CHAR(64)    NULL,           -- getrennter Token fuer Abmeldung und freiwillige Angaben (SHA-256)
    blocked_at       DATETIME     NULL,           -- Sperrvermerk: kein Versand mehr, Klartextangaben ausser E-Mail entfernt
    beta_interest    TINYINT(1)   NOT NULL DEFAULT 0, -- freiwillige Angaben nach Bestaetigung
    invoices_per_month VARCHAR(20) NULL,
    has_stripe       TINYINT(1)   NULL,
    has_api_access   TINYINT(1)   NULL,
    invited_at       DATETIME     NULL,           -- Betaeinladung vorgemerkt (nur bestaetigt und nicht gesperrt)
    activated_org_id CHAR(36)     NULL,           -- spaeterer Firmenbezug nach tatsaechlicher Aktivierung
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_mail_at     DATETIME     NULL,
    confirmed_at     DATETIME     NULL,
    unsubscribed_at  DATETIME     NULL,
    notified_at      DATETIME     NULL,
    UNIQUE KEY uq_interest_provider_email (provider_code, email),
    KEY ix_interest_status (provider_code, status, created_at),
    KEY ix_interest_token (token_hash),
    KEY ix_interest_manage (manage_token_hash),
    KEY ix_interest_created (created_at),
    CONSTRAINT fk_interest_provider FOREIGN KEY (provider_code) REFERENCES integration_providers (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
