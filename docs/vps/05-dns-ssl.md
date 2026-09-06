# DNS und SSL

Stand: 06.09.2026 (Auftrag III). Betrifft ausschließlich die neuen Anwendungshosts auf dem VPS.
Die Marketingdomains (`smart-einzug.de`, `lexware-einzug.de`, `lexoffice-einzug.de` und die
weiteren in `websites/`) bleiben unverändert auf dem IONOS-Webhosting und sind hier nicht
betroffen.

## DNS-Einträge

Im IONOS Kundenbereich (Domains & SSL) je Subdomain einen A-Eintrag (IPv4) auf `HIER-VPS-IP`
anlegen bzw. ändern:

| Subdomain | Zieltyp | Ziel | Zweck |
|---|---|---|---|
| `app.smart-einzug.de` | A | `HIER-VPS-IP` | Kundenanwendung |
| `admin.smart-einzug.de` | A | `HIER-VPS-IP` | Plattformadministration |
| `api.smart-einzug.de` | A | `HIER-VPS-IP` | Webhooks, Health-Check, Tracking |
| `status.smart-einzug.de` | A | `HIER-VPS-IP` | Öffentliche Statusseite |
| `staging.smart-einzug.de` | A | Adresse des Staging-Servers (empfohlen: eigener, separater VPS, siehe `docs/vps/02-einrichtung-vps.md`, Schritt 25) | Testumgebung |

Alle übrigen Domains (Marketingseiten, Aliase) bleiben unverändert bei den bisherigen Einträgen
des Webhostings.

## TTL

Mindestens 24 Stunden vor einer geplanten Umstellung (Ersteinrichtung oder ein späterer Cutover
weiterer Firmen, siehe `docs/vps/04-datenbankmigration.md`) die TTL der betroffenen Einträge auf
300 Sekunden senken, damit die Umstellung selbst schnell wirksam wird. Nach abgeschlossener und
bestätigter Umstellung kann die TTL wieder auf einen höheren Wert (z. B. 3600 Sekunden) gesetzt
werden.

## Caddy und Let's Encrypt

Caddy (`deploy/vps/Caddyfile`) bezieht und erneuert TLS-Zertifikate automatisch über Let's
Encrypt, sobald:

- DNS für den jeweiligen Host auf den VPS zeigt,
- Port 80 und 443 aus dem Internet erreichbar sind (Firewall, siehe
  `docs/vps/02-einrichtung-vps.md`, Schritt 7),
- `LETSENCRYPT_EMAIL` in der `.env` gesetzt ist (Adresse, an die Let's Encrypt Warnungen zu
  bevorstehenden Ablaufdaten schickt, sofern eine Erneuerung ausnahmsweise fehlschlägt).

Kein manuelles `certbot`, kein manueller Cron-Job für die Erneuerung nötig; Caddy erneuert
Zertifikate von sich aus rechtzeitig vor Ablauf und ohne Ausfallzeit (siehe
`deploy/vps/README.md`, Abschnitt „Warum Caddy statt nginx“).

`Caddyfile.staging` verwendet denselben Mechanismus für `staging.smart-einzug.de` mit einer
eigenen `.env` (nur `DOMAIN_STAGING` gesetzt).

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
| Zertifikatsfehler „NET::ERR_CERT_AUTHORITY_INVALID“ | Caddy konnte kein Zertifikat beziehen (DNS zeigte zum Zeitpunkt des Versuchs noch nicht auf den Server, oder Port 80/443 blockiert) | `docker compose logs caddy`, DNS und Firewall prüfen, danach Caddy neu starten, damit ein neuer Versuch unternommen wird |
| `api.smart-einzug.de` liefert andere Endpunkte als 403 | Caddyfile-Regel für `api`-Host nicht wirksam (Konfigurationsfehler, falscher Host in `.env`) | `Caddyfile`, Abschnitt für `{$DOMAIN_API}` prüfen, `docker compose exec caddy caddy validate` |
| „Too Many Requests“ von Let's Encrypt | Wiederholte fehlgeschlagene Zertifikatsanfragen für denselben Host innerhalb kurzer Zeit (Rate-Limit von Let's Encrypt) | Ursache des ursprünglichen Fehlschlags beheben (meist DNS), einige Stunden warten, nicht wiederholt neu versuchen |
