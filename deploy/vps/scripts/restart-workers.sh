#!/usr/bin/env bash
# SmartEinzug: Aenderung an shared/config.php ohne Deployment wirksam machen.
#
# Zwei Gruende, warum ein blosser "docker compose restart" NICHT reicht:
#  1. shared/config.php ist als EINZELDATEI in die Container gebunden (docker-compose.yml, type: bind).
#     Docker bindet dabei den Inode. Werkzeuge wie sed -i, viele Editoren und "cp datei config.php"
#     schreiben eine NEUE Datei und ersetzen den Inode; laufende und nur neu gestartete Container sehen
#     dann weiter den ALTEN Inhalt (Vorfall 07.09.2026: mail.enabled blieb im Container false, obwohl die
#     Datei auf dem Host true zeigte). Nur ein neu ERZEUGTER Container loest den Pfad neu auf.
#  2. Scheduler, Worker und Metrik-Sammler lesen config.php nur beim Start (app/bootstrap.php); php-fpm
#     haelt sie wegen opcache.validate_timestamps=0 (deploy/vps/php/php.ini) bis zum Reload im Cache.
#
# Ablauf: Syntaxpruefung in einem frischen Container -> Hintergrunddienste neu erzeugen (force-recreate,
# --no-deps) -> php: bei geaendertem Inode neu erzeugen (wenige Sekunden Unterbrechung, Caddy loest
# php:9000 je Anfrage neu auf), sonst nur php-fpm per SIGUSR2 neu laden -> Zustand ausgeben.
# Kein Release- und kein Datenbankwechsel.
#
#   bash /opt/smarteinzug/deploy/scripts/restart-workers.sh
set -euo pipefail
BASE=/opt/smarteinzug
DEPLOY_DIR="$BASE/deploy"
CONFIG="$BASE/shared/config.php"
cd "$DEPLOY_DIR"
export RELEASE_SHA="$(basename "$(readlink -f "$BASE/releases/current")")"
DEPLOY_ENV="$(grep -E '^DEPLOY_ENV=' .env | tail -n1 | cut -d= -f2- | tr -d "\"'" || true)"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env)
if [[ "${DEPLOY_ENV:-prod}" == "staging" ]]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml --env-file .env)
fi
HINTERGRUND=(scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics)

echo "Pruefe Syntax von $CONFIG in einem frischen Container (liest die aktuelle Datei) ..."
if ! "${COMPOSE[@]}" run --rm --no-deps -T php php -l "$CONFIG"; then
    echo "ABBRUCH: config.php hat einen Syntaxfehler. Es wurde kein Dienst angefasst." >&2
    exit 1
fi

echo "Erzeuge Hintergrunddienste neu (Release $RELEASE_SHA): ${HINTERGRUND[*]}"
"${COMPOSE[@]}" up -d --force-recreate --no-deps "${HINTERGRUND[@]}"

host_inode="$(stat -c %i "$CONFIG")"
cont_inode="$("${COMPOSE[@]}" exec -T php stat -c %i "$CONFIG" 2>/dev/null | tr -d '[:space:]' || true)"
if [[ -z "$cont_inode" || "$cont_inode" != "$host_inode" ]]; then
    echo "config.php wurde als neue Datei geschrieben (Inode Host $host_inode, Container ${cont_inode:-unbekannt}): erzeuge den php-Container neu ..."
    "${COMPOSE[@]}" up -d --force-recreate --no-deps php
    deadline=$((SECONDS + 90))
    while (( SECONDS < deadline )); do
        cid="$("${COMPOSE[@]}" ps -q php 2>/dev/null | head -n1)"
        state="$([[ -n "$cid" ]] && docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || true)"
        [[ "$state" == "healthy" ]] && break
        sleep 3
    done
    if [[ "${state:-}" != "healthy" ]]; then
        echo "WARNUNG: php-Container ist nach 90 s nicht healthy (Zustand: ${state:-unbekannt}). Bitte 'docker compose logs php' pruefen." >&2
    fi
else
    echo "Lade php-fpm neu (SIGUSR2, OPcache verwirft die gecachte config.php) ..."
    "${COMPOSE[@]}" exec -T php kill -USR2 1
fi

echo "Zustand:"
"${COMPOSE[@]}" ps --format '{{.Name}}\t{{.Status}}' 2>/dev/null || "${COMPOSE[@]}" ps
echo "Gegenprobe im php-Container (muss den neuen Stand der Datei zeigen; laedt die Konfiguration ueber app/bootstrap.php):"
"${COMPOSE[@]}" exec -T php php bin/mail-check.php 2>/dev/null | sed -n '1,4p' | sed 's/^/  /' || echo "  (Gegenprobe nicht moeglich)"
