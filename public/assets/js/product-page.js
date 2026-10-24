// Product page: gallery (thumbnails, hover zoom, lightbox), variation pills, quantity, add to cart / buy now, tabs, sticky bar.
(function () {
  'use strict';
  var D = document, pdp = D.querySelector('[data-pdp]');
  if (!pdp) return;
  var $ = function (s, r) { return (r || D).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
  var Shop = window.Shop || {};
  var form = $('[data-pdp-form]'), hasVariants = pdp.dataset.hasVariants === '1', LOW = Number(pdp.dataset.low) || 5;

  // ---- gallery ------------------------------------------------------------------------------------------------
  var gal = $('[data-gallery]'), shots = []; try { shots = JSON.parse(gal.dataset.images || '[]'); } catch (e) { shots = []; }
  var stage = $('[data-stage]'), main = $('#main-img'), at = 0;
  function show(i) {
    if (!shots.length) return;
    at = (i + shots.length) % shots.length;
    stage.classList.add('swap');
    var img = new Image(); img.onload = function () { main.src = shots[at]; stage.classList.remove('swap'); }; img.onerror = function () { stage.classList.remove('swap'); }; img.src = shots[at];
    $$('[data-thumb]', gal).forEach(function (t, k) { var on = k === at; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
  }
  $$('[data-thumb]', gal).forEach(function (t) { t.addEventListener('click', function () { show(Number(t.dataset.thumb)); }); });
  var thumbList = $('.pdp-thumbs', gal);
  if (thumbList) thumbList.addEventListener('keydown', function (e) {
    var k = e.key; if (k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'ArrowLeft' && k !== 'ArrowRight') return;
    e.preventDefault(); show(at + (k === 'ArrowDown' || k === 'ArrowRight' ? 1 : -1)); var t = $('[data-thumb="' + at + '"]', gal); if (t) t.focus();
  });
  gal.addEventListener('click', function (e) { var s = e.target.closest('[data-step]'); if (s) { e.stopPropagation(); show(at + Number(s.dataset.step)); } });
  // hover zoom (mouse only, wide screens)
  if (stage && main && window.matchMedia('(hover:hover) and (min-width:900px)').matches) {
    stage.addEventListener('mousemove', function (e) { var r = stage.getBoundingClientRect(); main.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%'; stage.classList.add('zooming'); });
    stage.addEventListener('mouseleave', function () { stage.classList.remove('zooming'); });
  }
  // lightbox
  var lb = $('#lightbox'), lbImg = $('#lb-img'), lbCount = $('#lb-count');
  function lbShow(i) { at = (i + shots.length) % shots.length; lbImg.src = shots[at]; lbCount.textContent = shots.length > 1 ? (at + 1) + ' / ' + shots.length : ''; show(at); }
  if (lb && shots.length) {
    stage.addEventListener('click', function (e) { if (e.target.closest('[data-step]')) return; if (typeof lb.showModal === 'function') { lbShow(at); lb.showModal(); } });
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.closest('[data-lb-close]')) lb.close(); var s = e.target.closest('[data-lb-step]'); if (s) lbShow(at + Number(s.dataset.lbStep)); });
    lb.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') lbShow(at - 1); else if (e.key === 'ArrowRight') lbShow(at + 1); });
    var sx = null; lb.addEventListener('pointerdown', function (e) { sx = e.clientX; });
    lb.addEventListener('pointerup', function (e) { if (sx !== null && Math.abs(e.clientX - sx) > 60) lbShow(at + (e.clientX < sx ? 1 : -1)); sx = null; });
    if (shots.length < 2) $$('.lb-nav', lb).forEach(function (b) { b.hidden = true; });
  }

  // ---- variations ---------------------------------------------------------------------------------------------
  var pills = $$('.pill[data-variant]', pdp), chosen = null;
  var nowEl = $('#price-now'), wasEl = $('#price-was'), saveEl = $('#price-save'), hint = $('#price-hint'), stockEl = $('#stock-note'), skuEl = $('#spec-sku'), stickyPrice = $('#sticky-price');
  var qtyInput = form ? $('input[name="qty"]', form) : null, msg = $('[data-choose-msg]', pdp), hidden = null;
  function setStock(n) {
    if (!stockEl) return;
    var state = n < 1 ? 'out' : n <= LOW ? 'low' : 'ok';
    stockEl.dataset.state = state; stockEl.textContent = n < 1 ? 'Sold out' : n <= LOW ? 'Only ' + n + ' left: order soon' : 'In stock';
    if (qtyInput) { var max = Math.max(1, Math.min(99, n)); qtyInput.max = String(max); if (Number(qtyInput.value) > max) qtyInput.value = String(max); }
  }
  function choose(pill) {
    if (!pill || pill.getAttribute('aria-disabled') === 'true') return;
    chosen = pill;
    pills.forEach(function (p) { p.setAttribute('aria-pressed', p === pill ? 'true' : 'false'); });
    $$('[data-chosen]', pdp).forEach(function (c) { c.hidden = true; });
    var chosenLabel = pill.closest('.opt-group').querySelector('[data-chosen]'); chosenLabel.textContent = ': ' + pill.dataset.label; chosenLabel.hidden = false;
    if (!hidden) { hidden = D.createElement('input'); hidden.type = 'hidden'; hidden.name = 'variant_id'; form.appendChild(hidden); }
    hidden.value = pill.dataset.variant;
    nowEl.textContent = pill.dataset.price; nowEl.classList.toggle('sale', pill.dataset.sale === '1');
    wasEl.textContent = pill.dataset.retail; wasEl.hidden = pill.dataset.sale !== '1';
    var d = Number(pill.dataset.discount); saveEl.textContent = 'Save ' + d + '%'; saveEl.hidden = !(d > 0);
    if (hint) hint.hidden = true;
    if (stickyPrice) stickyPrice.textContent = pill.dataset.price;
    if (skuEl) skuEl.textContent = pill.dataset.sku || '—';
    setStock(Number(pill.dataset.stock));
    if (msg) msg.hidden = true;
  }
  pills.forEach(function (p) { p.addEventListener('click', function () { choose(p); }); });
  if (hasVariants) { var live = pills.filter(function (p) { return p.getAttribute('aria-disabled') !== 'true'; }); if (pills.length === 1 && live.length === 1) choose(live[0]); }

  // ---- add to cart / buy now ----------------------------------------------------------------------------------
  function needOption() {
    if (!hasVariants || chosen) return false;
    var g = $('[data-variants]', pdp); if (msg) msg.hidden = false;
    if (g) { g.classList.remove('shake'); void g.offsetWidth; g.classList.add('shake'); g.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    return true;
  }
  function fields() { var f = { product_id: pdp.dataset.product, qty: qtyInput ? qtyInput.value : 1 }; if (chosen) f.variant_id = chosen.dataset.variant; return f; }
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (needOption()) return;
    var btn = e.submitter && e.submitter.hasAttribute('data-pdp-add') ? e.submitter : $('[data-pdp-add]', form);
    Shop.addToCart(fields(), btn, main);
  });
  var buy = $('[data-buy-now]');
  if (buy) buy.addEventListener('click', function () {
    if (needOption()) return;
    buy.classList.add('loading');
    Shop.addToCart(fields(), null, null).then(function (ok) { if (ok) location.href = D.body.dataset.checkoutUrl; else buy.classList.remove('loading'); }); // no flight: the page changes at once
  });

  // sticky bar on phones once the real buttons scroll out of view
  var sticky = $('[data-sticky]'), actions = $('[data-actions]');
  if (sticky && actions && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (es) { var vis = es[0].isIntersecting; sticky.hidden = vis; D.body.classList.toggle('has-sticky', !vis); }, { rootMargin: '0px 0px -10px 0px' }).observe(actions);
    $('[data-sticky-add]', sticky).addEventListener('click', function () { if (needOption()) return; Shop.addToCart(fields(), null, main || $('[data-sticky-add]', sticky)); });
  }

  // ---- tabs ---------------------------------------------------------------------------------------------------
  var tabs = $('[data-tabs]');
  if (tabs) {
    var btns = $$('[data-tab]', tabs);
    var pick = function (b) { btns.forEach(function (x) { var on = x === b; x.setAttribute('aria-selected', on ? 'true' : 'false'); $('[data-panel="' + x.dataset.tab + '"]', tabs).hidden = !on; }); };
    btns.forEach(function (b) { b.addEventListener('click', function () { pick(b); }); });
    $('.tab-list', tabs).addEventListener('keydown', function (e) {
      var i = btns.indexOf(D.activeElement); if (i < 0 || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
      e.preventDefault(); var n = btns[(i + (e.key === 'ArrowRight' ? 1 : -1) + btns.length) % btns.length]; n.focus(); pick(n);
    });
  }
})();
