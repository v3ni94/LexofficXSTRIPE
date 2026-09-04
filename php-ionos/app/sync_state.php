<?php
/**
 * Serverseitiger Zustand der Lexware-Office-Synchronisation.
 *
 * Der Cursor eines laufenden Syncs liegt in der Datenbank (Tabelle
 * sync_state), nicht mehr nur in der Browser-Session. Dadurch kann der Lauf
 * sowohl vom Browser (automatisches Nachladen auf der Rechnungsseite) als
 * auch vom Cron (cron.php) fortgesetzt werden: Der Nutzer muss die Seite
 * nicht geöffnet lassen. Ein Sperrzeitfenster verhindert, dass zwei Aufrufe
 * denselben Schritt doppelt ausführen.
 */

declare(strict_types=1);

if (get_included_files()[0] === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit.php';

const SYNC_LOCK_SECONDS = 90;     // maximale Dauer eines Schritts
const SYNC_STALE_MINUTES = 30;    // danach gilt ein "running" ohne Fortschritt als abgebrochen

function sync_state_get(string $tenantId): ?array
{
    $stmt = db()->prepare('SELECT * FROM sync_state WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();
    if ($row) {
        $row['cursor'] = $row['cursor_json'] ? json_decode($row['cursor_json'], true) : null;
        $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true) : null;
    }
    return $row ?: null;
}

/** Läuft für die Firma gerade eine Synchronisation (mit Fortschritt in den letzten Minuten)? */
function sync_state_is_running(?array $state): bool
{
    if (!$state || $state['status'] !== 'running') {
        return false;
    }
    $updated = new DateTimeImmutable($state['updated_at']);
    return $updated > (new DateTimeImmutable('now'))->modify('-' . SYNC_STALE_MINUTES . ' minutes');
}

/** Neuen Lauf starten (oder laufenden weiterverwenden). */
function sync_state_start(string $tenantId, ?array $actor): array
{
    $pdo = db();
    $state = sync_state_get($tenantId);
    if (sync_state_is_running($state)) {
        return $state;
    }
    $pdo->prepare(
        'INSERT INTO sync_state (tenant_id, status, cursor_json, requested_by_user_id, lock_until, started_at, finished_at, last_error, result_json)
         VALUES (?, "running", NULL, ?, NULL, NOW(), NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE status = "running", cursor_json = NULL, requested_by_user_id = VALUES(requested_by_user_id),
             lock_until = NULL, started_at = NOW(), finished_at = NULL, last_error = NULL, result_json = NULL'
    )->execute([$tenantId, $actor['user_id'] ?? null]);

    audit_log($tenantId, $actor, 'sync_requested', 'organization', $tenantId, [
        'requested_by_user_id' => $actor['user_id'] ?? null,
    ]);
    return sync_state_get($tenantId);
}

function sync_state_cancel(string $tenantId, ?array $actor): void
{
    db()->prepare(
        "UPDATE sync_state SET status = 'idle', cursor_json = NULL, lock_until = NULL, finished_at = NOW() WHERE tenant_id = ?"
    )->execute([$tenantId]);
    audit_log($tenantId, $actor, 'sync_cancelled', 'organization', $tenantId);
}

/** Lexware-Office-Client für eine Firma (Schlüssel wird je Schritt neu gelesen). */
function sync_lex_client(string $tenantId): LexofficeClient
{
    // Testhaken: erlaubt automatisierten Tests, einen Ersatz-Client ohne echte API zu liefern.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['lexsepa_lex_client_factory']) && is_callable($GLOBALS['lexsepa_lex_client_factory'])) {
        return ($GLOBALS['lexsepa_lex_client_factory'])($tenantId);
    }
    $stmt = db()->prepare('SELECT * FROM integrations WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    $integration = $stmt->fetch();
    if (!$integration || !(int)$integration['lexoffice_connected']) {
        throw new RuntimeException('Lexware Office ist nicht verbunden.');
    }
    $apiKey = decrypt_value($integration['lexoffice_api_key_encrypted']);
    if (!$apiKey) {
        throw new RuntimeException('Lexware Office API-Key fehlt.');
    }
    return new LexofficeClient($apiKey);
}

/**
 * Einen Schritt des laufenden Syncs ausführen (mit Sperre).
 * @return array{done:bool,skipped:bool,result:array}
 */
function sync_state_step(string $tenantId, int $batchSize = 6): array
{
    $pdo = db();
    $empty = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

    // Sperre holen: nur ein Aufrufer je Firma gleichzeitig
    $stmt = $pdo->prepare(
        "UPDATE sync_state SET lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
         WHERE tenant_id = ? AND status = 'running' AND (lock_until IS NULL OR lock_until < NOW())"
    );
    $stmt->execute([SYNC_LOCK_SECONDS, $tenantId]);
    if ($stmt->rowCount() !== 1) {
        $state = sync_state_get($tenantId);
        return ['done' => !sync_state_is_running($state), 'skipped' => true, 'result' => $state['result'] ?? ($state['cursor']['result'] ?? $empty)];
    }

    $state = sync_state_get($tenantId);
    try {
        $lex = sync_lex_client($tenantId);
        $step = sync_invoices_step($tenantId, $lex, $state['cursor'], $batchSize);

        if ($step['done']) {
            $pdo->prepare(
                "UPDATE sync_state SET status = 'done', cursor_json = NULL, lock_until = NULL, finished_at = NOW(), result_json = ?
                 WHERE tenant_id = ?"
            )->execute([json_encode($step['result']), $tenantId]);
            $actor = $state['requested_by_user_id'] ? ['user_id' => $state['requested_by_user_id']] : null;
            audit_log($tenantId, $actor, 'sync_completed', 'organization', $tenantId, $step['result']);
            funnel_event_once($tenantId, 'first_sync', $state['requested_by_user_id']);
        } else {
            $pdo->prepare(
                'UPDATE sync_state SET cursor_json = ?, lock_until = NULL, result_json = ? WHERE tenant_id = ?'
            )->execute([json_encode($step['cursor']), json_encode($step['result']), $tenantId]);
        }
        return ['done' => $step['done'], 'skipped' => false, 'result' => $step['result']];
    } catch (Throwable $e) {
        $pdo->prepare(
            "UPDATE sync_state SET status = 'error', lock_until = NULL, finished_at = NOW(), last_error = ? WHERE tenant_id = ?"
        )->execute([mb_substr($e->getMessage(), 0, 2000), $tenantId]);
        throw $e;
    }
}

/**
 * Für den Cron: alle laufenden Synchronisationen im Zeitbudget fortsetzen.
 * @return array{tenants:int,steps:int,finished:int,errors:int}
 */
function sync_run_pending(int $maxSeconds = 50): array
{
    $deadline = microtime(true) + $maxSeconds;
    $stats = ['tenants' => 0, 'steps' => 0, 'finished' => 0, 'errors' => 0];

    $rows = db()->query("SELECT tenant_id FROM sync_state WHERE status = 'running'")->fetchAll();
    foreach ($rows as $row) {
        $stats['tenants']++;
        while (microtime(true) < $deadline) {
            try {
                $r = sync_state_step($row['tenant_id']);
            } catch (Throwable $e) {
                error_log('Sync-Cron für Firma ' . $row['tenant_id'] . ' fehlgeschlagen: ' . $e->getMessage());
                $stats['errors']++;
                break;
            }
            if ($r['skipped']) {
                break;
            }
            $stats['steps']++;
            if ($r['done']) {
                $stats['finished']++;
                break;
            }
        }
    }
    return $stats;
}
