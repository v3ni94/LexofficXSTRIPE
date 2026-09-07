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
    # Protokollname aus der Statusdatei: bevorzugt per jq (formatunabhaengig), sonst per sed mit Toleranz fuer
    # Leerraum nach dem Doppelpunkt (die Datei kann kompakt vom Runner oder per jq von deploy.sh stammen).
    if command -v jq >/dev/null 2>&1; then
        LOG_NAME="$(jq -r '.log_file // empty' "$STATUS_FILE" 2>/dev/null || true)"
    else
        LOG_NAME="$(sed -n 's/.*"log_file":[[:space:]]*"\([^"]*\)".*/\1/p' "$STATUS_FILE" | head -n1)"
    fi
    if [[ -n "$LOG_NAME" && -f "$LOG_DIR/$LOG_NAME" ]]; then
        echo "--- letzte $N Zeilen von $LOG_NAME ---"
        tail -n "$N" "$LOG_DIR/$LOG_NAME"
    else
        echo "--- Protokolldatei nicht gefunden ---"
    fi
fi
