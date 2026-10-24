// Bank transfer proof fields: searchable bank list (Select2, with typing-in for banks not listed), receipt picker with
// preview and checks, a sensible default for the payment time, and (on checkout) showing the fields only for Bank transfer.
(function () {
  'use strict';
  var D = document, MAX = 5 * 1024 * 1024, OK = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  var $ = function (s, r) { return (r || D).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
  var size = function (n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };
  var pad = function (n) { return n < 10 ? '0' + n : String(n); };
  var local = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };

  function bankSelect(fields) {
    var sel = $('[data-bank-select]', fields);
    if (!sel || !window.jQuery || !jQuery.fn.select2) return; // the plain <select> still works
    jQuery(sel).select2({
      width: '100%', placeholder: sel.dataset.placeholder || 'Choose a bank…', tags: true, minimumResultsForSearch: 0,
      createTag: function (p) { var t = (p.term || '').trim(); return t.length < 2 || t.length > 80 ? null : { id: t, text: t + ' (use this name)', newTag: true }; },
      language: { noResults: function () { return 'No bank found: type the full name to add it.'; } }
    });
  }

  function receipt(fields) {
    var input = $('[data-receipt]', fields), drop = $('[data-drop]', fields), ui = $('[data-drop-ui]', fields), prev = $('[data-preview]', fields), err = $('[data-receipt-error]', fields);
    if (!input) return;
    var fail = function (m) { err.textContent = m; err.hidden = false; input.value = ''; clear(); };
    var clear = function () { prev.hidden = true; prev.textContent = ''; ui.hidden = false; drop.classList.remove('has-file'); };
    input.addEventListener('change', function () {
      err.hidden = true;
      var f = input.files[0]; if (!f) { clear(); return; }
      var okType = OK.indexOf(f.type) >= 0 || /\.(jpe?g|png|webp|pdf)$/i.test(f.name);
      if (!okType) return fail('Attach a JPG, PNG, WebP or PDF file.');
      if (f.size > MAX) return fail('That file is ' + size(f.size) + '. The limit is 5 MB: try a screenshot instead.');
      prev.textContent = ''; ui.hidden = true; prev.hidden = false; drop.classList.add('has-file');
      var thumb = D.createElement('span'); thumb.className = 'proof-thumb';
      if (/^image\//.test(f.type)) { var r = new FileReader(); r.onload = function () { var i = new Image(); i.alt = ''; i.src = r.result; thumb.appendChild(i); }; r.readAsDataURL(f); }
      else thumb.textContent = 'PDF';
      var meta = D.createElement('span'); meta.className = 'proof-meta';
      var nm = D.createElement('strong'); nm.textContent = f.name; var sz = D.createElement('small'); sz.textContent = size(f.size) + ' · tap to change';
      meta.appendChild(nm); meta.appendChild(sz); prev.appendChild(thumb); prev.appendChild(meta);
    });
    ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.remove('over'); }); });
  }

  function when(fields) {
    var w = $('[data-paid-at]', fields); if (!w) return;
    var now = new Date(), min = new Date(now.getTime() - 30 * 86400000);
    w.max = local(now); w.min = local(min);
    if (!w.value) w.value = local(now); // most people fill this in right after paying
  }

  /** Checkout: show the panel only for Bank transfer and only send / require the fields then. */
  function panel(box) {
    var form = box.closest('form'), radios = $$('input[name="payment_method"]', form), fields = $('[data-proof-fields]', box);
    var controls = $$('input, select', fields);
    function sync() {
      var on = !!radios.filter(function (r) { return r.checked && r.value === 'bank_transfer'; }).length;
      box.hidden = !on;
      controls.forEach(function (c) {
        c.disabled = !on;
        if (c.matches('[data-receipt],[data-bank-select],[data-paid-at]')) c.required = on;
        if (c.matches('[data-bank-select]') && window.jQuery && jQuery.fn.select2) jQuery(c).trigger('change.select2');
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
    form.addEventListener('submit', function (e) {
      if (box.hidden) return;
      var f = $('[data-receipt]', fields), bank = $('[data-bank-select]', fields);
      if (!f.files.length || !bank.value || !$('[data-paid-at]', fields).value) { // let the browser point at the first missing field
        e.preventDefault(); if (form.reportValidity) form.reportValidity();
        if (!f.files.length) { var er = $('[data-receipt-error]', fields); er.textContent = 'Please attach your payment receipt.'; er.hidden = false; box.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        return;
      }
      var btn = $('[data-place-order]', form); if (btn) { btn.disabled = true; btn.classList.add('loading'); } // uploads can take a moment: stop double clicks
    });
  }

  function init() {
    $$('[data-proof-fields]').forEach(function (f) { bankSelect(f); receipt(f); when(f); });
    $$('[data-bank-proof]').forEach(panel);
    // order page upload form: the same busy state
    $$('form[data-proof-form]').forEach(function (form) {
      form.addEventListener('submit', function () { var b = $('button[type=submit], button:not([type])', form); if (b) { b.disabled = true; b.classList.add('loading'); } });
    });
  }
  if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', init); else init();
})();
