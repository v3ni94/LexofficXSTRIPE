#!/usr/bin/env bash
#
# Regressionstest des automatischen Synchronisationsplans (scheduler_auto_sync in app/jobs.php) gegen
# eine ECHTE, temporaere MariaDB (tools/lib/mariadb-sandbox.sh). Ohne Docker, ohne Produktionsdaten.
#
# Hintergrund: Im Monitoring stand dauerhaft "Wartende Aufgaben (1 Sync)". Ursache war ein Lauf, den ein
# hart beendeter Worker offen zurueckgelassen hatte: Der Scheduler schloss ihn als Fehler, reihte die
# Fortsetzung aber erst zur naechsten regulaeren Faelligkeit ein (auto_sync_hours, Vorgabe 6 Stunden).
# Jetzt wird die Fortsetzung sofort eingereiht. Zusaetzlich haelt dieser Test fest, dass das
# Einreichfenster fuer Lastschriften (Vorgabe 23:00 bis 06:00) die Synchronisation NICHT beeinflusst:
# Es begrenzt ausschliesslich das Einreichen von Einzuegen, nicht Abrufe und Statusabgleiche.
#
# Aufruf: bash tools/scheduler-sync-check.sh      Exit 0 = alle Faelle bestanden
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

# shellcheck source=lib/mariadb-sandbox.sh
source "$ROOT/tools/lib/mariadb-sandbox.sh"

T="$(mktemp -d)"
cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }
trap cleanup EXIT INT TERM

if ! mariadb_sandbox_available; then
    echo "  (uebersprungen: mariadbd/mariadb-install-db nicht verfuegbar)"
    exit 0
fi
mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true]," || exit 1

SIM="$ROOT/tools/lib/scheduler-sync-sim.php"
run() { php "$SIM" "$ROOT" "$1" 2>&1; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }

echo "1) Verwaister Lauf (kein Fortschritt, kein Job): wird geschlossen UND sofort fortgesetzt"
OUT="$(run verwaist)"
[[ "$(feld "$OUT" sync_state)" == "error" ]] && ok "Lauf als Fehler geschlossen" || bad "sync_state=$(feld "$OUT" sync_state), erwartet error ($OUT)"
[[ "$(feld "$OUT" jobs_offen)" == "1" ]] && ok "genau ein Sync-Job eingereiht" || bad "jobs_offen=$(feld "$OUT" jobs_offen), erwartet 1"
[[ "$(feld "$OUT" eingereiht)" == *"fortsetzung"* ]] && ok "Scheduler meldet die Fortsetzung ($(feld "$OUT" eingereiht))" || bad "Meldung: $(feld "$OUT" eingereiht)"

echo "2) Lauf mit Fortschritt vor einer Minute: unberuehrt, kein neuer Job"
OUT="$(run frisch)"
[[ "$(feld "$OUT" sync_state)" == "running" ]] && ok "laufender Zustand bleibt running" || bad "sync_state=$(feld "$OUT" sync_state)"
[[ "$(feld "$OUT" jobs_offen)" == "0" ]] && ok "kein zusaetzlicher Job" || bad "jobs_offen=$(feld "$OUT" jobs_offen), erwartet 0"

echo "3) Verwaister Lauf, aber Job liegt bereits in der Warteschlange: kein zweiter Job, Lauf unberuehrt"
OUT="$(run mit-job)"
[[ "$(feld "$OUT" jobs_offen)" == "1" ]] && ok "weiterhin genau ein Job (kein Doppeleintrag)" || bad "jobs_offen=$(feld "$OUT" jobs_offen), erwartet 1"
[[ "$(feld "$OUT" sync_state)" == "running" ]] && ok "Lauf nicht vorschnell geschlossen" || bad "sync_state=$(feld "$OUT" sync_state), erwartet running"

echo "4) Firma pausiert: nichts einreihen, nichts schliessen"
OUT="$(run pausiert)"
[[ "$(feld "$OUT" jobs_offen)" == "0" ]] && ok "kein Job fuer eine pausierte Firma" || bad "jobs_offen=$(feld "$OUT" jobs_offen), erwartet 0"
[[ "$(feld "$OUT" sync_state)" == "running" ]] && ok "Zustand der pausierten Firma unberuehrt" || bad "sync_state=$(feld "$OUT" sync_state)"

echo "5) Einreichfenster fuer Lastschriften begrenzt die Synchronisation NICHT"
OUT="$(run nachtfenster)"
[[ "$(feld "$OUT" fenster_mittags)" == "geschlossen" ]] && ok "mittags ist das Einreichfenster geschlossen (Vorgabe 23:00 bis 06:00)" || bad "fenster_mittags=$(feld "$OUT" fenster_mittags)"
[[ "$(feld "$OUT" fenster_nachts)" == "offen" ]] && ok "um 23:30 ist es offen" || bad "fenster_nachts=$(feld "$OUT" fenster_nachts)"
[[ "$(feld "$OUT" jobs_offen)" == "1" ]] && ok "Synchronisation wird auch bei geschlossenem Fenster eingereiht" || bad "jobs_offen=$(feld "$OUT" jobs_offen), erwartet 1"

echo "6) Statisch: das Fenster wird nur beim Einreichen von Einzuegen geprueft"
STELLEN="$(grep -rn "collections_window_open(" "$ROOT/php-ionos/app" "$ROOT/php-ionos"/*.php 2>/dev/null \
    | grep -v "function collections_window_open" | grep -v "docs-build" | wc -l)"
UNERWARTET="$(grep -rln "collections_window_open(" "$ROOT/php-ionos/app" 2>/dev/null | grep -v "docs-build" \
    | grep -vE "collections\.php$" | tr '\n' ' ')"
[[ -z "$UNERWARTET" ]] && ok "Fensterpruefung nur in app/collections.php ($STELLEN Verwendungen), nicht in jobs.php/sync_state.php" \
    || bad "Fensterpruefung auch in: $UNERWARTET"
grep -q "collections_window_open" "$ROOT/php-ionos/app/jobs.php" && bad "app/jobs.php prueft das Einreichfenster (Sync und Klaerung wuerden nachts haengen)" \
    || ok "app/jobs.php prueft das Einreichfenster nicht (Sync, Klaerung, Monitoring laufen rund um die Uhr)"

echo "7) Warteschlangenansicht: wartende Jobs erscheinen, reservierte nicht"
OUT="$(run wartende-jobs)"
[[ "$(feld "$OUT" wartend)" == "1" ]] && ok "genau der wartende Job in der Liste (der reservierte fehlt korrekt)" || bad "wartend=$(feld "$OUT" wartend), erwartet 1 ($OUT)"
[[ "$(feld "$OUT" typen)" == "sync_run" ]] && ok "Typ des wartenden Jobs: sync_run" || bad "typen=$(feld "$OUT" typen)"
[[ "$(feld "$OUT" reserviert)" == "maintenance" ]] && ok "der zweite Job wurde reserviert (maintenance)" || bad "reserviert=$(feld "$OUT" reserviert)"

echo "8) Reservierung ohne Lebenszeichen: sichtbar, Freigabe ohne Fehlversuch, frischer Heartbeat schuetzt"
OUT="$(run stale-reservierung)"
[[ "$(feld "$OUT" frisch_gefunden)" == "0" ]] && ok "frisch reservierter Job erscheint NICHT als ohne Lebenszeichen" || bad "frisch_gefunden=$(feld "$OUT" frisch_gefunden), erwartet 0"
[[ "$(feld "$OUT" freigabe_frisch)" == "nein" ]] && ok "Freigabe bei frischem Heartbeat abgelehnt (kein Diebstahl am laufenden Worker)" || bad "freigabe_frisch=$(feld "$OUT" freigabe_frisch)"
[[ "$(feld "$OUT" stale_gefunden)" == "1" ]] && ok "nach Ablauf des Heartbeats in der Liste" || bad "stale_gefunden=$(feld "$OUT" stale_gefunden), erwartet 1"
[[ "$(feld "$OUT" freigabe)" == "ja" ]] && ok "Freigabe erfolgreich" || bad "freigabe=$(feld "$OUT" freigabe)"
[[ "$(feld "$OUT" status)" == "queued" ]] && ok "Job wartet danach wieder" || bad "status=$(feld "$OUT" status), erwartet queued"
[[ "$(feld "$OUT" versuche_vorher)" == "$(feld "$OUT" versuche_nachher)" ]] && ok "kein Fehlversuch gezaehlt ($(feld "$OUT" versuche_vorher) -> $(feld "$OUT" versuche_nachher))" || bad "Versuche geaendert: $(feld "$OUT" versuche_vorher) -> $(feld "$OUT" versuche_nachher)"
[[ "$(feld "$OUT" locked)" == "frei" ]] && ok "Reservierung entfernt" || bad "locked=$(feld "$OUT" locked)"

echo "9) Offene Synchronisationslaeufe: haengend erkannt, mit Job nicht mehr"
OUT="$(run offene-laeufe)"
[[ "$(feld "$OUT" laeufe)" == "1" ]] && ok "ein offener Lauf gelistet" || bad "laeufe=$(feld "$OUT" laeufe)"
[[ "$(feld "$OUT" haengt_vorher)" == "ja" ]] && ok "ohne Job als haengend gekennzeichnet" || bad "haengt_vorher=$(feld "$OUT" haengt_vorher)"
[[ "$(feld "$OUT" haengt_nachher)" == "nein" ]] && ok "mit wartendem Job nicht mehr haengend" || bad "haengt_nachher=$(feld "$OUT" haengt_nachher)"
[[ "$(feld "$OUT" firma)" == "Testfirma" ]] && ok "Firmenname fuer die Anzeige verknuepft" || bad "firma=$(feld "$OUT" firma)"

echo "10) Statisch: Kennzahlen der Uebersicht sind verlinkt, Aktionen abgesichert"
MV="$ROOT/php-ionos/app/monitor_view.php"; AS="$ROOT/php-ionos/admin-system.php"
[[ "$(grep -c 'stat-card stat-link' "$MV")" -eq 5 ]] && ok "alle fuenf Kennzahlen sind anklickbar" || bad "$(grep -c 'stat-card stat-link' "$MV") von 5 Kennzahlen verlinkt"
for ANKER in 'id="aktive-jobs"' 'id="wartend"' 'id="laufende"'; do
    grep -q "$ANKER" "$AS" && ok "Zielabschnitt vorhanden: $ANKER" || bad "Zielabschnitt fehlt: $ANKER"
done
# Zweitbestaetigung seit 4.36 nur fuer Geldfluss (Vorstand 07.09.2026): job_release verlangt den 2FA-Code nur bei
# geldbewegenden Jobtypen (queue_type_is_money), sync_enqueue (nur Jobtyp sync_run) gar nicht. Vollstaendige Regel: tools/totp-policy-check.php.
if grep -q "action === 'job_release'" "$AS" && sed -n "/action === 'job_release'/,/elseif/p" "$AS" | grep -q "queue_type_is_money" && sed -n "/action === 'job_release'/,/elseif/p" "$AS" | grep -q require_recent_totp; then
    ok "Aktion job_release verlangt den 2FA-Code nur bei geldbewegenden Jobtypen"
else
    bad "Aktion job_release fehlt oder ohne typabhaengige 2FA-Pruefung"
fi
if grep -q "action === 'sync_enqueue'" "$AS" && ! sed -n "/action === 'sync_enqueue'/,/elseif/p" "$AS" | grep -q require_recent_totp; then
    ok "Aktion sync_enqueue ohne 2FA-Code (nur Jobtyp sync_run, kein Geldfluss)"
else
    bad "Aktion sync_enqueue fehlt oder verlangt noch einen 2FA-Code"
fi
grep -q 'name="action" value="job_release"' "$AS" && grep -q 'csrf_field()' "$AS" && ok "Formulare mit CSRF-Feld vorhanden" || bad "Formular oder CSRF-Feld fehlt"

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
