<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute;

use Drupal\beantragt_compute\Attribute\ComputeFunction;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Manages discovery and instantiation of ComputeFunction plugins.
 *
 * Plugins are discovered under Plugin/ComputeFunction/ in any module,
 * identified by the #[ComputeFunction(...)] attribute, and must implement
 * ComputeFunctionInterface.
 */
class ComputeFunctionManager extends DefaultPluginManager {

  /**
   * Constructs a ComputeFunctionManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ComputeFunction',
      $namespaces,
      $module_handler,
      ComputeFunctionInterface::class,
      ComputeFunction::class,
    );

    $this->alterInfo('beantragt_compute_function');
    $this->setCacheBackend($cache_backend, 'beantragt_compute_functions');
  }

}
