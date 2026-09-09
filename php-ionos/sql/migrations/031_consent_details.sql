-- 031: Zustimmungsnachweis um ein Erläuterungsfeld ergänzen (wiederholbar)
--
-- Grund: Die Zustimmung zur zahlungspflichtigen Bestellung (subject 'bestellung', seit 4.53) braucht neben der
-- Fassung der AGB auch den vereinbarten Gegenstand: Tarif, Nettopreis und Periode. Die Spalte "version" ist mit
-- VARCHAR(60) dafür zu kurz; ein Abschneiden würde den Nachweis wertlos machen. Bis 4.52 stand die Zustimmung
-- ausschließlich im Protokoll (audit_log), das nach 90 Tagen gelöscht wird.
--
-- Additiv und wiederholbar: Die Spalte wird nur angelegt, wenn sie fehlt.

SET @has_details := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consent_records' AND COLUMN_NAME = 'details'
);
SET @sql := IF(@has_details = 0,
    'ALTER TABLE consent_records ADD COLUMN details VARCHAR(255) NULL AFTER version',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
