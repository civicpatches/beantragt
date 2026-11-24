<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\Controller\CitizenApplicationController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the citizen-facing application API controller.
 */
#[CoversClass(CitizenApplicationController::class)]
#[Group('beantragt_application')]
final class CitizenApplicationControllerTest extends UnitTestCase {

  /**
   * Anonymous v1 application reads return a JSON auth error.
   */
  public function testListV1RejectsAnonymous(): void {
    $this->installContainer($this->account(0), $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(new Request());
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertSame('auth.required', $payload['error']['code']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Service accounts cannot use the citizen application read profile.
   */
  public function testListV1RejectsServiceAccountProfile(): void {
    $account = $this->account(42, ['authenticated', 'service_account'], TRUE);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(new Request());
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Public v1 citizen reads require an explicit jurisdiction scope.
   */
  public function testListV1RejectsMissingWorkspaceScope(): void {
    $account = $this->account(7, ['authenticated'], TRUE);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(new Request(['limit' => '20']));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('workspace.required', $payload['error']['code']);
  }

  /**
   * Invalid pagination is rejected before the entity query runs.
   */
  public function testListV1RejectsInvalidPagination(): void {
    $account = $this->account(7, ['authenticated'], TRUE);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(new Request(['limit' => '0']));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('pagination.invalid', $payload['error']['code']);
  }

  /**
   * The v1 list returns a paginated data/meta envelope without entity IDs.
   */
  public function testListV1ReturnsPaginatedExternalProjection(): void {
    $account = $this->account(7, ['authenticated'], TRUE, 'ada@example.org');
    $application = $this->application();
    $query = $this->query([41 => 41, 44 => 44, 45 => 45, 46 => 46]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->with([41 => 41, 44 => 44, 45 => 45, 46 => 46])->willReturn([
      41 => $this->application('4ce21a78-14de-4213-95d7-16d458b56a41', 'MS-BAU-2026-00004', FALSE),
      44 => $application,
      45 => $this->application('2f7cb7ac-9003-48a1-b174-b94858e2fa23', 'MS-BAU-2026-00006'),
      46 => $this->application('3018f542-65f7-4660-b18c-c8ff014390d3', 'MS-BAU-2026-00007'),
    ]);

    $this->installContainer($account, $this->entityTypeManager($storage, $this->groupStorage()));

    $response = $this->controller()->listV1(new Request([
      'jurisdiction' => 'musterstadt',
      'limit' => '2',
      'offset' => '0',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertCount(2, $payload['data']);
    $this->assertSame('application', $payload['data'][0]['type']);
    $this->assertSame('9a67f32b-93cc-48c5-8899-9d5470f57f44', $payload['data'][0]['id']);
    $this->assertArrayNotHasKey('entityId', $payload['data'][0]);
    // The delivery block belongs to the detail only.
    foreach ($payload['data'] as $item) {
      $this->assertArrayNotHasKey('delivery', $item);
    }
    $this->assertSame('applications', $payload['meta']['resource']);
    $this->assertSame(2, $payload['meta']['count']);
    $this->assertSame([
      'limit' => 2,
      'offset' => 0,
      'hasMore' => TRUE,
      'nextOffset' => 2,
    ], $payload['meta']['page']);
  }

  /**
   * The v1 detail response redacts schema-protected submitted data.
   */
  public function testDetailV1RedactsSubmittedData(): void {
    $account = $this->account(7, ['authenticated'], TRUE, 'ada@example.org');
    $application = $this->application();
    $query = $this->query([44 => 44]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->with(44)->willReturn($application);

    $this->installContainer($account, $this->entityTypeManager($storage, $this->groupStorage()));

    $response = $this->controller()->detailV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('application', $payload['data']['type']);
    $this->assertSame('9a67f32b-93cc-48c5-8899-9d5470f57f44', $payload['data']['id']);
    $this->assertSame('MS-BAU-2026-00005', $payload['data']['referenceNumber']);
    $this->assertSame('Bauantrag', $payload['data']['form']['title']);
    $this->assertSame('a25564c2-b6a4-4c7b-83b3-8e6e31fb3602', $payload['data']['form']['id']);
    $this->assertSame('in_review', $payload['data']['status']['code']);
    $this->assertSame('Ada Lovelace', $payload['data']['submittedData']['full_name']);
    $this->assertSame(
      [
        ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
      ],
      $payload['data']['submittedData']['evidence'],
    );
    $this->assertTrue($payload['meta']['protectedDataRedacted']);
    $this->assertArrayNotHasKey('entityId', $payload['data']);
  }

  /**
   * The v1 detail carries the delivery block from the latest record event.
   *
   * Only route, source and chosenAt (ISO like submittedAt); never the events,
   * uids or channels of the record.
   */
  public function testDetailV1ProjectsDelivery(): void {
    $record = [
      [
        'event' => 'route_chosen',
        'at' => 1762500000,
        'route' => 'portal',
        'source' => 'public_submit',
        'offered' => ['portal', 'postal'],
        'reason' => NULL,
      ],
      [
        'event' => 'route_changed',
        'at' => 1762600000,
        'receivedAt' => 1762590000,
        'route' => 'postal',
        'previous' => 'portal',
        'source' => 'staff',
        'byUid' => 7,
        'channel' => 'letter',
      ],
    ];
    $payload = $this->detailPayload($this->application(route: 'postal', record: $record));
    $this->assertSame([
      'route' => 'postal',
      'source' => 'staff',
      'chosenAt' => date(\DateTimeInterface::ATOM, 1762600000),
    ], $payload['data']['delivery']);
    $this->assertStringNotContainsString('byUid', json_encode($payload, JSON_THROW_ON_ERROR));
    $this->assertStringNotContainsString('letter', json_encode($payload['data']['delivery'], JSON_THROW_ON_ERROR));

    // Intake writes one route_chosen event.
    $payload = $this->detailPayload($this->application(route: 'postal', record: [$record[0]]));
    $this->assertSame([
      'route' => 'postal',
      'source' => 'public_submit',
      'chosenAt' => date(\DateTimeInterface::ATOM, 1762500000),
    ], $payload['data']['delivery']);

    // An application without a record: source and chosenAt are NULL.
    $payload = $this->detailPayload($this->application());
    $this->assertSame(['route' => 'portal', 'source' => NULL, 'chosenAt' => NULL], $payload['data']['delivery']);
  }

  /**
   * Reads the v1 detail of one application as its owner.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   */
  private function detailPayload(ApplicationInterface $application): array {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $storage->method('load')->with(44)->willReturn($application);
    $this->installContainer($this->account(7, ['authenticated'], TRUE, 'ada@example.org'), $this->entityTypeManager($storage, $this->groupStorage()));

    $response = $this->controller()->detailV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Citizen detail labels follow the form schema in the request language.
   */
  public function testFieldLabelsFollowSchemaAndLanguage(): void {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('getSchema')->willReturn(json_encode([
      'sections' => [
        [
          'id' => 'applicant',
          'fields' => [
            ['id' => 'given_name', 'label' => 'Vorname', 'i18n' => ['en' => ['label' => 'Given name']]],
            ['id' => 'consent', 'label' => ''],
            ['id' => 'breed', 'label' => 'Rasse', 'i18n' => ['en' => ['label' => '']]],
          ],
        ],
        ['id' => 'dog', 'fields' => [['id' => 'dog_name', 'label' => 'Name des Hundes']]],
      ],
    ], JSON_THROW_ON_ERROR));
    $method = new \ReflectionMethod(CitizenApplicationController::class, 'fieldLabels');

    foreach (['de' => 'Vorname', 'en' => 'Given name'] as $langcode => $expected) {
      $language = $this->createMock(LanguageInterface::class);
      $language->method('getId')->willReturn($langcode);
      $language_manager = $this->createMock(LanguageManagerInterface::class);
      $language_manager->method('getCurrentLanguage')->willReturn($language);
      $container = new ContainerBuilder();
      $container->set('language_manager', $language_manager);
      $container->set('entity_type.manager', $this->createMock(EntityTypeManagerInterface::class));
      \Drupal::setContainer($container);

      $labels = $method->invoke($this->controller(), $schema, $langcode);
      $this->assertSame(['given_name' => $expected, 'breed' => 'Rasse', 'dog_name' => 'Name des Hundes'], $labels);
    }
    $this->assertSame([], $method->invoke($this->controller(), NULL, 'de'));
  }

  /**
   * Installs the Drupal service container required by ControllerBase.
   */
  private function installContainer(AccountInterface $account, EntityTypeManagerInterface $entity_type_manager): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('entity_type.manager', $entity_type_manager);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getCurrentLanguage')->willReturn($language);
    $container->set('language_manager', $language_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates the controller under test.
   */
  private function controller(): CitizenApplicationController {
    return new CitizenApplicationController(
      new ApplicationDataProtectionService(
        \Drupal::entityTypeManager(),
        $this->createMock(LoggerInterface::class),
      ),
      new CitizenStatusProjection(\Drupal::entityTypeManager(), $this->createMock(ModuleHandlerInterface::class)),
      $this->createMock(ConfirmedApplicationMailbox::class),
    );
  }

  /**
   * Creates an account mock.
   */
  private function account(
    int $uid,
    array $roles = ['anonymous'],
    bool $can_view_own = FALSE,
    string $email = '',
  ): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    $account->method('getEmail')->willReturn($email);
    $account->method('hasPermission')
      ->willReturnCallback(
        static fn(string $permission): bool => $permission === 'view own application entities' && $can_view_own,
      );
    return $account;
  }

  /**
   * Creates an entity type manager for application storage.
   */
  private function entityTypeManager(
    EntityStorageInterface $application_storage,
    ?EntityStorageInterface $group_storage = NULL,
  ): EntityTypeManagerInterface {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')
      ->willReturnCallback(
        fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
          'application' => $application_storage,
          'group' => $group_storage ?? throw new \InvalidArgumentException($entity_type_id),
          default => throw new \InvalidArgumentException($entity_type_id),
        },
      );
    return $manager;
  }

  /**
   * Creates group storage for resolving the required jurisdiction slug.
   */
  private function groupStorage(): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([3 => 3]));
    $storage->method('load')->with(3)->willReturn($this->group(3));
    return $storage;
  }

  /**
   * Creates a minimal group entity mock.
   */
  private function group(int $id): FieldableEntityInterface {
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('hasField')->willReturn(FALSE);
    return $group;
  }

  /**
   * Creates an application entity query mock.
   *
   * @param array<int, int> $ids
   *   Entity query result IDs.
   * @param array{0: int, 1: int}|null $expected_range
   *   Expected range() start and length, or NULL when range() is not used.
   */
  private function query(array $ids, ?array $expected_range = NULL): QueryInterface {
    $or_group = $this->createMock(ConditionInterface::class);
    $or_group->method('condition')->willReturnSelf();

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    if ($expected_range === NULL) {
      $query->method('range')->willReturnSelf();
    }
    else {
      $query->expects($this->once())->method('range')->with($expected_range[0], $expected_range[1])->willReturnSelf();
    }
    $query->method('orConditionGroup')->willReturn($or_group);
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Creates an application mock with schema, status and submitted data.
   */
  private function application(
    string $uuid = '9a67f32b-93cc-48c5-8899-9d5470f57f44',
    string $reference = 'MS-BAU-2026-00005',
    bool $can_access = TRUE,
    string $route = 'portal',
    array $record = [],
  ): ApplicationInterface {
    $schema = $this->schema();
    $status = $this->workflowStatus();
    $fields = [
      'field_schema' => $this->entityReference($schema),
      'field_workflow_status' => $this->entityReference($status),
    ];

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getOwnerId')->willReturn(7);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn($uuid);
    $application->method('getReferenceNumber')->willReturn($reference);
    $application->method('getCreatedTime')->willReturn(1781431200);
    $application->method('getChangedTime')->willReturn(1781431300);
    $application->method('getData')
      ->willReturn('{"full_name":"Ada Lovelace","evidence":[{"filename":"scan.pdf","attachmentId":"1fded50b-9249-4af7-88a5-4ea2245a10bb"}]}');
    $application->method('getSchemaVersion')->willReturn(0);
    $application->method('access')->willReturn($can_access);
    $application->method('getDeliveryRoute')->willReturn($route);
    $application->method('getDeliveryRecord')->willReturn($record);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $application->method('get')
      ->willReturnCallback(fn(string $field_name): FieldItemListInterface => $fields[$field_name] ?? $this->emptyField());
    return $application;
  }

  /**
   * Creates a schema entity mock.
   */
  private function schema(): FormSchemaInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('uuid')->willReturn('a25564c2-b6a4-4c7b-83b3-8e6e31fb3602');
    $schema->method('label')->willReturn('Bauantrag');
    $schema->method('getVersion')->willReturn(1);
    $schema->method('getSchema')->willReturn(json_encode([
      'sections' => [
        [
          'fields' => [
            ['id' => 'full_name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    return $schema;
  }

  /**
   * Creates a workflow status entity mock.
   */
  private function workflowStatus(): FieldableEntityInterface {
    $fields = [
      'field_machine_name' => $this->fieldValue('in_review'),
      'field_color' => $this->fieldValue('blue'),
      'field_icon' => $this->fieldValue('hourglass'),
    ];

    $status = $this->createMock(FieldableEntityInterface::class);
    $status->method('label')->willReturn('In Pruefung');
    $status->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $status->method('get')
      ->willReturnCallback(fn(string $field_name): FieldItemListInterface => $fields[$field_name] ?? $this->emptyField());
    return $status;
  }

  /**
   * Builds an entity reference field list.
   */
  private function entityReference(object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn(string $property): bool => in_array($property, ['entity', 'target_id'], TRUE),
    );
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => method_exists($entity, 'id') ? $entity->id() : NULL,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds a scalar field list.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || $value === '');
    $field->method('getValue')->willReturn([['value' => $value]]);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createStub(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    $field->method('getValue')->willReturn([]);
    $field->method('__get')->willReturn(NULL);
    return $field;
  }

}
