// Order details: "View order" on the order list opens the order page inside a SweetAlert popup.
// Also handles checkboxes that show or hide a box (data-toggle-box="id"), which inline scripts cannot do in a popup.
(function () {
  'use strict';

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t || !t.matches || !t.matches('input[data-toggle-box]')) return;
    var box = document.getElementById(t.dataset.toggleBox);
    if (box) box.hidden = !t.checked;
  });

  if (!window.Swal) return;

  function load(url, fullUrl, title, scrollTo) {
    var box = document.createElement('div');
    box.className = 'order-details-pop';
    var loading = document.createElement('p');
    loading.className = 'muted';
    loading.textContent = 'Loading…';
    box.appendChild(loading);
    Swal.fire({ title: title, html: box, width: 'min(920px, 96vw)', showConfirmButton: false, showCloseButton: true });

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error('status ' + r.status); return r.text(); })
      .then(function (html) {
        box.textContent = '';
        var link = document.createElement('p');
        link.className = 'order-details-open';
        var a = document.createElement('a');
        a.href = fullUrl;
        a.textContent = 'Open full page';
        link.appendChild(a);
        box.appendChild(link);
        box.insertAdjacentHTML('beforeend', html); // our own server-rendered page, staff only
        var target = scrollTo ? box.querySelector(scrollTo) : null;
        if (target) target.scrollIntoView({ block: 'start' });
      })
      .catch(function () {
        box.textContent = 'Could not load this order. Check your connection and try again.';
      });
  }

  document.addEventListener('click', function (ev) {
    var link = ev.target.closest ? ev.target.closest('a[data-order-details]') : null;
    if (!link || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button) return;
    ev.preventDefault();
    load(link.dataset.orderDetails, link.getAttribute('href'), link.dataset.orderNo, link.dataset.scrollTo);
  });
})();
