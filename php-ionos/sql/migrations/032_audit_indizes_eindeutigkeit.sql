-- 032: Indizes und Eindeutigkeit aus dem Audit vom 10.09.2026 (wiederholbar, rein additiv)
--
--  * payment_collections (tenant_id, stripe_payment_intent_id) UNIQUE: ein PaymentIntent, genau ein Einzugsdatensatz
--    (Befund A-05/D-04: Webhook und Klaerungsjob konnten denselben PaymentIntent gleichzeitig nachtragen). NULL-Werte
--    (terminierte, noch nicht eingereichte Einzuege) bleiben mehrfach erlaubt. Der Index wird NUR angelegt, wenn der
--    Bestand keine Dubletten enthaelt; sonst bleibt er aus, die Migration endet trotzdem erfolgreich, und die Anwendung
--    schuetzt den Nachtrag ueber die Zeilensperre je Rechnung (_attempt_backfill_collection). Dubletten vorher pruefen:
--      SELECT tenant_id, stripe_payment_intent_id, COUNT(*) FROM payment_collections
--       WHERE stripe_payment_intent_id IS NOT NULL GROUP BY 1, 2 HAVING COUNT(*) > 1;
--  * jobs (status, finished_at) und job_runs (status, finished_at): Statistik und Fehlerliste des Adminbereichs lesen
--    abgeschlossene Jobs nach Status und Zeitraum (Befund D-13).

ALTER TABLE jobs     ADD INDEX IF NOT EXISTS ix_jobs_status_finished (status, finished_at);
ALTER TABLE job_runs ADD INDEX IF NOT EXISTS ix_jobruns_status_finished (status, finished_at);

SET @dubletten := (
    SELECT COUNT(*) FROM (
        SELECT tenant_id, stripe_payment_intent_id FROM payment_collections
        WHERE stripe_payment_intent_id IS NOT NULL GROUP BY tenant_id, stripe_payment_intent_id HAVING COUNT(*) > 1
    ) d
);
SET @sql := IF(@dubletten = 0,
    'ALTER TABLE payment_collections ADD UNIQUE INDEX IF NOT EXISTS uq_collection_tenant_pi (tenant_id, stripe_payment_intent_id)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
