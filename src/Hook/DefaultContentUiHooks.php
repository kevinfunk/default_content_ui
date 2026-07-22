<?php

namespace Drupal\default_content_ui\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\default_content_ui\Traits\LocalExportEligibilityTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for Default Content UI.
 */
class DefaultContentUiHooks {

  use StringTranslationTrait;
  use MessengerTrait;
  use LocalExportEligibilityTrait;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new DefaultContentUiHooks object.
   */
  public function __construct(
    StreamWrapperManagerInterface $stream_wrapper_manager,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack,
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Implements hook_file_download().
   */
  #[Hook('file_download')]
  public function fileDownload($uri) {
    $scheme = $this->streamWrapperManager->getScheme($uri);
    $target = $this->streamWrapperManager->getTarget($uri);

    // Only allow temporary files with alphanumeric characters and .zip.
    $valid_file = preg_match('/^default_content[a-zA-Z0-9_\-\.]+\.zip$/', $target);
    if ($scheme == 'temporary' && $valid_file) {
      if (!$this->currentUser->hasPermission('default content export')) {
        return -1;
      }

      // The permission check above is necessary but not sufficient: this
      // hook also gates Drupal's public, unauthenticated-reachable
      // '/system/temporary' route directly, bypassing
      // ExportDownloadController's own session check entirely. Export
      // filenames are time()-based, not random, so without also requiring
      // a matching pending download in *this* session, any other user who
      // merely holds the same permission could fetch someone else's
      // already access-scoped export archive by guessing its filename.
      $request = $this->requestStack->getCurrentRequest();
      $session = $request && $request->hasSession() ? $request->getSession() : NULL;
      $expected_filename = $session ? $session->get('default_content_ui_download') : NULL;

      if (!$expected_filename || basename($expected_filename) !== basename($target)) {
        return -1;
      }

      $filename = basename($target);
      return [
        'Content-disposition' => 'attachment; filename="' . $filename . '"',
      ];
    }
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments) {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return;
    }
    $session = $request->getSession();

    $attachments['#cache']['contexts'][] = 'url.query_args';

    if ($session->has('default_content_ui_download')) {
      $filename = $session->get('default_content_ui_download');

      if (empty($filename) || !file_exists('temporary://' . basename($filename))) {
        $session->remove('default_content_ui_download');
        $session->remove('default_content_ui_download_message');
        return;
      }

      // The message (with the right item count/label already filled in)
      // is built once, by ExportBatch::finished(), and just displayed here.
      $message = $session->get('default_content_ui_download_message') ?? $this->t('The export archive is downloading automatically.');
      $this->messenger()->addStatus($message);

      // Cleanup session variables.
      $session->remove('default_content_ui_download_message');

      // Add the auto-download meta tag.
      $attachments['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'http-equiv' => 'refresh',
            'content' => '0;url=' . Url::fromRoute('default_content_ui.export_download')->toString(),
          ],
        ],
        'default_content_auto_download',
      ];

      // Disable caching for this specific response for the meta tag.
      $attachments['#cache']['max-age'] = 0;
    }
  }

  /**
   * Implements hook_entity_operation().
   */
  #[Hook('entity_operation')]
  public function entityOperation($entity) {
    $operations = [];
    $entity_type = $entity->getEntityType();
    $enabled_types = $this->configFactory->get('default_content_ui.settings')->get('local_export_types');
    $is_enabled = is_null($enabled_types) || in_array($entity->getEntityTypeId(), $enabled_types);

    if (!$is_enabled) {
      return $operations;
    }

    if ($this->isEligibleForLocalExport($entity_type)) {
      if ($this->currentUser->hasPermission('default content export') && $entity->access('view')) {
        $operations['default_content_export'] = [
          'title' => $this->t('Export'),
          'weight' => 100,
          'url' => Url::fromRoute(
            "entity.{$entity->getEntityTypeId()}.default_content_export",
            [$entity->getEntityTypeId() => $entity->id()]
          ),
        ];
      }
    }

    return $operations;
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.default_content_ui':
        $output = '';
        $output .= '<h3>' . $this->t('About') . '</h3>';
        $output .= '<p>' . $this->t("The Default Content UI module provides an administrative interface around Drupal core's Default Content system, for moving content between environments as portable ZIP archives.") . '</p>';

        $output .= '<h3>' . $this->t('Features') . '</h3>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('<strong>Single-Entity Export</strong>: An "Export" tab and operation link on individual content items, configurable per entity type in <a href=":url">Settings</a>.', [':url' => Url::fromRoute('default_content_ui.settings')->toString()]) . '</li>';
        $output .= '<li>' . $this->t('<strong>Bulk Export</strong>: Export all content of one or more entity types at once.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Views Bulk Action</strong>: An "Export Default Content" action for use in Views with a bulk operations field.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Import</strong>: Upload a previously exported ZIP archive to recreate its content. Entities whose UUID already exists on the site are skipped, never overwritten.') . '</li>';
        $output .= '</ul>';

        $output .= '<h3>' . $this->t('Uses') . '</h3>';
        $output .= '<dl>';
        $output .= '<dt>' . $this->t('Exporting Content') . '</dt>';
        $output .= '<dd>' . $this->t('Use the <a href=":url">Export Content</a> page for a bulk export, or the "Export" tab/link on an individual entity for a single-entity export.', [':url' => Url::fromRoute('default_content_ui.export_bulk')->toString()]) . '</dd>';
        $output .= '<dt>' . $this->t('Importing Content') . '</dt>';
        $output .= '<dd>' . $this->t('Use the <a href=":url">Import Content</a> page to upload a ZIP archive.', [':url' => Url::fromRoute('default_content_ui.import')->toString()]) . '</dd>';
        $output .= '</dl>';

        return ['#markup' => $output];
    }
  }

}
