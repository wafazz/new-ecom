// Flash sales: countdown timers (storefront, product page) and the live preview in the admin editor.
(function () {
  'use strict';
  var D = document;

  // ---- countdown ----------------------------------------------------------------------------------------------
  // The clock is the server's: each timer records how far this browser's clock is from the server's (data-now) once,
  // so a wrong phone clock does not change the countdown.
  function fmt(s) {
    s = Math.max(0, s);
    var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var clock = pad(h) + ':' + pad(m) + ':' + pad(x);
    return d > 0 ? d + 'd ' + clock : clock;
  }

  function tick() {
    var timers = D.querySelectorAll('[data-countdown]');
    for (var i = 0; i < timers.length; i++) {
      var el = timers[i];
      var ends = Date.parse(el.getAttribute('data-ends'));
      if (isNaN(ends)) continue;
      if (el.__offset === undefined) {
        var serverNow = Date.parse(el.getAttribute('data-now'));
        el.__offset = isNaN(serverNow) ? 0 : serverNow - Date.now();
      }
      var left = Math.floor((ends - (Date.now() + el.__offset)) / 1000);
      var out = el.querySelector('[data-cd-text]');
      if (left <= 0) {
        if (out) out.textContent = 'Ended';
        // The price on the page is out of date now: reload once, unless this is the admin preview.
        if (!el.__ended) {
          el.__ended = true;
          if (!el.closest('[data-flash-block-preview]')) setTimeout(function () { window.location.reload(); }, 1500);
        }
        continue;
      }
      if (out) out.textContent = fmt(left);
    }
  }
  if (D.querySelector('[data-countdown]')) {
    tick();
    setInterval(tick, 1000);
  }

  // ---- admin editor -------------------------------------------------------------------------------------------
  var editor = D.querySelector('[data-flash-editor]');
  // ---- product carousel ---------------------------------------------------------------------------------------
  // Each arrow scrolls one screenful (the track's visible width): 4 cards on desktop, 2 columns x 2 rows on mobile.
  var carousels = D.querySelectorAll('[data-flash-carousel]');
  for (var c = 0; c < carousels.length; c++) (function (box) {
    var track = box.querySelector('[data-flash-track]');
    var buttons = box.querySelectorAll('[data-flash-step]');
    function update() {
      var max = track.scrollWidth - track.clientWidth;
      box.classList.toggle('no-overflow', max <= 2);
      for (var b = 0; b < buttons.length; b++) {
        var dir = Number(buttons[b].getAttribute('data-flash-step'));
        buttons[b].disabled = dir < 0 ? track.scrollLeft <= 2 : track.scrollLeft >= max - 2;
      }
    }
    for (var b = 0; b < buttons.length; b++) {
      buttons[b].addEventListener('click', function (e) {
        var dir = Number(e.currentTarget.getAttribute('data-flash-step'));
        track.scrollBy({ left: dir * track.clientWidth, behavior: 'smooth' });
      });
    }
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();

    // Auto-scroll: one screen every few seconds, back to the start at the end. Paused while the pointer or a finger is on it.
    var paused = false;
    box.addEventListener('mouseenter', function () { paused = true; });
    box.addEventListener('mouseleave', function () { paused = false; });
    box.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    box.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 2500); });
    setInterval(function () {
      if (paused || document.hidden) return;
      var max = track.scrollWidth - track.clientWidth;
      if (max <= 2) return; // nothing to scroll
      if (track.scrollLeft >= max - 2) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
      } else {
        track.scrollBy({ left: track.clientWidth, behavior: 'smooth' });
      }
    }, 4500);
  })(carousels[c]);

  if (!editor) return;

  var preview = editor.querySelector('[data-flash-preview]');
  var state = editor.querySelector('[data-flash-preview-state]');
  var url = editor.getAttribute('data-preview-url');
  var timer = null, seq = 0;

  function refresh() {
    var mine = ++seq;
    if (state) state.textContent = 'Updating…';
    fetch(url, { method: 'POST', body: new FormData(editor), credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(function (html) {
        if (mine !== seq) return; // a newer change is on its way
        preview.srcdoc = html; // a standalone storefront page, so its styles stay out of the admin
        if (state) state.textContent = 'Up to date';
      })
      .catch(function () {
        if (mine !== seq) return;
        if (state) state.textContent = 'Could not refresh the preview';
      });
  }
  function queue() {
    clearTimeout(timer);
    timer = setTimeout(refresh, 350);
  }

  editor.addEventListener('input', function (e) {
    // Typing a percentage ticks its product, so the preview shows it straight away.
    var pct = e.target.closest && e.target.closest('input[name$="[percent]"]');
    if (pct && pct.value.trim() !== '') {
      var row = pct.closest('[data-flash-row]');
      var box = row && row.querySelector('[data-flash-pick]');
      if (box) box.checked = true;
    }
    queue();
  });
  editor.addEventListener('change', queue);

  // Product search: hides rows that do not match.
  var search = editor.querySelector('[data-flash-search]');
  if (search) {
    search.addEventListener('input', function () {
      var term = search.value.trim().toLowerCase();
      var rows = editor.querySelectorAll('[data-flash-row]');
      for (var i = 0; i < rows.length; i++) {
        rows[i].hidden = term !== '' && (rows[i].getAttribute('data-name') || '').indexOf(term) === -1;
      }
    });
  }

  queue(); // show the preview for what is already saved
})();
