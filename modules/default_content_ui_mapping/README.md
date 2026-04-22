# Default Content UI Mapping

An extension for the **Default Content UI** module that allows site administrators to map, filter, and clean up exported YAML content on the fly during the import process. 

When migrating content between different Drupal sites using the core Default Content API, field architectures often don't match perfectly. This module intercepts the temporary ZIP archive during import and safely rewrites the YAML files before Drupal attempts to process them, preventing schema errors and data loss.

## Features

* **Field Mapping:** Map incoming source field machine names to the actual target field machine names on your destination site (e.g., rename `media_image` to `field_media_image`).
* **Excluded Fields (Drop Entire Field):** Completely drop deprecated, legacy, or unwanted fields during import to prevent validation errors. Exclusions can be applied globally, or targeted to specific Entity Types and Bundles (e.g., only remove `field_old_data` from `article` nodes).
* **Value Exclusions (Drop Specific Values):** Remove specific array items (like a deleted taxonomy term, a missing user role, or an obsolete select list option) from a field without destroying the rest of the field's data.
* **Translation Stripping:** If your destination site is not multilingual, you can automatically strip extra translation arrays from the incoming YAML files, retaining only the primary/fallback language. This process also cleanly removes orphaned `content_translation_source` and `content_translation_outdated` metadata.
* **Audit Logging:** Translation stripping and YAML parsing errors are logged to the `default_content_ui_mapping` channel for easy troubleshooting.

## Requirements

* Drupal 11.3 or higher.
* The [Default Content UI](https://www.drupal.org/project/default_content_ui) module.

## Installation

1. Place the `default_content_ui_mapping` folder inside your `modules/custom` (or `modules/contrib`) directory alongside `default_content_ui`.
2. Enable the module via the Drupal UI (`/admin/modules`) or using Drush:
   ```bash
   drush en default_content_ui_mapping
   ```

## Configuration & Usage

Navigate to **Configuration > Development > Default content > Field Mapping** (`/admin/config/development/default-content/mapping`).

### 1. Translation Handling
If you are importing content from a multilingual site into a single-language site:
1. Check **Strip extra translations from incoming data**.
2. Specify your **Primary Language Key** (e.g., `default` or `en`).
3. During import, any language arrays not matching this key will be safely removed.

### 2. Excluded Fields (Drop Entire Field)
To prevent entire fields from importing:
1. Click **Add exclusion rule**.
2. Select the **Entity Type** (e.g., Node) and the specific **Bundle** (e.g., Article). Leave the Bundle as `- Any -` to apply the rule to all bundles of that entity type.
3. Enter the exact machine name of the incoming field you wish to drop (e.g., `field_deprecated_tags`).

### 3. Value Exclusions (Drop Specific Values)
To remove specific items from a list or reference field (e.g., removing a `member` role while keeping the `administrator` role):
1. Click **Add value exclusion**.
2. Select the **Entity Type** and **Bundle**. *(Note: For "bundleless" entities like Users or Files, leave the Bundle as `- Any -`)*.
3. Enter the **Field Name** (e.g., `roles`).
4. Enter the **Property Name** used in the YAML array (e.g., `target_id` for entity reference fields, `value` for text/boolean fields).
5. Enter the **Value to Exclude** (e.g., `member`).

### 4. Field Mapping
To rename a field during import:
1. Click **Add another mapping**.
2. Enter the **Incoming Field Name (Source)** exactly as it appears in the exported YAML.
3. Enter the **Site Field Name (Target)**.

## Running the Import
Once your rules are saved, simply navigate to the **Import** tab (`/admin/config/development/default-content/import`) and upload your ZIP archive.

The module will automatically extract the archive, apply your translation, exclusion, and mapping rules to the raw YAML files, and then pass the cleaned data to Drupal core's Importer service.

## Troubleshooting
If an import behaves unexpectedly, check **Reports > Recent log messages** (`/admin/reports/dblog`). The module logs notices when translations are stripped (including the affected Entity UUIDs) and logs errors if a corrupted YAML file is encountered in the uploaded archive.
