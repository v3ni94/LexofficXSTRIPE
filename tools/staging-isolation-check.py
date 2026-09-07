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
  9. redis hat in BEIDEN Umgebungen keinen veroeffentlichten Host-Port, haengt ausschliesslich am
     internen Netz smarteinzug_internal (insbesondere NICHT am oeffentlichen Coolify-Netz) und traegt
     keine Traefik-Labels (waere sonst ueber den Coolify-Proxy erreichbar) - Voraussetzung dafuer, dass
     "protected-mode no" in redis.conf vertretbar ist (siehe redis.conf, Kopfkommentar, und
     docs/vps/06-betrieb.md, Abschnitt "Redis protected mode"). Belegt zugleich, dass Produktion und
     Staging getrennte Redis-Ressourcen verwenden (unterschiedliche Netz-Laufzeitnamen, siehe Punkt 3).
 10. redis traegt in beiden Umgebungen den eindeutigen Alias "smarteinzug-redis" AUSSCHLIESSLICH im
     internen Netz; jeder Dienst aus dem PHP-Image setzt SMARTEINZUG_REDIS_HOST genau darauf und
     SMARTEINZUG_REDIS_EXPECTED_CIDR auf das Subnetz des internen Netzes; PHP-Dienste und redis teilen
     dasselbe interne Netz (Hintergrund: Alias-Kollision von "redis" mit Coolifys eigenem Redis im Netz
     "coolify", siehe docs/vps/06-betrieb.md, Abschnitt "Redis-Alias-Kollision").

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

    # 9. redis: kein Host-Port, ausschliesslich smarteinzug_internal, keine Traefik-Labels - jeweils in
    # BEIDEN Umgebungen. Voraussetzung fuer "protected-mode no" (siehe redis.conf) und zugleich ein
    # weiterer Beleg, dass Produktion und Staging getrennte Redis-Ressourcen verwenden.
    for env_name, cfg in (("Produktion", prod), ("Staging", staging)):
        redis_service = ((cfg.get("services") or {}).get("redis")) or {}
        ports = redis_service.get("ports") or []
        if ports:
            fail(f"redis hat in {env_name} einen veroeffentlichten Host-Port ({ports}). Mit "
                 f"'protected-mode no' waere Redis dann ohne jeden Schutz von aussen erreichbar.")
        nets = set((redis_service.get("networks") or {}).keys())
        if nets != {"smarteinzug_internal"}:
            fail(f"redis haengt in {env_name} an unerwarteten Netzen ({sorted(nets)}), erwartet "
                 f"ausschliesslich {{'smarteinzug_internal'}} (insbesondere nicht am Coolify-Netz).")
        redis_labels = redis_service.get("labels") or {}
        redis_traefik = traefik_names(redis_labels)
        if redis_traefik:
            fail(f"redis traegt in {env_name} Traefik-Labels ({sorted(redis_traefik)}) und waere damit "
                 f"ueber den Coolify-Proxy erreichbar.")
        if not ports and nets == {"smarteinzug_internal"} and not redis_traefik:
            print(f"redis in {env_name}: kein Host-Port, ausschliesslich smarteinzug_internal, keine Traefik-Labels.")

    # 10. Eindeutiger Redis-Alias und dazu passender Stack-Hostname (Version 4.10): Der Hostname "redis"
    # kollidiert auf einem Coolify-Server mit Coolifys eigenem Redis im Netz "coolify". Unser Redis muss
    # deshalb den Alias "smarteinzug-redis" AUSSCHLIESSLICH im internen Netz tragen, JEDER PHP-Container
    # (php, scheduler, worker-*, metrics) muss SMARTEINZUG_REDIS_HOST genau auf diesen Alias setzen und
    # SMARTEINZUG_REDIS_EXPECTED_CIDR muss dem Subnetz des internen Netzes entsprechen; kein Dienst darf
    # den Alias im Coolify-Netz fuehren. PHP-Container und Redis muessen dasselbe interne Netz teilen.
    for env_name, cfg in (("Produktion", prod), ("Staging", staging)):
        services = cfg.get("services") or {}
        redis_service = services.get("redis") or {}
        redis_nets = redis_service.get("networks") or {}
        internal = redis_nets.get("smarteinzug_internal") or {}
        aliases = set(internal.get("aliases") or [])
        if "smarteinzug-redis" not in aliases:
            fail(f"redis traegt in {env_name} nicht den Alias 'smarteinzug-redis' im Netz smarteinzug_internal "
                 f"(Aliase: {sorted(aliases)}). Ohne eindeutigen Alias loest 'redis' zu Coolifys Redis auf.")
        subnet = None
        try:
            subnet = (((cfg.get("networks") or {}).get("smarteinzug_internal") or {}).get("ipam") or {}).get("config", [{}])[0].get("subnet")
        except (AttributeError, IndexError, TypeError):
            subnet = None
        php_like = [n for n, s in services.items() if isinstance(s, dict) and s.get("image") == "smarteinzug-php:local"]
        bad_host, bad_cidr, not_internal = [], [], []
        for name in php_like:
            s = services[name]
            envs = s.get("environment") or {}
            if envs.get("SMARTEINZUG_REDIS_HOST") != "smarteinzug-redis":
                bad_host.append(f"{name}={envs.get('SMARTEINZUG_REDIS_HOST')!r}")
            if envs.get("SMARTEINZUG_REDIS_EXPECTED_CIDR") != subnet:
                bad_cidr.append(f"{name}={envs.get('SMARTEINZUG_REDIS_EXPECTED_CIDR')!r}")
            if "smarteinzug_internal" not in (s.get("networks") or {}):
                not_internal.append(name)
        if bad_host:
            fail(f"{env_name}: SMARTEINZUG_REDIS_HOST ist nicht ueberall 'smarteinzug-redis': {bad_host}")
        if bad_cidr:
            fail(f"{env_name}: SMARTEINZUG_REDIS_EXPECTED_CIDR passt nicht zum Subnetz {subnet!r} von "
                 f"smarteinzug_internal: {bad_cidr}")
        if not_internal:
            fail(f"{env_name}: PHP-Dienste ohne Anbindung an smarteinzug_internal (koennen Redis nicht erreichen): {not_internal}")
        alias_on_coolify = [n for n, s in services.items() if isinstance(s, dict)
                            and "smarteinzug-redis" in set((((s.get("networks") or {}).get("coolify") or {}) or {}).get("aliases") or [])]
        if alias_on_coolify:
            fail(f"{env_name}: Alias 'smarteinzug-redis' im Coolify-Netz gefunden ({alias_on_coolify}); er darf nur im internen Netz existieren.")
        if "smarteinzug-redis" in aliases and not bad_host and not bad_cidr and not not_internal and not alias_on_coolify:
            print(f"{env_name}: redis-Alias 'smarteinzug-redis' nur im internen Netz, {len(php_like)} PHP-Dienste mit passendem "
                  f"SMARTEINZUG_REDIS_HOST und CIDR {subnet}, alle im selben internen Netz wie redis.")

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
