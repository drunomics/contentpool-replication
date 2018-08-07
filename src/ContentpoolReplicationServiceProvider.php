<?php

namespace Drupal\contentpool_replication;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the replication changes factory.
 */
class ContentpoolReplicationServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('replication.changes_factory');
    $definition->setClass('Drupal\contentpool_replication\ResolvedChangesFactory');
  }

}
