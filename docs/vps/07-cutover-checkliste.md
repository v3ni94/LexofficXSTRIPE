# Cutover-Checkliste

Stand: 06.09.2026 (Auftrag III), ergänzt für den Hostinger-VPS (Nachtrag, siehe
`docs/auftrag-iii-abschluss.md`). Abzuarbeiten für jeden Produktions-Cutover einer oder mehrerer
Firmen vom IONOS-Webhosting auf den Hostinger-VPS. Jeden Punkt tatsächlich prüfen, nicht nur
abhaken; bei einem gescheiterten Punkt den Cutover anhalten und die Ursache klären, bevor der
nächste Phasenschritt beginnt. Zum Stand dieses Nachtrags wurde noch nichts produktiv
eingerichtet und kein DNS-Eintrag geändert (siehe `docs/vps/08-hostinger-coolify.md`, Tabelle
„Status“).

## Phasenplan (1 bis 12)

1. **Vorbereitung:** VPS eingerichtet (`docs/vps/02-einrichtung-vps.md`), GitHub-Deployment
   getestet (`docs/vps/03-github-deployment.md`), DNS-TTL gesenkt (`docs/vps/05-dns-ssl.md`).
2. **Erstimport:** Datenbank-Dump importiert, erste Prüfungen bestanden
   (`docs/vps/04-datenbankmigration.md`, Schritte 1 bis 8).
3. **Funktionstest auf dem VPS:** Test mit einer nicht kritischen Firma oder Testfirma
   (`docs/vps/04-datenbankmigration.md`, Schritt 9).
4. **Ankündigung:** Betroffene Firmen (sofern Bestandsfirmen betroffen sind) über das
   Wartungsfenster informieren, Zeitpunkt und erwartete Dauer nennen.
5. **Wartungsmodus Webhosting AN**, letzter Datenabgleich (Delta-Dump, Import auf dem VPS,
   `docs/vps/04-datenbankmigration.md`, Schritt 10).
6. **DNS-Umstellung** (bereits vorbereitet, TTL niedrig; falls noch nicht geschehen, jetzt
   umstellen und Ausbreitung abwarten).
7. **Wartungsmodus VPS AUS.**
8. **Prüfpunkte dieser Checkliste** (Abschnitt unten) vollständig abarbeiten.
9. **`admin_base_url` und `allowed_hosts`** in der VPS-Konfiguration final setzen, sofern noch im
   Übergangszustand.
10. **`VPS_HEALTH_STRICT` auf `true`** setzen (ab jetzt bricht ein fehlgeschlagener Health-Check
    künftige Deployments ab, siehe `docs/vps/03-github-deployment.md`).
11. **Beobachtungsphase:** mindestens 24 Stunden erhöhte Aufmerksamkeit (Logs, Adminbereich
    System, Dead-Letter-Liste, Circuit Breaker).
12. **Nachlauf:** alte Webhosting-Datenbank einige Tage als Referenz halten, danach Zugangsdaten
    entfernen (`docs/vps/04-datenbankmigration.md`, Schritt 11); DNS-TTL wieder erhöhen.

## Prüfpunkte (abhakbar)

### Zugang und Anmeldung

- [ ] Login mit bestehendem Benutzer funktioniert (Passwort, 2FA-Code).
- [ ] Login mit einem Gerät, das zuvor als „90 Tage merken“ hinterlegt wurde, überspringt die
      2FA-Abfrage erwartungsgemäß (`docs/device-trust.md`).
- [ ] Registrierung eines neuen Benutzers (Testkonto) funktioniert vollständig, inklusive
      E-Mail-Bestätigung, sofern `mail.enabled = true`.
- [ ] Passwort-Zurücksetzen funktioniert (Testkonto).

### Firma wechseln, Berechtigungen

- [ ] Firmenwechsel (Multiaccount) funktioniert für einen Benutzer mit mehreren Firmen
      (`docs/multiaccount.md`).
- [ ] Rollen wirken wie erwartet: Mitarbeiter sieht keine API-Verbindungen/Firmendaten,
      Administrator und Inhaber schon.
- [ ] Jede Aktion erscheint korrekt im Protokoll (`audit_log`) mit dem richtigen Benutzer.

### Admin

- [ ] Adminbereich (`admin.smart-einzug.de`) erreichbar, `app.smart-einzug.de` liefert für
      `admin.php` 404 (Host-Trennung wirksam).
- [ ] Adminbereich System zeigt Jobs, Server (inklusive Host-Metriken), Versionen, Dokumentation.
- [ ] Support-Zugriff („Auf Firma wechseln“) funktioniert, Sicherheits-E-Mail an den Inhaber wird
      versendet.

### Lexware-Verbindung

- [ ] Bestehende Lexware-Office-Verbindung einer Testfirma bleibt nach dem Umzug verbunden
      (`lexoffice_connected = 1`, kein erneuter API-Schlüssel nötig).
- [ ] Manuelle Synchronisation liefert dieselben Rechnungen wie vor dem Umzug (Stichprobe).

### Stripe-Verbindung

- [ ] Bestehende Stripe-Verbindung bleibt verbunden.
- [ ] Webhook-Endpunkt `https://api.smart-einzug.de/stripe-webhook.php` ist im jeweiligen
      Stripe-Konto zusätzlich hinterlegt und empfängt ein Test-Event (Stripe Dashboard >
      Entwickler > Webhooks > Testereignis senden).
- [ ] Im Stripe-Dashboard (Entwickler > Webhooks) die während des Wartungsfensters fehlgeschlagenen
  Ereignisse (Antwort 503) für beide Endpunkte erneut senden; danach Einzugsstatus und Abo-Status
  stichprobenartig prüfen. Hintergrund: Im Wartungsmodus antworten Webhooks bewusst mit 503, damit
  nichts mehr in die alte Datenbank geschrieben wird; Stripe wiederholt Ereignisse nur begrenzt.
- [ ] Plattform-Webhook `https://api.smart-einzug.de/billing-webhook.php` funktioniert
      entsprechend (Stripe-Konto der Müller Holding AG).

### Sync, Queue, Worker

- [ ] Synchronisation einer Testfirma über die Warteschlange (Feature-Flag `queue` aktiv) läuft
      vollständig durch, Fortschrittsanzeige aktualisiert sich.
- [ ] `bin/healthcheck.php --all` liefert Exit-Code 0.
- [ ] Adminbereich System > Jobs zeigt keine unerwarteten Einträge in „Fehlgeschlagen“.
- [ ] Circuit Breaker aller drei Anbindungen stehen auf `closed`.

### Mail

- [ ] Testmail über den Mailversand der Anwendung kommt an (monitoring.test_mail_to oder eine
      Einladungsmail an ein Testkonto).

### Migration

- [ ] `bin/migrate.php --status` zeigt ausschließlich `success`.
- [ ] Kein Eintrag `failed` oder `unknown` in `schema_migrations`.

### DB

- [ ] `db-verify.php` liefert auf VPS und (letztmalig) Webhosting identische Zeilenzahlen und
      Prüfsummen für alle Stammdatentabellen (`docs/vps/04-datenbankmigration.md`, Schritt 5/6).
- [ ] Fremdschlüssel und Indizes vollständig (Schritt 7/8 desselben Runbooks).

### Benutzerrechte

- [ ] Datenbankbenutzer der Anwendung auf dem VPS hat keine Rechte über die eigene Datenbank
      hinaus (kein `GRANT ALL` auf `*.*`).
- [ ] Kein veröffentlichter Datenbank- oder Redis-Port aus dem Internet erreichbar
      (`nc -zv HIER-VPS-IP 3306` und `6379` schlagen von außen fehl).

### Abonnement

- [ ] Firmen mit aktivem Abo (`billing.enabled`) sehen ihren Status unter Firma > Abonnement
      korrekt (Tarif, Periodenende, Rechnungsarchiv aus Stripe).
- [ ] Ein Testkauf im Stripe-Testmodus (sofern zu diesem Zeitpunkt noch nicht produktiv scharf
      geschaltet, siehe `php-ionos/ANLEITUNG-IONOS.md`, Abschnitt 6) funktioniert unverändert.

### Health Checks

- [ ] `https://app.smart-einzug.de/health.php` liefert `"php": true` mit aktuellem Zeitstempel.
- [ ] Externer Health-Check des GitHub-Workflows (`VPS_HEALTH_STRICT`) ist scharf geschaltet und
      war beim letzten Deployment erfolgreich.

### Backup

- [ ] Mindestens eine erfolgreiche Coolify-Sicherung der Datenbank nach dem Cutover, sichtbar in
      Coolify und, sofern `COOLIFY_BACKUP_DIR` eine lokale Kopie liefert, als Komponente
      „Sicherungen“ im Adminbereich System.
- [ ] Externer Upload der Sicherung nach Hetzner Object Storage weiterhin erfolgreich.
- [ ] Wiederherstellungstest gegen einen aus Coolify heruntergeladenen Dump nach dem Cutover
      erfolgreich (`deploy/vps/backup/restore-test.sh` in einem kurzlebigen Client-Container im
      Netz `coolify`, siehe `docs/vps/06-betrieb.md`).

## Bei Abbruch

Ergibt sich in Phase 6 bis 8 ein nicht behebbarer Fehler: DNS zurück auf das Webhosting stellen
(sofern bereits umgestellt), Wartungsmodus des Webhostings ausschalten, Ursache in Ruhe klären.
Die alte Webhosting-Datenbank wurde bis zu diesem Zeitpunkt nicht verändert (nur gelesen für den
Export), ein Zurückrollen ist daher ohne Datenverlust möglich, solange Phase 12 (Entfernen der
Zugangsdaten) noch nicht erreicht wurde.
