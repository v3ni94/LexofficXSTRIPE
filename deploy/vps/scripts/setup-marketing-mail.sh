#!/usr/bin/env bash
# SmartEinzug: Versandprofil des Marketingmoduls (mail_marketing, Amazon SES) in shared/config.php einrichten (4.68).
#
# Fragt Region, SMTP-Zugangsdaten und Absender ab (Passwort unsichtbar), sichert die Konfiguration, fuegt den Block
# mail_marketing vor dem schliessenden "];" ein, erzeugt einen Webhook-Token fuer marketing-webhook.php, prueft die
# Syntax im Container, erzeugt Scheduler und Worker neu (restart-workers.sh) und zeigt den Zustand des Profils
# (bin/mail-check.php --marketing). Zugangsdaten landen nur in shared/config.php, nie in der Shell-Historie.
#
#   bash /opt/smarteinzug/deploy/scripts/setup-marketing-mail.sh            einrichten
#   bash /opt/smarteinzug/deploy/scripts/setup-marketing-mail.sh --status   nur Zustand anzeigen
#   bash /opt/smarteinzug/deploy/scripts/setup-marketing-mail.sh --test=ADRESSE   Testnachricht ueber das Profil senden
#
# Voraussetzungen in AWS (docs/marketing.md): Identitaet mail.smart-einzug.de verifiziert (DKIM, MAIL FROM), SMTP-Zugangsdaten
# aus der SES-Konsole, in der Sandbox zusaetzlich die Testadresse als Identitaet verifiziert.
set -euo pipefail
BASE=/opt/smarteinzug
CFG="$BASE/shared/config.php"
DEPLOY="$BASE/deploy"
cd "$DEPLOY"
export RELEASE_SHA="$(basename "$(readlink -f "$BASE/releases/current")")"
compose() { docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env "$@"; }

MODE="${1:-setup}"
case "$MODE" in
    --status) compose exec -T php php bin/mail-check.php --marketing; exit $? ;;
    --test=*) compose exec -T php php bin/mail-check.php --marketing "--send=${MODE#--test=}"; exit $? ;;
    setup) ;;
    *) echo "Unbekannte Option $MODE (erlaubt: --status, --test=ADRESSE)"; exit 2 ;;
esac

if [[ ! -f "$CFG" ]]; then echo "Konfiguration $CFG fehlt."; exit 1; fi
if grep -q "'mail_marketing'" "$CFG"; then
    echo "Der Block mail_marketing ist bereits vorhanden (grep -n mail_marketing $CFG). Zum Aendern die Werte dort direkt"
    echo "anpassen und danach bash scripts/restart-workers.sh ausfuehren; Zustand mit --status."
    exit 1
fi

echo "Einrichtung des Marketing-Versandprofils (Amazon SES). Werte aus der SES-Konsole; Abbruch jederzeit mit Strg+C."
read -rp "SES-Region [eu-central-1]: " REGION; REGION="${REGION:-eu-central-1}"
if [[ ! "$REGION" =~ ^[a-z]{2}-[a-z]+-[0-9]$ ]]; then echo "Ungueltige Region (Beispiel eu-central-1)."; exit 1; fi
read -rp "SMTP-Benutzername (beginnt mit AKIA): " SMTPUSER
if [[ ! "$SMTPUSER" =~ ^[A-Z0-9]{16,32}$ ]]; then echo "Der SMTP-Benutzername ist der IAM-Zugangsschluessel (16 bis 32 Grossbuchstaben und Ziffern), kein Postfach."; exit 1; fi
read -rsp "SMTP-Passwort (Eingabe unsichtbar): " SMTPPASS; echo
if [[ ${#SMTPPASS} -lt 20 ]]; then echo "SMTP-Passwort zu kurz."; exit 1; fi
read -rp "Absenderadresse [kontakt@mail.smart-einzug.de]: " FROM; FROM="${FROM:-kontakt@mail.smart-einzug.de}"
if [[ ! "$FROM" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]]; then echo "Ungueltige Absenderadresse."; exit 1; fi
TOKEN="$(openssl rand -hex 24)"

BACKUP="$CFG.bak-$(date +%Y%m%d-%H%M%S)"
cp -a "$CFG" "$BACKUP"
chmod 600 "$BACKUP"
export REGION SMTPUSER SMTPPASS FROM TOKEN
python3 - "$CFG" <<'PY'
import os, sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
def q(v: str) -> str:
    return v.replace('\\', '\\\\').replace("'", "\\'")
block = (
    "\n    // Marketingmodul (4.63): Werbenachrichten ueber Amazon SES, getrennt vom Systemversand (docs/marketing.md).\n"
    "    // Eingerichtet durch deploy/vps/scripts/setup-marketing-mail.sh.\n"
    "    'mail_marketing' => [\n"
    "        'enabled'      => true,\n"
    "        'transport'    => 'smtp',\n"
    f"        'from_address' => '{q(os.environ['FROM'])}',\n"
    "        'from_name'    => 'SmartEinzug',\n"
    "        'reply_to'     => 'kontakt@smart-einzug.de',\n"
    "        'smtp' => [\n"
    f"            'host'       => 'email-smtp.{q(os.environ['REGION'])}.amazonaws.com',\n"
    "            'port'       => 587,\n"
    "            'encryption' => 'tls',\n"
    f"            'user'       => '{q(os.environ['SMTPUSER'])}',\n"
    f"            'pass'       => '{q(os.environ['SMTPPASS'])}',\n"
    "        ],\n"
    f"        'webhook_token' => '{os.environ['TOKEN']}',\n"
    "    ],\n"
)
i = s.rstrip().rfind('];')
if i < 0:
    sys.exit('Konfiguration ohne schliessendes ]; gefunden, Abbruch ohne Aenderung.')
open(p, 'w', encoding='utf-8').write(s[:i].rstrip('\n') + '\n' + block + '];\n')
print('Block mail_marketing eingefuegt.')
PY
unset SMTPPASS

echo "Syntaxpruefung im Container ..."
if ! compose run --rm --no-deps -T php php -l "$CFG" >/dev/null 2>&1; then
    echo "Syntaxfehler in $CFG, stelle Sicherung wieder her: $BACKUP"
    cp -a "$BACKUP" "$CFG"
    exit 1
fi
echo
echo "SNS-Endpunkt fuer Ruecklaeufer und Beschwerden (in AWS SNS als HTTPS-Abonnement eintragen):"
echo "  https://app.smart-einzug.de/marketing-webhook.php?token=$TOKEN"
echo
bash "$DEPLOY/scripts/restart-workers.sh"
echo
compose exec -T php php bin/mail-check.php --marketing || true
echo
echo "Naechster Schritt: Testnachricht ueber das Profil:  bash $DEPLOY/scripts/setup-marketing-mail.sh --test=IHRE-ADRESSE"
echo "Sicherung der alten Konfiguration: $BACKUP (nach erfolgreicher Pruefung loeschen)."
