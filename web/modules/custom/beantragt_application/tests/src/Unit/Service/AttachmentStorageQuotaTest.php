<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests tenant attachment storage quota helpers.
 */
#[CoversClass(AttachmentStorageQuota::class)]
#[Group('beantragt_application')]
final class AttachmentStorageQuotaTest extends UnitTestCase {

  /**
   * Stored upload policies are normalized fail-closed into bounded integers.
   */
  public function testNormalizePolicy(): void {
    $this->assertSame([
      'maxStorageBytes' => 0,
      'warningThresholdPercent' => 80,
    ], AttachmentStorageQuota::normalizePolicy([]));

    $this->assertSame([
      'maxStorageBytes' => 104857600,
      'warningThresholdPercent' => 75,
    ], AttachmentStorageQuota::normalizePolicy([
      'maxStorageBytes' => '104857600',
      'warningThresholdPercent' => '75',
    ]));

    $this->assertSame([
      'maxStorageBytes' => 0,
      'warningThresholdPercent' => 80,
    ], AttachmentStorageQuota::normalizePolicy([
      'maxStorageBytes' => -1,
      'warningThresholdPercent' => 150,
    ]));
  }

  /**
   * Usage summaries expose durable status fields for the admin UI.
   */
  public function testSummaryFromUsage(): void {
    $quota = new AttachmentStorageQuota($this->createMock(EntityTypeManagerInterface::class));

    $this->assertSame([
      'maxStorageBytes' => 100,
      'warningThresholdPercent' => 75,
      'warningStorageBytes' => 75,
      'usedStorageBytes' => 80,
      'attachmentCount' => 3,
      'quotaEnabled' => TRUE,
      'usagePercent' => 80,
      'quotaStatus' => 'warning',
    ], $quota->summaryFromUsage(80, 3, [
      'maxStorageBytes' => 100,
      'warningThresholdPercent' => 75,
    ]));

    $this->assertSame('disabled', $quota->summaryFromUsage(80, 3, [
      'maxStorageBytes' => 0,
      'warningThresholdPercent' => 80,
    ])['quotaStatus']);
  }

  /**
   * Invalid jurisdiction identifiers never reach aggregate storage queries.
   */
  public function testUsageSkipsInvalidJurisdiction(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('select');
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn(0);

    $quota = new AttachmentStorageQuota($entity_type_manager, NULL, $database);

    $this->assertSame([
      'usedStorageBytes' => 0,
      'attachmentCount' => 0,
    ], $quota->usageForJurisdiction($jurisdiction));
  }

}
