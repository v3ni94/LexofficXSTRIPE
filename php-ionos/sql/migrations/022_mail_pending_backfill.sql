-- Migration 022: Nachtrag zu 021. Eintraege, die vor 4.22 bei nicht aktivem Mailversand entstanden sind, tragen noch
-- keine Wartemarke und wuerden nie nachgesendet. Wiederholbar (idempotent), rein Daten, kein Schemawechsel.
UPDATE interest_registrations
   SET mail_pending = 1, mail_pending_since = COALESCE(last_mail_at, created_at)
 WHERE status = 'pending' AND blocked_at IS NULL AND mail_pending = 0;
UPDATE users
   SET welcome_mail_pending = 1
 WHERE welcome_mail_pending = 0 AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY);
