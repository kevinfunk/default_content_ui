<?php

namespace Drupal\default_content_ui\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides local tasks for entity export.
 */
class ExportEntityLocalTask extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ExportEntityLocalTask.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $enabled_types = $this->configFactory->get('default_content_ui.settings')->get('local_export_types');

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      $is_enabled = is_null($enabled_types) || in_array($entity_type_id, $enabled_types);

      if ($is_enabled && $entity_type->getGroup() === 'content' && $entity_type->hasLinkTemplate('canonical')) {
        $this->derivatives[$entity_type_id] = [
          'route_name' => "entity.$entity_type_id.default_content_export",
          'title' => $this->t('Export'),
          'base_route' => "entity.$entity_type_id.canonical",
          'weight' => 100,
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

}
