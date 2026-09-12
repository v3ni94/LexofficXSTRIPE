# Mailversand einrichten (Produktion)

Stand: 11.09.2026 (4.61). Ohne aktiven Mailversand sendet die Anwendung keine E-Mails: keine Willkommens- und
Bestätigungsmail nach der Registrierung, keine Einladungen, keine Sicherheitsmeldungen, keine Vorabankündigungen per
Mail. Vorregistrierungen werden gespeichert und als wartend markiert (Seite „Vormerkung gespeichert, Bestätigungs-E-Mail
folgt“); die Bestätigungsmail wird nach der Aktivierung automatisch nachgesendet. Die Statusseite zeigt die Komponente
„E-Mail-Benachrichtigungen“ als unbekannt.

## 1. Postfach anlegen (Betreiber)

Ein eigenes Absenderpostfach für die Anwendung beim Mailanbieter der Domain anlegen; produktiv ist das
`kontakt@smart-einzug.de` bei IONOS. Absender (`from_address`), Versandpostfach (`smtp.user`) und Antwortadresse (`reply_to`)
gehören zur selben Domain, weil SPF, DKIM und DMARC für die Domain des sichtbaren Absenders gelten. Antworten der Kunden
laufen auf die Absenderadresse selbst; eine Antwortadresse auf fremder Domain (bis 4.60 war `info@mueller-holding.ag`
vorgesehen) setzt die Anwendung seit 4.61 nicht mehr, weil Spamfilter das als Missbrauchsmerkmal werten und der Fußtext
der Vorlagen „Antworten erreichen uns über die Adresse im Absender“ sonst falsch wäre. Soll ein anderes Postfach antworten,
eine Adresse derselben Domain eintragen (zum Beispiel `support@smart-einzug.de`) oder beim Anbieter eine Weiterleitung des
Absenderpostfachs einrichten. Für zuverlässige Zustellung beim Domainanbieter SPF, DKIM und DMARC für die Absenderdomain
einrichten (Vorgaben des Mailanbieters verwenden, hier bewusst keine erfundenen Werte; Anleitung für smart-einzug.de unten).

## 2. Konfiguration eintragen

In `/opt/smarteinzug/shared/config.php` (nur dort, nie im Repository) den Block `mail` füllen:

```php
'mail' => [
    'enabled'      => true,
    'transport'    => 'smtp',
    'from_address' => 'kontakt@smart-einzug.de',      // muss zum Postfach passen
    'from_name'    => 'SmartEinzug',
    'reply_to'     => 'kontakt@smart-einzug.de',      // gleiche Domain wie from_address; identisch = kein eigener Header
    'smtp' => ['host' => 'smtp.ionos.de', 'port' => 587, 'encryption' => 'tls', 'user' => 'kontakt@smart-einzug.de', 'pass' => '<passwort>'],
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

## Zustellbarkeit prüfen (seit 4.60)

Wenn Nachrichten im Spam-Ordner landen, zuerst auf dem VPS im php-Container ausführen. `RELEASE_SHA` ist Pflicht, weil die
Compose-Datei das Release daran bindet; ohne die Variable bricht jeder `docker compose`-Aufruf ab. Die Option
`--zustellbarkeit` gibt es erst ab Release 4.60; auf einem älteren Stand zeigt das Werkzeug nur die Grundangaben
(Transport, Absender, SMTP-Nutzer), die für Schritt 1 der Anleitung unten bereits ausreichen.

```bash
cd /opt/smarteinzug/deploy && export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php --zustellbarkeit
```

Das Werkzeug prüft SPF, DMARC und DKIM der Absenderdomain per DNS sowie die Konsistenz von Absender (`mail.from_address`),
Versandpostfach (`mail.smtp.user`) und Antwortadresse (`mail.reply_to`). Jede Zeile trägt OK, PRUEFEN, FEHLT oder UNKLAR und
eine Handlungsanweisung. Grenzen: Der Eintrag eines DKIM-Selektors im DNS beweist nicht, dass der Anbieter signiert; bei
IONOS zeigen `s1-ionos._domainkey` und `s2-ionos._domainkey` als CNAME auf einen gemeinsamen Schlüssel und werden mit der
Mailkonfiguration angelegt, unabhängig vom Schalter im Kundenbereich. Der abschließende Nachweis ist immer eine Testmail
(`--send=ADRESSE`) an ein Gmail-Postfach und dort „Original anzeigen“: SPF, DKIM und DMARC müssen PASS zeigen, `header.d`
muss die Absenderdomain sein.

Per DNS aus der Prüfumgebung am 11.09.2026 nachgewiesen: `_spf-eu.ionos.com` gibt ausschließlich IONOS-Adressbereiche frei
(212.227.x, 82.165.159.x, 217.72.192.x, zwei IPv6-Netze); die Adresse des VPS 72.61.80.67 (PTR `srv1960492.hstgr.cloud`,
generischer Hostinger-Name) ist nicht enthalten. `dmarc.ionos.de` liefert `v=DMARC1; p=none;` ohne `rua`, es erhält also
niemand Berichte. `s1-ionos._domainkey.smart-einzug.de` löst über den CNAME auf einen Schlüssel des Anbieters auf.
Folge: Sendet die Anwendung nicht über `smtp.ionos.de`, sondern direkt vom VPS, scheitert SPF (Softfail) und es fehlt
jede DKIM-Signatur; zusammen mit dem generischen PTR ist das die typische Spam-Einstufung.

Stand der Zone smart-einzug.de am 11.09.2026 (Auszug des Betreibers): SPF `v=spf1
include:_spf-eu.ionos.com ~all` vorhanden, `_dmarc` als CNAME auf `dmarc.ionos.de`, DKIM `s1-ionos`/`s2-ionos` als CNAME auf
`s1.dkim.ionos.com`/`s2.dkim.ionos.com`. Damit fehlt für den Versand über `smtp.ionos.de` kein Eintrag. Offene Prüfpunkte:
DKIM im IONOS-Kundenbereich aktiv, Transport der Anwendung tatsächlich `smtp` über das Postfach `kontakt@smart-einzug.de`,
DMARC in eigene Hand nehmen (TXT statt CNAME, Berichte an eigene Adresse). Der Punkt Reply-To ist seit 4.61 im Code gelöst.

Erledigt am 11.09.2026 (Betreiber, nachgewiesen): Transport `smtp` über `smtp.ionos.de` mit dem Postfach `kontakt@smart-einzug.de`
(`bin/mail-check.php` auf dem VPS), DKIM aktiv (Mailkopf mit `dkim=pass header.d=smart-einzug.de header.s=s1-ionos`),
`from_name` auf `SmartEinzug` vereinheitlicht (`restart-workers.sh`), DMARC als eigener TXT-Eintrag
`v=DMARC1; p=none; rua=mailto:kontakt@smart-einzug.de; fo=1` beim autoritativen Nameserver `ns1034.ui-dns.de` bestätigt, der
frühere CNAME auf `dmarc.ionos.de` ist entfernt. Damit stehen alle vier Zeilen von `--zustellbarkeit` auf OK, sobald der
Resolver-Cache abgelaufen ist. Nächste Stufe nach zwei Wochen mit sauberen Berichten: `p=quarantine`, später `p=reject`, SPF `-all`.

## Auswertung eines Mailkopfs vom 11.09.2026 (Willkommensmail, 4.58)

Ergebnis: Versand über `mout.kundenserver.de` (IONOS), beim ersten Empfang durch Google SPF PASS, DKIM PASS mit `s1-ionos`
und `d=smart-einzug.de`, DMARC PASS, IONOS `X-Spam-Flag: NO`. DKIM ist also aktiv. Das in der Gmail-Übersicht angezeigte
SPF-SOFTFAIL entstand erst beim zweiten Hop: Die Mail an `emb@mueller-holding.ag` wurde auf `timo@muellerhv.de`
weitergeleitet, der Return-Path blieb `kontakt@smart-einzug.de`, und Googles Weiterleitungsserver steht naturgemäß nicht im
SPF. ARC trägt die ursprünglichen Ergebnisse weiter (`arc=pass`), weitergeleitete Mails mit Bestätigungslink werden aber
strenger eingestuft. Die direkt zugestellte Testmail desselben Tages lag im Posteingang. Folgerungen: Zustellbarkeitstests
immer an die Zieladresse ohne Weiterleitung; `from_name` auf „SmartEinzug“ vereinheitlichen (Kopf sagte „Smart-Einzug“, Text
„SmartEinzug“); Betreffkodierung an Wortgrenzen (4.64); DMARC-Berichte über einen eigenen TXT-Eintrag beziehen, um
Weiterleitungsfälle zu sehen.

## Kopfzeilen der Nachrichten (seit 4.61)

Jede Nachricht der Anwendung trägt neben From, Date, Message-ID und MIME-Version die Kopfzeile `Auto-Submitted:
auto-generated` (RFC 3834). Abwesenheitsnotizen und Autoresponder antworten dann nicht auf Systemnachrichten. `Reply-To`
wird nur gesetzt, wenn `mail.reply_to` gültig ist, sich vom Absender unterscheidet und zur Absenderdomain gehört
(`mail_reply_to_effective()`); eine fremde Domain wird ignoriert und einmal je Prozess im Fehlerprotokoll vermerkt,
`bin/mail-check.php` zeigt die wirksame Antwortadresse in der Zeile „Antwort an“.

Die drei Nachrichten der Vormerkung (Bestätigung, Nachsendung, „Vormerkung ist bestätigt“) tragen zusätzlich
`List-Unsubscribe: <https://app.smart-einzug.de/vormerken.php?abmelden=TOKEN>` und `List-Unsubscribe-Post:
List-Unsubscribe=One-Click` (RFC 8058). Gmail, Outlook und Apple Mail zeigen dann eine eigene Abmeldeschaltfläche und
senden bei Klick einen POST mit dem Feld `List-Unsubscribe=One-Click` an diese Adresse; `vormerken.php` führt das wie
„Jetzt abmelden“ aus (`interest_is_one_click_unsubscribe()`), die Berechtigung ergibt sich allein aus Token B. Postfachanbieter
werten eine funktionierende Abmeldung als Merkmal seriöser Nachrichten und melden Beschwerden statt Spam-Einstufung.
Willkommens-, Sicherheits- und Vorabankündigungsmails tragen die Kopfzeile nicht: Sie sind Vertragsnachrichten ohne
Abmeldemöglichkeit. Die Abmeldeadresse wird als Option `unsubscribe_url` über `mail_send()` und den Warteschlangen-Payload
(`options`) an `mail_send_direct()` gereicht; andere Kopfzeilen lassen sich darüber nicht setzen (`mail_options_normalize()`).
`php tools/mail-ci-check.php` prüft Kopfzeilen, Reihenfolge, Header-Injection über `reply_to` und die Weitergabe.

## Schritt für Schritt: Zustellbarkeit von kontakt@smart-einzug.de herstellen (Betreiber, 11.09.2026)

Reihenfolge einhalten; jeder Schritt nennt, wo er ausgeführt wird. Nichts davon erfordert eine Codeänderung, alle
Codeanteile sind mit 4.61 im Release enthalten. Werte, die hier nicht stehen (Passwörter, Kundenbereichs-Menüpfade), nicht
raten, sondern in der IONOS-Hilfe nachlesen.

1. **Server: Ist-Zustand messen.** Per SSH auf dem VPS ausführen und die Ausgabe aufbewahren:
   ```bash
   cd /opt/smarteinzug/deploy && export RELEASE_SHA="$(basename "$(readlink -f /opt/smarteinzug/releases/current)")"
   docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env exec -T php php bin/mail-check.php --zustellbarkeit
   ```
   Auf dem heutigen Produktionsstand (4.58) kennt das Werkzeug `--zustellbarkeit` noch nicht und zeigt nur die
   Grundangaben; das reicht für diesen Schritt. Steht bei „Transport“ nicht `smtp` oder bei „SMTP-Nutzer“ nicht das
   Postfach `ko****de`, ist die Ursache gefunden: Der Server sendet dann nicht über IONOS und SPF sowie DKIM können
   nicht passen. Die DNS-Bewertung liefert der Befehl nach dem Deployment von 4.61.
2. **Server: `/opt/smarteinzug/shared/config.php` prüfen und angleichen** (nur dort, nie im Repository):
   `transport` = `smtp`, `smtp.host` = `smtp.ionos.de`, `smtp.port` = 587, `smtp.encryption` = `tls`,
   `smtp.user` = `kontakt@smart-einzug.de`, `from_address` = `kontakt@smart-einzug.de`, `reply_to` =
   `kontakt@smart-einzug.de` (oder leer). Danach zwingend `bash scripts/restart-workers.sh` (Scheduler und Worker lesen die
   Konfiguration nur beim Start; das Skript erzeugt die Container neu und lädt php-fpm neu).
3. **IONOS Kundenbereich: DKIM für smart-einzug.de einschalten.** Im Bereich E-Mail der Domain die Signatur (DKIM)
   aktivieren, falls der Schalter nicht bereits aktiv ist. Die DNS-Einträge `s1-ionos._domainkey` und `s2-ionos._domainkey`
   sind vorhanden; sie beweisen aber nicht, dass IONOS signiert. Ohne aktive Signatur fehlt bei Gmail „DKIM: PASS“.
4. **IONOS DNS: DMARC in eigene Hand nehmen.** Den Eintrag `_dmarc` vom Typ CNAME (Ziel `dmarc.ionos.de`) löschen und
   stattdessen einen TXT-Eintrag anlegen: Name `_dmarc`, Wert `v=DMARC1; p=none; rua=mailto:dmarc@smart-einzug.de; fo=1`.
   Vorher das Postfach oder eine Weiterleitung `dmarc@smart-einzug.de` anlegen, sonst gehen die Berichte ins Leere.
   Begründung: Der IONOS-Eintrag lautet nur `v=DMARC1; p=none;` ohne Berichtsempfänger; niemand erfährt, ob Nachrichten
   die Prüfung bestehen. CNAME und TXT für `_dmarc` dürfen nicht gleichzeitig existieren.
5. **IONOS DNS: SPF unverändert lassen.** `v=spf1 include:_spf-eu.ionos.com ~all` ist für den Versand über `smtp.ionos.de`
   richtig. Erst nach zwei Wochen fehlerfreier DMARC-Berichte auf `-all` verschärfen. Den VPS nie in den SPF aufnehmen:
   Die Anwendung soll nicht direkt vom Server senden.
6. **IONOS DNS: Altlasten bereinigen (optional, ohne Einfluss auf Mail).** `cloudflare-verify` und `_acme-challenge.pay`
   sind Reste früherer Einrichtungen und können gelöscht werden. Die Einträge `staging` und `coolify` zeigen öffentlich auf
   den Produktions-VPS: `staging` widerspricht der Regel „Staging auf eigenem Server“, `coolify` macht die Verwaltungs-
   oberfläche öffentlich erreichbar; beide gehören auf den richtigen Server oder in eine Zugriffsbeschränkung (Entscheidung
   Geschäftsführung, kein Mailthema).
7. **Server: Nachweis mit Testmail.** Mit denselben zwei Zeilen wie in Schritt 1, aber der Option
   `--send=EIGENE-GMAIL-ADRESSE` statt `--zustellbarkeit`, eine Testmail senden, die
   Nachricht in Gmail öffnen, Menü „Original anzeigen“: SPF PASS, DKIM PASS mit `header.d=smart-einzug.de`, DMARC PASS.
   Zusätzlich muss im Original `Auto-Submitted: auto-generated` stehen und darf kein `Reply-To` auf fremder Domain
   erscheinen. Eine Vormerkung über smart-einzug.de/integrationen/sevdesk/ mit derselben Adresse zeigt in Gmail die
   Schaltfläche „Abbestellen“ neben dem Absender (List-Unsubscribe wirksam).
8. **Google Postmaster Tools** (postmaster.google.com) für smart-einzug.de einrichten (Nachweis über TXT-Eintrag bei IONOS,
   Wert aus dem Werkzeug übernehmen). Zeigt Spam-Rate, Domain-Reputation und Authentifizierungsquote der letzten Tage.
9. **Nach zwei Wochen:** DMARC-Berichte prüfen (alle Quellen bestehen SPF und DKIM?), dann `p=quarantine`, später
   `p=reject`; SPF auf `-all`. Jede Verschärfung mit einer erneuten Testmail nach Schritt 7 absichern.

Was ein Spamfilter danach noch bewerten kann: Reputation des jungen Absenders (steigt mit Zeit und niedriger
Beschwerdequote), Inhalt (die Vorlagen sind textlastig, ohne Werbesprache, mit Pflichtangaben und Abmeldelink) sowie das
Verhältnis von Text- zu HTML-Fassung (beide Fassungen werden mitgesendet). Ohne bestandene Authentifizierung hilft keine
dieser Maßnahmen; deshalb zuerst die Schritte 1 bis 4.
