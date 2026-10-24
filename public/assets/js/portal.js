// Portal shell behaviour: theme toggle, collapsible sidebar (desktop), slide-in drawer (mobile).
(function () {
  var root = document.documentElement, body = document.body;
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function stored(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  // Theme
  var themeBtn = document.querySelector('[data-theme-toggle]');
  function applyTheme(t, persist) {
    root.dataset.theme = t;
    if (persist) store('theme', t);
    if (themeBtn) {
      themeBtn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
      themeBtn.setAttribute('title', t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
  }
  applyTheme(root.dataset.theme === 'dark' ? 'dark' : 'light', false);
  if (themeBtn) themeBtn.addEventListener('click', function () { applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true); });
  if (window.matchMedia) {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      if (!stored('theme')) applyTheme(e.matches ? 'dark' : 'light', false); // follow the OS until the user picks
    });
  }

  // Desktop: collapse to an icon rail
  var collapse = document.querySelector('[data-sidebar-collapse]');
  if (collapse) collapse.addEventListener('click', function () {
    var on = root.dataset.sidebar !== 'collapsed';
    if (on) root.dataset.sidebar = 'collapsed'; else delete root.dataset.sidebar;
    store('sidebar', on ? 'collapsed' : 'open');
    collapse.setAttribute('aria-expanded', on ? 'false' : 'true');
  });

  // Sub-menus (Orders by status). The server opens it only inside the Orders section; the button toggles it for this page
  // only. Nothing is remembered, so it never stays open on pages that have nothing to do with orders.
  try { localStorage.removeItem('nav-sub'); } catch (e) { /* older versions remembered this */ }
  Array.prototype.forEach.call(document.querySelectorAll('.nav-item.has-sub'), function (item) {
    var btn = item.querySelector('.nav-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (root.dataset.sidebar === 'collapsed') { // icon rail: the sub-menu is hidden there, so widen the sidebar and show it
        delete root.dataset.sidebar; store('sidebar', 'open');
        var cb = document.querySelector('[data-sidebar-collapse]'); if (cb) cb.setAttribute('aria-expanded', 'true');
        item.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
        return;
      }
      var open = !item.classList.contains('open');
      item.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
    });
  });

  // Mobile: drawer
  var openBtn = document.querySelector('[data-nav-open]'), side = document.getElementById('sidebar');
  function setDrawer(open) {
    body.classList.toggle('nav-open', open);
    if (openBtn) openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && side) { var first = side.querySelector('a'); if (first) first.focus(); }
    if (!open && openBtn && document.activeElement && side && side.contains(document.activeElement)) openBtn.focus();
  }
  if (openBtn) openBtn.addEventListener('click', function () { setDrawer(!body.classList.contains('nav-open')); });
  var backdrop = document.querySelector('.backdrop');
  if (backdrop) backdrop.addEventListener('click', function () { setDrawer(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && body.classList.contains('nav-open')) setDrawer(false); });
  if (side) side.addEventListener('click', function (e) { if (e.target.closest('a')) setDrawer(false); });
  if (window.matchMedia) matchMedia('(min-width:901px)').addEventListener('change', function (e) { if (e.matches) setDrawer(false); });

  // ---- toasts: flash messages become non-blocking toasts (the server markup stays as the no-JS fallback) ----
  var Portal = (window.Portal = {});
  var box = document.createElement('div');
  box.className = 'toasts'; box.setAttribute('aria-live', 'polite'); box.setAttribute('role', 'region'); box.setAttribute('aria-label', 'Notifications');
  document.body.appendChild(box);
  Portal.toast = function (message, type, opts) {
    opts = opts || {}; type = type || 'info';
    var t = document.createElement('div');
    t.className = 'toast ' + type; t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var m = document.createElement('span'); m.className = 'toast-msg'; m.textContent = message; t.appendChild(m);
    if (opts.href) { var a = document.createElement('a'); a.href = opts.href; a.textContent = opts.label || 'View'; t.appendChild(a); }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'toast-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    function close() { t.classList.add('out'); setTimeout(function () { t.remove(); }, 200); }
    x.addEventListener('click', close); t.appendChild(x);
    box.appendChild(t);
    while (box.children.length > 4) box.firstChild.remove();
    if (type !== 'error') setTimeout(close, opts.ms || 5500);
    return t;
  };
  Array.prototype.forEach.call(document.querySelectorAll('.content > .alert'), function (a) {
    Portal.toast(a.textContent.trim(), a.classList.contains('bad') ? 'error' : 'success');
    a.remove();
  });

  // ---- command palette: Ctrl/Cmd+K or "/" ----------------------------------------------------------------
  var pal = null, prevFocus = null, active = 0, shown = [], seq = 0, timer = 0;
  function pageItems() {
    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('#sidebar .nav-group'), function (g) {
      var group = (g.querySelector('.nav-title') || {}).textContent || '';
      Array.prototype.forEach.call(g.querySelectorAll('a'), function (a) {
        out.push({ group: 'Go to', label: a.dataset.paletteLabel || a.querySelector('.nav-label').textContent.trim(), sub: group, href: a.getAttribute('href') });
      });
    });
    var prod = document.querySelector('#sidebar .nav a[href$="/products"]');
    if (prod) out.push({ group: 'Actions', label: 'Add a product', sub: 'Catalog', href: prod.getAttribute('href') + '/create' });
    out.push({ group: 'Actions', label: 'Toggle dark mode', sub: 'Appearance', run: function () { var b = document.querySelector('[data-theme-toggle]'); if (b) b.click(); } });
    out.push({ group: 'Actions', label: 'View the shop', sub: 'Opens the storefront', href: (document.querySelector('.topbar a[title="View shop"]') || {}).href });
    return out.filter(function (i) { return i.href !== undefined || i.run; });
  }
  function score(q, it) {
    var hay = (it.label + ' ' + (it.sub || '')).toLowerCase(), l = it.label.toLowerCase(), pts = 0;
    var parts = q.toLowerCase().split(/\s+/).filter(Boolean);
    for (var i = 0; i < parts.length; i++) {
      if (hay.indexOf(parts[i]) < 0) return -1;
      pts += l.indexOf(parts[i]) === 0 ? 3 : l.indexOf(parts[i]) > 0 ? 2 : 1;
    }
    return pts;
  }
  function build() {
    pal = document.createElement('div'); pal.className = 'palette'; pal.hidden = true;
    pal.innerHTML = '<div class="palette-bg"></div><div class="palette-box" role="dialog" aria-modal="true" aria-label="Search and commands"><input type="text" class="palette-input" placeholder="Search pages, orders, products…" autocomplete="off" spellcheck="false" aria-label="Search" role="combobox" aria-expanded="true" aria-controls="palette-list"><ul class="palette-list" id="palette-list" role="listbox"></ul><div class="palette-foot"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div></div>';
    document.body.appendChild(pal);
    pal.querySelector('.palette-bg').addEventListener('click', closePal);
    var input = pal.querySelector('input');
    input.addEventListener('input', function () { update(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { active = Math.min(shown.length - 1, active + 1); mark(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); mark(); e.preventDefault(); }
      else if (e.key === 'Enter') { choose(shown[active]); e.preventDefault(); }
      else if (e.key === 'Escape') { closePal(); e.preventDefault(); }
      else if (e.key === 'Tab') e.preventDefault();
    });
  }
  function mark() {
    var lis = pal.querySelectorAll('li[role=option]');
    Array.prototype.forEach.call(lis, function (li, i) { li.setAttribute('aria-selected', String(i === active)); if (i === active) li.scrollIntoView({ block: 'nearest' }); });
  }
  function choose(it) { if (!it) return; closePal(); if (it.run) it.run(); else location.href = it.href; }
  function draw(list) {
    shown = list; active = 0;
    var ul = pal.querySelector('.palette-list'); ul.textContent = '';
    if (!list.length) { var e = document.createElement('li'); e.className = 'palette-empty'; e.textContent = 'No matches.'; ul.appendChild(e); return; }
    var last = '';
    list.forEach(function (it, i) {
      if (it.group !== last) { last = it.group; var g = document.createElement('li'); g.className = 'palette-group'; g.setAttribute('role', 'presentation'); g.textContent = it.group; ul.appendChild(g); }
      var li = document.createElement('li'); li.setAttribute('role', 'option');
      var a = document.createElement('span'); a.className = 'p-label'; a.textContent = it.label; li.appendChild(a);
      if (it.sub) { var b = document.createElement('small'); b.className = 'muted'; b.textContent = it.sub; li.appendChild(b); }
      li.addEventListener('click', function () { choose(it); });
      li.addEventListener('pointermove', function () { if (active !== i) { active = i; mark(); } });
      ul.appendChild(li);
    });
    mark();
  }
  function update(q) {
    q = q.trim();
    var local = pageItems().map(function (i) { return { i: i, s: q ? score(q, i) : 0 }; }).filter(function (x) { return x.s >= 0; })
      .sort(function (a, b) { return b.s - a.s; }).map(function (x) { return x.i; }).slice(0, q ? 6 : 12);
    draw(local);
    var url = document.body.dataset.search;
    clearTimeout(timer);
    if (!url || q.length < 2) return;
    var mine = ++seq;
    timer = setTimeout(function () {
      fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          if (!res || mine !== seq || pal.hidden) return;
          var extra = [];
          (res.orders || []).forEach(function (o) { extra.push({ group: 'Orders', label: o.label, sub: o.sub, href: o.href }); });
          (res.products || []).forEach(function (o) { extra.push({ group: 'Products', label: o.label, sub: o.sub, href: o.href }); });
          draw(extra.concat(local));
        }).catch(function () {});
    }, 180);
  }
  function openPal() {
    if (!pal) build();
    prevFocus = document.activeElement; pal.hidden = false; document.body.classList.add('palette-open');
    var input = pal.querySelector('input'); input.value = ''; update(''); input.focus();
  }
  function closePal() {
    if (!pal || pal.hidden) return;
    pal.hidden = true; document.body.classList.remove('palette-open');
    if (prevFocus && prevFocus.focus) prevFocus.focus();
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-palette-open]'), function (b) { b.addEventListener('click', openPal); });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal && !pal.hidden ? closePal() : openPal(); }
    else if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey) { e.preventDefault(); openPal(); }
  });
})();
