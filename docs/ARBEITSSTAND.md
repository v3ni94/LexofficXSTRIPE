# Arbeitsstand SmartEinzug (LexofficXSTRIPE)

Stand: 11.09.2026. Der Prüfbranch `audit/2026-09-09-gesamtpruefung` (4.59 bis 4.63, Basis 66c59d5 = 4.58) wurde am 11.09.2026 auf
Freigabe des Betreibers („Produktiv ausrollen“) per Fast-Forward auf `claude/setup-lexsepa-monorepo-v5ZcZ` gepusht (HEAD 9bac540);
der GitHub-Workflow rollt 4.63 mit den Migrationen 032 bis 034 aus. Vor dem Push wurde die Historie der sieben Commits neu
geschrieben (SHAs geändert), weil GitHubs Push-Schutz die synthetischen Schlüssel `sk_live_TESTGUARD…` in `tools/test-guard-check.sh`
als Stripe-Schlüssel wertete; die Prüfwerte werden seitdem zur Laufzeit zusammengesetzt (`'sk_' . 'live_TESTGUARD'`). Übergabe des Audits: `docs/audit/HANDOVER.md`. Diese Datei ist der Einstieg für die Fortsetzung der Arbeit
und wird bei jedem Arbeitspaket aktualisiert. Sie enthält keine Zugangsdaten. Angaben, die nicht aus Code, Tests oder
Git-Historie belegbar sind, tragen den Vermerk „unsicher“.

## 1. Aktueller Auftrag

Masterprompt-Ergänzung „Vollständiges Dokumentationssystem“ (07.09.2026): drei dauerhaft gepflegte Dokumentationen im Adminbereich
(Versionen & Dokumentation), PDF im CI der Müller Holding AG, Zugriffsschutz je Klassifizierung, Historie, Kundenhandbuch in der
Kundenanwendung, Diagramme als Mermaid-Quellen, Prüfungen im Workflow, Dokumentationspflicht in CLAUDE.md. Stand: Erstfassungen
(Revision r1) aller drei Dokumente erzeugt und ausgeliefert; fachliche Prüfung durch Geschäftsführung, Screenshots für das
Kundenhandbuch, DNS-Nachweis und Wiederherstellungstest offen (siehe `docs/entwickler/abdeckung-und-offene-punkte.md`).
Arbeitsteilung seit 07.09.2026: dieser Chat arbeitet nur im Backend (`php-ionos/`, `deploy/`, `tools/`, `docs/`); `websites/`
bearbeitet ein anderer Chat (DETM-Leadseiten als Patch übergeben). Frühere Aufträge (Mail, Rechtsdokumente, Buchhaltungssystem-
Wechsel, Konzeptpapiere) sind abgeschlossen und gepusht.

## 2. Verbindliche Entscheidungen

- Deployment ausschließlich über den GitHub-Workflow per SSH (kein Coolify-Autodeploy). Migrationen laufen isoliert vor
  dem Cutover; Migrationen sind nur additiv und rückwärtsverträglich (`docs/migrations.md`).
- Einreichfenster 23:00 bis 06:00 gilt nur für das Einreichen von Lastschriften, nicht für Synchronisation und Statusabrufe.
- Einführungspreis 25,00 EUR netto je 4 Wochen mit rollierendem Stichtag (Ende des laufenden Kalendermonats), Berechnung in
  `app/pricing.php`; weitere Tarife nur über die Tabelle `plans`.
- sevdesk: eine Plattform, getrennte Anbindungen über die Adaptergrenze `InvoiceSource`, getrennter Marktauftritt je
  Rechnungssystem, keine zweite Anwendung (Empfehlung in `docs/integrations.md`, vom Betreiber noch nicht bestätigt).
  Öffentlich gilt „in Planung, Start geplant zum 30.09.2026“ (Termin vom Betreiber vorgegeben), nur Vormerkung, kein Preis,
  kein Kaufbutton, keine Aussagen zu sevdesk-Tarifen.
- sevdesk-Pilot (Entscheidung 08.09.2026): Bis zum 30.09.2026 dürfen nur Firmen mit einem Administrator-Mitglied (Timo Müller) sowie
  Firmen aus `sevdesk_pilot_orgs` sevdesk verbinden; danach alle. Freigabe früher für alle: `sevdesk_connect = 1`.
- sevdesk (Entscheidung 07.09.2026, Aufgabe 3): Phase 2 OHNE Testkonto umgesetzt. Verbinden und Lesen werden automatisch am
  30.09.2026 freigegeben (`sevdesk_release_at`; `sevdesk_connect` = 1 schaltet früher frei, = 0 sperrt). Einzüge für sevdesk-Rechnungen
  bleiben gesperrt, bis der Betreiber die Zahlungsfelder mit einem echten sevdesk-Konto bestätigt (`sevdesk_api_verified` = 1) und
  `sevdesk_collections` = 1 setzt; das ist eine bewusste Abweichung vom Wunsch „alles zum 30.09.“, weil ohne verifizierten Restbetrag
  Fehlbeträge möglich wären. Keine weitere Subdomain nötig: eine Anwendung, ein Adminbereich; Marketing je System läuft über Seiten
  beziehungsweise Leaddomains (`signup_domain`).
- Leadseiten lexware-einzug.de und lexoffice-einzug.de sollen auf DETM Management Consulting FZCO laufen (eigenständige
  Leadseiten ohne SmartEinzug-Logo, Provision nach Herkunft). Impressumsdaten fehlen, nichts erfinden.

## 3. Umgesetzt (Code vorhanden)

| Version | Inhalt | Commit | Push |
|---|---|---|---|
| 4.66 | Marketing: gesperrte Schaltflächen (Test, Freigabe) nennen den Grund (Profil inaktiv, SMTP unvollständig, Test fehlt); Betreiber hielt den ausgegrauten Testknopf für einen Fehler. Marketingversand bleibt bis zur SES-Einrichtung gesperrt | siehe git log | ja |
| 4.65 | Marketing-Vorschau: Iframe lädt nicht mehr `?vorschau=` nach (Caddy `X-Frame-Options: DENY` auf dem Adminhost, Browser „Verbindung abgelehnt“), sondern bettet den HTML-Code per `srcdoc` in das sandbox-iframe ein; Link „in neuem Fenster öffnen“ bleibt | siehe git log | ja |
| Betrieb 11.09.2026 | Produktion auf 4.64 (Release 8f521af, alle Container healthy). `shared/config.php`: `from_name` = SmartEinzug (restart-workers.sh, Inode-Wechsel erkannt). IONOS-DNS: DMARC-CNAME entfernt, eigener TXT `v=DMARC1; p=none; rua=mailto:kontakt@smart-einzug.de; fo=1` am Nameserver bestätigt. Mailkopf einer Willkommensmail ausgewertet: SPF/DKIM/DMARC PASS über IONOS, SOFTFAIL nur durch Weiterleitung beim Empfänger. Offen (Betreiber): Abnahmetest Vormerkung mit direkter Adresse, DMARC-Berichte nach zwei Wochen auswerten, Kernel-Neustart im Wartungsfenster, API-Schlüssel im IONOS Developer Portal löschen (war in Chat und Historie sichtbar), SES-Einrichtung für das Marketingmodul | keine Codeänderung | entfällt |
| 4.64 | `mail_encode_header_value()` (RFC 2047, Base64 an Wortgrenzen) für Betreff und Anzeigename statt `mb_encode_mimeheader`; Auswertung des Mailkopfs vom 11.09.2026 in `docs/mail-einrichtung.md` (Authentifizierung über IONOS in Ordnung, SOFTFAIL durch Weiterleitung beim Empfänger); mail-ci-check 86/0 | siehe git log | ja |
| Prüfstand | `tools/collections-check.sh`: Terminierungsdatum auf den nächsten Werktag (Mo bis Fr) statt fest „morgen“; am Freitag oder Samstag scheiterten sonst 41 Fälle an der berechtigten Wochenendsperre (`validate_scheduled_date`), kein Anwendungsfehler. Vollständiger Regressionslauf 11.09.2026 auf dem Prüfbranch: collections 133/0 (ohne SLOW), sync 18/0, auth 13/0, worker-signal 17/0, scheduler-sync 59/0, sevdesk 129/0, invoice-source 42/0, legal 66/0, interest 133/0, platform-roles 143/0, marketing 137/0, migrations 12/0, compose 0, staging-isolation 0, release-version 23/0, github-poll 25/0, github-ssh-retry 43/0, billing-setup 83/0, admin-period 44/0, healthcheck-redis 0, test-guard 22/0, redis-deploy 111/0, deploy-runner 35/0, payment-safety 69/0, totp-policy 87/0, mail-ci 76/0, mail-dns 30/0, host-separation 25/0, app-tracking 53/0, docs-access 23/0, pricing 14/0, docs-build-check 0. Release-Checkliste um 4.60 bis 4.63 ergänzt | 9bac540 (Historie neu geschrieben) | ja, 11.09.2026 (Workflow-Lauf nicht aus der Session einsehbar) |
| 4.63 | Marketingmodul: `admin-marketing.php`, `app/marketing.php`, `abmelden.php`, `marketing-webhook.php`, Migration 034 (6 Tabellen, Raten in platform_settings), Versandprofil `marketing` in `app/mailer.php` (`mail_profile_config`, Precedence bulk), Jobtyp `marketing_send` (Pool mail, Scheduler 300 s bei offenen Kampagnen), Rechte marketing.view/manage, 2FA für `campaign_start`, Test-Schutz für `mail_marketing`; `docs/marketing.md` mit SES-Anleitung; marketing-check 137/0, totp-policy 87/0, test-guard 22/0. Offen: SES-Identität, DNS und SNS-Thema durch den Betreiber; SNS-Signatur nur mit eigenem Zertifikat geprüft | 9bac540 (Historie neu geschrieben) | ja, 11.09.2026 (Workflow-Lauf nicht aus der Session einsehbar) |
| 4.62 | Kundenprofil im Support: `admin-kunde.php` (Firma `?org=`, Benutzer `?user=`), `app/customer_profile.php` (Lesen `support.view`, Ändern `support.customers` nur Kontaktdaten mit Grund, Audit `org_updated_support`/`profile_updated_support`, Sicherheitsmail; `CUSTOMER_PROFILE_LOCKED_FIELDS`), Migration 033 (Recht für Systemrolle support), Links in admin-support.php und admin.php; platform-roles-check +23 Fälle, totp-policy-check +3 | 9bac540 (Historie neu geschrieben) | ja, 11.09.2026 (Workflow-Lauf nicht aus der Session einsehbar) |
| 4.61 | Kopfzeilen der Zustellbarkeit: `mail_header_lines()`, `mail_reply_to_effective()` (Reply-To nur auf Absenderdomain, fremde Domain ignoriert und protokolliert), `Auto-Submitted: auto-generated`, `List-Unsubscribe`/`List-Unsubscribe-Post` One-Click nur für die drei Vormerkungsmails (Option `unsubscribe_url` durch `mail_send`, Payload `options`, `job_mail`), `interest_is_one_click_unsubscribe()` in `vormerken.php`; Vorgabe `config.example.php` auf kontakt@smart-einzug.de; Anleitung für Betreiber in `docs/mail-einrichtung.md` (Server-Transport, DKIM-Schalter, DMARC-TXT mit rua, Testmail, Postmaster Tools); mail-ci-check 76/0 | 9bac540 (Historie neu geschrieben) | ja, 11.09.2026 (Workflow-Lauf nicht aus der Session einsehbar) |
| 4.60 | Zustellbarkeit ausgehender Mails: `bin/mail-check.php --zustellbarkeit` (DNS-Abfrage auf dem VPS), `app/mail_dns.php` (Auswertung SPF, DMARC, DKIM, Absenderkonsistenz), `tools/mail-dns-check.php` 30/0; Anlass Spam-Einstufung von kontakt@smart-einzug.de; DNS-Zone laut Betreiber vollständig für IONOS-Versand, offene Prüfpunkte DKIM-Schalter, Transport, DMARC in eigener Hand, Reply-To | 9bac540 (Historie neu geschrieben) | ja, 11.09.2026 (Workflow-Lauf nicht aus der Session einsehbar) |
| 4.59 | Gesamtaudit (Rollen A bis F): 26 behobene Befunde in Geldfluss (Klärung mit Listenprüfung, Zeitzonen der Fristen, hängende submitting-Einzüge, Schutzschaltung als Zurückstellung, Webhook 500 statt 200, decline_code, Terminierung mit eigenen Einzügen, Storno, Erstattung, Währung, Backfill-Sperre, benannte Firmensperre), Sicherheit (C-01 Selbsterhöhung, C-02, C-03, C-05, C-06, C-09, C-10), Synchronisation (B-01, B-02, B-03, B-05, B-07, B-08, B-09, A-12), Betrieb (D-02, D-06, D-07, D-08, D-09), Oberfläche (E-01, E-02, E-04, E-05, E-07); Migration 032; Test-Schutz und Suiten collections/sync/auth/test-guard; `docs/audit/` | e7bba57, 166b46e (Historie neu geschrieben) | ja, 11.09.2026 auf Freigabe des Betreibers |
| 4.11 bis 4.16 | Statusdatei, Worker-Signalmodell, Docker-CLI-Probe, Billing-Werkzeuge, Betriebsdoku im Admin, Scheduler-Waisen, verlinkte Kennzahlen, Statusseite | bis bdd42e0 | ja, produktiv aktiv (Deploy 22 s, alle Container healthy laut Serverausgabe) |
| 4.17 | Deployjob robust gegen SSH-Netzaussetzer: `vps-ssh-retry.sh`, `vps-trigger.sh` (triggered/rejected/unclear/unreachable), Frischeprüfung des Endstatus (`JOB_STARTED_AT`), `.release-complete`-Nachweis in `deploy.sh`, Bereinigung unvollständiger Releases, Fristen je Schritt, Doku | 54caa37 | ja (Workflow-Lauf dadurch ausgelöst, Ergebnis nicht einsehbar: GitHub-API in der Session gesperrt) |
| 4.18 | sevdesk-Vorankündigung: indexierbare Seite mit Vormerkformular, `vormerken.php`, `app/interest.php`, Migration 020 `interest_registrations`, Mailvorlage, Admin-Karte, Wartung `interest_cleanup`, Datenschutz 3a, `docs/integrations.md`; Review-Fixes (faf10c1) | 9b3c880, faf10c1 | ja, 07.09.2026 auf Anweisung „mache den nächsten Schritt“ |
| 4.19 | Masterplan Phase 1: Landingpage nach Masterplan 6 (zwei Formulare, Voraussetzungen, Abgrenzung), Startseiten-Teaser, Vorregistrierung mit getrennten Token A/B, Name, Einwilligung v3, freiwillige Angaben, Sperrvermerk, Betaeinladung, Kennzahlen; Admin Suche/Filter/CSV/Aktionen; Freigabeschalter `app/integration_state.php`; `register.php?integration=`; Adapter-Gerüst `app/sevdesk.php`; `docs/sevdesk.md` mit Bestandsaufnahme | 40b6e14 | ja, 07.09.2026; Deployment b5fcd8d laut Serverausgabe erfolgreich (28 s, alle Container healthy), Migration 020 applied |
| 4.20 | sevdesk-Seite als vollständige SEO-Inhaltsseite (FAQ-Markup); Bereinigung schützt vollständige Altreleases ohne Nachweis | b029920 | ja |
| 4.41 | Code-Review 4.35 bis 4.40 (neun Befunde): `platform_user_invite` bestehender Konten über `platform_user_set_role`, Systemrollen support/staff ohne `docs.*`/`users.manage`, `email_verified_at` bei Einladung, `worker-sevdesk` in restart-workers/deploy/rollback, Fairness nur eigener Jobtyp, Systemprüfung der Verbindungsaktionen in settings.php, `last_sync` nach System, `sync_perf_full_sync_plan` wie Scheduler, Hinweistext Vormerkungen | siehe git log | platform-roles-check 88/0, scheduler-sync-check 59/0, totp-policy-check 73/0, redis-deploy-check 111/0, deploy-runner-check 35/0, compose-check 0 |
| 4.43 | Zeitraumsteuerung Adminbereich: `app/admin_period.php` (Voreinstellungen, freier Bereich, Sitzungsvorgabe, Vorzeitraum, Auflösung, SQL-Buckets, Auswahlleiste), admin.php Kennzahlen/Funnel/Diagramme mit Zeitraum und Vergleich, admin-system Verfügbarkeit und Performance mit Zeitraum, `sync_perf` mit from/to, CSS, `tools/admin-period-check.php` (44/0) | siehe git log | admin-period-check 44/0, scheduler-sync-check 59/0, docs-build-check |
| 4.44 | Vorabankündigung als Vorlage `mail_tpl_prenotification()` und `mail_prenotification_sample()` (`app/mailer.php`), `_send_prenotification()` nutzt sie; Musterversand `test_prenotification` in admin-system.php (nur eigene Adresse, MUSTER-Betreff, Audit), `bin/mail-check.php --vorabankuendigung --send|--html`; Doku handbuch 8.4 (Inhalt, Stripe-Hinweis), email-system, mail-einrichtung, sicherheit, monitoring | siehe git log | mail-ci-check 46/0, totp-policy-check 76/0 |
| 4.45 | Downgrade-Schutz: `deploy/vps/scripts/lib/release-version.sh` (Versionsvergleich), Einbindung in `deploy.sh` vor Übernahme des Deploy-Ordners und erstem Compose-Aufruf, `SMARTEINZUG_ALLOW_DOWNGRADE=1`, Sandbox kopiert die Bibliothek; `tools/release-version-check.sh` (23/0); 06-betrieb.md Abschnitt „Kein Downgrade durch erneut gestartete alte Läufe“ | siehe git log | release-version-check 23/0, deploy-runner-check, compose-check 0 Fehler |
| 4.46 | Preisänderung: `billing_setup_price_needs_replacement()` und `transfer_lookup_key` in `app/billing_setup.php`, `--preis-neu` in `bin/billing-setup-stripe.php` (Ersatzpreis, Eintrag, Archivierung, Hinweis auf Bestandsabos), Sperre in `admin.php` (Betrag/Periode geändert bei gleicher Preis-ID wird nicht gespeichert), `docs/abrechnung.md` Kapitel „Weitere Tarife anlegen“ und „Preis eines Tarifs ändern“ | siehe git log | billing-setup-check 69/0 |
| 4.47 | Steuerprüfung: `billing_check_tax()` in `app/billing_setup.php` (Status, Hauptsitz, aktive Registrierungen), `bin/billing-check.php` liest `/tax/registrations`; `docs/abrechnung.md` Schritt 2 ergänzt | siehe git log | billing-setup-check 78/0 |
| 4.48 | `billing_check_tax()` prüft zusätzlich den Standard-Steuercode (`defaults.tax_code`); `docs/abrechnung.md` um Steuercode und Anzeigeverhalten des Checkouts ergänzt | siehe git log | billing-setup-check 80/0 |
| 4.49 | `billing_check_tax()` liest die Art jeder Registrierung (`country_options`), meldet eine reine OSS-Registrierung im Land des Hauptsitzes als Fehler; Ausgabe nennt Land und Art | siehe git log | billing-setup-check 83/0 |
| 4.50 | `admin_host_separated()` in `app/bootstrap.php`; `app/layout.php` zeigt die Balken Abonnement, Testmodus und Support nur in der Kundenanwendung und verlinkt absolut über `app_base_url()`; `tools/host-separation-check.php` (25/0) | siehe git log | host-separation-check 25/0 |
| 4.58 | Conversion der abgeschlossenen Bestellung: `tracking_conversion_page()` und `tracking_conversion_label()` in `app/tracking.php` (nur `subscription.php?bestellt=1`, nur Ads-Kennung, Label aus `analytics.ads_conversion_label`), `assets/js/consent.js` meldet einmalig nach Einwilligung, `subscription.php` behält den Parameter und kennzeichnet die Bestellung mit Nettobetrag, Währung und gehashter Vorgangskennung; `tools/app-tracking-check.php` 31 auf 53 Fälle; `docs/ads-conversions.md`, CLAUDE.md, Revision r23 | siehe git log | app-tracking-check 53/0 |
| 4.55 | Empfehlungen der Gesamtprüfung umgesetzt: `app/webhook_events.php` (Beanspruchen, Reihenfolge, Freigabe), beide Webhooks mit HTTP 500 und Wiederholung; Support-Modus zentral in `app/customer_settings.php`, `app/collections.php`, `app/legal.php` sowie in customer.php, notstopp.php, team.php, settings.php; Sitzungscookie mit Secure hinter dem Proxy, `config_is_placeholder()` in crypto/cron/migrate, setup-check.php mit SMARTEINZUG_CONFIG und Caddy-Sperre; Bestellnachweis in `consent_records` mit echter AGB-Fassung (Migration 031, Spalte details); Alarmmarke erst nach Versand, unabhängiger Kanal `monitoring.heartbeat_url`; Doku payment-safety 5f, monitoring, email-system (SPF/DKIM/DMARC), sicherheit, CLAUDE.md | siehe git log | payment-safety-check 69/0, alle 25 Suiten grün |
| 4.52 | Gesamtprüfung (16 Fachrichtungen, 72 Rohbefunde): sechs kritische Befunde behoben. `stripe-webhook.php` Rücklastschrift setzt `requires_review`; `app/stripe.php` 5xx/409 als unbekanntes Ergebnis; `app/collections.php` `collections_source_blocked()` vor beiden Einzugswegen (+ `invoice_source_code_for_tenant()`); `app/queue.php` `queue_requeue()` liest den Zwischenstand aus der Datenbank; `app/sync.php` Cursor-Vorgaben immer ergänzt; `deploy/vps/scripts/deploy.sh` Cutover mit Auswertung, Bericht und Rollback. Neu `tools/payment-safety-check.php` (34/0), `tools/sevdesk-check.sh` +5 Fälle (129/0), veralteter Fall in `tools/interest-check.sh` korrigiert (133/0), schnelle Prüfungen im GitHub-Workflow | siehe git log | Gesamtlauf aller Suiten grün, siehe Abschnitt 4 |
| 4.42 | sevdesk-Pilot (Entscheidung 08.09.2026): `sevdesk_connect = 'pilot'` (Migration 030, Vorgabe), `integration_pilot_mode/_pilot_tenant/_connect_allowed` in `app/integration_state.php`, tenant-bewusst in Factory, Einstellungen, Wechsel, Scheduler, Registrierung, Performance-Reiter; Texte; Tests (sevdesk-check 124/0) | siehe git log | sevdesk-check 124/0, migrations-check 12/0, scheduler-sync-check 59/0 |
| 4.41 | Review-Befunde 4.35 bis 4.40: Einladung bestehender Konten über `platform_user_set_role`, Systemrollen support/staff ohne docs.*/users.manage, `worker-sevdesk` in restart-workers/deploy/rollback, Fairness nur eigener Jobtyp, Systemprüfung der Verbindungsaktionen in settings.php, letzte Synchronisation je System, Vollabgleichsplan wie Scheduler, eingeladene Benutzer mit bestätigter Adresse, Hinweistext Vormerkungen | siehe git log | platform-roles-check 88/0, scheduler-sync-check 59/0, sevdesk-check 110/0, docs-build-check |
| 4.40 | Migration 028 korrigiert (`api_version` = 'v1'), `migrations_release()` + `bin/migrate.php --retry=NNN` (Status pending statt DELETE, Audit), Marker 020 bis 029, `tools/migrations-check.sh` (Vorzustand aus Git, Strukturvergleich, Idempotenz, --retry), docs/migrations.md (Vorfall, Freigabeweg), CLAUDE.md | siehe git log | migrations-check 12/0, sevdesk-check, docs-build-check |
| 4.39 | Performance Phase 1: Migration 029 (`job_runs.queue_wait_ms`, `sync_runs.detail_calls/contact_calls/api_ms_max/cursor_bytes_max`), `job_execute` misst Wartezeit, `LexofficeClient`/`SevdeskClient` `requestMsMax`, Cursorgröße in `sync_state_step`, `scheduler_full_sync_hour()` mit `queue.full_sync_window_hours`, Fairness `queue.sync_fair_seconds` + `queue_waiting_count()`, `sync.page_size` (max 250), `app/sync_perf.php` + Reiter „Synchronisation & Performance“; Doku sync-performance.md (Nachtrag), jobs.md, 06-betrieb.md, Datenwörterbuch, CLAUDE.md | siehe git log | scheduler-sync-check (10a bis 10c), sevdesk-check, worker-signal-check, totp-policy-check, php -l |
| 4.38 | sevdesk Phase 2 ohne Testkonto: `app/sevdesk.php` (Client mit Header-Token, api_call_gate, Circuit Breaker, Monitoring; Adapter mit Lexware-Strukturen, Status- und Fälligkeitsabbildung, Restbetrag nur mit `sevdesk_api_verified`), Freigabetermin `sevdesk_release_at` (Vorgabe 30.09.2026, `integration_switch` mit Vorrang expliziter Werte), Migration 028 (integrations.sevdesk_*, invoice_source_lock_reset_at, Registry development, Freigabetermin), Einstellungen sevdesk (verbinden/prüfen/trennen), Onboarding und Kundenseiten mit dynamischem Label (Sonnet-Agent), Jobtyp `sync_run_sevdesk`/Pool `sevdesk`/`worker-sevdesk` (prod+staging), Scheduler nach Buchhaltungssystem, `QUEUE_SYNC_TYPES`, Adminaktion Wechselsperre aufheben (`companies.manage`, Pflichtgrund, Audit), Monitoring-Komponente sevdesk, AVV-Entwurf Anlage 1 A2 (Fassung entwurf-2), Doku (Sonnet-Agent: sevdesk.md Endpunktregister, integrations, handbuch 5.1a, jobs, 06-betrieb, unternehmensdoku), CLAUDE.md; neue Suite `tools/sevdesk-check.sh` (Stub-Server) | siehe git log | sevdesk-check 110/0, scheduler-sync-check 35/0, invoice-source-check 42/0, legal-check 66/0, platform-roles-check 83/0, interest-check 133/0, totp-policy-check 73/0, compose-check 0, staging-isolation 0, php -l |
| 4.37 | Plattform-Benutzer und Rechte: `app/platform.php` (PLATFORM_PERMISSIONS, Systemrollen, platform_can/require_platform, Einladung, Schutzregeln), Migration 027 (`platform_roles`, `users.platform_role`), `admin-users.php`, Plattformkontext ohne Firma (`_current_user_platform`, `platform_only`, `platform_home_url`), Rechteprüfung in allen Adminseiten und im Support-Modus, Navigation nach Rechten, Dokumentationsrechte über docs.admin/docs.technical; Doku sicherheit.md (neuer Abschnitt), schnittstellen, unternehmensdoku, monitoring, Datenwörterbuch, CLAUDE.md; neues `tools/platform-roles-check.sh` | siehe git log | platform-roles-check 83/0, totp-policy-check 73/0, docs-access-check 23/0, legal-check 66/0, scheduler-sync-check 35/0, interest-check 133/0, invoice-source-check 42/0, php -l |
| 4.36 | Zweitbestätigung (2FA) nur für Wichtiges nach Vorstandsbeschluss: `QUEUE_MONEY_TYPES`/`queue_type_is_money()`, geteilte Zweige (incident_publish, org_sync_pause, platform_pause nur Aufheben, admin-legal nur publish/retire), zehn Aktionen ohne Code, Formularfelder angepasst; Mobilbefund admin-legal (table-wrap); Doku sicherheit.md (Tabelle), payment-safety 5c, monitoring, unternehmensdoku, schnittstellen, 06-betrieb, integrations, qa, CLAUDE.md; neues `tools/totp-policy-check.php` | siehe git log | totp-policy-check 73/0, legal-check, scheduler-sync-check, php -l |
| 4.35 | Workflow-Datei repariert: `VPS_RETRY_STATE_FILE` mit festem Pfad statt `runner.temp` in der Job-Umgebung (Lauf #69 „Invalid workflow file“, 4.34 startete gar nicht); Dokumentationsauswirkung: `docs/vps/06-betrieb.md` (Regel zu Kontexten auf Job-Ebene), Revision entwickler r4 | siehe git log | YAML-Parse, github-ssh-retry-check 43/0, github-poll-check 25/0, compose-check 0 Fehler |
| 4.34 | Zustimmungsnachweis AGB/Datenschutz (Migration 025, `app/consent.php`), Reiterleiste `layout_subnav()`, Dokumentationskarten, Dokumentationsrechte für Plattformadministratoren, Indizes (Migration 026), automatischer zweiter Anlauf `deploy-vps` bei Verbindungsfehler | siehe git log | legal-check 64/0, docs-access-check 23/0, github-ssh-retry-check, github-poll-check |
| 4.33 | Host-Trennung: Adminseiten per Muster `admin-*.php` (admin-legal.php lieferte 404) | siehe git log | php -l |
| 4.32 | Dokumentationssystem: drei Dokumentationen (Unternehmen, Entwickler/Betrieb, Kunden) aus docs/, Generator mit CI-PDF (Logo je Seite, Deckblatt, Abschlussblatt, TOC, Querformat), HTML mit Suche, Kapitel-PDFs, Manifest Schema 2, Zugriffsstufen (`app/docs.php`, `docs.technical_readers`), `handbuch.php`, Archiv in `shared/docs-archive` (deploy.sh), 14 Mermaid-Schaubilder, Datenwörterbuch-Generator, Datenbankkapitel mit Kompendium-Prüfung, Dokumentationspflicht in CLAUDE.md | siehe git log | docs-build-check, Sichtprüfung PDF |
| 4.31 | Buchhaltungssystem je Firma: Anzeige, Vorauswahl bei Registrierung, Wechsel in Einstellungen mit Vier-Wochen-Sperre, Trennung der alten Verbindung, Audit (Migration 024) | siehe git log | invoice-source-check 42/0 |
| 4.30 | Rechtsdokumente (AVV, Verschwiegenheit § 203 StGB) mit Zustimmungsnachweis, Registrierung, Dashboard, Adminverwaltung, Entwurfstexte mit Datenanlage aus Codeinventur; Protokoll 20 Zeilen/Export/90 Tage | siehe git log | legal-check 56/0 |
| 4.28 | `restart-workers.sh` mit Deploy-Sperre (`.deploy.lock`), kein Zusammentreffen mit Deployments mehr | siehe git log | bash -n, interest-check |
| 4.27 | Gegenprobe in `restart-workers.sh` über `bin/mail-check.php` statt Direktaufruf von `config.php` (Forbidden) | siehe git log | bash -n |
| 4.26 | `restart-workers.sh` erzeugt Container neu (Einzeldatei-Bind-Mount, Inode-Prüfung, php-fpm-Reload, Syntaxprüfung, Gegenprobe) | siehe git log | bash -n, interest-check |
| 4.25 | `mail-check.php`: aktueller Hinweistext, Warnung bei ungültiger Absender-/Antwortadresse; Betriebsdoku (Hostinger-Firewall, fail2ban-Einheit, RELEASE_SHA, Protokollpfad) | siehe git log | ja |
| 4.24 | Token erst nach erfolgreichem Versand, Bestätigt-Mail wird nachgesendet, ehrliche Bestätigungsseite | siehe git log | ja |
| 4.23 | Nachsenden vervollständigt (Migration 022 Nachtrag, Willkommensmail auch bei bestätigter Adresse, Warteschlange, kein Doppelversand, keine leeren Mails), Cron-Matrix und `restart-workers.sh` | siehe git log | ja |
| 4.22 | Nachsenden wartender Bestätigungs- und Willkommensmails (Migration 021), ehrlicher Seitentext, Adminwarnung | siehe git log | ja |
| 4.21 | E-Mails im CI der Müller Holding AG mit Pflichtangaben, Willkommensmail bei Registrierung, Bestätigungsmail nach Vorregistrierung, `bin/mail-check.php`, `docs/mail-einrichtung.md`, Statusseite „seit Erfassungsbeginn“ | siehe git log | ja |

Betroffene Dateien 4.17: `.github/workflows/deploy.yml`, `.github/scripts/vps-ssh-retry.sh`, `.github/scripts/vps-trigger.sh`,
`.github/scripts/vps-wait-status.sh`, `deploy/vps/scripts/deploy.sh`, `deploy/vps/scripts/rollback.sh`, `tools/github-ssh-retry-check.sh`,
`tools/github-poll-check.sh`, `tools/redis-deploy-check.sh`, `tools/lib/deploy-sandbox.sh`, `docs/vps/03-github-deployment.md`,
`docs/vps/06-betrieb.md`, `docs/migrations.md`, `CLAUDE.md`, `php-ionos/app/version.php`.

Betroffene Dateien 4.18: `php-ionos/vormerken.php`, `php-ionos/app/interest.php`, `php-ionos/app/mailer.php`, `php-ionos/app/jobs.php`,
`php-ionos/admin.php`, `php-ionos/sql/migrations/020_interest_registrations.sql`, `php-ionos/sql/schema.sql`,
`websites/smart-einzug.de/integrationen/sevdesk/index.html`, `.../integrationen/index.html`, `.../hilfe/index.html`,
`.../datenschutz/index.html`, `.../assets/css/site.css` (Asset-Hashes aller Seiten der Domain neu), `.../sitemap.xml`,
`tools/interest-check.sh`, `tools/lib/interest-sim.php`, `tools/build-docs.py`, `docs/integrations.md`, `docs/einwilligungen.md`, `php-ionos/cron.php`, `CLAUDE.md`, `php-ionos/app/version.php`.

## 4. Getestet (lokal, Gesamtlauf 09.09.2026 nach 4.52)

Alle Suiten grün: payment-safety 34/0, totp-policy 76/0, billing-setup 83/0, admin-period 44/0, docs-access 23/0,
mail-ci 46/0, host-separation 25/0, healthcheck-redis 0 Fehler, compose-check 0, staging-isolation 0,
docs-build-check 0, legal 66/0, interest 133/0, invoice-source 42/0, platform-roles 88/0, sevdesk 129/0,
scheduler-sync 59/0, release-version 23/0, github-poll 25/0, github-ssh-retry 43/0, migrations 12/0,
worker-signal 17/0, deploy-runner 35/0, redis-deploy 111/0. `php -l` über alle 31.833 Zeilen PHP ohne Befund.
Nach dem Merge des Marketingstands (4.51) ebenfalls grün: pricing-check 14/14, site-qa 0 Fehler (2 Warnungen),
seo-map-check 0 Fehler. Damit läuft `pricing-check` im GitHub-Workflow verbindlich mit, nicht mehr nur als Hinweis.

**Nicht im Repository:** Die in diesem Dokument und in CLAUDE.md genannte E2E-Suite `scratchpad/e2e_saas.php` samt
`test_monitor.php`, `test_queue.php`, `test_payment_safety.php`, `test_rules_sync.php`, `test_sync_perf.php`,
`test_sync_lock.php`, `test_migrate_endpoint.php` und `test_healthcheck.php` liegt nicht unter Versionsverwaltung
(`scratchpad/` existiert im Klon nicht). Aus einem frischen Klon lässt sich die wichtigste Testsuite damit nicht
ausführen; ein Nachbau oder eine Aufnahme ins Repository steht aus.

## 4a. Frühere Gesamtläufe (08.09.2026 nach 4.39: alle Suiten grün)

| Suite | Ergebnis |
|---|---|
| `bash tools/github-ssh-retry-check.sh` | 43 bestanden, 0 fehlgeschlagen |
| `bash tools/github-poll-check.sh` | 25 / 0 |
| `bash tools/redis-deploy-check.sh` | 111 / 0 |
| `bash tools/deploy-runner-check.sh` | 35 / 0 |
| `bash tools/scheduler-sync-check.sh` | 59 / 0 (Fälle 10a bis 10c seit 4.39) |
| `bash tools/worker-signal-check.sh` | 17 / 0 |
| `bash tools/invoice-source-check.sh` | 42 / 0 (temporäre MariaDB) |
| `bash tools/legal-check.sh` | 66 / 0 (temporäre MariaDB) |
| `bash tools/interest-check.sh` | 133 / 0 (statische Prüfung „keine stille Bestätigung“ seit 4.24 fälschlich rot, weil sie den lesenden Vergleich `=== 'confirmed'` traf; Muster auf schreibende Zuweisung eingegrenzt) (temporäre MariaDB, Fassung 4.24) |
| `php tools/mail-ci-check.php` | 32 / 0 |
| `php tools/totp-policy-check.php` | 73 / 0 (neu in 4.36) |
| `bash tools/platform-roles-check.sh` | 83 / 0 (neu in 4.37, temporäre MariaDB) |
| `bash tools/sevdesk-check.sh` | 110 / 0 (neu in 4.38, HTTP-Stub plus temporäre MariaDB) |
| `php tools/pricing-check.php`, `php tools/billing-setup-check.php` | 13 / 0, 55 / 0 |
| `python3 tools/site-qa.py` | 0 Fehler, 4 Warnungen (bekannte Überschriftendoppelungen zwischen Domains) |
| `python3 tools/compose-check.py`, `docs-build-check.py`, `staging-isolation-check.py` | 0 Fehler |
| `php -l` aller PHP-Dateien, YAML des Workflows, `docker compose config` prod | ohne Befund |

Gegenproben 4.17 (Frischeprüfung aus, Vollständigkeitsprüfung aus, Schnellabbruch aus) ließen jeweils die zugehörigen Tests
rot werden. Nicht getestet: der Web-Teil von `vormerken.php` (Origin-Prüfung, gerenderte Seiten) läuft nur über `php -l`
und die statischen Prüfungen; die E2E-Suite `scratchpad/e2e_saas.php` wurde in dieser Session nicht ausgeführt (unsicher,
ob sie mit Migration 020 unverändert grün bleibt, erwartet ja, da rein additiv).

## 4b. Gesamtlauf 10.09.2026 nach 4.59 (Prüfbranch)

Alle 27 Bestandssuiten grün (platform-roles 102/0 mit neuen Fällen, payment-safety 69/0 mit angepasstem Fall B), neu:
test-guard 20/0, collections 136/0 (mit SLOW; vorher gegen 66c59d5: 85/36), sync 18/0 (vorher 9/8), auth 13/0, migrations 12/0
mit 032, docs-build 0 Fehler, `php -l` fehlerfrei. Gegenprüfung F: 18 Gegenbeispiele, 13 umgesetzt, 5 dokumentiert
(`docs/audit/AUDIT_REPORT.md` Abschnitt 6). Leistungsmessung lokal (`tools/perf-probe.sh`): `docs/audit/PERFORMANCE_REPORT.md`.

## 5. Bekannte Fehler und Risiken

- GitHub-Workflow-Lauf #51 (4.14) scheiterte an einem SSH-Timeout; Ursache extern (Netz/Firewall), behoben durch
  Wiederholung in 4.17. Ob der Lauf für 54caa37 grün war, ist aus der Session nicht einsehbar (GitHub-API 403).
- Adversariale Prüfung des Vormerk-Endpunkts (21 Agenten): 16 Befunde bestätigt und eingearbeitet (Commit „4.18 Review“),
  2 verworfen. Restrisiko dokumentiert in `docs/integrations.md`: Ein Skript ohne Header kann die globale Grenze von 30
  Einträgen je Minute ausschöpfen (Denial of Service der Vormerkung, kein Datenabfluss). Datenschutztext 3a nennt
  Dienstleister nur generisch (Hosting, E-Mail-Versand); Prüfung durch Rechtsberatung empfohlen (unsicher, ob Namen der
  Auftragsverarbeiter genannt werden müssen).
- Statusseite: `status_publish` wurde vom Betreiber am 07.09.2026 in `shared/config.php` gesetzt und `worker-maintenance`
  neu gestartet; Wirksamkeit (status.json mit Daten statt Platzhalter) noch nicht bestätigt. Auf dem Server liegen aus
  einer versehentlichen Shell-Eingabe zwei leere Dateien: `/opt/smarteinzug/shared/status/status.json],` und
  vermutlich `/root/[file` (unsicher, aus der Terminalausgabe geschlossen). Sie sind harmlos und sollten entfernt werden.
- Produktionskonfiguration ohne Schlüssel `environment` (Anzeige „Umgebung: ?“ in `bin/billing-check.php`), toleriert.
- Repository-Variable `WEBHOSTING_APP_DEPLOY` und externer Uptime-Check sind ungeprüft beziehungsweise nicht eingerichtet.

- Masterplan sevdesk: Adapter, Rechnungsabgleich und Einzug sind **blockiert**, bis ein sevdesk-Testkonto mit API-Zugang
  vorliegt (nach sevdesk-Hilfe Tarif Buchhaltung Pro, Systemversion 2.0). Basisadresse und Headerform des Clients sind
  Konfigurationswerte, unverifiziert. Live zeigte lexoffice-einzug.de am 07.09.2026 laut Masterplan noch „31.12.2026“;
  das Repository ist rollierend (pricing-check grün), also ist der Stand des IONOS-Webhostings beziehungsweise des
  Jobs `deploy-webhosting` zu prüfen (aus der Session nicht einsehbar).
- Widerspruch: Der Masterplan verlangt eine Tarifaussage zu sevdesk (Buchhaltung Pro nach offizieller Hilfe); die
  frühere Regel „keine Aussagen zu sevdesk-Tarifen“ wurde deshalb auf genau diese belegte Formulierung geändert (CLAUDE.md).

- **Mailversand aktiv seit 07.09.2026, 23:00 Uhr:** `mail.enabled` true, `reply_to` und `smtp.user` auf
  `kontakt@smart-einzug.de` korrigiert (beiden fehlte `.de`), Testmails über smtp.ionos.de erfolgreich übergeben. Ankunft
  im Postfach und Nachsendung der wartenden Vormerkung (stündliche Wartung) noch vom Betreiber zu bestätigen.
- **Vorfall 07.09.2026, 21:36 Uhr:** Nach `sed -i` auf `shared/config.php` (mail.enabled true, reply_to korrigiert) und
  `restart-workers.sh` (alte Fassung) meldete `mail-check.php` im Container weiter `false` und die alte Antwortadresse.
  Ursache: Einzeldatei-Bind-Mount bindet den Inode, `sed -i` schreibt einen neuen; `docker compose restart` erzeugt die
  Container nicht neu. Behoben in 4.26 (Skript erzeugt neu, Inode-Vergleich, php-fpm-Reload). Betreiber muss nach dem
  Deployment von 4.26 das Skript erneut ausführen; bis dahin ist der Mailversand trotz geänderter Datei nicht aktiv.
- **Lauf #63 (4.27) fehlgeschlagen, 07.09.2026 um 23:00 Uhr:** zeitgleich liefen zwei `restart-workers.sh`-Aufrufe des
  Betreibers, die alle Container mit Release f3ec760 (4.26) neu erzeugten. Wahrscheinlichste Ursache: Kollision mit dem
  Cutover (Release-Bindung). Ursache im Protokoll noch zu bestätigen; behoben ab 4.28 durch gemeinsame Sperre. Aktiver
  Stand auf dem Server nach dem Fehlschlag zu prüfen (`.deploy-status.json`, `readlink releases/current`).
- GitHub-Lauf #58 (4.22) scheiterte wie #51 im ersten SSH-Schritt (vier Versuche, Server nie erreicht); Läufe 4.23 und
  4.24 waren grün, 4.24 (c38f7a2) ist seit 07.09.2026, 16:47 UTC aktiv. Auf dem Server ausgeschlossen: fail2ban (nie eine
  Sperre) und ufw (22/tcp ALLOW). Offen: Hostinger-Firewall im hPanel, zeitweilige Netzstörung. Auffällig: Der
  fail2ban-Jail `sshd` filtert `_SYSTEMD_UNIT=sshd.service`, die Einheit heißt auf Ubuntu 24.04 `ssh.service`; zugleich
  lieferte `journalctl -u ssh` für 24 Stunden keinen einzigen Fehlversuch. Entweder protokolliert sshd unter einer anderen
  Einheit oder fail2ban sieht keine Fehlversuche (dann wirkungslos). Prüfschritte in `docs/vps/06-betrieb.md`.

- **Läufe #73 (4.38) und #74 (4.39) fehlgeschlagen, 08.09.2026, Phase Migration:** Migration 028 schrieb `'v1 (Systemversion 2.0)'` in
  `integration_providers.api_version` VARCHAR(20) („Data too long“); die ALTER-Anweisungen davor waren wirksam, die Zeile steht auf
  `failed`, 4.39 blieb dadurch blockiert. Laufende Container unverändert (4.37 aktiv). Behoben in 4.40. **Betreiber:** nach dem
  Ausrollen von 4.40 (der Lauf scheitert zunächst weiter an der Blockade) einmalig im Container ausführen:
  `export RELEASE_SHA=<sha von 4.40>; docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env run --rm --no-deps -T php php bin/migrate.php --retry=028`,
  danach den GitHub-Lauf von 4.40 erneut starten (Re-run). Erwartung: 028 vollständig, 029 eingespielt, Cutover auf 4.40.
- **sevdesk-Adapter unverifiziert (4.38):** Endpunkte, Felder, Statuscodes, Zeitzone der Zeitstempel, Paginierung und Rate-Limit stammen
  aus Sekundärquellen (Register in `docs/sevdesk.md`). Möglich ist, dass der Verbindungstest oder die Liste am echten Konto fehlschlägt
  (dann klare Fehlermeldung, kein Datenverlust, kein Geldfluss). Vor `sevdesk_api_verified` = 1 zwingend mit Testkonto prüfen:
  Basisadresse, Header, `status`-Werte 200/750/1000, `sumGross`/`paidAmount`, `payDate`/`timeToPay`, `update`, `embed=contact`,
  `InvoicePos`-Filter, `CommunicationWay`-Typ EMAIL, Belegtyp-Bedeutungen (MA, TR, AR, ER, WKR), Gutschriften.
- **Lauf #69 (4.34) „Invalid workflow file“:** `runner.temp` in der Job-Umgebung von `deploy-vps`; GitHub lehnte die Datei ab,
  kein Job lief, 4.34 wurde nicht deployt. Behoben in 4.35 (fester Pfad). Der Lauf für 4.35 muss grün werden und holt die
  Migrationen 025 und 026 nach; Ergebnis aus der Session nicht einsehbar (GitHub-API gesperrt), vom Betreiber zu bestätigen.
- **Vorfall 07.09.2026:** Die Bereinigung von 4.17 löschte beim Deployment b5fcd8d das Altrelease bdd42e0 (4.16), weil es
  keine Markerdatei trug. Behoben in 4.20 (vollständige Altreleases werden nachträglich gekennzeichnet). bdd42e0 ist
  verloren; Rollback-Ziele sind 54caa37 (4.17) und die folgenden Releases.

- Cron: Der VPS braucht keine Cron-Jobs (Abdeckungsmatrix `docs/vps/06-betrieb.md`); der alte IONOS-Cronjob ist vom
  Betreiber zu löschen (Cutover-Checkliste Punkt 12). Konfigurationsänderungen ohne Deployment erfordern
  `deploy/vps/scripts/restart-workers.sh`.

## 5a. Offene Befunde des Audits (nicht behoben, Priorität laut `docs/audit/AUDIT_REPORT.md`)

B-04 dasselbe Lexware-Konto in zwei Firmenaccounts (Doppel-Einzug über Firmengrenze, P1, braucht Identitätsfeld aus
`/profile` und Entscheidung des Betreibers), B-06 Altrechnungen nach Wechsel des Buchhaltungssystems (P2, braucht Spalte
Herkunftssystem), A-13 Import-Übernahme ohne Neuprüfung (P3), E-03 Stripe-Rohtexte (P2), E-06 Bestätigung ohne Cache (P2),
C-04, C-08, C-11, C-12 (P3), D-10 bis D-18 (P3). Betreiberaufgaben: `trusted_proxies` setzen, Datenbankzeitzone feststellen,
Migration 032 auf Staging prüfen (RELEASE_CHECKLIST.md).

## 6. Nächste offene Schritte (Reihenfolge)

Stand 08.09.2026 nach Abschluss der Zwölf-Aufgaben-Nachricht vom 07.09.2026 (Releases 4.33 bis 4.39, alle gepusht):

- **Betreiber, Deployment prüfen:** Läufe für 4.35 bis 4.39 im GitHub-Workflow (aus der Session nicht einsehbar); Migrationen 025
  bis 029 laufen isoliert vor dem Cutover. Nach dem Deployment `restart-workers.sh` ist nicht nötig (kein Konfigurationswechsel).
- **Betreiber, Rechte:** unter Adminbereich, Benutzer und Rechte, erste Mitarbeiter einladen (Mailversand aktiv); Rolle Mitarbeiter
  Support für Supportkräfte; eigene Rollen bei Bedarf.
- **Betreiber, sevdesk:** Testkonto beschaffen; Prüffragen des Endpunktregisters (`docs/sevdesk.md`, 5b) abarbeiten; erst dann
  `sevdesk_api_verified = 1`, Pilotfirmen, `sevdesk_collections = 1`. Ohne Eingriff wird am 30.09.2026 nur die Verbindung frei.
- **Betreiber, Go-live:** Leitfaden Scharfschaltung (Unternehmensdokumentation, Kapitel 2, auch als Kapitel-PDF) Schritt für Schritt;
  Stripe-Konto der Müller Holding AG, Rechtsdokumente nach anwaltlicher Prüfung, Wiederherstellungstest, DNS-Nachweis.
- **Frontend-Chat:** `php tools/pricing-check.php` Abschnitt D ist rot wegen fester Preisangaben auf `websites/` (kein Backend-Thema);
  bitte dort bereinigen, damit der Workflow die Prüfung wieder mitlaufen kann.
- **Performance, Phase 2 (nach einer Woche Messwerten):** Bemessung der Lexware-Worker, Lexware-Webhooks und Seitengröße erst nach
  Prüfung der Dokumentation am Primärtext (`docs/sync-performance.md`, Nachtrag 4.39).

0. **Umsetzung der Empfehlungen (Version 4.55, 09.09.2026).** Aus der Gesamtprüfung wurden über die sechs kritischen
   Befunde hinaus umgesetzt: Wiederholung und Reihenfolge beider Webhooks, Support-Modus zentral gesperrt,
   Sitzungscookie hinter dem Proxy, Einrichtungsprüfung verschlossen, Platzhalter der Beispielkonfiguration
   abgewiesen, dauerhafter Nachweis der zahlungspflichtigen Bestellung (Migration 031), Alarmmarke erst nach
   Versand und unabhängiger Alarmkanal. Offen bleiben die Punkte, die nur der Betreiber erledigen kann:
   Auszahlungen bei Stripe freischalten (in Arbeit), `monitoring.heartbeat_url` bei einem externen Dienst
   einrichten, SPF, DKIM und DMARC im DNS setzen und nachweisen (Anleitung in `docs/entwickler/email-system.md`),
   Wiederherstellungstest durchführen und protokollieren (`deploy/vps/backup/restore-test.sh`), AVV anwaltlich
   prüfen und veröffentlichen, AGB- und Datenschutzlink im Stripe-Konto eintragen, die vier eigenen Firmen
   befreien.
0. **Gesamtprüfung 09.09.2026 (Version 4.52).** Sechzehn Fachrichtungen (Einzüge, Stripe je Firma, Abrechnung, Mandate,
   Anmeldung, Mandantentrennung, Plattformrechte, Websicherheit, Geheimnisse, Warteschlange, Synchronisation, sevdesk,
   Datenschutz, Datenbank, Betrieb, Monitoring) lieferten 72 Rohbefunde. Die sechs als kritisch eingestuften wurden
   einzeln am Code verifiziert, behoben und mit Tests gesichert (siehe Zeile 4.52). Die übrigen 66 Befunde
   (26 hoch, 33 mittel, 7 niedrig) liegen vollständig mit Fundstelle, Nachweis, Fehlerfall und Vorschlag in
   `docs/pruefung-2026-09-09.md` und sind der nächste Arbeitsvorrat. Sie sind einzeln gegengeprüft, aber NICHT
   adversarial verifiziert (die Prüfrunde wurde wegen Rechenzeit abgebrochen): vor jeder Umsetzung am Code
   bestätigen. Schwerpunkte:
   Idempotenz und Reihenfolge der Webhooks, Support-Modus mit zu weiten Rechten, Sitzungscookie ohne Secure-Flag
   hinter dem Proxy, setup-check.php ohne Token erreichbar, Migrationen 003 und 022 nicht wiederholbar,
   Alarmierung im überwachten System, Zustimmung zur zahlungspflichtigen Bestellung nur im Frontend.
0. **Lauf #82 (4.45) fehlgeschlagen, 08.09.2026 23:55 UTC:** Die Candidate-Prüfung brach mit
   `Parse error ... config.php on line 19` ab, weil `shared/config.php` in genau diesem Moment von Hand bearbeitet wurde
   (`'environment' => 'prod'` ohne abschließendes Komma). Gewollte Wirkung: laufende Container unverändert, kein Rollback.
   Die Datei ist korrigiert (`php -l` grün), Umgebung meldet jetzt `prod`. Deployment über „Run workflow“ erneut auslösen.
0. **Umsatzsteuer im Checkout: geklärt und in Ordnung (09.09.2026).** Ablauf des Vorfalls, alle Zeiten UTC: Der erste echte
   Kauf um 00:20 Uhr wurde ohne Umsatzsteuer belastet (25,00 EUR statt 29,75 EUR), weil im Stripe-Konto zu diesem Zeitpunkt
   keine Steuerregistrierung hinterlegt war; der Beleg nennt das als „Steuerpflicht: nicht registriert“. Die Registrierung
   für Deutschland wurde gegen 00:30 Uhr nachgetragen. Die anschließend beobachteten 0,00 EUR auf der Bezahlseite waren
   kein Fehler: Stripe weist die Steuer erst aus, wenn der Kunde seine Rechnungsadresse eingegeben hat. Mit vollständiger
   Adresse rechnet der Checkout korrekt 25,00 EUR netto zuzüglich 4,75 EUR Umsatzsteuer, Gesamt 29,75 EUR (vom Betreiber
   bestätigt). Der Kauf vom 00:20 Uhr wurde erstattet, die Stripe-Gebühr von 0,63 EUR bleibt als Kosten des Tests.
   Ein von Hand angelegter Steuersatz (`txr_…`) wirkt neben Stripe Tax nicht und sollte archiviert werden.
   Aus dem Vorfall entstanden die Prüfungen 4.47 bis 4.49 (Registrierung vorhanden, Standard-Steuercode, Art der
   Registrierung); `bin/billing-check.php` hätte den Ausgangszustand von Anfang an als Fehler gemeldet.
0. **Abrechnung scharf geschaltet (09.09.2026, ca. 02:20 Uhr):** `billing.enabled = true`, erste echte Bestellung
   durchgelaufen (Status `active`, Webhook hat den Status selbst gesetzt). Zwei Firmen (WEB2MEDIA GmbH, M&B Consulting GmbH)
   stehen noch auf `pending` und sind gesperrt: befreien oder Abonnement abschließen. **Fehlbetrag beim ersten Kauf:**
   25,00 EUR statt 29,75 EUR, weil im Stripe-Konto keine aktive Steuerregistrierung hinterlegt war; ohne sie berechnet
   Stripe keine Umsatzsteuer. Registrierung im Dashboard nachtragen, danach `bin/billing-check.php` (prüft das seit 4.47).
   Weiter offen: Auszahlungen im Stripe-Konto sind pausiert (überfällige Verifizierungsaufgabe), AGB- und Datenschutzlink
   unter öffentliche Unternehmensinformationen.
0. **Stripe-Plattformabrechnung eingerichtet (08./09.09.2026):** Live-Konto der Müller Holding AG (`acct_1UCRt4…`),
   Live-Schlüssel und Webhook-Geheimnis in `shared/config.php`, Webhook mit den fünf Ereignissen, Stripe Tax aktiv,
   Kundenportal konfiguriert, Produkt und Preis angelegt (`price_1UDYzd…`, 25,00 EUR netto je 28 Tage, `tax_behavior`
   exclusive), Preis-ID in `plans`. `bin/billing-check.php`: 0 Fehler, 2 Warnungen. Offen vor `billing.enabled = true`:
   Auszahlungen im Stripe-Konto freischalten (Aufgabe „Payouts paused“), AGB- und Datenschutzlink unter öffentliche
   Unternehmensinformationen, Entscheidung zu den drei Firmen ohne Abonnement (Hausverwaltung Müller GmbH, WEB2MEDIA GmbH,
   M&B Consulting GmbH: befreien oder Abonnement abschließen lassen).
0. **Vorfall 08.09.2026, 21:05 bis 21:37 UTC (Downgrade auf 4.38):** Die früher fehlgeschlagenen Läufe #73 bis #79 wurden in GitHub
   erneut gestartet („Re-run“); jeder deployte seinen alten Commit, zuletzt 4.38 (d435ca4). `.release_history` belegt die Reihenfolge
   075d617 (4.44) → 6514eb8 → 4b9f331 → d8f666f → 6de4972 → d435ca4; die Bereinigung löschte Release 075d617. Datenbank unverändert
   (028 bis 030 eingespielt). Behebung: Push 4.45 rollt den aktuellen Stand aus, Downgrade-Schutz in `deploy.sh`. Nach dem Deployment
   im Adminbereich prüfen: Fußzeile 4.45, `restart-workers.sh` nennt worker-sevdesk.
0. **Deployment 08.09.2026, Abend:** Lauf #79 (4.43) scheiterte im ersten SSH-Schritt („Connection timed out“, vier Versuche, Server nie
   erreicht, nichts verändert). Der automatische zweite Anlauf (#81, auto_retry=1) und der Push-Lauf zu 4.44 (#80) liefen beide grün
   (deploy-vps success 20:31 und 20:35 UTC); 4.44 enthält 4.43, nichts nachzuholen. Muster wie #51 und #58 (Runner-Adresse oder kurze
   Netzstörung, `docs/vps/06-betrieb.md`, „SSH-Fehler des Deployments“); Prüfschritte 1 bis 7 dort nur nötig, wenn es sich häuft.
0. **Betreiber:** Migrationsblockade am 08.09.2026, 14:11 Uhr gelöst (`--retry=028`: 028 und 029 eingespielt, 0 offen). Offen: Re-run des
   Workflows für 4.40 (fd500a4), danach im Adminbereich prüfen, dass Version 4.40 in der Fußzeile steht.
0. **Betreiber (nach Deployment 4.37):** Migration 027 setzt bestehenden Superadmin-Konten die Rolle Administrator. Unter Adminbereich,
   „Benutzer und Rechte“ Mitarbeiter einladen (Mailversand muss aktiv sein); für den Fall, dass ein Mitarbeiter ohne Firma sich anmeldet,
   landet er direkt im Adminbereich (Adminhost). Erster Test: Einladung an eine eigene Zweitadresse mit Rolle Mitarbeiter, Passwort setzen,
   2FA einrichten, prüfen, dass Not-Stopp, Tarife und Benutzerverwaltung ausgeblendet und per Direktaufruf verweigert werden.
1. **Betreiber:** Ankunft der Testmail prüfen, nach etwa einer Stunde die nachgesendete Bestätigungsmail der eigenen Vormerkung
   (Button „Vormerkung bestätigen“, danach Bestätigt-Mail mit Abmeldelink) und die Statusseite (Komponente E-Mail) kontrollieren.
1c. **Betreiber/Rechtsanwalt:** Entwurfstexte AVV und Verschwiegenheitsvereinbarung prüfen (`app/legal_drafts.php`, Adminbereich
   Rechtsdokumente), Anlage 3 mit Hostinganbieter (Firma, Anschrift, Serverstandort) und Sicherungsspeicher füllen, dann als
   Fassung veröffentlichen (2FA). Entscheidung: Firmen ohne AVV-Zustimmung nach Übergangsfrist sperren? (nicht umgesetzt).
   Ablauf: `docs/rechtsdokumente.md`.
1d. **Geschäftsführung:** Konzeptpapier Produkt 2 (`docs/anlagen/MHAG_Konzept_Produkt2-sevdesk-x-Stripe_2026-09-07.pdf`) prüfen:
   Entscheidung zu Testkonto und Kombitarif für Mandanten mit zwei Buchhaltungen (zwei Firmenaccounts, Multiaccount).
1e. Offen zum Systemwechsel: Adminaktion zum Aufheben der Vier-Wochen-Sperre (derzeit nur per Datenbank), Verbindungsseite für
   sevdesk nach Freigabe des Adapters.
1f. **Betreiber:** erledigt am 07.09.2026 (Entwicklerdokumentation ist seit 4.34 für Plattformadministratoren ohne Leserliste sichtbar). Alt: `docs.technical_readers` in `shared/config.php` mit der eigenen Adresse füllen (sonst ist die Entwicklerdokumentation
   im Adminbereich für niemanden abrufbar), danach `restart-workers.sh`. Prüfen: Adminbereich, System, Versionen & Dokumentation.
1g. **Betreiber:** DNS-Nachweis `dig +short app.smart-einzug.de` (erwartet 72.61.80.67), Altinstanz `sepa.muellerhv.de` und IONOS-Cronjob
   abschalten; Ergebnis in `docs/entwickler/hosts.md` und `docs/vps/08-hostinger-coolify.md` nachtragen (Nachweisstufe).
1h. Entschieden am 07.09.2026 (Abwägung delegiert): Indizes umgesetzt (Migration 026), Übriges zurückgestellt oder Betreiberaufgabe
   (`docs/entwickler/datenbank.md`, Abschnitt 8.0).
1a. **Betreiber:** sevdesk-Testkonto nach `docs/sevdesk.md`, Abschnitt 5a (Tarif mit API-Zugang, Token nur über sicheren Kanal).
1b. **Betreiber:** DETM Management Consulting FZCO: vollständige Anschrift, Registerangaben, vertretungsberechtigte Person,
   E-Mail und Telefon für das Impressum; Entscheidung, wie der Provisionsnachweis je Herkunftsdomain erfolgen soll
   (Kennzahlen je `signup_domain` sind im Adminbereich vorhanden).
3. SSH-Ausfälle der Läufe #51/#58: Hostinger-Firewall im hPanel prüfen, `journalctl _COMM=sshd` gegen `journalctl -u ssh`
   vergleichen und fail2ban-Jail auf die richtige Einheit stellen (Serverkonfiguration, bewusst planen). Optional: automatischer
   zweiter Anlauf des Jobs `deploy-vps` bei reinem Verbindungsfehler (braucht `actions: write`, nur nach Freigabe).
4. DETM-Leadseiten: blockiert bis Impressumsdaten und Entscheidung zum Provisionsnachweis vorliegen.
5. Masterplan Phase 2 (ab 11.09.): sevdesk-Testkonto beschaffen, Endpunkte verifizieren, `SevdeskSource` füllen, Workertyp
   `sevdesk`, Anbieterwahl in der Firmeneinrichtung (`invoice_source`), Verbindungsseite „Buchhaltungssystem“ ohne
   Lexware-Pflichtfelder, Pilotfreigabe über `sevdesk_connect`. Versandwerkzeug für die Startnachricht (nur bestätigt und
   nicht gesperrt, setzt `notified_at`). Rechtliche Prüfung der Texte (Einwilligung v3, Datenschutz 3a, Sperrvermerk).


## 7. Frontend-Branch `claude/frontend-smart-einzug-egsouk` (Stand 09.09.2026, kein Deployment aus diesem Branch)

Auftrag: Masterprompt „SEO-, Content- und Landingpage-Ausbau für SmartEinzug“ vom 07.09.2026 (Bestandsaufnahme, Faktenregister, Bereinigung, Keyword-Map, Maßnahmenplan). Arbeitsordner `docs/seo/`, Einstieg `docs/seo/README.md`.

Entscheidungen des Betreibers (07.09.2026): keine Preisbeträge auf den Marketingseiten bis zur Freigabe; lexoffice-einzug.de und lexware-einzug.de als Leadseiten der DETM Management Consulting FZCO ohne SmartEinzug-Logo (Pflichtangaben offen); neue Leaddomains sevdesk-einzug.de (Vormerkung) und sevdesk-sepa.de (SEPA-Wissen) mit getrennten Inhalten; lastschrift-einfach.de ist nur eine Weiterleitung auf smart-abrechnen.de. Entscheidung vom 08.09.2026: zurückgestellte Maßnahmen (Zusammenführung M4, lastschrift-einfach.de, AGB ohne Beträge, Version) auch ohne Rankingdaten umsetzen, weil die Seiten offiziell noch nicht online sind.

Umgesetzt: Inventarwerkzeug `tools/seo-inventory.py`, Keyword-Map `docs/seo/keyword-map.json` mit `tools/seo-map-check.py` (28 Cluster, 72 Seiten, 0 Fehler), `tools/build-sitemaps.py` mit lastmod aus Git, `tools/pricing-check.php` Abschnitt D, `tools/lead-assets.py`, Faktenregister (191 Einträge), Aussagenprüfung (241 Befunde, 58 verworfen), DETM-Umstellung der Leadseiten, sevdesk-Domains, Bereinigung der Werbeaussagen und Preisentfernung (Phase 2), neun neue Seiten der Hauptdomain (Anleitungen, Wissen, Sicherheit) und Ausbau der Integrationsseiten (Phase 3b), Abschlussbericht `docs/seo/07-abschlussbericht.md`. M4 umgesetzt (acht Artikel nach `smart-einzug.de/wissen/` verlagert, 19 Seiten der Leaddomains per 301 weitergeleitet, `.htaccess` beider Leaddomains), lastschrift-einfach.de auf `.htaccess` und 404 reduziert und aus den Website-Werkzeugen entfernt, AGB Abschnitt 7 auf drei Domains ohne Beträge (Entwurf, Prüfvorbehalt), `APP_VERSION` 4.51 mit Changelog (nach Merge des Backend-Stands 4.50 am 09.09.2026; Konflikte in `version.php`, `CLAUDE.md`, `tools/build-docs.py` aufgelöst, SEO-Kapitel als viertes Dokument `marketing` im Dokumentationssystem). Stand 08.09.2026: 62 Seiten auf fünf Domains, site-qa 0 Fehler (2 Warnungen), pricing-check 14/14, seo-map-check 0 Fehler, docs-build-check 0 Fehler.

Vor dem Merge in den Backend-Branch zu klären: DETM-Impressumsangaben, `signup_domains` in Produktion um die sevdesk-Domains ergänzen, IONOS-Zuordnung der neuen Domains, anwaltliche Prüfung der AGB-Entwürfe, Freigabe der Preisdarstellung, Pull Request #2 wurde am 09.09.2026 gemergt (41b21c8), Lauf 88 des Workflows hat alle Website-Ordner einschließlich sevdesk-einzug.de und sevdesk-sepa.de hochgeladen (lexware-einzug.de zeigt live DETM). Danach (4.53): sepa-einzug.de und sepaeinzug.de als Alias-Domains mit 301 auf smart-einzug.de (`websites/aliases/`, Maßnahmenplan M10); bei IONOS auf `alias-sepa-einzug` und `alias-sepaeinzug` zu legen (Lauf 90 hat die Ordner angelegt). 4.57: Google-Tag auch in der Anwendung, begrenzt auf `register.php` und `vormerken.php` und nur nach Einwilligung (`app/tracking.php`, `tools/app-tracking-check.php` 31/0); im Firmenaccount findet keine Messung statt. 4.56: Technisches SEO-Audit aller 62 Seiten (`docs/seo/SEO_AUDIT.md`, Seitenmatrix und Weiterleitungsverzeichnis als CSV); umgesetzt wurden nur risikoarme Korrekturen (verwaiste Seite verlinkt, doppelte Titel aufgelöst, Fußzeilenlogo lazy, acht ungenutzte Logodateien der Leaddomains entfernt), neuer Prüfer `tools/seo-linkcheck.py` im Workflow. 4.54: Google-Ads-Kennung AW-18431688840 zusätzlich für smart-einzug.de in `site.js` (nur nach Einwilligung; Googles Tag-Prüfung ohne Einwilligung findet das Tag nicht, Nachweis über echte Treffer). `deploy.yml`: Der Job `deploy-webhosting` spiegelt die Website-Ordner seit dem 08.09.2026 mit `--delete` (Entscheidung Betreiber; Ausnahmen Verifizierungsdateien von Google und Bing sowie `.well-known`, Ordner `app` weiterhin ohne Löschung); vor dem ersten Lauf prüfen, ob in den Website-Ordnern des Hosters Dateien liegen, die nicht im Repository sind. Offene Fragen an den Betreiber stehen in `docs/seo/02-faktenregister.md`, Abschnitt „Offene Fragen“.
