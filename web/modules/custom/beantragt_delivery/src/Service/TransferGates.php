<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\TransferGateInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects transfer gates; an empty collector preserves the core policy.
 */
final class TransferGates implements TransferGateInterface {

  /**
   * Registered gates, in collector order.
   *
   * @var \Drupal\beantragt_delivery\TransferGateInterface[]
   */
  private array $gates = [];

  /**
   * Constructs the collector with a logger for post-commit failures.
   */
  public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

  /**
   * Registers a gate that must not depend on the planner or document service.
   */
  public function addGate(TransferGateInterface $gate): void {
    $this->gates[] = $gate;
  }

  /**
   * {@inheritdoc}
   */
  public function planningRefusal(ApplicationInterface $a, string $trigger, int $actorUid): ?string {
    foreach ($this->gates as $gate) {
      $refusal = $gate->planningRefusal($a, $trigger, $actorUid);
      if ($refusal !== NULL) {
        return $refusal;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function onPlanning(ApplicationInterface $a, ?array $row, int $actorUid, ?string $refusal): void {
    foreach ($this->gates as $gate) {
      try {
        $gate->onPlanning($a, $row, $actorUid, $refusal);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Transfer planning observer failed: @class.', ['@class' => $exception::class]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function approvalRefusal(ApplicationInterface $a, array $row, int $approverUid): ?string {
    foreach ($this->gates as $gate) {
      $refusal = $gate->approvalRefusal($a, $row, $approverUid);
      if ($refusal !== NULL) {
        return $refusal;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function onApproved(ApplicationInterface $a, array $row, int $approverUid): void {
    $failure = NULL;
    foreach ($this->gates as $gate) {
      try {
        $gate->onApproved($a, $row, $approverUid);
      }
      catch (\Throwable $exception) {
        $failure ??= $exception;
      }
    }
    if ($failure !== NULL) {
      $this->logger->error('Transfer approval observer failed: @class.', ['@class' => $failure::class]);
      throw new IntegrationException('integration.internal_error', 500);
    }
  }

}
