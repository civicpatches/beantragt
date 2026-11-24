<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_roles_api\Unit;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests shared role classification helpers.
 */
#[CoversClass(BeantragtRoles::class)]
#[Group('beantragt_roles_api')]
final class BeantragtRolesTest extends UnitTestCase {

  /**
   * Management roles are narrower than staff roles.
   */
  public function testManagementRolesExcludeCaseWorkers(): void {
    $this->assertTrue(BeantragtRoles::isStaffAccount($this->account(['case_worker'])));
    $this->assertFalse(BeantragtRoles::isManagementAccount($this->account(['case_worker'])));

    $this->assertTrue(BeantragtRoles::isManagementAccount($this->account(['department_admin'])));
    $this->assertTrue(BeantragtRoles::isManagementAccount($this->account(['administrator'])));
  }

  /**
   * System administration and service accounts are no case-working persons.
   */
  public function testCaseProcessingExcludesSystemAndServiceAccounts(): void {
    $this->assertTrue(BeantragtRoles::isCaseProcessingAccount($this->account(['case_worker'], 8)));
    $this->assertTrue(BeantragtRoles::isCaseProcessingAccount($this->account(['department_admin'], 3)));
    $this->assertTrue(BeantragtRoles::isCaseProcessingAccount($this->account(['administrator', 'case_worker'], 9)));

    $this->assertFalse(BeantragtRoles::isCaseProcessingAccount($this->account(['administrator'], 5)), 'Administrator-only accounts do system administration.');
    $this->assertFalse(BeantragtRoles::isCaseProcessingAccount($this->account(['administrator', 'department_admin'], 1)), 'uid 1 is the installation account.');
    $this->assertFalse(BeantragtRoles::isCaseProcessingAccount($this->account(['service_account', 'case_worker'], 2)));
    $this->assertFalse(BeantragtRoles::isCaseProcessingAccount($this->account([], 4)));
    $this->assertFalse(BeantragtRoles::isCaseProcessingAccount($this->account(['case_worker'], 0)));
  }

  /**
   * Creates an account mock with fixed roles.
   *
   * @param string[] $roles
   *   Account role IDs.
   * @param int $uid
   *   Account id.
   */
  private function account(array $roles, int $uid = 10): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(array_merge(['authenticated'], $roles));
    $account->method('id')->willReturn($uid);
    return $account;
  }

}
