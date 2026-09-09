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
# -> Image ggf. neu bauen -> Redis-Infrastruktur ausschliesslich anfassen, wenn sich redis.conf
# gegenueber dem laufenden Release geaendert hat (vorab validieren, NUR den redis-Dienst gezielt neu
# erzeugen, auf healthy warten, Zugriff aus einem ANDEREN Container ueber das interne Netz pruefen;
# schlaegt das fehl, wird ausschliesslich Redis auf die vorherige Konfiguration zurueckgesetzt und das
# Deployment bricht ab, BEVOR die Candidate-Pruefung ueberhaupt beginnt) -> Candidate isoliert pruefen
# (eigener Container, neuer Code, DB+Redis erreichbar, laufende Anwendung unberuehrt) -> Migrationen
# isoliert mit dem neuen Code einspielen (noch VOR dem Cutover; schlaegt einer der beiden Schritte fehl,
# wurde nichts an den laufenden Containern veraendert, kein Rollback noetig) -> Cutover ("docker compose
# up -d", jetzt sicher: Schema bereits migriert) -> auf "healthy" warten -> Release-Bindung verifizieren -> current-Symlink umstellen
# (Buchfuehrung) -> php-fpm neu laden -> Health-Check -> bei
# Fehler automatisches Rollback auf das vorherige Release (Code UND Symlink). Alte Releases werden auf
# die letzten 5 begrenzt.
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
# Vollstaendigkeitsnachweis: Der GitHub-Workflow schreibt .release-complete ERST, nachdem alle
# Uebertragungen (Anwendung, deploy/vps, Statusseite) erfolgreich waren. Fehlt die Datei, ist das Release
# entweder halb uebertragen (abgebrochener rsync, abgebrochener Lauf) oder von Hand angelegt. Beides darf
# nicht ausgeliefert werden: Ein fehlender Migrationsschritt wuerde als "0 offen" gemeldet und neuer Code
# liefe auf altem Schema. SMARTEINZUG_SKIP_RELEASE_CHECK=1 erlaubt einen bewussten Handbetrieb.
if [[ ! -f "$RELEASE_DIR/.release-complete" && "${SMARTEINZUG_SKIP_RELEASE_CHECK:-0}" != "1" ]]; then
    echo "::error:: $RELEASE_DIR/.release-complete fehlt: Das Release ist nicht als vollstaendig"
    echo "::error:: gekennzeichnet (halb uebertragen oder von Hand angelegt). Kein Deployment."
    echo "::error:: Der GitHub-Workflow schreibt diese Datei nach dem letzten rsync. Bei bewusstem"
    echo "::error:: Handbetrieb: SMARTEINZUG_SKIP_RELEASE_CHECK=1 setzen."
    exit 1
fi
if [[ ! -f "$DEPLOY_DIR/.env" ]]; then
    echo "::error:: $DEPLOY_DIR/.env fehlt. Vorlage .env.example einmalig nach .env kopieren und fuellen."
    exit 1
fi

# Downgrade-Schutz (Vorfall 08.09.2026): Ein "Re-run" eines alten GitHub-Laufs deployt dessen alten Commit und
# setzte Produktion so von 4.44 auf 4.38 zurueck, mit gruenen Laeufen. Ein Release mit kleinerer APP_VERSION als
# das aktive wird deshalb abgewiesen; gleiche oder hoehere Version ist erlaubt. Bewusster Ruecksprung: rollback.sh
# oder SMARTEINZUG_ALLOW_DOWNGRADE=1 im Handbetrieb. Logik und Tests: scripts/lib/release-version.sh,
# tools/release-version-check.sh.
RELEASE_VERSION_LIB="$RELEASE_DIR/deploy/vps/scripts/lib/release-version.sh"
if [[ ! -f "$RELEASE_VERSION_LIB" ]]; then
    echo "::warning:: $RELEASE_VERSION_LIB fehlt im Release, kein Downgrade-Schutz fuer diesen Lauf."
elif [[ -L "$CURRENT_LINK" ]]; then
    # shellcheck source=lib/release-version.sh
    source "$RELEASE_DIR/deploy/vps/scripts/lib/release-version.sh"
    AKTIV_DIR="$(readlink -f "$CURRENT_LINK" || true)"
    set +e
    DOWNGRADE_MSG="$(release_downgrade_check "$RELEASE_DIR" "${AKTIV_DIR:-}")"
    DOWNGRADE_RC=$?
    set -e
    if [[ "$DOWNGRADE_RC" -eq 1 ]]; then
        if [[ "${SMARTEINZUG_ALLOW_DOWNGRADE:-0}" == "1" ]]; then
            echo "::warning:: $DOWNGRADE_MSG Downgrade ausdruecklich erlaubt (SMARTEINZUG_ALLOW_DOWNGRADE=1)."
        else
            echo "::error:: $DOWNGRADE_MSG Kein Deployment: Das waere ein Downgrade."
            echo "::error:: Ursache ist meist ein erneut gestarteter ALTER GitHub-Lauf (Re-run deployt dessen alten Commit)."
            echo "::error:: Aktuellen Stand ausrollen: Workflow 'Run workflow' auf dem Branch oder neuer Push. Bewusster"
            echo "::error:: Ruecksprung: rollback.sh, oder SMARTEINZUG_ALLOW_DOWNGRADE=1 im Handbetrieb."
            exit 1
        fi
    else
        echo "Versionspruefung: $DOWNGRADE_MSG"
    fi
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

# Fortschritt in der Statusdatei des Deploy-Runners: Wird dieses Skript von deploy-runner.sh gestartet,
# uebergibt der den Pfad seiner Statusdatei (SMARTEINZUG_STATUS_FILE, /opt/smarteinzug/deploy/
# .deploy-status.json). Hier werden nur die Felder "step" und "updated_at" ATOMAR (Zwischendatei + mv)
# aktualisiert; phase/sha/pid/started_at/log_file bleiben unveraendert und gehoeren dem Runner. Rein
# informativ fuer das GitHub-Polling (zeigt an, in welchem Schritt das Deployment steht); ein Fehler hier
# darf das Deployment nie beeinflussen. Keine Geheimnisse: nur ein Schrittname und ein Zeitstempel.
deploy_step() {
    local step="$1" f="${SMARTEINZUG_STATUS_FILE:-}" tmp
    [[ -n "$f" && -f "$f" ]] || return 0
    command -v jq >/dev/null 2>&1 || return 0
    tmp="$f.tmp.$$"
    # "jq -c": einzeilig und ohne Leerzeichen, im selben Format wie deploy-runner.sh (deploy-status.sh --tail
    # liest log_file daraus; ein mehrzeilig formatiertes JSON fand es waehrend des Laufs nicht). Auch ein
    # fehlgeschlagenes mv darf das Deployment nicht beenden (set -e gilt in diesem Zweig).
    if jq -c --arg s "$step" --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '.step = $s | .updated_at = $t' "$f" > "$tmp" 2>/dev/null; then
        mv -f "$tmp" "$f" 2>/dev/null || rm -f "$tmp"
    else
        rm -f "$tmp"
    fi
    return 0
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

# Gemeinsamer Ordner der veroeffentlichten Statusdaten: Die Anwendung schreibt dorthin (config
# status_publish.file), Caddy liefert /status.json von dort aus (Caddyfile, Status-Host). Der Ordner liegt
# ausserhalb der Releases, damit die Datei einen Releasewechsel ueberlebt; auf Servern, die vor dieser
# Version eingerichtet wurden, fehlt er noch. Ohne eine vorhandene Datei wuerde die Statusseite 404
# liefern, deshalb wird der Platzhalter aus dem Release einmalig hineinkopiert (spaeter nie ueberschrieben).
install -d -m 750 "$BASE/shared/status"
if [[ ! -f "$BASE/shared/status/status.json" && -f "$RELEASE_DIR/status/status.json" ]]; then
    install -m 640 "$RELEASE_DIR/status/status.json" "$BASE/shared/status/status.json"
    echo "Platzhalter fuer die Statusseite nach shared/status/status.json kopiert (noch keine Statusdaten veroeffentlicht)."
fi

deploy_step "release-uebernehmen"
echo "Uebernehme deploy/vps aus dem Release nach $DEPLOY_DIR ..."
# Laufzeitdateien des Deploy-Ordners NIE mitloeschen: Die Muster /.deploy* (Sperre, PID-Datei,
# Statusdatei .deploy-status.json samt ihrer .tmp-Zwischendatei), /.release* (.release_history,
# .release.env), /.previous_sha, /.php-image.sha256 und /.env liegen NICHT im Release und wuerden von
# "--delete" sonst entfernt. Genau das war die Ursache fuer "phase=unknown" waehrend eines laufenden
# Deployments (Version 4.11): deploy-runner.sh hatte die Statusdatei bereits mit "running" geschrieben,
# dieser rsync loeschte sie Sekunden spaeter, deploy-status.sh fand bis zum Ende nichts mehr.
rsync -a --delete --exclude '/.env' --exclude '/.deploy*' --exclude '/.php-image.sha256' \
    --exclude '/.release*' --exclude '/.previous_sha' \
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
    deploy_step "image-build"
    echo "deploy/vps/php hat sich geaendert, baue Image neu ..."
    "${COMPOSE[@]}" build php
    echo "$PHP_IMAGE_HASH" > "$PHP_IMAGE_HASH_FILE"
else
    echo "deploy/vps/php unveraendert, verwende bestehendes Image."
fi

# --- Redis-Infrastruktur: nur anfassen, wenn sich redis.conf gegenueber dem laufenden Release ---------
# tatsaechlich geaendert hat (Bootstrap-Problem, siehe Version 4.8 -> 4.9): Die Candidate-Pruefung
# kommuniziert ueber die REGULAERE, bereits laufende Redis-Ressource; solange deren redis.conf noch der
# ALTEN Fassung entspricht, kann eine redis.conf-Korrektur (z.B. protected-mode) sich nicht selbst
# deployen. redis.conf ist per Bind-Mount eingebunden: "docker compose up -d" erkennt eine reine
# Inhaltsaenderung der Datei NICHT von selbst (die Dienstdefinition - Image, Mount-Quelle, Umgebung -
# bleibt gleich); ein Vergleich der tatsaechlichen Dateiinhalte plus ein gezieltes "--force-recreate
# redis" sind deshalb erforderlich, bevor die Candidate-Pruefung ueberhaupt beginnt.
REDIS_CONF_REL="deploy/vps/redis/redis.conf"
REDIS_CONF_CHANGED=1
if [[ -n "$PREV_SHA" && -f "$RELEASES_DIR/$PREV_SHA/$REDIS_CONF_REL" ]] \
    && cmp -s "$RELEASES_DIR/$PREV_SHA/$REDIS_CONF_REL" "$RELEASE_DIR/$REDIS_CONF_REL"; then
    REDIS_CONF_CHANGED=0
fi
# Zweite Aenderungsquelle: die Compose-DIENSTDEFINITION von redis selbst (Image, Kommando, Netze/Aliase,
# Umgebung). Compose bildet daraus einen Konfigurations-Hash und speichert ihn am Container als Label
# com.docker.compose.config-hash; "docker compose config --hash redis" berechnet denselben Wert fuer die
# neue Konfiguration. Weicht er vom laufenden Container ab (oder gibt es noch keinen), muss redis ebenfalls
# VOR der Candidate-Pruefung kontrolliert neu erzeugt werden, sonst pruefte der Candidate gegen einen Redis
# mit alter Definition (z.B. ohne den Alias smarteinzug-redis) und scheiterte deterministisch mit
# alias_missing, obwohl der spaetere Cutover ihn ohnehin neu erzeugt haette.
# "|| true" in den Zuweisungen: Unter "set -euo pipefail" beendete ein fehlschlagender Compose-Aufruf in
# einer Pipeline das Skript sonst STILL (kein ::error::, kein Rollback); ein leerer Wert wird unten regulaer
# behandelt ("kein Hash lesbar" bzw. "kein Container").
REDIS_DEF_CHANGED=0
REDIS_NEW_HASH="$("${COMPOSE[@]}" config --hash redis 2>/dev/null | awk '$1=="redis"{print $2}' | head -n1 || true)"
REDIS_RUNNING_ID="$("${COMPOSE[@]}" ps -q redis 2>/dev/null | head -n1 || true)"
if [[ -n "$REDIS_NEW_HASH" ]]; then
    if [[ -z "$REDIS_RUNNING_ID" ]]; then
        REDIS_DEF_CHANGED=1
        echo "redis: kein laufender Container (Erststart oder frueher Abbruch), Dienst wird kontrolliert erzeugt."
    else
        REDIS_RUNNING_HASH="$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.config-hash" }}' "$REDIS_RUNNING_ID" 2>/dev/null || true)"
        if [[ "$REDIS_RUNNING_HASH" != "$REDIS_NEW_HASH" ]]; then
            REDIS_DEF_CHANGED=1
            echo "redis: Compose-Dienstdefinition geaendert (Konfigurations-Hash laufend=${REDIS_RUNNING_HASH:-?} neu=$REDIS_NEW_HASH)."
        fi
    fi
fi

# Sicherheitsvoraussetzung fuer "protected-mode no" (siehe redis.conf, Kopfkommentar): kein
# veroeffentlichter Host-Port, ausschliesslich im internen Netz smarteinzug_internal, kein Traefik-Label
# (also nie ueber den Coolify-Proxy erreichbar). Wird bei JEDEM Deployment geprueft, nicht nur bei einer
# Aenderung an redis.conf, da diese Eigenschaften aus den Compose-Dateien stammen, nicht aus redis.conf.
redis_isolation_ok() {
    local cfg
    cfg="$("${COMPOSE[@]}" config redis --format json 2>/dev/null)" || {
        echo "::error:: Konnte die aufgeloeste Redis-Konfiguration nicht lesen (docker compose config redis)."
        return 1
    }
    if echo "$cfg" | jq -e '.services.redis.ports // [] | length > 0' >/dev/null 2>&1; then
        echo "::error:: redis-Dienst hat einen veroeffentlichten Host-Port. Mit 'protected-mode no' waere Redis dann ohne jeden Schutz von aussen erreichbar."
        return 1
    fi
    local nets
    nets="$(echo "$cfg" | jq -r '.services.redis.networks | keys[]' 2>/dev/null | sort | tr '\n' ',')"
    if [[ "$nets" != "smarteinzug_internal," ]]; then
        echo "::error:: redis-Dienst haengt an unerwarteten Netzen ($nets), erwartet ausschliesslich smarteinzug_internal (kein coolify-Netz)."
        return 1
    fi
    if echo "$cfg" | jq -e '(.services.redis.labels // {}) | keys[] | select(startswith("traefik."))' >/dev/null 2>&1; then
        echo "::error:: redis-Dienst traegt Traefik-Labels und waere damit ueber den Coolify-Proxy erreichbar."
        return 1
    fi
    return 0
}

# Wartet, bis der redis-Dienst (und nur dieser) healthy ist; Rueckgabe 1 bei Zeitueberschreitung.
redis_wait_healthy() {
    # REDIS_WAIT_HEALTHY_SECONDS: nur fuer Regressionstests (tools/redis-deploy-check.sh) ohne echten
    # Docker-Daemon relevant, um den Zeitueberschreitungs-Pfad in wenigen Sekunden statt 90s zu pruefen;
    # im echten Betrieb bleibt es bei 90s (Vorgabewert).
    local deadline=$((SECONDS + ${REDIS_WAIT_HEALTHY_SECONDS:-90})) unhealthy
    local lines
    while true; do
        # "ps -a": auch ein beendeter/abgestuerzter Container erscheint (sonst wuerde ihn Compose ausblenden
        # und eine LEERE Ausgabe faelschlich wie "alles gesund" wirken). Gesund heisst: mindestens eine
        # Zeile UND jede Zeile mit Health "healthy".
        lines="$("${COMPOSE[@]}" ps -a redis --format '{{.Name}} {{.Health}}' 2>/dev/null)"
        unhealthy="$(printf '%s\n' "$lines" | awk 'NF{ if ($2!="healthy") print $1"="($2==""?"kein-healthcheck":$2) }')"
        if [[ -n "$lines" && -z "$unhealthy" ]]; then
            return 0
        fi
        [[ -z "$lines" ]] && unhealthy="kein-redis-container"
        
        if (( SECONDS > deadline )); then
            echo "::error:: Zeitueberschreitung beim Warten auf gesundes Redis: $unhealthy"
            "${COMPOSE[@]}" logs redis --tail=80 || true
            return 1
        fi
        sleep 3
    done
}

# Netzwerkbasierter Redis-Test AUS EINEM ANDEREN CONTAINER ueber das interne Docker-Netz - ausdruecklich
# NICHT "docker exec redis redis-cli ping": Dieser Befehl laeuft ueber Redis' EIGENE Loopback-Adresse und
# haette genau das urspruengliche protected-mode-Problem verborgen (siehe Version 4.8). Verwendet wird
# IMMER der Code des NEUEN Release ($SHA, ueber das bereits exportierte RELEASE_SHA): Nur er kennt den vom
# Stack gesetzten eindeutigen Hostnamen SMARTEINZUG_REDIS_HOST (ein aelteres Release wuerde weiter den
# mehrdeutigen Namen "redis" verwenden und damit Coolifys Redis treffen, siehe Version 4.10) und liefert
# die stufenweise DIAGNOSE-Zeile (Aufloesung, erwartetes Netz, TCP, Protokoll) ins Protokoll. Ein
# Fehlschlag hier bedeutet: Redis ist fuer den neuen Code aus dem internen Netz nicht nutzbar - egal, ob
# die Ursache in redis.conf, im Netz oder im Alias liegt; die DIAGNOSE-Zeile benennt die Stufe.
redis_network_probe() {
    local rc
    set +e
    "${COMPOSE[@]}" run --rm --no-deps -T php php bin/healthcheck.php --redis
    rc=$?
    set -e
    return "$rc"
}

# Stellt AUSSCHLIESSLICH deploy/vps/redis/ aus dem vorherigen Release wieder her (nicht den gesamten
# deploy/vps-Baum: das laufende deploy.sh/rollback.sh dieses Releases soll fuer die Fehlerausgabe/den
# Abbruch unten unveraendert nutzbar bleiben). Rueckgabe 1, wenn kein vorheriges Release bekannt ist.
restore_redis_conf_from_prev() {
    if [[ -z "$PREV_SHA" || ! -d "$RELEASES_DIR/$PREV_SHA/deploy/vps/redis" ]]; then
        return 1
    fi
    rsync -a --delete "$RELEASES_DIR/$PREV_SHA/deploy/vps/redis/" "$DEPLOY_DIR/redis/"
}

if ! redis_isolation_ok; then
    deploy_fail_report "redis-isolationspruefung" "" ""
    echo "::error:: Redis verletzt die Netzwerk-Isolationsvorgaben (siehe oben). Abbruch vor jeder Aenderung."
    exit 1
fi

if [[ "$REDIS_CONF_CHANGED" -eq 0 && "$REDIS_DEF_CHANGED" -eq 0 ]]; then
    echo "redis.conf und Compose-Definition von redis unveraendert gegenueber dem laufenden Stand (${PREV_SHA:-keins}), Redis wird nicht angefasst."
else
    deploy_step "redis-infrastruktur"
    if [[ "$REDIS_CONF_CHANGED" -eq 1 ]]; then
        echo "redis.conf hat sich gegenueber dem laufenden Release geaendert (oder es gibt kein vorheriges Release)."
    fi
    echo "Aktualisiere ausschliesslich den Redis-Dienst kontrolliert, VOR der Candidate-Pruefung ..."

    echo "Validiere die neue redis.conf vorab in einem eigenstaendigen Wegwerfcontainer (kein Compose-Projekt, kein Netz) ..."
    REDIS_IMAGE="$("${COMPOSE[@]}" config --images redis 2>/dev/null | head -n1 || true)"
    set +e
    VALIDATE_OUT="$(timeout -k 1 3 docker run --rm --entrypoint redis-server \
        -v "$DEPLOY_DIR/redis/redis.conf:/redis.conf:ro" "$REDIS_IMAGE" /redis.conf --port 16399 2>&1)"
    VALIDATE_RC=$?
    set -e
    # "timeout" beendet einen erfolgreich GESTARTETEN (also gueltigen) Redis-Server nach 3s zwangsweise
    # (Exit-Code 124/137/143 je nach Signalweiterleitung durch Docker); ein SOFORTIGER Fehlschlag mit
    # einer erkennbaren Parse-/Fatal-Meldung zeigt dagegen eine ungueltige Konfiguration an.
    REDIS_UPDATE_FAILED=0
    if [[ "$VALIDATE_RC" -eq 1 ]] && echo "$VALIDATE_OUT" | grep -qiE "Fatal error|Bad directive|can.t open|Errors trying to open|FATAL CONFIG"; then
        echo "::error:: Neue redis.conf ist ungueltig: $VALIDATE_OUT"
        REDIS_UPDATE_FAILED=1
    fi

    if [[ "$REDIS_UPDATE_FAILED" -eq 0 ]]; then
        echo "Aktualisiere ausschliesslich den Redis-Dienst (force-recreate, --no-deps: keine anderen Dienste betroffen) ..."
        set +e
        "${COMPOSE[@]}" up -d --no-deps --force-recreate redis
        RECREATE_RC=$?
        set -e
        if [[ "$RECREATE_RC" -ne 0 ]]; then
            echo "::error:: 'docker compose up -d --no-deps --force-recreate redis' schlug fehl (Exitcode $RECREATE_RC)."
            REDIS_UPDATE_FAILED=1
        elif ! redis_wait_healthy; then
            REDIS_UPDATE_FAILED=1
        else
            echo "Redis mit der neuen Konfiguration ist healthy. Pruefe den Zugriff aus einem ANDEREN Container ueber das interne Netz (nicht per docker exec/Loopback) ..."
            if ! redis_network_probe; then
                echo "::error:: Redis ist zwar 'healthy', aber ueber das interne Docker-Netz aus einem anderen Container NICHT nutzbar (Stufe und Kategorie siehe DIAGNOSE-Zeile oben; genau der Fehler, den 'docker exec redis redis-cli ping' verborgen haette)."
                REDIS_UPDATE_FAILED=1
            else
                echo "Netzwerkbasierter Redis-Test aus einem anderen Container erfolgreich."
            fi
        fi
    fi

    if [[ "$REDIS_UPDATE_FAILED" -eq 1 ]]; then
        deploy_fail_report "redis-infrastruktur-aktualisierung" "docker compose up -d --no-deps --force-recreate redis" ""
        echo "Setze die Redis-Infrastruktur auf das vorherige Release zurueck ..."
        # Bewertung des Rollbacks in zwei Stufen (siehe docs/vps/06-betrieb.md, Version 4.10):
        #  - TECHNISCHE Wiederherstellung (harte Kriterien): alte redis.conf zurueckkopiert, redis-Dienst
        #    neu erzeugt, redis-Dienst healthy. Nur wenn das fehlschlaegt, ist der Rollback gescheitert.
        #  - FACHLICHE Erreichbarkeit aus einem anderen Container: nur eine WARNUNG. Der vorherige Zustand
        #    kann den Netzwerktest bekanntermassen nicht bestehen (z.B. protected-mode yes oder der
        #    mehrdeutige Hostname "redis"), genau deshalb wurde ja ein neues Release ausgeliefert. Das
        #    ist der bekannte Altzustand, kein Fehlschlag der Wiederherstellung.
        if restore_redis_conf_from_prev; then
            set +e
            "${COMPOSE[@]}" up -d --no-deps --force-recreate redis
            RESTORE_RC=$?
            set -e
            if [[ "$RESTORE_RC" -eq 0 ]] && redis_wait_healthy; then
                if [[ "$REDIS_CONF_CHANGED" -eq 1 ]]; then
                    echo "Redis-Infrastruktur TECHNISCH erfolgreich auf die vorherige Konfiguration zurueckgesetzt (redis.conf wiederhergestellt, Dienst neu erzeugt, healthy)."
                else
                    # Nur die Compose-DEFINITION hatte sich geaendert: Sie stammt aus den Compose-Dateien des neuen
                    # Release im Deploy-Ordner und wird von diesem Skript nicht zurueckgebaut. Ehrlich melden.
                    echo "Redis-Dienst neu erzeugt und healthy. HINWEIS: redis.conf war unveraendert; die geaenderte Compose-Definition von redis kann dieses Skript nicht zurueckbauen, Redis laeuft mit der NEUEN Definition. Erreichen die laufenden Container Redis damit nicht, stellt 'rollback.sh ${PREV_SHA}' auch die Compose-Definition wieder her."
                fi
                if redis_network_probe; then
                    echo "Redis ist mit der vorherigen Konfiguration aus einem anderen Container erreichbar."
                else
                    echo "::warning:: Redis ist mit der vorherigen Konfiguration aus einem anderen Container weiterhin NICHT nutzbar (Ursache: siehe DIAGNOSE-Zeile oben; entspricht dem Zustand VOR diesem Deployment). Der Rollback selbst ist technisch erfolgreich; die Anwendung arbeitet wie zuvor mit dem Datenbank-Fallback ohne Redis."
                fi
            else
                echo "::error:: Wiederherstellung der vorherigen Redis-Konfiguration TECHNISCH fehlgeschlagen (Recreate-Exitcode $RESTORE_RC oder Dienst nicht healthy). Manuelle Pruefung auf dem Server erforderlich (docker compose logs redis)."
            fi
        else
            echo "::error:: Kein vorheriges Release fuer eine Redis-Wiederherstellung bekannt (Ersteinrichtung?). Manuelle Pruefung erforderlich."
        fi
        echo "::error:: Redis-Infrastrukturaenderung fehlgeschlagen. Deployment abgebrochen VOR der Candidate-Pruefung: keine Migration, kein Cutover. Die laufende Anwendung (ausser Redis) wurde nicht veraendert."
        exit 1
    fi
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
# --expect-env=$DEPLOY_ENV: Die Candidate-Konfiguration muss zum hier laufenden Deployment passen (siehe
# app/config.example.php, Feld "environment"). Schuetzt vor einem versehentlichen Staging-Deploy gegen
# die Produktionskonfiguration, falls Staging jemals auf demselben Host wie Produktion mit derselben
# config.php eingerichtet wuerde (siehe docs/vps/06-betrieb.md, Abschnitt "Staging- und
# Produktionsisolation").
deploy_step "candidate"
echo "Pruefe den Candidaten isoliert (eigener Container, ohne die laufende Anwendung zu beruehren) ..."
CANDIDATE_CMD="docker compose run --rm --no-deps -T php php bin/healthcheck.php --db --redis --expect-env=$DEPLOY_ENV"
set +e
"${COMPOSE[@]}" run --rm --no-deps -T php php bin/healthcheck.php --db --redis --expect-env="$DEPLOY_ENV"
CANDIDATE_RC=$?
set -e
if [[ "$CANDIDATE_RC" -ne 0 ]]; then
    deploy_fail_report "candidate-pruefung" "$CANDIDATE_CMD" "$CANDIDATE_RC"
    echo "::error:: Candidate-Pruefung fehlgeschlagen (Datenbank/Redis mit dem neuen Code nicht erreichbar oder falsche Umgebung in der Konfiguration; genaue Ursache siehe UNGESUND-Zeile oben, z.B. dns/connection_refused/connection/timeout/auth/environment)."
    echo "Die laufenden Container wurden NICHT veraendert, kein Rollback noetig."
    exit 1
fi

deploy_step "migration"
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
deploy_step "cutover"
# Uebergangshilfe fuer Container mit VERALTETER Stop-Konfiguration (wirksam beim ersten Deployment ab
# Version 4.11 und nach jedem Rollback auf ein aelteres Release, sonst ohne Wirkung): Docker stoppt einen
# Container beim Neuerzeugen durch "up -d" mit StopSignal und StopTimeout des LAUFENDEN Containers, nicht
# mit den Werten der neuen Definition. Die Container der Versionen bis 4.10 tragen STOPSIGNAL SIGQUIT (aus
# dem Basisimage) und StopTimeout 660 s; ihr Code behandelt SIGQUIT nicht, der Cutover haette damit nochmals
# bis zu 11 Minuten gedauert und die 12-Minuten-Frist des GitHub-Pollings gerissen. Solche Container werden
# deshalb vorab gezielt mit SIGTERM (vom alten Code behandelt: kein neuer Job, Ende nach dem laufenden Job)
# und einer Frist von 90 s beendet; ein danach noch laufender Job wird ueber heartbeat_ttl regulaer
# freigegeben. "docker stop" verhindert zugleich den sofortigen Neustart durch "restart: unless-stopped";
# "up -d" erzeugt die Dienste anschliessend mit der neuen Stop-Konfiguration (SIGTERM, 75 s bzw. 20 s).
# php-fpm, Caddy und Redis sind bewusst nicht betroffen (ihre Stop-Signale sind korrekt).
#
# "docker stop --signal" gibt es erst ab Docker CLI 23. Statt eine Mindestversion vorauszusetzen (die auf
# dem Coolify-Host niemand von Hand pruefen soll), fragt dieses Skript die Faehigkeit selbst ab und weicht
# sonst auf den seit Langem verfuegbaren Weg aus: "docker kill --signal SIGTERM" (sendet nur das Signal,
# beendet den Container nicht sofort), danach warten, bis die Prozesse selbst enden, hoechstens dieselben
# 90 s. Ein danach noch laufender Container wird mit kurzer Frist regulaer gestoppt, damit "up -d" nicht
# erneut auf die alte Frist von 660 s wartet.
LEGACY_STOP_IDS=()
for svc in scheduler worker-lexware-1 worker-lexware-2 worker-sevdesk worker-stripe worker-mail worker-maintenance metrics; do
    cid="$("${COMPOSE[@]}" ps -q "$svc" 2>/dev/null | head -n1 || true)"
    [[ -n "$cid" ]] || continue
    stopcfg="$(docker inspect --format '{{.Config.StopSignal}} {{.Config.StopTimeout}}' "$cid" 2>/dev/null || true)"
    stopsig="${stopcfg%% *}"; stoptmo="${stopcfg#* }"
    # Leeres Signal = Docker-Vorgabe SIGTERM; StopTimeout "<nil>" = Docker-Vorgabe 10 s.
    [[ "$stoptmo" =~ ^[0-9]+$ ]] || stoptmo=10
    if [[ -n "$stopsig" && "$stopsig" != "SIGTERM" && "$stopsig" != "TERM" && "$stopsig" != "15" ]] || (( stoptmo > 120 )); then
        echo "  $svc: veraltete Stop-Konfiguration (Signal ${stopsig:-SIGTERM}, Frist ${stoptmo} s), wird vorab kontrolliert mit SIGTERM beendet."
        LEGACY_STOP_IDS+=("$cid")
    fi
done
# Warten, bis keiner der uebergebenen Container mehr laeuft; Rueckgabe 1 bei Zeitueberschreitung.
legacy_wait_stopped() { # $1 = Sekunden, danach die Container-IDs
    local limit="$1" deadline running cid
    shift
    deadline=$((SECONDS + limit))
    while (( SECONDS <= deadline )); do
        running=0
        for cid in "$@"; do
            [[ "$(docker inspect --format '{{.State.Running}}' "$cid" 2>/dev/null || echo false)" == "true" ]] && running=$((running + 1))
        done
        (( running == 0 )) && return 0
        sleep 2
    done
    return 1
}

if (( ${#LEGACY_STOP_IDS[@]} > 0 )); then
    echo "Beende ${#LEGACY_STOP_IDS[@]} Hintergrund-Container mit veralteter Stop-Konfiguration vorab (SIGTERM, Frist 90 s) ..."
    LEGACY_STOP_WARN="::warning:: Vorab-Stopp der Container mit veralteter Stop-Konfiguration schlug fehl; 'up -d' beendet sie mit ihrer alten Konfiguration (der Cutover kann entsprechend laenger dauern)."
    if docker stop --help 2>/dev/null | grep -q -- '--signal'; then
        docker stop --signal SIGTERM --timeout 90 "${LEGACY_STOP_IDS[@]}" >/dev/null \
            || echo "$LEGACY_STOP_WARN"
    else
        echo "Diese Docker-CLI kennt 'stop --signal' nicht (aelter als Version 23); Ausweichweg: 'kill --signal SIGTERM' und warten."
        if docker kill --signal SIGTERM "${LEGACY_STOP_IDS[@]}" >/dev/null 2>&1; then
            # LEGACY_STOP_WAIT_SECONDS: nur fuer Regressionstests ohne echten Docker-Daemon
            # (tools/redis-deploy-check.sh), im Betrieb bleibt es bei 90 s.
            if legacy_wait_stopped "${LEGACY_STOP_WAIT_SECONDS:-90}" "${LEGACY_STOP_IDS[@]}"; then
                echo "Alle vorab beendeten Container haben sich selbst beendet."
            else
                echo "::warning:: Nicht alle Container endeten innerhalb von ${LEGACY_STOP_WAIT_SECONDS:-90} s nach SIGTERM; sie werden jetzt mit kurzer Frist gestoppt (ein laufender Job wird nach heartbeat_ttl regulaer freigegeben)."
                docker stop --time 5 "${LEGACY_STOP_IDS[@]}" >/dev/null 2>&1 || echo "$LEGACY_STOP_WARN"
            fi
        else
            echo "$LEGACY_STOP_WARN"
        fi
    fi
fi
echo "Migrationen abgeschlossen. Aktiviere Release $SHA (Cutover: Container werden neu erzeugt) ..."
# Der Cutover ist der einzige Schritt, der den laufenden Betrieb tatsaechlich veraendert. Er lief bis 4.51
# ungeschuetzt unter "set -e": Scheiterte "up -d" (Beispiel: ein Dienst wird wegen depends_on nicht gesund,
# ein Restcontainer belegt einen Namen, das Image fehlt), brach das Skript sofort ab. Zurueck blieb ein halb
# erneuerter Stack, ohne Rollback und ohne die Fehlermeldung in der Statusdatei. Deshalb wie jeder andere
# riskante Schritt: set +e, Exitcode auswerten, Bericht schreiben, Rollback ausloesen.
set +e
"${COMPOSE[@]}" up -d --remove-orphans
CUTOVER_RC=$?
set -e
if [[ "$CUTOVER_RC" -ne 0 ]]; then
    deploy_fail_report "cutover" "docker compose up -d --remove-orphans" "$CUTOVER_RC"
    echo "::error:: Cutover fehlgeschlagen (Exitcode $CUTOVER_RC). Automatisches Rollback auf das vorherige Release."
    "${COMPOSE[@]}" logs --tail=100 || true
    run_rollback || true
    exit 1
fi

echo "Zustand nach dem Start:"
"${COMPOSE[@]}" ps -a --format '{{.Name}}\t{{.State}}\t{{.Health}}' 2>/dev/null || true

deploy_step "warte-healthy"
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

# KEIN zweiter Neustart von Scheduler und Workern mehr (frueher "restart -t 660", das waren bis zu
# 11 Minuten je Neustart, Version 4.11). Beweis der Entbehrlichkeit: working_dir jedes PHP-Containers ist
# /opt/smarteinzug/releases/${RELEASE_SHA} (docker-compose.yml, x-php-common) und damit Teil der
# Compose-Dienstdefinition und ihres Konfigurations-Hash (Label com.docker.compose.config-hash). Aendert
# sich RELEASE_SHA, erzeugt "docker compose up -d" den Container zwingend neu, mit dem neuen Code als
# working_dir; laeuft er bereits mit genau diesem Release (Wiederholung desselben SHA), gibt es nichts, was
# ein Neustart laden koennte. Statt blind neu zu starten, wird die Release-Bindung deshalb VERIFIZIERT:
# Jeder Container aus dem PHP-Image muss laufen und working_dir dieses Release tragen, sonst Rollback.
# Die Verifikation laeuft VOR der Aktivierung des Symlinks "current": Schlaegt sie fehl und wird auch
# der Rollback verweigert, zeigt die Buchfuehrung weiterhin auf das zuletzt bekannte GUTE Release.
echo "Verifiziere die Release-Bindung aller PHP-Container (working_dir = $RELEASES_DIR/$SHA) ..."
RELEASE_BOUND_SERVICES=(php scheduler worker-lexware-1 worker-stripe worker-mail worker-maintenance metrics)
for optional_svc in worker-lexware-2 worker-sevdesk; do
    if "${COMPOSE[@]}" config --services 2>/dev/null | grep -qx "$optional_svc"; then
        RELEASE_BOUND_SERVICES+=("$optional_svc")
    fi
done
BINDING_ERRORS=0
for svc in "${RELEASE_BOUND_SERVICES[@]}"; do
    # "|| true": ein fehlschlagender Compose-Aufruf darf das Skript hier (nach dem Cutover!) nicht still
    # beenden, sondern muss als "kein Container" in den Rollback-Pfad unten laufen.
    cid="$("${COMPOSE[@]}" ps -q "$svc" 2>/dev/null | head -n1 || true)"
    if [[ -z "$cid" ]]; then
        echo "::error:: Dienst $svc: kein Container gefunden."
        BINDING_ERRORS=$((BINDING_ERRORS + 1))
        continue
    fi
    wd="$(docker inspect --format '{{.Config.WorkingDir}}' "$cid" 2>/dev/null || true)"
    state="$(docker inspect --format '{{.State.Status}}' "$cid" 2>/dev/null || true)"
    if [[ "$wd" != "$RELEASES_DIR/$SHA" || "$state" != "running" ]]; then
        echo "::error:: Dienst $svc: working_dir=${wd:-?} Zustand=${state:-?}, erwartet $RELEASES_DIR/$SHA und running."
        BINDING_ERRORS=$((BINDING_ERRORS + 1))
    else
        echo "  $svc: $wd ($state)"
    fi
done
if (( BINDING_ERRORS > 0 )); then
    deploy_fail_report "release-bindung" "docker inspect --format {{.Config.WorkingDir}} ($BINDING_ERRORS Dienste abweichend)" ""
    echo "::error:: Nicht alle PHP-Container laufen mit dem neuen Release. Automatisches Rollback."
    run_rollback || true
    exit 1
fi

# Symlink "current" erst NACH dem gesunden Cutover umstellen (reine Buchfuehrung fuer Menschen und
# Werkzeuge wie readlink/db-import.sh, siehe Kopfkommentar; fuer die Korrektheit der Container ohne
# Bedeutung, da deren working_dir bereits ueber RELEASE_SHA an dieses Release gebunden ist) und erst NACH
# der bestandenen Verifikation der Release-Bindung (oben). Solange dieser Schritt nicht erreicht ist,
# zeigt "current" weiterhin auf das zuletzt bekannte GUTE Release.
deploy_step "aktivierung"
echo "Aktiviere Release $SHA (Symlink $CURRENT_LINK, Buchfuehrung) ..."
set_current "$SHA"

echo "$(date -u +%FT%TZ) deploy $SHA" >> "$DEPLOY_DIR/.release_history"
[[ -n "$PREV_SHA" ]] && echo "$PREV_SHA" > "$DEPLOY_DIR/.previous_sha"

# php-fpm-Reload (SIGUSR2): Nach dem Cutover ist der php-Container bereits mit dem neuen Release neu
# erzeugt; der Reload ist dann wirkungslos, aber sofort erledigt und ohne Risiko. Er bleibt fuer den Fall,
# dass derselbe SHA erneut ausgerollt wird (kein Recreate, z.B. nach einer Aenderung an shared/config.php):
# dann uebernimmt php-fpm geaenderte Konfiguration/OPcache ohne Verbindungsabbruch.
echo "Lade php-fpm neu (SIGUSR2, ohne Verbindungsabbruch) ..."
"${COMPOSE[@]}" exec -T php kill -USR2 1


deploy_step "health-check"
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

deploy_step "bereinigung"
# Erst die Reste abgebrochener Laeufe entfernen: Bricht ein Workflow-Lauf zwischen "Zielverzeichnis
# anlegen" und dem letzten rsync ab (Netzfehler, abgebrochener Lauf), bleibt ein leeres oder halbes
# Releaseverzeichnis OHNE .release-complete liegen. Es ist nie auslieferbar (deploy.sh verweigert es),
# wuerde aber einen der fuenf aufbewahrten Plaetze belegen und damit die Rollbacktiefe verringern.
# Ausgenommen sind das aktuelle und das vorherige Release sowie Verzeichnisse der letzten Stunde
# (dort koennte gerade ein paralleler Lauf uebertragen).
# Dokumentationsstand archivieren (Historie unabhaengig von GitHub-Artefakten): Kopie von app/docs-build nach
# shared/docs-archive/<Version>_<sha>/, nur wenn ein Manifest vorliegt; die letzten 20 Staende bleiben erhalten.
if [[ -f "$RELEASE_DIR/app/docs-build/manifest.json" ]]; then
    DOCS_VERSION="$(sed -n "s/.*\"version\": *\"\([^\"]*\)\".*/\1/p" "$RELEASE_DIR/app/docs-build/manifest.json" | head -n1)"
    DOCS_ARCHIVE="$BASE/shared/docs-archive/${DOCS_VERSION:-unbekannt}_${SHA:0:12}"
    if [[ ! -d "$DOCS_ARCHIVE" ]]; then
        install -d -m 750 "$BASE/shared/docs-archive"
        cp -a "$RELEASE_DIR/app/docs-build" "$DOCS_ARCHIVE.tmp" && mv "$DOCS_ARCHIVE.tmp" "$DOCS_ARCHIVE" \
            && echo "Dokumentationsstand archiviert: $DOCS_ARCHIVE" || echo "::warning:: Dokumentationsstand konnte nicht archiviert werden."
        ls -1dt "$BASE"/shared/docs-archive/*/ 2>/dev/null | tail -n +21 | xargs -r rm -rf
    fi
else
    echo "Hinweis: Release ohne Dokumentationsbuild (app/docs-build/manifest.json fehlt), kein Archiv."
fi

echo "Entferne Reste abgebrochener Laeufe (Releases ohne Vollstaendigkeitsnachweis) ..."
while IFS= read -r rest; do
    [[ -n "$rest" ]] || continue
    rest_sha="$(basename "$rest")"
    [[ "$rest_sha" == "current" || "$rest_sha" == "$SHA" || "$rest_sha" == "$PREV_SHA" ]] && continue
    [[ -f "$rest/.release-complete" ]] && continue
    if [[ -n "$(find "$rest" -maxdepth 0 -mmin -60 2>/dev/null)" ]]; then
        echo "  $rest_sha: ohne Nachweis, aber juenger als eine Stunde (moeglicher paralleler Lauf), bleibt erhalten."
        continue
    fi
    # Altreleases aus der Zeit VOR dem Nachweis (bis 4.16) tragen keine Markerdatei, sind aber vollstaendig und
    # waren produktiv aktiv. Sie sind Rollback-Ziele und werden nachtraeglich gekennzeichnet statt geloescht.
    # Geloescht wird nur, was erkennbar unvollstaendig ist (App, Deploy-Skripte oder Statusseite fehlen).
    if [[ -f "$rest/app/bootstrap.php" && -f "$rest/deploy/vps/scripts/deploy.sh" && -d "$rest/status" ]]; then
        printf '%s\n%s\n%s\n' "$rest_sha" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "legacy" > "$rest/.release-complete"
        echo "  $rest_sha: vollstaendiges Altrelease ohne Nachweis, nachtraeglich gekennzeichnet (bleibt Rollback-Ziel)."
        continue
    fi
    echo "  Entferne unvollstaendiges Release $rest_sha (kein .release-complete, Bestand unvollstaendig)"
    rm -rf "${rest:?}"
done < <(find "$RELEASES_DIR" -maxdepth 1 -mindepth 1 -type d 2>/dev/null || true)

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
