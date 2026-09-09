# Prüfbefehle für die Marketingseiten

Stand: 10.09.2026. Alle Befehle laufen im Wurzelverzeichnis des Repositorys und brauchen weder Netzzugang noch zusätzliche Pakete, sofern nicht anders vermerkt.

## 1. Pflichtprüfungen vor jedem Commit an `websites/`

```
python3 tools/site-qa.py          # muss 0 Fehler liefern
python3 tools/asset-version.py    # Hashes fuer site.css und site.js
python3 tools/build-sitemaps.py   # lastmod aus der Git-Historie
python3 tools/seo-inventory.py    # URL-Inventar neu erheben
python3 tools/seo-map-check.py    # muss 0 Fehler liefern
php tools/pricing-check.php       # keine Produktpreise auf den Seiten
```

Reihenfolge einhalten: `asset-version.py` vor `build-sitemaps.py`, weil es HTML-Dateien anfasst und damit den Änderungsstand verschiebt.

## 2. Prüfungen dieses Audits zum Nachstellen

Defekte interne Links, verwaiste Seiten und eingehende Verlinkung:

```
python3 tools/seo-linkcheck.py
```

Der Prüfer meldet drei Klassen: defekte interne Links als Fehler, indexierbare Seiten ohne eingehenden Link als Fehler, indexierbare Seiten mit weniger als zwei eingehenden Links als Warnung.

Doppelte Titel und Beschreibungen:

```
python3 -c "
import json,collections
p=[x for x in json.load(open('docs/seo/url-inventar.json',encoding='utf-8')) if x['indexierbar_technisch']]
for k in ('title','description'):
    c=collections.Counter(x[k] for x in p)
    print(k, [t for t,n in c.items() if n>1] or 'keine Dubletten')"
```

Bilder ohne Abmessungen, ohne Alt-Text oder ohne verzögertes Laden unterhalb des sichtbaren Bereichs:

```
grep -rho '<img[^>]*>' websites --include=*.html | grep -v 'width=' | head
grep -rho '<img[^>]*>' websites --include=*.html | grep -v 'alt=' | head
```

Beide Aufrufe dürfen nichts ausgeben.

## 3. Messungen, die einen Live-Zugang brauchen

Aus der Arbeitsumgebung heraus nicht möglich, weil der Netzzugang die Domains nicht erreicht. Auf einem Rechner mit Internetzugang:

```
npx lighthouse https://smart-einzug.de/ --preset=desktop --output=json --output-path=./lh-desktop.json
npx lighthouse https://smart-einzug.de/ --form-factor=mobile --throttling-method=simulate --output=json --output-path=./lh-mobile.json
```

Je Seitenvorlage drei Läufe, den mittleren Wert festhalten. Sinnvolle Vorlagen: Startseite, eine Anleitung, ein Wissensbeitrag, eine Leaddomain-Startseite.

Auslieferung und Weiterleitungen prüfen:

```
curl -sSI https://smart-einzug.de/wissen/ | head -20
curl -sSIL https://lexware-einzug.de/ratgeber/mandatsreferenz-glaeubiger-id.html | grep -E '^(HTTP|location)'
curl -sSIL https://www.lexware-einzug.de/ | grep -E '^(HTTP|location)'
```

Erwartung: ein einziger 301 je Adresse, bei Aufruf über `www` zwei Sprünge, Ziel jeweils mit HTTP 200.

## 4. Google-Tag prüfen

Die Google-Skripte laden erst nach ausdrücklicher Einwilligung. Ein automatischer Scanner ohne Einwilligung findet sie deshalb nicht. Prüfung von Hand: Seite im privaten Fenster öffnen, Entwicklerwerkzeuge auf den Reiter Netzwerk stellen, im Banner alle akzeptieren. Danach müssen Anfragen an `googletagmanager.com/gtag/js` und, auf lexware-einzug.de und smart-einzug.de, an `googleads.g.doubleclick.net` erscheinen.
