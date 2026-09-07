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

## M3 Rolle der Leadseiten und die Entscheidung DETM Management Consulting FZCO

- Befund: `docs/ARBEITSSTAND.md` (Abschnitt 2) hält fest, dass lexware-einzug.de und lexoffice-einzug.de auf DETM Management Consulting FZCO laufen sollen (eigenständige Leadseiten ohne SmartEinzug-Logo, Provision nach Herkunft); Impressumsdaten fehlen. Der Masterprompt vom 07.09.2026 verlangt dagegen, dass alle drei Domains erkennbar zu SmartEinzug gehören und nicht als drei Anbieter erscheinen.
- Aktueller Stand der Seiten: SmartEinzug-Logo im Kopf, Fußzeile „SmartEinzug ist ein Angebot der Müller Holding AG“, Impressum der Müller Holding AG, Organisation Müller Holding AG im Markup. Das entspricht dem Masterprompt.
- Entscheidung nötig: Gilt die DETM-Entscheidung weiter? Dann braucht es Impressumsdaten, eine Anbieterkennzeichnung, die Kunden nicht täuscht, und eine Regelung, wer Vertragspartner wird. Bis zur Entscheidung bleibt die Darstellung als Angebot der Müller Holding AG bestehen; nichts wird erfunden.

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
