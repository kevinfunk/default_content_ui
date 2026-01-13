<?php

namespace Drupal\default_content_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\default_content_ui\Batch\ExportBatch;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for single entity export.
 */
class ExportEntityController extends ControllerBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs an ExportEntityController.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system')
    );
  }

  /**
   * Exports a single entity.
   */
  public function export(RouteMatchInterface $route_match) {
    $entity_type_id = $route_match->getRouteObject()->getDefault('entity_type_id');
    $entity = $route_match->getParameter($entity_type_id);

    if (!$entity) {
      throw new NotFoundHttpException();
    }

    $include_dependencies = $this->config('default_content_ui.settings')->get('local_export_reference_mode') ?? TRUE;
    $mode = $include_dependencies ? 'references' : 'entity';

    $batch = [
      'title' => $this->t('Exporting @label', ['@label' => $entity->label()]),
      'operations' => [
        [[ExportBatch::class, 'start'], []],
      ],
      'finished' => [ExportBatch::class, 'finished'],
    ];

    $folder = 'temporary://default_content_export_' . time();
    $this->fileSystem->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);

    $batch['operations'][] = [
      [ExportBatch::class, 'exportSingle'],
      [$entity, $folder, $mode],
    ];

    $batch['operations'][] = [
      [ExportBatch::class, 'compress'],
      [$folder],
    ];

    batch_set($batch);

    $url = $entity->toUrl();
    $url->setOption('query', ['export_finished' => time()]);

    return batch_process($url);
  }

}
