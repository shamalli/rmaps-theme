/* Marker-pen arrows for the documentation sketches.
 *
 * A note says what it points at, and the arrow is drawn from the note to that
 * element, so moving a note or reflowing the mock never leaves an arrow
 * pointing at nothing:
 *
 *   <div class="note" style="left:620px;top:210px"
 *        data-to="#publish" data-at="l" data-from="l" data-bend=".3">text</div>
 *
 *   data-to    CSS selector of the target (first match in the page).
 *   data-at    side of the target the arrow lands on: l, r, t or b, optionally
 *              followed by a position along that side, 0..1 ("t.3" = the top
 *              edge, 30% from its left end). Default: the side facing the note.
 *   data-from  side of the note the arrow leaves from, same syntax. Default:
 *              the side facing the target.
 *   data-bend  how far the curve bulges, as a share of its length; the sign
 *              picks the side. Default .22.
 *   data-block the note labels a whole block (a page section, the radius
 *              circle), so render.py lets its target be big. Any other
 *              note points at the thing itself: the input, button or row.
 *
 * An arrow stays short (under ~170px), lands on the exact element and
 * crosses no text or control; render.py prints every arrow that does not.
 *
 * Everything is drawn into one SVG laid over .sheet once the fonts have
 * loaded (the note sizes depend on Segoe Print); render.py waits for
 * window.sketchReady before it takes the picture. */
(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var HEAD = 10;          // arrowhead stroke length
  var HEAD_ANGLE = 0.5;   // radians either side of the shaft
  var GAP_END = 5;        // stop this short of the target
  var GAP_START = 4;      // start this far off the note

  function rel(el, origin) {
    var r = el.getBoundingClientRect();
    return { l: r.left - origin.left, t: r.top - origin.top, r: r.right - origin.left, b: r.bottom - origin.top };
  }

  function centre(b) { return [(b.l + b.r) / 2, (b.t + b.b) / 2]; }

  // Side of box `a` that faces box `b`.
  function facing(a, b) {
    var ca = centre(a), cb = centre(b);
    var dx = cb[0] - ca[0], dy = cb[1] - ca[1];
    // Boxes side by side (no horizontal overlap) read left/right; stacked ones top/bottom.
    var hOverlap = Math.min(a.r, b.r) - Math.max(a.l, b.l);
    var vOverlap = Math.min(a.b, b.b) - Math.max(a.t, b.t);
    if (hOverlap < 0 && (vOverlap > 0 || Math.abs(dx) > Math.abs(dy))) return dx > 0 ? 'r' : 'l';
    return dy > 0 ? 'b' : 't';
  }

  function parseSide(spec) {
    if (!spec) return null;
    var side = spec.charAt(0), f = parseFloat(spec.slice(1));
    return { side: side, f: isNaN(f) ? 0.5 : f };
  }

  var NORMAL = { l: [-1, 0], r: [1, 0], t: [0, -1], b: [0, 1] };

  function point(box, s, gap) {
    var x, y;
    if (s.side === 'l' || s.side === 'r') {
      x = s.side === 'l' ? box.l : box.r;
      y = box.t + (box.b - box.t) * s.f;
    } else {
      x = box.l + (box.r - box.l) * s.f;
      y = s.side === 't' ? box.t : box.b;
    }
    var n = NORMAL[s.side];
    return [x + n[0] * gap, y + n[1] * gap];
  }

  function path(d) {
    var p = document.createElementNS(NS, 'path');
    p.setAttribute('d', d);
    return p;
  }

  function draw() {
    var sheet = document.querySelector('.sheet');
    if (!sheet) return;
    var origin = sheet.getBoundingClientRect();
    window.sketchArrows = [];
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('class', 'arrows');
    svg.setAttribute('width', Math.ceil(origin.width));
    svg.setAttribute('height', Math.ceil(origin.height));
    sheet.appendChild(svg);

    [].forEach.call(document.querySelectorAll('.note[data-to]'), function (note) {
      var target = document.querySelector(note.getAttribute('data-to'));
      if (!target) {
        note.style.outline = '2px dashed magenta'; // a missing target shows in the render
        return;
      }
      var nb = rel(note, origin), tb = rel(target, origin);
      var at = parseSide(note.getAttribute('data-at')) || { side: facing(tb, nb), f: 0.5 };
      var from = parseSide(note.getAttribute('data-from')) || { side: facing(nb, tb), f: 0.5 };
      var bend = parseFloat(note.getAttribute('data-bend'));
      if (isNaN(bend)) bend = 0.22;

      var s = point(nb, from, GAP_START), e = point(tb, at, GAP_END);
      var dx = e[0] - s[0], dy = e[1] - s[1], len = Math.sqrt(dx * dx + dy * dy) || 1;
      // Control point: off the midpoint, square to the shaft.
      var c = [(s[0] + e[0]) / 2 - dy / len * bend * len, (s[1] + e[1]) / 2 + dx / len * bend * len];
      // render.py reads these back to check each arrow (length, what it crosses, its target).
      window.sketchArrows.push({ note: note, target: target, s: s, c: c, e: e });
      svg.appendChild(path('M' + s[0].toFixed(1) + ' ' + s[1].toFixed(1)
        + ' Q' + c[0].toFixed(1) + ' ' + c[1].toFixed(1) + ' ' + e[0].toFixed(1) + ' ' + e[1].toFixed(1)));

      // Arrowhead along the curve's direction at its end (E - C for a quadratic).
      var a = Math.atan2(e[1] - c[1], e[0] - c[0]);
      var h1 = [e[0] - HEAD * Math.cos(a - HEAD_ANGLE), e[1] - HEAD * Math.sin(a - HEAD_ANGLE)];
      var h2 = [e[0] - HEAD * Math.cos(a + HEAD_ANGLE), e[1] - HEAD * Math.sin(a + HEAD_ANGLE)];
      svg.appendChild(path('M' + h1[0].toFixed(1) + ' ' + h1[1].toFixed(1) + ' L' + e[0].toFixed(1) + ' ' + e[1].toFixed(1)
        + ' L' + h2[0].toFixed(1) + ' ' + h2[1].toFixed(1)));
    });
    window.sketchReady = true;
  }

  function start() {
    (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(draw);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
