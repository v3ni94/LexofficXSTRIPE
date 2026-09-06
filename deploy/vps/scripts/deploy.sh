#!/usr/bin/env bash
#
# SmartEinzug: Deployment eines bereits per rsync abgelegten Release auf dem VPS.
#
#   bash /opt/smarteinzug/releases/<git-sha>/deploy/vps/scripts/deploy.sh <git-sha>
#
# Der GitHub-Workflow ruft genau diese Kopie aus dem NEUEN Release auf, damit Aenderungen an diesem
# Skript sofort wirken; anschliessend liegt dieselbe Fassung auch unter
# /opt/smarteinzug/deploy/scripts/deploy.sh (Handbetrieb).
#
# Voraussetzung: /opt/smarteinzug/releases/<git-sha>/ existiert bereits vollstaendig (Inhalt von
# php-ionos/, per rsync durch den GitHub-Workflow oder von Hand angelegt) UND enthaelt den Ordner
# deploy/vps/ (dieser Ordner). Dieses Skript selbst holt keinen Code, es aktiviert nur ein
# vorhandenes Release.
#
# Ablauf: Sperre -> deploy/vps aus dem Release uebernehmen -> Image ggf. neu bauen -> Container
# hochfahren -> auf "healthy" warten -> current-Symlink umstellen -> Migrationen mit dem neuen Code
# einspielen (bei Fehler Symlink zurueck, kein Reload) -> php-fpm neu laden -> Worker/Scheduler
# kontrolliert neu starten -> Health-Check -> bei Fehler automatisches Rollback auf das vorherige
# Release. Alte Releases werden auf die letzten 5 begrenzt.
#
# Pfade: Die Container binden /opt/smarteinzug/releases nur lesend ein und arbeiten mit dem Pfad
# /opt/smarteinzug/releases/current. Dieser Symlink zeigt auf /opt/smarteinzug/releases/<git-sha>,
# also in denselben Mount, und wird im Container bei jedem Dateizugriff neu aufgeloest; deshalb genuegt
# nach dem Umstellen ein Reload von php-fpm (kein Neustart der Container).
set -euo pipefail

BASE=/opt/smarteinzug
DEPLOY_DIR="$BASE/deploy"
LOG_DIR="$BASE/logs"
RELEASES_DIR="$BASE/releases"
CURRENT_LINK="$RELEASES_DIR/current"
SHA="${1:?Nutzung: deploy.sh <git-sha>}"

# Nur einfache Release-Namen zulassen (Git-SHA oder ein Name wie "erstinstallation").
case "$SHA" in
    current|.*|*/*|*' '*)
        echo "::error:: Ungueltiger Release-Name: $SHA"
        exit 1
        ;;
esac
RELEASE_DIR="$RELEASES_DIR/$SHA"
ROLLBACK_SH="$RELEASE_DIR/deploy/vps/scripts/rollback.sh"

install -d -m 750 "$LOG_DIR"
TS="$(date -u +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/deploy-$TS.log"
exec > >(tee -a "$LOG_FILE") 2>&1
# Protokolle aelter als 90 Tage entfernen (Deployment- und Rollback-Protokolle).
find "$LOG_DIR" -maxdepth 1 -name '*.log' -mtime +90 -delete 2>/dev/null || true

echo "[$(date -u +%FT%TZ)] Deployment $SHA gestartet."

install -d -m 750 "$DEPLOY_DIR"
LOCK_FILE="$DEPLOY_DIR/.deploy.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "::error:: Es laeuft bereits ein Deployment oder Rollback (Sperre $LOCK_FILE belegt)."
    exit 1
fi

if [[ ! -d "$RELEASE_DIR" ]]; then
    echo "::error:: Release-Ordner fehlt: $RELEASE_DIR"
    exit 1
fi
if [[ ! -d "$RELEASE_DIR/deploy/vps" || ! -f "$ROLLBACK_SH" ]]; then
    echo "::error:: $RELEASE_DIR/deploy/vps fehlt oder ist unvollstaendig. Kein Deployment."
    exit 1
fi
if [[ ! -f "$DEPLOY_DIR/.env" ]]; then
    echo "::error:: $DEPLOY_DIR/.env fehlt. Vorlage .env.example einmalig nach .env kopieren und fuellen."
    exit 1
fi

# Einen Wert aus deploy/.env lesen, OHNE die Datei auszufuehren (kein "source": Geheimnisse gelangen
# so nicht in die Umgebung dieses Skripts und seiner Kindprozesse; docker compose liest die Datei
# selbst ueber --env-file).
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

# Vorheriges Release fuer ein moegliches Rollback merken, BEVOR der Symlink umgestellt wird.
PREV_SHA=""
if [[ -L "$CURRENT_LINK" ]]; then
    PREV_TARGET="$(readlink -f "$CURRENT_LINK" || true)"
    PREV_SHA="$(basename "${PREV_TARGET:-}")"
    [[ "$PREV_SHA" == "$SHA" ]] && PREV_SHA=""
fi

run_rollback() {
    # Die Sperre freigeben, sonst kann rollback.sh sie nicht erhalten (dieselbe Sperrdatei).
    flock -u 9 || true
    exec 9>&-
    if [[ -z "$PREV_SHA" || ! -d "$RELEASES_DIR/$PREV_SHA" ]]; then
        echo "::error:: Kein vorheriges Release bekannt, automatisches Rollback nicht moeglich. Manuelle Pruefung erforderlich."
        return 1
    fi
    bash "$ROLLBACK_SH" "$PREV_SHA"
}

# Ob das Image neu gebaut werden muss, wird ueber eine Pruefsumme des gesamten deploy/vps/php/-Ordners
# entschieden (nicht nur des Dockerfile: php.ini/www.conf fliessen ebenfalls ins Image ein). Aendert
# sich diese Pruefsumme nicht, wird kein neues Image gebaut ("Ein Deployment baut kein Image neu,
# ausser wenn deploy/vps/php/Dockerfile geaendert wurde").
PHP_IMAGE_HASH="$(find "$RELEASE_DIR/deploy/vps/php" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"
PHP_IMAGE_HASH_FILE="$DEPLOY_DIR/.php-image.sha256"
NEEDS_BUILD=1
if [[ -f "$PHP_IMAGE_HASH_FILE" ]] && [[ "$(cat "$PHP_IMAGE_HASH_FILE")" == "$PHP_IMAGE_HASH" ]]; then
    NEEDS_BUILD=0
fi

echo "Uebernehme deploy/vps aus dem Release nach $DEPLOY_DIR ..."
rsync -a --delete --exclude '.env' --exclude '.deploy.lock' --exclude '.php-image.sha256' \
    --exclude '.release_history' --exclude '.previous_sha' \
    "$RELEASE_DIR/deploy/vps/" "$DEPLOY_DIR/"

cd "$DEPLOY_DIR"
DEPLOY_ENV="$(envval DEPLOY_ENV prod)"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env)
if [[ "$DEPLOY_ENV" == "staging" ]]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml --env-file .env)
fi
echo "Umgebung: $DEPLOY_ENV"

# Erstinstallation: Ohne current-Symlink koennen die Container nicht starten (Arbeitsverzeichnis).
# Es gibt dann auch nichts, worauf zurueckgerollt werden koennte.
if [[ ! -L "$CURRENT_LINK" ]]; then
    echo "Kein aktives Release vorhanden (Erstinstallation), setze current auf $SHA vor dem Start der Container."
    set_current "$SHA"
fi

if [[ "$NEEDS_BUILD" -eq 1 ]]; then
    echo "deploy/vps/php hat sich geaendert, baue Image neu ..."
    "${COMPOSE[@]}" build php
    echo "$PHP_IMAGE_HASH" > "$PHP_IMAGE_HASH_FILE"
else
    echo "deploy/vps/php unveraendert, verwende bestehendes Image."
fi

echo "Starte/aktualisiere Container ..."
"${COMPOSE[@]}" up -d --remove-orphans

echo "Warte auf gesunde Container (bis zu 180 Sekunden) ..."
DEADLINE=$((SECONDS + 180))
while true; do
    UNHEALTHY="$("${COMPOSE[@]}" ps --format '{{.Name}} {{.Health}}' 2>/dev/null | awk '$2!="" && $2!="healthy"{print $1"="$2}')"
    if [[ -z "$UNHEALTHY" ]]; then
        echo "Alle Container mit Healthcheck sind healthy."
        break
    fi
    if (( SECONDS > DEADLINE )); then
        echo "::error:: Zeitueberschreitung beim Warten auf gesunde Container: $UNHEALTHY"
        "${COMPOSE[@]}" logs --tail=100
        run_rollback || true
        exit 1
    fi
    sleep 5
done

# Reihenfolge: zuerst den Symlink umstellen, dann die Migration mit dem NEUEN Code ausfuehren (der
# php-Container arbeitet unter /opt/smarteinzug/releases/current; ein CLI-Aufruf liest die Dateien
# frisch, waehrend php-fpm wegen opcache.validate_timestamps=0 bis zum Reload weiter den alten Code
# ausliefert). Schlaegt die Migration fehl, wird der Symlink zurueckgestellt und nichts neu geladen:
# Webanwendung und Worker laufen unveraendert mit dem alten Code weiter, die Datenbank bleibt im
# Zustand vor der fehlgeschlagenen Migration (Runner markiert sie als failed, keine automatische
# Wiederholung, siehe docs/migrations.md).
echo "Aktiviere Release $SHA (Symlink $CURRENT_LINK) ..."
set_current "$SHA"

echo "Spiele Datenbankmigrationen ein (php-CLI im php-Container mit dem neuen Code, nie oeffentlich erreichbar) ..."
if ! "${COMPOSE[@]}" exec -T php php bin/migrate.php; then
    echo "::error:: Migration fehlgeschlagen. Symlink wird zurueckgestellt, kein Reload; Webanwendung laeuft mit dem alten Code weiter."
    if [[ -n "$PREV_SHA" && -d "$RELEASES_DIR/$PREV_SHA" ]]; then
        set_current "$PREV_SHA"
    fi
    exit 1
fi

echo "$(date -u +%FT%TZ) deploy $SHA" >> "$DEPLOY_DIR/.release_history"
[[ -n "$PREV_SHA" ]] && echo "$PREV_SHA" > "$DEPLOY_DIR/.previous_sha"

echo "Lade php-fpm neu (SIGUSR2, uebernimmt neuen Code ohne Verbindungsabbruch) ..."
"${COMPOSE[@]}" exec -T php kill -USR2 1

# Worker und Scheduler erhalten SIGTERM und bis zu 660 s Zeit (stop_grace_period), damit ein laufender
# Sync-Abschnitt (max. 600 s) sauber abgeschlossen und der Job freigegeben wird.
echo "Starte Scheduler und Worker kontrolliert neu (SIGTERM, laufender Job wird zu Ende gebracht) ..."
BACKGROUND_SERVICES=(scheduler worker-lexware-1 worker-stripe worker-mail worker-maintenance)
if "${COMPOSE[@]}" config --services 2>/dev/null | grep -qx worker-lexware-2; then
    BACKGROUND_SERVICES+=(worker-lexware-2)
fi
"${COMPOSE[@]}" restart -t 660 "${BACKGROUND_SERVICES[@]}"

echo "Health-Check nach der Aktivierung ..."
sleep 5
if ! "${COMPOSE[@]}" exec -T php php bin/healthcheck.php --all; then
    echo "::error:: Health-Check nach der Aktivierung fehlgeschlagen. Automatisches Rollback."
    run_rollback || true
    exit 1
fi

# HTTPS-Pruefung ueber Caddy auf diesem Server (ohne DNS: --resolve zeigt den Hostnamen auf 127.0.0.1).
# Vor dem Cutover kann Let's Encrypt noch kein Zertifikat ausstellen; dann meldet dieser Schritt nur
# eine Warnung (HEALTH_STRICT=false in .env). Nach dem Cutover HEALTH_STRICT=true setzen.
HEALTH_STRICT="$(envval HEALTH_STRICT false)"
HEALTH_DOMAIN="$(envval DOMAIN_APP app.smart-einzug.de)"
if [[ "$DEPLOY_ENV" == "staging" ]]; then
    HEALTH_DOMAIN="$(envval DOMAIN_STAGING "$HEALTH_DOMAIN")"
fi
if curl -fsS --max-time 10 --resolve "${HEALTH_DOMAIN}:443:127.0.0.1" "https://${HEALTH_DOMAIN}/health.php" >/dev/null; then
    echo "HTTPS-Health-Check ueber Caddy (https://${HEALTH_DOMAIN}/health.php, lokal) erfolgreich."
elif [[ "$HEALTH_STRICT" == "true" ]]; then
    echo "::error:: HTTPS-Health-Check ueber Caddy fehlgeschlagen (HEALTH_STRICT=true). Automatisches Rollback."
    run_rollback || true
    exit 1
else
    echo "::warning:: HTTPS-Health-Check ueber Caddy fehlgeschlagen (HEALTH_STRICT=false, nur Hinweis; vor dem Cutover erwartbar, solange kein Zertifikat vorliegt)."
fi

echo "Bereinige alte Releases (behalte die letzten 5) ..."
mapfile -t OLD_RELEASES < <(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | grep -v '/current/$' | tail -n +6 || true)
for old in "${OLD_RELEASES[@]}"; do
    old_sha="$(basename "$old")"
    if [[ "$old_sha" != "$SHA" && "$old_sha" != "$PREV_SHA" && "$old_sha" != "current" ]]; then
        echo "Entferne altes Release $old_sha"
        rm -rf "${old:?}"
    fi
done

echo "[$(date -u +%FT%TZ)] Deployment $SHA abgeschlossen."
