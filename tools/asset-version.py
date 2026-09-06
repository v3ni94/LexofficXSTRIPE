#!/usr/bin/env python3
"""
Inhaltsbasierte Versionierung fuer site.css und site.js.

Ablauf: Das Skript laeuft nach jeder Aenderung an einer site.css oder
site.js, jeweils vor dem Commit. Es berechnet je Domain einen kurzen Hash
aus dem aktuellen Dateiinhalt und traegt ihn als ?v=-Parameter in alle
HTML-Dateien der Domain ein. So bekommt ein geaendertes Asset trotz der
langen Cache-Dauer (max-age=31536000, immutable laut .htaccess) sofort
eine neue URL und wird beim Nutzer nicht laenger aus dem alten Cache
ausgeliefert.

Aufruf:  python3 tools/asset-version.py           schreibt die Aenderungen
         python3 tools/asset-version.py --check   prueft nur (Exit 1 bei Abweichung)
"""
import glob
import hashlib
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'websites')
DOMAINS = ['smart-einzug.de', 'lexware-einzug.de', 'lexoffice-einzug.de', 'lastschrift-einfach.de']
HASH_LEN = 10

# Pfad je Asset relativ zum Domain-Ordner, sowie das Muster, das die
# Einbindung im HTML findet (mit optionalem bestehendem v=-Parameter).
ASSETS = {
    'css': ('assets/css/site.css', re.compile(r'assets/css/site\.css(\?v=([^"\'\s]*))?')),
    'js': ('assets/js/site.js', re.compile(r'assets/js/site\.js(\?v=([^"\'\s]*))?')),
}


def file_hash(path):
    """Berechnet den Kurz-Hash (SHA-256, erste HASH_LEN Hexzeichen) einer Datei."""
    with open(path, 'rb') as f:
        digest = hashlib.sha256(f.read()).hexdigest()
    return digest[:HASH_LEN]


def domain_hashes(domain):
    """Liefert die aktuellen Hash-Werte einer Domain als {'css': ..., 'js': ...}.

    Fehlt eine Asset-Datei, steht an ihrer Stelle None und sie wird bei
    Pruefung und Ersetzung uebersprungen.
    """
    base = os.path.join(ROOT, domain)
    hashes = {}
    for key, (rel, _pattern) in ASSETS.items():
        path = os.path.join(base, rel)
        hashes[key] = file_hash(path) if os.path.isfile(path) else None
    return hashes


def find_asset_problems(text, hashes):
    """Vergleicht die im HTML-Text gefundenen v=-Werte mit den erwarteten Hashes.

    Gibt eine Liste von Texten wie 'site.css: v=abc123 statt v=def456' zurueck,
    eine leere Liste, wenn alles passt. Fehlt der Parameter ganz, wird 'kein
    v=-Parameter' gemeldet.
    """
    problems = []
    for key, (rel, pattern) in ASSETS.items():
        expected = hashes.get(key)
        if expected is None:
            continue
        name = os.path.basename(rel)
        for m in pattern.finditer(text):
            found = m.group(2)
            if found != expected:
                if found is None:
                    problems.append(f'{name}: kein v=-Parameter (erwartet v={expected})')
                else:
                    problems.append(f'{name}: v={found} statt v={expected}')
    return problems


def update_text(text, hashes):
    """Setzt in allen Einbindungen von site.css/site.js den passenden Hash.

    Andere Assets bleiben unangetastet. Gibt (neuer_text, anzahl_ersetzungen)
    zurueck.
    """
    count = 0
    for key, (rel, pattern) in ASSETS.items():
        expected = hashes.get(key)
        if expected is None:
            continue

        def repl(m, rel=rel, expected=expected):
            nonlocal count
            if m.group(2) != expected:
                count += 1
            return f'{rel}?v={expected}'

        text = pattern.sub(repl, text)
    return text, count


def process_domain(domain, check_only):
    """Verarbeitet eine Domain, gibt (hashes, geaenderte_dateien) zurueck."""
    base = os.path.join(ROOT, domain)
    hashes = domain_hashes(domain)
    changed = []
    for path in sorted(glob.glob(os.path.join(base, '**', '*.html'), recursive=True)):
        with open(path, encoding='utf-8') as f:
            text = f.read()
        if check_only:
            if find_asset_problems(text, hashes):
                changed.append(os.path.relpath(path, base))
        else:
            new_text, cnt = update_text(text, hashes)
            if cnt:
                changed.append(os.path.relpath(path, base))
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new_text)
    return hashes, changed


def main():
    check_only = '--check' in sys.argv
    total_changed = 0
    for domain in DOMAINS:
        hashes, changed = process_domain(domain, check_only)
        total_changed += len(changed)
        css_v = hashes['css'] or 'fehlt'
        js_v = hashes['js'] or 'fehlt'
        if check_only:
            if changed:
                print(f'{domain}: {len(changed)} Datei(en) weichen ab (css soll v={css_v}, js soll v={js_v})')
                for rel in changed:
                    print(f'  {rel}')
            else:
                print(f'{domain}: aktuell (css=v={css_v}, js=v={js_v})')
        else:
            print(f'{domain}: {len(changed)} Datei(en) geaendert (css=v={css_v}, js=v={js_v})')
    if check_only and total_changed:
        sys.exit(1)


if __name__ == '__main__':
    main()
