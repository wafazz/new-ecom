// Categories page: add/edit in a SweetAlert popup (delete confirm comes from confirm.js).
// Without JavaScript the <noscript> add form and the plain delete buttons still work.
(function () {
  'use strict';
  var root = document.getElementById('cat-root');
  if (!root || !window.Swal) return;
  var base = root.dataset.base, tokenValue = root.dataset.token;
  var css = getComputedStyle(document.documentElement);
  var cv = function (n, d) { return css.getPropertyValue(n).trim() || d; };
  var Alert = Swal.mixin({
    background: cv('--surface', '#fff'), color: cv('--text', '#111'),
    confirmButtonColor: cv('--accent', '#111'), cancelButtonColor: '#6b7280',
    showCancelButton: true, reverseButtons: true, focusCancel: false
  });
  var toast = function (m, t) { if (window.Portal) window.Portal.toast(m, t); };

  function send(url, fields) {
    var fd = new FormData();
    fd.append('_token', tokenValue);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (r.status === 401 || d.login) { location.reload(); return { ok: false, message: 'Session expired.' }; }
          return { ok: r.ok && d.ok !== false, message: d.message || (r.ok ? 'Done.' : 'Something went wrong.'), errors: d.errors || {} };
        });
      }, function () { return { ok: false, message: 'Network problem. Check your connection and try again.', errors: {} }; });
  }

  var parents = [];
  try { parents = JSON.parse(root.dataset.parents || '[]'); } catch (e) { parents = []; }

  /** Popup with a name field and an optional parent; the server stays the authority on validation. */
  function nameDialog(opts) {
    var wrap = document.createElement('div');
    function field(labelText, el) {
      var l = document.createElement('label'); l.className = 'swal2-input-label'; l.textContent = labelText; l.style.display = 'block'; l.style.margin = '1em 0 .3em';
      wrap.appendChild(l); wrap.appendChild(el);
    }
    var nameEl = document.createElement('input'); nameEl.className = 'swal2-input'; nameEl.maxLength = 120; nameEl.value = opts.value || ''; nameEl.style.margin = '0 auto'; nameEl.autocomplete = 'off';
    field('Category name', nameEl);
    var parentEl = null;
    if (!opts.hasChildren) { // a category with sub-categories must stay top-level (one level deep)
      parentEl = document.createElement('select'); parentEl.className = 'swal2-select'; parentEl.style.margin = '0 auto'; parentEl.style.minWidth = '60%';
      var none = document.createElement('option'); none.value = '0'; none.textContent = '— None (top-level) —'; parentEl.appendChild(none);
      parents.forEach(function (p) {
        if (String(p.id) === String(opts.id || '')) return;
        var o = document.createElement('option'); o.value = String(p.id); o.textContent = p.name; parentEl.appendChild(o);
      });
      parentEl.value = String(opts.parent || 0);
      field('Parent category (optional)', parentEl);
    }
    Alert.fire({
      title: opts.title, html: wrap, confirmButtonText: opts.button, showLoaderOnConfirm: true,
      allowOutsideClick: function () { return !Swal.isLoading(); },
      didOpen: function () { nameEl.focus(); nameEl.select(); },
      preConfirm: function () {
        var name = nameEl.value.trim(), parent = parentEl ? parentEl.value : String(opts.parent || 0);
        if (!name) { Swal.showValidationMessage('Enter a category name.'); return false; }
        if (opts.id && name === opts.value && parent === String(opts.parent || 0)) { return { unchanged: true }; }
        return send(opts.url, { name: name, parent_id: parent }).then(function (res) {
          if (!res.ok) { Swal.showValidationMessage(res.errors.name || res.errors.parent_id || res.message); return false; }
          return res;
        });
      }
    }).then(function (r) {
      if (!r.isConfirmed) return;
      if (r.value && r.value.unchanged) return;
      try { sessionStorage.setItem('cat-toast', r.value.message); } catch (e) { /* ignore */ }
      location.reload();
    });
  }

  var add = document.getElementById('cat-add');
  if (add) { add.hidden = false; add.addEventListener('click', function () { nameDialog({ title: 'Add category', button: 'Add', url: base }); }); }

  [].forEach.call(root.querySelectorAll('[data-cat-edit]'), function (b) {
    b.hidden = false;
    b.addEventListener('click', function () {
      nameDialog({ title: 'Edit category', button: 'Save changes', id: b.dataset.catEdit, value: b.dataset.name, parent: b.dataset.parent, hasChildren: Number(b.dataset.children) > 0, url: base + '/' + b.dataset.catEdit });
    });
  });

  try { var m = sessionStorage.getItem('cat-toast'); if (m) { sessionStorage.removeItem('cat-toast'); toast(m, 'success'); } } catch (e) { /* ignore */ }
})();
