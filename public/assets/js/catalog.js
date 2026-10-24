// Catalogue: filters apply live (no page reload), "show more" appends the next page, the URL stays shareable,
// and on phones the filters live in a drawer. Without JavaScript the sidebar is a normal GET form.
(function () {
  'use strict';
  var D = document, root = D.querySelector('[data-catalog]');
  if (!root) return;
  var $ = function (s, r) { return (r || D).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
  var form = $('#filter-form'), results = $('#results'), endpoint = root.dataset.endpoint;
  var sort = $('select[name="sort"]'), toggle = $('.filters-toggle');
  var ctl = null, timer = 0;

  // ---- URL <-> form -------------------------------------------------------------------------------------------
  function params() {
    var p = new URLSearchParams();
    var add = function (k, v) { if (v !== '' && v != null) p.set(k, v); };
    add('q', form.elements.q.value.trim());
    add('category', (form.querySelector('input[name="category"]:checked') || {}).value || '');
    add('brand', (form.querySelector('input[name="brand"]:checked') || {}).value || '');
    add('min', form.elements.min.value.trim()); add('max', form.elements.max.value.trim());
    if (form.elements.sale.checked) p.set('sale', '1');
    if (form.elements.instock.checked) p.set('instock', '1');
    if (sort && sort.value && sort.value !== 'newest') p.set('sort', sort.value);
    return p;
  }
  function fillForm(p) {
    form.elements.q.value = p.get('q') || '';
    ['category', 'brand'].forEach(function (n) {
      var v = p.get(n) || '', r = form.querySelector('input[name="' + n + '"][value="' + v.replace(/"/g, '') + '"]') || form.querySelector('input[name="' + n + '"][value=""]');
      if (r) r.checked = true;
    });
    form.elements.min.value = p.get('min') || ''; form.elements.max.value = p.get('max') || '';
    form.elements.sale.checked = p.get('sale') === '1'; form.elements.instock.checked = p.get('instock') === '1';
    if (sort) sort.value = p.get('sort') || 'newest';
  }
  function activeCount(p) { return ['category', 'brand', 'sale', 'instock'].filter(function (k) { return p.get(k); }).length + (p.get('min') || p.get('max') ? 1 : 0); }
  function skeleton() {
    var g = D.createElement('div'); g.className = 'skeleton-grid';
    for (var i = 0; i < 8; i++) { var s = D.createElement('div'); s.className = 'skeleton'; g.appendChild(s); }
    return g;
  }

  // ---- loading results ----------------------------------------------------------------------------------------
  function load(p, push) {
    var qs = p.toString(), url = endpoint + (qs ? '?' + qs : '');
    if (ctl) ctl.abort(); ctl = window.AbortController ? new AbortController() : null;
    results.setAttribute('aria-busy', 'true');
    var q2 = new URLSearchParams(p); q2.set('partial', '1');
    fetch(endpoint + '?' + q2.toString(), { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.text(); })
      .then(function (html) {
        results.innerHTML = html; results.setAttribute('aria-busy', 'false');
        if (push) history.pushState({ qs: qs }, '', url);
        var n = activeCount(p);
        if (toggle) toggle.innerHTML = toggle.querySelector('svg').outerHTML + ' Filters' + (n ? ' <span class="s-badge inline">' + n + '</span>' : '');
        enhance(); if (window.Shop) window.Shop.reveal(results);
        var top = results.getBoundingClientRect().top + window.scrollY - 140; if (window.scrollY > top) window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
      })
      .catch(function (e) { if (e && e.name === 'AbortError') return; results.setAttribute('aria-busy', 'false'); location.href = url; }); // fall back to a normal page load
  }
  function apply() { clearTimeout(timer); load(params(), true); }

  form.addEventListener('submit', function (e) { e.preventDefault(); apply(); });
  form.addEventListener('change', function (e) { if (e.target.name === 'min' || e.target.name === 'max') return; apply(); });
  ['min', 'max'].forEach(function (n) {
    form.elements[n].addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 600); });
    form.elements[n].addEventListener('change', apply);
  });
  if (sort) sort.addEventListener('change', apply);

  // chips / "clear" links keep the page state in sync instead of navigating
  D.addEventListener('click', function (e) {
    var a = e.target.closest('[data-chip], [data-clear]'); if (!a || !root.contains(a) && !a.closest('.results')) return;
    e.preventDefault();
    var u = new URL(a.href, location.href); var p = new URLSearchParams(u.search); p.delete('page');
    fillForm(p); closeFilters(); load(params(), true);
  });
  window.addEventListener('popstate', function () { var p = new URLSearchParams(location.search); fillForm(p); load(p, false); });

  // ---- "show more" --------------------------------------------------------------------------------------------
  function enhance() {
    var more = $('[data-load-more]', results);
    if (!more) return;
    more.hidden = false; // JS is here: the page links are replaced by the button
    more.addEventListener('click', function () {
      var p = params(); p.set('page', more.dataset.next); p.set('partial', 'cards');
      more.classList.add('loading');
      fetch(endpoint + '?' + p.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('http'); return r.text(); })
        .then(function (html) {
          var t = D.createElement('template'); t.innerHTML = html;
          var marker = t.content.querySelector('[data-next]'), next = marker ? Number(marker.dataset.next) : 0; if (marker) marker.remove();
          var grid = $('#pgrid', results), first = t.content.firstElementChild;
          grid.appendChild(t.content);
          if (first && first.querySelector('a')) first.querySelector('a').focus({ preventScroll: true });
          more.classList.remove('loading');
          if (next) more.dataset.next = String(next); else more.remove();
          if (window.Shop) window.Shop.reveal(grid);
        })
        .catch(function () { more.classList.remove('loading'); if (window.Shop) window.Shop.toast('Could not load more products. Please try again.', 'error'); });
    });
  }
  enhance();

  // ---- phone filter drawer ------------------------------------------------------------------------------------
  function openFilters() { D.body.classList.add('filters-open'); var f = $('#filters input, #filters button'); if (f) f.focus(); }
  function closeFilters() { D.body.classList.remove('filters-open'); }
  D.addEventListener('click', function (e) {
    if (e.target.closest('[data-filters-open]')) openFilters();
    else if (e.target.closest('[data-filters-close], [data-filters-backdrop]')) closeFilters();
  });
  D.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeFilters(); });
})();
