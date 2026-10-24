// Visitors page: refreshes the numbers from the stats endpoint every 15 seconds.
(function () {
  'use strict';
  var root = document.querySelector('[data-visitors-root]');
  if (!root) return;
  var url = root.dataset.statsUrl;

  function update(data) {
    root.querySelectorAll('[data-stat]').forEach(function (el) {
      var v = data[el.dataset.stat];
      if (v !== undefined) el.textContent = String(v);
    });
  }

  function refresh() {
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); })
      .then(update)
      .catch(function () { /* keep the last numbers; the next refresh tries again */ });
  }

  setInterval(refresh, 15000);
})();
