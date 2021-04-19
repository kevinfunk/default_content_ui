<?php

namespace Drupal\default_content_ui\Controller;

use Drupal\system\FileDownloadController;
use Symfony\Component\HttpFoundation\Request;

/**
 * DownloadController.
 */
class DownloadController extends FileDownloadController {

  /**
   * Downloads a tarball of export.
   */
  public function downloadExport() {
    $request = new Request(['file' => 'default_content.tar.gz']);
    return $this->download($request, 'temporary');
  }

}
