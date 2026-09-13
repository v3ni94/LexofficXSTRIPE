# Testbericht Frontend

Stand 13.09.2026. Basis: Branch `claude/frontend-smart-einzug-egsouk` nach Zusammenführung mit dem
Backend-Stand 4.75. Alle Läufe fanden in der Arbeitsumgebung dieser Sitzung statt, nicht auf dem Server.

## 1. Ausgeführte Prüfungen

Alle folgenden Werkzeuge wurden nach der letzten Änderung ausgeführt und einzeln auf ihren Rückgabewert
geprüft.

| Prüfung | Befehl | Ergebnis | Rückgabewert |
|---|---|---|---|
| Marketingseiten, redaktionelle und technische Regeln | `python3 tools/site-qa.py` | 0 Fehler, 2 Warnungen | 0 |
| Interne Verlinkung, verwaiste Seiten | `python3 tools/seo-linkcheck.py` | 63 Seiten, 53 indexierbar, 0 Fehler, 0 Warnungen | 0 |
| Themen- und URL-Zuordnung | `python3 tools/seo-map-check.py` | 29 Cluster, 63 Seiten, 0 Fehler | 0 |
| Google-Tag und Einwilligung auf den Seiten | `python3 tools/site-tag-check.py` | 0 Fehler, 0 Warnungen | 0 |
| Messung in der Anwendung | `php tools/app-tracking-check.php` | 57 von 57 bestanden | 0 |
| Keine Preisbeträge auf statischen Seiten | `php tools/pricing-check.php` | 14 von 14 bestanden | 0 |
| Trennung Kundenanwendung und Adminhost | `php tools/host-separation-check.php` | 25 von 25 bestanden | 0 |
| Geltungsbereich der Zweitbestätigung | `php tools/totp-policy-check.php` | 87 von 87 bestanden | 0 |
| Sicherungen des Geldflusses | `php tools/payment-safety-check.php` | 74 von 74 bestanden | 0 |
| Dokumentationssystem | `python3 tools/docs-build-check.py` | 0 Fehler nach Neubau | 0 |

Die beiden Warnungen von `site-qa.py` bestehen seit früheren Durchgängen und betreffen gleichlautende
Überschriften zwischen der Hauptdomain und den Leadseiten. Sie sind bekannt und bewusst nicht behoben, weil
die betroffenen Seiten unterschiedliche Suchintentionen bedienen.

## 2. Gegenproben

Eine Prüfung, die nie rot wird, beweist nichts. Zu den in diesem Durchgang berührten Prüfpunkten wurde
deshalb jeweils der Fehlerfall hergestellt und das Anschlagen belegt.

| Gegenprobe | Erwartet | Tatsächlich |
|---|---|---|
| Linkziel `/wissen/sepa-lastschriftmandat/` auf der Faktenseite (existierte nicht) | Fehler | `seo-linkcheck.py` meldete „interner Link ohne Ziel“, Rückgabewert 1. Ziel auf `/wissen/sepa-mandat/` korrigiert. |
| Faktenseite ohne Eintrag in der Sitemap | Fehler | `seo-map-check.py` meldete „Primärseite fehlt in der Sitemap“, Rückgabewert 1. Sitemaps neu gebaut. |
| Faktenseite ohne Signaturkommentar | Warnung | `site-qa.py` meldete „Signaturkommentar fehlt“. Ergänzt. |

Diese drei Fehler sind während der Arbeit tatsächlich aufgetreten und wurden von den Prüfern gefunden,
nicht vorab konstruiert.

## 3. Was nicht getestet wurde

Diese Punkte werden ausdrücklich als ungetestet ausgewiesen. Es werden dazu keine Ergebnisse behauptet.

| Bereich | Grund |
|---|---|
| Abruf der Live-Seiten | Der Ausgangsproxy dieser Umgebung verweigert fremde Domains mit HTTP 403. Statuscodes, Weiterleitungen, Header und die tatsächliche Auslieferung der Sicherheitsrichtlinie konnten nicht am laufenden Server geprüft werden. |
| Angemeldete Oberflächen der Anwendung | Kein Zugang zu einer laufenden Instanz. Registrierung, Zwei-Faktor-Anmeldung, Onboarding, Stripe-Verbindung, Rechnungsliste und Einzugsfreigabe wurden im Quelltext gelesen, nicht bedient. |
| Browser- und Gerätetests | Keine realen Endgeräte verfügbar. Eine Emulation wird nicht als Test eines physischen Geräts ausgegeben. |
| Web Vitals | Keine Felddaten verfügbar. Laborwerte ohne Feldbezug sind kein Nachweis und werden deshalb nicht ausgewiesen. |
| Barrierefreiheit nach WCAG 2.2 AA | Die neue Seite verwendet ausschließlich Bausteine des bestehenden Stylesheets, semantisches HTML, eine Tabelle mit `scope` an jeder Kopfzelle und keine eigenen Skripte. Eine Prüfung mit Tastatur und Screenreader in einem echten Browser hat nicht stattgefunden. |
| Doppelzählung von Conversions | Setzt einen Testlauf mit echtem Google-Ads-Konto voraus. Statisch geprüft ist, dass in der Anwendung kein Conversion-Label gesetzt ist, solange die Marketingseite den Klick meldet. |

## 4. Bewertung

Die Änderungen dieses Durchgangs sind statisches HTML, eine Ergänzung der Keyword-Map, neu gebaute
Sitemaps und Dokumentation. Sie berühren keine Geschäftslogik, keine Datenbank, keinen Zahlungsweg und
keine Berechtigung. Das Risiko einer Regression liegt damit im Bereich Darstellung und Verlinkung, und
genau dafür sind die oben genannten Prüfungen ausgelegt.

Nicht abgedeckt bleibt alles, was erst im laufenden Betrieb sichtbar wird. Für eine Freigabe zur
Veröffentlichung ist die Gegenprobe am laufenden System erforderlich, siehe `release-checklist.md`.
