<?php

namespace Drupal\default_content_ui\EventSubscriber;

use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to Default Content export events.
 *
 * "entity_reference_revisions" here names the field type being targeted
 * (Drupal's actual machine name for it, used by Paragraphs, Cohesion, and
 * others), not a description of what this class does with revisions: the
 * fix deliberately discards target_revision_id and exports only a plain
 * entity UUID, so the reference always resolves to whatever the target
 * entity's current revision is on import — never a specific historical
 * one pinned at export time. See exportEntityReferenceRevisionsItem()
 * for why.
 */
class DefaultContentSubscriber implements EventSubscriberInterface {

  /**
   * Field type plugin IDs that are entity_reference_revisions-family.
   *
   * Discovered lazily and cached for the container's lifetime — the
   * field type plugin definitions don't change mid-request, and
   * PreExportEvent fires once per exported entity, so re-discovering
   * them every time would be wasted work.
   *
   * @var string[]|null
   */
  protected ?array $entityReferenceRevisionsFieldTypeIds = NULL;

  public function __construct(
    protected FieldTypePluginManagerInterface $fieldTypePluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreExportEvent::class => 'onPreExport',
    ];
  }

  /**
   * Reacts before an entity is exported.
   *
   * @param \Drupal\Core\DefaultContent\PreExportEvent $event
   *   The pre-export event.
   */
  public function onPreExport(PreExportEvent $event): void {
    $this->stripInvalidTargetUuid($event);
    $this->registerEntityReferenceRevisionsCallbacks($event);
    $this->excludeHostTrackingFields($event);
  }

  /**
   * Modifies export callbacks to strip invalid UUIDs.
   *
   * Fixes a compatibility issue with JSON:API Extras, where a computed
   * 'target_uuid' property is added to entity references.
   */
  protected function stripInvalidTargetUuid(PreExportEvent $event): void {
    $callbacks = $event->getCallbacks();
    $decorated = $callbacks['field_item:entity_reference'] ?? NULL;

    if ($decorated) {
      $event->setCallback('field_item:entity_reference', function ($item, $metadata) use ($decorated): ?array {
        $values = $decorated($item, $metadata);

        if (is_array($values)) {
          unset($values['target_uuid']);
        }

        return $values;
      });
    }
  }

  /**
   * Registers an export callback for every matching field type.
   *
   * Drupal core's Default Content exporter only recognizes the base
   * 'entity_reference', 'file', and 'image' field types as resolvable
   * entity references (see
   * \Drupal\Core\DefaultContent\Exporter::export(), which registers a
   * 'field_item:<type>' callback for exactly those three). Anything else
   * — including the base 'entity_reference_revisions' type itself, and
   * any module's own subclass of it (e.g. Cohesion's
   * 'cohesion_entity_reference_revisions', or Paragraphs' own field
   * type) — falls through to the exporter's generic fallback, which
   * dumps the raw, non-portable target_id/target_revision_id values and
   * never records the referenced entity as a dependency at all.
   *
   * Only the export side needs this fix. On import,
   * \Drupal\Core\DefaultContent\Importer::setFieldValues() already
   * resolves any field property of type
   * \Drupal\Core\Entity\Plugin\DataType\EntityReference generically, via
   * the entity's UUID listed in _meta.depends — and
   * \Drupal\entity_reference_revisions\Plugin\DataType\EntityReferenceRevisions
   * (the 'entity' property's own type) extends that same core class, so
   * it is already covered without any changes there.
   *
   * The entity_reference_revisions module is not a hard dependency of
   * this one: getEntityReferenceRevisionsFieldTypeIds() simply finds no
   * matching field types if it's absent, and this becomes a no-op.
   */
  protected function registerEntityReferenceRevisionsCallbacks(PreExportEvent $event): void {
    foreach ($this->getEntityReferenceRevisionsFieldTypeIds() as $field_type_id) {
      $event->setCallback('field_item:' . $field_type_id, $this->exportEntityReferenceRevisionsItem(...));
    }
  }

  /**
   * Exports one entity_reference_revisions-family field item.
   *
   * Mirrors \Drupal\Core\DefaultContent\Exporter::exportReference(), but
   * additionally drops target_revision_id: the referenced entity is
   * resolved by UUID on import via the generic EntityReference-property
   * resolution already in core, always to whatever its current revision
   * is at that time. Pinning to today's specific revision ID would be
   * both unportable (revision IDs are not stable across sites) and not
   * what these entities need — fields of this type are typically used
   * for entities managed 1:1 alongside their host (e.g. a Cohesion
   * layout, or a paragraph), where "the current revision" is always the
   * intent, not a specific historical snapshot.
   *
   * The user-0/user-1 special case in Exporter::exportReference() is
   * deliberately not replicated here: entity_reference_revisions fields
   * reference revisionable entities (nodes, media, paragraphs, and
   * similar), never user accounts.
   */
  protected function exportEntityReferenceRevisionsItem(FieldItemInterface $item, ExportMetadata $metadata): ?array {
    $entity = $item->get('entity')->getValue();
    if ($entity === NULL) {
      \Drupal::logger('default_content_ui')->warning('Failed to export a reference on field @field of @entity_type %label because the referenced entity no longer exists.', [
        '@field' => $item->getFieldDefinition()->getLabel(),
        '@entity_type' => $item->getEntity()->getEntityTypeId(),
        '%label' => $item->getEntity()->label(),
      ]);
      return NULL;
    }

    $values = [
      'target_id' => $item->get('target_id')->getValue(),
      'target_revision_id' => $item->get('target_revision_id')->getValue(),
    ];

    if ($entity instanceof ContentEntityInterface) {
      $metadata->addDependency($entity);
      if ($entity->getEntityType()->hasIntegerId()) {
        unset($values['target_id'], $values['target_revision_id']);
        $values['entity'] = $entity->uuid();
      }
    }

    return $values;
  }

  /**
   * Returns the plugin IDs of every entity_reference_revisions-family type.
   *
   * @return string[]
   *   The field type plugin IDs.
   */
  protected function getEntityReferenceRevisionsFieldTypeIds(): array {
    if ($this->entityReferenceRevisionsFieldTypeIds === NULL) {
      $this->entityReferenceRevisionsFieldTypeIds = [];
      // Referenced by string, not `use`d: entity_reference_revisions is
      // not a hard dependency of this module, and is_a() with a class
      // name that can't be autoloaded simply returns FALSE rather than
      // fatally erroring.
      $base_class = 'Drupal\entity_reference_revisions\Plugin\Field\FieldType\EntityReferenceRevisionsItem';
      foreach ($this->fieldTypePluginManager->getDefinitions() as $plugin_id => $definition) {
        $class = $definition['class'] ?? NULL;
        if ($class && is_a($class, $base_class, TRUE)) {
          $this->entityReferenceRevisionsFieldTypeIds[] = $plugin_id;
        }
      }
    }
    return $this->entityReferenceRevisionsFieldTypeIds;
  }

  /**
   * Excludes entity_reference_revisions host-tracking fields from export.
   *
   * A composite entity's entity_revision_parent_*_field annotations
   * (e.g. a Cohesion layout's parent_id) store a raw, site-specific
   * numeric host ID — not portable, and not necessary to export:
   * EntityReferenceRevisionsItem::postSave() re-derives them
   * automatically whenever the real host entity is saved, which happens
   * the moment it's imported.
   */
  protected function excludeHostTrackingFields(PreExportEvent $event): void {
    $entity_type = $event->entity->getEntityType();
    $annotations = [
      'entity_revision_parent_id_field',
      'entity_revision_parent_type_field',
      'entity_revision_parent_field_name_field',
    ];
    foreach ($annotations as $annotation) {
      $field_name = $entity_type->get($annotation);
      if ($field_name) {
        $event->setExportable($field_name, FALSE);
      }
    }
  }

}
