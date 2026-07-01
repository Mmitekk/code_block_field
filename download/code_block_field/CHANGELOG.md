# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.1]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.1
[1.0.0]: https://github.com/Mmitekk/code_block_field/releases/tag/1.0.0
