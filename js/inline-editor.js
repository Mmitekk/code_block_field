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
        ${Drupal.t('Режим редактирования')}
      </button>
      <button type="button" class="cbf-inline-toolbar__btn cbf-js-save" disabled>
        ${Drupal.t('Сохранить')}
      </button>
      <button type="button" class="cbf-inline-toolbar__btn cbf-js-cancel" disabled>
        ${Drupal.t('Отмена')}
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

    setHint(state.active ? Drupal.t('Режим редактирования. Кликните по тексту для правки, по картинке — для замены.') : '');
    updateSaveButton();
  }

  function onCancel() {
    if (state.dirty.size > 0) {
      if (!window.confirm(Drupal.t('Сбросить несохранённые изменения?'))) {
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

    /* ===== WYSIWYG floating toolbar (inside shadow DOM, attached to body) ===== */
    /* The toolbar is created in the light DOM (so it can be positioned relative
       to the viewport) but visually appears above the selection. See
       showFormatToolbar() in inline-editor.js. */

    /* Image resize handles */
    .cbf-img-resizing {
      outline: 2px solid var(--cbf-edit-outline, #ff8a3d) !important;
      position: relative;
    }
    .cbf-img-resize-handle {
      position: absolute;
      width: 12px;
      height: 12px;
      background: var(--cbf-edit-outline, #ff8a3d);
      border: 2px solid #fff;
      border-radius: 50%;
      cursor: nwse-resize;
      z-index: 10;
      box-shadow: 0 1px 3px rgba(0,0,0,0.4);
    }
    .cbf-img-resize-handle--br {
      right: -6px;
      bottom: -6px;
      cursor: nwse-resize;
    }
    .cbf-img-resize-handle--bl {
      left: -6px;
      bottom: -6px;
      cursor: nesw-resize;
    }

    /* Context menu for images */
    .cbf-img-context-menu {
      position: absolute;
      background: #1e1e2e;
      color: #fff;
      border-radius: 6px;
      box-shadow: 0 6px 18px rgba(0,0,0,0.35);
      padding: 4px;
      z-index: 99998;
      font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      min-width: 180px;
    }
    .cbf-img-context-menu__item {
      display: block;
      width: 100%;
      padding: 6px 12px;
      background: transparent;
      color: #fff;
      border: none;
      cursor: pointer;
      border-radius: 4px;
      text-align: left;
    }
    .cbf-img-context-menu__item:hover {
      background: rgba(255,255,255,0.12);
    }
    .cbf-img-context-menu__item--danger {
      color: #ff6b6b;
    }
    .cbf-img-context-menu__sep {
      height: 1px;
      background: rgba(255,255,255,0.12);
      margin: 4px 0;
    }

    /* Alt-edit popup */
    .cbf-alt-editor {
      position: absolute;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 6px;
      padding: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 99998;
      font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      max-width: 320px;
    }
    .cbf-alt-editor__input {
      display: block;
      width: 100%;
      padding: 4px 8px;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 13px;
      margin-bottom: 6px;
      box-sizing: border-box;
    }
    .cbf-alt-editor__btn {
      padding: 4px 10px;
      background: var(--cbf-edit-outline, #ff8a3d);
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
    }

    /* "Insert image" button */
    .cbf-insert-img-btn {
      position: absolute;
      top: 4px;
      right: 4px;
      background: var(--cbf-edit-outline, #ff8a3d);
      color: #fff;
      border: none;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      cursor: pointer;
      font-size: 18px;
      line-height: 1;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
      z-index: 5;
    }
    .cbf-insert-img-btn:hover {
      transform: scale(1.1);
    }

    /* Background-image editing indicator */
    .cbf-editable-bg-image {
      cursor: pointer !important;
      outline: 2px dashed rgba(255, 138, 61, 0.55);
      outline-offset: 2px;
      transition: outline-color 0.15s ease;
    }
    .cbf-editable-bg-image:hover {
      outline-color: #ff8a3d;
    }
    .cbf-editable-bg-image::after {
      content: "✎ BG";
      position: absolute;
      top: 2px;
      right: 2px;
      background: var(--cbf-edit-outline, #ff8a3d);
      color: #fff;
      font: 10px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      padding: 1px 5px;
      border-radius: 3px;
      pointer-events: none;
      z-index: 10;
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
      // Show the WYSIWYG toolbar when the user selects text inside this element.
      el.addEventListener('mouseup', onEditableSelectionChange.bind(null, host));
      el.addEventListener('keyup', onEditableSelectionChange.bind(null, host));
    });

    // Listen for selection changes globally (covers keyboard selection
    // across multiple editable elements within the same shadow root).
    document.addEventListener('selectionchange', onSelectionChangeGlobal);

    // Image overlays + resize handles + context menu + alt editor.
    root.querySelectorAll('img[data-cbf-asset]').forEach(function (img) {
      if (img.dataset.cbfOverlayAttached) {
        return;
      }
      img.dataset.cbfOverlayAttached = '1';
      img.classList.add('cbf-editable-image');
      attachImageEditing(host, img);
    });
    // Also allow non-asset images to be resized + alt-edited (but not replaced).
    root.querySelectorAll('img:not([data-cbf-asset])').forEach(function (img) {
      if (img.dataset.cbfOverlayAttached) {
        return;
      }
      img.dataset.cbfOverlayAttached = '1';
      attachImageEditing(host, img, { noReplace: true });
    });

    // Background-image editing: find elements with background-image:url(...)
    // in their inline style attribute and make them clickable to replace
    // the background image.
    root.querySelectorAll('[style*="background-image"]'), root.querySelectorAll('[style*="background:"]').forEach(function (el) {
      if (el.dataset.cbfBgImageAttached) {
        return;
      }
      var style = el.getAttribute('style') || '';
      if (style.indexOf('url(') === -1) {
        return;
      }
      el.dataset.cbfBgImageAttached = '1';
      el.classList.add('cbf-editable-bg-image');
      el.addEventListener('click', function (e) {
        if (!state.active) {
          return;
        }
        // Only trigger if the user clicks directly on this element (not a child).
        if (e.target !== el) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        openBackgroundImagePicker(host, el);
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
      handle.title = Drupal.t('Редактировать ссылку');
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

    // "Insert image" floating button (per block).
    if (!host.querySelector('.cbf-insert-img-btn')) {
      const insertBtn = document.createElement('button');
      insertBtn.type = 'button';
      insertBtn.className = 'cbf-insert-img-btn';
      insertBtn.title = Drupal.t('Вставить изображение');
      insertBtn.textContent = '+';
      insertBtn.setAttribute('contenteditable', 'false');
      insertBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        insertNewImage(host);
      });
      host.appendChild(insertBtn);
    }

    setHint(Drupal.t('Редактируется «%s».', { '%s': getInstanceId(host) }));
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
      img.classList.remove('cbf-img-resizing');
    });
    // Remove background-image editing classes.
    root.querySelectorAll('[data-cbf-bg-image-attached]').forEach(function (el) {
      delete el.dataset.cbfBgImageAttached;
      el.classList.remove('cbf-editable-bg-image');
    });
    // Remove image resize handles.
    root.querySelectorAll('.cbf-img-resize-handle').forEach(function (h) {
      h.parentNode && h.parentNode.removeChild(h);
    });
    // Remove insert-image button.
    host.querySelectorAll('.cbf-insert-img-btn').forEach(function (b) {
      b.parentNode && b.parentNode.removeChild(b);
    });
    // Hide any open context menus / alt editors / format toolbar.
    closeAllPopups();
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
    // Delay hiding the format toolbar so that clicking a button on it
    // does not lose the selection first.
    setTimeout(function () {
      const sel = window.getSelection();
      if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
        // Don't hide if focus moved to the toolbar itself.
        if (!formatToolbar || !formatToolbar.contains(document.activeElement)) {
          hideFormatToolbar();
        }
      }
    }, 200);
  }

  function onEditableSelectionChange(host) {
    if (!state.active) {
      return;
    }
    // Use a small timeout so the browser has time to update the selection
    // after the mouseup/keyup event.
    setTimeout(function () {
      showFormatToolbar(host);
    }, 10);
  }

  function onSelectionChangeGlobal() {
    if (!state.active) {
      return;
    }
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
      // Selection collapsed — hide the toolbar unless focus is on it.
      if (!formatToolbar || !formatToolbar.contains(document.activeElement)) {
        hideFormatToolbar();
      }
      return;
    }
    // Determine which host the selection belongs to by checking ancestors.
    let node = sel.anchorNode;
    if (node && node.nodeType === Node.TEXT_NODE) {
      node = node.parentNode;
    }
    while (node && node.nodeType === Node.ELEMENT_NODE) {
      if (node.host && node.host.classList && node.host.classList.contains('cbf-host')) {
        // node is a shadow root; show toolbar for its host.
        showFormatToolbar(node.host);
        return;
      }
      if (node.classList && node.classList.contains('cbf-host')) {
        showFormatToolbar(node);
        return;
      }
      node = node.parentNode;
    }
    // Selection not inside a code block — hide the toolbar.
    hideFormatToolbar();
  }

  // Close popups on outside click.
  document.addEventListener('mousedown', function (e) {
    if (!state.active) {
      return;
    }
    if (e.target.closest && (e.target.closest('.cbf-img-context-menu') || e.target.closest('.cbf-alt-editor') || e.target.closest('.cbf-format-toolbar'))) {
      return;
    }
    closeAllPopups();
  });

  // Reposition resize handles on window scroll/resize.
  window.addEventListener('scroll', function () {
    if (!state.active) {
      return;
    }
    document.querySelectorAll('.cbf-img-resize-handle').forEach(function (h) {
      // Find the img this handle belongs to — it was appended as a sibling.
      const img = h.previousElementSibling && h.previousElementSibling.tagName === 'IMG'
        ? h.previousElementSibling
        : (h.nextElementSibling && h.nextElementSibling.tagName === 'IMG' ? h.nextElementSibling : null);
      if (img) {
        const corner = h.classList.contains('cbf-img-resize-handle--br') ? 'br' : 'bl';
        positionImageHandle(h, img, corner);
      }
    });
  }, { passive: true });

  // -- WYSIWYG floating toolbar ---------------------------------------------

  /**
   * Floating format toolbar shown above the current selection inside an
   * editable element. Lives in the light DOM (so it can be positioned
   * relative to the viewport) but its buttons act on the selection inside
   * the shadow root.
   */
  let formatToolbar = null;
  let currentEditHost = null;        // the .cbf-host that owns the current selection
  let currentEditRoot = null;        // the shadow root of that host
  let savedRange = null;             // saved Range from the selection, used to
                                     // restore the selection before applying
                                     // a format command (otherwise the
                                     // toolbar button click collapses it)

  function buildFormatToolbar() {
    if (formatToolbar) {
      return;
    }
    formatToolbar = document.createElement('div');
    formatToolbar.className = 'cbf-format-toolbar';
    formatToolbar.setAttribute('role', 'toolbar');
    formatToolbar.setAttribute('contenteditable', 'false');
    formatToolbar.style.display = 'none';
    formatToolbar.innerHTML = `
      <button type="button" data-cmd="bold" title="${Drupal.t('Жирный')}" aria-label="${Drupal.t('Жирный')}"><b>B</b></button>
      <button type="button" data-cmd="italic" title="${Drupal.t('Курсив')}" aria-label="${Drupal.t('Курсив')}"><i>I</i></button>
      <button type="button" data-cmd="underline" title="${Drupal.t('Подчёркнутый')}" aria-label="${Drupal.t('Подчёркнутый')}"><u>U</u></button>
      <button type="button" data-cmd="strikeThrough" title="${Drupal.t('Зачёркнутый')}" aria-label="${Drupal.t('Зачёркнутый')}"><s>S</s></button>
      <span class="cbf-format-toolbar__sep"></span>
      <button type="button" data-block="h2" title="H2"><b>H2</b></button>
      <button type="button" data-block="h3" title="H3"><b>H3</b></button>
      <button type="button" data-block="h4" title="H4"><b>H4</b></button>
      <button type="button" data-block="p" title="${Drupal.t('Абзац')}">¶</button>
      <span class="cbf-format-toolbar__sep"></span>
      <button type="button" data-cmd="justifyLeft" title="${Drupal.t('По левому краю')}">⬅</button>
      <button type="button" data-cmd="justifyCenter" title="${Drupal.t('По центру')}">⬌</button>
      <button type="button" data-cmd="justifyRight" title="${Drupal.t('По правому краю')}">➡</button>
      <button type="button" data-cmd="justifyFull" title="${Drupal.t('По ширине')}">☰</button>
      <span class="cbf-format-toolbar__sep"></span>
      <button type="button" data-cmd="insertUnorderedList" title="${Drupal.t('Маркированный список')}">•</button>
      <button type="button" data-cmd="insertOrderedList" title="${Drupal.t('Нумерованный список')}">1.</button>
      <span class="cbf-format-toolbar__sep"></span>
      <label class="cbf-format-toolbar__color" title="${Drupal.t('Цвет текста')}">
        <span class="cbf-format-toolbar__color-icon">A</span>
        <input type="color" data-cmd="foreColor" value="#000000">
      </label>
      <span class="cbf-format-toolbar__sep"></span>
      <button type="button" data-cmd="createLink" title="${Drupal.t('Ссылка')}">🔗</button>
      <button type="button" data-cmd="removeFormat" title="${Drupal.t('Очистить форматирование')}">⌫</button>
    `;
    document.body.appendChild(formatToolbar);

    formatToolbar.addEventListener('mousedown', function (e) {
      // Prevent the selection from collapsing when the user clicks a button.
      if (e.target.tagName !== 'INPUT' || e.target.type !== 'color') {
        e.preventDefault();
      }
    });

    formatToolbar.querySelectorAll('button[data-cmd]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const cmd = btn.getAttribute('data-cmd');
        // eslint-disable-next-line no-console
        console.log('Code Block Field: format button clicked', {
          cmd: cmd,
          hasSavedRange: !!savedRange,
          savedRangeCollapsed: savedRange ? savedRange.collapsed : null,
        });
        runFormatCommand(cmd, btn);
      });
    });

    formatToolbar.querySelectorAll('button[data-block]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const tag = btn.getAttribute('data-block');
        runBlockCommand(tag);
      });
    });

    const colorInput = formatToolbar.querySelector('input[data-cmd="foreColor"]');
    if (colorInput) {
      colorInput.addEventListener('input', function (e) {
        runFormatCommand('foreColor', null, e.target.value);
      });
    }
  }

  function showFormatToolbar(host) {
    buildFormatToolbar();
    currentEditHost = host;
    const entry = getRegistryEntry(host);
    currentEditRoot = entry ? entry.shadowRoot : null;
    if (!currentEditRoot) {
      return;
    }
    const sel = currentEditRoot.getSelection ? currentEditRoot.getSelection() : window.getSelection();
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
      hideFormatToolbar();
      return;
    }
    const range = sel.getRangeAt(0);
    const rect = range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) {
      hideFormatToolbar();
      return;
    }
    // Save the range so we can restore the selection when the user
    // clicks a toolbar button (the click would otherwise move focus
    // to the button and collapse the selection inside the shadow root).
    savedRange = range.cloneRange();
    formatToolbar.style.display = 'flex';
    const toolbarRect = formatToolbar.getBoundingClientRect();
    let top = rect.top - toolbarRect.height - 6;
    let left = rect.left + (rect.width / 2) - (toolbarRect.width / 2);
    if (top < 4) {
      top = rect.bottom + 6;
    }
    if (left < 4) {
      left = 4;
    }
    if (left + toolbarRect.width > window.innerWidth - 4) {
      left = window.innerWidth - toolbarRect.width - 4;
    }
    formatToolbar.style.top = top + 'px';
    formatToolbar.style.left = left + 'px';
    updateToolbarState();
  }

  function hideFormatToolbar() {
    if (formatToolbar) {
      formatToolbar.style.display = 'none';
    }
    currentEditHost = null;
    currentEditRoot = null;
    savedRange = null;
  }

  /**
   * Restores the saved selection range inside the shadow root. This is
   * called before every format command because clicking a toolbar button
   * moves focus to the button and collapses the selection inside the
   * shadow root.
   *
   * Returns true if the selection was restored successfully.
   */
  function restoreSelection() {
    if (!savedRange || !currentEditRoot) {
      return false;
    }
    const sel = window.getSelection();
    if (!sel) {
      return false;
    }
    sel.removeAllRanges();
    try {
      sel.addRange(savedRange);
      return true;
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('Code Block Field: failed to restore selection:', e);
      return false;
    }
  }

  function updateToolbarState() {
    if (!formatToolbar || !currentEditRoot) {
      return;
    }
    // queryCommandState does not work reliably inside Shadow DOM, so we
    // check the actual DOM ancestors of the current selection.
    const sel = window.getSelection();
    let node = (sel && sel.rangeCount > 0) ? sel.getRangeAt(0).commonAncestorContainer : null;
    if (node && node.nodeType === Node.TEXT_NODE) {
      node = node.parentNode;
    }

    const tagMap = {
      bold: ['strong', 'b'],
      italic: ['em', 'i'],
      underline: ['u'],
      strikeThrough: ['s', 'strike'],
    };

    Object.keys(tagMap).forEach(function (cmd) {
      const btn = formatToolbar.querySelector('button[data-cmd="' + cmd + '"]');
      if (!btn) {
        return;
      }
      let active = false;
      let walker = node;
      while (walker && walker !== currentEditRoot) {
        if (walker.nodeType === Node.ELEMENT_NODE) {
          if (tagMap[cmd].indexOf(walker.tagName.toLowerCase()) !== -1) {
            active = true;
            break;
          }
        }
        walker = walker.parentNode;
      }
      btn.classList.toggle('is-active', active);
    });
  }

  /**
   * Maps inline format commands to their HTML tag.
   */
  const INLINE_FORMAT_TAGS = {
    bold: 'strong',
    italic: 'em',
    underline: 'u',
    strikeThrough: 's',
  };

  /**
   * Wraps the current selection in the given tag. Uses Range.surroundContents
   * when possible (single-range, fully-contained selection); falls back to
   * extractContents + wrap + insertNode when the selection crosses element
   * boundaries (surroundContents throws on partial selections).
   *
   * Works inside Shadow DOM (unlike document.execCommand which is
   * unreliable there).
   */
  function wrapSelectionWithTag(tagName) {
    if (!currentEditRoot) {
      return false;
    }
    // Restore the saved selection — the toolbar button click may have
    // collapsed it.
    restoreSelection();
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
      // eslint-disable-next-line no-console
      console.warn('Code Block Field: wrapSelectionWithTag(' + tagName + ') — no selection');
      return false;
    }
    const range = sel.getRangeAt(0);

    // If the selection is already entirely inside a tag of the same name,
    // toggle it off (unwrap).
    let container = range.commonAncestorContainer;
    if (container.nodeType === Node.TEXT_NODE) {
      container = container.parentNode;
    }
    if (container && container.closest) {
      const existing = container.closest(tagName);
      if (existing && existing.getRootNode() === currentEditRoot) {
        // Unwrap: replace the wrapper with its children.
        const parent = existing.parentNode;
        while (existing.firstChild) {
          parent.insertBefore(existing.firstChild, existing);
        }
        parent.removeChild(existing);
        // eslint-disable-next-line no-console
        console.log('Code Block Field: unwrapped <' + tagName + '>');
        return true;
      }
    }

    // Try surroundContents first — works for clean, single-element selections.
    try {
      const wrapper = document.createElement(tagName);
      range.surroundContents(wrapper);
      // eslint-disable-next-line no-console
      console.log('Code Block Field: wrapped selection in <' + tagName + '> via surroundContents');
      return true;
    } catch (e) {
      // surroundContents throws if the selection crosses element boundaries
      // (e.g. user selected text spanning two <p> tags). Fall back to
      // extractContents + wrap + insertNode.
    }

    // Fallback: extract the selection, wrap each text node in the tag,
    // and re-insert.
    try {
      const fragment = range.extractContents();
      const wrapper = document.createElement(tagName);
      wrapper.appendChild(fragment);
      range.insertNode(wrapper);
      // eslint-disable-next-line no-console
      console.log('Code Block Field: wrapped selection in <' + tagName + '> via extractContents fallback');
      return true;
    } catch (e2) {
      // eslint-disable-next-line no-console
      console.warn('wrapSelectionWithTag(' + tagName + ') failed:', e2);
      return false;
    }
  }

  /**
   * Removes inline formatting (bold/italic/underline/strike) from the
   * current selection by unwrapping the relevant tags.
   */
  function clearInlineFormat() {
    if (!currentEditRoot) {
      return;
    }
    restoreSelection();
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) {
      return;
    }
    const range = sel.getRangeAt(0);

    // Find all formatting tags that intersect the selection.
    const tagsToRemove = ['strong', 'em', 'u', 's', 'b', 'i', 'span'];
    let container = range.commonAncestorContainer;
    if (container.nodeType === Node.TEXT_NODE) {
      container = container.parentNode;
    }
    if (!container || !container.querySelectorAll) {
      return;
    }

    tagsToRemove.forEach(function (tag) {
      container.querySelectorAll(tag).forEach(function (el) {
        // Only unwrap if the element is within the selection range.
        const elRange = document.createRange();
        elRange.selectNodeContents(el);
        if (range.intersectsNode(el)) {
          const parent = el.parentNode;
          while (el.firstChild) {
            parent.insertBefore(el.firstChild, el);
          }
          parent.removeChild(el);
        }
      });
    });
  }

  /**
   * Applies an inline format (bold/italic/underline/strikethrough) to the
   * current selection. Uses surroundContents-based wrapping instead of
   * document.execCommand (which is unreliable inside Shadow DOM).
   */
  function runFormatCommand(cmd, btn, value) {
    if (!currentEditRoot) {
      return;
    }
    // Restore the saved selection — the toolbar button click may have
    // collapsed it. This is essential for all format commands to work.
    restoreSelection();

    // For createLink, use the existing link picker modal.
    if (cmd === 'createLink') {
      const sel = window.getSelection();
      if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
        return;
      }
      const range = sel.getRangeAt(0);
      // Find an existing <a> in the selection to edit, or create one.
      let a = null;
      let node = range.commonAncestorContainer;
      if (node.nodeType === Node.TEXT_NODE) {
        node = node.parentNode;
      }
      if (node && node.closest) {
        a = node.closest('a');
      }
      if (!a) {
        // Wrap selection in a temporary <a> so the picker can edit it.
        a = document.createElement('a');
        a.href = '#';
        try {
          range.surroundContents(a);
        } catch (err) {
          // Selection crosses boundaries — fall back to extractContents.
          try {
            const fragment = range.extractContents();
            a.appendChild(fragment);
            range.insertNode(a);
          } catch (err2) {
            return;
          }
        }
      }
      if (currentEditHost && a) {
        openLinkPicker(currentEditHost, a);
      }
      markDirty(currentEditHost);
      hideFormatToolbar();
      return;
    }

    // For removeFormat, unwrap all formatting tags.
    if (cmd === 'removeFormat') {
      clearInlineFormat();
      markDirty(currentEditHost);
      updateToolbarState();
      return;
    }

    // For foreColor, we need to wrap the selection in a <span style="color:...">.
    if (cmd === 'foreColor' && value) {
      const sel = window.getSelection();
      if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
        return;
      }
      const range = sel.getRangeAt(0);
      try {
        const span = document.createElement('span');
        span.style.color = value;
        range.surroundContents(span);
      } catch (e) {
        try {
          const fragment = range.extractContents();
          const span = document.createElement('span');
          span.style.color = value;
          span.appendChild(fragment);
          range.insertNode(span);
        } catch (e2) {
          // eslint-disable-next-line no-console
          console.warn('foreColor failed:', e2);
        }
      }
      markDirty(currentEditHost);
      updateToolbarState();
      return;
    }

    // Inline formats: bold/italic/underline/strikeThrough.
    if (INLINE_FORMAT_TAGS[cmd]) {
      wrapSelectionWithTag(INLINE_FORMAT_TAGS[cmd]);
      markDirty(currentEditHost);
      updateToolbarState();
      return;
    }

    // Alignment and lists: these are block-level and execCommand actually
    // works for them in most browsers because they operate on the block
    // ancestor, not the inline selection. Try execCommand as a fallback.
    try {
      document.execCommand(cmd, false, value || null);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('execCommand ' + cmd + ' failed:', e);
    }
    markDirty(currentEditHost);
    updateToolbarState();
  }

  /**
   * Converts the current block-level element of the selection to the given
   * tag (h2/h3/h4/p). Uses direct DOM manipulation instead of
   * document.execCommand('formatBlock') which is unreliable inside Shadow
   * DOM.
   */
  function runBlockCommand(tag) {
    if (!currentEditRoot) {
      return;
    }
    restoreSelection();
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) {
      return;
    }
    const range = sel.getRangeAt(0);
    let node = range.startContainer;
    if (node.nodeType === Node.TEXT_NODE) {
      node = node.parentNode;
    }
    if (!node) {
      return;
    }
    // Walk up to find the nearest block-level ancestor inside the shadow root.
    const blockTags = ['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'DIV', 'LI', 'BLOCKQUOTE'];
    let block = node;
    while (block && block !== currentEditRoot) {
      if (block.nodeType === Node.ELEMENT_NODE && blockTags.indexOf(block.tagName) !== -1) {
        break;
      }
      block = block.parentNode;
    }
    if (!block || block === currentEditRoot) {
      // No block ancestor — fall back to execCommand.
      try {
        document.execCommand('formatBlock', false, '<' + tag + '>');
      } catch (e) {
        // eslint-disable-next-line no-console
        console.warn('formatBlock fallback failed:', e);
      }
      markDirty(currentEditHost);
      updateToolbarState();
      return;
    }

    // If the block is already the target tag, do nothing.
    if (block.tagName.toLowerCase() === tag.toLowerCase()) {
      return;
    }

    // Replace the block element with a new one of the target tag.
    const newBlock = document.createElement(tag);
    // Move all children.
    while (block.firstChild) {
      newBlock.appendChild(block.firstChild);
    }
    // Copy over class and style (often the user wants those preserved).
    if (block.className) {
      newBlock.className = block.className;
    }
    if (block.getAttribute('style')) {
      newBlock.setAttribute('style', block.getAttribute('style'));
    }
    block.parentNode.replaceChild(newBlock, block);

    markDirty(currentEditHost);
    updateToolbarState();
  }

  function markDirty(host) {
    if (!host) {
      return;
    }
    const instanceId = getInstanceId(host);
    state.dirty.add(instanceId);
    host.classList.add('cbf-host--dirty');
    updateSaveButton();
  }

  // -- Image editing helpers (resize / context menu / alt / insert) --------

  function attachImageEditing(host, img, opts) {
    opts = opts || {};
    img.addEventListener('click', function (e) {
      if (!state.active) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      // Single click selects the image and shows resize handles.
      selectImage(host, img);
    });

    img.addEventListener('contextmenu', function (e) {
      if (!state.active) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      showImageContextMenu(host, img, e, opts);
    });

    img.addEventListener('dblclick', function (e) {
      if (!state.active) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      // Double-click on the image itself = replace (if asset) or edit alt.
      if (opts.noReplace) {
        editImageAltOnTheSpot(host, img);
      } else {
        openImagePicker(host, img);
      }
    });
  }

  function selectImage(host, img) {
    const entry = getRegistryEntry(host);
    if (!entry || !entry.shadowRoot) {
      return;
    }
    // Deselect previous.
    entry.shadowRoot.querySelectorAll('.cbf-img-resizing').forEach(function (el) {
      el.classList.remove('cbf-img-resizing');
      el.querySelectorAll('.cbf-img-resize-handle').forEach(function (h) {
        h.parentNode && h.parentNode.removeChild(h);
      });
    });
    img.classList.add('cbf-img-resizing');
    // Add bottom-right and bottom-left resize handles.
    ['br', 'bl'].forEach(function (corner) {
      const handle = document.createElement('div');
      handle.className = 'cbf-img-resize-handle cbf-img-resize-handle--' + corner;
      handle.setAttribute('contenteditable', 'false');
      img.appendChild ? null : null;
      // Insert as sibling of img if img cannot have children (e.g. <img>).
      if (img.parentNode) {
        img.parentNode.appendChild(handle);
        // Position the handle relative to the image using a wrapper trick:
        // we can't wrap <img> in another element easily, so position the
        // handle absolutely based on img.getBoundingClientRect().
        positionImageHandle(handle, img, corner);
      }
      setupImageResize(handle, img, corner, host);
    });
  }

  function positionImageHandle(handle, img, corner) {
    const rect = img.getBoundingClientRect();
    const root = handle.ownerDocument;
    // The handle lives in the shadow root; we use fixed positioning based
    // on the viewport-relative rect of the image.
    handle.style.position = 'fixed';
    handle.style.top = (corner === 'br' ? rect.bottom - 6 : rect.bottom - 6) + 'px';
    if (corner === 'br') {
      handle.style.left = '';
      handle.style.right = (window.innerWidth - rect.right - 6) + 'px';
    } else {
      handle.style.left = (rect.left - 6) + 'px';
      handle.style.right = '';
    }
  }

  function setupImageResize(handle, img, corner, host) {
    let startX = 0;
    let startY = 0;
    let startWidth = 0;
    let startHeight = 0;
    let dragging = false;

    handle.addEventListener('mousedown', function (e) {
      if (!state.active) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      dragging = true;
      startX = e.clientX;
      startY = e.clientY;
      startWidth = img.offsetWidth;
      startHeight = img.offsetHeight;
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });

    function onMove(e) {
      if (!dragging) {
        return;
      }
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      // Use the larger delta to keep aspect ratio roughly intact.
      let newWidth = startWidth;
      if (corner === 'br') {
        newWidth = Math.max(40, startWidth + dx);
      } else {
        newWidth = Math.max(40, startWidth - dx);
      }
      const ratio = startHeight / startWidth;
      img.style.width = newWidth + 'px';
      img.style.height = Math.round(newWidth * ratio) + 'px';
      // Re-position the handle.
      positionImageHandle(handle, img, corner);
    }

    function onUp() {
      dragging = false;
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      markDirty(host);
    }
  }

  function showImageContextMenu(host, img, e, opts) {
    closeAllPopups();
    const menu = document.createElement('div');
    menu.className = 'cbf-img-context-menu';
    menu.setAttribute('contenteditable', 'false');
    const items = [];
    if (!opts || !opts.noReplace) {
      items.push({ label: Drupal.t('Заменить изображение'), action: function () { openImagePicker(host, img); } });
      items.push({ label: Drupal.t('Загрузить по URL…'), action: function () { uploadFromUrl(host, img); } });
    }
    items.push({ label: Drupal.t('Редактировать alt-текст'), action: function () { editImageAltOnTheSpot(host, img); } });
    items.push({ sep: true });
    items.push({ label: Drupal.t('Сбросить размер'), action: function () {
      img.style.width = '';
      img.style.height = '';
      markDirty(host);
    } });
    items.push({ sep: true });
    items.push({ label: Drupal.t('Удалить изображение'), danger: true, action: function () {
      img.parentNode && img.parentNode.removeChild(img);
      markDirty(host);
    } });

    items.forEach(function (item) {
      if (item.sep) {
        const sep = document.createElement('div');
        sep.className = 'cbf-img-context-menu__sep';
        menu.appendChild(sep);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cbf-img-context-menu__item' + (item.danger ? ' cbf-img-context-menu__item--danger' : '');
      btn.textContent = item.label;
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        closeAllPopups();
        item.action();
      });
      menu.appendChild(btn);
    });

    document.body.appendChild(menu);
    menu.style.position = 'fixed';
    menu.style.top = e.clientY + 'px';
    menu.style.left = e.clientX + 'px';
    // Adjust if overflowing.
    const rect = menu.getBoundingClientRect();
    if (rect.right > window.innerWidth - 4) {
      menu.style.left = (window.innerWidth - rect.width - 4) + 'px';
    }
    if (rect.bottom > window.innerHeight - 4) {
      menu.style.top = (window.innerHeight - rect.height - 4) + 'px';
    }
  }

  function editImageAltOnTheSpot(host, img) {
    closeAllPopups();
    const popup = document.createElement('div');
    popup.className = 'cbf-alt-editor';
    popup.setAttribute('contenteditable', 'false');
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'cbf-alt-editor__input';
    input.value = img.getAttribute('alt') || '';
    input.placeholder = Drupal.t('Alt-текст (для доступности)');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cbf-alt-editor__btn';
    btn.textContent = Drupal.t('Сохранить');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      img.setAttribute('alt', input.value);
      closeAllPopups();
      markDirty(host);
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        btn.click();
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        closeAllPopups();
      }
    });
    popup.appendChild(input);
    popup.appendChild(btn);
    document.body.appendChild(popup);
    const rect = img.getBoundingClientRect();
    popup.style.position = 'fixed';
    popup.style.top = (rect.bottom + 6) + 'px';
    popup.style.left = Math.max(4, rect.left) + 'px';
    input.focus();
    input.select();
  }

  function uploadFromUrl(host, img) {
    closeAllPopups();
    const url = window.prompt(Drupal.t('URL изображения (https://…)'), img.getAttribute('src') || '');
    if (!url) {
      return;
    }
    img.setAttribute('src', url);
    // If the image is a managed asset, switching to an external URL would
    // break the file usage — clear the data-cbf-asset attribute so the
    // save controller treats it as an external image.
    if (img.hasAttribute('data-cbf-asset')) {
      img.removeAttribute('data-cbf-asset');
    }
    markDirty(host);
  }

  function insertNewImage(host) {
    if (!state.active) {
      return;
    }
    const entry = getRegistryEntry(host);
    if (!entry || !entry.shadowRoot) {
      return;
    }
    const root = entry.shadowRoot;
    // Generate a unique asset key.
    const key = 'asset-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
    // Find a sensible insertion point: the currently-focused element, or
    // the first editable text element, or just append to the wrapper.
    let target = root.querySelector('.cbf-shadow-content');
    const sel = root.getSelection ? root.getSelection() : window.getSelection();
    if (sel && sel.rangeCount > 0 && !sel.isCollapsed) {
      const range = sel.getRangeAt(0);
      const img = document.createElement('img');
      img.setAttribute('data-cbf-asset', key);
      img.setAttribute('src', '/core/misc/placeholder.svg');
      img.setAttribute('alt', '');
      img.style.maxWidth = '100%';
      range.insertNode(img);
      // Immediately open the picker so the user can upload a real file.
      selectImage(host, img);
      setTimeout(function () { openImagePicker(host, img); }, 100);
      markDirty(host);
      return;
    }
    // No selection — append at the end of the content wrapper.
    const img = document.createElement('img');
    img.setAttribute('data-cbf-asset', key);
    img.setAttribute('src', '/core/misc/placeholder.svg');
    img.setAttribute('alt', '');
    img.style.maxWidth = '100%';
    img.style.display = 'block';
    img.style.margin = '1em auto';
    target.appendChild(img);
    selectImage(host, img);
    setTimeout(function () { openImagePicker(host, img); }, 100);
    markDirty(host);
  }

  function closeAllPopups() {
    document.querySelectorAll('.cbf-img-context-menu, .cbf-alt-editor').forEach(function (el) {
      el.parentNode && el.parentNode.removeChild(el);
    });
    hideFormatToolbar();
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
      dialog: { width: 600, title: Drupal.t('Заменить изображение') },
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

  // -- Background-image picker -------------------------------------------------

  /**
   * Opens a simple prompt to replace the background-image URL of an
   * element. The user can either paste a new URL or upload a file
   * through the standard image picker (which returns a URL).
   *
   * For simplicity, this uses window.prompt() for the URL input.
   * A future version could open the Drupal modal file picker.
   */
  function openBackgroundImagePicker(host, el) {
    if (!state.active) {
      return;
    }
    // Extract current background-image URL from the style attribute.
    var style = el.getAttribute('style') || '';
    var currentUrl = '';
    var bgMatch = style.match(/background-image:\s*url\((['"]?)([^'")]+)\1\)/i);
    if (!bgMatch) {
      bgMatch = style.match(/background:\s*[^;]*url\((['"]?)([^'")]+)\1\)/i);
    }
    if (bgMatch) {
      currentUrl = bgMatch[2];
    }

    // Ask the user for the new URL.
    var newUrl = window.prompt(Drupal.t('URL фонового изображения (вставьте ссылку или загрузите файл через медиа-библиотеку Drupal и вставьте URL):'), currentUrl);
    if (!newUrl || newUrl === currentUrl) {
      return;
    }

    // Replace the URL in the style attribute.
    if (bgMatch) {
      var quote = bgMatch[1] || '';
      var oldFull = bgMatch[0];
      var newFull = oldFull.replace(currentUrl, newUrl);
      style = style.replace(oldFull, newFull);
      el.setAttribute('style', style);
    }
    else {
      // No existing background-image — add one.
      el.style.backgroundImage = 'url(' + newUrl + ')';
    }

    markDirty(host);
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
      dialog: { width: 500, title: Drupal.t('Редактировать ссылку') },
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
    // Remove background-image editing class.
    clone.querySelectorAll('.cbf-editable-bg-image').forEach(function (el) {
      el.classList.remove('cbf-editable-bg-image');
    });
    // Remove resize handles (they are siblings of the img, not children).
    clone.querySelectorAll('.cbf-img-resize-handle').forEach(function (h) {
      h.parentNode && h.parentNode.removeChild(h);
    });
    clone.querySelectorAll('.cbf-img-resizing').forEach(function (el) {
      el.classList.remove('cbf-img-resizing');
    });
    // Strip inline width/height styles added by resize handles ONLY if the
    // image still has natural dimensions. We keep the style if it was set
    // originally by the author — there is no reliable way to tell the
    // difference, so we keep the resized size (which is what the user
    // intended).
    // NOTE: this means resize persists in the saved HTML, which is the
    // desired behaviour.

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
      saveBtn.textContent = Drupal.t('Сохранение…');
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
          // Handle non-JSON responses (e.g., HTML error pages from
          // access-denied or server errors).
          return r.text().then(function (text) {
            let json;
            try {
              json = JSON.parse(text);
            } catch (e) {
              json = { error: 'Server returned non-JSON response (HTTP ' + r.status + '): ' + text.substring(0, 500) };
            }
            return { host: host, response: json, ok: r.ok, status: r.status };
          });
        }));
      });
    });

    Promise.all(saves).then(function (results) {
      const failed = results.filter(function (r) { return !r.ok || (r.response && r.response.error); });
      // Log each save result to the console for debugging.
      results.forEach(function (r) {
        if (r.ok && !r.response.error) {
          // eslint-disable-next-line no-console
          console.log('Code Block Field: saved', {
            entity_type: r.host.getAttribute('data-cbf-entity-type'),
            entity_id: r.host.getAttribute('data-cbf-entity-id'),
            field: r.host.getAttribute('data-cbf-field-name'),
            delta: r.host.getAttribute('data-cbf-delta'),
            response: r.response,
          });
        } else {
          // eslint-disable-next-line no-console
          console.error('Code Block Field: save failed', {
            status: r.status,
            response: r.response,
            host: r.host,
          });
        }
      });
      results.forEach(function (r) {
        r.host.classList.remove('cbf-host--dirty');
      });
      state.dirty.clear();
      if (saveBtn) {
        saveBtn.textContent = Drupal.t('Сохранить');
        saveBtn.disabled = true;
      }
      if (failed.length) {
        // eslint-disable-next-line no-console
        console.error('Code Block Field: save failed for some blocks', failed);
        const errorMsg = failed[0].response && failed[0].response.error
          ? failed[0].response.error
          : Drupal.t('HTTP @status', { '@status': failed[0].status });
        window.alert(Drupal.t('Не удалось сохранить блоки: @error', { '@error': errorMsg }));
      } else {
        setHint(Drupal.t('Все изменения сохранены.'));
        setTimeout(function () { setHint(''); }, 3000);
      }
    }).catch(function (err) {
      // eslint-disable-next-line no-console
      console.error('Code Block Field: save error', err);
      if (saveBtn) {
        saveBtn.textContent = Drupal.t('Сохранить');
        saveBtn.disabled = false;
      }
      window.alert(Drupal.t('Сохранение не удалось: @error', { '@error': err.message || err }));
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
