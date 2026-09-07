# Mailversand einrichten (Produktion)

Stand: 07.09.2026. Ohne aktiven Mailversand sendet die Anwendung keine E-Mails: keine Willkommens- und
Bestätigungsmail nach der Registrierung, keine Einladungen, keine Sicherheitsmeldungen, keine Vorabankündigungen per
Mail, und `vormerken.php` nimmt keine Vorregistrierung an (Antwort 503, weil ohne Bestätigungsmail kein Double-Opt-in
möglich ist). Die Statusseite zeigt die Komponente „E-Mail-Benachrichtigungen“ dann als unbekannt.

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
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env restart worker-mail worker-maintenance
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php --send=ihre.adresse@example.de
```

`bin/mail-check.php` zeigt Passwörter nie im Klartext. Die Testmail läuft direkt über den Transport (ohne Warteschlange)
und zeigt das Corporate Design mit den Pflichtangaben der Müller Holding AG.

## 4. Fachlich prüfen

1. Registrierung mit eigener Adresse: Willkommensmail mit Bestätigungslink kommt an, Link funktioniert.
2. Vorregistrierung auf smart-einzug.de/integrationen/sevdesk/: Bestätigungsmail mit Bestätigungs- und Abmeldelink, nach
   dem Klick auf „E-Mail-Adresse bestätigen“ eine zweite Mail „Vormerkung ist bestätigt“; Eintrag im Adminbereich unter
   „Vormerkungen“.
3. Statusseite: „E-Mail-Benachrichtigungen“ wechselt nach dem nächsten Monitoringlauf (alle 240 s) auf betriebsbereit.

## Gestaltung der E-Mails

Alle E-Mails laufen durch `mail_layout()` (`app/mailer.php`): Kopfnaht mit Goldsegment, Wortmarke SmartEinzug, Goldbalken
unter der Überschrift, Fließtext Anthrazit, Gold nur als Akzent, Fußband in Anthrazit mit den Pflichtangaben der Müller
Holding AG (Sitz, Registergericht, HRB, Vorstand, Aufsichtsratsvorsitzender). `php tools/mail-ci-check.php` prüft das.
