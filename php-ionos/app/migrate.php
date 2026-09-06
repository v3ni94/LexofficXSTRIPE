<?php
/**
 * Datenbankmigrationen automatisch einspielen.
 *
 * Die Dateien sql/migrations/NNN_*.sql liegen mit der Anwendung auf dem Server
 * (per Web nicht abrufbar, siehe .htaccess). Für jede Migration ist ein Marker
 * hinterlegt (Tabelle oder Spalte, die sie anlegt). Ist der Marker vorhanden,
 * gilt die Migration als eingespielt und wird nie erneut ausgeführt; fehlt er,
 * wird die Datei Anweisung für Anweisung ausgeführt. Ergebnis steht in der
 * Tabelle schema_migrations. Aufruf durch cron.php (bei jedem Lauf) oder
 * manuell über migrate.php?token=<cron_token>.
 */
declare(strict_types=1);

if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }

/** Marker je Migration: [Tabelle, Spalte|null]. Neue Migrationen hier ergänzen. */
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

function _migrations_ensure_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(10)  NOT NULL PRIMARY KEY,
        filename   VARCHAR(255) NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_by VARCHAR(40)  NOT NULL DEFAULT \'auto\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
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
 * Status aller Migrationsdateien: version, filename, state (applied|pending|unknown).
 * Migrationen mit vorhandenem Marker werden als eingespielt vermerkt.
 */
function migrations_status(): array
{
    _migrations_ensure_table();
    $pdo = db();
    $recorded = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $recorded = array_fill_keys($recorded, true);
    $markers = migrations_markers();
    $out = [];
    foreach (glob(migrations_dir() . '/[0-9][0-9][0-9]_*.sql') ?: [] as $file) {
        $name = basename($file);
        $version = substr($name, 0, 3);
        $state = 'pending';
        if (isset($recorded[$version])) {
            $state = 'applied';
        } elseif (isset($markers[$version])) {
            if (_migration_marker_present($markers[$version])) {
                $pdo->prepare('INSERT IGNORE INTO schema_migrations (version, filename, applied_by) VALUES (?, ?, ?)')
                    ->execute([$version, $name, 'marker']);
                $state = 'applied';
            }
        } else {
            $state = 'unknown'; // kein Marker hinterlegt: nur manuell einspielen
        }
        $out[] = ['version' => $version, 'filename' => $name, 'state' => $state, 'path' => $file];
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
        // Kommentar am Zeilenende nach einem Semikolon entfernen ("...; -- Hinweis"),
        // sonst würde das Semikolon nicht als Trenner erkannt.
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
 * Ausstehende Migrationen in Reihenfolge einspielen.
 * @return array{applied:string[],failed:?string,error:?string,skipped:string[]}
 */
function migrations_apply(string $by = 'auto'): array
{
    $result = ['applied' => [], 'failed' => null, 'error' => null, 'skipped' => []];
    $pdo = db();
    foreach (migrations_status() as $m) {
        if ($m['state'] === 'applied') {
            continue;
        }
        if ($m['state'] === 'unknown') {
            $result['skipped'][] = $m['filename'];
            continue;
        }
        try {
            foreach (migration_split_statements((string)file_get_contents($m['path'])) as $stmt) {
                $pdo->exec($stmt);
            }
            $pdo->prepare('INSERT IGNORE INTO schema_migrations (version, filename, applied_by) VALUES (?, ?, ?)')
                ->execute([$m['version'], $m['filename'], mb_substr($by, 0, 40)]);
            $result['applied'][] = $m['filename'];
            if (function_exists('audit_log')) {
                audit_log(null, ['user_id' => null, 'email' => $by], 'migration_applied', 'database', $m['version'], ['datei' => $m['filename']]);
            }
        } catch (Throwable $e) {
            $result['failed'] = $m['filename'];
            $result['error'] = $e->getMessage();
            error_log('Migration ' . $m['filename'] . ' fehlgeschlagen: ' . $e->getMessage());
            break; // Reihenfolge einhalten, nichts überspringen
        }
    }
    return $result;
}
