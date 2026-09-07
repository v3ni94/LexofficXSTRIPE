#!/usr/bin/env bash
#
# Gemeinsamer Pruefstand mit einer TEMPORAEREN, lokalen MariaDB fuer Tests, die echte
# Datenbanklogik brauchen (tools/worker-signal-check.sh, tools/scheduler-sync-check.sh).
# Kein Docker, keine Produktionsdaten: eigener Datenbestand unter einem temporaeren Ordner,
# Schema aus php-ionos/sql/schema.sql, eigene config.php ueber SMARTEINZUG_CONFIG.
#
# Nutzung (erwartet ROOT = Repo-Wurzel):
#   source "$ROOT/tools/lib/mariadb-sandbox.sh"
#   mariadb_sandbox_available || { echo "uebersprungen"; exit 0; }
#   mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true],"   # zweites Argument optional
#   mariadb --socket="$MDB_SOCK" -uroot "$MDB_DB" -e "SELECT 1"
#   ... Tests mit SMARTEINZUG_CONFIG ...
#   mariadb_sandbox_stop        (auch im EXIT-Trap aufrufen, VOR dem Loeschen des Ordners:
#                                der Socket liegt darin, sonst bleibt ein Serverprozess zurueck)
set -uo pipefail

MDB_SOCK=""
MDB_PORT=""
MDB_PID=""
MDB_DB=""
MDB_DIR=""

mariadb_sandbox_available() {
    command -v mariadbd >/dev/null 2>&1 && command -v mariadb-install-db >/dev/null 2>&1 \
        && command -v mariadb >/dev/null 2>&1
}

# $1 = Verzeichnis fuer Datenbestand, Socket und config.php; $2 = zusaetzliche Zeilen fuer config.php
mariadb_sandbox_start() {
    MDB_DIR="$1"
    local extra="${2:-}"
    install -d "$MDB_DIR"
    MDB_SOCK="$MDB_DIR/sock"
    MDB_PORT=$((23000 + RANDOM % 1000))
    MDB_DB="se_test"
    mariadb-install-db --datadir="$MDB_DIR/data" --user="$(id -un)" \
        --auth-root-authentication-method=normal >"$MDB_DIR/install.log" 2>&1 || {
        echo "::error:: mariadb-install-db fehlgeschlagen: $(tail -3 "$MDB_DIR/install.log")" >&2
        return 1
    }
    mariadbd --datadir="$MDB_DIR/data" --socket="$MDB_SOCK" --port="$MDB_PORT" --bind-address=127.0.0.1 \
        --pid-file="$MDB_DIR/pid" --log-error="$MDB_DIR/err.log" --user="$(id -un)" >/dev/null 2>&1 &
    MDB_PID=$!
    local i
    for i in $(seq 1 100); do
        mariadb --socket="$MDB_SOCK" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
        sleep 0.2
    done
    if ! mariadb --socket="$MDB_SOCK" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        echo "::error:: Temporaere MariaDB startete nicht: $(tail -3 "$MDB_DIR/err.log" 2>/dev/null)" >&2
        return 1
    fi
    mariadb --socket="$MDB_SOCK" -uroot -e "CREATE DATABASE $MDB_DB CHARACTER SET utf8mb4;
        CREATE USER '$MDB_DB'@'127.0.0.1' IDENTIFIED BY 'test-pw';
        GRANT ALL ON $MDB_DB.* TO '$MDB_DB'@'127.0.0.1';"
    mariadb --socket="$MDB_SOCK" -uroot "$MDB_DB" < "$ROOT/php-ionos/sql/schema.sql"
    install -d "$MDB_DIR/storage"
    cat > "$MDB_DIR/config.php" <<EOF
<?php
declare(strict_types=1);
if (get_included_files()[0] === __FILE__) { http_response_code(403); exit('Forbidden'); }
return [
    'timezone' => 'Europe/Berlin', 'environment' => 'prod',
    'app_secret' => str_repeat('a', 64), 'cron_token' => str_repeat('b', 32),
    'db' => ['host' => '127.0.0.1', 'port' => $MDB_PORT, 'name' => '$MDB_DB', 'user' => '$MDB_DB', 'pass' => 'test-pw', 'charset' => 'utf8mb4'],
    'redis' => null,
    'storage_dir' => '$MDB_DIR/storage',
    $extra
];
EOF
    export SMARTEINZUG_CONFIG="$MDB_DIR/config.php"
    return 0
}

# Server ueber den Socket herunterfahren und auf das Ende warten. Muss VOR dem Loeschen des
# Verzeichnisses laufen, sonst ist der Socket weg und der Prozess bleibt mit geloeschtem Datadir zurueck.
mariadb_sandbox_stop() {
    if [[ -n "$MDB_SOCK" && -S "$MDB_SOCK" ]]; then
        mariadb --socket="$MDB_SOCK" -uroot -e "SHUTDOWN" >/dev/null 2>&1 || true
    fi
    if [[ -n "$MDB_PID" ]]; then
        local i
        for i in $(seq 1 50); do kill -0 "$MDB_PID" 2>/dev/null || break; sleep 0.1; done
        kill -TERM "$MDB_PID" 2>/dev/null || true
        wait "$MDB_PID" 2>/dev/null || true
    fi
    MDB_PID=""
    unset SMARTEINZUG_CONFIG
}
