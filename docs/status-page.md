# Öffentliche Statusseite status.smart-einzug.de (vorbereitet)

Stand: 06.09.2026 (Auftrag II, Abschnitt 8). Es wurde nichts an DNS, Hosting-Verträgen oder externen Konten geändert. Diese Datei beschreibt, was vorbereitet ist und was zur Inbetriebnahme fehlt.

## Was vorbereitet ist

| Bestandteil | Ort | Zustand |
|---|---|---|
| Statische Statusseite | websites/status.smart-einzug.de/index.html, .htaccess, robots.txt, status.json (Platzhalter) | Fertig, wird vom Workflow in den Ordner status.smart-einzug.de des Webspace hochgeladen; noch keiner Domain zugeordnet |
| Öffentliche Datenstruktur | app/monitor.php, monitor_public_snapshot() | Fertig (Positivliste, Schema 1) |
| Veröffentlichung | status_publish(): Datei auf demselben Webspace oder GitHub-Repository über die Contents-API | Fertig, nicht konfiguriert |
| Links | Footer der Anwendung und Anmeldeseite zeigen "Systemstatus", sobald config status_page_url gesetzt ist | Fertig, deaktiviert bis die Seite erreichbar ist |
| Footer der Hauptwebsite | Zeile für websites/smart-einzug.de (siehe unten) | Bewusst noch nicht eingetragen, um keinen toten Link zu veröffentlichen |
| Externer Prüfer | Beschreibung unten | Nicht eingerichtet |

## Öffentliche Datenstruktur (status.json)

Nur diese Felder werden erzeugt (Positivliste in monitor_public_snapshot, keine nachträgliche Filterung eines Diagnoseobjekts):

- schema, generated_at (UTC, ISO 8601), valid_for_seconds (900), checked_at
- overall: state (ok, degraded, fail, maintenance, unknown), label, uncertain
- components[]: key, name, state, label, checked_at. Komponenten: Webanwendung, Anmeldung, Datenabgleich (Lexware Office), Einzugsverarbeitung, E-Mail-Benachrichtigungen
- incidents[] (nur veröffentlichte, gelöste nur 90 Tage): id, kind, title, status, status_label, components, started_at, ended_at, scheduled_end_at, message, updates[] mit phase, phase_label, text, at
- availability: je Komponente days30 und days90 mit pct (null bei unzureichender Abdeckung), coverage_pct, observed_from, observed_to, label, min_coverage_pct
- history: je Komponente 90 Tage mit day und state (ok, degraded, fail, unknown, nodata)
- notes: feste Hinweistexte

Nicht enthalten: Versionen, Hostnamen, Pfade, Datenbankgrößen, Zähler, Latenzen, Firmen- oder Kundendaten, Fehlermeldungen, interne Notizen. Der Test test_monitor.php prüft die Feldnamen gegen die Positivliste und den Inhalt gegen eine Liste verbotener Begriffe.

## Aktualität

Die Seite lädt status.json alle 60 Sekunden (pausiert in inaktiven Tabs) und vergleicht generated_at mit valid_for_seconds. Bei überschrittener Frist zeigt sie "Der aktuelle Status kann derzeit nicht zuverlässig ermittelt werden. Letzte erfolgreiche Prüfung: …" und stellt alle Komponenten als unbekannt dar. Ohne JavaScript erscheint ein Hinweis mit Link auf status.json und der Erklärung des Feldes generated_at. Besucher lösen keine Prüfungen aus; status.json hat eine Cache-Zeit von 60 Sekunden.

Die Veröffentlichung überschreibt nie einen neueren Stand mit einem älteren (Vergleich von generated_at vor dem Schreiben, bei GitHub über den vorhandenen Dateiinhalt).

## Unabhängigkeit: Bewertung und Empfehlung

Ein Ordner auf demselben IONOS-Webspace (Ziel file) ist statisch und funktioniert auch bei ausgefallenem PHP oder ausgefallener Datenbank der Anwendung, solange der Webserver läuft. Er ist aber nicht unabhängig vom Webspace selbst. Er eignet sich als erste Stufe.

Empfohlene unabhängige Lösung: ein eigenes GitHub-Repository (zum Beispiel smarteinzug-status) mit GitHub Pages als Hosting der statischen Seite und status.json. Vorteile: kostenfrei, HTTPS für eigene Domains, unabhängig von IONOS, Veröffentlichung über die Contents-API mit einem fine-grained Token (nur Contents: Read and write für genau dieses Repository), manuelle Störungsmeldung bei vollständigem App-Ausfall direkt über GitHub (Bearbeiten von incidents in status.json oder einer separaten incidents.json über den GitHub-Editor, geschützt durch das GitHub-Konto mit 2FA, ohne ungeschützten Notfall-Endpunkt).

Externe Erreichbarkeitsprüfung: Die Anwendung kann sich nicht selbst von außen prüfen. Optionen: cron-job.org (bereits für den Cron genutzt) zusätzlich auf https://app.smart-einzug.de/health.php mit Benachrichtigung bei Fehlern (liefert einen von außen erhobenen Nachweis, aber keine Übertragung in status.json) oder ein kleiner GitHub-Actions-Workflow im Status-Repository, der health.php abruft und ein Ergebnisfeld in status.json ergänzt (Mindestintervall 5 Minuten, Verzögerungen möglich, nicht minutengenau). Beides ist vorbereitet beschrieben, nicht eingerichtet.

## Einrichtungsliste (offen)

1. Entscheidung Hosting: GitHub Pages (empfohlen) oder Ordner auf dem Webspace.
2. GitHub Pages: Repository anlegen, Inhalt von websites/status.smart-einzug.de hochladen, Pages aktivieren, Custom Domain status.smart-einzug.de eintragen, HTTPS erzwingen. Fine-grained Token mit Contents: Read and write nur für dieses Repository erzeugen.
3. DNS bei IONOS: CNAME status.smart-einzug.de auf das von GitHub Pages angegebene Ziel (Benutzername.github.io) beziehungsweise bei Webspace-Hosting die Subdomain im IONOS-Kundenbereich auf den Ordner status.smart-einzug.de zeigen lassen und SSL aktivieren. Das konkrete Ziel wird erst nach Schritt 2 angezeigt und ist hier absichtlich nicht angegeben.
4. config.php der Anwendung ergänzen (Platzhalter ersetzen, keine echten Werte im Repository):

```php
'status_page_url' => 'https://status.smart-einzug.de',
'status_publish' => [
    // Variante A (Webspace): 'file' => '/home/www/public/status.smart-einzug.de/status.json',
    // Variante B (GitHub Pages):
    'github' => ['owner' => 'HIER-GITHUB-BENUTZER', 'repo' => 'HIER-STATUS-REPOSITORY', 'path' => 'status.json', 'branch' => 'main', 'token' => 'HIER-FINE-GRAINED-TOKEN'],
],
```

5. Im Adminbereich System unter Störungen "Snapshot jetzt übertragen" (2FA) ausführen und die Seite prüfen.
6. Footer der Hauptwebsite ergänzen (websites/smart-einzug.de, Footer-Navigation "Rechtliches"): `<li><a href="https://status.smart-einzug.de/">Systemstatus</a></li>` und danach `python3 tools/site-qa.py` sowie `python3 tools/build-sitemaps.py` ausführen.
7. Optional externer Prüfer (cron-job.org auf health.php) einrichten.

Cookies: Die Statusseite setzt keine Cookies. Sitzungscookies der Anwendung sind Host-only (app. beziehungsweise admin.) und gelten nicht für die Status-Subdomain.

## Grenzen

Bis zur Einrichtung ist keine öffentliche Statusseite live und keine externe Prüfung aktiv. Der Snapshot spiegelt interne Messungen der Anwendung; fällt die Anwendung vollständig aus, bleibt der letzte Stand sichtbar und wird nach 15 Minuten als veraltet gekennzeichnet. Ein extern erhobener Erreichbarkeitsnachweis ist damit noch nicht Teil der Seite.
