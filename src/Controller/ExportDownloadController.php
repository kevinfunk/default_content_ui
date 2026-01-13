<?php

namespace Drupal\default_content_ui\Controller;

use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\system\FileDownloadController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles the secure download of the exported archive.
 */
class ExportDownloadController extends FileDownloadController {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a new ExportDownloadController.
   */
  public function __construct(StreamWrapperManagerInterface $stream_wrapper_manager, RequestStack $request_stack) {
    parent::__construct($stream_wrapper_manager);
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('stream_wrapper_manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Downloads a zip of export based on session variable.
   */
  public function downloadExport() {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $filename = $session->get('default_content_ui_download');

    $session->remove('default_content_ui_download');
    $session->remove('default_content_ui_download_label');

    if ($filename) {
      $filename = basename($filename);

      if (file_exists('temporary://' . $filename)) {
        $request = new Request(['file' => $filename]);
        return $this->download($request, 'temporary');
      }
    }

    throw new NotFoundHttpException();
  }

}
