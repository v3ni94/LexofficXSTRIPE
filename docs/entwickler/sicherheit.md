# Sicherheit, Rollen und Geheimnisverwaltung

Stand: 07.09.2026. Ausgewertet aus `php-ionos/app/auth.php`, `php-ionos/app/totp.php`,
`php-ionos/app/devices.php`, `php-ionos/app/support.php`, `php-ionos/app/audit.php`,
`php-ionos/app/crypto.php`, `php-ionos/app/bootstrap.php`, `php-ionos/app/config.example.php`,
`php-ionos/sql/schema.sql`, `deploy/vps/Caddyfile`, `php-ionos/.htaccess`,
`.github/workflows/deploy.yml`. Jede Aussage ist mit Datei:Funktion bzw. Datei:Zeile belegt; nicht
Auffindbares ist als „nicht gefunden"/„nicht dokumentiert" gekennzeichnet. Keine echten Geheimnisse,
nur Bezeichner und Ablageorte.

## Anmeldung und Sitzungen

- **Ablauf:** Passwort (`auth_login()`, `app/auth.php:402-459`) → bei eingerichteter 2FA:
  TOTP- oder Recovery-Code (`auth_login_2fa()`, Zeilen 483-535) → Session. Ohne eingerichtete 2FA wird
  der Nutzer nach dem Passwort sofort angemeldet, `require_login()` erzwingt danach zwingend die
  2FA-Einrichtung (`app/auth.php:198-218`, `config('require_2fa')` Standard `true`,
  `config.example.php:134`).
- **Passwort-Hashing:** `password_hash($password, PASSWORD_DEFAULT)` bei Registrierung
  (`app/auth.php:1076`), Passwortwechsel (Zeile 962) und Reset (Zeile 943); Prüfung über
  `password_verify()` (Zeile 414); automatisches Rehashing bei veraltetem Hash-Format
  (`password_needs_rehash()`, Zeilen 429-432). Mindestlänge 10, Höchstlänge 200 Zeichen, darf nicht der
  E-Mail-Adresse entsprechen (`validate_password()`, Zeilen 333-345).
- **Sitzungscookie** (`app/bootstrap.php:387-400`): Name `LXEINZUGSESSID`
  (Zeile 398), `secure` nur bei HTTPS (Zeile 391), `httponly = true` (Zeile 392), `samesite = 'Lax'`
  (Zeile 393), `session.use_strict_mode = 1` (Zeile 395, keine fremd vorgegebenen Session-IDs),
  `session.use_only_cookies = 1` (Zeile 396), `session_cache_limiter('nocache')` (Zeile 397, Seiten mit
  Kundendaten nie im Browser-/Proxy-Cache). Sitzungen für Webhook/Cron werden über `define('SKIP_SESSION',
  true)` bewusst nicht gestartet (Zeile 387, z. B. `health.php:9`).
- **`session_epoch`:** jede Sitzung trägt beim Anmelden den aktuellen `users.session_epoch`
  (`app/auth.php:571`); `current_user()` vergleicht ihn bei jeder Anfrage gegen die Datenbank
  (Zeilen 99-104), weicht er ab (Passwort/2FA zurückgesetzt, Nutzer entfernt/gesperrt), wird die
  Sitzung sofort beendet. Erhöht wird `session_epoch` u. a. bei `password_reset_complete()`
  (Zeile 942), `password_change()` (indirekt über `devices_revoke_all`, siehe unten) und
  `twofa_reset()`/`user_revoke_sessions()` (Zeilen 584-587).
- **Session-Regenerierung:** `session_regenerate_id(true)` bei Übergang in die 2FA-Wartestufe
  (Zeile 449) und beim endgültigen Login-Abschluss (`session_finish_login()`, Zeile 568), verhindert
  Session-Fixation.

## Zwei-Faktor-Authentifizierung (TOTP)

- **Algorithmus:** TOTP nach RFC 6238 auf Basis HOTP nach RFC 4226 (`app/totp.php:2-3, 91-104,
  109-128`), Base32-kodiertes Geheimnis (RFC 4648, `base32_encode()`/`base32_decode()`, Zeilen 20-73),
  20 Byte Zufallsgeheimnis (`totp_generate_secret()`, Zeilen 78-84), 6-stelliger Code, 30-Sekunden-Schritt,
  SHA-1 (Standardparameter `totp_verify()`, Zeile 136).
- **Replay-Schutz:** `totp_verify()` prüft ein Fenster von ±1 Schritt (`$window = 1`) und lehnt jeden
  Schritt ≤ `$lastUsedStep` ab (Zeilen 152-163); der akzeptierte Schritt wird als neuer
  `users.totp_last_step` gespeichert (`twofa_verify_user()`, `app/auth.php:679`), derselbe Code kann
  nicht zweimal verwendet werden.
- **Speicherung:** `users.totp_secret_encrypted` (verschlüsselt über `encrypt_value()`,
  `app/auth.php:649`), Klartext-Geheimnis nur während der Einrichtung in der Session
  (`$_SESSION['totp_setup_secret']`, `twofa_begin_setup()`, Zeile 616-622).
- **Recovery-Codes:** 10 Codes im Format `XXXX-XXXX-XXXX`, Alphabet ohne `0/O/1/I`
  (`RECOVERY_ALPHABET`, `app/totp.php:184`), Erzeugung über `random_int()`
  (`recovery_codes_generate()`, Zeilen 190-206); gespeichert wird ausschließlich
  `HMAC-SHA256(normalisierter Code, app_secret)` (`recovery_code_hash()`, Zeilen 225-231,
  `user_recovery_codes.code_hash`), Verbrauch ist einmalig (`recovery_code_consume()`,
  `app/auth.php:753-765`, `UPDATE ... WHERE used_at IS NULL`).
- **`require_recent_totp()`** (`app/auth.php:689-711`): Zweitbestätigung kritischer Aktionen
  (z. B. Not-Stopp, Admin-Support-Zugriff, Rechtsdokumente veröffentlichen). Akzeptiert entweder einen
  frisch eingegebenen Code oder, mit `$allowFreshWindow = true`, ein Frischefenster von 300 s nach
  der letzten echten Authenticator-Bestätigung (`TOTP_FRESH_SECONDS`, `app/devices.php:21`,
  `totp_is_fresh()`, Zeilen 336-339). Recovery-Codes werden hier bewusst **nicht** akzeptiert
  (Kommentar `app/auth.php:687`). Ein Fehlschlag protokolliert `twofa_reauth_failed` im Audit
  (Zeile 705-707).
- **`require_superadmin()`** verlangt zusätzlich zu `is_superadmin = 1` eine aktive 2FA
  (`(int)$ctx['totp_enabled']`, `app/auth.php:280`), ein Superadmin-Konto ohne eingerichtete 2FA hat
  keinen Zugriff auf den Adminbereich.

## Gerätevertrauen (90 Tage)

Umgesetzt in `app/devices.php` (Migration 016, `devices_available()` Zeilen 138-150 prüft die
Tabelle `trusted_devices` und liefert `false` statt eines Fehlers, wenn sie fehlt):

- **Dauer:** `DEVICE_TRUST_DAYS = 90` (`app/devices.php:19`), Ablaufzeitpunkt = Freigabezeitpunkt +
  90 Tage in UTC, wird durch spätere Anmeldungen oder Token-Rotation **nicht** verlängert
  (`device_trust_create()`, Zeile 165; Kommentar Zeile 10-11).
- **Cookie:** `<id>.<geheim>` (Regex-Format `app/devices.php:85`), Name mit `__Host-`-Präfix sobald
  HTTPS aktiv ist (`device_cookie_name()`, Zeilen 65-68), `httponly`, `samesite=Lax`, `secure` bei
  HTTPS (`device_cookie_options()`, Zeilen 70-79). Serverseitig wird nur
  `HMAC-SHA256(geheimer Teil, app_secret)` gespeichert (`device_token_hash()`, Zeilen 109-112,
  `trusted_devices.token_hash`).
- **Rotation:** bei jeder erfolgreichen Anmeldung über eine Freigabe wird der geheime Teil rotiert
  (`device_trust_rotate()`, Zeilen 234-245), atomar über den alten Hash (`UPDATE ... WHERE token_hash =
  ?`), verliert eine parallele Anfrage das Rennen, bleibt deren Cookie gültig, das
  Ablaufdatum bleibt unverändert.
- **Bereichstrennung:** `device_scope()` unterscheidet `app` und `admin`
  (`on_admin_host()`-Abfrage, `app/devices.php:24-27`), eine Freigabe des einen Bereichs gilt nicht
  im anderen (`device_trust_check()`, Zeile 217).
- **Widerruf:** bei Passwortänderung (`password_change()`, `app/auth.php:963`), 2FA-Änderung
  (`twofa_confirm_setup()`, Zeile 655; `twofa_reset()`, Zeile 787), Passwort-Reset
  (`password_reset_complete()`, Zeile 944), explizitem „Abmelden und Gerät vergessen"
  (`auth_logout(true)`, Zeilen 589-596) sowie einzeln über die Sicherheitsseite
  (`device_revoke()`, Zeilen 268-280). Jede Änderung der 2FA-Konfiguration verwirft **alle**
  bestehenden Gerätefreigaben (`devices_revoke_all()`, Kommentar `app/auth.php:654`).
- **Bereinigung:** `devices_cleanup()` (`app/devices.php:321-328`) löscht widerrufene/abgelaufene
  Einträge nach 30 Tagen, aufgerufen aus `job_maintenance()` (siehe `docs/entwickler/jobs.md`).
- Ausführliche Fachdarstellung inkl. Testfällen: `docs/device-trust.md` (nicht erneut dupliziert).

## Rollen: owner, admin, member, superadmin

Definiert im Kommentarkopf `app/auth.php:6-12` und in den Prüffunktionen:

| Rolle | Darf laut Code |
|---|---|
| `owner` (Inhaber) | Alles inkl. Mitgliederverwaltung, Rollen, Inhaberschaft, Abonnement, Löschung der Firma (`can_manage_members()`, `app/auth.php:303-306`: `$ctx['role'] === 'owner'`; `require_owner()`, Zeilen 267-274; `require_owner_action()`, `team.php:23`) |
| `admin` (Administrator) | Wie `member`, zusätzlich API-Verbindungen (Lexware Office, Stripe) und Firmendaten ändern (`can_manage_settings()`, `app/auth.php:309-312`: `in_array($ctx['role'], ['owner', 'admin'], true)`, genutzt u. a. in `settings.php:18`, `notstopp.php:15`, `stripe-import.php:13`) |
| `member` (Mitarbeiter) | Voller operativer Zugriff (Synchronisation, Rechnungen, Einzüge, Kunden, SEPA pflegen), keine Mitglieder-/Abo-Verwaltung, keine API-Verbindungen (Kommentar `app/auth.php:11-12`) |
| `superadmin` (`users.is_superadmin = 1`) | Plattformweiter Zugriff auf den Adminbereich, unabhängig von einer Firmenmitgliedschaft; verlangt zusätzlich aktive 2FA (`require_superadmin()`, `app/auth.php:277-284`) |

Rollen sind je Firma (`organization_members.role`) vergeben, `superadmin` ist ein globales Attribut
des Nutzerkontos (`users.is_superadmin`) und unabhängig davon.

## Support-Modus

`app/support.php`: Ein Superadmin kann zeitlich begrenzt „auf eine Firma wechseln":

- Anlage nur mit Begründung (5 bis 255 Zeichen, `support_session_create()`, `app/support.php:21-26`) und
  bereits aktueller 2FA (Aufrufstelle `admin-support.php`, hier nicht erneut ausgewertet, siehe
  `docs/entwickler/schnittstellen.md`).
- Einmal-Token: nur als SHA-256-Hash gespeichert (`token_hash()`, wiederverwendet aus `app/auth.php`),
  5 Minuten einlösbar (`SUPPORT_REDEEM_MINUTES`, `app/support.php:17`), Einlösung löscht den Hash
  (`support_session_redeem()`, Zeilen 50-73, `token_hash = NULL` nach Einlösung).
- Sitzungsdauer höchstens 60 Minuten (`SUPPORT_SESSION_MINUTES`, Zeile 18).
- Im Support-Modus gilt serverseitig die Rolle `admin` (`_current_user_support()`,
  `app/auth.php:140`), **unabhängig** von der tatsächlichen Mitgliedschaft (der Support-Nutzer ist
  gar kein Mitglied der Zielfirma), es existiert kein `organization_members`-Eintrag.
- **Sperre für geldrelevante Aktionen:** `support_guard()` (`app/support.php:105-110`) wirft eine
  `RuntimeException` „Im Support-Modus sind Einzüge, IBAN-Änderungen und Zugangsdaten gesperrt."
, der Aufrufer muss `support_guard()` an den entsprechenden Stellen selbst aufrufen (Prüfstellen
  in `customer.php`/`settings.php`/`collections.php` hier nicht einzeln nachgewiesen, nur die
  zentrale Sperrfunktion).
- Jede Aktion im Support-Modus trägt im Audit-Log den Vermerk `support_session`
  (`audit_log()`, `app/audit.php:45-47`, automatisch angehängt).
- Der Inhaber der Zielfirma wird laut Kommentarkopf (`app/support.php:9`) per Sicherheits-E-Mail über
  den Zugriff informiert (konkrete Versandstelle nicht in dieser Datei, siehe
  `docs/entwickler/email-system.md`, Ereignis nicht in dortiger Tabelle explizit geführt, **nicht
  gefunden** als eigener `mail_tpl_*`, vermutlich über `security_notify_owner()`,
  `app/auth.php:816-826`).

## Mandantentrennung (`tenant_id`)

Jede Abfrage auf mandantenbezogene Tabellen filtert zusätzlich zum fachlichen Schlüssel auf
`tenant_id = ?` mit dem Wert aus `$ctx['org_id']` (Session-Kontext). Beispiele:

- `customer.php:21`: `SELECT * FROM customers WHERE id = ? AND tenant_id = ?`
- `customer.php:127, 150, 159`: IBANs, Rechnungen und Einzüge eines Kunden zusätzlich über
  `tenant_id = ?` gefiltert.
- `stripe-webhook.php:82, 91, 162-169, 211-214`: Zuordnung eines Stripe-Ereignisses zur Firma
  ausschließlich über `payment_collections.tenant_id`, nie über einen vom Client mitgesendeten Wert.
- `queue.php` (`app/queue.php`): Jobs tragen `tenant_id`; `queue_tenant_active()`
  (Zeilen 565-577) filtert explizit danach, bevor ein Nutzer den Zustand eines Jobs seiner Firma
  sieht.
- Der Session-Kontext selbst (`current_user()`, `app/auth.php:78-97`) bindet `org_id` fest an einen
  `organization_members`-Eintrag mit `status = 'active'`, ein Nutzer kann sich nicht durch
  Parametermanipulation in eine fremde `org_id` einloggen, da jede Anfrage serverseitig gegen die
  Session (`$_SESSION['org_id']`) und nicht gegen einen Client-Parameter geprüft wird.

## Verschlüsselung (`app/crypto.php`)

- **Algorithmus:** AES-256-GCM (`encrypt_value()`/`decrypt_value()`, `app/crypto.php:23-51`),
  zufälliger 12-Byte-IV je Wert (`random_bytes(12)`, Zeile 28), Format
  `base64(iv[12] | tag[16] | ciphertext)` (Kommentarkopf Zeile 4).
- **Schlüsselableitung:** `hash('sha256', app_secret, true)` (`crypto_key()`, Zeilen 14-21),
  verlangt `app_secret` mit mindestens 32 Zeichen, sonst `RuntimeException`.
- **Verschlüsselte Spalten** (laut `sql/schema.sql`): `users.totp_secret_encrypted` (Zeile 92),
  `integrations.lexoffice_api_key_encrypted` (Zeile 407), `integrations.stripe_secret_key_encrypted`
  (Zeile 408), `integrations.stripe_webhook_secret_encrypted` (Zeile 409). Verwendet u. a. von
  `twofa_confirm_setup()` (`app/auth.php:649`), `stripe-webhook.php:114` (Entschlüsselung des
  Firmen-Webhook-Secrets), `app/integrations.php` (Ver-/Entschlüsselung der API-Schlüssel, dort nicht
  im Detail erneut ausgewertet).
- **Warnung im Code:** `app_secret` darf „nach Inbetriebnahme nicht mehr geändert werden, sonst sind
  gespeicherte API-Keys und 2FA-Geheimnisse nicht mehr entschlüsselbar"
  (`app/config.example.php:43-47`), es gibt **keinen** dokumentierten Rotationsmechanismus für
  `app_secret` selbst (siehe Offene Prüfpunkte).

## CSRF-Schutz

- Token je Sitzung: `csrf_token()` erzeugt bei Bedarf `bin2hex(random_bytes(32))`
  (`app/bootstrap.php:420-426`), ausgegeben über `csrf_field()` (Zeilen 428-431) als verstecktes
  Formularfeld.
- Prüfung: `csrf_check()` vergleicht `$_POST['csrf_token']` mit `hash_equals()` gegen den
  Sitzungswert, bricht sonst mit HTTP 403 ab (`app/bootstrap.php:434-441`).
- Der Token wird beim endgültigen Login-Abschluss verworfen (`unset($_SESSION['csrf_token'], ...)`,
  `app/auth.php:574`), nach der Anmeldung wird ein neuer Token erzeugt, ein vor der Anmeldung
  ausgestellter Token ist danach ungültig.

## Ratenbegrenzung und Kontosperren

- **Anmeldung:** höchstens `LOGIN_MAX_FAILS_EMAIL = 5` Fehlversuche je E-Mail-Adresse in
  `LOGIN_LOCK_MINUTES = 15` Minuten, höchstens `LOGIN_MAX_FAILS_IP = 30` Fehlversuche je IP im
  selben Fenster (`app/auth.php:33-35, 359-391`); zusätzlich Kontosperre `users.locked_until` nach
  10 Fehlversuchen in Folge (`auth_login()`, Zeilen 417-425).
- **2FA-Code:** höchstens `TWOFA_MAX_FAILS = 5` Versuche je Anmeldevorgang, danach muss die
  Anmeldung neu begonnen werden (`app/auth.php:36, 489-494`); die 2FA-Wartestufe verfällt nach
  `PENDING_2FA_TTL = 600` Sekunden (Zeile 37, `pending_2fa_user()`, Zeilen 462-477).
- **Passwort-Reset:** höchstens 5 Anforderungen je IP/Adresse in 15 Minuten
  (`password_reset_request()`, `app/auth.php:890-899`).
- **Cookielose Funnel-Messung (`track.php`):** höchstens 300 Ereignisse je Domain und Minute
  (`track.php:45-55`).
- **Vormerkung (`vormerken.php`/`app/interest.php`):** globale Obergrenze neuer Zeilen je Minute
  (`INTEREST_MAX_PER_MINUTE = 30`), Wiederversand frühestens nach 10 Minuten
  (`INTEREST_RESEND_SECONDS = 600`), höchstens 3 Mails je Adresse in 24 Stunden
  (`INTEREST_MAILS_PER_DAY = 3`), Honeypot-Feld `website` (`app/interest.php:41-43`, Prüfung an
  anderer Stelle in derselben Datei).
- **Externe Aufrufe (Lexware Office, Stripe, sevdesk, Mail):** zentral über `api_call_gate()`
  (`app/queue.php:766-801`), siehe `docs/entwickler/schnittstellen.md`.

## Audit-Log (`app/audit.php`)

- Jede geldrelevante oder sicherheitskritische Aktion wird protokolliert: `audit_log(tenantId, actor,
  action, targetType, targetId, details)` (`app/audit.php:37-68`), gespeichert mit `user_id`,
  `user_email`, Aktion (max. 60 Zeichen), Zielobjekt, Detail-JSON und `client_ip()` (Zeile 22-29, nur
  gültige, formal geprüfte IP-Adressen, max. 45 Zeichen für IPv6).
- Ein aktiver Support-Modus wird automatisch als `details.support_session` mitgeschrieben
  (Zeilen 45-47); ebenso die aktuelle Correlation-ID, falls vorhanden (Zeile 48-50).
- Ein Fehler beim Schreiben des Audit-Eintrags bricht die eigentliche Aktion **nie** ab
  (`try/catch` um den `INSERT`, Zeilen 51-67, nur `error_log()`).
- **Aufbewahrung:** `AUDIT_RETENTION_DAYS = 90` (Zeile 70), konfigurierbar über
  `config('audit')['retention_days']`, Mindestwert 30 Tage erzwungen (`audit_retention_days()`,
  Zeilen 72-77); Löschung über `audit_cleanup()` (Zeilen 80-90, `DELETE ... LIMIT 5000` je Lauf),
  aufgerufen aus `job_maintenance()` (`app/jobs.php:293`) bzw. `cron.php:73`. Fachliche Nachweise
  (Einzüge, Mandate, Vertragszustimmungen) liegen in eigenen Tabellen und sind von dieser
  Aufbewahrungsfrist **nicht** betroffen (Kommentarkopf Zeilen 6-8).
- Über 90 Aktionsarten sind in `audit_action_label()` (Zeilen 111-216) mit einer lesbaren Bezeichnung
  hinterlegt (Anmeldung, Rollenänderung, IBAN-Änderungen, Einzüge, Support-Zugriffe,
  Migrationsergebnisse usw.).

## Host-Trennung app/admin/api

- **PHP-seitig:** `enforce_host_rules()` (`app/bootstrap.php:214-250`), siehe
  `docs/entwickler/schnittstellen.md`, Abschnitt a) für die vollständige Allowlist und
  Ausnahmeliste.
- **Webserver-seitig (VPS):** `deploy/vps/Caddyfile` bildet dieselbe Trennung zusätzlich auf
  Ebene des Reverse Proxys ab, der API-Host (`{$DOMAIN_API}`) liefert ausschließlich
  `/stripe-webhook.php`, `/billing-webhook.php`, `/health.php`, `/track.php` aus, jede andere Anfrage
  erhält 404 ohne `file_server` (Caddyfile, Block „API-Host: nur Webhooks, Health-Check und
  Tracking-Pixel"). App- und Adminhost teilen sich denselben Dokumentenstamm; die eigentliche
  Trennung liegt vollständig bei PHP.
- **IONOS-Webhosting:** `.htaccess` schützt `app/` und `sql/` vollständig vor Webzugriff
  (`RewriteRule ^app/ - [F,L]`, `RewriteRule ^sql/ - [F,L]`, `php-ionos/.htaccess:20-21`) sowie
  Dateiendungen `.sql`, `.md`, `.log`, `.jar`, `.example.php` (Zeilen 22-24); die Host-Trennung
  App/Admin selbst erfolgt auch dort ausschließlich über `app/bootstrap.php`, nicht über eigene
  Apache-Regeln (Kommentarkopf `.htaccess:5-9`).

## Content-Security-Policy

- **Gefunden:** ausschließlich auf dem Status-Host (`{$DOMAIN_STATUS}`, statische Statusseite ohne
  PHP): `Content-Security-Policy "default-src 'none'; script-src 'unsafe-inline'; style-src
  'unsafe-inline'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'"`
  (`deploy/vps/Caddyfile:136`).
- **Nicht gefunden:** eine Content-Security-Policy für die Kundenanwendung, den Adminbereich oder den
  API-Host. Weder `deploy/vps/Caddyfile` (Block `security_headers`, Zeilen 27-40) noch
  `php-ionos/.htaccess` (Zeilen 43-52) setzen dort einen CSP-Header, beide setzen `nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy`, `X-Robots-Tag: noindex, nofollow`,
  `Permissions-Policy` sowie (nur `.htaccess`, produktiv über IONOS-Webhosting) `Strict-Transport-
  Security`; im Caddyfile ist HSTS bewusst auskommentiert, bis App-, Admin- und API-Host dauerhaft
  ausschließlich über gültiges HTTPS erreichbar sind, und soll erst nach ausdrücklicher Freigabe der
  Geschäftsführung aktiviert werden (Kommentar Zeilen 33-36). Siehe Offene Prüfpunkte.

## Geheimnisverwaltung

| Secret-Bezeichnung | Verwendungszweck | Ablageort | Benötigte Berechtigung | Bereitstellungsweg | Rotationsverfahren |
|---|---|---|---|---|---|
| `app_secret` | Schlüssel für AES-256-GCM-Verschlüsselung der API-Keys und 2FA-Geheimnisse (`app/crypto.php:14-21`) | `shared/config.php` (VPS) bzw. `app/config.php` (IONOS), nie im Git (`config.example.php:6-7`) | Root/Betreiber mit Zugriff auf den Anwendungsserver | manuell in die Konfigurationsdatei eingetragen (`openssl rand -hex 32`, Kommentar `config.example.php:45`) | **nicht dokumentiert**, Kommentar warnt ausdrücklich davor, den Wert nach Inbetriebnahme zu ändern, da bestehende verschlüsselte Werte sonst unlesbar werden (`config.example.php:46-47`) |
| `cron_token` | Schutz von `cron.php`/`setup-check.php` (`cron.php:32-33`, `setup-check.php:44`) | `shared/config.php`/`app/config.php` | wie oben | manuell, mindestens 32 Zufallszeichen (`config.example.php:50`) | nicht dokumentiert; laut Code jederzeit änderbar (nur `hash_equals()`-Vergleich, kein gespeicherter abgeleiteter Wert) |
| `migration_token` | Schutz von `migrate.php` (Header `X-Migration-Token`, `migrate.php:57-66`) | `shared/config.php`/`app/config.php`, identisch mit GitHub-Secret `MIGRATION_TOKEN` | Deployment-Automat (GitHub Actions) | GitHub Actions setzt den Header aus `secrets.MIGRATION_TOKEN` (`.github/workflows/deploy.yml:232, 429`) | nicht dokumentiert; müsste an beiden Stellen (Server-Konfiguration und GitHub-Secret) synchron geändert werden |
| `db.pass` | MariaDB-Zugangsdaten (`app/bootstrap.php:253-269`, `db()`) | `shared/config.php`/`app/config.php`; VPS: Passwort stammt aus Coolify (private Datenbankressource) | Betreiber/Coolify-Administrator | IONOS-Kundenbereich bzw. Coolify-Oberfläche | nicht im Repository dokumentiert (liegt bei IONOS bzw. Coolify) |
| `mail.smtp.pass` | SMTP-Authentifizierung (`app/mailer.php:340-344`) | `shared/config.php`/`app/config.php` | Betreiber mit Zugriff auf das Postfach | manuell eingetragen | nicht dokumentiert |
| `billing.stripe_secret_key` | Plattform-Stripe-Konto der Müller Holding AG (Abonnements, `app/billing.php`) | `shared/config.php`/`app/config.php`, `sk_live_...` bzw. `sk_test_...` | Betreiber mit Zugriff auf das Stripe-Konto | manuell eingetragen; Prüfung nur lesend über `bin/billing-check.php` (Schlüssel maskiert ausgegeben) | nicht im Repository dokumentiert; Rotationsverfahren wäre Stripe-seitig (Schlüssel widerrufen/neu erzeugen), Ablauf hier nicht beschrieben |
| `billing.stripe_webhook_secret` | Signaturprüfung `billing-webhook.php:16-25` | `shared/config.php`/`app/config.php`, `whsec_...` | wie oben | manuell aus dem Stripe-Dashboard übernommen | nicht dokumentiert |
| `integrations.stripe_secret_key_encrypted`, `stripe_webhook_secret_encrypted`, `lexoffice_api_key_encrypted` | API-Zugangsdaten der einzelnen Firmen (SEPA-Einzüge, Lexware-Synchronisation) | Datenbanktabelle `integrations`, AES-256-GCM-verschlüsselt mit `app_secret` (`sql/schema.sql:407-409`) | Firma selbst (Rolle `owner`/`admin`, `can_manage_settings()`) über `settings.php` | über das Formular in `settings.php`, vor dem Speichern gegen die jeweilige API geprüft (Kommentarkopf `settings.php:3-6`), danach verschlüsselt abgelegt, nie wieder im Browser angezeigt | Rotation über erneutes Eintragen in `settings.php`; kein automatisierter Rotationsjob gefunden |
| `users.totp_secret_encrypted` | 2FA-Geheimnis je Nutzer | Datenbanktabelle `users`, AES-256-GCM-verschlüsselt (`app/auth.php:649`) | Nutzer selbst | Einrichtung über `twofa-setup.php` | Neueinrichtung über `security.php`/Admin-Reset (`twofa_reset()`), kein turnusmäßiges Rotationsverfahren |
| `GitHub-Secrets` `VPS_SSH_PRIVATE_KEY`, `VPS_SSH_KNOWN_HOSTS`, `VPS_HOST`, `VPS_SSH_USER`, `VPS_SSH_PORT` | SSH-Zugriff des Deployment-Workflows auf den VPS (`.github/workflows/deploy.yml:507-539`) | GitHub-Repository-Secrets | GitHub-Actions-Workflow `deploy-vps` | in den Repository-/Organisationseinstellungen von GitHub hinterlegt | nicht im Repository dokumentiert (GitHub-seitige Verwaltung) |
| `GitHub-Secrets` `SFTP_HOST`, `SFTP_USER`, `SFTP_PORT`, `SFTP_PATH`, `SFTP_PASSWORD` | Upload des IONOS-Webhosting-Release per SFTP (`.github/workflows/deploy.yml:290-294`) | GitHub-Repository-Secrets | GitHub-Actions-Workflow (IONOS-Job) | wie oben | nicht dokumentiert |
| `GitHub-Secret` `MIGRATION_TOKEN` | siehe `migration_token` oben | GitHub-Repository-Secrets | GitHub-Actions-Workflow | wie oben | siehe `migration_token` |
| Redis-Passwort | laut `config.example.php:241` bleibt `password => null`: das interne Redis auf dem VPS verlangt kein Passwort, ist ausschließlich im internen Docker-Netz erreichbar (`docs/vps/06-betrieb.md`, Abschnitt „Redis' eigener protected mode") | `deploy/vps/redis/redis.conf` (kein Passwort gesetzt) | Betreiber (Server-Zugriff) | Teil des Deployments | entfällt (kein Passwort) |

Keine der oben genannten Konfigurationsdateien wird eingecheckt: `app/config.php` ist über
`.htaccess`/`.gitignore` geschützt (`config.example.php:6-7`), `shared/config.php` liegt auf dem VPS
außerhalb der Releases (`app/bootstrap.php:16-18`).

## Offene Prüfpunkte

1. **Frage:** Soll für App- und Adminhost ebenfalls eine Content-Security-Policy eingeführt werden
   (aktuell nur der statische Status-Host hat eine), und wie wäre sie mit den bestehenden
   Inline-Skripten/-Styles der Anwendung vereinbar? **Quelle:** `deploy/vps/Caddyfile:27-40, 136`,
   `php-ionos/.htaccess:43-52` (kein CSP-Header für App/Admin gefunden). **Prüfverfahren:**
   Bestandsaufnahme der tatsächlich verwendeten Inline-Skripte/-Styles in `assets/js/`, `assets/css/`
   und den PHP-Templates, danach Entwurf einer CSP mit Nonce oder Hash, Testlauf im Report-Only-Modus
   vor der Aktivierung; Freigabe durch die Geschäftsführung laut Eskalationsregeln.
2. **Frage:** Existiert ein Rotationsverfahren für `app_secret`, falls ein Verdacht auf Kompromittierung
   besteht, ohne dass bestehende verschlüsselte Werte (2FA-Geheimnisse, API-Schlüssel der Firmen)
   verloren gehen? **Quelle:** `app/config.example.php:43-47` (ausdrückliche Warnung, kein Verfahren
   beschrieben). **Prüfverfahren:** Konzept für eine Zweischlüssel-Übergangsphase (alten und neuen
   Schlüssel parallel entschlüsseln, schrittweise mit dem neuen Schlüssel neu verschlüsseln) vor der
   Umsetzung mit einem Sicherheitsverantwortlichen abstimmen; keinesfalls ohne ein solches Verfahren
   den Wert direkt ändern.
3. **Frage:** Wird die Sicherheits-E-Mail an den Inhaber bei einem gestarteten Support-Zugriff
   (`app/support.php:9`, Kommentarkopf) tatsächlich versendet, und über welche konkrete Funktion?
   **Quelle:** kein direkter `mail_send()`-Aufruf in `app/support.php` gefunden; vermutlich
   `security_notify_owner()` (`app/auth.php:816-826`) aus `admin-support.php` heraus. **Prüfverfahren:**
   Testlauf einer Support-Sitzung gegen eine Firma mit aktivem Mailversand (`mail.enabled = true`,
   Transport `log`) und Prüfung von `mail.log` auf eine entsprechende Nachricht.
4. **Frage:** Ist HSTS (`Strict-Transport-Security`) inzwischen für App-, Admin- und API-Host auf dem
   VPS freigegeben und aktiviert worden? **Quelle:** `deploy/vps/Caddyfile:33-36` (auskommentiert,
   Freigabe durch die Geschäftsführung vorausgesetzt). **Prüfverfahren:** Rücksprache mit der
   Geschäftsführung, danach Prüfung, dass alle drei Hosts dauerhaft ausschließlich über gültiges
   HTTPS erreichbar sind, bevor der Header einkommentiert wird (Browser-Cache macht eine Rücknahme
   sonst langwierig).
