// Password page: show/hide each field, a simple strength hint, and a live "passwords match" check.
// Purely advisory: the server enforces the real rules.
(function () {
  'use strict';
  var form = document.querySelector('[data-password-form]');
  if (!form) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var next = form.elements.password, again = form.elements.password_confirmation;
  var meter = $('[data-pw-meter]', form), hint = $('[data-pw-hint]', form), match = $('[data-pw-match]', form);

  [].forEach.call(form.querySelectorAll('[data-pw-toggle]'), function (b) {
    b.hidden = false;
    b.addEventListener('click', function () {
      var inp = b.parentNode.querySelector('input'), show = inp.type === 'password';
      inp.type = show ? 'text' : 'password'; b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  var labels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
  function score(p) {
    if (p.length < 8) return 0;
    var mixed = /[a-z]/.test(p) && /[A-Z]/.test(p), digit = /\d/.test(p), symbol = /[^A-Za-z0-9]/.test(p);
    var s = 1;
    if (p.length >= 12) s++;
    if (p.length >= 16 || (mixed && digit)) s++;
    if (p.length >= 20 || (mixed && digit && symbol && p.length >= 14)) s++;
    return Math.min(4, s);
  }
  function paint() {
    var p = next.value;
    meter.hidden = p === '';
    if (p === '') { hint.textContent = 'At least 8 characters. A longer phrase is stronger than a short, complicated one.'; return; }
    var s = score(p); meter.dataset.level = String(s);
    [].forEach.call(meter.children, function (bar, i) { bar.classList.toggle('on', i < s || (s === 0 && i === 0)); });
    hint.textContent = labels[s] + (s < 3 ? ': try a longer phrase.' : '.');
    checkMatch();
  }
  function checkMatch() {
    if (!again.value) { match.hidden = true; return; }
    var ok = again.value === next.value; match.hidden = false; match.dataset.ok = ok ? '1' : '0';
    match.textContent = ok ? 'Passwords match.' : 'Passwords do not match yet.';
  }
  next.addEventListener('input', paint); again.addEventListener('input', checkMatch);
})();
