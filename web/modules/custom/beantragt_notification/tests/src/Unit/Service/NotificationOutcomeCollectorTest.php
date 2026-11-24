<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\beantragt_notification\Service\NotificationOutcomeCollector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests request-scoped notification outcome collection.
 */
#[CoversClass(NotificationOutcomeCollector::class)]
#[Group('beantragt_notification')]
final class NotificationOutcomeCollectorTest extends UnitTestCase {

  /**
   * Recording preserves order and draining clears the collector.
   */
  public function testRecordDrainAndClear(): void {
    $collector = new NotificationOutcomeCollector();
    $first = [
      'key' => 'assignment_alert',
      'recipient_kind' => 'person',
      'status' => 'sent',
    ];
    $second = [
      'key' => 'delegation_alert',
      'recipient_kind' => NULL,
      'status' => 'skipped_no_recipient',
    ];

    $collector->record($first);
    $collector->record($second);

    $this->assertSame([$first, $second], $collector->drain());
    $this->assertSame([], $collector->drain());
  }

}
