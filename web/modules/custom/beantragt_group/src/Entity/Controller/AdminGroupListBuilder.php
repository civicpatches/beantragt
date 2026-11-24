<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Entity\Controller;

use Drupal\group\Entity\Controller\GroupListBuilder;

/**
 * Lists every workspace for platform-wide Drupal administrators.
 */
final class AdminGroupListBuilder extends GroupListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    if (!$this->hasPlatformAdminPermission()) {
      return parent::getEntityIds();
    }

    $query = $this->getStorage()->getQuery()->accessCheck(FALSE);
    $header = $this->buildHeader();
    $query->tableSort($header);
    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query->execute();
  }

  /**
   * Checks the restricted global workspace administration permissions.
   */
  private function hasPlatformAdminPermission(): bool {
    return $this->currentUser->hasPermission('administer group')
      || $this->currentUser->hasPermission('administer beantragt groups');
  }

}
