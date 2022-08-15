<?php

/**
 * @file
 * Contains \Drupal\default_content_ui\Form\ExportForm.
 */
namespace Drupal\default_content_ui\Form;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandler;

/**
 * Implements the Default content export form.
 */
class ExportForm extends FormBase {

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Entity\EntityTypeBundleInfoInterface
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityBundleInfo;

  /**
   * ExampleForm constructor.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  public function __construct(ModuleHandler $module_handler, EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $entity_bundle_info) {
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityBundleInfo = $entity_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}.
   */
  public function getFormId() {
    return 'default_content_ui_export_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Select the entity types that should be included when performing an export.') . '</p>',
    ];

    // Build the tableselect of entities.
    $export_list = $this->config('default_content_ui.settings')->get('export_list');
    $options = [];
    $types = $this->entityTypeManager->getDefinitions();
    foreach ($types as $type => $type_object) {
      if ($type_object->getGroup() == 'content' && in_array($type, $export_list)) {
        $type = $type;
        $options[$type] = [
          'name' => $type . " (" . $type_object->getLabel() . ")",
        ];
      }
    }
    ksort($options);

    $form['entity_types'] = [
      '#type' => 'tableselect',
      '#header' => [
        'name' => 'Entity type',
      ],
      '#options' => $options,
    ];

    $form['export_configuration'] = [
      '#title' => $this->t('Entity Types for Export'),
      '#type' => 'details',
      '#open' => FALSE,
    ];

    $type_options = [];
    foreach ($types as $type => $type_object) {
      if ($type_object->getGroup() == 'content') {
        $type_options[$type] = $type . " (" . $type_object->getLabel() . ")";
      }
    }
    ksort($type_options);

    $form['export_configuration']['export_list'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity types available in export configuration'),
      '#options' => $type_options,
      '#default_value' => $export_list,
    ];

    $form['export_configuration']['export_info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Selecting an entity type will modify the available entity type checkboxes. Save Configuration to reload the form after changing these values.')
    ];

    $form['export'] = [
      '#title' => $this->t('Export settings'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['export']['references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export with references'),
      '#description' => $this->t('Export entity type and referenced entity types. (This can add additional entity types that are not checked.)'),
      '#default_value' => TRUE,
    ];

    $form['export']['download'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Download a tar file'),
      '#description' => $this->t('Exports a tar file of the current export setting. (Uncheck to set export folder.)'),
      '#default_value' => TRUE,
    ];

    $form['export']['folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder'),
      '#description' => $this->t('All existing content will be exported to this folder. Example (sites/default/files/default_content)'),
      '#default_value' => $this->config('default_content_ui.settings')->get('folder'),
      '#states' => [
        'visible' => [
          ':input[name="download"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Configuration'),
      '#button_type' => 'primary',
      '#name' => 'save',
    ];
    $form['actions']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export Content'),
      '#name' => 'export',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = $this->configFactory()->getEditable('default_content_ui.settings');
    $export_list = $form_state->getValue('export_list');
    foreach ($export_list as $key => $value) {
      if ($value == '0') {
        unset($export_list[$key]);
      }
    }
    $settings->set('export_list', $export_list)->save();
    $folder = $form_state->getValue('folder');
    $settings->set('folder', $folder)->save();
    $trigger = $form_state->getTriggeringElement();
    $mode = 'entity';

    if ($form_state->getValue('references')) {
      $mode = 'references';
    }

    if ($trigger['#name'] == 'export') {

      $batch = [
        'title' => t('Exporting'),
        'operations' => [
          ['Drupal\default_content_ui\Form\ExportForm::batchStart', []],
        ],
        'finished' => 'Drupal\default_content_ui\Form\ExportForm::batchFinished',
      ];

      if ($form_state->getValue('download')) {
        $file_system = \Drupal::service('file_system');
        $folder = $file_system->getTempDirectory() . '/default_content';
      }

      foreach ($form_state->getValue('entity_types') as $entity_type => $checked) {
        if (\Drupal::entityQuery($entity_type)->execute() && $checked) {
          $batch['operations'][] = [
            'Drupal\default_content_ui\Form\ExportForm::batchExport', [
              $entity_type, $folder, $mode,
            ]
          ];
        }
      }

      if ($form_state->getValue('download')) {
        $batch['operations'][] = [
          'Drupal\default_content_ui\Form\ExportForm::batchExportTar', [
            $folder,
          ]
        ];
      }

      batch_set($batch);

    }
  }

  /**
   * {@inheritdoc}
   */
  public static function batchStart(&$context) {
    $context['results']['entity_types'] = [];
  }

  public static function batchExport($entity_type, $folder, $mode, &$context) {
    $context['results']['entity_types'][] = $entity_type;
    $exporter = \Drupal::service('default_content.exporter');
    $entities = \Drupal::entityQuery($entity_type)->execute();
    foreach ($entities as $entity_id) {
      if ($mode === 'references') {
        $exporter->exportContent($entity_type, $entity_id, $references = true, $folder);
      }
      else {
        $exporter->exportContent($entity_type, $entity_id, $references = false, $folder);
      }

    }
  }

  /**
   * {@inheritdoc}
   */
  public static function batchExportTar($folder) {
    $file_system = \Drupal::service('file_system');
    try {
      $file_system->delete($folder . '.tar.gz');
    }
    catch (FileException $e) {
      // Ignore failed deletes.
    }

    // Create new tarball.
    $archiver = new ArchiveTar($folder . '.tar.gz', 'gz');
    $archiver->addModify($folder, basename($folder), $folder);

    // Try to delete our export.
    try {
      $file_system->deleteRecursive($folder);
    }
    catch (FileException $e) {
      // Ignore failed deletes.
    }
    $download_link = Link::fromTextAndUrl(t('Download tar file'),
      Url::fromRoute('default_content_ui.export_download'))->toString();
    \Drupal::messenger()->addMessage($download_link);
  }

  /**
   * {@inheritdoc}
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      if ($results['entity_types']) {
        foreach ($results['entity_types'] as $entity_type) {
          \Drupal::messenger()
            ->addMessage(t('@entity_type has been exported.', [
              '@entity_type' => $entity_type,
            ]));
        }
      }
    }
    else {
      $error_operation = reset($operations);
      \Drupal::service('messenger')
        ->addMessage(t('An error occurred while processing @operation with arguments : @args'), [
          '@operation' => $error_operation[0],
          '@args' => print_r($error_operation[0]),
        ]);
    }
  }

}
