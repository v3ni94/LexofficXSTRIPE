#!/usr/bin/env bash
#
# HINWEIS (Hostinger-VPS mit Coolify): Dieses Skript ist NICHT Teil des produktiven Stacks. Die
# Datenbanksicherung uebernimmt Coolify (taeglich, externes Ziel Hetzner Object Storage, Restore getestet).
# Der Backup-Container ist aus docker-compose.yml entfernt, damit kein zweiter Dump-Weg parallel laeuft.
# Das Skript bleibt als Ausweichloesung fuer einen Server ohne Coolify-Backups erhalten.
#
# SmartEinzug: taeglicher Datenbank-Dump (laeuft per Cron INNERHALB des backup-Containers).
#
# Ablauf: mysqldump --single-transaction -> gzip -> sha256 -> optional age-Verschluesselung ->
# lokale Aufbewahrung (BACKUP_RETENTION_DAYS, Standard 14 Tage) -> optionaler externer Upload
# per rclone (oder curl, falls rclone fehlt) -> Pruefung (Datei vorhanden, Groesse plausibel,
# gunzip -t, Pruefsumme) -> Rueckmeldung an die Anwendung.
#
# Rueckmeldung an die Anwendung OHNE Docker-Socket: dieser Container schreibt das Ergebnis als kleine
# JSON-Datei in den gemeinsamen Speicher (RESULT_FILE, Standard /opt/smarteinzug/shared/storage/backup-status.json).
# Der Monitoring-Sammler der Anwendung liest die Datei (Komponente "backup") und meldet veraltete oder
# fehlgeschlagene Sicherungen. Damit braucht der Backup-Container keinerlei Rechte auf dem Docker-Host.
set -euo pipefail

BACKUP_DIR=/backups
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
TS="$(date -u +%Y%m%d-%H%M%S)"
DUMP_BASENAME="smarteinzug-${TS}.sql"
DUMP_PATH="$BACKUP_DIR/${DUMP_BASENAME}.gz"

install -d -m 750 "$BACKUP_DIR"

RESULT_FILE="${RESULT_FILE:-/opt/smarteinzug/shared/storage/backup-status.json}"

report() {
    # $1 = ok|fail, $2 = Groesse in Bytes (0 wenn nicht bekannt), $3 = sha256 ("-" wenn keiner), $4 = Kurztext
    local status="$1" bytes="$2" sha="$3" text="${4:-}"
    local tmp="${RESULT_FILE}.tmp"
    install -d -m 750 "$(dirname "$RESULT_FILE")" 2>/dev/null || true
    printf '{"status":"%s","bytes":%s,"sha256":"%s","finished_at":"%s","file":"%s","remote":%s,"text":"%s"}\n' \
        "$status" "${bytes:-0}" "$sha" "$(date -u +%FT%TZ)" "$(basename "${FINAL_PATH:-$DUMP_PATH}")" \
        "$([[ -n "${BACKUP_REMOTE:-}" ]] && echo true || echo false)" "${text//\"/}" > "$tmp" \
        && mv -f "$tmp" "$RESULT_FILE" \
        || echo "::error:: Ergebnisdatei $RESULT_FILE konnte nicht geschrieben werden (status=$status)." >&2
}

fail() {
    echo "::error:: $1" >&2
    report fail 0 "-" "$1" || true
    exit 1
}

: "${DB_HOST:?DB_HOST fehlt}"
: "${DB_NAME:?DB_NAME fehlt}"
: "${DB_USER:?DB_USER fehlt}"
: "${DB_PASSWORD:?DB_PASSWORD fehlt}"

echo "[$(date -u +%FT%TZ)] Starte Datenbank-Dump von ${DB_NAME}@${DB_HOST} ..."

# Zugangsdaten nicht auf der Kommandozeile (Prozessliste), sondern ueber eine temporaere Optionsdatei
CREDS_FILE="$(mktemp)"
chmod 600 "$CREDS_FILE"
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASSWORD" > "$CREDS_FILE"
trap 'rm -f "$CREDS_FILE"' EXIT

if ! mariadb-dump \
        --defaults-extra-file="$CREDS_FILE" \
        --single-transaction \
        --routines \
        --triggers \
        --quick \
        "$DB_NAME" | gzip -9 > "$DUMP_PATH"; then
    rm -f "$DUMP_PATH"
    fail "mysqldump oder gzip fehlgeschlagen."
fi

# --- Pruefung 1: Datei vorhanden und Groesse plausibel (mehr als 1 KB) -------------------------
[[ -s "$DUMP_PATH" ]] || fail "Dump-Datei fehlt oder ist leer: $DUMP_PATH"
SIZE_BYTES="$(stat -c %s "$DUMP_PATH")"
if (( SIZE_BYTES < 1024 )); then
    fail "Dump verdaechtig klein (${SIZE_BYTES} Byte): $DUMP_PATH"
fi

# --- Pruefung 2: gzip-Integritaet ---------------------------------------------------------------
gunzip -t "$DUMP_PATH" || fail "gunzip -t meldet ein beschaedigtes Archiv: $DUMP_PATH"

# --- Optional: age-Verschluesselung, bevor die Datei den Server in Richtung Remote verlaesst ----
FINAL_PATH="$DUMP_PATH"
if [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]]; then
    if command -v age >/dev/null 2>&1; then
        age -r "$BACKUP_AGE_RECIPIENT" -o "${DUMP_PATH}.age" "$DUMP_PATH" \
            || fail "age-Verschluesselung fehlgeschlagen."
        rm -f "$DUMP_PATH"
        FINAL_PATH="${DUMP_PATH}.age"
    else
        fail "BACKUP_AGE_RECIPIENT gesetzt, aber 'age' ist nicht installiert."
    fi
fi

# --- Pruefsumme ueber die tatsaechlich aufbewahrte/hochgeladene Datei ----------------------------
SHA256="$(sha256sum "$FINAL_PATH" | awk '{print $1}')"
echo "$SHA256  $(basename "$FINAL_PATH")" > "${FINAL_PATH}.sha256"
FINAL_SIZE="$(stat -c %s "$FINAL_PATH")"

echo "Dump erstellt: $FINAL_PATH (${FINAL_SIZE} Byte, sha256 ${SHA256})"

# --- Optionaler externer Upload -------------------------------------------------------------------
if [[ -n "${BACKUP_REMOTE:-}" ]]; then
    if command -v rclone >/dev/null 2>&1 && rclone listremotes >/dev/null 2>&1; then
        echo "Lade Dump per rclone nach ${BACKUP_REMOTE} hoch ..."
        rclone copy "$FINAL_PATH" "$BACKUP_REMOTE" || fail "rclone-Upload fehlgeschlagen."
        rclone copy "${FINAL_PATH}.sha256" "$BACKUP_REMOTE" || fail "rclone-Upload der Pruefsummendatei fehlgeschlagen."
    elif command -v curl >/dev/null 2>&1; then
        echo "rclone nicht verfuegbar, versuche Upload per curl nach ${BACKUP_REMOTE} ..."
        curl --fail --silent --show-error --upload-file "$FINAL_PATH" "${BACKUP_REMOTE%/}/$(basename "$FINAL_PATH")" \
            || fail "curl-Upload fehlgeschlagen."
    else
        fail "BACKUP_REMOTE gesetzt, aber weder rclone noch curl verfuegbar."
    fi
else
    echo "BACKUP_REMOTE nicht gesetzt, nur lokale Aufbewahrung unter $BACKUP_DIR."
fi

# --- Lokale Aufbewahrung: alte Sicherungen jenseits von BACKUP_RETENTION_DAYS entfernen -----------
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'smarteinzug-*.sql.gz*' -mtime +"$RETENTION_DAYS" -print -delete

report ok "$FINAL_SIZE" "$SHA256" "Sicherung erstellt und geprueft"

echo "[$(date -u +%FT%TZ)] Backup abgeschlossen."
