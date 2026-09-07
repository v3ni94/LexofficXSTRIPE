#!/usr/bin/env python3
"""Prueft das Dokumentationssystem (Quellen, Diagramme, Datenwoerterbuch, Revisionen, erzeugte Ausgaben, Auslieferung).

Hintergrund: php-ionos/admin-doc.php und handbuch.php liefern AUSSCHLIESSLICH Dateien aus, die im Manifest
(app/docs-build/manifest.json, Schema 2) gelistet sind, mit Zugriffsstufe je Datei. Kommen Kapitel oder Anlagen
hinzu, muessen sie im Manifest stehen und einen ausgelieferten Dateityp haben. Geprueft wird ohne Webserver und
ohne Datenbank; die Erzeugung selbst laeuft vorher mit python3 tools/build-docs.py.

Aufruf: python3 tools/docs-build-check.py     Exit 0 = keine Fehler
"""
from __future__ import annotations

import json, os, re, subprocess, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
DOCS = os.path.join(ROOT, 'docs')
BUILD = os.path.join(ROOT, 'php-ionos', 'app', 'docs-build')
errors: list[str] = []
notes: list[str] = []
SECRET_PATTERNS = [r'sk_live_[A-Za-z0-9]{8,}', r'rk_live_[A-Za-z0-9]{8,}', r'whsec_[A-Za-z0-9]{12,}', r'-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----\s*\n[A-Za-z0-9+/=]{40,}',
                   r"'pass'\s*=>\s*'(?![<H])[^']{6,}'", r'AKIA[0-9A-Z]{16}']


def fail(msg: str) -> None:
    errors.append(msg)


def run(cmd: list[str]) -> int:
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True).returncode


def main() -> int:
    build_py = open(os.path.join(ROOT, 'tools', 'build-docs.py'), encoding='utf-8').read()

    # 1) Kapitelquellen und Ueberschriften
    chapters = re.findall(r"'([\w/.-]+\.md)'", build_py[build_py.index('DOCUMENTS = ['):build_py.index('ATTACHMENTS = [')])
    if not chapters:
        fail('In tools/build-docs.py wurden keine Kapitelquellen gefunden.')
    for rel in chapters:
        p = os.path.join(DOCS, rel)
        if not os.path.isfile(p):
            fail(f'Kapitelquelle fehlt: docs/{rel}')
            continue
        text = open(p, encoding='utf-8').read()
        ohne_code = re.sub(r'```.*?```', '', text, flags=re.S)
        h1 = re.findall(r'^# .+$', ohne_code, re.M)
        if len(h1) != 1:
            fail(f'docs/{rel}: genau eine Ueberschrift der Ebene 1 erwartet, gefunden {len(h1)}.')
        for m in re.finditer(r'^@@diagramm\s+([\w-]+)', text, re.M):
            if not os.path.isfile(os.path.join(DOCS, 'diagramme', m.group(1) + '.mmd')):
                fail(f'docs/{rel} verweist auf unbekanntes Diagramm {m.group(1)}.')
        for m in re.finditer(r'\]\((docs/[^)#\s]+|[a-z0-9_./-]+\.md)\)', text):
            target = m.group(1)
            cand = os.path.join(ROOT, target) if target.startswith('docs/') else os.path.join(os.path.dirname(p), target)
            if not os.path.exists(cand):
                notes.append(f'docs/{rel}: Verweis auf nicht vorhandene Datei {target}')
    notes.append(f'{len(chapters)} Kapitelquellen deklariert.')

    # 2) Anlagen
    attachments = re.findall(r"^\s*\('(anlagen/[^']+)',\s*\n?\s*'([^']*)'\),", build_py, re.M)
    for rel, title in attachments:
        if not os.path.isfile(os.path.join(DOCS, rel)):
            fail(f'Anlage fehlt: docs/{rel}')
        if not title.strip():
            fail(f'Anlage ohne Titel: docs/{rel}')
    notes.append(f'{len(attachments)} Anlage(n) deklariert.')

    # 3) Diagramme und Datenwoerterbuch aktuell
    if run([sys.executable, 'tools/render-mermaid.py', '--check']) != 0:
        fail('Diagramme nicht aktuell gerendert (python3 tools/render-mermaid.py).')
    if run([sys.executable, 'tools/gen-datenwoerterbuch.py', '--check']) != 0:
        fail('Datenwoerterbuch nicht aktuell (python3 tools/gen-datenwoerterbuch.py).')

    # 4) Revisionen
    rev_path = os.path.join(DOCS, 'dokumentationsregeln', 'revisionen.json')
    try:
        revisions = json.load(open(rev_path, encoding='utf-8'))
    except (OSError, ValueError):
        revisions = {}
        fail('docs/dokumentationsregeln/revisionen.json fehlt oder ist ungueltig.')
    codes = re.findall(r"'code':\s*'(\w+)'", build_py)
    for code in codes:
        r = revisions.get(code) or {}
        if not re.match(r'^r\d+$', str(r.get('revision', ''))) or not re.match(r'^\d{2}\.\d{2}\.\d{4}$', str(r.get('date', ''))):
            fail(f'Revision fuer Dokument {code} fehlt oder hat falsches Format (rN, TT.MM.JJJJ).')

    # 5) Manifest und Ausgaben
    manifest_path = os.path.join(BUILD, 'manifest.json')
    if not os.path.isfile(manifest_path):
        fail('app/docs-build/manifest.json fehlt (python3 tools/build-docs.py ausfuehren).')
        return finish()
    manifest = json.load(open(manifest_path, encoding='utf-8'))
    if manifest.get('schema') != 2:
        fail('Manifest hat nicht Schema 2.')
    if manifest.get('status') != 'complete':
        fail(f"Erzeugung unvollstaendig: {manifest.get('missing')}")
    version_php = open(os.path.join(ROOT, 'php-ionos', 'app', 'version.php'), encoding='utf-8').read()
    app_version = re.search(r"const APP_VERSION = '([^']+)'", version_php).group(1)
    if manifest.get('version') != app_version:
        fail(f"Manifest-Version {manifest.get('version')} passt nicht zu APP_VERSION {app_version} (Build wiederholen).")
    served = {'pdf', 'html', 'svg', 'json', 'png'}
    for entry in manifest.get('files') or []:
        name = entry.get('name', '')
        path = os.path.join(BUILD, name)
        if not name or not os.path.isfile(path):
            fail(f'Im Manifest gelistet, aber nicht vorhanden: {name}')
            continue
        if os.path.getsize(path) != entry.get('bytes'):
            fail(f'Groesse im Manifest weicht ab: {name}')
        if entry.get('kind') not in served:
            fail(f'Dateityp wird nicht ausgeliefert: {name} (kind={entry.get("kind")})')
        if entry.get('access') not in ('technical', 'admin', 'customer'):
            fail(f'Unbekannte Zugriffsstufe fuer {name}: {entry.get("access")}')
    docs_ = manifest.get('documents') or []
    if len(docs_) != len(codes):
        fail(f'Manifest enthaelt {len(docs_)} Dokumente, deklariert sind {len(codes)}.')
    for d in docs_:
        for key in ('html', 'pdf', 'search'):
            if not os.path.isfile(os.path.join(BUILD, d.get(key, ''))):
                fail(f"Dokument {d.get('code')}: Datei {key} fehlt.")
        if os.path.getsize(os.path.join(BUILD, d['pdf'])) < 50_000:
            fail(f"Dokument {d.get('code')}: PDF unerwartet klein.")
        if d.get('access') == 'customer':
            # Kundenfassung darf keine internen Betriebsdaten enthalten
            html = open(os.path.join(BUILD, d['html']), encoding='utf-8').read()
            for word in ('/opt/smarteinzug', 'shared/config.php', 'docker compose', '72.61.80.67', 'srv1960492'):
                if word in html:
                    fail(f'Kundenfassung enthaelt interne Betriebsangabe: {word}')
        for ch in d.get('chapters') or []:
            if not os.path.isfile(os.path.join(BUILD, ch.get('pdf', ''))):
                fail(f"Dokument {d.get('code')}: Kapitel-PDF fehlt: {ch.get('pdf')}")
    for base, title in [(os.path.basename(rel), t) for rel, t in attachments]:
        if not any(f.get('name') == base for f in manifest.get('files') or []):
            fail(f'Anlage nicht im Manifest: {base}')

    # 6) Geheimnisse in Quellen und Ausgaben
    scan_files = [os.path.join(DOCS, rel) for rel in chapters] + [os.path.join(BUILD, d['html']) for d in docs_ if os.path.isfile(os.path.join(BUILD, d.get('html', '')))]
    for p in scan_files:
        text = open(p, encoding='utf-8', errors='ignore').read()
        for pat in SECRET_PATTERNS:
            if re.search(pat, text):
                fail(f'Moegliches Geheimnis in {os.path.relpath(p, ROOT)} (Muster {pat}).')
        if '—' in text and p.startswith(DOCS):
            notes.append(f'Gedankenstrich in {os.path.relpath(p, ROOT)} (Stilregel).')

    # 7) Auslieferung im Adminbereich und Kundenanwendung
    admin = open(os.path.join(ROOT, 'php-ionos', 'admin-system.php'), encoding='utf-8').read()
    for needle in ('admin-doc.php/', 'docs_can_access', 'docs_archive_list', 'revision'):
        if needle not in admin:
            fail(f'admin-system.php: Baustein fehlt: {needle}')
    for f, needles in (('admin-doc.php', ('require_platform', 'docs_serve')), ('handbuch.php', ('require_login', "'customer'")),
                       ('app/docs.php', ('technical_readers', 'realpath'))):
        text = open(os.path.join(ROOT, 'php-ionos', f), encoding='utf-8').read()
        for n in needles:
            if n not in text:
                fail(f'{f}: Baustein fehlt: {n}')
    if run(['php', 'tools/docs-access-check.php']) != 0:
        fail('Zugriffsregeln der Dokumentation verletzt (php tools/docs-access-check.php).')
    deploy = open(os.path.join(ROOT, 'deploy', 'vps', 'scripts', 'deploy.sh'), encoding='utf-8').read()
    if 'docs-archive' not in deploy:
        fail('deploy.sh archiviert den Dokumentationsstand nicht (shared/docs-archive).')
    claude = open(os.path.join(ROOT, 'CLAUDE.md'), encoding='utf-8').read()
    if 'Dokumentationspflicht' not in claude:
        fail('CLAUDE.md: Abschnitt Dokumentationspflicht fehlt.')
    return finish()


def finish() -> int:
    for n in notes:
        print('  ' + n)
    for e in errors:
        print('FEHLER: ' + e)
    print(f'{len(errors)} Fehler')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
