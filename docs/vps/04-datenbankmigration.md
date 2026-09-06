# Runbook: Datenbankmigration Webhosting-MariaDB nach Coolify-MariaDB

Stand: 06.09.2026 (Auftrag III), ergänzt für die Coolify-MariaDB (siehe
`docs/auftrag-iii-abschluss.md`, Nachtrag Coolify-MariaDB). Gilt für den einmaligen Umzug von
Bestandsdaten einer oder mehrerer Firmen vom IONOS-Webhosting (MariaDB, ältere Version, siehe
dortige Hosting-Dokumentation) auf die Zieldatenbank auf dem Hostinger-VPS: eine bereits
eingerichtete, private Coolify-Datenbankressource (MariaDB 11.8.9, Datenbank `smarteinzug`).
Werkzeuge: `deploy/vps/scripts/db-import.sh`, `deploy/vps/scripts/db-verify.php`,
`deploy/vps/scripts/maintenance.sh`. Kein Fallback im Code: Nach dem Cutover liest und schreibt die
Anwendung ausschließlich die VPS-Datenbank, es gibt keinen automatischen Rückgriff auf das
Webhosting. Kein produktiver Import und keine produktive Migration waren zum Stand dieses
Dokumentationsnachtrags erfolgt (auf dem Server zu prüfen).

Voraussetzung: Der VPS-Stack läuft bereits (`docs/vps/02-einrichtung-vps.md`, Schritte 1 bis 14),
DNS ist noch NICHT umgestellt (folgt in Schritt 9 dieses Runbooks, siehe `docs/vps/05-dns-ssl.md`).

## Vorbereitung: TTL senken

Mindestens 24 Stunden vor dem geplanten Cutover die TTL der betroffenen DNS-Einträge
(`app`, `admin`, `api`, `status`) im IONOS Kundenbereich auf 300 Sekunden senken, damit die
spätere Umstellung schnell wirksam wird. Nach dem Cutover kann die TTL wieder erhöht werden.

## Die elf Schritte

### 1. Backup des Webhostings ziehen

Über phpMyAdmin (Export, Format SQL, „Vollständiger Insert“) oder, falls SSH-Zugang zum Webhosting
besteht, `mysqldump`. Ergebnis: eine `.sql`- oder `.sql.gz`-Datei mit vollständigem Datenbestand
der Webhosting-Datenbank.

**Prüfkommando:** Dateigröße plausibel (nicht 0 Byte), Datei lässt sich mit `gunzip -t` (falls
komprimiert) oder `head` (Anfang zeigt `-- MySQL dump` oder ähnliche Kopfzeile) öffnen.

### 2. Prüfsumme bilden

```bash
sha256sum smarteinzug-webhosting.sql.gz > smarteinzug-webhosting.sql.gz.sha256
```

Beide Dateien gemeinsam auf den VPS übertragen (z. B. `scp`), niemals über einen ungesicherten
Kanal. Die Prüfsumme stellt sicher, dass eine spätere Beschädigung oder ein unvollständiger
Transfer beim Import auffällt, statt unbemerkt eine Teilkopie einzuspielen.

### 3. Zieldatenbank anlegen bzw. bereitstellen

Die Zieldatenbank existiert bereits als eigene, private Coolify-Datenbankressource
(`docs/vps/08-hostinger-coolify.md`), kein eigener Dienst im SmartEinzug-Stack. Vor dem Import:
sicherstellen, dass die Zieldatenbank leer ist oder ausschließlich mit dem aktuellen Schema
(`sql/schema.sql` und alle Migrationen) befüllt wurde, nicht mit abweichenden Testdaten.

```bash
cd /opt/smarteinzug/deploy
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec php php bin/migrate.php --status
```

**Erwartetes Ergebnis:** alle Migrationen `success`, keine Firmendaten außer eventuellen
Testeinträgen, die vor dem Import bewusst entfernt werden.

### 4. Dump importieren

```bash
cd /opt/smarteinzug/deploy
bash scripts/db-import.sh /pfad/smarteinzug-webhosting.sql.gz /pfad/smarteinzug-webhosting.sql.gz.sha256
```

Das Skript prüft zuerst die Prüfsumme und den Containernamen (`DB_CONTAINER` aus `.env`, siehe
`docs/vps/02-einrichtung-vps.md`, Schritt 10), prüft die Erreichbarkeit der Coolify-MariaDB per
`docker exec`, fragt vor dem eigentlichen Import ausdrücklich nach Bestätigung (`Import
überschreibt vorhandene Tabellen ...`) und spielt den Dump danach per `docker exec -i` in den
Coolify-MariaDB-Container ein. Es nutzt dafür die Umgebungsvariablen `MARIADB_USER`,
`MARIADB_PASSWORD`, `MARIADB_DATABASE`, die Coolify dem Datenbank-Container mitgibt (auf dem Server
zu prüfen: `docker exec <containername-der-coolify-mariadb> env | grep MARIADB_`); die
Zugangsdaten werden dabei weder abgefragt noch protokolliert. Ein veröffentlichter Port ist dafür
nicht nötig.

**Erwartetes Ergebnis:** Ausgabe „Import abgeschlossen“, keine SQL-Fehlermeldungen.
**Mögliche Fehler:** „Prüfsumme stimmt nicht überein“ (Übertragung wiederholen, Datei nicht
verwenden); Containername in `DB_CONTAINER` falsch oder Container nicht gefunden (`docker ps`
prüfen); Datenbank antwortet nicht (Status der Coolify-Datenbankressource in Coolify prüfen).

### 5. Tabellen prüfen

```bash
docker compose exec php php /opt/smarteinzug/deploy/../releases/current/../current/../../deploy/vps/scripts/db-verify.php ...
```

Praktischer: `db-verify.php` läuft eigenständig (kein Anwendungs-Bootstrap nötig) direkt gegen
eine Konfigurationsdatei mit Datenbankzugangsdaten:

```bash
php deploy/vps/scripts/db-verify.php /opt/smarteinzug/shared/config.php > neu.json
```

Denselben Befehl VOR dem Import bereits gegen die Webhosting-Datenbank ausführen (auf einem
Rechner mit Netzwerkzugriff auf das Webhosting, mit einer eigenen kleinen Konfigurationsdatei nach
demselben Schema `['host' => ..., 'name' => ..., 'user' => ..., 'pass' => ...]`), Ergebnis als
`alt.json` sichern.

**Prüfkommando:** `diff <(jq .tables alt.json) <(jq .tables neu.json)` liefert keine Abweichung
außer bei Tabellen, die sich zwischen Backup-Zeitpunkt (Schritt 1) und Prüfzeitpunkt durch
laufenden Betrieb des Webhostings geändert haben (siehe Schritt 10, Delta).

### 6. Zeilenzahlen abgleichen

Bereits Teil der Ausgabe von `db-verify.php` (`rows` je Tabelle). Abweichungen bei Tabellen mit
hoher Schreibfrequenz (`monitor_checks`, `job_runs`, `audit_log`) sind im laufenden Betrieb normal
und kein Fehler; bei Stammdatentabellen (`organizations`, `users`, `customers`, `invoices`,
`sepa_mandates`) müssen die Zahlen exakt übereinstimmen.

### 7. Fremdschlüssel prüfen

```bash
docker exec <containername-der-coolify-mariadb> sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_SCHEMA = DATABASE();"'
```

**Erwartetes Ergebnis:** Liste entspricht den `CONSTRAINT`-Namen aus `php-ionos/sql/schema.sql`
(z. B. `fk_customer_org`, `fk_invoice_customer`, `fk_mandate_customer`), keine fehlenden
Fremdschlüssel (deutet auf einen unvollständigen Import hin).

### 8. Indizes prüfen

```bash
docker exec <containername-der-coolify-mariadb> sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW INDEX FROM invoices;"'
```

Stichprobenartig für die wichtigsten Tabellen (`invoices`, `customers`, `payment_collections`,
`jobs`) wiederholen; die Indexnamen müssen mit `sql/schema.sql` übereinstimmen. Ein Import über
`mariadb <` aus einem vollständigen `mysqldump` überträgt Indizes automatisch mit; diese Prüfung
fängt einen unvollständigen oder manuell bearbeiteten Dump ab.

### 9. Test der Anwendung auf dem VPS

Mit einer Testfirma (oder, sofern vertretbar, einer nicht kritischen Bestandsfirma) auf dem VPS
anmelden, Rechnungen und Kunden ansehen, testweise eine Synchronisation anstoßen (im Stripe-
Testmodus, keinen echten Einzug auslösen). Erst nach erfolgreichem Test die DNS-Umstellung
vorbereiten (`docs/vps/05-dns-ssl.md`); DNS zu diesem Zeitpunkt technisch bereits möglich, aber
der eigentliche Cutover (Schritt 10) sollte erst nach diesem Test erfolgen.

### 10. Delta und Cutover

Zwischen Schritt 1 (Backup-Zeitpunkt) und dem eigentlichen Umschalten vergeht Zeit, in der das
Webhosting weiterhin Änderungen entgegennimmt (neue Rechnungen aus der Synchronisation, neue
Einzüge). Diese Lücke wird durch einen zweiten, aktuelleren Dump kurz vor dem Cutover geschlossen:

1. Wartungsmodus auf dem Webhosting aktivieren (`app/storage/maintenance.flag` anlegen oder
   `maintenance_mode` in der Webhosting-`config.php`, je nachdem, was dort eingerichtet ist),
   damit während des Deltas keine neuen Schreibvorgänge mehr entstehen.
2. Letzten, vollständigen Dump ziehen (wie Schritt 1 und 2, diesmal mit Wartungsmodus aktiv, also
   garantiert konsistent mit dem letzten Schreibzugriff).
3. Diesen letzten Dump auf dem VPS importieren (wie Schritt 4, ersetzt den vorläufigen Import aus
   Schritt 4 vollständig).
4. DNS ist zu diesem Zeitpunkt bereits auf den VPS umgestellt (niedrige TTL aus der Vorbereitung
   greift; DNS-Umstellung selbst kann bereits vor dem Wartungsmodus erfolgen, da die alte Adresse
   durch den Wartungsmodus ohnehin keine neuen Schreibvorgänge mehr zulässt).
5. Wartungsmodus auf dem VPS ausschalten (`bash scripts/maintenance.sh off`).
6. Erneuter Abgleich (`db-verify.php` auf beiden Seiten, Schritte 5 bis 8) bestätigt: kein
   Unterschied mehr außer den technisch erwarteten (siehe Schritt 6).

Kein paralleles Schreiben in zwei Datenbanken: Zwischen dem Aktivieren des Wartungsmodus auf dem
Webhosting (Schritt 10.1) und dem Ausschalten auf dem VPS (Schritt 10.5) darf keine der beiden
Umgebungen für die betroffene(n) Firma(en) produktiv Schreibzugriffe verarbeiten.

### 11. Alte Datenbank schreibgeschützt halten

Nach erfolgreichem Cutover die Webhosting-Datenbank nicht sofort löschen, sondern als Referenz
schreibgeschützt vorhalten (Benutzerrechte auf `SELECT` reduzieren, sofern das Hosting das
zulässt, oder den Anwendungszugriff durch Entfernen des Datenbank-Passworts aus der Webhosting-
`config.php` unterbinden). Empfehlung: einige Tage als Referenz behalten (Rückfragen, Abgleich bei
Unstimmigkeiten), danach Zugangsdaten der alten Datenbank entfernen (Benutzer in der
IONOS-Datenbankverwaltung löschen oder Passwort ändern und nicht weitergeben). Es gibt keinen
automatischen Fallback im Code auf die alte Datenbank; ihr Entfernen hat auf die Anwendung selbst
keine Auswirkung, sobald der Cutover abgeschlossen ist.

## Zusammenfassung des zeitlichen Ablaufs

```
TTL senken (≥ 24 h vorher)
  -> Schritte 1-9 (vorläufiger Import und Test, Webhosting bleibt produktiv)
  -> Wartungsmodus Webhosting AN
  -> letzter Dump + Prüfsumme
  -> DNS auf VPS (falls noch nicht geschehen)
  -> Import des letzten Dumps auf dem VPS
  -> Wartungsmodus VPS AUS
  -> abschließender Abgleich (db-verify.php)
  -> alte Datenbank schreibgeschützt, einige Tage als Referenz
  -> Zugangsdaten der alten Datenbank entfernen
```

Weiterführend: `docs/vps/07-cutover-checkliste.md` für die vollständige fachliche Abnahme nach dem
Cutover (Login, Synchronisation, Einzüge, Berechtigungen), nicht nur den Datenbankabgleich.
