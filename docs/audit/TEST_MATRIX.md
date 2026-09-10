# Testmatrix Audit SmartEinzug (Stand 10.09.2026)

Alle Läufe: Prüfbranch `audit/2026-09-09-gesamtpruefung`, lokale Prüfumgebung (PHP 8.4.19, MariaDB 10.11.14, Redis 7.0.15,
Ubuntu 24.04), temporäre MariaDB je Suite (eigener Port, Datenbank `se_test`, Datenbankzeitzone bewusst UTC, PHP Europe/Berlin),
Stripe über lokalen Stub, Lexware über CLI-Testhaken, kein Mailversand, kein Netz. Kennzeichnung: automatisiert (Suite und
Abschnitt), statisch (Textprüfung im Quelltext), manuell, nicht geprüft, nicht anwendbar.

## 1. Baseline (Commit 66c59d5, unveränderte Suiten)

27 Suiten grün, `php -l` fehlerfrei. Protokolle im Prüfstand (`scratchpad/baseline/*.log`), Zusammenfassung:
payment-safety 69/0, totp-policy 76/0, billing-setup 83/0, host-separation 25/0, admin-period 44/0, docs-access 23/0,
mail-ci 46/0, pricing 14/0, app-tracking 53/0, healthcheck-redis 0 Fehler, compose 0 Fehler, staging-isolation 0 Fehler,
docs-build 0 Fehler, release-version 23/0, github-poll 25/0, github-ssh-retry 43/0, migrations 12/0, scheduler-sync 59/0,
worker-signal 17/0, sevdesk 129/0, interest 133/0, invoice-source 42/0, legal 66/0, platform-roles 88/0, deploy-runner 35/0,
redis-deploy 111/0.

## 2. Neue Suiten (Audit)

| Suite | Befehl | Inhalt | Vorher (66c59d5) | Nachher |
|---|---|---|---|---|
| Test-Schutz | `bash tools/test-guard-check.sh` | 18 synthetische Konfigurationen, jede Produktions- oder Live-Eigenschaft bricht vor jeder Nebenwirkung ab | neu | 18/0 |
| Geldfluss | `bash tools/collections-check.sh` (`COLLECTIONS_CHECK_SLOW=1` für Timeout und Not-Stopp) | 121 Fälle, echte `app/collections.php`, Stripe-Stub, echter Webhook-Endpunkt, echte parallele Prozesse | 85 bestanden, 36 fehlgeschlagen (Protokoll `collections-check-vorher.log`) | 121/0 (ohne SLOW); mit SLOW siehe Abschnitt 4 |
| Synchronisation | `bash tools/sync-check.sh` | Fake-Rechnungsquelle: Kontaktfehler, fehlender Kontakt, Nachprüfungsfehler, bezahlte Rechnung mit terminiertem Einzug, 401-Kategorie | 9 bestanden, 8 fehlgeschlagen (gegen alte sync.php/lexoffice.php) | 17/0 |
| Anmeldesicherheit | `bash tools/auth-check.sh` | statische Sicherungen C-01, C-03, C-05, C-06, C-09, C-10, D-02, D-08, B-03, B-05; TOTP-Wettlauf mit sechs Prozessen; Bereinigung login_attempts | 9 bestanden, 4 fehlgeschlagen (statische Fälle; der Wettlauf ließ sich mit Prozessen nicht reproduzieren, Fenster zu klein) | 13/0 |
| Plattformrollen | `bash tools/platform-roles-check.sh` | +9 Fälle C-01 (Zweitkonto, eigene Rolle, privilegierte Rollen) | 88/0 (alte Fälle), neue Fälle gegen alten Code nicht ausgeführt | 97/0 |

## 3. Szenarien 01 bis 28 des Auftrags

| Nr. | Szenario | Nachweis | Status |
|---|---|---|---|
| 01 | Vollständiger Ablauf Import, Mandat, Einzug, bestätigter Status | collections-check 2 (Sofort-Einzug), 14 (Webhook succeeded, Rechnung collected); sync-check 1 bis 4 (Import) | automatisiert (ohne Browser-Login; Login und 2FA statisch, Rolle C) |
| 02 | Zugriff zwischen zwei Firmen (IDs, Downloads, Exporte, Jobs) | collections-check 3 (fremde Rechnung, fremdes Mandat), 14a (fremdes Webhook-Ereignis); Rolle C statisch für Seiten, Downloads, Exporte | automatisiert (Geldfluss), statisch (Seiten) |
| 03 | Doppelklick, zwei gleichzeitige Aktionen auf dieselbe Rechnung | collections-check 4 (sechs echte Prozesse, genau ein PaymentIntent) | automatisiert |
| 04 | Mehrere Worker und überlappende Cronjobs bearbeiten denselben Einzug | collections-check 8 (drei parallele Fälligkeitsläufe, sechs Einzüge, sechs PaymentIntents); D-08 Cron-Sperre statisch | automatisiert |
| 05 | Dasselbe externe Rechnungskonto mehrfach angebunden | Befund B-04, nicht behoben (siehe AUDIT_REPORT, offen) | nicht geprüft (kein Test), Risiko dokumentiert |
| 06 | Rechnung teilbezahlt, storniert, gutgeschrieben, ausgeglichen | collections-check 5 (Teilzahlung, bezahlt), 12/12a (bereits eingezogen, Rest); sync-check 4 (bezahlt mit terminiertem Einzug) | automatisiert; Gutschrift nicht anwendbar (kein eigener Belegtyp im Code) |
| 07 | Forderungsstand ändert sich zwischen Synchronisation und Einreichung | collections-check 5, 12a (Live-Restbetrag bei Einreichung) | automatisiert |
| 08 | Fehlendes, widerrufenes oder fremdes Mandat | collections-check 3, 6 | automatisiert |
| 09 | Ungültige Beträge, Formate, Währungen, Grenzwerte | collections-check 5 (CHF, 0, Bestätigungsbetrag) | automatisiert (Beträge sind Cent-Integer aus DECIMAL, keine Browsereingabe) |
| 10 | Verbindungsabbruch vor oder nach Annahme durch Stripe | collections-check 7 (500 mit angelegtem PI), 7d (502 ohne JSON), 7e (Timeout, SLOW) | automatisiert |
| 11 | Datenbankfehler nach erfolgreichem externem Aufruf | Versuchsjournal auf eigener Verbindung; verwaister succeeded-Versuch blockiert und wird nachgetragen (collections-check 7a, Klärung); Absturz selbst nicht injiziert | teilweise automatisiert |
| 12 | Webhook vor API-Antwort bzw. lokaler Speicherung | collections-check 14c (junger Versuch: HTTP 500, Wiederholung) | automatisiert |
| 13 | Webhook mehrfach, verspätet, vertauscht | collections-check 14 (Duplikat, verspätetes processing) | automatisiert |
| 14 | Gefälschter Webhook, fremdes Konto | collections-check 14 (falsche Signatur), 14a (fremde Firma) | automatisiert |
| 15 | Zahlung in Verarbeitung, später Erfolg oder Fehler | collections-check 14 (processing, succeeded, payment_failed) | automatisiert |
| 16 | Rücklastschrift oder Erstattung nach Erfolg | collections-check 13, 14 (dispute) | automatisiert |
| 17 | Technischer Retry gegen fachlich neuen Versuch | collections-check 7, 7b, 7c (neuer Schlüssel nur nach geklärtem Fehlschlag) | automatisiert |
| 18 | Wiederaufnahme außerhalb des Idempotenz-Fensters | Klärung über metadata.attempt_key in Liste und Suche (7a, 7b); 24-Stunden-Fenster selbst nicht simulierbar | teilweise automatisiert, Rest dokumentiert |
| 19 | Rate-Limit, Timeout, ungültige Antwort, 5xx, ungültiger Zugang | collections-check 7, 7b, 7d, 7e, 11 (Circuit); sync-check 5 (401) | automatisiert |
| 20 | Große mehrseitige Synchronisation unterbrochen | scheduler-sync-check (Cursor, Fortsetzung), payment-safety D (Cursor vollständig); kein Lasttest mit Stub | teilweise automatisiert |
| 21 | API-Verbindung getrennt oder gewechselt bei wartenden Jobs | B-09/A-12 umgesetzt (settings.php), statisch geprüft, kein Funktionstest | statisch |
| 22 | Workerabsturz, Deadlock, abgelaufene Sperre | worker-signal-check (Stop-Signale, stale), collections-check 10 (submitting ohne Versuch) | automatisiert |
| 23 | Monatswechsel, Schaltjahr, Zeitumstellung, Einzugstage | collections-check 9 (Fenster, Zeitumstellung Sommer und Winter) | automatisiert |
| 24 | Migration auf bestehende Daten, Wiederaufnahme | migrations-check 12/0 einschließlich 032 (Vorzustand aus Git, Idempotenz, --retry) | automatisiert |
| 25 | Wiederhergestellter älterer Datenbestand | Verfahren in RELEASE_CHECKLIST.md; kein Test | manuell (Verfahren), nicht automatisiert |
| 26 | Benutzer sieht Fehler, Teilerfolg, Wartestatus verständlich | Rolle E statisch; E-01, E-02, E-04, E-05, E-07 umgesetzt; kein Browsertest | statisch |
| 27 | Test-Schutz verhindert externe Nebenwirkung | test-guard-check 18/0; collections-check 1 (Konfiguration mit externer Stripe-Adresse abgewiesen) | automatisiert |
| 28 | Kernfunktionen weiterhin | alle 27 vorhandenen Suiten nach den Änderungen (Abschnitt 4) | automatisiert |

## 4. Läufe nach den Korrekturen

Siehe Abschnitt „Endlauf“ in AUDIT_REPORT.md (wird nach dem letzten Lauf ergänzt).
