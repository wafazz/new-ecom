// Printable AWB (one or many labels): draws the barcodes and QR codes, prints on request, records the print, and closes the tab.
(function () {
  'use strict';
  var root = document.getElementById('awb-root');
  if (!root) return;

  if (window.JsBarcode) {
    document.querySelectorAll('svg[data-barcode]').forEach(function (svg) {
      JsBarcode(svg, svg.dataset.barcode, { format: 'CODE128', displayValue: false, margin: 0, width: 1.4, height: 40 });
      if (svg.dataset.stretch === '1') svg.setAttribute('preserveAspectRatio', 'none'); // bars fill the full label width
    });
  }

  if (window.qrcode) {
    document.querySelectorAll('[data-qr]').forEach(function (box) {
      var qr = qrcode(0, 'M');
      qr.addData(box.dataset.qr);
      qr.make();
      box.innerHTML = qr.createSvgTag(3, 0);
    });
  }

  // Record the first print of each label (the order list shows PRINTED). Sent when the print dialog opens.
  function recordPrint() {
    var url = root.dataset.printedUrl, token = root.dataset.token;
    if (!url || !token) return;
    var fd = new FormData();
    fd.append('_token', token);
    if (root.dataset.printedIds) fd.append('ids', root.dataset.printedIds);
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .catch(function () { /* the labels still print; the badge just is not set */ });
  }

  function printLabels() {
    recordPrint();
    window.print();
  }

  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', printLabels);
  });
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { window.close(); if (!window.closed) history.back(); });
  });

  if (root.dataset.printOnLoad === '1') {
    window.addEventListener('load', function () { setTimeout(printLabels, 250); });
  }
})();
