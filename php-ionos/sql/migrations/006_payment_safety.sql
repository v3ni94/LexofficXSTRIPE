-- Migration 006: Zahlungsqualität und Adaptergrenze (Paket D und F)
--  * Restbetrag laut Lexware Office vor der Einreichung (invoices.open_amount)
--  * Versuchsjournal mit Idempotenz-Schlüssel für Stripe (collection_attempts)
--  * Not-Stopp je Firma und plattformweit (organizations.collections_paused,
--    platform_settings)
--  * Stripe-Mandatsdaten am SEPA-Mandat (stripe_mandate_id, stripe_mandate_reference)
--  * Digitale Mandatsanforderung (mandate_requests), Feature-Schalter in config.php
--  * Regelautomatik nur als Gerüst (collection_rules), keine Verarbeitung
--  * Registry der Integrationen (integration_providers), Rechnungsquelle je Firma
--
-- Einmalig über phpMyAdmin ausführen (nach 005). Alle Befehle sind wiederholbar
-- (IF NOT EXISTS, INSERT IGNORE). Für Neuinstallationen ist alles bereits in
-- sql/schema.sql enthalten.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Restbetrag laut Lexware Office (Payments-Endpunkt), Vermerk je Einzug
-- ---------------------------------------------------------------------------
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS open_amount            DECIMAL(10,2) NULL AFTER total_gross_amount,
    ADD COLUMN IF NOT EXISTS open_amount_fetched_at DATETIME      NULL AFTER open_amount;

ALTER TABLE payment_collections
    ADD COLUMN IF NOT EXISTS note             VARCHAR(255) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS stripe_charge_id VARCHAR(255) NULL AFTER stripe_payment_intent_id;

-- ---------------------------------------------------------------------------
-- Versuchsjournal: jeder Stripe-Aufruf wird VOR dem Aufruf mit seinem
-- Idempotenz-Schlüssel festgehalten (eigene Datenbankverbindung, damit der
-- Eintrag auch bei Abbruch der Einzugs-Transaktion erhalten bleibt).
-- status: pending (Aufruf läuft) | succeeded | failed (Stripe hat abgelehnt,
-- neuer Versuch erlaubt) | unknown (Zeitüberschreitung oder Netzwerkfehler,
-- Ergebnis unbekannt, kein neuer Versuch ohne Klärung).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collection_attempts (
    id                       CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id                CHAR(36)     NOT NULL,
    collection_id            CHAR(36)     NULL,
    invoice_id               CHAR(36)     NOT NULL,
    idempotency_key          CHAR(64)     NOT NULL,
    amount_cents             INT          NOT NULL,
    status                   VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending|succeeded|failed|unknown
    stripe_payment_intent_id VARCHAR(255) NULL,
    error_text               TEXT         NULL,
    created_by_user_id       CHAR(36)     NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attempt_key (idempotency_key),
    KEY ix_attempt_invoice (tenant_id, invoice_id, status),
    KEY ix_attempt_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Not-Stopp: je Firma (Inhaber/Admin über notstopp.php) und plattformweit
-- (nur per SQL, siehe docs/payment-safety.md)
-- ---------------------------------------------------------------------------
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS collections_paused    TINYINT(1) NOT NULL DEFAULT 0 AFTER require_signed_mandate,
    ADD COLUMN IF NOT EXISTS collections_paused_at DATETIME   NULL AFTER collections_paused;

CREATE TABLE IF NOT EXISTS platform_settings (
    `key`      VARCHAR(64)  NOT NULL PRIMARY KEY,
    `value`    VARCHAR(255) NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('collections_paused', '0');

-- ---------------------------------------------------------------------------
-- Stripe-Mandatsdaten am SEPA-Mandat (aus Charge bzw. SetupIntent)
-- ---------------------------------------------------------------------------
ALTER TABLE sepa_mandates
    ADD COLUMN IF NOT EXISTS stripe_mandate_id        VARCHAR(255) NULL AFTER stripe_customer_id,
    ADD COLUMN IF NOT EXISTS stripe_mandate_reference VARCHAR(64)  NULL AFTER stripe_mandate_id;

-- Herkunft einer Bankverbindung: manual (im Portal erfasst) | stripe_digital
-- (aus digitaler Mandatsanforderung; IBAN liegt nur maskiert vor)
ALTER TABLE customer_ibans
    ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER account_holder_name;

-- ---------------------------------------------------------------------------
-- Digitale Mandatsanforderung (Stripe Checkout mode=setup). Nur der Hash des
-- Links wird gespeichert. status: requested (Link versendet) | pending
-- (Checkout gestartet) | granted | unusable | revoked | expired
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mandate_requests (
    id                         CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id                  CHAR(36)     NOT NULL,
    customer_id                CHAR(36)     NOT NULL,
    token_hash                 CHAR(64)     NOT NULL,
    status                     VARCHAR(12)  NOT NULL DEFAULT 'requested',
    expires_at                 DATETIME     NOT NULL,
    stripe_checkout_session_id VARCHAR(255) NULL,
    stripe_setup_intent_id     VARCHAR(255) NULL,
    stripe_payment_method_id   VARCHAR(255) NULL,
    stripe_mandate_id          VARCHAR(255) NULL,
    mandate_id                 CHAR(36)     NULL,
    reminders_sent             TINYINT      NOT NULL DEFAULT 0,
    last_reminded_at           DATETIME     NULL,
    granted_at                 DATETIME     NULL,
    revoked_at                 DATETIME     NULL,
    created_by_user_id         CHAR(36)     NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mandate_request_token (token_hash),
    KEY ix_mandate_request_customer (tenant_id, customer_id, status),
    KEY ix_mandate_request_session (stripe_checkout_session_id),
    CONSTRAINT fk_mandate_request_org      FOREIGN KEY (tenant_id)   REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandate_request_customer FOREIGN KEY (customer_id) REFERENCES customers (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Regelautomatik: nur Gerüst. is_active bleibt 0, es gibt keine Verarbeitung,
-- nur eine Vorschau (collection_rules_preview) ohne Einreichung.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collection_rules (
    id                      CHAR(36)    NOT NULL PRIMARY KEY,
    tenant_id               CHAR(36)    NOT NULL,
    is_active               TINYINT(1)  NOT NULL DEFAULT 0,
    start_date              DATE        NULL,
    customer_scope          VARCHAR(10) NOT NULL DEFAULT 'selected', -- all | selected
    customer_ids_json       TEXT        NULL,
    max_amount_cents        INT         NULL,
    max_per_run             INT         NULL,
    require_second_approval TINYINT(1)  NOT NULL DEFAULT 1,
    created_by_user_id      CHAR(36)    NULL,
    created_at              DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_rule_tenant (tenant_id),
    CONSTRAINT fk_rule_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Registry der Integrationen (Rechnungssysteme, Zahlungsdienstleister)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integration_providers (
    code              VARCHAR(32)  NOT NULL PRIMARY KEY,
    name              VARCHAR(80)  NOT NULL,
    kind              VARCHAR(20)  NOT NULL, -- invoice_system | payment_provider
    status            VARCHAR(20)  NOT NULL DEFAULT 'planned', -- planned | development | closed_test | released
    capabilities_json TEXT         NULL,
    api_version       VARCHAR(20)  NULL,
    notes             VARCHAR(500) NULL,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO integration_providers (code, name, kind, status, capabilities_json, api_version, notes) VALUES
    ('lexware_office', 'Lexware Office', 'invoice_system', 'released',
     '["read_customers","read_open_invoices","read_open_amount","detect_changes"]', 'v1',
     'Public API (nach Angaben von Lexware Tarif XL erforderlich, im eigenen Konto prüfen). Kein Schreibzugriff auf Zahlungen.'),
    ('sevdesk', 'sevdesk', 'invoice_system', 'planned',
     '[]', 'v2',
     'In Planung. Voraussetzung laut Anbieter voraussichtlich Tarif Buchhaltung Pro, API v2. Ungeprüft, keine Freigabe, kein Angebot.'),
    ('stripe', 'Stripe', 'payment_provider', 'released',
     '["sepa_debit","payment_intents","setup_checkout","mandates","webhooks"]', '2024-06-20',
     'Eigenes Stripe-Konto des Kunden, SEPA-Lastschrift muss dort freigeschaltet sein.');

-- Eine aktive Rechnungsquelle je Firma (integrations bleibt die Verbindungs-Tabelle)
ALTER TABLE integrations
    ADD COLUMN IF NOT EXISTS invoice_source VARCHAR(32) NOT NULL DEFAULT 'lexware_office' AFTER tenant_id;
