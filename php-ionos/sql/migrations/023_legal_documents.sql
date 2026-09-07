-- Migration 023: Rechtsdokumente mit Zustimmungsnachweis (Auftragsverarbeitungsvertrag nach Art. 28 DSGVO,
-- Verschwiegenheitsvereinbarung fuer Berufsgeheimnistraeger nach § 203 StGB, weitere Dokumente).
--  * legal_documents: versionierte Texte; nur Fassungen mit published_at werden Kunden angezeigt und koennen
--    akzeptiert werden. required_for: all = jede Firma, secrecy = nur Firmen mit Verschwiegenheitspflicht,
--    none = reine Information.
--  * legal_acceptances: Nachweis je Firma und Fassung (wer, wann, auf welchem Weg). Keine IP-Speicherung.
--  * organizations.professional_secrecy: Firma erklaert eine berufliche Verschwiegenheitspflicht (§ 203 StGB).
-- Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust.
CREATE TABLE IF NOT EXISTS legal_documents (
    id             CHAR(36)     NOT NULL PRIMARY KEY,
    code           VARCHAR(40)  NOT NULL,               -- avv | secrecy | ...
    version        VARCHAR(40)  NOT NULL,               -- z. B. 2026-09-a
    title          VARCHAR(200) NOT NULL,
    summary        VARCHAR(500) NULL,
    body_md        MEDIUMTEXT   NOT NULL,               -- eingeschraenktes Markdown (Ueberschriften, Absaetze, Listen)
    required_for   ENUM('all','secrecy','none') NOT NULL DEFAULT 'all',
    published_at   DATETIME     NULL,
    retired_at     DATETIME     NULL,
    created_by     VARCHAR(255) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_legal_code_version (code, version),
    KEY ix_legal_code_published (code, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_acceptances (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    organization_id CHAR(36)     NOT NULL,
    document_id     CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NULL,
    user_email      VARCHAR(255) NOT NULL,              -- Nachweis bleibt lesbar, auch wenn der Benutzer geloescht wird
    method          ENUM('registration','backend','admin') NOT NULL DEFAULT 'backend',
    accepted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_legal_acc_org_doc (organization_id, document_id),
    KEY ix_legal_acc_org (organization_id),
    KEY ix_legal_acc_doc (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS professional_secrecy      TINYINT(1)   NOT NULL DEFAULT 0 AFTER require_signed_mandate,
    ADD COLUMN IF NOT EXISTS professional_secrecy_kind VARCHAR(80)  NULL AFTER professional_secrecy;
