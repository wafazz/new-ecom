// Rich-text description: Quill (self-hosted) on top of the real <textarea name="description">.
// The textarea always holds the editor's HTML, so AJAX posts, plain posts and the unsaved-changes check just work.
// The server sanitises on save (RichText::clean); nothing the editor sends is trusted.
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function init(root) {
    if (!window.Quill) return; // no editor: the plain textarea stays and still works
    $$('[data-richtext]', root || document).forEach(function (box) {
      if (box.dataset.rtReady) return;
      box.dataset.rtReady = '1';
      var holder = $('[data-rt-editor]', box), source = $('[data-rt-source]', box);
      holder.hidden = false; source.hidden = true;
      var q = new Quill(holder, {
        theme: 'snow',
        placeholder: 'Describe the product…',
        formats: ['bold', 'italic', 'underline', 'strike', 'header', 'list', 'blockquote', 'link'],
        modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] }
      });
      q.clipboard.dangerouslyPasteHTML(source.value); // already sanitised by the server
      var sync = function () { source.value = q.getText().trim() === '' && q.root.querySelectorAll('li').length === 0 ? '' : q.getSemanticHTML().replace(/&nbsp;/g, ' '); };
      sync();
      q.on('text-change', function () { sync(); source.dispatchEvent(new Event('input', { bubbles: true })); });
      var label = $('label', box); if (label) label.addEventListener('click', function () { q.focus(); });
      q.root.setAttribute('aria-label', 'Description');
      box._quill = q;
    });
  }

  window.RichText = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
