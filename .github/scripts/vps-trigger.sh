#!/usr/bin/env bash
#
# SmartEinzug: Deployment auf dem VPS ausloesen und das Ergebnis eindeutig einordnen (GitHub-Workflow,
# Job deploy-vps, Schritt "Deployment auf dem VPS ausloesen").
#
# Ausgelagert aus deploy.yml, damit genau dieser Code mit tools/github-ssh-retry-check.sh gegen ein
# Fake-"ssh" regressionsgeprueft wird und die Einordnung NICHT als verkuerzte Kopie im Workflow steht.
#
# Drei Zustaende, geschrieben nach GITHUB_OUTPUT als trigger_state:
#   triggered  Dieser Lauf hat das Deployment fuer GITHUB_SHA ausgeloest (deploy-runner.sh: TRIGGERED).
#              Der Warteschritt verlangt danach genau diesen sha im Endstatus.
#   rejected   Auf dem Server laeuft bereits ein Deployment oder Rollback (Exitcode 3 bzw. REJECTED).
#              Betrifft es denselben sha (die Statuszeile nennt ihn), gilt das wie triggered: Das eigene
#              Release wird gerade ausgeliefert. Ein fremder sha bedeutet nur warten.
#   unclear    Die Verbindung brach MITTEN in der Sitzung ab (z.B. Broken pipe). Ob deploy-runner.sh die
#              Sperre schon erworben hatte, ist von hier aus nicht entscheidbar; der Warteschritt prueft
#              den tatsaechlichen Stand auf dem Server und akzeptiert nur einen FRISCHEN Endstatus.
# Kam ueberhaupt keine Verbindung zustande (connect_failed des Wiederholungswrappers), bricht dieses
# Skript sofort mit Exitcode 1 ab: Dann ist gesichert, dass serverseitig nichts angestossen wurde, und
# zwoelf Minuten Warten auf einen Status, den niemand schreibt, waeren verlorene Zeit.
#
# Eingaben (Umgebung): VPS_SSH_USER, VPS_HOST, VPS_DEPLOY_PATH, GITHUB_SHA, SSH_OPTS.
# Exitcode: 0 bei triggered, rejected und unclear; 1, wenn der Server nicht erreichbar war.
set -uo pipefail

: "${VPS_SSH_USER:?VPS_SSH_USER fehlt}" "${VPS_HOST:?VPS_HOST fehlt}" "${VPS_DEPLOY_PATH:?VPS_DEPLOY_PATH fehlt}" "${GITHUB_SHA:?GITHUB_SHA fehlt}"
SSH_OPTS="${SSH_OPTS:-}"
HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

setze() { # $1 = Feld, $2 = Wert
    [[ -n "${GITHUB_OUTPUT:-}" ]] && echo "$1=$2" >> "$GITHUB_OUTPUT"
    echo "$1=$2"
}

MARKER="$(mktemp)"
trap 'rm -f "$MARKER"' EXIT

# VPS_RETRY_FINAL_CODES=3: "Es laeuft bereits ein Deployment" ist eine endgueltige Antwort des Servers,
# keine Stoerung; sie wird nicht wiederholt. VPS_RETRY_STATE_FILE liefert die Einordnung des Wrappers
# (connect_failed), damit hier keine zweite, abweichende Mustererkennung entsteht.
# shellcheck disable=SC2086
OUT="$(VPS_RETRY_FINAL_CODES=3 VPS_RETRY_STATE_FILE="$MARKER" bash "$HIER/vps-ssh-retry.sh" "Deployment ausloesen" -- \
    ssh $SSH_OPTS "$VPS_SSH_USER@$VPS_HOST" \
    "bash '$VPS_DEPLOY_PATH/releases/$GITHUB_SHA/deploy/vps/scripts/deploy-runner.sh' '$GITHUB_SHA'" 2>&1)"
RC=$?
echo "$OUT"
CONNECT_FAILED="$(sed -n 's/^connect_failed=//p' "$MARKER" | tail -n1)"

if [[ "$RC" -eq 0 ]] && grep -q '^TRIGGERED' <<< "$OUT"; then
    echo "Deployment ausgeloest, dieser Lauf ist fuer $GITHUB_SHA zustaendig."
    setze trigger_state triggered
    exit 0
fi

if [[ "$RC" -eq 3 ]] || grep -q '^REJECTED' <<< "$OUT"; then
    # deploy-runner.sh gibt bei REJECTED den aktuellen Stand als JSON aus; daraus laesst sich erkennen,
    # ob GERADE DIESES Release ausgeliefert wird (dann ist der Endstatus fuer uns verbindlich).
    LAEUFT_SHA="$(grep -o '"sha":"[0-9a-f]\{7,40\}"' <<< "$OUT" | head -n1 | cut -d'"' -f4)"
    if [[ -n "$LAEUFT_SHA" && "$LAEUFT_SHA" == "$GITHUB_SHA" ]]; then
        echo "::notice::Fuer genau dieses Release laeuft bereits ein Deployment (sha=$LAEUFT_SHA); dieser Lauf wartet auf dessen Abschluss."
        setze trigger_state triggered
        exit 0
    fi
    echo "::warning::Es laeuft bereits ein Deployment oder Rollback${LAEUFT_SHA:+ (sha=$LAEUFT_SHA)}; dieser Lauf hat"
    echo "::warning::nichts NEU ausgeloest, sondern wartet im naechsten Schritt auf dessen Abschluss."
    setze trigger_state rejected
    exit 0
fi

if [[ "$CONNECT_FAILED" == "true" ]]; then
    echo "::error::Der VPS war ueber SSH nicht erreichbar (mehrere Versuche). Es wurde nichts ausgeloest, auf dem"
    echo "::error::Server wurde nichts veraendert. Zu pruefen: Erreichbarkeit, Firewall (ufw), fail2ban (gesperrte"
    echo "::error::Runner-Adresse), SSH-Port und Hostkey; danach diesen Lauf erneut starten."
    echo "::error::Anleitung: docs/vps/06-betrieb.md, Abschnitt \"SSH-Fehler des Deployments\"."
    setze trigger_state unreachable
    exit 1
fi

echo "::warning::Ergebnis des Ausloesens unklar (Exitcode $RC). Die Verbindung kann MITTEN in der Sitzung"
echo "::warning::abgebrochen sein, moeglicherweise nachdem deploy-runner.sh bereits gestartet hatte. Der"
echo "::warning::naechste Schritt prueft den tatsaechlichen Stand auf dem Server und akzeptiert nur einen"
echo "::warning::Endstatus, der NACH dem Start dieses Laufs geschrieben wurde."
setze trigger_state unclear
exit 0
