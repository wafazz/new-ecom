// Storefront: floating "online now" badge. Refreshes the count, and can be hidden or shown (remembered in this browser).
(function () {
  'use strict';
  var box = document.querySelector('[data-live-visitors]');
  if (!box) return;
  var url = box.dataset.liveUrl, KEY = 'shop-live-hidden';
  var toggle = box.querySelector('[data-live-toggle]');
  var label = box.querySelector('[data-live-label]');

  function setHidden(hidden, save) {
    box.classList.toggle('is-hidden', hidden);
    toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    label.textContent = hidden ? 'Show' : 'Online';
    if (save) { try { localStorage.setItem(KEY, hidden ? '1' : '0'); } catch (e) { /* private mode: just not remembered */ } }
  }

  function refresh() {
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); })
      .then(function (d) {
        box.querySelectorAll('[data-live]').forEach(function (el) {
          var v = d[el.dataset.live];
          if (v !== undefined) el.textContent = String(v);
        });
      })
      .catch(function () { /* keep the last number */ });
  }

  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) { /* ignore */ }
  setHidden(saved === '1', false);
  toggle.addEventListener('click', function () { setHidden(!box.classList.contains('is-hidden'), true); });
  setInterval(refresh, 30000);
})();
