<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_compute\Unit\Plugin\ComputeFunction;

use Drupal\beantragt_compute\Plugin\ComputeFunction\ApprovalTier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for the ApprovalTier compute function plugin.
 *
 * @coversDefaultClass \Drupal\beantragt_compute\Plugin\ComputeFunction\ApprovalTier
 */
#[Group('beantragt_compute')]
class ApprovalTierTest extends UnitTestCase {

  /**
   * Creates a plugin instance via the PluginBase constructor signature.
   *
   * This mirrors the arguments the DefaultPluginManager ContainerFactory
   * passes: `new static($configuration, $plugin_id, $plugin_definition)`.
   * Because ApprovalTier is DI-free, no service mocking is required.
   */
  private function createPlugin(): ApprovalTier {
    return new ApprovalTier([], 'approvalTier', []);
  }

  /**
   * Data provider for boundary tests.
   *
   * @return array<string, array{0: mixed, 1: string}>
   *   Keyed by description, values are [amount, expectedTier].
   */
  public static function tierBoundaryProvider(): array {
    return [
      'just below lower boundary (499.99) -> team_lead' => [
        499.99,
        ApprovalTier::TIER_TEAM_LEAD,
      ],
      'lower boundary exactly (500) -> department_head' => [
        500,
        ApprovalTier::TIER_DEPARTMENT_HEAD,
      ],
      'upper boundary exactly (5000) -> department_head' => [
        5000,
        ApprovalTier::TIER_DEPARTMENT_HEAD,
      ],
      'just above upper boundary (5000.01) -> management' => [
        5000.01,
        ApprovalTier::TIER_MANAGEMENT,
      ],
      'zero -> team_lead' => [
        0,
        ApprovalTier::TIER_TEAM_LEAD,
      ],
      'string numeric (499.99) -> team_lead' => [
        '499.99',
        ApprovalTier::TIER_TEAM_LEAD,
      ],
      'string numeric (500) -> department_head' => [
        '500',
        ApprovalTier::TIER_DEPARTMENT_HEAD,
      ],
      'large value -> management' => [
        1000000,
        ApprovalTier::TIER_MANAGEMENT,
      ],
    ];
  }

  /**
   * Tests tier boundary resolution.
   *
   * @covers ::compute
   */
  #[DataProvider('tierBoundaryProvider')]
  public function testTierBoundaries(mixed $amount, string $expected): void {
    $plugin = $this->createPlugin();
    $result = $plugin->compute([$amount], []);
    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for non-numeric / missing input cases.
   *
   * @return array<string, array{0: mixed}>
   *   Keyed by description, values are [input].
   */
  public static function nonNumericProvider(): array {
    return [
      'null input' => [NULL],
      'empty string' => [''],
      'alphabetic string' => ['foobar'],
      'empty args array' => ['__EMPTY_ARGS__'],
      'boolean false' => [FALSE],
      'array value' => [[]],
    ];
  }

  /**
   * Tests that non-numeric/missing input defaults to the lowest tier.
   *
   * @covers ::compute
   */
  #[DataProvider('nonNumericProvider')]
  public function testNonNumericDefaultsToTeamLead(mixed $input): void {
    $plugin = $this->createPlugin();

    if ($input === '__EMPTY_ARGS__') {
      $result = $plugin->compute([], []);
    }
    else {
      $result = $plugin->compute([$input], []);
    }

    $this->assertSame(ApprovalTier::TIER_TEAM_LEAD, $result);
  }

}
