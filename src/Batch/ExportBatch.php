<?php

namespace Drupal\default_content_ui\Batch;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\File\FileSystemInterface;

/**
 * Batch operations for Default Content UI export using Core APIs.
 */
class ExportBatch {

  /**
   * Initializes the batch results.
   */
  public static function start(&$context) {
    $context['results']['download_archive'] = NULL;
    $context['results']['primary_label'] = NULL;
  }

  /**
   * Batch callback to export a chunk of entities.
   */
  public static function export($entity_type, $folder, $mode, &$context) {
    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type);

    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = $storage->getQuery()->accessCheck(TRUE)->count()->execute();
    }

    $limit = 10;
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition($storage->getEntityType()->getKey('id'), $context['sandbox']['current_id'], '>')
      ->sort($storage->getEntityType()->getKey('id'))
      ->range(0, $limit)
      ->execute();

    if (empty($ids)) {
      $context['finished'] = 1;
      return;
    }

    $entities = $storage->loadMultiple($ids);
    $include_dependencies = ($mode === 'references');

    foreach ($entities as $entity) {
      if ($include_dependencies) {
        $exporter->exportWithDependencies($entity, $folder);
      }
      else {
        $exporter->exportToFile($entity, $folder);
      }

      $context['sandbox']['progress']++;
      $context['sandbox']['current_id'] = $entity->id();
    }

    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Callback to export a single entity.
   */
  public static function exportSingle($entity, $folder, $mode, &$context) {
    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);

    $context['results']['primary_label'] = $entity->label();

    if ($mode === 'references') {
      $exporter->exportWithDependencies($entity, $folder);
    }
    else {
      $exporter->exportToFile($entity, $folder);
    }
  }

  /**
   * Compresses the export folder into a ZIP archive.
   */
  public static function compress($folder, &$context) {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');

    $sys_temp = sys_get_temp_dir();
    $archive_name = basename($folder) . '.zip';
    $zip_path = $sys_temp . '/' . $archive_name;

    if (file_exists($zip_path)) {
      @unlink($zip_path);
    }

    $zip = new \ZipArchive();
    if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
      $real_folder = $file_system->realpath($folder);
      if ($real_folder) {
        $files = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($real_folder, \RecursiveDirectoryIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
          if (!$file->isFile()) {
            continue;
          }
          $local_path = substr($file->getPathname(), strlen($real_folder) + 1);
          $zip->addFile($file->getPathname(), $local_path);
        }
      }
      $zip->close();

      $destination = 'temporary://' . $archive_name;
      $file_system->move($zip_path, $destination, FileSystemInterface::EXISTS_REPLACE);

      $context['results']['download_archive'] = $archive_name;
    }

    try {
      $file_system->deleteRecursive($folder);
    }
    catch (\Exception $e) {
      // Log the error instead of silently ignoring it.
      \Drupal::logger('default_content_ui')->warning('Failed to delete temporary export folder: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Callback for batch completion.
   */
  public static function finished($success, $results, $operations) {
    if ($success && !empty($results['download_archive'])) {
      $session = \Drupal::request()->getSession();
      $session->set('default_content_ui_download', $results['download_archive']);
      if (!empty($results['primary_label'])) {
        $session->set('default_content_ui_download_label', $results['primary_label']);
      }
      $session->save();
      \Drupal::messenger()->addStatus(t('Export complete.'));
    }
    else {
      \Drupal::messenger()->addError(t('Export failed.'));
    }
  }

}
