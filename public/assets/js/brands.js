// Brands page: add/edit in a SweetAlert popup (delete confirm comes from confirm.js).
(function () {
  'use strict';
  var root = document.getElementById('brand-root');
  if (!root || !window.Swal || !window.Confirm) return;
  var base = root.dataset.base, tokenValue = root.dataset.token;
  var Alert = window.Confirm.alert;
  var toast = function (m, t) { if (window.Portal) window.Portal.toast(m, t); };

  function send(url, fields) {
    var fd = new FormData();
    fd.append('_token', tokenValue);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (r.status === 401 || d.login) { location.reload(); return { ok: false, message: 'Session expired.', errors: {} }; }
          return { ok: r.ok && d.ok !== false, message: d.message || (r.ok ? 'Done.' : 'Something went wrong.'), errors: d.errors || {} };
        });
      }, function () { return { ok: false, message: 'Network problem. Check your connection and try again.', errors: {} }; });
  }

  var MAX = 2 * 1024 * 1024, TYPES = ['image/jpeg', 'image/png', 'image/webp'];

  function nameDialog(opts) {
    var wrap = document.createElement('div');
    function field(text, el) {
      var l = document.createElement('label'); l.className = 'swal2-input-label'; l.textContent = text; l.style.display = 'block'; l.style.margin = '1em 0 .3em';
      wrap.appendChild(l); wrap.appendChild(el);
    }
    var nameEl = document.createElement('input'); nameEl.className = 'swal2-input'; nameEl.maxLength = 120; nameEl.value = opts.value || ''; nameEl.style.margin = '0 auto'; nameEl.autocomplete = 'off';
    field('Brand name', nameEl);

    var prev = document.createElement('img'); prev.alt = ''; prev.className = 'brand-logo'; prev.style.height = '64px'; prev.style.maxWidth = '160px'; prev.style.display = opts.logo ? 'block' : 'none'; prev.style.margin = '0 auto .5em';
    if (opts.logo) prev.src = opts.logo;
    var fileEl = document.createElement('input'); fileEl.type = 'file'; fileEl.accept = TYPES.join(','); fileEl.className = 'swal2-file'; fileEl.style.margin = '0 auto';
    var rm = null;
    var lab = document.createElement('label'); lab.className = 'swal2-input-label'; lab.textContent = 'Logo (optional, JPG/PNG/WebP, max 2 MB)'; lab.style.display = 'block'; lab.style.margin = '1em 0 .5em';
    wrap.appendChild(lab); wrap.appendChild(prev); wrap.appendChild(fileEl);
    if (opts.logo) {
      var rl = document.createElement('label'); rl.style.display = 'block'; rl.style.marginTop = '.5em'; rl.style.fontSize = '.9em';
      rm = document.createElement('input'); rm.type = 'checkbox'; rl.appendChild(rm); rl.appendChild(document.createTextNode(' Remove current logo'));
      wrap.appendChild(rl);
    }
    fileEl.addEventListener('change', function () {
      var f = fileEl.files[0];
      if (!f) { prev.style.display = opts.logo ? 'block' : 'none'; if (opts.logo) prev.src = opts.logo; return; }
      if (TYPES.indexOf(f.type) < 0) { Swal.showValidationMessage('Only JPG, PNG or WebP images are allowed.'); fileEl.value = ''; return; }
      if (f.size > MAX) { Swal.showValidationMessage('Image is too large (max 2 MB).'); fileEl.value = ''; return; }
      Swal.resetValidationMessage();
      var fr = new FileReader(); fr.onload = function () { prev.src = fr.result; prev.style.display = 'block'; }; fr.readAsDataURL(f);
      if (rm) rm.checked = false;
    });

    Alert.fire({
      title: opts.title, html: wrap, confirmButtonText: opts.button, showLoaderOnConfirm: true,
      allowOutsideClick: function () { return !Swal.isLoading(); },
      didOpen: function () { nameEl.focus(); nameEl.select(); },
      preConfirm: function () {
        var name = nameEl.value.trim(), file = fileEl.files[0] || null, remove = !!(rm && rm.checked);
        if (!name) { Swal.showValidationMessage('Enter a brand name.'); return false; }
        if (opts.id && name === opts.value && !file && !remove) return { unchanged: true };
        var fields = { name: name };
        if (file) fields.logo = file;
        if (remove && !file) fields.remove_logo = '1';
        return send(opts.url, fields).then(function (res) {
          if (!res.ok) { Swal.showValidationMessage(res.errors.name || res.errors.logo || res.message); return false; }
          return res;
        });
      }
    }).then(function (r) {
      if (!r.isConfirmed || (r.value && r.value.unchanged)) return;
      try { sessionStorage.setItem('brand-toast', r.value.message); } catch (e) { /* ignore */ }
      location.reload();
    });
  }

  var add = document.getElementById('brand-add');
  if (add) { add.hidden = false; add.addEventListener('click', function () { nameDialog({ title: 'Add brand', button: 'Add', url: base }); }); }
  [].forEach.call(root.querySelectorAll('[data-brand-edit]'), function (b) {
    b.hidden = false;
    b.addEventListener('click', function () { nameDialog({ title: 'Edit brand', button: 'Save changes', id: b.dataset.brandEdit, value: b.dataset.name, logo: b.dataset.logo, url: base + '/' + b.dataset.brandEdit }); });
  });
  try { var m = sessionStorage.getItem('brand-toast'); if (m) { sessionStorage.removeItem('brand-toast'); toast(m, 'success'); } } catch (e) { /* ignore */ }
})();
