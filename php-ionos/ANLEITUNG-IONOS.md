# SEPA-Portal (PHP) – Installation auf IONOS Webhosting per FTP

Diese Version läuft auf normalem IONOS Webhosting (kein VPS, kein Docker nötig).
Voraussetzungen: IONOS Webhosting-Paket mit PHP 8.1 oder neuer und einer MariaDB-Datenbank.

## 1. Subdomain anlegen

1. IONOS Kundenbereich > Domains & SSL > muellerhv.de > Subdomain anlegen: `sepa.muellerhv.de`
2. SSL-Zertifikat für die Subdomain aktivieren (bei IONOS im Paket enthalten).
3. Unter Hosting > Webspace der Subdomain das Zielverzeichnis zuweisen, z.B. `/sepa`.

## 2. Datenbank anlegen

1. IONOS Kundenbereich > Hosting > Datenbanken > Neue Datenbank (MariaDB/MySQL).
2. Notieren: Hostname (z.B. `db5001234567.hosting-data.io`), Datenbankname, Benutzer, Passwort.
3. phpMyAdmin öffnen und die Datei `sql/schema.sql` importieren
   (Reiter "Importieren" > Datei auswählen > OK).

## 3. Konfiguration erstellen

1. `app/config.example.php` lokal nach `app/config.php` kopieren.
2. Werte eintragen:
   - Datenbankzugangsdaten aus Schritt 2
   - `app_secret`: 64 Zufallszeichen (z.B. mit `openssl rand -hex 32` generieren)
   - `cron_token`: 32 Zufallszeichen
   - `base_url`: `https://sepa.muellerhv.de`
3. Wichtig: `app_secret` nach Inbetriebnahme nie mehr ändern, sonst sind die
   verschlüsselt gespeicherten API-Keys nicht mehr lesbar.

## 4. Upload per FileZilla (FTP/SFTP)

Verbindung: IONOS Kundenbereich > Hosting > SFTP- und SSH-Zugang
(SFTP bevorzugen, Protokoll "SFTP", Port 22; alternativ FTPS).

Kompletten Inhalt des Ordners `php-ionos/` in das Verzeichnis der Subdomain
hochladen (z.B. `/sepa`):

```
/sepa/
├── .htaccess
├── index.php, login.php, register.php, logout.php
├── onboarding.php, dashboard.php
├── invoices.php, collections.php, customers.php
├── team.php, invite.php, settings.php
├── stripe-webhook.php, cron.php
├── assets/css/style.css
├── app/            (inkl. der lokal erstellten config.php)
└── sql/            (optional, wird nur für den Import gebraucht)
```

Hinweise:
- In FileZilla "Versteckte Dateien anzeigen" aktivieren (Server-Menü), sonst
  wird die `.htaccess` nicht übertragen.
- Die `.htaccess` sperrt den Webzugriff auf `app/` und `sql/`.

## 5. Cronjob einrichten (terminierte Einzüge)

IONOS Kundenbereich > Hosting > Cronjobs > Neuer Cronjob:

- URL: `https://sepa.muellerhv.de/cron.php?token=<cron_token aus config.php>`
- Zeitplan: täglich 06:00 Uhr

Der Cron reicht alle fälligen terminierten Lastschriften bei Stripe ein.
Fällt ein Lauf aus, holt der nächste Lauf die fälligen Einzüge nach.

## 6. Erste Einrichtung im Browser

1. `https://sepa.muellerhv.de` aufrufen > "Neue Organisation registrieren".
2. Nach der Registrierung führt das Onboarding durch:
   - Lexoffice API-Key hinterlegen (Einstellungen)
   - Stripe Secret Key hinterlegen (Einstellungen)
   - Erste Synchronisation ausführen (Rechnungen)
3. Danach in `app/config.php` `'allow_registration' => false` setzen und die
   Datei erneut hochladen, damit sich keine Fremden registrieren können.

## 7. Stripe-Webhook konfigurieren

Stripe-Dashboard > Entwickler > Webhooks > Endpunkt hinzufügen:

- URL: `https://sepa.muellerhv.de/stripe-webhook.php`
- Events: `payment_intent.processing`, `payment_intent.succeeded`,
  `payment_intent.payment_failed`, `charge.dispute.created`
- Das angezeigte Signing Secret (`whsec_...`) im Portal unter
  Einstellungen > Stripe als Webhook-Secret speichern.

## 8. Corporate Identity (HVM)

Das Layout nutzt zentrale CSS-Variablen in `assets/css/style.css`
(Block `:root` am Dateianfang). Dort sind Primärfarbe, Akzentfarbe und
Schrift als Platzhalter gekennzeichnet und müssen durch die verbindlichen
Werte aus dem CI-Handbuch der Hausverwaltung Müller GmbH ersetzt werden.
Ein Logo wird als `assets/img/logo.svg` abgelegt und automatisch im Kopf
angezeigt. Die Pflichtangaben im Fußbereich sind in `app/layout.php`
(Funktion `layout_footer`) zu ergänzen.

## Updates einspielen

Geänderte Dateien einfach per FileZilla erneut hochladen und überschreiben.
Die `app/config.php` auf dem Server dabei nicht überschreiben.

## Sicherheit und Hinweise

- API-Keys werden AES-256-verschlüsselt in der Datenbank gespeichert.
- Alle Formulare sind CSRF-geschützt; Sitzungen laufen über sichere Cookies.
- `app/` und `sql/` sind per .htaccess für den Browser gesperrt. Test:
  `https://sepa.muellerhv.de/app/config.php` muss "Forbidden" liefern.
- Rechtlicher Hinweis: SEPA-Lastschriften setzen ein gültiges, unterschriebenes
  SEPA-Lastschriftmandat des Zahlungspflichtigen voraus. Die Verantwortung für
  das Vorliegen der Mandate liegt beim Verwender.
