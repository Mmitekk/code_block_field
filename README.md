# Code Block Field

A Drupal 10/11 module that provides a new **field type** for storing an
**HTML / CSS / JS bundle** as a single field. The field can be attached to any
entity (node, paragraph, block_content, taxonomy_term, …). On the rendered
page the bundle is mounted inside an **isolated Shadow DOM**, and — for users
with the right permission — can be **edited inline** (text, images, links)
without opening the host entity, similar to page builders like Elementor or
Webflow.

## Features

- New field type `code_block` with sub-fields **HTML**, **CSS**, **JS** and a
  serialised `assets` map (managed files referenced by the HTML).
- Widget backed by **CodeMirror** (HTML / CSS / JS modes, autocomplete, tag
  auto-close) served from CDN.
- Formatter renders each block inside an **open or closed Shadow DOM** so the
  block’s CSS never leaks into the host theme.
- **Inline editor**:
  - Floating toolbar with **Edit mode** toggle, **Save changes**, **Cancel**.
  - `contenteditable` for every text-bearing element (headings, paragraphs,
    spans, list items, table cells, …).
  - Click any `<img data-cbf-asset="key">` to replace it through a Drupal
    modal **file picker** (managed files, with alt-text editing).
  - Double-click (or click the pencil handle on) any `<a>` to edit its
    `href`, target, `rel`, and visible text through a modal form.
  - Dirty tracking: only blocks with actual changes are saved.
  - All saves go through a CSRF-protected JSON endpoint and run the same
    HTML filter that the entity form would run.
- Per-role + per-entity permission model: the `use code block field inline
  editor` permission is required **and** the user must also have `update`
  access on the host entity.
- Configurable HTML filter (powered by the core `filter_html` plugin) and
  configurable upload destination / max filesize.
- File usage tracking: managed files referenced from the HTML are tracked
  with `file.usage` so they are not garbage-collected and are released
  automatically when the entity is deleted.

## Installation

1. Copy the `code_block_field/` directory into your project’s
   `web/modules/custom/` directory (or wherever your site’s contrib/custom
   modules live).
2. Enable the module:
   ```bash
   drush en code_block_field -y
   ```
   or via *Extend* (`/admin/modules`).
3. Visit **Configuration → Content authoring → Code Block Field**
   (`/admin/config/content/code-block-field`) to review the defaults:
   - Allowed HTML tags (used by `filter_html` on every save).
   - Upload destination (defaults to `public://code-block-field`).
   - Maximum upload size (defaults to 5 MB).
   - Shadow DOM mode (open / closed).
4. Add a new field of type **Code Block (HTML / CSS / JS)** to any entity
   (node, paragraph, block_content, …).
5. On the field’s **Manage form display** tab, choose the
   **Code Block editor (CodeMirror)** widget.
6. On the **Manage display** tab, choose the
   **Code Block (Shadow DOM, inline-editable)** formatter and (optionally)
   configure the formatter’s Shadow DOM mode and the image style applied to
   managed assets.

## Usage

### Authoring a block

1. Open the host entity (node / paragraph / block / term) for editing.
2. In the **Code Block** field widget, write the **HTML**, **CSS**, and
   **JS** for your block.
3. To mark an image as a managed asset, use
   `<img data-cbf-asset="my-key" src="…" alt="…">` in the HTML. The first
   time you save the entity the asset map is rebuilt and the file is
   associated with the entity through Drupal’s file-usage system.
4. Save the entity.

### Editing a block inline

1. View the rendered page (any display that uses the
   **Code Block (Shadow DOM, inline-editable)** formatter).
2. If you have the `use code block field inline editor` permission and
   `update` access to the entity, a floating **Code Block** toolbar appears
   in the top-right corner.
3. Click **Edit mode** — every code block on the page gets a dashed outline
   and its text becomes editable in place. Images show a **Replace** badge;
   links get a small ✎ handle.
4. Edit text by clicking it. Replace an image by clicking on it (a modal
   file picker opens). Edit a link by double-clicking it (or clicking the ✎
   handle).
5. Click **Save changes** in the toolbar — every dirty block is POSTed to
   `/admin/code-block-field/inline-save` and saved back into the host
   entity’s field. The page is **not** reloaded; the Shadow DOM keeps the
   new content.
6. Click **Cancel** to discard all unsaved changes and exit edit mode.

## How isolation works

Each block is rendered into its own Shadow DOM root by `js/renderer.js`.
This means:

- The block’s CSS **cannot** leak into the host theme, and the host theme’s
  CSS **cannot** leak into the block.
- The block’s JavaScript runs in the global page scope (Shadow DOM does not
  isolate JS, only DOM/CSS). Inside the block’s script, the variables
  `host` (the host element) and `shadowRoot` (its shadow root) are bound.
  Use `shadowRoot.querySelector(...)` to access the block’s own DOM.

For tighter JS isolation, choose **closed** Shadow DOM mode — external
scripts will not be able to reach into the block through
`element.shadowRoot`. The inline editor still works because it captures a
direct reference to the shadow root when the block is first mounted.

## Permissions

| Permission | Description |
|------------|-------------|
| `use code block field inline editor` | Required to see the floating toolbar and to call the inline-save / image-upload endpoints. |
| `administer code block field` | Required to access the global settings form. |
| `edit code block html directly` | Optional — when denied, the user can only modify the field through the inline editor (the HTML textarea in the entity form is read-only). This is enforced in the widget; not yet wired in the default widget — see `code_block_field_field_widget_form_alter()` if you want to enforce it. |

Additionally, the inline editor always checks `update` access on the host
entity before allowing any change.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/admin/code-block-field/inline-save` | Saves the modified HTML of one field item. CSRF-protected. |
| POST | `/admin/code-block-field/image-upload` | Used internally by the modal image picker (when called from the inline editor outside the entity form). CSRF-protected. |
| GET  | `/admin/code-block-field/image-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{asset_key}` | Modal form for picking an image. |
| GET  | `/admin/code-block-field/link-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{link_key}` | Modal form for editing a link. |

## Theming

The default template `templates/code-block-field.html.twig` emits a single
`.cbf-host` div with the field’s metadata as `data-*` attributes and the
HTML / CSS / JS payload as an embedded `<script type="application/json">`.
You can override this template in your theme (`code-block-field.html.twig`).

The visual chrome of the inline editor lives in
`css/inline-editor.css` (host-document styles) and in a `<style>` tag
injected into each shadow root when edit mode is enabled
(`js/inline-editor.js`, `SHADOW_EDIT_STYLES`).

## Programmatic API

```js
// Activate / deactivate the inline editor from your own JS.
Drupal.codeBlockField.activate();
Drupal.codeBlockField.deactivate();

// Re-render a mounted block with new payload (useful after AJAX).
Drupal.codeBlockField.render(instanceId, { html, css, js });

// Registry of all mounted instances on the page.
window.codeBlockFieldRegistry;
```

## Caveats & known limitations

- The inline editor only modifies the HTML sub-field (and the assets map).
  Changes to the CSS or JS sub-fields must still be done through the entity
  edit form. (You can extend the inline toolbar with a “Code” button that
  opens the CodeMirror editor in a modal — see TODOs in `inline-editor.js`.)
- Filtering of the inline-saved HTML uses the global `code_block_field.settings`
  allowed_html. Per-field overrides are stored on the field but not yet
  applied during inline save.
- The editor relies on `DOMParser` and `Element.matches` — both available in
  all browsers supported by Drupal 10/11.
- CodeMirror is loaded from `cdnjs.cloudflare.com` — for offline use, drop
  the local copies into `assets/codemirror/` and rewrite the entries in
  `code_block_field.libraries.yml`.

## License

GPL-2.0-or-later, same as Drupal core.
