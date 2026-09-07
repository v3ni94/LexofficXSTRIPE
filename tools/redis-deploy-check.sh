#!/usr/bin/env bash
#
# Regressionstest fuer den Redis-Infrastruktur-Teil von deploy/vps/scripts/deploy.sh, OHNE echten
# Docker-Daemon: simuliert /opt/smarteinzug in einem temporaeren Ordner (wie tools/deploy-runner-check.sh)
# und ersetzt "docker" durch einen steuerbaren Fake, der die tatsaechliche bash-Logik von deploy.sh
# unveraendert ausfuehrt (kein Fake von deploy.sh selbst).
#
# Hintergrund (Bootstrap-Problem, Version 4.8 -> 4.9): Die Candidate-Pruefung kommunizierte mit dem
# BEREITS LAUFENDEN Redis-Container (alte redis.conf); eine redis.conf-Korrektur (protected-mode no)
# konnte sich dadurch nicht selbst deployen, weil die Candidate-Pruefung immer gegen den alten,
# unveraenderten Redis-Container lief. deploy.sh prueft jetzt VOR der Candidate-Pruefung, ob sich
# redis.conf gegenueber dem laufenden Release geaendert hat, und aktualisiert in diesem Fall
# AUSSCHLIESSLICH den redis-Dienst kontrolliert (validieren, force-recreate, auf healthy warten,
# Zugriff aus einem ANDEREN Container ueber das interne Netz pruefen - nicht per "docker exec redis
# redis-cli ping", das genau das protected-mode-Problem verborgen hatte), mit definiertem Rollback der
# Redis-Infrastruktur (nicht des gesamten Releases) bei einem Fehlschlag.
#
# Der Fake-"docker" antwortet auf den Netzwerktest ("bin/healthcheck.php --redis") NICHT mit einem
# festen Wert, sondern anhand des TATSAECHLICHEN, gerade aktiven Inhalts von deploy/redis/redis.conf
# (enthaelt die Zeile "protected-mode no"? ja = erreichbar, nein = wie beim echten Fehler blockiert).
# Das macht die simulierten Szenarien inhaltlich echt, nicht nur formal grün.
#
# Geprueft wird (Nummern entsprechen den Abschnitten der Ausgabe):
#   1. redis.conf unveraendert gegenueber dem laufenden Release -> Redis wird NICHT recreated; Candidate,
#      Migration und Cutover laufen normal durch; kein Vorab-Stopp, weil die laufenden Container bereits die
#      neue Stop-Konfiguration tragen.
#   2. redis.conf geaendert -> AUSSCHLIESSLICH Redis wird vor der Candidate-Pruefung aktualisiert; danach
#      erreicht der neue Redis-Dienst "healthy" UND ist aus einem anderen Container ueber das interne
#      Netz erreichbar -> Candidate, Migration und Cutover laufen normal durch.
#   3. Redis wird zwar "healthy" (Docker-Healthcheck, entspricht Loopback-Ping), ist aber ueber das
#      interne Netz aus einem anderen Container NICHT erreichbar (genau der urspruengliche
#      protected-mode-Fehler) -> Deployment stoppt, die Redis-Infrastruktur wird auf die vorherige
#      Konfiguration zurueckgesetzt und deren Erreichbarkeit erneut bestaetigt; KEINE Migration, KEIN
#      Cutover.
#   4. Eine ungueltige neue redis.conf wird bereits in der Vorab-Validierung erkannt, BEVOR sie jemals aktiv
#      wird; der Dienst wird ueber den Rollback-Pfad einmal mit der alten, unveraenderten Konfiguration neu erzeugt.
#   5. Verletzt Redis die Netzwerk-Isolationsvorgaben (hier simuliert: veroeffentlichter Host-Port), bricht
#      das Deployment ab, BEVOR irgendetwas an Redis geaendert wird.
#      (In keinem Fehlerfall, 3 bis 5 und 9, 11, 12, 14, wird jemals bin/migrate.php oder der Cutover
#      "up -d --remove-orphans" aufgerufen; das wird in jedem dieser Abschnitte einzeln geprueft.)
#   6. Eine Wiederholung desselben (bereits erfolgreichen) Deployments bleibt idempotent (zweiter Lauf
#      ebenfalls erfolgreich, keine Fehlerhaeufung).
#   7. Fehlt der Alias "smarteinzug-redis" (Redis in anderem Compose-Projekt/Netz), bricht das Deployment mit
#      der Diagnose alias_missing ab; der Rollback der Redis-Infrastruktur wird zweistufig bewertet: die
#      technische Wiederherstellung (redis.conf zurueck, recreated, healthy) ist hartes Kriterium, der
#      Netzwerktest gegen den bekannt alten, defekten Zustand nur eine Warnung.
#   8. Der Netzwerktest nach dem Recreate laeuft mit dem Code des NEUEN Release, alle Compose-Aufrufe
#      treffen dasselbe Projekt, jeder "docker compose run" nutzt --no-deps, die Reihenfolge ist
#      Recreate -> Netzwerktest -> Candidate -> Migration -> Cutover, und es gibt keinen zweiten
#      Worker-Neustart ("restart -t") mehr, sondern eine Verifikation der Release-Bindung per inspect.
#   9. Harte Stufe des Rollbacks: schlaegt auch das Recreate im Rollback fehl, wird "TECHNISCH
#      fehlgeschlagen" gemeldet (Exitcode ungleich 0, keine Migration, kein Cutover).
#  10. Aendert sich die Compose-DEFINITION von redis (Konfigurations-Hash, z.B. neuer Alias), aber nicht
#      redis.conf, wird redis trotzdem VOR der Candidate-Pruefung kontrolliert neu erzeugt.
#  11. Redis wird nach dem Recreate nie "healthy": Zeitueberschreitung (REDIS_WAIT_HEALTHY_SECONDS verkuerzt),
#      Rollback-Pfad, Redis-Protokoll im Deploy-Protokoll, keine Migration, kein Cutover.
#  12. "ps -a redis" liefert KEINE Zeile (Container fehlt): gilt nicht als gesund ("kein-redis-container").
#  13. Laufende Hintergrund-Container mit ALTER Stop-Konfiguration (SIGQUIT/660 s, erstes Deployment ab 4.11):
#      deploy.sh beendet genau diese vorab mit "docker stop --signal SIGTERM --timeout 90" (php/redis nicht),
#      nach der Migration und vor dem Cutover; ein weiteres Deployment loest keinen Vorab-Stopp mehr aus.
#  14. Nur die Compose-Definition geaendert (redis.conf unveraendert) und der Netzwerktest scheitert: Die
#      Meldung des Rueckbaus ist ehrlich (redis.conf war unveraendert, Definition nicht zurueckgebaut,
#      Hinweis auf rollback.sh), kein falsches "TECHNISCH erfolgreich ... zurueckgesetzt".
#  15. Docker-CLI ohne "stop --signal" (aelter als Version 23): deploy.sh erkennt das selbst und weicht auf
#      "kill --signal SIGTERM" plus Warten aus; kein Aufruf von "stop --signal", Deployment laeuft durch.
#  16. Ausweichweg, aber die Container enden nicht innerhalb der Frist: Warnung, danach regulaerer Stopp mit
#      kurzer Frist, Deployment laeuft trotzdem durch (kein Warten auf die alten 660 s).
#  17. Statusseite: deploy.sh legt /opt/smarteinzug/shared/status an und kopiert den Platzhalter des
#      Release einmalig hinein; bereits veroeffentlichte Statusdaten ueberleben jedes Folgedeployment.
#
# Aufruf: bash tools/redis-deploy-check.sh        Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

# Sandbox, Fake-"docker", run_deploy/calls/output: tools/lib/deploy-sandbox.sh (gemeinsam mit
# tools/deploy-runner-check.sh).
# shellcheck source=lib/deploy-sandbox.sh
source "$ROOT/tools/lib/deploy-sandbox.sh"
trap sandbox_cleanup_all EXIT

echo "1) redis.conf unveraendert: Redis wird nicht angefasst, Candidate/Migration/Cutover laufen durch"
S1="$(new_sandbox)"
make_release "$S1" prevsha "protected-mode no"
make_release "$S1" newsha "protected-mode no"
set_current "$S1" prevsha
make_fake_docker "$S1"
run_deploy "$S1" newsha; RC1=$?
[[ "$RC1" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl (Exitcode $RC1): $(output "$S1")"
[[ "$(call_count "$S1" 'force-recreate redis')" -eq 0 ]] && ok "Redis wurde NICHT recreated (unveraendert)" || bad "Redis wurde unerwartet recreated: $(calls "$S1")"
[[ "$(call_count "$S1" 'bin/migrate.php')" -ge 1 ]] && ok "Migration wurde ausgefuehrt" || bad "Migration wurde NICHT ausgefuehrt"
[[ "$(call_count "$S1" 'up -d --remove-orphans')" -ge 1 ]] && ok "Cutover wurde ausgefuehrt" || bad "Cutover wurde NICHT ausgefuehrt"
[[ "$(call_count "$S1" 'stop --signal')" -eq 0 ]] && ok "Kein Vorab-Stopp: laufende Container tragen bereits die neue Stop-Konfiguration (SIGTERM/75 s)" || bad "Unerwarteter Vorab-Stopp: $(calls "$S1" | grep 'stop --signal')"
rm -rf "$S1"

echo "2) redis.conf geaendert, neuer Redis healthy UND aus anderem Container erreichbar: Candidate folgt"
S2="$(new_sandbox)"
make_release "$S2" prevsha "protected-mode yes"
make_release "$S2" newsha "protected-mode no"
set_current "$S2" prevsha
make_fake_docker "$S2"
export FAKE_REDIS_HEALTHY=1
run_deploy "$S2" newsha; RC2=$?
unset FAKE_REDIS_HEALTHY
[[ "$RC2" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl (Exitcode $RC2): $(output "$S2")"
[[ "$(call_count "$S2" 'force-recreate redis')" -ge 1 ]] && ok "Redis wurde AUSSCHLIESSLICH wegen der geaenderten redis.conf recreated" || bad "Redis wurde nicht recreated, obwohl redis.conf sich geaendert hat"
if calls "$S2" | grep -q 'bin/healthcheck\.php --redis$'; then
    ok "Netzwerkbasierter Redis-Test aus einem anderen Container wurde ausgefuehrt (nicht per docker exec/Loopback)"
else
    bad "Netzwerkbasierter Redis-Test wurde nicht ausgefuehrt"
fi
[[ "$(call_count "$S2" 'bin/migrate.php')" -ge 1 ]] && ok "Migration wurde nach erfolgreicher Redis-Aktualisierung ausgefuehrt" || bad "Migration wurde NICHT ausgefuehrt"
[[ "$(call_count "$S2" 'up -d --remove-orphans')" -ge 1 ]] && ok "Cutover wurde ausgefuehrt" || bad "Cutover wurde NICHT ausgefuehrt"
rm -rf "$S2"

echo "3) Redis healthy, aber ueber das interne Netz aus anderem Container blockiert: Deployment stoppt, Rollback der Redis-Infrastruktur"
S3="$(new_sandbox)"
make_release "$S3" prevsha "protected-mode no"
make_release "$S3" newsha "protected-mode yes"
set_current "$S3" prevsha
make_fake_docker "$S3"
export FAKE_REDIS_HEALTHY=1
run_deploy "$S3" newsha; RC3=$?
unset FAKE_REDIS_HEALTHY
[[ "$RC3" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC3 != 0)" || bad "Deployment meldete faelschlich Erfolg"
[[ "$(call_count "$S3" 'force-recreate redis')" -ge 2 ]] && ok "Redis wurde recreated (Versuch) UND erneut (Rollback)" || bad "Redis wurde nicht (auch) fuer den Rollback recreated: $(calls "$S3")"
[[ "$(call_count "$S3" 'bin/migrate.php')" -eq 0 ]] && ok "Keine Migration vor erfolgreicher Redis-/Candidate-Pruefung" || bad "Migration wurde faelschlich ausgefuehrt"
[[ "$(call_count "$S3" 'up -d --remove-orphans')" -eq 0 ]] && ok "Kein Cutover vor erfolgreicher Pruefung" || bad "Cutover wurde faelschlich ausgefuehrt"
if output "$S3" | grep -q "TECHNISCH erfolgreich auf die vorherige Konfiguration zurueckgesetzt"; then
    ok "Redis-Infrastruktur TECHNISCH erfolgreich auf die vorherige Konfiguration zurueckgesetzt (bestaetigt)"
else
    bad "Keine Bestaetigung des technisch erfolgreichen Redis-Rollbacks im Protokoll: $(output "$S3")"
fi
if output "$S3" | grep -q "mit der vorherigen Konfiguration aus einem anderen Container erreichbar" && ! output "$S3" | grep -q "::warning::"; then
    ok "Alte (funktionierende) Konfiguration ist nach dem Rollback aus einem anderen Container erreichbar, keine Warnung"
else
    bad "Erreichbarkeit nach dem Rollback nicht bestaetigt oder unerwartete Warnung: $(output "$S3" | grep -E 'erreichbar|warning')"
fi
if output "$S3" | grep -q "DIAGNOSE redis:"; then
    ok "DIAGNOSE-Zeile des fehlgeschlagenen Netzwerktests steht im Protokoll"
else
    bad "Keine DIAGNOSE-Zeile im Protokoll"
fi
if grep -qx 'protected-mode no' "$S3/deploy/redis/redis.conf" 2>/dev/null; then
    ok "redis.conf im Deploy-Ordner wieder auf die alte (funktionierende) Fassung zurueckgesetzt"
else
    bad "redis.conf wurde nicht auf die alte Fassung zurueckgesetzt"
fi
rm -rf "$S3"

echo "4) Ungueltige neue redis.conf: bereits die Vorab-Validierung schlaegt fehl, alter Redis bleibt unberuehrt"
S4="$(new_sandbox)"
make_release "$S4" prevsha "protected-mode no"
make_release "$S4" newsha "protected-mode no
bogus-directive-die-es-nicht-gibt zzz"
set_current "$S4" prevsha
make_fake_docker "$S4"
export FAKE_VALIDATE_MODE=bad
run_deploy "$S4" newsha; RC4=$?
unset FAKE_VALIDATE_MODE
[[ "$RC4" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC4 != 0)" || bad "Deployment meldete faelschlich Erfolg trotz ungueltiger redis.conf"
[[ "$(call_count "$S4" 'force-recreate redis')" -eq 1 ]] && ok "Der laufende Redis-Dienst wurde NICHT mit der ungueltigen Konfiguration angefasst (Recreate nur einmal, aus dem Rollback-Pfad)" || bad "Unerwartete Anzahl force-recreate-Aufrufe: $(calls "$S4")"
[[ "$(call_count "$S4" 'bin/migrate.php')" -eq 0 ]] && ok "Keine Migration nach fehlgeschlagener Validierung" || bad "Migration wurde faelschlich ausgefuehrt"
[[ "$(call_count "$S4" 'up -d --remove-orphans')" -eq 0 ]] && ok "Kein Cutover nach fehlgeschlagener Validierung" || bad "Cutover wurde faelschlich ausgefuehrt"
output "$S4" | grep -qi "ungueltig" && ok "Fehlermeldung nennt die ungueltige redis.conf" || bad "Keine erkennbare Fehlermeldung zur ungueltigen redis.conf"
rm -rf "$S4"

echo "5) Redis verletzt die Netzwerk-Isolationsvorgaben (hier: veroeffentlichter Host-Port): Abbruch vor jeder Aenderung"
S5="$(new_sandbox)"
make_release "$S5" prevsha "protected-mode no"
make_release "$S5" newsha "protected-mode no"
set_current "$S5" prevsha
make_fake_docker "$S5"
export FAKE_ISOLATION_BAD=1
run_deploy "$S5" newsha; RC5=$?
unset FAKE_ISOLATION_BAD
[[ "$RC5" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC5 != 0)" || bad "Deployment meldete faelschlich Erfolg trotz Isolationsverletzung"
[[ "$(call_count "$S5" 'force-recreate redis')" -eq 0 ]] && ok "Redis wurde vor der Isolationspruefung nicht angefasst" || bad "Redis wurde trotz Isolationsverletzung angefasst"
[[ "$(call_count "$S5" 'run --rm --entrypoint redis-server')" -eq 0 ]] && ok "Keine Validierung nach fehlgeschlagener Isolationspruefung" || bad "Validierung lief trotz Isolationsverletzung"
[[ "$(call_count "$S5" 'bin/migrate.php')" -eq 0 ]] && ok "Keine Migration nach fehlgeschlagener Isolationspruefung" || bad "Migration wurde faelschlich ausgefuehrt"
[[ "$(call_count "$S5" 'up -d --remove-orphans')" -eq 0 ]] && ok "Kein Cutover nach fehlgeschlagener Isolationspruefung" || bad "Cutover wurde faelschlich ausgefuehrt"
output "$S5" | grep -qi "Isolationsvorgaben\|veroeffentlichten Host-Port" && ok "Fehlermeldung nennt die Isolationsverletzung" || bad "Keine erkennbare Fehlermeldung zur Isolationsverletzung"
rm -rf "$S5"

echo "6) Wiederholung eines erfolgreichen Deployments bleibt idempotent"
S6="$(new_sandbox)"
make_release "$S6" prevsha "protected-mode yes"
make_release "$S6" newsha "protected-mode no"
set_current "$S6" prevsha
make_fake_docker "$S6"
run_deploy "$S6" newsha; RC6A=$?
[[ "$RC6A" -eq 0 ]] && ok "Erster Lauf erfolgreich" || bad "Erster Lauf schlug fehl: $(output "$S6")"
run_deploy "$S6" newsha; RC6B=$?
[[ "$RC6B" -eq 0 ]] && ok "Zweiter, wiederholter Lauf ebenfalls erfolgreich (idempotent)" || bad "Zweiter Lauf schlug fehl: $(output "$S6")"
rm -rf "$S6"

echo "7) Alias 'smarteinzug-redis' fehlt (z.B. Redis in anderem Compose-Projekt/Netz): Abbruch; Rollback auf bekannt alten, defekten Zustand ist TECHNISCH erfolgreich, fachlich nur Warnung"
S7="$(new_sandbox)"
make_release "$S7" prevsha "protected-mode yes"
make_release "$S7" newsha "protected-mode no"
set_current "$S7" prevsha
make_fake_docker "$S7"
export FAKE_ALIAS_MISSING=1
run_deploy "$S7" newsha; RC7=$?
unset FAKE_ALIAS_MISSING
[[ "$RC7" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC7 != 0)" || bad "Deployment meldete faelschlich Erfolg trotz fehlendem Alias"
output "$S7" | grep -q "kategorie=alias_missing" && ok "DIAGNOSE nennt die Ursache: kategorie=alias_missing (Stufe resolve)" || bad "Keine alias_missing-Diagnose im Protokoll: $(output "$S7" | grep DIAGNOSE)"
[[ "$(call_count "$S7" 'force-recreate redis')" -eq 2 ]] && ok "Redis recreated (Versuch) und erneut recreated (Rollback)" || bad "Unerwartete Anzahl force-recreate-Aufrufe: $(call_count "$S7" 'force-recreate redis')"
output "$S7" | grep -q "TECHNISCH erfolgreich auf die vorherige Konfiguration zurueckgesetzt" && ok "Rollback TECHNISCH erfolgreich bewertet (redis.conf zurueck, recreated, healthy)" || bad "Rollback nicht als technisch erfolgreich bewertet: $(output "$S7" | grep -i rollback)"
output "$S7" | grep -q "::warning:: Redis ist mit der vorherigen Konfiguration aus einem anderen Container weiterhin NICHT nutzbar" && output "$S7" | grep -q "Rollback selbst ist technisch erfolgreich" && ok "Bekannter Altzustand nach dem Rollback nur als WARNUNG gemeldet, nicht als Fehlschlag der Wiederherstellung" || bad "Bekannter Altzustand wurde nicht als Warnung gemeldet"
output "$S7" | grep -q "TECHNISCH fehlgeschlagen" && bad "Rollback wurde faelschlich als technisch fehlgeschlagen gemeldet" || ok "Kein 'TECHNISCH fehlgeschlagen' im Protokoll"
grep -qx 'protected-mode yes' "$S7/deploy/redis/redis.conf" && ok "redis.conf im Deploy-Ordner auf die alte Fassung (protected-mode yes) zurueckgesetzt" || bad "redis.conf nicht zurueckgesetzt"
[[ "$(call_count "$S7" 'bin/migrate.php')" -eq 0 ]] && ok "Keine Migration" || bad "Migration wurde faelschlich ausgefuehrt"
[[ "$(call_count "$S7" 'up -d --remove-orphans')" -eq 0 ]] && ok "Kein Cutover" || bad "Cutover wurde faelschlich ausgefuehrt"
rm -rf "$S7"

echo "8) Netzwerktest laeuft mit dem NEUEN Release-Code, im selben Compose-Projekt wie alle anderen Aufrufe, immer mit --no-deps"
S8="$(new_sandbox)"
make_release "$S8" prevsha "protected-mode yes"
make_release "$S8" newsha "protected-mode no"
set_current "$S8" prevsha
make_fake_docker "$S8"
run_deploy "$S8" newsha; RC8=$?
[[ "$RC8" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl (Exitcode $RC8): $(output "$S8")"
PROBE_LINE="$(calls "$S8" | grep 'bin/healthcheck\.php --redis$' | head -n1)"
if [[ "$PROBE_LINE" == RELEASE_SHA=newsha* ]]; then
    ok "Netzwerktest lief mit dem neuen Release (RELEASE_SHA=newsha), nicht mit dem alten Code"
else
    bad "Netzwerktest lief nicht mit dem neuen Release: ${PROBE_LINE:-<kein Aufruf>}"
fi
PROJECTS="$(calls "$S8" | grep -o 'compose -f [^ ]* -f [^ ]* --env-file [^ ]*' | sort -u)"
if [[ "$(printf '%s\n' "$PROJECTS" | grep -c .)" -eq 1 && "$PROJECTS" == *"docker-compose.prod.yml"* ]]; then
    ok "Alle Compose-Aufrufe (Recreate, Netzwerktest, Candidate, Migration, Cutover) treffen dasselbe Projekt: $PROJECTS"
else
    bad "Compose-Aufrufe treffen unterschiedliche Projekte/Dateien: $PROJECTS"
fi
RUNS_WITHOUT_NODEPS="$(calls "$S8" | grep ' run --rm ' | grep -v -- '--no-deps' | grep -vc 'run --rm --entrypoint redis-server' || true)"
[[ "$RUNS_WITHOUT_NODEPS" -eq 0 ]] && ok "Jeder 'docker compose run' (Netzwerktest, Candidate, Migration) verwendet --no-deps" || bad "$RUNS_WITHOUT_NODEPS Compose-run-Aufrufe ohne --no-deps"
[[ "$(call_count "$S8" 'force-recreate redis')" -eq 1 ]] && ok "Genau ein Redis-Recreate (kein Rollback noetig)" || bad "Unerwartete Anzahl force-recreate-Aufrufe"
[[ "$(call_count "$S8" 'restart -t')" -eq 0 ]] && ok "Kein zweiter Worker-Neustart (restart -t) nach dem Cutover" || bad "Es wurde noch ein 'restart -t' ausgefuehrt"
[[ "$(call_count "$S8" 'Config.WorkingDir')" -ge 7 ]] && ok "Release-Bindung aller PHP-Container per docker inspect verifiziert ($(call_count "$S8" 'Config.WorkingDir') Abfragen)" || bad "Release-Bindung wurde nicht verifiziert"
output "$S8" | grep -q "worker-stripe: $S8/releases/newsha (running)" && ok "Worker laufen nach dem Cutover nachweislich mit dem neuen Release (working_dir)" || bad "working_dir der Worker nicht wie erwartet: $(output "$S8" | grep 'worker-stripe')"
# Reihenfolge: Recreate -> Netzwerktest -> Candidate -> Migration -> Cutover (Zeilennummern im Aufrufprotokoll)
L_RECREATE="$(calls "$S8" | grep -n 'force-recreate redis' | head -n1 | cut -d: -f1)"
L_PROBE="$(calls "$S8" | grep -n 'bin/healthcheck\.php --redis$' | head -n1 | cut -d: -f1)"
L_CAND="$(calls "$S8" | grep -n 'bin/healthcheck\.php --db --redis --expect-env=' | head -n1 | cut -d: -f1)"
L_MIG="$(calls "$S8" | grep -n 'bin/migrate\.php' | head -n1 | cut -d: -f1)"
L_CUT="$(calls "$S8" | grep -n 'up -d --remove-orphans' | head -n1 | cut -d: -f1)"
if [[ -n "$L_RECREATE" && -n "$L_PROBE" && -n "$L_CAND" && -n "$L_MIG" && -n "$L_CUT" && "$L_RECREATE" -lt "$L_PROBE" && "$L_PROBE" -lt "$L_CAND" && "$L_CAND" -lt "$L_MIG" && "$L_MIG" -lt "$L_CUT" ]]; then
    ok "Reihenfolge eingehalten: Redis-Recreate ($L_RECREATE) -> Netzwerktest ($L_PROBE) -> Candidate ($L_CAND) -> Migration ($L_MIG) -> Cutover ($L_CUT)"
else
    bad "Reihenfolge verletzt: recreate=$L_RECREATE probe=$L_PROBE candidate=$L_CAND migrate=$L_MIG cutover=$L_CUT"
fi
rm -rf "$S8"

echo "9) Harte Stufe: auch das Recreate im Rollback schlaegt fehl -> TECHNISCH fehlgeschlagen, Abbruch, keine Migration/kein Cutover"
S9="$(new_sandbox)"
make_release "$S9" prevsha "protected-mode no"
make_release "$S9" newsha "protected-mode yes"
set_current "$S9" prevsha
make_fake_docker "$S9"
export FAKE_RECREATE_FAIL_ON=2
run_deploy "$S9" newsha; RC9=$?
unset FAKE_RECREATE_FAIL_ON
[[ "$RC9" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC9 != 0)" || bad "Deployment meldete faelschlich Erfolg"
output "$S9" | grep -q "TECHNISCH fehlgeschlagen" && ok "Rollback als TECHNISCH fehlgeschlagen gemeldet (harte Stufe)" || bad "Harte Stufe nicht gemeldet: $(output "$S9" | grep -i rollback)"
output "$S9" | grep -q "TECHNISCH erfolgreich" && bad "Rollback faelschlich als technisch erfolgreich gemeldet" || ok "Kein falsches 'TECHNISCH erfolgreich'"
[[ "$(call_count "$S9" 'bin/migrate.php')" -eq 0 && "$(call_count "$S9" 'up -d --remove-orphans')" -eq 0 ]] && ok "Keine Migration, kein Cutover" || bad "Migration/Cutover trotz Fehlschlag"
rm -rf "$S9"

echo "10) Compose-Definition von redis geaendert (Hash), redis.conf unveraendert -> redis wird VOR der Candidate-Pruefung neu erzeugt"
S10="$(new_sandbox)"
make_release "$S10" prevsha "protected-mode no"
make_release "$S10" newsha "protected-mode no"
set_current "$S10" prevsha
make_fake_docker "$S10"
export FAKE_REDIS_HASH=h-neuer-alias
run_deploy "$S10" newsha; RC10=$?
unset FAKE_REDIS_HASH
[[ "$RC10" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl: $(output "$S10")"
output "$S10" | grep -q "Compose-Dienstdefinition geaendert (Konfigurations-Hash laufend=h-default neu=h-neuer-alias)" && ok "Hash-Abweichung erkannt und protokolliert" || bad "Hash-Abweichung nicht erkannt: $(output "$S10" | grep -i hash)"
[[ "$(call_count "$S10" 'force-recreate redis')" -eq 1 ]] && ok "redis wurde trotz unveraenderter redis.conf einmal kontrolliert neu erzeugt" || bad "redis wurde nicht neu erzeugt (Anzahl: $(call_count "$S10" 'force-recreate redis'))"
L_RC="$(calls "$S10" | grep -n 'force-recreate redis' | head -n1 | cut -d: -f1)"; L_CD="$(calls "$S10" | grep -n 'bin/healthcheck\.php --db --redis --expect-env=' | head -n1 | cut -d: -f1)"
[[ -n "$L_RC" && -n "$L_CD" && "$L_RC" -lt "$L_CD" ]] && ok "Recreate ($L_RC) lag vor der Candidate-Pruefung ($L_CD)" || bad "Reihenfolge verletzt: recreate=$L_RC candidate=$L_CD"
rm -rf "$S10"

echo "11) Redis wird nach dem Recreate nie healthy: Zeitueberschreitung, Rollback-Pfad, keine Migration, kein Cutover"
S11="$(new_sandbox)"
make_release "$S11" prevsha "protected-mode yes"
make_release "$S11" newsha "protected-mode no"
set_current "$S11" prevsha
make_fake_docker "$S11"
export FAKE_REDIS_HEALTHY=0 REDIS_WAIT_HEALTHY_SECONDS=2
run_deploy "$S11" newsha; RC11=$?
unset FAKE_REDIS_HEALTHY REDIS_WAIT_HEALTHY_SECONDS
[[ "$RC11" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC11 != 0)" || bad "Deployment meldete faelschlich Erfolg, obwohl Redis nie healthy wurde"
output "$S11" | grep -q "Zeitueberschreitung beim Warten auf gesundes Redis: fake-redis-1=starting" && ok "Zeitueberschreitung mit Zustand des Containers gemeldet" || bad "Keine Zeitueberschreitungsmeldung: $(output "$S11" | grep -i redis | head -5)"
output "$S11" | grep -q "(fake redis logs)" && ok "Redis-Protokoll (logs redis --tail) im Deploy-Protokoll" || bad "Redis-Protokoll fehlt"
[[ "$(call_count "$S11" 'force-recreate redis')" -eq 2 ]] && ok "Recreate (Versuch) und Recreate (Rollback)" || bad "Unerwartete Anzahl force-recreate-Aufrufe: $(call_count "$S11" 'force-recreate redis')"
output "$S11" | grep -q "TECHNISCH fehlgeschlagen" && ok "Rollback als TECHNISCH fehlgeschlagen gemeldet (auch die alte Konfiguration wurde nicht healthy)" || bad "Harte Stufe nicht gemeldet"
[[ "$(call_count "$S11" 'bin/migrate.php')" -eq 0 && "$(call_count "$S11" 'up -d --remove-orphans')" -eq 0 ]] && ok "Keine Migration, kein Cutover" || bad "Migration/Cutover trotz ungesundem Redis"
rm -rf "$S11"

echo "12) 'ps -a redis' liefert KEINE Zeile (kein Container): gilt nicht als gesund"
S12="$(new_sandbox)"
make_release "$S12" prevsha "protected-mode yes"
make_release "$S12" newsha "protected-mode no"
set_current "$S12" prevsha
make_fake_docker "$S12"
export FAKE_REDIS_PS_EMPTY=1 REDIS_WAIT_HEALTHY_SECONDS=2
run_deploy "$S12" newsha; RC12=$?
unset FAKE_REDIS_PS_EMPTY REDIS_WAIT_HEALTHY_SECONDS
[[ "$RC12" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC12 != 0)" || bad "Leere ps-Ausgabe wurde faelschlich als gesund gewertet"
output "$S12" | grep -q "Zeitueberschreitung beim Warten auf gesundes Redis: kein-redis-container" && ok "Leere Ausgabe als 'kein-redis-container' gemeldet" || bad "Fehlender Container nicht erkannt: $(output "$S12" | grep -i redis | head -5)"
[[ "$(call_count "$S12" 'bin/migrate.php')" -eq 0 && "$(call_count "$S12" 'up -d --remove-orphans')" -eq 0 ]] && ok "Keine Migration, kein Cutover" || bad "Migration/Cutover trotz fehlendem Redis-Container"
rm -rf "$S12"

echo "13) Laufende Hintergrund-Container mit ALTER Stop-Konfiguration (SIGQUIT/660 s): Vorab-Stopp mit SIGTERM vor dem Cutover"
S13="$(new_sandbox)"
make_release "$S13" prevsha "protected-mode no"
make_release "$S13" newsha "protected-mode no"
make_release "$S13" newsha2 "protected-mode no"
set_current "$S13" prevsha
set_legacy_stop_config "$S13"
make_fake_docker "$S13"
run_deploy "$S13" newsha; RC13=$?
[[ "$RC13" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl (Exitcode $RC13): $(output "$S13")"
STOPLINE="$(calls "$S13" | grep 'stop --signal SIGTERM --timeout 90' | head -n1)"
[[ -n "$STOPLINE" ]] && ok "docker stop --signal SIGTERM --timeout 90 wurde aufgerufen" || bad "Kein Vorab-Stopp trotz veralteter Stop-Konfiguration: $(calls "$S13" | grep -i stop)"
MISSING13=""
for svc in scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics; do
    [[ "$STOPLINE" == *"cid-$svc"* ]] || MISSING13="$MISSING13 $svc"
done
[[ -z "$MISSING13" ]] && ok "Alle 7 Hintergrund-Container (Scheduler, 5 Worker, Metrik-Sammler) im Vorab-Stopp enthalten" || bad "Im Vorab-Stopp fehlen:$MISSING13"
[[ "$STOPLINE" != *"cid-php"* && "$STOPLINE" != *"cid-redis"* ]] && ok "php-fpm und redis NICHT vorab gestoppt" || bad "php/redis wurden faelschlich gestoppt: $STOPLINE"
output "$S13" | grep -q "veraltete Stop-Konfiguration (Signal SIGQUIT, Frist 660 s)" && ok "Protokoll nennt Signal und Frist der veralteten Konfiguration" || bad "Keine Meldung zur veralteten Konfiguration"
L_STOP="$(calls "$S13" | grep -n 'stop --signal' | head -n1 | cut -d: -f1)"
L_MIG13="$(calls "$S13" | grep -n 'bin/migrate\.php' | head -n1 | cut -d: -f1)"
L_CUT13="$(calls "$S13" | grep -n 'up -d --remove-orphans' | head -n1 | cut -d: -f1)"
[[ -n "$L_STOP" && -n "$L_MIG13" && -n "$L_CUT13" && "$L_MIG13" -lt "$L_STOP" && "$L_STOP" -lt "$L_CUT13" ]] && ok "Reihenfolge: Migration ($L_MIG13) -> Vorab-Stopp ($L_STOP) -> Cutover ($L_CUT13)" || bad "Reihenfolge verletzt: migrate=$L_MIG13 stop=$L_STOP cutover=$L_CUT13"
[[ "$(call_count "$S13" 'restart -t')" -eq 0 ]] && ok "Kein 'restart -t' (der Vorab-Stopp ist kein zweiter Neustart)" || bad "restart -t aufgerufen"
run_deploy "$S13" newsha2; RC13B=$?
[[ "$RC13B" -eq 0 && "$(call_count "$S13" 'stop --signal')" -eq 1 ]] && ok "Folgedeployment (Container tragen jetzt SIGTERM/75 s): kein weiterer Vorab-Stopp (idempotent)" || bad "Folgedeployment: Exit $RC13B, Vorab-Stopps insgesamt $(call_count "$S13" 'stop --signal')"
rm -rf "$S13"

echo "14) Nur Compose-Definition geaendert, Netzwerktest scheitert: ehrliche Rueckbau-Meldung (redis.conf unveraendert, Definition nicht zurueckgebaut)"
S14="$(new_sandbox)"
make_release "$S14" prevsha "protected-mode no"
make_release "$S14" newsha "protected-mode no"
set_current "$S14" prevsha
make_fake_docker "$S14"
export FAKE_REDIS_HASH=h-neuer-alias FAKE_ALIAS_MISSING=1
run_deploy "$S14" newsha; RC14=$?
unset FAKE_REDIS_HASH FAKE_ALIAS_MISSING
[[ "$RC14" -ne 0 ]] && ok "Deployment abgebrochen (Exitcode $RC14 != 0)" || bad "Deployment meldete faelschlich Erfolg"
output "$S14" | grep -q "HINWEIS: redis.conf war unveraendert; die geaenderte Compose-Definition von redis kann dieses Skript nicht zurueckbauen" && ok "Ehrliche Meldung: Definition nicht zurueckgebaut" || bad "Irrefuehrende oder fehlende Rueckbau-Meldung: $(output "$S14" | grep -i 'redis' | tail -5)"
output "$S14" | grep -q "rollback.sh prevsha" && ok "Hinweis auf rollback.sh mit dem vorherigen Release" || bad "Kein rollback.sh-Hinweis"
output "$S14" | grep -q "TECHNISCH erfolgreich auf die vorherige Konfiguration zurueckgesetzt" && bad "Falsches 'auf die vorherige Konfiguration zurueckgesetzt' trotz unveraenderter redis.conf" || ok "Kein falsches 'auf die vorherige Konfiguration zurueckgesetzt'"
[[ "$(call_count "$S14" 'bin/migrate.php')" -eq 0 && "$(call_count "$S14" 'up -d --remove-orphans')" -eq 0 ]] && ok "Keine Migration, kein Cutover" || bad "Migration/Cutover trotz Fehlschlag"
rm -rf "$S14"

echo "15) Docker-CLI ohne 'stop --signal': Ausweichweg ueber 'kill --signal SIGTERM' plus Warten"
S15="$(new_sandbox)"
make_release "$S15" prevsha "protected-mode no"
make_release "$S15" newsha "protected-mode no"
set_current "$S15" prevsha
set_legacy_stop_config "$S15"
make_fake_docker "$S15"
export FAKE_STOP_SIGNAL_UNSUPPORTED=1
run_deploy "$S15" newsha; RC15=$?
unset FAKE_STOP_SIGNAL_UNSUPPORTED
[[ "$RC15" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl (Exitcode $RC15): $(output "$S15")"
output "$S15" | grep -q "kennt 'stop --signal' nicht" && ok "Fehlende Faehigkeit erkannt und im Protokoll benannt" || bad "Keine Meldung zur fehlenden Faehigkeit: $(output "$S15" | grep -i signal)"
[[ "$(call_count "$S15" 'stop --signal')" -eq 0 ]] && ok "Kein Aufruf von 'stop --signal' (waere mit dieser CLI ein Fehler)" || bad "'stop --signal' trotzdem aufgerufen"
KILLLINE="$(calls "$S15" | grep 'kill --signal SIGTERM' | head -n1)"
[[ -n "$KILLLINE" ]] && ok "Ausweichweg 'kill --signal SIGTERM' aufgerufen" || bad "Kein kill --signal: $(calls "$S15" | grep -i kill)"
MISSING15=""
for svc in scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics; do
    [[ "$KILLLINE" == *"cid-$svc"* ]] || MISSING15="$MISSING15 $svc"
done
[[ -z "$MISSING15" ]] && ok "Alle 7 Hintergrund-Container im Ausweichweg enthalten" || bad "Im Ausweichweg fehlen:$MISSING15"
[[ "$KILLLINE" != *"cid-php"* && "$KILLLINE" != *"cid-redis"* ]] && ok "php-fpm und redis nicht betroffen" || bad "php/redis faelschlich beendet: $KILLLINE"
output "$S15" | grep -q "haben sich selbst beendet" && ok "Warten bestaetigt das Ende der Container" || bad "Kein Nachweis des Endes: $(output "$S15" | grep -i beendet | tail -3)"
# Nur Warnungen ZUM VORAB-STOPP sind hier ein Fehler; die HTTPS-Warnung ist in der Sandbox ohne Zertifikat
# erwartbar (HEALTH_STRICT=false).
output "$S15" | grep '::warning::' | grep -qiE 'Vorab-Stopp|endeten innerhalb' && bad "Unerwartete Warnung zum Vorab-Stopp: $(output "$S15" | grep '::warning::' | grep -iE 'Vorab-Stopp|endeten innerhalb')" || ok "Keine Warnung zum Vorab-Stopp (Ausweichweg funktionierte sauber)"
[[ "$(call_count "$S15" 'up -d --remove-orphans')" -ge 1 ]] && ok "Cutover wurde ausgefuehrt" || bad "Cutover fehlt"
rm -rf "$S15"

echo "16) Ausweichweg, Container enden nicht in der Frist: Warnung, dann regulaerer Stopp mit kurzer Frist"
S16="$(new_sandbox)"
make_release "$S16" prevsha "protected-mode no"
make_release "$S16" newsha "protected-mode no"
set_current "$S16" prevsha
set_legacy_stop_config "$S16"
make_fake_docker "$S16"
export FAKE_STOP_SIGNAL_UNSUPPORTED=1 FAKE_LEGACY_HANGS=1 LEGACY_STOP_WAIT_SECONDS=2
run_deploy "$S16" newsha; RC16=$?
unset FAKE_STOP_SIGNAL_UNSUPPORTED FAKE_LEGACY_HANGS LEGACY_STOP_WAIT_SECONDS
[[ "$RC16" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0), kein Warten auf die alten 660 s" || bad "Deployment schlug fehl (Exitcode $RC16): $(output "$S16")"
output "$S16" | grep -q "::warning:: Nicht alle Container endeten innerhalb von 2 s nach SIGTERM" && ok "Warnung mit der tatsaechlichen Frist gemeldet" || bad "Keine Warnung zur Zeitueberschreitung: $(output "$S16" | grep '::warning::')"
[[ "$(call_count "$S16" 'stop --time 5')" -ge 1 ]] && ok "Regulaerer Stopp mit kurzer Frist (stop --time 5) als letzte Stufe" || bad "Kein 'stop --time 5': $(calls "$S16" | grep -i stop)"
[[ "$(call_count "$S16" 'up -d --remove-orphans')" -ge 1 ]] && ok "Cutover wurde ausgefuehrt" || bad "Cutover fehlt"
rm -rf "$S16"

echo "17) Statusseite: gemeinsamer Ordner wird angelegt, Platzhalter einmalig kopiert, danach unberuehrt"
S17="$(new_sandbox)"
make_release "$S17" prevsha "protected-mode no"
make_release "$S17" newsha "protected-mode no"
make_release "$S17" newsha2 "protected-mode no"
set_current "$S17" prevsha
make_fake_docker "$S17"
run_deploy "$S17" newsha; RC17=$?
[[ "$RC17" -eq 0 ]] && ok "Deployment erfolgreich (Exitcode 0)" || bad "Deployment schlug fehl: $(output "$S17")"
[[ -d "$S17/shared/status" ]] && ok "Ordner shared/status angelegt" || bad "shared/status fehlt"
[[ -f "$S17/shared/status/status.json" ]] && ok "Platzhalter nach shared/status kopiert (Caddy kann /status.json ausliefern)" || bad "shared/status/status.json fehlt"
output "$S17" | grep -q "Platzhalter fuer die Statusseite" && ok "Kopie im Protokoll vermerkt" || bad "Kein Protokolleintrag zur Kopie"
# Veroeffentlichte Daten der Anwendung duerfen ein Folgedeployment NICHT verlieren.
printf '{"schema":1,"overall":{"state":"ok"},"generated_at":"2026-09-07T10:00:00Z"}\n' > "$S17/shared/status/status.json"
run_deploy "$S17" newsha2; RC17B=$?
[[ "$RC17B" -eq 0 ]] && ok "Folgedeployment erfolgreich" || bad "Folgedeployment schlug fehl: $(output "$S17")"
grep -q '"state":"ok"' "$S17/shared/status/status.json" && ok "veroeffentlichte Statusdaten ueberlebten das Deployment (Platzhalter nicht erneut kopiert)" || bad "Statusdaten wurden ueberschrieben: $(cat "$S17/shared/status/status.json")"
rm -rf "$S17"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
