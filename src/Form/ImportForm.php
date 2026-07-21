<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\default_content_ui\Batch\ImportBatch;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements the Import form.
 */
class ImportForm extends FormBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * ImportForm constructor.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_content_ui_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['archive'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload ZIP Archive'),
      '#description' => $this->t('<strong>Format:</strong> .zip<br><strong>Structure:</strong> The archive must contain folders named by entity type (e.g., <em>node</em>, <em>taxonomy_term</em>) containing YAML files.'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'zip'],
        'FileSizeLimit' => ['fileLimit' => 50 * 1024 * 1024],
      ],
      '#upload_location' => 'temporary://',
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Content'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fids = $form_state->getValue('archive');
    if (empty($fids)) {
      return;
    }

    $fid = reset($fids);
    $file = File::load($fid);

    if (!$file) {
      $this->messenger()->addError($this->t('The file could not be loaded.'));
      return;
    }

    $zip_uri = $file->getFileUri();
    $extract_path = 'temporary://import_extract_' . uniqid('', TRUE);

    $batch = [
      'title' => $this->t('Importing Content'),
      'operations' => [
        [
          [ImportBatch::class, 'extract'],
          [$zip_uri, $extract_path, $fid],
        ],
        [
          [ImportBatch::class, 'import'],
          [$extract_path],
        ],
      ],
      'finished' => [ImportBatch::class, 'finished'],
    ];

    batch_set($batch);
  }

}
