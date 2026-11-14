// Superadmin > Modules: switches update in place. Each switch is a plain form, so without JavaScript it still posts
// (with a PIN field from <noscript>). Switching a module OFF asks first. Then a PIN is asked for on an iPhone-style
// keypad. The server checks the PIN again, so this popup is a convenience, not the security. Price and discount
// forms post normally (they carry the class mod-price and are skipped here).
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

  /** The iPhone-style passcode popup. Resolves with the 6 digits, or null when cancelled. */
  function pinPad(title, sub, error) {
    return new Promise(function (resolve) {
      var overlay = el('div', 'pin-overlay');
      var panel = el('div', 'pin-panel');
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', title);
      panel.appendChild(el('h2', 'pin-title', title));
      panel.appendChild(el('p', 'pin-sub muted', sub));
      var msg = el('p', 'pin-error', error || '');
      panel.appendChild(msg);

      var dots = el('div', 'pin-dots');
      var dotEls = [];
      for (var i = 0; i < 6; i++) {
        var d = el('span', 'pin-dot');
        dotEls.push(d);
        dots.appendChild(d);
      }
      panel.appendChild(dots);

      var value = '';
      var keys = el('div', 'pin-keys');
      var done = false;
      function paint() {
        dotEls.forEach(function (d, i) { d.classList.toggle('on', i < value.length); });
      }
      function finish(result) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        resolve(result);
      }
      function press(digit) {
        if (value.length >= 6) return;
        value += digit;
        paint();
        if (value.length === 6) setTimeout(function () { finish(value); }, 120);
      }
      function key(label, aria, fn, cls) {
        var b = el('button', 'pin-key' + (cls ? ' ' + cls : ''), label);
        b.type = 'button';
        if (aria) b.setAttribute('aria-label', aria);
        b.addEventListener('click', fn);
        keys.appendChild(b);
        return b;
      }
      ['1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach(function (n) {
        key(n, n, function () { press(n); });
      });
      key('', '', null, 'ghost');
      key('0', '0', function () { press('0'); });
      key('⌫', 'Delete', function () { value = value.slice(0, -1); paint(); });
      panel.appendChild(keys);

      var cancel = el('button', 'pin-cancel', 'Cancel');
      cancel.type = 'button';
      cancel.addEventListener('click', function () { finish(null); });
      panel.appendChild(cancel);

      function onKey(e) {
        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); press(e.key); }
        else if (e.key === 'Backspace') { e.preventDefault(); value = value.slice(0, -1); paint(); }
        else if (e.key === 'Escape') { e.preventDefault(); finish(null); }
      }
      document.addEventListener('keydown', onKey, true);

      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      if (error) {
        panel.classList.add('is-error');
        setTimeout(function () { panel.classList.remove('is-error'); }, 450);
      }
      keys.querySelector('.pin-key').focus();
    });
  }

  /** Sends the switch with the PIN. Resolves { ok, pinWrong, locked, message }. */
  function post(card, form, pin) {
    setBusy(card, true);
    var fd = new FormData(form);
    fd.set('pin', pin);
    return fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (r.status === 401 || d.login) { location.reload(); return { ok: false }; }
        if (r.ok && d.ok === true) {
          paint(card, d.enabled);
          updateCount();
          renderAudit(d.audit);
          if (window.Portal) window.Portal.toast(d.message, 'success');
          return { ok: true };
        }
        return { ok: false, pinWrong: !!d.pin, locked: !!d.locked, message: d.message || 'Could not change this module. Try again.' };
      });
    }, function () {
      return { ok: false, message: 'Network problem. Check your connection and try again.' };
    }).then(function (res) {
      setBusy(card, false);
      return res;
    });
  }

  /** Asks for the PIN, sends, and asks again after a wrong PIN, until it is right, locked, or cancelled. */
  function switchWithPin(card, form, title, sub, error) {
    return pinPad(title, sub, error).then(function (pin) {
      if (pin === null) return;
      return post(card, form, pin).then(function (res) {
        if (res.ok) return;
        if (res.pinWrong) return switchWithPin(card, form, title, sub, 'Wrong PIN. Try again.');
        if (window.Portal) window.Portal.toast(res.message, 'error');
      });
    });
  }

  function confirmOff(name) {
    var text = 'Shoppers and staff lose access to it straight away. Its data is kept, and you can switch it back on at any time.';
    if (window.Confirm) return window.Confirm.ask({ title: 'Switch off ' + name + '?', text: text, button: 'Switch off', danger: true });
    return Promise.resolve(window.confirm('Switch off ' + name + '? ' + text));
  }

  list.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.classList.contains('mod-price')) return; // price forms post normally (no switch)
    var card = form.closest('[data-module]');
    if (!card || card.classList.contains('is-busy')) { e.preventDefault(); return; }
    e.preventDefault();
    var label = card.dataset.label;
    var turningOff = card.dataset.enabled === '1';
    var go = function () {
      switchWithPin(card, form,
        'Enter your PIN',
        (turningOff ? 'Switch off ' : 'Switch on ') + label,
        '');
    };
    if (turningOff) {
      confirmOff(label).then(function (ok) { if (ok) go(); });
    } else {
      go();
    }
  });
})();
