# Mailversand einrichten (Produktion)

Stand: 07.09.2026. Ohne aktiven Mailversand sendet die Anwendung keine E-Mails: keine Willkommens- und
Bestätigungsmail nach der Registrierung, keine Einladungen, keine Sicherheitsmeldungen, keine Vorabankündigungen per
Mail. Vorregistrierungen werden gespeichert und als wartend markiert (Seite „Vormerkung gespeichert, Bestätigungs-E-Mail
folgt“); die Bestätigungsmail wird nach der Aktivierung automatisch nachgesendet. Die Statusseite zeigt die Komponente
„E-Mail-Benachrichtigungen“ als unbekannt.

## 1. Postfach anlegen (Betreiber)

Ein eigenes Absenderpostfach für die Anwendung, zum Beispiel `noreply@smart-einzug.de`, beim Mailanbieter der Domain
anlegen. Empfehlung: Antworten auf `reply_to` (zum Beispiel `info@mueller-holding.ag`) laufen lassen, damit Kunden auf
jede E-Mail antworten können. Für zuverlässige Zustellung beim Domainanbieter SPF, DKIM und DMARC für die Absenderdomain
einrichten (Vorgaben des Mailanbieters verwenden, hier bewusst keine erfundenen Werte).

## 2. Konfiguration eintragen

In `/opt/smarteinzug/shared/config.php` (nur dort, nie im Repository) den Block `mail` füllen:

```php
'mail' => [
    'enabled'      => true,
    'transport'    => 'smtp',
    'from_address' => 'noreply@smart-einzug.de',      // muss zum Postfach passen
    'from_name'    => 'SmartEinzug',
    'reply_to'     => 'info@mueller-holding.ag',
    'smtp' => ['host' => '<smtp-host>', 'port' => 587, 'encryption' => 'tls', 'user' => 'noreply@smart-einzug.de', 'pass' => '<passwort>'],
],
```

## 3. Worker neu starten und prüfen

Die Worker lesen die Konfiguration nur beim Start. Danach die Prüfung im php-Container ausführen und eine Testmail an
die eigene Adresse senden:

```bash
cd /opt/smarteinzug/deploy && export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
bash scripts/restart-workers.sh   # erzeugt Scheduler, Worker und Metrik-Sammler neu und laedt php-fpm neu; ein blosser restart reicht nicht (Einzeldatei-Bind-Mount, OPcache), siehe docs/vps/06-betrieb.md
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php --send=ihre.adresse@example.de
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php --vorabankuendigung --send=ihre.adresse@example.de
```

Die dritte Zeile sendet das Muster der Vorabankündigung (Musterrechnung RE-MUSTER-0001, Mustermandat, 1.234,56 EUR, Betreff
mit Vorsatz MUSTER) an die angegebene Adresse; ohne Server geht dasselbe im Adminbereich unter System, Übersicht,
„Muster der Vorabankündigung an mich senden“ (nur an die eigene Adresse). `--vorabankuendigung --html=DATEI` schreibt die
HTML-Fassung als Vorschau ohne Versand.

`bin/mail-check.php` zeigt Passwörter nie im Klartext. Die Testmail läuft direkt über den Transport (ohne Warteschlange)
und zeigt das Corporate Design mit den Pflichtangaben der Müller Holding AG.

## 4. Fachlich prüfen

1. Registrierung mit eigener Adresse: Willkommensmail mit Bestätigungslink kommt an, Link funktioniert.
2. Vorregistrierung auf smart-einzug.de/integrationen/sevdesk/: Bestätigungsmail mit Bestätigungs- und Abmeldelink, nach
   dem Klick auf „E-Mail-Adresse bestätigen“ eine zweite Mail „Vormerkung ist bestätigt“; Eintrag im Adminbereich unter
   „Vormerkungen“.
3. Statusseite: „E-Mail-Benachrichtigungen“ wechselt nach dem nächsten Monitoringlauf (alle 240 s) auf betriebsbereit.

## Nachsenden bei nicht aktivem Versand (seit 4.22)

Kann eine Bestätigungsmail nicht erzeugt werden (Versand nicht aktiv oder gestört), geht nichts verloren:

- Vorregistrierung: Der Eintrag bleibt `pending` und wird mit `mail_pending = 1` markiert. Die Seite meldet ehrlich
  „Vormerkung gespeichert, Bestätigungs-E-Mail folgt“. `interest_send_pending()` (Wartungsjob und `cron.php`) sendet die
  Mail nach, sobald `mail.enabled` gesetzt ist; Token werden dabei neu erzeugt, die 7 Tage beginnen mit dem Versand.
- Registrierung: `users.welcome_mail_pending = 1`; `auth_send_pending_welcome_mails()` sendet die Willkommensmail nach: mit
  Bestätigungslink, wenn die Adresse noch unbestätigt ist, sonst ohne (bei Registrierung ohne Mailversand gilt die Adresse
  als bestätigt). Nachsendungen laufen über die Mail-Warteschlange (Ratenbegrenzung, Circuit Breaker).
- Migration 022 trägt die Wartemarke für Einträge nach, die vor 4.22 entstanden sind (offene Vormerkungen, Benutzer der
  letzten 30 Tage). Nachgesendete Bestätigungsmails nennen Datum und Herkunft der Eintragung.
- Der Adminbereich zeigt bei nicht aktivem Versand eine Warnung mit der Anzahl wartender Nachsendungen.

Nach der Aktivierung des Versands genügt es also, den Wartungsjob abzuwarten (stündlich) oder ihn über den
Adminbereich, System, Jobs anzustoßen.

## Gestaltung der E-Mails

Alle E-Mails laufen durch `mail_layout()` (`app/mailer.php`): Kopfnaht mit Goldsegment, Wortmarke SmartEinzug, Goldbalken
unter der Überschrift, Fließtext Anthrazit, Gold nur als Akzent, Fußband in Anthrazit mit den Pflichtangaben der Müller
Holding AG (Sitz, Registergericht, HRB, Vorstand, Aufsichtsratsvorsitzender). `php tools/mail-ci-check.php` prüft das.
