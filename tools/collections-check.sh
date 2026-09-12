#!/usr/bin/env bash
# Funktions- und Nebenlaeufigkeitspruefung des Geldflusses (Audit 09.09.2026): echte app/collections.php gegen eine
# temporaere MariaDB (tools/lib/mariadb-sandbox.sh), einen lokalen Stripe-Stub (tools/lib/stripe-stub.php) und den
# echten Webhook-Endpunkt (php -S auf php-ionos/). Lexware wird ueber den CLI-Testhaken ersetzt. Der zentrale
# Test-Schutz (tools/lib/test-guard.php) bricht ab, sobald die Konfiguration auf Produktion oder echte Konten zeigt.
#
# Die Datenbank laeuft absichtlich in UTC, PHP in Europe/Berlin (Worst Case fuer gemischte Zeitvergleiche).
# Invariante ueber alle Faelle: Jede Rechnung erzeugt hoechstens EINEN PaymentIntent je fachlich erlaubtem Versuch
# (Zaehlung ueber pi.log des Stubs).
#
# Aufruf: bash tools/collections-check.sh          (Exit 0 = gruen)
#         COLLECTIONS_CHECK_SLOW=1 ...             zusaetzlich der 31-Sekunden-Timeout-Fall
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { local got; got="$(feld "$OUT" "$2")"; [[ "$got" == "$3" ]] && ok "$1" || bad "$1 ($2='$got', erwartet '$3')"; }
erwp() { local got; got="$(feld "$OUT" "$2")"; [[ "$got" == $3 ]] && ok "$1" || bad "$1 ($2='$got', erwartet Muster $3)"; }
pis() { if [[ -f "$STRIPE_STUB_DIR/pi.log" ]]; then wc -l < "$STRIPE_STUB_DIR/pi.log"; else echo 0; fi; }
pis_fuer() { if [[ -f "$STRIPE_STUB_DIR/pi.log" ]]; then grep -c " $1 " "$STRIPE_STUB_DIR/pi.log" || true; else echo 0; fi; }
mode() { echo "$1" > "$STRIPE_STUB_DIR/mode"; }

source "$ROOT/tools/lib/mariadb-sandbox.sh"
if ! mariadb_sandbox_available; then echo "uebersprungen: keine lokale MariaDB"; exit 0; fi
T="$(mktemp -d)"
export STRIPE_STUB_DIR="$T/stub"; mkdir -p "$STRIPE_STUB_DIR"
STUB_PID=""; WEB_PID=""
cleanup() { [[ -n "$STUB_PID" ]] && kill "$STUB_PID" 2>/dev/null; [[ -n "$WEB_PID" ]] && kill "$WEB_PID" 2>/dev/null; mariadb_sandbox_stop; rm -rf "$T"; }
trap cleanup EXIT INT TERM
PORT=$((29000 + RANDOM % 900)); WPORT=$((PORT + 1))
php -S "127.0.0.1:$PORT" "$ROOT/tools/lib/stripe-stub.php" >"$T/stub.log" 2>&1 &
STUB_PID=$!
for i in $(seq 1 50); do curl -s -o /dev/null -u sk_test_x: "http://127.0.0.1:$PORT/v1/account" && break; sleep 0.1; done

echo "1) Pruefstand"
mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'stripe_api_base_url' => 'http://127.0.0.1:$PORT', 'lexware_api_base_url' => 'http://127.0.0.1:1', 'collections' => ['grace_hours' => 0, 'window_enabled' => false], 'queue' => ['stripe_per_second' => 100, 'stripe_global_per_second' => 500], 'base_url' => 'http://127.0.0.1:$WPORT'," || exit 1
mariadb --socket="$MDB_SOCK" -uroot -e "SET GLOBAL time_zone = '+00:00'"
export LEX_FAKE_FILE="$T/lex.json"; echo '{}' > "$LEX_FAKE_FILE"
SIM="php $ROOT/tools/lib/collections-sim.php $ROOT"
SQL() { mariadb --socket="$MDB_SOCK" -uroot "$MDB_DB" -N -e "$1"; }
A=aaaaaaaa-0000-0000-0000-00000000000a; B=bbbbbbbb-0000-0000-0000-00000000000b
INV() { printf 'aaaaaaaa-4444-0000-0000-%012d' "$1"; }
INVB() { printf 'bbbbbbbb-4444-0000-0000-%012d' "$1"; }
LEXI() { printf 'aaaaaaaa-5555-0000-0000-%012d' "$1"; }
OUT="$($SIM seed 2>&1)"; erw "Testdaten angelegt (zwei Firmen, je 6 Rechnungen)" seed ok
[[ "$(SQL "SELECT @@global.time_zone")" == "+00:00" ]] && ok "Datenbank laeuft in UTC, PHP in Europe/Berlin (Worst Case)" || bad "Zeitzone der Sandbox"
# Test-Schutz greift auch hier: eine Konfiguration mit externer Stripe-Adresse darf nicht laufen
sed "s#'stripe_api_base_url' => 'http://127.0.0.1:$PORT'#'stripe_api_base_url' => 'https://api.stripe.com/v1'#" "$SMARTEINZUG_CONFIG" > "$T/cfg-extern.php"
OUT="$(SMARTEINZUG_CONFIG="$T/cfg-extern.php" $SIM state x 2>&1)"; [[ "$OUT" == *TEST-GUARD* && "$OUT" == *"nicht auf 127.0.0.1"* ]] && ok "Test-Schutz aktiv (Konfiguration mit externer Stripe-Adresse abgewiesen)" || bad "Test-Schutz: $OUT"

echo "2) Sofort-Einzug: genau ein PaymentIntent, Journal, Zustaende"
OUT="$($SIM submit $A "$(INV 1)")"; erw "Sofort-Einzug angenommen" result ok
OUT="$($SIM state "$(INV 1)")"
erw "Rechnung in_collection" invoice_status in_collection
erwp "Einzug processing mit PaymentIntent" c0 "processing|pi_*|10000|*"
erwp "Versuch succeeded mit PaymentIntent und Einzugs-ID" a0 "succeeded|pi_*|[0-9a-f]*"
[[ "$(pis)" == 1 ]] && ok "genau ein PaymentIntent beim Stub" || bad "PaymentIntents: $(pis)"
OUT="$($SIM submit $A "$(INV 1)")"; erw "zweiter Sofort-Einzug derselben Rechnung abgewiesen" result error
erwp "Grund: bereits im Einzugsverfahren" error "*bereits im Einzugsverfahren*"
[[ "$(pis)" == 1 ]] && ok "kein zweiter PaymentIntent" || bad "PaymentIntents: $(pis)"

echo "3) Mandantentrennung"
OUT="$($SIM submit $B "$(INV 2)")"; erw "Firma B kann Rechnung von Firma A nicht einziehen" result error
erwp "Grund: nicht gefunden" error "*nicht gefunden*"
[[ "$(pis)" == 1 ]] && ok "kein PaymentIntent fuer fremde Rechnung" || bad "PaymentIntents: $(pis)"
SQL "UPDATE sepa_mandates SET tenant_id = '$B' WHERE tenant_id = '$A' AND customer_id = 'aaaaaaaa-2222-0000-0000-000000000002'"
OUT="$($SIM submit $A "$(INV 2)")"
erw "Mandat einer anderen Firma wird nicht verwendet: neues, nicht unterschriebenes Mandat => Einzug abgewiesen" result error
erwp "Grund: kein unterschriebenes Mandat" error "*unterschrieben*"
[[ "$(SQL "SELECT COUNT(*) FROM sepa_mandates WHERE customer_id = 'aaaaaaaa-2222-0000-0000-000000000002' AND tenant_id = '$B'")" == 1 ]] && ok "fremdes Mandat unveraendert bei Firma B" || bad "fremdes Mandat veraendert"
[[ "$(pis_fuer "$(INV 2)")" == 0 ]] && ok "kein PaymentIntent" || bad "PaymentIntent trotz fehlendem Mandat"
SQL "UPDATE sepa_mandates SET tenant_id = '$A' WHERE tenant_id = '$B' AND customer_id = 'aaaaaaaa-2222-0000-0000-000000000002'"

echo "4) Parallele Sofort-Einzuege derselben Rechnung (echte Prozesse)"
PIDS=(); for i in 1 2 3 4 5 6; do $SIM submit $A "$(INV 2)" > "$T/par$i.txt" 2>&1 & PIDS+=($!); done; wait "${PIDS[@]}"
OKS=$(cat "$T"/par*.txt | grep -c '^result=ok$'); ERRS=$(cat "$T"/par*.txt | grep -c '^result=error$')
[[ $OKS -eq 1 && $ERRS -eq 5 ]] && ok "sechs gleichzeitige Anfragen: genau eine angenommen, fuenf abgewiesen" || bad "parallel: ok=$OKS error=$ERRS"
[[ "$(pis_fuer "$(INV 2)")" == 1 ]] && ok "genau ein PaymentIntent trotz sechs paralleler Anfragen" || bad "PaymentIntents fuer INV2: $(pis_fuer "$(INV 2)")"

echo "5) Betrag: Restbetrag, Teilzahlung, bezahlt, Waehrung"
echo "{\"$(LEXI 3)\": 40.00}" > "$LEX_FAKE_FILE"
OUT="$($SIM submit $A "$(INV 3)")"; erw "Teilzahlung ohne Bestaetigung abgewiesen" result error
erwp "Grund: Restbetrag bestaetigen" error "*Restbetrag*"
OUT="$($SIM submit $A "$(INV 3)" 3999)"; erw "falscher Bestaetigungsbetrag abgewiesen" result error
OUT="$($SIM submit $A "$(INV 3)" 4000)"; erw "bestaetigter Restbetrag angenommen" result ok
OUT="$($SIM state "$(INV 3)")"; erwp "Einzug ueber den Restbetrag 40,00 EUR" c0 "processing|pi_*|4000|*"
echo "{\"$(LEXI 4)\": 0}" > "$LEX_FAKE_FILE"
OUT="$($SIM submit $A "$(INV 4)")"; erw "bezahlte Rechnung (offen 0) abgewiesen" result error
SQL "UPDATE invoices SET open_amount = NULL, open_amount_fetched_at = NULL WHERE id = '$(INV 4)'"
echo "{\"$(LEXI 4)\": \"fail\"}" > "$LEX_FAKE_FILE"
OUT="$($SIM submit $A "$(INV 4)")"; erw "Lexware nicht erreichbar: kein Einzug" result error
erwp "Grund: Restbetrag nicht abrufbar" error "*nicht bei Lexware Office abgerufen*"
echo '{}' > "$LEX_FAKE_FILE"
SQL "UPDATE invoices SET open_amount = NULL, open_amount_fetched_at = NULL WHERE id = '$(INV 4)'"
SQL "UPDATE invoices SET currency = 'CHF' WHERE id = '$(INV 4)'"
OUT="$($SIM submit $A "$(INV 4)")"; erw "Rechnung in CHF wird nicht als EUR eingezogen (A-09)" result error
SQL "UPDATE invoices SET currency = 'EUR' WHERE id = '$(INV 4)'"
P4=$(pis); [[ "$(pis_fuer "$(INV 4)")" == 0 ]] && ok "kein PaymentIntent fuer Rechnung 4" || bad "PaymentIntents INV4: $(pis_fuer "$(INV 4)")"

echo "6) Mandat widerrufen"
SQL "UPDATE sepa_mandates SET is_active = 0, status = 'cancelled' WHERE tenant_id = '$A' AND customer_id = 'aaaaaaaa-2222-0000-0000-000000000004'"
OUT="$($SIM submit $A "$(INV 4)")"; erw "widerrufenes Mandat: Einzug abgewiesen" result error
erwp "Grund: widerrufen oder verfallen" error "*widerrufen*"
SQL "UPDATE sepa_mandates SET is_active = 1, status = 'active' WHERE tenant_id = '$A' AND customer_id = 'aaaaaaaa-2222-0000-0000-000000000004'"

echo "7) Unbekanntes Ergebnis (HTTP 500 mit angelegtem PaymentIntent): keine zweite Lastschrift"
mode http500
OUT="$($SIM submit $A "$(INV 4)")"; erw "Aufruf mit HTTP 500 endet in der Klaerung" error_class CollectionUnknownOutcomeException
OUT="$($SIM state "$(INV 4)")"; erwp "Versuch als unknown vermerkt" a0 "unknown|*"
erw "kein Einzugsdatensatz vor der Klaerung" collections 0
mode ok
OUT="$($SIM submit $A "$(INV 4)")"; erw "erneuter Einzug bis zur Klaerung gesperrt" result error
erwp "Grund: Versuch nicht abgeschlossen" error "*nicht abgeschlossen*"
[[ "$(pis_fuer "$(INV 4)")" == 1 ]] && ok "genau ein PaymentIntent (der aus dem 500-Aufruf)" || bad "PaymentIntents INV4: $(pis_fuer "$(INV 4)")"
echo "7a) Klaerung: Suchindex haengt, Liste kennt den PaymentIntent (A-01, A-04)"
touch "$STRIPE_STUB_DIR/search_lag"
OUT="$($SIM resolve $A)"
erw "junger unknown-Versuch wird nicht als failed freigegeben" cleared 0
OUT="$($SIM state "$(INV 4)")"
erwp "Versuch nicht auf failed gesetzt (Zeitzone UTC/Berlin darf die Frist nicht verfaelschen)" a0 "[su]*|*"
SQL "UPDATE collection_attempts SET created_at = DATE_SUB(created_at, INTERVAL 20 MINUTE) WHERE invoice_id = '$(INV 4)'"
OUT="$($SIM resolve $A)"
erw "alter unknown-Versuch: PaymentIntent ueber die Liste gefunden und nachgetragen" recovered 1
erw "nicht als failed freigegeben" cleared 0
OUT="$($SIM state "$(INV 4)")"
erwp "Einzugsdatensatz nachgetragen (processing, PaymentIntent)" c0 "processing|pi_*|10000|*"
erw "genau ein Einzugsdatensatz" collections 1
erw "Rechnung in_collection" invoice_status in_collection
OUT="$($SIM submit $A "$(INV 4)")"; erw "nach Nachtrag kein weiterer Einzug" result error
[[ "$(pis_fuer "$(INV 4)")" == 1 ]] && ok "weiterhin genau ein PaymentIntent" || bad "PaymentIntents INV4: $(pis_fuer "$(INV 4)")"
rm -f "$STRIPE_STUB_DIR/search_lag"
echo "7a2) Listenpruefung ueber mehrere Seiten (F-01): Seitengroesse 2, Treffer auf einer spaeteren Seite"
SQL "UPDATE invoices SET collection_status = 'none', open_amount = NULL WHERE id = '$(INV 1)'"
SQL "DELETE FROM payment_collections WHERE invoice_id = '$(INV 1)'; DELETE FROM collection_attempts WHERE invoice_id = '$(INV 1)'"
rm -f "$STRIPE_STUB_DIR"/idem/*
mode http500; OUT="$($SIM submit $A "$(INV 1)")"; erw "500: Versuch unknown" error_class CollectionUnknownOutcomeException; mode ok
sleep 1
for i in 1 2 3; do SQL "UPDATE invoices SET collection_status = 'none', open_amount = NULL WHERE id = '$(INVB $i)'"; SQL "DELETE FROM payment_collections WHERE invoice_id = '$(INVB $i)'; DELETE FROM collection_attempts WHERE invoice_id = '$(INVB $i)'"; $SIM submit $B "$(INVB $i)" >/dev/null 2>&1; done
touch "$STRIPE_STUB_DIR/search_lag"; echo 2 > "$STRIPE_STUB_DIR/pi_page_size"; : > "$STRIPE_STUB_DIR/list.log"
SQL "UPDATE collection_attempts SET created_at = DATE_SUB(created_at, INTERVAL 20 MINUTE) WHERE invoice_id = '$(INV 1)'"
OUT="$($SIM resolve $A)"
erw "Treffer auf spaeterer Seite gefunden und nachgetragen" recovered 1
erw "nicht freigegeben" cleared 0
[[ "$(wc -l < "$STRIPE_STUB_DIR/list.log")" -ge 2 ]] && ok "Liste wurde ueber mehrere Seiten gelesen ($(wc -l < "$STRIPE_STUB_DIR/list.log") Seiten)" || bad "F-01: nur $(wc -l < "$STRIPE_STUB_DIR/list.log") Seite(n) gelesen"
rm -f "$STRIPE_STUB_DIR/pi_page_size" "$STRIPE_STUB_DIR/search_lag"
echo "7b) Klaerung: Stripe hat nichts angelegt (HTTP 409): Freigabe erst nach Frist und Listenpruefung"
mode http409
OUT="$($SIM submit $A "$(INV 5)")"; erw "409 endet in der Klaerung" error_class CollectionUnknownOutcomeException
mode ok
OUT="$($SIM resolve $A)"; erw "junger Versuch bleibt offen" cleared 0
SQL "UPDATE collection_attempts SET created_at = DATE_SUB(created_at, INTERVAL 20 MINUTE) WHERE invoice_id = '$(INV 5)'"
OUT="$($SIM resolve $A)"; erw "kein PaymentIntent bei Stripe: Versuch freigegeben" cleared 1
OUT="$($SIM submit $A "$(INV 5)")"; erw "danach ist ein neuer Versuch erlaubt (fachlich geklaerter Fehlschlag)" result ok
OUT="$($SIM state "$(INV 5)")"; erw "zwei Versuche (failed, succeeded)" attempts 2
A0="$(feld "$OUT" a0_key)"; A1="$(feld "$OUT" a1_key)"; [[ -n "$A0" && "$A0" != "$A1" ]] && ok "neuer Versuch hat einen neuen Idempotenzschluessel" || bad "Schluessel gleich"
[[ "$(pis_fuer "$(INV 5)")" == 1 ]] && ok "genau ein PaymentIntent fuer Rechnung 5" || bad "PaymentIntents INV5: $(pis_fuer "$(INV 5)")"
echo "7c) Fachliche Ablehnung (HTTP 402): sofort neuer Versuch erlaubt"
mode http402
OUT="$($SIM submit $A "$(INV 6)")"; erw "402 ist ein endgueltiger Fehlschlag" error_class StripeException
OUT="$($SIM state "$(INV 6)")"; erwp "Versuch failed" a0 "failed|*"
mode ok
OUT="$($SIM submit $A "$(INV 6)")"; erw "neuer Versuch nach klarer Ablehnung erlaubt" result ok
[[ "$(pis_fuer "$(INV 6)")" == 1 ]] && ok "genau ein PaymentIntent fuer Rechnung 6" || bad "PaymentIntents INV6: $(pis_fuer "$(INV 6)")"
echo "7d) Antwort ohne JSON mit HTTP 502 (Proxy): unbekannt, keine Wiederholung"
SQL "UPDATE invoices SET collection_status = 'none', open_amount = NULL WHERE id = '$(INVB 1)'"; SQL "DELETE FROM payment_collections WHERE invoice_id = '$(INVB 1)'; DELETE FROM collection_attempts WHERE invoice_id = '$(INVB 1)'"; rm -f "$STRIPE_STUB_DIR"/idem/*
mode http502html
OUT="$($SIM submit $B "$(INVB 1)")"; erw "502 ohne JSON endet in der Klaerung" error_class CollectionUnknownOutcomeException
mode ok
echo "7e) Not-Stopp waehrend eines laufenden Einzugs (D-05, langsamer Stub 5 s)"
SQL "UPDATE invoices SET collection_status = 'none', open_amount = NULL WHERE id = '$(INVB 4)'"
mode slow5
$SIM submit $B "$(INVB 4)" > "$T/slow.txt" 2>&1 & SLOWPID=$!
sleep 2
OUT="$($SIM pause $B 1)"; erw "Not-Stopp waehrend des laufenden Einzugs gesetzt" result ok
D="$(feld "$OUT" dauer_ms)"; [[ -n "$D" && $D -lt 2500 ]] && ok "Not-Stopp wartet nicht auf den Einzug (${D} ms)" || bad "D-05: Not-Stopp brauchte ${D} ms"
wait $SLOWPID; OUT="$(cat "$T/slow.txt")"; erw "laufender Einzug endet regulaer" result ok
mode ok
OUT="$($SIM pause $B 0)"; erw "Not-Stopp wieder aufgehoben" result ok
if [[ "${COLLECTIONS_CHECK_SLOW:-0}" == 1 ]]; then
    echo "7f) Zeitueberschreitung (31 s)"
    SQL "UPDATE invoices SET collection_status = 'none', open_amount = NULL WHERE id = '$(INVB 2)'"; SQL "DELETE FROM payment_collections WHERE invoice_id = '$(INVB 2)'; DELETE FROM collection_attempts WHERE invoice_id = '$(INVB 2)'"; rm -f "$STRIPE_STUB_DIR"/idem/*
    VORHER=$(pis_fuer "$(INVB 2)")
    mode timeout
    OUT="$($SIM submit $B "$(INVB 2)")"; erw "Timeout endet in der Klaerung" error_class CollectionUnknownOutcomeException
    mode ok
    [[ "$(pis_fuer "$(INVB 2)")" == $((VORHER + 1)) ]] && ok "PaymentIntent trotz Timeout genau einmal angelegt" || bad "PaymentIntents INVB2: $(pis_fuer "$(INVB 2)") (vorher $VORHER)"
    OUT="$($SIM submit $B "$(INVB 2)")"; erw "bis zur Klaerung kein weiterer Versuch" result error
fi

echo "8) Terminierte Einzuege: Fenster, parallele Laeufe, Beanspruchung"
SQL "UPDATE invoices SET collection_status = 'none' WHERE tenant_id = '$B'"
SQL "DELETE FROM payment_collections WHERE tenant_id = '$B'; DELETE FROM collection_attempts WHERE tenant_id = '$B'"
rm -f "$STRIPE_STUB_DIR"/idem/*; : > "$STRIPE_STUB_DIR/pi.log"
# Naechster Werktag (Mo bis Fr): validate_scheduled_date() weist Wochenenden ab; ein Lauf am Freitag oder Samstag terminierte
# sonst auf einen unzulaessigen Tag (Befund 11.09.2026, 41 datumsabhaengige Fehlschlaege).
MORGEN="$(date -d '+1 day' +%F)"; while [[ "$(date -d "$MORGEN" +%u)" -ge 6 ]]; do MORGEN="$(date -d "$MORGEN +1 day" +%F)"; done
for i in 1 2 3 4 5 6; do OUT="$($SIM submit $B "$(INVB $i)" - "$MORGEN")"; [[ "$(feld "$OUT" result)" == ok ]] || bad "Terminierung $i: $(feld "$OUT" error)"; done
[[ "$(SQL "SELECT COUNT(*) FROM payment_collections WHERE tenant_id = '$B' AND stripe_status = 'scheduled'")" == 6 ]] && ok "sechs terminierte Einzuege" || bad "terminierte Einzuege"
[[ "$(pis)" == 0 ]] && ok "Terminierung erzeugt keinen PaymentIntent" || bad "PaymentIntents nach Terminierung: $(pis)"
SQL "UPDATE payment_collections SET scheduled_date = CURDATE() WHERE tenant_id = '$B'"
PIDS=(); for i in 1 2 3; do $SIM process $B > "$T/proc$i.txt" 2>&1 & PIDS+=($!); done; wait "${PIDS[@]}"
SUBM=$(cat "$T"/proc*.txt | sed -n 's/^submitted=//p' | paste -sd+ | bc)
[[ "$SUBM" == 6 ]] && ok "drei parallele Laeufe reichen zusammen genau sechs Einzuege ein" || bad "parallel process: submitted=$SUBM"
[[ "$(pis)" == 6 ]] && ok "genau sechs PaymentIntents fuer sechs terminierte Einzuege" || bad "PaymentIntents: $(pis)"
[[ "$(SQL "SELECT COUNT(*) FROM payment_collections WHERE tenant_id = '$B' AND stripe_status = 'processing' AND scheduled_submitted = 1")" == 6 ]] && ok "alle sechs processing und als eingereicht markiert" || bad "Zustand nach paralleler Einreichung"
OUT="$($SIM process $B)"; erw "erneuter Lauf findet nichts Faelliges" submitted 0

echo "9) Fenster: Einzug erst im Fenster (Fensterlogik ueber Zeitpunkt)"
php -r 'require $argv[1]."/php-ionos/bin/_cli.php"; require_once $argv[1]."/php-ionos/app/collections.php"; $GLOBALS["config"]["collections"]=["window_enabled"=>true,"window_start"=>"23:00","window_end"=>"06:00","grace_hours"=>4];
echo "w1=", collections_window_open(new DateTimeImmutable("2026-09-10 12:00:00")) ? 1 : 0, "\n";
echo "w2=", collections_window_open(new DateTimeImmutable("2026-09-10 23:30:00")) ? 1 : 0, "\n";
echo "w3=", collections_window_open(new DateTimeImmutable("2026-09-11 05:59:00")) ? 1 : 0, "\n";
echo "w4=", collections_window_open(new DateTimeImmutable("2026-09-11 06:00:00")) ? 1 : 0, "\n";
echo "e1=", collections_earliest_submit(new DateTimeImmutable("2026-09-10 12:00:00"))->format("Y-m-d H:i"), "\n";
echo "e2=", collections_earliest_submit(new DateTimeImmutable("2026-10-25 01:30:00"))->format("Y-m-d H:i"), "\n";
echo "e3=", collections_earliest_submit(new DateTimeImmutable("2026-03-28 22:30:00"))->format("Y-m-d H:i"), "\n";' "$ROOT" > "$T/win.txt" 2>&1; OUT="$(cat "$T/win.txt")"
erw "12:00 ausserhalb" w1 0; erw "23:30 im Fenster" w2 1; erw "05:59 im Fenster" w3 1; erw "06:00 ausserhalb" w4 0
erw "Karenz 4 h ab 12:00 -> Fensterbeginn 23:00" e1 "2026-09-10 23:00"
erwp "Zeitumstellung Winter (25.10. 01:30 + 4 h): Ergebnis im Fenster, mindestens 4 h spaeter" e2 "2026-10-25 0[45]:30"
erw "Zeitumstellung Sommer (28.03. 22:30 + 4 h => 03:30 liegt im Fenster)" e3 "2026-03-29 03:30"

echo "10) Haengender Einzug in submitting ohne Versuch (A-02)"
SQL "UPDATE invoices SET collection_status = 'none' WHERE id = '$(INVB 1)'"
SQL "INSERT INTO payment_collections (id, tenant_id, invoice_id, mandate_id, customer_iban_id, amount_cents, currency, stripe_status, is_scheduled, scheduled_date, scheduled_submitted, created_at, updated_at)
     SELECT 'cccccccc-0000-0000-0000-000000000001', tenant_id, invoice_id, mandate_id, customer_iban_id, 10000, 'EUR', 'submitting', 1, CURDATE(), 0, NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 30 MINUTE FROM payment_collections WHERE invoice_id = '$(INVB 1)' LIMIT 1"
SQL "DELETE FROM collection_attempts WHERE tenant_id = '$B'"
OUT="$($SIM resolve $B)"
[[ "$(SQL "SELECT stripe_status FROM payment_collections WHERE id = 'cccccccc-0000-0000-0000-000000000001'")" == scheduled ]] && ok "submitting ohne Versuch wird nach 15 Minuten wieder scheduled (auch ohne offene Versuche)" || bad "A-02: bleibt $(SQL "SELECT stripe_status FROM payment_collections WHERE id = 'cccccccc-0000-0000-0000-000000000001'")"
SQL "DELETE FROM payment_collections WHERE id = 'cccccccc-0000-0000-0000-000000000001'"

echo "11) Circuit Breaker offen: Zurueckstellung statt Fehlschlag (A-06)"
SQL "UPDATE invoices SET collection_status = 'none' WHERE id = '$(INVB 2)'"
SQL "DELETE FROM payment_collections WHERE invoice_id = '$(INVB 2)'; DELETE FROM collection_attempts WHERE invoice_id = '$(INVB 2)'"
OUT="$($SIM submit $B "$(INVB 2)" - "$MORGEN")"; erw "Terminierung" result ok
CID="$(feld "$OUT" collection_id)"
SQL "UPDATE payment_collections SET scheduled_date = CURDATE() WHERE id = '$CID'"
SQL "INSERT INTO api_circuits (api, state, failures, opened_at, next_probe_at, updated_at) VALUES ('stripe', 'open', 9, NOW(), NOW() + INTERVAL 1 HOUR, NOW()) ON DUPLICATE KEY UPDATE state='open', next_probe_at = NOW() + INTERVAL 1 HOUR"
OUT="$($SIM process $B)"
erw "offener Circuit Breaker: Einzug zurueckgestellt" deferred 1
erw "nicht als fehlgeschlagen markiert" failed 0
[[ "$(SQL "SELECT stripe_status FROM payment_collections WHERE id = '$CID'")" == scheduled ]] && ok "Einzug bleibt scheduled" || bad "A-06: Einzug ist $(SQL "SELECT stripe_status FROM payment_collections WHERE id = '$CID'")"
[[ "$(SQL "SELECT COUNT(*) FROM collection_attempts WHERE invoice_id = '$(INVB 2)' AND status = 'failed'")" == 0 ]] && ok "kein verbrannter Versuch" || bad "A-06: Versuch als failed journalisiert"
SQL "DELETE FROM api_circuits WHERE api = 'stripe'"
OUT="$($SIM process $B)"; erw "nach Schliessen des Breakers eingereicht" submitted 1

echo "12) Bereits eingezogene Rechnung nicht erneut terminierbar (A-10)"
SQL "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE id = '$CID'"
SQL "UPDATE invoices SET collection_status = 'collected' WHERE id = '$(INVB 2)'"
OUT="$($SIM submit $B "$(INVB 2)" - "$MORGEN")"
erw "collected-Rechnung ohne Restbetrag: Terminierung abgewiesen" result error
[[ "$(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")" == collected ]] && ok "Rechnungsstatus bleibt collected" || bad "A-10: Status $(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")"
echo "12a) Teilweise eingezogen: Rest terminierbar"
SQL "UPDATE payment_collections SET amount_cents = 6000 WHERE id = '$CID'"
OUT="$($SIM submit $B "$(INVB 2)" - "$MORGEN")"; erw "Rest ohne Bestaetigung: Rueckfrage" result error
OUT="$($SIM submit $B "$(INVB 2)" 4000 "$MORGEN")"; erw "Rest 40,00 EUR mit Bestaetigung terminierbar" result ok
OUT="$($SIM state "$(INVB 2)")"; printf '%s\n' "$OUT" | grep -qE '^c[0-9]+=scheduled\|-\|4000\|' && ok "zweiter Einzug ueber 4000 Cent scheduled" || bad "kein terminierter Einzug ueber 4000 Cent: $(printf '%s\n' "$OUT" | grep -E '^c[0-9]+=' | tr '\n' ' ')"
CREST="$(SQL "SELECT id FROM payment_collections WHERE invoice_id = '$(INVB 2)' AND stripe_status = 'scheduled' AND amount_cents = 4000 LIMIT 1")"
OUT="$($SIM cancel $B "$CREST")"; erw "Storno des terminierten Rests" result ok
[[ "$(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")" == open ]] && ok "Storno des Rests: Rechnung mit Teileinzug 60,00 EUR gilt als offen, nicht als eingezogen (F-05)" || bad "A-10/F-05 Storno: Status $(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")"

echo "13) Erstattung nach verlorenem Erfolgsereignis (A-14)"
SQL "UPDATE payment_collections SET stripe_status = 'processing', completed_at = NULL, amount_cents = 10000 WHERE id = '$CID'"
SQL "UPDATE invoices SET collection_status = 'in_collection' WHERE id = '$(INVB 2)'"
OUT="$($SIM refund $B "$CID" 10000)"; erw "Vollerstattung uebernommen" result changed
OUT="$($SIM state "$(INVB 2)")"
erw "Rechnung mit Klaerungsbedarf" requires_review 1
erw "Rechnung nach Vollerstattung wieder open (auch aus in_collection)" invoice_status open
printf '%s\n' "$OUT" | grep -qE '^c[0-9]+=refunded\|' && ok "Einzug refunded" || bad "kein Einzug im Zustand refunded"
OUT="$($SIM review_clear $B "$(INVB 2)")"; erw "Klaerung abgeschlossen" result ok
OUT="$($SIM submit $B "$(INVB 2)" - "$MORGEN")"; erw "danach wieder terminierbar" result ok

echo "13b) Faelligkeitslauf trifft bezahlte Rechnung (F-04): Storno statt Fehlschlag"
C5="$(SQL "SELECT id FROM payment_collections WHERE invoice_id = '$(INVB 2)' AND stripe_status = 'scheduled' ORDER BY created_at DESC LIMIT 1")"
[[ -n "$C5" ]] && ok "terminierter Einzug aus 13 vorhanden" || bad "kein terminierter Einzug fuer 13b"
SQL "UPDATE payment_collections SET scheduled_date = CURDATE() WHERE id = '$C5'"
echo "{\"bbbbbbbb-5555-0000-0000-000000000002\": 0}" > "$LEX_FAKE_FILE"
OUT="$($SIM process $B)"; erw "kein Fehlschlag" failed 0; erw "als storniert gezaehlt" cancelled_covered 1
echo '{}' > "$LEX_FAKE_FILE"
[[ "$(SQL "SELECT stripe_status FROM payment_collections WHERE id = '$C5'")" == cancelled ]] && ok "Einzug storniert, nicht failed" || bad "F-04: $(SQL "SELECT stripe_status FROM payment_collections WHERE id = '$C5'")"
[[ "$(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")" == open ]] && ok "Rechnung nicht als fehlgeschlagen markiert" || bad "F-04 Rechnung: $(SQL "SELECT collection_status FROM invoices WHERE id = '$(INVB 2)'")"

echo "14) Webhook-Endpunkt (echter HTTP-Aufruf gegen stripe-webhook.php)"
( cd "$ROOT/php-ionos" && SMARTEINZUG_CONFIG="$SMARTEINZUG_CONFIG" php -S "127.0.0.1:$WPORT" >"$T/web.log" 2>&1 ) &
WEB_PID=$!
for i in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$WPORT/health.php" && break; sleep 0.1; done
PI1="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INVB 3)'")"
hook() { # $1 secret, $2 payload-datei -> HTTP-Code + Body in $HOOK_CODE/$HOOK_BODY
    local hdr; hdr="$($SIM sign "$1" "$2" | sed -n 's/^header=//p')"
    HOOK_BODY="$(curl -s -o - -w '\n%{http_code}' -H "Stripe-Signature: $hdr" -H 'Content-Type: application/json' --data-binary "@$2" "http://127.0.0.1:$WPORT/stripe-webhook.php")"
    HOOK_CODE="${HOOK_BODY##*$'\n'}"; HOOK_BODY="${HOOK_BODY%$'\n'*}"
}
ev() { # $1 id, $2 type, $3 created, $4 object-json
    printf '{"id":"%s","object":"event","type":"%s","created":%s,"livemode":false,"data":{"object":%s}}' "$1" "$2" "$3" "$4"
}
NOW=$(date +%s)
ev evt_1 payment_intent.processing $((NOW-100)) "{\"id\":\"$PI1\",\"object\":\"payment_intent\",\"status\":\"processing\",\"amount\":10000,\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e1.json"
hook whsec_FALSCH "$T/e1.json"; [[ "$HOOK_CODE" == 200 && "$HOOK_BODY" == ok ]] && ok "falsche Signatur: 200 ohne Wirkung (Stripe soll nicht wiederholen)" || bad "falsche Signatur: $HOOK_CODE $HOOK_BODY"
hook whsec_stub_FB "$T/e1.json"; [[ "$HOOK_CODE" == 200 ]] && ok "processing-Ereignis angenommen" || bad "processing: $HOOK_CODE $HOOK_BODY"
ev evt_2 payment_intent.succeeded $((NOW-50)) "{\"id\":\"$PI1\",\"object\":\"payment_intent\",\"status\":\"succeeded\",\"amount\":10000,\"latest_charge\":\"ch_x\",\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e2.json"
hook whsec_stub_FB "$T/e2.json"; [[ "$HOOK_CODE" == 200 ]] && ok "succeeded-Ereignis angenommen" || bad "succeeded: $HOOK_CODE $HOOK_BODY"
OUT="$($SIM state "$(INVB 3)")"; erwp "Einzug succeeded" c0 "succeeded|*"; erw "Rechnung collected" invoice_status collected
hook whsec_stub_FB "$T/e2.json"; [[ "$HOOK_CODE" == 200 && "$HOOK_BODY" == ok ]] && ok "doppelte Zustellung: bereits verarbeitet" || bad "Duplikat: $HOOK_CODE $HOOK_BODY"
ev evt_3 payment_intent.processing $((NOW-80)) "{\"id\":\"$PI1\",\"object\":\"payment_intent\",\"status\":\"processing\",\"amount\":10000,\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e3.json"
hook whsec_stub_FB "$T/e3.json"
OUT="$($SIM state "$(INVB 3)")"; erwp "verspaetetes processing ueberschreibt succeeded nicht" c0 "succeeded|*"
ev evt_4 charge.dispute.created $((NOW-10)) "{\"id\":\"dp_1\",\"object\":\"dispute\",\"payment_intent\":\"$PI1\",\"charge\":\"ch_x\",\"amount\":10000}" > "$T/e4.json"
hook whsec_stub_FB "$T/e4.json"; [[ "$HOOK_CODE" == 200 ]] && ok "Ruecklastschrift angenommen" || bad "dispute: $HOOK_CODE $HOOK_BODY"
OUT="$($SIM state "$(INVB 3)")"; erwp "Einzug disputed" c0 "disputed|*"; erw "Rechnung mit Klaerungsbedarf (kein automatischer Neu-Einzug)" requires_review 1; erw "Rechnungsstatus failed" invoice_status failed
OUT="$($SIM submit $B "$(INVB 3)")"; erw "Rechnung mit Klaerungsbedarf nicht einziehbar" result error
echo "14a) Ereignis einer anderen Firma"
PI4="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INVB 4)'")"
ev evt_5 payment_intent.succeeded $((NOW+3000)) "{\"id\":\"$PI4\",\"object\":\"payment_intent\",\"status\":\"succeeded\",\"amount\":10000,\"metadata\":{\"tenant_id\":\"$A\"}}" > "$T/e5.json"
hook whsec_stub_FA "$T/e5.json"; [[ "$HOOK_CODE" == 200 ]] && ok "Firma A signiert ein Ereignis zu einem PaymentIntent von Firma B: keine Wirkung" || bad "fremdes Ereignis: $HOOK_CODE"
OUT="$($SIM state "$(INVB 4)")"; erwp "Einzug von Firma B unveraendert processing" c0 "processing|*"
ev evt_6 payment_intent.succeeded $((NOW-5)) "{\"id\":\"$PI4\",\"object\":\"payment_intent\",\"status\":\"succeeded\",\"amount\":10000,\"latest_charge\":\"ch_y\",\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e6.json"
hook whsec_stub_FB "$T/e6.json"; [[ "$HOOK_CODE" == 200 ]] && ok "echtes Ereignis von Firma B angenommen" || bad "B succeeded: $HOOK_CODE $HOOK_BODY"
OUT="$($SIM state "$(INVB 4)")"; erwp "Reihenfolgepruefung durch fremdes Ereignis nicht vergiftet (C-07)" c0 "succeeded|*"
echo "14b) payment_failed mit Mandatsproblem (A-08)"
PI5="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INVB 5)'")"
ev evt_7 payment_intent.payment_failed $((NOW-4)) "{\"id\":\"$PI5\",\"object\":\"payment_intent\",\"status\":\"requires_payment_method\",\"amount\":10000,\"last_payment_error\":{\"message\":\"The customer has disputed this debit.\",\"decline_code\":\"debit_not_authorized\"},\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e7.json"
hook whsec_stub_FB "$T/e7.json"; [[ "$HOOK_CODE" == 200 ]] && ok "payment_failed angenommen" || bad "payment_failed: $HOOK_CODE"
OUT="$($SIM state "$(INVB 5)")"; erwp "Einzug failed" c0 "failed|*"
erw "Mandatsproblem (debit_not_authorized): Klaerungsbedarf statt automatischer Wiederholung" requires_review 1
PI6="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INVB 6)'")"
ev evt_8 payment_intent.payment_failed $((NOW-3)) "{\"id\":\"$PI6\",\"object\":\"payment_intent\",\"status\":\"requires_payment_method\",\"amount\":10000,\"last_payment_error\":{\"message\":\"Insufficient funds.\",\"decline_code\":\"insufficient_funds\"},\"metadata\":{\"tenant_id\":\"$B\"}}" > "$T/e8.json"
hook whsec_stub_FB "$T/e8.json"
OUT="$($SIM state "$(INVB 6)")"; erwp "Einzug failed" c0 "failed|*"; erw "Deckungsproblem: kein Klaerungsbedarf (Wiederholung bleibt moeglich)" requires_review 0
echo "14c) Ereignis vor lokaler Speicherung (junger Versuch): Stripe soll wiederholen (A-07)"
SQL "INSERT INTO collection_attempts (id, tenant_id, invoice_id, idempotency_key, amount_cents, status) VALUES ('dddddddd-0000-0000-0000-000000000001', '$A', '$(INV 1)', REPEAT('e', 64), 10000, 'pending')"
ev evt_9 payment_intent.processing $((NOW-2)) "{\"id\":\"pi_unbekannt\",\"object\":\"payment_intent\",\"status\":\"processing\",\"amount\":10000,\"metadata\":{\"tenant_id\":\"$A\",\"attempt_key\":\"$(printf 'e%.0s' $(seq 1 64))\"}}" > "$T/e9.json"
hook whsec_stub_FA "$T/e9.json"; [[ "$HOOK_CODE" == 500 ]] && ok "Einzug noch nicht festgeschrieben: HTTP 500, Stripe wiederholt" || bad "A-07: $HOOK_CODE $HOOK_BODY"
[[ "$(SQL "SELECT COUNT(*) FROM webhook_events WHERE id = 'evt_9'")" == 0 ]] && ok "Beanspruchung freigegeben" || bad "Beanspruchung nicht freigegeben"
[[ "$(SQL "SELECT COUNT(*) FROM payment_collections WHERE stripe_payment_intent_id = 'pi_unbekannt'")" == 0 ]] && ok "kein Einzugsdatensatz aus jungem Versuch" || bad "Backfill aus jungem Versuch"
SQL "DELETE FROM collection_attempts WHERE id = 'dddddddd-0000-0000-0000-000000000001'"
ev evt_10 payment_intent.processing $((NOW-1)) "{\"id\":\"pi_fremd\",\"object\":\"payment_intent\",\"status\":\"processing\",\"amount\":10000,\"metadata\":{\"tenant_id\":\"$A\"}}" > "$T/e10.json"
hook whsec_stub_FA "$T/e10.json"; [[ "$HOOK_CODE" == 200 ]] && ok "unbekannter PaymentIntent ohne Versuch: 200 (nichts zu tun)" || bad "unbekannter PI: $HOOK_CODE"

P14=$(pis)
echo "14d) Statusabgleich erkennt Ruecklastschrift und Erstattung Wochen nach dem Erfolg (4.73)"
charge_of() { php -r '$p = json_decode((string)file_get_contents($argv[1]), true) ?: []; echo (string)($p[$argv[2]]["latest_charge"] ?? "");' "$STRIPE_STUB_DIR/pis.json" "$1"; }
charge_set() { # $1 charge-id, $2 feld, $3 json-wert: Ueberschreibung der Charge im Stub (charges.json)
    php -r '$f = $argv[1]; $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; $d[$argv[2]][$argv[3]] = json_decode($argv[4], true); file_put_contents($f, json_encode($d));' "$STRIPE_STUB_DIR/charges.json" "$1" "$2" "$3"
}
PIA1="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INV 1)' LIMIT 1")"; CHA1="$(charge_of "$PIA1")"
PIA2="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INV 2)' LIMIT 1")"; CHA2="$(charge_of "$PIA2")"
PIA3="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INV 3)' LIMIT 1")"; CHA3="$(charge_of "$PIA3")"
[[ -n "$CHA1" && -n "$CHA2" && -n "$CHA3" ]] && ok "Charges der Einzuege 1 bis 3 von Firma A beim Stub bekannt" || bad "Charges fehlen: '$CHA1' '$CHA2' '$CHA3'"
OUT="$($SIM sync_status $A)"; erw "Abgleich ohne Aenderung bei Stripe: keine Ruecklastschrift" disputed 0; erw "keine Erstattung" refunded 0
# Einzug 1: Wochen spaeter Ruecklastschrift bei Stripe, Webhook blieb aus
SQL "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE invoice_id = '$(INV 1)'"
SQL "UPDATE invoices SET collection_status = 'collected' WHERE id = '$(INV 1)'"
charge_set "$CHA1" disputed true
OUT="$($SIM sync_status $A)"; erw "Ruecklastschrift des erfolgreichen Einzugs erkannt" disputed 1
erwp "abgeschlossene Einzuege wurden in die Rueckschau einbezogen" reviewed "[1-9]*"
OUT="$($SIM state "$(INV 1)")"; erwp "Einzug disputed" c0 "disputed|*"; erw "Rechnung failed" invoice_status failed
erw "Klaerungsbedarf gesetzt (kein automatischer Neu-Einzug)" requires_review 1; erwp "Grund nennt die Ruecklastschrift" review_reason "*widerrufen*"
OUT="$($SIM submit $A "$(INV 1)")"; erw "Rechnung nach Ruecklastschrift nicht ohne Klaerung einziehbar" result error
OUT="$($SIM sync_status $A)"; erw "Wiederholung: Ruecklastschrift nicht doppelt vermerkt (idempotent)" disputed 0
[[ "$(SQL "SELECT COUNT(*) FROM audit_log WHERE action = 'collection_disputed' AND target_id = (SELECT id FROM payment_collections WHERE invoice_id = '$(INV 1)')")" == 1 ]] && ok "genau ein Audit-Eintrag collection_disputed" || bad "Audit collection_disputed: $(SQL "SELECT COUNT(*) FROM audit_log WHERE action = 'collection_disputed'")"
# Einzug 3: Vollerstattung bei Stripe
SQL "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE invoice_id = '$(INV 3)'"
SQL "UPDATE invoices SET collection_status = 'collected' WHERE id = '$(INV 3)'"
charge_set "$CHA3" amount_refunded 4000
OUT="$($SIM sync_status $A)"; erw "Vollerstattung erkannt" refunded 1; erw "keine weitere Ruecklastschrift" disputed 0
OUT="$($SIM state "$(INV 3)")"; erwp "Einzug refunded" c0 "refunded|*"; erw "Erstattungsbetrag uebernommen" c0_refunded 4000
erw "Rechnung wieder open" invoice_status open; erw "Klaerungsbedarf gesetzt" requires_review 1
OUT="$($SIM sync_status $A)"; erw "Wiederholung: Erstattungsstand unveraendert" refunded 0
# Einzug 2: Teilerstattung
SQL "UPDATE payment_collections SET stripe_status = 'succeeded', completed_at = NOW() WHERE invoice_id = '$(INV 2)'"
SQL "UPDATE invoices SET collection_status = 'collected' WHERE id = '$(INV 2)'"
charge_set "$CHA2" amount_refunded 2500
OUT="$($SIM sync_status $A)"; erw "Teilerstattung erkannt" refunded 1
OUT="$($SIM state "$(INV 2)")"; erwp "Einzug bleibt succeeded" c0 "succeeded|*"; erw "Teilbetrag vermerkt" c0_refunded 2500
erw "Rechnung bleibt collected" invoice_status collected; erw "Klaerungsbedarf gesetzt" requires_review 1
# Rueckschau-Grenze: aelter als sync_lookback_days wird nicht geprueft
SQL "UPDATE payment_collections SET submitted_at = NOW() - INTERVAL 400 DAY, completed_at = NOW() - INTERVAL 400 DAY, created_at = NOW() - INTERVAL 400 DAY WHERE invoice_id = '$(INV 2)'"
charge_set "$CHA2" disputed true
OUT="$($SIM sync_status $A)"; erw "Einzug ausserhalb der Rueckschau (400 Tage) wird nicht geprueft" disputed 0
erw "Rueckschau der Vorgabe 70 Tage" lookback_days 70
OUT="$($SIM state "$(INV 2)")"; erwp "Einzug ausserhalb der Rueckschau unveraendert" c0 "succeeded|*"
SQL "UPDATE payment_collections SET submitted_at = NOW(), completed_at = NOW(), created_at = NOW() WHERE invoice_id = '$(INV 2)'"
OUT="$($SIM sync_status $A)"; erw "innerhalb der Rueckschau: Ruecklastschrift erkannt" disputed 1
OUT="$($SIM state "$(INV 2)")"; erwp "Einzug disputed" c0 "disputed|*"
# Mandantentrennung: Ruecklastschrift zu einem Einzug von Firma B darf der Abgleich von Firma A nicht vermerken
PIB4="$(SQL "SELECT stripe_payment_intent_id FROM payment_collections WHERE invoice_id = '$(INVB 4)' LIMIT 1")"; CHB4="$(charge_of "$PIB4")"
charge_set "$CHB4" disputed true
OUT="$($SIM sync_status $A)"; erw "Abgleich von Firma A vermerkt nichts an Einzuegen von Firma B" disputed 0
OUT="$($SIM state "$(INVB 4)")"; erwp "Einzug von Firma B unveraendert succeeded" c0 "succeeded|*"
OUT="$($SIM sync_status $B)"; erw "Abgleich von Firma B erkennt die eigene Ruecklastschrift" disputed 1
OUT="$($SIM state "$(INVB 4)")"; erwp "Einzug von Firma B disputed" c0 "disputed|*"; erw "Rechnung von Firma B mit Klaerungsbedarf" requires_review 1
grep -q "expand=latest_charge" "$STRIPE_STUB_DIR/list.log" && ok "Rueckschau nutzt die Liste mit eingebetteter Charge (ein Aufruf je 100 Einzuege)" || bad "Liste ohne expand=data.latest_charge"
[[ "$(pis)" == "$P14" ]] && ok "kein PaymentIntent durch den Statusabgleich (reiner Lesezugriff)" || bad "Statusabgleich hat PaymentIntents angelegt: $(pis) statt $P14"

echo "15) Statische Sicherungen"
grep -q "AND stripe_status IN ('submitting', 'scheduled')" "$ROOT/php-ionos/app/collections.php" && ok "Fehlermarkierung nur aus submitting/scheduled (A-11)" || bad "A-11 Fehlermarkierung ohne Zustandsbedingung"
[[ "$(grep -c "webhook_retry('Datenbankfehler bei Firmenzuordnung" "$ROOT/php-ionos/stripe-webhook.php")" == 2 ]] && grep -q "http_response_code(500)" <(sed -n '/^function webhook_retry/,/^}/p' "$ROOT/php-ionos/stripe-webhook.php") && ok "Datenbankfehler bei der Firmenzuordnung antwortet mit 500 (A-03)" || bad "A-03: Datenbankfehler mit 200 quittiert"
grep -q "outcomeUnknown = \$status === 0 || \$status >= 500 || \$status < 400" "$ROOT/php-ionos/app/stripe.php" && ok "Antwort ohne JSON: nur klare 4xx sind endgueltig" || bad "Antwort ohne JSON mit 2xx gilt als endgueltiger Fehlschlag"

echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ $FAIL -eq 0 ]]
