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

  function populateShadow(host, shadowRoot, payload) {
    // Build the inner DOM.
    const wrapper = document.createElement('div');
    wrapper.className = 'cbf-shadow-content';
    wrapper.innerHTML = payload.html || '';

    const style = document.createElement('style');
    style.textContent = payload.css || '';

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
