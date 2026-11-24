<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Staff case scope for the detail and protected-data routes.
 *
 * Moved out of ApplicationDataController::protectedDataAccess() so callers
 * inside a route (the Nachreichung case view) can ask it as well.
 */
class ProtectedDataGate {

  /**
   * Constructs the gate.
   */
  public function __construct(private readonly ApplicationCaseScope $applicationCaseScope) {}

  /**
   * TRUE when the account may reveal protected values of the application.
   *
   * Does not include application view access or the permission "view
   * protected application data"; callers combine them.
   */
  public function allows(ApplicationInterface $application, AccountInterface $account): bool {
    return $this->access($application, $account)->isAllowed();
  }

  /**
   * Checks the permission for staff findings and protected file fields.
   */
  public static function canReadProtectedAttachmentData(AccountInterface $account): bool {
    return $account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')
      || $account->hasPermission('view protected application data');
  }

  /**
   * The access result of the route requirement.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->applicationCaseScope->access($application, $account)->setCacheMaxAge(0);
  }

}
