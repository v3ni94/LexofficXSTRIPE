-- Migration 017: Systemmonitoring (Adminbereich "System") und Statusseite
--  * job_runs: einzelne Ausführungsversuche der eigenen SmartEinzug-Jobs (Cron, Synchronisationsschritte,
--    Einzugsverarbeitung) mit Start, Heartbeat, Abschluss, Laufzeit, Mengen und API-Zählern.
--    job_key bündelt Versuche desselben fachlichen Auftrags.
--  * monitor_checks: Rohmessungen der Gesundheitsprüfungen und instrumentierten Ereignisse (14 Tage).
--  * monitor_daily: Tagesaggregate je Komponente für die Verfügbarkeitshistorie (400 Tage).
--  * monitor_requests: Minutenzähler instrumentierter PHP-Anfragen (30 Tage).
--  * monitor_incidents / monitor_incident_updates: Störungen und Wartungen mit getrennten
--    öffentlichen Texten und internen Notizen; werden nicht mit den Rohmessungen bereinigt.
--  * Alle Zeitangaben dieser Tabellen sind UTC (die Anwendung schreibt und liest gmdate-Werte).
--
-- Wird ausschließlich über den Migrationsendpunkt (deploy.yml) eingespielt. Wiederholbar.

CREATE TABLE IF NOT EXISTS job_runs (
    id                CHAR(36)     NOT NULL PRIMARY KEY,
    job_type          VARCHAR(30)  NOT NULL,             -- cron | sync | collections | monitor
    job_key           VARCHAR(120) NULL,                 -- fachlicher Auftrag (z.B. sync:<firma>:<start>)
    tenant_id         CHAR(36)     NULL,
    source            VARCHAR(20)  NOT NULL DEFAULT 'cron', -- cron | web | cli
    status            VARCHAR(12)  NOT NULL DEFAULT 'running', -- running | success | failed | unknown
    started_at        DATETIME     NOT NULL,
    heartbeat_at      DATETIME     NOT NULL,
    finished_at       DATETIME     NULL,
    duration_ms       INT          NULL,
    items_processed   INT          NOT NULL DEFAULT 0,
    api_calls         INT          NOT NULL DEFAULT 0,
    api_errors        INT          NOT NULL DEFAULT 0,
    throttle_ms       INT          NOT NULL DEFAULT 0,
    retries           INT          NOT NULL DEFAULT 0,
    skipped_starts    INT          NOT NULL DEFAULT 0,
    peak_memory_bytes INT UNSIGNED NULL,
    error_category    VARCHAR(60)  NULL,
    KEY ix_jobruns_type_started (job_type, started_at),
    KEY ix_jobruns_finished (finished_at),
    KEY ix_jobruns_status_heartbeat (status, heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_checks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    component     VARCHAR(40)  NOT NULL,
    source        VARCHAR(20)  NOT NULL DEFAULT 'internal', -- internal | instrumented | external
    checked_at    DATETIME     NOT NULL,
    status        VARCHAR(10)  NOT NULL,                    -- ok | degraded | fail | unknown
    latency_ms    INT          NULL,
    value_num     DECIMAL(14,2) NULL,
    unit          VARCHAR(12)  NULL,
    category      VARCHAR(60)  NULL,                        -- bereinigte Fehlerkategorie, keine Rohtexte
    valid_seconds INT          NOT NULL DEFAULT 300,        -- Gültigkeitsdauer der Messung für die Zeitgewichtung
    KEY ix_mon_component_time (component, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_daily (
    component  VARCHAR(40) NOT NULL,
    day        DATE        NOT NULL,
    t_ok       INT         NOT NULL DEFAULT 0,   -- Sekunden nutzbar (inkl. eingeschränkt)
    t_degraded INT         NOT NULL DEFAULT 0,   -- davon eingeschränkt
    t_fail     INT         NOT NULL DEFAULT 0,   -- Sekunden nicht nutzbar
    t_unknown  INT         NOT NULL DEFAULT 0,   -- Sekunden ohne gültigen Nachweis
    checks     INT         NOT NULL DEFAULT 0,
    fails      INT         NOT NULL DEFAULT 0,
    updated_at DATETIME    NOT NULL,
    PRIMARY KEY (component, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_requests (
    minute     DATETIME     NOT NULL PRIMARY KEY,           -- UTC, auf die Minute gekürzt
    requests   INT          NOT NULL DEFAULT 0,
    errors_5xx INT          NOT NULL DEFAULT 0,
    sum_ms     INT UNSIGNED NOT NULL DEFAULT 0,
    max_ms     INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_incidents (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    kind             VARCHAR(12)  NOT NULL DEFAULT 'incident', -- incident | maintenance
    title            VARCHAR(160) NOT NULL,
    status           VARCHAR(20)  NOT NULL,                    -- investigating | identified | monitoring | resolved | scheduled | active | completed
    components       TEXT         NULL,                        -- JSON-Liste öffentlicher Komponentenschlüssel
    started_at       DATETIME     NOT NULL,
    ended_at         DATETIME     NULL,
    scheduled_end_at DATETIME     NULL,
    public_message   TEXT         NULL,                        -- veröffentlichter Text (bereinigt)
    internal_notes   TEXT         NULL,                        -- nie veröffentlicht
    published        TINYINT(1)   NOT NULL DEFAULT 0,
    published_at     DATETIME     NULL,
    created_by       CHAR(36)     NULL,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    KEY ix_incidents_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitor_incident_updates (
    id            CHAR(36)    NOT NULL PRIMARY KEY,
    incident_id   CHAR(36)    NOT NULL,
    phase         VARCHAR(20) NOT NULL,
    public_text   TEXT        NULL,
    internal_note TEXT        NULL,
    created_by    CHAR(36)    NULL,
    created_at    DATETIME    NOT NULL,
    KEY ix_incupd_incident (incident_id, created_at),
    CONSTRAINT fk_incupd_incident FOREIGN KEY (incident_id) REFERENCES monitor_incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
