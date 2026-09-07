#!/usr/bin/env bash
# Regressionstest der Vormerkung (app/interest.php, vormerken.php) gegen eine temporaere MariaDB:
# Anlage, Normalisierung, Double-Opt-in-Token nur als Hash, Wiederholungsschutz, ungueltige Eingaben,
# Bestaetigung, Abmeldung, Ablauf, Wartung, Obergrenze je Minute, Statisches: Endpunkt ohne IP-Speicherung,
# Seite indexierbar mit Formular und Datenschutzanker.
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
    echo "1) Anlage und Bestaetigungsmail"
    erw "Anlage erfolgreich" neu_ok 1; erw "Zustand mail_sent" neu_state mail_sent; erw "genau eine Zeile" zeilen 1
    erw "E-Mail kleingeschrieben" email_normalisiert kunde@example.test; erw "Firmenname bereinigt" company_bereinigt "Muster GmbH"
    erw "Herkunft ohne www" herkunft smart-einzug.de; erw "Status pending" status_nach_anlage pending; erw "Einwilligungsfassung" consent vormerkung-v2
    erw "Mail als Job eingereiht" mail_job 1; erw "Mail an die Adresse" mail_an kunde@example.test; erw "Token im Mailtext" token_im_text 1
    erw "Token NICHT im Klartext gespeichert" token_nicht_gespeichert 1; erw "SHA-256 des Tokens gespeichert" token_hash_passt 1
    erw "Abmeldelink in der Bestaetigungsmail" abmeldelink_in_mail 1; erw "consent_at gesetzt" consent_at 1; erw "last_mail_at beim Versuch gesetzt" last_mail_at 1; erw "mail_count=1" mail_count 1
    erw "Tagesgrenze: hoechstens 3 Mails je Adresse in 24 h (1 + 2 weitere)" tagesgrenze_mails 3; erw "Zaehler steht auf 3" tagesgrenze_zaehler 3; erw "jeder Versand erneuert den Token" token_erneuert 1
    echo "2) Wiederholung, ungueltige Eingaben"
    erw "Wiederholung innerhalb 10 Minuten: gleiche Antwortklasse" wdh_state already; erw "keine zweite Zeile" wdh_zeilen 1; erw "keine zweite Mail" wdh_mails 1
    erw "ungueltige E-Mail abgelehnt" err_email email; erw "ohne Einwilligung abgelehnt" err_consent consent; erw "Honeypot abgelehnt" err_honeypot honeypot
    erw "freigegebener Anbieter (lexware_office) nicht vormerkbar" err_provider_frei provider; erw "unbekannter Anbieter abgelehnt" err_provider_fremd provider; erw "Ablehnungen legen nichts an" err_zeilen 1
    echo "3) Bestaetigen, Abmelden, Ablauf, Wartung, Grenze"
    erw "falscher Token" confirm_falsch invalid; erw "Muell-Token" confirm_muell invalid; erw "Bestaetigung" confirm confirmed; erw "zweite Bestaetigung idempotent" confirm_erneut already
    erw "Status confirmed" status_bestaetigt confirmed; erw "confirmed_at gesetzt" confirmed_at 1; erw "Token ohne Ablauf fuer Abmeldung" ablauf_entfernt 1
    erw "erneute Anmeldung nach Bestaetigung: gleiche Antwort" nach_bestaetigung_state already; erw "keine weitere Mail" nach_bestaetigung_mails 3
    erw "Abmeldung" unsub unsubscribed; erw "Status unsubscribed" status_abgemeldet unsubscribed
    erw "abgemeldete Zeile nicht wieder bestaetigbar" confirm_nach_abmeldung invalid
    erw "Wartung: abgemeldet nicht vor 30 Tagen" cleanup_abgemeldet_frueh 0; erw "Wartung: abgemeldet nach 30 Tagen geloescht" cleanup_abgemeldet_spaet 1
    erw "abgelaufener Link ungueltig" confirm_abgelaufen invalid; erw "Wartung: unbestaetigt nicht vor 30 Tagen" cleanup_pending_frueh 0; erw "Wartung: unbestaetigt nach 30 Tagen geloescht" cleanup_pending_spaet 1
    erw "Wartung: bestaetigt 30 Tage nach Startnachricht geloescht" cleanup_benachrichtigt 1
    erw "Herkunft: erlaubte Domain mit www" origin_ok 1; erw "Herkunft: fremder Origin abgelehnt (Referer zaehlt nicht mehr)" origin_fremd 0; erw "Herkunft: Referer allein reicht" origin_referer 1; erw "Herkunft: ohne Header zugelassen" origin_leer 1; erw "Herkunft: Origin null zugelassen" origin_null 1
    erw "Obergrenze je Minute" limit_error busy; erw "Statistik zaehlt pending" stats_pending 30; erw "Anbietername aus integration_providers" stats_name sevdesk
else
    echo "  (Datenbankteil uebersprungen: mariadbd nicht verfuegbar)"
fi
echo "4) Statisch"
P="$ROOT/websites/smart-einzug.de/integrationen/sevdesk/index.html"
! grep -q noindex "$P" && ok "Seite indexierbar" || bad "Seite noindex"
grep -q 'action="https://app.smart-einzug.de/vormerken.php"' "$P" && ok "Formular zeigt auf vormerken.php" || bad "Formularziel fehlt"
grep -q 'name="website"' "$P" && grep -q 'name="consent"' "$P" && ok "Honeypot und Einwilligung im Formular" || bad "Formularfelder fehlen"
grep -q 'id="vormerkung"' "$ROOT/websites/smart-einzug.de/datenschutz/index.html" && ok "Datenschutzabschnitt #vormerkung vorhanden" || bad "Datenschutzabschnitt fehlt"
grep -q "form-action 'self' https://app.smart-einzug.de" "$ROOT/websites/smart-einzug.de/.htaccess" && ok "CSP erlaubt das Formularziel" || bad "CSP form-action"
! grep -qiE "REMOTE_ADDR|client_ip\(" "$ROOT/php-ionos/vormerken.php" "$ROOT/php-ionos/app/interest.php" && ok "keine IP-Verarbeitung im Endpunkt" || bad "IP-Zugriff gefunden"
grep -q "interest_cleanup" "$ROOT/php-ionos/app/jobs.php" && grep -q "interest_cleanup" "$ROOT/php-ionos/cron.php" && ok "Wartung in job_maintenance UND cron.php verdrahtet" || bad "interest_cleanup fehlt in einem Betriebspfad"
! grep -q "mail_enabled()" "$ROOT/php-ionos/app/interest.php" && ok "keine stille Bestaetigung ohne Mailversand" || bad "mail_enabled-Sonderpfad vorhanden"
grep -q "aktion=abmelden" "$ROOT/php-ionos/app/interest.php" && ok "Abmeldelink wird uebergeben" || bad "Abmeldelink fehlt"
grep -q "'aktion' => 'bestaetigen'" "$ROOT/php-ionos/vormerken.php" && ok "Bestaetigung erst per Button (POST)" || bad "GET bestaetigt direkt"
grep -q "vormerkung-v2" "$ROOT/docs/einwilligungen.md" && grep -q "INTEREST_CONSENT_VERSION = 'vormerkung-v2'" "$ROOT/php-ionos/app/interest.php" && ok "Einwilligungsfassung v2 archiviert" || bad "Einwilligungsfassung nicht archiviert"
grep -q "interest_delete_id\|interest_unsubscribe_id" "$ROOT/php-ionos/admin.php" && ok "Adminaktionen Abmelden/Loeschen vorhanden" || bad "Adminaktionen fehlen"
grep -q "ix_interest_created" "$ROOT/php-ionos/sql/migrations/020_interest_registrations.sql" && ok "Index auf created_at" || bad "Index fehlt"
grep -q "interest_registrations" "$ROOT/php-ionos/sql/schema.sql" && ok "schema.sql gespiegelt" || bad "schema.sql ohne Tabelle"
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; [[ $FAIL -eq 0 ]]
