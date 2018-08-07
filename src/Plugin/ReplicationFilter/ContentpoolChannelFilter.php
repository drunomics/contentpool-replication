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
      $topics = $configuration['topics'];

      // If the entity doesn't have a channel field we don't bother with it here.
      if (!$entity->hasField('field_channel') && !$entity->hasField('field_topic')) {
        return TRUE;
      }

      // If basically available in the channel we optionally check for the topic.
      if ($channels && $entity->hasField('field_channel') && !$entity->field_channel->isEmpty()) {
        $channel_uuid = $entity->field_channel->entity->uuid();
        // If the remote doesn't reference the entities channel, we'll filter it.
        if (in_array($channel_uuid, array_keys($channels))) {
          return TRUE;
        }
      }

      // If basically available in the channel we optionally check for the topic.
      if ($topics && $entity->hasField('field_topic') && !$entity->field_topic->isEmpty()) {
        $topic_uuid = $entity->field_topic->entity->uuid();
        // If the remote doesn't reference the entities topic, we'll filter it.
        if (in_array($topic_uuid, array_keys($topics))) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
