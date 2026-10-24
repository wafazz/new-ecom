// "View invoice" links open the invoice in a popup. Without JavaScript the link still opens the PDF in a new tab.
(function () {
  var dlg = document.getElementById('invoice-modal');
  if (!dlg) return;
  var frame = document.getElementById('invoice-frame');
  var download = document.getElementById('invoice-download');
  var newTab = document.getElementById('invoice-newtab');

  function open(url) {
    frame.src = url;
    download.href = url;
    newTab.href = url;
    if (typeof dlg.showModal !== 'function') {
      window.open(url, '_blank', 'noopener');
      return;
    }
    if (!dlg.open) dlg.showModal();
    document.body.classList.add('modal-open');
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest('[data-invoice-open]');
    if (link) {
      // Ctrl/Cmd or Shift click still opens the usual new tab.
      if (e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      open(link.getAttribute('href'));
      return;
    }
    if (e.target.closest('[data-invoice-close]') || e.target === dlg) {
      dlg.close();
    }
  });

  dlg.addEventListener('close', function () {
    frame.src = 'about:blank'; // stop the PDF loading once it is closed
    document.body.classList.remove('modal-open');
  });
})();
