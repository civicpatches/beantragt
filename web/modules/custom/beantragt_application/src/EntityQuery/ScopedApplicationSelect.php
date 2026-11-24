<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\EntityQuery;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * The compiled scope of the applications one staff account may see.
 *
 * The scope is compiled once per request; every aggregate works on its own
 * clone, so the conditions are never rebuilt and never shared between
 * aggregates.
 */
final class ScopedApplicationSelect {

  /**
   * Constructs the compiled scope.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *   Compiled select without fields, never executed itself.
   * @param array<string, string> $columns
   *   Escaped SQL columns keyed by entity field name.
   */
  public function __construct(
    private readonly SelectInterface $select,
    private readonly array $columns,
  ) {}

  /**
   * Returns a fresh copy of the scoped select to add fields to.
   */
  public function newSelect(): SelectInterface {
    return clone $this->select;
  }

  /**
   * Returns the escaped SQL column of a resolved entity field.
   *
   * @throws \LogicException
   *   When the field was not resolved at compile time.
   */
  public function column(string $field): string {
    if (!isset($this->columns[$field])) {
      throw new \LogicException(sprintf('Column %s was not resolved for the scoped application select.', $field));
    }
    return $this->columns[$field];
  }

}
