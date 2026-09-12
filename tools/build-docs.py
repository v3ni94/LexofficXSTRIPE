#!/usr/bin/env python3
"""
Dokumentationssystem SmartEinzug: erzeugt aus den Markdown-Quellen unter docs/ drei Dokumentationen
(Unternehmen, Entwickler und Betrieb, Kunden) als HTML (mit Inhaltsverzeichnis, Kapitelnavigation, Suchindex)
und als PDF im CI der Mueller Holding AG (Deckblatt, Klassifizierung, Softwarestand, Dokumentrevision,
klickbares Inhaltsverzeichnis, Kopf- und Fussband, Seitenzahlen), dazu je Kapitel eine PDF-Datei, sowie ein
manifest.json (Schema 2) als Allowlist fuer die Auslieferung ueber php-ionos/admin-doc.php und handbuch.php.

Ausgabe: php-ionos/app/docs-build/ (gitignored, im GitHub-Workflow erzeugt und mit dem Release ausgeliefert):
  <code>/index.html, <code>/suche.json, <code>/kapitel-NN-<slug>.pdf, <code>.pdf, diagramme/*.svg|png, manifest.json

Diagramme: versionierte Mermaid-Quellen docs/diagramme/*.mmd, gerendert nach docs/diagramme/build/ (SVG fuer HTML,
PNG fuer PDF) durch tools/render-mermaid.py; im Markdown eingebunden ueber eine Zeile "@@diagramm <name>".

Rein lesend gegenueber der Anwendung; keine Datenbank, kein Netz. Nur Python-Standardbibliothek plus reportlab.
Pruefung: python3 tools/docs-build-check.py
"""
import hashlib, json, os, re, shutil, subprocess, sys
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS_DIR = os.path.join(ROOT, 'docs')
DIAG_DIR = os.path.join(DOCS_DIR, 'diagramme', 'build')
CI_DIR = os.path.join(DOCS_DIR, 'ci')
OUT_DIR = os.path.join(ROOT, 'php-ionos', 'app', 'docs-build')
VERSION_FILE = os.path.join(ROOT, 'php-ionos', 'app', 'version.php')
REVISIONS_FILE = os.path.join(DOCS_DIR, 'dokumentationsregeln', 'revisionen.json')

GOLD, ANTHRAZIT, GRAU, BEIGE, WEISS, HAIR = '#E3AC48', '#2E2D2E', '#9F9F9F', '#FBF6EC', '#FFFFFF', '#DDDBD6'
FIRMA = 'Müller Holding AG'
REGISTER = ('Sitz: Monheim am Rhein · Registergericht: Amtsgericht Düsseldorf · HRB 104291 · '
            'Vorstand: Timo Müller · Aufsichtsratsvorsitzender: Jan Walprecht')
MAIL, WEB = 'kontakt@mueller-holding.ag', 'mueller-holding.ag'

# Klassifizierungen: oeffentlich | kundenbezogen | intern | streng vertraulich.
# access: technical (nur docs.technical_readers), admin (alle Plattformadministratoren), customer (angemeldete Kunden).
DOCUMENTS = [
    {
        'code': 'unternehmen', 'title': 'Unternehmens- und Verkaufsdokumentation',
        'audience': 'Kaufinteressenten, externe Prüfer, Unternehmens- und Softwareübergabe',
        'classification': 'intern', 'access': 'admin',
        'chapters': ['unternehmen/unternehmensdokumentation.md', 'unternehmen/go-live-leitfaden.md'],
    },
    {
        'code': 'entwickler', 'title': 'Entwickler- und Betriebsdokumentation',
        'audience': 'Programmierer, Administratoren, technische Übernehmer',
        'classification': 'streng vertraulich', 'access': 'technical',
        'chapters': [
            'entwickler/architektur.md', 'entwickler/hosts.md', 'entwickler/repository.md',
            'entwickler/datenwoerterbuch.md', 'entwickler/datenbank.md', 'entwickler/geschaeftslogik.md', 'entwickler/schnittstellen.md',
            'entwickler/jobs.md', 'entwickler/email-system.md', 'entwickler/sicherheit.md',
            'entwickler/einrichtung.md', 'entwickler/fehlerhandbuch.md', 'entwickler/tests-und-nachverfolgbarkeit.md',
            'vps/01-architektur.md', 'vps/02-einrichtung-vps.md', 'vps/03-github-deployment.md',
            'vps/04-datenbankmigration.md', 'vps/05-dns-ssl.md', 'vps/06-betrieb.md',
            'vps/07-cutover-checkliste.md', 'vps/08-hostinger-coolify.md',
            'betrieb-migration-vps.md', 'migrations.md', 'queue-worker.md', 'monitoring.md', 'multiaccount.md',
            'device-trust.md', 'status-page.md', 'sync-performance.md', 'integrations.md', 'einwilligungen.md',
            'sevdesk.md', 'mail-einrichtung.md', 'marketing.md', 'rechtsdokumente.md',
            'dokumentationsregeln/README.md', 'entwickler/abdeckung-und-offene-punkte.md',
            'audit/AUDIT_REPORT.md', 'audit/PAYMENT_INVARIANTS.md', 'audit/TEST_MATRIX.md',
            'audit/PERFORMANCE_REPORT.md', 'audit/RELEASE_CHECKLIST.md', 'audit/HANDOVER.md',
        ],
    },
    {
        'code': 'kunden', 'title': 'Benutzerhandbuch',
        'audience': 'Kunden: Einrichtung und tägliche Nutzung von SmartEinzug',
        'classification': 'kundenbezogen', 'access': 'customer',
        'chapters': ['kunden/handbuch.md'],
    },
    {
        'code': 'marketing', 'title': 'SEO- und Marketingdokumentation',
        'audience': 'Geschäftsführung, Marketing und Redaktion der Marketingseiten',
        'classification': 'intern', 'access': 'admin',
        'chapters': [
            'seo/README.md', 'seo/01-bestandsaufnahme.md', 'seo/02-faktenregister.md', 'seo/03-aussagenpruefung.md',
            'seo/04-keyword-map.md', 'seo/05-massnahmenplan.md', 'seo/06-mess-und-pflegekonzept.md', 'seo/07-abschlussbericht.md',
            'seo/SEO_PROJECT_CONTEXT.md', 'seo/SEO_AUDIT.md', 'seo/SEO_SCHEMA_REPORT.md',
            'seo/SEO_CONTENT_OPPORTUNITIES.md', 'seo/SEO_QA_CHECKLIST.md', 'seo/SEO_QA_COMMANDS.md',
            'seo/SEO_CHANGELOG.md',
        ],
    },
]

# Unveraenderte Originalunterlagen (nur Adminbereich, access admin)
ATTACHMENTS = [
    ('anlagen/MHAG-SE-OPS-20260907_Betrieb-und-VPS-Migration_v1.0.pdf',
     'Betrieb, Architektur und VPS-Migration (Originalfassung, MHAG-SE-OPS-20260907, v1.0)'),
    ('anlagen/MHAG_Konzept_Produkt2-sevdesk-x-Stripe_2026-09-07.pdf',
     'Konzeptpapier Produkt 2: sevdesk × Stripe auf derselben Plattform (07.09.2026)'),
    ('anlagen/MHAG_Ergaenzung_Buchhaltungssystem-Wechsel_2026-09-07_v2.pdf',
     'Ergänzung: Buchhaltungssystem je Firma, Anzeige und Wechsel mit Vier-Wochen-Sperre, Fassung 2 (07.09.2026)'),
    ('anlagen/MHAG_Kompendium_DatenbankenMariaDB_20260906.pdf',
     'Kompendium Datenbanken und MariaDB: Vom Fundament zur Expertise (Müller Holding AG, 06.09.2026)'),
]

DIAGRAM_TITLES = {
    '01-produktuebersicht': 'Produkt- und Systemübersicht',
    '02-zwei-host-architektur': 'Zwei-Host-Architektur: IONOS-Webhosting und Hostinger-VPS',
    '03-domains-dienste': 'Domain-, Dienst- und Serverzuordnung',
    '04-repository-deployment': 'Repository- und Deployment-Struktur',
    '05-datenbank-uebersicht': 'Datenbankübersicht: Tabellen nach Modul',
    '06-er-kern': 'ER-Diagramm: Kern der Fachdaten',
    '07-rechnungssynchronisation': 'Rechnungssynchronisation (Sequenz)',
    '08-lastschrift-zustaende': 'Lastschriftprozess: Zustandsübergänge eines Einzugs',
    '09-webhook-verarbeitung': 'Webhook-Verarbeitung (Stripe, Firmenkonto)',
    '10-hintergrundprozesse': 'Cronjob- und Hintergrundprozessübersicht',
    '11-email-fluss': 'E-Mail-Fluss',
    '12-github-deployment': 'GitHub-Deployment auf den VPS (Sequenz)',
    '13-backup-wiederherstellung': 'Backup und Wiederherstellung',
    '14-kundeneinrichtung': 'Kundeneinrichtung als Schrittfolge',
}

# ===================================================================================================
# Markdown
# ===================================================================================================

def _esc(s):
    return (s or '').replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def parse_markdown(text):
    """Einfacher Markdown-Parser: Ueberschriften, Absaetze, Listen, Tabellen, Codebloecke, Diagrammzeilen."""
    lines = text.split('\n')
    blocks = []
    i, n = 0, len(lines)
    while i < n:
        line = lines[i]
        stripped = line.strip()
        if stripped == '' or re.match(r'^(-{3,}|\*{3,}|_{3,})$', stripped):
            i += 1
            continue
        if stripped.startswith('```'):
            i += 1
            code_lines = []
            while i < n and not lines[i].strip().startswith('```'):
                code_lines.append(lines[i])
                i += 1
            i += 1
            blocks.append(('code', '\n'.join(code_lines)))
            continue
        m = re.match(r'^@@diagramm\s+([\w-]+)\s*$', stripped)
        if m:
            blocks.append(('diagram', m.group(1)))
            i += 1
            continue
        m = re.match(r'^(#{1,4})\s+(.*)$', stripped)
        if m:
            blocks.append((f'h{len(m.group(1))}', m.group(2).strip()))
            i += 1
            continue
        if stripped.startswith('|'):
            rows = []
            while i < n and lines[i].strip().startswith('|'):
                cells = [c.strip() for c in lines[i].strip().strip('|').split('|')]
                if not all(re.match(r'^:?-{2,}:?$', c) for c in cells):
                    rows.append(cells)
                i += 1
            if rows:
                blocks.append(('table', rows))
            continue
        if re.match(r'^(-|\*)\s+\[[ x]\]', stripped):
            items = []
            while i < n and re.match(r'^(-|\*)\s+\[[ x]\]', lines[i].strip()):
                items.append(('[x]' in lines[i], re.sub(r'^(-|\*)\s+\[[ x]\]\s*', '', lines[i].strip())))
                i += 1
            blocks.append(('checklist', items))
            continue
        if re.match(r'^(-|\*)\s+', stripped):
            items = []
            while i < n and (re.match(r'^(-|\*)\s+', lines[i].strip()) or (lines[i].startswith('  ') and lines[i].strip() and items)):
                if re.match(r'^(-|\*)\s+', lines[i].strip()):
                    items.append(re.sub(r'^(-|\*)\s+', '', lines[i].strip()))
                else:
                    items[-1] += ' ' + lines[i].strip()
                i += 1
            blocks.append(('ul', items))
            continue
        if re.match(r'^\d+[.)]\s+', stripped):
            items = []
            while i < n and (re.match(r'^\d+[.)]\s+', lines[i].strip()) or (lines[i].startswith('  ') and lines[i].strip() and items)):
                if re.match(r'^\d+[.)]\s+', lines[i].strip()):
                    items.append(re.sub(r'^\d+[.)]\s+', '', lines[i].strip()))
                else:
                    items[-1] += ' ' + lines[i].strip()
                i += 1
            blocks.append(('ol', items))
            continue
        para = [stripped]
        i += 1
        while i < n and lines[i].strip() != '' and not re.match(r'^(#{1,4}\s|```|\||-\s|\*\s|\d+[.)]\s|@@diagramm)', lines[i].strip()):
            para.append(lines[i].strip())
            i += 1
        blocks.append(('p', ' '.join(para)))
    return blocks


def _inline_html(text):
    codes = []
    hold = lambda m: (codes.append(m.group(1)) or '') + chr(0) + str(len(codes) - 1) + chr(0)
    t = re.sub(r'`([^`]+)`', hold, text)
    t = _esc(t)
    t = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', t)
    t = re.sub(r'\[([^\]]+)\]\((https?://[^)\s]+)\)', r'<a href="\2" rel="noopener">\1</a>', t)
    t = re.sub(r'\[([^\]]+)\]\((#[^)\s]+)\)', r'<a href="\2">\1</a>', t)
    t = re.sub(r'\[([^\]]+)\]\(([^)\s]+)\)', lambda m: m.group(1) + ' (<code>' + m.group(2) + '</code>)', t)
    t = re.sub(chr(0) + r'(\d+)' + chr(0), lambda m: '<code>' + _esc(codes[int(m.group(1))]) + '</code>', t)
    return t


def _plain(text):
    text = re.sub(r'\*\*(.+?)\*\*', r'\1', text)
    text = re.sub(r'`([^`]+)`', r'\1', text)
    text = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'\1', text)
    return text


def slug(text):
    s = _plain(text).lower()
    s = s.replace('ä', 'ae').replace('ö', 'oe').replace('ü', 'ue').replace('ß', 'ss')
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    return s[:70] or 'abschnitt'


def read_diagram(name, kind):
    path = os.path.join(DIAG_DIR, f'{name}.{kind}')
    if not os.path.isfile(path):
        return None
    return open(path, encoding='utf-8').read() if kind == 'svg' else path


# ===================================================================================================
# HTML
# ===================================================================================================

def blocks_to_html(blocks, chapter_no, anchors):
    out = []
    for kind, content in blocks:
        if kind in ('h1', 'h2', 'h3', 'h4'):
            aid = f'k{chapter_no}-{slug(content)}'
            base, k = aid, 2
            while aid in anchors:
                aid = f'{base}-{k}'; k += 1
            anchors.add(aid)
            out.append(f'<{kind} id="{aid}">{_inline_html(content)}</{kind}>')
        elif kind == 'p':
            out.append(f'<p>{_inline_html(content)}</p>')
        elif kind == 'ul':
            out.append('<ul>' + ''.join(f'<li>{_inline_html(i)}</li>' for i in content) + '</ul>')
        elif kind == 'ol':
            out.append('<ol>' + ''.join(f'<li>{_inline_html(i)}</li>' for i in content) + '</ol>')
        elif kind == 'checklist':
            out.append('<ul class="checklist">' + ''.join(f'<li>{"☑" if c else "☐"} {_inline_html(t)}</li>' for c, t in content) + '</ul>')
        elif kind == 'table':
            head, *body = content
            out.append('<div class="table-wrap"><table><thead><tr>' + ''.join(f'<th>{_inline_html(c)}</th>' for c in head) + '</tr></thead><tbody>')
            for r in body:
                out.append('<tr>' + ''.join(f'<td>{_inline_html(c)}</td>' for c in r) + '</tr>')
            out.append('</tbody></table></div>')
        elif kind == 'code':
            out.append(f'<pre><code>{_esc(content)}</code></pre>')
        elif kind == 'diagram':
            svg = read_diagram(content, 'svg')
            title = DIAGRAM_TITLES.get(content, content)
            if svg:
                out.append(f'<figure class="diagramm" id="diagramm-{content}"><figcaption>{_esc(title)}</figcaption>{svg}'
                           f'<p class="hint">Quelle: docs/diagramme/{content}.mmd</p></figure>')
            else:
                out.append(f'<p class="hint">[Diagramm {_esc(content)} nicht gerendert]</p>')
    return '\n'.join(out)


def build_html(doc, meta, chapters):
    """chapters: Liste aus (title, blocks, html). Erzeugt index.html mit Inhaltsverzeichnis, Kapitelnavigation, Suche."""
    toc = []
    for ci, (title, blocks, html) in enumerate(chapters):
        subs = ''.join(f'<li><a href="#k{ci}-{slug(c)}">{_inline_html(c)}</a></li>' for k, c in blocks if k == 'h2')
        toc.append(f'<li><a href="#kapitel-{ci}">{ci + 1}. {_inline_html(title)}</a>{"<ul>" + subs + "</ul>" if subs else ""}</li>')
    body = ''.join(f'<section class="kapitel" id="kapitel-{ci}" data-kapitel="{ci + 1}">{html}'
                   f'<p class="kapitel-fuss"><a href="#top">Nach oben</a> · <a href="kapitel-{ci + 1:02d}-{slug(title)}.pdf">Dieses Kapitel als PDF</a></p></section>'
                   for ci, (title, blocks, html) in enumerate(chapters))
    return f"""<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{_esc(doc['title'])} · SmartEinzug</title>
<style>
  :root {{ --gold:{GOLD}; --anthrazit:{ANTHRAZIT}; --grau:{GRAU}; --beige:{BEIGE}; --hair:{HAIR}; }}
  body {{ font-family: Carlito, Calibri, 'Segoe UI', Helvetica, Arial, sans-serif; margin:0; background:#fff; color:#1A1A1A; font-size:15px; line-height:1.5; }}
  header.kopf {{ padding:22px 32px 14px; border-bottom:1px solid var(--hair); position:relative; }}
  header.kopf:after {{ content:""; position:absolute; left:32px; bottom:-1px; width:34mm; height:2px; background:var(--gold); }}
  header.kopf h1 {{ margin:0; font-size:24px; color:var(--anthrazit); }}
  header.kopf p {{ margin:6px 0 0; color:var(--grau); font-size:13px; }}
  .klass {{ display:inline-block; color:var(--gold); letter-spacing:.3em; text-transform:uppercase; font-size:11px; font-weight:bold; margin-right:14px; }}
  .layout {{ display:grid; grid-template-columns: 300px 1fr; gap:0; }}
  nav.seite {{ position:sticky; top:0; align-self:start; max-height:100vh; overflow:auto; padding:18px 20px; border-right:1px solid var(--hair); background:var(--beige); font-size:13px; }}
  nav.seite ul {{ list-style:none; padding-left:0; margin:0; }} nav.seite ul ul {{ padding-left:14px; margin:2px 0 8px; }}
  nav.seite a {{ color:var(--anthrazit); text-decoration:none; display:block; padding:2px 0; }} nav.seite a:hover {{ color:var(--gold); }}
  nav.seite input {{ width:100%; box-sizing:border-box; padding:7px 9px; border:1px solid var(--hair); border-radius:4px; margin-bottom:10px; font-size:13px; }}
  #treffer {{ font-size:12.5px; margin-bottom:12px; }} #treffer li {{ margin:4px 0; }} #treffer mark {{ background:#F7E3B5; }}
  main {{ padding:20px 40px 80px; max-width:1000px; }}
  section.kapitel {{ padding:8px 0 24px; border-bottom:1px solid var(--hair); margin-bottom:24px; }}
  h1 {{ color:var(--anthrazit); font-size:22px; margin:26px 0 4px; }} h1:after {{ content:""; display:block; width:18mm; height:2px; background:var(--gold); margin-top:6px; }}
  h2 {{ color:var(--anthrazit); font-size:17px; margin-top:26px; }} h3 {{ font-size:14.5px; color:var(--anthrazit); }} h4 {{ font-size:13px; color:var(--grau); text-transform:uppercase; letter-spacing:.08em; }}
  code, pre {{ background:var(--beige); font-family: ui-monospace, Consolas, monospace; font-size:12.5px; }} pre {{ padding:12px; overflow-x:auto; border-radius:6px; border-left:3px solid var(--gold); }}
  .table-wrap {{ overflow-x:auto; margin:10px 0; }} table {{ border-collapse:collapse; width:100%; font-size:13px; }}
  th {{ background:var(--anthrazit); color:#fff; text-align:left; padding:6px 8px; font-size:11.5px; text-transform:uppercase; letter-spacing:.06em; border-bottom:2px solid var(--gold); }}
  td {{ padding:5px 8px; border-bottom:1px solid #E9E7E2; vertical-align:top; }} tr:nth-child(even) td {{ background:var(--beige); }}
  figure.diagramm {{ margin:18px 0; padding:12px; border:1px solid var(--hair); border-radius:6px; background:#fff; overflow-x:auto; }}
  figure.diagramm svg {{ max-width:100%; height:auto; }} figcaption {{ font-weight:bold; margin-bottom:8px; color:var(--anthrazit); }}
  .hint {{ color:var(--grau); font-size:12.5px; }} .kapitel-fuss {{ font-size:12px; color:var(--grau); }}
  ul li::marker {{ color:var(--gold); }}
  footer.fuss {{ background:var(--anthrazit); color:#8F8C87; font-size:11px; padding:10px 32px; border-top:2px solid var(--gold); }} footer.fuss strong {{ color:#fff; }} footer.fuss .web {{ color:var(--gold); font-weight:bold; }}
  @media (max-width: 900px) {{ .layout {{ grid-template-columns:1fr; }} nav.seite {{ position:static; max-height:none; }} }}
  @media print {{ nav.seite, .kapitel-fuss {{ display:none; }} .layout {{ display:block; }} }}
</style></head>
<body id="top">
<header class="kopf"><span class="klass">{_esc(doc['classification'])}</span><h1>{_esc(doc['title'])}</h1>
<p>SmartEinzug · Softwarestand {_esc(meta['version'])} (Commit {_esc(meta['commit'])}) · Dokumentrevision {_esc(meta['revision'])} vom {_esc(meta['revision_date'])} · erzeugt {_esc(meta['generated_at'])} UTC · Zielgruppe: {_esc(doc['audience'])} · <a href="../{doc['code']}.pdf">Gesamtes Dokument als PDF</a></p></header>
<div class="layout">
<nav class="seite" aria-label="Inhalt"><input type="search" id="suche" placeholder="Suchen in diesem Dokument" autocomplete="off"><ol id="treffer"></ol><ul>{''.join(toc)}</ul></nav>
<main>{body}</main>
</div>
<footer class="fuss"><strong>{FIRMA}</strong> · {_esc(REGISTER)} · <span class="web">{WEB}</span> · {MAIL}</footer>
<script>
(function () {{
  var idx = null, inp = document.getElementById('suche'), out = document.getElementById('treffer');
  function lade(cb) {{ if (idx) return cb(); fetch('suche.json', {{credentials: 'same-origin'}}).then(function (r) {{ return r.json(); }}).then(function (j) {{ idx = j; cb(); }}).catch(function () {{ out.innerHTML = '<li>Suchindex nicht verfügbar.</li>'; }}); }}
  function esc(s) {{ return s.replace(/[&<>]/g, function (c) {{ return {{'&':'&amp;','<':'&lt;','>':'&gt;'}}[c]; }}); }}
  inp.addEventListener('input', function () {{
    var q = inp.value.trim().toLowerCase(); if (q.length < 3) {{ out.innerHTML = ''; return; }}
    lade(function () {{
      var hits = []; for (var i = 0; i < idx.length && hits.length < 40; i++) {{ var t = idx[i]; var p = t.text.toLowerCase().indexOf(q); if (p >= 0) {{ var s = Math.max(0, p - 60); hits.push({{a: t.anchor, k: t.kapitel, h: t.heading, snip: t.text.substr(s, 160)}}); }} }}
      out.innerHTML = hits.length ? hits.map(function (h) {{ return '<li><a href="#' + h.a + '">' + esc(h.k + ' › ' + h.h) + '</a><br>' + esc(h.snip).replace(new RegExp(q.replace(/[.*+?^${{}}()|[\\]\\\\]/g, '\\\\$&'), 'ig'), function (m) {{ return '<mark>' + m + '</mark>'; }}) + '</li>'; }}).join('') : '<li>Keine Treffer.</li>';
    }});
  }});
}})();
</script>
</body></html>
"""


def search_index(chapters):
    idx = []
    for ci, (title, blocks, html) in enumerate(chapters):
        heading, anchor, seen = title, f'kapitel-{ci}', set()
        for kind, content in blocks:
            if kind in ('h1', 'h2', 'h3', 'h4'):
                heading = _plain(content)
                aid = f'k{ci}-{slug(content)}'
                base, k = aid, 2
                while aid in seen:
                    aid = f'{base}-{k}'; k += 1
                seen.add(aid); anchor = aid
                idx.append({'kapitel': f'{ci + 1}. {_plain(title)}', 'heading': heading, 'anchor': anchor, 'text': heading})
            elif kind in ('p',):
                idx.append({'kapitel': f'{ci + 1}. {_plain(title)}', 'heading': heading, 'anchor': anchor, 'text': _plain(content)[:400]})
            elif kind in ('ul', 'ol'):
                idx.append({'kapitel': f'{ci + 1}. {_plain(title)}', 'heading': heading, 'anchor': anchor, 'text': _plain(' '.join(content))[:400]})
            elif kind == 'table':
                idx.append({'kapitel': f'{ci + 1}. {_plain(title)}', 'heading': heading, 'anchor': anchor, 'text': _plain(' '.join(' '.join(r) for r in content))[:600]})
    return idx


# ===================================================================================================
# PDF (reportlab, CI der Mueller Holding AG)
# ===================================================================================================

def _fonts():
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.pdfmetrics import registerFontFamily
    from reportlab.pdfbase.ttfonts import TTFont
    fd = '/usr/share/fonts/truetype/crosextra'
    if os.path.isfile(os.path.join(fd, 'Carlito-Regular.ttf')):
        try:
            pdfmetrics.registerFont(TTFont('Carlito', f'{fd}/Carlito-Regular.ttf'))
            pdfmetrics.registerFont(TTFont('Carlito-Bold', f'{fd}/Carlito-Bold.ttf'))
            pdfmetrics.registerFont(TTFont('Carlito-It', f'{fd}/Carlito-Italic.ttf'))
            pdfmetrics.registerFont(TTFont('Carlito-BoldIt', f'{fd}/Carlito-BoldItalic.ttf'))
            registerFontFamily('Carlito', normal='Carlito', bold='Carlito-Bold', italic='Carlito-It', boldItalic='Carlito-BoldIt')
            return 'Carlito', 'Carlito-Bold', 'Carlito'
        except Exception:
            pass
    dj = '/usr/share/fonts/truetype/dejavu'
    if os.path.isfile(os.path.join(dj, 'DejaVuSans.ttf')):
        pdfmetrics.registerFont(TTFont('DejaVu', f'{dj}/DejaVuSans.ttf'))
        pdfmetrics.registerFont(TTFont('DejaVu-Bold', f'{dj}/DejaVuSans-Bold.ttf'))
        registerFontFamily('DejaVu', normal='DejaVu', bold='DejaVu-Bold', italic='DejaVu', boldItalic='DejaVu-Bold')
        return 'DejaVu', 'DejaVu-Bold', 'DejaVu (Carlito nicht installiert)'
    return 'Helvetica', 'Helvetica-Bold', 'Helvetica (Carlito nicht installiert)'


def build_pdf(path, doc, meta, chapters, single_chapter=None):
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib.units import mm
    from reportlab.lib.colors import HexColor, white
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.utils import ImageReader
    from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
                                    Preformatted, PageBreak, ListFlowable, ListItem, NextPageTemplate, Image, KeepTogether)
    from reportlab.platypus.tableofcontents import TableOfContents

    F, FB, font_note = _fonts()
    PAGE_W, PAGE_H = A4
    M_L, M_R, M_T, BAND = 22 * mm, 20 * mm, 22 * mm, 15.5 * mm
    CW = PAGE_W - M_L - M_R
    C = lambda h: HexColor(h)

    st = {
        'body': ParagraphStyle('body', fontName=F, fontSize=9.8, leading=13.4, textColor=C('#1A1A1A'), spaceAfter=5),
        'small': ParagraphStyle('small', fontName=F, fontSize=8.3, leading=11, textColor=C(GRAU)),
        'h1': ParagraphStyle('h1', fontName=FB, fontSize=18, leading=22, textColor=C(ANTHRAZIT), spaceBefore=6, spaceAfter=10),
        'h2': ParagraphStyle('h2', fontName=FB, fontSize=13, leading=16, textColor=C(ANTHRAZIT), spaceBefore=12, spaceAfter=5),
        'h3': ParagraphStyle('h3', fontName=FB, fontSize=11, leading=14, textColor=C(ANTHRAZIT), spaceBefore=9, spaceAfter=4),
        'h4': ParagraphStyle('h4', fontName=FB, fontSize=8, leading=11, textColor=C(GRAU), spaceBefore=8, spaceAfter=3),
        'code': ParagraphStyle('code', fontName='Courier', fontSize=7.3, leading=9.2, backColor=C(BEIGE), leftIndent=6, borderPadding=4, spaceAfter=6),
        'cell': ParagraphStyle('cell', fontName=F, fontSize=8, leading=10.2, textColor=C('#1A1A1A')),
        'cellh': ParagraphStyle('cellh', fontName=FB, fontSize=7.4, leading=9.5, textColor=white),
        'title': ParagraphStyle('title', fontName=FB, fontSize=26, leading=31, textColor=C(ANTHRAZIT)),
        'sub': ParagraphStyle('sub', fontName=F, fontSize=12, leading=16, textColor=C(ANTHRAZIT)),
        'klass': ParagraphStyle('klass', fontName=FB, fontSize=9, leading=12, textColor=C(GOLD)),
        'toc0': ParagraphStyle('toc0', fontName=FB, fontSize=10.5, leading=15, textColor=C(ANTHRAZIT)),
        'toc1': ParagraphStyle('toc1', fontName=F, fontSize=9.5, leading=13, leftIndent=14, textColor=C('#1A1A1A')),
    }

    def inline(text):
        t = _inline_html(text).replace('<code>', f'<font face="Courier" size="8">').replace('</code>', '</font>')
        t = re.sub(r'<a href="[^"]*"( rel="noopener")?>', '<u>', t).replace('</a>', '</u>')
        return t

    def para(text, style='body'):
        return Paragraph(inline(text), st[style])

    def klass_text(k):
        return '&#8202;'.join(k.upper())

    story = []
    # ---- Deckblatt
    story.append(NextPageTemplate('inhalt'))
    story.append(Spacer(1, 30 * mm))
    story.append(Paragraph(klass_text(doc['classification']), st['klass']))
    story.append(Spacer(1, 4))
    story.append(Paragraph('SmartEinzug', st['sub']))
    story.append(Paragraph(_esc(doc['title']) if single_chapter is None else _esc(doc['title']) + ': Kapitel ' + _esc(single_chapter), st['title']))
    story.append(_Goldbalken())
    story.append(Spacer(1, 10 * mm))
    meta_rows = [['Zielgruppe', doc['audience']], ['Vertraulichkeit', doc['classification']],
                 ['Softwarestand', f"Version {meta['version']}, Commit {meta['commit']}"],
                 ['Dokumentrevision', f"{meta['revision']} vom {meta['revision_date']}"],
                 ['Erzeugt', f"{meta['generated_at']} UTC durch tools/build-docs.py"],
                 ['Herausgeber', f'{FIRMA}, Rheinpromenade 13, 40789 Monheim am Rhein']]
    t = Table([[Paragraph(f'<font color="{GRAU}">{_esc(a).upper()}</font>', st['h4']), Paragraph(_esc(b), st['body'])] for a, b in meta_rows], colWidths=[38 * mm, CW - 38 * mm])
    t.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('BOTTOMPADDING', (0, 0), (-1, -1), 3), ('TOPPADDING', (0, 0), (-1, -1), 3)]))
    story.append(t)
    if 'vertraulich' in doc['classification'] or doc['classification'] == 'intern':
        story.append(Spacer(1, 8 * mm))
        story.append(para('Dieses Dokument enthält interne Betriebsinformationen der Müller Holding AG. Weitergabe nur an berechtigte Personen. Zugangsdaten, private Schlüssel und Geheimnisse sind absichtlich nicht enthalten.', 'small'))
    story.append(PageBreak())

    # ---- Inhaltsverzeichnis
    toc = TableOfContents()
    toc.levelStyles = [st['toc0'], st['toc1']]
    story.append(Paragraph('Inhaltsverzeichnis', st['h1']))
    story.append(toc)
    story.append(PageBreak())

    # ---- Kapitel
    for ci, (title, blocks, html) in enumerate(chapters):
        first_h1 = True
        for kind, content in blocks:
            if kind == 'h1':
                text = f'{ci + 1}. {_plain(content)}' if single_chapter is None else _plain(content)
                p = Paragraph(inline(text), st['h1']); p._toc_level = 0
                story.append(p); story.append(_Goldbalken()); first_h1 = False
            elif kind == 'h2':
                p = Paragraph(inline(content), st['h2']); p._toc_level = 1
                story.append(p)
            elif kind == 'h3':
                story.append(Paragraph(inline(content), st['h3']))
            elif kind == 'h4':
                story.append(Paragraph(inline(_plain(content).upper()), st['h4']))
            elif kind == 'p':
                story.append(para(content))
            elif kind in ('ul', 'ol'):
                story.append(ListFlowable([ListItem(para(i), leftIndent=8) for i in content],
                                          bulletType='bullet' if kind == 'ul' else '1', start='•' if kind == 'ul' else None,
                                          leftIndent=14, bulletColor=C(GOLD) if kind == 'ul' else C(ANTHRAZIT), bulletFontName=F, bulletFontSize=9))
                story.append(Spacer(1, 4))
            elif kind == 'checklist':
                story.append(ListFlowable([ListItem(para(tx), leftIndent=8, value=('☑' if c else '☐')) for c, tx in content], bulletType='bullet', leftIndent=14))
            elif kind == 'table':
                head, *body = content
                ncol = max(len(r) for r in content)
                rows = [r + [''] * (ncol - len(r)) for r in content]
                wide = ncol >= 6 or max(len(' '.join(r)) for r in rows) > 420
                avail = (landscape(A4)[0] - M_L - M_R) if wide else CW
                lens = [max(8, max(len(_plain(r[j])) for r in rows)) for j in range(ncol)]
                lens = [min(l, 60) for l in lens]
                tot = sum(lens)
                widths = [max(16 * mm, avail * l / tot) for l in lens]
                scale = avail / sum(widths); widths = [w * scale for w in widths]
                data = [[Paragraph(inline(c), st['cellh']) for c in rows[0]]] + [[Paragraph(inline(c), st['cell']) for c in r] for r in rows[1:]]
                tb = Table(data, colWidths=widths, repeatRows=1)
                sty = [('BACKGROUND', (0, 0), (-1, 0), C(ANTHRAZIT)), ('LINEBELOW', (0, 0), (-1, 0), 1.2, C(GOLD)),
                       ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('TOPPADDING', (0, 0), (-1, -1), 3), ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
                       ('LEFTPADDING', (0, 0), (-1, -1), 4), ('RIGHTPADDING', (0, 0), (-1, -1), 4)]
                for i in range(1, len(data)):
                    sty.append(('LINEBELOW', (0, i), (-1, i), 0.4, C('#E9E7E2')))
                    if i % 2 == 0:
                        sty.append(('BACKGROUND', (0, i), (-1, i), C(BEIGE)))
                tb.setStyle(TableStyle(sty))
                if wide:
                    story += [NextPageTemplate('quer'), PageBreak(), tb, NextPageTemplate('inhalt'), PageBreak()]
                else:
                    story += [tb, Spacer(1, 6)]
            elif kind == 'code':
                story.append(Preformatted(content, st['code'], maxLineLength=118))
            elif kind == 'diagram':
                png = read_diagram(content, 'png')
                title_d = DIAGRAM_TITLES.get(content, content)
                if png:
                    iw, ih = ImageReader(png).getSize()
                    wide = iw / ih > 1.45 and iw > 1600
                    avail_w = (landscape(A4)[0] - M_L - M_R) if wide else CW
                    avail_h = (landscape(A4)[1] - 62 * mm) if wide else (PAGE_H - M_T - BAND - 62 * mm)
                    sc = min(avail_w / iw, avail_h / ih)
                    img = Image(png, width=iw * sc, height=ih * sc)
                    block = KeepTogether([Paragraph(inline(f'Schaubild: {title_d}'), st['h3']), img,
                                          para(f'Quelle: docs/diagramme/{content}.mmd (Mermaid), gerendert durch tools/render-mermaid.py', 'small')])
                    if wide:
                        story += [NextPageTemplate('quer'), PageBreak(), block, NextPageTemplate('inhalt'), PageBreak()]
                    else:
                        story.append(block)
                else:
                    story.append(para(f'[Diagramm {content} nicht gerendert]', 'small'))
        story.append(PageBreak())

    # ---- Abschlussblatt
    story.append(NextPageTemplate('schluss'))
    story.append(Paragraph('Ende des Dokuments', ParagraphStyle('ende', fontName=FB, fontSize=14, leading=18, textColor=C(ANTHRAZIT), alignment=1)))
    story.append(_Goldbalken())
    story.append(Spacer(1, 6 * mm))
    schluss_rows = [['Dokument', f"{doc['title']}" + (f', Kapitel {single_chapter}' if single_chapter else '')],
                    ['Revision', f"{meta['revision']} vom {meta['revision_date']}"], ['Softwarestand', f"Version {meta['version']}, Commit {meta['commit']}"],
                    ['Vertraulichkeit', doc['classification']], ['Herausgeber', f'{FIRMA}, Rheinpromenade 13, 40789 Monheim am Rhein'],
                    ['Kontakt', f'{MAIL} · {WEB}']]
    ts = Table([[Paragraph(f'<font color="{GRAU}">{_esc(a).upper()}</font>', st['h4']), Paragraph(_esc(b), st['body'])] for a, b in schluss_rows], colWidths=[38 * mm, CW - 38 * mm])
    ts.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('TOPPADDING', (0, 0), (-1, -1), 2), ('BOTTOMPADDING', (0, 0), (-1, -1), 2)]))
    story.append(ts)
    story.append(Spacer(1, 6 * mm))
    story.append(para('Dieses Dokument wurde automatisch aus den versionierten Quellen des Repositorys erzeugt (tools/build-docs.py). Änderungen erfolgen ausschließlich an den Quellen; Korrekturen erhalten eine neue Dokumentrevision.', 'small'))

    # ---- Seitendekor
    logo = os.path.join(CI_DIR, 'Logo_MHAG_transparent.png')
    wz = os.path.join(CI_DIR, 'Wasserzeichen_Marke.png')
    head_text = f"{FIRMA} · SmartEinzug · {doc['title']} · Revision {meta['revision']} · {doc['classification']}"

    def fussband(c, w):
        c.setFillColor(C(ANTHRAZIT)); c.rect(0, 0, w, BAND, stroke=0, fill=1)
        c.setStrokeColor(C(GOLD)); c.setLineWidth(1); c.line(0, BAND, w, BAND)
        c.setFont(FB, 8); c.setFillColor(white); c.drawString(M_L, 9.8 * mm, FIRMA)
        c.setFont(F, 6.6); c.setFillColor(C('#8F8C87')); c.drawString(M_L, 4.6 * mm, REGISTER)
        seite = f'Seite {c.getPageNumber()}'
        c.setFont(F, 7); c.setFillColor(C('#C9C6C0'))
        w_s = c.stringWidth(seite, F, 7); c.drawString(w - M_R - w_s, 9.8 * mm, seite)
        w_m = c.stringWidth(MAIL, F, 7); c.drawString(w - M_R - w_s - 3.6 * mm - w_m, 9.8 * mm, MAIL)
        c.setFont(FB, 8); c.setFillColor(C(GOLD)); w_w = c.stringWidth(WEB, FB, 8)
        x_web = w - M_R - w_s - 3.6 * mm - w_m - 3.6 * mm - w_w
        c.drawString(x_web, 9.8 * mm, WEB); c.circle(x_web - 4 * mm, 9.8 * mm + 1 * mm, 1.0 * mm, stroke=0, fill=1)

    def wasserzeichen(c, w):
        if os.path.isfile(wz):
            img = ImageReader(wz); iw, ih = img.getSize(); ww = 160 * mm; hh = ww * ih / iw
            c.drawImage(wz, w - ww + 12 * mm, -hh + 92 * mm, width=ww, height=hh, mask='auto')

    def logo_klein(c, w, h):
        # CI-Regel: Logo der Mueller Holding AG mindestens klein auf jeder Seite (oben rechts, ueber der Kopfnaht).
        if os.path.isfile(logo):
            img = ImageReader(logo); iw, ih = img.getSize(); lw = 16 * mm
            c.drawImage(logo, w - M_R - lw, h - 6 * mm - lw * ih / iw, width=lw, height=lw * ih / iw, mask='auto')

    def deko_titel(c, d):
        # Deckblatt: Logo mittelgross und mittig, darunter der Titelblock aus der Story.
        c.saveState(); wasserzeichen(c, PAGE_W)
        if os.path.isfile(logo):
            img = ImageReader(logo); iw, ih = img.getSize(); lw = 62 * mm
            c.drawImage(logo, (PAGE_W - lw) / 2, PAGE_H - M_T - lw * ih / iw, width=lw, height=lw * ih / iw, mask='auto')
        y = PAGE_H - M_T - 62 * mm * ih / iw - 10 * mm if os.path.isfile(logo) else PAGE_H - M_T - 26 * mm
        c.setStrokeColor(C(HAIR)); c.setLineWidth(0.5); c.line(M_L, y, PAGE_W - M_R, y)
        c.setStrokeColor(C(GOLD)); c.setLineWidth(1.8); c.line(M_L, y, M_L + 34 * mm, y)
        fussband(c, PAGE_W); c.restoreState()

    def deko(c, d, w, h):
        c.saveState(); wasserzeichen(c, w)
        c.setFont(F, 7.5); c.setFillColor(C(GRAU)); c.drawString(M_L, h - 12 * mm, head_text[:140])
        logo_klein(c, w, h)
        y = h - 14 * mm
        c.setStrokeColor(C(HAIR)); c.setLineWidth(0.5); c.line(M_L, y, w - M_R - 20 * mm, y)
        c.setStrokeColor(C(GOLD)); c.setLineWidth(1.8); c.line(M_L, y, M_L + 34 * mm, y)
        fussband(c, w); c.restoreState()

    def deko_schluss(c, d):
        # Abschlussblatt: Logo mittig, Kontakt, Pflichtangaben im Fussband.
        c.saveState(); wasserzeichen(c, PAGE_W)
        if os.path.isfile(logo):
            img = ImageReader(logo); iw, ih = img.getSize(); lw = 48 * mm
            c.drawImage(logo, (PAGE_W - lw) / 2, PAGE_H / 2 + 20 * mm, width=lw, height=lw * ih / iw, mask='auto')
        fussband(c, PAGE_W); c.restoreState()

    class DocT(BaseDocTemplate):
        def afterFlowable(self, fl):
            lvl = getattr(fl, '_toc_level', None)
            if lvl is not None:
                text = fl.getPlainText()
                key = f'toc-{self.page}-{abs(hash(text)) % 100000}'
                self.canv.bookmarkPage(key)
                self.canv.addOutlineEntry(text, key, level=lvl, closed=(lvl > 0))
                self.notify('TOCEntry', (lvl, text, self.page, key))

    f_titel = Frame(M_L, BAND + 8 * mm, CW, PAGE_H - M_T - 58 * mm - BAND - 8 * mm, id='t', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    f_schluss = Frame(M_L, BAND + 8 * mm, CW, PAGE_H / 2 - BAND, id='s', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    f_inhalt = Frame(M_L, BAND + 8 * mm, CW, PAGE_H - 20 * mm - BAND - 8 * mm, id='i', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    LW, LH = landscape(A4)
    f_quer = Frame(M_L, BAND + 8 * mm, LW - M_L - M_R, LH - 20 * mm - BAND - 8 * mm, id='q', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    d = DocT(path, pagesize=A4, title=f"{doc['title']} · SmartEinzug", author=FIRMA, subject=f"Revision {meta['revision']}, Version {meta['version']}")
    d.addPageTemplates([PageTemplate(id='titel', frames=[f_titel], onPage=deko_titel),
                        PageTemplate(id='inhalt', frames=[f_inhalt], onPage=lambda c, dd: deko(c, dd, PAGE_W, PAGE_H)),
                        PageTemplate(id='quer', frames=[f_quer], pagesize=landscape(A4), onPage=lambda c, dd: deko(c, dd, LW, LH)),
                        PageTemplate(id='schluss', frames=[f_schluss], onPage=deko_schluss)])
    d.multiBuild(story)
    return font_note


def _Goldbalken():
    from reportlab.platypus import Flowable
    class G(Flowable):
        def wrap(self, w, h): return (w, 6)
        def draw(self):
            from reportlab.lib.colors import HexColor
            self.canv.setStrokeColor(HexColor(GOLD)); self.canv.setLineWidth(1.8); self.canv.line(0, 3, 51, 3)
    return G()


# ===================================================================================================
# Hilfen und Hauptprogramm
# ===================================================================================================

def read_version():
    try:
        m = re.search(r"const APP_VERSION = '([^']+)'", open(VERSION_FILE, encoding='utf-8').read())
        return m.group(1) if m else 'unbekannt'
    except OSError:
        return 'unbekannt'


def read_commit():
    try:
        out = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT, capture_output=True, text=True, timeout=10, check=False)
        return out.stdout.strip() or 'unbekannt'
    except (OSError, subprocess.SubprocessError):
        return 'unbekannt'


def read_revisions():
    try:
        return json.load(open(REVISIONS_FILE, encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def chapter_title(blocks, fallback):
    for kind, content in blocks:
        if kind == 'h1':
            return content
    return fallback


def sha256(path):
    return hashlib.sha256(open(path, 'rb').read()).hexdigest()


def main():
    if os.path.isdir(OUT_DIR):
        for name in os.listdir(OUT_DIR):
            p = os.path.join(OUT_DIR, name)
            shutil.rmtree(p) if os.path.isdir(p) else os.remove(p)
    os.makedirs(OUT_DIR, exist_ok=True)
    version, commit = read_version(), read_commit()
    generated_at = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    revisions = read_revisions()
    files, documents, missing, warnings = [], [], [], []

    # Diagramme kopieren
    diag_out = os.path.join(OUT_DIR, 'diagramme')
    os.makedirs(diag_out, exist_ok=True)
    if os.path.isdir(DIAG_DIR):
        for f in sorted(os.listdir(DIAG_DIR)):
            if f.endswith(('.svg', '.png')):
                shutil.copyfile(os.path.join(DIAG_DIR, f), os.path.join(diag_out, f))
                files.append({'name': f'diagramme/{f}', 'kind': f.rsplit('.', 1)[1], 'doc': None, 'access': 'admin', 'title': DIAGRAM_TITLES.get(f.rsplit('.', 1)[0], '')})

    font_note = None
    for doc in DOCUMENTS:
        code = doc['code']
        rev = revisions.get(code, {})
        meta = {'version': version, 'commit': commit, 'generated_at': generated_at,
                'revision': str(rev.get('revision', 'r0')), 'revision_date': str(rev.get('date', 'unbekannt'))}
        chapters = []
        anchors = set()
        for ci, rel in enumerate(doc['chapters']):
            full = os.path.join(DOCS_DIR, rel)
            if not os.path.isfile(full):
                missing.append(f'{code}: {rel}')
                continue
            blocks = parse_markdown(open(full, encoding='utf-8').read())
            title = chapter_title(blocks, os.path.basename(rel))
            chapters.append((title, blocks, blocks_to_html(blocks, len(chapters), anchors)))
        if not chapters:
            warnings.append(f'{code}: keine Kapitel gefunden')
            continue
        ddir = os.path.join(OUT_DIR, code)
        os.makedirs(ddir, exist_ok=True)
        # HTML und Suchindex
        with open(os.path.join(ddir, 'index.html'), 'w', encoding='utf-8') as f:
            f.write(build_html(doc, meta, chapters))
        with open(os.path.join(ddir, 'suche.json'), 'w', encoding='utf-8') as f:
            json.dump(search_index(chapters), f, ensure_ascii=False)
        files.append({'name': f'{code}/index.html', 'kind': 'html', 'doc': code, 'access': doc['access'], 'title': doc['title']})
        files.append({'name': f'{code}/suche.json', 'kind': 'json', 'doc': code, 'access': doc['access'], 'title': 'Suchindex'})
        # Gesamt-PDF
        pdf_path = os.path.join(OUT_DIR, f'{code}.pdf')
        font_note = build_pdf(pdf_path, doc, meta, chapters)
        files.append({'name': f'{code}.pdf', 'kind': 'pdf', 'doc': code, 'access': doc['access'], 'title': doc['title'] + ' (PDF)'})
        # Kapitel-PDFs
        chap_entries = []
        for ci, ch in enumerate(chapters):
            name = f'{code}/kapitel-{ci + 1:02d}-{slug(ch[0])}.pdf'
            build_pdf(os.path.join(OUT_DIR, name), doc, meta, [ch], single_chapter=f'{ci + 1}')
            files.append({'name': name, 'kind': 'pdf', 'doc': code, 'access': doc['access'], 'title': f'{doc["title"]}, Kapitel {ci + 1}: {_plain(ch[0])}'})
            chap_entries.append({'n': ci + 1, 'title': _plain(ch[0]), 'pdf': name, 'source': doc['chapters'][ci] if ci < len(doc['chapters']) else ''})
        documents.append({
            'code': code, 'title': doc['title'], 'audience': doc['audience'], 'classification': doc['classification'],
            'access': doc['access'], 'revision': meta['revision'], 'revision_date': meta['revision_date'],
            'revision_summary': rev.get('summary', ''), 'html': f'{code}/index.html', 'pdf': f'{code}.pdf', 'search': f'{code}/suche.json',
            'chapters': chap_entries, 'pdf_sha256': sha256(pdf_path), 'pdf_bytes': os.path.getsize(pdf_path),
        })
        print(f'  {code}: {len(chapters)} Kapitel, PDF {os.path.getsize(pdf_path)} Byte')

    # Anlagen
    for rel, title in ATTACHMENTS:
        src = os.path.join(DOCS_DIR, rel)
        if not os.path.isfile(src):
            warnings.append(f'Anlage fehlt: docs/{rel}')
            continue
        shutil.copyfile(src, os.path.join(OUT_DIR, os.path.basename(rel)))
        files.append({'name': os.path.basename(rel), 'kind': 'pdf', 'doc': None, 'access': 'admin', 'title': title})

    for e in files:
        e['bytes'] = os.path.getsize(os.path.join(OUT_DIR, e['name']))
    manifest = {'schema': 2, 'version': version, 'commit': commit, 'generated_at': generated_at, 'font': font_note,
                'status': 'complete' if not missing else 'incomplete', 'missing': missing, 'warnings': warnings,
                'documents': documents, 'files': files}
    with open(os.path.join(OUT_DIR, 'manifest.json'), 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2); f.write('\n')
    print(f'Dokumentation erzeugt: {OUT_DIR} (Version {version}, Commit {commit}, Schrift {font_note})')
    if missing:
        print('Fehlende Kapitelquellen: ' + ', '.join(missing), file=sys.stderr)
    if warnings:
        print('Hinweise: ' + '; '.join(warnings), file=sys.stderr)
    return 1 if missing else 0


if __name__ == '__main__':
    sys.exit(main())
