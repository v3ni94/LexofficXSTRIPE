-- Migration 018: Job-Queue, Worker-Heartbeats, Synchronisationshistorie, Circuit Breaker, Feature-Flags
-- (Auftrag III: Migration auf VPS mit Scheduler, Queue und Workern)
--  * jobs: zentrale Warteschlange (Auftrag). Jeder Verarbeitungsversuch eines Jobs wird zusätzlich
--    als Zeile in der vorhandenen Tabelle job_runs erfasst (job_key = Job-ID); keine Doppelstruktur.
--    dedupe_key ist nur für aktive Jobs gesetzt und eindeutig: derselbe fachliche Auftrag (z.B. sync:<firma>)
--    kann nicht zweimal gleichzeitig in der Warteschlange stehen.
--  * worker_heartbeats: laufende Worker und Scheduler mit letztem Heartbeat und aktuellem Job.
--  * sync_runs: dauerhafte Historie je Firma (Start, Ende, Dauer, Mengen, API-Zähler, Fehler); der laufende
--    Zustand bleibt in sync_state (Cursor, Sperre).
--  * api_circuits: Zustand des Circuit Breakers je externer Anbindung (geteilt über alle Worker).
--  * organizations.sync_paused: Wartungsmodus je Firma (nur Synchronisation pausieren).
--  * organizations.feature_flags: je Firma aktivierte Funktionen (JSON-Liste), z.B. ["queue"].
--  * Alle Zeitangaben der Job- und Heartbeat-Tabellen sind UTC (die Anwendung schreibt gmdate-Werte).
--
-- Wird ausschließlich über das vorhandene Migrationssystem eingespielt (migrate.php oder bin/migrate.php). Wiederholbar.

CREATE TABLE IF NOT EXISTS jobs (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id      CHAR(36)     NULL,
    user_id        CHAR(36)     NULL,
    type           VARCHAR(40)  NOT NULL,
    priority       TINYINT      NOT NULL DEFAULT 50,          -- 10 HIGH, 50 NORMAL, 90 LOW (kleiner = früher)
    payload        TEXT         NULL,                         -- JSON, keine Geheimnisse
    status         VARCHAR(20)  NOT NULL DEFAULT 'queued',    -- queued | processing | retry | completed | partially_completed | failed | cancelled
    progress       TINYINT UNSIGNED NULL,                     -- 0 bis 100, NULL = unbekannt
    progress_text  VARCHAR(160) NULL,
    available_at   DATETIME     NOT NULL,
    created_at     DATETIME     NOT NULL,
    started_at     DATETIME     NULL,
    finished_at    DATETIME     NULL,
    attempts       INT          NOT NULL DEFAULT 0,
    max_attempts   INT          NOT NULL DEFAULT 5,
    locked_by      VARCHAR(64)  NULL,
    locked_at      DATETIME     NULL,
    heartbeat_at   DATETIME     NULL,
    last_error     VARCHAR(255) NULL,                         -- bereinigt (Kategorie und Kurztext)
    result_json    TEXT         NULL,
    correlation_id CHAR(36)     NULL,
    dedupe_key     VARCHAR(120) NULL,                         -- nur für aktive Jobs gesetzt, eindeutig
    closed_at      DATETIME     NULL,                         -- fehlgeschlagener Job vom Admin dauerhaft geschlossen
    UNIQUE KEY uq_jobs_dedupe (dedupe_key),
    KEY ix_jobs_pick (status, type, available_at, priority),
    KEY ix_jobs_tenant (tenant_id, created_at),
    KEY ix_jobs_status_created (status, created_at),
    KEY ix_jobs_locked (locked_by, heartbeat_at),
    KEY ix_jobs_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
    pool           VARCHAR(30)  NOT NULL,                     -- lexware | stripe | mail | maintenance | all | scheduler
    hostname       VARCHAR(100) NULL,
    pid            INT          NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'idle',      -- idle | busy | stopping | stopped
    current_job_id CHAR(36)     NULL,
    started_at     DATETIME     NOT NULL,
    heartbeat_at   DATETIME     NOT NULL,
    jobs_done      INT          NOT NULL DEFAULT 0,
    jobs_failed    INT          NOT NULL DEFAULT 0,
    version        VARCHAR(40)  NULL,
    KEY ix_workers_pool (pool, heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id      CHAR(36)     NOT NULL,
    job_id         CHAR(36)     NULL,
    correlation_id CHAR(36)     NULL,
    triggered_by   VARCHAR(20)  NOT NULL DEFAULT 'manual',    -- manual | auto | full | admin | cron
    user_id        CHAR(36)     NULL,
    worker_id      VARCHAR(64)  NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'running',   -- running | success | partial | failed | cancelled
    started_at     DATETIME     NOT NULL,                     -- lokale Zeit wie sync_state (NOW())
    finished_at    DATETIME     NULL,
    duration_ms    INT          NULL,
    steps          INT          NOT NULL DEFAULT 0,
    checked        INT          NOT NULL DEFAULT 0,
    created        INT          NOT NULL DEFAULT 0,
    updated        INT          NOT NULL DEFAULT 0,
    removed        INT          NOT NULL DEFAULT 0,
    skipped        INT          NOT NULL DEFAULT 0,
    errors         INT          NOT NULL DEFAULT 0,
    retries        INT          NOT NULL DEFAULT 0,
    api_calls      INT          NOT NULL DEFAULT 0,
    api_ms         INT          NOT NULL DEFAULT 0,
    throttle_ms    INT          NOT NULL DEFAULT 0,
    error_category VARCHAR(60)  NULL,
    error_text     VARCHAR(500) NULL,                         -- bereinigt
    KEY ix_syncruns_tenant (tenant_id, started_at),
    CONSTRAINT fk_syncruns_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_circuits (
    api                   VARCHAR(30) NOT NULL PRIMARY KEY,   -- lexoffice | stripe | mail
    state                 VARCHAR(10) NOT NULL DEFAULT 'closed', -- closed | open | half_open
    failures              INT         NOT NULL DEFAULT 0,
    opened_at             DATETIME    NULL,
    next_probe_at         DATETIME    NULL,
    last_failure_category VARCHAR(60) NULL,
    last_failure_at       DATETIME    NULL,
    last_success_at       DATETIME    NULL,
    updated_at            DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS sync_paused        TINYINT(1)   NOT NULL DEFAULT 0 AFTER collections_paused,
    ADD COLUMN IF NOT EXISTS sync_paused_reason VARCHAR(160) NULL AFTER sync_paused,
    ADD COLUMN IF NOT EXISTS feature_flags      TEXT         NULL AFTER sync_paused_reason;
