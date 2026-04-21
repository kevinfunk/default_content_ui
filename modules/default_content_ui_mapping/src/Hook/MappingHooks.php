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
    $strip_translations = (bool) $config->get('strip_translations');

    // Bail early if no processing is configured.
    if (empty($mappings) && !$strip_translations && empty($exclusion_rules)) {
      return;
    }

    $content_root = is_dir($folder . '/content') ? $folder . '/content' : $folder;
    $files = $this->fileSystem->scanDirectory($content_root, '/\.yml$/');

    foreach ($files as $file) {
      $this->processFile($file->uri, $mappings, $exclusion_rules, $strip_translations, $config->get('fallback_langcode') ?: 'default');
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
   * @param bool $strip_translations
   *   Whether to strip non-fallback translations.
   * @param string $fallback_langcode
   *   The primary language code to retain.
   */
  protected function processFile(string $uri, array $mappings, array $exclusion_rules, bool $strip_translations, string $fallback_langcode): void {
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
      $changed = $this->stripTranslations($data, $fallback_langcode) || $changed;
    }

    $changed = $this->applyRules($data, $mappings, $exclusion_rules) || $changed;

    if ($changed) {
      file_put_contents($uri, Yaml::encode($data));
    }
  }

  /**
   * Removes non-fallback translations and metadata.
   *
   * @param array $data
   *   The parsed YAML data array.
   * @param string $fallback_langcode
   *   The language code to retain.
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function stripTranslations(array &$data, string $fallback_langcode): bool {
    $changed = FALSE;
    $stripped_langs = [];

    foreach (array_keys($data) as $langcode) {
      if (!in_array($langcode, ['_meta', 'default', $fallback_langcode], TRUE)) {
        $stripped_langs[] = $langcode;
        unset($data[$langcode]);
        $changed = TRUE;
      }
      elseif ($langcode !== '_meta' && is_array($data[$langcode])) {
        foreach (['content_translation_source', 'content_translation_outdated'] as $meta_field) {
          if (isset($data[$langcode][$meta_field])) {
            unset($data[$langcode][$meta_field]);
            $changed = TRUE;
          }
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
   * Applies field mappings and exclusion rules to the data array.
   *
   * @param array $data
   *   The parsed YAML data array.
   * @param array $mappings
   *   The field mapping rules.
   * @param array $exclusion_rules
   *   The field exclusion rules.
   *
   * @return bool
   *   TRUE if the data was modified, FALSE otherwise.
   */
  protected function applyRules(array &$data, array $mappings, array $exclusion_rules): bool {
    $changed = FALSE;

    if (empty($mappings) && empty($exclusion_rules)) {
      return FALSE;
    }

    $entity_type = $data['_meta']['entity_type'] ?? '';
    $bundle = $data['_meta']['bundle'] ?? '';

    foreach ($data as $langcode => &$translation_data) {
      if ($langcode === '_meta' || !is_array($translation_data)) {
        continue;
      }

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

      foreach ($mappings as $source => $target) {
        if (array_key_exists($source, $translation_data)) {
          $translation_data[$target] = $translation_data[$source];
          unset($translation_data[$source]);
          $changed = TRUE;
        }
      }
    }

    return $changed;
  }

}
