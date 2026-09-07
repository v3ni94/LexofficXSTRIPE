#!/usr/bin/env bash
#
# Regressionstest fuer deploy/vps/scripts/deploy-runner.sh, OHNE Docker, OHNE echten Server: Simuliert
# /opt/smarteinzug in einem temporaeren Ordner und ersetzt deploy.sh durch einen steuerbaren Fake, der
# je nach Testfall erfolgreich ist, fehlschlaegt oder eine Zeit lang laeuft. Prueft genau die
# Eigenschaften, wegen derer deploy-runner.sh eingefuehrt wurde:
#
#   1. Ein simulierter SSH-Abbruch (SIGHUP an bzw. Beenden des ausloesenden Vordergrundprozesses)
#      stoppt den bereits per "setsid" entkoppelten Hintergrundlauf NICHT (das eigentliche Problem,
#      das zum Fehlschlag "client_loop: send disconnect: Broken pipe" fuehrte).
#   2. Ein zweiter, gleichzeitiger Versuch wird abgelehnt (kein Doppel-Deploy durch einen GitHub-Retry
#      oder einen zweiten Workflow-Lauf), waehrend der erste unbeeinflusst weiterlaeuft.
#   3. Erfolg und Fehlschlag landen korrekt (Phase, Exit-Code) in der Statusdatei.
#   4. Nach Abschluss ist ein erneuter Lauf wieder moeglich (idempotent, keine haengengebliebene Sperre).
#   5. Es werden keine Geheimnisse protokolliert (die Skripte lesen .env nie im Klartext ein).
#
# Aufruf:  bash tools/deploy-runner-check.sh        Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER_SRC="$ROOT/deploy/vps/scripts/deploy-runner.sh"

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

# --- Eine isolierte /opt/smarteinzug-Umgebung je Testfall aufbauen -----------------------------------
new_sandbox() {
    local dir
    dir="$(mktemp -d)"
    install -d -m 750 "$dir/deploy" "$dir/logs" "$dir/releases/testsha/deploy/vps/scripts"
    cp "$RUNNER_SRC" "$dir/releases/testsha/deploy/vps/scripts/deploy-runner.sh"
    printf '%s' "$dir"
}

# Fake-deploy.sh: verhaelt sich je nach $2 (ok|fail|slow) unterschiedlich; schreibt einen Marker mit
# seiner eigenen PID/Sitzungs-ID, damit sich bei Bedarf nachweisen laesst, in welcher Sitzung der
# eigentliche Deploy-Prozess lief.
make_fake_deploy() {
    local sandbox="$1" mode="$2"
    cat > "$sandbox/releases/testsha/deploy/vps/scripts/deploy.sh" <<FAKE
#!/usr/bin/env bash
set -uo pipefail
echo "PID=\$\$ SID=\$(ps -o sid= -p \$\$ | tr -d ' ')" > "$sandbox/deploy/.fake-deploy-marker"
case "$mode" in
    ok)   sleep 0.3; exit 0 ;;
    fail) sleep 0.3; exit 7 ;;
    slow) sleep 3; echo done > "$sandbox/deploy/.fake-deploy-finished"; exit 0 ;;
esac
FAKE
    chmod +x "$sandbox/releases/testsha/deploy/vps/scripts/deploy.sh"
}

status_phase() {
    local sandbox="$1"
    sed -n 's/.*"phase":"\([a-z]*\)".*/\1/p' "$sandbox/deploy/.deploy-status.json" 2>/dev/null
}
status_exit_code() {
    local sandbox="$1"
    sed -n 's/.*"exit_code":\([0-9null]*\).*/\1/p' "$sandbox/deploy/.deploy-status.json" 2>/dev/null
}
wait_for_phase() {
    local sandbox="$1" want="$2" tries=0
    while [[ "$(status_phase "$sandbox")" != "$want" && $tries -lt 50 ]]; do
        sleep 0.2
        tries=$((tries + 1))
    done
    [[ "$(status_phase "$sandbox")" == "$want" ]]
}
run_trigger() {
    # Fuehrt deploy-runner.sh so aus, wie es ein SSH-Kommando taete: eigener BASE=/opt/smarteinzug wird
    # ueber ein Wrapper-Skript vorgegaukelt, indem wir das Runner-Skript in eine Kopie mit
    # ausgetauschtem BASE kopieren (einfacher und robuster als /opt/smarteinzug real zu verwenden).
    local sandbox="$1" sha="${2:-testsha}"
    local runner="$sandbox/releases/$sha/deploy/vps/scripts/deploy-runner.sh"
    bash "$runner" "$sha"
}

# Da deploy-runner.sh mit BASE=/opt/smarteinzug fest verdrahtet ist (bewusst: keine Ueberraschungen im
# echten Betrieb durch eine veraenderliche Umgebungsvariable), erzeugt dieser Test fuer jeden Lauf eine
# Kopie des Skripts mit umgeschriebenem BASE auf den Sandbox-Pfad. Das ist die einzige Anpassung; Ablauf,
# Locking und Prozess-Handling bleiben exakt der Originalcode.
prepare_runner_for_sandbox() {
    local sandbox="$1"
    local runner="$sandbox/releases/testsha/deploy/vps/scripts/deploy-runner.sh"
    sed -i "s#^BASE=/opt/smarteinzug\$#BASE=$sandbox#" "$runner"
    grep -q "^BASE=$sandbox\$" "$runner" || { echo "::error:: BASE-Ersetzung im Runner fehlgeschlagen"; exit 2; }
}

echo "1) Erfolgreicher Lauf: Status wird 'success', Exit-Code 0"
S1="$(new_sandbox)"
prepare_runner_for_sandbox "$S1"
make_fake_deploy "$S1" ok
OUT="$(run_trigger "$S1" 2>&1)"
if [[ "$OUT" == TRIGGERED* ]] && wait_for_phase "$S1" success; then
    ok "Phase 'success' erreicht ($OUT)"
else
    bad "Erwartet 'success', Status: $(cat "$S1/deploy/.deploy-status.json" 2>/dev/null) ($OUT)"
fi
[[ "$(status_exit_code "$S1")" == "0" ]] && ok "Exit-Code 0 in der Statusdatei" || bad "Exit-Code nicht 0"
[[ ! -f "$S1/deploy/.deploy.pid" ]] && ok "PID-Datei nach Abschluss entfernt" || bad "PID-Datei blieb liegen"
rm -rf "$S1"

echo "2) Fehlgeschlagener Lauf: Status wird 'failed', Exit-Code wird uebernommen"
S2="$(new_sandbox)"
prepare_runner_for_sandbox "$S2"
make_fake_deploy "$S2" fail
run_trigger "$S2" >/dev/null 2>&1
if wait_for_phase "$S2" failed; then
    ok "Phase 'failed' erreicht"
else
    bad "Erwartet 'failed', Status: $(cat "$S2/deploy/.deploy-status.json" 2>/dev/null)"
fi
[[ "$(status_exit_code "$S2")" == "7" ]] && ok "Exit-Code 7 (vom Fake-deploy.sh) korrekt uebernommen" || bad "Exit-Code falsch: $(status_exit_code "$S2")"
rm -rf "$S2"

echo "3) Zweiter, gleichzeitiger Versuch wird abgelehnt (kein Doppel-Deploy)"
S3="$(new_sandbox)"
prepare_runner_for_sandbox "$S3"
make_fake_deploy "$S3" slow
run_trigger "$S3" >/dev/null 2>&1
sleep 0.5
OUT2="$(run_trigger "$S3" 2>&1)"
RC2=$?
if [[ "$OUT2" == REJECTED* ]]; then
    ok "Zweiter Versuch mit REJECTED abgelehnt, waehrend der erste noch laeuft"
else
    bad "Zweiter Versuch wurde nicht abgelehnt: $OUT2"
fi
[[ "$RC2" == "3" ]] && ok "Exit-Code 3 fuer den abgelehnten zweiten Versuch" || bad "Exit-Code des abgelehnten Versuchs ist $RC2, erwartet 3"
if wait_for_phase "$S3" success; then
    ok "Erster (langsamer) Lauf wurde durch den abgelehnten zweiten Versuch NICHT gestoert"
else
    bad "Erster Lauf wurde beeintraechtigt: $(cat "$S3/deploy/.deploy-status.json" 2>/dev/null)"
fi
rm -rf "$S3"

echo "4) Simulierter SSH-Abbruch: Der entkoppelte Hintergrundlauf ueberlebt das Ende des ausloesenden Prozesses"
S4="$(new_sandbox)"
prepare_runner_for_sandbox "$S4"
make_fake_deploy "$S4" slow
# Den ausloesenden Aufruf in einer EIGENEN Prozessgruppe starten (wie es eine SSH-Sitzung waere) und
# diese Prozessgruppe kurz danach hart beenden - das entspricht einem abbrechenden SSH-Kanal. Der
# eigentliche Deploy laeuft, weil "setsid" ihn in einer eigenen Sitzung gestartet hat, unbeeindruckt weiter.
setsid bash -c "cd '$S4' && bash '$S4/releases/testsha/deploy/vps/scripts/deploy-runner.sh' testsha >'$S4/trigger.out' 2>&1" &
TRIGGER_PGID=$!
sleep 0.6
kill -KILL -- "-$TRIGGER_PGID" 2>/dev/null || kill -KILL "$TRIGGER_PGID" 2>/dev/null || true
wait "$TRIGGER_PGID" 2>/dev/null || true
if wait_for_phase "$S4" success; then
    ok "Deployment lief trotz hart beendetem ausloesenden Prozess bis zum Ende durch"
else
    bad "Deployment wurde durch das Beenden des ausloesenden Prozesses gestoppt: $(cat "$S4/deploy/.deploy-status.json" 2>/dev/null)"
fi
[[ -f "$S4/deploy/.fake-deploy-finished" ]] && ok "Fake-deploy.sh ist bis zum eigenen Ende gelaufen (nicht abgebrochen)" || bad "Fake-deploy.sh wurde vorzeitig beendet"
rm -rf "$S4"

echo "5) Idempotenz: Nach Abschluss ist ein erneuter Lauf sofort wieder moeglich"
S5="$(new_sandbox)"
prepare_runner_for_sandbox "$S5"
make_fake_deploy "$S5" ok
run_trigger "$S5" >/dev/null 2>&1
wait_for_phase "$S5" success
OUT5="$(run_trigger "$S5" 2>&1)"
if [[ "$OUT5" == TRIGGERED* ]] && wait_for_phase "$S5" success; then
    ok "Erneuter Lauf nach Abschluss erfolgreich (Sperre wurde freigegeben)"
else
    bad "Erneuter Lauf schlug fehl: $OUT5 / $(cat "$S5/deploy/.deploy-status.json" 2>/dev/null)"
fi
rm -rf "$S5"

echo "6) Keine Geheimnisse: Die Skripte lesen deploy/.env nie im Klartext (kein 'cat .env'/'source .env')"
if grep -rEn "cat[[:space:]]+.*\.env[\"']?\$|source[[:space:]]+.*/\.env[\"']?\$" \
    "$ROOT/deploy/vps/scripts/deploy-runner.sh" "$ROOT/deploy/vps/scripts/deploy.sh" \
    "$ROOT/deploy/vps/scripts/rollback.sh" "$ROOT/deploy/vps/scripts/deploy-status.sh" >/tmp/secret-grep.$$; then
    bad "Verdaechtige Zeile gefunden: $(cat /tmp/secret-grep.$$)"
else
    ok "Kein 'cat .env' / 'source .env' in den Deploy-Skripten"
fi
rm -f /tmp/secret-grep.$$

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
exit $((FAIL > 0 ? 1 : 0))
