#!/usr/bin/env python3
"""
Generates the WordPress.org directory assets (icon, banner) for the plugin.

The files produced here are NOT part of the plugin package: they belong to the
"assets/" folder at the root of the plugin's Subversion repository, next to
trunk/, tags/ and branches/.

Requirements: Pillow, plus the Poppins and Kalam font files (see FONT_DIR).

Author: Mirko Montecchiani (mirkomontecchiani.com)
"""

import os
from PIL import Image, ImageDraw, ImageFont

# --- Paths ------------------------------------------------------------------

HERE = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.path.join(HERE, 'assets')
FONT_DIR = os.environ.get('MBCAT_FONT_DIR', os.path.join(HERE, 'fonts'))

# --- Palette ----------------------------------------------------------------
# The dark blue is the same one the plugin uses for the book detail overlay,
# the greys are those of the "no cover" placeholder shipped with the plugin.

BG_DARK = (26, 26, 46)      # #1a1a2e
BG_LIGHT = (38, 38, 66)     # #262642
PAGE = (255, 255, 255)      # #ffffff
PAGE_EDGE = (156, 163, 175) # #9ca3af
ACCENT = (242, 181, 68)     # #f2b544
TEXT_MUTED = (156, 163, 175)
SIGNATURE = (124, 124, 158)

BOOK_BOX = (70, 330, 930, 806)  # left, top, right, bottom in the 1000 space
BOOK_CX = (BOOK_BOX[0] + BOOK_BOX[2]) / 2
BOOK_CY = (BOOK_BOX[1] + BOOK_BOX[3]) / 2

# Supersampling factor: everything is drawn large and scaled down at the end,
# which is what gives the shapes and the text their antialiased edges.
SS = 4


def font(name, size):
    """Loads one of the bundled fonts at the given size."""
    return ImageFont.truetype(os.path.join(FONT_DIR, name), size)


def qbez(p0, p1, p2, steps=48):
    """Samples a quadratic Bezier curve as a list of points."""
    pts = []
    for i in range(steps + 1):
        t = i / steps
        u = 1 - t
        x = u * u * p0[0] + 2 * u * t * p1[0] + t * t * p2[0]
        y = u * u * p0[1] + 2 * u * t * p1[1] + t * t * p2[1]
        pts.append((x, y))
    return pts


def draw_book(draw, ox, oy, size, ribbon=True):
    """
    Draws an open book inside a square box of the given size, positioned at
    (ox, oy). The shape is described in a 1000x1000 space and scaled from there,
    so the same code serves both the icon and the banner.
    """
    s = size / 1000.0

    def p(x, y):
        return (ox + x * s, oy + y * s)

    def page(outer_x, center_x):
        """One half of the book: the outer edge is vertical, the top and bottom
        edges sag gently towards the centre, the way an open book does."""
        mid_x = (outer_x + center_x) / 2
        top = qbez(p(outer_x, 330), p(mid_x, 348), p(center_x, 400))
        bottom = qbez(p(center_x, 780), p(mid_x, 718), p(outer_x, 700))
        return top + bottom

    for outer_x, center_x in ((70, 480), (930, 520)):
        pts = page(outer_x, center_x)
        # The stack of pages underneath, drawn first as a shifted copy.
        draw.polygon([(x, y + 26 * s) for x, y in pts], fill=PAGE_EDGE)
        draw.polygon(pts, fill=PAGE)

    if ribbon:
        # A bookmark ribbon hanging from the top of the right hand page.
        x0, x1 = p(600, 0)[0], p(648, 0)[0]
        y0, y1 = p(0, 424)[1], p(0, 580)[1]
        notch = p(0, 545)[1]
        draw.polygon(
            [(x0, y0), (x1, y0), (x1, y1), ((x0 + x1) / 2, notch), (x0, y1)],
            fill=ACCENT,
        )


def build_icon(px):
    """Builds one square icon of the requested size."""
    n = px * SS
    img = Image.new('RGB', (n, n), BG_DARK)
    draw = ImageDraw.Draw(img)
    # The book itself, not the box around it, is what gets centred: it is much
    # wider than it is tall, so centring the box would leave it sitting low.
    scale = n * 0.88 / (BOOK_BOX[2] - BOOK_BOX[0])
    draw_book(draw, n * 0.5 - BOOK_CX * scale, n * 0.5 - BOOK_CY * scale, 1000 * scale)
    return img.resize((px, px), Image.LANCZOS)


def build_banner(width, height):
    """Builds the directory banner at the requested size."""
    n_w, n_h = width * SS, height * SS
    img = Image.new('RGB', (n_w, n_h), BG_DARK)
    draw = ImageDraw.Draw(img)

    # Diagonal gradient, painted one column at a time.
    for x in range(n_w):
        t = x / n_w
        col = tuple(int(BG_DARK[i] + (BG_LIGHT[i] - BG_DARK[i]) * t) for i in range(3))
        draw.line([(x, 0), (x, n_h)], fill=col)

    # Decorative shelf of book covers on the right, kept very low contrast so
    # it reads as a texture and never competes with the title.
    shelf = Image.new('RGBA', (n_w, n_h), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(shelf)
    base_y = n_h * 0.90
    x = n_w * 0.815
    for w_f, h_f, alpha in (
        (0.046, 0.60, 36), (0.038, 0.72, 30), (0.050, 0.50, 24), (0.040, 0.66, 18)
    ):
        w, h = n_w * w_f, n_h * h_f
        sdraw.rounded_rectangle(
            [x, base_y - h, x + w, base_y], radius=n_h * 0.018, fill=(255, 255, 255, alpha)
        )
        # Two short bands near the top read as the title and the author on a
        # spine, which is what tells these apart from the bars of a chart.
        for band, band_h in ((0.16, 0.030), (0.24, 0.018)):
            sdraw.rounded_rectangle(
                [
                    x + w * 0.24,
                    base_y - h + h * band,
                    x + w * 0.76,
                    base_y - h + h * (band + band_h),
                ],
                radius=n_h * 0.004,
                fill=(255, 255, 255, alpha + 26),
            )
        x += w * 1.42
    # The shelf the books stand on.
    sdraw.rectangle(
        [n_w * 0.795, base_y, n_w, base_y + n_h * 0.012], fill=(255, 255, 255, 30)
    )
    img = Image.alpha_composite(img.convert('RGBA'), shelf).convert('RGB')
    draw = ImageDraw.Draw(img)

    # The book glyph on the left.
    glyph = n_h * 0.66
    draw_book(draw, n_w * 0.045, n_h * 0.5 - glyph * (BOOK_CY / 1000), glyph)

    # Title, subtitle and signature.
    text_x = n_w * 0.045 + glyph * 1.02
    title_f = font('Poppins-SemiBold.ttf', int(n_h * 0.148))
    sub_f = font('Poppins-Regular.ttf', int(n_h * 0.062))
    sign_f = font('Kalam-Regular.ttf', int(n_h * 0.062))

    draw.text((text_x, n_h * 0.30), 'Montecchiani', font=title_f, fill=PAGE, anchor='ls')
    draw.text((text_x, n_h * 0.48), 'Book Catalog', font=title_f, fill=PAGE, anchor='ls')
    draw.text(
        (text_x, n_h * 0.645),
        'Book post type, responsive grid and one click ISBN autofill',
        font=sub_f,
        fill=TEXT_MUTED,
        anchor='ls',
    )
    draw.text((text_x, n_h * 0.80), 'Mirko Montecchiani', font=sign_f, fill=SIGNATURE, anchor='ls')

    return img.resize((width, height), Image.LANCZOS)


def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    jobs = [
        ('icon-256x256.png', build_icon(256)),
        ('icon-128x128.png', build_icon(128)),
        ('banner-1544x500.png', build_banner(1544, 500)),
        ('banner-772x250.png', build_banner(772, 250)),
    ]
    for name, img in jobs:
        path = os.path.join(OUT_DIR, name)
        img.save(path, 'PNG', optimize=True)
        print('%-22s %s' % (name, '%d bytes' % os.path.getsize(path)))


if __name__ == '__main__':
    main()
