// Storefront behaviour shared by every page: theme, header, drawers, live search, cart drawer, add-to-cart, wishlist, toasts.
// Everything degrades: without JavaScript the same buttons are plain forms and links.
(function () {
  'use strict';
  var D = document, B = D.body, root = D.documentElement;
  var $ = function (s, r) { return (r || D).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); };
  root.classList.add('js');
  var cfg = { member: B.dataset.member === '1', login: B.dataset.login, mini: B.dataset.mini, suggest: B.dataset.suggest, cartUrl: B.dataset.cartUrl, checkoutUrl: B.dataset.checkoutUrl };
  var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function el(tag, cls, text) { var n = D.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function icon(name, cls) {
    var s = D.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', cls || 'i'); s.setAttribute('aria-hidden', 'true');
    var u = D.createElementNS('http://www.w3.org/2000/svg', 'use'); u.setAttribute('href', '#i-' + name); s.appendChild(u); return s;
  }
  function token() { var m = $('meta[name="csrf-token"]'); return m ? m.content : ''; }

  /** POST as the browser would, asking for JSON. Always resolves to {ok, status, data}. */
  function post(url, fields) {
    var fd = new FormData(); fd.append('_token', token());
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok && d.ok !== false, status: r.status, data: d }; }); },
        function () { return { ok: false, status: 0, data: { message: 'Network problem. Check your connection and try again.' } }; });
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
      .then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  // ---- toasts ------------------------------------------------------------------------------------------------
  var toastBox = $('#s-toasts');
  function toast(msg, type, action) {
    if (!toastBox) return;
    var t = el('div', 's-toast' + (type === 'error' ? ' err' : '')); t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var ic = el('span', 't-ico'); ic.appendChild(icon(type === 'error' ? 'x' : 'check', 'i i-sm')); t.appendChild(ic);
    t.appendChild(el('span', '', msg));
    if (action) {
      var a = el('a', '', action.label); a.href = action.href || '#';
      if (action.run) a.addEventListener('click', function (e) { e.preventDefault(); action.run(); t.remove(); });
      t.appendChild(a);
    }
    toastBox.appendChild(t);
    while (toastBox.children.length > 3) toastBox.firstChild.remove();
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 220); }, type === 'error' ? 6000 : 3800);
  }

  // ---- theme, header, back to top ----------------------------------------------------------------------------
  var themeBtn = $('[data-theme-toggle]');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next; try { localStorage.setItem('theme', next); } catch (e) { /* private mode */ }
  });
  var header = $('#s-header'), topBtn = $('[data-top]');
  function onScroll() {
    var y = window.scrollY || 0;
    if (header) header.classList.toggle('scrolled', y > 8);
    if (topBtn) topBtn.hidden = y < 700;
  }
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  if (topBtn) topBtn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' }); });

  // account dropdown (<details>) closes on outside click / Esc
  D.addEventListener('click', function (e) { $$('details.s-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
  D.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('details.s-menu[open]').forEach(function (d) { d.removeAttribute('open'); d.querySelector('summary').focus(); }); });

  // ---- drawers (mobile menu, cart) ---------------------------------------------------------------------------
  var lastFocus = null;
  function drawerOpen(name) {
    var d = $(name === 'cart' ? '#cart-drawer' : '#s-menu'); if (!d) return;
    lastFocus = D.activeElement;
    B.classList.add('drawer-open', name + '-open'); d.setAttribute('aria-hidden', 'false');
    setTimeout(function () { var f = d.querySelector('button, a, input'); if (f) f.focus(); }, 60);
  }
  function drawerClose() {
    B.classList.remove('drawer-open', 'cart-open', 'menu-open');
    $$('#cart-drawer, #s-menu').forEach(function (d) { d.setAttribute('aria-hidden', 'true'); });
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  D.addEventListener('click', function (e) {
    if (e.target.closest('[data-menu-open]')) drawerOpen('menu');
    else if (e.target.closest('[data-cart-open]')) { loadMini(); drawerOpen('cart'); }
    else if (e.target.closest('[data-menu-close], [data-cart-close], [data-backdrop]')) drawerClose();
    else if (e.target.closest('#s-menu a')) drawerClose();
  });
  D.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && (B.classList.contains('cart-open') || B.classList.contains('menu-open'))) drawerClose();
    if (e.key === 'Tab' && B.classList.contains('cart-open')) { // keep Tab inside the open cart
      var d = $('#cart-drawer'), f = $$('button:not([disabled]), a[href], input', d); if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && D.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && D.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // ---- cart state + drawer -----------------------------------------------------------------------------------
  var cartBadges = $$('[data-cart-count]');
  function setCount(n) {
    cartBadges.forEach(function (b) {
      var changed = b.textContent !== String(n);
      b.textContent = String(n); b.hidden = n < 1;
      if (changed && !reduced) { b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
    });
  }
  function qtyStepper(item, qty, stock) {
    var wrap = el('div', 'qty qty-sm'); wrap.setAttribute('data-qty', '');
    var minus = el('button'); minus.type = 'button'; minus.setAttribute('aria-label', 'Decrease quantity'); minus.appendChild(icon('minus'));
    var inp = el('input'); inp.type = 'number'; inp.min = '1'; inp.max = String(Math.max(1, Math.min(99, stock || 99))); inp.value = String(qty); inp.setAttribute('aria-label', 'Quantity'); inp.inputMode = 'numeric';
    var plus = el('button'); plus.type = 'button'; plus.setAttribute('aria-label', 'Increase quantity'); plus.appendChild(icon('plus'));
    var apply = function (n) { n = Math.max(1, Math.min(Number(inp.max), n)); if (n !== qty) updateLine(item, n, wrap.closest('.mc-line')); else inp.value = String(qty); };
    minus.addEventListener('click', function () { apply(Number(inp.value) - 1); });
    plus.addEventListener('click', function () { apply(Number(inp.value) + 1); });
    inp.addEventListener('change', function () { apply(parseInt(inp.value, 10) || 1); });
    wrap.appendChild(minus); wrap.appendChild(inp); wrap.appendChild(plus);
    return wrap;
  }
  function renderCart(cart) {
    var body = $('#cart-lines'), foot = $('#cart-foot'), title = $('[data-cart-title-count]');
    if (!body) return;
    body.textContent = '';
    if (title) title.textContent = cart.count > 0 ? '(' + cart.count + ')' : '';
    if (!cart.items.length) {
      var empty = el('div', 'mc-empty'); empty.appendChild(icon('bag', 'i i-xl'));
      empty.appendChild(el('p', '', 'Your cart is empty.'));
      var go = el('a', 's-btn', 'Start shopping'); go.href = cfg.cartUrl.replace(/cart$/, 'products'); empty.appendChild(go);
      body.appendChild(empty); foot.hidden = true; return;
    }
    cart.items.forEach(function (it) {
      var line = el('div', 'mc-line' + (it.available ? '' : ' bad')); line.dataset.item = it.item_id;
      var a = el('a'); a.href = it.href;
      if (it.image) { var img = el('img'); img.src = it.image; img.alt = ''; img.loading = 'lazy'; a.appendChild(img); } else { var ph = el('span', 'mc-ph'); ph.appendChild(icon('box')); a.appendChild(ph); }
      line.appendChild(a);
      var info = el('div'); var name = el('a', 'mc-name', it.name); name.href = it.href; info.appendChild(name);
      if (it.variant) info.appendChild(el('span', 'mc-var', it.variant));
      if (!it.available) info.appendChild(el('span', 'mc-warn', 'No longer available'));
      else if (it.adjusted) info.appendChild(el('span', 'mc-warn', 'Only ' + it.stock + ' in stock'));
      var row = el('div', 'mc-row');
      if (it.available) row.appendChild(qtyStepper(it.item_id, it.qty, it.stock));
      var rm = el('button', 'mc-rm'); rm.type = 'button'; rm.setAttribute('aria-label', 'Remove ' + it.name); rm.appendChild(icon('trash'));
      rm.addEventListener('click', function () { removeLine(it.item_id, line); });
      row.appendChild(rm); info.appendChild(row); line.appendChild(info);
      line.appendChild(el('span', 'mc-price', it.line));
      body.appendChild(line);
    });
    foot.textContent = ''; foot.hidden = false;
    var s1 = el('p', 'sum-row'); s1.appendChild(el('span', '', 'Subtotal')); s1.appendChild(el('span', '', cart.subtotal)); foot.appendChild(s1);
    if (cart.shipping) foot.appendChild(el('small', 'muted', 'Flat-rate shipping ' + cart.shipping + ' is added at checkout.'));
    var view = el('a', 's-btn ghost block', 'View cart'); view.href = cfg.cartUrl; foot.appendChild(view);
    var co = el('a', 's-btn block' + (cart.has_problem ? ' disabled' : ''), 'Checkout'); co.href = cfg.checkoutUrl; if (cart.has_problem) co.setAttribute('aria-disabled', 'true'); co.appendChild(icon('arrow')); foot.appendChild(co);
    if (cart.has_problem) foot.appendChild(el('small', 'mc-warn', 'Review the items marked above to check out.'));
  }
  function setCart(cart) { if (!cart) return; setCount(cart.count); renderCart(cart); }
  function loadMini() { return getJSON(cfg.mini).then(function (d) { if (d && d.cart) setCart(d.cart); }); }
  function updateLine(item, qty, lineEl) {
    if (lineEl) lineEl.classList.add('busy');
    var f = {}; f['qty[' + item + ']'] = qty;
    post(cfg.cartUrl.replace(/cart$/, 'cart/update'), f).then(function (r) {
      if (r.ok) { setCart(r.data.cart); if (r.data.capped) toast(r.data.message, 'error'); } else { if (lineEl) lineEl.classList.remove('busy'); toast(r.data.message || 'Could not update the cart.', 'error'); }
    });
  }
  function removeLine(item, lineEl) {
    if (lineEl) lineEl.classList.add('busy');
    post(cfg.cartUrl.replace(/cart$/, 'cart/remove'), { item_id: item }).then(function (r) {
      if (r.ok) setCart(r.data.cart); else { if (lineEl) lineEl.classList.remove('busy'); toast(r.data.message || 'Could not remove the item.', 'error'); }
    });
  }

  /**
   * Fly a copy of the product photo (or a small bag if there is none) from `src` to the cart button in the header.
   * Resolves when it lands, straight away when motion is off or there is nothing to animate.
   */
  function flyToCart(src) {
    return new Promise(function (done) {
      var target = $$('.s-cart-btn').filter(function (b) { var r = b.getBoundingClientRect(); return r.width > 0 && r.height > 0; })[0];
      if (!src || !target || reduced || !src.getBoundingClientRect || typeof Element.prototype.animate !== 'function') { done(); return; }
      var s = src.getBoundingClientRect(), t = target.getBoundingClientRect();
      if (!s.width || !s.height || s.bottom < 0 || s.top > window.innerHeight) { s = target.getBoundingClientRect(); s = { left: s.left + 40, top: s.top + 80, width: 40, height: 40 }; } // source off screen: drop in from just below the cart
      var size = Math.round(Math.max(56, Math.min(120, Math.min(s.width, s.height) * 0.55)));
      var ghost = el('div', 'fly');
      var url = src.tagName === 'IMG' ? (src.currentSrc || src.src) : '';
      if (url) ghost.style.backgroundImage = 'url(' + JSON.stringify(url) + ')'; else ghost.appendChild(icon('bag', 'i i-lg'));
      var cx = s.left + s.width / 2, cy = s.top + s.height / 2;
      ghost.style.cssText += ';width:' + size + 'px;height:' + size + 'px;left:' + (cx - size / 2) + 'px;top:' + (cy - size / 2) + 'px';
      D.body.appendChild(ghost);
      var dx = t.left + t.width / 2 - cx, dy = t.top + t.height / 2 - cy;
      var rise = Math.min(90, Math.abs(dy) * 0.25 + 40); // a little arc: up first, then down into the cart
      var finish = function () { ghost.remove(); target.classList.remove('pulse'); void target.offsetWidth; target.classList.add('pulse'); done(); };
      var a = ghost.animate([
        { transform: 'translate(0,0) scale(1)', opacity: 1, offset: 0 },
        { transform: 'translate(' + dx * 0.5 + 'px,' + (dy * 0.5 - rise) + 'px) scale(.7)', opacity: 1, offset: 0.5 },
        { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.14)', opacity: 0.35, offset: 1 }
      ], { duration: 780, easing: 'cubic-bezier(.45,.05,.55,.95)' });
      a.onfinish = finish; a.oncancel = finish;
    });
  }

  /** Add to cart without leaving the page. `source` is the element the photo flies from. Resolves to true when it worked. */
  function addToCart(fields, btn, source) {
    if (btn) btn.classList.add('loading');
    return post(cfg.cartUrl.replace(/cart$/, 'cart/add'), fields).then(function (r) {
      if (btn) btn.classList.remove('loading');
      if (!r.ok) { toast((r.data && r.data.message) || 'Could not add that to your cart.', 'error'); return false; }
      toast('Added to your cart', 'ok', { label: 'View cart', run: function () { drawerOpen('cart'); } });
      flyToCart(source || btn).then(function () { setCart(r.data.cart); }); // the badge and drawer update as the photo lands
      return true;
    });
  }

  // quick-add buttons on product cards (they are real forms too)
  D.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('.pcard-quick-form'); if (!f) return;
    e.preventDefault();
    var btn = $('button', f), card = f.closest('.pcard');
    addToCart({ product_id: f.elements.product_id.value, qty: f.elements.qty ? f.elements.qty.value : 1 }, btn, card && $('.pcard-img', card));
  });

  // ---- wishlist hearts (cards and product page) --------------------------------------------------------------
  var wishBadge = $('[data-wish-count]');
  D.addEventListener('click', function (e) {
    var b = e.target.closest('[data-wish]'); if (!b) return;
    e.preventDefault();
    if (!cfg.member) { toast('Log in to save products to your wishlist.', 'ok', { label: 'Log in', href: cfg.login }); return; }
    var id = b.dataset.wish;
    post(B.dataset.home.replace(/\/?$/, '/') + 'wishlist/toggle', { product_id: id }).then(function (r) {
      if (!r.ok) { if (r.status === 401 || (r.data && r.data.login)) { location.href = cfg.login; return; } toast((r.data && r.data.message) || 'Could not update your wishlist.', 'error'); return; }
      $$('[data-wish="' + id + '"]').forEach(function (h) {
        h.classList.toggle('on', !!r.data.added); h.setAttribute('aria-pressed', r.data.added ? 'true' : 'false');
        h.setAttribute('aria-label', (r.data.added ? 'Remove from wishlist' : 'Save to wishlist'));
        if (!reduced) { h.classList.remove('pop'); void h.offsetWidth; h.classList.add('pop'); }
      });
      if (wishBadge && typeof r.data.count === 'number') { wishBadge.textContent = String(r.data.count); wishBadge.hidden = r.data.count < 1; }
      toast(r.data.message || (r.data.added ? 'Saved to your wishlist.' : 'Removed from your wishlist.'));
    });
  });

  // ---- copy buttons (bank account number, order reference) ----------------------------------------------------
  if (navigator.clipboard && window.isSecureContext) $$('[data-copy]').forEach(function (b) { b.hidden = false; });
  D.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    var src = b.parentNode.querySelector('[data-copy-text]'); if (!src) return;
    navigator.clipboard.writeText(src.textContent.trim()).then(function () { toast('Copied: ' + src.textContent.trim()); b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); },
      function () { toast('Could not copy: select the text instead.', 'error'); });
  });

  // ---- quantity steppers (product page, cart page): buttons around a number input -----------------------------
  D.addEventListener('click', function (e) {
    var s = e.target.closest('[data-qty-step]'); if (!s) return;
    var wrap = s.closest('[data-qty]'), inp = wrap && $('input', wrap); if (!inp) return;
    if (wrap.closest('#cart-drawer')) return; // the drawer has its own handlers
    var min = inp.min === '' ? 1 : Number(inp.min), max = inp.max === '' ? 99 : Number(inp.max);
    var n = Math.max(min, Math.min(max, (parseInt(inp.value, 10) || min) + Number(s.dataset.qtyStep)));
    if (String(n) !== inp.value) { inp.value = String(n); inp.dispatchEvent(new Event('input', { bubbles: true })); inp.dispatchEvent(new Event('change', { bubbles: true })); }
  });

  // ---- live search suggestions -------------------------------------------------------------------------------
  var sForm = $('[data-search]');
  if (sForm && cfg.suggest) {
    var sIn = sForm.elements.q, box = $('#s-suggest'), timer = 0, ctl = null, rows = [], at = -1;
    var close = function () { box.hidden = true; sIn.setAttribute('aria-expanded', 'false'); at = -1; };
    var mark = function () { rows.forEach(function (r, i) { r.setAttribute('aria-selected', i === at ? 'true' : 'false'); }); if (rows[at]) rows[at].scrollIntoView({ block: 'nearest' }); };
    var render = function (d) {
      box.textContent = ''; rows = []; at = -1;
      if (!d.items.length) { box.appendChild(el('p', 'sg-empty', 'No products found for “' + d.q + '”.')); }
      d.items.forEach(function (it) {
        var a = el('a'); a.href = it.href; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', 'false');
        if (it.image) { var img = el('img'); img.src = it.image; img.alt = ''; a.appendChild(img); } else { var ph = el('span', 'sg-ph'); ph.appendChild(icon('box')); a.appendChild(ph); }
        var t = el('span'); t.appendChild(el('span', 'sg-name', it.name));
        var m = el('span', 'sg-meta', (it.brand ? it.brand + ' · ' : '') + (it.sold_out ? 'Sold out' : it.price));
        if (it.was && !it.sold_out) m.appendChild(el('s', '', it.was));
        t.appendChild(m); a.appendChild(t); box.appendChild(a); rows.push(a);
      });
      if (d.total > 0) { var all = el('a', 'sg-all'); all.href = d.all; all.setAttribute('role', 'option'); all.appendChild(el('span', '', 'See all ' + d.total + ' result' + (d.total === 1 ? '' : 's'))); all.appendChild(icon('arrow', 'i i-sm')); box.appendChild(all); rows.push(all); }
      box.hidden = false; sIn.setAttribute('aria-expanded', 'true');
    };
    sIn.addEventListener('input', function () {
      clearTimeout(timer); var q = sIn.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function () {
        if (ctl) ctl.abort(); ctl = window.AbortController ? new AbortController() : null;
        fetch(cfg.suggest + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined, headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); }).then(function (d) { if (sIn.value.trim() === q) render(d); }).catch(function () { /* aborted or offline */ });
      }, 180);
    });
    sIn.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); at = (at + 1) % rows.length; mark(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); at = (at - 1 + rows.length) % rows.length; mark(); }
      else if (e.key === 'Enter' && at >= 0) { e.preventDefault(); location.href = rows[at].href; }
      else if (e.key === 'Escape') { close(); }
    });
    sIn.addEventListener('focus', function () { if (rows.length && sIn.value.trim().length >= 2) { box.hidden = false; sIn.setAttribute('aria-expanded', 'true'); } });
    D.addEventListener('click', function (e) { if (!sForm.contains(e.target)) close(); });
  }

  // ---- reveal on scroll --------------------------------------------------------------------------------------
  var io = null;
  function reveal(scope) {
    if (reduced || !('IntersectionObserver' in window)) return;
    if (!io) io = new IntersectionObserver(function (es) { es.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }); }, { rootMargin: '0px 0px -40px 0px' });
    $$('.pcard, .cat-tile, .sec-head, .usp > div, .brand-chip, .cta-band, .co-card', scope || D).forEach(function (n) {
      if (n.classList.contains('reveal')) return;
      var r = n.getBoundingClientRect();
      if (r.top < window.innerHeight && r.bottom > 0) return; // already on screen: no flash
      n.classList.add('reveal'); io.observe(n);
    });
  }
  reveal();

  window.Shop = { toast: toast, post: post, getJSON: getJSON, addToCart: addToCart, flyToCart: flyToCart, setCart: setCart, openCart: function () { loadMini(); drawerOpen('cart'); }, reveal: reveal, el: el, icon: icon };

  // the badge in the header is server-rendered; fill the drawer lazily on first open
  if (B.classList.contains('shop') && location.hash === '#cart') { loadMini(); drawerOpen('cart'); }
})();
