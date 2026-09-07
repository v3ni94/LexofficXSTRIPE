#!/usr/bin/env python3
"""
Erzeugt die Bildassets der Leadseiten lexoffice-einzug.de und lexware-einzug.de ohne SmartEinzug-Logo
(Entscheidung des Betreibers vom 07.09.2026: Leadseiten der DETM Management Consulting FZCO, Farben und
Gestaltung bleiben, das SmartEinzug-Logo entfällt).

Erzeugt je Domain: og-image.png (1200x630), icon-512.png, apple-touch-icon.png (180x180), favicon-32.png,
favicon.ico. Motiv: Euro-Zeichen in Gold auf Anthrazit (entspricht der CSS-Klasse .mark der Seiten),
Textwortmarke der Domain, Unterzeile. Schrift: Liberation Sans (metrisch kompatibel zu Arial, dem
Rückfall des CI-Schriftstapels).

Aufruf:  python3 tools/lead-assets.py
"""
import os

from PIL import Image, ImageDraw, ImageFont

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..', 'websites'))
GOLD, ANTHRAZIT, BEIGE, GRAU = (227, 172, 72), (46, 45, 46), (251, 246, 236), (159, 159, 159)
FONT_BOLD = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'
FONT_REG = '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'

DOMAINS = {
    'lexware-einzug.de': ('SEPA-Lastschrift für Lexware Office', 'Offene Rechnungen aus Lexware Office per Lastschrift einziehen'),
    'lexoffice-einzug.de': ('Für lexoffice-Nutzer, heute Lexware Office', 'Offene Rechnungen per SEPA-Lastschrift einziehen'),
    'sevdesk-einzug.de': ('SEPA-Lastschrift für sevdesk, in Vorbereitung', 'Vormerken für den geplanten Einzug aus sevdesk'),
    'sevdesk-sepa.de': ('SEPA-Lastschrift verstehen, für sevdesk-Nutzer', 'Mandat, Gläubiger-ID, Vorabankündigung, Rücklastschrift'),
}


def font(path, size):
    return ImageFont.truetype(path, size)


def euro_mark(size, radius_ratio=0.22):
    """Anthrazit-Kachel mit goldenem Euro-Zeichen, transparenter Hintergrund."""
    im = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle((0, 0, size - 1, size - 1), radius=int(size * radius_ratio), fill=ANTHRAZIT)
    f = font(FONT_BOLD, int(size * 0.68))
    text = '€'
    bbox = d.textbbox((0, 0), text, font=f)
    w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
    d.text(((size - w) / 2 - bbox[0], (size - h) / 2 - bbox[1] - size * 0.02), text, font=f, fill=GOLD)
    return im


def og_image(domain, sub, claim):
    W, H = 1200, 630
    im = Image.new('RGB', (W, H), BEIGE)
    d = ImageDraw.Draw(im)
    # Kopfnaht: Goldsegment links, graue Linie
    d.rectangle((0, 0, W, 8), fill=(214, 213, 211))
    d.rectangle((0, 0, 260, 8), fill=GOLD)
    # Marke
    mark = euro_mark(150)
    im.paste(mark, (100, 190), mark)
    f_word = font(FONT_BOLD, 74)
    f_sub = font(FONT_REG, 34)
    d.text((290, 185), domain, font=f_word, fill=ANTHRAZIT)
    d.text((292, 285), sub, font=f_sub, fill=GRAU)
    # Goldbalken
    d.rectangle((292, 345, 292 + 120, 351), fill=GOLD)
    # Zeile automatisch in die verfuegbare Breite einpassen
    size = 30
    while size > 18:
        f_claim = font(FONT_REG, size)
        if d.textlength(claim, font=f_claim) <= W - 292 - 80:
            break
        size -= 1
    d.text((292, 380), claim, font=f_claim, fill=ANTHRAZIT)
    f_foot = font(FONT_REG, 24)
    d.text((100, 560), 'Ein Service der DETM Management Consulting FZCO', font=f_foot, fill=GRAU)
    return im


def main():
    for domain, (sub, claim) in DOMAINS.items():
        img_dir = os.path.join(ROOT, domain, 'assets', 'img')
        og_image(domain, sub, claim).save(os.path.join(img_dir, 'og-image.png'), optimize=True)
        icon = euro_mark(512)
        icon.save(os.path.join(img_dir, 'icon-512.png'), optimize=True)
        icon.resize((180, 180), Image.LANCZOS).convert('RGB').save(os.path.join(img_dir, 'apple-touch-icon.png'), optimize=True)
        fav32 = icon.resize((32, 32), Image.LANCZOS)
        fav32.save(os.path.join(img_dir, 'favicon-32.png'), optimize=True)
        fav32.save(os.path.join(img_dir, 'favicon.ico'), sizes=[(16, 16), (32, 32)])
        fav32.save(os.path.join(ROOT, domain, 'favicon.ico'), sizes=[(16, 16), (32, 32)])
        icon.resize((96, 96), Image.LANCZOS).save(os.path.join(img_dir, 'bildmarke.png'), optimize=True)
        print(domain, 'Assets ohne SmartEinzug-Logo erzeugt')


if __name__ == '__main__':
    main()
