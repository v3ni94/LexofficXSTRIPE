-- Migration 012: Hilfe-Center mit Support-Anfragen (Tickets)
--  * support_tickets: Anfrage einer Firma an den Betreiber (Betreff, Text, Seite,
--    Status offen | beantwortet | geschlossen)
--  * support_ticket_messages: Verlauf je Anfrage (Kunde und Support)
--
-- Einmalig über phpMyAdmin ausführen (nach 011) oder automatisch über den Cron
-- bzw. migrate.php. Wiederholbar (IF NOT EXISTS). Für Neuinstallationen ist
-- alles bereits in sql/schema.sql enthalten.

CREATE TABLE IF NOT EXISTS support_tickets (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NULL,
    user_email      VARCHAR(255) NOT NULL,
    subject         VARCHAR(160) NOT NULL,
    category        VARCHAR(40)  NOT NULL DEFAULT 'allgemein',
    page            VARCHAR(120) NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'open', -- open | answered | closed
    last_message_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at     DATETIME     NULL,
    closed_at       DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_ticket_tenant (tenant_id, created_at),
    KEY ix_ticket_status (status, last_message_at),
    CONSTRAINT fk_ticket_org FOREIGN KEY (tenant_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id           CHAR(36)     NOT NULL PRIMARY KEY,
    ticket_id    CHAR(36)     NOT NULL,
    tenant_id    CHAR(36)     NOT NULL,
    author_type  VARCHAR(10)  NOT NULL, -- customer | support
    author_email VARCHAR(255) NULL,
    body         TEXT         NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ticket_msg (ticket_id, created_at),
    CONSTRAINT fk_ticket_msg FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
