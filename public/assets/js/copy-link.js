// "Copy link" buttons (data-copy="URL") and the product filter on the affiliate page (data-link-filter).
(function () {
  function copyText(text, done) {
    function fallback() {
      var t = document.createElement('textarea');
      t.value = text;
      t.setAttribute('readonly', '');
      t.style.position = 'fixed';
      t.style.opacity = '0';
      document.body.appendChild(t);
      t.select();
      try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
      document.body.removeChild(t);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
    } else {
      fallback();
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    var label = btn.textContent;
    copyText(btn.getAttribute('data-copy'), function (ok) {
      btn.textContent = ok ? 'Copied!' : 'Select and copy manually';
      setTimeout(function () { btn.textContent = label; }, 1800);
    });
  });

  var filter = document.querySelector('[data-link-filter]');
  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      document.querySelectorAll('[data-link-row]').forEach(function (row) {
        row.hidden = q !== '' && row.getAttribute('data-name').indexOf(q) === -1;
      });
    });
  }
})();
