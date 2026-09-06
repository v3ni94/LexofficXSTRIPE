#!/usr/bin/env bash
#
# SmartEinzug: Wiederherstellungstest - spielt eine Sicherung in eine TEMPORAERE Datenbank ein und
# zaehlt die Zeilen je Tabelle, ohne die produktive Datenbank zu beruehren. Dient dazu, regelmaessig
# zu belegen, dass Backups tatsaechlich wiederherstellbar sind (nicht nur "Datei vorhanden").
#
# Auf dem Hostinger-VPS laeuft die Datenbank als Coolify-Ressource und Coolify sichert sie (Hetzner Object
# Storage). Dieses Skript ist dann ein zusaetzliches Werkzeug, um einen aus Coolify heruntergeladenen Dump
# in einer temporaeren Datenbank auf Wiederherstellbarkeit zu pruefen. Ausfuehrung in einem kurzlebigen
# Client-Container im Coolify-Netz (kein veroeffentlichter Port noetig), Beispiel:
#
#   docker run --rm -it --network coolify -v /pfad/zum/dump:/dump:ro -v "$PWD/backup:/tools:ro" \
#       -e DB_HOST=<containername-der-coolify-mariadb> -e DB_ROOT_PASSWORD=<root-passwort-aus-coolify> \
#       mariadb:11 bash /tools/restore-test.sh /dump/<datei>.sql.gz
#
# Benoetigt DB_HOST, DB_ROOT_PASSWORD (Rechte zum Anlegen/Loeschen einer Datenbank) in der Umgebung
# des Containers, in dem dieses Skript laeuft; fuer .age-Dateien zusaetzlich age und BACKUP_AGE_IDENTITY.
set -euo pipefail

DUMP_FILE="${1:?Nutzung: restore-test.sh <dump.sql[.gz][.age]>}"
[[ -f "$DUMP_FILE" ]] || { echo "::error:: Datei fehlt: $DUMP_FILE"; exit 1; }

: "${DB_HOST:?DB_HOST fehlt}"
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD fehlt (nur fuer diesen Test, nicht fuer den taeglichen Betrieb noetig)}"

TEST_DB="restore_test_$(date -u +%Y%m%d%H%M%S)"
WORK_FILE="$(mktemp)"
# Zugangsdaten nie auf der Kommandozeile uebergeben (waeren in der Prozessliste sichtbar), sondern
# ueber eine nur fuer diesen Lauf angelegte Optionsdatei mit Rechten 600.
CREDS_FILE="$(mktemp)"
chmod 600 "$CREDS_FILE"
printf '[client]\nhost=%s\nuser=root\npassword=%s\n' "$DB_HOST" "$DB_ROOT_PASSWORD" > "$CREDS_FILE"
MARIADB=(mariadb --defaults-extra-file="$CREDS_FILE")
trap 'rm -f "$WORK_FILE" "$CREDS_FILE"' EXIT

SRC="$DUMP_FILE"
if [[ "$SRC" == *.age ]]; then
    : "${BACKUP_AGE_IDENTITY:?Fuer verschluesselte Dumps wird ein age-Identitaetsschluessel (BACKUP_AGE_IDENTITY, Pfad zur Datei) benoetigt.}"
    age -d -i "$BACKUP_AGE_IDENTITY" -o "${WORK_FILE}.gz" "$SRC" || { echo "::error:: age-Entschluesselung fehlgeschlagen."; exit 1; }
    SRC="${WORK_FILE}.gz"
fi

echo "Lege temporaere Datenbank $TEST_DB an ..."
"${MARIADB[@]}" \
    -e "CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cleanup_db() {
    echo "Entferne temporaere Datenbank $TEST_DB ..."
    "${MARIADB[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB\`;" || true
}
trap 'cleanup_db; rm -f "$WORK_FILE" "${WORK_FILE}.gz" "$CREDS_FILE"' EXIT

echo "Spiele $SRC in $TEST_DB ein ..."
if [[ "$SRC" == *.gz ]]; then
    gunzip -c "$SRC" | "${MARIADB[@]}" "$TEST_DB"
else
    "${MARIADB[@]}" "$TEST_DB" < "$SRC"
fi

echo "Zeilenzahlen je Tabelle in $TEST_DB:"
"${MARIADB[@]}" "$TEST_DB" -N -e "
    SELECT table_name FROM information_schema.tables WHERE table_schema = '$TEST_DB';
" | while read -r table; do
    count="$("${MARIADB[@]}" "$TEST_DB" -N -e "SELECT COUNT(*) FROM \`$table\`;")"
    printf '%-40s %s\n' "$table" "$count"
done

echo "Wiederherstellungstest erfolgreich. Temporaere Datenbank wird jetzt entfernt."
