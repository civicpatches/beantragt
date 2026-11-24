<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\TransferGateInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\TransferGates;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Verifies gate ordering and the different transaction boundaries of hooks.
 */
#[Group('beantragt_delivery')]
final class TransferGatesTest extends UnitTestCase {

  /**
   * First refusal wins; planning failures are isolated per observer.
   */
  public function testCompositeOrderingAndObserverIsolation(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(self::anything(), ['@class' => \RuntimeException::class]);
    $gates = new TransferGates($logger);
    self::assertNull($gates->planningRefusal($application, 'staff', 0));
    self::assertNull($gates->approvalRefusal($application, [], 7));
    $first = $this->createMock(TransferGateInterface::class);
    $first->method('planningRefusal')->willReturn('integration.handover_status_required');
    $first->method('approvalRefusal')->willReturn('integration.four_eyes_required');
    $first->method('onPlanning')->willThrowException(new \RuntimeException('private data'));
    $first->expects(self::once())->method('onApproved');
    $second = $this->createMock(TransferGateInterface::class);
    $second->expects(self::never())->method('planningRefusal');
    $second->expects(self::never())->method('approvalRefusal');
    $second->expects(self::once())->method('onPlanning');
    $second->expects(self::once())->method('onApproved');
    $gates->addGate($first);
    $gates->addGate($second);
    self::assertSame('integration.handover_status_required', $gates->planningRefusal($application, 'staff', 0));
    self::assertSame('integration.four_eyes_required', $gates->approvalRefusal($application, [], 7));
    $gates->onPlanning($application, NULL, 7, 'integration.handover_agent_stopped');
    $gates->onApproved($application, [], 7);
  }

  /**
   * Every approval observer runs before the first failure rolls approval back.
   */
  public function testApprovalObserversAllRunBeforeRethrowing(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(self::anything(), ['@class' => \RuntimeException::class]);
    $gates = new TransferGates($logger);
    $application = $this->createMock(ApplicationInterface::class);
    $first = $this->createMock(TransferGateInterface::class);
    $failure = new \RuntimeException('first failure');
    $first->expects(self::once())->method('onApproved')->willThrowException($failure);
    $second = $this->createMock(TransferGateInterface::class);
    $second->expects(self::once())->method('onApproved')->willThrowException(new \LogicException('second failure'));
    $third = $this->createMock(TransferGateInterface::class);
    $third->expects(self::once())->method('onApproved');
    $gates->addGate($first);
    $gates->addGate($second);
    $gates->addGate($third);
    try {
      $gates->onApproved($application, [], 7);
      self::fail('The approval observer failure was swallowed.');
    }
    catch (IntegrationException $exception) {
      self::assertSame('integration.internal_error', $exception->errorCode);
      self::assertSame(500, $exception->status);
      self::assertNull($exception->getPrevious());
      self::assertSame('integration.internal_error', $exception->getMessage());
    }
  }

}
