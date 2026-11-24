<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\user\UserInterface;
use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_application\Service\ApplicationCorrectionTransferLock;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests administrative application data edits.
 */
#[CoversClass(ApplicationDataController::class)]
#[Group('beantragt_application')]
final class ApplicationDataControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates an authorized editor for the data correction entry point.
   */
  private function createDataCorrectionAccount(): AccountInterface&MockObject {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('hasPermission')->willReturnMap([
      ['edit application data entities', TRUE],
      ['administer application entities', TRUE],
    ]);
    return $account;
  }

  /**
   * Supplies storage and a real mutation lock for controller update tests.
   */
  private function createDataCorrectionController(
    AccountInterface $account,
    TimeInterface $time,
    ApplicationInterface&MockObject $application,
    ?ApplicationDataProtectionService $data_protection = NULL,
    bool $database_available = TRUE,
  ): ApplicationDataController {
    $application->method('id')->willReturn(42);
    $application->method('access')->willReturnMap([
      ['update', $account, FALSE, TRUE],
      ['delete', $account, FALSE, FALSE],
    ]);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(0);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($application);
    $storage->method('loadUnchanged')->with(42)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $manager->method('getAccessControlHandler')->with('application')->willReturn($this->createMock(EntityAccessControlHandlerInterface::class));

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');
    $database = NULL;
    if ($database_available) {
      $schema = $this->createMock(Schema::class);
      $schema->method('tableExists')->with('beantragt_delivery_log')->willReturn(FALSE);
      $database = $this->createMock(Connection::class);
      $database->method('schema')->willReturn($schema);
    }
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('beantragt_delivery')->willReturn(FALSE);
    return new ApplicationDataController(
      $account,
      $time,
      $manager,
      new ApplicationCaseScope($manager),
      $data_protection,
      applicationMutationLock: new ApplicationDependentMutationLock($manager, $lock),
      correctionTransferLock: new ApplicationCorrectionTransferLock($database, $modules),
    );
  }

  /**
   * Route access allows platform administrators without tenant membership.
   */
  public function testAccessAllowsPlatformAdministrator(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn (string $permission): bool => $permission === 'administer application entities');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $result = $controller->access($this->createApplicationMock(), $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Automatic status changes (uid 0) show as the system, not as Anonymous.
   */
  public function testRevisionSummaryNamesSystemInsteadOfAnonymous(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $this->createMock(AccountInterface::class),
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );
    $summary = new \ReflectionMethod($controller, 'revisionSummary');

    $anonymous = $this->createMock(UserInterface::class);
    $anonymous->method('isAnonymous')->willReturn(TRUE);
    $anonymous->method('getDisplayName')->willReturn('Anonymous');
    $system = $this->createMock(ApplicationInterface::class);
    $system->method('getRevisionId')->willReturn(7);
    $system->method('getRevisionUser')->willReturn($anonymous);
    $this->assertSame(['id' => 7, 'authorSystem' => TRUE], $summary->invoke($controller, $system));

    $person = $this->createMock(UserInterface::class);
    $person->method('isAnonymous')->willReturn(FALSE);
    $person->method('getDisplayName')->willReturn('Sachbearbeitung');
    $person->method('access')->willReturn(TRUE);
    $released = $this->createMock(ApplicationInterface::class);
    $released->method('getRevisionId')->willReturn(8);
    $released->method('getRevisionUser')->willReturn($person);
    $this->assertSame(['id' => 8, 'authorName' => 'Sachbearbeitung'], $summary->invoke($controller, $released));
  }

  /**
   * Delete access allows platform administrators without tenant membership.
   */
  public function testDeleteAccessAllowsPlatformAdministrator(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn (string $permission): bool => $permission === 'administer application entities');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $result = $controller->deleteAccess($this->createApplicationMock(), $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Delete access fails closed without the dedicated permission.
   */
  public function testDeleteAccessRejectsMissingPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $result = $controller->deleteAccess($this->createApplicationMock(), $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Application deletion requires an unchanged record and returns its UUID.
   */
  public function testDeleteRemovesApplication(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:44']);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->expects($this->once())->method('delete');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $response = $controller->delete(
          $application,
          new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
      );

    $this->assertSame([
      'success' => TRUE,
      'id' => '11111111-1111-4111-8111-111111111111',
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Application deletion always nests the application lock in the scope lock.
   */
  public function testDeleteUsesConsistentMutationLockOrder(): void {
    $events = [];
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(7);

    $current = $this->createMock(ApplicationInterface::class);
    $current->method('getCacheContexts')->willReturn([]);
    $current->method('getCacheTags')->willReturn(['application:44']);
    $current->method('getCacheMaxAge')->willReturn(-1);
    $current->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $current->method('getChangedTime')->willReturn(1779780300);
    $current->method('getRevisionId')->willReturn(7);
    $current->expects($this->once())->method('delete')
      ->willReturnCallback(static function () use (&$events): void {
        $events[] = 'delete';
      });

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('resetCache')->with([44]);
    $storage->expects($this->exactly(2))->method('load')->with(44)->willReturn($current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $backend = $this->createMock(LockBackendInterface::class);
    $backend->expects($this->once())->method('acquire')
      ->willReturnCallback(static function () use (&$events): bool {
        $events[] = 'application-acquire';
        return TRUE;
      });
    $backend->expects($this->once())->method('release')
      ->willReturnCallback(static function () use (&$events): void {
        $events[] = 'application-release';
      });
    $mutation_lock = new ApplicationDependentMutationLock($manager, $backend);

    $scope_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_lock->expects($this->once())->method('acquireForApplication')
      ->with($application)
      ->willReturnCallback(static function () use (&$events): bool {
        $events[] = 'scope-acquire';
        return TRUE;
      });
    $scope_lock->expects($this->once())->method('release')
      ->with($application)
      ->willReturnCallback(static function () use (&$events): void {
        $events[] = 'scope-release';
      });

    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      NULL,
      $mutation_lock,
      $scope_lock,
    );

    $controller->delete(
      $application,
      new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );

    $this->assertSame([
      'scope-acquire',
      'application-acquire',
      'delete',
      'application-release',
      'scope-release',
    ], $events);
  }

  /**
   * Application deletion reports an active delivery as a conflict.
   */
  public function testDeleteReportsActiveDeliveryConflict(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:44']);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->method('delete')->willThrowException(new EntityStorageException(
      'Exception during application deletion.',
      0,
      new ApplicationDeletionLockedException('Delivery active.'),
    ));

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->expectException(ConflictHttpException::class);
    $controller->delete(
      $application,
      new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );
  }

  /**
   * Application deletion rejects a stale detail view.
   */
  public function testDeleteRejectsChangedApplication(): void {
    $account = $this->createMock(AccountInterface::class);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780301);
    $application->expects($this->never())->method('delete');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(ConflictHttpException::class);
    $controller->delete(
          $application,
          new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );
  }

  /**
   * Deletion revalidates the changed timestamp after acquiring the lock.
   */
  public function testDeleteRejectsChangeCommittedBeforeLockAcquisition(): void {
    $account = $this->createMock(AccountInterface::class);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('delete');

    $current = $this->createMock(ApplicationInterface::class);
    $current->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $current->method('getChangedTime')->willReturn(1779780301);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('resetCache')->with([44]);
    $storage->expects($this->exactly(2))->method('load')->with(44)->willReturn($current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $backend = $this->createMock(LockBackendInterface::class);
    $backend->expects($this->once())->method('acquire')->willReturn(TRUE);
    $backend->expects($this->once())->method('release');
    $mutation_lock = new ApplicationDependentMutationLock($manager, $backend);

    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      NULL,
      $mutation_lock,
    );

    $this->expectException(ConflictHttpException::class);
    $controller->delete(
      $application,
      new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );
  }

  /**
   * Deletion rejects a same-second revision change after lock acquisition.
   */
  public function testDeleteRejectsRevisionCommittedBeforeLockAcquisition(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(7);
    $application->expects($this->never())->method('delete');

    $current = $this->createMock(ApplicationInterface::class);
    $current->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $current->method('getChangedTime')->willReturn(1779780300);
    $current->method('getRevisionId')->willReturn(8);
    $current->expects($this->never())->method('delete');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('resetCache')->with([44]);
    $storage->expects($this->exactly(2))->method('load')->with(44)->willReturn($current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $backend = $this->createMock(LockBackendInterface::class);
    $backend->expects($this->once())->method('acquire')->willReturn(TRUE);
    $backend->expects($this->once())->method('release');
    $mutation_lock = new ApplicationDependentMutationLock($manager, $backend);

    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      NULL,
      $mutation_lock,
    );

    $this->expectException(ConflictHttpException::class);
    $controller->delete(
      $application,
      new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );
  }

  /**
   * Deletion rechecks tenant access against the entity loaded under the lock.
   */
  public function testDeleteRejectsEntityThatIsNoLongerAccessibleAfterLock(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(7);
    $application->expects($this->never())->method('delete');

    $current = $this->createMock(ApplicationInterface::class);
    $current->method('getCacheContexts')->willReturn([]);
    $current->method('getCacheTags')->willReturn(['application:44']);
    $current->method('getCacheMaxAge')->willReturn(-1);
    $current->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $current->method('getChangedTime')->willReturn(1779780300);
    $current->method('getRevisionId')->willReturn(7);
    $current->expects($this->never())->method('delete');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('resetCache')->with([44]);
    $storage->expects($this->exactly(2))->method('load')->with(44)->willReturn($current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $backend = $this->createMock(LockBackendInterface::class);
    $backend->expects($this->once())->method('acquire')->willReturn(TRUE);
    $backend->expects($this->once())->method('release');
    $mutation_lock = new ApplicationDependentMutationLock($manager, $backend);

    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      NULL,
      $mutation_lock,
    );

    $this->expectException(AccessDeniedHttpException::class);
    $controller->delete(
      $application,
      new Request([], [], [], [], [], [], '{"expected_changed":1779780300}'),
    );
  }

  /**
   * Scoped edit access fails closed for applications without tenant scope.
   */
  public function testAccessRejectsUnscopedApplication(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn (string $permission): bool => $permission === 'edit application data entities');

    $application = $this->createApplicationMock();
    $application->method('hasField')->willReturn(FALSE);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $result = $controller->access($application, $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Sensitive application data edits require protected-data reveal access.
   */
  public function testAccessRejectsSensitiveApplicationWithoutProtectedDataPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === 'edit application data entities');

    $application = $this->createApplicationMock();
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'sections' => [
                      [
                        'fields' => [
                          ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getSchemaVersion')->willReturn(0);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
          new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)),
      );

    $result = $controller->access($application, $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Sensitive application data view redacts values without reveal permission.
   */
  public function testViewRedactsSensitiveApplicationDataWithoutProtectedPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $application = $this->createApplicationMock();
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'sections' => [
                      [
                        'fields' => [
                          ['id' => 'full_name', 'label' => 'Name'],
                          ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => 'true'],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getSchemaVersion')->willReturn(0);
    $application->method('getData')
      ->willReturn('{"full_name":"Max Mustermann","diagnosis":"IfSG-Meldung"}');
    $application->method('getChangedTime')->willReturn(1779780000);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
          new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)),
      );

    $response = $controller->view($application);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $payload['data']['full_name']
      );
    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $payload['data']['diagnosis']
      );
    $this->assertTrue($payload['meta']['protected_data_redacted']);
  }

  /**
   * File-only application data view is protected by default.
   */
  public function testViewRedactsFileOnlyApplicationDataWithoutProtectedPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $application = $this->createApplicationMock();
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'sections' => [
                      [
                        'fields' => [
                          ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getSchemaVersion')->willReturn(0);
    $application->method('getData')
      ->willReturn('{"evidence":[{"filename":"scan.pdf","attachmentId":"1fded50b-9249-4af7-88a5-4ea2245a10bb"}]}');
    $application->method('getChangedTime')->willReturn(1779780000);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
          new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)),
      );

    $response = $controller->view($application);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $payload['data']['evidence']
      );
    $this->assertTrue($payload['meta']['protected_data_redacted']);
  }

  /**
   * Application data view returns the schema revision used by the application.
   */
  public function testViewReturnsApplicationSchemaRevisionMeta(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $current_schema = $this->createFormSchemaMock(
          [
            'sections' => [
              [
                'fields' => [
                  ['id' => 'full_name', 'label' => 'Aktueller Name'],
                ],
              ],
            ],
          ],
          3,
          'schema-current',
          'Aktuelles Formular',
          1779780500,
          '42',
      );
    $revision_schema = $this->createFormSchemaMock(
          [
            'sections' => [
              [
                'fields' => [
                  ['id' => 'full_name', 'label' => 'Historischer Name'],
                ],
              ],
            ],
          ],
          2,
          'schema-current',
          'Historisches Formular',
          1779770000,
          '42',
          '{"mode":"inherit"}',
      );

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->with('revision_id', 'DESC')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([20 => 20]);

    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->with(20)->willReturn($revision_schema);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('form_schema')->willReturn($storage);

    $application = $this->createApplicationMock();
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn($this->createSchemaReferenceFieldForEntity($current_schema));
    $application->method('getSchemaVersion')->willReturn(2);
    $application->method('getData')->willReturn('{"full_name":"Ada Antrag"}');
    $application->method('getChangedTime')->willReturn(1779780000);

    $controller = new ApplicationDataController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)),
    );

    $response = $controller->view($application);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(['full_name' => 'Ada Antrag'], $payload['data']);
    $this->assertFalse($payload['meta']['protected_data_redacted']);
    $this->assertSame('schema-current', $payload['meta']['schema']['id']);
    $this->assertSame('form_schema--form_schema', $payload['meta']['schema']['type']);
    $this->assertSame('Historisches Formular', $payload['meta']['schema']['attributes']['label']);
    $this->assertSame(2, $payload['meta']['schema']['attributes']['field_version']);
    $this->assertSame('2026-05-26T04:33:20+00:00', $payload['meta']['schema']['attributes']['changed']);
    $this->assertSame(
          '{"mode":"inherit"}',
          $payload['meta']['schema']['attributes']['field_workflow_definition']['value'],
      );
    $schema_payload = json_decode($payload['meta']['schema']['attributes']['field_schema']['value'], TRUE);
    $this->assertSame('Historischer Name', $schema_payload['sections'][0]['fields'][0]['label']);
  }

  /**
   * Creates an application mock with cacheability metadata.
   */
  private function createApplicationMock(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:1']);
    $application->method('getCacheMaxAge')->willReturn(-1);
    return $application;
  }

  /**
   * Due date updates create an auditable revision.
   */
  public function testUpdateDeadlineCreatesRevision(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->once())
      ->method('getRequestTime')
      ->willReturn(1779780400);

    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->with('field_due_date')
      ->willReturn(TRUE);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(33);
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);
    $application->expects($this->once())->method('setRevisionUserId')->with(1);
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400);
    $application->expects($this->once())->method('setRevisionLogMessage')->with('');
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                  return $application;
              }
          );
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $time,
          $manager,
          new ApplicationCaseScope($manager),
      );

    $response = $controller->updateDeadline(
          $application,
          new Request([], [], [], [], [], [], '{"expected_changed":1779780300,"due_date":"2026-06-30"}'),
      );

    $this->assertSame('2026-06-30', $writes['field_due_date']);
    $this->assertSame(
          [
            'success' => TRUE,
            'revision_id' => 33,
            'due_date' => '2026-06-30',
          ], json_decode((string) $response->getContent(), TRUE)
      );
  }

  /**
   * Due date updates reject invalid dates.
   */
  public function testUpdateDeadlineRejectsInvalidDate(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->with('field_due_date')
      ->willReturn(TRUE);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Due date is invalid.');

    $controller->updateDeadline(
          $application,
          new Request([], [], [], [], [], [], '{"expected_changed":1779780300,"due_date":"2026-02-31"}'),
      );
  }

  /**
   * Due date updates are guarded by the application changed timestamp.
   */
  public function testUpdateDeadlineRejectsStaleApplication(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->with('field_due_date')
      ->willReturn(TRUE);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(ConflictHttpException::class);

    $controller->updateDeadline(
          $application,
          new Request([], [], [], [], [], [], '{"expected_changed":1779780000,"due_date":"2026-06-30"}'),
      );
  }

  /**
   * Data edits persist the payload and create an auditable revision.
   */
  public function testUpdateCreatesRevisionWithTimestamp(): void {
    $account = $this->createDataCorrectionAccount();
    $account->method('getDisplayName')->willReturn('admin');

    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->once())
      ->method('getRequestTime')
      ->willReturn(1779780000);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->exactly(2))
      ->method('getChangedTime')
      ->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Dienstreise',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'destination',
                            'type' => 'text',
                            'label' => 'Ziel',
                            'required' => TRUE,
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"destination":"Musterstadt"}');
    $application->expects($this->once())
      ->method('setNewRevision')
      ->with(TRUE)
      ->willReturnSelf();
    $application->expects($this->once())
      ->method('setRevisionUserId')
      ->with(1)
      ->willReturnSelf();
    $application->expects($this->once())
      ->method('setRevisionCreationTime')
      ->with(1779780000)
      ->willReturnSelf();
    $application->expects($this->once())->method('setRevisionLogMessage')->with('Corrected after review');
    $application->expects($this->once())
      ->method('set')
      ->with('field_data', '{"destination":"Dunkelstadt Nord"}')
      ->willReturnSelf();
    $application->expects($this->once())
      ->method('refreshCrmDataFromSchema')
      ->with(
              '{"destination":"Dunkelstadt Nord"}',
              $this->isInstanceOf(FormSchemaInterface::class),
          )
      ->willReturnSelf();
    $application->expects($this->once())
      ->method('validate')
      ->willReturn(new ConstraintViolationList());
    $application->expects($this->once())
      ->method('save');
    $application->method('getRevisionId')->willReturn(42);

    $controller = $this->createDataCorrectionController($account, $time, $application);
    $response = $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"destination":"Dunkelstadt Nord"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );

    $audit = RevisionLog::decode(RevisionLog::build($application, []));
    $this->assertSame(['data_edited'], $audit['events']);
    $this->assertSame('Corrected after review', $audit['message']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
          ['success' => TRUE, 'revision_id' => 42],
          json_decode((string) $response->getContent(), TRUE),
      );
  }

  /**
   * A running transfer holds the case: the edit is refused, not a 500.
   */
  public function testUpdateAnswersLockedWhileTheCaseIsHeld(): void {
    $account = $this->createDataCorrectionAccount();
    $saving = function (EntityStorageException $failure): ApplicationInterface&MockObject {
      $application = $this->createMock(ApplicationInterface::class);
      $application->method('getChangedTime')->willReturn(1779779900);
      $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
      $application->method('get')->with('field_schema')->willReturn($this->createSchemaReferenceField([
        'title' => 'Dienstreise',
        'sections' => [
          [
            'id' => 'basis',
            'title' => 'Basis',
            'fields' => [
              ['id' => 'destination', 'type' => 'text', 'label' => 'Ziel', 'required' => TRUE],
            ],
          ],
        ],
      ]));
      $application->method('getData')->willReturn('{"destination":"Musterstadt"}');
      $application->method('validate')->willReturn(new ConstraintViolationList());
      $application->method('save')->willThrowException($failure);
      return $application;
    };
    $request = fn(): Request => new Request([], [], [], [], [], [], '{"data":{"destination":"Dunkelstadt"},"reason":"Corrected after review","expected_changed":1779779900}');
    $time = $this->createMock(TimeInterface::class);

    // SqlContentEntityStorage wraps the presave lock failure.
    $application = $saving(new EntityStorageException('Locked.', 0,
      new ApplicationDeletionLockedException('The application is currently being deleted or delivered.')));
    $controller = $this->createDataCorrectionController($account, $time, $application);
    try {
      $controller->update($application, $request());
      $this->fail('A held case lock must refuse the edit.');
    }
    catch (HttpException $exception) {
      $this->assertSame(423, $exception->getStatusCode());
    }

    // Other storage failures keep their own error path.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Disk full.');
    $application = $saving(new EntityStorageException('Disk full.'));
    $controller = $this->createDataCorrectionController($account, $time, $application);
    $controller->update($application, $request());
  }

  /**
   * A missing mutation lock refuses the correction before accessing storage.
   */
  public function testUpdateRequiresMutationLock(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->expects($this->never())->method('save');
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $controller = new ApplicationDataController($this->createDataCorrectionAccount(), $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));

    $this->expectException(ApplicationLockedHttpException::class);
    $controller->update($application, Request::create('/', 'PATCH', content: '{"data":{"count":2},"reason":"Corrected count","expected_changed":1779779900}'));
  }

  /**
   * A missing transfer database refuses the correction under the case lock.
   */
  public function testUpdateRequiresTransferDatabase(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->expects($this->never())->method('save');
    $controller = $this->createDataCorrectionController($this->createDataCorrectionAccount(), $this->createMock(TimeInterface::class), $application, database_available: FALSE);

    $response = $controller->update($application, Request::create('/', 'PATCH', content: '{"data":{"count":2},"reason":"Corrected count","expected_changed":1779779900}'));
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('application.data_edit_transfer_locked', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * A missing referenced schema makes the data read-only.
   */
  public function testDataCorrectionRequiresApplicationSchema(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->with('field_schema')->willReturn(FALSE);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->expects($this->never())->method('save');
    $controller = $this->createDataCorrectionController($this->createDataCorrectionAccount(), $this->createMock(TimeInterface::class), $application);

    $response = $controller->view($application);
    $this->assertSame(200, $response->getStatusCode());
    $meta = json_decode((string) $response->getContent(), TRUE)['meta'];
    $this->assertNull($meta['schema']);
    $this->assertFalse($meta['can_edit_data']);
    $this->assertFalse($meta['can_delete']);
    $this->assertSame('schema', $meta['edit_data_blocked_reason']);

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Application schema is missing.');
    $controller->update($application, Request::create('/', 'PATCH', content: '{"data":{"count":2},"reason":"Corrected count","expected_changed":1779779900}'));
  }

  /**
   * Status, due date and rollback saves answer 423 while the case is held.
   */
  public function testWorkflowSavesAnswerLockedWhileTheCaseIsHeld(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);
    $locked = static fn(): EntityStorageException => new EntityStorageException('Locked.', 0,
      new ApplicationDeletionLockedException('The application is currently being deleted or delivered.'));

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->with('id', 7)->willReturnSelf();
    $query->method('sort')->with('revision_id', 'DESC')->willReturnSelf();
    $query->method('execute')->willReturn([30 => 30, 10 => 10]);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->willReturnMap([
      [10, $this->createWorkflowApplicationRevision(7, 10, 1, 1779780100)],
    ]);
    [$status_storage, $resolver] = $this->rollbackTargetInWorkflow();
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(fn(string $type) => $type === 'workflow_status' ? $status_storage : $storage);
    $controller = new ApplicationDataController($account, $time, $manager, new ApplicationCaseScope($manager), workflowResolver: $resolver);

    $calls = [
      'updateStatus' => '{"expected_status_id":2,"expected_changed":1779780300,"target_status_id":3,"attributes":{}}',
      'updateDeadline' => '{"expected_changed":1779780300,"due_date":"2026-06-30"}',
      'rollbackStatus' => '{"expected_status_id":2,"expected_changed":1779780300}',
    ];
    foreach ($calls as $method => $body) {
      $application = $this->createMock(ApplicationInterface::class);
      $application->method('id')->willReturn(7);
      $application->method('getRevisionId')->willReturn(30);
      $application->method('getChangedTime')->willReturn(1779780300);
      $application->method('hasField')->willReturn($method === 'updateDeadline');
      $application->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => $field === 'field_workflow_status'
        ? $this->createWorkflowStatusField(2)
        : $this->createWorkflowAttributesField(NULL));
      $application->method('validate')->willReturn(new ConstraintViolationList());
      $application->expects($this->once())->method('save')->willThrowException($locked());
      try {
        $controller->{$method}($application, new Request([], [], [], [], [], [], $body));
        $this->fail($method . ' must refuse the save while the case is held.');
      }
      catch (ApplicationLockedHttpException $exception) {
        $this->assertSame(423, $exception->getStatusCode(), $method);
        $this->assertInstanceOf(EntityStorageException::class, $exception->getPrevious());
      }
    }

    // Other storage failures keep their own error path.
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->willReturn(TRUE);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->method('save')->willThrowException(new EntityStorageException('Disk full.'));
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Disk full.');
    $controller->updateDeadline($application, new Request([], [], [], [], [], [], $calls['updateDeadline']));
  }

  /**
   * Protected data edits refresh the applicant email lookup hash.
   */
  public function testUpdateRefreshesProtectedApplicantEmailHash(): void {
    $original_key = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('b', 32));

    try {
      $account = $this->createDataCorrectionAccount();
      $account->method('getDisplayName')->willReturn('admin');

      $time = $this->createMock(TimeInterface::class);
      $time->method('getRequestTime')->willReturn(1779780000);

      $schema = [
        'title' => 'IfSG',
        'sections' => [
          [
            'id' => 'basis',
            'title' => 'Basis',
            'fields' => [
              ['id' => 'applicant_email', 'type' => 'email', 'label' => 'E-Mail'],
              ['id' => 'diagnosis', 'type' => 'text', 'label' => 'Diagnose', 'sensitive' => TRUE],
            ],
          ],
        ],
      ];
      $schema_field = $this->createSchemaReferenceField($schema);
      $data_json = '{"applicant_email":"old@example.test","diagnosis":"Alt"}';
      $writes = [];

      $application = $this->createMock(ApplicationInterface::class);
      $application->method('getChangedTime')->willReturn(1779779900);
      $application->method('getData')->willReturnCallback(static function () use (&$data_json): string {
        return $data_json;
      });
      $application->method('hasField')
        ->willReturnCallback(static fn (string $field_name): bool => in_array($field_name, [
          'field_schema',
          'field_applicant_email',
          'field_applicant_email_hash',
          'field_crm_data',
        ], TRUE));
      $application->method('get')
        ->willReturnCallback(fn (string $field_name): FieldItemListInterface => match ($field_name) {
          'field_schema' => $schema_field,
          'field_applicant_email' => $this->fieldList([], TRUE),
          default => $this->fieldList([], TRUE),
        });
      $application->method('getSchemaVersion')->willReturn(1);
      $application->method('setNewRevision')->with(TRUE)->willReturnSelf();
      $application->method('setRevisionUserId')->with(1)->willReturnSelf();
      $application->method('setRevisionCreationTime')->with(1779780000)->willReturnSelf();
      $application->method('setRevisionLogMessage')->willReturnSelf();
      $application->method('set')
        ->willReturnCallback(
                function (string $field_name, mixed $value) use (&$data_json, &$writes, $application): ApplicationInterface {
                    $writes[$field_name] = $value;
                  if ($field_name === 'field_data') {
                      $data_json = (string) $value;
                  }
                    return $application;
                }
            );
      $application->method('refreshCrmDataFromSchema')->willReturnSelf();
      $application->method('validate')->willReturn(new ConstraintViolationList());
      $application->method('save');
      $application->method('getRevisionId')->willReturn(43);

      $data_protection = new ApplicationDataProtectionService(
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(LoggerInterface::class),
      );
      $controller = $this->createDataCorrectionController($account, $time, $application, $data_protection);

      $controller->update(
            $application,
            new Request([], [], [], [], [], [], '{"data":{"applicant_email":"new@example.test","diagnosis":"Neu"},"reason":"Corrected after review","expected_changed":1779779900}'),
        );

      $this->assertSame('{"applicant_email":"new@example.test","diagnosis":"Neu"}', $writes['field_data']);
      $this->assertSame($data_protection->applicantEmailLookupHash('new@example.test'), $writes['field_applicant_email_hash']);
      $this->assertNull($writes['field_applicant_email']);
      $this->assertNull($writes['field_crm_data']);
    }
    finally {
      if ($original_key === FALSE) {
        putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
      }
      else {
        putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $original_key);
      }
    }
  }

  /**
   * Stale edits are rejected before mutating the entity.
   */
  public function testUpdateRejectsStaleChangedTimestamp(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->never())
      ->method('getRequestTime');

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())
      ->method('getChangedTime')
      ->willReturn(1779779900);
    $application->expects($this->never())
      ->method('setNewRevision');
    $application->expects($this->never())
      ->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $time,
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(ConflictHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"destination":"Dunkelstadt Nord"},"reason":"Corrected after review","expected_changed":1779779800}'),
      );
  }

  /**
   * Schema choice domains are enforced on the Drupal endpoint.
   */
  public function testUpdateRejectsInvalidSchemaChoice(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Auswahl',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'decision',
                            'type' => 'select',
                            'label' => 'Entscheidung',
                            'options' => ['approved', 'rejected'],
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"decision":"approved"}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"decision":"pending"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * Schema-unknown stored keys must be preserved byte-for-byte.
   */
  public function testUpdateRejectsChangedUnknownStoredKey(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Freitext',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'destination',
                            'type' => 'text',
                            'label' => 'Ziel',
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"destination":"Musterstadt","legacy_pdf_field":"keep"}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"destination":"Dunkelstadt","legacy_pdf_field":"changed"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * Normalized schema format rules are enforced by the Drupal endpoint.
   */
  public function testUpdateRejectsInvalidInferredUrlFormat(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Homepage',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'homepage',
                            'type' => 'url',
                            'label' => 'Homepage',
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"homepage":"https://example.org"}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"homepage":"javascript://example.com"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * Legacy invalid validation patterns fail closed on application edits.
   */
  public function testUpdateRejectsInvalidSchemaValidationPattern(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Regex',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'code',
                            'type' => 'text',
                            'label' => 'Code',
                            'validation' => [
                              'pattern' => '[',
                            ],
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"code":"ABC"}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"code":"ABC"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * Empty validation patterns are treated as no pattern.
   */
  public function testUpdateIgnoresEmptySchemaValidationPattern(): void {
    $account = $this->createDataCorrectionAccount();
    $account->method('getDisplayName')->willReturn('admin');

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780000);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Regex',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'code',
                            'type' => 'text',
                            'label' => 'Code',
                            'validation' => [
                              'pattern' => '',
                            ],
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')->willReturn('{"code":"ABC"}');
    $application->method('setNewRevision')->with(TRUE)->willReturnSelf();
    $application->method('setRevisionUserId')->with(1)->willReturnSelf();
    $application->method('setRevisionCreationTime')->with(1779780000)->willReturnSelf();
    $application->method('setRevisionLogMessage')->willReturnSelf();
    $application->method('set')->with('field_data', '{"code":"ABC"}')->willReturnSelf();
    $application->method('refreshCrmDataFromSchema')->willReturnSelf();
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');
    $application->method('getRevisionId')->willReturn(43);

    $controller = $this->createDataCorrectionController($account, $time, $application);

    $response = $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"code":"ABC"},"reason":"Corrected after review","expected_changed":1779779900}'),
      );

    $this->assertSame(
          ['success' => TRUE, 'revision_id' => 43],
          json_decode((string) $response->getContent(), TRUE),
      );
  }

  /**
   * Hidden schema-known values that were already hidden cannot be deleted.
   */
  public function testUpdateRejectsHiddenStoredFieldDeletion(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Conditional',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'has_note',
                            'type' => 'checkbox',
                            'label' => 'Has note',
                          ],
                          [
                            'id' => 'note',
                            'type' => 'textarea',
                            'label' => 'Note',
                            'conditionalOn' => [
                              'fieldId' => 'has_note',
                              'value' => TRUE,
                            ],
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"has_note":false,"note":"Previously hidden"}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"has_note":false},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * Checkbox option groups must remain string arrays.
   */
  public function testUpdateRejectsNonStringCheckboxOptions(): void {
    $account = $this->createDataCorrectionAccount();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779779900);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn(
              $this->createSchemaReferenceField(
                  [
                    'title' => 'Choices',
                    'sections' => [
                      [
                        'id' => 'basis',
                        'title' => 'Basis',
                        'fields' => [
                          [
                            'id' => 'choices',
                            'type' => 'checkbox',
                            'label' => 'Choices',
                            'options' => ['1', '2'],
                          ],
                        ],
                      ],
                    ],
                  ]
              )
          );
    $application->method('getData')
      ->willReturn('{"choices":["1"]}');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->update(
          $application,
          new Request([], [], [], [], [], [], '{"data":{"choices":[1]},"reason":"Corrected after review","expected_changed":1779779900}'),
      );
  }

  /**
   * The application's origin decides whether entry columns exist.
   *
   * Same stored data and the same edit: a batch application validates the
   * repeatable section entry by entry, a citizen application flat.
   */
  public function testUpdatePassesTheOriginToEntryValidation(): void {
    foreach ([
      'bulk' => 'Section "entries", entry 1: Field "text_a" must be a string.',
      'citizen' => 'Field "text_a" must be a string.',
    ] as $origin => $message) {
      $account = $this->createDataCorrectionAccount();
      $application = $this->createMock(ApplicationInterface::class);
      $application->method('getChangedTime')->willReturn(1779779900);
      $application->method('getOrigin')->willReturn($origin);
      $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
      $application->method('get')->with('field_schema')->willReturn($this->createSchemaReferenceField([
        'title' => 'Entries',
        'sections' => [
          [
            'id' => 'entries',
            'title' => 'Entries',
            'repeatable' => TRUE,
            'fields' => [['id' => 'text_a', 'type' => 'text', 'label' => 'Text A']],
          ],
        ],
      ]));
      $application->method('getData')->willReturn('{"text_a":["First","Second"]}');
      $application->expects($this->never())->method('save');
      $controller = $this->createDataCorrectionController($account, $this->createMock(TimeInterface::class), $application);
      try {
        $controller->update($application, new Request([], [], [], [], [], [], '{"data":{"text_a":["First",2]},"reason":"Corrected after review","expected_changed":1779779900}'));
        $this->fail('Expected a refusal for origin ' . $origin);
      }
      catch (UnprocessableEntityHttpException $exception) {
        $this->assertSame($message, $exception->getMessage(), $origin);
      }
    }
  }

  /**
   * Rollback target includes the revision that introduced the current status.
   */
  public function testStatusRollbackTargetIncludesRevisionContext(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $current_application = $this->createWorkflowApplicationRevision(7, 30, 2, 1779780300);
    $same_status_revision = $this->createWorkflowApplicationRevision(7, 20, 2, 1779780200);
    $previous_status_revision = $this->createWorkflowApplicationRevision(7, 10, 1, 1779780100);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->with('id', 7)->willReturnSelf();
    $query->method('sort')->with('revision_id', 'DESC')->willReturnSelf();
    $query->method('execute')->willReturn(
          [
            30 => 30,
            20 => 20,
            10 => 10,
          ]
      );

    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')
      ->willReturnMap(
              [
                [20, $same_status_revision],
                [10, $previous_status_revision],
              ]
          );

    // The target status 1 belongs to group 5 and is part of the form's
    // governing workflow there.
    $target_status = $this->createMock(WorkflowStatusInterface::class);
    $target_status->method('getGroupId')->willReturn(5);
    $status_storage = $this->createMock(EntityStorageInterface::class);
    $status_storage->method('load')->with(1)->willReturn($target_status);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')
      ->willReturnMap([
        ['application', $storage],
        ['workflow_status', $status_storage],
      ]);
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('governingStatusIds')->with($current_application, 5)->willReturn([1, 2]);

    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
          workflowResolver: $resolver,
      );

    $response = $controller->statusRollbackTarget($current_application);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
          [
            'available' => TRUE,
            'currentStatus' => ['internalId' => 2],
            'targetStatus' => ['internalId' => 1],
            'currentStatusRevision' => [
              'id' => 20,
              'created' => 1779780200,
            ],
          ], json_decode((string) $response->getContent(), TRUE)
      );
  }

  /**
   * Invisible historic scope prevents both target disclosure and rollback.
   */
  public function testRollbackCannotReadOrApplyAnInvisibleRevision(): void {
    $account = $this->createMock(AccountInterface::class);
    $current = $this->createWorkflowApplicationRevision(7, 30, 2, 1779780300);
    $previous = $this->createWorkflowApplicationRevision(7, 10, 1, 1779780100, can_view: FALSE);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([30 => 30, 10 => 10]);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->with(10)->willReturn($previous);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $controller = new ApplicationDataController($account, $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));
    $this->assertSame(['available' => FALSE], json_decode((string) $controller->statusRollbackTarget($current)->getContent(), TRUE));
    $current->expects($this->never())->method('save');
    $this->expectException(ConflictHttpException::class);
    $controller->rollbackStatus($current, new Request(content: json_encode([
      'expected_changed' => 0,
      'expected_status_id' => 2,
      'note' => 'Please restore.',
    ], JSON_THROW_ON_ERROR)));
  }

  /**
   * An author label is never exposed without the label access operation.
   */
  public function testRevisionAuthorSummaryRespectsLabelAccess(): void {
    $viewer = $this->createMock(AccountInterface::class);
    $author = $this->createMock(UserInterface::class);
    $author->method('access')->with('view label', $viewer)->willReturn(FALSE);
    $author->expects($this->never())->method('getDisplayName');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getRevisionId')->willReturn(20);
    $application->method('getRevisionUser')->willReturn($author);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController($viewer, $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));
    $summary = (new \ReflectionMethod($controller, 'revisionSummary'))->invoke($controller, $application);
    $this->assertSame(['id' => 20], $summary);
  }

  /**
   * Rollback restores the previous distinct status and its status attributes.
   */
  public function testRollbackStatusRestoresPreviousStatusAndAttributes(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $current_status_id = 2;
    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(7);
    $application->method('getRevisionId')->willReturn(30);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('get')
      ->willReturnCallback(
              function (string $field_name) use (&$current_status_id): FieldItemListInterface {
                if ($field_name === 'field_workflow_status') {
                    return $this->createWorkflowStatusField($current_status_id);
                }
                if ($field_name === 'field_workflow_attributes') {
                    return $this->createWorkflowAttributesField(NULL);
                }
                  return $this->createWorkflowAttributesField(NULL);
              }
          );
    $application->expects($this->once())->method('allowWorkflowStatusReset');
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);
    $application->expects($this->once())->method('setRevisionUserId')->with(1);
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400);
    $application->expects($this->once())->method('setRevisionLogMessage')->with('zurück auf Fachprüfung');
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$current_status_id, &$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                if ($field_name === 'field_workflow_status') {
                    $current_status_id = (int) ($value['target_id'] ?? 0);
                }
                  return $application;
              }
          );
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $same_status_revision = $this->createWorkflowApplicationRevision(7, 20, 2, 1779780200);
    $previous_status_revision = $this->createWorkflowApplicationRevision(
          7,
          10,
          1,
          1779780100,
          '{"approved":true}'
      );

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->with('id', 7)->willReturnSelf();
    $query->method('sort')->with('revision_id', 'DESC')->willReturnSelf();
    $query->method('execute')->willReturn(
          [
            30 => 30,
            20 => 20,
            10 => 10,
          ]
      );

    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')
      ->willReturnMap(
              [
                [20, $same_status_revision],
                [10, $previous_status_revision],
              ]
          );

    [$status_storage, $resolver] = $this->rollbackTargetInWorkflow();
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')
      ->willReturnMap([
        ['application', $storage],
        ['workflow_status', $status_storage],
      ]);

    $controller = new ApplicationDataController($account, $time, $manager, new ApplicationCaseScope($manager), workflowResolver: $resolver);

    $response = $controller->rollbackStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":2,"expected_changed":1779780300,"note":"zurück auf Fachprüfung"}'),
      );

    $this->assertSame(['target_id' => 1], $writes['field_workflow_status']);
    $this->assertSame('{"approved":true}', $writes['field_workflow_attributes']);
    $this->assertSame(
          [
            'success' => TRUE,
            'revision_id' => 30,
            'target_status' => ['internalId' => 1],
          ], json_decode((string) $response->getContent(), TRUE)
      );
  }

  /**
   * Status updates without transition attributes persist an empty object.
   *
   * An empty JSON object ({}) decodes to [] in PHP; the controller must not
   * reject it and must store "{}" so Application::preSave() object-shape
   * validation passes.
   */
  public function testUpdateStatusAcceptsEmptyAttributesObject(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $current_status_id = 1;
    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(7);
    $application->method('getRevisionId')->willReturn(31);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('get')
      ->willReturnCallback(
              function (string $field_name) use (&$current_status_id): FieldItemListInterface {
                if ($field_name === 'field_workflow_status') {
                    return $this->createWorkflowStatusField($current_status_id);
                }
                  return $this->createWorkflowAttributesField(NULL);
              }
          );
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);
    $application->expects($this->once())->method('setRevisionUserId')->with(1);
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400);
    $application->expects($this->once())->method('setRevisionLogMessage')->with('Smoke-Notiz');
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$current_status_id, &$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                if ($field_name === 'field_workflow_status') {
                    $current_status_id = (int) ($value['target_id'] ?? 0);
                }
                  return $application;
              }
          );
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $time,
          $manager,
          new ApplicationCaseScope($manager),
      );

    $response = $controller->updateStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":1,"expected_changed":1779780300,"target_status_id":2,"attributes":{},"note":"Smoke-Notiz"}'),
      );

    $this->assertSame(['target_id' => 2], $writes['field_workflow_status']);
    $this->assertSame('{}', $writes['field_workflow_attributes']);
    $this->assertSame(
          [
            'success' => TRUE,
            'revision_id' => 31,
            'target_status' => ['internalId' => 2],
          ], json_decode((string) $response->getContent(), TRUE)
      );
  }

  /**
   * Status updates apply the target status SLA as an internal due date.
   */
  public function testUpdateStatusAppliesTargetStatusSlaDueDate(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $current_status_id = 1;
    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(7);
    $application->method('getRevisionId')->willReturn(31);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_due_date');
    $application->method('get')
      ->willReturnCallback(
              function (string $field_name) use (&$current_status_id): FieldItemListInterface {
                if ($field_name === 'field_workflow_status') {
                    return $this->createWorkflowStatusField($current_status_id);
                }
                  return $this->createWorkflowAttributesField(NULL);
              }
          );
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$current_status_id, &$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                if ($field_name === 'field_workflow_status') {
                    $current_status_id = (int) ($value['target_id'] ?? 0);
                }
                  return $application;
              }
          );
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $target_status = $this->createMock(WorkflowStatusInterface::class);
    $target_status->method('isTerminal')->willReturn(FALSE);
    $target_status->method('getSlaDays')->willReturn(3);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(2)->willReturn($target_status);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('workflow_status')->willReturn($storage);

    $controller = new ApplicationDataController(
          $account,
          $time,
          $manager,
          new ApplicationCaseScope($manager),
      );

    $controller->updateStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":1,"expected_changed":1779780300,"target_status_id":2,"attributes":{}}'),
      );

    $this->assertSame('2026-05-29', $writes['field_due_date']);
  }

  /**
   * Status updates preserve the submit-time citizen notification locale.
   */
  public function testUpdateStatusPreservesSubmissionLocale(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $current_status_id = 1;
    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(7);
    $application->method('getRevisionId')->willReturn(32);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('get')
      ->willReturnCallback(
              function (string $field_name) use (&$current_status_id): FieldItemListInterface {
                if ($field_name === 'field_workflow_status') {
                    return $this->createWorkflowStatusField($current_status_id);
                }
                  return $this->createWorkflowAttributesField('{"submissionLocale":"en"}');
              }
          );
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);
    $application->expects($this->once())->method('setRevisionUserId')->with(1);
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400);
    $application->expects($this->once())->method('setRevisionLogMessage')->with('');
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$current_status_id, &$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                if ($field_name === 'field_workflow_status') {
                    $current_status_id = (int) ($value['target_id'] ?? 0);
                }
                  return $application;
              }
          );
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $time,
          $manager,
          new ApplicationCaseScope($manager),
      );

    $response = $controller->updateStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":1,"expected_changed":1779780300,"target_status_id":2,"attributes":{}}'),
      );

    $this->assertSame(['target_id' => 2], $writes['field_workflow_status']);
    $this->assertSame('{"submissionLocale":"en"}', $writes['field_workflow_attributes']);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Status updates reject reserved submit-time locale mutations.
   */
  public function testUpdateStatusRejectsSubmissionLocaleAttribute(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('get')
      ->willReturnCallback(fn (): FieldItemListInterface => $this->createWorkflowStatusField(1));
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Workflow attributes contain reserved submission metadata.');

    $controller->updateStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":1,"expected_changed":1779780300,"target_status_id":2,"attributes":{"submissionLocale":"de"}}'),
      );
  }

  /**
   * Status updates still reject list-shaped workflow attributes.
   */
  public function testUpdateStatusRejectsListAttributes(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('get')
      ->willReturnCallback(fn (): FieldItemListInterface => $this->createWorkflowStatusField(1));
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationDataController(
          $account,
          $this->createMock(TimeInterface::class),
          $manager,
          new ApplicationCaseScope($manager),
      );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Workflow attributes must be an object.');

    $controller->updateStatus(
          $application,
          new Request([], [], [], [], [], [], '{"expected_status_id":1,"expected_changed":1779780300,"target_status_id":2,"attributes":[1,2]}'),
      );
  }

  /**
   * Builds a field item list mock that references a form schema entity.
   */
  private function createSchemaReferenceField(array $schema): FieldItemListInterface {
    return $this->createSchemaReferenceFieldForEntity($this->createFormSchemaMock($schema));
  }

  /**
   * Builds a field item list mock that references a concrete schema mock.
   */
  private function createSchemaReferenceFieldForEntity(FormSchemaInterface $schema_entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->with('entity')
      ->willReturn(TRUE);
    $field->method('__get')
      ->with('entity')
      ->willReturn($schema_entity);

    return $field;
  }

  /**
   * Creates a form schema entity mock.
   *
   * @param array<string, mixed> $schema
   *   Decoded schema payload.
   * @param int $version
   *   Form schema version.
   * @param string $uuid
   *   Form schema UUID.
   * @param string $label
   *   Form schema label.
   * @param int $changed
   *   Changed timestamp.
   * @param string $id
   *   Internal entity ID.
   * @param string|null $workflow_definition
   *   Workflow definition JSON.
   */
  private function createFormSchemaMock(
    array $schema,
    int $version = 1,
    string $uuid = 'schema-uuid',
    string $label = 'Formular',
    int $changed = 1779780000,
    string $id = '1',
    ?string $workflow_definition = NULL,
  ): FormSchemaInterface {
    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('id')->willReturn($id);
    $schema_entity->method('uuid')->willReturn($uuid);
    $schema_entity->method('label')->willReturn($label);
    $schema_entity->method('getSchema')
      ->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));
    $schema_entity->method('getWorkflowDefinition')->willReturn($workflow_definition);
    $schema_entity->method('getVersion')->willReturn($version);
    $schema_entity->method('getChangedTime')->willReturn($changed);
    return $schema_entity;
  }

  /**
   * Creates an application revision mock with a workflow status target id.
   */
  private function createWorkflowApplicationRevision(
    int $id,
    int $revision_id,
    int $status_id,
    int $created,
    ?string $workflow_attributes = NULL,
    bool $can_view = TRUE,
  ): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($id);
    $application->method('getRevisionId')->willReturn($revision_id);
    $application->method('getRevisionCreationTime')->willReturn($created);
    $application->method('access')->with('view', $this->anything())->willReturn($can_view);
    $application->method('getRevisionUser')->willReturn(NULL);
    $application->method('getRevisionLogMessage')->willReturn('');
    $application->method('get')
      ->willReturnCallback(
              fn (string $field_name): FieldItemListInterface => match ($field_name) {
                'field_workflow_status' => $this->createWorkflowStatusField($status_id),
                'field_workflow_attributes' => $this->createWorkflowAttributesField($workflow_attributes),
                default => $this->createWorkflowAttributesField(NULL),
              }
            );

    return $application;
  }

  /**
   * Builds a workflow status reference field mock.
   */
  private function createWorkflowStatusField(int $status_id, ?WorkflowStatusInterface $entity = NULL): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => in_array($property, ['target_id', 'entity'], TRUE));
    $field->method('__get')
      ->willReturnCallback(
              static fn (string $property): mixed => match ($property) {
                'target_id' => $status_id,
                'entity' => $entity,
                default => NULL,
              }
            );

    return $field;
  }

  /**
   * Creates a field item list mock exposing magic field properties.
   */
  private function fieldList(array $properties, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getValue')->willReturn($empty ? [] : [$properties]);
    $field->method('__isset')->willReturnCallback(
      static fn (string $name): bool => array_key_exists($name, $properties)
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $name): mixed => $properties[$name] ?? NULL
    );
    return $field;
  }

  /**
   * Builds a workflow attributes field mock.
   */
  private function createWorkflowAttributesField(?string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => $property === 'value');
    $field->method('__get')
      ->willReturnCallback(static fn (string $property): ?string => $property === 'value' ? $value : NULL);

    return $field;
  }

  /**
   * Doubles for a rollback whose target status 1 is in the form's workflow.
   *
   * @return array{0: \Drupal\Core\Entity\EntityStorageInterface, 1: \Drupal\beantragt_application\Service\EffectiveWorkflowResolver}
   *   The workflow_status storage and the resolver.
   */
  private function rollbackTargetInWorkflow(): array {
    $target_status = $this->createMock(WorkflowStatusInterface::class);
    $target_status->method('getGroupId')->willReturn(5);
    $status_storage = $this->createMock(EntityStorageInterface::class);
    $status_storage->method('load')->willReturnMap([[1, $target_status]]);
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('governingStatusIds')->willReturn([1, 2]);
    return [$status_storage, $resolver];
  }

}
