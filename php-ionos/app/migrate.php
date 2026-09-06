<?php
/**
 * Datenbankmigrationen: zentraler Runner mit gemeinsamer Sperre.
 *
 * Die Dateien sql/migrations/NNN_*.sql liegen mit der Anwendung auf dem Server
 * (per Web nicht abrufbar, siehe .htaccess). Der Stand steht in der Tabelle
 * schema_migrations (version, status success | running | failed | unknown).
 * Für ältere Migrationen ist ein Marker hinterlegt (Tabelle oder Spalte, die
 * sie anlegt); ist er vorhanden, gilt die Migration als eingespielt.
 *
 * Aufrufer: ausschließlich migrate.php (POST mit X-Migration-Token, vom
 * GitHub-Workflow nach vollständigem Upload) über migrations_run(). Kein
 * anderer Einstiegspunkt (Cron, Seitenaufruf, Login, Gesundheitsprüfung) führt
 * Migrationen aus; setup-check.php liest nur.
 *
 * Sperre: MariaDB GET_LOCK auf einen datenbankspezifischen Namen, atomar,
 * verbindungsgebunden (kein Ablauf während des Laufs, keine Freigabe fremder
 * Sperren möglich). Ist sie belegt, wird nichts ausgeführt (MigrationLockedException).
 *
 * Fehler: Der Lauf bricht bei der ersten fehlgeschlagenen Migration ab und
 * merkt sie als 'failed'. Eine Migration mit Status failed, unknown oder einem
 * verwaisten running blockiert jeden weiteren Lauf, bis sie manuell geklärt ist
 * (siehe docs/migrations.md). Keine automatische Wiederholung.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

class MigrationLockedException extends RuntimeException {}
class MigrationBlockedException extends RuntimeException {}

/** Marker je Migration: [Tabelle, Spalte|null], nur zur Erkennung bereits eingespielter Altbestände. */
function migrations_markers(): array
{
    return [
        '001' => ['customers', 'sepa_debit_enabled'],
        '002' => ['organizations', 'use_hvm_ci'],
        '003' => ['users', 'totp_enabled'],
        '004' => ['integrations', 'stripe_account_id'],
        '005' => ['mandate_files', null],
        '006' => ['collection_attempts', null],
        '007' => ['payment_collections', 'refunded_cents'],
        '008' => ['support_sessions', null],
        '009' => ['payment_collections', 'source'],
        '010' => ['users', 'phone_business'],
        '011' => ['payment_collections', 'submit_not_before'],
        '012' => ['support_tickets', null],
        '013' => ['invoices', 'lexoffice_updated_at'],
    ];
}

function migrations_dir(): string
{
    return dirname(__DIR__) . '/sql/migrations';
}

/** Buchführungstabelle des Runners (eigene Tabelle, keine fachliche Tabelle). */
function _migrations_ensure_table(): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version     VARCHAR(10)  NOT NULL PRIMARY KEY,
        filename    VARCHAR(255) NOT NULL,
        applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_by  VARCHAR(40)  NOT NULL DEFAULT \'auto\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('ALTER TABLE schema_migrations
        ADD COLUMN IF NOT EXISTS status      VARCHAR(10)  NOT NULL DEFAULT \'success\',
        ADD COLUMN IF NOT EXISTS started_at  DATETIME     NULL,
        ADD COLUMN IF NOT EXISTS finished_at DATETIME     NULL,
        ADD COLUMN IF NOT EXISTS error_text  TEXT         NULL,
        ADD COLUMN IF NOT EXISTS lock_owner  VARCHAR(64)  NULL');
}

/** Name der gemeinsamen Sperre (je Datenbank). */
function migrations_lock_name(): string
{
    $db = (string)(config('db')['name'] ?? 'smarteinzug');
    return 'smarteinzug_migrations_' . substr(md5($db), 0, 16);
}

/** Sperre atomar holen (0 Sekunden Wartezeit). true = erhalten. */
function migrations_lock_acquire(): bool
{
    $stmt = db()->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([migrations_lock_name()]);
    return (int)$stmt->fetchColumn() === 1;
}

function migrations_lock_release(): void
{
    try {
        $stmt = db()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([migrations_lock_name()]);
    } catch (Throwable $e) {
        // Verbindung bereits geschlossen: Sperre ist damit ohnehin frei
    }
}

/** true, wenn ein anderer Prozess die Sperre gerade hält. */
function migrations_lock_held_elsewhere(): bool
{
    $stmt = db()->prepare('SELECT IS_USED_LOCK(?)');
    $stmt->execute([migrations_lock_name()]);
    $owner = $stmt->fetchColumn();
    return $owner !== null && $owner !== false && (int)$owner !== (int)db()->query('SELECT CONNECTION_ID()')->fetchColumn();
}

function _migration_marker_present(array $marker): bool
{
    [$table, $column] = $marker;
    $pdo = db();
    if ($column === null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
    }
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Stand aller Migrationsdateien (rein lesend, für setup-check und den Runner).
 * state: applied | pending | failed | running | unknown
 *  - failed:  Ausführung fehlgeschlagen, manuelle Klärung nötig
 *  - running: laut Tabelle noch in Ausführung (Sperre wird beim Lauf gesondert geprüft)
 *  - unknown: Prozess abgebrochen, Teiländerungen möglich, manuelle Klärung nötig
 */
function migrations_status(): array
{
    _migrations_ensure_table();
    $pdo = db();
    $rows = [];
    foreach ($pdo->query('SELECT * FROM schema_migrations') as $r) {
        $rows[$r['version']] = $r;
    }
    $markers = migrations_markers();
    $out = [];
    foreach (glob(migrations_dir() . '/[0-9][0-9][0-9]_*.sql') ?: [] as $file) {
        $name = basename($file);
        $version = substr($name, 0, 3);
        $state = 'pending';
        $row = $rows[$version] ?? null;
        if ($row) {
            $state = match ((string)($row['status'] ?? 'success')) {
                'success' => 'applied',
                'failed'  => 'failed',
                'running' => 'running',
                default   => 'unknown',
            };
        } elseif (isset($markers[$version]) && _migration_marker_present($markers[$version])) {
            $pdo->prepare("INSERT IGNORE INTO schema_migrations (version, filename, applied_by, status, finished_at) VALUES (?, ?, 'marker', 'success', NOW())")
                ->execute([$version, $name]);
            $state = 'applied';
        }
        $out[] = ['version' => $version, 'filename' => $name, 'state' => $state, 'path' => $file, 'error' => $row['error_text'] ?? null];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['version'], $b['version']));
    return $out;
}

/** SQL-Datei in einzelne Anweisungen zerlegen (Kommentare entfernen, an ; trennen). */
function migration_split_statements(string $sql): array
{
    $lines = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
            continue;
        }
        $line = preg_replace('/;\s*--.*$/', ';', $line) ?? $line;
        $lines[] = $line;
    }
    $clean = implode("\n", $lines);
    $statements = [];
    foreach (preg_split('/;\s*(?=\n|$)/', $clean) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }
    return $statements;
}

/**
 * Migrationen ausführen: Sperre holen, Blockaden prüfen, offene Migrationen in
 * Reihenfolge einspielen. Einziger erlaubter Einstieg für Änderungen.
 *
 * @return array{applied:string[],pending:string[]} nur bei vollständigem Erfolg
 * @throws MigrationLockedException  Sperre belegt (nichts ausgeführt)
 * @throws MigrationBlockedException Migration mit Status failed/unknown/verwaist running
 * @throws RuntimeException          Migration fehlgeschlagen (als failed vermerkt)
 */
function migrations_run(string $by = 'deploy'): array
{
    if (!migrations_lock_acquire()) {
        throw new MigrationLockedException('migration_in_progress');
    }
    $pdo = db();
    try {
        $status = migrations_status();
        // Verwaiste running-Zeilen: der Prozess ist weg (wir halten die Sperre), Zustand unklar
        foreach ($status as $i => $m) {
            if ($m['state'] === 'running') {
                $pdo->prepare("UPDATE schema_migrations SET status = 'unknown', error_text = CONCAT_WS(' ', error_text, 'Prozess abgebrochen, Zustand ungeklärt') WHERE version = ? AND status = 'running'")
                    ->execute([$m['version']]);
                $status[$i]['state'] = 'unknown';
            }
        }
        $blocked = array_values(array_filter($status, static fn(array $m): bool => in_array($m['state'], ['failed', 'unknown'], true)));
        if ($blocked) {
            throw new MigrationBlockedException('Blockiert durch ' . implode(', ', array_column($blocked, 'filename')) . ' (manuelle Klärung erforderlich, siehe docs/migrations.md)');
        }

        $applied = [];
        foreach ($status as $m) {
            if ($m['state'] !== 'pending') {
                continue;
            }
            $pdo->prepare("INSERT INTO schema_migrations (version, filename, applied_by, status, started_at) VALUES (?, ?, ?, 'running', NOW())
                           ON DUPLICATE KEY UPDATE status = 'running', started_at = NOW(), error_text = NULL, applied_by = VALUES(applied_by)")
                ->execute([$m['version'], $m['filename'], mb_substr($by, 0, 40)]);
            try {
                foreach (migration_split_statements((string)file_get_contents($m['path'])) as $stmt) {
                    $pdo->exec($stmt);
                }
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE schema_migrations SET status = 'failed', finished_at = NOW(), error_text = ? WHERE version = ?")
                    ->execute([mb_substr($e->getMessage(), 0, 2000), $m['version']]);
                error_log('Migration ' . $m['filename'] . ' fehlgeschlagen: ' . $e->getMessage());
                if (function_exists('audit_log')) {
                    audit_log(null, ['user_id' => null, 'email' => $by], 'migration_failed', 'database', $m['version'], ['datei' => $m['filename']]);
                }
                throw new RuntimeException('migration_failed: ' . $m['filename'], 0, $e);
            }
            $pdo->prepare("UPDATE schema_migrations SET status = 'success', finished_at = NOW(), applied_at = NOW() WHERE version = ?")
                ->execute([$m['version']]);
            $applied[] = $m['filename'];
            if (function_exists('audit_log')) {
                audit_log(null, ['user_id' => null, 'email' => $by], 'migration_applied', 'database', $m['version'], ['datei' => $m['filename']]);
            }
        }
        $pending = array_values(array_map(static fn(array $m): string => $m['filename'],
            array_filter(migrations_status(), static fn(array $m): bool => $m['state'] !== 'applied')));
        return ['applied' => $applied, 'pending' => $pending];
    } finally {
        migrations_lock_release();
    }
}
