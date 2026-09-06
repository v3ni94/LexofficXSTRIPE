# Auftrag II: Umsetzung und Übergabe

Stand: 06.09.2026. Alle Änderungen liegen auf dem Branch claude/setup-lexsepa-monorepo-v5ZcZ. Jeder Push löst den Workflow deploy.yml aus (Upload per SFTP, danach ein einziger Migrationsaufruf über migrate.php). Sämtliche Tests liefen ausschließlich gegen die Testdatenbank lexsepa_e2e und den lokalen PHP-Testserver; auf der produktiven Datenbank wurde keine Migration ausgeführt. Die produktive Bereitstellung erfolgt durch den autorisierten Deployment-Workflow und ist von diesen Tests zu unterscheiden.

## Abschnitte und Dokumente

| Abschnitt | Umsetzung | Dokumentation |
|---|---|---|
| 0 Migrationsaufruf durch GitHub | migrate.php (POST, X-Migration-Token, hash_equals, JSON-Vertrag), app/migrate.php (gemeinsame Sperre, Zustände success, running, failed, unknown, keine automatische Wiederholung), Cron ohne Migrationen | docs/migrations.md (Runner, Aufrufer, Sperre, Fehlerzustände, manuelle Klärung, Ort von MIGRATION_TOKEN: app/config.php, Zeile 'migration_token' => '...') |
| 1 Synchronisierung | Sperre mit Inhaber, Doppelstarts gezählt, Budget je Schritt, API-Backoff mit Retry-After, Zustandstexte | docs/sync-performance.md |
| 2 Navigation und Export | Header ohne Dubletten, Firmendaten und Firmenübersicht, Exportvergleich | docs/navigation.md (Exporte gleichwertig in Endpunkt und Spalten; Seitenbutton übergibt zusätzlich den Statusfilter und bleibt deshalb mit klarer Beschriftung bestehen, der doppelte Header-Link wurde entfernt) |
| 3, 4, 6 Multiaccount, Registrierung, Dubletten | Migration 015, Checkbox im Profil, Registrierung mit bekannter E-Mail (Countdown, Fortsetzung nach Anmeldung), Sperren und Constraints | docs/multiaccount.md |
| 5 Gerätefreigabe 90 Tage | Migration 016, app/devices.php, Checkbox auf der 2FA-Seite, Verwaltung unter Sicherheit, Widerrufe | docs/device-trust.md |
| 7 Adminbereich System | Migration 017, app/monitor.php, admin-system.php, health.php, Instrumentierung | docs/monitoring.md |
| 8 Statusseite | websites/status.smart-einzug.de, Snapshot und Veröffentlichung, Einrichtungsliste | docs/status-page.md |

## Geänderte und neue Dateien (Abschnitte 2 bis 8)

Anwendung (php-ionos): app/auth.php, app/devices.php (neu), app/monitor.php (neu), app/monitor_view.php (neu), app/layout.php, app/bootstrap.php, app/mailer.php, app/stripe.php, app/sync_state.php, app/migrate.php, app/help_content.php, app/config.example.php, register.php, register-fortsetzen.php (neu), login.php, logout.php, twofa-verify.php, twofa-setup.php, security.php, team.php, companies.php, collections.php, settings.php, support-login.php, cron.php, migrate.php, stripe-webhook.php, admin.php, admin-system.php (neu), admin-system-data.php (neu), health.php (neu), setup-check.php, assets/js/app.js, assets/css/style.css, sql/schema.sql, sql/migrations/015_multiaccount_registration.sql, 016_trusted_devices.sql, 017_monitoring.sql.

Website und Workflow: websites/status.smart-einzug.de (neu), .github/workflows/deploy.yml (Mapping für den Statusordner, Schritt app/build.txt mit Commit und Zeitpunkt), .gitignore (build.txt).

## Migrationen

| Nummer | Inhalt | Wiederholbar |
|---|---|---|
| 015 | users.multiaccount_enabled, Tabelle registration_requests, Bestandsbenutzer mit mehreren Firmen | Ja |
| 016 | Tabelle trusted_devices | Ja |
| 017 | Tabellen job_runs, monitor_checks, monitor_daily, monitor_requests, monitor_incidents, monitor_incident_updates | Ja |

Einspielen ausschließlich über den Migrationsendpunkt (deploy.yml). Ohne die Migrationen verhält sich die Anwendung wie zuvor (Firmenübersicht sichtbar, keine Gerätefreigabe-Checkbox, Systemseite meldet die fehlende Migration). Legacy-Prüfung der Migration 015 gegen einen künstlichen Altzustand in der Testdatenbank erfolgte zweifach (wiederholbar); 016 und 017 wurden über schema.sql und den Runner-Splitter geprüft.

## Konfiguration (config.php auf IONOS, /home/www/public/app/app/config.php)

Neu und optional: 'monitoring' => [...], 'status_page_url', 'status_publish', 'test_time_offset_seconds' (nur Tests). Vorlagen und Erklärungen in app/config.example.php. Migrationstoken (migration_token), Monitoring-Zugriff (Superadmin plus monitoring.editors) und Status-Publishing (status_publish.github.token) sind getrennte Werte; MIGRATION_TOKEN wird nirgends im Monitoring verwendet. Es wurden keine echten Zugangsdaten hinterlegt.

## Tests

Alle Suiten laufen gegen eine frisch aus sql/schema.sql erzeugte Testdatenbank in fester Reihenfolge: e2e_saas.php, test_monitor.php, test_payment_safety.php, test_rules_sync.php, test_sync_perf.php, test_sync_lock.php, test_migrate_endpoint.php. Ergebnisse siehe Commit-Nachrichten und Abschlussmeldung.

Nicht geprüft (keine Testumgebung oder Zugangsdaten): TLS-Prüfung gegen echte Hosts, SMTP- und Stripe-Störungen mit echten Diensten, echte Alarmmails, Eigenlast des Monitorings auf dem IONOS-Server, Gerätefreigabe auf dem getrennten Admin-Host (im Test nur über den gespeicherten Bereich simuliert), Statusseite auf einer echten Domain, externer Prüfer. Diese Punkte sind in den Einzeldokumenten benannt.

## Freigaben und offene Entscheidungen der Geschäftsführung

- DNS und Hosting für status.smart-einzug.de (GitHub Pages empfohlen) sowie Footer-Link auf der Hauptwebsite nach Inbetriebnahme.
- Empfänger für Störungsmails (monitoring.alert_emails) und eine feste Testadresse (monitoring.test_mail_to).
- Optionaler externer Prüfer (cron-job.org auf health.php).
- Hinweis: Der Sammler läuft im bestehenden Cron; bei knappem Zeitbudget werden Prüfungen ausgelassen und als veraltet gekennzeichnet, nicht erfunden.
