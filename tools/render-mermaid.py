#!/usr/bin/env python3
"""
Mermaid-Diagramme rendern: docs/diagramme/*.mmd -> docs/diagramme/build/<name>.svg und .png (2x Aufloesung).

Quelle jeder Darstellung ist die versionierte .mmd-Datei; die erzeugten SVG/PNG werden mit eingecheckt, damit
tools/build-docs.py (auch im GitHub-Workflow) ohne Browser auskommt. tools/docs-build-check.py prueft ueber
build/hashes.json, dass jede .mmd-Datei mit dem eingecheckten Stand gerendert wurde.

Rendering lokal und ohne Netzabruf beim Rendern: Playwright-Chromium plus mermaid.min.js aus einem npm-Paket.
Das Paket wird bei Bedarf einmalig nach ~/.cache/smarteinzug-mermaid installiert (npm install mermaid@11),
oder ueber SMARTEINZUG_MERMAID_JS auf eine vorhandene mermaid.min.js gezeigt.

Aufruf:  python3 tools/render-mermaid.py [--check] [name ...]
  --check  nur pruefen, ob build/hashes.json zu den .mmd-Dateien passt (Exit 1 bei Abweichung), kein Browser
"""
import asyncio, hashlib, json, os, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC_DIR = os.path.join(ROOT, 'docs', 'diagramme')
OUT_DIR = os.path.join(SRC_DIR, 'build')
HASHES = os.path.join(OUT_DIR, 'hashes.json')
MERMAID_INIT = {
    'startOnLoad': False, 'theme': 'base', 'securityLevel': 'strict',
    'fontFamily': 'Carlito, Calibri, Helvetica, Arial, sans-serif',
    'themeVariables': {
        'primaryColor': '#FBF6EC', 'primaryBorderColor': '#2E2D2E', 'primaryTextColor': '#2E2D2E', 'lineColor': '#2E2D2E',
        'secondaryColor': '#FFFFFF', 'tertiaryColor': '#F3EFE6', 'fontSize': '14px', 'clusterBkg': '#FFFFFF',
        'clusterBorder': '#9F9F9F', 'edgeLabelBackground': '#FFFFFF', 'noteBkgColor': '#FBF6EC', 'noteBorderColor': '#E3AC48',
        'actorBkg': '#FBF6EC', 'actorBorder': '#2E2D2E', 'signalColor': '#2E2D2E', 'labelBoxBkgColor': '#FBF6EC',
        'labelBoxBorderColor': '#E3AC48', 'attributeBackgroundColorOdd': '#FFFFFF', 'attributeBackgroundColorEven': '#FBF6EC',
    },
    'flowchart': {'htmlLabels': False, 'curve': 'basis', 'useMaxWidth': False},
    'sequence': {'useMaxWidth': False}, 'er': {'useMaxWidth': False}, 'state': {'useMaxWidth': False},
}


def sha(path):
    return hashlib.sha256(open(path, 'rb').read()).hexdigest()


def sources():
    return sorted(f[:-4] for f in os.listdir(SRC_DIR) if f.endswith('.mmd'))


def load_hashes():
    try:
        return json.load(open(HASHES, encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def check():
    hashes = load_hashes()
    bad = []
    for name in sources():
        h = sha(os.path.join(SRC_DIR, name + '.mmd'))
        if hashes.get(name) != h or not os.path.isfile(os.path.join(OUT_DIR, name + '.svg')) \
                or not os.path.isfile(os.path.join(OUT_DIR, name + '.png')):
            bad.append(name)
    for name in hashes:
        if not os.path.isfile(os.path.join(SRC_DIR, name + '.mmd')):
            bad.append(name + ' (Quelle fehlt)')
    return bad


def mermaid_js():
    env = os.environ.get('SMARTEINZUG_MERMAID_JS')
    if env and os.path.isfile(env):
        return env
    cache = os.path.join(os.path.expanduser('~'), '.cache', 'smarteinzug-mermaid')
    js = os.path.join(cache, 'node_modules', 'mermaid', 'dist', 'mermaid.min.js')
    if not os.path.isfile(js):
        os.makedirs(cache, exist_ok=True)
        if not os.path.isfile(os.path.join(cache, 'package.json')):
            subprocess.run(['npm', 'init', '-y'], cwd=cache, check=True, capture_output=True)
        subprocess.run(['npm', 'install', '--no-audit', '--no-fund', 'mermaid@11'], cwd=cache, check=True)
    return js


def chromium_path():
    for base in ('/opt/pw-browsers',):
        if os.path.isdir(base):
            for d in sorted(os.listdir(base)):
                cand = os.path.join(base, d, 'chrome-linux', 'chrome')
                if d.startswith('chromium-') and os.path.isfile(cand):
                    return cand
    return None


async def render(names):
    from playwright.async_api import async_playwright
    js = mermaid_js()
    os.makedirs(OUT_DIR, exist_ok=True)
    hashes = load_hashes()
    async with async_playwright() as p:
        exe = chromium_path()
        browser = await (p.chromium.launch(executable_path=exe) if exe else p.chromium.launch())
        page = await browser.new_page(viewport={'width': 1600, 'height': 1000}, device_scale_factor=2)
        await page.set_content("<html><body style='margin:0;background:#fff'><div id='d'></div></body></html>")
        await page.add_script_tag(path=js)
        for name in names:
            src_path = os.path.join(SRC_DIR, name + '.mmd')
            code = open(src_path, encoding='utf-8').read()
            svg = await page.evaluate(
                "async ([code, init]) => { mermaid.initialize(init); const r = await mermaid.render('m_' + Math.random().toString(36).slice(2), code);"
                " document.getElementById('d').innerHTML = r.svg; return r.svg; }", [code, MERMAID_INIT])
            open(os.path.join(OUT_DIR, name + '.svg'), 'w', encoding='utf-8').write(svg)
            el = await page.query_selector('#d svg')
            await el.screenshot(path=os.path.join(OUT_DIR, name + '.png'), omit_background=False)
            hashes[name] = sha(src_path)
            print(f'  gerendert: {name}')
        await browser.close()
    hashes = {k: v for k, v in hashes.items() if os.path.isfile(os.path.join(SRC_DIR, k + '.mmd'))}
    json.dump(hashes, open(HASHES, 'w', encoding='utf-8'), indent=2, sort_keys=True)


def main(argv):
    if '--check' in argv:
        bad = check()
        if bad:
            print('Diagramme nicht aktuell gerendert: ' + ', '.join(bad))
            print('Abhilfe: python3 tools/render-mermaid.py')
            return 1
        print(f'Diagramme aktuell: {len(sources())} Quellen.')
        return 0
    names = [a for a in argv if not a.startswith('--')] or sources()
    asyncio.run(render(names))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
