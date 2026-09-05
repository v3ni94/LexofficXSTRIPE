-- Migration 009: Bestehende Einzüge aus Stripe übernehmen (Einmal-Import)
--  * payment_collections: mandate_id und customer_iban_id dürfen leer sein
--    (importierte Einzüge aus einer früheren Installation haben kein lokales
--    Mandat), neue Spalten source ('app' | 'import') und
--    imported_mandate_reference (Mandatsreferenz aus den Stripe-Metadaten).
--  * Tabellen stripe_imports (Lauf je Firma, Zeitraum, Cursor, Status) und
--    stripe_import_items (jede gefundene Stripe-Zahlung mit Zuordnungsergebnis).
--
-- Einmalig über phpMyAdmin ausführen (nach 008). Alle Befehle sind wiederholbar.
-- Für Neuinstallationen ist alles bereits in sql/schema.sql enthalten.

ALTER TABLE payment_collections
    MODIFY mandate_id       CHAR(36) NULL,
    MODIFY customer_iban_id CHAR(36) NULL;

ALTER TABLE payment_collections
    ADD COLUMN IF NOT EXISTS source                     VARCHAR(20) NOT NULL DEFAULT 'app' AFTER description,
    ADD COLUMN IF NOT EXISTS imported_mandate_reference VARCHAR(35) NULL AFTER source;

CREATE TABLE IF NOT EXISTS stripe_imports (
    id                  CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id           CHAR(36)     NOT NULL,
    status              VARCHAR(20)  NOT NULL DEFAULT 'loading', -- loading | preview | done | discarded
    period_months       INT          NOT NULL DEFAULT 6,
    created_gte         DATETIME     NOT NULL,
    cursor_pi           VARCHAR(255) NULL,
    pages_fetched       INT          NOT NULL DEFAULT 0,
    fetched_count       INT          NOT NULL DEFAULT 0,
    imported_count      INT          NOT NULL DEFAULT 0,
    created_by_user_id  CHAR(36)     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at         DATETIME     NULL,
    last_error          TEXT         NULL,
    KEY ix_stripe_import_tenant (tenant_id, created_at),
    CONSTRAINT fk_stripe_import_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_import_items (
    id                   CHAR(36)     NOT NULL PRIMARY KEY,
    import_id            CHAR(36)     NOT NULL,
    tenant_id            CHAR(36)     NOT NULL,
    payment_intent_id    VARCHAR(255) NOT NULL,
    stripe_created_at    DATETIME     NOT NULL,
    amount_cents         INT          NOT NULL,
    currency             CHAR(3)      NOT NULL DEFAULT 'EUR',
    pi_status            VARCHAR(40)  NOT NULL,
    charge_id            VARCHAR(255) NULL,
    amount_refunded_cents INT         NOT NULL DEFAULT 0,
    disputed             TINYINT(1)   NOT NULL DEFAULT 0,
    failure_message      VARCHAR(255) NULL,
    voucher_number       VARCHAR(50)  NULL,
    customer_number      VARCHAR(50)  NULL,
    mandate_reference    VARCHAR(35)  NULL,
    description          VARCHAR(255) NULL,
    match_state          VARCHAR(30)  NOT NULL, -- matched | already_known | invoice_missing | amount_mismatch | invoice_has_collection | not_ours
    invoice_id           CHAR(36)     NULL,
    collection_id        CHAR(36)     NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_import_item (import_id, payment_intent_id),
    KEY ix_import_item_tenant (tenant_id, match_state),
    CONSTRAINT fk_import_item_import FOREIGN KEY (import_id) REFERENCES stripe_imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
