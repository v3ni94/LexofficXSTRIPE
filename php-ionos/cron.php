<?php
/**
 * Cron-Endpunkt: fällige terminierte SEPA-Einzüge bei Stripe einreichen.
 *
 * Einrichtung im IONOS Kundenbereich (Hosting > Cronjobs) oder über einen
 * externen Cron-Dienst, täglich morgens an Werktagen, z.B. 06:00 Uhr:
 *
 *   https://sepa.muellerhv.de/cron.php?token=<cron_token aus config.php>
 *
 * Alternativ per PHP-CLI: php cron.php <cron_token>
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/collections.php';

header('Content-Type: text/plain; charset=utf-8');

$token = PHP_SAPI === 'cli'
    ? ($argv[1] ?? '')
    : ($_GET['token'] ?? '');

$expected = (string)config('cron_token');
if (strlen($expected) < 16 || !hash_equals($expected, (string)$token)) {
    http_response_code(403);
    die('Zugriff verweigert.');
}

@set_time_limit(300);

$result = process_scheduled_collections();

echo sprintf(
    "[%s] Terminierte Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen\n",
    date('d.m.Y H:i:s'),
    $result['submitted'],
    $result['failed']
);
