<?php

namespace Drupal\default_content_ui\Batch;

use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Entity\File;

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
   * Imports content from the extracted folder.
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

    $entity_type_manager = \Drupal::entityTypeManager();
    $file_system = \Drupal::service('file_system');
    $scan = scandir($folder);
    $candidates = array_diff($scan, ['.', '..', '__MACOSX']);
    if (count($candidates) === 1) {
      $subdir = reset($candidates);
      if (is_dir($folder . '/' . $subdir)) {
        $folder .= '/' . $subdir;
      }
    }

    $content_root = $folder;
    if (is_dir($folder . '/content')) {
      $content_root = $folder . '/content';
    }

    // Allows modules to find sibling folders like 'components/'.
    \Drupal::moduleHandler()->invokeAll('default_content_ui_pre_import', [$folder]);

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
            // Log and ignore.
          }
        }
      }
    }

    try {
      $finder = new Finder($content_root);
      $importer->importContent($finder, Existing::Skip);

      $context['results']['imported'] = TRUE;
    }
    catch (\Exception $e) {
      $context['results']['error'] = $e->getMessage();
    }
  }

  /**
   * Callback for batch completion.
   */
  public static function finished($success, $results, $operations) {
    $file_system = \Drupal::service('file_system');

    // $success reflects Drupal's own batch processing (e.g. an uncaught
    // fatal error) and is unrelated to failures this class detects and
    // handles itself (invalid archives, rejected uploads); those are only
    // visible via $results['error'].
    if ($success && empty($results['error']) && !empty($results['imported'])) {
      \Drupal::messenger()->addStatus(new TranslatableMarkup('Content import completed successfully.'));
    }
    else {
      \Drupal::messenger()->addError(new TranslatableMarkup('Import failed: @error', ['@error' => $results['error'] ?? 'Unknown error']));
    }

    if (!empty($results['extract_path'])) {
      try {
        $file_system->deleteRecursive($results['extract_path']);
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

}
