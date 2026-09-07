-- Migration 020: Vormerkungen (Warteliste) für angekündigte Integrationen, zuerst sevdesk.
--  * Eine Zeile je Anbieter und E-Mail-Adresse. Double-Opt-in: pending bis der Bestätigungslink aus der
--    E-Mail eingelöst ist, danach confirmed; unsubscribed nach Abmeldung über den Link.
--  * Keine IP-Adressen, keine Nutzerkennungen: nur E-Mail, optional Firmenname, Herkunftsdomain und die
--    Fassung des Einwilligungstextes (consent_text), der zugestimmt wurde.
--  * token_hash ist der SHA-256 des Links (der Klartext steht nur in der E-Mail).
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
CREATE TABLE IF NOT EXISTS interest_registrations (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    provider_code    VARCHAR(32)  NOT NULL,
    email            VARCHAR(255) NOT NULL,
    company          VARCHAR(160) NULL,
    source_domain    VARCHAR(100) NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending | confirmed | unsubscribed
    consent_text     VARCHAR(80)  NOT NULL,
    token_hash       CHAR(64)     NULL,
    token_expires_at DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_mail_at     DATETIME     NULL,
    confirmed_at     DATETIME     NULL,
    unsubscribed_at  DATETIME     NULL,
    notified_at      DATETIME     NULL,
    UNIQUE KEY uq_interest_provider_email (provider_code, email),
    KEY ix_interest_status (provider_code, status, created_at),
    KEY ix_interest_token (token_hash),
    CONSTRAINT fk_interest_provider FOREIGN KEY (provider_code) REFERENCES integration_providers (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
