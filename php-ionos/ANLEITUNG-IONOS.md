# SmartEinzug (SEPA-Portal) auf IONOS Webhosting: Installation und Betrieb

Betreiber: Müller Holding AG. Die Anwendung läuft auf normalem IONOS Webhosting
(PHP 8.1 oder neuer, MariaDB), ohne Composer, ohne Docker.

Bestandteile des Repositories:

| Ordner | Inhalt | Ziel |
|---|---|---|
| `php-ionos/` | Anwendung (Registrierung, Login mit 2FA, Dashboard, Rechnungen, Einzüge, Kunden, SEPA Pflegen, Firma, Einstellungen, Admin) | heute `app.smart-einzug.de`, künftig `app.smart-einzug.de` und `admin.smart-einzug.de` (Abschnitt 11) |
| `websites/smart-einzug.de/` | Hauptwebsite der Produktmarke SmartEinzug | `smart-einzug.de` |
| `websites/lexware-einzug.de/` | Marketingseite für die aktuelle Bezeichnung Lexware Office | `lexware-einzug.de` |
| `websites/lexoffice-einzug.de/` | eigenständige Marketingseite für den früheren Namen lexoffice | `lexoffice-einzug.de` |

## 1. Bestehende Installation aktualisieren (Update von der Mehrfirmen-Version)

1. Alle Dateien aus `php-ionos/` per FileZilla hochladen und überschreiben. Die
   `app/config.php` auf dem Server NICHT überschreiben.
2. `app/config.php` um die neuen Schlüssel ergänzen (Vorlage `app/config.example.php`):
   `product_name`, `marketing_url`, `operator`, `signup_domains`, `require_2fa`,
   `mail`, `lexware_api_base_url`, `stripe_mandate_reference_prefix`, `billing`,
   sowie `app_base_url`, `admin_base_url`, `public_base_url`, `allowed_hosts`
   (Abschnitt 11).
   Fehlende Schlüssel werden mit sinnvollen Standardwerten behandelt, die
   Ergänzung ist aber empfohlen.
3. Datenbank migrieren: phpMyAdmin öffnen, Datenbank wählen, Reiter SQL,
   Inhalt von `sql/migrations/003_saas_2fa_roles_plans.sql` einfügen und ausführen,
   danach in dieser Reihenfolge 004_integration_verification.sql, 005_mandate_files.sql,
   006_payment_safety.sql und 007_refunds_alerts.sql (alle wiederholbar). Neuinstallationen
   brauchen nur sql/schema.sql.
   (einmalig; die ALTER-Befehle sind wiederholbar, die UPDATE-Befehle am Ende
   sind für Bestandsdaten gedacht).
4. `setup-check.php` aufrufen: alle 17 Tabellen müssen vorhanden sein.
5. Anmelden. Beim ersten Login wird für jeden bestehenden Benutzer die
   Zwei-Faktor-Authentifizierung eingerichtet (Authenticator-App, QR-Code,
   Recovery-Codes). Ohne diesen Schritt ist die Anwendung nicht nutzbar.

Was die Migration mit Bestandsdaten macht: bestehende Benutzer gelten als
E-Mail-verifiziert, bestehende Firmen sind von der Plattform-Abrechnung befreit
(`billing_exempt = 1`, Tarif UNLIMITED START, unbegrenzte Benutzer) und dürfen
weiterhin ohne erfasste Mandatsunterschrift einziehen (`require_signed_mandate = 0`,
unter "Firma" umstellbar).

## 2. Neuinstallation

1. Subdomain anlegen (IONOS Kundenbereich > Domains & SSL), SSL aktivieren,
   Zielverzeichnis zuweisen (z.B. `/app`).
2. Datenbank anlegen (Hosting > Datenbanken), `sql/schema.sql` per phpMyAdmin importieren.
3. `app/config.example.php` nach `app/config.php` kopieren und ausfüllen
   (Datenbank, `app_secret` mit `openssl rand -hex 32`, `cron_token`, `base_url`).
4. Inhalt von `php-ionos/` hochladen (in FileZilla "Versteckte Dateien anzeigen",
   sonst fehlt die `.htaccess`).
5. `setup-check.php` aufrufen, danach vom Server löschen.
6. Registrieren, 2FA einrichten, Onboarding durchlaufen.

## 3. Konfiguration im Überblick (`app/config.php`)

| Schlüssel | Bedeutung |
|---|---|
| `base_url` | Bisherige Adresse der Anwendung, z.B. `https://app.smart-einzug.de`; Rückfallwert für `app_base_url` |
| `app_base_url` | Adresse der Kundenanwendung für alle absoluten Links (leer = `base_url`) |
| `admin_base_url` | Adresse des Adminbereichs; leer = Übergangsmodus, `admin.php` auf demselben Host erreichbar |
| `public_base_url` | Öffentliche Produktwebsite, `https://smart-einzug.de` |
| `allowed_hosts` | Liste erlaubter Hostnamen; leer = keine Prüfung; gefüllt = andere Hosts erhalten 404 |
| `product_name` | Produktname in Titel, E-Mails und Authenticator-App (Standard SmartEinzug) |
| `marketing_url` | Rückfallwert für `public_base_url` (Links auf Datenschutz und AGB) |
| `operator` | Impressumsdaten der Müller Holding AG (Telefon und USt-IdNr. sind Platzhalter) |
| `signup_domains` | Erlaubte Herkunftsdomains für `?src=` und `track.php`; genau einmal in der Datei eintragen, bei doppeltem Schlüssel gilt nur der letzte |
| `cron_time_budget_seconds` | Zeitbudget je Cron-Aufruf in Sekunden (Standard 20, Abschnitt 7) |
| `allow_registration` | Registrierung neuer Firmen erlauben (für den Marktstart `true`) |
| `require_2fa` | 2FA-Pflicht (immer `true`; nur im Notfall kurz `false`) |
| `mail` | E-Mail-Versand (siehe Abschnitt 5) |
| `lexware_api_base_url` | `https://api.lexware.io/v1`; bei Verbindungsfehlern wird automatisch `api.lexoffice.io` versucht |
| `stripe_mandate_reference_prefix` | Stripe-Mandatsreferenz mit Firmenpräfix beginnen lassen (erst nach Test aktivieren) |
| `billing` | Plattform-Abrechnung über das Stripe-Konto der Müller Holding AG (Abschnitt 6) |

## 4. Rollen und Zugänge

- Inhaber (registrierende Person): alles, insbesondere Mitarbeiter einladen,
  entfernen, sperren, Rollen ändern, Inhaberschaft übertragen (Passwort und 2FA),
  Abonnement.
- Administrator: wie Mitarbeiter, zusätzlich API-Verbindungen und Firmendaten.
- Mitarbeiter: voller operativer Zugriff (Synchronisieren, Rechnungen, Einzüge,
  Kunden, Mandate, SEPA pflegen). Jede Aktion wird namentlich protokolliert
  ("Firma" > Protokoll, Spalte "Ausgelöst von" bei Einzügen).
- Plattform-Administrator (Superadmin, `admin.php`): Kennzahlen je Herkunft
  mit Diagrammen, Tarife direkt bearbeiten (Name, Preis, Limits, Sichtbarkeit,
  Stripe-Preis-ID, jede Änderung mit 2FA-Code), Firmen. Bereich Support
  (`admin-support.php`): "Auf Firma wechseln" (Support-Zugriff, siehe unten),
  Konten entsperren, 2FA zurücksetzen, Protokoll. Freischalten per SQL:

```sql
UPDATE users SET is_superadmin = 1 WHERE email = 'ihre-adresse@example.de';
```

Für `admin.smart-einzug.de` die Subdomain auf dasselbe Verzeichnis zeigen
lassen; die Seite `admin.php` prüft die Berechtigung selbst. Sobald
`admin_base_url` gesetzt ist, antwortet `admin.php` nur noch auf diesem Host
(Abschnitt 11).

Support-Zugriff ("Auf Firma wechseln", Migration 008): Der Superadmin gibt
unter Support einen Grund (z.B. Ticketnummer) und den aktuellen 2FA-Code ein und
arbeitet danach höchstens 60 Minuten in der Kundenanwendung dieser Firma mit
der Rolle Administrator. Der Einmal-Link ist 5 Minuten gültig, der Inhaber der
Firma erhält eine Sicherheits-E-Mail, jede Aktion trägt im Protokoll der Firma
den Vermerk `support_session`. Gesperrt sind im Support-Modus Einzüge, IBAN-
Änderungen und Zugangsdaten (Lexware-Schlüssel, Stripe-Schlüssel, Webhook-
Secret). Beenden über den gelben Hinweis oder unter Support > Aktive Sitzungen.

Notfall (Authenticator und Recovery-Codes eines Benutzers verloren): der
Superadmin setzt die 2FA unter Support zurück (wird als Support-Reset
protokolliert, Benutzer erhält eine Sicherheits-E-Mail). Ohne Superadmin per SQL:

```sql
UPDATE users SET totp_enabled = 0, totp_secret_encrypted = NULL, totp_last_step = NULL,
       session_epoch = session_epoch + 1 WHERE email = 'adresse@example.de';
DELETE FROM user_recovery_codes WHERE user_id = (SELECT id FROM users WHERE email = 'adresse@example.de');
```

## 5. E-Mail-Versand

`mail.enabled = true` aktiviert Einladungen per E-Mail, E-Mail-Bestätigung bei
der Registrierung, Passwort-Zurücksetzen, Sicherheitsbenachrichtigungen an den
Inhaber (neuer Mitarbeiter, Entfernung, Verbindungen geändert, 2FA-Reset,
Kündigung) und die optionale Vorabankündigung an Kunden. Bei IONOS muss die
Absenderadresse (`mail.from_address`) zu einer Domain des Hosting-Pakets gehören
(z.B. `noreply@lexware-einzug.de`, Postfach im Kundenbereich anlegen).
Solange `enabled = false` ist, werden Einladungslinks im Portal angezeigt und
die E-Mail-Bestätigung entfällt.

## 6. Plattform-Abrechnung (Abo 25 EUR je 4 Wochen)

1. Im Stripe-Konto der Müller Holding AG ein Produkt "UNLIMITED START" mit
   wiederkehrendem Preis 25,00 EUR alle 4 Wochen anlegen; die Preis-ID
   (`price_...`) im Portal unter Admin > Tarife eintragen.
2. Webhook-Endpunkt `https://app.smart-einzug.de/billing-webhook.php` mit den
   Events `checkout.session.completed`, `customer.subscription.created`,
   `customer.subscription.updated`, `customer.subscription.deleted`,
   `invoice.payment_failed` anlegen und das Signing Secret in
   `billing.stripe_webhook_secret` eintragen.
3. Kundenportal in Stripe aktivieren (Zahlungsmethode ändern, Rechnungen, Kündigung).
4. `billing.enabled = true` setzen. Neue Firmen müssen dann das Abo abschließen,
   bevor Rechnungen, Einzüge und Kunden nutzbar sind. Bestandsfirmen bleiben befreit.
5. Optional `agb_version` in config.php setzen (z. B. "AGB smart-einzug.de, Stand
   01.10.2026"); die Fassung wird bei jeder Bestellbestätigung protokolliert.

Ablauf für den Kunden (nur Inhaber): Ohne aktives Abo erscheint auf jeder Seite
ein Hinweisbalken mit Button; unter Firma > Abonnement stehen Registrierungsdatum,
Tarif, Status, Periodenende und die Buttons Abo kündigen bzw. Vertrag aktivieren.
Vor der Weiterleitung zu Stripe zeigt `subscription.php?bestellen=1` eine
Bestellübersicht (Preis netto, Laufzeit, Kündigung, Vertragspartner) mit
Pflichthäkchen für AGB und Unternehmerbestätigung und dem Button
"Zahlungspflichtig abonnieren"; die Bestätigung wird mit Zeitpunkt, IP und
AGB-Fassung protokolliert (`subscription_consent`). Rechnungen des Abonnements
werden aus Stripe gelesen und unter Abonnement als Archiv angezeigt (Ansehen, PDF).

Spätere Tarife (BASIC, PLUS, PRO, UNLIMITED) sind angelegt, aber inaktiv und
nicht öffentlich. Ein Wechsel auf einen Tarif mit weniger Benutzern wird
abgelehnt, solange mehr Benutzer oder offene Einladungen vorhanden sind.

## 7. Cronjob (empfohlen)

Externer Dienst (cron-job.org) oder IONOS Kundenbereich > Hosting > Cronjobs,
Intervall 5 Minuten (mindestens alle 15 Minuten), nur eine Instanz:

```
https://app.smart-einzug.de/cron.php?token=<cron_token aus config.php>
```

Der Cron reicht fällige terminierte Lastschriften ein, klärt unklare
Einzugsversuche, versendet Alarm-E-Mails, räumt abgelaufene Support-Sitzungen
auf und setzt laufende Synchronisationen fort. Ohne Cron funktioniert alles
weiterhin über die Buttons; die Synchronisation läuft dann, solange die
Rechnungsseite geöffnet ist, und wird beim nächsten Öffnen fortgesetzt.

Laufzeit: Externe Cron-Dienste brechen nach 30 Sekunden ab. `cron.php` begrenzt
die Gesamtlaufzeit deshalb auf `cron_time_budget_seconds` (Standard 20). Die
Synchronisation arbeitet in kleinen Schritten und verteilt die Zeit im
Round-Robin auf alle Firmen (am längsten wartende zuerst, zwei Schritte je
Firma und Runde), ein Erstimport einer Firma blockiert die anderen also nicht.
Nicht erledigte Schritte laufen beim nächsten Aufruf weiter. Faustregel für die
Kapazität: 20 Sekunden je Aufruf, alle 5 Minuten, ergibt rund 96 Minuten
Synchronisationszeit je Tag; reicht das bei vielen Firmen nicht mehr, Intervall
verkürzen (cron-job.org erlaubt 1 Minute) oder auf IONOS-Cron ohne 30-Sekunden-
Grenze wechseln und `cron_time_budget_seconds` auf 90 erhöhen.

## 7b. Datenbankmigrationen automatisch

Neue Versionen bringen ihre Migrationsdateien unter `sql/migrations/` mit
(per Web nicht abrufbar). Der Cron spielt ausstehende Migrationen bei jedem
Lauf automatisch ein, spätestens also fünf Minuten nach einem Upload. Sofort
einspielen und den Stand prüfen:

```
https://app.smart-einzug.de/migrate.php?token=<cron_token aus config.php>
```

Jede Migration hat einen Marker (Tabelle oder Spalte); vorhandene Marker
gelten als eingespielt, nichts wird doppelt ausgeführt. Der Stand steht in
`schema_migrations` und im `setup-check.php`.

Automatisch nach dem Upload: Der GitHub-Workflow `deploy.yml` ruft nach dem
SFTP-Upload `migrate.php` per POST mit dem Header `X-Migration-Token` auf und
erwartet HTTP 200 mit `{"success": true}`. Der Tokenwert steht als GitHub-Secret
`MIGRATION_TOKEN` und in `config.php` als `migration_token` (leer = `cron_token`
gilt). Schlägt eine Migration fehl, wird der Lauf rot und der Fehler steht im
Actions-Protokoll; die Dateien sind dann bereits hochgeladen.

## 7a. Bestehende Einzüge aus Stripe übernehmen (Migration 009)

Nach einem Neuaufbau oder einer neuen Verknüpfung der Firma kennt die
Anwendung Lastschriften nicht, die eine frühere Installation über dasselbe
Stripe-Konto eingereicht hat. Unter Einstellungen > Stripe > "Bestehende
Einzüge aus Stripe übernehmen" (`stripe-import.php`) lädt die Firma die
Zahlungen eines Zeitraums (3 bis 24 Monate, nur Lesezugriff), sieht eine
Vorschau mit Zuordnung über Rechnungsnummer und Betrag und übernimmt die
eindeutigen Treffer mit 2FA-Code als Einzüge mit Herkunft "Import". Die
Rechnungen erhalten den passenden Status, Erstattungen werden wie beim
Webhook übernommen (Klärungsbedarf). Bekannte Zahlungen werden übersprungen,
der Import ist wiederholbar. Mandate und IBANs werden nicht übernommen.

## 7c. Karenzzeit und Einreichfenster (Migration 011)

Ein Sofort-Einzug wird nicht mehr direkt an Stripe übergeben, sondern
"vorgemerkt": Einreichung frühestens `collections.grace_hours` Stunden nach
dem Auslösen (Standard 4) und nur im Einreichfenster
`collections.window_start` bis `collections.window_end` (Standard 23:00 bis
06:00). Bis zur Einreichung kann jeder Einzug unter Einzüge storniert werden;
die Rechnung ist danach wieder offen. Der Cron reicht im Fenster bei jedem
Lauf einen Teil ein (höchstens die Hälfte des Zeitbudgets), der Rest folgt im
nächsten Lauf. Terminierte Einzüge werden am Fälligkeitstag ebenfalls nur im
Fenster eingereicht.

Termine, die länger als `collections.overdue_days` Tage (Standard 3)
zurückliegen, zum Beispiel nach einem Not-Stopp, werden nicht automatisch
nachgeholt, sondern als überfällig angezeigt und müssen neu terminiert oder
storniert werden. Inhaber und Administratoren können fällige Einzüge
ausnahmsweise außerhalb des Fensters einreichen (2FA-Code, protokolliert als
`collections_due_forced`). Der Not-Stopp bietet beim Aktivieren an, alle
vorgemerkten und terminierten Einzüge gesammelt zu stornieren.

## 7d. Hilfe-Center und Support-Anfragen (Migration 012)

Menüpunkt Hilfe (`hilfe.php`): Anleitungen und häufige Fragen aus
`app/help_content.php` (reines Inhaltsarray, dort pflegen), Suche, Formular
für Support-Anfragen. Anfragen landen in `support_tickets` mit Verlauf
(`support_ticket_messages`), der Betreiber antwortet unter Support >
Support-Anfragen (`admin-support.php`). Benachrichtigungen per E-Mail, sofern
der Mailversand aktiv ist: an `support_email` (leer = `operator.email`) bei
neuer oder ergänzter Anfrage, an den Fragesteller bei Antwort. Zugangsdaten
(Stripe-Schlüssel, Webhook-Secret) und vollständige IBANs werden im Formular
abgewiesen. Höchstens zehn offene Anfragen je Firma.

## 7e. Synchronisation: Schrittgröße, Änderungserkennung (Migration 013)

Die Synchronisation arbeitet zeitbasiert (`sync.step_seconds`, Standard 8 s je
Aufruf, höchstens `sync.step_max` Detailabrufe). Rechnungen, deren
`updatedDate` laut Voucherliste unverändert ist, werden ohne Detailabruf
übernommen (`sync.skip_unchanged`); Kontakte werden höchstens alle
`sync.contact_refresh_hours` Stunden neu geladen. Messwerte je Lauf stehen in
der Abschlussmeldung und im Protokoll. Rückrollen ohne Codeänderung:
`skip_unchanged` false, `contact_refresh_hours` 0. Details und Messwerte in
docs/sync-performance.md.

## 8. SEPA-Mandate

- Unter "Firma": Anschrift, Gläubiger-Identifikationsnummer (Deutsche Bundesbank,
  wird mit Prüfziffer geprüft), Vorabankündigungsfrist, Pflicht zur erfassten
  Unterschrift.
- Unter Kunden > Kunde: "SEPA-Mandat erzeugen" vergibt die Mandatsreferenz
  automatisch (Firmenpräfix + Kundennummer, bei Folge-Mandaten mit Endung -2, -3)
  und öffnet das Dokument zum Drucken bzw. PDF-Speichern. Nach Rücklauf
  "Unterschrift erfassen" (Datum, Ort).
- Systemseitige Regeln: Referenz maximal 35 Zeichen im SEPA-Zeichensatz, je Firma
  eindeutig; Mandat verfällt nach 36 Monaten ohne Einzug; kein Einzug ohne aktives
  Mandat und (wenn eingestellt) ohne erfasste Unterschrift; Mandate werden nie
  gelöscht, nur widerrufen (Aufbewahrung).
- Vorabankündigung: Standard 14 Tage vor Fälligkeit, durch Vereinbarung verkürzbar
  (steht so im Mandatsdokument). Optional versendet das Portal die Ankündigung per
  E-Mail; dann sind nur terminierte Einzüge mit entsprechender Vorlaufzeit möglich.
- Hinweis: Der technische Einzug läuft über Stripe. Auf dem Kontoauszug des Kunden
  können Gläubiger-ID und Mandatsreferenz von Stripe erscheinen; das Dokument
  weist darauf hin. Wortlaut des Mandats vor dem ersten Einsatz mit der
  Rechtsberatung und den aktuellen Stripe-Vorgaben abstimmen.

## 9. Marketingseiten hochladen

Je Domain ein eigenes Verzeichnis im Hosting anlegen und den Inhalt von
`websites/<domain>/` hochladen (inkl. `.htaccess` für HTTPS, saubere URLs ohne
`.html` und Caching). Vor Veröffentlichung:

- Platzhalter in `impressum.html` (Telefon, USt-IdNr.) füllen.
- `datenschutz.html` und `agb.html` sind Entwürfe und müssen rechtlich geprüft werden.
- Markenrechtliche Prüfung der Domains (fremde Marken im Domainnamen).
- Die Seiten senden cookielose Zählereignisse an `app.smart-einzug.de/track.php`
  und leiten Registrierungen mit `?src=<domain>` weiter; die Auswertung steht
  im Superadmin unter "Funnel je Domain".

## 10. Sicherheit und Hinweise

- API-Keys und 2FA-Geheimnisse werden mit `app_secret` AES-256-verschlüsselt gespeichert.
  `app_secret` nach Inbetriebnahme nie ändern.
- Passwörter als Hash, Rate-Limit (5 Fehlversuche je Adresse in 15 Minuten),
  Anmeldeversuche werden protokolliert.
- Einladungslinks und Passwort-Links sind zufällig, zeitlich begrenzt und nur als
  Hash gespeichert.
- `app/`, `sql/` und `.md`-Dateien sind per `.htaccess` gesperrt. Test:
  `https://app.smart-einzug.de/app/config.php` muss "Forbidden" liefern.
- Rechtlicher Hinweis: SEPA-Lastschriften setzen ein gültiges Mandat des
  Zahlungspflichtigen voraus. Die Verantwortung für das Vorliegen der Mandate
  liegt bei der jeweiligen Firma.

## 11. Umzug auf app.smart-einzug.de und admin.smart-einzug.de

Die Anwendung bleibt in einem einzigen Ordner. Neue und alte Adresse laufen
während des Umzugs parallel, damit E-Mail-Links, Lesezeichen und
Stripe-Webhooks weiter funktionieren. Reihenfolge einhalten, jeden Schritt
prüfen, bevor der nächste folgt.

1. DNS und SSL: Im IONOS Kundenbereich (Domains & SSL) die Subdomains
   `app.smart-einzug.de` und `admin.smart-einzug.de` anlegen und für beide ein
   SSL-Zertifikat aktivieren. Warten, bis beide Hosts per HTTPS antworten.
2. Zielverzeichnis: Beide Hosts auf denselben App-Ordner zeigen lassen, in dem
   heute `app.smart-einzug.de` liegt. Keine zweite Kopie der Anwendung
   anlegen (eine Datenbank, ein `app_secret`, ein Cron).
3. Allowlist ergänzen: In `app/config.php` den Schlüssel `allowed_hosts` mit
   allen drei Hosts füllen, alte Adresse eingeschlossen:

   ```php
   'allowed_hosts' => ['app.smart-einzug.de', 'app.smart-einzug.de', 'admin.smart-einzug.de'],
   ```

   Danach `setup-check.php?token=<cron_token>` über die alte und die neue
   Adresse aufrufen. Die Prüfung "Aktueller Host in der Allowlist" muss
   jeweils bestanden sein. Ein Host, der nicht in der Liste steht, erhält 404.
4. Basisadresse umstellen: `app_base_url` auf `https://app.smart-einzug.de`
   setzen (`base_url` kann stehen bleiben, es dient nur noch als Rückfall).
   Ab jetzt zeigen neue E-Mail-Links, Einladungen, die Rückkehr aus dem
   Stripe Checkout und die angezeigte Webhook-Adresse auf die neue Adresse.
   Bereits versandte Links auf die alte Adresse bleiben gültig, solange der
   alte Host in `allowed_hosts` steht.
5. Stripe-Webhooks: In jedem angebundenen Stripe-Konto der Kunden den Endpunkt
   `https://app.smart-einzug.de/stripe-webhook.php` zusätzlich anlegen und im
   Plattformkonto `https://app.smart-einzug.de/billing-webhook.php`. Die alten
   Endpunkte auf `app.smart-einzug.de` behalten; Ereignisse werden über
   `webhook_events` nur einmal verarbeitet. Kunden mit eigenem Webhook-Secret
   unter "Einstellungen" darauf hinweisen, dass die dort angezeigte Adresse
   jetzt die neue ist. Keine 301-Weiterleitung für POST-Anfragen einrichten.
6. Prüfung: Anmeldung, 2FA, Einladung, Passwort-Zurücksetzen und ein Test-Einzug
   im Stripe-Testmodus über die neue Adresse. Cron-URL bei IONOS auf
   `https://app.smart-einzug.de/cron.php?token=...` umstellen (eine Instanz).
7. Adminbereich trennen: Erst wenn alles über die neue Adresse läuft,
   `admin_base_url` auf `https://admin.smart-einzug.de` setzen. Wirkung:
   `admin.php` liefert auf `app.smart-einzug.de` und `app.smart-einzug.de`
   404; auf `admin.smart-einzug.de` sind nur `admin.php`, Anmeldung, 2FA,
   Passwort-Zurücksetzen, Sicherheit, Abmelden und die Assets erreichbar,
   alle Kundenseiten liefern dort 404. Die Sitzung ist an den Host gebunden,
   der Superadmin meldet sich auf dem Adminhost separat an.
8. Nachlauf: Marketingseiten und Redirect-Domains auf die neue Adresse
   umstellen (Registrierungslinks `register.php?src=<domain>`), Tarif- und
   Preisangaben prüfen. Die alte Adresse frühestens nach Ablauf aller
   versandten Einladungen (7 Tage) und Bestätigungslinks (24 Stunden) aus
   `allowed_hosts` entfernen; Stripe-Endpunkte der alten Adresse erst danach
   löschen.

Rückweg: Jeder Schritt lässt sich durch Zurücksetzen des jeweiligen
Konfigurationsschlüssels aufheben. Datenbankänderungen sind für den Umzug
nicht erforderlich.
