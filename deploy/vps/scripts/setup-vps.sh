#!/usr/bin/env bash
#
# SmartEinzug: Einmalige Grundeinrichtung eines frischen IONOS-VPS (Ubuntu 24.04, kein Plesk).
# Idempotent: mehrfaches Ausfuehren wiederholt nur fehlende Schritte, aendert nichts bereits
# Eingerichtetes ungewollt.
#
#   sudo bash setup-vps.sh /pfad/zum/ssh-ed25519-public-key.pub
#
# Der uebergebene oeffentliche Schluessel wird dem neuen Benutzer "deploy" hinterlegt. ERST NACHDEM
# der Zugang mit diesem Schluessel getestet wurde, sshd auf reine Schluesselanmeldung umstellen
# (dieses Skript fragt dafuer ausdruecklich nach, siehe unten - keine automatische Abschaltung von
# PasswordAuthentication/PermitRootLogin ohne Bestaetigung, sonst droht Aussperrung).
#
# Muss als root (oder mit sudo) laufen.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Bitte als root ausfuehren (sudo bash setup-vps.sh ...)." >&2
    exit 1
fi

PUBKEY_FILE="${1:-}"
if [[ -z "$PUBKEY_FILE" || ! -f "$PUBKEY_FILE" ]]; then
    echo "Nutzung: sudo bash setup-vps.sh /pfad/zum/ssh-schluessel.pub" >&2
    exit 1
fi
if ! grep -qE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-)' "$PUBKEY_FILE"; then
    echo "::error:: Datei sieht nicht wie ein oeffentlicher SSH-Schluessel aus: $PUBKEY_FILE" >&2
    exit 1
fi

echo "== 1/9: System aktualisieren =="
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y

echo "== 2/9: Grundwerkzeuge =="
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg lsb-release ufw fail2ban unattended-upgrades \
    apt-listchanges rsync jq

echo "== 3/9: Benutzer deploy anlegen (idempotent) =="
if ! id -u deploy >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash deploy
    echo "Benutzer 'deploy' angelegt."
else
    echo "Benutzer 'deploy' existiert bereits, wird nicht veraendert."
fi
usermod -aG sudo deploy || true

install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
AUTH_KEYS="/home/deploy/.ssh/authorized_keys"
touch "$AUTH_KEYS"
if ! grep -qF "$(cat "$PUBKEY_FILE")" "$AUTH_KEYS" 2>/dev/null; then
    cat "$PUBKEY_FILE" >> "$AUTH_KEYS"
    echo "Oeffentlicher Schluessel zu $AUTH_KEYS hinzugefuegt."
else
    echo "Schluessel bereits in $AUTH_KEYS vorhanden."
fi
chown deploy:deploy "$AUTH_KEYS"
chmod 600 "$AUTH_KEYS"

echo "== 4/9: Docker aus dem offiziellen Repository (inkl. compose-plugin) =="
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    if [[ ! -f /etc/apt/keyrings/docker.gpg ]]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
    fi
    ARCH="$(dpkg --print-architecture)"
    CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
    echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
    echo "Docker bereits installiert, ueberspringe Installation."
fi
usermod -aG docker deploy || true
systemctl enable --now docker

echo "== 5/9: Verzeichnisstruktur unter /opt/smarteinzug =="
install -d -m 750 -o deploy -g deploy \
    /opt/smarteinzug \
    /opt/smarteinzug/releases \
    /opt/smarteinzug/shared \
    /opt/smarteinzug/shared/storage \
    /opt/smarteinzug/shared/sessions \
    /opt/smarteinzug/deploy \
    /opt/smarteinzug/logs \
    /opt/smarteinzug/backups
# shared/config.php muss von Hand angelegt werden (enthaelt Geheimnisse, siehe ANLEITUNG-IONOS.md /
# docs zur VPS-Einrichtung); hier nur die Datei mit sicheren Rechten vorbereiten, falls noch nicht da.
if [[ ! -f /opt/smarteinzug/shared/config.php ]]; then
    install -m 640 -o deploy -g deploy /dev/null /opt/smarteinzug/shared/config.php
    echo "Leere /opt/smarteinzug/shared/config.php angelegt. Vor dem ersten Start mit echtem Inhalt fuellen."
fi

echo "== 6/9: ufw (Firewall) =="
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
echo "ufw aktiv: nur 22, 80, 443 eingehend erlaubt."
cat <<'EOF'
HINWEIS Docker und ufw:
Docker traegt eigene iptables-Regeln in die Kette DOCKER-USER ein und umgeht damit ufw fuer
veroeffentlichte Container-Ports. Da hier NUR Caddy Ports veroeffentlicht (80/443, siehe
docker-compose.yml) und MariaDB/Redis bewusst ohne "ports:"-Eintrag laufen, ist das Risiko gering.
Trotzdem vor dem produktiven Start pruefen:
  - "docker ps" zeigt nur 0.0.0.0:80->80 und 0.0.0.0:443->443 fuer den caddy-Dienst, keine weiteren
    veroeffentlichten Ports.
  - Empfehlung fuer zusaetzliche Absicherung: Paket "ufw-docker" (https://github.com/chaifeng/ufw-docker)
    installieren, das die DOCKER-USER-Kette an ufw-Regeln bindet, oder manuell eine Regel in
    /etc/ufw/after.rules ergaenzen, die die DOCKER-USER-Kette an ufw's Standardablehnung koppelt.
    Dieses Skript nimmt diese zusaetzliche Haertung NICHT automatisch vor (siehe README.md, offene
    Punkte) und muss vor der Inbetriebnahme mit Produktivdaten bewusst entschieden werden.
EOF

echo "== 7/9: fail2ban (sshd-Jail) =="
install -d -m 755 /etc/fail2ban/jail.d
cat > /etc/fail2ban/jail.d/sshd.local <<'EOF'
[sshd]
enabled = true
port = ssh
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

echo "== 8/9: unattended-upgrades (Sicherheitsupdates automatisch) =="
dpkg-reconfigure -f noninteractive unattended-upgrades || true
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
systemctl enable --now unattended-upgrades

echo "== 9/9: sshd haerten (nur nach ausdruecklicher Bestaetigung) =="
cat <<'EOF'

VOR DEM NAECHSTEN SCHRITT: In einem ZWEITEN Terminal jetzt pruefen, dass die Anmeldung funktioniert:
    ssh -i /pfad/zum/privaten/schluessel deploy@<VPS-IP>
Diese Sitzung offen lassen, bis der zweite Zugriff bestaetigt ist. Erst danach sshd auf reine
Schluesselanmeldung umstellen (PasswordAuthentication no, PermitRootLogin no) - sonst droht bei
einem fehlerhaften Schluessel die vollstaendige Aussperrung vom Server.
EOF
read -r -p "Zugang mit dem Schluessel als Benutzer 'deploy' erfolgreich getestet? Jetzt sshd haerten? [ja/nein] " CONFIRM
if [[ "${CONFIRM,,}" == "ja" ]]; then
    SSHD_DROPIN=/etc/ssh/sshd_config.d/99-smarteinzug.conf
    cat > "$SSHD_DROPIN" <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
EOF
    if sshd -t; then
        systemctl reload ssh || systemctl reload sshd
        echo "sshd gehaertet: nur noch Schluesselanmeldung, kein Root-Login mehr."
    else
        echo "::error:: sshd-Konfiguration ungueltig, Haertung NICHT uebernommen (Datei bleibt liegen: $SSHD_DROPIN)." >&2
        rm -f "$SSHD_DROPIN"
        exit 1
    fi
else
    echo "sshd wurde NICHT gehaertet. Skript spaeter erneut ausfuehren, wenn der Schluesselzugang bestaetigt ist."
fi

echo
echo "Grundeinrichtung abgeschlossen. Naechste Schritte laut README.md:"
echo "  1. Erstes Release nach /opt/smarteinzug/releases/<name>/ kopieren (Inhalt von php-ionos/ plus deploy/vps/)."
echo "  2. /opt/smarteinzug/shared/config.php mit echtem Inhalt fuellen (Vorlage: php-ionos/app/config.example.php)."
echo "  3. cp .env.example .env unter /opt/smarteinzug/deploy und Werte eintragen."
echo "  4. Erstes Deployment: bash /opt/smarteinzug/releases/<name>/deploy/vps/scripts/deploy.sh <name>"
echo "     (setzt bei der Erstinstallation den Symlink releases/current selbst); spaeter uebernimmt der GitHub-Workflow."
