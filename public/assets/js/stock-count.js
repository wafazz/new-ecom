// Stock counts: the print button on a count sheet.
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (b) {
    b.addEventListener('click', function () { window.print(); });
  });
})();
