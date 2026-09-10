#!/usr/bin/env bash
# Plattform-Benutzer und Rechte (app/platform.php, Migration 027): statische Pruefungen plus Prueffaelle gegen eine
# temporaere MariaDB (tools/lib/platform-sim.php). Aufruf: bash tools/platform-roles-check.sh   (Exit 0 = gruen)
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }

echo "1) Statische Pruefungen"
for f in app/platform.php app/auth.php app/layout.php admin-users.php admin.php admin-support.php admin-system.php admin-legal.php admin-doc.php admin-system-data.php app/docs.php app/monitor.php app/support.php twofa-setup.php support-end.php verify-email.php; do
    php -l "$ROOT/php-ionos/$f" >/dev/null 2>&1 && ok "php -l $f" || bad "php -l $f"
done
grep -q "CREATE TABLE IF NOT EXISTS platform_roles" "$ROOT/php-ionos/sql/migrations/027_platform_roles.sql" && grep -q "platform_role " "$ROOT/php-ionos/sql/schema.sql" && grep -q "CREATE TABLE IF NOT EXISTS platform_roles" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 027 und schema.sql" || bad "Migration 027"
for f in admin.php admin-support.php admin-system.php admin-legal.php admin-doc.php admin-users.php; do
    grep -q "require_platform(" "$ROOT/php-ionos/$f" && ok "$f nutzt require_platform" || bad "$f ohne require_platform"
done
! grep -rn "require_superadmin()" "$ROOT/php-ionos" --include=*.php | grep -v "function require_superadmin" | grep -q . && ok "keine Seite ruft mehr require_superadmin() direkt" || bad "require_superadmin() noch in Verwendung"
! grep -rn "is_superadmin" "$ROOT/php-ionos"/admin*.php | grep -v "platform_role\|is_superadmin = 1\|is_superadmin DESC\|is_superadmin === 1" | grep -q . && ok "Adminseiten pruefen Rechte nicht mehr direkt ueber is_superadmin" || bad "direkte is_superadmin-Pruefung in Adminseiten"
grep -q "platform_can(\$ctx, 'monitoring.edit')" "$ROOT/php-ionos/app/monitor.php" && ok "monitor_can_edit verlangt monitoring.edit" || bad "monitor_can_edit"
grep -q "platform_can(\$row, 'support.sessions')" "$ROOT/php-ionos/app/support.php" && grep -q "platform_can(\$row, 'support.sessions')" "$ROOT/php-ionos/app/auth.php" && ok "Support-Modus verlangt support.sessions (Einloesen und laufende Sitzung)" || bad "Support-Modus ohne Rechtepruefung"
grep -q "platform_can(\$ctx, 'docs.technical')" "$ROOT/php-ionos/app/docs.php" && grep -q "platform_can(\$ctx, 'docs.admin')" "$ROOT/php-ionos/app/docs.php" && ok "Dokumentationsrechte ueber Berechtigungen" || bad "docs.php"
grep -q "'users.manage'" "$ROOT/php-ionos/admin-users.php" && ok "Benutzerverwaltung nur mit users.manage" || bad "admin-users.php Recht"
grep -q "mail_enabled()" "$ROOT/php-ionos/app/platform.php" && grep -q "nie im Adminbereich angezeigt\|nie im Frontend" "$ROOT/php-ionos/app/platform.php" && ok "Einladung nur per Mail, kein Passwortlink im Frontend" || bad "Einladung ohne Mailpflicht"
grep -q "platform_only" "$ROOT/php-ionos/app/auth.php" && grep -q "is_admin_script(\$script)" "$ROOT/php-ionos/app/auth.php" && ok "Plattformkontext: Kundenseiten leiten in den Adminbereich" || bad "Plattformkontext"
grep -q "admin_subnav_items(\$ctx)" "$ROOT/php-ionos/admin-legal.php" && grep -q "admin_subnav_items(\$ctx)" "$ROOT/php-ionos/admin-users.php" && ok "Reiterleiste nach Rechten gefiltert" || bad "Reiterleiste"
! grep -q "—" "$ROOT/php-ionos/app/platform.php" "$ROOT/php-ionos/admin-users.php" && ok "keine Gedankenstriche" || bad "Gedankenstrich"

echo "2) Prueffaelle gegen temporaere MariaDB"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "" || exit 1
    OUT="$(php "$ROOT/tools/lib/platform-sim.php" "$ROOT" 2>&1)"
    if ! grep -q "^systemrollen=" <<<"$OUT"; then bad "Simulation lief nicht: $(tail -n 8 <<<"$OUT")"; else
    erw "Systemrollen aus schema.sql vorhanden" systemrollen "admin,staff,support"
    erw "Administrator hat alle Rechte" admin_alle_rechte 1
    erw "Superadmin darf Benutzer verwalten" super_users_manage 1
    erw "Rolle admin darf Benutzer verwalten" rolle_admin_users_manage 1
    erw "Support darf auf Firmen wechseln" support_sessions 1
    erw "Support darf keine Benutzer verwalten" support_kein_users_manage 1
    erw "Support darf keinen Plattform-Not-Stopp" support_kein_notstopp 1
    erw "Mitarbeiter sieht Systemuebersicht" staff_monitoring_view 1
    erw "Mitarbeiter aendert nichts im System" staff_kein_monitoring_edit 1
    erw "ohne 2FA kein Zugang trotz Rolle admin" ohne_2fa_kein_zugang 1
    erw "ohne Rolle kein Zugang" ohne_rolle_kein_zugang 1
    erw "unbekannte Rolle kein Zugang" unbekannte_rolle_kein_zugang 1
    erw "Mitarbeiter: unbekanntes Recht verweigert" staff_unbekanntes_recht 1
    erw "Entwicklerdoku: Superadmin" docs_technical_super 1
    erw "Entwicklerdoku: Support nein" docs_technical_support_nein 1
    erw "Unternehmensdoku: Mitarbeiter nein" docs_admin_staff_nein 1
    erw "Kundenhandbuch: Mitarbeiter ja" docs_customer_staff 1
    erw "eigene Rolle: Rechte gefiltert, admin.view ergaenzt" rolle_buchhaltung_rechte "admin.view,companies.plan,companies.view,plans.manage"
    erw "Rollencode mit fuehrender Ziffer verweigert" rolle_code_ungueltig verweigert
    erw "Rollencode wird kleingeschrieben gespeichert" rolle_code_kleingeschrieben 1
    erw "doppelter Rollencode verweigert" rolle_doppelt verweigert
    erw "Rolle ohne Rechte verweigert" rolle_ohne_rechte verweigert
    erw "Systemrolle admin unveraenderlich" systemrolle_admin_unveraenderlich verweigert
    erw "Systemrolle support: docs.technical verweigert" systemrolle_support_docs_verweigert verweigert
    erw "Systemrolle staff: users.manage verweigert" systemrolle_staff_users_manage_verweigert verweigert
    erw "eigene Rolle darf Dokumentationsrechte erhalten" eigene_rolle_docs_erlaubt ok
    erw "Systemrolle support editierbar" systemrolle_support_editierbar ok
    erw "geaenderte Rolle wirkt sofort" support_nach_aenderung_keine_sessions 1
    erw "Support darf keine Rollen anlegen" support_darf_keine_rollen_anlegen verweigert
    erw "Systemrolle nicht loeschbar" systemrolle_nicht_loeschbar verweigert
    erw "Rolle zugewiesen" rolle_zugewiesen buchhaltung
    erw "Rolle in Verwendung nicht loeschbar" rolle_in_verwendung_nicht_loeschbar verweigert
    erw "eigene Rolle nicht aenderbar" eigene_rolle_nicht_aenderbar verweigert
    erw "Mitarbeiter darf nicht verwalten" staff_darf_nicht_verwalten verweigert
    erw "Superadmin herabgestuft: Spalte 0" super_herabgestuft_spalte 0
    erw "Superadmin herabgestuft: Rolle staff" super_herabgestuft_rolle staff
    erw "Superadmin herabgestuft: Sitzungen beendet" super_herabgestuft_epoche 1
    erw "Zugang entzogen" zugang_entzogen 1
    erw "Rolle nach Entzug loeschbar" rolle_nach_entzug_loeschbar ok
    erw "genau ein aktiver Administrator" admin_anzahl 1
    erw "letzter Administrator nicht entfernbar" letzter_admin_nicht_entfernbar verweigert
    erw "letzter Administrator nicht deaktivierbar" letzter_admin_nicht_deaktivierbar verweigert
    erw "Mitarbeiter deaktiviert" staff_deaktiviert 1
    erw "eigenes Konto nicht deaktivierbar" eigenes_konto_nicht_deaktivierbar verweigert
    erw "Einladung des eigenen Kontos mit hoeherer Rolle verweigert" einladung_selbst_verweigert verweigert
    erw "C-01: users.manage laedt kein Zweitkonto als admin ein" c01_zweitkonto_admin_verweigert verweigert
    erw "C-01: kein Konto angelegt" c01_zweitkonto_nicht_angelegt 0
    erw "C-01: eigene Rolle nicht bearbeitbar" c01_eigene_rolle_bearbeiten_verweigert verweigert
    erw "C-01: eigene Rolle unveraendert" c01_eigene_rolle_unveraendert 1
    erw "C-01: neue Rolle mit users.manage nur durch Administrator" c01_neue_rolle_mit_users_manage_verweigert verweigert
    erw "C-01: unprivilegierte Rolle weiterhin anlegbar" c01_neue_rolle_ohne_privileg_erlaubt ok
    erw "C-01: unprivilegierte Rolle vergebbar" c01_verwalter_vergibt_lesen ok
    erw "C-01: admin nur durch Administrator vergebbar" c01_verwalter_vergibt_admin_verweigert verweigert
    erw "C-01: Administrator vergibt admin" c01_admin_vergibt_admin ok
    erw "eigene Rolle nach Einladungsversuch unveraendert" einladung_selbst_rolle_unveraendert technik
    erw "Einladung ohne Mailversand verweigert" einladung_ohne_mail_verweigert verweigert
    erw "Einladung mit ungueltiger Adresse verweigert" einladung_ungueltige_adresse verweigert
    erw "verweigerte Einladung legt kein Konto an" einladung_kein_konto_angelegt 0
    erw "Plattformkontext: Rolle platform" plattformkontext_rolle platform
    erw "Plattformkontext ohne Firma" plattformkontext_ohne_firma 1
    erw "Plattformkontext traegt Rechte" plattformkontext_zugang 1
    erw "Kunde ohne Rolle: kein Plattformkontext" kunde_ohne_rolle_kein_plattformkontext 1
    erw "Plattform-Benutzer ohne Firma erkannt" plattform_user_ohne_org 1
    erw "Audit: alle Aenderungen protokolliert" audit_aktionen "platform_role_changed,platform_role_created,platform_role_deleted,platform_user_access_removed,platform_user_deactivated,platform_user_role_changed"
    fi
else
    echo "  uebersprungen: mariadbd/mariadb-install-db nicht vorhanden"
fi

echo
echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"
[[ "$FAIL" -eq 0 ]]
