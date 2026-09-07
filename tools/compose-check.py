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
