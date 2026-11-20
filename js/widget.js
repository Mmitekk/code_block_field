/**
 * @file
 * Widget behaviour:
 * - keeps the hidden `assets` JSON in sync with the `<img data-cbf-asset="key">`
 *   entries present in the HTML editor;
 * - per-block actions dropdown: copy code / clone / delete.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Saves all CodeMirror instances on the page back into their textareas.
   * AJAX form serialization reads the textareas, not the editors, so this
   * must run before any submit triggered from the widget.
   */
  function flushEditors() {
    document.querySelectorAll('textarea.code-block-editor').forEach(function (textarea) {
      if (textarea.codeMirrorEditor) {
        textarea.codeMirrorEditor.save();
      }
    });
  }

  /**
   * Copies text to the clipboard, with an execCommand fallback for
   * non-secure contexts (plain-http admin pages).
   */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      const helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.opacity = '0';
      document.body.appendChild(helper);
      helper.select();
      let ok = false;
      try {
        ok = document.execCommand('copy');
      }
      catch (e) {
        ok = false;
      }
      document.body.removeChild(helper);
      if (ok) {
        resolve();
      }
      else {
        reject(new Error('execCommand copy failed'));
      }
    });
  }

  /** Closes every open actions dropdown on the page. */
  function closeAllMenus() {
    document.querySelectorAll('[data-cbf-widget] .cbf-actions-menu:not([hidden])').forEach(function (menu) {
      menu.setAttribute('hidden', 'hidden');
    });
    document.querySelectorAll('.cbf-actions-toggle[aria-expanded="true"]').forEach(function (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    });
  }

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

      // One set of delegated document handlers for every widget on the page.
      // Delegation keeps working after AJAX re-renders of the widget.
      once('cbf-actions-global', document.documentElement).forEach(function () {
        document.addEventListener('click', function (e) {
          if (!e.target || !e.target.closest) {
            return;
          }

          const toggle = e.target.closest('.cbf-actions-toggle');
          if (toggle) {
            e.preventDefault();
            const wrapper = toggle.closest('[data-cbf-widget]');
            const menu = wrapper && wrapper.querySelector('.cbf-actions-menu');
            if (!menu) {
              return;
            }
            const willOpen = menu.hasAttribute('hidden');
            closeAllMenus();
            if (willOpen) {
              menu.removeAttribute('hidden');
              toggle.setAttribute('aria-expanded', 'true');
            }
            return;
          }

          const copyButton = e.target.closest('[data-cbf-copy]');
          if (copyButton) {
            const wrapper = copyButton.closest('[data-cbf-widget]');
            if (!wrapper) {
              return;
            }
            flushEditors();
            const html = wrapper.querySelector('textarea.code-block-editor-html');
            const css = wrapper.querySelector('textarea.code-block-editor-css');
            const js = wrapper.querySelector('textarea.code-block-editor-js');
            const htmlText = html ? (html.value || '') : '';
            const cssText = css ? (css.value || '') : '';
            const jsText = js ? (js.value || '') : '';
            const parts = [htmlText];
            if (cssText.trim()) {
              parts.push('<!-- CSS -->\n' + cssText);
            }
            if (jsText.trim()) {
              parts.push('<!-- JS -->\n' + jsText);
            }
            copyText(parts.join('\n\n')).then(function () {
              const original = copyButton.textContent;
              copyButton.textContent = Drupal.t('Скопировано ✓');
              copyButton.classList.add('is-copied');
              setTimeout(function () {
                copyButton.textContent = original;
                copyButton.classList.remove('is-copied');
                closeAllMenus();
              }, 1200);
            });
            return;
          }

          // Clicked outside any dropdown — close them all.
          if (!e.target.closest('.cbf-actions-menu')) {
            closeAllMenus();
          }
        });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            closeAllMenus();
          }
        });

        // Flush CodeMirror before any AJAX/submit button inside the widget
        // area fires: Drupal.ajax binds submit buttons on mousedown, which
        // happens before click and before the form submit event.
        document.addEventListener('mousedown', function (e) {
          if (!e.target || !e.target.closest) {
            return;
          }
          const button = e.target.closest('input[type="submit"], button[type="submit"]');
          if (button && button.closest('.field--widget-code_block_widget, [data-cbf-widget]')) {
            flushEditors();
          }
        }, true);

        // Confirmation for destructive actions (delete). Capture phase: the
        // Drupal AJAX handler is bound on the button itself, so declining
        // here must stop it from ever reaching that handler.
        document.addEventListener('click', function (e) {
          if (!e.target || !e.target.closest) {
            return;
          }
          const button = e.target.closest('[data-cbf-confirm]');
          if (button && !window.confirm(button.getAttribute('data-cbf-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
          }
        }, true);
      });
    },
  };
})(Drupal, once);
