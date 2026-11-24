<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures an application's workflow status belongs to its own workspace group.
 *
 * Applied to application.field_workflow_status. Rejects references to a
 * workflow_status owned by a different jurisdiction or organisation,
 * preventing cross-workspace status leakage.
 *
 * @Constraint(
 *   id = "ApplicationStatusInGroup",
 *   label = @Translation("Application status belongs to its group", context = "Validation"),
 * )
 */
class ApplicationStatusInGroupConstraint extends Constraint {

  /**
   * Message shown when the status belongs to another group.
   */
  public string $message = 'The selected workflow status does not belong to this application\'s workspace.';

  /**
   * Message shown when the application has no workflow workspace scope.
   */
  public string $missingScopeMessage = 'The selected workflow status requires an application workspace scope.';

  /**
   * Message shown when no status is set and no default can be resolved.
   */
  public string $requiredMessage = 'Applications require a workspace default workflow status.';

}
