<?php
/**
 * Migrationsendpunkt für den GitHub-Workflow (deploy.yml), nach vollständigem
 * SFTP-Upload: POST mit Header "X-Migration-Token: <migration_token aus config.php>".
 *
 * Vertrag (siehe docs/migrations.md):
 *   200 {"success":true}                                  alle offenen Migrationen eingespielt oder nichts offen
 *   401 {"success":false,"error":"unauthorized"}          Token fehlt, leer oder falsch
 *   405 {"success":false,"error":"method_not_allowed"}    andere Methode als POST (Header Allow: POST)
 *   409 {"success":false,"error":"migration_in_progress"} Sperre belegt
 *   500 {"success":false,"error":"migration_failed"}      Migration fehlgeschlagen oder Blockade durch früheren Fehler
 *   500 {"success":false,"error":"server_configuration_error"} migration_token nicht konfiguriert
 * Der Token wird ausschließlich aus dem Header gelesen (kein URL-Parameter, kein
 * Formularfeld, kein Cookie) und nur mit hash_equals(konfiguriert, übermittelt) verglichen.
 * Vor erfolgreicher Prüfung wird keine Migration ausgeführt.
 */
declare(strict_types=1);

ob_start(); // Fremdausgaben (Hinweise, BOM) dürfen das JSON nicht beschädigen

/** Antwort senden und beenden; verwirft jede vorherige Ausgabe. */
function migrate_respond(int $code, array $body, array $headers = []): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    error_log(sprintf('migrate.php: %s in %s:%d', $str, $file, $line));
    return true; // nie in die Ausgabe
});

try {
    require_once __DIR__ . '/app/bootstrap.php';
    require_once __DIR__ . '/app/audit.php';
    require_once __DIR__ . '/app/migrate.php';
} catch (Throwable $e) {
    error_log('migrate.php: Bootstrap fehlgeschlagen: ' . $e->getMessage());
    migrate_respond(500, ['success' => false, 'error' => 'server_configuration_error']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    migrate_respond(405, ['success' => false, 'error' => 'method_not_allowed'], ['Allow: POST']);
}

$expected = config('migration_token', null);
if (!is_string($expected) || trim($expected) === '') {
    error_log('migrate.php: migration_token ist nicht konfiguriert.');
    migrate_respond(500, ['success' => false, 'error' => 'server_configuration_error']);
}
$received = $_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? null;
if (!is_string($received) || trim($received) === '' || !hash_equals($expected, $received)) {
    error_log('migrate.php: Migrationsaufruf ohne gültigen Token abgewiesen.');
    migrate_respond(401, ['success' => false, 'error' => 'unauthorized']);
}

@set_time_limit(600);
@ignore_user_abort(true);
try {
    migrations_run('deploy');
    migrate_respond(200, ['success' => true]);
} catch (MigrationLockedException $e) {
    migrate_respond(409, ['success' => false, 'error' => 'migration_in_progress']);
} catch (MigrationBlockedException $e) {
    error_log('migrate.php: ' . $e->getMessage());
    migrate_respond(500, ['success' => false, 'error' => 'migration_failed']);
} catch (Throwable $e) {
    error_log('migrate.php: ' . $e->getMessage());
    migrate_respond(500, ['success' => false, 'error' => 'migration_failed']);
}
