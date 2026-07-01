/**
 * @file
 * Inline editor for Code Block fields.
 *
 * Behaviour summary:
 *  - A floating "Edit mode" toolbar is injected on every page where at least
 *    one .cbf-host--inline-enabled element is present and the user has the
 *    "use code block field inline editor" permission.
 *  - Clicking "Edit mode" toggles edit mode for every code block on the page.
 *  - In edit mode the toolbar exposes: "Save changes", "Cancel", and
 *    "Add block" (links to the host entity edit form).
 *  - Inside each shadow root:
 *      * Every text-bearing element (h1-h6, p, span, a, li, td, figcaption,
 *        blockquote, strong, em, b, i, label, small) becomes contenteditable.
 *      * <img data-cbf-asset="KEY"> get an overlay "Replace" button; clicking
 *        it opens the Drupal modal image picker.
 *      * <a> elements get a small "Edit link" pencil icon that opens the
 *        link picker modal.
 *  - Dirty tracking: any element marked contenteditable that receives input
 *    marks its host block as dirty. The Save button is disabled unless at
 *    least one block is dirty.
 *  - Saving collects the (potentially modified) HTML of every dirty block
 *    and POSTs it to /admin/code-block-field/inline-save.
 */
(function (Drupal, once) {
  'use strict';

  const TEXT_SELECTOR = 'h1, h2, h3, h4, h5, h6, p, span, a, li, td, th, figcaption, blockquote, strong, em, b, i, u, s, label, small, div';
  // Elements that should never be made editable on their own (their children
  // are edited individually).
  const SKIP_TEXT_SELECTOR = 'html, head, body, script, style, iframe, video, audio, canvas, svg';

  const state = {
    active: false,
    dirty: new Set(),
  };

  function getInstanceId(host) {
    return host.getAttribute('data-cbf-instance');
  }

  function getRegistryEntry(host) {
    return window.codeBlockFieldRegistry[getInstanceId(host)];
  }

  // Reads the colour palette from drupalSettings (populated by the field
  // formatter) and writes it as CSS custom properties on the toolbar element
  // and on <body> so that all CB rules can pick them up.
  function applyColors() {
    const colors = (drupalSettings && drupalSettings.code_block_field && drupalSettings.code_block_field.colors) || {};
    const toolbar = document.querySelector('.cbf-inline-toolbar');
    const map = {
      '--cbf-toolbar-bg': colors.toolbar_bg,
      '--cbf-toolbar-accent': colors.toolbar_accent,
      '--cbf-edit-outline': colors.edit_outline,
      '--cbf-dirty-outline': colors.dirty_outline,
      '--cbf-focus-outline': colors.focus_outline,
    };
    Object.keys(map).forEach(function (key) {
      if (!map[key]) {
        return;
      }
      if (toolbar) {
        toolbar.style.setProperty(key, map[key]);
      }
      document.body.style.setProperty(key, map[key]);
    });
  }

  function buildToolbar() {
    if (document.querySelector('.cbf-inline-toolbar')) {
      return;
    }
    const toolbar = document.createElement('div');
    toolbar.className = 'cbf-inline-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.innerHTML = `
      <div class="cbf-inline-toolbar__brand">
        <span class="cbf-inline-toolbar__dot"></span>
        Code Block
      </div>
      <button type="button" class="cbf-inline-toolbar__btn cbf-inline-toolbar__btn--primary cbf-js-toggle" aria-pressed="false">
        ${Drupal.t('Edit mode')}
      </button>
      <button type="button" class="cbf-inline-toolbar__btn cbf-js-save" disabled>
        ${Drupal.t('Save changes')}
      </button>
      <button type="button" class="cbf-inline-toolbar__btn cbf-js-cancel" disabled>
        ${Drupal.t('Cancel')}
      </button>
      <span class="cbf-inline-toolbar__hint"></span>
    `;
    document.body.appendChild(toolbar);

    toolbar.querySelector('.cbf-js-toggle').addEventListener('click', onToggle);
    toolbar.querySelector('.cbf-js-save').addEventListener('click', onSave);
    toolbar.querySelector('.cbf-js-cancel').addEventListener('click', onCancel);

    applyColors();
  }

  function setHint(text) {
    const el = document.querySelector('.cbf-inline-toolbar__hint');
    if (el) {
      el.textContent = text || '';
    }
  }

  function updateSaveButton() {
    const btn = document.querySelector('.cbf-js-save');
    if (btn) {
      btn.disabled = state.dirty.size === 0;
    }
  }

  function onToggle() {
    state.active = !state.active;
    const toggleBtn = document.querySelector('.cbf-js-toggle');
    const cancelBtn = document.querySelector('.cbf-js-cancel');
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-pressed', String(state.active));
      toggleBtn.classList.toggle('is-active', state.active);
    }
    if (cancelBtn) {
      cancelBtn.disabled = !state.active;
    }
    document.body.classList.toggle('cbf-inline-editing', state.active);

    document.querySelectorAll('.cbf-host--inline-enabled.cbf-host--mounted').forEach(function (host) {
      if (state.active) {
        enableEditing(host);
      } else {
        disableEditing(host);
      }
    });

    setHint(state.active ? Drupal.t('Editing. Click text to edit, click an image to replace.') : '');
    updateSaveButton();
  }

  function onCancel() {
    if (state.dirty.size > 0) {
      if (!window.confirm(Drupal.t('Discard unsaved changes?'))) {
        return;
      }
    }
    state.dirty.clear();
    // Re-render every dirty block from the registry payload (discard changes).
    state.dirty.forEach(function (instanceId) {
      const entry = window.codeBlockFieldRegistry[instanceId];
      if (entry) {
        Drupal.codeBlockField.render(instanceId, entry.payload);
      }
    });
    state.dirty.clear();
    onToggle(); // turn edit mode off
  }

  // CSS rules injected into each shadow root when editing is enabled.
  const SHADOW_EDIT_STYLES = `
    .cbf-editable {
      outline: 1px dashed transparent;
      outline-offset: 2px;
      transition: outline-color 0.15s ease, background-color 0.15s ease;
      cursor: text;
    }
    .cbf-editable:hover {
      outline-color: rgba(255, 138, 61, 0.55);
      background-color: rgba(255, 138, 61, 0.06);
    }
    .cbf-editable:focus {
      outline: 2px solid #0071eb;
      background-color: rgba(0, 113, 235, 0.06);
    }
    .cbf-editable-image {
      cursor: pointer !important;
      position: relative;
      outline: 2px dashed rgba(255, 138, 61, 0.55);
      outline-offset: 2px;
      transition: outline-color 0.15s ease, opacity 0.15s ease;
    }
    .cbf-editable-image:hover {
      outline-color: #ff8a3d;
      opacity: 0.85;
    }
    .cbf-editable-image::after {
      content: "${'\\270E'} Replace";
      position: absolute;
      top: 4px;
      left: 4px;
      background: rgba(15, 17, 32, 0.9);
      color: #fff;
      font: 11px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      padding: 2px 6px;
      border-radius: 4px;
      pointer-events: none;
    }
    .cbf-editable-link {
      position: relative;
      cursor: pointer;
    }
    .cbf-link-handle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 18px;
      height: 18px;
      margin-left: 4px;
      background: #ff8a3d;
      color: #fff;
      border-radius: 50%;
      font-size: 11px;
      line-height: 1;
      vertical-align: middle;
      cursor: pointer;
      opacity: 0.85;
      transition: opacity 0.15s ease, transform 0.15s ease;
    }
    .cbf-link-handle:hover {
      opacity: 1;
      transform: scale(1.1);
    }
  `;

  function injectShadowStyles(root) {
    let style = root.querySelector('style.cbf-inline-edit-styles');
    if (!style) {
      style = document.createElement('style');
      style.className = 'cbf-inline-edit-styles';
      root.appendChild(style);
    }
    style.textContent = SHADOW_EDIT_STYLES;
  }

  function removeShadowStyles(root) {
    const style = root.querySelector('style.cbf-inline-edit-styles');
    if (style) {
      style.parentNode.removeChild(style);
    }
  }

  function enableEditing(host) {
    const entry = getRegistryEntry(host);
    if (!entry || !entry.shadowRoot) {
      return;
    }
    const root = entry.shadowRoot;
    host.classList.add('cbf-host--editing');
    injectShadowStyles(root);

    // Wrap editable text nodes.
    root.querySelectorAll(TEXT_SELECTOR).forEach(function (el) {
      if (el.matches(SKIP_TEXT_SELECTOR)) {
        return;
      }
      // Skip elements that have block-level children only (no direct text).
      const hasText = Array.from(el.childNodes).some(function (n) {
        return n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '';
      });
      if (!hasText && el.tagName !== 'A') {
        return;
      }
      el.setAttribute('contenteditable', 'true');
      el.setAttribute('spellcheck', 'false');
      el.classList.add('cbf-editable');
      el.addEventListener('input', onEditableInput.bind(null, host));
      el.addEventListener('focus', onEditableFocus.bind(null, host));
      el.addEventListener('blur', onEditableBlur);
    });

    // Image overlays.
    root.querySelectorAll('img[data-cbf-asset]').forEach(function (img) {
      if (img.dataset.cbfOverlayAttached) {
        return;
      }
      img.dataset.cbfOverlayAttached = '1';
      img.classList.add('cbf-editable-image');
      img.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openImagePicker(host, img);
      });
    });

    // Link editor handles.
    root.querySelectorAll('a').forEach(function (a) {
      if (a.dataset.cbfLinkHandleAttached) {
        return;
      }
      a.dataset.cbfLinkHandleAttached = '1';
      a.classList.add('cbf-editable-link');
      a.addEventListener('click', function (e) {
        // Prevent navigation while editing.
        e.preventDefault();
        e.stopPropagation();
      });
      a.addEventListener('dblclick', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openLinkPicker(host, a);
      });
      // Inject a small pencil handle.
      const handle = document.createElement('span');
      handle.className = 'cbf-link-handle';
      handle.setAttribute('contenteditable', 'false');
      handle.title = Drupal.t('Edit link');
      handle.textContent = '✎';
      handle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openLinkPicker(host, a);
      });
      try {
        a.appendChild(handle);
      } catch (err) {
        // Some <a> elements might not allow children (e.g. inside SVG);
        // skip those silently.
      }
    });

    setHint(Drupal.t('Editing “%s”.', { '%s': getInstanceId(host) }));
  }

  function disableEditing(host) {
    const entry = getRegistryEntry(host);
    if (!entry || !entry.shadowRoot) {
      return;
    }
    const root = entry.shadowRoot;
    host.classList.remove('cbf-host--editing');
    removeShadowStyles(root);

    root.querySelectorAll('[contenteditable="true"]').forEach(function (el) {
      el.removeAttribute('contenteditable');
      el.removeAttribute('spellcheck');
      el.classList.remove('cbf-editable');
    });

    // Remove link handles.
    root.querySelectorAll('.cbf-link-handle').forEach(function (h) {
      h.parentNode && h.parentNode.removeChild(h);
    });
    // Remove image overlay classes (the click handlers stay, but are gated
    // by state.active inside openImagePicker).
    root.querySelectorAll('img[data-cbf-overlay-attached]').forEach(function (img) {
      delete img.dataset.cbfOverlayAttached;
      img.classList.remove('cbf-editable-image');
    });
  }

  function onEditableInput(host, e) {
    const instanceId = getInstanceId(host);
    state.dirty.add(instanceId);
    host.classList.add('cbf-host--dirty');
    updateSaveButton();
  }

  function onEditableFocus(host) {
    host.classList.add('cbf-host--focused');
  }

  function onEditableBlur() {
    document.querySelectorAll('.cbf-host--focused').forEach(function (h) {
      h.classList.remove('cbf-host--focused');
    });
  }

  // -- Image picker -----------------------------------------------------------

  function openImagePicker(host, img) {
    if (!state.active) {
      return;
    }
    const assetKey = img.getAttribute('data-cbf-asset');
    if (!assetKey) {
      return;
    }
    const url = Drupal.url('admin/code-block-field/image-picker/' + host.getAttribute('data-cbf-entity-type') + '/' + host.getAttribute('data-cbf-entity-id') + '/' + host.getAttribute('data-cbf-field-name') + '/' + host.getAttribute('data-cbf-delta') + '/' + encodeURIComponent(assetKey));
    const ajax = Drupal.ajax({
      url: url,
      dialogType: 'modal',
      dialog: { width: 600, title: Drupal.t('Replace image') },
    });
    ajax.execute();
    // Listen for the global "image picked" event sent by the modal form.
    document.addEventListener('codeBlockFieldImagePicked', function onPick(ev) {
      document.removeEventListener('codeBlockFieldImagePicked', onPick);
      const payload = ev.detail || ev.data || null;
      if (!payload) {
        return;
      }
      img.setAttribute('src', payload.url);
      if (payload.alt) {
        img.setAttribute('alt', payload.alt);
      }
      // Update the registry entry’s payload so the change is captured.
      const instanceId = getInstanceId(host);
      const entry = window.codeBlockFieldRegistry[instanceId];
      if (entry) {
        entry.payload.assets = entry.payload.assets || {};
        entry.payload.assets[assetKey] = { fid: payload.fid, alt: payload.alt || '', src: payload.url };
      }
      // Update dirty tracking.
      state.dirty.add(instanceId);
      host.classList.add('cbf-host--dirty');
      updateSaveButton();
    });
  }

  // -- Link picker ------------------------------------------------------------

  function openLinkPicker(host, a) {
    if (!state.active) {
      return;
    }
    const linkKey = a.dataset.cbfLinkId || ('link-' + Math.random().toString(36).slice(2, 9));
    a.dataset.cbfLinkId = linkKey;
    const url = Drupal.url('admin/code-block-field/link-picker/' + host.getAttribute('data-cbf-entity-type') + '/' + host.getAttribute('data-cbf-entity-id') + '/' + host.getAttribute('data-cbf-field-name') + '/' + host.getAttribute('data-cbf-delta') + '/' + encodeURIComponent(linkKey));
    const ajax = Drupal.ajax({
      url: url,
      dialogType: 'modal',
      dialog: { width: 500, title: Drupal.t('Edit link') },
    });
    ajax.execute();
    document.addEventListener('codeBlockFieldLinkPicked', function onPick(ev) {
      document.removeEventListener('codeBlockFieldLinkPicked', onPick);
      const payload = ev.detail || ev.data || null;
      if (!payload) {
        return;
      }
      if (payload.href) {
        a.setAttribute('href', payload.href);
      }
      if (payload.target) {
        a.setAttribute('target', payload.target);
      } else {
        a.removeAttribute('target');
      }
      if (payload.rel) {
        a.setAttribute('rel', payload.rel);
      } else {
        a.removeAttribute('rel');
      }
      if (payload.text) {
        a.textContent = payload.text;
      }
      state.dirty.add(getInstanceId(host));
      host.classList.add('cbf-host--dirty');
      updateSaveButton();
    });
  }

  // -- Save -------------------------------------------------------------------

  function collectPayload(host) {
    const entry = getRegistryEntry(host);
    if (!entry || !entry.shadowRoot) {
      return null;
    }
    const root = entry.shadowRoot;
    // The first child is the <style> element; the second is the wrapper div.
    const contentDiv = root.querySelector('.cbf-shadow-content');
    if (!contentDiv) {
      return null;
    }
    // Strip edit-only decorations from the cloned HTML.
    const clone = contentDiv.cloneNode(true);
    clone.querySelectorAll('.cbf-link-handle').forEach(function (h) { h.parentNode && h.parentNode.removeChild(h); });
    clone.querySelectorAll('[contenteditable]').forEach(function (el) {
      el.removeAttribute('contenteditable');
      el.removeAttribute('spellcheck');
      el.classList.remove('cbf-editable');
    });
    clone.querySelectorAll('.cbf-editable-image').forEach(function (el) {
      el.classList.remove('cbf-editable-image');
    });
    clone.querySelectorAll('.cbf-editable-link').forEach(function (el) {
      el.classList.remove('cbf-editable-link');
    });

    const html = clone.innerHTML;
    // Build assets map from <img data-cbf-asset="key">.
    const assets = {};
    clone.querySelectorAll('img[data-cbf-asset]').forEach(function (img) {
      const key = img.getAttribute('data-cbf-asset');
      const fid = (entry.payload.assets && entry.payload.assets[key] && entry.payload.assets[key].fid) ? entry.payload.assets[key].fid : 0;
      assets[key] = {
        fid: fid,
        alt: img.getAttribute('alt') || '',
        src: img.getAttribute('src') || '',
      };
    });
    return {
      html: html,
      css: entry.payload.css || '',
      js: entry.payload.js || '',
      assets: assets,
    };
  }

  function onSave() {
    if (state.dirty.size === 0) {
      return;
    }
    const endpoints = (drupalSettings && drupalSettings.code_block_field && drupalSettings.code_block_field.endpoints) || {};
    if (!endpoints.save) {
      // eslint-disable-next-line no-console
      console.error('Code Block Field: save endpoint not configured.');
      return;
    }
    const saveBtn = document.querySelector('.cbf-js-save');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = Drupal.t('Saving…');
    }

    const saves = [];
    // Group dirty blocks by (entity_type, entity_id, field_name) so we can
    // batch-save multiple deltas of the same field in a single request.
    const groups = new Map();
    state.dirty.forEach(function (instanceId) {
      const host = document.querySelector('[data-cbf-instance="' + instanceId + '"]');
      if (!host) {
        return;
      }
      const key = host.getAttribute('data-cbf-entity-type') + ':' + host.getAttribute('data-cbf-entity-id') + ':' + host.getAttribute('data-cbf-field-name') + ':' + host.getAttribute('data-cbf-langcode');
      if (!groups.has(key)) {
        groups.set(key, []);
      }
      groups.get(key).push(host);
    });

    groups.forEach(function (hosts) {
      hosts.forEach(function (host) {
        const data = collectPayload(host);
        if (!data) {
          return;
        }
        const payload = {
          entity_type: host.getAttribute('data-cbf-entity-type'),
          entity_id: parseInt(host.getAttribute('data-cbf-entity-id'), 10),
          field_name: host.getAttribute('data-cbf-field-name'),
          delta: parseInt(host.getAttribute('data-cbf-delta'), 10),
          langcode: host.getAttribute('data-cbf-langcode'),
          html: data.html,
          assets: data.assets,
        };
        saves.push(fetch(endpoints.save, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': drupalSettings.code_block_field.csrf_token,
          },
          body: JSON.stringify(payload),
        }).then(function (r) {
          return r.json().then(function (j) {
            return { host: host, response: j, ok: r.ok };
          });
        }));
      });
    });

    Promise.all(saves).then(function (results) {
      const failed = results.filter(function (r) { return !r.ok || (r.response && r.response.error); });
      results.forEach(function (r) {
        r.host.classList.remove('cbf-host--dirty');
      });
      state.dirty.clear();
      if (saveBtn) {
        saveBtn.textContent = Drupal.t('Save changes');
        saveBtn.disabled = true;
      }
      if (failed.length) {
        // eslint-disable-next-line no-console
        console.error('Code Block Field: save failed for some blocks', failed);
        window.alert(Drupal.t('Some blocks could not be saved. See the browser console for details.'));
      } else {
        setHint(Drupal.t('All changes saved.'));
        setTimeout(function () { setHint(''); }, 3000);
      }
    }).catch(function (err) {
      // eslint-disable-next-line no-console
      console.error('Code Block Field: save error', err);
      if (saveBtn) {
        saveBtn.textContent = Drupal.t('Save changes');
        saveBtn.disabled = false;
      }
      window.alert(Drupal.t('Save failed. See the browser console for details.'));
    });
  }

  // -- Behaviour --------------------------------------------------------------

  Drupal.behaviors.codeBlockFieldInlineEditor = {
    attach: function (context) {
      // Build the floating toolbar once on the document level.
      if (context === document || context === document.body) {
        const hasInline = document.querySelector('.cbf-host--inline-enabled.cbf-host--mounted');
        if (hasInline && once('cbf-toolbar', 'body').length) {
          buildToolbar();
        }
      }
    },
  };

  // Public API for themers / integrators.
  Drupal.codeBlockField = Drupal.codeBlockField || {};
  Drupal.codeBlockField.activate = function () { if (!state.active) { onToggle(); } };
  Drupal.codeBlockField.deactivate = function () { if (state.active) { onToggle(); } };

  // Bridge: convert the custom `InvokeCommand` callbacks used by the modal
  // forms into DOM events so the editor can listen to them generically.
  jQuery(document).on('codeBlockFieldImagePicked', function (e, payload) {
    document.dispatchEvent(new CustomEvent('codeBlockFieldImagePicked', { detail: payload }));
  });
  jQuery(document).on('codeBlockFieldLinkPicked', function (e, payload) {
    document.dispatchEvent(new CustomEvent('codeBlockFieldLinkPicked', { detail: payload }));
  });

})(Drupal, once);
