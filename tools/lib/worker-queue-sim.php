<?php
/**
 * Queue-Semantik mit ECHTER Datenbank fuer tools/worker-signal-check.sh (nur mit lokaler Test-MariaDB):
 * prueft, wie ein beim Worker-Shutdown unterbrochener bzw. ein hart beendeter Job behandelt wird.
 *
 *   SMARTEINZUG_CONFIG=<test-config> php tools/lib/worker-queue-sim.php <repo> <fall>
 *   Faelle: requeue   Job reservieren, wie job_execute() bei WorkerShutdownException fortsetzen lassen
 *                     (queue_requeue): status queued, attempts unveraendert, locked_by leer, _continuations 1
 *           stale     Job reservieren, Heartbeat kuenstlich veralten (SIGKILL-Fall), queue_release_stale():
 *                     status retry, last_error heartbeat_stale, locked_by leer, kein Verlust
 *           push      Job vom Typ maintenance einreihen und dessen id ausgeben (fuer den Worker-Test)
 *           state     Status/locked_by/attempts eines Jobs ausgeben: state <id>
 *           worker    Status eines Workers aus worker_heartbeats: worker <worker_id-praefix>
 * Ausgabe: "OK <fall>" bzw. "FEHLER <fall>: <grund>" (Exit 1).
 */
declare(strict_types=1);
define('LOG_SERVICE', 'cli');
require $argv[1] . '/php-ionos/bin/_cli.php';
require_once $argv[1] . '/php-ionos/app/jobs.php';

$case = $argv[2] ?? '';
$fail = static function (string $why) use ($case): never { echo "FEHLER $case: $why\n"; exit(1); };

switch ($case) {
    case 'push':
        $job = queue_push('maintenance', ['test' => true], ['tenant_id' => null]);
        echo $job['id'], "\n";
        exit(0);
    case 'state':
        $st = db()->prepare('SELECT status, locked_by, attempts, last_error, payload FROM jobs WHERE id = ?');
        $st->execute([$argv[3] ?? '']);
        $row = $st->fetch() ?: [];
        echo json_encode($row, JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    case 'worker':
        $st = db()->prepare('SELECT status FROM worker_heartbeats WHERE worker_id LIKE ? ORDER BY heartbeat_at DESC LIMIT 1');
        $st->execute([($argv[3] ?? '') . '%']);
        echo (string)$st->fetchColumn(), "\n";
        exit(0);
    case 'requeue':
        $job = queue_push('maintenance', ['sim' => 'requeue']);
        $reserved = queue_reserve('sim-worker', ['maintenance']);
        if (!$reserved || $reserved['id'] !== $job['id']) {
            $fail('Job konnte nicht reserviert werden');
        }
        // Genau das tut job_execute() bei WorkerShutdownException (Unterklasse von JobRequeueException):
        worker_db_rollback_if_open();
        queue_requeue($reserved, 0, 'Worker wird beendet, Fortsetzung eingeplant');
        $after = queue_get($job['id']);
        if (($after['status'] ?? '') !== 'queued') { $fail('status ist ' . ($after['status'] ?? '?') . ', erwartet queued'); }
        if (!empty($after['locked_by'])) { $fail('locked_by nicht geleert'); }
        if ((int)$after['attempts'] !== 0) { $fail('attempts=' . $after['attempts'] . ', erwartet 0 (Fortsetzung zaehlt nicht als Fehlversuch)'); }
        if ((int)(($after['payload_data']['_continuations'] ?? 0)) !== 1) { $fail('_continuations nicht 1'); }
        $again = queue_reserve('sim-worker-2', ['maintenance']);
        if (!$again || $again['id'] !== $job['id']) { $fail('Job nach der Fortsetzung nicht erneut reservierbar'); }
        queue_complete($again, 'cancelled');
        echo "OK requeue\n";
        exit(0);
    case 'stale':
        $job = queue_push('maintenance', ['sim' => 'stale']);
        $reserved = queue_reserve('sim-worker-killed', ['maintenance']);
        if (!$reserved || $reserved['id'] !== $job['id']) { $fail('Job konnte nicht reserviert werden'); }
        // SIGKILL-Fall: der Worker konnte nichts mehr schreiben, der Heartbeat veraltet ueber heartbeat_ttl hinaus
        $ttl = (int)queue_type_defaults('maintenance')['heartbeat_ttl'];
        db()->prepare('UPDATE jobs SET heartbeat_at = ? WHERE id = ?')->execute([queue_utc(queue_now() - $ttl - 120), $job['id']]);
        $n = queue_release_stale();
        $after = queue_get($job['id']);
        if ($n < 1) { $fail('queue_release_stale() hat nichts freigegeben'); }
        if (($after['status'] ?? '') !== 'retry') { $fail('status ist ' . ($after['status'] ?? '?') . ', erwartet retry'); }
        if (!empty($after['locked_by'])) { $fail('locked_by nicht geleert'); }
        if (!str_contains((string)($after['last_error'] ?? ''), 'heartbeat_stale')) { $fail('last_error nennt heartbeat_stale nicht'); }
        db()->prepare("UPDATE jobs SET status = 'cancelled', finished_at = NOW() WHERE id = ?")->execute([$job['id']]);
        echo "OK stale\n";
        exit(0);
    default:
        fwrite(STDERR, "Unbekannter Fall '$case'\n");
        exit(2);
}
