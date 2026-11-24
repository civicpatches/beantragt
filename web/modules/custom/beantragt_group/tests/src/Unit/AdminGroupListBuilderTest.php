<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\beantragt_group\Entity\Controller\AdminGroupListBuilder;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\RouterInterface;

/**
 * Tests the platform administrator workspace listing.
 */
#[CoversClass(AdminGroupListBuilder::class)]
#[Group('beantragt_group')]
final class AdminGroupListBuilderTest extends UnitTestCase {

  /**
   * Global workspace administrators bypass membership query filtering.
   */
  public function testPlatformAdminListDisablesQueryAccessCheck(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $query = $this->createMock(QueryInterface::class);
    $query->expects(self::once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects(self::once())->method('tableSort')->willReturnSelf();
    $query->expects(self::once())->method('pager')->with(50)->willReturnSelf();
    $query->expects(self::once())->method('execute')->willReturn([3 => 3, 14 => 14]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('id')->willReturn('group');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => $permission === 'administer beantragt groups',
    );

    $builder = new AdminGroupListBuilder(
      $entity_type,
      $storage,
      $this->createMock(RedirectDestinationInterface::class),
      $account,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(RouterInterface::class),
    );

    $method = new \ReflectionMethod($builder, 'getEntityIds');

    self::assertSame([3 => 3, 14 => 14], $method->invoke($builder));
  }

}
