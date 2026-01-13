<?php

namespace Drupal\default_content_ui\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Default Content UI settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

  /**
   * Constructs a new SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Menu\LocalTaskManagerInterface $local_task_manager
   *   The local task manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    EntityTypeManagerInterface $entity_type_manager,
    LocalTaskManagerInterface $local_task_manager,
  ) {
    parent::__construct($config_factory, $typed_config);
    $this->entityTypeManager = $entity_type_manager;
    $this->localTaskManager = $local_task_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.menu.local_task')
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
    return 'default_content_ui_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('default_content_ui.settings');

    $form['single_export'] = [
      '#type' => 'details',
      '#title' => $this->t('Single Entity Export'),
      '#description' => $this->t('Configure the "Export" tabs and operation links that appear on individual content items.'),
      '#open' => TRUE,
    ];

    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->getGroup() === 'content' && $entity_type->hasLinkTemplate('canonical')) {
        $options[$entity_type_id] = [
          'label' => $entity_type->getLabel(),
          'id' => $entity_type_id,
        ];
      }
    }
    uasort($options, fn($a, $b) => strcmp($a['label'], $b['label']));

    $saved_values = $config->get('local_export_types');
    if ($saved_values === NULL) {
      $default_values = array_fill_keys(array_keys($options), TRUE);
    }
    else {
      $default_values = array_fill_keys($saved_values, TRUE);
    }

    $form['single_export']['local_export_types'] = [
      '#type' => 'tableselect',
      '#header' => [
        'label' => $this->t('Enabled Entity Types'),
        'id' => $this->t('Machine Name'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No content entities found.'),
      '#default_value' => $default_values,
    ];

    $form['single_export']['references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include dependencies'),
      '#description' => $this->t('Automatically export referenced entities (e.g., taxonomy terms, media) when exporting a single item.'),
      '#default_value' => $config->get('local_export_reference_mode') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('local_export_types'));
    $enabled_types = array_keys($selected);
    $reference_mode = (bool) $form_state->getValue('references');

    $this->config('default_content_ui.settings')
      ->set('local_export_types', $enabled_types)
      ->set('local_export_reference_mode', $reference_mode)
      ->save();

    $this->localTaskManager->clearCachedDefinitions();

    parent::submitForm($form, $form_state);
  }

}
