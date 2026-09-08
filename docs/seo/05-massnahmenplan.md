# Maßnahmenplan: Eingriffe mit Freigabevorbehalt

Stand: 07.09.2026. Dieser Plan bündelt alle Maßnahmen, die der Masterprompt ausdrücklich von einer Freigabe der Geschäftsführung abhängig macht (Abschnitt 19): Zusammenführungen, URL-Wechsel, Änderungen an der Indexierung, Vertragstexte, Preisstrategie, Domainstrategie. Nichts davon ist umgesetzt. Jede Maßnahme nennt betroffene Seiten, Begründung, Risiko, Rückfallmöglichkeit und den Datenbedarf vor der Entscheidung. Ergänzt wird der Plan nach Abschluss der Überschneidungsanalyse (`04-keyword-map.md`).

Status je Maßnahme: **empfohlen** (Vorschlag), **freigegeben** (Entscheidung liegt vor), **umgesetzt**, **verworfen**.

## M1 Preisdarstellung auf den Marketingseiten (Vorgabe vom 07.09.2026, in Umsetzung)

- Stand: Betreibervorgabe, Umsetzung in Phase 2 dieses Pakets. Die Marketingseiten nennen keine Preisbeträge mehr; JSON-LD-Angebote (`Offer.price`) entfallen; die Preisseiten erklären Tarifmodell, Abrechnungsperiode, Kündigung und verweisen auf die Konditionen im Registrierungs- und Bestellprozess der Anwendung (`register.php` zeigt Tarif und Preis, `subscription.php` zeigt eine Bestellübersicht mit Preis, Laufzeit und Kündigungsregel vor der Weiterleitung zu Stripe).
- Offen für die Geschäftsführung: (a) Freigabe einer künftigen Preisdarstellung (welcher Betrag, welche Aktionslogik), (b) die AGB-Seiten aller drei Domains nennen den Einführungspreis 25,00 EUR und den bisherigen Preis 50,00 EUR im Vertragstext (`agb`). Änderung nur nach anwaltlicher Prüfung, weil AGB Vertragsbestandteil sind. `tools/pricing-check.php` meldet die AGB als Hinweis, nicht als Fehler.
- Risiko: Interessenten erfahren den Preis erst im Buchungsprozess; Absprungrate dort beobachten (Trichter im Adminbereich, Ereignisse registration_started und subscription_active).
- Rückfall: Preisbausteine sind in Git erhalten (Stand abd5d26) und lassen sich wiederherstellen; Abschnitt D der Preisprüfung wird dann angepasst.

## M2 lastschrift-einfach.de (geklärt am 07.09.2026)

- Auskunft des Betreibers: Die Domain ist nur eine Weiterleitung auf smart-abrechnen.de. Sie gehört nicht zum SEO-Geltungsbereich dieses Pakets und wird in Inventar und Keyword-Map nicht mehr geführt.
- Befund im Repository: Der Ordner `websites/lastschrift-einfach.de` enthält eine vollständige Inhaltsseite (acht indexierbare Seiten) und wird vom Job `deploy-webhosting` mit hochgeladen; `docs/seo-url-map.csv` nennt als Ziel smart-einzug.de. Beides passt nicht zur tatsächlichen Nutzung.
- Empfehlung (Umsetzung durch das Backend-Team, weil `deploy.yml` betroffen ist): Ordner aus dem Upload nehmen oder auf eine `.htaccess` mit 301 auf smart-abrechnen.de reduzieren, Domain aus `tools/site-qa.py`, `tools/asset-version.py`, `tools/build-sitemaps.py`, `tools/sync-chrome.py` und `signup_domains` entfernen. Bis dahin unschädlich, solange die Weiterleitung beim Hoster greift.

## M3 Leadseiten unter DETM Management Consulting FZCO (entschieden am 07.09.2026)

- Entscheidung des Betreibers: lexoffice-einzug.de und lexware-einzug.de sind Leadseiten der DETM Management Consulting FZCO; die Domaininhaberschaft liegt bei DETM. DETM tritt als Anbieter der Leadseiten auf und rechnet mit der Müller Holding AG ab. Damit richten sich Beanstandungen zu den Leadseiten an DETM und nicht an die Müller Holding AG. Vertragspartner für die Software SmartEinzug bleibt die Müller Holding AG (Registrierung auf app.smart-einzug.de).
- Umsetzung in diesem Paket: SmartEinzug-Logo aus Kopf- und Fußzeile beider Domains entfernt und durch eine Textwortmarke der Domain ersetzt; Farben, Schrift und Gestaltung bleiben. Open-Graph-Bilder ohne SmartEinzug-Logo neu erzeugt. Impressum: Anbieter DETM Management Consulting FZCO mit Hinweis „Ein Service der DETM Management Consulting FZCO. DETM betreibt diese Leadseite als Anbieter und rechnet mit der Müller Holding AG ab. Anbieter der Software SmartEinzug ist die Müller Holding AG.“ Datenschutz: Verantwortlicher für die Webseite ist DETM; für die Anwendung bleibt die Müller Holding AG verantwortlich (Verweis auf smart-einzug.de/datenschutz/). Fußzeile: „Ein Service der DETM Management Consulting FZCO. SmartEinzug ist ein Produkt der Müller Holding AG.“ JSON-LD: Herausgeber der Website DETM, Software SmartEinzug mit Anbieter Müller Holding AG.
- Offene Angaben bleiben leer und werden nicht erfunden: Anschrift, Registerangaben, vertretungsberechtigte Person, E-Mail, Telefon der DETM Management Consulting FZCO. Die Felder sind als „wird ergänzt“ gekennzeichnet. Vor einer Veröffentlichung (Merge in den Backend-Branch) müssen die Pflichtangaben vollständig sein; ein unvollständiges Impressum ist abmahnfähig. Rechtliche Prüfung empfohlen: Impressumspflicht für einen Anbieter mit Sitz außerhalb der EU (Zustellungsfähigkeit, Vertretung), Datenschutzverantwortlichkeit und Auftragsverarbeitung zwischen DETM und Müller Holding AG (Trichterdaten, Google Analytics), AGB-Verweis auf den Softwareanbieter.
- AGB-Seiten der Leadseiten: Die AGB regeln die Nutzung der Software SmartEinzug (Müller Holding AG). Sie bleiben als Information erhalten, werden aber ausdrücklich als AGB des Softwareanbieters gekennzeichnet; maßgeblich ist die Fassung auf smart-einzug.de/agb/ und im Bestellprozess.

## M4 Zusammenführungen und Verlagerungen (Kandidaten aus der Keyword-Map, Freigabe nötig)

- Grundsatz: Eine Suchintention, eine bevorzugte organische Zielseite über alle Domains. Ohne Search-Console-Daten wird nichts verschoben oder weitergeleitet; die Kandidaten stehen in `04-keyword-map.md` mit Konfliktart und Empfehlung.
- Kandidatengruppe A, Spiegelseiten der Leaddomains (C01, C02, C03, C05, C15): Je Paar lexware-einzug.de gegenüber lexoffice-einzug.de nach 16 Wochen Search-Console-Daten entscheiden: die schwächere Seite auf die stärkere per 301 leiten, oder die lexoffice-Seite auf die Umbenennung fokussieren und alles Produktbezogene der lexware-Seite überlassen. Innerhalb von lexware-einzug.de die drei Keyword-Seiten zu „Lexware Office Lastschrift“ auf eine Seite zusammenführen.
- Kandidatengruppe B, dauerhafte Inhalte auf Leaddomains (C06, C11, C16 bis C20, C22, C23): Anleitungen, Sicherheit und Wissensartikel liegen auf lexware-einzug.de und lexoffice-einzug.de. Ziel laut Masterprompt: Hauptdomain. Vorgehen: neue Seiten unter `smart-einzug.de/anleitungen/`, `/wissen/`, `/sicherheit/` erstellen, danach die Leadseiten-Fassungen per 301 leiten (nicht parallel betreiben). Erst nach Rankingprüfung; bis dahin bleiben die bestehenden Seiten unverändert und werden nur inhaltlich bereinigt.
- Kandidatengruppe C, Hauptdomain intern (C07/C08): so-funktionierts als Einrichtungsseite ausbauen, hilfe auf Bestandsnutzer zuschneiden; keine Weiterleitung nötig.
- Rückfall: Weiterleitungen in `.htaccess` sind jederzeit rücknehmbar; Inhalte bleiben in Git.

## M5 Indexierungsänderungen an bestehenden Seiten

- Kampagnenseiten (`lp/`) und Vergleichsseite sind bereits `noindex,follow`. Keine weiteren Ausschlüsse ohne Rankingdaten. Neue reine Anzeigenvarianten erhalten standardmäßig `noindex,follow` und werden in der Keyword-Map mit Rolle „kampagne“ geführt.

## M6 Domainübergreifende Messung

- Siehe `06-mess-und-pflegekonzept.md`, Abschnitt 2. Entscheidung: gemeinsame GA4-Property mit Cross-Domain-Linker oder Verzicht zugunsten der eigenen cookielosen Zählung. Backend-Abstimmung nötig.

## M7 PDF-Dokumente für Interessenten (Infoblatt, Bedienungsanleitung)

- Vorgabe des Betreibers vom 07.09.2026 für alle PDFs im CI der Müller Holding AG: Logo mindestens klein auf jeder Seite, auf dem Deckblatt mittelgroß bis groß und gerne mittig, immer ein Abschlussblatt. Diese Regel ergänzt den Skill `mhag-ci` (der für Folgeseiten bisher eine schlanke Kopfzeile ohne Logo vorsieht) und hat Vorrang.
- Derzeit gibt es im Frontend keine PDFs. Ein Infoblatt zur Information vor Vertragsschluss ist erst sinnvoll, wenn die Preisdarstellung (M1) freigegeben ist, weil vorvertragliche Information ohne Konditionen unvollständig wäre. Eine Bedienungsanleitung als PDF sollte aus den Anleitungsseiten der Hauptdomain entstehen, nicht parallel gepflegt werden.
- Empfehlung: nach Freigabe M1 ein Infoblatt (Deckblatt, Ablauf, Voraussetzungen, Konditionen, Pflichtangaben nach § 80 AktG, Abschlussblatt) über den Generator des Skills `mhag-ci` erzeugen und auf `smart-einzug.de/preise/` verlinken.

## M8 Wettbewerbervergleich

- `smart-einzug.de/vergleich/sepaheld-gocardless/` ist `noindex`, trägt „(Entwurf)“ im Titel und ist laut `docs/ads-conversions.md` nur nach Freigabe zu bewerben. Keine Änderung; Freigabe der Geschäftsführung und rechtliche Prüfung vergleichender Werbung vor Veröffentlichung.

## M9 sevdesk-Leaddomains sevdesk-einzug.de und sevdesk-sepa.de (Auftrag vom 07.09.2026, umgesetzt im Repository)

- Auftrag des Betreibers: zwei eigenständige Landingpages mit unterschiedlichem Inhalt, keine Doppelung zu lexoffice-einzug.de und lexware-einzug.de, Domains werden vom Betreiber gekauft. Anbieter der Leadseiten: DETM Management Consulting FZCO (wie M3), Vormerkung durch die Müller Holding AG.
- Trennung der Suchintentionen: `sevdesk-einzug.de` bedient die transaktionale Absicht „sevdesk-Rechnungen per Lastschrift einziehen, Anbindung vormerken“ (Vormerkformular, geplanter Ablauf, Voraussetzungen, Fragen). `sevdesk-sepa.de` bedient die informative Absicht „SEPA-Lastschrift als sevdesk-Nutzer vorbereiten“ (Basis- und Firmenlastschrift, Mandat, Gläubiger-ID, Vorabankündigung, Rücklastschrift, Checkliste) ohne Formular, mit Verweis auf die Vormerkung. Die Detailseite `smart-einzug.de/integrationen/sevdesk/` bleibt die ausführliche Produktseite; alle drei verlinken aufeinander. Textähnlichkeit laut `site-qa.py` unter 10 Prozent zu allen bestehenden Seiten.
- Regeln eingehalten: sevdesk nur „in Vorbereitung, Start für Ende September 2026 geplant“, kein Preis, kein Kaufbutton, Tarifformulierung nach der offiziellen sevdesk-Hilfe, SEPA-Basislastschrift (Core), keine Partnerschaftsbehauptung, keine Zusage einer Rückschreibung nach sevdesk, keine Aussagen über Funktionen von sevdesk selbst.
- Voraussetzungen vor Inbetriebnahme (Backend und Betreiber): `signup_domains` in `shared/config.php` um beide Domains ergänzen (sonst lehnt `vormerken.php` das Formular ab und `track.php` die Zählung), Domains bei IONOS anlegen und dem Job `deploy-webhosting` zuordnen (Zeilen in `deploy.yml` ergänzt), Impressumsangaben der DETM nachtragen, `config.example.php` bereits angepasst. Google-Analytics-Kennungen sind nicht hinterlegt; ohne Kennung lädt `site.js` kein Google-Skript und zeigt kein Banner.
- Risiko: Fünf Domains für ein Angebot erhöhen die Nähe zu Doorway-Mustern. Vertretbar nur mit klar getrennten Inhalten, wie hier umgesetzt; keine weiteren Domains ohne eigenen Zweck. Rankingdaten je Domain nach Inbetriebnahme über die Search Console prüfen.
