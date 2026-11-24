<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * A transfer reads the coupling only of a connection in its own scope.
 */
#[CoversClass(IntegrationRepository::class)]
#[Group('beantragt_delivery')]
final class IntegrationRepositoryScopeTest extends UnitTestCase {

  /**
   * Same scope keeps the connection, another scope drops it.
   */
  public function testConnectionOfAnotherScopeIsDropped(): void {
    $connection = ['uuid' => 'c', 'scope_group_id' => 10, 'presentation' => ['closure_rule' => 'manual']];
    self::assertSame($connection, IntegrationRepository::inScopeOf($connection, ['scope_group_id' => 10]));
    self::assertSame($connection, IntegrationRepository::inScopeOf($connection, ['scope_group_id' => '10']));
    self::assertNull(IntegrationRepository::inScopeOf($connection, ['scope_group_id' => 11]));
    self::assertNull(IntegrationRepository::inScopeOf(['uuid' => 'c'], ['scope_group_id' => 11]));
    self::assertNull(IntegrationRepository::inScopeOf(NULL, ['scope_group_id' => 10]));
    // An unbound legacy row has no tenant to compare.
    self::assertSame($connection, IntegrationRepository::inScopeOf($connection, ['scope_group_id' => NULL]));
    self::assertSame($connection, IntegrationRepository::inScopeOf($connection, []));
  }

}
