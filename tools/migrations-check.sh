#!/usr/bin/env bash
# Migrationen gegen den echten VORZUSTAND pruefen (Version 4.40, nach den Laeufen #73/#74):
#   1. Datenbank A aus dem schema.sql des Commits VOR der aeltesten kuerzlich hinzugefuegten Migrationsdatei aufbauen
#      (Standard: Migrationen der letzten 10 Commits; ohne neue Datei: Vorgaenger-Commit, dann pruefen nur Idempotenz).
#   2. bin/migrate.php mit dem AKTUELLEN Code gegen A laufen lassen: alle neuen Migrationen werden wirklich ausgefuehrt,
#      inklusive UPDATE/INSERT auf den Altdaten der Seeds (genau das fehlte: 'Data too long' bei Migration 028).
#   3. Datenbank B aus dem aktuellen schema.sql aufbauen und die Struktur (Spalten, Typen, NULL, Vorgaben, Indizes)
#      von A und B vergleichen: Migrationen und schema.sql muessen denselben Stand ergeben.
#   4. Zweiter Lauf gegen A: Idempotenz (nichts offen, kein Fehler). Freigabe --retry an einer kuenstlich
#      fehlgeschlagenen Migration.
# Aufruf: bash tools/migrations-check.sh [ANZAHL_COMMITS]   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
source "$ROOT/tools/lib/mariadb-sandbox.sh"
if ! mariadb_sandbox_available; then echo "  uebersprungen: mariadbd/mariadb-install-db nicht vorhanden"; exit 0; fi
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
N="${1:-10}"

echo "1) Vorzustand bestimmen"
BASE=""
for f in "$ROOT"/php-ionos/sql/migrations/[0-9][0-9][0-9]_*.sql; do
    c="$(git -C "$ROOT" log --format=%H --diff-filter=A -1 -- "php-ionos/sql/migrations/$(basename "$f")" 2>/dev/null)"
    [[ -z "$c" ]] && continue
    if git -C "$ROOT" merge-base --is-ancestor "$c" HEAD 2>/dev/null && [[ "$(git -C "$ROOT" rev-list --count "$c..HEAD")" -lt "$N" ]]; then
        # aelteste dieser Datei: kleinste Nummer gewinnt (Dateien sind sortiert)
        BASE="${BASE:-$c}"
    fi
done
if [[ -n "$BASE" ]]; then
    PARENT="$(git -C "$ROOT" rev-parse "$BASE^" 2>/dev/null || true)"
    echo "  neue Migrationen seit Commit $(git -C "$ROOT" log --format='%h (%s)' -1 "$BASE" | cut -c1-90); Vorzustand = $(git -C "$ROOT" log --format=%h -1 "$PARENT")"
else
    PARENT="$(git -C "$ROOT" rev-parse HEAD^)"
    echo "  keine neue Migrationsdatei in den letzten $N Commits; Vorzustand = HEAD^ (nur Idempotenz)"
fi
git -C "$ROOT" show "$PARENT:php-ionos/sql/schema.sql" > "$T/schema-alt.sql" 2>/dev/null && ok "schema.sql des Vorzustands geladen ($(wc -l < "$T/schema-alt.sql") Zeilen)" || { bad "schema.sql des Vorzustands nicht lesbar"; exit 1; }

echo "2) Datenbank A (Vorzustand) aufbauen, aktuelle Migrationen ausfuehren"
mariadb_sandbox_start "$T/mdb" "" || exit 1
mariadb --socket="$MDB_SOCK" -uroot -e "CREATE DATABASE se_alt CHARACTER SET utf8mb4; GRANT ALL ON se_alt.* TO '$MDB_DB'@'127.0.0.1';"
mariadb --socket="$MDB_SOCK" -uroot se_alt < "$T/schema-alt.sql" && ok "Datenbank A aus Vorzustand aufgebaut" || bad "Vorzustand liess sich nicht laden"
sed "s/'name' => '$MDB_DB'/'name' => 'se_alt'/" "$MDB_DIR/config.php" > "$MDB_DIR/config-alt.php"
grep -q "'name' => 'se_alt'" "$MDB_DIR/config-alt.php" && ok "Konfiguration fuer A" || bad "Konfiguration fuer A"
CFG_NEU="$SMARTEINZUG_CONFIG"
OUT="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php 2>&1)"; RC=$?
echo "$OUT" | sed 's/^/        /' | head -20
[[ $RC -eq 0 ]] && ok "Migrationen gegen den Vorzustand fehlerfrei (Exit 0)" || bad "Migrationen gegen den Vorzustand fehlgeschlagen (Exit $RC)"
echo "$OUT" | grep -q "0 offen" && ok "keine offenen Migrationen nach dem Lauf" || bad "offene Migrationen nach dem Lauf"

echo "3) Strukturvergleich A (migriert) gegen B (aktuelles schema.sql)"
struktur() { mariadb --socket="$MDB_SOCK" -uroot -N -e "
SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,' ',COLUMN_TYPE,' ',IS_NULLABLE,' ',COALESCE(COLUMN_DEFAULT,'<null>'),' ',EXTRA)
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$1' AND TABLE_NAME<>'schema_migrations' ORDER BY 1;
SELECT CONCAT('IDX ',TABLE_NAME,'.',INDEX_NAME,' ',NON_UNIQUE,' ',GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX))
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$1' AND TABLE_NAME<>'schema_migrations' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY 1;" | sort; }
struktur se_alt > "$T/a.txt"; struktur "$MDB_DB" > "$T/b.txt"
if diff -u "$T/b.txt" "$T/a.txt" > "$T/diff.txt"; then
    ok "Struktur identisch ($(wc -l < "$T/a.txt") Spalten- und Indexzeilen)"
else
    bad "Struktur weicht ab (links schema.sql, rechts migriert):"; grep '^[-+][^-+]' "$T/diff.txt" | head -30 | sed 's/^/        /'
fi

echo "4) Idempotenz und Freigabe --retry"
OUT2="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php 2>&1)"; RC2=$?
[[ $RC2 -eq 0 ]] && echo "$OUT2" | grep -q "0 eingespielt, 0 offen" && ok "zweiter Lauf: nichts zu tun, kein Fehler" || bad "zweiter Lauf: $OUT2"
LAST="$(ls "$ROOT"/php-ionos/sql/migrations/[0-9][0-9][0-9]_*.sql | tail -1 | xargs basename | cut -c1-3)"
mariadb --socket="$MDB_SOCK" -uroot se_alt -e "UPDATE schema_migrations SET status='failed', error_text='Testfall' WHERE version='$LAST';"
OUT3="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php 2>&1)"; RC3=$?
[[ $RC3 -eq 1 ]] && echo "$OUT3" | grep -q "Blockiert" && ok "fehlgeschlagene Migration blockiert den Lauf" || bad "Blockade fehlt: $OUT3"
OUT4="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php --retry=999 2>&1)"; RC4=$?
[[ $RC4 -eq 1 ]] && echo "$OUT4" | grep -q "Keine Migrationsdatei" && ok "--retry mit unbekannter Nummer verweigert" || bad "--retry 999: $OUT4"
OUT5="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php --retry=$LAST 2>&1)"; RC5=$?
[[ $RC5 -eq 0 ]] && echo "$OUT5" | grep -q "Freigegeben zur Wiederholung" && echo "$OUT5" | grep -q "1 eingespielt, 0 offen" && ok "--retry=$LAST gibt frei und spielt trotz vorhandenem Marker vollstaendig erneut ein" || bad "--retry: $OUT5"
OUT6="$(cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$MDB_DIR/config-alt.php" php bin/migrate.php --retry=$LAST 2>&1)"; RC6=$?
[[ $RC6 -eq 1 ]] && echo "$OUT6" | grep -q 'Zustand "applied"' && ok "--retry auf eingespielte Migration verweigert" || bad "--retry auf applied: $OUT6"
A="$(mariadb --socket="$MDB_SOCK" -uroot -N se_alt -e "SELECT COUNT(*) FROM audit_log WHERE action='migration_released'")"
[[ "$A" == "1" ]] && ok "Freigabe im audit_log protokolliert" || bad "audit migration_released=$A"

echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; [[ $FAIL -eq 0 ]]
