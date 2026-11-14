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

  /** Asks for the term when a paid module is switched on. Resolves 'monthly' | 'yearly' | 'one_time', or null. */
  function termPicker(name) {
    return new Promise(function (resolve) {
      var overlay = el('div', 'pin-overlay');
      var panel = el('div', 'pin-panel term-panel');
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', 'Choose a term for ' + name);
      panel.appendChild(el('h2', 'pin-title', 'Switch on ' + name));
      panel.appendChild(el('p', 'pin-sub muted', 'How long should it stay on?'));
      var options = el('div', 'term-options');
      var done = false;
      function finish(v) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
        resolve(v);
      }
      [['monthly', 'Monthly', '1 month'], ['yearly', 'Yearly', '1 year'], ['one_time', 'Lifetime', 'No end date']].forEach(function (o) {
        var b = el('button', 'term-option');
        b.type = 'button';
        b.dataset.term = o[0];
        b.appendChild(el('span', 'term-name', o[1]));
        b.appendChild(el('span', 'term-sub muted', o[2]));
        b.addEventListener('click', function () { finish(o[0]); });
        options.appendChild(b);
      });
      panel.appendChild(options);
      var cancel = el('button', 'pin-cancel', 'Cancel');
      cancel.type = 'button';
      cancel.addEventListener('click', function () { finish(null); });
      panel.appendChild(cancel);
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); finish(null); }
      }
      document.addEventListener('keydown', onKey, true);
      overlay.appendChild(panel);
      document.body.appendChild(overlay);
      options.querySelector('.term-option').focus();
    });
  }

  /** Sends the switch with the PIN (and the term, when switching a paid module on). Resolves { ok, pinWrong, locked, message }. */
  function post(card, form, pin, term) {
    setBusy(card, true);
    var fd = new FormData(form);
    fd.set('pin', pin);
    if (term) fd.set('term', term);
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
  function switchWithPin(card, form, title, sub, error, term) {
    return pinPad(title, sub, error).then(function (pin) {
      if (pin === null) return;
      return post(card, form, pin, term).then(function (res) {
        if (res.ok) return;
        if (res.pinWrong) return switchWithPin(card, form, title, sub, 'Wrong PIN. Try again.', term);
        if (window.Portal) window.Portal.toast(res.message, 'error');
      });
    });
  }

  function confirmOff(name) {
    var text = 'Shoppers and staff lose access to it straight away. Its data is kept, and you can switch it back on at any time.';
    if (window.Confirm) return window.Confirm.ask({ title: 'Switch off ' + name + '?', text: text, button: 'Switch off', danger: true });
    return Promise.resolve(window.confirm('Switch off ' + name + '? ' + text));
  }


  // Inline discount timers: "Ends in 2d 03:10:05", then "Ended". Used for the discount end times in this page.
  function fmtLeft(ms) {
    var s = Math.floor(ms / 1000);
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    var x = s % 60;
    function p(n) { return n < 10 ? '0' + n : '' + n; }
    return (d > 0 ? d + 'd ' : '') + p(h) + ':' + p(m) + ':' + p(x);
  }
  var inlineTimers = Array.prototype.slice.call(document.querySelectorAll('.mod-timer, .bill-disc-timer'));
  function tickInline() {
    var live = 0;
    inlineTimers.forEach(function (t) {
      var left = Number(t.dataset.endMs) - Date.now();
      if (left <= 0) { t.textContent = 'Ended'; t.classList.add('is-ended'); return; }
      live++;
      t.textContent = 'Ends in ' + fmtLeft(left);
    });
    return live;
  }
  if (inlineTimers.length) {
    tickInline();
    var inlineHandle = setInterval(function () { if (tickInline() === 0) clearInterval(inlineHandle); }, 1000);
  }

  // ---- Pricing and discounts (inside each card): live prices, save only when changed, quick end dates ----
  function fmt(n) { return 'RM' + n.toFixed(2); }
  function finalOf(list, kind, value) {
    if (kind === 'fixed') return Math.min(list, value);
    return Math.round(list * (1 - value / 100) * 100) / 100;
  }
  function paintPlans(form) {
    var dirty = false;
    Array.prototype.forEach.call(form.querySelectorAll('[data-plan-input]'), function (inp) {
      var raw = inp.value.trim().replace(',', '.');
      var label = inp.closest('.mod-plan').querySelector('[data-final]');
      var list = parseFloat(raw);
      if (inp.value.trim() !== (inp.dataset.initial || '')) dirty = true;
      if (raw === '' || isNaN(list) || list <= 0) {
        label.textContent = 'Not offered';
      } else if (inp.dataset.discKind) {
        label.textContent = 'Now ' + fmt(finalOf(list, inp.dataset.discKind, parseFloat(inp.dataset.discValue)));
      } else {
        label.textContent = fmt(list);
      }
    });
    var save = form.querySelector('[data-save-plans]');
    if (save) save.disabled = !dirty;
  }
  Array.prototype.forEach.call(list.querySelectorAll('[data-plans-form]'), function (form) {
    form.addEventListener('input', function () { paintPlans(form); });
    paintPlans(form);
  });

  Array.prototype.forEach.call(list.querySelectorAll('[data-discount-form]'), function (form) {
    var value = form.querySelector('[data-value-input]');
    Array.prototype.forEach.call(form.querySelectorAll('input[name=kind]'), function (radio) {
      radio.addEventListener('change', function () {
        value.placeholder = radio.value === 'fixed' ? 'e.g. 50.00' : 'e.g. 20';
      });
    });
    Array.prototype.forEach.call(form.querySelectorAll('[data-days]'), function (chip) {
      chip.addEventListener('click', function () {
        var d = new Date(Date.now() + Number(chip.dataset.days) * 86400000);
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        var local = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        form.querySelector('[data-ends]').value = local;
        Array.prototype.forEach.call(form.querySelectorAll('[data-days]'), function (c) { c.classList.toggle('is-on', c === chip); });
      });
    });
  });

  list.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.classList.contains('mod-price') || form.classList.contains('mod-plain')) return; // price, discount and clear forms post normally
    var card = form.closest('[data-module]');
    if (!card || card.classList.contains('is-busy')) { e.preventDefault(); return; }
    e.preventDefault();
    var label = card.dataset.label;
    var turningOff = card.dataset.enabled === '1';
    var priced = card.dataset.priced === '1';
    var go = function () {
      var askTerm = !turningOff && priced ? termPicker(label) : Promise.resolve('');
      askTerm.then(function (term) {
        if (term === null) return;
        switchWithPin(card, form,
          'Enter your PIN',
          (turningOff ? 'Switch off ' : 'Switch on ') + label + (term ? ' · ' + ({ monthly: 'monthly', yearly: 'yearly', one_time: 'lifetime' })[term] : ''),
          '', term);
      });
    };
    if (turningOff) {
      confirmOff(label).then(function (ok) { if (ok) go(); });
    } else {
      go();
    }
  });
})();
