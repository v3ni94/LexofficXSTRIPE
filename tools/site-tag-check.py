#!/usr/bin/env python3
"""Prueft das Google-Tag im Seitenkopf der Marketingseiten.

Seit dem 12.09.2026 traegt smart-einzug.de das Google-Tag auf Vorgabe des Betreibers
direkt im <head> jeder Seite, damit Googles eigene Tag-Pruefung es findet. Das ist nur
tragfaehig, wenn drei Bedingungen dauerhaft gelten; genau die prueft dieses Werkzeug:

  1. Das Tag steht auf JEDER Seite der Domain und auf jeder Seite GENAU EINMAL.
     Google weist ausdruecklich darauf hin, dass es je Seite nur einmal vorhanden sein darf.
  2. Der Hash des Inline-Skripts steht in der Content-Security-Policy der Domain.
     Fehlt er oder weicht er ab, blockiert der Browser das Skript stillschweigend:
     Die Seite sieht normal aus, das Tag laeuft nie. Genau das faellt sonst niemandem auf.
  3. Das Inline-Skript setzt "consent default denied" VOR dem config-Aufruf. Ohne das
     wuerde Google schon vor der Einwilligung Cookies setzen (§ 25 Abs. 1 TDDDG).

Zusaetzlich: Keine andere Domain darf ein Tag im Kopf tragen, und site.js darf kein
zweites gtag.js nachladen, wenn die Seite bereits eines im Kopf hat.

Aufruf: python3 tools/site-tag-check.py     Exit 0 = keine Fehler
"""
import base64
import glob
import hashlib
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# Domains, die das Tag im Kopf tragen sollen, mit der erwarteten Kennung.
SEITEN_TAG = {'smart-einzug.de': 'AW-18431688840'}
ALLE_DOMAINS = ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de',
                'sevdesk-einzug.de', 'sevdesk-sepa.de']

fehler = []
warnungen = []


def fail(msg):
    fehler.append(msg)


def warn(msg):
    warnungen.append(msg)


def inline_skripte(html):
    """Inhalt aller Inline-Skripte ohne src-Attribut, ohne JSON-LD."""
    out = []
    for m in re.finditer(r'<script(?![^>]*\bsrc=)([^>]*)>(.*?)</script>', html, re.S):
        if 'application/ld+json' in m.group(1):
            continue
        out.append(m.group(2))
    return out


def csp_von(domain):
    p = os.path.join(ROOT, 'websites', domain, '.htaccess')
    if not os.path.isfile(p):
        return ''
    m = re.search(r'Content-Security-Policy\s+"([^"]*)"', open(p, encoding='utf-8').read())
    return m.group(1) if m else ''


def main():
    for domain in ALLE_DOMAINS:
        dateien = sorted(glob.glob(os.path.join(ROOT, 'websites', domain, '**', '*.html'), recursive=True))
        if not dateien:
            continue
        erwartet = SEITEN_TAG.get(domain)
        csp = csp_von(domain)
        # Nur die script-src-Direktive ist fuer Skripte massgeblich; style-src darf
        # 'unsafe-inline' tragen, ohne dass das Tag davon beruehrt waere.
        m_script = re.search(r'script-src([^;]*)', csp)
        script_src = m_script.group(1) if m_script else ''
        hashes = set(re.findall(r"'(sha256-[A-Za-z0-9+/=]+)'", script_src))
        gesehen = set()

        for f in dateien:
            rel = os.path.relpath(f, ROOT)
            html = open(f, encoding='utf-8').read()
            loader = re.findall(r'<script[^>]+src="https://www\.googletagmanager\.com/gtag/js\?id=([^"]+)"', html)

            if erwartet is None:
                if loader:
                    fail(f'{rel}: Tag im Kopf, obwohl diese Domain keines tragen soll')
                continue

            if not loader:
                fail(f'{rel}: Google-Tag fehlt im Kopf')
                continue
            if len(loader) > 1:
                fail(f'{rel}: Google-Tag {len(loader)} mal vorhanden, erlaubt ist genau einmal')
            if loader[0] != erwartet:
                fail(f'{rel}: fremde Kennung im Tag: {loader[0]}, erwartet {erwartet}')

            # Das Tag gehoert in den Kopf, vor den Inhalt.
            kopf = html.split('</head>', 1)[0]
            if 'googletagmanager.com/gtag/js' not in kopf:
                fail(f'{rel}: Tag steht nicht im <head>')

            passend = [s for s in inline_skripte(html) if 'gtag(' in s]
            if len(passend) != 1:
                fail(f'{rel}: {len(passend)} Inline-Skripte mit gtag, erwartet genau eines')
                continue
            skript = passend[0]
            gesehen.add(skript)

            if "gtag('consent', 'default'" not in skript:
                fail(f'{rel}: Inline-Skript ohne "consent default"; Google wuerde vor der Einwilligung Cookies setzen')
            else:
                vor_default = skript.index("gtag('consent', 'default'")
                vor_config = skript.index("gtag('config'") if "gtag('config'" in skript else len(skript)
                if vor_default > vor_config:
                    fail(f'{rel}: "consent default" steht nach "config"; die Voreinstellung greift dann nicht')
            for schluessel in ('ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'):
                if f"{schluessel}: 'denied'" not in skript:
                    fail(f'{rel}: {schluessel} ist in der Voreinstellung nicht auf "denied"')

        if erwartet is None:
            continue
        if len(gesehen) > 1:
            fail(f'{domain}: {len(gesehen)} unterschiedliche Fassungen des Inline-Skripts; '
                 f'ein einziger CSP-Hash kann nicht alle abdecken')
        for skript in gesehen:
            h = 'sha256-' + base64.b64encode(hashlib.sha256(skript.encode('utf-8')).digest()).decode()
            if h not in hashes:
                fail(f'{domain}: CSP enthaelt den Hash des Inline-Skripts nicht ({h}). '
                     f'Der Browser blockiert das Tag sonst stillschweigend.')
        if "'unsafe-inline'" in script_src:
            warn(f"{domain}: CSP erlaubt 'unsafe-inline'; der Hash waere dann wirkungslos und der Schutz gegen "
                 f'eingeschleuste Skripte deutlich schwaecher')

    # site.js darf kein zweites gtag.js nachladen, wenn die Seite bereits eines traegt.
    js = os.path.join(ROOT, 'websites', 'smart-einzug.de', 'assets', 'js', 'site.js')
    quelle = open(js, encoding='utf-8').read()
    if 'function pageTagPresent' not in quelle:
        fail('site.js: keine Pruefung auf ein bereits vorhandenes Tag im Kopf')
    elif "gtag('consent', 'update'" not in quelle:
        fail('site.js: zieht die Einwilligung nicht per "consent update" nach')
    # Alle Domains liefern dieselbe site.js aus.
    fassungen = {open(p, encoding='utf-8').read() for p in glob.glob(os.path.join(ROOT, 'websites', '*', 'assets', 'js', 'site.js'))}
    if len(fassungen) > 1:
        fail(f'site.js liegt in {len(fassungen)} unterschiedlichen Fassungen vor, erwartet wird eine')

    for w in warnungen:
        print('WARNUNG:', w)
    for e in fehler:
        print('FEHLER:', e)
    print(f'\n{len(fehler)} Fehler, {len(warnungen)} Warnungen')
    return 1 if fehler else 0


if __name__ == '__main__':
    sys.exit(main())
