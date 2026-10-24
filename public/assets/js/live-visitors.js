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

  // Each check also counts this browser as still online (the request is recorded like a page view).
  function refresh() {
    if (document.hidden) return;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.json(); })
      .then(function (d) {
        box.querySelectorAll('[data-live]').forEach(function (el) {
          var v = d[el.dataset.live];
          if (v === undefined) return;
          var next = String(v);
          if (el.textContent !== next) {
            el.textContent = next;
            el.classList.remove('live-bump');
            void el.offsetWidth; // restart the flash
            el.classList.add('live-bump');
          }
        });
      })
      .catch(function () { /* keep the last number; the next check tries again */ });
  }

  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) { /* ignore */ }
  setHidden(saved === '1', false);
  toggle.addEventListener('click', function () { setHidden(!box.classList.contains('is-hidden'), true); });
  setInterval(refresh, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
