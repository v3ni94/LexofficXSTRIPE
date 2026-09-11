# Abdeckungsübersicht und offene Punkte

Stand 07.09.2026, Version 4.32. Prüfmaßstab der Stufen: **Inhalt vorhanden** (Kapitel geschrieben), **technisch referenziert** (Aussagen mit Datei und Funktion belegt), **fachlich geprüft** (durch den Betreiber oder eine zweite Person inhaltlich abgenommen), **praktisch getestet** (automatischer Test oder dokumentierter Probelauf). Es wird keine Gesamtprozentzahl ausgewiesen, solange die fachliche Prüfung durch den Betreiber nicht erfolgt ist.

## Abdeckung nach Bereich

| Bereich | Kapitel | Inhalt vorhanden | Technisch referenziert | Fachlich geprüft | Praktisch getestet |
|---|---|---|---|---|---|
| Architektur, Hosts, Netzwerk | Architektur; Server, Hosting, Domains | ja | ja (Compose, Caddyfile, Betreiberausgaben) | offen | Deployments 4.20 bis 4.31 erfolgreich; DNS-Nachweis offen |
| Repository und Deployment | Repository; Einrichtung; VPS-Kapitel 01 bis 08 | ja | ja | offen | github-ssh-retry-check, github-poll-check, deploy-runner-check, redis-deploy-check, compose-check grün |
| Datenmodell | Datenwörterbuch (45 Tabellen) | ja | ja (aus schema.sql erzeugt) | offen | Migrationsläufe im Deployment; interest-check, legal-check, invoice-source-check gegen temporäre MariaDB |
| Geschäftslogik | Geschäftslogik (7 Abläufe, Referenzfälle) | ja | ja | offen | worker-signal-check, scheduler-sync-check; Referenzfälle noch nicht als automatischer Test |
| Schnittstellen und Webhooks | Schnittstellen | ja | ja | offen | Webhook-Signaturprüfung nur im Code; kein automatischer Test |
| Hintergrundprozesse | Cronjobs, Warteschlangen; Hintergrundverarbeitung | ja | ja | offen | worker-signal-check, scheduler-sync-check |
| E-Mail | E-Mail-System; Mailversand einrichten | ja | ja | Betreiber 07.09.2026 (Testversand erfolgreich) | mail-ci-check; SPF/DKIM/DMARC offen |
| Sicherheit und Rollen | Sicherheit | ja | ja | offen (adversariale Prüfungen 4.18 dokumentiert) | teilweise (interest-check, legal-check) |
| Wiederherstellung | Einrichtung (e); Backup und Wiederherstellung (Schaubild) | ja | ja | offen | Wiederherstellungstest nicht protokolliert |
| Fehlersuche | Fehlerhandbuch (11 Fälle) | ja | ja | offen | nicht anwendbar |
| Tests und Nachverfolgbarkeit | Tests und Nachverfolgbarkeit | ja | ja | offen | Werkzeuge laufen im Workflow |
| Rechtsdokumente | Rechtsdokumente | ja | ja | anwaltliche Prüfung offen | legal-check |
| sevdesk (Roadmap) | sevdesk-Erweiterung; Integrationen | ja | ja | offen | interest-check, invoice-source-check |
| Unternehmensdokumentation | eigenes Dokument | ja | ja (Funktionskatalog aus Code) | Geschäftsführung offen | nicht anwendbar |
| Benutzerhandbuch | eigenes Dokument | ja (Screenshots offen) | ja (Oberflächentexte) | offen | nicht anwendbar |

## Bekannte Lücken der Quellen

- Die in `CLAUDE.md` und älteren Kapiteln genannte E2E-Suite `scratchpad/e2e_saas.php` und die Dateien `test_*.php` existieren im Repository nicht; Aussagen dazu sind als „referenziert, nicht gefunden“ zu lesen.
- Der Wiederherstellungstest der Datenbank ist nur als Betreiberbestätigung ohne Protokoll dokumentiert (`docs/betrieb-migration-vps.md`); Sicherung von `shared/config.php` und `shared/storage` ist nicht belegt.
- SPF, DKIM und DMARC der Absenderdomain sind im Repository nicht nachgewiesen.
- Screenshots für das Benutzerhandbuch fehlen (bereinigte Demo-Umgebung erforderlich).
- Kontingent-Warnmail (`app/plans.php`, `plan_quota_warning_maybe_send()`) nutzt nicht `mail_layout()`.
- Content-Security-Policy existiert nur für die Statusseite, nicht für App-, Admin- und API-Host.

## Offene Prüfpunkte aus den Kapiteln

Automatisch eingesammelt aus den Abschnitten „Offene Prüfpunkte“ der Entwicklerkapitel (Zuständigkeit: Betreiber, sofern nicht anders genannt).

| Kapitel | Prüfpunkt |
|---|---|
| einrichtung.md | Ein eigenständiges Dokument für die lokale Entwicklungsumgebung fehlt; Abschnitt (a) dieser Datei ist aus Einzelbausteinen abgeleitet und sollte bei Gelegenheit in ein eigenes, offizielles Einrichtungsdokument überführt werden. |
| einrichtung.md | Sicherung von `shared/storage` (Mandatsdateien), `config.php`/`shared/config.php` und den darin enthaltenen Verschlüsselungsschlüsseln ist weder als eingerichtet noch als getestet belegt; hier besteht eine Lücke gegenüber dem sonst dokumentierten Datenbank-Backup. |
| einrichtung.md | Die tatsächliche Objektliste im Hetzner-Bucket sowie ein mit Zeitstempel, Zieltabellen und Zeilenzahlen dokumentierter Restore-Test stehen laut `docs/betrieb-migration-vps.md` noch aus; ein solcher Test mit `deploy/vps/backup/restore-test.sh` sollte nachgeholt und protokolliert werden, bevor er als vollständig geprüft gilt. |
| einrichtung.md | Verschlüsselung der produktiv verwendeten Coolify-Sicherungen (nicht nur des optionalen Ausweichwegs) ist im Repository nicht belegt; vor einer verbindlichen Aussage dazu Rücksprache mit dem Betreiber/Coolify-Administrator nötig. |
| einrichtung.md | Aufbewahrungsfristen des produktiven Coolify-Backupplans sind im Repository nicht belegt (nur die abweichende, nicht aktive 14-Tage-Rotation des Ausweichskripts ist bekannt). |
| email-system.md | **Frage:** Soll die Einzugskontingent-Warnung (`plan_quota_warning_maybe_send()`, |
| email-system.md | **Frage:** Gibt es einen Wiederversand-/Pending-Mechanismus für Einladungen |
| email-system.md | **Frage:** Ist eine E-Mail-Benachrichtigung bei Störungen der öffentlichen Statusseite an |
| email-system.md | **Frage:** Sind SPF, DKIM und DMARC für die tatsächlich verwendete Absenderdomain (z. B. |
| fehlerhandbuch.md | Für Fall 7 (Rücklastschrift) ist der externe Test mit einer echten Erstattung im Stripe-Testmodus laut `docs/payment-safety.md` Abschnitt 10 noch offen; die Webhook-Endpunkte der Firmen müssen dafür `charge.refunded` und `charge.refund.updated` liefern. |
| fehlerhandbuch.md | Für Fall 9 wurde kein Bericht über einen tatsächlich durchgeführten Rollback nach einem produktiven Fehlschlag der Migration gefunden (nur die Abläufe für Candidate-Fehler und Health-Check-Fehler sind mit realen Vorfällen belegt); ein reiner Migrationsfehlschlag in Produktion ist laut Repository bislang nicht dokumentiert aufgetreten. |
| fehlerhandbuch.md | Für Fall 3 (Redis) ist die tatsächliche Docker-DNS-Auflösung des Alias und die Netzmitgliedschaft eines echten `compose run`-Containers laut `docs/vps/06-betrieb.md` (Abschnitt „Nachtrag Version 4.10“) in der Entwicklungsumgebung nicht nachstellbar (kein Docker-Daemon); die dortige Herleitung stützt sich auf zitierte Primärquellen, nicht auf einen eigenen Testlauf in diesem Repository. |
| fehlerhandbuch.md | Ob die in `docs/vps/06-betrieb.md` erwähnte manuelle Löschung des alten IONOS-Cronjobs (Abschnitt „Braucht der VPS Cron-Jobs?“, Zeile 610 ff.) bereits erledigt wurde, ist im Repository nicht nachvollziehbar (organisatorische Aufgabe außerhalb des Codes); Stand in `docs/vps/07-cutover-checkliste.md`, Punkt 12, zu prüfen. |
| jobs.md | **Frage:** Verhält sich `full_sync_hour` bei der Umstellung zwischen Sommer- und Winterzeit |
| jobs.md | **Frage:** Ist ein manueller Neustart nur eines einzelnen Worker-Containers (statt aller fünf) |
| jobs.md | **Frage:** Wie lange bleibt ein `sync_run`-Job nach einer erzwungenen Fortsetzung |
| schnittstellen.md | **Frage:** Gilt für Lexware Office tatsächlich ein Limit von 2 Anfragen/Sekunde, oder hat sich der |
| schnittstellen.md | **Frage:** Soll `stripe-webhook.php` zusätzlich einen `webhook_events`-Dedupe-Schutz erhalten (wie |
| schnittstellen.md | **Frage:** Ist die sevdesk-Basisadresse (`config('sevdesk')['base_url']`) und der Header-Präfix |
| schnittstellen.md | **Frage:** Warum liefert `health.php` keine eigene Host-Einschränkung im PHP-Code (nur die |
| sicherheit.md | **Frage:** Soll für App- und Adminhost ebenfalls eine Content-Security-Policy eingeführt werden |
| sicherheit.md | **Frage:** Existiert ein Rotationsverfahren für `app_secret`, falls ein Verdacht auf Kompromittierung |
| sicherheit.md | **Frage:** Wird die Sicherheits-E-Mail an den Inhaber bei einem gestarteten Support-Zugriff |
| sicherheit.md | **Frage:** Ist HSTS (`Strict-Transport-Security`) inzwischen für App-, Admin- und API-Host auf dem |
| tests-und-nachverfolgbarkeit.md | Klären, ob `scratchpad/e2e_saas.php` und die neun referenzierten `test_*.php`-Dateien jemals im Repository lagen oder ausschließlich in einer nicht versionierten lokalen Arbeitsumgebung existierten; ohne diese Klärung bleibt unklar, ob die in den Fachdokumenten beschriebenen Testergebnisse (z. B. „Reihenfolge 4 vor 5 vor 6“ in `docs/payment-safety.md`) aktuell reproduzierbar sind. |
| tests-und-nachverfolgbarkeit.md | Keines der vorhandenen `tools/*`-Werkzeuge ist als Schritt in `.github/workflows/deploy.yml` eingebunden; ihre Ausführung vor einem Commit ist nur durch `CLAUDE.md` organisatorisch vorgeschrieben, nicht technisch erzwungen. Eine Ergänzung des Workflows um zumindest die schnellen, datenbankfreien Prüfungen (`tools/mail-ci-check.php`, `tools/pricing-check.php`, `tools/billing-setup-check.php`, `tools/healthcheck-redis-check.php`, `tools/docs-build-check.py`, `tools/staging-isolation-check.py`) wäre eine mögliche Verbesserung, wurde aber nicht umgesetzt und ist hier nicht empfohlen, ohne dass die Geschäftsführung die Erweiterung des Workflows freigibt. |
| tests-und-nachverfolgbarkeit.md | Für „Kunden/Mandate“, „Firmen/Team/Rollen“, „Lexware-Verbindung“ und „Webhooks“ wurde kein einziges automatisches Testwerkzeug im Repository gefunden; eine Ergänzung wäre fachlich sinnvoll, ist aber nicht Teil dieses Auftrags. |

### Marketingmodul (4.63)

- **Offen (Betreiber):** SES-Identität `mail.smart-einzug.de` mit DKIM und MAIL-FROM-Domain verifizieren, DNS bei IONOS
  eintragen, SMTP-Zugangsdaten in `shared/config.php`, Produktionsfreigabe des SES-Kontos, SNS-Thema mit HTTPS-Abonnement auf
  `marketing-webhook.php`. Prüfverfahren: `docs/marketing.md`, Schritt 8 (Testnachricht an Gmail, „Original anzeigen“).
- **Annahme (im Prüfstand mit eigenem Zertifikat belegt, nicht mit echtem Ereignis):** Aufbau der SNS-Signaturprüfung und
  Struktur der SES-Ereignisse nach AWS-Dokumentation. Prüfverfahren: erster echter Rückläufer erzeugt ein Ereignis `bounce`;
  andernfalls steht „SNS-Nachricht mit ungueltiger Signatur abgewiesen“ im Anwendungsprotokoll.
- **Offen (Entscheidung):** Aufbewahrungsdauer von `marketing_events` und versendeten Kampagnen (derzeit unbegrenzt, als
  Nachweis); Bereinigung als Wartungsaufgabe nachziehen, sobald eine Frist festgelegt ist.

## Zuständigkeiten

| Thema | Zuständig | Nachweis |
|---|---|---|
| DNS und Abschaltung der Altinstanz | Betreiber | dig, IONOS-Kundenbereich, Cronjob-Liste |
| Wiederherstellungstest | Betreiber mit Entwicklung | Protokoll mit Datum, Zeilenzahlen, Prüfsummen in `docs/betrieb-migration-vps.md` |
| Anwaltliche Prüfung der Rechtsdokumente | Geschäftsführung | Freigabe im Adminbereich, Rechtsdokumente |
| Fachliche Abnahme der Dokumentation | Geschäftsführung | Vermerk in `docs/dokumentationsregeln/revisionen.json` (Feld summary) |
| Screenshots Benutzerhandbuch | Entwicklung | Demo-Umgebung, Bilder unter `docs/kunden/bilder/` |
