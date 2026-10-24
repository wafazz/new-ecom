// Visitors (admin Visitors page and dashboard panel): live numbers that count up when they change, and the 7-day bars.
(function () {
  'use strict';
  var root = document.querySelector('[data-visitors-root]');
  if (!root) return;
  var url = root.dataset.statsUrl;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Count from the shown number to the new one, so changes are easy to notice.
  function countTo(el, to) {
    var from = parseInt(el.textContent, 10);
    if (isNaN(from) || reduce || from === to) { el.textContent = String(to); return; }
    var start = null, dur = 600;
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      el.textContent = String(Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
    el.classList.remove('live-bump');
    void el.offsetWidth;
    el.classList.add('live-bump');
  }

  function drawTrend(box, trend) {
    var max = Math.max.apply(null, [1].concat(trend.map(function (d) { return d.n; })));
    box.textContent = '';
    trend.forEach(function (d) {
      var wrap = document.createElement('span');
      wrap.className = 'vis-bar-wrap';
      wrap.title = d.label + ': ' + d.n + ' unique visitors';
      var bar = document.createElement('span');
      bar.className = 'vis-bar';
      bar.style.height = Math.max(4, Math.round(d.n / max * 100)) + '%';
      var lab = document.createElement('span');
      lab.className = 'vis-bar-label';
      lab.textContent = d.label;
      wrap.appendChild(bar);
      wrap.appendChild(lab);
      box.appendChild(wrap);
    });
  }

  function update(data) {
    root.querySelectorAll('[data-stat]').forEach(function (el) {
      var v = data[el.dataset.stat];
      if (v === undefined) return;
      if (el.hasAttribute('data-count')) countTo(el, Number(v));
      else el.textContent = String(v);
    });
    var trendBox = root.querySelector('[data-trend]');
    if (trendBox && Array.isArray(data.trend)) drawTrend(trendBox, data.trend);
  }

  function refresh() {
    if (document.hidden) return;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); })
      .then(update)
      .catch(function () { /* keep the last numbers; the next refresh tries again */ });
  }

  setInterval(refresh, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
