// Shop logo: the image is drawn on a canvas (resized, transparency kept) and shown in the drop box.
// The chosen file goes into the form's own file input, so the normal upload runs. The input itself is hidden.
(function () {
  'use strict';
  var form = document.querySelector('[data-logo-form]');
  if (!form) return;
  var input = form.querySelector('[data-logo-input]');
  var zone = form.querySelector('[data-logo-drop]');
  var canvas = form.querySelector('[data-logo-canvas]');
  var hint = form.querySelector('[data-logo-hint]');
  var save = form.querySelector('[data-logo-save]');
  var TYPES = ['image/jpeg', 'image/png', 'image/webp'], MAX_SIDE = 800, MAX_BYTES = 2 * 1024 * 1024;
  var W = 300, H = 140, dpr = Math.min(2, window.devicePixelRatio || 1);
  var canFill = (function () { try { new DataTransfer(); return true; } catch (e) { return false; } })();

  canvas.width = W * dpr;
  canvas.height = H * dpr;
  if (canFill) input.hidden = true; // without DataTransfer the plain file input stays visible and works

  function say(text, bad) {
    hint.textContent = text;
    hint.classList.toggle('bad', !!bad);
  }

  /** Draw an image or canvas onto the preview, fitted and centred (contain). */
  function draw(source, w, h) {
    var g = canvas.getContext('2d');
    g.clearRect(0, 0, canvas.width, canvas.height);
    var s = Math.min(canvas.width / w, canvas.height / h);
    var dw = w * s, dh = h * s;
    g.drawImage(source, (canvas.width - dw) / 2, (canvas.height - dh) / 2, dw, dh);
  }

  function loadImage(src) {
    return new Promise(function (ok, bad) { var i = new Image(); i.onload = function () { ok(i); }; i.onerror = function () { bad(new Error('decode')); }; i.src = src; });
  }

  // Show the current logo in the box until a new one is chosen.
  if (zone.dataset.current) loadImage(zone.dataset.current).then(function (img) { draw(img, img.naturalWidth, img.naturalHeight); }).catch(function () { /* none shown */ });

  /** Resize on a canvas (max 800 px side), keep transparency (PNG), and return {file, canvas}. */
  function prepare(file) {
    return new Promise(function (ok, bad) {
      var r = new FileReader();
      r.onload = function () { ok(r.result); };
      r.onerror = function () { bad(new Error('read')); };
      r.readAsDataURL(file);
    }).then(loadImage).then(function (img) {
      var w = img.naturalWidth, h = img.naturalHeight;
      if (!w || !h) throw new Error('decode');
      var s = Math.min(1, MAX_SIDE / Math.max(w, h));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(w * s));
      c.height = Math.max(1, Math.round(h * s));
      var g = c.getContext('2d');
      var type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/png';
      if (type === 'image/jpeg') { g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); }
      g.drawImage(img, 0, 0, c.width, c.height);
      return new Promise(function (ok) { c.toBlob(ok, type, 0.92); }).then(function (blob) {
        if (!blob || blob.size > MAX_BYTES) throw new Error('large');
        var ext = type === 'image/jpeg' ? '.jpg' : '.png';
        return { file: new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'logo') + ext, { type: type }), canvas: c };
      });
    });
  }

  function handle(list) {
    var f = list && list[0];
    if (!f) return;
    if (TYPES.indexOf(f.type) < 0) { say('Only JPG, PNG or WebP images are allowed.', true); return; }
    zone.classList.add('busy');
    say('Preparing…', false);
    prepare(f).then(function (res) {
      zone.classList.remove('busy');
      if (canFill) {
        var dt = new DataTransfer();
        dt.items.add(res.file);
        input.files = dt.files;
      }
      draw(res.canvas, res.canvas.width, res.canvas.height);
      save.disabled = false;
      say('Ready. Click “Upload logo” to save it.', false);
    }).catch(function () {
      zone.classList.remove('busy');
      say('This image could not be read or is too large. Try a smaller one.', true);
    });
  }

  zone.addEventListener('click', function () { input.click(); });
  zone.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
  });
  ['dragenter', 'dragover'].forEach(function (t) {
    zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('over'); });
  });
  zone.addEventListener('dragleave', function () { zone.classList.remove('over'); });
  zone.addEventListener('drop', function (e) {
    e.preventDefault();
    zone.classList.remove('over');
    handle(e.dataTransfer.files);
  });
  input.addEventListener('change', function () { handle(input.files); });
})();
