<?php
/**
 * Setup-Prüfung für das SEPA-Portal.
 *
 * Nach dem Hochladen aufrufen: https://sepa.muellerhv.de/setup-check.php
 * Prüft PHP-Version, benötigte Module, Konfiguration, Datenbankverbindung
 * und ob das Schema importiert wurde. Gibt keine Zugangsdaten aus.
 *
 * WICHTIG: Nach erfolgreichem Setup diese Datei vom Server löschen.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

// Sobald eine Konfiguration existiert, ist die Prüfung nur noch mit dem
// cron_token erreichbar (setup-check.php?token=...), damit keine
// Systeminformationen öffentlich abrufbar sind.
$preConfig = null;
$configLoadError = null;
if (is_file(__DIR__ . '/app/config.php')) {
    // Kodierung prüfen (Windows-Editoren speichern gern mit BOM oder als UTF-16)
    $raw = (string)file_get_contents(__DIR__ . '/app/config.php');
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $configLoadError = 'Die Datei beginnt mit einer Byte-Order-Markierung (BOM). Bitte im Editor als "UTF-8 ohne BOM" speichern.';
    } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $configLoadError = 'Die Datei ist als UTF-16 gespeichert. Bitte als UTF-8 speichern.';
    } elseif (!str_starts_with(ltrim($raw), '<?php')) {
        $configLoadError = 'Die Datei beginnt nicht mit "<?php". Vermutlich wurde eine falsche Datei hochgeladen.';
    } else {
        try {
            $preConfig = require __DIR__ . '/app/config.php';
            if (!is_array($preConfig)) {
                $configLoadError = 'Die Datei liefert kein Konfigurations-Array (fehlt "return [" oder das abschließende "];"?).';
            }
        } catch (Throwable $e) {
            $configLoadError = 'PHP-Fehler in app/config.php, Zeile ' . $e->getLine() . ': ' . $e->getMessage()
                . ' (häufig ein fehlendes Anführungszeichen oder Komma in der Zeile davor).';
        }
    }
}
if (is_array($preConfig) && strlen((string)($preConfig['cron_token'] ?? '')) >= 16 && !str_contains((string)$preConfig['cron_token'], 'HIER-')) {
    if (!hash_equals((string)$preConfig['cron_token'], (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Zugriff nur mit Token: setup-check.php?token=<cron_token aus app/config.php>');
    }
}

$checks = [];
function add_check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

// --- 1. PHP-Version ---
add_check(
    'PHP-Version (mindestens 8.1)',
    PHP_VERSION_ID >= 80100,
    'Gefunden: PHP ' . PHP_VERSION
);

// --- 2. Erforderliche Erweiterungen ---
foreach (['pdo_mysql' => 'MariaDB-Anbindung', 'curl' => 'Stripe/Lexware-Office-API', 'openssl' => 'Verschlüsselung der API-Keys', 'mbstring' => 'Textverarbeitung'] as $ext => $zweck) {
    add_check("PHP-Modul $ext ($zweck)", extension_loaded($ext));
}

// --- 3. Konfiguration ---
$configFile = __DIR__ . '/app/config.php';
$config = null;
if ($configLoadError !== null) {
    add_check('Konfigurationsdatei app/config.php lesbar', false, $configLoadError);
} elseif (is_file($configFile)) {
    add_check('Konfigurationsdatei app/config.php vorhanden', true);
    $config = $preConfig;

    $secret = (string)($config['app_secret'] ?? '');
    add_check(
        'app_secret gesetzt (mindestens 32 Zeichen, kein Platzhalter)',
        strlen($secret) >= 32 && !str_contains($secret, 'HIER-'),
    );
    $cron = (string)($config['cron_token'] ?? '');
    add_check(
        'cron_token gesetzt (mindestens 16 Zeichen, kein Platzhalter)',
        strlen($cron) >= 16 && !str_contains($cron, 'HIER-'),
    );
    add_check(
        'base_url gesetzt',
        !empty($config['base_url']) && str_starts_with((string)$config['base_url'], 'https://'),
        'Aktuell: ' . ($config['base_url'] ?? '(leer)')
    );
} else {
    add_check('Konfigurationsdatei app/config.php vorhanden', false,
        'Bitte app/config.example.php nach app/config.php kopieren und ausfüllen.');
}

// --- 4. Datenbankverbindung und Schema ---
$expectedTables = [
    'organizations', 'users', 'organization_members', 'invitations', 'integrations',
    'customers', 'customer_ibans', 'iban_history', 'sepa_mandates', 'invoices',
    'payment_collections',
    // seit Migration 003 (SaaS-Ausbau)
    'plans', 'user_recovery_codes', 'login_attempts', 'audit_log', 'sync_state', 'funnel_events', 'webhook_events',
];

if ($config && !empty($config['db']['host'])) {
    try {
        $c = $config['db'];
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4'),
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        add_check('Datenbankverbindung', true, 'Server: ' . $version);

        $found = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($expectedTables, $found);
        add_check(
            'Schema importiert (' . count($expectedTables) . ' Tabellen)',
            !$missing,
            $missing ? 'Fehlend: ' . implode(', ', $missing) . ' – bitte sql/schema.sql per phpMyAdmin importieren.' : ''
        );

        if (!$missing) {
            $orgs = (int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
            add_check(
                'Registrierung',
                true,
                $orgs === 0
                    ? 'Noch keine Organisation angelegt – jetzt registrieren.'
                    : "$orgs Organisation(en) vorhanden."
            );
        }
    } catch (Throwable $e) {
        add_check('Datenbankverbindung', false,
            'Fehler: ' . $e->getMessage() . ' – Host, Datenbankname, Benutzer und Passwort in app/config.php prüfen.');
    }
}

// --- 5. Schutz interner Verzeichnisse ---
$base = null;
if ($config && !empty($config['base_url']) && function_exists('curl_init')) {
    $base = rtrim((string)$config['base_url'], '/');
    $ch = curl_init($base . '/app/config.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status > 0) {
        add_check(
            '.htaccess-Schutz für app/ aktiv',
            in_array($status, [403, 404], true),
            "HTTP $status beim Zugriff auf /app/config.php" .
            (in_array($status, [403, 404], true) ? '' : ' – .htaccess wurde vermutlich nicht mit hochgeladen (versteckte Dateien in FileZilla einblenden).')
        );
    }
}

$allOk = array_reduce($checks, fn($carry, $c) => $carry && $c['ok'], true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Setup-Prüfung | SEPA-Portal</title>
    <style>
        body { font-family: "Segoe UI", Arial, sans-serif; background: #f4f5f7; color: #1f2933; margin: 0; padding: 40px 16px; }
        .box { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d9dde3; border-radius: 6px; padding: 28px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { padding: 10px 0; border-bottom: 1px solid #eceef1; }
        .ok::before { content: "\2713 "; color: #1e7e4a; font-weight: bold; }
        .fail::before { content: "\2717 "; color: #a12622; font-weight: bold; }
        .detail { font-size: 13px; color: #616e7c; margin: 4px 0 0 20px; }
        .summary { margin-top: 20px; padding: 12px 16px; border-radius: 6px; font-weight: 600; }
        .summary.ok { background: #e3f3ea; color: #1e7e4a; }
        .summary.fail { background: #f8e7e6; color: #a12622; }
        .warn { margin-top: 16px; font-size: 13px; color: #616e7c; }
    </style>
</head>
<body>
<div class="box">
    <h1>Setup-Prüfung SEPA-Portal</h1>
    <ul>
        <?php foreach ($checks as $c): ?>
        <li class="<?= $c['ok'] ? 'ok' : 'fail' ?>">
            <?= htmlspecialchars($c['name']) ?>
            <?php if ($c['detail']): ?><div class="detail"><?= htmlspecialchars($c['detail']) ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="summary <?= $allOk ? 'ok' : 'fail' ?>">
        <?= $allOk
            ? 'Alle Prüfungen bestanden. Das Portal ist einsatzbereit: ' . htmlspecialchars(($base ?? '')) . '/login.php'
            : 'Es gibt noch offene Punkte, siehe rote Einträge oben.' ?>
    </div>
    <p class="warn">Hinweis: Diese Datei (setup-check.php) nach erfolgreichem Setup vom Server löschen.</p>
</div>
</body>
</html>
