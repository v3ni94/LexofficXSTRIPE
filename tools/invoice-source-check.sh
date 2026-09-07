#!/usr/bin/env bash
# Wechsel des Buchhaltungssystems je Firma (app/invoice_source_switch.php, Migration 024): statische Pruefungen und
# Prueffaelle gegen eine temporaere MariaDB (tools/lib/invoice-source-sim.php).
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }

echo "1) Statische Pruefungen"
for f in app/invoice_source_switch.php settings.php dashboard.php app/auth.php; do
    php -l "$ROOT/php-ionos/$f" >/dev/null 2>&1 && ok "php -l $f" || bad "php -l $f"
done
grep -q "invoice_source_changed_at" "$ROOT/php-ionos/sql/migrations/024_invoice_source_switch.sql" && grep -q "invoice_source_switches" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 024 und schema.sql" || bad "Migration 024"
grep -q "INVOICE_SOURCE_LOCK_DAYS = 28" "$ROOT/php-ionos/app/invoice_source_switch.php" && ok "Sperre vier Wochen (28 Tage)" || bad "Sperrdauer"
grep -q "switch_invoice_source" "$ROOT/php-ionos/settings.php" && grep -q "require_recent_totp(\$ctx, (string)(\$_POST\['code'\] ?? ''));" "$ROOT/php-ionos/settings.php" && ok "Einstellungen: Wechsel nur mit 2FA-Code" || bad "settings.php 2FA"
grep -q "invoice_source_apply_signup" "$ROOT/php-ionos/app/auth.php" && ok "Registrierung uebernimmt Vorauswahl" || bad "Registrierung"
grep -q "invoice_source_current" "$ROOT/php-ionos/dashboard.php" && ok "Dashboard zeigt das System der Firma" || bad "Dashboard"
grep -q "'invoice_source_switched'" "$ROOT/php-ionos/app/audit.php" && ok "Audit-Bezeichnung" || bad "Audit-Label"
grep -q "isrc\['code'\] === 'lexware_office'" "$ROOT/php-ionos/settings.php" && ok "Lexware-Abschnitt nur bei Lexware Office" || bad "Abschnittsschaltung"
grep -q "Vier-Wochen-Sperre\|vier Wochen" "$ROOT/docs/integrations.md" && ok "Doku integrations.md" || bad "Doku"
! grep -q "—" "$ROOT/php-ionos/app/invoice_source_switch.php" && ok "keine Gedankenstriche" || bad "Gedankenstrich"

echo "2) Prueffaelle gegen temporaere MariaDB"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true]," || exit 1
    OUT="$(php "$ROOT/tools/lib/invoice-source-sim.php" "$ROOT" 2>&1)"
    if ! grep -q "^start_code=" <<<"$OUT"; then bad "Simulation lief nicht: $(tail -n 5 <<<"$OUT")"; else
    erw "Standard Lexware Office" start_code lexware_office
    erw "Anzeigename" start_label "Lexware Office"
    erw "sevdesk ohne Freigabe blockiert" blocker_ohne_freigabe 1
    erw "gleiches System blockiert" blocker_gleiches_system 1
    erw "unbekanntes System blockiert" blocker_unbekannt 1
    erw "Vorauswahl ohne Freigabe wirkungslos" signup_ohne_freigabe_ignoriert 1
    erw "Freigabe sevdesk_connect erkannt" freigabe_aktiv 1
    erw "mit Freigabe kein Hindernis" blocker_mit_freigabe_frei 1
    erw "Mitarbeiter darf nicht wechseln" member_verweigert 1
    erw "offener Einzug blockiert" blocker_offener_einzug 1
    erw "Wechsel trotz Einzug verweigert" switch_trotz_einzug_verweigert 1
    erw "abgeschlossener Einzug kein Hindernis" abgeschlossener_einzug_kein_hindernis 1
    erw "Wechsel zu sevdesk" nach_wechsel_code sevdesk
    erw "Zaehler 1" nach_wechsel_zaehler 1
    erw "Zeitpunkt gesetzt" nach_wechsel_zeit 1
    erw "alte Verbindung getrennt, Schluessel geloescht" alte_verbindung_getrennt 1
    erw "Historie bleibt" historie_bleibt 1
    erw "Audit-Eintrag" audit_wechsel 1
    erw "Audit von/nach" audit_from_to "lexware_office>sevdesk"
    erw "Sperre aktiv" sperre_aktiv 1
    erw "Sperre 28 Tage" sperre_tage 28
    erw "Rueckwechsel gesperrt" rueckwechsel_gesperrt 1
    erw "Tag 27 noch gesperrt" tag27_gesperrt 1
    erw "Tag 29 frei" tag29_frei 1
    erw "Rueckwechsel zu Lexware" rueckwechsel_code lexware_office
    erw "Zaehler 2" rueckwechsel_zaehler 2
    erw "Lexware muss neu verbunden werden" rueckwechsel_verbindung_offen 1
    erw "Vorauswahl mit Freigabe wirkt" signup_mit_freigabe sevdesk
    erw "unbekannte Vorauswahl ignoriert" signup_unbekannt_ignoriert sevdesk
    fi
else
    echo "  SKIP  mariadb nicht verfuegbar"
fi
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; [[ $FAIL -eq 0 ]]
