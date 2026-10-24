// Payments page: the on/off words next to each switch, and a live "what customers see" line for Bank transfer.
(function () {
  'use strict';
  var form = document.querySelector('[data-payments]');
  if (!form) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };

  function words() {
    [].forEach.call(form.querySelectorAll('.toggle'), function (t) {
      var on = $('input', t).checked; $('[data-toggle-text]', t).textContent = on ? 'On' : 'Off';
      var card = t.closest('.pay-card'); if (card) card.classList.toggle('is-off', !on);
    });
  }
  form.addEventListener('change', function (e) { if (e.target.matches('[data-toggle]')) words(); });
  words();

  // searchable bank list; a bank that is not listed can be typed in
  var bankSel = $('[data-bank-select]', form);
  if (bankSel && window.jQuery && jQuery.fn.select2) {
    jQuery(bankSel).select2({
      width: '100%', placeholder: bankSel.dataset.placeholder || 'Choose a bank…', tags: true,
      createTag: function (p) { var t = (p.term || '').trim(); return t.length < 2 || t.length > 80 ? null : { id: t, text: t + ' (use this name)', newTag: true }; },
      language: { noResults: function () { return 'No bank found: type the full name to add it.'; } }
    }).on('change', function () { preview(); });
  }
  var f = function (k) { return ($('[data-bank="' + k + '"]', form).value || '').trim(); };
  function preview() {
    var name = f('name'), no = f('number'), holder = f('holder'), note = f('note'), out = $('#bank-preview');
    var base = (name && no && holder)
      ? 'Transfer the total to ' + name + ', account ' + no + ' (' + holder + '). Use your order number as the reference. We ship after payment is confirmed.'
      : 'Fill in the bank name, account number and account name: customers only see this method once all three are set.';
    out.textContent = (name && no && holder && note) ? base + ' ' + note : base;
    out.classList.toggle('incomplete', !(name && no && holder));
  }
  form.addEventListener('input', function (e) { if (e.target.matches('[data-bank]')) preview(); });
  preview();
})();
