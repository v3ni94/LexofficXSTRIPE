<?php
/**
 * Simulierte Worker-Schleife OHNE Datenbank fuer tools/worker-signal-check.sh: nutzt die echte
 * app/worker_signals.php (Signalbehandlung, kooperativer Abbruch, Notbremse) mit einer Stub-Ausnahme an
 * Stelle von app/jobs.php.
 *
 *   php tools/lib/worker-signal-sim.php <repo> <modus> <ausgabedatei> [sekunden]
 *   Modi: idle                 Leerlaufschleife (sleep 1), endet beim Stop-Signal
 *         job-interruptible    Job ohne Kooperation (Typ sync_run): nur die Notbremse beendet ihn
 *         job-money            Geldfluss-Job (collections_due) mit kooperativem Punkt alle 0,5 s
 *         job-money-stuck      Geldfluss-Job OHNE Kooperation: darf NICHT unterbrochen werden, endet nach [sekunden]
 *         job-mail-stuck       Mail-Job OHNE Kooperation: darf ebenfalls NICHT unterbrochen werden
 *         job-converted        Notbremse wird von einer Zwischenschicht (catch Throwable) in einen anderen Fehler
 *                              umgedeutet: worker_job_exception_outcome() muss trotzdem "requeued" liefern
 * Schreibt Zeilen "ready", "signal=<name>", "requeue:<meldung>" oder "done", "elapsed=<s> stop=<0|1>",
 * im Modus job-converted zusaetzlich "outcome=...", "outcome_fresh=...", "outcome_failed=...".
 */
declare(strict_types=1);

function config(string $key, $default = null) { return $default; }
// Stubs der Ausnahmeklassen aus app/queue.php (dieselben Namen, damit worker_job_exception_outcome() greift)
class JobRequeueException extends RuntimeException {}
class JobFailedException extends RuntimeException {}
class CircuitOpenException extends RuntimeException {}
require $argv[1] . '/php-ionos/app/worker_signals.php';

$mode = $argv[2] ?? 'idle';
$out = $argv[3] ?? 'php://stdout';
$seconds = (float)($argv[4] ?? 4);
$emit = static function (string $line) use ($out): void { file_put_contents($out, $line . "\n", FILE_APPEND); };

worker_signals_install(static function (int $signo) use ($emit): void { $emit('signal=' . worker_stop_signal_name()); });
$emit('ready');
$t0 = microtime(true);
try {
    switch ($mode) {
        case 'idle':
            while (!worker_stop_requested()) {
                sleep(1);
            }
            break;
        case 'job-interruptible':
            worker_job_begin(['type' => 'sync_run']);
            try {
                while (true) { // kooperiert bewusst nicht
                    sleep(1);
                }
            } finally {
                worker_job_end();
            }
            break;
        case 'job-money':
            worker_job_begin(['type' => 'collections_due']);
            try {
                while (!worker_stop_requested()) { // kooperativer Punkt zwischen zwei "Einzuegen"
                    usleep(500000);
                }
            } finally {
                worker_job_end();
            }
            break;
        case 'job-money-stuck':
        case 'job-mail-stuck':
            worker_job_begin(['type' => $mode === 'job-mail-stuck' ? 'mail' : 'collections_due']);
            try {
                $end = microtime(true) + $seconds;
                while (microtime(true) < $end) { // kooperiert nicht: Notbremse darf trotzdem NICHT greifen
                    sleep(1);
                }
            } finally {
                worker_job_end();
            }
            break;
        case 'job-converted':
            // Wie job_execute(): worker_job_begin, Job laeuft; die Notbremse wirft WorkerShutdownException, eine
            // "Zwischenschicht" (z.B. sync_state_step) faengt Throwable und wirft einen ANDEREN Fehler. Die
            // Ergebnisklasse muss trotzdem "requeued" sein. Danach ein neuer Job ohne Notbremse: "retry"/"business".
            worker_job_begin(['type' => 'sync_run']);
            $converted = null;
            try {
                try {
                    while (true) { // kooperiert bewusst nicht
                        sleep(1);
                    }
                } catch (Throwable $inner) { // fremde Zwischenschicht deutet um
                    $converted = new RuntimeException('umgedeutet: ' . $inner->getMessage(), 0, $inner);
                }
            } finally {
                worker_job_end();
            }
            $emit('outcome=' . worker_job_exception_outcome($converted ?? new RuntimeException('keine Ausnahme aufgetreten')));
            worker_job_begin(['type' => 'sync_run']); // neuer Job: Flag der Notbremse zurueckgesetzt
            $emit('outcome_fresh=' . worker_job_exception_outcome(new RuntimeException('echter technischer Fehler')));
            $emit('outcome_failed=' . worker_job_exception_outcome(new JobFailedException('fachlicher Fehler')));
            worker_job_end();
            break;
        default:
            fwrite(STDERR, "Unbekannter Modus $mode\n");
            exit(2);
    }
    $emit('done');
} catch (WorkerShutdownException $e) {
    $emit('requeue:' . $e->getMessage());
}
$emit(sprintf('elapsed=%.1f stop=%d', microtime(true) - $t0, worker_stop_requested() ? 1 : 0));
exit(0);
