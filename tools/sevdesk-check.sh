#!/usr/bin/env bash
# sevdesk-Adapter (app/sevdesk.php), Freigabetermin, Verbindung je Firma, Wechselsperre-Reset, Worker-Pool und
# Scheduler-Auswahl: statische Pruefungen plus Prueffaelle gegen einen lokalen HTTP-Stub (tools/lib/sevdesk-stub.php)
# und eine temporaere MariaDB (tools/lib/sevdesk-sim.php). Kein Zugriff auf die echte sevdesk-API.
# Aufruf: bash tools/sevdesk-check.sh   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }
erwp() { [[ "$(feld "$OUT" "$2")" == $3 ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet Muster $3)"; }

echo "1) Statische Pruefungen"
for f in app/sevdesk.php app/integration_state.php app/invoice_source.php app/invoice_source_switch.php app/integrations.php app/jobs.php app/queue.php app/sync_state.php app/monitor.php settings.php admin.php admin-system.php invoices.php app/legal_drafts.php; do
    php -l "$ROOT/php-ionos/$f" >/dev/null 2>&1 && ok "php -l $f" || bad "php -l $f"
done
S="$ROOT/php-ionos/app/sevdesk.php"
grep -q "sevdesk_api_verified" "$S" && grep -q "'open_amount'    => \$open" "$S" && ok "Restbetrag nur mit sevdesk_api_verified, sonst null" || bad "Restbetragsschutz"
! grep -q "\$this->apiKey" <(grep -i "RuntimeException" "$S") && ok "kein Token in Fehlermeldungen" || bad "Token in Fehlermeldung"
grep -q "Authorization: ' . \$this->authPrefix . \$this->apiKey" "$S" && ! grep -q "token=" "$S" && ok "Token nur im Header Authorization, nie in der URL" || bad "Token-Uebergabe"
grep -q "integration_switch(self::CODE, 'connect')" "$S" && ok "Client verweigert ohne Freigabe" || bad "Freigabepruefung im Client"
grep -q "api_call_gate('sevdesk'" "$S" && grep -q "circuit_failure('sevdesk'" "$S" && ok "Drosselung und Circuit Breaker" || bad "api_call_gate/circuit_failure"
grep -q "\$type !== 'RE'" "$S" && ok "nur Belegtyp RE, Mahnungen und Teilrechnungen ausgeschlossen" || bad "Belegtypfilter"
grep -q "_release_at" "$ROOT/php-ionos/app/integration_state.php" && grep -q "Europe/Berlin" "$ROOT/php-ionos/app/integration_state.php" && ok "Freigabetermin (Kalendertag Europe/Berlin)" || bad "Freigabetermin"
grep -q "sevdesk_release_at', '2026-09-30'" "$ROOT/php-ionos/sql/migrations/028_sevdesk_verbindung.sql" && grep -q "sevdesk_api_key_encrypted" "$ROOT/php-ionos/sql/schema.sql" && grep -q "invoice_source_lock_reset_at" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 028 und schema.sql" || bad "Migration 028"
grep -q "'sevdesk'     => \['sync_run_sevdesk'\]" "$ROOT/php-ionos/app/jobs.php" && grep -q "case 'sync_run_sevdesk'" "$ROOT/php-ionos/app/jobs.php" && ok "Pool sevdesk und Jobtyp sync_run_sevdesk" || bad "Pool/Jobtyp"
grep -q "worker-sevdesk:" "$ROOT/deploy/vps/docker-compose.yml" && grep -q "worker-sevdesk:" "$ROOT/deploy/vps/docker-compose.staging.yml" && grep -q -- "--pool=sevdesk" "$ROOT/deploy/vps/docker-compose.yml" && ok "Container worker-sevdesk (prod und staging)" || bad "worker-sevdesk"
grep -q "invoice_source_sync_job_type" "$ROOT/php-ionos/invoices.php" && grep -q "invoice_source_sync_job_type" "$ROOT/php-ionos/admin-system.php" && ok "manuelle Synchronisation waehlt den Jobtyp nach Buchhaltungssystem" || bad "Jobtyp manuell"
grep -q "QUEUE_SYNC_TYPES" "$ROOT/php-ionos/app/sync_state.php" && ok "Sync-Zustand kennt beide Jobtypen" || bad "sync_state Jobtypen"
grep -q "sevdesk_api_key_encrypted = NULL, sevdesk_connected = 0" "$ROOT/php-ionos/app/invoice_source_switch.php" && ok "Wechsel von sevdesk weg loescht den Token" || bad "Trennung beim Wechsel"
grep -q "'org_lock_reset' => 'companies.manage'" "$ROOT/php-ionos/admin.php" && grep -q "invoice_source_lock_reset(" "$ROOT/php-ionos/admin.php" && ok "Adminaktion Wechselsperre aufheben mit Berechtigung companies.manage" || bad "org_lock_reset"
! sed -n "/action === 'org_lock_reset'/,/elseif/p" "$ROOT/php-ionos/admin.php" | grep -q require_recent_totp && ok "Sperre aufheben ohne 2FA-Code (kein Geldfluss, Regel 4.36)" || bad "org_lock_reset mit 2FA"
grep -q "save_sevdesk\|verify_sevdesk\|disconnect_sevdesk" "$ROOT/php-ionos/settings.php" && grep -q "support_guard();" "$ROOT/php-ionos/settings.php" && ok "Einstellungen: sevdesk verbinden, pruefen, trennen (Support-Modus gesperrt)" || bad "settings sevdesk"
grep -q "## A2. Abruf aus sevdesk" "$ROOT/php-ionos/app/legal_drafts.php" && grep -q "2026-09-entwurf-2" "$ROOT/php-ionos/app/legal_drafts.php" && ok "AVV-Entwurf: Anlage 1 mit sevdesk (neue Fassung)" || bad "AVV Anlage 1"
grep -q "'sevdesk'  => \['name' => 'sevdesk-Anbindung'" "$ROOT/php-ionos/app/monitor.php" && ok "Monitoring-Komponente sevdesk" || bad "Monitoring"
! grep -q "—" "$S" "$ROOT/php-ionos/admin.php" "$ROOT/php-ionos/settings.php" && ok "keine Gedankenstriche" || bad "Gedankenstrich"

echo "2) Prueffaelle gegen Stub und temporaere MariaDB"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"
STUB_PID=""; STUB2_PID=""
cleanup() { [[ -n "$STUB_PID" ]] && kill "$STUB_PID" 2>/dev/null; [[ -n "$STUB2_PID" ]] && kill "$STUB2_PID" 2>/dev/null; mariadb_sandbox_stop; rm -rf "$T"; }
trap cleanup EXIT INT TERM
PORT=$((28000 + RANDOM % 1000)); PORT2=$((PORT + 1))
php -S "127.0.0.1:$PORT" "$ROOT/tools/lib/sevdesk-stub.php" >"$T/stub.log" 2>&1 &
STUB_PID=$!
SEVDESK_STUB_MANY=1 php -S "127.0.0.1:$PORT2" "$ROOT/tools/lib/sevdesk-stub.php" >"$T/stub2.log" 2>&1 &
STUB2_PID=$!
for i in $(seq 1 50); do curl -s -o /dev/null -H "Authorization: TOKEN-OK" "http://127.0.0.1:$PORT/Contact?limit=1" && break; sleep 0.1; done
if curl -s -H "Authorization: TOKEN-OK" "http://127.0.0.1:$PORT/Contact?limit=1&countAll=true" | grep -q '"total":"3"'; then ok "Stub-Server antwortet"; else bad "Stub-Server antwortet nicht: $(tail -3 "$T/stub.log")"; fi
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'sevdesk' => ['base_url' => 'http://127.0.0.1:$PORT', 'auth_prefix' => '', 'x_version' => ''], 'queue' => ['sevdesk_per_second' => 50, 'sevdesk_global_per_second' => 200]," || exit 1
    OUT="$(php "$ROOT/tools/lib/sevdesk-sim.php" "$ROOT" "http://127.0.0.1:$PORT" "http://127.0.0.1:$PORT2" 2>&1)"
    if ! grep -q "^connect_ohne_alles=" <<<"$OUT"; then bad "Simulation lief nicht: $(tail -n 8 <<<"$OUT")"; else
    erw "ohne Schalter und Termin: keine Freigabe" connect_ohne_alles 0
    erw "vor dem Freigabetermin: keine Freigabe" connect_vor_termin 0
    erw "Text vor dem Termin" text_vor_termin "automatische Freigabe am 30.09.2026"
    erw "am Freigabetermin (00:00 Europe/Berlin): freigegeben" connect_am_termin 1
    erw "Text am Termin" text_am_termin "automatisch freigegeben seit 30.09.2026"
    erw "ausdrueckliches connect=0 hat Vorrang vor dem Termin" connect_explizit_0_trotz_termin 0
    erw "ausdrueckliches connect=1 gilt vor dem Termin" connect_explizit_1_vor_termin 1
    erw "Einzuege bleiben gesperrt (sevdesk_collections)" collections_bleibt_zu 1
    erw "Not-Aus: Lexware-Firma darf einziehen" gate_lexfirma_frei 1
    erw "Not-Aus: sevdesk-Firma ohne Freigabe gesperrt" gate_sevfirma_gesperrt 1
    erw "Not-Aus: api_verified allein gibt den Einzug NICHT frei" gate_sevfirma_trotz_api_verified_gesperrt 1
    erw "Not-Aus: mit sevdesk_collections=1 frei" gate_sevfirma_nach_freigabe_frei 1
    erw "Not-Aus: Ruecknahme auf 0 sperrt wieder" gate_sevfirma_wieder_gesperrt 1
    erw "sevdesk als Wechselziel verfuegbar bei Freigabe" sevdesk_verfuegbar_fuer_wechsel 1
    erw "Pilot: Modus aktiv vor dem Termin" pilot_modus 1
    erw "Pilot: Schalter technisch offen" pilot_schalter_offen 1
    erw "Pilot: Firma mit Administrator-Mitglied erlaubt" pilot_adminfirma_erlaubt 1
    erw "Pilot: Kundenfirma ohne Administrator gesperrt" pilot_kundenfirma_gesperrt 1
    erw "Pilot: Registrierung mit sevdesk gesperrt" pilot_registrierung_gesperrt 1
    erw "Pilot: Wechsel der Kundenfirma gesperrt" pilot_wechsel_kundenfirma gesperrt
    erwp "Pilot: Hinweistext beim Wechsel" pilot_wechsel_text "*Pilotphase*"
    erw "Pilot: Firma aus sevdesk_pilot_orgs erlaubt (Leerzeichen toleriert)" pilot_liste_erlaubt 1
    erw "Pilot: Zustandstext" pilot_text "Pilot: nur Firmen von Administratoren, für alle ab 30.09.2026 (Schalter sevdesk_connect = pilot)"
    erw "Pilot endet am Freigabetermin: alle Firmen" pilot_nach_termin_alle 1
    erw "Pilot: Text nach dem Termin" pilot_text_nach_termin "Pilot beendet, für alle freigegeben seit 30.09.2026"
    erw "Pilot: Verlust des Adminrechts sperrt die Firma" pilot_ohne_adminrecht_gesperrt 1
    erw "Faehigkeiten ohne Verifikation: lesen, ohne Restbetrag" faehigkeiten_ohne_verifikation "read_customers,read_open_invoices,detect_changes"
    erw "Verbindungstest: erreichbar, kein Firmenname, 3 Kontakte" profil_erreichbar 1
    erw "Liste offen: nur Typ RE (5001, 5002, 5007)" liste_offen_ids "5001,5002,5007"
    erw "5001 offen (Faelligkeit in 10 Tagen)" liste_5001_status open
    erw "5002 ueberfaellig (invoiceDate + timeToPay)" liste_5002_ueberfaellig overdue
    erwp "Aenderungszeitpunkt uebernommen (Zeitzone laut Serverkonfiguration, Zeitzone der sevdesk-Zeitstempel ist Prueffrage)" liste_5001_updated "2026-09-01T10:00:00*"
    erw "eine Seite" liste_totalpages 1
    erw "teilbezahlte Rechnung ueber Statusseite overdue (750)" liste_teilbezahlt_ids 5004
    erw "Detail: Rechnungsnummer" detail_nummer RE-2026-001
    erw "Detail: Bruttobetrag" detail_brutto 119
    erw "Detail: Waehrung" detail_waehrung EUR
    erwp "Detail: Faelligkeit aus payDate" detail_faellig "20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]"
    erw "Detail: Kontaktreferenz und Name" detail_kontakt "100|Musterfirma GmbH"
    erw "Detail: Positionen" detail_positionen "1|Beratung September"
    erw "Detail 5002: Faelligkeit aus invoiceDate + timeToPay" detail_5002_faellig_aus_timeToPay 1
    erw "Detail 5002: ueberfaellig" detail_5002_status overdue
    erw "bezahlte Rechnung: paid" detail_bezahlt_status paid
    erw "Entwurf: draft" detail_entwurf_status draft
    erw "Kontakt Firma: Name, Kundennummer, E-Mail" kontakt_firma "Musterfirma GmbH|20001|rechnung@musterfirma.test"
    erw "Kontakt Person: Vor- und Nachname, E-Mail" kontakt_person "Erika Beispiel|erika.beispiel@example.test"
    erw "Kontakt ohne Kundennummer: Laufkunde" kontakt_ohne_nummer keine
    erw "Restbetrag ohne Verifikation: null (kein Einzug)" restbetrag_ohne_verifikation null
    erw "Faehigkeiten mit Verifikation: read_open_amount" faehigkeiten_mit_verifikation "read_customers,read_open_invoices,detect_changes,read_open_amount"
    erw "Restbetrag offen = sumGross" restbetrag_offen 119
    erw "Restbetrag teilbezahlt = sumGross minus paidAmount" restbetrag_teilbezahlt 200
    erw "Restbetrag bezahlt: null" restbetrag_bezahlt null
    erw "Restbetrag ohne paidAmount-Feld: null trotz Verifikation" restbetrag_ohne_paidAmount null
    erw "Zahlungsstatus teilbezahlt" zahlstatus_teilbezahlt partially_paid
    erw "Seitennavigation: 205 Rechnungen = 3 Seiten" seiten_gesamt 3
    erw "Seite 0: 100 Eintraege" seite0_anzahl 100
    erw "Seite 2: 5 Eintraege" seite2_anzahl 5
    erw "Seite 2 beginnt bei Eintrag 201" seite2_erste_id 7201
    erw "getOpenInvoices liest alle Seiten (205 offen plus 1 teilbezahlt)" alle_offenen 206
    erwp "HTTP 401: Zugriff verweigert, ohne Token im Text" fehler_401 "fehler:sevdesk: Zugriff verweigert (HTTP 401)*"
    erwp "HTTP 429: Anfragegrenze" fehler_429 "fehler:sevdesk: Anfragegrenze erreicht*"
    erwp "HTTP 500: vorruebergehend nicht verfuegbar" fehler_500 "fehler:sevdesk: vorübergehend nicht verfügbar*"
    erwp "HTML statt JSON: unerwartete Antwort" fehler_html "fehler:sevdesk: unerwartete Antwort*"
    erwp "HTTP 404: Datensatz nicht gefunden" fehler_404 "fehler:sevdesk: Datensatz nicht gefunden*"
    erw "Monitoring zaehlt Fehler (sevdesk_api)" monitoring_fehler_gezaehlt 1
    erwp "gesperrte Freigabe: kein Aufruf" gesperrt_kein_aufruf "fehler:Die sevdesk-Anbindung ist noch nicht freigegeben*"
    erw "gesperrte Freigabe: keine Faehigkeiten" faehigkeiten_gesperrt ""
    erwp "Firma ohne Verbindung: sprechender Fehler" quelle_sev_nicht_verbunden "fehler:sevdesk ist nicht verbunden*"
    erw "verbundene sevdesk-Firma erhaelt den Adapter" quelle_sev_verbunden 1
    erw "Lexware-Firma bleibt bei Lexware" quelle_lex_bleibt_lexware 1
    erw "Verbindungstest speichert Pruefzeitpunkt" verifiziert_gespeichert 1
    erw "Jobtyp sevdesk-Firma" jobtyp_sev sync_run_sevdesk
    erw "Jobtyp Lexware-Firma" jobtyp_lex sync_run
    erw "Pool sevdesk" pool_sevdesk sync_run_sevdesk
    erw "Pool lexware ohne sevdesk-Jobs" pool_lexware_ohne_sevdesk 1
    erwp "Scheduler reiht beide Firmen ein" scheduler_eingereiht "sync_run:auto:*,sync_run:auto:*"
    erw "Scheduler: Lexware-Firma als sync_run" scheduler_typ_lex sync_run
    erw "Scheduler: sevdesk-Firma als sync_run_sevdesk" scheduler_typ_sev sync_run_sevdesk
    erw "queue_tenant_active kennt beide Typen" tenant_active_beide_typen 1
    erwp "Scheduler bei gesperrter Freigabe: nur Lexware" scheduler_sev_gesperrt "sync_run:auto:aaaaaaaa-0000-0000-0000-000000000001"
    erw "Scheduler im Pilot: Adminfirma wird eingereiht (beide Firmen)" scheduler_pilot_adminfirma 2
    erwp "Scheduler im Pilot ohne Adminrecht: nur Lexware" scheduler_pilot_ohne_admin "sync_run:auto:aaaaaaaa-0000-0000-0000-000000000001"
    erw "Wechselsperre aktiv" sperre_aktiv 1
    erwp "Reset ohne Grund verweigert" reset_ohne_grund "fehler:*Grund*"
    erw "Reset durch Betreiber" reset_ok ok
    erw "Sperre nach Reset aufgehoben" sperre_nach_reset 0
    erw "Zaehler unveraendert" zaehler_unveraendert 2
    erw "Reset-Zeitpunkt gespeichert" reset_zeitpunkt_gesetzt 1
    erwp "Reset ohne aktive Sperre verweigert" reset_ohne_sperre "fehler:*keine Wechselsperre*"
    erw "Audit invoice_source_lock_reset" audit_reset 1
    erw "Wechsel von sevdesk weg trennt und loescht den Token" wechsel_trennt_sevdesk 1
    erw "Wechsel erhoeht den Zaehler" wechsel_zaehler 3
    erw "neue Sperre nach Wechsel" sperre_nach_wechsel 1
    erwp "nach Wechsel: Lexware nicht verbunden" quelle_nach_wechsel "fehler:Lexware Office ist nicht verbunden*"
    fi
    grep -q "TOKEN-OK" "$T/stub.log" && bad "Token im Stub-Protokoll (php -S Log)" || ok "kein Token im Server-Protokoll des Stubs"
else
    echo "  uebersprungen: mariadbd/mariadb-install-db nicht vorhanden"
fi

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
