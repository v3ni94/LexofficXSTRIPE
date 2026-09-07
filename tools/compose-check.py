#!/usr/bin/env python3
"""
Prueft die Compose-Dateien des VPS-Stacks (deploy/vps) ohne laufenden Docker-Daemon.

Hintergrund: Beim ersten Deployment auf dem Hostinger-VPS meldete der Dienst "metrics" dauerhaft
"unhealthy" und brach deploy.sh ab. Ursache war ein fehlender eigener Healthcheck: der Dienst erbte den
Standard-Healthcheck des PHP-Images (bin/healthcheck.php --heartbeat), erzeugt als Metrik-Sammler aber
keinen Worker-Heartbeat. Dieses Skript verhindert genau diese Fehlerklasse dauerhaft.

Geprueft wird:
  1. Jeder Dienst aus dem gemeinsamen PHP-Image hat einen eigenen Healthcheck (kein geerbter).
  2. Der Healthcheck passt zur tatsaechlichen Prozessart (Kommando): php-fpm -> --db,
     bin/worker.php / bin/scheduler.php -> --heartbeat, bin/host-metrics.php -> --metrics.
  3. Das PHP-Image gibt keinen vererbbaren Standard-Healthcheck vor (HEALTHCHECK NONE im Dockerfile).
  4. In Healthcheck-Kommandos steht kein "$" (Docker Compose wuerde es als Variable auswerten;
     ein einfaches $c wurde zu einer leeren Zeichenkette und beschaedigte den Befehl).
  5. Jede Variable in den Compose-Dateien ist entweder in .env.example vorhanden, hat einen
     Vorgabewert (${VAR:-...}) oder ist als $$ literal escaped.
  6. Die von healthcheck.php unterstuetzten Schalter existieren tatsaechlich (Abgleich mit dem PHP-Code).
  7. RELEASE_SHA (working_dir aller PHP-Container, Caddys Dokumentenstamm) ist ausschliesslich als
     PFLICHTWERT (${RELEASE_SHA:?...}) referenziert, nie mit einem Vorgabewert (${RELEASE_SHA:-...}).
     Ein Vorgabewert waere eine Regression zurueck auf einen impliziten, moeglicherweise falschen
     Stand (z.B. den mutable Symlink "current") und wuerde genau die Garantie aufheben, dass Container,
     Healthcheck und Code immer zum selben Release gehoeren (siehe deploy.sh, Abschnitt Release-Bindung).
  8. Keine Compose-Datei definiert einen Dienst "mariadb" oder "backup": Die Datenbank ist eine externe
     Coolify-Ressource, die Sicherung uebernimmt Coolify (siehe docker-compose.yml, Kopfkommentar).
  9. Kein "docker-compose*.yml" verwendet ausserhalb eines Kommentars noch den Pfad
     "/opt/smarteinzug/releases/current" als working_dir, root oder Bind-Mount-Ziel (Regression zurueck
     auf den mutable Symlink); der Symlink darf weiterhin als Buchfuehrung in Kommentaren erwaehnt werden.
  10. deploy/vps/scripts/deploy.sh haelt die Reihenfolge Candidate-Pruefung -> Migration -> Cutover
      ("up -d") ein, und weder die Candidate-Pruefung noch die Migration fassen ueber "docker compose
      run --rm --no-deps" hinaus die laufenden Container an (kein "up"/"restart" in diesen Bloecken).
  11. Scheduler, Worker und Metrik-Sammler: stop_signal SIGTERM, begrenzte stop_grace_period (Worker 60 bis
      120 s, Metrik-Sammler 5 bis 60 s), php direkt als PID 1 ohne "sh -c"-Wrapper (Ursache der 11 Minuten
      Wartezeit beim Container-Stopp, Version 4.11).
  12. deploy.sh/rollback.sh schliessen beim rsync nach /opt/smarteinzug/deploy alle Laufzeitdateien
      (/.deploy*, /.release*, /.previous_sha, /.php-image.sha256, /.env) aus, enthalten kein "restart -t"
      mehr und verifizieren die Release-Bindung der Container per docker inspect.

Aufruf:  python3 tools/compose-check.py        Exit 0 = in Ordnung, 1 = Fehler
"""
import pathlib
import re
import sys

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML fehlt: pip install pyyaml", file=sys.stderr)
    sys.exit(2)

ROOT = pathlib.Path(__file__).resolve().parent.parent
VPS = ROOT / "deploy" / "vps"
PHP_IMAGE = "smarteinzug-php:local"

# Kommando im Container -> erwarteter Schalter von bin/healthcheck.php
COMMAND_TO_FLAG = [
    ("bin/host-metrics.php", "--metrics"),
    ("bin/worker.php", "--heartbeat"),
    ("bin/scheduler.php", "--heartbeat"),
]
DEFAULT_FLAG = "--db"  # ohne eigenes Kommando laeuft php-fpm (Web)

errors: list[str] = []
checked = 0


def fail(msg: str) -> None:
    errors.append(msg)


def command_text(service: dict) -> str:
    cmd = service.get("command")
    if cmd is None:
        return ""
    return " ".join(cmd) if isinstance(cmd, list) else str(cmd)


def healthcheck_flags(service: dict) -> list[str]:
    test = (service.get("healthcheck") or {}).get("test")
    if not test:
        return []
    parts = test if isinstance(test, list) else [str(test)]
    return [p for p in parts if isinstance(p, str) and p.startswith("--")]


def check_services(path: pathlib.Path) -> None:
    global checked
    data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    for name, service in (data.get("services") or {}).items():
        if not isinstance(service, dict) or service.get("image") != PHP_IMAGE:
            continue
        checked += 1
        cmd = command_text(service)
        expected = DEFAULT_FLAG
        for needle, flag in COMMAND_TO_FLAG:
            if needle in cmd:
                expected = flag
                break
        test = (service.get("healthcheck") or {}).get("test")
        if not test:
            fail(f"{path.name}: Dienst '{name}' hat keinen eigenen Healthcheck. Ohne eigenen Eintrag "
                 f"wuerde ein Standard-Healthcheck des Images gelten, der zur Prozessart nicht passt. "
                 f"Erwartet: bin/healthcheck.php {expected}")
            continue
        flags = healthcheck_flags(service)
        if expected not in flags:
            fail(f"{path.name}: Dienst '{name}' fuehrt '{cmd or 'php-fpm'}' aus, der Healthcheck nutzt aber "
                 f"{flags or test} statt {expected}.")
        if expected != "--heartbeat" and "--heartbeat" in flags:
            fail(f"{path.name}: Dienst '{name}' ist kein Worker/Scheduler, verwendet aber den "
                 f"Worker-Heartbeat-Healthcheck. Genau dieser Fehler liess 'metrics' dauerhaft ungesund wirken.")
        parts = test if isinstance(test, list) else [str(test)]
        for part in parts:
            if isinstance(part, str) and "$" in part:
                fail(f"{path.name}: Healthcheck von '{name}' enthaelt '$' ({part!r}). Docker Compose wertet "
                     f"das als Variable aus. Healthcheck ohne Shell-Ausdruck formulieren oder '$' als '$$' "
                     f"escapen.")


def strip_comment(line: str) -> str:
    """YAML-Kommentar entfernen (# am Zeilenanfang oder nach Leerzeichen, ausserhalb von Anfuehrungszeichen)."""
    quote = ""
    for i, ch in enumerate(line):
        if quote:
            if ch == quote:
                quote = ""
        elif ch in "\"'":
            quote = ch
        elif ch == "#" and (i == 0 or line[i - 1] in " \t"):
            return line[:i]
    return line


def check_variables(path: pathlib.Path, known: set[str]) -> None:
    """Jede Variable muss einen Vorgabewert haben oder in .env.example stehen; $ nur als ${...} oder $$."""
    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = strip_comment(raw)
        i = 0
        while i < len(line):
            if line[i] != "$":
                i += 1
                continue
            if i + 1 < len(line) and line[i + 1] == "$":  # $$ = literales Dollarzeichen
                i += 2
                continue
            if i + 1 < len(line) and line[i + 1] == "{":
                end = line.find("}", i)
                if end == -1:
                    fail(f"{path.name}:{lineno}: '${{' ohne schliessende Klammer.")
                    break
                inner = line[i + 2:end]
                name = re.split(r"[:?\-]", inner, maxsplit=1)[0]
                has_default = ":-" in inner or ":?" in inner or inner.startswith(name + "-")
                if not name:
                    fail(f"{path.name}:{lineno}: '${{}}' ohne Variablennamen.")
                elif not has_default and name not in known:
                    fail(f"{path.name}:{lineno}: Variable ${{{name}}} hat keinen Vorgabewert und fehlt in "
                         f".env.example.")
                i = end + 1
                continue
            rest = re.match(r"\$([A-Za-z_][A-Za-z0-9_]*)", line[i:])
            if rest:
                fail(f"{path.name}:{lineno}: Variable ${rest.group(1)} ohne geschweifte Klammern. "
                     f"${{{rest.group(1)}}} schreiben oder als $${rest.group(1)} escapen, wenn ein literales "
                     f"Dollarzeichen gemeint ist (genau daran scheiterte der erste Healthcheck-Versuch).")
                i += len(rest.group(0))
                continue
            fail(f"{path.name}:{lineno}: '$' ohne Variablennamen.")
            i += 1


def check_release_sha_required(path: pathlib.Path) -> None:
    """RELEASE_SHA darf nirgends einen Vorgabewert haben (waere eine Regression, siehe Docstring)."""
    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = strip_comment(raw)
        for m in re.finditer(r"\$\{RELEASE_SHA([^}]*)\}", line):
            inner = m.group(1)
            if inner.startswith(":?") or inner == "":
                continue
            fail(f"{path.name}:{lineno}: RELEASE_SHA hat einen Vorgabewert ('${{RELEASE_SHA{inner}}}') statt "
                 f"eines Pflichtwerts (${{RELEASE_SHA:?...}}). Ein Vorgabewert wuerde bei fehlender Variable "
                 f"still auf einen falschen Stand zurueckfallen, statt den Aufruf klar abzulehnen.")
        # $RELEASE_SHA ohne geschweifte Klammern wird bereits von check_variables() als Fehler gemeldet.


def check_no_removed_services(path: pathlib.Path) -> None:
    """Die Datenbank und die Sicherung sind externe Coolify-Ressourcen, kein Dienst in diesem Stack."""
    data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    for forbidden in ("mariadb", "backup"):
        if forbidden in (data.get("services") or {}):
            fail(f"{path.name}: Dienst '{forbidden}' ist definiert. Die Datenbank ist eine externe "
                 f"Coolify-Ressource, die Sicherung uebernimmt Coolify; ein eigener Dienst hierfuer waere "
                 f"eine doppelte, unkontrolliert parallele Ressource (siehe docker-compose.yml, Kopfkommentar).")


def check_deploy_sh_candidate_order() -> None:
    """
    deploy.sh muss die Reihenfolge Candidate-Pruefung -> Migration -> Cutover ("up -d") beibehalten, und
    Candidate-Pruefung sowie Migration duerfen NIE die laufenden Container anfassen (nur "run --rm
    --no-deps", nie "up"/"restart"). Hintergrund: Genau diese Reihenfolge stellt sicher, dass ein
    fehlschlagender Candidate (z.B. Redis mit dem neuen Code nicht erreichbar) die laufende Produktion
    unveraendert laesst; eine kuenftige Aenderung, die diese Reihenfolge umkehrt oder die Isolation
    aufhebt, waere ein Rueckfall auf genau das Risiko, das mit RELEASE_SHA und der Candidate-Isolation
    behoben wurde (siehe deploy.sh, Kopfkommentar).
    """
    text = (ROOT / "deploy" / "vps" / "scripts" / "deploy.sh").read_text(encoding="utf-8")

    def first_index(needle: str) -> int:
        idx = text.find(needle)
        if idx == -1:
            fail(f"deploy.sh: erwarteter Textbaustein nicht gefunden: {needle!r}")
        return idx

    candidate_idx = first_index("run --rm --no-deps -T php php bin/healthcheck.php --db --redis")
    migrate_idx = first_index("run --rm --no-deps -T php php bin/migrate.php")
    cutover_idx = first_index("up -d --remove-orphans")
    if -1 in (candidate_idx, migrate_idx, cutover_idx):
        return
    if not (candidate_idx < migrate_idx < cutover_idx):
        fail("deploy.sh: Reihenfolge Candidate-Pruefung -> Migration -> Cutover ist nicht mehr eingehalten "
             "(candidate=%d migrate=%d cutover=%d). Ohne diese Reihenfolge koennte neuer Code live "
             "Anfragen beantworten, bevor er isoliert geprueft und migriert wurde." % (candidate_idx, migrate_idx, cutover_idx))

    # Zwischen "Pruefe den Candidaten isoliert" und "Spiele Datenbankmigrationen" (also im Codeblock der
    # Candidate-Pruefung) darf kein "up -d" oder "restart" auftauchen: Der Candidate darf die laufenden
    # Container nicht anfassen.
    candidate_block_start = first_index("Pruefe den Candidaten isoliert")
    migrate_block_start = first_index("Spiele Datenbankmigrationen isoliert")
    cutover_block_start = first_index("Cutover: laufende Container")
    if -1 in (candidate_block_start, migrate_block_start, cutover_block_start):
        return
    candidate_block = text[candidate_block_start:migrate_block_start]
    migrate_block = text[migrate_block_start:cutover_block_start]
    for name, block in (("Candidate-Pruefung", candidate_block), ("Migration", migrate_block)):
        for forbidden in ("up -d", "restart ", "compose up", "compose restart"):
            if forbidden in block:
                fail(f"deploy.sh: Block '{name}' enthaelt '{forbidden}'. Candidate-Pruefung und Migration "
                     f"duerfen ausschliesslich ueber 'docker compose run --rm --no-deps' laufen und niemals "
                     f"die laufenden Container anfassen.")
        if "--no-deps" not in block:
            fail(f"deploy.sh: Block '{name}' verwendet nicht '--no-deps'. Ohne '--no-deps' wuerde 'docker "
                 f"compose run' abhaengige Dienste ggf. mitstarten/neu erzeugen statt nur einen isolierten "
                 f"Einwegcontainer zu verwenden.")


def check_background_stop_config(path: pathlib.Path) -> None:
    """
    Scheduler, Worker und Metrik-Sammler: stop_signal MUSS SIGTERM sein (das Basisimage php:*-fpm setzt
    STOPSIGNAL SIGQUIT, das die PHP-Prozesse frueher nicht behandelten -> "Container failed to exit within
    11m0s of signal 3"), stop_grace_period muss gesetzt und begrenzt sein (60 bis 120 s; 660 s waren die
    11-Minuten-Wartezeit), und "command:" muss php DIREKT als PID 1 starten (kein "sh -c"-Wrapper, der
    Signale nicht weiterreicht). php-fpm (Web), Redis und Caddy werden hier bewusst nicht bewertet.
    """
    data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    for name, service in (data.get("services") or {}).items():
        if not isinstance(service, dict) or service.get("image") != PHP_IMAGE:
            continue
        cmd = command_text(service)
        if not any(needle in cmd for needle in ("bin/worker.php", "bin/scheduler.php", "bin/host-metrics.php")):
            continue
        raw = service.get("command")
        first = raw[0] if isinstance(raw, list) and raw else (str(raw).split()[0] if raw else "")
        if first in ("sh", "bash", "/bin/sh", "/bin/bash"):
            fail(f"{path.name}: Dienst '{name}' startet ueber einen Shell-Wrapper ('{first}'). php muss direkt "
                 f"PID 1 sein, sonst erreicht das Stop-Signal den PHP-Prozess nicht zuverlaessig.")
        if str(service.get("stop_signal", "")).upper() != "SIGTERM":
            fail(f"{path.name}: Dienst '{name}' hat kein 'stop_signal: SIGTERM' (erbt sonst STOPSIGNAL SIGQUIT "
                 f"des php-fpm-Basisimages; Worker liefen damit bis zur Grace-Period weiter).")
        grace = str(service.get("stop_grace_period", "")).strip()
        m = re.fullmatch(r"(\d+)(s|m)?", grace)
        seconds = int(m.group(1)) * (60 if m.group(2) == "m" else 1) if m else None
        if seconds is None:
            fail(f"{path.name}: Dienst '{name}' hat keine (auswertbare) stop_grace_period ('{grace}').")
        elif "bin/host-metrics.php" in cmd:
            if not 5 <= seconds <= 60:
                fail(f"{path.name}: Dienst '{name}' (Metrik-Sammler) stop_grace_period={seconds}s, erwartet 5 bis 60 s.")
        elif not 60 <= seconds <= 120:
            fail(f"{path.name}: Dienst '{name}' stop_grace_period={seconds}s, erwartet 60 bis 120 s (abgeleitet: "
                 f"Notbremse 30 s + laengster externer Aufruf 30 s + Reserve; 660 s waren die 11-Minuten-Wartezeit).")


def check_deploy_scripts_runtime_state() -> None:
    """
    deploy.sh und rollback.sh kopieren deploy/vps per "rsync --delete" nach /opt/smarteinzug/deploy; die
    Laufzeitdateien dort (.deploy.lock, .deploy.pid, .deploy-status.json, .release_history, .release.env,
    .previous_sha, .php-image.sha256, .env) liegen NICHT im Release und muessen ausgeschlossen bleiben.
    Fehlt "/.deploy*", loescht der rsync die vom Runner gerade geschriebene Statusdatei, deploy-status.sh
    meldet waehrend des gesamten Deployments "phase=unknown" (Ursache des GitHub-Timeouts, Version 4.11).
    Ausserdem darf kein Skript mehr "restart -t" auf die Hintergrunddienste anwenden (redundanter zweiter
    Neustart, frueher bis zu 11 Minuten); stattdessen wird die Release-Bindung per docker inspect verifiziert.
    """
    for name in ("deploy.sh", "rollback.sh"):
        text = (ROOT / "deploy" / "vps" / "scripts" / name).read_text(encoding="utf-8")
        rsyncs = [l for l in text.splitlines() if "rsync -a --delete" in l and "$RELEASE_DIR/deploy/vps/" in "".join(text.splitlines()[text.splitlines().index(l):text.splitlines().index(l) + 3])]
        if not rsyncs:
            fail(f"{name}: rsync von deploy/vps nach dem Deploy-Ordner nicht gefunden.")
        for l in rsyncs:
            idx = text.splitlines().index(l)
            block = " ".join(text.splitlines()[idx:idx + 3])
            for pattern in ("--exclude '/.deploy*'", "--exclude '/.release*'", "--exclude '/.previous_sha'",
                            "--exclude '/.php-image.sha256'", "--exclude '/.env'"):
                if pattern not in block:
                    fail(f"{name}: rsync nach dem Deploy-Ordner ohne {pattern}; die Laufzeitdatei wuerde durch "
                         f"'--delete' entfernt (Statusdatei -> phase=unknown waehrend des Deployments).")
        code_lines = "\n".join(l for l in text.splitlines() if not l.lstrip().startswith("#"))
        if re.search(r"restart\s+-t\s+\d+", code_lines):
            fail(f"{name}: enthaelt noch 'restart -t' (zweiter Worker-Neustart nach dem Cutover ist redundant und "
                 f"wartete frueher bis zu 660 s je Dienst).")
        if "{{.Config.WorkingDir}}" not in text:
            fail(f"{name}: verifiziert die Release-Bindung der Container nicht (docker inspect .Config.WorkingDir).")


def check_status_publish_path() -> None:
    """
    Weg der oeffentlichen Statusdaten: Die Anwendung schreibt status.json in den gemeinsamen Ordner
    /opt/smarteinzug/shared/status (config status_publish.file), Caddy liefert genau diese Datei unter dem
    Status-Host aus. Das Release ist fuer die Container nur lesend; ohne diesen Weg bliebe die Seite
    dauerhaft auf "Status unbekannt (Daten veraltet)". Caddy darf den Ordner nur LESEND sehen (er enthaelt
    keine Kundendaten, aber Schreibrechte braucht dort nur PHP), und shared/storage bleibt fuer Caddy
    unsichtbar (dort liegen Mandatsdokumente und Profilbilder).
    """
    compose = (VPS / "docker-compose.yml").read_text(encoding="utf-8")
    caddyfile = (VPS / "Caddyfile").read_text(encoding="utf-8")
    deploy_sh = (ROOT / "deploy" / "vps" / "scripts" / "deploy.sh").read_text(encoding="utf-8")

    if "/opt/smarteinzug/shared/status:/opt/smarteinzug/shared/status:ro" not in compose:
        fail("docker-compose.yml: caddy bindet /opt/smarteinzug/shared/status nicht (nur lesend) ein; "
             "die Statusseite koennte nur den Platzhalter aus dem Release ausliefern.")
    if "source: /opt/smarteinzug/shared/status" not in compose:
        fail("docker-compose.yml: die PHP-Container binden /opt/smarteinzug/shared/status nicht ein; "
             "status_publish koennte die Datei nicht schreiben (Release ist read-only).")
    if "shared/storage:/opt/smarteinzug/shared/storage" in compose:
        fail("docker-compose.yml: caddy bindet shared/storage ein. Dort liegen Kundendaten "
             "(Mandatsdokumente, Profilbilder); die Statusdaten gehoeren in shared/status.")
    if "root * /opt/smarteinzug/shared/status" not in caddyfile or "handle /status.json" not in caddyfile:
        fail("Caddyfile: /status.json wird nicht aus /opt/smarteinzug/shared/status ausgeliefert.")
    if 'install -d -m 750 "$BASE/shared/status"' not in deploy_sh:
        fail("deploy.sh legt /opt/smarteinzug/shared/status nicht an; auf aelter eingerichteten Servern "
             "fehlt der Ordner und Caddy liefert 404 statt der Statusdaten.")
    config_example = (ROOT / "php-ionos" / "app" / "config.example.php").read_text(encoding="utf-8")
    if "shared/status/status.json" not in config_example:
        fail("app/config.example.php nennt das Ziel status_publish.file nicht; die Einrichtung bliebe unklar.")


def check_no_mutable_current_path(path: pathlib.Path) -> None:
    """working_dir/root/Bind-Mount-Ziele duerfen nicht mehr auf den mutable Symlink "current" zeigen."""
    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = strip_comment(raw)
        if "/opt/smarteinzug/releases/current" in line:
            fail(f"{path.name}:{lineno}: Verweist noch auf '/opt/smarteinzug/releases/current' (mutable "
                 f"Symlink). working_dir und Bind-Mount-Ziele muessen ueber ${{RELEASE_SHA:?...}} an ein "
                 f"konkretes Release gebunden sein, sonst kann ein Container mit neuer Compose-Konfiguration "
                 f"(z.B. einem neuen Healthcheck) auf aelteren Code treffen.")


def main() -> int:
    dockerfile = (VPS / "php" / "Dockerfile").read_text(encoding="utf-8")
    if not re.search(r"^HEALTHCHECK\s+NONE\s*$", dockerfile, re.M):
        fail("deploy/vps/php/Dockerfile: 'HEALTHCHECK NONE' fehlt. Ein Standard-Healthcheck im Image gilt "
             "fuer Web, Scheduler, Worker und Metrik-Sammler gleichzeitig und ist damit fuer mindestens "
             "eine Rolle falsch; jeder Dienst definiert seinen Healthcheck in docker-compose.yml selbst.")

    env_example = (VPS / ".env.example").read_text(encoding="utf-8")
    known = {m.group(1) for m in re.finditer(r"^([A-Z_][A-Z0-9_]*)=", env_example, re.M)}

    compose_files = sorted(VPS.glob("docker-compose*.yml"))
    if not compose_files:
        fail("deploy/vps: keine docker-compose*.yml gefunden.")
    for path in compose_files:
        check_services(path)
        check_variables(path, known)
        check_release_sha_required(path)
        check_no_removed_services(path)
        check_no_mutable_current_path(path)
        check_background_stop_config(path)

    check_deploy_sh_candidate_order()
    check_deploy_scripts_runtime_state()
    check_status_publish_path()

    healthcheck_php = (ROOT / "php-ionos" / "bin" / "healthcheck.php").read_text(encoding="utf-8")
    for _, flag in COMMAND_TO_FLAG + [("", DEFAULT_FLAG)]:
        if f"$opts['{flag.lstrip('-')}']" not in healthcheck_php:
            fail(f"bin/healthcheck.php kennt den Schalter {flag} nicht, er wird aber in docker-compose.yml "
                 f"verwendet.")

    for path in compose_files:
        print(f"geprueft: {path.relative_to(ROOT)}")
    print(f"{checked} Dienste aus dem PHP-Image geprueft.")
    if errors:
        print()
        for e in errors:
            print(f"FEHLER: {e}")
        print(f"\n{len(errors)} Fehler")
        return 1
    print("0 Fehler")
    return 0


if __name__ == "__main__":
    sys.exit(main())
