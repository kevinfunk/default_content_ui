<?php

namespace Drupal\default_content_ui\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\default_content_ui\Traits\LocalExportEligibilityTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver for the Export Content action.
 */
class ExportActionDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;
  use LocalExportEligibilityTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ExportActionDeriver.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($this->isEligibleForLocalExport($entity_type)) {
        $this->derivatives[$entity_type_id] = $base_plugin_definition;
        $this->derivatives[$entity_type_id]['type'] = $entity_type_id;
        $this->derivatives[$entity_type_id]['label'] = $this->t('Export @type content', ['@type' => $entity_type->getLabel()]);
      }
    }
    return $this->derivatives;
  }

}
