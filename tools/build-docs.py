#!/usr/bin/env python3
"""Erzeugt die technische Dokumentation von SmartEinzug (PDF, HTML, Diagramme, Manifest).

Liest die Markdown-Dateien unter docs/vps/*.md sowie docs/migrations.md, docs/monitoring.md,
docs/multiaccount.md, docs/device-trust.md, docs/status-page.md, docs/sync-performance.md und
erzeugt in php-ionos/app/docs-build/:

  - SmartEinzug_Technische_Dokumentation.pdf  (reportlab, Titelseite, Inhaltsverzeichnis, Kapitel,
    Diagramme als reportlab-Grafik, Fußzeile mit Seitenzahl)
  - index.html                                (alle Kapitel als HTML, Diagramme als SVG eingebettet)
  - diagramme/*.svg                           (dieselben Diagramme als eigenständige SVG-Dateien)
  - manifest.json                             ({"version", "generated_at", "commit", "files": [...]})

Die Diagrammdaten (Knoten, Kanten) stehen in einer zentralen Datenstruktur (DIAGRAMS), damit SVG-
und PDF-Ausgabe exakt denselben Inhalt zeigen. Nur Python-Standardbibliothek plus reportlab
(bereits installiert).

Aufruf: python3 tools/build-docs.py
"""
from __future__ import annotations

import json
import math
import os
import re
import subprocess
import sys
from datetime import datetime, timezone

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
DOCS_DIR = os.path.join(ROOT, 'docs')
OUT_DIR = os.path.join(ROOT, 'php-ionos', 'app', 'docs-build')
DIAGRAM_DIR = os.path.join(OUT_DIR, 'diagramme')
VERSION_FILE = os.path.join(ROOT, 'php-ionos', 'app', 'version.php')

# --- SmartEinzug-Farben --------------------------------------------------------------------------
ANTHRAZIT = '#2E2D2E'
GOLD = '#E3AC48'
BEIGE = '#FBF6EC'
GRAU = '#9F9F9F'
WEISS = '#FFFFFF'

# Kapitel in Anzeigereihenfolge: (Dateipfad relativ zu docs/, Anzeigename für die Kopfzeile)
CHAPTERS = [
    ('vps/01-architektur.md', None),
    ('vps/02-einrichtung-vps.md', None),
    ('vps/03-github-deployment.md', None),
    ('vps/04-datenbankmigration.md', None),
    ('vps/05-dns-ssl.md', None),
    ('vps/06-betrieb.md', None),
    ('vps/07-cutover-checkliste.md', None),
    ('vps/08-hostinger-coolify.md', None),
    ('migrations.md', None),
    ('monitoring.md', None),
    ('multiaccount.md', None),
    ('device-trust.md', None),
    ('status-page.md', None),
    ('sync-performance.md', None),
]


# ===================================================================================================
# Diagrammdaten (zentrale Quelle fuer SVG und PDF)
# ===================================================================================================

def box(node_id, x, y, w, h, label, sub=None, fill=GOLD, stroke=ANTHRAZIT, text_color=ANTHRAZIT):
    return {'id': node_id, 'x': x, 'y': y, 'w': w, 'h': h, 'label': label, 'sub': sub,
            'fill': fill, 'stroke': stroke, 'text_color': text_color}


def edge(src, dst, label=None, dashed=False, color=ANTHRAZIT):
    return {'from': src, 'to': dst, 'label': label, 'dashed': dashed, 'color': color}


def group(x, y, w, h, label):
    return {'x': x, 'y': y, 'w': w, 'h': h, 'label': label}


def _architektur():
    nodes = [
        box('internet', 30, 30, 150, 50, 'Internet', fill=BEIGE, stroke=GRAU),
        box('dns', 220, 30, 150, 50, 'DNS', fill=BEIGE, stroke=GRAU),
        box('webhosting', 410, 20, 230, 90, 'Webhosting', 'Marketingdomains\nsmart-einzug.de u.a.'),
        box('traefik', 90, 190, 200, 60, 'Coolify-Proxy (Traefik)', 'TLS, Ports 80/443'),
        box('caddy', 330, 190, 180, 60, 'Caddy (intern)', 'HTTP, php_fastcgi'),
        box('php', 330, 270, 180, 60, 'PHP-FPM', 'Anwendung'),
        box('mariadb', 330, 350, 180, 60, 'MariaDB 11.8 (Coolify)', 'private Ressource, kein Port'),
        box('redis', 330, 430, 180, 60, 'Redis', 'optional'),
        box('scheduler', 550, 270, 170, 60, 'Scheduler', 'Tick alle 30 s'),
        box('queue', 550, 350, 170, 60, 'Queue', 'Tabelle jobs'),
        box('workers', 550, 430, 170, 80, 'Worker W1-W3', 'Pools: Lexware, Stripe,\nMail, Wartung'),
        box('backup', 330, 530, 180, 60, 'Coolify-Backup', 'täglich, Hetzner Object Storage'),
        box('metrics', 550, 530, 170, 60, 'Host-Metriken', 'CPU/RAM/Platte/Load'),
        box('lexware', 810, 270, 200, 60, 'Lexware Office', 'externe API', fill=BEIGE, stroke=GRAU),
        box('stripe', 810, 350, 200, 60, 'Stripe', 'externe API', fill=BEIGE, stroke=GRAU),
    ]
    edges = [
        edge('internet', 'dns'),
        edge('dns', 'webhosting'),
        edge('dns', 'traefik'),
        edge('traefik', 'caddy'),
        edge('caddy', 'php'),
        edge('php', 'mariadb'),
        edge('php', 'redis'),
        edge('php', 'queue', 'Job anlegen'),
        edge('scheduler', 'queue', 'Job erzeugen'),
        edge('queue', 'workers', 'reserviert'),
        edge('workers', 'lexware'),
        edge('workers', 'stripe'),
        edge('workers', 'mariadb', 'Ergebnis schreiben', dashed=True),
        edge('mariadb', 'backup', 'Dump durch Coolify', dashed=True),
        edge('metrics', 'php', 'Kennzahlen', dashed=True, color=GRAU),
    ]
    groups = [group(70, 150, 950, 460, 'Hostinger-VPS KVM 8 (Coolify)')]
    return {'key': 'architektur', 'title': 'Architektur: Webhosting und VPS',
            'w': 1060, 'h': 640, 'nodes': nodes, 'edges': edges, 'groups': groups}


def _deployment():
    nodes = [
        box('developer', 30, 40, 150, 50, 'Developer', fill=BEIGE, stroke=GRAU),
        box('github', 220, 40, 150, 50, 'GitHub'),
        box('actions', 410, 40, 170, 60, 'Actions', 'Lint, QA, Doku-Build'),
        box('sftp', 410, 150, 190, 60, 'SFTP-Upload', 'Webhosting'),
        box('migrate_web', 410, 240, 190, 60, 'Migration', 'POST migrate.php'),
        box('ssh', 660, 150, 190, 60, 'SSH-Deploy', 'rsync auf den VPS'),
        box('dockerdeploy', 660, 240, 190, 60, 'Docker-Deploy', 'deploy.sh <sha>'),
        box('migrate_vps', 660, 330, 190, 60, 'Migration', 'bin/migrate.php'),
        box('healthcheck', 660, 420, 190, 60, 'Healthcheck', 'health.php'),
    ]
    edges = [
        edge('developer', 'github', 'push'),
        edge('github', 'actions'),
        edge('actions', 'sftp', 'Webhosting-Zweig'),
        edge('actions', 'ssh', 'VPS-Zweig'),
        edge('sftp', 'migrate_web'),
        edge('ssh', 'dockerdeploy'),
        edge('dockerdeploy', 'migrate_vps'),
        edge('migrate_vps', 'healthcheck'),
    ]
    return {'key': 'deployment', 'title': 'Deployment: GitHub Actions',
            'w': 900, 'h': 520, 'nodes': nodes, 'edges': edges, 'groups': []}


def _jobs():
    nodes = [
        box('scheduler', 30, 30, 150, 60, 'Scheduler', 'alle 30 s'),
        box('erzeugen', 220, 30, 150, 60, 'Job erzeugen'),
        box('queue', 410, 30, 150, 60, 'Queue', 'Tabelle jobs'),
        box('reserviert', 600, 30, 160, 60, 'Worker reserviert', 'FOR UPDATE SKIP LOCKED'),
        box('processing', 800, 30, 150, 60, 'Processing'),
        box('apicall', 990, 30, 150, 60, 'API-Call', 'Lexware/Stripe'),
        box('dbupdate', 990, 150, 150, 60, 'DB-Update'),
        box('completed', 990, 260, 150, 60, 'Completed', fill=GOLD),
        box('error', 800, 260, 140, 55, 'Error'),
        box('retry', 600, 260, 140, 55, 'Retry'),
        box('backoff', 600, 360, 140, 55, 'Backoff', '60/300/900/3600 s'),
        box('failed', 410, 360, 190, 60, 'Failed / Dead Letter', 'max. Versuche erreicht', fill=BEIGE, stroke=GRAU),
    ]
    edges = [
        edge('scheduler', 'erzeugen'),
        edge('erzeugen', 'queue'),
        edge('queue', 'reserviert'),
        edge('reserviert', 'processing'),
        edge('processing', 'apicall'),
        edge('apicall', 'dbupdate', 'Erfolg'),
        edge('dbupdate', 'completed'),
        edge('apicall', 'error', 'Fehler', dashed=True, color=GRAU),
        edge('error', 'retry'),
        edge('retry', 'backoff', 'erneuter Versuch'),
        edge('backoff', 'queue', 'zurück in die Warteschlange', dashed=True),
        edge('retry', 'failed', 'max. Versuche', dashed=True, color=GRAU),
    ]
    return {'key': 'jobs', 'title': 'Ablauf eines Jobs (Erfolgs- und Fehlerpfad)',
            'w': 1180, 'h': 460, 'nodes': nodes, 'edges': edges, 'groups': []}


def _er_diagramm():
    # Spalten x Zeilen, Boxbreite/-hoehe einheitlich
    w, h, gx, gy = 260, 78, 30, 30
    cols = 4
    tables = [
        ('users', 'PK id', None),
        ('organizations', 'PK id', None),
        ('organization_members', 'PK id', 'FK organization_id, user_id'),
        ('integrations', 'PK id', 'FK tenant_id (1:1 je Firma)'),
        ('customers', 'PK id', 'FK tenant_id'),
        ('sepa_mandates', 'PK id', 'FK tenant_id, customer_id'),
        ('invoices', 'PK id', 'FK tenant_id, customer_id'),
        ('payment_collections', 'PK id', 'FK tenant_id, invoice_id, mandate_id'),
        ('sync_state', 'PK tenant_id', 'FK tenant_id (1:1 je Firma)'),
        ('sync_runs', 'PK id', 'FK tenant_id'),
        ('jobs', 'PK id', 'tenant_id ohne FK (Mandant optional)'),
        ('job_runs', 'PK id', 'job_key ohne FK (fachlicher Schlüssel)'),
        ('worker_heartbeats', 'PK worker_id', 'ohne FK'),
        ('api_circuits', 'PK api', 'ohne FK'),
        ('monitor_checks', 'PK id', 'ohne FK'),
        ('audit_log', 'PK id', 'ohne FK (organization_id, user_id als Referenz)'),
    ]
    nodes = []
    for i, (name, pk, fk) in enumerate(tables):
        col = i % cols
        row = i // cols
        sub = pk + ('\n' + fk if fk else '')
        fill = GOLD if not fk or 'ohne FK' not in fk else BEIGE
        nodes.append(box(name, gx + col * (w + 30), gy + row * (h + 30), w, h, name, sub,
                          fill=fill, stroke=ANTHRAZIT))
    edges = [
        edge('organization_members', 'organizations'),
        edge('organization_members', 'users'),
        edge('integrations', 'organizations'),
        edge('sync_state', 'organizations'),
        edge('customers', 'organizations'),
        edge('sepa_mandates', 'organizations'),
        edge('sepa_mandates', 'customers'),
        edge('invoices', 'organizations'),
        edge('invoices', 'customers'),
        edge('payment_collections', 'organizations'),
        edge('payment_collections', 'invoices'),
        edge('payment_collections', 'sepa_mandates'),
        edge('sync_runs', 'organizations'),
    ]
    total_w = gx + cols * (w + 30)
    total_h = gy + math.ceil(len(tables) / cols) * (h + 30)
    return {'key': 'er-diagramm', 'title': 'ER-Diagramm (Auswahl der wichtigsten Tabellen)',
            'w': total_w, 'h': total_h, 'nodes': nodes, 'edges': edges, 'groups': []}


def _db_uebergang():
    nodes = [
        box('alt', 30, 100, 220, 100, 'ALT', 'Webhosting-MariaDB\nIONOS', fill=BEIGE, stroke=GRAU),
        box('migration', 320, 100, 220, 100, 'MIGRATION', 'Export -> Prüfsumme\n-> Import -> Abgleich'),
        box('neu', 610, 100, 220, 100, 'NEU', 'VPS-MariaDB\nDocker-Container', fill=GOLD),
    ]
    edges = [
        edge('alt', 'migration', 'Dump (mysqldump)'),
        edge('migration', 'neu', 'db-import.sh'),
        edge('neu', 'alt', 'db-verify.php (Abgleich)', dashed=True, color=GRAU),
    ]
    return {'key': 'db-uebergang', 'title': 'Datenbankübergang Webhosting -> VPS',
            'w': 880, 'h': 260, 'nodes': nodes, 'edges': edges, 'groups': []}


DIAGRAMS = [_architektur(), _deployment(), _jobs(), _er_diagramm(), _db_uebergang()]


# ===================================================================================================
# Geometrie: Kanten an der Boxkante andocken (nicht am Mittelpunkt)
# ===================================================================================================

def _center(n):
    return n['x'] + n['w'] / 2, n['y'] + n['h'] / 2


def _connect(n1, n2):
    cx1, cy1 = _center(n1)
    cx2, cy2 = _center(n2)
    dx, dy = cx2 - cx1, cy2 - cy1
    if abs(dx) >= abs(dy):
        if dx >= 0:
            p1 = (n1['x'] + n1['w'], cy1)
            p2 = (n2['x'], cy2)
        else:
            p1 = (n1['x'], cy1)
            p2 = (n2['x'] + n2['w'], cy2)
    else:
        if dy >= 0:
            p1 = (cx1, n1['y'] + n1['h'])
            p2 = (cx2, n2['y'])
        else:
            p1 = (cx1, n1['y'])
            p2 = (cx2, n2['y'] + n2['h'])
    return p1, p2


# ===================================================================================================
# SVG-Rendering
# ===================================================================================================

def _esc(s):
    return (s or '').replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def render_svg(diag):
    W, H = diag['w'], diag['h']
    nodes = {n['id']: n for n in diag['nodes']}
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" '
             f'width="{W}" height="{H}" font-family="Helvetica, Arial, sans-serif">']
    parts.append(f'<rect x="0" y="0" width="{W}" height="{H}" fill="{BEIGE}"/>')
    parts.append(
        '<defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" '
        f'markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{ANTHRAZIT}"/>'
        '</marker></defs>'
    )
    for g in diag.get('groups', []):
        parts.append(f'<rect x="{g["x"]}" y="{g["y"]}" width="{g["w"]}" height="{g["h"]}" '
                      f'fill="none" stroke="{GRAU}" stroke-width="1.5" stroke-dasharray="6,4" rx="10"/>')
        parts.append(f'<text x="{g["x"] + 12}" y="{g["y"] + 22}" font-size="13" fill="{GRAU}">'
                      f'{_esc(g["label"])}</text>')
    for e in diag['edges']:
        n1, n2 = nodes[e['from']], nodes[e['to']]
        p1, p2 = _connect(n1, n2)
        color = e.get('color', ANTHRAZIT)
        dash = ' stroke-dasharray="6,5"' if e.get('dashed') else ''
        parts.append(f'<line x1="{p1[0]:.1f}" y1="{p1[1]:.1f}" x2="{p2[0]:.1f}" y2="{p2[1]:.1f}" '
                      f'stroke="{color}" stroke-width="2"{dash} marker-end="url(#arrow)"/>')
        if e.get('label'):
            mx, my = (p1[0] + p2[0]) / 2, (p1[1] + p2[1]) / 2
            lw = max(30, len(e['label']) * 6.4)
            parts.append(f'<rect x="{mx - lw / 2:.1f}" y="{my - 9:.1f}" width="{lw:.1f}" height="16" '
                          f'fill="{BEIGE}"/>')
            parts.append(f'<text x="{mx:.1f}" y="{my + 4:.1f}" font-size="11" text-anchor="middle" '
                          f'fill="{color}">{_esc(e["label"])}</text>')
    for n in diag['nodes']:
        fill = n.get('fill', GOLD)
        stroke = n.get('stroke', ANTHRAZIT)
        tc = n.get('text_color', ANTHRAZIT)
        parts.append(f'<rect x="{n["x"]}" y="{n["y"]}" width="{n["w"]}" height="{n["h"]}" rx="8" '
                      f'fill="{fill}" stroke="{stroke}" stroke-width="1.5"/>')
        lines = [n['label']] + (n['sub'].split('\n') if n.get('sub') else [])
        block_h = 16 * (len(lines) - 1) + 14
        ty0 = n['y'] + n['h'] / 2 - block_h / 2 + 12
        for i, line in enumerate(lines):
            fs = 13 if i == 0 else 10.5
            fw = 'bold' if i == 0 else 'normal'
            parts.append(f'<text x="{n["x"] + n["w"] / 2}" y="{ty0 + i * 16:.1f}" font-size="{fs}" '
                          f'font-weight="{fw}" text-anchor="middle" fill="{tc}">{_esc(line)}</text>')
    parts.append('</svg>')
    return '\n'.join(parts)


# ===================================================================================================
# PDF-Rendering (reportlab), dieselben Diagrammdaten
# ===================================================================================================

def draw_diagram_pdf(c, diag, x0, y0, box_w, box_h):
    from reportlab.lib.colors import HexColor
    scale = min(box_w / diag['w'], box_h / diag['h'])
    ox = x0 + (box_w - diag['w'] * scale) / 2
    oy = y0 + (box_h - diag['h'] * scale) / 2

    def tx(x):
        return ox + x * scale

    def ty(y):
        return oy + (diag['h'] - y) * scale

    nodes = {n['id']: n for n in diag['nodes']}

    c.setFillColor(HexColor(BEIGE))
    c.rect(x0, y0, box_w, box_h, fill=1, stroke=0)

    for g in diag.get('groups', []):
        c.setStrokeColor(HexColor(GRAU))
        c.setDash(6, 4)
        c.setLineWidth(1.2)
        c.rect(tx(g['x']), ty(g['y'] + g['h']), g['w'] * scale, g['h'] * scale, fill=0, stroke=1)
        c.setDash()
        c.setFillColor(HexColor(GRAU))
        c.setFont('Helvetica', 7.5)
        c.drawString(tx(g['x']) + 4, ty(g['y']) - 11, g['label'])

    for e in diag['edges']:
        n1, n2 = nodes[e['from']], nodes[e['to']]
        p1, p2 = _connect(n1, n2)
        color = e.get('color', ANTHRAZIT)
        c.setStrokeColor(HexColor(color))
        if e.get('dashed'):
            c.setDash(4, 3)
        else:
            c.setDash()
        c.setLineWidth(1.1)
        x1, y1, x2, y2 = tx(p1[0]), ty(p1[1]), tx(p2[0]), ty(p2[1])
        c.line(x1, y1, x2, y2)
        c.setDash()
        ang = math.atan2(y2 - y1, x2 - x1)
        size = 5
        a1, a2 = ang + math.radians(150), ang - math.radians(150)
        c.setFillColor(HexColor(color))
        path = c.beginPath()
        path.moveTo(x2, y2)
        path.lineTo(x2 + size * math.cos(a1), y2 + size * math.sin(a1))
        path.lineTo(x2 + size * math.cos(a2), y2 + size * math.sin(a2))
        path.close()
        c.drawPath(path, fill=1, stroke=0)
        if e.get('label'):
            mx, my = (x1 + x2) / 2, (y1 + y2) / 2
            lw = max(20, len(e['label']) * 3.1)
            c.setFillColor(HexColor(BEIGE))
            c.rect(mx - lw / 2, my - 4, lw, 8, fill=1, stroke=0)
            c.setFillColor(HexColor(color))
            c.setFont('Helvetica', 5.5)
            c.drawCentredString(mx, my - 2, e['label'])

    for n in diag['nodes']:
        c.setFillColor(HexColor(n.get('fill', GOLD)))
        c.setStrokeColor(HexColor(n.get('stroke', ANTHRAZIT)))
        c.setLineWidth(0.9)
        nx, ny, nw, nh = tx(n['x']), ty(n['y'] + n['h']), n['w'] * scale, n['h'] * scale
        c.roundRect(nx, ny, nw, nh, 5, fill=1, stroke=1)
        c.setFillColor(HexColor(n.get('text_color', ANTHRAZIT)))
        lines = [n['label']] + (n['sub'].split('\n') if n.get('sub') else [])
        fs0, fs1 = 7.5, 6
        block_h = fs0 + max(0, len(lines) - 1) * (fs1 + 2)
        start_y = ny + nh / 2 + block_h / 2 - fs0 * 0.75
        for i, line in enumerate(lines):
            if i == 0:
                c.setFont('Helvetica-Bold', fs0)
                c.drawCentredString(nx + nw / 2, start_y, line)
            else:
                c.setFont('Helvetica', fs1)
                c.drawCentredString(nx + nw / 2, start_y - i * (fs1 + 2), line)


class DiagramFlowable:
    """Reportlab-Flowable, das ein Diagramm aus DIAGRAMS in den Textfluss einbettet."""

    def __init__(self, diag, width, height):
        from reportlab.platypus import Flowable

        class _F(Flowable):
            def __init__(self_, diag_, width_, height_):
                Flowable.__init__(self_)
                self_.diag = diag_
                self_.width = width_
                self_.height = height_

            def wrap(self_, avail_w, avail_h):
                return self_.width, self_.height

            def draw(self_):
                draw_diagram_pdf(self_.canv, self_.diag, 0, 0, self_.width, self_.height)

        self._impl = _F(diag, width, height)

    def flowable(self):
        return self._impl


# ===================================================================================================
# Markdown -> Blöcke (gemeinsame Grundlage fuer HTML und PDF)
# ===================================================================================================

def parse_markdown(text):
    """Sehr einfacher Markdown-Parser: Ueberschriften, Absaetze, Listen, Tabellen, Codebloecke."""
    lines = text.split('\n')
    blocks = []
    i = 0
    n = len(lines)
    while i < n:
        line = lines[i]
        stripped = line.strip()
        if stripped == '':
            i += 1
            continue
        if stripped.startswith('```'):
            i += 1
            code_lines = []
            while i < n and not lines[i].strip().startswith('```'):
                code_lines.append(lines[i])
                i += 1
            i += 1  # schliessendes ```
            blocks.append(('code', '\n'.join(code_lines)))
            continue
        m = re.match(r'^(#{1,4})\s+(.*)$', stripped)
        if m:
            level = len(m.group(1))
            blocks.append((f'h{level}', m.group(2).strip()))
            i += 1
            continue
        if stripped.startswith('|'):
            table_lines = []
            while i < n and lines[i].strip().startswith('|'):
                table_lines.append(lines[i].strip())
                i += 1
            rows = []
            for tl in table_lines:
                cells = [c.strip() for c in tl.strip('|').split('|')]
                if all(re.match(r'^:?-{2,}:?$', c) for c in cells):
                    continue  # Trennzeile
                rows.append(cells)
            if rows:
                blocks.append(('table', rows))
            continue
        if re.match(r'^(-|\*)\s+', stripped):
            items = []
            while i < n and re.match(r'^(-|\*)\s+', lines[i].strip()):
                items.append(re.sub(r'^(-|\*)\s+', '', lines[i].strip()))
                i += 1
            blocks.append(('ul', items))
            continue
        if re.match(r'^\d+\.\s+', stripped):
            items = []
            while i < n and re.match(r'^\d+\.\s+', lines[i].strip()):
                items.append(re.sub(r'^\d+\.\s+', '', lines[i].strip()))
                i += 1
            blocks.append(('ol', items))
            continue
        if stripped.startswith('- [ ]') or stripped.startswith('- [x]'):
            items = []
            while i < n and (lines[i].strip().startswith('- [ ]') or lines[i].strip().startswith('- [x]')):
                checked = lines[i].strip().startswith('- [x]')
                items.append((checked, re.sub(r'^- \[[ x]\]\s*', '', lines[i].strip())))
                i += 1
            blocks.append(('checklist', items))
            continue
        # Absatz: Zeilen sammeln bis Leerzeile oder neuer Blockstart
        para_lines = [stripped]
        i += 1
        while i < n and lines[i].strip() != '' and not re.match(r'^(#{1,4}\s|```|\||-\s|\*\s|\d+\.\s)', lines[i].strip()):
            para_lines.append(lines[i].strip())
            i += 1
        blocks.append(('p', ' '.join(para_lines)))
    return blocks


def _inline_html(text):
    text = _esc(text)
    text = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', text)
    text = re.sub(r'`([^`]+)`', r'<code>\1</code>', text)
    text = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', text)
    return text


def blocks_to_html(blocks):
    out = []
    for kind, content in blocks:
        if kind in ('h1', 'h2', 'h3', 'h4'):
            out.append(f'<{kind}>{_inline_html(content)}</{kind}>')
        elif kind == 'p':
            out.append(f'<p>{_inline_html(content)}</p>')
        elif kind == 'ul':
            out.append('<ul>' + ''.join(f'<li>{_inline_html(i)}</li>' for i in content) + '</ul>')
        elif kind == 'ol':
            out.append('<ol>' + ''.join(f'<li>{_inline_html(i)}</li>' for i in content) + '</ol>')
        elif kind == 'checklist':
            out.append('<ul class="checklist">' + ''.join(
                f'<li>{"☑" if c else "☐"} {_inline_html(t)}</li>' for c, t in content) + '</ul>')
        elif kind == 'table':
            rows = content
            head, *body = rows
            out.append('<div class="table-wrap"><table>')
            out.append('<thead><tr>' + ''.join(f'<th>{_inline_html(c)}</th>' for c in head) + '</tr></thead>')
            out.append('<tbody>')
            for r in body:
                out.append('<tr>' + ''.join(f'<td>{_inline_html(c)}</td>' for c in r) + '</tr>')
            out.append('</tbody></table></div>')
        elif kind == 'code':
            out.append(f'<pre><code>{_esc(content)}</code></pre>')
    return '\n'.join(out)


# ===================================================================================================
# HTML-Ausgabe
# ===================================================================================================

def build_html(version, generated_at, commit, chapter_htmls, chapter_titles, diagram_svgs):
    nav = ''.join(f'<li><a href="#kapitel-{i}">{_esc(t)}</a></li>' for i, t in enumerate(chapter_titles))
    chapters_html = ''.join(
        f'<section id="kapitel-{i}">{html_block}</section>'
        for i, html_block in enumerate(chapter_htmls)
    )
    diagrams_html = ''.join(
        f'<figure><figcaption>{_esc(d["title"])}</figcaption>{svg}</figure>'
        for d, svg in diagram_svgs
    )
    return f"""<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>SmartEinzug: Technische Dokumentation</title>
<style>
  body {{ font-family: Helvetica, Arial, sans-serif; margin: 0; background: {BEIGE}; color: {ANTHRAZIT}; }}
  header {{ background: {ANTHRAZIT}; color: {WEISS}; padding: 24px 32px; }}
  header h1 {{ margin: 0 0 4px 0; font-size: 22px; }}
  header p {{ margin: 0; color: {GOLD}; font-size: 13px; }}
  nav {{ background: {WEISS}; border-bottom: 1px solid #ddd; padding: 12px 32px; }}
  nav ul {{ list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 12px; }}
  nav a {{ color: {ANTHRAZIT}; text-decoration: none; font-size: 13px; border-bottom: 1px solid {GOLD}; }}
  main {{ max-width: 980px; margin: 0 auto; padding: 24px 32px 80px 32px; }}
  section {{ background: {WEISS}; padding: 24px 32px; margin-bottom: 24px; border-radius: 8px; }}
  h1 {{ color: {ANTHRAZIT}; border-bottom: 3px solid {GOLD}; padding-bottom: 6px; }}
  h2 {{ color: {ANTHRAZIT}; margin-top: 28px; }}
  h3 {{ color: {ANTHRAZIT}; }}
  code, pre {{ background: {BEIGE}; }}
  pre {{ padding: 12px; overflow-x: auto; border-radius: 6px; }}
  .table-wrap {{ overflow-x: auto; }}
  table {{ border-collapse: collapse; width: 100%; font-size: 13px; }}
  th, td {{ border: 1px solid #ddd; padding: 6px 10px; text-align: left; vertical-align: top; }}
  th {{ background: {ANTHRAZIT}; color: {WEISS}; }}
  ul.checklist {{ list-style: none; padding-left: 4px; }}
  figure {{ margin: 0 0 32px 0; text-align: center; }}
  figure svg {{ max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 6px; background: {BEIGE}; }}
  figcaption {{ font-weight: bold; margin-bottom: 8px; }}
</style>
</head>
<body>
<header>
  <h1>SmartEinzug: Technische Dokumentation</h1>
  <p>Version {_esc(version)} &middot; erzeugt {_esc(generated_at)} &middot; Commit {_esc(commit)}</p>
</header>
<nav><ul>{nav}<li><a href="#diagramme">Diagramme</a></li></ul></nav>
<main>
{chapters_html}
<section id="diagramme">
<h1>Diagramme</h1>
{diagrams_html}
</section>
</main>
</body>
</html>
"""


# ===================================================================================================
# PDF-Ausgabe
# ===================================================================================================

def build_pdf(path, version, generated_at, commit, chapter_blocks, chapter_titles):
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.units import mm
    from reportlab.lib.colors import HexColor
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer,
                                     Table, TableStyle, Preformatted, PageBreak, ListFlowable,
                                     ListItem, NextPageTemplate)
    from reportlab.platypus.tableofcontents import TableOfContents

    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle('SEH1', parent=styles['Heading1'], textColor=HexColor(ANTHRAZIT),
                               spaceBefore=14, spaceAfter=8, borderColor=HexColor(GOLD)))
    styles.add(ParagraphStyle('SEH2', parent=styles['Heading2'], textColor=HexColor(ANTHRAZIT),
                               spaceBefore=10, spaceAfter=6))
    styles.add(ParagraphStyle('SEH3', parent=styles['Heading3'], textColor=HexColor(ANTHRAZIT),
                               spaceBefore=8, spaceAfter=4))
    styles.add(ParagraphStyle('SEBody', parent=styles['BodyText'], fontName='Helvetica',
                               fontSize=9.5, leading=13, spaceAfter=6))
    styles.add(ParagraphStyle('SECode', parent=styles['Code'], fontSize=7.5, leading=9.5,
                               backColor=HexColor(BEIGE)))
    styles.add(ParagraphStyle('SETitle', parent=styles['Title'], textColor=HexColor(ANTHRAZIT),
                               fontSize=26))
    styles.add(ParagraphStyle('SESub', parent=styles['Normal'], textColor=HexColor(GOLD),
                               fontSize=12, alignment=1))
    styles.add(ParagraphStyle('TOCHeading', parent=styles['SEH2']))

    def para(text):
        return Paragraph(_inline_html(text), styles['SEBody'])

    story = []

    # --- Titelseite ---
    story.append(Spacer(1, 60 * mm))
    story.append(Paragraph('SmartEinzug', styles['SETitle']))
    story.append(Paragraph('Technische Dokumentation', ParagraphStyle(
        'sub2', parent=styles['Normal'], fontSize=16, alignment=1, textColor=HexColor(ANTHRAZIT),
        spaceBefore=6)))
    story.append(Spacer(1, 20 * mm))
    story.append(Paragraph(f'Version {version}', styles['SESub']))
    story.append(Paragraph(f'Stand: {generated_at}', styles['SESub']))
    story.append(Paragraph(f'Commit: {commit}', styles['SESub']))
    story.append(Spacer(1, 10 * mm))
    story.append(Paragraph('Müller Holding AG', ParagraphStyle(
        'operator', parent=styles['Normal'], fontSize=10, alignment=1, textColor=HexColor(GRAU))))
    story.append(PageBreak())

    # --- Inhaltsverzeichnis ---
    toc = TableOfContents()
    toc.levelStyles = [
        ParagraphStyle('TOCLevel0', parent=styles['Normal'], fontSize=11, leftIndent=0, firstLineIndent=0,
                        spaceBefore=4, textColor=HexColor(ANTHRAZIT)),
    ]
    story.append(Paragraph('Inhaltsverzeichnis', styles['SEH1']))
    story.append(toc)
    story.append(PageBreak())

    # --- Kapitel ---
    for title, blocks in zip(chapter_titles, chapter_blocks):
        for kind, content in blocks:
            if kind == 'h1':
                story.append(Paragraph(_inline_html(content), styles['SEH1']))
            elif kind == 'h2':
                story.append(Paragraph(_inline_html(content), styles['SEH2']))
            elif kind in ('h3', 'h4'):
                story.append(Paragraph(_inline_html(content), styles['SEH3']))
            elif kind == 'p':
                story.append(para(content))
            elif kind == 'ul':
                story.append(ListFlowable(
                    [ListItem(para(i), leftIndent=6) for i in content],
                    bulletType='bullet', start='•', leftIndent=14))
                story.append(Spacer(1, 4))
            elif kind == 'ol':
                story.append(ListFlowable(
                    [ListItem(para(i), leftIndent=6) for i in content],
                    bulletType='1', leftIndent=14))
                story.append(Spacer(1, 4))
            elif kind == 'checklist':
                story.append(ListFlowable(
                    [ListItem(para(t), leftIndent=6, value=('☑' if c else '☐')) for c, t in content],
                    bulletType='bullet', leftIndent=14))
                story.append(Spacer(1, 4))
            elif kind == 'table':
                head, *body = content
                data = [head] + body
                # Zellen umbrechen als Paragraph, damit lange Tabellen nicht ueberlaufen
                wrapped = [[Paragraph(_inline_html(str(c)), styles['SEBody']) for c in row] for row in data]
                t = Table(wrapped, repeatRows=1)
                t.setStyle(TableStyle([
                    ('BACKGROUND', (0, 0), (-1, 0), HexColor(ANTHRAZIT)),
                    ('TEXTCOLOR', (0, 0), (-1, 0), HexColor(WEISS)),
                    ('GRID', (0, 0), (-1, -1), 0.5, HexColor('#CCCCCC')),
                    ('VALIGN', (0, 0), (-1, -1), 'TOP'),
                    ('FONTSIZE', (0, 0), (-1, -1), 8),
                ]))
                story.append(t)
                story.append(Spacer(1, 6))
            elif kind == 'code':
                story.append(Preformatted(content, styles['SECode']))
                story.append(Spacer(1, 6))
        story.append(PageBreak())

    # --- Diagramme ---
    story.append(Paragraph('Anhang: Diagramme', styles['SEH1']))
    from reportlab.platypus import Flowable
    page_w, page_h = A4
    content_w = page_w - 40 * mm
    for d in DIAGRAMS:
        story.append(Paragraph(d['title'], styles['SEH2']))
        diag_h = min(230, content_w * d['h'] / d['w'])
        story.append(DiagramFlowable(d, content_w, diag_h).flowable())
        story.append(Spacer(1, 10))

    # --- DocTemplate mit TOC-Unterstuetzung und Fusszeile ---
    class SEDocTemplate(BaseDocTemplate):
        def afterFlowable(self, flowable):
            if isinstance(flowable, Paragraph) and flowable.style.name == 'SEH1':
                text = flowable.getPlainText()
                if text in ('Inhaltsverzeichnis', 'Anhang: Diagramme'):
                    return
                key = f'h1-{id(flowable)}-{self.page}'
                self.canv.bookmarkPage(key)
                self.canv.addOutlineEntry(text, key, level=0, closed=False)
                self.notify('TOCEntry', (0, text, self.page, key))

    def on_page(canv, doc_):
        canv.saveState()
        canv.setFont('Helvetica', 8)
        canv.setFillColor(HexColor(GRAU))
        canv.drawString(20 * mm, 12 * mm, 'SmartEinzug, Müller Holding AG')
        canv.drawRightString(page_w - 20 * mm, 12 * mm, f'Seite {doc_.page}')
        canv.setStrokeColor(HexColor(GOLD))
        canv.setLineWidth(1)
        canv.line(20 * mm, 16 * mm, page_w - 20 * mm, 16 * mm)
        canv.restoreState()

    frame = Frame(20 * mm, 20 * mm, page_w - 40 * mm, page_h - 35 * mm, id='normal')
    doc = SEDocTemplate(path, pagesize=A4)
    doc.addPageTemplates([PageTemplate(id='all', frames=[frame], onPage=on_page)])
    doc.multiBuild(story)


# ===================================================================================================
# Hilfsfunktionen
# ===================================================================================================

def read_version():
    try:
        text = open(VERSION_FILE, encoding='utf-8').read()
        m = re.search(r"const APP_VERSION = '([^']+)'", text)
        return m.group(1) if m else 'unbekannt'
    except OSError:
        return 'unbekannt'


def read_commit():
    try:
        out = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT,
                              capture_output=True, text=True, timeout=10, check=False)
        sha = out.stdout.strip()
        return sha if out.returncode == 0 and sha else 'unbekannt'
    except (OSError, subprocess.SubprocessError):
        return 'unbekannt'


def chapter_title(blocks, fallback):
    for kind, content in blocks:
        if kind == 'h1':
            return content
    return fallback


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    os.makedirs(DIAGRAM_DIR, exist_ok=True)

    version = read_version()
    commit = read_commit()
    generated_at = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

    chapter_blocks_list = []
    chapter_titles = []
    chapter_htmls = []
    missing = []
    for rel_path, _ in CHAPTERS:
        full_path = os.path.join(DOCS_DIR, rel_path)
        if not os.path.isfile(full_path):
            missing.append(rel_path)
            continue
        text = open(full_path, encoding='utf-8').read()
        blocks = parse_markdown(text)
        chapter_blocks_list.append(blocks)
        title = chapter_title(blocks, os.path.basename(rel_path))
        chapter_titles.append(title)
        chapter_htmls.append(blocks_to_html(blocks))
    if missing:
        print('Hinweis: folgende Quelldateien fehlen und wurden ausgelassen:', ', '.join(missing),
              file=sys.stderr)

    # SVGs schreiben (einzeln) und fuer HTML/Manifest vorhalten
    diagram_svgs = []
    written_files = []
    for d in DIAGRAMS:
        svg = render_svg(d)
        svg_path = os.path.join(DIAGRAM_DIR, f'{d["key"]}.svg')
        with open(svg_path, 'w', encoding='utf-8') as f:
            f.write(svg)
        written_files.append(svg_path)
        diagram_svgs.append((d, svg))

    # HTML schreiben
    html = build_html(version, generated_at, commit, chapter_htmls, chapter_titles, diagram_svgs)
    html_path = os.path.join(OUT_DIR, 'index.html')
    with open(html_path, 'w', encoding='utf-8') as f:
        f.write(html)
    written_files.append(html_path)

    # PDF schreiben
    pdf_path = os.path.join(OUT_DIR, 'SmartEinzug_Technische_Dokumentation.pdf')
    build_pdf(pdf_path, version, generated_at, commit, chapter_blocks_list, chapter_titles)
    written_files.append(pdf_path)

    # Manifest schreiben
    def kind_of(path):
        if path.endswith('.pdf'):
            return 'pdf'
        if path.endswith('.html'):
            return 'html'
        if path.endswith('.svg'):
            return 'svg'
        return 'other'

    manifest = {
        'version': version,
        'generated_at': generated_at,
        'commit': commit,
        'files': [
            {'name': os.path.relpath(p, OUT_DIR), 'bytes': os.path.getsize(p), 'kind': kind_of(p)}
            for p in written_files
        ],
    }
    manifest_path = os.path.join(OUT_DIR, 'manifest.json')
    with open(manifest_path, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
        f.write('\n')

    print(f'Dokumentation erzeugt: {OUT_DIR}')
    print(f'  Version {version}, Commit {commit}, erzeugt {generated_at}')
    for entry in manifest['files']:
        print(f"  {entry['name']:50s} {entry['bytes']:>10d} Byte  ({entry['kind']})")
    print(f'  manifest.json: {os.path.relpath(manifest_path, OUT_DIR)}')


if __name__ == '__main__':
    main()
