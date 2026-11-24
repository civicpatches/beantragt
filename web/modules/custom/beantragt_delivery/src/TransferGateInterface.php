<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Extends transfer policy without depending on the planner or its consumers.
 */
interface TransferGateInterface {

  /**
   * Returns a refusal code for status or staff planning, without side effects.
   */
  public function planningRefusal(ApplicationInterface $a, string $trigger, int $actorUid): ?string;

  /**
   * Observes status planning; implementations must never throw.
   */
  public function onPlanning(ApplicationInterface $a, ?array $row, int $actorUid, ?string $refusal): void;

  /**
   * Returns a refusal code for this approver, without side effects.
   */
  public function approvalRefusal(ApplicationInterface $a, array $row, int $approverUid): ?string;

  /**
   * Records approval inside its transaction; exceptions roll it back.
   */
  public function onApproved(ApplicationInterface $a, array $row, int $approverUid): void;

}
