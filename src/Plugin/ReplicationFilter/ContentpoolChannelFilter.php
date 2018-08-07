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
        // We add the child terms to the channels.
        $channels = array_merge($channels, $this->getChildTerms($channels, 'channel'));
        $channel_uuid = $entity->field_channel->entity->uuid();
        // If the remote doesn't reference the entities channel, we'll filter it.
        if (in_array($channel_uuid, array_keys($channels))) {
          return TRUE;
        }
      }

      // If basically available in the channel we optionally check for the topic.
      if ($topics && $entity->hasField('field_topic') && !$entity->field_topic->isEmpty()) {
        // We add the child terms to the topics..
        $topics = array_merge($topics, $this->getChildTerms($topics, 'topics'));
        $topic_uuid = $entity->field_topic->entity->uuid();
        // If the remote doesn't reference the entities topic, we'll filter it.
        if (in_array($topic_uuid, array_keys($topics))) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Gets an array of term uuids that are children of the specified terms.
   *
   * @param $terms
   * @param $vocabulary_id
   */
  protected function getChildTerms($terms, $vocabulary_id) {
    $child_terms = [];
    foreach ($terms as $term_uuid) {
      // We need the taxonomy term object for the local id.
      $found_channels = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['uuid' => $term_uuid]);

      // If we found one  we get all children and add their uuids.
      if (!empty($found_channels)) {
        $children = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary_id, reset(array_keys($found_channels)), NULL, TRUE);

        foreach ($children as $child) {
          $child_terms[$child->uuid()] = $child->uuid();
        }
      }
    }

    return $child_terms;
  }

}
