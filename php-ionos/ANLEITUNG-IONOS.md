# Lexware-Einzug (SEPA-Portal) auf IONOS Webhosting: Installation und Betrieb

Betreiber: Müller Holding AG. Die Anwendung läuft auf normalem IONOS Webhosting
(PHP 8.1 oder neuer, MariaDB), ohne Composer, ohne Docker.

Bestandteile des Repositories:

| Ordner | Inhalt | Ziel |
|---|---|---|
| `php-ionos/` | Anwendung (Registrierung, Login mit 2FA, Dashboard, Rechnungen, Einzüge, Kunden, SEPA Pflegen, Firma, Einstellungen, Admin) | `app.lexware-einzug.de` (heute noch `sepa.muellerhv.de`) |
| `websites/lexware-einzug.de/` | Marketingseite für die aktuelle Bezeichnung Lexware Office | `lexware-einzug.de` |
| `websites/lexoffice-einzug.de/` | eigenständige Marketingseite für den früheren Namen lexoffice | `lexoffice-einzug.de` |

## 1. Bestehende Installation aktualisieren (Update von der Mehrfirmen-Version)

1. Alle Dateien aus `php-ionos/` per FileZilla hochladen und überschreiben. Die
   `app/config.php` auf dem Server NICHT überschreiben.
2. `app/config.php` um die neuen Schlüssel ergänzen (Vorlage `app/config.example.php`):
   `product_name`, `marketing_url`, `operator`, `signup_domains`, `require_2fa`,
   `mail`, `lexware_api_base_url`, `stripe_mandate_reference_prefix`, `billing`.
   Fehlende Schlüssel werden mit sinnvollen Standardwerten behandelt, die
   Ergänzung ist aber empfohlen.
3. Datenbank migrieren: phpMyAdmin öffnen, Datenbank wählen, Reiter SQL,
   Inhalt von `sql/migrations/003_saas_2fa_roles_plans.sql` einfügen und ausführen
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
| `base_url` | Adresse der Anwendung, z.B. `https://app.lexware-einzug.de` |
| `product_name` | Produktname in Titel, E-Mails und Authenticator-App (Lexware-Einzug) |
| `marketing_url` | Marketingseite für Links auf Datenschutz und AGB |
| `operator` | Impressumsdaten der Müller Holding AG (Telefon und USt-IdNr. sind Platzhalter) |
| `signup_domains` | Erlaubte Herkunftsdomains für `?src=` und `track.php` |
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
- Plattform-Administrator (Superadmin, `admin.php`): Kennzahlen je Herkunft,
  Tarife, Firmen, Support-Funktionen. Freischalten per SQL:

```sql
UPDATE users SET is_superadmin = 1 WHERE email = 'ihre-adresse@example.de';
```

Für `admin.lexware-einzug.de` die Subdomain auf dasselbe Verzeichnis zeigen
lassen; die Seite `admin.php` prüft die Berechtigung selbst.

Notfall (Authenticator und Recovery-Codes eines Benutzers verloren): der
Superadmin setzt die 2FA unter Admin > Support zurück (wird als Support-Reset
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
2. Webhook-Endpunkt `https://app.lexware-einzug.de/billing-webhook.php` mit den
   Events `checkout.session.completed`, `customer.subscription.created`,
   `customer.subscription.updated`, `customer.subscription.deleted`,
   `invoice.payment_failed` anlegen und das Signing Secret in
   `billing.stripe_webhook_secret` eintragen.
3. Kundenportal in Stripe aktivieren (Zahlungsmethode ändern, Rechnungen, Kündigung).
4. `billing.enabled = true` setzen. Neue Firmen müssen dann das Abo abschließen,
   bevor Rechnungen, Einzüge und Kunden nutzbar sind. Bestandsfirmen bleiben befreit.

Spätere Tarife (BASIC, PLUS, PRO, UNLIMITED) sind angelegt, aber inaktiv und
nicht öffentlich. Ein Wechsel auf einen Tarif mit weniger Benutzern wird
abgelehnt, solange mehr Benutzer oder offene Einladungen vorhanden sind.

## 7. Cronjob (optional, empfohlen)

IONOS Kundenbereich > Hosting > Cronjobs, alle 5 bis 15 Minuten:

```
https://app.lexware-einzug.de/cron.php?token=<cron_token aus config.php>
```

Der Cron reicht fällige terminierte Lastschriften ein und setzt laufende
Synchronisationen im Hintergrund fort. Ohne Cron funktioniert alles weiterhin
über die Buttons; die Synchronisation läuft dann, solange die Rechnungsseite
geöffnet ist, und wird beim nächsten Öffnen fortgesetzt.

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
- Die Seiten senden cookielose Zählereignisse an `app.lexware-einzug.de/track.php`
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
  `https://app.lexware-einzug.de/app/config.php` muss "Forbidden" liefern.
- Rechtlicher Hinweis: SEPA-Lastschriften setzen ein gültiges Mandat des
  Zahlungspflichtigen voraus. Die Verantwortung für das Vorliegen der Mandate
  liegt bei der jeweiligen Firma.
