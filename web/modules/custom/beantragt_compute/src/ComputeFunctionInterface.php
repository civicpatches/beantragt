<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute;

/**
 * Interface for compute function plugins.
 *
 * Implementations must be pure and deterministic: given the same arguments
 * they must always return the same value. They must not access entities,
 * perform I/O, or produce side effects. All argument resolution (field-id
 * lookup, type coercion) happens in ComputedFieldEvaluator before compute()
 * is called.
 *
 * The return type is intentionally broad to accommodate numeric results,
 * boolean flags, and machine-value strings. Callers must document the
 * concrete return type in their plugin's PHPDoc.
 *
 * Instantiation contract: plugins are created via the DefaultPluginManager,
 * whose ContainerFactory does `new static($configuration, $plugin_id,
 * $plugin_definition)`. A plugin that needs NO services should simply extend
 * \Drupal\Core\Plugin\PluginBase and add no constructor (see ApprovalTier).
 * A plugin that genuinely needs a service (e.g. a reference-data lookup) MUST
 * implement \Drupal\Core\Plugin\ContainerFactoryPluginInterface and provide
 * `public static function create(ContainerInterface $container, array
 * $configuration, $plugin_id, $plugin_definition)` to inject it; otherwise the
 * empty $configuration array is passed where the service is expected and
 * instantiation fails with a TypeError. This is the path the future PerDiem
 * reference-data function will use.
 */
interface ComputeFunctionInterface {

  /**
   * Computes a derived field value from resolved argument values.
   *
   * @param array $args
   *   Ordered list of resolved argument values, matching the declared arity.
   *   Each value is already looked up from submitted form data; the plugin
   *   receives plain scalar values (string, int, float, bool, or null).
   * @param array $context
   *   Optional context map for future extension (e.g. locale, jurisdiction id).
   *   Plugins should ignore unknown keys for forward compatibility.
   *
   * @return string|int|float|bool|null
   *   The computed field value. For machine-value outputs (e.g. 'team_lead')
   *   the frontend is responsible for display-label mapping.
   */
  public function compute(array $args, array $context): string|int|float|bool|null;

}
