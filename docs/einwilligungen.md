# Einwilligungstexte (Archiv der Fassungen)

Zu jeder gespeicherten Einwilligung wird die Fassungskennung (`consent_text`) und der Zeitpunkt (`consent_at`) abgelegt.
Der zugehörige Wortlaut steht hier. Bei jeder Textänderung: neue Fassung anlegen, `INTEREST_CONSENT_VERSION` in
`php-ionos/app/interest.php` hochzählen, Seite und diese Datei am selben Tag ändern.

## agb-2026-09 und datenschutz-2026-09 (ab 4.34, Registrierung in der Kundenanwendung)

Bei der Registrierung bestätigt der Inhaber im Formular (`register.php`, Pflichtfeld): „Ich akzeptiere die AGB und habe die
Datenschutzerklärung zur Kenntnis genommen.“ Verlinkt sind die Seiten `/agb` und `/datenschutz` der Marketing-Website
(Fassungen dort, Pflege durch das Frontend). Seit 4.34 speichert die Anwendung dazu je Benutzer zwei Zeilen in
`consent_records` (Gegenstand `agb` beziehungsweise `datenschutz`, Fassung `AGB_VERSION`/`DATENSCHUTZ_VERSION` aus
`app/consent.php`, UTC-Zeitpunkt, Weg `registration`, Quellseite, E-Mail, keine IP). Anzeige: Firma unter Rechtliches
(„Zustimmungen zu AGB und Datenschutzerklärung“), Benutzer unter Sicherheit („Meine Zustimmungen“). Ändert das Frontend den
Text der AGB oder der Datenschutzerklärung, ist die jeweilige Konstante hochzuzählen und hier die neue Fassung mit Datum zu
vermerken. Fassung agb-2026-09: Stand der Website vom 07.09.2026 (Einführungspreis mit rollierendem Stichtag, AVV-Klarstellung).
Fassung datenschutz-2026-09: Stand vom 07.09.2026 (Abschnitt 3a Vormerkung, Müller Holding AG als Verantwortliche der Anwendung).

## vormerkung-v3 (ab 07.09.2026, smart-einzug.de/integrationen/sevdesk/, Zweck launch_info)

Ich möchte von der Müller Holding AG per E-Mail über den Entwicklungsstand und den Start der sevdesk-Anbindung von
SmartEinzug informiert werden, einschließlich einer möglichen Einladung zum Betatest. Ich kann meine Einwilligung
jederzeit über den Abmeldelink widerrufen. Hinweise zur Verarbeitung: Datenschutzerklärung (Abschnitt 3a).

## vormerkung-v2 (07.09.2026, nie produktiv)

Ich möchte per E-Mail informiert werden, sobald die sevdesk-Integration von SmartEinzug verfügbar ist oder sich der
geplante Starttermin wesentlich ändert. Die Vormerkung ist kostenlos und unverbindlich; ich kann sie jederzeit über den
Abmeldelink in jeder E-Mail beenden. Meine Angaben werden nur dafür sowie zur Auswertung, über welche unserer Seiten die
Vormerkung erfolgte, verwendet. Hinweise zur Verarbeitung: Datenschutzerklärung (Abschnitt 3a).

## vormerkung-v1 (07.09.2026, nie produktiv)

Ich möchte per E-Mail informiert werden, sobald die sevdesk-Integration von SmartEinzug verfügbar ist. Die Vormerkung ist
kostenlos und unverbindlich; ich kann sie jederzeit über den Link in der E-Mail beenden. Hinweise zur Verarbeitung:
Datenschutzerklärung.
