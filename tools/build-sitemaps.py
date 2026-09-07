#!/usr/bin/env python3
"""
Erzeugt je Domain eine sitemap.xml aus allen indexierbaren, self-canonical HTML-Seiten.

lastmod nennt das Datum der letzten inhaltlichen Änderung der Datei laut Git-Historie
(letzter Commit, der die Datei berührt hat). Ist die Datei im Arbeitsverzeichnis gegenüber
HEAD geändert oder noch nicht versioniert, gilt das heutige Datum. Bisher trug jede URL das
Datum des Sitemap-Laufs, also faktisch das Deploy-Datum; Google empfiehlt lastmod nur für
echte Inhaltsänderungen (Masterprompt SEO, Abschnitt 12).

Aufruf:  python3 tools/build-sitemaps.py
"""
import datetime
import glob
import os
import re
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..'))
ROOT = os.path.join(REPO, 'websites')
DOMAINS = ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de', 'lastschrift-einfach.de']
today = datetime.date.today().isoformat()


def git(*args):
    try:
        return subprocess.run(['git', *args], cwd=REPO, capture_output=True, text=True, check=False).stdout.strip()
    except OSError:
        return ''


def lastmod_for(path):
    rel = os.path.relpath(path, REPO)
    # Ungespeicherte oder nicht versionierte Änderung: heute
    if git('status', '--porcelain', '--', rel):
        return today
    date = git('log', '-1', '--format=%cs', '--', rel)
    return date if re.fullmatch(r'\d{4}-\d{2}-\d{2}', date or '') else today


for domain in DOMAINS:
    base = os.path.join(ROOT, domain)
    if not os.path.isdir(base):
        continue
    urls = []
    for f in sorted(glob.glob(os.path.join(base, '**', '*.html'), recursive=True)):
        s = open(f, encoding='utf-8').read()
        if 'noindex' in s:
            continue
        c = re.search(r'<link rel="canonical" href="([^"]+)"', s)
        if not c or not c.group(1).startswith(f'https://{domain}/'):
            continue
        rel = os.path.relpath(f, base)
        legal = rel in ('impressum.html', 'datenschutz.html', 'agb.html') or rel.split('/')[0] in ('impressum', 'datenschutz', 'agb', 'kontakt')
        prio = '1.0' if rel == 'index.html' else ('0.3' if legal else ('0.6' if rel.startswith('ratgeber/') else '0.8'))
        urls.append((c.group(1), prio, lastmod_for(f)))
    xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for u, p, lm in sorted(urls, key=lambda x: (-float(x[1]), x[0])):
        xml.append(f'  <url><loc>{u}</loc><lastmod>{lm}</lastmod><priority>{p}</priority></url>')
    xml.append('</urlset>')
    open(os.path.join(base, 'sitemap.xml'), 'w', encoding='utf-8').write('\n'.join(xml) + '\n')
    print(domain, len(urls), 'URLs, lastmod aus Git-Historie')
