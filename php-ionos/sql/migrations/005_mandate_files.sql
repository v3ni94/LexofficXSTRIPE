-- Migration 005: Hochgeladene Mandatsdokumente (PDF, JPG, PNG) je Kunde
-- Ein Kunde kann mehrere Dateien haben (z. B. Scan und Foto). Optional ist die
-- Datei einem konkreten Mandat zugeordnet. Die Dateien selbst liegen außerhalb
-- des Webzugriffs unter app/storage/mandates/<tenant_id>/ mit zufälligem Namen;
-- der Download läuft ausschließlich über mandate-file.php mit Mandantenprüfung.
--
-- Einmalig über phpMyAdmin ausführen (nach 004).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mandate_files (
    id                  CHAR(36)     NOT NULL PRIMARY KEY,
    tenant_id           CHAR(36)     NOT NULL,
    customer_id         CHAR(36)     NOT NULL,
    mandate_id          CHAR(36)     NULL,
    original_name       VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    size_bytes          INT UNSIGNED NOT NULL,
    sha256              CHAR(64)     NOT NULL,
    stored_name         VARCHAR(64)  NOT NULL,
    note                VARCHAR(255) NULL,
    uploaded_by_user_id CHAR(36)     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mandate_files_customer (tenant_id, customer_id),
    KEY idx_mandate_files_mandate (mandate_id),
    CONSTRAINT fk_mandate_file_org      FOREIGN KEY (tenant_id)   REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandate_file_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandate_file_mandate  FOREIGN KEY (mandate_id)  REFERENCES sepa_mandates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
