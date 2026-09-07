#!/usr/bin/env bash
#
# SmartEinzug: Deployment eines bereits per rsync abgelegten Release auf dem VPS.
#
#   bash /opt/smarteinzug/releases/<git-sha>/deploy/vps/scripts/deploy.sh <git-sha>
#
# Der GitHub-Workflow ruft dieses Skript NICHT direkt auf, sondern ueber deploy-runner.sh, der es
# serverseitig von der SSH-Sitzung des Workflows entkoppelt (siehe deploy-runner.sh, Begruendung:
# ein mehrminuetiger Vorgang darf nicht durch einen kurzen SSH-Abbruch abgebrochen werden). Fuer
# manuelle Eingriffe auf dem Server bleibt der direkte Aufruf dieses Skripts unveraendert moeglich
# und weiterhin durch eine eigene Sperre abgesichert (siehe SMARTEINZUG_LOCK_HELD unten).
#
# Der GitHub-Workflow ruft dabei genau die Kopie aus dem NEUEN Release auf, damit Aenderungen an
# diesem Skript sofort wirken; anschliessend liegt dieselbe Fassung auch unter
# /opt/smarteinzug/deploy/scripts/deploy.sh (Handbetrieb).
#
# Voraussetzung: /opt/smarteinzug/releases/<git-sha>/ existiert bereits vollstaendig (Inhalt von
# php-ionos/, per rsync durch den GitHub-Workflow oder von Hand angelegt) UND enthaelt den Ordner
# deploy/vps/ (dieser Ordner). Dieses Skript selbst holt keinen Code, es aktiviert nur ein
# vorhandenes Release.
#
# Ablauf: Sperre -> deploy/vps aus dem Release uebernehmen -> Zustand vorhandener Container protokollieren
# -> Image ggf. neu bauen -> Candidate isoliert pruefen (eigener Container, neuer Code, DB+Redis
# erreichbar, laufende Anwendung unberuehrt) -> Migrationen isoliert mit dem neuen Code einspielen (noch
# VOR dem Cutover; schlaegt einer der beiden Schritte fehl, wurde nichts an den laufenden Containern
# veraendert, kein Rollback noetig) -> Cutover ("docker compose up -d", jetzt sicher: Schema bereits
# migriert) -> auf "healthy" warten -> current-Symlink umstellen (Buchfuehrung) -> php-fpm neu laden ->
# Worker/Scheduler kontrolliert neu starten -> Health-Check -> bei Fehler automatisches Rollback auf das
# vorherige Release (Code UND Symlink). Alte Releases werden auf die letzten 5 begrenzt.
#
# Pfade und Release-Bindung: Die Container binden /opt/smarteinzug/releases nur lesend ein, ihr
# working_dir (siehe docker-compose.yml) ist aber NICHT der mutable Symlink "current", sondern der
# konkrete Pfad /opt/smarteinzug/releases/${RELEASE_SHA}, exportiert von diesem Skript VOR jedem
# "docker compose"-Aufruf. Damit gehoeren Compose-Konfiguration (inklusive Healthchecks), Image und
# Anwendungscode bei JEDEM Containerstart garantiert zum selben Release: Ein Container kann nicht mit
# neuer Compose-Konfiguration (z.B. einem neuen Healthcheck-Modus) starten, waehrend sein working_dir
# noch auf aelteren Code zeigt (das war vor dieser Aenderung ein reales Risiko, wenn "current" waehrend
# eines "docker compose up" noch auf das VORHERIGE Release zeigte). Der Symlink "current" bleibt fuer
# Menschen und Werkzeuge (Doku, readlink, db-import.sh) als Buchfuehrung ueber das zuletzt aktivierte,
# erfolgreich gesundgeprüfte Release bestehen, ist fuer die Korrektheit der Container aber nicht mehr
# erforderlich.
#
# Sperre: Standardmaessig oeffnet dieses Skript die Sperrdatei selbst (Dateideskriptor 9). Wird es von
# deploy-runner.sh aufgerufen, haelt DIESER die Sperre bereits (SMARTEINZUG_LOCK_HELD=1 gesetzt); dieses
# Skript versucht dann NICHT, dieselbe Sperre ein zweites Mal zu erwerben (wuerde sonst immer
# fehlschlagen, da eine Datei-Sperre nicht doppelt vom selben Prozessbaum gehalten werden kann).
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
if [[ "${SMARTEINZUG_LOCK_HELD:-0}" == "1" ]]; then
    echo "Sperre wird bereits vom aufrufenden Prozess gehalten (deploy-runner.sh), kein erneuter Erwerb."
else
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "::error:: Es laeuft bereits ein Deployment oder Rollback (Sperre $LOCK_FILE belegt)."
        exit 1
    fi
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
    if [[ "${SMARTEINZUG_LOCK_HELD:-0}" != "1" ]]; then
        # Eigene Sperre freigeben, sonst kann rollback.sh sie nicht erhalten (dieselbe Sperrdatei).
        flock -u 9 || true
        exec 9>&-
    fi
    # Laeuft dieses Skript unter deploy-runner.sh (SMARTEINZUG_LOCK_HELD=1), haelt DER die Sperre
    # weiterhin (ueber die gesamte Laufzeit inklusive eines etwaigen Rollbacks); rollback.sh wird dann
    # angewiesen, selbst KEINE eigene Sperre zu erwerben, statt an der bereits gehaltenen zu scheitern.
    if [[ -z "$PREV_SHA" || ! -d "$RELEASES_DIR/$PREV_SHA" ]]; then
        echo "::error:: Kein vorheriges Release bekannt, automatisches Rollback nicht moeglich. Manuelle Pruefung erforderlich."
        return 1
    fi
    SMARTEINZUG_LOCK_HELD="${SMARTEINZUG_LOCK_HELD:-0}" bash "$ROLLBACK_SH" "$PREV_SHA"
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

# Strukturierte Fehlermeldung fuer die persistente Logdatei (siehe "exec > >(tee -a "$LOG_FILE")" oben,
# das Protokoll enthaelt diese Zeilen also zuverlaessig): Phase, fehlgeschlagener Befehl, Exitcode,
# Release-SHA und der aktuelle Containerzustand, ohne jemals .env/Secrets auszugeben.
deploy_fail_report() {
    local phase="$1" cmd="${2:-}" rc="${3:-}"
    echo "::error:: Phase=$phase Release-SHA=$SHA Umgebung=$DEPLOY_ENV${cmd:+ Befehl=\"$cmd\"}${rc:+ Exitcode=$rc}"
    echo "Containerzustand (Projekt smarteinzug):"
    "${COMPOSE[@]}" ps -a --format '{{.Name}}\t{{.State}}\t{{.Health}}' 2>/dev/null || echo "  (Zustand nicht abrufbar)"
}

# RELEASE_SHA bindet jeden Container-Start an dieses konkrete Release (working_dir in docker-compose.yml
# lautet /opt/smarteinzug/releases/${RELEASE_SHA}, nicht an den mutable Symlink "current"): Damit
# gehoeren Compose-Konfiguration, Healthchecks und Anwendungscode bei jedem "docker compose"-Aufruf
# untenstehend garantiert zusammen. export wirkt fuer den Rest DIESES Skripts (auch fuer restart/exec).
export RELEASE_SHA="$SHA"
# Zusaetzlich als Datei ablegen: rein informativ fuer manuelle Eingriffe auf dem Server (z.B.
# "set -a; source .release.env; set +a" vor einem Hand-Aufruf von "docker compose logs"); enthaelt
# keine Geheimnisse, nur den Git-SHA.
printf 'RELEASE_SHA=%s\n' "$SHA" > "$DEPLOY_DIR/.release.env"

# Zustand VOR dem Start protokollieren: hilft, einen von einem frueheren abgebrochenen Deployment
# halb erzeugten Stand (Container im Zustand "created", nie gestartet) sichtbar zu machen. "docker
# compose up -d" ist idempotent und bringt einen solchen Stand von selbst in Ordnung (es erzeugt oder
# startet nur, was von der Zieldefinition abweicht); dieses Protokoll dient der Nachvollziehbarkeit,
# nicht der Korrektheit. Ausschliesslich Ressourcen des Compose-Projekts "smarteinzug" (siehe "name:"
# am Kopf von docker-compose.yml); Coolify-Proxy und Coolify-MariaDB gehoeren zu anderen Projekten und
# werden von "docker compose" hier nie angefasst.
echo "Zustand vor dem Start (Projekt smarteinzug):"
"${COMPOSE[@]}" ps -a --format '{{.Name}}\t{{.State}}\t{{.Health}}' 2>/dev/null || echo "  (noch keine Container vorhanden)"

if [[ "$NEEDS_BUILD" -eq 1 ]]; then
    echo "deploy/vps/php hat sich geaendert, baue Image neu ..."
    "${COMPOSE[@]}" build php
    echo "$PHP_IMAGE_HASH" > "$PHP_IMAGE_HASH_FILE"
else
    echo "deploy/vps/php unveraendert, verwende bestehendes Image."
fi

# --- Candidate pruefen und Migrationen einspielen, OHNE die laufende Anwendung anzufassen -----------
# "docker compose run --rm --no-deps" startet einen ZUSAETZLICHEN, eigenstaendigen Container aus
# demselben Image und derselben Konfiguration (working_dir also bereits /opt/smarteinzug/releases/$SHA),
# aber unter einem eigenen Namen, ohne die bereits laufenden Container "php"/"scheduler"/"worker-*" zu
# beruehren. Caddy leitet Anfragen weiterhin an den bisherigen "php"-Dienst weiter, der unveraendert mit
# dem ALTEN Code laeuft, waehrend hier mit dem NEUEN Code geprueft und migriert wird. Erst wenn beides
# erfolgreich war, wird unten der eigentliche Cutover ("up -d") ausgefuehrt. Das garantiert die
# Reihenfolge "Candidate technisch geprueft -> Migration -> Cutover" ohne ein Zeitfenster, in dem der neue
# Code live Anfragen gegen ein noch unmigriertes Schema beantwortet, UND ohne ein Zeitfenster, in dem eine
# Migration gegen ein durch einen abgebrochenen Container-Recreate halb hergestelltes Release liefe:
# Schlaegt einer der beiden Schritte fehl, wurde an den laufenden Containern noch NICHTS veraendert, ein
# Rollback ist dann nicht noetig (die alte Version laeuft unveraendert weiter).
echo "Pruefe den Candidaten isoliert (eigener Container, ohne die laufende Anwendung zu beruehren) ..."
CANDIDATE_CMD='docker compose run --rm --no-deps -T php php bin/healthcheck.php --db --redis'
set +e
"${COMPOSE[@]}" run --rm --no-deps -T php php bin/healthcheck.php --db --redis
CANDIDATE_RC=$?
set -e
if [[ "$CANDIDATE_RC" -ne 0 ]]; then
    deploy_fail_report "candidate-pruefung" "$CANDIDATE_CMD" "$CANDIDATE_RC"
    echo "::error:: Candidate-Pruefung fehlgeschlagen (Datenbank oder Redis mit dem neuen Code nicht erreichbar; genaue Ursache siehe UNGESUND-Zeile oben, z.B. dns/connection_refused/connection/timeout/auth)."
    echo "Die laufenden Container wurden NICHT veraendert, kein Rollback noetig."
    exit 1
fi

echo "Spiele Datenbankmigrationen isoliert mit dem neuen Code ein (noch VOR dem Cutover) ..."
MIGRATE_CMD='docker compose run --rm --no-deps -T php php bin/migrate.php'
set +e
"${COMPOSE[@]}" run --rm --no-deps -T php php bin/migrate.php
MIGRATE_RC=$?
set -e
if [[ "$MIGRATE_RC" -ne 0 ]]; then
    deploy_fail_report "migration" "$MIGRATE_CMD" "$MIGRATE_RC"
    echo "::error:: Migration fehlgeschlagen. Die laufenden Container wurden NICHT veraendert (kein Rollback noetig);"
    echo "die alte Version laeuft mit dem alten Datenbankstand unveraendert weiter. Serverprotokoll pruefen,"
    echo "siehe docs/migrations.md. Keine automatische Wiederholung."
    exit 1
fi

# --- Cutover: laufende Container auf das geprüfte, bereits migrierte Release umstellen ---------------
echo "Migrationen abgeschlossen. Aktiviere Release $SHA (Cutover: Container werden neu erzeugt) ..."
"${COMPOSE[@]}" up -d --remove-orphans

echo "Zustand nach dem Start:"
"${COMPOSE[@]}" ps -a --format '{{.Name}}\t{{.State}}\t{{.Health}}' 2>/dev/null || true

echo "Warte auf gesunde Container (bis zu 180 Sekunden) ..."
DEADLINE=$((SECONDS + 180))
while true; do
    UNHEALTHY="$("${COMPOSE[@]}" ps --format '{{.Name}} {{.Health}}' 2>/dev/null | awk '$2!="" && $2!="healthy"{print $1"="$2}')"
    if [[ -z "$UNHEALTHY" ]]; then
        echo "Alle Container mit Healthcheck sind healthy."
        break
    fi
    if (( SECONDS > DEADLINE )); then
        deploy_fail_report "warte-auf-healthy" "" ""
        echo "::error:: Zeitueberschreitung beim Warten auf gesunde Container: $UNHEALTHY"
        "${COMPOSE[@]}" logs --tail=100
        run_rollback || true
        exit 1
    fi
    sleep 5
done

# Symlink "current" erst NACH dem gesunden Cutover umstellen (reine Buchfuehrung fuer Menschen und
# Werkzeuge wie readlink/db-import.sh, siehe Kopfkommentar; fuer die Korrektheit der Container ohne
# Bedeutung, da deren working_dir bereits ueber RELEASE_SHA an dieses Release gebunden ist). Solange
# dieser Schritt nicht erreicht ist, zeigt "current" weiterhin auf das zuletzt bekannte GUTE Release.
echo "Aktiviere Release $SHA (Symlink $CURRENT_LINK, Buchfuehrung) ..."
set_current "$SHA"

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
set +e
"${COMPOSE[@]}" exec -T php php bin/healthcheck.php --all
POST_HEALTH_RC=$?
set -e
if [[ "$POST_HEALTH_RC" -ne 0 ]]; then
    deploy_fail_report "health-check-nach-aktivierung" "docker compose exec -T php php bin/healthcheck.php --all" "$POST_HEALTH_RC"
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
set +e
curl -fsS --max-time 10 --resolve "${HEALTH_DOMAIN}:443:127.0.0.1" "https://${HEALTH_DOMAIN}/health.php" >/dev/null
HTTPS_CHECK_RC=$?
set -e
if [[ "$HTTPS_CHECK_RC" -eq 0 ]]; then
    echo "HTTPS-Health-Check ueber Caddy (https://${HEALTH_DOMAIN}/health.php, lokal) erfolgreich."
elif [[ "$HEALTH_STRICT" == "true" ]]; then
    deploy_fail_report "https-health-check" "curl https://${HEALTH_DOMAIN}/health.php" "$HTTPS_CHECK_RC"
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
