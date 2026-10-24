// Cart page: change a quantity or remove a line without a page reload. The page region is re-fetched, so the
// server stays the single source of truth for prices, stock warnings and totals.
(function () {
  'use strict';
  var D = document, region = D.querySelector('[data-cart-page]');
  if (!region || !window.Shop) return;
  var Shop = window.Shop, timer = 0, busy = false;

  function refresh(cart) {
    if (cart) Shop.setCart(cart);
    return fetch(location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = doc.querySelector('[data-cart-page]');
      if (fresh) { region.innerHTML = fresh.innerHTML; var h = D.querySelector('.page-head h1'), nh = doc.querySelector('.page-head h1'); if (h && nh) h.innerHTML = nh.innerHTML; Shop.reveal(region); }
    });
  }
  function send() {
    var form = region.querySelector('[data-cart-form]'); if (!form || busy) return;
    busy = true; region.querySelectorAll('.cl').forEach(function (l) { l.classList.add('busy'); });
    var fields = {};
    region.querySelectorAll('input[name^="qty["]').forEach(function (i) { fields[i.name] = i.value; });
    Shop.post(form.action, fields).then(function (r) {
      busy = false;
      if (!r.ok) { Shop.toast((r.data && r.data.message) || 'Could not update your cart.', 'error'); return refresh(); }
      return refresh(r.data.cart).then(function () { if (r.data.capped) Shop.toast(r.data.message, 'error'); });
    });
  }
  region.addEventListener('change', function (e) { if (e.target.matches('input[name^="qty["]')) { clearTimeout(timer); timer = setTimeout(send, 350); } });
  region.addEventListener('click', function (e) {
    var rm = e.target.closest('[data-remove]'); if (!rm) return;
    e.preventDefault();
    var line = rm.closest('.cl'); if (line) line.classList.add('busy');
    Shop.post(rm.getAttribute('formaction'), { item_id: rm.value }).then(function (r) {
      if (!r.ok) { if (line) line.classList.remove('busy'); Shop.toast((r.data && r.data.message) || 'Could not remove that item.', 'error'); return; }
      Shop.toast('Item removed'); refresh(r.data.cart);
    });
  });
})();
