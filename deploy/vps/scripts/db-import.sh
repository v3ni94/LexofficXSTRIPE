#!/usr/bin/env bash
#
# SmartEinzug: Import eines Datenbank-Dumps (z.B. aus dem IONOS-Webhosting-Umzug) in die bereits
# vorhandene Coolify-MariaDB. Der Datenbank-Container veroeffentlicht keinen Port; der Import laeuft
# deshalb ueber "docker exec" in den Container (Containername DB_CONTAINER aus deploy/.env). Die
# Zugangsdaten stammen aus den Umgebungsvariablen, die Coolify dem Container mitgibt (MARIADB_USER,
# MARIADB_PASSWORD, MARIADB_DATABASE); sie werden hier weder abgefragt noch ausgegeben.
#
#   scripts/db-import.sh <dump.sql[.gz]> <dump.sql[.gz].sha256>
#
# Auszufuehren im Verzeichnis, das die aktive .env enthaelt (normalerweise /opt/smarteinzug/deploy).
# Prueft vor dem Import die mitgegebene Pruefsumme, damit ein beschaedigter oder falsch uebertragener
# Dump nicht unbemerkt eingespielt wird. Fuehrt KEINE Migrationen aus (das macht deploy.sh).
set -euo pipefail

DUMP_FILE="${1:?Nutzung: db-import.sh <dump.sql[.gz]> <pruefsummendatei.sha256>}"
SUM_FILE="${2:?Nutzung: db-import.sh <dump.sql[.gz]> <pruefsummendatei.sha256>}"

[[ -f "$DUMP_FILE" ]] || { echo "::error:: Dump-Datei fehlt: $DUMP_FILE"; exit 1; }
[[ -f "$SUM_FILE" ]] || { echo "::error:: Pruefsummendatei fehlt: $SUM_FILE"; exit 1; }
[[ -f ".env" ]] || { echo "::error:: .env fehlt in diesem Verzeichnis (z.B. /opt/smarteinzug/deploy)."; exit 1; }

envval() {
    local key="$1" default="${2:-}" line value
    line="$(grep -E "^[[:space:]]*${key}=" ".env" | tail -n 1 || true)"
    value="${line#*=}"; value="${value%$'\r'}"
    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
    printf '%s' "${value:-$default}"
}
DB_CONTAINER="$(envval DB_CONTAINER)"
if [[ -z "$DB_CONTAINER" || "$DB_CONTAINER" == HIER-* ]]; then
    echo "::error:: DB_CONTAINER in .env nicht gesetzt (Containername der Coolify-MariaDB, siehe docker ps)."
    exit 1
fi
if ! docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "::error:: Container $DB_CONTAINER nicht gefunden (docker ps pruefen)."
    exit 1
fi
if ! docker exec "$DB_CONTAINER" sh -c 'test -n "$MARIADB_USER" && test -n "$MARIADB_PASSWORD" && test -n "$MARIADB_DATABASE"'; then
    echo "::error:: Im Container fehlen MARIADB_USER/MARIADB_PASSWORD/MARIADB_DATABASE (Coolify-Konfiguration der Datenbank pruefen)."
    exit 1
fi
DB_NAME_IN="$(docker exec "$DB_CONTAINER" sh -c 'printf %s "$MARIADB_DATABASE"')"

echo "Pruefe Pruefsumme ..."
if ! sha256sum -c "$SUM_FILE" --ignore-missing; then
    echo "::error:: Pruefsumme stimmt nicht ueberein. Import abgebrochen, Dump erneut uebertragen."
    exit 1
fi
echo "Pruefsumme in Ordnung."

echo "Pruefe Erreichbarkeit der Datenbank im Container ..."
if ! docker exec "$DB_CONTAINER" sh -c 'command -v mariadb-admin >/dev/null && mariadb-admin -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" ping || mysqladmin -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" ping' >/dev/null 2>&1; then
    echo "::error:: Datenbank antwortet nicht (Coolify: Status der Datenbankressource pruefen)."
    exit 1
fi

read -r -p "Import ueberschreibt vorhandene Tabellen in der Datenbank '$DB_NAME_IN' (Container $DB_CONTAINER). Fortfahren? [ja/nein] " CONFIRM
if [[ "${CONFIRM,,}" != "ja" ]]; then
    echo "Abgebrochen."
    exit 0
fi

echo "Spiele Dump ein ..."
if [[ "$DUMP_FILE" == *.gz ]]; then
    gunzip -c "$DUMP_FILE" | docker exec -i "$DB_CONTAINER" sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
else
    docker exec -i "$DB_CONTAINER" sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < "$DUMP_FILE"
fi

echo "Import abgeschlossen. Zum Abgleich mit der Quelle: php scripts/db-verify.php <config.php> (siehe docs/vps/04)."
