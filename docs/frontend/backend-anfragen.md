# Anfragen des Frontends an das Backend

Stand 13.09.2026. Diese Datei wird vom Frontend geführt und vom Backend gelesen. Sie enthält nur, was das
Frontend nicht selbst entscheiden oder umsetzen darf.

## Vorbemerkung zur Arbeitsteilung

Ein Datenvertrag unter `docs/contracts/` besteht in diesem Projekt nicht. Die gleichwertige vorhandene
Quelle ist `docs/seo/02-faktenregister.md`: 191 Aussagen mit Status und Quellenangabe als Datei und Zeile.
Das Frontend nutzt sie als Faktenquelle und legt bewusst keine zweite Wahrheit daneben an.

Dateibesitz in diesem Durchgang: Das Frontend hat ausschließlich unter `websites/`, `docs/frontend/`,
`docs/seo/` und `tools/site-tag-check.py` geschrieben. In `php-ionos/` wurde nur gelesen, mit einer
Ausnahme: `app/version.php` für den Änderungsverlauf, wie es die Projektregeln verlangen.

## Offene Punkte

### 1. Faktenregister ist an einer Stelle überholt

**Betrifft:** `docs/seo/02-faktenregister.md`, Eintrag RUECK-01.

Das Register sagt, Rücklastschriften erkenne ausschließlich der Webhook. Seit 4.73 erkennt sie auch der
Statusabgleich (`collection_apply_dispute()`, gemeinsame Funktion für beide Wege). Die betroffene
Marketingseite wurde bereits korrigiert, das Register nicht: Es wird vom Backend gepflegt und ist die
Quelle für alle weiteren Texte.

**Nötig:** RUECK-01 auf den Stand 4.75 nachziehen. Zu prüfen ist außerdem, ob weitere Einträge seit dem
07.09.2026 überholt sind; das Register trägt den Repository-Stand `abd5d26`.

### 2. Keine belegbare Standortangabe

**Betrifft:** Grounding Page, Datenschutzerklärung, Sicherheitsseite, Anlage 3 des AVV.

Der Rechenzentrumsstandort des VPS und die Region des Sicherungsspeichers sind nirgends dokumentiert
(HOST-01, HOST-03, beide „ungeklärt“). Die Faktenseite trifft deshalb keine Standortaussage.

**Nötig:** Beides aus den Vertragsunterlagen der Anbieter feststellen und im Faktenregister mit
Nachweisstufe eintragen. Erst danach darf eine Seite etwas dazu sagen.

### 3. Verschlüsselung der IBAN

**Betrifft:** `/sicherheit/`, Grounding Page, Anlage 1 B und Anlage 2 des AVV-Entwurfs.

Im Portal erfasste IBANs liegen im Klartext in der Datenbank (SICH-03). Die Sicherheitsseite benennt das
offen, die Faktenseite behauptet deshalb nichts Gegenteiliges. Das ist keine Frontend-Aufgabe, hat aber
unmittelbare Auswirkung auf die zulässigen Aussagen.

**Nötig:** Entscheidung des Betreibers, ob verschlüsselt wird (Codeänderung plus Migration) oder ob die
Anlagen des AVV an den Ist-Zustand angepasst werden. Bis dahin bleibt die Aussage auf den Seiten, wie sie
ist.

### 4. Sicherheitsrichtlinie der Anwendung

**Betrifft:** `app.smart-einzug.de`.

Die Marketingdomains führen je eine Content-Security-Policy mit Hash statt `unsafe-inline`. Die Anwendung
führt außer auf Einzelseiten (`docs.php`, `mandat.php`) keine. Das ist eine Backend-Entscheidung und im
Kapitel `docs/entwickler/sicherheit.md` bereits als offener Prüfpunkt vermerkt.

**Nötig, falls eine Richtlinie eingeführt wird:** Sie muss das Einwilligungsskript
(`assets/js/consent.js`) und die von ihm nachgeladenen Google-Hosts zulassen, und zwar nicht nur in
`script-src`, sondern auch in `img-src` und `connect-src`. Genau dieser Punkt war am 12.09.2026 auf den
Marketingdomains die Ursache dafür, dass Google Ads das Tag nicht fand (4.71). Vor einer Aktivierung bitte
Rückmeldung, damit die betroffenen Seiten dagegen geprüft werden können.

### 5. Conversion für den tatsächlichen Vertragsabschluss

**Betrifft:** `analytics.ads_conversion_label`, Bestellbestätigung `subscription.php?bestellt=1`.

Derzeit meldet die Marketingseite die Conversion „Kauf (1)“ beim Klick auf Registrieren. Gezählt wird damit
eine Absicht, kein Abschluss. Die Anwendung könnte den Abschluss melden, hat aber bewusst kein Label
konfiguriert, weil sonst derselbe Vorgang doppelt zählt.

**Nötig, wenn der Abschluss gemessen werden soll:** eine zweite Conversion-Aktion in Google Ads anlegen,
deren Label in `analytics.ads_conversion_label` eintragen und die erste Aktion auf der Marketingseite
entsprechend umwidmen. Das Label darf nie erfunden werden. Entscheidung liegt beim Betreiber.

## Keine Anfragen zu

Für die in diesem Durchgang umgesetzten Änderungen war keine Backend-Leistung nötig. Es wurden keine neuen
Endpunkte, Felder oder Statuswerte angenommen und keine erfunden.
