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
require_once __DIR__ . '/app/monitor.php';

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
// Monitoring: dieser Cron-Lauf als Ausführungsversuch (Heartbeat, Laufzeit, Speicher). Fehler der Diagnose stören den Lauf nie.
define('CRON_CONTEXT', true);
$cronRunId = job_run_start('cron', 'cron', null, PHP_SAPI === 'cli' ? 'cli' : 'cron');

// Datenbankmigrationen laufen NICHT im Cron (nur über migrate.php nach dem Upload,
// siehe docs/migrations.md), damit sie nie während eines laufenden Uploads starten.

require_once __DIR__ . '/app/queue.php';
if (queue_any_enabled()) {
    // Hybridbetrieb (Warteschlange aktiv, aber keine Worker-Container wie auf dem Webhosting): Scheduler-Tick
    // und Jobs im Zeitbudget inline verarbeiten. Auf dem VPS übernehmen Scheduler- und Worker-Container.
    require_once __DIR__ . '/app/jobs.php';
    define('IN_WORKER', true);
    $st = queue_run_inline(max(5.0, $totalBudget - 3), 'cron-inline-' . getmypid() . '-' . bin2hex(random_bytes(3)));
    echo sprintf("[%s] Warteschlange (inline): %d eingereiht (%s), %d verarbeitet, %d fortgesetzt, %d fehlgeschlagen oder erneut geplant\n",
        date('d.m.Y H:i:s'), count($st['queued']), implode(', ', $st['queued']) ?: 'nichts Neues', $st['processed'], $st['requeued'], $st['failed']);
    job_run_finish($cronRunId, 'success', ['items' => (int)$st['processed']]);
    echo sprintf("[%s] Laufzeit %.1f s, PHP-Spitzenspeicher dieses Jobs %.1f MB\n", date('d.m.Y H:i:s'), microtime(true) - $start, memory_get_peak_usage(true) / 1048576);
    exit;
}

require_once __DIR__ . '/app/support.php';
try { support_sessions_expire(); } catch (Throwable $e) { /* Tabelle fehlt bis Migration 008 */ }
require_once __DIR__ . '/app/auth.php';
try { registration_requests_cleanup(); } catch (Throwable $e) { /* Tabelle fehlt bis Migration 015 */ }
try { devices_cleanup(); } catch (Throwable $e) { /* Tabelle fehlt bis Migration 016 */ }

// Fällige vorgemerkte und terminierte Einzüge: nur im Einreichfenster, höchstens die
// Hälfte des Zeitbudgets je Lauf; der Rest folgt beim nächsten Lauf (alle 5 Minuten).
$collRunId = job_run_start('collections', 'collections:due', null, 'cron');
try {
    $result = process_scheduled_collections(null, null, ['deadline' => $start + $totalBudget * 0.5]);
    job_run_finish($collRunId, 'success', ['items' => (int)$result['submitted'], 'api_errors' => (int)$result['failed'] + (int)$result['unknown']]);
} catch (Throwable $e) {
    job_run_finish($collRunId, 'failed', [], monitor_category($e));
    throw $e;
}
echo sprintf(
    "[%s] Einzüge verarbeitet: %d eingereicht, %d fehlgeschlagen, %d zurückgestellt, %d unklar, %d wegen Not-Stopp übersprungen%s%s%s\n",
    date('d.m.Y H:i:s'),
    $result['submitted'],
    $result['failed'],
    $result['deferred'],
    $result['unknown'],
    $result['skipped_paused'],
    $result['outside_window'] > 0 ? sprintf(', %d fällig, warten auf das Einreichfenster', $result['outside_window']) : '',
    $result['remaining'] > 0 ? sprintf(', %d folgen im nächsten Lauf', $result['remaining']) : '',
    $result['overdue_skipped'] > 0 ? sprintf(', %d überfällig (manuell neu terminieren)', $result['overdue_skipped']) : ''
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

// Für den Monitoring-Sammler bleiben etwa 4 Sekunden reserviert
$budget = (int)max(3, $totalBudget - (microtime(true) - $start) - 4);
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

// Monitoring-Sammler: eigene Sperre, kurze Timeouts, nur freigegebene Prüfungen; startet nichts.
try {
    $monBudget = max(2.0, min(8.0, $totalBudget + 5 - (microtime(true) - $start)));
    $mon = monitor_collect(['budget' => $monBudget, 'source' => 'cron']);
    echo sprintf("[%s] Monitoring: %s\n", date('d.m.Y H:i:s'),
        isset($mon['skipped']) ? 'übersprungen (' . $mon['skipped'] . ')' : count($mon['checks'] ?? []) . ' Prüfungen, ' . count($mon['skipped_checks'] ?? []) . ' wegen Zeitbudget ausgelassen, ' . (int)($mon['stale_runs'] ?? 0) . ' unbestätigte Läufe markiert');
} catch (Throwable $e) {
    error_log('Cron Monitoring fehlgeschlagen: ' . monitor_category($e));
}
job_run_finish($cronRunId, 'success', ['items' => (int)($sync['steps'] ?? 0) + (int)($result['submitted'] ?? 0), 'extra_ms' => 0]);
echo sprintf("[%s] Laufzeit %.1f s, PHP-Spitzenspeicher dieses Jobs %.1f MB\n", date('d.m.Y H:i:s'), microtime(true) - $start, memory_get_peak_usage(true) / 1048576);
