# Datenbankmigrationen: Runner, Sperre, Fehlerbehandlung, Deployment

Stand 06.09.2026. Gilt für die Anwendungsdatenbank von SmartEinzug (php-ionos).

## Runner und Aufrufer

| Bestandteil | Datei | Aufgabe |
|---|---|---|
| Runner | `php-ionos/app/migrate.php` | `migrations_run()`: Sperre holen, Blockaden prüfen, offene Migrationen in Reihenfolge ausführen, Stand in `schema_migrations` führen. `migrations_status()` liest nur. |
| Endpunkt | `php-ionos/migrate.php` | Einziger Einstieg, der Migrationen ausführt. Nur POST mit Header `X-Migration-Token`. Antwort ausschließlich JSON. |
| Migrationsdateien | `php-ionos/sql/migrations/NNN_*.sql` | Wiederholbare SQL-Anweisungen (`IF NOT EXISTS`), eine Datei je Version. Per Web gesperrt (`php-ionos/sql/.htaccess`, `php-ionos/.htaccess`). |
| Stand anzeigen | `php-ionos/setup-check.php?token=<cron_token>` | Zeile "Migrationen (Stand)": eingespielt, offen, failed, unknown. Nur lesend. |
| Workflow | `.github/workflows/deploy.yml` | Nach vollständig erfolgreichem SFTP-Upload genau ein POST auf die Adresse aus der GitHub-Repository-Variablen `WEBHOSTING_MIGRATE_URL` (nicht mehr fest verdrahtet); geprüft durch `tools/check-migrate-url.sh`, einmal vor dem Upload und einmal unmittelbar vor dem Aufruf. Erfolg nur bei HTTP 200 und JSON `{"success":true}`; keine Wiederholung. |

Geprüfte frühere Aufrufer und ihr Stand:

- `cron.php`: rief bis Version vom 06.09.2026 bei jedem Lauf `migrations_apply()` auf. Entfernt. Der Cron erledigt weiterhin Einzüge, Klärung, Alarme und Synchronisation.
- `migrate.php?token=<cron_token>` (GET, Textausgabe): entfernt. GET liefert jetzt 405.
- `app/bootstrap.php`, Login, Seitenaufrufe, `setup-check.php`, Webhooks, Synchronisation: führen keine Migration aus (im Test 7 von `test_migrate_endpoint.php` belegt: offene Migration bleibt nach Login, Cron und Setup-Check offen).
- Kein CLI-Skript, keine Adminfunktion und kein Wartungsskript ruft den Runner auf.

## Token

- Konfigurationsschlüssel `migration_token` in `php-ionos/app/config.php` (Array-Struktur der vorhandenen Konfiguration, siehe `app/config.example.php`):

```php
    'migration_token' => 'HIER-MIGRATIONSTOKEN-64-ZUFALLSZEICHEN',
```

  Einfügestelle: direkt unter `'cron_token' => ...`. Eigener Zufallswert (z. B. `openssl rand -hex 32`), unabhängig von `cron_token`, `app_secret` und allen API-Schlüsseln. Derselbe Wert wird in GitHub als Repository-Secret `MIGRATION_TOKEN` hinterlegt (Settings > Secrets and variables > Actions).
- Ort auf dem Server: Die Datei liegt im App-Ordner, den der Workflow als `php-ionos` nach `<SFTP_PATH>/app` spiegelt. SFTP-relativer Pfad daher `app/app/config.php` unterhalb des Webspace-Wurzelverzeichnisses (`SFTP_PATH`, bei IONOS in der Regel `/`). Absoluter Pfad laut Setup-Prüfung vom 06.09.2026: `/home/www/public/app/app/config.php` (aus der dort gemeldeten Ablage `/home/www/public/app/app/storage/mandates` abgeleitet).
- Schutz: `deploy.yml` schließt `config.php` vom Upload aus (`--exclude '(^|/)(config\.php|...)'`) und spiegelt ohne `--delete`; die Datei wird weder überschrieben noch entfernt. `php-ionos/.htaccess` sperrt das Verzeichnis `app/` für Webzugriffe, `setup-check.php` zeigt nur, ob der Wert gesetzt ist.
- Prüfung im Endpunkt: nur der Header `X-Migration-Token` wird gelesen (kein URL-Parameter, kein Formularfeld, kein Cookie); leere oder fehlende Werte auf beiden Seiten führen nie zur Freigabe; Vergleich mit `hash_equals(konfiguriert, übermittelt)`.

## HTTP-Vertrag

| Situation | Status | Antwort |
|---|---:|---|
| alle offenen Migrationen erfolgreich, oder nichts offen | 200 | `{"success":true}` |
| Token fehlt, leer, falsch | 401 | `{"success":false,"error":"unauthorized"}` |
| Sperre belegt | 409 | `{"success":false,"error":"migration_in_progress"}` |
| Migration fehlgeschlagen oder Blockade durch failed/unknown | 500 | `{"success":false,"error":"migration_failed"}` |
| `migration_token` nicht konfiguriert, Bootstrap-Fehler | 500 | `{"success":false,"error":"server_configuration_error"}` |
| andere Methode als POST | 405 | `{"success":false,"error":"method_not_allowed"}`, Header `Allow: POST` |

Header immer: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`. Fremdausgaben werden über Ausgabepufferung verworfen, PHP-Hinweise landen nur im Serverprotokoll. Bricht der Webserver die Verbindung selbst ab (Timeout), gibt es keine JSON-Antwort; der Workflow wertet das als Fehler.

## Gemeinsame Sperre

`GET_LOCK('smarteinzug_migrations_<hash der Datenbank>', 0)` in MariaDB: atomar, an die Datenbankverbindung gebunden, ohne feste Ablauffrist. Stirbt der Prozess, gibt der Server die Sperre frei; ein fremder Prozess kann sie nicht aufheben. Belegt = HTTP 409, nichts wird ausgeführt und nichts vorgemerkt. Die Sperre ist unabhängig von den Synchronisationssperren je Firma (`sync_state.lock_until`) und von Sperren der Einzugsverarbeitung.

## Fehlerzustände und manuelle Klärung

Tabelle `schema_migrations`: `version`, `filename`, `status` (`success`, `running`, `failed`, `unknown`), `started_at`, `finished_at`, `error_text`, `applied_by`.

- Vor jeder Migration wird die Zeile mit `running` angelegt, nach vollständigem Erfolg auf `success` gesetzt. Fehler: `failed` mit Fehlertext, Lauf bricht ab, spätere Migrationen laufen nicht.
- Ein `running` ohne laufenden Prozess (der nächste Lauf hält die Sperre und findet die Zeile) wird zu `unknown`: Teiländerungen sind möglich.
- `failed` und `unknown` blockieren jeden weiteren Lauf (HTTP 500), auch durch GitHub. Keine automatische Wiederholung.

Manuelle Klärung (nur mit Datenbankzugriff, z. B. phpMyAdmin):

1. Tatsächlichen Zustand prüfen: Welche Anweisungen der Datei sind wirksam (Tabellen, Spalten vorhanden)? Die Migrationsdateien sind wiederholbar formuliert (`IF NOT EXISTS`), Teiländerungen sind daher in der Regel unschädlich.
2. Ursache beheben (Datei korrigieren und erneut hochladen, Rechte, Speicher).
3. Freigabe ausdrücklich erteilen:
   - Erneut ausführen (seit 4.40 bevorzugt, ohne Datenbankkonsole): `php bin/migrate.php --retry=NNN` im php-Container. Setzt die
     Zeile auf `pending` und spielt die korrigierte Datei im selben Aufruf vollständig erneut ein; protokolliert als
     `migration_released` im Audit. Ein `DELETE` der Zeile ist dafür NICHT geeignet: Nach einem Teilerfolg (Spalten angelegt,
     Datenanweisung fehlgeschlagen) gilt die Migration über ihren Marker sonst als eingespielt und die restlichen Anweisungen
     laufen nie (Vorfall Migration 028, Läufe #73 und #74, 08.09.2026).
   - Als erledigt übernehmen (nur wenn alle Anweisungen nachweislich wirksam sind): `UPDATE schema_migrations SET status = 'success', finished_at = NOW(), error_text = NULL WHERE version = 'NNN';`
4. Danach den Workflow erneut laufen lassen (Re-run in GitHub Actions) oder das nächste Deployment abwarten. Die korrigierte
   Datei muss dabei bereits ausgerollt sein; `--retry` im alten Release würde die fehlerhafte Datei erneut ausführen.

Es gibt keine automatische Rückabwicklung. MariaDB führt DDL nicht transaktional aus; deshalb die Klärung am tatsächlichen Datenbankzustand.

## Freigabekriterium: nur additive, rückwärtsverträgliche Migrationen

Auf dem VPS läuft die Migration isoliert mit dem neuen Code, BEVOR die Container gewechselt werden. Während
sie läuft, beantwortet das alte Release weiter Anfragen, und `rollback.sh` wechselt bei einem Rollback nur
den Anwendungscode, nie das Schema. Beides trägt nur, wenn jede Migration additiv und rückwärtsverträglich
ist. Verbindlich vor jeder Freigabe zu prüfen:

- kein `DROP TABLE`, kein `DROP COLUMN`, kein `RENAME` an bestehenden Objekten;
- kein verengender Typwechsel, keine neue `NOT NULL`-Spalte ohne Vorgabewert;
- keine Datenumschreibung, die der alte Code nicht versteht;
- wiederholbar formuliert (`IF NOT EXISTS`, `INSERT IGNORE`), damit eine Teilausführung unschädlich bleibt.

Entfernen oder Umbauen erfolgt in zwei Releases: erst der Code, der das Alte nicht mehr braucht, im
Folgerelease die Bereinigung. Es gibt keinen Datenbankauszug unmittelbar vor der Migration; die tägliche
Sicherung läuft über Coolify. Eine Wiederherstellung über bereits gebuchte Zahlungen hinweg ist ausgeschlossen
(`docs/betrieb-migration-vps.md`), der wirksame Schutz ist deshalb dieses Kriterium, nicht ein Auszug.

### Klärung von `failed` oder `unknown` auf dem VPS

Auf dem VPS gibt es kein phpMyAdmin und keinen öffentlichen Datenbankport. Die Klärung läuft über die
Container:

```bash
cd /opt/smarteinzug/deploy
export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
# Stand der Migrationen aus Sicht der Anwendung
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/migrate.php --status
# Datenbankzugang über den Coolify-MariaDB-Container (Name in .env, DB_CONTAINER)
docker exec -it "$DB_CONTAINER" mariadb -u"$DB_USER" -p "$DB_NAME"
```

Freigabe zur Wiederholung direkt im Container (kein Datenbankzugang nötig; das Release mit der korrigierten Datei muss
unter `releases/` liegen, deshalb `RELEASE_SHA` auf dessen Kennung setzen, nicht auf `current`):

```bash
export RELEASE_SHA="<sha des korrigierten Release>"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env run --rm --no-deps -T php php bin/migrate.php --retry=NNN
```

Danach den GitHub-Lauf des korrigierten Release erneut starten (Re-run); er findet keine Blockade mehr. Alternativ
gelten die oben beschriebenen Wege (`UPDATE ... 'success'` für nachweislich wirksame Änderungen).

### Vorfall Migration 028 (Läufe #73 und #74, 08.09.2026)

Migration 028 schrieb `'v1 (Systemversion 2.0)'` in `integration_providers.api_version` (`VARCHAR(20)`): „Data too long“.
Die vorangehenden `ALTER TABLE` waren wirksam, die Zeile stand auf `failed`, 4.39 blieb blockiert. Korrektur in 4.40:
Wert `v1`, Erläuterung in `notes`. Lehre: Migrationen müssen gegen den echten Vorzustand laufen, nicht nur gegen das
aktuelle `schema.sql` (dort greift `UPDATE ... WHERE status = 'planned'` keine Zeile). Dafür gibt es seit 4.40
`bash tools/migrations-check.sh`: baut die Datenbank aus dem `schema.sql` des Commits vor der ältesten neuen
Migrationsdatei, führt `bin/migrate.php` mit dem aktuellen Code aus, vergleicht die Struktur mit dem aktuellen
`schema.sql` (Spalten, Typen, Indizes) und prüft Idempotenz sowie `--retry`. Läuft vor jedem Release mit Migration.

## Deployment-Ablauf

1. Workflow prüft Secrets (`MIGRATION_TOKEN` ohne Zeilenumbruch, `migrate.php` vorhanden, `sql/.htaccess` vorhanden) sowie, im selben Schritt, mit `tools/check-migrate-url.sh` die Adresse aus `WEBHOSTING_MIGRATE_URL` (https, Pfad endet auf `/migrate.php`, keine Zugangsdaten oder Parameter in der Adresse, kein zum VPS gehörender Name). Fehlt oder eignet sich die Adresse nicht, bricht der Workflow bereits hier ab, es wird nichts hochgeladen.
2. SFTP-Upload je Ordner (ohne `config.php`, `storage`, Logs, ohne `--delete`), zuletzt `sql/.htaccess` und `sql/migrations/`.
3. Nur bei vollständig erfolgreichem Upload: `tools/check-migrate-url.sh` läuft ein zweites Mal unmittelbar vor dem Aufruf, danach ein POST auf die geprüfte Adresse, Auswertung von HTTP 200 und `success: true` mit `jq`, `--retry 0`, keine Weiterleitungen. Die HTTPS-Zertifikatsprüfung bleibt dabei aktiv, es wird kein `curl -k`/`--insecure` verwendet.
4. Bei Fehler nach dem Upload: Hinweis, kein Rollback, keine Wiederholung.

`concurrency: production-sftp` mit `cancel-in-progress: false` verhindert parallele Workflows derselben Gruppe; ein zweiter Push wartet, bis Upload und Migrationsaufruf des ersten abgeschlossen sind. Da der Cron keine Migrationen mehr startet, kann während eines Uploads keine Migration auf einen unvollständigen Dateibestand treffen.

## Störung: Zertifikatsfehler beim Migrationsaufruf während des Umzugs

Beobachtet im Job `deploy-webhosting`: Abbruch mit „curl: (60) SSL certificate problem: self-signed certificate“ und „Verbindungsfehler oder Timeout beim Migrationsaufruf“, danach die Warnung „Dateien sind bereits hochgeladen. Es erfolgt kein automatischer Rollback.“

Ursache: Der Migrationsaufruf war zu diesem Zeitpunkt fest auf `https://app.smart-einzug.de/migrate.php` verdrahtet (überholt, siehe unten). Im laufenden Umzug von IONOS auf den Hostinger-VPS zeigte dieser Name bereits auf den VPS. Der dortige Coolify-Proxy (Traefik) beantwortete die Anfrage mit seinem Standardzertifikat, weil für diesen Namen noch kein Let's-Encrypt-Zertifikat vorlag; curl brach deshalb beim TLS-Handshake ab, also bevor die HTTP-Anfrage überhaupt übertragen wurde. Es wurde dadurch keine Migration gestartet, weder auf dem Webhosting noch auf dem VPS; die Zertifikatsprüfung hat verhindert, dass der Aufruf den falschen Server und die falsche Datenbank erreicht.

Korrektur: Die Adresse ist nicht mehr fest verdrahtet, sondern kommt aus der Repository-Variablen `WEBHOSTING_MIGRATE_URL` (siehe Tabelle oben und `tools/check-migrate-url.sh`); die Angabe `https://app.smart-einzug.de/migrate.php` ist damit als Anweisung überholt. Die Prüfung läuft zweimal (vor dem Upload und unmittelbar vor dem Aufruf), sodass eine fehlende oder unbrauchbare Adresse keinen Upload mehr auslöst. Die Zertifikatsprüfung selbst bleibt unverändert aktiv.

Empfohlenes Vorgehen beim Umzug: Solange die Anwendung noch auf dem Webhosting läuft, `WEBHOSTING_MIGRATE_URL` vor der DNS-Umstellung auf eine technisch eindeutige, vom Umzug nicht betroffene Adresse des Webhostings setzen (siehe `docs/vps/07-cutover-checkliste.md`). Sobald die Anwendung vollständig auf dem VPS läuft, `WEBHOSTING_APP_DEPLOY` auf `false` setzen; ein Migrationsaufruf über eine Domain, die künftig auf den VPS zeigt, ist dann weder nötig noch zulässig, Migrationen laufen auf dem VPS ausschließlich über `deploy.sh` mit `php bin/migrate.php`.

## Tests (nur Testdatenbank)

`scratchpad/test_migrate_endpoint.php` (29 Prüfungen) gegen `lexsepa_e2e` über den lokalen PHP-Server: 405 mit Allow, 401 bei fehlendem, leerem, falschem Token, URL-Parameter und cron_token; 200 ohne offene Migrationen; Einspielen, exaktes einmaliges Ausführen, Überspringen bei Wiederholung; Fehler mit Abbruch, `failed`-Zeile, keine Wiederholung, Folgemigration nicht ausgeführt; manuelle Klärung und Fortsetzung; verwaistes `running` wird `unknown` und blockiert; 409 bei fremder Sperre ohne Ausführung; Login, Cron und Setup-Check starten keine Migration; leerer Server-Token liefert 500 auch bei leerem Client-Token. Der Test bricht ab, wenn die Konfiguration nicht auf `lexsepa_e2e` zeigt oder `migration_token` fehlt bzw. dem `cron_token` gleicht. Es wurde keine produktive Migration ausgeführt.

## Migration 034 (Marketingmodul, 4.63)

`034_marketing.sql` legt `marketing_lists`, `marketing_recipients`, `marketing_suppressions`, `marketing_campaigns`,
`marketing_sends` und `marketing_events` an (alle `CREATE TABLE IF NOT EXISTS`, Zeitpunkte in UTC) und setzt die
Ratenbegrenzung `marketing_rate_per_second` = 1 und `marketing_rate_per_day` = 200 in `platform_settings` (`INSERT IGNORE`,
im Adminbereich änderbar). Kein Eingriff in bestehende Tabellen. Details `docs/marketing.md`.

## Migration 033 (Kundenprofil, 4.62)

`033_support_customers.sql` trägt der Systemrolle `support` das neue Recht `support.customers` nach (Kundenprofile pflegen,
`admin-kunde.php`). Die Rechte einer Rolle liegen als JSON-Array in `platform_roles.permissions`; die Konstante
`PLATFORM_SYSTEM_ROLES` ist nur Rückfall. Idempotent über `JSON_CONTAINS`, ändert nur die Systemrolle, eigene Rollen
bleiben unverändert (bewusste Vergabe im Adminbereich). Kein Strukturwechsel.

## Migration 032 (Audit 10.09.2026)

`032_audit_indizes_eindeutigkeit.sql` legt `ix_jobs_status_finished`, `ix_jobruns_status_finished` und, NUR wenn der Bestand
keine Dubletten enthält, den eindeutigen Index `uq_collection_tenant_pi (tenant_id, stripe_payment_intent_id)` an (Prüfung
über `PREPARE` wie in 031). Enthält der Bestand Dubletten, endet die Migration erfolgreich ohne den Index; die Anwendung
schützt den Nachtrag dann nur über die Zeilensperre je Rechnung. Vorgehen: Dubletten mit der Abfrage im Kopf der Migration
ermitteln, fachlich bereinigen, `php bin/migrate.php --retry=032`. `tools/migrations-check.sh` prüft die Migration gegen den
Vorzustand (12/0 am 10.09.2026).
