# Leistungsbericht Audit SmartEinzug (Stand 10.09.2026)

## 1. Zielgröße und Einordnung

Planungsszenario des Betreibers: 10.000 Nutzer, 100 bis 500 gleichzeitig aktiv. Dieser Bericht liefert KEINEN Nachweis dieser
Kapazität. Gemessen wurde lokal in der Prüfumgebung mit Stubs, um den Engpass zu benennen und die Nebenläufigkeitsinvarianten
unter Last zu prüfen. Hochrechnungen auf Produktion sind nicht belastbar und werden hier nicht vorgenommen.

## 2. Messumgebung und Annahmen (offengelegt)

| Größe | Wert |
|---|---|
| Umgebung | Container der Prüfumgebung, 4 CPU, 16.075 MB RAM, PHP 8.4.19 CLI, MariaDB 10.11.14 (temporär, eigener Port, UTC), Redis nicht genutzt (`redis => null`, Ratenbegrenzung damit inaktiv) |
| Stripe | lokaler Stub `tools/lib/stripe-stub.php` (php -S, ein Prozess, Antwort in Millisekunden). Echte Stripe-Latenz (typisch mehrere hundert Millisekunden je Aufruf, vier Aufrufe je Erst-Einzug) fehlt vollständig |
| Lexware | Fake-Quelle im Prozess (kein Netz, keine Drosselung 2 Anfragen/s je Schlüssel). Echte Synchronisation ist durch die Anbieterlimits bestimmt, nicht durch die Datenbank |
| Daten | 1 Firma, 60 Kunden mit Mandat und Rechnung; 400 Jobs; 300 Belege mit 60 Kontakten; leere Tabellen sonst |
| Werkzeug | `bash tools/perf-probe.sh 60 400 300` (reproduzierbar, Protokoll `perf-probe.log` im Prüfstand) |
| Abbruchgrenzen | Suite-Zeitlimit 900 s, keine Lasterzeugung gegen externe Dienste (Test-Schutz erzwingt 127.0.0.1) |

## 3. Messwerte (Stand nach den Korrekturen, Version 4.59)

| Messung | Ergebnis | Bewertung |
|---|---|---|
| A) 60 fällige terminierte Einzüge, ein Lauf | 1.308 ms gesamt, 21 ms je Einzug (Datenbank, Versuchsjournal, vier Stub-Aufrufe, Audit) | Datenbankanteil je Einzug klein; in Produktion dominiert die Stripe-Latenz |
| A) dieselben 60 Einzüge, drei parallele Läufe | 480 ms gesamt, 60 PaymentIntents, 0 doppelte PaymentIntents, 0 doppelte Einzugsdatensätze | Beanspruchung `UPDATE ... WHERE stripe_status = 'scheduled'` skaliert; Invariante I1 unter Last bestätigt |
| B) 400 Jobs, 8 parallele Reservierer | 849 ms, 400 reserviert, 0 doppelt, etwa 2,1 ms je Reservierung inklusive Abschluss | `FOR UPDATE SKIP LOCKED` trägt; kein Engpass bei dieser Größe |
| C) Synchronisation 300 Belege (Fake-Quelle) | 333 ms, 10 Schritte, etwa 1 ms je Beleg, 6 MB Spitzenspeicher | Datenbankseite des Imports ist nicht der Engpass; zweiter Lauf unverändert 170 ms (Delta über updatedDate) |

Vor den Korrekturen wurde keine Messung durchgeführt, weil die Änderungen des Audits keine Leistungsoptimierung waren; die
Werte dienen als Referenz für spätere Vergleiche. Die einzige leistungsrelevante Änderung ist der Wechsel von `FOR UPDATE` auf
`organizations` zu `GET_LOCK` je Firma (D-05): Er verkürzt Wartezeiten anderer Schreiber auf die Firmenzeile, nicht den Einzug.

## 4. Engpassanalyse (aus Code und Messung, nicht aus Last gegen Anbieter)

1. Lexware-Ratenbegrenzung: 2 Anfragen je Sekunde je Schlüssel (`queue.lexoffice_per_second`, Annahme laut
   `docs/sync-performance.md`, nicht am Primärtext verifiziert). Ein Erstimport mit 1.000 Belegen braucht mindestens 1.000
   Detailabrufe plus Kontakte, also im Bereich von 10 bis 20 Minuten je Firma unabhängig von der Datenbank. Bei 500 Firmen mit
   nächtlichem Vollabgleich verteilt `full_sync_window_hours` die Last; zwei Lexware-Worker begrenzen die Parallelität.
2. Stripe-Latenz: vier Aufrufe je Erst-Einzug, später einer (Zahlungsmethode gespeichert). Ratenbegrenzung 20/s je Konto
   (`queue.stripe_per_second`). Der Fälligkeitslauf ist seriell je Worker (ein `worker-stripe`); bei mehreren tausend fälligen
   Einzügen je Nacht wäre die Zahl der Stripe-Worker zu erhöhen (Compose), die Beanspruchung je Einzug erlaubt das gefahrlos
   (Messung A, drei parallele Läufe).
3. Datenbank: Alle gemessenen Pfade liegen bei 1 bis 21 ms je Vorgang. Indizes für die Jobstatistik (Migration 032) beheben
   den einzigen aus dem Code erkannten Tabellenscan (D-13). `NOT IN` mit einem Platzhalter je Rechnung (D-18) begrenzt die
   Nachprüfung auf unter 65.535 offene Rechnungen je Firma (Grenze von MariaDB), praktisch nicht erreicht.
4. Webanfragen: nicht gemessen (kein Lasttest gegen php-fpm in der Prüfumgebung). Dashboard und Rechnungsliste laden je
   Firma mit `LIMIT 500`; für 100 bis 500 gleichzeitige Nutzer ist die Anzahl der php-fpm-Kinder im Container die
   bestimmende Größe (nicht geprüft).

## 5. Empfehlungen (Nutzen, Risiko, Aufwand)

- Vor einer Kapazitätsaussage einen Staging-Lasttest mit realistischer Stripe-Testlatenz und Lexware-Testkonto durchführen
  (Nutzen hoch, Risiko keines im Testmodus, Aufwand mittel). Kein Redis-Ausbau, keine weiteren Dienste ohne diese Messung.
- `worker-stripe` horizontal skalierbar halten (bereits durch Beanspruchung abgesichert); Anzahl erst nach Messung erhöhen.
- Perzentile der Jobstatistik in SQL statt PHP berechnen (D-13, P3), sobald `job_runs` sechsstellig wird.
