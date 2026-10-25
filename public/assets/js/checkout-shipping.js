// Checkout: postage and total follow the chosen state and payment method. The server does the maths; this only shows it.
(function () {
  var form = document.querySelector('.co-form[data-shipping-url]');
  if (!form) return;
  var url = form.getAttribute('data-shipping-url');
  var shipping = document.getElementById('co-shipping');
  var total = document.getElementById('co-total');
  var placeTotal = document.getElementById('co-place-total');
  var bankTotal = document.getElementById('co-bank-total');
  var error = document.getElementById('co-shipping-error');
  var place = document.getElementById('co-place');
  var latest = 0;

  function field(name) {
    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  }

  function refresh() {
    var mine = ++latest;
    var query = '?state=' + encodeURIComponent(field('state')) + '&payment_method=' + encodeURIComponent(field('payment_method'));
    fetch(url + query, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== latest) return; // a newer choice has already been asked for
        if (shipping) shipping.textContent = d.shipping;
        if (total) total.textContent = d.total;
        if (placeTotal) placeTotal.textContent = d.total;
        if (bankTotal) bankTotal.textContent = d.total;
        if (error) {
          error.textContent = d.error || '';
          error.hidden = !d.error;
        }
        if (place) place.disabled = !d.ok;
      })
      .catch(function () {
        if (error) { error.textContent = 'Could not work out the postage. Check your connection and try again.'; error.hidden = false; }
        if (place) place.disabled = true;
      });
  }

  form.addEventListener('change', function (e) {
    if (e.target.name === 'state' || e.target.name === 'payment_method') refresh();
  });
})();
