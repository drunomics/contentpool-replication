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

    // Setup filter plugin.
    $parameters = is_array($this->parameters) ? $this->parameters : [];
    $filter = NULL;
    if (is_string($this->filter) && $this->filter) {
      $filter = $this->filterManager->createInstance($this->filter, $parameters);
    }
    // If UUIDs are sent as a parameter, but no filter is set, automatically
    // select the "uuid" filter.
    elseif (isset($parameters['uuids'])) {
      $filter = $this->filterManager->createInstance('uuid', $parameters);
    }

    // Format the result array.
    $changes = [];
    $count = 0;
    // The entities that changes may depend on.
    $additional_changes = [];
    foreach ($sequences as $sequence) {
      if (!empty($sequence['local']) || !empty($sequence['is_stub'])) {
        continue;
      }

      // When we have the since parameter set, we should exclude the value with
      // that sequence from the results.
      if ($this->since > 0 && $sequence['seq'] == $this->since) {
       continue;
      }

      // We always get the dodcument for further resolving the dependencies.
      /** @var \Drupal\multiversion\Entity\Storage\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage($sequence['entity_type_id']);
      $storage->useWorkspace($this->workspaceId);
      $revision = $storage->loadRevision($sequence['revision_id']);
      $storage->useWorkspace(NULL);

      // Filter the document.
      if ($revision && $filter !== NULL && !$filter->filter($revision)) {
        continue;
      }

      if ($this->limit && $count >= $this->limit) {
        break;
      }

      $uuid = $sequence['entity_uuid'];
      if (!isset($changes[$uuid])) {
        $count++;
      }

      $changes[$uuid] = $this->buildChangeRecord($sequence, $revision);

      // Add additional documents based on references.
      if ($revision && $revision instanceof ContentEntityInterface) {
        $additional_changes = $this->addEntityFieldReferences($revision, $additional_changes);
      }
    }

    // Add the additional changes to the main array.
    foreach ($additional_changes as $entity_type_id => $entities) {
      /** @var ContentEntityInterface $entity */
      foreach ($entities as $entity) {
        $uuid = $entity->uuid();
        if (!isset($changes[$uuid])) {
          $count++;
          $changes[$uuid] = $this->buildAdditionalChangeRecord($entity);
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

  protected function buildChangeRecord($sequence, $entity) {
    $uuid = $sequence['entity_uuid'];
    $change_record = [
      'changes' => [
        ['rev' => $sequence['rev']],
      ],
      'id' => $uuid,
    ];
    if (isset($sequence['seq'])) {
      $change_record['seq'] = $sequence['seq'];
    }
    if ($sequence['deleted']) {
      $change_record['deleted'] = TRUE;
    }

    // Include the document.
    if ($this->includeDocs == TRUE) {
      $change_record['doc'] = $this->serializer->normalize($entity);
    }

    return $change_record;
  }

  protected function buildAdditionalChangeRecord(ContentEntityInterface $entity) {
    // We build a slim version of the sequence built in SequenceIndex.
    // We omit the sequence id ('seq') here.
    $sequence = [
      'entity_uuid' => $entity->uuid(),
      'rev' => $entity->_rev->value,
      'deleted' => $entity->_deleted->value,
    ];

    return $this->buildChangeRecord($sequence, $entity);
  }

  /**
   * Returns the ids of all hierarchically referenced entities.
   *
   * @param $entity
   */
  protected function addEntityFieldReferences(ContentEntityInterface $entity, $additional_changes) {
    // We filter certain base fields.
    $prohibited_field_ids = ['type', 'uid', 'revision_uid'];
    $field_definitions = array_filter($entity->getFieldDefinitions(), function($key) use ($prohibited_field_ids) {
      return !in_array($key, $prohibited_field_ids);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($field_definitions as $key => $field_definition) {
      if ($field_definition->getType() == 'entity_reference') {
        if (!$entity->{$key}->isEmpty()) {
          // Add the referenced entity itself.
          $referenced_entity_id = $entity->{$key}->target_id;

          if (array_key_exists($referenced_entity_id, $additional_changes)) {
            // We already resolved the entity in another field.
            continue;
          }

          // Determine if the referenced entity has to be checked too.
          /** @var EntityInterface $target_entity */
          $target_entity = $entity->{$key}->entity;
          $target_entity_type = $target_entity->getEntityType();
          if (!$target_entity) {
            continue;
          }

          if ($entity->bundle() == 'article') {
            $test = 'DEBUG';
          }

          // If the target entity type is fieldable we have to check it.
          if ($target_entity_type->entityClassImplements("\Drupal\Core\Entity\ContentEntityInterface")) {
            // We only add entry if entity available.
            $additional_changes[$target_entity_type->id()][$referenced_entity_id] = $target_entity;
            $additional_changes = $this->addEntityFieldReferences($target_entity, $additional_changes);
          }
        }
      }
    }

    return $additional_changes;
  }

}
