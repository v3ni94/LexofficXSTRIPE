#!/usr/bin/env bash
#
# SmartEinzug: Wartungsmodus fuer den Cutover ein-/ausschalten (siehe app/bootstrap.php,
# maintenance_active(): prueft app/storage/maintenance.flag ODER config maintenance_mode).
#
#   bash /opt/smarteinzug/deploy/scripts/maintenance.sh on
#   bash /opt/smarteinzug/deploy/scripts/maintenance.sh off
#
# Wirkt sofort auf alle Container, da app/storage als gemeinsamer Bind-Mount
# (/opt/smarteinzug/shared/storage) eingebunden ist; kein Neustart der Container noetig.
#
# Wirkung: Webseiten antworten mit 503 (ausser health.php, migrate.php, Anmeldung und Adminbereich),
# Scheduler reiht keine neuen Jobs ein, Worker reservieren keine Jobs mehr (laufender Job wird beendet),
# Webhooks (Stripe) und cron.php erhalten ebenfalls 503. Stripe wiederholt Ereignisse; nach dem Cutover
# fehlgeschlagene Ereignisse im Stripe-Dashboard erneut senden (docs/vps/07-cutover-checkliste.md).
# Das Fenster deshalb kurz halten; der Adminbereich (System) zeigt die Dauer des Wartungsmodus an.
set -euo pipefail

MODE="${1:?Nutzung: maintenance.sh on|off}"
FLAG_FILE=/opt/smarteinzug/shared/storage/maintenance.flag
LOG_FILE=/opt/smarteinzug/logs/maintenance.log

case "$MODE" in
    on)
        # Zeitpunkt in die Markerdatei schreiben (Adminbereich zeigt daraus die Dauer an).
        date -u +%FT%TZ > "$FLAG_FILE"
        echo "$(date -u +%FT%TZ) on  ($(whoami))" >> "$LOG_FILE" 2>/dev/null || true
        echo "Wartungsmodus AN ($FLAG_FILE angelegt). health.php, migrate.php, Anmeldung und Adminbereich bleiben erreichbar;"
        echo "Scheduler und Worker pausieren, Webhooks erhalten 503 (Stripe wiederholt). Fenster kurz halten."
        ;;
    off)
        rm -f "$FLAG_FILE"
        echo "$(date -u +%FT%TZ) off ($(whoami))" >> "$LOG_FILE" 2>/dev/null || true
        echo "Wartungsmodus AUS ($FLAG_FILE entfernt). Scheduler und Worker nehmen die Arbeit innerhalb weniger Sekunden wieder auf."
        ;;
    *)
        echo "Nutzung: maintenance.sh on|off" >&2
        exit 1
        ;;
esac
