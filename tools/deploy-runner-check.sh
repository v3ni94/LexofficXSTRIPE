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
#   6. Mit der ECHTEN deploy.sh (Fake-"docker", tools/lib/deploy-sandbox.sh): Die Statusdatei ist
#      unmittelbar nach dem Ausloesen "running" mit dem richtigen sha/pid und bleibt es WAEHREND des
#      gesamten Laufs, insbesondere nach dem "rsync --delete" von deploy/vps in den Deploy-Ordner (das
#      loeschte sie frueher: GitHub sah 12 Minuten "unknown", Version 4.11); jede Zwischenabfrage liefert
#      gueltiges JSON (atomares Schreiben), das Feld "step" zeigt den Fortschritt, am Ende "success" mit
#      exit_code 0 und einer existierenden Protokolldatei; deploy-status.sh --tail funktioniert.
#   7. Ein fehlgeschlagener echter Lauf endet mit "failed" und dem Exitcode von deploy.sh; --tail zeigt
#      die Fehlerzeile.
#   8. Statusdatei und Protokoll enthalten keine Geheimnisse aus deploy/.env; die Statusdatei enthaelt
#      nur die erwarteten Felder.
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
legacy_sandbox() {
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
legacy_trigger() {
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

# Gemeinsame Sandbox mit Fake-"docker" fuer die Szenarien 7 bis 9, in denen der Runner die ECHTE
# deploy.sh ausfuehrt (tools/lib/deploy-sandbox.sh; liefert auch wait_for_phase/status_field). Der Trap
# raeumt alle Sandboxes auch bei einem abgebrochenen Lauf auf.
# shellcheck source=lib/deploy-sandbox.sh
source "$ROOT/tools/lib/deploy-sandbox.sh"
trap sandbox_cleanup_all EXIT

echo "1) Erfolgreicher Lauf: Status wird 'success', Exit-Code 0"
S1="$(legacy_sandbox)"
prepare_runner_for_sandbox "$S1"
make_fake_deploy "$S1" ok
OUT="$(legacy_trigger "$S1" 2>&1)"
if [[ "$OUT" == TRIGGERED* ]] && wait_for_phase "$S1" success; then
    ok "Phase 'success' erreicht ($OUT)"
else
    bad "Erwartet 'success', Status: $(cat "$S1/deploy/.deploy-status.json" 2>/dev/null) ($OUT)"
fi
[[ "$(status_exit_code "$S1")" == "0" ]] && ok "Exit-Code 0 in der Statusdatei" || bad "Exit-Code nicht 0"
[[ ! -f "$S1/deploy/.deploy.pid" ]] && ok "PID-Datei nach Abschluss entfernt" || bad "PID-Datei blieb liegen"
rm -rf "$S1"

echo "2) Fehlgeschlagener Lauf: Status wird 'failed', Exit-Code wird uebernommen"
S2="$(legacy_sandbox)"
prepare_runner_for_sandbox "$S2"
make_fake_deploy "$S2" fail
legacy_trigger "$S2" >/dev/null 2>&1
if wait_for_phase "$S2" failed; then
    ok "Phase 'failed' erreicht"
else
    bad "Erwartet 'failed', Status: $(cat "$S2/deploy/.deploy-status.json" 2>/dev/null)"
fi
[[ "$(status_exit_code "$S2")" == "7" ]] && ok "Exit-Code 7 (vom Fake-deploy.sh) korrekt uebernommen" || bad "Exit-Code falsch: $(status_exit_code "$S2")"
rm -rf "$S2"

echo "3) Zweiter, gleichzeitiger Versuch wird abgelehnt (kein Doppel-Deploy)"
S3="$(legacy_sandbox)"
prepare_runner_for_sandbox "$S3"
make_fake_deploy "$S3" slow
legacy_trigger "$S3" >/dev/null 2>&1
sleep 0.5
OUT2="$(legacy_trigger "$S3" 2>&1)"
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
S4="$(legacy_sandbox)"
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
S5="$(legacy_sandbox)"
prepare_runner_for_sandbox "$S5"
make_fake_deploy "$S5" ok
legacy_trigger "$S5" >/dev/null 2>&1
wait_for_phase "$S5" success
OUT5="$(legacy_trigger "$S5" 2>&1)"
if [[ "$OUT5" == TRIGGERED* ]] && wait_for_phase "$S5" success; then
    ok "Erneuter Lauf nach Abschluss erfolgreich (Sperre wurde freigegeben)"
else
    bad "Erneuter Lauf schlug fehl: $OUT5 / $(cat "$S5/deploy/.deploy-status.json" 2>/dev/null)"
fi
rm -rf "$S5"

echo "6) Keine Geheimnisse: Die Skripte lesen deploy/.env nie im Klartext (kein 'cat .env'/'source .env')"
SECRET_GREP="$(mktemp)"
if grep -rEn "cat[[:space:]]+.*\.env[\"']?\$|source[[:space:]]+.*/\.env[\"']?\$" \
    "$ROOT/deploy/vps/scripts/deploy-runner.sh" "$ROOT/deploy/vps/scripts/deploy.sh" \
    "$ROOT/deploy/vps/scripts/rollback.sh" "$ROOT/deploy/vps/scripts/deploy-status.sh" >"$SECRET_GREP"; then
    bad "Verdaechtige Zeile gefunden: $(cat "$SECRET_GREP")"
else
    ok "Kein 'cat .env' / 'source .env' in den Deploy-Skripten"
fi
rm -f "$SECRET_GREP"

echo "7) Echte deploy.sh unter dem Runner: Status sofort 'running', ueberlebt den rsync, gueltiges JSON bei jeder Abfrage, 'step', --tail waehrend des Laufs, am Ende 'success'"
S7="$(new_sandbox)"
echo 'DB_PASS=streng-geheim-Testwert-4711' >> "$S7/deploy/.env"
make_release "$S7" prevsha "protected-mode no"
make_release "$S7" newsha "protected-mode no"
set_current "$S7" prevsha
make_fake_docker "$S7"
# Veralteter Stand eines FRUEHEREN Laufs (die Statusdatei ueberlebt seit 4.11 jeden Lauf): Er darf nach dem
# Ausloesen zu keinem Zeitpunkt mehr sichtbar sein, sonst wertete das GitHub-Polling "failed"/alten sha als
# Ergebnis des neuen Laufs.
printf '{"phase":"failed","sha":"altersha","pid":4711,"started_at":"2026-01-01T00:00:00Z","updated_at":"2026-01-01T00:01:00Z","exit_code":1,"log_file":"deploy-runner-alt.log","message":"alter Lauf"}\n' > "$S7/deploy/.deploy-status.json"
export FAKE_SLOW_STEP="up -d --remove-orphans" FAKE_SLOW_SECONDS=4
OUT7="$(run_trigger "$S7" newsha 2>&1)"
[[ "$OUT7" == TRIGGERED* ]] && ok "Runner meldet TRIGGERED ($OUT7)" || bad "Runner meldete nicht TRIGGERED: $OUT7"
PI="$(status_field "$S7" phase)"; SHAI="$(status_field "$S7" sha)"
[[ "$PI" == "running" && "$SHAI" == "newsha" ]] && ok "Unmittelbar nach TRIGGERED (ohne Wartezeit): running fuer newsha, nie mehr der veraltete Stand (failed/altersha)" || bad "Direkt nach TRIGGERED: phase=$PI sha=$SHAI ($(status_json "$S7"))"
sleep 0.4
P0="$(status_field "$S7" phase)"; SHA0="$(status_field "$S7" sha)"; PID0="$(status_field "$S7" pid)"
[[ "$P0" == "running" ]] && ok "Status unmittelbar nach dem Ausloesen: running" || bad "Status nach dem Ausloesen: '${P0:-<leer>}' statt running ($(status_json "$S7"))"
[[ "$SHA0" == "newsha" ]] && ok "Status-SHA entspricht dem gestarteten Release (newsha)" || bad "Status-SHA falsch: $SHA0"
[[ "$PID0" =~ ^[0-9]+$ ]] && ok "PID im Status numerisch ($PID0)" || bad "PID im Status fehlt/ungueltig: $PID0"
[[ -n "$(status_field "$S7" started_at)" && -n "$(status_field "$S7" log_file)" ]] && ok "started_at und log_file gesetzt" || bad "started_at/log_file fehlen"
# Waehrend des Laufs (der rsync von deploy/vps ist laengst passiert, der Fake haelt den Cutover 4 s auf):
# jede Abfrage muss gueltiges JSON mit phase=running liefern, nie 'unknown' oder eine fehlende Datei; und
# "--tail" muss das Protokoll auch JETZT finden (nicht erst nach Abschluss), denn genau das ist der
# dokumentierte Diagnoseweg bei einer Zeitueberschreitung.
BAD_POLLS=0; POLLS=0; STEPS_SEEN=""; MID_TAIL=""
for _ in $(seq 1 12); do
    OUTP="$(run_status "$S7" newsha)"
    POLLS=$((POLLS + 1))
    if ! printf '%s' "$OUTP" | jq -e . >/dev/null 2>&1; then BAD_POLLS=$((BAD_POLLS + 1)); continue; fi
    PH="$(printf '%s' "$OUTP" | jq -r '.phase // empty')"
    ST="$(printf '%s' "$OUTP" | jq -r '.step // empty')"
    [[ -n "$ST" && "$STEPS_SEEN" != *"$ST"* ]] && STEPS_SEEN="$STEPS_SEEN $ST"
    if [[ "$PH" != "running" && "$PH" != "success" ]]; then BAD_POLLS=$((BAD_POLLS + 1)); fi
    if [[ -z "$MID_TAIL" && "$PH" == "running" && -n "$ST" ]]; then MID_TAIL="$(run_status "$S7" newsha --tail 3)"; fi
    sleep 0.25
done
[[ "$BAD_POLLS" -eq 0 ]] && ok "Alle $POLLS Abfragen waehrend des Laufs: gueltiges JSON, phase running/success (Statusdatei ueberlebt den rsync)" || bad "$BAD_POLLS von $POLLS Abfragen waehrend des Laufs lieferten unknown/ungueltiges JSON"
[[ "$STEPS_SEEN" == *"cutover"* || "$STEPS_SEEN" == *"warte-healthy"* || "$STEPS_SEEN" == *"candidate"* ]] && ok "Feld 'step' zeigt den Fortschritt (gesehen:$STEPS_SEEN)" || bad "Kein Fortschrittsschritt im Status gesehen (gesehen:$STEPS_SEEN)"
if [[ -n "$MID_TAIL" ]] && printf '%s' "$MID_TAIL" | grep -q -- "--- letzte 3 Zeilen von" && ! printf '%s' "$MID_TAIL" | grep -q "Protokolldatei nicht gefunden"; then
    ok "deploy-status.sh --tail findet das Protokoll WAEHREND des Laufs (Statusdatei mit 'step' von deploy_step)"
else
    bad "--tail waehrend des Laufs ohne Protokoll: ${MID_TAIL:-<keine Abfrage im Zustand running/step>}"
fi
if wait_for_phase "$S7" success; then ok "Phase 'success' erreicht"; else bad "Kein success: $(status_json "$S7")"; fi
[[ "$(status_field "$S7" exit_code)" == "0" ]] && ok "exit_code 0" || bad "exit_code: $(status_field "$S7" exit_code)"
LOGF="$S7/logs/$(status_field "$S7" log_file)"
[[ -s "$LOGF" ]] && ok "Protokolldatei existiert und ist nicht leer ($(basename "$LOGF"))" || bad "Protokolldatei fehlt: $LOGF"
TAIL7="$(run_status "$S7" newsha --tail 5)"
printf '%s' "$TAIL7" | grep -q -- "--- letzte 5 Zeilen von" && printf '%s' "$TAIL7" | grep -q "Deployment newsha abgeschlossen" && ok "deploy-status.sh --tail liefert die letzten Protokollzeilen" || bad "--tail unvollstaendig: $TAIL7"
grep -q "streng-geheim-Testwert-4711" "$S7/deploy/.deploy-status.json" "$LOGF" 2>/dev/null && bad "Geheimnis aus deploy/.env in Status oder Protokoll gefunden" || ok "Kein Geheimnis aus deploy/.env in Statusdatei oder Protokoll"
KEYS="$(jq -r 'keys[]' "$S7/deploy/.deploy-status.json" | sort | tr '\n' ' ')"
# Die finale Statusdatei schreibt der Runner vollstaendig neu (phase/sha/pid/exit_code/...); das nur waehrend
# des Laufs von deploy.sh gepflegte Feld "step" ist darin absichtlich nicht mehr enthalten.
[[ "$KEYS" == "exit_code log_file message phase pid sha started_at updated_at " ]] && ok "Finale Statusdatei enthaelt genau die erwarteten Felder" || bad "Unerwartete Felder in der Statusdatei: $KEYS"
[[ ! -f "$S7/deploy/.deploy.pid" ]] && ok "PID-Datei nach Abschluss entfernt" || bad "PID-Datei blieb liegen"
unset FAKE_SLOW_STEP FAKE_SLOW_SECONDS
rm -rf "$S7"

echo "8) Echte deploy.sh schlaegt fehl: Status 'failed' mit Exitcode, --tail zeigt die Fehlerzeile"
S8="$(new_sandbox)"
make_release "$S8" prevsha "protected-mode no"
make_release "$S8" newsha "protected-mode no"
set_current "$S8" prevsha
make_fake_docker "$S8"
export FAKE_ISOLATION_BAD=1
run_trigger "$S8" newsha >/dev/null 2>&1
unset FAKE_ISOLATION_BAD
if wait_for_phase "$S8" failed; then ok "Phase 'failed' erreicht"; else bad "Kein failed: $(status_json "$S8")"; fi
[[ "$(status_field "$S8" exit_code)" == "1" ]] && ok "exit_code 1 von deploy.sh uebernommen" || bad "exit_code: $(status_field "$S8" exit_code)"
[[ "$(status_field "$S8" sha)" == "newsha" ]] && ok "sha im Fehlstatus korrekt" || bad "sha im Fehlstatus: $(status_field "$S8" sha)"
run_status "$S8" newsha --tail 30 | grep -q "Isolationsvorgaben" && ok "--tail zeigt die tatsaechliche Fehlerzeile" || bad "--tail zeigt die Fehlerzeile nicht"
rm -rf "$S8"

echo "9) Atomares Schreiben: Runner und deploy_step schreiben ueber Zwischendatei + mv (statisch)"
grep -q 'mv -f "$tmp" "$STATUS_FILE"' "$RUNNER_SRC" && ok "deploy-runner.sh schreibt die Statusdatei atomar (tmp + mv)" || bad "deploy-runner.sh schreibt nicht atomar"
grep -q 'mv -f "$tmp" "$f"' "$DEPLOY_SH_SRC" && ok "deploy.sh (deploy_step) aktualisiert die Statusdatei atomar (tmp + mv)" || bad "deploy_step schreibt nicht atomar"
grep -q -- "--exclude '/.deploy\*'" "$DEPLOY_SH_SRC" && ok "deploy.sh: rsync schliesst /.deploy* (Status, PID, Sperre) aus" || bad "deploy.sh: rsync-Exclude /.deploy* fehlt"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
exit $((FAIL > 0 ? 1 : 0))
