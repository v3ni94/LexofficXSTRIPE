# Navigation und Export der Kundenanwendung

Stand: 06.09.2026 (Auftrag II, Abschnitt 2)

## Grundsatz

Ein Menüpunkt erscheint entweder in der Hauptnavigation im Kopfbereich oder im Profilmenü, nie an beiden Stellen. Das Profilmenü bündelt alles, was das eigene Konto, die eigene Firma oder Verwaltungsaufgaben betrifft. Die Hauptnavigation enthält nur die täglichen Arbeitsbereiche.

## Aufbau

Hauptnavigation (Kopfbereich):

| Punkt | Ziel | Sichtbar für |
|---|---|---|
| Dashboard | dashboard.php | alle |
| Rechnungen | invoices.php | alle |
| Einzüge | collections.php | alle |
| Kunden | customers.php | alle |
| SEPA Pflegen | sepa-pflegen.php | alle |
| Not-Stopp | notstopp.php | Einstellungsberechtigte |
| Hilfe | hilfe.php | alle |
| Admin | admin.php | Betreiber-Admins |

Profilmenü (Avatar rechts oben):

| Punkt | Ziel | Sichtbar für |
|---|---|---|
| Firmendaten | team.php | alle (nicht auf dem Admin-Host) |
| Einstellungen | settings.php | Einstellungsberechtigte |
| Export | export.php | alle (nicht auf dem Admin-Host) |
| Sicherheit | security.php | alle |
| Firmenübersicht | companies.php | alle, außer im Support-Modus und auf dem Admin-Host |
| Abmelden | logout.php | alle |

## Umbenennungen

| Bisher | Neu | Betroffene Stellen |
|---|---|---|
| Team | Firmendaten | Profilmenü, team.php (Titel und Überschrift), Verweise in settings.php, Hilfe-Center, Support-E-Mail |
| Firmen | Firmenübersicht | Profilmenü, companies.php (Titel und Überschrift) |
| Hilfe im Profilmenü | entfällt | Hilfe bleibt nur in der Hauptnavigation |
| Export im Kopfbereich | entfällt | Export bleibt nur im Profilmenü |
| Kundenanwendung im Admin-Profilmenü | entfällt | Wechsel in die Kundenanwendung ausschließlich über den Support-Bereich |

Die Unternavigation im Adminbereich (admin.php) behält den Punkt "Firmen", weil dort die Firmenliste des Betreibers gemeint ist, nicht die Firmenübersicht des angemeldeten Benutzers.

## Vergleich der Exportfunktionen

Geprüft wurden der Export im Profilmenü und der Exportbutton auf der Seite Einzüge.

Ergebnis:

- Beide Wege rufen dieselbe Datei export.php auf und liefern dasselbe CSV-Format mit identischer Kopfzeile und identischen Spalten.
- Der Export im Profilmenü liefert immer das vollständige Einzugsjournal der Firma.
- Der Button auf der Seite Einzüge übergibt zusätzlich den gerade aktiven Statusfilter. Ohne aktiven Filter ist das Ergebnis mit dem Export aus dem Profilmenü identisch.

Entscheidung: Der Button auf der Seite Einzüge bleibt bestehen, weil er die gefilterte Ansicht exportiert und damit eine eigene Funktion hat. Damit der Unterschied erkennbar ist, lautet die Beschriftung jetzt "Gefilterte Einzüge als CSV exportieren", sobald ein Filter aktiv ist, und "Journal als CSV exportieren" ohne Filter. Der Tooltip erklärt, dass es sich um denselben Export wie im Profilmenü handelt.

Der zuvor zusätzlich im Kopfbereich vorhandene Export-Link war eine echte Dublette zum Profilmenü und wurde entfernt.

## Tests

Die E2E-Suite (scratchpad/e2e_saas.php) prüft:

- Profilmenü enthält Firmendaten, Export (mit Tooltip) und Firmenübersicht.
- team.php, settings.php und Hilfe kommen in der Seite jeweils genau einmal als Navigationslink vor.
- Der Export aus dem Profilmenü und der gefilterte Export der Seite Einzüge haben dieselbe Kopfzeile, der gefilterte Export ist eine Teilmenge des vollständigen Exports.
