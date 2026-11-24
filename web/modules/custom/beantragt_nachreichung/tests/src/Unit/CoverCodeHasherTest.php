<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Unit;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_nachreichung\Code\CoverCodeHasher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Keyed lookup hash and key check of cover sheet identifiers.
 */
#[Group('beantragt_nachreichung')]
final class CoverCodeHasherTest extends UnitTestCase {

  private const ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  /**
   * Key material before the test.
   */
  private string|false $previous;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previous = getenv(self::ENV);
    putenv(self::ENV . '=' . str_repeat('0123456789abcdef', 4));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Shared PHPUnit process: restore the key (AGENTS gotcha 7).
    putenv($this->previous === FALSE ? self::ENV : self::ENV . '=' . $this->previous);
    parent::tearDown();
  }

  /**
   * Fixed vectors: HKDF info beantragt_cover_code_lookup_v1, "BA1:" prefix.
   */
  public function testKnownVectors(): void {
    $hasher = $this->hasher([]);
    $this->assertSame('e45a4d1c193e599833d75a1accdff530cc8613772b840748ee1f4bee0f5b6d56', $hasher->hash('00000000000000000', 1));
    $this->assertSame('074ac448a58b6023a3a316dd365d36cb68668d43e06d4dda3170da0931f88099', $hasher->hash('00000000000000000', 2));
    $this->assertSame('0ad247160c579bbd', $hasher->keyCheck(1));
    $this->assertSame(1, $hasher->currentVersion());
  }

  /**
   * Only versions 1 to 99 exist.
   */
  public function testVersionOutOfRangeIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->hasher([])->hash('00000000000000000', 100);
  }

  /**
   * The first use stores the KCV; a changed key refuses issuing.
   */
  public function testChangedKeyRefusesIssuing(): void {
    $stored = [];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function () use (&$stored): array {
      return $stored;
    });
    $state->method('set')->willReturnCallback(static function (string $key, array $value) use (&$stored): void {
      $stored = $value;
    });
    $hasher = $this->hasher(NULL, $state);
    $hasher->guard(1);
    $this->assertSame([1 => '0ad247160c579bbd'], $stored);
    $hasher->guard(1);
    $this->assertTrue($hasher->resolvable(1));

    $stored = [1 => 'ffffffffffffffff'];
    $this->assertFalse($hasher->resolvable(1));
    $this->expectException(\RuntimeException::class);
    $hasher->guard(1);
  }

  /**
   * Builds the hasher on the real data protection service.
   */
  private function hasher(?array $checks, ?StateInterface $state = NULL): CoverCodeHasher {
    if ($state === NULL) {
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturn($checks ?? []);
    }
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(1);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $protection = new ApplicationDataProtectionService($this->createMock(EntityTypeManagerInterface::class), new NullLogger());
    return new CoverCodeHasher($protection, $factory, $state, new NullLogger());
  }

}
