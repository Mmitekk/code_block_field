# Code Block Field

A Drupal 10 / 11 module that provides a new field type for storing an **HTML / CSS / JS bundle** in a single field. The field can be attached to any entity (node, paragraph, block_content, taxonomy_term, etc.). On the rendered page the bundle is mounted inside an **isolated Shadow DOM**, and — for users with the right permission — can be **edited inline** (text, images, links) without opening the host entity, similar to page builders like Elementor / Webflow / Bricks.

[Читать документацию на русском](./README.md)

## Features

- **New field type `code_block`** with sub-fields **HTML**, **CSS**, **JS** and a serialised **assets** map (managed files)
- **Shadow DOM rendering** — the block’s CSS does not leak into the host theme and vice versa. Both `open` and `closed` modes are supported
- **Page-builder-style inline editor:**
  - Floating toolbar in the top-right corner with **Edit mode**, **Save changes**, **Cancel** buttons
  - `contenteditable` for every text-bearing element (h1–h6, p, span, a, li, td, figcaption, blockquote, strong, em, b, i, …)
  - Click any `<img data-cbf-asset="key">` to replace it through a Drupal modal file picker, with alt-text editing
  - Double-click any `<a>` (or click the pencil ✎ handle) to edit `href`, `target`, `rel`, link text through a modal form
  - Dirty tracking + CSRF-protected JSON save endpoint
- **CodeMirror 5 editor** in the entity edit form — HTML / CSS / JS modes, autocomplete, tag auto-close, themes (Material Darker / Dracula / Nord / Monokai / Default)
- **Global admin settings page** (like FAQ by URL): HTML filter, upload location, max filesize, Shadow DOM mode, editor colours
- **Per-role permissions** + `update` access check on the host entity on every save
- **File usage tracking** — every image uploaded through the inline editor is associated with the entity through `file.usage` and is released automatically when the entity is deleted
- **HTML filtering** on every save through the core `filter_html` plugin
- **Compatibility** — Drupal 10.x and 11.x

## Installation

### Option 1: Via Composer (recommended)

Composer will automatically download the module into the right directory and manage updates.

1. Add the module repository to your project’s `composer.json`:
   ```bash
   composer config repositories.code_block_field vcs https://github.com/Mmitekk/code_block_field.git
   ```

2. Install the module:
   ```bash
   composer require mmitekk/code_block_field:dev-main
   ```
   The module will be installed into `web/modules/custom/code_block_field/` (the exact path depends on your project layout).

3. Enable the module via Drush or the admin UI:
   ```bash
   drush en code_block_field -y
   ```

4. The module automatically creates the `code_block_field.settings.yml` configuration file with the default values.

**Updating via Composer:**
```bash
composer update mmitekk/code_block_field
drush updb -y
drush cr
```

**Switching to a stable release** (once tags are published):
```bash
composer require mmitekk/code_block_field:^1.0
```

> **Note:** if Composer installs the module somewhere other than `web/modules/custom/`, add the `installer-paths` section to your project’s `composer.json`:
> ```json
> "extra": {
>     "installer-paths": {
>         "web/modules/custom/{$name}": ["type:drupal-custom-module"]
>     }
> }
> ```

### Option 2: Manually

1. Download the archive from GitHub: https://github.com/Mmitekk/code_block_field/archive/refs/heads/main.zip
2. Extract and rename the folder to `code_block_field`
3. Copy the `code_block_field` folder into the `web/modules/custom/` directory of your Drupal site
4. Enable the module via the admin UI (`/admin/modules`) or Drush:
   ```bash
   drush en code_block_field -y
   ```
5. The module automatically creates the default configuration

## Configuration

### Step 1. Add the field to an entity

1. Open **Structure → Content types → [your type] → Manage fields** (or any other entity: paragraph, block_content, taxonomy_term)
2. Click **Add field** → choose the field type **Code Block (HTML / CSS / JS)**
3. Name the field (e.g. `field_code_block`)

### Step 2. Configure the form and display

1. On **Manage form display**, choose the widget **Code Block editor (CodeMirror)**
2. On **Manage display**, choose the formatter **Code Block (Shadow DOM, inline-editable)**
3. The formatter exposes its own settings:
   - **Shadow DOM mode** — `open` / `closed`
   - **Enable inline editing on this display** — toggles the inline editor for this display mode
   - **Image style for managed assets** — which image style to apply to uploaded images

### Step 3. Configure permissions

On **People → Permissions** (`/admin/people/permissions`):

| Permission | Description |
|------------|-------------|
| **Use inline editor for Code Block fields** | Access to the inline editor and the save endpoint. The user must additionally have `update` access on the entity |
| **Administer Code Block Field settings** | Access to the module’s global settings page |
| **Edit raw HTML in Code Block fields** | Access to editing the HTML code in the entity form (can be disabled for content editors, restricting them to the inline editor only) |

### Step 4. Global module settings

Go to **Configuration → Content authoring → Code Block Field** (`/admin/config/content/code-block-field`):

#### HTML filter

| Setting | Description | Default |
|---------|-------------|---------|
| **Filter HTML on save** | Run HTML through `filter_html` on every save | On |
| **Allowed HTML tags** | Allowed tags and attributes (Filter module format) | See below |

#### File storage

| Setting | Description | Default |
|---------|-------------|---------|
| **Upload destination** | Stream wrapper for images uploaded through the inline editor | `public://code-block-field` |
| **Maximum upload size** | Maximum file size (`5 MB`, `1024 KB`, `1G`) | `5 MB` |
| **Default image style** | Default image style applied to rendered assets | Original |

#### Shadow DOM

| Setting | Description | Default |
|---------|-------------|---------|
| **Default Shadow DOM mode** | `Open` (developer-inspectable) or `Closed` (extra isolation) | `Open` |

#### Inline editor

| Setting | Description | Default |
|---------|-------------|---------|
| **Show floating "Edit Mode" button** | Show the floating button on pages with code blocks | On |

#### Editor colours

| Setting | Description | Default |
|---------|-------------|---------|
| **Toolbar background** | Background colour of the floating toolbar | `#1e1e2e` |
| **Toolbar accent** | Accent colour of the active "Edit mode" toggle and toolbar badge | `#ff8a3d` |
| **Editing outline** | Outline drawn around an editable block while edit mode is on | `#ff8a3d` |
| **Dirty outline** | Outline colour of a block with unsaved changes | `#28a745` |
| **Focused outline** | Outline colour of the block containing the element currently being edited | `#0071eb` |

## Usage

### Authoring a block

1. Open the host entity (node / paragraph / block / term) for editing
2. In the **Code Block** field, fill in the **HTML**, **CSS**, **JS** tabs
3. To make an image managed (replaceable via the inline editor), mark it with the `data-cbf-asset` attribute:
   ```html
   <img data-cbf-asset="hero-photo" src="/sites/default/files/placeholder.jpg" alt="Hero photo">
   ```
4. Save the entity

### Inline editing

1. Open the rendered page (any display that uses the **Code Block (Shadow DOM, inline-editable)** formatter)
2. If you have the `use code block field inline editor` permission and `update` access on the entity, a floating **Code Block** toolbar appears in the top-right corner
3. Click **Edit mode** — every code block on the page gets a dashed outline and its text becomes editable in place. Images show a **“✎ Replace”** badge; links get a small pencil handle
4. Edit text by clicking it. Replace an image by clicking on it (a Drupal modal file picker opens). Edit a link by double-clicking it (or clicking the pencil handle)
5. Click **Save changes** — every dirty block is POSTed to `/admin/code-block-field/inline-save` and saved back into the host entity’s field. The page **is not reloaded**; the Shadow DOM keeps the new content
6. Click **Cancel** to discard all unsaved changes and exit edit mode

## Module structure

```
code_block_field/
├── code_block_field.info.yml             — Module metadata
├── code_block_field.module               — Module hooks (theme, help, entity hooks)
├── code_block_field.install              — Install/uninstall hooks
├── code_block_field.routing.yml          — Routes (save, upload, picker dialogs)
├── code_block_field.permissions.yml      — Permissions
├── code_block_field.links.menu.yml       — Admin menu item
├── code_block_field.libraries.yml        — Attached libraries (CodeMirror + custom)
├── composer.json                         — Composer metadata
├── config/
│   ├── install/code_block_field.settings.yml — Default settings
│   └── schema/code_block_field.schema.yml    — Configuration schema
├── css/
│   ├── codemirror-overrides.css          — CodeMirror style overrides
│   ├── widget.css                        — Entity-form widget styles
│   └── inline-editor.css                 — Inline editor styles (toolbar, outlines)
├── js/
│   ├── codemirror-init.js                — CodeMirror initialisation on textareas
│   ├── widget.js                         — HTML ↔ hidden assets field sync
│   ├── renderer.js                       — Shadow DOM mounting
│   └── inline-editor.js                  — Inline editor (toolbar, contenteditable, pickers)
├── templates/
│   └── code-block-field.html.twig        — Formatter Twig template
└── src/
    ├── Controller/
    │   └── InlineEditController.php      — AJAX endpoints (save, upload, picker dialogs)
    ├── Form/
    │   ├── SettingsForm.php              — Global settings form
    │   ├── InlineImagePickerForm.php     — Modal image picker form
    │   └── InlineLinkPickerForm.php      — Modal link editor form
    └── Plugin/Field/
        ├── FieldType/CodeBlockItem.php   — Field type (html, css, js, assets)
        ├── FieldWidget/CodeBlockWidget.php     — CodeMirror-backed widget
        └── FieldFormatter/CodeBlockFormatter.php — Shadow DOM formatter
```

## How isolation works

Each block is rendered into its own Shadow DOM root by `js/renderer.js`:

- The block’s CSS **cannot** leak into the host theme, and the host theme’s CSS **cannot** leak into the block
- JavaScript executes in the global page scope (Shadow DOM isolates DOM and CSS, but not JS). Inside the block’s script, the variables `host` (the host element) and `shadowRoot` (its shadow root) are bound. Use `shadowRoot.querySelector(...)` to access the block’s own DOM
- For tighter isolation, choose the **Closed** mode — external scripts will not be able to reach into the block through `element.shadowRoot`. The inline editor still works because it captures a direct reference to the shadow root when the block is first mounted

## Customisation

### Template override

Copy `templates/code-block-field.html.twig` into your theme folder and adjust as needed. The template emits a single `<div class="cbf-host">` with `data-*` attributes and an embedded `<script type="application/json">` with the payload.

### Inline-editor style overrides

Colours are configurable through the admin UI (`/admin/config/content/code-block-field`) and are emitted as CSS custom properties. For deeper overrides, use your own styles in the theme:

```css
.cbf-inline-toolbar {
  --cbf-toolbar-bg: #your-color;
  --cbf-toolbar-accent: #your-color;
  --cbf-edit-outline: #your-color;
  --cbf-dirty-outline: #your-color;
  --cbf-focus-outline: #your-color;
}
```

### Programmatic API

```javascript
// Activate/deactivate the inline editor from your own JS
Drupal.codeBlockField.activate();
Drupal.codeBlockField.deactivate();

// Re-render a mounted block with a new payload (useful after AJAX)
Drupal.codeBlockField.render(instanceId, { html, css, js });

// Registry of all mounted instances on the page
window.codeBlockFieldRegistry;
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/admin/code-block-field/inline-save` | Saves the modified HTML of one field item. CSRF-protected |
| POST | `/admin/code-block-field/image-upload` | Uploads an image from the inline editor. CSRF-protected |
| GET  | `/admin/code-block-field/image-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{asset_key}` | Modal image picker form |
| GET  | `/admin/code-block-field/link-picker/{entity_type}/{entity_id}/{field_name}/{delta}/{link_key}` | Modal link editor form |

## Caveats and known limitations

- The inline editor only modifies the HTML sub-field (and the assets map). CSS / JS sub-fields must be edited through the entity form
- Filtering of inline-saved HTML uses the global `code_block_field.settings.allowed_html`. Per-field overrides are stored but are not yet applied during inline save
- The editor relies on `DOMParser` and `Element.matches` — available in every browser supported by Drupal 10 / 11
- CodeMirror is loaded from `cdnjs.cloudflare.com`. For offline use, drop local copies into `assets/codemirror/` and rewrite the URLs in `code_block_field.libraries.yml`
- The CSRF token is generated per session, so the inline editor’s render array uses `#cache['max-age'] = 0`

## Uninstallation

1. Disable the module via the admin UI or Drush:
   ```bash
   drush pm:uninstall code_block_field -y
   ```
2. On uninstall, Drupal automatically removes the module’s configuration. Field storage and field data are also removed automatically. File usage records are released through `hook_entity_delete()` when the host entity is deleted

## Compatibility

| Drupal | PHP | Status |
|--------|-----|--------|
| 10.x   | 8.1+ | Fully supported |
| 11.x   | 8.3+ | Fully supported |

## License

GPL-2.0-or-later, same as Drupal core.

## Author

- **Mmitekk** — [https://github.com/Mmitekk](https://github.com/Mmitekk)

## Links

- **Repository:** [https://github.com/Mmitekk/code_block_field](https://github.com/Mmitekk/code_block_field)
- **Issues:** [https://github.com/Mmitekk/code_block_field/issues](https://github.com/Mmitekk/code_block_field/issues)
- **Документация на русском:** [README.md](./README.md)
