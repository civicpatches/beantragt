<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Checks whether a user can issue workspace invites for a parent jurisdiction.
 */
interface WorkspaceInviteAccessInterface {

  /**
   * Returns TRUE when the issuer can invite below the target parent.
   */
  public function canIssue(UserInterface $issuer, GroupInterface $targetParent): bool;

  /**
   * Returns the issuer scope jurisdiction ID, or NULL for global scope.
   */
  public function issuerScopeId(UserInterface $issuer): ?int;

}
