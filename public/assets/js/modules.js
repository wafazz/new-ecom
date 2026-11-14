// Superadmin > Modules: switches update in place. Each switch is a plain form, so without JavaScript it still
// posts and redirects. Switching a module OFF asks first. Uses Portal.toast (portal.js) and Confirm.ask (confirm.js).
(function () {
  'use strict';
  var root = document.querySelector('[data-modules]');
  if (!root) return;
  var list = root.querySelector('[data-module-list]');
  var count = root.querySelector('[data-count]');
  var audit = root.querySelector('[data-audit]');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function paint(card, on) {
    card.dataset.enabled = on ? '1' : '0';
    card.querySelector('input[name=enabled]').value = on ? '0' : '1';
    var sw = card.querySelector('.mod-switch');
    var name = card.dataset.label;
    sw.setAttribute('aria-checked', on ? 'true' : 'false');
    sw.setAttribute('aria-label', name + ': ' + (on ? 'on, switch off' : 'off, switch on'));
    sw.querySelector('.mod-switch-text').textContent = on ? 'Switch off' : 'Switch on';
    var status = card.querySelector('.mod-status');
    status.textContent = on ? 'On' : 'Off';
    status.className = 'mod-status ' + (on ? 'is-on' : 'is-off');
  }

  function updateCount() {
    var cards = list.querySelectorAll('[data-module]');
    var on = Array.prototype.filter.call(cards, function (c) { return c.dataset.enabled === '1'; }).length;
    count.textContent = on + ' of ' + cards.length + ' on';
  }

  function renderAudit(rows) {
    audit.innerHTML = '';
    if (!rows || !rows.length) {
      audit.appendChild(el('p', 'muted', 'No module changes yet.'));
      return;
    }
    var ol = el('ol', 'mod-log');
    rows.forEach(function (r) {
      var li = el('li');
      li.appendChild(el('span', 'mod-log-what', r.what));
      li.appendChild(document.createTextNode(' '));
      li.appendChild(el('span', 'muted', r.who + ' · ' + r.when));
      ol.appendChild(li);
    });
    audit.appendChild(ol);
  }

  function setBusy(card, busy) {
    card.classList.toggle('is-busy', busy);
    card.querySelector('.mod-switch').disabled = busy;
  }

  function send(card, form) {
    setBusy(card, true);
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (r.status === 401 || d.login) { location.reload(); return; }
        if (!r.ok || d.ok !== true) throw new Error(d.message || 'Could not change this module. Try again.');
        paint(card, d.enabled);
        updateCount();
        renderAudit(d.audit);
        if (window.Portal) window.Portal.toast(d.message, 'success');
      });
    }, function () {
      if (window.Portal) window.Portal.toast('Network problem. Check your connection and try again.', 'error');
    }).catch(function (err) {
      if (window.Portal) window.Portal.toast(err.message, 'error');
    }).then(function () { setBusy(card, false); });
  }

  function confirmOff(name) {
    var text = 'Shoppers and staff lose access to it straight away. Its data is kept, and you can switch it back on at any time.';
    if (window.Confirm) return window.Confirm.ask({ title: 'Switch off ' + name + '?', text: text, button: 'Switch off', danger: true });
    return Promise.resolve(window.confirm('Switch off ' + name + '? ' + text));
  }

  list.addEventListener('submit', function (e) {
    var form = e.target;
    var card = form.closest('[data-module]');
    if (!card || card.classList.contains('is-busy')) { e.preventDefault(); return; }
    e.preventDefault();
    if (card.dataset.enabled === '1') {
      confirmOff(card.dataset.label).then(function (ok) { if (ok) send(card, form); });
    } else {
      send(card, form);
    }
  });
})();
