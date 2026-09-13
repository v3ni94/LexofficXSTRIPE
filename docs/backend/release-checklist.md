# Release-Checkliste Backend 4.79 (Masterprompt, 13.09.2026)

Gilt für den Arbeitsbranch `backend/masterprompt-2026-09-13`. Kein Produktionsdeploy aus diesem Auftrag; Ausrollen erst
nach Zusammenführung mit dem Frontend-Stand, Staging-Abnahme und Freigabe des Betreibers. Die allgemeine Checkliste des
Gesamtaudits bleibt gültig: `docs/audit/RELEASE_CHECKLIST.md`.

## 1. Tests (lokal, 13.09.2026, PHP 8.4, temporäre MariaDB, Stripe-Stub)

| Prüfung | Befehl | Ergebnis |
|---|---|---|
| Geldfluss mit Verbindungszustand | `bash tools/collections-check.sh` | 201 bestanden, 0 fehlgeschlagen |
| Migrationen 001 bis 035 | `bash tools/migrations-check.sh` | 12/0 |
| Produktfakten | `php tools/product-facts-check.php` | 33/0 |
| Sicherungen Geldfluss | `php tools/payment-safety-check.php` | 74/0 |
| 2FA-Geltungsbereich | `php tools/totp-policy-check.php` | 87/0 |
| Hosttrennung | `php tools/host-separation-check.php` | 25/0 |
| Google-Tag der Anwendung | `php tools/app-tracking-check.php` | 57/0 |
| Dokumentationssystem | `python3 tools/docs-build-check.py` | 0 Fehler |
| Syntax | `php -l` aller geänderten Dateien | fehlerfrei |

Unit-/Stub-Tests, keine Sandbox-Integration: Ein echtes Stripe-Testkonto stand nicht zur Verfügung.

## 2. Pflichtfälle des Auftrags (Zuordnung)

| Pflichtfall | Nachweis | Stand |
|---|---|---|
| Registrierung, E-Mail-Bestätigung, Pflicht-2FA, Rollen, Recovery unverändert | `tools/auth-check.sh` 13/0 (Baseline), keine Änderung in diesem Auftrag | unverändert, nicht erneut ausgeführt |
| Kein Zugriff Mandant A auf B | collections-check 3, 14a, 16 | bestanden |
| Stripe: Erfolg, Ablehnung, ungültiger Key, fehlende Rechte, fehlende SEPA-Fähigkeit, Trennen, Neuverbinden | collections-check 2, 7, 16 (401, 403, inaktiv, charges_enabled false, Wiederherstellung); Trennen und Neuverbinden statisch (`settings.php`, A-12) | bestanden (Stub), Sandbox offen |
| Fehlendes/widerrufenes Mandat, bezahlte/stornierte Rechnung, Teilzahlung, konkurrierende Versuche | collections-check 3, 4, 5, 6, 12, 13b | bestanden |
| Doppelrequest, parallele Jobs, Timeout, Wiederholung ohne Doppeleinzug | collections-check 4, 7, 7a, 7f (SLOW), 8 | bestanden |
| Webhooks: gültig, ungültige Signatur, falscher Modus, Duplikat, verspätet, Reihenfolge | collections-check 14, 14a, 16a | bestanden |
| Asynchroner Erfolg, Fehlschlag, Rückgabe, Reconciliation, Replay | collections-check 14, 14d, 7a | bestanden |
| Rate Limit, API-Ausfall, Cron-Überlappung, Lock-Ablauf, Wiederanlauf | collections-check 11, `tools/scheduler-sync-check.sh`, `tools/worker-signal-check.sh` | letzte beiden nicht erneut ausgeführt (kein Codeeingriff) |
| Conversion-Ereignisse dedupliziert, Consent vor Export | `funnel_event_once`, kein Export vorhanden; `tools/app-tracking-check.php` 57/0 | bestanden |
| Produktfakten-Snapshot ohne Geheimnisse, Vertragsstatus korrekt | `tools/product-facts-check.php` 33/0 | bestanden |
| Routen, Header, Cache, Zugriffsschutz | `docs/backend/routen-inventar.md`, `tools/host-separation-check.php` | statisch |
| Deployment/Migration/Rollback ohne Live-Zahlung | `tools/migrations-check.sh` 12/0; deploy-runner und release-version aus der Baseline | nicht erneut ausgeführt (kein Eingriff in deploy/) |

## 3. Deployment und Migration

- Migration 035 ist additiv (drei NULL-Spalten in `integrations`), wiederholbar (`IF NOT EXISTS`), ohne Datenänderung.
  Rollback der Anwendung ist ohne Rückbau der Spalten möglich (alter Code ignoriert sie).
- Kein neuer Konfigurationsschlüssel nötig. `fakten.php` braucht keinen Eintrag in Caddy oder Traefik (normale PHP-Datei
  auf dem App-Host; auf dem Adminhost 404).
- Nach dem Ausrollen: „Verbindung prüfen“ je Firma oder abwarten, bis die Firma sie ausführt; bis dahin Zustand
  `unverified`, Einzüge weiter möglich.
- Rollback über `deploy/vps/scripts/rollback.sh` auf das vorherige Release; Downgrade-Schutz beachten.

## 4. Restpunkte und Freigaben

| Punkt | Wer | Art |
|---|---|---|
| Staging-Abnahme mit Stripe-Testkonto: Konto ohne SEPA-Fähigkeit, Restricted Key ohne Kontorecht, Live-Ereignis an Testfirma | Betreiber, Backend | manuell erforderlich |
| Zusammenführung mit Frontend-Stand, gemeinsamer Integrationsstand, Frontend-Kernpfade gegen Testbackend | beide Chats | vor Veröffentlichung |
| Preisangaben: freigegeben 13.09.2026 (Betrag, Steuerhinweis, Periode); Vergleichspreis bleibt gesperrt bis zur wettbewerbsrechtlichen Klärung | Geschäftsführung | erledigt, Rest offen |
| Entscheidung IBAN-Verschlüsselung (MP-10) und CSP der Anwendung (MP-11), Antworten in Vertrag Abschnitt 10 | Geschäftsführung, Backend | offen |
| B-04 Entscheidung (Konto in zwei Firmen) | Geschäftsführung | offen |
| Stripe- und Lexware-Dokumentation gegen die Annahmen prüfen (Capability-Werte, SEPA-Schema, Rückgabefristen) | Backend mit Netzzugang | offen |
| `version.txt` entfernen oder aktualisieren | Absprache mit Frontend | offen |
