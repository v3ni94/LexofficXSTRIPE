#!/usr/bin/env python3
"""
Prueft die Trennung von Produktion und Staging im VPS-Stack, ohne einen laufenden Docker-Daemon und
OHNE irgendetwas zu starten oder zu veraendern (nur "docker compose ... config", reine Textausgabe).

Hintergrund: Eine Pruefung auf dem produktiven VPS ergab, dass Staging und Produktion denselben
Compose-Projektnamen ("name: smarteinzug") erbten und damit dieselben Container-, Netz- und
Volume-Namen erhalten haetten (z.B. "smarteinzug_smarteinzug_internal", "smarteinzug_caddy_data"); ein
versehentlicher "docker compose up -d" fuer Staging haette produktive Ressourcen neu erzeugen oder
ueberschreiben koennen. Zusaetzlich verwendeten beide Umgebungen identische Traefik-Router-/
Middleware-/Dienstnamen (nur der Host()-Regelwert unterschied sich); auf demselben Coolify-Proxy
haetten sich Produktion und Staging beim Registrieren dieser Router gegenseitig ueberschrieben,
unabhaengig vom Compose-Projektnamen (Traefik kennt keine Compose-Projekte).

Geprueft wird (jeweils gegen die tatsaechlich von "docker compose ... config" aufgeloeste
Konfiguration, nicht nur den Rohtext der YAML-Dateien):
  1. Produktion und Staging erhalten unterschiedliche Compose-Projektnamen.
  2. Die persistenten Volumes (caddy_data, caddy_config) erhalten dadurch automatisch unterschiedliche
     Laufzeitnamen.
  3. Das interne Netz (smarteinzug_internal) erhaelt dadurch automatisch einen unterschiedlichen
     Laufzeitnamen.
  4. Die Traefik-Router-, Middleware- und Dienstnamen von Produktion und Staging ueberschneiden sich in
     KEINEM Namen (unabhaengig vom Compose-Projekt, siehe oben).
  5. Die Host()-Regel von Produktion referenziert nicht die Staging-Domain und umgekehrt.
  6. bin/healthcheck.php kennt den Schalter --expect-env (Schutz gegen einen versehentlichen
     Staging-Deploy/-Rollback gegen die Produktionskonfiguration).
  7. deploy.sh uebergibt --expect-env=$DEPLOY_ENV an die isolierte Candidate-Pruefung, rollback.sh
     prueft --expect-env=$DEPLOY_ENV vor jedem Rollback.
  8. app/config.example.php dokumentiert das Feld 'environment'.

Aufruf: python3 tools/staging-isolation-check.py     Exit 0 = in Ordnung, 1 = Fehler
"""
import pathlib
import re
import subprocess
import sys

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML fehlt: pip install pyyaml", file=sys.stderr)
    sys.exit(2)

ROOT = pathlib.Path(__file__).resolve().parent.parent
VPS = ROOT / "deploy" / "vps"

errors: list[str] = []


def fail(msg: str) -> None:
    errors.append(msg)


def resolved_config(overlay: str) -> dict:
    cmd = [
        "docker", "compose",
        "-f", str(VPS / "docker-compose.yml"),
        "-f", str(VPS / overlay),
        "--env-file", str(VPS / ".env.example"),
        "config",
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, env={"RELEASE_SHA": "isolation-check", "PATH": "/usr/bin:/bin"})
    if proc.returncode != 0:
        fail(f"'docker compose ... -f {overlay} config' schlug fehl (Exitcode {proc.returncode}): {proc.stderr.strip()}")
        return {}
    return yaml.safe_load(proc.stdout) or {}


def traefik_names(labels: dict) -> set[str]:
    """Alle Router-/Middleware-/Dienstnamen aus Traefik-Compose-Labels (Docker-Label-basierte Discovery)."""
    names = set()
    for key in labels or {}:
        m = re.match(r"traefik\.http\.(routers|middlewares|services)\.([^.]+)\.", key)
        if m:
            names.add(f"{m.group(1)}:{m.group(2)}")
    return names


def main() -> int:
    prod = resolved_config("docker-compose.prod.yml")
    staging = resolved_config("docker-compose.staging.yml")
    if not prod or not staging:
        print("Konnte eine der beiden Konfigurationen nicht aufloesen, weitere Pruefungen uebersprungen.")
        for e in errors:
            print(f"FEHLER: {e}")
        return 1

    # 1. Projektname
    prod_name = prod.get("name")
    staging_name = staging.get("name")
    if not prod_name or not staging_name or prod_name == staging_name:
        fail(f"Compose-Projektnamen sind nicht eindeutig getrennt (Produktion={prod_name!r}, "
             f"Staging={staging_name!r}). docker-compose.staging.yml muss ein eigenes 'name:' setzen.")
    else:
        print(f"Projektnamen getrennt: Produktion={prod_name!r}, Staging={staging_name!r}")

    # 2. Volumes
    for vol in ("caddy_data", "caddy_config"):
        prod_vol_name = ((prod.get("volumes") or {}).get(vol) or {}).get("name")
        staging_vol_name = ((staging.get("volumes") or {}).get(vol) or {}).get("name")
        if not prod_vol_name or not staging_vol_name or prod_vol_name == staging_vol_name:
            fail(f"Volume '{vol}' hat keinen eindeutig getrennten Laufzeitnamen (Produktion="
                 f"{prod_vol_name!r}, Staging={staging_vol_name!r}).")
        else:
            print(f"Volume '{vol}' getrennt: Produktion={prod_vol_name!r}, Staging={staging_vol_name!r}")

    # 3. Internes Netz
    prod_net_name = ((prod.get("networks") or {}).get("smarteinzug_internal") or {}).get("name")
    staging_net_name = ((staging.get("networks") or {}).get("smarteinzug_internal") or {}).get("name")
    if not prod_net_name or not staging_net_name or prod_net_name == staging_net_name:
        fail(f"Netz 'smarteinzug_internal' hat keinen eindeutig getrennten Laufzeitnamen (Produktion="
             f"{prod_net_name!r}, Staging={staging_net_name!r}).")
    else:
        print(f"Netz 'smarteinzug_internal' getrennt: Produktion={prod_net_name!r}, Staging={staging_net_name!r}")

    # 4. Traefik-Namen duerfen sich nicht ueberschneiden (Traefik kennt keine Compose-Projekte)
    prod_labels = ((prod.get("services") or {}).get("caddy") or {}).get("labels") or {}
    staging_labels = ((staging.get("services") or {}).get("caddy") or {}).get("labels") or {}
    prod_traefik = traefik_names(prod_labels)
    staging_traefik = traefik_names(staging_labels)
    overlap = prod_traefik & staging_traefik
    if not prod_traefik or not staging_traefik:
        fail("Konnte keine Traefik-Router-/Dienstnamen aus den Caddy-Labels lesen (Produktion oder "
             "Staging); Pruefung auf Ueberschneidung uebersprungen.")
    elif overlap:
        fail(f"Produktion und Staging verwenden identische Traefik-Namen: {sorted(overlap)}. Auf "
             f"demselben Coolify-Proxy wuerde der zuletzt gestartete Container den Router des jeweils "
             f"anderen ueberschreiben.")
    else:
        print(f"Traefik-Namen getrennt: Produktion={sorted(prod_traefik)}, Staging={sorted(staging_traefik)}")

    # 5. Host()-Regeln verweisen nicht auf die jeweils andere Domain
    prod_rule_text = " ".join(v for k, v in prod_labels.items() if isinstance(v, str) and ".rule" in k)
    staging_rule_text = " ".join(v for k, v in staging_labels.items() if isinstance(v, str) and ".rule" in k)
    if "staging.smart-einzug.de" in prod_rule_text:
        fail(f"Die Produktions-Host()-Regel enthaelt die Staging-Domain: {prod_rule_text!r}.")
    if any(d in staging_rule_text for d in ("app.smart-einzug.de", "admin.smart-einzug.de", "api.smart-einzug.de", "status.smart-einzug.de")):
        fail(f"Die Staging-Host()-Regel enthaelt eine Produktionsdomain: {staging_rule_text!r}.")
    if "staging.smart-einzug.de" not in prod_rule_text and not any(
        d in staging_rule_text for d in ("app.smart-einzug.de", "admin.smart-einzug.de", "api.smart-einzug.de", "status.smart-einzug.de")
    ):
        print("Host()-Regeln sauber getrennt (keine Domain der anderen Umgebung).")

    # 6. bin/healthcheck.php kennt --expect-env
    healthcheck_php = (ROOT / "php-ionos" / "bin" / "healthcheck.php").read_text(encoding="utf-8")
    if "$opts['expect-env']" not in healthcheck_php:
        fail("bin/healthcheck.php kennt den Schalter --expect-env nicht (Schutz gegen einen "
             "versehentlichen Staging-Deploy/-Rollback gegen die Produktionskonfiguration fehlt).")
    else:
        print("bin/healthcheck.php kennt --expect-env.")

    # 7. deploy.sh und rollback.sh rufen --expect-env auf
    deploy_sh = (VPS / "scripts" / "deploy.sh").read_text(encoding="utf-8")
    if "--expect-env=" not in deploy_sh:
        fail("deploy.sh uebergibt --expect-env nicht an die Candidate-Pruefung.")
    else:
        print("deploy.sh prueft --expect-env in der Candidate-Pruefung.")
    rollback_sh = (VPS / "scripts" / "rollback.sh").read_text(encoding="utf-8")
    if "--expect-env=" not in rollback_sh:
        fail("rollback.sh prueft --expect-env nicht vor dem Rollback.")
    else:
        print("rollback.sh prueft --expect-env vor dem Rollback.")

    # 8. app/config.example.php dokumentiert 'environment'
    config_example = (ROOT / "php-ionos" / "app" / "config.example.php").read_text(encoding="utf-8")
    if "'environment'" not in config_example:
        fail("app/config.example.php dokumentiert das Feld 'environment' nicht.")
    else:
        print("app/config.example.php dokumentiert 'environment'.")

    print()
    if errors:
        for e in errors:
            print(f"FEHLER: {e}")
        print(f"\n{len(errors)} Fehler")
        return 1
    print("0 Fehler")
    return 0


if __name__ == "__main__":
    sys.exit(main())
