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
require_once __DIR__ . '/invoice_source.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit.php';

const SYNC_LOCK_SECONDS = 180;    // maximale Dauer eines Schritts (Zeitbudget plus Wiederholungen)
const SYNC_STALE_MINUTES = 30;    // danach gilt ein "running" ohne Fortschritt als abgebrochen

function sync_state_get(string $tenantId): ?array
{
    // Zeitvergleiche in der Datenbank rechnen (Datenbank- und PHP-Zeitzone können abweichen)
    $stmt = db()->prepare('SELECT s.*, TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) AS age_seconds,
                                  (s.lock_until IS NOT NULL AND s.lock_until > NOW()) AS lock_active
                           FROM sync_state s WHERE s.tenant_id = ?');
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
    if (isset($state['age_seconds'])) {
        return (int)$state['age_seconds'] < SYNC_STALE_MINUTES * 60;
    }
    $updated = new DateTimeImmutable($state['updated_at']);
    return $updated > (new DateTimeImmutable('now'))->modify('-' . SYNC_STALE_MINUTES . ' minutes');
}

/**
 * Neuen Lauf starten. Läuft bereits einer, wird kein zweiter angelegt: der bestehende
 * Zustand kommt mit already_running = true zurück und der Doppelstart wird gezählt.
 */
function sync_state_start(string $tenantId, ?array $actor): array
{
    $pdo = db();
    $state = sync_state_get($tenantId);
    if (sync_state_is_running($state)) {
        $pdo->prepare('UPDATE sync_state SET skipped_starts = skipped_starts + 1 WHERE tenant_id = ?')->execute([$tenantId]);
        audit_log($tenantId, $actor, 'sync_start_skipped', 'organization', $tenantId);
        $state['already_running'] = true;
        return $state;
    }
    $pdo->prepare(
        'INSERT INTO sync_state (tenant_id, status, cursor_json, requested_by_user_id, lock_until, lock_owner, skipped_starts, started_at, finished_at, last_error, result_json)
         VALUES (?, "running", NULL, ?, NULL, NULL, 0, NOW(), NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE status = "running", cursor_json = NULL, requested_by_user_id = VALUES(requested_by_user_id),
             lock_until = NULL, lock_owner = NULL, skipped_starts = 0, started_at = NOW(), finished_at = NULL, last_error = NULL, result_json = NULL'
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

/**
 * Rechnungsquelle einer Firma für die Synchronisation (Schlüssel wird je
 * Schritt neu gelesen). Kapselt invoice_source_for_tenant() aus
 * app/invoice_source.php; der Testhaken lexsepa_lex_client_factory wird dort
 * ausgewertet.
 */
function sync_invoice_source(string $tenantId): InvoiceSource
{
    return invoice_source_for_tenant($tenantId);
}

/** Lexware-Office-Client für eine Firma (Verbindungsprüfung, Abgleich). Bevorzugt sync_invoice_source() verwenden. */
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
function sync_state_step(string $tenantId, int $batchSize = 0): array
{
    $pdo = db();
    $empty = ['synced' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

    // Sperre atomar holen: nur ein Aufrufer je Firma gleichzeitig. Der Inhaber wird mit
    // einer zufälligen Kennung vermerkt; nur er darf den Schritt später speichern.
    $owner = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        "UPDATE sync_state SET lock_until = DATE_ADD(NOW(), INTERVAL ? SECOND), lock_owner = ?
         WHERE tenant_id = ? AND status = 'running' AND (lock_until IS NULL OR lock_until < NOW())"
    );
    $stmt->execute([SYNC_LOCK_SECONDS, $owner, $tenantId]);
    if ($stmt->rowCount() !== 1) {
        $state = sync_state_get($tenantId);
        return ['done' => !sync_state_is_running($state), 'skipped' => true, 'result' => $state['result'] ?? ($state['cursor']['result'] ?? $empty)];
    }

    $state = sync_state_get($tenantId);
    try {
        $lex = sync_invoice_source($tenantId);
        $step = sync_invoices_step($tenantId, $lex, $state['cursor'], $batchSize);

        if ($step['done']) {
            $upd = $pdo->prepare(
                "UPDATE sync_state SET status = 'done', cursor_json = NULL, lock_until = NULL, lock_owner = NULL, finished_at = NOW(), last_step_at = NOW(), result_json = ?
                 WHERE tenant_id = ? AND lock_owner = ?"
            );
            $upd->execute([json_encode($step['result']), $tenantId, $owner]);
            if ($upd->rowCount() !== 1) {
                return _sync_lock_lost($tenantId, $owner, $step['result']);
            }
            $actor = $state['requested_by_user_id'] ? ['user_id' => $state['requested_by_user_id']] : null;
            audit_log($tenantId, $actor, 'sync_completed', 'organization', $tenantId, $step['result']);
            funnel_event_once($tenantId, 'first_sync', $state['requested_by_user_id']);
        } else {
            // Fortschritt nur speichern, wenn die Sperre noch uns gehört. Wurde sie in der
            // Zwischenzeit von einem anderen Prozess übernommen (Zeitüberschreitung), wird der
            // Cursor dieses Schritts verworfen; die Datenänderungen selbst sind idempotente Upserts.
            $upd = $pdo->prepare(
                'UPDATE sync_state SET cursor_json = ?, lock_until = NULL, lock_owner = NULL, last_step_at = NOW(), result_json = ? WHERE tenant_id = ? AND lock_owner = ?'
            );
            $upd->execute([json_encode($step['cursor']), json_encode($step['result']), $tenantId, $owner]);
            if ($upd->rowCount() !== 1) {
                return _sync_lock_lost($tenantId, $owner, $step['result']);
            }
        }
        return ['done' => $step['done'], 'skipped' => false, 'result' => $step['result']];
    } catch (Throwable $e) {
        $pdo->prepare(
            "UPDATE sync_state SET status = 'error', lock_until = NULL, lock_owner = NULL, finished_at = NOW(), last_error = ? WHERE tenant_id = ? AND lock_owner = ?"
        )->execute([mb_substr($e->getMessage(), 0, 2000), $tenantId, $owner]);
        throw $e;
    }
}

/** Sperre während des Schritts verloren: nichts speichern, protokollieren, als übersprungen melden. */
function _sync_lock_lost(string $tenantId, string $owner, array $result): array
{
    error_log('Sync für Firma ' . $tenantId . ': Sperre während des Schritts verloren (Inhaber ' . substr($owner, 0, 8) . '), Cursor verworfen.');
    audit_log($tenantId, null, 'sync_lock_lost', 'organization', $tenantId, ['owner' => substr($owner, 0, 8)]);
    return ['done' => false, 'skipped' => true, 'lock_lost' => true, 'result' => $result];
}

/**
 * Verständlicher Zustand eines Laufs für die Oberfläche.
 * Wartet | Wird synchronisiert | Teilweise verarbeitet | Abgeschlossen | Fehler | Kein Lauf
 */
function sync_state_label(?array $state): string
{
    if (!$state) {
        return 'Kein Lauf';
    }
    switch ($state['status']) {
        case 'done':
            return 'Abgeschlossen';
        case 'error':
            return 'Fehler';
        case 'running':
            if (!sync_state_is_running($state)) {
                return 'Abgebrochen (kein Fortschritt seit ' . SYNC_STALE_MINUTES . ' Minuten)';
            }
            if ((int)($state['lock_active'] ?? 0) === 1) {
                return 'Wird synchronisiert';
            }
            return !empty($state['cursor']) ? 'Teilweise verarbeitet, wartet auf den nächsten Schritt' : 'Wartet';
        default:
            return 'Kein Lauf';
    }
}

/**
 * Für den Cron: alle laufenden Synchronisationen im Zeitbudget fortsetzen.
 *
 * Fairness: Die Firmen werden im Round-Robin bedient, die am längsten nicht
 * bearbeitete zuerst. Je Runde erhält jede Firma höchstens $stepsPerRound
 * Schritte, dann kommt die nächste Firma dran; solange Zeit bleibt, beginnt
 * eine neue Runde. So blockiert ein Erstimport mit vielen Rechnungen nicht die
 * übrigen Firmen, auch wenn der Aufruf (z.B. cron-job.org, 30 s) knapp ist.
 *
 * @return array{tenants:int,steps:int,finished:int,errors:int,rounds:int,open:int}
 */
function sync_run_pending(int $maxSeconds = 50, int $stepsPerRound = 2): array
{
    $deadline = microtime(true) + $maxSeconds;
    $stats = ['tenants' => 0, 'steps' => 0, 'finished' => 0, 'errors' => 0, 'rounds' => 0, 'open' => 0];

    $queue = db()->query(
        "SELECT tenant_id FROM sync_state WHERE status = 'running' AND (lock_until IS NULL OR lock_until < NOW())
         ORDER BY updated_at ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $stats['tenants'] = count($queue);

    while ($queue && microtime(true) < $deadline) {
        $stats['rounds']++;
        $next = [];
        foreach ($queue as $tenantId) {
            if (microtime(true) >= $deadline) {
                $next[] = $tenantId;
                continue;
            }
            $keep = true;
            for ($i = 0; $i < max(1, $stepsPerRound) && microtime(true) < $deadline; $i++) {
                try {
                    $r = sync_state_step((string)$tenantId);
                } catch (Throwable $e) {
                    error_log('Sync-Cron für Firma ' . $tenantId . ' fehlgeschlagen: ' . $e->getMessage());
                    $stats['errors']++;
                    $keep = false;
                    break;
                }
                if ($r['skipped']) { // fremde Sperre (z.B. Nutzer synchronisiert gerade selbst)
                    $keep = false;
                    break;
                }
                $stats['steps']++;
                if ($r['done']) {
                    $stats['finished']++;
                    $keep = false;
                    break;
                }
            }
            if ($keep) {
                $next[] = $tenantId;
            }
        }
        $queue = $next;
    }
    $stats['open'] = count($queue);
    return $stats;
}
