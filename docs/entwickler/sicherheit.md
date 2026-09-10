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
- **`require_platform($recht)`** (`app/auth.php`, seit 4.37) verlangt Plattformzugang (`platform_access()`: Superadmin-Kennzeichen
  oder Plattformrolle, jeweils nur mit aktiver 2FA) und die genannte Berechtigung (`platform_can()`, `app/platform.php`).
  `require_superadmin()` ist nur noch der Altname für `require_platform('admin.view')`. Ein Konto ohne eingerichtete 2FA hat
  keinen Zugriff auf den Adminbereich, unabhängig von seiner Rolle.

### Geltungsbereich der Zweitbestätigung (Beschluss des Vorstands vom 07.09.2026, umgesetzt in 4.36)

Zweitbestätigung nur für Wichtiges: Anmeldung, Wechsel in Kundenaccounts, Wartung **aktivieren**, Not-Stopp **aufheben**,
Geldfluss sowie Kontosicherheit. Alles andere läuft mit Anmeldung, Berechtigungsprüfung, CSRF-Schutz und Audit, aber ohne
Codeeingabe. Die Regel ist statisch abgesichert (`php tools/totp-policy-check.php`, 73 Prüfungen: Zweige, Formularfelder,
Geldfluss-Jobtypen) und wird bei jeder neuen Aktion dort ergänzt.

| Kategorie | Aktion (Datei) | Zweitbestätigung |
|---|---|---|
| Anmeldung | `login.php` (eigener Mechanismus, `auth_login_2fa()`) | ja |
| Kundenaccount | `support_start` (`admin-support.php`) | ja, zusätzlich Begründung |
| Wartung aktivieren | `org_sync_pause` (`admin-system.php`); `incident_publish` (Störungs- oder Wartungsmeldung veröffentlichen) | ja; Fortsetzen (`org_sync_resume`) und Zurückziehen (`incident_unpublish`) nein |
| Not-Stopp aufheben | `resume` (`notstopp.php`); `platform_pause` mit `pause=0` (`admin.php`) | ja; Aktivieren beider Not-Stopps bewusst ohne Hürde |
| Geldfluss | `process_due_now` (`collections.php`), `apply` (`stripe-import.php`), Jobaktionen `job_retry_now`/`job_cancel`/`job_close`/`job_release` für `collections_due` und `unclear_attempts` (`QUEUE_MONEY_TYPES`, `queue_type_is_money()`) | ja; dieselben Jobaktionen für `sync_run`, `mail`, `alerts`, `mandate_reminders`, `monitor_collect`, `maintenance` nein |
| Kontosicherheit | `change_password` (`security.php`), `transfer_ownership` (`team.php`, zusätzlich Passwort), `switch_invoice_source` (`settings.php`, Projektregel) | ja |
| Rechtsdokumente | `publish`/`retire` (`admin-legal.php`, Außenwirkung für alle Firmen) | ja; `import_draft`, `create`, `delete` (nur unveröffentlichte Fassungen) nein |
| Support-Modus, zentral gesperrt (seit 4.55) | Der Plattformbetreiber arbeitet im Namen der Firma. Gesperrt sind: Einzüge, IBAN setzen und deaktivieren, SEPA-Freigabe je Kunde, Mandate erzeugen und widerrufen, Aufheben des Not-Stopps (Aktivieren bleibt möglich), Firmendaten mit Geldbezug (Gläubiger-Identifikationsnummer, Pflicht zum unterschriebenen Mandat, Vorabankündigung und Frist), Wechsel des Buchhaltungssystems, Zustimmung zu AVV und Verschwiegenheitsvereinbarung. Die Prüfung liegt in den Funktionen selbst (`app/customer_settings.php`, `app/collections.php`, `app/legal.php`), nicht nur in den Seiten | nein (Sperre, kein Code; `php tools/payment-safety-check.php` Abschnitt H) |
| Sitzungscookie (seit 4.55) | `Secure` wird gesetzt, sobald die Anwendung unter https angesprochen wird (`app_base_url`, `admin_base_url`) oder der Proxy https meldet, nicht mehr allein anhand von `$_SERVER['HTTPS']`. Hinter dem Coolify-Proxy mit leerem `trusted_proxies` lief das Cookie sonst ohne Secure | nein |
| Platzhalter der Beispielkonfiguration (seit 4.55) | `config_is_placeholder()` erkennt die 32 Zeichen langen Platzhalter aus `app/config.example.php`. Verschlüsselung, Cron-Endpunkt und Migrationsendpunkt weisen sie ab; die reinen Längenprüfungen ließen sie bis 4.54 durch | nein |
| Hosttrennung (seit 4.50 geprüft) | Auf dem eigenen Adminhost sind nur Adminseiten, Anmeldung, 2FA, Passwort und Sicherheit erreichbar (`enforce_host_rules()`); Kundenbalken und ihre Links werden über `admin_host_separated()` ausgeblendet und zeigen sonst absolut auf `app_base_url()` | nein (reine Anzeige- und Routingregel, `php tools/host-separation-check.php`) |
| Entfallen seit 4.36 | `plan_update`, `org_plan`, `interest_unsubscribe`/`interest_delete`/`interest_block`/`interest_invite` (`admin.php`); `publish_now`, `test_mail`, `test_prenotification` (Muster nur an die eigene Adresse, seit 4.44), `sync_enqueue`, `org_queue_flag_on`/`off` (`admin-system.php`) | nein (Audit unverändert; endgültiges Löschen einer Vormerkung mit Bestätigungsdialog) |

Bewusst offen gelassen (Empfehlung „behalten“, vom Vorstand nicht ausdrücklich genannt): `change_password` und
`transfer_ownership` als Kontosicherheit, `switch_invoice_source` wegen der Projektregel in `CLAUDE.md`. Wer eine dieser
Stellen ändert, passt zuerst `tools/totp-policy-check.php` an.

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
| Plattformrolle (`users.platform_role`, Tabelle `platform_roles`; `users.is_superadmin = 1` als Vollzugriff) | Zugriff auf den Adminbereich nach Berechtigungskatalog, unabhängig von einer Firmenmitgliedschaft; verlangt aktive 2FA (`require_platform()`, `platform_can()`, `app/platform.php`) |

Rollen sind je Firma (`organization_members.role`) vergeben; die Plattformrolle ist ein globales Attribut des Nutzerkontos
und unabhängig davon. Ein Inhaber einer Kundenfirma kann zugleich Plattformrolle tragen (dann erscheint „Admin“ im Menü).

### Plattform-Benutzer und Rechte (seit 4.37, Migration 027)

Mitarbeiter und Administratoren des Betreibers erhalten den Adminbereich über eine **Plattformrolle** statt über das
Superadmin-Kennzeichen. Verwaltung in `admin-users.php` (Berechtigung `users.manage`), Logik in `app/platform.php`:

- **Systemrollen:** `admin` (alle Rechte, unveränderlich, entspricht dem bisherigen Superadmin), `support`
  (Support-Anfragen, Firmenzugriff, Konten entsperren, Systemübersicht lesend), `staff` (lesend: Firmen, Vormerkungen,
  Systemübersicht). Systemrollen sind nicht löschbar; `support` und `staff` sind anpassbar, dürfen aber nie `docs.*` oder
  `users.manage` erhalten (seit 4.41 im Code erzwungen; Dokumentationsregel: nie Mitarbeiter- oder Supportrollen). Eigene Rollen bestehen aus
  einer Auswahl des Katalogs; `admin.view` ist immer enthalten.
- **Berechtigungskatalog** (`PLATFORM_PERMISSIONS`): `admin.view`, `companies.view`, `companies.plan`, `companies.manage` (Wechselsperre des Buchhaltungssystems aufheben, seit 4.38), `plans.manage`,
  `notstopp.platform`, `interest.view`, `interest.manage`, `support.view`, `support.tickets`, `support.sessions`,
  `support.users`, `monitoring.view`, `monitoring.edit`, `legal.view`, `legal.manage`, `docs.admin`, `docs.technical`,
  `users.manage`. Jede Adminseite verlangt ein Eintrittsrecht (`require_platform`) und prüft je POST-Aktion serverseitig
  das passende Recht; Menüpunkte und Formulare werden zusätzlich ausgeblendet, sind aber nie der Schutz.
- **Zuordnung:** `admin.php` (`admin.view`; Kennzahlen/Firmen `companies.view`, Tarif je Firma `companies.plan`, Wechselsperre aufheben `companies.manage`,
  Tarife `plans.manage`, Not-Stopp `notstopp.platform`, Vormerkungen `interest.view`/`interest.manage`),
  `admin-support.php` (`support.view`; Firmenwechsel `support.sessions`, Anfragen `support.tickets`, Entsperren und
  2FA-Reset `support.users`), `admin-system.php` und `admin-system-data.php` (`monitoring.view`; Änderungen
  `monitoring.edit`, zusätzlich `monitoring.editors`), `admin-legal.php` (`legal.view`/`legal.manage`),
  `admin-doc.php` (`admin.view`, je Datei `docs_can_access()`: `docs.admin`, `docs.technical`), `admin-users.php`
  (`users.manage`). Der Support-Modus (`support_session_redeem()`, `_current_user_support()`) verlangt `support.sessions`
  beim Einlösen und bei jeder Anfrage; entzogene Rechte beenden die Sitzung.
- **Plattformkontext ohne Firma:** Plattform-Benutzer brauchen keine Firmenmitgliedschaft. `session_finish_login()`
  meldet sie mit `org_id = NULL` an, `current_user()` liefert über `_current_user_platform()` einen Kontext mit Rolle
  `platform` und `platform_only = true`; `require_login()` leitet sie von Kundenseiten auf `platform_home_url()`
  (Adminhost) um, erlaubt sind Adminseiten, `security.php`, `twofa-setup.php`, `verify-email.php`, `support-*.php`,
  `handbuch.php`. Nach Ende einer Support-Sitzung kehren sie in den Plattformkontext zurück (`support-end.php`).
- **Einladung:** `platform_user_invite()` legt das Konto mit Zufallspasswort und bestätigter E-Mail-Adresse (der Link beweist sie) an, setzt die Rolle und sendet einen Link
  zum Festlegen des Passworts (`password_reset_token_hash`, `PLATFORM_INVITE_DAYS = 3`); danach ist die 2FA-Einrichtung
  Pflicht. Ohne aktiven Mailversand wird die Einladung verweigert, ein Passwortlink erscheint nie im Adminbereich.
  Bestehende Konten (etwa Inhaber einer Firma) erhalten nur die Rolle und eine Hinweismail; die Rollenvergabe läuft dabei
  über `platform_user_set_role()` mit allen Schutzregeln (seit 4.41: keine Selbst-Eskalation per Einladung, kein Herabstufen
  des letzten Administrators, `is_superadmin` fällt bei einer anderen Rolle).
- **Schutzregeln:** die eigene Rolle ist nicht änderbar, das eigene Konto nicht deaktivierbar; der letzte aktive
  Administrator (`platform_admin_count()`) kann weder herabgestuft noch entfernt noch deaktiviert werden; wer den
  Vollzugriff verliert (Rolle unter `admin` oder Entzug), verliert sofort alle Sitzungen (`user_revoke_sessions()`),
  `is_superadmin` wird dabei auf 0 gesetzt, damit die Spalte die Rolle nicht unterläuft. Deaktivieren gilt für das
  gesamte Konto, auch für Firmenmitgliedschaften (Warnhinweis im Formular).
- **Nachweis:** `audit_log`-Aktionen `platform_user_invited`, `platform_user_reinvited`, `platform_user_role_changed`,
  `platform_user_access_removed`, `platform_user_activated`, `platform_user_deactivated`, `platform_role_created`,
  `platform_role_changed`, `platform_role_deleted`. Prüfung: `bash tools/platform-roles-check.sh` (83 Prüfungen gegen
  eine temporäre MariaDB: Rechte je Rolle, Dokumentationsrechte, Rollenpflege, Schutzregeln, Einladung ohne Mail,
  Plattformkontext, Audit).

## Support-Modus

`app/support.php`: Ein Plattform-Benutzer mit Berechtigung `support.sessions` kann zeitlich begrenzt „auf eine Firma wechseln":

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

- **PHP-seitig:** `enforce_host_rules()` (`app/bootstrap.php`), siehe
  `docs/entwickler/schnittstellen.md`, Abschnitt a) für die vollständige Allowlist und
  Ausnahmeliste. Seit 4.33 gilt jede Seite `admin.php` und `admin-*.php` als Adminseite (`is_admin_script()`): nur auf dem
  Adminhost erreichbar, auf dem App-Host 404, im Wartungsmodus weiter erreichbar. Vorher war die Liste fest verdrahtet;
  `admin-legal.php` (4.30) fehlte darin und lieferte auf dem Adminhost 404 (Befund des Betreibers am 07.09.2026).
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

## Google-Tag der Anwendung (`app/tracking.php`, seit 4.57)

Vorgabe des Betreibers vom 10.09.2026: Die Google-Ads-Kennung soll auch in der Anwendung hinterlegt sein, damit eine Registrierung als Conversion messbar wird. Weil die Anwendung Kundendaten führt, gilt eine enge, im Code festgelegte Grenze.

**Wo das Tag wirkt.** Ausschließlich auf den Seiten in `TRACKING_PUBLIC_PAGES`, derzeit `register.php` und `vormerken.php`. Beide sind ohne Anmeldung erreichbar und tragen keine Kennung in der Adresse. `layout_header()` gibt das Skript nur aus, wenn die aufrufende Seite es ausdrücklich über `$opts['tracking']` freigibt.

**Wo es nie wirkt und warum.**

| Ausgeschlossen | Grund |
|---|---|
| alle Seiten nach der Anmeldung | Der Seitenpfad enthält Kennungen zu Kunden, Rechnungen, Mandaten und Einzügen. Diese Daten verarbeitet die Müller Holding AG im Auftrag ihrer Kunden. Eine Übermittlung an Google wäre ein neuer Unterauftragsverarbeiter und damit ein Verstoß gegen den Auftragsverarbeitungsvertrag samt Anlage 3. |
| `reset-password.php`, `invite.php`, `twofa-verify.php`, `support-login.php` | Diese Adressen tragen ein Token. Ein Token, das über den Seitenpfad oder den Verweis an Google gelangt, ist ein Sicherheitsvorfall. |
| `login.php` | Anmeldeseite, für Werbemessung nicht nötig. |
| Adminbereich und Schnittstellen | keine Werbemessung, kein Nutzen. |

**Einwilligung.** `assets/js/consent.js` blendet ein Banner ein und lädt das Google-Skript erst nach „Alle akzeptieren“. Vorher wird kein Google-Skript geladen und kein Cookie gesetzt. Die Entscheidung liegt 12 Monate im `localStorage` der Herkunft `app.smart-einzug.de` und ist über „Cookie-Einstellungen“ in der Fußzeile widerrufbar. Sie gilt getrennt von der Einwilligung auf smart-einzug.de, weil `localStorage` an die Herkunft gebunden ist; ein Besucher wird deshalb beim Wechsel auf die Anwendung erneut gefragt. `ad_personalization` bleibt in jedem Fall auf `denied`.

**Konfiguration.** `analytics.enabled`, `analytics.ga_id`, `analytics.ads_id` in `shared/config.php`. Ohne `enabled` passiert nichts. Die Kennungen werden gegen die von Google vergebenen Formate geprüft (`G-…`, `AW-…`), damit eine falsch gepflegte Konfiguration keine fremde Kennung einschleust.

**Eine weitere Seite aufzunehmen ist keine Kleinigkeit.** `TRACKING_PUBLIC_PAGES` zu erweitern heißt, für die neue Seite zu prüfen, ob ihre Adresse personenbezogene oder mandantenbezogene Kennungen tragen kann, und die Auftragsverarbeitung neu zu bewerten. `php tools/app-tracking-check.php` (31 Fälle) hält die Grenze fest und läuft im GitHub-Workflow mit.

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

## Gesamtaudit vom 10.09.2026 (Version 4.59)

Vollständige Befundliste: `docs/audit/AUDIT_REPORT.md`. Umgesetzt in der Anmelde- und Rechteverwaltung:

- Plattformrollen (C-01): Die Rolle Administrator sowie jede Rolle mit `users.manage`, `*` oder `docs.*` gilt als privilegiert
  (`platform_role_is_privileged()`). Vergabe (`platform_user_invite`, `platform_user_set_role`) und Anlegen oder Ändern solcher
  Rollen (`platform_role_save`) nur durch Administratoren (`platform_actor_is_admin()`); die eigene Rolle ist nie bearbeitbar.
  Nachweis: `tools/platform-roles-check.sh`, Fälle C-01.
- Gerätecookie (C-03): `device_cookie_secure()` nutzt `request_is_https()` aus `app/bootstrap.php`, dieselbe Ableitung wie das
  Sitzungscookie (HTTPS, X-Forwarded-Proto, https-Basisadresse).
- Gerätefreigabe (C-05): `current_user()` prüft `device_session_valid()` vor dem Plattformkontext; widerrufene Freigaben wirken
  auch für Plattform-Benutzer ohne Firma.
- TOTP-Wiederholungsschutz (C-06): `twofa_verify_user()` schreibt den Zeitschritt mit Bedingung (`totp_last_step < ?`) und
  akzeptiert nur bei genau einer geänderten Zeile. Nachweis: `tools/auth-check.sh` (sechs parallele Prozesse).
- IP-Grenzen hinter nicht konfiguriertem Proxy (C-02): `client_ip_is_unresolved_proxy()` (private oder Loopback-Adresse mit
  X-Forwarded-For ohne `trusted_proxies`) setzt die IP-Sperre der Anmeldung aus; die Sperre je E-Mail-Adresse bleibt.
  Betriebsauflage: `trusted_proxies` in `shared/config.php` belegen (RELEASE_CHECKLIST.md).
- Datenminimierung (C-09): `login_attempts_cleanup()` löscht Anmeldeversuche nach 30 Tagen (Wartung und Cron).
- Bestätigungsmail (C-10): `verify-email.php` sendet je Klick genau einmal.
- Nicht umgesetzt, dokumentiert (P3): Support-Einlösetoken per GET (C-04), serverseitige Sitzungsablaufprüfung (C-08),
  Abmeldung per GET (C-11), Cron-Token in der URL (C-12).
