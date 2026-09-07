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
#   TRIGGER_STATE          triggered | rejected | unclear (aus .github/scripts/vps-trigger.sh):
#                          triggered verlangt, dass der Endstatus genau GITHUB_SHA betrifft; rejected und
#                          unclear lassen einen fremden sha zu, verlangen aber einen FRISCHEN Status.
#   EXPECT_SHA             veralteter Schalter, wird nur noch beachtet, wenn TRIGGER_STATE fehlt
#   JOB_STARTED_AT         Sekunden seit Epoche beim Start des Wartens. Ein Endstatus (success/failed)
#                          gilt nur, wenn er DANACH geschrieben wurde. Ohne diese Pruefung koennte der
#                          success des VORHERIGEN Laufs einen Lauf gruen melden, in dem nichts
#                          ausgeliefert wurde (etwa wenn das Ausloesen die Verbindung verlor).
#   DEADLINE_SECONDS       Wartefrist, Standard 720 (12 Minuten; ein normales Deployment braucht deutlich
#                          weniger, siehe docs/vps/06-betrieb.md "GitHub-Polling")
#   POLL_SECONDS           Abstand der Abfragen, Standard 10
# Exit 0: success (und sha passt, falls EXPECT_SHA=true). Exit 1: failed, Zeitueberschreitung oder
# falscher sha. Bei "failed" werden die letzten 80 Protokollzeilen vom Server angezeigt.
set -uo pipefail

: "${VPS_SSH_USER:?VPS_SSH_USER fehlt}" "${VPS_HOST:?VPS_HOST fehlt}" "${VPS_DEPLOY_PATH:?VPS_DEPLOY_PATH fehlt}" "${GITHUB_SHA:?GITHUB_SHA fehlt}"
SSH_OPTS="${SSH_OPTS:-}"
TRIGGER_STATE="${TRIGGER_STATE:-}"
if [[ -z "$TRIGGER_STATE" ]]; then
    TRIGGER_STATE="$([[ "${EXPECT_SHA:-false}" == "true" ]] && echo triggered || echo rejected)"
fi
JOB_STARTED_AT="${JOB_STARTED_AT:-0}"
[[ "$JOB_STARTED_AT" =~ ^[0-9]+$ ]] || JOB_STARTED_AT=0
POLL="${POLL_SECONDS:-10}"
DEADLINE=$((SECONDS + ${DEADLINE_SECONDS:-720}))

command -v jq >/dev/null 2>&1 || {
    echo "::error::jq fehlt auf dem Runner; der Status kann nicht ausgewertet werden."
    exit 1
}

status_field() {
    printf '%s' "$1" | jq -r ".$2 // empty" 2>/dev/null || true
}

# Sekunden seit Epoche aus einem Zeitstempel der Statusdatei (2026-09-07T11:04:30Z), 0 wenn unlesbar.
status_ts() {
    local v="$1"
    [[ -z "$v" ]] && { echo 0; return; }
    date -u -d "$v" +%s 2>/dev/null || echo 0
}

# Ein Endstatus zaehlt nur, wenn er NACH dem Start dieses Wartens geschrieben wurde. Sonst koennte der
# Endstatus eines FRUEHEREN Laufs (der noch in der Datei steht, weil sie jeden Lauf ueberlebt) diesen
# Lauf beenden, obwohl nichts ausgeliefert wurde.
endstatus_frisch() {
    local aktualisiert
    aktualisiert="$(status_ts "$(status_field "$1" updated_at)")"
    (( JOB_STARTED_AT == 0 )) && return 0
    (( aktualisiert >= JOB_STARTED_AT - 60 ))
}

STATUS=""
LAST_SHOWN=""
VERALTET_GEMELDET=nein
FEHLABFRAGEN=0
SSH_FEHLER="$(mktemp)"
trap 'rm -f "$SSH_FEHLER"' EXIT
while (( SECONDS < DEADLINE )); do
    # shellcheck disable=SC2086
    STATUS="$(ssh $SSH_OPTS "$VPS_SSH_USER@$VPS_HOST" \
        "bash '$VPS_DEPLOY_PATH/deploy/scripts/deploy-status.sh'" 2>"$SSH_FEHLER")"
    RC_ABFRAGE=$?
    if [[ "$RC_ABFRAGE" -ne 0 || -z "$STATUS" ]]; then
        # Einzelne Fehlabfragen sind unkritisch (kurze Netzstoerung), duerfen aber nicht unsichtbar
        # bleiben: sonst endet eine dauerhafte Ursache (Zugang, Firewall, Port) nach zwoelf Minuten mit
        # einer Meldung, die auf ein haengendes Deployment hindeutet.
        FEHLABFRAGEN=$((FEHLABFRAGEN + 1))
        if (( FEHLABFRAGEN == 1 || FEHLABFRAGEN % 6 == 0 )); then
            echo "::warning::Statusabfrage $FEHLABFRAGEN fehlgeschlagen: $(tr '\n' ' ' < "$SSH_FEHLER" | cut -c1-200)"
        fi
    fi
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
            if ! endstatus_frisch "$STATUS"; then
                if [[ "$VERALTET_GEMELDET" != "ja" ]]; then
                    echo "::warning::Die Statusdatei nennt bereits success (sha=$SHA_SEEN), der Stand ist aber AELTER als"
                    echo "::warning::der Start dieses Laufs; er gehoert also zu einem frueheren Deployment. Es wird weiter"
                    echo "::warning::auf einen frischen Status gewartet."
                    VERALTET_GEMELDET=ja
                fi
                sleep "$POLL"
                continue
            fi
            echo "Deployment abgeschlossen (Status: success, sha=$SHA_SEEN)."
            break
            ;;
        failed)
            if ! endstatus_frisch "$STATUS"; then
                if [[ "$VERALTET_GEMELDET" != "ja" ]]; then
                    echo "::warning::Die Statusdatei nennt failed aus einem FRUEHEREN Lauf (sha=$SHA_SEEN); es wird weiter"
                    echo "::warning::auf einen frischen Status gewartet."
                    VERALTET_GEMELDET=ja
                fi
                sleep "$POLL"
                continue
            fi
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

# Nach der Schleife: Erfolg gilt nur, wenn die Phase success lautet UND der Stand aus DIESEM Lauf
# stammt. Ohne die zweite Bedingung koennte der Endstatus eines frueheren Deployments (die Statusdatei
# ueberlebt jeden Lauf) diesen Lauf gruen melden, obwohl nichts ausgeliefert wurde.
PHASE_ENDE="$(status_field "$STATUS" phase)"
if [[ "$PHASE_ENDE" != "success" ]] || ! endstatus_frisch "$STATUS"; then
    if [[ "$PHASE_ENDE" == "success" ]]; then
        echo "::error::Kein Deployment dieses Laufs nachweisbar. Die Statusdatei nennt success, der Stand"
        echo "::error::gehoert aber zu einem frueheren Lauf (sha=$(status_field "$STATUS" sha))."
    else
        echo "::error::Zeitueberschreitung beim Warten auf den Abschluss des Deployments. Letzter Status: ${STATUS:-<keiner>}"
    fi
    if (( FEHLABFRAGEN > 0 )); then
        echo "::error::$FEHLABFRAGEN Statusabfrage(n) sind fehlgeschlagen. Wenn ALLE fehlschlugen, liegt die"
        echo "::error::Ursache im Zugang (Netz, Firewall, fail2ban, Port), nicht im Deployment."
    fi
    echo "Der serverseitige Deploy-Runner laeuft davon unabhaengig weiter; Stand auf dem Server:"
    echo "  bash $VPS_DEPLOY_PATH/deploy/scripts/deploy-status.sh --tail 80"
    exit 1
fi
if [[ "$TRIGGER_STATE" == "triggered" ]]; then
    SHA_SEEN="$(status_field "$STATUS" sha)"
    if [[ "$SHA_SEEN" != "$GITHUB_SHA" ]]; then
        echo "::error::Der erfolgreich abgeschlossene Deploy betraf sha=$SHA_SEEN, nicht $GITHUB_SHA."
        echo "Dieser Lauf hatte selbst ausgeloest, das Ergebnis gehoert aber zu einem anderen Release."
        echo "Bitte diesen Workflow-Lauf erneut ausfuehren (workflow_dispatch oder erneuter Push)."
        exit 1
    fi
    echo "Erfolgreich abgeschlossen fuer dieses Release (sha=$SHA_SEEN)."
else
    echo "::notice::Dieser Lauf hat selbst kein Deployment ausgeloest (Zustand: $TRIGGER_STATE), sondern auf"
    echo "::notice::ein bereits laufendes gewartet. Abgeschlossen wurde sha=$(status_field "$STATUS" sha)."
    echo "::notice::Bitte pruefen, ob $GITHUB_SHA tatsaechlich aktiviert wurde (Adminbereich, System, Versionen),"
    echo "::notice::und den Lauf andernfalls erneut ausfuehren."
fi
exit 0
