#!/usr/bin/env bash
#
# Gemeinsame Testumgebung fuer tools/redis-deploy-check.sh und tools/deploy-runner-check.sh: simuliert
# /opt/smarteinzug in einem temporaeren Ordner und ersetzt "docker" durch einen steuerbaren Fake, gegen
# den die ECHTEN Skripte deploy.sh / deploy-runner.sh / deploy-status.sh (nur BASE umgeschrieben) laufen.
# Kein Docker-Daemon noetig. Wird per "source" eingebunden; erwartet die Variablen ROOT (Repo-Wurzel),
# ok()/bad() fuer die Ergebnisausgabe.
#
# Der Fake-"docker" fuehrt einen kleinen Zustand (Ordner fake-state im Sandbox): welcher Dienst mit welchem
# RELEASE_SHA "laeuft" (working_dir), welcher Compose-Konfigurations-Hash am redis-Container haengt, wie oft
# redis neu erzeugt wurde. Damit lassen sich die realen Fragen von deploy.sh beantworten (ps -q, inspect,
# config --hash) und Szenarien wie "Compose-Definition von redis geaendert, redis.conf nicht" oder
# "Rollback-Recreate schlaegt fehl" nachstellen. Steuerung ueber Umgebungsvariablen (alle optional):
#   FAKE_ISOLATION_BAD=1          config redis --format json meldet einen veroeffentlichten Host-Port
#   FAKE_VALIDATE_MODE=bad        Vorab-Validierung der redis.conf (docker run redis-server) schlaegt fehl
#   FAKE_RECREATE_MODE=fail       jedes "up -d --no-deps --force-recreate redis" schlaegt fehl
#   FAKE_RECREATE_FAIL_ON=N       nur das N-te "force-recreate redis" dieses Sandbox schlaegt fehl (z.B. 2 = Rollback)
#   FAKE_REDIS_HEALTHY=0          "ps -a redis" meldet "starting" statt "healthy"
#   FAKE_REDIS_PS_EMPTY=1         "ps -a redis" liefert KEINE Zeile (kein Container vorhanden)
#   FAKE_STOP_SIGNAL_UNSUPPORTED=1  "docker stop --help" kennt kein --signal (Docker-CLI aelter als 23)
#   FAKE_LEGACY_HANGS=1           die vorab mit "kill --signal" beendeten Container laufen weiter
#   FAKE_STOP_MODE=fail           "docker stop" schlaegt fehl
#   FAKE_ALIAS_MISSING=1          Netzwerktest/Candidate melden alias_missing (Alias nicht im Netz)
#   set_legacy_stop_config <sb>   laufende Hintergrund-Container tragen die ALTE Stop-Konfiguration
#                                 (SIGQUIT, 660 s) wie beim ersten Deployment ab Version 4.11; der Fake
#                                 beantwortet "inspect ... StopSignal StopTimeout" und "stop --signal"
#   FAKE_REDIS_HASH=<wert>        Konfigurations-Hash der NEUEN redis-Definition (config --hash redis);
#                                 Standard "h-default"; der laufende Container traegt den beim letzten
#                                 "up" gespeicherten Hash (Vorbelegung: h-default)
#   FAKE_SLOW_STEP=<teilstring>   der erste Compose-Aufruf, der diesen Teilstring enthaelt, dauert
#                                 FAKE_SLOW_SECONDS (Standard 3) Sekunden (fuer Status-Abfragen waehrenddessen)
set -uo pipefail

DEPLOY_SH_SRC="$ROOT/deploy/vps/scripts/deploy.sh"
RUNNER_SRC="$ROOT/deploy/vps/scripts/deploy-runner.sh"
STATUS_SH_SRC="$ROOT/deploy/vps/scripts/deploy-status.sh"

# Liste aller angelegten Sandboxes (Datei statt Array, weil new_sandbox in "$(...)"-Subshells laeuft). Die
# Testskripte registrieren sandbox_cleanup_all per "trap ... EXIT", damit auch ein abgebrochener Lauf
# (Ctrl-C, CI-Timeout) keine Verzeichnisse zuruecklaesst; per setsid entkoppelte Fake-Deploys enden von
# selbst innerhalb weniger Sekunden.
SANDBOX_LIST="$(mktemp)"
sandbox_cleanup_all() {
    local d
    if [[ -f "${SANDBOX_LIST:-}" ]]; then
        while IFS= read -r d; do
            [[ -n "$d" && -d "$d" ]] && rm -rf "$d"
        done < "$SANDBOX_LIST"
        rm -f "$SANDBOX_LIST"
    fi
}

new_sandbox() {
    local dir
    dir="$(mktemp -d)"
    printf '%s\n' "$dir" >> "$SANDBOX_LIST"
    install -d -m 750 "$dir/deploy" "$dir/logs" "$dir/fake-state"
    cat > "$dir/deploy/.env" <<'ENV'
DEPLOY_ENV=prod
HEALTH_STRICT=false
DOMAIN_APP=test.invalid
ENV
    printf '%s' "$dir"
}

# Die echten Skripte sind auf BASE=/opt/smarteinzug fest verdrahtet (bewusst); der Test arbeitet mit einer
# Kopie, in der genau diese eine Zeile auf den Sandbox-Pfad zeigt.
patch_base() {
    local file="$1" sandbox="$2"
    sed -i "s#^BASE=/opt/smarteinzug\$#BASE=$sandbox#" "$file"
    grep -q "^BASE=$sandbox\$" "$file" || { echo "::error:: BASE-Ersetzung fehlgeschlagen: $file"; exit 2; }
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
    # Statusseite des Release (Platzhalter), wie sie der GitHub-Workflow ablegt: deploy.sh kopiert sie
    # einmalig nach shared/status, damit Caddy /status.json ausliefern kann.
    # Anwendungskern wie im echten Release (deploy.sh erkennt daran vollstaendige Altreleases ohne Nachweis).
    install -d -m 750 "$sandbox/releases/$sha/app"
    printf '<?php\n' > "$sandbox/releases/$sha/app/bootstrap.php"
    install -d -m 750 "$sandbox/releases/$sha/status"
    printf '{"schema":1,"overall":{"state":"unknown"}}\n' > "$sandbox/releases/$sha/status/status.json"
    # Vollstaendigkeitsnachweis, wie ihn der GitHub-Workflow nach dem letzten rsync schreibt; ohne ihn
    # verweigert deploy.sh die Auslieferung (siehe dort ".release-complete").
    printf '%s\n2026-01-01T00:00:00Z\n42\n' "$sha" > "$sandbox/releases/$sha/.release-complete"
    printf '#!/usr/bin/env bash\nexit 0\n' > "$rel/scripts/rollback.sh"
    chmod +x "$rel/scripts/rollback.sh"
    # Downgrade-Schutz wie im echten Release (deploy.sh laedt die Bibliothek aus dem Release).
    install -d -m 750 "$rel/scripts/lib"
    cp "$(dirname "$DEPLOY_SH_SRC")/lib/release-version.sh" "$rel/scripts/lib/release-version.sh"
    cp "$DEPLOY_SH_SRC" "$rel/scripts/deploy.sh"
    patch_base "$rel/scripts/deploy.sh" "$sandbox"
    cp "$RUNNER_SRC" "$rel/scripts/deploy-runner.sh"
    patch_base "$rel/scripts/deploy-runner.sh" "$sandbox"
    cp "$STATUS_SH_SRC" "$rel/scripts/deploy-status.sh"
    patch_base "$rel/scripts/deploy-status.sh" "$sandbox"
    # NEEDS_BUILD=0 erzwingen (identischer php/-Inhalt in jedem Release dieses Tests): kein Image-Build.
    local hash
    hash="$(find "$rel/php" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"
    echo "$hash" > "$sandbox/deploy/.php-image.sha256"
}

set_current() {
    local sandbox="$1" sha="$2"
    ln -sfn "$sandbox/releases/$sha" "$sandbox/releases/current"
    # Der "laufende Stack" traegt dieses Release (working_dir) und den Standard-Hash am redis-Container.
    local svc
    for svc in php scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics redis; do
        printf '%s\n' "$sandbox/releases/$sha" > "$sandbox/fake-state/$svc.workdir"
        printf 'SIGTERM 75\n' > "$sandbox/fake-state/$svc.stopcfg"   # neue Stop-Konfiguration (ab 4.11)
    done
    printf 'h-default\n' > "$sandbox/fake-state/redis.hash"
}

# Die laufenden Hintergrund-Container tragen die ALTE Stop-Konfiguration der Versionen bis 4.10 (STOPSIGNAL
# SIGQUIT aus dem Basisimage, StopTimeout 660 s), wie beim ersten Deployment ab Version 4.11.
set_legacy_stop_config() {
    local sandbox="$1" svc
    for svc in scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics; do
        printf 'SIGQUIT 660\n' > "$sandbox/fake-state/$svc.stopcfg"
    done
}

make_fake_docker() {
    local sandbox="$1"
    install -d -m 750 "$sandbox/fakebin"
    cat > "$sandbox/fakebin/docker" <<'FAKE'
#!/usr/bin/env bash
# Fake "docker" (tools/lib/deploy-sandbox.sh): bildet nur die von deploy.sh/rollback.sh verwendeten
# Unterbefehle nach, ohne Docker-Daemon, mit kleinem Zustand unter $FAKE_STATE_DIR.
set -uo pipefail
ARGS="$*"
STATE="${FAKE_STATE_DIR:?FAKE_STATE_DIR nicht gesetzt}"
echo "RELEASE_SHA=${RELEASE_SHA:-unset} $ARGS" >> "${FAKE_CALL_LOG:?FAKE_CALL_LOG nicht gesetzt}"

if [[ -n "${FAKE_SLOW_STEP:-}" && "$ARGS" == *"$FAKE_SLOW_STEP"* && ! -f "$STATE/slowed" ]]; then
    : > "$STATE/slowed"
    sleep "${FAKE_SLOW_SECONDS:-3}"
fi

redis_conf_ok() {
    grep -qx 'protected-mode no' "${FAKE_DEPLOY_DIR:?FAKE_DEPLOY_DIR nicht gesetzt}/redis/redis.conf" 2>/dev/null
}
redis_probe_result() {
    if [[ "${FAKE_ALIAS_MISSING:-0}" == "1" ]]; then
        echo 'DIAGNOSE redis: host=smarteinzug-redis port=6379 aufgeloest=keine erwartet=172.28.0.0/24 stufe=resolve kategorie=alias_missing meldung="(fake)"' >&2
        echo "UNGESUND: redis: alias_missing" >&2
        return 1
    fi
    if redis_conf_ok; then
        echo 'DIAGNOSE redis: host=smarteinzug-redis port=6379 aufgeloest=172.28.0.5 erwartet=172.28.0.0/24 stufe=ok kategorie=ok meldung=""' >&2
        return 0
    fi
    echo 'DIAGNOSE redis: host=smarteinzug-redis port=6379 aufgeloest=172.28.0.5 erwartet=172.28.0.0/24 stufe=redis kategorie=redis_protected_mode meldung="DENIED (fake)"' >&2
    echo "UNGESUND: redis: redis_protected_mode" >&2
    return 1
}
record_running() { # $1 = Dienst
    printf '%s\n' "/tmp-unused" >/dev/null
    printf '%s\n' "${FAKE_RELEASES_DIR:?}/${RELEASE_SHA:-unset}" > "$STATE/$1.workdir"
}

case "$ARGS" in
    *"config redis --format json"*)
        if [[ "${FAKE_ISOLATION_BAD:-0}" == "1" ]]; then
            echo '{"services":{"redis":{"ports":[{"published":"6379"}],"networks":{"smarteinzug_internal":null},"labels":null}}}'
        else
            echo '{"services":{"redis":{"ports":null,"networks":{"smarteinzug_internal":{"aliases":["smarteinzug-redis"]}},"labels":null}}}'
        fi
        ;;
    *"config --hash redis"*)
        echo "redis ${FAKE_REDIS_HASH:-h-default}"
        ;;
    *"config --images redis"*)
        echo "redis:7-alpine"
        ;;
    *"config --services"*)
        printf 'php\nscheduler\nworker-lexware-1\nworker-stripe\nworker-mail\nworker-maintenance\nmetrics\nredis\n'
        ;;
    *"up -d --no-deps --force-recreate redis"*)
        n=$(( $(cat "$STATE/redis.recreates" 2>/dev/null || echo 0) + 1 ))
        echo "$n" > "$STATE/redis.recreates"
        [[ "${FAKE_RECREATE_MODE:-ok}" == "fail" ]] && exit 1
        [[ -n "${FAKE_RECREATE_FAIL_ON:-}" && "$n" -eq "${FAKE_RECREATE_FAIL_ON}" ]] && exit 1
        echo "${FAKE_REDIS_HASH:-h-default}" > "$STATE/redis.hash"
        record_running redis
        exit 0
        ;;
    *"up -d --remove-orphans"*)
        for svc in php scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics redis; do
            record_running "$svc"
            printf 'SIGTERM 75\n' > "$STATE/$svc.stopcfg"   # neu erzeugt: neue Stop-Konfiguration
        done
        echo "${FAKE_REDIS_HASH:-h-default}" > "$STATE/redis.hash"
        exit 0
        ;;
    "stop --help"*)
        printf 'Usage:  docker stop [OPTIONS] CONTAINER [CONTAINER...]\n\nOptions:\n'
        [[ "${FAKE_STOP_SIGNAL_UNSUPPORTED:-0}" == "1" ]] || printf '  -s, --signal string   Signal to send to the container\n'
        printf '  -t, --timeout int     Seconds to wait before killing the container\n'
        exit 0
        ;;
    "kill --signal "*)
        # docker kill --signal SIGTERM <cid> ...: Ausweichweg fuer Docker-CLI ohne "stop --signal"
        for tok in $ARGS; do
            [[ "$tok" == cid-* && "${FAKE_LEGACY_HANGS:-0}" != "1" ]] && : > "$STATE/${tok#cid-}.stopped"
        done
        [[ "${FAKE_KILL_MODE:-ok}" == "fail" ]] && exit 1
        exit 0
        ;;
    "stop --time "*)
        for tok in $ARGS; do
            [[ "$tok" == cid-* ]] && : > "$STATE/${tok#cid-}.stopped"
        done
        [[ "${FAKE_STOP_MODE:-ok}" == "fail" ]] && exit 1
        exit 0
        ;;
    "stop --signal "*)
        # docker stop --signal SIGTERM --timeout 90 <cid> ...: Vorab-Stopp veralteter Container (deploy.sh)
        for tok in $ARGS; do
            [[ "$tok" == cid-* ]] && : > "$STATE/${tok#cid-}.stopped"
        done
        [[ "${FAKE_STOP_MODE:-ok}" == "fail" ]] && exit 1
        exit 0
        ;;
    *"ps -a redis --format"*|*"ps redis --format"*)
        if [[ "${FAKE_REDIS_PS_EMPTY:-0}" == "1" ]]; then
            exit 0
        elif [[ "${FAKE_REDIS_HEALTHY:-1}" == "1" ]]; then
            echo "fake-redis-1 healthy"
        else
            echo "fake-redis-1 starting"
        fi
        ;;
    *" ps -q "*)
        svc="${ARGS##* ps -q }"; svc="${svc%% *}"
        [[ -f "$STATE/$svc.workdir" ]] && echo "cid-$svc"
        exit 0
        ;;
    "inspect --format "*)
        fmt="${ARGS#inspect --format }"; cid="${fmt##* }"; fmt="${fmt% *}"; svc="${cid#cid-}"
        case "$fmt" in
            *config-hash*) cat "$STATE/redis.hash" 2>/dev/null ;;
            *WorkingDir*)  cat "$STATE/$svc.workdir" 2>/dev/null ;;
            *StopSignal*)  cat "$STATE/$svc.stopcfg" 2>/dev/null || echo "SIGTERM 75" ;;
            *State.Running*) [[ -f "$STATE/$svc.stopped" ]] && echo false || echo true ;;
            *State.Status*) echo running ;;
            *) echo "" ;;
        esac
        exit 0
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
        echo "0 eingespielt, 0 offen"
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
    *"restart -t"*)
        echo "FAKE-DOCKER: unerwarteter restart: $ARGS" >&2
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

# deploy.sh fuer "$sha" direkt ausfuehren (ohne Runner). Rueckgabe: Exitcode. Ausgabe in deploy-output.log,
# Aufrufe des Fake-docker in fake-docker-calls.log (wird NICHT zurueckgesetzt: ein zweiter Lauf im selben
# Sandbox protokolliert beide Laeufe).
# Zusaetzliche Umgebungsvariablen (z.B. SMARTEINZUG_SKIP_RELEASE_CHECK, REDIS_WAIT_HEALTHY_SECONDS)
# werden aus der Umgebung des Aufrufers uebernommen: "VAR=wert run_deploy ..." wirkt wie erwartet.
run_deploy() {
    local sandbox="$1" sha="$2"
    FAKE_CALL_LOG="$sandbox/fake-docker-calls.log" \
    FAKE_DEPLOY_DIR="$sandbox/deploy" \
    FAKE_STATE_DIR="$sandbox/fake-state" \
    FAKE_RELEASES_DIR="$sandbox/releases" \
    PATH="$sandbox/fakebin:$PATH" \
        bash "$sandbox/releases/$sha/deploy/vps/scripts/deploy.sh" "$sha" > "$sandbox/deploy-output.log" 2>&1
    return $?
}

# deploy-runner.sh fuer "$sha" ausloesen (wie der GitHub-Workflow per SSH): kehrt nach TRIGGERED zurueck,
# der Deploy laeuft entkoppelt weiter. Ausgabe des Triggers auf stdout.
run_trigger() {
    local sandbox="$1" sha="$2"
    FAKE_CALL_LOG="$sandbox/fake-docker-calls.log" \
    FAKE_DEPLOY_DIR="$sandbox/deploy" \
    FAKE_STATE_DIR="$sandbox/fake-state" \
    FAKE_RELEASES_DIR="$sandbox/releases" \
    PATH="$sandbox/fakebin:$PATH" \
        bash "$sandbox/releases/$sha/deploy/vps/scripts/deploy-runner.sh" "$sha"
}

# deploy-status.sh des Release "$sha" (BASE auf den Sandbox gesetzt) aufrufen; Argumente werden durchgereicht.
run_status() {
    local sandbox="$1" sha="$2"; shift 2
    bash "$sandbox/releases/$sha/deploy/vps/scripts/deploy-status.sh" "$@"
}

calls() { cat "$1/fake-docker-calls.log" 2>/dev/null; }
call_count() { calls "$1" | grep -c -- "$2" || true; }
output() { cat "$1/deploy-output.log" 2>/dev/null; }
status_json() { cat "$1/deploy/.deploy-status.json" 2>/dev/null; }
status_field() { status_json "$1" | jq -r ".$2 // empty" 2>/dev/null || true; }
wait_for_phase() {
    local sandbox="$1" want="$2" tries=0
    while [[ "$(status_field "$sandbox" phase)" != "$want" && $tries -lt 300 ]]; do
        sleep 0.2
        tries=$((tries + 1))
    done
    [[ "$(status_field "$sandbox" phase)" == "$want" ]]
}
