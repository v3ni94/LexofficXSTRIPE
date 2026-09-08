#!/usr/bin/env bash
#
# SmartEinzug: Rollback auf ein frueheres, bereits ausgeliefertes Release.
#
#   bash /opt/smarteinzug/deploy/scripts/rollback.sh <git-sha>
#   bash /opt/smarteinzug/deploy/scripts/rollback.sh previous
#
# "previous" verwendet das zuletzt von deploy.sh hinterlegte vorherige Release
# (/opt/smarteinzug/deploy/.previous_sha). Ein Rollback spielt KEINE Migrationen ein und rollt die
# Datenbank nicht zurueck: Migrationen dieses Projekts sind additiv/rueckwaertskompatibel angelegt
# (siehe docs/migrations.md), ein Rollback wechselt also nur den Anwendungscode. Sind in der
# Datenbank Migrationen eingespielt, die das Zielrelease nicht kennt, bricht dieses Skript ab; nur mit
# FORCE_ROLLBACK=1 (bewusste Entscheidung nach Pruefung) wird trotzdem zurueckgerollt.
#
# Sperre: Wie deploy.sh oeffnet dieses Skript standardmaessig selbst die Sperrdatei (Dateideskriptor 9).
# Wird es von deploy.sh aus einem automatischen Rollback heraus aufgerufen, das seinerseits von
# deploy-runner.sh gestartet wurde, haelt deploy-runner.sh die Sperre bereits ueber die gesamte Laufzeit
# (SMARTEINZUG_LOCK_HELD=1); dieses Skript versucht dann NICHT, dieselbe Sperre ein zweites Mal zu
# erwerben (wuerde sonst fehlschlagen, weil sie vom selben Prozessbaum bereits gehalten wird).
#
# Release-Bindung: Wie deploy.sh exportiert dieses Skript RELEASE_SHA (hier: das ZIELrelease des
# Rollbacks) vor jedem "docker compose"-Aufruf; docker-compose.yml bindet working_dir aller
# PHP-Container darueber an den konkreten Freigabepfad, nie an den mutable Symlink "current".
set -euo pipefail

BASE=/opt/smarteinzug
DEPLOY_DIR="$BASE/deploy"
LOG_DIR="$BASE/logs"
RELEASES_DIR="$BASE/releases"
CURRENT_LINK="$RELEASES_DIR/current"
TARGET="${1:?Nutzung: rollback.sh <git-sha|previous>}"

install -d -m 750 "$LOG_DIR"
TS="$(date -u +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/rollback-$TS.log"
exec > >(tee -a "$LOG_FILE") 2>&1

if [[ "$TARGET" == "previous" ]]; then
    if [[ ! -f "$DEPLOY_DIR/.previous_sha" ]]; then
        echo "::error:: Kein hinterlegtes vorheriges Release (.previous_sha fehlt). Git-SHA explizit angeben."
        exit 1
    fi
    TARGET="$(cat "$DEPLOY_DIR/.previous_sha")"
fi
case "$TARGET" in
    current|.*|*/*|*' '*|"")
        echo "::error:: Ungueltiger Release-Name: $TARGET"
        exit 1
        ;;
esac

RELEASE_DIR="$RELEASES_DIR/$TARGET"
if [[ ! -d "$RELEASE_DIR" ]]; then
    echo "::error:: Release-Ordner fehlt: $RELEASE_DIR (evtl. bereits durch die Aufbewahrung der letzten 5 Releases entfernt)."
    exit 1
fi
if [[ ! -d "$RELEASE_DIR/deploy/vps" ]]; then
    echo "::error:: $RELEASE_DIR/deploy/vps fehlt. Zielrelease unvollstaendig, kein Rollback."
    exit 1
fi
if [[ ! -f "$DEPLOY_DIR/.env" ]]; then
    echo "::error:: $DEPLOY_DIR/.env fehlt."
    exit 1
fi

install -d -m 750 "$DEPLOY_DIR"
LOCK_FILE="$DEPLOY_DIR/.deploy.lock"
if [[ "${SMARTEINZUG_LOCK_HELD:-0}" == "1" ]]; then
    echo "Sperre wird bereits vom aufrufenden Prozess gehalten (deploy.sh/deploy-runner.sh), kein erneuter Erwerb."
else
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "::error:: Es laeuft bereits ein Deployment oder Rollback (Sperre $LOCK_FILE belegt)."
        exit 1
    fi
fi

envval() {
    local key="$1" default="${2:-}" line value
    line="$(grep -E "^[[:space:]]*${key}=" "$DEPLOY_DIR/.env" | tail -n 1 || true)"
    value="${line#*=}"
    value="${value%$'\r'}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "${value:-$default}"
}

set_current() {
    ln -sfn "$RELEASES_DIR/$1" "$CURRENT_LINK.tmp"
    mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
}

# Der Vollstaendigkeitsnachweis (.release-complete, siehe deploy.sh) wird beim Rollback NICHT verlangt:
# Releases, die vor seiner Einfuehrung ausgeliefert wurden, tragen ihn nicht, und ein Rollback auf ein
# nachweislich einmal erfolgreich betriebenes Release muss moeglich bleiben. Fehlt er, wird es vermerkt.
if [[ ! -f "$RELEASE_DIR/.release-complete" ]]; then
    echo "::warning:: $RELEASE_DIR/.release-complete fehlt (Release aus der Zeit vor dieser Pruefung oder von Hand angelegt). Rollback wird trotzdem ausgefuehrt."
fi

echo "[$(date -u +%FT%TZ)] Rollback auf $TARGET gestartet."

FROM_SHA=""
if [[ -L "$CURRENT_LINK" ]]; then
    FROM_SHA="$(basename "$(readlink -f "$CURRENT_LINK" || true)")"
fi

cd "$DEPLOY_DIR"
DEPLOY_ENV="$(envval DEPLOY_ENV prod)"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env)
if [[ "$DEPLOY_ENV" == "staging" ]]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml --env-file .env)
fi

# RELEASE_SHA bindet working_dir aller PHP-Container (siehe docker-compose.yml); noetig, damit
# "docker compose" ueberhaupt interpolieren kann, auch fuer den folgenden Status-Aufruf gegen den noch
# laufenden (alten) Container, dessen working_dir davon unberuehrt bleibt (bei "exec" bereits erzeugte
# Container werden nicht neu erzeugt). Fuer den spaeteren "up -d" ist es der tatsaechliche Zielwert.
export RELEASE_SHA="$TARGET"
printf 'RELEASE_SHA=%s\n' "$TARGET" > "$DEPLOY_DIR/.release.env"

# Schutz gegen einen versehentlichen Staging-Rollback gegen die Produktionskonfiguration (dieselbe
# Absicherung wie in deploy.sh, siehe dort "Candidate pruefen"). Normalerweise ueber den bereits laufenden
# php-Container (exec). Laeuft er nicht (z.B. automatischer Rollback aus deploy.sh, weil genau der
# php-Container nach dem Cutover nicht startete), wuerde "exec" scheitern und der Rollback unterbleiben;
# dann laeuft die Pruefung stattdessen in einem isolierten Wegwerfcontainer des Zielrelease
# (run --rm --no-deps, wie die Candidate-Pruefung in deploy.sh). shared/config.php ist releaseunabhaengig.
PHP_CID="$("${COMPOSE[@]}" ps -q php 2>/dev/null | head -n1 || true)"
PHP_STATE="$([[ -n "$PHP_CID" ]] && docker inspect --format '{{.State.Status}}' "$PHP_CID" 2>/dev/null || true)"
if [[ "$PHP_STATE" == "running" ]]; then
    PHP_RUNNER=(exec -T php)
else
    echo "php-Container laeuft nicht (Zustand: ${PHP_STATE:-keiner}); Umgebungs- und Migrationspruefung in einem isolierten Wegwerfcontainer des Zielrelease."
    PHP_RUNNER=(run --rm --no-deps -T php)
fi
if ! "${COMPOSE[@]}" "${PHP_RUNNER[@]}" php bin/healthcheck.php --expect-env="$DEPLOY_ENV" 2>&1; then
    echo "::error:: Umgebungspruefung fehlgeschlagen (config('environment') passt nicht zu DEPLOY_ENV=$DEPLOY_ENV). Kein Rollback."
    exit 1
fi

# Vertraeglichkeit mit dem Datenbankschema pruefen: Alle eingespielten Migrationen muessen im
# Zielrelease vorhanden sein, sonst wuerde aelterer Code auf ein neueres Schema treffen.
APPLIED="$("${COMPOSE[@]}" "${PHP_RUNNER[@]}" php bin/migrate.php --status 2>/dev/null | awk '$2=="applied"{print $1}' || true)"
if [[ -z "$APPLIED" ]]; then
    echo "::warning:: Migrationsstand konnte nicht gelesen werden (php-Container nicht erreichbar?). Vertraeglichkeitspruefung uebersprungen."
else
    MISSING=""
    for v in $APPLIED; do
        if ! compgen -G "$RELEASE_DIR/sql/migrations/${v}_*.sql" >/dev/null; then
            MISSING="$MISSING $v"
        fi
    done
    if [[ -n "$MISSING" ]]; then
        if [[ "${FORCE_ROLLBACK:-0}" == "1" ]]; then
            echo "::warning:: Datenbank enthaelt Migrationen, die das Zielrelease nicht kennt ($MISSING). FORCE_ROLLBACK=1 gesetzt, Rollback wird trotzdem ausgefuehrt."
        else
            echo "::error:: Datenbank enthaelt Migrationen, die das Zielrelease nicht kennt:$MISSING."
            echo "         Das Zielrelease koennte mit dem aktuellen Schema unvertraeglich sein. Nach Pruefung (docs/migrations.md)"
            echo "         mit FORCE_ROLLBACK=1 erneut aufrufen oder ein neueres Zielrelease waehlen."
            exit 1
        fi
    else
        echo "Migrationsstand vertraeglich mit dem Zielrelease."
    fi
fi

echo "Uebernehme deploy/vps aus dem Zielrelease nach $DEPLOY_DIR ..."
# Laufzeitdateien des Deploy-Ordners NIE mitloeschen: Die Muster /.deploy* (Sperre, PID-Datei,
# Statusdatei .deploy-status.json samt ihrer .tmp-Zwischendatei), /.release* (.release_history,
# .release.env), /.previous_sha, /.php-image.sha256 und /.env liegen NICHT im Release und wuerden von
# "--delete" sonst entfernt. Genau das war die Ursache fuer "phase=unknown" waehrend eines laufenden
# Deployments (Version 4.11): deploy-runner.sh hatte die Statusdatei bereits mit "running" geschrieben,
# dieser rsync loeschte sie Sekunden spaeter, deploy-status.sh fand bis zum Ende nichts mehr.
rsync -a --delete --exclude '/.env' --exclude '/.deploy*' --exclude '/.php-image.sha256' \
    --exclude '/.release*' --exclude '/.previous_sha' \
    "$RELEASE_DIR/deploy/vps/" "$DEPLOY_DIR/"

# Image neu bauen, wenn das Zielrelease einen anderen Stand von deploy/vps/php hat; die Pruefsumme wird
# danach aktualisiert, damit das naechste Deployment korrekt erkennt, welches Image gerade laeuft.
PHP_IMAGE_HASH="$(find "$RELEASE_DIR/deploy/vps/php" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"
PHP_IMAGE_HASH_FILE="$DEPLOY_DIR/.php-image.sha256"
if [[ ! -f "$PHP_IMAGE_HASH_FILE" ]] || [[ "$(cat "$PHP_IMAGE_HASH_FILE")" != "$PHP_IMAGE_HASH" ]]; then
    echo "Baue Image fuer das Rollback-Ziel neu ..."
    "${COMPOSE[@]}" build php
    echo "$PHP_IMAGE_HASH" > "$PHP_IMAGE_HASH_FILE"
fi

"${COMPOSE[@]}" up -d --remove-orphans

echo "Warte auf gesunde Container (bis zu 180 Sekunden) ..."
DEADLINE=$((SECONDS + 180))
while true; do
    UNHEALTHY="$("${COMPOSE[@]}" ps --format '{{.Name}} {{.Health}}' 2>/dev/null | awk '$2!="" && $2!="healthy"{print $1"="$2}')"
    if [[ -z "$UNHEALTHY" ]]; then
        break
    fi
    if (( SECONDS > DEADLINE )); then
        echo "::error:: Zeitueberschreitung beim Warten auf gesunde Container waehrend des Rollbacks: $UNHEALTHY"
        "${COMPOSE[@]}" logs --tail=100
        exit 1
    fi
    sleep 5
done

echo "Aktiviere Release $TARGET (Symlink $CURRENT_LINK) ..."
set_current "$TARGET"
echo "$(date -u +%FT%TZ) rollback $TARGET (von ${FROM_SHA:-unbekannt})" >> "$DEPLOY_DIR/.release_history"
if [[ -n "$FROM_SHA" && "$FROM_SHA" != "$TARGET" && -d "$RELEASES_DIR/$FROM_SHA" ]]; then
    echo "$FROM_SHA" > "$DEPLOY_DIR/.previous_sha"
fi

"${COMPOSE[@]}" exec -T php kill -USR2 1

# Kein zweiter Neustart von Scheduler/Workern (frueher "restart -t 660"): "up -d" oben hat sie wegen des
# geaenderten working_dir (RELEASE_SHA=$TARGET, Teil des Compose-Konfigurations-Hash) bereits neu erzeugt.
# Stattdessen wird die Release-Bindung verifiziert (siehe deploy.sh).
echo "Verifiziere die Release-Bindung aller PHP-Container (working_dir = $RELEASES_DIR/$TARGET) ..."
RELEASE_BOUND_SERVICES=(php scheduler worker-lexware-1 worker-stripe worker-mail worker-maintenance metrics)
for optional_svc in worker-lexware-2 worker-sevdesk; do
    if "${COMPOSE[@]}" config --services 2>/dev/null | grep -qx "$optional_svc"; then
        RELEASE_BOUND_SERVICES+=("$optional_svc")
    fi
done
BINDING_ERRORS=0
for svc in "${RELEASE_BOUND_SERVICES[@]}"; do
    # "|| true": ein fehlschlagender Compose-Aufruf darf den Rollback hier nicht still beenden (set -e).
    cid="$("${COMPOSE[@]}" ps -q "$svc" 2>/dev/null | head -n1 || true)"
    wd="$([[ -n "$cid" ]] && docker inspect --format '{{.Config.WorkingDir}}' "$cid" 2>/dev/null || true)"
    state="$([[ -n "$cid" ]] && docker inspect --format '{{.State.Status}}' "$cid" 2>/dev/null || true)"
    if [[ -z "$cid" || "$wd" != "$RELEASES_DIR/$TARGET" || "$state" != "running" ]]; then
        echo "::error:: Dienst $svc: working_dir=${wd:-?} Zustand=${state:-?}, erwartet $RELEASES_DIR/$TARGET und running."
        BINDING_ERRORS=$((BINDING_ERRORS + 1))
    else
        echo "  $svc: $wd ($state)"
    fi
done
if (( BINDING_ERRORS > 0 )); then
    echo "::error:: Nicht alle PHP-Container laufen mit dem Zielrelease. Manuelle Pruefung auf dem Server erforderlich."
    exit 1
fi

sleep 5
if ! "${COMPOSE[@]}" exec -T php php bin/healthcheck.php --all; then
    echo "::error:: Health-Check nach dem Rollback fehlgeschlagen. Manuelle Pruefung auf dem Server erforderlich."
    exit 1
fi

echo "[$(date -u +%FT%TZ)] Rollback auf $TARGET abgeschlossen."
