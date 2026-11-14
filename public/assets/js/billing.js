// Admin > Module billing: a live countdown to the end of each paid period, and the chosen receipt's file name.
// Everything works without JavaScript; the countdown just shows its end date until the script runs.
(function () {
  'use strict';

  // Flip-clock countdown for each paid card. The end time comes from the server as a timestamp (any timezone).
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  var clocks = Array.prototype.slice.call(document.querySelectorAll('.flip-clock[data-end-ms]'));
  clocks.forEach(function (c) {
    c._cells = Array.prototype.slice.call(c.querySelectorAll('.flip-num'));
    c._end = c.querySelector('.flip-end');
    c._endText = c._end ? c._end.textContent : '';
  });
  // A page-flip: the top half of the old digits folds down, and the bottom half of the new digits rises into place.
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function flipTo(num, text) {
    var tile = num.parentNode;
    var size = getComputedStyle(num).fontSize;
    var color = getComputedStyle(num).color;
    var old = num.textContent;
    num.textContent = text;
    var top = document.createElement('div');
    top.className = 'flip-leaf top';
    var topFace = document.createElement('span');
    topFace.className = 'flip-face';
    topFace.textContent = old;
    topFace.style.fontSize = size;
    topFace.style.color = color;
    top.appendChild(topFace);
    var bottom = document.createElement('div');
    bottom.className = 'flip-leaf bottom';
    var bottomFace = document.createElement('span');
    bottomFace.className = 'flip-face';
    bottomFace.textContent = text;
    bottomFace.style.fontSize = size;
    bottomFace.style.color = color;
    bottom.appendChild(bottomFace);
    tile.appendChild(top);
    tile.appendChild(bottom);
    var done = 0;
    function cleanup() { if (++done === 2) { top.remove(); bottom.remove(); } }
    top.addEventListener('animationend', cleanup);
    bottom.addEventListener('animationend', cleanup);
  }
  function setCell(num, text) {
    if (num.textContent === text) return;
    if (reduceMotion) { num.textContent = text; return; }
    flipTo(num, text);
  }
  function paintClock(c) {
    var left = Number(c.dataset.endMs) - Date.now();
    c.classList.remove('is-soon', 'is-ended');
    if (left <= 0) {
      c._cells.forEach(function (n) { setCell(n, '00'); });
      c.classList.add('is-ended');
      if (c._end) c._end.textContent = c.dataset.endedText || 'Ended · pay to renew';
      return false;
    }
    var s = Math.floor(left / 1000);
    var parts = [Math.floor(s / 86400), Math.floor((s % 86400) / 3600), Math.floor((s % 3600) / 60), s % 60];
    c._cells.forEach(function (n, i) { setCell(n, pad(parts[i])); });
    if (left < 7 * 86400000) c.classList.add('is-soon');
    if (c._end) c._end.textContent = c._endText;
    return true;
  }
  function tickClocks() {
    var live = 0;
    clocks.forEach(function (c) { if (c.dataset.done !== '1') { if (paintClock(c)) live++; else c.dataset.done = '1'; } });
    return live;
  }
  if (clocks.length) {
    tickClocks();
    var clockHandle = setInterval(function () { if (tickClocks() === 0) clearInterval(clockHandle); }, 1000);
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

  // Per-card receipt pickers only. The basket's picker is handled in the basket block below.
  Array.prototype.forEach.call(document.querySelectorAll('[data-file-input]:not([data-basket-file])'), function (input) {
    var out = input.closest('form').querySelector('[data-file-name]');
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      out.textContent = f ? 'Selected: ' + f.name : '';
    });
  });

  // ---- Pay several modules together: the basket --------------------------------------------------------------
  var basket = document.getElementById('bill-basket');
  if (basket) {
    var summary = basket.querySelector('[data-basket-summary]');
    var list = basket.querySelector('[data-basket-list]');
    var totalEl = basket.querySelector('[data-basket-total]');
    var msg = basket.querySelector('[data-basket-msg]');
    var payBtn = basket.querySelector('[data-basket-pay]');
    var recBtn = basket.querySelector('[data-basket-receipt]');
    var count = basket.querySelector('[data-basket-count]');
    var hint = basket.querySelector('[data-basket-hint]');
    var drop = basket.querySelector('[data-drop]');
    var fileInput = basket.querySelector('[data-basket-file]');
    var fileName = basket.querySelector('[data-file-name]');
    var fileClear = basket.querySelector('[data-file-clear]');
    var toggle = document.querySelector('[data-basket-toggle]');
    var countMini = document.querySelector('[data-basket-count-mini]');
    var totalMini = document.querySelector('[data-basket-total-mini]');
    var maxBytes = Number(basket.dataset.maxBytes || 5242880);
    var allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    var cardOf = function (el) { return el.closest('.bill-card'); };
    var picked = function () {
      return Array.prototype.filter.call(document.querySelectorAll('[data-pick]'), function (c) { return c.checked; });
    };
    var planOf = function (pick) {
      var r = cardOf(pick).querySelector('input[name=period]:checked');
      return r || null;
    };
    var fileChosen = function () { return !!(fileInput && fileInput.files && fileInput.files[0]); };
    // Returns an error message for a bad receipt, or '' when it is fine.
    var receiptProblem = function () {
      if (!fileChosen()) return 'Choose a receipt file first.';
      var f = fileInput.files[0];
      if (allowedTypes.indexOf(f.type) === -1) return 'Receipt must be a JPG, PNG or PDF.';
      if (f.size > maxBytes) return 'Receipt is larger than 5 MB.';
      return '';
    };
    function bump(el) {
      el.classList.remove('is-bump');
      void el.offsetWidth; // restart the animation
      el.classList.add('is-bump');
    }
    function setFileState() {
      var f = fileChosen() ? fileInput.files[0] : null;
      var problem = f ? receiptProblem() : '';
      if (fileName) fileName.textContent = f ? (problem ? f.name + ' (' + problem + ')' : 'Ready: ' + f.name) : 'No file chosen';
      if (fileClear) fileClear.hidden = !f;
      if (drop) {
        drop.classList.toggle('is-ready', !!f && !problem);
        drop.classList.toggle('is-bad', !!problem);
      }
    }
    function refresh() {
      var picks = picked();
      var total = 0;
      var before = totalEl.textContent;
      list.innerHTML = '';
      picks.forEach(function (p) {
        var r = planOf(p);
        if (!r) return;
        var amount = Number(r.dataset.final || 0);
        total += amount;
        var li = document.createElement('li');
        var name = cardOf(p).querySelector('.bill-head h2');
        var plan = r.parentNode.querySelector('.bill-plan-name');
        var label = document.createElement('span');
        label.textContent = (name ? name.textContent : p.value) + ' · ' + (plan ? plan.textContent : r.value);
        li.appendChild(label);
        var right = document.createElement('span');
        right.className = 'bill-basket-end';
        var amt = document.createElement('span');
        amt.className = 'bill-basket-amt';
        amt.textContent = 'RM ' + amount.toFixed(2);
        right.appendChild(amt);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'bill-basket-rm';
        rm.dataset.remove = p.value;
        rm.setAttribute('aria-label', 'Remove ' + label.textContent + ' from this payment');
        rm.textContent = '×';
        right.appendChild(rm);
        li.appendChild(right);
        list.appendChild(li);
      });
      var newTotal = 'RM ' + total.toFixed(2);
      totalEl.textContent = newTotal;
      if (before !== newTotal) bump(totalEl);
      if (countMini) countMini.textContent = String(picks.length);
      if (totalMini) totalMini.textContent = newTotal;
      if (count) {
        count.textContent = String(picks.length);
        if (count.dataset.last !== String(picks.length)) bump(count);
        count.dataset.last = String(picks.length);
      }
      summary.textContent = picks.length === 0
        ? 'Select modules to pay together.'
        : (picks.length === 1 ? '1 module selected' : picks.length + ' modules selected');
      if (hint) {
        hint.textContent = picks.length === 0
          ? 'Tick “Add to payment” on one or more modules to start.'
          : (fileChosen() ? 'Receipt chosen. Upload it, or pay by Billplz.' : 'Pay by Billplz, or attach one receipt for everything selected.');
      }
      msg.textContent = '';
      setFileState();
      if (payBtn) payBtn.disabled = !(picks.length && payBtn.dataset.ready === '1');
      if (recBtn) recBtn.disabled = !(picks.length && fileChosen() && !receiptProblem());
    }
    // Un-tick a module from the basket list.
    list.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-remove]');
      if (!b) return;
      var box = document.querySelector('[data-pick="' + b.dataset.remove + '"]');
      if (box) { box.checked = false; refresh(); }
    });
    document.addEventListener('change', function (e) {
      if (e.target.matches && (e.target.matches('[data-pick]') || e.target.matches('input[name=period]'))) refresh();
    });
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var problem = receiptProblem();
        if (problem && fileChosen()) msg.textContent = problem;
        else msg.textContent = '';
        refresh();
      });
    }
    if (fileClear) {
      fileClear.addEventListener('click', function () {
        fileInput.value = '';
        msg.textContent = '';
        refresh();
        fileInput.focus();
      });
    }
    basket.addEventListener('submit', function (e) {
      basket.querySelectorAll('input[data-item]').forEach(function (n) { n.remove(); });
      var picks = picked();
      var isReceipt = e.submitter && e.submitter.hasAttribute && e.submitter.hasAttribute('data-basket-receipt');
      if (!picks.length) {
        e.preventDefault();
        msg.textContent = 'Choose at least one module.';
        return;
      }
      if (isReceipt) {
        var problem = receiptProblem();
        if (problem) {
          e.preventDefault();
          msg.textContent = problem;
          setFileState();
          return;
        }
      }
      picks.forEach(function (p) {
        var r = planOf(p);
        if (!r) return;
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'items[]';
        h.value = p.value + ':' + r.value;
        h.dataset.item = '1';
        basket.appendChild(h);
      });
    });
    // Hidden by default; the bar above always shows the count and total.
    function setBasketOpen(open) {
      basket.hidden = !open;
      if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (toggle) {
      toggle.addEventListener('click', function () { setBasketOpen(basket.hidden); });
    }
    refresh();
  }

  // ---- Module info popups ---------------------------------------------------------------------------------
  // Opens from the "i" button. Tabs switch the four sections (arrow keys too). "Add to payment" ticks the card's box.
  // While a popup is open the page behind it does not scroll; it scrolls again when the popup closes.
  var scrollLocks = 0;
  var savedOverflow = '';
  function lockScroll() {
    if (scrollLocks++ === 0) {
      savedOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      document.documentElement.classList.add('info-open');
    }
  }
  function unlockScroll() {
    if (scrollLocks > 0 && --scrollLocks === 0) {
      document.body.style.overflow = savedOverflow;
      document.documentElement.classList.remove('info-open');
    }
  }
  function showTab(dlg, key, focus) {
    Array.prototype.forEach.call(dlg.querySelectorAll('[data-info-tab]'), function (t) {
      var on = t.getAttribute('data-info-tab') === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
      if (on && focus) t.focus();
    });
    Array.prototype.forEach.call(dlg.querySelectorAll('[data-info-panel]'), function (p) {
      p.hidden = p.getAttribute('data-info-panel') !== key;
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('.bill-info-dialog'), function (dlg) {
    dlg.addEventListener('close', unlockScroll);
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) dlg.close();
      var tab = e.target.closest && e.target.closest('[data-info-tab]');
      if (tab) showTab(dlg, tab.getAttribute('data-info-tab'), false);
      if (e.target.closest && e.target.closest('[data-info-done]')) dlg.close();
      var add = e.target.closest && e.target.closest('[data-info-add]');
      if (add) {
        var box = document.querySelector('[data-pick="' + add.getAttribute('data-info-add') + '"]');
        if (box && !box.checked) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
        dlg.close();
      }
    });
    dlg.addEventListener('keydown', function (e) {
      var tab = e.target.getAttribute && e.target.getAttribute('data-info-tab');
      if (!tab) return;
      var order = ['what', 'where', 'on', 'off'];
      var i = order.indexOf(tab);
      if (e.key === 'ArrowRight') { e.preventDefault(); showTab(dlg, order[(i + 1) % order.length], true); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); showTab(dlg, order[(i + order.length - 1) % order.length], true); }
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-info-open]'), function (btn) {
    btn.addEventListener('click', function () {
      var dlg = document.getElementById(btn.getAttribute('data-info-open'));
      if (!dlg) return;
      showTab(dlg, 'what', false);
      if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
      lockScroll();
    });
  });
})();
