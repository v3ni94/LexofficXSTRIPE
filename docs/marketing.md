# Marketingmodul: Werbe- und Informationsnachrichten (seit 4.63)

Stand: 11.09.2026. Das Marketingmodul versendet Werbe- und Informationsnachrichten an eigene Kunden und importierte
Geschäftskontakte aus dem Adminbereich (Reiter Marketing, `admin-marketing.php`, Logik in `app/marketing.php`). Es ist
bewusst vom Systemversand getrennt: eigener Absender auf einer eigenen Subdomain, eigener SMTP-Weg über Amazon SES,
eigene Ratenbegrenzung, eigene Sperrliste. Die Systemmails (Bestätigungen, Sicherheitshinweise, Vorabankündigungen) laufen
unverändert über `kontakt@smart-einzug.de` und IONOS (`docs/mail-einrichtung.md`).

## Grundsätze

- **Quellen der Empfänger:** ausschließlich CSV-Importe des Betreibers und die Firmenaccounts der Plattform (Inhaber,
  wahlweise Administratoren aktiver Firmen). Die Kunden der Firmen (Rechnungsempfänger, Mandatsinhaber in `customers`)
  werden im Auftrag verarbeitet und sind keine Quelle; `app/marketing.php` liest die Tabelle nicht. Vormerkungen
  (`interest_registrations`) sind ebenfalls keine Quelle, weil ihre Einwilligung nur Nachrichten zur vorgemerkten Anbindung
  deckt.
- **Rechtsgrundlage je Import:** Jeder CSV-Import trägt eine Rechtsgrundlage (Bestandskunde, Einwilligung, Geschäftskontakt
  B2B, Sonstiges mit Pflichtvermerk) und einen Vermerk zur Herkunft. Die Bewertung trifft der Betreiber. Einschätzung ohne
  Gewähr: Werbung per E-Mail setzt nach dem Wettbewerbsrecht grundsätzlich eine vorherige ausdrückliche Einwilligung
  voraus, auch gegenüber Unternehmen; ohne Einwilligung ist sie nur gegenüber eigenen Kunden für ähnliche Leistungen und
  nur mit Hinweis auf die Widerspruchsmöglichkeit zulässig. Der Betreiber hat entschieden, B2B-Kontakte ohne gesonderte
  Einwilligung zu importieren (Vorgabe 11.09.2026); die Anwendung dokumentiert diese Entscheidung je Import, ersetzt aber
  keine anwaltliche Prüfung.
- **Sperrliste dauerhaft:** Abmeldungen (Link und One-Click), Beschwerden und harte Rückläufer aus SES sowie von Hand
  gesperrte Adressen stehen listenübergreifend in `marketing_suppressions`. Eine gesperrte Adresse wird beim Import, bei der
  Freigabe und unmittelbar vor jedem Senden geprüft und nie angeschrieben, auch nach erneutem Import. Abmeldungen und
  Beschwerden lassen sich im Adminbereich nicht aufheben; nur Rückläufer und manuelle Sperren mit Grund und Protokoll.
- **Jede Nachricht** entsteht über `mail_layout()` (CI der Müller Holding AG, Pflichtangaben nach § 80 AktG), trägt einen
  Abmeldelink mit eigenem Token, die Kopfzeilen `List-Unsubscribe` und `List-Unsubscribe-Post` (One-Click nach RFC 8058)
  sowie `Precedence: bulk`, und nennt im Fußtext den Grund der Zusendung. HTML im Text ist nicht erlaubt; die Gestaltung
  übernimmt die Vorlage. Platzhalter `{{name}}` und `{{firma}}` werden je Empfänger ersetzt.
- **Freigabe nur nach Test und mit 2FA-Code:** Ein Massenversand ist außenwirksam und nicht rückholbar. Er setzt einen
  Testversand an eine frei gewählte Adresse voraus (jede Inhaltsänderung verlangt einen neuen Test) und verlangt den
  aktuellen 2FA-Code (`require_recent_totp()`, wie der Wechsel in Kundenaccounts). Der Versand lässt sich anhalten,
  fortsetzen und abbrechen; gesendete Nachrichten bleiben gesendet.

## Rechte

`marketing.view` liest Listen, Sperrliste, Kampagnen, Statistik und exportiert CSV; `marketing.manage` importiert,
pflegt die Sperrliste, legt Kampagnen an, testet, gibt frei und setzt die Ratenbegrenzung. Keine Systemrolle außer
Administrator erhält die Rechte automatisch; sie werden über eigene Rollen bewusst vergeben (`admin-users.php`).
Jede Funktion in `app/marketing.php` prüft ihr Recht selbst (`marketing_require()`), die Seite blendet nur aus.

## Ablauf im Adminbereich

1. **Ratenbegrenzung** (Übersicht): Nachrichten je Sekunde und je 24 Stunden (`platform_settings`
   `marketing_rate_per_second`, `marketing_rate_per_day`; Vorgabe 1 und 200, die Grenzen der SES-Sandbox). Die
   Tagesgrenze zählt Kampagnen- und Testnachrichten der letzten 24 Stunden. Erst nach Produktionsfreigabe des SES-Kontos
   anheben; die zulässigen Werte stehen in der SES-Konsole unter Sending quotas.
2. **Listen:** Importliste anlegen und CSV hochladen oder Zeilen einfügen (Kopfzeile mit E-Mail, Name oder Vorname und
   Nachname, Firma; ohne Kopfzeile Spalte 1 E-Mail, 2 Name, 3 Firma; Semikolon, Komma oder Tabulator; höchstens 20.000
   Zeilen, 5 MB). Der Import meldet neu, bereits vorhanden, ungültig und gesperrt. Systemliste: Inhaber oder Inhaber und
   Administratoren aktiver Firmen als Bestandskunden, jederzeit aktualisierbar (entfernte Mitglieder werden auf `removed`
   gesetzt). CSV-Export je Liste (formelsicher, UTF-8 mit BOM).
3. **Sperrliste:** Adresse von Hand sperren (mit Vermerk), suchen, Rückläufer- und manuelle Sperren mit Grund aufheben.
4. **Kampagne:** interner Name, Betreff, Überschrift, Text (Absätze durch Leerzeilen), optional Schaltfläche (https),
   Fußnote, Listen. Vorschau mit Musterdaten im abgeschirmten Fenster (sandbox-iframe mit eingebettetem HTML per `srcdoc`, weil Caddy
   für den Adminhost `X-Frame-Options: DENY` setzt und ein nachgeladenes Dokument die Einbettung verweigern würde), zusätzlich
   als eigene Seite `?vorschau=` in neuem Fenster (eigene CSP) und als Textfassung. Testversand an eine eigene Adresse (Betreff mit Vorsatz TEST, zählt zur Tagesgrenze). Freigabe mit 2FA-Code:
   Die Anwendung bildet je Adresse genau eine Versandzeile (`marketing_sends`, Dublette über mehrere Listen wird einmal
   angeschrieben), markiert gesperrte Adressen als übersprungen und reiht den Versandjob ein.
5. **Versand:** Jobtyp `marketing_send` im Pool `mail` (Container `worker-mail`), ein Job für alle Kampagnen
   (`dedupe_key marketing:send`). Der Job beansprucht jede Adresse einzeln (`UPDATE ... WHERE status = 'queued'`), prüft die
   Sperrliste erneut, erzeugt den Abmeldetoken (nur als Hash gespeichert), hält den Abstand je Sekunde ein und endet nach
   50 Sekunden mit Fortsetzung (Fairness: Systemmails des Pools gehen vor). Bei erreichter Tagesgrenze endet der Job; der
   Scheduler reiht ihn alle 300 Sekunden neu ein, solange Kampagnen offen sind. Ein Transportfehler stellt die Adresse
   zurück und beendet den Versuch mit Backoff; eine endgültige Ablehnung des Servers markiert die Adresse als fehlgeschlagen.
   Ohne Warteschlange (Webhosting) verarbeitet `cron.php` die Jobs inline, sofern `features.queue` gesetzt ist.
6. **Ereignisse:** Abmeldungen, Tests, Rückläufer, Beschwerden und Zustellungen aus SES stehen in `marketing_events`.

## Abmeldung

`abmelden.php?t=<token>` zeigt eine Seite mit der Schaltfläche „Keine weiteren Nachrichten“; der POST sperrt die Adresse
dauerhaft (Grund `unsubscribe`). Ein POST mit dem Feld `List-Unsubscribe=One-Click` (Postfachanbieter) sperrt ohne
Rückfrage und antwortet mit `ok`. Der Token stammt aus der Nachricht (je Empfänger und Kampagne, 48 Hexzeichen, nur als
SHA-256 gespeichert) und ist die einzige Berechtigung; kein Sitzungs-CSRF, weil kein Benutzer angemeldet ist und ein POST
höchstens die gewünschte Abmeldung bewirkt. Die Seite trägt kein Tracking (Token in der Adresse, `TRACKING_PUBLIC_PAGES`
enthält sie nicht).

## Amazon SES einrichten (Betreiber, Schritt für Schritt)

Voraussetzung: AWS-Konto der Müller Holding AG mit SES in einer festen Region (empfohlen `eu-central-1`, Frankfurt).
Werte aus der SES-Konsole übernehmen, nichts raten. Zugangsdaten nie ins Repository, nur nach `shared/config.php`.

1. **Identität anlegen:** SES, Verified identities, Create identity, Typ Domain, Domain `mail.smart-einzug.de`. Easy DKIM
   mit 2048 Bit wählen. Die Konsole zeigt drei CNAME-Einträge `<selector>._domainkey.mail.smart-einzug.de`.
2. **Custom MAIL FROM domain** in derselben Identität setzen, zum Beispiel `bounce.mail.smart-einzug.de`. Die Konsole zeigt
   einen MX-Eintrag (`feedback-smtp.<region>.amazonses.com`, Priorität 10) und einen TXT-Eintrag
   `v=spf1 include:amazonses.com ~all` für diese Subdomain. Damit stimmen SPF und DKIM mit dem sichtbaren Absender überein
   (Alignment) und DMARC der Hauptdomain besteht.
3. **IONOS DNS eintragen** (Zone smart-einzug.de): die drei DKIM-CNAMEs, den MX und den TXT der MAIL-FROM-Domain sowie
   für die Absenderdomain `mail.smart-einzug.de` einen TXT `v=spf1 include:amazonses.com ~all`. Die bestehenden Einträge
   der Hauptdomain (IONOS-SPF, IONOS-DKIM, DMARC) bleiben unverändert. Nach der Verbreitung zeigt SES die Identität als
   Verified und DKIM als Successful.
4. **Postfach `kontakt@mail.smart-einzug.de`:** SES sendet nur. Antworten gehen an `reply_to` (`kontakt@smart-einzug.de`,
   dieselbe registrierbare Domain). Eine Weiterleitung für `kontakt@mail.smart-einzug.de` ist nur nötig, wenn Empfänger
   direkt an den Absender schreiben; sonst laufen Antworten über Reply-To.
5. **SMTP-Zugangsdaten:** SES, SMTP settings, Create SMTP credentials. Prüfung danach im php-Container mit
   `php bin/mail-check.php --marketing` (Zustand) und `--marketing --send=ADRESSE` (Testnachricht über das Profil). Der Endpunkt lautet
   `email-smtp.<region>.amazonaws.com`, Port 587 mit STARTTLS. Benutzername und Passwort nach `shared/config.php`, Block
   `mail_marketing` (siehe `config.example.php`): `enabled` true, `transport` smtp, `from_address`
   `kontakt@mail.smart-einzug.de`, `reply_to` `kontakt@smart-einzug.de`, `webhook_token` als Zufallswert mit mindestens 32
   Zeichen. Danach `bash scripts/restart-workers.sh`. Zugangsdaten, die außerhalb der Konfiguration abgelegt oder
   übertragen wurden, in IAM löschen und neu erzeugen.
6. **Sandbox verlassen:** Neue SES-Konten senden nur an verifizierte Adressen und höchstens 200 Nachrichten je 24 Stunden
   bei 1 je Sekunde. Produktionsfreigabe in der SES-Konsole beantragen (Account dashboard, Request production access) mit
   Angabe des Zwecks, der erwarteten Menge und des Abmeldeverfahrens. Erst danach die Ratenbegrenzung im Adminbereich
   anheben.
7. **Rückläufer und Beschwerden:** SNS, Topics, Create topic (Standard, zum Beispiel `smarteinzug-ses-feedback`). Create
   subscription, Protokoll HTTPS, Endpoint `https://app.smart-einzug.de/marketing-webhook.php?token=<webhook_token>`.
   Die Anwendung bestätigt das Abonnement selbst (Aufruf der SubscribeURL, nur amazonaws.com) und protokolliert das als
   Ereignis `subscription`. In der SES-Identität unter Notifications das Thema für Bounce und Complaint (optional Delivery)
   eintragen, Include original email headers nicht nötig. Harte Rückläufer und Beschwerden sperren die Adresse dauerhaft.
   SES verlangt niedrige Bounce- und Beschwerdequoten; ohne diese Anbindung droht die Sperre des SES-Kontos.
8. **Nachweis:** Kampagne anlegen, Testnachricht an ein Gmail-Postfach, „Original anzeigen“: SPF PASS für
   `bounce.mail.smart-einzug.de`, DKIM PASS mit `d=mail.smart-einzug.de`, DMARC PASS, Kopfzeilen `List-Unsubscribe` und
   `Precedence: bulk`, Schaltfläche „Abbestellen“ neben dem Absender.

Bewusste Annahmen: Der Aufbau der SNS-Signaturprüfung (Felder und Reihenfolge der zu signierenden Zeichenkette,
SignatureVersion 1 und 2) folgt der AWS-Dokumentation und ist im Prüfstand mit einem selbst erzeugten Zertifikat
belegt, nicht mit einem echten SNS-Ereignis; der erste echte Rückläufer bestätigt das (Ereignis `bounce` in der Tabelle,
sonst Eintrag „ungueltige Signatur“ im Anwendungsprotokoll). Die Struktur der SES-Ereignisse (`notificationType`,
`bounce.bounceType`, `bouncedRecipients`, `complaint.complainedRecipients`) folgt ebenfalls der AWS-Dokumentation.

## Konfiguration und Betrieb

- `mail_marketing` in `shared/config.php` (siehe oben); `mail_profile_config('marketing')` in `app/mailer.php`. Ist das
  Profil nicht aktiv, lassen sich Kampagnen anlegen und in der Vorschau prüfen, aber weder testen noch versenden.
- Ratenbegrenzung in `platform_settings` (Adminbereich, Audit `marketing_rates_changed`).
- Tabellen (Migration 034): `marketing_lists`, `marketing_recipients`, `marketing_suppressions`, `marketing_campaigns`,
  `marketing_sends`, `marketing_events`; Zeitpunkte in UTC. Versendete Kampagnen und ihre Versandzeilen bleiben als
  Nachweis (nicht löschbar); Entwürfe und abgebrochene Kampagnen sind löschbar; Listen erst, wenn keine laufende Kampagne
  sie verwendet. Die Sperrliste wird durch keine Löschung berührt.
- Audit: `marketing_list_created/imported/synced/exported/deleted`, `marketing_recipient_removed`,
  `marketing_suppressed/unsuppressed` (Adresse nur als Kennung `mail_addr_ref()`), `marketing_campaign_created/updated/
  test_sent/started/paused/queued/cancelled/sent/deleted`, `marketing_rates_changed`.
- Prüfstand: `bash tools/marketing-check.sh` (temporäre MariaDB, Transport log, 137 Fälle: Rechte, Raten, CSV, Systemliste
  ohne Endkunden, Sperrliste, Kampagne, Test, Freigabe, Versand mit Tages- und Sekundengrenze, Abmeldung, One-Click, SES,
  SNS-Signatur, Export, Audit); `php tools/totp-policy-check.php` (2FA für `campaign_start`); `bash tools/test-guard-check.sh`
  (Prüfstände dürfen `mail_marketing` nur mit Transport log und nie mit SES-Host laden).
