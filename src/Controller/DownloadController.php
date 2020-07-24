<?php

namespace Drupal\default_content_ui\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\system\FileDownloadController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for config module routes.
 */
class DownloadController implements ContainerInjectionInterface {

  /**
   * The file download controller.
   *
   * @var \Drupal\system\FileDownloadController
   */
  protected $fileDownloadController;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      new FileDownloadController()
    );
  }

  /**
   * Constructs a ConfigController object.
   *
   * @param \Drupal\system\FileDownloadController $file_download_controller
   *   The file download controller.
   */
  public function __construct(FileDownloadController $file_download_controller) {
    $this->fileDownloadController = $file_download_controller;
  }

  /**
   * Downloads a tarball of export.
   */
  public function downloadExport() {
    $request = new Request(['file' => 'default_content.tar.gz']);
    return $this->fileDownloadController->download($request, 'temporary');
  }

}
