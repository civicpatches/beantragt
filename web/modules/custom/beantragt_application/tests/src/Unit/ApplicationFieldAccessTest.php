<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/**
 * Tests application field access hooks.
 *
 * @group beantragt_application
 */
final class ApplicationFieldAccessTest extends UnitTestCase {

  /**
   * Generic API reads and writes cannot bypass the analysis encryption writer.
   */
  public function testAttachmentAnalysisMetadataAccessIsForbidden(): void {
    foreach (['view', 'edit'] as $operation) {
      $result = \beantragt_application_entity_field_access(
        $operation,
        $this->createFieldDefinition('application_attachment', 'field_analysis_metadata'),
        $this->createMock(AccountInterface::class),
      );
      $this->assertTrue($result->isForbidden());
    }
  }

  /**
   * Generic API field hops cannot discover the linked protected bulk case.
   */
  public function testBulkCaseReferenceViewIsForbiddenWithoutItems(): void {
    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', 'field_bulk_case'),
      $this->createMock(AccountInterface::class),
    );
    $this->assertTrue($result->isForbidden());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/beantragt_application.module';

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $container->set('beantragt_application.data_protection', new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    ));
    \Drupal::setContainer($container);
  }

  /**
   * Existing field_data edits must use the audited data endpoint.
   */
  public function testExistingFieldDataEditIsForbidden(): void {
    $application = $this->createApplication(FALSE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $result = \beantragt_application_entity_field_access(
      'edit',
      $this->createFieldDefinition('application', 'field_data'),
      $this->createMock(AccountInterface::class),
      $items,
    );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * New entities can still receive field_data during submission creation.
   */
  public function testNewFieldDataEditIsNeutral(): void {
    $application = $this->createApplication(TRUE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $result = \beantragt_application_entity_field_access(
      'edit',
      $this->createFieldDefinition('application', 'field_data'),
      $this->createMock(AccountInterface::class),
      $items,
    );

    $this->assertFalse($result->isForbidden());
  }

  /**
   * Derived fields are storage-managed and assignment edits require an audit.
   */
  #[DataProvider('derivedApplicationFieldProvider')]
  #[DataProvider('assignmentFieldProvider')]
  public function testExistingDerivedOrAssignmentFieldEditIsForbidden(string $field_name): void {
    $application = $this->createApplication(FALSE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $result = \beantragt_application_entity_field_access(
      'edit',
      $this->createFieldDefinition('application', $field_name),
      $this->createMock(AccountInterface::class),
      $items,
    );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Provides derived application fields protected from direct edits.
   *
   * @return iterable<string, array{string}>
   *   Field name cases.
   */
  public static function derivedApplicationFieldProvider(): iterable {
    yield 'applicant email' => ['field_applicant_email'];
    yield 'applicant email hash' => ['field_applicant_email_hash'];
    yield 'public status token hash' => ['field_public_status_token_hash'];
    yield 'profile consent' => ['field_profile_consent'];
    yield 'profile consent record' => ['field_profile_consent_record'];
    yield 'bank consent' => ['field_bank_consent'];
    yield 'bank consent record' => ['field_bank_consent_record'];
  }

  /**
   * The origin decides the entry columns of a staff data edit: never edited.
   *
   * Set at intake only; an API client editing it could turn a citizen
   * application into a batch application or vice versa.
   */
  public function testOriginEditIsForbidden(): void {
    foreach ([FALSE, TRUE] as $new) {
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('getEntity')->willReturn($this->createApplication($new));
      $result = \beantragt_application_entity_field_access(
        'edit',
        $this->createFieldDefinition('application', 'field_origin'),
        $this->createMock(AccountInterface::class),
        $items,
      );
      $this->assertTrue($result->isForbidden(), $new ? 'new' : 'existing');
    }
    // Reading and filtering by origin stay possible.
    $view = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', 'field_origin'),
      $this->createMock(AccountInterface::class),
    );
    $this->assertFalse($view->isForbidden());
  }

  /**
   * Assignment views require the dedicated assignment view permission.
   */
  #[DataProvider('staffActorFieldProvider')]
  public function testAssignmentViewIsForbiddenWithoutAssignmentViewPermission(string $field_name): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', $field_name),
      $account,
    );

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Assignment views stay available for users with assignment-view access.
   */
  #[DataProvider('assignmentViewPermissionFieldProvider')]
  public function testAssignmentViewIsNeutralWithAllowedPermission(string $allowed_permission, string $field_name): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === $allowed_permission);

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', $field_name),
      $account,
    );

    $this->assertFalse($result->isForbidden());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Provides permissions allowed to view assignment relationships.
   *
   * @return iterable<string, array{string}>
   *   Permission test cases.
   */
  public static function assignedToViewPermissionsProvider(): iterable {
    yield 'platform admin' => ['administer application entities'];
    yield 'scope bypass' => ['bypass beantragt group scope'];
    yield 'assignment viewer' => ['view workflow assignments'];
  }

  /**
   * Provides assignment fields and permissions allowed to view them.
   *
   * @return iterable<string, array{string, string}>
   *   Permission and field test cases.
   */
  public static function assignmentViewPermissionFieldProvider(): iterable {
    foreach (self::assignedToViewPermissionsProvider() as $permission_label => [$permission]) {
      foreach (self::staffActorFieldProvider() as $field_label => [$field_name]) {
        yield $permission_label . ' / ' . $field_label => [$permission, $field_name];
      }
    }
  }

  /**
   * Provides assignment fields protected by the audited assignment endpoint.
   *
   * @return iterable<string, array{string}>
   *   Field name cases.
   */
  public static function assignmentFieldProvider(): iterable {
    yield 'person assignment' => ['field_assigned_to'];
    yield 'team assignment' => ['field_assigned_team'];
  }

  /**
   * Provides fields that name staff accounts and need assignment-view access.
   *
   * @return iterable<string, array{string}>
   *   Field name cases.
   */
  public static function staffActorFieldProvider(): iterable {
    yield from self::assignmentFieldProvider();
    yield 'revision author' => ['revision_user'];
  }

  /**
   * Direct JSON:API reads of application PII fields use the staff API instead.
   */
  #[DataProvider('rawPiiFieldProvider')]
  public function testRawPiiFieldViewIsForbiddenWithoutPlatformAccess(string $field_name): void {
    $application = $this->createApplication(FALSE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', $field_name),
      $account,
      $items,
    );

    $this->assertTrue($result->isForbidden());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('user', $result->getCacheContexts());
  }

  /**
   * Platform-level operators keep emergency visibility into raw PII fields.
   */
  #[DataProvider('rawPiiFieldProvider')]
  public function testRawPiiFieldViewIsNeutralForPlatformAdmin(string $field_name): void {
    $application = $this->createApplication(FALSE);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === 'administer application entities');

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', $field_name),
      $account,
      $items,
    );

    $this->assertFalse($result->isForbidden());
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('user', $result->getCacheContexts());
  }

  /**
   * Provides raw PII fields protected from direct JSON:API reads.
   *
   * @return iterable<string, array{string}>
   *   Field name cases.
   */
  public static function rawPiiFieldProvider(): iterable {
    yield 'crm data' => ['field_crm_data'];
    yield 'applicant email' => ['field_applicant_email'];
    yield 'applicant email hash' => ['field_applicant_email_hash'];
    yield 'workflow attributes' => ['field_workflow_attributes'];
    yield 'profile consent record' => ['field_profile_consent_record'];
    yield 'bank consent record' => ['field_bank_consent_record'];
    yield 'delivery record' => ['field_delivery_record'];
  }

  /**
   * Delivery route and record are server-managed, also on new entities.
   *
   * JSON:API runs with read_only FALSE and case workers may edit any
   * application, so setReadOnly() alone would not stop a PATCH or POST.
   */
  #[DataProvider('deliveryFieldEditProvider')]
  public function testDeliveryFieldEditIsForbidden(string $field_name, bool $is_new, bool $with_items): void {
    $items = NULL;
    if ($with_items) {
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('getEntity')->willReturn($this->createApplication($is_new));
    }
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);

    $result = \beantragt_application_entity_field_access(
      'edit',
      $this->createFieldDefinition('application', $field_name),
      $account,
      $items,
    );

    $this->assertTrue($result->isForbidden());
    $this->assertSame('Delivery route and record are server-managed.', $result->getReason());
  }

  /**
   * Field, new entity, with items.
   *
   * @return iterable<string, array{string, bool, bool}>
   *   Cases.
   */
  public static function deliveryFieldEditProvider(): iterable {
    foreach (['field_delivery_route', 'field_delivery_record'] as $field_name) {
      yield $field_name . ' existing' => [$field_name, FALSE, TRUE];
      yield $field_name . ' new' => [$field_name, TRUE, TRUE];
      yield $field_name . ' without items' => [$field_name, FALSE, FALSE];
    }
  }

  /**
   * The current route stays readable; only the record is staff API only.
   */
  public function testDeliveryRouteViewIsNeutralForCaseWorker(): void {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($this->createApplication(FALSE));
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === 'edit any application entities');

    $route = \beantragt_application_entity_field_access('view', $this->createFieldDefinition('application', 'field_delivery_route'), $account, $items);
    $record = \beantragt_application_entity_field_access('view', $this->createFieldDefinition('application', 'field_delivery_record'), $account, $items);

    $this->assertFalse($route->isForbidden());
    $this->assertTrue($record->isForbidden());
    $this->assertSame('Delivery records must be read through the scoped staff API.', $record->getReason());
  }

  /**
   * JSON:API filter, sort and relationship paths cannot test hidden values.
   *
   * JSON:API checks these paths without items. The filter hook also covers
   * fields whose view access depends on the item.
   */
  #[DataProvider('filterProtectedFieldProvider')]
  public function testFilterOnProtectedFieldIsForbiddenWithoutPermissions(string $entity_type_id, string $field_name): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $definition = $this->createFieldDefinition($entity_type_id, $field_name);

    $filter = \beantragt_application_jsonapi_entity_field_filter_access($definition, $account);

    $this->assertTrue($filter->isForbidden(), $field_name);
    // The view rule refuses the same field for this account once it has a
    // value, which is what the filter would reveal.
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($this->createApplication(FALSE));
    $this->assertTrue(\beantragt_application_entity_field_access('view', $definition, $account, $items)->isForbidden(), $field_name);
  }

  /**
   * Fields whose view the field access hook can refuse.
   *
   * @return iterable<string, array{string, string}>
   *   Entity type and field name.
   */
  public static function filterProtectedFieldProvider(): iterable {
    foreach ([
      'field_data',
      'field_crm_data',
      'field_applicant_email',
      'field_applicant_email_hash',
      'field_public_status_token_hash',
      'field_workflow_attributes',
      'field_email_confirmed',
      'field_email_confirmation_token_hash',
      'field_email_confirmation_expires',
      'field_email_confirmed_time',
      'field_profile_consent_record',
      'field_bank_consent_record',
      'field_delivery_record',
      'field_bulk_case',
      'field_assigned_to',
      'field_assigned_team',
      'revision_user',
      'revision_log_message',
    ] as $field_name) {
      yield $field_name => ['application', $field_name];
    }
    yield 'attachment analysis metadata' => ['application_attachment', 'field_analysis_metadata'];
  }

  /**
   * Platform operators and assignment viewers keep the paths they may read.
   */
  public function testFilterFollowsPlatformAndAssignmentPermissions(): void {
    $admin = $this->createMock(AccountInterface::class);
    $admin->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === 'administer application entities');
    $bypass = $this->createMock(AccountInterface::class);
    $bypass->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === 'bypass beantragt group scope');
    $assignments = $this->createMock(AccountInterface::class);
    $assignments->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === 'view workflow assignments');
    $nobody = $this->createMock(AccountInterface::class);
    $nobody->method('hasPermission')->willReturn(FALSE);
    $filter = fn (string $field_name, AccountInterface $account) => \beantragt_application_jsonapi_entity_field_filter_access($this->createFieldDefinition('application', $field_name), $account);

    $restricted = [
      'field_delivery_record',
      'field_applicant_email',
      'field_data',
      'field_assigned_to',
    ];
    foreach ($restricted as $field_name) {
      $this->assertTrue($filter($field_name, $admin)->isNeutral(), $field_name);
      $this->assertTrue($filter($field_name, $bypass)->isNeutral(), $field_name);
      $this->assertContains('user.permissions', $filter($field_name, $nobody)->getCacheContexts(), $field_name);
    }
    foreach (['field_assigned_to', 'field_assigned_team', 'revision_user'] as $field_name) {
      $this->assertTrue($filter($field_name, $assignments)->isNeutral(), $field_name);
    }
    // A correction reason must not be inferred through filtering or sorting.
    foreach ([$admin, $bypass, $assignments, $nobody] as $account) {
      $this->assertTrue($filter('revision_log_message', $account)->isForbidden());
    }
    $this->assertTrue($filter('field_delivery_record', $assignments)->isForbidden());
    foreach (['field_bulk_case', 'field_public_status_token_hash'] as $field_name) {
      $this->assertTrue($filter($field_name, $admin)->isForbidden(), $field_name);
      $definition = $this->createFieldDefinition('application', $field_name);
      $this->assertTrue(\beantragt_application_entity_field_access('view', $definition, $admin)->isForbidden(), $field_name);
    }
    // Readable fields stay filterable for everyone.
    foreach (['field_delivery_route', 'field_origin', 'field_workflow_status', 'created'] as $field_name) {
      $this->assertTrue($filter($field_name, $nobody)->isNeutral(), $field_name);
    }
    $this->assertTrue(\beantragt_application_jsonapi_entity_field_filter_access($this->createFieldDefinition('form_schema', 'field_applicant_email'), $nobody)->isNeutral());
  }

  /**
   * Sensitive field_data is not exposed through direct JSON:API field access.
   */
  public function testSensitiveFieldDataViewIsForbiddenWithoutRevealAccess(): void {
    $application = $this->createApplicationWithSchema([
      'sections' => [
        [
          'fields' => [
            ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
          ],
        ],
      ],
    ]);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', 'field_data'),
      $account,
      $items,
    );

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Non-sensitive field_data keeps the default entity field access behavior.
   */
  public function testNonSensitiveFieldDataViewIsNeutral(): void {
    $application = $this->createApplicationWithSchema([
      'sections' => [
        [
          'fields' => [
            ['id' => 'destination', 'label' => 'Ziel'],
          ],
        ],
      ],
    ]);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', 'field_data'),
      $account,
      $items,
    );

    $this->assertFalse($result->isForbidden());
  }

  /**
   * Platform administrators can still read protected field_data.
   */
  public function testSensitiveFieldDataViewIsNeutralForPlatformAdmin(): void {
    $application = $this->createApplicationWithSchema([
      'sections' => [
        [
          'fields' => [
            ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
          ],
        ],
      ],
    ]);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getEntity')->willReturn($application);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(static fn (string $permission): bool => $permission === 'administer application entities');

    $result = \beantragt_application_entity_field_access(
      'view',
      $this->createFieldDefinition('application', 'field_data'),
      $account,
      $items,
    );

    $this->assertFalse($result->isForbidden());
  }

  /**
   * Creates a field definition mock.
   */
  private function createFieldDefinition(string $entity_type_id, string $field_name): FieldDefinitionInterface {
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getTargetEntityTypeId')->willReturn($entity_type_id);
    $definition->method('getName')->willReturn($field_name);

    return $definition;
  }

  /**
   * Creates an application mock with cacheability metadata.
   */
  private function createApplication(bool $is_new): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isNew')->willReturn($is_new);
    $application->method('isDefaultRevision')->willReturn(TRUE);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:1']);
    $application->method('getCacheMaxAge')->willReturn(-1);

    return $application;
  }

  /**
   * Creates an existing application mock that references a schema.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   */
  private function createApplicationWithSchema(array $schema): ApplicationInterface {
    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('getSchema')->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));
    $schema_entity->method('getVersion')->willReturn(1);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->with('entity')
      ->willReturn(TRUE);
    $field->method('__get')
      ->with('entity')
      ->willReturn($schema_entity);

    $application = $this->createApplication(FALSE);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_schema');
    $application->method('get')
      ->with('field_schema')
      ->willReturn($field);
    $application->method('getSchemaVersion')->willReturn(1);

    return $application;
  }

}
