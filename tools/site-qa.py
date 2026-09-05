#!/usr/bin/env python3
"""
SEO- und Qualitätsprüfung der beiden Marketingseiten (statisches HTML).

Prüft je Domain: Title/Description vorhanden und eindeutig, Self-Canonical,
Open Graph, genau ein <h1>, <html lang="de">, Bilder mit alt, interne Links
auf existierende Dateien, Sitemap-Konsistenz (nur indexierbare, existierende
Seiten), JSON-LD parsebar, keine Gedankenstriche, Disclaimer und Signatur,
sowie domainübergreifend die Textähnlichkeit des Hauptinhalts
(Navigation, Footer, Rechtstexte entfernt).

Aufruf:  python3 tools/site-qa.py [--strict]
Exit 1 bei Fehlern (und bei --strict auch bei Warnungen).
"""
import glob, json, os, re, sys, html
from difflib import SequenceMatcher

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'websites')
DOMAINS = ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de', 'lastschrift-einfach.de']
NOINDEX_FILES = {'404.html'}
import itertools
SIM_WARN, SIM_FAIL = 0.35, 0.50

errors, warnings = [], []
def err(m): errors.append(m)
def warn(m): warnings.append(m)

def strip_tags(s):
    s = re.sub(r'<(script|style|svg)\b.*?</\1>', ' ', s, flags=re.S | re.I)
    s = re.sub(r'<!--.*?-->', ' ', s, flags=re.S)
    s = re.sub(r'<[^>]+>', ' ', s)
    return html.unescape(re.sub(r'\s+', ' ', s)).strip()

def main_content(s):
    m = re.search(r'<main\b.*?</main>', s, re.S)
    body = m.group(0) if m else s
    body = re.sub(r'<nav\b.*?</nav>', ' ', body, flags=re.S)
    body = re.sub(r'<footer\b.*?</footer>', ' ', body, flags=re.S)
    return strip_tags(body)

def url_for(domain, rel):
    rel = rel.replace('index.html', '')
    rel = re.sub(r'\.html$', '', rel)
    return f'https://{domain}/{rel}'

def check_domain(domain):
    base = os.path.join(ROOT, domain)
    files = sorted(glob.glob(os.path.join(base, '**', '*.html'), recursive=True))
    titles, descs, indexable = {}, {}, []
    for f in files:
        rel = os.path.relpath(f, base)
        s = open(f, encoding='utf-8').read()
        tag = f'{domain}/{rel}'
        if '<html lang="de">' not in s: err(f'{tag}: <html lang="de"> fehlt')
        if not re.search(r'<meta charset="?utf-8"?', s, re.I): err(f'{tag}: charset fehlt')
        if 'name="viewport"' not in s: err(f'{tag}: viewport fehlt')
        t = re.search(r'<title>(.*?)</title>', s, re.S)
        if not t or not t.group(1).strip(): err(f'{tag}: Title fehlt')
        else:
            titles.setdefault(t.group(1).strip(), []).append(rel)
            if len(t.group(1)) > 65: warn(f'{tag}: Title länger als 65 Zeichen ({len(t.group(1))})')
        noindex = 'noindex' in s
        d = re.search(r'<meta name="description" content="([^"]*)"', s)
        if not noindex:
            if not d or not d.group(1).strip(): err(f'{tag}: Meta Description fehlt')
            else:
                descs.setdefault(d.group(1).strip(), []).append(rel)
                if not 90 <= len(d.group(1)) <= 165: warn(f'{tag}: Description {len(d.group(1))} Zeichen')
            c = re.search(r'<link rel="canonical" href="([^"]+)"', s)
            expected = url_for(domain, rel)
            if not c: err(f'{tag}: Canonical fehlt')
            elif c.group(1) != expected: err(f'{tag}: Canonical {c.group(1)} statt {expected}')
            for og in ['og:title', 'og:description', 'og:url']:
                if f'property="{og}"' not in s: err(f'{tag}: {og} fehlt')
            ogu = re.search(r'property="og:url" content="([^"]+)"', s)
            if ogu and ogu.group(1) != expected: err(f'{tag}: og:url {ogu.group(1)} statt {expected}')
            indexable.append(expected)
        h1 = len(re.findall(r'<h1[\s>]', s))
        if h1 != 1: err(f'{tag}: {h1} <h1>')
        for img in re.findall(r'<img\b[^>]*>', s):
            if 'alt=' not in img: err(f'{tag}: <img> ohne alt')
        if '—' in strip_tags(s) or ' – ' in strip_tags(s): err(f'{tag}: Gedankenstrich im Text')
        if 'Kein Produkt der Haufe-Lexware' not in s: err(f'{tag}: Markenhinweis fehlt')
        if 'In Liebe zu Charlotte' not in s: warn(f'{tag}: Signaturkommentar fehlt')
        if 'noindex' in s and rel.startswith('lp/') and 'follow' not in s: err(f'{tag}: Landingpage braucht noindex,follow')
        if s.count('In Liebe zu Charlotte') > 1: warn(f'{tag}: Signaturkommentar mehrfach')
        for ld in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
            try:
                data = json.loads(ld)
                if 'aggregateRating' in ld or '"Review"' in ld: err(f'{tag}: Bewertungs-Markup ohne echte Bewertungen')
            except Exception as e:
                err(f'{tag}: JSON-LD ungültig ({e})')
        # interne Links
        for href in re.findall(r'href="([^"#]+)(?:#[^"]*)?"', s):
            if href.startswith(('http', 'mailto:', 'tel:')) or href.startswith('/assets/'):
                if href.startswith('/assets/'):
                    p = os.path.join(base, href.lstrip('/').split('?')[0])
                    if not os.path.exists(p): err(f'{tag}: Asset fehlt {href}')
                continue
            path = href.split('?')[0]
            if path in ('', '/'): continue
            target = path.lstrip('/')
            candidates = [os.path.join(base, target), os.path.join(base, target + '.html'), os.path.join(base, target.rstrip('/'), 'index.html')]
            if target.endswith('.html'): warn(f'{tag}: Link mit .html-Endung {href}')
            if not any(os.path.exists(c) for c in candidates): err(f'{tag}: interner Link ohne Ziel {href}')
    for t, fs in titles.items():
        if len(fs) > 1: err(f'{domain}: doppelter Title "{t}" in {fs}')
    for d, fs in descs.items():
        if len(fs) > 1: err(f'{domain}: doppelte Description in {fs}')
    # Sitemap
    sm_path = os.path.join(base, 'sitemap.xml')
    if not os.path.exists(sm_path): err(f'{domain}: sitemap.xml fehlt')
    else:
        locs = re.findall(r'<loc>(.*?)</loc>', open(sm_path, encoding='utf-8').read())
        for l in locs:
            if l not in indexable: err(f'{domain}: Sitemap enthält nicht indexierbare oder fehlende URL {l}')
        for u in indexable:
            if u not in locs: err(f'{domain}: indexierbare Seite fehlt in Sitemap {u}')
    rp = os.path.join(base, 'robots.txt')
    if not os.path.exists(rp): err(f'{domain}: robots.txt fehlt')
    elif f'https://{domain}/sitemap.xml' not in open(rp).read(): err(f'{domain}: robots.txt ohne Sitemap-Verweis')
    return files

def similarity_report():
    print('\nContent-Ähnlichkeit (Hauptinhalt, ohne Navigation/Footer/Rechtstexte):')
    legal = {'impressum.html', 'datenschutz.html', 'agb.html', '404.html'}
    def texts(domain):
        out = {}
        for f in glob.glob(os.path.join(ROOT, domain, '**', '*.html'), recursive=True):
            rel = os.path.relpath(f, os.path.join(ROOT, domain))
            if os.path.basename(rel) in legal: continue
            out[rel] = main_content(open(f, encoding='utf-8').read())
        return out
    def shingles(t, n=8):
        w = re.findall(r'\w+', t.lower())
        return set(' '.join(w[i:i + n]) for i in range(max(0, len(w) - n + 1)))
    worst = 0.0
    rows = []
    for da, db_ in itertools.combinations(DOMAINS, 2):
        a, b = texts(da), texts(db_)
        for fa, ta in a.items():
            if fa.startswith('lp/'): continue
            sa = shingles(ta)
            best = (0.0, None, 0)
            for fb, tb in b.items():
                if fb.startswith('lp/'): continue
                sb = shingles(tb)
                if not sa or not sb: continue
                jac = len(sa & sb) / len(sa | sb)
                ratio = SequenceMatcher(None, ta[:6000], tb[:6000]).ratio()
                score = max(jac, ratio)
                if score > best[0]: best = (score, fb, len(sa & sb))
            rows.append((best[0], f'{da}/{fa}', f'{db_}/{best[1]}', best[2]))
            worst = max(worst, best[0])
    for score, fa, fb, common in sorted(rows, reverse=True)[:10]:
        print(f'  {score:5.1%}  {fa}  <->  {fb}  (gemeinsame 8-Wort-Folgen: {common})')
    if worst > SIM_FAIL: err(f'Content-Ähnlichkeit {worst:.0%} über {SIM_FAIL:.0%}')
    elif worst > SIM_WARN: warn(f'Content-Ähnlichkeit {worst:.0%} über internem Ziel {SIM_WARN:.0%}')
    # wortgleiche Überschriften
    def heads(domain):
        hs = set()
        for f in glob.glob(os.path.join(ROOT, domain, '**', '*.html'), recursive=True):
            if os.path.basename(f) in legal: continue
            s = open(f, encoding='utf-8').read()
            hs |= set(strip_tags(h) for h in re.findall(r'<h[12][^>]*>(.*?)</h[12]>', s, re.S))
        return hs
    for da, db_ in itertools.combinations(DOMAINS, 2):
        same = heads(da) & heads(db_)
        if same: warn(f'identische H1/H2 auf {da} und {db_}: {sorted(same)}')

if __name__ == '__main__':
    total = 0
    for d in DOMAINS:
        total += len(check_domain(d))
    similarity_report()
    print(f'\n{total} HTML-Dateien geprüft.')
    for w in warnings: print('WARNUNG:', w)
    for e in errors: print('FEHLER:', e)
    print(f'\n{len(errors)} Fehler, {len(warnings)} Warnungen')
    sys.exit(1 if errors or ('--strict' in sys.argv and warnings) else 0)
