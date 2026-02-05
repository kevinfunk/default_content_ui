<?php

namespace Drupal\default_content_ui\EventSubscriber;

use Drupal\Core\DefaultContent\PreExportEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to Default Content export events.
 */
class DefaultContentSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreExportEvent::class => 'onPreExport',
    ];
  }

  /**
   * Modifies export callbacks to strip invalid UUIDs.
   *
   * Fixes a compatibility issue with JSON:API Extras, where a computed
   * 'target_uuid' property is added to entity references.
   *
   * @param \Drupal\Core\DefaultContent\PreExportEvent $event
   *   The pre-export event.
   */
  public function onPreExport(PreExportEvent $event): void {
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

}
