/**
 * @file
 * Mounts the Shadow DOM for every .cbf-host element on the page and
 * populates it with the HTML / CSS / JS stored in the embedded JSON
 * payload. Also keeps a registry of every mounted instance so the
 * inline editor can reach into the shadow roots later.
 */
(function (Drupal, once) {
  'use strict';

  // Registry of mounted instances keyed by the data-cbf-instance id.
  // Each entry contains: { host, shadowRoot, payload, shadowMode }.
  window.codeBlockFieldRegistry = window.codeBlockFieldRegistry || {};

  function readPayload(host) {
    const script = host.querySelector('script.cbf-payload[type="application/json"]');
    if (!script) {
      return { html: '', css: '', js: '' };
    }
    try {
      return JSON.parse(script.textContent);
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('Code Block Field: invalid payload', e);
      return { html: '', css: '', js: '' };
    }
  }

  function mountShadow(host) {
    if (host.shadowRoot) {
      return host.shadowRoot;
    }
    const mode = host.getAttribute('data-cbf-shadow-mode') || 'open';
    const shadowRoot = host.attachShadow({ mode: mode === 'closed' ? 'closed' : 'open' });
    return shadowRoot;
  }

  /**
   * Pre-processes the user-supplied CSS so that it works correctly inside
   * a Shadow DOM. Two transformations are applied:
   *
   *  1. `:root { ... }` selectors are rewritten to `:host { ... }`.
   *     The user-authored CSS typically defines custom properties
   *     (--blue, --accent, ...) on `:root`, which inside a shadow root
   *     refers to the document root — outside the shadow boundary — so
   *     the variables would be invisible. Rewriting to `:host` makes
   *     the variables resolve to the host element of the shadow root,
   *     which is the intended behaviour.
   *
   *  2. A small bridge stylesheet is prepended that uses `inherit` on
   *     `:host` for every custom property declared on the document's
   *     `:root`. This lets site-level variables (e.g. ones declared by
   *     the theme) propagate into the shadow root, so authors can mix
   *     their own variables with theme variables. Only properties that
   *     actually exist on `document.documentElement` are bridged — no
   *     guessing.
   */
  function prepareCss(css) {
    if (!css) {
      return css;
    }
    let processed = css;

    // 1. Replace `:root { ... }` with `:host { ... }`. Custom properties
    //    defined on :root in light DOM are invisible inside the shadow
    //    root; :host is the correct selector for the host element of the
    //    shadow root.
    processed = processed.replace(/(^|\s|;|}):root\s*\{/g, function (m, prefix) {
      return prefix + ':host {';
    });

    // 2. Replace `html, body` (or `body`, `html`) selectors with `:host`.
    //    Inside a shadow root there is no <html> or <body>; using :host
    //    applies the styles to the host element which is the closest
    //    equivalent. We do this BEFORE the variable-bridge injection so
    //    that user styles for body (font, color, background, etc.) apply
    //    to the host element.
    processed = processed.replace(/(^|\s|;|})\s*(html\s*,\s*body|body\s*,\s*html|html|body)\s*\{/g, function (m, prefix) {
      return prefix + ' :host {';
    });

    // 3. Build a bridge of `--var: inherit;` declarations for every custom
    //    property currently declared on document.documentElement so that
    //    theme-level variables also resolve inside the shadow root.
    let bridge = '';
    try {
      const rootStyle = window.getComputedStyle(document.documentElement);
      for (let i = 0; i < rootStyle.length; i++) {
        const name = rootStyle[i];
        if (typeof name === 'string' && name.indexOf('--') === 0) {
          bridge += '  ' + name + ': inherit;\n';
        }
      }
    } catch (e) {
      // getComputedStyle on documentElement is supported everywhere, but
      // be defensive — if it throws, just skip the bridge.
    }
    if (bridge) {
      processed = ':host {\n' + bridge + '}\n' + processed;
    }
    return processed;
  }

  function populateShadow(host, shadowRoot, payload) {
    // Build the inner DOM.
    const wrapper = document.createElement('div');
    wrapper.className = 'cbf-shadow-content';
    wrapper.innerHTML = payload.html || '';

    const style = document.createElement('style');
    style.textContent = prepareCss(payload.css || '');

    // Reset the shadow root.
    while (shadowRoot.firstChild) {
      shadowRoot.removeChild(shadowRoot.firstChild);
    }
    shadowRoot.appendChild(style);
    shadowRoot.appendChild(wrapper);

    // Execute the user’s JS in a function scope where `host` and
    // `shadowRoot` are bound. Use Function() so that the script runs in
    // the global scope (no closure leakage from this file’s locals).
    if (payload.js && payload.js.trim().length) {
      try {
        // eslint-disable-next-line no-new-func
        const userFn = new Function(
          'host',
          'shadowRoot',
          'document',
          'window',
          '"use strict";\n' + payload.js
        );
        userFn.call(host, host, shadowRoot, document, window);
      } catch (e) {
        // eslint-disable-next-line no-console
        console.error('Code Block Field: error running block JS', e);
      }
    }

    return wrapper;
  }

  Drupal.behaviors.codeBlockFieldRenderer = {
    attach: function (context) {
      once('cbf-renderer', '.cbf-host', context).forEach(function (host) {
        const payload = readPayload(host);
        const shadowRoot = mountShadow(host);
        populateShadow(host, shadowRoot, payload);

        const instanceId = host.getAttribute('data-cbf-instance');
        window.codeBlockFieldRegistry[instanceId] = {
          host: host,
          shadowRoot: shadowRoot,
          payload: payload,
          shadowMode: host.getAttribute('data-cbf-shadow-mode') || 'open',
        };

        host.classList.add('cbf-host--mounted');
      });
    },
  };

  // Public helper: re-render a mounted instance with new payload.
  Drupal.codeBlockField = Drupal.codeBlockField || {};
  Drupal.codeBlockField.render = function (instanceId, payload) {
    const entry = window.codeBlockFieldRegistry[instanceId];
    if (!entry) {
      return;
    }
    entry.payload = payload;
    populateShadow(entry.host, entry.shadowRoot, payload);
  };
})(Drupal, once);
