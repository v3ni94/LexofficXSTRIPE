# Datenwörterbuch (alle Anwendungstabellen)

Erzeugt aus `php-ionos/sql/schema.sql` durch `tools/gen-datenwoerterbuch.py`; fachliche Angaben aus `docs/entwickler/tabellen-beschreibungen.json`. 53 Tabellen. Datenbank: MariaDB (Coolify-MariaDB auf dem VPS; Zeichensatz utf8mb4, Kollation utf8mb4_unicode_ci laut Tabellendefinitionen). Zeitangaben: DATETIME ohne Zeitzone; die Anwendung schreibt teils UTC (UTC_TIMESTAMP(), Kommentar UTC) und teils Serverzeit (NOW(), CURRENT_TIMESTAMP, Zeitzone des Containers TZ=Europe/Berlin), siehe Spaltenkommentare. Geldbeträge: Cent als INT (`*_cents`) oder DECIMAL(10,2) in EUR, siehe Spaltentyp.

Legende: PK Primärschlüssel, FK Fremdschlüssel (von der Datenbank erzwungen), UQ eindeutig, IX Index. Beziehungen ohne FK-Eintrag werden nur durch Anwendungscode gesichert.

## Übersicht

@@diagramm 05-datenbank-uebersicht

@@diagramm 06-er-kern

| Tabelle | Zweck | Modul | Mandantenspalte | Spalten | FK |
|---|---|---|---|---|---|
| [plans](#plans) | Tarifkatalog der Plattformabrechnung (Abonnement der Firmenaccounts bei der Müller Holding AG). Legt Preis, Periode und Limits je Tarifcode fest (Kommentar schema.sql Zeile 11: 'Tarife (Limits kommen ausschliesslich aus dieser Tabelle)'). | Konten und Firmen / Plattform-Abrechnung | keine (plattformweit, ein Datensatz je Tarifcode) | 14 | 0 |
| [organizations](#organizations) | Firmenaccount (Mandant) des Portals. Traeger von Tarif, Abrechnungsstatus, SEPA-Stammdaten (Glaeubiger-ID, Mandatspraefix) und Firmenadresse. | Konten und Firmen | id (die Tabelle IST der Mandant) | 38 | 0 |
| [users](#users) | Persoenlicher Benutzerzugang (Login), unabhaengig von der Firmenzugehoerigkeit. Verpflichtende Zwei-Faktor-Authentifizierung (TOTP). | Konten und Firmen / Anmeldung | keine (Benutzer koennen laut multiaccount_enabled mehreren Firmen ueber organization_members zugeordnet sein) | 29 | 0 |
| [user_recovery_codes](#user-recovery-codes) | Einmal-Wiederherstellungscodes fuer den Fall eines verlorenen TOTP-Geraets. | Konten und Firmen / Anmeldung | keine direkt; ueber user_id an users und damit indirekt an dessen Firmen gebunden | 5 | 1 |
| [login_attempts](#login-attempts) | Protokoll aller Anmeldeversuche (Passwort, TOTP, Wiederherstellungscode) fuer Sperrlogik und Missbrauchserkennung. | Konten und Firmen / Anmeldung | keine (plattformweit, nur nach E-Mail und IP) | 6 | 0 |
| [audit_log](#audit-log) | Revisionssicheres Protokoll geldrelevanter und sicherheitsrelevanter Aktionen (CLAUDE.md: 'jede geldrelevante Aktion in audit_log'). | Sicherheit und Nachvollziehbarkeit | tenant_id | 10 | 0 |
| [organization_members](#organization-members) | Zuordnung Benutzer zu Firma mit Rolle (Mitgliedschaftstabelle, ermoeglicht Mehrfirmen-Zugehoerigkeit). | Konten und Firmen | organization_id | 7 | 2 |
| [invitations](#invitations) | Einladungen weiterer Benutzer in eine Firma per Link. | Konten und Firmen | organization_id | 14 | 1 |
| [registration_requests](#registration-requests) | Zwischenspeicher fuer Firmendaten, wenn sich eine bereits bekannte E-Mail-Adresse ein zweites Mal registriert, bis der bestehende Benutzer sich anmeldet und die Zweitfirma bestaetigt (Migration 015). | Konten und Firmen / Anmeldung | keine eigene tenant_id vor Abschluss; user_id bindet an den bestehenden Benutzer, created_org_id verweist nach Abschluss auf die neue Firma | 10 | 1 |
| [trusted_devices](#trusted-devices) | Vertrauenswuerdige Geraete, auf denen die 2FA-Abfrage fuer eine begrenzte Zeit uebersprungen werden kann (Migration 016). | Konten und Firmen / Anmeldung | keine direkt; user_id bindet an den Benutzer | 12 | 1 |
| [job_runs](#job-runs) | Historie/Monitoring aller Hintergrundlaeufe (Cron, Synchronisation, Einzuege, Monitoring) mit Kennzahlen (Migration 017). | Monitoring und Betrieb | tenant_id | 19 | 0 |
| [monitor_checks](#monitor-checks) | Einzelne Gesundheitspruefungen/Messpunkte je Komponente (Migration 017), Grundlage der Statusseite. | Monitoring und Betrieb | keine (plattformweit, component-bezogen) | 10 | 0 |
| [monitor_daily](#monitor-daily) | Tagesaggregat der Verfuegbarkeit je Komponente (Sekunden ok/eingeschraenkt/ausgefallen/unbekannt), Grundlage der oeffentlichen Statusseite. | Monitoring und Betrieb | keine (plattformweit) | 9 | 0 |
| [monitor_requests](#monitor-requests) | Minutengenaue Kennzahlen aller eingehenden Web-Anfragen (Anzahl, 5xx-Fehler, Antwortzeiten) fuer die Statusseite. | Monitoring und Betrieb | keine (plattformweit) | 5 | 0 |
| [monitor_incidents](#monitor-incidents) | Stoerungen und geplante Wartungen fuer die oeffentliche Statusseite und interne Nachverfolgung. | Monitoring und Betrieb | keine (plattformweit) | 15 | 0 |
| [monitor_incident_updates](#monitor-incident-updates) | Verlaufsmeldungen (Updates) zu einer Stoerung/Wartung aus monitor_incidents. | Monitoring und Betrieb | keine (ueber incident_id an monitor_incidents gebunden, dort plattformweit) | 7 | 1 |
| [jobs](#jobs) | Warteschlange fuer Hintergrundverarbeitung (Feature-Flag features.queue), verarbeitet von Worker-Prozessen auf dem VPS bzw. inline im Cron. | Hintergrundverarbeitung / Warteschlange | tenant_id | 24 | 0 |
| [worker_heartbeats](#worker-heartbeats) | Lebenszeichen der laufenden Worker-Prozesse (Scheduler, Worker-Pools) fuer Betriebsueberwachung. | Hintergrundverarbeitung / Warteschlange | keine (plattformweit, worker_id-bezogen) | 11 | 0 |
| [sync_runs](#sync-runs) | Historie einzelner Synchronisationslaeufe mit Lexware Office je Firma (Kennzahlen, Fehlerkategorie). | Synchronisation | tenant_id | 28 | 1 |
| [api_circuits](#api-circuits) | Circuit Breaker je externer API (Lexware Office, Stripe, Mail): unterbindet weitere Aufrufe nach wiederholten technischen Fehlern (CLAUDE.md: api_call_gate()). | Externe Anbindungen / Stabilitaet | keine (plattformweit je API) | 9 | 0 |
| [integrations](#integrations) | Verbindungsdaten je Firma zu Lexware Office und Stripe (verschluesselte Zugangsdaten, Verbindungsstatus) sowie Wahl der aktiven Rechnungsquelle (Migration 024). | Externe Anbindungen | tenant_id | 27 | 1 |
| [sync_state](#sync-state) | Serverseitiger Fortschritt der laufenden Lexware-Synchronisation je Firma, damit Browser und Cron denselben Lauf fortsetzen koennen (schema.sql-Kommentar Zeile 427). | Synchronisation | tenant_id | 13 | 1 |
| [webhook_events](#webhook-events) | Idempotenz- und Reihenfolgeschutz fuer verarbeitete Webhook-Ereignisse (verhindert doppelte Verarbeitung). | Externe Anbindungen | keine direkt (object_id kann auf Firmenobjekte verweisen, kein tenant_id-Feld) | 6 | 0 |
| [funnel_events](#funnel-events) | Anonymes Trichter-/Konversions-Tracking je Herkunftsdomain (cookielos, ohne IP-Adresse laut schema.sql-Kommentar Zeile 456). | Marketing und Auswertung | tenant_id | 7 | 0 |
| [customers](#customers) | Kunden der Firma, synchronisiert aus Lexware Office (Kontakte). | Fachdaten je Firma / Kunden | tenant_id | 11 | 1 |
| [customer_ibans](#customer-ibans) | Bankverbindungen (IBAN/BIC) eines Kunden, mit Historie ueber Aktivierung/Deaktivierung. | Fachdaten je Firma / SEPA-Mandate | tenant_id | 10 | 2 |
| [iban_history](#iban-history) | Aenderungshistorie zu Bankverbindungen (wer hat wann was geaendert und warum). | Fachdaten je Firma / SEPA-Mandate | tenant_id | 9 | 1 |
| [sepa_mandates](#sepa-mandates) | SEPA-Lastschriftmandate der Kunden: Referenz, Unterschrift, Verfall (schema.sql-Kommentar Zeile 519f.), Bezug zu Stripe-Mandat/Zahlungsmethode. | Fachdaten je Firma / SEPA-Mandate | tenant_id | 23 | 3 |
| [invoices](#invoices) | Aus Lexware Office synchronisierte Rechnungen (Belege) je Firma, mit Einzugsstatus. | Fachdaten je Firma / Rechnungen | tenant_id | 20 | 2 |
| [payment_collections](#payment-collections) | Einzelner SEPA-Lastschrifteinzug zu einer Rechnung ueber Stripe (Kernobjekt des Geldflusses). | Fachdaten je Firma / Einzuege | tenant_id | 30 | 3 |
| [support_sessions](#support-sessions) | Zeitlich begrenzter Support-Zugriff des Plattformbetreibers auf einen Firmenaccount (Migration 008, siehe app/support.php). | Support und Administration | organization_id | 13 | 0 |
| [mandate_files](#mandate-files) | Hochgeladene Mandatsdokumente (PDF/JPG/PNG) je Kunde, Dateien liegen unter app/storage/mandates/ (schema.sql-Kommentar Zeile 627). | Fachdaten je Firma / SEPA-Mandate | tenant_id | 12 | 3 |
| [collection_attempts](#collection-attempts) | Versuchsjournal: jeder Stripe-Aufruf zu einem Einzug wird VOR dem Aufruf mit Idempotenz-Schluessel festgehalten, damit bei Abbruch der Transaktion der Versuch nachvollziehbar bleibt (schema.sql-Kommentar Zeile 664-671, Migration 006). | Fachdaten je Firma / Einzuege / Zahlungssicherheit | tenant_id | 12 | 0 |
| [platform_settings](#platform-settings) | Einfacher Schluessel-Wert-Speicher fuer plattformweite Einstellungen, u. a. der plattformweite Not-Stopp fuer Einzuege (schema.sql-Kommentar Zeile 691-693). | Sicherheit / Zahlungssicherheit | keine (plattformweit) | 3 | 0 |
| [mandate_requests](#mandate-requests) | Digitale Mandatsanforderung per Stripe Checkout (mode=setup): Versand eines Links an den Kunden, der die Bankverbindung selbst hinterlegt (schema.sql-Kommentar Zeile 727-731). | Fachdaten je Firma / SEPA-Mandate | tenant_id | 17 | 2 |
| [collection_rules](#collection-rules) | Regelautomatik fuer wiederkehrende Einzuege, laut schema.sql-Kommentar (Zeile 758-760) nur ein Geruest: is_active bleibt 0, es gibt keine Verarbeitung, nur eine Vorschau (collection_rules_preview()) ohne Einreichung. | Fachdaten je Firma / Einzuege (Vorstufe, nicht produktiv) | tenant_id | 11 | 1 |
| [integration_providers](#integration-providers) | Registry der Anbindungen (Rechnungssysteme und Zahlungsdienstleister), Adaptergrenze fuer weitere Integrationen wie sevdesk (CLAUDE.md, schema.sql-Kommentar Zeile 778-779). | Externe Anbindungen | keine (plattformweit, Stammdaten-/Referenztabelle) | 8 | 0 |
| [stripe_imports](#stripe-imports) | Lauf zum Import bestehender, ausserhalb des Portals getätigter Stripe-Einzuege in die Datenbank (Migration 009, app/stripe_import.php). | Fachdaten je Firma / Einzuege (einmaliger Altdatenimport) | tenant_id | 14 | 1 |
| [stripe_import_items](#stripe-import-items) | Einzelne bei einem Stripe-Import gefundene Zahlungen mit Abgleichsergebnis gegen bestehende Rechnungen/Einzuege. | Fachdaten je Firma / Einzuege (einmaliger Altdatenimport) | tenant_id | 20 | 1 |
| [schema_migrations](#schema-migrations) | Stand der automatisch angewendeten Migrationen (app/migrate.php), verhindert doppelte Ausfuehrung. | Betrieb / Deployment | keine (plattformweit, technische Tabelle) | 4 | 0 |
| [support_tickets](#support-tickets) | Hilfe-Center: Support-Anfragen der Kunden an den Plattformbetreiber (Migration 012, app/support_tickets.php). | Support und Administration | tenant_id | 13 | 1 |
| [support_ticket_messages](#support-ticket-messages) | Nachrichtenverlauf eines Support-Tickets (Kunde und Support im Wechsel). | Support und Administration | tenant_id | 7 | 1 |
| [interest_registrations](#interest-registrations) | Vormerkungen (Warteliste) fuer angekuendigte Integrationen, zuerst sevdesk, mit Double-Opt-in (Migration 020, CLAUDE.md-Abschnitt sevdesk). | Marketing / Vorregistrierung | keine (plattformweit je Anbieter und E-Mail-Adresse) | 30 | 1 |
| [legal_documents](#legal-documents) | Versionierte Rechtsdokumente mit Zustimmungsnachweis (z. B. Auftragsverarbeitungsvertrag nach Art. 28 DSGVO, Verschwiegenheitsvereinbarung nach § 203 StGB), Migration 023. | Recht und Vertraege | keine (plattformweit, ein Dokument gilt fuer alle oder eine Teilmenge von Firmen laut required_for) | 12 | 0 |
| [legal_acceptances](#legal-acceptances) | Zustimmungsnachweis je Firma und Fassung eines Rechtsdokuments (wer, wann, auf welchem Weg), Migration 023. | Recht und Vertraege | organization_id | 7 | 0 |
| [consent_records](#consent-records) | Zustimmungsnachweis zu AGB und Datenschutzerklärung je Benutzer (Gegenstand, Fassung, Zeitpunkt UTC, Weg, Quellseite, E-Mail). Ergänzt legal_acceptances (Vertragsdokumente mit Volltext) und interest_registrations (Vorregistrierung). | Konten und Firmen / Rechtsdokumente | organization_id | 10 | 0 |
| [platform_roles](#platform-roles) | Rollen des Adminbereichs (Plattform-Benutzer und Rechte, Version 4.37): je Rolle eine Liste von Berechtigungscodes aus dem festen Katalog PLATFORM_PERMISSIONS in app/platform.php, oder ["*"] für Vollzugriff. Systemrollen admin, support, staff werden mit Migration 027 angelegt. | Konten und Firmen / Plattform-Administration | keine (plattformweit) | 7 | 0 |
| [marketing_lists](#marketing-lists) | Empfaengerlisten des Marketingmoduls: Importlisten (CSV) und Systemlisten aus den Firmenaccounts (docs/marketing.md). | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 8 | 0 |
| [marketing_recipients](#marketing-recipients) | Empfaenger je Liste mit Rechtsgrundlage und Vermerk des Imports; email_norm ist der Vergleichsschluessel gegen Sperrliste und Dubletten. | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 11 | 1 |
| [marketing_suppressions](#marketing-suppressions) | Dauerhafte, listenuebergreifende Sperrliste: Adressen, die nie wieder eine Werbenachricht erhalten (Abmeldung, Beschwerde, harter Ruecklaeufer, von Hand). | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 6 | 0 |
| [marketing_campaigns](#marketing-campaigns) | Kampagnen des Marketingmoduls: Inhalt (Betreff, Ueberschrift, Absaetze, Schaltflaeche, Fussnote), Listen, Status, Test- und Freigabenachweis, Zaehler. | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 22 | 0 |
| [marketing_sends](#marketing-sends) | Versandzeilen je Kampagne und Adresse: Beanspruchung, Ergebnis, Abmeldetoken (Hash). | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 14 | 1 |
| [marketing_events](#marketing-events) | Ereignisprotokoll des Marketingmoduls: Abmeldungen und Testversand der Anwendung, Ruecklaeufer, Beschwerden, Zustellungen und Abonnementbestaetigungen aus Amazon SES/SNS. | Marketing / Werbeversand (4.63) | keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen) | 7 | 0 |

## plans

**Zweck:** Tarifkatalog der Plattformabrechnung (Abonnement der Firmenaccounts bei der Müller Holding AG). Legt Preis, Periode und Limits je Tarifcode fest (Kommentar schema.sql Zeile 11: 'Tarife (Limits kommen ausschliesslich aus dieser Tabelle)').  
**Modul:** Konten und Firmen / Plattform-Abrechnung  
**Mandantenzuordnung:** keine (plattformweit, ein Datensatz je Tarifcode)  
**Primärschlüssel:** code  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| code | VARCHAR(30) | nein |  | [PK; PRIMARY KEY] |
| name | VARCHAR(60) | nein |  |  |
| price_cents | INT | nein |  |  |
| period_days | INT | nein | 28 |  |
| max_collections_per_period | INT | ja |  |  |
| max_users | INT | ja |  | NULL = unbegrenzt |
| unlimited_users | TINYINT(1) | nein | 0 | 1 = Benutzeranzahl trotz gesetztem max_users nicht begrenzt (Sonderfall fuer bestimmte Tarife) |
| user_invites_enabled | TINYINT(1) | nein | 1 | steuert, ob die Firma laut Tarif weitere Benutzer einladen darf |
| active | TINYINT(1) | nein | 0 |  |
| public_visible | TINYINT(1) | nein | 0 | steuert, ob der Tarif Kunden zur Wahl angeboten wird (nicht-oeffentliche Tarife nur intern/Bestand) |
| sort_order | INT | nein | 0 | Anzeigereihenfolge in Tarifuebersichten |
| stripe_price_id | VARCHAR(255) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** sql/schema.sql INSERT IGNORE INTO plans (Installationsdaten, 5 Tarife: unlimited_start, basic, plus, pro, unlimited); keine INSERT-Anweisung im PHP-Code gefunden  
**Verändert durch:** admin.php plan_input_from_post() (Tarifpflege im Adminbereich), bin/billing-setup-stripe.php billing_setup_store_price_id() (schreibt stripe_price_id nach Anlage in Stripe)  
**Gelesen durch:** app/plans.php (fast alle Funktionen, u.a. plan_upgrade_candidate(), Limitpruefung), app/billing.php (billing_change_plan(), billing_choose_plan()), admin.php, team.php, onboarding.php  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden (keine DELETE-Anweisung; Tarife werden ueber active/public_visible aus- statt abgeschaltet)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: price_cents (Preis in Cent, netto laut CLAUDE.md-Vorgabe); Zeit: period_days (Abrechnungsperiode in Tagen, Standard 28), created_at/updated_at DATETIME; externe IDs: stripe_price_id (Stripe-Preisobjekt der Plattformabrechnung)  
**Migrationen:** 003_saas_2fa_roles_plans.sql (CREATE TABLE), 019_plan_upsell.sql (kein Spaltenzusatz an plans selbst, betrifft organizations)  
**Besonderheiten:** Primaerschluessel ist der sprechende code (kein UUID). max_collections_per_period/max_users NULL bedeutet laut Kommentar 'unbegrenzt'. Laut CLAUDE.md wirken Tarifwechsel nur, wenn billing.enabled gesetzt ist und mindestens zwei Tarife aktiv und oeffentlich sind.  

## organizations

**Zweck:** Firmenaccount (Mandant) des Portals. Traeger von Tarif, Abrechnungsstatus, SEPA-Stammdaten (Glaeubiger-ID, Mandatspraefix) und Firmenadresse.  
**Modul:** Konten und Firmen  
**Mandantenzuordnung:** id (die Tabelle IST der Mandant)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| name | VARCHAR(255) | nein |  |  |
| mandate_prefix | VARCHAR(10) | nein | '' | Praefix der SEPA-Mandatsreferenzen dieser Firma (schema.sql-Kommentar); wird mit Kundennummer kombiniert (siehe customer.php) |
| use_hvm_ci | TINYINT(1) | nein | 0 | nur fuer die Hausverwaltung Müller GmbH selbst, steuert Anzeige im HVM-Corporate-Design (schema.sql-Kommentar, Migration 002) |
| onboarding_completed | TINYINT(1) | nein | 0 | nur für die Hausverwaltung Müller GmbH selbst |
| onboarding_step | INT | nein | 0 |  |
| plan_code | VARCHAR(30) | nein | 'unlimited_start' |  |
| subscription_status | VARCHAR(20) | nein | 'pending' |  |
| subscription_period_end | DATETIME | ja |  | pending\|active\|past_due\|canceled\|exempt |
| cancel_at_period_end | TINYINT(1) | nein | 0 |  |
| billing_exempt | TINYINT(1) | nein | 0 | Firma von der Zwangspruefung eines nutzbaren Abonnements ausgenommen (siehe CLAUDE.md, admin.php) |
| platform_stripe_customer_id | VARCHAR(255) | ja |  |  |
| platform_stripe_subscription_id | VARCHAR(255) | ja |  |  |
| signup_domain | VARCHAR(100) | ja |  | Domain, ueber die sich die Firma registriert hat (Marketing/Funnel-Auswertung) |
| utm_source | VARCHAR(100) | ja |  |  |
| utm_medium | VARCHAR(100) | ja |  |  |
| utm_campaign | VARCHAR(100) | ja |  |  |
| utm_content | VARCHAR(100) | ja |  |  |
| referrer | VARCHAR(500) | ja |  |  |
| street | VARCHAR(255) | ja |  |  |
| zip | VARCHAR(20) | ja |  |  |
| city | VARCHAR(100) | ja |  |  |
| country | CHAR(2) | nein | 'DE' |  |
| creditor_identifier | VARCHAR(35) | ja |  |  |
| pre_notification_days | INT | nein | 14 | Gläubiger-Identifikationsnummer |
| send_pre_notification | TINYINT(1) | nein | 0 |  |
| require_signed_mandate | TINYINT(1) | nein | 1 |  |
| professional_secrecy | TINYINT(1) | nein | 0 | Firma erklaert eine berufliche Verschwiegenheitspflicht nach § 203 StGB (Migration 023) |
| deleted_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| collections_paused | TINYINT(1) | nein | 0 | [per ALTER ergänzt] |
| collections_paused_at | DATETIME | ja |  | [per ALTER ergänzt] |
| sync_paused | TINYINT(1) | nein | 0 | [per ALTER ergänzt] |
| sync_paused_reason | VARCHAR(160) | ja |  | [per ALTER ergänzt] |
| feature_flags | TEXT | ja |  | JSON-Liste je Firma freigeschalteter Funktionen, gesetzt vom Plattformadministrator (app/features.php-Kommentar) [per ALTER ergänzt] |
| quota_warning_period_start | DATETIME | ja |  | Beginn der laufenden Abrechnungsperiode, fuer die bereits eine Kontingentwarnung gesendet wurde (verhindert Mehrfachversand) [per ALTER ergänzt] |
| plan_changed_at | DATETIME | ja |  | [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** IX ix_org_signup_domain (signup_domain)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `subscription_status`: pending | active | past_due | canceled | exempt (Kommentar schema.sql Zeile 50). Uebergaenge laut app/billing.php: pending/aktiv nach billing_apply_subscription() aus Stripe-Ereignis; past_due wenn Stripe eine faellige Rechnung meldet (billing_handle_event(), nur aus 'active'); exempt manuell durch Plattformadmin (admin.php plan_input_from_post()).
**Erzeugt durch:** app/auth.php create_company() (Zeile 1214, Firmengruendung bei Registrierung oder Zweitfirma)  
**Verändert durch:** app/legal.php legal_set_secrecy() (professional_secrecy, professional_secrecy_kind), app/plans.php plan_quota_warning_maybe_send() (quota_warning_period_start), app/collections.php collections_set_paused() (collections_paused, collections_paused_at), app/features.php tenant_feature_set() (feature_flags), app/billing.php billing_change_plan()/billing_choose_plan() (plan_code, plan_changed_at), billing_ensure_customer() (platform_stripe_customer_id), billing_apply_subscription()/billing_handle_event() (subscription_status, subscription_period_end, cancel_at_period_end), team.php (Firmenstammdaten: name, street, zip, city, country, creditor_identifier), admin-system.php (sync_paused, sync_paused_reason), onboarding.php (onboarding_completed, onboarding_step), admin.php plan_input_from_post() (plan_code, billing_exempt, subscription_status)  
**Gelesen durch:** so gut wie jede Seite ueber den Tenant-Kontext (require_login()), insbesondere team.php, settings.php, admin.php  
**Löschung, Archivierung, Aufbewahrung:** Soft-Delete ueber deleted_at (Spalte vorhanden); Loeschcode selbst nicht in den durchsuchten Dateien gefunden, admin-system.php prueft deleted_at IS NULL bei sync_paused  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine eigenen (Preise kommen aus plans); Zeit: subscription_period_end, plan_changed_at, quota_warning_period_start, collections_paused_at, deleted_at, created_at/updated_at DATETIME; externe IDs: platform_stripe_customer_id, platform_stripe_subscription_id (Stripe-Konto der Müller Holding AG, getrennt vom Stripe-Konto der Firma in integrations)  
**Migrationen:** Basistabelle vor Migrationszaehlung (existierte bereits vor 001), 002_add_multi_company_support.sql (mandate_prefix, use_hvm_ci), 003_saas_2fa_roles_plans.sql (plan_code, subscription_status u.a., signup_domain-Index), 006_payment_safety.sql (collections_paused, collections_paused_at), 007_refunds_alerts.sql (pre_notification_days DEFAULT), 018_queue_worker.sql (sync_paused, sync_paused_reason, feature_flags), 019_plan_upsell.sql (quota_warning_period_start, plan_changed_at), 023_legal_documents.sql (professional_secrecy, professional_secrecy_kind)  
**Besonderheiten:** id ist CHAR(36) UUID und zugleich Mandantenschluessel fuer praktisch alle Fachtabellen (tenant_id-Fremdschluessel, teils nur per Anwendungscode abgesichert, teils per FOREIGN KEY ON DELETE CASCADE). creditor_identifier ist die Glaeubiger-Identifikationsnummer fuer SEPA-Lastschriften.  

## users

**Zweck:** Persoenlicher Benutzerzugang (Login), unabhaengig von der Firmenzugehoerigkeit. Verpflichtende Zwei-Faktor-Authentifizierung (TOTP).  
**Modul:** Konten und Firmen / Anmeldung  
**Mandantenzuordnung:** keine (Benutzer koennen laut multiaccount_enabled mehreren Firmen ueber organization_members zugeordnet sein)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| email | VARCHAR(255) | nein |  | [UQ uq_users_email] |
| password_hash | VARCHAR(255) | nein |  |  |
| display_name | VARCHAR(255) | ja |  |  |
| first_name | VARCHAR(100) | ja |  |  |
| last_name | VARCHAR(100) | ja |  |  |
| avatar_path | VARCHAR(255) | ja |  |  |
| phone_private | VARCHAR(40) | ja |  | Profilbild (Migration 010) |
| phone_business | VARCHAR(40) | ja |  |  |
| is_active | TINYINT(1) | nein | 1 |  |
| totp_secret_encrypted | TEXT | ja |  |  |
| totp_enabled | TINYINT(1) | nein | 0 |  |
| totp_confirmed_at | DATETIME | ja |  |  |
| totp_last_step | BIGINT | ja |  | letzter verwendeter TOTP-Zeitschritt, verhindert Wiederverwendung desselben Codes |
| email_verified_at | DATETIME | ja |  |  |
| welcome_mail_pending | TINYINT(1) | nein | 0 | Willkommensmail mit Bestaetigungslink konnte nicht erzeugt werden und steht aus (Migration 021), wird durch Wartung nachgesendet |
| email_verify_token_hash | CHAR(64) | ja |  | Willkommensmail steht aus (Migration 021) |
| email_verify_expires_at | DATETIME | ja |  |  |
| password_reset_token_hash | CHAR(64) | ja |  |  |
| password_reset_expires_at | DATETIME | ja |  |  |
| is_superadmin | TINYINT(1) | nein | 0 |  |
| platform_role | VARCHAR(32) | ja |  | Plattformrolle (platform_roles.code) für den Adminbereich; NULL = kein Adminzugang. is_superadmin = 1 bleibt Vollzugriff (Migration 027 setzt dazu die Rolle admin). |
| NULL | = kein Adminzugang (Migration 027) multiaccount_enabled       TINYINT(1)   NOT NULL DEFAULT 0 | nein | 0 |  |
| last_login_at | DATETIME | ja |  | manuell aktiviert (Migration 015); automatisch wirksam bei mehreren Firmen |
| failed_login_count | INT | nein | 0 |  |
| locked_until | DATETIME | ja |  |  |
| session_epoch | INT | nein | 0 | wird bei Sicherheitsereignissen erhoeht, um bestehende Sitzungen serverseitig ungueltig zu machen (user_revoke_sessions()) |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** UQ uq_users_email (email); IX ix_users_platform_role (platform_role)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/auth.php _auth_register_create() (Zeile 1073, Registrierung), invite.php (Zeile 76, Benutzer akzeptiert eine Einladung ohne bestehenden Account)  
**Verändert durch:** app/auth.php auth_login() (failed_login_count, password_hash bei automatischer Rehash-Aktualisierung), app/auth.php session_finish_login() (last_login_at, failed_login_count, locked_until), app/auth.php user_revoke_sessions() (session_epoch), app/auth.php twofa_confirm_setup()/twofa_verify_user()/twofa_reset() (totp_secret_encrypted, totp_enabled, totp_confirmed_at, totp_last_step), app/auth.php email_verification_send()/email_verification_consume() (email_verify_token_hash, email_verify_expires_at, email_verified_at), app/auth.php password_reset_request()/password_reset_complete()/password_change() (password_reset_token_hash, password_reset_expires_at, password_hash), app/auth.php auth_send_pending_welcome_mails() (welcome_mail_pending = 0), app/auth.php user_multiaccount_set()/user_multiaccount_autoenable() (multiaccount_enabled), app/profile.php profile_update()/profile_avatar_store()/profile_avatar_delete() (display_name, phone_private, phone_business, avatar_path), admin-support.php (failed_login_count, locked_until, Support-Entsperrung)  
**Gelesen durch:** app/auth.php require_login() und praktisch jede Seite  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden (keine DELETE-Anweisung fuer users im durchsuchten Code); organization_members hat ON DELETE CASCADE auf user_id, was auf eine vorgesehene, aber nicht lokalisierte Loeschroutine hindeutet  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: totp_confirmed_at, email_verified_at, last_login_at, locked_until, created_at/updated_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung, 010_profile.sql (avatar_path), 015_multiaccount_registration.sql (multiaccount_enabled), 021_mail_pending.sql (welcome_mail_pending), 027_platform_roles.sql  
**Besonderheiten:** totp_secret_encrypted verschluesselt ueber app/crypto.php (AES-256-GCM, encrypt_value()/decrypt_value()); password_hash mit PHP password_hash(); *_token_hash-Spalten speichern laut Projektkonvention nur den SHA-256-Hash des Links, nie den Klartext.  

## user_recovery_codes

**Zweck:** Einmal-Wiederherstellungscodes fuer den Fall eines verlorenen TOTP-Geraets.  
**Modul:** Konten und Firmen / Anmeldung  
**Mandantenzuordnung:** keine direkt; ueber user_id an users und damit indirekt an dessen Firmen gebunden  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| user_id | CHAR(36) | nein |  | [FK → users.id (ON DELETE CASCADE); UQ uq_recovery_user_hash] |
| code_hash | CHAR(64) | nein |  | [UQ uq_recovery_user_hash] |
| used_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** UQ uq_recovery_user_hash (user_id, code_hash)  
**Von der Datenbank erzwungene Beziehungen:** user_id → users.id (ON DELETE CASCADE)  
**Erzeugt durch:** app/auth.php recovery_codes_regenerate()  
**Verändert durch:** app/auth.php recovery_code_consume() (used_at)  
**Gelesen durch:** app/auth.php (Login-Ablauf mit Wiederherstellungscode)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber user_id bei Loeschung des Benutzers  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: used_at, created_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung (Teil der 2FA-Einfuehrung, vermutlich 003)  
**Besonderheiten:** code_hash speichert nur den Hash des Codes; UNIQUE (user_id, code_hash) verhindert Duplikate je Benutzer.  

## login_attempts

**Zweck:** Protokoll aller Anmeldeversuche (Passwort, TOTP, Wiederherstellungscode) fuer Sperrlogik und Missbrauchserkennung.  
**Modul:** Konten und Firmen / Anmeldung  
**Mandantenzuordnung:** keine (plattformweit, nur nach E-Mail und IP)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | nein |  | [PK; PRIMARY KEY AUTO_INCREMENT] |
| email | VARCHAR(255) | nein |  |  |
| ip | VARCHAR(45) | ja |  |  |
| success | TINYINT(1) | nein | 0 |  |
| stage | VARCHAR(20) | nein | 'password' |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP | password \| totp \| recovery |

**Indizes und Eindeutigkeit:** IX ix_login_email_time (email, created_at); IX ix_login_ip_time (ip, created_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `stage`: password | totp | recovery (schema.sql-Kommentar Zeile 128)
**Erzeugt durch:** app/auth.php login_record()  
**Gelesen durch:** app/auth.php (Sperrlogik nach fehlgeschlagenen Versuchen)  
**Löschung, Archivierung, Aufbewahrung:** admin-support.php loescht fehlgeschlagene Versuche einer E-Mail bei manueller Entsperrung (DELETE FROM login_attempts WHERE email = ? AND success = 0)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung  
**Besonderheiten:** Kein Fremdschluessel, nur lose ueber email verknuepft (Protokolltabelle).  

## audit_log

**Zweck:** Revisionssicheres Protokoll geldrelevanter und sicherheitsrelevanter Aktionen (CLAUDE.md: 'jede geldrelevante Aktion in audit_log').  
**Modul:** Sicherheit und Nachvollziehbarkeit  
**Mandantenzuordnung:** tenant_id (NULL bei plattformweiten/Support-Aktionen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | nein |  | [PK; PRIMARY KEY AUTO_INCREMENT] |
| tenant_id | CHAR(36) | ja |  |  |
| user_id | CHAR(36) | ja |  |  |
| user_email | VARCHAR(255) | ja |  |  |
| action | VARCHAR(60) | nein |  |  |
| target_type | VARCHAR(40) | ja |  |  |
| target_id | VARCHAR(64) | ja |  |  |
| details_json | TEXT | ja |  | zusaetzliche Ereignisdaten als JSON, u. a. correlation_id (app/audit.php) |
| ip | VARCHAR(45) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_audit_tenant_time (tenant_id, created_at); IX ix_audit_user_time (user_id, created_at); IX ix_audit_action (action); IX ix_audit_created (created_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/audit.php audit_log() (zentrale Funktion, von sehr vielen Stellen im Code aufgerufen)  
**Gelesen durch:** Adminbereich (Auditansichten), Support-Werkzeuge  
**Löschung, Archivierung, Aufbewahrung:** app/audit.php audit_cleanup(), aufgerufen aus app/jobs.php (Job 'audit_pruned' innerhalb job_maintenance()); Aufbewahrung ueber audit_retention_days() (config audit.retention_days, Vorgabe 90 Tage, Mindestwert 30)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine eigene Spalte (Betraege stecken ggf. in details_json); Zeit: created_at DATETIME; externe IDs: keine eigene Spalte  
**Migrationen:** Basistabelle vor Migrationszaehlung  
**Besonderheiten:** Bewusst ohne Fremdschluessel (Kommentar schema.sql Zeile 134), damit Eintraege auch nach Loeschung von Firma/Benutzer lesbar bleiben. details_json ist JSON-Freitext.  

## organization_members

**Zweck:** Zuordnung Benutzer zu Firma mit Rolle (Mitgliedschaftstabelle, ermoeglicht Mehrfirmen-Zugehoerigkeit).  
**Modul:** Konten und Firmen  
**Mandantenzuordnung:** organization_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| organization_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_org_member_user] |
| user_id | CHAR(36) | nein |  | [FK → users.id (ON DELETE CASCADE); UQ uq_org_member_user] |
| role | VARCHAR(20) | nein | 'member' |  |
| status | VARCHAR(20) | nein | 'active' | owner \| admin \| member |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP | active \| suspended |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** UQ uq_org_member_user (organization_id, user_id); IX ix_member_user (user_id)  
**Von der Datenbank erzwungene Beziehungen:** organization_id → organizations.id (ON DELETE CASCADE); user_id → users.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `role`: owner | admin | member (schema.sql-Kommentar Zeile 155)
- `status`: active | suspended (schema.sql-Kommentar Zeile 156)
**Erzeugt durch:** app/auth.php _auth_register_create() (Ersteigentuemer bei Registrierung), app/auth.php create_company() (Ersteigentuemer bei Firmengruendung), invite.php (Beitritt nach Einladungsannahme)  
**Verändert durch:** team.php (Rollenaenderung, Sperren/Aktivieren, Eigentuemeruebergabe), invite.php (Reaktivierung bei erneuter Einladung eines bestehenden Mitglieds)  
**Gelesen durch:** app/auth.php require_login() (Rollen- und Zugriffspruefung), team.php  
**Löschung, Archivierung, Aufbewahrung:** kein DELETE im durchsuchten Code gefunden; ON DELETE CASCADE ueber organization_id und user_id bei Loeschung von Firma oder Benutzer  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at/updated_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung (Teil der Mehrfirmenstruktur, siehe UNIQUE (organization_id, user_id))  
**Besonderheiten:** UNIQUE (organization_id, user_id) verhindert Doppelmitgliedschaft.  

## invitations

**Zweck:** Einladungen weiterer Benutzer in eine Firma per Link.  
**Modul:** Konten und Firmen  
**Mandantenzuordnung:** organization_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| organization_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_invitation_org_email] |
| email | VARCHAR(255) | nein |  | [UQ uq_invitation_org_email] |
| first_name | VARCHAR(100) | ja |  |  |
| last_name | VARCHAR(100) | ja |  |  |
| role | VARCHAR(20) | nein | 'member' |  |
| token | VARCHAR(64) | nein |  | [UQ uq_invitation_token] |
| invited_by_user_id | CHAR(36) | nein |  |  |
| status | VARCHAR(20) | nein | 'pending' |  |
| expires_at | DATETIME | nein |  | pending \| accepted \| revoked \| expired |
| accepted_at | DATETIME | ja |  |  |
| revoked_at | DATETIME | ja |  |  |
| last_sent_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** UQ uq_invitation_org_email (organization_id, email); UQ uq_invitation_token (token)  
**Von der Datenbank erzwungene Beziehungen:** organization_id → organizations.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: pending | accepted | revoked | expired (schema.sql-Kommentar Zeile 175); Uebergaenge in team.php (revoked) und invite.php (accepted)
**Erzeugt durch:** team.php send_invitation_mail() (Zeile 165)  
**Verändert durch:** team.php send_invitation_mail() (erneuter Versand: neuer Token/Reset auf pending; Widerruf: revoked), invite.php (Annahme: accepted)  
**Gelesen durch:** invite.php (Einladungslink oeffnen), team.php (Uebersicht)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber organization_id bei Loeschung der Firma  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: expires_at, accepted_at, revoked_at, last_sent_at, created_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung  
**Besonderheiten:** token speichert laut Projektkonvention (schema.sql-Kommentar Zeile 165) den SHA-256-Hash des Links, nie den Klartext. UNIQUE (organization_id, email) verhindert Doppeleinladung derselben Adresse.  

## registration_requests

**Zweck:** Zwischenspeicher fuer Firmendaten, wenn sich eine bereits bekannte E-Mail-Adresse ein zweites Mal registriert, bis der bestehende Benutzer sich anmeldet und die Zweitfirma bestaetigt (Migration 015).  
**Modul:** Konten und Firmen / Anmeldung  
**Mandantenzuordnung:** keine eigene tenant_id vor Abschluss; user_id bindet an den bestehenden Benutzer, created_org_id verweist nach Abschluss auf die neue Firma  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| user_id | CHAR(36) | nein |  | [FK → users.id (ON DELETE CASCADE)] |
| org_name | VARCHAR(255) | nein |  |  |
| mandate_prefix | VARCHAR(10) | nein |  |  |
| status | VARCHAR(20) | nein | 'pending' |  |
| created_org_id | CHAR(36) | ja |  | pending \| completed \| expired \| discarded |
| ip | VARCHAR(45) | ja |  |  |
| expires_at | DATETIME | nein |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| completed_at | DATETIME | ja |  |  |

**Indizes und Eindeutigkeit:** IX ix_regreq_user_status (user_id, status); IX ix_regreq_expires (expires_at)  
**Von der Datenbank erzwungene Beziehungen:** user_id → users.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: pending | completed | expired | discarded (schema.sql-Kommentar Zeile 193)
**Erzeugt durch:** app/auth.php registration_request_create() (Zeile 1465)  
**Verändert durch:** app/auth.php registration_request_complete() (completed, created_org_id), app/auth.php registration_request_discard() (discarded), app/auth.php registration_requests_cleanup() (expired, zeitgesteuert)  
**Gelesen durch:** app/auth.php (Login-Ablauf, offene Zweitfirmen-Anfrage anzeigen)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden (nur Statuswechsel auf expired/discarded, kein DELETE)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: expires_at, completed_at, created_at DATETIME; externe IDs: keine  
**Migrationen:** 015_multiaccount_registration.sql (CREATE TABLE)  
**Besonderheiten:** Kommentar schema.sql Zeile 187: 'Keine Passwoerter, keine Geheimnisse.' ON DELETE CASCADE ueber user_id.  

## trusted_devices

**Zweck:** Vertrauenswuerdige Geraete, auf denen die 2FA-Abfrage fuer eine begrenzte Zeit uebersprungen werden kann (Migration 016).  
**Modul:** Konten und Firmen / Anmeldung  
**Mandantenzuordnung:** keine direkt; user_id bindet an den Benutzer  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| user_id | CHAR(36) | nein |  | [FK → users.id (ON DELETE CASCADE)] |
| scope | VARCHAR(20) | nein | 'app' |  |
| token_hash | CHAR(64) | nein |  | app \| admin [UQ uq_trusted_token] |
| label | VARCHAR(120) | ja |  | HMAC-SHA256 des geheimen Tokenteils |
| created_at | DATETIME | nein |  |  |
| expires_at | DATETIME | nein |  |  |
| wird | nie | ja |  |  |
| rotated_at | DATETIME | ja |  |  |
| revoked_at | DATETIME | ja |  |  |
| revoked_reason | VARCHAR(40) | ja |  | Grund des Widerrufs, z. B. manuell oder durch devices_revoke_all() |
| ip_created | VARCHAR(45) | ja |  |  |

**Indizes und Eindeutigkeit:** UQ uq_trusted_token (token_hash); IX ix_trusted_user (user_id, revoked_at, expires_at)  
**Von der Datenbank erzwungene Beziehungen:** user_id → users.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `scope`: app | admin (schema.sql-Kommentar Zeile 208)
**Erzeugt durch:** app/devices.php device_trust_create()  
**Verändert durch:** app/devices.php device_trust_rotate() (Token-Rotation), app/devices.php device_revoke() (Einzelgeraet), app/devices.php devices_revoke_all() (alle Geraete eines Benutzers)  
**Gelesen durch:** app/auth.php (2FA-Pruefung beim Login)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber user_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at, expires_at (created_at + 90 Tage, laut Kommentar nie verlaengert), last_used_at, rotated_at, revoked_at DATETIME; externe IDs: keine  
**Migrationen:** 016_trusted_devices.sql (CREATE TABLE)  
**Besonderheiten:** token_hash speichert den HMAC-SHA256 des geheimen Tokenteils (schema.sql-Kommentar Zeile 209). Zeiten in UTC laut Kommentar.  

## job_runs

**Zweck:** Historie/Monitoring aller Hintergrundlaeufe (Cron, Synchronisation, Einzuege, Monitoring) mit Kennzahlen (Migration 017).  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** tenant_id (NULL bei plattformweiten Laeufen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| job_type | VARCHAR(30) | nein |  |  |
| job_key | VARCHAR(120) | ja |  | cron \| sync \| collections \| monitor |
| tenant_id | CHAR(36) | ja |  | fachlicher Auftrag (z.B. sync:<firma>:<start>) |
| source | VARCHAR(20) | nein | 'cron' |  |
| status | VARCHAR(12) | nein | 'running' | cron \| web \| cli |
| started_at | DATETIME | nein |  | running \| success \| failed \| unknown |
| heartbeat_at | DATETIME | nein |  |  |
| finished_at | DATETIME | ja |  |  |
| duration_ms | INT | ja |  |  |
| items_processed | INT | nein | 0 |  |
| api_calls | INT | nein | 0 |  |
| api_errors | INT | nein | 0 |  |
| throttle_ms | INT | nein | 0 |  |
| retries | INT | nein | 0 |  |
| skipped_starts | INT | nein | 0 |  |
| queue_wait_ms | INT | ja |  | Wartezeit eines Warteschlangenjobs von der Fälligkeit (available_at) bis zur Reservierung durch einen Worker, in Millisekunden (Migration 029); NULL bei Cron- und Webläufen |
| peak_memory_bytes | INT UNSIGNED | ja |  | Wartezeit in der Warteschlange bis zur Reservierung (Migration 029) |
| error_category | VARCHAR(60) | ja |  | bereinigte Fehlerkategorie (siehe monitor_category()), keine Rohtexte |

**Indizes und Eindeutigkeit:** IX ix_jobruns_type_started (job_type, started_at); IX ix_jobruns_finished (finished_at); IX ix_jobruns_status_heartbeat (status, heartbeat_at); IX ix_jobruns_status_finished (status, finished_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: running | success | failed | unknown (schema.sql-Kommentar Zeile 230); unknown wird gesetzt, wenn ein Lauf ueberfaellig ist (queue_release_stale(), _mon_mark_stale_runs())
**Erzeugt durch:** app/monitor.php job_run_start()  
**Verändert durch:** app/monitor.php job_run_heartbeat(), job_run_finish(), app/monitor.php _mon_mark_stale_runs() (verwaiste Laeufe auf unknown), app/queue.php queue_release_stale() (heartbeat_stale)  
**Gelesen durch:** app/monitor.php (Statusseite, Adminbereich System/Wartende Aufgaben)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden im durchsuchten Code (moeglich, aber nicht lokalisiert)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: started_at, heartbeat_at, finished_at DATETIME, duration_ms; externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE), 029_sync_performance.sql  
**Besonderheiten:** job_key beschreibt laut Kommentar den fachlichen Auftrag (z. B. sync:<firma>:<start>).  

## monitor_checks

**Zweck:** Einzelne Gesundheitspruefungen/Messpunkte je Komponente (Migration 017), Grundlage der Statusseite.  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** keine (plattformweit, component-bezogen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | nein |  | [PK; PRIMARY KEY AUTO_INCREMENT] |
| component | VARCHAR(40) | nein |  |  |
| source | VARCHAR(20) | nein | 'internal' | internal \| instrumented \| external (Herkunft der Messung) |
| checked_at | DATETIME | nein |  | internal \| instrumented \| external |
| status | VARCHAR(10) | nein |  |  |
| latency_ms | INT | ja |  | ok \| degraded \| fail \| unknown |
| value_num | DECIMAL(14,2) | ja |  |  |
| unit | VARCHAR(12) | ja |  |  |
| category | VARCHAR(60) | ja |  |  |
| keine | Rohtexte | nein | 300 |  |

**Indizes und Eindeutigkeit:** IX ix_mon_component_time (component, checked_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: ok | degraded | fail | unknown (schema.sql-Kommentar Zeile 253)
**Erzeugt durch:** app/monitor.php monitor_event()  
**Gelesen durch:** app/monitor.php (Aggregation zu monitor_daily, Statusseite)  
**Löschung, Archivierung, Aufbewahrung:** app/monitor.php (DELETE FROM monitor_checks WHERE checked_at < ..., Aufbewahrung MONITOR_RAW_DAYS)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: checked_at DATETIME, valid_seconds (Gueltigkeitsdauer der Messung); externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE)  
**Besonderheiten:** category enthaelt laut Kommentar eine bereinigte Fehlerkategorie, keine Rohtexte (Vermeidung von Geheimnis-Leaks in oeffentlichen Auswertungen).  

## monitor_daily

**Zweck:** Tagesaggregat der Verfuegbarkeit je Komponente (Sekunden ok/eingeschraenkt/ausgefallen/unbekannt), Grundlage der oeffentlichen Statusseite.  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** keine (plattformweit)  
**Primärschlüssel:** component, day  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| component | VARCHAR(40) | nein |  | [PK] |
| day | DATE | nein |  | [PK] |
| t_ok | INT | nein | 0 | Sekunden nutzbar inklusive eingeschraenkt (schema.sql-Kommentar) |
| t_degraded | INT | nein | 0 | davon eingeschraenkt nutzbar |
| t_fail | INT | nein | 0 | Sekunden nicht nutzbar |
| t_unknown | INT | nein | 0 | Sekunden ohne gueltigen Nachweis |
| checks | INT | nein | 0 | Sekunden ohne gültigen Nachweis |
| fails | INT | nein | 0 |  |
| updated_at | DATETIME | nein |  |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/monitor.php monitor_aggregate_daily()  
**Verändert durch:** app/monitor.php monitor_aggregate_daily() (ON DUPLICATE KEY UPDATE laut INSERT-Struktur mit Primaerschluessel component+day)  
**Gelesen durch:** app/monitor.php (Statusseite, Verfuegbarkeitsverlauf)  
**Löschung, Archivierung, Aufbewahrung:** app/monitor.php (DELETE FROM monitor_daily WHERE day < ..., Aufbewahrung MONITOR_DAILY_DAYS)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: day DATE, updated_at DATETIME; externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE)  
**Besonderheiten:** Primaerschluessel (component, day) macht die Tabelle je Tag und Komponente eindeutig.  

## monitor_requests

**Zweck:** Minutengenaue Kennzahlen aller eingehenden Web-Anfragen (Anzahl, 5xx-Fehler, Antwortzeiten) fuer die Statusseite.  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** keine (plattformweit)  
**Primärschlüssel:** minute  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| minute | DATETIME | nein |  | [PK; PRIMARY KEY] |
| auf | die | nein | 0 |  |
| errors_5xx | INT | nein | 0 |  |
| sum_ms | INT UNSIGNED | nein | 0 |  |
| max_ms | INT | nein | 0 |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/bootstrap.php csrf_check() (Zeile 459, faktisch am Ende jeder Anfrage im Request-Lebenszyklus, nicht nur bei CSRF-Pruefung)  
**Verändert durch:** app/bootstrap.php (ON DUPLICATE KEY UPDATE ueber Primaerschluessel minute, laut INSERT-Struktur requests = requests + 1 u. ae.)  
**Gelesen durch:** app/monitor.php (Statusseite, Lastauswertung)  
**Löschung, Archivierung, Aufbewahrung:** app/monitor.php (DELETE FROM monitor_requests WHERE minute < ..., Aufbewahrung MONITOR_REQ_DAYS)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: minute DATETIME (Primaerschluessel, auf die Minute gekuerzt, UTC laut Kommentar); externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE)  
**Besonderheiten:** minute ist zugleich Primaerschluessel, ermoeglicht Upsert je Minute.  

## monitor_incidents

**Zweck:** Stoerungen und geplante Wartungen fuer die oeffentliche Statusseite und interne Nachverfolgung.  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** keine (plattformweit)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| kind | VARCHAR(12) | nein | 'incident' |  |
| title | VARCHAR(160) | nein |  | incident \| maintenance |
| status | VARCHAR(20) | nein |  |  |
| components | TEXT | ja |  | investigating \| identified \| monitoring \| resolved \| scheduled \| active \| completed |
| started_at | DATETIME | nein |  | JSON-Liste öffentlicher Komponentenschlüssel |
| ended_at | DATETIME | ja |  |  |
| scheduled_end_at | DATETIME | ja |  |  |
| public_message | TEXT | ja |  |  |
| internal_notes | TEXT | ja |  | veröffentlichter Text (bereinigt) |
| published | TINYINT(1) | nein | 0 | steuert, ob der Vorfall auf der oeffentlichen Statusseite erscheint |
| published_at | DATETIME | ja |  |  |
| created_by | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein |  |  |
| updated_at | DATETIME | nein |  |  |

**Indizes und Eindeutigkeit:** IX ix_incidents_started (started_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `kind`: incident | maintenance (schema.sql-Kommentar Zeile 285)
- `status`: investigating | identified | monitoring | resolved | scheduled | active | completed (schema.sql-Kommentar Zeile 287)
**Erzeugt durch:** app/monitor.php monitor_incident_create()  
**Verändert durch:** app/monitor.php monitor_incident_update() (status, ended_at, public_message), app/monitor.php monitor_incident_publish() (published, published_at)  
**Gelesen durch:** Statusseite (status.smart-einzug.de), Adminbereich Monitoring  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: started_at, ended_at, scheduled_end_at, published_at, created_at/updated_at DATETIME; externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE)  
**Besonderheiten:** internal_notes wird laut Kommentar nie veroeffentlicht, nur public_message. components ist eine JSON-Liste oeffentlicher Komponentenschluessel.  

## monitor_incident_updates

**Zweck:** Verlaufsmeldungen (Updates) zu einer Stoerung/Wartung aus monitor_incidents.  
**Modul:** Monitoring und Betrieb  
**Mandantenzuordnung:** keine (ueber incident_id an monitor_incidents gebunden, dort plattformweit)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| incident_id | CHAR(36) | nein |  | [FK → monitor_incidents.id (ON DELETE CASCADE)] |
| phase | VARCHAR(20) | nein |  |  |
| public_text | TEXT | ja |  |  |
| internal_note | TEXT | ja |  |  |
| created_by | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein |  |  |

**Indizes und Eindeutigkeit:** IX ix_incupd_incident (incident_id, created_at)  
**Von der Datenbank erzwungene Beziehungen:** incident_id → monitor_incidents.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `phase`: vermutlich identisch zu monitor_incidents.status (investigating/identified/monitoring/resolved/scheduled/active/completed); im durchsuchten Code kein separates Enum gefunden, daher nicht sicher belegt
**Erzeugt durch:** app/monitor.php monitor_incident_update()  
**Gelesen durch:** Statusseite, Adminbereich Monitoring  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber incident_id bei Loeschung des Vorfalls  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** 017_monitoring.sql (CREATE TABLE)  
**Besonderheiten:** internal_note wird laut Analogie zu monitor_incidents nie veroeffentlicht.  

## jobs

**Zweck:** Warteschlange fuer Hintergrundverarbeitung (Feature-Flag features.queue), verarbeitet von Worker-Prozessen auf dem VPS bzw. inline im Cron.  
**Modul:** Hintergrundverarbeitung / Warteschlange  
**Mandantenzuordnung:** tenant_id (NULL bei plattformweiten Jobs wie mail oder audit_pruned)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | ja |  |  |
| user_id | CHAR(36) | ja |  |  |
| type | VARCHAR(40) | nein |  |  |
| priority | TINYINT | nein | 50 | 10 HIGH, 50 NORMAL, 90 LOW, kleinere Zahl wird zuerst bearbeitet (schema.sql-Kommentar Zeile 320) |
| 50 | NORMAL | ja |  |  |
| 90 | LOW | ja |  |  |
| keine | Geheimnisse | nein | 'queued' |  |
| progress | TINYINT UNSIGNED | ja |  | queued \| processing \| retry \| completed \| partially_completed \| failed \| cancelled |
| NULL | = unbekannt progress_text  VARCHAR(160) NULL | ja |  |  |
| available_at | DATETIME | nein |  |  |
| created_at | DATETIME | nein |  |  |
| started_at | DATETIME | ja |  |  |
| finished_at | DATETIME | ja |  |  |
| attempts | INT | nein | 0 |  |
| max_attempts | INT | nein | 5 |  |
| locked_by | VARCHAR(64) | ja |  |  |
| locked_at | DATETIME | ja |  |  |
| heartbeat_at | DATETIME | ja |  |  |
| last_error | VARCHAR(255) | ja |  |  |
| result_json | TEXT | ja |  | bereinigt (Kategorie und Kurztext) |
| correlation_id | CHAR(36) | ja |  |  |
| dedupe_key | VARCHAR(120) | ja |  | [UQ uq_jobs_dedupe] |
| eindeutig | closed | ja |  |  |

**Indizes und Eindeutigkeit:** UQ uq_jobs_dedupe (dedupe_key); IX ix_jobs_pick (status, type, available_at, priority); IX ix_jobs_tenant (tenant_id, created_at); IX ix_jobs_status_created (status, created_at); IX ix_jobs_locked (locked_by, heartbeat_at); IX ix_jobs_correlation (correlation_id); IX ix_jobs_status_finished (status, finished_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: queued | processing | retry | completed | partially_completed | failed | cancelled (schema.sql-Kommentar Zeile 322); Uebergaenge in app/queue.php: queue_reserve() -> processing, queue_complete() -> completed/partially_completed/failed, queue_requeue()/queue_fail() -> retry oder failed, queue_cancel() -> cancelled, queue_retry_now() -> queued
**Erzeugt durch:** app/queue.php queue_push()  
**Verändert durch:** app/queue.php queue_reserve(), queue_heartbeat(), queue_complete(), queue_requeue(), queue_update_payload(), queue_prune_payload(), queue_fail(), queue_cancel(), queue_retry_now(), queue_close(), queue_release_one()  
**Gelesen durch:** app/jobs.php job_handle() und Worker-CLI (bin/worker.php), admin-system.php (Wartende Aufgaben)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden im durchsuchten Code  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: available_at, started_at, finished_at, heartbeat_at, locked_at, closed_at DATETIME; externe IDs: correlation_id (Verknuepfung mit Log-/Audit-Eintraegen, app/log.php)  
**Migrationen:** 018_queue_worker.sql (CREATE TABLE)  
**Besonderheiten:** type entspricht den Jobtypen aus app/jobs.php (u. a. sync_run, collections_due, unclear_attempts, mail, mandate_reminders, maintenance). dedupe_key ist laut Kommentar (Zeile 337 und app/queue.php) nur fuer aktive Jobs gesetzt und sorgt dafuer, dass ein fachlicher Auftrag nur einmal gleichzeitig in der Warteschlange steht (UNIQUE KEY).  

## worker_heartbeats

**Zweck:** Lebenszeichen der laufenden Worker-Prozesse (Scheduler, Worker-Pools) fuer Betriebsueberwachung.  
**Modul:** Hintergrundverarbeitung / Warteschlange  
**Mandantenzuordnung:** keine (plattformweit, worker_id-bezogen)  
**Primärschlüssel:** worker_id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| worker_id | VARCHAR(64) | nein |  | [PK; PRIMARY KEY] |
| pool | VARCHAR(30) | nein |  | lexware \| stripe \| mail \| maintenance \| all \| scheduler (schema.sql-Kommentar Zeile 349) |
| hostname | VARCHAR(100) | ja |  | lexware \| stripe \| mail \| maintenance \| all \| scheduler |
| pid | INT | ja |  |  |
| status | VARCHAR(20) | nein | 'idle' |  |
| current_job_id | CHAR(36) | ja |  | idle \| busy \| stopping \| stopped |
| started_at | DATETIME | nein |  |  |
| heartbeat_at | DATETIME | nein |  |  |
| jobs_done | INT | nein | 0 |  |
| jobs_failed | INT | nein | 0 |  |
| version | VARCHAR(40) | ja |  |  |

**Indizes und Eindeutigkeit:** IX ix_workers_pool (pool, heartbeat_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: idle | busy | stopping | stopped (schema.sql-Kommentar Zeile 352)
**Erzeugt durch:** app/queue.php worker_register()  
**Verändert durch:** app/queue.php worker_heartbeat(), app/queue.php worker_stop() (status = stopped)  
**Gelesen durch:** admin-system.php/Statusseite (Betriebsuebersicht der Worker)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: started_at, heartbeat_at DATETIME; externe IDs: keine  
**Migrationen:** 018_queue_worker.sql (CREATE TABLE)  
**Besonderheiten:** worker_id ist Primaerschluessel (ein Datensatz je Worker-Prozess, nicht je Lauf).  

## sync_runs

**Zweck:** Historie einzelner Synchronisationslaeufe mit Lexware Office je Firma (Kennzahlen, Fehlerkategorie).  
**Modul:** Synchronisation  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| job_id | CHAR(36) | ja |  |  |
| correlation_id | CHAR(36) | ja |  |  |
| triggered_by | VARCHAR(20) | nein | 'manual' |  |
| user_id | CHAR(36) | ja |  | manual \| auto \| full \| admin \| cron |
| worker_id | VARCHAR(64) | ja |  |  |
| status | VARCHAR(20) | nein | 'running' |  |
| started_at | DATETIME | nein |  | running \| success \| partial \| failed \| cancelled |
| finished_at | DATETIME | ja |  | lokale Zeit wie sync_state (NOW()) |
| duration_ms | INT | ja |  |  |
| steps | INT | nein | 0 |  |
| checked | INT | nein | 0 |  |
| created | INT | nein | 0 |  |
| updated | INT | nein | 0 |  |
| removed | INT | nein | 0 |  |
| skipped | INT | nein | 0 |  |
| errors | INT | nein | 0 |  |
| retries | INT | nein | 0 |  |
| api_calls | INT | nein | 0 |  |
| detail_calls | INT | nein | 0 | Einzelabrufe Rechnungsdetail im Lauf (Migration 029) |
| contact_calls | INT | nein | 0 | Einzelabrufe Kontakt im Lauf (Migration 029) |
| api_ms | INT | nein | 0 | Einzelabrufe Kontakt (Migration 029) |
| throttle_ms | INT | nein | 0 |  |
| api_ms_max | INT | nein | 0 | längster einzelner API-Aufruf in Millisekunden (Migration 029) |
| cursor_bytes_max | INT | nein | 0 | größter gespeicherter Cursor (JSON) des Laufs in Byte (Migration 029) |
| error_category | VARCHAR(60) | ja |  | groesster Cursor des Laufs (Migration 029) |
| error_text | VARCHAR(500) | ja |  |  |

**Indizes und Eindeutigkeit:** IX ix_syncruns_tenant (tenant_id, started_at)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: running | success | partial | failed | cancelled (schema.sql-Kommentar Zeile 370)
**Erzeugt durch:** app/sync_state.php sync_run_open()  
**Verändert durch:** app/sync_state.php sync_run_open() (cancelled bei ueberlappendem neuem Lauf), sync_run_attach(), sync_run_finish()  
**Gelesen durch:** invoices.php (Synchronisationshistorie), admin-system.php, app/sync_perf.php (Adminbereich System, Reiter Synchronisation & Performance, 4.39)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id bei Loeschung der Firma  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: started_at (lokale Zeit wie sync_state laut Kommentar Zeile 371), finished_at DATETIME, duration_ms, api_ms, throttle_ms; externe IDs: correlation_id, job_id (Bezug zur jobs-Tabelle)  
**Migrationen:** 013_sync_performance.sql (vermutlich, da Kennzahlenspalten wie steps/checked/api_calls zur Sync-Performance-Migration passen; im Code nicht abschliessend bestaetigt) und 014_sync_lock_owner.sql (worker_id-Bezug), 029_sync_performance.sql  
**Besonderheiten:** triggered_by unterscheidet manual | auto | full | admin | cron (schema.sql-Kommentar Zeile 367).  

## api_circuits

**Zweck:** Circuit Breaker je externer API (Lexware Office, Stripe, Mail): unterbindet weitere Aufrufe nach wiederholten technischen Fehlern (CLAUDE.md: api_call_gate()).  
**Modul:** Externe Anbindungen / Stabilitaet  
**Mandantenzuordnung:** keine (plattformweit je API)  
**Primärschlüssel:** api  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| api | VARCHAR(30) | nein |  | [PK; PRIMARY KEY] |
| state | VARCHAR(10) | nein | 'closed' | lexoffice \| stripe \| mail |
| failures | INT | nein | 0 | closed \| open \| half_open |
| opened_at | DATETIME | ja |  |  |
| next_probe_at | DATETIME | ja |  |  |
| last_failure_category | VARCHAR(60) | ja |  |  |
| last_failure_at | DATETIME | ja |  |  |
| last_success_at | DATETIME | ja |  |  |
| updated_at | DATETIME | nein |  |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `state`: closed | open | half_open (schema.sql-Kommentar Zeile 393)
**Erzeugt durch:** app/queue.php circuit_success() und circuit_failure() (INSERT ... ON DUPLICATE KEY UPDATE-artig, jeweils mit INSERT IGNORE bzw. INSERT bei erstem Datensatz)  
**Verändert durch:** app/queue.php circuit_allow() (half_open), circuit_failure() (failures, state=open)  
**Gelesen durch:** app/queue.php api_call_gate() (Aufrufsperre vor externen Aufrufen)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: opened_at, next_probe_at, last_failure_at, last_success_at, updated_at DATETIME; externe IDs: keine (api ist der interne Bezeichner lexoffice|stripe|mail)  
**Migrationen:** 018_queue_worker.sql (CREATE TABLE, im Kontext der Hintergrundverarbeitung)  
**Besonderheiten:** Primaerschluessel ist der API-Name (api), ein Datensatz je externer Anbindung. last_failure_category wird laut CLAUDE.md ueber circuit_failure() nur bei technischen Fehlern gesetzt, nicht bei fachlichen Ablehnungen.  

## integrations

**Zweck:** Verbindungsdaten je Firma zu Lexware Office und Stripe (verschluesselte Zugangsdaten, Verbindungsstatus) sowie Wahl der aktiven Rechnungsquelle (Migration 024).  
**Modul:** Externe Anbindungen  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_integration_tenant] |
| lexoffice_api_key_encrypted | TEXT | ja |  |  |
| stripe_secret_key_encrypted | TEXT | ja |  |  |
| stripe_webhook_secret_encrypted | TEXT | ja |  |  |
| lexoffice_connected | TINYINT(1) | nein | 0 |  |
| lexoffice_company_name | VARCHAR(255) | ja |  |  |
| lexoffice_last_verified_at | DATETIME | ja |  |  |
| lexoffice_disconnected_at | DATETIME | ja |  |  |
| stripe_connected | TINYINT(1) | nein | 0 |  |
| stripe_account_id | VARCHAR(64) | ja |  |  |
| stripe_business_name | VARCHAR(255) | ja |  |  |
| stripe_mode | VARCHAR(8) | ja |  |  |
| stripe_last_verified_at | DATETIME | ja |  | test \| live |
| stripe_disconnected_at | DATETIME | ja |  |  |
| lexoffice_last_sync | DATETIME | ja |  |  |
| sevdesk_api_key_encrypted | TEXT | ja |  | sevdesk-API-Token, AES-256-GCM verschlüsselt; nie protokolliert oder angezeigt (Migration 028) |
| sevdesk_connected | TINYINT(1) | nein | 0 | 1 = sevdesk verbunden (Token geprüft); Verbinden nur bei freigegebener Anbindung |
| sevdesk_company_name | VARCHAR(255) | ja |  | bleibt leer: der Verbindungstest liefert keinen Firmennamen (Endpunkt nicht verifiziert) |
| sevdesk_last_verified_at | DATETIME | ja |  | letzter erfolgreicher Verbindungstest |
| sevdesk_disconnected_at | DATETIME | ja |  | Zeitpunkt der Trennung (Einstellungen oder Wechsel des Buchhaltungssystems) |
| sevdesk_last_sync | DATETIME | ja |  | letzte abgeschlossene Synchronisation einer sevdesk-Firma |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| invoice_source | VARCHAR(32) | nein | 'lexware_office' | aktuell genutztes Rechnungssystem (lexware_office \| sevdesk laut integration_providers), Standard lexware_office (Migration 024) [per ALTER ergänzt] |
| invoice_source_changed_at | DATETIME | nein | 0 | Zeitpunkt des letzten Wechsels, Grundlage einer Sperrfrist von vier Wochen bis zum naechsten Wechsel (Migrationskommentar 024) [per ALTER ergänzt] |
| invoice_source_lock_reset_at | DATETIME | ja |  | Zeitpunkt (UTC), an dem der Betreiber die Vier-Wochen-Sperre aufgehoben hat (Audit invoice_source_lock_reset) [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** UQ uq_integration_tenant (tenant_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Erzeugt durch:** app/integrations.php integration_load() (Zeile 26, legt Datensatz bei erstem Zugriff an, falls nicht vorhanden), app/auth.php _auth_register_create()/create_company() (bei Firmenanlage)  
**Verändert durch:** settings.php (lexoffice_api_key_encrypted, lexoffice_connected, stripe_secret_key_encrypted, stripe_webhook_secret_encrypted, stripe_connected und jeweilige *_disconnected_at), app/invoice_source_switch.php invoice_source_switch()/invoice_source_apply_signup() (invoice_source, invoice_source_changed_at, invoice_source_switches), app/integrations.php integration_verify_stripe()/integration_verify_lexoffice() (stripe_account_id, stripe_business_name, stripe_mode, lexoffice_company_name, jeweilige *_last_verified_at), app/sync.php sync_invoices_step()/sync_invoices() (lexoffice_last_sync), settings.php save_sevdesk/verify_sevdesk/disconnect_sevdesk, app/integrations.php integration_verify_sevdesk() (4.38), admin.php org_lock_reset über app/invoice_source_switch.php invoice_source_lock_reset() (4.38)  
**Gelesen durch:** settings.php, onboarding.php, app/sync.php, app/collections.php (Pruefung, ob Anbindungen aktiv sind)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id bei Loeschung der Firma  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: lexoffice_last_verified_at, lexoffice_disconnected_at, stripe_last_verified_at, stripe_disconnected_at, lexoffice_last_sync, created_at/updated_at DATETIME; externe IDs: stripe_account_id (Stripe-Konto der Firma, getrennt vom Plattform-Stripe-Konto in organizations)  
**Migrationen:** Basistabelle vor Migrationszaehlung, 004_integration_verification.sql (vermutlich *_last_verified_at/_disconnected_at, *_company_name/_business_name/_mode, dem Namen nach passend), 024_invoice_source_switch.sql (invoice_source_changed_at, invoice_source_switches), 028_sevdesk_verbindung.sql  
**Besonderheiten:** lexoffice_api_key_encrypted, stripe_secret_key_encrypted und stripe_webhook_secret_encrypted sind ueber app/crypto.php (AES-256-GCM) verschluesselt, laut CLAUDE.md nie im Frontend gezeigt. UNIQUE (tenant_id) macht die Tabelle 1:1 zur Firma.  

## sync_state

**Zweck:** Serverseitiger Fortschritt der laufenden Lexware-Synchronisation je Firma, damit Browser und Cron denselben Lauf fortsetzen koennen (schema.sql-Kommentar Zeile 427).  
**Modul:** Synchronisation  
**Mandantenzuordnung:** tenant_id (zugleich Primaerschluessel, 1:1 zur Firma)  
**Primärschlüssel:** tenant_id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| tenant_id | CHAR(36) | nein |  | [PK; FK → organizations.id (ON DELETE CASCADE); PRIMARY KEY] |
| status | VARCHAR(20) | nein | 'idle' |  |
| cursor_json | MEDIUMTEXT | ja |  | idle \| running \| done \| error |
| requested_by_user_id | CHAR(36) | ja |  |  |
| lock_until | DATETIME | ja |  |  |
| lock_owner | VARCHAR(64) | ja |  |  |
| skipped_starts | INT | nein | 0 | Anzahl uebersprungener Startversuche, waehrend bereits ein Lauf aktiv war |
| last_step_at | DATETIME | ja |  |  |
| started_at | DATETIME | ja |  |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| finished_at | DATETIME | ja |  |  |
| last_error | TEXT | ja |  |  |
| result_json | TEXT | ja |  |  |

**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: idle | running | done | error (schema.sql-Kommentar Zeile 430)
**Erzeugt durch:** app/sync_state.php sync_state_start()  
**Verändert durch:** app/sync_state.php sync_state_start(), sync_state_cancel(), sync_state_step(), app/jobs.php (mehrere Stellen, u. a. force_full setzen, verwaiste Laeufe schliessen), app/queue.php queue_fail() (status=error bei Jobfehler), invoices.php (Statuszuruecksetzung nach Anzeige des Ergebnisses)  
**Gelesen durch:** invoices.php (Fortschrittsanzeige, automatische Fortsetzung)  
**Löschung, Archivierung, Aufbewahrung:** nicht zutreffend (1 Zeile je Firma, kein Loeschen, nur Statuswechsel); ON DELETE CASCADE ueber tenant_id bei Loeschung der Firma  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: lock_until, last_step_at, started_at, finished_at DATETIME (lokale Zeit laut Kommentar); externe IDs: keine  
**Migrationen:** 013_sync_performance.sql (vermutlich, Kernnamen passen zu Performance-Ueberarbeitung) und 014_sync_lock_owner.sql (lock_owner)  
**Besonderheiten:** lock_owner (Migration 014) verhindert, dass zwei Prozesse gleichzeitig am selben Synchronisationsschritt arbeiten. cursor_json haelt den Fortsetzungspunkt der Lexware-Abfrage (MEDIUMTEXT).  

## webhook_events

**Zweck:** Idempotenz- und Reihenfolgeschutz fuer verarbeitete Webhook-Ereignisse (verhindert doppelte Verarbeitung).  
**Modul:** Externe Anbindungen  
**Mandantenzuordnung:** keine direkt (object_id kann auf Firmenobjekte verweisen, kein tenant_id-Feld)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | VARCHAR(255) | nein |  | [PK; PRIMARY KEY] |
| source | VARCHAR(20) | nein |  | billing \| tenant (schema.sql-Kommentar Zeile 448, unterscheidet Plattform-Abrechnungs-Webhook von firmenspezifischem Stripe-Webhook) |
| event_type | VARCHAR(60) | nein |  | billing \| tenant |
| object_id | VARCHAR(255) | ja |  |  |
| event_created | BIGINT | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_webhook_object (source, object_id)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/billing.php billing_event_claim() (INSERT IGNORE, beansprucht die Ereignis-ID exklusiv)  
**Verändert durch:** app/billing.php billing_handle_event() (object_id, event_created nachtragen)  
**Gelesen durch:** app/billing.php (Duplikatspruefung vor Verarbeitung eines Stripe-Ereignisses)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME, event_created (BIGINT, vermutlich Unix-Zeitstempel des externen Ereignisses); externe IDs: id (Stripe-Event-ID), object_id  
**Migrationen:** nicht eindeutig lokalisiert; thematisch zur Plattform-Abrechnung passend, vermutlich Teil der Abrechnungs-Einfuehrung (nicht in Migrationen 001-024 als eigenstaendiges CREATE TABLE gefunden, moeglicherweise Teil der Basistabellen oder einer nicht separat benannten Abrechnungs-Migration)  
**Besonderheiten:** id ist Primaerschluessel und entspricht der Stripe-Ereignis-ID (VARCHAR(255)), macht InsertIgnore zur Idempotenzsperre.  

## funnel_events

**Zweck:** Anonymes Trichter-/Konversions-Tracking je Herkunftsdomain (cookielos, ohne IP-Adresse laut schema.sql-Kommentar Zeile 456).  
**Modul:** Marketing und Auswertung  
**Mandantenzuordnung:** tenant_id (optional, NULL vor Firmenzuordnung)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | nein |  | [PK; PRIMARY KEY AUTO_INCREMENT] |
| domain | VARCHAR(100) | nein |  |  |
| event | VARCHAR(40) | nein |  | z. B. interest_submitted, interest_confirmed, onboarding_completed (siehe Aufrufe in app/interest.php, onboarding.php) |
| path | VARCHAR(255) | ja |  |  |
| tenant_id | CHAR(36) | ja |  |  |
| user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_funnel_domain_event (domain, event, created_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/audit.php funnel_event()  
**Gelesen durch:** app/audit.php (Auswertungsfunktionen), app/interest.php (Kennzahlen interest_submitted/interest_confirmed)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** nicht eindeutig lokalisiert (nicht als eigenstaendiges CREATE TABLE in Migrationen 001-024 gefunden, vermutlich Teil der Basistabellen)  
**Besonderheiten:** Bewusst ohne IP-Adresse zur Datensparsamkeit (DSGVO).  

## customers

**Zweck:** Kunden der Firma, synchronisiert aus Lexware Office (Kontakte).  
**Modul:** Fachdaten je Firma / Kunden  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_customer_tenant_lexoffice] |
| lexoffice_contact_id | CHAR(36) | ja |  | [UQ uq_customer_tenant_lexoffice] |
| customer_number | VARCHAR(50) | nein |  |  |
| name | VARCHAR(255) | nein |  |  |
| email | VARCHAR(255) | ja |  |  |
| is_walk_in | TINYINT(1) | nein | 0 | Laufkunde/Sammelkontakt ohne dauerhafte Kundenbeziehung (z. B. Lexware-Sammelkontakt fuer Barverkauf) |
| lexoffice_synced_at | DATETIME | ja |  |  |
| sepa_debit_enabled | TINYINT(1) | nein | 1 | steuert, ob fuer diesen Kunden ueberhaupt SEPA-Lastschrifteinzug erlaubt ist |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** UQ uq_customer_tenant_lexoffice (tenant_id, lexoffice_contact_id); IX ix_customer_number (tenant_id, customer_number)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Erzeugt durch:** app/sync.php _sync_upsert_customer()  
**Verändert durch:** app/sync.php _sync_upsert_customer() (Stammdatenabgleich aus Lexware Office), app/mandate_requests.php mandate_request_grant() (sepa_debit_enabled = 1 nach digital erteiltem Mandat), app/customer_settings.php set_customer_sepa_debit()/set_customer_iban() (sepa_debit_enabled)  
**Gelesen durch:** customer.php, invoices.php, app/collections.php (praktisch alle kundenbezogenen Ablaeufe)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id bei Loeschung der Firma  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine eigene; Zeit: lexoffice_synced_at, created_at/updated_at DATETIME; externe IDs: lexoffice_contact_id  
**Migrationen:** Basistabelle vor Migrationszaehlung, 001_add_sepa_debit_enabled.sql (sepa_debit_enabled), 013_sync_performance.sql (lexoffice_synced_at)  
**Besonderheiten:** UNIQUE (tenant_id, lexoffice_contact_id) verhindert doppelte Uebernahme desselben Lexware-Kontakts.  

## customer_ibans

**Zweck:** Bankverbindungen (IBAN/BIC) eines Kunden, mit Historie ueber Aktivierung/Deaktivierung.  
**Modul:** Fachdaten je Firma / SEPA-Mandate  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| customer_id | CHAR(36) | nein |  | [FK → customers.id (ON DELETE CASCADE)] |
| iban | VARCHAR(34) | nein |  |  |
| bic | VARCHAR(11) | ja |  |  |
| account_holder_name | VARCHAR(255) | nein |  |  |
| is_active | TINYINT(1) | nein | 1 |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| source | VARCHAR(20) | nein | 'manual' | manual (im Portal erfasst) \| stripe_digital (aus digitaler Mandatsanforderung, schema.sql-Kommentar Zeile 722) [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** IX ix_customer_iban_active (customer_id, is_active); IX ix_iban_tenant (tenant_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE); customer_id → customers.id (ON DELETE CASCADE)  
**Erzeugt durch:** app/mandate_requests.php mandate_request_grant() (nach digital erteiltem Mandat, source=stripe_digital), app/customer_settings.php set_customer_iban() (manuelle Erfassung im Portal, source=manual)  
**Verändert durch:** app/mandate_requests.php mandate_request_grant() (is_active=0 bei Ersetzung), app/customer_settings.php set_customer_iban()/deactivate_customer_iban() (is_active)  
**Gelesen durch:** app/mandates.php, app/collections.php (Einzug), customer.php  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden (Deaktivierung statt Loeschung ueber is_active); ON DELETE CASCADE ueber tenant_id und customer_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at/updated_at DATETIME; externe IDs: keine (IBAN/BIC selbst sind Bankdaten, keine externen Systemreferenzen)  
**Migrationen:** Basistabelle vor Migrationszaehlung, 006_payment_safety.sql (source-Spalte, Herkunft der Bankverbindung)  
**Besonderheiten:** Bei digitaler Mandatsanforderung (source=stripe_digital) liegt die IBAN laut schema.sql-Kommentar (Zeile 723) nur maskiert vor.  

## iban_history

**Zweck:** Aenderungshistorie zu Bankverbindungen (wer hat wann was geaendert und warum).  
**Modul:** Fachdaten je Firma / SEPA-Mandate  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  |  |
| customer_iban_id | CHAR(36) | nein |  | [FK → customer_ibans.id (ON DELETE CASCADE)] |
| action | VARCHAR(20) | nein |  |  |
| old_iban | VARCHAR(34) | ja |  | created \| deactivated \| reactivated |
| new_iban | VARCHAR(34) | ja |  |  |
| changed_by | CHAR(36) | nein |  | Benutzer-ID, die die Aenderung vorgenommen hat (NOT NULL) |
| change_reason | TEXT | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_history_iban (customer_iban_id); IX ix_history_tenant (tenant_id)  
**Von der Datenbank erzwungene Beziehungen:** customer_iban_id → customer_ibans.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `action`: created | deactivated | reactivated (schema.sql-Kommentar Zeile 508)
**Erzeugt durch:** app/mandate_requests.php mandate_request_grant(), app/customer_settings.php set_customer_iban()/deactivate_customer_iban()  
**Gelesen durch:** customer.php (Verlaufsanzeige)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber customer_iban_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** Basistabelle vor Migrationszaehlung  
**Besonderheiten:** Reines Anhaengeprotokoll (nur INSERT, keine UPDATE-Anweisung gefunden).  

## sepa_mandates

**Zweck:** SEPA-Lastschriftmandate der Kunden: Referenz, Unterschrift, Verfall (schema.sql-Kommentar Zeile 519f.), Bezug zu Stripe-Mandat/Zahlungsmethode.  
**Modul:** Fachdaten je Firma / SEPA-Mandate  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_mandate_tenant_reference] |
| customer_id | CHAR(36) | nein |  | [FK → customers.id (ON DELETE CASCADE)] |
| customer_iban_id | CHAR(36) | ja |  | [FK → customer_ibans.id] |
| mandate_reference | VARCHAR(35) | nein |  | SEPA-Mandatsreferenz, vergeben aus organizations.mandate_prefix + Kundennummer (customer.php-Hinweistext) [UQ uq_mandate_tenant_reference] |
| mandate_date | DATE | nein |  |  |
| is_active | TINYINT(1) | nein | 1 |  |
| status | VARCHAR(20) | nein | 'active' |  |
| mandate_type | VARCHAR(10) | nein | 'recurrent' | recurrent \| one_off (schema.sql-Kommentar Zeile 530) |
| signed_date | DATE | ja |  | recurrent \| one_off |
| signed_place | VARCHAR(100) | ja |  |  |
| creditor_identifier | VARCHAR(35) | ja |  | je Mandat gespeicherte Glaeubiger-ID (kann von organizations.creditor_identifier abweichen, falls sich diese spaeter aendert) |
| document_generated_at | DATETIME | ja |  |  |
| document_generated_by | CHAR(36) | ja |  |  |
| last_used_at | DATETIME | ja |  |  |
| cancelled_at | DATETIME | ja |  |  |
| cancel_reason | VARCHAR(255) | ja |  |  |
| stripe_payment_method_id | VARCHAR(255) | ja |  |  |
| stripe_customer_id | VARCHAR(255) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| stripe_mandate_id | VARCHAR(255) | ja |  | [per ALTER ergänzt] |
| stripe_mandate_reference | VARCHAR(64) | ja |  | [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** UQ uq_mandate_tenant_reference (tenant_id, mandate_reference); IX ix_mandate_customer (customer_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE); customer_id → customers.id (ON DELETE CASCADE); customer_iban_id → customer_ibans.id  
**Statuswerte und Übergänge:**  
- `status`: draft | active | cancelled | expired (schema.sql-Kommentar Zeile 529); Uebergaenge: mandate_cancel() -> cancelled, mandate_check_usable() -> expired nach 36 Monaten ohne Nutzung (MANDATE_EXPIRY_MONTHS in app/mandates.php)
**Erzeugt durch:** app/mandates.php get_or_create_mandate() (kein direkter INSERT-Treffer in der Grep-Suche gefunden, Funktion legt laut Namen neue Mandate an; nicht abschliessend im Code bestaetigt, INSERT vermutlich in get_or_create_mandate() selbst)  
**Verändert durch:** app/mandates.php get_or_create_mandate() (customer_iban_id, Ruecksetzen der Stripe-Zahlungsmethode bei IBAN-Wechsel), app/mandates.php mandate_mark_document_generated() (document_generated_at, document_generated_by), app/mandates.php mandate_mark_signed() (signed_date, signed_place, mandate_date), app/mandates.php mandate_cancel() (is_active=0, status=cancelled, cancelled_at, cancel_reason), app/mandates.php mandate_touch_used() (last_used_at, bei jeder Nutzung im Einzug), app/mandates.php mandate_check_usable() (is_active=0, status=expired nach 36-Monats-Regel), app/collections.php store_stripe_mandate_data()/register_iban_with_stripe()/_submit_collection_locked()/_submit_single_scheduled() (stripe_mandate_id, stripe_mandate_reference, stripe_payment_method_id, stripe_customer_id)  
**Gelesen durch:** customer.php, app/collections.php (Einzug), app/mandate_files.php  
**Löschung, Archivierung, Aufbewahrung:** kein Loeschen, nur Statuswechsel auf cancelled/expired; ON DELETE CASCADE ueber tenant_id und customer_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine eigene (Betrag steht im Einzug payment_collections); Zeit: mandate_date, signed_date DATE, document_generated_at, last_used_at, cancelled_at, created_at/updated_at DATETIME; externe IDs: stripe_payment_method_id, stripe_customer_id, stripe_mandate_id, stripe_mandate_reference  
**Migrationen:** Basistabelle vor Migrationszaehlung, 005_mandate_files.sql (Bezug zu mandate_files), 006_payment_safety.sql (stripe_mandate_id, stripe_mandate_reference)  
**Besonderheiten:** MANDATE_EXPIRY_MONTHS = 36 (app/mandates.php): Ein Mandat erlischt, wenn 36 Monate lang keine Lastschrift eingezogen wurde (schema.sql-Kommentar Zeile 520, Kommentar app/mandates.php Zeile 15-20, handels- und steuerrechtliche Empfehlung). UNIQUE (tenant_id, mandate_reference).  

## invoices

**Zweck:** Aus Lexware Office synchronisierte Rechnungen (Belege) je Firma, mit Einzugsstatus.  
**Modul:** Fachdaten je Firma / Rechnungen  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE); UQ uq_invoice_tenant_lexoffice] |
| lexoffice_invoice_id | CHAR(36) | nein |  | [UQ uq_invoice_tenant_lexoffice] |
| voucher_number | VARCHAR(50) | nein |  |  |
| customer_id | CHAR(36) | ja |  | [FK → customers.id (ON DELETE SET NULL)] |
| contact_name | VARCHAR(255) | nein |  |  |
| total_gross_amount | DECIMAL(10,2) | nein |  |  |
| currency | CHAR(3) | nein | 'EUR' |  |
| due_date | DATE | ja |  |  |
| lexoffice_status | VARCHAR(50) | nein |  |  |
| lexoffice_updated_at | DATETIME | ja |  |  |
| collection_status | VARCHAR(20) | nein | 'none' | updatedDate laut Voucherliste (Migration 013) |
| line_items_json | MEDIUMTEXT | ja |  | Rechnungspositionen aus Lexware Office als JSON |
| last_synced_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| open_amount | DECIMAL(10,2) | ja |  | Restbetrag laut Lexware Office (Payments-Endpunkt), kann vom Rechnungsbetrag abweichen bei Teilzahlung [per ALTER ergänzt] |
| open_amount_fetched_at | DATETIME | ja |  | [per ALTER ergänzt] |
| requires_review | TINYINT(1) | nein | 0 | [per ALTER ergänzt] |
| review_reason | VARCHAR(255) | ja |  | [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** UQ uq_invoice_tenant_lexoffice (tenant_id, lexoffice_invoice_id); IX ix_invoice_customer (customer_id); IX ix_invoice_tenant_status (tenant_id, lexoffice_status)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE); customer_id → customers.id (ON DELETE SET NULL)  
**Statuswerte und Übergänge:**  
- `collection_status`: none | open | in_collection | collected | failed | scheduled (schema.sql-Kommentar Zeile 562). Uebergaenge (app/collections.php, app/sync.php, stripe-webhook.php): open -> scheduled (Terminierung, _submit_collection_locked()) -> in_collection (Einreichung) -> collected/failed (Stripe-Webhook bzw. sync_collection_statuses()); collections_cancel_all_pending() setzt scheduled zurueck auf open.
**Erzeugt durch:** app/sync.php _sync_process_voucher() (kein direkter INSERT-Treffer in Grep-Suche, vermutlich INSERT innerhalb dieser Funktion beim erstmaligen Anlegen einer Rechnung; nicht abschliessend im Code bestaetigt)  
**Verändert durch:** app/stripe_import.php stripe_import_apply() (collection_status bei Uebernahme importierter Einzuege), app/sync.php sync_invoices_step()/sync_invoices()/_sync_process_voucher() (lexoffice_status, collection_status, lexoffice_updated_at, last_synced_at, voucher_number, customer_id, contact_name, ... bei jeder Synchronisation), app/collections.php collections_cancel_all_pending(), _persist_open_amount() (open_amount, open_amount_fetched_at), _attempt_backfill_collection(), invoice_review_clear() (requires_review, review_reason), _submit_collection_locked(), cancel_scheduled_collection(), sync_collection_statuses(), process_scheduled_collections(), _submit_single_scheduled(), stripe-webhook.php (collection_status bei erfolgreichem/fehlgeschlagenem Einzug laut Stripe-Ereignis)  
**Gelesen durch:** invoices.php, app/collections.php (praktisch alle Rechnungsablaeufe)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id, ON DELETE SET NULL ueber customer_id bei Loeschung des Kunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: total_gross_amount, open_amount DECIMAL(10,2); Zeit: due_date DATE, lexoffice_updated_at, last_synced_at, open_amount_fetched_at, created_at/updated_at DATETIME; externe IDs: lexoffice_invoice_id  
**Migrationen:** Basistabelle vor Migrationszaehlung, 013_sync_performance.sql (lexoffice_updated_at), 006_payment_safety.sql (open_amount, open_amount_fetched_at), 007_refunds_alerts.sql (requires_review, review_reason)  
**Besonderheiten:** UNIQUE (tenant_id, lexoffice_invoice_id). requires_review=1 blockiert laut schema.sql-Kommentar (Zeile 824) jede Einreichung, bis Inhaber oder Administrator die Klaerung abschliesst.  

## payment_collections

**Zweck:** Einzelner SEPA-Lastschrifteinzug zu einer Rechnung ueber Stripe (Kernobjekt des Geldflusses).  
**Modul:** Fachdaten je Firma / Einzuege  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [UQ uq_collection_tenant_pi] |
| invoice_id | CHAR(36) | nein |  | [FK → invoices.id (ON DELETE CASCADE)] |
| mandate_id | CHAR(36) | ja |  | [FK → sepa_mandates.id] |
| customer_iban_id | CHAR(36) | ja |  | NULL bei importierten Einzügen (Migration 009) [FK → customer_ibans.id] |
| amount_cents | INT | nein |  |  |
| currency | CHAR(3) | nein | 'EUR' |  |
| stripe_payment_intent_id | VARCHAR(255) | ja |  | [UQ uq_collection_tenant_pi] |
| stripe_status | VARCHAR(50) | ja |  |  |
| submitted_at | DATETIME | ja |  | scheduled\|submitting\|processing\|succeeded\|failed\|disputed\|refunded\|cancelled |
| completed_at | DATETIME | ja |  |  |
| failure_reason | TEXT | ja |  |  |
| description | VARCHAR(140) | ja |  |  |
| scheduled_date | DATE | ja |  |  |
| submit_not_before | DATETIME | ja |  | fruehester Einreichzeitpunkt (Karenzzeit, Einreichfenster; Migration 011); begrenzt laut CLAUDE.md ausschliesslich das Einreichen, nicht andere Ablaeufe |
| queued_immediate | TINYINT(1) | nein | 0 | 1 = vorgemerkter Sofort-Einzug (schema.sql-Kommentar Zeile 591) |
| is_scheduled | TINYINT(1) | nein | 0 | 1 = vorgemerkter Sofort-Einzug |
| scheduled_submitted | TINYINT(1) | nein | 0 |  |
| created_by_user_id | CHAR(36) | ja |  |  |
| prenotified_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| ein | Einzug | ja |  |  |
| note | VARCHAR(255) | ja |  | [per ALTER ergänzt] |
| stripe_charge_id | VARCHAR(255) | ja |  | [per ALTER ergänzt] |
| refunded_cents | INT | nein | 0 | [per ALTER ergänzt] |
| refunded_at | DATETIME | ja |  | [per ALTER ergänzt] |
| refund_note | VARCHAR(255) | ja |  | [per ALTER ergänzt] |
| source | VARCHAR(20) | nein | 'app' | app (im Portal ausgeloest) oder Herkunft aus Stripe-Import (Migration 009) [per ALTER ergänzt] |
| imported_mandate_reference | VARCHAR(35) | ja |  | [per ALTER ergänzt] |

**Indizes und Eindeutigkeit:** UQ uq_collection_tenant_pi (tenant_id, stripe_payment_intent_id); IX ix_collection_tenant (tenant_id); IX ix_collection_pi (stripe_payment_intent_id); IX ix_collection_scheduled (is_scheduled, scheduled_submitted, scheduled_date); IX ix_collection_tenant_status (tenant_id, stripe_status)  
**Von der Datenbank erzwungene Beziehungen:** invoice_id → invoices.id (ON DELETE CASCADE); mandate_id → sepa_mandates.id; customer_iban_id → customer_ibans.id  
**Statuswerte und Übergänge:**  
- `stripe_status`: scheduled | submitting | processing | succeeded | failed | disputed | refunded | cancelled (schema.sql-Kommentar Zeile 584). Uebergaenge: scheduled -> submitting (_submit_single_scheduled()) -> processing (stripe-webhook.php) -> succeeded/failed (Stripe-Webhook, sync_collection_statuses()); scheduled -> cancelled (collections_cancel_all_pending(), cancel_scheduled_collection()).
**Erzeugt durch:** app/collections.php (kein direkter INSERT-Treffer in Grep-Suche fuer payment_collections; INSERT vermutlich in einer Funktion wie schedule_collection()/create_collection() in app/collections.php, nicht abschliessend per Grep bestaetigt), app/stripe_import.php (Uebernahme importierter Einzuege)  
**Verändert durch:** app/collections.php collections_cancel_all_pending(), collection_attempts_resolve(), store_stripe_mandate_data() (stripe_charge_id), _submit_collection_locked() (prenotified_at), cancel_scheduled_collection(), reschedule_collection() (scheduled_date, submit_not_before, queued_immediate, prenotified_at), sync_collection_statuses(), process_scheduled_collections() (note, stripe_status, failure_reason), _submit_single_scheduled() (stripe_status, customer_iban_id, mandate_id, amount_cents, note), stripe-webhook.php (stripe_status, completed_at anhand Stripe-Ereignis)  
**Gelesen durch:** invoices.php, customer.php, app/collections.php (Einzugsuebersicht)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id und invoice_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: amount_cents INT, refunded_cents INT; Zeit: submitted_at, completed_at, refunded_at, scheduled_date DATE, submit_not_before, prenotified_at, created_at/updated_at DATETIME; externe IDs: stripe_payment_intent_id, stripe_charge_id  
**Migrationen:** Basistabelle vor Migrationszaehlung, 006_payment_safety.sql (note, stripe_charge_id), 007_refunds_alerts.sql (refunded_cents, refunded_at, refund_note), 009_stripe_import.sql (source, imported_mandate_reference), 011_collection_queue.sql (submit_not_before, queued_immediate)  
**Besonderheiten:** mandate_id kann laut Kommentar (Zeile 579) NULL sein bei importierten Einzuegen (Migration 009). Fremdschluessel zu sepa_mandates und customer_ibans ohne ON DELETE-Regel (nur per Anwendungscode abgesichert, da Mandate/IBANs typischerweise nicht geloescht, nur deaktiviert werden).  

## support_sessions

**Zweck:** Zeitlich begrenzter Support-Zugriff des Plattformbetreibers auf einen Firmenaccount (Migration 008, siehe app/support.php).  
**Modul:** Support und Administration  
**Mandantenzuordnung:** organization_id (Zielfirma des Support-Zugriffs)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| admin_user_id | CHAR(36) | nein |  |  |
| admin_email | VARCHAR(255) | nein |  |  |
| organization_id | CHAR(36) | nein |  |  |
| reason | VARCHAR(255) | nein |  | vom Administrator angegebener Grund des Support-Zugriffs (Pflichtfeld, NOT NULL) |
| token_hash | CHAR(64) | ja |  |  |
| redeem_expires_at | DATETIME | nein |  |  |
| redeemed_at | DATETIME | ja |  |  |
| expires_at | DATETIME | nein |  |  |
| ended_at | DATETIME | ja |  |  |
| ended_by | VARCHAR(20) | ja |  |  |
| ip | VARCHAR(45) | ja |  | admin\|expired\|revoked\|logout |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_support_org (organization_id, created_at); IX ix_support_token (token_hash); IX ix_support_admin (admin_user_id, created_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/support.php support_session_create()  
**Verändert durch:** app/support.php support_session_redeem() (redeemed_at, token_hash=NULL), app/support.php support_session_end() (ended_at, ended_by), app/support.php support_sessions_expire() (zeitgesteuertes Beenden abgelaufener Sitzungen)  
**Gelesen durch:** app/support.php (Zugriffspruefung waehrend aktiver Support-Sitzung)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: redeem_expires_at, redeemed_at, expires_at, ended_at, created_at DATETIME; externe IDs: keine  
**Migrationen:** 008_support_sessions.sql (CREATE TABLE)  
**Besonderheiten:** token_hash wird nach Einloesung bzw. Beendigung auf NULL gesetzt (Einmalgebrauch). ended_by: admin | expired | revoked | logout (schema.sql-Kommentar Zeile 619).  

## mandate_files

**Zweck:** Hochgeladene Mandatsdokumente (PDF/JPG/PNG) je Kunde, Dateien liegen unter app/storage/mandates/ (schema.sql-Kommentar Zeile 627).  
**Modul:** Fachdaten je Firma / SEPA-Mandate  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| customer_id | CHAR(36) | nein |  | [FK → customers.id (ON DELETE CASCADE)] |
| mandate_id | CHAR(36) | ja |  | [FK → sepa_mandates.id (ON DELETE SET NULL)] |
| original_name | VARCHAR(255) | nein |  |  |
| mime_type | VARCHAR(100) | nein |  |  |
| size_bytes | INT UNSIGNED | nein |  |  |
| sha256 | CHAR(64) | nein |  |  |
| stored_name | VARCHAR(64) | nein |  |  |
| note | VARCHAR(255) | ja |  |  |
| uploaded_by_user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX idx_mandate_files_customer (tenant_id, customer_id); IX idx_mandate_files_mandate (mandate_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE); customer_id → customers.id (ON DELETE CASCADE); mandate_id → sepa_mandates.id (ON DELETE SET NULL)  
**Erzeugt durch:** app/mandate_files.php mandate_file_store()  
**Gelesen durch:** customer.php (Anzeige/Download hochgeladener Mandatsdokumente)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden im durchsuchten Code (nur INSERT gefunden)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** 005_mandate_files.sql (CREATE TABLE)  
**Besonderheiten:** sha256 sichert die Unversehrtheit der gespeicherten Datei; stored_name ist der auf dem Dateisystem verwendete, vom Originalnamen entkoppelte Name. ON DELETE CASCADE ueber tenant_id/customer_id, ON DELETE SET NULL ueber mandate_id.  

## collection_attempts

**Zweck:** Versuchsjournal: jeder Stripe-Aufruf zu einem Einzug wird VOR dem Aufruf mit Idempotenz-Schluessel festgehalten, damit bei Abbruch der Transaktion der Versuch nachvollziehbar bleibt (schema.sql-Kommentar Zeile 664-671, Migration 006).  
**Modul:** Fachdaten je Firma / Einzuege / Zahlungssicherheit  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  |  |
| collection_id | CHAR(36) | ja |  |  |
| invoice_id | CHAR(36) | nein |  |  |
| idempotency_key | CHAR(64) | nein |  | [UQ uq_attempt_key] |
| amount_cents | INT | nein |  |  |
| status | VARCHAR(12) | nein | 'pending' |  |
| stripe_payment_intent_id | VARCHAR(255) | ja |  | pending\|succeeded\|failed\|unknown |
| error_text | TEXT | ja |  |  |
| created_by_user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** UQ uq_attempt_key (idempotency_key); IX ix_attempt_invoice (tenant_id, invoice_id, status); IX ix_attempt_tenant_status (tenant_id, status)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: pending (Aufruf laeuft) | succeeded | failed (Stripe hat abgelehnt, neuer Versuch erlaubt) | unknown (Zeitueberschreitung oder Netzwerkfehler, Ergebnis unbekannt, kein neuer Versuch ohne Klaerung) laut schema.sql-Kommentar Zeile 668-670
**Erzeugt durch:** app/collections.php collection_attempt_begin()  
**Verändert durch:** app/collections.php collection_attempt_finish()  
**Gelesen durch:** app/collections.php collection_attempts_resolve() (Abgleich unklarer Versuche gegen Stripe)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: amount_cents INT; Zeit: created_at/updated_at DATETIME; externe IDs: idempotency_key, stripe_payment_intent_id  
**Migrationen:** 006_payment_safety.sql (CREATE TABLE)  
**Besonderheiten:** UNIQUE (idempotency_key) auf eigener Datenbankverbindung geschrieben (laut Kommentar), damit der Eintrag auch bei Abbruch der Einzugs-Transaktion erhalten bleibt: zentrales Bauteil der Zahlungssicherheit gegen Doppelabbuchung.  

## platform_settings

**Zweck:** Einfacher Schluessel-Wert-Speicher fuer plattformweite Einstellungen, u. a. der plattformweite Not-Stopp fuer Einzuege (schema.sql-Kommentar Zeile 691-693).  
**Modul:** Sicherheit / Zahlungssicherheit  
**Mandantenzuordnung:** keine (plattformweit)  
**Primärschlüssel:** key  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| key | VARCHAR(64) | nein |  | [PK; PRIMARY KEY] |
| value | VARCHAR(255) | ja |  |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** sql/schema.sql INSERT IGNORE (Startwert collections_paused=0), app/collections.php platform_setting_set() (ON DUPLICATE KEY UPDATE, wirkt zugleich als Anlage und Aenderung)  
**Verändert durch:** app/collections.php platform_setting_set()  
**Gelesen durch:** app/collections.php (Pruefung des plattformweiten Not-Stopps vor jeder Einreichung)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: updated_at DATETIME; externe IDs: keine  
**Migrationen:** 006_payment_safety.sql (CREATE TABLE)  
**Besonderheiten:** Laut CLAUDE.md kann der plattformweite Not-Stopp nur per SQL gesetzt werden (nicht ueber die Oberflaeche), siehe docs/payment-safety.md. `key` und `value` sind reservierte Bezeichner und daher in Backticks.  

## mandate_requests

**Zweck:** Digitale Mandatsanforderung per Stripe Checkout (mode=setup): Versand eines Links an den Kunden, der die Bankverbindung selbst hinterlegt (schema.sql-Kommentar Zeile 727-731).  
**Modul:** Fachdaten je Firma / SEPA-Mandate  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| customer_id | CHAR(36) | nein |  | [FK → customers.id (ON DELETE CASCADE)] |
| token_hash | CHAR(64) | nein |  | [UQ uq_mandate_request_token] |
| status | VARCHAR(12) | nein | 'requested' |  |
| expires_at | DATETIME | nein |  |  |
| stripe_checkout_session_id | VARCHAR(255) | ja |  |  |
| stripe_setup_intent_id | VARCHAR(255) | ja |  |  |
| stripe_payment_method_id | VARCHAR(255) | ja |  |  |
| stripe_mandate_id | VARCHAR(255) | ja |  |  |
| mandate_id | CHAR(36) | ja |  |  |
| reminders_sent | TINYINT | nein | 0 | Anzahl gesendeter Erinnerungen an den Kunden, den Link einzuloesen |
| last_reminded_at | DATETIME | ja |  |  |
| granted_at | DATETIME | ja |  |  |
| revoked_at | DATETIME | ja |  |  |
| created_by_user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** UQ uq_mandate_request_token (token_hash); IX ix_mandate_request_customer (tenant_id, customer_id, status); IX ix_mandate_request_session (stripe_checkout_session_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE); customer_id → customers.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: requested (Link versendet) | pending (Checkout gestartet) | granted | unusable | revoked | expired (schema.sql-Kommentar Zeile 729-731)
**Erzeugt durch:** app/mandate_requests.php mandate_request_create()  
**Verändert durch:** app/mandate_requests.php mandate_requests_for_customer() (automatisches Setzen von expired bei Ablauf beim Lesen), mandate_request_create() (revoked bei Ersetzung einer bestehenden Anfrage), mandate_request_revoke() (revoked), mandate_request_load_by_token() (expired bei Tokenablauf), mandate_request_start_checkout() (pending, stripe_checkout_session_id), mandate_request_grant() (granted, granted_at, stripe_setup_intent_id, stripe_payment_method_id, stripe_mandate_id, mandate_id; unusable bei Fehlschlag), mandate_request_remind() (token_hash-Erneuerung, reminders_sent, last_reminded_at)  
**Gelesen durch:** customer.php (Status der Mandatsanforderung), app/jobs.php job_mandate_reminders()  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id und customer_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: expires_at, granted_at, revoked_at, last_reminded_at, created_at DATETIME; externe IDs: stripe_checkout_session_id, stripe_setup_intent_id, stripe_payment_method_id, stripe_mandate_id  
**Migrationen:** 006_payment_safety.sql (CREATE TABLE)  
**Besonderheiten:** token_hash speichert laut Kommentar (Zeile 728) nur den Hash des Links, UNIQUE (token_hash).  

## collection_rules

**Zweck:** Regelautomatik fuer wiederkehrende Einzuege, laut schema.sql-Kommentar (Zeile 758-760) nur ein Geruest: is_active bleibt 0, es gibt keine Verarbeitung, nur eine Vorschau (collection_rules_preview()) ohne Einreichung.  
**Modul:** Fachdaten je Firma / Einzuege (Vorstufe, nicht produktiv)  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| is_active | TINYINT(1) | nein | 0 |  |
| start_date | DATE | ja |  |  |
| customer_scope | VARCHAR(10) | nein | 'selected' |  |
| customer_ids_json | TEXT | ja |  | all \| selected |
| max_amount_cents | INT | ja |  |  |
| max_per_run | INT | ja |  |  |
| require_second_approval | TINYINT(1) | nein | 1 | vorgesehene Vier-Augen-Freigabe fuer automatisierte Einzuege (noch ohne Wirkung, da is_active nie 1 wird) |
| created_by_user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_rule_tenant (tenant_id)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Erzeugt durch:** nicht gefunden (keine INSERT-Anweisung im durchsuchten PHP-Code, nur Lesezugriff in app/collection_rules.php)  
**Gelesen durch:** app/collection_rules.php collection_rules_preview()  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: max_amount_cents INT (Obergrenze); Zeit: start_date DATE, created_at DATETIME; externe IDs: keine  
**Migrationen:** 006_payment_safety.sql (CREATE TABLE)  
**Besonderheiten:** Aktuell ohne Schreibpfad im Code auffindbar; passt zum Kommentar, dass es sich um ein reines Geruest ohne produktive Verarbeitung handelt.  

## integration_providers

**Zweck:** Registry der Anbindungen (Rechnungssysteme und Zahlungsdienstleister), Adaptergrenze fuer weitere Integrationen wie sevdesk (CLAUDE.md, schema.sql-Kommentar Zeile 778-779).  
**Modul:** Externe Anbindungen  
**Mandantenzuordnung:** keine (plattformweit, Stammdaten-/Referenztabelle)  
**Primärschlüssel:** code  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| code | VARCHAR(32) | nein |  | [PK; PRIMARY KEY] |
| name | VARCHAR(80) | nein |  |  |
| kind | VARCHAR(20) | nein |  |  |
| status | VARCHAR(20) | nein | 'planned' | invoice_system \| payment_provider |
| capabilities_json | TEXT | ja |  | JSON-Liste unterstuetzter Fähigkeiten je Anbindung (z. B. read_customers, sepa_debit) |
| api_version | VARCHAR(20) | ja |  |  |
| notes | VARCHAR(500) | ja |  |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: planned | development | closed_test | released (schema.sql-Kommentar Zeile 784)
**Erzeugt durch:** sql/schema.sql INSERT IGNORE (Startdaten: lexware_office, sevdesk, stripe); keine INSERT-Anweisung im PHP-Code gefunden  
**Verändert durch:** nicht gefunden (keine UPDATE-Anweisung im durchsuchten PHP-Code)  
**Gelesen durch:** app/integration_state.php, app/sevdesk.php, app/interest.php (Vormerkung je provider_code), app/invoice_source_switch.php  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: updated_at DATETIME; externe IDs: keine  
**Migrationen:** 006_payment_safety.sql (CREATE TABLE), 024_invoice_source_switch.sql (Nutzung als Fremdschluesselziel fuer integrations.invoice_source)  
**Besonderheiten:** code ist Primaerschluessel (z. B. lexware_office, sevdesk, stripe). Laut CLAUDE.md zu sevdesk nur die vorsichtige Formulierung verwenden, keine Partnerschafts- oder Zertifizierungsbehauptung.  

## stripe_imports

**Zweck:** Lauf zum Import bestehender, ausserhalb des Portals getätigter Stripe-Einzuege in die Datenbank (Migration 009, app/stripe_import.php).  
**Modul:** Fachdaten je Firma / Einzuege (einmaliger Altdatenimport)  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| status | VARCHAR(20) | nein | 'loading' |  |
| period_months | INT | nein | 6 | loading \| preview \| done \| discarded |
| created_gte | DATETIME | nein |  |  |
| cursor_pi | VARCHAR(255) | ja |  |  |
| pages_fetched | INT | nein | 0 |  |
| fetched_count | INT | nein | 0 |  |
| imported_count | INT | nein | 0 |  |
| created_by_user_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |
| finished_at | DATETIME | ja |  |  |
| last_error | TEXT | ja |  |  |

**Indizes und Eindeutigkeit:** IX ix_stripe_import_tenant (tenant_id, created_at)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: loading | preview | done | discarded (schema.sql-Kommentar Zeile 847)
**Erzeugt durch:** app/stripe_import.php stripe_import_start()  
**Verändert durch:** app/stripe_import.php stripe_import_start() (discarded bei vorherigem offenem Lauf), stripe_import_discard(), stripe_import_fetch() (cursor_pi, pages_fetched, fetched_count, last_error, status=preview), stripe_import_apply() (status=done, imported_count)  
**Gelesen durch:** app/stripe_import.php (Fortschrittsanzeige des Imports im Portal)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine eigene; Zeit: created_gte, created_at/updated_at, finished_at DATETIME; externe IDs: cursor_pi (Stripe-Paginierungscursor)  
**Migrationen:** 009_stripe_import.sql (CREATE TABLE)  
**Besonderheiten:** period_months begrenzt den Importzeitraum rueckwirkend.  

## stripe_import_items

**Zweck:** Einzelne bei einem Stripe-Import gefundene Zahlungen mit Abgleichsergebnis gegen bestehende Rechnungen/Einzuege.  
**Modul:** Fachdaten je Firma / Einzuege (einmaliger Altdatenimport)  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| import_id | CHAR(36) | nein |  | [FK → stripe_imports.id (ON DELETE CASCADE); UQ ux_import_item] |
| tenant_id | CHAR(36) | nein |  |  |
| payment_intent_id | VARCHAR(255) | nein |  | [UQ ux_import_item] |
| stripe_created_at | DATETIME | nein |  |  |
| amount_cents | INT | nein |  |  |
| currency | CHAR(3) | nein | 'EUR' |  |
| pi_status | VARCHAR(40) | nein |  |  |
| charge_id | VARCHAR(255) | ja |  |  |
| amount_refunded_cents | INT | nein | 0 |  |
| disputed | TINYINT(1) | nein | 0 |  |
| failure_message | VARCHAR(255) | ja |  |  |
| voucher_number | VARCHAR(50) | ja |  |  |
| customer_number | VARCHAR(50) | ja |  |  |
| mandate_reference | VARCHAR(35) | ja |  |  |
| description | VARCHAR(255) | ja |  |  |
| match_state | VARCHAR(30) | nein |  | Ergebnis des automatischen Abgleichs mit vorhandenen Rechnungen/Kundennummern/Mandatsreferenzen vor der Uebernahme |
| invoice_id | CHAR(36) | ja |  | matched \| already_known \| invoice_missing \| amount_mismatch \| invoice_has_collection \| not_ours |
| collection_id | CHAR(36) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** UQ ux_import_item (import_id, payment_intent_id); IX ix_import_item_tenant (tenant_id, match_state)  
**Von der Datenbank erzwungene Beziehungen:** import_id → stripe_imports.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `match_state`: matched | already_known | invoice_missing | amount_mismatch | invoice_has_collection | not_ours (schema.sql-Kommentar Zeile 880)
**Erzeugt durch:** app/stripe_import.php stripe_import_fetch() (kein direkter INSERT-Treffer in Grep-Suche, vermutlich INSERT innerhalb dieser Funktion beim Einlesen der Stripe-Seiten; nicht abschliessend im Code bestaetigt)  
**Verändert durch:** app/stripe_import.php stripe_import_apply() (collection_id nach Uebernahme in payment_collections)  
**Gelesen durch:** app/stripe_import.php (Vorschau vor Uebernahme)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber import_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: amount_cents, amount_refunded_cents INT; Zeit: stripe_created_at, created_at DATETIME; externe IDs: payment_intent_id, charge_id  
**Migrationen:** 009_stripe_import.sql (CREATE TABLE)  
**Besonderheiten:** UNIQUE (import_id, payment_intent_id) verhindert doppelte Erfassung derselben Stripe-Zahlung je Importlauf.  

## schema_migrations

**Zweck:** Stand der automatisch angewendeten Migrationen (app/migrate.php), verhindert doppelte Ausfuehrung.  
**Modul:** Betrieb / Deployment  
**Mandantenzuordnung:** keine (plattformweit, technische Tabelle)  
**Primärschlüssel:** version  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| version | VARCHAR(10) | nein |  | [PK; PRIMARY KEY] |
| filename | VARCHAR(255) | nein |  |  |
| applied_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| applied_by | VARCHAR(40) | nein | 'auto' |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: running | success | failed | unknown (abgeleitet aus app/migrate.php migrations_run(): 'running' beim Start, 'success'/'failed' beim Abschluss, 'unknown' bei abgebrochenem Prozess mit ungeklaertem Zustand)
**Erzeugt durch:** app/migrate.php migrations_status() (INSERT IGNORE als Markereintrag), migrations_run() (INSERT beim Start einer Migration)  
**Verändert durch:** app/migrate.php migrations_run() (status, finished_at, applied_at, error_text)  
**Gelesen durch:** app/migrate.php (Migrationsstatus, verhindert Doppelausfuehrung)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: applied_at DATETIME; externe IDs: keine  
**Migrationen:** wird laut schema.sql-Kommentar (Zeile 889-892) zur Laufzeit ebenfalls per CREATE TABLE IF NOT EXISTS angelegt, ist selbst nicht Gegenstand einer eigenen nummerierten Migration  
**Besonderheiten:** version ist Primaerschluessel (Migrationsnummer als Zeichenkette). applied_by unterscheidet automatischen Lauf ('auto') von manuellem Markereintrag ('marker').  

## support_tickets

**Zweck:** Hilfe-Center: Support-Anfragen der Kunden an den Plattformbetreiber (Migration 012, app/support_tickets.php).  
**Modul:** Support und Administration  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| tenant_id | CHAR(36) | nein |  | [FK → organizations.id (ON DELETE CASCADE)] |
| user_id | CHAR(36) | ja |  |  |
| user_email | VARCHAR(255) | nein |  |  |
| subject | VARCHAR(160) | nein |  |  |
| category | VARCHAR(40) | nein | 'allgemein' |  |
| page | VARCHAR(120) | ja |  |  |
| status | VARCHAR(20) | nein | 'open' |  |
| last_message_at | DATETIME | nein | CURRENT_TIMESTAMP | open \| answered \| closed |
| answered_at | DATETIME | ja |  |  |
| closed_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** IX ix_ticket_tenant (tenant_id, created_at); IX ix_ticket_status (status, last_message_at)  
**Von der Datenbank erzwungene Beziehungen:** tenant_id → organizations.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: open | answered | closed (schema.sql-Kommentar Zeile 911). Uebergaenge: ticket_customer_reply() -> open, ticket_support_reply() -> closed oder answered je nach Aktion.
**Erzeugt durch:** app/support_tickets.php ticket_create()  
**Verändert durch:** app/support_tickets.php ticket_add_message() (last_message_at), ticket_customer_reply() (status=open, closed_at=NULL), ticket_support_reply() (status=closed/answered, answered_at/closed_at)  
**Gelesen durch:** admin-support.php, Hilfe-Center-Seiten des Kunden  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber tenant_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: last_message_at, answered_at, closed_at, created_at/updated_at DATETIME; externe IDs: keine  
**Migrationen:** 012_support_tickets.sql (CREATE TABLE)  
**Besonderheiten:** page haelt fest, von welcher Seite aus die Anfrage gestellt wurde (Kontext fuer den Support).  

## support_ticket_messages

**Zweck:** Nachrichtenverlauf eines Support-Tickets (Kunde und Support im Wechsel).  
**Modul:** Support und Administration  
**Mandantenzuordnung:** tenant_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| ticket_id | CHAR(36) | nein |  | [FK → support_tickets.id (ON DELETE CASCADE)] |
| tenant_id | CHAR(36) | nein |  |  |
| author_type | VARCHAR(10) | nein |  |  |
| author_email | VARCHAR(255) | ja |  | customer \| support |
| body | TEXT | nein |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_ticket_msg (ticket_id, created_at)  
**Von der Datenbank erzwungene Beziehungen:** ticket_id → support_tickets.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `author_type`: customer | support (schema.sql-Kommentar Zeile 926)
**Erzeugt durch:** app/support_tickets.php ticket_add_message()  
**Gelesen durch:** admin-support.php, Hilfe-Center-Seiten des Kunden  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden; ON DELETE CASCADE ueber ticket_id  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: created_at DATETIME; externe IDs: keine  
**Migrationen:** 012_support_tickets.sql (CREATE TABLE)  
**Besonderheiten:** Reines Anhaengeprotokoll (nur INSERT gefunden, keine Aenderung bestehender Nachrichten).  

## interest_registrations

**Zweck:** Vormerkungen (Warteliste) fuer angekuendigte Integrationen, zuerst sevdesk, mit Double-Opt-in (Migration 020, CLAUDE.md-Abschnitt sevdesk).  
**Modul:** Marketing / Vorregistrierung  
**Mandantenzuordnung:** keine (plattformweit je Anbieter und E-Mail-Adresse)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| provider_code | VARCHAR(32) | nein |  | [FK → integration_providers.code; UQ uq_interest_provider_email] |
| email | VARCHAR(255) | nein |  | [UQ uq_interest_provider_email] |
| name | VARCHAR(120) | ja |  |  |
| company | VARCHAR(160) | ja |  |  |
| source_domain | VARCHAR(100) | ja |  |  |
| purpose | VARCHAR(40) | nein | 'launch_info' | Zweck der Einwilligung, Standard launch_info (Start- und Entwicklungsinformationen, Betaeinladung laut Kommentar Zeile 945) |
| status | VARCHAR(20) | nein | 'pending' | Zweck der Einwilligung (Start- und Entwicklungsinformationen, Betaeinladung) |
| consent_text | VARCHAR(80) | nein |  | Fassung des Einwilligungstextes (INTEREST_CONSENT_VERSION laut CLAUDE.md), der zugestimmt wurde |
| consent_at | DATETIME | ja |  |  |
| mail_count | SMALLINT UNSIGNED | nein | 0 | Zeitpunkt der letzten Einwilligung (Absenden des Formulars, UTC) |
| mail_window_at | DATETIME | ja |  | Bestaetigungsmails im laufenden 24-Stunden-Fenster |
| mail_pending | TINYINT(1) | nein | 0 | Bestaetigungsmail steht aus (Migration 021), wird durch Wartung nachgesendet, sobald mail.enabled gesetzt ist |
| Wartung | sendet | ja |  |  |
| token_hash | CHAR(64) | ja |  |  |
| 7 | Tage | ja |  |  |
| nach | Bestaetigung | ja |  |  |
| manage_token_hash | CHAR(64) | ja |  |  |
| blocked_at | DATETIME | ja |  | Sperrvermerk: kein Versand mehr, Klartextangaben ausser E-Mail entfernt |
| Klartextangaben | ausser | nein | 0 |  |
| invoices_per_month | VARCHAR(20) | ja |  | freiwillige Angaben nach Bestaetigung |
| has_stripe | TINYINT(1) | ja |  |  |
| has_api_access | TINYINT(1) | ja |  |  |
| invited_at | DATETIME | ja |  |  |
| activated_org_id | CHAR(36) | ja |  | Betaeinladung vorgemerkt (nur bestaetigt und nicht gesperrt) |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP | spaeterer Firmenbezug nach tatsaechlicher Aktivierung |
| last_mail_at | DATETIME | ja |  |  |
| confirmed_at | DATETIME | ja |  |  |
| unsubscribed_at | DATETIME | ja |  |  |
| notified_at | DATETIME | ja |  |  |

**Indizes und Eindeutigkeit:** UQ uq_interest_provider_email (provider_code, email); IX ix_interest_status (provider_code, status, created_at); IX ix_interest_token (token_hash); IX ix_interest_manage (manage_token_hash); IX ix_interest_created (created_at)  
**Von der Datenbank erzwungene Beziehungen:** provider_code → integration_providers.code  
**Statuswerte und Übergänge:**  
- `status`: pending | confirmed | unsubscribed (schema.sql-Kommentar Zeile 946). Uebergaenge: interest_register() -> pending, interest_confirm() -> confirmed, interest_unsubscribe_id()/interest_block_id() -> unsubscribed (Abmeldung wird laut app/version.php-Changelog nie automatisch aufgehoben).
**Erzeugt durch:** app/interest.php interest_register()  
**Verändert durch:** app/interest.php interest_register() (token_hash, mail_pending), app/interest.php interest_send_pending() (Wartungsjob, sendet ausstehende Bestaetigungsmails nach), app/interest.php interest_confirm() (status=confirmed, confirmed_at), app/interest.php interest_send_confirmed_mail(), app/interest.php interest_unsubscribe_id() (status=unsubscribed), app/interest.php interest_block_id() (Sperrvermerk blocked_at, Klartextangaben ausser E-Mail werden dabei entfernt laut schema.sql-Kommentar Zeile 956), app/interest.php interest_invite_id() (invited_at, Betaeinladung vorgemerkt), app/interest.php interest_optional_update() (freiwillige Angaben nach Bestaetigung: beta_interest, invoices_per_month, has_stripe, has_api_access)  
**Gelesen durch:** app/interest.php (Vormerkungsformular, Verwaltung), admin-Bereich  
**Löschung, Archivierung, Aufbewahrung:** kein DELETE gefunden; Sperrvermerk (blocked_at) entfernt laut Kommentar Klartextangaben ausser E-Mail, statt die Zeile zu loeschen  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: consent_at, mail_window_at, mail_pending_since, token_expires_at, created_at, last_mail_at, confirmed_at, unsubscribed_at, notified_at, invited_at DATETIME (alle UTC laut Kommentar); externe IDs: activated_org_id (spaeterer Firmenbezug nach tatsaechlicher Aktivierung)  
**Migrationen:** 020_interest_registrations.sql (CREATE TABLE), 021_mail_pending.sql (mail_pending, mail_pending_since), 022_mail_pending_backfill.sql (reine Datenmigration, keine Schemaaenderung)  
**Besonderheiten:** UNIQUE (provider_code, email). token_hash ist der SHA-256-Hash des Bestaetigungslinks (7 Tage gueltig, nach Bestaetigung geloescht laut Kommentar), manage_token_hash ein getrennter Hash fuer Abmeldung/freiwillige Angaben. Laut CLAUDE.md keine IP-Speicherung.  

## legal_documents

**Zweck:** Versionierte Rechtsdokumente mit Zustimmungsnachweis (z. B. Auftragsverarbeitungsvertrag nach Art. 28 DSGVO, Verschwiegenheitsvereinbarung nach § 203 StGB), Migration 023.  
**Modul:** Recht und Vertraege  
**Mandantenzuordnung:** keine (plattformweit, ein Dokument gilt fuer alle oder eine Teilmenge von Firmen laut required_for)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| code | VARCHAR(40) | nein |  | [UQ ux_legal_code_version] |
| version | VARCHAR(40) | nein |  | avv \| secrecy \| ... [UQ ux_legal_code_version] |
| title | VARCHAR(200) | nein |  | z. B. 2026-09-a |
| summary | VARCHAR(500) | ja |  |  |
| body_md | MEDIUMTEXT | nein |  |  |
| required_for | ENUM('all','secrecy','none') | nein | 'all' | all = jede Firma, secrecy = nur Firmen mit Verschwiegenheitspflicht (organizations.professional_secrecy), none = reine Information (schema.sql-Kommentar Zeile 986) |
| published_at | DATETIME | ja |  |  |
| retired_at | DATETIME | ja |  |  |
| created_by | VARCHAR(255) | ja |  |  |
| created_at | DATETIME | nein | CURRENT_TIMESTAMP |  |
| updated_at | DATETIME | nein | CURRENT_TIMESTAMP | [ON UPDATE CURRENT_TIMESTAMP] |

**Indizes und Eindeutigkeit:** UQ ux_legal_code_version (code, version); IX ix_legal_code_published (code, published_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/legal.php legal_document_create()  
**Verändert durch:** app/legal.php legal_document_publish() (published_at, retired_at; setzt zugleich die vorherige veroeffentlichte Fassung desselben code auf retired_at)  
**Gelesen durch:** rechtliches.php, app/legal_drafts.php, app/legal.php (Pruefung, welche Fassung ein Kunde akzeptieren muss)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: published_at, retired_at, created_at/updated_at DATETIME; externe IDs: keine  
**Migrationen:** 023_legal_documents.sql (CREATE TABLE)  
**Besonderheiten:** UNIQUE (code, version). Laut Kommentar (Zeile 986) werden nur Fassungen mit published_at Kunden angezeigt und koennen akzeptiert werden. body_md ist eingeschraenktes Markdown (Ueberschriften, Absaetze, Listen laut Kommentar).  

## legal_acceptances

**Zweck:** Zustimmungsnachweis je Firma und Fassung eines Rechtsdokuments (wer, wann, auf welchem Weg), Migration 023.  
**Modul:** Recht und Vertraege  
**Mandantenzuordnung:** organization_id  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| organization_id | CHAR(36) | nein |  | [UQ ux_legal_acc_org_doc] |
| document_id | CHAR(36) | nein |  | [UQ ux_legal_acc_org_doc] |
| user_id | CHAR(36) | ja |  |  |
| user_email | VARCHAR(255) | nein |  |  |
| auch | wenn | nein | 'backend' |  |
| accepted_at | DATETIME | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** UQ ux_legal_acc_org_doc (organization_id, document_id); IX ix_legal_acc_org (organization_id); IX ix_legal_acc_doc (document_id)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/legal.php legal_accept()  
**Gelesen durch:** app/legal.php, rechtliches.php (Pruefung, ob eine Fassung bereits akzeptiert wurde)  
**Löschung, Archivierung, Aufbewahrung:** nicht gefunden  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Betraege: keine; Zeit: accepted_at DATETIME; externe IDs: keine  
**Migrationen:** 023_legal_documents.sql (CREATE TABLE)  
**Besonderheiten:** UNIQUE (organization_id, document_id) verhindert doppelte Zustimmung zur selben Fassung. user_email bleibt laut Kommentar (Zeile 1001) auch nach Loeschung des Benutzers lesbar (kein Fremdschluessel auf users, nur informativer user_id-Verweis ohne FOREIGN-KEY-Constraint im Schema). Keine IP-Speicherung laut Kommentar zu Migration 023.  

## consent_records

**Zweck:** Zustimmungsnachweis zu AGB und Datenschutzerklärung je Benutzer (Gegenstand, Fassung, Zeitpunkt UTC, Weg, Quellseite, E-Mail). Ergänzt legal_acceptances (Vertragsdokumente mit Volltext) und interest_registrations (Vorregistrierung).  
**Modul:** Konten und Firmen / Rechtsdokumente  
**Mandantenzuordnung:** organization_id (NULL bei benutzerbezogener Zustimmung ohne Firma)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| user_id | CHAR(36) | ja |  |  |
| organization_id | CHAR(36) | ja |  |  |
| user_email | VARCHAR(255) | nein |  |  |
| subject | VARCHAR(40) | nein |  | agb \| datenschutz |
| version | VARCHAR(60) | nein |  | Fassung, z. B. agb-2026-09 |
| archiviert | in | ja |  |  |
| method | VARCHAR(20) | nein | 'registration' | registration \| backend \| import |
| source_url | VARCHAR(255) | ja |  | Seite, deren Text akzeptiert wurde |
| deren | Text | nein | CURRENT_TIMESTAMP |  |

**Indizes und Eindeutigkeit:** IX ix_consent_user (user_id, accepted_at); IX ix_consent_org (organization_id, accepted_at); IX ix_consent_subject (subject, version)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Erzeugt durch:** app/consent.php consent_record(), consent_record_registration() aus register.php nach erfolgreicher Registrierung  
**Verändert durch:** keine Änderung vorgesehen (Nachweis, nur Einfügen)  
**Gelesen durch:** rechtliches.php (consent_list_for_org), security.php (consent_list_for_user)  
**Löschung, Archivierung, Aufbewahrung:** kein automatisches Löschen (Nachweis); kein Fremdschlüssel, damit die Zeile den Benutzer überdauert  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: accepted_at UTC (UTC_TIMESTAMP()); keine Beträge; keine externen IDs; keine IP  
**Migrationen:** 025_consent_records.sql  
**Besonderheiten:** Fassungen als Konstanten AGB_VERSION/DATENSCHUTZ_VERSION in app/consent.php, Archiv in docs/einwilligungen.md; idempotent je Benutzer, Gegenstand, Fassung.  

## platform_roles

**Zweck:** Rollen des Adminbereichs (Plattform-Benutzer und Rechte, Version 4.37): je Rolle eine Liste von Berechtigungscodes aus dem festen Katalog PLATFORM_PERMISSIONS in app/platform.php, oder ["*"] für Vollzugriff. Systemrollen admin, support, staff werden mit Migration 027 angelegt.  
**Modul:** Konten und Firmen / Plattform-Administration  
**Mandantenzuordnung:** keine (plattformweit)  
**Primärschlüssel:** code  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| code | VARCHAR(32) | nein |  | Rollencode, 2 bis 32 Zeichen, Kleinbuchstaben, Ziffern, - und _ [PK; PRIMARY KEY] |
| eigene | Rollen | nein |  |  |
| description | VARCHAR(255) | ja |  |  |
| permissions | TEXT | nein |  | JSON-Array von Berechtigungscodes (z. B. support.sessions) oder ["*"] |
| is_system | TINYINT(1) | nein | 0 | Systemrolle ja/nein |
| admin | nicht | nein | UTC_TIMESTAMP() |  |
| updated_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `is_system`: 1 = Systemrolle (nicht löschbar; admin zusätzlich nicht editierbar), 0 = eigene Rolle
**Erzeugt durch:** sql/migrations/027_platform_roles.sql und sql/schema.sql (INSERT IGNORE der Systemrollen), app/platform.php platform_role_save() aus admin-users.php (eigene Rollen)  
**Verändert durch:** app/platform.php platform_role_save() (Name, Beschreibung, Berechtigungen; admin unveränderlich)  
**Gelesen durch:** app/platform.php platform_roles(), platform_can(), platform_access() bei jeder Rechteprüfung (require_platform, Navigation, Support-Modus, Dokumentationszugriff)  
**Löschung, Archivierung, Aufbewahrung:** platform_role_delete() nur für eigene Rollen ohne zugewiesene Benutzer; Systemrollen nie  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at, updated_at UTC (UTC_TIMESTAMP()); keine Beträge; keine externen IDs  
**Migrationen:** 027_platform_roles.sql  
**Besonderheiten:** users.platform_role verweist ohne Fremdschlüssel auf code (Rollen mit Benutzern sind nicht löschbar, Prüfung im Code). Fehlt die Tabelle (Migration noch nicht gelaufen), liefert platform_roles() die Systemrollen aus PLATFORM_SYSTEM_ROLES als Rückfall. Jede Änderung im audit_log (platform_role_created/changed/deleted).  

## marketing_lists

**Zweck:** Empfaengerlisten des Marketingmoduls: Importlisten (CSV) und Systemlisten aus den Firmenaccounts (docs/marketing.md).  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| name | VARCHAR(120) | nein |  | [UQ uq_marketing_list_name] |
| description | VARCHAR(500) | ja |  |  |
| source | VARCHAR(20) | nein | 'import' |  |
| system_scope | VARCHAR(40) | ja |  | owners = nur Inhaber, owners_admins = Inhaber und Administratoren aktiver Firmen |
| created_by | VARCHAR(255) | ja |  | system: owners \| owners_admins |
| created_at | DATETIME | nein | UTC_TIMESTAMP() |  |
| updated_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Indizes und Eindeutigkeit:** UQ uq_marketing_list_name (name)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `source`: import | system (Systemliste aus users/organization_members, system_scope owners oder owners_admins)
**Erzeugt durch:** app/marketing.php marketing_list_create() aus admin-marketing.php  
**Verändert durch:** app/marketing.php marketing_list_import(), marketing_list_sync_system() (updated_at)  
**Gelesen durch:** admin-marketing.php (Uebersicht, Empfaenger, Export), marketing_campaign_save() (Pruefung der Listen)  
**Löschung, Archivierung, Aufbewahrung:** marketing_list_delete() nur ohne laufende Kampagne; Empfaenger folgen per ON DELETE CASCADE; die Sperrliste bleibt unberuehrt  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at, updated_at UTC; keine Betraege; keine externen IDs  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** Name eindeutig (uq_marketing_list_name). Systemlisten werden nie importiert, sondern aus den Firmenaccounts aufgebaut (Rechtsgrundlage bestandskunde).  

## marketing_recipients

**Zweck:** Empfaenger je Liste mit Rechtsgrundlage und Vermerk des Imports; email_norm ist der Vergleichsschluessel gegen Sperrliste und Dubletten.  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| list_id | CHAR(36) | nein |  | [FK → marketing_lists.id (ON DELETE CASCADE); UQ uq_marketing_recipient] |
| email | VARCHAR(255) | nein |  |  |
| email_norm | VARCHAR(255) | nein |  | kleingeschriebene Adresse fuer Vergleiche [UQ uq_marketing_recipient] |
| Vergleichsschluessel | name | ja |  |  |
| company | VARCHAR(255) | ja |  |  |
| source | VARCHAR(20) | nein | 'import' |  |
| legal_basis | VARCHAR(30) | nein |  | import \| system |
| legal_note | VARCHAR(255) | ja |  | Herkunft und Nachweis des Imports (frei, Pflicht bei sonstiges) |
| status | VARCHAR(20) | nein | 'active' |  |
| created_at | DATETIME | nein | UTC_TIMESTAMP() | active \| suppressed \| removed |

**Indizes und Eindeutigkeit:** UQ uq_marketing_recipient (list_id, email_norm); IX ix_marketing_recipient_email (email_norm)  
**Von der Datenbank erzwungene Beziehungen:** list_id → marketing_lists.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: active | suppressed (auf der Sperrliste, wird nie angeschrieben) | removed (Systemliste: Mitglied nicht mehr vorhanden)
- `legal_basis`: bestandskunde | einwilligung | b2b_kontakt | sonstiges (MARKETING_LEGAL_BASES)
- `source`: import | system
**Erzeugt durch:** app/marketing.php marketing_list_import() (INSERT IGNORE je Liste und email_norm), app/marketing.php marketing_list_sync_system()  
**Verändert durch:** marketing_suppress() setzt status suppressed, marketing_unsuppress() setzt status active, marketing_list_sync_system() (name, company, removed)  
**Gelesen durch:** admin-marketing.php, marketing_campaign_start() (Versandzeilen), marketing_list_export_csv()  
**Löschung, Archivierung, Aufbewahrung:** marketing_recipient_remove() einzeln (Audit ohne Klartextadresse); Cascade mit der Liste  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at UTC; keine Betraege  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** Eindeutig je Liste und email_norm (uq_marketing_recipient). Der Import erzwingt eine Rechtsgrundlage, bei sonstiges einen Vermerk; die Bewertung trifft der Betreiber.  

## marketing_suppressions

**Zweck:** Dauerhafte, listenuebergreifende Sperrliste: Adressen, die nie wieder eine Werbenachricht erhalten (Abmeldung, Beschwerde, harter Ruecklaeufer, von Hand).  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** email_norm  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| email_norm | VARCHAR(255) | nein |  | [PK; PRIMARY KEY] |
| reason | VARCHAR(20) | nein |  |  |
| note | VARCHAR(255) | ja |  | Herkunft der Sperre (per Link, one-click, SES-Untertyp, Vermerk des Mitarbeiters) |
| campaign_id | CHAR(36) | ja |  |  |
| created_by | VARCHAR(255) | ja |  |  |
| created_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `reason`: unsubscribe | complaint | bounce | manual (MARKETING_SUPPRESSION_REASONS); unsubscribe und complaint sind nicht aufhebbar (MARKETING_SUPPRESSION_PERMANENT)
**Erzeugt durch:** app/marketing.php marketing_suppress() aus marketing_unsubscribe() (abmelden.php), marketing_ses_handle() (marketing-webhook.php), marketing_suppress_manual() (admin-marketing.php)  
**Verändert durch:** marketing_suppress(): ein dauerhafter Grund ersetzt einen schwaecheren, nie umgekehrt  
**Gelesen durch:** marketing_is_suppressed() bei Import, Freigabe und vor jedem Senden; admin-marketing.php Sperrliste  
**Löschung, Archivierung, Aufbewahrung:** marketing_unsuppress() nur fuer bounce und manual mit Grund und Audit; unsubscribe und complaint nie  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at UTC; keine Betraege; campaign_id verweist ohne Fremdschluessel auf die ausloesende Kampagne  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** Primaerschluessel email_norm. Audit marketing_suppressed/marketing_unsuppressed traegt die Adresse nur als Kennung (mail_addr_ref).  

## marketing_campaigns

**Zweck:** Kampagnen des Marketingmoduls: Inhalt (Betreff, Ueberschrift, Absaetze, Schaltflaeche, Fussnote), Listen, Status, Test- und Freigabenachweis, Zaehler.  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| name | VARCHAR(120) | nein |  |  |
| subject | VARCHAR(200) | nein |  |  |
| title | VARCHAR(200) | nein |  |  |
| body_text | TEXT | nein |  |  |
| Platzhalter | {{name}} und {{firma}} button_label   VARCHAR(80)  NULL | ja |  |  |
| button_url | VARCHAR(500) | ja |  |  |
| footer_note | VARCHAR(500) | ja |  |  |
| list_ids | TEXT | nein |  |  |
| status | VARCHAR(20) | nein | 'draft' | JSON-Array der Listen |
| test_sent_at | DATETIME | ja |  | draft \| queued \| sending \| paused \| sent \| cancelled |
| test_sent_ref | VARCHAR(80) | ja |  | Kennung der Testadresse (mail_addr_ref), kein Klartext |
| started_by | VARCHAR(255) | ja |  |  |
| started_at | DATETIME | ja |  |  |
| finished_at | DATETIME | ja |  |  |
| total_count | INT | nein | 0 | Versandzeilen bei Freigabe (eine je Adresse ueber alle Listen) |
| sent_count | INT | nein | 0 |  |
| failed_count | INT | nein | 0 |  |
| skipped_count | INT | nein | 0 |  |
| created_by | VARCHAR(255) | ja |  |  |
| created_at | DATETIME | nein | UTC_TIMESTAMP() |  |
| updated_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `status`: draft | queued (freigegeben) | sending | paused | sent | cancelled (MARKETING_CAMPAIGN_STATUS). Uebergaenge: marketing_campaign_start() draft -> queued; marketing_send_process() queued -> sending -> sent; marketing_campaign_set_status() queued/sending -> paused, paused -> queued, queued/sending/paused -> cancelled
**Erzeugt durch:** app/marketing.php marketing_campaign_save() aus admin-marketing.php  
**Verändert durch:** marketing_campaign_save() (nur draft; Inhaltsaenderung setzt test_sent_at zurueck), marketing_campaign_test_send() (test_sent_at, test_sent_ref), marketing_campaign_start() (status, started_by, total_count, skipped_count), marketing_send_process() und marketing_campaign_refresh_counts() (status, Zaehler, finished_at), marketing_campaign_set_status()  
**Gelesen durch:** admin-marketing.php, job_marketing_send(), scheduler_tick() (marketing_campaigns_pending())  
**Löschung, Archivierung, Aufbewahrung:** marketing_campaign_delete() nur draft und cancelled; versendete Kampagnen bleiben als Nachweis  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at, updated_at, test_sent_at, started_at, finished_at UTC; keine Betraege  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** Freigabe nur nach Testversand und mit 2FA-Code (admin-marketing.php campaign_start). body_text ohne HTML; Platzhalter {{name}} und {{firma}}. list_ids ist ein JSON-Array.  

## marketing_sends

**Zweck:** Versandzeilen je Kampagne und Adresse: Beanspruchung, Ergebnis, Abmeldetoken (Hash).  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | CHAR(36) | nein |  | [PK; PRIMARY KEY] |
| campaign_id | CHAR(36) | nein |  | [FK → marketing_campaigns.id (ON DELETE CASCADE); UQ uq_marketing_send] |
| recipient_id | CHAR(36) | ja |  |  |
| email | VARCHAR(255) | nein |  |  |
| email_norm | VARCHAR(255) | nein |  | [UQ uq_marketing_send] |
| name | VARCHAR(200) | ja |  |  |
| company | VARCHAR(255) | ja |  |  |
| status | VARCHAR(20) | nein | 'queued' |  |
| skip_reason | VARCHAR(40) | ja |  | suppressed (Sperrliste) \| cancelled (Kampagne abgebrochen) |
| error | VARCHAR(255) | ja |  | Meldung des Versandwegs bei failed oder letztem Transportfehler |
| unsubscribe_token_hash | CHAR(64) | ja |  |  |
| claimed_at | DATETIME | ja |  |  |
| sent_at | DATETIME | ja |  |  |
| created_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Indizes und Eindeutigkeit:** UQ uq_marketing_send (campaign_id, email_norm); IX ix_marketing_send_status (campaign_id, status); IX ix_marketing_send_sent (sent_at); IX ix_marketing_send_token (unsubscribe_token_hash)  
**Von der Datenbank erzwungene Beziehungen:** campaign_id → marketing_campaigns.id (ON DELETE CASCADE)  
**Statuswerte und Übergänge:**  
- `status`: queued | sending (beansprucht) | sent | failed (endgueltige Ablehnung) | skipped (skip_reason suppressed oder cancelled)
**Erzeugt durch:** app/marketing.php marketing_campaign_start() (INSERT IGNORE je Kampagne und email_norm)  
**Verändert durch:** marketing_send_process(): UPDATE ... WHERE status = 'queued' als Beanspruchung, unsubscribe_token_hash, sent_at, error; Transportfehler stellt auf queued zurueck, marketing_suppress() setzt offene Zeilen der Adresse auf skipped, marketing_campaign_set_status(cancelled)  
**Gelesen durch:** marketing_send_by_token() (abmelden.php), marketing_sent_last_24h() (Tagesgrenze), admin-marketing.php (Fehlerliste)  
**Löschung, Archivierung, Aufbewahrung:** Cascade mit der Kampagne; versendete Kampagnen werden nicht geloescht  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: claimed_at, sent_at, created_at UTC; keine Betraege; message_id der Anwendung nicht gespeichert (SES-Ereignisse tragen die SES-Message-ID)  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** Eindeutig je Kampagne und email_norm (uq_marketing_send): jede Adresse wird je Kampagne genau einmal angeschrieben. Der Abmeldetoken (48 Hexzeichen) steht nur als SHA-256 in unsubscribe_token_hash.  

## marketing_events

**Zweck:** Ereignisprotokoll des Marketingmoduls: Abmeldungen und Testversand der Anwendung, Ruecklaeufer, Beschwerden, Zustellungen und Abonnementbestaetigungen aus Amazon SES/SNS.  
**Modul:** Marketing / Werbeversand (4.63)  
**Mandantenzuordnung:** keine (plattformweit; Empfaenger sind Firmenaccounts und importierte Kontakte, nie Kunden der Firmen)  
**Primärschlüssel:** id  

| Spalte | Typ | NULL | Standard | Bedeutung |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | nein |  | [PK; PRIMARY KEY AUTO_INCREMENT] |
| source | VARCHAR(20) | nein |  |  |
| event_type | VARCHAR(40) | nein |  | ses \| app |
| email_norm | VARCHAR(255) | ja |  | bounce \| bounce_transient \| complaint \| delivery \| test_send \| unsubscribe \| ... |
| message_id | VARCHAR(120) | ja |  | Message-ID aus dem SES-Ereignis (mail.messageId) |
| details_json | TEXT | ja |  |  |
| created_at | DATETIME | nein | UTC_TIMESTAMP() |  |

**Indizes und Eindeutigkeit:** IX ix_marketing_event_email (email_norm); IX ix_marketing_event_created (created_at)  
**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  
**Statuswerte und Übergänge:**  
- `source`: app | ses
- `event_type`: test_send | unsubscribe | bounce | bounce_transient | complaint | delivery | subscription | sonstige SES-Typen kleingeschrieben
**Erzeugt durch:** app/marketing.php marketing_campaign_test_send(), marketing_unsubscribe(), marketing_ses_handle(); marketing-webhook.php (subscription)  
**Verändert durch:** keine (nur Einfuegen)  
**Gelesen durch:** admin-marketing.php Abschnitt Ereignisse, marketing_sent_last_24h() (test_send zaehlt zur Tagesgrenze)  
**Löschung, Archivierung, Aufbewahrung:** keine automatische Bereinigung (offen, siehe abdeckung-und-offene-punkte.md)  
**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** Zeit: created_at UTC; message_id = SES-Message-ID; keine Betraege  
**Migrationen:** 034_marketing.sql  
**Besonderheiten:** details_json enthaelt nur technische Angaben (Bounce-Typ, Statuscode, gekuerzter Diagnosetext), keine Nachrichteninhalte.  

## Offene Beschreibungen

0 Tabelle(n) ohne fachliche Beschreibung in `tabellen-beschreibungen.json`.

