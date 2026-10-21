# -*- coding: utf-8 -*-
"""Render the documentation sketches to PNG.

    python render.py                 # every src/*.html
    python render.py wizard maps     # just these (file names without .html)
    python render.py wizard --out C:/tmp   # somewhere else than the theme

Each sketch is laid out 880 CSS px wide (body in src/_sketch.css) and scaled
to 808, the width of the documentation column (.rmaps-docs__main in
page-rmaps-documentation.php: 900 minus 2 x 46 padding). The page is shot at 2x
in your installed Chrome (Playwright, channel="chrome"), then downsampled with
Pillow so small text and thin lines stay crisp. The height is the page's own.

Before each shot it waits for src/_sketch.js to draw the note arrows, then
prints any note that covers text or a control of the mock, or runs off the
sheet: a note belongs in empty space.

PNG, not JPEG: flat UI colour, small text and the red notes smear in JPEG.
The PNG is cut to a 256-colour palette (median cut refined by k-means, so a
lone colour such as an orange notice border is not pulled into the red of
the notes; dithered): a third of
the bytes, and a flat UI loses nothing visible.
Writes ../../assets/img/docs/sketch-<name>.png, where the page expects it.

Needs:  pip install playwright pillow
"""
import argparse
import glob
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'src')
OUT_DIR = os.path.join(HERE, '..', '..', 'assets', 'img', 'docs')
LAYOUT_W = 880   # keep in step with body{} in src/_sketch.css
OUT_W = 808      # the documentation column

# Notes that sit on top of the mock's text or controls, or off the sheet.
OVERLAPS_JS = """() => {
  const sheet = document.querySelector('.sheet').getBoundingClientRect();
  const hits = [];
  const boxes = [];
  const walker = document.createTreeWalker(document.querySelector('.sheet'), NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    if (!n.textContent.trim() || n.parentElement.closest('.note')) continue;
    const range = document.createRange();
    range.selectNodeContents(n);
    for (const r of range.getClientRects()) boxes.push([r, n.textContent.trim().slice(0, 28)]);
  }
  document.querySelectorAll('.btn,.wpbtn,.inp,.sel,.mk,.cb,.rd,.tg,img,.fld').forEach(e => {
    if (!e.closest('.note')) boxes.push([e.getBoundingClientRect(), '<' + e.className + '>']);
  });
  document.querySelectorAll('.note').forEach(note => {
    const a = note.getBoundingClientRect(), name = note.textContent.trim().slice(0, 24);
    if (a.left < sheet.left + 2 || a.right > sheet.right - 2) hits.push(name + ' | runs off the sheet');
    for (const [b, what] of boxes) {
      if (b.right > a.left && b.left < a.right && b.bottom > a.top && b.top < a.bottom) hits.push(name + ' | covers ' + what);
    }
  });
  return hits;
}"""

# Arrows that run long, cut across the mock's text or controls, or land on a
# box so big the reader cannot tell what they point at. _sketch.js leaves each
# arrow's start, control and end point (sheet coordinates) in window.sketchArrows.
ARROWS_JS = """([maxLen, maxW, maxH]) => {
  const sheet = document.querySelector('.sheet').getBoundingClientRect();
  const rel = r => ({l: r.left - sheet.left, t: r.top - sheet.top, r: r.right - sheet.left, b: r.bottom - sheet.top});
  const boxes = [];
  const walker = document.createTreeWalker(document.querySelector('.sheet'), NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    if (!n.textContent.trim() || n.parentElement.closest('.note')) continue;
    const range = document.createRange();
    range.selectNodeContents(n);
    for (const r of range.getClientRects()) boxes.push([rel(r), n.parentElement, n.textContent.trim().slice(0, 24)]);
  }
  document.querySelectorAll('.btn,.wpbtn,.inp,.sel,.cb,.rd,.tg,img').forEach(e => {
    if (!e.closest('.note')) boxes.push([rel(e.getBoundingClientRect()), e, '<' + (e.className || e.tagName) + '>']);
  });
  const hits = [];
  for (const a of (window.sketchArrows || [])) {
    const name = a.note.textContent.trim().slice(0, 24);
    const len = Math.hypot(a.e[0] - a.s[0], a.e[1] - a.s[1]);
    if (len > maxLen) hits.push(name + ' | long arrow, ' + Math.round(len) + 'px');
    const tb = a.target.getBoundingClientRect();
    // A note that labels a whole block says so with data-block; any other target
    // must be the thing itself (an input, a button, a row), not the box around it.
    if (!a.note.hasAttribute('data-block') && (tb.height > maxH || (tb.width > maxW && tb.height > 60)))
      hits.push(name + ' | target too big (' + Math.round(tb.width) + 'x' + Math.round(tb.height) + '): point at the thing itself');
    const crossed = new Set();
    for (let i = 3; i <= 37; i++) {
      const t = i / 40, u = 1 - t;
      const x = u * u * a.s[0] + 2 * u * t * a.c[0] + t * t * a.e[0];
      const y = u * u * a.s[1] + 2 * u * t * a.c[1] + t * t * a.e[1];
      for (const [b, el, what] of boxes) {
        if (a.target.contains(el) || el.contains(a.target)) continue;
        if (x > b.l && x < b.r && y > b.t && y < b.b) crossed.add(what);
      }
    }
    if (crossed.size) hits.push(name + ' | crosses ' + [...crossed].slice(0, 4).join(', '));
  }
  return hits;
}"""
MAX_ARROW = 170          # CSS px, start to end
MAX_TARGET = (320, 140)  # taller than 140, or wider than 320 and taller than 60: a container, not a thing


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('names', nargs='*', help='sketch names (default: all)')
    ap.add_argument('--out', default=OUT_DIR, help='output folder')
    args = ap.parse_args()

    try:
        from playwright.sync_api import sync_playwright
        from PIL import Image
    except ImportError:
        sys.exit('Needs:  pip install playwright pillow')
    # A Windows console codepage cannot print every character of a note.
    sys.stdout.reconfigure(errors='replace')

    names = args.names or sorted(
        os.path.splitext(os.path.basename(p))[0]
        for p in glob.glob(os.path.join(SRC, '*.html'))
        if not os.path.basename(p).startswith('_')
    )
    os.makedirs(args.out, exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chrome', headless=True)
        page = browser.new_page(viewport={'width': LAYOUT_W, 'height': 600}, device_scale_factor=2)
        for name in names:
            src = os.path.join(SRC, name + '.html')
            if not os.path.exists(src):
                print('skip (no such sketch):', name)
                continue
            page.goto('file:///' + src.replace('\\', '/'))
            page.wait_for_load_state('load')
            page.evaluate('document.fonts.ready')
            page.wait_for_function('window.sketchReady === true || !document.querySelector(\'script[src$="_sketch.js"]\')')
            for hit in page.evaluate(OVERLAPS_JS):
                print('  note overlap in %s: %s' % (name, hit))
            for hit in page.evaluate(ARROWS_JS, [MAX_ARROW, MAX_TARGET[0], MAX_TARGET[1]]):
                print('  arrow in %s: %s' % (name, hit))
            h = page.evaluate('Math.ceil(document.querySelector(".sheet").getBoundingClientRect().bottom)')
            page.set_viewport_size({'width': LAYOUT_W, 'height': h})
            tmp = os.path.join(args.out, '.' + name + '@2x.png')
            page.screenshot(path=tmp, clip={'x': 0, 'y': 0, 'width': LAYOUT_W, 'height': h})
            out_h = round(h * OUT_W / LAYOUT_W)
            out = os.path.join(args.out, 'sketch-%s.png' % name)
            img = Image.open(tmp).convert('RGB').resize((OUT_W, out_h), Image.LANCZOS)
            img = img.quantize(colors=256, method=Image.Quantize.MEDIANCUT, kmeans=3, dither=Image.Dither.FLOYDSTEINBERG)
            img.save(out, 'PNG', optimize=True)
            os.remove(tmp)
            print('wrote', os.path.normpath(out), '%dx%d' % (OUT_W, out_h))
        browser.close()


if __name__ == '__main__':
    main()
