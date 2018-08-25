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
 *   description = @Translation("Replicate only entities that match the contentpool data model.")
 * )
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
      // An array of entity types to replicate, filtered by below filters.
      // Referenced entities are added-in automatically.
      'types' => [],
      // An array with additional filter by term-reference field. First level
      // keys are "entity-type:bundle" and with a nested array of field filter
      // values, keyed by field name and with possible term UUIDs as values.
      // E.g.:
      // "node:article" => [
      //    "field_channel" => [
      //       "5f84eca9-b623-46a7-8579-ca5532585823",
      //       "02c8cbd9-15b7-4231-b9ef-46c1ef37b233",
      //    ],
      // ],
      // At least one filters must match for an entity to be replicated (OR),
      // while for each field at least one term must match (OR).
      'filter' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function filter(EntityInterface $entity) {
    $result = parent::filter($entity);

    if (!$result) {
      return FALSE;
    }

    // Continue filtering if we have defined filters.
    $config = $this->getConfiguration();
    $key = $entity->getEntityTypeId() . ':' . $entity->bundle();
    if (empty($config['filter'][$key])) {
      return TRUE;
    }

    // Check all filters and return TRUE as soon as one matches.
    foreach ($config['filter'][$key] as $field_name => $uuids) {
      if ($this->entityHasOneTermOf($entity, $uuids, $field_name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines whether the entity has one of the given terms.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to check.
   * @param string[] $uuids
   *   The UUIDs of the terms to check for.
   * @param $field_name
   *   The name of the term referenced field to use for checking.
   *
   * @return bool
   *   Whether the entity has one of the given terms.
   */
  protected function entityHasOneTermOf(ContentEntityInterface $entity, $uuids, $field_name) {
    $matching_ids = $this->getMatchingTermIds($uuids);
    foreach ($entity->get($field_name) as $item) {
      if (isset($matching_ids[$item->target_id])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets an array of term IDs that match for the given terms.
   *
   * Implements hierarchic matching by adding in all children of the given
   * terms.
   *
   * @param string[] $term_uuids
   *   An array term UUIDs for the terms to filter.
   *
   * @return string[]
   *   An array of matching term IDs, where the keys and values are term IDs.
   */
  protected function getMatchingTermIds(array $term_uuids) {
    $key = implode(':', $term_uuids);
    if (!isset($this->matchingIds[$key])) {
      $this->matchingIds[$key] = [];
      $terms = $this->getEntityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['uuid' => $term_uuids]);
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
