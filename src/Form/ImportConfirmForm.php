<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\default_content_ui\Batch\ImportBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports a dry-run scan of an uploaded archive before it is imported.
 */
class ImportConfirmForm extends FormBase {

  /**
   * The private tempstore for this module.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected PrivateTempStore $tempStore;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * ImportConfirmForm constructor.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, FileSystemInterface $file_system, ModuleHandlerInterface $module_handler) {
    $this->tempStore = $temp_store_factory->get('default_content_ui');
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private'),
      $container->get('file_system'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_content_ui_import_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $pending = $this->tempStore->get('pending_import');

    if (empty($pending)) {
      $form['empty'] = [
        '#markup' => $this->t('There is no pending import to review. <a href=":url">Upload an archive</a> to get started.', [
          ':url' => Url::fromRoute('default_content_ui.import')->toString(),
        ]),
      ];
      return $form;
    }

    $warnings = $pending['field_warnings'] ?? [];
    $form['actions'] = ['#type' => 'actions'];

    if ($warnings) {
      $form['warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('The following field issues were found in the archive:'),
        '#items' => array_map('strval', array_unique($warnings)),
      ];

      // Importing now would hit the exact same unrecognized field and
      // abort (see ImportBatch::scanForUnknownFields()), so there's no
      // working "Import Content" action to offer here — only a way to fix
      // the mismatch first, then Cancel and re-upload.
      if ($this->moduleHandler->moduleExists('default_content_ui_mapping')) {
        $form['fix'] = [
          '#type' => 'link',
          '#title' => $this->t('Go to Field Mapping to add a mapping rule'),
          '#url' => Url::fromRoute('default_content_ui_mapping.settings'),
        ];
      }
      else {
        $form['fix'] = [
          '#type' => 'link',
          '#title' => $this->t('Enable the Default Content UI Mapping module to fix field names during import'),
          '#url' => Url::fromRoute('system.modules_list'),
        ];
      }
    }
    else {
      $form['no_warnings'] = [
        '#markup' => '<p>' . $this->t('No field issues were found.') . '</p>',
      ];
      $form['actions']['import'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import Content'),
        '#button_type' => 'primary',
        '#submit' => ['::submitImport'],
      ];
    }

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::submitCancel'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op: both actions above declare their own #submit handler.
  }

  /**
   * Submit handler for proceeding with the previously scanned import.
   */
  public function submitImport(array &$form, FormStateInterface $form_state) {
    $pending = $this->tempStore->get('pending_import');
    $this->tempStore->delete('pending_import');

    if (empty($pending['extract_path'])) {
      $this->messenger()->addError($this->t('The staged import could not be found. Please upload the archive again.'));
      return;
    }

    // The form never renders this action while warnings are present, but
    // guard against it directly too, since importing now is guaranteed to
    // fail on the first unrecognized field.
    if (!empty($pending['field_warnings'])) {
      $this->messenger()->addError($this->t('This import cannot proceed until its field issues are resolved. Please upload the archive again.'));
      ImportBatch::cleanupExtracted($pending);
      return;
    }

    $batch = [
      'title' => $this->t('Importing Content'),
      'operations' => [
        [
          [ImportBatch::class, 'resumeExtracted'],
          [$pending['extract_path'], $pending['cleanup_fid'] ?? NULL],
        ],
        [
          [ImportBatch::class, 'import'],
          [$pending['extract_path']],
        ],
      ],
      'finished' => [ImportBatch::class, 'finished'],
    ];

    batch_set($batch);
  }

  /**
   * Submit handler for discarding the previously scanned import.
   */
  public function submitCancel(array &$form, FormStateInterface $form_state) {
    $pending = $this->tempStore->get('pending_import');
    $this->tempStore->delete('pending_import');

    if ($pending) {
      ImportBatch::cleanupExtracted($pending);
    }

    $this->messenger()->addStatus($this->t('Import cancelled.'));
    $form_state->setRedirect('default_content_ui.import');
  }

}
