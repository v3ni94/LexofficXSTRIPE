-- Lexware-Einzug (SEPA-Portal) – MariaDB Schema für Neuinstallationen (IONOS)
-- Import über phpMyAdmin im IONOS Kundenbereich. Zeichensatz: utf8mb4
--
-- Bestehende Installationen NICHT mit dieser Datei aktualisieren, sondern
-- die Dateien in sql/migrations/ in Reihenfolge ausführen.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
    stripe_price_id             VARCHAR(255) NULL,
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
-- Firmen (Mandanten)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organizations (
    id                              CHAR(36)     NOT NULL PRIMARY KEY,
    name                            VARCHAR(255) NOT NULL,
    mandate_prefix                  VARCHAR(10)  NOT NULL DEFAULT '', -- Präfix für SEPA-Mandatsreferenzen dieser Firma
    use_hvm_ci                      TINYINT(1)   NOT NULL DEFAULT 0,  -- nur für die Hausverwaltung Müller GmbH selbst
    onboarding_completed            TINYINT(1)   NOT NULL DEFAULT 0,
    onboarding_step                 INT          NOT NULL DEFAULT 0,
    plan_code                       VARCHAR(30)  NOT NULL DEFAULT 'unlimited_start',
    subscription_status             VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|active|past_due|canceled|exempt
    subscription_period_end         DATETIME     NULL,
    cancel_at_period_end            TINYINT(1)   NOT NULL DEFAULT 0,
    billing_exempt                  TINYINT(1)   NOT NULL DEFAULT 0,
    platform_stripe_customer_id     VARCHAR(255) NULL,
    platform_stripe_subscription_id VARCHAR(255) NULL,
    signup_domain                   VARCHAR(100) NULL,
    utm_source                      VARCHAR(100) NULL,
    utm_medium                      VARCHAR(100) NULL,
    utm_campaign                    VARCHAR(100) NULL,
    utm_content                     VARCHAR(100) NULL,
    referrer                        VARCHAR(500) NULL,
    street                          VARCHAR(255) NULL,
    zip                             VARCHAR(20)  NULL,
    city                            VARCHAR(100) NULL,
    country                         CHAR(2)      NOT NULL DEFAULT 'DE',
    creditor_identifier             VARCHAR(35)  NULL,  -- Gläubiger-Identifikationsnummer
    pre_notification_days           INT          NOT NULL DEFAULT 14,
    send_pre_notification           TINYINT(1)   NOT NULL DEFAULT 0,
    require_signed_mandate          TINYINT(1)   NOT NULL DEFAULT 1,
    deleted_at                      DATETIME     NULL,
    created_at                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_org_signup_domain (signup_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Benutzer (persönliche Zugänge, verpflichtende 2FA)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                         CHAR(36)     NOT NULL PRIMARY KEY,
    email                      VARCHAR(255) NOT NULL,
    password_hash              VARCHAR(255) NOT NULL,
    display_name               VARCHAR(255) NULL,
    first_name                 VARCHAR(100) NULL,
    last_name                  VARCHAR(100) NULL,
    is_active                  TINYINT(1)   NOT NULL DEFAULT 1,
    totp_secret_encrypted      TEXT         NULL,
    totp_enabled               TINYINT(1)   NOT NULL DEFAULT 0,
    totp_confirmed_at          DATETIME     NULL,
    totp_last_step             BIGINT       NULL,
    email_verified_at          DATETIME     NULL,
    email_verify_token_hash    CHAR(64)     NULL,
    email_verify_expires_at    DATETIME     NULL,
    password_reset_token_hash  CHAR(64)     NULL,
    password_reset_expires_at  DATETIME     NULL,
    is_superadmin              TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at              DATETIME     NULL,
    failed_login_count         INT          NOT NULL DEFAULT 0,
    locked_until               DATETIME     NULL,
    session_epoch              INT          NOT NULL DEFAULT 0,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id         CHAR(36) NOT NULL PRIMARY KEY,
    user_id    CHAR(36) NOT NULL,
    code_hash  CHAR(64) NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recovery_user_hash (user_id, code_hash),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Audit-Log: ohne Fremdschlüssel, damit Einträge erhalten bleiben. Wird nie gelöscht.
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

CREATE TABLE IF NOT EXISTS organization_members (
    id              CHAR(36)    NOT NULL PRIMARY KEY,
    organization_id CHAR(36)    NOT NULL,
    user_id         CHAR(36)    NOT NULL,
    role            VARCHAR(20) NOT NULL DEFAULT 'member', -- owner | admin | member
    status          VARCHAR(20) NOT NULL DEFAULT 'active', -- active | suspended
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_member_user (organization_id, user_id),
    KEY ix_member_user (user_id),
    CONSTRAINT fk_member_org  FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einladungen: token enthält den SHA-256-Hash des Links, nie den Klartext
CREATE TABLE IF NOT EXISTS invitations (
    id                 CHAR(36)     NOT NULL PRIMARY KEY,
    organization_id    CHAR(36)     NOT NULL,
    email              VARCHAR(255) NOT NULL,
    first_name         VARCHAR(100) NULL,
    last_name          VARCHAR(100) NULL,
    role               VARCHAR(20)  NOT NULL DEFAULT 'member',
    token              VARCHAR(64)  NOT NULL,
    invited_by_user_id CHAR(36)     NOT NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending | accepted | revoked | expired
    expires_at         DATETIME     NOT NULL,
    accepted_at        DATETIME     NULL,
    revoked_at         DATETIME     NULL,
    last_sent_at       DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invitation_org_email (organization_id, email),
    UNIQUE KEY uq_invitation_token (token),
    CONSTRAINT fk_invitation_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrations (
    id                              CHAR(36)   NOT NULL PRIMARY KEY,
    tenant_id                       CHAR(36)   NOT NULL,
    lexoffice_api_key_encrypted     TEXT       NULL,
    stripe_secret_key_encrypted     TEXT       NULL,
    stripe_webhook_secret_encrypted TEXT       NULL,
    lexoffice_connected             TINYINT(1) NOT NULL DEFAULT 0,
    stripe_connected                TINYINT(1) NOT NULL DEFAULT 0,
    lexoffice_last_sync             DATETIME   NULL,
    created_at                      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_integration_tenant (tenant_id),
    CONSTRAINT fk_integration_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
-- Fachdaten je Firma
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id                   CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id            CHAR(36)     NOT NULL,
    lexoffice_contact_id CHAR(36)     NULL,
    customer_number      VARCHAR(50)  NOT NULL,
    name                 VARCHAR(255) NOT NULL,
    email                VARCHAR(255) NULL,
    is_walk_in           TINYINT(1)   NOT NULL DEFAULT 0,
    sepa_debit_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_tenant_lexoffice (tenant_id, lexoffice_contact_id),
    KEY ix_customer_number (tenant_id, customer_number),
    CONSTRAINT fk_customer_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_ibans (
    id                  CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id           CHAR(36)     NOT NULL,
    customer_id         CHAR(36)     NOT NULL,
    iban                VARCHAR(34)  NOT NULL,
    bic                 VARCHAR(11)  NULL,
    account_holder_name VARCHAR(255) NOT NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_customer_iban_active (customer_id, is_active),
    KEY ix_iban_tenant (tenant_id),
    CONSTRAINT fk_iban_org      FOREIGN KEY (tenant_id)   REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_iban_customer FOREIGN KEY (customer_id) REFERENCES customers (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS iban_history (
    id               CHAR(36)    NOT NULL PRIMARY KEY,
    tenant_id        CHAR(36)    NOT NULL,
    customer_iban_id CHAR(36)    NOT NULL,
    action           VARCHAR(20) NOT NULL, -- created | deactivated | reactivated
    old_iban         VARCHAR(34) NULL,
    new_iban         VARCHAR(34) NULL,
    changed_by       CHAR(36)    NOT NULL,
    change_reason    TEXT        NULL,
    created_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_history_iban (customer_iban_id),
    KEY ix_history_tenant (tenant_id),
    CONSTRAINT fk_history_iban FOREIGN KEY (customer_iban_id) REFERENCES customer_ibans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEPA-Mandate: Referenz wird vom Portal vergeben, Dokument wird aus dem
-- Portal erzeugt, Unterschrift und Verfall (36 Monate ohne Nutzung) werden geführt.
CREATE TABLE IF NOT EXISTS sepa_mandates (
    id                       CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id                CHAR(36)     NOT NULL,
    customer_id              CHAR(36)     NOT NULL,
    customer_iban_id         CHAR(36)     NULL,
    mandate_reference        VARCHAR(35)  NOT NULL,
    mandate_date             DATE         NOT NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    status                   VARCHAR(20)  NOT NULL DEFAULT 'active', -- draft | active | cancelled | expired
    mandate_type             VARCHAR(10)  NOT NULL DEFAULT 'recurrent', -- recurrent | one_off
    signed_date              DATE         NULL,
    signed_place             VARCHAR(100) NULL,
    creditor_identifier      VARCHAR(35)  NULL,
    document_generated_at    DATETIME     NULL,
    document_generated_by    CHAR(36)     NULL,
    last_used_at             DATETIME     NULL,
    cancelled_at             DATETIME     NULL,
    cancel_reason            VARCHAR(255) NULL,
    stripe_payment_method_id VARCHAR(255) NULL,
    stripe_customer_id       VARCHAR(255) NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mandate_tenant_reference (tenant_id, mandate_reference),
    KEY ix_mandate_customer (customer_id),
    CONSTRAINT fk_mandate_org      FOREIGN KEY (tenant_id)        REFERENCES organizations (id)  ON DELETE CASCADE,
    CONSTRAINT fk_mandate_customer FOREIGN KEY (customer_id)      REFERENCES customers (id)      ON DELETE CASCADE,
    CONSTRAINT fk_mandate_iban     FOREIGN KEY (customer_iban_id) REFERENCES customer_ibans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id                   CHAR(36)      NOT NULL PRIMARY KEY,
    tenant_id            CHAR(36)      NOT NULL,
    lexoffice_invoice_id CHAR(36)      NOT NULL,
    voucher_number       VARCHAR(50)   NOT NULL,
    customer_id          CHAR(36)      NULL,
    contact_name         VARCHAR(255)  NOT NULL,
    total_gross_amount   DECIMAL(10,2) NOT NULL,
    currency             CHAR(3)       NOT NULL DEFAULT 'EUR',
    due_date             DATE          NULL,
    lexoffice_status     VARCHAR(50)   NOT NULL,
    collection_status    VARCHAR(20)   NOT NULL DEFAULT 'none', -- none|open|in_collection|collected|failed|scheduled
    line_items_json      MEDIUMTEXT    NULL,
    keyword              VARCHAR(100)  NULL,
    keyword_sepa         VARCHAR(100)  NULL,
    last_synced_at       DATETIME      NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_tenant_lexoffice (tenant_id, lexoffice_invoice_id),
    KEY ix_invoice_customer (customer_id),
    CONSTRAINT fk_invoice_org      FOREIGN KEY (tenant_id)   REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_collections (
    id                       CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id                CHAR(36)     NOT NULL,
    invoice_id               CHAR(36)     NOT NULL,
    mandate_id               CHAR(36)     NOT NULL,
    customer_iban_id         CHAR(36)     NOT NULL,
    amount_cents             INT          NOT NULL,
    currency                 CHAR(3)      NOT NULL DEFAULT 'EUR',
    stripe_payment_intent_id VARCHAR(255) NULL,
    stripe_status            VARCHAR(50)  NULL, -- scheduled|processing|succeeded|failed|disputed|cancelled
    submitted_at             DATETIME     NULL,
    completed_at             DATETIME     NULL,
    failure_reason           TEXT         NULL,
    description              VARCHAR(140) NULL,
    scheduled_date           DATE         NULL,
    is_scheduled             TINYINT(1)   NOT NULL DEFAULT 0,
    scheduled_submitted      TINYINT(1)   NOT NULL DEFAULT 0,
    created_by_user_id       CHAR(36)     NULL,
    prenotified_at           DATETIME     NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_collection_tenant (tenant_id),
    KEY ix_collection_pi (stripe_payment_intent_id),
    KEY ix_collection_scheduled (is_scheduled, scheduled_submitted, scheduled_date),
    CONSTRAINT fk_collection_org     FOREIGN KEY (tenant_id)        REFERENCES organizations (id)  ON DELETE CASCADE,
    CONSTRAINT fk_collection_invoice FOREIGN KEY (invoice_id)       REFERENCES invoices (id)       ON DELETE CASCADE,
    CONSTRAINT fk_collection_mandate FOREIGN KEY (mandate_id)       REFERENCES sepa_mandates (id),
    CONSTRAINT fk_collection_iban    FOREIGN KEY (customer_iban_id) REFERENCES customer_ibans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
