<?php

/**
 * @file
 * Contains \Drupal\default_content_ui\Form\ImportForm.
 */
namespace Drupal\default_content_ui\Form;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandler;

/**
 * Implements the Default content import form.
 */
class ImportForm extends FormBase {

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
    return 'default_content_ui_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['import']['location'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import location'),
      //'#default_value' => 'tarball',
      '#options' => [
        'tarball' => $this->t('Upload tar file'),
        'folder' => $this->t('Folder on server'),
      ],
    ];

    $form['import']['tarball'] = [
      '#type' => 'file',
      '#title' => $this->t('Import from a tar file.'),
      '#description' => $this->t('This will not update existing content in a content directory.'),
      '#states' => [
        'visible' => [
          ':input[name="location"]' => ['value' => 'tarball'],
        ],
      ],
    ];

    $form['import']['folder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder'),
      '#description' => $this->t('Folder for content to be imported from. Example (sites/default/files/default_content)'),
      '#default_value' => $this->config('default_content_ui.settings')->get('folder'),
      '#states' => [
        'visible' => [
          ':input[name="location"]' => ['value' => 'folder'],
        ],
      ],
    ];

    $form['import']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import content'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $all_files = $this->getRequest()->files->get('files', []);
    if (!empty($all_files['tarball'])) {
      $file_upload = $all_files['tarball'];
      if ($file_upload->isValid()) {
        $form_state->setValue('tarball', $file_upload->getRealPath());
        return;
      }
      else {
        $form_state->setErrorByName('tarball', $this->t('The file could not be uploaded.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = $this->configFactory()->getEditable('default_content_ui.settings');
    $file_system = \Drupal::service('file_system');
    $module = 'default_content';
    $importer = \Drupal::service('default_content.importer');

    if ($path = $form_state->getValue('tarball')) {
      try {
        $archiver = new ArchiveTar($path, 'gz');
        $files = [];
        foreach ($archiver->listContent() as $file) {
          $files[] = $file['filename'];
        }
        $temp_folder = $file_system->getTempDirectory();
        $archiver->extractList($files, $temp_folder, '', FALSE, FALSE);
        $folder = $temp_folder . '/default_content';
        $importer->importContent($module, $folder);
        $file_system = \Drupal::service('file_system');
        $file_system->deleteRecursive($folder . '/default_content');
      }
      catch (\Exception $e) {
      }
      $file_system->unlink($path);
    }
    else {
      $folder = $form_state->getValue('folder');
      $settings->set('folder', $folder)->save();
      $importer->importContent($module, $folder);
    }

    \Drupal::messenger()->addMessage(t('Content has been imported.'));
  }


}
