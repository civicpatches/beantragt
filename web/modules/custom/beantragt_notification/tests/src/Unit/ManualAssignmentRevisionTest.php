<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/beantragt_notification.module';

/**
 * Tests which revisions count as a manual assignment for staff mails.
 */
#[Group('beantragt_notification')]
final class ManualAssignmentRevisionTest extends UnitTestCase {

  /**
   * The assignment endpoint event and the legacy sentence both qualify.
   */
  #[DataProvider('revisionLogProvider')]
  public function testManualAssignmentRevisionDetection(string $log, bool $expected): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getRevisionLogMessage')->willReturn($log);

    $this->assertSame($expected, _beantragt_notification_is_manual_assignment_revision($application));
  }

  /**
   * Revision logs with the expected detection result.
   */
  public static function revisionLogProvider(): array {
    $envelope = static fn(array $events, string $message = ''): string => json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [],
      'events' => $events,
      'message' => $message,
    ], JSON_THROW_ON_ERROR);

    return [
      'assignment endpoint event' => [$envelope(['assignment_changed']), TRUE],
      'legacy generated sentence' => ['Application assignment changed by Erika (uid: 7).', TRUE],
      'automatic routing' => [$envelope(['assignment_sync']), FALSE],
      'staff reason quoting the sentence' => [
        $envelope(['delegated'], 'Application assignment changed by hand.'),
        FALSE,
      ],
      'empty log' => ['', FALSE],
    ];
  }

}
