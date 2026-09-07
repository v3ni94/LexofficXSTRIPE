#!/usr/bin/env bash
#
# Regressionstest fuer .github/scripts/vps-wait-status.sh (die Polling-Logik des GitHub-Workflows) gegen
# ein Fake-"ssh", das eine vorgegebene Folge von deploy-status.sh-Antworten liefert. Kein Netz, kein Server.
#
# Geprueft wird:
#   1. running (mit Schritten) -> success: Exit 0, Phase-/Schrittwechsel werden protokolliert.
#   2. running -> failed: Exit 1, die letzten Protokollzeilen werden vom Server nachgeladen (--tail 80).
#   3. unknown (Statusdatei noch nicht da) und ein SSH-Verbindungsabbruch beenden das Warten NICHT.
#   4. success fuer einen ANDEREN sha, obwohl dieser Lauf selbst ausgeloest hat (EXPECT_SHA=true): Exit 1.
#   5. success fuer einen anderen sha ohne eigenes Ausloesen (EXPECT_SHA=false): Exit 0 mit Hinweis.
#   6. Nur "running" bis zur Frist: Exit 1 mit Zeitueberschreitung und Hinweis auf deploy-status.sh --tail.
#
# Aufruf: bash tools/github-poll-check.sh        Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WAIT_SH="$ROOT/.github/scripts/vps-wait-status.sh"

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

# Fake-ssh: ignoriert alle Optionen; das entfernte Kommando steht im letzten Argument. Ein --tail-Aufruf
# liefert eine erkennbare Protokollzeile; jeder andere Aufruf liefert die naechste Datei der Folge
# ($SEQ_DIR/status.N; nach dem Ende wird die letzte wiederholt). Inhalt "SSHFAIL" = Verbindungsabbruch
# (Exit 255, keine Ausgabe), "EMPTY" = leere Antwort.
make_fake_ssh() {
    local dir="$1"
    install -d -m 750 "$dir/bin"
    cat > "$dir/bin/ssh" <<'FAKE'
#!/usr/bin/env bash
set -uo pipefail
CMD="${*: -1}"
SEQ="${SEQ_DIR:?}"
if [[ "$CMD" == *"--tail"* ]]; then
    echo "--- letzte 80 Zeilen von deploy-runner-fake.log ---"
    echo "(fake tail) ::error:: Candidate-Pruefung fehlgeschlagen"
    exit 0
fi
n=$(( $(cat "$SEQ/ptr" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$SEQ/ptr"
total=$(ls "$SEQ"/status.* | wc -l)
(( n > total )) && n=$total
content="$(cat "$SEQ/status.$n")"
case "$content" in
    SSHFAIL) exit 255 ;;
    EMPTY) exit 0 ;;
    *) printf '%s\n' "$content" ;;
esac
FAKE
    chmod +x "$dir/bin/ssh"
}

run_wait() {  # $1 = seq dir, $2 = EXPECT_SHA, $3 = deadline seconds; stdout+stderr -> $1/out.log, gibt Exitcode zurueck
    local dir="$1"
    rm -f "$dir/ptr"
    SEQ_DIR="$dir" PATH="$dir/bin:$PATH" \
    VPS_SSH_USER=deploy VPS_HOST=vps.test.invalid VPS_DEPLOY_PATH=/opt/smarteinzug GITHUB_SHA=abc123 \
    SSH_OPTS="-o Fake=yes" EXPECT_SHA="$2" DEADLINE_SECONDS="$3" POLL_SECONDS=0 \
        bash "$WAIT_SH" > "$dir/out.log" 2>&1
    return $?
}

S='{"phase":"%s","sha":"%s","pid":42,"started_at":"t","updated_at":"t","exit_code":%s,"log_file":"deploy-runner-fake.log","message":"m"%s}'
status() { printf "$S" "$1" "$2" "${3:-null}" "${4:+,\"step\":\"$4\"}"; }

echo "1) running (candidate, cutover) -> success: Exit 0, Fortschritt protokolliert"
D1="$(mktemp -d)"; make_fake_ssh "$D1"
status running abc123 null candidate > "$D1/status.1"
status running abc123 null cutover   > "$D1/status.2"
status success abc123 0              > "$D1/status.3"
run_wait "$D1" true 20; RC=$?
[[ "$RC" -eq 0 ]] && ok "Exit 0 bei success" || bad "Exit $RC statt 0: $(cat "$D1/out.log")"
grep -q "Status: phase=running step=candidate sha=abc123" "$D1/out.log" && grep -q "step=cutover" "$D1/out.log" && ok "Phase/Schritt-Wechsel protokolliert" || bad "Fortschritt nicht protokolliert: $(cat "$D1/out.log")"
grep -q "Deployment abgeschlossen (Status: success, sha=abc123)" "$D1/out.log" && ok "Erfolg erkannt" || bad "Erfolg nicht gemeldet"
rm -rf "$D1"

echo "2) running -> failed: Exit 1, Protokollzeilen nachgeladen"
D2="$(mktemp -d)"; make_fake_ssh "$D2"
status running abc123 null redis-infrastruktur > "$D2/status.1"
status failed abc123 1 > "$D2/status.2"
run_wait "$D2" true 20; RC=$?
[[ "$RC" -eq 1 ]] && ok "Exit 1 bei failed" || bad "Exit $RC statt 1"
grep -q "::error::Deployment fehlgeschlagen" "$D2/out.log" && ok "Fehlschlag gemeldet" || bad "Fehlschlag nicht gemeldet"
grep -q "(fake tail)" "$D2/out.log" && ok "Letzte Protokollzeilen vom Server nachgeladen (--tail 80)" || bad "--tail wurde nicht abgerufen"
rm -rf "$D2"

echo "3) unknown und SSH-Abbruch werden toleriert: danach running -> success"
D3="$(mktemp -d)"; make_fake_ssh "$D3"
echo '{"phase":"unknown","message":"Noch kein Deployment"}' > "$D3/status.1"
echo SSHFAIL > "$D3/status.2"
echo EMPTY > "$D3/status.3"
status running abc123 null candidate > "$D3/status.4"
status success abc123 0 > "$D3/status.5"
run_wait "$D3" true 20; RC=$?
[[ "$RC" -eq 0 ]] && ok "Exit 0 trotz unknown/SSH-Abbruch/leerer Antwort zwischendurch" || bad "Exit $RC: $(cat "$D3/out.log")"
[[ "$(cat "$D3/ptr")" -ge 5 ]] && ok "Es wurde weiter abgefragt (mindestens 5 Abfragen)" || bad "Zu wenige Abfragen: $(cat "$D3/ptr")"
rm -rf "$D3"

echo "4) success fuer anderen sha, dieser Lauf hatte selbst ausgeloest (EXPECT_SHA=true): Exit 1"
D4="$(mktemp -d)"; make_fake_ssh "$D4"
status success deadbeef 0 > "$D4/status.1"
run_wait "$D4" true 20; RC=$?
[[ "$RC" -eq 1 ]] && ok "Exit 1 bei falschem sha" || bad "Exit $RC statt 1"
grep -q "betraf sha=deadbeef, nicht abc123" "$D4/out.log" && ok "sha-Abweichung benannt" || bad "sha-Abweichung nicht gemeldet"
rm -rf "$D4"

echo "5) success fuer anderen sha ohne eigenes Ausloesen (EXPECT_SHA=false): Exit 0 mit Hinweis"
D5="$(mktemp -d)"; make_fake_ssh "$D5"
status success deadbeef 0 > "$D5/status.1"
run_wait "$D5" false 20; RC=$?
[[ "$RC" -eq 0 ]] && ok "Exit 0" || bad "Exit $RC statt 0"
grep -q "::notice::Dieser Lauf hat selbst kein Deployment ausgeloest" "$D5/out.log" && ok "Hinweis ausgegeben" || bad "Hinweis fehlt"
rm -rf "$D5"

echo "6) Nur running bis zur Frist: Exit 1 mit Zeitueberschreitung und Recovery-Hinweis"
D6="$(mktemp -d)"; make_fake_ssh "$D6"
status running abc123 null warte-healthy > "$D6/status.1"
run_wait "$D6" true 2; RC=$?
[[ "$RC" -eq 1 ]] && ok "Exit 1 bei Zeitueberschreitung" || bad "Exit $RC statt 1"
grep -q "Zeitueberschreitung beim Warten" "$D6/out.log" && grep -q "deploy-status.sh --tail 80" "$D6/out.log" && ok "Zeitueberschreitung und Hinweis auf deploy-status.sh --tail gemeldet" || bad "Meldung unvollstaendig: $(cat "$D6/out.log")"
rm -rf "$D6"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
