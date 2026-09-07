#!/usr/bin/env bash
# Rechtsdokumente mit Zustimmungsnachweis (app/legal.php, Migration 023) und Audit-Aufbewahrung:
# statische Pruefungen plus Prueffaelle gegen eine temporaere MariaDB (tools/lib/legal-sim.php).
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  OK    $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }
feld() { printf '%s' "$1" | sed -n "s/^$2=//p" | tail -n1; }
erw() { [[ "$(feld "$OUT" "$2")" == "$3" ]] && ok "$1" || bad "$1 ($2=$(feld "$OUT" "$2"), erwartet $3)"; }

echo "1) Statische Pruefungen"
for f in app/legal.php app/legal_drafts.php rechtliches.php admin-legal.php register.php dashboard.php team.php export.php app/audit.php; do
    php -l "$ROOT/php-ionos/$f" >/dev/null 2>&1 && ok "php -l $f" || bad "php -l $f"
done
grep -q "CREATE TABLE IF NOT EXISTS legal_documents" "$ROOT/php-ionos/sql/migrations/023_legal_documents.sql" && grep -q "legal_acceptances" "$ROOT/php-ionos/sql/schema.sql" && ok "Migration 023 und schema.sql" || bad "Migration 023"
grep -q "professional_secrecy" "$ROOT/php-ionos/sql/schema.sql" && ok "organizations.professional_secrecy" || bad "professional_secrecy fehlt"
grep -q "legal_accept(\$ctx" "$ROOT/php-ionos/rechtliches.php" && grep -q "can_manage_settings" "$ROOT/php-ionos/rechtliches.php" && ok "Zustimmung nur ueber legal_accept, Rechtepruefung" || bad "rechtliches.php"
grep -q "accept_avv" "$ROOT/php-ionos/register.php" && grep -q "legal_active_documents()\['avv'\]" "$ROOT/php-ionos/register.php" && ok "Registrierung: AVV-Checkbox nur bei veroeffentlichter Fassung" || bad "register.php"
grep -q "'registration'" "$ROOT/php-ionos/register.php" && ok "Registrierung schreibt Nachweis (Weg registration)" || bad "Nachweis Registrierung"
grep -q "require_platform('legal.view')" "$ROOT/php-ionos/admin-legal.php" && grep -q "legal.manage" "$ROOT/php-ionos/admin-legal.php" && grep -q "require_recent_totp" "$ROOT/php-ionos/admin-legal.php" && ok "Adminseite: Plattformrecht legal.view/legal.manage und 2FA" || bad "admin-legal.php Schutz"
sed -n "/action === 'publish' || \$action === 'retire'/,/elseif/p" "$ROOT/php-ionos/admin-legal.php" | grep -q require_recent_totp && ok "Veroeffentlichen und Zurueckziehen verlangen den 2FA-Code" || bad "publish/retire ohne 2FA"
! sed -n "/action === 'import_draft'/,/elseif/p" "$ROOT/php-ionos/admin-legal.php" | grep -q require_recent_totp && ok "Vorlage uebernehmen ohne 2FA-Code (Entwurf ohne Aussenwirkung, seit 4.36)" || bad "import_draft verlangt noch 2FA"
grep -q "\[Platzhalter" "$ROOT/php-ionos/app/legal.php" && ok "Veroeffentlichung mit Platzhaltern gesperrt" || bad "Platzhalter-Sperre"
! grep -q "REMOTE_ADDR\|client_ip(" "$ROOT/php-ionos/app/legal.php" && ok "keine IP-Speicherung im Nachweis" || bad "IP im Nachweis"
grep -q "legal_pending_for_org" "$ROOT/php-ionos/dashboard.php" && ok "Dashboard-Hinweis auf offene Pflichtdokumente" || bad "Dashboard"
grep -q 'href="rechtliches.php"' "$ROOT/php-ionos/app/layout.php" && ok "Menuelink Rechtliches" || bad "Menuelink"
grep -q "audit_cleanup" "$ROOT/php-ionos/app/jobs.php" && grep -q "audit_cleanup" "$ROOT/php-ionos/cron.php" && ok "Audit-Bereinigung in beiden Betriebspfaden" || bad "audit_cleanup"
grep -q "typ=protokoll" "$ROOT/php-ionos/team.php" && grep -q "'protokoll'" "$ROOT/php-ionos/export.php" && grep -q "is_owner(\$ctx)" "$ROOT/php-ionos/export.php" && ok "Protokoll-Export nur Inhaber" || bad "Protokoll-Export"
grep -q 'audit-more' "$ROOT/php-ionos/team.php" && ok "Protokoll: 20 Zeilen, Rest aufklappbar" || bad "Protokoll-Anzeige"
! grep -qi "nie gelöscht" "$ROOT/php-ionos/team.php" "$ROOT/php-ionos/sql/schema.sql" && ok "kein veralteter Hinweis 'nie geloescht'" || bad "veralteter Hinweis"
grep -q "consent_record_registration" "$ROOT/php-ionos/register.php" && grep -q "consent_list_for_org" "$ROOT/php-ionos/rechtliches.php" && grep -q "consent_list_for_user" "$ROOT/php-ionos/security.php" && grep -q "agb-2026-09" "$ROOT/docs/einwilligungen.md" && ok "Zustimmungsnachweis: Registrierung, Rechtliches, Sicherheit, Archiv der Fassungen" || bad "Zustimmungsnachweis"
grep -q "consent_text" "$ROOT/php-ionos/vormerken.php" && ok "Vormerkung zeigt Einwilligung mit Fassung und Zeitpunkt" || bad "Vormerkung Einwilligung"
grep -q "Anlage 1" "$ROOT/php-ionos/app/legal_drafts.php" && grep -q "Lexware Office" "$ROOT/php-ionos/app/legal_drafts.php" && grep -q "203" "$ROOT/php-ionos/app/legal_drafts.php" && ok "Entwuerfe: AVV mit Datenanlage, Verschwiegenheit § 203" || bad "Entwuerfe"
! grep -q "—" "$ROOT/php-ionos/app/legal_drafts.php" "$ROOT/php-ionos/rechtliches.php" "$ROOT/php-ionos/admin-legal.php" && ok "keine Gedankenstriche" || bad "Gedankenstrich"

echo "2) Prueffaelle gegen temporaere MariaDB"
source "$ROOT/tools/lib/mariadb-sandbox.sh"
T="$(mktemp -d)"; cleanup() { mariadb_sandbox_stop; rm -rf "$T"; }; trap cleanup EXIT INT TERM
if mariadb_sandbox_available; then
    mariadb_sandbox_start "$T/mdb" "'features' => ['queue' => true]," || exit 1
    OUT="$(php "$ROOT/tools/lib/legal-sim.php" "$ROOT" 2>&1)"
    if ! grep -q "^vorlagen=" <<<"$OUT"; then bad "Simulation lief nicht: $(tail -n 5 <<<"$OUT")"; else
    erw "zwei Vorlagen uebernommen" vorlagen 2
    erw "vor Veroeffentlichung nichts aktiv" aktiv_vor_veroeffentlichung 0
    erw "Firma sieht ohne Veroeffentlichung keine Dokumente" status_a_ohne_veroeffentlichung 0
    erw "Platzhalter sperren Veroeffentlichung" platzhalter_sperre 1
    erw "Zustimmung zu unveroeffentlichter Fassung verweigert" accept_unveroeffentlicht_verweigert 1
    erw "zwei aktive Dokumente nach Veroeffentlichung" aktiv_nach_veroeffentlichung 2
    erw "aktive AVV-Fassung ist die veroeffentlichte" aktiv_avv_ist_test1 1
    erw "Firma ohne Verschwiegenheit: nur AVV offen" pending_a avv
    erw "Kanzlei: AVV und Verschwiegenheit offen" pending_b "avv,secrecy"
    erw "Zaehlung fehlender AVV-Zustimmungen" missing_avv 2
    erw "Zaehlung fehlender Verschwiegenheit nur bei Berufsgeheimnistraegern" missing_secrecy 1
    erw "Mitarbeiter darf nicht akzeptieren" member_verweigert 1
    erw "Mitarbeiter darf Verschwiegenheit nicht setzen" member_secrecy_verweigert 1
    erw "Zustimmung idempotent (ein Nachweis)" nachweise_a 1
    erw "Nachweis traegt E-Mail" nachweis_email inhaber@firma-a.test
    erw "Nachweis traegt ersten Weg" nachweis_weg registration
    erw "nach Zustimmung nichts offen" pending_a_nach_accept ""
    erw "Audit legal_accepted" audit_legal_accepted 1
    erw "Audit Verschwiegenheit" audit_secrecy 1
    erw "Mandantentrennung: Kanzlei ohne Nachweise" mandant_b_unberuehrt 0
    erw "neue Fassung zieht alte zurueck" alte_fassung_zurueckgezogen 1
    erw "neue Fassung erneut zustimmungspflichtig" neue_fassung_offen 1
    erw "alter Nachweis bleibt sichtbar" alter_nachweis_sichtbar 1
    erw "Nachweise bleiben erhalten" nachweise_a_bleiben 1
    erw "veroeffentlichte Fassung nicht loeschbar" veroeffentlicht_nicht_loeschbar 1
    erw "unveroeffentlichte Fassung loeschbar" unveroeffentlicht_geloescht 1
    erw "Renderer escaped HTML" render_escaped 1
    erw "Renderer: Ueberschrift, Listen, fett, Platzhalter" render_struktur 1
    erw "Zustimmung: AGB und Datenschutz je Registrierung" consent_zwei_gegenstaende 2
    erw "Zustimmung: idempotent je Benutzer, Gegenstand, Fassung" consent_idempotent 2
    erw "Zustimmung: Fassungen aus app/consent.php" consent_fassungen "agb:agb-2026-09,datenschutz:datenschutz-2026-09"
    erw "Zustimmung: E-Mail kleingeschrieben" consent_email_klein inhaber@firma-a.test
    erw "Zustimmung: Weg registration" consent_weg registration
    erw "Zustimmung: unbekannter Gegenstand abgelehnt" consent_unbekannt_abgelehnt 1
    erw "Audit: alter Eintrag geloescht" audit_geloescht 1
    erw "Audit: junger Eintrag bleibt" audit_rest 1
    erw "Audit-Aufbewahrung Vorgabe 90 Tage" audit_retention_default 90
    fi
else
    echo "  SKIP  mariadb nicht verfuegbar"
fi
echo; echo "Ergebnis: $PASS bestanden, $FAIL fehlgeschlagen"; [[ $FAIL -eq 0 ]]
