-- Migration 002: Mehrere Firmen pro Konto unterstützen.
--
-- Fügt organizations.mandate_prefix (Präfix für SEPA-Mandatsreferenzen,
-- bisher fest "HVM") und organizations.use_hvm_ci (steuert, ob das
-- HVM-Corporate-Design angezeigt wird) hinzu.
--
-- WICHTIG: Diesen zweiten Befehl nur EINMAL ausführen, direkt nach dem
-- ersten und BEVOR eine weitere Firma angelegt wird. Er setzt die bisher
-- einzige (bestehende) Organisation auf das Präfix "HVM" und aktiviert
-- das HVM-Design, damit bereits vergebene Mandatsreferenzen (z.B.
-- "HVM10045") unverändert gültig bleiben. Neu angelegte Firmen erhalten
-- ihr Präfix beim Anlegen selbst und bekommen KEIN HVM-Design.

ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS mandate_prefix VARCHAR(10) NOT NULL DEFAULT '' AFTER name;
ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS use_hvm_ci TINYINT(1) NOT NULL DEFAULT 0 AFTER mandate_prefix;

UPDATE organizations SET mandate_prefix = 'HVM', use_hvm_ci = 1 WHERE mandate_prefix = '';
