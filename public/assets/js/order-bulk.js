// Order list (Processing): tick orders, then print their AWB labels in one run, or move printed orders on.
(function () {
  'use strict';
  var bar = document.querySelector('[data-bulk-bar]');
  if (!bar) return;
  var boxes = [].slice.call(document.querySelectorAll('input[data-awb-select]'));
  var count = bar.querySelector('[data-bulk-count]');
  var printBtn = bar.querySelector('[data-bulk-print]');
  var moveBtn = bar.querySelector('[data-bulk-move-btn]');
  var moveForm = bar.querySelector('[data-bulk-move]');

  function checked() {
    return boxes.filter(function (b) { return b.checked; });
  }

  function refresh() {
    var n = checked().length;
    count.textContent = String(n);
    printBtn.disabled = n === 0;
    if (moveBtn) moveBtn.disabled = n === 0;
  }

  // Confirm text: COD goes to Shipped; paid non-COD goes to Shipped and then Completed; unpaid non-COD is skipped.
  function moveSummary(list) {
    var cod = 0, paid = 0, unpaid = 0;
    list.forEach(function (b) {
      if (b.dataset.cod === '1') cod++;
      else if (b.dataset.paid === '1') paid++;
      else unpaid++;
    });
    var parts = [];
    if (cod) parts.push(cod + ' COD order(s) go to Shipped.');
    if (paid) parts.push(paid + ' paid non-COD order(s) go to Shipped, then Completed.');
    if (unpaid) parts.push(unpaid + ' unpaid non-COD order(s) are skipped (not paid yet).');
    return parts.join(' ');
  }

  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  bar.querySelector('[data-bulk-all]').addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = true; });
    refresh();
  });
  bar.querySelector('[data-bulk-none]').addEventListener('click', function () {
    boxes.forEach(function (b) { b.checked = false; });
    refresh();
  });
  printBtn.addEventListener('click', function () {
    var ids = checked().map(function (b) { return b.value; });
    if (ids.length === 0) return;
    window.open(bar.dataset.bulkUrl + '?print=1&ids=' + ids.join(','), '_blank', 'noopener');
  });

  if (moveBtn && moveForm) {
    // The click fills the form before the browser submits it, so the confirm dialog gets the right text.
    moveBtn.addEventListener('click', function () {
      var list = checked();
      moveForm.querySelector('input[name=ids]').value = list.map(function (b) { return b.value; }).join(',');
      moveForm.dataset.swalText = moveSummary(list);
    });
  }
  refresh();
})();
