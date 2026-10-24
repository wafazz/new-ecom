// Product form images: drag & drop (or click) to add, drag thumbnails to reorder (first = main image), × to remove.
// Thumbnails are drawn on <canvas>; big photos are resized/re-encoded on a canvas so they fit the 2 MB limit.
// The browser's own file input (images[]) is filled from the list, so both the popup (AJAX) and plain form posts just work.
(function () {
  'use strict';
  var TYPES = ['image/jpeg', 'image/png', 'image/webp'], MAX_BYTES = 2 * 1024 * 1024, MAX_SIDE = 2000, THUMB = 112;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var toast = function (m, t) { if (window.Portal) window.Portal.toast(m, t || 'error'); };
  var dpr = Math.min(2, window.devicePixelRatio || 1);
  var canWebp = (function () { try { return document.createElement('canvas').toDataURL('image/webp').indexOf('data:image/webp') === 0; } catch (e) { return false; } })();
  var canFill = (function () { try { new DataTransfer(); return true; } catch (e) { return false; } })();

  function readDataUrl(file) {
    return new Promise(function (ok, bad) { var r = new FileReader(); r.onload = function () { ok(r.result); }; r.onerror = function () { bad(new Error('read')); }; r.readAsDataURL(file); });
  }
  function loadImage(src) {
    return new Promise(function (ok, bad) { var i = new Image(); i.onload = function () { ok(i); }; i.onerror = function () { bad(new Error('decode')); }; i.src = src; });
  }
  function toBlob(canvas, type, q) { return new Promise(function (ok) { canvas.toBlob(ok, type, q); }); }

  /** Re-encode on a canvas, lowering quality until it fits. Resolves a Blob or rejects. */
  function encode(img, w, h, type) {
    var c = document.createElement('canvas'); c.width = w; c.height = h;
    var g = c.getContext('2d');
    if (type === 'image/jpeg') { g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); } // JPEG has no transparency
    g.drawImage(img, 0, 0, w, h);
    var qs = type === 'image/png' ? [undefined] : [0.9, 0.8, 0.7, 0.6, 0.5];
    function next(i) {
      return toBlob(c, type, qs[i]).then(function (b) {
        if (b && b.size <= MAX_BYTES) return b;
        if (i + 1 < qs.length) return next(i + 1);
        throw new Error('too large');
      });
    }
    return next(0);
  }

  /** @return Promise<{file: File, img: HTMLImageElement}> the original when it is small enough, else a canvas-resized copy */
  function prepare(file) {
    return readDataUrl(file).then(loadImage).then(function (img) {
      var w = img.naturalWidth, h = img.naturalHeight;
      if (!w || !h) throw new Error('decode');
      if (Math.max(w, h) <= MAX_SIDE && file.size <= MAX_BYTES) return { file: file, img: img };
      var s = Math.min(1, MAX_SIDE / Math.max(w, h)), cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
      var primary = canWebp ? 'image/webp' : (file.type === 'image/png' ? 'image/png' : 'image/jpeg');
      return encode(img, cw, ch, primary).catch(function () { return encode(img, cw, ch, 'image/jpeg'); }).then(function (blob) {
        var ext = blob.type === 'image/webp' ? '.webp' : blob.type === 'image/png' ? '.png' : '.jpg';
        return { file: new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'image') + ext, { type: blob.type }), img: img };
      });
    });
  }

  function drawThumb(canvas, img) {
    var T = THUMB * dpr, g = canvas.getContext('2d');
    var s = Math.max(T / img.naturalWidth, T / img.naturalHeight), sw = T / s, sh = T / s;
    g.clearRect(0, 0, T, T);
    g.drawImage(img, (img.naturalWidth - sw) / 2, (img.naturalHeight - sh) / 2, sw, sh, 0, 0, T, T); // cover-fit crop
  }

  function init(root) {
    $$('[data-images]', root || document).forEach(function (fs) {
      if (fs.dataset.imgReady || !canFill) return; // without DataTransfer the plain file input stays visible and works
      fs.dataset.imgReady = '1';
      var max = Number(fs.dataset.max) || 5;
      var zone = $('[data-img-drop]', fs), tiles = $('[data-img-tiles]', fs), hint = $('[data-img-hint]', fs), count = $('[data-img-count]', fs);
      var picker = $('[data-img-picker]', fs), input = $('[data-img-input]', fs);
      var items = [], dragFrom = null, form = fs.closest('form');
      try { JSON.parse(fs.dataset.existing || '[]').forEach(function (e) { items.push({ kind: 'e', id: e.id, url: e.url, img: null }); }); } catch (e) { /* none */ }
      zone.hidden = false;

      function sync() {
        $$('input[name="image_order[]"]', fs).forEach(function (n) { n.remove(); });
        var dt = new DataTransfer(), n = 0;
        items.forEach(function (it) {
          var h = document.createElement('input'); h.type = 'hidden'; h.name = 'image_order[]';
          if (it.kind === 'e') h.value = 'e' + it.id; else { h.value = 'n' + n++; dt.items.add(it.file); }
          fs.appendChild(h);
        });
        input.files = dt.files;
        // Plain `images[]` without image_order[] would mean "keep everything": make sure an emptied list still sends an order.
        if (!items.length) { var e = document.createElement('input'); e.type = 'hidden'; e.name = 'image_order[]'; e.value = ''; fs.appendChild(e); }
        if (form) form.dispatchEvent(new Event('input', { bubbles: true }));
      }

      function render(focusIndex) {
        tiles.textContent = '';
        items.forEach(function (it, i) {
          var t = document.createElement('div'); t.className = 'img-tile'; t.draggable = true; t.tabIndex = 0; t.dataset.i = String(i);
          t.setAttribute('aria-label', 'Image ' + (i + 1) + (i === 0 ? ' (main)' : '') + '. Alt+Left or Alt+Right to move, Delete to remove.');
          var c = document.createElement('canvas'); c.width = THUMB * dpr; c.height = THUMB * dpr; c.className = 'img-thumb'; c.setAttribute('aria-hidden', 'true');
          t.appendChild(c);
          if (i === 0) { var b = document.createElement('span'); b.className = 'img-badge'; b.textContent = 'Main'; t.appendChild(b); }
          var x = document.createElement('button'); x.type = 'button'; x.className = 'img-x'; x.setAttribute('aria-label', 'Remove image ' + (i + 1)); x.textContent = '×'; t.appendChild(x);
          tiles.appendChild(t);
          if (it.img) drawThumb(c, it.img);
          else loadImage(it.url).then(function (img) { it.img = img; drawThumb(c, img); }).catch(function () { t.classList.add('broken'); });
        });
        if (items.length < max) {
          var add = document.createElement('div'); add.className = 'img-tile img-add'; add.setAttribute('aria-hidden', 'true'); add.textContent = '+'; tiles.appendChild(add);
        }
        count.textContent = items.length + ' / ' + max;
        hint.textContent = items.length >= max ? 'Maximum of ' + max + ' images reached. Remove one to add another.' : 'Drag & drop images here, or click to browse.';
        zone.classList.toggle('full', items.length >= max);
        if (focusIndex != null) { var f = $('.img-tile[data-i="' + focusIndex + '"]', tiles); if (f) f.focus(); }
      }

      function change(focusIndex) { render(focusIndex); sync(); }

      function addFiles(list) {
        var files = Array.prototype.slice.call(list || []);
        var ok = files.filter(function (f) { return TYPES.indexOf(f.type) >= 0; });
        if (files.length && !ok.length) { toast('Only JPG, PNG or WebP images are allowed.'); return; }
        if (ok.length < files.length) toast('Some files were skipped: only JPG, PNG or WebP images are allowed.');
        var room = max - items.length;
        if (room <= 0) { toast('A product can have at most ' + max + ' images. Remove one first.'); return; }
        if (ok.length > room) { toast('Only ' + room + ' more image' + (room === 1 ? '' : 's') + ' fit (max ' + max + '). The rest were skipped.'); ok = ok.slice(0, room); }
        fs.classList.add('busy');
        Promise.all(ok.map(function (f) { return prepare(f).catch(function () { toast(f.name + ': could not be read or reduced under 2 MB.'); return null; }); })).then(function (res) {
          res.filter(Boolean).slice(0, max - items.length).forEach(function (r) { items.push({ kind: 'n', file: r.file, img: r.img }); });
          fs.classList.remove('busy');
          change();
        });
      }

      function move(from, to) {
        if (from === to || to < 0 || to >= items.length) return;
        items.splice(to, 0, items.splice(from, 1)[0]);
        change(to);
      }

      zone.addEventListener('click', function (e) {
        var x = e.target.closest('.img-x');
        if (x) { e.stopPropagation(); var i = Number(x.closest('.img-tile').dataset.i); items.splice(i, 1); change(Math.min(i, items.length - 1) >= 0 ? Math.min(i, items.length - 1) : null); return; }
        if (e.target.closest('.img-tile:not(.img-add)')) return;
        if (items.length < max) picker.click(); else toast('Maximum of ' + max + ' images reached. Remove one to add another.');
      });
      zone.addEventListener('keydown', function (e) {
        var tile = e.target.closest('.img-tile:not(.img-add)');
        if (!tile) { if ((e.key === 'Enter' || e.key === ' ') && e.target === zone) { e.preventDefault(); if (items.length < max) picker.click(); } return; }
        var i = Number(tile.dataset.i);
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); items.splice(i, 1); change(items.length ? Math.min(i, items.length - 1) : null); }
        else if (e.altKey && e.key === 'ArrowLeft') { e.preventDefault(); move(i, i - 1); }
        else if (e.altKey && e.key === 'ArrowRight') { e.preventDefault(); move(i, i + 1); }
      });
      picker.addEventListener('change', function () { addFiles(picker.files); picker.value = ''; });

      // Files dragged in from the desktop.
      var hasFiles = function (e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0; };
      ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { if (hasFiles(e)) { e.preventDefault(); zone.classList.add('over'); } }); });
      zone.addEventListener('dragleave', function (e) { if (!zone.contains(e.relatedTarget)) zone.classList.remove('over'); });
      zone.addEventListener('drop', function (e) { zone.classList.remove('over'); if (hasFiles(e)) { e.preventDefault(); addFiles(e.dataTransfer.files); } });

      // Thumbnails dragged to reorder.
      tiles.addEventListener('dragstart', function (e) {
        var t = e.target.closest && e.target.closest('.img-tile:not(.img-add)'); if (!t) return;
        dragFrom = Number(t.dataset.i); t.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'image-' + dragFrom);
      });
      tiles.addEventListener('dragover', function (e) {
        if (dragFrom == null) return;
        var t = e.target.closest('.img-tile:not(.img-add)'); if (!t) return;
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        $$('.drop-target', tiles).forEach(function (n) { n.classList.remove('drop-target'); }); t.classList.add('drop-target');
      });
      tiles.addEventListener('drop', function (e) {
        if (dragFrom == null) return;
        var t = e.target.closest('.img-tile:not(.img-add)'); if (!t) return;
        e.preventDefault(); e.stopPropagation(); var from = dragFrom; dragFrom = null; move(from, Number(t.dataset.i));
      });
      tiles.addEventListener('dragend', function () { dragFrom = null; $$('.dragging,.drop-target', tiles).forEach(function (n) { n.classList.remove('dragging', 'drop-target'); }); });

      change();
    });
  }

  window.ProductImages = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
