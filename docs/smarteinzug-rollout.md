# SmartEinzug Rollout, Domains und Hosts (Paket E), Stand 05.09.2026

Grundlage: Repository-Stand (`websites/`, `php-ionos/`, `docs/bestandsmatrix.md`). Keine IP-Adressen, DNS-Einträge oder Zertifikatsdaten erfunden, diese sind bei IONOS zu prüfen.

## 1. Domainmatrix (9 Hosts)

| Nr. | Host | Aufgabe | Ordnerzuordnung IONOS (Ziel) | Nachweis im Repository |
|---|---|---|---|---|
| 1 | smart-einzug.de | Neue Hauptwebsite (Marke SmartEinzug) | `/smart-einzug.de` | `websites/smart-einzug.de/` |
| 2 | www.smart-einzug.de | Weiterleitung auf smart-einzug.de (kanonisch ohne www) | eigener Ordner mit `.htaccess`, 301 | zu verifizieren bei IONOS |
| 3 | app.smart-einzug.de | Künftiger App-Host (Kunden-Login, Registrierung) | bestehender App-Ordner (`php-ionos/`) als Zusatz-Domain | `app/config.php`, Bestandsmatrix „Getrennte public_base_url, app_base_url, admin_base_url" |
| 4 | admin.smart-einzug.de | Adminbereich, getrennt vom Kundenhost | bestehender App-Ordner, Auslieferung nur für Adminrouten | Bestandsmatrix „Nur auf admin.smart-einzug.de ausliefern, sonst 404" |
| 5 | lexware-einzug.de | Bestandswebsite, bleibt aktiv, künftig ggf. Alias/Redirect-Kandidat | `/lexware-einzug.de` (unverändert) | `websites/lexware-einzug.de/` |
| 6 | lexoffice-einzug.de | Bestandswebsite, bleibt aktiv | `/lexoffice-einzug.de` (unverändert) | `websites/lexoffice-einzug.de/` |
| 7 | app.lexware-einzug.de | Bestehender App-Host, bleibt als Alt-Einstiegspunkt gültig | bestehender App-Ordner (unverändert) | Bestandsmatrix „Alte Endpunkte bleiben" |
| 8 | einzug-direkt.de | Redirect-Alias auf smart-einzug.de | eigener Ordner, nur `.htaccess` und `404.html` | `websites/aliases/einzug-direkt.de/` |
| 9 | smart-lastschrift.de / lastschrift-einfach.de / smarteinzug.de | Weitere Redirect-Aliase auf smart-einzug.de | je eigener Ordner, nur `.htaccess` und `404.html` | `websites/aliases/smart-lastschrift.de/`, `.../lastschrift-einfach.de/`, `.../smarteinzug.de/` |

Anmerkung: Im Repository liegen vier Alias-Ordner (`einzug-direkt.de`, `smart-lastschrift.de`, `lastschrift-einfach.de`, `smarteinzug.de`), siehe Bestandsmatrix „Redirect-Domains (4 Aliase)". Zusammen mit den fünf produktiven Domains (smart-einzug.de inkl. www, app, admin, lexware-einzug.de, lexoffice-einzug.de, app.lexware-einzug.de) ergibt sich die Neun-Host-Übersicht dieser Tabelle. Welche der genannten Domains tatsächlich bei IONOS registriert sind, ist dort zu prüfen.

## 2. IONOS-Schritte

1. **Domains anlegen**: Alle neun Hosts im IONOS-Kundenkonto als Domain beziehungsweise Subdomain anlegen beziehungsweise vorhandene Zuordnungen prüfen. Reihenfolge unkritisch, DNS-Ausbreitung vorab einplanen (Zeitpuffer, keine feste Frist ohne Bestätigung durch IONOS-Support ansetzen).
2. **Ordner zuordnen**:
   - `smart-einzug.de` (und `www.smart-einzug.de` als 301) auf den Ordner `/smart-einzug.de`.
   - Jeder Alias (`einzug-direkt.de`, `smart-lastschrift.de`, `lastschrift-einfach.de`, `smarteinzug.de`) auf einen eigenen, separaten Ordner, der ausschließlich `.htaccess` (301-Regeln je Pfad) und `404.html` enthält. Kein gemeinsamer Ordner mit smart-einzug.de, damit keine Inhalte doppelt indexiert werden.
   - `app.smart-einzug.de` und `admin.smart-einzug.de` als zusätzliche Domainzuordnung auf den bestehenden App-Ordner (`php-ionos/`), analog zur heutigen Zuordnung von `app.lexware-einzug.de`.
   - `lexware-einzug.de` und `lexoffice-einzug.de` bleiben unverändert auf ihren bestehenden Ordnern.
3. **SSL je Host**: Für jeden der neun Hosts ein eigenes SSL-Zertifikat (IONOS-Standardzertifikat oder Let's-Encrypt-Automatik, je nach Tarif) aktivieren, bevor der Host produktiv geschaltet wird. Ohne gültiges Zertifikat kein Aufruf über https vorsehen. Zertifikatsstatus ist bei IONOS zu prüfen, hier nicht vorwegzunehmen.
4. **Aktivierungsreihenfolge** (siehe Abschnitt 3).
5. Nach jeder Umschaltung: `curl`-Prüfung der Redirect-Matrix (siehe Abschnitt 4) und Sichtprüfung im Browser.

## 3. Aktivierungsreihenfolge

1. **Website zuerst**: `smart-einzug.de` produktiv schalten (Ordner, DNS, SSL), Inhalte prüfen, Alte Domains (`lexware-einzug.de`, `lexoffice-einzug.de`) bleiben parallel unverändert online.
2. **App-Hosts als Zusatz**: `app.smart-einzug.de` als zusätzliche Domain auf den bestehenden App-Ordner legen, dabei `allowed_hosts` in der App-Konfiguration um den neuen Host erweitern (siehe Bestandsmatrix „Host-Allowlist"). Der bisherige Host `app.lexware-einzug.de` bleibt zu diesem Zeitpunkt weiterhin die aktive `app_base_url` für alle bestehenden Links.
3. **`base_url`-Wechsel**: Erst wenn `app.smart-einzug.de` erreichbar, mit gültigem Zertifikat läuft und die Host-Allowlist geprüft ist, wird `app_base_url` in der Konfiguration auf `https://app.smart-einzug.de` umgestellt. Ab diesem Zeitpunkt erzeugt die App neue Links (Registrierung, Login, E-Mails) auf dem neuen Host. Bestehende, bereits verschickte Links auf `app.lexware-einzug.de` bleiben nutzbar (siehe Abschnitt 5).
4. **Admin-Host danach**: `admin.smart-einzug.de` erst aktivieren, wenn die Trennung „Kundenrouten auf Adminhost sperren" (Bestandsmatrix, Paket C) umgesetzt und getestet ist, damit auf dem Adminhost keine Kundenrouten ausgeliefert werden.
5. Aliase (Abschnitt 1, Nr. 8 und 9) können unabhängig von dieser Reihenfolge jederzeit auf smart-einzug.de weiterleiten, sobald ihre `.htaccess`-Ordner eingerichtet sind.

## 4. Kompatibilität

- **Alte Links bleiben gültig**: `app.lexware-einzug.de` wird nach dem `base_url`-Wechsel nicht abgeschaltet oder umgeleitet. Bereits registrierte Konten, verschickte E-Mail-Links (Bestätigung, Passwort-Reset) und Lesezeichen funktionieren unverändert weiter, solange der alte Host DNS-technisch bestehen bleibt.
- **Webhooks alt und neu**: Die bestehenden Endpunkte `stripe-webhook.php` und `billing-webhook.php` bleiben unter ihrer bisherigen Host-Adresse erreichbar. Für POST-Anfragen (Webhooks) wird keine 301-Weiterleitung eingerichtet, da die meisten Webhook-Clients Redirects bei POST nicht automatisch verfolgen (siehe Bestandsmatrix „keine 301 auf POST"). Wird zusätzlich ein Webhook-Ziel unter dem neuen Host benötigt, ist dies bei Stripe als zweiter Endpunkt anzulegen, nicht als Ersatz des bestehenden.
- **Redirect-Matrix der Aliase**: Jeder Alias leitet mit 301 auf `https://smart-einzug.de` weiter, mit Pfad-Mapping wo vorhanden, sonst auf die Startseite beziehungsweise `404.html` bei unbekanntem Pfad (siehe Bestandsmatrix „Pfad-Mapping, 404 sonst").

## 5. Rollback

- **`base_url` zurück**: Sollte sich nach der Umstellung ein Problem zeigen, wird `app_base_url` in der Konfiguration auf den bisherigen Wert `https://app.lexware-einzug.de` zurückgesetzt. Die App erzeugt danach wieder Links auf dem bisherigen Host.
- **DNS bleibt**: Ein Rollback erfordert keine Rücknahme der DNS-Einträge oder Ordnerzuordnungen bei IONOS. `app.smart-einzug.de` kann als Domain bestehen bleiben, ohne dass die App aktiv Links darauf ausstellt. Dadurch ist der Rollback ohne Wartezeit für DNS-Ausbreitung möglich.
- Ein Rollback der Website (`smart-einzug.de`) selbst ist über die Ordnerzuordnung bei IONOS möglich, indem die alten Domains als primäre Marketingseiten weiter beworben werden, bis Ursache und Behebung geklärt sind.

## Offene Punkte

- Tatsächliche Registrierung und DNS-Konfiguration aller neun Hosts bei IONOS ist außerhalb des Repositorys zu prüfen.
- Zertifikatsstatus je Host ist bei IONOS zu verifizieren, nicht im Repository nachweisbar.
- Zeitpunkt der Aktivierungsschritte ist mit der Geschäftsführung abzustimmen, insbesondere der `base_url`-Wechsel als geldflussnahe Änderung (Registrierungs- und Login-Links).
