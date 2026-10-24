// Product form: "Single product / With variations" radio + dynamic variation rows (SKU, option, value, price, stock).
// Works on the standalone product pages and inside the product popup (products.js calls Variations.init after it loads a form).
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function apply(form) {
    var fs = form.querySelector('[data-variations]'); if (!fs) return;
    var checked = form.querySelector('input[name="product_type"]:checked');
    var variable = !!checked && checked.value === 'variable';
    fs.hidden = !variable;
    $$('input,button', fs).forEach(function (el) { if (!el.matches('[data-var-add]')) el.disabled = !variable; }); // disabled = not submitted
    $$('[data-single-only]', form).forEach(function (l) { l.hidden = variable; $$('input', l).forEach(function (i) { i.disabled = variable; }); });
    var note = form.querySelector('[data-drop-note]');
    if (note) note.hidden = variable || fs.hasAttribute('data-start-hidden'); // only warn when the product had variations
  }

  function init(root) {
    $$('[data-variations]', root || document).forEach(function (fs) {
      var max = -1;
      $$('[name^="variants["]', fs).forEach(function (el) { var m = el.name.match(/^variants\[(\d+)\]/); if (m) max = Math.max(max, Number(m[1])); });
      fs.dataset.next = String(max + 1);
      apply(fs.closest('form'));
    });
  }

  function addRow(fs) {
    var tpl = fs.querySelector('[data-var-template]'), box = fs.querySelector('[data-var-rows]');
    var i = Number(fs.dataset.next || 0); fs.dataset.next = String(i + 1);
    var wrap = document.createElement('div');
    wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, String(i));
    var row = wrap.firstElementChild; box.appendChild(row);
    var first = row.querySelector('input[name$="[sku]"]'); if (first) first.focus();
    return row;
  }

  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-var-add]');
    if (add) { e.preventDefault(); addRow(add.closest('[data-variations]')); return; }
    var rm = e.target.closest('[data-var-remove]');
    if (rm) {
      e.preventDefault();
      var fs = rm.closest('[data-variations]'), row = rm.closest('[data-var-row]');
      $$('.error.js', row).forEach(function (n) { n.remove(); });
      row.remove();
      if (!fs.querySelector('[data-var-row]')) addRow(fs); // always keep one row to type into
    }
  });
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('input[name="product_type"]')) apply(e.target.closest('form'));
  });

  window.Variations = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
