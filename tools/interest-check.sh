#!/usr/bin/env bash
# Regressionstest der Vorregistrierung (app/interest.php, vormerken.php) gegen eine temporaere MariaDB: Anlage,
# getrennte Token (A Bestaetigung, B Abmeldung) nur als Hash, Wiederholungs- und Tagesgrenze, ungueltige Eingaben,
# Schalter, Bestaetigung per Button-Logik, freiwillige Angaben, Einladung, Abmeldung mit neuer Bestaetigungspflicht,
# Sperrvermerk, Wartung, Kennzahlen, Suche, formelsicherer CSV-Export, Obergrenze, Herkunftspruefung; statische Pruefungen.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true], 'mail' => ['enabled' => true, 'from_address' => 'noreply@example.test', 'from_name' => 'Test'], 'base_url' => 'https://app.example.test'," || exit 1
    OUT="$(php "$ROOT/tools/lib/interest-sim.php" "$ROOT" 2>&1)"
    grep -q "Fatal\|Warning\|Notice" <<< "$OUT" && bad "PHP-Meldung in der Simulation: $(grep -m1 "Fatal\|Warning\|Notice" <<< "$OUT")"
    echo "1) Anlage, Mail mit zwei getrennten Token"
    erw "Zustand mail_sent" neu_state mail_sent; erw "eine Zeile" zeilen 1; erw "eine Mail" mails 1
    erw "E-Mail kleingeschrieben" email_normalisiert kunde@example.test; erw "Name bereinigt" name_bereinigt "Erika Muster"; erw "Firma bereinigt" company_bereinigt "Muster GmbH"
    erw "Herkunft ohne www" herkunft smart-einzug.de; erw "Status pending" status_nach_anlage pending; erw "Einwilligung v3" consent vormerkung-v3; erw "consent_at gesetzt" consent_at 1; erw "Zweck launch_info" purpose launch_info
    erw "Token A (Bestaetigung) in der Mail" token_a_in_mail 1; erw "Token B (Abmeldung) in der Mail" token_b_in_mail 1; erw "Token A und B verschieden" tokens_verschieden 1
    erw "kein Klartext-Token gespeichert" klartext_nicht_gespeichert 1; erw "Mailtext: kein kostenpflichtiges Abonnement" mail_text_kein_abo 1; erw "Kennzahl Formularabsendung" funnel_submitted 1
    echo "2) Wiederholung, Tagesgrenze, ungueltige Eingaben, Schalter"
    erw "Wiederholung: gleiche Antwortklasse" wdh_state already; erw "keine zweite Zeile" wdh_zeilen 1; erw "keine zweite Mail" wdh_mails 1
    erw "hoechstens 3 Mails je Adresse in 24 h" tagesgrenze_mails 3; erw "Zaehler 3" tagesgrenze_zaehler 3; erw "juengster Token A gueltig" token_a_erneuert 1; erw "juengster Token B gueltig" token_b_gueltig 1
    erw "ungueltige E-Mail" err_email email; erw "ohne Einwilligung" err_consent consent; erw "Honeypot" err_honeypot honeypot
    erw "freigegebener Anbieter nicht vormerkbar" err_provider_frei provider; erw "unbekannter Anbieter" err_provider_fremd provider; erw "Ablehnungen legen nichts an" err_zeilen 1
    erw "Schalter sevdesk_waitlist=0 schliesst die Vormerkung" err_waitlist_zu provider
    echo "3) Bestaetigung, Angaben, Einladung, Abmeldung, Sperrvermerk"
    erw "falscher Token" confirm_falsch invalid; erw "Muell-Token" confirm_muell invalid; erw "Bestaetigung" confirm confirmed
    erw "Status confirmed" status_bestaetigt confirmed; erw "confirmed_at gesetzt" confirmed_at 1; erw "Token A nach Bestaetigung geloescht" token_a_geloescht 1; erw "Token A nicht wiederverwendbar" confirm_erneut invalid
    erw "frischer Token B aus interest_confirm" manage_neu_passt 1; erw "Mail nach Bestaetigung mit Abmeldelink" bestaetigt_mail 1; erw "Bestaetigung sendet genau eine Mail (4)" mails_nach_confirm 4
    erw "Kennzahl Bestaetigung" funnel_confirmed 1; erw "erneute Anmeldung nach Bestaetigung: gleiche Antwort" nach_bestaetigung_state already; erw "keine weitere Mail" nach_bestaetigung_mails 4
    erw "freiwillige Angaben gespeichert" angaben 1; erw "Betatest-Interesse" beta 1; erw "Rechnungen je Monat" ipm 21_100; erw "Stripe vorhanden" has_stripe 1; erw "API-Zugang nein" has_api 0; erw "ungueltiger Bereich wird NULL" ipm_ungueltig_null 1
    erw "Betaeinladung fuer bestaetigten Eintrag" invite 1
    erw "falscher Abmeldetoken" unsub_falsch invalid; erw "Abmeldung ueber Token B" unsub unsubscribed; erw "Status unsubscribed" status_abgemeldet unsubscribed; erw "keine Angaben nach Abmeldung" angaben_nach_abmeldung 0
    erw "erneute Eintragung nach Abmeldung: neue Mail" erneut_state mail_sent; erw "erneute Eintragung bleibt pending (Abmeldung nicht automatisch aufgehoben)" erneut_status pending; erw "keine zweite Zeile" erneut_zeilen 1
    erw "Sperrvermerk gesetzt" block 1; erw "gesperrt = abgemeldet" block_status unsubscribed; erw "Klartext ausser E-Mail entfernt" block_name_null 1; erw "E-Mail bleibt als Sperrvermerk" block_email_bleibt kunde@example.test
    erw "Eintragung trotz Sperre: gleiche Antwort" block_register_state already; erw "Eintragung trotz Sperre: keine Mail" block_keine_mail 1; erw "keine Einladung fuer gesperrte" block_invite 0; erw "Wartung loescht gesperrte nicht" cleanup_gesperrt_bleibt 1
    echo "4) Wartung, Kennzahlen, Suche, CSV, Grenze, Herkunft"
    erw "Wartung loescht 3 alte Zeilen (pending, abgemeldet, benachrichtigt)" cleanup 3; erw "junger pending-Eintrag bleibt" jung_bleibt 1
    erw "Kennzahl Absendungen zaehlt jede gueltige Absendung (9, auch Wiederholungen)" metrik_submitted 9; erw "Kennzahl bestaetigt" metrik_confirmed 1; erw "Kennzahl Betatest-Interesse" metrik_beta 1; erw "Kennzahl verbundene Firmen 0" metrik_connected 0
    erw "Suche E-Mail" suche_q 1; erw "Filter Status" suche_status 1; erw "Filter Herkunft" suche_source 1; erw "Filter gesperrt" suche_blocked 1
    erw "CSV mit BOM" csv_bom 1; erw "CSV: Formel entschaerft" csv_formel_entschaerft 1; erw "CSV: Anfuehrungszeichen verdoppelt" csv_quote 1
    erw "Obergrenze je Minute" limit_error busy
    echo "5) Nachsenden bei nicht aktivem Mailversand"
    erw "ohne Mailversand: Zustand mail_deferred (Eintrag gespeichert)" deferred_state mail_deferred; erw "Eintrag pending, nicht bestaetigt" deferred_status pending; erw "als wartend markiert" deferred_flag 1; erw "keine Mail erzeugt" deferred_mails 0
    erw "erneutes Absenden waehrend des Wartens bleibt ehrlich (mail_deferred)" deferred_again mail_deferred
    erw "Wartung ohne Mailversand sendet nichts" resend_ohne 0; erw "Wartung mit Mailversand sendet die wartende Mail" resend_mit 1; erw "Wartemarke geloescht" resend_flag 0; erw "nachgesendete Mail nennt Datum und Herkunft" resend_nennt_datum 1; erw "zweiter Wartungslauf sendet nichts doppelt" resend_zweimal_null 0; erw "Mail mit Token A und B" resend_mail_tokens 1; erw "Token aus der nachgesendeten Mail bestaetigt" resend_confirm confirmed
    erw "Herkunft: erlaubte Domain mit www" origin_ok 1; erw "Herkunft: fremder Origin abgelehnt" origin_fremd 0; erw "Herkunft: Referer allein reicht" origin_referer 1; erw "Herkunft: ohne Header zugelassen" origin_leer 1; erw "Herkunft: Origin null zugelassen" origin_null 1
else
    echo "  (Datenbankteil uebersprungen: mariadbd nicht verfuegbar)"
fi
echo "5) Statisch"
P="$ROOT/websites/smart-einzug.de/integrationen/sevdesk/index.html"
! grep -q noindex "$P" && ok "Seite indexierbar" || bad "Seite noindex"
[[ "$(grep -c 'action="https://app.smart-einzug.de/vormerken.php"' "$P")" == "2" ]] && ok "zwei Formulare auf vormerken.php" || bad "Formularzahl"
grep -q 'name="website"' "$P" && grep -q 'name="consent"' "$P" && grep -q 'name="name"' "$P" && ok "Honeypot, Einwilligung, Name im Formular" || bad "Formularfelder"
grep -q "Müller Holding AG per E-Mail über den Entwicklungsstand" "$P" && ok "Einwilligungstext nach Masterplan" || bad "Einwilligungstext"
grep -q "Buchhaltung Pro" "$P" && grep -q "Firmenlastschriftverfahren (B2B)" "$P" && ok "Voraussetzungen (sevdesk-Tarif nach Hilfe, kein B2B)" || bad "Voraussetzungen fehlen"
! grep -qi "Partner\b\|zertifiziert" "$P" && ok "keine Partnerschafts-/Zertifizierungsbehauptung" || bad "Partnerschaftsbehauptung"
grep -q 'id="sevdesk"' "$ROOT/websites/smart-einzug.de/index.html" && ok "Startseiten-Teaser vorhanden" || bad "Teaser fehlt"
grep -q 'id="vormerkung"' "$ROOT/websites/smart-einzug.de/datenschutz/index.html" && ok "Datenschutz 3a" || bad "Datenschutz"
grep -q "form-action 'self' https://app.smart-einzug.de" "$ROOT/websites/smart-einzug.de/.htaccess" && ok "CSP erlaubt das Formularziel" || bad "CSP"
! grep -qiE "REMOTE_ADDR|client_ip\(" "$ROOT/php-ionos/vormerken.php" "$ROOT/php-ionos/app/interest.php" && ok "keine IP-Verarbeitung" || bad "IP-Zugriff"
grep -q "interest_cleanup" "$ROOT/php-ionos/app/jobs.php" && grep -q "interest_cleanup" "$ROOT/php-ionos/cron.php" && ok "Wartung in beiden Betriebspfaden" || bad "Wartung"
! sed -n '/^function interest_register/,/^function interest_pending_mail_count/p' "$ROOT/php-ionos/app/interest.php" | grep -q "mail_enabled()\|'confirmed'" && grep -q "mail_deferred" "$ROOT/php-ionos/app/interest.php" && ok "keine stille Bestaetigung ohne Mailversand; nicht sendbare Mails werden als wartend markiert" || bad "interest_register bestaetigt still oder kennt kein mail_deferred"
grep -q "interest_send_pending" "$ROOT/php-ionos/app/jobs.php" && grep -q "interest_send_pending" "$ROOT/php-ionos/cron.php" && grep -q "auth_send_pending_welcome_mails" "$ROOT/php-ionos/app/jobs.php" && grep -q "auth_send_pending_welcome_mails" "$ROOT/php-ionos/cron.php" && ok "Nachsenden in Wartung und Cron verdrahtet" || bad "Nachsenden nicht verdrahtet"
grep -q "welcome_mail_pending = 1" "$ROOT/php-ionos/app/auth.php" && ok "Willkommensmail wird bei Fehlschlag als wartend markiert" || bad "welcome_mail_pending fehlt"
grep -q "mail_pending" "$ROOT/php-ionos/sql/migrations/021_mail_pending.sql" && grep -q "welcome_mail_pending" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 021 und schema.sql" || bad "Migration 021"
grep -q "SET mail_pending = 1" "$ROOT/php-ionos/sql/migrations/022_mail_pending_backfill.sql" && ok "Migration 022 traegt die Wartemarke fuer Altbestand nach" || bad "Migration 022 fehlt"
grep -q "_pruned" "$ROOT/php-ionos/app/queue.php" && grep -q "bereinigt" "$ROOT/php-ionos/app/queue.php" && ok "queue_retry_now verweigert bereinigte Mailjobs" || bad "retry bereinigt"
grep -q "Leerer oder bereinigter Nachrichteninhalt" "$ROOT/php-ionos/app/jobs.php" && ok "job_mail sendet keine leeren Mails" || bad "job_mail leer"
grep -q "mail_send_queued" "$ROOT/php-ionos/app/interest.php" && grep -q "mail_send_queued" "$ROOT/php-ionos/app/auth.php" && ok "Nachsenden ueber die Mail-Warteschlange" || bad "Nachsenden direkt"
[[ -x "$ROOT/deploy/vps/scripts/restart-workers.sh" ]] && ok "restart-workers.sh vorhanden" || bad "restart-workers.sh fehlt"
grep -q "'aktion' => 'bestaetigen'" "$ROOT/php-ionos/vormerken.php" && ok "Bestaetigung erst per Button (POST)" || bad "GET bestaetigt direkt"
grep -q "vormerkung-v3" "$ROOT/docs/einwilligungen.md" && grep -q "INTEREST_CONSENT_VERSION = 'vormerkung-v3'" "$ROOT/php-ionos/app/interest.php" && ok "Einwilligungsfassung v3 archiviert" || bad "Einwilligungsfassung"
grep -q "interest_block_id\|interest_invite_id" "$ROOT/php-ionos/admin.php" && grep -q "export=vormerkungen" "$ROOT/php-ionos/admin.php" && ok "Adminaktionen und CSV-Export" || bad "Adminaktionen"
grep -q "manage_token_hash" "$ROOT/php-ionos/sql/migrations/020_interest_registrations.sql" && grep -q "manage_token_hash" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 020 und schema.sql mit Token B" || bad "Migration"
grep -q "integration_switch('sevdesk', 'connect')" "$ROOT/php-ionos/register.php" && ok "register.php: sevdesk vor Freigabe zur Vorregistrierung" || bad "register.php"
! grep -q "token=" "$ROOT/php-ionos/app/sevdesk.php" | grep -v Authorization && grep -q "Authorization: " "$ROOT/php-ionos/app/sevdesk.php" && ok "sevdesk-Client: Authorization-Header, kein URL-Token" || bad "sevdesk-Client"
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; [[ $FAIL -eq 0 ]]
