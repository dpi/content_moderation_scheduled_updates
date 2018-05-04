<?php

namespace Drupal\content_moderation_scheduled_updates;

/**
 * Cmsu utilities.
 */
interface CmsuUtilityInterface {

  /**
   * Get entity reference fields which reference scheduled update entities.
   *
   * @param string $entityTypeId
   *   An entity type ID.
   * @param string $bundle
   *   Bundle for an entity type.
   *
   * @return string[]
   *   An array of field names.
   */
  public function getScheduledUpdateReferenceFields(string $entityTypeId, string $bundle): array;

}
