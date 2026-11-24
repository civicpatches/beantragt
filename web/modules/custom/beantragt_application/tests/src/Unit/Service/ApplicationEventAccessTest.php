<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\ApplicationMessageReadState;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ensures event visibility follows the owning module's access decision.
 */
#[Group('beantragt_application')]
final class ApplicationEventAccessTest extends UnitTestCase {

  /**
   * Enumerates independent owning access checks.
   */
  public static function owningGates(): array {
    return [
      'message' => ['message'],
      'documents' => ['documents'],
      'transfer' => ['transfer'],
      'release' => ['release'],
      'scan' => ['scan'],
    ];
  }

  /**
   * A visible case never bypasses its event source permissions.
   */
  #[DataProvider('owningGates')]
  public function testOwningGateCanDenyEvenVisibleApplication(string $kind): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('access')->willReturn(TRUE);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(21);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['authenticated', 'case_worker']);
    foreach ([FALSE, TRUE] as $allowed) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('id')->willReturn(21);
      $account->method('isAuthenticated')->willReturn(TRUE);
      $account->method('getRoles')->willReturn(['authenticated', 'case_worker']);
      $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $permission === 'view protected application data'
        || ($allowed && in_array($permission, [
          'manage application deliveries', 'bypass beantragt group scope',
        ], TRUE)));
      $messages = $this->createMock(ApplicationMessageReadState::class);
      $messages->method('canReadMessages')->willReturn($allowed);
      $protected = $this->createMock(ProtectedDataGate::class);
      $protected->method('allows')->willReturn($allowed);
      $user = $this->createMock(UserInterface::class);
      $user->method('isActive')->willReturn(TRUE);
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturn($user);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->method('getStorage')->with('user')->willReturn($storage);
      $group = $this->createMock(GroupInterface::class);
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(FALSE);
      $field->method('__get')->with('entity')->willReturn($group);
      $field->method('__isset')->with('entity')->willReturn(TRUE);
      $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_organisation');
      $application->method('get')->with('field_organisation')->willReturn($field);
      $delivery = new ScopeAccess($manager);
      $docgen = $this->createMock(DocgenScopeAccess::class);
      $docgen->method('canWorkOnCase')->with($application, $account, DocgenScopeAccess::PERMISSION_RELEASE, TRUE)->willReturn($allowed);
      $service = new class($this->createMock(Connection::class), $this->createMock(TimeInterface::class), $messages,
        $this->createMock(StateInterface::class), $this->createMock(ClassResolverInterface::class), $protected, $delivery, $docgen) extends ApplicationEventReadState {

        /**
         * Exposes the protected predicate for the unit assertion.
         */
        public function canRead(string $kind, ApplicationInterface $application, AccountInterface $account): bool {
          return $this->allowed($kind, $application, $account);
        }

      };
      $this->assertSame($allowed, $service->canRead($kind, $application, $account));
    }
  }

  /**
   * Released replies remain visible to staff without delivery administration.
   */
  public function testReplyAccessDoesNotRequireDeliveryManagement(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('access')->willReturn(TRUE);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['authenticated', 'case_worker']);
    $account->method('hasPermission')->willReturn(FALSE);
    $delivery = new ScopeAccess($this->createMock(EntityTypeManagerInterface::class));
    $service = new class($this->createMock(Connection::class), $this->createMock(TimeInterface::class),
      $this->createMock(ApplicationMessageReadState::class), $this->createMock(StateInterface::class),
      $this->createMock(ClassResolverInterface::class), $this->createMock(ProtectedDataGate::class), $delivery) extends ApplicationEventReadState {

      /**
       * Exposes the owning endpoint entry policy.
       */
      public function canRead(ApplicationInterface $application, AccountInterface $account): bool {
        return $this->allowed('reply', $application, $account);
      }

    };
    $this->assertTrue($service->canRead($application, $account));
  }

  /**
   * Assignment requires the route permission and the controller's tenant scope.
   */
  public function testAssignmentUsesOwningControllerScope(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('access')->willReturn(TRUE);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);
    foreach ([FALSE, TRUE] as $allowed) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('isAuthenticated')->willReturn(TRUE);
      $account->method('getRoles')->willReturn(['authenticated', 'case_worker']);
      $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $permission === 'view workflow assignments'
        || ($allowed && $permission === 'bypass beantragt group scope'));
      $time = $this->createMock(TimeInterface::class);
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $controller = new ApplicationAssignmentController($account, $time, $manager, new ApplicationCaseScope($manager));
      $classes = $this->createMock(ClassResolverInterface::class);
      $classes->method('getInstanceFromDefinition')->with(ApplicationAssignmentController::class)->willReturn($controller);
      $service = new class($this->createMock(Connection::class), $time, $this->createMock(ApplicationMessageReadState::class),
        $this->createMock(StateInterface::class), $classes, $this->createMock(ProtectedDataGate::class)) extends ApplicationEventReadState {

        /**
         * Exposes the protected predicate for the unit assertion.
         */
        public function canRead(ApplicationInterface $application, AccountInterface $account): bool {
          return $this->allowed('assigned', $application, $account);
        }

      };
      $this->assertSame($allowed, $service->canRead($application, $account));
    }
  }

}
