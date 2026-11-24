<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the ComputeFunction plugin attribute.
 *
 * Compute function plugins are pure, deterministic functions that derive a
 * field value from resolved argument values. They must implement
 * ComputeFunctionInterface and be placed in Plugin/ComputeFunction/.
 *
 * Example:
 * @code
 * #[ComputeFunction(
 *   id: 'approvalTier',
 *   label: new TranslatableMarkup('Approval tier by amount'),
 *   arity: 1,
 * )]
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ComputeFunction extends Plugin {

  /**
   * Constructs a ComputeFunction attribute.
   *
   * @param string $id
   *   The plugin ID (camelCase machine name, e.g. 'approvalTier').
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   Human-readable label for documentation and admin UIs.
   * @param int|null $arity
   *   (optional) The number of arguments the function expects. NULL means
   *   variadic / unbounded. Used by tooling; not enforced at runtime.
   * @param class-string|null $deriver
   *   (optional) The deriver class for derived plugins.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup|string $label = '',
    public readonly ?int $arity = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
