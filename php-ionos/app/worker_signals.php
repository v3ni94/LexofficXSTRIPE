<?php
/**
 * Signalbehandlung und begrenzter, kontrollierter Shutdown fuer langlaufende CLI-Prozesse
 * (bin/worker.php, bin/scheduler.php, bin/host-metrics.php).
 *
 * Signalmodell (Details: docs/vps/06-betrieb.md, Abschnitt "Signalmodell der Worker"):
 *  - Stop-Signale sind SIGTERM (Docker stop_signal in docker-compose.yml), SIGINT (Konsole) und SIGQUIT.
 *    SIGQUIT wird mitbehandelt, weil das Basisimage php:*-fpm STOPSIGNAL SIGQUIT vorgibt (richtig fuer
 *    php-fpm) und dieses Signal jeden Container aus dem Image traf: Ein PID-1-Prozess OHNE Handler bekommt
 *    ein solches Signal vom Kernel verworfen (Init eines PID-Namensraums ignoriert Signale mit
 *    Standardaktion) und lief bis zum Ablauf der Grace-Period weiter ("Container failed to exit within
 *    11m0s of signal 3 - using the force"). Mit Handler UND eindeutigem stop_signal SIGTERM passiert das
 *    nicht mehr; der Handler fuer SIGQUIT bleibt als zweite Sicherung. Die Handler werden VOR dem ersten
 *    Datenbankzugriff installiert, damit auch ein waehrend eines blockierten Verbindungsaufbaus
 *    eintreffendes Signal nicht verworfen wird.
 *  - Nach dem Signal nimmt der Prozess keine neue Arbeit mehr an (worker_stop_requested()). Ein bereits
 *    laufender Job laeuft bis zum naechsten KOOPERATIVEN Abbruchpunkt weiter (Synchronisation: nach jedem
 *    Schritt, Einzuege: zwischen zwei Einzuegen, Klaerung: zwischen zwei Versuchen, Wartung: zwischen zwei
 *    Teilaufgaben, siehe app/jobs.php und app/collections.php) und wird dort als Fortsetzung eingeplant
 *    (JobRequeueException, KEIN Fehlversuch, Cursor bleibt erhalten).
 *  - Notbremse: WORKER_STOP_JOB_SECONDS (Standard 30 s) nach dem Signal loest SIGALRM aus. Ist dann noch
 *    ein Job aktiv UND ist sein Typ unterbrechbar, wirft der Handler eine WorkerShutdownException in den
 *    laufenden Code; job_execute() behandelt sie wie eine Fortsetzung. NICHT unterbrechbar sind die
 *    geldbewegenden Typen (collections_due, unclear_attempts) und der Mailversand (mail): Ein Stripe-Aufruf
 *    zwischen "gesendet" und "verbucht" bzw. ein SMTP-Dialog zwischen Annahme der Nachricht und Rueckkehr
 *    darf nicht abgebrochen werden (Doppeleinzug, Doppelzustellung; Versuchsjournal siehe
 *    docs/payment-safety.md). Diese Typen enden ausschliesslich an ihren kooperativen Punkten.
 *  - Umgedeutete Notbremse: Auf dem Weg nach oben durchquert die WorkerShutdownException fremde
 *    catch (Throwable)-Bloecke (z.B. sync_state_step(), mail_send_direct()), die sie in einen anderen
 *    Fehler umdeuten koennen. Der Handler merkt sich deshalb, DASS er geworfen hat
 *    (worker_shutdown_interrupted()); worker_job_exception_outcome() stuft danach JEDE Ausnahme des Jobs
 *    als Fortsetzung ohne Fehlversuch ein. Ohne diese Sicherung zaehlte z.B. ein unterbrochener
 *    Sync-Schritt als Fehlversuch mit Backoff (bis zu 1 h Verzoegerung je Deployment).
 *  - Docker stop_grace_period (75 s in docker-compose.yml) ist die harte Obergrenze: 30 s Notbremse +
 *    30 s laengster einzelner externer Aufruf (Stripe) + 15 s Reserve fuer Heartbeat und Abmeldung. Das
 *    deckt den Normalfall ab (ein Einzug besteht aus einem Lexware- und mehreren Stripe-Aufrufen bei
 *    normaler Latenz); bei gestoerter Anbindung kann ein Geldfluss-Job seinen kooperativen Punkt spaeter
 *    erreichen und wird dann hart beendet. Sein Job bleibt hoechstens bis heartbeat_ttl reserviert und wird
 *    dann von queue_release_stale() als Fehlversuch freigegeben (kein Verlust, keine dauerhafte Sperre); das
 *    Versuchsjournal der Einzuege sichert den Geldfluss unabhaengig davon.
 *  - Restrisiko (Mikrosekunden): Faellt die Notbremse genau zwischen die letzte Datenaenderung eines Jobs
 *    und worker_job_end(), wird ein bereits fertiger Job als Fortsetzung erneut ausgefuehrt. Unterbrechbar
 *    sind deshalb nur Typen, deren Wiederholung fachlich unschaedlich ist (Synchronisation: idempotente
 *    Upserts; Wartung und Monitoring: idempotente Bereinigungen; Alarme und Mandatserinnerungen: im
 *    schlimmsten Fall eine doppelte Benachrichtigung, das kleinere Uebel gegenueber einem harten SIGKILL
 *    mit bis zu 30 Minuten Reservierung ueber heartbeat_ttl).
 *
 * Diese Datei ist ohne Datenbank und ohne app/jobs.php ladbar (host-metrics.php); WorkerShutdownException
 * wird nur definiert, wenn JobRequeueException (app/queue.php) bereits geladen ist, und nur dann geworfen.
 */
declare(strict_types=1);

const WORKER_STOP_JOB_SECONDS_DEFAULT = 30;
/**
 * Obergrenze fuer WORKER_STOP_JOB_SECONDS: Die Grace-Period von 75 s ist als 30 + 30 + 15 s hergeleitet
 * (docker-compose.yml). Ein groesserer Wert schoebe die Notbremse hinter Dockers SIGKILL, Jobs wuerden
 * wieder hart abgebrochen. Kleinere Werte (Regressionstests: 1) sind zulaessig.
 */
const WORKER_STOP_JOB_SECONDS_MAX = 30;
/** Jobtypen, die nie erzwungen unterbrochen werden (Geldfluss, Mailzustellung): nur kooperativer Abbruch. */
const WORKER_NO_FORCED_ABORT_TYPES = ['collections_due', 'unclear_attempts', 'mail'];

if (class_exists('JobRequeueException') && !class_exists('WorkerShutdownException')) {
    /** Job wegen Worker-Shutdown kontrolliert unterbrochen: wie eine Fortsetzung, kein Fehlversuch. */
    class WorkerShutdownException extends JobRequeueException {}
}

/**
 * Handler fuer SIGTERM/SIGINT/SIGQUIT (Stop) und SIGALRM (Notbremse) installieren. $onStop wird beim
 * ersten Stop-Signal einmal aufgerufen (z.B. fuer eine Protokollzeile); Ausnahmen darin werden verworfen.
 */
function worker_signals_install(?callable $onStop = null): void
{
    $GLOBALS['worker_stop_requested'] = false;
    $GLOBALS['worker_stop_signal'] = null;
    $GLOBALS['worker_busy_job'] = null;
    $GLOBALS['worker_alarm_thrown'] = false;
    if (!function_exists('pcntl_async_signals')) {
        return;
    }
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) {
        pcntl_signal($sig, static function (int $signo) use ($onStop): void {
            if (!empty($GLOBALS['worker_stop_requested'])) {
                return; // zweites Signal: nichts weiter, die Notbremse laeuft bereits
            }
            $GLOBALS['worker_stop_requested'] = true;
            $GLOBALS['worker_stop_signal'] = $signo;
            if ($onStop !== null) {
                try {
                    $onStop($signo);
                } catch (Throwable $e) {
                    // Protokollfehler duerfen den Shutdown nicht verhindern
                }
            }
            if ($GLOBALS['worker_busy_job'] !== null) {
                worker_arm_stop_alarm();
            }
        });
    }
    pcntl_signal(SIGALRM, static function (): void {
        $job = $GLOBALS['worker_busy_job'] ?? null;
        if ($job === null || empty($GLOBALS['worker_stop_requested'])) {
            return;
        }
        if (!worker_forced_abort_allowed((string)($job['type'] ?? '')) || !class_exists('WorkerShutdownException')) {
            // Geldfluss/Mail: kein erzwungener Abbruch, der kooperative Punkt folgt; Docker-Grace bleibt harte Grenze.
            return;
        }
        $GLOBALS['worker_alarm_thrown'] = true;
        throw new WorkerShutdownException('Worker wird beendet, Job wird kontrolliert unterbrochen und fortgesetzt');
    });
}

/** true, sobald ein Stop-Signal eingetroffen ist: keine neue Arbeit mehr annehmen. */
function worker_stop_requested(): bool
{
    return !empty($GLOBALS['worker_stop_requested']);
}

/** true, wenn die Notbremse fuer den AKTUELLEN Job bereits eine WorkerShutdownException geworfen hat. */
function worker_shutdown_interrupted(): bool
{
    return !empty($GLOBALS['worker_alarm_thrown']);
}

/** Name des eingetroffenen Stop-Signals fuer Protokollzeilen (z.B. "SIGTERM"), '' wenn keines. */
function worker_stop_signal_name(): string
{
    $s = $GLOBALS['worker_stop_signal'] ?? null;
    if ($s === null) {
        return '';
    }
    foreach (['SIGTERM', 'SIGINT', 'SIGQUIT'] as $name) {
        if (defined($name) && constant($name) === $s) {
            return $name;
        }
    }
    return 'Signal ' . (int)$s;
}

/**
 * Sekunden nach dem Stop-Signal, nach denen ein unterbrechbarer Job kontrolliert abgebrochen wird
 * (Umgebung WORKER_STOP_JOB_SECONDS vor config('queue')['stop_job_seconds']), begrenzt auf 1 bis
 * WORKER_STOP_JOB_SECONDS_MAX.
 */
function worker_stop_job_seconds(): int
{
    $v = 0;
    $env = getenv('WORKER_STOP_JOB_SECONDS');
    if ($env !== false && $env !== '' && (int)$env > 0) {
        $v = (int)$env;
    } else {
        $cfg = function_exists('config') ? (array)config('queue', []) : [];
        $v = (int)($cfg['stop_job_seconds'] ?? WORKER_STOP_JOB_SECONDS_DEFAULT);
    }
    if ($v <= 0) {
        $v = WORKER_STOP_JOB_SECONDS_DEFAULT;
    }
    return max(1, min(WORKER_STOP_JOB_SECONDS_MAX, $v));
}

function worker_forced_abort_allowed(string $type): bool
{
    return !in_array($type, WORKER_NO_FORCED_ABORT_TYPES, true);
}

function worker_arm_stop_alarm(): void
{
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(worker_stop_job_seconds());
    }
}

/** Beginn eines Jobs melden; kam das Stop-Signal schon vorher, laeuft die Notbremse ab sofort. */
function worker_job_begin(array $job): void
{
    $GLOBALS['worker_busy_job'] = $job;
    $GLOBALS['worker_alarm_thrown'] = false;
    if (worker_stop_requested()) {
        worker_arm_stop_alarm();
    }
}

/** Ende eines Jobs melden (Erfolg oder Fehler): Notbremse entschaerfen. */
function worker_job_end(): void
{
    $GLOBALS['worker_busy_job'] = null;
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
}

/**
 * Ergebnisklasse einer aus dem Job entkommenen Ausnahme: requeued | business | circuit_open | retry.
 * Eine JobRequeueException (kooperativer Punkt, Zeitbudget, WorkerShutdownException) ist immer eine
 * Fortsetzung. Hat die Notbremse fuer diesen Job bereits geworfen (worker_shutdown_interrupted()), gilt das
 * auch fuer jede UMGEDEUTETE Ausnahme (Zwischenschicht fing Throwable und warf einen anderen Fehler):
 * Massgeblich ist der Shutdown, nicht die Umdeutung. Ohne Datenbank pruefbar (tools/worker-signal-check.sh);
 * die Ausnahmeklassen stammen aus app/queue.php, instanceof gegen eine nicht geladene Klasse ist false.
 */
function worker_job_exception_outcome(Throwable $e): string
{
    if ($e instanceof JobRequeueException) {
        return 'requeued';
    }
    if (worker_shutdown_interrupted()) {
        return 'requeued';
    }
    if ($e instanceof JobFailedException) {
        return 'business';
    }
    if ($e instanceof CircuitOpenException) {
        return 'circuit_open';
    }
    return 'retry';
}

/**
 * Nach einer Unterbrechung mitten im Job eine ggf. offene Datenbanktransaktion zurueckrollen, damit die
 * anschliessende Statusaenderung des Jobs (Fortsetzung/Fehlversuch) nicht in einer nie bestaetigten
 * Transaktion verloren geht.
 */
function worker_db_rollback_if_open(): void
{
    if (!function_exists('db')) {
        return;
    }
    try {
        $pdo = db();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        // nichts: die Verbindung ist ohnehin unbrauchbar, der Job wird spaeter freigegeben
    }
}
