<?php

namespace Drupal\default_content_ui\Traits;

use Drupal\Core\File\FileSystemInterface;
use Drupal\default_content_ui\Batch\ExportBatch;

/**
 * Builds the temporary export folders and starting batch definition.
 *
 * Shared by every export entry point (single-entity, bulk form, and the
 * Views action) — each of which otherwise only differs in which per-entity
 * ExportBatch operations it appends afterward.
 *
 * Requires the consuming class to provide $this->fileSystem.
 */
trait ExportBatchSkeletonTrait {

  /**
   * Creates the temporary export folders and the starting batch definition.
   *
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The batch's progress title.
   *
   * @return array
   *   An array with 'batch' (the batch definition, still missing its
   *   per-entity export and compress operations), 'root_folder', and
   *   'content_folder'.
   */
  protected function createExportBatchSkeleton($title): array {
    $root_folder = 'temporary://default_content_export_' . uniqid('', TRUE);
    $this->fileSystem->prepareDirectory($root_folder, FileSystemInterface::CREATE_DIRECTORY);

    $content_folder = $root_folder . '/content';
    $this->fileSystem->prepareDirectory($content_folder, FileSystemInterface::CREATE_DIRECTORY);

    return [
      'batch' => [
        'title' => $title,
        'operations' => [
          [[ExportBatch::class, 'start'], []],
        ],
        'finished' => [ExportBatch::class, 'finished'],
      ],
      'root_folder' => $root_folder,
      'content_folder' => $content_folder,
    ];
  }

}
