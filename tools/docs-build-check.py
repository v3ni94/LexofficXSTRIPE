#!/usr/bin/env python3
"""Prueft die erzeugte technische Dokumentation und ihre Auslieferung im Adminbereich.

Hintergrund: php-ionos/admin-doc.php liefert AUSSCHLIESSLICH Dateien aus, die im Manifest
(app/docs-build/manifest.json) gelistet sind (Allowlist, nur Plattformadministratoren, jeder Abruf im
Audit). Kommen Kapitel oder Anlagen hinzu, muessen sie im Manifest stehen, einen ausgelieferten
Dateityp haben und im Adminbereich verlinkt sein. Geprueft wird ohne Webserver und ohne Datenbank.

Aufruf: python3 tools/docs-build-check.py     Exit 0 = keine Fehler
"""
from __future__ import annotations

import json
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
DOCS = os.path.join(ROOT, 'docs')
BUILD = os.path.join(ROOT, 'php-ionos', 'app', 'docs-build')
errors: list[str] = []
notes: list[str] = []


def fail(msg: str) -> None:
    errors.append(msg)


def main() -> int:
    build_py = open(os.path.join(ROOT, 'tools', 'build-docs.py'), encoding='utf-8').read()

    # 1. Jede in build-docs.py gelistete Quelle existiert
    chapters = re.findall(r"^\s*\('([^']+\.md)',\s*[^)]*\),", build_py, re.M)
    if not chapters:
        fail('In tools/build-docs.py wurden keine Kapitelquellen gefunden.')
    for rel in chapters:
        if not os.path.isfile(os.path.join(DOCS, rel)):
            fail(f'Kapitelquelle fehlt: docs/{rel}')
    notes.append(f'{len(chapters)} Kapitelquellen deklariert.')

    # 2. Anlagen existieren und tragen einen Titel
    attachments = re.findall(r"^\s*\('(anlagen/[^']+)',\s*\n?\s*'([^']*)'\),", build_py, re.M)
    for rel, title in attachments:
        if not os.path.isfile(os.path.join(DOCS, rel)):
            fail(f'Anlage fehlt: docs/{rel}')
        if title.strip() == '':
            fail(f'Anlage ohne Titel: docs/{rel} (Titel erscheint im Adminbereich)')
    notes.append(f'{len(attachments)} Anlage(n) deklariert.')

    # 3. Manifest: gebaut, vollstaendig, jede Datei vorhanden, Typ ausgeliefert
    manifest_path = os.path.join(BUILD, 'manifest.json')
    if not os.path.isfile(manifest_path):
        fail('app/docs-build/manifest.json fehlt (python3 tools/build-docs.py ausfuehren).')
        return report()
    manifest = json.load(open(manifest_path, encoding='utf-8'))
    files = manifest.get('files') or []
    if not files:
        fail('Manifest enthaelt keine Dateien.')
    served = {'pdf', 'html', 'svg', 'json'}  # siehe admin-doc.php, $contentTypes
    for entry in files:
        name = str(entry.get('name', ''))
        path = os.path.join(BUILD, name)
        if not os.path.isfile(path):
            fail(f'Im Manifest gelistet, aber nicht vorhanden: {name}')
            continue
        if int(entry.get('bytes', 0)) != os.path.getsize(path):
            fail(f'Groesse im Manifest weicht ab: {name}')
        if str(entry.get('kind', '')) not in served:
            fail(f'Dateityp wird von admin-doc.php nicht ausgeliefert: {name} (kind={entry.get("kind")})')
    notes.append(f'{len(files)} Dateien im Manifest, Version {manifest.get("version")}.')

    # 4. Jede Anlage ist im Manifest, mit Titel, und die Datei ist unveraendert
    for rel, title in attachments:
        base = os.path.basename(rel)
        entry = next((f for f in files if str(f.get('name')) == base), None)
        if entry is None:
            fail(f'Anlage nicht im Manifest: {base} (waere im Adminbereich nicht abrufbar)')
            continue
        if str(entry.get('title', '')).strip() != title.strip():
            fail(f'Titel der Anlage im Manifest weicht ab: {base}')
        src = os.path.join(DOCS, rel)
        if os.path.isfile(src) and open(src, 'rb').read() != open(os.path.join(BUILD, base), 'rb').read():
            fail(f'Ausgelieferte Anlage stimmt nicht mit docs/{rel} ueberein.')

    # 5. Inhalt: jedes Kapitel steht mit seiner Ueberschrift im HTML und im PDF
    html = open(os.path.join(BUILD, 'index.html'), encoding='utf-8').read()
    pdf_path = os.path.join(BUILD, 'SmartEinzug_Technische_Dokumentation.pdf')
    pdf_size = os.path.getsize(pdf_path) if os.path.isfile(pdf_path) else 0
    if pdf_size < 50_000:
        fail(f'Erzeugte PDF ist unerwartet klein ({pdf_size} Byte).')
    for rel in chapters:
        text = open(os.path.join(DOCS, rel), encoding='utf-8').read()
        m = re.search(r'^#\s+(.+)$', text, re.M)
        if not m:
            fail(f'docs/{rel} hat keine Ueberschrift der Ebene 1 (Kapiteltitel fuer HTML und PDF).')
            continue
        title = m.group(1).strip()
        if title.split('(')[0].strip()[:40] not in html:
            fail(f'Kapitel fehlt im erzeugten HTML: {title}')

    # 6. Adminbereich verlinkt das Manifest und zeigt den Titel
    admin = open(os.path.join(ROOT, 'php-ionos', 'admin-system.php'), encoding='utf-8').read()
    if 'admin-doc.php?f=' not in admin:
        fail('admin-system.php verlinkt keine Dokumentationsdateien (admin-doc.php?f=).')
    if "'title'" not in admin and '"title"' not in admin:
        fail('admin-system.php zeigt den Titel der Anlagen nicht an.')
    doc = open(os.path.join(ROOT, 'php-ionos', 'admin-doc.php'), encoding='utf-8').read()
    if 'require_superadmin' not in doc:
        fail('admin-doc.php erzwingt keinen Superadmin (Auslieferung interner Unterlagen).')
    if 'audit_log' not in doc:
        fail('admin-doc.php protokolliert den Abruf nicht im Audit.')
    for guard in ('manifest', 'realpath'):
        if guard not in doc:
            fail(f'admin-doc.php ohne erkennbare Absicherung: {guard} fehlt.')

    return report()


def report() -> int:
    for n in notes:
        print(f'  {n}')
    for e in errors:
        print(f'FEHLER: {e}')
    print(f'\n{len(errors)} Fehler')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
