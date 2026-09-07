# Datenbank: Aufbau, Betrieb und Prüfung gegen das MariaDB-Kompendium

Diese Dokumentation beschreibt die Datenbankschicht von SmartEinzug (php-ionos) anhand des tatsächlichen Codes und Schemas und ordnet den Stand den Kapiteln des Lehrwerks "Datenbanken und MariaDB: Vom Fundament zur Expertise" (Müller Holding AG, 06.09.2026, im Folgenden "Kompendium") zu. Alle Aussagen stammen aus dem Repository (Schema, PHP-Code, Konfigurationsvorlagen, Dokumentation); Werte, die nur ein laufender Server zeigen kann, sind ausdrücklich als offen gekennzeichnet, mit dem Prüfbefehl dafür. Es wurden keine Änderungen an Code oder Schema vorgenommen; alle Vorschläge sind Vorschläge und erst nach Freigabe der Geschäftsführung umzusetzen. Für die vollständige Tabellenbeschreibung gilt das automatisch erzeugte Datenwörterbuch, `docs/entwickler/datenwoerterbuch.md`; diese Datei wird hier nur referenziert, nicht wiederholt.

## Inhalt

1. Datenbanksystem und Verbindung
2. Schema-Überblick
3. Transaktionen, Sperren, Parallelität
4. Migrationen
5. Sicherung und Wiederherstellung
6. Betrieb und Überwachung
7. Prüfung gegen das Kompendium
8. Empfehlungsliste und offene Prüfpunkte



## 1. Datenbanksystem und Verbindung

### 1.1 Betriebsumgebung

SmartEinzug läuft in zwei Umgebungen mit unterschiedlicher Datenbankanbindung:

- **Hostinger-VPS (Coolify, Zielumgebung):** MariaDB ist keine eigene Ressource im SmartEinzug-Docker-Stack, sondern eine private Coolify-Datenbankressource im Docker-Netz `coolify` (`deploy/vps/docker-compose.yml:16-20`, `deploy/vps/docker-compose.yml:320`, `deploy/vps/README.md:192`). Sie hat keinen veröffentlichten Port; der Cutover-Katalog verlangt ausdrücklich den Nachweis, dass ein Portscan von außen fehlschlägt (`docs/vps/07-cutover-checkliste.md:135-137`: "Datenbankbenutzer der Anwendung auf dem VPS hat keine Rechte über die eigene Datenbank hinaus (kein `GRANT ALL` auf `*.*`)" und "Kein veröffentlichter Datenbank- oder Redis-Port aus dem Internet erreichbar (`nc -zv HIER-VPS-IP 3306` ... schlägt von außen fehl)"). Die Anwendungscontainer erreichen die Datenbank ausschließlich über das interne Docker-Netz.
- **IONOS-Webhosting (Bestandsumgebung):** klassischer Hosting-Datenbankzugang, Zugangsdaten aus dem IONOS-Kundenbereich (`php-ionos/app/config.example.php:29-38`).

**Version:** Die VPS-Dokumentation nennt durchgängig MariaDB 11.8.9 als eingerichtete Version (`docs/vps/01-architektur.md:137`, `docs/vps/04-datenbankmigration.md:7`, `docs/vps/08-hostinger-coolify.md:30`, `docs/vps/08-hostinger-coolify.md:223`); dieser Wert stammt aus der Betriebsdokumentation, nicht aus einer in dieser Prüfung selbst ausgeführten Live-Abfrage. **Prüffrage (auf dem tatsächlichen Server auszuführen):** `SELECT VERSION();`

### 1.2 Konfigurationsschlüssel (keine Werte)

`php-ionos/app/config.example.php` ist die Vorlage für `app/config.php` (wird nicht eingecheckt). Relevante Schlüssel, nur als Struktur, ohne Werte:

```php
'db' => [
    'host'    => '...', // IONOS DB-Hostname bzw. Containername der Coolify-MariaDB
    'port'    => 3306,
    'name'    => '...',
    'user'    => '...',
    'pass'    => '...',
    'charset' => 'utf8mb4',
],
```

(`app/config.example.php:31-38`). Auf dem VPS ist `db.host` laut Kommentar der Containername der Coolify-MariaDB im internen Netz, nie die VPS-IP oder ein veröffentlichter Port. `db.name`/`db.user`/`db.pass` stammen aus Coolify. Zusätzlich verlangt `app_secret` (mindestens 64 Zufallszeichen) für die Feldverschlüsselung (Abschnitt 2.4).

### 1.3 Verbindungsaufbau (`bootstrap.php`, Funktion `db()`)

```php
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
```

(`php-ionos/app/bootstrap.php:253-269`). Bewertung der Optionen:

- `PDO::ATTR_ERRMODE_EXCEPTION`: jeder Datenbankfehler wird zur Ausnahme, kein stilles Weiterlaufen mit `false`-Rückgabewerten. Konsequent im gesamten Code genutzt (durchgängig `try { ... } catch (PDOException $e)` bzw. `catch (Throwable $e)` an den Aufrufstellen).
- `PDO::ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`: assoziative Arrays als Standard, spart `PDO::FETCH_ASSOC` an jeder Abfrage.
- `PDO::ATTR_EMULATE_PREPARES => false`: **echte** serverseitige Prepared Statements statt client-seitiger Emulation. Das schützt konsequenter vor SQL-Injection (Parameter werden nie in den SQL-Text eingebettet) und vermeidet Typumdeutungen durch den PHP-Treiber.
- Zeichensatz `utf8mb4` steht im DSN selbst (`charset=%s` in der DSN-Zeichenkette); PDO/mysqlnd setzt damit den Verbindungszeichensatz beim Verbindungsaufbau, ein zusätzliches `SET NAMES` ist nicht nötig.
- **Nicht gesetzt:** kein `PDO::ATTR_PERSISTENT`, keine explizite Zeitzonen-Anweisung (`PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"` o. ä.), keine TLS-Optionen (`PDO::MYSQL_ATTR_SSL_CA` etc.) für die Verbindung zur Datenbank. Auf dem VPS ist die Strecke Anwendungscontainer zu Coolify-MariaDB ausschließlich internes Docker-Netz ohne veröffentlichten Port; eine zusätzliche Transportverschlüsselung zwischen den Containern ist im Code nicht erzwungen (siehe Abschnitt 7, Zeile "Transportverschlüsselung").

### 1.4 Verbindungsverhalten der Worker (Dauerprozesse)

`db()` hält die PDO-Instanz als **statische Variable je PHP-Prozess** (Singleton pro Prozess, nicht global über Prozesse hinweg). Für kurzlebige Webanfragen ist das unproblematisch (eine Verbindung je Request). Scheduler und Worker (`php-ionos/bin/scheduler.php`, `php-ionos/bin/worker.php`) laufen dagegen als **Dauerprozesse** (PID 1, siehe Worker-Signalmodell in `CLAUDE.md`); `bin/scheduler.php:27` ruft `db()` direkt zu Prozessstart auf und hält dieselbe Verbindung über die gesamte Laufzeit. Im Code wurde **keine** explizite Behandlung für "MySQL server has gone away" / Verbindungsabbruch nach Leerlauf gefunden (kein `try`/Reconnect um `db()` herum, kein `PDO::ATTR_PERSISTENT`, kein periodisches `SELECT 1`, das die Verbindung aktiv hält). Ein Verbindungsabbruch durch `wait_timeout` der Datenbank oder einen Netzwerk-Hop würde als `PDOException` in der laufenden Verarbeitung auftreten; die Warteschlangen-Jobs würden sie über die generische Retry-Logik (Abschnitt 3.4) auffangen, ein synchroner Codepfad außerhalb der Queue jedoch nicht automatisch. Als Prüfpunkt aufgenommen (Abschnitt 7/8).

### 1.5 Zeichensatz, Kollation, Speicher-Engine

- Zeichensatz **utf8mb4**, Kollation **utf8mb4_unicode_ci** auf jeder einzelnen `CREATE TABLE`-Anweisung in `php-ionos/sql/schema.sql` (z. B. Zeile 28, 76, 111 … durchgängig für alle 45 Tabellen), konsistent mit dem Verbindungszeichensatz aus 1.3.
- Speicher-Engine **InnoDB** durchgängig (`ENGINE=InnoDB` auf jeder Tabelle, `sql/schema.sql`), transaktionsfähig, referenzielle Integrität grundsätzlich möglich (siehe Abschnitt 2.3 zu tatsächlich gesetzten Fremdschlüsseln).

### 1.6 Zeitzonenverhalten

Dies ist ein Bereich mit uneinheitlicher Praxis im Code, deshalb im Detail:

- `date_default_timezone_set($GLOBALS['config']['timezone'] ?? 'Europe/Berlin')` (`bootstrap.php:27`) setzt nur die **PHP-Zeitzone** des jeweiligen Prozesses. Sie wirkt auf PHP-Funktionen (`date()`, `DateTime` ohne explizite Zone), **nicht** auf die MariaDB-Sitzung.
- Alle `DATETIME`-Spalten im Schema sind **ohne Zeitzoneninformation** (Typ `DATETIME`, kein `TIMESTAMP` mit Zeitzonenkonvertierung). Was in ihnen steht, hängt davon ab, mit welcher SQL-Funktion geschrieben wurde:
  - Viele Spalten verwenden `DEFAULT CURRENT_TIMESTAMP` (bzw. `... ON UPDATE CURRENT_TIMESTAMP`), das ist serverseitiges `NOW()` zum Schreibzeitpunkt, in der **Sitzungszeitzone der Datenbankverbindung**.
  - Monitoring (`app/monitor.php`) schreibt/liest bewusst **UTC**: `function mon_utc(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }` (`monitor.php:83-86`), ebenso die Warteschlange `queue_utc()` (`app/queue.php:79`) und die Vormerkungen (`app/interest.php`, `UTC_TIMESTAMP()` an mehreren Stellen, z. B. Zeile 128, 154, 189, 201 f., 223, 250, 274, 324, 348).
  - Die Bereinigung des Audit-Logs vergleicht dagegen gegen **`NOW()`** ohne Zeitzonen-Funktion: `'DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . $d . ' DAY) LIMIT 5000'` (`app/audit.php:84`); da auch die schreibende Seite (`DEFAULT CURRENT_TIMESTAMP`) `NOW()`-Semantik hat, ist das **innerhalb** von `audit_log` konsistent, weicht aber vom UTC-Ansatz der übrigen Module ab.
  - Synchronisationszeiten (`sync_runs.started_at`) sind laut Schema-Kommentar bewusst **lokale Zeit wie `sync_state`** (`sql/schema.sql:371`: `-- lokale Zeit wie sync_state (NOW())`), ebenfalls ein bewusster Gegensatz zu den UTC-Zeitstempeln im Monitoring.
  - Von Lexware Office übernommene Zeitstempel werden dagegen aktiv nach UTC konvertiert, bevor sie gespeichert werden (`app/sync.php:52-59`: `(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')`).
- Was die **Datenbank selbst** als Sitzungs- bzw. Systemzeitzone verwendet (`@@session.time_zone`, `@@system_time_zone`), ist im Repository nicht gesetzt und nicht dokumentiert; die MariaDB-Instanz ist eine externe Coolify-Ressource außerhalb dieses Docker-Stacks, ihre `TZ`/`time_zone`-Einstellung liegt außerhalb des hier geprüften Codes. **Prüfbefehle:** `SHOW VARIABLES LIKE 'time_zone';`, `SHOW VARIABLES LIKE 'system_time_zone';`, `SELECT NOW(), UTC_TIMESTAMP();` (Differenz zeigt die wirksame Sitzungszeitzone).

**Zusammenfassung:** Der Code kennt zwei bewusste Konventionen (UTC für Monitoring/Queue/Vormerkung/Lexware-Import, lokale `NOW()`-Zeit für Sync-Zustand/Audit/die meisten `CURRENT_TIMESTAMP`-Spalten), aber keine einheitliche, dokumentierte Regel für neue Tabellen. Als Prüfpunkt in Abschnitt 7 aufgenommen.



## 2. Schema-Überblick

### 2.1 Module und Tabellen

Das vollständige, automatisch erzeugte Datenwörterbuch (`docs/entwickler/datenwoerterbuch.md`) beschreibt alle 45 Tabellen einzeln (Spalten, Typen, Kommentare); diese Übersicht ordnet sie nur den fachlichen Modulen zu, ohne die Feldbeschreibung zu wiederholen:

| Modul | Tabellen |
|---|---|
| Firmen und Zugänge | `organizations`, `users`, `organization_members`, `invitations`, `registration_requests`, `user_recovery_codes`, `trusted_devices`, `login_attempts` |
| Lexware-/Stripe-Fachdaten je Firma | `customers`, `customer_ibans`, `iban_history`, `sepa_mandates`, `invoices`, `payment_collections`, `collection_attempts`, `mandate_files`, `mandate_requests`, `collection_rules` |
| Übernahme bestehender Stripe-Einzüge | `stripe_imports`, `stripe_import_items` |
| Plattform (Tarife, Abrechnung, Recht, Interesse) | `plans`, `platform_settings`, `integration_providers`, `legal_documents`, `legal_acceptances`, `interest_registrations`, `support_sessions`, `support_tickets`, `support_ticket_messages`, `funnel_events` |
| Betrieb, Hintergrundverarbeitung, Beobachtung | `job_runs`, `monitor_checks`, `monitor_daily`, `monitor_requests`, `monitor_incidents`, `monitor_incident_updates`, `jobs`, `worker_heartbeats`, `sync_runs`, `sync_state`, `api_circuits`, `webhook_events`, `schema_migrations`, `audit_log` |

### 2.2 Schlüsselwahl

Der weit überwiegende Teil der fachlichen Tabellen verwendet **`CHAR(36)` mit einer zufälligen UUID v4** als Primärschlüssel, erzeugt in PHP:

```php
function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
```

(`bootstrap.php:272-278`). Ausnahmen mit anderer Schlüsselwahl:

- **Fortlaufende Zahlen** (`BIGINT UNSIGNED AUTO_INCREMENT`): `login_attempts`, `audit_log`, `funnel_events`, `monitor_checks`, durchweg Protokoll-/Zeitreihentabellen mit hohem Schreibaufkommen und rein chronologischem Zugriff, kein fachlicher Fremdbezug über die ID selbst nötig.
- **Natürlicher Schlüssel:** `plans.code`, `platform_settings.key`, `api_circuits.api`, `integration_providers.code` (jeweils `VARCHAR` PK), `worker_heartbeats.worker_id` (`VARCHAR` PK), `monitor_requests.minute` (`DATETIME` PK), `monitor_daily` (zusammengesetzter PK `component, day`), `sync_state.tenant_id` (1:1 zur Firma).

Die UUID-als-Clustered-Primärschlüssel-Frage wird in Abschnitt 7 gegen Kompendium-Kapitel 6.2 bewertet.

### 2.3 Fremdschlüssel

Von 45 Tabellen tragen **23** mindestens eine `CONSTRAINT fk_...`-Zeile, **22** keine. Ohne Fremdschlüssel (Bezug, falls vorhanden, ausschließlich über Anwendungscode geprüft):

`plans`, `organizations` (Wurzel, kein Elternbezug), `users` (Wurzel), `login_attempts`, `audit_log`, `job_runs`, `monitor_checks`, `monitor_daily`, `monitor_requests`, `monitor_incidents`, `jobs`, `worker_heartbeats`, `api_circuits`, `webhook_events`, `funnel_events`, `support_sessions`, `collection_attempts`, `platform_settings`, `integration_providers`, `schema_migrations`, `legal_documents`, `legal_acceptances`.

Zu unterscheiden:

- **Bewusst ohne Fremdschlüssel, weil Protokoll-/Zeitreihentabelle:** `audit_log` trägt einen expliziten Schema-Kommentar dazu: `-- Audit-Log: ohne Fremdschlüssel. Aufbewahrung 90 Tage (audit_cleanup, config audit.retention_days), danach gelöscht.` (`sql/schema.sql:134`). Sinnvoll, weil ein Audit-Eintrag auch nach Löschung des referenzierten Objekts lesbar bleiben soll (z. B. `legal_acceptances.user_email` trägt aus demselben Grund ausdrücklich die E-Mail-Adresse redundant: "Nachweis bleibt lesbar, auch wenn der Benutzer gelöscht wird", `sql/schema.sql` bei `legal_acceptances`).
- **Fachlicher Bezug vorhanden, aber nur per Code geprüft:** `jobs.tenant_id` (Bezug zu `organizations`), `collection_attempts.tenant_id`/`invoice_id`/`collection_id`, `support_sessions.organization_id`, `legal_acceptances.organization_id`/`document_id`. Hier gibt es einen fachlichen Elternbezug, aber keine Datenbank-Constraint, die ihn erzwingt. Für Protokoll- und Warteschlangentabellen mit hohem Schreibdurchsatz ist der Verzicht auf FK-Prüfung beim Schreiben eine bewusste Abwägung (weniger Sperraufwand beim Insert); für `legal_acceptances` (Rechtsnachweis) ist die fehlende FK-Absicherung dagegen eher ein Homogenitätsthema als ein Leistungsthema, da diese Tabelle kein hohes Schreibaufkommen hat.
- **Mit Fremdschlüssel:** u. a. `customers`, `customer_ibans`, `iban_history`, `sepa_mandates`, `invoices`, `payment_collections`, `mandate_files`, `mandate_requests`, `sync_state`, `sync_runs`, `integrations`, `organization_members`, `invitations`, `registration_requests`, `trusted_devices`, `user_recovery_codes`, `stripe_imports`, `stripe_import_items`, `interest_registrations`, `monitor_incident_updates`, `collection_rules`, überwiegend mit `ON DELETE CASCADE` an `organizations`/`customers`, teils `ON DELETE SET NULL` (`invoices.customer_id` → `customers`) bzw. ohne `ON DELETE`-Klausel dort, wo ein Löschen des Elternobjekts fachlich ausgeschlossen sein soll (`payment_collections.mandate_id`, `.customer_iban_id`).

### 2.4 Indizes je Zugriffsmuster

Diese Einschätzung beruht auf einer Durchsicht der `WHERE`-Klauseln in `php-ionos/app/*.php` gegen die im Schema definierten Indizes.

**`payment_collections`** (Indizes: `ix_collection_tenant(tenant_id)`, `ix_collection_pi(stripe_payment_intent_id)`, `ix_collection_scheduled(is_scheduled, scheduled_submitted, scheduled_date)`):
- Einreichung fälliger Einzüge (`app/collections.php:1731,1743,1750`, Filter auf `is_scheduled`, `scheduled_submitted`, `scheduled_date`/`submit_not_before`) passt zu `ix_collection_scheduled`.
- Sperr-/Kontingentabfragen nach `tenant_id` UND `stripe_status` (`app/invoice_source_switch.php:76`: `WHERE tenant_id = ? AND stripe_status IN ('scheduled','submitting','processing')`; ähnlich `app/plans.php:191`) finden nur `ix_collection_tenant(tenant_id)` als Präfix, keinen zusammengesetzten Index über `tenant_id, stripe_status`. Bei wachsendem Datenbestand pro Firma prüft MariaDB dafür mehr Zeilen als nötig.

**`invoices`** (Indizes: `uq_invoice_tenant_lexoffice(tenant_id, lexoffice_invoice_id)`, `ix_invoice_customer(customer_id)`):
- Offene-Rechnungen-Abfragen nach `tenant_id` und `lexoffice_status IN ('open','overdue')` treten mehrfach auf (`app/sync.php:240`, `:359`, `:365`); sie können nur das `tenant_id`-Präfix des Unique-Index nutzen, `lexoffice_status` selbst ist nicht indiziert.
- Zeilenweiser Zugriff über `id` + `tenant_id` (`app/collections.php:646,840,873,1044,1853`) läuft über den Primärschlüssel, unproblematisch.

**`customers`** (Indizes: `uq_customer_tenant_lexoffice(tenant_id, lexoffice_contact_id)`, `ix_customer_number(tenant_id, customer_number)`):
- Zugriffe sind fast durchweg `id` + `tenant_id` (Primärschlüssel-Zugriff, `app/collections.php`, `app/customer_settings.php`, `app/mandates.php`, `app/mandate_files.php`), abgedeckt über den Primärschlüssel; kein eigener Bedarf an einem zusätzlichen zusammengesetzten Index erkennbar.

**`jobs`** (Indizes: `ix_jobs_pick(status, type, available_at, priority)`, `ix_jobs_tenant(tenant_id, created_at)`, `ix_jobs_status_created(status, created_at)`, `ix_jobs_locked(locked_by, heartbeat_at)`, `ix_jobs_correlation(correlation_id)`, `uq_jobs_dedupe(dedupe_key)`):
- Die Job-Abholung (`app/queue.php:182-183`: `WHERE status IN ('queued','retry') AND available_at <= ? AND type IN (...) ORDER BY priority ASC, available_at ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED`) ist gut auf `ix_jobs_pick` zugeschnitten.
- Die Abfrage nach laufenden Jobs einer Firma und eines Typs (`app/queue.php:570`: `WHERE tenant_id = ? AND type = ? AND status IN (...) ORDER BY created_at DESC LIMIT 1`) nutzt nur das `tenant_id`-Präfix von `ix_jobs_tenant`; `type`/`status` werden danach ungestützt gefiltert.

**`audit_log`** (Indizes: `ix_audit_tenant_time(tenant_id, created_at)`, `ix_audit_user_time(user_id, created_at)`, `ix_audit_action(action)`):
- Anzeige/Export je Firma (`app/audit.php:95,104`: `WHERE tenant_id = ? ORDER BY id DESC`) ist über `ix_audit_tenant_time` abgedeckt.
- Die Bereinigung (`app/audit.php:84`: `DELETE ... WHERE created_at < ... LIMIT 5000`) filtert **ausschließlich** nach `created_at`, ohne `tenant_id`/`user_id`. Dafür existiert kein Index mit `created_at` als führender Spalte; der tägliche Wartungslauf muss die gesamte Tabelle nach infrage kommenden Zeilen durchsuchen, auch wenn er dank `LIMIT 5000` nur begrenzt viele Zeilen tatsächlich löscht.

**`monitor_checks`** (Index `ix_mon_component_time(component, checked_at)`) passt zu den komponentenweisen Abfragen im Monitoring.

### 2.5 Geldbeträge: Datentypen

Zwei bewusst getrennte Darstellungen:

- **`INT` als Cent-Betrag** für alle selbst erzeugten Zahlungsbewegungen: `plans.price_cents`, `payment_collections.amount_cents`, `collection_attempts.amount_cents`, `stripe_import_items.amount_cents`/`amount_refunded_cents`, `collection_rules.max_amount_cents`. Das entspricht der Cent-Darstellung, die Stripe selbst in seiner API verwendet (keine Rundungsfehler durch Fließkomma, kein Nachkommastellen-Mapping nötig).
- **`DECIMAL(10,2)`** für aus Lexware Office übernommene Rechnungsbeträge: `invoices.total_gross_amount`, `invoices.open_amount`. Diese Werte kommen als Dezimalzahl von der Lexware-API und werden unverändert in dieser Form gehalten.

Diese Aufteilung ist sachlich begründet (eigene Zahlungslogik in Cent wie beim Zahlungsdienstleister, externe Rechnungsbeträge in der vom Quellsystem gelieferten Form) und wird in Abschnitt 7 als erfüllt bewertet, mit dem Hinweis, dass ein Entwickler beide Konventionen kennen muss, um sie nicht versehentlich zu vermischen.

### 2.6 Externe IDs

`lexoffice_contact_id`, `lexoffice_invoice_id` (`CHAR(36)`, Lexware verwendet selbst UUIDs), `stripe_payment_intent_id`, `stripe_charge_id`, `stripe_customer_id`, `stripe_mandate_id`, `stripe_account_id`, `stripe_price_id`, `platform_stripe_customer_id`, `platform_stripe_subscription_id` (`VARCHAR(255)` durchgängig, ausreichend für alle bekannten Stripe-ID-Formate).

### 2.7 Verschlüsselte Spalten

`app/crypto.php` implementiert AES-256-GCM (`encrypt_value()`/`decrypt_value()`), Schlüssel aus `app_secret` per SHA-256 abgeleitet (`crypto_key()`), Format `base64(iv | tag | ciphertext)`. Eingesetzt für:

- `integrations.lexoffice_api_key_encrypted`, `integrations.stripe_secret_key_encrypted`, `integrations.stripe_webhook_secret_encrypted` (Zugangsdaten der Kunden zu Lexware/Stripe).
- `users.totp_secret_encrypted` (2FA-Geheimnis).

**Nicht verschlüsselt** in der Datenbank: `customer_ibans.iban`/`account_holder_name` (Klartext, `VARCHAR(34)`/`VARCHAR(255)`), obwohl es sich um Bankverbindungsdaten handelt. Bewertung in Abschnitt 7 (Kompendium 12.3).

### 2.8 Aufbewahrung und Bereinigung

| Tabelle/Bereich | Frist | Quelle |
|---|---|---|
| `audit_log` | 90 Tage Standard (`AUDIT_RETENTION_DAYS = 90`), konfigurierbar über `audit.retention_days`, Mindestwert 30 Tage erzwungen | `app/audit.php:70,73,80-89` |
| `monitor_checks` (Rohmessungen) | 14 Tage | `app/monitor.php:23`, `monitor_cleanup()` Zeile 897-906 |
| `monitor_requests` (Minutenzähler) | 30 Tage | `app/monitor.php:24` |
| `monitor_daily` (Tagesaggregate) | 400 Tage | `app/monitor.php:25` |
| `trusted_devices` | Gültig 90 Tage ab Erstellung (schema.sql-Kommentar Zeile 212: "wird nie verlängert"), Bereinigung 30 Tage nach Widerruf/Ablauf | `app/devices.php:321-328` |
| `interest_registrations` | 30 Tage für `pending`/`unsubscribed`/`confirmed`-benachrichtigt; gesperrte Einträge (`blocked_at` gesetzt) bleiben dauerhaft erhalten | `app/interest.php:45,419-425` |
| `jobs` (abgeschlossen/abgebrochen/dauerhaft geschlossen) | Standard 30 Tage, Mindestwert 7 Tage | `app/jobs.php:46`, `app/queue.php:419` |

Keine erkennbare automatische Bereinigung für `funnel_events`, `login_attempts`, `webhook_events`, `stripe_imports`/`stripe_import_items`, `support_tickets`/`-messages` im durchsuchten Code (kein `DELETE FROM` mit Fristbezug gefunden); als offener Prüfpunkt in Abschnitt 8 aufgenommen, sofern dies fachlich gewünscht ist.



## 3. Transaktionen, Sperren, Parallelität

### 3.1 Explizite Transaktionen

Acht Fundstellen mit `beginTransaction()`:

| Datei:Zeile | Zweck |
|---|---|
| `app/auth.php:1067` | Registrierung (Anlegen von Benutzer/Firma) |
| `app/auth.php:1209` | Firma anlegen (verschachtelbar, prüft `$outer`, siehe 3.2) |
| `app/auth.php:1510` | Fortsetzen einer Registrierungsanfrage, mit vorausgehendem `SELECT ... FOR UPDATE` auf `registration_requests` |
| `app/collections.php:1223` | Einzug einreichen: Tenant-Zeilensperre über `organizations` (siehe 3.3) |
| `app/customer_settings.php:94` | Kundendaten/IBAN-Änderung |
| `app/invoice_source_switch.php:134` | Wechsel der Rechnungsquelle einer Firma |
| `app/mandate_requests.php:308` | Digitale Mandatsanforderung |
| `app/queue.php:180` | Job-Reservierung durch einen Worker |

### 3.2 Wiedereintrittsfähigkeit (verschachtelte Aufrufe)

Mehrere Funktionen prüfen `$pdo->inTransaction()`, bevor sie selbst eine Transaktion öffnen, und committen/rollen nur zurück, wenn sie selbst die äußere Transaktion sind (`$ownTransaction`/`$outer`-Muster, z. B. `app/collections.php:1218-1246`, `app/auth.php:1206-1230`). Das verhindert, dass ein innerer Aufruf eine vom Aufrufer offene Transaktion vorzeitig beendet.

### 3.3 Sperren: `SELECT ... FOR UPDATE`

| Datei:Zeile | Tabelle | Zweck |
|---|---|---|
| `app/auth.php:1513` | `registration_requests` | Verhindert doppeltes Abschließen derselben Registrierungsanfrage |
| `app/collections.php:1044` | `invoices` (nur wenn `$pdo->inTransaction()`) | Rechnungszeile für die Dauer des Einzugsvorgangs sperren |
| `app/collections.php:1226` | `organizations` | **Firmenweite Sperre** für den gesamten Einzugsvorgang |
| `app/queue.php:183` | `jobs` | Job-Reservierung mit `FOR UPDATE SKIP LOCKED` (konkurrierende Worker überspringen bereits gesperrte Zeilen, statt zu warten) |

Der Kommentar im Code zur Tenant-Sperre ist ausdrücklich: *"Firmenzeile sperren: verhindert doppelte Einzüge derselben Rechnung und Kontingentüberschreitungen durch parallele Anfragen. Die Sperre gilt bis zum Commit am Ende (auch der Stripe-Aufruf liegt darin, er dauert nur wenige Sekunden)."* (`app/collections.php:1218-1221`). Das ist ein bewusst gewähltes, einfaches Muster (ein pessimistischer Mutex je Firma über eine ohnehin vorhandene Zeile), hat aber eine Nebenwirkung, die in Abschnitt 7 bewertet wird: Die Sperrdauer hängt an der Antwortzeit von Stripe, nicht nur an reiner Datenbankarbeit.

### 3.4 Idempotenz und eindeutige Schlüssel

- `collection_attempts.idempotency_key` (`UNIQUE KEY uq_attempt_key`): wird **vor** jedem Stripe-Aufruf über eine **eigene** Datenbankverbindung geschrieben, damit der Versuch auch bei Abbruch der umgebenden Transaktion des eigentlichen Einzugs erhalten bleibt (Schema-Kommentar `sql/schema.sql:664-671`).
- `jobs.dedupe_key` (`UNIQUE KEY uq_jobs_dedupe`): verhindert, dass derselbe fachliche Auftrag mehrfach gleichzeitig in der Warteschlange steht.
- `webhook_events` (Primärschlüssel = Stripe-Event-ID): Schutz gegen mehrfache Verarbeitung wiederholt zugestellter Webhook-Ereignisse.

### 3.5 Sperren außerhalb von Zeilen (Tabellen als Zustandsspeicher, Redis, `GET_LOCK`)

- `sync_state` (Spalten `lock_until`, `lock_owner`): Sperre je Firma für Synchronisationsschritte, damit Browser- und Cron-Aufrufe denselben Lauf fortsetzen statt zu kollidieren.
- Migrations-Sperre über MariaDBs `GET_LOCK('smarteinzug_migrations_<hash>', 0)` (`app/migrate.php`, Funktionen `migrations_lock_acquire()`/`migrations_lock_release()`/`migrations_lock_held_elsewhere()`): datenbankweite, verbindungsgebundene Sperre ohne Ablauffrist, unabhängig von den InnoDB-Zeilensperren aus 3.3 und von `sync_state` (`docs/migrations.md:50`).
- Redis ergänzt optional Sperren/Ratenbegrenzung für die Warteschlange (`app/queue.php`-Kommentar Zeile 6-9), ersetzt die Datenbank aber nicht: ohne Redis läuft alles über MariaDB.

### 3.6 Isolationsstufe

Im durchsuchten Code (`app/*.php`, `bin/*.php`, `sql/*.sql`) findet sich **keine** explizite `SET TRANSACTION ISOLATION LEVEL`- oder `SET SESSION ...`-Anweisung. Es gilt damit der MariaDB-Standard **REPEATABLE READ** für InnoDB (Kompendium 5.3), ohne dass dies im Repository dokumentiert oder durch einen Test abgesichert wäre. **Prüfbefehl:** `SHOW VARIABLES LIKE 'transaction_isolation';` (bzw. `tx_isolation` auf älteren Versionen).

### 3.7 Deadlock-Behandlung und Retry

- Kein Code fängt gezielt SQLSTATE `40001` oder die MariaDB-Fehlernummer 1213 ab und wiederholt dieselbe Transaktion. Der einzige Treffer für "deadlock" im Anwendungscode ist die Fehlerkategorisierung im Monitoring: `if (preg_match('/sqlstate|database|datenbank|deadlock/', $msg)) return 'database';` (`app/monitor.php:269`); das ordnet einen Fehler nur einer Anzeige-Kategorie zu, wiederholt aber nichts automatisch.
- **Warteschlangen-Jobs** (`app/queue.php`) werden bei **jeder** `PDOException` (nicht nur Deadlocks) generisch nach der Backoff-Tabelle `QUEUE_BACKOFF = [60, 300, 900, 3600]` Sekunden erneut versucht, begrenzt durch `max_attempts` je Jobtyp (`app/queue.php:98-108`, z. B. `sync_run` 6 Versuche, `collections_due` 5 Versuche). Das deckt einen Deadlock in einem Warteschlangen-Job indirekt ab (der Job landet einfach erneut in der Warteschlange), ist aber keine gezielte, sofortige Deadlock-Wiederholung innerhalb derselben Anfrage.
- **Synchrone Webanfragen** außerhalb der Warteschlange (z. B. `submit_collection()` in `app/collections.php`, direkt aus der Weboberfläche ausgelöst) haben **keine** eigene Wiederholungslogik bei einem Deadlock; ein Deadlock würde dort als Ausnahme bis zur Anwendungsebene durchgereicht und dem Benutzer als Fehler angezeigt.



## 4. Migrationen

Ausführlich dokumentiert in `docs/migrations.md`; hier nur die für diese Prüfung relevante Zusammenfassung.

- **Runner:** `php-ionos/app/migrate.php` (`migrations_run()` führt aus, `migrations_status()` liest nur), Buchführung in Tabelle `schema_migrations` (`version`, `filename`, `status` `success`/`running`/`failed`/`unknown`, `started_at`, `finished_at`, `error_text`, `applied_by`). Für ältere, vor Einführung des Runners eingespielte Migrationen dient `migrations_markers()` (`app/migrate.php:29-49`) als Fallback-Erkennung anhand einer je Version bekannten Tabelle/Spalte.
- **Sperre:** `GET_LOCK` auf einen datenbankspezifischen Namen (`migrations_lock_name()`, `md5($db_name)`-basiert), 0 Sekunden Wartezeit: Belegt bedeutet sofortiger Abbruch (HTTP 409 am Endpunkt), kein Warten, kein teilweises Ausführen.
- **Aufrufer:** ausschließlich `php-ionos/migrate.php` (POST, Header `X-Migration-Token`, verglichen mit `hash_equals()`) bzw. auf dem VPS `bin/migrate.php` über `deploy.sh`. Cron, Login, `setup-check.php`, Webhooks lösen laut `docs/migrations.md:15-20` **keine** Migration mehr aus (mit Testnachweis `test_migrate_endpoint.php`, Test 7).
- **Additivitätsregel** (`docs/migrations.md`, Abschnitt "Freigabekriterium: nur additive, rückwärtsverträgliche Migrationen"): kein `DROP TABLE`/`DROP COLUMN`/`RENAME` an bestehenden Objekten, kein verengender Typwechsel, keine neue `NOT NULL`-Spalte ohne Vorgabewert, keine Datenumschreibung, die der alte Code nicht versteht, wiederholbar formuliert (`IF NOT EXISTS`, `INSERT IGNORE`). 24 Migrationsdateien (`001_...sql` bis `024_invoice_source_switch.sql`) folgen diesem Muster; `sql/schema.sql` selbst enthält dieselben `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`-Anweisungen wie die einzelnen Migrationen, damit eine Neuinstallation und ein migrierter Bestand strukturell identisch enden.
- **Ablauf im Deployment (VPS):** Migration läuft **isoliert** mit dem neuen Code (`docker compose run --rm --no-deps`), **bevor** die laufenden Container gewechselt werden (Reihenfolge Candidate-Prüfung, dann Migration, dann Cutover, `docs/migrations.md` "Freigabekriterium"-Abschnitt sowie `CLAUDE.md`). Schlägt Candidate-Prüfung oder Migration fehl, bleiben die laufenden Container unverändert; `rollback.sh` wechselt bei einem Rollback nur den Anwendungscode, **nie** das Schema. Die Additivitätsregel ist der eigentliche Kompatibilitätsschutz, nicht ein Datenbankauszug unmittelbar vor der Migration (den es laut derselben Quelle nicht gibt, siehe Abschnitt 5).
- **Fehlerklärung:** `failed`/`unknown` blockieren jeden weiteren Lauf, keine automatische Wiederholung; Klärung nur manuell über `schema_migrations` (`DELETE` für erneutes Ausführen, `UPDATE ... status='success'` für nachweislich wirksame Teiländerungen), da MariaDB DDL nicht transaktional ausführt.



## 5. Sicherung und Wiederherstellung

### 5.1 Aktiver Backupweg

Produktiv zuständig ist **Coolify**, nicht ein Skript in diesem Repository: Coolify-Backupplan `id=1`, `enabled=yes`, `save_s3=yes`, Zeitplan `0 3 * * *` (täglich 03:00), Ziel S3-Storage `id=1` ("SmartEinzug Backups", Bucket `smarteinzug`, Endpoint `https://fsn1.your-objectstorage.com`), laut `docs/betrieb-migration-vps.md:642-643`. Das produktive Datenvolume der MariaDB liegt lokal als Docker-Volume; das Backup ist der eigentliche Weg zu einer vom Server unabhängigen Kopie.

### 5.2 Ergänzende, nicht aktive Bestandteile

- `deploy/vps/backup/backup.sh`: laut `docs/betrieb-migration-vps.md:272,624` eine **erhaltene Ausweichlösung**, nicht Teil des aktiven Appstacks; seine Standardrotation (14 Tage), optionale Verschlüsselung, `rclone`/`curl`-Upload sind ausdrücklich **nicht** als Einstellungen des tatsächlich eingesetzten Coolify-Plans zu übernehmen (Zeile 646).
- Manuelle, lokal abgelegte Dumps (`docs/betrieb-migration-vps.md:639-641`): `ionos-production-import.sql` (765.449 Byte), `post-import-20260906-212031.sql` (731.967 Byte), `pre-first-deploy-20260906-211344.sql` (1.407 Byte), punktuelle Sicherungen aus dem Umzug, keine laufende Sicherung.
- `php-ionos/bin/backup-record.php`: nimmt **nur** Ergebnis, Größe und Prüfsummen-Kurzform (`ok`/`fail`, Bytes, SHA-256, davon nur die ersten 12 Zeichen) vom externen Sicherungslauf entgegen (`docker exec <php-container> php bin/backup-record.php <ok|fail> <bytes> <sha256>`) und schreibt ein Monitoring-Ereignis (`component 'backup'`), damit der Adminbereich den letzten Lauf anzeigt; keine Pfade, keine Zugangsdaten, keine eigene Sicherungslogik.
- `deploy/vps/scripts/db-import.sh`: Import eines Dumps in die Coolify-MariaDB über `docker exec` in den Datenbankcontainer, mit vorheriger Prüfsummenkontrolle (`sha256sum -c ... --ignore-missing`), interaktiver Bestätigung vor dem Überschreiben, **keine** Migration (die läuft über `deploy.sh`/`bin/migrate.php`).
- `deploy/vps/scripts/db-verify.php`: eigenständiges Werkzeug **ohne** Abhängigkeit vom Anwendungs-Bootstrap (eigene, minimale PDO-Verbindung aus einer übergebenen Konfigurationsdatei), gibt je Tabelle Zeilenzahl und `CHECKSUM TABLE` als JSON aus; gedacht zum Abgleich zweier Umgebungen (`diff <(php db-verify.php alt.php) <(php db-verify.php neu.php)`), etwa beim Umzug IONOS → VPS.

### 5.3 Wiederherstellungstest-Status (ehrlich, aus `docs/betrieb-migration-vps.md`)

Dieser Punkt wird bewusst nicht beschönigt, weil die eigene Betriebsdokumentation ihn ausdrücklich einschränkt:

- Backups insgesamt: **"TEILWEISE BELEGT"**: *"Lokaler Dump vorhanden; aktiver Zeitplan mit S3-Ziel. Heutiges Remote-Objekt nicht unmittelbar gelistet."* (`docs/betrieb-migration-vps.md:40`).
- Wiederherstellung: **"NUTZERBESTÄTIGT"**: *"Restore funktioniert laut Nutzer. Herkunft des Dumps und Testprotokoll nicht beigefügt."* (`docs/betrieb-migration-vps.md:41`, ebenso Zeile 617-618: *"Restore-Test: vom Nutzer erfolgreich bestätigt. Objektliste/Download aus S3 und verwendete Restore-Datei nicht vorgelegt."*).
- Ausdrücklich als offen benannt (`docs/betrieb-migration-vps.md:648`): *"konkrete Aufbewahrungsregeln, Objektliste/Prüfsumme im S3-Bucket, letzte erfolgreiche externe Rücklesung, Sicherung von Appdateien und Entschlüsselungsschlüsseln sowie Alarmierung bei ausgebliebenem Backup."*
- Ebenfalls offen (`docs/betrieb-migration-vps.md:453`): Die Sicherung der MariaDB allein sichert **nicht automatisch** `app_secret` und andere Anwendungsdateien/Schlüssel, von denen die Entschlüsselbarkeit der in Abschnitt 2.7 genannten verschlüsselten Spalten abhängt.

**Einordnung:** Ein bestätigter Restore-Test ohne Datum, Zieldatenbank, geprüfte Tabellen/Zeilenzahlen oder Prüfsummen ist eine belastbare mündliche Aussage, aber kein wiederholbarer, dokumentierter Nachweis im Sinne des Kompendiums (Kapitel 11.4, Backup-Strategie inklusive Restore-Test). Siehe Empfehlung in Abschnitt 8.



## 6. Betrieb und Überwachung

### 6.1 Datenbankprüfung im Healthcheck

Containerhealthcheck (`docker-compose.yml`, `--db`): `bin/healthcheck.php:44`: `$check('db', fn() => (int)db()->query('SELECT 1')->fetchColumn() === 1);`, reiner Verbindungs-/Lesetest.

Internes Monitoring (`_mon_check_db()`, `app/monitor.php:316-326`): misst zusätzlich die Antwortzeit in Millisekunden, vergleicht gegen die Schwelle `latency_warn_ms.db` (Standard 500 ms, `monitor.php:50`) und meldet `degraded`/Kategorie `slow` bei Überschreitung. Ausdrücklich dokumentiert als **nur Lesetest**: *"Lesetest; Schreibfähigkeit wird nicht behauptet."* (`monitor.php:705`).

### 6.2 Datenbankgröße

Stündliche Messung über `information_schema` (`monitor.php:623`, Ergebnis in MB über `monitor_event('db_size', ...)`), mit dem dokumentierten Vorbehalt: *"Kontingent des Tarifs wird vom Hosting nicht bereitgestellt."* (`monitor.php:715`). Getrennt davon wird der Speicherbedarf hochgeladener Mandatsdateien gemessen (`SELECT COALESCE(SUM(size_bytes),0) FROM mandate_files`, `monitor.php:628`, Komponente `storage`).

### 6.3 Langsame Abfragen (Slow-Query-Log)

**Nicht konfiguriert im Repository.** Weder in `deploy/vps/docker-compose.yml` noch in einer Konfigurationsdatei dieses Repositories findet sich `slow_query_log` oder `long_query_time`; MariaDB ist eine externe Coolify-Ressource, ihre Serverkonfiguration liegt außerhalb dieses Codes. **Offen. Prüfbefehle:** `SHOW VARIABLES LIKE 'slow_query_log';`, `SHOW VARIABLES LIKE 'long_query_time';`, `SHOW VARIABLES LIKE 'slow_query_log_file';`.

### 6.4 Wartung (`ANALYZE TABLE`/`OPTIMIZE TABLE`)

**Nicht im Repository vorgesehen.** Kein Cron-Eintrag, kein Wartungsjob (`app/jobs.php`) und kein Skript führt `ANALYZE TABLE` oder `OPTIMIZE TABLE` aus. InnoDB aktualisiert Tabellenstatistiken standardmäßig automatisch im Hintergrund (`innodb_stats_auto_recalc`), eine geplante manuelle Auffrischung findet aber nicht statt. **Offen. Prüfbefehl:** `SHOW VARIABLES LIKE 'innodb_stats_auto_recalc';`

### 6.5 Weitere Betriebselemente

- `monitor_incidents`/`monitor_incident_updates`: Grundlage der öffentlichen Statusseite (`websites/status.smart-einzug.de`), Datenquelle ist `shared/status/status.json`, geschrieben aus `monitor_collect()` (siehe `CLAUDE.md`, Abschnitt Statusseite).
- `monitor_daily`: Tagesaggregation je Komponente (Sekunden `ok`/`degraded`/`fail`/`unknown`), Grundlage der öffentlichen Prozentwerte, mit Mindest-Messabdeckung (`monitoring.public_min_coverage_pct`, Standard 99 %, `app/config.example.php`).
- `job_runs`, `worker_heartbeats`, `sync_runs`, `api_circuits`: operative Sicht auf Hintergrundverarbeitung, Circuit Breaker und Synchronisationshistorie, jeweils mit eigenen Indizes für die Admin-Übersichten (siehe Datenwörterbuch).



## 7. Prüfung gegen das Kompendium

Bewertungsskala: **erfüllt** / **teilweise** / **nicht erfüllt** / **nicht anwendbar**.

| Prüfpunkt | Kompendium-Kapitel | Stand SmartEinzug | Bewertung | Vorschlag | Aufwand | Risiko |
|---|---|---|---|---|---|---|
| Zufällige UUID als geclusterter Primärschlüssel | 6.2 Clustered Index | `CHAR(36)` UUID v4 (`uuid4()`, `bootstrap.php:272-278`) als PK der meisten Fachtabellen | nicht erfüllt (bewusst in Kauf genommen) | **Nicht umsetzen.** Ein Wechsel auf sequentielle/zeitlich sortierbare Schlüssel (z. B. UUIDv7) beträfe praktisch jede Tabelle, jeden Fremdschlüssel und jede Integration (Lexware-/Stripe-Referenzen bleiben unabhängig davon `VARCHAR`); nur als informativer Hinweis dokumentieren, keine Migration vorschlagen | hoch | hoch |
| Zusammengesetzte Indizes für `tenant_id` + Statusfeld | 6.4 Zusammengesetzte Indizes | `payment_collections` (`tenant_id`+`stripe_status`), `invoices` (`tenant_id`+`lexoffice_status`), `jobs` (`tenant_id`+`type`+`status`) werden mehrfach gemeinsam gefiltert (Abschnitt 2.4), aber nur das `tenant_id`-Präfix bestehender Indizes greift | teilweise | Gezielt je Abfragemuster zusammengesetzte Indizes ergänzen, z. B. `(tenant_id, stripe_status)` auf `payment_collections`, `(tenant_id, lexoffice_status)` auf `invoices`; vorher mit `EXPLAIN` an einer Kopie mit realistischer Datenmenge belegen | mittel | gering |
| Fremdschlüssel-Abdeckung | 2.7 Constraints | 23 von 45 Tabellen mit FK, 22 ohne (Abschnitt 2.3); bei Protokoll-/Zeitreihentabellen bewusst (`audit_log`-Kommentar), bei `jobs`, `collection_attempts`, `support_sessions`, `legal_acceptances` fachlicher Bezug ohne DB-Constraint | teilweise | Je Tabelle einzeln prüfen, ob eine FK ohne Leistungsnachteil ergänzt werden kann (`legal_acceptances` zuerst, geringes Schreibaufkommen); Protokolltabellen mit hohem Durchsatz bewusst ausnehmen | gering bis mittel | gering bis mittel (Sperrverhalten beim Löschen des Elternobjekts vorher prüfen) |
| `CHECK`/`ENUM` für Statusspalten | 2.7 Constraints | Nur 2 von vielen Statusfeldern als `ENUM` (`legal_documents.required_for`, `legal_acceptances.method`); alle übrigen (`status`, `collection_status`, `stripe_status` etc.) sind freier `VARCHAR` mit erläuterndem Kommentar, ohne `CHECK` (MariaDB ≥ 10.2 unterstützt `CHECK`) | teilweise | `CHECK`-Constraints für die zentralen Statusspalten ergänzen, erst nach Bereinigung eventuell vorhandener nicht dokumentierter Werte | mittel | mittel (bestehende Daten müssen vorher geprüft werden) |
| NULL-Nutzung | 2.6 NULL bewusst einsetzen | Optionale Fremdbezüge (`sepa_mandates.customer_iban_id`), optionale Zeitpunkte (`cancelled_at`, `refunded_at` etc.) konsequent `NULL`; boolesche Felder als `TINYINT(1) NOT NULL DEFAULT 0/1` (MariaDB kennt keinen eigenen `BOOLEAN`-Typ) | erfüllt | keiner | – | – |
| Datentypen für Geldbeträge | 2.5 Datentypen | `INT`-Cent für eigene Zahlungsbewegungen, `DECIMAL(10,2)` für externe Lexware-Beträge (Abschnitt 2.5), beide Konventionen konsequent innerhalb ihres Bereichs | erfüllt, mit Dokumentationsbedarf | In einer kurzen Entwicklerrichtlinie festhalten, welcher Betragstyp in welchem Modul gilt, damit neue Spalten die richtige Wahl treffen | gering | gering |
| Isolationsstufe | 5.3 Isolationsstufen in InnoDB | Keine explizite `SET TRANSACTION ISOLATION LEVEL`; es gilt der MariaDB-Standard `REPEATABLE READ`, ungeprüft und undokumentiert | teilweise | Auf dem laufenden Server prüfen (`SHOW VARIABLES LIKE 'transaction_isolation'`) und in dieser Datei als bestätigten Wert nachtragen; Standard beibehalten, sofern kein konkretes Anomalie-Risiko benannt wird | gering (Prüfung) | gering |
| Sperrdauer (Tenant-Sperre über den Stripe-Aufruf) | 5.4 Sperrtypen, 5.6 Lock Waits und Timeouts | `organizations`-Zeile wird für die **gesamte** Dauer eines Einzugs gesperrt, einschließlich des synchronen Stripe-Aufrufs (`app/collections.php:1218-1226`) | teilweise | Beobachten, ob `innodb_lock_wait_timeout` (Standard 50 s) bei Stripe-Verzögerungen zu abgewiesenen parallelen Anfragen derselben Firma führt; ein Umbau (Sperre nur für die reine Datenbankarbeit, Stripe-Aufruf mit separater Idempotenzsicherung außerhalb der Transaktion) wäre ein Eingriff in den Geldfluss und braucht eigene Abnahme | hoch (bei Umbau) / gering (bei reiner Beobachtung) | mittel |
| Deadlock-Erkennung und Wiederholung | 5.5 Deadlocks | Keine gezielte Behandlung von SQLSTATE `40001`/Fehler 1213; Warteschlangen-Jobs werden generisch nach Backoff wiederholt (Abschnitt 3.7), synchrone Webanfragen nicht | teilweise | Für synchrone, geldrelevante Pfade (Einzug einreichen) eine gezielte, einmalige Wiederholung bei erkanntem Deadlock ergänzen, mit Protokollierung im Audit-Log | mittel | gering |
| Backup-Verifikation | 11.4 Strategie (Backup und Restore-Test) | Coolify-Plan aktiv und dokumentiert (Abschnitt 5.1); Restore-Test nur mündlich bestätigt, ohne Datum/Zielinstanz/Prüfsumme (Abschnitt 5.3) | teilweise | Einen dokumentierten, wiederholbaren Restore-Test durchführen und protokollieren (Datum, Zieldatenbank, `db-verify.php`-Ausgabe vor/nach, Ergebnis); danach hier nachtragen | gering bis mittel | gering (reine Prüfung) |
| Benutzerrechte (Least Privilege) | 12.1 Benutzer, Rechte, Rollen | Ein einziger Anwendungsbenutzer (`db.user`) für alle Lese- und Schreibzugriffe von Web, Cron, Worker, Scheduler und Migration; `docs/vps/07-cutover-checkliste.md:135-137` verlangt nur, dass dieser Benutzer keine Rechte über die eigene Datenbank hinaus hat (kein `GRANT ALL` auf `*.*`), nicht getrennte Rollen | offen (nicht aus dem Repository ablesbar) | Getrennte Datenbankbenutzer erwägen (z. B. ein leserechtebeschränkter Benutzer für Monitoring-Lesezugriffe), abgewogen gegen den Zusatzaufwand in einer Ein-Anwendungs-Architektur mit privater DB | mittel bis hoch | mittel |
| Verschlüsselung ruhender Daten (Feldebene) | 12.3 Verschlüsselung ruhender Daten | API-Schlüssel und TOTP-Geheimnis verschlüsselt (`app/crypto.php`); IBAN und Kontoinhabername (`customer_ibans`) liegen im Klartext in der Datenbank | teilweise | Prüfen, ob eine Feldverschlüsselung der IBAN fachlich vertretbar ist (Abwägung gegen SEPA-Abgleich, Anzeige, Stripe-Zusammenspiel); mindestens dokumentieren, dass ein Datenbankzugriff IBANs im Klartext zeigt | hoch (bei Umsetzung) | hoch (SEPA-Prozesse dürfen nicht brechen) |
| Transportverschlüsselung zur Datenbank | 12.2 Netzwerk und Transport | Keine TLS-Optionen im PDO-DSN (`bootstrap.php:253-269`); Schutz beruht auf dem internen, nicht veröffentlichten Docker-Netz (Abschnitt 1.1) | teilweise (durch Netzisolation kompensiert, nicht durch Transportverschlüsselung selbst) | Klären, ob die Coolify-MariaDB TLS anbietet, und falls ja, `PDO::MYSQL_ATTR_SSL_*` ergänzen; sonst die Netzisolation als bewussten Ersatz dokumentieren | gering (Prüfung) bis mittel (Umsetzung) | gering |
| Slow-Query-Log | 9.1 Das Slow Query Log | Nicht konfiguriert, außerhalb dieses Repositories (Abschnitt 6.3) | nicht erfüllt/offen | Aktivieren (`slow_query_log = ON`, `long_query_time` z. B. 1 Sekunde) auf der Coolify-MariaDB-Ressource, außerhalb dieses Codes | gering | gering |
| `ANALYZE TABLE`/Statistik-Wartung | 13.4 Wiederkehrende Wartung | Kein geplanter Wartungslauf im Repository (Abschnitt 6.4) | nicht erfüllt/offen | Als wiederkehrenden Wartungsjob in `app/jobs.php` ergänzen (z. B. wöchentlich, außerhalb der Kernzeiten) | gering | gering |
| Zeichensatz und Kollation | 2.5 Datentypen (Zeichensatz), 12 Sicherheit | `utf8mb4`/`utf8mb4_unicode_ci` konsequent auf Verbindungs- und Tabellenebene (Abschnitt 1.5) | erfüllt | keiner | – | – |
| Zeitzonenkonvention | 2.5 Datentypen (implizit), 4.6 | Gemischt: UTC bewusst in Monitoring/Queue/Vormerkung/Lexware-Import, lokale `NOW()`-Zeit in Audit-Bereinigung und den meisten `CURRENT_TIMESTAMP`-Defaults (Abschnitt 1.6) | teilweise | Für neue Tabellen eine verbindliche Konvention (UTC) in einer kurzen Entwicklerrichtlinie festschreiben; eine rückwirkende Vereinheitlichung bestehender Spalten nur mit eigener, sorgfältig getesteter Migration, nicht nebenbei | gering (Richtlinie) / hoch (rückwirkende Vereinheitlichung) | gering (Richtlinie) / mittel (Migration) |
| Query-Optimierung, `EXPLAIN`-Nachweis | 7.1 EXPLAIN lesen, 7.8 Typische Optimierungsschritte | Keine im Repository dokumentierten `EXPLAIN`-Analysen oder Abfrageoptimierungen für die in Abschnitt 2.4 genannten Kernabfragen | nicht erfüllt | Für die Kernabfragen (Einzugseinreichung, Job-Abholung, Sync-Abgleich) `EXPLAIN`-Stichproben gegen eine Kopie mit realistischer Datenmenge erstellen und hier oder in einer eigenen Notiz festhalten | gering | gering |
| Verbindungsverhalten der Worker (Reconnect) | 4.6 Speicherverbrauch verstehen, 9.5 Diagnose-Ablauf | Dauerprozesse (`bin/worker.php`, `bin/scheduler.php`) halten eine einzige `db()`-Verbindung über die gesamte Laufzeit, keine erkennbare Reconnect-Behandlung bei Verbindungsabbruch (Abschnitt 1.4) | offen | Gezielten Testfall für einen erzwungenen Verbindungsabbruch während eines langen Worker-Laufs ergänzen (`tools/worker-signal-check.sh`-Umfeld), Verhalten beobachten und ggf. Reconnect ergänzen | mittel | gering |
| Version, Puffergröße | 4.6, 8.1 Die Parameter, die zählen | Version laut Betriebsdoku 11.8.9 (Abschnitt 1.1), Puffergröße (`innodb_buffer_pool_size`) im Repository nicht dokumentiert | offen | Auf dem produktiven Server abfragen und dokumentieren | gering (Prüfung) | gering |



## 8. Empfehlungsliste und offene Prüfpunkte

### 8.1 Empfehlungsliste (priorisiert, nur Vorschläge, Umsetzung erst nach Freigabe der Geschäftsführung)

1. **Zusammengesetzte Indizes ergänzen** für die am häufigsten gemeinsam gefilterten Spalten: `payment_collections (tenant_id, stripe_status)`, `invoices (tenant_id, lexoffice_status)`, `jobs (tenant_id, type, status)`, geringes Risiko, mit `EXPLAIN`-Nachweis vor Freigabe.
2. **Bereinigungsabfrage von `audit_log` mit Index unterlegen** (aktuell reiner Tabellenscan bei jedem täglichen Lauf, Abschnitt 2.4/6): entweder einen Index mit `created_at` als führender Spalte ergänzen oder die Löschung anders staffeln (z. B. je `tenant_id`-Bereich).
3. **Dokumentierten Restore-Test durchführen und protokollieren** (Datum, Zieldatenbank, `db-verify.php`-Vergleich vor/nach, Ergebnis); das schließt die derzeit nur mündlich bestätigte Wiederherstellung sauber ab.
4. **Slow-Query-Log aktivieren** auf der Coolify-MariaDB-Ressource (`long_query_time` z. B. 1 Sekunde), Voraussetzung für jede spätere Abfrageoptimierung.
5. **Geldrelevante Sperrdauer beobachten**: Tenant-Sperre über `organizations` hält den Stripe-Aufruf innerhalb der Transaktion (Abschnitt 3.3/7); vor einem Umbau erst Kennzahlen sammeln (Wartezeit, Häufigkeit paralleler Anfragen je Firma).
6. **Gezielte Deadlock-Wiederholung für synchrone, geldrelevante Pfade** ergänzen (aktuell nur generischer Job-Retry in der Warteschlange, Abschnitt 3.7).
7. **Einheitliche Zeitzonenkonvention für neue Tabellen** schriftlich festlegen (UTC, wie bereits in Monitoring/Queue/Vormerkung praktiziert); bestehende Spalten nicht nebenbei umstellen.
8. **`CHECK`-Constraints für die zentralen Statusspalten** prüfen, nach vorheriger Bereinigung eventuell vorhandener nicht dokumentierter Werte.

### 8.2 Offene Prüfpunkte (nur der laufende Server kann sie beantworten)

| Punkt | Prüfbefehl |
|---|---|
| MariaDB-Version | `SELECT VERSION();` |
| InnoDB-Puffergröße | `SHOW VARIABLES LIKE 'innodb_buffer_pool_size';` |
| Sitzungs-/Systemzeitzone der Datenbank | `SHOW VARIABLES LIKE 'time_zone'; SHOW VARIABLES LIKE 'system_time_zone'; SELECT NOW(), UTC_TIMESTAMP();` |
| Isolationsstufe | `SHOW VARIABLES LIKE 'transaction_isolation';` |
| Lock-Wait-Timeout | `SHOW VARIABLES LIKE 'innodb_lock_wait_timeout';` |
| Slow-Query-Log | `SHOW VARIABLES LIKE 'slow_query_log'; SHOW VARIABLES LIKE 'long_query_time';` |
| Statistik-Autoaktualisierung | `SHOW VARIABLES LIKE 'innodb_stats_auto_recalc';` |
| Benutzerrechte des Anwendungsbenutzers | `SHOW GRANTS FOR CURRENT_USER();` (nur maskiert/ohne Kennwort weitergeben) |
| Aktuelle Datenbankgröße je Tabelle | `SELECT table_name, data_length, index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY (data_length+index_length) DESC;` |
| Tatsächlicher Objektbestand im S3-Backup-Bucket | außerhalb der Datenbank, über Coolify bzw. den S3-Anbieter zu prüfen |
| Transportverschlüsselung der Datenbankverbindung | `SHOW STATUS LIKE 'Ssl_cipher';` (aus einer bestehenden Verbindung heraus) |



## Zusammenfassung der wichtigsten Vorschläge

1. Zusammengesetzte Indizes für `tenant_id` + Statusfeld auf `payment_collections`, `invoices` und `jobs` ergänzen.
2. Bereinigungsabfrage von `audit_log` mit einem passenden Index unterlegen.
3. Restore-Test dokumentiert und wiederholbar durchführen (Datum, Zieldatenbank, Prüfsummen).
4. Slow-Query-Log auf der Datenbank aktivieren.
5. Sperrdauer der Tenant-Sperre über den Stripe-Aufruf beobachten, vor jedem Umbau Kennzahlen sammeln.
6. Gezielte Deadlock-Wiederholung für synchrone, geldrelevante Pfade ergänzen.
7. Einheitliche UTC-Zeitzonenkonvention für neue Tabellen schriftlich festlegen.
8. `CHECK`-Constraints für zentrale Statusspalten prüfen, nach vorheriger Datenbereinigung.

Die zufällige UUID als geclusterter Primärschlüssel (Kompendium 6.2) ist damit bewusst **nicht** in der Empfehlungsliste enthalten: Der Auftrag verlangt ausdrücklich, diesen Punkt nur als Vorschlag mit Aufwand/Risiko zu dokumentieren, nicht umzusetzen.
