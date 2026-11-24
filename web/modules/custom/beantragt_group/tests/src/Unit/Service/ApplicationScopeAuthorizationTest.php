<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_group\Service\ApplicationScopeAuthorization;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cheap application target-scope authorization paths.
 */
#[CoversClass(ApplicationScopeAuthorization::class)]
#[Group('beantragt_group')]
final class ApplicationScopeAuthorizationTest extends UnitTestCase {

  /**
   * Unchanged scope returns before any entity storage is requested.
   */
  public function testUnchangedScopeDoesNotLoadGroups(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects(self::never())->method('getStorage');
    $authorization = new ApplicationScopeAuthorization(
      $entity_type_manager,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
    );

    $original = $this->application([3, 4, 4]);
    $application = $this->application([3, 4, 4], $original);

    self::assertNull($authorization->findUnauthorizedTarget(
      $application,
      $this->createMock(AccountInterface::class),
    ));
  }

  /**
   * New and syncing applications are exempt before original or group loads.
   */
  public function testNewAndSyncingApplicationsAreExempt(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects(self::never())->method('getStorage');
    $authorization = new ApplicationScopeAuthorization(
      $entity_type_manager,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
    );
    $account = $this->createMock(AccountInterface::class);

    self::assertNull($authorization->findUnauthorizedTarget(
      $this->application([7, 8, 8], is_new: TRUE),
      $account,
    ));
    self::assertNull($authorization->findUnauthorizedTarget(
      $this->application([7, 8, 8], is_syncing: TRUE),
      $account,
    ));
  }

  /**
   * An unloadable original fails closed for a target the actor cannot manage.
   */
  public function testUnloadableOriginalFailsClosedForUnmanagedTarget(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturn(NULL);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);
    $authorization = new ApplicationScopeAuthorization(
      $entity_type_manager,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
    );

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(2);
    $account->method('getRoles')->willReturn(['authenticated']);

    $violation = $authorization->findUnauthorizedTarget(
      $this->application([5, 0, 0], application_id: 42),
      $account,
    );

    self::assertNotNull($violation);
    self::assertSame('field_jurisdiction', $violation['field_name']);
    self::assertSame(5, $violation['jurisdiction_id']);
  }

  /**
   * Failing closed on an unloadable original still exempts administrators.
   */
  public function testUnloadableOriginalStillAllowsAdministrator(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturn(NULL);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);
    $authorization = new ApplicationScopeAuthorization(
      $entity_type_manager,
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
    );

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $account->method('getRoles')->willReturn(['authenticated']);

    self::assertNull($authorization->findUnauthorizedTarget(
      $this->application([5, 0, 0], application_id: 42),
      $account,
    ));
  }

  /**
   * Creates an application mock with explicit scope fields.
   *
   * @param int[] $scope_ids
   *   Jurisdiction, organisation, and legacy target IDs.
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface|null $original
   *   The original application state.
   * @param bool $is_new
   *   Whether the application is new.
   * @param bool $is_syncing
   *   Whether the application is being synchronized.
   * @param int $application_id
   *   The application entity ID used for loadUnchanged() fallbacks.
   */
  private function application(
    array $scope_ids,
    ?ApplicationInterface $original = NULL,
    bool $is_new = FALSE,
    bool $is_syncing = FALSE,
    int $application_id = 1,
  ): ApplicationInterface {
    [$jurisdiction_id, $organisation_id, $legacy_id] = $scope_ids;
    $fields = [
      'field_jurisdiction' => $this->referenceField($jurisdiction_id),
      'field_organisation' => $this->referenceField($organisation_id),
      'field_group' => $this->referenceField($legacy_id),
    ];

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($application_id);
    $application->method('isNew')->willReturn($is_new);
    $application->method('isSyncing')->willReturn($is_syncing);
    $application->method('getOriginal')->willReturn($original);
    $application->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => isset($fields[$field_name]),
    );
    $application->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name],
    );
    return $application;
  }

  /**
   * Creates one entity-reference field mock.
   */
  private function referenceField(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($target_id <= 0);
    $field->method('getValue')->willReturn(
      $target_id > 0 ? [['target_id' => $target_id]] : [],
    );
    return $field;
  }

}
