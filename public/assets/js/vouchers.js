// Vouchers page: the "New code" popup. Reopens by itself when the form came back with errors.
(function () {
  var dlg = document.getElementById('voucher-modal');
  if (!dlg) return;

  function open() {
    if (typeof dlg.showModal === 'function') {
      if (!dlg.open) dlg.showModal();
    } else {
      dlg.setAttribute('open', '');
    }
    document.body.classList.add('modal-open');
  }

  function close() {
    if (typeof dlg.close === 'function') dlg.close();
    else dlg.removeAttribute('open');
    document.body.classList.remove('modal-open');
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-voucher-open]')) { open(); return; }
    if (e.target.closest('[data-voucher-close]')) { close(); return; }
    if (e.target === dlg) close(); // a click on the dark backdrop, outside the box
  });
  dlg.addEventListener('close', function () { document.body.classList.remove('modal-open'); });

  if (dlg.dataset.reopen === '1') open();
})();
