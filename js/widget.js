/**
 * @file
 * Widget behaviour: keeps the hidden `assets` JSON in sync with the
 * `<img data-cbf-asset="key">` entries present in the HTML editor.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.codeBlockFieldWidget = {
    attach: function (context) {
      once('cbf-widget-sync', '[data-cbf-widget]', context).forEach(function (wrapper) {
        const htmlField = wrapper.querySelector('textarea.code-block-editor-html');
        const assetsField = wrapper.querySelector('input.code-block-assets');
        if (!htmlField || !assetsField) {
          return;
        }

        const sync = function () {
          // CodeMirror stores the latest value, so we need to pull it
          // back into the textarea first.
          if (htmlField.codeMirrorEditor) {
            htmlField.codeMirrorEditor.save();
          }
          const html = htmlField.value || '';
          const parser = new DOMParser();
          const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
          const imgs = doc.querySelectorAll('img[data-cbf-asset]');
          let assets = {};
          try {
            assets = JSON.parse(assetsField.value || '{}') || {};
          } catch (e) {
            assets = {};
          }
          const seen = {};
          imgs.forEach(function (img) {
            const key = img.getAttribute('data-cbf-asset');
            seen[key] = true;
            if (!assets[key]) {
              assets[key] = { fid: 0, alt: img.getAttribute('alt') || '', src: img.getAttribute('src') || '' };
            } else {
              assets[key].alt = img.getAttribute('alt') || assets[key].alt || '';
              assets[key].src = img.getAttribute('src') || assets[key].src || '';
            }
          });
          // Drop removed keys.
          Object.keys(assets).forEach(function (k) {
            if (!seen[k]) {
              delete assets[k];
            }
          });
          assetsField.value = JSON.stringify(assets);
        };

        // Re-sync on every change (debounced through CodeMirror’s own events).
        if (htmlField.codeMirrorEditor) {
          htmlField.codeMirrorEditor.on('change', Drupal.debounce(sync, 400));
        } else {
          htmlField.addEventListener('input', Drupal.debounce(sync, 400));
        }
        // And once on attach.
        sync();
      });
    },
  };
})(Drupal, once);
