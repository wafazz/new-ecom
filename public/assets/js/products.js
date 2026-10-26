// Products page: live search/filter, inline status, bulk actions, and the add/edit popup. (Stock is changed in Stock Control, not here.)
// Everything degrades to the normal full-page forms without JavaScript.
(function () {
  'use strict';
  var res = document.getElementById('prod-results');
  if (!res) return;
  var modal = document.getElementById('product-modal');
  var form = document.getElementById('prod-filters');
  var base = res.dataset.base, endpoint = res.dataset.endpoint, isAdmin = res.dataset.admin === '1';
  var toast = function (m, t, o) { if (window.Portal) window.Portal.toast(m, t, o); };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /** SweetAlert confirm (confirm.js); falls back to the browser dialog if it failed to load. */
  function ask(o) { return window.Confirm ? window.Confirm.ask(o) : Promise.resolve(confirm(o.title + (o.text ? '\n' + o.text : ''))); }

  function token() { var i = $('input[name="_token"]'); return i ? i.value : ''; }

  /** What to tell the admin when the server answered with something other than the expected JSON. */
  function explainBadResponse(status) {
    if (status === 413) return 'The photos are too big for the server. Use smaller photos (under 2 MB each), or ask your host to raise the upload limit (nginx client_max_body_size, PHP post_max_size).';
    if (status === 419) return 'Your session expired. Reload the page and try again.';
    if (status === 504 || status === 524) return 'The server took too long to save the photos. Try again with fewer or smaller photos.';
    if (status >= 500) return 'The server had a problem saving this (error ' + status + '). Try again; if it keeps happening, tell the developer.';
    return 'The server answered in a way the page did not expect (status ' + status + '). Reload and try again.';
  }

  /** POST via fetch; always resolves to {ok, status, data}. */
  function post(url, body) {
    var fd = body instanceof FormData ? body : (function () { var f = new FormData(); Object.keys(body || {}).forEach(function (k) { [].concat(body[k]).forEach(function (v) { f.append(Array.isArray(body[k]) ? k + '[]' : k, v); }); }); return f; })();
    if (!fd.has('_token')) fd.append('_token', token());
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, message: explainBadResponse(r.status) }; }).then(function (d) {
          if (r.status === 401 || (d && d.login)) { toast('Your session expired. Reloading…', 'error'); setTimeout(function () { location.reload(); }, 1200); }
          return { ok: r.ok && d.ok !== false, status: r.status, data: d };
        });
      })
      .catch(function () { return { ok: false, status: 0, data: { message: 'Network problem. Check your connection and try again.' } }; });
  }

  // ---- list state ----------------------------------------------------------------------------
  var state = { page: Number(new URLSearchParams(location.search).get('page')) || 1 };
  function params(extra) {
    var p = new URLSearchParams();
    ['q', 'category', 'brand', 'sort', 'status', 'stock'].forEach(function (k) { var v = form.elements[k] ? form.elements[k].value.trim() : ''; if (v && v !== 'newest') p.set(k, v); });
    var page = extra && extra.page != null ? extra.page : state.page;
    if (page > 1) p.set('page', page);
    return p;
  }
  var ctl = null;
  function load(opts) {
    opts = opts || {};
    if (ctl) ctl.abort();
    ctl = new AbortController();
    res.classList.add('is-loading');
    var p = params(); var qs = p.toString();
    var p2 = new URLSearchParams(p); p2.set('partial', '1');
    return fetch(endpoint + '?' + p2.toString(), { credentials: 'same-origin', signal: ctl.signal })
      .then(function (r) { if (r.redirected || !r.ok) throw new Error('bad'); return r.text(); })
      .then(function (html) {
        res.innerHTML = html;
        history.replaceState(null, '', endpoint + (qs ? '?' + qs : ''));
        updateBulk();
      })
      .catch(function (e) { if (e.name !== 'AbortError') toast('Could not refresh the list.', 'error'); })
      .then(function () { res.classList.remove('is-loading'); });
  }
  /** Refresh just the filter chips (counts) without touching the rows being edited. */
  var chipTimer;
  function refreshChips() {
    clearTimeout(chipTimer);
    chipTimer = setTimeout(function () {
      var p = params(); p.set('partial', '1');
      fetch(endpoint + '?' + p.toString(), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
        var t = document.createElement('div'); t.innerHTML = html;
        var fresh = $('.prod-chips', t), cur = $('.prod-chips', res);
        if (fresh && cur) cur.innerHTML = fresh.innerHTML;
      }).catch(function () {});
    }, 400);
  }

  if (form) {
    var btn = $('#prod-filter-btn'); if (btn) btn.hidden = true;
    form.addEventListener('submit', function (e) { e.preventDefault(); state.page = 1; load(); });
    var t;
    form.elements.q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { state.page = 1; load(); }, 250); });
    ['category', 'brand', 'sort'].forEach(function (n) { form.elements[n].addEventListener('change', function () { state.page = 1; load(); }); });
  }

  // ---- clicks on the list (delegated: the fragment is re-rendered) ----------------------------------------
  res.addEventListener('click', function (e) {
    var chip = e.target.closest('.prod-chips a');
    if (chip) {
      e.preventDefault();
      var set = JSON.parse(chip.dataset.set); Object.keys(set).forEach(function (k) { form.elements[k].value = set[k]; });
      state.page = 1; load(); return;
    }
    var pg = e.target.closest('a[data-page]'); if (pg) { e.preventDefault(); state.page = Number(pg.dataset.page); load(); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    var reset = e.target.closest('[data-reset]'); if (reset) { e.preventDefault(); form.reset(); ['q', 'status', 'stock'].forEach(function (k) { form.elements[k].value = ''; }); form.elements.category.value = ''; form.elements.brand.value = ''; form.elements.sort.value = 'newest'; state.page = 1; load(); return; }
    var sw = e.target.closest('.sw'); if (sw) { toggleStatus(sw); return; }
    var bulk = e.target.closest('[data-bulk]'); if (bulk) { runBulk(bulk.dataset.bulk); return; }
    if (e.target.closest('[data-bulk-clear]')) { $$('.row-check', res).forEach(function (c) { c.checked = false; }); var sa = $('#select-all', res); if (sa) sa.checked = false; updateBulk(); return; }
    // clicking an empty part of a row opens it
    var tr = e.target.closest('tr[data-id]');
    if (tr && !e.target.closest('input,button,a,.sw,.chk,.act,label')) openModal(tr.dataset.id);
  });
  res.addEventListener('change', function (e) {
    if (e.target.id === 'select-all') { $$('.row-check', res).forEach(function (c) { c.checked = e.target.checked; }); updateBulk(); }
    else if (e.target.classList.contains('row-check')) updateBulk();
  });

  // Any "open product" link (list rows, header button, empty state) opens the popup; modified clicks keep the normal link behaviour.
  document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-open-product]');
    if (!open || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault(); openModal(open.dataset.openProduct);
  });

  // ---- inline status ---------------------------------------------------------------------------------
  function toggleStatus(sw) {
    var tr = sw.closest('tr'), now = sw.getAttribute('aria-checked') === 'true', next = now ? 'draft' : 'active';
    sw.setAttribute('aria-checked', String(!now)); $('.sw-label', tr).textContent = next; tr.classList.toggle('is-draft', next === 'draft'); sw.disabled = true;
    post(base + '/products/' + tr.dataset.id + '/quick', { field: 'status', value: next }).then(function (r) {
      sw.disabled = false;
      if (r.ok) { toast((tr.dataset.name || 'Product') + (next === 'active' ? ' is now visible in the shop.' : ' is hidden from the shop (draft).'), 'success'); refreshChips(); }
      else { sw.setAttribute('aria-checked', String(now)); $('.sw-label', tr).textContent = now ? 'active' : 'draft'; tr.classList.toggle('is-draft', !now); toast(r.data.message || 'Could not change the status.', 'error'); }
    });
  }

  // ---- bulk ------------------------------------------------------------------------------------------
  function selected() { return $$('.row-check:checked', res).map(function (c) { return c.value; }); }
  function updateBulk() {
    var n = selected().length, bar = $('#bulk-bar', res); if (!bar) return;
    bar.hidden = n === 0; $('#bulk-count', res).textContent = n;
    var sa = $('#select-all', res), all = $$('.row-check', res); if (sa) { sa.checked = n > 0 && n === all.length; sa.indeterminate = n > 0 && n < all.length; }
    $$('.row-check', res).forEach(function (c) { c.closest('tr').classList.toggle('is-selected', c.checked); });
  }
  function runBulk(action) {
    var ids = selected(); if (!ids.length) return;
    var go = function () {
      post(base + '/products/bulk', { action: action, ids: ids }).then(function (r) {
        toast(r.data.message || (r.ok ? 'Done.' : 'Could not complete that.'), r.ok ? 'success' : 'error');
        if (r.ok) load();
      });
    };
    if (action !== 'delete') return go();
    ask({ title: 'Delete ' + ids.length + ' product(s)?', text: 'They disappear from the shop; past orders keep their details.', button: 'Yes, delete', danger: true }).then(function (ok) { if (ok) go(); });
  }

  // ---- modal -----------------------------------------------------------------------------------------
  var dirtySnap = '', current = null;
  function snap() { var f = $('#product-form', modal); return f ? new URLSearchParams(new FormData(f)).toString() : ''; }
  function isDirty() { return !!$('#product-form', modal) && snap() !== dirtySnap; }
  function loading() { modal.innerHTML = '<div class="modal-inner"><div class="modal-loading">Loading…</div></div>'; }
  function openModal(target, tab) {
    var url = base + (target === 'new' ? '/products/create' : '/products/' + encodeURIComponent(target) + '/edit') + '?modal=1';
    current = target;
    if (!modal.open) { loading(); modal.showModal(); document.body.classList.add('modal-open'); }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('bad'); return r.text(); })
      .then(function (html) { modal.innerHTML = html; if (window.Variations) window.Variations.init(modal); if (window.DigitalProduct) window.DigitalProduct.init(modal); if (window.ProductImages) window.ProductImages.init(modal); if (window.RichText) window.RichText.init(modal); setup(tab); })
      .catch(function () { closeModal(true); toast('Could not open the product.', 'error'); });
  }
  function closeModal(force) {
    if (!force && isDirty()) {
      ask({ title: 'Discard unsaved changes?', text: 'What you typed will be lost.', button: 'Yes, discard', danger: true }).then(function (ok) { if (ok) closeModal(true); });
      return;
    }
    if (modal.open) modal.close();
    document.body.classList.remove('modal-open'); modal.innerHTML = '';
  }
  modal.addEventListener('cancel', function (e) { e.preventDefault(); closeModal(); });
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
    else if (e.target.closest('[data-modal-close]')) closeModal();
    var tab = e.target.closest('.modal-tabs [data-tab]'); if (tab) showTab(tab.dataset.tab);
  });

  function showTab(name) {
    $$('.modal-tabs [data-tab]', modal).forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === name)); });
    $$('.tab-panel', modal).forEach(function (p) { p.hidden = p.dataset.panel !== name; });
    var foot = $('[data-foot]', modal); if (foot) foot.hidden = name !== 'details';
    var body = $('.modal-body', modal); if (body) body.scrollTop = 0;
    modal.dataset.tab = name;
  }
  function setup(tab) {
    showTab(tab || 'details');
    dirtySnap = snap();
    var f = $('#product-form', modal);
    if (f) { f.addEventListener('input', margin); margin(); }
    if (!tab || tab === 'details') { var first = $('input[name="name"]', modal); if (first && !first.value) first.focus(); }
  }

  function clearErrors() { $$('.error.js', modal).forEach(function (n) { n.remove(); }); var a = $('#modal-alert', modal); if (a) { a.hidden = true; a.textContent = ''; } }
  function showErrors(frm, data) {
    clearErrors();
    var first = null;
    var orphans = [];
    Object.keys(data.errors || {}).forEach(function (k) {
      if (k === 'form') return;
      var el = frm.elements[k]; if (!el) { orphans.push(data.errors[k]); return; } // e.g. the images list has no single input
      var small = document.createElement('small'); small.className = 'error js'; small.textContent = data.errors[k];
      if (el.closest('.var-row')) el.classList.add('err');
      (el.closest('label') || el.parentNode).appendChild(small);
      if (!first) first = el;
    });
    var a = $('#modal-alert', modal), general = (data.errors && data.errors.form) || orphans.join(' ') || (!first ? data.message : '');
    if (a && general) { a.textContent = general; a.hidden = false; a.scrollIntoView({ block: 'nearest' }); }
    if (first) { first.focus(); }
    else if (a && general) a.focus && a.focus();
  }

  modal.addEventListener('submit', function (e) {
    var frm = e.target; if (frm.tagName !== 'FORM') return;
    e.preventDefault();
    if (frm.dataset.confirm && !frm.dataset.confirmed) {
      ask({ title: frm.dataset.confirm, button: frm.dataset.confirmButton, danger: !!frm.dataset.confirmDanger }).then(function (ok) {
        if (!ok) return;
        frm.dataset.confirmed = '1'; frm.requestSubmit(); delete frm.dataset.confirmed;
      });
      return;
    }
    var fd = new FormData(frm);
    if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
    var btns = $$('button[type=submit],button:not([type])', modal), tab = modal.dataset.tab || 'details';
    var foot = $$('[form="product-form"]', modal);
    btns = btns.concat(foot); btns.forEach(function (b) { b.disabled = true; });
    var label = e.submitter && e.submitter.textContent; if (e.submitter) e.submitter.textContent = 'Saving…';
    clearErrors();
    post(frm.getAttribute('action'), fd).then(function (r) {
      btns.forEach(function (b) { b.disabled = false; }); if (e.submitter && label) e.submitter.textContent = label;
      if (!r.ok) { showErrors(frm, r.data); return; }
      toast(r.data.message || 'Saved.', 'success');
      var m = (r.data.redirect || '').match(/\/products\/(\d+)\/edit/);
      if (frm.id === 'product-form') { dirtySnap = snap(); }
      if (/\/delete$/.test(frm.getAttribute('action'))) { closeModal(true); load(); return; }
      load();
      if (m) openModal(m[1], frm.id === 'product-form' ? 'details' : tab);
      else openModal(current, tab);
    });
  });

  // Editing a field clears its own server error straight away.
  modal.addEventListener('input', function (e) { var l = e.target.closest && (e.target.closest('label') || e.target.closest('.var-row')); if (e.target.classList) e.target.classList.remove('err'); if (l) $$('.error.js', l).forEach(function (n) { n.remove(); }); });
  modal.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's' && (modal.dataset.tab || 'details') === 'details') { var f = $('#product-form', modal); if (f) { e.preventDefault(); f.requestSubmit(); } }
  });

  // ---- live margin / price sanity check ----------------------------------------------------------------------
  function num(f, n) { var el = f.elements[n]; if (!el || el.value.trim() === '') return null; return /^\d{1,8}(\.\d{1,2})?$/.test(el.value.trim()) ? parseFloat(el.value) : NaN; }
  function margin() {
    var f = $('#product-form', modal), out = $('#margin-preview', modal); if (!f || !out) return;
    var base_ = isAdmin ? num(f, 'base_price') : null, comp = num(f, 'company_base_price'), retail = num(f, 'retail_price'), disc = num(f, 'discount_price');
    var msgs = [], warn = false, fmt = function (v) { return (document.documentElement.dataset.currency || 'MYR') + ' ' + v.toFixed(2); };
    [base_, comp, retail, disc].forEach(function (v) { if (Number.isNaN(v)) { warn = true; } });
    if (warn) { out.className = 'margin-preview warn'; out.textContent = 'Prices must be amounts like 12.50.'; return; }
    if (retail === null || comp === null) { out.className = 'margin-preview'; out.textContent = ''; return; }
    var sell = disc !== null && disc <= retail ? disc : retail;
    if (retail < comp) msgs.push('Retail is below the company base price.');
    if (base_ !== null && comp < base_) msgs.push('Company base is below your cost.');
    if (disc !== null && disc > retail) msgs.push('Discount is above retail.');
    var m = sell - comp, pct = sell > 0 ? Math.round((m / sell) * 100) : 0;
    out.className = 'margin-preview' + (msgs.length ? ' warn' : '');
    out.textContent = msgs.length ? msgs.join(' ') : 'Sells at ' + fmt(sell) + ' · ' + fmt(m) + ' (' + pct + '%) above the company base' + (base_ !== null ? ' · ' + fmt(sell - base_) + ' above your cost' : '') + '.';
  }

  // "n" opens a new product when nothing is being typed
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (e.key === 'n' && !e.metaKey && !e.ctrlKey && !e.altKey && !modal.open && tag !== 'input' && tag !== 'textarea' && tag !== 'select' && !e.target.isContentEditable && !document.body.classList.contains('palette-open')) { e.preventDefault(); openModal('new'); }
  });
  var addBtn = $('.row-between a[data-open-product]'); if (addBtn) addBtn.title = 'Press N';
  updateBulk();
})();
