# Architektur und Systemlandschaft

Stand 07.09.2026, Version 4.32. Verständliche Übersicht zuerst, danach die technischen Einzelheiten. Kennzeichnung: **nachgewiesen** (aus Deployments oder Betreiberausgaben vom 07.09.2026 belegt), **im Code** (im Repository vorhanden), **konfiguriert, nicht geprüft**, **offen** (Prüffrage).

## Übersicht

SmartEinzug ist eine PHP-Anwendung (PHP 8.4, MariaDB, ohne Composer), die offene Rechnungen aus dem Buchhaltungssystem einer Firma liest und per SEPA-Basislastschrift über das Stripe-Konto derselben Firma einzieht. Die Anwendung kennt drei Hosts (Kundenanwendung, Adminbereich, Webhook-Host), eine Warteschlange mit Scheduler und Workern für Synchronisation, Einzüge, Mails und Wartung, sowie eine öffentliche Statusseite. Die statischen Websites liegen getrennt auf dem IONOS-Webhosting.

@@diagramm 01-produktuebersicht

## Zwei Hosts: IONOS-Webhosting und Hostinger-VPS

- **IONOS-Webhosting (nachgewiesen für die Websites durch den Job `deploy-webhosting`):** statische Seiten `websites/smart-einzug.de`, `websites/lexoffice-einzug.de`, `websites/lexware-einzug.de`, `websites/lastschrift-einfach.de`; Auslieferung per SFTP. Historisch lief dort auch die Anwendung (`README.md`: `sepa.muellerhv.de`); ob diese Instanz noch erreichbar ist und ob der IONOS-Cronjob gelöscht wurde, ist **offen** (Cutover-Checkliste Punkt 12, `docs/ARBEITSSTAND.md`).
- **Hostinger-VPS (nachgewiesen):** Deployments 4.20 bis 4.31 am 07.09.2026 erfolgreich (`.deploy-status.json` phase success, `releases/current` auf dem Release), Container laufen (Ausgabe `restart-workers.sh`), Health-Check über Caddy mit Host `app.smart-einzug.de` erfolgreich. Ob die öffentlichen DNS-Einträge für `app.`, `admin.`, `api.` und `status.smart-einzug.de` auf den VPS zeigen, ist im Repository nicht belegt (**Prüffrage:** `dig +short app.smart-einzug.de` muss `72.61.80.67` liefern). Die Betriebsdokumentation in `docs/vps/08-hostinger-coolify.md` nennt den Cutover noch als offen; der Kundenverkehr der Vorregistrierung am 07.09.2026 wurde jedoch auf dem VPS verarbeitet (Wartemarken in der VPS-Datenbank, Nachsendung nach Aktivierung des Mailversands), was für einen erfolgten Cutover spricht.

@@diagramm 02-zwei-host-architektur

## Komponenten und Verbindungen

| Verbindung | Richtung | Protokoll und Authentifizierung | Daten | Fehlerverhalten | Vertrauensgrenze |
|---|---|---|---|---|---|
| Browser → Traefik → Caddy → php-fpm | eingehend | HTTPS (Let's Encrypt am Coolify-Proxy), intern HTTP; Sitzung mit 2FA-Pflicht | Formulare, Seiten | HTTP-Fehlerseiten, Ratenbegrenzung Login | Traefik ist die Außengrenze; Caddy trennt Hosts, PHP erzwingt Host-Regeln zusätzlich |
| php-fpm und Worker → Coolify-MariaDB | ausgehend, Coolify-Netz | MySQL-Protokoll, Benutzer und Passwort aus `shared/config.php`, kein öffentlicher Port | alle Anwendungsdaten | Ausnahme, Health-Check `--db` schlägt an | privates Docker-Netz |
| php-fpm, Scheduler, Worker → Redis | intern | RESP, Alias `smarteinzug-redis`, nur internes Netz, `protected-mode no` | Warteschlange, Sperren | `bin/healthcheck.php --redis` diagnostiziert stufenweise | Netz `smarteinzug_internal` |
| Worker → Lexware Office API | ausgehend | HTTPS, Bearer-Token der Firma (verschlüsselt gespeichert) | nur lesend: Belege, Rechnungen, Kontakte, Zahlungsstand | Drosselung 2/s je Firma, Circuit Breaker `api_call_gate('lexoffice')` | Fremdsystem der Firma |
| Worker und php-fpm → Stripe API | ausgehend | HTTPS, geheimer Schlüssel der Firma (verschlüsselt); Plattform-Abrechnung mit Schlüssel der Müller Holding AG aus der Konfiguration | Kunden, Zahlungsmethoden, PaymentIntents, Checkout-Sitzungen | Fehlerklassen, Klärung unklarer Versuche | Fremdsystem, zwei getrennte Konten |
| Stripe → api-Host | eingehend | HTTPS, Signaturprüfung mit Webhook-Secret der Firma bzw. der Plattform | Ereignisse | immer HTTP 200, Verwerfen bei ungültiger Signatur, Idempotenz | öffentlicher Endpunkt, keine Sitzung |
| Anwendung → IONOS SMTP | ausgehend | SMTP mit TLS, Benutzer und Passwort aus der Konfiguration | E-Mails aus Vorlagen | Marker `mail_last_fail_at`, Nachsenden wartender Mails | Fremdsystem |
| GitHub Actions → VPS | ausgehend vom Runner | SSH mit Schlüssel, `StrictHostKeyChecking=yes`, Wiederholung bei Netzfehlern | Release-Dateien | Abbruch ohne Serveränderung, Statusdatei | Runner-Adressen wechseln (Firewall-Fragen in `docs/vps/06-betrieb.md`) |
| GitHub Actions → IONOS | ausgehend | SFTP (Secrets `SFTP_*`) | Websites | Jobfehler | Fremdsystem |

Datenfluss und Zahlungsfluss sind getrennt: Geld fließt ausschließlich zwischen dem Bankkonto des Debitors und dem Stripe-Konto der Firma (Auszahlung durch Stripe an die Firma). SmartEinzug hält kein Geld und ist kein Zahlungsdienstleister; für das Abonnement der Firma bei der Müller Holding AG zieht Stripe im Auftrag der Müller Holding AG ein (getrenntes Stripe-Konto, `app/billing.php`).

@@diagramm 03-domains-dienste

## Hintergrundverarbeitung

Feature-Flag `features.queue`: auf dem VPS aktiv (Warteschlange in Redis, Scheduler und Worker als eigene Container), auf dem Webhosting inaktiv (`cron.php`). Details in den Kapiteln Cronjobs und Hintergrundprozesse sowie Hintergrundverarbeitung.

@@diagramm 10-hintergrundprozesse

## Wo die Wahrheit liegt

- Tarife: Tabelle `plans`. Freigabeschalter: Tabelle `platform_settings`. Rechtsdokumente: `legal_documents`. Konfiguration: `shared/config.php` (außerhalb der Releases, Einzeldatei-Bind-Mount). Release-Bindung: `RELEASE_SHA` in Compose, `releases/current` nur für Menschen.
- Diagrammquellen: `docs/diagramme/*.mmd`. Datenmodell: `php-ionos/sql/schema.sql` plus Migrationen `php-ionos/sql/migrations/`.
