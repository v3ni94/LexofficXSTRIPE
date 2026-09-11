# E-Mail-System und Benachrichtigungen

Stand: 07.09.2026. Ausgewertet aus `php-ionos/app/mailer.php`, `php-ionos/app/auth.php`,
`php-ionos/app/interest.php`, `php-ionos/app/alerts.php`, `php-ionos/app/plans.php`,
`php-ionos/app/mandate_requests.php`, `php-ionos/app/support_tickets.php`,
`php-ionos/app/collections.php`, `php-ionos/app/monitor.php`, `php-ionos/bin/mail-check.php`. Jede
Aussage ist mit Datei:Funktion bzw. Datei:Zeile belegt. Keine echten Adressen, Passwörter oder
Kundendaten; Beispiele sind synthetisch.

## Versandwege

@@diagramm 11-email-fluss

- **Grundschalter:** `mail_enabled()` prüft `config('mail')['enabled'] === true`
  (`app/mailer.php:20-24`). Ist der Schalter aus, versendet die Anwendung **nichts**; alle
  Aufrufer prüfen das vor dem Versand.
- **Transportarten** (`config('mail')['transport']`, `app/mailer.php:204-255`):
  - `mail` (Standard laut Doku-Kopf `config.example.php:136-138`): PHP-Funktion `mail()`
    (`app/mailer.php:246-254`).
  - `log`: schreibt die vollständige Nachricht (Header + Body) in `config('mail')['log_file']`
    (Standard `APP_ROOT . '/mail.log'`, `app/mailer.php:207`), für Test-/Entwicklungszwecke,
    kein echter Versand.
  - `smtp`: eigener minimaler SMTP-Client `mail_smtp_send()` (`app/mailer.php:283-358`), siehe
    unten.
- **SMTP-Konfiguration** (Konfigurationsschlüssel, keine Werte): `config('mail')['smtp']['host']`,
  `['port']` (587 STARTTLS oder 465 SSL laut Doku-Kopf `config.example.php:142-143`),
  `['encryption']` (`tls`/`ssl`/`none`), `['user']`, `['pass']`. Ablauf: TCP/TLS-Verbindung
  (`stream_socket_client()`, `app/mailer.php:296`), `EHLO`, optionales `STARTTLS` mit
  `verify_peer`/`verify_peer_name` aktiv (Zeilen 294, 331-337), `AUTH LOGIN` (Base64, Zeilen
  340-344), `MAIL FROM`/`RCPT TO`/`DATA` (Zeilen 346-351). Zeilen, die mit „." beginnen, werden nach
  SMTP-Konvention verdoppelt (Zeile 350). Timeout 20 s für Verbindungsaufbau und Lesevorgänge
  (Zeilen 296, 300).
- **`mail_send()`** (`app/mailer.php:129-150`): Standardweg für alle Vorlagen. Ist die Warteschlange
  aktiv (`queue_enabled()`) und läuft der Aufruf **nicht** bereits in einem Worker
  (`!defined('IN_WORKER')`), wird die Nachricht als Job `mail` eingereiht statt sofort versendet
  (Zeilen 134-148), Webanfragen warten so nicht auf SMTP. Ohne aktive Warteschlange (oder aus einem
  Worker heraus) erfolgt sofortige Übergabe über `mail_send_direct()`.
- **`mail_send_queued()`** (`app/mailer.php:109-127`): bevorzugt die Warteschlange auch **innerhalb**
  eines Workers (anders als `mail_send()`), gedacht für Nachsendungen aus dem Wartungsjob
  (Kommentar Zeilen 105-107), genutzt von `interest_send_pending()` (`app/interest.php:273`) und
  `auth_send_pending_welcome_mails()` (`app/auth.php:1154`).
- **Warteschlange, Jobtyp `mail`, Worker `worker-mail`:** Details (Drosselung, Fehlerklassen,
  Datensparsamkeit, Nichtunterbrechbarkeit) stehen in `docs/entwickler/jobs.md`, Abschnitt „`mail`,   E-Mail-Versand", und werden hier nicht dupliziert.

## Absender- und Antwortadresse (Konfigurationsschlüssel)

- `config('mail')['from_address']`, `['from_name']` (Standard `SmartEinzug`, ansonsten
  `product_name()`, `app/mailer.php:178, 33-40`).
- `config('mail')['reply_to']`, optional. Seit 4.61 setzt `mail_reply_to_effective()` den Header
  `Reply-To` nur, wenn die Adresse gültig ist, sich vom Absender unterscheidet und dieselbe registrierbare
  Domain wie `from_address` hat; eine fremde Domain (bis 4.60 Vorgabe `info@mueller-holding.ag`) wird
  ignoriert und einmal je Prozess protokolliert (`mail_log_once()`). Vorgabe in `config.example.php`
  ist `kontakt@smart-einzug.de`, identisch mit dem Absender, also ohne eigenen Header.
- Alle Kopfzeilen entstehen in `mail_header_lines(array $cfg, string $contentType, bool $plainOnly,
  array $options)`: From, Reply-To (wirksam), MIME-Version, Date, Message-ID, `Auto-Submitted:
  auto-generated` (RFC 3834, jede Nachricht), bei Option `unsubscribe_url` `List-Unsubscribe: <URL>` und
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058), dann Content-Type. Optionen laufen
  als fünfter Parameter durch `mail_send()`, `mail_send_queued()` und `mail_send_direct()` sowie als
  Schlüssel `options` im Payload des Jobtyps `mail` (`mail_queue_payload()`, `job_mail()`);
  `mail_options_normalize()` lässt nur `unsubscribe_url` mit `http(s)://` zu, andere Schlüssel werden
  verworfen (kein Durchreichen beliebiger Kopfzeilen). Gesetzt wird die Option ausschließlich von den
  drei Vormerkungsmails in `app/interest.php`; die One-Click-Anfrage der Postfachanbieter (POST mit Feld
  `List-Unsubscribe=One-Click` an `vormerken.php?abmelden=B`) erkennt `interest_is_one_click_unsubscribe()`
  und führt die Abmeldung ohne Rückfrage aus. Prüfung: `php tools/mail-ci-check.php`, Abschnitt 4.
- Header-Werte werden vor dem Versand von `\r`/`\n` bereinigt (`mail_sanitize_header()`,
  `app/mailer.php:27-30`), Schutz vor Header-Injection über Freitextfelder (z. B. Betreff aus
  Support-Ticket).
- Message-ID wird aus dem Host der Anwendungsbasisadresse abgeleitet (`mail_message_id_host()`,
  `app/mailer.php:43-48`, `mail_generate_message_id()`, Zeilen 51-58).

## `mail_layout()` und CI-Pflichtangaben

`mail_layout()` (`app/mailer.php:369-463`) ist die einzige vorgesehene Quelle für HTML-Vorlagen
(projektweite Regel, CLAUDE.md „E-Mails"). Sie erzeugt Text- und HTML-Fassung aus Titel, Absätzen,
optionalem Button, optionaler Fußnotiz und optionalem Zweitlink:

- Gestaltungssprache „Goldpunkt" der Müller Holding AG (Kommentar Zeile 371-373): Kopfnaht mit
  Goldsegment, Wortmarke (Logo aus `public_base_url() . '/assets/img/logo-horizontal.png'`, Zeile
  377), Goldbalken unter der Überschrift, Anthrazit-Fließtext, Gold nur als Akzent.
- Pflichtangaben nach § 80 AktG im Fußband (Zeilen 380-381, 449-455): „Müller Holding AG ·
  Rheinpromenade 13 · 40789 Monheim am Rhein · kontakt@mueller-holding.ag · mueller-holding.ag" sowie
  „Sitz: Monheim am Rhein · Registergericht: Amtsgericht Düsseldorf · HRB 104291 · Vorstand: Timo
  Müller · Aufsichtsratsvorsitzender: Jan Walprecht".
- Lexware-Office-Disclaimer in jeder Mail: „... ist ein Angebot der Müller Holding AG. Unabhängige
  Softwarelösung mit Schnittstelle zu Lexware Office. Kein Produkt der Haufe-Lexware GmbH & Co. KG."
  (Zeilen 378-379).
- Zweitlink für Abmeldungen: Parameter `$secondaryLink` (`['label' => ..., 'url' => ...]`), z. B.
  für die Vormerkungs-Bestätigung (`mail_tpl_interest_confirm()`, Zeile 536) und die
  Bestätigungsnachricht nach Opt-in (`mail_tpl_interest_confirmed()`, Zeile 555).
- **Bekannte Ausnahme (Fund, kein Bestandteil der Vorlage):** `plan_quota_warning_maybe_send()`
  versendet die Quota-Warnung über `mail_send($o['email'], $subject,
  implode("\n", $lines))` **ohne** `mail_layout()` und **ohne** HTML-Fassung
  (`app/plans.php:341`), die Nachricht trägt weder das CI-Layout noch die
  Pflichtangaben aus dem Fußband. Siehe Offene Prüfpunkte.

## Vorlagen (`mail_tpl_*`) und Zweck

Alle in `app/mailer.php`, sofern nicht anders vermerkt:

| Funktion | Zeile | Zweck |
|---|---|---|
| `mail_tpl_invitation()` | 470 | Einladung eines neuen Mitarbeiters zu einem Firmenaccount |
| `mail_tpl_verify_email()` | 506 | Bestätigung der E-Mail-Adresse nach Registrierung/erneuter Anforderung |
| `mail_tpl_interest_confirm()` | 522 | Double-Opt-in-Bestätigung einer Integrations-Vormerkung (sevdesk) |
| `mail_tpl_interest_confirmed()` | 544 | Bestätigte Vormerkung: nächste Schritte, Abmeldelink |
| `mail_tpl_welcome()` | 562 | Willkommensmail nach Registrierung, mit oder ohne Bestätigungslink |
| `mail_tpl_prenotification()` | vor `mail_tpl_recovery_codes_regenerated()` | Vorabankündigung SEPA-Lastschrift an den Kunden der Firma (Rechnung, Betrag, Einzugstermin, Zahlungsempfänger, Mandatsreferenz, Gläubiger-ID, Hinweis auf Stripe und Kontodeckung); einzige Quelle, seit 4.44 auch vom Musterversand genutzt |
| `mail_prenotification_sample()` | direkt danach | Musterdaten (Rechnung RE-MUSTER-0001, Mandat MUSTER-MANDAT-0001, 1.234,56 EUR, Termin heute plus 14 Tage) für den Musterversand; Firmenname und Gläubiger-ID aus der übergebenen Firma |
| `mail_tpl_security()` | 587 | Generische Sicherheitsbenachrichtigung (Basis für mehrere Spezialfälle) |
| `mail_tpl_recovery_codes_regenerated()` | 608 | Wiederherstellungscodes wurden neu erzeugt |
| `mail_tpl_2fa_reset()` | 620 | Zwei-Faktor-Authentifizierung wurde zurückgesetzt (durch Nutzer oder Admin) |
| `mail_tpl_ownership_transferred()` | 637 | Inhaberschaft eines Firmenaccounts wurde übertragen |
| `mail_tpl_member_removed()` | 651 | Mitglied wurde aus einem Firmenaccount entfernt |
| `mail_tpl_member_joined()` | 664 | Neues Mitglied ist einem Firmenaccount beigetreten |
| `mail_tpl_integration_changed()` | 676 | Änderung an einer Integration (Lexware Office/Stripe) |

Nicht als eigene `mail_tpl_*`-Funktion, aber ebenfalls über `mail_layout()` gebaut: Alarm-E-Mail
(`app/alerts.php:213`), Mandatsanforderung/-erinnerung (`app/mandate_requests.php:99`),
Support-Ticket-Benachrichtigungen
(`app/support_tickets.php:190, 205`), Störungs-/Entwarnungsmail (`app/monitor.php:1481`),
Testversand aus dem Adminbereich und `bin/mail-check.php --send` (`admin-system.php:88`,
`bin/mail-check.php:57`), jeweils direkter Aufruf von `mail_layout()` statt einer eigenen
`mail_tpl_*`-Funktion. Die Vorabankündigung war bis 4.43 ebenfalls ein direkter `mail_layout()`-Aufruf in
`app/collections.php` und ist seit 4.44 die Vorlage `mail_tpl_prenotification()` (Inhalt unverändert).

Musterversand der Vorabankündigung (seit 4.44): Aktion `test_prenotification` in `admin-system.php` sendet die Vorlage mit
`mail_prenotification_sample()` (Firmenname und Gläubiger-ID der Firma des Administrators, sonst „Muster GmbH“) mit dem
Betreffvorsatz „MUSTER“ ausschließlich an die E-Mail-Adresse des angemeldeten Administrators (`$ctx['email']`, kein
Adressfeld im Formular, kein Kunde, kein Eintrag in `payment_collections`); Recht `monitoring.edit`, CSRF, Audit
`monitor_test_prenotification`, keine Zweitbestätigung (Diagnose ohne Geldwirkung). CLI: `bin/mail-check.php
--vorabankuendigung --send=ADRESSE` (Direktversand über `mail_send_direct()`), `--html=DATEI` schreibt die HTML-Fassung als
Vorschau ohne Versand und ohne `mail.enabled`.

## Zuordnung Ereignis → Auslöser → Empfänger → Vorlage → Versandprozess → Fehlerbehandlung

| Ereignis | Auslöser (Datei:Funktion) | Empfänger | Vorlage | Versandprozess | Fehlerbehandlung |
|---|---|---|---|---|---|
| Registrierung (Willkommen + Verifizierung) | `app/auth.php:838` `email_verification_send()`, aufgerufen aus `register.php` | neuer Nutzer | `mail_tpl_welcome()` (mit Link) bzw. `mail_tpl_verify_email()` bei erneuter Anforderung | `mail_send()` (`app/auth.php:851`) | Kann `mail_send()` `false` liefern oder scheitert die Erzeugung, markiert `_auth_register_create()` `users.welcome_mail_pending = 1` (`app/auth.php:1126`); Nachsendung über `auth_send_pending_welcome_mails()` |
| E-Mail-Verifizierung erneut anfordern | `verify-email.php` (POST) → `email_verification_send()` | Nutzer selbst | `mail_tpl_verify_email()` | `mail_send()` | wie oben |
| Passwort zurücksetzen (Anforderung) | `forgot-password.php` → `password_reset_request()` (`app/auth.php:885`) | angegebene Adresse (neutrale Antwort unabhängig vom Ergebnis) | `mail_tpl_security('Passwort zurücksetzen', ...)` (Zeile 914) | `mail_send()` (Zeile 918) | Rate-Limit 5 Anforderungen/15 Min je IP und Adresse (Zeilen 890-899), sonst stille Ablehnung; kein Pending-Mechanismus (Anforderung ist wiederholbar) |
| Passwort zurückgesetzt (Bestätigung) | `reset-password.php` → `password_reset_complete()` (`app/auth.php:923`) | Nutzer | `mail_tpl_security('Passwort geändert', ...)` über `security_notify_user()` (Zeile 946) | `mail_send()` | kein spezieller Pending-Mechanismus |
| Passwort im angemeldeten Zustand geändert | `security.php` → `password_change()` (`app/auth.php:953`) | Nutzer | `mail_tpl_security('Passwort geändert', ...)` (Zeile 965) | `mail_send()` | wie oben |
| Einladung eines Mitarbeiters | `team.php` (Aktion Einladen) → `app/auth.php` bzw. `team.php:50` | eingeladene Adresse | `mail_tpl_invitation()` | `mail_send()` (`team.php:51`) | kein Pending-Mechanismus gefunden; erneutes Senden über eigene Aktion „Einladung erneut senden" (Audit `invite_resent`) |
| Mitglied beigetreten | `invite.php` (nach Annahme) | Inhaber der Firma | `mail_tpl_member_joined()` | `mail_send()` (`invite.php:114`) | - |
| Mitglied entfernt | `team.php:282-284` | entferntes Mitglied + Inhaber | `mail_tpl_member_removed()` | `mail_send()` (zweifach, an beide Adressen) | - |
| Inhaberschaft übertragen | `team.php:314-316` | neuer Inhaber + bisheriger Inhaber | `mail_tpl_ownership_transferred()` | `mail_send()` | - |
| 2FA eingerichtet/Recovery-Codes neu erzeugt | `security.php`/`twofa-setup.php` → `recovery_codes_regenerate()` (`app/auth.php:728`) | Nutzer | `mail_tpl_recovery_codes_regenerated()` (nur wenn `$notify = true`) | `mail_send()` (Zeile 745) | - |
| 2FA zurückgesetzt (selbst oder durch Admin) | `security.php`/`admin-support.php` → `twofa_reset()` (`app/auth.php:779`) | betroffener Nutzer | `mail_tpl_2fa_reset()` | `mail_send()` (Zeile 800) | - |
| Recovery-Code bei Anmeldung verwendet | `auth_login_2fa()` (`app/auth.php:483`) → `security_notify_user()` (Zeile 529) | Nutzer | `mail_tpl_security('Recovery-Code verwendet', ...)` | `mail_send()` | - |
| Gerätefreigabe eingerichtet (90 Tage) | `app/devices.php:156` `device_trust_create()` → `security_notify_user()` (Zeile 175) | Nutzer | `mail_tpl_security('Gerät für 90 Tage gemerkt', ...)` | `mail_send()` | - |
| Vorregistrierung/Vormerkung (Double-Opt-in) | `vormerken.php` → `interest_register()` (`app/interest.php`, Ablauf Kommentarkopf Zeilen 3-27) | Interessent | `mail_tpl_interest_confirm()` | `mail_send()` (`app/interest.php:211`) | Kann die Mail nicht erzeugt werden, bleibt der Eintrag `pending` und wird `mail_pending = 1` markiert (Zeile 223); Nachsendung über `interest_send_pending()`, das `mail_send_queued()` nutzt (Zeile 273) |
| Vormerkung bestätigt | `vormerken.php` (Token A) → `interest_confirm()` | Interessent | `mail_tpl_interest_confirmed()` | `mail_send()` (Aufrufstelle `app/interest.php:341` ff., konkreter Sendeaufruf im weiteren Funktionsverlauf) | Double-Opt-in ausschließlich per Button, nicht per GET (Kommentar Zeile 10-11) |
| Support-Ticket erstellt/ergänzt | `hilfe.php` → `ticket_notify_support()` (`app/support_tickets.php:177`) | Support-Adresse (`ticket_support_address()`) | `mail_layout('Neue Support-Anfrage', ...)` bzw. „Ergänzung ..." | `mail_send()` (Zeile 191) | kein Versand, wenn keine Support-Adresse konfiguriert (Zeile 181) |
| Support-Ticket beantwortet/geschlossen | `admin-support.php` → `ticket_notify_customer()` (`app/support_tickets.php:194`) | anfragender Nutzer | `mail_layout('Antwort auf Ihre Support-Anfrage', ...)` | `mail_send()` (Zeile 206) | - |
| Vorabankündigung SEPA-Lastschrift | `app/collections.php:1183` `_send_prenotification()`, aus dem Einzugsprozess | Kunde der Firma | `mail_tpl_prenotification()` (seit 4.44, vorher direkter `mail_layout()`-Aufruf) | `mail_send()` (Zeile 1197) | nur wenn `organizations.send_pre_notification` gesetzt UND Kunde eine E-Mail-Adresse hat (Zeile 1186); kein Pending-Mechanismus |
| Digitale Mandatsanforderung/-erinnerung | `customer.php` bzw. Job `mandate_reminders` → `_mandate_request_mail()` (`app/mandate_requests.php:81`) | Kunde | `mail_layout('SEPA-Lastschriftmandat für ... bestätigen', ...)` bzw. mit Präfix „Erinnerung:" | `mail_send()` (Zeile 100) | Feature-Flag `features.mandate_request` muss aktiv sein (siehe `docs/entwickler/jobs.md`) |
| Alarmierung (Stufe „hoch") | Job `alerts` → `alerts_cron_notify()` (`app/alerts.php:190-220`) | Inhaber der Firma | `mail_layout('Hinweise zu Ihrem Firmenaccount', ...)` (Zeile 213) | `mail_send()` (Zeile 214) | höchstens einmal je Kalendertag und Firma (`platform_setting`-Marke, Zeile 190) |
| Einzugskontingent-Warnung | `plan_quota_warning_maybe_send()` (`app/plans.php:290-345`), aufgerufen im Einzugsprozess | Inhaber der Firma | **kein** `mail_tpl_*`/`mail_layout()`, reiner Text (Zeilen 320-335) | `mail_send()` ohne HTML-Parameter (Zeile 341) | höchstens einmal je Abrechnungsperiode (`organizations.quota_warning_period_start`, Zeilen 300-312); siehe Offene Prüfpunkte |
| Störung/Entwarnung Systemmonitoring | `app/monitor.php:1481-1488` | `config('monitoring')['alert_emails']` | `mail_tpl_security('Störung: ...'/'Entwarnung: ...', ...)` | `mail_send()` | - |
| Manueller Testversand (Admin) | `admin-system.php:88-89` | `config('monitoring')['test_mail_to']` (nie Kundenadressen, Kommentar `config.example.php:213`) | `mail_tpl_security('Testversand Systemmonitoring', ...)` | `mail_send()` | nur für konfigurierte Bearbeiter (`monitoring.editors`) mit frischer 2FA (siehe `docs/entwickler/schnittstellen.md`) |
| Statusseite/Störung öffentlich | **nicht gefunden** als E-Mail, die Statusseite wird über eine statische JSON-Datei (`status_publish`) veröffentlicht, kein Mailversand an Endnutzer der Statusseite gefunden (`app/monitor.php`, `docs/status-page.md`, außerhalb dieses Dokuments) | - | - | - | - |

## Nachsenden wartender Mails

Zwei getrennte Wartemarken:

- **`interest_registrations.mail_pending`** (`app/interest.php`, gesetzt Zeilen 223, 353): erneuter
  Versand über `interest_send_pending()` (Zeile 242), verwendet `mail_send_queued()` (Zeile 273);
  bereinigt die Marke bei Erfolg (Zeile 274). Aufgerufen aus `job_maintenance()`
  (`app/jobs.php:294`) und `cron.php:74` (Webhosting-Pfad).
- **`users.welcome_mail_pending`** (`app/auth.php`, gesetzt Zeile 1126): erneuter Versand über
  `auth_send_pending_welcome_mails()` (Zeile 1137), ebenfalls `mail_send_queued()`; bereinigt die
  Marke bei Erfolg (Zeile 1159). Aufgerufen aus `job_maintenance()` (`app/jobs.php:295`) und
  `cron.php:75`.

Beide Mechanismen sind laut `app/version.php:66` explizit dafür gebaut, dass „jede Registrierung und
jede Vorregistrierung eine E-Mail erhält, sobald der Versand eingerichtet ist", kein stiller
Bestätigungsvorgang ohne E-Mail (projektweite Regel, CLAUDE.md).

## Fehlerdiagnose

- **`bin/mail-check.php`** (nur lesend, optional `--send=ADRESSE`): zeigt Status
  (`mail_enabled()`), Transport, Absender/Antwortadresse, SMTP-Host/Port/Verschlüsselung, ob ein
  SMTP-Passwort gesetzt ist (maskiert, nie im Klartext, `bin/mail-check.php:26, 34`), sowie ob die
  Warteschlange oder der direkte Versand greift (Zeile 35). Warnt bei ungültiger `reply_to`- oder
  `from_address`-Adresse (Zeilen 37-44). Mit `--send` wird eine echte Testmail im CI direkt über
  `mail_send_direct()` versendet (ohne Warteschlange, Zeile 62), Exit-Code 0 bei Erfolg. Mit
  `--vorabankuendigung --send=ADRESSE` geht statt der Testnachricht das Muster der Vorabankündigung
  (Betreff „MUSTER Vorabankündigung SEPA-Lastschrift RE-MUSTER-0001“) an die Adresse;
  `--vorabankuendigung --html=DATEI` schreibt nur die HTML-Vorschau (kein Versand, läuft auch ohne
  `mail.enabled`).
- **`mail.log`** (nur bei Transport `log`): vollständige Kopie jeder „gesendeten" Nachricht inkl.
  Header und Body (`app/mailer.php:206-221`), nur für Test-/Entwicklungsumgebungen gedacht, keine
  echte Zustellung.
- **Monitoring-Marker** (`app/mailer.php:261-276` `mail_monitor_mark()`): schreibt bei Erfolg
  `mail_last_ok_at` (UTC-Zeitstempel) und ein Monitoring-Ereignis `mail_send` mit Status `ok`; bei
  Fehlschlag `mail_last_fail_at`, `mail_last_fail_category` (über `monitor_category()`, bereinigt,
  keine Rohmeldung) sowie ein Ereignis mit Status `fail`. Eine `WorkerShutdownException` (Notbremse
  des Workers) wird dagegen unverändert durchgereicht und **nicht** als Transportfehler gezählt
  (`app/mailer.php:230-235`).
- **Fehlerklassen bei SMTP** (`mail_send_direct()`, Zeilen 223-244): Unterscheidung zwischen
  endgültiger Ablehnung des Empfängers/der Nachricht (5xx auf `RCPT TO` oder `DATA`, erkannt über
  Musterabgleich der Fehlermeldung, Zeile 239, `kind = 'rejected'`) und einem Transportproblem
  (`kind = 'transport'`); `mail_last_error()` (Zeile 688-691) liefert diese Unterscheidung an den
  Jobhandler (`job_mail()`, siehe `docs/entwickler/jobs.md`).

## SPF, DKIM, DMARC

**Nicht im Repository nachgewiesen, Prüfung im DNS erforderlich.** Der einzige Fund ist eine
Empfehlung in der Betriebsdokumentation: „Für zuverlässige Zustellung beim Domainanbieter SPF, DKIM
und DMARC für die Absenderdomain einrichten (Vorgaben des Mailanbieters verwenden, hier bewusst keine
erfundenen Werte)" (`docs/mail-einrichtung.md:13-14`). Das ist eine Handlungsempfehlung für die
Einrichtung, kein Nachweis vorhandener DNS-Einträge; im gesamten durchsuchten Bestand von `docs/` und
`websites/` findet sich keine weitere Erwähnung und kein hinterlegter Eintrag (z. B. TXT-Record-Text).
Eine tatsächliche Prüfung müsste außerhalb dieses Repositorys im DNS der jeweiligen Absenderdomain
erfolgen (z. B. `dig TXT <domain>` für SPF/DMARC, `dig TXT selector._domainkey.<domain>` für DKIM).

## Zustellbarkeit einrichten und nachweisen (offen, Betreiber)

Ohne SPF, DKIM und DMARC landen Bestätigungs-, Mandats- und Vorabankündigungsmails häufig im Spam. Das ist
unmittelbar geldwirksam: Ohne angeklickten Mandatslink entsteht kein Mandat und damit kein Einzug. Vorgehen ohne
erfundene Werte:

1. Beim Mailanbieter (IONOS) die für die Absenderdomain gültigen Werte abrufen: SPF-Eintrag des Anbieters,
   DKIM-Selektor und öffentlicher Schlüssel, empfohlene DMARC-Regel. Nur diese Werte verwenden.
2. Die Einträge im DNS der Absenderdomain setzen (TXT für SPF und DMARC, TXT für `<selektor>._domainkey`).
3. Nachweisen und das Ergebnis mit Datum in `docs/ARBEITSSTAND.md` vermerken:

```bash
dig +short TXT smart-einzug.de                      # SPF, beginnt mit v=spf1
dig +short TXT _dmarc.smart-einzug.de               # DMARC, beginnt mit v=DMARC1
dig +short TXT <selektor>._domainkey.smart-einzug.de # DKIM, beginnt mit v=DKIM1
```

4. Danach eine Testmail an ein externes Postfach senden (`bin/mail-check.php --send=...`) und im Kopf der
   empfangenen Nachricht prüfen, dass SPF, DKIM und DMARC mit `pass` bewertet sind.

DMARC zunächst auf `p=none` mit Berichtsadresse setzen und erst nach einigen Tagen ohne Beanstandung verschärfen.

## Offene Prüfpunkte

1. **Frage:** Soll die Einzugskontingent-Warnung (`plan_quota_warning_maybe_send()`,
   `app/plans.php:341`) auf `mail_layout()` umgestellt werden, damit sie wie alle anderen Vorlagen
   das CI der Müller Holding AG und die Pflichtangaben nach § 80 AktG trägt? **Quelle:**
   `app/plans.php:320-341` (reiner Text, keine HTML-Fassung, kein Fußband). **Prüfverfahren:**
   `php tools/mail-ci-check.php` erweitern, sodass es auch diese Vorlage erfasst (aktuell laut
   CLAUDE.md nur „Vorlagen Willkommen und Vormerkung" genannt); danach Testlauf mit
   `bin/mail-check.php --send`.
2. **Frage:** Gibt es einen Wiederversand-/Pending-Mechanismus für Einladungen
   (`mail_tpl_invitation()`), falls der Versand beim Anlegen fehlschlägt, vergleichbar mit
   `welcome_mail_pending`? **Quelle:** `team.php:50-51` (kein Pending-Flag im Code gefunden).
   **Prüfverfahren:** Testfall mit `mail.enabled = false` bzw. simuliertem SMTP-Fehler beim Einladen
   eines Mitarbeiters; prüfen, ob die Einladung ohne erneuten manuellen Versand nutzlos bleibt.
3. **Frage:** Ist eine E-Mail-Benachrichtigung bei Störungen der öffentlichen Statusseite an
   Endnutzer (nicht Plattformadministratoren) vorgesehen oder bewusst nicht umgesetzt?
   **Quelle:** kein Fund außerhalb von `app/monitor.php:1481-1488` (nur `alert_emails` der
   Administratoren). **Prüfverfahren:** Abgleich mit `docs/status-page.md` und Rückfrage bei der
   Produktverantwortlichen, ob ein Abonnement-Mechanismus für Kunden geplant ist.
4. **Frage:** Sind SPF, DKIM und DMARC für die tatsächlich verwendete Absenderdomain
   (`kontakt@smart-einzug.de`) im Produktivbetrieb eingerichtet und ist die DKIM-Signatur bei IONOS aktiv?
   **Quelle:** siehe Abschnitt „SPF, DKIM, DMARC" oben. **Prüfverfahren:** DNS-Abfrage gegen die
   produktiv genutzte Absenderdomain (`dig TXT`, `dig TXT default._domainkey.<domain>` oder den vom
   Mailanbieter genannten Selektor) und Abgleich mit den Vorgaben des eingesetzten
   SMTP-Anbieters.

## Zustellbarkeitsprüfung (4.60)

`app/mail_dns.php` wertet SPF, DMARC, DKIM und die Absenderkonsistenz aus, ohne selbst DNS abzufragen; die Abfrage liegt in
`bin/mail-check.php --zustellbarkeit` (nur dort, damit die Logik in `tools/mail-dns-check.php` ohne Netz geprüft werden kann).
Regeln: genau ein `v=spf1`-Eintrag, Anbieter des SMTP-Relays muss im Eintrag vorkommen, Transport `mail` ist eine Warnung
(Server-Adresse müsste im SPF stehen); DMARC als CNAME oder mit fremdem `rua` ist eine Warnung, fehlende Richtlinie ein
Fehler; DKIM nur mit Verweis ohne Schlüssel ist UNKLAR (Signatur beim Anbieter vermutlich nicht eingeschaltet); Absender und
Versandpostfach auf verschiedenen Domains ist ein Fehler (Alignment), Reply-To auf fremder Domain eine Warnung. Der
Gesamtstatus ist der schlechteste Einzelwert; Exit 1 bei FEHLT oder UNKLAR. Betriebsanleitung: `docs/mail-einrichtung.md`.
