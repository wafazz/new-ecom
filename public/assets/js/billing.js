// Admin > Module billing: a live countdown to the end of each paid period, and the chosen receipt's file name.
// Everything works without JavaScript; the countdown just shows its end date until the script runs.
(function () {
  'use strict';

  // The end time comes from the server as a timestamp, so the countdown is right in any timezone.
  var timers = Array.prototype.slice.call(document.querySelectorAll('.bill-count[data-end-ms]'));
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function paint(box) {
    var end = Number(box.dataset.endMs);
    var left = end - Date.now();
    var value = box.querySelector('[data-count]');
    var label = box.querySelector('.bill-count-label');
    box.classList.remove('is-soon', 'is-ended');
    if (left <= 0) {
      box.classList.add('is-ended');
      label.textContent = 'Ended';
      value.textContent = 'Pay to renew';
      return false;
    }
    var s = Math.floor(left / 1000);
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    label.textContent = 'Time left';
    value.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(sec);
    if (left < 7 * 86400000) { box.classList.add('is-soon'); }
    return true;
  }
  function tick() {
    timers.forEach(function (box) {
      if (!paint(box)) { box.dataset.done = '1'; }
    });
  }
  if (timers.length) {
    tick();
    var handle = setInterval(function () {
      tick();
      if (timers.every(function (b) { return b.dataset.done === '1'; })) { clearInterval(handle); }
    }, 1000);
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
})();
