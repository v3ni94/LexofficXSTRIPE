-- 034: Marketingmodul (Version 4.63, wiederholbar, rein additiv): Empfaengerlisten, Sperrliste, Kampagnen, Versandzeilen,
-- Ereignisse aus Amazon SES sowie die Ratenbegrenzung in platform_settings. Zeitpunkte in UTC (UTC_TIMESTAMP()).
-- Quelle der Empfaenger sind Importe und die Firmenaccounts (users, organization_members), nie die Kunden der Firmen.

CREATE TABLE IF NOT EXISTS marketing_lists (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    description   VARCHAR(500) NULL,
    source        VARCHAR(20)  NOT NULL DEFAULT 'import',   -- import | system
    system_scope  VARCHAR(40)  NULL,                         -- system: owners | owners_admins
    created_by    VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    updated_at    DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    UNIQUE KEY uq_marketing_list_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_recipients (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    list_id       CHAR(36)     NOT NULL,
    email         VARCHAR(255) NOT NULL,
    email_norm    VARCHAR(255) NOT NULL,                     -- kleingeschrieben, Vergleichsschluessel
    name          VARCHAR(200) NULL,
    company       VARCHAR(255) NULL,
    source        VARCHAR(20)  NOT NULL DEFAULT 'import',   -- import | system
    legal_basis   VARCHAR(30)  NOT NULL,                     -- bestandskunde | einwilligung | b2b_kontakt | sonstiges
    legal_note    VARCHAR(255) NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'active',    -- active | suppressed | removed
    created_at    DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    UNIQUE KEY uq_marketing_recipient (list_id, email_norm),
    KEY ix_marketing_recipient_email (email_norm),
    CONSTRAINT fk_marketing_recipient_list FOREIGN KEY (list_id) REFERENCES marketing_lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_suppressions (
    email_norm    VARCHAR(255) NOT NULL PRIMARY KEY,
    reason        VARCHAR(20)  NOT NULL,                     -- unsubscribe | complaint | bounce | manual
    note          VARCHAR(255) NULL,
    campaign_id   CHAR(36)     NULL,
    created_by    VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    subject        VARCHAR(200) NOT NULL,
    title          VARCHAR(200) NOT NULL,
    body_text      TEXT         NOT NULL,                    -- Absaetze durch Leerzeilen, Platzhalter {{name}} und {{firma}}
    button_label   VARCHAR(80)  NULL,
    button_url     VARCHAR(500) NULL,
    footer_note    VARCHAR(500) NULL,
    list_ids       TEXT         NOT NULL,                    -- JSON-Array der Listen
    status         VARCHAR(20)  NOT NULL DEFAULT 'draft',    -- draft | queued | sending | paused | sent | cancelled
    test_sent_at   DATETIME     NULL,
    test_sent_ref  VARCHAR(80)  NULL,
    started_by     VARCHAR(255) NULL,
    started_at     DATETIME     NULL,
    finished_at    DATETIME     NULL,
    total_count    INT          NOT NULL DEFAULT 0,
    sent_count     INT          NOT NULL DEFAULT 0,
    failed_count   INT          NOT NULL DEFAULT 0,
    skipped_count  INT          NOT NULL DEFAULT 0,
    created_by     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    updated_at     DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_sends (
    id                     CHAR(36)     NOT NULL PRIMARY KEY,
    campaign_id            CHAR(36)     NOT NULL,
    recipient_id           CHAR(36)     NULL,
    email                  VARCHAR(255) NOT NULL,
    email_norm             VARCHAR(255) NOT NULL,
    name                   VARCHAR(200) NULL,
    company                VARCHAR(255) NULL,
    status                 VARCHAR(20)  NOT NULL DEFAULT 'queued',   -- queued | sending | sent | failed | skipped
    skip_reason            VARCHAR(40)  NULL,                        -- suppressed | cancelled
    error                  VARCHAR(255) NULL,
    unsubscribe_token_hash CHAR(64)     NULL,
    claimed_at             DATETIME     NULL,
    sent_at                DATETIME     NULL,
    created_at             DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    UNIQUE KEY uq_marketing_send (campaign_id, email_norm),
    KEY ix_marketing_send_status (campaign_id, status),
    KEY ix_marketing_send_sent (sent_at),
    KEY ix_marketing_send_token (unsubscribe_token_hash),
    CONSTRAINT fk_marketing_send_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source         VARCHAR(20)  NOT NULL,                    -- ses | app
    event_type     VARCHAR(40)  NOT NULL,                    -- bounce | bounce_transient | complaint | delivery | test_send | unsubscribe | ...
    email_norm     VARCHAR(255) NULL,
    message_id     VARCHAR(120) NULL,
    details_json   TEXT         NULL,
    created_at     DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
    KEY ix_marketing_event_email (email_norm),
    KEY ix_marketing_event_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ratenbegrenzung des Werbeversands (Vorgabe: Werte der SES-Sandbox), im Adminbereich aenderbar
INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('marketing_rate_per_second', '1');
INSERT IGNORE INTO platform_settings (`key`, `value`) VALUES ('marketing_rate_per_day', '200');
