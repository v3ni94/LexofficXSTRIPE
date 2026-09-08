#!/usr/bin/env python3
"""
URL-Inventar der statischen Marketingseiten (Masterprompt SEO, Abschnitt 4).

Liest alle HTML-Dateien unter websites/<domain>/ und erzeugt je Seite einen
deterministischen Datensatz: URL, Seitentyp (heuristisch), Title, Description,
H1, H2-Liste, Canonical, robots-Meta, Indexierbarkeit, Sitemap-Aufnahme,
interne Verlinkung (ausgehend und eingehend), Handlungsaufforderungen,
strukturierte Daten (Typen, Preisangaben), Preisbeträge und Datumsangaben im
Text, Bildanzahl sowie Trefferzahlen fuer pruefbeduerftige Aussagen.

Die Ausgabe ist die Grundlage fuer Faktenpruefung und Themenzuordnung. Sie
enthaelt keine Bewertung. Leistungsdaten (Search Console, Analytics) liegen im
Repository nicht vor und werden als Luecke ausgewiesen.

Aufruf:  python3 tools/seo-inventory.py            schreibt docs/seo/url-inventar.json und .md
         python3 tools/seo-inventory.py --stdout   gibt das JSON auf der Standardausgabe aus
"""
import glob
import html
import json
import os
import re
import sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..', 'websites'))
OUT_DIR = os.path.normpath(os.path.join(HERE, '..', 'docs', 'seo'))
# lastschrift-einfach.de ist laut Betreiber (07.09.2026) nur eine Weiterleitung auf smart-abrechnen.de und
# gehoert nicht zum SEO-Geltungsbereich; der Ordner websites/lastschrift-einfach.de enthaelt seit dem
# 08.09.2026 nur noch .htaccess (301) und 404.html.
DOMAINS = ['smart-einzug.de', 'lexoffice-einzug.de', 'lexware-einzug.de', 'sevdesk-einzug.de', 'sevdesk-sepa.de']

# Woerter und Muster, deren Vorkommen eine Faktenpruefung ausloest (Masterprompt Abschnitte 4, 10, 11).
CLAIM_PATTERNS = {
    'kostenlos': r'kostenlos',
    'testen_testphase': r'\btest(en|phase|zeitraum|monat)',
    'minuten_angabe': r'\b\d+\s*Minuten',
    'partner_offiziell': r'\b(Partner|offiziell|zertifiziert|autorisiert)',
    'garantie': r'garantier',
    'automatisch': r'automatis',
    'firmenlastschrift_b2b': r'(Firmenlastschrift|\bB2B\b)',
    'ruecklastschrift': r'R[üu]cklastschrift',
    'vorabankuendigung': r'Vorabank[üu]ndigung|Pre-?Notification',
    'glaeubiger_id': r'Gl[äa]ubiger',
    'mandat': r'\bMandat',
    'rueckschreibung_buchhaltung': r'(ausgebucht|verbucht|zur[üu]ckgeschrieben|als bezahlt|Zahlungseingang|Offene-Posten|OP-Liste)',
    'mehrfirmen': r'(mehrere Firmen|Mehrfirmen|mehrere Unternehmen|mehrere Mandanten)',
    'zwei_faktor': r'(Zwei-Faktor|2FA|Zweitfaktor)',
    'verschluesselung': r'verschl[üu]ssel',
    'standort_deutschland': r'(Server in Deutschland|Rechenzentrum|Serverstandort|in Deutschland gehostet|deutschen Servern)',
    'dsgvo': r'DSGVO',
    'sevdesk': r'sevdesk',
    'lexoffice': r'lexoffice',
    'lexware_office': r'Lexware Office',
    'lexware_allgemein': r'Lexware(?! Office)',
    'tarif_xl_public_api': r'(Tarif XL|Public API|Tarif\s+L\b|Premium)',
    'wettbewerber': r'(GoCardless|SEPAHeld|Mollie|PayPal)',
    'stripe_gebuehr': r'(Stripe-Geb[üu]hr|Transaktionsgeb[üu]hr|Prozent|%)',
    'sofort_geld': r'(sofort|umgehend|am selben Tag|innerhalb von 24)',
    'einzug_datum_frist': r'(Vorlauf|Bankarbeitstag|Fristen|Fälligkeit|F[äa]lligkeit)',
}

PRICE_RE = re.compile(r'\d{1,3}(?:\.\d{3})*(?:,\d{2})?\s?(?:EUR|€|Euro)')
DATE_RE = re.compile(r'\b\d{2}\.\d{2}\.20\d{2}\b')


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


def page_type(rel):
    r = rel.replace('\\', '/')
    if r == 'index.html':
        return 'startseite'
    if r == '404.html':
        return 'fehlerseite'
    first = r.split('/')[0]
    base = re.sub(r'\.html$', '', r)
    if base in ('impressum', 'datenschutz', 'agb', 'kontakt') or first in ('impressum', 'datenschutz', 'agb', 'kontakt'):
        return 'rechtliches_kontakt'
    if first == 'lp':
        return 'kampagnenseite'
    if first == 'ratgeber':
        return 'ratgeber_uebersicht' if base.endswith('ratgeber/index') else 'ratgeber'
    if first == 'anleitung' or 'einrichten' in base:
        return 'anleitung'
    if first == 'integrationen':
        return 'integration'
    if first == 'vergleich':
        return 'vergleich'
    if base in ('funktionen', 'preise', 'so-funktionierts', 'hilfe', 'faq', 'sicherheit') or first in ('funktionen', 'preise', 'so-funktionierts', 'hilfe', 'faq', 'sicherheit'):
        return 'produktseite'
    if first in ('ablauf', 'fuer-wen', 'glossar', 'grundlagen', 'mandat', 'ruecklastschrift'):
        return 'wissen'
    return 'keyword_landingpage'


def attr(tag, name):
    m = re.search(r'\b' + re.escape(name) + r'="([^"]*)"', tag)
    return html.unescape(m.group(1)) if m else ''


def jsonld_info(s):
    types, prices, faq = [], [], False

    def walk(node):
        nonlocal faq
        if isinstance(node, dict):
            t = node.get('@type')
            if t:
                if isinstance(t, list):
                    types.extend(str(x) for x in t)
                else:
                    types.append(str(t))
                if t == 'FAQPage' or (isinstance(t, list) and 'FAQPage' in t):
                    faq = True
            if 'price' in node and node.get('price') not in (None, ''):
                prices.append(str(node.get('price')) + ' ' + str(node.get('priceCurrency', '')))
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    for ld in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        try:
            walk(json.loads(ld))
        except Exception:
            types.append('UNGUELTIG')
    return sorted(set(types)), prices, faq


def analyse(domain):
    base = os.path.join(ROOT, domain)
    files = sorted(glob.glob(os.path.join(base, '**', '*.html'), recursive=True))
    sm_path = os.path.join(base, 'sitemap.xml')
    sitemap = set(re.findall(r'<loc>(.*?)</loc>', open(sm_path, encoding='utf-8').read())) if os.path.exists(sm_path) else set()
    pages = []
    inbound = defaultdict(set)
    for f in files:
        rel = os.path.relpath(f, base).replace('\\', '/')
        s = open(f, encoding='utf-8').read()
        url = url_for(domain, rel)
        title = re.search(r'<title>(.*?)</title>', s, re.S)
        desc = re.search(r'<meta name="description" content="([^"]*)"', s)
        robots = re.search(r'<meta name="robots" content="([^"]*)"', s)
        canonical = re.search(r'<link rel="canonical" href="([^"]+)"', s)
        og_image = re.search(r'property="og:image" content="([^"]+)"', s)
        h1 = re.findall(r'<h1[^>]*>(.*?)</h1>', s, re.S)
        h2 = re.findall(r'<h2[^>]*>(.*?)</h2>', s, re.S)
        text = main_content(s)
        robots_val = robots.group(1) if robots else ''
        noindex = 'noindex' in robots_val
        # Links
        internal, external, ctas = [], set(), []
        for a in re.findall(r'<a\b[^>]*>.*?</a>', s, re.S):
            href = attr(a, 'href')
            label = strip_tags(a)
            if not href or href.startswith('#'):
                continue
            if href.startswith(('mailto:', 'tel:')):
                continue
            if href.startswith('http'):
                host = re.sub(r'^https?://([^/]+).*$', r'\1', href)
                if host != domain:
                    external.add(host)
                    if 'app.smart-einzug.de' in host and ('btn' in a or 'data-cta' in a):
                        ctas.append({'text': label, 'href': href.split('?')[0]})
                continue
            path = href.split('?')[0].split('#')[0]
            if path.startswith('/assets/'):
                continue
            target = url_for(domain, path.lstrip('/') or 'index.html')
            if path.endswith('/') and path != '/':
                target = url_for(domain, path.lstrip('/') + 'index.html')
            internal.append(target)
            if 'btn' in a or 'data-cta' in a:
                ctas.append({'text': label, 'href': path})
        internal = sorted(set(internal))
        for t in internal:
            inbound[t].add(url)
        imgs = re.findall(r'<img\b[^>]*>', s)
        types, ld_prices, faq = jsonld_info(s)
        # Preise und Daten im sichtbaren Text (ohne Rechtsseiten-Unterscheidung, die folgt in der Bewertung)
        prices = sorted(set(PRICE_RE.findall(text)))
        dates = sorted(set(DATE_RE.findall(text)))
        meta_text = ' '.join([title.group(1) if title else '', desc.group(1) if desc else ''])
        prices_meta = sorted(set(PRICE_RE.findall(meta_text)))
        claims = {}
        for key, pat in CLAIM_PATTERNS.items():
            n = len(re.findall(pat, text, flags=re.I if key not in ('lexware_allgemein', 'lexware_office') else 0))
            if n:
                claims[key] = n
        pages.append({
            'domain': domain,
            'datei': rel,
            'url': url,
            'seitentyp': page_type(rel),
            'title': strip_tags(title.group(1)) if title else '',
            'title_laenge': len(strip_tags(title.group(1))) if title else 0,
            'description': html.unescape(desc.group(1)) if desc else '',
            'description_laenge': len(desc.group(1)) if desc else 0,
            'h1': [strip_tags(x) for x in h1],
            'h2': [strip_tags(x) for x in h2],
            'canonical': canonical.group(1) if canonical else '',
            'canonical_self': bool(canonical and canonical.group(1) == url),
            'robots_meta': robots_val,
            'indexierbar_technisch': not noindex,
            'in_sitemap': url in sitemap,
            'og_image': og_image.group(1) if og_image else '',
            'woerter_hauptinhalt': len(text.split()),
            'jsonld_typen': types,
            'jsonld_preise': ld_prices,
            'jsonld_faq': faq,
            'preise_text': prices,
            'preise_meta': prices_meta,
            'daten_text': dates,
            'bilder': len(imgs),
            'bilder_ohne_alt': sum(1 for i in imgs if 'alt=' not in i),
            'links_intern_ausgehend': internal,
            'links_extern_hosts': sorted(external),
            'ctas': ctas,
            'pruefwoerter': claims,
            'indexiert_bei_google': 'unbestaetigt (keine Search-Console-Daten im Repository)',
            'leistungsdaten': 'nicht vorhanden (Search Console, Analytics, Ads nicht im Repository)',
        })
    for p in pages:
        p['links_intern_eingehend'] = len(inbound.get(p['url'], set()))
        p['verwaist'] = p['links_intern_eingehend'] == 0 and p['datei'] not in ('index.html', '404.html')
    return pages


def render_md(all_pages):
    out = ['# URL-Inventar der Marketingseiten', '',
           'Erzeugt von `tools/seo-inventory.py` aus dem Repository. Indexierung bei Google und Leistungsdaten sind nicht bestätigt, weil keine Search-Console-, Analytics- oder Ads-Daten im Repository liegen. Preise und Daten stammen aus dem sichtbaren Hauptinhalt (ohne Navigation und Fußzeile).', '']
    for domain in DOMAINS:
        pages = [p for p in all_pages if p['domain'] == domain]
        if not pages:
            continue
        idx = sum(1 for p in pages if p['indexierbar_technisch'])
        out += [f'## {domain}', '', f'{len(pages)} HTML-Dateien, {idx} technisch indexierbar, {sum(1 for p in pages if p["in_sitemap"])} in der Sitemap.', '',
                '| Pfad | Typ | H1 | Index | Sitemap | Eingehend | Wörter | Preise | Daten | JSON-LD |',
                '|---|---|---|---|---|---|---|---|---|---|']
        for p in pages:
            h1 = (p['h1'][0] if p['h1'] else '(fehlt)').replace('|', '\\|')
            out.append('| {datei} | {typ} | {h1} | {idx} | {sm} | {inb} | {w} | {pr} | {dt} | {ld} |'.format(
                datei=p['datei'], typ=p['seitentyp'], h1=h1[:70],
                idx='ja' if p['indexierbar_technisch'] else 'noindex', sm='ja' if p['in_sitemap'] else 'nein',
                inb=p['links_intern_eingehend'], w=p['woerter_hauptinhalt'],
                pr=', '.join(p['preise_text'] + p['preise_meta'] + p['jsonld_preise']) or '',
                dt=', '.join(p['daten_text']) or '', ld=', '.join(p['jsonld_typen']) or ''))
        out.append('')
    return '\n'.join(out)


def main():
    all_pages = []
    for d in DOMAINS:
        if os.path.isdir(os.path.join(ROOT, d)):
            all_pages.extend(analyse(d))
    if '--stdout' in sys.argv:
        json.dump(all_pages, sys.stdout, ensure_ascii=False, indent=1)
        return
    os.makedirs(OUT_DIR, exist_ok=True)
    with open(os.path.join(OUT_DIR, 'url-inventar.json'), 'w', encoding='utf-8') as fh:
        json.dump(all_pages, fh, ensure_ascii=False, indent=1)
    with open(os.path.join(OUT_DIR, 'url-inventar.md'), 'w', encoding='utf-8') as fh:
        fh.write(render_md(all_pages))
    print(f'{len(all_pages)} Seiten inventarisiert -> docs/seo/url-inventar.json, docs/seo/url-inventar.md')


if __name__ == '__main__':
    main()
