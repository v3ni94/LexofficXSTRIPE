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
 *   verteilung      Vollabgleich verteilt sich ueber das Fenster (4.39), Scheduler reiht ihn nur zur Stunde der Firma ein.
 *   fairness        queue_waiting_count zaehlt nur faellige Sync-Jobs anderer Firmen (4.39).
 *   performance     Kennzahlen des Reiters Synchronisation & Performance (4.39).
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

    case 'verteilung':
        // Vollabgleich entzerren (4.39): 40 Firmen verteilen sich ueber das Fenster, jede Firma behaelt ihre Stunde.
        $cfg = jobs_config();
        $cfg['full_sync_hour'] = 3;
        $cfg['full_sync_window_hours'] = 4;
        $hours = [];
        for ($i = 0; $i < 40; $i++) {
            $h = scheduler_full_sync_hour('org-' . $i, $cfg);
            $hours[$h] = ($hours[$h] ?? 0) + 1;
        }
        ksort($hours);
        $stable = scheduler_full_sync_hour('org-7', $cfg) === scheduler_full_sync_hour('org-7', $cfg);
        $cfgOne = $cfg; $cfgOne['full_sync_window_hours'] = 1;
        $allThree = true;
        for ($i = 0; $i < 40; $i++) { if (scheduler_full_sync_hour('org-' . $i, $cfgOne) !== 3) { $allThree = false; } }
        $cfgWrap = $cfg; $cfgWrap['full_sync_hour'] = 22; $cfgWrap['full_sync_window_hours'] = 4;
        $wrapOk = true;
        for ($i = 0; $i < 40; $i++) { $h = scheduler_full_sync_hour('org-' . $i, $cfgWrap); if (!in_array($h, [22, 23, 0, 1], true)) { $wrapOk = false; } }
        // Scheduler reiht den Vollabgleich nur zur Stunde der Firma ein
        seed_org($pdo, $tid);
        $pdo->prepare("INSERT INTO sync_state (tenant_id, status, started_at, finished_at, updated_at) VALUES (?, 'idle', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR))")->execute([$tid]);
        $own = scheduler_full_sync_hour($tid, $cfg);
        $other = ($own + 1) % 24;
        $tsOwn = (new DateTimeImmutable('today'))->setTime($own, 5)->getTimestamp();
        $tsOther = (new DateTimeImmutable('today'))->setTime($other, 5)->getTimestamp();
        $qOther = scheduler_auto_sync($cfg, $tsOther);
        $fullOther = count(array_filter($qOther, static fn(string $q): bool => str_starts_with($q, 'sync_run:full')));
        $pdo->exec('DELETE FROM jobs');
        $qOwn = scheduler_auto_sync($cfg, $tsOwn);
        $fullOwn = count(array_filter($qOwn, static fn(string $q): bool => str_starts_with($q, 'sync_run:full')));
        printf("stunden=%s\nstabil=%d\nfenster1_alle_3=%d\numbruch_ok=%d\nfull_fremde_stunde=%d\nfull_eigene_stunde=%d\n",
            implode(',', array_keys($hours)), $stable ? 1 : 0, $allThree ? 1 : 0, $wrapOk ? 1 : 0, $fullOther, $fullOwn);
        break;

    case 'fairness':
        // queue_waiting_count zaehlt faellige Sync-Jobs anderer Firmen; eigene Firma und spaetere Faelligkeit nicht.
        seed_org($pdo, $tid);
        $other = 'org-' . substr(bin2hex(random_bytes(4)), 0, 8);
        seed_org($pdo, $other);
        printf("leer=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES, $tid));
        queue_push('sync_run', [], ['tenant_id' => $tid, 'dedupe_key' => 'sync:' . $tid]);
        printf("nur_eigene=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES, $tid));
        queue_push('sync_run_sevdesk', [], ['tenant_id' => $other, 'dedupe_key' => 'sync:' . $other]);
        printf("fremde=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES, $tid));
        printf("alle=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES));
        queue_push('mail', [], ['dedupe_key' => 'mail:x']);
        printf("mail_zaehlt_nicht=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES, $tid));
        $pdo->exec('DELETE FROM jobs');
        queue_push('sync_run', [], ['tenant_id' => $other, 'dedupe_key' => 'sync:' . $other, 'available_at' => time() + 3600]);
        printf("spaeter_faellig=%d\n", queue_waiting_count(QUEUE_SYNC_TYPES, $tid));
        printf("fair_seconds=%d\n", jobs_config()['sync_fair_seconds']);
        break;

    case 'performance':
        // Reiter Synchronisation & Performance: Funktionen laufen ohne Fehler, auch ohne Daten und mit Daten.
        require_once $root . '/php-ionos/app/sync_perf.php';
        require_once $root . '/php-ionos/app/invoice_source_switch.php';
        require_once $root . '/php-ionos/app/layout.php';
        require_once $root . '/php-ionos/app/admin_period.php';
        $per = admin_period_from_request(['zeitraum' => '7t']);
        seed_org($pdo, $tid);
        $o = sync_perf_overview($per['from'], $per['to']);
        printf("leer_runs=%d\nleer_wait=%s\n", $o['runs'], $o['queue_wait_avg_ms'] === null ? 'keine' : 'zahl');
        $pdo->prepare("INSERT INTO sync_runs (id, tenant_id, triggered_by, status, started_at, finished_at, duration_ms, steps, checked, skipped, api_calls, api_ms, throttle_ms, detail_calls, contact_calls, api_ms_max, cursor_bytes_max)
                       VALUES (?, ?, 'auto', 'success', DATE_SUB(NOW(), INTERVAL 10 MINUTE), DATE_SUB(NOW(), INTERVAL 8 MINUTE), 120000, 4, 50, 30, 80, 40000, 12000, 20, 10, 900, 20480)")
            ->execute([uuid4(), $tid]);
        $pdo->prepare("INSERT INTO job_runs (id, job_type, job_key, tenant_id, source, status, started_at, heartbeat_at, finished_at, queue_wait_ms) VALUES (?, 'queue:sync_run', 'j1', ?, 'worker', 'success', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 2500)")
            ->execute([uuid4(), $tid]);
        $o = sync_perf_overview($per['from'], $per['to']);
        $top = sync_perf_top_tenants($per['from'], $per['to'], 5);
        $html = sync_perf_render($per);
        printf("runs=%d\napi_calls=%d\nms_je_aufruf=%d\nwait_avg=%d\ndetail=%d\ncursor=%d\ntop=%d\ntop_firma=%s\nhtml_ok=%d\nplan=%d\n",
            $o['runs'], $o['api_calls'], (int)$o['avg_ms_per_call'], (int)$o['queue_wait_avg_ms'], $o['detail_calls'], $o['cursor_bytes_max'], count($top), (string)($top[0]['org_name'] ?? ''),
            (str_contains($html, 'Synchronisation &amp; Performance') && str_contains($html, 'Wirksame Konfiguration') && str_contains($html, 'Testfirma')) ? 1 : 0,
            array_sum(sync_perf_full_sync_plan(jobs_config())));
        break;

    default:
        fwrite(STDERR, "Unbekannter Fall: $fall\n");
        exit(2);
}
