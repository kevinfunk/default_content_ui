<?php

namespace Drupal\default_content_ui\Batch;

use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
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
        for ($i = 0; $i < $zip->numFiles; $i++) {
          $filename = $zip->getNameIndex($i);
          if (strpos($filename, '../') !== FALSE || strpos($filename, '..\\') !== FALSE) {
            throw new \Exception("Security Error: Zip file contains directory traversal characters.");
          }
        }

        $zip->extractTo($file_system->realpath($extract_path));
        $zip->close();

        $context['results']['extract_path'] = $extract_path;
        $context['results']['cleanup_fid'] = $fid;
      }
      else {
        throw new \Exception("Failed to open ZIP archive.");
      }
    }
    catch (\Exception $e) {
      $context['success'] = FALSE;
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
    /** @var \Drupal\Core\DefaultContent\Importer $importer */
    $importer = \Drupal::service(Importer::class);

    $scan = scandir($folder);
    $candidates = array_diff($scan, ['.', '..', '__MACOSX']);
    if (count($candidates) === 1) {
      $subdir = reset($candidates);
      if (is_dir($folder . '/' . $subdir)) {
        $folder .= '/' . $subdir;
      }
    }

    try {
      $finder = new Finder($folder);
      $importer->importContent($finder, Existing::Skip);

      $context['results']['imported'] = TRUE;
    }
    catch (\Exception $e) {
      $context['success'] = FALSE;
      $context['results']['error'] = $e->getMessage();
    }
  }

  /**
   * Callback for batch completion.
   */
  public static function finished($success, $results, $operations) {
    $file_system = \Drupal::service('file_system');
    if ($success && !empty($results['imported'])) {
      \Drupal::messenger()->addStatus(t('Content import completed successfully.'));
    }
    else {
      \Drupal::messenger()->addError(t('Import failed: @error', ['@error' => $results['error'] ?? 'Unknown error']));
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
