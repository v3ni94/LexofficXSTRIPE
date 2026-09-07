# Arbeitsstand SmartEinzug (LexofficXSTRIPE)

Stand: 07.09.2026, Branch `claude/setup-lexsepa-monorepo-v5ZcZ`. Diese Datei ist der Einstieg für die Fortsetzung der Arbeit
und wird bei jedem Arbeitspaket aktualisiert. Sie enthält keine Zugangsdaten. Angaben, die nicht aus Code, Tests oder
Git-Historie belegbar sind, tragen den Vermerk „unsicher“.

## 1. Aktueller Auftrag

Laufende Session (Auftrag III und Folgepakete): Betrieb und Deployment auf dem Hostinger-VPS absichern, Adminbereich und
Statusseite vervollständigen, Abrechnung scharf schalten, sevdesk-Vorankündigung mit Vormerkung, Dokumentation.
Verbindliche Vorgaben des Betreibers: keine produktiven Serveraktionen durch die Session; jeder Push auf den Branch löst
den GitHub-Workflow und damit ein Produktionsdeployment aus. Für den Dokumentationsschritt vom 07.09.2026 gilt
ausdrücklich: kein Push, kein Deployment.

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
- Leadseiten lexware-einzug.de und lexoffice-einzug.de sollen auf DETM Management Consulting FZCO laufen (eigenständige
  Leadseiten ohne SmartEinzug-Logo, Provision nach Herkunft). Impressumsdaten fehlen, nichts erfinden.

## 3. Umgesetzt (Code vorhanden)

| Version | Inhalt | Commit | Push |
|---|---|---|---|
| 4.11 bis 4.16 | Statusdatei, Worker-Signalmodell, Docker-CLI-Probe, Billing-Werkzeuge, Betriebsdoku im Admin, Scheduler-Waisen, verlinkte Kennzahlen, Statusseite | bis bdd42e0 | ja, produktiv aktiv (Deploy 22 s, alle Container healthy laut Serverausgabe) |
| 4.17 | Deployjob robust gegen SSH-Netzaussetzer: `vps-ssh-retry.sh`, `vps-trigger.sh` (triggered/rejected/unclear/unreachable), Frischeprüfung des Endstatus (`JOB_STARTED_AT`), `.release-complete`-Nachweis in `deploy.sh`, Bereinigung unvollständiger Releases, Fristen je Schritt, Doku | 54caa37 | ja (Workflow-Lauf dadurch ausgelöst, Ergebnis nicht einsehbar: GitHub-API in der Session gesperrt) |
| 4.18 | sevdesk-Vorankündigung: indexierbare Seite mit Vormerkformular, `vormerken.php`, `app/interest.php`, Migration 020 `interest_registrations`, Mailvorlage, Admin-Karte, Wartung `interest_cleanup`, Datenschutz 3a, `docs/integrations.md`; Review-Fixes (faf10c1) | 9b3c880, faf10c1 | ja, 07.09.2026 auf Anweisung „mache den nächsten Schritt“ |
| 4.19 | Masterplan Phase 1: Landingpage nach Masterplan 6 (zwei Formulare, Voraussetzungen, Abgrenzung), Startseiten-Teaser, Vorregistrierung mit getrennten Token A/B, Name, Einwilligung v3, freiwillige Angaben, Sperrvermerk, Betaeinladung, Kennzahlen; Admin Suche/Filter/CSV/Aktionen; Freigabeschalter `app/integration_state.php`; `register.php?integration=`; Adapter-Gerüst `app/sevdesk.php`; `docs/sevdesk.md` mit Bestandsaufnahme | 40b6e14 | ja, 07.09.2026; Deployment b5fcd8d laut Serverausgabe erfolgreich (28 s, alle Container healthy), Migration 020 applied |
| 4.20 | sevdesk-Seite als vollständige SEO-Inhaltsseite (FAQ-Markup); Bereinigung schützt vollständige Altreleases ohne Nachweis | b029920 | ja |
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

## 4. Getestet (lokal, 07.09.2026)

| Suite | Ergebnis |
|---|---|
| `bash tools/github-ssh-retry-check.sh` | 43 bestanden, 0 fehlgeschlagen |
| `bash tools/github-poll-check.sh` | 25 / 0 |
| `bash tools/redis-deploy-check.sh` | 109 / 0 (Szenarien 18 und 19 neu) |
| `bash tools/deploy-runner-check.sh` | 35 / 0 |
| `bash tools/scheduler-sync-check.sh` | 35 / 0 |
| `bash tools/worker-signal-check.sh` | 17 / 0 |
| `bash tools/interest-check.sh` | 133 / 0 (temporäre MariaDB, Fassung 4.24) |
| `php tools/mail-ci-check.php` | 32 / 0 |
| `php tools/pricing-check.php`, `php tools/billing-setup-check.php` | 13 / 0, 55 / 0 |
| `python3 tools/site-qa.py` | 0 Fehler, 4 Warnungen (bekannte Überschriftendoppelungen zwischen Domains) |
| `python3 tools/compose-check.py`, `docs-build-check.py`, `staging-isolation-check.py` | 0 Fehler |
| `php -l` aller PHP-Dateien, YAML des Workflows, `docker compose config` prod | ohne Befund |

Gegenproben 4.17 (Frischeprüfung aus, Vollständigkeitsprüfung aus, Schnellabbruch aus) ließen jeweils die zugehörigen Tests
rot werden. Nicht getestet: der Web-Teil von `vormerken.php` (Origin-Prüfung, gerenderte Seiten) läuft nur über `php -l`
und die statischen Prüfungen; die E2E-Suite `scratchpad/e2e_saas.php` wurde in dieser Session nicht ausgeführt (unsicher,
ob sie mit Migration 020 unverändert grün bleibt, erwartet ja, da rein additiv).

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

- **Produktion: `mail.enabled` steht auf `false`**, Stand `bin/mail-check.php` vom 07.09.2026, 21:11 Uhr auf Release 4.24: SMTP ist
  vollständig konfiguriert (smtp.ionos.de:587, TLS, Nutzer und Passwort gesetzt, Absender kontakt@smart-einzug.de), es fehlt
  allein der Schalter. Bis dahin versendet die Anwendung nichts; seit 4.22 werden Vormerkungen und Registrierungen mit
  Wartemarke gespeichert und nach dem Einschalten von der Wartung nachgesendet (Migrationen 021 und 022 sind angewandt).
  Zusätzlich fehlerhaft: `mail.reply_to` lautet `kontakt@smart-einzug` (ohne `.de`), Antworten der Empfänger gingen ins
  Leere; vor dem Einschalten in `shared/config.php` korrigieren. `mail-check.php` warnt seit 4.25 bei ungültigen Adressen.
- **Vorfall 07.09.2026, 21:36 Uhr:** Nach `sed -i` auf `shared/config.php` (mail.enabled true, reply_to korrigiert) und
  `restart-workers.sh` (alte Fassung) meldete `mail-check.php` im Container weiter `false` und die alte Antwortadresse.
  Ursache: Einzeldatei-Bind-Mount bindet den Inode, `sed -i` schreibt einen neuen; `docker compose restart` erzeugt die
  Container nicht neu. Behoben in 4.26 (Skript erzeugt neu, Inode-Vergleich, php-fpm-Reload). Betreiber muss nach dem
  Deployment von 4.26 das Skript erneut ausführen; bis dahin ist der Mailversand trotz geänderter Datei nicht aktiv.
- GitHub-Lauf #58 (4.22) scheiterte wie #51 im ersten SSH-Schritt (vier Versuche, Server nie erreicht); Läufe 4.23 und
  4.24 waren grün, 4.24 (c38f7a2) ist seit 07.09.2026, 16:47 UTC aktiv. Auf dem Server ausgeschlossen: fail2ban (nie eine
  Sperre) und ufw (22/tcp ALLOW). Offen: Hostinger-Firewall im hPanel, zeitweilige Netzstörung. Auffällig: Der
  fail2ban-Jail `sshd` filtert `_SYSTEMD_UNIT=sshd.service`, die Einheit heißt auf Ubuntu 24.04 `ssh.service`; zugleich
  lieferte `journalctl -u ssh` für 24 Stunden keinen einzigen Fehlversuch. Entweder protokolliert sshd unter einer anderen
  Einheit oder fail2ban sieht keine Fehlversuche (dann wirkungslos). Prüfschritte in `docs/vps/06-betrieb.md`.

- **Vorfall 07.09.2026:** Die Bereinigung von 4.17 löschte beim Deployment b5fcd8d das Altrelease bdd42e0 (4.16), weil es
  keine Markerdatei trug. Behoben in 4.20 (vollständige Altreleases werden nachträglich gekennzeichnet). bdd42e0 ist
  verloren; Rollback-Ziele sind 54caa37 (4.17) und die folgenden Releases.

- Cron: Der VPS braucht keine Cron-Jobs (Abdeckungsmatrix `docs/vps/06-betrieb.md`); der alte IONOS-Cronjob ist vom
  Betreiber zu löschen (Cutover-Checkliste Punkt 12). Konfigurationsänderungen ohne Deployment erfordern
  `deploy/vps/scripts/restart-workers.sh`.

## 6. Nächste offene Schritte (Reihenfolge)

1. **Betreiber:** Mailversand einschalten: in `shared/config.php` `mail.reply_to` auf `kontakt@smart-einzug.de` korrigieren
   und `mail.enabled` auf `true` setzen, dann `bash /opt/smarteinzug/deploy/scripts/restart-workers.sh` und
   `php bin/mail-check.php --send=...` im php-Container (vorher `export RELEASE_SHA=...`, siehe `docs/mail-einrichtung.md`).
   Die wartenden Bestätigungs- und Willkommensmails sendet die Wartung danach innerhalb einer Stunde nach. Anschließend
   Registrierung und Vorregistrierung mit eigener Adresse durchspielen.
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
