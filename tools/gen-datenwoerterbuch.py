#!/usr/bin/env python3
"""
Datenwoerterbuch aus php-ionos/sql/schema.sql erzeugen: docs/entwickler/datenwoerterbuch.md

Quelle der Struktur ist ausschliesslich schema.sql (CREATE TABLE, ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
Indizes, Fremdschluessel, Kommentare). Fachliche Beschreibungen (Zweck, Modul, Statuswerte, erzeugende und
veraendernde Prozesse, Loeschung, Migrationen) kommen aus docs/entwickler/tabellen-beschreibungen.json.
Fehlt eine Beschreibung, steht "Beschreibung offen" im Text; tools/docs-build-check.py zaehlt diese Stellen.

Aufruf: python3 tools/gen-datenwoerterbuch.py [--check]   (--check: Exit 1, wenn die erzeugte Datei nicht aktuell ist)
"""
import json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCHEMA = os.path.join(ROOT, 'php-ionos', 'sql', 'schema.sql')
MIGR = os.path.join(ROOT, 'php-ionos', 'sql', 'migrations')
DESC = os.path.join(ROOT, 'docs', 'entwickler', 'tabellen-beschreibungen.json')
OUT = os.path.join(ROOT, 'docs', 'entwickler', 'datenwoerterbuch.md')


def strip_comment(line):
    # SQL-Kommentar am Zeilenende (-- ...) abtrennen, Kommentar zurueckgeben
    m = re.match(r'^(.*?)\s*--\s*(.*)$', line)
    if m and not re.search(r"'[^']*--", m.group(1)):
        return m.group(1).rstrip(), m.group(2).strip()
    return line.rstrip(), ''


def parse_schema(text):
    tables = {}
    order = []
    # CREATE TABLE
    for m in re.finditer(r'CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE=', text, re.S):
        name, body = m.group(1), m.group(2)
        t = {'columns': [], 'pk': [], 'fks': [], 'indexes': [], 'uniques': [], 'comment': ''}
        depth_lines = []
        buf = ''
        depth = 0
        for ch in body:
            if ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
            if ch == ',' and depth == 0:
                depth_lines.append(buf); buf = ''
            else:
                buf += ch
        if buf.strip():
            depth_lines.append(buf)
        for raw in depth_lines:
            raw = raw.strip('\n')
            code, comment = strip_comment(raw.strip())
            # Kommentare koennen ueber mehrere Zeilen im Rohtext stehen: nur letzte Zeile zaehlt fuer Spalte
            code = ' '.join(l.split('--')[0].strip() for l in raw.strip().split('\n'))
            comments = [strip_comment(l.strip())[1] for l in raw.strip().split('\n')]
            comment = ' '.join(c for c in comments if c)
            code = code.strip().rstrip(',').strip()
            if not code:
                continue
            up = code.upper()
            if up.startswith('PRIMARY KEY'):
                t['pk'] = re.findall(r'`?(\w+)`?', code[len('PRIMARY KEY'):])
            elif up.startswith('UNIQUE KEY') or up.startswith('UNIQUE INDEX') or up.startswith('UNIQUE ('):
                mm = re.match(r'UNIQUE(?: KEY| INDEX)?\s*`?(\w*)`?\s*\((.*)\)', code, re.I)
                if mm:
                    t['uniques'].append((mm.group(1), [c.strip(' `') for c in mm.group(2).split(',')]))
            elif up.startswith('KEY') or up.startswith('INDEX'):
                mm = re.match(r'(?:KEY|INDEX)\s*`?(\w+)`?\s*\((.*)\)', code, re.I)
                if mm:
                    t['indexes'].append((mm.group(1), [c.strip(' `') for c in mm.group(2).split(',')]))
            elif up.startswith('CONSTRAINT') or up.startswith('FOREIGN KEY'):
                mm = re.search(r'FOREIGN KEY\s*\(`?(\w+)`?\)\s*REFERENCES\s+`?(\w+)`?\s*\(`?(\w+)`?\)(.*)$', code, re.I)
                if mm:
                    t['fks'].append({'column': mm.group(1), 'ref_table': mm.group(2), 'ref_column': mm.group(3),
                                     'on': re.sub(r'\s+', ' ', mm.group(4).strip())})
            else:
                mm = re.match(r'`?(\w+)`?\s+(.*)$', code)
                if mm:
                    col, rest = mm.group(1), mm.group(2)
                    ctype = re.match(r'([A-Z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)', rest, re.I)
                    ctype = ctype.group(1) if ctype else rest
                    nullable = 'NOT NULL' not in rest.upper()
                    dm = re.search(r'DEFAULT\s+((?:\'[^\']*\')|[\w().]+)', rest, re.I)
                    default = dm.group(1) if dm else ''
                    extra = ' '.join(x for x in ['PRIMARY KEY' if 'PRIMARY KEY' in rest.upper() else '',
                                                 'AUTO_INCREMENT' if 'AUTO_INCREMENT' in rest.upper() else '',
                                                 'ON UPDATE CURRENT_TIMESTAMP' if 'ON UPDATE' in rest.upper() else ''] if x)
                    if 'PRIMARY KEY' in rest.upper():
                        t['pk'] = [col]
                    t['columns'].append({'name': col, 'type': ctype, 'null': nullable, 'default': default,
                                         'extra': extra, 'comment': comment, 'source': 'CREATE'})
        tables[name] = t
        order.append(name)
    # ALTER TABLE ADD COLUMN
    for m in re.finditer(r'ALTER TABLE\s+`?(\w+)`?\s+(.*?);', text, re.S):
        name, body = m.group(1), m.group(2)
        if name not in tables:
            tables[name] = {'columns': [], 'pk': [], 'fks': [], 'indexes': [], 'uniques': [], 'comment': ''}
            order.append(name)
        for part in re.split(r',\s*(?=ADD )', body):
            code_lines = part.strip().split('\n')
            code = ' '.join(l.split('--')[0].strip() for l in code_lines)
            comment = ' '.join(strip_comment(l.strip())[1] for l in code_lines if '--' in l)
            mm = re.match(r'ADD COLUMN(?: IF NOT EXISTS)?\s+`?(\w+)`?\s+(.*)$', code.strip(), re.I)
            if mm:
                col, rest = mm.group(1), mm.group(2)
                rest = re.sub(r'\s+AFTER\s+\w+\s*$', '', rest)
                ctype = re.match(r'([A-Z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)', rest, re.I)
                dm = re.search(r'DEFAULT\s+((?:\'[^\']*\')|[\w().]+)', rest, re.I)
                if not any(c['name'] == col for c in tables[name]['columns']):
                    tables[name]['columns'].append({'name': col, 'type': ctype.group(1) if ctype else rest,
                                                    'null': 'NOT NULL' not in rest.upper(), 'default': dm.group(1) if dm else '',
                                                    'extra': '', 'comment': comment.strip(), 'source': 'ALTER'})
                continue
            mm = re.match(r'ADD (?:UNIQUE )?(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.*?)\)', code.strip(), re.I)
            if mm:
                target = tables[name]['uniques'] if 'UNIQUE' in code.upper() else tables[name]['indexes']
                target.append((mm.group(1), [c.strip(' `') for c in mm.group(2).split(',')]))
                continue
            mm = re.search(r'ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(`?(\w+)`?\)\s*REFERENCES\s+`?(\w+)`?\s*\(`?(\w+)`?\)(.*)$', code.strip(), re.I)
            if mm:
                tables[name]['fks'].append({'column': mm.group(2), 'ref_table': mm.group(3), 'ref_column': mm.group(4),
                                            'on': re.sub(r'\s+', ' ', mm.group(5).strip())})
    return tables, order


def migrations_for(table):
    out = []
    try:
        for f in sorted(os.listdir(MIGR)):
            if not f.endswith('.sql'):
                continue
            txt = open(os.path.join(MIGR, f), encoding='utf-8').read()
            if re.search(r'\b(CREATE TABLE IF NOT EXISTS|ALTER TABLE|UPDATE|INSERT (?:IGNORE )?INTO)\s+`?' + re.escape(table) + r'`?\b', txt):
                out.append(f)
    except OSError:
        pass
    return out


def md_cell(s):
    return str(s).replace('|', '\\|').replace('\n', ' ')


def tenant_column(t):
    names = [c['name'] for c in t['columns']]
    for cand in ('tenant_id', 'organization_id'):
        if cand in names:
            return cand
    return None


def generate():
    text = open(SCHEMA, encoding='utf-8').read()
    tables, order = parse_schema(text)
    try:
        desc = json.load(open(DESC, encoding='utf-8'))
    except (OSError, ValueError):
        desc = {}
    lines = ['# Datenwörterbuch (alle Anwendungstabellen)', '',
             f'Erzeugt aus `php-ionos/sql/schema.sql` durch `tools/gen-datenwoerterbuch.py`; fachliche Angaben aus '
             f'`docs/entwickler/tabellen-beschreibungen.json`. {len(order)} Tabellen. Datenbank: MariaDB (Coolify-MariaDB auf dem VPS; '
             'Zeichensatz utf8mb4, Kollation utf8mb4_unicode_ci laut Tabellendefinitionen). Zeitangaben: DATETIME ohne Zeitzone; die Anwendung '
             'schreibt teils UTC (UTC_TIMESTAMP(), Kommentar UTC) und teils Serverzeit (NOW(), CURRENT_TIMESTAMP, Zeitzone des Containers TZ=Europe/Berlin), '
             'siehe Spaltenkommentare. Geldbeträge: Cent als INT (`*_cents`) oder DECIMAL(10,2) in EUR, siehe Spaltentyp.', '',
             'Legende: PK Primärschlüssel, FK Fremdschlüssel (von der Datenbank erzwungen), UQ eindeutig, IX Index. Beziehungen ohne FK-Eintrag werden nur durch Anwendungscode gesichert.', '']
    # Uebersicht
    lines += ['## Übersicht', '', '@@diagramm 05-datenbank-uebersicht', '', '@@diagramm 06-er-kern', '', '| Tabelle | Zweck | Modul | Mandantenspalte | Spalten | FK |', '|---|---|---|---|---|---|']
    open_count = 0
    for name in order:
        t = tables[name]; d = desc.get(name, {})
        zweck = d.get('zweck') or 'Beschreibung offen'
        if zweck == 'Beschreibung offen':
            open_count += 1
        lines.append(f"| [{name}](#{name.replace('_', '-')}) | {md_cell(zweck)} | {md_cell(d.get('modul', 'offen'))} | "
                     f"{tenant_column(t) or (d.get('mandant') or 'keine')} | {len(t['columns'])} | {len(t['fks'])} |")
    lines.append('')
    for name in order:
        t = tables[name]; d = desc.get(name, {})
        lines += [f'## {name}', '']
        lines.append(f"**Zweck:** {d.get('zweck') or 'Beschreibung offen'}  ")
        lines.append(f"**Modul:** {d.get('modul') or 'offen'}  ")
        lines.append(f"**Mandantenzuordnung:** {d.get('mandant') or (tenant_column(t) or 'keine (plattformweit)')}  ")
        if t['pk']:
            lines.append(f"**Primärschlüssel:** {', '.join(t['pk'])}  ")
        lines.append('')
        lines += ['| Spalte | Typ | NULL | Standard | Bedeutung |', '|---|---|---|---|---|']
        erl = d.get('spalten_erlaeuterung', {}) or {}
        for c in t['columns']:
            bedeutung = erl.get(c['name']) or c['comment'] or ''
            flags = []
            if c['name'] in t['pk']:
                flags.append('PK')
            for fk in t['fks']:
                if fk['column'] == c['name']:
                    flags.append(f"FK → {fk['ref_table']}.{fk['ref_column']}" + (f" ({fk['on']})" if fk['on'] else ''))
            for uq_name, cols in t['uniques']:
                if c['name'] in cols:
                    flags.append('UQ ' + (uq_name or ','.join(cols)))
            if c['extra']:
                flags.append(c['extra'])
            if c['source'] == 'ALTER':
                flags.append('per ALTER ergänzt')
            if flags:
                bedeutung = (bedeutung + ' ' if bedeutung else '') + '[' + '; '.join(flags) + ']'
            lines.append(f"| {c['name']} | {md_cell(c['type'])} | {'ja' if c['null'] else 'nein'} | {md_cell(c['default'])} | {md_cell(bedeutung)} |")
        lines.append('')
        if t['indexes'] or t['uniques']:
            ix = [f"UQ {n or '(ohne Namen)'} ({', '.join(cols)})" for n, cols in t['uniques']] + [f"IX {n} ({', '.join(cols)})" for n, cols in t['indexes']]
            lines.append('**Indizes und Eindeutigkeit:** ' + '; '.join(ix) + '  ')
        if t['fks']:
            lines.append('**Von der Datenbank erzwungene Beziehungen:** ' + '; '.join(
                f"{fk['column']} → {fk['ref_table']}.{fk['ref_column']}{' (' + fk['on'] + ')' if fk['on'] else ''}" for fk in t['fks']) + '  ')
        else:
            lines.append('**Von der Datenbank erzwungene Beziehungen:** keine (Beziehungen nur im Anwendungscode).  ')
        st = d.get('statuswerte') or {}
        if st:
            lines.append('**Statuswerte und Übergänge:**  ')
            for k, v in st.items():
                lines.append(f'- `{k}`: {v}')
        for key, label in (('erzeugt_durch', 'Erzeugt durch'), ('veraendert_durch', 'Verändert durch'), ('gelesen_durch', 'Gelesen durch')):
            v = d.get(key)
            if v:
                lines.append(f"**{label}:** {', '.join(v) if isinstance(v, list) else v}  ")
        if d.get('loeschung'):
            lines.append(f"**Löschung, Archivierung, Aufbewahrung:** {d['loeschung']}  ")
        if d.get('geld_zeit_extern'):
            lines.append(f"**Geldbeträge, Zeit, externe IDs, Verschlüsselung:** {d['geld_zeit_extern']}  ")
        migs = d.get('migrationen') or migrations_for(name)
        lines.append(f"**Migrationen:** {', '.join(migs) if migs else 'nur schema.sql (Erstanlage)'}  ")
        if d.get('besonderheiten'):
            lines.append(f"**Besonderheiten:** {d['besonderheiten']}  ")
        lines.append('')
    lines += ['## Offene Beschreibungen', '', f'{open_count} Tabelle(n) ohne fachliche Beschreibung in `tabellen-beschreibungen.json`.', '']
    return '\n'.join(lines) + '\n'


def main(argv):
    content = generate()
    if '--check' in argv:
        cur = open(OUT, encoding='utf-8').read() if os.path.isfile(OUT) else ''
        if cur != content:
            print('docs/entwickler/datenwoerterbuch.md ist nicht aktuell. Abhilfe: python3 tools/gen-datenwoerterbuch.py')
            return 1
        print('Datenwörterbuch aktuell.')
        return 0
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    open(OUT, 'w', encoding='utf-8').write(content)
    print(f'geschrieben: {os.path.relpath(OUT, ROOT)} ({content.count(chr(10))} Zeilen)')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
