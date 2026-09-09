#!/usr/bin/env bash
# Versionsvergleich zweier Releases fuer deploy.sh (Schutz gegen unbeabsichtigte Downgrades).
#
# Hintergrund (08.09.2026, 21:05 bis 21:37 UTC): Alte, einst fehlgeschlagene GitHub-Laeufe (#73 bis #79) wurden
# ueber "Re-run" erneut gestartet. Jeder Re-run deployt den Commit SEINES Laufs, nicht den aktuellen Stand des
# Branches. So wurde Produktion von 4.44 schrittweise auf 4.38 zurueckgesetzt, jeder Lauf gruen, und die
# Releasebereinigung (behalte die letzten 5) loeschte das Release 4.44 sogar vom Server.
#
# Regel: Ein Release mit KLEINERER APP_VERSION als das aktuell aktive wird von deploy.sh abgewiesen. Gleiche
# Version (erneutes Ausrollen desselben Standes, Hotfix ohne Versionssprung) und groessere Version sind erlaubt.
# Ein bewusster Ruecksprung laeuft ueber rollback.sh oder mit SMARTEINZUG_ALLOW_DOWNGRADE=1 im Handbetrieb.
#
# Funktionen (keine Seiteneffekte, kein set -e noetig):
#   release_app_version <release-ordner>     gibt APP_VERSION aus app/version.php aus (leer, wenn nicht lesbar)
#   release_version_cmp <a> <b>              gibt -1, 0 oder 1 aus (a < b, a = b, a > b), numerisch je Stelle
#   release_downgrade_check <neu> <aktuell>  0 = erlaubt, 1 = Downgrade (Meldung auf stdout), 2 = nicht pruefbar

release_app_version() {
    local f="${1:-}/app/version.php"
    [[ -f "$f" ]] || { printf ''; return 0; }
    sed -n "s/^const APP_VERSION = '\([0-9][0-9.]*\)';.*/\1/p" "$f" | head -n 1
}

release_version_cmp() {
    local a="$1" b="$2" i x y
    local -a pa pb
    IFS=. read -r -a pa <<< "$a"
    IFS=. read -r -a pb <<< "$b"
    for ((i = 0; i < ${#pa[@]} || i < ${#pb[@]}; i++)); do
        x="${pa[i]:-0}"; y="${pb[i]:-0}"
        [[ "$x" =~ ^[0-9]+$ ]] || x=0
        [[ "$y" =~ ^[0-9]+$ ]] || y=0
        if (( 10#$x < 10#$y )); then echo -1; return 0; fi
        if (( 10#$x > 10#$y )); then echo 1; return 0; fi
    done
    echo 0
}

release_downgrade_check() {
    local neu_dir="$1" akt_dir="$2" vneu vakt
    vneu="$(release_app_version "$neu_dir")"
    vakt="$(release_app_version "$akt_dir")"
    if [[ -z "$vneu" || -z "$vakt" ]]; then
        echo "Versionsvergleich nicht moeglich (neu='${vneu:-?}', aktiv='${vakt:-?}'); kein Downgrade-Schutz fuer diesen Lauf."
        return 2
    fi
    if [[ "$(release_version_cmp "$vneu" "$vakt")" == "-1" ]]; then
        echo "Release traegt Version $vneu, aktiv ist bereits Version $vakt."
        return 1
    fi
    echo "Version $vneu (aktiv: $vakt)."
    return 0
}
