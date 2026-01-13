# Default Content UI

Provides a User Interface for the Drupal Core Default Content API (available in Drupal 11.3+).

This module replaces the need for Drush commands when exporting content for recipes or module installation.

## Features

* **Bulk Export UI**: Select specific entity types to export.
    * Download as a `.zip` archive (ideal for moving content between sites).
    * Includes dependencies automatically using the Core API.
* **Single Entity Export**: Export individual entities directly.
    * Adds an **Export** tab to content entities.
    * Adds an **Export** action to entity operation lists (e.g., `/admin/content`).
* **Import UI**: Upload a `.zip` archive to import content.
    * Uses the core `Importer` service to handle dependencies and entity creation.

## Requirements

* Drupal 11.3 or higher.
* `default content export` permission is required to export.
* `default content import` permission is required to import.

## Usage

### Bulk Export
1.  Navigate to **Configuration > Development > Default content** (`/admin/config/development/default-content`).
2.  Select the entity types you wish to export.
3.  Click **Export Content**. The archive will be generated and downloaded automatically.

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
