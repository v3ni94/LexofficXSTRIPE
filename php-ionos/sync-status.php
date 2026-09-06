<?php
/**
 * Live-Zustand der Synchronisation der eigenen Firma (mandantengefiltert) als HTML-Fragment oder JSON.
 * Wird von der Synchronisierungsansicht alle paar Sekunden abgefragt; löst selbst nichts aus.
 */
define('SKIP_REQUEST_METRICS', true); // Polling-Abrufe verfälschen die Anfragekennzahlen nicht
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/sync_state.php';
require_once __DIR__ . '/app/queue.php';

$ctx = require_login();
$tenantId = (string)$ctx['org_id'];
header('Cache-Control: no-store');
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $state = sync_state_get($tenantId);
    $p = sync_progress($state);
    echo json_encode(['running' => $state && $state['status'] === 'running', 'percent' => $p['percent'], 'text' => $p['text'], 'processed' => $p['processed'], 'total' => $p['total'], 'label' => sync_state_label($state)], JSON_UNESCAPED_UNICODE);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo sync_progress_fragment($tenantId);
