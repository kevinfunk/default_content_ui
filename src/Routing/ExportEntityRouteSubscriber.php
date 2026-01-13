<?php

namespace Drupal\default_content_ui\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds export routes for content entities.
 */
class ExportEntityRouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ExportEntityRouteSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->getGroup() === 'content' && $entity_type->hasLinkTemplate('canonical')) {
        $route = new Route(
          $entity_type->getLinkTemplate('canonical') . '/default-content-export',
          [
            '_controller' => '\Drupal\default_content_ui\Controller\ExportEntityController::export',
            '_title' => 'Export Default Content',
            'entity_type_id' => $entity_type_id,
          ],
          [
            '_permission' => 'default content export',
            '_entity_access' => $entity_type_id . '.view',
          ],
          [
            'parameters' => [
              $entity_type_id => ['type' => 'entity:' . $entity_type_id],
            ],
            '_admin_route' => TRUE,
          ]
        );

        $collection->add("entity.$entity_type_id.default_content_export", $route);
      }
    }
  }

}
