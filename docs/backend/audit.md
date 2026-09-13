# Backend-Audit (Masterprompt Backend, 13.09.2026)

Stand 13.09.2026, Basis-Commit e29e5d8 (4.75), Arbeitsbranch `backend/masterprompt-2026-09-13` (4.79). Dieses Dokument
konsolidiert die Bestandsaufnahme für den Ergänzungsauftrag; die ausführlichen Befunde des Gesamtaudits vom 10.09.2026
bleiben in `docs/audit/AUDIT_REPORT.md`, die Geldfluss-Invarianten in `docs/audit/PAYMENT_INVARIANTS.md`, die Prüfmatrix in
`docs/audit/TEST_MATRIX.md`. Angaben tragen die Kennzeichnung nachgewiesen (Suite oder Code), konfiguriert (nicht geprüft),
geplant oder offen.

## 1. Arbeitsgrenzen und Vorgehen

- Arbeitskopie: eigener Klon dieser Sitzung, eigener Branch ohne Deploy-Auslöser. Der Workflow `deploy.yml` deployt nur
  `main` und `claude/setup-lexsepa-monorepo-v5ZcZ` bei Änderungen unter `php-ionos/**`, `websites/**`, `deploy/vps/**`;
  ein Push auf den Arbeitsbranch veröffentlicht nichts. Kein Produktionsdeploy, keine Live-Zahlung, keine Kundenmail.
- Fremde Änderungen: Der Frontend-Chat hat am 12.09.2026 die Versionen 4.69 bis 4.72 (Google-Tag) über PR #6 und #7 und am
  13.09.2026 die Versionen 4.76 und 4.77 (Faktenseite `/fakten/`, Preis öffentlich, Preisprüfer auf Richtigkeit) über PR #8 und #9
  in den Deploybranch gebracht. Der Backend-Stand wurde deshalb auf 4.79 umnummeriert und auf 0e6ea52 aufgesetzt. Keine
  uncommitteten fremden Änderungen im Klon.
- Subagenten: ein Sonnet-Agent für das Routeninventar (`docs/backend/routen-inventar.md`), sonst direkte Arbeit plus
  vorhandene Prüfstände. Externe Dokumentation (docs.stripe.com, developers.lexware.io) war in dieser Sitzung durch die
  Netzsperre nicht abrufbar; versionsabhängige Aussagen sind entsprechend als ungeprüft gekennzeichnet.

## 2. Tatsächlicher Technikstand (nachgewiesen)

| Punkt | Stand | Nachweis |
|---|---|---|
| Sprache, Framework | PHP 8.4, kein Framework, kein Composer (`composer.json` fehlt) | Repository |
| Datenbank | MariaDB (Coolify-Dienst auf dem VPS), Migrationen 001 bis 035 additiv | `php-ionos/sql/`, `tools/migrations-check.sh` 12/0 |
| Laufzeit | Docker-Stack hinter Coolify/Traefik auf einem Hostinger-VPS, Caddy intern, Redis-Warteschlange, Worker als PID 1 | `deploy/vps/`, `docs/vps/` |
| Marketingseiten | statisches HTML auf IONOS-Webhosting, Spiegelung per SFTP im Workflow | `.github/workflows/deploy.yml` Job `deploy-webhosting` |
| Deployment | ausschließlich GitHub-Workflow über SSH, serverseitiger Runner mit Sperre, Candidate-Prüfung, isolierte Migration, Cutover, Downgrade-Schutz | `deploy/vps/scripts/deploy*.sh`, `tools/deploy-runner-check.sh` |
| Historische Angaben „Laravel, SFTP-Deploy der App“ | treffen nicht mehr zu; die App läuft auf dem VPS, SFTP nur noch für die Websites | Workflow |

Kein Wechsel von Framework, Hosting oder Diensten in diesem Auftrag; Docker und Redis sind Bestand, nicht neu eingeführt.

## 3. Bestandsaufnahme (Kurzfassung mit Verweisen)

- Mandanten und Rollen: `organizations`, `organization_members.role` (owner, admin, member), Plattformrollen
  `platform_roles` mit `PLATFORM_PERMISSIONS`; jede Abfrage mit `tenant_id` (`docs/entwickler/sicherheit.md`).
- Registrierung, E-Mail-Bestätigung, Pflicht-2FA (TOTP), Recovery-Codes, Gerätefreigabe 90 Tage: `app/auth.php`,
  `tools/auth-check.sh`.
- Stripe je Firma: Secret oder Restricted Key verschlüsselt, Webhook-Secret je Firma, Konto-ID und Modus gespeichert;
  seit 4.79 zusätzlich Zahlungsfreischaltung und SEPA-Fähigkeit (`docs/backend/stripe-review.md`).
- Plattform-Abrechnung: eigenes Stripe-Konto der Müller Holding AG, `billing.php`, `billing-webhook.php`,
  fünf Pflichtereignisse, Nettopreise, 28-Tage-Periode (`docs/abrechnung.md`).
- Einzüge, Mandate, Zustandsautomat, Versuchsjournal, Idempotenz, Webhook-Beanspruchung und Reihenfolge:
  `app/collections.php`, `app/webhook_events.php`, `docs/payment-safety.md`, `docs/audit/PAYMENT_INVARIANTS.md`.
- Synchronisation Lexware Office: nur lesend, Rate-Limit über `api_call_gate()`, Delta und Vollabgleich, Nachprüfung
  bezahlter Rechnungen, Storno terminierter Einzüge bei bezahlter Rechnung: `app/sync.php`, `docs/sync-performance.md`.
- sevdesk: Adapter nach Sekundärquellen, Freigabetermin 30.09.2026 nur für Verbinden und Lesen, Einzüge gesperrt bis
  `sevdesk_api_verified` und `sevdesk_collections`: `docs/sevdesk.md`. Status geplant, kein Nachweis am echten Konto.
- Hintergrundjobs: Warteschlange in Redis, Jobtypen in `app/jobs.php`, Signalmodell, Fairness, Einreichfenster nur für
  Einzüge: `docs/queue-worker.md`, `tools/scheduler-sync-check.sh`, `tools/worker-signal-check.sh`.
- Consent: AGB und Datenschutz je Registrierung (`consent_records`), Vormerkung mit Double-Opt-in (`interest_registrations`),
  Rechtsdokumente versioniert (`legal_documents`, `legal_acceptances`).
- Logs und Monitoring: `audit_log` (90 Tage), `monitor_events`, Alarmierung mit Totmannschalter, Statusseite.
- Routen: 58 Serverdateien, Zugriffsklassen und Header in `docs/backend/routen-inventar.md` (Subagent, aus dem Quelltext).

## 4. Prioritäten und Befunde dieses Auftrags

| ID | Prio | Befund | Stand |
|---|---|---|---|
| MP-01 | P1 | Stripe-Verbindung galt als „verbunden“, sobald `GET /v1/account` antwortete; Zahlungsfreischaltung und SEPA-Fähigkeit blieben ungeprüft, ein Einzug scheiterte erst bei Stripe mit Rohtext (E-03) | behoben 4.79: Migration 035, `stripe_connection_state()`, Sperre in `_get_stripe_client($tenantId, true)`, Anzeige in `settings.php`; Prüfstand collections-check 16 (32 Fälle) |
| MP-02 | P2 | Webhook prüfte den Modus des Ereignisses (`livemode`) nicht gegen den Modus des hinterlegten Schlüssels (Signatur fängt den Fall meist ab) | behoben 4.79: Ereignis im falschen Modus wird mit 200 ignoriert; Fall 16a |
| MP-03 | P2 | Trennen der Stripe-Verbindung nannte nicht, dass terminierte Einzüge bestehen bleiben | behoben 4.79: Zahl der terminierten Einzüge im Hinweis und im Audit; Fälligkeitslauf stellt sie ohne Verbindung zurück (kein Fehlschlag, kein Einzug) |
| MP-04 | P1 | Keine zentrale, versionierte Produktfaktenquelle; Website und Faktenregister (`docs/seo`) ohne maschinenlesbaren Stand | behoben 4.79: `app/product_facts.php`, Snapshot, `fakten.php`, `tools/product-facts-check.php` 33/0 |
| MP-05 | P1 | Kein Datenvertrag zwischen Backend und Frontend | behoben: `docs/contracts/smarteinzug-contract.md` 1.0 |
| MP-06 | P3 | `billing-webhook.php` ohne Methodenprüfung (Signatur schlägt bei GET ohnehin fehl) | behoben 4.79: 405 für andere Methoden als POST |
| MP-07 | P3 | `collections.php` Aktion `cancel` prüft den Support-Modus nicht | bewertet, keine Änderung: Storno verhindert einen Einzug, bewegt kein Geld (Schutzrichtung wie Not-Stopp aktivieren); dokumentiert in Vertrag Abschnitt 6 |
| MP-08 | P3 | `setup-check.php` vor Anlage der Konfiguration ohne Token erreichbar | bewertet, keine Änderung: nur auf einem Server ohne Konfiguration (Einrichtung), seit 4.54 mit Token sobald die Konfiguration existiert |
| MP-09 | P3 | `version.txt` veraltet (06.09.2026) | dokumentiert im Vertrag, nicht als Versionsquelle verwenden; Entfernung ist Frontend-Absprache (Datei wird von Websites nicht referenziert, Prüfung offen) |
| MP-10 | P2 | IBANs der Kunden liegen im Klartext in `customer_ibans.iban` (Frontend-Befund SICH-03, bestätigt); verschlüsselt sind nur API-Schlüssel | offen, Entscheidung des Betreibers: Verschlüsselung mit Migration und Umschlüsselung des Bestands oder Anpassung der AVV-Anlagen an den Ist-Zustand |
| MP-11 | P3 | Anwendung ohne Content-Security-Policy außer Einzelseiten (`docs.php`, `mandat.php`) | offen; vor Aktivierung Abstimmung mit dem Frontend (consent.js, Google-Hosts in script-src, img-src, connect-src) |

Offen aus dem Gesamtaudit (unverändert): B-04 P1 (dasselbe Lexware-Konto in zwei Firmen, Entscheidung des Betreibers),
B-06, E-03 teilweise (Rohtexte außerhalb der Kontoprüfung), E-06, A-13, C-04, C-08, C-11, C-12, D-10 bis D-18, F-14, F-17,
F-18 (`docs/audit/AUDIT_REPORT.md` 4.2 und 6).

## 5. Öffentliche und geschützte Grenzen (aus dem Routeninventar)

Öffentlich ohne Anmeldung: `index.php`, `login.php`, `register.php`, `forgot-password.php`, `twofa-verify.php`,
`logout.php`, `impressum.php`, `health.php`, `track.php`, `setup-check.php` (nur ohne Konfiguration), neu `fakten.php`.
Token in der Adresse: `vormerken.php`, `abmelden.php`, `invite.php`, `mandat.php`, `reset-password.php`,
`support-login.php`. Anmeldung: 28 Seiten. Plattformrecht: 9 Adminseiten (nur Adminhost). Webhooks mit Signatur: 3.
CLI oder Token: `cron.php`, `migrate.php` (POST mit Token, kein GET). Vollständige Tabelle mit Methoden, Aktionen, CSRF,
2FA, Support-Sperre, Mandantenbindung und Headern: `docs/backend/routen-inventar.md`.

## 6. Datenschutz und Rechtliches (Status, keine Rechtsprüfung)

Datenflüsse, Empfänger und Aufbewahrung sind in `docs/entwickler/sicherheit.md`, `docs/rechtsdokumente.md` und
`docs/einwilligungen.md` beschrieben. Offen und nur durch den Betreiber lösbar: anwaltliche Prüfung und Veröffentlichung
des AVV, Nennung der Auftragsverarbeiter in der Datenschutzerklärung, gemeinsame Verantwortlichkeit für das Google-Tag auf
der Leadseite lexware-einzug.de (Frontend-Änderung vom 12.09.2026), Rechenzentrumsstandort. Eine technische Prüfung ist kein
Nachweis der Datenschutz- oder Rechtskonformität.

## 7. Fehlende Zugänge und Nachweise

- Stripe- und Lexware-Dokumentation nicht abrufbar (Netzsperre der Sitzung); Aussagen zu SEPA-Schema, Rückgabefristen,
  Capability-Namen und Rate-Limits stammen aus Projektwissen und sind im Register als `oeffentlich_behauptet` oder mit
  Prüfdatum null gekennzeichnet.
- Kein Stripe-Testkonto, kein sevdesk-Konto, keine Analytics- oder Search-Console-Daten in der Sitzung.
- GitHub-Workflow-Läufe und Produktionszustand nicht einsehbar; Deployment-Prüfung nur lokal (Prüfstände).
