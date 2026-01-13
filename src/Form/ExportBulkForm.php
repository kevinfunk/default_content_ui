<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\default_content_ui\Batch\ExportBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements the Bulk Export form.
 */
class ExportBulkForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * ExportBulkForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
  ) {
    parent::__construct($config_factory, $typed_config);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['default_content_ui.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_content_ui_export_bulk_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Select the entity types to include in this <strong>Bulk Export</strong>.') . '</p>',
    ];

    $config = $this->config('default_content_ui.settings');

    $options = [];
    $types = $this->entityTypeManager->getDefinitions();

    foreach ($types as $type_id => $type_object) {
      if ($type_object->getGroup() == 'content') {
        try {
          $count = $this->entityTypeManager->getStorage($type_id)
            ->getQuery()
            ->accessCheck(FALSE)
            ->count()
            ->execute();
        }
        catch (\Exception $e) {
          $count = '-';
        }

        $options[$type_id] = [
          'label' => $type_object->getLabel(),
          'id' => $type_id,
          'count' => $count,
        ];
      }
    }

    uasort($options, fn($a, $b) => strcmp($a['label'], $b['label']));

    $saved_values = $config->get('bulk_export_types');
    if ($saved_values === NULL) {
      $default_values = array_fill_keys(array_keys($options), TRUE);
    }
    else {
      $default_values = array_fill_keys($saved_values, TRUE);
    }

    $form['bulk_export_types'] = [
      '#type' => 'tableselect',
      '#header' => [
        'label' => $this->t('Entity Type'),
        'id' => $this->t('Machine Name'),
        'count' => $this->t('Items'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No content entity types found.'),
      '#default_value' => $default_values,
    ];

    $form['options'] = [
      '#title' => $this->t('Options'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['options']['references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include dependencies'),
      '#description' => $this->t('Should referenced entities be exported automatically?'),
      '#default_value' => $config->get('bulk_export_reference_mode') ?? TRUE,
    ];

    $form['actions']['#type'] = 'actions';

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export Content'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('bulk_export_types'));
    $enabled_types = array_keys($selected);
    $reference_mode = (bool) $form_state->getValue('references');

    $this->config('default_content_ui.settings')
      ->set('bulk_export_types', $enabled_types)
      ->set('bulk_export_reference_mode', $reference_mode)
      ->save();

    if (empty($enabled_types)) {
      $this->messenger()->addWarning($this->t('No entity types were selected for export.'));
      return;
    }

    $mode = $reference_mode ? 'references' : 'entity';

    $batch = [
      'title' => $this->t('Exporting Content'),
      'operations' => [
        [[ExportBatch::class, 'start'], []],
      ],
      'finished' => [ExportBatch::class, 'finished'],
    ];

    $folder = 'temporary://default_content_export_' . time();
    $this->fileSystem->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);

    foreach ($enabled_types as $entity_type) {
      $batch['operations'][] = [
        [ExportBatch::class, 'export'], [$entity_type, $folder, $mode],
      ];
    }

    $batch['operations'][] = [
      [ExportBatch::class, 'compress'], [$folder],
    ];

    batch_set($batch);
  }

}
