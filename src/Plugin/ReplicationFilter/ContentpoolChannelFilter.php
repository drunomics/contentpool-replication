<?php

namespace Drupal\contentpool_replication\Plugin\ReplicationFilter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\replication\Plugin\ReplicationFilter\EntityTypeFilter;

/**
 * Provides a filter based on entity type.
 *
 * Use the configuration "types" which is an array of values in the format
 * "{entity_type_id}.{bundle}".
 *
 * @ReplicationFilter(
 *   id = "contentpool_channels",
 *   label = @Translation("Filter by types and contentpool channels"),
 *   description = @Translation("Replicate only entities that match the types and contentpool channels.")
 * )
 */
class ContentpoolChannelFilter extends EntityTypeFilter {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'types' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function filter(EntityInterface $entity) {
    if(parent::filter($entity)) {
      $configuration = $this->getConfiguration();
      $channels = $configuration['channels'];

      // If the entity has a channel field and it is not empty.
      if ($entity->hasField('field_channel') && !$entity->field_channel->isEmpty()) {
        $uuid = $entity->field_channel->entity->uuid();

        // If the entity references a channel that is specified in the remote
        // settings we allow it.
        if (in_array($uuid, array_keys($channels))) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
