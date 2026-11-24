<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Extends case documents without depending on rendering or draft services.
 */
interface DocgenCaseExtensionInterface {

  /**
   * Supplies values for VariableCatalog::PROVIDED, including receipt overrides.
   *
   * @return array<string, string>
   *   System values keyed by tag.
   */
  public function systemValues(ApplicationInterface $application, AccountInterface $account): array;

  /**
   * Returns additional blockers for a draft, never an ongoing release.
   *
   * @return string[]
   *   Release blocker codes.
   */
  public function releaseBlockers(array $row): array;

  /**
   * Observes committed releases; the decider is each row's released_by.
   */
  public function released(ApplicationInterface $application, array $rows, AccountInterface $account): void;

}
