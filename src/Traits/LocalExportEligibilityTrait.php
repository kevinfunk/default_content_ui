<?php

namespace Drupal\default_content_ui\Traits;

use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Determines which entity types are eligible for single-entity export.
 *
 * An entity type must be in the "content" group and have a canonical link
 * template — the "Export" tab, operation link, and route all attach to that
 * canonical page. This same two-part check independently governs whether
 * each of those pieces of UI appears, so letting them drift out of sync
 * would mean, e.g., a tab appears for an entity type the route subscriber
 * never actually built a route for.
 *
 * Requires the consuming class to provide $this->entityTypeManager for
 * getLocalExportEligibleEntityTypeIds().
 */
trait LocalExportEligibilityTrait {

  /**
   * Determines whether an entity type is eligible for single-entity export.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to check.
   *
   * @return bool
   *   TRUE if the entity type is eligible.
   */
  protected function isEligibleForLocalExport(EntityTypeInterface $entity_type): bool {
    return $entity_type->getGroup() === 'content' && $entity_type->hasLinkTemplate('canonical');
  }

  /**
   * Returns the IDs of entity types eligible for single-entity export.
   *
   * @return string[]
   *   The eligible entity type IDs.
   */
  protected function getLocalExportEligibleEntityTypeIds(): array {
    $ids = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($this->isEligibleForLocalExport($entity_type)) {
        $ids[] = $entity_type_id;
      }
    }
    return $ids;
  }

}
