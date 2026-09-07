<?php
/**
 * Dokumentationssystem: Manifest lesen, Zugriff je Klassifizierung pruefen, Archiv auflisten.
 *
 * Erzeugt wird die Dokumentation ausschliesslich durch tools/build-docs.py (GitHub-Workflow), nie zur Laufzeit.
 * Zugriffsstufen je Datei (manifest.json, Feld access):
 *   technical  Entwickler- und Betriebsdokumentation (streng vertraulich): Plattformadministratoren (users.is_superadmin,
 *              2FA aktiv). Zusaetzlich duerfen Adressen aus config docs.technical_readers lesen, sobald sie ueber die
 *              Plattformrollen (4.34) Adminzugang ohne Superadmin-Recht haben; Mitarbeiter- und Supportrollen sehen sie nie.
 *   admin      Unternehmensdokumentation, Anlagen, Diagramme: Plattformadministratoren mit 2FA; nicht fuer Mitarbeiter/Support.
 *   customer   Benutzerhandbuch: angemeldete Benutzer der Kundenanwendung (handbuch.php) und Plattformadministratoren.
 * Historische Fassungen liegen unter shared/docs-archive/<id>/ (von deploy.sh abgelegt) mit eigenem Manifest.
 */
declare(strict_types=1);

function docs_build_dir(): ?string
{
    $p = realpath(__DIR__ . '/docs-build');
    return $p === false ? null : $p;
}

/** Archivverzeichnis: config docs.archive_dir, sonst <shared>/docs-archive neben der Konfigurationsdatei. */
function docs_archive_dir(): ?string
{
    $cfg = $GLOBALS['config']['docs'] ?? [];
    $dir = (string)($cfg['archive_dir'] ?? '');
    if ($dir === '') {
        $configFile = (string)(getenv('SMARTEINZUG_CONFIG') ?: '');
        if ($configFile !== '') {
            $dir = dirname($configFile) . '/docs-archive';
        }
    }
    if ($dir === '' || !is_dir($dir)) {
        return null;
    }
    $r = realpath($dir);
    return $r === false ? null : $r;
}

function docs_manifest(?string $dir = null): ?array
{
    $dir = $dir ?? docs_build_dir();
    if ($dir === null || !is_file($dir . '/manifest.json')) {
        return null;
    }
    $m = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
    return is_array($m) ? $m : null;
}

/** Liste der Adressen mit Zugriff auf die technische Dokumentation (kleingeschrieben). */
function docs_technical_readers(): array
{
    $cfg = $GLOBALS['config']['docs'] ?? [];
    return array_values(array_filter(array_map(static fn($e) => mb_strtolower(trim((string)$e)), (array)($cfg['technical_readers'] ?? []))));
}

/** Darf dieser Kontext Dateien der Zugriffsstufe lesen? */
function docs_can_access(array $ctx, string $access): bool
{
    require_once __DIR__ . '/platform.php';
    $isSuper = (int)($ctx['is_superadmin'] ?? 0) === 1 && (int)($ctx['totp_enabled'] ?? 0) === 1;
    switch ($access) {
        case 'technical':
            // Superadmin, Berechtigung docs.technical einer Plattformrolle oder (Altregel) eingetragene Adresse mit Plattformadminrolle.
            return $isSuper || platform_can($ctx, 'docs.technical')
                || (!empty($ctx['platform_admin']) && in_array(mb_strtolower((string)($ctx['email'] ?? '')), docs_technical_readers(), true));
        case 'admin':
            return $isSuper || platform_can($ctx, 'docs.admin');
        case 'customer':
            return !empty($ctx['user_id']);
        default:
            return false;
    }
}

/** Manifest-Eintrag zu einem Dateinamen (exakter Abgleich) oder null. */
function docs_manifest_entry(array $manifest, string $name): ?array
{
    if ($name === 'manifest.json') {
        return ['name' => 'manifest.json', 'kind' => 'json', 'access' => 'admin', 'doc' => null];
    }
    foreach ((array)($manifest['files'] ?? []) as $f) {
        if (($f['name'] ?? '') === $name) {
            return $f;
        }
    }
    return null;
}

/** Archivierte Fassungen: [['id' => ..., 'version' => ..., 'commit' => ..., 'generated_at' => ..., 'documents' => [...]], ...], neueste zuerst. */
function docs_archive_list(): array
{
    $dir = docs_archive_dir();
    if ($dir === null) {
        return [];
    }
    $out = [];
    foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $id) {
        if ($id === '.' || $id === '..' || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id) || !is_dir($dir . '/' . $id)) {
            continue;
        }
        $m = docs_manifest($dir . '/' . $id);
        $out[] = ['id' => $id, 'version' => (string)($m['version'] ?? '?'), 'commit' => (string)($m['commit'] ?? '?'),
                  'generated_at' => (string)($m['generated_at'] ?? '?'), 'documents' => (array)($m['documents'] ?? []), 'manifest' => $m];
    }
    return $out;
}

/** Archivverzeichnis zu einer Kennung, nur wenn vorhanden und unterhalb des Archivs. */
function docs_archive_path(string $id): ?string
{
    $dir = docs_archive_dir();
    if ($dir === null || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $id)) {
        return null;
    }
    $p = realpath($dir . '/' . $id);
    return ($p !== false && str_starts_with($p, $dir . DIRECTORY_SEPARATOR) && is_dir($p)) ? $p : null;
}

/** Datei aus einem Dokumentationsverzeichnis ausliefern (Allowlist ueber Manifest, realpath-Pruefung). */
function docs_serve(string $baseDir, array $manifest, string $name, array $ctx, string $auditAction): void
{
    $entry = docs_manifest_entry($manifest, $name);
    if ($entry === null) {
        docs_fail(404, 'Diese Datei ist nicht im Manifest der Dokumentation gelistet.');
    }
    if (!docs_can_access($ctx, (string)($entry['access'] ?? 'technical'))) {
        docs_fail(403, 'Für dieses Dokument fehlt die Leseberechtigung.');
    }
    $path = realpath($baseDir . '/' . $name);
    if ($path === false || !str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR) || !is_file($path) || basename($path) !== basename($name)) {
        docs_fail(404, 'Datei nicht gefunden.');
    }
    $kind = (string)($entry['kind'] ?? '');
    $types = ['pdf' => 'application/pdf', 'html' => 'text/html; charset=utf-8', 'svg' => 'image/svg+xml', 'json' => 'application/json; charset=utf-8', 'png' => 'image/png'];
    if (!isset($types[$kind])) {
        docs_fail(415, 'Dieser Dateityp wird nicht ausgeliefert.');
    }
    require_once __DIR__ . '/audit.php';
    audit_log(null, $ctx, $auditAction, 'doc', $name, ['access' => $entry['access'] ?? null, 'doc' => $entry['doc'] ?? null]);
    header('Content-Type: ' . $types[$kind]);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($kind === 'html' || $kind === 'svg') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; font-src data:; connect-src 'self'");
    }
    if ($kind === 'html' || $kind === 'pdf' || $kind === 'png') {
        header('Content-Disposition: inline; filename="' . str_replace(['"', '/', '\\'], '', basename($name)) . '"');
    }
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

function docs_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}
