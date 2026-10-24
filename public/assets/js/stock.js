// Stock Control Management: the Adjust popup (add / remove / set), posted as JSON. The server re-checks everything.
(function () {
  'use strict';
  var root = document.getElementById('stock-root');
  var filters = document.getElementById('stock-filters');
  if (filters) { // live filtering: selects submit at once, search after a short pause
    var t;
    [].forEach.call(filters.querySelectorAll('select'), function (s) { s.addEventListener('change', function () { filters.submit(); }); });
    var q = filters.elements.q; if (q) q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { filters.submit(); }, 500); });
    if (q && q.value) { var l = q.value.length; q.focus(); q.setSelectionRange(l, l); }
  }
  // Products with variations: the main row toggles its variation rows.
  if (root) {
    var tools = document.querySelector('[data-stk-tools]');
    var groups = [].slice.call(root.querySelectorAll('.stk-group.has-variations'));
    var setOpen = function (g, open) {
      g.classList.toggle('open', open);
      var b = g.querySelector('.stk-chev'); if (b) b.setAttribute('aria-expanded', String(open));
      [].forEach.call(g.querySelectorAll('.stk-child'), function (r) { r.hidden = !open; });
    };
    if (tools && groups.length) tools.hidden = false;
    root.addEventListener('click', function (e) {
      if (e.target.closest('a, [data-adjust]')) return;
      var main = e.target.closest('[data-toggle-group]'); if (!main) return;
      var g = main.closest('.stk-group'); setOpen(g, !g.classList.contains('open'));
    });
    if (tools) tools.addEventListener('click', function (e) {
      if (e.target.closest('[data-expand-all]')) groups.forEach(function (g) { setOpen(g, true); });
      else if (e.target.closest('[data-collapse-all]')) groups.forEach(function (g) { setOpen(g, false); });
    });
  }
  if (!root || !window.Swal || !window.Confirm) return;
  var Alert = window.Confirm.alert, endpoint = root.dataset.endpoint, token = root.dataset.token, low = Number(root.dataset.low) || 5;
  var reasons = {}; try { reasons = JSON.parse(root.dataset.reasons || '{}'); } catch (e) { /* none */ }
  var toast = function (m, t) { if (window.Portal) window.Portal.toast(m, t || 'success'); };
  var fmt = function (n) { return Number(n).toLocaleString(); };

  [].forEach.call(root.querySelectorAll('[data-adjust]'), function (b) { b.hidden = false; });
  try { var m = sessionStorage.getItem('stock-toast'); if (m) { sessionStorage.removeItem('stock-toast'); toast(m); } } catch (e) { /* ignore */ }

  function post(fields) {
    var fd = new FormData(); fd.append('_token', token);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (r.status === 401 || d.login) { location.reload(); return { ok: false, message: 'Session expired.' }; }
          return { ok: r.ok && d.ok !== false, message: d.message || (r.ok ? 'Done.' : 'Something went wrong.'), data: d };
        });
      }, function () { return { ok: false, message: 'Network problem. Check your connection and try again.' }; });
  }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  function dialog(tr) {
    var cur = Number(tr.dataset.stock), name = tr.dataset.name, variation = tr.dataset.variation;
    var wrap = el('div', 'stk');
    var head = el('p', 'stk-item'); head.appendChild(el('strong', '', name));
    if (variation) head.appendChild(el('span', 'var-chip', variation));
    wrap.appendChild(head);
    var now = el('p', 'stk-now'); now.appendChild(document.createTextNode('In stock now: ')); now.appendChild(el('strong', '', fmt(cur))); wrap.appendChild(now);

    var modes = el('div', 'stk-modes'); modes.setAttribute('role', 'radiogroup'); modes.setAttribute('aria-label', 'What do you want to do?');
    [['add', 'Add stock'], ['remove', 'Remove stock'], ['set', 'Set exact count']].forEach(function (m, i) {
      var l = el('label', 'stk-mode'); var r = document.createElement('input'); r.type = 'radio'; r.name = 'stk-mode'; r.value = m[0]; if (i === 0) r.checked = true;
      l.appendChild(r); l.appendChild(el('span', '', m[1])); modes.appendChild(l);
    });
    wrap.appendChild(modes);

    var qtyL = el('label', 'stk-field'); var qtyT = el('span', '', 'Quantity to add'); var qty = document.createElement('input');
    qty.type = 'number'; qty.min = '0'; qty.max = '1000000'; qty.step = '1'; qty.inputMode = 'numeric'; qty.placeholder = '0'; qty.className = 'swal2-input';
    qtyL.appendChild(qtyT); qtyL.appendChild(qty); wrap.appendChild(qtyL);

    var rL = el('label', 'stk-field'); rL.appendChild(el('span', '', 'Reason')); var reason = document.createElement('select'); reason.className = 'swal2-select';
    Object.keys(reasons).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = reasons[k]; reason.appendChild(o); });
    rL.appendChild(reason); wrap.appendChild(rL);

    var nL = el('label', 'stk-field'); nL.appendChild(el('span', '', 'Note (optional)')); var note = document.createElement('input'); note.className = 'swal2-input'; note.maxLength = 255; note.placeholder = 'e.g. supplier delivery #1042';
    nL.appendChild(note); wrap.appendChild(nL);

    var prev = el('p', 'stk-preview'); prev.setAttribute('aria-live', 'polite'); wrap.appendChild(prev);
    var mode = function () { return wrap.querySelector('input[name="stk-mode"]:checked').value; };
    function refresh() {
      var md = mode(), n = qty.value === '' ? null : Number(qty.value);
      qtyT.textContent = md === 'add' ? 'Quantity to add' : md === 'remove' ? 'Quantity to remove' : 'New stock count';
      if (n === null || !isFinite(n) || n < 0) { prev.textContent = 'Enter a quantity.'; prev.className = 'stk-preview'; return; }
      var after = md === 'add' ? cur + n : md === 'remove' ? cur - n : n;
      prev.className = 'stk-preview' + (after < 0 ? ' bad' : after === 0 ? ' warn' : after <= low ? ' warn' : '');
      prev.textContent = after < 0 ? 'Not possible: only ' + fmt(cur) + ' in stock.' : 'Stock will become ' + fmt(after) + (after === cur ? ' (no change).' : ' (' + (after > cur ? '+' : '−') + fmt(Math.abs(after - cur)) + ').');
    }
    wrap.addEventListener('input', refresh); wrap.addEventListener('change', function () {
      refresh();
      if (mode() === 'set' && qty.value === '') qty.value = String(cur);
    });
    wrap.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName !== 'SELECT') { e.preventDefault(); Swal.clickConfirm(); } });
    refresh();

    return Alert.fire({
      title: 'Adjust stock', html: wrap, confirmButtonText: 'Update stock', showLoaderOnConfirm: true,
      allowOutsideClick: function () { return !Swal.isLoading(); },
      didOpen: function () { qty.focus(); },
      preConfirm: function () {
        if (qty.value === '' || !/^\d+$/.test(qty.value)) { Swal.showValidationMessage('Enter a whole number.'); return false; }
        if (mode() !== 'set' && Number(qty.value) < 1) { Swal.showValidationMessage('Enter a quantity of 1 or more.'); return false; }
        if (mode() === 'remove' && Number(qty.value) > cur) { Swal.showValidationMessage('Only ' + fmt(cur) + ' in stock.'); return false; }
        return post({ product_id: tr.dataset.product, variant_id: tr.dataset.variant, mode: mode(), qty: qty.value, reason: reason.value, note: note.value }).then(function (r) {
          if (!r.ok) { Swal.showValidationMessage(r.message); return false; }
          return r;
        });
      }
    }).then(function (res) {
      if (!res.isConfirmed) return;
      try { sessionStorage.setItem('stock-toast', res.value.message); } catch (e) { /* ignore */ }
      location.reload();
    });
  }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-adjust]'); if (!b) return;
    dialog(b.closest('tr'));
  });
})();
