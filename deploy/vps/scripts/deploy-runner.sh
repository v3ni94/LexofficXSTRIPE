#!/usr/bin/env bash
#
# SmartEinzug: Serverseitiger Deploy-Runner. Entkoppelt das eigentliche Deployment (deploy.sh, mehrere
# Minuten: Image-Build, Container-Neustart, Migration) von der SSH-Sitzung des GitHub-Workflows.
#
#   bash /opt/smarteinzug/releases/<git-sha>/deploy/vps/scripts/deploy-runner.sh <git-sha>
#
# HINTERGRUND (Ursache des Fehlers "client_loop: send disconnect: Broken pipe"):
# GitHub Actions rief frueher direkt "ssh ... deploy.sh <sha>" auf und wartete auf EINEM SSH-Kanal
# ueber die gesamte Deploymentdauer (Image-Build, docker compose up, Migration). Bricht dieser Kanal
# waehrend eines mehrminuetigen Vorgangs kurz ab (Netzwerkstoerung, NAT-Timeout, GitHub-Runner-Hiccup),
# erhaelt der entfernte Prozess (deploy.sh) ein SIGHUP bzw. SIGPIPE und stirbt MITTEN im
# "docker compose up": neu erzeugte Container bleiben im Zustand "Created" (nie gestartet), waehrend
# alte Container teils weiterlaufen. Der Symlink "current" wurde dabei noch NICHT umgestellt und die
# Migration noch NICHT erreicht (siehe deploy.sh: Reihenfolge up -> warten auf healthy -> current
# umstellen -> migrieren) - das ist der sichere Teil der bestehenden Logik. Das eigentliche Problem war,
# dass diese Logik gar nicht zu Ende laufen konnte, weil der SSH-Kanal selbst das Signal zum Absturz gab.
#
# LOESUNG: Dieses Skript laeuft NICHT im Vordergrund der SSH-Sitzung. Es
#   1. Prueft nicht-blockierend, ob bereits ein Deployment/Rollback laeuft (dieselbe Sperrdatei wie
#      deploy.sh/rollback.sh). Laeuft bereits eines, wird NICHTS gestartet (kein Doppel-Deploy durch
#      einen GitHub-Retry oder einen zweiten Workflow-Lauf); Exit-Code 3, der aktuelle Status wird
#      ausgegeben.
#   2. Haelt die Sperre selbst (Dateideskriptor 9) und startet sich per "setsid" selbst neu unter EINER
#      NEUEN SITZUNG, die vom SSH-Kanal unabhaengig ist: Ein Abbruch der SSH-Verbindung sendet KEIN
#      SIGHUP mehr an den laufenden Deploy-Prozess. Der Dateideskriptor der Sperre wird an den neuen
#      Prozess vererbt (kein erneutes Locking, keine Race Condition zwischen Pruefung und Uebernahme).
#   3. Schreibt PID-Datei, Statusdatei (JSON, siehe deploy_status() unten) und ein eigenes Protokoll
#      unter /opt/smarteinzug/logs; ruft danach deploy.sh mit SMARTEINZUG_LOCK_HELD=1 auf (deploy.sh
#      versucht dann NICHT, dieselbe Sperre ein zweites Mal zu erwerben).
#   4. Der urspruengliche, im Vordergrund laufende Teil (der SSH-Kanal sieht nur DIESEN Teil) kehrt
#      sofort zurueck ("TRIGGERED"), sobald der Hintergrundprozess gestartet ist - typischerweise
#      innerhalb von 1-2 Sekunden. Der GitHub-Workflow fragt den Fortschritt danach über kurze,
#      unabhaengige SSH-Verbindungen ab (scripts/deploy-status.sh); jede einzelne Abfrage darf
#      abbrechen, ohne das laufende Deployment zu gefaehrden.
#
# Das ist bewusst KEIN "blindes nohup ... &": Es gibt eine explizite, nicht-blockierende Sperrpruefung
# VOR dem Start, eine PID-Datei, eine strukturierte Statusdatei mit Phase/Exit-Code/Zeitstempeln und ein
# vollstaendiges Protokoll - der GitHub-Workflow kann den Ausgang jederzeit zuverlaessig ermitteln, auch
# nach eigenem Verbindungsabbruch oder einem Neustart des Workflows.
#
# Rechte: laeuft als Benutzer "deploy" (kein root noetig), verwendet dieselbe Sperrdatei wie deploy.sh.
set -uo pipefail

BASE=/opt/smarteinzug
DEPLOY_DIR="$BASE/deploy"
LOG_DIR="$BASE/logs"
LOCK_FILE="$DEPLOY_DIR/.deploy.lock"
STATUS_FILE="$DEPLOY_DIR/.deploy-status.json"
PID_FILE="$DEPLOY_DIR/.deploy.pid"

SHA="${2:-${1:?Nutzung: deploy-runner.sh <git-sha>}}"
case "$SHA" in
    current|.*|*/*|*' '*|"")
        echo "::error:: Ungueltiger Release-Name: $SHA" >&2
        exit 1
        ;;
esac
RELEASE_SH="$BASE/releases/$SHA/deploy/vps/scripts/deploy.sh"

install -d -m 750 "$DEPLOY_DIR" "$LOG_DIR"

# JSON-Statusdatei atomar schreiben (kein Secret enthalten: nur Phase, SHA, PID, Zeitstempel, Exit-Code,
# Protokolldateiname und eine kurze Meldung).
deploy_status() {
    local phase="$1" exit_code="${2-null}" message="${3:-}"
    local esc_msg tmp
    esc_msg="$(printf '%s' "$message" | tr -d '\r' | tr '\n' ' ' | sed 's/"/\\"/g')"
    tmp="$STATUS_FILE.tmp.$$"
    printf '{"phase":"%s","sha":"%s","pid":%s,"started_at":"%s","updated_at":"%s","exit_code":%s,"log_file":"%s","message":"%s"}\n' \
        "$phase" "$SHA" "${WORKER_PID:-null}" "${STARTED_AT:-}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        "$exit_code" "$(basename "${RUNNER_LOG:-}")" "$esc_msg" > "$tmp"
    mv -f "$tmp" "$STATUS_FILE"
}

# --- Modus "--worker": laeuft bereits detached unter setsid, haelt die Sperre (fd 9 ererbt). ---------
if [[ "${1:-}" == "--worker" ]]; then
    STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    WORKER_PID=$BASHPID
    echo "$WORKER_PID" > "$PID_FILE"
    RUNNER_LOG="${SMARTEINZUG_RUNNER_LOG:?intern: SMARTEINZUG_RUNNER_LOG fehlt}"
    deploy_status running null "Deployment gestartet (PID $WORKER_PID)"

    if [[ ! -f "$RELEASE_SH" ]]; then
        deploy_status failed 1 "deploy.sh im Release nicht gefunden: $RELEASE_SH"
        rm -f "$PID_FILE"
        exit 1
    fi

    # SMARTEINZUG_STATUS_FILE: deploy.sh aktualisiert darin nur "step"/"updated_at" (atomar, siehe dort
    # deploy_step()); phase/sha/pid/exit_code schreibt ausschliesslich dieser Runner.
    if SMARTEINZUG_LOCK_HELD=1 SMARTEINZUG_STATUS_FILE="$STATUS_FILE" bash "$RELEASE_SH" "$SHA"; then
        deploy_status success 0 "Deployment abgeschlossen"
        rc=0
    else
        rc=$?
        deploy_status failed "$rc" "Deployment fehlgeschlagen (exit $rc), Protokoll pruefen: $LOG_DIR/$(basename "$RUNNER_LOG")"
    fi
    rm -f "$PID_FILE"
    exit "$rc"
fi

# --- Vordergrund: Sperre nicht-blockierend pruefen, dann detached neu starten. -----------------------
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "REJECTED"
    echo "Es laeuft bereits ein Deployment oder Rollback. Aktueller Stand:"
    cat "$STATUS_FILE" 2>/dev/null || echo "(keine Statusdatei vorhanden)"
    exit 3
fi

TS="$(date -u +%Y%m%d-%H%M%S)"
RUNNER_LOG="$LOG_DIR/deploy-runner-$TS-$SHA.log"
find "$LOG_DIR" -maxdepth 1 -name 'deploy-runner-*.log' -mtime +90 -delete 2>/dev/null || true

# Statusdatei SOFORT (noch im Vordergrund, unter der bereits gehaltenen Sperre) mit "running" fuer DIESEN
# Lauf vorbelegen: Die Datei ueberlebt seit Version 4.11 jeden Lauf, beim Ausloesen steht also noch das
# Ergebnis des VORHERIGEN Laufs darin (success/failed mit altem sha). Ohne Vorbelegung koennte das
# GitHub-Polling in den ersten Sekunden diesen alten Stand lesen und als Ergebnis dieses Laufs missdeuten
# (alter sha -> "falsches Release", alter Fehlstatus -> sofortiger Abbruch). pid bleibt null, bis der
# Hintergrundprozess seine eigene PID eintraegt.
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
deploy_status running null "Deployment angenommen, Hintergrundprozess startet"

# setsid startet dieses Skript im Modus "--worker" in einer NEUEN Sitzung (kein Bezug mehr zur
# SSH-Sitzung, kein SIGHUP bei deren Abbruch). "9<&9" vererbt den bereits gehaltenen Sperr-Deskriptor
# explizit an den neuen Prozess; volle Umleitung von stdin/stdout/stderr verhindert SIGPIPE, wenn der
# SSH-Kanal in der Zwischenzeit bereits weg ist.
SMARTEINZUG_RUNNER_LOG="$RUNNER_LOG" setsid bash "$0" --worker "$SHA" \
    9<&9 </dev/null >>"$RUNNER_LOG" 2>&1 &
disown

# Kurz warten, bis der Hintergrundprozess seine PID in die Statusdatei geschrieben hat; rein informativ fuer
# den Aufrufer ("running" fuer diesen sha steht seit der Vorbelegung oben bereits in der Datei).
for _ in 1 2 3 4 5 6 7 8 9 10; do
    grep -q '"pid":[0-9]' "$STATUS_FILE" 2>/dev/null && break
    sleep 0.3
done

echo "TRIGGERED sha=$SHA log=$(basename "$RUNNER_LOG")"
exit 0
