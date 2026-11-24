<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Resolves an application's per-organisation workflow status data.
 */
final class WorkflowStatusResolver {

  /**
   * Resolves the workflow status machine name from field_workflow_status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   The application entity or revision.
   * @param string $fallback
   *   The fallback machine name when no status entity is referenced.
   *
   * @return string
   *   The workflow status machine name.
   */
  public static function machineName(EntityInterface $application, string $fallback = 'new'): string {
    $status = self::statusEntity($application);
    if (!$status instanceof FieldableEntityInterface || !$status->hasField('field_machine_name')) {
      return $fallback;
    }

    $machine_name = (string) (self::fieldValue($status, 'field_machine_name') ?? '');
    return $machine_name !== '' ? $machine_name : $fallback;
  }

  /**
   * Checks whether the referenced workflow status is terminal.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   The application entity or revision.
   * @param string[] $legacy_terminal_machine_names
   *   Fallback terminal machine names for legacy or missing status references.
   *
   * @return bool
   *   TRUE when the referenced status is marked terminal.
   */
  public static function isTerminal(
    EntityInterface $application,
    array $legacy_terminal_machine_names = ['approved', 'rejected'],
  ): bool {
    $status = self::statusEntity($application);
    if ($status instanceof FieldableEntityInterface && $status->hasField('field_is_terminal')) {
      return (bool) self::fieldValue($status, 'field_is_terminal');
    }

    $machine_name = self::machineName($application, '');
    if ($machine_name !== '' && in_array($machine_name, $legacy_terminal_machine_names, TRUE)) {
      return TRUE;
    }

    // Missing status metadata is GDPR-sensitive: anonymizing terminal-looking
    // records is safer than hard-deleting an audit trail.
    return TRUE;
  }

  /**
   * Gets the referenced workflow_status entity, if available.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   The application entity or revision.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The referenced status entity, or NULL.
   */
  private static function statusEntity(EntityInterface $application): ?EntityInterface {
    if (!$application instanceof FieldableEntityInterface
      || !$application->hasField('field_workflow_status')
      || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }

    $status = $application->get('field_workflow_status')->entity;
    return $status instanceof EntityInterface ? $status : NULL;
  }

  /**
   * Reads a scalar field's first value from a fieldable entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return mixed
   *   The field value, or NULL when absent.
   */
  private static function fieldValue(FieldableEntityInterface $entity, string $field_name): mixed {
    $values = $entity->get($field_name)->getValue();
    return is_array($values) ? ($values[0]['value'] ?? NULL) : NULL;
  }

}
