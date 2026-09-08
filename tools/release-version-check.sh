#!/usr/bin/env bash
# Prueft den Downgrade-Schutz des Deployments (deploy/vps/scripts/lib/release-version.sh und seine Einbindung
# in deploy.sh): Versionsvergleich, Abweisung kleinerer Versionen, Erlaubnis gleicher und groesserer Versionen,
# Verhalten ohne lesbare Version, Schalter SMARTEINZUG_ALLOW_DOWNGRADE. Ohne Docker, ohne Netz, ohne Datenbank.
#   bash tools/release-version-check.sh
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/deploy/vps/scripts/lib/release-version.sh"
DEPLOY_SH="$ROOT/deploy/vps/scripts/deploy.sh"
pass=0; fail=0
ok()  { pass=$((pass+1)); echo "  OK    $1"; }
bad() { fail=$((fail+1)); echo "  FAIL  $1"; }
# shellcheck source=../deploy/vps/scripts/lib/release-version.sh
source "$LIB"

T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkrel() { # $1 = Ordner, $2 = Version ('' = keine version.php)
    mkdir -p "$T/$1/app"
    [[ -n "$2" ]] && printf "<?php\ndeclare(strict_types=1);\n\nconst APP_VERSION = '%s';\n" "$2" > "$T/$1/app/version.php"
    return 0
}
mkrel alt 4.38; mkrel neu 4.44; mkrel gleich 4.44; mkrel zehn 4.10; mkrel neun 4.9; mkrel fuenf 5.0; mkrel ohne ''

echo "1) Version lesen"
[[ "$(release_app_version "$T/neu")" == "4.44" ]] && ok "APP_VERSION 4.44 gelesen" || bad "Lesen: $(release_app_version "$T/neu")"
[[ -z "$(release_app_version "$T/ohne")" ]] && ok "fehlende version.php liefert leer" || bad "fehlende Datei nicht leer"
[[ -z "$(release_app_version "$T/gibt-es-nicht")" ]] && ok "fehlender Ordner liefert leer" || bad "fehlender Ordner nicht leer"

echo "2) Vergleich numerisch je Stelle (kein Zeichenkettenvergleich)"
[[ "$(release_version_cmp 4.38 4.44)" == "-1" ]] && ok "4.38 < 4.44" || bad "4.38 < 4.44"
[[ "$(release_version_cmp 4.44 4.38)" == "1" ]] && ok "4.44 > 4.38" || bad "4.44 > 4.38"
[[ "$(release_version_cmp 4.44 4.44)" == "0" ]] && ok "4.44 = 4.44" || bad "4.44 = 4.44"
[[ "$(release_version_cmp 4.10 4.9)" == "1" ]] && ok "4.10 > 4.9 (numerisch)" || bad "4.10 > 4.9"
[[ "$(release_version_cmp 5.0 4.99)" == "1" ]] && ok "5.0 > 4.99" || bad "5.0 > 4.99"
[[ "$(release_version_cmp 4.4 4.4.1)" == "-1" ]] && ok "4.4 < 4.4.1 (fehlende Stelle = 0)" || bad "4.4 < 4.4.1"

echo "3) Entscheidung"
release_downgrade_check "$T/alt" "$T/neu" >/dev/null; [[ $? -eq 1 ]] && ok "4.38 auf aktives 4.44: Downgrade (1)" || bad "Downgrade nicht erkannt"
release_downgrade_check "$T/neu" "$T/alt" >/dev/null; [[ $? -eq 0 ]] && ok "4.44 auf aktives 4.38: erlaubt (0)" || bad "Upgrade abgewiesen"
release_downgrade_check "$T/gleich" "$T/neu" >/dev/null; [[ $? -eq 0 ]] && ok "gleiche Version: erlaubt (erneutes Ausrollen)" || bad "gleiche Version abgewiesen"
release_downgrade_check "$T/neun" "$T/zehn" >/dev/null; [[ $? -eq 1 ]] && ok "4.9 auf aktives 4.10: Downgrade" || bad "4.9/4.10 falsch"
release_downgrade_check "$T/neu" "$T/ohne" >/dev/null; [[ $? -eq 2 ]] && ok "aktives Release ohne Version: nicht pruefbar (2), kein Abbruch" || bad "ohne Version falsch"
release_downgrade_check "$T/ohne" "$T/neu" >/dev/null; [[ $? -eq 2 ]] && ok "neues Release ohne Version: nicht pruefbar (2)" || bad "neu ohne Version falsch"
release_downgrade_check "$T/neu" "" >/dev/null; [[ $? -eq 2 ]] && ok "kein aktives Release (Erstinstallation): nicht pruefbar (2)" || bad "Erstinstallation falsch"
msg="$(release_downgrade_check "$T/alt" "$T/neu")"
[[ "$msg" == *"Version 4.38"* && "$msg" == *"Version 4.44"* ]] && ok "Meldung nennt beide Versionen" || bad "Meldung: $msg"

echo "4) Einbindung in deploy.sh"
grep -q 'source "$RELEASE_DIR/deploy/vps/scripts/lib/release-version.sh"' "$DEPLOY_SH" && ok "deploy.sh laedt die Bibliothek aus dem Release" || bad "Einbindung fehlt"
grep -q 'release_downgrade_check "$RELEASE_DIR" "${AKTIV_DIR:-}"' "$DEPLOY_SH" && ok "deploy.sh prueft neues gegen aktives Release" || bad "Aufruf fehlt"
grep -q 'SMARTEINZUG_ALLOW_DOWNGRADE:-0}" == "1"' "$DEPLOY_SH" && ok "Ausnahme nur mit SMARTEINZUG_ALLOW_DOWNGRADE=1" || bad "Ausnahmeschalter fehlt"
awk '/release_downgrade_check "\$RELEASE_DIR"/{f=1} f&&/exit 1/{print "abbruch"; exit}' "$DEPLOY_SH" | grep -q abbruch && ok "Downgrade beendet deploy.sh mit exit 1" || bad "kein Abbruch bei Downgrade"
# Reihenfolge: Pruefung VOR dem Uebernehmen von deploy/vps und vor jedem docker-Aufruf
pos_check="$(grep -n 'release_downgrade_check "\$RELEASE_DIR"' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
pos_rsync="$(grep -n 'deploy_step "release-uebernehmen"' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
pos_docker="$(grep -n '"${COMPOSE\[@\]}"' "$DEPLOY_SH" | head -1 | cut -d: -f1)"
(( pos_check < pos_rsync && pos_check < pos_docker )) && ok "Pruefung liegt vor Uebernahme des Deploy-Ordners und vor dem ersten Compose-Aufruf" || bad "Reihenfolge: check=$pos_check rsync=$pos_rsync docker=$pos_docker"
grep -q "rollback.sh" "$DEPLOY_SH" && grep -A6 'Kein Deployment: Das waere ein Downgrade' "$DEPLOY_SH" | grep -q "rollback.sh" && ok "Fehlermeldung verweist auf rollback.sh und Run workflow" || bad "Hinweis in der Fehlermeldung fehlt"

echo
echo "Ergebnis: $pass bestanden, $fail fehlgeschlagen"
exit $(( fail == 0 ? 0 : 1 ))
