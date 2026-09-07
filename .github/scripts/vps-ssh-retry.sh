#!/usr/bin/env bash
#
# SmartEinzug: Einen ssh-, rsync- oder scp-Aufruf des VPS-Deployjobs mit begrenzter Wiederholung und
# wachsender Wartezeit ausfuehren (GitHub-Workflow "Deployment IONOS-Webhosting und VPS", Job deploy-vps).
#
# HINTERGRUND (echter Vorfall, Lauf #51, Version 4.14): Der erste SSH-Aufruf des Jobs (Zielverzeichnis
# anlegen) scheiterte mit "ssh: connect to host *** port ***: Connection timed out" und Exitcode 255.
# Der Server war zu diesem Zeitpunkt nachweislich in Ordnung (spaetere Laeufe liefen in 22 Sekunden
# durch), die Verbindung kam schlicht nicht zustande: wechselnde Runner-Adressen, Firewall/fail2ban,
# kurzzeitige Netzstoerung. Ein einzelner solcher Aussetzer beendete den gesamten Lauf, obwohl auf dem
# Server nichts geschehen war und ein zweiter Versuch Sekunden spaeter erfolgreich gewesen waere.
#
# Wiederholen ist hier korrekt, weil JEDER so ausgefuehrte Schritt idempotent ist:
#   - "mkdir -p" legt vorhandene Verzeichnisse nicht erneut an,
#   - "rsync --delete" mit unveraenderter Quelle erzeugt denselben Zielstand,
#   - der Ausloeseschritt ist serverseitig durch die Deploy-Sperre geschuetzt (deploy-runner.sh): Ein
#     zweiter Versuch waehrend eines laufenden Deployments antwortet mit REJECTED und startet nichts.
# Nicht wiederholt wird deshalb der eigentliche Deploy selbst; der laeuft serverseitig entkoppelt.
#
#   bash .github/scripts/vps-ssh-retry.sh "<Beschriftung>" -- <befehl> [argumente...]
#
# Eingaben (Umgebung, alle optional):
#   VPS_RETRIES               Anzahl der Versuche insgesamt, Standard 4
#   VPS_RETRY_DELAY_SECONDS   Wartezeit vor dem zweiten Versuch, danach jeweils verdoppelt, Standard 5
#                             (Standard also 5, 10, 20 Sekunden Pause; im Test auf 0 gesetzt)
#   VPS_RETRY_FINAL_CODES     Exitcodes, die eine ENDGUELTIGE Antwort sind und nicht wiederholt werden
#                             (kommagetrennt). Beispiel: deploy-runner.sh antwortet mit 3, wenn bereits
#                             ein Deployment laeuft; das ist kein Fehler der Verbindung, sondern die
#                             richtige Antwort, und ein zweiter Versuch wuerde nur Zeit kosten.
#   VPS_RETRY_OK_CODES        Exitcodes, die als Erfolg MIT Warnung gelten (kommagetrennt). Beispiel:
#                             rsync 24 ("some files vanished") ist kein Uebertragungsfehler.
#   VPS_RETRY_STATE_FILE      Datei, in die attempts, exit_code und connect_failed geschrieben werden,
#                             auch ohne GITHUB_OUTPUT (der Ausloeseschritt liest daraus seine Einordnung,
#                             statt die Mustererkennung ein zweites Mal zu formulieren).
# Ausgaben: Ausgabe des Befehls unveraendert; bei GITHUB_OUTPUT zusaetzlich attempts, exit_code und
# connect_failed (true, wenn KEIN Versuch eine Verbindung aufbauen konnte, der Server also nie erreicht
# wurde: dann ist gesichert, dass serverseitig nichts angestossen wurde).
# Exitcode: 0 bei Erfolg, sonst der Exitcode des letzten Versuchs.
set -uo pipefail

BESCHRIFTUNG="${1:?Nutzung: vps-ssh-retry.sh <Beschriftung> -- <befehl...>}"
shift
if [[ "${1:-}" == "--" ]]; then
    shift
fi
if [[ $# -eq 0 ]]; then
    echo "::error::vps-ssh-retry.sh: kein Befehl angegeben." >&2
    exit 2
fi

VERSUCHE="${VPS_RETRIES:-4}"
# Deckel: hoechstens 8 Versuche (Pause verdoppelt sich je Versuch, bei 5 s Start also hoechstens 640 s).
[[ "$VERSUCHE" =~ ^[0-9]+$ ]] || VERSUCHE=4
(( VERSUCHE < 1 )) && VERSUCHE=1
(( VERSUCHE > 8 )) && VERSUCHE=8
PAUSE="${VPS_RETRY_DELAY_SECONDS:-5}"
ENDGUELTIG="${VPS_RETRY_FINAL_CODES:-}"
ALS_ERFOLG="${VPS_RETRY_OK_CODES:-}"
ZUSTANDSDATEI="${VPS_RETRY_STATE_FILE:-}"
[[ "$VERSUCHE" =~ ^[0-9]+$ && "$VERSUCHE" -ge 1 ]] || VERSUCHE=4
[[ "$PAUSE" =~ ^[0-9]+$ ]] || PAUSE=5

# Meldungen, die belegen, dass die Verbindung NICHT zustande kam (der Befehl hat den Server also nie
# erreicht). Abgegrenzt von einem Abbruch MITTEN in einer bestehenden Verbindung ("Broken pipe",
# "client_loop"), bei dem unklar bleibt, was auf dem Server bereits geschehen ist.
VERBINDUNGSFEHLER='connect to host|Connection timed out|Operation timed out|Network is unreachable|No route to host|Connection refused|Connection closed by remote host|kex_exchange_identification|Host key verification failed|Could not resolve hostname|Temporary failure in name resolution'

AUSGABE="$(mktemp)"
trap 'rm -f "$AUSGABE"' EXIT

versuch=0
rc=1
nur_verbindungsfehler=1
wartezeit=0
while (( versuch < VERSUCHE )); do
    versuch=$((versuch + 1))
    if (( versuch > 1 )); then
        echo "::notice::$BESCHRIFTUNG: Versuch $versuch von $VERSUCHE nach $wartezeit s Pause."
    fi
    : > "$AUSGABE"
    # Ausgabe zuerst sammeln, dann ausgeben: Die Klassifizierung braucht den vollstaendigen Text, und
    # die Reihenfolge im Protokoll bleibt eindeutig (keine Prozesssubstitution, kein Wettlauf mit tee).
    # Die uebertragenen Datenmengen sind klein, eine laufende Fortschrittsanzeige ist nicht erforderlich.
    "$@" > "$AUSGABE" 2>&1
    rc=$?
    cat "$AUSGABE"
    if [[ "$rc" -eq 0 ]]; then
        if (( versuch > 1 )); then
            echo "::notice::$BESCHRIFTUNG: im Versuch $versuch erfolgreich."
        fi
        break
    fi
    # Als Erfolg zu bewertender Exitcode (z.B. rsync 24): Warnung, aber kein Fehlschlag.
    if [[ -n "$ALS_ERFOLG" ]] && printf '%s' ",$ALS_ERFOLG," | grep -q ",$rc,"; then
        echo "::warning::$BESCHRIFTUNG: Exitcode $rc gilt als Erfolg (z.B. rsync 24: Dateien verschwanden waehrend der Uebertragung)."
        rc=0
        break
    fi
    # Endgueltige Antwort (z.B. 3 = Deployment laeuft bereits): nicht wiederholen, Exitcode durchreichen.
    if [[ -n "$ENDGUELTIG" ]] && printf '%s' ",$ENDGUELTIG," | grep -q ",$rc,"; then
        echo "::notice::$BESCHRIFTUNG: Exitcode $rc ist eine endgueltige Antwort, keine Wiederholung."
        break
    fi
    if grep -qiE "$VERBINDUNGSFEHLER" "$AUSGABE"; then
        echo "::warning::$BESCHRIFTUNG: Verbindung nicht zustande gekommen (Exitcode $rc). Der Server wurde nicht erreicht, es wurde dort nichts veraendert."
    else
        nur_verbindungsfehler=0
        echo "::warning::$BESCHRIFTUNG: fehlgeschlagen (Exitcode $rc), kein reiner Verbindungsfehler."
    fi
    if (( versuch >= VERSUCHE )); then
        break
    fi
    wartezeit=$(( PAUSE * (1 << (versuch - 1)) ))
    sleep "$wartezeit"
done

ZUSTAND="attempts=$versuch
exit_code=$rc
connect_failed=$([[ "$rc" -ne 0 && "$nur_verbindungsfehler" -eq 1 ]] && echo true || echo false)"
[[ -n "${GITHUB_OUTPUT:-}" ]] && printf '%s\n' "$ZUSTAND" >> "$GITHUB_OUTPUT"
[[ -n "$ZUSTANDSDATEI" ]] && printf '%s\n' "$ZUSTAND" > "$ZUSTANDSDATEI"

if [[ "$rc" -ne 0 ]]; then
    echo "::error::$BESCHRIFTUNG: nach $versuch Versuch(en) fehlgeschlagen (Exitcode $rc)."
    if [[ "$nur_verbindungsfehler" -eq 1 ]]; then
        echo "::error::Kein Versuch konnte eine SSH-Verbindung aufbauen. Auf dem Server wurde nichts veraendert."
        echo "::error::Zu pruefen: Erreichbarkeit des VPS, Firewall (ufw), fail2ban (gesperrte Runner-Adresse),"
        echo "::error::SSH-Port und Hostkey. Anleitung: docs/vps/06-betrieb.md, Abschnitt \"SSH-Fehler des Deployments\"."
    fi
fi
exit "$rc"
