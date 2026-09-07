# Rechtsdokumente mit Zustimmungsnachweis (AVV, Verschwiegenheit)

Stand: 07.09.2026, Version 4.30. Betreiberin der Anwendung ist die Müller Holding AG; sie verarbeitet die Rechnungs- und Kundendaten der Firmen in deren Auftrag. Dafür braucht jede Firma eine Vereinbarung zur Auftragsverarbeitung nach Art. 28 DSGVO. Firmen, die einer beruflichen Verschwiegenheitspflicht unterliegen (§ 203 StGB, zum Beispiel Steuerberater, Rechtsanwälte, Ärzte), müssen den Dienstleister zusätzlich zur Verschwiegenheit verpflichten. Beides bildet die Anwendung ab, wie es Nutzer von Lexware Office kennen („Akzeptiert am ... durch ...“).

## Bausteine

| Baustein | Datei | Zweck |
|---|---|---|
| Tabellen `legal_documents`, `legal_acceptances`, Spalten `organizations.professional_secrecy(_kind)` | `sql/migrations/023_legal_documents.sql` | versionierte Texte, Nachweis je Firma und Fassung, Angabe zur Verschwiegenheitspflicht |
| Bibliothek | `app/legal.php` | aktive Fassungen, Pflichtenlage je Firma, Zustimmung (nur Inhaber/Admin, nur veröffentlichte Fassung, idempotent, Audit), Verwaltung, Renderer |
| Entwurfstexte | `app/legal_drafts.php` | AVV mit Anlagen 1 bis 3 und Verschwiegenheitsvereinbarung als Vorlagen für die anwaltliche Prüfung |
| Firmenseite | `rechtliches.php` | Menü Profil, Rechtliches: Status je Dokument, Volltext, Akzeptieren, Verschwiegenheitspflicht angeben, Nachweise |
| Registrierung | `register.php` | AVV-Checkbox nur, wenn eine Fassung veröffentlicht ist; Nachweis mit Weg „registration“ |
| Dashboard | `dashboard.php` | Hinweis, solange ein Pflichtdokument in der aktuellen Fassung nicht akzeptiert ist |
| Adminseite | `admin-legal.php` | Vorlagen übernehmen, neue Fassung anlegen, veröffentlichen, zurückziehen (2FA, Audit), Zähler fehlender Zustimmungen |
| Prüfung | `tools/legal-check.sh` | statische Prüfungen und Prüffälle gegen eine temporäre MariaDB |

## Ablauf bis zur Wirksamkeit

1. Deployment mit Migration 023.
2. Adminbereich, Rechtsdokumente: Vorlage „Vereinbarung zur Auftragsverarbeitung“ übernehmen. Es entsteht eine unveröffentlichte Fassung.
3. Text anwaltlich prüfen lassen. Anlage 3 enthält Platzhalter (Hostinganbieter mit Serverstandort, Sicherungsspeicher); solange der Text `[Platzhalter` enthält, ist Veröffentlichen gesperrt. Änderungen werden als neue Fassung angelegt (Formular „Neue Fassung anlegen“, vorbelegt aus der geöffneten Fassung).
4. Veröffentlichen mit 2FA-Code. Ab jetzt: Registrierung verlangt den AVV, bestehende Firmen sehen im Dashboard den Hinweis und akzeptieren unter Rechtliches. Die Anwendung sperrt nichts; ob Firmen ohne Zustimmung gesperrt werden sollen, ist eine Entscheidung der Geschäftsführung (nicht umgesetzt).
5. Verschwiegenheitsvereinbarung ebenso; sie gilt nur für Firmen, die unter Rechtliches die Verschwiegenheitspflicht angegeben haben.

Neue Fassung eines Dokuments: Veröffentlichen zieht die bisherige Fassung zurück; alle Firmen müssen erneut zustimmen, alte Nachweise bleiben erhalten und sichtbar. Veröffentlichte Fassungen werden nie gelöscht (Nachweispflicht).

## Anlage 1 des AVV: Herkunft der Angaben

Die Datenanlage wurde am 07.09.2026 aus dem Code erhoben, nicht aus Annahmen: Lexware Office (`app/lexoffice.php`, `app/sync.php`, `app/collections.php`): nur lesende Abrufe von Profil, Belegliste, Rechnungsdetail, Kontakt und Zahlungsstand; gespeichert werden Rechnungsnummer, Empfängername, Bruttobetrag, Währung, Fälligkeit, Status, Positionen, Kontaktname, Kundennummer, eine E-Mail-Adresse, offener Restbetrag. Anschriften, Telefonnummern, Steuernummern werden nicht abgerufen. Stripe im Firmenkonto (`app/stripe.php`, `app/collections.php`, `app/mandate_requests.php`, `stripe-webhook.php`): gesendet werden Kundenname, E-Mail, Kennungen, bei im Portal erfasster IBAN die IBAN mit Kontoinhaber, je Lastschrift Betrag und Verwendungszweck; empfangen werden Objektkennungen, Status, Fehlergrund, Erstattungen, maskierte IBAN. Plattform-Abrechnung (`app/billing.php`): Firmenname und E-Mail des Inhabers. Eigene Erhebung (`app/auth.php`, `app/audit.php`, `app/devices.php`, `app/profile.php`, `app/support_tickets.php`, `track.php`). Ändert sich eine Schnittstelle, ist Anlage 1 als neue Fassung nachzuziehen.

Auffälligkeiten aus der Inventur, für die Rechtsprüfung relevant: Fehlt einem Kunden die E-Mail-Adresse, übergibt die Anwendung Stripe eine technische Platzhalteradresse (`_fallback_contact_email()`); das Protokoll (`audit_log`) speichert IP-Adressen; `login_attempts`, `funnel_events`, Supporttickets und technische Laufprotokolle haben keine automatische Löschfrist.

## Protokoll (Audit) und Aufbewahrung

Seit 4.30 bewahrt die Anwendung Protokolleinträge 90 Tage auf (`AUDIT_RETENTION_DAYS`, überschreibbar mit `audit.retention_days` in der Konfiguration, Mindestwert 30). Die Wartung löscht ältere Einträge (`audit_cleanup()` in `job_maintenance` und `cron.php`). Fachliche Nachweise sind davon unabhängig: Einzüge (`payment_collections`), Mandate (`sepa_mandates`) und Vertragszustimmungen (`legal_acceptances`) bleiben erhalten. Die Firmenseite zeigt die letzten 20 Einträge, weitere aufklappbar (bis 200), Export als CSV für den Inhaber (`export.php?typ=protokoll`, selbst protokolliert).

## Offene Entscheidungen

- Anwaltliche Prüfung der Entwurfstexte, insbesondere Ziffern 9 (Meldefrist 48 Stunden), 10 (Aufbewahrung), 11 (Kontrollen) und Anlage 3.
- Angaben zu Hostinganbieter (Firma, Anschrift, Serverstandort) und Sicherungsspeicher für Anlage 3.
- Sperre von Firmen ohne AVV-Zustimmung nach Übergangsfrist: ja oder nein.
- Umgang mit der Platzhalteradresse für Kunden ohne E-Mail bei Stripe.
