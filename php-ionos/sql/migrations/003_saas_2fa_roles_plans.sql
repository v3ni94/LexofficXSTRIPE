-- Migration 003: SaaS-Ausbau (Ergänzung IV)
-- Verpflichtende 2FA, Rollenmodell Owner/Member, Sitzlimits, Tarife,
-- Audit-Log, Login-Protokoll, Herkunft der Registrierung, serverseitiger
-- Sync-Zustand, SEPA-Mandatsverwaltung mit Gläubiger-ID und Mandatsdokument.
--
-- Einmalig über phpMyAdmin auf der bestehenden Datenbank ausführen
-- (nach 001 und 002). Für Neuinstallationen ist alles bereits in
-- sql/schema.sql enthalten. Die ALTER-Befehle sind wiederholbar
-- (IF NOT EXISTS), die UPDATE-Befehle am Ende sind für Bestandsdaten
-- gedacht und sollten nur einmal laufen.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Benutzer: 2FA, E-Mail-Verifizierung, Sperre, Superadmin, Session-Widerruf
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name                 VARCHAR(100) NULL AFTER display_name,
    ADD COLUMN IF NOT EXISTS last_name                  VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS totp_secret_encrypted      TEXT         NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled               TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS totp_confirmed_at          DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS totp_last_step             BIGINT       NULL,
    ADD COLUMN IF NOT EXISTS email_verified_at          DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS email_verify_token_hash    CHAR(64)     NULL,
    ADD COLUMN IF NOT EXISTS email_verify_expires_at    DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS password_reset_token_hash  CHAR(64)     NULL,
    ADD COLUMN IF NOT EXISTS password_reset_expires_at  DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS is_superadmin              TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_login_at              DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS failed_login_count         INT          NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS locked_until               DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS session_epoch              INT          NOT NULL DEFAULT 0;

-- Recovery-Codes (nur als HMAC-Hash gespeichert, jeder Code einmal verwendbar)
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id         CHAR(36) NOT NULL PRIMARY KEY,
    user_id    CHAR(36) NOT NULL,
    code_hash  CHAR(64) NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recovery_user_hash (user_id, code_hash),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login-Versuche (Rate-Limiting und Nachvollziehbarkeit)
CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    ip         VARCHAR(45)  NULL,
    success    TINYINT(1)   NOT NULL DEFAULT 0,
    stage      VARCHAR(20)  NOT NULL DEFAULT 'password', -- password | totp | recovery
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_email_time (email, created_at),
    KEY ix_login_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit-Log: bewusst OHNE Fremdschlüssel, damit Einträge erhalten bleiben,
-- wenn Benutzer oder Firmen gelöscht werden. Wird nie gelöscht.
CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id    CHAR(36)     NULL,
    user_id      CHAR(36)     NULL,
    user_email   VARCHAR(255) NULL,
    action       VARCHAR(60)  NOT NULL,
    target_type  VARCHAR(40)  NULL,
    target_id    VARCHAR(64)  NULL,
    details_json TEXT         NULL,
    ip           VARCHAR(45)  NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_audit_tenant_time (tenant_id, created_at),
    KEY ix_audit_user_time (user_id, created_at),
    KEY ix_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tarife (Limits kommen ausschließlich aus dieser Tabelle)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    code                        VARCHAR(30)  NOT NULL PRIMARY KEY,
    name                        VARCHAR(60)  NOT NULL,
    price_cents                 INT          NOT NULL,
    period_days                 INT          NOT NULL DEFAULT 28,
    max_collections_per_period  INT          NULL,      -- NULL = unbegrenzt
    max_users                   INT          NULL,      -- NULL = unbegrenzt
    unlimited_users             TINYINT(1)   NOT NULL DEFAULT 0,
    user_invites_enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    active                      TINYINT(1)   NOT NULL DEFAULT 0,
    public_visible              TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order                  INT          NOT NULL DEFAULT 0,
    stripe_price_id             VARCHAR(255) NULL,      -- Preis-ID im Plattform-Stripe-Konto
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO plans
    (code, name, price_cents, period_days, max_collections_per_period, max_users, unlimited_users, user_invites_enabled, active, public_visible, sort_order)
VALUES
    ('unlimited_start', 'UNLIMITED START', 2500, 28, NULL, NULL, 1, 1, 1, 1, 10),
    ('basic',           'BASIC',           2000, 28, 20,   1,    0, 0, 0, 0, 20),
    ('plus',            'PLUS',            3500, 28, 50,   2,    0, 1, 0, 0, 30),
    ('pro',             'PRO',             5000, 28, 100,  NULL, 1, 1, 0, 0, 40),
    ('unlimited',       'UNLIMITED',      10000, 28, NULL, NULL, 1, 1, 0, 0, 50);

-- ---------------------------------------------------------------------------
-- Firmen: Tarif, Abo-Status, Herkunft, Anschrift, SEPA-Gläubigerdaten
-- ---------------------------------------------------------------------------
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS plan_code                       VARCHAR(30)  NOT NULL DEFAULT 'unlimited_start',
    ADD COLUMN IF NOT EXISTS subscription_status             VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|active|past_due|canceled|exempt
    ADD COLUMN IF NOT EXISTS subscription_period_end         DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS cancel_at_period_end            TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS billing_exempt                  TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS platform_stripe_customer_id     VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS platform_stripe_subscription_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS signup_domain                   VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS utm_source                      VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS utm_medium                      VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS utm_campaign                    VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS utm_content                     VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS referrer                        VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS street                          VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS zip                             VARCHAR(20)  NULL,
    ADD COLUMN IF NOT EXISTS city                            VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS country                         CHAR(2)      NOT NULL DEFAULT 'DE',
    ADD COLUMN IF NOT EXISTS creditor_identifier             VARCHAR(35)  NULL,  -- Gläubiger-Identifikationsnummer
    ADD COLUMN IF NOT EXISTS pre_notification_days           INT          NOT NULL DEFAULT 14,
    ADD COLUMN IF NOT EXISTS send_pre_notification           TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS require_signed_mandate          TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS deleted_at                      DATETIME     NULL;

ALTER TABLE organizations ADD INDEX IF NOT EXISTS ix_org_signup_domain (signup_domain);

-- Mitgliedschaften: Sperrstatus
ALTER TABLE organization_members
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active'; -- active | suspended

-- Einladungen: Name, Lebenszyklus (token enthält ab jetzt den SHA-256-Hash)
ALTER TABLE invitations
    ADD COLUMN IF NOT EXISTS first_name   VARCHAR(100) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS last_name    VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS accepted_at  DATETIME NULL,
    ADD COLUMN IF NOT EXISTS revoked_at   DATETIME NULL,
    ADD COLUMN IF NOT EXISTS last_sent_at DATETIME NULL;

-- Einzüge: wer hat ausgelöst, Vorabankündigung
ALTER TABLE payment_collections
    ADD COLUMN IF NOT EXISTS created_by_user_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS prenotified_at     DATETIME NULL;

-- Serverseitiger Synchronisationszustand (Browser und Cron setzen denselben Lauf fort)
CREATE TABLE IF NOT EXISTS sync_state (
    tenant_id            CHAR(36)    NOT NULL PRIMARY KEY,
    status               VARCHAR(20) NOT NULL DEFAULT 'idle', -- idle | running | done | error
    cursor_json          MEDIUMTEXT  NULL,
    requested_by_user_id CHAR(36)    NULL,
    lock_until           DATETIME    NULL,
    started_at           DATETIME    NULL,
    updated_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at          DATETIME    NULL,
    last_error           TEXT        NULL,
    result_json          TEXT        NULL,
    CONSTRAINT fk_sync_state_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verarbeitete Webhook-Ereignisse (Idempotenz und Reihenfolgeschutz)
CREATE TABLE IF NOT EXISTS webhook_events (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    source        VARCHAR(20)  NOT NULL, -- billing | tenant
    event_type    VARCHAR(60)  NOT NULL,
    object_id     VARCHAR(255) NULL,
    event_created BIGINT       NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_webhook_object (source, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Funnel-Ereignisse je Herkunftsdomain (cookielos, ohne IP-Adresse)
CREATE TABLE IF NOT EXISTS funnel_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    domain     VARCHAR(100) NOT NULL,
    event      VARCHAR(40)  NOT NULL,
    path       VARCHAR(255) NULL,
    tenant_id  CHAR(36)     NULL,
    user_id    CHAR(36)     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_funnel_domain_event (domain, event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SEPA-Mandate: Dokument, Unterschrift, Status, Verfall
-- ---------------------------------------------------------------------------
ALTER TABLE sepa_mandates MODIFY customer_iban_id CHAR(36) NULL;
ALTER TABLE sepa_mandates
    ADD COLUMN IF NOT EXISTS status                VARCHAR(20)  NOT NULL DEFAULT 'active', -- draft | active | cancelled | expired
    ADD COLUMN IF NOT EXISTS mandate_type          VARCHAR(10)  NOT NULL DEFAULT 'recurrent', -- recurrent | one_off
    ADD COLUMN IF NOT EXISTS signed_date           DATE         NULL,
    ADD COLUMN IF NOT EXISTS signed_place          VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS creditor_identifier   VARCHAR(35)  NULL,
    ADD COLUMN IF NOT EXISTS document_generated_at DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS document_generated_by CHAR(36)     NULL,
    ADD COLUMN IF NOT EXISTS last_used_at          DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS cancelled_at          DATETIME     NULL,
    ADD COLUMN IF NOT EXISTS cancel_reason         VARCHAR(255) NULL;

-- ---------------------------------------------------------------------------
-- Bestandsdaten (nur einmal ausführen)
-- ---------------------------------------------------------------------------
-- Bestehende Benutzer gelten als E-Mail-verifiziert (sie haben sich bereits angemeldet).
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

-- Bestehende Firmen sind vom Plattform-Abo befreit (Eigen-Nutzung vor Marktstart)
-- und behalten unbegrenzte Benutzer (Grandfathering über den Tarif unlimited_start).
UPDATE organizations SET subscription_status = 'exempt', billing_exempt = 1
 WHERE subscription_status = 'pending' AND billing_exempt = 0;

-- Bestehende Firmen: bisheriger Ablauf ohne erfasste Unterschrift bleibt möglich
UPDATE organizations SET require_signed_mandate = 0 WHERE created_at < NOW();

-- Offene Einladungslinks: Token ab jetzt gehasht speichern (bestehende Links bleiben gültig)
UPDATE invitations SET token = SHA2(token, 256) WHERE status = 'pending' AND accepted_at IS NULL AND revoked_at IS NULL;

-- Letzte Nutzung bestehender Mandate aus den Einzügen ableiten (36-Monats-Regel)
UPDATE sepa_mandates m
  JOIN (SELECT mandate_id, MAX(COALESCE(submitted_at, created_at)) AS last_use
          FROM payment_collections GROUP BY mandate_id) x ON x.mandate_id = m.id
   SET m.last_used_at = x.last_use
 WHERE m.last_used_at IS NULL;
UPDATE sepa_mandates SET status = IF(is_active = 1, 'active', 'cancelled') WHERE status = 'active' AND is_active = 0;
