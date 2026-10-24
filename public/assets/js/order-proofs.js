// Order list: the eye button opens the customer's latest bank transfer receipt in a SweetAlert popup.
(function () {
  'use strict';
  if (!window.Swal) return;

  function line(label, value) {
    var p = document.createElement('p');
    p.className = 'proof-view-line';
    var b = document.createElement('strong');
    b.textContent = label + ' ';
    p.appendChild(b);
    p.appendChild(document.createTextNode(value));
    return p;
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-proof-view]') : null;
    if (!btn) return;
    ev.preventDefault();
    var d = btn.dataset;
    var wrap = document.createElement('div');
    wrap.className = 'proof-view';
    wrap.appendChild(line('Bank:', d.proofBank));
    wrap.appendChild(line('Paid on:', d.proofPaid));
    wrap.appendChild(line('Sent on:', d.proofSent));
    wrap.appendChild(line('Amount to match:', d.proofAmount));
    wrap.appendChild(line('Status:', d.proofStatus.replace(/_/g, ' ')));

    var media;
    if (d.proofMime.indexOf('image/') === 0) {
      media = document.createElement('img');
      media.alt = 'Payment receipt';
    } else {
      media = document.createElement('iframe');
      media.title = 'Payment receipt';
    }
    media.className = 'proof-view-media';
    media.src = d.proofView;
    wrap.appendChild(media);

    var open = document.createElement('a');
    open.className = 'btn secondary';
    open.href = d.proofView;
    open.target = '_blank';
    open.rel = 'noopener';
    open.textContent = 'Open in a new tab';
    wrap.appendChild(open);

    Swal.fire({ title: 'Payment receipt', html: wrap, width: 'min(860px, 94vw)', showConfirmButton: false, showCloseButton: true });
  });
})();
