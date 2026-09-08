# Leitfaden Scharfschaltung (Go-live) mit Stripe-Einrichtung

Stand: 07.09.2026, Softwarestand 4.38. Zielgruppe: Vorstand und Betreiber der Müller Holding AG. Dieser Leitfaden führt in
der richtigen Reihenfolge durch die Inbetriebnahme von SmartEinzug für zahlende Kunden. Jeder Schritt nennt, wo er ausgeführt
wird, wie er geprüft wird und welche Freigabe er braucht. Angaben, die nur der Betreiber am Server bestätigen kann, sind mit
„Nachweis durch Betreiber“ gekennzeichnet. Rechtliche und steuerliche Punkte (AGB, Datenschutzerklärung, AVV, Umsatzsteuer,
Widerruf) sind mit Rechtsanwalt beziehungsweise Steuerberater abzustimmen; der Leitfaden ist eine technische und kaufmännische
Arbeitsanleitung, keine Beratung.

Ausführliche Einzelanleitungen, auf die dieser Leitfaden verweist: Plattform-Abrechnung (`docs/abrechnung.md`),
Cutover-Checkliste (`docs/vps/07-cutover-checkliste.md`), Betrieb (`docs/vps/06-betrieb.md`), Mailversand
(`docs/mail-einrichtung.md`), Statusseite (`docs/status-page.md`), Rechtsdokumente (`docs/rechtsdokumente.md`), sevdesk
(`docs/sevdesk.md`).

## 1. Ausgangslage (Stand 07.09.2026)

| Bereich | Stand | Nachweis |
|---|---|---|
| Anwendung auf dem Hostinger-VPS hinter Coolify, Deployment über GitHub | produktiv, letzte grüne Läufe dokumentiert in `docs/ARBEITSSTAND.md` | Serverausgaben des Betreibers, Deploy-Status |
| Mailversand über smtp.ionos.de | aktiv seit 07.09.2026 (Testmails angekommen) | Betreiber, `bin/mail-check.php` |
| Öffentliche Statusseite | Seite ausgeliefert, Daten über `status_publish`; Wirksamkeit vom Betreiber zu bestätigen | Betreiber |
| Plattform-Abrechnung (Abonnement der Firmen) | Code vollständig, `billing.enabled` noch aus, Stripe-Konto der Müller Holding AG noch nicht eingerichtet | `bin/billing-check.php` |
| Rechtsdokumente AVV und Verschwiegenheit | Entwürfe im Code, keine veröffentlichte Fassung; anwaltliche Prüfung offen | Adminbereich, Rechtsdokumente |
| Zustimmung AGB und Datenschutz bei Registrierung | aktiv (Fassungen agb-2026-09, datenschutz-2026-09) | Rechtliches, Sicherheit |
| Plattform-Benutzer und Rechte | aktiv ab 4.37; Superadmin-Konten tragen die Rolle Administrator | Adminbereich, Benutzer und Rechte |
| sevdesk | Adapter gebaut, Verbindung ab Freigabetermin 30.09.2026 lesend; Einzüge gesperrt bis Bestätigung mit Testkonto | `docs/sevdesk.md` |
| Marketingseiten (smart-einzug.de, Leadseiten) | eigener Arbeitsbereich (Frontend), nicht Teil dieses Leitfadens | Frontend-Team |

## 2. Reihenfolge der Scharfschaltung (Übersicht)

1. Betriebsgrundlagen absichern (Konfiguration, Health, Backups, Monitoring).
2. Rechtsgrundlagen veröffentlichen (AGB, Datenschutz, AVV nach anwaltlicher Prüfung).
3. Stripe-Konto der Müller Holding AG einrichten und Abrechnung im Testmodus durchspielen.
4. Bestehende Firmen schützen, dann `billing.enabled` live schalten.
5. Kundenseite prüfen: Registrierung, Verbindung Lexware Office und Stripe, erster Einzug mit einer eigenen Testfirma.
6. Freigaben dokumentieren, Beobachtungsphase, Nachlauf.

Jeder Schritt wird erst begonnen, wenn der vorherige nachweislich abgeschlossen ist. Ein Rückschritt ist an jeder Stelle
möglich (Abschnitt 8).

## 3. Schritt 1: Betriebsgrundlagen

Ort: VPS (Betreiber per SSH), Adminbereich System.

| Prüfung | Wie | Sollzustand |
|---|---|---|
| Konfiguration `shared/config.php` | Sichtprüfung durch den Betreiber | `environment` auf `prod`, `admin_base_url` und `allowed_hosts` gesetzt, `require_2fa` true, `allow_registration` nach Entscheidung, `mail.enabled` true mit korrekter Absender- und Antwortadresse, `status_publish` gesetzt |
| Health der Container | `php bin/healthcheck.php --all` im php-Container | alle Prüfungen grün; Web `--db`, Scheduler und Worker `--heartbeat`, Metrik-Sammler `--metrics` |
| Redis und Warteschlange | `php bin/healthcheck.php --redis` und Adminbereich System, Reiter Jobs | keine Dead Letter, Worker melden Heartbeat, Redis-Alias erreichbar |
| Monitoring-Empfänger | `monitoring.alert_emails` und `monitoring.editors` in der Konfiguration | mindestens eine Adresse des Betreibers, Testversand über Adminbereich System |
| Backups | Coolify-Backup nach Hetzner Object Storage, Wiederherstellungstest nach `docs/vps/06-betrieb.md`, Abschnitt Backups | ein dokumentierter Restore-Test vor dem Go-live (Nachweis durch Betreiber) |
| Statusseite | status.smart-einzug.de zeigt Komponenten mit Daten statt „Status unbekannt“ | Nachweis durch Betreiber |
| Deployment-Sicherung | GitHub-Variable `VPS_HEALTH_STRICT` auf `true` | ein fehlgeschlagener Health-Check bricht künftige Deployments ab |
| Konfigurationsänderungen | nach jeder Änderung ohne Deployment `deploy/vps/scripts/restart-workers.sh` | Scheduler und Worker lesen die neue Konfiguration |

Freigabe: Betreiber bestätigt die Tabelle schriftlich in `docs/ARBEITSSTAND.md` (Datum, Ergebnis je Zeile).

## 4. Schritt 2: Rechtsgrundlagen

Ort: Adminbereich, Rechtsdokumente (Berechtigung `legal.manage`), Marketingseiten (Frontend).

1. AGB und Datenschutzerklärung auf den Marketingseiten sind die Fassungen, denen Kunden bei der Registrierung zustimmen
   (`AGB_VERSION`, `DATENSCHUTZ_VERSION`). Jede Textänderung heißt: Fassung im Code hochzählen und in
   `docs/einwilligungen.md` archivieren. Ohne diese Abstimmung stimmen Kunden einem anderen Text zu, als angezeigt wird.
2. AVV (Auftragsverarbeitung nach Art. 28 DSGVO): Vorlage im Adminbereich übernehmen, Text anwaltlich prüfen lassen,
   Platzhalter in Anlage 3 ersetzen (Hostinganbieter mit Serverstandort, Sicherungsspeicher). Veröffentlichen ist gesperrt,
   solange `[Platzhalter` im Text steht. Veröffentlichen verlangt den 2FA-Code. Ab dann verlangt die Registrierung den AVV,
   bestehende Firmen akzeptieren unter Rechtliches.
3. Verschwiegenheitsvereinbarung (§ 203 StGB) ebenso; sie gilt nur für Firmen, die die Verschwiegenheitspflicht angeben.
4. Anlage 1 des AVV muss dem Code entsprechen. Der Entwurf 2026-09-entwurf-2 enthält bereits den Abschnitt sevdesk.

Freigabe: Vorstand nach anwaltlicher Prüfung; Datum und Fassung in `docs/ARBEITSSTAND.md` festhalten.

## 5. Schritt 3: Stripe-Konto der Müller Holding AG und Abrechnung im Testmodus

Ort: Stripe-Dashboard (Konto der Müller Holding AG, getrennt von den Stripe-Konten der Kunden), VPS.

Die Einzelschritte stehen in `docs/abrechnung.md`; hier die Reihenfolge mit Prüfpunkten:

| Nr. | Schritt | Prüfung |
|---|---|---|
| 3.1 | Testschlüssel (`sk_test_...`) in `shared/config.php`, Abschnitt `billing`, eintragen; `billing.enabled` bleibt `false` | `php bin/billing-check.php` läuft ohne Fehler (Schlüssel nur maskiert) |
| 3.2 | Stripe Tax aktivieren, deutsche Steuerregistrierung eintragen, Produktsteuercode „Software als Dienstleistung“ im Dashboard prüfen, Kundenportal konfigurieren und speichern | `billing-check` meldet Tax und Portal als eingerichtet |
| 3.3 | Produkt und Preis anlegen: `php bin/billing-setup-stripe.php` (Trockenlauf), dann `--apply`; Betrag, Periode und Bezeichnung kommen aus der Tabelle `plans` (Nettopreis, `tax_behavior = exclusive`, 28-Tage-Periode, `lookup_key = lexsepa_<tarifcode>`) | `plans.stripe_price_id` gefüllt, Audit-Eintrag vorhanden |
| 3.4 | Webhook `https://app.smart-einzug.de/billing-webhook.php` mit genau fünf Ereignissen anlegen (`checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`); Signaturgeheimnis als `billing.stripe_webhook_secret` eintragen | `billing-check` prüft Adresse, Zustand und Ereignisse |
| 3.5 | Vollständiger Durchlauf mit einer Testfirma: Hinweisbalken, Bestellung, Checkout mit Stripe-Testzahlung, Rückkehr, Status `active`, Rechnungsliste, Kundenportal, Kündigung vormerken und zurücknehmen | Status in der Anwendung wechselt über den Webhook; `billing-check` ohne Fehler |

Steuerliche Einordnung (Umsatzsteuer auf Nettopreise, Reverse Charge bei EU-Kunden) vor Schritt 3.6 mit dem Steuerberater
abstimmen.

## 6. Schritt 4: Bestehende Firmen schützen und live schalten

1. `bin/billing-check.php` nennt alle Firmen ohne nutzbares Abonnement. Für jede entscheiden: `billing_exempt` setzen
   (Adminbereich, Firmen, Berechtigung `companies.plan`), Abonnement vorher abschließen lassen oder Zeitpunkt so wählen, dass
   niemand unvorbereitet gesperrt wird. Eigene Gesellschaften der Gruppe: `billing_exempt`.
2. Live-Schlüssel (`sk_live_...`) und Live-Signaturgeheimnis eintragen; Produkt und Preis im Live-Konto anlegen
   (`--apply --live-bestaetigt`), Webhook im Live-Konto anlegen; `billing-check` ohne Fehler.
3. `billing.enabled = true` setzen, `restart-workers.sh` ausführen, sofort erneut `billing-check`, Hinweisbalken und eine
   Bestellung mit einem eigenen Account kontrollieren.
4. Rücknahme jederzeit: `billing.enabled = false` (Abschnitt 8).

Freigabe: Vorstand (Zahlungszusage gegenüber Kunden, Preis laut Tabelle `plans`, Einführungspreis mit rollierendem Stichtag).

## 7. Schritt 5: Kundenseite prüfen (eigene Testfirma)

| Prüfung | Erwartung |
|---|---|
| Registrierung mit Zustimmung AGB und Datenschutz, Willkommensmail mit Bestätigungslink, 2FA-Einrichtung | Zustimmungen unter Rechtliches und Sicherheit sichtbar, Mail kommt an |
| Lexware Office verbinden (API-Schlüssel), Verbindung prüfen, erste Synchronisation | Rechnungen und Kunden erscheinen, Synchronisationsverlauf gefüllt |
| Stripe der Testfirma verbinden (eigenes Konto der Firma, zunächst Testmodus), Webhook-Geheimnis eintragen | Testmodus-Banner sichtbar, Verbindungstest grün |
| SEPA-Mandat erzeugen, IBAN hinterlegen, Sofort-Einzug einer kleinen Rechnung | Restbetrag wird vor dem Einzug bei Lexware Office abgerufen, Einzug erscheint mit Status, Webhook-Ereignis verarbeitet |
| Not-Stopp je Firma aktivieren und wieder aufheben (Aufheben mit 2FA-Code) | keine Einreichung während des Stopps, Aufhebung protokolliert |
| Abonnement bestellen (falls `billing.enabled`) | Status `active`, Rechnung im Kundenportal |
| Support-Modus aus dem Adminbereich (Berechtigung `support.sessions`, Grund, 2FA-Code) | Sicherheitsmail an den Inhaber, Sperren im Support-Modus wirksam |
| Mobilansicht (390 px) der Hauptseiten | kein seitliches Scrollen |

Freigabe: Betreiber protokolliert Datum und Ergebnis; erst danach Werbung und Registrierung für Dritte öffnen.

## 8. Rücknahme und Not-Aus

| Situation | Maßnahme | Wirkung |
|---|---|---|
| Abrechnung stoppen | `billing.enabled = false`, `restart-workers.sh` | keine Sperre, kein Hinweisbalken; bestehende Stripe-Abonnements laufen weiter und werden im Dashboard gekündigt |
| Alle Einzüge stoppen | Adminbereich, Not-Stopp (Plattform) aktivieren (ohne Hürde); Aufheben mit 2FA-Code | keine neuen Einreichungen für alle Firmen, laufende Vorgänge bleiben nachvollziehbar |
| Einzüge einer Firma stoppen | Firma selbst über Not-Stopp, oder Betreiber pausiert die Synchronisation (Adminbereich System, mit 2FA-Code) | wie oben je Firma |
| sevdesk anhalten | `sevdesk_connect = 0` in `platform_settings` | keine Verbindung und kein Abruf; `sevdesk_collections` bleibt ohnehin 0 bis zur Bestätigung |
| Fehlerhaftes Release | `deploy/vps/scripts/rollback.sh` auf dem VPS | vorheriges Release aktiv; Migrationen sind additiv und rückwärtsverträglich |

## 9. Beobachtungsphase und Nachlauf

- Mindestens 24 Stunden erhöhte Aufmerksamkeit: Adminbereich System (Dienste, Jobs, Dead Letter, Circuit Breaker),
  Alarmmails, Statusseite.
- `bin/billing-check.php` nach jeder Änderung an Tarifen, Schlüsseln oder Webhooks.
- Wiederherstellungstest der Datenbank vierteljährlich wiederholen und im Arbeitsstand vermerken.
- sevdesk: vor `sevdesk_api_verified = 1` und `sevdesk_collections = 1` zwingend Testkonto beschaffen und die Prüffragen des
  Endpunktregisters abarbeiten (`docs/sevdesk.md`).
- Offene Betreiberpunkte zum Stand 07.09.2026: DNS-Nachweis der Domains, Wiederherstellungstest, anwaltliche Prüfung der
  Rechtsdokumente, Stripe-Konto der Müller Holding AG, sevdesk-Testkonto, externer Erreichbarkeitsprüfer für die Statusseite.

## 10. Freigabematrix

| Schritt | Freigabe durch | Nachweis |
|---|---|---|
| Betriebsgrundlagen | Betreiber | Tabelle Abschnitt 3 im Arbeitsstand |
| Rechtsgrundlagen | Vorstand nach anwaltlicher Prüfung | veröffentlichte Fassungen im Adminbereich, Datum im Arbeitsstand |
| Stripe Testmodus | Betreiber | `billing-check` ohne Fehler, Testdurchlauf protokolliert |
| `billing.enabled` live | Vorstand | Datum, Uhrzeit, `billing-check` nach dem Umschalten |
| Öffnung für Dritte (Werbung, Registrierung) | Vorstand | Ergebnis Abschnitt 7 |
| sevdesk Einzüge | Vorstand nach Bestätigung mit Testkonto | Prüffragen aus dem Endpunktregister beantwortet |
