# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] — 2026-07-01

### Removed

- **All HTML processing has been removed from the save pipeline.** Despite
  five previous attempts to fix SVG-attribute stripping (1.2.2 → 1.2.6),
  some sites still saw HTML being mangled on save. Rather than continue
  patching, the entire HTML-processing layer has been deleted:

  - `hook_entity_presave()` no longer calls `filter_html` or
    `ProcessImages::process()`. The only thing it still does is register
    `file.usage` for managed assets — it never touches the HTML.
  - `InlineEditController::save()` no longer calls `filterHtml()` or
    `ProcessImages::process()`. The HTML sent by the inline editor is
    stored verbatim.
  - `InlineEditController::reconcileAssets()` has been rewritten to use
    a regex instead of `DOMDocument`. This was the last remaining
    `DOMDocument` instance in the codebase.
  - `CodeBlockFormatter::processAssets()` already used a regex (since
    1.2.3) and is unchanged.

  The `filter_html` and `auto_assign_asset_keys` settings still exist in
  the config schema (so the settings form does not break) but they no
  longer have any effect — the form fields are kept for forward
  compatibility in case someone wants to re-enable filtering through a
  future hook alter.

- **The field now stores exactly what the author entered.** No tag
  stripping, no attribute normalisation, no DOMDocument round-tripping
  anywhere in the pipeline. SVG icons like
  ```html
  <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
       stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
       stroke-linejoin="round">
    <polyline points="20 6 9 17 4 12"></polyline>
  </svg>
  ```
  survive the save pipeline intact.

## [1.2.6] — 2026-07-01

### Changed

- **HTML filtering and auto-asset-assignment are now OFF by default.**
  Despite all the previous fixes (1.2.2 — expanded `allowed_html`,
  1.2.3 — removed `filterHtml` from the formatter, 1.2.4 — custom
  case-sensitive `HtmlFilter`, 1.2.5 — `ProcessImages` on regex),
  some sites still saw HTML being mangled on save. The cause on those
  sites is likely PHP opcode cache keeping the old code, or other
  modules' `hook_entity_presave` implementations running after ours
  and re-filtering the HTML.

  Since `code_block_field` is a field for arbitrary HTML/CSS/JS where
  the author is responsible for the content, any HTML processing here
  causes more problems than it solves. Both `filter_html` and
  `auto_assign_asset_keys` are now `false` by default in
  `config/install/code_block_field.settings.yml`.

- Added `hook_update_N()` (update 8102) that **forces** both
  `filter_html` and `auto_assign_asset_keys` to `false` on existing
  installations. After running `drush updb`, the field's HTML is saved
  and rendered exactly as the author entered it — no tag stripping,
  no attribute normalisation, no DOMDocument round-tripping anywhere
  in the pipeline.

  Users who actually want filtering can re-enable it on
  `/admin/config/content/code-block-field`.

## [1.2.5] — 2026-07-01

### Fixed

- **SVG attributes were STILL being stripped on save even after 1.2.4.**
  Root cause: `ProcessImages::process()` (used by `hook_entity_presave`
  when `auto_assign_asset_keys` is enabled) was round-tripping the entire
  HTML through `DOMDocument` to find `<img>` tags and add `data-cbf-asset`
  attributes. DOMDocument:
  - Lowercases attribute names (`viewBox` → `viewbox`)
  - Self-closes empty tags (`<polyline></polyline>` → `<polyline />`)
  - Drops attributes it does not understand

  So even though our new `HtmlFilter` (introduced in 1.2.4) preserves
  attribute case, by the time it ran the HTML had already been mangled
  by `ProcessImages`.

- **Rewrote `ProcessImages::process()` to use a regex instead of
  DOMDocument.** The new implementation uses `preg_replace_callback` to
  patch only the `<img>` tags that need a `data-cbf-asset` attribute,
  leaving the rest of the HTML (including `<svg>`, `<polyline>`, etc.)
  completely untouched.

- After this fix, SVG icons like
  ```html
  <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
       stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
       stroke-linejoin="round">
    <polyline points="20 6 9 17 4 12"></polyline>
  </svg>
  ```
  survive the save pipeline intact.

## [1.2.4] — 2026-07-01

### Fixed

- **SVG attributes (viewBox, fill, stroke, etc.) were STILL being stripped on
  save even after 1.2.3.** Root cause: the core `filter_html` plugin (which
  our `code_block_field_filter_html()` helper was delegating to) uses
  `DOMDocument` internally, which lowercases attribute names. SVG
  attributes like `viewBox` were being stored as `viewbox` in the DOM,
  but the whitelist check in `filter_html` is case-sensitive — so `viewbox`
  never matched `viewBox` in `allowed_html`, and the attribute was silently
  stripped. The 1.2.3 fix (removing filterHtml from the formatter) only
  fixed the render path; the save path still went through the broken
  core filter.

- **Replaced the core `filter_html` plugin with a custom regex-based
  `HtmlFilter` class.** The new `Drupal\code_block_field\HtmlFilter`:
  - Parses the `allowed_html` string (same format as the Filter module)
    into a lowercased whitelist for case-insensitive lookup.
  - Walks through every HTML tag with a regex (`<((/?)([a-zA-Z][a-zA-Z0-9]*))([^>]*)>`).
  - Removes tags and attributes that are not in the whitelist.
  - **Preserves the original case of attribute names** in the output —
    so `<svg viewBox="...">` stays `<svg viewBox="...">` instead of
    being lowercased to `viewbox`.
  - Always allows the `data-cbf-asset` attribute (used internally by
    the inline editor) regardless of the whitelist.
  - Supports wildcard attributes (e.g. `data-*`).

- After this fix, SVG icons like
  ```html
  <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
       stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
       stroke-linejoin="round">
    <polyline points="20 6 9 17 4 12"></polyline>
  </svg>
  ```
  survive the save filter intact.

## [1.2.3] — 2026-07-01

### Fixed

- **SVG attributes were still being stripped on render even after the
  1.2.2 fix.** Root cause: the formatter was re-filtering HTML on every
  render through the core `filter_html` plugin, which internally uses
  `DOMDocument`. `DOMDocument` normalises attribute names to lowercase
  (`viewBox` → `viewbox`, `strokeWidth` → `strokewidth`, etc.) before
  the filter checks them against the case-sensitive `allowed_html`
  whitelist — so `viewBox` was being silently dropped even though it was
  listed in `allowed_html`. The fix has three parts:

  1. **Removed `filterHtml()` call from the formatter.** HTML is no
     longer re-filtered on render. Filtering happens once, at save
     time — the same content that is in the database is what gets sent
     to the browser. This also fixes a double-filtering issue where
     inline-saved HTML was filtered twice (once in
     `InlineEditController::save`, once in the formatter on the next
     page load).
  2. **Rewrote `processAssets()` to use a regex instead of
     `DOMDocument`.** The old implementation round-tripped the entire
     HTML through `DOMDocument` to find `<img data-cbf-asset="...">`
     tags, which normalised the case of every attribute in the HTML
     (including SVG attributes inside the same block). The new
     implementation uses `preg_replace_callback` to patch only the
     `src` (and optionally `alt`) attributes of `<img>` tags with a
     `data-cbf-asset` attribute, leaving the rest of the HTML
     completely untouched.
  3. **Added `filterHtml` call to `hook_entity_presave()`.** Previously,
     HTML saved through the entity form was never filtered (only
     inline-saved HTML was filtered in `InlineEditController::save`).
     Now both save paths filter through the same centralised
     `code_block_field_filter_html()` helper function, so the
     behaviour is consistent and the formatter can safely skip
     filtering.

- Added `code_block_field_filter_html()` helper function in
  `code_block_field.module` to centralise the filter_html invocation
  logic. Used by both `hook_entity_presave()` and
  `InlineEditController::filterHtml()`.

## [1.2.2] — 2026-07-01

### Fixed

- **SVG presentation attributes were stripped by the HTML filter.** The
  default `allowed_html` list only allowed a handful of SVG attributes
  (`viewBox`, `xmlns`, `width`, `height` on `<svg>`; `points` on
  `<polyline>` and `<polygon>`; `d` on `<path>`; etc.). Common
  presentation attributes like `fill`, `stroke`, `stroke-width`,
  `stroke-linecap`, `stroke-linejoin`, `stroke-dasharray`,
  `stroke-opacity`, `fill-opacity`, `fill-rule`, `clip-path`, `opacity`,
  `transform` were silently dropped on save, which broke inline SVG
  icons such as:
  ```html
  <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
       stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
       stroke-linejoin="round">
    <polyline points="20 6 9 17 4 12"></polyline>
  </svg>
  ```
  After this fix, the default `allowed_html` includes the full set of
  SVG presentation attributes on every SVG element (`<svg>`, `<path>`,
  `<circle>`, `<rect>`, `<line>`, `<polyline>`, `<polygon>`,
  `<ellipse>`, `<g>`), plus the `<defs>`, `<clipPath>`, `<mask>`,
  `<linearGradient>`, `<radialGradient>`, `<stop>` elements needed for
  gradient and mask definitions. `<source>` now also allows `sizes`
  and `type`. `<iframe>` allows `loading`. `<video>` and `<audio>`
  allow `poster`/`preload`. `<use>` allows `xlink:href` in addition
  to `href`.
- Added `hook_update_N()` (update 8101) that automatically upgrades the
  `allowed_html` setting for existing installations to the new default
  (only if the user has not customised it — otherwise a warning
  message is shown with instructions on how to update manually).

## [1.2.1] — 2026-07-01

### Fixed

- **CSS variables (`var(--...)`) and `:root` selectors did not work inside
  the Shadow DOM.** User-authored CSS typically defines custom properties
  on `:root`, but inside a shadow root `:root` refers to the document root
  (which is outside the shadow boundary), so the variables were invisible.
  The renderer now pre-processes every block's CSS with a `prepareCss()`
  function that:
  1. Rewrites `:root { ... }` selectors to `:host { ... }` so that
     custom properties resolve to the host element of the shadow root.
  2. Rewrites `html`, `body`, `html, body` selectors to `:host` (there is
     no `<html>` or `<body>` inside a shadow root — `:host` is the closest
     equivalent).
  3. Prepends a bridge stylesheet that sets `--var: inherit;` for every
     custom property currently declared on `document.documentElement`,
     so that theme-level variables also propagate into the shadow root.
     This means blocks can mix their own variables with theme variables.
- After this fix, blocks that use `:root { --blue: ...; }` followed by
  `color: var(--blue)` (very common authoring pattern) render correctly.

## [1.2.0] — 2026-07-01

### Added

- **Russian interface translation.** All user-facing strings in the module
  settings form, widget, formatter, modal pickers, help text, permissions,
  menu link and inline editor (toolbar buttons, context menus, alt-editor
  popup, save/cancel/error messages) are now in Russian. The strings are
  still wrapped in `t()` / `Drupal.t()`, so they participate in Drupal's
  translation system — English sites can override them through a `.po` file.
- **Auto-assignment of `data-cbf-asset` to images.** New module setting
  "Автоматически добавлять data-cbf-asset картинкам" (default: on). When
  enabled, every `<img>` in the HTML that does not already have a
  `data-cbf-asset` attribute gets a unique key (e.g.
  `auto-asset-68a1f3-2`) assigned on save — both through the entity form
  and through the inline editor. This means:
  - Every image in a code block is editable through the inline editor
    without manual markup.
  - If the `src` points to a Drupal managed file (e.g.
    `/sites/default/files/foo.jpg`), the file is looked up by URI and its
    `fid` is recorded in the assets map, with `file.usage` registered
    against the host entity. Existing images immediately become managed
    assets without manual key assignment.
  - Image-style derivatives (e.g. `/sites/default/files/styles/large/...`)
    are resolved back to the original file URI.
  - Works on Drupal 9.5+ / 10.x / 11.x — uses the
    `stream_wrapper_manager` service to convert paths to URIs rather than
    `FileUrlGenerator::generateUriFromString()` (which only exists in
    Drupal 10.3+).

### Changed

- Module description in `info.yml`, permissions labels, menu link label
  and help text are now in Russian.

## [1.1.0] — 2026-07-01

### Added

- **WYSIWYG floating format toolbar.** When the user selects text inside an
  editable element, a floating toolbar appears above the selection (Medium /
  Notion style) with the following buttons:
  - **B / I / U / S** — bold, italic, underline, strikethrough
  - **H2 / H3 / H4 / ¶** — convert the current block to a heading or paragraph
  - **⬅ ⬌ ➡ ☰** — left / centre / right / justify alignment
  - **• / 1.** — bulleted and numbered lists
  - **A** with a colour picker — text colour
  - **🔗** — insert/edit a link (uses the existing modal link picker)
  - **⌫** — clear formatting
  The toolbar highlights active formats (e.g. B is highlighted when the
  selection is bold). It hides automatically when the selection collapses.
- **Image editing improvements:**
  - **Resize handles.** Clicking an image in edit mode shows two corner
    handles (bottom-left and bottom-right) for resizing by drag. The aspect
    ratio is preserved. The new size is saved as inline `style="width:…"`.
  - **Context menu.** Right-clicking an image opens a menu with: Replace
    image, Upload from URL…, Edit alt text, Reset size, Delete image.
  - **Alt editing on the spot.** Edit alt text in a small popup next to
    the image (no modal).
  - **Insert new image.** A floating "+" button in the top-right of every
    block adds a new `<img data-cbf-asset="…">` and immediately opens the
    file picker.
  - **Upload from URL.** Replace an image with an external URL through a
    prompt. The `data-cbf-asset` attribute is removed so the file-usage
    tracking stays consistent.
- **Revision support (optional).** A new "Create a new entity revision on
  every inline save" checkbox in the module settings, with a configurable
  revision log message (`%date` is replaced with the current date/time).
  When enabled, every inline save creates a new revision of the host
  entity (node, paragraph, …). The response includes `revision_id` when a
  revision was created. Only applies to entity types that implement
  `RevisionableInterface`.

### Changed

- **Default allowed_html now permits `style` on common block elements**
  (`p`, `h1`–`h6`, `ul`, `ol`, `li`, `table`, `tr`, `td`, `th`, `div`,
  `span`, `img`, `figure`, `section`, …) so that inline-resized images and
  alignment styles persist through the HTML filter on save. Existing sites
  that already installed the module need to update the allowed_html setting
  manually on the module settings page, or run
  `drush config-delete code_block_field.settings allowed_html && drush cr`
  to pick up the new default.

## [1.0.3] — 2026-07-01

### Added

- **Drupal 9.5 compatibility.** The module now declares
  `core_version_requirement: ^9.5 || ^10 || ^11` and is fully usable on
  Drupal 9.5.x with PHP 7.4+. Updated the README/README.en compatibility
  tables and composer.json `drupal/core` constraint accordingly.

### Fixed

- **Incompatible return-type declarations on overridden methods.** Several
  overrides of `WidgetBase`, `FormatterBase`, `FieldItemBase`, `ConfigFormBase`
  and `FormBase` methods declared strict return types (`: array`, `: string`,
  `: void`, `: ?string`, `: AjaxResponse`, `: static`) that the parent
  methods in Drupal core do **not** declare. PHP does not allow a subclass
  to add a return type that the parent does not have, so this caused fatal
  `Compile Error: Declaration of ... must be compatible with ...` errors
  on every code path that loaded the widget, formatter or field type.
  Removed every return-type declaration from overridden methods so the
  signatures match Drupal core across 9.5 / 10 / 11.
- **`Drupal\Core\Controller\ControllerBase::filterManager()` does not exist.**
  The inline-save controller called `$this->filterManager()` which is not
  a method of `ControllerBase` in any Drupal version (9.5 / 10 / 11).
  Replaced with `\Drupal::service('plugin.manager.filter')` so the
  filter_html plugin is correctly instantiated.

## [1.0.2] — 2026-07-01

### Fixed

- **TypeError on saving a paragraph with a Code Block field.**
  `CodeBlockWidget::extractFormValues()` declared a strict `: array` return
  type and returned the value of `parent::extractFormValues()`. In Drupal 11
  the parent method has no return type and does not actually return a value
  (it works via side-effects on `$items`), so `null` was returned and the
  strict type check threw `TypeError: Return value must be of type array,
  null returned`.
- Removed the `extractFormValues()` override entirely. File-usage
  registration for managed assets is already handled by
  `hook_entity_presave()` (which is properly guarded against non-fieldable
  entities since 1.0.1), so the override was duplicating work and was the
  only reason the fatal occurred.

## [1.0.1] — 2026-07-01

### Fixed

- **Fatal error on saving field configuration.** `hook_entity_presave()` and
  `hook_entity_delete()` were calling `getFieldDefinitions()` on every entity
  that Drupal saved — including configuration entities (`FieldStorageConfig`,
  `FieldConfig`, `ImageStyle`, etc.) that do not implement
  `FieldableEntityInterface` and do not have that method. Both hooks now
  short-circuit with an `instanceof FieldableEntityInterface` check so the
  file-usage sync logic only runs on content entities that actually have
  fields.

## [1.0.0] — 2026-07-01

### Added

- New field type `code_block` with sub-fields `html`, `css`, `js` and a
  serialised `assets` map (managed files).
- `CodeBlockWidget` widget backed by **CodeMirror 5** (HTML / CSS / JS modes,
  autocomplete, tag auto-close, multiple themes).
- `CodeBlockFormatter` formatter that renders each block inside an isolated
  **Shadow DOM** (open / closed mode configurable).
- **Inline editor** (page-builder style):
  - Floating toolbar with `Edit mode` toggle, `Save changes`, `Cancel`.
  - `contenteditable` for every text-bearing element.
  - Click `<img data-cbf-asset>` to replace via Drupal modal file picker.
  - Double-click `<a>` to edit `href` / `target` / `rel` / text via modal.
  - Dirty tracking + CSRF-protected JSON save endpoint.
- Global admin settings page at `/admin/config/content/code-block-field`
  with fieldsets for HTML filter, file storage, Shadow DOM, inline editor,
  and editor colours.
- Three permissions: `use code block field inline editor`,
  `administer code block field`, `edit code block html directly`.
- File usage tracking (`file.usage`) for managed assets.
- HTML filtering on every save through the core `filter_html` plugin.
- `composer.json` with `drupal-custom-module` type, installer-paths, and
  suggested modules.
- `code_block_field.install` with a welcome message and uninstall notes.
- `code_block_field.links.menu.yml` adding a menu item under
  *Configuration → Content authoring*.
- Russian (`README.md`) and English (`README.en.md`) documentation
  with installation, configuration, usage, structure, and customisation
  instructions.

[1.3.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.3.0
[1.2.6]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.6
[1.2.5]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.5
[1.2.4]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.4
[1.2.3]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.3
[1.2.2]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.2
[1.2.1]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.1
[1.2.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.0
[1.1.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.1.0
[1.0.3]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.3
[1.0.2]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.2
[1.0.1]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.1
[1.0.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.0
