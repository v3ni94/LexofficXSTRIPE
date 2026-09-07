#!/usr/bin/env bash
#
# SmartEinzug: Status des serverseitigen Deploy-Runners abfragen (siehe deploy-runner.sh).
#
#   bash /opt/smarteinzug/deploy/scripts/deploy-status.sh            Statusdatei als JSON ausgeben
#   bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 50  zusaetzlich die letzten Zeilen
#                                                                     des zugehoerigen Protokolls
#
# Absichtlich sehr einfach und schnell (keine Locks, keine schreibenden Zugriffe): Der GitHub-Workflow
# ruft dieses Skript wiederholt ueber kurze, unabhaengige SSH-Verbindungen auf, waehrend im Hintergrund
# ein Deployment laeuft. Jeder einzelne Aufruf darf scheitern/abbrechen, ohne das laufende Deployment zu
# beeinflussen (dieses Skript aendert nichts am Zustand, es liest nur).
set -uo pipefail

BASE=/opt/smarteinzug
STATUS_FILE="$BASE/deploy/.deploy-status.json"
LOG_DIR="$BASE/logs"

if [[ ! -f "$STATUS_FILE" ]]; then
    echo '{"phase":"unknown","message":"Noch kein Deployment ueber deploy-runner.sh auf diesem Server ausgefuehrt."}'
    exit 0
fi
cat "$STATUS_FILE"
echo

if [[ "${1:-}" == "--tail" ]]; then
    N="${2:-50}"
    LOG_NAME="$(sed -n 's/.*"log_file":"\([^"]*\)".*/\1/p' "$STATUS_FILE" | head -n1)"
    if [[ -n "$LOG_NAME" && -f "$LOG_DIR/$LOG_NAME" ]]; then
        echo "--- letzte $N Zeilen von $LOG_NAME ---"
        tail -n "$N" "$LOG_DIR/$LOG_NAME"
    else
        echo "--- Protokolldatei nicht gefunden ---"
    fi
fi
