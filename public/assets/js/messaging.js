// WhatsApp and Email admin pages: placeholder buttons, live previews, unsaved-changes bar, busy buttons, log filters.
(function () {
  'use strict';

  // Sample values for the previews only (never real customer data).
  var SAMPLE = {
    name: 'Ali Bin Abu', order_no: 'ORD-20417', total: '54.00', status: 'Shipped', tracking: 'SPE3984522429',
    shop: (document.querySelector('[data-shop]') || { dataset: {} }).dataset.shop || 'Lure Shop', ordered_item: '2 x Lure Silicone Shrimp (Pink / 3in)\n1 x Hook Pack 2/0',
    payment: 'Cash on delivery', track_url: 'https://example.com/order/ORD-20417/…', verify_url: 'https://example.com/verify-email/…'
  };

  function fill(text) {
    return String(text || '').replace(/\{(\w+)\}/g, function (m, k) { return SAMPLE[k] !== undefined ? SAMPLE[k] : m; });
  }

  var lastField = null;
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('textarea, input[type=text], input:not([type]), input[type=email]')) lastField = e.target;
  });

  // Placeholder buttons insert "{name}" at the cursor of the last field the user was in.
  document.addEventListener('click', function (e) {
    var chip = e.target.closest && e.target.closest('[data-insert]');
    if (!chip) return;
    e.preventDefault();
    var field = lastField && chip.closest('form') === lastField.closest('form') ? lastField : chip.closest('form').querySelector('textarea, input[type=text]');
    if (!field) return;
    var token = chip.dataset.insert, start = field.selectionStart == null ? field.value.length : field.selectionStart, end = field.selectionEnd == null ? start : field.selectionEnd;
    field.value = field.value.slice(0, start) + token + field.value.slice(end);
    field.focus();
    field.selectionStart = field.selectionEnd = start + token.length;
    field.dispatchEvent(new Event('input', { bubbles: true }));
  });

  // Live previews: [data-preview-for="field-id"] shows the field's text with sample values.
  document.querySelectorAll('[data-preview-for]').forEach(function (box) {
    var field = document.getElementById(box.dataset.previewFor);
    if (!field) return;
    var kind = box.dataset.previewKind || 'text';
    var out = box.querySelector('.msg-preview-body');
    if (!out) return;
    var render = function () {
      var t = fill(field.value);
      out.textContent = t.trim() === '' ? 'Nothing to show yet.' : t;
      var n = box.querySelector('[data-count]');
      if (n) n.textContent = field.value.length + ' characters';
    };
    field.addEventListener('input', render);
    render();
    if (kind === 'wa') box.classList.add('is-wa');
  });

  // Subject previews (emails and announcements).
  document.querySelectorAll('[data-subject-for]').forEach(function (el) {
    var field = document.getElementById(el.dataset.subjectFor);
    if (!field) return;
    var render = function () { el.textContent = fill(field.value) || '(no subject)'; };
    field.addEventListener('input', render);
    render();
  });

  // Unsaved-changes bar for the settings forms.
  document.querySelectorAll('[data-msg-form]').forEach(function (form) {
    var bar = document.querySelector('[data-savebar-for="' + form.id + '"]');
    var dirty = false;
    var set = function (v) { dirty = v; if (bar) bar.classList.toggle('is-visible', v); };
    form.addEventListener('input', function () { set(true); });
    form.addEventListener('change', function () { set(true); });
    form.addEventListener('submit', function () { set(false); });
    window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
  });

  // Show / hide the API key.
  document.querySelectorAll('[data-toggle-secret]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.toggleSecret);
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });

  // Busy state: a button shows "Working…" and cannot be pressed twice while the request runs.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('[data-busy-form]')) return;
    if (form.hasAttribute('data-swal-confirm') && !form.dataset.swalOk) return; // wait for the confirmation first
    setTimeout(function () {
      form.querySelectorAll('button').forEach(function (b) { b.disabled = true; b.classList.add('is-busy'); });
    }, 0);
  });

  // Audience cards: the summary shows how many people will get it.
  var aud = document.querySelectorAll('[data-audience]');
  var summary = document.querySelector('[data-audience-summary]');
  var setAudience = function () {
    var checked = document.querySelector('[data-audience]:checked');
    aud.forEach(function (r) { r.closest('.aud-card').classList.toggle('is-selected', r.checked); });
    if (checked && summary) {
      var n = Number(checked.dataset.count) || 0;
      summary.innerHTML = '';
      var strong = document.createElement('strong');
      strong.textContent = String(n);
      summary.appendChild(document.createTextNode('This will go to '));
      summary.appendChild(strong);
      summary.appendChild(document.createTextNode(' ' + (n === 1 ? 'person' : 'people') + '.'));
    }
  };
  aud.forEach(function (r) { r.addEventListener('change', setAudience); });
  if (aud.length) setAudience();

  // Log filters (All / Sent / Failed).
  document.querySelectorAll('[data-log-filter]').forEach(function (group) {
    var table = document.querySelector('[data-log-table="' + group.dataset.logFilter + '"]');
    group.querySelectorAll('[data-filter]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        group.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('is-on', b === btn); b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        if (!table) return;
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          var want = btn.dataset.filter;
          tr.hidden = !(want === 'all' || (want === 'sent' && tr.dataset.ok === '1') || (want === 'failed' && tr.dataset.ok === '0'));
        });
      });
    });
  });
})();
