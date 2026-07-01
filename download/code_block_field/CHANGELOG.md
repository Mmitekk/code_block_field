# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.2.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.2.0
[1.1.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.1.0
[1.0.3]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.3
[1.0.2]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.2
[1.0.1]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.1
[1.0.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.0
