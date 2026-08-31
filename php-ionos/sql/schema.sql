-- HVM SEPA-Portal – MariaDB Schema (IONOS)
-- Import über phpMyAdmin im IONOS Kundenbereich.
-- Zeichensatz: utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS organizations (
    id                    CHAR(36)     NOT NULL PRIMARY KEY,
    name                  VARCHAR(255) NOT NULL,
    onboarding_completed  TINYINT(1)   NOT NULL DEFAULT 0,
    onboarding_step       INT          NOT NULL DEFAULT 0,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(255) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_members (
    id              CHAR(36)    NOT NULL PRIMARY KEY,
    organization_id CHAR(36)    NOT NULL,
    user_id         CHAR(36)    NOT NULL,
    role            VARCHAR(20) NOT NULL DEFAULT 'member', -- owner | admin | member
    created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_member_user (organization_id, user_id),
    KEY ix_member_user (user_id),
    CONSTRAINT fk_member_org  FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
    id                 CHAR(36)     NOT NULL PRIMARY KEY,
    organization_id    CHAR(36)     NOT NULL,
    email              VARCHAR(255) NOT NULL,
    role               VARCHAR(20)  NOT NULL DEFAULT 'member',
    token              VARCHAR(64)  NOT NULL,
    invited_by_user_id CHAR(36)     NOT NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending | accepted | expired
    expires_at         DATETIME     NOT NULL,
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

CREATE TABLE IF NOT EXISTS sepa_mandates (
    id                       CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id                CHAR(36)     NOT NULL,
    customer_id              CHAR(36)     NOT NULL,
    customer_iban_id         CHAR(36)     NOT NULL,
    mandate_reference        VARCHAR(35)  NOT NULL,
    mandate_date             DATE         NOT NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
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
