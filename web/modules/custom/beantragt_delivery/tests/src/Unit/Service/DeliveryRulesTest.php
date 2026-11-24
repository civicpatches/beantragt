<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Service\DeliveryRules;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Effective route per provided response: every branch and their order.
 */
#[Group('beantragt_delivery')]
final class DeliveryRulesTest extends UnitTestCase {

  /**
   * First match wins.
   */
  #[DataProvider('routes')]
  public function testEffectiveRoute(string $chosen, bool $decision, bool $bulk, bool $postbox, string $route, ?string $reason): void {
    self::assertSame(['route' => $route, 'reason' => $reason], DeliveryRules::effectiveRoute($chosen, $decision, $bulk, $postbox));
  }

  /**
   * Chosen route, decision, bulk, postbox available, expected route, reason.
   */
  public static function routes(): array {
    return [
      'postal chosen on bulk keeps the citizen reason' => ['postal', TRUE, TRUE, FALSE, 'postal', 'chosen_postal'],
      'postal communication' => ['postal', FALSE, FALSE, FALSE, 'postal', 'chosen_postal'],
      'bulk portal decision' => ['portal', TRUE, TRUE, FALSE, 'postal', 'no_digital_option'],
      'bulk portal communication' => ['portal', FALSE, TRUE, FALSE, 'postal', 'no_digital_option'],
      'bulk bundid before postbox' => ['bundid', TRUE, TRUE, FALSE, 'postal', 'no_digital_option'],
      'bundid unavailable decision' => ['bundid', TRUE, FALSE, FALSE, 'postal', 'postbox_unavailable'],
      'bundid unavailable communication' => ['bundid', FALSE, FALSE, FALSE, 'postal', 'postbox_unavailable'],
      'bundid available decision' => ['bundid', TRUE, FALSE, TRUE, 'postal', 'decision_postal_only'],
      'bundid available communication' => ['bundid', FALSE, FALSE, TRUE, 'bundid', NULL],
      'portal decision' => ['portal', TRUE, FALSE, FALSE, 'postal', 'decision_postal_only'],
      'portal communication' => ['portal', FALSE, FALSE, FALSE, 'portal', NULL],
      'empty route is portal' => ['', FALSE, FALSE, FALSE, 'portal', NULL],
      'empty route decision' => ['', TRUE, FALSE, FALSE, 'postal', 'decision_postal_only'],
      'unknown route is portal' => ['fax', FALSE, FALSE, TRUE, 'portal', NULL],
    ];
  }

  /**
   * Only a filing receipt is no decision; no outcome fails closed.
   */
  public function testIsDecisionResponse(): void {
    self::assertTrue(DeliveryRules::isDecisionResponse(NULL));
    self::assertTrue(DeliveryRules::isDecisionResponse('approved'));
    self::assertTrue(DeliveryRules::isDecisionResponse('rejected'));
    self::assertFalse(DeliveryRules::isDecisionResponse('filed'));
  }

  /**
   * Every reason the rules can return is listed in REASONS.
   */
  public function testReasonsAreListed(): void {
    foreach (self::routes() as $case) {
      if ($case[5] !== NULL) {
        self::assertContains($case[5], DeliveryRules::REASONS);
      }
    }
  }

}
