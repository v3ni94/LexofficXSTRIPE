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
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "::error:: Es laeuft bereits ein Deployment oder Rollback (Sperre $LOCK_FILE belegt)."
    exit 1
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

# Vertraeglichkeit mit dem Datenbankschema pruefen: Alle eingespielten Migrationen muessen im
# Zielrelease vorhanden sein, sonst wuerde aelterer Code auf ein neueres Schema treffen.
APPLIED="$("${COMPOSE[@]}" exec -T php php bin/migrate.php --status 2>/dev/null | awk '$2=="applied"{print $1}' || true)"
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
rsync -a --delete --exclude '.env' --exclude '.deploy.lock' --exclude '.php-image.sha256' \
    --exclude '.release_history' --exclude '.previous_sha' \
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
BACKGROUND_SERVICES=(scheduler worker-lexware-1 worker-stripe worker-mail worker-maintenance)
if "${COMPOSE[@]}" config --services 2>/dev/null | grep -qx worker-lexware-2; then
    BACKGROUND_SERVICES+=(worker-lexware-2)
fi
"${COMPOSE[@]}" restart -t 660 "${BACKGROUND_SERVICES[@]}"

sleep 5
if ! "${COMPOSE[@]}" exec -T php php bin/healthcheck.php --all; then
    echo "::error:: Health-Check nach dem Rollback fehlgeschlagen. Manuelle Pruefung auf dem Server erforderlich."
    exit 1
fi

echo "[$(date -u +%FT%TZ)] Rollback auf $TARGET abgeschlossen."
