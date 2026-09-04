#!/usr/bin/env python3
"""Erzeugt je Domain eine sitemap.xml aus allen indexierbaren, self-canonical HTML-Seiten."""
import glob, os, re, datetime
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'websites')
today = datetime.date.today().isoformat()
for domain in ['lexware-einzug.de', 'lexoffice-einzug.de']:
    base = os.path.join(ROOT, domain); urls = []
    for f in sorted(glob.glob(os.path.join(base, '**', '*.html'), recursive=True)):
        s = open(f, encoding='utf-8').read()
        if 'noindex' in s: continue
        c = re.search(r'<link rel="canonical" href="([^"]+)"', s)
        if not c or not c.group(1).startswith(f'https://{domain}/'): continue
        rel = os.path.relpath(f, base)
        prio = '1.0' if rel == 'index.html' else ('0.3' if rel in ('impressum.html', 'datenschutz.html', 'agb.html') else ('0.6' if rel.startswith('ratgeber/') else '0.8'))
        urls.append((c.group(1), prio))
    xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for u, p in sorted(urls, key=lambda x: (-float(x[1]), x[0])):
        xml.append(f'  <url><loc>{u}</loc><lastmod>{today}</lastmod><priority>{p}</priority></url>')
    xml.append('</urlset>')
    open(os.path.join(base, 'sitemap.xml'), 'w', encoding='utf-8').write('\n'.join(xml) + '\n')
    print(domain, len(urls), 'URLs')
