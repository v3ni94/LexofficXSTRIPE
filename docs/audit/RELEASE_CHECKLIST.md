# Release-Checkliste nach dem Audit (Stand 11.09.2026, ergänzt um 4.60 bis 4.63)

Der Prüfbranch `audit/2026-09-09-gesamtpruefung` wurde nicht gepusht und nicht ausgerollt. Er enthält inzwischen 4.59 (Audit), 4.60 und 4.61
(Zustellbarkeit), 4.62 (Kundenprofil, Migration 033) und 4.63 (Marketingmodul, Migration 034); die Punkte 7 bis 9 in Abschnitt 1
und 4 bis 6 in Abschnitt 2 betreffen diese Nachträge. Diese Liste beschreibt, was vor,
während und nach dem Ausrollen zu tun ist. Jeder Punkt nennt Zweck und Risiko.

## 1. Vor dem Ausrollen (Staging)

1. Branch in den Arbeitsbranch übernehmen (Merge oder Rebase), Konflikte prüfen. Zweck: einheitlicher Stand. Risiko: Merge
   überschreibt Frontend-Änderungen des anderen Chats; `git diff --stat` gegen `origin/claude/setup-lexsepa-monorepo-v5ZcZ`.
2. Alle Suiten lokal ausführen (Reihenfolge und Liste in CLAUDE.md, neu: `tools/test-guard-check.sh`, `tools/collections-check.sh`,
   `tools/sync-check.sh`, `tools/auth-check.sh`). Zweck: Nachweis. Risiko: keines.
3. Migration 032 auf Staging einspielen (`php bin/migrate.php`), danach prüfen, ob der Index `uq_collection_tenant_pi` existiert:
   `SHOW INDEX FROM payment_collections WHERE Key_name = 'uq_collection_tenant_pi'`. Fehlt er, enthält der Bestand Dubletten
   (Abfrage im Kopf der Migration); dann Dubletten fachlich bereinigen (welcher Datensatz gilt, Stripe-Dashboard vergleichen)
   und die Migration erneut ausführen (`--retry=032`). Zweck: Eindeutigkeit. Risiko: ohne Index bleibt nur der Anwendungsschutz.
4. Zeitzone der Datenbank feststellen und dokumentieren: `SELECT @@global.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP()`.
   Zweck: Grundlage für alle Zeitvergleiche (Befund A-04). Die Klärungsfristen sind seit dem Audit zeitzonenunabhängig;
   Anzeigen historischer Zeitstempel (`created_at` mit CURRENT_TIMESTAMP) hängen weiter von der Serverzone ab.
5. `trusted_proxies` in `shared/config.php` mit dem Adressbereich des Coolify-Proxy-Netzes belegen (Befund C-02, C-03) und
   danach `deploy/vps/scripts/restart-workers.sh` ausführen. Zweck: echte Client-IP für Sperren und Protokolle, Secure-Flag
   direkt aus dem Proxy-Header. Risiko: falscher Bereich bedeutet weiterhin Proxy-IP; die neuen Grenzen setzen dann aus.
6. Stripe-Testkonto: einen vollständigen Einzug im Testmodus ausführen (Sofort, terminiert, Rücklastschrift-Testereignis aus
   dem Dashboard) und prüfen, dass die Webhook-Antworten 200 bzw. 500 (Wiederholung) im Dashboard sichtbar sind.
   Zweck: Verhalten gegen echtes Stripe. Risiko: keines im Testmodus.
7. Migrationen 033 und 034 auf Staging einspielen (`php bin/migrate.php`): 033 ergänzt der Systemrolle `support` das Recht
   `support.customers` (Prüfung: `SELECT permissions FROM platform_roles WHERE code = 'support'` enthält den Code), 034 legt die
   sechs `marketing_*`-Tabellen an und setzt `marketing_rate_per_second` = 1, `marketing_rate_per_day` = 200. Beide additiv und
   wiederholbar (`tools/migrations-check.sh` 12/0 am 11.09.2026). Risiko: keines für bestehende Daten.
8. Mailkonfiguration (`docs/mail-einrichtung.md`): `mail.reply_to` auf `kontakt@smart-einzug.de` oder leer; seit 4.61 wird eine
   Antwortadresse auf fremder Domain ohnehin nicht mehr gesetzt (Eintrag im Fehlerprotokoll). Auf Staging eine Vormerkung
   durchspielen und im Postfach prüfen: `Auto-Submitted`, `List-Unsubscribe`, Schaltfläche „Abbestellen“, One-Click führt zur
   Abmeldung. Zweck: Zustellbarkeit. Risiko: keines.
9. Marketingmodul (`docs/marketing.md`): Block `mail_marketing` in `shared/config.php` vorerst mit `enabled` false lassen, bis
   die SES-Identität `mail.smart-einzug.de` verifiziert, das SES-Konto aus der Sandbox entlassen und das SNS-Thema für
   Rückläufer eingerichtet ist. Ohne aktives Profil lassen sich Kampagnen anlegen und in der Vorschau prüfen, aber weder testen
   noch versenden. Rechte `marketing.view`/`marketing.manage` bewusst über eigene Rollen vergeben (Administrator hat sie).

## 2. Ausrollen (Produktion)

1. Nur über „Run workflow“ auf dem Branch oder neuen Push, nie über „Re-run“ eines alten Laufs (Downgrade-Schutz, 4.45).
2. Not-Stopp der Plattform während des Ausrollens ist nicht nötig (Migration 032 ist additiv, Cutover ohne Datenänderung).
3. Nach dem Cutover: `php bin/healthcheck.php --all`, Adminbereich System (Worker lebend, keine hängenden Jobs), einen
   Blick in `payment_collections` mit `stripe_status = 'submitting'` (sollte leer sein oder jünger als 15 Minuten).
4. Migrationen 033 und 034 laufen isoliert vor dem Cutover; sie ändern keine bestehenden Zeilen außer `platform_roles.support`.
5. Nach dem Cutover `php bin/mail-check.php --zustellbarkeit` im php-Container (erst ab 4.60 verfügbar) und eine Testmail an ein
   Gmail-Postfach mit „Original anzeigen“ (SPF, DKIM, DMARC PASS, `header.d=smart-einzug.de`).
6. Supportbereich: Kundenprofil einer Testfirma öffnen (`admin-kunde.php`), eine Adressänderung mit Grund speichern, Sicherheitsmail
   beim Inhaber und Protokolleintrag `org_updated_support` prüfen. Reiter Marketing: Übersicht zeigt „Profil nicht aktiv“, solange
   `mail_marketing.enabled` false ist.

## 3. Überwachung nach dem Ausrollen (erste 72 Stunden)

- Audit-Aktionen `collection_attempt_cleared` (Versuch nach Klärung freigegeben) und `collection_attempt_recovered`: jede
  Freigabe im Stripe-Dashboard gegenprüfen (Suche nach `metadata.attempt_key`). Zweck: Nachweis, dass die Listenprüfung greift.
- Webhook-Fehlerquote (Stripe-Dashboard, Endpunkt der Firmen): 500-Antworten sind jetzt beabsichtigt für junge Versuche und
  Datenbankfehler; dauerhaft steigende 500 deuten auf ein Datenbankproblem.
- `requires_review` nach `payment_intent.payment_failed` mit Mandats- oder Kontocode: Anzahl der neu markierten Rechnungen;
  die Codeliste `STRIPE_SEPA_RETRYABLE_DECLINE_CODES` ist eine ANNAHME und gegen die Stripe-Dokumentation zu prüfen.
- Container-Healthchecks: Worker melden jetzt während laufender Jobs; ein `unhealthy` ist damit ein echtes Signal.

## 4. Rückfallweg

- Code-Rollback über `deploy/vps/scripts/rollback.sh` auf das vorige Release. Migration 032 ist additiv (Indizes) und muss
  nicht zurückgenommen werden; der alte Code läuft mit den Indizes unverändert.
- Ein Code-Rollback macht eingereichte Lastschriften nicht rückgängig. Vor dem Rollback offene Versuche klären
  (Adminbereich Einzüge, „Unklare Versuche prüfen“ je Firma oder Job `unclear_attempts`).

## 5. Wiederherstellung aus einer Sicherung (Datenverlust)

1. Not-Stopp der Plattform setzen (`platform_settings.collections_paused = 1`), Worker anhalten.
2. Sicherung einspielen. Danach für jede Firma mit Stripe-Verbindung die PaymentIntents seit dem Sicherungszeitpunkt aus
   Stripe lesen (`stripe-import.php`, Lesezugriff) und gegen `payment_collections` abgleichen; fehlende Einzüge nachtragen,
   bevor der nächste Fälligkeitslauf startet. Zweck: ein verlorener lokaler Stand darf keine bereits ausgeführte Lastschrift
   erneut auslösen. Die Idempotenzschlüssel der verlorenen Versuche sind nicht rekonstruierbar; maßgeblich ist der Abgleich.
3. Klärung je Firma laufen lassen (`unclear_attempts`), erst danach Not-Stopp aufheben (2FA).

## 6. Offene Betreiberaufgaben (aus früheren Sitzungen, unverändert)

Stripe-Auszahlungen, `analytics`-Block, externer Totmannschalter (`monitoring.heartbeat_url`), SPF/DKIM/DMARC,
Wiederherstellungstest der Datenbank (`deploy/vps/backup/restore-test.sh`), anwaltliche Prüfung und Veröffentlichung des AVV.
