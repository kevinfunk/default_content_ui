<?php

namespace Drupal\default_content_ui\Batch;

use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Batch operations for Default Content UI import using Core APIs.
 */
class ImportBatch {

  /**
   * Extracts the uploaded ZIP archive.
   */
  public static function extract($zip_uri, $extract_path, $fid, &$context) {
    $file_system = \Drupal::service('file_system');

    if (!file_exists($extract_path)) {
      $file_system->mkdir($extract_path);
    }

    // Recorded unconditionally (not only on success) so finished() always
    // cleans up this directory, including the case where it was created but
    // extraction was then rejected or failed.
    $context['results']['extract_path'] = $extract_path;
    $context['results']['cleanup_fid'] = $fid;

    $real_path = $file_system->realpath($zip_uri);
    $temp_copy = NULL;

    if (!$real_path) {
      $temp_copy = sys_get_temp_dir() . '/' . basename($zip_uri);
      copy($zip_uri, $temp_copy);
      $real_path = $temp_copy;
    }

    try {
      $zip = new \ZipArchive();
      if ($zip->open($real_path) === TRUE) {
        // The upload itself is already capped at 50MB (compressed) by the
        // 'FileSizeLimit' validator on the 'archive' element, but a small
        // archive can still decompress to gigabytes, so file count and
        // uncompressed size are capped independently here too.
        $max_files = 5000;
        $max_uncompressed_bytes = 200 * 1024 * 1024;

        if ($zip->numFiles > $max_files) {
          throw new \Exception("Security Error: Zip file contains too many entries.");
        }

        $total_uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
          $filename = $zip->getNameIndex($i);
          if (strpos($filename, '../') !== FALSE || strpos($filename, '..\\') !== FALSE) {
            throw new \Exception("Security Error: Zip file contains directory traversal characters.");
          }

          $stat = $zip->statIndex($i);
          $total_uncompressed += $stat['size'] ?? 0;
          if ($total_uncompressed > $max_uncompressed_bytes) {
            throw new \Exception("Security Error: Zip file's uncompressed contents are too large.");
          }
        }

        $zip->extractTo($file_system->realpath($extract_path));
        $zip->close();
      }
      else {
        throw new \Exception("Failed to open ZIP archive.");
      }
    }
    catch (\Exception $e) {
      $context['results']['error'] = $e->getMessage();
    }
    finally {
      if ($temp_copy && file_exists($temp_copy)) {
        @unlink($temp_copy);
      }
    }
  }

  /**
   * Resolves the real archive root, unwrapping an accidental wrapper folder.
   *
   * A single top-level entry is only unwrapped if it isn't a meaningful
   * name — 'content', 'components', or a real content entity type —
   * otherwise a legitimately single-folder archive would have that folder
   * wrongly unwrapped, hiding any sibling that should sit alongside it.
   */
  protected static function resolveArchiveRoot(string $folder, EntityTypeManagerInterface $entity_type_manager): string {
    $scan = scandir($folder);
    $candidates = array_diff($scan, ['.', '..', '__MACOSX']);
    if (count($candidates) !== 1) {
      return $folder;
    }

    $subdir = reset($candidates);
    $is_meaningful_name = in_array($subdir, ['content', 'components'], TRUE)
      || ($entity_type_manager->hasDefinition($subdir) && $entity_type_manager->getDefinition($subdir)->getGroup() === 'content');

    if (!$is_meaningful_name && is_dir($folder . '/' . $subdir)) {
      return $folder . '/' . $subdir;
    }
    return $folder;
  }

  /**
   * Resolves the content root and prepares it for scanning or import.
   *
   * Shared by import() and scanOnly(), so root resolution and the
   * invalid-folder cleanup can never drift apart between an actual import
   * and a dry-run scan of the same archive. Deliberately does NOT invoke
   * the default_content_ui_pre_import hook — an implementation of that
   * hook (e.g. canvas_component_manager's) can actually import content as
   * a real, permanent side effect, which must never happen during a scan
   * that's only supposed to be previewing the archive.
   *
   * @return array{0: string, 1: string}
   *   The resolved archive folder, and the content root within it.
   */
  protected static function prepareContentRoot(string $folder): array {
    $entity_type_manager = \Drupal::entityTypeManager();
    $file_system = \Drupal::service('file_system');
    $folder = self::resolveArchiveRoot($folder, $entity_type_manager);

    $content_root = $folder;
    if (is_dir($folder . '/content')) {
      $content_root = $folder . '/content';
    }

    // Make sure only valid entity type folders remain in the folder.
    $valid_folders = array_diff(scandir($content_root), ['.', '..', '__MACOSX']);
    foreach ($valid_folders as $item) {
      $item_path = $content_root . '/' . $item;
      if (is_dir($item_path)) {
        $is_valid_type = FALSE;
        if ($entity_type_manager->hasDefinition($item)) {
          $def = $entity_type_manager->getDefinition($item);
          if ($def->getGroup() === 'content') {
            $is_valid_type = TRUE;
          }
        }

        if (!$is_valid_type) {
          try {
            $file_system->deleteRecursive($item_path);
          }
          catch (\Exception $e) {
            \Drupal::logger('default_content_ui')->warning('Failed to remove invalid folder @path from import archive: @message', [
              '@path' => $item_path,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
    }

    return [$folder, $content_root];
  }

  /**
   * Imports content from the extracted folder.
   *
   * Runs inside a database transaction, so a failure partway through (e.g.
   * one entity out of fifty failing validation) rolls back everything this
   * call touched — including any component import triggered by the
   * default_content_ui_pre_import hook — instead of leaving the site with
   * a half-imported archive. On failure, any messenger messages added
   * during the attempt are discarded too, since those aren't undone by the
   * rollback on their own. Neither covers non-database side effects (e.g.
   * physical files copied alongside file entities).
   */
  public static function import($folder, &$context) {
    if (!empty($context['results']['error'])) {
      // The extract operation already recorded a failure; do not attempt
      // to import from a folder that was rejected or never (fully)
      // extracted.
      return;
    }

    if (!is_dir($folder)) {
      $context['results']['error'] = 'Import directory not found or extraction failed.';
      return;
    }

    /** @var \Drupal\Core\DefaultContent\Importer $importer */
    $importer = \Drupal::service(Importer::class);
    [$resolved_folder, $content_root] = self::prepareContentRoot($folder);

    $transaction = \Drupal::database()->startTransaction();
    $messenger = \Drupal::messenger();
    $messages_before_import = $messenger->all();

    try {
      $finder = new Finder($content_root);
      $context['results']['field_warnings'] = self::scanForUnknownFields($finder);

      // Allows modules to find sibling folders like 'components/' — only
      // for a real import; see prepareContentRoot()'s docblock for why
      // this can't also run during scanOrImport()'s preview.
      \Drupal::moduleHandler()->invokeAll('default_content_ui_pre_import', [$resolved_folder]);

      $importer->importContent($finder, Existing::Skip);

      $context['results']['imported'] = TRUE;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $context['results']['error'] = $e->getMessage();

      // Messenger messages live in the session, not the database, so
      // rolling back doesn't retract one a hook implementation (e.g.
      // canvas_component_manager's own component-import reporting) added
      // while this transaction was still open — without this, the admin
      // would see a stale "N items imported" success message right next
      // to the failure that undid it.
      $messenger->deleteAll();
      foreach ($messages_before_import as $type => $messages_of_type) {
        foreach ($messages_of_type as $message) {
          $messenger->addMessage($message, $type);
        }
      }
    }
  }

  /**
   * Scans the extracted folder, importing immediately if nothing is found.
   *
   * There's nothing for an admin to review or fix when the scan comes back
   * clean, so this imports right away in that case exactly like import()
   * would, rather than making them click through an empty report. If the
   * scan finds field issues, it stops here without calling
   * Importer::importContent() or invoking default_content_ui_pre_import —
   * nothing is created or changed on the site — so the caller can show
   * those issues and let the admin decide whether to fix and retry.
   */
  public static function scanOrImport($folder, &$context) {
    if (!empty($context['results']['error'])) {
      return;
    }

    if (!is_dir($folder)) {
      $context['results']['error'] = 'Import directory not found or extraction failed.';
      return;
    }

    [, $content_root] = self::prepareContentRoot($folder);

    try {
      $context['results']['field_warnings'] = self::scanForUnknownFields(new Finder($content_root));
    }
    catch (\Exception $e) {
      $context['results']['error'] = $e->getMessage();
      return;
    }

    if (empty($context['results']['field_warnings'])) {
      self::import($folder, $context);
    }
  }

  /**
   * Re-seeds batch results for an already-extracted folder.
   *
   * When the dry-run report is confirmed, the real import runs as its own,
   * separate batch — one with a fresh $context that never ran extract() —
   * so this restores the extract_path/cleanup_fid results finished() needs
   * to clean up the temporary folder and uploaded file afterward.
   */
  public static function resumeExtracted($extract_path, $cleanup_fid, &$context) {
    $context['results']['extract_path'] = $extract_path;
    $context['results']['cleanup_fid'] = $cleanup_fid;
  }

  /**
   * Scans decoded import data for field names unknown to the destination.
   *
   * A source field name with no matching destination field is what causes
   * \Drupal\Core\Entity\ContentEntityBase::get() to throw "Field X is
   * unknown." during the real import, aborting it entirely — this runs the
   * same check ahead of time, across every entity in the archive at once,
   * so all of them can be reported together instead of one import attempt
   * per fixed field.
   *
   * Each entity's data is passed through the real PreEntityImportEvent
   * dispatch first, exactly as the real import does, so a field already
   * renamed by a configured Field Mapping rule (see
   * default_content_ui_mapping) is checked under its renamed name, not
   * flagged as unknown under its original one.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   A warning message for each distinct entity type/bundle/field name
   *   combination that has no matching destination field.
   */
  protected static function scanForUnknownFields(Finder $finder): array {
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $event_dispatcher = \Drupal::service('event_dispatcher');
    $checked = [];
    $warnings = [];

    foreach ($finder->data as $decoded) {
      $event = new PreEntityImportEvent($decoded);
      $event_dispatcher->dispatch($event);

      $entity_type = $event->metadata['entity_type'] ?? NULL;
      $bundle = $event->metadata['bundle'] ?? $entity_type;
      if (!$entity_type) {
        continue;
      }

      $field_names = array_keys($event->data['default'] ?? []);
      foreach ($event->data['translations'] ?? [] as $translation_data) {
        $field_names = array_merge($field_names, array_keys($translation_data));
      }

      $definitions = $entity_field_manager->getFieldDefinitions($entity_type, $bundle);

      foreach (array_unique($field_names) as $field_name) {
        $key = "$entity_type:$bundle:$field_name";
        if (isset($checked[$key]) || isset($definitions[$field_name])) {
          continue;
        }
        $checked[$key] = TRUE;

        $warnings[] = new TranslatableMarkup("Field '@field' on @type (@bundle) does not exist on this site.", [
          '@field' => $field_name,
          '@type' => $entity_type,
          '@bundle' => $bundle,
        ]);
      }
    }

    return $warnings;
  }

  /**
   * Deletes the extracted temporary folder and the uploaded archive.
   *
   * Shared by finished() (a completed or failed real import), by
   * finishedDryRun() (a failed scan, which has nothing left to confirm),
   * and by ImportConfirmForm::submitCancel() (a scan the admin chose not
   * to proceed with) — the three situations where nothing further will
   * ever read the extracted folder again.
   */
  public static function cleanupExtracted(array $results): void {
    if (!empty($results['extract_path'])) {
      try {
        \Drupal::service('file_system')->deleteRecursive($results['extract_path']);
      }
      catch (\Exception $e) {
        \Drupal::logger('default_content_ui')->warning('Failed to delete temporary import folder: @message', ['@message' => $e->getMessage()]);
      }
    }
    if (!empty($results['cleanup_fid'])) {
      $file = File::load($results['cleanup_fid']);
      if ($file) {
        $file->delete();
      }
    }
  }

  /**
   * Callback for batch completion.
   */
  public static function finished($success, $results, $operations) {
    foreach (array_unique($results['field_warnings'] ?? []) as $warning) {
      \Drupal::messenger()->addWarning($warning);
    }

    // $success reflects Drupal's own batch processing (e.g. an uncaught
    // fatal error) and is unrelated to failures this class detects and
    // handles itself (invalid archives, rejected uploads); those are only
    // visible via $results['error'].
    if ($success && empty($results['error']) && !empty($results['imported'])) {
      \Drupal::messenger()->addStatus(new TranslatableMarkup('Content import completed successfully.'));
    }
    else {
      $error = $results['error'] ?? 'Unknown error';
      \Drupal::logger('default_content_ui')->error('Content import failed: @error', ['@error' => $error]);
      \Drupal::messenger()->addError(new TranslatableMarkup('Import failed: @error', ['@error' => $error]));
    }

    self::cleanupExtracted($results);
  }

  /**
   * Callback for the dry-run scan batch's completion.
   *
   * There's only something to confirm when the scan actually found field
   * issues to review: that's the one outcome where the extracted folder is
   * deliberately left in place and its path stashed in the private
   * tempstore for ImportConfirmForm. A clean scan already imported
   * immediately (see scanOrImport()), and a failure has nothing left to
   * confirm either — both of those redirect straight back to the plain
   * import form instead of a confirm page with nothing to show.
   */
  public static function finishedDryRun($success, $results, $operations) {
    if (!empty($results['imported'])) {
      self::finished($success, $results, $operations);
      return new RedirectResponse(Url::fromRoute('default_content_ui.import')->toString());
    }

    if (!$success || !empty($results['error'])) {
      $error = $results['error'] ?? 'Unknown error';
      \Drupal::logger('default_content_ui')->error('Content import failed: @error', ['@error' => $error]);
      \Drupal::messenger()->addError(new TranslatableMarkup('Import failed: @error', ['@error' => $error]));
      self::cleanupExtracted($results);
      return new RedirectResponse(Url::fromRoute('default_content_ui.import')->toString());
    }

    \Drupal::service('tempstore.private')->get('default_content_ui')->set('pending_import', $results);
  }

}
