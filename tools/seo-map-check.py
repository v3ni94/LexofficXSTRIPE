#!/usr/bin/env python3
"""
Prüft die gemeinsame Themen- und URL-Zuordnung (docs/seo/keyword-map.json) gegen den
tatsächlichen Stand der Marketingseiten (Masterprompt SEO, Abschnitte 6, 12 und 18).

Regeln:
  1. Jede technisch indexierbare HTML-Seite aller Domains steht genau einmal in der Map.
  2. Jede in der Map genannte URL existiert als Datei.
  3. Jedes Cluster hat genau eine bevorzugte organische Zielseite (primaer_url), und diese ist
     indexierbar, self-canonical und in der Sitemap ihrer Domain.
  4. Die Indexierungsentscheidung je Seite ("index" oder "noindex") stimmt mit dem robots-Meta
     der Datei überein; noindex-Seiten stehen nicht in der Sitemap.
  5. Seiten mit Rolle "kampagne" sind noindex (Anzeigenvarianten ohne organisches Ziel).
  6. Jede Seite mit Rolle "primaer" oder "ergaenzend" nennt einen eigenständigen Mehrwert.
  7. Jedes Cluster nennt Faktenquellen und einen Prüftermin (TT.MM.JJJJ).
  8. Konfliktcluster ("konflikt" nicht "keine") tragen eine Empfehlung und, falls die Empfehlung
     eine Verlagerung, Weiterleitung oder Indexierungsänderung ist, freigabe_noetig = true.

Aufruf:  python3 tools/seo-map-check.py           Exit 1 bei Fehlern
"""
import importlib
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
inv = importlib.import_module('seo-inventory')

MAP = os.path.normpath(os.path.join(HERE, '..', 'docs', 'seo', 'keyword-map.json'))
ROLLEN = {'primaer', 'ergaenzend', 'konkurrent', 'kampagne', 'rechtlich', 'technisch'}
EMPFEHLUNGEN_MIT_FREIGABE = {'zusammenfuehren', 'verlagern', 'weiterleiten', 'noindex_setzen', 'canonical_setzen', 'entfernen'}

errors, warnings = [], []
err = errors.append
warn = warnings.append


def main():
    if not os.path.exists(MAP):
        print(f'FEHLER: {MAP} fehlt')
        return 1
    data = json.load(open(MAP, encoding='utf-8'))
    cluster = data.get('cluster', [])
    pages = []
    for d in inv.DOMAINS:
        if os.path.isdir(os.path.join(inv.ROOT, d)):
            pages.extend(inv.analyse(d))
    by_url = {p['url']: p for p in pages}
    seen = {}
    for c in cluster:
        cid = c.get('cluster_id', '?')
        prim = [s for s in c.get('seiten', []) if s.get('rolle') == 'primaer']
        if len(prim) != 1:
            err(f'{cid}: {len(prim)} Seiten mit Rolle primaer, erwartet genau eine')
        if c.get('primaer_url') and prim and prim[0].get('url') != c['primaer_url']:
            err(f'{cid}: primaer_url {c["primaer_url"]} passt nicht zur Seite mit Rolle primaer {prim[0].get("url")}')
        if not c.get('faktenquellen'):
            err(f'{cid}: faktenquellen fehlen')
        if not re.fullmatch(r'\d{2}\.\d{2}\.\d{4}', str(c.get('prueftermin', ''))):
            err(f'{cid}: prueftermin fehlt oder nicht TT.MM.JJJJ')
        if c.get('konflikt', 'keine') != 'keine':
            if not c.get('empfehlung'):
                err(f'{cid}: Konflikt ohne Empfehlung')
            if c.get('empfehlung') in EMPFEHLUNGEN_MIT_FREIGABE and c.get('freigabe_noetig') is not True:
                err(f'{cid}: Empfehlung {c.get("empfehlung")} verlangt freigabe_noetig = true')
        for s in c.get('seiten', []):
            url = s.get('url', '')
            rolle = s.get('rolle', '')
            if rolle not in ROLLEN:
                err(f'{cid}: unbekannte Rolle "{rolle}" für {url}')
            p = by_url.get(url)
            if not p:
                err(f'{cid}: URL ohne Datei im Repository: {url}')
                continue
            if url in seen:
                err(f'{url}: doppelt zugeordnet ({seen[url]} und {cid})')
            seen[url] = cid
            soll = s.get('indexierung', '')
            ist = 'index' if p['indexierbar_technisch'] else 'noindex'
            if soll not in ('index', 'noindex'):
                err(f'{cid}: {url} ohne Indexierungsentscheidung (index/noindex)')
            elif soll != ist:
                err(f'{cid}: {url} Map sagt {soll}, Datei ist {ist}')
            if ist == 'noindex' and p['in_sitemap']:
                err(f'{url}: noindex, aber in der Sitemap')
            if rolle == 'kampagne' and ist != 'noindex':
                err(f'{cid}: Kampagnenseite {url} ist indexierbar; reine Anzeigenvarianten sind noindex')
            if rolle in ('primaer', 'ergaenzend') and len(str(s.get('mehrwert', '')).strip()) < 20:
                err(f'{cid}: {url} ohne eigenständigen Mehrwert (Rolle {rolle})')
            if rolle == 'primaer':
                if ist != 'index':
                    err(f'{cid}: Primärseite {url} ist nicht indexierbar')
                if not p['canonical_self']:
                    err(f'{cid}: Primärseite {url} ohne Self-Canonical')
                if not p['in_sitemap']:
                    err(f'{cid}: Primärseite {url} fehlt in der Sitemap')
    for p in pages:
        if p['indexierbar_technisch'] and p['url'] not in seen:
            err(f'{p["url"]}: indexierbare Seite fehlt in der Keyword-Map')
        if not p['indexierbar_technisch'] and p['url'] not in seen and p['datei'] != '404.html':
            warn(f'{p["url"]}: noindex-Seite nicht in der Map (zulässig, aber Kampagnen sollten geführt werden)')
    for w in warnings:
        print('WARNUNG:', w)
    for e in errors:
        print('FEHLER:', e)
    print(f'{len(cluster)} Cluster, {len(seen)} zugeordnete Seiten, {len(errors)} Fehler, {len(warnings)} Warnungen')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
