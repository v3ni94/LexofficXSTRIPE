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

## M4 Zusammenführungen und Weiterleitungen (wird nach der Überschneidungsanalyse ergänzt)

- Grundsatz: Eine Suchintention, eine bevorzugte organische Zielseite über alle Domains. Kandidaten stammen aus `keyword-map.json` (Konfliktcluster).
- Vorgehen je Kandidat: Search-Console-Daten der beteiligten URLs (Klicks, Impressionen, Position der letzten 16 Wochen) einholen; erst dann entscheiden zwischen „behalten und schärfen“, „zusammenführen mit 301 auf die stärkere Seite“ oder „Canonical“ (nur bei tatsächlich gleichartigem Inhalt). Keine pauschalen Weiterleitungen auf die Startseite.
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
