<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\beantragt_notification\Service\ResubmissionReminderResolver;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests resubmission reminder policy resolution.
 */
#[CoversClass(ResubmissionReminderResolver::class)]
#[Group('beantragt_notification')]
final class ResubmissionReminderResolverTest extends UnitTestCase {

  /**
   * Schema policy values override jurisdiction and global defaults.
   */
  public function testSchemaPolicyBeatsJurisdictionAndGlobalDefaults(): void {
    $policy = ResubmissionReminderResolver::policyFromSources(
      [
        'resubmission' => [
          'reminderDays' => '14',
          'reminderScope' => 'user',
        ],
      ],
      [
        'resubmission' => [
          'reminderDays' => 30,
          'reminderScope' => 'org',
        ],
      ],
      42,
      'org',
    );

    $this->assertSame(14, $policy['days']);
    $this->assertSame('user', $policy['scope']);
    $this->assertSame('schema', $policy['daysSource']);
    $this->assertSame('schema', $policy['scopeSource']);
  }

  /**
   * Days and scope fall through independently across the policy chain.
   */
  public function testPartialSchemaOverrideKeepsJurisdictionScope(): void {
    $policy = ResubmissionReminderResolver::policyFromSources(
      ['resubmission' => ['reminderDays' => 21]],
      ['resubmission' => ['reminderScope' => 'user']],
      42,
      'org',
    );

    $this->assertSame(21, $policy['days']);
    $this->assertSame('user', $policy['scope']);
    $this->assertSame('schema', $policy['daysSource']);
    $this->assertSame('jurisdiction', $policy['scopeSource']);
  }

  /**
   * Invalid source values fall back to global or built-in defaults.
   */
  public function testInvalidValuesFallBackSafely(): void {
    $policy = ResubmissionReminderResolver::policyFromSources(
      ['resubmission' => ['reminderDays' => 0, 'reminderScope' => 'team']],
      ['resubmission' => ['reminderDays' => 366, 'reminderScope' => 'invalid']],
      'oops',
      'elsewhere',
    );

    $this->assertSame(42, $policy['days']);
    $this->assertSame('org', $policy['scope']);
    $this->assertSame('default', $policy['daysSource']);
    $this->assertSame('default', $policy['scopeSource']);
  }

}
