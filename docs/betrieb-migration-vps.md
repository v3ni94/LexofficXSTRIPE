# Betrieb, Architektur und VPS-Migration (Müller Holding AG)

Interne Betriebs- und Migrationsdokumentation, Dokument-ID MHAG-SE-OPS-20260907, Dokumentversion 1.0, Stand 07.09.2026. Diese Fassung ist inhaltsgleich mit dem Originaldokument als PDF (`docs/anlagen/MHAG-SE-OPS-20260907_Betrieb-und-VPS-Migration_v1.0.pdf`, im Adminbereich unter System, Dokumentation abrufbar); im Zweifel gilt das PDF. Grafiken erscheinen hier als Textdiagramme.

Alle Befehlsblöcke sind Dokumentation für den späteren Gebrauch. Sie wurden bei der Erstellung dieses Dokuments nicht ausgeführt.

## 01. Dokumentauftrag und Leseschlüssel

Betriebsdokumentation aus dem Chat, nicht die Freigabe eines ungeprüften Neuaufbaus.

Auftraggeber und Betreiber: Timo Müller, Müller Holding AG. Gegenstand: das bestehende SmartEinzug-/LexofficXSTRIPE-Projekt, dessen Entwicklung, Umzug von IONOS-Webhosting auf Hostinger, Inbetriebnahme mit Docker/Coolify, Datenbankwechsel und Sicherungskonzept. Dokumentierter Zeitraum: 06. und 07. September 2026.

Die Darstellung konsolidiert den verfügbaren Chatverlauf, die eingebrachten Masterprompts, Entwicklungsberichte und Terminalprotokolle. Wiederholte Logblöcke werden nicht mehrfach abgedruckt. Fremde Projekte aus anderen Gesprächen, etwa eine getrennte Laravel-7.4-Anwendung, werden nicht mit diesem Stack vermischt.

| Kennzeichnung | Bedeutung |
|---|---|
| BELEGT | Direkte Terminal-/Workflow-Ausgabe im Chat. Gilt für den jeweiligen Messzeitpunkt, nicht automatisch für einen späteren Serverzustand. |
| BERICHTET | Von Claude im Abschlussbericht als implementiert oder getestet beschrieben. Der Bericht ist eine Quelle, kein hier erneut durchgeführter Code-Audit. |
| NUTZERBESTÄTIGT | Vom Auftraggeber ausdrücklich mitgeteilt, etwa Löschung der IONOS-Datenbank oder erfolgreicher Restore-Test. |
| VORGESEHEN / OFFEN | Anforderung, vorgesehener Zusammenhang oder noch nicht durch eine entsprechende Ausgabe bestätigte Prüfung. |

Quellenprinzip: Kurzcodes Q01–Q13 führen zum Quellenregister am Dokumentende. Dort stehen Originaldateien, Abschnitte und relevante Zeilen. Technische Zahlen und Pfade sind aus den Quellen übernommen. Widersprüche zwischen früheren Hypothesen und späteren Befunden werden ausdrücklich benannt.

Keine neuen Produktivtests: Für diese Dokumentation wurden weder Server noch Datenbank, DNS, Zahlungsabläufe oder Backups verändert. Die ausdrücklich verschobene fachliche Applikationsabnahme bleibt offen. Die PDF ist kein vollständiger Quellcodeexport und enthält kein rekonstruiertes SQL-Schema.

Vertraulichkeit und Gestaltung: Interne Betriebsunterlage mit Infrastrukturkennungen. Keine Passwörter, privaten SSH-Schlüssel, API-/2FA-Geheimnisse oder Sitzungswerte. Gestaltung nach der vorhandenen MH-AG-Referenz mit Original-Wortbildmarke, Gold/Orange #FCAF16, Grau #A7A9AC und Dunkel #231F20. Die TXT-Fassung enthält denselben Sachinhalt und Textfassungen der Diagramme, aber keine Farben oder eingebetteten Logos.

**Nachweise:** Q01; Q02, Auftrag und Infrastrukturentscheidung; Q03, Einleitung; Q08, Abschlussbericht; Q09, Schlussprotokoll.

## 02. Ergebnis und belastbarer Abschlussstand

Der Umzug funktioniert im dokumentierten Betrieb. Eine fachliche Vollabnahme ist davon getrennt.

| Bereich | Stand am Chatende | Nachweis / Einschränkung |
|---|---|---|
| VPS-Deployment | BELEGT: success | Release c4fabc…; exit_code 0. Start 04:34:16Z, Ende 04:34:40Z, also 24 Sekunden für den serverseitigen Lauf. |
| Produktive Dienste | BELEGT: laufend | PHP, Redis, Scheduler, Worker und Metrics healthy; Caddy running. Keine Aussage über jede Kundenfunktion. |
| HTTPS / Hosts | BELEGT | Vier produktive Hostnamen im SAN-Zertifikat. HTTP-Antworten und Weiterleitungsziele dokumentiert. |
| Anwendungsdatenbank | BELEGT + NUTZERBESTÄTIGT | Konfiguration zeigt neue Coolify-MariaDB; DB-Healthcheck OK; alte IONOS-DB laut Nutzer gelöscht. |
| Backups | TEILWEISE BELEGT | Lokaler Dump vorhanden; aktiver Zeitplan mit S3-Ziel. Heutiges Remote-Objekt nicht unmittelbar gelistet. |
| Wiederherstellung | NUTZERBESTÄTIGT | Restore funktioniert laut Nutzer. Herkunft des Dumps und Testprotokoll nicht beigefügt. |
| Fachliche Abnahme | VERTAGT | Login/2FA, Einzüge, API, Webhooks, E-Mail und Alarmierung werden später systematisch geprüft. |

### Versionsstand nicht mit dem letzten Dateidatum verwechseln

Der letzte vollständig zugeordnete Erfolgsstatus nennt c4fabc081aa73b772d049b33bbd7b3d6d5868bca. Später ist zusätzlich Release 33349cfd99fcd6dbc2ee96448264e7d5fb42640c in einer Dateiliste sichtbar; kurz danach zeigen Appcontainer neue Laufzeiten. Für diesen späteren Stand liegt im Chat kein erneuter vollständiger SHA-/Statusabgleich vor. Er wird deshalb nicht stillschweigend als verifiziertes Endrelease ausgegeben.

Freigegeben dokumentiert: der bisherige Infrastruktur- und Datenbankübergang. Nicht daraus abzuleiten: fehlerfreie Verarbeitung jeder Lastschrift, vollständige Sicherheitsabnahme, durchgängige Offsite-Restorekette oder ein bereits produktiv isoliertes Staging.

**Nachweise:** Q07, Z. 899–924; Q08, Abschnitte 1–13; Q09, Z. 41–56, 198–245, 318–342 und 824–837; Q01, Nutzerbestätigungen.

## 03. Gesamtarchitektur

Drei Anbieter mit unterschiedlichen Aufgaben: IONOS, Hostinger und Hetzner.

```
GitHub --SFTP--> IONOS-Marketing
GitHub --SSH/rsync--> Hostinger-VPS
Browser --HTTPS--> Coolify-Traefik --HTTP--> Caddy --FastCGI--> PHP
PHP / Worker --SQL--> Coolify-MariaDB --Dump--> lokales Coolify-Backup
PHP / Worker --intern--> eigener SmartEinzug-Redis
Coolify-Backup - - S3 konfiguriert - -> Hetzner Object Storage
IONOS bleibt für DNS/Domains, Mail und Marketing bestehen.
```

**Abbildung 1.** Konsolidierte Verbindungsübersicht. Durchgezogene Verbindungen beschreiben den dokumentierten Aufbau; der gestrichelte Backupweg ist konfiguriert, das konkrete Remote-Objekt wurde im Chat nicht ausgelesen.

Hostinger ist der VPS-Hoster. Hetzner stellt in diesem Aufbau das externe Object Storage bereit, nicht den produktiven VPS. IONOS bleibt für die vorgesehenen Marketingseiten, Domain-/DNS-Verwaltung und SMTP bestehen. Die alte Anwendungsdatenbank bei IONOS wurde laut Auftraggeber gelöscht.

GitHub ist Codequelle und Auslöser des kontrollierten Deployments. Coolify verwaltet Infrastruktur, Proxy und Datenbank-/Backupressourcen; die SmartEinzug-App wird über den eigenen GitHub-SSH-Workflow bereitgestellt. Ein zusätzlicher eigenständiger Coolify-App-Autodeploy ist nicht als aktiver Pfad belegt.

**Nachweise:** Q02, Infrastrukturentscheidung; Q04, Rolle des Proxys; Q07, Netzwerk- und Containerlogs; Q09, Datenbank, S3 und Zeitplan.

## 04. Anbieter, Server und Verantwortungsgrenzen

Inventar der im Verlauf tatsächlich genannten und beobachteten Infrastruktur.

| Komponente | Dokumentierter Wert | Einordnung |
|---|---|---|
| Hostinger VPS | KVM 8; srv1960492 | Tarifkauf im Auftrag bestätigt. 8 vCPU / 32 GB / 400 GB NVMe als damalige Tarifangaben, kein erneuter Hardwareaudit. |
| Öffentliche IPv4 | 72.61.80.67 | Im Loginbanner, DNS und SSH-Verlauf belegt. |
| Öffentliche IPv6 | 2a02:4780:41:765c::1 | Im Loginbanner belegt; lokale IPv6-Porttests vorhanden. |
| Betriebssystem | Ubuntu 24.04.4 LTS | Kernel 6.8.0-139-generic x86_64 laut Terminal. |
| Docker / Compose | Engine 29.8.0 / Compose v5.5.1 | Beobachteter Versionsstand im nächtlichen Diagnoseprotokoll. |
| Coolify | coollabsio/coolify:4.3.17 | Verwaltungscontainer vorhanden; keine Produktversionsrecherche für dieses Dokument. |
| TLS-Proxy | coolify-proxy / traefik:v3.6 | Öffentlicher Einstieg für die SmartEinzug-Hosts. |
| S3-Ziel | Bucket smarteinzug; Storage-ID 1 | Endpoint fsn1.your-objectstorage.com, im Chat als Hetzner-Ziel eingerichtet. |
| IONOS | DNS, Marketing-Webhosting, SMTP | Bestehende Leistungen nicht pauschal mit der alten DB entfernen. |

Im Loginbanner waren rund 386,42 GB Dateisystemkapazität und zeitweise 1,6–2,2 % Belegung sichtbar. Momentaufnahmen wie 3 % RAM-Verbrauch und geringer Load sind keine Kapazitätsplanung und kein Lasttest. Maximale Kundenzahl oder Transaktionsleistung lassen sich daraus nicht seriös ableiten.

Betriebsgrenze: Root/SSH verwaltet den Host. Der Anwendungsbetrieb soll keine Root- oder Docker-Socket-Rechte benötigen. Der Chat enthält Einrichtungsschritte und Beobachtungen, aber keinen vollständigen abschließenden Firewall-, Rechte- oder Sicherheits-Audit.

**Nachweise:** Q02, Infrastrukturentscheidung; Q04, README; Q05, Einrichtung; Q07, Docker-Versionen; Q09, Loginbanner und Containerinventar.

## 05. Containerinventar der Produktion

Anwendungscontainer und Coolify-eigene Dienste müssen auseinandergehalten werden.

| Container / Dienst | Aufgabe im dokumentierten Aufbau | Beobachteter Zustand |
|---|---|---|
| smarteinzug-caddy-1 | Interner HTTP-Server vor PHP, Host-Routing | running / Up, kein eigener Healthcheck in gezeigter Compose-Ausgabe |
| smarteinzug-php-1 | PHP-FPM für App und Admin | healthy; DB-Healthcheck OK |
| smarteinzug-scheduler-1 | Zeitgesteuerte Aufgaben einreihen | healthy; Intervall 30 s im Startlog |
| smarteinzug-worker-lexware-1-1; smarteinzug-worker-lexware-2-1 | Zwei Lexware-Worker in Produktion | healthy |
| smarteinzug-worker-stripe-1 | Stripe-/Zahlungsverarbeitung | healthy; Fachabläufe nicht abschließend abgenommen |
| smarteinzug-worker-mail-1 | Mailverarbeitung | healthy; Zustellung an Empfänger separat |
| smarteinzug-worker-maintenance-1 | Wartungsaufgaben | healthy |
| smarteinzug-metrics-1 | Host-/Anwendungsmetriken | healthy; eigenes Mess-/Healthmodell |
| smarteinzug-redis-1 | SmartEinzug-interner Redis | healthy; nicht mit coolify-redis verwechseln |
| fywft1vc4rr5uyy3mw7lgy4s | Produktive MariaDB, Image mariadb:11.8.9 | Laufender Coolify-Datenbankcontainer |

Zusätzliche Coolify-Dienste: coolify, coolify-db (PostgreSQL 15), coolify-redis (Redis 7), coolify-realtime und coolify-sentinel. Die PostgreSQL-Datenbank coolify-db ist die Verwaltungskomponente von Coolify, nicht die SmartEinzug-MariaDB.

Der PHP-Build verwendet im protokollierten Dockerfile php:8.4-fpm-alpine mit pdo_mysql, gd, intl, opcache, zip, bcmath, pcntl und phpredis. Der Logeintrag zum Dockerfile-WORKDIR releases/current ist von dem später durch Compose überschriebenen tatsächlichen working_dir zu unterscheiden.

**Nachweise:** Q07, Containerstände und HostConfig; Q09, Z. 275–285, 290–328 und 632–638; Q08, Signalmodell.

## 06. Domains, DNS und verbliebene IONOS-Dienste

Der Umzug betrifft die App-Laufzeit und die Datenbank, nicht automatisch alle Domains und Postfächer.

| Hostname | Rolle / Zuordnung | Nachweisstand |
|---|---|---|
| smart-einzug.de | Hauptwebsite und Produktmarke; IONOS-Marketingpfad vorgesehen | Kein neuer Abschluss-DNS-Test der Hauptdomain im Chat. |
| lexoffice-einzug.de; lexware-einzug.de | Thematische Nebenwebsites; eigener Marketinginhalt | Weiterbestehen vereinbart; nicht gemeinsam mit der App-DB löschen. |
| app.smart-einzug.de | Kundenanwendung auf dem VPS | A-Abfrage 72.61.80.67; HTTPS und 302 belegt. |
| admin.smart-einzug.de | Zentraler Administrationsbereich | A-Abfrage 72.61.80.67; HTTPS und 302 belegt. |
| api.smart-einzug.de | API-Host | A-Abfrage 72.61.80.67; / liefert 404; reale Route offen. |
| status.smart-einzug.de | Öffentliche Statusdarstellung | A-Abfrage 72.61.80.67; HTTP 200. Unabhängigkeit vom VPS nicht belegt. |
| staging.smart-einzug.de | Vorgesehene Testumgebung | DNS zeitweise auf denselben VPS; separater produktiver Stagingbetrieb nicht bestätigt. |
| coolify.smart-einzug.de | Verwaltungsoberfläche | In Coolify-Zugriffslogs belegt; nicht Bestandteil der vier geprüften SAN-Hosts. |

Die DNS-Umstellung zeigte zwischenzeitlich gemischte IPv4-Antworten: 72.61.80.67 und 217.160.0.203. Im früheren IONOS-DNS-Auszug erscheinen für einzelne Webhosting-Namen auch 217.160.0.219 und die alte IPv6 2001:8d8:100f:f000::200. Diese Werte sind historisch, keine heutige Zielvorgabe.

Die letzten im DNS-Teil gezeigten AAAA-Antworten waren nicht für alle Hosts identisch vollständig. Admin, API und Status zeigten die Hostinger-IPv6; bei App und Staging war in einem späteren Snapshot noch keine AAAA-Antwort vorhanden. Erfolgreiches HTTPS bestätigt nicht rückwirkend jede IPv4-/IPv6-Route von jedem externen Netz.

**Nachweise:** Q01, Domain-/Deploymententscheidungen; Q02, Infrastrukturaufteilung; Q10, DNS-Auszug; Q09, Z. 58–126 und 200–215.

## 07. HTTPS, Zertifikat und Request-Weg

TLS endet im dokumentierten Zielaufbau am Coolify-Proxy, nicht am internen Caddy.

```
Browser -> DNS IONOS -> 72.61.80.67:443
 -> Traefik (TLS, SAN-Zertifikat)
 -> Caddy (intern HTTP:80) -> PHP-FPM
app / -> login.php (302); admin / -> /admin.php (302)
api / -> 404; status / -> 200. Kein fachlicher End-to-End-Nachweis.
```

**Abbildung 2.** Dokumentierter Request-Weg. Das Diagramm ist eine technische Rekonstruktion aus Labels, README und Antworten, kein neuer Netzwerktest.

Zertifikatsnachweis: Aussteller Let’s Encrypt, CN YR2; Gültigkeit 06.09.2026 23:32:35 GMT bis 05.12.2026 23:32:34 GMT. Der Subject-CN lautet app.smart-einzug.de; die SAN-Liste enthält app, admin, api und status. TRAEFIK DEFAULT CERT wurde in der Abschlussprüfung nicht mehr ausgegeben.

Die geprüften Weiterleitungen sind relativ und bleiben beim jeweiligen Host: login.php auf App und /admin.php auf Admin. Die API-Rootantwort 404 wird nicht als bestätigter API-Fehler oder als garantierter Sollzustand umgedeutet; dafür fehlt der Test eines vorgesehenen API-Endpunkts.

**Nachweise:** Q04, Rolle des Proxys; Q01, Traefik-Labels und ACME-Fehler; Q09, Z. 58–167.

## 08. Docker-Netze und Redis-Verwechslung

Ein identischer Kurzname kann im geteilten Netzwerk den falschen Dienst bezeichnen.

```
Netz coolify: Traefik, Caddy, PHP/Worker/Metrics (laut Log),
  Coolify-MariaDB fywft1vc4rr5uyy3mw7lgy4s (172.16.1.3), Coolify-Redis
Netz smarteinzug_smarteinzug_internal:
  Caddy/PHP/Worker <-> SmartEinzug-Redis (Snapshot 172.28.0.5)
PHP/Worker sind teilweise an beiden Netzen. Eindeutiger Redis-Alias
verhindert die Verwechslung mit Coolify-Redis; Aliastext nicht belegt.
```

**Abbildung 3.** Netzwerkzuordnung aus den Protokollen. IP-Adressen sind Snapshots; maßgeblich bleiben die verifizierten Service-/Aliasnamen. Der endgültige neue Redis-Alias wurde im vorliegenden Chat nicht ausgeschrieben.

Die Laufzeitlogs zeigen PHP-Testcontainer, mehrere Worker, Metrics und Caddy sowohl im coolify-Netz als auch im internen SmartEinzug-Netz. Deshalb wäre die vereinfachte Behauptung „nur Caddy ist im Coolify-Netz“ falsch. Die genaue aktuelle Mitgliedschaft sämtlicher Dienste muss bei späteren Änderungen erneut ausgelesen werden.

Die spätere Korrektur zu Run #44 benannte eine Alias-Kollision mit Coolifys eigenem Redis. Danach war der Cross-Container-Test OK. Der vorangegangene protected-mode-Fix allein hatte den Ablauf noch nicht geheilt. Beide Fehlerstränge werden in der Historie getrennt dokumentiert.

**Nachweise:** Q07, Netzwerklogs Z. 693–761 und frühere Redis-Diagnose; Q01, Run #44 und Redis-Abschlussberichte; Q09, DB-Inspect.

## 09. Ports und Verbindungsregister

Nicht jeder Containerport ist ein öffentlich freigegebener Hostport.

| Quelle → Ziel | Port / Weg | Status / Zweck |
|---|---|---|
| Browser → Traefik | HTTPS 443; HTTP 80 zur Einleitung/Umleitung | Vier produktive Hosts mit HTTPS-Antworten belegt. |
| Traefik → Caddy | HTTP 80 im Docker-Netz | Loadbalancer-Label nennt Port 80; Caddy ohne eigene Hostport-Veröffentlichung vorgesehen. |
| Caddy → PHP-FPM | FastCGI; PHP-Image zeigt 9000/tcp | Web-/PHP-Pfad in Architektur dokumentiert; keine öffentliche FPM-Freigabe belegt. |
| App / Worker → MariaDB | 3306; Host fywft1vc4rr5uyy3mw7lgy4s | Konfiguration und lokaler DB-Container belegt. Vollständige PortBindings nicht im Abschluss ausgelesen. |
| App / Worker → SmartEinzug-Redis | 6379 intern | Netztest nach Alias-Korrektur OK; eigener Redis bleibt getrennt. |
| GitHub → VPS | SSH / rsync | VPS_SSH_PORT im Workflow maskiert; 22/tcp im historischen UFW-Snapshot offen. |
| GitHub → IONOS | SFTP | Getrennter Website-Upload; Zielwerte als Secrets, nicht in diesem Dokument. |
| App-Mailer → IONOS | smtp.ionos.de:587 | Aktive SMTP-Konfiguration belegt; Zustelltest vertagt. |
| Coolify → Hetzner | S3-kompatibles HTTPS | Endpoint und save_s3=yes belegt; konkrete Objektprüfung nicht gezeigt. |
| Worker → Lexware / Stripe | API über HTTPS, fachlich vorgesehen | Tatsächliche Authentifizierung, Webhook-URLs und Prozessabnahme separat. |

Keine neue Freigabe aus dieser Tabelle ableiten. Insbesondere 3306, 6379, 9000 und Verwaltungsports nicht pauschal ins Internet öffnen. Die Tabelle beschreibt den Chatstand, nicht eine ausgerollte Firewallregeldatei.

**Nachweise:** Q04, Proxyarchitektur; Q05, UFW-Snapshot; Q07, Container- und Netzwerkausgaben; Q09, DB/SMTP-Konfiguration; Q01, SSH-Workflow.

## 10. GitHub und zweigleisiges Deployment

Der Quellcode wird pro Ziel kontrolliert bereitgestellt.

```
GitHub Repository -> Änderungen + Tests
  |-> deploy-webhosting --SFTP--> IONOS-Marketing
  |     WEBHOSTING_APP_DEPLOY=false
  |-> deploy-vps --SSH/rsync--> Hostinger-Release
        VPS_DEPLOY_ENABLED=true
        -> deploy-runner (setsid + Lock) -> deploy.sh
        -> Status-JSON <- GitHub-Polling (kurze SSH-Abfragen)
GitHub prüft Endstatus und SHA; Beobachtung und Serverprozess sind getrennt.
```

**Abbildung 4.** Website-Deployment und VPS-Deployment sind unterschiedliche Zweige. Der serverseitige Deploy bleibt bei Verlust einer einzelnen SSH-Verbindung unabhängig.

Repository: v3ni94/LexofficXSTRIPE. Genannter Arbeitsbranch: claude/setup-lexsepa-monorepo-v5ZcZ. Workflowdatei: .github/workflows/deploy.yml. Der Analysebericht zu Commit 650da91 nennt für deploy-vps nur die Abhängigkeiten changes und test, nicht deploy-webhosting.

Die Berichte nennen getrennte Nebenläufigkeitsgruppen production-sftp und production-vps. Der eigene VPS-Runner hält darüber hinaus eine serverseitige Sperre. Reine GitHub-Farbzustände und ein begonnener Trigger werden nicht mit einem erfolgreich aktivierten Release gleichgesetzt.

**Nachweise:** Q01, Workflowanalyse, Variablen und Fehler #33–#44; Q04, regulärer Deploymentweg; Q08, Status-/Polling-Fix.

## 11. Variablen, Secrets und SSH-Identität

Ein Variablenname ist Teil des Vertrags zwischen Workflow und Repository-Einstellungen.

| Name | Ablage / Wert im Verlauf | Bedeutung |
|---|---|---|
| WEBHOSTING_APP_DEPLOY | Repository-Variable: false | App-Upload und HTTP-Migration auf Webhosting aus; Marketing bleibt. |
| VPS_DEPLOY_ENABLED | Repository-Variable: true | VPS-Job grundsätzlich aktiviert. |
| WEBHOSTING_MIGRATE_URL | Repository-Variable; nur alter App-Pfad | Nach 650da91 keine harte App-Domain mehr. Bei ausgeschaltetem Webhosting-Appdeploy nicht erforderlich. |
| VPS_HOST | Secret; Hostinger-Ziel im Betrieb 72.61.80.67 | Ziel für SSH; Secret selbst wird im Workflow maskiert. |
| VPS_SSH_USER / VPS_SSH_PORT | Secrets; zuvor fehlten beide | Nötig für Verbindung. Der deploy-Benutzer läuft in den Serverprozesslogs. |
| VPS_SSH_PRIVATE_KEY | Secret, privater Deployschlüssel | Kein öffentlicher Schlüssel und kein Fingerprint. |
| VPS_SSH_KNOWN_HOSTS | Secret, geprüfte Hostkey-Zeile | Hostidentität, etwa Host + ssh-ed25519 + vollständiger öffentlicher Hostkey. |
| VPS_DEPLOY_PATH | Workflow-Umgebung: /opt/smarteinzug | Wurzel für Releases, Laufzeit und Logs; genaue Repository-Ablage nicht gesondert bestätigt. |
| SFTP_HOST / USER / PASSWORD; SFTP_PORT / PATH | Secrets des IONOS-Zweigs | Nicht mit VPS-Secrets überschreiben oder pauschal löschen. |
| MIGRATION_TOKEN | Secret für historischen HTTP-Kanal | Zweckgebunden; kein zusätzliches Auslösen neben der internen VPS-Migration. |

Der Fehler vars.WEBHOSTING_APP_DEPLOY => null entstand, als die Einstellung nicht als lesbare Repository-Variable vorlag. null != false ließ den Migrationsschritt weiterlaufen. Die späteren fehlenden USER-/PORT-Secrets brachen vor dem tatsächlichen Deploy ab.

Bei known_hosts ist eine Zeile wie „SHA256:… (ED25519)“ nicht dasselbe wie der benötigte Hostkeyeintrag. Die Prüfung blieb streng: StrictHostKeyChecking=yes. Keepalive im Workflow: ServerAliveInterval=30, ServerAliveCountMax=10, TCPKeepAlive=yes; ConnectTimeout=15.

**Nachweise:** Q01, Variablenkorrektur und SSH-Fehler; Q08, Runner/Polling; Q09, serverseitige Domain- und Konfigurationswerte.

## 12. Dateibaum und persistente Daten

Release-Code ist austauschbar. Daten, Konfiguration und Deploystatus dürfen nicht versehentlich verschwinden.

```
/opt/smarteinzug/
  releases/
    <git-sha>/              Anwendungscode + deploy/vps/
    current -> <git-sha>    aktive Release-Buchführung
  shared/
    config.php             aktive geheime Laufzeitkonfiguration
    storage/               persistente Anwendungsablage
    sessions/              PHP-Sitzungsdateien
  deploy/
    .env                   Compose-/Umgebungseinstellungen
    .release.env           Release-Zuordnung
    .release_history       Releasehistorie
    .deploy.lock           Sperre des Deployablaufs
    .deploy-status.json    Status des Runners
    .deploy.pid            Runner-PID-Buchführung
    .php-image.sha256      Build-/Änderungsbuchführung
    docker-compose*.yml    Basis + produktiver / Staging-Override
    scripts/               Deploy, Status, Rollback, Einrichtung
    redis/redis.conf       Redis-Laufzeitkonfiguration
    backup/backup.sh       erhaltene Ausweichlösung, nicht aktiv
  logs/                    datierte Deploy-Runner-Protokolle
  backups/                 manuelle Migrationssicherungen
```

Beobachtete Mounts von PHP: releases read-only, shared/config.php read-only, shared/storage read-write sowie shared/sessions nach /var/lib/php/sessions read-write. Die neue MariaDB liegt in einem eigenen Docker-Volume und nicht in einem Releaseordner.

deploy/vps wird aus dem Release in den aktiven Deployordner synchronisiert. Genau dieser Vorgang löschte früher mit rsync --delete versehentlich Status- und PID-Datei. Laut Abschlussbericht wurden die nötigen Ausschlüsse in Deploy und Rollback ergänzt.

Nicht als Backup behandeln: ein Docker-Volume, das nur auf demselben VPS liegt. Nicht löschen: shared/config.php, persistente Nutzdaten, Datenvolume oder Sperr-/Statusdateien eines laufenden Deployments. Alte Releases werden ausschließlich über den vorgesehenen Ablauf bereinigt.

**Nachweise:** Q07, HostConfig und Releaseverzeichnisse; Q08, rsync-Ursache; Q09, Config- und Volume-Nachweise.

## 13. Ablauf eines regulären VPS-Deploys

Ein vollständiger Release muss vor Migration und Aktivierung eindeutig zugeordnet sein.

```
Release + Lock -> Infrastrukturprüfung -> isolierter Candidate
 -> einmalige Migration -> Container-Cutover -> Healthchecks
 -> laufende Release-Bindung prüfen -> current -> Abschluss + success
Fehler vor Cutover: keine Freigabe neuer Appcontainer.
Fehler nach Cutover: nur kompatibler Code-Rollback, keine blinde DB-Rücksetzung.
```

**Abbildung 5.** Nachweis- und Reihenfolgelogik nach den im Chat beschriebenen Korrekturen. Einzelne Schritte sind Codebericht, die erfolgreichen Durchläufe zusätzlich in Terminalprotokollen belegt.

Vor dem Candidate können erforderliche Infrastrukturänderungen vorgenommen werden, insbesondere ein kontrollierter Redis-Recreate. Das ist keine Behauptung, dass ein fehlgeschlagener Candidate grundsätzlich keinerlei Serveränderung hinterlässt. Die laufenden Appcontainer bleiben vor dem Cutover unberührt; notwendige Redis-Rücknahme wird gesondert bewertet.

Die Release-Bindung aller PHP-basierten Container wird laut finalem Bericht vor dem Setzen von current geprüft. Der zweite pauschale Worker-Restart entfällt. Bei Fehlern darf eine bereits veränderte Datenbank nicht ohne Prüfung auf einen alten Inhalt zurückgesetzt werden.

**Nachweise:** Q01, Berichte 8898a54, 6c4a1ed und aadefb80; Q08, insbesondere Abschnitte 1, 6, 7, 9 und 10; Q02, Abschnitt 0.

## 14. Deploystatus, Lock und Abschlussnachweis

Der frühere Statusverlust war ein Dateisynchronisationsfehler, nicht das Ausbleiben eines Serverprozesses.

### Bestätigte Ursache

Laut Abschlussbericht schrieb der Runner zunächst running. Anschließend übernahm deploy.sh beziehungsweise rollback.sh den Deployordner per rsync -a --delete. Weil .deploy-status.json und .deploy.pid nicht ausgeschlossen waren, wurden sie gelöscht. Während der eigentliche Prozess weiterlief, meldete der Leser phase=unknown. Nach Ende schrieb der Runner erneut einen Endstatus.

Zwei weitere Berichtsbefunde betrafen mehrzeiliges JSON und die Logsuche bei --tail sowie die irrtümliche Annahme einer noch vorhandenen Statusdatei des Vorlaufs. Diese Fälle wurden im Runner-/Pollingtest aufgenommen. Status und Prozessexistenz sind deshalb zwei unterschiedliche Diagnosequellen.

### Belegter erfolgreicher Endstatus

```
```

{ "phase": "success", "sha": "c4fabc081aa73b772d049b33bbd7b3d6d5868bca", "pid": 995056, "started_at": "2026-09-07T04:34:16Z", "updated_at": "2026-09-07T04:34:40Z", "exit_code": 0, "message": "Deployment abgeschlossen" }

Die PID in einer Abschlussdatei ist eine historische Prozesskennung. Sie beweist nicht, dass der Prozess weiterhin läuft. Erfolgsstatus, aktiver Symlink, reale Container-Bindung und Gesundheit gehören zusammen; ein Endstatus eines anderen SHA darf einen neuen Lauf nicht freigeben.

Bei GitHub-Timeout: nicht blind erneut auslösen. Erst serverseitigen Status und Runner-Log zum betroffenen SHA lesen. Im dokumentierten Störfall arbeitete der Server nach dem roten GitHub-Lauf erfolgreich weiter.

Der beschriebene normale Pollingrahmen beträgt 12 Minuten. Zwischen Abfragen lag im gezeigten Workflow ein sleep von zehn Sekunden; einzelne SSH-Verbindungsfehler wurden toleriert. Die tatsächliche Gesamtdauer einer Schleife hängt auch von der Dauer ihrer SSH-Aufrufe ab. Der später beobachtete 24-Sekunden-Lauf ist ein Einzelfall, keine garantierte Deployzeit.

**Nachweise:** Q08, Abschnitte 1, 9 und 10; Q09, Z. 52–56; Q07, laufende Runner bei fehlendem Status.

## 15. Hintergrundverarbeitung und Aufgaben

Scheduler, Warteschlange und Worker erfüllen unterschiedliche Aufgaben.

```
Nutzer/Webhooks/Scheduler -> dauerhafte Aufgaben -> Worker-Pools
Worker <-> MariaDB (Fortschritt, Journal, Sperren, Versuche)
Worker <-> eigener Redis (technische Unterstützung)
Worker -> Lexware API / Stripe API / IONOS SMTP
Genannte Live-Jobs: collections_due, alerts, maintenance, mandate_reminders.
Konzeptioneller Datenfluss; kein aus SQL abgeleitetes physisches Datenmodell.
```

**Abbildung 6.** Fachlicher Verarbeitungszusammenhang aus Auftrag und Entwicklungsbericht. Die Grafik bildet kein verifiziertes physisches Datenbankschema ab.

Im konkreten Schedulerlog vom 07.09.2026 stehen ein Start mit 30-Sekunden-Intervall und anschließend die eingereihten Typen collections_due, alerts, maintenance und mandate_reminders. Das belegt Aktivität und Einreihung, nicht den fachlich erfolgreichen Abschluss aller dieser Aufgaben.

Für Synchronisierung wurden pro Firma und API-Verbindung atomare Sperren, begrenzte Pakete, persistenter Fortschritt, Backoff und gemeinsame API-Begrenzung verlangt. Ein Benutzerklick, ein Schedulerlauf und ein Webhook dürfen denselben Auftrag nicht unkoordiniert vervielfachen. Ein reiner Datenabgleich soll keine zusätzliche Lastschrift auslösen.

**Nachweise:** Q02, Abschnitte 1 und 7; Q03, Synchronisierung; Q08, Job-/Signalmodell; Q09, Z. 318–331.

## 16. Worker-Shutdown und Schutz laufender Jobs

Signalbehandlung wurde nicht nur beschleunigt, sondern mit der Jobsemantik verbunden.

```
Vorher: SIGQUIT ungehandhabt -> 660s -> Force-Kill
           + zweiter Restart -t 660 -> nochmals lange Wartezeit
Jetzt laut Bericht: SIGTERM + Handler INT/QUIT -> kein neuer Job
 -> kooperativer Ausstieg -> Worker 75s / Metrics 20s
30s-Notbremse nur für unterbrechbare Typen; Geldfluss und Mail gesondert.
```

**Abbildung 7.** Vorher-/Nachher-Signalmodell laut Abschlussbericht. Grace Periods sind Konfigurationsgrenzen; sie sind keine Zusage, dass externe Zahlungsfolgen dadurch allein sicher sind.

Der finale Bericht nennt direkte PHP-Prozesse als PID 1 in Listenform statt eines sh -c-Wrappers. Handler für SIGTERM, SIGINT und SIGQUIT werden vor dem ersten Datenbankzugriff installiert. Nach dem Signal soll kein neuer Job begonnen werden. Fortschritt wird an vorgesehenen kooperativen Punkten gespeichert beziehungsweise der Auftrag zur Fortsetzung freigegeben.

Die 30-Sekunden-Notbremse gilt ausdrücklich nicht pauschal für collections_due, unclear_attempts oder mail. Für hart beendete Jobs beschreibt Claude Reservierung bis zur jeweiligen heartbeat_ttl (120–1800 Sekunden je Typ) und anschließende Behandlung durch queue_release_stale(). Der vollständige Nachweis zu Doppelbelastung, Zustellung und Wiederaufnahme bleibt Teil der vertagten Fachtests.

**Nachweise:** Q08, Abschnitte 2, 4–8; Q07, Docker-Daemonlog und restart -t 660.

## 17. Migrationen: ein System, ein bewusster Aufruf

Erst die neue Codebasis prüfen, dann einmalig migrieren, danach die Laufzeit freigeben.

Der Auftrag verlangte ausdrücklich die Weiterverwendung des bestehenden Migrationssystems. Weder Weboberfläche, Login, Healthcheck, Containerstart noch Monitoring sollen Migrationen als Nebenwirkung auslösen. Fehlerhafte oder unklar abgebrochene Migrationen dürfen nicht allein wegen eines späteren Deploymentstarts blind wiederholt werden.

| Phase / Kanal | Dokumentierter Zweck | Abgrenzung |
|---|---|---|
| Historisches Webhosting | POST auf migrate.php, eigener Header X-Migration-Token | Anfangs fest an app.smart-einzug.de gebunden; beim DNS-Umzug problematisch. |
| Absicherung 650da91 | WEBHOSTING_MIGRATE_URL und zweifache URL-Prüfung | Kein HTTP statt HTTPS, keine unerwünschten Hosts, Parameter oder Zugangsdaten in URL. |
| Zielbetrieb VPS | Interner CLI-Aufruf im isolierten Candidate-Kontext | Keine zusätzliche öffentliche HTTP-Migration durch den Website-Upload. |
| Deploy-Lock | Ein zuständiger Aktivierungsvorgang pro Ziel | Nicht dasselbe wie eine fachliche Sync-Sperre pro Firma. |
| Migrationssperre | Gemeinsamer Schutz für Migrationen derselben DB | Ausgangsvorgabe: alle Aufrufer nutzen denselben Runner und Schutz. |
| Code-Rollback | Rückkehr zu kompatiblem vorherigem Release | Keine pauschale Rückwärtsmigration und kein Restore über neue Zahlungen hinweg. |

Im erfolgreichen nächtlichen Lauf aadefb80… meldete der isolierte Migrationsschritt: 0 eingespielt, 0 offen. Das bedeutet, dass dort keine weitere Migration anstand. Es bedeutet nicht, dass die Datenbank leer war oder seit der Erstanlage niemals migriert wurde.

Historisch genannt: Migration 018 für Hintergrundverarbeitung und Migration 019 im Zusammenhang mit Tarifwechsel/Upsell. Der Bericht zu 650da91 erklärte, Versionen 4.2–4.4 hätten keine neuen Datenbankänderungen. Diese Aussage ist historisch und kein vollständiges Migrationsinventar des späteren Projekts.

Offener Umfang: Der Chat enthält keine vollständige geordnete Liste sämtlicher SQL-Migrationsdateien und keine komplette Ausgabe der Migrationstabelle. Diese Unterlage erfindet deshalb weder weitere Versionsnummern noch ausgeführte DDL-Befehle.

**Nachweise:** Q02, Abschnitt 0; Q01, Berichte 650da91 und 8898a54; Q07, Z. 322–335; Q09, abgeschlossene Laufzeit.

## 18. Datenbankwechsel und Nachweiskette

Die beobachtete Konfiguration, der lokale Container und die Nutzerbestätigung ergeben den dokumentierten Wechsel.

| Prüfung | Beobachtung | Was damit belegt ist |
|---|---|---|
| Aktive config.php | Host fywft1vc4rr5uyy3mw7lgy4s; Port 3306; Name smarteinzug; User mariadb | Anwendungs-Konfigurationsziel zeigt auf den lokalen Coolify-Servicenamen. |
| Docker-Inventar | Container fywft1vc4rr5uyy3mw7lgy4s; mariadb:11.8.9; IP 172.16.1.3 | Der bezeichnete MariaDB-Container existiert auf dem Host. |
| PHP-Healthcheck | bin/healthcheck.php --db liefert mehrfach OK | Die im jeweiligen PHP-Prüfkontext verwendete DB-Verbindung funktioniert. |
| Alte IONOS-DB | Vom Nutzer als gelöscht bestätigt | Die frühere Ressource ist laut Auftraggeber nicht mehr verfügbar. |
| Bereinigung | Alte Configs entfernt; aktive config.php erhalten | Keine Löschung der laufenden VPS-Konfiguration. |
| Suche alter Host | Kein Treffer in shared, deploy und releases/current angezeigt | Kein sichtbarer Treffer im durchsuchten Bereich; keine globale Fremdsystemprüfung. |

### Warum einige Diagnosebefehle scheiterten

Direktes require der geschützten config.php lieferte Forbidden. Der vorgeschlagene Pfad bin/bootstrap.php existierte nicht und erzeugte einen Fatal Error. Diese Diagnosefehler waren kein Nachweis eines Datenbankausfalls. Sie dürfen nicht als funktionierende Standardbefehle übernommen werden.

Der direkte MariaDB-CLI-Versuch meldete using password: NO. Die geplante Identitätsabfrage mit DATABASE(), @@hostname, @@port, @@version und @@server_id wurde damit nicht erfolgreich ausgeführt. Ein vollständiger SQL-Fingerprint aus der realen App-Verbindung liegt im Chat nicht vor.

Präzises Fazit: Der neue Anwendungs-DB-Pfad ist stark belegt und funktioniert im dokumentierten Betrieb. Eine endgültige Vollständigkeitsprüfung aller Fremdprogramme, alten IONOS-Cronjobs, PHP-FPM-Kontexte und Datenbestände ist nicht automatisch in diesen Healthchecks enthalten.

**Nachweise:** Q09, Z. 182–245, 246–254 und 824–837; Q01, Nutzer: IONOS-Datenbank gelöscht.

## 19. Datenmodell: bekannte Zusammenhänge

Die Datenobjekte sind fachlich dokumentiert; exakte Tabellen und Schlüssel bleiben nur dort benannt, wo die Quellen sie nennen.

```
Konzeptionell, keine Tabellen-/Fremdschlüsselbehauptung:
Benutzer -> Firmenzuordnung/Rolle -> Firma
Benutzer -> Gerätefreigabe (App/Admin getrennt)
Firma -> API-Verbindungen -> Rechnung/Einzug/Journal
Auftrag -> einzelne Ausführungsversuche/Heartbeat/Fortschritt
Genannter Tabellenname: job_runs. Vollständiges DDL fehlt.
```

**Abbildung 8.** Konzeptionelle Zuordnung, ausdrücklich kein ER-Diagramm der produktiven Datenbank. Pfeile beschreiben fachliche Beziehungen, nicht nachgewiesene Fremdschlüssel oder Kardinalitäten.

Gesichert genannt werden Benutzeridentität, Firmenzuordnungen und Rollen, Firmen-/API-Daten, Rechnungen und Einzüge, externe IDs, Idempotenzschlüssel, Jobs und einzelne Ausführungsversuche, Heartbeats, Migrationen, Monitoringdaten und Gerätefreigaben. Der Abschlussbericht nennt konkret job_runs; daraus folgt kein vollständiges Tabellenverzeichnis.

Für ein physisches Datenbankhandbuch wären ein geprüftes Schema ohne Kundendaten, Spalten/Datentypen, Primär- und Fremdschlüssel, Indizes, Constraints und die tatsächlich aktive Migrationstabelle nötig. Solche Inhalte sind nicht vollständig geliefert. Die bereits vorhandenen SQL-Backups wurden für diese Dokumentation nicht als Kundendatenquelle geöffnet.

**Nachweise:** Q02, Abschnitte 1, 3–7; Q03, Multiaccount und 2FA; Q08, Jobverarbeitung.

## 20. Persistenz, Konfiguration und Schlüssel

Die Datenbank allein ist nicht das vollständige wiederherstellbare System.

MariaDB-Volume: mariadb-data-fywft1vc4rr5uyy3mw7lgy4s. Hostquelle: /var/lib/docker/volumes/mariadb-data-fywft1vc4rr5uyy3mw7lgy4s/_data. Containerziel: /var/lib/mysql. Typ: Docker-Volume, lokal, read-write. Das ist der aktive Datenbestand, kein separates Offsite-Backup.

| Bestand | Nachgewiesener Ort | Bedeutung für Wiederherstellung |
|---|---|---|
| DB-Daten | Eigenes MariaDB-Volume | Wird unabhängig vom Apprelease gehalten; nicht von Hand aus einem laufenden Volume kopieren als behaupteter konsistenter Dump. |
| Aktive Appkonfiguration | /opt/smarteinzug/shared/config.php | Enthält DB-/Mail-/API- und weitere Runtimewerte; realer Inhalt bleibt geheim. |
| Persistente Appdateien | /opt/smarteinzug/shared/storage | Mount belegt; Umfang, Aufbewahrung und externe Sicherung nicht vollständig ausgewiesen. |
| PHP-Sessions | shared/sessions → /var/lib/php/sessions | Separat vom Release; keine Sitzungswerte in Dokumentation übernehmen. |
| Deployment-Konfiguration | /opt/smarteinzug/deploy/.env | Umgebungszuordnung und Composeparameter; keine pauschale Überschreibung. |
| Code und Migrationsdateien | GitHub + releases/<SHA> | Reproduzierbarer Releasebezug ist nötig, aber ohne passende Daten/Secrets nicht ausreichend. |

Die frühe Hostinger-Vorgabe verlangte ausdrücklich den Erhalt vorhandener Entschlüsselungsschlüssel, TOTP-Konfigurationen und externer Zahlungskennungen. Die erfolgreiche Sicherung der MariaDB beweist nicht automatisch die unabhängige Sicherung dieser Dateien und Schlüssel. Dieser Punkt bleibt als Betriebsnachweis offen, ohne die bestätigte DB-Wiederherstellung in Frage zu stellen.

**Nachweise:** Q02, Persistenz und Secretablage; Q07, HostConfig; Q09, Z. 286–315, 616–622 und 824–828.

## 21. Staging: Vorbereitung versus tatsächlicher Betrieb

Die Testumgebung ist kein bloßes Umschalten von DEPLOY_ENV in Produktion.

In der frühen aufgelösten Konfiguration zeigte der Staging-Override noch name: smarteinzug und dieselben Router-/Netzwerk-/Volume-Bezüge wie Produktion. Deshalb war ein paralleler Start auf derselben Basis kein bestätigter isolierter Testbetrieb. Die Datei DOMAIN_STAGING=staging.smart-einzug.de allein erzeugte keine getrennte Umgebung.

| Merkmal | Später berichtete Korrektur | Beleggrenze |
|---|---|---|
| Compose-Projekt | smarteinzug-staging statt smarteinzug | Im Bericht als geändert und per config-Test geprüft. |
| Traefik-Namen | Eigene smarteinzug-staging-* Router/Services | Kollisionen mit Produktion sollen ausgeschlossen sein. |
| Konfiguration | DEPLOY_ENV=staging; config environment=staging | Candidate und Rollback prüfen --expect-env. |
| Daten und Zugänge | Eigene leere Datenbank, keine Prod-Schlüssel; Stripe-Testzugänge | So im Einrichtungsbericht vorgesehen. |
| Betriebsort | Bericht empfiehlt gesonderten physischen Server | Keine zusätzliche tatsächlich laufende Staginginstanz belegt. |
| Prüfung | tools/staging-isolation-check.py | Automatisierte Konfigurationsprüfung berichtet, kein hier durchgeführter Livebetrieb. |

Nicht als erledigt markieren: Ein vorhandener DNS-Eintrag oder eine auflösbare Staging-Compose-Datei ist keine produktiv isolierte Testumgebung. Im Chat wurde der echte Stagingbetrieb nicht abschließend nachgewiesen.

Für später geplante Funktions- und Fehlertests sollen keine realen Kundenlastschriften, Produktionsmigrationen, unkontrollierten Webhooks oder Testmails an Kunden ausgelöst werden. Dies war bereits Teil des Ausgangsauftrags; die aktuelle Dokumentationsaufgabe verschiebt diese Tests ausdrücklich.

**Nachweise:** Q01, Staging-Prüfungen und Berichte 431a2ea; Q02, Umzugs-/Testvorgaben; Q08, Isolationstests.

## 22. Chronologie I: Planung und erste Inbetriebnahme

Historische Vorgaben bleiben als Vorgaben erkennbar; spätere Änderungen ersetzen sie nicht rückwirkend.

| Schritt | Dokumentierter Verlauf | Ergebnis / Nachweis |
|---|---|---|
| Erweiterungsauftrag | Sync-Sperren, paketierte Verarbeitung, Multiaccount, Navigation, 90-Tage-Gerätefreigabe | Anforderungen, nicht pauschal als abgenommen behandeln. |
| Monitoringauftrag | Interne Systemübersicht und datensparsame öffentliche Statusseite | Eigene Messquellen, Datenlücken und externe Ausfallunabhängigkeit verlangt. |
| Hostinger-Entscheidung | KVM 8 gekauft; IONOS für DNS/Mail/Marketing beibehalten | Früher IONOS-VPS-entworfener Stack auf Hostinger/Coolify ausgerichtet. |
| Coolify-Einrichtung | Verwaltungsoberfläche und MariaDB-Ressource eingerichtet | Im Chat sichtbare Coolify-UI und spätere Container. |
| Hostsetup | setup-vps.sh; deploy-Benutzer, Docker-Erkennung, Pfade, UFW, fail2ban, Updates | Coolify/Docker wurden erkannt statt neu installiert. |
| SSH-Härtung | Schritt im Setup ausdrücklich mit „nein“ beantwortet | Script meldete sshd NICHT gehärtet; späterer vollständiger Nachweis fehlt. |
| Datenübernahme | IONOS-Importdatei; pre-first-deploy- und post-import-Sicherungen | Dateien vom 06.09.2026 vorhanden; vollständiger Zeilen-/Checksum-Abgleich nicht vorgelegt. |
| Manuelles Initialrelease | releases/manual-initial und current-Symlink | Ausgangspunkt der nächtlichen GitHub-Deploys. |
| Metrics-Diagnose | Heartbeat-Healthcheck meldete keinen frischen Heartbeat | Später eigenes --metrics-Verfahren; finaler Container healthy. |

Der Chat enthält umfangreiche Auswahlseiten von Hostinger und Coolify. Diese belegen den Einrichtungsverlauf, aber nicht die Installation sämtlicher dort angebotener Anwendungen. Nicht belegte Dienste wie zusätzliche Monitoringstacks werden nicht als vorhanden inventarisiert.

**Nachweise:** Q01; Q02; Q03; Q04; Q05; Q06; Q10.

## 23. Chronologie II: Releases und Fehlerläufe

Die Hashes sind Rückverfolgungsanker, keine Anweisung zum erneuten Deploy historischer Stände.

| Stand / Lauf | Commit | Inhalt und Ausgang |
|---|---|---|
| v4.0 | dbf05b43ade033edce9644db99906496a0886dec | Hintergrundverarbeitung, VPS-Stack, Deployment, Admin-System und Dokumentation; Migration 018 genannt. |
| v4.1 | e6c56e04b9cabd72bebc47eb95b82313da911974 | Hostinger/Coolify-Ausrichtung; Tarifwechsel/Upsell; Migration 019. |
| Webhosting-Absicherung | 650da914b241b54846ffc69fabbf31e0c5dec89b | Migrations-URL entkoppelt; Prüfung vor Upload/Aufruf. Lauf #38 endet später mit Broken pipe. |
| v4.5 / #39 | 8898a54226b0aa88cf1090cbb81eb4f157d7ad22 | Entkoppelter Runner, Trigger + Poll, RELEASE_SHA. Früher Fehlerauszug noch unvollständig. |
| v4.6 | 77c0444 | Redis-Diagnosekategorien und detaillierte Fehlerphasen erweitert. |
| v4.7 / #41 | 431a2ea09525d465f31176d433fb480060184247 | Stagingisolation; Candidate meldet redis: auth. |
| v4.8 / #42 | d3679e878edfcd844a1133346959c3ef1ea08167 | protected-mode-Korrektur; Bootstrapabfolge noch unzureichend. |
| v4.9 / #43 | 6c4a1ed372d3fe84e38368963568a5632b8ac027 | Redis-Konfigurationsänderung vor Candidate aktivieren; Netzprobe scheitert erneut. |
| v4.10 / #44 | aadefb80a2eafb1c8169f3e240780bc79c7dd630 | Alias-Kollision korrigiert. Serverdeploy endet 02:44:21Z; GitHub vorher rot. |
| v4.11 | b38f2f7faa90f34217117b4238791a09b124af10 | Status-rsync-Fix, Signalbehandlung, Polling und Tests. |
| v4.11 Nachbefunde | c4fabc081aa73b772d049b33bbd7b3d6d5868bca | Letzter vollständig zugeordneter Erfolgsstatus; Laufzeit 24 Sekunden. |
| Späterer Dateistand | 33349cfd99fcd6dbc2ee96448264e7d5fb42640c | Releaseverzeichnis/backup-record.php sichtbar; kein vollständiger neuer Endstatus im Chat. |

**Nachweise:** Q01, gepostete Abschlussberichte und Actions-Läufe; Q04, Commitübersicht; Q07; Q08; Q09, spätere Release-Dateiliste.

## 24. Störung A: DNS, TLS und falsches Migrationsziel

Der erste sichtbare SSL-Fehler war mit dem Wechsel des Domainziels verknüpft.

### Symptom und beobachteter Kontext

Der Webhosting-Workflow rief historisch https://app.smart-einzug.de/migrate.php auf. Während die App-Domain zum Hostinger-VPS wechselte, lieferte der Coolify-Proxy zunächst TRAEFIK DEFAULT CERT. curl brach mit Fehler 60 („self-signed certificate“) ab. Die TLS-Prüfung wurde nicht mit -k oder --insecure abgeschaltet.

Der ACME-Log nannte für App, Admin und Status die alte IPv6-Adresse 2001:8d8:100f:f000::200 und eine ungültige HTTP-Challenge-Antwort 204. Für api.smart-einzug.de wurde zu diesem Zeitpunkt NXDOMAIN für A/AAAA gemeldet. Spätere DNS-Abfragen zeigten die neue IPv4, anschließend teilweise die neue IPv6.

### Korrekturen im Verlauf

Der Entwicklungsbericht zu 650da91 ersetzte die fest verdrahtete Migrations-URL durch WEBHOSTING_MIGRATE_URL ohne Vorgabewert. Ein neues Prüfsystem sollte sowohl vor dem SFTP-Upload als auch unmittelbar vor dem Migrationsaufruf fehlerhafte Ziele abweisen. Die eigentliche Umstellung des Betriebs erfolgte mit WEBHOSTING_APP_DEPLOY=false und VPS_DEPLOY_ENABLED=true.

Die späteren curl-Aufrufe lieferten ohne den früheren Zertifikatsfehler Antworten; OpenSSL zeigte ein Let’s-Encrypt-Zertifikat mit allen vier produktiven SAN-Namen. Der lokale Aufruf per IPv6 auf den VPS ergab zunächst HTTP 404 und einen erfolgreichen TCP-Aufbau zu 443. Das belegt lokale Erreichbarkeit, nicht allein die komplette externe IPv6-Validierung.

Lehre aus diesem Vorfall: Domainname und Serverrolle sind beim Cutover nicht gleichbedeutend. Ein App-Hostname, der gestern Webhosting meinte, kann heute den VPS erreichen. Für Migrationen ist die konkrete Zielumgebung entscheidend, nicht nur eine korrekt geschriebene URL.

Die frühere Aussage „keine Migration gestartet“ bezog sich im Analysebericht auf den vor der HTTP-Anfrage abgebrochenen TLS-Aufruf. Sie ist nicht auf sämtliche späteren Workflow- oder Migrationsläufe zu übertragen.

**Nachweise:** Q01, SSL-Analyse Commit 650da91, DNS-/OpenSSL-/Traefik-Ausgaben; Q10, historischer DNS-Auszug; Q09, Abschlusszertifikat.

## 25. Störung B: Secrets, Hostkey und SSH-Abbruch

Drei verschiedene Fehlerklassen wurden nacheinander sichtbar.

| Fehler | Belegtes Symptom | Bearbeitung im Chat |
|---|---|---|
| Variable am falschen Ort | vars.WEBHOSTING_APP_DEPLOY war null | Die beiden Schalter wurden als Repository-Variablen ergänzt; nicht bloß als Secrets. |
| Fehlender Benutzer | Secret VPS_SSH_USER fehlt | Secret ergänzt; nächster Lauf erreichte die nächste Vorbedingung. |
| Fehlender Port | Secret VPS_SSH_PORT fehlt | Secret ergänzt. Wert nicht in unmaskiertem GitHub-Log offengelegt. |
| Hostkey unbekannt | No ED25519 host key is known; strict checking | VPS_SSH_KNOWN_HOSTS mit vollständiger Hostkeyzeile statt SHA256-Fingerprint gepflegt. |
| Verbindung bricht ab | client_loop: send disconnect: Broken pipe; Exit 255 | Der Remote-Deploy war zuvor an eine lange SSH-Sitzung gebunden. |
| Teilweiser Recreate | Neue PHP/Metrics-Container Created; alte Worker weiter Up | Manuelle gezielte Wiederherstellung von PHP/Metrics; current blieb manual-initial. |

Im Broken-pipe-Lauf war das PHP-Image bereits gebaut. Der Recreate hatte begonnen, aber der Prozessabbruch unterbrach die weitere Schutz-/Abschlusslogik. Aus der Meldung allein war nicht ableitbar, ob ein Netzproblem, ein Laufzeitlimit oder eine andere Verbindungsursache zugrunde lag.

Die spätere Lösung kombinierte SSH-Keepalive mit einem entkoppelten Runner. GitHub löst kurz aus und fragt anschließend über separate Verbindungen ab. Der Abschlussbericht berichtet einen Test, der einen Abbruch der auslösenden Prozessgruppe simuliert; ein echter Docker-SSH-Abbruchtest war im ersten Runnerbericht noch nicht verfügbar.

Nicht verwechseln: Ein Hostkeyfehler wird vor der sicheren Anmeldung behoben. Ein fehlendes Secret ist eine Konfigurationsvorbedingung. Ein Broken pipe während des Deploys verlangt zusätzlich die Prüfung des tatsächlich erreichten Serverzustands. Mehrfaches blindes Wiederholen ist kein Ersatz dafür.

**Nachweise:** Q01, Actions #33, #35 und #38; Q07, Zustand nach abgebrochenem Recreate; Q08, SSH-Strategie.

## 26. Störung C: Redis in mehreren Fehlerstufen

Die wechselnden Kategorien „other“ und „auth“ waren nicht bereits die bestätigte Ursache.

### 1. Diagnosekategorie statt Ursache

Zunächst meldete der Candidate redis: other, später redis: auth. Die Diagnose wurde um musl-/Alpine-DNS-Texte, Verbindungs- und Authentifizierungsfehler erweitert. Eine aktiv konfigurierte Redis-Passphrase war nicht belegt: In config.php stand password=null. Der direkte lokale redis-cli ping lieferte PONG.

### 2. Protected Mode

Der Bericht zu d3679e8 reproduzierte eine DENIED-Meldung bei Cross-Container-Zugriffen mit protected-mode yes ohne Passwort. Die neue Konfiguration verwendete protected-mode no. Die Freigabe war im Bericht an interne Netzisolation, fehlende Hostport-Veröffentlichung und getrennte Infrastruktur gekoppelt. Sie ist keine pauschale Empfehlung, Redis öffentlich ohne Schutz zu betreiben.

### 3. Reihenfolge und Bind-Mount

Die Candidate-Prüfung griff auf den schon laufenden Redis zu. Eine geänderte redis.conf im Dateibaum bedeutete nicht, dass der vorhandene Prozess die neue Konfiguration verwendete. Commit 6c4a1ed zog die kontrollierte Redis-Aktualisierung vor: Änderung erkennen, separat validieren, nur Redis neu erzeugen, healthy abwarten und aus einem anderen Container testen.

### 4. Alias-Kollision und Rollbackbewertung

Trotz Recreate schlug die Netzprobe erneut fehl. Die Rücknahme stellte zwar alte Konfiguration und healthy her, konnte aber den schon zuvor fehlerhaften Cross-Container-Zustand nicht in einen Erfolg verwandeln. Technische Wiederherstellung und fachliche Nutzbarkeit waren zu unterscheiden. Der spätere Lauf #44 benannte zusätzlich die Verwechslung mit Coolifys eigenem Redis über einen mehrdeutigen Alias.

Späterer positiver Beleg: Mit Release aadefb80… wurden „Netzwerkbasierter Redis-Test … erfolgreich“, Candidate OK und „Migrationen: 0 eingespielt, 0 offen“ protokolliert. Erst hier war der zuvor blockierte komplette Vorabpfad im realen Lauf erfolgreich.

**Nachweise:** Q01, Berichte 77c0444, d3679e8, 6c4a1ed und Run #44; Q07, Redis-Netzprobe und erfolgreicher Candidate.

## 27. Störung D: 23 Minuten trotz laufender Container

Die scheinbare Compose-Blockade war im entscheidenden Lauf eine lange Stop-Wartezeit.

Während docker compose up -d --remove-orphans lief, standen neue PHP-, Caddy-, Metrics- und Workercontainer zeitweise auf Created. Alte Worker blieben parallel Up. Der Zustand futex_wait_queue ließ zunächst einen Hänger vermuten; ein Abhängigkeitszyklus wurde anhand der gezeigten depends_on-Struktur nicht bestätigt.

Erst das Docker-Daemonlog zeigte die konkrete Warteursache: Mehrere alte Worker verließen nach Signal 3 die Prozesse nicht innerhalb von 11 Minuten. Nach dem erzwungenen Stop wurden die neuen Container gestartet. Anschließend führte deploy.sh nochmals einen Worker-/Scheduler-Restart mit -t 660 aus. Damit fiel die lange Wartephase ein zweites Mal an.

| Zeitpunkt laut Serverlog | Ereignis |
|---|---|
| 02:21:30 UTC | Runner-Log für aadefb80… angelegt / Start des Laufs. |
| 02:22:00 UTC | Redis-Netzprobe und Candidate OK; Migrationen abgeschlossen; Cutover beginnt. |
| 02:33:01 UTC | Docker erzwingt Beendigung alter Worker nach 11 Minuten. |
| 02:33:03–08 UTC | Neue PHP-/Worker-/Caddycontainer starten beziehungsweise treten Netzen bei. |
| 02:33:14 UTC | Zweiter Restart der Worker/Scheduler beginnt. |
| 02:44:21 UTC | Healthcheck und interner HTTPS-Check OK; Releasebereinigung; Deploy abgeschlossen. |

Die aus den dokumentierten Zeiten berechnete Dauer beträgt etwa 22 Minuten 51 Sekunden. GitHub hatte währenddessen nach seiner 12-Minuten-Frist mit zuletzt unknown abgebrochen. Beides war real: Der Workflow war fehlgeschlagen, der entkoppelte Serverdeploy endete später erfolgreich.

Version 4.11 korrigierte laut Bericht Signalhandler, Stop-Signal und Grace Periods, entfernte den redundanten Restart und schützte Statusdateien gegen rsync. Der vollständig belegte c4fabc-Lauf dauerte danach 24 Sekunden serverseitig. Unterschiedliche Builds/Jobs/Last machen daraus keinen allgemeinen Leistungsvergleich.

**Nachweise:** Q07, Z. 705–813 sowie 899–924; Q08, Abschnitte 1–10; Q01, Actions #44.

## 28. Backups und Wiederherstellung

Lokaler Dump, konfiguriertes Offsiteziel und erfolgreicher Nutzertest sind drei unterschiedliche Nachweisarten.

```
Produktive MariaDB -> Coolify Backupplan (enabled, 0 3 * * *)
 -> lokaler Dump: 1.274.269 Byte am 07.09.2026
 - - save_s3=yes / storage_id=1 - -> Hetzner Bucket smarteinzug
Restore-Test: vom Nutzer erfolgreich bestätigt.
Objektliste/Download aus S3 und verwendete Restore-Datei nicht vorgelegt.
App-Konfiguration, Schlüssel und Dateien sind ein separater Sicherungsumfang.
```

**Abbildung 9.** Sicherungskette mit Nachweisgrenzen. Die gestrichelte Verbindung stellt den aktiv konfigurierten S3-Weg dar, nicht einen in dieser Sitzung nachgeholten Objektdownload.

Der Kommentar des erhaltenen backup.sh nennt Coolify als zuständigen produktiven Backupweg. Das Skript selbst ist eine Ausweichlösung und nicht Bestandteil des aktiven Appstacks. Leere Root-/Deploy-Crontabs und ein allein sichtbarer dpkg-db-backup.timer widerlegen den internen Coolify-Backupplan nicht.

Der Nutzer teilte mit, dass ein Restore-Test bereits durchgeführt wurde und funktioniert. Das wird als erfolgreich bestätigter Test dokumentiert. Nicht mitgeliefert wurden Testdatum, Zielinstanz, geprüfte Tabellen/Zeilenzahlen, Prüfsummen und die Information, ob die Datei direkt aus dem Hetzner-Bucket zurückgelesen wurde.

**Nachweise:** Q09, Z. 665–690, 801–823; Q01, Restore-Bestätigung; Q02, Sicherung von Dateien und Schlüsseln.

## 29. Backupinventar, Zeitplan und offene Detailnachweise

Die vorhandenen Sicherungen sind benannt; die Produktions-DB wird nicht mit Coolifys eigener PostgreSQL-DB verwechselt.

| Objekt / Einstellung | Dokumentierter Wert / Beobachtung |
|---|---|
| Lokaler aktueller Dump | mariadb-dump-smarteinzug-1788750004.dmp; 1.274.269 Byte; 07.09.2026 03:00:06 laut Dateiliste. |
| Zuordnung | /data/coolify/backups/databases/root-team-0/; smarteinzug-mariadb-fywft1vc4rr5uyy3mw7lgy4s/ |
| Frühere Coolify-Dumps | …1788722891.dmp und …1788722742.dmp, jeweils 1.353 Byte. Inhalt nicht geprüft; geringe Größe allein beweist keine leere Datenbank. |
| Manuelle IONOS-Importdatei | /opt/smarteinzug/backups/ionos-production-import.sql; 765.449 Byte, 06.09.2026. |
| Manuelles Post-import-Backup | post-import-20260906-212031.sql; 731.967 Byte. |
| Manuelles Pre-first-deploy-Backup | pre-first-deploy-20260906-211344.sql; 1.407 Byte. |
| Coolify-Backupplan | id=1; enabled=yes; save_s3=yes; s3_storage_id=1; frequency=0 3 * * *. |
| S3 Storage | id=1; Name SmartEinzug Backups; Bucket smarteinzug; Endpoint https://fsn1.your-objectstorage.com. |
| Zeitbezug | Ein Lauf um 03:00 UTC ist im Serverlog sichtbar. Der Cronausdruck alleine legt keine separate Scheduler-Zeitzone offen. |

Nicht aus dem Ausweichskript übernehmen: dessen Standardrotation von 14 Tagen, optionale age-Verschlüsselung, rclone-/curl-Upload oder lokaler Ordner /backups sind keine nachgewiesenen Einstellungen des tatsächlich eingesetzten Coolify-Backupplans.

Als Nachweise noch offen: konkrete Aufbewahrungsregeln, Objektliste/Prüfsumme im S3-Bucket, letzte erfolgreiche externe Rücklesung, Sicherung von Appdateien und Entschlüsselungsschlüsseln sowie Alarmierung bei ausgebliebenem Backup. Ein Jobstatus DONE ist nicht allein die Prüfung jedes dieser Punkte.

**Nachweise:** Q09, Z. 332–354, 616–622, 665–690 und 801–823; Q01, Restore-Aussage.

## 30. Sicherheit, Root-Zugang und bereinigte Altbestände

Ein erfolgreicher Umzug ersetzt keinen abschließenden Sicherheitscheck.

### Erledigte Bereinigung

Die Dateien /opt/smarteinzug/shared/config.php.bak und config.php.before-vps-fields wurden im Root-Terminal entfernt. Danach zeigte ls nur noch die aktive config.php mit Besitzer deploy:deploy und Rechten rw-r-----. Die anschließende Suche nach dem alten IONOS-DB-Host zeigte keinen Treffer; der DB-Healthcheck war erneut OK.

### Verschiedene Sicherheitsgrenzen

Im historischen Setup wurden 22, 80 und 443 für IPv4/IPv6 zugelassen, Port 8000 in UFW gesperrt und fail2ban sowie unattended-upgrades eingerichtet beziehungsweise aktiviert. Der ausdrückliche sshd-Härtungsschritt wurde mit „nein“ beantwortet. Der Chat enthält keinen späteren vollständigen Nachweis zu PasswordAuthentication, PermitRootLogin, Docker-PortBindings oder Hostinger-Firewall.

Der automatische Deploy verwendet einen eigenen SSH-Zugang und strenge Hostkeyprüfung. Die Root-Befehle des Chats waren administrative Eingriffe; daraus folgt keine Root-Berechtigung der Webanwendung. Ein vollständiger Least-Privilege-Nachweis für alle Container ist nicht enthalten.

### Konkreter noch offener Header-Abgleich

In einer App-Antwort ist ein Sitzungscookie mit HttpOnly und SameSite=Lax sichtbar, aber ohne sichtbares Secure-Attribut. Der Ausgangsauftrag verlangt Secure für die Gerätefreigabe. Ob dies eine andere Cookieklasse, eine Proxyerkennungsfrage oder eine Konfigurationsabweichung betrifft, wurde im Chat nicht geprüft. Der reale Sitzungswert wird hier bewusst nicht wiedergegeben.

Ausstehende Abnahme, keine Fehlerbehauptung: öffentliche Erreichbarkeit von Verwaltungsports, Token-/Cookieattribute, Backup-/Schlüsselverschlüsselung und kundenseitige Rechteprüfung müssen bei der späteren Sicherheits-/Funktionsabnahme gezielt gegen den aktuellen Code geprüft werden.

Die Suche mit unterdrückter Fehlerausgabe ist ein begrenzter Nachweis des sichtbaren Suchbereichs. Sie ist kein globaler Beweis, dass keine fremde Datei, historische Sicherung, externe Aufgabe oder frühere Kopie noch einen IONOS-Bezug enthält. IONOS-SMTP bleibt absichtlich konfiguriert.

**Nachweise:** Q02, Sicherheitsvorgaben; Q05, Einrichtung/UFW/sshd; Q09, HTTP-Header und Bereinigung Z. 824–837; Q03, sichere Cookies.

## 31. Produktanforderungen: Sync, Firmen und Exporte

Dieser Teil bewahrt den fachlichen Ausgangsauftrag des Chats. Er ist nicht die nachgeholte Benutzerabnahme.

### Synchronisierung ohne Doppelverarbeitung

Gefordert wurden atomare firmenspezifische Sperren über alle Startkanäle, eindeutige Lockinhaber und sichere Behandlung verwaister Läufe. Fortschritt darf erst nach dauerhaft gespeicherten Daten weitergesetzt werden. Zeitbudget, Datensatzanzahl, API-Aufrufe und Wiederholungen sollen zentral begrenzt sein. Transaktionen bleiben kurz und laufen nicht über langsame externe API-Aufrufe hinweg.

Webhooks sollen geprüft und dauerhaft übernommen werden, bevor deren Empfang bestätigt wird. Doppelte oder zeitlich ungeordnete Ereignisse dürfen keine zusätzlichen Zahlungen erzeugen. Ein unklarer Stripe-Timeout wird anhand des vorhandenen Vorgangs aufgeklärt, nicht durch einen neuen unabhängigen Zahlungsauftrag ersetzt. Die frühe Angabe „zwei Lexware-Anfragen je Sekunde“ war ausdrücklich zu verifizieren, nicht als unveränderter Grenzwert festzuschreiben.

### Navigation und Multiaccount

„Firma“ soll „Firmendaten“ heißen; „Firmen“ soll „Firmenübersicht“ heißen. Doppelte Header-/Profilnavigation entfällt ohne Funktionsverlust. Multiaccount ist eine benutzerbezogene Einstellung: Bei einer Firma optional, bei mehreren zugänglichen Firmen automatisch aktiv. Firmenwechsel prüft Berechtigungen serverseitig und mischt weder Daten noch Zahlungszugänge oder Abonnements.

### Registrierung und Dubletten

Eine E-Mail-Adresse entspricht einer Benutzeridentität. Weitere Firmen werden erst nach Anmeldung und bewusstem Abschluss zugeordnet. Ein identischer Firmenname ist kein Zugriffsrecht auf eine fremde Firma. Bereits bestehende E-Mail-/Firmenkombinationen sollen keinen Doppelbestand erzeugen und mit Hinweis, fünf Sekunden Countdown und vorausgefüllter E-Mail zur Anmeldung führen.

### Exportvergleich nicht ungeprüft vorwegnehmen

„Export“ und „Journal als CSV exportieren“ dürfen nur zusammengeführt werden, wenn Inhalt, Rechte, Filter, Zeitraum, Auswahl/Paginierung und Ausgabe fachlich gleichwertig sind. Andernfalls bleibt die eigenständige Funktion bestehen. Ein abschließender Vergleich beider Exportdateien ist im vorliegenden Betriebschat nicht nachgewiesen.

**Nachweise:** Q03, Abschnitte 1–4 und 6–8; Q02, Abschnitte 1–4 und 6; Q01, verschobene Tests.

## 32. Produktanforderungen: 90-Tage-Gerätefreigabe

Die gewünschte Funktion merkt einen bestätigten Browser, nicht den TOTP-Code.

| Vorgabe | Konkret geforderte Ausgestaltung |
|---|---|
| Aktivierung | Checkbox „Dieses Gerät für 90 Tage merken“, standardmäßig aus; erst nach erfolgreichem regulärem Authenticator-Code. |
| Anmeldung | Passwort bleibt erforderlich. Gerätefreigabe ersetzt nur die reguläre erneute 2FA-Abfrage im passenden Browser-/Nutzerkontext. |
| Gültigkeit | Fester serverseitiger Ablauf nach 90 Tagen; keine gleitende Verlängerung durch Aktivität, Firmenwechsel oder Tokenrotation. |
| Token | Kryptografischer Zufallswert; serverseitig nur Hash, Bezug zu Benutzer/Bereich, Freigabe, Ablauf, letzter Verwendung und Widerruf. |
| Cookie | Secure, HttpOnly, SameSite=Lax, Path=/; Host-only und nach Vorgabe vorzugsweise __Host-Präfix. Kein localStorage oder URL-Token. |
| App versus Admin | Getrennte Vertrauensbereiche; eine Appfreigabe gilt nicht automatisch für den Adminhost. |
| Kritische Aktionen | Frische Authenticator-Bestätigung; höchstens fünfminütiges passendes Bestätigungsfenster. |
| Widerruf | Gerät vergessen, alle Geräte vergessen, Sicherheitsänderungen und überall abmelden entwerten passende Freigaben. |
| Recovery | Recovery-Anmeldung ermöglicht Wiederherstellung, stellt allein aber keine neue 90-Tage-Freigabe aus. |
| Darstellung | Geräte-/Browserbezeichnung, Bereich, letzte Verwendung und Ablauf; keine erfundene Hardwarebindung. |

Die 90 Tage wurden als Produktentscheidung verlangt, nicht als Bankenstandard oder regulatorischer Konformitätsnachweis. Die Funktion ist kein 90-tägiger Dauerlogin. Sitzungs- und Inaktivitätsgrenzen bleiben davon getrennt.

Abnahmestand: Die Betriebsprotokolle zeigen keine vollständigen Browsertests für Ablauf, Widerruf, getrennte App-/Adminfreigaben oder kritische Aktionen. Diese Anforderungen bleiben im späteren Testplan sichtbar, statt aus grünen Containerchecks als erledigt abgeleitet zu werden.

**Nachweise:** Q03, Abschnitt 5; Q02, Abschnitt 5; Q01, Nutzeranforderung zur 2FA und vertagte Tests.

## 33. Monitoring: interne Sicht und Messgrenzen

Die Systemübersicht soll echte Messwerte zeigen, keine aus technischen Nebenbefunden abgeleitete Vollverfügbarkeit.

Der Ausgangsauftrag verlangte einen Plattform-Administrationsbereich „System“ mit Übersicht, Diensten, Aktivität, Verfügbarkeit sowie Störungen/Wartung. In späteren Berichten werden Reiter Jobs, Server, Versionen und Dokumentation genannt. Die finale Navigation wurde nicht mit einem kompletten Screenshot oder Browsertest abgenommen.

| Messbereich | Gewünschte Aussage | Nicht gleichsetzen mit |
|---|---|---|
| Host | CPU, RAM, Swap, Datenträger, Inodes, Netzwerk, Laufzeit | PHP-Skriptverbrauch oder ungeprüften Tarifdaten. |
| Container | Zustand, Neustarts, Ressourcen und Healthcheck | Erfolg jeder fachlichen Anwendungstransaktion. |
| Jobs | Start, Ende, letzter Erfolg, Heartbeat, Fortschritt, Warteschlange | Anzahl laufender Betriebssystemprozesse. |
| Datenbank | Verbindung und kleiner Lesetest | Bewiesener Schreibfähigkeit, Vollständigkeit oder Backupqualität. |
| Mail | Warteschlange, Übergabe, Fehler und Alter | Bewiesener Zustellung im Empfängerpostfach. |
| Lexware / Stripe | Tatsächliche API-Ergebnisse, Drosselung und Verarbeitungsrückstand | Zusätzlichen häufigen Testabrufen oder fachlicher Zahlungsablehnung als Systemausfall. |
| Deployment | Release-SHA, Endstatus, Migration und reale Laufzeitbindung | Bloßer Annahme des Triggers. |
| Sicherungen | Zeitpunkt, Größe, Ziel, Ergebnis und Restorebeleg | Vorhandensein eines Scripts oder konfigurierten Buckets. |

Geforderte Aktivitätsfenster: 1 Minute, 10 Minuten, 1 Stunde und 24 Stunden. Für Verfügbarkeit zusätzlich 7, 30 und 90 Tage. Start- und Abschlusszähler, Versuche und eindeutige Aufträge sowie unbekannte Zeiträume sollen getrennt bleiben. Ein einminütiges Anzeigefenster erzwingt keinen nachgewiesenen einminütigen Messtakt.

Die gewünschte Rohdatenrotation (14 Tage), Minutenaggregate (30), Stundenaggregate (90) und Tagesaggregate (400) waren anpassbare Ausgangswerte. Die aktuell eingerichteten Retentionswerte sind nicht in den Terminalprüfungen ausgewiesen.

**Nachweise:** Q02, Abschnitt 7; Q11, Systemmonitoring-Gesamtprompt; Q06, Metrics-Fehler; Q08 und Q09, finale Betriebsnachweise.

## 34. Statusseite und Alarmierung

Erreichbar ist nicht dasselbe wie ausfallunabhängig oder fachlich vollständig überwacht.

status.smart-einzug.de lieferte HTTP 200 und ist vom gültigen SAN-Zertifikat abgedeckt. Zugleich zeigt der dokumentierte DNS-/Routeraufbau diesen Host auf demselben Hostinger-VPS wie die App. Damit ist die im Ausgangsauftrag gewünschte unabhängige Ausfallumgebung nicht durch die vorgelegten Daten belegt.

Offene Abweichung zum Ausgangsauftrag: Eine zusätzliche Status-Subdomain auf demselben VPS ist keine nachgewiesene unabhängige Störungskommunikation bei einem vollständigen VPS-Ausfall. Dies wird hier ausdrücklich als noch zu klärender Architekturpunkt ausgewiesen; es wird kein weiterer Dienst als bereits eingerichtet behauptet.

### Geforderte öffentliche Inhalte

Öffentlich vorgesehen sind kundenbezogene Funktionszustände wie Webanwendung, Anmeldung, Datenabgleich, Einzugsverarbeitung und E-Mail-Benachrichtigungen; dazu Aktualität, Vorfälle und geplante Wartung. Interne IP-Adressen, Versionen, Pfade, genaue Jobzahlen, Kundendaten und Zugangswerte gehören laut Auftrag nicht in diese öffentliche Ausgabe.

### Verfügbarkeit nach ursprünglicher Vorgabe

```
```

T_Fenster = T_verfuegbar + T_ausfall + T_unbekannt

Beobachtete Verfuegbarkeit = 100 * T_verfuegbar / (T_verfuegbar + T_ausfall)

Messabdeckung = 100 * (T_verfuegbar + T_ausfall) / T_Fenster

Bei unzureichender Datengrundlage soll „Status unbekannt“ beziehungsweise „Unvollständige Messdaten“ erscheinen. Die geforderte Schwelle von 99 % Messabdeckung ist eine Produkteinstellung, kein belegtes SLA. Historische 30-/90-Tage-Verfügbarkeit darf kurz nach Einführung nicht erfunden werden.

Alarmkanäle, Empfänger, Entwarnung, Deduplizierung und ein unabhängiger Alarmweg bei Mailausfall wurden verlangt. Ein konkreter End-to-End-Alarmauslöser mit nachgewiesenem Eingang beim Empfänger ist im Chat nicht enthalten. Das gilt auch für Backupausfälle.

**Nachweise:** Q02, Abschnitte 7.7–8; Q11; Q01, endgültige DNS-/HTTPS-Tests; Q09, HTTP 200 für status.

## 35. Entwicklungsprüfungen und Testabdeckung

Die Testberichte sind umfangreich, ihre Grenzen wurden im Verlauf teilweise ausdrücklich genannt.

| Prüfwerkzeug / Bereich | Laut Abschlussbericht v4.11 | Grenze |
|---|---|---|
| redis-deploy-check.sh | 14 Szenarien, 79 Prüfungen | Fake-Docker-Sandbox; kein vollständiger produktiver Docker-Faulttest. |
| deploy-runner-check.sh | 35 Prüfungen | Unter anderem Status, --tail, Vorlaufstatus und SSH-Prozessgruppenabbruch. |
| worker-signal-check.sh | 17 Prüfungen | Echte PHP-Prozesse und bin/worker.php gegen temporäre MariaDB. |
| github-poll-check.sh | 14 Prüfungen | Pollingzustände und Fehlerbehandlung in Testumgebung. |
| compose-check.py | 0 Fehler | Statische Schutz-/Reihenfolgeprüfungen. |
| staging-isolation-check.py | 0 Fehler | Konfigurationsisolation, kein nachgewiesener Staging-Kundenbetrieb. |
| healthcheck-redis-check.php | 0 Fehler | Diagnose-/Regressionsfälle. |
| Lint / Konfiguration | php -l, bash -n, YAML, Compose prod/staging OK | Syntax/aufgelöste Konfiguration sind kein vollständiger Geschäftsprozess. |
| Adversariale Gegenproben | Schutz temporär entfernt → passende Tests rot | Berichtet für Excludes, Signale, Polling, Vorabstopp, --tail und weitere Fälle. |

Der Reviewbericht beschreibt 39 Befunde, davon 17 als real bestätigt und behoben. Die übrigen wurden als Doppelmeldungen, Formulierungen oder bereits erledigte Arbeitsstände eingeordnet. Zwei Nachbefunde führten zum Folgecommit c4fabc: Fortsetzung von collections_due bei frühem Stop und Release-Bindungsprüfung vor der Symlink-Aktivierung.

Explizit nicht automatisiert getestet: der Shutdown-Zweig in sync_state_step() und die Fortsetzung von collections_due vor dem ersten Einzug wurden im Bericht nur durch Code-Lesen verifiziert. Außerdem blieb die PDO-Verbindungszeit ohne Timeout in db() unverändert. Eine pauschale Aussage „alles getestet“ wäre deshalb unzutreffend.

Die produktiven Betriebsbelege ergänzen diese Entwicklungstests: erfolgreiche Containerstarts, der Redis-Netztest, Migrationsstatus, HTTPS-Antworten und der erfolgreiche 24-Sekunden-Deploy. Die echten fachlichen Applikationstests wurden auf Wunsch vertagt.

**Nachweise:** Q08, Z. 96–114; Q01, frühere Abschlüsse 8898a54 bis 6c4a1ed; Q02, Abnahmekriterien.

## 36. Runbook A: Status und aktuelle Laufzeit

Für eine spätere lesende Diagnose. Die Befehle wurden in diesem Dokumentationsauftrag nicht ausgeführt.

Nur die Befehle im jeweils bezeichneten Block kopieren; Terminalprompt, Beispielantworten und Überschriften sind keine Shellbefehle. Zunächst immer den aktuellen Stand prüfen, statt einen alten SHA aus dieser Dokumentation als heute aktiv anzunehmen.

### A1 · Deploystatus, Protokoll und Release

```
bash /opt/smarteinzug/deploy/scripts/deploy-status.sh

bash /opt/smarteinzug/deploy/scripts/deploy-status.sh --tail 80

readlink -f /opt/smarteinzug/releases/current
```

Zu bewerten sind phase, SHA, Zeitstempel und exit_code. success mit passendem SHA bestätigt den beendeten Lauf; running bedeutet weiter prüfen, nicht erneut starten. unknown ist kein Beweis, dass kein Serverprozess arbeitet. Die Status-PID kann nach Ende historisch sein.

### A2 · Container und laufende Deployprozesse

```
docker ps -a \
  --filter label=com.docker.compose.project=smarteinzug \
  --format 'table {{.Names}}\t{{.Status}}'

ps aux | grep -E 'deploy-runner|deploy\.sh|docker compose' \
  | grep -v grep
```

Der Projektfilter ist eine redaktionell präzisierte lesende Variante der im Chat verwendeten Namenssuche. Created, restarting, exited und unhealthy sind unterschiedlich zu behandeln. Kein Treffer in einer Prozesssuche allein bestätigt nicht den Erfolg; dafür gehört das zugehörige Runner-Log dazu.

### A3 · Tatsächlich laufendes Release des PHP-Containers

```
docker inspect smarteinzug-php-1 \
  --format 'Status={{.State.Status}} WorkingDir={{.Config.WorkingDir}}'
```

Dieser zusätzliche lesende Prüfblock ist aus der dokumentierten Release-Bindungsprüfung abgeleitet, im Chat aber nicht in exakt dieser Form ausgeführt. Er verändert weder Code noch Datenbank.

**Nachweise:** Q07; Q08, Statusskript; Q09, Endstatus und Healthchecks. Befehle aus dem Chat, nur lesend ausgewählt.

## 37. Runbook B: Datenbank, Netz und Scheduler

Geheimnisse bleiben verborgen. Die laufende Produktions-DB wird nicht für Experimente verändert.

### B1 · Datenbank-Erreichbarkeit über den bekannten App-Prüfweg

```
docker exec smarteinzug-php-1 php bin/healthcheck.php --db
```

Die erwartete erfolgreiche Antwort lautet OK. Sie bestätigt den implementierten DB-Healthcheck, nicht Tabellenvollständigkeit, Schreibfähigkeit, Mandantentrennung oder den Erfolg eines Einzugs. Keine vollständige config.php und keine Umgebungsvariablen ausgeben.

### B2 · Container und Datenvolume identifizieren

```
docker inspect fywft1vc4rr5uyy3mw7lgy4s \
  --format 'Name={{.Name}} Image={{.Config.Image}}'

docker inspect fywft1vc4rr5uyy3mw7lgy4s \
  --format '{{json .Mounts}}' | jq

docker inspect fywft1vc4rr5uyy3mw7lgy4s \
  --format '{{json .NetworkSettings.Networks}}' | jq
```

Containername und Volume gehören zum dokumentierten Bestand. Bei einer Neuinstallation oder Ersetzung zuerst die neue Kennung ermitteln; keine angenommene Wiederverwendung der internen IP erzwingen. Die letzten beiden Darstellungen sind reine Docker-Metadatenabfragen.

### B3 · Arbeitsnachweis des Schedulers

```
docker logs --since 15m smarteinzug-scheduler-1 2>&1 | tail -50
```

Einreihungen sind von abgeschlossenen Jobs zu unterscheiden. Enthalten Logs später Kunden- oder Zahlungsdaten, vor Weitergabe redigieren. Der ursprüngliche Befehl require "bin/bootstrap.php" ist kein gültiger Standardweg in diesem Projekt; er scheiterte mit „No such file or directory“. Ungeprüfte --verbose-Optionen werden nicht vorausgesetzt.

Bei Authentifizierungsfehlern nicht durchprobieren: Keine Passwörter in Befehlszeile, Chat oder Logs schreiben. Die direkte SQL-Identitätsabfrage war im Chat nicht erfolgreich; ein Container-Inspect ersetzt sie nicht vollständig.

**Nachweise:** Q09, Healthcheck, MariaDB-Inspect und Schedulerlog; Q07, Netzdiagnosen; Q01, fehlerhafte Bootstrap-Versuche.

## 38. Runbook C: HTTPS und Backupnachweise

Lesende Prüfungen aus dem Verlauf; keine neue Sicherung, Migration oder Wiederherstellung starten.

### C1 · Antworten der vier produktiven Hosts

```
for d in app.smart-einzug.de admin.smart-einzug.de \
         api.smart-einzug.de status.smart-einzug.de; do
  echo "===== $d ====="
  curl -sS --connect-timeout 10 --max-time 20 \
    -o /dev/null -w 'HTTP %{http_code} | %{time_total}s\n' \
    "https://$d/"
done
```

Die Timeouts sind eine ergänzte Begrenzung des im Chat verwendeten Lesetests, kein bereits ausgeführter neuer Test. TLS-Prüfung bleibt aktiv; kein -k/--insecure. Vom VPS aus ausgeführte Requests sind keine unabhängige externe Mehrstandortprüfung.

### C2 · Lokale Coolify-Backupdateien auflisten

```
find /data/coolify/backups -maxdepth 4 -type f \
  -printf '%TY-%Tm-%Td %TH:%TM:%TS  %10s  %p\n' \
  | sort -r | head -30
```

Datum, Datenbankpfad und Größe zuordnen. Eine vorhandene große Datei ist noch kein vollständiger Restorebeweis. Keine SQL-Dumps mit Kundendaten in den Chat kopieren.

### C3 · Eingestellten Coolify-Backupplan lesen

```
docker exec coolify php artisan tinker --execute='
\App\Models\ScheduledDatabaseBackup::query()->get()->each(function($b) {
    echo "id=".$b->id.
         " enabled=".($b->enabled ? "yes" : "no").
         " save_s3=".($b->save_s3 ? "yes" : "no").
         " s3_storage_id=".($b->s3_storage_id ?? "-").
         " frequency=".($b->frequency ?? "-").PHP_EOL;
```

}); '

Diese Modellabfrage funktionierte im dokumentierten Coolify-Stand. Bei einer späteren Version zuerst Kompatibilität prüfen. Sie liest Planwerte, listet aber keine S3-Objekte. Das Ausweichscript backup.sh wird dadurch nicht gestartet.

**Nachweise:** Q09, HTTPS-, SAN-, Backup- und S3-Ausgaben; Q01, verschobene Funktionstests.

## 39. Störungsbehandlung ohne Nebenwirkung

Handlungslogik für spätere Vorfälle – keine pauschalen Reparaturkommandos gegen Produktion.

| Beobachtung | Zuerst lesend prüfen | Nicht vorschnell tun |
|---|---|---|
| GitHub rot, VPS läuft | Betroffenen SHA, Statusdatei, Runner-Log und laufende Prozesse | Neuen Deploy starten oder einen laufenden Runner killen. |
| Status unknown | Status-/PID-Pfade, Dateiübergabe, Rechte, aktuelle Runnerprozesse | Daraus „kein Deployment“ folgern oder Sperrdatei löschen. |
| Container Created | Composeprozess, relevante Docker-Daemonmeldungen und Stop-Wartezeit | Unkoordiniert zusätzlich compose up/restart auslösen. |
| Redis local PONG, Appfehler | Cross-Container-Ziel, Alias, Netzmitgliedschaft, tatsächliche Fehlermeldung | AUTH/Passwort ändern, nur weil ein allgemeiner Fehler auth heißt. |
| DB-Healthcheck OK, Fachfehler | Konkreten Benutzer-/Firmen-/Jobkontext und Anwendungslog | Blind migrieren, einen Zahlungsauftrag neu erzeugen oder alte DB zurückspielen. |
| Migration fehlgeschlagen | Fehlerzustand und mögliche Teiländerungen; kompatiblen Wiederherstellungsweg | Fehlerhistory löschen oder automatisch mehrfach ausführen. |
| Backupdatei vorhanden | Zuordnung, Zeitpunkt, Remote-Kopie und dokumentierten Restoreumfang | Die produktive DB testweise überschreiben. |
| Einzug unklar | Bestehende Zahlungskennung, Versuchsjournal und externen Status | Neue Lastschrift als vermeintlichen Verbindungstest erzeugen. |

Ein Code-Rollback bleibt von einem Datenrestore getrennt. Wurden seit der Sicherung Zahlungen, Webhooks, Rechnungen oder Benutzeränderungen verarbeitet, könnte ein unkontrollierter Restore diese Historie übergehen. Der Ausgangsauftrag verlangt dafür ausdrücklich eine kontrollierte Klärung statt einer automatischen Rücksetzung.

Bei manuellen administrativen Änderungen muss anschließend der Repository-/Deploystand wieder dazu passen. Temporäre Änderungen nur am Host können beim nächsten Release ersetzt werden. Der dokumentierte Redis-Bootstrap zeigt genau, warum Laufzeit, Bind-Mount-Datei und Releasevergleich separat betrachtet werden müssen.

**Nachweise:** Q01, Verlauf der Wiederanläufe; Q02, Migrations-/Recoveryvorgaben; Q07; Q08.

## 40. Vertagte fachliche Abnahme

Der Auftraggeber hat die folgenden Tests ausdrücklich auf später verschoben.

| Testbereich | Später zu prüfen | Aktueller Nachweis |
|---|---|---|
| Login / 2FA | Normaler Login, falscher Code, Recovery, Ablauf/Widerruf der Gerätefreigabe | Weiterleitung zur Loginseite, keine vollständige Browserabnahme. |
| Administration / Rollen | Plattformadministrator versus Firmenadministrator; fremde Mandanten ablehnen | Admin-Weiterleitung und healthy-Container. |
| Multiaccount | Anlegen/Wechseln mehrerer Firmen, Rechte und bestehende E-Mail | Anforderungsbestand, keine abschließende Kundenprüfung. |
| Lexware-Sync | Erstimport, Fortsetzung, Rate-Limit, Doppelstart und Fehlerwiederaufnahme | Worker healthy; Betriebs-/Regressionsberichte vorhanden. |
| Stripe / Einzüge | Idempotenz, unklare Ergebnisse, Webhookreihenfolge, Wiederaufnahme nach Stop | Kein finaler realitätsnaher End-to-End-Nachweis im Chat. |
| Mail | Warteschlange, Übergabe, Zustellung, doppelter Versand bei Wiederaufnahme | SMTP-Konfiguration und Mailworker healthy. |
| API / Webhooks | Vorgesehene echte Routen, Authentifizierung, Signatur und Fehlercodes | API-Root 404, echte Route noch nicht geprüft. |
| Exporte | Gleichwertigkeit der beiden Exportbedienelemente | Fachlicher Dateivergleich nicht nachgewiesen. |
| Monitoring / Alarm | Ausfall, Warnung, Entwarnung und tatsächlicher Empfang | Statusseite 200, konkrete Alarmabnahme offen. |
| Backups / Restoreumfang | Remote-Rücklesung, Daten-/Schlüssel-/Dateiwiederherstellung | Lokaler Dump, S3-Plan und Nutzerbestätigung zum Restore. |

Testvorgabe aus dem Chat: Fehler- und Zahlungstests in isolierter Umgebung, mit geeigneten Mocks oder ausdrücklich vorgesehenen Testzugängen. Keine echten Kundenlastschriften und keine Produktionsunterbrechung nur für die Abnahme.

**Nachweise:** Q01, letzter Dokumentationsauftrag; Q02, Abschnitte 9.1–9.5; Q03, Testkatalog; Q08, offen benannte Codepfade.

## 41. Offene Punkte und Entscheidungsregister

Offen bedeutet: in diesen Quellen nicht hinreichend nachgewiesen – nicht automatisch defekt.

| Priorität | Offener Nachweis / Entscheidung | Empfohlener Abschlussbeleg |
|---|---|---|
| Vor fachlicher Freigabe | Echte Applikationsprozesse einschließlich Geldfluss und Mail | Protokollierter Testfall mit Erwartung, Ergebnis, Release und Testumgebung. |
| Betrieblich wichtig | Unabhängige Statusseite / externer Monitor | Nachweis des getrennten Auslieferungsorts und Verhaltens bei App-/VPS-Ausfall. |
| Betrieblich wichtig | Backupobjekt im Remoteziel und Herkunft des getesteten Restores | Objektname/Datum/Größe oder Prüfsumme; Restoreprotokoll ohne Kundendaten. |
| Betrieblich wichtig | Sicherung notwendiger Appdateien und Schlüssel | Separates Inventar, geschützter Speicherort und Wiederherstellungsnachweis. |
| Sicherheitsabnahme | SSH-Härtung, Host-/Docker-Firewall, Verwaltungsports | Aktuelle tatsächliche Konfiguration und kontrollierte Außenprüfung. |
| Sicherheitsabnahme | Cookie-Secure-/Proxy-Konfiguration | Aktuelle Header- und Browserprüfung getrennt nach Session und Gerätefreigabe. |
| Nachvollziehbarkeit | Späterer Release 33349… | Aktueller Status + Symlink + tatsächlicher WorkingDir-Abgleich. |
| Migration abschließen | Verbliebene IONOS-Appdateien, alte Cron-/Webhookaufrufer | Gezielte Bestandsliste; nur abgelöste SmartEinzug-Komponenten entfernen. |
| Dokumentationspflege | Physisches DB-Schema und aktive Migrationshistorie | Geprüfter struktureller Export ohne Nutzdaten; kein neu erfundenes Modell. |

Die oben genannten Prüfungen werden mit dieser Dokumentation nicht ausgelöst. Sie dienen als klarer Übergabepunkt, damit der nächste Bearbeiter nicht wieder bereits gelöste Redis-/Deployprobleme pauschal umbaut oder unbestätigte Anforderungen als erfolgreich abgenommen behandelt.

**Nachweise:** Q01; Q02; Q05; Q08, offene Punkte; Q09, tatsächlich gezeigte Ergebnisse.

## 42. Betriebsübergabe und Pflege der Dokumentation

Vorgeschlagene Betriebsroutine, getrennt vom nachgewiesenen Einrichtungszustand.

### Nach einem später autorisierten Deploy

Endstatus und SHA mit aktiver Laufzeit vergleichen, healthchecks und betroffene Dienste prüfen und den fachlichen Schwerpunkt der Änderung kontrolliert testen. Relevanten Commit, Lauf-ID, Ergebnis und Abweichungen in die Änderungshistorie übernehmen. Keine manuelle Erfolgsmeldung aus einem bloß gestarteten Trigger ableiten.

### Regelmäßige Betriebskontrolle

Als Arbeitsroutine vorgeschlagen: letzte erfolgreiche Sicherung und externe Kopie, Queuealter/fehlgeschlagene Jobs, Aktualität der Heartbeats, freien Speicher, ungewöhnliche Neustarts und Zertifikatsablauf prüfen. Tatsächliche automatische Empfänger/Schwellenwerte müssen separat aus der implementierten Konfiguration dokumentiert werden.

### Zuständigkeit und Geheimnisse

Timo Müller ist Auftraggeber und im Chat handelnder Betreibervertreter. Ein namentlicher zweiter technischer Verantwortlicher oder ein Bereitschaftsdienst wurde nicht festgelegt. Passwörter, private Schlüssel und Recoveryinformationen gehören in den dafür vorgesehenen geschützten Bestand, nicht in PDF, TXT, Repository oder Tickets.

### Fortschreibung

Dokumentversion 1.0 bildet den Chatabschluss vom 07.09.2026 ab. Änderungen an Anbieterzuordnung, Domains, Zertifikaten, Netzwerkaliasen, Docker-/PHP-/DB-Versionen, Releaseablauf, Backupziel, Retention oder Restoreverfahren benötigen eine neue Version mit Datum und Nachweis. Screenshots und Logs sollten den konkreten Zeitpunkt und die Umgebung erkennen lassen.

Leitlinie: Den funktionierenden Stand erhalten. Änderungen zuerst im richtigen Testkontext vorbereiten, nicht ungeprüft aus alten Chatkommandos auf Produktion übertragen. Diese Dokumentation ist eine belastbare Übergabegrundlage, keine dauerhafte Garantie des Serverzustands.

**Nachweise:** Q02, Betriebs-/Dokumentationsanforderungen; Q08, Deployment-Rahmen; Q01, Abschlusswünsche.

## 43. Quellenregister I: Auftrag und Einrichtung

Primärbasis sind Chatbeiträge und vom Nutzer eingebrachte Dateien. Externe Fakten wurden für diese Dokumentation nicht neu recherchiert.

| Quelle | Datei / Gesprächsbestand | Verwendeter Umfang |
|---|---|---|
| Q01 | Direkter Chatverlauf vom 06.–07.09.2026 | Nutzerentscheidungen, gepostete GitHub-Fehler, Claude-Berichte 650da91 bis aadefb80, Status-/DNS-Ausgaben, Restorebestätigung, DB-Löschung und Auftrag zur Vertagung fachlicher Tests. |
| Q02 | SmartEinzug_Gesamtprompt_Hostinger_KVM8.md | 1105 Zeilen; Infrastrukturentscheidung, Migration §0, Synchronisierung §1, Benutzer-/2FA-Anforderungen §2–6, Monitoring §7, Statusseite §8 und Testkatalog §9. Als damalige Vorgabe, nicht als Beweis der Umsetzung. |
| Q03 | markdown(4).md eingefügt | 506 Zeilen; ursprünglicher Ergänzungsauftrag zu Sync, Navigation, Export, Multiaccount, Registrierung und 90-Tage-Gerätefreigabe. |
| Q04 | markdown(20260906-194219).md eingefügt | 336 Zeilen; damalige Repository-/README-Ansicht. Rollen von Caddy/Traefik, GitHub-SSH-Deployment, frühe Ressourcen-/Stackzuordnung. Historische Basisdienste später teilweise abgelöst. |
| Q05 | markdown(20260906-220742).md eingefügt | 1031 Zeilen; Hostsetup, deploy-Benutzer, Coolify-Erkennung, UFW, fail2ban, unattended-upgrades; insbesondere Setupbereich um Z. 623–745 und abgelehnte sshd-Härtung. |
| Q06 | Eingefügter Text(20260906-214647).txt | Frühe Metrics-/Heartbeat-Diagnose, Composeanpassungen und temporäre Wiederherstellung. Nicht als finale Signal-/Healthcheckkonfiguration ausgegeben. |

Die automatisch erzeugten Masterprompts enthalten ihrerseits damalige technische Referenzen. In dieser Dokumentation werden deren konkrete Projektvorgaben zusammengefasst; eine erneute Gültigkeitsprüfung dieser externen Quellen oder aktueller Anbieterprodukte ist nicht Teil des Auftrags.

**Nachweise:** Die Kennungen Q01–Q13 werden in den Kapiteln verwendet. Zeilen beziehen sich auf die ursprünglichen Dateien, soweit angegeben.

## 44. Quellenregister II: Betrieb und Abschluss

Die späteren Protokolle enthalten teils wiederholte frühere Ausgaben. Maßgeblich ist jeweils die zugeordnete letzte Beobachtung.

| Quelle | Datei / Gesprächsbestand | Verwendeter Umfang |
|---|---|---|
| Q07 | Eingefügter Text(20260907-024626).txt | 925 Zeilen. Redis-/Candidate-/Migrationspfad, Created-Zustand, Docker-Versionen und Daemonlogs, 11-Minuten-Stopp, Abschluss aadefb80 um 02:44:21Z, finale healthy-Container und current-Symlink. |
| Q08 | markdown(20260907-075849).md eingefügt | 114 Zeilen. Abschlussbericht Version 4.11; Root Causes Z. 61–65, Signalstrategie Z. 75–88, SSH/Polling Z. 90–94, Tests Z. 96–104, Commits Z. 106–109, offene Punkte Z. 114. |
| Q09 | Eingefügter Text(20260907-083958).txt | 837 Zeilen. Kumulierte Endbelege: c4fabc-Status, HTTP/TLS/SAN, DB-Konfiguration und Container, persistentes Volume, Scheduler, Backupdateien, S3 und Backupplan, Löschen alter Configs sowie letzter DB-Check. |
| Q10 | markdown(7).md eingefügt | 173 Zeilen. Historischer IONOS-DNS-Auszug, alte/neue A-/AAAA-Ziele und IONOS-Mailrecords. Kein Abschlussbeleg heutiger Werte. |
| Q11 | SmartEinzug_Gesamtprompt_Systemmonitoring_Statusseite.md | Frühere 981-zeilige Vorgabenfassung; Einordnung über nachfolgend konsolidierten Hostinger-Gesamtprompt Q02. |
| Q12 | Weitere im Chat hochgeladene Terminal- und UI-Protokolle | Zeitlich überlappende Vorläufe vom 06.09.2026 und der Nacht zum 07.09.2026; für ergänzende Suche und Vergleich genutzt. Keine fingierte vollständige Einzelabnahme jeder protokollierten UI-Option. |
| Q13 | MH_AG_Google_Ads_Keywordanalyse_SEPA_2026-09-05.pdf | Vorhandene MH-AG-Referenz aus der Dateibibliothek, Seite 1: Original-Wortbildmarke und Gestaltung. Ausschließlich CI-Referenz, keine technische SmartEinzug-Betriebsquelle. |

Der für die Recherche sichtbare Dateibestand des Gesprächs umfasste 43 Nutzer-Uploads und zwei zuvor erzeugte Masterprompt-Dateien. Diese Dokumentation ist eine konsolidierte Fassung und keine ungefilterte Veröffentlichung dieses gesamten Bestands. Die eingebrachten echten Konfigurationsdateien, Sitzungskennungen und Zugangswerte werden nicht vervielfältigt.

**Nachweise:** Q07–Q13; keine Passwörter oder vollständigen Secret-Dateien als Anhang übernommen.

## 45. Begriffe und Abschlussvermerk

Kurze Begriffe für die gemeinsame Arbeit von Geschäftsführung und Technik.

| Begriff | Bedeutung in diesem Projekt |
|---|---|
| Release / SHA | Eindeutiger Git-Commit und zugehöriger Codebestand unter releases/<SHA>. |
| current | Symlink auf den aktivierten Release; später vor allem Buchführung, nicht alleinige Laufzeitbindung. |
| Candidate | Neuer Codebestand, der vor der Freigabe isoliert auf Voraussetzungen geprüft wird. |
| Cutover | Kontrollierter Übergang der produktiven Laufzeit auf den neuen Release. |
| Runner / Polling | Serverseitiger Ausführungsprozess und davon getrennte wiederholte Statusabfragen durch GitHub. |
| Worker / Scheduler | Worker bearbeitet Aufgaben; Scheduler reiht zeitgesteuerte Aufgaben ein. |
| Heartbeat | Regelmäßiges Lebenszeichen eines Prozesses oder Auftrags; Frische ist entscheidend. |
| Idempotenz | Wiederholte Bearbeitung desselben fachlichen Vorgangs soll keine zusätzliche Zahlung oder Doppelbuchung erzeugen. |
| Volume / Bind-Mount | Separater Docker-Datenspeicher beziehungsweise eingebundener Hostpfad; beide getrennt vom austauschbaren Container. |
| SAN / TLS | Im Zertifikat genannte gültige Hostnamen und geschützter Verbindungsaufbau. |
| Offsite / Restore | Sicherung außerhalb des VPS und Wiederherstellung daraus; beide Nachweise separat erfassen. |

Abschlussvermerk: Der Infrastruktur- und Datenbankumzug ist im dokumentierten Umfang erfolgreich. Die alte IONOS-Anwendungsdatenbank wurde laut Nutzer gelöscht; der anschließende DB-Check war OK. Alte lokale Konfigurationssicherungen sind entfernt. Coolify-Backupplan und S3-Ziel sind konfiguriert; ein lokaler Dump und die Nutzerbestätigung zum Restore liegen vor. Fachliche Applikationstests bleiben ausdrücklich vertagt.

Diese Ausgabe wurde als PDF mit beschrifteten technischen Vektordiagrammen und als inhaltsgleiche UTF-8-Textfassung erstellt. Alle Befehlsblöcke sind Dokumentation für den späteren Gebrauch. Eine Ausführung auf dem VPS oder eine neue technische Abnahme fand bei der Erstellung nicht statt.

**Nachweise:** Begriffe werden im Sinne der Projektquellen verwendet. Abschlussstand: Q01, Q08 und Q09.
