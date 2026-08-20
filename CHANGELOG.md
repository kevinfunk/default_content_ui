# Changelog

## 3.0.0-alpha1

First tagged release. Numbered 3.0.0 rather than 1.0.0 because the `3.x`
branch is the module's third generation: earlier `8.x-1.x` and `2.x`
branches (never tagged) built on the contrib Default Content modules,
while `3.x` is a rewrite on top of Drupal core's own Default Content API.

### Features

- Bulk export UI to select entity types and export entire sets of content.
- Views bulk action to export specific selected items (e.g. from `/admin/content`) as a group.
- Single-entity export via a local task and an operations-list action.
- Import UI to upload a `.zip` archive, with a dry-run field-compatibility report before committing.
- All-or-nothing import: a failure partway through an archive rolls back everything imported earlier in that same batch.
- Automatic fix for a known JSON:API Extras issue where `target_uuid` properties cause core's exporter to output an invalid UUID for User 1.
- Field Mapping submodule (`default_content_ui_mapping`): rename, exclude, or strip fields/values during import to reconcile mismatched field architectures between sites.

### Requirements

- Drupal core `^11.3` (uses core's Default Content API, available in 11.3+)
