-- Migration 026: Indizes fuer die haeufigsten Zugriffe (Entscheidung nach Pruefung gegen das MariaDB-Kompendium, 07.09.2026).
--  * payment_collections (tenant_id, stripe_status): Listen und Zaehlungen je Firma und Status (collections.php, dashboard,
--    Wechselsperre des Buchhaltungssystems).
--  * invoices (tenant_id, lexoffice_status): offene und ueberfaellige Rechnungen je Firma (Dashboard, invoices.php, Sync-Recheck).
--  * audit_log (created_at): taegliche Bereinigung audit_cleanup() ohne Tabellenscan.
-- Bewusst NICHT umgesetzt: Wechsel des UUID-Primaerschluessels, CHECK-Constraints, zusaetzliche Indizes auf jobs (ix_jobs_pick
-- und ix_jobs_tenant vorhanden). Wiederholbar (IF NOT EXISTS), rein additiv, kein Datenverlust; Laufzeit bei kleinen Tabellen Sekunden.
ALTER TABLE payment_collections ADD INDEX IF NOT EXISTS ix_collection_tenant_status (tenant_id, stripe_status);
ALTER TABLE invoices            ADD INDEX IF NOT EXISTS ix_invoice_tenant_status (tenant_id, lexoffice_status);
ALTER TABLE audit_log           ADD INDEX IF NOT EXISTS ix_audit_created (created_at);
