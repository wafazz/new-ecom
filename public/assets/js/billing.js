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
  function setCell(num, text) {
    if (num.textContent === text) return;
    num.textContent = text;
    num.classList.remove('is-turn');
    void num.offsetWidth; // restart the flip animation
    num.classList.add('is-turn');
  }
  function paintClock(c) {
    var left = Number(c.dataset.endMs) - Date.now();
    c.classList.remove('is-soon', 'is-ended');
    if (left <= 0) {
      c._cells.forEach(function (n) { setCell(n, '00'); });
      c.classList.add('is-ended');
      if (c._end) c._end.textContent = 'Ended · pay to renew';
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

  Array.prototype.forEach.call(document.querySelectorAll('[data-file-input]'), function (input) {
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
    var msg = basket.querySelector('[data-basket-msg]');
    var payBtn = basket.querySelector('[data-basket-pay]');
    var recBtn = basket.querySelector('[data-basket-receipt]');
    var cardOf = function (el) { return el.closest('.bill-card'); };
    var picked = function () {
      return Array.prototype.filter.call(document.querySelectorAll('[data-pick]'), function (c) { return c.checked; });
    };
    var planOf = function (pick) {
      var r = cardOf(pick).querySelector('input[name=period]:checked');
      return r || null;
    };
    function refresh() {
      var picks = picked();
      var total = 0;
      picks.forEach(function (p) { var r = planOf(p); if (r) total += Number(r.dataset.final || 0); });
      summary.textContent = picks.length
        ? picks.length + ' selected · RM ' + total.toFixed(2) + ' in total'
        : 'Select modules to pay together.';
      msg.textContent = '';
      if (payBtn) payBtn.disabled = !(picks.length && payBtn.dataset.ready === '1');
      if (recBtn) recBtn.disabled = !picks.length;
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches && (e.target.matches('[data-pick]') || e.target.matches('input[name=period]'))) refresh();
    });
    basket.addEventListener('submit', function (e) {
      basket.querySelectorAll('input[data-item]').forEach(function (n) { n.remove(); });
      var picks = picked();
      if (!picks.length) {
        e.preventDefault();
        msg.textContent = 'Choose at least one module.';
        return;
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
    refresh();
  }
})();
