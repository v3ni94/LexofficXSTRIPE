<?php
/**
 * Cron-Endpunkt (optional, alles ist auch manuell über Buttons möglich):
 *  1. fällige terminierte SEPA-Einzüge bei Stripe einreichen,
 *  2. laufende Lexware-Office-Synchronisationen im Hintergrund fortsetzen
 *     (der Nutzer muss die Rechnungsseite dann nicht geöffnet lassen).
 *
 * Einrichtung im IONOS Kundenbereich (Hosting > Cronjobs) oder über einen
 * externen Cron-Dienst, z.B. alle 5 Minuten:
 *
 *   https://app.lexware-einzug.de/cron.php?token=<cron_token aus config.php>
 *
 * Alternativ per PHP-CLI: php cron.php <cron_token>
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/sync_state.php';

header('Content-Type: text/plain; charset=utf-8');

$token = PHP_SAPI === 'cli'
    ? ($argv[1] ?? '')
    : ($_GET['token'] ?? '');

$expected = (string)config('cron_token');
if (strlen($expected) < 16 || !hash_equals($expected, (string)$token)) {
    http_response_code(403);
    die('Zugriff verweigert.');
}

@set_time_limit(120);
$start = microtime(true);

$result = process_scheduled_collections();
echo sprintf(
    "[%s] Terminierte Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen\n",
    date('d.m.Y H:i:s'),
    $result['submitted'],
    $result['failed']
);

$budget = (int)max(10, 50 - (microtime(true) - $start));
$sync = sync_run_pending($budget);
echo sprintf(
    "[%s] Synchronisation: %d Firma/Firmen, %d Schritte, %d abgeschlossen, %d Fehler\n",
    date('d.m.Y H:i:s'),
    $sync['tenants'],
    $sync['steps'],
    $sync['finished'],
    $sync['errors']
);
