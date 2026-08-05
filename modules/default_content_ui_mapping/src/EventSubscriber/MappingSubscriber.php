<?php

namespace Drupal\default_content_ui_mapping\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies field mappings, exclusions, and translation stripping on import.
 */
class MappingSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreEntityImportEvent::class => 'onPreEntityImport',
    ];
  }

  /**
   * Reacts before an entity is created during default content import.
   */
  public function onPreEntityImport(PreEntityImportEvent $event): void {
    $config = $this->configFactory->get('default_content_ui_mapping.settings');

    $mappings = array_column($config->get('mappings') ?? [], 'target', 'source');
    $exclusion_rules = $config->get('excluded_fields') ?? [];
    $value_exclusions = $config->get('value_exclusions') ?? [];
    $strip_translations = (bool) $config->get('strip_translations');

    // Bail early if no processing is configured.
    if (empty($mappings) && !$strip_translations && empty($exclusion_rules) && empty($value_exclusions)) {
      return;
    }

    $fallback_langcode = $config->get('fallback_langcode') ?: 'default';

    if ($strip_translations) {
      $this->stripTranslations($event->data, $fallback_langcode, $event->metadata['uuid'] ?? 'unknown');
    }

    $this->applyRules($event->data, $event->metadata, $mappings, $exclusion_rules, $value_exclusions);
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
   *   The entity data (by reference).
   * @param string $fallback_langcode
   *   The langcode of the one additional translation to retain, if any.
   * @param string $uuid
   *   The entity UUID, for logging.
   */
  protected function stripTranslations(array &$data, string $fallback_langcode, string $uuid): void {
    $stripped_langs = [];

    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach (array_keys($data['translations']) as $langcode) {
        if ($langcode !== $fallback_langcode) {
          $stripped_langs[] = $langcode;
          unset($data['translations'][$langcode]);
        }
      }
      if (empty($data['translations'])) {
        unset($data['translations']);
      }
    }

    if (isset($data['default']) && is_array($data['default'])) {
      $this->stripTranslationMetaFields($data['default']);
    }
    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach ($data['translations'] as &$translation_data) {
        if (is_array($translation_data)) {
          $this->stripTranslationMetaFields($translation_data);
        }
      }
    }

    if (!empty($stripped_langs)) {
      $this->loggerFactory->get('default_content_ui_mapping')->notice(
        'Stripped translations (@langs) from entity UUID: @uuid.',
        ['@langs' => implode(', ', $stripped_langs), '@uuid' => $uuid]
      );
    }
  }

  /**
   * Removes translation-tracking metadata fields from one translation block.
   *
   * @param array $translation_data
   *   A single translation's field-values array (by reference).
   */
  protected function stripTranslationMetaFields(array &$translation_data): void {
    foreach (['content_translation_source', 'content_translation_outdated'] as $meta_field) {
      unset($translation_data[$meta_field]);
    }
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
   *   The entity data (by reference).
   * @param array $metadata
   *   The entity metadata (entity_type, bundle, etc.).
   * @param array $mappings
   *   The field mapping rules.
   * @param array $exclusion_rules
   *   The field exclusion rules.
   * @param array $value_exclusions
   *   The value exclusion rules.
   */
  protected function applyRules(array &$data, array $metadata, array $mappings, array $exclusion_rules, array $value_exclusions): void {
    if (empty($mappings) && empty($exclusion_rules) && empty($value_exclusions)) {
      return;
    }

    $entity_type = $metadata['entity_type'] ?? '';
    $bundle = $metadata['bundle'] ?? $entity_type;

    if (isset($data['default']) && is_array($data['default'])) {
      $this->applyRulesToTranslation($data['default'], $entity_type, $bundle, $mappings, $exclusion_rules, $value_exclusions);
    }

    if (isset($data['translations']) && is_array($data['translations'])) {
      foreach ($data['translations'] as &$translation_data) {
        if (is_array($translation_data)) {
          $this->applyRulesToTranslation($translation_data, $entity_type, $bundle, $mappings, $exclusion_rules, $value_exclusions);
        }
      }
    }
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
   */
  protected function applyRulesToTranslation(array &$translation_data, string $entity_type, string $bundle, array $mappings, array $exclusion_rules, array $value_exclusions): void {
    foreach ($exclusion_rules as $rule) {
      if (!empty($rule['entity_type']) && $rule['entity_type'] !== $entity_type) {
        continue;
      }
      if (!empty($rule['bundle']) && $rule['bundle'] !== $bundle) {
        continue;
      }
      unset($translation_data[$rule['field_name']]);
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
      }
    }
  }

}
