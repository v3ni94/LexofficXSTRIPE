#!/usr/bin/env python3
"""Überträgt Header (inkl. Navigation) und Footer der Startseite auf alle Unterseiten derselben Domain."""
import glob, os, re
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'websites')
for domain in ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de', 'lastschrift-einfach.de']:
    base = os.path.join(ROOT, domain)
    idx = open(os.path.join(base, 'index.html'), encoding='utf-8').read()
    header = re.search(r'<header class="site-header">.*?</header>', idx, re.S).group(0)
    footer = re.search(r'<footer class="site-footer">.*?</footer>', idx, re.S).group(0)
    n = 0
    for f in glob.glob(os.path.join(base, '**', '*.html'), recursive=True):
        if f.endswith('index.html') and os.path.dirname(f) == base: continue
        s = open(f, encoding='utf-8').read()
        t = re.sub(r'<header class="site-header">.*?</header>', lambda m: header, s, count=1, flags=re.S)
        t = re.sub(r'<footer class="site-footer">.*?</footer>', lambda m: footer, t, count=1, flags=re.S)
        if t != s: open(f, 'w', encoding='utf-8').write(t); n += 1
    print(domain, 'aktualisiert:', n)
