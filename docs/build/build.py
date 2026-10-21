# Assemble the Rocket Maps docs page: python docs/build/build.py
# The chrome (styles, TOC script, lightbox) comes from the w2dc template in
# directory-theme-v3; the text from the 0*-*.html fragments here (IMG(name|alt)
# becomes a sketch figure). Writes page-rmaps-documentation.php and the
# standalone docs/rmaps-documentation.html. Edit the fragments, then rebuild.
import html, io, os, re
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
THEME = os.path.normpath(os.path.join(HERE, '..', '..'))
W2DC = 'C:/projects/directory-theme-v3/page-w2dc-documentation.php'
IMGDIR = THEME + '/assets/img/docs'

w = io.open(W2DC, encoding='utf-8').read()
style = w[w.index('<style>'):w.index('</style>') + len('</style>')]
script = w[w.index('<script>'):w.index('</script>') + len('</script>')]
lb = re.search(r'<div class="w2dc-docs__lb".*?</div></div>\n', w, re.S).group(0)

def rebrand(s):
    return s.replace('w2dc-docs', 'rmaps-docs').replace('--w2dc-docs-anchor', '--rmaps-docs-anchor')

style = rebrand(style)
script = rebrand(script)
lb = rebrand(lb)

# colours follow the theme's light/dark tokens; fonts follow the theme
style = re.sub(r'\.rmaps-docs\{--pri:[^}]*\}',
    ".rmaps-docs{--pri:var(--rmaps-theme-accent,#2563eb);--pri-d:var(--rmaps-theme-accent-hover,#1d4ed8);"
    "--sky-l:var(--rmaps-theme-accent-soft,rgba(37,99,235,.1));--ink:var(--rmaps-theme-fg-soft,#4b5563);"
    "--head:var(--rmaps-theme-fg,#191e25);--muted:var(--rmaps-theme-fg-mute,#6b7280);--steel:var(--rmaps-theme-fg-soft,#4b5563);"
    "--surface:var(--rmaps-theme-bg-soft,#f6f7f9);--line:var(--rmaps-theme-border,#e5e7eb);"
    "display:flex;align-items:flex-start;max-width:1320px;margin:0 auto;color:var(--ink);font-family:inherit;line-height:1.65;font-size:16px}",
    style, count=1)
style = style.replace("font-family:'Russo One','Roboto',sans-serif;font-size:19px;color:var(--pri-d)", "font-weight:700;font-size:19px;color:var(--head)")
style = style.replace("font-family:'Roboto',sans-serif;font-size:12px;letter-spacing", "font-size:12px;letter-spacing")
style = style.replace('color:#2b2b2b', 'color:var(--head)')
style = style.replace('.rmaps-docs__main tr:nth-child(even) td{background:#fbfdfe}', '.rmaps-docs__main tr:nth-child(even) td{background:var(--surface)}')
style = style.replace('color:#28414f}', 'color:var(--ink)}')
style = style.replace('.rmaps-docs__toc{position:sticky;top:65px;align-self:flex-start;max-height:calc(100vh - 65px)',
                      '.rmaps-docs__toc{position:sticky;top:64px;align-self:flex-start;max-height:calc(100vh - 64px)')
style = style.replace('body.admin-bar .rmaps-docs__toc{top:97px;max-height:calc(100vh - 97px)}', 'body.admin-bar .rmaps-docs__toc{top:96px;max-height:calc(100vh - 96px)}')
style = style.replace('--rmaps-docs-anchor:77px', '--rmaps-docs-anchor:76px').replace('--rmaps-docs-anchor:109px', '--rmaps-docs-anchor:108px')
style = re.sub(r'/\* The sticky TOC sits below.*?\*/', '/* The sticky TOC sits below the sticky site header (64px, --rmaps-theme-header-h).\n * With the WP admin bar it must also clear the 32px desktop bar -> 96. The TOC is\n * sticky only above 960px, where the bar is always 32px. */', style, flags=re.S)
style = re.sub(r'/\* Anchor-scroll offset.*?\*/', '/* Anchor-scroll offset: a clicked TOC link lands the heading below the sticky\n * 64px header, and below the 32px admin bar on desktop (at <=782px the bar is\n * position:absolute and the header returns to top:0). */', style, flags=re.S)
for a, b in [('\u2014', '-')]:
    style = style.replace(a, b); script = script.replace(a, b)

body = ''.join(io.open(os.path.join(HERE, f), encoding='utf-8').read()
               for f in ['01-install.html', '02-shortcodes.html', '03-features-a.html', '04-features-b.html', '05-custom.html', '06-translation.html'])

def figure(m, src):
    name, alt = m.group(1), m.group(2)
    fn = 'sketch-%s.png' % name
    wd, ht = Image.open(os.path.join(IMGDIR, fn)).size
    alt = html.escape(html.unescape(alt), quote=True)
    return ('<figure class="rmaps-docs__shot rmaps-docs__sketch"><img class="rmaps-docs__shot-img" src="%s" width="%d" height="%d" alt="%s"></figure>'
            % (src(fn), wd, ht, alt))

# TOC from the h2/h3 headings
toc, cur = [], None
for lvl, hid, txt in re.findall(r'<h([23]) id="([^"]+)">(.*?)</h\1>', body):
    if lvl == '2':
        cur = [hid, txt, []]; toc.append(cur)
    elif cur is not None:
        cur[2].append((hid, txt))
out = ['<nav><ul class="toc-l1">']
for hid, txt, kids in toc:
    out.append('<li><a href="#%s">%s</a>' % (hid, txt))
    if kids:
        out.append('<ul class="toc-l2">')
        out += ['<li><a href="#%s">%s</a></li>' % k for k in kids]
        out.append('</ul>')
    out.append('</li>')
out.append('</ul></nav>')
tocs = '\n'.join(out)

def page(src):
    b = re.sub(r'IMG\(([a-z-]+)\|(.*?)\)(?=<|\n)', lambda m: figure(m, src), body)
    assert 'IMG(' not in b, b[b.index('IMG('):b.index('IMG(') + 80]
    return ('<div class="rmaps-docs">\n<aside class="rmaps-docs__toc"><div class="rmaps-docs__toc-brand">Rocket Maps<span>Documentation</span></div>\n'
            '<button class="rmaps-docs__toggle" type="button">&#9776; Contents</button>\n' + tocs + '</aside>\n'
            '<main class="rmaps-docs__main">\n<header class="rmaps-docs__hero"><h1>Rocket Maps: Documentation</h1><span class="rmaps-docs__ver">Version 1.5.x</span>'
            '<p class="rmaps-docs__lead">Rocket Maps builds fast, searchable maps for WordPress and places them on any page with one shortcode: Google Maps, Mapbox or MapLibre, '
            'listings with your own fields, custom markers and clusters, popups and a listings sidebar, a drag-and-drop search form, front-end submission with a member dashboard, '
            'ratings and CSV import/export. This guide covers installation, every shortcode and its parameters, the feature set, styling and template overrides, and translation.</p></header>\n'
            + b + '\n<footer class="rmaps-docs__foot">Rocket Maps documentation &middot; salephpscripts.com.</footer>\n</main>\n' + lb + '</div>\n')

php = ('''<?php
/**
 * Template Name: Rocket Maps Documentation
 * Template Post Type: page
 *
 * Full-width Rocket Maps documentation page for the rmaps-theme.
 * Self-contained: styles + markup + script are scoped under .rmaps-docs, so it
 * cannot clash with the theme, and its colours follow the theme's light / dark
 * tokens. Create an (empty) Page and assign this template; the page content is
 * not used. The figures are sketches from docs/sketches (render.py writes them
 * to assets/img/docs/). A standalone copy is docs/rmaps-documentation.html.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// URL of a docs image with ?ver=<file mtime>, so every upload gets a new
// URL and Cloudflare/browser caches never serve a stale picture.
$rmaps_docs_img = function ( $name ) {
	$rel = '/assets/img/docs/' . $name;
	$ver = @filemtime( get_template_directory() . $rel );
	return esc_url( get_template_directory_uri() . $rel . ( $ver ? '?ver=' . $ver : '' ) );
};

get_header();
?>
<article class="rmaps-theme-page rmaps-theme-page-fullwidth">
<div class="rmaps-theme-page-content rmaps-theme-page-content-full">
''' + style + '\n' + page(lambda fn: "<?php echo $rmaps_docs_img( '%s' ); ?>" % fn) + script + '''
</div>
</article>
<?php
get_footer();
''')
io.open(THEME + '/page-rmaps-documentation.php', 'w', encoding='utf-8', newline='\n').write(php)

standalone = ('<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n'
              '<title>Rocket Maps Documentation</title>\n'
              '<style>body{margin:0;background:#fff;font-family:-apple-system,"Segoe UI",Roboto,sans-serif}</style>\n'
              + style.replace('top:64px', 'top:0').replace('calc(100vh - 64px)', '100vh') + '\n</head>\n<body>\n'
              + page(lambda fn: '../assets/img/docs/' + fn) + script + '\n</body>\n</html>\n')
standalone = standalone.replace('--rmaps-docs-anchor:76px', '--rmaps-docs-anchor:12px')
io.open(THEME + '/docs/rmaps-documentation.html', 'w', encoding='utf-8', newline='\n').write(standalone)
print('ok', len(php), 'toc items', len(toc), sum(len(k) for _, _, k in toc))
