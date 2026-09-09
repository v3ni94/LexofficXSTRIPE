#!/usr/bin/env python3
"""Interne Verlinkung der Marketingseiten pruefen (Ergaenzung zu site-qa.py und seo-map-check.py).

Geprueft wird gegen den Dateibestand unter websites/, ohne Netzzugang:

  1. Jeder interne Link zeigt auf eine vorhandene Seite oder Datei (sonst FEHLER).
  2. Jede indexierbare Seite hat mindestens einen eingehenden internen Link
     (sonst FEHLER: verwaist). Kampagnenseiten unter lp/ und Seiten mit noindex
     sind ausgenommen, sie werden bewusst nicht verlinkt.
  3. Jede indexierbare Seite hat mindestens MIN_INBOUND eingehende Links
     (sonst WARNUNG). Nach der Zusammenfuehrung vom 08.09.2026 sind einzelne
     Beitraege dadurch schwach angebunden gewesen.

Hintergrund: Die Zusammenfuehrung der Leaddomain-Seiten (Massnahmenplan M4) hat
eine Seite verwaist zurueckgelassen, weil die verlinkenden Seiten geloescht
wurden. Diese Pruefung faengt genau diesen Fall kuenftig ab.

Aufruf: python3 tools/seo-linkcheck.py
Rueckgabe: 0 wenn keine Fehler, sonst 1.
"""
import collections
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAINS = ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de',
           'sevdesk-einzug.de', 'sevdesk-sepa.de']
MIN_INBOUND = 2

fehler = []
warnungen = []


def fail(msg):
    fehler.append(msg)


def warn(msg):
    warnungen.append(msg)


def seiten_url(pfad):
    """Dateipfad -> (domain, url-pfad ohne .html und ohne index)."""
    rel = pfad[len(os.path.join(ROOT, 'websites')) + 1:]
    dom, _, rest = rel.partition(os.sep)
    rest = rest.replace(os.sep, '/')
    if rest.endswith('/index.html'):
        rest = rest[:-len('index.html')]
    elif rest == 'index.html':
        rest = ''
    elif rest.endswith('.html'):
        rest = rest[:-len('.html')]
    return dom, '/' + rest


def schluessel(dom, pfad):
    return dom, (pfad.rstrip('/') or '/')


def ziel_vorhanden(dom, pfad):
    """Loest einen internen Pfad gegen den Dateibestand auf."""
    p = pfad.split('#')[0].split('?')[0].lstrip('/')
    basis = os.path.join(ROOT, 'websites', dom)
    if p in ('', '/'):
        return os.path.isfile(os.path.join(basis, 'index.html'))
    kandidaten = [p, p + '.html', p.rstrip('/') + '/index.html', p.rstrip('/') + '.html']
    return any(os.path.isfile(os.path.join(basis, k)) for k in kandidaten)


def ziel_domain(dom, href):
    """Liefert (domain, pfad) fuer interne und domainuebergreifende Links, sonst (None, None)."""
    if href.startswith('http'):
        for d in DOMAINS:
            if f'//{d}/' in href or href.rstrip('/') == f'https://{d}':
                return d, '/' + href.split(d, 1)[1].lstrip('/').split('#')[0].split('?')[0]
        return None, None
    if href.startswith('/'):
        return dom, href.split('#')[0].split('?')[0]
    return None, None


def main():
    eingehend = collections.Counter()
    seiten = {}

    dateien = []
    for dom in DOMAINS:
        dateien += sorted(glob.glob(os.path.join(ROOT, 'websites', dom, '**', '*.html'), recursive=True))
    if not dateien:
        print('Keine HTML-Dateien gefunden, Pruefung abgebrochen.')
        return 1

    for f in dateien:
        dom, pfad = seiten_url(f)
        html = open(f, encoding='utf-8').read()
        robots = re.search(r'name="robots"\s+content="([^"]*)"', html)
        noindex = bool(robots and 'noindex' in robots.group(1).lower())
        seiten[schluessel(dom, pfad)] = {
            'datei': os.path.relpath(f, ROOT),
            'noindex': noindex,
            'kampagne': '/lp/' in pfad,
            'fehler404': f.endswith('404.html'),
        }
        koerper = html.split('<body', 1)[1] if '<body' in html else html
        for href in set(re.findall(r'<a[^>]+href="([^"]+)"', koerper)):
            d, p = ziel_domain(dom, href)
            if d is None:
                continue
            if d == dom and not ziel_vorhanden(d, p):
                fail(f'{os.path.relpath(f, ROOT)}: interner Link ohne Ziel: {href}')
                continue
            if d != dom and not ziel_vorhanden(d, p):
                fail(f'{os.path.relpath(f, ROOT)}: Link auf {d}{p} ohne Datei im Repository')
                continue
            if schluessel(d, p) != schluessel(dom, pfad):
                eingehend[schluessel(d, p)] += 1

    gezaehlt = 0
    for key, info in sorted(seiten.items()):
        if info['noindex'] or info['kampagne'] or info['fehler404']:
            continue
        gezaehlt += 1
        n = eingehend[key]
        adresse = f'{key[0]}{key[1]}'
        if n == 0:
            fail(f'{adresse}: verwaist, kein eingehender interner Link ({info["datei"]})')
        elif n < MIN_INBOUND:
            warn(f'{adresse}: nur {n} eingehender interner Link, empfohlen sind {MIN_INBOUND}')

    print(f'{len(seiten)} Seiten geprueft, davon {gezaehlt} indexierbar.')
    for w in warnungen:
        print('WARNUNG:', w)
    for e in fehler:
        print('FEHLER:', e)
    print(f'\n{len(fehler)} Fehler, {len(warnungen)} Warnungen')
    return 1 if fehler else 0


if __name__ == '__main__':
    sys.exit(main())
