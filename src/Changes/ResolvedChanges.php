<?php

namespace Drupal\contentpool_replication\Changes;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\replication\Changes\Changes;

/**
 * {@inheritdoc}
 */
class ResolvedChanges extends Changes {

  /**
   * {@inheritdoc}
   */
  public function getNormal() {
    $sequences = $this->sequenceIndex
      ->useWorkspace($this->workspaceId)
      ->getRange($this->since, $this->stop);

    // Removes sequences that shouldn't be processed.
    $all_sequences = $sequences = $this->preFilterSequences($sequences, $this->since);

    $filter = $this->getFilter();
    if ($this->includeDocs == TRUE || $filter !== NULL) {
      // If we need to apply a filter or include docs, we populate the entities.
      $all_sequences = $sequences = $this->populateSequenceRevisions($sequences);
    }

    // Apply the filter to the sequences.
    $sequences = $this->filterSequences($sequences, $filter);

    // We build the change records for the sequences.
    $changes = [];
    $count = 0;
    // The entities that changes may depend on.
    $additional_changes = [];
    foreach ($sequences as $sequence) {
      if ($this->limit && $count >= $this->limit) {
        break;
      }

      $uuid = $sequence['entity_uuid'];
      if (!isset($changes[$uuid])) {
        $count++;
      }

      $changes[$uuid] = $this->buildChangeRecord($sequence);

      // Add additional documents based on references.
      if ($sequence['revision'] && $sequence['revision'] instanceof ContentEntityInterface) {
        $this->addEntityFieldReferences($sequence['revision'], $additional_changes);
      }
    }

    // Add the additional changes to the main array.
    foreach ($additional_changes as $entity_type_id => $entities) {
      /** @var ContentEntityInterface $entity */
      foreach ($entities as $entity) {
        $uuid = $entity->uuid();
        if (!isset($changes[$uuid])) {
          // We look for the sequence in the unfiltered sequences.
          $sequence_id = array_search($uuid, array_column($all_sequences, 'entity_uuid'));

          if ($sequence_id) {
            $changes[$uuid] = $this->buildChangeRecord($all_sequences[$sequence_id], $entity);
          }
        }
      }
    }

    // Now when we have rebuilt the result array we need to ensure that the
    // results array is still sorted on the sequence key, as in the index.
    $return = array_values($changes);
    usort($return, function($a, $b) {
      return $a['seq'] - $b['seq'];
    });

    return $return;
  }

  /**
   * Adds (recursively) referenced entities as additional change.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity containing references.
   * @param array $additional_changes
   *   The so far additional changes.
   */
  protected function addEntityFieldReferences(ContentEntityInterface $entity, array &$additional_changes) {
    // We filter certain base fields.
    $prohibited_field_ids = ['type', 'uid', 'revision_uid', 'vid', 'parent'];
    $field_definitions = array_filter($entity->getFieldDefinitions(), function($key) use ($prohibited_field_ids) {
      return !in_array($key, $prohibited_field_ids);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($field_definitions as $key => $field_definition) {
      if ($field_definition->getType() == 'entity_reference') {
        // Add the referenced entities itself.
        foreach ($entity->{$key}->referencedEntities() as $referenced_entity) {
          /* @var EntityInterface $referenced_entity */

          // Only add it if it's not already added.
          if (isset($additional_changes[$referenced_entity->getEntityTypeId()][$referenced_entity->id()])) {
            continue;
          }

          // If the target entity type is fieldable we have to check it too.
          if ($referenced_entity instanceof ContentEntityInterface) {
            // We only add entry if entity available.
            $additional_changes[$referenced_entity->getEntityTypeId()][$referenced_entity->id()] = $referenced_entity;
            $this->addEntityFieldReferences($referenced_entity, $additional_changes);
          }
        }
      }
    }
  }

}
