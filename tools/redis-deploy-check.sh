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
# Geprueft wird:
#   1. redis.conf unveraendert gegenueber dem laufenden Release -> Redis wird NICHT recreated; Candidate,
#      Migration und Cutover laufen normal durch.
#   2. redis.conf geaendert -> AUSSCHLIESSLICH Redis wird vor der Candidate-Pruefung aktualisiert; danach
#      erreicht der neue Redis-Dienst "healthy" UND ist aus einem anderen Container ueber das interne
#      Netz erreichbar -> Candidate, Migration und Cutover laufen normal durch.
#   3. Redis wird zwar "healthy" (Docker-Healthcheck, entspricht Loopback-Ping), ist aber ueber das
#      interne Netz aus einem anderen Container NICHT erreichbar (genau der urspruengliche
#      protected-mode-Fehler) -> Deployment stoppt, die Redis-Infrastruktur wird auf die vorherige
#      Konfiguration zurueckgesetzt und deren Erreichbarkeit erneut bestaetigt; KEINE Migration, KEIN
#      Cutover.
#   4. Eine ungueltige neue redis.conf wird bereits in der Vorab-Validierung erkannt, BEVOR der laufende
#      Redis-Dienst ueberhaupt angefasst wird; die alte Konfiguration wird (idempotent) wiederhergestellt.
#   5. Verletzt Redis die Netzwerk-Isolationsvorgaben (hier simuliert: veroeffentlichter Host-Port), bricht
#      das Deployment ab, BEVOR irgendetwas an Redis geaendert wird.
#   6. In keinem Fehlerfall (3, 4, 5) wird jemals bin/migrate.php oder der Cutover ("up -d
#      --remove-orphans") aufgerufen.
#   7. Eine Wiederholung desselben (bereits erfolgreichen) Deployments bleibt idempotent (zweiter Lauf
#      ebenfalls erfolgreich, keine Fehlerhaeufung).
#
# Aufruf: bash tools/redis-deploy-check.sh        Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_SH_SRC="$ROOT/deploy/vps/scripts/deploy.sh"

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

new_sandbox() {
    local dir
    dir="$(mktemp -d)"
    install -d -m 750 "$dir/deploy" "$dir/logs"
    cat > "$dir/deploy/.env" <<'ENV'
DEPLOY_ENV=prod
HEALTH_STRICT=false
DOMAIN_APP=test.invalid
ENV
    printf '%s' "$dir"
}

# Legt ein Release "$sha" mit minimalem deploy/vps-Baum an; $3 = voller Inhalt von redis.conf.
make_release() {
    local sandbox="$1" sha="$2" redis_conf_content="$3"
    local rel="$sandbox/releases/$sha/deploy/vps"
    install -d -m 750 "$rel/scripts" "$rel/php" "$rel/redis"
    printf 'FROM scratch\n' > "$rel/php/Dockerfile"
    printf '%s\n' "$redis_conf_content" > "$rel/redis/redis.conf"
    : > "$rel/docker-compose.yml"
    : > "$rel/docker-compose.prod.yml"
    : > "$rel/docker-compose.staging.yml"
    printf '#!/usr/bin/env bash\nexit 0\n' > "$rel/scripts/rollback.sh"
    chmod +x "$rel/scripts/rollback.sh"
    cp "$DEPLOY_SH_SRC" "$rel/scripts/deploy.sh"
    sed -i "s#^BASE=/opt/smarteinzug\$#BASE=$sandbox#" "$rel/scripts/deploy.sh"
    grep -q "^BASE=$sandbox\$" "$rel/scripts/deploy.sh" || { echo "::error:: BASE-Ersetzung im deploy.sh fehlgeschlagen"; exit 2; }
    # NEEDS_BUILD=0 erzwingen (identischer php/-Inhalt in jedem Release dieses Tests): kein echter
    # Image-Build noetig, die Pruefsumme wird vorab passend hinterlegt.
    local hash
    hash="$(find "$rel/php" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"
    echo "$hash" > "$sandbox/deploy/.php-image.sha256"
}

set_current() {
    local sandbox="$1" sha="$2"
    ln -sfn "$sandbox/releases/$sha" "$sandbox/releases/current"
}

make_fake_docker() {
    local sandbox="$1"
    install -d -m 750 "$sandbox/fakebin"
    cat > "$sandbox/fakebin/docker" <<'FAKE'
#!/usr/bin/env bash
# Fake "docker" fuer tools/redis-deploy-check.sh: bildet nur die von deploy.sh tatsaechlich verwendeten
# Unterbefehle nach, ohne echten Docker-Daemon. Der Netzwerktest ("bin/healthcheck.php --redis") wertet
# den TATSAECHLICHEN, gerade aktiven Inhalt von redis.conf aus (echtes Verhalten nachgebildet, kein
# reiner Formalwert).
set -uo pipefail
ARGS="$*"
# Jede Zeile traegt das RELEASE_SHA aus der Umgebung des Aufrufs: so laesst sich pruefen, mit welchem
# Release-Code ein "docker compose run" (Netzwerktest, Candidate) tatsaechlich lief.
echo "RELEASE_SHA=${RELEASE_SHA:-unset} $ARGS" >> "${FAKE_CALL_LOG:?FAKE_CALL_LOG nicht gesetzt}"

redis_conf_ok() {
    grep -qx 'protected-mode no' "${FAKE_DEPLOY_DIR:?FAKE_DEPLOY_DIR nicht gesetzt}/redis/redis.conf" 2>/dev/null
}

# Netzwerktest aus einem anderen Container, stufenweise wie bin/healthcheck.php --redis: zuerst der
# DNS-Alias (FAKE_ALIAS_MISSING=1 simuliert einen fehlenden Alias, z.B. Redis in einem anderen
# Compose-Projekt/Netz), dann das Redis-Protokoll (haengt vom Inhalt der aktiven redis.conf ab).
redis_probe_result() {
    if [[ "${FAKE_ALIAS_MISSING:-0}" == "1" ]]; then
        echo 'DIAGNOSE redis: host=smarteinzug-redis port=6379 aufgeloest=keine erwartet=172.28.0.0/24 stufe=resolve kategorie=alias_missing meldung="(fake)"' >&2
        echo "UNGESUND: redis: alias_missing" >&2
        return 1
    fi
    if redis_conf_ok; then
        return 0
    fi
    echo 'DIAGNOSE redis: host=smarteinzug-redis port=6379 aufgeloest=172.28.0.5 erwartet=172.28.0.0/24 stufe=redis kategorie=redis_protected_mode meldung="DENIED (fake)"' >&2
    echo "UNGESUND: redis: redis_protected_mode" >&2
    return 1
}

case "$ARGS" in
    *"config redis --format json"*)
        if [[ "${FAKE_ISOLATION_BAD:-0}" == "1" ]]; then
            echo '{"services":{"redis":{"ports":[{"published":"6379"}],"networks":{"smarteinzug_internal":null},"labels":null}}}'
        else
            echo '{"services":{"redis":{"ports":null,"networks":{"smarteinzug_internal":null},"labels":null}}}'
        fi
        ;;
    *"config --images redis"*)
        echo "redis:7-alpine"
        ;;
    *"config --services"*)
        printf 'php\nscheduler\nworker-lexware-1\nworker-stripe\nworker-mail\nworker-maintenance\nmetrics\nredis\n'
        ;;
    *"up -d --no-deps --force-recreate redis"*)
        [[ "${FAKE_RECREATE_MODE:-ok}" == "fail" ]] && exit 1
        exit 0
        ;;
    *"ps redis --format"*)
        if [[ "${FAKE_REDIS_HEALTHY:-1}" == "1" ]]; then
            echo "fake-redis-1 healthy"
        else
            echo "fake-redis-1 starting"
        fi
        ;;
    *"logs redis --tail"*)
        echo "(fake redis logs)"
        ;;
    *"bin/healthcheck.php --redis")
        redis_probe_result; exit $?
        ;;
    *"bin/healthcheck.php --db --redis --expect-env="*)
        redis_probe_result; exit $?
        ;;
    *"bin/migrate.php"*)
        exit 0
        ;;
    *"up -d --remove-orphans"*)
        exit 0
        ;;
    *"ps -a --format"*)
        echo "fake-php-1 running healthy"
        ;;
    *"ps --format"*)
        echo "fake-php-1 healthy"
        ;;
    *"exec -T php kill -USR2 1"*)
        exit 0
        ;;
    *"restart -t 660"*)
        exit 0
        ;;
    *"bin/healthcheck.php --all"*)
        exit 0
        ;;
    *"build php"*)
        exit 0
        ;;
    *"logs --tail"*)
        echo "(fake logs)"
        ;;
    "run --rm --entrypoint redis-server"*)
        if [[ "${FAKE_VALIDATE_MODE:-ok}" == "bad" ]]; then
            echo "*** FATAL CONFIG FILE ERROR (fake) ***"
            exit 1
        fi
        exit 0
        ;;
    *)
        echo "FAKE-DOCKER: unhandled args: $ARGS" >&2
        exit 0
        ;;
esac
FAKE
    chmod +x "$sandbox/fakebin/docker"
}

# Fuehrt deploy.sh fuer "$sha" im Sandbox aus; gibt den Exitcode zurueck, Ausgabe/Aufrufe landen in
# "$sandbox/deploy-output.log" bzw. "$sandbox/fake-docker-calls.log" (wird NICHT zurueckgesetzt, damit
# ein zweiter Lauf im selben Sandbox - Idempotenz-Test - beide Laeufe protokolliert).
run_deploy() {
    # Kein "set -e" hier: dieses Skript verwendet ohnehin kein globales "-e" (nur "-uo pipefail"),
    # und ein "set -e" kurz vor "return" wuerde beim naechsten Aufruf mit Fehlschlag (Rueckgabewert
    # ungleich 0) das GESAMTE Testskript sofort beenden, da errexit dann fuer den Rest des Skripts
    # aktiv waere - genau der Fehler, der die ersten Fassungen dieses Tests nach Szenario 2 stumm
    # abbrechen liess.
    local sandbox="$1" sha="$2"
    FAKE_CALL_LOG="$sandbox/fake-docker-calls.log" \
    FAKE_DEPLOY_DIR="$sandbox/deploy" \
    PATH="$sandbox/fakebin:$PATH" \
        bash "$sandbox/releases/$sha/deploy/vps/scripts/deploy.sh" "$sha" > "$sandbox/deploy-output.log" 2>&1
    return $?
}

calls() { cat "$1/fake-docker-calls.log" 2>/dev/null; }
call_count() { calls "$1" | grep -c -- "$2" || true; }
output() { cat "$1/deploy-output.log" 2>/dev/null; }

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
output "$S7" | grep -q "::warning:: Redis ist mit der vorherigen Konfiguration aus einem anderen Container weiterhin NICHT nutzbar (bekannter Altzustand" && ok "Bekannter Altzustand nach dem Rollback nur als WARNUNG gemeldet, nicht als Fehlschlag der Wiederherstellung" || bad "Bekannter Altzustand wurde nicht als Warnung gemeldet"
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

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
