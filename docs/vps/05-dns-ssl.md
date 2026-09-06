# DNS und SSL

Stand: 06.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`). Betrifft ausschließlich die neuen Anwendungshosts auf dem VPS.
Die Marketingdomains (`smart-einzug.de`, `lexware-einzug.de`, `lexoffice-einzug.de` und die
weiteren in `websites/`) bleiben unverändert auf dem IONOS-Webhosting und sind hier nicht
betroffen. Die Domainverwaltung (DNS-Zone) bleibt unabhängig vom Server, auf dem die Anwendung
läuft, weiterhin beim registrierten Anbieter der Domain (IONOS Kundenbereich); nur das Ziel der
A-Einträge ändert sich auf die IPv4-Adresse des Hostinger-VPS.

**Zeitpunkt:** Die produktiven DNS-Einträge werden während der Einrichtung des Hostinger-VPS
ausdrücklich NICHT geändert. Erst nach vollständigem Test (Kapitel 08, dann diese Datei und
`docs/vps/07-cutover-checkliste.md`) erfolgt die Umschaltung.

## DNS-Einträge

Im IONOS Kundenbereich (Domains & SSL) je Subdomain einen A-Eintrag (IPv4) auf `HIER-VPS-IP`
anlegen bzw. ändern. Für den tatsächlich beschafften Hostinger-VPS lautet dieser Wert
`72.61.80.67` (Hostname `srv1960492.hstgr.cloud`):

| Subdomain | Zieltyp | Ziel | Zweck |
|---|---|---|---|
| `app.smart-einzug.de` | A | `HIER-VPS-IP` (Hostinger-VPS: `72.61.80.67`) | Kundenanwendung |
| `admin.smart-einzug.de` | A | `HIER-VPS-IP` (Hostinger-VPS: `72.61.80.67`) | Plattformadministration |
| `api.smart-einzug.de` | A | `HIER-VPS-IP` (Hostinger-VPS: `72.61.80.67`) | Webhooks, Health-Check, Tracking |
| `status.smart-einzug.de` | A | `HIER-VPS-IP` (Hostinger-VPS: `72.61.80.67`) | Öffentliche Statusseite |
| `staging.smart-einzug.de` | A | Adresse des Staging-Servers (empfohlen: eigener, separater VPS, siehe `docs/vps/02-einrichtung-vps.md`, Schritt 25; ein solcher Staging-Server war zum Stand dieses Nachtrags nicht bestellt) | Testumgebung |

Alle übrigen Domains (Marketingseiten, Aliase) bleiben unverändert bei den bisherigen Einträgen
des Webhostings.

## TTL

Mindestens 24 Stunden vor einer geplanten Umstellung (Ersteinrichtung oder ein späterer Cutover
weiterer Firmen, siehe `docs/vps/04-datenbankmigration.md`) die TTL der betroffenen Einträge auf
300 Sekunden senken, damit die Umstellung selbst schnell wirksam wird. Nach abgeschlossener und
bestätigter Umstellung kann die TTL wieder auf einen höheren Wert (z. B. 3600 Sekunden) gesetzt
werden.

## Coolify-Proxy (Traefik) und Let's Encrypt

Auf dem Hostinger-VPS bezieht und erneuert NICHT Caddy die TLS-Zertifikate, sondern der bereits
laufende Coolify-Proxy (Traefik) davor; Caddy hat `auto_https off` gesetzt und spricht nur noch
HTTP auf Port 80 innerhalb des Docker-Netzes (siehe `docs/vps/01-architektur.md`, Abschnitt
„Proxykette“). Traefik bezieht ein Zertifikat automatisch über Let's Encrypt, sobald:

- DNS für den jeweiligen Host auf den VPS zeigt,
- Port 80 und 443 aus dem Internet erreichbar sind (Firewall, siehe
  `docs/vps/02-einrichtung-vps.md`, Schritt 7),
- die Traefik-Labels am `caddy`-Dienst (`deploy/vps/docker-compose.yml`) zu Entrypoint- und
  Certresolver-Namen passen, die Coolifys eigene Proxy-Konfiguration tatsächlich verwendet
  (Standardnamen `http`/`https` und `letsencrypt`, entsprechen der Coolify-Standardkonfiguration;
  auf dem Server gegen `/data/coolify/proxy/docker-compose.yml` zu prüfen).

`LETSENCRYPT_EMAIL` gibt es in `.env` nicht mehr; eine an Let's Encrypt hinterlegte
Benachrichtigungsadresse verwaltet stattdessen Coolify selbst (auf dem Server zu prüfen,
Coolify-Oberfläche oder `/data/coolify/proxy/docker-compose.yml`).

Kein manuelles `certbot`, kein manueller Cron-Job für die Erneuerung nötig; Traefik erneuert
Zertifikate von sich aus rechtzeitig vor Ablauf und ohne Ausfallzeit.

`Caddyfile.staging` verwendet denselben Mechanismus (Traefik-Labels statt eigenem TLS) für
`staging.smart-einzug.de` mit einer eigenen `.env` (nur `DOMAIN_STAGING` und `COOLIFY_NETWORK`
gesetzt).

### HSTS

Der `Strict-Transport-Security`-Header ist in `deploy/vps/Caddyfile` bewusst auskommentiert, bis
`app.`, `admin.` und `api.smart-einzug.de` dauerhaft ausschließlich über gültiges HTTPS erreichbar
sind (siehe `deploy/vps/README.md`, Abschnitt „Offene Punkte“). Eine fehlerhafte HSTS-Einstellung
lässt sich wegen des Browser-Caches nicht kurzfristig zurücknehmen; die Freischaltung erfolgt
daher erst nach ausdrücklicher Bestätigung durch die Geschäftsführung (Eskalationsregel für
haftungsrelevante, schwer rückgängig zu machende Einstellungen).

## Prüfungen

Nach jeder DNS- oder Zertifikatsänderung:

```bash
dig +short app.smart-einzug.de
dig +short admin.smart-einzug.de
dig +short api.smart-einzug.de
dig +short status.smart-einzug.de

curl -vI https://app.smart-einzug.de/health.php 2>&1 | grep -iE "SSL certificate|subject:|expire"
curl -s https://app.smart-einzug.de/health.php | jq .
curl -s -o /dev/null -w '%{http_code}\n' https://api.smart-einzug.de/register.php   # muss 403 liefern (nicht erlaubter Endpunkt)
curl -s -o /dev/null -w '%{http_code}\n' https://status.smart-einzug.de/status.json
```

**Erwartetes Ergebnis:** alle vier `dig`-Aufrufe liefern `HIER-VPS-IP` (bzw. die Staging-Adresse),
das Zertifikat ist gültig und von Let's Encrypt ausgestellt, `health.php` liefert `"php": true`,
`api.smart-einzug.de` lehnt nicht erlaubte Endpunkte mit 403 ab, `status.json` liefert 200.

**Mögliche Fehler:**

| Symptom | Ursache | Behebung |
|---|---|---|
| `dig` liefert noch die alte Adresse | DNS-Propagierung, Resolver-Cache | `dig @1.1.1.1` gegen einen fremden Resolver testen, TTL abwarten |
| Zertifikatsfehler „NET::ERR_CERT_AUTHORITY_INVALID“ | Der Coolify-Proxy (Traefik) konnte kein Zertifikat beziehen (DNS zeigte zum Zeitpunkt des Versuchs noch nicht auf den Server, Port 80/443 blockiert, oder die Traefik-Labels am `caddy`-Dienst passen nicht zu Coolifys Entrypoint-/Certresolver-Namen) | Logs des Coolify-Proxy-Containers ansehen (Name auf dem Server mit `docker ps` ermitteln, üblicherweise `coolify-proxy`), DNS und Firewall prüfen, `deploy/vps/docker-compose.yml` gegen `/data/coolify/proxy/docker-compose.yml` abgleichen |
| `api.smart-einzug.de` liefert andere Endpunkte als 403 | Caddyfile-Regel für `api`-Host nicht wirksam (Konfigurationsfehler, falscher Host in `.env`) | `Caddyfile`, Abschnitt für `{$DOMAIN_API}` prüfen, `docker compose exec caddy caddy validate` |
| „Too Many Requests“ von Let's Encrypt | Wiederholte fehlgeschlagene Zertifikatsanfragen für denselben Host innerhalb kurzer Zeit (Rate-Limit von Let's Encrypt) | Ursache des ursprünglichen Fehlschlags beheben (meist DNS), einige Stunden warten, nicht wiederholt neu versuchen |
