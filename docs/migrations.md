# Datenbankmigrationen: Runner, Sperre, Fehlerbehandlung, Deployment

Stand 06.09.2026. Gilt für die Anwendungsdatenbank von SmartEinzug (php-ionos).

## Runner und Aufrufer

| Bestandteil | Datei | Aufgabe |
|---|---|---|
| Runner | `php-ionos/app/migrate.php` | `migrations_run()`: Sperre holen, Blockaden prüfen, offene Migrationen in Reihenfolge ausführen, Stand in `schema_migrations` führen. `migrations_status()` liest nur. |
| Endpunkt | `php-ionos/migrate.php` | Einziger Einstieg, der Migrationen ausführt. Nur POST mit Header `X-Migration-Token`. Antwort ausschließlich JSON. |
| Migrationsdateien | `php-ionos/sql/migrations/NNN_*.sql` | Wiederholbare SQL-Anweisungen (`IF NOT EXISTS`), eine Datei je Version. Per Web gesperrt (`php-ionos/sql/.htaccess`, `php-ionos/.htaccess`). |
| Stand anzeigen | `php-ionos/setup-check.php?token=<cron_token>` | Zeile "Migrationen (Stand)": eingespielt, offen, failed, unknown. Nur lesend. |
| Workflow | `.github/workflows/deploy.yml` | Nach vollständig erfolgreichem SFTP-Upload genau ein POST auf `https://app.smart-einzug.de/migrate.php`; Erfolg nur bei HTTP 200 und JSON `{"success":true}`; keine Wiederholung. |

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
   - Erneut ausführen: `DELETE FROM schema_migrations WHERE version = 'NNN';`
   - Als erledigt übernehmen (wenn alle Anweisungen nachweislich wirksam sind): `UPDATE schema_migrations SET status = 'success', finished_at = NOW(), error_text = NULL WHERE version = 'NNN';`
4. Danach den Workflow erneut laufen lassen (Re-run in GitHub Actions) oder das nächste Deployment abwarten.

Es gibt keine automatische Rückabwicklung. MariaDB führt DDL nicht transaktional aus; deshalb die Klärung am tatsächlichen Datenbankzustand.

## Deployment-Ablauf

1. Workflow prüft Secrets (`MIGRATION_TOKEN` ohne Zeilenumbruch, `migrate.php` vorhanden, `sql/.htaccess` vorhanden).
2. SFTP-Upload je Ordner (ohne `config.php`, `storage`, Logs, ohne `--delete`), zuletzt `sql/.htaccess` und `sql/migrations/`.
3. Nur bei vollständig erfolgreichem Upload: ein POST auf `migrate.php`, Auswertung von HTTP 200 und `success: true` mit `jq`, `--retry 0`, keine Weiterleitungen.
4. Bei Fehler nach dem Upload: Hinweis, kein Rollback, keine Wiederholung.

`concurrency: production-sftp` mit `cancel-in-progress: false` verhindert parallele Workflows derselben Gruppe; ein zweiter Push wartet, bis Upload und Migrationsaufruf des ersten abgeschlossen sind. Da der Cron keine Migrationen mehr startet, kann während eines Uploads keine Migration auf einen unvollständigen Dateibestand treffen.

## Tests (nur Testdatenbank)

`scratchpad/test_migrate_endpoint.php` (29 Prüfungen) gegen `lexsepa_e2e` über den lokalen PHP-Server: 405 mit Allow, 401 bei fehlendem, leerem, falschem Token, URL-Parameter und cron_token; 200 ohne offene Migrationen; Einspielen, exaktes einmaliges Ausführen, Überspringen bei Wiederholung; Fehler mit Abbruch, `failed`-Zeile, keine Wiederholung, Folgemigration nicht ausgeführt; manuelle Klärung und Fortsetzung; verwaistes `running` wird `unknown` und blockiert; 409 bei fremder Sperre ohne Ausführung; Login, Cron und Setup-Check starten keine Migration; leerer Server-Token liefert 500 auch bei leerem Client-Token. Der Test bricht ab, wenn die Konfiguration nicht auf `lexsepa_e2e` zeigt oder `migration_token` fehlt bzw. dem `cron_token` gleicht. Es wurde keine produktive Migration ausgeführt.
