<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Denies generic entity access to decision templates.
 *
 * Templates are read and changed only through the scoped docgen staff API,
 * which checks permissions and group membership itself. Generic paths (entity
 * API, private file downloads through file usage) stay closed, except for
 * site administrators.
 */
final class DocgenTemplateAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return AccessResult::forbidden('Decision templates are served by the docgen staff API only.')->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::forbidden('Decision templates are created by the docgen staff API only.')->cachePerPermissions();
  }

}
