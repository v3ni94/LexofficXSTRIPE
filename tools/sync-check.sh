#!/usr/bin/env bash
# Synchronisation gegen temporaere MariaDB mit Fake-Rechnungsquelle (Audit 10.09.2026: B-01, B-03, B-07, B-08).
# Aufruf: bash tools/sync-check.sh   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { local got; got="$(feld "$OUT" "$2")"; [[ "$got" == "$3" ]] && ok "$1" || bad "$1 ($2='$got', erwartet '$3')"; }
erwp() { local got; got="$(feld "$OUT" "$2")"; [[ "$got" == $3 ]] && ok "$1" || bad "$1 ($2='$got', erwartet Muster $3)"; }
source "$ROOT/tools/lib/mariadb-sandbox.sh"
if ! mariadb_sandbox_available; then echo "uebersprungen: keine lokale MariaDB"; exit 0; fi
T="$(mktemp -d)"
cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }
trap cleanup EXIT INT TERM
mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'stripe_api_base_url' => 'http://127.0.0.1:1', 'lexware_api_base_url' => 'http://127.0.0.1:1', 'collections' => ['grace_hours' => 0, 'window_enabled' => false]," || exit 1
SIM="php $ROOT/tools/lib/sync-sim.php $ROOT"

echo "1) B-01 Kontaktabruf scheitert technisch"
OUT="$($SIM kontaktfehler 2>&1)"
erw "Kundennummer bleibt erhalten" kunde_nummer 20017
erw "E-Mail bleibt erhalten" kunde_email buchhaltung@bestand.test
erw "Kunde bleibt kein Laufkunde" kunde_walkin 0
erw "technischer Fehler laesst den Schritt scheitern (Wiederholung), statt Daten zu verwerfen (F-07)" status fehler
echo "2) B-01 Kontakt fachlich nicht vorhanden (neuer Kunde)"
OUT="$($SIM kontaktfehlt 2>&1)"
erw "Ersatzkunde 10001 wie bisher" kunde_nummer 10001
erw "als Laufkunde markiert" kunde_walkin 1
erw "Rechnung importiert" rechnung_status open
echo "3) B-07 Nachpruefung scheitert einmal"
OUT="$($SIM recheck-fehler 2>&1)"
erw "Lauf 1: Rechnung offen" lauf1_r1 open
erwp "Lauf 2: Zwischenzustand (not_open) nach Fehler" lauf2_r1 "not_open"
erw "Lauf 3: Rechnung als bezahlt erkannt (kein Dauerzustand)" lauf3_r1 paid
echo "4) B-08 Rechnung in Lexware bezahlt, Einzug terminiert"
OUT="$($SIM bezahlt-terminiert 2>&1)"
erw "terminierter Einzug storniert" einzug_status cancelled
erwp "Storno-Vermerk" einzug_note "*bezahlt*"
erw "Rechnung bezahlt" rechnung_lex paid
erw "Rechnungsstatus collected, nicht failed" rechnung_collection collected
erw "Faelligkeitslauf: kein Fehlschlag" faellig_failed 0
erw "Rechnungsstatus nach Lauf unveraendert" rechnung_collection_nach_lauf collected
echo "5) B-03 Kategorie des 401-Fehlers"
OUT="$($SIM auth 2>&1)"
erw "401-Meldung wird als auth erkannt" kategorie_401 auth
erw "alte Meldung war nicht erkennbar (Beleg des Befunds)" kategorie_alt other
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ $FAIL -eq 0 ]]
