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
4.  Click **Import Content**.

## Configuration

Navigate to **Configuration > Development > Default content > Settings** to configure:
* Which entity types should display the "Export" tab and operation link.
* Whether dependencies are included by default during single entity exports.

## Compatibility Notes

### JSON:API Extras
This module includes an event subscriber that automatically intercepts and fixes a known issue where `target_uuid` properties (added by modules like JSON:API Extras) cause the core exporter to output invalid UUIDs ("0") for the root user (User 1). No manual configuration is required; the fix is applied automatically during export.
