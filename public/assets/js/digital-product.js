// Product form: "Physical / Digital" switch. A digital product hides the shipping size and shows the delivery fields;
// only the fields for the chosen delivery (file, link or license keys) are sent. The server checks everything again.
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function setHidden(el, hidden) {
    el.hidden = hidden;
    $$('input,select,textarea,button', el).forEach(function (i) { i.disabled = hidden; }); // disabled = not submitted
  }

  function apply(form) {
    var kind = form.querySelector('input[name="product_kind"]:checked');
    var digital = !!kind && kind.value === 'digital';
    $$('[data-digital-only]', form).forEach(function (fs) { setHidden(fs, !digital); });
    $$('[data-physical-only]', form).forEach(function (fs) { setHidden(fs, digital); });
    $$('[data-product-type]', form).forEach(function (fs) { fs.hidden = digital; }); // digital products are always single
    var delivery = form.querySelector('input[name="digital_delivery"]:checked');
    $$('[data-delivery]', form).forEach(function (l) {
      setHidden(l, !digital || !delivery || delivery.value !== l.getAttribute('data-delivery'));
    });
  }

  function init(root) {
    $$('form', root || document).forEach(function (form) { if (form.querySelector('[data-product-kind]')) apply(form); });
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('input[name="product_kind"], input[name="digital_delivery"]')) apply(e.target.closest('form'));
  });

  window.DigitalProduct = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
