# Schnittstellen, APIs und Webhooks

Stand: 07.09.2026. Diese Übersicht beschreibt die Schnittstellen der Anwendung SmartEinzug
(`php-ionos/`) so, wie sie im Quellcode dieses Repositorys tatsächlich umgesetzt sind. Jede Aussage
ist mit Datei:Funktion oder Datei:Zeile belegt. Nicht im Code auffindbare Angaben (z. B. verbindliche
Rate-Limits von Lexware Office) sind als „nicht gefunden" bzw. „laut Code-Kommentar Annahme, nicht
verifiziert" gekennzeichnet. Alle Beispielwerte (Tokens, Schlüssel, IDs) sind synthetisch.

## a) Eingehende HTTP-Endpunkte

Grundmechanismen der Authentifizierung (`php-ionos/app/auth.php`):

- `require_login()` (`app/auth.php:198`): erzwingt eine Sitzung, danach (sofern Mailversand aktiv)
  bestätigte E-Mail-Adresse und eingerichtete 2FA.
- `require_onboarded()` (`app/auth.php:221`), `require_subscription()` (`app/auth.php:235`),
  `require_role($roles)` (`app/auth.php:257`), `require_owner()` (`app/auth.php:267`),
  `require_platform('<recht>')` (`app/auth.php`, verlangt Plattformzugang mit aktiver 2FA und die Berechtigung aus `app/platform.php`; `require_superadmin()` ist der Altname für `admin.view`).
- Rollenprüfungen ohne Redirect: `can_manage_settings()` (`app/auth.php:309`, Inhaber und
  Administrator dürfen API-Verbindungen/Firmendaten ändern), `can_manage_members()`
  (`app/auth.php:303`, nur Inhaber), `require_owner_action()` (`team.php:23`).
- Host-Bindung: `enforce_host_rules()` (`app/bootstrap.php:214`) liest `config('allowed_hosts')`
  (Allowlist, `app/bootstrap.php:185` `host_allowed()`) und `config('admin_base_url')`. Ausgenommen von
  der Allowlist-Prüfung sind `cron.php`, `stripe-webhook.php`, `billing-webhook.php`, `track.php`,
  `migrate.php` (`app/bootstrap.php:220`, eigene Signatur/Token-Prüfung). Ist `admin_base_url` gesetzt,
  sind auf dem Adminhost ausschließlich `admin.php`, `admin-support.php`, `admin-system.php`,
  `admin-system-data.php`, `admin-doc.php`, `login.php`, `twofa-verify.php`, `twofa-setup.php`,
  `logout.php`, `security.php`, `forgot-password.php`, `reset-password.php` erreichbar
  (`app/bootstrap.php:235`); jeder andere Host liefert für die Admin-Skripte 404
  (`app/bootstrap.php:247`). Auf Ebene des Webservers spiegelt `deploy/vps/Caddyfile` das: Der
  API-Host `{$DOMAIN_API}` liefert ausschließlich `/stripe-webhook.php`, `/billing-webhook.php`,
  `/health.php`, `/track.php` aus, alles andere 404 (`deploy/vps/Caddyfile`, Block „API-Host"); App- und
  Adminhost teilen sich denselben Dokumentenstamm, die eigentliche Trennung erzwingt PHP
  (`enforce_host_rules()`).

Spalte „Host": **App** = Kundenanwendung (`app_base_url`), **Admin** = nur auf `admin_base_url`
erreichbar (zusätzlich auf App-Host solange kein separater Adminhost konfiguriert ist), **API** = laut
Caddyfile nur auf `{$DOMAIN_API}`, **alle** = ohne Allowlist-Bindung bzw. selbstschützend.

| Datei | Zweck | Methode(n) | Authentifizierung | Rolle/Berechtigung | Wichtige Parameter | Antwort | Host |
|---|---|---|---|---|---|---|---|
| `index.php` | Startseite/Weiterleitung zu Login bzw. Dashboard | GET | keine (leitet ggf. weiter) | - | - | HTML/Redirect | App, Admin |
| `login.php` | Anmeldung Stufe 1 (Passwort) | GET, POST | keine | - | POST `email`, `password` | HTML, bei Erfolg Redirect zu 2FA oder Dashboard | App, Admin |
| `twofa-verify.php` | Anmeldung Stufe 2 (TOTP/Recovery-Code) | GET, POST | Zwischenzustand `$_SESSION['pending_2fa']` (`app/auth.php:462`) | - | POST `action`, `code`, `remember_device` | HTML | App, Admin |
| `twofa-setup.php` | Pflichteinrichtung 2FA (QR-Code, Recovery-Codes) | GET, POST | `require_login()` | jede Rolle | POST `action`, `code`, `confirm`, `remember_device` | HTML | App |
| `logout.php` | Abmelden, optional Gerät vergessen | GET, POST | Session (kein `require_login`, `auth_logout()` toleriert fehlende Session) | jede Rolle | POST `action=forget` (CSRF) | Redirect | App, Admin |
| `forgot-password.php` | Passwort-Reset anfordern | GET, POST | keine (Rate-Limit `app/auth.php:892`) | - | POST `email` | HTML, neutrale Antwort unabhängig vom Kontostatus | App |
| `reset-password.php` | Passwort-Reset einlösen | GET, POST | Token im Link (`token_hash`, 1 h gültig) | - | GET/POST `token`, POST `password`, `password2` | HTML | App |
| `verify-email.php` | E-Mail-Adresse bestätigen | GET, POST | `require_login()` für den POST-Zweig (erneut anfordern); GET-Einlösung nur mit Token | jede Rolle | GET `token` | HTML | App |
| `register.php` | Registrierung neuer Firma (ggf. weiterer Firma für angemeldeten Nutzer) | GET, POST | keine bzw. bestehende Session für Zusatzfirma | - | POST `email`, `password`, `password2`, `org_name`, `mandate_prefix`, `first_name`, `last_name`, `accept_terms`, `accept_avv` | HTML | App |
| `register-fortsetzen.php` | Registrierung mit bekannter E-Mail fortsetzen (Auftrag II, 4.2) | GET, POST | `require_login()` | jede Rolle (an vorgemerkten Nutzer gebunden) | POST `action` | HTML | App |
| `invite.php` | Einladung annehmen | GET, POST | Token (SHA-256-Hash gespeichert, `app/auth.php` Doku-Kopf) | - | GET/POST `token`, POST `code`, `first_name`, `last_name`, `password`, `password2` | HTML | App |
| `onboarding.php` | Einrichtungsassistent nach Registrierung | GET, POST | `require_login()` (`onboarding.php:6`) | jede Rolle | POST `action` | HTML | App |
| `dashboard.php` | Übersicht der Firma | GET | `require_login()` (`dashboard.php:9`) | jede Rolle | - | HTML | App |
| `companies.php` | Zwischen eigenen Firmen wechseln, neue anlegen | GET, POST | `require_login()` (`companies.php:12`) | jede Rolle (Anlage: angemeldeter Nutzer wird Inhaber der neuen Firma) | POST `action`, `org_id`, `org_name`, `mandate_prefix` | HTML | App |
| `team.php` | Firmendaten, Team, Einladungen, Inhaberschaft, Abo-Übersicht, Protokoll | GET, POST | `require_login()` (`team.php:17`) | Sichtbar für alle; Mitglieder verwalten nur Inhaber (`require_owner_action`, `team.php:23`); Firmendaten `can_manage_settings()` | POST `action`, `email`, `role`, `member_id`, `invitation_id`, Firmendaten (`street`, `zip`, `city`, `country`, `creditor_identifier`, `pre_notification_days`, `send_pre_notification`, `require_signed_mandate`, `multiaccount_enabled`), Profil (`display_name`, `first_name`, `last_name`, `phone_business`, `phone_private`, `remove_avatar`) | HTML | App |
| `settings.php` | API-Verbindungen (Lexware Office, Stripe) | GET, POST | `require_login()` (`settings.php:15`) | Ändern: `can_manage_settings()`; Mitarbeiter nur Ansicht | POST `action`, `target`, `lexoffice_api_key`, `stripe_secret_key`, `stripe_webhook_secret`, `confirm`, `code`, `reason` | HTML | App |
| `customers.php` | Kundenliste, Filter, Suche | GET | `require_login()` | jede Rolle | GET `q`, `only` | HTML | App |
| `customer.php` | Kundendetail (Stammdaten, SEPA, Mandate, Rechnungen) | GET, POST | `require_login()` | jede Rolle | GET `id`; POST `action`, `customer_id`, `iban`, `bic`, `account_holder_name`, `sepa_debit_enabled`, `mandate_id`, `signed_date`, `signed_place`, `iban_id`, `file_id`, `request_id`, `reason` | HTML | App |
| `sepa-pflegen.php` | Schlanke Arbeitsseite: SEPA-Entscheidung je Kunde ohne IBAN | GET, POST | `require_login()` | jede Rolle | GET `customer`; POST `action`, `customer_id`, `iban`, `bic`, `account_holder_name` | HTML | App |
| `invoices.php` | Offene Rechnungen, Einzug auslösen/terminieren | GET, POST | `require_subscription()` statt `require_onboarded()` (Kommentar `invoices.php:172`) | jede Rolle | GET `status`, `sepa`, `syncing`; POST `action`, `invoice_id`, `customer_id`, `scheduled_date`, `confirm_amount_cents`, `back_status`, `back_sepa` | HTML | App |
| `collections.php` | Einzugsliste, Stornieren/Umterminieren | GET, POST | `require_login()` | jede Rolle | GET `status`; POST `action`, `collection_id`, `new_date`, `code` | HTML | App |
| `notstopp.php` | Not-Stopp: alle Einzüge der Firma anhalten/freigeben | GET, POST | `require_login()` (`notstopp.php:14`) | `can_manage_settings()` (`notstopp.php:15`) | POST `action`, `confirm`, `reason`, `code`, `cancel_pending` | HTML | App |
| `reconcile.php` | Schnellabgleich offener Rechnungsnummern gegen Lexware Office | GET, POST | `require_login()` (`reconcile.php:14`) | jede Rolle | POST (kein GET-Parameter außer Formular) | HTML | App |
| `sync-status.php` | Live-Zustand der laufenden Synchronisation | GET | `require_login()` (`sync-status.php:12`) | jede Rolle, mandantengefiltert | GET `format` (HTML-Fragment oder JSON) | HTML-Fragment/JSON | App |
| `synchronisationen.php` | Sync-Historie der Firma | GET | `require_login()` | jede Rolle | GET `id` | HTML | App |
| `export.php` | Journal-Export der Einzüge als CSV | GET | `require_login()` (`export.php:13`); Audit-Export nur Inhaber (Kommentar `export.php:126`) | jede Rolle (Audit-Export: Inhaber) | GET `status`, `typ` | CSV (UTF-8 mit BOM, Formelschutz) | App |
| `mandat.php` | Öffentliche Seite zur digitalen Mandatserteilung (Linkziel aus E-Mail) | GET, POST | keine Anmeldung, CSRF-Token; `no-referrer`, kein Suchmaschinenindex (Doku-Kopf) | öffentlich (an Token `t` gebunden) | GET `t`, `done`; POST `t`, `action`, `accept` | HTML, POST startet Stripe Checkout (Redirect) | App |
| `mandate-file.php` | Ausgabe eines hochgeladenen Mandatsdokuments | GET | `require_login()` (`mandate-file.php:11`), Mandantenprüfung | jede Rolle der Firma | GET `id`, `download` | Datei (inline oder Download) | App |
| `mandate-print.php` | SEPA-Mandat als Druckansicht | GET | `require_login()` (kein expliziter `require_*`-Aufruf im Kopf gefunden, prüft Mandat gegen Firma) | jede Rolle | GET `mandate` | HTML | App |
| `subscription.php` | Abonnement der Firma (Tarif, Checkout, Billing Portal, Kündigung) | GET, POST | `require_login()`; Vertragsabschluss nur Inhaber (Doku-Kopf) | Inhaber für Änderungen | GET `checkout`, `bestellen`; POST `action`, `plan_code`, `code`, `password` | HTML/Redirect zu Stripe | App |
| `stripe-import.php` | Einmal-Import bestehender Einzüge aus Stripe | GET, POST | `require_login()` (`stripe-import.php:12`) | `can_manage_settings()` (`stripe-import.php:13`) | POST `action`, `import_id`, `months`, `code` | HTML | App |
| `hilfe.php` | Hilfe-Center, FAQ-Suche, Support-Tickets | GET, POST | `require_login()` (`hilfe.php:11`) | jede Rolle | GET `q`, `thema`, `ticket`, `von`; POST `action`, `category`, `subject`, `body`, `ticket_id`, `page` | HTML | App |
| `rechtliches.php` | Vertragsdokumente (AVV, Verschwiegenheit) mit Zustimmung | GET, POST | `require_login()` (`rechtliches.php:14`) | Zustimmung/Angabe ändern: Inhaber/Administrator (Doku-Kopf); Ansicht alle | GET `dok`; POST `action`, `document_id`, `confirm`, `professional_secrecy`, `professional_secrecy_kind` | HTML | App |
| `security.php` | Eigene Kontosicherheit (Passwort, Recovery-Codes, 2FA-Reset, Geräte) | GET, POST | `require_login()` (`security.php:11`) | jede Rolle (eigenes Konto) | POST `action`, `current_password`, `new_password`, `new_password2`, `password`, `code`, `device_id` | HTML | App |
| `profile.php`-Funktionen werden über `team.php` bedient | siehe `team.php` | - | - | - | - | - | - |
| `avatar.php` | Profilbild ausliefern | GET | `require_login()`, nur eigenes Bild oder Bild eines Mitglieds derselben Firma (Doku-Kopf) | jede Rolle | GET `u` | Bilddatei | App |
| `vormerken.php` | Vorregistrierung/Warteliste für angekündigte Integrationen (sevdesk) | GET, POST | keine, Double-Opt-in per Button (kein GET-Auslösen) | öffentlich | GET `token`, `abmelden`; POST `provider`, `email`, `name`, `company`, `consent`, `src`, `website` (Honeypot), `token`, `aktion`, `abmelden` | HTML | öffentliche Marketing-Domains (kein `require_login`) |
| `impressum.php` | Impressum | GET | keine | öffentlich | - | HTML | App, Admin |
| `hilfe.php`/`rechtliches.php` siehe oben | | | | | | | |
| `admin.php` | Superadmin: Kennzahlen, Firmen, Tarife, Support-Funktionen | GET, POST | `require_superadmin()` (`admin.php:29`) | Plattformadministrator (2FA aktiv) | GET `export`, `vq`, `vsource`, `vstatus`; POST `action`, `org_id`, `plan_code`, `pause`, `billing_exempt`, `interest_id`, `code` | HTML/CSV | Admin |
| `admin-legal.php` | Adminbereich: Rechtsdokumente verwalten/veröffentlichen | GET, POST | `require_platform('legal.view')` | Plattformrolle mit `legal.view`; Änderungen `legal.manage`; Veröffentlichen und Zurückziehen zusätzlich frischer 2FA-Code (Entwürfe ohne, seit 4.36) | GET `dok`; POST `action`, `doc_code`, `title`, `version`, `summary`, `body_md`, `required_for`, `draft`, `document_id`, `code` | HTML | Admin |
| `admin-support.php` | Support: Firmenwechsel, Sitzungen, Entsperren, 2FA-Reset, Tickets | GET, POST | `require_platform('support.view')` | Plattformrolle mit `support.view`; Firmenwechsel `support.sessions` plus Begründung und frischer 2FA-Code (`app/support.php`), Anfragen `support.tickets`, Entsperren/2FA-Reset `support.users` | GET `q`, `ticket`, `tstatus`; POST `action`, `org_id`, `reason`, `code`, `email`, `password`, `session_id`, `ticket_id`, `body` | HTML | Admin |
| `admin-system.php` | Betriebsübersicht (Monitoring, Warteschlange, Störungsmeldungen) | GET, POST | `require_platform('monitoring.view')` | Ansicht: Plattformrolle mit `monitoring.view`; Änderungen `monitoring.edit`; Ändern von Störungsmeldungen/Testversand/Jobs: zusätzlich `monitoring.editors`; frischer 2FA-Code nur beim Veröffentlichen, beim Pausieren einer Firma und bei geldbewegenden Jobtypen (seit 4.36, `docs/entwickler/sicherheit.md`) | GET `tab`, `d`, `w`; POST `action`, `code`, `org_id`, `job_id`, `kind`, `reason`, `components`, `title`, `public_message`, `public_text`, `internal_note(s)`, `phase`, `started_at`, `scheduled_end_at`, `incident_id` | HTML | Admin |
| `settings.php` (sevdesk, seit 4.38) | Einstellungen: sevdesk-Token speichern (Verbindungstest), prüfen, trennen; nur für Firmen mit `invoice_source = sevdesk` und freigegebener Anbindung | POST | `require_login()`, `can_manage_settings()`, `support_guard()` bei Speichern und Trennen | Inhaber und Administrator der Firma | POST `action` (save_sevdesk, verify_sevdesk, disconnect_sevdesk), `sevdesk_api_key` | HTML | App |
| `admin-users.php` | Adminbereich: Plattform-Benutzer einladen, Rollen vergeben, eigene Rollen mit Berechtigungen anlegen (seit 4.37) | GET, POST | `require_platform('users.manage')` | Plattformrolle mit `users.manage` (Systemrolle Administrator); Schutzregeln in `app/platform.php` (eigene Rolle, letzter Administrator) | GET `rolle`; POST `action` (invite, reinvite, set_role, remove_access, deactivate, activate, role_save, role_delete), `email`, `first_name`, `last_name`, `role`, `user_id`, `code`, `name`, `description`, `perm[]`, `is_new` | HTML | Admin |
| `admin-system-data.php` | Datenendpunkt: aktualisiertes Kopf-Fragment (Polling alle 30 s) | GET | dieselbe Autorisierung wie `admin-system.php` (Doku-Kopf, keine eigene neue Prüfung) | Plattformadministrator | - | HTML-Fragment | Admin |
| `admin-doc.php` | Auslieferung der technischen Dokumentation (Manifest-Allowlist) | GET | `require_superadmin()` (`admin-doc.php:22`) | Plattformadministrator | GET `f` (nur exakter Abgleich gegen `manifest.json`, zusätzlich `realpath()`-Prüfung) | Datei (PDF/HTML) | Admin |
| `support-login.php` | Einmal-Token einer Support-Sitzung einlösen | GET | Token (`app/support.php:50`, 5 Minuten gültig, einmalig) | keine feste Rolle (Ergebnis: Support-Sitzung als Administrator-Rolle) | GET `token` | Redirect ins Dashboard der Zielfirma | App |
| `support-end.php` | Support-Sitzung beenden | GET | Session (`support_session_id`) | Plattformadministrator im Support-Modus | - | Redirect | App |
| `stripe-webhook.php` | Stripe-Webhook der Firmen-Einzüge | POST | Signatur je Firma (`stripe_verify_webhook_signature()`, Webhook-Secret aus `integrations`) | keine Login-Rolle, Zuordnung über `metadata.tenant_id` bzw. Payment-Intent/Charge-Lookup | Rohkörper JSON, Header `Stripe-Signature` | immer HTTP 200, Text `ok` | API |
| `billing-webhook.php` | Stripe-Webhook der Plattform-Abrechnung | POST | Signatur mit `config('billing')['stripe_webhook_secret']` (`billing-webhook.php:20`) | keine | Rohkörper JSON, Header `Stripe-Signature` | HTTP 200 `ok` bzw. 400 `invalid` bei Signaturfehler | API |
| `health.php` | Minimaler Gesundheitscheck | GET | keine (`SKIP_SESSION`, `health.php:9`) | öffentlich | - | JSON `{status, php, db, time}`, HTTP 200/503 | API, App, Admin (kein Host-Ausschluss im Code, Caddyfile beschränkt API-Host zusätzlich) |
| `track.php` | Cookielose Funnel-Messung der Marketingseiten | POST, OPTIONS | keine, nur erlaubte Domain (`signup_domains`) + Ereignis-Allowlist, Ratenbegrenzung 300/Domain/Minute | öffentlich (CORS nur für konfigurierte Domains) | JSON-Body `{d, e, p, c}` | HTTP 204 (kein Inhalt) | API |
| `migrate.php` | Migrationsendpunkt für den Deployment-Workflow | POST | Header `X-Migration-Token` gegen `config('migration_token')`, `hash_equals()` (`migrate.php:63`) | keine (Deployment-Automat) | Header `X-Migration-Token`; kein Body ausgewertet | JSON, siehe Vertrag unten | selbstschützend (in `enforce_host_rules()`-Ausnahmeliste) |
| `cron.php` | Optionaler Cron-Einstieg (IONOS-Webhosting) | GET, CLI | `hash_equals()` gegen `config('cron_token')` (`cron.php:33`) | keine | GET `token` bzw. `argv[1]` (CLI) | Klartext-Protokollzeilen | selbstschützend |
| `setup-check.php` | Einmalige Setup-Prüfung nach Erst-Upload | GET | `hash_equals()` gegen `cron_token` (`setup-check.php:44`) | keine | GET `token` | HTML | App (vor Produktivbetrieb zu löschen, Doku-Kopf) |

Nicht als eigener HTTP-Endpunkt umgesetzt, aber erwähnenswert: `admin-legal.php`, `admin-support.php`,
`admin-system.php`, `admin-system-data.php`, `admin-doc.php` sind die einzigen fünf Skripte, die auf
einem separaten Adminhost zusätzlich zu Login/2FA/Passwort/Sicherheit/Abmelden erreichbar bleiben
(`app/bootstrap.php:235`).

## b) Ausgehende Aufrufe

### Lexware Office (`app/lexoffice.php`)

- Kanonische Basisadresse `https://api.lexware.io/v1` (`LexofficeClient::DEFAULT_BASE_URL`,
  `app/lexoffice.php:28`), frühere Domain `https://api.lexoffice.io/v1` als automatischer Ausweichpfad
  bei Verbindungsfehlern (`LEGACY_BASE_URL`, `app/lexoffice.php:29`, Wechsel in `request()`,
  `app/lexoffice.php:127-139`). Auswahl über `config('lexware_api_base_url')`
  (`app/config.example.php:164`).
- Authentifizierung: Header `Authorization: Bearer <apiKey>` (`app/lexoffice.php:105`), `Accept:
  application/json`.
- Endpunkte: `GET /profile` (Verbindungstest, `getProfile()`, `app/lexoffice.php:188`), `GET
  /voucherlist` (Rechnungsliste seitenweise, `getInvoiceVouchersPage()`, `app/lexoffice.php:219`,
  Parameter `voucherType=invoice`, `voucherStatus`, `size=100`, `page`), `GET /invoices/{id}`
  (`getInvoiceDetail()`, `app/lexoffice.php:229`), `GET /contacts/{id}` (`getContact()`,
  `app/lexoffice.php:234`), `GET /payments/{voucherId}` (`getPayment()`, `app/lexoffice.php:251`,
  liefert `open_amount`, `currency`, `payment_status`, `voucher_status`, `paid_date`; nicht numerische
  `openAmount` wird defensiv als `null` normalisiert, der Aufrufer darf dann nicht einziehen,
  Kommentar `app/lexoffice.php:242-250`).
- Drosselung: Mindestabstand 0,6 s zwischen zwei Aufrufen (`MIN_REQUEST_INTERVAL_US`,
  `app/lexoffice.php:30`, Kommentar „Annahme < 2 Requests/Sekunde, am 06.09.2026 nicht online
  verifiziert", `app/lexoffice.php:12-14`) zusätzlich zur zentralen Ratenbegrenzung über
  `api_call_gate('lexoffice', ...)` (`app/lexoffice.php:93`, Kontingent je API-Schlüssel/Firma
  `queue.lexoffice_per_second` Standard 2, globale Obergrenze `queue.lexoffice_global_per_second`
  Standard 50, `app/config.example.php:227-228`).
- Fehlerklassen/Retries: HTTP 429 bis zu 3 Wiederholungen mit `Retry-After` (gedeckelt 1 bis 30 s) oder
  exponentiellem Backoff plus Zufallsanteil (`backoff()`, `app/lexoffice.php:63-69`, `request()`
  Zeilen 157-167); HTTP 500/502/503 bis zu 2 Wiederholungen (Zeilen 169-179); HTTP 401 wirft sofort
  `LexofficeException` „API-Key ungültig oder abgelaufen" (`app/lexoffice.php:154`); Verbindungsfehler
  (DNS/TLS/Timeout) lösen `circuit_failure('lexoffice', ...)` aus und versuchen einmalig die jeweils
  andere Basisadresse (Zeilen 128-139).
- `api_call_gate()` (`app/queue.php:766`): prüft zuerst den Circuit Breaker (`circuit_allow()`,
  wirft `CircuitOpenException`, wenn offen, `app/queue.php:768`), danach eine Redis-gestützte
  Ratenbegrenzung (`redis_rate_wait_ms()`); ohne Redis wirkt nur der Circuit Breaker.
  `circuit_failure()` akzeptiert ausschließlich die Kategorien `timeout, dns, tls, connection,
  connection_refused, protocol, http_5xx, throttled, other` (`app/queue.php:732`); Standard-Schwelle 5
  Fehlversuche öffnet den Kreis für 300 s, danach ein Testaufruf (`half_open`,
  `circuit_config()`/`circuit_allow()`, `app/queue.php:659-708`).

### Stripe (`app/stripe.php`)

- Basisadresse `https://api.stripe.com/v1` (`StripeClient::BASE_URL`, `app/stripe.php:28`).
  API-Version standardmäßig `2024-06-20` (`StripeClient::API_VERSION`, `app/stripe.php:30`), Header
  `Stripe-Version` je Aufruf setzbar; für `mandate_options.reference_prefix` wird gezielt
  `2024-12-18.acacia` verwendet (`API_VERSION_MANDATE_PREFIX`, `app/stripe.php:32`,
  `createPaymentIntent()` Zeilen 309-329). **Prüferklärung:** Die App verwendet zwei API-Versionen
  bewusst nebeneinander, nicht aus Versehen, die neuere Version nur für den einen Aufruf, der das
  Präfix-Feld benötigt; lehnt Stripe das Präfix ab (`invalid_mandate_reference_prefix_format`), wird
  der Einzug ohne Präfix und ohne Versionswechsel wiederholt (Zeilen 319-325).
- Authentifizierung: HTTP Basic Auth mit dem Secret Key als Benutzername, leeres Passwort
  (`CURLOPT_USERPWD`, `app/stripe.php:66`). Zwei getrennte Schlüsselkreise: Firmenschlüssel (SEPA-
  Einzüge bei deren Kunden) und Plattformschlüssel der Müller Holding AG (`app/billing.php`,
  Kommentarkopf `app/stripe.php:5-9`).
- Idempotenz: Header `Idempotency-Key` (bereinigt auf `[A-Za-z0-9_-]`, max. 255 Zeichen,
  `app/stripe.php:57-62`) bei Einzügen (`createPaymentIntent()`); Stripe liefert bei erneuter Anfrage
  mit demselben Schlüssel innerhalb 24 h die gespeicherte Antwort statt einer zweiten Abbuchung
  (Kommentar Zeilen 58-60). Bei Präfix-Rückfall wird ein abgeleiteter Schlüssel mit Suffix `-p`
  verwendet (Zeile 318), damit derselbe fachliche Versuch nicht denselben Idempotenzschlüssel für zwei
  unterschiedliche Parametersätze nutzt.
- Endpunkte (Auszug): `GET /account` (Verbindungstest), `GET /payment_intents/{id}`, `GET
  /charges/{id}`, `GET /mandates/{id}`, `GET /payment_methods/{id}`, `GET /setup_intents/{id}`, `GET
  /checkout/sessions/{id}`, `GET /payment_intents` (seitenweise Listung für den Einmal-Import,
  `listPaymentIntents()`, Zeile 194), `GET /payment_intents/search` (Klärung unklarer Versuche,
  Zeile 208), `POST /checkout/sessions` (Modus `setup`, digitale Mandatserteilung,
  `createSetupCheckoutSession()`, Zeile 218), `POST /customers/search` + `POST /customers`
  (`findOrCreateCustomer()`, Zeile 239), `POST /payment_methods` (SEPA-Zahlungsmethode anlegen, Zeile
  261), `POST /payment_methods/{id}/attach` (Zeile 270), `POST /payment_intents` (SEPA-Lastschrift
  auslösen, `confirm=true`, `mandate_data.customer_acceptance.type=offline`, Zeilen 283-329).
- Fehlerklassen: HTTP ≥ 400 wirft `StripeException` mit `stripeCode` aus `error.code`
  (`app/stripe.php:110-114`); `outcomeUnknown = true`, wenn das Ergebnis unklar bleibt (Verbindungsfehler,
  kein JSON bei Status 0 oder ≥ 500, Zeilen 90-104), Aufrufer dürfen einen Einzug dann nicht blind
  wiederholen (siehe `docs/payment-safety.md`, nicht Teil dieses Dokuments). Monitoring: 5xx und 429
  gelten als technische Störung (`circuit_failure`), 4xx als fachliche Ablehnung ohne
  Circuit-Breaker-Zählung (`monitor()`, Zeilen 106-134).
- Drosselung: `api_call_gate('stripe', ...)` mit Kontingent je Stripe-Konto
  (`queue.stripe_per_second`, Standard 20) und globaler Obergrenze (`queue.stripe_global_per_second`,
  Standard 200, `app/stripe.php:51`, `app/config.example.php:229-231`).
- Webhook-Signaturprüfung (gemeinsame Funktion für beide Webhooks): `stripe_verify_webhook_signature()`
  (`app/stripe.php:352-383`), parst den Header `Stripe-Signature` (`t=...,v1=...`), verlangt einen
  Zeitstempel innerhalb von 300 s Toleranz und prüft `hash_hmac('sha256', "$t.$payload", $secret)` mit
  `hash_equals()` gegen jede `v1`-Signatur.

### sevdesk (`app/sevdesk.php`), Gerüst ohne Freigabe

- Klasse `SevdeskClient` (`app/sevdesk.php:22`): Basisadresse und Header-Präfix ausschließlich aus
  `config('sevdesk')` (`base_url`, `auth_prefix`), **nicht** fest verdrahtet; laut Kommentar „zu
  verifizieren mit dem Testkonto" (Zeile 34-36). Authentifizierung über Header `Authorization:
  <auth_prefix><apiKey>` (Zeile 60), kein Token als URL-Parameter, laut Kommentar entsprechend der
  offiziellen sevdesk-API-News von Februar 2025 (Zeilen 10-11).
- `get()` (Zeile 43) wirft `RuntimeException`, solange `integration_switch('sevdesk', 'connect')`
  nicht freigegeben ist (Zeile 45-47), die Anbindung ist serverseitig hart gesperrt, bevor überhaupt
  ein Netzaufruf versucht wird. Drosselung ebenfalls über `api_call_gate('sevdesk', ...)`
  (`queue.sevdesk_per_second` Standard 2, `queue.sevdesk_global_per_second` Standard 20, Zeile 53).
- `SevdeskSource` (Zeile 91) implementiert das `InvoiceSource`-Interface, liefert aber
  `capabilities() === []` (Zeile 106) und wirft für jede fachliche Methode
  (`getProfile`, `getOpenInvoices`, `getInvoiceVouchersPage`, `getInvoiceDetail`, `getContact`,
  `getPayment`) eine `RuntimeException` „... ist noch nicht gegen die offizielle Dokumentation
  verifiziert und daher gesperrt" (`nichtVerifiziert()`, Zeile 109-142). Endpunkte, Feldnamen und
  Statuscodes von sevdesk sind **nicht gefunden** (im Code bewusst nicht ausprogrammiert, siehe
  `docs/sevdesk.md` für den fachlichen Stand).

### Mail (SMTP) (`app/mailer.php`)

Kein REST-API-Client, sondern ein selbstgebauter minimaler SMTP-Client ohne Bibliotheken
(`mail_smtp_send()`, `app/mailer.php:283-358`): TCP- oder TLS-Verbindung (`stream_socket_client()`,
Zeile 296), `EHLO`, optional `STARTTLS` (Zeile 331-337, `verify_peer`/`verify_peer_name` aktiv, Zeile
294), `AUTH LOGIN` mit Base64-kodiertem Benutzer/Passwort (Zeile 340-344), `MAIL FROM`/`RCPT
TO`/`DATA`. Konfiguration ausschließlich über `config('mail')['smtp']` (`host`, `port`, `encryption`,
`user`, `pass`); Details siehe `docs/entwickler/email-system.md`.

## c) Webhooks: `stripe-webhook.php` und `billing-webhook.php`

Beide Endpunkte sind getrennt (unterschiedliche Stripe-Konten: Firmenkonten für SEPA-Einzüge bzw.
Plattformkonto der Müller Holding AG für Abonnements, Kommentarkopf `stripe-webhook.php:1-2` und
`billing-webhook.php:1-6`).

### `stripe-webhook.php` (SEPA-Einzüge der Firmen)

- **Signaturprüfung:** je Firma mit deren eigenem, verschlüsselt gespeichertem Webhook-Secret
  (`integrations.stripe_webhook_secret_encrypted`, entschlüsselt über `decrypt_value()`,
  `stripe-webhook.php:114`); ohne aktive Stripe-Integration oder ohne Secret bricht die Verarbeitung
  über `webhook_exit()` ab (Zeilen 110-117).
- **Zuordnung zur Firma:** zuerst `metadata.tenant_id` des Event-Objekts (Zeile 57); fehlt das
  (Charge-/Refund-Ereignisse ohne PaymentIntent-Metadaten), Rückgriff auf
  `payment_collections.stripe_payment_intent_id` bzw. `stripe_charge_id` (Zeilen 80-97). Ohne
  ermittelbare Firma: `webhook_exit("Firma nicht ermittelbar ...")` (Zeile 100).
- **Ereignistypen:** `payment_intent.processing`, `payment_intent.succeeded`,
  `payment_intent.payment_failed`, `charge.dispute.created`, `charge.refunded`,
  `charge.refund.updated`, `checkout.session.completed` (nur `mode=setup`, digitale
  Mandatserteilung) (Kommentarkopf Zeilen 9-17, `switch` Zeilen 245-293).
- **Doppelte Zustellung:** Für diesen Webhook **kein** `webhook_events`-Dedupe-Mechanismus im Code
  gefunden (anders als bei `billing-webhook.php`); die Verarbeitung ist stattdessen zustandsbasiert
  idempotent, z. B. setzt `payment_intent.succeeded` den Status ungeachtet des Vorzustands neu
  (Zeilen 251-254), Erstattungen werden über `collection_apply_refund()` mit dem bereits
  gespeicherten `refunded_cents`-Stand abgeglichen (`changed`-Rückgabe, Zeile 196). Nur bei
  `payment_intent.*`-Ereignissen mit `metadata.attempt_key` und passendem Alter (≥ 120 s bei
  Status `pending`, sofort bei `unknown`) wird ein noch unbekannter Versuch nachgetragen
  (`collection_attempt_recover()`, Zeilen 220-238), bewusst nicht bei einem gerade erst laufenden
  Sofort-Einzug, damit der Webhook nicht mit der eigenen Transaktion des Einzugs kollidiert
  (Kommentar Zeilen 226-229).
- **Reihenfolge:** kein expliziter Sequenzschutz über `event.created` gefunden (anders als bei
  `billing-webhook.php`, siehe unten); die Zustandsübergänge in `payment_collections` sind so
  gestaltet, dass ein späteres Ereignis den Stand konsistent überschreibt.
- **Antwortcodes:** Die Datei antwortet in jedem Fall mit HTTP 200 (`http_response_code(200)`,
  Zeile 24, bereits vor jeder Prüfung gesetzt) und Text `ok`, auch bei erkannten Fehlern
  (`webhook_exit()`, Zeilen 27-41), Kommentar: „damit Stripe nicht endlos wiederholt" (Zeile 6-7).
  Fehlerursachen werden stattdessen über `error_log()` und `monitor_event('stripe_webhook', ...)`
  festgehalten (Zeilen 30-38); Kategorie `database` bei Datenbankfehlern, `signature` bei
  Signaturfehlern, `tenant_unknown` wenn keine Firma ermittelbar war.
- **Wiederanlauf:** Da die Antwort immer 200 ist, plant Stripe selbst keinen Retry aufgrund eines
  Fehlercodes ein; ein technisch nicht verarbeitetes Ereignis bleibt dauerhaft unbehandelt, bis die
  Klärung unklarer Versuche (`job_unclear_attempts()`/`collection_attempts_resolve()`,
  siehe `docs/entwickler/jobs.md`) es über einen Lesezugriff bei Stripe nachträgt.

### `billing-webhook.php` (Plattform-Abrechnung)

- **Signaturprüfung:** ein einziges, konfiguriertes Secret `config('billing')['stripe_webhook_secret']`
  (`billing-webhook.php:16`); fehlt Secret oder Signatur oder ist sie ungültig, antwortet der Endpunkt
  mit HTTP 400 `invalid` (Zeilen 20-25), einziger hier gefundener Webhook, der NICHT immer 200
  liefert.
- **Ereignistypen:** laut `BILLING_REQUIRED_WEBHOOK_EVENTS` (`app/billing_setup.php:21-27`, auch
  ausgegeben von `bin/billing-check.php:124`): `checkout.session.completed`,
  `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`,
  `invoice.payment_failed`. Verarbeitung in `billing_handle_event()` (`app/billing.php:265-323`).
- **Zuordnung zur Firma:** `metadata.tenant_id`, bei Checkout hilfsweise `client_reference_id`
  (Zeile 275); bei Subscription-Ereignissen ohne Metadaten Rückfall auf
  `organizations.platform_stripe_subscription_id`/`platform_stripe_customer_id`
  (Zeilen 300-304).
- **Doppelte Zustellung:** `billing_event_claim()` (`app/billing.php:254-262`) versucht `INSERT IGNORE
  INTO webhook_events (id, source='billing', event_type)`; `rowCount() === 1` markiert ein neues
  Ereignis, sonst gilt es als „bereits verarbeitet" und wird ignoriert (Zeile 269-271).
- **Reihenfolge:** bei `customer.subscription.*` wird zusätzlich das jüngste bekannte
  `event_created` je Stripe-Objekt in `webhook_events.object_id`/`event_created` verglichen
  (Zeilen 291-299); ein älteres, verspätet zugestelltes Ereignis wird verworfen
  („veraltetes Ereignis ignoriert", Zeile 296).
- **Antwortcodes:** HTTP 400 bei Signaturfehler (s. o.), sonst immer HTTP 200 `ok`
  (Zeile 12, 29, 39), auch wenn `billing_handle_event()` intern eine Ausnahme wirft (abgefangen,
  Zeile 36-38, nur `error_log()`).
- **Wiederanlauf:** Stripe wiederholt bei HTTP 400 gemäß eigenem Verhalten (außerhalb dieses
  Repositorys); bei HTTP 200 nach interner Ausnahme gibt es im Code keinen Wiederholungsmechanismus
  außer einer erneuten Zustellung durch Stripe selbst.

## d) Test- und Produktivbetrieb

- **Stripe-Modus je Firma:** kein globaler Schalter `stripe_mode`, sondern Ableitung aus dem
  Schlüsselpräfix der jeweiligen Firma: `stripe_mode_from_key()` erkennt `sk_test_`/`rk_test_` als
  Testmodus, alles andere als live (`app/integrations.php:34-37`), gespeichert in
  `integrations.stripe_mode` bei jeder Prüfung (`integration_verify_stripe()`, Zeilen 44-59).
  `integration_stripe_test_mode()` (Zeilen 87-97) steuert den Testmodus-Hinweis im Layout
  (`app/layout.php:150`).
- **Plattform-Abrechnung:** eigener Schalter `billing.enabled` (`app/config.example.php:177`);
  Staging darf laut `bin/healthcheck.php --expect-env=staging` niemals mit einem Schlüssel beginnend
  `sk_live_` laufen, wenn `billing.enabled` gesetzt ist (`bin/healthcheck.php:86-91`), technische
  Absicherung gegen echte Abbuchungen in der Testumgebung.
- **`environment`-Feld:** `config('environment')` unterscheidet `prod`/`staging` (Doku im
  `config.example.php:18-27`); geprüft über `bin/healthcheck.php --expect-env` beim Deployment (Details
  in `docs/vps/06-betrieb.md`, nicht Gegenstand dieses Dokuments).
- **API-Versionen mit Prüferklärung:** Stripe wird mit zwei Versionsangaben betrieben,   `2024-06-20` als Standard für praktisch alle Aufrufe und `2024-12-18.acacia` gezielt nur für den
  einen `POST /payment_intents`-Aufruf, der `mandate_options.reference_prefix` setzt
  (`app/stripe.php:30-32`, `283-329`). Das ist beabsichtigt (unterschiedliche Antwortformate je nach
  benötigtem Feld), keine Inkonsistenz. Lexware Office hat laut Code keinen expliziten
  API-Versions-Header; die Basisadresse selbst kodiert die aktuelle Version (`/v1`,
  `app/lexoffice.php:28-29`).

## e) Bereinigte Beispielanfragen und -antworten (synthetisch)

**Lexware Office, offene Rechnungen abrufen** (`getInvoiceVouchersPage()`,
`app/lexoffice.php:219-227`):

```
GET /v1/voucherlist?voucherType=invoice&voucherStatus=open&size=100&page=0 HTTP/1.1
Host: api.lexware.io
Authorization: Bearer sk_test_XXXXXXXXXXXXXXXXXXXX
Accept: application/json
```

```json
{
  "content": [
    {"id": "b7a1c2d3-...", "voucherNumber": "RE-2026-0001", "voucherStatus": "open", "totalPrice": {"totalGrossAmount": 119.00}}
  ],
  "totalPages": 1
}
```

**Stripe, SEPA-Lastschrift auslösen** (`createPaymentIntent()`, `app/stripe.php:283-329`):

```
POST /v1/payment_intents HTTP/1.1
Host: api.stripe.com
Authorization: Basic <base64(sk_test_XXXXXXXXXXXXXXXXXXXX:)>
Stripe-Version: 2024-06-20
Idempotency-Key: collect-2026-09-07-abcd1234

amount=11900&currency=eur&customer=cus_TESTXXXX&payment_method=pm_TESTXXXX
&payment_method_types[]=sepa_debit&confirm=true
&mandate_data[customer_acceptance][type]=offline
&description=Rechnung+RE-2026-0001&metadata[tenant_id]=org_TESTXXXX
```

```json
{
  "id": "pi_TESTXXXXXXXXXXXX",
  "status": "processing",
  "amount": 11900,
  "currency": "eur"
}
```

**Stripe-Webhook, eingehendes Ereignis** (`stripe-webhook.php`, Struktur gemäß Auswertung Zeilen
55-70):

```json
{
  "id": "evt_TESTXXXXXXXXXXXX",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_TESTXXXXXXXXXXXX",
      "metadata": {"tenant_id": "org_TESTXXXX"}
    }
  }
}
```

Antwort in jedem Fall: `HTTP/1.1 200 OK`, Body `ok`.

**Migrationsendpunkt** (`migrate.php`, Vertrag Zeilen 6-12):

```
POST /migrate.php HTTP/1.1
X-Migration-Token: <migration_token, synthetisch: a1b2c3...>
```

```json
{"success": true}
```

## Offene Prüfpunkte

1. **Frage:** Gilt für Lexware Office tatsächlich ein Limit von 2 Anfragen/Sekunde, oder hat sich der
   Wert seit der letzten Dokumentationsprüfung geändert? **Quelle:** offizielle Lexware-Office-API-
   Dokumentation (online, zum Prüfzeitpunkt laut Code-Kommentar nicht erreichbar,
   `app/lexoffice.php:12-14`). **Prüfverfahren:** aktuelle Dokumentation unter der Lexware-Office-
   Entwicklerseite gegenlesen; bei Abweichung `queue.lexoffice_per_second`
   (`app/config.example.php:227`) anpassen.
2. **Frage:** Soll `stripe-webhook.php` zusätzlich einen `webhook_events`-Dedupe-Schutz erhalten (wie
   `billing-webhook.php`), um doppelte Stripe-Zustellungen unabhängig vom fachlichen Zustand
   auszuschließen? **Quelle:** `stripe-webhook.php` (kein Fund), `app/billing.php:254-262`
   (Vorbild). **Prüfverfahren:** Code-Review/Architekturentscheidung durch die Geschäftsführung;
   Testfall mit zweifach zugestelltem `payment_intent.succeeded`-Ereignis gegen eine lokale Kopie.
3. **Frage:** Ist die sevdesk-Basisadresse (`config('sevdesk')['base_url']`) und der Header-Präfix
   inzwischen mit einem echten Testkonto verifiziert? **Quelle:** `app/sevdesk.php:8-12`, `docs/sevdesk.md`.
   **Prüfverfahren:** Verbindungstest gegen ein sevdesk-Testkonto, danach `capabilities()`
   (`app/sevdesk.php:106`) schrittweise füllen.
4. **Frage:** Warum liefert `health.php` keine eigene Host-Einschränkung im PHP-Code (nur die
   Caddy-Regel des API-Hosts beschränkt den Zugriff), obwohl es laut Kommentar auch „für externe
   Prüfer" gedacht ist? **Quelle:** `health.php:1-7`, `deploy/vps/Caddyfile`. **Prüfverfahren:**
   Abgleich mit `app/bootstrap.php:220` (Ausnahmeliste `enforce_host_rules()`), IONOS-Webhosting hat
   keine Caddy-Ebene, dort wäre `health.php` auf jedem erlaubten Host erreichbar.
