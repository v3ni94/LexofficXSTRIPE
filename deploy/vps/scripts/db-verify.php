<?php
/**
 * SmartEinzug: Datenbankvergleich alte/neue Umgebung (z.B. IONOS-Webhosting vs. VPS).
 *
 * Eigenstaendiges PHP-CLI-Werkzeug ohne Abhaengigkeit vom Anwendungs-Bootstrap, damit es
 * wahlweise gegen die alte Konfiguration (Webhosting) oder die neue (VPS) laufen kann.
 * Gibt fuer jede Tabelle Zeilenzahl und CHECKSUM TABLE als JSON aus; die Ausgabe zweier Laeufe
 * (alt und neu) laesst sich damit direkt vergleichen (z.B. mit "diff <(...) <(...)").
 *
 *   php db-verify.php /pfad/zu/config.php
 *
 * Die Konfigurationsdatei muss ein Array zurueckgeben, das entweder direkt die Schluessel
 * host/port/name/user/pass/charset enthaelt, oder wie app/config.php ein Array mit dem
 * Unterschluessel 'db' => [...]. Es werden AUSSCHLIESSLICH Datenbank-Zugangsdaten aus dieser
 * Datei gelesen, keine Geheimnisse in dieser Ausgabe ausgegeben.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur in der Kommandozeile ausfuehrbar.\n");
    exit(2);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath)) {
    fwrite(STDERR, "Nutzung: php db-verify.php /pfad/zu/config.php\n");
    exit(2);
}

$raw = require $configPath;
if (!is_array($raw)) {
    fwrite(STDERR, "Konfigurationsdatei liefert kein Array.\n");
    exit(2);
}
$db = $raw['db'] ?? $raw;
foreach (['host', 'name', 'user', 'pass'] as $required) {
    if (!isset($db[$required])) {
        fwrite(STDERR, "Konfiguration unvollstaendig, Schluessel fehlt: $required\n");
        exit(2);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int)($db['port'] ?? 3306),
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    // Fehlermeldung koennte Zugangsdaten enthalten (z.B. den Host im DSN); daher nur eine
    // bereinigte Kurzmeldung ausgeben, keine Ausnahme direkt durchreichen.
    fwrite(STDERR, "Verbindung fehlgeschlagen (Host/Zugangsdaten pruefen).\n");
    exit(1);
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);

$result = [
    'database'    => $db['name'],
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'table_count' => count($tables),
    'tables'      => [],
];

foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
    $checksumRow = $pdo->query('CHECKSUM TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
    $result['tables'][] = [
        'name'     => $table,
        'rows'     => $rowCount,
        'checksum' => $checksumRow['Checksum'] ?? null,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
