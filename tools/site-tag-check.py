#!/usr/bin/env python3
"""Prueft das Google-Tag im Seitenkopf der Marketingseiten.

Seit dem 12.09.2026 tragen smart-einzug.de und lexware-einzug.de das Google-Tag auf Vorgabe des Betreibers
direkt im <head> jeder Seite, damit Googles eigene Tag-Pruefung es findet. Das ist nur
tragfaehig, wenn drei Bedingungen dauerhaft gelten; genau die prueft dieses Werkzeug:

  1. Das Tag steht auf JEDER Seite der Domain und auf jeder Seite GENAU EINMAL.
     Google weist ausdruecklich darauf hin, dass es je Seite nur einmal vorhanden sein darf.
  2. Der Hash des Inline-Skripts steht in der Content-Security-Policy der Domain.
     Fehlt er oder weicht er ab, blockiert der Browser das Skript stillschweigend:
     Die Seite sieht normal aus, das Tag laeuft nie. Genau das faellt sonst niemandem auf.
  3. Das Inline-Skript setzt "consent default denied" VOR dem config-Aufruf. Ohne das
     wuerde Google schon vor der Einwilligung Cookies setzen (§ 25 Abs. 1 TDDDG).

  4. Die CSP erlaubt dem Tag auch den RUECKKANAL (img-src, connect-src). Steht ein Host nur
     in script-src, laedt das Skript, der Conversion-Ping wird aber blockiert: Google Ads
     meldet dann, es finde kein Tag, obwohl das Tag im Quelltext sichtbar ist.

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
SEITEN_TAG = {'smart-einzug.de': 'AW-18431688840', 'lexware-einzug.de': 'AW-18431688840'}
# Conversion-Aktion "Kauf (1)" aus Google Ads. Wird beim Klick auf Registrieren gemeldet
# (Vorgabe des Betreibers 12.09.2026). Das Label wird nie erfunden, es kommt aus Google Ads.
CONVERSION_SEND_TO = {'smart-einzug.de': 'AW-18431688840/3yI5CMyYwfIcEIiB9dRE',
                      'lexware-einzug.de': 'AW-18431688840/3yI5CMyYwfIcEIiB9dRE'}
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


def direktive(csp, name):
    """Inhalt einer CSP-Direktive, sonst der Inhalt von default-src."""
    m = re.search(re.escape(name) + r'([^;]*)', csp)
    if m:
        return m.group(1)
    m = re.search(r'default-src([^;]*)', csp)
    return m.group(1) if m else ''


# Hosts, die das Google-Ads-Tag fuer seinen RUECKKANAL braucht. Das Laden des Skripts
# regelt script-src; gemeldet wird die Conversion aber als Bild oder fetch an diese Hosts.
# Befund 12.09.2026: googleadservices stand nur in script-src. Das Skript lud, der
# Conversion-Ping wurde vom Browser blockiert, und Google Ads meldete deshalb, es finde
# kein Tag. Ein Fehler, den man der Seite nicht ansieht: Sie funktioniert vollstaendig,
# nur die Messung kommt nie an. Deshalb hier geprueft und nicht dem Zufall ueberlassen.
ADS_RUECKKANAL = ['https://www.googleadservices.com', 'https://googleads.g.doubleclick.net']


def ads_domains():
    """Domains mit einer Ads-Kennung: aus dem Seitenkopf und aus der Zuordnung in site.js."""
    treffer = set(SEITEN_TAG)
    js = os.path.join(ROOT, 'websites', 'smart-einzug.de', 'assets', 'js', 'site.js')
    if os.path.isfile(js):
        for host in re.findall(r"'([a-z0-9.-]+)':\s*'AW-\d+'", open(js, encoding='utf-8').read()):
            treffer.add(host[4:] if host.startswith('www.') else host)
    return sorted(t for t in treffer if t in ALLE_DOMAINS)


def pruefe_ads_rueckkanal():
    for domain in ads_domains():
        csp = csp_von(domain)
        if not csp:
            fail(f'{domain}: fuehrt eine Ads-Kennung, hat aber keine Content-Security-Policy')
            continue
        for richtung in ('img-src', 'connect-src'):
            werte = direktive(csp, richtung)
            for host in ADS_RUECKKANAL:
                if host not in werte:
                    fail(f'{domain}: {richtung} erlaubt {host} nicht. Das Tag laedt, der '
                         f'Conversion-Ping wird blockiert; Google Ads sieht kein Tag.')


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
        fassungen_konfig = set()
        fassungen_ereignis = set()

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
            konfig = [s for s in passend if "gtag('config'" in s]
            ereignis = [s for s in passend if 'gtag_report_conversion' in s]
            if len(konfig) != 1:
                fail(f'{rel}: {len(konfig)} Inline-Skripte mit gtag config, erwartet genau eines')
                continue
            skript = konfig[0]
            fassungen_konfig.add(skript)
            fassungen_ereignis.update(ereignis)

            sende = CONVERSION_SEND_TO.get(domain)
            if sende:
                if len(ereignis) != 1:
                    fail(f'{rel}: {len(ereignis)} Conversion-Schnipsel, erwartet genau einen')
                else:
                    if f"'send_to': '{sende}'" not in ereignis[0]:
                        fail(f'{rel}: Conversion-Schnipsel ohne das erwartete Label {sende}')
                    if 'event_callback' not in ereignis[0]:
                        fail(f'{rel}: Conversion-Schnipsel ohne event_callback, der Klick wuerde nicht weiterleiten')
                    if html.index('gtag_report_conversion') < html.index("gtag('config'"):
                        fail(f'{rel}: Conversion-Schnipsel steht vor dem Google-Tag')

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
        # Je Art genau eine Fassung, sonst braeuchte die CSP je Seite einen eigenen Hash.
        if len(fassungen_konfig) > 1:
            fail(f'{domain}: {len(fassungen_konfig)} unterschiedliche Fassungen des Google-Tags')
        if len(fassungen_ereignis) > 1:
            fail(f'{domain}: {len(fassungen_ereignis)} unterschiedliche Fassungen des Conversion-Schnipsels')
        for skript in fassungen_konfig | fassungen_ereignis:
            h = 'sha256-' + base64.b64encode(hashlib.sha256(skript.encode('utf-8')).digest()).decode()
            if h not in hashes:
                fail(f'{domain}: CSP enthaelt den Hash des Inline-Skripts nicht ({h}). '
                     f'Der Browser blockiert das Tag sonst stillschweigend.')
        if "'unsafe-inline'" in script_src:
            warn(f"{domain}: CSP erlaubt 'unsafe-inline'; der Hash waere dann wirkungslos und der Schutz gegen "
                 f'eingeschleuste Skripte deutlich schwaecher')

    pruefe_ads_rueckkanal()

    # site.js darf kein zweites gtag.js nachladen, wenn die Seite bereits eines traegt.
    js = os.path.join(ROOT, 'websites', 'smart-einzug.de', 'assets', 'js', 'site.js')
    quelle = open(js, encoding='utf-8').read()
    if 'function pageTagPresent' not in quelle:
        fail('site.js: keine Pruefung auf ein bereits vorhandenes Tag im Kopf')
    elif "gtag('consent', 'update'" not in quelle:
        fail('site.js: zieht die Einwilligung nicht per "consent update" nach')
    # Die Messung darf eine Registrierung nie verhindern.
    if 'bindConversionLinks' not in quelle:
        fail('site.js: bindet die Conversion nicht an die Registrierungslinks')
    else:
        if 'NAV_NOTBREMSE' not in quelle or 'setTimeout' not in quelle:
            fail('site.js: keine Notbremse; bliebe Google stumm, kaeme der Kunde nicht zur Registrierung')
        if 'catch (e) { gehe(); }' not in quelle:
            fail('site.js: ein Fehler in der Messung fuehrt nicht zur Navigation')
        if 'event.metaKey' not in quelle or 'el.target' not in quelle:
            fail('site.js: Klicks in neuem Fenster oder mit Sondertaste werden nicht ausgenommen')
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
