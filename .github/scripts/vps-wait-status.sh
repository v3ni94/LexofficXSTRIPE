#!/usr/bin/env bash
#
# SmartEinzug: Auf den Abschluss des serverseitig entkoppelten VPS-Deployments warten (GitHub-Workflow
# "Deployment IONOS-Webhosting und VPS", Schritt "Auf Abschluss des Deployments warten").
#
# Ausgelagert aus .github/workflows/deploy.yml, damit genau dieser Code mit tools/github-poll-check.sh gegen
# ein Fake-"ssh" regressionsgeprueft werden kann. Entscheidungslogik (success/failed/Frist/sha-Abgleich)
# unveraendert; ergaenzt um die Ausgabe jedes Phasen-/Schrittwechsels, das Feld "step", einen
# Recovery-Hinweis bei Zeitueberschreitung und die Umgebungsvariablen DEADLINE_SECONDS/POLL_SECONDS.
#
# Ablauf: Der vorherige Schritt hat deploy-runner.sh auf dem Server ausgeloest; der laeuft per setsid
# unabhaengig von jeder SSH-Sitzung weiter. Dieses Skript fragt deploy-status.sh ueber kurze, WIEDERHOLTE,
# voneinander unabhaengige SSH-Verbindungen ab. Eine einzelne fehlgeschlagene Abfrage (Verbindungsabbruch,
# Statusdatei gerade nicht lesbar) beendet das Warten NICHT; erst "success"/"failed" oder die Frist.
#
# Eingaben (Umgebung):
#   VPS_SSH_USER, VPS_HOST, VPS_DEPLOY_PATH, GITHUB_SHA   (Pflicht)
#   SSH_OPTS               zusaetzliche ssh-Optionen (Keepalive usw.), wortgetrennt
#   EXPECT_SHA             "true", wenn dieser Lauf das Deployment selbst ausgeloest hat (dann muss der
#                          erfolgreich abgeschlossene Deploy genau GITHUB_SHA betreffen)
#   DEADLINE_SECONDS       Wartefrist, Standard 720 (12 Minuten; ein normales Deployment braucht deutlich
#                          weniger, siehe docs/vps/06-betrieb.md "GitHub-Polling")
#   POLL_SECONDS           Abstand der Abfragen, Standard 10
# Exit 0: success (und sha passt, falls EXPECT_SHA=true). Exit 1: failed, Zeitueberschreitung oder
# falscher sha. Bei "failed" werden die letzten 80 Protokollzeilen vom Server angezeigt.
set -uo pipefail

: "${VPS_SSH_USER:?VPS_SSH_USER fehlt}" "${VPS_HOST:?VPS_HOST fehlt}" "${VPS_DEPLOY_PATH:?VPS_DEPLOY_PATH fehlt}" "${GITHUB_SHA:?GITHUB_SHA fehlt}"
SSH_OPTS="${SSH_OPTS:-}"
EXPECT_SHA="${EXPECT_SHA:-false}"
POLL="${POLL_SECONDS:-10}"
DEADLINE=$((SECONDS + ${DEADLINE_SECONDS:-720}))

status_field() {
    printf '%s' "$1" | jq -r ".$2 // empty" 2>/dev/null || true
}

STATUS=""
LAST_SHOWN=""
while (( SECONDS < DEADLINE )); do
    # shellcheck disable=SC2086
    STATUS="$(ssh $SSH_OPTS "$VPS_SSH_USER@$VPS_HOST" \
        "bash '$VPS_DEPLOY_PATH/deploy/scripts/deploy-status.sh'" 2>/dev/null)"
    PHASE="$(status_field "$STATUS" phase)"
    SHA_SEEN="$(status_field "$STATUS" sha)"
    STEP="$(status_field "$STATUS" step)"
    # Jede Aenderung von Phase oder Schritt einmal protokollieren (kein Rauschen bei unveraendertem Stand).
    SHOWN="$PHASE/$STEP/$SHA_SEEN"
    if [[ -n "$PHASE" && "$SHOWN" != "$LAST_SHOWN" ]]; then
        echo "Status: phase=$PHASE${STEP:+ step=$STEP}${SHA_SEEN:+ sha=$SHA_SEEN}"
        LAST_SHOWN="$SHOWN"
    fi
    case "$PHASE" in
        success)
            echo "Deployment abgeschlossen (Status: success, sha=$SHA_SEEN)."
            break
            ;;
        failed)
            echo "::error::Deployment fehlgeschlagen. Status: $STATUS"
            # shellcheck disable=SC2086
            ssh $SSH_OPTS "$VPS_SSH_USER@$VPS_HOST" \
                "bash '$VPS_DEPLOY_PATH/deploy/scripts/deploy-status.sh' --tail 80" 2>/dev/null || true
            exit 1
            ;;
        running) : ;;
        *) : ;;  # Statusdatei kurzzeitig nicht lesbar/noch nicht vorhanden: einfach weiter warten
    esac
    sleep "$POLL"
done

if [[ "$(status_field "$STATUS" phase)" != "success" ]]; then
    echo "::error::Zeitueberschreitung beim Warten auf den Abschluss des Deployments. Letzter Status: ${STATUS:-<keiner>}"
    echo "Der serverseitige Deploy-Runner laeuft davon unabhaengig weiter; Stand auf dem Server:"
    echo "  bash $VPS_DEPLOY_PATH/deploy/scripts/deploy-status.sh --tail 80"
    exit 1
fi
if [[ "$EXPECT_SHA" == "true" ]]; then
    SHA_SEEN="$(status_field "$STATUS" sha)"
    if [[ "$SHA_SEEN" != "$GITHUB_SHA" ]]; then
        echo "::error::Der erfolgreich abgeschlossene Deploy betraf sha=$SHA_SEEN, nicht $GITHUB_SHA."
        echo "Dieser Lauf hatte selbst ausgeloest, das Ergebnis gehoert aber zu einem anderen Release."
        echo "Bitte diesen Workflow-Lauf erneut ausfuehren (workflow_dispatch oder erneuter Push)."
        exit 1
    fi
else
    echo "::notice::Dieser Lauf hat selbst kein Deployment ausgeloest (Sperre war belegt), sondern nur"
    echo "::notice::auf ein bereits laufendes gewartet. Bitte pruefen, ob sha=$GITHUB_SHA tatsaechlich"
    echo "::notice::aktiviert wurde (Reiter Server/Versionen im Adminbereich, oder erneut ausloesen)."
fi
exit 0
