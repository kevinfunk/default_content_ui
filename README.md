# Default Content UI

Provides a User Interface for the Drupal Core Default Content API (available in Drupal 11.3+).

This module replaces the need for Drush commands when exporting content for recipes or module installation.

## Features

* **Bulk Export UI**: Select specific entity types to export entire sets of content.
* **Views Bulk Export**: Select specific items from any View (like `/admin/content`) to export as a group.
* **Single Entity Export**: Export individual entities directly.
    * Adds an **Export** tab to content entities.
    * Adds an **Export** action to entity operation lists.
* **Import UI**: Upload a `.zip` archive to import content.
    * Uses the core `Importer` service to handle dependencies and entity creation.

## Requirements

* Drupal 11.3 or higher.
* `default content export` permission is required to export.
* `default content import` permission is required to import.

## Usage

### Bulk Export (Global)
1.  Navigate to **Configuration > Development > Default content** (`/admin/config/development/default-content`).
2.  Select the **Export** tab.
3.  Select the entity types you wish to export.
4.  Optionally check **Include dependencies** to automatically export referenced content (e.g., tags, media).
5.  Click **Export Content**. The archive will be generated and downloaded automatically.

### Views Bulk Export (Selection)
You can export specific sets of content using Drupal's "Action" system on Views (e.g., the Content administration page).
1.  Navigate to a View with bulk operations enabled (e.g., `/admin/content`).
2.  Check the boxes next to the items you want to export.
3.  In the **Action** dropdown, select **Export Default Content**.
4.  Click **Apply to selected items**.
5.  A ZIP archive containing the selected items (and their dependencies) will download automatically.

### Single Entity Export
You can export a single entity (and its dependencies) in two ways:
1.  **Local Task (Tab)**: View any content entity (e.g., a Node) and click the **Export** tab.
2.  **Operations Link**: On administrative lists (like `/admin/content`), click the arrow in the operations dropbutton and select **Export**.

### Import
1.  Navigate to **Configuration > Development > Default content** (`/admin/config/development/default-content`).
2.  Click the **Import** tab.
3.  Upload a `.zip` file containing your YAML content.
    * *Note:* The archive structure should be `entity_type/uuid.yml`. The module automatically handles archives wrapped in a top-level folder.
4.  Click **Import Content**. By default, the archive is first scanned for fields that don't exist on this site (e.g. it was exported from a site with a different field architecture):
    * If nothing is found, the import proceeds immediately — there's no separate report to click through for a clean archive.
    * If an issue is found, the import stops and shows a report naming the field. Since importing as-is is guaranteed to fail on that same field, there's no **Import Content** button in this case — instead, a link takes you to [Default Content UI Mapping](modules/default_content_ui_mapping)'s Field Mapping settings (if installed) to add a rename rule, or to enable that module (if not), before you **Cancel** and re-upload.
    * The import itself is all-or-nothing: if any entity in the archive fails partway through (e.g. it references a taxonomy term, role, or other entity that doesn't exist on this site), everything imported earlier in that same batch is rolled back too, rather than leaving a half-imported archive behind — and any success message a module reported about work it did earlier in that same failed attempt is discarded along with it, so you won't see a stale "N items imported" next to the failure. This covers database-recorded content only — a physical file already copied alongside a file entity is not undone by a rollback.

## Configuration

Navigate to **Configuration > Development > Default content > Settings** to configure:
* Which entity types should display the "Export" tab and operation link.
* Whether dependencies are included by default during single entity exports.
* Whether uploading an archive shows the dry-run report before importing, or imports immediately in one step ("Skip the dry-run report").

## Compatibility Notes

### JSON:API Extras
This module includes an event subscriber that automatically intercepts and fixes a known issue where `target_uuid` properties (added by modules like JSON:API Extras) cause the core exporter to output invalid UUIDs ("0") for the root user (User 1). No manual configuration is required; the fix is applied automatically during export.
