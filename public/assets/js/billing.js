// Admin > Module billing: shows the chosen receipt's file name. Plan choice and buttons work without JavaScript.
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-file-input]'), function (input) {
    var out = input.closest('form').querySelector('[data-file-name]');
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      out.textContent = f ? 'Selected: ' + f.name : '';
    });
  });
})();
