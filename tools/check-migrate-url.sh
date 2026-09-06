#!/usr/bin/env bash
#
# Prueft die Adresse des Migrationsendpunkts des IONOS-Webhostings, bevor der Deployment-Workflow sie
# verwendet. Hintergrund: Die Adresse war fest auf https://app.smart-einzug.de/migrate.php verdrahtet.
# Waehrend des Umzugs zeigt dieser Name auf den Hostinger-VPS, der Aufruf traf damit den falschen Server
# (dort gab es nur das Standardzertifikat des Proxys, curl brach die Pruefung ab). Ohne diese Pruefung
# waere bei gueltigem Zertifikat die Migration gegen die falsche Datenbank gelaufen.
#
#   tools/check-migrate-url.sh "<url>"
#
# Regeln: https, Pfad endet auf /migrate.php, keine Zugangsdaten und keine Parameter in der Adresse,
# und der Hostname darf keiner der Namen sein, die auf den VPS zeigen. Fuer das Webhosting eine
# technisch eindeutige Adresse verwenden, die vom Umzug nicht betroffen ist (IONOS-Technikdomain oder
# eine Domain, die dauerhaft auf dem Webhosting bleibt).
#
# Exit 0 = in Ordnung, 1 = nicht verwendbar. Die Adresse selbst wird ausgegeben, sie enthaelt kein Geheimnis.
set -euo pipefail

URL="${1:-}"
# Namen, die im Zuge des Umzugs auf den VPS zeigen (ueberschreibbar fuer Tests).
VPS_HOSTS="${VPS_HOSTS:-app.smart-einzug.de admin.smart-einzug.de api.smart-einzug.de status.smart-einzug.de staging.smart-einzug.de}"

fail() { echo "::error::$1"; exit 1; }

[[ -n "$URL" ]] || fail "Adresse des Migrationsendpunkts fehlt. GitHub-Variable WEBHOSTING_MIGRATE_URL setzen (Beispiel: https://<technikdomain-des-webhostings>/migrate.php)."

case "$URL" in
    https://*) ;;
    http://*)  fail "Migrationsendpunkt muss HTTPS verwenden (gefunden: HTTP). Der Token darf nicht unverschluesselt uebertragen werden." ;;
    *)         fail "Migrationsendpunkt muss mit https:// beginnen (gefunden: ${URL})." ;;
esac

REST="${URL#https://}"
HOSTPORT="${REST%%/*}"
PATH_PART="/${REST#*/}"
[[ "$REST" == */* ]] || PATH_PART=""
HOST="${HOSTPORT%%:*}"

[[ "$HOSTPORT" != *"@"* ]] || fail "Migrationsendpunkt darf keine Zugangsdaten in der Adresse enthalten."
[[ "$URL" != *"?"* && "$URL" != *"#"* ]] || fail "Migrationsendpunkt darf keine Parameter enthalten; der Token wird als Header uebertragen."
[[ "$PATH_PART" == */migrate.php ]] || fail "Migrationsendpunkt muss auf /migrate.php enden (gefunden: ${PATH_PART:-/})."
[[ -n "$HOST" ]] || fail "Migrationsendpunkt enthaelt keinen Hostnamen."

for vps in $VPS_HOSTS; do
    if [[ "$HOST" == "$vps" ]]; then
        fail "Der Migrationsendpunkt zeigt auf '${HOST}'. Dieser Name gehoert zum VPS, nicht zum IONOS-Webhosting. Der Aufruf wuerde den falschen Server und die falsche Datenbank treffen. Bitte eine technisch eindeutige Adresse des Webhostings eintragen (IONOS-Technikdomain oder eine dauerhaft auf dem Webhosting verbleibende Domain). Ist das Webhosting nicht mehr im Einsatz, stattdessen die Variable WEBHOSTING_APP_DEPLOY auf false setzen."
    fi
done

if [[ "$HOST" == *.smart-einzug.de || "$HOST" == "smart-einzug.de" ]]; then
    echo "::warning::Der Migrationsendpunkt nutzt '${HOST}'. Diese Domain ist Teil des Umzugs; bitte sicherstellen, dass dieser Name dauerhaft auf das IONOS-Webhosting zeigt."
fi

echo "Migrationsendpunkt in Ordnung: ${URL}"
