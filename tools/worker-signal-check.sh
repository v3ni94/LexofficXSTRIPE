#!/usr/bin/env bash
#
# Regressionstest fuer das Signalmodell der Worker (app/worker_signals.php, bin/worker.php) mit ECHTEN
# PHP-Prozessen und echten Signalen; Teil B zusaetzlich mit der ECHTEN bin/worker.php gegen eine
# temporaere lokale MariaDB (nur wenn mariadbd und mariadb-install-db vorhanden sind, sonst uebersprungen).
#
# Hintergrund (Version 4.11): Scheduler-/Worker-Container brauchten beim Deployment bis zu 11 Minuten
# ("Container failed to exit within 11m0s of signal 3 - using the force"): Das Basisimage php:*-fpm setzt
# STOPSIGNAL SIGQUIT, die PHP-Prozesse behandelten nur SIGTERM/SIGINT, ein PID-1-Prozess ohne Handler
# bekommt das Signal vom Kernel verworfen. Jetzt: stop_signal SIGTERM, php direkt als PID 1, Handler fuer
# SIGTERM/SIGINT/SIGQUIT, kooperativer Abbruch, Notbremse (SIGALRM) fuer unterbrechbare Jobtypen,
# stop_grace_period 75 s.
#
# Geprueft wird:
#  A1. Leerlauf: SIGTERM, SIGQUIT und SIGINT beenden den Prozess jeweils innerhalb von 2 s mit Exit 0.
#  A2. Laufender, nicht kooperierender Job eines unterbrechbaren Typs (sync_run): nach dem Stop-Signal wirft
#      die Notbremse nach WORKER_STOP_JOB_SECONDS eine WorkerShutdownException (Fortsetzung), Exit 0.
#  A3. Laufender Geldfluss-Job (collections_due) mit kooperativem Punkt: endet ohne erzwungenen Abbruch.
#  A4. Laufender Geldfluss-Job OHNE Kooperation: wird NICHT unterbrochen (Notbremse greift bewusst nicht),
#      laeuft zu Ende; die Docker-Grace-Period bleibt die einzige harte Grenze.
#  A5. Ein zweites Stop-Signal aendert nichts (idempotent).
#  A6. Umgedeutete Notbremse: Faengt eine Zwischenschicht die WorkerShutdownException (catch Throwable) und
#      wirft einen anderen Fehler, stuft worker_job_exception_outcome() ihn trotzdem als "requeued" ein; ein
#      neuer Job ohne Notbremse liefert wieder "retry" bzw. "business".
#  A7. Laufender Mail-Job (mail) OHNE Kooperation: wird wie Geldfluss-Jobs NICHT unterbrochen (SMTP-Dialog
#      zwischen Annahme und Rueckkehr darf nicht abbrechen, sonst Doppelzustellung).
#  B1. Echte bin/worker.php im Leerlauf: SIGTERM und SIGQUIT -> Exit 0 innerhalb von 3 s, Meldung mit
#      Signalname, worker_heartbeats.status = stopped.
#  B2. Nach dem Stop-Signal wird kein neuer Job mehr angenommen: ein waehrend der Leerlaufpause eingereihter
#      Job bleibt queued und unreserviert.
#  B3. Queue-Semantik eines unterbrochenen Jobs (echte DB): Fortsetzung ohne Fehlversuch (queue_requeue wie
#      in job_execute() bei WorkerShutdownException); hart beendeter Worker: queue_release_stale() gibt den
#      Job nach heartbeat_ttl als Fehlversuch frei, kein Verlust, keine dauerhafte Sperre.
#  C.  Statisch: docker-compose.yml setzt fuer Scheduler/Worker stop_signal SIGTERM und eine begrenzte
#      stop_grace_period, startet php direkt (kein sh -c); deploy.sh enthaelt keinen zweiten Worker-Neustart.
#
# Aufruf: bash tools/worker-signal-check.sh        Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIM="$ROOT/tools/lib/worker-signal-sim.php"
# Temporaere MariaDB fuer Teil B (gemeinsam mit tools/scheduler-sync-check.sh)
# shellcheck source=lib/mariadb-sandbox.sh
source "$ROOT/tools/lib/mariadb-sandbox.sh"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
T="$(mktemp -d)"
# Aufraeumen in der richtigen Reihenfolge: ERST die temporaere MariaDB ueber ihren Socket herunterfahren (der
# Socket liegt in $T; wuerde $T zuerst geloescht, bliebe je Testlauf ein mariadbd mit geloeschtem Datadir
# und belegtem Port zurueck), dann verbliebene Kindprozesse (Simulationen, Worker) beenden, zuletzt $T loeschen.
cleanup() {
    mariadb_sandbox_stop          # erst den Server beenden (Socket liegt in $T), dann loeschen
    pkill -TERM -P $$ 2>/dev/null || true
    rm -rf "$T"
}
trap cleanup EXIT INT TERM

# Startet die Simulation als Kindprozess DIESER Shell (kein $(...): sonst hielte der Hintergrundprozess
# die Ausgabe-Pipe offen und "wait" koennte seinen Exitcode nicht liefern), wartet auf "ready", setzt SIM_PID.
start_sim() { # $1 modus, $2 ausgabedatei, $3 sekunden(optional)
    : > "$2"
    php "$SIM" "$ROOT" "$1" "$2" "${3:-4}" >/dev/null 2>&1 &
    SIM_PID=$!
    for _ in $(seq 1 50); do grep -q '^ready$' "$2" 2>/dev/null && break; sleep 0.1; done
}
# Wartet bis zu $2 Sekunden auf das Ende von PID $1; gibt den Exitcode oder 124 (noch am Leben) zurueck.
wait_pid() {
    local pid="$1" limit="$2" i=0
    while kill -0 "$pid" 2>/dev/null && (( i < limit * 10 )); do sleep 0.1; i=$((i + 1)); done
    if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; return 124; fi
    wait "$pid"; return $?
}

echo "A1) Leerlauf: SIGTERM/SIGQUIT/SIGINT beenden innerhalb von 2 s mit Exit 0"
for SIG in TERM QUIT INT; do
    OUT="$T/idle-$SIG.log"; start_sim idle "$OUT"; PID=$SIM_PID; sleep 0.3
    kill -"$SIG" "$PID"; wait_pid "$PID" 2; RC=$?
    if [[ "$RC" -eq 0 ]] && grep -q "^signal=SIG$SIG$" "$OUT" && grep -q "^done$" "$OUT"; then
        ok "SIG$SIG: Exit 0, Signal erkannt ($(grep elapsed "$OUT"))"
    else
        bad "SIG$SIG: Exit $RC, Ausgabe: $(tr '\n' ' ' < "$OUT")"
    fi
done

echo "A2) Nicht kooperierender Job (sync_run): Notbremse nach WORKER_STOP_JOB_SECONDS -> Fortsetzung, Exit 0"
OUT="$T/job-int.log"; WORKER_STOP_JOB_SECONDS=1 start_sim job-interruptible "$OUT"; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; wait_pid "$PID" 4; RC=$?
if [[ "$RC" -eq 0 ]] && grep -q "^requeue:Worker wird beendet" "$OUT" && ! grep -q "^done$" "$OUT"; then
    ok "WorkerShutdownException nach der Notbremse, Exit 0 ($(grep elapsed "$OUT"))"
else
    bad "Notbremse griff nicht wie erwartet: Exit $RC, $(tr '\n' ' ' < "$OUT")"
fi

echo "A3) Geldfluss-Job (collections_due) mit kooperativem Punkt: endet ohne erzwungenen Abbruch"
OUT="$T/job-money.log"; WORKER_STOP_JOB_SECONDS=1 start_sim job-money "$OUT"; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; wait_pid "$PID" 3; RC=$?
if [[ "$RC" -eq 0 ]] && grep -q "^done$" "$OUT" && ! grep -q "^requeue:" "$OUT"; then
    ok "kooperativ beendet, keine WorkerShutdownException ($(grep elapsed "$OUT"))"
else
    bad "Geldfluss-Job nicht kooperativ beendet: Exit $RC, $(tr '\n' ' ' < "$OUT")"
fi

echo "A4) Geldfluss-Job OHNE Kooperation: wird NICHT unterbrochen (Notbremse greift bewusst nicht), laeuft zu Ende"
OUT="$T/job-money-stuck.log"; WORKER_STOP_JOB_SECONDS=1 start_sim job-money-stuck "$OUT" 3; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; wait_pid "$PID" 6; RC=$?
if [[ "$RC" -eq 0 ]] && grep -q "^done$" "$OUT" && ! grep -q "^requeue:" "$OUT" && awk -F'[= ]' '/elapsed/{exit !($2>=2.5)}' "$OUT"; then
    ok "nicht unterbrochen, regulaer beendet nach ~3 s ($(grep elapsed "$OUT"))"
else
    bad "Geldfluss-Job wurde unterbrochen oder endete falsch: Exit $RC, $(tr '\n' ' ' < "$OUT")"
fi

echo "A5) Zweites Stop-Signal ist wirkungslos (idempotent)"
OUT="$T/idle2.log"; start_sim idle "$OUT"; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; kill -TERM "$PID" 2>/dev/null; kill -QUIT "$PID" 2>/dev/null; wait_pid "$PID" 2; RC=$?
[[ "$RC" -eq 0 && "$(grep -c '^signal=' "$OUT")" -eq 1 ]] && ok "genau ein Signal verarbeitet, Exit 0" || bad "Exit $RC, Signale: $(grep -c '^signal=' "$OUT")"

echo "A6) Umgedeutete Notbremse: Ergebnisklasse bleibt 'requeued'; neuer Job ohne Notbremse: 'retry' bzw. 'business'"
OUT="$T/job-conv.log"; WORKER_STOP_JOB_SECONDS=1 start_sim job-converted "$OUT"; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; wait_pid "$PID" 4; RC=$?
if [[ "$RC" -eq 0 ]] && grep -q "^outcome=requeued$" "$OUT" && grep -q "^outcome_fresh=retry$" "$OUT" && grep -q "^outcome_failed=business$" "$OUT"; then
    ok "umgedeutete Notbremse -> requeued; frischer Job -> retry/business ($(grep elapsed "$OUT"))"
else
    bad "Ergebnisklassen falsch: Exit $RC, $(tr '\n' ' ' < "$OUT")"
fi

echo "A7) Mail-Job OHNE Kooperation: wird NICHT unterbrochen (wie Geldfluss-Jobs), laeuft zu Ende"
OUT="$T/job-mail-stuck.log"; WORKER_STOP_JOB_SECONDS=1 start_sim job-mail-stuck "$OUT" 3; PID=$SIM_PID; sleep 0.3
kill -TERM "$PID"; wait_pid "$PID" 6; RC=$?
if [[ "$RC" -eq 0 ]] && grep -q "^done$" "$OUT" && ! grep -q "^requeue:" "$OUT" && awk -F'[= ]' '/elapsed/{exit !($2>=2.5)}' "$OUT"; then
    ok "Mail-Job nicht unterbrochen, regulaer beendet nach ~3 s ($(grep elapsed "$OUT"))"
else
    bad "Mail-Job wurde unterbrochen oder endete falsch: Exit $RC, $(tr '\n' ' ' < "$OUT")"
fi

echo "B) Echte bin/worker.php gegen eine temporaere lokale MariaDB"
if mariadb_sandbox_available; then
    if ! mariadb_sandbox_start "$T/mdb"; then
        bad "Temporaere MariaDB startete nicht"
    else
        QSIM="$ROOT/tools/lib/worker-queue-sim.php"

        echo "B1) Leerlauf-Worker: SIGTERM/SIGQUIT -> Exit 0 innerhalb von 3 s, Status stopped"
        for SIG in TERM QUIT; do
            WOUT="$T/worker-$SIG.log"
            WORKER_HEARTBEAT_FILE="$T/hb-$SIG" php "$ROOT/php-ionos/bin/worker.php" --pool=maintenance --sleep=1 > "$WOUT" 2>&1 &
            WPID=$!
            for _ in $(seq 1 60); do grep -q 'gestartet' "$WOUT" 2>/dev/null && break; sleep 0.1; done
            sleep 0.5
            kill -"$SIG" "$WPID"; wait_pid "$WPID" 3; RC=$?
            WID="$(sed -n 's/^Worker \([^ ]*\) gestartet.*/\1/p' "$WOUT" | head -n1)"
            WSTATE="$(php "$QSIM" "$ROOT" worker "$WID" 2>/dev/null)"
            if [[ "$RC" -eq 0 ]] && grep -q "beendet.*(SIG$SIG)" "$WOUT" && [[ "$WSTATE" == "stopped" ]]; then
                ok "SIG$SIG: echter Worker beendet (Exit 0, worker_heartbeats=stopped)"
            else
                bad "SIG$SIG: Exit $RC, DB-Status '$WSTATE', Ausgabe: $(tr '\n' ' ' < "$WOUT")"
            fi
        done

        echo "B2) Nach dem Stop-Signal kein neuer Job: waehrend der Leerlaufpause eingereihter Job bleibt queued"
        WOUT="$T/worker-nonew.log"
        WORKER_HEARTBEAT_FILE="$T/hb-nonew" php "$ROOT/php-ionos/bin/worker.php" --pool=maintenance --sleep=4 > "$WOUT" 2>&1 &
        WPID=$!
        for _ in $(seq 1 60); do grep -q 'gestartet' "$WOUT" 2>/dev/null && break; sleep 0.1; done
        sleep 1  # Worker ist jetzt in der 4-s-Leerlaufpause
        JID="$(php "$QSIM" "$ROOT" push)"
        kill -TERM "$WPID"; wait_pid "$WPID" 3; RC=$?
        JSTATE="$(php "$QSIM" "$ROOT" state "$JID")"
        if [[ "$RC" -eq 0 ]] && printf '%s' "$JSTATE" | jq -e '.status=="queued" and (.locked_by==null or .locked_by=="") and (.attempts|tonumber)==0' >/dev/null; then
            ok "Worker beendet ohne den neuen Job zu reservieren ($JSTATE)"
        else
            bad "Job wurde trotz Stop-Signal angefasst oder Worker haengt: Exit $RC, $JSTATE, $(tail -2 "$WOUT" | tr '\n' ' ')"
        fi
        mariadb --socket="$MDB_SOCK" -uroot "$MDB_DB" -e "UPDATE jobs SET status='cancelled' WHERE id='$JID'" 2>/dev/null

        echo "B3) Queue-Semantik: Fortsetzung ohne Fehlversuch; hart beendeter Worker -> Freigabe nach heartbeat_ttl"
        # Der CLI-Logger schreibt JSON-Protokollzeilen auf stdout; das Ergebnis steht in der letzten Zeile.
        R="$(php "$QSIM" "$ROOT" requeue 2>&1 | tail -n1)"; [[ "$R" == "OK requeue" ]] && ok "Unterbrochener Job: queued, attempts unveraendert, locked_by leer, erneut reservierbar" || bad "$R"
        R="$(php "$QSIM" "$ROOT" stale 2>&1 | tail -n1)"; [[ "$R" == "OK stale" ]] && ok "Hart beendeter Worker: Job nach heartbeat_ttl als retry freigegeben (heartbeat_stale), keine dauerhafte Sperre" || bad "$R"
    fi
else
    echo "  (uebersprungen: mariadbd/mariadb-install-db nicht verfuegbar)"
fi

echo "C) Statisch: Compose-Stop-Konfiguration und kein zweiter Worker-Neustart"
python3 "$ROOT/tools/compose-check.py" >/dev/null 2>&1 && ok "tools/compose-check.py (stop_signal SIGTERM, Grace 60 bis 120 s, php als PID 1, kein restart -t) bestanden" || bad "tools/compose-check.py meldet Fehler"
grep -q "stop_grace_period: 75s" "$ROOT/deploy/vps/docker-compose.yml" && ok "stop_grace_period 75 s fuer Scheduler/Worker" || bad "stop_grace_period 75 s nicht gesetzt"
grep -q '\[SIGTERM, SIGINT, SIGQUIT\]' "$ROOT/php-ionos/app/worker_signals.php" && ok "Handler fuer SIGTERM, SIGINT und SIGQUIT installiert" || bad "SIGQUIT-Handler fehlt"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
