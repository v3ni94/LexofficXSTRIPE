# Faktenregister SmartEinzug

Stand: 07.09.2026, Repository-Stand abd5d26 (APP_VERSION laut `php-ionos/app/version.php`). Erstellt aus dem Programmcode und der Dokumentation durch vier Faktenprüfer, Quellen als Datei und Zeilenbereich. Status: **bestätigt** (im Code vorhanden), **geplant** (vorbereitet oder per Schalter gesperrt), **nicht_vorhanden** (ausdrücklich nicht implementiert), **ungeklärt** (aus dem Code nicht entscheidbar). Die Spalte „Öffentliche Formulierung“ nennt, was zulässig ist und was falsch wäre. Produktionskonfiguration (`shared/config.php`) ist im Repository nicht enthalten; alles, was davon abhängt, steht unter „Offene Fragen“.
Zusammenfassung: 166 bestätigt, 9 geplant, 7 nicht_vorhanden, 9 ungeklärt.


## Bereich konto-sicherheit-doku

54 Einträge.

**KONTO-01** (bestätigt): Die Zwei-Faktor-Authentifizierung ist für jeden Benutzer Pflicht: require_login() leitet jeden Benutzer ohne eingerichtete 2FA zwingend nach twofa-setup.php, solange config('require_2fa', true) gilt (Vorgabe im Code und in config.example.php: true). Ohne 2FA ist die Anwendung nicht nutzbar. Das gilt für Inhaber, Administratoren und Mitarbeiter gleichermaßen.  
Quelle: ["php-ionos/app/auth.php:1-17", "php-ionos/app/auth.php:194-218", "php-ionos/app/config.example.php:134", "php-ionos/twofa-setup.php:1-6", "php-ionos/twofa-setup.php:620-621", "php-ionos/register.php:426-427"]  
Öffentliche Formulierung: Zulässig: 'Zwei-Faktor-Authentifizierung ist für jeden Benutzerzugang verpflichtend (Authenticator-App).' Falsch wären: 'optional', '2FA per SMS oder E-Mail', 'Hardware-Token', 'für Mitarbeiter optional'. Hinweis: Die Pflicht ist technisch über den Konfigurationsschlüssel require_2fa abschaltbar; der Produktionswert liegt nicht im Repository (Vorgabe true).

**KONTO-02** (bestätigt): Das 2FA-Verfahren ist TOTP nach RFC 6238 (HOTP RFC 4226), 6 Stellen, 30 Sekunden, SHA1, Toleranz plus/minus ein Zeitschritt, mit Replay-Schutz über users.totp_last_step (jeder Code gilt nur einmal). Einrichtung per QR-Code (otpauth-URI) oder manueller Schlüsseleingabe; kompatibel mit gängigen Authenticator-Apps.  
Quelle: ["php-ionos/app/totp.php:54-56", "php-ionos/app/totp.php:142-216", "php-ionos/app/totp.php:222-233", "php-ionos/app/auth.php:664-681", "php-ionos/twofa-setup.php:627-635"]  
Öffentliche Formulierung: Zulässig: 'zeitbasierte Einmalcodes (TOTP) mit jeder gängigen Authenticator-App'. Falsch: 'FIDO2/WebAuthn', 'Passkeys', 'Push-Bestätigung'.

**KONTO-03** (bestätigt): Bei der 2FA-Einrichtung werden 10 Recovery-Codes (Format XXXX-XXXX-XXXX, Zeichenvorrat ohne 0/O/1/I) erzeugt, nur einmal im Klartext angezeigt und ausschließlich als HMAC-SHA256 (Schlüssel app_secret) gespeichert; jeder Code ist genau einmal verwendbar. Ein Recovery-Code ermöglicht die Anmeldung, stellt aber keine Gerätefreigabe aus und wird bei der Zweitbestätigung kritischer Aktionen (require_recent_totp) nicht akzeptiert. Nutzung löst Audit und Sicherheits-E-Mail aus.  
Quelle: ["php-ionos/app/totp.php:235-282", "php-ionos/app/auth.php:727-773", "php-ionos/app/auth.php:496-534", "php-ionos/app/auth.php:683-711", "php-ionos/twofa-setup.php:665-681"]  
Öffentliche Formulierung: Zulässig: 'Recovery-Codes für den Geräteverlust, einmalig verwendbar, nur als Hash gespeichert'. Falsch: 'Codes jederzeit erneut einsehbar'.

**KONTO-04** (bestätigt): Plattformadministratoren (users.is_superadmin = 1) erhalten Zugriff auf den Adminbereich nur mit aktiver 2FA (require_superadmin prüft is_superadmin UND totp_enabled). Die Rolle Administrator einer Kundenfirma ist davon getrennt.  
Quelle: ["php-ionos/app/auth.php:276-284", "php-ionos/sql/schema.sql:93", "php-ionos/sql/schema.sql:102", "docs/monitoring.md:7"]  
Öffentliche Formulierung: Zulässig: 'Auch der Betreiberzugang ist nur mit 2FA möglich.'

**KONTO-05** (bestätigt): Passwörter müssen mindestens 10 und höchstens 200 Zeichen haben und dürfen nicht der E-Mail-Adresse entsprechen; sie werden ausschließlich als Hash mit password_hash(PASSWORD_DEFAULT) gespeichert und bei Bedarf automatisch neu gehasht (password_needs_rehash).  
Quelle: ["php-ionos/app/auth.php:333-345", "php-ionos/app/auth.php:429-432", "php-ionos/app/auth.php:1072-1079", "php-ionos/register.php:465-468"]  
Öffentliche Formulierung: Zulässig: 'Passwörter werden nur als Hash gespeichert.' Falsch: konkrete Nennung eines Algorithmus wie 'Argon2' oder 'bcrypt' (PASSWORD_DEFAULT hängt von der PHP-Version ab, nicht im Repository festgelegt).

**KONTO-06** (bestätigt): Ratenbegrenzung der Anmeldung: höchstens 5 Fehlversuche je E-Mail-Adresse und 30 je IP-Adresse in 15 Minuten, Kontosperre für 15 Minuten nach 10 aufeinanderfolgenden Fehlversuchen (Audit login_locked), höchstens 5 falsche 2FA-Codes je Anmeldevorgang, 2FA-Wartestufe verfällt nach 600 Sekunden. Fehlversuche werden in login_attempts (E-Mail, IP, Stufe) protokolliert.  
Quelle: ["php-ionos/app/auth.php:33-37", "php-ionos/app/auth.php:347-392", "php-ionos/app/auth.php:402-427", "php-ionos/app/auth.php:483-507"]  
Öffentliche Formulierung: Zulässig: 'Schutz vor Brute-Force durch Sperren nach Fehlversuchen'. Konkrete Zahlen nur, wenn gewünscht; sie sind Produkteinstellungen und können sich ändern.

**KONTO-07** (bestätigt): Die E-Mail-Bestätigung (Link 24 Stunden gültig, Token nur als SHA-256 gespeichert) wird nur erzwungen, wenn der Mailversand aktiv ist (mail.enabled); ohne Mailversand gilt die Adresse bei der Registrierung als bestätigt. Passwort-Zurücksetzen: Link 1 Stunde gültig, Token als Hash, Drossel 5 Anforderungen in 15 Minuten je Adresse oder IP, Antwort für Angreifer nicht unterscheidbar; nach dem Zurücksetzen enden alle Sitzungen und Gerätefreigaben, die 2FA bleibt bestehen.  
Quelle: ["php-ionos/app/auth.php:194-218", "php-ionos/app/auth.php:832-882", "php-ionos/app/auth.php:884-950", "php-ionos/app/auth.php:1072-1079", "php-ionos/verify-email.php:1-9", "php-ionos/verify-email.php:525-529"]  
Öffentliche Formulierung: Zulässig: 'Bestätigung der E-Mail-Adresse per Link' ohne Zusatz 'immer'. Falsch: 'jede Registrierung wird per E-Mail verifiziert' als absolute Aussage (gilt nur bei aktivem Mailversand).

**KONTO-08** (bestätigt): Sitzungscookie LXEINZUGSESSID: HttpOnly, SameSite=Lax, Secure bei HTTPS, Host-only, session.use_strict_mode, kein Browser- oder Proxy-Cache für Seiten mit Kundendaten. Sitzungen werden zentral widerrufbar (users.session_epoch): 'Überall abmelden', Sperren oder Entfernen eines Mitarbeiters, 2FA-Reset und Passwort-Zurücksetzung beenden alle Sitzungen der betroffenen Person.  
Quelle: ["php-ionos/app/bootstrap.php:383-400", "php-ionos/app/auth.php:99-107", "php-ionos/app/auth.php:583-609", "php-ionos/security.php:53-60", "php-ionos/team.php:254-287"]  
Öffentliche Formulierung: Zulässig: 'Sitzungen lassen sich zentral beenden (Überall abmelden); entfernte Mitarbeiter verlieren sofort den Zugriff.'

**KONTO-09** (bestätigt): Sicherheitskritische und geldrelevante Aktionen verlangen eine Zweitbestätigung mit dem aktuellen Authenticator-Code (require_recent_totp, Replay-Schutz, Recovery-Codes ausgeschlossen): Inhaberwechsel, Support-Zugriff, Wechsel des Buchhaltungssystems, Not-Stopp, Tarifwechsel, Veröffentlichung von Rechtsdokumenten und Störungsmeldungen. Nur die Passwortänderung akzeptiert eine frische Codeeingabe der letzten 5 Minuten; eine Anmeldung über Gerätefreigabe setzt dieses Fenster nicht.  
Quelle: ["php-ionos/app/auth.php:683-711", "php-ionos/security.php:29-32", "php-ionos/team.php:294-295", "php-ionos/admin-support.php:32", "php-ionos/app/devices.php:21", "php-ionos/app/devices.php:330-340", "docs/device-trust.md:44-48"]  
Öffentliche Formulierung: Zulässig: 'Kritische Aktionen erfordern eine erneute Bestätigung mit dem Authenticator-Code.'

**KONTO-10** (bestätigt): Sicherheits-E-Mails an den Benutzer bei Recovery-Code-Nutzung, Passwortänderung, Passwort-Zurücksetzung, 2FA-Einrichtung und -Reset, neuer Gerätefreigabe; an den Inhaber bei Support-Zugriff, Einladungsannahme und Firmendatenänderung durch Administratoren. Alle nur, wenn mail.enabled gesetzt ist (security_notify_user prüft mail_enabled()).  
Quelle: ["php-ionos/app/auth.php:527-533", "php-ionos/app/auth.php:804-826", "php-ionos/app/auth.php:944-969", "php-ionos/app/devices.php:172-181", "php-ionos/support-login.php:412-418", "php-ionos/team.php:122-126", "php-ionos/invite.php:106-108"]  
Öffentliche Formulierung: Zulässig: 'Sicherheitsrelevante Änderungen werden per E-Mail gemeldet.' Hinweis: Mailversand ist seit 07.09.2026 aktiv, Zustellung noch vom Betreiber zu bestätigen (docs/ARBEITSSTAND.md:108-110).

**KONTO-11** (bestätigt): Alle POST-Formulare sind über ein sitzungsgebundenes CSRF-Token (32 Zufallsbytes, hash_equals) geschützt; Fehlschlag liefert 403.  
Quelle: ["php-ionos/app/bootstrap.php:417-441", "php-ionos/security.php:19", "php-ionos/team.php:55", "php-ionos/register.php:327"]  
Öffentliche Formulierung: Technische Aussage, für Marketing in der Regel nicht nötig.

**SICH-01** (bestätigt): Der Lexware-Office-API-Schlüssel, der Stripe Secret Key und das Stripe-Webhook-Secret werden mit AES-256-GCM verschlüsselt gespeichert (openssl, Schlüssel = SHA-256 aus app_secret mit mindestens 32 Zeichen, 12 Byte zufälliger IV, 16 Byte Auth-Tag, Ablage als base64(iv\|tag\|ciphertext)). In den Einstellungen wird nur 'hinterlegt' angezeigt, nie der Klartext.  
Quelle: ["php-ionos/app/crypto.php:1-51", "php-ionos/settings.php:55", "php-ionos/settings.php:93-97", "php-ionos/settings.php:123-125", "php-ionos/settings.php:280", "php-ionos/sql/schema.sql:407-409", "php-ionos/app/integrations.php:78-83"]  
Öffentliche Formulierung: Zulässig: 'API-Schlüssel und Stripe-Geheimnisse werden serverseitig mit AES-256-GCM verschlüsselt gespeichert und nach der Eingabe nicht mehr im Klartext angezeigt.' Falsch: 'Ende-zu-Ende-verschlüsselt', 'wir haben keinen Zugriff auf Ihre Schlüssel' (der Server hält den Schlüssel app_secret und entschlüsselt zur Laufzeit), 'Hardware-Sicherheitsmodul'.

**SICH-02** (bestätigt): Das TOTP-Geheimnis jedes Benutzers wird mit derselben AES-256-GCM-Verschlüsselung gespeichert (users.totp_secret_encrypted) und nur zur Codeprüfung entschlüsselt.  
Quelle: ["php-ionos/app/auth.php:646-650", "php-ionos/app/auth.php:664-681", "php-ionos/sql/schema.sql:92"]  
Öffentliche Formulierung: Zulässig: '2FA-Geheimnisse werden verschlüsselt gespeichert.'

**SICH-03** (bestätigt): Im Portal erfasste IBANs werden im KLARTEXT in customer_ibans.iban (VARCHAR(34)) und iban_history gespeichert; encrypt_value() wird dafür nicht aufgerufen. Bei digital über Stripe erteilten Mandaten wird nur eine maskierte IBAN (Ländercode, letzte vier Stellen) gespeichert. Der AVV-Entwurf behauptet dagegen in Anlage 1 B eine 'verschlüsselte Ablage' der IBAN und in Anlage 2 'IBAN ... mit AES-256-GCM verschlüsselt gespeichert'; das entspricht nicht dem Code.  
Quelle: ["php-ionos/app/customer_settings.php:93-119", "php-ionos/sql/schema.sql:488-510", "php-ionos/app/mandate_requests.php:311-324", "php-ionos/app/legal_drafts.php:121", "php-ionos/app/legal_drafts.php:149"]  
Öffentliche Formulierung: Öffentlich darf NICHT behauptet werden, IBANs würden verschlüsselt gespeichert. Zulässig ist nur: 'Bankverbindungen werden mandantengetrennt und außerhalb des Webzugriffs gespeichert' beziehungsweise 'bei digitaler Mandatserteilung kennt SmartEinzug nur die maskierte IBAN'. Der Widerspruch zwischen Code und AVV-Entwurf ist vor Veröffentlichung des AVV aufzulösen (Code ändern oder Text ändern).

**SICH-04** (bestätigt): Audit-Details dürfen laut Kommentar keine Passwörter, Codes, API-Keys oder vollständigen IBANs enthalten (Konvention, nicht technisch erzwungen); das strukturierte Logging maskiert Stripe-Webhook-Geheimnisse (whsec_) und API-Schlüssel; Supportanfragen mit Zugangsdaten oder IBAN werden abgewiesen (ticket_reject_secrets). Die Ratenbegrenzung speichert nur eine nicht rückrechenbare Kennung des API-Schlüssels.  
Quelle: ["php-ionos/app/audit.php:313-317", "docs/auftrag-iii-abschluss.md:67", "php-ionos/app/support_tickets.php (ticket_reject_secrets, Aufruf in ticket_create)", "docs/auftrag-iii-abschluss.md:106"]  
Öffentliche Formulierung: Zulässig: 'Zugangsdaten werden nie protokolliert oder im Frontend angezeigt.' Hinweis: Die Audit-Regel ist eine Programmierkonvention; sie wurde in diesem Auftrag nicht je Aufrufstelle verifiziert.

**SICH-05** (bestätigt): Alle Firmen liegen in einer gemeinsamen Datenbank; die Trennung erfolgt logisch über tenant_id/organization_id in jeder Abfrage. Der Benutzerkontext wird bei jeder Anfrage über die Mitgliedschaft (organization_members, Status active) geladen, der Firmenwechsel prüft die Mitgliedschaft serverseitig, Profilbilder sind nur für Mitglieder derselben Firma sichtbar. Die adversariale Prüfung (Auftrag III) meldete keinen Cross-Tenant-Befund.  
Quelle: ["php-ionos/app/auth.php:78-107", "php-ionos/app/auth.php:1255-1268", "php-ionos/app/profile.php:828-844", "php-ionos/companies.php:249-253", "docs/auftrag-iii-abschluss.md:9", "CLAUDE.md:30"]  
Öffentliche Formulierung: Zulässig: 'Die Daten jedes Firmenaccounts sind logisch von anderen Firmen getrennt.' Falsch: 'eigene Datenbank je Kunde', 'physisch getrennte Server'.

**SICH-06** (bestätigt): Die Anwendung prüft den Host jeder Anfrage gegen eine Allowlist (fremde Hosts erhalten 404) und trennt bei gesetztem admin_base_url den Adminbereich (admin.smart-einzug.de) vom Kundenhost: auf dem Adminhost sind nur Anmeldung, 2FA, Passwort, Sicherheit und Adminseiten erreichbar, admin*.php auf anderen Hosts liefert 404. Ohne Konfiguration gilt ein Übergangsmodus. Der Produktionswert liegt nicht im Repository.  
Quelle: ["php-ionos/app/bootstrap.php:181-250", "php-ionos/app/config.example.php:72-101", "docs/vps/01-architektur.md:81-93"]  
Öffentliche Formulierung: Zulässig: 'Adminbereich auf getrenntem Host.' Konkrete Hostnamen nur nennen, wenn der Betreiber die produktive Konfiguration bestätigt.

**SICH-07** (bestätigt): HTTPS für alle öffentlichen Hosts; TLS endet am Coolify-Proxy (Traefik, Let's Encrypt), intern läuft HTTP zwischen Traefik, Caddy und php-fpm im Docker-Netz. X-Forwarded-Header werden nur von konfigurierten trusted_proxies übernommen. HSTS ist bewusst noch nicht aktiviert (Freigabe der Geschäftsführung erforderlich).  
Quelle: ["php-ionos/app/bootstrap.php:27-90", "docs/vps/01-architektur.md:25-54", "deploy/vps/README.md:203-206", "deploy/vps/README.md:299-303"]  
Öffentliche Formulierung: Zulässig: 'Die Kommunikation läuft verschlüsselt über HTTPS.' Falsch: 'HSTS aktiv', 'durchgehende Verschlüsselung bis zum Anwendungsserver'.

**SICH-08** (bestätigt): Mandatsdokumente, Profilbilder und Logs liegen außerhalb des Webzugriffs (app/storage mit 'Require all denied'; auf dem VPS /opt/smarteinzug/shared/storage, das Caddy nicht sieht). Profilbilder werden neu gerendert (Metadaten entfernt) und nur an Mitglieder derselben Firma ausgeliefert. Auf dem VPS binden Container Code nur lesend ein; kein Docker-Socket in Anwendungscontainern.  
Quelle: ["php-ionos/app/storage/.htaccess:1", "php-ionos/app/profile.php:662-670", "php-ionos/app/profile.php:828-853", "docs/status-page.md:191-193", "deploy/vps/README.md:253-256", "docs/vps/01-architektur.md:281-304"]  
Öffentliche Formulierung: Zulässig: 'Hochgeladene Dokumente sind nicht direkt über das Web erreichbar und nur für Mitglieder der Firma abrufbar.'

**SICH-09** (bestätigt): Stripe-Webhooks werden mit dem je Firma verschlüsselt hinterlegten Webhook-Secret signaturgeprüft und über webhook_events genau einmal verarbeitet; ohne hinterlegtes Secret werden Statusänderungen nur beim manuellen Abgleich erkannt.  
Quelle: ["php-ionos/stripe-webhook.php:114", "php-ionos/settings.php:125", "php-ionos/settings.php:280", "docs/vps/01-architektur.md:111-112", "docs/vps/01-architektur.md:210-211"]  
Öffentliche Formulierung: Zulässig: 'Statusmeldungen von Stripe werden signaturgeprüft entgegengenommen.'

**ROLLE-01** (bestätigt): Drei Rollen je Firma: Inhaber (owner: registrierende Person; allein zuständig für Mitarbeiter, Einladungen, Rollen, Inhaberschaft, Abonnement, Protokollansicht), Administrator (admin: wie Mitarbeiter plus API-Verbindungen, Firmendaten, SEPA-Einstellungen, Wechsel des Buchhaltungssystems, Zustimmung zu Rechtsdokumenten), Mitarbeiter (member: voller operativer Zugriff auf Synchronisation, Rechnungen, Einzüge, Kunden, SEPA-Pflege). Rollen werden serverseitig geprüft (require_owner, require_role, can_manage_settings).  
Quelle: ["php-ionos/app/auth.php:6-12", "php-ionos/app/auth.php:256-318", "php-ionos/team.php:1-9", "php-ionos/team.php:82-85", "php-ionos/team.php:129-135", "php-ionos/team.php:241-319", "php-ionos/team.php:358", "php-ionos/app/layout.php:219-226", "php-ionos/app/legal.php:461-463"]  
Öffentliche Formulierung: Zulässig: 'Rollen Inhaber, Administrator und Mitarbeiter mit abgestuften Rechten.' Falsch: 'frei konfigurierbare Rechte', 'Nur-Lese-Rolle' (existiert nicht: Mitarbeiter dürfen Einzüge auslösen).

**ROLLE-02** (bestätigt): Die Inhaberschaft kann nur der Inhaber übertragen, mit Passwort und aktuellem 2FA-Code; das Zielmitglied muss aktiv sein und 2FA eingerichtet haben. Der bisherige Inhaber wird Mitarbeiter; beide erhalten eine E-Mail; Audit ownership_transferred.  
Quelle: ["php-ionos/team.php:288-319", "php-ionos/team.php:603-623"]  
Öffentliche Formulierung: Zulässig: 'Inhaberschaft übertragbar, mit Passwort und 2FA-Code abgesichert.'

**ROLLE-03** (bestätigt): Mitarbeiter treten ausschließlich über Einladungen des Inhabers bei: Rolle admin oder member (nie owner), Einladungslink mit 32 Zufallsbytes, gespeichert nur als SHA-256, 7 Tage gültig, fest an Firma, E-Mail und Rolle gebunden, widerrufbar, Sitzlimit des Tarifs wird unter Zeilensperre geprüft. Bestehende Benutzer bestätigen mit Passwort (und 2FA-Code), neue legen ein Passwort fest und richten anschließend zwingend 2FA ein. Ohne Mailversand wird der Link einmalig angezeigt.  
Quelle: ["php-ionos/team.php:129-239", "php-ionos/invite.php:1-9", "php-ionos/invite.php:19-27", "php-ionos/invite.php:44-119", "docs/multiaccount.md:127"]  
Öffentliche Formulierung: Zulässig: 'Mitarbeiter per Einladung hinzufügen, jeder mit eigenem Zugang und eigener 2FA.'

**ROLLE-04** (bestätigt): Der Inhaber kann Mitarbeiter sperren, entsperren und entfernen; Sperren und Entfernen beenden sofort alle Sitzungen der Person (user_revoke_sessions). Der Inhaber selbst kann weder gesperrt noch entfernt werden. Protokolleinträge bleiben erhalten.  
Quelle: ["php-ionos/team.php:254-287", "php-ionos/app/auth.php:99-107", "php-ionos/app/auth.php:583-587"]  
Öffentliche Formulierung: Zulässig: 'Entfernte Mitarbeiter verlieren sofort den Zugriff.'

**ROLLE-05** (ungeklärt): Die Registrierungsseite nennt für UNLIMITED START 'unbegrenzte Einzüge, unbegrenzte Mitarbeiter'; das tatsächliche Sitzlimit kommt aus der Tabelle plans (seats_limit, Anzeige '(unbegrenzt)' bei null). Ob die produktive plans-Zeile ohne Limit gesetzt ist, ist aus dem Code nicht entscheidbar.  
Quelle: ["php-ionos/register.php:499", "php-ionos/team.php:146-157", "php-ionos/team.php:356-364", "CLAUDE.md:24"]  
Öffentliche Formulierung: Nur mit Bestätigung des Betreibers als 'unbegrenzte Mitarbeiter' werben; Tarifgrenzen kommen ausschließlich aus der Tabelle plans.

**MULTI-01** (bestätigt): Ein Benutzerkonto kann mehreren Firmen zugeordnet sein (organization_members). Über die Firmenübersicht legt ein angemeldeter Benutzer weitere Firmen an (wird dort Inhaber) und wechselt zwischen Firmen; jeder Wechsel prüft die aktive Mitgliedschaft serverseitig. Der Multiaccount-Schalter ist benutzerbezogen (users.multiaccount_enabled), wird bei mehr als einer Firma automatisch aktiviert und ist dann nicht deaktivierbar. Ein Firmenwechsel löst keine neue 2FA-Abfrage aus.  
Quelle: ["php-ionos/app/auth.php:1166-1243", "php-ionos/app/auth.php:1255-1282", "php-ionos/app/auth.php:1365-1415", "php-ionos/companies.php:249-300", "php-ionos/team.php:66-75", "php-ionos/team.php:399-411", "docs/multiaccount.md:81-99", "docs/device-trust.md:42"]  
Öffentliche Formulierung: Zulässig: 'Mehrere Firmen mit einem Benutzerkonto verwalten.' Falsch: 'konzernweite Auswertungen über Firmen hinweg' (nicht vorhanden).

**MULTI-02** (bestätigt): Jede Firma hat vollständig getrennte Kunden, Rechnungen, Einzüge, Mandate, eigene Lexware-Office- und Stripe-Anbindung, eigenes Mandatspräfix (global eindeutig, nachträglich nicht änderbar) und eigenes Abonnement. Multiaccount verbindet nur Anmeldung und Navigation, nicht die Datenbestände. Genau ein Buchhaltungssystem je Firma; wer zwei Buchhaltungen führt, braucht zwei Firmenaccounts.  
Quelle: ["php-ionos/companies.php:249-253", "php-ionos/companies.php:297-300", "php-ionos/companies.php:349-357", "php-ionos/app/auth.php:1189-1193", "docs/multiaccount.md:83", "docs/integrations.md:236-240"]  
Öffentliche Formulierung: Zulässig: 'Je Firma eigene Anbindungen und eigenes Abonnement; Multiaccount verbindet nur die Anmeldung.' Falsch: 'ein Abonnement für alle Firmen'.

**MULTI-03** (bestätigt): Eine E-Mail-Adresse gehört zu genau einer Benutzeridentität (eindeutiger Index users.email). Registriert sich eine bekannte Adresse erneut, wird kein zweites Konto angelegt: Der Benutzer wird zur Anmeldung geführt, die Firmenanlage 30 Minuten zwischengespeichert und erst nach Passwort und 2FA abgeschlossen. Das dabei eingegebene Passwort wird weder geprüft noch gespeichert. Bewusste Offenlegung: Die Antwort verrät, dass die Adresse registriert ist (Ratenbegrenzung 10 Versuche je IP in 15 Minuten).  
Quelle: ["php-ionos/app/auth.php:1297-1298", "php-ionos/app/auth.php:1417-1458", "php-ionos/register.php:363-398", "docs/multiaccount.md:101-121"]  
Öffentliche Formulierung: Für Marketing nicht relevant; für Datenschutzprüfung: Existenz einer E-Mail-Adresse ist über die Registrierung erkennbar (docs/multiaccount.md:121).

**GERAET-01** (bestätigt): Optionale Gerätefreigabe 'Dieses Gerät für 90 Tage merken': nur nach erfolgreicher Authenticator-Bestätigung und ausdrücklicher Checkbox. Browser erhält Cookie '<id>.<geheim>' (32 Zufallsbytes, __Host-Präfix bei HTTPS, HttpOnly, SameSite=Lax, Host-only), serverseitig nur HMAC-SHA256 des Geheimnisses; Vergleich mit hash_equals; Token wird bei jeder Anmeldung rotiert. Feste Laufzeit 90 Tage (UTC), keine Verlängerung durch Nutzung. Ersetzt ausschließlich die Codeabfrage bei der Anmeldung; Passwort, Sperren, Rollen und Mitgliedschaften werden unverändert geprüft. Recovery-Code-Anmeldung stellt keine Freigabe aus. Freigaben je Bereich (app/admin) getrennt.  
Quelle: ["php-ionos/app/devices.php:1-21", "php-ionos/app/devices.php:59-112", "php-ionos/app/devices.php:152-245", "php-ionos/app/auth.php:436-452", "php-ionos/app/auth.php:515-525", "php-ionos/twofa-setup.php:642-650", "docs/device-trust.md:5-42"]  
Öffentliche Formulierung: Zulässig: 'Vertrauenswürdige Geräte können optional 90 Tage gemerkt werden; das Passwort bleibt immer erforderlich.' Falsch: 'Bankenstandard', 'regulatorisch konform' (docs/device-trust.md:11), 'Hardwarebindung', 'verlängert sich automatisch', 'Geräteerkennung über IP'.

**GERAET-02** (bestätigt): Unter Sicherheit, Gemerkte Geräte: Liste mit Browserbezeichnung (grob aus User-Agent), Bereich, Freigabezeitpunkt, letzte Verwendung, Ablauf; Aktionen 'Gerät vergessen', 'Alle Geräte vergessen', 'Überall abmelden' (Sitzungsepoche plus Widerruf aller Freigaben). Automatischer Widerruf aller Freigaben bei Passwortänderung, Passwort-Zurücksetzung, 2FA-Einrichtung, 2FA-Reset (auch durch Support). Widerruf wirkt sofort: Sitzungen auf widerrufener Freigabe enden beim nächsten Zugriff. Widerrufene und abgelaufene Einträge werden nach 30 Tagen gelöscht. Jede Freigabe erzeugt Audit device_trusted und eine Sicherheits-E-Mail.  
Quelle: ["php-ionos/security.php:38-60", "php-ionos/security.php:143-196", "php-ionos/app/devices.php:247-328", "php-ionos/app/auth.php:60-70", "php-ionos/app/auth.php:654-655", "php-ionos/app/auth.php:787", "php-ionos/app/auth.php:944", "php-ionos/app/auth.php:963", "docs/device-trust.md:50-60"]  
Öffentliche Formulierung: Zulässig: 'Gemerkte Geräte sind jederzeit einsehbar und widerrufbar.'

**GERAET-03** (bestätigt): Die Wiedererkennung ist browserbezogen; ein entwendetes Cookie kann zusammen mit dem Passwort bis zum Widerruf oder Ablauf verwendet werden; IP-Adresse und Browserkennung dienen nur der Anzeige. Die Funktion setzt Migration 016 voraus; ohne die Tabelle trusted_devices wird die Checkbox nicht angeboten und nichts bricht.  
Quelle: ["php-ionos/app/devices.php:13-15", "php-ionos/app/devices.php:137-150", "php-ionos/twofa-setup.php:642", "docs/device-trust.md:62-64"]  
Öffentliche Formulierung: Grenzen nicht verschweigen, wenn die Funktion beworben wird; keine Aussage, die eine Gerätebindung suggeriert.

**AUDIT-01** (bestätigt): audit_log protokolliert jede sicherheits- und geldrelevante Aktion mit Firma, Benutzer (Kennung, E-Mail), Aktion, Zielobjekt, Zusatzangaben (JSON), IP-Adresse und Zeitpunkt; im Support-Modus zusätzlich mit Support-Vermerk; rund 100 Aktionstypen (Anmeldungen, 2FA, Einladungen, Rollen, Verbindungen, IBAN, Mandate, Einzüge, Abonnement, Support, Rechtsdokumente, Systemwechsel). Fehler beim Protokollieren brechen die Aktion nie ab. Keine Fremdschlüssel: Einträge bleiben bei gelöschten Benutzern oder Firmen erhalten.  
Quelle: ["php-ionos/app/audit.php:283-350", "php-ionos/app/audit.php:392-498", "docs/vps/01-architektur.md:225-229", "CLAUDE.md:30"]  
Öffentliche Formulierung: Zulässig: 'Sicherheits- und geldrelevante Aktionen werden mit Person und Zeitpunkt protokolliert.' Falsch: 'lückenlose Protokollierung aller Aktionen' (nur definierte Aktionstypen), 'unveränderliches Protokoll' (Datenbanktabelle ohne technische Unveränderbarkeit).

**AUDIT-02** (bestätigt): Protokolleinträge werden 90 Tage aufbewahrt (AUDIT_RETENTION_DAYS, überschreibbar mit config audit.retention_days, Mindestwert 30) und danach durch die Wartung gelöscht (audit_cleanup, bis 5.000 Zeilen je Lauf). Fachliche Nachweise bleiben unabhängig davon erhalten: Einzüge (payment_collections), Mandate (sepa_mandates), Vertragszustimmungen (legal_acceptances). Der AVV-Entwurf sagt in Anlage 1 D 'Wird zu Nachweiszwecken nicht gelöscht' und in Anlage 2 'unveränderliches Protokoll'; beides widerspricht der 90-Tage-Löschung.  
Quelle: ["php-ionos/app/audit.php:283-293", "php-ionos/app/audit.php:352-372", "php-ionos/team.php:629-632", "docs/rechtsdokumente.md:186-188", "php-ionos/app/legal_drafts.php:136", "php-ionos/app/legal_drafts.php:150", "CLAUDE.md:28"]  
Öffentliche Formulierung: Zulässig: 'Protokoll 90 Tage einsehbar und exportierbar; Einzüge, Mandate und Vertragszustimmungen bleiben dauerhaft nachweisbar.' Falsch: 'dauerhaftes Audit-Protokoll', 'revisionssicher'. AVV-Entwurf vor Veröffentlichung anpassen.

**AUDIT-03** (bestätigt): Die Protokollansicht unter Firmendaten ist nur für den Inhaber gefüllt (20 Zeilen sichtbar, aufklappbar bis 200); Export als CSV über export.php?typ=protokoll (selbst protokolliert als audit_exported). Jeder Benutzer sieht unter Sicherheit seine letzten 15 Anmeldeversuche mit Stufe, Ergebnis und IP-Adresse. Superadmins sehen im Adminbereich das Protokoll der Support-Zugriffe (letzte 30).  
Quelle: ["php-ionos/team.php:358", "php-ionos/team.php:628-670", "php-ionos/app/audit.php:374-390", "php-ionos/security.php:85-91", "php-ionos/security.php:228-246", "php-ionos/admin-support.php:83", "php-ionos/admin-support.php:265-288"]  
Öffentliche Formulierung: Zulässig: 'Der Inhaber kann das Protokoll einsehen und als CSV exportieren.' Falsch: 'jeder Mitarbeiter sieht das Protokoll'.

**AUDIT-04** (bestätigt): IP-Adressen werden in audit_log, login_attempts, registration_requests, support_sessions und trusted_devices (ip_created) gespeichert; nicht in funnel_events, legal_acceptances und interest_registrations. Für login_attempts, funnel_events, Supporttickets und technische Laufprotokolle ist keine automatische Löschfrist dokumentiert.  
Quelle: ["php-ionos/app/audit.php:303-311", "php-ionos/app/audit.php:345", "php-ionos/app/audit.php:500-514", "php-ionos/app/auth.php:351-352", "php-ionos/app/auth.php:1461-1468", "php-ionos/app/support.php:35-41", "php-ionos/app/devices.php:166-169", "php-ionos/app/legal.php:348", "docs/rechtsdokumente.md:184", "CLAUDE.md:25"]  
Öffentliche Formulierung: Keine Werbeaussage 'keine IP-Speicherung' für die Anwendung; das gilt nur für Funnel, Vormerkung und Rechtsnachweise.

**SUPPORT-01** (bestätigt): Ein Superadmin startet im Adminbereich einen Support-Zugriff auf eine Firma mit Pflichtgrund (5 bis 255 Zeichen, z. B. Ticketnummer) und aktuellem 2FA-Code. Es entsteht ein Einmal-Token (nur als Hash gespeichert, 5 Minuten einlösbar), das auf dem Kundenhost über support-login.php eingelöst wird. Die Sitzung läuft höchstens 60 Minuten mit der Rolle Administrator und wird im Adminbereich angezeigt und dort widerrufbar; sie kann jederzeit beendet werden (support-end.php). Eine vorherige Zustimmung oder Freigabe des Kunden ist NICHT vorgesehen; der Kunde wird informiert (Sicherheits-E-Mail an den Inhaber bei aktivem Mailversand) und alle Aktionen tragen im Protokoll der Firma einen Support-Vermerk.  
Quelle: ["php-ionos/app/support.php:1-73", "php-ionos/admin-support.php:28-35", "php-ionos/admin-support.php:122-126", "php-ionos/support-login.php:1-6", "php-ionos/support-login.php:392-420", "php-ionos/support-end.php:1-22", "php-ionos/app/audit.php:327-329", "php-ionos/app/auth.php:113-159"]  
Öffentliche Formulierung: Zulässig: 'Supportzugriffe sind zeitlich befristet (höchstens 60 Minuten), begründet, mit 2FA bestätigt, für den Kunden im Protokoll sichtbar und lösen eine Benachrichtigung an den Inhaber aus.' Falsch: 'Support sieht Ihre Daten nur mit Ihrer Zustimmung', 'Support hat keinen Zugriff auf Kundendaten' (im Support-Modus sind Rechnungen, Kunden, Mandate und Einzüge sichtbar).

**SUPPORT-02** (bestätigt): Im Support-Modus sind Einzüge (Einreichen, Sammel-Einzug, Ausnahme außerhalb des Fensters), IBAN-Änderungen, Zugangsdaten (Lexware-Schlüssel, Stripe-Schlüssel, Webhook-Secret, Trennen), Stripe-Import und die Fortsetzung einer Registrierung gesperrt (support_guard wirft eine Ausnahme, Audit support_access_blocked). Profil-Multiaccount-Schalter und Firmenübersicht sind ausgeblendet.  
Quelle: ["php-ionos/app/support.php:104-110", "php-ionos/collections.php:29", "php-ionos/collections.php:53", "php-ionos/sepa-pflegen.php:30", "php-ionos/sepa-pflegen.php:48", "php-ionos/settings.php:47-128", "php-ionos/stripe-import.php:24", "php-ionos/register-fortsetzen.php:13", "php-ionos/app/collections.php:1213", "php-ionos/app/collections.php:1473", "php-ionos/app/layout.php:124"]  
Öffentliche Formulierung: Zulässig: 'Der Support kann keine Einzüge auslösen und keine Bankverbindungen oder Zugangsdaten ändern.'

**SUPPORT-03** (bestätigt): Superadmins können Konten entsperren und die 2FA eines Benutzers zurücksetzen; dafür sind eigenes Passwort und eigener 2FA-Code nötig. Der Reset löscht Geheimnis und Recovery-Codes, beendet alle Sitzungen und Gerätefreigaben, wird als 2fa_admin_reset protokolliert und per E-Mail an den Nutzer gemeldet. Die Identitätsprüfung (z. B. Rückruf) ist organisatorische Vorgabe im Hinweistext, nicht technisch erzwungen.  
Quelle: ["php-ionos/admin-support.php:46-66", "php-ionos/admin-support.php:247-263", "php-ionos/app/auth.php:779-802"]  
Öffentliche Formulierung: Zulässig: '2FA-Zurücksetzung durch den Support nur nach Identitätsprüfung, protokolliert und mit Benachrichtigung.'

**SUPPORT-04** (bestätigt): Supportanfragen laufen über ein Hilfe-Center im Firmenaccount (Betreff, Kategorie, Seite, Verlauf); Antworten des Betreibers erscheinen dort und werden bei aktivem Mailversand per E-Mail gesendet; Anfragen mit Zugangsdaten oder IBAN werden abgewiesen. Ticketinhalte sind für Superadmins einsehbar.  
Quelle: ["php-ionos/admin-support.php:36-41", "php-ionos/admin-support.php:92-108", "php-ionos/admin-support.php:192-245", "php-ionos/app/support_tickets.php (ticket_reject_secrets in ticket_create)", "php-ionos/app/legal_drafts.php:138"]  
Öffentliche Formulierung: Zulässig: 'Support-Anfragen direkt aus der Anwendung.'

**RECHT-01** (bestätigt): Rechtsdokumente (Auftragsverarbeitungsvertrag nach Art. 28 DSGVO, Verschwiegenheitsvereinbarung nach § 203 StGB) sind versioniert in legal_documents; Firmen sehen und akzeptieren nur veröffentlichte, nicht zurückgezogene Fassungen. Zustimmen dürfen nur Inhaber und Administratoren; der Nachweis (Firma, Fassung, Benutzer, E-Mail, Zeitpunkt UTC, Weg) liegt in legal_acceptances ohne IP-Adresse, idempotent je Firma und Fassung, mit Audit legal_accepted. Die Verschwiegenheitsvereinbarung wird Pflicht, sobald die Firma unter Rechtliches ihre Verschwiegenheitspflicht angibt. Veröffentlichte Fassungen werden nie gelöscht; eine neue Fassung zieht die alte zurück und verlangt erneute Zustimmung.  
Quelle: ["php-ionos/app/legal.php:341-356", "php-ionos/app/legal.php:371-386", "php-ionos/app/legal.php:396-475", "php-ionos/app/legal.php:509-543", "docs/rechtsdokumente.md:157-168", "docs/rechtsdokumente.md:178"]  
Öffentliche Formulierung: Zulässig erst nach Veröffentlichung einer Fassung: 'AVV wird elektronisch im Firmenaccount geschlossen, Nachweis mit Fassung und Zeitpunkt jederzeit einsehbar.'

**RECHT-02** (geplant): Im Repository liegen ausschließlich ENTWÜRFE (Version '2026-09-entwurf-1') für die anwaltliche Prüfung. Anlage 3 (Unterauftragsverarbeiter) enthält Platzhalter für Hostinganbieter mit Serverstandort und Sicherungsspeicher; solange der Text '[Platzhalter' enthält, verweigert legal_document_publish() die Veröffentlichung. Ob produktiv eine Fassung veröffentlicht wurde, ist aus dem Code nicht entscheidbar; laut Dokumentation stehen anwaltliche Prüfung und Angaben zu Anlage 3 noch aus. Die Datenschutzerklärungen beider Domains enthalten noch den Platzhalter 'Auftragsverarbeitungsvertrag ergänzen beziehungsweise verlinken'.  
Quelle: ["php-ionos/app/legal_drafts.php:1-38", "php-ionos/app/legal_drafts.php:157-159", "php-ionos/app/legal.php:517-520", "docs/rechtsdokumente.md:170-178", "docs/rechtsdokumente.md:190-195", "docs/ARBEITSSTAND.md:139-142", "websites/smart-einzug.de/datenschutz/index.html:106", "websites/lexware-einzug.de/datenschutz.html:113"]  
Öffentliche Formulierung: Bis zur Veröffentlichung NICHT behaupten: 'AVV verfügbar', 'AVV wird bei Registrierung abgeschlossen', 'Verschwiegenheitsvereinbarung für Berufsgeheimnisträger verfügbar'. Zulässig: 'Ein Auftragsverarbeitungsvertrag wird bereitgestellt' nur mit Freigabe der Geschäftsführung und Klärung des Zeitpunkts.

**RECHT-03** (bestätigt): Die Registrierung verlangt die AVV-Checkbox nur, wenn eine Fassung veröffentlicht ist; die Zustimmung wird mit Weg 'registration' als Nachweis gespeichert. Das Dashboard mahnt offene Pflichtdokumente an. Firmen ohne Zustimmung werden nicht gesperrt (Entscheidung der Geschäftsführung offen, nicht umgesetzt).  
Quelle: ["php-ionos/register.php:320-333", "php-ionos/register.php:352-359", "php-ionos/register.php:485-492", "docs/rechtsdokumente.md:175", "docs/rechtsdokumente.md:194"]  
Öffentliche Formulierung: Keine Aussage 'ohne AVV keine Nutzung möglich'.

**RECHT-04** (ungeklärt): Anlage 1 des AVV-Entwurfs beschreibt die verarbeiteten Daten aus einer Codeinventur vom 07.09.2026: aus Lexware Office nur lesend Profil, Belegliste, Rechnungsdetail, Kontakt (Name, Kundennummer, eine E-Mail-Adresse), Zahlungsstand; keine Anschriften, Telefonnummern, Steuernummern, Bankverbindungen; an Stripe nur Name, E-Mail (bei Fehlen eine technische Platzhalteradresse), Kennungen, IBAN und Kontoinhaber bei Portalerfassung, Betrag und Verwendungszweck. Zutreffend belegt sind die AES-256-GCM-Aussagen zu Schnittstellenschlüsseln und Stripe-Geheimnissen. Nicht codekonform sind: 'verschlüsselte Ablage' der IBAN (Anlage 1 B und Anlage 2), 'Protokoll wird nicht gelöscht' und 'unveränderliches Protokoll' (Anlage 1 D und Anlage 2). Nur Betreiberangaben, nicht aus dem Code belegbar: SSH-Schlüsselauthentifizierung, Firewall, Sperrung nach Fehlversuchen, tägliche Sicherungen, Serverstandort.  
Quelle: ["php-ionos/app/legal_drafts.php:9-13", "php-ionos/app/legal_drafts.php:103-144", "php-ionos/app/legal_drafts.php:145-153", "php-ionos/app/customer_settings.php:112-119", "php-ionos/app/audit.php:352-372", "docs/rechtsdokumente.md:180-184"]  
Öffentliche Formulierung: Anlage 1 darf öffentlich als 'aus dem Code erhobene Datenanlage' beschrieben werden, aber erst nach Korrektur der beiden Widersprüche. Änderungen an Schnittstellen erfordern eine neue Fassung.

**RECHT-05** (bestätigt): Anbieter laut Impressum smart-einzug.de und AVV-Entwurf: Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein, Sitz Monheim am Rhein, Registergericht Amtsgericht Düsseldorf, HRB 104291, Vorstand Timo Müller, Aufsichtsratsvorsitzender Jan Walprecht, E-Mail kontakt@mueller-holding.ag, Web mueller-holding.ag. Telefon und USt-IdNr. sind im Impressum noch Platzhalter. Beide Leadseiten (lexware-einzug.de, lexoffice-einzug.de) tragen derzeit dasselbe Impressum der Müller Holding AG.  
Quelle: ["websites/smart-einzug.de/impressum/index.html:68-110", "websites/smart-einzug.de/impressum/index.html:145", "php-ionos/app/legal_drafts.php:42", "websites/lexware-einzug.de/impressum.html:82-100", "websites/lexoffice-einzug.de/impressum.html:76"]  
Öffentliche Formulierung: Nur diese Angaben verwenden; nichts ergänzen oder erfinden. Platzhalter Telefon und USt-IdNr. vor Veröffentlichung neuer Seiten klären.

**HOST-01** (ungeklärt): Die Anwendung (app, admin, api, status) läuft auf einem Hostinger-VPS KVM 8 (8 vCPU, 32 GB RAM, 400 GB NVMe, Ubuntu 24.04, Coolify mit Traefik-Proxy, Docker), Hostname srv1960492.hstgr.cloud, IPv4 72.61.80.67. Der Rechenzentrumsstandort (Land) ist in keiner gelesenen Quelle dokumentiert; die AVV-Anlage 3 enthält dafür einen Platzhalter und die Dokumentation führt 'Angaben zu Hostinganbieter (Firma, Anschrift, Serverstandort)' als offen.  
Quelle: ["docs/vps/01-architektur.md:11-16", "docs/vps/01-architektur.md:65-79", "docs/auftrag-iii-abschluss.md:124-132", "docs/betrieb-migration-vps.md:78-83", "php-ionos/app/legal_drafts.php:157", "docs/rechtsdokumente.md:193"]  
Öffentliche Formulierung: Für die Anwendung darf KEINE Standortaussage ('Server in Deutschland', 'EU-Hosting', 'Rechenzentrum in ...') gemacht werden, bis der Betreiber den Vertragsstandort belegt. Zulässig: 'Betrieb auf einem eigenen virtuellen Server bei einem europäischen Hostinganbieter' nur, wenn der Betreiber dies bestätigt; im Repository nicht belegt.

**HOST-02** (bestätigt): Die Datenbank MariaDB 11.8.9 (Datenbank smarteinzug) läuft als private Coolify-Ressource auf demselben VPS, ohne öffentlichen Port, erreichbar nur im Docker-Netz; Redis läuft intern ohne Host-Port und ohne dauerhaften Datenbestand; Zugriff von außen nur per SSH-Tunnel; Coolify-Oberfläche nicht öffentlich. Vom Betreiber bestätigt, nicht aus dem Repository verifizierbar.  
Quelle: ["docs/vps/01-architektur.md:135-163", "docs/vps/01-architektur.md:275-276", "docs/auftrag-iii-abschluss.md:200-206", "deploy/vps/README.md:192-201", "docs/betrieb-migration-vps.md:184-198"]  
Öffentliche Formulierung: Zulässig: 'Datenbank nicht aus dem Internet erreichbar.' Standort wie HOST-01 ungeklärt.

**HOST-03** (ungeklärt): Tägliche Datenbanksicherung durch Coolify mit externem Upload in einen Hetzner-Object-Storage-Bucket 'smarteinzug' (S3-Endpoint fsn1.your-objectstorage.com); Restore laut Betreiber getestet, Testdatum, Zielinstanz und Prüfsummen nicht dokumentiert. Das Endpoint-Kürzel 'fsn1' wird im Repository nicht als Standort ausgewiesen; ein Land ist damit nicht belegt. Für Anlage 3 des AVV fehlen Firma, Anschrift und Speicherregion des Objektspeicheranbieters (Platzhalter).  
Quelle: ["docs/betrieb-migration-vps.md:54-68", "docs/betrieb-migration-vps.md:87", "docs/betrieb-migration-vps.md:626", "docs/betrieb-migration-vps.md:643", "docs/vps/01-architektur.md:76", "docs/vps/01-architektur.md:113-118", "deploy/vps/README.md:192-194", "php-ionos/app/legal_drafts.php:158"]  
Öffentliche Formulierung: Zulässig: 'tägliche Sicherungen mit externem Sicherungsziel' (Betreiberangabe). Nicht zulässig ohne Nachweis: 'Backups in Deutschland/EU', 'georedundant'. Der Anbieter Hetzner darf genannt werden (dokumentiert); Standort erst nach Bestätigung der Bucket-Region.

**HOST-04** (bestätigt): Die Marketing- und Leadseiten (smart-einzug.de, lexware-einzug.de, lexoffice-einzug.de, Aliase) liegen auf dem IONOS-Webhosting; die Datenschutzerklärungen nennen 'IONOS SE, Deutschland' als Hoster (Firmensitz, kein Rechenzentrumsstandort). Systemmails gehen über smtp.ionos.de; Anlage 3 nennt IONOS SE, Montabaur, als Unterauftragsverarbeiter für den E-Mail-Versand. Die alte Anwendungsdatenbank bei IONOS wurde laut Auftraggeber gelöscht.  
Quelle: ["websites/smart-einzug.de/datenschutz/index.html:77-78", "websites/lexware-einzug.de/datenschutz.html:88", "docs/vps/01-architektur.md:18-23", "docs/betrieb-migration-vps.md:68", "php-ionos/app/config.example.php:137-143", "php-ionos/app/legal_drafts.php:159", "docs/ARBEITSSTAND.md:108-110"]  
Öffentliche Formulierung: Zulässig: 'Diese Webseite wird bei der IONOS SE, Deutschland, gehostet.' Nicht auf die Anwendung übertragen: Die Anwendung läuft nicht bei IONOS.

**HOST-05** (bestätigt): Ein einzelner VPS trägt Anwendung, Datenbank, Redis und Coolify; kein Failover, keine Replikation, keine Hochverfügbarkeit. Ausfall des VPS bedeutet Nichtverfügbarkeit bis zur Wiederherstellung aus der Sicherung. Deployment mit Candidate-Prüfung, isolierter Migration, Cutover und automatischem Rollback; Wartungsmodus mit 503.  
Quelle: ["docs/vps/01-architektur.md:259-270", "deploy/vps/README.md:258-267", "docs/auftrag-iii-abschluss.md:554-558", "deploy/vps/README.md:63-104", "php-ionos/app/bootstrap.php:286-319"]  
Öffentliche Formulierung: Falsch wären: 'hochverfügbar', 'redundant', 'ausfallsicher', 'georedundant', 'Cluster'. Zulässig: 'tägliche Sicherungen, kontrollierte Deployments mit Rückfallmöglichkeit'.

**VERF-01** (bestätigt): Die AGB beider Domains sichern keine bestimmte prozentuale Verfügbarkeit zu. Intern misst die Anwendung die Verfügbarkeit zeitgewichtet aus eigenen Prüfungen; öffentliche Prozentwerte erscheinen nur bei mindestens 99 Prozent Messabdeckung (monitoring.public_min_coverage_pct), sonst 'Unvollständige Messdaten'; Wartung wird nicht herausgerechnet; kurze Ausfälle zwischen Prüfungen werden nicht erfasst; die Anwendung misst sich selbst, ein unabhängiger externer Prüfer ist nicht eingerichtet; Marketingseiten werden nicht gemessen.  
Quelle: ["websites/smart-einzug.de/agb/index.html:111-112", "websites/lexoffice-einzug.de/agb.html:94-95", "docs/monitoring.md:52-67", "docs/monitoring.md:95", "php-ionos/app/config.example.php:214", "php-ionos/app/monitor.php:1218", "docs/status-page.md:143-149", "docs/status-page.md:173-175", "docs/status-page.md:209-211", "docs/ARBEITSSTAND.md:98"]  
Öffentliche Formulierung: Keine Prozentwerte ('99,9 %'), kein 'SLA', keine 'garantierte Verfügbarkeit'. Zulässig: 'Wir bemühen uns um eine möglichst unterbrechungsfreie Verfügbarkeit' (AGB-Formulierung) und 'öffentliche Statusseite' nach Inbetriebnahme.

**STATUS-01** (bestätigt): Öffentliche Statusseite status.smart-einzug.de: statische Seite im Release, Daten aus status.json (Positivliste: Gesamtzustand, Komponenten Webanwendung, Anmeldung, Datenabgleich Lexware Office, Einzugsverarbeitung, E-Mail-Benachrichtigungen; veröffentlichte Störungen und Wartungen; Verfügbarkeit 30 und 90 Tage nur bei ausreichender Abdeckung; 90-Tage-Historie). Keine Cookies, keine Versionen, Hosts, Zähler oder Kundendaten. Aktualisierung alle 60 Sekunden, Kennzeichnung als veraltet nach 15 Minuten. Geschrieben von monitor_collect() (alle vier Minuten), bei Störungsveröffentlichung und manuell im Adminbereich (2FA). Ohne konfiguriertes status_publish zeigt die Seite 'Status unbekannt'.  
Quelle: ["docs/status-page.md:112-141", "docs/status-page.md:171", "docs/status-page.md:177-207", "docs/vps/01-architektur.md:79", "docs/vps/01-architektur.md:88", "deploy/vps/README.md:249-251"]  
Öffentliche Formulierung: Zulässig nach bestätigter Inbetriebnahme: 'Öffentliche Statusseite mit Komponentenstatus, Störungsmeldungen und Verfügbarkeitshistorie.' Nicht: 'unabhängiges externes Monitoring'.

**STATUS-02** (ungeklärt): status_publish wurde vom Betreiber am 07.09.2026 in shared/config.php gesetzt und worker-maintenance neu gestartet; die Wirksamkeit (status.json mit Daten statt Platzhalter) ist nicht bestätigt. Auf dem Server liegen zwei versehentlich erzeugte leere Dateien. Ein Footer-Link 'Systemstatus' auf der Hauptwebsite war laut Einrichtungsliste bewusst zurückgestellt, bis die Seite erreichbar ist; der Link im Anwendungsfooter erscheint nur bei gesetztem status_page_url.  
Quelle: ["docs/ARBEITSSTAND.md:93-96", "docs/status-page.md:118-120", "docs/status-page.md:151-168", "php-ionos/app/config.example.php:254"]  
Öffentliche Formulierung: Statusseite erst verlinken und bewerben, wenn der Betreiber die Datenanzeige bestätigt hat.

**STATUS-03** (bestätigt): Störungs- und Wartungsmeldungen werden manuell von Plattformadministratoren (optional eingeschränkt über monitoring.editors) mit 2FA-Code angelegt und veröffentlicht, mit Phasen und getrennten öffentlichen und internen Texten; Änderungen werden auditiert. Alarm-E-Mails an den Betreiber nur bei konfigurierten monitoring.alert_emails (nach 3 Fehlprüfungen, Entwarnung nach 2); ein unabhängiger Alarmkanal ist vorbereitet, aber nicht aktiv.  
Quelle: ["docs/monitoring.md:5-11", "docs/monitoring.md:69-73", "docs/auftrag-ii-abschluss.md:187-192"]  
Öffentliche Formulierung: Zulässig: 'Störungen werden auf der Statusseite kommuniziert.' Nicht: 'automatische Störungserkennung rund um die Uhr mit garantierter Reaktionszeit'.

**KONTO-12** (bestätigt): Registrierung nur bei config allow_registration; legt Benutzer (Inhaber), Firma, Mitgliedschaft und Integrationsdatensatz in einer Transaktion unter einer datenbankweiten Sperre an; Mandatspräfix 2 bis 10 Zeichen, global eindeutig, nachträglich nicht änderbar; Herkunft (src, utm, Referrer) wird für die Auswertung gespeichert; Pflichtfelder AGB/Datenschutz-Bestätigung; Passwort-Doppeleingabe. Nach der Registrierung folgt (bei aktivem Mailversand) die E-Mail-Bestätigung, dann zwingend die 2FA-Einrichtung, dann das Onboarding.  
Quelle: ["php-ionos/register.php:295-318", "php-ionos/register.php:326-362", "php-ionos/app/auth.php:976-1131", "php-ionos/app/auth.php:1245-1253", "php-ionos/twofa-setup.php:574-577"]  
Öffentliche Formulierung: Zulässig: 'Registrierung, 2FA-Einrichtung, dann Verbindung von Lexware Office und Stripe.' Der Text 'Kein Produkt der Haufe-Lexware GmbH & Co. KG' und der vorsichtige Hinweis zu Lexware Office XL sind auf der Registrierungsseite vorhanden und öffentlich zu übernehmen.


## Bereich mandate-stripe

40 Einträge.

**MAND-01** (bestätigt): Weg 1 (Standard): Ein Mitarbeiter erzeugt in den Kundendetails ein Mandatsdokument (Druckansicht A4, Schaltfläche 'Drucken / Als PDF speichern'). Die Mandatsreferenz wird dabei vom Portal vergeben. Die Unterschrift des Zahlungspflichtigen erfolgt auf Papier; der Mitarbeiter erfasst anschließend nur Datum und Ort der Unterschrift ('Unterschrift erfassen'). Das Unterschriftsdatum darf nicht in der Zukunft liegen.  
Quelle: ["php-ionos/customer.php:54-76", "php-ionos/mandate-print.php:1-9,85-142", "php-ionos/app/mandates.php:241-265"]  
Öffentliche Formulierung: Zulässig: 'Mandatsdokument je Kunde erzeugen, Mandatsreferenz automatisch vergeben, Unterschrift und Gültigkeit im Blick'. Falsch wäre: 'digitale Unterschrift', 'elektronische Signatur', 'automatische Mandatseinholung'. Die Software prüft die Unterschrift nicht, sie hält nur den Erfassungsvermerk fest.

**MAND-02** (bestätigt): Weg 2: Upload eines unterschriebenen Papiermandats als PDF, JPG oder PNG bis 10 MB. Der Dateityp wird am Inhalt geprüft (finfo, PDF-Kopf, getimagesize), die Datei wird mit Zufallsnamen außerhalb des Webzugriffs abgelegt (SHA-256 wird gespeichert), nur über mandate-file.php nach Mandantenprüfung ausgeliefert, jede Aktion wird auditiert. Optional wird beim Upload die Unterschrift gleich erfasst (Häkchen 'Unterschrift gleich erfassen', Datum und Ort). Löschen dürfen nur Inhaber und Administratoren.  
Quelle: ["php-ionos/app/mandate_files.php:1-18,48-113,184-212", "php-ionos/customer.php:78-87,363-397", "php-ionos/sepa-pflegen.php:44-46,144-167", "php-ionos/mandate-file.php:1-32", "php-ionos/setup-check.php:252-257"]  
Öffentliche Formulierung: Zulässig: 'Unterschriebenes Mandat als Scan oder Foto hinterlegen (PDF, JPG, PNG bis 10 MB)'. Nicht behaupten: Texterkennung, inhaltliche Prüfung des Dokuments oder Ersatz der Aufbewahrungspflicht (customer.php:364-366 sagt ausdrücklich, dass der Upload das Original nicht ersetzt).

**MAND-03** (bestätigt): Weg 3: Manuelle Erfassung einer IBAN durch Mitarbeiter (Kundendetails oder Arbeitsseite 'SEPA Pflegen'). Die IBAN wird nach ISO 13616 (Modulo 97, Länderlängen DE, AT, CH, NL, FR, BE, LU, IT, ES) geprüft, ein BIC optional auf 8 oder 11 Zeichen. Das Speichern setzt 'SEPA-Einzug: Ja' für die Kundennummer, bindet ein vorhandenes Mandat an die IBAN oder legt ein Mandat an und registriert die Zahlungsmethode sofort bei Stripe (ohne Zahlung), sofern Stripe verbunden ist. Andere Länder als die neun genannten werden abgelehnt.  
Quelle: ["php-ionos/app/customer_settings.php:59-166", "php-ionos/app/iban.php:12-54", "php-ionos/app/collections.php:937-1000", "php-ionos/sepa-pflegen.php:29-43", "php-ionos/customer.php:39-48,437-447"]  
Öffentliche Formulierung: Zulässig: 'IBAN mit Prüfsummenkontrolle hinterlegen'. Nicht behaupten: 'alle SEPA-Länder', 'IBAN-Bankabgleich' oder 'Kontoinhaber wird verifiziert'. Es findet keine Prüfung gegen Bankdaten statt.

**MAND-04** (geplant): Weg 4 (digital): Mitarbeiter fordern das Mandat per Link an. Der Zahlungspflichtige erhält per E-Mail einen 14 Tage gültigen Link auf die öffentliche Seite mandat.php (Token 32 Byte, nur SHA-256-Hash gespeichert). Dort liest er den Mandatstext, bestätigt per Häkchen und wird zu einer Stripe Checkout Session im Modus 'setup' mit payment_method_types sepa_debit weitergeleitet (keine Zahlung). Stripe meldet checkout.session.completed; die App ruft den SetupIntent ab (Status succeeded) und aktiviert das Mandat (signed_place 'digital (Stripe)', signed_date = Tag der Erteilung). Bis zu zwei Erinnerungen nach frühestens 4 Tagen mit rotierendem Token. Der Weg ist ein Feature-Schalter (features.mandate_request), Standard aus; ohne Schalter fehlt der Button, customer.php lehnt serverseitig ab, mandat.php liefert 404.  
Quelle: ["php-ionos/app/mandate_requests.php:1-43,109-146,201-240,247-357,368-400", "php-ionos/mandat.php:1-53", "php-ionos/app/stripe.php:405-423", "php-ionos/customer.php:89-107", "php-ionos/app/config.example.php:271-280", "docs/payment-safety.md:111-121"]  
Öffentliche Formulierung: Öffentlich erst nach Freischaltung als vorhanden nennen. Bis dahin höchstens 'in Vorbereitung'. Nicht schreiben: 'Kunde unterschreibt digital', 'E-Signatur'. Korrekt wäre nach Freigabe: 'Mandat per Link, Bankverbindung wird direkt bei Stripe eingegeben'.

**MAND-05** (bestätigt): Es gibt kein eigenes Online-Formular der Anwendung, in dem der Zahlungspflichtige selbst eine IBAN eingibt. Bei digitaler Erteilung wird die Bankverbindung ausschließlich bei Stripe eingegeben; die Anwendung erhält nur Land, Bankleitzahl und die letzten vier Stellen und speichert eine maskierte IBAN (Quelle 'stripe_digital'). Die Zahlungsmethode bei Stripe wird für Einzüge direkt verwendet.  
Quelle: ["php-ionos/mandat.php:118,127-131", "php-ionos/app/mandate_requests.php:17-20,276-330", "php-ionos/app/collections.php:960-963,1122-1124"]  
Öffentliche Formulierung: Zulässig (nach Freigabe von MAND-04): 'Die IBAN Ihres Kunden liegt bei Stripe, SmartEinzug sieht sie nur maskiert'. Falsch wäre: 'Kundenportal zur Bankdateneingabe'.

**MAND-06** (bestätigt): Die interne Mandatsreferenz vergibt das Portal: Firmenpräfix (organizations.mandate_prefix, Rückfall 'FIRMA') plus Kundennummer ohne Sonderzeichen; bei erneuter Vergabe für dieselbe Kundennummer (z. B. nach Widerruf) mit laufender Endung '-2', '-3'. Laufkunden erhalten Präfix plus JJJJMMTT plus dreistellige laufende Nummer. Prüfung: 1 bis 35 Zeichen, eingeschränkter SEPA-Zeichensatz, je Firma eindeutig (UNIQUE-Index tenant_id, mandate_reference).  
Quelle: ["php-ionos/app/mandates.php:38-48,95-130", "php-ionos/sql/schema.sql:545", "php-ionos/customer.php:212"]  
Öffentliche Formulierung: Zulässig: 'Mandatsreferenz wird automatisch vergeben, max. 35 Zeichen, je Firma eindeutig'. Nicht behaupten, dass diese Referenz auf dem Kontoauszug des Zahlers erscheint (siehe MAND-07).

**MAND-07** (bestätigt): Der technische Einzug läuft über das Stripe-Konto der Firma. Stripe erzeugt eine eigene Mandatsreferenz und verwendet gegenüber den Banken seine Gläubiger-Identifikationsnummer; laut Code und Mandatstext können deshalb auf dem Kontoauszug des Zahlungspflichtigen Gläubiger-ID und Mandatsreferenz von Stripe erscheinen. Die Stripe-Referenz wird nach dem ersten erfolgreichen Einzug (GET /v1/charges, GET /v1/mandates, Feld payment_method_details.sepa_debit.reference) oder bei digitaler Erteilung gespeichert und in Kundendetails und Einzugsübersicht getrennt von der internen Referenz angezeigt.  
Quelle: ["php-ionos/app/mandates.php:23-26,339-347", "php-ionos/app/collections.php:895-928", "php-ionos/app/mandate_requests.php:281-289,334-345", "php-ionos/customer.php:222-223,258-259", "php-ionos/team.php:445-446", "docs/payment-safety.md:59"]  
Öffentliche Formulierung: Zulässig: 'Der Einzug läuft über Ihr eigenes Stripe-Konto; auf dem Kontoauszug Ihres Kunden können Gläubiger-ID und Mandatsreferenz von Stripe stehen'. Falsch wäre: 'Einzug unter Ihrer eigenen Gläubiger-ID' oder 'Ihre Mandatsreferenz erscheint auf dem Kontoauszug'. Welche Stripe-Gläubiger-ID konkret erscheint, ist aus dem Code nicht ableitbar.

**MAND-08** (geplant): Optional kann die von Stripe erzeugte Mandatsreferenz mit dem Firmenpräfix beginnen (payment_method_options.sepa_debit.mandate_options.reference_prefix, Stripe-API-Version 2024-12-18.acacia; nur Großbuchstaben und Ziffern, höchstens 12 Zeichen, nicht mit 'STRIPE' beginnend). Lehnt Stripe das Präfix ab, wird der Einzug ohne Präfix wiederholt. Der Schalter config 'stripe_mandate_reference_prefix' ist standardmäßig aus und soll erst nach einem erfolgreichen Test-Einzug aktiviert werden.  
Quelle: ["php-ionos/app/stripe.php:216-219,464-532", "php-ionos/app/collections.php:1149-1155", "php-ionos/app/config.example.php:166-170"]  
Öffentliche Formulierung: Nicht öffentlich bewerben, solange der Schalter aus ist. Ob er produktiv gesetzt ist, ist aus dem Repository nicht ersichtlich (config.php nicht im Repo).

**MAND-09** (bestätigt): Die Gläubiger-ID der Firma ist ein freiwilliges Feld unter Firmendaten. Wenn hinterlegt, wird sie mit Prüfziffer (ISO 7064 Mod 97-10, Geschäftsbereichskennung unberücksichtigt) und für DE auf genau 18 Stellen geprüft, am Mandat gespeichert (sepa_mandates.creditor_identifier), im Mandatsdokument, auf mandat.php und in der Vorabankündigungsmail ausgegeben. An Stripe wird sie nicht übermittelt (PaymentIntent-Metadaten: tenant_id, invoice_id, mandate_reference, voucher_number, customer_number, attempt_key). Fehlt sie, erscheint im Dokument ein Platzhalter.  
Quelle: ["php-ionos/app/mandates.php:50-81,170-183,373-383", "php-ionos/team.php:90-96,442-446", "php-ionos/mandate-print.php:102-103", "php-ionos/mandat.php:104-106", "php-ionos/app/collections.php:1156-1165,1191-1192"]  
Öffentliche Formulierung: Zulässig: 'Gläubiger-ID mit Prüfziffernkontrolle, erscheint auf dem Mandatsdokument'. Nicht behaupten: 'Einzug unter Ihrer Gläubiger-ID' (siehe MAND-07).

**MAND-10** (bestätigt): Verwendet wird ausschließlich die SEPA-Basislastschrift (Core): Stripe-Zahlungsmethode 'sepa_debit' bei PaymentMethod, PaymentIntent und Checkout Session; Mandatsdokument und mandat.php tragen die Bezeichnung 'SEPA-Basislastschrift'. Eine Firmenlastschrift (B2B) ist an keiner Stelle vorgesehen.  
Quelle: ["php-ionos/app/stripe.php:414,450-451,484", "php-ionos/mandate-print.php:94", "php-ionos/mandat.php:108", "php-ionos/app/mandates.php:5,321"]  
Öffentliche Formulierung: Zulässig: 'SEPA-Basislastschrift über Stripe'. Falsch wäre jede Erwähnung von B2B-Lastschrift oder 'ohne Erstattungsrecht'. Der Mandatstext enthält den Acht-Wochen-Erstattungshinweis (mandates.php:336-338).

**MAND-11** (bestätigt): Neu angelegte Mandate sind immer wiederkehrend (mandate_type 'recurrent'). Der Datenbankwert 'one_off' existiert und wird im Dokument dargestellt, es gibt aber keine Oberfläche zur Anlage einmaliger Mandate.  
Quelle: ["php-ionos/app/mandates.php:174-183", "php-ionos/sql/schema.sql:531", "php-ionos/mandate-print.php:94,110-111", "php-ionos/customer.php:225"]  
Öffentliche Formulierung: Nicht mit 'Einmalmandaten' werben.

**MAND-12** (bestätigt): Vor jedem Einzug prüft die Software: (1) Mandat aktiv und weder widerrufen noch verfallen; (2) IBAN am Mandat hinterlegt; (3) 36-Monats-Regel: Anker ist die letzte Nutzung, sonst Unterschriftsdatum, sonst Mandatsdatum; liegt der Anker mehr als 36 Monate zurück, wird das Mandat dauerhaft als 'expired' markiert und der Einzug abgelehnt; (4) bei aktiver Firmeneinstellung 'Handschriftlicher Nachweis erforderlich' (require_signed_mandate, Standard 1) muss ein Unterschriftsdatum erfasst sein; (5) nach Widerruf oder Verfall wird kein Mandat still neu angelegt, ein neues Mandat ist ausdrücklich über die Kundendetails zu erfassen. Die letzte Nutzung wird bei jeder Einreichung fortgeschrieben.  
Quelle: ["php-ionos/app/mandates.php:36,190-211,279-318", "php-ionos/app/collections.php:1236-1249,1281-1293,1422", "docs/payment-safety.md:16"]  
Öffentliche Formulierung: Zulässig: 'Mandat verfällt nach 36 Monaten ohne Einzug und wird dann gesperrt', 'Einzug nur mit erfasstem Nachweis, wenn gewünscht'. Nicht behaupten: 'rechtssichere Mandatsprüfung' oder 'Mandatsprüfung nach Bundesbank-Vorgaben'; die Regeln stehen im Code ausdrücklich unter dem Vorbehalt der Verifikation durch Rechtsberatung (mandates.php:5-6).

**MAND-13** (ungeklärt): Die Beschriftung unter Firmendaten lautet 'Handschriftlicher Nachweis erforderlich (Einzug erst nach erfasster Unterschrift oder hochgeladenem Mandat)'. Der Code prüft jedoch ausschließlich sepa_mandates.signed_date. Ein hochgeladenes Dokument ohne gesetztes Häkchen 'Unterschrift gleich erfassen' macht das Mandat nicht nutzbar.  
Quelle: ["php-ionos/team.php:462", "php-ionos/app/mandates.php:313-316", "php-ionos/app/mandate_files.php:204-210"]  
Öffentliche Formulierung: Öffentlich nur 'Einzug erst nach erfasster Unterschrift' formulieren, nicht 'oder hochgeladenem Mandat'.

**MAND-14** (bestätigt): Mandate werden nie gelöscht, sondern widerrufen (status 'cancelled', is_active 0, Zeitpunkt und optionaler Grund, Audit 'mandate_cancelled'). Ein Widerruf ist für jedes aktive Mandat in den Kundendetails möglich. Ein Widerruf durch den Zahler oder seine Bank wird nicht automatisch übernommen; eine Rücklastschrift ändert den Mandatsstatus nicht.  
Quelle: ["php-ionos/app/mandates.php:15-21,267-277", "php-ionos/customer.php:112-122,259-266", "php-ionos/stripe-webhook.php:276-288"]  
Öffentliche Formulierung: Zulässig: 'Mandate bleiben zur Aufbewahrung gespeichert, Widerruf mit Protokoll'. Nicht behaupten: 'Widerruf wird automatisch erkannt'.

**MAND-15** (bestätigt): Das Mandatsdokument und mandat.php enthalten: Autorisierungstext (Deutsche Kreditwirtschaft, ergänzt um Firmenname), Hinweis auf das Erstattungsrecht von acht Wochen, Hinweis auf Stripe Payments Europe, Ltd. als Zahlungsdienstleister mit Ermächtigung, Vorabankündigungshinweis (14 Kalendertage oder die verkürzte Frist der Firma, 'Rechnung gilt als Vorabankündigung'), Verfallshinweis 36 Monate. Bei aktiviertem HVM-CI (organizations.use_hvm_ci) trägt das Dokument Kennlinie und Pflichtangaben der Hausverwaltung Müller GmbH, sonst neutraler Aufbau.  
Quelle: ["php-ionos/app/mandates.php:320-367", "php-ionos/mandate-print.php:5-7,36,92,127-130,137-141", "php-ionos/mandat.php:111-119"]  
Öffentliche Formulierung: Zulässig: 'Mandatstext mit Erstattungshinweis, Vorabankündigung und Zahlungsdienstleister-Hinweis'. Nicht behaupten: 'anwaltlich geprüfter Mandatstext'.

**MAND-16** (bestätigt): Je Firma: Vorabankündigungsfrist 1 bis 30 Tage (Standard 14) und Schalter 'Vorabankündigung per E-Mail durch das Portal senden'. Ist der Schalter aktiv, sind Sofort-Einzüge gesperrt, Einzüge müssen mit mindestens der Frist terminiert werden, der Kunde braucht eine E-Mail-Adresse, und die Ankündigung wird beim Terminieren sowie beim Umterminieren versendet (Fehlschlag verhindert die Terminierung). Ist der Schalter aus, versendet die Software keine Vorabankündigung; laut Mandatstext gilt dann die Rechnung als Vorabankündigung, was die Software nicht prüft. Termine nur an Werktagen Mo bis Fr, höchstens 365 Tage voraus.  
Quelle: ["php-ionos/team.php:449-459", "php-ionos/app/collections.php:1003-1021,1183-1198,1258-1300,1376-1380,1471-1528", "php-ionos/app/mandates.php:348-355"]  
Öffentliche Formulierung: Zulässig: 'optionale Vorabankündigung per E-Mail beim Terminieren'. Falsch wäre: 'Vorabankündigung immer automatisch' oder 'Frist wird immer eingehalten'.

**MAND-17** (bestätigt): Für Laufkunden (Sammel-Kundennummer, customers.is_walk_in) wird kein personenbezogenes Mandat erzeugt, keine digitale Anforderung erlaubt und der SEPA-Schalter nicht angeboten; IBANs werden ohne Stripe-Registrierung gespeichert.  
Quelle: ["php-ionos/customer.php:55-57,185-187", "php-ionos/app/customer_settings.php:33-38,121-125,139-158", "php-ionos/app/mandate_requests.php:114-116"]  
Öffentliche Formulierung: Keine Aussage nötig; falls, dann 'Sammelkunden ohne Mandat'.

**MAND-18** (bestätigt): Manuell erfasste IBANs werden im Klartext in customer_ibans gespeichert (keine Verschlüsselungsspalte), in der Oberfläche maskiert angezeigt (erste vier und letzte vier Stellen) und im Audit nur maskiert protokolliert. Jede Änderung wird in iban_history festgehalten. Nur API-Schlüssel und Webhook-Secrets liegen verschlüsselt vor.  
Quelle: ["php-ionos/app/customer_settings.php:111-119,133-135", "php-ionos/app/iban.php:63-74", "php-ionos/customer.php:409", "php-ionos/sql/schema.sql:407-409,488-516"]  
Öffentliche Formulierung: Nicht behaupten: 'IBANs verschlüsselt gespeichert'. Zulässig: 'IBAN in der Oberfläche maskiert, Änderungshistorie'.

**STRIPE-01** (bestätigt): Jede Firma verbindet ihr eigenes Stripe-Konto über einen Secret Key oder Restricted Key (Format sk_ oder rk_, test oder live; Publishable Keys werden abgelehnt). Der Schlüssel wird vor dem Speichern gegen GET /v1/account geprüft, verschlüsselt gespeichert (encrypt_value) und nie wieder angezeigt. Konto-ID, Business Name und Modus werden gespeichert. Es gibt kein Stripe Connect, keine Plattform-Verrechnung der Kundenzahlungen: 'Zahlungen laufen ausschließlich über Ihr Konto'. Das Stripe-Konto der Müller Holding AG wird nur für das Abonnement der Firmen genutzt (billing.php, getrennt).  
Quelle: ["php-ionos/settings.php:79-105,271-272,343-368", "php-ionos/app/integrations.php:44-59,75-79", "php-ionos/app/stripe.php:190-197", "php-ionos/app/collections.php:1023-1036"]  
Öffentliche Formulierung: Zulässig: 'Ihr eigenes Stripe-Konto, Zahlungen laufen direkt auf Ihr Konto, SmartEinzug hält kein Geld'. Falsch wäre: 'Stripe Connect', 'Zahlungsabwicklung durch SmartEinzug', 'Treuhandkonto'.

**STRIPE-02** (ungeklärt): Die Einrichtungsanleitung empfiehlt einen eingeschränkten Schlüssel (rk_) mit Schreibrecht für Customers, Payment Methods, Payment Intents und Leserecht für Charges und Disputes. Der Code ruft zusätzlich GET /v1/mandates/{id}, GET /v1/customers/search, GET /v1/payment_intents/search, GET /v1/setup_intents/{id}, GET /v1/checkout/sessions/{id} und POST /v1/checkout/sessions auf. Ob die empfohlenen Rechte für diese Aufrufe ausreichen, ist aus dem Code nicht entscheidbar.  
Quelle: ["php-ionos/settings.php:349", "php-ionos/app/stripe.php:333-373,395-398,405-423,426-446"]  
Öffentliche Formulierung: Öffentlich nur 'eigener Stripe-Schlüssel, eingeschränkter Schlüssel möglich' ohne konkrete Rechteliste, bis geklärt.

**STRIPE-03** (bestätigt): Test- oder Live-Modus wird aus dem Schlüssel abgeleitet und angezeigt ('Testmodus, es werden keine echten Lastschriften ausgeführt'). Jede Änderung der Verbindung wird auditiert und dem Inhaber per Sicherheits-E-Mail gemeldet. Trennen löscht Schlüssel und Webhook-Secret; Einzüge und Mandate bleiben erhalten, neue Einzüge sind bis zur erneuten Verbindung nicht möglich.  
Quelle: ["php-ionos/settings.php:20-25,99-105,127-134,264-278", "php-ionos/app/integrations.php:54-58"]  
Öffentliche Formulierung: Zulässig: 'Testmodus zum Ausprobieren'.

**STRIPE-04** (bestätigt): Der Webhook-Endpunkt ist /stripe-webhook.php je Installation, mandantenübergreifend. Die Firma wird aus metadata.tenant_id des Objekts oder über PaymentIntent bzw. Charge des gespeicherten Einzugs ermittelt; danach wird die Signatur (Header Stripe-Signature, HMAC-SHA256, Toleranz 300 Sekunden) mit dem verschlüsselt gespeicherten Webhook-Secret der Firma geprüft. Der Endpunkt antwortet immer HTTP 200. Das Webhook-Secret ist optional; ohne Secret werden alle Meldungen verworfen, Rücklastschriften und Erstattungen werden dann nicht erkannt (Alarm 'mittel' im Dashboard).  
Quelle: ["php-ionos/stripe-webhook.php:1-7,43-121", "php-ionos/app/stripe.php:535-570", "php-ionos/settings.php:154,280-303", "php-ionos/app/alerts.php:372-379"]  
Öffentliche Formulierung: Zulässig: 'Rückmeldung von Stripe per Webhook, Signatur wird geprüft'. Hinweis: 'Rücklastschrift kommt automatisch zurück' gilt nur mit eingerichtetem Webhook.

**STRIPE-05** (bestätigt): Verarbeitet werden genau sieben Ereignistypen: payment_intent.processing, payment_intent.succeeded, payment_intent.payment_failed, charge.dispute.created, charge.refunded, charge.refund.updated und checkout.session.completed (nur mode=setup). Alle anderen Typen werden ignoriert. Die Anleitung fordert, nur diese sieben im Stripe-Dashboard anzuhaken. Ereignis-IDs werden für den Firmen-Webhook nicht gespeichert; Mehrfachverarbeitung wird über Statusübergänge verhindert (z. B. atomarer Wechsel auf 'granted', unveränderter Erstattungsstand ohne Änderung).  
Quelle: ["php-ionos/stripe-webhook.php:9-18,58-76,245-293", "php-ionos/settings.php:288-297", "php-ionos/app/mandate_requests.php:250-256", "docs/payment-safety.md:71"]  
Öffentliche Formulierung: Zulässig: 'Erfolg, Fehlschlag, Rücklastschrift und Erstattung kommen von Stripe zurück'. Nicht behaupten: 'alle Stripe-Ereignisse' oder 'Echtzeit-Kontoabgleich'.

**STRIPE-06** (bestätigt): Bei manuell erfasster IBAN legt die App bei Stripe einen Customer an oder findet ihn über Metadaten (tenant_id, customer_id), erzeugt eine PaymentMethod 'sepa_debit' mit der vollständigen IBAN und billing_details (Kontoinhaber, E-Mail des Kunden oder Rückfall noreply@<app-host>), hängt sie an den Customer an und erstellt einen PaymentIntent (EUR, confirm=true, mandate_data.customer_acceptance.type='offline', Beschreibung als Verwendungszweck, Metadaten, Idempotency-Key). Bei digital erteiltem Mandat wird die bei Stripe gespeicherte Zahlungsmethode verwendet, keine neue aus der IBAN erzeugt.  
Quelle: ["php-ionos/app/stripe.php:426-462,470-516", "php-ionos/app/collections.php:61-66,1108-1176"]  
Öffentliche Formulierung: Technische Details nicht bewerben. Falsch wäre: 'Kunde bestätigt jede Lastschrift online'.

**STRIPE-07** (bestätigt): Der Verwendungszweck (PaymentIntent description) lautet '<Firmenname> SEPA <Rechnungsnummer> KD <Kundennummer> - <Stichwort>', höchstens 140 Zeichen, auf den SEPA-Zeichensatz bereinigt (Umlaute ersetzt). Das Stichwort wird aus den Rechnungspositionen erkannt (Katalog: Vermietung, Verkauf, Verwaltung, Mieterhöhung, Nebenkostenabrechnung, Kaution, Provision, Instandhaltung, Sonstiges).  
Quelle: ["php-ionos/app/keywords.php:14-66,67-80,151-169", "php-ionos/app/collections.php:1104"]  
Öffentliche Formulierung: Zulässig: 'Verwendungszweck mit Firmenname, Rechnungs- und Kundennummer'. Ob Stripe den Text unverändert auf dem Kontoauszug ausgibt, ist aus dem Code nicht ableitbar.

**STRIPE-08** (bestätigt): Jeder Stripe-Aufruf läuft durch api_call_gate (Circuit Breaker, Ratenbegrenzung Standard 20 Aufrufe je Sekunde je Stripe-Konto, 200 insgesamt), Timeout 30 Sekunden, Stripe-Version 2024-06-20. Jeder Einzugsversuch erhält einen Idempotency-Key (sha256 aus Firma, Rechnung, Betrag, Versuchsnummer) und einen Eintrag im Versuchsjournal; bei unbekanntem Ergebnis (Timeout, 5xx) wird nicht wiederholt, sondern über die Stripe Search API geklärt (Cron und Button). HTTP 5xx und 429 gelten als Störung, 4xx als fachliche Ablehnung.  
Quelle: ["php-ionos/app/stripe.php:215-219,232-305", "php-ionos/app/collections.php:51,709-760", "docs/payment-safety.md:38-47", "php-ionos/cron.php:105", "php-ionos/app/jobs.php:56,226"]  
Öffentliche Formulierung: Zulässig: 'Schutz vor Doppelbelastung durch Idempotenz und Versuchsjournal'. Nicht behaupten: 'Stripe garantiert ...'.

**STRIPE-09** (bestätigt): Bestehende Einzüge aus Stripe können einmalig übernommen werden (Inhaber und Administratoren, 2FA-Zweitbestätigung, im Support-Modus gesperrt). Der Import liest PaymentIntents der letzten 3, 6, 12 oder 24 Monate (nur Lesezugriff), ordnet über metadata.voucher_number und Betrag/Währung zu, übernimmt nur eindeutige Treffer als Einzüge mit Herkunft 'import' und übernimmt Erstattungsstand und Dispute-Kennzeichen. Mandate und IBANs werden nicht importiert, weil Stripe die IBAN nicht vollständig herausgibt.  
Quelle: ["php-ionos/app/stripe_import.php:1-24,130-190,244-322", "php-ionos/stripe-import.php:1-5,20-59,82", "php-ionos/app/stripe.php:381-389"]  
Öffentliche Formulierung: Zulässig: 'Übernahme bereits eingereichter Lastschriften aus Ihrem Stripe-Konto, nur lesend'. Nicht behaupten: 'Import von Mandaten oder Bankverbindungen aus Stripe'.

**STATUS-01** (bestätigt): Einzugsstatus (payment_collections.stripe_status) und Anzeige: 'scheduled' = Terminiert (bzw. 'queued' Vorgemerkt bei Sofort-Einzügen in der Karenzzeit), 'submitting' = Wird eingereicht, 'processing' = In Bearbeitung, 'succeeded' = Erfolgreich, 'failed' = Fehlgeschlagen, 'disputed' = Rücklastschrift, 'refunded' = Erstattet, 'cancelled' = Storniert. Rechnungsstatus (invoices.collection_status): open, queued, scheduled, in_collection (Im Einzug), collected (Eingezogen), failed (Fehlgeschlagen).  
Quelle: ["php-ionos/app/layout.php:229-264", "php-ionos/stripe-webhook.php:245-288", "php-ionos/app/collections.php:804-860,1408-1418", "docs/payment-safety.md:143-149"]  
Öffentliche Formulierung: Zulässig: 'Status je Einzug: terminiert, in Bearbeitung, erfolgreich, fehlgeschlagen, Rücklastschrift, erstattet'.

**STATUS-02** (bestätigt): Es gibt keinen von 'In Bearbeitung' getrennten Status 'eingereicht': Unmittelbar nach dem Anlegen des PaymentIntent steht der Einzug auf 'processing' und die Rechnung auf 'in_collection'; das Webhook-Ereignis payment_intent.processing setzt ebenfalls nur 'processing' (außer bei bereits erfolgreichem Einzug).  
Quelle: ["php-ionos/app/collections.php:1407-1418", "php-ionos/stripe-webhook.php:246-249"]  
Öffentliche Formulierung: Nicht mit einem eigenen Status 'eingereicht bei der Bank' werben.

**STATUS-03** (bestätigt): Statusabgleich ohne Webhook ('Status mit Stripe abgleichen', auch per Cron): Für Einzüge im Status 'processing' wird der PaymentIntent abgerufen; 'succeeded' setzt Erfolgreich und die Rechnung auf Eingezogen und speichert Charge und Stripe-Mandatsdaten; 'canceled' oder 'requires_payment_method' setzt Fehlgeschlagen mit Stripe-Fehlertext. Rücklastschriften (Disputes) und Erstattungen zu bereits erfolgreichen Einzügen werden vom Abgleich nicht erkannt, dafür ist der Webhook erforderlich. Der Hinweistext in settings.php:280 ('Rücklastschriften werden nur beim manuellen Abgleich erkannt') deckt sich insoweit nicht mit dem Code.  
Quelle: ["php-ionos/app/collections.php:1548-1600", "php-ionos/settings.php:280,284", "php-ionos/app/alerts.php:376"]  
Öffentliche Formulierung: Formulieren: 'Erfolg und Fehlschlag auch ohne Webhook per Abgleich; Rücklastschriften und Erstattungen erfordern den Webhook'.

**RUECK-01** (bestätigt): Eine Rücklastschrift oder ein Widerspruch des Zahlers erreicht die App ausschließlich als Stripe-Ereignis charge.dispute.created. Der Einzug erhält Status 'disputed' mit dem festen Grund 'SEPA-Lastschrift wurde vom Kunden widerrufen', die Rechnung wird 'failed', es entsteht ein Audit-Eintrag 'collection_disputed'. Der Stripe-Rückgabegrund (dispute reason) wird nicht ausgewertet; Rückgaben wegen mangelnder Deckung und echte Widersprüche werden gleich behandelt. Ein 'disputed'-Einzug zählt nicht mehr als eigener Einzug der Rechnung.  
Quelle: ["php-ionos/stripe-webhook.php:60-65,203-205,276-288", "docs/payment-safety.md:60"]  
Öffentliche Formulierung: Zulässig: 'Rücklastschriften werden erkannt, markiert und protokolliert'. Falsch wäre: 'Rückgabegrund wird angezeigt', 'Unterscheidung Widerspruch/Deckung'.

**RUECK-02** (bestätigt): Es gibt keine automatische Wiederholung: Weder nach 'payment_failed' noch nach Rücklastschrift noch nach Erstattung reicht die Software erneut ein. Ein fehlgeschlagener Einzug kann nicht umterminiert werden; ein Neu-Einzug erfolgt nur manuell (Einzelrechnung, Sammel-Einzug oder Terminierung) und durchläuft wieder alle Prüfungen einschließlich Live-Restbetrag bei Lexware Office. Auch bei unbekanntem Ergebnis eines Versuchs wird nicht wiederholt, sondern geklärt.  
Quelle: ["php-ionos/app/collections.php:18,51,1495", "php-ionos/stripe-webhook.php:266-274", "docs/payment-safety.md:60,74,105"]  
Öffentliche Formulierung: Zulässig: 'kein automatischer Neu-Einzug, Sie entscheiden'. Falsch wäre: 'automatische Wiederholung', 'Dunning', 'Retry-Logik'.

**RUECK-03** (bestätigt): Erstattungen werden nur aus Stripe übernommen (charge.refunded mit amount_refunded, charge.refund.updated mit Nachlesen der Charge). Vollerstattung: Einzug 'refunded', Rechnung wieder 'open' und 'requires_review' mit Vermerk 'kein automatischer Neu-Einzug'. Teilerstattung: refunded_cents gesetzt, Status bleibt 'succeeded', Rechnung 'requires_review'. Klärung schließt nur ein Inhaber oder Administrator ab. Die App selbst löst keine Erstattungen aus (kein POST /v1/refunds).  
Quelle: ["php-ionos/stripe-webhook.php:157-200", "php-ionos/app/collections.php:799-860", "php-ionos/app/stripe.php:327-533", "docs/payment-safety.md:63-78"]  
Öffentliche Formulierung: Zulässig: 'Erstattungen aus Stripe werden übernommen und die Rechnung zur Klärung markiert'. Falsch wäre: 'Erstattung per Klick aus SmartEinzug'.

**RUECK-04** (bestätigt): Rücklastschriften lösen keine E-Mail aus, weder an den Zahlungspflichtigen noch an den Inhaber. Die Alarmierung (alerts_for_tenant) kennt keine Bedingung für 'disputed'; die Rücklastschrift ist nur in Einzugsübersicht, Kundendetails, CSV-Export und Audit sichtbar.  
Quelle: ["php-ionos/app/alerts.php:329-411", "php-ionos/stripe-webhook.php:276-288", "docs/payment-safety.md:109"]  
Öffentliche Formulierung: Nicht behaupten: 'Benachrichtigung bei Rücklastschrift'. Zulässig: 'Rücklastschriften sichtbar in Übersicht und Export'.

**FEES-01** (nicht_vorhanden): Die Anwendung berechnet, speichert oder zeigt keine Stripe-Gebühren, Rücklastschriftgebühren oder Mahngebühren; keine Weiterberechnung an den Zahler. Die Marketingseiten nennen keine Gebührenhöhe, sondern nur, dass Stripe die Gebühren des eigenen Stripe-Kontos direkt mit der Firma abrechnet.  
Quelle: ["Suche nach 'Gebühr', 'fee', 'payout' in php-ionos/app/*.php und php-ionos/*.php ohne Treffer außer Stichwortkatalog php-ionos/app/keywords.php:30,51", "websites/smart-einzug.de/index.html:258-261,287,307", "websites/lexware-einzug.de/index.html:336-339", "websites/lexoffice-einzug.de/index.html:292"]  
Öffentliche Formulierung: Zulässig: 'Stripe-Gebühren Ihres eigenen Kontos rechnet Stripe direkt mit Ihnen ab'. Keine Prozentsätze oder Cent-Beträge von Stripe nennen; keine Aussage 'keine Gebühren'. Hinweis: lexware-einzug.de:336 sagt 'keine Kosten je Einzug', das bezieht sich auf den Tarif der Plattform und sollte im Kontext der Stripe-Gebühren eindeutig formuliert sein.

**FEES-02** (nicht_vorhanden): Es gibt keine Aussage zur Auszahlungsdauer und keine Verarbeitung von Auszahlungsdaten (keine payout-Endpunkte, keine Balance-Abfragen). Die App kennt nur den Status des PaymentIntent.  
Quelle: ["Suche nach 'payout', 'Auszahlung', 'balance' in php-ionos/app/stripe.php, php-ionos/app/collections.php, php-ionos/stripe-webhook.php und websites/*/index.html ohne Treffer", "php-ionos/app/stripe.php:333-398"]  
Öffentliche Formulierung: Keine Angaben wie 'Geld in X Tagen auf Ihrem Konto'. Höchstens: 'Auszahlung erfolgt durch Stripe nach dessen Bedingungen'.

**MAIL-01** (bestätigt): An Zahlungspflichtige gehen genau zwei Arten von E-Mails: (a) die Vorabankündigung 'Vorabankündigung SEPA-Lastschrift <Rechnungsnummer>' mit Rechnungsnummer, Betrag, Einzugsdatum, Zahlungsempfänger, Mandatsreferenz, Gläubiger-ID (falls hinterlegt) und dem Hinweis auf Stripe, nur bei aktiviertem Firmenschalter beim Terminieren und Umterminieren; (b) die Mandatsanforderung und deren Erinnerung (nur bei aktivem Feature-Schalter, siehe MAND-04). Beide nutzen mail_layout; Absender ist die Plattformadresse aus config('mail'), Fußband mit Pflichtangaben der Müller Holding AG; die Firma erscheint nur im Text und in der Fußnote als Zahlungsempfänger. Ohne mail.enabled wird nichts versendet.  
Quelle: ["php-ionos/app/collections.php:1183-1198", "php-ionos/app/mandate_requests.php:81-101,368-400", "php-ionos/app/mailer.php:20-24,164-177,369-381"]  
Öffentliche Formulierung: Zulässig: 'Vorabankündigung per E-Mail an Ihre Kunden (optional)'. Nicht behaupten: 'E-Mail im Namen Ihrer Firma' oder 'mit Ihrem Absender'; der Absender ist die Plattform.

**MAIL-02** (nicht_vorhanden): Keine E-Mails an Zahlungspflichtige bei erfolgreichem Einzug, fehlgeschlagener Lastschrift, Rücklastschrift oder Erstattung; keine Zahlungsbestätigung, keine Mahnung, keine Mandatsbestätigung nach Papiererfassung. Stripe-eigene E-Mails (z. B. Mandatsbestätigung durch Stripe bei Checkout) werden vom Code weder ausgelöst noch unterdrückt und sind aus dem Repository nicht beurteilbar.  
Quelle: ["Alle mail_send-Aufrufe mit Kundenadresse: php-ionos/app/collections.php:1197 und php-ionos/app/mandate_requests.php:100; keine weiteren Treffer in php-ionos/app/*.php und php-ionos/*.php", "php-ionos/app/alerts.php:477-526 (nur Inhaber)"]  
Öffentliche Formulierung: Nicht behaupten: 'Ihre Kunden werden über jeden Einzug informiert'.

**MAIL-03** (bestätigt): Der Inhaber erhält höchstens einmal je Kalendertag eine Hinweismail bei Alarmen der Stufe 'hoch' (Synchronisation älter als 48 Stunden, unklare Einzugsversuche) sowie Sicherheitsmails bei Änderungen der Stripe- oder Lexware-Verbindung. Alarme der Stufe 'mittel' (fehlendes Webhook-Secret, Not-Stopp, überfällige Termine, Rechnungen mit Klärungsbedarf) werden nur angezeigt.  
Quelle: ["php-ionos/app/alerts.php:1-9,329-411,477-526", "php-ionos/settings.php:20-25,58,103,133"]  
Öffentliche Formulierung: Zulässig: 'Hinweis per E-Mail bei unklaren Einzugsversuchen und ausgefallener Synchronisation'.

**SETUP-01** (bestätigt): setup-check.php prüft für den Mandatsbereich nur, ob der Ablageordner app/storage/mandates beschreibbar ist und das Upload-Limit mindestens 10 MB beträgt; Stripe-spezifisch wird nur das PHP-Modul curl geprüft. Es findet keine Prüfung des Stripe-Kontos oder des Webhooks statt.  
Quelle: ["php-ionos/setup-check.php:63-66,252-257"]  
Öffentliche Formulierung: Keine Marketingrelevanz.


## Bereich sync-einzug

49 Einträge.

**SYNC-01** (bestätigt): Die Anwendung ruft aus Lexware Office ausschließlich über HTTP GET folgende Endpunkte der Public API ab: /profile (Verbindungstest), /voucherlist (Rechnungsliste, voucherType=invoice), /invoices/{id} (Rechnungsdetail), /contacts/{id} (Kontakt) und /payments/{voucherId} (Zahlungsstand). Basis-URL https://api.lexware.io/v1, Ausweichadresse https://api.lexoffice.io/v1 bei Verbindungsfehlern.  
Quelle: php-ionos/app/lexoffice.php:28-29, 80-185, 187-266; php-ionos/app/config.example.php:385-389  
Öffentliche Formulierung: Zulässig: 'liest offene Rechnungen, Kunden und Zahlungsstände aus Lexware Office über die Public API'. Falsch wäre: 'bidirektionale Schnittstelle', 'bucht in Lexware Office'.

**SYNC-02** (bestätigt): Aus der Rechnungsliste werden id, voucherNumber, voucherStatus und updatedDate übernommen; aus dem Rechnungsdetail voucherNumber, voucherStatus, updatedDate, address.contactId, address.name bzw. address.supplement, totalPrice.totalGrossAmount, totalPrice.currency, dueDate und lineItems (als JSON gespeichert, daraus wird ein Stichwort für den Verwendungszweck gebildet).  
Quelle: php-ionos/app/sync.php:167-175, 453-477, 495-519, 609-619; php-ionos/app/invoice_source.php:39-49; php-ionos/app/keywords.php:91-102  
Öffentliche Formulierung: Zulässig: 'übernimmt Rechnungsnummer, Bruttobetrag, Fälligkeit, Status, Kundenbezug und Positionen'. Nicht behaupten: Nettobeträge, Steuerdaten, Dokumente/PDFs oder Zahlungsbedingungen würden übernommen (nicht im Code).

**SYNC-03** (bestätigt): Aus dem Lexware-Kontakt werden roles.customer.number (Kundennummer), company.name oder person.firstName/lastName (Name) und die erste E-Mail-Adresse aus emailAddresses (Reihenfolge business, office, private, other) übernommen. Kontakte mit Kundennummer 10001 gelten als Laufkunden (is_walk_in). Bekannte Kunden werden höchstens alle 24 Stunden neu geladen (sync.contact_refresh_hours).  
Quelle: php-ionos/app/sync.php:532-607, 621-640; php-ionos/app/config.example.php:488-494  
Öffentliche Formulierung: Zulässig: 'Kundenname, Kundennummer und E-Mail-Adresse werden aus Lexware Office übernommen'. Nicht behaupten: Anschriften, Telefonnummern oder Bankverbindungen würden aus Lexware Office gelesen (IBANs werden in der Anwendung erfasst).

**SYNC-04** (bestätigt): Der Zahlungsstand einer Rechnung (GET /payments/{voucherId}) wird ausschließlich unmittelbar vor einem Einzug abgerufen, nicht während der Synchronisation. Normalisiert werden openAmount, currency, paymentStatus, voucherStatus und paidDate. Fehlt ein numerischer openAmount, gilt der Restbetrag als nicht ermittelbar und es wird nicht eingezogen. Die Feldnamen konnten laut Code-Kommentar beim Bau nicht gegen die Online-Dokumentation verifiziert werden.  
Quelle: php-ionos/app/lexoffice.php:239-266; php-ionos/app/collections.php:330-365; docs/payment-safety.md:25-36, 133  
Öffentliche Formulierung: Zulässig: 'vor jeder Lastschrift wird der offene Restbetrag live bei Lexware Office abgefragt'. Falsch wäre: 'die Synchronisation hält Restbeträge laufend aktuell' (der gespeicherte Restbetrag stammt nur aus Einzugsversuchen).

**SYNC-05** (nicht_vorhanden): Es wird nichts nach Lexware Office zurückgeschrieben: keine Zahlungen, keine Notizen, keine Statusänderungen. Der HTTP-Client kennt nur GET-Aufrufe (kein CURLOPT_POST, kein CURLOPT_CUSTOMREQUEST), und alle öffentlichen Methoden sind Leseabrufe.  
Quelle: php-ionos/app/lexoffice.php:80-185 (nur Authorization- und Accept-Header, keine Schreiboption), 187-266; docs/payment-safety.md:36  
Öffentliche Formulierung: Zulässig: 'reiner Lesezugriff, Ihre Buchhaltung bleibt unverändert', 'Zahlungseingänge buchen Sie wie bisher in Lexware Office'. Falsch wäre jede Aussage über automatische Zahlungszuordnung, Verbuchung oder Rückmeldung an Lexware Office.

**SYNC-06** (bestätigt): Die Rechnungsliste wird mit voucherType=invoice und voucherStatus=open sowie voucherStatus=overdue abgerufen, je 100 Einträge je Seite über alle Seiten. Es gibt keinen Datumsfilter, keinen Betragsfilter und kein Mengenlimit; alle in Lexware Office offenen oder überfälligen Rechnungen werden übernommen, auch Altbestand vor der Registrierung.  
Quelle: php-ionos/app/lexoffice.php:193-227; php-ionos/app/sync.php:146-197; docs/payment-safety.md:101-105  
Öffentliche Formulierung: Zulässig: 'alle offenen und überfälligen Rechnungen aus Lexware Office'. Falsch wäre: 'nur Rechnungen ab Datum X' oder 'frei konfigurierbare Filter'. Entwürfe, bezahlte oder stornierte Rechnungen werden nicht importiert.

**SYNC-07** (bestätigt): Rechnungen, die lokal als offen/überfällig geführt werden, aber in der aktuellen Lexware-Liste fehlen, erhalten sofort den Sammelstatus not_open und werden anschließend einzeln per Detailabruf geprüft: voucherStatus paid setzt collection_status auf collected, voided oder cancelled auf none. Laufende Stripe-Vorgänge (in_collection, scheduled) werden dabei nicht angetastet.  
Quelle: php-ionos/app/sync.php:220-323, 479-483; php-ionos/app/layout.php:267-279  
Öffentliche Formulierung: Zulässig: 'in Lexware Office bezahlte oder stornierte Rechnungen werden bei der nächsten Synchronisation erkannt und nicht mehr eingezogen'. Nicht behaupten: 'in Echtzeit' (nur beim Lauf, es gibt keine Lexware-Webhooks, docs/sync-performance.md:200).

**SYNC-08** (bestätigt): Rechnungen mit unverändertem updatedDate und gleichem Status werden ohne Detailabruf übersprungen (sync.skip_unchanged, Standard an). Der nächtliche Vollabgleich (force_full) schaltet diese Erkennung für den Lauf ab.  
Quelle: php-ionos/app/sync.php:40-50, 114-117, 431-451; php-ionos/app/jobs.php:109-112; docs/sync-performance.md:169-175  
Öffentliche Formulierung: Zulässig: 'schonende Synchronisation, unveränderte Rechnungen werden nicht erneut geladen'. Keine Zahlen zu Geschwindigkeit nennen, die Messwerte in docs/sync-performance.md:177-187 stammen aus einer Fake-Quelle ohne Netz.

**SYNC-09** (bestätigt): Die Synchronisation läuft in Schritten mit Zeitbudget: Standard 8 Sekunden je Schritt, höchstens 40 Detailabrufe und 60 Lexware-Aufrufe je Schritt; der Cursor liegt serverseitig in sync_state, Sperre je Firma 180 Sekunden, ein Lauf ohne Fortschritt gilt nach 30 Minuten als abgebrochen. Doppelstarts werden abgewiesen und gezählt.  
Quelle: php-ionos/app/sync.php:40-50, 94-141; php-ionos/app/sync_state.php:26-27, 60-87, 133-219; php-ionos/app/config.example.php:488-494  
Öffentliche Formulierung: Zulässig: 'läuft im Hintergrund weiter, der Browser muss nicht geöffnet bleiben'. Interne Grenzwerte gehören nicht in Marketingtexte.

**SYNC-10** (bestätigt): Der Client hält 0,6 Sekunden Abstand zwischen Aufrufen (unter 2 Anfragen je Sekunde), wiederholt bei HTTP 429 bis zu dreimal und bei 500/502/503 bis zu zweimal mit Retry-After (1 bis 30 Sekunden) oder exponentiellem Backoff plus Zufallsanteil, bricht bei 401 sofort ab und weicht bei Verbindungsfehlern einmal auf die andere API-Domain aus. Zusätzlich greift api_call_gate (Circuit Breaker, optionale Redis-Ratenbegrenzung je API-Schlüssel und global). Der Grenzwert von 2 Anfragen je Sekunde ist laut Code-Kommentar eine unverifizierte Annahme.  
Quelle: php-ionos/app/lexoffice.php:12-14, 30, 59-78, 87-94, 123-183; php-ionos/app/config.example.php:452-453; docs/sync-performance.md:208  
Öffentliche Formulierung: Zulässig: 'beachtet die Lastgrenzen der Lexware-Schnittstelle'. Nicht behaupten: konkrete Lexware-Limits (nicht verifiziert).

**SYNC-11** (bestätigt): Ohne aktives Feature features.queue startet eine Synchronisation nur manuell über den Button 'Mit Lexware Office synchronisieren' (invoices.php) oder den Onboarding-Schritt; Browser und cron.php setzen einen laufenden Lauf schrittweise fort (Round-Robin über Firmen im Zeitbudget). Ein automatischer Start ohne Nutzeraktion existiert in diesem Betriebsmodus nicht. Empfohlenes Cron-Intervall laut Datei-Kommentar: alle 5 Minuten.  
Quelle: php-ionos/invoices.php:21-47, 49-71, 171-179; php-ionos/cron.php:3-16, 130-142; php-ionos/app/sync_state.php:256-315  
Öffentliche Formulierung: Auf dem Webhosting darf nicht 'automatische Synchronisation' versprochen werden, nur 'Fortsetzung im Hintergrund'.

**SYNC-12** (bestätigt): Bei aktivem Feature features.queue reiht der Scheduler (Prüfintervall 30 Sekunden) je verbundener Firma mit abgeschlossenem Onboarding automatisch einen Delta-Abgleich ein, sobald der letzte Lauf älter als queue.auto_sync_hours (Standard 6 Stunden) ist, und täglich zur Stunde queue.full_sync_hour (Standard 3 Uhr, lokale Zeit) einen Vollabgleich mit Priorität low. Firmen mit sync_paused werden übersprungen; verwaiste Läufe werden geschlossen und sofort fortgesetzt.  
Quelle: php-ionos/app/jobs.php:37-48, 323-355, 357-419; php-ionos/bin/scheduler.php:3-17; docs/queue-worker.md:246  
Öffentliche Formulierung: Zulässig nur, wenn features.queue in Produktion aktiv ist: 'automatischer Abgleich mehrmals täglich, nächtlicher Vollabgleich'. Konkrete Intervalle nur als Standardwerte formulieren ('in der Regel alle sechs Stunden'), da konfigurierbar.

**SYNC-13** (bestätigt): Nutzer sehen den laufenden Fortschritt (Prozent, Phase, verarbeitete Rechnungen; bei Warteschlange Polling über sync-status.php alle 3 Sekunden), die Abschlussmeldung mit Kennzahlen, das Datum der letzten Synchronisation auf Dashboard und Rechnungsseite sowie die Historie aller Läufe (Status, Dauer, Auslöser, Benutzer, Mengen, API-Aufrufe, Fehlerkategorie) unter Synchronisationen.  
Quelle: php-ionos/invoices.php:123-184, 237; php-ionos/sync-status.php:1-23; php-ionos/synchronisationen.php:1-106; php-ionos/dashboard.php:65-81; php-ionos/app/sync_state.php:321-362, 461-523  
Öffentliche Formulierung: Zulässig: 'nachvollziehbare Historie jeder Synchronisation'.

**SYNC-14** (bestätigt): Die Seite 'Mit Lexware Office abgleichen' (reconcile.php) vergleicht nur Rechnungsnummern und Status der Lexware-Liste mit dem lokalen Bestand und listet Abweichungen in beide Richtungen; Beträge werden dabei ausdrücklich nicht geprüft.  
Quelle: php-ionos/reconcile.php:1-6, 22-48, 51-80, 85-87  
Öffentliche Formulierung: Zulässig: 'Abgleichsansicht zeigt Abweichungen zwischen Lexware Office und Portal'. Nicht behaupten, der Abgleich prüfe Beträge.

**SYNC-15** (bestätigt): Liegt bei verbundenem Lexware Office die letzte Synchronisation mehr als 48 Stunden zurück oder fehlt ganz, entsteht ein Alarm der Stufe hoch; Alarme der Stufe hoch werden höchstens einmal je Kalendertag per E-Mail an den aktiven Inhaber gesendet, sofern der Mailversand aktiv ist (mail.enabled).  
Quelle: php-ionos/app/alerts.php:21, 33-54, 168-225; php-ionos/cron.php:122-128; php-ionos/app/jobs.php:58, 335  
Öffentliche Formulierung: Zulässig: 'Hinweis-E-Mail, wenn die Synchronisation ausbleibt'. Nur mit aktivem Mailversand behaupten.

**SYNC-16** (bestätigt): Voraussetzung ist ein API-Schlüssel der Lexware Office Public API, der beim Speichern über GET /profile getestet, verschlüsselt abgelegt und danach nicht mehr angezeigt wird. Der Code prüft keinen Lexware-Tarif; die Oberfläche nennt lediglich den Hinweis 'Nach Angaben von Lexware setzt die Public API derzeit den Tarif Lexware Office XL voraus' mit dem Zusatz, dass sich Tarifbedingungen ändern können.  
Quelle: php-ionos/settings.php:204-206, 231-248; php-ionos/app/lexoffice.php:187-191; php-ionos/app/invoice_source.php:151-158; php-ionos/onboarding.php:47  
Öffentliche Formulierung: Tarifaussagen nur vorsichtig: 'nach Angaben von Lexware ist die Public API an bestimmte Tarife gebunden, bitte prüfen Sie Ihren Tarif'. Falsch wäre: 'funktioniert mit jedem Lexware-Tarif' oder eine feste Tarifzusage.

**SYNC-17** (ungeklärt): Ob und welcher Lexware-Tarif die Public API voraussetzt, ist aus dem Code nicht entscheidbar; die Anwendung verlässt sich allein darauf, ob der API-Schlüssel funktioniert.  
Quelle: php-ionos/settings.php:240-241 (Hinweistext, keine Prüfung)  
Öffentliche Formulierung: Keine eigene Tarifaussage treffen, nur auf Lexware verweisen.

**SYNC-18** (bestätigt): Rechnungsdaten laufen über das Interface InvoiceSource; heute existiert nur die Implementierung LexwareOfficeSource. sevdesk ist als Code registriert, aber nicht freigegeben (Schalter sevdesk_connect serverseitig); der Wechsel des Buchhaltungssystems ist nur Inhabern und Administratoren möglich, trennt die alte Verbindung (Schlüssel gelöscht), ist bei offenen Einzügen oder laufender Synchronisation gesperrt und danach 28 Tage gesperrt.  
Quelle: php-ionos/app/invoice_source.php:28-56, 59-114, 125-159; php-ionos/app/invoice_source_switch.php:20-21, 45-55, 87-117, 123-151  
Öffentliche Formulierung: Zulässig: 'genau ein Buchhaltungssystem je Firmenaccount'. sevdesk nur als 'in Vorbereitung' formulieren, keine Funktionszusage.

**SYNC-19** (bestätigt): Ändert sich eine Rechnung in Lexware Office (updatedDate neu), werden Rechnungsnummer, Kunde, Bruttobetrag, Währung, Fälligkeit, Status und Positionen im Portal überschrieben; das Stichwort für den Verwendungszweck wird nur bei geänderten Positionen neu berechnet. Beim Einzug blockiert ein Restbetrag laut Lexware, der höher als der im Portal gespeicherte Rechnungsbetrag ist, mit der Aufforderung zuerst zu synchronisieren.  
Quelle: php-ionos/app/sync.php:479-506; php-ionos/app/collections.php:445-451  
Öffentliche Formulierung: Zulässig: 'Änderungen an Rechnungen werden beim nächsten Abgleich übernommen'.

**SYNC-20** (bestätigt): Der Betreiber kann die Synchronisation einer Firma pausieren (organizations.sync_paused); der Button meldet dann eine Wartung, der Scheduler überspringt die Firma und ein Job wird als abgebrochen beendet. Das Einreichfenster für Einzüge begrenzt die Synchronisation nicht.  
Quelle: php-ionos/invoices.php:23-32; php-ionos/app/jobs.php:81-90, 377-379; php-ionos/app/collections.php:177-192 (Fenster nur in collections.php)  
Öffentliche Formulierung: Interne Betreiberfunktion, nicht für Marketing.

**EINZUG-01** (bestätigt): Ein Einzug entsteht ausschließlich durch eine Nutzeraktion: je Rechnung 'Einziehen' bzw. 'Einzug vormerken' oder 'Terminieren' mit Datum auf der Rechnungsseite, oder als Sammel-Einzug 'Alle bereiten Einzüge vormerken/einreichen' auf der Einzugsseite. Die Synchronisation löst niemals einen Einzug aus.  
Quelle: php-ionos/invoices.php:77-96, 343-379; php-ionos/collections.php:67-76, 213-225; php-ionos/app/collections.php:1208-1433, 1611-1661; docs/payment-safety.md:105  
Öffentliche Formulierung: Zulässig: 'Sie entscheiden je Rechnung oder gesammelt, was eingezogen wird'. Falsch wäre: 'vollautomatischer Einzug aller offenen Rechnungen'.

**EINZUG-02** (geplant): Eine regelbasierte automatische Freigabe (Tabelle collection_rules mit Kundenkreis, Höchstbetrag, Höchstzahl je Lauf, Vier-Augen-Freigabe) existiert nur als Gerüst mit einer Vorschaufunktion; es gibt keine Verarbeitung, keinen Cron-Anschluss und keine Oberfläche. Die Vorschau wird von keiner Seite aufgerufen.  
Quelle: php-ionos/app/collection_rules.php:1-13, 100-116 (Hinweistext 'werden derzeit nicht automatisch verarbeitet'); docs/payment-safety.md:123-125; Aufrufsuche: nur php-ionos/setup-check.php:190 (Tabellenprüfung)  
Öffentliche Formulierung: Nicht bewerben. Höchstens als 'geplant' ohne Termin, wenn der Betreiber das freigibt.

**EINZUG-03** (bestätigt): Sofort-Einzüge werden nicht direkt an Stripe übergeben, sondern als vorgemerkt gespeichert (queued_immediate) und frühestens nach der Karenzzeit (collections.grace_hours, Standard 4 Stunden) im Einreichfenster (collections.window_start bis window_end, Standard 23:00 bis 06:00 Uhr, konfigurierbar, Fenster darf über Mitternacht gehen) eingereicht. Bis zur Einreichung ist jeder Einzug stornierbar. Bei window_enabled=false und grace_hours=0 würde sofort eingereicht.  
Quelle: php-ionos/app/collections.php:139-236, 1316-1337, 1363-1372; php-ionos/app/config.example.php:409-422; docs/payment-safety.md:143-149  
Öffentliche Formulierung: Zulässig: 'Einreichung gebündelt nachts, bis dahin stornierbar' mit Standardwerten. Die Uhrzeiten sind Plattformeinstellungen, in Texten als 'in der Regel' formulieren.

**EINZUG-04** (bestätigt): Ein terminierter Einzug braucht ein Datum ab morgen, höchstens 365 Tage voraus, nur Montag bis Freitag; bei aktiver Vorabankündigung mindestens pre_notification_days Tage Vorlauf. Bank- und Feiertage werden nicht geprüft. Der terminierte Einzug wird am Termin nur im Einreichfenster eingereicht; die Vormerkung eines Sofort-Einzugs wandelt sich beim Umterminieren in einen regulären Termin.  
Quelle: php-ionos/app/collections.php:996-1021, 1265-1266, 1471-1536; php-ionos/invoices.php:220-226  
Öffentliche Formulierung: Zulässig: 'Einzüge auf einen Werktag terminieren'. Nicht behaupten: 'berücksichtigt Feiertage' oder 'Ausführung genau am Fälligkeitstag' (Einreichung bei Stripe, Belastung beim Kunden richtet sich nach Stripe und Bank).

**EINZUG-05** (bestätigt): Terminierte oder vorgemerkte Einzüge, deren Termin länger als collections.overdue_days (Standard 3 Tage) zurückliegt, werden nicht automatisch nachgeholt, sondern als überfällig angezeigt und müssen neu terminiert oder storniert werden (zum Beispiel nach einem Not-Stopp).  
Quelle: php-ionos/app/collections.php:160, 266-274, 1717-1727; php-ionos/collections.php:145-149; php-ionos/notstopp.php:127  
Öffentliche Formulierung: Zulässig: 'keine Lastschrift mit veralteter Ankündigung, überfällige Termine werden nicht still nachgeholt'.

**EINZUG-06** (bestätigt): Die Einreichung fälliger Einzüge außerhalb des Einreichfensters ist nur Inhabern und Administratoren mit aktuellem 2FA-Code möglich und wird mit collections_due_forced protokolliert; die Karenzzeit muss dabei abgelaufen sein. Im Support-Modus des Betreibers ist jede Einreichung gesperrt.  
Quelle: php-ionos/collections.php:28-38, 189-202; php-ionos/app/collections.php:1211-1216, 1742-1747  
Öffentliche Formulierung: Zulässig: 'Ausnahmen nur mit Zweitbestätigung'.

**EINZUG-07** (bestätigt): Vor jedem Einreichen laufen in dieser Reihenfolge: Not-Stopp (Plattform und Firma), Vorabankündigungsregel, Tarifkontingent, Rechnung offen oder überfällig laut Lexware, nicht bereits im Einzug, kein Klärungsbedarf (requires_review), Kunde mit aktivem SEPA-Einzug, aktive IBAN, kein manuell zu erneuerndes Mandat, Mandat verwendbar, Vorprüfung des gespeicherten Restbetrags, Stripe-Verbindung, Live-Restbetrag bei Lexware Office, Versuchsjournal mit Idempotenzschlüssel, dann der Stripe-Aufruf. Beim Vormerken werden Stripe-Verbindung, offene Versuche und gespeicherter Restbetrag geprüft; der Live-Restbetrag folgt bei der Einreichung.  
Quelle: php-ionos/app/collections.php:10-19, 1039-1089, 1252-1433; docs/payment-safety.md:7-23  
Öffentliche Formulierung: Zulässig: 'mehrstufige Sicherheitsprüfung vor jeder Lastschrift'. Konkrete Aufzählung nur mit den hier belegten Punkten.

**EINZUG-08** (bestätigt): Beim Sofort-Einzug wird der Betrag aus dem Live-Restbetrag laut Lexware Office abzüglich eigener laufender, terminierter oder erfolgreicher Einzüge dieser Rechnung bestimmt. Restbetrag 0 oder negativ: keine Einreichung ('vollständig bezahlt'). Restbetrag abzüglich eigener Einzüge 0: keine Einreichung. Ergebnis größer als der Portalbetrag: keine Einreichung, erst synchronisieren. Ergebnis kleiner als der Rechnungsbetrag (Teilzahlung): Einreichung nur mit ausdrücklicher Bestätigung genau dieses Betrags (confirm_amount_cents), der Einzug erhält einen Vermerk. Andere Währung als EUR: keine Einreichung. Ein frischer gespeicherter Restbetrag 0 (jünger als 24 Stunden) blockiert bereits ohne API-Aufruf.  
Quelle: php-ionos/app/collections.php:55, 314-466, 1304-1305, 1339-1361, 1395-1401; php-ionos/invoices.php:273-275, 343-365  
Öffentliche Formulierung: Zulässig: 'Teilzahlungen werden erkannt, eingezogen wird nur der bestätigte Restbetrag; bezahlte Rechnungen werden nie eingezogen'. Nicht behaupten: 'automatischer Restbetragseinzug ohne Rückfrage' beim Sofort-Einzug.

**EINZUG-09** (bestätigt): Bei der Einreichung eines terminierten oder vorgemerkten Einzugs wird der Restbetrag erneut live geprüft: nicht abrufbar bedeutet zurückstellen (bleibt terminiert, Vermerk, nächster Lauf prüft erneut); bezahlt oder durch eigene Einzüge gedeckt bedeutet fehlgeschlagen ohne Stripe-Aufruf; Teilzahlung seit Terminierung bedeutet Einzug nur des Restbetrags ohne erneute Bestätigung (weniger als angekündigt ist zulässig), mit Vermerk am Einzug. Außerdem wird immer die aktuell aktive IBAN des Kunden verwendet; wurde sie ersetzt, wird das Mandat zur neuen IBAN geprüft.  
Quelle: php-ionos/app/collections.php:1826-1944; docs/payment-safety.md:35  
Öffentliche Formulierung: Zulässig: 'auch am Fälligkeitstag wird der Restbetrag noch einmal geprüft'.

**EINZUG-10** (bestätigt): Ein Einzug setzt ein aktives Mandat mit IBAN voraus. Mandate, die 36 Monate nicht genutzt wurden (Anker: letzte Nutzung, sonst Unterschrift bzw. Mandatsdatum), werden beim Prüfen als verfallen markiert und blockieren. Ist die Firmeneinstellung 'Handschriftlicher Nachweis erforderlich' aktiv (Standard 1), blockiert ein Mandat ohne erfasste Unterschrift. Nach Widerruf oder Verfall wird kein Mandat still neu angelegt; es muss manuell in den Kundendetails erfasst werden.  
Quelle: php-ionos/app/mandates.php:15-16, 36, 196-212, 290-317; php-ionos/app/collections.php:1282-1293, 1863-1871; php-ionos/team.php:462; php-ionos/sql/schema.sql:69  
Öffentliche Formulierung: Zulässig: 'Mandatsverwaltung mit Verfallsprüfung und optionalem Unterschriftsnachweis'. Die 36-Monats-Regel steht im Code als 'Stand der Recherche, vor Produktivstart durch Rechtsberatung verifizieren' (mandates.php:5-6); nicht als Rechtsauskunft formulieren.

**EINZUG-11** (bestätigt): Vor jedem Stripe-Aufruf wird ein Versuch in collection_attempts mit Schlüssel sha256(Firma\|Rechnung\|Betrag\|Versuchsnummer) über eine eigene Autocommit-Verbindung gespeichert; der Schlüssel geht als Idempotency-Key und als Metadatum attempt_key an Stripe. Solange zu einer Rechnung ein Versuch pending, unknown oder erfolgreich ohne Einzugsdatensatz (verwaist) ist, wird kein weiterer Versuch angelegt. Zusätzlich sperrt die Firmenzeile (FOR UPDATE) parallele Anfragen, und terminierte Einzüge werden atomar auf submitting gesetzt.  
Quelle: php-ionos/app/collections.php:468-627, 1217-1227, 1835-1848; php-ionos/app/stripe.php:283-318; docs/payment-safety.md:38-47  
Öffentliche Formulierung: Zulässig: 'Schutz vor Doppelbelastung durch Idempotenzschlüssel und Versuchsjournal'.

**EINZUG-12** (bestätigt): Antwortet Stripe nicht (Zeitüberschreitung, Netzwerkfehler), wird der Versuch als unbekannt vermerkt und die Rechnung bis zur Klärung nicht erneut eingereicht. Die Klärung (Button 'Unklare Versuche prüfen', im Cron je Lauf, mit Warteschlange alle 10 Minuten) sucht per Stripe Search API nach dem attempt_key (nur Lesezugriff), trägt gefundene Einzüge nach oder gibt Versuche älter als 10 Minuten ohne PaymentIntent frei. Auch der Stripe-Webhook trägt PaymentIntents mit attempt_key ohne Einzugsdatensatz nach.  
Quelle: php-ionos/app/collections.php:51-52, 583-627, 703-794, 1784-1791; php-ionos/cron.php:100-111; php-ionos/app/jobs.php:212-243, 333; php-ionos/stripe-webhook.php:220-232; php-ionos/collections.php:150-177  
Öffentliche Formulierung: Zulässig: 'im Zweifel wird nicht eingereicht, unklare Fälle werden geklärt statt wiederholt'.

**EINZUG-13** (bestätigt): Ein Not-Stopp existiert je Firma (Inhaber und Administratoren, Aktivierung ohne Zweitbestätigung, Aufheben nur mit Bestätigungshäkchen und aktuellem 2FA-Code, Audit mit Grund) und plattformweit (platform_settings.collections_paused, nur Betreiber). Er verhindert Sofort-Einzug, Sammel-Einzug und die Einreichung fälliger Einzüge per Button und Cron; beim Aktivieren können alle vorgemerkten und terminierten Einzüge gesammelt storniert werden. Bereits eingereichte Lastschriften werden nicht gestoppt; Synchronisation, Statusabgleich und Webhooks laufen weiter.  
Quelle: php-ionos/app/collections.php:79-136, 276-308, 1256-1259, 1613-1615, 1729-1747, 1831-1833; php-ionos/notstopp.php:1-8, 21-51, 122-132  
Öffentliche Formulierung: Zulässig: 'Not-Stopp hält alle Einreichungen der Firma sofort an'. Nicht behaupten, bereits eingereichte Lastschriften ließen sich zurückholen.

**EINZUG-14** (nicht_vorhanden): Es gibt keine konfigurierbaren Höchst- oder Mindestbeträge je Einzug oder je Lauf. Begrenzt wird nur die Anzahl der Einzüge je Abrechnungsperiode über das Tarifkontingent (plans.max_collections_per_period, null = unbegrenzt), stornierte Einzüge zählen nicht; ab 80 Prozent Auslastung erscheint ein Hinweis mit Upsell. Die Währung ist auf EUR beschränkt.  
Quelle: php-ionos/app/plans.php:175-213; php-ionos/app/collections.php:354-356, 1276-1279, 1388-1393; php-ionos/invoices.php:228-244; Höchstbetrag nur im Gerüst php-ionos/app/collection_rules.php:439-442  
Öffentliche Formulierung: Nicht behaupten: 'Betragslimits' oder 'Freigabegrenzen'. Kontingente nur als Tarifmerkmal aus der Tabelle plans nennen, keine Zahlen fest verdrahten.

**EINZUG-15** (bestätigt): Der Einzug wird als Stripe PaymentIntent mit payment_method_types sepa_debit, confirm=true, mandate_data offline, Betrag in Cent, Verwendungszweck (höchstens 140 Zeichen, Muster '<Firma> SEPA <Rechnungsnummer> KD <Kundennummer> - <Stichwort>') und Metadaten (tenant_id, invoice_id, mandate_reference, voucher_number, customer_number, attempt_key) über das Stripe-Konto der jeweiligen Firma angelegt. Kunde und SEPA-Zahlungsmethode werden bei Stripe angelegt bzw. wiederverwendet; ohne Kunden-E-Mail wird eine noreply-Platzhalteradresse an Stripe übergeben.  
Quelle: php-ionos/app/collections.php:57-66, 1023-1037, 1091-1176; php-ionos/app/stripe.php:283-323; php-ionos/app/keywords.php:151-169  
Öffentliche Formulierung: Zulässig: 'Einzug über das eigene Stripe-Konto der Firma, sprechender Verwendungszweck mit Rechnungs- und Kundennummer'. Auf dem Kontoauszug können Gläubiger-ID und Mandatsreferenz von Stripe erscheinen (mandates.php:23-27), nicht 'im Namen der Firma mit eigener Gläubiger-ID' behaupten.

**EINZUG-16** (bestätigt): Eine Vorabankündigung per E-Mail an den Zahlungspflichtigen versendet die Anwendung nur, wenn die Firma die Einstellung 'Vorabankündigung senden' aktiviert hat (organizations.send_pre_notification, Standard 0, Frist pre_notification_days Standard 14, Einstellung in team.php), der Mailversand der Plattform aktiv ist und der Kunde eine E-Mail-Adresse hat. Sie wird beim Terminieren und beim Umterminieren versendet (Absender ist die Anwendung über mail_layout, Inhalt: Rechnung, Betrag, Einzugsdatum, Zahlungsempfänger, Mandatsreferenz, Gläubiger-ID, Hinweis auf Stripe); scheitert der Versand, wird nicht terminiert. Bei aktiver Vorabankündigung ist der Sofort-Einzug gesperrt und es muss terminiert werden; der Termin muss mindestens pre_notification_days Tage voraus liegen. Für vorgemerkte Sofort-Einzüge (queued_immediate) wird keine Ankündigung versendet.  
Quelle: php-ionos/app/collections.php:1178-1198, 1262-1273, 1294-1302, 1376-1381, 1513-1531; php-ionos/team.php:98-120, 449-462; php-ionos/sql/schema.sql:67-68; php-ionos/collections.php:115-120, 226-228; php-ionos/invoices.php:276-279  
Öffentliche Formulierung: Zulässig: 'optionale Vorabankündigung per E-Mail an Ihre Kunden'. Falsch wäre: 'Pre-Notification erfolgt automatisch' ohne Hinweis auf die Einstellung, oder 'Stripe versendet die Vorabankündigung' (im Code versendet die Anwendung). Ohne aktive Einstellung liegt die Vorabankündigung beim Nutzer.

**EINZUG-17** (bestätigt): Der Einzugsstatus wird auf zwei Wegen zurückgemeldet: über den Stripe-Webhook der Firma (payment_intent.processing, payment_intent.succeeded, payment_intent.payment_failed, charge.dispute.created, charge.refunded, charge.refund.updated, Signaturprüfung mit dem Webhook-Secret der Firma) und über den Button 'Status mit Stripe abgleichen' (Lesezugriff auf laufende PaymentIntents). Rücklastschriften und Erstattungen erkennt ausschließlich der Webhook; ohne hinterlegtes Webhook-Secret entsteht ein Alarm der Stufe mittel.  
Quelle: php-ionos/stripe-webhook.php:1-17, 245-290; php-ionos/app/collections.php:1538-1602; php-ionos/collections.php:60-66, 205-211, 231-233; php-ionos/app/alerts.php:71-78  
Öffentliche Formulierung: Zulässig: 'Status jeder Lastschrift wird zurückgemeldet und angezeigt'. Nicht behaupten: 'Rücklastschriften werden immer erkannt' ohne Hinweis auf den einzurichtenden Webhook.

**EINZUG-18** (bestätigt): Einzüge tragen die Status scheduled (Terminiert bzw. Vorgemerkt), submitting (Wird eingereicht), processing (In Bearbeitung), succeeded (Erfolgreich), failed (Fehlgeschlagen), disputed (Rücklastschrift), refunded (Erstattet), cancelled (Storniert) sowie die Anzeige Überfällig. Rechnungen tragen collection_status open, none, scheduled, in_collection, collected, failed. Sichtbar sind sie auf der Einzugsseite (Filter je Status, Termin, Einreichzeitpunkt, Vermerk, Fehlergrund, auslösende Person), im Dashboard (Kennzahlen offene Rechnungen, im Einzug, vorgemerkt/terminiert, erfolgreich, fehlgeschlagen/Rücklastschriften, letzte acht Einzüge) und auf der Rechnungsseite.  
Quelle: php-ionos/app/layout.php:229-258, 267-279; php-ionos/collections.php:84-135, 234-315; php-ionos/dashboard.php:16-63, 108-178; php-ionos/invoices.php:317-324  
Öffentliche Formulierung: Zulässig: 'transparente Statusanzeige je Rechnung und Einzug mit auslösender Person'.

**EINZUG-19** (bestätigt): Bei charge.dispute.created wird der Einzug auf disputed und die Rechnung auf failed gesetzt (Audit collection_disputed). Ein erneuter Einzug ist nur manuell möglich und durchläuft alle Prüfungen; eine automatische Wiedervorlage oder ein automatischer Zweitversuch nach Rücklastschrift oder fehlgeschlagener Lastschrift existiert nicht.  
Quelle: php-ionos/stripe-webhook.php:276-287; php-ionos/app/collections.php:1587-1594, 1797-1805; docs/payment-safety.md:60  
Öffentliche Formulierung: Nicht behaupten: 'automatisches Mahnwesen' oder 'automatischer Neuversuch'.

**EINZUG-20** (bestätigt): Erstattungen aus Stripe (Voll- oder Teilerstattung, auch Rücknahme) werden am Einzug vermerkt (refunded_cents, refund_note, Status refunded bei Vollerstattung); die Rechnung erhält Klärungsbedarf (requires_review) und wird nicht automatisch erneut eingezogen. Die Klärung schließen nur Inhaber oder Administratoren ab (Audit invoice_review_cleared); danach ist die Rechnung wieder manuell einziehbar.  
Quelle: php-ionos/app/collections.php:796-888; php-ionos/invoices.php:98-103, 326-340; docs/payment-safety.md:63-78  
Öffentliche Formulierung: Zulässig: 'Erstattungen werden übernommen, betroffene Rechnungen kommen zur Klärung'.

**EINZUG-21** (bestätigt): Vorgemerkte und terminierte Einzüge können bis zur Einreichung storniert (Rechnung wieder offen) oder umterminiert werden; beide Aktionen sind atomar gegen einen parallel laufenden Einreichlauf. Bereits eingereichte Einzüge lassen sich nicht mehr stornieren.  
Quelle: php-ionos/app/collections.php:1435-1536; php-ionos/collections.php:19-27, 289-307  
Öffentliche Formulierung: Zulässig: 'bis zur Einreichung jederzeit stornierbar'.

**EINZUG-22** (bestätigt): Fällige Einzüge werden ohne Warteschlange von cron.php (empfohlen alle 5 Minuten, halbes Zeitbudget je Lauf, Rest im nächsten Lauf) und mit Warteschlange vom Job collections_due (Scheduler-Intervall 300 Sekunden, Zeitbudget queue.collections_seconds Standard 120 Sekunden, Fortsetzung ohne Fehlversuch) eingereicht; zusätzlich über den Button 'Fällige Einzüge jetzt einreichen' der eigenen Firma. Not-Stopp-Firmen werden protokolliert übersprungen, zurückgestellte Einzüge bleiben terminiert, unbekannte Ergebnisse bleiben im Status submitting bis zur Klärung.  
Quelle: php-ionos/cron.php:77-98; php-ionos/app/jobs.php:43, 176-210, 332; php-ionos/app/collections.php:1689-1824; php-ionos/collections.php:181-188  
Öffentliche Formulierung: Zulässig: 'automatische Einreichung fälliger Einzüge im Einreichfenster'.

**EINZUG-23** (bestätigt): Der Journal-Export (export.php, alle Rollen der Firma, GET, Login-Pflicht) liefert CSV UTF-8 mit BOM und Semikolon mit den Spalten Rechnungsnummer, Lexware-Rechnungs-ID, Kunde, Kundennummer, Einzugs-ID, Stripe PaymentIntent, Stripe Charge, Betrag EUR, Status, Eingereicht am, Erfolgreich am, Rücklastschrift am, Erstattet EUR, Erstattet am, Mandatsreferenz, Stripe-Mandatsreferenz, Herkunft, Restbetrag laut Lexware, Restbetrag abgerufen am, Ausgelöst von, Termin, Vermerk, Fehlergrund; optional mit Statusfilter, mit Formelschutz, jeder Export wird protokolliert (collections_exported). Zusätzlich exportiert der Inhaber das Protokoll (Audit) als CSV.  
Quelle: php-ionos/export.php:2-7, 17-43, 45-58, 76-78, 119-124, 296-334 (Zeilen der Datei); php-ionos/collections.php:204; docs/payment-safety.md:107-109  
Öffentliche Formulierung: Zulässig: 'Einzugsjournal als CSV für Buchhaltung und Steuerberater'. Nicht behaupten: 'DATEV-Export', 'Rückspielung nach Lexware Office' oder Excel-Format (nur CSV).

**EINZUG-24** (bestätigt): Jede geldrelevante Aktion wird mit Benutzer und Details in audit_log protokolliert (unter anderem collection_queued, collection_scheduled, collection_submitted, collection_cancelled, collection_rescheduled, collections_bulk, collections_due_processed, collections_paused, collections_resumed, collection_refunded, collection_disputed, collection_attempt_recovered, collection_attempt_cleared, collections_exported). Protokolleinträge werden nach 90 Tagen von der Wartung gelöscht; Einzüge, Versuche und Mandate bleiben in eigenen Tabellen erhalten.  
Quelle: php-ionos/app/collections.php:133-135, 303-306, 713-716, 770-773, 858-862, 1383-1387, 1424-1428, 1466-1468, 1532-1535, 1657-1659, 1819-1822, 1962-1967; php-ionos/stripe-webhook.php:285-287; php-ionos/cron.php:73; php-ionos/app/jobs.php:293; php-ionos/app/version.php (Eintrag 4.30, Protokoll 90 Tage)  
Öffentliche Formulierung: Zulässig: 'jede Einzugsaktion ist nachvollziehbar protokolliert'. Bei Aufbewahrung 90 Tage nennen, nicht 'revisionssichere Archivierung'.

**EINZUG-25** (geplant): Die digitale Mandatsanforderung (Link per E-Mail, Stripe Checkout im Modus setup, öffentliche Seite mandat.php, Erinnerungen) liegt hinter dem Feature-Schalter features.mandate_request (Standard false); ohne Schalter fehlt der Button, die Aktion wird serverseitig abgelehnt und mandat.php liefert 404.  
Quelle: php-ionos/app/config.example.php:496-501; php-ionos/app/jobs.php:275-282; php-ionos/cron.php:113-120; docs/payment-safety.md:111-121  
Öffentliche Formulierung: Nicht bewerben, solange der Schalter in Produktion nicht aktiv ist.

**EINZUG-26** (geplant): Die Option, die von Stripe erzeugte Mandatsreferenz mit dem Firmenpräfix beginnen zu lassen (stripe_mandate_reference_prefix), ist im Code vorhanden, aber standardmäßig deaktiviert und laut Kommentar erst nach erfolgreichem Test-Einzug zu aktivieren.  
Quelle: php-ionos/app/config.example.php:391-395; php-ionos/app/collections.php:1146-1152; php-ionos/app/stripe.php:309-323  
Öffentliche Formulierung: Nicht bewerben.

**EINZUG-27** (bestätigt): Der SEPA-Einzug kann je Kunde deaktiviert werden (customers.sepa_debit_enabled); deaktivierte Kunden werden von Einzug, Sammel-Einzug und Bereitschaftszählung ausgeschlossen, ebenso Laufkunden (is_walk_in) beim Umschalten. Kunden ohne aktive IBAN können nicht eingezogen werden; das Dashboard listet Kunden mit SEPA-Einzug ohne IBAN und Mandate ohne Unterschrift als offene Aufgaben.  
Quelle: php-ionos/invoices.php:105-112, 293-303, 385-400; php-ionos/app/collections.php:1075-1086, 1617-1628; php-ionos/dashboard.php:40-51, 135-147  
Öffentliche Formulierung: Zulässig: 'SEPA-Einzug je Kunde ein- oder ausschalten'.

**EINZUG-28** (bestätigt): Lastschriften laufen über das Stripe-Konto der jeweiligen Firma (integrations.stripe_secret_key_encrypted, verschlüsselt), nicht über ein Konto der Plattform; die Plattform-Abrechnung (Abonnement) nutzt ein getrenntes Stripe-Konto der Müller Holding AG. Ohne verbundenes Stripe-Konto wird weder vorgemerkt noch eingereicht.  
Quelle: php-ionos/app/collections.php:1023-1037, 1321-1322, 1395-1396; php-ionos/app/config.example.php:397-407  
Öffentliche Formulierung: Zulässig: 'Ihr eigenes Stripe-Konto, Gelder fließen direkt an Sie'. Nicht behaupten, die Plattform sei Zahlungsdienstleister oder halte Kundengelder.

**EINZUG-29** (bestätigt): Das Dashboard zeigt Alarme: Synchronisation älter 48 Stunden (hoch), unklare Einzugsversuche (hoch), Not-Stopp (mittel), Stripe ohne Webhook-Secret (mittel), terminierte Einzüge mit Fälligkeit in der Vergangenheit (mittel), Rechnungen mit Klärungsbedarf (mittel).  
Quelle: php-ionos/app/alerts.php:28-110; php-ionos/dashboard.php:72, 83-106  
Öffentliche Formulierung: Zulässig: 'Hinweise im Dashboard zu offenen Vorgängen'.


## Bereich tarife-registrierung-sevdesk

48 Einträge.

**TARIF-01** (bestätigt): Die Startdaten der Tabelle plans enthalten fünf Tarife: unlimited_start (UNLIMITED START, 25,00 EUR netto, 28 Tage, Einzüge unbegrenzt, Benutzer unbegrenzt, Einladungen erlaubt, aktiv, öffentlich, Sortierung 10); basic (BASIC, 20,00 EUR, 28 Tage, 20 Einzüge, 1 Benutzer, keine Einladungen, inaktiv, nicht öffentlich); plus (PLUS, 35,00 EUR, 28 Tage, 50 Einzüge, 2 Benutzer, inaktiv, nicht öffentlich); pro (PRO, 50,00 EUR, 28 Tage, 100 Einzüge, Benutzer unbegrenzt, inaktiv, nicht öffentlich); unlimited (UNLIMITED, 100,00 EUR, 28 Tage, Einzüge und Benutzer unbegrenzt, inaktiv, nicht öffentlich).  
Quelle: ["php-ionos/sql/schema.sql:13-37", "php-ionos/sql/migrations/003_saas_2fa_roles_plans.sql:79-103"]  
Öffentliche Formulierung: Öffentlich darf nur UNLIMITED START genannt werden. BASIC, PLUS, PRO und UNLIMITED sind inaktiv und nicht öffentlich; sie dürfen weder als verfügbar noch als geplant beworben werden. Der Datensatz PRO zu 50,00 EUR ist kein Beleg für einen früheren Preis von UNLIMITED START.

**TARIF-02** (bestätigt): Buchbar oder wählbar sind ausschließlich Tarife mit active = 1 und public_visible = 1 (plans_public(), billing_choose_plan(), billing_change_plan()). Ein unbekannter Tarifcode fällt auf unlimited_start zurück; fehlt auch dieser in der Datenbank, greift ein im Code hinterlegter Notfalldatensatz (UNLIMITED START, 2500 Cent, 28 Tage, unbegrenzt).  
Quelle: ["php-ionos/app/plans.php:19-43", "php-ionos/app/plans.php:216-219", "php-ionos/app/billing.php:52-54", "php-ionos/app/billing.php:113-115", "php-ionos/subscription.php:86"]  
Öffentliche Formulierung: Aussagen wie "mehrere Tarife zur Auswahl" sind falsch, solange nur ein Tarif aktiv und öffentlich ist.

**TARIF-03** (bestätigt): Jede neu registrierte Firma erhält plan_code unlimited_start und subscription_status pending; es wird kein Stripe-Kunde angelegt und keine Zahlungsinformation erhoben.  
Quelle: ["php-ionos/app/auth.php:1070-1079", "php-ionos/sql/schema.sql:47-55"]  
Öffentliche Formulierung: Zulässig: "Registrierung ohne Zahlungsdaten". Falsch: "Registrierung schließt das Abonnement ab".

**TARIF-04** (bestätigt): UNLIMITED START schließt laut Anwendung ein: unbegrenzte SEPA-Einzüge je Abrechnungsperiode, unbegrenzte Benutzer mit Einladungen, Lexware-Office-Anbindung, Stripe-Anbindung, Hintergrundsynchronisation, SEPA-Verwaltung mit Mandatsdokument, Einzugshistorie, Statussynchronisation, Rechnungsarchiv, Support. Die Bestellübersicht beschreibt die Leistung als "SEPA-Einzug für Rechnungen aus Lexware Office über das eigene Stripe-Konto, Mandatsverwaltung, Einzugshistorie, Support".  
Quelle: ["php-ionos/subscription.php:110", "php-ionos/subscription.php:198-207", "php-ionos/register.php:210", "php-ionos/sql/schema.sql:33"]  
Öffentliche Formulierung: "Unbegrenzte Einzüge und unbegrenzte Benutzer" ist belegt. Nicht behaupten: automatische Einzugsregeln (laut Hilfe nur Vorschau), Rückschreibung von Zahlungen in Lexware Office, Mandatseinholung durch SmartEinzug (AGB: SmartEinzug holt keine Mandate ein).

**TARIF-05** (bestätigt): Alle Tarifpreise sind Nettopreise. Die Anwendung zeigt den Bruttobetrag über billing_vat_hint() mit dem Konfigurationswert billing.vat_rate_percent (Vorgabe 19) nur zur Anzeige an; die Steuer berechnet Stripe Tax (tax_behavior exclusive, automatic_tax, Vorgabe true), USt-IdNr. wird abgefragt (Reverse Charge im EU-Ausland).  
Quelle: ["php-ionos/app/plans.php:389-398", "php-ionos/app/billing_setup.php:98-125", "php-ionos/app/billing.php:166-172", "php-ionos/app/config.example.php:176-182", "php-ionos/subscription.php:111"]  
Öffentliche Formulierung: Immer "netto zzgl. USt." angeben. Bruttopreise oder "inkl. MwSt." wären falsch.

**TARIF-06** (bestätigt): Der Einführungspreis hat einen rollierenden Stichtag: intro_price_deadline() liefert den letzten Tag des laufenden Kalendermonats (TT.MM.JJJJ, Zeitzone der Anwendung); der Standardsatz lautet "Der Einführungspreis gilt für Firmenaccounts, die bis zum TT.MM.JJJJ angelegt werden (Ende des laufenden Kalendermonats); für diese Accounts bleibt er bestehen, solange das Abonnement läuft." Die Datei enthält keinen Preisbetrag. tools/pricing-check.php verbietet feste Ablaufdaten in Preisangaben.  
Quelle: ["php-ionos/app/pricing.php:1-41", "tools/pricing-check.php:27-70", "php-ionos/app/version.php:113-117"]  
Öffentliche Formulierung: Zulässig: "für Firmenaccounts, die bis zum Ende des laufenden Kalendermonats angelegt werden". Falsch: jedes feste Datum (etwa "bis 31.12.2026") und jede Zusage über die Dauer des Einführungspreises hinaus.

**TARIF-07** (bestätigt): Der Betrag des Einführungspreises (25,00 EUR netto je 4 Wochen) und der Vergleichspreis ("bisher 50,00 EUR") stehen als fester Text im Hilfe-Center, auf der Registrierungsseite und im Onboarding; sie werden dort nicht aus der Tabelle plans gelesen. Für UNLIMITED START existiert kein Datensatz zu 50,00 EUR in plans.  
Quelle: ["php-ionos/app/help_content.php:208", "php-ionos/app/help_content.php:243", "php-ionos/register.php:210", "php-ionos/onboarding.php:40", "php-ionos/sql/schema.sql:30-37"]  
Öffentliche Formulierung: Die Herkunft des Vergleichspreises 50,00 EUR ist aus dem Code nicht belegbar (nur Text in Hilfe, AGB-Entwurf und Marketingseiten). Eine "statt"-Preiswerbung setzt voraus, dass der Preis tatsächlich verlangt wurde; das ist eine Rechtsfrage.

**TARIF-08** (bestätigt): Bestandskunden behalten ihren Tarifcode (Grandfathering). Laut Adminhinweis gelten vom Superadmin geänderte Preise für Bestandskunden ab der nächsten Periode, geänderte Limits sofort; abgerechnet wird der in Stripe hinterlegte Preis (stripe_price_id), nicht der Anzeigewert.  
Quelle: ["php-ionos/app/plans.php:1-9", "php-ionos/admin.php:382-387", "php-ionos/admin.php:456-457", "php-ionos/subscription.php:194", "php-ionos/subscription.php:205-206"]  
Öffentliche Formulierung: Zulässig: "bereits angelegte Accounts behalten ihren Preis, solange das Abonnement läuft" (so pricing.php). Der Adminhinweis "ab der nächsten Periode" beschreibt eine technische Möglichkeit des Betreibers, keine Kundenzusage; nicht als Marketingaussage verwenden.

**TARIF-09** (bestätigt): Einzugskontingent: Bei Tarifen mit max_collections_per_period zählen nicht stornierte Einzüge ab Terminierung in einer rollierenden Periode von period_days Tagen (oder ab Stripe-Periodenende); ab 80 Prozent (PLAN_QUOTA_WARN_PERCENT) erscheint ein Hinweis, der Inhaber erhält einmal je Periode eine E-Mail; bei ausgeschöpftem Kontingent sind keine weiteren Einzüge möglich. UNLIMITED START hat kein Limit, die Prüfung liefert dort immer erlaubt.  
Quelle: ["php-ionos/app/plans.php:153-206", "php-ionos/app/plans.php:213", "php-ionos/app/plans.php:286-348", "php-ionos/app/help_content.php:111-112"]  
Öffentliche Formulierung: Für UNLIMITED START gilt "unbegrenzte Einzüge". Kontingentmechanik nur erwähnen, wenn ein limitierter Tarif öffentlich ist.

**TARIF-10** (bestätigt): Sitzlimit: seats_used() zählt aktive und gesperrte Mitglieder plus offene, gültige Einladungen; bei Tarifen ohne unlimited_users greift max_users, bei user_invites_enabled = 0 sind keine Einladungen möglich. Ein Wechsel auf einen Tarif mit weniger Plätzen wird abgelehnt, bis Benutzer oder Einladungen entfernt sind (Downgrade-Schutz).  
Quelle: ["php-ionos/app/plans.php:65-151", "php-ionos/app/help_content.php:244"]  
Öffentliche Formulierung: Für UNLIMITED START: "unbegrenzte Benutzer" belegt. Die Hilfe formuliert vorsichtig "in der Regel", weil der Tarif administrativ geändert werden kann.

**TARIF-11** (geplant): Tarifwechsel und Upsell sind implementiert, wirken aber nur, wenn billing_enabled() gilt und mindestens zwei Tarife aktiv und öffentlich sind (plan_upsell_available()). Upgrade: sofort wirksam, anteilige Berechnung sofort in Rechnung gestellt und eingezogen (proration_behavior always_invoice, payment_behavior error_if_incomplete; scheitert die Zahlung, bleibt der alte Tarif). Downgrade: sofort wirksam, anteilige Gutschrift auf der nächsten Rechnung (create_prorations). Jeder Wechsel verlangt erneute AGB- und Unternehmerbestätigung und wird auditiert; der Inhaber erhält eine Sicherheitsmail.  
Quelle: ["php-ionos/app/plans.php:208-284", "php-ionos/app/billing.php:38-126", "php-ionos/subscription.php:46-64", "php-ionos/subscription.php:162-196", "php-ionos/app/help_content.php:209-210", "php-ionos/app/version.php:205-208"]  
Öffentliche Formulierung: Solange nur UNLIMITED START öffentlich ist, ist "Tarif jederzeit wechseln" nicht zutreffend. Zulässig ist höchstens die Hilfe-Formulierung "sobald mehrere Tarife angeboten werden".

**TARIF-12** (bestätigt): Der Superadmin kann jeden Tarif im Adminbereich ändern (Name, Nettopreis, Periode in Tagen, Einzüge je Periode, Benutzer, Sortierung, Aktiv, Öffentlich, Stripe-Preis-ID); jede Änderung verlangt den aktuellen 2FA-Code und wird protokolliert. Er kann Firmen einen Tarif zuweisen und von der Abrechnung befreien (billing_exempt, Status exempt).  
Quelle: ["php-ionos/admin.php:45-138", "php-ionos/admin.php:150-165", "php-ionos/admin.php:382-415", "php-ionos/admin.php:440-457"]  
Öffentliche Formulierung: Folge: Der tatsächliche Produktionsstand der Tarife ist aus dem Repository nicht ablesbar. Preisangaben vor Veröffentlichung gegen die Produktionsdatenbank prüfen.

**BILL-01** (bestätigt): billing_enabled() ist nur wahr, wenn in config.php sowohl billing.enabled gesetzt als auch billing.stripe_secret_key nicht leer ist. Die Beispielkonfiguration setzt enabled = false und leere Schlüssel. Der Abrechnungsclient billing_client() wirft ohne diese Werte eine Ausnahme ("Die Plattform-Abrechnung ist nicht konfiguriert.").  
Quelle: ["php-ionos/app/plans.php:350-355", "php-ionos/app/billing.php:22-36", "php-ionos/app/config.example.php:172-182"]  
Öffentliche Formulierung: Kein Marketingbezug; interne Voraussetzung jeder Abrechnung.

**BILL-02** (bestätigt): Ohne billing_enabled() liefert subscription_allows_operation() für jede Firma true: Es gibt keine Sperre, keinen Hinweisbalken, keinen Bestellvorgang und keine Abbuchung; alle operativen Funktionen sind nutzbar. Die Abo-Seite zeigt nur Tarif und Status mit dem Hinweis "Die Online-Abrechnung ist noch nicht freigeschaltet. Bis dahin entstehen keine Einschränkungen."; Firmendaten zeigen "Abrechnung noch nicht freigeschaltet ... keine Einschränkungen und keine Kosten"; die Registrierungsseite sagt "Derzeit entsteht mit der Registrierung keine Zahlungspflicht."; das Hilfe-Center: "Die Abo-Abrechnung wird für einen Firmenaccount erst mit Freischaltung durch den Betreiber aktiv."  
Quelle: ["php-ionos/app/plans.php:357-375", "php-ionos/app/layout.php:155-171", "php-ionos/subscription.php:211-212", "php-ionos/team.php:690", "php-ionos/register.php:207-209", "php-ionos/app/help_content.php:215", "php-ionos/app/config.example.php:173-175", "docs/abrechnung.md:35-42"]  
Öffentliche Formulierung: Die faktische Kostenfreiheit ist eine Folge eines Betreiberschalters, kein Produktmerkmal und keine Testphase. Nicht bewerben als "kostenlos", "gratis", "Testphase" oder "Probezeit". Zulässig ist allenfalls die in der Anwendung verwendete Formulierung, dass mit der Registrierung derzeit keine Zahlungspflicht entsteht, und auch das nur, solange der Schalter tatsächlich aus ist.

**BILL-03** (bestätigt): Mit billing_enabled() wird jede Firma ohne billing_exempt gesperrt, deren subscription_status nicht active oder exempt ist; past_due behält den Zugriff bis subscription_period_end. require_subscription() schützt collections.php, customers.php, customer.php, invoices.php, mandate-print.php und sepa-pflegen.php; Inhaber werden auf subscription.php geleitet, Mitarbeiter sehen "Abonnement erforderlich" (HTTP 403). Der Hinweisbalken nennt Tarif, Nettopreis je Periode und "jederzeit zum Periodenende kündbar" mit Button "Jetzt freischalten" bzw. "Vertrag aktivieren".  
Quelle: ["php-ionos/app/plans.php:357-375", "php-ionos/app/auth.php:230-250", "php-ionos/collections.php:7", "php-ionos/customers.php:6", "php-ionos/customer.php:16", "php-ionos/invoices.php:10-12", "php-ionos/mandate-print.php:14", "php-ionos/sepa-pflegen.php:16", "php-ionos/app/layout.php:155-171"]  
Öffentliche Formulierung: Zulässig: "Der Firmenaccount wird mit aktivem Abonnement sofort freigeschaltet" (subscription.php:217). Falsch: "sofort nach Registrierung einziehen", wenn die Abrechnung aktiv ist.

**BILL-04** (ungeklärt): Ob die Plattform-Abrechnung produktiv aktiv ist (billing.enabled, Live-Schlüssel, Stripe-Preis-ID in plans, Webhook), lässt sich aus dem Repository nicht ablesen: config.php ist nicht eingecheckt, docs/ARBEITSSTAND.md führt "Abrechnung scharf schalten" als laufenden Auftrag, docs/abrechnung.md ist eine Anleitung zur Inbetriebnahme, bin/billing-check.php meldet bei fehlendem enabled "noch nicht scharf geschaltet". Ein Beleg für Live-Betrieb fehlt.  
Quelle: ["docs/ARBEITSSTAND.md:7-14", "docs/abrechnung.md:24-119", "php-ionos/app/billing_setup.php:262-264", "php-ionos/app/config.example.php:4-7"]  
Öffentliche Formulierung: Keine Aussage zur laufenden Abrechnung oder zu Kundenzahlen treffen. Preisangaben sind unabhängig davon zulässig, wenn sie als Konditionen des Abonnements formuliert sind.

**BILL-05** (nicht_vorhanden): Es gibt keine kostenlose Testphase, keinen Probezeitraum und keinen kostenlosen Zeitraum im Abonnement: Der Stripe-Checkout wird ohne trial_period_days erzeugt, die Tabelle plans kennt kein Trial-Feld, kein Text der Anwendung nennt eine Testphase. Einzig die Zuordnung des Stripe-Status trialing auf active in billing_apply_subscription() existiert als defensive Statusabbildung; sie wird von der Anwendung nicht ausgelöst.  
Quelle: ["php-ionos/app/billing.php:144-176", "php-ionos/app/billing.php:209-222", "php-ionos/sql/schema.sql:13-28", "php-ionos/app/help_content.php:204-219"]  
Öffentliche Formulierung: Falsch wären: "kostenlos testen", "14 Tage gratis", "Probemonat", "Testphase". Zulässig: "Kündigung jederzeit zum Ende der laufenden Vierwochenperiode, keine Jahresbindung". Die Stripe-Testmodus-Funktion (Testschlüssel des Kunden, TESTMODUS-Banner) ist ein technischer Modus, keine Testphase des Abonnements.

**BILL-06** (bestätigt): Konditionen vor Abschluss (Bestellübersicht subscription.php?bestellen=1, nur Inhaber): Leistung (Produkt, Tarif, Leistungsbeschreibung); Preis (Nettobetrag je period_days Tage, USt-Hinweis mit Bruttobetrag, USt auf der Rechnung, Reverse Charge bei gültiger USt-IdNr. außerhalb Deutschlands); Laufzeit (Abrechnungsperiode period_days Tage, verlängert sich automatisch um jeweils period_days Tage bis zur Kündigung); Kündigung (jederzeit zum Ende der laufenden Abrechnungsperiode, ohne Frist, über Firma > Abonnement, Zugriff bleibt bis Periodenende); Zahlung (über Stripe, SEPA-Lastschrift oder Karte, erste Abbuchung mit Abschluss, danach je Periode, Rechnungen im Archiv und Stripe-Kundenportal); Vertragspartner (config operator: Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein). Zwei Pflichtkontrollkästchen (Unternehmereigenschaft; AGB gelesen und akzeptiert, Datenschutzerklärung zur Kenntnis genommen), Button "Zahlungspflichtig abonnieren", Hinweis: Weiterleitung zu Stripe, Vertrag kommt mit Abschluss der Zahlung zustande, Zeitpunkt, AGB-Fassung und Preis werden protokolliert.  
Quelle: ["php-ionos/subscription.php:12", "php-ionos/subscription.php:86-89", "php-ionos/subscription.php:106-135", "php-ionos/subscription.php:214-221", "php-ionos/app/billing.php:372-390", "php-ionos/app/config.example.php:113-124"]  
Öffentliche Formulierung: Die Anwendung erfüllt die Vorgabe "Konditionen im Buchungsprozess transparent" mit Preis, Laufzeit, Kündigung, Zahlung und Vertragspartner vor der Weiterleitung. Marketingseiten dürfen darauf verweisen ("alle Konditionen vor Abschluss in der Bestellübersicht"). Das Angebot richtet sich ausdrücklich nicht an Verbraucher.

**BILL-07** (bestätigt): Der Stripe-Checkout wird mit mode subscription, locale de, allow_promotion_codes true, automatic_tax gemäß Konfiguration, tax_id_collection, billing_address_collection required und Metadaten tenant_id und plan_code erzeugt; Rückkehr auf subscription.php?checkout=success bzw. cancel. Nach der Rückkehr gleicht die Anwendung das Abonnement direkt mit Stripe ab; sonst aktualisiert der Webhook den Status.  
Quelle: ["php-ionos/app/billing.php:144-176", "php-ionos/subscription.php:22-36"]  
Öffentliche Formulierung: Gutschein- oder Aktionscodes sind technisch möglich (allow_promotion_codes), aber nur bewerben, wenn im Stripe-Konto Codes angelegt sind (ungeklärt). Zahlungsarten im Checkout hängen von der Stripe-Konfiguration ab; die Bestellübersicht nennt SEPA-Lastschrift oder Karte.

**BILL-08** (bestätigt): Kündigung: Der Inhaber merkt die Kündigung zum Ende der laufenden Abrechnungsperiode vor (Stripe cancel_at_period_end) und kann sie bis dahin zurücknehmen; beides verlangt Passwort und 2FA-Code, wird auditiert und löst eine Sicherheitsmail an den Inhaber aus. Der Zugriff bleibt bis zum Periodenende bestehen; das Periodenende wird angezeigt. Alternativ steht das Stripe-Kundenportal (Zahlungsmethode, Rechnungen, Kündigung) zur Verfügung.  
Quelle: ["php-ionos/subscription.php:67-79", "php-ionos/subscription.php:229-257", "php-ionos/app/billing.php:178-206", "php-ionos/app/help_content.php:213-219", "php-ionos/app/help_content.php:252", "php-ionos/app/layout.php:170-173"]  
Öffentliche Formulierung: Zulässig: "jederzeit zum Ende der laufenden Vierwochenperiode kündbar, keine Kündigungsfrist, keine Jahresbindung". Falsch: "sofortige Kündigung mit Rückerstattung" oder "monatlich kündbar" (Periode sind 28 Tage, kein Kalendermonat).

**BILL-09** (bestätigt): Abrechnungsperiode ist period_days aus plans (28 Tage). In Stripe wird der Preis als wiederkehrend mit interval day und interval_count = period_days angelegt, Nettopreis (tax_behavior exclusive), lookup_key lexsepa_<tarifcode>, Produktname "SmartEinzug <Tarifname>". Anlage nur über bin/billing-setup-stripe.php (Trockenlauf Standard, --apply, mit Live-Schlüssel --live-bestaetigt); Prüfung über bin/billing-check.php. Monats- oder Jahresintervalle meldet die Prüfung als Fehler.  
Quelle: ["php-ionos/app/billing_setup.php:32-39", "php-ionos/app/billing_setup.php:86-185", "docs/abrechnung.md:54-73", "php-ionos/app/version.php:125-131"]  
Öffentliche Formulierung: "je 4 Wochen" oder "je 28 Tage" ist richtig; "monatlich" oder "pro Monat" wäre falsch (13 Perioden im Jahr statt 12).

**BILL-10** (bestätigt): Der Webhook billing-webhook.php verarbeitet genau fünf Ereignisse (checkout.session.completed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, invoice.payment_failed) mit Idempotenz und Reihenfolgeschutz. invoice.payment_failed setzt eine aktive Firma auf past_due; sie arbeitet bis zum Ende der bezahlten Periode weiter, danach greift die Sperre. Rechnungen stellt Stripe aus; die Anwendung zeigt bis zu 24 Rechnungen mit Nummer, Zeitraum, Betrag, Status, Ansicht und PDF aus Stripe an.  
Quelle: ["php-ionos/app/billing_setup.php:20-30", "php-ionos/app/billing.php:250-323", "php-ionos/app/billing.php:325-370", "php-ionos/subscription.php:261-292", "docs/abrechnung.md:75-91", "docs/abrechnung.md:133-134"]  
Öffentliche Formulierung: Zulässig: "Rechnungen zum Abonnement jederzeit im Rechnungsarchiv und im Stripe-Kundenportal abrufbar".

**BILL-11** (bestätigt): Firmen können von der Abrechnung befreit werden (billing_exempt = 1, subscription_status exempt) durch den Superadmin; Migration 003 setzte beim Ausführen alle damals bestehenden Firmen mit Status pending auf exempt (Eigen-Nutzung vor Marktstart). Befreite Firmen sehen "Diese Firma ist von der Plattform-Abrechnung befreit."  
Quelle: ["php-ionos/admin.php:150-165", "php-ionos/sql/migrations/003_saas_2fa_roles_plans.sql:211-214", "php-ionos/subscription.php:209-210", "php-ionos/app/plans.php:362-369"]  
Öffentliche Formulierung: Kein Marketingbezug; Befreiung ist eine interne Verwaltungsentscheidung.

**BILL-12** (bestätigt): Die Bestellbestätigung protokolliert die AGB-Fassung aus config('agb_version'); dieser Schlüssel fehlt in config.example.php, der Rückfallwert lautet "AGB smart-einzug.de, Stand <Tagesdatum>" mit Link public_base_url()/agb.  
Quelle: ["php-ionos/app/billing.php:377-390", "php-ionos/app/config.example.php:1-284"]  
Öffentliche Formulierung: Kein Marketingbezug.

**BILL-13** (bestätigt): Die Plattform-Abrechnung läuft über das Stripe-Konto der Müller Holding AG und ist getrennt von den Stripe-Konten der Firmen, über die diese ihre eigenen SEPA-Einzüge abwickeln; es gibt kein Stripe Connect und kein Sammelkonto.  
Quelle: ["php-ionos/app/billing.php:1-9", "docs/sevdesk.md:149-150", "docs/integrations.md:25"]  
Öffentliche Formulierung: Zulässig: "Einzug über Ihr eigenes Stripe-Konto, Stripe-Gebühren rechnet Stripe direkt mit Ihnen ab" (so auch AGB-Entwurf Zeile 102). Falsch: "SmartEinzug zieht das Geld ein" oder "Auszahlung durch SmartEinzug".

**REG-01** (bestätigt): Die Registrierung ist nur erreichbar, wenn config allow_registration gesetzt ist (Vorgabe true); sonst Weiterleitung zur Anmeldung mit Hinweis. Angemeldete Benutzer werden zur Firmenübersicht geleitet und legen weitere Firmen dort an.  
Quelle: ["php-ionos/register.php:6-15", "php-ionos/app/config.example.php:129-130"]  
Öffentliche Formulierung: Kein Marketingbezug.

**REG-02** (bestätigt): Pflichtangaben der Registrierung: Firmenname (Zahlungsempfänger auf dem SEPA-Mandat), Mandatspräfix (2 bis 10 alphanumerische Zeichen, bildet den Anfang der Mandatsreferenzen, nach der Einrichtung nicht änderbar), E-Mail-Adresse (persönlich, kein Sammelpostfach), Passwort mindestens 10 Zeichen mit Wiederholung, Kontrollkästchen AGB akzeptiert und Datenschutzerklärung zur Kenntnis genommen; Vor- und Nachname optional. Ein Kontrollkästchen zum Auftragsverarbeitungsvertrag erscheint nur, wenn eine AVV-Fassung veröffentlicht ist; dann ist es Pflicht und der Abschluss wird nachgewiesen.  
Quelle: ["php-ionos/register.php:31-50", "php-ionos/register.php:62-70", "php-ionos/register.php:147-204"]  
Öffentliche Formulierung: Zulässig: "Registrierung in wenigen Minuten ohne Zahlungsdaten". Nicht behaupten: "ohne AGB-Zustimmung" oder "anonym".

**REG-03** (bestätigt): Die Registrierungsseite nennt als Voraussetzungen "Lexware Office XL (nach Angaben von Lexware für die Public API erforderlich, bitte im eigenen Konto prüfen) und ein für SEPA-Lastschriften freigeschaltetes eigenes Stripe-Konto" und beschreibt das Produkt als "SEPA-Lastschriften für Rechnungen aus Lexware Office über Stripe einziehen"; der Registrierende wird Inhaber und richtet anschließend die verpflichtende Zwei-Faktor-Authentifizierung ein (require_2fa Vorgabe true).  
Quelle: ["php-ionos/register.php:136-145", "php-ionos/app/config.example.php:132-134", "php-ionos/app/help_content.php:17-21"]  
Öffentliche Formulierung: Lexware-Tarifaussage nur vorsichtig: "nach Angaben von Lexware", "im eigenen Konto prüfen". Falsch: "funktioniert mit jedem Lexware-Office-Tarif".

**REG-04** (bestätigt): Konditionen im Registrierungsprozess: Der Fußtext der Registrierungsseite lautet "Tarif UNLIMITED START: 25,00 EUR netto zzgl. USt. je 4 Wochen, unbegrenzte Einzüge, unbegrenzte Mitarbeiter." plus Unabhängigkeitshinweis ("Kein Produkt der Haufe-Lexware GmbH & Co. KG"). Darüber steht je nach Schalter "Der Tarif wird erst mit Abschluss des Abonnements im Firmenbereich kostenpflichtig." (Abrechnung aktiv) oder "Derzeit entsteht mit der Registrierung keine Zahlungspflicht." (Abrechnung aus). Der Stichtag des Einführungspreises wird auf der Registrierungsseite nicht genannt; Zahlungsdaten werden nicht erhoben.  
Quelle: ["php-ionos/register.php:206-211"]  
Öffentliche Formulierung: Die Registrierung ist kein Vertragsschluss über das Abonnement; der zahlungspflichtige Abschluss erfolgt erst in der Bestellübersicht (BILL-06). Marketing darf sagen: "Registrierung und Einrichtung ohne Zahlungsdaten, Abonnement erst im Firmenbereich abschließen".

**REG-05** (bestätigt): Bekannte E-Mail-Adresse: Eine Adresse gehört zu genau einem Benutzerkonto. Bei gleicher Firma erscheint "Benutzerkonto vorhanden" mit Weiterleitung zur Anmeldung; bei anderer Firma wird der Vorgang (Firmenname, Mandatspräfix, Ablaufzeit) zwischengespeichert, der Benutzer meldet sich an und bestätigt in register-fortsetzen.php die Anlage der weiteren Firma mit getrennten Kunden, Rechnungen, Einzügen, Zugängen und eigenem Abonnement. Das eingegebene Passwort wird dabei weder geprüft noch gespeichert.  
Quelle: ["php-ionos/register.php:74-129", "php-ionos/register-fortsetzen.php:1-76", "php-ionos/app/help_content.php:201"]  
Öffentliche Formulierung: Zulässig: "Mehrere Firmen unter einem Benutzerkonto, jede mit eigenem Abonnement" (Multiaccount).

**REG-06** (bestätigt): Herkunft und Vorauswahl: register.php liest src (nur Domains aus signup_domains: smart-einzug.de, lexware-einzug.de, lexoffice-einzug.de, lastschrift-einfach.de), utm_*, Referrer und integration (nur lexware_office oder sevdesk) in die Session und speichert Herkunft in organizations; der Parameter integration ist fachliche Vorauswahl, kein Berechtigungsnachweis. Bei integration=sevdesk ohne gesetzten Schalter sevdesk_connect wird die Vorauswahl verworfen und zur Vorregistrierung /integrationen/sevdesk/#vormerken weitergeleitet.  
Quelle: ["php-ionos/app/auth.php:976-1010", "php-ionos/register.php:16-25", "php-ionos/app/invoice_source_switch.php:154-161", "php-ionos/app/config.example.php:126-127"]  
Öffentliche Formulierung: Registrierungslinks von Marketingseiten dürfen ?src=<domain> tragen; ?integration=sevdesk führt derzeit nur zur Vormerkung und darf nicht als "sevdesk-Registrierung" beworben werden.

**REG-07** (bestätigt): Nach erfolgreicher Registrierung ist der Benutzer angemeldet (Inhaber), die Herkunft wird auditiert und als Funnel-Ereignis gezählt, die Weiterleitung führt über dashboard.php zu onboarding.php; bei aktivem Mailversand folgt die E-Mail-Bestätigung, ohne Mailversand gilt die Adresse sofort als bestätigt.  
Quelle: ["php-ionos/register.php:71-72", "php-ionos/app/auth.php:1074-1079", "php-ionos/app/auth.php:1116-1131", "php-ionos/dashboard.php:11"]  
Öffentliche Formulierung: Kein Marketingbezug.

**ONB-01** (bestätigt): Die Einrichtungsseite zeigt Schritte: 1. Konto erstellt (Firma angelegt, 2FA aktiv); 2. nur bei aktiver Abrechnung und nicht befreiter Firma: Abonnement aktivieren ("UNLIMITED START, 25,00 EUR netto zzgl. USt. je 4 Wochen. Nur der Inhaber kann das Abonnement abschließen."); 3. Lexware Office verbinden (API-Schlüssel aus Einstellungen > Erweiterungen > Public API); 4. Stripe verbinden (eigenes Konto, Secret Key, optional Webhook-Secret); 5. Verbindungen prüfen; 6. Firmendaten für SEPA-Mandate (optional, empfohlen; Gläubiger-Identifikationsnummer freiwillig); 7. Einrichtung abgeschlossen (erste Synchronisation). Der Abschluss wird auditiert und als Funnel-Ereignis gezählt.  
Quelle: ["php-ionos/onboarding.php:14-24", "php-ionos/onboarding.php:26-77", "php-ionos/app/help_content.php:13-28"]  
Öffentliche Formulierung: Zulässig: "Einrichtung in wenigen Schritten: Registrierung, 2FA, Lexware Office verbinden, Stripe verbinden, erste Synchronisation". Nicht mit einer festen Minutenzahl werben, sie ist nicht belegt.

**HILFE-01** (bestätigt): Thema "Abonnement und Abrechnung": "SmartEinzug wird im Tarif UNLIMITED START angeboten. Für Firmenaccounts, die bis zum <rollierender Stichtag> angelegt werden (Ende des laufenden Kalendermonats), gilt ein Einführungspreis von 25,00 EUR netto je 4 Wochen (bisher 50,00 EUR); für diese Accounts bleibt er bestehen, solange das Abonnement läuft. Alle Preise verstehen sich netto zuzüglich der gesetzlichen Umsatzsteuer." FAQ "Was kostet SmartEinzug?" mit gleichem Inhalt. Weitere Abschnitte: Tarifwechsel ("sobald mehrere Tarife angeboten werden"), Abrechnung über Stripe mit Rechnungen im Kundenportal, Kündigung zum Periodenende und Rücknahme, Verwaltung nur durch den Inhaber, Hinweis auf Freischaltung durch den Betreiber.  
Quelle: ["php-ionos/app/help_content.php:5-8", "php-ionos/app/help_content.php:203-219", "php-ionos/app/help_content.php:243", "php-ionos/app/help_content.php:252"]  
Öffentliche Formulierung: Das Hilfe-Center ist die einzige Stelle der Anwendung, die den Stichtag als konkretes Datum nennt (berechnet). Marketingseiten sollen laut pricing.php die gleichlautende Regel ohne Datum verwenden.

**HILFE-02** (bestätigt): Das Hilfe-Center (13 Themen: erste-schritte, lexware-verbindung, stripe-verbindung, kunden-iban-mandate, einzug-ablauf, sammel-einzug, ruecklastschrift-erstattung, not-stopp, import-bestand, export-journal, rollen-sicherheit, abo-abrechnung, fehlermeldungen) nennt keine Testphase, keinen kostenlosen Zeitraum und keine sevdesk-Anbindung; Lexware-Voraussetzung wird vorsichtig als "bestimmter Tarif ... im eigenen Lexware-Konto prüfen" formuliert; Regeln für automatische Einzüge werden als "nur Vorschau, lösen noch keine Einzüge aus" beschrieben. Es ist nur für angemeldete Benutzer erreichbar und enthält Suche und Support-Anfragen.  
Quelle: ["php-ionos/app/help_content.php:13-31", "php-ionos/app/help_content.php:113-114", "php-ionos/hilfe.php:1-20", "php-ionos/hilfe.php:46-65"]  
Öffentliche Formulierung: Öffentliche Hilfe- oder FAQ-Seiten sollten dieselben Aussagen tragen wie das Hilfe-Center; insbesondere keine Automatisierungsversprechen über Regeln hinaus.

**SEV-01** (geplant): Registry integration_providers: sevdesk hat status planned, capabilities_json leer, api_version v2 und die Notiz "In Planung. Voraussetzung laut Anbieter voraussichtlich Tarif Buchhaltung Pro, API v2. Ungeprüft, keine Freigabe, kein Angebot." Die Factory invoice_source_for_tenant() lässt nur den Status released zu.  
Quelle: ["php-ionos/sql/schema.sql:780-799", "docs/integrations.md:19-27"]  
Öffentliche Formulierung: sevdesk nur als "in Vorbereitung" oder "in Planung" nennen.

**SEV-02** (geplant): app/sevdesk.php ist ein Gerüst ohne Fähigkeiten: SevdeskClient authentifiziert per HTTP-Header Authorization (Basisadresse und Headerform aus config sevdesk, zu verifizieren), nutzt api_call_gate() und circuit_failure() und wirft ohne Schalter sevdesk_connect; SevdeskSource liefert capabilities() = [] und jede fachliche Methode (Profil, offene Rechnungen, Seitennavigation, Rechnungsdetail, Kontakt, Zahlungsstand) wirft "noch nicht gegen die offizielle Dokumentation verifiziert und daher gesperrt". Blocker: kein sevdesk-Testkonto im Projekt.  
Quelle: ["php-ionos/app/sevdesk.php:1-143", "docs/sevdesk.md:197-216", "php-ionos/app/version.php:81"]  
Öffentliche Formulierung: Keine Funktionszusage zu sevdesk (kein Rechnungsabruf, kein Einzug, kein Verbindungstest verfügbar). Zulässig: "geplant", "soll", "vorgesehen".

**SEV-03** (bestätigt): Freigabeschalter in platform_settings, nur serverseitig setzbar: sevdesk_public_state (angekuendigt \| beta \| verfuegbar \| eingeschraenkt, Standard angekuendigt), sevdesk_waitlist (Standard 1), sevdesk_connect (Standard 0), sevdesk_collections (Standard 0, Not-Aus für neue Einzüge dieses Anbieters), sevdesk_writeback (Standard 0). Ohne Datensatz gelten die Standardwerte.  
Quelle: ["php-ionos/app/integration_state.php:1-56", "docs/sevdesk.md:173-185", "php-ionos/sql/schema.sql:707-713"]  
Öffentliche Formulierung: Der öffentliche Status (angekündigt) darf sich nur ändern, wenn der Schalter gesetzt ist; die Marketingseite ist statisch und muss von Hand nachgeführt werden.

**SEV-04** (bestätigt): Vorregistrierung (Warteliste) ist vorhanden: Formular auf smart-einzug.de/integrationen/sevdesk/ sendet an app.smart-einzug.de/vormerken.php; Pflicht sind E-Mail und Einwilligung, optional Name und Firma; Honeypot; Herkunftsprüfung gegen signup_domains; Double-Opt-in per Button (kein Auslösen per GET); Token A (Bestätigung, 7 Tage) und Token B (Abmeldung und freiwillige Angaben, dauerhaft) getrennt und nur als SHA-256 gespeichert; keine IP-Speicherung; Grenzen 30 neue Einträge je Minute, Wiederversand frühestens nach 10 Minuten, höchstens 3 Mails je Adresse in 24 Stunden; Löschung unbestätigt 30 Tage nach Eintragung, abgemeldet 30 Tage nach Abmeldung, bestätigt 30 Tage nach Startnachricht; Sperrvermerk behält nur die E-Mail. Freiwillige Angaben nach Bestätigung: Rechnungen je Monat (bis 20, 21 bis 100, 101 bis 500, mehr als 500), Stripe-Konto vorhanden, API-Zugang vorhanden, Betatest-Interesse. Vormerkung nur für Anbieter, die nicht released sind und deren waitlist-Schalter gesetzt ist.  
Quelle: ["php-ionos/app/interest.php:1-46", "php-ionos/app/interest.php:60-84", "php-ionos/app/interest.php:116-225", "php-ionos/app/interest.php:313-355", "php-ionos/app/interest.php:404-431", "php-ionos/vormerken.php:1-15", "php-ionos/vormerken.php:204-217", "php-ionos/vormerken.php:295-350", "php-ionos/sql/schema.sql:934-974", "docs/integrations.md:77-84"]  
Öffentliche Formulierung: Zulässig: "kostenlose, unverbindliche Vormerkung mit Bestätigung per E-Mail, jederzeit abmeldbar". Nicht: "Anmeldung", "Konto", "Vorbestellung", "Frühbucherpreis".

**SEV-05** (bestätigt): Die Vormerkung erzeugt kein Abonnement, keinen Firmenaccount, keine Zahlungspflicht und nennt keinen Preis; die Anwendung sagt das auf jeder Seite des Ablaufs und in beiden E-Mails ausdrücklich ("Durch die Bestätigung entsteht kein kostenpflichtiges Abonnement", "Es entsteht kein Abonnement und keine Zahlungspflicht", "Die Vormerkung ist kostenlos und unverbindlich"). Eine Wartelistenbestätigung ist kein Login und erteilt keine Rechte an Firmenkonten.  
Quelle: ["php-ionos/app/interest.php:25-26", "php-ionos/vormerken.php:14-15", "php-ionos/vormerken.php:274-292", "php-ionos/vormerken.php:347-350", "php-ionos/app/mailer.php:522-556"]  
Öffentliche Formulierung: Die Landingpage darf sagen: "Kostenlose Vormerkung. Kein Abonnement durch die Vorregistrierung." (so bereits websites/smart-einzug.de/integrationen/sevdesk/index.html:170).

**SEV-06** (bestätigt): Verbindliche Formulierungen (CLAUDE.md, umgesetzt auf der Landingpage): Status "In Vorbereitung, Start für Ende September 2026 geplant" (nie als Zusage; "Ende September 2026 ist ein Planungsziel; ohne erfolgreiche Abnahme bleibt die Anbindung angekündigt oder in einer begrenzten Betaphase"); sevdesk-Voraussetzung nur als "nach der offiziellen sevdesk-Hilfe wird der API-Zugang für die Systemversion 2.0 in Deutschland im Tarif Buchhaltung Pro angeboten ... prüft SmartEinzug beim Verbindungstest ... Maßgeblich sind allein die Angaben von sevdesk"; Stripe unterstützt das SEPA-Basislastschriftverfahren (Core), nicht das Firmenlastschriftverfahren (B2B); keine Partnerschaft, Zertifizierung oder geschäftliche Verbindung mit dem Anbieter von sevdesk; Rückschreibung nach sevdesk "erst nach Abnahme", nicht zugesagt.  
Quelle: ["websites/smart-einzug.de/integrationen/sevdesk/index.html:7", "websites/smart-einzug.de/integrationen/sevdesk/index.html:124", "websites/smart-einzug.de/integrationen/sevdesk/index.html:162-170", "websites/smart-einzug.de/integrationen/sevdesk/index.html:268", "websites/smart-einzug.de/integrationen/sevdesk/index.html:327-333", "websites/smart-einzug.de/integrationen/sevdesk/index.html:353", "websites/smart-einzug.de/integrationen/sevdesk/index.html:394-411", "docs/sevdesk.md:1-5", "docs/sevdesk.md:159-160", "php-ionos/app/version.php:76-79"]  
Öffentliche Formulierung: Nur diese Formulierungen verwenden. Falsch: "ab 30.09.2026 verfügbar", "offizielle sevdesk-Integration", "sevdesk-Partner", "zertifiziert", "Zahlungen werden automatisch in sevdesk verbucht", "funktioniert mit jedem sevdesk-Tarif", "B2B-Lastschrift".

**SEV-07** (nicht_vorhanden): Für sevdesk existiert kein eigener Tarif und kein Preis: Die Tabelle plans enthält keinen sevdesk-Datensatz; laut Dokumentation gelten für beide Buchhaltungssysteme identische Tarife, ein eigener sevdesk-Tarif wäre ohne Codeänderung über plans möglich, ist aber nicht angelegt. Öffentlich: kein Preis, kein Kaufbutton, kein Firmenaccount vor Freigabe.  
Quelle: ["php-ionos/sql/schema.sql:30-37", "docs/integrations.md:45", "docs/integrations.md:66", "docs/integrations.md:74", "docs/sevdesk.md:158"]  
Öffentliche Formulierung: Keine Preis- oder Tarifaussage zu sevdesk, auch nicht "gleicher Preis wie Lexware Office" (keine Preisgarantie, docs/sevdesk.md:158).

**SEV-08** (bestätigt): Die Adminverwaltung der Interessenten ist vorhanden: Suche und Filter (Status, Herkunft, Text), CSV-Export mit Formelschutz, Kennzahlen je Anbieter (Absendungen, bestätigt, Betainteresse, eingeladen, aktiviert, verbundene Firmen, erfolgreiche Einzüge), Aktionen Abmelden, Sperren, Löschen, Betaeinladung vormerken. Ein Versandwerkzeug für die Startnachricht (nur status confirmed und nicht gesperrt, setzt notified_at) existiert noch nicht.  
Quelle: ["php-ionos/app/interest.php:376-401", "php-ionos/app/interest.php:433-539", "docs/sevdesk.md:155", "docs/sevdesk.md:187-195", "docs/integrations.md:83"]  
Öffentliche Formulierung: Kein Marketingbezug. Die Landingpage verspricht Information per E-Mail beim Start; das Werkzeug dafür fehlt noch.

**SRC-01** (bestätigt): Auswählbare Buchhaltungssysteme: INVOICE_SOURCE_CODES = lexware_office und sevdesk. Lexware Office ist immer verfügbar (Standardwert integrations.invoice_source), sevdesk nur bei gesetztem Schalter sevdesk_connect. Vorauswahl über register.php?integration=<code>; jede Firma hat genau ein Buchhaltungssystem und sieht nur dieses.  
Quelle: ["php-ionos/app/invoice_source_switch.php:1-21", "php-ionos/app/invoice_source_switch.php:45-55", "php-ionos/app/invoice_source_switch.php:154-161", "php-ionos/sql/schema.sql:802-807", "php-ionos/app/version.php:17-21"]  
Öffentliche Formulierung: Zulässig heute: "verfügbar für Lexware Office". sevdesk ist nicht auswählbar, solange der Schalter nicht gesetzt ist.

**SRC-02** (bestätigt): Wechsel des Buchhaltungssystems: nur Inhaber oder Administrator mit aktuellem 2FA-Code in den Einstellungen; danach Sperre von 28 Tagen (INVOICE_SOURCE_LOCK_DAYS, entspricht der Abrechnungsperiode); gesperrt, solange Einzüge vorgemerkt, terminiert oder in Verarbeitung sind, eine Synchronisation läuft oder das Zielsystem nicht freigegeben ist; der Wechsel trennt die alte Verbindung (Schlüssel gelöscht), Rechnungen, Kunden, Mandate und Einzüge bleiben als Historie, Audit invoice_source_switched. Das Abonnement bleibt unverändert; wer zwei Buchhaltungen führt, braucht zwei Firmenaccounts.  
Quelle: ["php-ionos/app/invoice_source_switch.php:1-21", "docs/integrations.md:39-51", "php-ionos/app/version.php:17-21"]  
Öffentliche Formulierung: Zulässig: "ein Abonnement je Firma und Buchhaltungssystem". Nicht: "Lexware Office und sevdesk gleichzeitig in einem Account".

**MAIL-01** (bestätigt): Jede Registrierung sendet eine Willkommensmail im CI der Müller Holding AG (mail_layout) mit Betreff "Willkommen bei SmartEinzug: Bitte E-Mail-Adresse bestätigen": Firmenaccount angelegt, Bestätigungslink (24 Stunden gültig, erneut anforderbar), nächste Schritte (Zwei-Faktor-Anmeldung, Buchhaltungssystem verbinden, eigenes Stripe-Konto verbinden), Hinweis "Falls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail; es entsteht kein Vertrag." Kann die Mail nicht erzeugt werden (mail.enabled aus oder Störung), wird users.welcome_mail_pending gesetzt und die Wartung sendet nach; gilt die Adresse bereits als bestätigt, ohne Bestätigungslink mit Betreff "Willkommen bei SmartEinzug" und Button "Zur Anwendung".  
Quelle: ["php-ionos/app/mailer.php:559-579", "php-ionos/app/auth.php:837-850", "php-ionos/app/auth.php:1123-1131", "php-ionos/app/auth.php:1133-1163", "php-ionos/app/version.php:58-70"]  
Öffentliche Formulierung: Zulässig: "Nach der Registrierung erhalten Sie eine Willkommensmail mit Bestätigungslink". Der Produktname kommt aus config product_name (Vorgabe SmartEinzug).

**MAIL-02** (bestätigt): Vormerkung: Erste Mail mit Betreff "Bitte bestätigen Sie Ihre sevdesk-Vormerkung bei SmartEinzug", Bestätigungsbutton (7 Tage gültig), Hinweis auf Löschung nach spätestens 30 Tagen ohne Bestätigung, "Die Vormerkung ist kostenlos und unverbindlich", Zweitlink "Abmelden oder Eintrag löschen lassen"; beim Nachsenden zusätzlich Entschuldigung für die Verzögerung mit Eintragungsdatum. Nach Bestätigung zweite Mail "Ihre sevdesk-Vormerkung bei SmartEinzug ist bestätigt" mit Hinweis, dass noch kein sevdesk- oder Stripe-Konto verbunden werden muss, kein Abonnement und keine Zahlungspflicht entsteht, Button "Zum aktuellen Stand" (Produktseite), Abmeldelink, Löschung 30 Tage nach Startnachricht oder Abmeldung. Ohne Mailversand wird die Zeile als wartend markiert und nachgesendet; nie eine stille Bestätigung.  
Quelle: ["php-ionos/app/mailer.php:519-556", "php-ionos/app/interest.php:207-225", "php-ionos/app/interest.php:237-279", "php-ionos/app/interest.php:331-355", "php-ionos/vormerken.php:340-350"]  
Öffentliche Formulierung: Zulässig: "Bestätigung per E-Mail, jederzeit abmeldbar, Löschung nach 30 Tagen".

**MAIL-03** (bestätigt): Weitere abrechnungsbezogene Mails: Kontingenthinweis an den Inhaber einmal je Periode (nur Tarife mit Einzugslimit, Betreff "SmartEinzug: Einzugskontingent zu N Prozent belegt"); Sicherheitsmails an den Inhaber bei Tarifwechsel ("Tarif geändert"), Kündigung ("Abonnement gekündigt") und Rücknahme ("Kündigung zurückgenommen"). Eine Bestellbestätigung per E-Mail durch die Anwendung selbst ist nicht implementiert; Rechnungen kommen von Stripe.  
Quelle: ["php-ionos/app/plans.php:286-348", "php-ionos/subscription.php:54-57", "php-ionos/subscription.php:73-75", "php-ionos/app/billing.php:144-176"]  
Öffentliche Formulierung: Nicht behaupten: "Auftragsbestätigung per E-Mail von SmartEinzug". Zutreffend: "Rechnungen und Zahlungsbestätigungen erhalten Sie von Stripe" (Stripe-Konfiguration, ungeklärt).



## Sicherheitsaussagen: öffentlich belegbar und nicht belegbar

Belegbar (mit Verweis auf die Einträge):

- Pflicht-2FA per Authenticator-App (TOTP) für jeden Benutzer inklusive Betreiberzugang (KONTO-01, KONTO-02, KONTO-04).
- Recovery-Codes einmalig verwendbar, nur als Hash gespeichert (KONTO-03).
- API-Schlüssel, Stripe-Geheimnisse und 2FA-Geheimnis mit AES-256-GCM verschlüsselt, nie im Klartext angezeigt (SICH-01, SICH-02).
- Passwörter nur als Hash; Sperren nach Fehlversuchen (KONTO-05, KONTO-06).
- Rollen Inhaber, Administrator, Mitarbeiter; Einladungen mit Hash-Token; sofortiger Sitzungsentzug (ROLLE-01 bis ROLLE-04).
- Mehrere Firmen je Benutzerkonto mit vollständig getrennten Daten und Anbindungen (MULTI-01, MULTI-02).
- Optionale Gerätefreigabe 90 Tage, Passwort bleibt erforderlich, jederzeit widerrufbar (GERAET-01, GERAET-02).
- Protokoll sicherheits- und geldrelevanter Aktionen, 90 Tage, CSV-Export für den Inhaber (AUDIT-01 bis AUDIT-03).
- Support-Zugriff befristet auf 60 Minuten, begründet, mit 2FA, im Kundenprotokoll sichtbar, Inhaber wird informiert, Einzüge und Zugangsdaten gesperrt (SUPPORT-01, SUPPORT-02).
- HTTPS für alle Hosts; Datenbank nicht öffentlich erreichbar; Dateien außerhalb des Webzugriffs (SICH-07, SICH-08, HOST-02).
- Marketingseiten bei IONOS SE, Deutschland (HOST-04).
- Keine Verfügbarkeitszusage (VERF-01).

Nicht belegbar, deshalb nicht öffentlich behaupten:

- IBAN-Verschlüsselung (Klartext im Code, SICH-03).
- Standort der Anwendung, der Datenbank und der Backups in Deutschland oder der EU (HOST-01, HOST-03).
- Verfügbarkeit von AVV oder Verschwiegenheitsvereinbarung (nur Entwürfe, RECHT-02).
- Prozentuale Verfügbarkeit, SLA, Hochverfügbarkeit, Redundanz (VERF-01, HOST-05).
- Unabhängiges externes Monitoring oder Live-Statusseite (STATUS-02, VERF-01).
- Dauerhaftes, unveränderliches oder revisionssicheres Protokoll (AUDIT-02).
- Support nur mit Zustimmung des Kunden (SUPPORT-01).
- HSTS, Firewall-Härtung, SSH-Konfiguration (SICH-07, X-20).
- Bankenstandard oder regulatorische Konformität der Gerätefreigabe (GERAET-01).
- Konkreter Passwort-Hash-Algorithmus (KONTO-05).
- Produktionskonfiguration (require_2fa, Hosts, Aufbewahrung), nur Standardwerte belegt (X-21).

## Was die Software ausdrücklich nicht tut

- Keine Verschlüsselung der im Portal erfassten IBAN (Klartext in customer_ibans.iban und iban_history). (Quelle: ["php-ionos/app/customer_settings.php:112-119", "php-ionos/sql/schema.sql:488-510"])
- Keine Sperre von Firmen ohne AVV-Zustimmung. (Quelle: ["docs/rechtsdokumente.md:175", "docs/rechtsdokumente.md:194"])
- Keine Hochverfügbarkeit, kein Failover, keine Replikation von Datenbank oder Redis. (Quelle: ["docs/vps/01-architektur.md:259-270", "deploy/vps/README.md:258-267"])
- Kein unabhängiger externer Erreichbarkeitsprüfer und kein unabhängiger Alarmkanal (vorbereitet, nicht aktiv). (Quelle: ["docs/status-page.md:149", "docs/status-page.md:211", "docs/monitoring.md:71"])
- Keine vorherige Zustimmung oder Freigabe des Kunden vor einem Support-Zugriff; nur Benachrichtigung und Protokoll. (Quelle: ["php-ionos/app/support.php:1-44", "php-ionos/support-login.php:392-420"])
- Keine Hardwarebindung der Gerätefreigabe; kein IP-Wechsel-Schutz. (Quelle: ["php-ionos/app/devices.php:13-15", "docs/device-trust.md:62-64"])
- Keine 2FA per SMS, E-Mail, Push, FIDO2/WebAuthn oder Passkeys; ausschließlich TOTP mit Recovery-Codes. (Quelle: ["php-ionos/app/totp.php:54-56", "php-ionos/app/auth.php:483-535"])
- Keine Änderung der Login-E-Mail-Adresse in der Anwendung. (Quelle: ["docs/device-trust.md:56"])
- Keine Rückschreibung von Zahlungen nach Lexware Office (kein Schreibzugriff auf das Buchhaltungssystem). (Quelle: ["docs/integrations.md:229", "php-ionos/app/legal_drafts.php:105"])
- Keine SEPA-Firmenlastschrift (B2B). (Quelle: ["docs/bestandsmatrix.md:41", "CLAUDE.md:25"])
- Kein HSTS-Header (bewusst auskommentiert, Freigabe der Geschäftsführung erforderlich). (Quelle: ["deploy/vps/README.md:299-303"])
- Keine automatische Löschfrist für login_attempts, funnel_events, Supporttickets und technische Laufprotokolle. (Quelle: ["docs/rechtsdokumente.md:184"])
- Keine Adminaktion zum Aufheben der Vier-Wochen-Sperre beim Wechsel des Buchhaltungssystems. (Quelle: ["docs/integrations.md:241"])
- Kein Versandwerkzeug für die sevdesk-Startnachricht an Vorgemerkte. (Quelle: ["docs/sevdesk.md:333-334"])
- Kein eigener Backup-Container oder Backup-Skript im Docker-Stack (Sicherung ausschließlich durch Coolify). (Quelle: ["deploy/vps/README.md:13", "docs/vps/01-architektur.md:76"])
- Keine eigene Datenbank je Kunde; eine gemeinsame Datenbank mit logischer Mandantentrennung. (Quelle: ["php-ionos/app/auth.php:78-107", "docs/vps/01-architektur.md:91-93"])
- Kein Docker-Socket in Anwendungscontainern; Container binden Code nur lesend ein. (Quelle: ["deploy/vps/README.md:253-256", "docs/vps/01-architektur.md:281-304"])
- Kein technisch unveränderliches oder dauerhaftes Audit-Protokoll (Löschung nach 90 Tagen, normale Datenbanktabelle). (Quelle: ["php-ionos/app/audit.php:352-372"])
- Keine SSO-, OAuth- oder SAML-Anmeldung (im gelesenen Code nur Passwort plus TOTP). (Quelle: ["php-ionos/app/auth.php:398-535", "php-ionos/login.php (grep ohne Treffer)"])
- Keine Nur-Lese-Rolle; Mitarbeiter haben vollen operativen Zugriff inklusive Einzügen. (Quelle: ["php-ionos/app/auth.php:6-12"])
- Keine Verfügbarkeitszusage in Prozent, kein SLA. (Quelle: ["websites/smart-einzug.de/agb/index.html:111-112"])
- Keine SEPA-Firmenlastschrift (B2B); ausschließlich SEPA-Basislastschrift über Stripe sepa_debit (php-ionos/app/stripe.php:414,484).
- Kein Stripe Connect, keine Zahlungsabwicklung über ein Konto der Plattform; jede Firma nutzt ihr eigenes Stripe-Konto (php-ionos/settings.php:271-272).
- Kein eigenes Online-Formular zur IBAN-Eingabe durch den Zahlungspflichtigen; digital nur über Stripe Checkout (php-ionos/mandat.php:118).
- Keine elektronische Signatur oder Unterschriftsprüfung; die Unterschrift wird nur mit Datum und Ort vermerkt (php-ionos/app/mandates.php:241-265).
- Keine inhaltliche Prüfung hochgeladener Mandatsdokumente (nur Dateityp, Größe, Integrität) (php-ionos/app/mandate_files.php:64-79).
- Keine Prüfung des Kontoinhabers gegen Bankdaten, kein Bankabgleich der IBAN; nur Prüfsumme und Länge für neun Länder (php-ionos/app/iban.php:12-54).
- Keine Einmalmandate über die Oberfläche; alle Mandate sind wiederkehrend (php-ionos/app/mandates.php:182).
- Keine automatische Übernahme eines Mandatswiderrufs durch Zahler oder Bank; eine Rücklastschrift ändert den Mandatsstatus nicht (php-ionos/stripe-webhook.php:276-288).
- Keine automatische Wiederholung fehlgeschlagener oder zurückgegebener Lastschriften, kein Dunning (php-ionos/app/collections.php:18,1495).
- Keine Auswertung des Stripe-Rückgabegrunds; jeder Dispute wird als 'vom Kunden widerrufen' beschriftet (php-ionos/stripe-webhook.php:277).
- Keine Erfassung oder Weiterberechnung von Stripe-, Rücklastschrift- oder Mahngebühren (keine Fundstelle in php-ionos/app).
- Keine Aussagen oder Daten zur Auszahlungsdauer, keine Payout- oder Balance-Abfragen (keine Fundstelle in php-ionos/app/stripe.php).
- Keine Auslösung von Erstattungen aus der Anwendung; Erstattungen werden nur aus Stripe übernommen (php-ionos/app/stripe.php ohne /refunds-Aufruf).
- Keine E-Mails an Zahlungspflichtige bei Erfolg, Fehlschlag, Rücklastschrift oder Erstattung; nur Vorabankündigung und Mandatsanforderung (php-ionos/app/collections.php:1197, php-ionos/app/mandate_requests.php:100).
- Keine E-Mail-Benachrichtigung an den Inhaber bei Rücklastschrift; nur Anzeige und Audit (php-ionos/app/alerts.php:329-411).
- Kein Import von Mandaten oder IBANs aus Stripe beim Einmal-Import (php-ionos/stripe-import.php:82).
- Keine Verschlüsselung der gespeicherten IBAN; Maskierung nur in Anzeige und Audit (php-ionos/app/customer_settings.php:111-119).
- Keine Prüfung, ob die Vorabankündigungsfrist eingehalten wurde, wenn die Firma die E-Mail-Vorabankündigung nicht aktiviert hat (php-ionos/app/collections.php:1262-1272).
- Keine Übermittlung der Gläubiger-ID der Firma an Stripe (php-ionos/app/collections.php:1156-1165).
- Keine Regelautomatik für Einzüge (nur Gerüst ohne Verarbeitung, docs/payment-safety.md:123-125).
- Kein Rückschreiben nach Lexware Office (keine Zahlungen, Notizen, Statusänderungen, Belege). (Quelle: php-ionos/app/lexoffice.php:80-266 (nur GET); docs/payment-safety.md:36)
- Keine automatische Zahlungszuordnung oder Verbuchung von Zahlungseingängen in Lexware Office. (Quelle: php-ionos/app/lexoffice.php:187-266)
- Keine automatische regelbasierte Freigabe von Einzügen; collection_rules ist nur Gerüst ohne Verarbeitung und Oberfläche. (Quelle: php-ionos/app/collection_rules.php:1-13, 100-116)
- Kein automatischer Neu-Einzug nach Rücklastschrift, fehlgeschlagener Lastschrift oder Erstattung; kein Mahnwesen. (Quelle: php-ionos/app/collections.php:800-864, 1587-1594, 1797-1805; php-ionos/stripe-webhook.php:276-287)
- Kein Datums-, Betrags- oder Mengenfilter beim Import; alle offenen und überfälligen Rechnungen werden übernommen. (Quelle: php-ionos/app/lexoffice.php:193-227; docs/payment-safety.md:101-105)
- Keine Höchst- oder Mindestbeträge je Einzug außerhalb des Tarifkontingents (Anzahl je Periode). (Quelle: php-ionos/app/plans.php:175-206)
- Keine Prüfung von Bank- oder Feiertagen bei der Terminierung, nur Montag bis Freitag. (Quelle: php-ionos/app/collections.php:1018-1020)
- Keine Vorabankündigung ohne aktivierte Firmeneinstellung send_pre_notification; keine Vorabankündigung für vorgemerkte Sofort-Einzüge. (Quelle: php-ionos/app/collections.php:1183-1198, 1376-1381; php-ionos/sql/schema.sql:68)
- Kein Abruf des Restbetrags während der Synchronisation; der gespeicherte Restbetrag stammt nur aus Einzugsversuchen. (Quelle: docs/payment-safety.md:36; php-ionos/app/sync.php (kein getPayment-Aufruf))
- Keine Lexware-Webhooks; Änderungen werden nur beim Synchronisationslauf erkannt. (Quelle: docs/sync-performance.md:200)
- Keine Erkennung von Rücklastschriften oder Erstattungen ohne eingerichteten Stripe-Webhook mit Secret. (Quelle: php-ionos/app/collections.php:1543-1544; php-ionos/app/alerts.php:71-78)
- Kein Zurückholen bereits bei Stripe eingereichter Lastschriften durch den Not-Stopp. (Quelle: php-ionos/notstopp.php:106, 129)
- Kein Einzug in anderer Währung als EUR. (Quelle: php-ionos/app/collections.php:354-356)
- Keine Einreichung durch den Plattformbetreiber im Support-Modus. (Quelle: php-ionos/app/collections.php:1211-1216)
- Keine Übernahme von Anschriften, Telefonnummern, Bankverbindungen oder Belegdokumenten aus Lexware Office. (Quelle: php-ionos/app/sync.php:577-581, 621-640)
- Kein DATEV- oder Excel-Export, nur CSV; keine Rückspielung des Journals nach Lexware Office. (Quelle: php-ionos/export.php:119-124, 291-294)
- Keine Tarifprüfung des Lexware-Kontos durch die Anwendung; nur der API-Schlüssel wird getestet. (Quelle: php-ionos/settings.php:240-241; php-ionos/app/lexoffice.php:187-191)
- Kein automatischer Synchronisationsstart ohne aktives Feature features.queue (Webhosting-Betrieb). (Quelle: php-ionos/cron.php:130-142; php-ionos/app/sync_state.php:267-315)
- Keine kostenlose Testphase, kein Probezeitraum, kein Trial im Abonnement (kein trial_period_days im Checkout, kein Trial-Feld in plans, kein Text dazu). (Quelle: ["php-ionos/app/billing.php:144-176", "php-ionos/sql/schema.sql:13-28"])
- Keine Monats- oder Jahresabrechnung und keine Jahresbindung; Periode ist period_days (28 Tage), Monats- oder Jahresintervalle meldet bin/billing-check.php als Fehler. (Quelle: ["php-ionos/app/billing_setup.php:155-165", "websites/smart-einzug.de/agb/index.html:106-107"])
- Kein sevdesk-Tarif, kein sevdesk-Preis, kein Kaufbutton und kein Firmenaccount für sevdesk vor der Freigabe. (Quelle: ["php-ionos/sql/schema.sql:30-37", "docs/integrations.md:66"])
- Kein funktionsfähiger sevdesk-Adapter: capabilities leer, jede fachliche Methode wirft; kein Verbindungstest, kein Rechnungsabruf, kein Einzug für sevdesk. (Quelle: ["php-ionos/app/sevdesk.php:90-143"])
- Keine Rückschreibung von Zahlungen in das Buchhaltungssystem (write_payment ist keine Pflichtfähigkeit, Lexware Office bietet keinen dokumentierten Endpunkt; für sevdesk nicht zugesagt, Schalter sevdesk_writeback Standard 0). (Quelle: ["docs/integrations.md:37", "docs/sevdesk.md:160", "php-ionos/app/integration_state.php:104-106"])
- Kein Versandwerkzeug für die sevdesk-Startnachricht an vorgemerkte Interessenten. (Quelle: ["docs/sevdesk.md:155", "docs/sevdesk.md:194-195", "docs/integrations.md:83"])
- Keine Speicherung von IP-Adressen bei der Vormerkung. (Quelle: ["php-ionos/app/interest.php:20-22", "php-ionos/vormerken.php:14"])
- Keine Tarifwahl, kein Tarifwechsel und kein Upsell, solange die Abrechnung nicht freigeschaltet ist oder weniger als zwei Tarife aktiv und öffentlich sind. (Quelle: ["php-ionos/app/plans.php:222-226", "php-ionos/app/billing.php:49-51", "php-ionos/app/billing.php:110-112"])
- Kein Stripe Connect und kein Sammelkonto; Einzüge laufen über das eigene Stripe-Konto der Firma, die Plattform-Abrechnung über das getrennte Konto der Müller Holding AG. (Quelle: ["docs/sevdesk.md:149-150", "php-ionos/app/billing.php:1-9"])
- Keine Erhebung von Zahlungsdaten bei der Registrierung; Zahlungsdaten verbleiben bei Stripe. (Quelle: ["php-ionos/register.php:147-204", "php-ionos/subscription.php:237"])
- Kein Abschluss des Abonnements durch Mitarbeiter oder Administratoren; nur der Inhaber (require_owner) kann abschließen, kündigen und den Tarif wechseln. (Quelle: ["php-ionos/subscription.php:12", "php-ionos/app/layout.php:164-168", "php-ionos/onboarding.php:40-42"])
- Keine automatische Kündigung bestehender Stripe-Abonnements beim Zurücknehmen von billing.enabled; sie laufen weiter und müssten im Stripe-Dashboard gekündigt werden. (Quelle: ["docs/abrechnung.md:121-125"])
- Keine Bestellbestätigung per E-Mail durch die Anwendung; die Anwendung protokolliert die Bestätigung im Audit, Rechnungen stellt Stripe aus. (Quelle: ["php-ionos/app/billing.php:377-390", "php-ionos/subscription.php:289"])
- Keine automatischen Einzugsregeln; Regeln sind laut Hilfe nur als Vorschau vorbereitet und lösen keine Einzüge aus. (Quelle: ["php-ionos/app/help_content.php:113-114"])
- Kein sevdesk-Bezug im Hilfe-Center und im Onboarding; beide nennen ausschließlich Lexware Office. (Quelle: ["php-ionos/app/help_content.php:13-31", "php-ionos/onboarding.php:45-76"])

## Dokumentierte Vorgaben des Betreibers mit Bezug zu den Marketingseiten

- **Preisdarstellung** Tarif UNLIMITED START, Einführungspreis 25,00 EUR netto je 4 Wochen (bisher 50,00 EUR), alle Preise netto zzgl. USt., unbegrenzte Einzüge. Der Stichtag ist rollierend ('bis zum Ende des laufenden Kalendermonats'), nie ein festes Datum in einer Preisangabe; Berechnung über intro_price_deadline() in app/pricing.php; php tools/pricing-check.php erzwingt das. Weitere Tarife nur über die Tabelle plans; Preise nie im Frontend fest verdrahten. (Quelle: ["CLAUDE.md:24", "docs/ARBEITSSTAND.md:20-21", "php-ionos/register.php:496-500"], 07.09.2026)
- **Leadseiten DETM Management Consulting FZCO** Die Leadseiten lexware-einzug.de und lexoffice-einzug.de sollen auf DETM Management Consulting FZCO laufen (eigenständige Leadseiten ohne SmartEinzug-Logo, Provision nach Herkunft, Messung über signup_domain). Impressumsdaten (Anschrift, Registerangaben, vertretungsberechtigte Person, E-Mail, Telefon) fehlen; nichts erfinden. Umsetzung blockiert bis Impressumsdaten und Entscheidung zum Provisionsnachweis vorliegen. (Quelle: ["docs/ARBEITSSTAND.md:26-27", "docs/ARBEITSSTAND.md:148-150", "docs/ARBEITSSTAND.md:154", "docs/integrations.md:265"], 07.09.2026)
- **sevdesk: Datum und Formulierung** Öffentlich gilt 'in Vorbereitung, Start für Ende September 2026 geplant', nie als Zusage; das Datum ist kein Freigabekriterium. Zu sevdesk-Tarifen nur: 'nach der offiziellen sevdesk-Hilfe Tarif Buchhaltung Pro, Systemversion 2.0, Prüfung beim Verbindungstest'. Stripe unterstützt SEPA-Basislastschrift (Core), nicht B2B. Keine Partnerschafts- oder Zertifizierungsbehauptung, keine Rückschreibung zusagen, keine Preise, kein Kaufbutton, kein Firmenaccount vor Freigabe; nur kostenlose Vormerkung mit Double-Opt-in. Freigabeschalter nur serverseitig. (Quelle: ["CLAUDE.md:25", "docs/ARBEITSSTAND.md:22-25", "docs/integrations.md:245-258", "docs/sevdesk.md:279-281", "docs/sevdesk.md:298"], 07.09.2026)
- **Wettbewerbervergleich nur nach Freigabe** Wettbewerber-Anzeigengruppen (SEPAHeld, GoCardless) bleiben bis zur ausdrücklichen Freigabe durch die Geschäftsführung pausiert; keine Wettbewerbernamen im Anzeigentext, keine Superlative. Die Vergleichsseite /vergleich/sepaheld-gocardless/ steht auf noindex, follow. (Quelle: ["docs/ads-conversions.md:14-17", "docs/ads-conversions.md:43", "docs/ads-conversions.md:66", "docs/ads-conversions.md:144", "docs/smarteinzug-qa.md:101", "websites/smart-einzug.de/vergleich/sepaheld-gocardless/index.html:7"], 05.09.2026)
- **Markenregeln SmartEinzug** Produktname SmartEinzug; Bildmarke Euro auf Anthrazit; Farben Gold #E3AC48, Anthrazit #2E2D2E, Beige #FBF6EC, Grau #9F9F9F; Schriftstapel Carlito, Calibri, Segoe UI, Arial; Schutzraum, Mindestgrößen, Alt-Texte; technische Kennungen (lexoffice_*, Session-Name) bleiben. Das Dokument ist keine Markenrechtsfreigabe; die markenrechtliche Prüfung des Namens steht aus. Markenhinweis zu Lexware, Lexware Office, lexoffice und Stripe beachten; 'Kein Produkt der Haufe-Lexware GmbH & Co. KG'; Lexware-Aussagen (Tarif XL) vorsichtig formulieren. (Quelle: ["docs/smarteinzug-brand.md:169-222", "docs/bestandsmatrix.md:19-23", "php-ionos/register.php:432", "php-ionos/register.php:500", "CLAUDE.md:23", "docs/integrations.md:215"], 05.09.2026)
- **Impressumsdaten Müller Holding AG** Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein, Sitz Monheim am Rhein, Amtsgericht Düsseldorf, HRB 104291, Vorstand Timo Müller, Aufsichtsratsvorsitzender Jan Walprecht, kontakt@mueller-holding.ag, mueller-holding.ag; Telefon und USt-IdNr. Platzhalter. E-Mails tragen die Pflichtangaben nach § 80 AktG im Fußband (mail_layout()). (Quelle: ["websites/smart-einzug.de/impressum/index.html:68-110", "php-ionos/app/legal_drafts.php:42", "CLAUDE.md (Abschnitt E-Mails, mail_layout)"], 07.09.2026)
- **Sprache und Stil** Deutsch, Sie-Form, kaufmännisch, keine Gedankenstriche, Datum TT.MM.JJJJ, Beträge 1.234,56 EUR; keine erfundenen Fakten, Fristen, Paragraphen oder Zahlen. (Quelle: ["CLAUDE.md:22-23"], 07.09.2026)
- **Marketingseiten QA** Inhalte der beiden Domains dürfen sich nicht gleichen (QA-Grenze 35 Prozent), nur Self-Canonicals, python3 tools/site-qa.py muss 0 Fehler liefern, danach tools/build-sitemaps.py. (Quelle: ["CLAUDE.md:29", "docs/ARBEITSSTAND.md:75"], 07.09.2026)
- **Google-Tags und Einwilligung** GA4 und Ads nur hinter der Einwilligung in assets/js/site.js, niemals direkt im HTML; für smart-einzug.de ist keine Google-Ads-Kennung hinterlegt; Conversion-ID und Label fehlen noch. (Quelle: ["CLAUDE.md:31", "docs/ads-conversions.md:136-141", "websites/smart-einzug.de/datenschutz/index.html:128"], 05.09.2026)
- **Firmenlastschrift (B2B)** Wird nicht angeboten und nicht beworben; Stripe unterstützt nur SEPA-Basislastschrift (Core). (Quelle: ["docs/bestandsmatrix.md:41", "docs/smarteinzug-qa.md:90", "CLAUDE.md:25"], 05.09.2026)
- **Rückschreibung nach Lexware Office** Nicht bauen und nicht zusagen (kein dokumentierter Schreibendpunkt); stattdessen Ratgeber zur manuellen Zuordnung. (Quelle: ["docs/bestandsmatrix.md:39", "docs/integrations.md:229", "docs/smarteinzug-qa.md:88"], 05.09.2026)
- **Statusseite und Footer-Link** Footer-Link 'Systemstatus' auf der Hauptwebsite erst nach Inbetriebnahme der Statusseite eintragen; Hosting-Entscheidung (Repository-Release auf dem VPS umgesetzt); externer Prüfer optional, nicht eingerichtet. (Quelle: ["docs/status-page.md:118-121", "docs/status-page.md:151-169", "docs/auftrag-ii-abschluss.md:187-191"], 06.09.2026)
- **Rechtsdokumente veröffentlichen** AVV und Verschwiegenheitsvereinbarung nur nach anwaltlicher Prüfung veröffentlichen; Texte nur aus app/legal_drafts.php oder admin-legal.php (Superadmin, 2FA); Anlage 1 muss dem Code entsprechen; Datenschutztext 3a nennt Dienstleister nur generisch, Rechtsprüfung empfohlen. (Quelle: ["CLAUDE.md:28", "docs/rechtsdokumente.md:170-178", "docs/ARBEITSSTAND.md:90-92", "docs/ARBEITSSTAND.md:139-142"], 07.09.2026)
- **Betriebsvorgaben mit Außenwirkung** Keine produktiven Serveraktionen durch die Session; jeder Push löst ein Produktionsdeployment aus; Deployment ausschließlich über den GitHub-Workflow; Einreichfenster 23:00 bis 06:00 gilt nur für das Einreichen von Lastschriften. (Quelle: ["docs/ARBEITSSTAND.md:9-19"], 07.09.2026)

## Als offen oder ungetestet dokumentierte Punkte mit Bezug zu öffentlichen Aussagen

- Statusseite: Wirksamkeit von status_publish (Daten statt Platzhalter) nicht bestätigt; zwei versehentlich erzeugte leere Dateien auf dem Server. (Quelle: ["docs/ARBEITSSTAND.md:93-96"])
- Externer Uptime-Check nicht eingerichtet; Repository-Variable WEBHOSTING_APP_DEPLOY ungeprüft. (Quelle: ["docs/ARBEITSSTAND.md:98", "docs/status-page.md:149", "docs/monitoring.md:50"])
- AVV und Verschwiegenheitsvereinbarung: anwaltliche Prüfung offen, keine Fassung veröffentlicht (aus dem Code nicht belegbar); Datenschutzerklärungen enthalten Platzhalter zum AVV. (Quelle: ["docs/rechtsdokumente.md:190-195", "websites/smart-einzug.de/datenschutz/index.html:106", "websites/lexware-einzug.de/datenschutz.html:113"])
- Hostinganbieter (Firma, Anschrift, Serverstandort) und Sicherungsspeicher für Anlage 3 fehlen. (Quelle: ["docs/rechtsdokumente.md:193", "php-ionos/app/legal_drafts.php:157-158"])
- lexoffice-einzug.de zeigte live am 07.09.2026 noch das feste Datum 31.12.2026; Stand des IONOS-Webhostings und des Jobs deploy-webhosting nicht einsehbar. (Quelle: ["docs/ARBEITSSTAND.md:102-104", "docs/sevdesk.md:297"])
- DETM Management Consulting FZCO: Impressumsdaten und Provisionsnachweis fehlen; Leadseiten blockiert. (Quelle: ["docs/ARBEITSSTAND.md:148-150", "docs/ARBEITSSTAND.md:154"])
- Markenrechtliche Prüfung des Namens SmartEinzug steht aus. (Quelle: ["docs/smarteinzug-brand.md:215-222"])
- Freigabe der Wettbewerber-Anzeigengruppen durch die Geschäftsführung steht aus. (Quelle: ["docs/ads-conversions.md:144", "docs/smarteinzug-qa.md:101"])
- Google-Ads-Conversion-ID und Label fehlen; Keyword-Liste ist ein Entwurf ohne Auftragstext 'Abschnitt 25'; Suchvolumina nicht prüfbar. (Quelle: ["docs/ads-conversions.md:3", "docs/ads-conversions.md:138-143"])
- DNS, Zertifikate und Ordnerzuordnung der neun Hosts bei IONOS nicht aus dem Repository prüfbar; Redirect-Matrix der Aliase ungetestet. (Quelle: ["docs/smarteinzug-rollout.md:160-164", "docs/smarteinzug-qa.md:96-98", "docs/bestandsmatrix.md:54"])
- Impressum: Telefon und USt-IdNr. als Platzhalter; Satz 'Ein Umzug der Anwendung auf app.smart-einzug.de ist vorgesehen' ist selbstbezüglich und nach dem VPS-Cutover zu prüfen. (Quelle: ["websites/smart-einzug.de/impressum/index.html:100-104", "websites/smart-einzug.de/impressum/index.html:115"])
- Mailversand seit 07.09.2026 aktiv; Ankunft der Testmail und Nachsendung der Bestätigungsmail vom Betreiber zu bestätigen. (Quelle: ["docs/ARBEITSSTAND.md:108-115", "docs/ARBEITSSTAND.md:137-138"])
- E2E-Suite scratchpad/e2e_saas.php in der aktuellen Session nicht ausgeführt (unsicher, ob mit Migrationen 020 bis 024 grün). (Quelle: ["docs/ARBEITSSTAND.md:80-82"])
- Restore-Test der Sicherung: vom Betreiber bestätigt, aber ohne Testdatum, Zielinstanz, geprüfte Tabellen und Prüfsummen dokumentiert. (Quelle: ["docs/betrieb-migration-vps.md:626"])
- sevdesk-Startformulierung uneinheitlich ('Ende September 2026' vs. '30.09.2026'); sevdesk-Testkonto fehlt (Blocker für Adapter). (Quelle: ["CLAUDE.md:25", "docs/ARBEITSSTAND.md:24", "docs/integrations.md:258", "docs/sevdesk.md:343"])
- Nicht getestet: TLS-Prüfung gegen echte Hosts, SMTP- und Stripe-Störungen mit echten Diensten, Alarmmails, Gerätefreigabe auf getrenntem Admin-Host, Statusseite auf echter Domain. (Quelle: ["docs/monitoring.md:107", "docs/auftrag-ii-abschluss.md:185", "docs/auftrag-iii-abschluss.md:100"])
- IBAN im Klartext gespeichert, AVV-Entwurf behauptet Verschlüsselung; Protokoll-Löschung nach 90 Tagen, AVV-Entwurf behauptet Nichtlöschung und Unveränderlichkeit. (Quelle: ["php-ionos/app/customer_settings.php:112-119", "php-ionos/app/legal_drafts.php:121", "php-ionos/app/legal_drafts.php:136", "php-ionos/app/legal_drafts.php:149-150", "php-ionos/app/audit.php:352-372"])
- Sperre von Firmen ohne AVV-Zustimmung nach Übergangsfrist: Entscheidung der Geschäftsführung offen. (Quelle: ["docs/rechtsdokumente.md:175", "docs/rechtsdokumente.md:194"])
- docs/monitoring.md beschreibt noch das IONOS-Umfeld (externer Cron, keine Einsicht in Host-Kennzahlen); auf dem VPS gelten Scheduler, Worker und Host-Metriken. (Quelle: ["docs/monitoring.md:15", "docs/vps/01-architektur.md:72-77", "docs/ARBEITSSTAND.md:131-133"])
- HSTS bewusst nicht aktiv; ufw/DOCKER-USER-Zusammenspiel nicht bestätigt; Hostinger-Firewall nach SSH-Ausfällen zu prüfen. (Quelle: ["deploy/vps/README.md:280-303", "docs/ARBEITSSTAND.md:120-125", "docs/ARBEITSSTAND.md:151-153"])
- Produktionswerte von require_2fa, allowed_hosts, admin_base_url, audit.retention_days, status_page_url und mail.enabled liegen nur in shared/config.php auf dem Server, nicht im Repository. (Quelle: ["php-ionos/app/config.example.php:72-134", "php-ionos/app/bootstrap.php:16-23"])
- Adminaktion zum Aufheben der Vier-Wochen-Sperre beim Systemwechsel fehlt (nur per Datenbank). (Quelle: ["docs/integrations.md:241", "docs/ARBEITSSTAND.md:145-146"])

## Offene Fragen an den Betreiber

- [konto-sicherheit-doku] In welchem Land beziehungsweise Rechenzentrum steht der Hostinger-VPS (Vertragsangabe), und in welcher Region liegt der Hetzner-Object-Storage-Bucket 'smarteinzug' (Endpoint fsn1)? Beides wird für Anlage 3 des AVV und jede Standortaussage benötigt.
- [konto-sicherheit-doku] Welche Werte hat die produktive shared/config.php für require_2fa, allowed_hosts, admin_base_url, audit.retention_days, status_page_url, status_publish und mail.enabled?
- [konto-sicherheit-doku] Ist produktiv eine Fassung des AVV oder der Verschwiegenheitsvereinbarung veröffentlicht (legal_documents.published_at)? Wann ist die anwaltliche Prüfung geplant?
- [konto-sicherheit-doku] Soll die IBAN künftig verschlüsselt gespeichert werden (Codeänderung, Migration) oder werden Anlage 1 B und Anlage 2 des AVV-Entwurfs an den Klartext angepasst?
- [konto-sicherheit-doku] Sollen Anlage 1 D und Anlage 2 ('wird nicht gelöscht', 'unveränderliches Protokoll') an die 90-Tage-Löschung angepasst werden, oder soll die Aufbewahrung verlängert werden?
- [konto-sicherheit-doku] Support-Zugriff: Bleibt es bei Benachrichtigung und Protokoll, oder soll eine vorherige Freigabe durch den Inhaber eingeführt werden? Das bestimmt die zulässige öffentliche Formulierung.
- [konto-sicherheit-doku] Welche sevdesk-Formulierung ist verbindlich: 'Start für Ende September 2026 geplant' oder 'geplant zum 30.09.2026'?
- [konto-sicherheit-doku] DETM Management Consulting FZCO: vollständige Anschrift, Registerangaben, vertretungsberechtigte Person, E-Mail, Telefon; Entscheidung zum Provisionsnachweis je Herkunftsdomain.
- [tracking] Auf lexware-einzug.de (Leadseite der DETM Management Consulting FZCO) läuft seit 12.09.2026 das Google-Ads-Tag des Kontos der Müller Holding AG. Damit bestimmen zwei Gesellschaften gemeinsam über Zweck und Mittel dieser Verarbeitung. Ob das eine gemeinsame Verantwortlichkeit nach Art. 26 DSGVO begründet und eine Vereinbarung darüber nötig ist, ist eine Rechtsfrage und anwaltlich zu klären; die Datenschutzerklärung der Domain nennt bislang allein DETM als Verantwortliche. Bis zur Klärung keine Aussage dazu auf den Seiten treffen.
- [konto-sicherheit-doku] Zeigt status.smart-einzug.de inzwischen echte Daten? Soll ein externer Prüfer (z. B. cron-job.org auf health.php) eingerichtet werden?
- [konto-sicherheit-doku] Liegen Telefonnummer und USt-IdNr. der Müller Holding AG für das Impressum vor, oder bleiben die Felder bewusst leer?
- [konto-sicherheit-doku] Wurde die markenrechtliche Prüfung des Namens SmartEinzug beauftragt oder abgeschlossen?
- [konto-sicherheit-doku] Ist der Wettbewerbervergleich (SEPAHeld, GoCardless) freigegeben oder bleibt er noindex und unbeworben?
- [konto-sicherheit-doku] Ist die Aussage 'unbegrenzte Mitarbeiter' durch die produktive plans-Zeile (seats_limit null) gedeckt?
- [konto-sicherheit-doku] Wurde der Restore aus dem Hetzner-Bucket mit dokumentiertem Datum, Zielinstanz und Prüfsummen wiederholt? Soll dies für Sicherheitsaussagen dokumentiert werden?
- [konto-sicherheit-doku] Ist die Lexware-Angabe 'Tarif XL für die Public API erforderlich' weiterhin aktuell (Prüfung bei Lexware)?
- [konto-sicherheit-doku] Wurde die E2E-Suite gegen den Stand 4.31 ausgeführt?
- [mandate-stripe] Ist der Feature-Schalter features.mandate_request produktiv gesetzt? Die Konfigurationsdatei liegt nicht im Repository; ohne Schalter darf die digitale Mandatsanforderung nicht als Funktion beworben werden.
- [mandate-stripe] Ist config 'stripe_mandate_reference_prefix' produktiv aktiv, und wurde der laut config.example.php:168-169 geforderte Test-Einzug durchgeführt?
- [mandate-stripe] Reicht der in settings.php:349 empfohlene Restricted Key (Customers, Payment Methods, Payment Intents schreiben; Charges, Disputes lesen) für GET /v1/mandates, /v1/customers/search, /v1/payment_intents/search, /v1/setup_intents und POST /v1/checkout/sessions?
- [mandate-stripe] Soll ein hochgeladenes Mandat ohne erfasste Unterschrift das Mandat freigeben (Beschriftung team.php:462) oder bleibt die Prüfung auf signed_date (mandates.php:313-316)?
- [mandate-stripe] Ist der Hinweistext settings.php:280 ('Rücklastschriften werden nur beim manuellen Abgleich erkannt') gewollt, obwohl sync_collection_statuses nur PaymentIntents im Status processing prüft?
- [mandate-stripe] Welche Gläubiger-Identifikationsnummer und welche Mandatsreferenz erscheinen tatsächlich auf dem Kontoauszug des Zahlers (Stripe Payments Europe)? Aus dem Code nur als Möglichkeit formuliert.
- [mandate-stripe] Wurde die Prüfung der Mandatstexte, des Datenschutzhinweises auf mandat.php und des Bundesbank-Hinweises in team.php durch die Rechtsberatung abgeschlossen (docs/payment-safety.md:141)?
- [mandate-stripe] Wurde der externe Test einer echten Erstattung im Stripe-Testmodus (docs/payment-safety.md:137) durchgeführt?
- [mandate-stripe] Ist die Stripe Search API im Konto der Firmen verfügbar (docs/payment-safety.md:138)? Ohne sie bleibt die Klärung unklarer Versuche manuell.
- [mandate-stripe] Ist die Klartextspeicherung der IBAN in customer_ibans gewollt, oder soll eine Verschlüsselung ergänzt werden?
- [mandate-stripe] Versendet Stripe bei Checkout im Modus setup eigene Mandatsbestätigungen an den Zahler? Aus dem Repository nicht beurteilbar, für Marketingaussagen zur Kundenkommunikation relevant.
- [sync-einzug] Ist features.queue in der Produktionskonfiguration aktiv, sodass der automatische Delta-Abgleich (Standard alle 6 Stunden) und der nächtliche Vollabgleich (Standard 3 Uhr) tatsächlich laufen? Welche Werte haben queue.auto_sync_hours und queue.full_sync_hour?
- [sync-einzug] Welche Werte haben collections.grace_hours, window_start, window_end und overdue_days in Produktion (Standard 4 Stunden, 23:00, 06:00, 3 Tage)?
- [sync-einzug] Ist mail.enabled in Produktion aktiv? Ohne Mailversand werden weder Vorabankündigungen noch Alarm-E-Mails versendet und die Terminierung mit aktiver Vorabankündigung wird abgelehnt.
- [sync-einzug] Wurde der Lexware-Payments-Endpunkt (Felder openAmount, paymentStatus, paidDate) inzwischen mit einem echten Beleg im Testkonto verifiziert (docs/payment-safety.md:133)?
- [sync-einzug] Wurde die Annahme von 2 Anfragen je Sekunde je API-Schlüssel und das Feld updatedDate in der Voucherliste gegen die aktuelle Lexware-Dokumentation geprüft (docs/sync-performance.md:197, 208)?
- [sync-einzug] Welche Lexware-Tarife setzen die Public API aktuell voraus? Der Hinweistext in settings.php:240-241 nennt 'derzeit Tarif Lexware Office XL' nach Angaben von Lexware; für öffentliche Aussagen ist eine aktuelle Quelle nötig.
- [sync-einzug] Ist die Stripe Search API in den Stripe-Konten der Firmen verfügbar (Voraussetzung für die automatische Klärung unklarer Versuche, docs/payment-safety.md:138)?
- [sync-einzug] Wurden die im Code als 'Stand der Recherche' gekennzeichneten SEPA-Regeln (36 Monate Mandatsverfall, 14 Tage Vorabankündigung, Aufbewahrung) und die Mandats- und Datenschutztexte anwaltlich geprüft (mandates.php:5-6, docs/payment-safety.md:141)?
- [sync-einzug] Soll die Regelautomatik (collection_rules) und die digitale Mandatsanforderung (features.mandate_request) öffentlich als geplant erwähnt werden oder gar nicht?
- [sync-einzug] Wie viele der veröffentlichten Firmen-Webhooks bei Stripe liefern die Ereignisse charge.refunded und charge.refund.updated (Voraussetzung für Erstattungserkennung, docs/payment-safety.md:78)?
- [tarife-registrierung-sevdesk] Ist billing.enabled in der Produktionskonfiguration gesetzt, mit welcher Schlüsselart (test oder live), und sind Produkt, Preis (plans.stripe_price_id) und Webhook im Stripe-Konto der Müller Holding AG angelegt? Ergebnis von bin/billing-check.php?
- [tarife-registrierung-sevdesk] Wie lautet der aktuelle Inhalt der Tabelle plans in Produktion (Preis, active, public_visible, stripe_price_id je Tarif)? Der Superadmin kann die Startdaten geändert haben.
- [tarife-registrierung-sevdesk] Woher stammt der Vergleichspreis "bisher 50,00 EUR" für UNLIMITED START? In plans gibt es dafür keinen Datensatz. Wurde dieser Preis tatsächlich verlangt (Voraussetzung für Streichpreiswerbung, Rechtsanwalt)?
- [tarife-registrierung-sevdesk] Ist config agb_version in Produktion gesetzt? Ohne den Wert protokolliert die Bestellbestätigung nur "AGB smart-einzug.de, Stand <Tagesdatum>".
- [tarife-registrierung-sevdesk] Welchen Stand haben die sevdesk-Schalter in platform_settings der Produktion (sevdesk_public_state, sevdesk_waitlist, sevdesk_connect, sevdesk_collections, sevdesk_writeback)?
- [tarife-registrierung-sevdesk] Welche Terminformulierung ist verbindlich: "Start geplant zum 30.09.2026" (docs/integrations.md:66, docs/ARBEITSSTAND.md:24) oder "Start für Ende September 2026 geplant" (Landingpage, docs/sevdesk.md)? Hat die Geschäftsführung den Termin freigegeben?
- [tarife-registrierung-sevdesk] Sind im Stripe-Plattformkonto Promotion Codes angelegt (Checkout erlaubt allow_promotion_codes)? Welche Zahlungsarten (SEPA-Lastschrift, Karte) sind für den Checkout aktiviert?
- [tarife-registrierung-sevdesk] Soll der Hinweistext "Ein Tarifwechsel erfolgt über den Support" (subscription.php:312) bleiben, obwohl der Online-Wechsel implementiert ist?
- [tarife-registrierung-sevdesk] Sind die festen Preisangaben in register.php:210, onboarding.php:40 und help_content.php:208 und 243 bewusst nicht aus der Tabelle plans gelesen (Regel "Preise nie im Frontend fest verdrahten")?
- [tarife-registrierung-sevdesk] Marketingseiten: Die geänderte Arbeitskopie von tools/pricing-check.php (Abschnitt D, nicht committet) verlangt, dass websites/ keine Produktpreise nennt, während websites/*/index.html und preise/index.html derzeit 25,00 EUR und 50,00 EUR nennen. Liegt die Freigabe der Geschäftsführung zur Preisdarstellung vor (docs/seo/README.md:24)?
- [tarife-registrierung-sevdesk] Registrierung ohne veröffentlichten AVV: Der Code fragt den AVV nur ab, wenn eine Fassung veröffentlicht ist (register.php:32, 43-44); CLAUDE.md sagt "Registrierung nur bei veröffentlichtem AVV". Ist eine Registrierung ohne AVV gewollt, oder soll die Registrierung dann gesperrt sein?
- [tarife-registrierung-sevdesk] Ist mail.enabled in Produktion aktiv? Ohne Versand bleiben Willkommens- und Bestätigungsmails wartend und die Adresse gilt bei Registrierung sofort als bestätigt.
- [tarife-registrierung-sevdesk] Die Leistungsbeschreibung in Bestellübersicht (subscription.php:110), Registrierungsseite (register.php:137) und Onboarding nennt fest Lexware Office. Soll sie vor einer sevdesk-Freigabe auf das Buchhaltungssystem der Firma umgestellt werden?
- [tarife-registrierung-sevdesk] Wurden seit Version 4.14 die Marketingseiten auf dem IONOS-Webhosting neu ausgeliefert? docs/sevdesk.md:158 meldet, dass lexoffice-einzug.de am 07.09.2026 live noch "31.12.2026" zeigte.
