#!/usr/bin/env bash
#
# SmartEinzug: Import eines Datenbank-Dumps (z.B. aus dem IONOS-Webhosting-Umzug) in den
# mariadb-Container. Prueft vor dem Import die mitgegebene Pruefsumme, damit ein beschaedigter
# oder falsch uebertragener Dump nicht unbemerkt eingespielt wird.
#
#   scripts/db-import.sh <dump.sql[.gz]> <dump.sql[.gz].sha256>
#
# Auszufuehren im Verzeichnis, das die aktiven docker-compose*.yml und .env enthaelt
# (normalerweise /opt/smarteinzug/deploy).
set -euo pipefail

DUMP_FILE="${1:?Nutzung: db-import.sh <dump.sql[.gz]> <pruefsummendatei.sha256>}"
SUM_FILE="${2:?Nutzung: db-import.sh <dump.sql[.gz]> <pruefsummendatei.sha256>}"

[[ -f "$DUMP_FILE" ]] || { echo "::error:: Dump-Datei fehlt: $DUMP_FILE"; exit 1; }
[[ -f "$SUM_FILE" ]] || { echo "::error:: Pruefsummendatei fehlt: $SUM_FILE"; exit 1; }
[[ -f "docker-compose.yml" ]] || { echo "::error:: Bitte im Verzeichnis mit docker-compose.yml ausfuehren (z.B. /opt/smarteinzug/deploy)."; exit 1; }
[[ -f ".env" ]] || { echo "::error:: .env fehlt in diesem Verzeichnis."; exit 1; }

echo "Pruefe Pruefsumme ..."
if ! sha256sum -c "$SUM_FILE" --ignore-missing; then
    echo "::error:: Pruefsumme stimmt nicht ueberein. Import abgebrochen, Dump erneut uebertragen."
    exit 1
fi
echo "Pruefsumme in Ordnung."

# shellcheck disable=SC1091
set -a; source .env; set +a
COMPOSE=(docker compose -f docker-compose.yml -f "${COMPOSE_OVERRIDE:-docker-compose.prod.yml}" --env-file .env)

echo "Warte, bis MariaDB gesund ist ..."
DEADLINE=$((SECONDS + 120))
while ! "${COMPOSE[@]}" exec -T mariadb healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
    if (( SECONDS > DEADLINE )); then
        echo "::error:: MariaDB wurde nicht rechtzeitig gesund."
        exit 1
    fi
    sleep 3
done

read -r -p "Import ueberschreibt vorhandene Tabellen in ${DB_NAME:?}. Fortfahren? [ja/nein] " CONFIRM
if [[ "${CONFIRM,,}" != "ja" ]]; then
    echo "Abgebrochen."
    exit 0
fi

echo "Spiele Dump ein ..."
if [[ "$DUMP_FILE" == *.gz ]]; then
    gunzip -c "$DUMP_FILE" | "${COMPOSE[@]}" exec -T mariadb sh -c \
        'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
else
    "${COMPOSE[@]}" exec -T mariadb sh -c \
        'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < "$DUMP_FILE"
fi

echo "Import abgeschlossen. Zum Abgleich mit der Quelle: php scripts/db-verify.php <config.php>"
