<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\EntityQuery;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\Query\Sql\Query;

/**
 * Compiles an entity query into a select for scoped aggregate statistics.
 *
 * The conditions come from the same callers as the staff list, above all
 * TenantScopedEntityQuery. Each matching application must contribute exactly
 * one row to the aggregate. Core resolves single-valued base fields directly
 * on the base table. A join could multiply rows, so compilation and column
 * resolution both reject it.
 */
final class ScopedAggregateSelectQuery extends Query {

  /**
   * Whether compileSelect() already ran on this object.
   */
  private bool $compiled = FALSE;

  /**
   * Runs the entity query alter hooks and compiles the conditions.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The select with the scoped base rows and no aggregate fields yet.
   *
   * @throws \LogicException
   *   When called twice, or when a condition needs a table other than the
   *   entity base table.
   */
  public function compileSelect(): SelectInterface {
    if ($this->compiled) {
      throw new \LogicException('A scoped aggregate query compiles once; build a new query object.');
    }
    $this->compiled = TRUE;
    $this->alter();
    $this->prepare();
    $this->compile();
    $this->assertBaseTableOnly();
    return $this->sqlQuery;
  }

  /**
   * Returns the escaped SQL column of an entity field in the compiled select.
   *
   * The base table columns used here are not translatable.
   *
   * @throws \LogicException
   *   When the select is not compiled or the field is not a base table column.
   */
  public function sqlColumn(string $field): string {
    if (!$this->compiled) {
      throw new \LogicException('Compile the scoped aggregate query before resolving columns.');
    }
    $column = $this->getSqlField($field, '');
    $this->assertBaseTableOnly();
    return $this->connection->escapeField($column);
  }

  /**
   * Fails closed unless the select reads only the entity base table.
   *
   * @throws \LogicException
   *   When the select requires a join.
   */
  private function assertBaseTableOnly(): void {
    if (array_keys($this->sqlQuery->getTables()) !== ['base_table']) {
      throw new \LogicException(sprintf(
        'Scoped aggregate queries may only read single-valued columns of the %s base table.',
        $this->entityType->getBaseTable(),
      ));
    }
  }

}
