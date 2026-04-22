<?php

namespace Drupal\default_content_ui_mapping\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Default Content UI Mapping settings.
 */
class MappingSettingsForm extends ConfigFormBase {

  /**
   * Constructs a new MappingSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['default_content_ui_mapping.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'default_content_ui_mapping_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('default_content_ui_mapping.settings');
    $form['#tree'] = TRUE;
    $user_input = $form_state->getUserInput();

    $form['translation_handling'] = $this->buildTranslationHandlingForm($config);

    $exclusions = $form_state->isRebuilding() ?
      ($user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields'] ?? []) :
      ($config->get('excluded_fields') ?? []);
    $form['exclusion_handling'] = $this->buildExclusionsTable($exclusions, $form_state);

    $value_exclusions = $form_state->isRebuilding() ?
      ($user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions'] ?? []) :
      ($config->get('value_exclusions') ?? []);
    $form['value_exclusion_handling'] = $this->buildValueExclusionsTable($value_exclusions, $form_state);

    $mappings = $form_state->isRebuilding() ?
      ($user_input['field_mapping']['mappings_wrapper']['mappings'] ?? []) :
      ($config->get('mappings') ?? []);
    $form['field_mapping'] = $this->buildMappingsTable($mappings, $form_state);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds the translation handling section.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   *
   * @return array
   *   The form render array for this section.
   */
  protected function buildTranslationHandlingForm(Config $config): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('Translation Handling'),
      '#open' => FALSE,
    ];

    $form['strip_translations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip extra translations from incoming data'),
      '#default_value' => $config->get('strip_translations') ?? FALSE,
    ];

    $form['fallback_langcode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Primary Language Key'),
      '#default_value' => $config->get('fallback_langcode') ?? 'default',
      '#states' => [
        'visible' => [
          ':input[name="translation_handling[strip_translations]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Builds the exclusions table section.
   *
   * @param array $excluded_fields
   *   The existing excluded fields array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form render array for this section.
   */
  protected function buildExclusionsTable(array $excluded_fields, FormStateInterface $form_state): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('Excluded Fields (Drop Entire Field)'),
      '#open' => TRUE,
      '#description' => '<p>' . $this->t('Define which incoming fields should be completely removed during import. You can restrict this to specific Entity Types and Bundles.') . '</p>',
    ];

    $exclusions_num_rows = $form_state->get('exclusions_num_rows') ?? max(1, count($excluded_fields));
    $form_state->set('exclusions_num_rows', $exclusions_num_rows);

    $form['exclusions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'exclusions-ajax-wrapper'],
    ];

    $form['exclusions_wrapper']['excluded_fields'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Entity Type'),
        $this->t('Bundle (Optional)'),
        $this->t('Field to Exclude'),
        $this->t('Operations'),
      ],
    ];

    $entity_type_options = $this->getEntityTypeOptions();

    for ($i = 0; $i < $exclusions_num_rows; $i++) {
      $selected_entity_type = $excluded_fields[$i]['entity_type'] ?? '';
      $bundle_options = $this->getBundleOptions($selected_entity_type);

      $form['exclusions_wrapper']['excluded_fields'][$i]['entity_type'] = [
        '#type' => 'select',
        '#options' => $entity_type_options,
        '#empty_option' => $this->t('- Any Entity Type -'),
        '#default_value' => $selected_entity_type,
        '#ajax' => [
          'callback' => '::exclusionsAjaxCallback',
          'wrapper' => 'exclusions-ajax-wrapper',
        ],
      ];

      $form['exclusions_wrapper']['excluded_fields'][$i]['bundle'] = [
        '#type' => 'select',
        '#options' => $bundle_options,
        '#empty_option' => $this->t('- Any Bundle -'),
        '#default_value' => $excluded_fields[$i]['bundle'] ?? '',
        '#disabled' => empty($selected_entity_type) || empty($bundle_options),
      ];

      $form['exclusions_wrapper']['excluded_fields'][$i]['field_name'] = [
        '#type' => 'textfield',
        '#default_value' => $excluded_fields[$i]['field_name'] ?? '',
        '#placeholder' => 'e.g., field_old_data',
        '#size' => 20,
      ];

      $form['exclusions_wrapper']['excluded_fields'][$i]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_exclusion_' . $i,
        '#submit' => ['::removeExclusionRow'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::exclusionsAjaxCallback',
          'wrapper' => 'exclusions-ajax-wrapper',
        ],
      ];
    }

    $form['exclusions_wrapper']['add_exclusion'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add exclusion rule'),
      '#submit' => ['::addExclusionRow'],
      '#ajax' => [
        'callback' => '::exclusionsAjaxCallback',
        'wrapper' => 'exclusions-ajax-wrapper',
      ],
      '#button_type' => 'secondary',
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Builds the value exclusions table section.
   *
   * @param array $value_exclusions
   *   The existing value exclusions array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form render array for this section.
   */
  protected function buildValueExclusionsTable(array $value_exclusions, FormStateInterface $form_state): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('Value Exclusions (Drop Specific Values)'),
      '#open' => TRUE,
      '#description' => '<p>' . $this->t('Remove specific list items, taxonomy terms, or roles without deleting the entire field. <br><em>Example: For roles, Field = "roles", Property = "target_id", Value = "member".</em>') . '</p>',
    ];

    $num_rows = $form_state->get('value_exclusions_num_rows') ?? max(1, count($value_exclusions));
    $form_state->set('value_exclusions_num_rows', $num_rows);

    $form['value_exclusions_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'value-exclusions-ajax-wrapper'],
    ];

    $form['value_exclusions_wrapper']['value_exclusions'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Entity Type'),
        $this->t('Bundle (Optional)'),
        $this->t('Field Name'),
        $this->t('Property Name'),
        $this->t('Value to Exclude'),
        $this->t('Operations'),
      ],
    ];

    $entity_type_options = $this->getEntityTypeOptions();

    for ($i = 0; $i < $num_rows; $i++) {
      $selected_entity_type = $value_exclusions[$i]['entity_type'] ?? '';
      $bundle_options = $this->getBundleOptions($selected_entity_type);

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['entity_type'] = [
        '#type' => 'select',
        '#options' => $entity_type_options,
        '#empty_option' => $this->t('- Any -'),
        '#default_value' => $selected_entity_type,
        '#ajax' => [
          'callback' => '::valueExclusionsAjaxCallback',
          'wrapper' => 'value-exclusions-ajax-wrapper',
        ],
      ];

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['bundle'] = [
        '#type' => 'select',
        '#options' => $bundle_options,
        '#empty_option' => $this->t('- Any -'),
        '#default_value' => $value_exclusions[$i]['bundle'] ?? '',
        '#disabled' => empty($selected_entity_type) || empty($bundle_options),
      ];

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['field_name'] = [
        '#type' => 'textfield',
        '#default_value' => $value_exclusions[$i]['field_name'] ?? '',
        '#placeholder' => 'e.g., roles',
        '#size' => 12,
      ];

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['property'] = [
        '#type' => 'textfield',
        '#default_value' => $value_exclusions[$i]['property'] ?? '',
        '#placeholder' => 'e.g., target_id',
        '#size' => 12,
      ];

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['value'] = [
        '#type' => 'textfield',
        '#default_value' => $value_exclusions[$i]['value'] ?? '',
        '#placeholder' => 'e.g., member',
        '#size' => 15,
      ];

      $form['value_exclusions_wrapper']['value_exclusions'][$i]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_val_exclusion_' . $i,
        '#submit' => ['::removeValueExclusionRow'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::valueExclusionsAjaxCallback',
          'wrapper' => 'value-exclusions-ajax-wrapper',
        ],
      ];
    }

    $form['value_exclusions_wrapper']['add_exclusion'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add value exclusion'),
      '#submit' => ['::addValueExclusionRow'],
      '#ajax' => [
        'callback' => '::valueExclusionsAjaxCallback',
        'wrapper' => 'value-exclusions-ajax-wrapper',
      ],
      '#button_type' => 'secondary',
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Builds the field mapping table section.
   *
   * @param array $mappings
   *   The existing mappings array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form render array for this section.
   */
  protected function buildMappingsTable(array $mappings, FormStateInterface $form_state): array {
    $form = [
      '#type' => 'details',
      '#title' => $this->t('Field Mapping'),
      '#open' => TRUE,
    ];

    $num_rows = $form_state->get('num_rows') ?? max(1, count($mappings));
    $form_state->set('num_rows', $num_rows);

    $form['mappings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'mappings-ajax-wrapper'],
    ];

    $form['mappings_wrapper']['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Incoming Field Name (Source)'),
        $this->t('Site Field Name (Target)'),
        $this->t('Operations'),
      ],
    ];

    for ($i = 0; $i < $num_rows; $i++) {
      $form['mappings_wrapper']['mappings'][$i]['source'] = [
        '#type' => 'textfield',
        '#default_value' => $mappings[$i]['source'] ?? '',
      ];

      $form['mappings_wrapper']['mappings'][$i]['target'] = [
        '#type' => 'textfield',
        '#placeholder' => 'e.g., field_media_image',
        '#default_value' => $mappings[$i]['target'] ?? '',
        '#size' => 25,
      ];

      $form['mappings_wrapper']['mappings'][$i]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_mapping_' . $i,
        '#submit' => ['::removeMappingRow'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::mappingsAjaxCallback',
          'wrapper' => 'mappings-ajax-wrapper',
        ],
      ];
    }

    $form['mappings_wrapper']['add_mapping'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another mapping'),
      '#submit' => ['::addMappingRow'],
      '#ajax' => [
        'callback' => '::mappingsAjaxCallback',
        'wrapper' => 'mappings-ajax-wrapper',
      ],
      '#button_type' => 'secondary',
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Retrieves a list of content entity type options.
   *
   * @return array
   *   An array of entity type labels keyed by their ID.
   */
  protected function getEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->getGroup() === 'content') {
        $options[$id] = (string) $definition->getLabel();
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Retrieves a list of bundle options for a specific entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   An array of bundle labels keyed by their bundle name.
   */
  protected function getBundleOptions(string $entity_type_id): array {
    if (empty($entity_type_id)) {
      return [];
    }

    $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);

    if (count($bundles) === 1 && key($bundles) === $entity_type_id) {
      return [];
    }

    $options = [];
    foreach ($bundles as $bundle_name => $info) {
      $options[$bundle_name] = $info['label'];
    }
    asort($options);
    return $options;
  }

  /**
   * Adds an exclusion row to the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addExclusionRow(array &$form, FormStateInterface $form_state) {
    $form_state->set('exclusions_num_rows', $form_state->get('exclusions_num_rows') + 1);
    $form_state->setRebuild();
  }

  /**
   * Removes an exclusion row from the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeExclusionRow(array &$form, FormStateInterface $form_state) {
    $row_index = $form_state->getTriggeringElement()['#parents'][3];
    $user_input = $form_state->getUserInput();
    if (isset($user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields'][$row_index])) {
      unset($user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields'][$row_index]);
      $user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields'] = array_values($user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields']);
      $form_state->setUserInput($user_input);
    }
    $form_state->set('exclusions_num_rows', max(1, count($user_input['exclusion_handling']['exclusions_wrapper']['excluded_fields'] ?? [])));
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the exclusions wrapper.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element to replace the wrapper.
   */
  public function exclusionsAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['exclusion_handling']['exclusions_wrapper'];
  }

  /**
   * Adds a value exclusion row to the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addValueExclusionRow(array &$form, FormStateInterface $form_state) {
    $form_state->set('value_exclusions_num_rows', $form_state->get('value_exclusions_num_rows') + 1);
    $form_state->setRebuild();
  }

  /**
   * Removes a value exclusion row from the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeValueExclusionRow(array &$form, FormStateInterface $form_state) {
    $row_index = $form_state->getTriggeringElement()['#parents'][3];
    $user_input = $form_state->getUserInput();
    if (isset($user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions'][$row_index])) {
      unset($user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions'][$row_index]);
      $user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions'] = array_values($user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions']);
      $form_state->setUserInput($user_input);
    }
    $form_state->set('value_exclusions_num_rows', max(1, count($user_input['value_exclusion_handling']['value_exclusions_wrapper']['value_exclusions'] ?? [])));
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the value exclusions wrapper.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element to replace the wrapper.
   */
  public function valueExclusionsAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['value_exclusion_handling']['value_exclusions_wrapper'];
  }

  /**
   * Adds a mapping row to the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function addMappingRow(array &$form, FormStateInterface $form_state) {
    $form_state->set('num_rows', $form_state->get('num_rows') + 1);
    $form_state->setRebuild();
  }

  /**
   * Removes a mapping row from the form.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function removeMappingRow(array &$form, FormStateInterface $form_state) {
    $row_index = $form_state->getTriggeringElement()['#parents'][3];
    $user_input = $form_state->getUserInput();
    if (isset($user_input['field_mapping']['mappings_wrapper']['mappings'][$row_index])) {
      unset($user_input['field_mapping']['mappings_wrapper']['mappings'][$row_index]);
      $user_input['field_mapping']['mappings_wrapper']['mappings'] = array_values($user_input['field_mapping']['mappings_wrapper']['mappings']);
      $form_state->setUserInput($user_input);
    }
    $form_state->set('num_rows', max(1, count($user_input['field_mapping']['mappings_wrapper']['mappings'] ?? [])));
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the mappings wrapper.
   *
   * @param array $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form element to replace the wrapper.
   */
  public function mappingsAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['field_mapping']['mappings_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $langcode = $form_state->getValue(['translation_handling', 'fallback_langcode']);
    if (!empty($langcode) && preg_match('/\s/', $langcode)) {
      $form_state->setErrorByName('translation_handling][fallback_langcode', $this->t('Language codes cannot contain spaces.'));
    }

    $exclusions = $form_state->getValue([
      'exclusion_handling',
      'exclusions_wrapper',
      'excluded_fields',
    ]) ?? [];

    foreach ($exclusions as $key => $exclusion) {
      if (!empty($exclusion['field_name']) && preg_match('/[^a-z0-9_]/', $exclusion['field_name'])) {
        $form_state->setErrorByName("exclusion_handling][exclusions_wrapper][excluded_fields][$key][field_name", $this->t('Field machine names must contain only lowercase letters, numbers, and underscores.'));
      }
    }

    $value_exclusions = $form_state->getValue([
      'value_exclusion_handling',
      'value_exclusions_wrapper',
      'value_exclusions',
    ]) ?? [];

    foreach ($value_exclusions as $key => $rule) {
      if (!empty($rule['field_name']) && empty($rule['property'])) {
        $form_state->setErrorByName("value_exclusion_handling][value_exclusions_wrapper][value_exclusions][$key][property", $this->t('Property name is required when applying a value exclusion.'));
      }
    }

    $mappings = $form_state->getValue([
      'field_mapping',
      'mappings_wrapper',
      'mappings',
    ]) ?? [];

    foreach ($mappings as $key => $mapping) {
      if (!empty($mapping['source']) && preg_match('/[^a-z0-9_]/', $mapping['source'])) {
        $form_state->setErrorByName("field_mapping][mappings_wrapper][mappings][$key][source", $this->t('Source machine names must contain only lowercase letters, numbers, and underscores.'));
      }
      if (!empty($mapping['target']) && preg_match('/[^a-z0-9_]/', $mapping['target'])) {
        $form_state->setErrorByName("field_mapping][mappings_wrapper][mappings][$key][target", $this->t('Target machine names must contain only lowercase letters, numbers, and underscores.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $submitted_mappings = $form_state->getValue([
      'field_mapping',
      'mappings_wrapper',
      'mappings',
    ]) ?? [];
    $valid_mappings = [];
    foreach ($submitted_mappings as $mapping) {
      if (!empty($mapping['source']) && !empty($mapping['target'])) {
        $valid_mappings[] = ['source' => trim($mapping['source']), 'target' => $mapping['target']];
      }
    }

    $submitted_exclusions = $form_state->getValue([
      'exclusion_handling',
      'exclusions_wrapper',
      'excluded_fields',
    ]) ?? [];
    $valid_exclusions = [];
    foreach ($submitted_exclusions as $exclusion) {
      if (!empty($exclusion['field_name'])) {
        $valid_exclusions[] = [
          'entity_type' => $exclusion['entity_type'] ?? '',
          'bundle' => $exclusion['bundle'] ?? '',
          'field_name' => trim($exclusion['field_name']),
        ];
      }
    }

    $submitted_val_exclusions = $form_state->getValue([
      'value_exclusion_handling',
      'value_exclusions_wrapper',
      'value_exclusions',
    ]) ?? [];
    $valid_val_exclusions = [];
    foreach ($submitted_val_exclusions as $rule) {
      if (!empty($rule['field_name']) && !empty($rule['property'])) {
        $valid_val_exclusions[] = [
          'entity_type' => $rule['entity_type'] ?? '',
          'bundle' => $rule['bundle'] ?? '',
          'field_name' => trim($rule['field_name']),
          'property' => trim($rule['property']),
          'value' => trim($rule['value']),
        ];
      }
    }

    $this->config('default_content_ui_mapping.settings')
      ->set('strip_translations', (bool) $form_state->getValue(['translation_handling', 'strip_translations']))
      ->set('fallback_langcode', $form_state->getValue(['translation_handling', 'fallback_langcode']))
      ->set('excluded_fields', $valid_exclusions)
      ->set('value_exclusions', $valid_val_exclusions)
      ->set('mappings', $valid_mappings)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
