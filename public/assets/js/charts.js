// Dependency-free chart helpers (SVG + HTML). Marks follow the house spec: 2px lines, >=8px markers with a
// 2px surface ring, ~10% area wash, hairline solid grid, bars <=24px with a rounded data end. Colours come
// from CSS variables so light/dark just work. All text is inserted with textContent (labels are untrusted).
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var Viz = (window.Viz = {});
  var currency = (document.documentElement.dataset.currency || 'MYR');

  function svg(tag, attrs, parent) {
    var n = document.createElementNS(NS, tag);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }
  function h(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  Viz.h = h;
  Viz.svg = svg;

  // ---- number formats --------------------------------------------------------------------
  Viz.amount = function (v) { return Number(v).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  Viz.currency = currency;
  Viz.money = function (v) { return currency + ' ' + Viz.amount(v); };
  Viz.int = function (v) { return Math.round(Number(v)).toLocaleString('en-MY'); };
  Viz.compact = function (v) {
    var a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
    return String(Math.round(v * 100) / 100);
  };
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  Viz.day = function (iso) { var p = iso.split('-'); return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1]; };
  Viz.ago = function (ts, now) {
    var d = Math.max(0, (now || Math.floor(Date.now() / 1000)) - ts);
    if (d < 90) return 'just now';
    if (d < 3600) return Math.floor(d / 60) + ' min ago';
    if (d < 86400) return Math.floor(d / 3600) + ' h ago';
    return Math.floor(d / 86400) + ' d ago';
  };

  /** Round the axis maximum up to a clean number so ticks read 0 / 500 / 1K. */
  Viz.niceMax = function (max, ticks) {
    if (!(max > 0)) return { max: ticks, step: 1 };
    var raw = max / ticks, mag = Math.pow(10, Math.floor(Math.log10(raw))), n = raw / mag;
    var step = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * mag;
    return { max: step * ticks, step: step };
  };

  // ---- tooltip (one shared element) -------------------------------------------------------
  var tt;
  function tip() {
    if (!tt) { tt = h('div', 'viz-tt'); tt.setAttribute('role', 'status'); document.body.appendChild(tt); }
    return tt;
  }
  Viz.showTip = function (x, y, nodes) {
    var t = tip();
    t.textContent = '';
    nodes.forEach(function (n) { t.appendChild(n); });
    t.classList.add('on');
    var w = t.offsetWidth, hh = t.offsetHeight;
    var left = x + 14, top = y - hh - 10;
    if (left + w > window.innerWidth - 8) left = x - w - 14;
    if (top < 8) top = y + 16;
    t.style.left = Math.max(8, left) + 'px';
    t.style.top = top + 'px';
  };
  Viz.hideTip = function () { if (tt) tt.classList.remove('on'); };
  function row(key, name, value) {
    var r = h('div', 'tt-row');
    if (key) { var k = h('i', 'tt-key'); k.style.background = key; r.appendChild(k); }
    r.appendChild(h('strong', null, value));
    r.appendChild(h('span', null, name));
    return r;
  }
  Viz.ttRow = row;

  // ---- sparkline ----------------------------------------------------------------------------
  Viz.sparkline = function (host, values, opts) {
    opts = opts || {};
    host.textContent = '';
    if (!values || !values.length) return;
    var w = Math.max(60, host.clientWidth || 120), hgt = opts.height || 32, pad = 5;
    var max = Math.max.apply(null, values), min = Math.min.apply(null, values);
    if (max === min) { max += 1; min = Math.max(0, min - 1); }
    var s = svg('svg', { width: w, height: hgt, viewBox: '0 0 ' + w + ' ' + hgt, 'aria-hidden': 'true', class: 'spark' }, host);
    var sx = function (i) { return pad + (values.length === 1 ? 0 : (i * (w - pad * 2)) / (values.length - 1)); };
    var sy = function (v) { return hgt - pad - ((v - min) / (max - min)) * (hgt - pad * 2); };
    var d = values.map(function (v, i) { return (i ? 'L' : 'M') + sx(i).toFixed(1) + ' ' + sy(v).toFixed(1); }).join('');
    svg('path', { d: d, class: 'viz-line', fill: 'none' }, s);
    var last = values.length - 1;
    svg('circle', { cx: sx(last), cy: sy(values[last]), r: 4, class: 'viz-dot' }, s);
  };

  // ---- time chart (area + line, previous period as context, crosshair + tooltip) -------------
  Viz.timeChart = function (host, cfg) {
    host.textContent = '';
    var labels = cfg.labels, series = cfg.series, fmt = cfg.format || Viz.int;
    var W = Math.max(280, host.clientWidth), H = cfg.height || 260;
    var m = { l: 46, r: 14, t: 10, b: 26 }, pw = W - m.l - m.r, ph = H - m.t - m.b;
    var all = []; series.forEach(function (s) { all = all.concat(s.values); });
    var nm = Viz.niceMax(Math.max.apply(null, all.concat([0])), 4);
    var n = labels.length;
    var x = function (i) { return m.l + (n === 1 ? pw / 2 : (i * pw) / (n - 1)); };
    var y = function (v) { return m.t + ph - (v / nm.max) * ph; };

    var s = svg('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, class: 'viz-chart', role: 'img', tabindex: '0',
      'aria-label': (cfg.title || 'Chart') + ': ' + (cfg.summary || '') + ' Use left and right arrow keys to read each day.' }, host);

    for (var t = 0; t <= 4; t++) {
      var v = nm.step * t, yy = y(v);
      svg('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: 'viz-grid' }, s);
      var yl = svg('text', { x: m.l - 8, y: yy + 4, class: 'viz-axis', 'text-anchor': 'end' }, s);
      yl.textContent = (cfg.axisFormat || Viz.compact)(v);
    }
    var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 74))));
    for (var i = 0; i < n; i += every) {
      var xl = svg('text', { x: x(i), y: H - 6, class: 'viz-axis', 'text-anchor': i === 0 ? 'start' : 'middle' }, s);
      xl.textContent = Viz.day(labels[i]);
    }

    // The primary series is the last one; context series draw first, beneath it.
    series.forEach(function (sr) {
      var pts = sr.values.map(function (v, i) { return [x(i), y(v)]; });
      var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join('');
      if (sr.primary) {
        svg('path', { d: line + 'L' + x(n - 1).toFixed(1) + ' ' + y(0) + 'L' + x(0).toFixed(1) + ' ' + y(0) + 'Z', class: 'viz-area' }, s);
      }
      svg('path', { d: line, class: sr.primary ? 'viz-line' : 'viz-line ctx', fill: 'none' }, s);
    });
    var prim = series.filter(function (q) { return q.primary; })[0];
    if (prim) svg('circle', { cx: x(n - 1), cy: y(prim.values[n - 1]), r: 4, class: 'viz-dot' }, s);

    if (!all.some(function (v) { return v > 0; })) {
      var msg = svg('text', { x: m.l + pw / 2, y: m.t + ph / 2, class: 'viz-axis', 'text-anchor': 'middle' }, s);
      msg.textContent = 'No data in this period yet';
    }
    var cross = svg('line', { y1: m.t, y2: m.t + ph, class: 'viz-cross', visibility: 'hidden' }, s);
    var dots = series.map(function (sr) { return svg('circle', { r: 4, class: sr.primary ? 'viz-dot' : 'viz-dot ctx', visibility: 'hidden' }, s); });
    var hit = svg('rect', { x: m.l, y: m.t, width: pw, height: ph, fill: 'transparent' }, s);

    function show(i, cx, cy) {
      i = Math.max(0, Math.min(n - 1, i));
      cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('visibility', 'visible');
      var rows = [h('div', 'tt-head', Viz.day(labels[i]))];
      for (var k = series.length - 1; k >= 0; k--) {
        dots[k].setAttribute('cx', x(i)); dots[k].setAttribute('cy', y(series[k].values[i])); dots[k].setAttribute('visibility', 'visible');
        rows.push(row(series[k].primary ? 'var(--viz-1)' : 'var(--viz-ctx)', series[k].name + (series[k].dates ? ' · ' + Viz.day(series[k].dates[i]) : ''), fmt(series[k].values[i])));
      }
      var box = s.getBoundingClientRect();
      Viz.showTip(cx != null ? cx : box.left + x(i), cy != null ? cy : box.top + y(prim ? prim.values[i] : 0), rows);
    }
    function hide() {
      cross.setAttribute('visibility', 'hidden');
      dots.forEach(function (d) { d.setAttribute('visibility', 'hidden'); });
      Viz.hideTip();
    }
    var cur = n - 1;
    hit.addEventListener('pointermove', function (e) {
      var box = s.getBoundingClientRect();
      cur = Math.round(((e.clientX - box.left - m.l) / pw) * (n - 1));
      show(cur, e.clientX, e.clientY);
    });
    hit.addEventListener('pointerleave', hide);
    s.addEventListener('focus', function () { show(cur); });
    s.addEventListener('blur', hide);
    s.addEventListener('keydown', function (e) {
      var d = e.key === 'ArrowLeft' ? -1 : e.key === 'ArrowRight' ? 1 : 0;
      if (e.key === 'Home') { cur = 0; show(cur); e.preventDefault(); }
      else if (e.key === 'End') { cur = n - 1; show(cur); e.preventDefault(); }
      else if (e.key === 'Escape') hide();
      else if (d) { cur = Math.max(0, Math.min(n - 1, cur + d)); show(cur); e.preventDefault(); }
    });
  };

  // ---- horizontal bars (nominal categories: one colour, thin, value at the tip) --------------
  Viz.hbars = function (host, rows, opts) {
    opts = opts || {};
    host.textContent = '';
    var fmt = opts.format || Viz.int;
    if (!rows.length || !rows.some(function (r) { return r.value > 0; })) {
      host.appendChild(h('p', 'muted empty', opts.empty || 'Nothing in this period yet.'));
      return;
    }
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; }));
    var list = h('div', 'hbars');
    // Reserve exactly enough room for the widest value label so it never overflows the panel.
    var widest = Math.max.apply(null, rows.map(function (r) { return fmt(r.value).length; }));
    list.style.setProperty('--vw', Math.ceil(widest * 7.6 + 18) + 'px');
    rows.forEach(function (r) {
      var row = h(r.href ? 'a' : 'div', 'hbar');
      if (r.href) row.setAttribute('href', r.href); else row.setAttribute('tabindex', '0');
      row.style.setProperty('--p', max > 0 ? (r.value / max).toFixed(4) : '0');
      var lab = h('span', 'hbar-label', r.label); lab.title = r.label;
      var track = h('span', 'hbar-track');
      track.appendChild(h('i', 'hbar-fill'));
      track.appendChild(h('b', 'hbar-val', fmt(r.value)));
      row.appendChild(lab); row.appendChild(track);
      var show = function (e) {
        var box = row.getBoundingClientRect();
        var nodes = [h('div', 'tt-head', r.label), row_(fmt(r.value), opts.valueName || 'Total')];
        (r.extra || []).forEach(function (x) { nodes.push(row_(x[1], x[0])); });
        Viz.showTip(e && e.clientX ? e.clientX : box.left + 120, e && e.clientY ? e.clientY : box.top, nodes);
      };
      function row_(v, name) { return Viz.ttRow('var(--viz-1)', name, v); }
      row.addEventListener('pointermove', show); row.addEventListener('focus', function () { show(); });
      row.addEventListener('pointerleave', Viz.hideTip); row.addEventListener('blur', Viz.hideTip);
      list.appendChild(row);
    });
    host.appendChild(list);
  };

  /** Accessible table alternative for any chart. */
  Viz.table = function (host, head, body) {
    host.textContent = '';
    var t = h('table', 'viz-table'), th = h('thead'), tr = h('tr');
    head.forEach(function (c, i) { var e = h('th', i ? 'num' : null, c); tr.appendChild(e); });
    th.appendChild(tr); t.appendChild(th);
    var tb = h('tbody');
    body.forEach(function (r) { var q = h('tr'); r.forEach(function (c, i) { q.appendChild(h('td', i ? 'num' : null, c)); }); tb.appendChild(q); });
    t.appendChild(tb); host.appendChild(t);
  };

  /** Animate a number from its current value; respects reduced motion. */
  Viz.countUp = function (node, to, format, ms) {
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) { node.textContent = format(to); return; }
    var from = Number(node.dataset.v || 0), t0 = null; ms = ms || 550;
    node.dataset.v = to;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / ms), e = 1 - Math.pow(1 - p, 3);
      node.textContent = format(from + (to - from) * e);
      if (p < 1) requestAnimationFrame(step); else node.textContent = format(to);
    }
    requestAnimationFrame(step);
  };
})();
