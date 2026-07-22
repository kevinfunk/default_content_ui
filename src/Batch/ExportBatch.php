<?php

namespace Drupal\default_content_ui\Batch;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Batch operations for Default Content UI export using Core APIs.
 */
class ExportBatch {

  /**
   * Initializes the batch results.
   */
  public static function start(&$context) {
    $context['results']['download_archive'] = NULL;
    $context['results']['single_label'] = NULL;
    $context['results']['count'] = 0;
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

      // Allow other modules to add related files to the folder.
      \Drupal::moduleHandler()->invokeAll('default_content_ui_export_entity', [$entity, $folder]);

      $context['sandbox']['progress']++;
      $context['sandbox']['current_id'] = $entity->id();

      if (!isset($context['results']['count'])) {
        $context['results']['count'] = 0;
      }
      $context['results']['count']++;
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
  public static function exportSingle($entity_type_id, $id, $folder, $mode, &$context) {
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($id);

    if (!$entity) {
      return;
    }

    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);

    if (!isset($context['results']['count'])) {
      $context['results']['count'] = 0;
    }
    $context['results']['count']++;

    if (empty($context['results']['single_label'])) {
      $context['results']['single_label'] = $entity->label();
    }

    if ($mode === 'references') {
      $exporter->exportWithDependencies($entity, $folder);
    }
    else {
      $exporter->exportToFile($entity, $folder);
    }

    // Allow other modules to add related files to the folder.
    \Drupal::moduleHandler()->invokeAll('default_content_ui_export_entity', [$entity, $folder]);
  }

  /**
   * Compresses the export folder into a ZIP archive.
   */
  public static function compress($folder, &$context) {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');

    $sys_temp = sys_get_temp_dir();
    $archive_name = basename($folder) . '.zip';
    $zip_path = $file_system->tempnam($sys_temp, 'dcu_export_');

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
    else {
      $context['results']['error'] = 'Could not open Zip archive for writing.';
    }

    try {
      $file_system->deleteRecursive($folder);
    }
    catch (\Exception $e) {
      \Drupal::logger('default_content_ui')->warning('Failed to delete temporary export folder: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Callback for batch completion.
   */
  public static function finished($success, $results, $operations) {
    // $success reflects Drupal's own batch processing (e.g. an uncaught
    // fatal error) and is unrelated to failures this class detects and
    // handles itself (e.g. compress() failing to open the archive); those
    // are only visible via $results['error'].
    if ($success && empty($results['error']) && !empty($results['download_archive'])) {
      $session = \Drupal::request()->getSession();
      $count = $results['count'] ?? 0;

      $message = new TranslatableMarkup('The export archive is downloading automatically.');
      if ($count > 1) {
        $message = new TranslatableMarkup('The export archive for @count items is downloading automatically.', ['@count' => $count]);
      }
      elseif ($count === 1 && !empty($results['single_label'])) {
        $message = new TranslatableMarkup('The export archive for %label is downloading automatically.', ['%label' => $results['single_label']]);
      }

      $session->set('default_content_ui_download', $results['download_archive']);
      $session->set('default_content_ui_download_message', $message);

      $session->save();
    }
    else {
      \Drupal::messenger()->addError(new TranslatableMarkup('Export failed: @error', ['@error' => $results['error'] ?? 'Unknown error']));
    }
  }

}
