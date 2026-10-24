// Shared SweetAlert confirm. Mark a form with data-swal-confirm="Title" (+ optional data-swal-text,
// data-swal-button, data-swal-danger) and it asks first. Other scripts call Confirm.ask({...}).
(function () {
  'use strict';
  if (!window.Swal) return;
  var css = getComputedStyle(document.documentElement);
  var cv = function (n, d) { return css.getPropertyValue(n).trim() || d; };
  var Alert = Swal.mixin({
    cancelButtonColor: '#6b7280',
    showCancelButton: true, reverseButtons: true
  });

  /** opts: {title, text, button, danger}. Resolves true when confirmed. */
  function ask(opts) {
    return Alert.fire({
      icon: opts.danger ? 'warning' : 'question', title: opts.title, text: opts.text || undefined,
      confirmButtonText: opts.button || 'Yes', cancelButtonText: opts.cancel || 'Cancel',
      confirmButtonColor: opts.danger ? '#b00020' : undefined, focusCancel: !!opts.danger
    }).then(function (r) { return r.isConfirmed; });
  }
  window.Confirm = { ask: ask, alert: Alert };

  /**
   * The confirm dialog replaces a popup that the form may live in (order details), which detaches the form.
   * A detached form cannot be submitted, so send it in the background and go to the page the server names.
   */
  function sendDetached(f) {
    var fd = new FormData(f);
    fetch(f.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, ok: r.ok && d.ok !== false, d: d }; });
      })
      .then(function (res) {
        if (res.status === 401 || res.d.login) { location.reload(); return; }
        if (res.ok) {
          try { sessionStorage.setItem('order-toast', res.d.message || 'Done.'); } catch (e) { /* ignore */ }
          location.href = res.d.redirect || location.href;
          return;
        }
        Swal.fire({ icon: 'error', title: 'Could not complete that', text: res.d.message || 'Something went wrong. Try again.' });
      }, function () {
        Swal.fire({ icon: 'error', title: 'Network problem', text: 'Check your connection and try again.' });
      });
  }

  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f.matches || !f.matches('form[data-swal-confirm]') || f.dataset.swalOk) return;
    ev.preventDefault();
    ask({ title: f.dataset.swalConfirm, text: f.dataset.swalText, button: f.dataset.swalButton, danger: !!f.dataset.swalDanger })
      .then(function (ok) {
        if (!ok) return;
        if (f.isConnected) { f.dataset.swalOk = '1'; f.submit(); return; }
        sendDetached(f);
      });
  });

  // A message left by a form that was sent in the background (see sendDetached) shows once, on the page it lands on.
  window.addEventListener('load', function () {
    try {
      var m = sessionStorage.getItem('order-toast');
      if (m) { sessionStorage.removeItem('order-toast'); if (window.Portal) window.Portal.toast(m, 'success'); }
    } catch (e) { /* ignore */ }
  });
})();
