# Freigabecheckliste Frontend

Stand 13.09.2026, APP_VERSION 4.76, Branch `claude/frontend-smart-einzug-egsouk`.

## 1. Stand der Zusammenführung

| | |
|---|---|
| Frontend-Branch | `claude/frontend-smart-einzug-egsouk` |
| Backend-Branch | `claude/setup-lexsepa-monorepo-v5ZcZ` |
| Gemeinsamer Stand | Backend 4.75 wurde am 13.09.2026 in den Frontend-Branch geholt, konfliktfrei |
| Faktenquelle | `docs/seo/02-faktenregister.md` (Stand 07.09.2026, ein Eintrag überholt, siehe `backend-anfragen.md`) |

Nur ein Push auf `main` oder auf den Backend-Branch löst ein Deployment aus. Der Frontend-Branch tut das
nicht. Eine Veröffentlichung entsteht erst durch die Zusammenführung in den Backend-Branch und ist eine
Entscheidung des Betreibers.

## 2. Vor der Zusammenführung erledigt

- [x] Backend-Stand in den Frontend-Branch geholt, konfliktfrei
- [x] Alle zehn Prüfwerkzeuge ausgeführt, Rückgabewerte einzeln geprüft (`testbericht.md`)
- [x] Gegenproben zu den berührten Prüfpunkten dokumentiert
- [x] Dokumentation neu gebaut, `docs-build-check.py` ohne Fehler
- [x] Änderungsverlauf, Dokumentrevisionen und Arbeitsstand fortgeschrieben
- [x] Keine Änderung in `php-ionos/` außer dem Änderungsverlauf
- [x] Keine neuen externen Dienste, keine neuen Abhängigkeiten, keine Änderung an Workflows

## 3. Nach der Veröffentlichung zu prüfen

Diese Punkte kann nur der Betreiber am laufenden System abarbeiten. Sie sind der Ersatz für die Tests, die
aus der Arbeitsumgebung nicht möglich waren.

- [ ] `https://smart-einzug.de/fakten/` liefert HTTP 200 und ist ohne JavaScript vollständig lesbar
- [ ] Der Canonical der Seite zeigt auf sich selbst
- [ ] Die Seite steht in `https://smart-einzug.de/sitemap.xml`
- [ ] Der Footer-Link „Fakten zu SmartEinzug“ erscheint auf den Seiten der Hauptdomain
- [ ] Die Entwicklerkonsole meldet auf der Faktenseite keine blockierte Ressource
- [ ] Die korrigierte Aussage unter `/wissen/ruecklastschrift/` ist live
- [ ] Die Seite wird in der Search Console zur Indexierung eingereicht

## 4. Rücknahme

Alle Änderungen dieses Durchgangs sind statische Dateien, eine JSON-Datei und Dokumentation. Eine Rücknahme
erfolgt durch Zurücknehmen des Änderungssatzes und einen erneuten Durchlauf des Deployments. Es gibt keine
Datenbankmigration, keine Änderung an Schnittstellen und keine Abhängigkeit zu einem bestimmten
Backend-Stand.

Zu beachten: Der Job `deploy-webhosting` spiegelt die Website-Ordner mit `--delete`. Eine Rücknahme
entfernt die Faktenseite deshalb auch vom Hoster. Das ist beabsichtigt und nicht gesondert zu behandeln.

## 5. Offen, Freigabe erforderlich

| Punkt | Wer entscheidet |
|---|---|
| Zusammenführung in den Backend-Branch und damit Veröffentlichung | Betreiber |
| Preisbeträge auf der Faktenseite (derzeit bewusst nicht genannt) | Betreiber |
| Nachziehen des Faktenregisters, Eintrag RUECK-01 | Backend |
| Standortangaben für Anwendung und Sicherungsspeicher | Betreiber, aus den Anbieterverträgen |
| Verschlüsselung der IBAN oder Anpassung der AVV-Anlagen | Betreiber, nach anwaltlicher Prüfung |
| Zweite Conversion-Aktion für den tatsächlichen Vertragsabschluss | Betreiber |
| `llms.txt` einführen oder nicht | Betreiber, siehe `grounding-seo.md` Abschnitt 6 |

## 6. Was dieser Durchgang nicht leistet

Keine Zusage zu Rankings, zur Nennung in KI-Antworten, zur Indexierung durch Suchmaschinen oder zur
Rechtskonformität. Keine Aussage zur Produktionsreife von Bereichen, die nicht getestet werden konnten;
die sind im `testbericht.md` einzeln benannt.
