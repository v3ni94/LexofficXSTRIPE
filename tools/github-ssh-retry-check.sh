#!/usr/bin/env bash
#
# Regressionstest der Wiederholungslogik der SSH-Schritte des VPS-Deployjobs
# (.github/scripts/vps-ssh-retry.sh) und der Auswertung im Workflow, OHNE Netz und OHNE Server:
# "ssh" und "rsync" werden durch steuerbare Fakes im PATH ersetzt.
#
# Hintergrund (echter Vorfall, Lauf #51, Version 4.14): Der erste SSH-Aufruf des Jobs scheiterte mit
# "ssh: connect to host *** port ***: Connection timed out" (Exitcode 255) und beendete den gesamten
# Lauf, obwohl der Server in Ordnung war und dort nichts geschehen ist. Seitdem laufen das Anlegen des
# Zielverzeichnisses, die drei rsync-Uebertragungen und das Ausloesen ueber die Wiederholung.
#
# Geprueft wird:
#   1. Erfolg beim ersten Versuch: genau ein Aufruf, Exit 0, keine Wiederholungsmeldung.
#   2. Erfolg beim dritten Versuch: drei Aufrufe, Exit 0, attempts=3 in GITHUB_OUTPUT.
#   3. Dauerhafter Verbindungsfehler: genau VPS_RETRIES Aufrufe (kein Endlosversuch), Exitcode des
#      Befehls (255), connect_failed=true und ein Hinweis, dass auf dem Server nichts veraendert wurde.
#   4. Fachlicher Fehler (kein Verbindungsfehler): wird ebenfalls begrenzt wiederholt, aber NICHT als
#      connect_failed gemeldet (die Ursache liegt nicht im Netz).
#   5. Endgueltiger Exitcode (VPS_RETRY_FINAL_CODES, z.B. 3 = "Deployment laeuft bereits"): genau ein
#      Aufruf, Exitcode durchgereicht.
#   6. rsync, das erst beim zweiten Aufruf gelingt: Exit 0, zwei Aufrufe.
#   7. Das ECHTE Ausloeseskript .github/scripts/vps-trigger.sh (keine nachgebildete Kopie der Logik):
#      TRIGGERED auch nach fehlgeschlagenem ersten Versuch, REJECTED mit fremdem und mit EIGENEM sha,
#      unerreichbarer Server (Exit 1, trigger_state=unreachable) und Abbruch mitten in der Sitzung
#      (trigger_state=unclear, Exit 0).
#   9. Betriebsstandard ohne Testverkuerzung: vier Versuche, Pausen 5, 10, 20 s (Fake-sleep zeichnet auf);
#      VPS_RETRIES wird auf 8 gedeckelt.
#  10. Gemischte Fehlerfolge (erst Verbindungsfehler, dann fachlicher Fehler): connect_failed=false, denn der
#      Server wurde erreicht; genau diese Folge hatte frueher eine falsche Sicherheitsaussage erzeugt.
#  11. Argumentweitergabe: Leerzeichen, Anfuehrungszeichen und $ im Fernbefehl kommen unveraendert an.
#  12. Statisch: alle uebertragenden Schritte in deploy.yml verwenden die Wiederholung.
#
# Aufruf: bash tools/github-ssh-retry-check.sh     Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SKRIPT="$ROOT/.github/scripts/vps-ssh-retry.sh"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT INT TERM
install -d "$T/bin"

# Fake-"ssh"/"rsync": scheitert mit der Meldung aus $FAKE_MELDUNG und Exitcode $FAKE_RC, bis
# $FAKE_ERFOLG_AB Aufrufe erreicht sind; zaehlt jeden Aufruf in einer Datei mit.
erzeuge_fake() { # $1 = Name (ssh|rsync)
    cat > "$T/bin/$1" <<'FAKE'
#!/usr/bin/env bash
ZAEHLER="${FAKE_COUNT_FILE:?}"
N="$(cat "$ZAEHLER" 2>/dev/null || echo 0)"; N=$((N + 1)); echo "$N" > "$ZAEHLER"
if [[ -n "${FAKE_ERFOLG_AB:-}" && "$N" -ge "${FAKE_ERFOLG_AB}" ]]; then
    echo "${FAKE_ERFOLG_TEXT:-ok}"
    exit 0
fi
echo "${FAKE_MELDUNG:-ssh: connect to host *** port ***: Connection timed out}" >&2
exit "${FAKE_RC:-255}"
FAKE
    chmod +x "$T/bin/$1"
}
erzeuge_fake ssh
erzeuge_fake rsync

# Fuehrt das Skript mit den Fakes im PATH aus; setzt AUSGABE, RC, AUFRUFE und OUTPUTS.
lauf() { # $@ = Befehl fuer das Skript
    : > "$T/count"
    : > "$T/gho"
    AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" \
        VPS_RETRY_DELAY_SECONDS=0 bash "$SKRIPT" "Test" -- "$@" 2>&1)"
    RC=$?
    AUFRUFE="$(cat "$T/count" 2>/dev/null || echo 0)"
    OUTPUTS="$(cat "$T/gho" 2>/dev/null)"
}
feld() { printf '%s' "$OUTPUTS" | sed -n "s/^$1=//p" | tail -n1; }

echo "1) Erfolg beim ersten Versuch"
FAKE_ERFOLG_AB=1 FAKE_ERFOLG_TEXT="TRIGGERED sha=abc log=x.log" lauf ssh host "cmd"
[[ "$RC" -eq 0 ]] && ok "Exit 0" || bad "Exit $RC"
[[ "$AUFRUFE" == "1" ]] && ok "genau ein Aufruf" || bad "$AUFRUFE Aufrufe, erwartet 1"
! grep -q "Versuch 2" <<< "$AUSGABE" && ok "keine Wiederholungsmeldung" || bad "unerwartete Wiederholung"
[[ "$(feld attempts)" == "1" && "$(feld connect_failed)" == "false" ]] && ok "attempts=1, connect_failed=false" || bad "OUTPUTS: $OUTPUTS"

echo "2) Erfolg beim dritten Versuch"
FAKE_ERFOLG_AB=3 FAKE_ERFOLG_TEXT="TRIGGERED sha=abc" lauf ssh host "cmd"
[[ "$RC" -eq 0 ]] && ok "Exit 0 nach Wiederholung" || bad "Exit $RC"
[[ "$AUFRUFE" == "3" ]] && ok "drei Aufrufe" || bad "$AUFRUFE Aufrufe, erwartet 3"
[[ "$(feld attempts)" == "3" ]] && ok "attempts=3" || bad "attempts=$(feld attempts)"
grep -q "im Versuch 3 erfolgreich" <<< "$AUSGABE" && ok "Erfolg im dritten Versuch protokolliert" || bad "keine Erfolgsmeldung"

echo "3) Dauerhafter Verbindungsfehler: begrenzte Anzahl, klare Ursache"
: > "$T/count"; : > "$T/gho"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" \
    VPS_RETRIES=3 VPS_RETRY_DELAY_SECONDS=0 bash "$SKRIPT" "Zielverzeichnis anlegen" -- ssh host "cmd" 2>&1)"
RC=$?; AUFRUFE="$(cat "$T/count")"; OUTPUTS="$(cat "$T/gho")"
[[ "$RC" -eq 255 ]] && ok "Exitcode 255 des Befehls durchgereicht" || bad "Exit $RC, erwartet 255"
[[ "$AUFRUFE" == "3" ]] && ok "genau drei Versuche (VPS_RETRIES), kein Endlosversuch" || bad "$AUFRUFE Aufrufe, erwartet 3"
[[ "$(feld connect_failed)" == "true" ]] && ok "connect_failed=true" || bad "connect_failed=$(feld connect_failed)"
grep -q "Der Server wurde nicht erreicht" <<< "$AUSGABE" && ok "Hinweis, dass serverseitig nichts veraendert wurde" || bad "Hinweis fehlt"
grep -q "fail2ban" <<< "$AUSGABE" && ok "Pruefhinweise (Firewall, fail2ban) genannt" || bad "keine Pruefhinweise"

echo "4) Fachlicher Fehler ist kein Verbindungsfehler"
: > "$T/count"; : > "$T/gho"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" \
    FAKE_MELDUNG="rsync: [sender] change_dir failed: No such file or directory (2)" FAKE_RC=23 \
    VPS_RETRIES=2 VPS_RETRY_DELAY_SECONDS=0 bash "$SKRIPT" "Anwendung uebertragen" -- rsync a b 2>&1)"
RC=$?; AUFRUFE="$(cat "$T/count")"; OUTPUTS="$(cat "$T/gho")"
[[ "$RC" -eq 23 ]] && ok "Exitcode 23 durchgereicht" || bad "Exit $RC"
[[ "$(feld connect_failed)" == "false" ]] && ok "connect_failed=false (Ursache nicht im Netz)" || bad "connect_failed=$(feld connect_failed)"
grep -q "kein reiner Verbindungsfehler" <<< "$AUSGABE" && ok "als fachlicher Fehler benannt" || bad "Einordnung fehlt"

echo "5) Endgueltiger Exitcode wird nicht wiederholt"
: > "$T/count"; : > "$T/gho"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" \
    FAKE_MELDUNG="REJECTED" FAKE_RC=3 VPS_RETRY_FINAL_CODES=3 VPS_RETRY_DELAY_SECONDS=0 \
    bash "$SKRIPT" "Deployment ausloesen" -- ssh host "cmd" 2>&1)"
RC=$?; AUFRUFE="$(cat "$T/count")"
[[ "$RC" -eq 3 ]] && ok "Exitcode 3 durchgereicht" || bad "Exit $RC"
[[ "$AUFRUFE" == "1" ]] && ok "genau ein Aufruf (keine Wiederholung einer endgueltigen Antwort)" || bad "$AUFRUFE Aufrufe, erwartet 1"
grep -q "endgueltige Antwort" <<< "$AUSGABE" && ok "als endgueltig protokolliert" || bad "Meldung fehlt"

echo "6) rsync gelingt beim zweiten Aufruf"
FAKE_ERFOLG_AB=2 FAKE_ERFOLG_TEXT="sent 1234 bytes" lauf rsync -az a b
[[ "$RC" -eq 0 && "$AUFRUFE" == "2" ]] && ok "Exit 0 nach zwei Aufrufen" || bad "Exit $RC, $AUFRUFE Aufrufe"

echo "7) Das echte Ausloeseskript .github/scripts/vps-trigger.sh"
TRIGGER="$ROOT/.github/scripts/vps-trigger.sh"
# Fake-ssh liefert die vorgegebene Antwort; FAKE_ERFOLG_AB steuert, ab welchem Aufruf sie kommt.
ausloesen() { # $1 = Antwort bei Erfolg, $2 = Erfolg ab Aufruf, $3 = Meldung/Exitcode bei Fehlschlag, $4 = RC
    : > "$T/count"; : > "$T/gho"
    AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" \
        FAKE_ERFOLG_AB="$2" FAKE_ERFOLG_TEXT="$1" FAKE_MELDUNG="$3" FAKE_RC="$4" \
        VPS_RETRIES=2 VPS_RETRY_DELAY_SECONDS=0 \
        VPS_SSH_USER=deploy VPS_HOST=vps.test.invalid VPS_DEPLOY_PATH=/opt/smarteinzug \
        GITHUB_SHA=abc123def456 SSH_OPTS="-o Fake=yes" \
        bash "$TRIGGER" 2>&1)"
    RC=$?
    OUTPUTS="$(cat "$T/gho" 2>/dev/null)"
}

ausloesen "TRIGGERED sha=abc123def456 log=x.log" 1 "" 255
[[ "$RC" -eq 0 && "$(feld trigger_state)" == "triggered" ]] && ok "TRIGGERED im ersten Versuch -> trigger_state=triggered" || bad "RC=$RC, state=$(feld trigger_state)"

ausloesen "TRIGGERED sha=abc123def456 log=x.log" 2 "ssh: connect to host *** port ***: Connection timed out" 255
[[ "$RC" -eq 0 && "$(feld trigger_state)" == "triggered" ]] && ok "TRIGGERED erst im zweiten Versuch wird erkannt (Meldungen davor stoeren nicht)" || bad "RC=$RC, state=$(feld trigger_state): $AUSGABE"

ausloesen 'REJECTED
{"phase":"running","sha":"fremdsha0000","pid":1}' 1 "" 3
[[ "$RC" -eq 0 && "$(feld trigger_state)" == "rejected" ]] && ok "REJECTED mit FREMDEM sha -> rejected (nur warten)" || bad "RC=$RC, state=$(feld trigger_state)"

ausloesen 'REJECTED
{"phase":"running","sha":"abc123def456","pid":1}' 1 "" 3
[[ "$RC" -eq 0 && "$(feld trigger_state)" == "triggered" ]] && ok "REJECTED mit EIGENEM sha -> triggered (unser Release wird gerade ausgeliefert)" || bad "RC=$RC, state=$(feld trigger_state)"
grep -q "Fuer genau dieses Release laeuft bereits ein Deployment" <<< "$AUSGABE" && ok "eigener sha im Protokoll benannt" || bad "Meldung fehlt"

ausloesen "" 99 "ssh: connect to host *** port ***: Connection timed out" 255
[[ "$RC" -eq 1 && "$(feld trigger_state)" == "unreachable" ]] && ok "unerreichbarer Server -> Exit 1, trigger_state=unreachable (kein zwoelfminuetiges Warten)" || bad "RC=$RC, state=$(feld trigger_state)"
grep -q "wurde nichts ausgeloest" <<< "$AUSGABE" && ok "klare Aussage: es wurde nichts ausgeloest" || bad "Aussage fehlt"

ausloesen "" 99 "client_loop: send disconnect: Broken pipe" 255
[[ "$RC" -eq 0 && "$(feld trigger_state)" == "unclear" ]] && ok "Abbruch MITTEN in der Sitzung -> unclear, Exit 0 (Stand wird serverseitig geprueft)" || bad "RC=$RC, state=$(feld trigger_state)"
grep -q "nur einen" <<< "$AUSGABE" && ok "Hinweis auf die Frischepruefung des Wartens" || bad "Hinweis fehlt"

echo "9) Betriebsstandard: Pausen 5, 10, 20 s (Fake-sleep zeichnet auf, wartet nicht)"
cat > "$T/bin/sleep" <<'FAKE'
#!/usr/bin/env bash
echo "$1" >> "${FAKE_SLEEP_LOG:?}"
FAKE
chmod +x "$T/bin/sleep"
: > "$T/count"; : > "$T/gho"; : > "$T/sleeps"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" FAKE_SLEEP_LOG="$T/sleeps" \
    env -u VPS_RETRY_DELAY_SECONDS -u VPS_RETRIES bash "$SKRIPT" "Standard" -- ssh host "cmd" 2>&1)"
RC=$?
[[ "$(cat "$T/count")" == "4" ]] && ok "Standard sind vier Versuche" || bad "$(cat "$T/count") Versuche, erwartet 4"
[[ "$(tr '\n' ',' < "$T/sleeps")" == "5,10,20," ]] && ok "Pausen 5, 10, 20 s (Verdopplung ab 5 s)" || bad "Pausen: $(tr '\n' ',' < "$T/sleeps")"
: > "$T/count"; : > "$T/sleeps"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" FAKE_SLEEP_LOG="$T/sleeps" VPS_RETRIES=50 VPS_RETRY_DELAY_SECONDS=1 bash "$SKRIPT" "Deckel" -- ssh host "cmd" 2>&1)"
[[ "$(cat "$T/count")" == "8" ]] && ok "VPS_RETRIES=50 wird auf 8 Versuche gedeckelt (kein Zahlenueberlauf der Verdopplung)" || bad "$(cat "$T/count") Versuche, erwartet 8"
rm -f "$T/bin/sleep"

echo "10) Gemischte Fehlerfolge: erst Verbindungsfehler, dann fachlicher Fehler -> KEIN connect_failed"
cat > "$T/bin/ssh" <<'FAKE'
#!/usr/bin/env bash
ZAEHLER="${FAKE_COUNT_FILE:?}"
N="$(cat "$ZAEHLER" 2>/dev/null || echo 0)"; N=$((N + 1)); echo "$N" > "$ZAEHLER"
if (( N == 1 )); then echo "ssh: connect to host *** port ***: Connection timed out" >&2; exit 255; fi
echo "bash: /opt/smarteinzug/releases/x/deploy/vps/scripts/deploy-runner.sh: No such file or directory" >&2; exit 127
FAKE
chmod +x "$T/bin/ssh"
: > "$T/count"; : > "$T/gho"
AUSGABE="$(PATH="$T/bin:$PATH" FAKE_COUNT_FILE="$T/count" GITHUB_OUTPUT="$T/gho" VPS_RETRIES=3 VPS_RETRY_DELAY_SECONDS=0 bash "$SKRIPT" "Gemischt" -- ssh host "cmd" 2>&1)"
RC=$?; OUTPUTS="$(cat "$T/gho")"
[[ "$RC" -eq 127 ]] && ok "letzter Exitcode (127) wird durchgereicht" || bad "Exit $RC, erwartet 127"
[[ "$(feld connect_failed)" == "false" ]] && ok "connect_failed=false: der Server WURDE erreicht, die Ursache liegt nicht im Netz" || bad "connect_failed=$(feld connect_failed), erwartet false"
grep -q "kein reiner Verbindungsfehler" <<< "$AUSGABE" && ok "als fachlicher Fehler eingeordnet" || bad "Einordnung fehlt: $AUSGABE"
erzeuge_fake ssh   # Standard-Fake wiederherstellen

echo "11) Argumentweitergabe: Leerzeichen, Anfuehrungszeichen und Sonderzeichen kommen unveraendert an"
cat > "$T/bin/ssh" <<'FAKE'
#!/usr/bin/env bash
: "${FAKE_ARGS_LOG:?}"
for a in "$@"; do printf '%s\n' "$a"; done > "$FAKE_ARGS_LOG"
exit 0
FAKE
chmod +x "$T/bin/ssh"
: > "$T/args"
PATH="$T/bin:$PATH" FAKE_ARGS_LOG="$T/args" VPS_RETRY_DELAY_SECONDS=0 bash "$SKRIPT" "Argumente" -- \
    ssh -o "ConnectTimeout=15" 'user@host' "mkdir -p '/opt/x/releases/abc def/status' && echo \$HOME \"zitiert\"" >/dev/null 2>&1
mapfile -t ARGS < "$T/args"
[[ "${#ARGS[@]}" -eq 4 ]] && ok "vier Argumente (Optionen, Ziel, Fernbefehl) unveraendert gezaehlt" || bad "${#ARGS[@]} Argumente: $(cat "$T/args")"
[[ "${ARGS[1]}" == "ConnectTimeout=15" && "${ARGS[2]}" == "user@host" ]] && ok "Option und Ziel unveraendert" || bad "Argumente: ${ARGS[*]}"
[[ "${ARGS[3]}" == "mkdir -p '/opt/x/releases/abc def/status' && echo \$HOME \"zitiert\"" ]] && ok "Fernbefehl mit Leerzeichen, Quotes und \$ unveraendert" || bad "Fernbefehl: ${ARGS[3]}"
erzeuge_fake ssh

echo "12) Statisch: alle uebertragenden Schritte verwenden die Wiederholung"
YML="$ROOT/.github/workflows/deploy.yml"
ANZ="$(grep -c 'vps-ssh-retry.sh' "$YML")"
[[ "$ANZ" -ge 4 ]] && ok "Wiederholung in $ANZ Schritten verdrahtet (mkdir, drei rsync)" || bad "nur $ANZ Verwendungen, erwartet mindestens 4"
grep -q 'run: bash .github/scripts/vps-trigger.sh' "$YML" && ok "Ausloesen laeuft ueber das ausgelagerte, getestete Skript" || bad "Ausloeseschritt ruft vps-trigger.sh nicht auf"
grep -q 'TRIGGER_STATE: ' "$YML" && grep -q 'JOB_STARTED_AT=' "$YML" && ok "Warteschritt erhaelt trigger_state und den Startzeitpunkt" || bad "TRIGGER_STATE oder JOB_STARTED_AT fehlt im Workflow"
OHNE="$(grep -nE '^\s+(ssh|rsync) \$SSH_OPTS|^\s+rsync -az' "$YML" | grep -v 'retry' || true)"
UNGESCHUETZT=0
while IFS= read -r zeile; do
    [[ -z "$zeile" ]] && continue
    NR="${zeile%%:*}"
    # Die Zeile gilt als geschuetzt, wenn in den fuenf Zeilen davor die Wiederholung aufgerufen wird.
    sed -n "$((NR > 5 ? NR - 5 : 1)),${NR}p" "$YML" | grep -q 'vps-ssh-retry.sh' || UNGESCHUETZT=$((UNGESCHUETZT + 1))
done <<< "$OHNE"
[[ "$UNGESCHUETZT" -eq 0 ]] && ok "kein ssh-/rsync-Aufruf ohne Wiederholung" || bad "$UNGESCHUETZT Aufruf(e) ohne Wiederholung"
grep -q 'VPS_RETRY_FINAL_CODES=3' "$ROOT/.github/scripts/vps-trigger.sh" && ok "Ausloesen behandelt Exitcode 3 als endgueltige Antwort" || bad "VPS_RETRY_FINAL_CODES fehlt im Ausloeseskript"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
