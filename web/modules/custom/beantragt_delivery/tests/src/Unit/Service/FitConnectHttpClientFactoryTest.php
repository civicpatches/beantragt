<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Service\FitConnectHttpClientFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keeps the FIT-Connect proxy optional, explicit and credential-free.
 */
#[CoversClass(FitConnectHttpClientFactory::class)]
#[Group('beantragt_delivery')]
final class FitConnectHttpClientFactoryTest extends UnitTestCase {

  /**
   * An unset or empty variable keeps direct connections.
   */
  public function testEmptyProxyMeansDirect(): void {
    $this->assertSame([], FitConnectHttpClientFactory::options(FALSE));
    $this->assertSame([], FitConnectHttpClientFactory::options('  '));
  }

  /**
   * A plain proxy URL applies to both schemes.
   */
  public function testProxyAppliesToBothSchemes(): void {
    $this->assertSame(
      ['proxy' => ['http' => 'http://fitconnect-egress:3128', 'https' => 'http://fitconnect-egress:3128']],
      FitConnectHttpClientFactory::options('http://fitconnect-egress:3128'),
    );
  }

  /**
   * Credentials, paths, other schemes and missing ports fail closed.
   */
  #[DataProvider('invalidProxies')]
  public function testInvalidProxyFailsClosed(string $proxy): void {
    $this->expectException(\InvalidArgumentException::class);
    FitConnectHttpClientFactory::options($proxy);
  }

  /**
   * Proxy values that must never be accepted.
   *
   * @return array<string, array{string}>
   *   Invalid values.
   */
  public static function invalidProxies(): array {
    return [
      'credentials' => ['http://user:pass@fitconnect-egress:3128'],
      'path' => ['http://fitconnect-egress:3128/x'],
      'https scheme' => ['https://fitconnect-egress:3128'],
      'socks' => ['socks5://fitconnect-egress:1080'],
      'no port' => ['http://fitconnect-egress'],
      'port zero' => ['http://fitconnect-egress:0'],
      'uppercase' => ['http://FitConnect-Egress:3128'],
    ];
  }

}
