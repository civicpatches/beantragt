<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Unit\Controller;

use Drupal\beantragt_application\Service\RetentionCleanupService;
use Drupal\beantragt_passwordless\Controller\GdprController;
use Drupal\beantragt_passwordless\Service\CitizenErasureService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards of the citizen erasure endpoints.
 *
 * The erasure itself is covered by the CitizenErasureTest kernel test.
 */
#[CoversClass(GdprController::class)]
#[Group('beantragt_passwordless')]
final class GdprControllerTest extends UnitTestCase {

  /**
   * A request without a valid CSRF token deletes nothing.
   */
  public function testDeleteAllRequiresCsrfToken(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(FALSE);

    $controller = $this->controller($this->account(77, ['authenticated']), $csrf, $entity_type_manager);

    $request = Request::create('/auth/gdpr/all', 'DELETE');
    $this->assertSame(Response::HTTP_FORBIDDEN, $controller->deleteAll($request)->getStatusCode());
    $request->headers->set('X-CSRF-Token', 'forged');
    $this->assertSame(Response::HTTP_FORBIDDEN, $controller->deleteAll($request)->getStatusCode());
  }

  /**
   * Staff, administrators and the service account cannot erase themselves.
   */
  #[DataProvider('nonCitizenAccounts')]
  public function testNonCitizenAccountsAreRefused(int $uid, array $roles): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(TRUE);

    $controller = $this->controller($this->account($uid, $roles), $csrf, $entity_type_manager);

    $preview = $controller->preview();
    $this->assertSame(Response::HTTP_FORBIDDEN, $preview->getStatusCode());
    $this->assertSame('gdpr.not_citizen_account', json_decode((string) $preview->getContent(), TRUE)['code']);

    $request = Request::create('/auth/gdpr/all', 'DELETE');
    $request->headers->set('X-CSRF-Token', 'valid');
    $this->assertSame(Response::HTTP_FORBIDDEN, $controller->deleteAll($request)->getStatusCode());
  }

  /**
   * Accounts the erasure endpoints refuse.
   */
  public static function nonCitizenAccounts(): array {
    return [
      'service account' => [12, ['authenticated', 'service_account']],
      'case worker' => [13, ['authenticated', 'case_worker']],
      'department admin' => [14, ['authenticated', 'department_admin']],
      'administrator role' => [15, ['authenticated', 'administrator']],
      'superuser' => [1, ['authenticated']],
    ];
  }

  /**
   * Builds the controller with a real erasure service over mocks.
   */
  private function controller(AccountInterface $account, CsrfTokenGenerator $csrf, EntityTypeManagerInterface $entity_type_manager): GdprController {
    $erasure = new CitizenErasureService(
      $entity_type_manager,
      $this->createMock(Connection::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      new RetentionCleanupService(
        $entity_type_manager,
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(TimeInterface::class),
        $this->createMock(LoggerInterface::class),
        $this->createMock(LockBackendInterface::class),
      ),
      $this->createMock(LoggerInterface::class),
    );
    return new GdprController(
      $account,
      $csrf,
      $this->createMock(SessionConfigurationInterface::class),
      $erasure,
      $this->createMock(SessionManagerInterface::class),
      $this->createMock(UserSessionRepositoryInterface::class),
    );
  }

  /**
   * Builds an authenticated account mock.
   *
   * @param int $uid
   *   User ID.
   * @param string[] $roles
   *   Role IDs.
   */
  private function account(int $uid, array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn($roles);
    $account->method('getEmail')->willReturn('citizen@example.org');
    return $account;
  }

}
