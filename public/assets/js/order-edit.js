// Order list: the pencil button edits a pending or processing order's contact and delivery details in a SweetAlert popup.
(function () {
  'use strict';
  var root = document.getElementById('order-edit-root');
  if (!root || !window.Swal) return;
  var token = root.dataset.token;
  var states = JSON.parse(root.dataset.states || '[]');
  var css = getComputedStyle(document.documentElement);
  var cv = function (n, d) { return css.getPropertyValue(n).trim() || d; };
  var Alert = Swal.mixin({
    background: cv('--surface', '#fff'), color: cv('--text', '#111'),
    confirmButtonColor: cv('--accent', '#111'), cancelButtonColor: '#6b7280',
    showCancelButton: true, reverseButtons: true, focusCancel: false
  });

  var FIELDS = [
    { key: 'name', label: 'Customer name', max: 120 },
    { key: 'phone', label: 'Phone', max: 30 },
    { key: 'line1', label: 'Address line 1', max: 190 },
    { key: 'line2', label: 'Address line 2 (optional)', max: 190 },
    { key: 'city', label: 'City', max: 100 },
    { key: 'state', label: 'State', select: true },
    { key: 'postcode', label: 'Postcode (5 digits)', max: 5 }
  ];

  function send(url, fields) {
    var fd = new FormData();
    fd.append('_token', token);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (r.status === 401 || d.login) { location.reload(); return { ok: false, message: 'Session expired.' }; }
          return { ok: r.ok && d.ok !== false, message: d.message || (r.ok ? 'Saved.' : 'Something went wrong.'), errors: d.errors || {} };
        });
      }, function () { return { ok: false, message: 'Network problem. Check your connection and try again.', errors: {} }; });
  }

  function buildForm(d) {
    var wrap = document.createElement('div');
    wrap.className = 'order-edit';
    var inputs = {};
    FIELDS.forEach(function (f) {
      var lab = document.createElement('label');
      lab.className = 'order-edit-field';
      var span = document.createElement('span');
      span.textContent = f.label;
      lab.appendChild(span);
      var input;
      if (f.select) {
        input = document.createElement('select');
        input.className = 'swal2-select';
        var list = states.slice();
        if (d[f.key] && list.indexOf(d[f.key]) === -1) list.unshift(d[f.key]);
        list.forEach(function (s) { var o = document.createElement('option'); o.value = s; o.textContent = s; input.appendChild(o); });
      } else {
        input = document.createElement('input');
        input.className = 'swal2-input';
        input.maxLength = f.max;
        input.autocomplete = 'off';
      }
      input.value = d[f.key] || '';
      inputs[f.key] = input;
      lab.appendChild(input);
      wrap.appendChild(lab);
    });
    return { el: wrap, inputs: inputs };
  }

  function firstError(res) {
    var keys = Object.keys(res.errors || {});
    return keys.length ? res.errors[keys[0]] : res.message;
  }

  function openEdit(btn) {
    var d = btn.dataset;
    var form = buildForm(d);
    Alert.fire({
      title: 'Edit ' + d.orderNo,
      html: form.el,
      confirmButtonText: 'Save changes',
      allowOutsideClick: function () { return !Swal.isLoading(); },
      didOpen: function () { form.inputs.name.focus(); },
      preConfirm: function () {
        var values = {}, changed = false;
        FIELDS.forEach(function (f) {
          values[f.key] = form.inputs[f.key].value.trim();
          if (values[f.key] !== (d[f.key] || '')) changed = true;
        });
        if (!changed) return { unchanged: true };
        return send(d.orderEdit, {
          name: values.name, phone: values.phone, line1: values.line1, line2: values.line2,
          city: values.city, state: values.state, postcode: values.postcode
        }).then(function (res) {
          if (!res.ok) { Swal.showValidationMessage(firstError(res)); return false; }
          return { message: res.message };
        });
      }
    }).then(function (r) {
      if (!r.isConfirmed || (r.value && r.value.unchanged)) return;
      try { sessionStorage.setItem('order-toast', r.value.message); } catch (e) { /* ignore */ }
      location.reload();
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-order-edit]') : null;
    if (!btn) return;
    ev.preventDefault();
    openEdit(btn);
  });

})();
