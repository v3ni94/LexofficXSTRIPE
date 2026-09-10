#!/usr/bin/env bash
# Begrenzte lokale Leistungsmessung (Audit 10.09.2026, kein Nachweis fuer Produktionskapazitaet): misst gegen eine temporaere
# MariaDB und den lokalen Stripe-Stub
#   A) Einreichung terminierter Einzuege: N Einzuege, 1 gegen 3 parallele Faelligkeitslaeufe (Durchsatz, Doppelzaehlung)
#   B) Jobreservierung: M Jobs, 8 parallele Reservierer (doppelte Reservierungen muessen 0 sein, Dauer)
#   C) Synchronisationsschritt: Fake-Quelle mit V Belegen, Dauer je Beleg (nur Datenbank, kein Netz)
# Aufruf: bash tools/perf-probe.sh [N=60] [M=400] [V=300]   Ausgabe: Kennzahlen als Zeilen key=wert
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
N="${1:-60}"; M="${2:-400}"; V="${3:-300}"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
mariadb_sandbox_available || { echo "uebersprungen: keine lokale MariaDB"; exit 0; }
T="$(mktemp -d)"; export STRIPE_STUB_DIR="$T/stub"; mkdir -p "$STRIPE_STUB_DIR"
PORT=$((29000 + RANDOM % 900))
php -S "127.0.0.1:$PORT" "$ROOT/tools/lib/stripe-stub.php" >"$T/stub.log" 2>&1 & STUB_PID=$!
cleanup() { kill "$STUB_PID" 2>/dev/null; mariadb_sandbox_stop; rm -rf "$T"; }
trap cleanup EXIT INT TERM
for i in $(seq 1 50); do curl -s -o /dev/null -u sk_test_x: "http://127.0.0.1:$PORT/v1/account" && break; sleep 0.1; done
mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'stripe_api_base_url' => 'http://127.0.0.1:$PORT', 'lexware_api_base_url' => 'http://127.0.0.1:1', 'collections' => ['grace_hours' => 0, 'window_enabled' => false], 'queue' => ['stripe_per_second' => 1000, 'stripe_global_per_second' => 5000]," || exit 1
export LEX_FAKE_FILE="$T/lex.json"; echo '{}' > "$LEX_FAKE_FILE"
SQL() { mariadb --socket="$MDB_SOCK" -uroot "$MDB_DB" -N -e "$1"; }
echo "umgebung_php=$(php -r 'echo PHP_VERSION;')"; echo "umgebung_mariadb=$(mariadbd --version | grep -oE '1[0-9]\.[0-9]+\.[0-9]+' | head -1)"; echo "umgebung_cpu=$(nproc)"; echo "umgebung_ram_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
ms() { date +%s%3N; }

echo "--- A) Einreichung terminierter Einzuege (N=$N)"
php "$ROOT/tools/lib/perf-sim.php" "$ROOT" seed-collections "$N" >/dev/null 2>&1
t0=$(ms); php "$ROOT/tools/lib/collections-sim.php" "$ROOT" process - >/dev/null 2>&1; t1=$(ms)
echo "a_seriell_ms=$((t1 - t0))"; echo "a_seriell_pro_einzug_ms=$(( (t1 - t0) / N ))"; echo "a_seriell_pis=$(wc -l < "$STRIPE_STUB_DIR/pi.log")"
php "$ROOT/tools/lib/perf-sim.php" "$ROOT" seed-collections "$N" >/dev/null 2>&1; rm -f "$STRIPE_STUB_DIR"/idem/*; : > "$STRIPE_STUB_DIR/pi.log"
t0=$(ms); PIDS=(); for i in 1 2 3; do php "$ROOT/tools/lib/collections-sim.php" "$ROOT" process - >/dev/null 2>&1 & PIDS+=($!); done; wait "${PIDS[@]}"; t1=$(ms)
echo "a_parallel3_ms=$((t1 - t0))"; echo "a_parallel3_pis=$(wc -l < "$STRIPE_STUB_DIR/pi.log")"; echo "a_parallel3_erwartet=$N"
echo "a_parallel3_doppelt=$(SQL "SELECT COUNT(*) - COUNT(DISTINCT stripe_payment_intent_id) FROM payment_collections WHERE stripe_payment_intent_id IS NOT NULL")"

echo "--- B) Jobreservierung (M=$M, 8 Reservierer)"
php "$ROOT/tools/lib/perf-sim.php" "$ROOT" seed-jobs "$M" >/dev/null 2>&1
t0=$(ms); PIDS=(); for i in 1 2 3 4 5 6 7 8; do php "$ROOT/tools/lib/perf-sim.php" "$ROOT" reserve-all "w$i" > "$T/r$i.txt" 2>/dev/null & PIDS+=($!); done; wait "${PIDS[@]}"; t1=$(ms)
echo "b_dauer_ms=$((t1 - t0))"; echo "b_reserviert=$(cat "$T"/r*.txt | sed -n 's/^reserviert=//p' | paste -sd+ | bc)"; echo "b_erwartet=$M"
echo "b_doppelt=$(cat "$T"/r*.txt | sed -n 's/^ids=//p' | tr ',' '\n' | grep -v '^$' | sort | uniq -d | wc -l)"
echo "b_pro_reservierung_ms=$(( (t1 - t0) * 1000 / M ))e-3"

echo "--- C) Synchronisationsschritt (V=$V Belege, Fake-Quelle, ohne Netz)"
OUT="$(php "$ROOT/tools/lib/perf-sim.php" "$ROOT" sync "$V" 2>/dev/null)"; echo "$OUT"
