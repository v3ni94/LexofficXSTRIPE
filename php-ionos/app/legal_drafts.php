<?php
/**
 * Entwurfstexte der Rechtsdokumente (Vorlagen fuer app/legal.php).
 *
 * Diese Texte sind ENTWUERFE fuer die anwaltliche Pruefung. Sie werden im Adminbereich (admin-legal.php) als
 * unveroeffentlichte Fassung uebernommen, dort geprueft und ggf. angepasst und erst dann veroeffentlicht.
 * Veroeffentlichen ist gesperrt, solange der Text "[Platzhalter" enthaelt.
 *
 * Die Datenanlage (Anlage 1) ist aus dem Code abgeleitet (Inventur vom 07.09.2026: app/lexoffice.php, app/sync.php,
 * app/stripe.php, app/collections.php, app/mandate_requests.php, app/billing.php, app/auth.php, app/audit.php,
 * app/devices.php, app/profile.php, app/support_tickets.php, track.php, sql/schema.sql). Aendert sich die
 * Verarbeitung, ist die Anlage als neue Fassung nachzuziehen.
 *
 * Format body_md: eingeschraenktes Markdown (siehe legal_render_md()).
 */
declare(strict_types=1);

function legal_drafts(): array
{
    return [
        [
            'code' => 'avv',
            'version' => '2026-09-entwurf-1',
            'title' => 'Vereinbarung zur Auftragsverarbeitung (Art. 28 DSGVO)',
            'summary' => 'Regelt die Verarbeitung der Rechnungs- und Kundendaten Ihrer Firma durch die Müller Holding AG als Auftragsverarbeiterin beim Betrieb von SmartEinzug. Anlage 1 nennt jedes verarbeitete Datenfeld und seine Herkunft.',
            'required_for' => 'all',
            'body_md' => legal_draft_avv(),
        ],
        [
            'code' => 'secrecy',
            'version' => '2026-09-entwurf-1',
            'title' => 'Verpflichtung zur Verschwiegenheit für Berufsgeheimnisträger (§ 203 StGB)',
            'summary' => 'Für Firmen, die einer beruflichen Verschwiegenheitspflicht unterliegen (zum Beispiel Rechtsanwälte, Steuerberater, Wirtschaftsprüfer, Notare, Ärzte, Apotheker): Verpflichtung der Müller Holding AG und ihrer mitwirkenden Personen zur Geheimhaltung.',
            'required_for' => 'secrecy',
            'body_md' => legal_draft_secrecy(),
        ],
    ];
}

function legal_draft_avv(): string
{
    return <<<'MD'
Zwischen dem Kunden (nachfolgend Verantwortlicher), vertreten durch die Person, die dieses Dokument im Firmenaccount akzeptiert, und der Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein, Sitz Monheim am Rhein, Registergericht Amtsgericht Düsseldorf, HRB 104291, Vorstand Timo Müller (nachfolgend Auftragsverarbeiterin), wird folgende Vereinbarung zur Auftragsverarbeitung nach Art. 28 der Datenschutz-Grundverordnung (DSGVO) geschlossen.

# 1. Gegenstand und Dauer

Die Auftragsverarbeiterin betreibt die Anwendung SmartEinzug. Der Verantwortliche nutzt sie, um offene Rechnungen aus seinem Buchhaltungssystem (Lexware Office, künftig gegebenenfalls weitere Systeme) mit seinen Kunden abzugleichen und SEPA-Basislastschriften über sein eigenes Stripe-Konto einzuziehen. Die Verarbeitung personenbezogener Daten durch die Auftragsverarbeiterin erfolgt ausschließlich zu diesem Zweck und im Auftrag des Verantwortlichen.

Die Vereinbarung gilt für die Dauer des Nutzungsvertrags über SmartEinzug. Sie endet mit dessen Beendigung; Ziffer 10 (Löschung und Rückgabe) bleibt darüber hinaus anwendbar.

# 2. Art und Zweck der Verarbeitung

- Abruf offener und überfälliger Rechnungen sowie der zugehörigen Kontaktdaten aus dem Buchhaltungssystem des Verantwortlichen über dessen Schnittstellenzugang (nur lesend; die Anwendung schreibt keine Daten in das Buchhaltungssystem).
- Speicherung dieser Daten zur Anzeige, Zuordnung von SEPA-Mandaten, Terminierung und Nachverfolgung von Einzügen.
- Übermittlung der für eine SEPA-Lastschrift erforderlichen Angaben an das Stripe-Konto des Verantwortlichen und Empfang der Statusmeldungen (Webhooks).
- Erzeugung und Aufbewahrung von SEPA-Mandatsdokumenten und Vorabankündigungen im Auftrag des Verantwortlichen.
- Betrieb, Sicherung, Fehleranalyse und Protokollierung, soweit für den sicheren Betrieb erforderlich.

# 3. Art der Daten und Kreis der Betroffenen

Die verarbeiteten Datenkategorien und Datenfelder sind in Anlage 1 abschließend aufgeführt. Betroffene sind die Kunden (Debitoren) des Verantwortlichen, deren Ansprechpersonen und Kontoinhaber, sowie die Benutzer des Verantwortlichen im Firmenaccount.

Für die Verarbeitung der Daten der Benutzer des Verantwortlichen (Registrierung, Kundenkonto, Zwei-Faktor-Authentifizierung, Abonnement, Sicherheits-E-Mails) ist die Auftragsverarbeiterin eigene Verantwortliche; diese Verarbeitung ist in der Datenschutzerklärung beschrieben und nicht Gegenstand dieser Vereinbarung.

# 4. Weisungen

Die Auftragsverarbeiterin verarbeitet personenbezogene Daten nur auf dokumentierte Weisung des Verantwortlichen. Weisungen erteilt der Verantwortliche durch die Nutzung der Funktionen der Anwendung (Verbindung der Systeme, Freigabe und Terminierung von Einzügen, Not-Stopp, Löschung) sowie in Textform über die Supportfunktion. Hält die Auftragsverarbeiterin eine Weisung für rechtswidrig, weist sie den Verantwortlichen unverzüglich darauf hin und darf die Ausführung bis zur Bestätigung aussetzen.

# 5. Vertraulichkeit

Die Auftragsverarbeiterin gewährleistet, dass alle mit der Verarbeitung befassten Personen zur Vertraulichkeit verpflichtet sind oder einer angemessenen gesetzlichen Verschwiegenheitspflicht unterliegen. Zugriff auf Kundendaten des Verantwortlichen erhalten nur Personen, deren Aufgabe dies erfordert; Supportzugriffe in den Firmenaccount erfolgen nur zeitlich begrenzt, mit Vermerk im Protokoll des Verantwortlichen und ohne Zugriff auf Zugangsdaten, IBAN-Änderungen und die Auslösung von Einzügen.

# 6. Technische und organisatorische Maßnahmen

Die Auftragsverarbeiterin trifft die in Anlage 2 beschriebenen technischen und organisatorischen Maßnahmen nach Art. 32 DSGVO und entwickelt sie entsprechend dem Stand der Technik weiter. Änderungen dürfen das Schutzniveau nicht unterschreiten.

# 7. Unterauftragsverarbeiter

Der Verantwortliche genehmigt die in Anlage 3 genannten Unterauftragsverarbeiter. Beabsichtigte Änderungen zeigt die Auftragsverarbeiterin dem Verantwortlichen mindestens vier Wochen vorab im Firmenaccount oder per E-Mail an; der Verantwortliche kann innerhalb dieser Frist aus wichtigem Grund widersprechen. Die Auftragsverarbeiterin legt jedem Unterauftragsverarbeiter vertraglich dieselben Datenschutzpflichten auf, die in dieser Vereinbarung festgelegt sind.

Der Zahlungsdienstleister Stripe wird für die SEPA-Einzüge nicht von der Auftragsverarbeiterin, sondern vom Verantwortlichen selbst beauftragt (eigenes Stripe-Konto, eigener Vertrag mit Stripe). Die Auftragsverarbeiterin übermittelt die dafür nötigen Daten im Auftrag des Verantwortlichen an dessen Stripe-Konto (Anlage 1, Abschnitt C).

# 8. Rechte der Betroffenen und Unterstützung

Die Auftragsverarbeiterin unterstützt den Verantwortlichen mit geeigneten technischen und organisatorischen Maßnahmen bei der Erfüllung der Betroffenenrechte (Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit). Anfragen Betroffener, die bei der Auftragsverarbeiterin eingehen, leitet sie unverzüglich an den Verantwortlichen weiter. Sie unterstützt den Verantwortlichen ferner bei der Sicherheit der Verarbeitung, bei der Meldung von Verletzungen des Schutzes personenbezogener Daten und bei Datenschutz-Folgenabschätzungen, soweit dies die ihr vorliegenden Informationen betrifft.

# 9. Meldung von Verletzungen

Die Auftragsverarbeiterin meldet dem Verantwortlichen jede ihr bekannt gewordene Verletzung des Schutzes personenbezogener Daten, die Daten des Verantwortlichen betrifft, unverzüglich, spätestens innerhalb von 48 Stunden nach Kenntnis, mit den ihr bekannten Informationen zu Art, betroffenen Daten, wahrscheinlichen Folgen und ergriffenen Maßnahmen. Die Mitteilung erfolgt an die E-Mail-Adresse des Inhabers des Firmenaccounts.

# 10. Löschung und Rückgabe

Nach Beendigung des Nutzungsvertrags löscht die Auftragsverarbeiterin die im Auftrag verarbeiteten personenbezogenen Daten, sofern keine gesetzliche Aufbewahrungspflicht besteht. SEPA-Mandate und Nachweise zu erfolgten Einzügen unterliegen den Aufbewahrungspflichten des SEPA-Regelwerks und des Handels- und Steuerrechts; sie werden bis zum Ablauf dieser Fristen gespeichert und danach gelöscht. Der Verantwortliche kann vor der Beendigung einen Export seiner Daten (CSV-Export im Firmenaccount) abrufen.

# 11. Nachweise und Kontrollen

Die Auftragsverarbeiterin stellt dem Verantwortlichen die zum Nachweis der Einhaltung dieser Vereinbarung erforderlichen Informationen zur Verfügung, insbesondere die Beschreibung der technischen und organisatorischen Maßnahmen, die Liste der Unterauftragsverarbeiter und die Protokolle im Firmenaccount. Kontrollen vor Ort erfolgen nach Terminabstimmung, während der Geschäftszeiten und ohne Störung des Betriebs; Kosten trägt der Verantwortliche, sofern die Kontrolle keine erheblichen Verstöße feststellt.

# 12. Haftung und Schlussbestimmungen

Für die Haftung gelten Art. 82 DSGVO und die Regelungen des Nutzungsvertrags. Bei Widersprüchen zwischen dieser Vereinbarung und dem Nutzungsvertrag geht in datenschutzrechtlichen Fragen diese Vereinbarung vor. Es gilt deutsches Recht. Diese Vereinbarung wird elektronisch im Firmenaccount geschlossen; der Zeitpunkt, die akzeptierende Person und die Fassung werden gespeichert und sind dem Verantwortlichen unter Rechtliches jederzeit einsehbar.

# Anlage 1: Verarbeitete Daten und ihre Herkunft

Grundsatz: Die Schnittstellen von Lexware Office und Stripe stellen erheblich mehr Daten bereit, als die Anwendung verwendet. SmartEinzug ruft ausschließlich die nachfolgend genannten Felder ab und speichert nur die als gespeichert gekennzeichneten. Schreibende Zugriffe auf das Buchhaltungssystem finden nicht statt.

## A. Abruf aus Lexware Office (nur lesend, mit dem Schnittstellenschlüssel des Verantwortlichen)

Verwendete Schnittstellen: Profil, Belegliste (nur Rechnungen mit Status offen oder überfällig), Rechnungsdetail, Kontakt, Zahlungsstand eines Belegs.

- Profil: Firmenname des Lexware-Kontos. Gespeichert (Anzeige der Verbindung).
- Belegliste: Belegkennung, Rechnungsnummer, Belegstatus, Änderungszeitpunkt. Nur zur Steuerung der Synchronisation; dauerhaft gespeichert erst über das Rechnungsdetail.
- Rechnungsdetail: Rechnungsnummer, Rechnungsempfänger (Name, Namenszusatz, Kontaktkennung), Bruttobetrag, Währung, Fälligkeitsdatum, Belegstatus, Änderungszeitpunkt, Rechnungspositionen (Bezeichnung, Beschreibung, Positionsbeträge). Gespeichert. Aus den Positionen wird ein Stichwort für die Zuordnung abgeleitet.
- Kontakt: Firmenname oder Vor- und Nachname, Kundennummer, erste hinterlegte E-Mail-Adresse (Reihenfolge geschäftlich, Büro, privat, sonstige). Gespeichert. Nicht abgerufen und nicht gespeichert werden Anschriften, Telefonnummern, Steuernummern, Bankverbindungen und alle weiteren Kontaktfelder.
- Zahlungsstand: offener Restbetrag und Währung unmittelbar vor einem Einzug. Gespeichert wird der offene Betrag mit Abrufzeitpunkt; Zahlungsstatus, Belegstatus und Zahldatum dieser Abfrage werden nicht gespeichert.
- Schnittstellenschlüssel: verschlüsselt gespeichert (AES-256-GCM), niemals protokolliert oder im Browser angezeigt.

## B. Im Firmenaccount erfasste Daten zu Kunden des Verantwortlichen

- SEPA-Mandat: Mandatsreferenz, Erteilungsdatum und Ort, Zahlungsart (wiederkehrend oder einmalig), Status, Widerrufsdatum.
- Bankverbindung: bei im Portal erfasster IBAN die vollständige IBAN und der Kontoinhabername (verschlüsselte Ablage) zur Anlage der Zahlungsmethode bei Stripe; bei digital über Stripe erteilten Mandaten nur Ländercode und letzte vier Stellen der IBAN sowie der Kontoinhabername.
- Hochgeladene Mandatsdokumente (PDF, JPG, PNG bis 10 MB): Dateiname, Typ, Größe, Prüfsumme, Notiz, hochladende Person, Zeitpunkt; die Datei selbst außerhalb des Webzugriffs, Abruf nur für Mitglieder der Firma mit Protokolleintrag.
- Erzeugtes Mandatsdokument und Vorabankündigung: Zahlungsempfänger (Firmendaten des Verantwortlichen, Gläubiger-Identifikationsnummer), Zahlungspflichtiger (Name, Kundennummer, IBAN, BIC), Mandatstexte, Unterschriftsfeld.
- Einzüge: Betrag, Währung, Rechnungsbezug, Status, Einreichungs- und Abschlusszeitpunkt, Fehlergrund, Erstattungen, Rücklastschriften.

## C. Übermittlung an das Stripe-Konto des Verantwortlichen und Empfang

Gesendet werden ausschließlich: Kundenname, Kunden-E-Mail (fehlt sie, eine technische Platzhalteradresse), Kennungen (Firmen-, Kunden- und Rechnungskennung, Kundennummer, Mandatsreferenz, Rechnungsnummer), bei im Portal erfasster IBAN die IBAN und der Kontoinhabername zur Anlage der Zahlungsmethode, für jede Lastschrift Betrag, Währung, Verwendungszweck mit Rechnungs- und Kundennummer sowie die Angabe, dass das Mandat außerhalb von Stripe erteilt wurde. Der Mandatstext selbst wird nicht an Stripe übermittelt. Bei digitaler Mandatserteilung gibt der Kunde seine IBAN direkt bei Stripe ein; die Anwendung übermittelt dafür nur Kennungen und Rücksprungadressen.

Empfangen und gespeichert werden: Kennungen der Stripe-Objekte (Kunde, Zahlungsmethode, Mandat, Zahlung, Buchung), Stripe-Mandatsreferenz, Zahlungsstatus, Fehlermeldung bei Fehlschlag, Erstattungsbeträge, Rücklastschriften, maskierte IBAN (Ländercode, letzte vier Stellen) und Kontoinhabername bei digitalen Mandaten sowie Kontokennung, Anzeigename und Modus (Test oder Live) des verbundenen Stripe-Kontos.

Stripe-Schlüssel und Webhook-Geheimnis des Verantwortlichen: verschlüsselt gespeichert (AES-256-GCM), niemals protokolliert oder im Browser angezeigt.

## D. Betriebsdaten mit Bezug zum Verantwortlichen

- Protokoll (Audit): Firma, handelnde Person (Kennung und E-Mail), Aktion, Zielobjekt, Zusatzangaben ohne Zugangsdaten, Codes oder vollständige IBAN, IP-Adresse, Zeitpunkt. Wird zu Nachweiszwecken nicht gelöscht.
- Technische Ausführungsprotokolle der Synchronisation und der Hintergrundverarbeitung, Webhook-Ereigniskennungen zur Vermeidung doppelter Verarbeitung.
- Supportanfragen aus dem Firmenaccount: Betreff, Kategorie, Seite, Nachrichtentexte, E-Mail der anfragenden Person; Nachrichten mit Zugangsdaten oder IBAN werden abgewiesen.

## E. Löschfristen im Auftrag

Rechnungs- und Kundendaten bleiben gespeichert, solange der Nutzungsvertrag besteht, und werden nach dessen Ende nach Ziffer 10 gelöscht. Vorgänge zur Registrierung und unbestätigte Vormerkungen nach 30 Tagen; widerrufene Gerätefreigaben nach 30 Tagen; technische Messwerte des Monitorings nach 14, 30 beziehungsweise 400 Tagen. SEPA-Mandate werden nicht gelöscht, sondern widerrufen und mindestens 14 Monate nach dem letzten Einzug aufbewahrt.

# Anlage 2: Technische und organisatorische Maßnahmen

- Zutritt und Zugang: Betrieb auf einem gemieteten virtuellen Server in einem Rechenzentrum des Hostinganbieters (Anlage 3) mit Zugangskontrolle durch den Anbieter; administrativer Zugang nur über SSH mit Schlüsselauthentifizierung, Firewall, Sperrung nach Fehlversuchen.
- Zugriffskontrolle in der Anwendung: persönliche Zugänge, verpflichtende Zwei-Faktor-Authentifizierung für jeden Benutzer, Rollen (Inhaber, Administrator, Mitarbeiter), Trennung von Anwendungs- und Adminhost, Zweitbestätigung mit Einmalcode für geldrelevante Aktionen.
- Mandantentrennung: jede Datenbankabfrage ist an die Firmenkennung gebunden; Dateien werden je Firma getrennt außerhalb des Webzugriffs gespeichert.
- Verschlüsselung: Transport ausschließlich über TLS; Schnittstellenschlüssel, Stripe-Geheimnisse, IBAN und Zwei-Faktor-Geheimnisse werden mit AES-256-GCM verschlüsselt gespeichert; Passwörter nur als Hash; Bestätigungs- und Einladungslinks nur als Hash.
- Protokollierung: unveränderliches Protokoll aller geldrelevanten und administrativen Aktionen mit handelnder Person und Zeitpunkt, für den Verantwortlichen im Firmenaccount einsehbar.
- Verfügbarkeit: tägliche Datenbanksicherungen in einen getrennten Objektspeicher (Anlage 3), überwachte Dienste mit Statusseite, Wiederanlaufverfahren mit Rückfallmöglichkeit auf das vorherige Release.
- Eingabekontrolle und Sicherheit der Zahlungsvorgänge: Restbetragsprüfung unmittelbar vor jedem Einzug, Einreichfenster, Not-Stopp je Firma und plattformweit, Klärungsverfahren für unklare Einzugsversuche, Ratenbegrenzung und Ausfallsicherung bei externen Schnittstellen.
- Organisation: Supportzugriffe nur zeitlich befristet mit Protokollvermerk; Grundsatz der Datenminimierung bei jeder Schnittstelle (nur benötigte Felder); Trennung der Konfiguration und Geheimnisse vom Programmcode; Änderungen am System nur über nachvollziehbare, geprüfte Auslieferungen.

# Anlage 3: Unterauftragsverarbeiter

- Hostinganbieter des Anwendungsservers (virtueller Server, Datenbank, Dateiablage): [Platzhalter: Firma, Anschrift und Serverstandort des Hostinganbieters einsetzen, z. B. Hostinger, Rechenzentrumsstandort laut Vertrag].
- Sicherungsspeicher für Datenbankbackups: [Platzhalter: Firma, Anschrift und Speicherregion des Objektspeicheranbieters einsetzen].
- E-Mail-Versand (Bestätigungs-, Sicherheits- und Systemnachrichten): IONOS SE, Elgendorfer Straße 57, 56410 Montabaur, Deutschland.

Nicht Unterauftragsverarbeiter im Sinne dieser Vereinbarung, weil vom Verantwortlichen selbst beauftragt: Haufe-Lexware GmbH & Co. KG (Lexware Office, Buchhaltungssystem des Verantwortlichen) und Stripe Payments Europe, Ltd. (Zahlungsdienstleister, eigenes Stripe-Konto des Verantwortlichen).

Für das Abonnement des Verantwortlichen bei der Auftragsverarbeiterin (Plattform-Abrechnung) nutzt die Auftragsverarbeiterin ihr eigenes Stripe-Konto; dabei werden Firmenname und die E-Mail-Adresse des Inhabers an Stripe übergeben, Rechnungsanschrift und Umsatzsteuer-Identifikationsnummer gibt der Verantwortliche direkt bei Stripe ein. Diese Verarbeitung erfolgt in eigener Verantwortung der Müller Holding AG und ist in der Datenschutzerklärung beschrieben.
MD;
}

function legal_draft_secrecy(): string
{
    return <<<'MD'
Zwischen dem Kunden (nachfolgend Geheimnisträger), der als Berufsgeheimnisträger im Sinne des § 203 Absatz 1 oder 2 des Strafgesetzbuchs (StGB) tätig ist, und der Müller Holding AG, Rheinpromenade 13, 40789 Monheim am Rhein, Registergericht Amtsgericht Düsseldorf, HRB 104291, Vorstand Timo Müller (nachfolgend Dienstleisterin), wird folgende Vereinbarung geschlossen.

# 1. Anlass

Der Geheimnisträger nutzt die Anwendung SmartEinzug der Dienstleisterin zum Abgleich offener Rechnungen und zum Einzug von SEPA-Lastschriften. Dabei kann die Dienstleisterin Zugang zu Tatsachen erhalten, die dem Geheimnisträger in seiner beruflichen Eigenschaft anvertraut oder bekannt geworden sind (insbesondere Namen, Kundennummern, Rechnungsdaten und Bankverbindungen von Mandanten oder Patienten). Nach § 203 Absatz 3 Satz 2 StGB darf der Geheimnisträger solche Geheimnisse einer mitwirkenden Person offenbaren, soweit dies für deren Tätigkeit erforderlich ist; nach § 203 Absatz 4 StGB hat er die mitwirkende Person zur Geheimhaltung zu verpflichten. Diese Vereinbarung dient dieser Verpflichtung.

# 2. Verpflichtung zur Geheimhaltung

Die Dienstleisterin verpflichtet sich, alle Geheimnisse des Geheimnisträgers, die ihr im Zusammenhang mit der Erbringung der Dienstleistung bekannt werden, geheim zu halten. Sie darf sie ausschließlich zur vertragsgemäßen Erbringung der Dienstleistung verwenden und weder unbefugt offenbaren noch verwerten. Die Verpflichtung besteht auch nach Beendigung des Vertragsverhältnisses fort.

# 3. Kenntnisnahme nur soweit erforderlich

Die Dienstleisterin nimmt Geheimnisse nur zur Kenntnis, soweit dies für den Betrieb, die Wartung, die Fehleranalyse oder die vom Geheimnisträger angeforderte Unterstützung erforderlich ist. Supportzugriffe in den Firmenaccount des Geheimnisträgers erfolgen nur zeitlich begrenzt und werden im Protokoll des Firmenaccounts vermerkt.

# 4. Mitwirkende Personen und weitere Dienstleister

Die Dienstleisterin verpflichtet alle Personen, die bei ihr mit der Dienstleistung befasst sind, in gleicher Weise schriftlich zur Geheimhaltung und weist sie auf die Strafbarkeit einer unbefugten Offenbarung nach § 203 Absatz 4 StGB hin. Zieht die Dienstleisterin weitere Dienstleister hinzu (Unterauftragsverarbeiter nach Anlage 3 der Vereinbarung zur Auftragsverarbeitung), verpflichtet sie diese entsprechend, soweit sie Zugang zu Geheimnissen erhalten können.

# 5. Belehrung

Die Dienstleisterin ist darüber belehrt, dass eine unbefugte Offenbarung fremder Geheimnisse, die ihr als mitwirkender Person bekannt geworden sind, nach § 203 Absatz 4 StGB strafbar ist, und dass sie sich ebenfalls strafbar macht, wenn sie weitere mitwirkende Personen nicht zur Geheimhaltung verpflichtet hat und diese unbefugt ein Geheimnis offenbaren.

# 6. Verhältnis zur Vereinbarung zur Auftragsverarbeitung

Diese Vereinbarung ergänzt die zwischen den Parteien geschlossene Vereinbarung zur Auftragsverarbeitung. Die dort beschriebenen technischen und organisatorischen Maßnahmen dienen zugleich dem Schutz der Geheimnisse des Geheimnisträgers.

# 7. Schlussbestimmungen

Es gilt deutsches Recht. Diese Vereinbarung wird elektronisch im Firmenaccount geschlossen; Zeitpunkt, akzeptierende Person und Fassung werden gespeichert und sind dem Geheimnisträger unter Rechtliches jederzeit einsehbar. Der Geheimnisträger bleibt für die Prüfung verantwortlich, ob die Einbindung der Dienstleisterin für seine Tätigkeit erforderlich ist.
MD;
}
