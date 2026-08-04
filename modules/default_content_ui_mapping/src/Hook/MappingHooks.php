<?php

namespace Drupal\default_content_ui_mapping\Hook;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hook implementations for Default Content UI Mapping.
 */
class MappingHooks implements ContainerInjectionInterface {

  /**
   * Constructs a new MappingHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('logger.factory')
    );
  }

  /**
   * Implements hook_default_content_ui_pre_import().
   */
  #[Hook('default_content_ui_pre_import')]
  public function preImport(string $folder): void {
    $config = $this->configFactory->get('default_content_ui_mapping.settings');

    $mappings = array_column($config->get('mappings') ?? [], 'target', 'source');
    $exclusion_rules = $config->get('excluded_fields') ?? [];
    $value_exclusions = $config->get('value_exclusions') ?? [];
    $strip_translations = (bool) $config->get('strip_translations');

    // Bail early if no processing is configured.
    if (empty($mappings) && !$strip_translations && empty($exclusion_rules) && empty($value_exclusions)) {
      return;
    }

    $content_root = is_dir($folder . '/content') ? $folder . '/content' : $folder;
    $files = $this->fileSystem->scanDirectory($content_root, '/\.yml$/');

    foreach ($files as $file) {
      $this->processFile($file->uri, $mappings, $exclusion_rules, $value_exclusions, $strip_translations, $config->get('fallback_langcode') ?: 'default');
    }
  }

  /**
   * Processes a single YAML file and applies configured rules.
   *
   * @param string $uri
   *   The file URI.
   * @param array $mappings
   *   The field mapping rules.
   * @param array $exclusion_rules
   *   The field exclusion rules.
   * @param array $value_exclusions
   *   The value exclusion rules.
   * @param bool $strip_translations
   *   Whether to strip non-fallback translations.
   * @param string $fallback_langcode
   *   The primary language code to retain.
   */
  protected function processFile(string $uri, array $mappings, array $exclusion_rules, array $value_exclusions, bool $strip_translations, string $fallback_langcode): void {
    try {
      $data = Yaml::decode(file_get_contents($uri));
    }
    catch (InvalidDataTypeException $e) {
      $this->loggerFactory->get('default_content_ui_mapping')->error(
        'Failed to parse YAML file during mapping prep: @file. Error: @error',
        ['@file' => $uri, '@error' => $e->getMessage()]
      );
      return;
    }

    if (!is_array($data)) {
      return;
    }

    $changed = FALSE;

    if ($strip_translations) {
      $changed = $this->stripTranslations($data, $fallback_langcode);
    }

    $changed = $this->applyRules($data, $mappings, $exclusion_rules, $value_exclusions) || $changed;

    if ($changed) {
      file_put_contents($uri, Yaml::encode($data));
    }
  }

  /**
   * Removes non-fallback translations and metadata.
   *
   * Default-content YAML stores the default-language field values under
   * 'default' and every other translation under 'translations.<langcode>'
   * (see \Drupal\Core\DefaultContent\Exporter::exportEntity()) — 'default'
   * is always retained here, and $fallback_langcode names the one
   * additional translation (if any) to keep from 'translations'.
   *
   * @param array $data
   *   The parsed YAML data array.
   * @param string $fallback_langcode
   *   The langcode of the one additional translation to retain, if any.
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function stripTranslations(array &$data, string $fallback_langcode): bool {
    $changed = FALSE;
    $stripped_langs = [];

    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach (array_keys($data['translations']) as $langcode) {
        if ($langcode !== $fallback_langcode) {
          $stripped_langs[] = $langcode;
          unset($data['translations'][$langcode]);
          $changed = TRUE;
        }
      }
      if (empty($data['translations'])) {
        unset($data['translations']);
      }
    }

    if (isset($data['default']) && is_array($data['default'])) {
      $changed = $this->stripTranslationMetaFields($data['default']) || $changed;
    }
    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach ($data['translations'] as &$translation_data) {
        if (is_array($translation_data)) {
          $changed = $this->stripTranslationMetaFields($translation_data) || $changed;
        }
      }
    }

    if (!empty($stripped_langs)) {
      $uuid = $data['_meta']['uuid'] ?? 'unknown';
      $this->loggerFactory->get('default_content_ui_mapping')->notice(
        'Stripped translations (@langs) from entity UUID: @uuid.',
        ['@langs' => implode(', ', $stripped_langs), '@uuid' => $uuid]
      );
    }

    return $changed;
  }

  /**
   * Removes translation-tracking metadata fields from one translation block.
   *
   * @param array $translation_data
   *   A single translation's field-values array (by reference).
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function stripTranslationMetaFields(array &$translation_data): bool {
    $changed = FALSE;
    foreach (['content_translation_source', 'content_translation_outdated'] as $meta_field) {
      if (isset($translation_data[$meta_field])) {
        unset($translation_data[$meta_field]);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Applies field mappings and exclusion rules to the data array.
   *
   * Applied to 'default' (the default-language field values) and every
   * entry of 'translations' (the other per-langcode field values) — see
   * the note on stripTranslations() about the actual default-content
   * YAML shape.
   *
   * @param array $data
   *   The parsed YAML data array.
   * @param array $mappings
   *   The field mapping rules.
   * @param array $exclusion_rules
   *   The field exclusion rules.
   * @param array $value_exclusions
   *   The value exclusion rules.
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function applyRules(array &$data, array $mappings, array $exclusion_rules, array $value_exclusions): bool {
    if (empty($mappings) && empty($exclusion_rules) && empty($value_exclusions)) {
      return FALSE;
    }

    $entity_type = $data['_meta']['entity_type'] ?? '';
    $bundle = $data['_meta']['bundle'] ?? $entity_type;
    $changed = FALSE;

    if (isset($data['default']) && is_array($data['default'])) {
      $changed = $this->applyRulesToTranslation($data['default'], $entity_type, $bundle, $mappings, $exclusion_rules, $value_exclusions);
    }

    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach ($data['translations'] as &$translation_data) {
        if (is_array($translation_data)) {
          $changed = $this->applyRulesToTranslation($translation_data, $entity_type, $bundle, $mappings, $exclusion_rules, $value_exclusions) || $changed;
        }
      }
    }

    return $changed;
  }

  /**
   * Applies field mappings and exclusion rules to a single translation.
   *
   * @param array $translation_data
   *   A single translation's field-values array (by reference).
   * @param string $entity_type
   *   The entity type the rules should be scoped to, if set.
   * @param string $bundle
   *   The bundle the rules should be scoped to, if set.
   * @param array $mappings
   *   The field mapping rules.
   * @param array $exclusion_rules
   *   The field exclusion rules.
   * @param array $value_exclusions
   *   The value exclusion rules.
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function applyRulesToTranslation(array &$translation_data, string $entity_type, string $bundle, array $mappings, array $exclusion_rules, array $value_exclusions): bool {
    $changed = FALSE;

    foreach ($exclusion_rules as $rule) {
      if (!empty($rule['entity_type']) && $rule['entity_type'] !== $entity_type) {
        continue;
      }
      if (!empty($rule['bundle']) && $rule['bundle'] !== $bundle) {
        continue;
      }

      $field = $rule['field_name'];
      if (array_key_exists($field, $translation_data)) {
        unset($translation_data[$field]);
        $changed = TRUE;
      }
    }

    foreach ($value_exclusions as $rule) {
      if (!empty($rule['entity_type']) && $rule['entity_type'] !== $entity_type) {
        continue;
      }
      if (!empty($rule['bundle']) && $rule['bundle'] !== $bundle) {
        continue;
      }

      $field = $rule['field_name'];
      $property = $rule['property'];
      $target_value = $rule['value'];

      if (!empty($translation_data[$field]) && is_array($translation_data[$field])) {
        $field_changed = FALSE;

        foreach ($translation_data[$field] as $index => $item) {
          if (isset($item[$property]) && (string) $item[$property] === (string) $target_value) {
            unset($translation_data[$field][$index]);
            $field_changed = TRUE;
            $changed = TRUE;
          }
        }

        if ($field_changed) {
          $translation_data[$field] = array_values($translation_data[$field]);
        }
      }
    }

    foreach ($mappings as $source => $target) {
      if (array_key_exists($source, $translation_data)) {
        $translation_data[$target] = $translation_data[$source];
        unset($translation_data[$source]);
        $changed = TRUE;
      }
    }

    return $changed;
  }

}
