<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_compute\Unit;

use Drupal\beantragt_compute\ComputedFieldEvaluator;
use Drupal\beantragt_compute\ComputeFunctionInterface;
use Drupal\beantragt_compute\ComputeFunctionManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ComputedFieldEvaluator.
 *
 * Also covers the SECURITY INVARIANT: the evaluator always recomputes,
 * ignoring any pre-existing (client-supplied) value at the computed field id.
 *
 * @coversDefaultClass \Drupal\beantragt_compute\ComputedFieldEvaluator
 */
#[Group('beantragt_compute')]
class ComputedFieldEvaluatorTest extends UnitTestCase {

  /**
   * A minimal schema definition with one computed field (approvalTier).
   */
  private function schemaWithApprovalTier(): array {
    return [
      'sections' => [
        [
          'id' => 'section_1',
          'title' => 'Details',
          'fields' => [
            [
              'id' => 'net_amount',
              'type' => 'text',
              'label' => 'Net amount',
            ],
            [
              'id' => 'approval_tier',
              'type' => 'text',
              'label' => 'Approval tier',
              'computed' => [
                'fn' => 'approvalTier',
                'args' => ['net_amount'],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Creates a mock plugin returning a fixed value.
   */
  private function mockPlugin(string $returnValue): ComputeFunctionInterface {
    $plugin = $this->createMock(ComputeFunctionInterface::class);
    $plugin->method('compute')->willReturn($returnValue);
    return $plugin;
  }

  /**
   * Creates the evaluator with a configured mock manager.
   */
  private function createEvaluator(
    bool $hasDefinition,
    ?ComputeFunctionInterface $plugin = NULL,
  ): ComputedFieldEvaluator {
    $logger = $this->createMock(LoggerInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $manager = $this->createMock(ComputeFunctionManager::class);
    $manager->method('hasDefinition')->willReturn($hasDefinition);
    if ($plugin !== NULL) {
      $manager->method('createInstance')->willReturn($plugin);
    }

    return new ComputedFieldEvaluator($manager, $factory);
  }

  /**
   * Tests that a computed field with a known fn resolves correctly.
   *
   * @covers ::evaluate
   */
  public function testKnownFnResolvesCorrectly(): void {
    $plugin = $this->mockPlugin('department_head');
    $evaluator = $this->createEvaluator(TRUE, $plugin);

    $result = $evaluator->evaluate(
      $this->schemaWithApprovalTier(),
      ['net_amount' => '750'],
    );

    $this->assertArrayHasKey('approval_tier', $result);
    $this->assertSame('department_head', $result['approval_tier']);
  }

  /**
   * Tests that args are passed in declared order.
   *
   * @covers ::evaluate
   */
  public function testArgsPassedInOrder(): void {
    $capturedArgs = [];
    $plugin = $this->createMock(ComputeFunctionInterface::class);
    $plugin->method('compute')->willReturnCallback(
      function (array $args) use (&$capturedArgs): string {
        $capturedArgs = $args;
        return 'team_lead';
      }
    );

    $evaluator = $this->createEvaluator(TRUE, $plugin);
    $evaluator->evaluate(
      $this->schemaWithApprovalTier(),
      ['net_amount' => '100'],
    );

    $this->assertSame(['100'], $capturedArgs);
  }

  /**
   * Tests that an unknown fn is skipped without throwing.
   *
   * @covers ::evaluate
   */
  public function testUnknownFnIsSkipped(): void {
    $logger = $this->createMock(LoggerInterface::class);
    // Expect a warning log for the unknown fn.
    $logger->expects($this->once())->method('warning');

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $manager = $this->createMock(ComputeFunctionManager::class);
    $manager->method('hasDefinition')->willReturn(FALSE);

    $evaluator = new ComputedFieldEvaluator($manager, $factory);

    $result = $evaluator->evaluate(
      $this->schemaWithApprovalTier(),
      ['net_amount' => '750'],
    );

    // The computed field must be absent from results (skipped, not thrown).
    $this->assertArrayNotHasKey('approval_tier', $result);
  }

  /**
   * Tests that computed-on-computed chaining is skipped with a warning.
   *
   * @covers ::evaluate
   */
  public function testComputedOnComputedIsSkipped(): void {
    $schema = [
      'sections' => [
        [
          'id' => 'section_1',
          'title' => 'Details',
          'fields' => [
            // Field A is computed.
            [
              'id' => 'field_a',
              'type' => 'text',
              'label' => 'Field A',
              'computed' => ['fn' => 'approvalTier', 'args' => ['net_amount']],
            ],
            // Field B tries to use field_a (another computed field) as arg.
            [
              'id' => 'field_b',
              'type' => 'text',
              'label' => 'Field B',
              'computed' => ['fn' => 'approvalTier', 'args' => ['field_a']],
            ],
            [
              'id' => 'net_amount',
              'type' => 'text',
              'label' => 'Net amount',
            ],
          ],
        ],
      ],
    ];

    $logger = $this->createMock(LoggerInterface::class);
    // Expect at least one warning (for the chaining skip on field_b).
    $logger->expects($this->atLeastOnce())->method('warning');

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $plugin = $this->mockPlugin('team_lead');
    $manager = $this->createMock(ComputeFunctionManager::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $manager->method('createInstance')->willReturn($plugin);

    $evaluator = new ComputedFieldEvaluator($manager, $factory);

    $result = $evaluator->evaluate($schema, ['net_amount' => '100']);

    // field_a resolves normally; field_b is skipped.
    $this->assertArrayHasKey('field_a', $result);
    $this->assertArrayNotHasKey('field_b', $result);
  }

  /**
   * SECURITY INVARIANT: evaluator ignores pre-existing (client-spoofed) values.
   *
   * Even if $submittedValues already contains a value at the computed field id
   * (e.g. a client that tried to spoof 'management'), evaluate() must return
   * the recomputed authoritative value. The presave hook then overwrites the
   * stored value with this result.
   *
   * @covers ::evaluate
   */
  public function testSecurityInvariantOverwritesSpoofedValue(): void {
    // Plugin always returns 'team_lead' (the correct result for amount 100).
    $plugin = $this->mockPlugin('team_lead');
    $evaluator = $this->createEvaluator(TRUE, $plugin);

    // Submitted values contain a spoofed 'management' for the computed field.
    $submittedValues = [
      'net_amount' => '100',
    // client-supplied spoof.
      'approval_tier' => 'management',
    ];

    $result = $evaluator->evaluate($this->schemaWithApprovalTier(), $submittedValues);

    // evaluate() must return the recomputed value, not the spoofed one.
    $this->assertSame('team_lead', $result['approval_tier']);
    $this->assertNotSame('management', $result['approval_tier']);
  }

  /**
   * Tests that fields without a computed spec are ignored.
   *
   * @covers ::evaluate
   */
  public function testNonComputedFieldsAreIgnored(): void {
    $schema = [
      'sections' => [
        [
          'id' => 'section_1',
          'title' => 'Details',
          'fields' => [
            ['id' => 'plain_field', 'type' => 'text', 'label' => 'Plain'],
          ],
        ],
      ],
    ];

    $evaluator = $this->createEvaluator(FALSE);
    $result = $evaluator->evaluate($schema, ['plain_field' => 'hello']);

    $this->assertSame([], $result);
  }

  /**
   * Tests that a missing arg value is passed as NULL (optional field case).
   *
   * @covers ::evaluate
   */
  public function testMissingArgValuePassedAsNull(): void {
    $capturedArgs = [];
    $plugin = $this->createMock(ComputeFunctionInterface::class);
    $plugin->method('compute')->willReturnCallback(
      function (array $args) use (&$capturedArgs): string {
        $capturedArgs = $args;
        return 'team_lead';
      }
    );

    $evaluator = $this->createEvaluator(TRUE, $plugin);
    // net_amount is not in submitted values.
    $evaluator->evaluate($this->schemaWithApprovalTier(), []);

    $this->assertSame([NULL], $capturedArgs);
  }

  /**
   * Tests that computedFieldIds() returns ALL declared computed field ids.
   *
   * Includes skipped fields (unknown fn, computed-on-computed): they are still
   * declared computed and MUST be enforced by the presave hook.
   *
   * @covers ::computedFieldIds
   */
  public function testComputedFieldIdsIncludesSkippedFields(): void {
    $schema = [
      'sections' => [
        [
          'id' => 'section_1',
          'title' => 'Details',
          'fields' => [
            ['id' => 'net_amount', 'type' => 'text', 'label' => 'Amount'],
            [
              'id' => 'good_tier',
              'type' => 'text',
              'label' => 'Good',
              'computed' => ['fn' => 'approvalTier', 'args' => ['net_amount']],
            ],
            [
              'id' => 'bad_tier',
              'type' => 'text',
              'label' => 'Bad',
              // Unknown fn: this field will be skipped at evaluate-time, but it
              // is still a declared computed field id.
              'computed' => ['fn' => 'doesNotExist', 'args' => ['net_amount']],
            ],
          ],
        ],
      ],
    ];

    $evaluator = $this->createEvaluator(TRUE);
    $ids = $evaluator->computedFieldIds($schema);

    $this->assertContains('good_tier', $ids);
    $this->assertContains('bad_tier', $ids);
    $this->assertNotContains('net_amount', $ids);
  }

  /**
   * SECURITY INVARIANT (Bug B): a SKIPPED computed field is stripped to null.
   *
   * A field declared computed but skipped (here: unknown fn) is absent from
   * evaluate()'s results. The presave hook must therefore strip the client
   * value to NULL using computedFieldIds(), NOT keep what the client sent.
   * This test reproduces the exact presave enforcement step and asserts the
   * spoofed value is removed.
   *
   * @covers ::evaluate
   * @covers ::computedFieldIds
   */
  public function testSkippedComputedFieldStripsClientSpoofToNull(): void {
    $schema = [
      'sections' => [
        [
          'id' => 'section_1',
          'title' => 'Details',
          'fields' => [
            ['id' => 'net_amount', 'type' => 'text', 'label' => 'Amount'],
            [
              'id' => 'approval_tier',
              'type' => 'text',
              'label' => 'Tier',
              // Unknown fn -> evaluate() skips this field entirely.
              'computed' => ['fn' => 'unknownFn', 'args' => ['net_amount']],
            ],
          ],
        ],
      ],
    ];

    // hasDefinition() returns FALSE -> the field is skipped.
    $evaluator = $this->createEvaluator(FALSE);

    // Client tries to spoof a high tier on the skipped computed field.
    $submittedValues = [
      'net_amount' => '100',
      'approval_tier' => 'management',
    ];

    $computed = $evaluator->evaluate($schema, $submittedValues);
    // The skipped field is NOT in results.
    $this->assertArrayNotHasKey('approval_tier', $computed);

    // Reproduce the presave enforcement loop over ALL declared computed ids.
    foreach ($evaluator->computedFieldIds($schema) as $cid) {
      $submittedValues[$cid] = $computed[$cid] ?? NULL;
    }

    // The spoofed value must have been stripped to NULL, never kept.
    $this->assertNull($submittedValues['approval_tier']);
    $this->assertNotSame('management', $submittedValues['approval_tier']);
  }

}
