#!/usr/bin/env bash
# SmartEinzug: Hintergrunddienste nach einer Aenderung an shared/config.php neu starten.
#
# Scheduler, Worker und Metrik-Sammler sind Dauerprozesse und lesen shared/config.php nur beim Start
# (app/bootstrap.php). Ein Deployment mit neuem Release erzeugt sie neu; eine reine Konfigurationsaenderung
# (z. B. mail.enabled, status_publish) erreicht sie NICHT, php-fpm dagegen liest je Anfrage. Dieses Skript
# startet genau die Dauerprozesse neu, ohne Release- oder Datenbankwechsel.
#
#   bash /opt/smarteinzug/deploy/scripts/restart-workers.sh
set -euo pipefail
BASE=/opt/smarteinzug
DEPLOY_DIR="$BASE/deploy"
cd "$DEPLOY_DIR"
export RELEASE_SHA="$(basename "$(readlink -f "$BASE/releases/current")")"
DEPLOY_ENV="$(grep -E '^DEPLOY_ENV=' .env | tail -n1 | cut -d= -f2- | tr -d "\"'" || true)"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env)
if [[ "${DEPLOY_ENV:-prod}" == "staging" ]]; then
    COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml --env-file .env)
fi
DIENSTE=(scheduler worker-lexware-1 worker-lexware-2 worker-stripe worker-mail worker-maintenance metrics)
echo "Starte neu (Release $RELEASE_SHA): ${DIENSTE[*]}"
"${COMPOSE[@]}" restart "${DIENSTE[@]}"
echo "Zustand:"
"${COMPOSE[@]}" ps --format '{{.Name}}\t{{.Status}}' 2>/dev/null || "${COMPOSE[@]}" ps
