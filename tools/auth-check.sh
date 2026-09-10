#!/usr/bin/env bash
# Anmeldesicherheit (Audit 10.09.2026): atomarer TOTP-Replay-Schutz mit echten parallelen Prozessen (C-06), Reihenfolge der
# Geraetefreigabepruefung (C-05, statisch), einheitliches Secure-Flag (C-03, statisch), Bereinigung login_attempts (C-09),
# Einmalversand der Bestaetigungsmail (C-10, statisch). Aufruf: bash tools/auth-check.sh   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | tail -n1; }

echo "1) Statische Sicherungen"
A="$ROOT/php-ionos/app/auth.php"
CU="$(sed -n '/^function current_user/,/^}/p' "$A")"
D=$(grep -n "device_session_valid" <<<"$CU" | head -1 | cut -d: -f1); P=$(grep -n "_current_user_platform" <<<"$CU" | head -1 | cut -d: -f1)
[[ -n "$D" && -n "$P" && $D -lt $P ]] && ok "Geraetefreigabe wird vor dem Plattformkontext geprueft (C-05)" || bad "C-05: Reihenfolge in current_user()"
grep -q "totp_last_step IS NULL OR totp_last_step < ?" "$A" && grep -q "return \$st->rowCount() === 1;" <(sed -n '/^function twofa_verify_user/,/^}/p' "$A") && ok "TOTP-Zeitschritt wird atomar fortgeschrieben (C-06)" || bad "C-06: Replay-Schutz nicht atomar"
grep -q "request_is_https()" "$ROOT/php-ionos/app/devices.php" && grep -q "^function request_is_https" "$ROOT/php-ionos/app/bootstrap.php" && grep -q "\$secureCookie = request_is_https();" "$ROOT/php-ionos/app/bootstrap.php" && ok "Sitzungs- und Geraetecookie leiten Secure gleich ab (C-03)" || bad "C-03: Secure-Flag uneinheitlich"
[[ "$(grep -c "email_verification_send(\$user)" "$ROOT/php-ionos/verify-email.php")" == 1 ]] && ok "Bestaetigungsmail wird je Klick genau einmal gesendet (C-10)" || bad "C-10: Mehrfachversand"
grep -q "login_attempts_cleanup" "$ROOT/php-ionos/app/jobs.php" && grep -q "login_attempts_cleanup" "$ROOT/php-ionos/cron.php" && ok "Bereinigung login_attempts in Wartung und Cron (C-09)" || bad "C-09: Bereinigung fehlt"
grep -q "GET_LOCK('smarteinzug_cron'" "$ROOT/php-ionos/cron.php" && ok "Cron gegen ueberlappende Laeufe gesperrt (D-08)" || bad "D-08: keine Cron-Sperre"
grep -q "GLOBALS\['worker_beat'\]" "$ROOT/php-ionos/app/queue.php" && grep -q "GLOBALS\['worker_beat'\] = \$beat" "$ROOT/php-ionos/bin/worker.php" && ok "Worker-Lebenszeichen bei jedem Jobfortschritt (D-02)" || bad "D-02: Heartbeat waehrend Job fehlt"
grep -q "(HTTP 401)" "$ROOT/php-ionos/app/lexoffice.php" && ok "401-Meldung fuer die Fehlerkategorie erkennbar (B-03)" || bad "B-03"
grep -q "sync_invoice_source(\$tenantId); // prüft Verbindung" "$ROOT/php-ionos/invoices.php" && ok "manueller Sync ueber die Adaptergrenze (B-05)" || bad "B-05"
grep -q "platform_role_is_privileged" "$ROOT/php-ionos/app/platform.php" && [[ "$(grep -c "platform_actor_is_admin(\$actor)" "$ROOT/php-ionos/app/platform.php")" -ge 3 ]] && ok "privilegierte Rollen nur durch Administratoren (C-01)" || bad "C-01"

echo "2) Parallele Zweitbestaetigung mit demselben Code (echte Prozesse)"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
if ! mariadb_sandbox_available; then echo "uebersprungen: keine lokale MariaDB"; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; exit $(( FAIL > 0 )); fi
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
mariadb_sandbox_start "$T/mdb" "'stripe_api_base_url' => 'http://127.0.0.1:1'," || exit 1
SIM="php $ROOT/tools/lib/auth-sim.php $ROOT"
# Nicht am Ende eines 30-Sekunden-Schritts starten, damit alle Parallelaufrufe denselben Code sehen
for i in 1 2 3; do OUT="$($SIM seed)"; S=$(feld "$OUT" sekunde_im_schritt); [[ $S -lt 22 ]] && break; sleep $((30 - S)); done
UID_="$(feld "$OUT" user_id)"; CODE="$(feld "$OUT" code)"
PIDS=(); for i in 1 2 3 4 5 6; do $SIM verify "$UID_" "$CODE" > "$T/v$i.txt" 2>&1 & PIDS+=($!); done; wait "${PIDS[@]}"
OKS=$(cat "$T"/v*.txt | grep -c '^ok=1$'); NOKS=$(cat "$T"/v*.txt | grep -c '^ok=0$')
[[ $OKS -eq 1 && $NOKS -eq 5 ]] && ok "sechs gleichzeitige Pruefungen desselben Codes: genau eine akzeptiert" || bad "Replay: ok=$OKS nein=$NOKS ($(cat "$T"/v*.txt | tr '\n' ' ' | cut -c1-200))"
OUT="$($SIM verify "$UID_" "$CODE")"; [[ "$(feld "$OUT" ok)" == 0 ]] && ok "erneute Verwendung danach abgewiesen" || bad "Replay nach Erfolg akzeptiert"
echo "3) Bereinigung login_attempts"
OUT="$($SIM cleanup)"
[[ "$(feld "$OUT" geloescht)" == 1 && "$(feld "$OUT" verbleibend)" == 1 ]] && ok "alte Anmeldeversuche geloescht, junge bleiben" || bad "Bereinigung: $OUT"
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ $FAIL -eq 0 ]]
