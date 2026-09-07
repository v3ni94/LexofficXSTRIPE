<?php
/**
 * Prueffaelle des automatischen Synchronisationsplans (scheduler_auto_sync in app/jobs.php) gegen eine
 * echte, temporaere Datenbank. Wird von tools/scheduler-sync-check.sh aufgerufen:
 *
 *   php tools/lib/scheduler-sync-sim.php <repo> <fall>
 *
 * Faelle:
 *   verwaist        Lauf "running" ohne Fortschritt und ohne Job -> wird geschlossen UND die Fortsetzung
 *                   sofort eingereiht (fruehe Freigabe statt Warten auf die naechste Faelligkeit).
 *   frisch          Lauf mit Fortschritt vor einer Minute -> unberuehrt, kein neuer Job.
 *   mit-job         Verwaister Lauf, aber ein Job liegt in der Warteschlange -> unberuehrt, kein zweiter Job.
 *   pausiert        sync_paused = 1 -> nichts, auch nicht schliessen.
 *   nachtfenster    Waehrend das Einreichfenster fuer Einzuege GESCHLOSSEN ist (tagsueber, Fenster 23:00
 *                   bis 06:00): Die Synchronisation wird trotzdem eingereiht. Das Fenster gilt nur fuer
 *                   das Einreichen von Lastschriften, nicht fuer Abrufe und Statusabgleiche.
 *
 * Gibt je Fall Zeilen "key=wert" aus, die das Testskript prueft.
 */
declare(strict_types=1);

$root = $argv[1] ?? '';
$fall = $argv[2] ?? '';
require $root . '/php-ionos/app/bootstrap.php';
require_once $root . '/php-ionos/app/jobs.php';

$pdo = db();
$tid = 'org-' . substr(bin2hex(random_bytes(4)), 0, 8);

// Jeder Fall startet auf leeren Tabellen: Das Testskript ruft mehrere Faelle gegen DIESELBE temporaere
// Datenbank auf; ohne Zuruecksetzen wuerden sich Firmen, Laeufe und Jobs addieren und die Zaehlungen
// verfaelschen. Betrifft ausschliesslich die Wegwerfdatenbank des Tests.
foreach (['jobs', 'sync_runs', 'sync_state', 'integrations', 'organizations'] as $tabelle) {
    try {
        $pdo->exec('DELETE FROM ' . $tabelle);
    } catch (Throwable $e) {
        // Tabelle fehlt in diesem Schema: nichts zu loeschen
    }
}

/** Firma mit abgeschlossenem Onboarding, verbundener Lexware-Anbindung und aktiver Warteschlange. */
function seed_org(PDO $pdo, string $tid, int $syncPaused = 0): void
{
    $pdo->prepare("INSERT INTO organizations (id, name, plan_code, onboarding_completed, sync_paused, feature_flags, created_at)
                   VALUES (?, 'Testfirma', 'unlimited_start', 1, ?, '{\"queue\":true}', NOW())")
        ->execute([$tid, $syncPaused]);
    $pdo->prepare("INSERT INTO integrations (id, tenant_id, lexoffice_connected) VALUES (?, ?, 1)")
        ->execute([uuid4(), $tid]);
}

/** Laufender Sync-Zustand mit Cursor; $ageMinutes steuert den letzten Fortschritt. */
function seed_state(PDO $pdo, string $tid, int $ageMinutes): void
{
    $pdo->prepare("INSERT INTO sync_state (tenant_id, status, cursor_json, started_at, updated_at, last_step_at)
                   VALUES (?, 'running', '{\"phase\":\"processing\",\"proc_index\":5}',
                           DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE))")
        ->execute([$tid, $ageMinutes, $ageMinutes, $ageMinutes]);
}

function report(PDO $pdo, string $tid, array $queued): void
{
    $st = $pdo->prepare('SELECT status, last_error FROM sync_state WHERE tenant_id = ?');
    $st->execute([$tid]);
    $state = $st->fetch() ?: ['status' => 'keiner', 'last_error' => null];
    $jb = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND type = 'sync_run' AND status IN ('queued','processing','retry')");
    $jb->execute([$tid]);
    printf("sync_state=%s\njobs_offen=%d\neingereiht=%s\n", (string)$state['status'], (int)$jb->fetchColumn(), implode(',', $queued) ?: 'nichts');
}

switch ($fall) {
    case 'verwaist':
        seed_org($pdo, $tid);
        seed_state($pdo, $tid, SYNC_STALE_MINUTES + 5);
        report($pdo, $tid, scheduler_auto_sync(jobs_config(), time()));
        break;

    case 'frisch':
        seed_org($pdo, $tid);
        seed_state($pdo, $tid, 1);
        report($pdo, $tid, scheduler_auto_sync(jobs_config(), time()));
        break;

    case 'mit-job':
        seed_org($pdo, $tid);
        seed_state($pdo, $tid, SYNC_STALE_MINUTES + 5);
        queue_push('sync_run', ['triggered_by' => 'test'], ['tenant_id' => $tid, 'dedupe_key' => 'sync:' . $tid]);
        report($pdo, $tid, scheduler_auto_sync(jobs_config(), time()));
        break;

    case 'pausiert':
        seed_org($pdo, $tid, 1);
        seed_state($pdo, $tid, SYNC_STALE_MINUTES + 5);
        report($pdo, $tid, scheduler_auto_sync(jobs_config(), time()));
        break;

    case 'nachtfenster':
        // Tagsueber: Das Einreichfenster (23:00 bis 06:00) ist GESCHLOSSEN. Die Synchronisation darf davon
        // nicht abhaengen. Geprueft wird beides in einem Lauf, damit die Aussage belegt ist.
        $mittags = new DateTimeImmutable('today 12:00', new DateTimeZone((string)config('timezone', 'Europe/Berlin')));
        $nachts = new DateTimeImmutable('today 23:30', new DateTimeZone((string)config('timezone', 'Europe/Berlin')));
        printf("fenster_mittags=%s\nfenster_nachts=%s\n",
            collections_window_open($mittags) ? 'offen' : 'geschlossen',
            collections_window_open($nachts) ? 'offen' : 'geschlossen');
        seed_org($pdo, $tid);
        // Kein laufender Zustand: die regulaere Faelligkeit greift (age = null)
        report($pdo, $tid, scheduler_auto_sync(jobs_config(), (int)$mittags->getTimestamp()));
        break;

    case 'wartende-jobs':
        // Warteschlangenansicht des Adminbereichs: wartender Job und geplante Wiederholung erscheinen,
        // ein reservierter Job nicht (der steht unter "Aktive Jobs").
        seed_org($pdo, $tid);
        queue_push('sync_run', ['triggered_by' => 'admin'], ['tenant_id' => $tid, 'dedupe_key' => 'sync:' . $tid]);
        queue_push('maintenance', [], ['dedupe_key' => 'wartung:test']);
        $reserviert = queue_reserve('worker-test', ['monitor_collect', 'maintenance']);
        $wartend = queue_waiting_jobs(50);
        printf("wartend=%d\nreserviert=%s\ntypen=%s\n", count($wartend), $reserviert ? (string)$reserviert['type'] : 'keiner',
            implode(',', array_map(static fn(array $j): string => (string)$j['type'], $wartend)));
        break;

    case 'stale-reservierung':
        // Reservierter Job ohne Lebenszeichen: erscheint in der Liste und laesst sich OHNE Fehlversuch
        // freigeben; ein frischer Heartbeat verhindert die Freigabe.
        seed_org($pdo, $tid);
        queue_push('sync_run', ['triggered_by' => 'admin'], ['tenant_id' => $tid, 'dedupe_key' => 'sync:' . $tid]);
        $job = queue_reserve('worker-test', ['sync_run']);
        printf("frisch_gefunden=%d\n", count(queue_stale_reservations(10)));
        $frisch = queue_release_one((string)$job['id'], ['email' => 'test']);
        printf("freigabe_frisch=%s\n", $frisch['ok'] ? 'ja' : 'nein');
        // Heartbeat kuenstlich altern lassen (heartbeat_ttl von sync_run: 300 s)
        $pdo->prepare('UPDATE jobs SET heartbeat_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = ?')->execute([$job['id']]);
        $liste = queue_stale_reservations(10);
        printf("stale_gefunden=%d\n", count($liste));
        $vorher = (int)queue_get((string)$job['id'])['attempts'];
        $r = queue_release_one((string)$job['id'], ['email' => 'test']);
        $nachher = queue_get((string)$job['id']);
        printf("freigabe=%s\nstatus=%s\nversuche_vorher=%d\nversuche_nachher=%d\nlocked=%s\n",
            $r['ok'] ? 'ja' : 'nein', (string)$nachher['status'], $vorher, (int)$nachher['attempts'],
            $nachher['locked_by'] === null ? 'frei' : (string)$nachher['locked_by']);
        break;

    case 'offene-laeufe':
        // Offener Lauf ohne Job gilt als haengend; sobald ein Job wartet, nicht mehr.
        seed_org($pdo, $tid);
        seed_state($pdo, $tid, SYNC_STALE_MINUTES + 5);
        $vor = sync_open_runs(10);
        queue_push('sync_run', ['triggered_by' => 'admin'], ['tenant_id' => $tid, 'dedupe_key' => 'sync:' . $tid]);
        $nach = sync_open_runs(10);
        printf("laeufe=%d\nhaengt_vorher=%s\nhaengt_nachher=%s\nfirma=%s\n", count($vor),
            !empty($vor[0]['stuck']) ? 'ja' : 'nein', !empty($nach[0]['stuck']) ? 'ja' : 'nein',
            (string)($vor[0]['org_name'] ?? '-'));
        break;

    default:
        fwrite(STDERR, "Unbekannter Fall: $fall\n");
        exit(2);
}
