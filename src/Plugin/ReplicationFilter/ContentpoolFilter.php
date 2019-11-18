<?php

namespace Drupal\contentpool_replication\Plugin\ReplicationFilter;

use drunomics\ServiceUtils\Core\Entity\EntityTypeManagerTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\replication\Plugin\ReplicationFilter\EntityTypeFilter;

/**
 * Provides a filter based on entity type.
 *
 * Use the configuration "types" which is an array of values in the format
 * "{entity_type_id}.{bundle}".
 *
 * @ReplicationFilter(
 *   id = "contentpool",
 *   label = @Translation("Filter by types required for contentpool"),
 *   description = @Translation("Replicate only entities that match the
 *   contentpool data model.")
 * )
 *
 * This class supports the ReplicationFilterValueProviderInterface but does
 * not implement it to avoid a dependency on multiversion_sequence_filter.
 */
class ContentpoolFilter extends EntityTypeFilter {

  use EntityTypeManagerTrait;

  /**
   * Static cache of matching UUIDS.
   *
   * @var array
   */
  protected $matchingIds = [];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // An array of entity types and bundles to replicate - all entities.
      // e.g. "taxonomy_term.channel"
      'types' => [],
      // An array of entity types and bundles to replicate when filters match.
      // e.g. "node.article"
      'filtered_types' => [],
      // An array with additional filter by term-reference field to apply for
      // all entities matching the 'types' filter.
      // Entries are keyed by field name and contain multiple term UUIDs.
      // E.g.:
      // "field_channel" => [
      //   "5f84eca9-b623-46a7-8579-ca5532585823",
      //   "02c8cbd9-15b7-4231-b9ef-46c1ef37b233",
      // ],
      // At least one filters must match for an entity to be replicated (OR),
      // while for each field at least one term must match (OR).
      'filter' => [],
    ];
  }

  /**
   * Implements \Drupal\multiversion_sequence_filter\ReplicationFilterValueProviderInterface::providesFilterValues().
   */
  public function providesFilterValues() {
    return TRUE;
  }

  /**
   * Implements \Drupal\multiversion_sequence_filter\ReplicationFilterValueProviderInterface::getUnfilteredTypes().
   */
  public function getUnfilteredTypes() {
    $config = $this->getConfiguration();
    return $config['types'];
  }

  /**
   * Implements \Drupal\multiversion_sequence_filter\ReplicationFilterValueProviderInterface::providesFilterValues().
   */
  public function getFilteredTypes() {
    $config = $this->getConfiguration();
    return $config['filtered_types'];
  }

  /**
   * Implements \Drupal\multiversion_sequence_filter\ReplicationFilterValueProviderInterface::deriveFilterValues().
   *
   * We provide filter values in the form of { field_name }:{ term_id }.
   */
  public function deriveFilterValues(EntityInterface $entity) {
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $values = [];
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() != 'entity_reference' || $definition->getFieldStorageDefinition()
        ->getSetting('target_type') != 'taxonomy_term') {
        continue;
      }
      foreach ($entity->get($field_name) as $item) {
        if ($item->target_id) {
          $values[] = $field_name . ':' . $item->target_id;
        }
      }
    }
    return $values;
  }

  /**
   * Implements \Drupal\multiversion_sequence_filter\ReplicationFilterValueProviderInterface::getFilterValues().
   */
  public function getFilterValues() {
    $config = $this->getConfiguration();
    $values = [];
    foreach ($config['filter'] as $field_name => $uuids) {
      foreach ($this->getMatchingTermIds($uuids) as $term_id) {
        $values[] = $field_name . ':' . $term_id;
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function filter(EntityInterface $entity) {
    // This method is used without multiversion_sequence_filter only.
    $config = $this->getConfiguration();
    // Replicate the matching logic of
    // \Drupal\multiversion_sequence_filter\FilteredSequenceIndex here, i.e.
    // add a trailing point for being able to match with substrings.
    $type = "{$entity->getEntityTypeId()}.{$entity->bundle()}" . '.';

    foreach ($config['types'] as $possible_type) {
      if (strpos($type, $possible_type . '.') === 0) {
        return TRUE;
      }
    }

    // If no type matches, a filtered type must match so we can check filters.
    $match = FALSE;
    foreach ($config['filtered_types'] as $possible_type) {
      if (strpos($type, $possible_type . '.') === 0) {
        $match = TRUE;
        break;
      }
    }

    if ($match) {
      // Return true if a configured filter value can be found.
      return (bool) array_intersect($this->deriveFilterValues($entity), $this->getFilterValues());
    }
    else {
      return FALSE;
    }
  }

  /**
   * Gets an array of term IDs that match for the given terms.
   *
   * Implements hierarchic matching by adding in all children of the given
   * terms.
   *
   * @param int[] $uuids
   *   An array term UUIDs for the terms to filter.
   *
   * @return string[]
   *   An array of matching term IDs, where the keys and values are term IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getMatchingTermIds(array $uuids) {
    $key = implode(':', $uuids);
    if (!isset($this->matchingIds[$key])) {
      $this->matchingIds[$key] = [];
      $terms = $this->getEntityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['uuid' => $uuids]);
      if (count($uuids) != count($terms)) {
        $not_found = $uuids;
        // Detect not found items.
        foreach ($terms as $term) {
          if (($key = array_search($term->uuid(), $not_found)) !== FALSE) {
            unset($not_found[$key]);
          }
        }
        throw new \InvalidArgumentException("Invalid filter values given. Terms with uuid " . implode(', ', $not_found) . " were selected but not found.");
      }
      /* @var \Drupal\taxonomy\TermInterface[] $terms */
      foreach ($terms as $term) {
        // Add the term itself to the results.
        $this->matchingIds[$key][$term->id()] = $term->id();
        // Add the children.
        $children = $this->getEntityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadTree($term->getVocabularyId(), $term->id(), NULL, FALSE);
        foreach ($children as $child) {
          $this->matchingIds[$key][$child->tid] = $child->tid;
        }
      }
    }
    return $this->matchingIds[$key];
  }

}
