<?php
/**
 * Gemeinsamer Einstieg der Kommandozeilenwerkzeuge (bin/*). Nur in der CLI ausführbar.
 * Lädt Bootstrap ohne Sitzung und Anfragezähler, setzt den Dienstnamen für das Logging.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur in der Kommandozeile.');
}
if (!defined('SKIP_SESSION')) {
    define('SKIP_SESSION', true);
}
if (!defined('SKIP_REQUEST_METRICS')) {
    define('SKIP_REQUEST_METRICS', true);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

/** Kommandozeilenoptionen --name=wert und --flag als Array. */
function cli_opts(array $argv): array
{
    $o = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--')) {
            $kv = explode('=', substr($a, 2), 2);
            $o[$kv[0]] = $kv[1] ?? true;
        }
    }
    return $o;
}

function cli_out(string $line): void
{
    fwrite(STDOUT, '[' . date('d.m.Y H:i:s') . '] ' . $line . "\n");
}
