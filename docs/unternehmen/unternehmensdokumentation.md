# SmartEinzug: Unternehmens- und Verkaufsdokumentation

Stand: 07.09.2026. Diese Dokumentation richtet sich an Kaufinteressenten, externe Prüfer und mögliche
Übernehmer von SmartEinzug. Sie ist ohne Programmierkenntnisse verständlich, verzichtet aber nicht auf
belastbare Einzelheiten. Grundlage sind ausschließlich der Quellcode und die vorhandene Dokumentation
dieses Repositorys (`v3ni94/LexofficXSTRIPE`, Stand des Branches `claude/setup-lexsepa-monorepo-v5ZcZ`).
Es wurden keine Umsatz, Kunden oder Marktangaben erfunden. Wo eine Angabe im Projektbestand fehlt,
steht das ausdrücklich so da, verbunden mit der Quelle, die sie liefern müsste.

Betreiberin der Anwendung ist die Müller Holding AG. SmartEinzug ist ein eigenständiges
Software-as-a-Service-Produkt der Gesellschaft und hat mit deren Immobilienverwaltung (Hausverwaltung
Müller GmbH) keinen fachlichen Zusammenhang; es teilt sich lediglich die Trägergesellschaft und, bei
Bedarf, deren Rechtsdokumente und Kontaktkanäle.

## Inhaltsverzeichnis

1. Produkt und Nutzen
2. Vollständiger Funktionskatalog
3. Systemlandschaft
4. Verzeichnis der Vermögenswerte und Abhängigkeiten
5. Risiken und Grenzen
6. Kennzahlen und Kosten
7. Roadmap
8. Übergabecheckliste
9. Zusammenfassung: fehlende Angaben

---

## 1. Produkt und Nutzen

@@diagramm 01-produktuebersicht

### 1.1 Das Problem

Unternehmen, die ihre Rechnungen in Lexware Office (früher lexoffice) verwalten, müssen offene und
überfällige Rechnungen manuell nachverfolgen und den Zahlungseinzug selbst organisieren. Wer seine
Kunden per SEPA-Lastschrift einzieht, pflegt Mandate, Fristen und Bankverbindungen häufig in
Tabellen oder in der Buchhaltungssoftware selbst nach, ohne automatisierte Verbindung zu einem
Zahlungsdienstleister. Das kostet Zeit, ist fehleranfällig und verzögert den Geldeingang.

### 1.2 Die Lösung

SmartEinzug verbindet die Buchhaltungssoftware eines Unternehmens (produktiv: Lexware Office) mit dem
eigenen Stripe-Konto dieses Unternehmens und automatisiert daraus den SEPA-Lastschrifteinzug für offene
Rechnungen: Rechnungen werden regelmäßig synchronisiert, Kunden erhalten eine Möglichkeit zur digitalen
Mandatserteilung, fällige Rechnungen werden geprüft und als SEPA-Lastschrift eingereicht, der
Zahlungsstatus wird zurückgemeldet und unklare oder fehlgeschlagene Versuche werden gezielt zur
Klärung vorgelegt. Das Produkt ist mehrmandantenfähig (mehrere Firmen je Benutzerkonto, vollständige
Datentrennung) und als Abonnement organisiert.

### 1.3 Zielgruppen

- Kleine und mittlere Unternehmen, die Lexware Office nutzen und wiederkehrend oder unregelmäßig
  Rechnungen per SEPA-Lastschrift einziehen möchten (Handwerk, Dienstleister, Vereine, Kanzleien).
- Firmen mit eigenem Stripe-Konto, die den SEPA-Einzug nicht über ein Sammelkonto eines Dritten,
  sondern über ihr eigenes Konto abwickeln möchten (Zahlungsströme bleiben beim Unternehmen selbst).
- Perspektivisch (siehe Abschnitt 7, Roadmap): Unternehmen, die sevdesk statt Lexware Office nutzen.

Nicht Zielgruppe: Firmenlastschrift (B2B-Lastschrift). Stripe unterstützt in dieser Anwendung
ausschließlich die SEPA-Basislastschrift (SEPA Core), keine B2B-Lastschrift; dies wird auch auf den
Marketingseiten nicht anders dargestellt.

### 1.4 Der vollständige Kundenprozess, von der Registrierung bis zum Geldeingang

1. **Registrierung:** Eine Person registriert sich unter `app.smart-einzug.de` (`register.php`), legt
   damit ihre Firma (Organisation) an und wird deren Inhaber (Rolle `owner`). Die Herkunft der
   Registrierung (Marketingseite, Domain, gegebenenfalls das gewählte Buchhaltungssystem über
   `integration=`) wird für die Erfolgsmessung erfasst.
2. **Pflicht-Zwei-Faktor-Authentifizierung:** Vor der ersten Nutzung wird eine Authenticator-App
   verpflichtend eingerichtet (`twofa-setup.php`); ohne aktive 2FA ist keine weitere Aktion möglich.
3. **E-Mail-Bestätigung:** Eine Willkommensmail mit Bestätigungslink wird versendet (Mailversand
   vorausgesetzt); ohne aktiven Mailversand wird der Versand nachgeholt, sobald er verfügbar ist.
4. **Verbindung der Buchhaltungssoftware:** Unter Einstellungen wird der API-Schlüssel von Lexware
   Office hinterlegt (`settings.php`, `app/integrations.php`); der Schlüssel wird ausschließlich
   verschlüsselt gespeichert.
5. **Verbindung von Stripe:** Ebenso wird der geheime Schlüssel des eigenen Stripe-Kontos der Firma
   hinterlegt. SmartEinzug betreibt kein Sammelkonto und keine Stripe-Connect-Vermittlung; die
   Lastschriften laufen unmittelbar über das Konto der Firma bei Stripe.
6. **Synchronisation:** Offene und überfällige Rechnungen sowie Kontaktdaten werden aus Lexware Office
   gelesen und lokal gespeichert (`app/sync.php`). Der Abgleich läuft in fortsetzbaren Schritten,
   damit auch große Rechnungsbestände ohne Zeitüberschreitung verarbeitet werden.
7. **Kunden und Mandate:** Für jeden Kunden mit hinterlegter IBAN kann ein SEPA-Mandat erteilt werden,
   entweder durch das Team im Portal (mit vorhandener Unterschrift) oder digital durch den Kunden
   selbst über einen zugesandten Link (`mandat.php`, `app/mandate_requests.php`), ohne dass der Kunde
   sich anmelden muss.
8. **Einzug:** Für eine offene Rechnung mit gültigem Mandat kann das Team einen sofortigen Einzug
   auslösen, einen Termin setzen oder mehrere Rechnungen gesammelt einziehen. Vor jeder Einreichung
   prüft die Anwendung unter anderem Mandatsstatus, Tarifkontingent, Karenzzeit und den zuletzt aus
   Lexware Office bekannten offenen Restbetrag.
9. **Einreichung bei Stripe:** Die Anwendung erstellt bei Stripe eine SEPA-Lastschrift über das Konto
   der Firma. Terminierte Lastschriften werden ausschließlich innerhalb eines konfigurierbaren
   Einreichfensters (Vorgabe 23:00 bis 06:00 Uhr) tatsächlich eingereicht.
10. **Statusrückmeldung:** Stripe meldet per Webhook, ob die Lastschrift erfolgreich war,
    fehlgeschlagen ist oder zurückgebucht wurde. Fehlgeschlagene oder unklare Versuche werden dem Team
    zur Klärung vorgelegt (Klärungsliste), erfolgreiche Einzüge aktualisieren den Rechnungsstatus.
11. **Erstattungen und Rücklastschriften:** Werden bei Stripe Erstattungen oder Rücklastschriften
    gebucht, übernimmt die Anwendung diesen Status; die betroffene Rechnung gilt wieder als offen.
12. **Geldeingang:** Der eigentliche Geldeingang (Gutschrift auf dem Bankkonto der Firma über deren
    Stripe-Konto, abzüglich der Stripe-Gebühren) läuft vollständig zwischen Stripe und der Firma; die
    Anwendung selbst führt keine Konten und bewegt selbst kein Geld.
13. **Zurückschreiben nach Lexware Office:** Lexware Office bietet nach aktuellem Stand keinen
    dokumentierten Endpunkt, um eine Zahlung automatisch zurückzuschreiben; die Zuordnung der
    beglichenen Rechnung erfolgt in Lexware Office manuell (ein Ratgeber im Hilfe-Center beschreibt das
    Vorgehen).
14. **Abonnement:** Der Zugriff der Firma auf SmartEinzug selbst ist ein eigenes Abonnement über das
    Stripe-Konto der Müller Holding AG (getrennt von den Stripe-Konten der Firmen), siehe Abschnitt 2,
    Tarife.

### 1.5 Rollenverteilung im Prozess

| Beteiligter | Rolle im Prozess |
|---|---|
| SmartEinzug (Anwendung) | Verbindet Buchhaltung und Zahlungsdienstleister, verwaltet Mandate, prüft und stößt Einzüge an, wertet Status aus, verwaltet Benutzer, Firmen, Tarife, Rechtsdokumente und Protokoll. Bewegt selbst kein Geld und speichert keine vollständigen Kontodaten unverschlüsselt. |
| Buchhaltungssoftware (Lexware Office, perspektivisch sevdesk) | Quelle der Rechnungs- und Kundendaten (nur lesender Zugriff durch SmartEinzug). Bleibt bei einem Zahlungseingang durch das Team manuell zu pflegen, da kein automatischer Rückschreibweg besteht. |
| Stripe (Konto der jeweiligen Firma) | Führt die SEPA-Lastschrift technisch aus, verwaltet Zahlungsmethoden und Mandatsobjekte, meldet Status per Webhook, wickelt Erstattungen und Rücklastschriften ab. Hält das tatsächliche Geld und rechnet mit der Firma ab. |
| Stripe (Konto der Müller Holding AG) | Getrennter, zweiter Anwendungsfall: Abonnement der Firmen für die Nutzung von SmartEinzug selbst (Plattform-Abrechnung). |
| Müller Holding AG | Betreiberin, verantwortliche Stelle für die Verarbeitung der Rechnungs- und Kundendaten der Firmen (Auftragsverarbeitung), Vertragspartnerin des Nutzungsvertrags. |

---

## 2. Vollständiger Funktionskatalog

Grundlage ist eine Sichtung aller Seiten in `php-ionos/*.php` und aller Module in `php-ionos/app/*.php`.
Die Spalte Verfügbarkeit unterscheidet: **produktiv nachgewiesen** (ein Nachweis für produktiven
Einsatz liegt in `docs/ARBEITSSTAND.md` oder anderer Dokumentation vor), **im Quellcode vorhanden**
(Code und Datenbankstruktur bestehen, ein produktiver Nachweis fehlt in der Dokumentation),
**konfiguriert, aber nicht geprüft** (technisch angelegt, Wirksamkeit laut Dokumentation noch nicht
bestätigt) und **geplant** (Absicht, kein Code oder nur ein Gerüst ohne Fähigkeiten).

### 2.1 Konten und Zugang

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Registrierung mit Herkunftserfassung | Firma in wenigen Schritten anlegen, Marketingerfolg messbar | Inhaber | E-Mail-Adresse | produktiv nachgewiesen (laut `docs/abrechnung.md` bestehen bereits produktiv arbeitende Firmenaccounts) | Registrierung mit bereits bekannter E-Mail-Adresse erfordert einen zusätzlichen Bestätigungsschritt | `app/auth.php`, Tabelle `funnel_events` | `php-ionos/register.php`, `php-ionos/register-fortsetzen.php` |
| Passwortanmeldung mit Pflicht-2FA | Schutz des Kontos, Standard bei Zahlungsanwendungen | alle Rollen | Authenticator-App (TOTP) | produktiv nachgewiesen | ohne eingerichtete 2FA ist keine weitere Aktion möglich (bewusst) | `app/totp.php` | `php-ionos/login.php`, `php-ionos/twofa-setup.php`, `php-ionos/twofa-verify.php` |
| Gerät 90 Tage merken | Seltenere 2FA-Abfrage auf vertrauten Geräten | alle Rollen | zuvor erfolgreiche 2FA-Bestätigung, ausdrückliche Checkbox | im Quellcode vorhanden | feste Gültigkeit 90 Tage, Widerruf bei Passwort- oder 2FA-Änderung | `app/devices.php` | `docs/device-trust.md`, `php-ionos/security.php` |
| Passwort zurücksetzen, Recovery-Codes | Zugriff auch bei Passwort- oder Gerätverlust | alle Rollen | E-Mail-Zugriff bzw. aufbewahrte Codes | im Quellcode vorhanden | Mailversand vorausgesetzt für den Link | `app/mailer.php` | `php-ionos/forgot-password.php`, `php-ionos/reset-password.php` |
| Rollen je Firma (Inhaber, Administrator, Mitarbeiter) | Zugriff nach Zuständigkeit begrenzen | Inhaber, Team | mindestens ein Mitglied je Rolle | produktiv nachgewiesen | nur der Inhaber verwaltet Mitglieder, Rollen, Inhaberschaft und Kündigung | Tabelle `organization_members` | `php-ionos/app/auth.php` (Kopfkommentar), `php-ionos/team.php` |
| Multiaccount (mehrere Firmen je Person) | Eine Person kann mehrere Mandanten bedienen, ohne mehrere Konten zu führen | Steuerberater, Dienstleister mit mehreren Firmen | separate Firmenanlage je Mandant | im Quellcode vorhanden | Kunden, Rechnungen, Einzüge und Anbindungen bleiben je Firma vollständig getrennt | Migration 015 | `docs/multiaccount.md`, `php-ionos/companies.php` |
| Sicherheitsbereich (Passwort, Recovery-Codes, 2FA-Reset, Anmeldeversuche) | Selbstständige Kontosicherung ohne Support | alle Rollen | aktuelles Passwort und 2FA-Code | im Quellcode vorhanden | 2FA-Zurücksetzen verlangt erneute Einrichtung | `app/totp.php`, `app/audit.php` | `php-ionos/security.php` |

### 2.2 Firmen und Team

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Firmenwechsel, neue Firma anlegen | Schneller Wechsel zwischen mehreren Mandanten | Inhaber mit mehreren Firmen | bestehendes Konto | im Quellcode vorhanden | jede Firma vollständig eigene Anbindungen und Daten | `organization_members` | `php-ionos/companies.php` |
| Firmendaten (Anschrift, Gläubiger-ID, SEPA-Regeln) | Grundlage für rechtssichere Mandate und Lastschriften | Inhaber, Administrator | Gläubiger-ID optional hinterlegbar | produktiv nachgewiesen | fehlende Angaben erscheinen als Platzhalter, nicht als erfundener Wert | `app/mandates.php` | `php-ionos/team.php` |
| Mitarbeiterverwaltung, Einladungen | Team ohne gemeinsames Passwort einbinden | Inhaber, Administrator | E-Mail-Adresse des einzuladenden Mitglieds | im Quellcode vorhanden | Einladung ist fest an Firma, E-Mail-Adresse und Rolle gebunden | Tabelle `invitations` | `php-ionos/invite.php`, `php-ionos/team.php` |
| Protokoll (Audit) je Firma | Nachvollziehbarkeit jeder geld- und sicherheitsrelevanten Aktion | Inhaber, Administrator | keine | produktiv nachgewiesen | Anzeige 20, aufklappbar bis 200 Einträge; Aufbewahrung 90 Tage (danach automatische Löschung, fachliche Nachweise bleiben unabhängig davon erhalten) | `app/audit.php` | `php-ionos/team.php`, `php-ionos/export.php?typ=protokoll` |

### 2.3 Schnittstellen und Adaptergrenze

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Anbindung Lexware Office (Public API) | Automatischer Zugriff auf offene Rechnungen und Kontakte | alle Firmen | eigener API-Schlüssel, nach Angaben von Lexware Tarif XL für die Public API (im eigenen Konto zu prüfen) | produktiv nachgewiesen | nur lesender Zugriff, kein Schreiben von Zahlungen zurück | `app/lexoffice.php` | `php-ionos/app/lexoffice.php`, `docs/integrations.md` |
| Anbindung Stripe (Konto der Firma) | Eigenes Konto, eigene Auszahlung, keine Sammelkontostruktur | alle Firmen | eigenes Stripe-Konto mit freigeschalteter SEPA-Lastschrift | produktiv nachgewiesen | keine B2B-Lastschrift, keine Stripe-Connect-Vermittlung | `app/stripe.php` | `php-ionos/app/stripe.php` |
| Adaptergrenze `InvoiceSource` | Technische Trennung zwischen Buchhaltungssystemen ohne zweite Anwendung | Betreiber, künftige sevdesk-Kunden | keine (Architekturbaustein) | im Quellcode vorhanden | bislang zwei Implementierungen (Lexware Office, sevdesk); die sevdesk-Implementierung liest bereits, der Einzug bleibt bis zur Bestätigung mit einem Testkonto gesperrt | Registry `integration_providers` | `php-ionos/app/invoice_source.php`, `docs/integrations.md` |
| Buchhaltungssystem je Firma anzeigen, vorwählen, wechseln | Klarheit, welches System aktiv ist; Wechsel ohne Firmenneuanlage möglich | Inhaber, Administrator | 2FA-Code für den Wechsel | im Quellcode vorhanden | nach einem Wechsel gilt eine Sperre von vier Wochen; Wechsel gesperrt bei laufenden Einzügen oder Synchronisation | Migration 024 | `php-ionos/app/invoice_source_switch.php` |
| sevdesk-Adapter | künftige zweite Buchhaltungsanbindung | künftige sevdesk-Kunden | sevdesk-Testkonto mit API-Zugang (fehlt derzeit) | im Quellcode vorhanden (4.38), Lesen ab Freigabe, Einzug gesperrt bis Bestätigung mit Testkonto | Adapter nach Sekundärquellen, nicht verifiziert | `app/sevdesk.php` | `php-ionos/app/sevdesk.php`, `docs/sevdesk.md` |

### 2.4 Synchronisation

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Fortsetzbare Rechnungs- und Kontaktsynchronisation | Kein manuelles Übertragen von Rechnungsdaten | alle Firmen | aktive Lexware-Verbindung | produktiv nachgewiesen | verarbeitet je Aufruf eine feste, kleine Rechnungsmenge (Ratenbegrenzung gegenüber Lexware Office) | `app/sync.php`, `app/sync_state.php` | `php-ionos/app/sync.php` |
| Live-Fortschrittsanzeige der Synchronisation | Transparenz während eines laufenden Abgleichs | alle Rollen | laufender Sync | im Quellcode vorhanden | reine Anzeige, löst selbst nichts aus | `app/sync_state.php` | `php-ionos/sync-status.php` |
| Synchronisationshistorie | Nachvollziehbarkeit von Dauer, Mengen und Fehlern je Lauf | Inhaber, Administrator | mindestens ein abgeschlossener Lauf | im Quellcode vorhanden | zeigt nur firmeneigene Läufe | `sync_runs` | `php-ionos/synchronisationen.php` |
| Schneller Abgleich (reconcile) | Erkennt Abweichungen zwischen Lexware Office und lokalem Stand ohne Einzelabrufe | alle Rollen | aktive Verbindung | im Quellcode vorhanden | nutzt nur die Belegliste (Nummer und Status), keine Detailprüfung | `app/invoice_source.php` | `php-ionos/reconcile.php` |
| Wartungsmodus der Synchronisation je Firma | Synchronisation gezielt pausieren, ohne die Anwendung zu sperren | Betreiber (Adminbereich) | Zugriff auf Adminbereich | im Quellcode vorhanden | betrifft nur die Synchronisation, nicht Login oder Einzüge | `organizations.sync_paused` | `php-ionos/admin-system.php` |

### 2.5 Mandate

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| SEPA-Mandatsverwaltung (Referenz, Dokument, Unterschrift, Verfall) | Rechtssichere Grundlage für jeden Einzug | alle Rollen | Kunde mit IBAN | produktiv nachgewiesen | fachliche Regeln zur SEPA-Basislastschrift sind Rechercheergebnis und vor Produktivstart durch Rechtsberatung zu verifizieren (Kopfkommentar der Datei) | `app/mandates.php` | `php-ionos/app/mandates.php`, `php-ionos/customer.php` |
| Digitale Mandatsanforderung per Link | Kunde erteilt Mandat selbst, ohne Anmeldung | Endkunden der Firmen | Feature-Schalter aktiv, E-Mail-Adresse des Kunden | im Quellcode vorhanden (Feature-Schalter) | Token einmalig und zeitlich begrenzt | `app/mandate_requests.php` | `php-ionos/mandat.php` |
| Mandatsdokument (Druckansicht, Upload) | Nachweisbares Mandat auch bei handschriftlicher Unterschrift | alle Rollen | hochgeladenes oder digital erteiltes Mandat | im Quellcode vorhanden | Auslieferung nur nach Mandantenprüfung, außerhalb des direkten Webzugriffs | `app/mandate_files.php` | `php-ionos/mandate-print.php`, `php-ionos/mandate-file.php` |

### 2.6 Lastschriften und Einzüge

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Sofortiger Einzug, Terminierung, Umterminierung, Storno | Flexible Einzugssteuerung je Rechnung | alle Rollen | gültiges Mandat, offene Rechnung | produktiv nachgewiesen | Prüfreihenfolge vor jedem Stripe-Aufruf ist standardmäßig sicher (im Zweifel keine Einreichung) | `app/collections.php` | `docs/payment-safety.md`, `php-ionos/collections.php` |
| Sammel-Einzug mehrerer Rechnungen | Zeitersparnis bei vielen fälligen Rechnungen | alle Rollen | mehrere offene, mandatierte Rechnungen | im Quellcode vorhanden | unterliegt denselben Prüfungen wie der Einzeleinzug | `app/collections.php` | `php-ionos/collections.php` |
| Einreichfenster für terminierte Lastschriften | Einreichung nur in einem definierten Zeitfenster (Vorgabe 23:00 bis 06:00 Uhr) | Betreiber, alle Firmen | keine | produktiv nachgewiesen | betrifft ausschließlich die Einreichung, nicht Synchronisation oder Klärung | `app/jobs.php`, `app/collections.php` | `php-ionos/app/collections.php` |
| Not-Stopp (alle Einzüge einer Firma anhalten) | Sofortiges Anhalten bei Verdacht auf Fehler, ohne Einzelbearbeitung | Inhaber, Administrator | keine | im Quellcode vorhanden | wirkt auf Sofort-, Sammel- und terminierte Einreichung gleichermaßen | `organizations.collections_paused` | `php-ionos/notstopp.php` |
| Übernahme bestehender Einzüge aus Stripe | Migration ohne Datenverlust bei Neuaufsatz oder Firmenwechsel | Inhaber, Administrator | 2FA-Zweitbestätigung | im Quellcode vorhanden | reiner Lesezugriff auf Stripe, kein Geld bewegt sich beim Import selbst | `app/stripe_import.php` | `php-ionos/stripe-import.php` |
| Regelbasierte Einzugsautomatik | Automatischer Einzug nach festen Kriterien ohne Einzelfreigabe | künftig alle Firmen | Freigabeschalter (derzeit aus) | geplant (Gerüst ohne Verarbeitung) | Datenstruktur vorhanden, keine automatische Verarbeitung ausgelöst | Tabelle `collection_rules` | `php-ionos/app/collection_rules.php` |

### 2.7 Statusverarbeitung, Klärung, Erstattungen

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Stripe-Webhook-Verarbeitung (Erfolg, Fehlschlag) | Zeitnaher, automatischer Statusabgleich | alle Firmen | Webhook im jeweiligen Stripe-Konto eingerichtet | produktiv nachgewiesen | jedes Ereignis wird genau einmal verarbeitet (Tabelle `webhook_events`), unabhängig vom Warteschlangenstatus | `stripe-webhook.php` | `php-ionos/stripe-webhook.php` |
| Klärung unklarer Einzugsversuche | Kein Versuch verschwindet unbemerkt | alle Rollen | technisch unklarer Rückmeldestatus | im Quellcode vorhanden | eigener Bearbeitungsschritt, kein automatischer Neuversuch ohne Prüfung | `app/collections.php` | `docs/payment-safety.md` |
| Erstattungen aus Stripe übernehmen | Betroffene Rechnung wird korrekt wieder als offen geführt | alle Firmen | Erstattung im Stripe-Konto gebucht | im Quellcode vorhanden | kein automatischer Neu-Einzug ohne erneute Freigabe | Dispute-Webhook | `php-ionos/app/collections.php` |
| Alarmierung bei kritischen Zuständen | Team wird auf eingriffsbedürftige Zustände aufmerksam gemacht | Inhaber (Stufe hoch, täglich per E-Mail) | Mailversand aktiv | im Quellcode vorhanden | reine Leseprüfungen ohne Nebenwirkungen | `app/alerts.php` | `php-ionos/app/alerts.php` |
| Zweitbestätigung kritischer Aktionen | Schutz vor versehentlichen, folgenreichen Aktionen | alle Rollen (je nach Aktion) | aktueller 2FA-Code | im Quellcode vorhanden | seit 4.36 nur für Anmeldung, Wechsel in Kundenaccounts, Wartung aktivieren, Not-Stopp aufheben, Geldfluss und Kontosicherheit (Beschluss des Vorstands vom 07.09.2026); Tarife, Entwürfe, Statusveröffentlichung, Diagnose ohne Code | `app/auth.php` | `docs/payment-safety.md`, `docs/entwickler/sicherheit.md` |

### 2.8 Benachrichtigungen

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| E-Mail-Layout im Corporate Design mit Pflichtangaben | Wiedererkennbare, rechtlich vollständige Kommunikation | alle Empfänger | Mailversand aktiv | im Quellcode vorhanden; Testversand technisch erfolgreich übergeben laut `docs/ARBEITSSTAND.md`, Zustellung im Postfach zum Stand dieser Dokumentation noch nicht bestätigt | ohne `mail.enabled` versendet die Anwendung keine E-Mails, Vorgänge werden stattdessen als wartend markiert und nachgesendet | `app/mailer.php` | `docs/mail-einrichtung.md` |
| Willkommens- und Bestätigungsmail bei Registrierung | Nachvollziehbare, bestätigte E-Mail-Adresse | neue Benutzer | Mailversand aktiv oder Nachsendung durch Wartung | im Quellcode vorhanden | Nachsendung erst nach Aktivierung des Versands (`users.welcome_mail_pending`) | `app/auth.php`, Migration 021/022 | `docs/mail-einrichtung.md` |
| Sicherheitsmeldungen (Support-Zugriff, Geräteänderung) | Transparenz bei sicherheitsrelevanten Ereignissen | Inhaber | Mailversand aktiv | im Quellcode vorhanden | abhängig vom Mailversand | `app/mailer.php` | `php-ionos/app/support.php` |
| Upsell-Hinweis bei Tarifgrenzen | Rechtzeitiger Hinweis vor Erreichen des Limits | Inhaber | mindestens zwei aktive Tarife | im Quellcode vorhanden | einmal je Periode zusätzlich per E-Mail | `app/plans.php` | `php-ionos/app/version.php` (Änderungseintrag 4.1) |

### 2.9 Abonnement und Tarife

Grundsatz: Tarife, Preise und Limits kommen ausschließlich aus der Tabelle `plans`
(`php-ionos/app/plans.php`), niemals fest verdrahtet im Frontend. Zum Stand dieser Dokumentation ist
genau ein Tarif angelegt.

| Tarif (Code) | Bezeichnung | Preis | Periode | Quelle |
|---|---|---|---|---|
| `unlimited_start` | UNLIMITED START | 25,00 EUR netto (Einführungspreis, bisher 50,00 EUR), zzgl. USt. | 28 Tage | `php-ionos/sql/schema.sql`, Zeile mit `INSERT INTO plans` |

Der Einführungspreis gilt für Firmenaccounts, die bis zum Ende des laufenden Kalendermonats angelegt
werden; bereits angelegte Accounts behalten ihn, solange das Abonnement läuft. Der Stichtag ist
rollierend und wird von der Anwendung berechnet (`intro_price_deadline()` in
`php-ionos/app/pricing.php`), nicht als festes Datum gepflegt. Weitere Tarife (laut
`docs/ARBEITSSTAND.md` sind zwei bis drei Tarife geplant) sind zum Stand dieser Dokumentation nicht
angelegt.

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Abo-Seite der Firma (Tarif, Status, Bestellung, Rechnungen, Kündigung) | Selbstständige Verwaltung des eigenen Abonnements | Inhaber | aktive Plattform-Abrechnung | im Quellcode vorhanden; Inbetriebnahme laut `docs/abrechnung.md` vorbereitet, ob `billing.enabled` produktiv gesetzt ist, ist aus dem Repository nicht ersichtlich | ohne aktive Abrechnung nur Anzeige, keine Sperre | `app/billing.php` | `php-ionos/subscription.php`, `docs/abrechnung.md` |
| Checkout, Kundenportal, Ereignisverarbeitung (Stripe-Konto der Müller Holding AG) | Abschluss, Zahlungsmethode und Kündigung ohne Support-Kontakt | Inhaber | Stripe Tax und Kundenportal im Dashboard konfiguriert | konfiguriert, aber nicht geprüft (Prüfschritte in `docs/abrechnung.md` noch abzuarbeiten) | Beträge bestehender Stripe-Preise sind unveränderlich, ein geänderter Tarifpreis braucht einen neuen Preis | `app/billing.php`, `billing-webhook.php` | `docs/abrechnung.md` |
| Sperre ohne nutzbares Abonnement | Schutz der Betreiberin vor unbezahlter Nutzung | Betreiber | `billing.enabled = true` | im Quellcode vorhanden | betrifft auch bestehende, produktiv arbeitende Firmen ohne Ausnahme (`billing_exempt`) | `subscription_allows_operation()` | `php-ionos/app/billing.php` |
| Tarifwechsel (Upgrade/Downgrade) | Anpassung an geänderten Bedarf | Inhaber | mindestens zwei aktive, öffentliche Tarife | im Quellcode vorhanden | erscheint nur bei mindestens zwei aktiven Tarifen; zum Stand dieser Dokumentation nur ein Tarif angelegt | Migration 019 | `php-ionos/subscription.php` |
| Prüf- und Anlagewerkzeuge (`billing-check.php`, `billing-setup-stripe.php`) | Sichere, wiederholbare Inbetriebnahme ohne manuelle Stripe-Konfiguration im Dashboard für Produkt und Preis | Betreiber | Zugriff auf den Anwendungscontainer | im Quellcode vorhanden | Schlüssel erscheinen in der Ausgabe nur maskiert | `app/billing_setup.php` | `php-ionos/bin/billing-check.php`, `php-ionos/bin/billing-setup-stripe.php` |

### 2.10 Administration

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Plattform-Benutzer und Rechte (Mitarbeiter, Support, Administratoren; eigene Rollen aus Berechtigungskatalog) | Arbeitsteilung im Betreiberteam ohne Weitergabe des Administratorkontos | Vorstand (Administrator) | Rolle Administrator (`users.manage`), aktiver Mailversand für Einladungen | im Quellcode vorhanden (4.37) | letzter Administrator nicht entfernbar; jeder Zugang braucht 2FA; Einladung nur per E-Mail | Migration 027, `app/platform.php` | `php-ionos/admin-users.php`, `docs/entwickler/sicherheit.md` |
| Kennzahlen je Akquisitionsquelle, Firmen, Tarifpflege | Steuerung von Marketing und Produktangebot | Plattformadministrator | Plattformrolle mit `companies.view`, `companies.plan`, `plans.manage`; aktive 2FA | produktiv nachgewiesen | keine Umsatzkennzahlen im engeren Sinn, siehe Abschnitt 6 | `app/plans.php` | `php-ionos/admin.php` |
| Systemmonitoring (Dienste, Warteschlange, Worker, Circuit Breaker, Server) | Ehrliche Betriebstransparenz ohne Fremdwerkzeug | Plattformadministrator | VPS-Betrieb für Host-Metriken, sonst eingeschränkt auf dem Webhosting | im Quellcode vorhanden | zeigt ausschließlich tatsächlich erhobene Werte, fehlende Messmöglichkeit erscheint als solche, nicht als Fehler | `app/monitor.php` | `php-ionos/admin-system.php`, `docs/monitoring.md` |
| Störungs- und Wartungsmeldungen, Statusveröffentlichung | Nachvollziehbare Kommunikation von Störungen | Plattformadministrator, indirekt alle Endkunden über die Statusseite | 2FA-Code beim Veröffentlichen; Zurückziehen, Testversand und Snapshot-Übertragung ohne Code (seit 4.36) | im Quellcode vorhanden | ohne `status_publish` bleibt die öffentliche Seite auf „Status unbekannt" | Migration 017 | `php-ionos/admin-system.php`, `docs/status-page.md` |
| Job- und Warteschlangenverwaltung (Dead Letter, erneut versuchen, abbrechen) | Kontrolle über Hintergrundverarbeitung ohne Servereingriff | Plattformadministrator | Feature-Flag `queue` aktiv | im Quellcode vorhanden | auf dem Webhosting standardmäßig aus (Cron-Betrieb), auf dem VPS vorgesehen | `app/queue.php`, `app/jobs.php` | `docs/queue-worker.md` |
| Technische Dokumentation im Adminbereich | Zentrale, versionierte Betriebsdokumentation ohne externes Wiki | Plattformadministrator | `tools/build-docs.py` ausgeführt | im Quellcode vorhanden | Auslieferung ausschließlich über eine Positivliste (`manifest.json`), keine freie Dateiauswahl | `app/docs-build` | `php-ionos/admin-doc.php` |
| Rechtsdokumente verwalten (Fassungen anlegen, veröffentlichen, zurückziehen) | Nachweisbare Vertragsgrundlage je Firma | Plattformadministrator | 2FA-Code beim Veröffentlichen und Zurückziehen; Entwürfe ohne Code (seit 4.36) | im Quellcode vorhanden | Veröffentlichen ist gesperrt, solange der Text Platzhalter enthält | `app/legal.php` | `php-ionos/admin-legal.php`, `docs/rechtsdokumente.md` |
| Verwaltung der Vormerkungen für angekündigte Integrationen | Übersicht über Interesse an künftigen Anbindungen (sevdesk) | Plattformadministrator | 2FA-Code für Aktionen | im Quellcode vorhanden | zum Stand dieser Dokumentation existiert kein Versandwerkzeug für die Startnachricht | Migration 020 | `php-ionos/admin.php`, `docs/integrations.md` |
| Support-Zugriff auf Firmenaccounts | Zielgerichtete Unterstützung ohne Passwortweitergabe | Plattformrolle mit `support.sessions` (Administrator, Mitarbeiter Support) | Begründung und 2FA-Code | im Quellcode vorhanden | zeitlich begrenzt, protokolliert, Sicherheitsmail an den Inhaber | `app/support.php` | `php-ionos/admin-support.php` |

### 2.11 Rechtsdokumente

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Auftragsverarbeitungsvertrag nach Art. 28 DSGVO mit Zustimmungsnachweis | Rechtssichere Grundlage für die Datenverarbeitung durch die Betreiberin | alle Firmen | veröffentlichte Fassung | im Quellcode vorhanden; laut `docs/ARBEITSSTAND.md` liegt der Entwurf zur anwaltlichen Prüfung noch nicht veröffentlicht vor | Anlage 3 (Hostinganbieter, Serverstandort) enthält noch Platzhalter, Veröffentlichen deshalb gesperrt | Migration 023 | `docs/rechtsdokumente.md`, `php-ionos/app/legal_drafts.php` |
| Verschwiegenheitsvereinbarung für Berufsgeheimnisträger (§ 203 StGB) | Zusätzliche Absicherung für Steuerberater, Anwälte, Ärzte als Kunden | Firmen mit Verschwiegenheitspflicht | Angabe der Verschwiegenheitspflicht unter Rechtliches | im Quellcode vorhanden, ebenfalls unveröffentlicht | gilt nur für Firmen, die ihre Pflicht angegeben haben | Migration 023 | `docs/rechtsdokumente.md` |
| Impressum, AGB, Datenschutzerklärung | Gesetzlich erforderliche Angaben | alle Besucher, Kunden | Betreiberdaten in Konfiguration | im Quellcode vorhanden | fehlende Angaben erscheinen als Platzhalter, nicht als erfundener Wert | `config('operator')` | `php-ionos/impressum.php`, `websites/smart-einzug.de/agb/`, `websites/smart-einzug.de/datenschutz/` |

### 2.12 Statusseite und Websites

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Öffentliche Statusseite (status.smart-einzug.de) | Transparente Kommunikation der Systemverfügbarkeit | Kunden, Interessenten | `status_publish` konfiguriert | konfiguriert, aber nicht geprüft (laut `docs/ARBEITSSTAND.md` am 07.09.2026 gesetzt, Wirksamkeit noch nicht bestätigt) | zeigt nur eine feste Positivliste an Feldern, keine internen Kennzahlen | `app/monitor.php` | `docs/status-page.md` |
| Marketingseiten (smart-einzug.de, lexware-einzug.de, lexoffice-einzug.de) | Produktinformation, Preisdarstellung, Registrierungsanstoß | Interessenten | keine | produktiv nachgewiesen (statische Auslieferung über IONOS-Webhosting) | Inhalte beider Kerndomains dürfen sich laut interner Qualitätsvorgabe zu höchstens 35 Prozent gleichen | `tools/site-qa.py` | `websites/smart-einzug.de`, `websites/lexware-einzug.de`, `websites/lexoffice-einzug.de` |
| Cookielose Reichweitenmessung (Funnel) | Marketingerfolg messbar ohne Cookies und personenbezogene Daten | Betreiber | keine | im Quellcode vorhanden | keine IP-Adressen, keine Cookies, keine Drittanbieter-Skripte ohne Einwilligung | Tabelle `funnel_events` | `php-ionos/track.php` |
| Google-Tags (GA4, Ads) hinter Einwilligung | Rechtskonforme Erfolgsmessung | Betreiber | Einwilligungsbanner bestätigt | im Quellcode vorhanden | niemals direkt im HTML eingebunden | `assets/js/site.js` | `websites/smart-einzug.de/assets/js/site.js` |

### 2.13 Hilfe-Center und Support

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Anleitungen und häufige Fragen | Selbstständige Klärung ohne Supportkontakt | alle Rollen | keine | im Quellcode vorhanden | Inhalte reines Textarray, eingeschränktes HTML | `app/help_content.php` | `php-ionos/hilfe.php` |
| Support-Anfragen (Tickets) mit Verlauf | Nachvollziehbare Klärung offener Fragen | alle Rollen | keine | im Quellcode vorhanden | Benachrichtigung per E-Mail vom Mailversand abhängig | `app/support_tickets.php` | `php-ionos/admin-support.php` |

### 2.14 Vorregistrierung für angekündigte Integrationen (sevdesk)

| Funktion | Kundennutzen | Zielgruppe | Voraussetzungen | Verfügbarkeit | Einschränkungen | Abhängigkeiten | Nachweis (Datei) |
|---|---|---|---|---|---|---|---|
| Unverbindliche Vormerkung (Warteliste) mit Double-Opt-in | Rechtzeitige Information bei Verfügbarkeit, ohne Verpflichtung | Interessenten für sevdesk | E-Mail-Adresse, Bestätigung per Link | im Quellcode vorhanden, seit Version 4.18 produktiv geschaltet laut `docs/ARBEITSSTAND.md` | kein Preis, kein Kaufbutton, kein Firmenaccount vor Freigabe der Anbindung | `app/interest.php`, Migration 020 | `php-ionos/vormerken.php`, `docs/integrations.md` |

---

## 3. Systemlandschaft

@@diagramm 02-zwei-host-architektur

### 3.1 Überblick

SmartEinzug besteht aus einer einzigen PHP-Codebasis (`php-ionos/`, PHP 8, MariaDB, ohne Composer),
die auf zwei unterschiedlichen Hostingumgebungen betrieben werden kann, sowie statischen
Marketing-Websites, die unabhängig davon liegen.

| Bestandteil | Läuft wo | Zweck |
|---|---|---|
| Anwendung (Kundenportal, Adminbereich, API-Endpunkte) | derzeit produktiv auf IONOS-Webhosting, vorbereitet für den Hostinger-VPS | Kernanwendung |
| Marketing-Websites (smart-einzug.de, lexware-einzug.de, lexoffice-einzug.de und weitere) | IONOS-Webhosting | Produktinformation, Registrierungsanstoß, Leadgenerierung |
| Statusseite (status.smart-einzug.de) | vorgesehen auf dem jeweils aktiven Anwendungsserver, siehe unten | Öffentliche Verfügbarkeitsanzeige |
| Datenbank (MariaDB) | auf dem Webhosting eine gewöhnliche MariaDB-Instanz, auf dem VPS eine private Coolify-MariaDB-Ressource | Anwendungsdaten |
| Redis | nur auf dem VPS vorgesehen, optional | Beschleunigt Sperren und Ratenbegrenzung |
| Stripe | extern (zwei getrennte Verwendungen, siehe unten) | Zahlungsabwicklung |
| Lexware Office | extern | Quelle der Rechnungs- und Kundendaten |
| E-Mail-Versand | über IONOS-SMTP | Transaktions- und Sicherheitsmails |
| GitHub (`v3ni94/LexofficXSTRIPE`) | extern | Quellcodeverwaltung, Deployment-Auslöser |

### 3.2 Betriebszustand: Hostinger-VPS produktiv, IONOS-Webhosting für Websites

Dies ist der wichtigste Punkt für das Verständnis des aktuellen Betriebszustands. Die Unterlagen im Repository sind hier nicht einheitlich, deshalb die Trennung nach Nachweisstufen:

- **Nachgewiesen (07.09.2026):** Die Anwendung (Kundenanwendung, Adminbereich, Webhook-Host, Statusseite) läuft auf dem Hostinger-VPS mit Coolify. Die Versionen 4.20 bis 4.31 wurden über den GitHub-Workflow erfolgreich auf diesen Server ausgeliefert (Statusdatei des Deployments, laufende Container, Health-Check über den internen Webserver). Der Mailversand der Anwendung wurde am selben Tag auf diesem Server in Betrieb genommen; Kundenvorgänge der Vorregistrierung wurden dort verarbeitet.
- **Konfiguriert:** Der VPS ist als Hostinger KVM 8 (Ubuntu 24.04, Hostname srv1960492, öffentliche IPv4 72.61.80.67) eingerichtet; der Docker-Stack umfasst Webserver, Anwendung, Scheduler, fünf Worker, Metrik-Sammler und Redis; die Datenbank ist eine private Coolify-MariaDB mit täglicher Sicherung nach Hetzner Object Storage (laut Betriebsdokumentation). Tarifgrößen (vCPU, RAM) sind Hostinger-Tarifangaben und im Server nur für den Speicher (386 GB) nachgewiesen.
- **Offen (Prüffrage für die Übernahme):** Die Betriebsdokumentation (`docs/vps/08-hostinger-coolify.md`, `docs/vps/07-cutover-checkliste.md`) führt den formalen Cutover mit DNS-Umstellung noch als offenen Schritt. Zu prüfen ist, ob die DNS-Einträge für `app.`, `admin.`, `api.` und `status.smart-einzug.de` auf die IPv4 des VPS zeigen, ob die frühere Anwendungsinstanz auf dem IONOS-Webhosting (`sepa.muellerhv.de`) abgeschaltet ist und ob deren Cronjob entfernt wurde (Cutover-Checkliste Punkt 12).
- **Dauerhaft auf dem IONOS-Webhosting:** die statischen Websites (`smart-einzug.de`, `lexoffice-einzug.de`, `lexware-einzug.de`, `lastschrift-einfach.de`), die DNS-Zonen und die E-Mail-Postfächer (SMTP-Versand der Anwendung über IONOS).

Für einen Übernehmer bedeutet das: Der Betrieb ist auf eine automatisiert ausgelieferte Container-Infrastruktur umgestellt; die letzten formalen Schritte (DNS-Nachweis, Abschaltung der Altinstanz, Aktualisierung der Betriebsdokumentation) sind zu bestätigen und in der Übergabecheckliste aufgeführt.

### 3.3 Hostarchitektur (Zielbild VPS)

| Host | Zweck |
|---|---|
| `app.smart-einzug.de` | Kundenanwendung (Login, Dashboard, Rechnungen, Einzüge, Kunden, Firma, Einstellungen) |
| `admin.smart-einzug.de` | Plattformadministration, getrennt von Kundenseiten |
| `api.smart-einzug.de` | ausschließlich maschinelle Endpunkte (Stripe-Webhook, Plattform-Billing-Webhook, Gesundheitsabfrage, Reichweitenmessung) |
| `status.smart-einzug.de` | öffentliche Statusseite, kein PHP |
| `staging.smart-einzug.de` | Testumgebung auf einem eigenen, physisch getrennten Server mit eigener Datenbank |

Auf dem VPS terminiert ein von Coolify bereitgestellter Reverse Proxy (Traefik) das TLS-Zertifikat
(Let's Encrypt) und reicht Anfragen an einen intern laufenden Caddy-Server weiter, der wiederum an
PHP-FPM weiterleitet. Ein Scheduler-Container prüft alle 30 Sekunden fällige wiederkehrende Aufgaben
und reiht sie in eine Warteschlange ein; mehrere Worker-Container verarbeiten diese Aufgaben nach
Zuständigkeit (Lexware-Synchronisation, Stripe, E-Mail, Wartung). Ein separater Container liest
Host-Kennzahlen (CPU, Speicher, Platte, Auslastung) für das Monitoring.

### 3.4 Datenhaltung

| Daten | Ort |
|---|---|
| Anwendungscode (VPS) | `/opt/smarteinzug/releases/<git-sha>/`, read-only in den Containern eingebunden |
| Konfiguration (VPS) | `/opt/smarteinzug/shared/config.php`, außerhalb jedes Release |
| Anwendungsdaten (Mandate, Avatare, Protokolle) | `/opt/smarteinzug/shared/storage`, gemeinsam für alle Container |
| Datenbank (VPS) | private Coolify-MariaDB-Ressource, nur intern erreichbar, kein veröffentlichter Port |
| Sicherungen | tägliche Coolify-Sicherung, zusätzlicher externer Upload nach Hetzner Object Storage |

### 3.5 Sicherheitsgrundsätze (technische Umsetzung)

- Zugangsdaten zu Lexware Office und Stripe werden ausschließlich verschlüsselt gespeichert
  (`app/crypto.php`, AES-256-GCM) und nie protokolliert oder an den Browser ausgegeben.
- Jede geld- und sicherheitsrelevante Aktion wird im Protokoll (`audit_log`) mit Urheber und
  Zeitpunkt festgehalten.
- Mandantentrennung gilt für jede Datenbankabfrage; jede Firma sieht ausschließlich eigene Kunden,
  Rechnungen, Einzüge und Anbindungen.
- Externe Aufrufe (Lexware Office, Stripe, E-Mail) laufen über eine zentrale Schranke mit Circuit
  Breaker und Ratenbegrenzung; technische Fehler werden erkannt und die betroffene Anbindung bei
  wiederholtem Fehlschlag vorübergehend geschont.

---

## 4. Verzeichnis der Vermögenswerte und Abhängigkeiten

Dieser Abschnitt dient der Vorbereitung einer möglichen Übernahme. Eigentum und Übertragbarkeit werden
nur behauptet, wo ein Nachweis im Repository vorliegt; andernfalls steht ausdrücklich
„Nachweis fehlt (Prüffrage)".

### 4.1 Domains

| Domain / Subdomain | Funktion | Registrar |
|---|---|---|
| smart-einzug.de (inkl. `app.`, `admin.`, `api.`, `status.`) | Hauptmarke, Anwendung, Adminbereich, API, Statusseite | Nachweis fehlt (Prüffrage); Betrieb laut Dokumentation über IONOS-Webhosting |
| lexoffice-einzug.de | Marketing-/Leadseite | Nachweis fehlt (Prüffrage); laut `php-ionos/app/version.php` (Eintrag 4.32) inhaltlich seit 07.09.2026 als eigenständige Informationsseite der DETM Management Consulting FZCO ausgewiesen, wirtschaftlich weiterhin Produkt der Müller Holding AG |
| lexware-einzug.de | Marketing-/Leadseite, ebenso über DETM ausgewiesen | Nachweis fehlt (Prüffrage) |
| lastschrift-einfach.de | Eigenständige SEO-Ratgeberseite zur SEPA-Lastschrift (siehe `tools/site-qa.py`) | Nachweis fehlt (Prüffrage) |
| einzug-direkt.de, smart-lastschrift.de, smarteinzug.de | Weiterleitungsdomains auf smart-einzug.de (nur `.htaccess` und `404.html`) | Nachweis fehlt (Prüffrage) |

Hinweis: `docs/smarteinzug-rollout.md` beschreibt `lastschrift-einfach.de` noch als reine
Weiterleitungsdomain; im Repository liegt inzwischen ein vollständiger, eigenständiger Ratgeberinhalt
unter `websites/lastschrift-einfach.de/`, und `tools/site-qa.py` prüft die Domain wie eine vollwertige
Inhaltsseite. Diese Abweichung zwischen Planungsdokument und tatsächlichem Repository-Stand sollte vor
einer Übernahme aufgelöst werden.

Welche dieser Domains tatsächlich bei einem Registrar (laut Dokumentation vermutlich IONOS) angemeldet
sind, ist aus dem Repository nicht ersichtlich und beim Registrar beziehungsweise Domain-Kontoinhaber
zu prüfen.

### 4.2 Repository

| Vermögenswert | Angabe |
|---|---|
| Quellcode-Verwaltung | GitHub, `v3ni94/LexofficXSTRIPE` |
| Aktiver Branch (Stand dieser Dokumentation) | `claude/setup-lexsepa-monorepo-v5ZcZ` |
| Deploymentweg | ausschließlich über den GitHub-Workflow per SSH auf den jeweiligen Server, kein Coolify-Autodeploy |
| Historie | vollständige Commit-Historie mit Autor Timo Müller, Versionsverlauf zusätzlich in `php-ionos/app/version.php` (`app_changelog()`) |

Eigentum am Repository und den zugehörigen GitHub-Zugängen: Nachweis fehlt (Prüffrage), da
Kontoinhaberschaft und Organisationszugehörigkeit bei GitHub nicht Teil des Repository-Inhalts sind.

### 4.3 Marken- und Designbestandteile

| Bestandteil | Ort |
|---|---|
| Markensteckbrief (Farben, Schriftstapel, Logovarianten, Schutzraum, Mindestgrößen) | `docs/smarteinzug-brand.md` |
| Logo-Dateien (Bildmarke, Wortmarke, Favicons, Social-Grafiken) | referenziert als externes Logo-Paket (`logo-paket-smarteinzug.zip`), eingebundene Assets unter `websites/*/assets/` |
| Markenfarben | Gold #E3AC48, Anthrazit #2E2D2E, Beige #FBF6EC, Grau #9F9F9F |

Ausdrücklicher Hinweis aus `docs/smarteinzug-brand.md`: Eine markenrechtliche Prüfung des Namens
„SmartEinzug" (Eintragbarkeit, Kollision mit Rechten Dritter) ist bislang nicht erfolgt und vor
umfangreicher Bewerbung durch einen Rechtsanwalt vorzunehmen.

### 4.4 Hostingkonten

| Anbieter | Zweck | Status laut Dokumentation |
|---|---|---|
| IONOS (Webhosting) | Produktiver Betrieb der Anwendung und aller Marketing-Websites | im Einsatz, Kontoinhaberschaft: Nachweis fehlt (Prüffrage) |
| Hostinger (VPS, Tarif KVM 8, Vorlage „Ubuntu 24.04 with Coolify") | Vorbereiteter, technisch erprobter Zielserver für den Kundenverkehr | eingerichtet, produktiver Kundenverkehr noch nicht umgestellt (siehe Abschnitt 3.2); Kontoinhaberschaft: Nachweis fehlt (Prüffrage) |

### 4.5 Dienstleister

| Dienstleister | Rolle | Vertragspartner laut Dokumentation |
|---|---|---|
| Stripe | Zahlungsabwicklung (SEPA-Lastschrift je Firma, getrennt Plattform-Abrechnung der Müller Holding AG) | je Firma eigener Vertrag mit Stripe; Plattform-Konto der Müller Holding AG |
| Lexware Office | Quelle der Rechnungs- und Kundendaten | Vertrag jeder Kundenfirma mit Lexware, nicht mit der Betreiberin |
| IONOS | Webhosting, E-Mail-Versand (SMTP) | vermutlich Vertragspartner der Müller Holding AG, Kontoinhaberschaft: Nachweis fehlt (Prüffrage) |
| Hetzner Object Storage | externes Sicherungsziel der Coolify-Datenbanksicherung | laut Dokumentation eingerichtet, Vertragsinhaberschaft: Nachweis fehlt (Prüffrage) |
| Hostinger | VPS-Bereitstellung inklusive vorinstalliertem Coolify | siehe 4.4 |

### 4.6 Softwarelizenzen

| Software | Einsatz | Lizenzart |
|---|---|---|
| PHP 8 | Laufzeitumgebung der Anwendung | Open-Source-Lizenz (PHP License); genaue Lizenzbedingungen der eingesetzten Version zu prüfen |
| MariaDB | Anwendungsdatenbank | Open-Source-Lizenz (GPL-Familie); genaue Lizenzbedingungen der eingesetzten Version (11.8.9 laut `docs/vps/01-architektur.md`) zu prüfen |
| Redis | optionale Beschleunigung von Sperren und Ratenbegrenzung | Lizenzbedingungen haben sich zwischen älteren und neueren Redis-Versionen geändert; zu prüfen, welche Version und Lizenz konkret eingesetzt wird |
| Caddy | interner HTTP-Server auf dem VPS | Open-Source-Lizenz (Apache License 2.0 laut allgemein bekanntem Projektstand); zu prüfen |
| Coolify | Reverse Proxy, Serververwaltung, private Datenbankressource auf dem VPS | Open-Source-Projekt mit eigenem Lizenzmodell; zu prüfen, ob eine kostenpflichtige Lizenzstufe genutzt wird |

Die Anwendung selbst ist ohne Composer und ohne weitere Drittbibliotheken aufgebaut (eigener
Stripe-Client, eigener Lexware-Client, eigene Kryptografie- und TOTP-Implementierung); es besteht daher
keine Abhängigkeit von einzelnen PHP-Paketen mit eigenen Lizenzbedingungen, abgesehen von den oben
genannten Systemkomponenten.

### 4.7 Dokumentationen

Vollständig im Repository vorhanden unter `docs/` (Fachdokumentation), `docs/vps/` (Einrichtung und
Betrieb des VPS), `docs/anlagen/` (drei PDF-Anlagen zu Betrieb/VPS-Migration, Buchhaltungssystem-Wechsel
und dem Konzept einer sevdesk-Anbindung) sowie als technische Dokumentation im Adminbereich der
Anwendung selbst (`tools/build-docs.py`, ausgeliefert über `admin-doc.php`).

### 4.8 Zuständigkeiten

Laut Commit-Historie und den in `CLAUDE.md` genannten Vorgaben liegt die operative und
geschäftsführende Zuständigkeit bei Timo Müller (Müller Holding AG). Eine über den Quellcode
hinausgehende Aufstellung weiterer Personen (Entwicklung, Support, Rechtsberatung) liegt im Repository
nicht vor: Nachweis fehlt (Prüffrage).

---

## 5. Risiken und Grenzen

Diese Aufstellung folgt bewusst den in `docs/ARBEITSSTAND.md` benannten offenen Punkten und ergänzt sie
um strukturelle Risiken, die sich aus der Systemlandschaft ergeben.

### 5.1 Bekannte technische Sachverhalte und offene Punkte (aus `docs/ARBEITSSTAND.md`)

- Die Wirksamkeit der am 07.09.2026 gesetzten Konfiguration für die öffentliche Statusseite
  (`status_publish`) war zum Stand des Dokuments noch nicht bestätigt.
- Auf dem Server liegen laut Dokumentation zwei vermutlich versehentlich entstandene, leere Dateien
  aus einer Shell-Eingabe; sie gelten als harmlos, sollten aber entfernt werden.
- Die Produktionskonfiguration trägt laut Dokumentation keinen Schlüssel `environment`, was in
  `bin/billing-check.php` als „Umgebung: ?" erscheint; dies wird toleriert, ist aber ein Beispiel für
  unvollständige Konfigurationspflege.
- Eine Repository-Variable (`WEBHOSTING_APP_DEPLOY`) und ein externer Uptime-Check sind laut
  Dokumentation ungeprüft beziehungsweise nicht eingerichtet.
- Der Mailversand wurde laut Dokumentation am 07.09.2026 aktiviert; die tatsächliche Ankunft von
  E-Mails im Postfach und die Nachsendung wartender Vormerkungen waren zum Stand des Dokuments noch
  vom Betreiber zu bestätigen.
- Ein Deployment-Lauf ist laut Dokumentation an einem SSH-Timeout gescheitert (externe Ursache,
  Netz oder Firewall); ein Zusammentreffen zweier gleichzeitiger manueller Wartungsskript-Aufrufe mit
  einem laufenden Deployment führte zu einem weiteren fehlgeschlagenen Lauf, was inzwischen durch eine
  gemeinsame Sperre behoben wurde.
- Ein Altrelease ist bei einer Bereinigung versehentlich gelöscht worden, weil ihm eine Markerdatei
  fehlte; dies wurde durch eine nachträgliche Kennzeichnung vollständiger Altreleases behoben, das
  betroffene Altrelease selbst gilt laut Dokumentation als verloren.

### 5.2 Technische Schulden und begrenzte Absicherung

- Die regelbasierte Einzugsautomatik (`app/collection_rules.php`) besteht bewusst nur als Gerüst ohne
  Verarbeitung; sie ist kein einsatzbereites Feature.
- Der sevdesk-Adapter ist ohne Testkonto nicht verifizierbar; Basisadresse, Headerform, Endpunkte und
  Feldzuordnungen sind Annahmen aus öffentlich zugänglicher Dokumentation, nicht gegen ein echtes Konto
  geprüft.
- Es gibt keinen dokumentierten automatischen Rückschreibweg von Zahlungen nach Lexware Office; die
  Zuordnung erfolgt manuell durch das Team der jeweiligen Kundenfirma.
- Fachliche Regeln zur SEPA-Basislastschrift (Mandatsreferenz, Fristen) sind laut Kopfkommentar von
  `app/mandates.php` ein Rechercheergebnis und vor einem uneingeschränkten Produktivstart durch
  Rechtsberatung zu verifizieren.
- Der Restrisikohinweis zur Vormerkfunktion (`docs/integrations.md`): Ein Skript ohne Kopfzeilen kann
  die globale Grenze von 30 neuen Einträgen je Minute ausschöpfen (Verfügbarkeitsstörung der
  Vormerkung, kein Datenabfluss).
- Für einen dokumentierten Wiederherstellungstest im engeren Sinn (vollständiger Restore-Durchlauf mit
  Protokoll außerhalb des laufenden Coolify-Backups) liegt im durchsuchten Bestand kein eigenständiges
  Protokoll vor; `deploy/vps/backup/restore-test.sh` existiert als Werkzeug, ein dokumentiertes
  Testergebnis eines vollständigen Durchlaufs war im Rahmen dieser Dokumentation nicht auffindbar.

### 5.3 Strukturelle Risiken

- **Einzelpersonenabhängigkeit:** Nach den durchsuchten Unterlagen liegt die operative Steuerung von
  Entwicklung, Deployment-Freigabe und Serverzugängen bei einer einzelnen Person (Timo Müller). Eine
  dokumentierte Vertretungsregelung oder ein zweiter Zugangsinhaber ist im Repository nicht
  ersichtlich: Nachweis fehlt (Prüffrage).
- **Keine Hochverfügbarkeit:** Laut `docs/vps/01-architektur.md` trägt ein einzelner Server Anwendung,
  Datenbank, Redis und die Coolify-Verwaltung zugleich; ein Ausfall dieses Servers legt die gesamte
  Anwendung lahm, ein automatischer Failover auf einen zweiten Server ist nicht vorgesehen.
- **Abhängigkeit vom Lexware-API-Tarif:** Der lesende Zugriff auf Lexware Office setzt nach Angaben von
  Lexware einen bestimmten Tarif (XL) für die Public API voraus; eine Änderung dieser Tarifbedingungen
  durch Lexware selbst läge außerhalb der Kontrolle der Betreiberin.
- **Abhängigkeit von Stripe:** Die gesamte Zahlungsabwicklung (SEPA-Lastschrift je Firma, getrennt die
  Plattform-Abrechnung) hängt an Stripe als einzigem Zahlungsdienstleister; eine Kontosperrung oder
  Bedingungsänderung bei Stripe würde die Kernfunktion unmittelbar betreffen.
- **Übergangszustand der Infrastruktur:** Solange der formale Cutover (DNS-Nachweis, Abschaltung der Altinstanz) nicht bestätigt ist
  (siehe Abschnitt 3.2), bleibt die produktive Anwendung auf dem älteren IONOS-Webhosting-Modell
  (Cron statt Warteschlange) angewiesen, während gleichzeitig Entwicklungsaufwand in die parallele
  VPS-Umgebung fließt. Dieser Doppelbetrieb ist zeitlich zu begrenzen, sonst drohen divergierende
  Konfigurationsstände.
- **Manuelle Betriebsaufgaben:** Mehrere im Repository beschriebene Schritte (Setzen von
  Freigabeschaltern, Aufheben der Vier-Wochen-Sperre beim Buchhaltungswechsel, Prüfung der
  Hostinger-Firewall, Entscheidungen zur Sperre von Firmen ohne AVV-Zustimmung) sind ausdrücklich als
  offene, manuell zu treffende Entscheidungen dokumentiert und nicht automatisiert.
- **Rechtliche Prüfung ausstehend:** Sowohl die Entwurfstexte der Rechtsdokumente als auch der
  Markenname SmartEinzug warten laut Dokumentation auf eine anwaltliche beziehungsweise
  markenrechtliche Prüfung.

---

## 6. Kennzahlen und Kosten

### 6.1 Kennzahlen

Im durchsuchten Projektbestand liegen keine belastbaren Umsatz- oder Kundenzahlen vor. Das
Repository enthält Code zur Erhebung solcher Kennzahlen (Adminbereich, `funnel_events`,
Kennzahlen je Akquisitionsquelle in `admin.php`, Systemmonitoring in `admin-system.php`), aber keine
im Repository gespeicherten Auswertungsergebnisse mit tatsächlichen Zahlen. Für belastbare Kennzahlen
sind folgende Quellen außerhalb dieses Repositorys heranzuziehen:

| Kennzahl | Quelle |
|---|---|
| Anzahl registrierter Firmenaccounts, Rollen, Aktivität je Akquisitionsquelle | Adminbereich der Anwendung (`admin.php`), Tabellen `organizations`, `funnel_events` |
| Anzahl und Volumen tatsächlich eingereichter SEPA-Lastschriften | Stripe-Dashboard der jeweiligen Firma beziehungsweise, plattformweit aggregiert, nur über eine eigens zu erstellende Auswertung der Tabelle `payment_collections` |
| Umsatz aus dem Abonnement der Firmen (Plattform-Abrechnung) | Stripe-Dashboard des Plattform-Kontos der Müller Holding AG, Buchhaltung der Müller Holding AG |
| Support-Aufkommen | Tabelle `support_tickets` beziehungsweise `admin-support.php` |
| Interesse an der sevdesk-Anbindung | Adminbereich, Karte „Vormerkungen für angekündigte Integrationen" |

### 6.2 Kosten der Infrastruktur

Auch hier liegen im Repository keine belegten Beträge vor; genannt werden können ausschließlich die
Tarifbezeichnungen, aus denen sich Kosten ergeben:

| Kostenposition | Tarif/Bezeichnung laut Dokumentation |
|---|---|
| VPS-Hosting (Zielinfrastruktur) | Hostinger, Tarif KVM 8 (8 vCPU, 32 GB RAM, 400 GB NVMe) |
| Webhosting (aktuell produktiv) | IONOS Webhosting (konkreter Tarifname im Repository nicht genannt) |
| Zahlungsabwicklung | Stripe-Gebühren je Transaktion (üblich prozentual plus fixer Betrag je Zahlung; konkrete, mit Stripe vereinbarte Konditionen nicht im Repository dokumentiert) |
| Externe Datensicherung | Hetzner Object Storage (konkreter Tarif nicht im Repository dokumentiert) |
| E-Mail-Versand | über bestehendes IONOS-Postfach, keine gesonderte Kostenangabe im Repository |

Für belastbare Kostenangaben sind die jeweiligen Vertragsunterlagen und Rechnungen bei IONOS,
Hostinger, Stripe und Hetzner sowie die Buchhaltung der Müller Holding AG heranzuziehen.

---

## 7. Roadmap

Grundlage: `docs/sevdesk.md`, `docs/integrations.md` sowie die offenen Punkte in
`docs/ARBEITSSTAND.md`. Alle folgenden Punkte sind Planung, keine Zusage mit festem Termin.

### 7.1 sevdesk-Anbindung (Phase 2)

Öffentlich wird ausschließlich die Formulierung „in Vorbereitung, Start für Ende September 2026
geplant" verwendet, niemals als Zusage; das Datum ist ausdrücklich kein Freigabekriterium. Bis zur
Freigabe gilt:

1. Beschaffung eines sevdesk-Testkontos mit API-Zugang (laut sevdesk-Hilfe voraussichtlich im Tarif
   Buchhaltung Pro, Systemversion 2.0) durch den Betreiber; dies ist der derzeitige Blocker für jede
   weitere technische Umsetzung.
2. Verifikation von Basisadresse, Authentifizierung, Endpunkten und Feldern des Adapters
   `SevdeskSource` gegen das Testkonto und die offizielle sevdesk-Dokumentation.
3. Vollständige Implementierung aller Pflichtfähigkeiten der Adaptergrenze `InvoiceSource`,
   insbesondere Erkennung des offenen Restbetrags bei Teilzahlungen und Statusänderungen.
4. Rechtliche Prüfung der um sevdesk erweiterten Texte (AGB, Datenschutz, Markenhinweise).
5. Geschlossener Test mit mindestens einer Firma im Stripe-Testmodus über eine volle
   Vierwochenperiode ohne Fehleinzug.
6. Freigabe durch die Geschäftsführung der Müller Holding AG; erst danach Übergang des
   Freigabeschalters von Vormerkung auf Einrichtung.

### 7.2 Weitere Tarife

Laut `docs/ARBEITSSTAND.md` sind zwei bis drei Tarife insgesamt geplant; zum Stand dieser
Dokumentation ist ausschließlich der Tarif UNLIMITED START angelegt (siehe Abschnitt 2.9). Weitere
Tarife entstehen ausschließlich als zusätzliche Zeilen der Tabelle `plans`, ohne Codeänderung.

### 7.3 Offene Adminfunktionen

- Adminaktion zum Aufheben der Vier-Wochen-Sperre beim Wechsel des Buchhaltungssystems (bislang nur
  über einen direkten Datenbankzugriff möglich).
- Verbindungsseite für sevdesk nach Freigabe des Adapters (Analogon zur bestehenden Einstellungsseite
  für Lexware Office).
- Versandwerkzeug für die Startnachricht an bestätigte, nicht gesperrte Vormerkungen.

### 7.4 Produktions-Cutover auf den Hostinger-VPS

Vollständiger, in zwölf Phasen dokumentierter Ablauf mit Prüfpunkten und Rückfallplan in
`docs/vps/07-cutover-checkliste.md`. Dies ist kein Zusatzprodukt, sondern der anstehende Wechsel der
produktiven Infrastruktur für die bestehende Anwendung (siehe Abschnitt 3.2).

---

## 8. Übergabecheckliste

### 8.1 Vorhandene Unterlagen (im Repository)

- Fachliche und technische Dokumentation (`docs/`, `docs/vps/`), Arbeitsstand mit Entscheidungen und
  offenen Punkten (`docs/ARBEITSSTAND.md`).
- Drei PDF-Anlagen zu Betrieb/VPS-Migration, Buchhaltungssystem-Wechsel und dem sevdesk-Konzept
  (`docs/anlagen/`).
- Vollständige Commit-Historie mit Änderungsverlauf je Version (`php-ionos/app/version.php`).
- Automatisierte Prüfwerkzeuge für nahezu jeden Funktionsbereich (`tools/`), die als lebende
  Abnahmedokumentation dienen können.
- Markensteckbrief (`docs/smarteinzug-brand.md`) und eingebundene Markenassets.

### 8.2 Offene Punkte vor einer Übernahme

- Anwaltliche Prüfung der Rechtsdokumente (Auftragsverarbeitungsvertrag, Verschwiegenheitsvereinbarung)
  und Vervollständigung der Anlage 3 (Hostinganbieter, Serverstandort).
- Markenrechtliche Prüfung des Namens SmartEinzug.
- Klärung der tatsächlichen Registrierung und Kontoinhaberschaft aller genannten Domains.
- Entscheidung und Abschluss des Produktions-Cutovers auf den Hostinger-VPS (siehe Abschnitt 3.2 und
  7.4) oder bewusste Entscheidung, das Webhosting fortzuführen.
- Klärung der Vollständigkeit der Impressumsangaben für die DETM-Leadseiten (Lizenznummer,
  Vertretung, E-Mail, Telefon fehlen laut `php-ionos/app/version.php`, Eintrag 4.32).
- Beschaffung eines sevdesk-Testkontos, sofern die Roadmap weiterverfolgt werden soll.
- Klärung der Einzelpersonenabhängigkeit (Vertretungsregelung, zweiter Zugangsinhaber).
- Dokumentierter, vollständiger Wiederherstellungstest der Datenbanksicherung über den bestehenden
  Coolify-Restore hinaus, sofern dieser noch nicht vorliegt.

### 8.3 Erforderliche Zugangsübertragungen (Bezeichnungen, keine Zugangsdaten)

- Domainverwaltung sämtlicher unter Abschnitt 4.1 genannter Domains.
- GitHub-Organisation beziehungsweise Repository `v3ni94/LexofficXSTRIPE`, inklusive der für den
  Deployment-Workflow hinterlegten Secrets und Variablen.
- Hostingkonto IONOS (Webhosting, E-Mail-Postfächer, SMTP-Zugang).
- Hostingkonto Hostinger und Zugang zur Coolify-Oberfläche des VPS.
- Stripe-Konto der Müller Holding AG (Plattform-Abrechnung) sowie Kenntnis, dass die Stripe-Konten der
  einzelnen Kundenfirmen jeweils diesen selbst gehören und nicht mitübertragen werden.
- Zugang zum externen Sicherungsziel bei Hetzner Object Storage.
- Zugriff auf das Markenassets-Paket (Logo-Dateien) außerhalb dieses Repositorys.

---

## 9. Zusammenfassung: welche Angaben fehlen und woher sie kommen müssten

| Fehlende Angabe | Quelle, die sie liefern müsste |
|---|---|
| Anzahl aktiver Firmenaccounts, Nutzer, tatsächlich eingereichte Lastschriften | Adminbereich der Anwendung, Datenbankauswertung |
| Umsatz aus dem Firmenabonnement und aus Transaktionsgebühren | Stripe-Dashboard (Plattform-Konto der Müller Holding AG), Buchhaltung |
| Tatsächliche Infrastrukturkosten in Euro (Hostinger, IONOS, Hetzner, Stripe-Gebührensätze) | Vertragsunterlagen und Rechnungen der jeweiligen Anbieter |
| Registrierung und Kontoinhaberschaft der Domains | Registrar (vermutlich IONOS), Domain-Kontoinhaber |
| Bestätigte Zustellung der Transaktions-E-Mails im Kundenpostfach | Betreiber, nach Abschluss der in `docs/mail-einrichtung.md` beschriebenen Prüfung |
| Anwaltliches Prüfergebnis der Rechtsdokumente (AVV, Verschwiegenheitsvereinbarung) | beauftragter Rechtsanwalt |
| Markenrechtliche Prüfung des Namens SmartEinzug | beauftragter Rechtsanwalt (Markenrecht) |
| sevdesk-Testkonto und daraus folgende Verifikation des Adapters | Betreiber (Kontobeschaffung), anschließend Entwicklung |
| Genaue Lizenzbedingungen der eingesetzten Versionen von PHP, MariaDB, Redis, Caddy und Coolify | Versionsprüfung auf dem jeweiligen Server, Lizenztexte der Projekte |
| Vollständige Impressumsangaben der DETM-Leadseiten | Betreiber beziehungsweise DETM Management Consulting FZCO |
| Zeitpunkt und Ergebnis des Produktions-Cutovers auf den Hostinger-VPS | Betreiber, nach Abarbeitung von `docs/vps/07-cutover-checkliste.md` |
| Zuständigkeiten über die Person Timo Müller hinaus (Vertretung, weitere Entwickler, Rechtsberatung) | Betreiber, gegebenenfalls Organigramm der Müller Holding AG |

Diese Dokumentation beruht ausschließlich auf dem Stand des Repositorys `v3ni94/LexofficXSTRIPE` am
07.09.2026 und ersetzt keine eigene technische, rechtliche oder betriebswirtschaftliche Prüfung durch
einen Kaufinteressenten oder externen Prüfer.
