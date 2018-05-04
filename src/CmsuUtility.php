<?php

namespace Drupal\content_moderation_scheduled_updates;

use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Cmsu utilities.
 */
class CmsuUtility implements CmsuUtilityInterface {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Creates a new CmsuHooks instance.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *    The entity field manager.
   */
  public function __construct(EntityFieldManagerInterface $entityFieldManager) {
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getScheduledUpdateReferenceFields(string $entityTypeId, string $bundle): array {
    $definitions = $this->entityFieldManager
      ->getFieldDefinitions($entityTypeId, $bundle);

    $fieldNames = [];
    foreach ($definitions as $definition) {
      if ('entity_reference' !== $definition->getType()) {
        continue;
      }

      if ('scheduled_update' !== $definition->getFieldStorageDefinition()->getSetting('target_type')) {
        continue;
      }

      $fieldNames[] = $definition->getName();
    }

    return $fieldNames;
  }

}
