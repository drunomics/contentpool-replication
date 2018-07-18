<?php

namespace Drupal\contentpool_replication\Changes;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\multiversion\Entity\Index\SequenceIndexInterface;
use Drupal\multiversion\Entity\WorkspaceInterface;
use Drupal\replication\Changes\Changes;
use Drupal\replication\Changes\ChangesInterface;
use Drupal\replication\Plugin\ReplicationFilterManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

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
    foreach ($sequences as $sequence) {
      if (!empty($sequence['local']) || !empty($sequence['is_stub'])) {
        continue;
      }

      // When we have the since parameter set, we should exclude the value with
      // that sequence from the results.
      if ($this->since > 0 && $sequence['seq'] == $this->since) {
       continue;
      }

      // Get the document.
      $revision = NULL;
      if ($this->includeDocs == TRUE || $filter !== NULL) {
        /** @var \Drupal\multiversion\Entity\Storage\ContentEntityStorageInterface $storage */
        $storage = $this->entityTypeManager->getStorage($sequence['entity_type_id']);
        $storage->useWorkspace($this->workspaceId);
        $revision = $storage->loadRevision($sequence['revision_id']);
        $storage->useWorkspace(NULL);
      }

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
      $changes[$uuid] = [
        'changes' => [
          ['rev' => $sequence['rev']],
        ],
        'id' => $uuid,
        'seq' => $sequence['seq'],
      ];
      if ($sequence['deleted']) {
        $changes[$uuid]['deleted'] = TRUE;
      }

      // Include the document.
      if ($this->includeDocs == TRUE) {
        $changes[$uuid]['doc'] = $this->serializer->normalize($revision);
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

}
