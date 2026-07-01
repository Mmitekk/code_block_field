/**
 * @file
 * Initialises CodeMirror editors on every textarea.code-block-editor
 * element on the page. Runs once per widget via the `once` library.
 */
(function (Drupal, once, CodeMirror) {
  'use strict';

  Drupal.behaviors.codeBlockFieldCodeMirror = {
    attach: function (context) {
      once('cbf-codemirror', 'textarea.code-block-editor', context).forEach(function (textarea) {
        if (typeof CodeMirror === 'undefined') {
          return;
        }
        const wrapper = textarea.closest('[data-cbf-widget]');
        const settings = wrapper ? JSON.parse(wrapper.getAttribute('data-cbf-settings') || '{}') : {};
        const mode = textarea.getAttribute('data-cbf-mode') || 'htmlmixed';
        const editor = CodeMirror.fromTextArea(textarea, {
          mode: mode,
          theme: settings.theme || 'material-darker',
          tabSize: parseInt(settings.tabSize || 2, 10),
          indentUnit: parseInt(settings.tabSize || 2, 10),
          indentWithTabs: false,
          lineNumbers: settings.lineNumbers !== false,
          lineWrapping: true,
          autoCloseTags: settings.autoCloseTags !== false,
          autoCloseBrackets: true,
          matchBrackets: true,
          matchTags: { bothTags: true },
          extraKeys: {
            'Ctrl-Space': 'autocomplete',
            'Cmd-Space': 'autocomplete',
          },
        });

        // Ensure the editor’s value is synced back to the textarea on submit.
        const form = textarea.form;
        if (form) {
          form.addEventListener('submit', function () {
            editor.save();
          });
        }

        // Resize the editor with the details element when it is opened/closed.
        if (wrapper && wrapper.tagName === 'DETAILS') {
          wrapper.addEventListener('toggle', function () {
            setTimeout(function () { editor.refresh(); }, 50);
          });
        }

        // Expose the editor instance for the inline asset sync logic.
        textarea.codeMirrorEditor = editor;
      });
    },
  };
})(Drupal, once, window.CodeMirror);
