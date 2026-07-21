<?php

namespace Drupal\default_content_ui\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\default_content_ui\Batch\ExportBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exports the selected entities to a Default Content ZIP archive.
 */
#[Action(
  id: 'default_content_ui_export_action',
  label: new TranslatableMarkup('Export Default Content'),
  action_label: new TranslatableMarkup('Export Default Content'),
  category: new TranslatableMarkup('Default Content'),
  deriver: 'Drupal\default_content_ui\Plugin\Derivative\ExportActionDeriver'
)]
class ExportContentAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new ExportContentAction object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    if (!$account) {
      $account = \Drupal::currentUser();
    }

    if ($object === NULL) {
      $result = AccessResult::allowedIfHasPermission($account, 'default content export');
      return $return_as_object ? $result : $result->isAllowed();
    }

    /** @var \Drupal\Core\Entity\EntityInterface $object */
    $result = $object->access('view', $account, TRUE)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'default content export'));

    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {
    // Required by ActionInterface.
    // Unused here because executeMultiple() is overridden to handle the batch.
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    if (empty($entities)) {
      return;
    }

    $config = $this->configFactory->get('default_content_ui.settings');
    $include_dependencies = $config->get('local_export_reference_mode') ?? TRUE;
    $mode = $include_dependencies ? 'references' : 'entity';

    $batch = [
      'title' => $this->t('Exporting Selected Content'),
      'operations' => [
        [[ExportBatch::class, 'start'], []],
      ],
      'finished' => [ExportBatch::class, 'finished'],
    ];

    // Create Root.
    $root_folder = 'temporary://default_content_export_' . uniqid('', TRUE);
    $this->fileSystem->prepareDirectory($root_folder, FileSystemInterface::CREATE_DIRECTORY);

    // Create Content Subdirectory.
    $content_folder = $root_folder . '/content';
    $this->fileSystem->prepareDirectory($content_folder, FileSystemInterface::CREATE_DIRECTORY);

    foreach ($entities as $entity) {
      $batch['operations'][] = [
        [ExportBatch::class, 'exportSingle'],
        [$entity->getEntityTypeId(), $entity->id(), $content_folder, $mode],
      ];
    }

    $batch['operations'][] = [
      [ExportBatch::class, 'compress'], [$root_folder],
    ];

    batch_set($batch);
  }

}
