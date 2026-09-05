<?php
/**
 * Cron-Endpunkt (optional, alles ist auch manuell über Buttons möglich):
 *  1. fällige terminierte SEPA-Einzüge bei Stripe einreichen,
 *  2. laufende Lexware-Office-Synchronisationen im Hintergrund fortsetzen
 *     (der Nutzer muss die Rechnungsseite dann nicht geöffnet lassen),
 *  3. unklare Einzugsversuche klären, Mandats-Erinnerungen versenden,
 *  4. Alarmierung: einmal je Kalendertag pro Firma eine E-Mail an den Inhaber
 *     bei Alarmen der Stufe 'hoch' (app/alerts.php).
 *
 * Einrichtung im IONOS Kundenbereich (Hosting > Cronjobs) oder über einen
 * externen Cron-Dienst, z.B. alle 5 Minuten:
 *
 *   https://app.smart-einzug.de/cron.php?token=<cron_token aus config.php>
 *
 * Alternativ per PHP-CLI: php cron.php <cron_token>
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/collections.php';
require_once __DIR__ . '/app/sync_state.php';
require_once __DIR__ . '/app/mandate_requests.php';
require_once __DIR__ . '/app/alerts.php';

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
@ignore_user_abort(true); // Bricht der Aufrufer (z.B. cron-job.org) ab, läuft der begonnene Schritt sauber zu Ende
$start = microtime(true);
// Gesamtlaufzeit: externe Cron-Dienste brechen häufig nach 30 Sekunden ab. Ziel sind
// höchstens etwa 20 bis 25 Sekunden je Aufruf; die Synchronisation arbeitet in Schritten
// und setzt beim nächsten Aufruf fort (config: cron_time_budget_seconds, Standard 20).
$totalBudget = max(10, min(110, (int)config('cron_time_budget_seconds', 20)));

require_once __DIR__ . '/app/support.php';
try { support_sessions_expire(); } catch (Throwable $e) { /* Tabelle fehlt bis Migration 008 */ }

$result = process_scheduled_collections();
echo sprintf(
    "[%s] Terminierte Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen\n",
    date('d.m.Y H:i:s'),
    $result['submitted'],
    $result['failed']
);

// Unklare Einzugsversuche je Firma mit Stripe-Verbindung klären (Timeout-Fälle)
$resolved = ['checked' => 0, 'recovered' => 0, 'cleared' => 0, 'pending' => 0];
$stmt = db()->query("SELECT DISTINCT tenant_id FROM collection_attempts WHERE status IN ('unknown','pending') AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
    try {
        $r = collection_attempts_resolve((string)$tid, ['user_id' => null, 'email' => 'cron']);
        foreach ($resolved as $k => $v) { $resolved[$k] += (int)($r[$k] ?? 0); }
    } catch (Throwable $e) {
        error_log('Cron Klärung fehlgeschlagen (' . $tid . '): ' . $e->getMessage());
    }
}
echo sprintf("[%s] Unklare Versuche: %d geprüft, %d nachgetragen, %d freigegeben, %d offen\n", date('d.m.Y H:i:s'), $resolved['checked'], $resolved['recovered'], $resolved['cleared'], $resolved['pending']);

if (!empty(config('features', [])['mandate_request'])) {
    try {
        $rem = mandate_request_remind();
        echo sprintf("[%s] Mandatsanforderungen: %d Erinnerungen versendet\n", date('d.m.Y H:i:s'), (int)($rem['sent'] ?? 0));
    } catch (Throwable $e) {
        error_log('Cron Mandats-Erinnerung fehlgeschlagen: ' . $e->getMessage());
    }
}

// Alarmierung: einmal je Kalendertag pro Firma eine E-Mail an den Inhaber bei Alarmen der Stufe 'hoch'
try {
    $al = alerts_cron_notify();
    echo sprintf("[%s] Alarmierung: %d Firmen geprüft, %d E-Mails versendet, %d übersprungen\n", date('d.m.Y H:i:s'), $al['checked'], $al['sent'], $al['skipped']);
} catch (Throwable $e) {
    error_log('Cron Alarmierung fehlgeschlagen: ' . $e->getMessage());
}

$budget = (int)max(3, $totalBudget - (microtime(true) - $start));
$sync = sync_run_pending($budget);
echo sprintf(
    "[%s] Synchronisation: %d Firma/Firmen, %d Schritte in %d Runde(n), %d abgeschlossen, %d Fehler, %d noch offen (Fortsetzung beim nächsten Aufruf)\n",
    date('d.m.Y H:i:s'),
    $sync['tenants'],
    $sync['steps'],
    $sync['rounds'] ?? 0,
    $sync['finished'],
    $sync['errors'],
    $sync['open'] ?? 0
);
