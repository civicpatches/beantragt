<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\AttachmentPrecheckProviderInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\AttachmentPrecheck;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The collector of attachment pre-check providers.
 */
#[CoversClass(AttachmentPrecheck::class)]
#[Group('beantragt_application')]
final class AttachmentPrecheckTest extends UnitTestCase {

  /**
   * Without a provider there is no block.
   */
  public function testNoProviderGivesNoBlock(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $this->assertSame([], (new AttachmentPrecheck())->forApplication($application, [1, 2]));
  }

  /**
   * No provider is asked for an empty list.
   */
  public function testEmptyListAsksNoProvider(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->expects($this->never())->method('forApplication');
    $collector = new AttachmentPrecheck();
    $collector->addProvider($provider);
    $this->assertSame([], $collector->forApplication($this->createMock(ApplicationInterface::class), []));
  }

  /**
   * The first provider that returns a block for an attachment wins.
   */
  public function testFirstProviderWins(): void {
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([1 => ['state' => 'finding', 'fieldId' => 'a']]));
    $collector->addProvider($this->provider([
      1 => ['state' => 'clean', 'fieldId' => 'b'],
      2 => ['state' => 'clean', 'fieldId' => 'c'],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2]);
    $this->assertSame('finding', $blocks[1]['state']);
    $this->assertSame('clean', $blocks[2]['state']);
  }

  /**
   * Provider answers are bounded by the collector.
   *
   * Blocks for attachments the list does not emit, or without a known state,
   * are dropped; an unavailable block keeps only state, field id and label.
   */
  public function testAnswersAreBounded(): void {
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([
      1 => ['state' => 'unavailable', 'fieldId' => 'a', 'label' => 'A', 'codes' => ['person_unmatched']],
      2 => ['state' => 'fictional'],
      3 => 'not a block',
      9 => ['state' => 'clean'],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2, 3]);
    $this->assertSame([1 => ['state' => 'unavailable', 'fieldId' => 'a', 'label' => 'A']], $blocks);
  }

  /**
   * Keys outside the contract never arrive, whatever a provider returns.
   *
   * The block keeps the contract keys in wire order.
   */
  public function testForeignKeysNeverArrive(): void {
    $block = [
      'notices' => [],
      'personBlock' => 'household#0',
      'state' => 'finding',
      'itemId' => '00000000-0000-4000-8000-000000000001',
      'fieldId' => 'proof_a',
      'provider' => ['name' => 'Fictional provider', 'model' => 'fictional-model'],
      'label' => 'Fictional proof',
      'codes' => ['person_unmatched'],
    ];
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([
      1 => $block,
      2 => ['state' => 'unavailable', 'fieldId' => 'proof_b', 'personBlock' => 'household#1'],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2]);
    $this->assertSame([
      'state' => 'finding',
      'fieldId' => 'proof_a',
      'label' => 'Fictional proof',
      'codes' => ['person_unmatched'],
      'notices' => [],
    ], $blocks[1]);
    $this->assertSame(['state' => 'unavailable', 'fieldId' => 'proof_b', 'label' => NULL], $blocks[2]);
    $this->assertSame([], array_diff(array_keys($blocks[1]), AttachmentPrecheckProviderInterface::KEYS));
  }

  /**
   * The field id must be a string; a label that is no string becomes null.
   */
  public function testFieldIdAndLabelTypes(): void {
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([
      1 => ['state' => 'clean', 'fieldId' => ['proof_a'], 'label' => 'A'],
      2 => ['state' => 'clean', 'fieldId' => 'proof_b', 'label' => ['Fictional Person']],
      3 => ['state' => 'unavailable', 'fieldId' => 7, 'label' => 'C'],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2, 3]);
    $this->assertSame([2 => ['state' => 'clean', 'fieldId' => 'proof_b', 'label' => NULL]], $blocks);
  }

  /**
   * A block JSON cannot encode is dropped; the other blocks stay.
   */
  public function testNonEncodableBlockIsDropped(): void {
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([
      1 => ['state' => 'clean', 'fieldId' => 'proof_a', 'label' => "Fictional \xB1 proof"],
      2 => ['state' => 'finding', 'fieldId' => 'proof_b', 'label' => 'B', 'confidence' => NAN],
      3 => ['state' => 'clean', 'fieldId' => 'proof_c', 'label' => 'C'],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2, 3]);
    $this->assertSame([3 => ['state' => 'clean', 'fieldId' => 'proof_c', 'label' => 'C']], $blocks);
  }

  /**
   * A deeply nested block is dropped here, not failing in the response (D2).
   */
  public function testDeeplyNestedBlockIsDropped(): void {
    $nested = 'leaf';
    for ($i = 0; $i < 20; $i++) {
      $nested = [$nested];
    }
    $collector = new AttachmentPrecheck();
    $collector->addProvider($this->provider([
      1 => ['state' => 'finding', 'fieldId' => 'proof_a', 'label' => 'A', 'criteria' => $nested],
      // The contract shape: block, notices, notice, params.
      2 => [
        'state' => 'finding',
        'fieldId' => 'proof_b',
        'label' => 'B',
        'notices' => [['code' => 'person_unmatched', 'params' => ['fact' => 'legible']]],
      ],
    ]));
    $blocks = $collector->forApplication($this->createMock(ApplicationInterface::class), [1, 2]);
    $this->assertSame([2], array_keys($blocks));
  }

  /**
   * A provider failure propagates to the list boundary, which catches it.
   */
  public function testProviderExceptionPropagates(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willThrowException(new \RuntimeException('fictional'));
    $collector = new AttachmentPrecheck();
    $collector->addProvider($provider);
    $this->expectException(\RuntimeException::class);
    $collector->forApplication($this->createMock(ApplicationInterface::class), [1]);
  }

  /**
   * Builds a provider with a fixed answer.
   */
  private function provider(array $answer): AttachmentPrecheckProviderInterface {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willReturn($answer);
    return $provider;
  }

}
