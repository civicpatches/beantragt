<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_content\Controller\AdminPublicDocumentController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the admin public document editor API controller.
 */
#[CoversClass(AdminPublicDocumentController::class)]
#[Group('beantragt_content')]
final class AdminPublicDocumentControllerTest extends UnitTestCase {

  /**
   * A missing file or retired placement is rejected before node creation.
   */
  #[DataProvider('invalidCreatePayloads')]
  public function testCreateRejectsInvalidPayloadBeforeNodeCreation(string $placement, int $status, string $field): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadByProperties')->willReturnCallback(
      static fn (array $properties): array => $properties === [
        'type' => 'jur',
        'field_slug' => 'musterstadt',
        'status' => 1,
      ] ? [$jurisdiction] : []
    );
    $group_storage->method('load')->with(3)->willReturn($jurisdiction);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->expects($this->never())->method('create');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $group_storage,
        'node' => $node_storage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $controller = new AdminPublicDocumentController(
      $entity_type_manager,
      $this->mockAccount(9, ['authenticated', 'department_admin']),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(FileUsageInterface::class),
      $this->createMock(AttachmentScanServiceInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $request = Request::create(
      '/api/admin/jurisdictions/musterstadt/documents',
      'POST',
      [],
      [],
      [],
      [],
      json_encode([
        'title' => 'Merkblatt Antragstellung',
        'status' => TRUE,
        'placement' => $placement,
      ], JSON_THROW_ON_ERROR),
    );

    $response = $controller->createDocument($request, 'musterstadt');
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame($status, $response->getStatusCode());
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame($field, $payload['error']['field']);
  }

  /**
   * Provides invalid document creation requests.
   */
  public static function invalidCreatePayloads(): iterable {
    yield 'missing file' => ['form_downloads', Response::HTTP_BAD_REQUEST, 'file'];
    yield 'retired footer' => ['footer', Response::HTTP_UNPROCESSABLE_ENTITY, 'placement'];
  }

  /**
   * Document mutation routes require a Drupal CSRF request token.
   */
  public function testDocumentMutationRoutesRequireCsrf(): void {
    $routing = Yaml::decode((string) file_get_contents(__DIR__ . '/../../../../beantragt_content.routing.yml'));

    $this->assertSame(
      'TRUE',
      $routing['beantragt_content.admin_documents_create']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
    $this->assertSame(
      'TRUE',
      $routing['beantragt_content.admin_documents_update']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
    $this->assertSame(
      'TRUE',
      $routing['beantragt_content.admin_documents_delete']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
    $this->assertSame(
      ['DELETE'],
      $routing['beantragt_content.admin_documents_delete']['methods'] ?? NULL,
    );
    $this->assertSame(
      ['GET'],
      $routing['beantragt_content.admin_documents_status']['methods'] ?? NULL,
    );
    $this->assertArrayNotHasKey(
      '_csrf_request_header_token',
      $routing['beantragt_content.admin_documents_status']['requirements'],
    );
    $this->assertArrayNotHasKey(
      '_csrf_request_header_token',
      $routing['beantragt_content.admin_documents']['requirements'],
    );
  }

  /**
   * Source references must be removed before the document can be deleted.
   */
  public function testSchemaReferenceGuardCoversContextAndPrecheckEvidence(): void {
    $source_uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $controller = $this->controller();
    $method = new \ReflectionMethod($controller, 'schemaReferencesDocument');

    $context_form = $this->createMock(FormSchemaInterface::class);
    $context_form->method('getSchema')->willReturn(json_encode([
      'contextSources' => [
        ['type' => 'form_attachment', 'id' => strtoupper($source_uuid)],
      ],
    ], JSON_THROW_ON_ERROR));
    self::assertTrue($method->invoke($controller, $context_form, $source_uuid));

    $precheck_form = $this->createMock(FormSchemaInterface::class);
    $precheck_form->method('getSchema')->willReturn(json_encode([
      'precheckSchema' => [
        'version' => 1,
        'criteria' => [
          [
            'evidence' => [
              'sourceType' => 'context_source',
              'sourceId' => strtoupper($source_uuid),
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    self::assertTrue($method->invoke($controller, $precheck_form, $source_uuid));

    $clean_form = $this->createMock(FormSchemaInterface::class);
    $clean_form->method('getSchema')->willReturn(json_encode([
      'contextSources' => [],
      'precheckSchema' => ['version' => 1, 'criteria' => []],
    ], JSON_THROW_ON_ERROR));
    self::assertFalse($method->invoke($controller, $clean_form, $source_uuid));

    $other_uppercase_source_form = $this->createMock(FormSchemaInterface::class);
    $other_uppercase_source_form->method('getSchema')->willReturn(json_encode([
      'contextSources' => [
        [
          'type' => 'public_document',
          'id' => strtoupper('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
        ],
      ],
      'precheckSchema' => [
        'version' => 1,
        'criteria' => [
          [
            'evidence' => [
              'sourceType' => 'context_source',
              'sourceId' => strtoupper('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    self::assertFalse($method->invoke($controller, $other_uppercase_source_form, $source_uuid));

    foreach ([
      [],
      ['contextSources' => 'invalid'],
      ['contextSources' => ['invalid']],
      ['contextSources' => ['id' => $source_uuid]],
      ['contextSources' => [['id' => $source_uuid]]],
      ['contextSources' => [['type' => 'public_document', 'id' => 'invalid']]],
      ['precheckSchema' => 'invalid'],
      ['precheckSchema' => []],
      ['precheckSchema' => ['version' => 1, 'criteria' => ['criterion' => []]]],
      ['precheckSchema' => ['criteria' => 'invalid']],
      ['precheckSchema' => ['criteria' => ['invalid']]],
      ['precheckSchema' => ['version' => 1, 'criteria' => [['id' => 'missing_evidence']]]],
      ['precheckSchema' => ['version' => 1, 'criteria' => [['evidence' => []]]]],
      ['precheckSchema' => ['criteria' => [['evidence' => 'invalid']]]],
      [
        'precheckSchema' => [
          'version' => 1,
          'criteria' => [
            [
              'evidence' => [
                'sourceType' => 'context_source',
                'sourceId' => 'invalid',
              ],
            ],
          ],
        ],
      ],
      [
        'precheckSchema' => [
          'version' => 1,
          'criteria' => [
            [
              'evidence' => [
                'sourceType' => 'form_source',
                'sourceId' => $source_uuid,
              ],
            ],
          ],
        ],
      ],
    ] as $malformed_schema) {
      $malformed_form = $this->createMock(FormSchemaInterface::class);
      $malformed_form->method('getSchema')->willReturn(json_encode($malformed_schema, JSON_THROW_ON_ERROR));
      self::assertTrue(
        $method->invoke($controller, $malformed_form, $source_uuid),
        'Structurally invalid source references must fail closed.',
      );
    }
  }

  /**
   * An unreferenced document and its unused private file can be deleted.
   */
  public function testDeleteDocumentRemovesNodeAndUnusedFile(): void {
    $response = $this->deleteDocumentResponse();
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'id' => '33333333-3333-4333-8333-333333333333',
      'deleted' => TRUE,
      'fileDeleted' => TRUE,
      'fileCleanup' => 'deleted',
    ], $payload['data']);
    self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * Active context or evidence references protect the document from deletion.
   */
  public function testDeleteDocumentRefusesActiveSchemaReference(): void {
    $source_uuid = '33333333-3333-4333-8333-333333333333';
    $response = $this->deleteDocumentResponse([
      'schema' => [
        'contextSources' => [
          ['type' => 'form_attachment', 'id' => $source_uuid],
        ],
      ],
      'expect_node_delete' => FALSE,
      'expect_file_delete' => FALSE,
    ]);

    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
  }

  /**
   * Document ownership, form binding, and form scope are independently hidden.
   */
  public function testDeleteDocumentHidesScopeAndBindingMismatches(): void {
    $wrong_form = $this->deleteDocumentResponse([
      'request_form_uuid' => '44444444-4444-4444-8444-444444444444',
      'expect_node_delete' => FALSE,
      'expect_file_delete' => FALSE,
    ]);
    self::assertSame(Response::HTTP_NOT_FOUND, $wrong_form->getStatusCode());

    $wrong_document_scope = $this->deleteDocumentResponse([
      'document_jurisdiction_id' => 4,
      'expect_node_delete' => FALSE,
      'expect_file_delete' => FALSE,
    ]);
    self::assertSame(Response::HTTP_NOT_FOUND, $wrong_document_scope->getStatusCode());

    $wrong_form_scope = $this->deleteDocumentResponse([
      'form_jurisdiction_id' => 4,
      'expect_node_delete' => FALSE,
      'expect_file_delete' => FALSE,
    ]);
    self::assertSame(Response::HTTP_NOT_FOUND, $wrong_form_scope->getStatusCode());
  }

  /**
   * Shared files remain when the document node is deleted.
   */
  public function testDeleteDocumentKeepsSharedFile(): void {
    $response = $this->deleteDocumentResponse([
      'file_usage' => ['other_module' => ['shared' => [17 => 1]]],
      'expect_file_delete' => FALSE,
    ]);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertFalse($payload['data']['fileDeleted']);
    self::assertSame('retained_in_use', $payload['data']['fileCleanup']);
  }

  /**
   * File cleanup failures are logged without lying about the node deletion.
   */
  public function testDeleteDocumentLogsFileCleanupFailure(): void {
    $response = $this->deleteDocumentResponse(['file_delete_throws' => TRUE]);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertTrue($payload['data']['deleted']);
    self::assertFalse($payload['data']['fileDeleted']);
    self::assertSame('cleanup_failed', $payload['data']['fileCleanup']);
  }

  /**
   * UUID-specific status distinguishes deletion, rebinding, and scope drift.
   */
  public function testDocumentStatusReportsExactLifecycleState(): void {
    foreach ([
      'active' => [],
      'unavailable_deleted' => ['document_exists' => FALSE],
      'rebound' => [
        'request_form_uuid' => '44444444-4444-4444-8444-444444444444',
      ],
      'unavailable_foreign_document' => ['document_jurisdiction_id' => 4],
      'rebound_foreign_form' => ['form_jurisdiction_id' => 4],
    ] as $case => $options) {
      $response = $this->deleteDocumentResponse([
        ...$options,
        'operation' => 'status',
      ]);
      $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

      self::assertSame(Response::HTTP_OK, $response->getStatusCode());
      self::assertSame(
        str_starts_with($case, 'unavailable') ? 'unavailable' : (str_starts_with($case, 'rebound') ? 'rebound' : 'active'),
        $payload['data']['state'],
      );
      self::assertSame('33333333-3333-4333-8333-333333333333', $payload['data']['id']);
      self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
  }

  /**
   * Builds a controller with inert collaborators.
   */
  private function controller(): AdminPublicDocumentController {
    return new AdminPublicDocumentController(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->mockAccount(9, ['authenticated', 'department_admin']),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(FileUsageInterface::class),
      $this->createMock(AttachmentScanServiceInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds and invokes the document deletion contract.
   *
   * @param array<string, mixed> $options
   *   Fixture overrides.
   */
  private function deleteDocumentResponse(array $options = []): Response {
    $source_uuid = '33333333-3333-4333-8333-333333333333';
    $form_uuid = '11111111-1111-4111-8111-111111111111';
    $operation = (string) ($options['operation'] ?? 'delete');
    $document_exists = ($options['document_exists'] ?? TRUE) === TRUE;
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $form_jurisdiction_id = (int) ($options['form_jurisdiction_id'] ?? 3);
    $form_jurisdiction = $form_jurisdiction_id === 3
      ? $jurisdiction
      : $this->mockGroup('jur', $form_jurisdiction_id, 'andere-stadt');

    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('uuid')->willReturn($form_uuid);
    $form->method('getSchema')->willReturn(json_encode(
      $options['schema'] ?? [
        'contextSources' => [],
        'precheckSchema' => ['version' => 1, 'criteria' => []],
      ],
      JSON_THROW_ON_ERROR,
    ));
    $form->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_jurisdiction',
    );
    $form->method('get')->with('field_jurisdiction')->willReturn(
      $this->referenceField($form_jurisdiction, $form_jurisdiction_id),
    );

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(71);
    if ($operation === 'delete' && ($options['file_delete_throws'] ?? FALSE) === TRUE) {
      $file->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('disk unavailable'));
    }
    elseif (($options['expect_file_delete'] ?? ($operation === 'delete' && $document_exists)) === TRUE) {
      $file->expects($this->once())->method('delete');
    }
    else {
      $file->expects($this->never())->method('delete');
    }

    $document_fields = [
      'field_jurisdiction' => $this->referenceField(
        $jurisdiction,
        (int) ($options['document_jurisdiction_id'] ?? 3),
      ),
      'field_form_schema' => $this->referenceField($form, 41),
      'field_public_document_file' => $this->referenceField($file, 71),
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('uuid')->willReturn($source_uuid);
    $node->method('hasField')->willReturnCallback(
      static fn (string $field): bool => isset($document_fields[$field]),
    );
    $node->method('get')->willReturnCallback(
      static fn (string $field): FieldItemListInterface => $document_fields[$field],
    );
    if (($options['expect_node_delete'] ?? ($operation === 'delete' && $document_exists)) === TRUE) {
      $node->expects($this->once())->method('delete');
    }
    else {
      $node->expects($this->never())->method('delete');
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($document_exists ? [17 => 17] : []);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadByProperties')->willReturn([$jurisdiction]);
    $group_storage->method('load')->with(3)->willReturn($jurisdiction);
    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('getQuery')->willReturn($query);
    $node_storage->method('load')->with('17')->willReturn($node);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $group_storage,
        'node' => $node_storage,
        default => throw new \InvalidArgumentException($entity_type_id),
      },
    );

    $file_usage = $this->createMock(FileUsageInterface::class);
    $file_usage->method('listUsage')->with($file)->willReturn($options['file_usage'] ?? []);
    $logger = $this->createMock(LoggerInterface::class);
    if ($operation === 'delete' && ($options['file_delete_throws'] ?? FALSE) === TRUE) {
      $logger->expects($this->once())->method('error');
    }
    else {
      $logger->expects($this->never())->method('error');
    }

    $controller = new AdminPublicDocumentController(
      $entity_type_manager,
      $this->mockAccount(9, ['authenticated', 'department_admin']),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $file_usage,
      $this->createMock(AttachmentScanServiceInterface::class),
      $logger,
    );
    $request = Request::create(
      '/api/admin/jurisdictions/musterstadt/documents/' . $source_uuid
        . '?form=' . ($options['request_form_uuid'] ?? $form_uuid),
      $operation === 'status' ? 'GET' : 'DELETE',
    );

    return $operation === 'status'
      ? $controller->documentStatus($request, 'musterstadt', $source_uuid)
      : $controller->deleteDocument($request, 'musterstadt', $source_uuid);
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(string $bundle, int $id, string $slug): GroupInterface {
    $fields = [
      'field_slug' => $this->fieldValue($slug),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn('Stadt Musterstadt');
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $group;
  }

  /**
   * Builds a scalar field list.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '' || $value === NULL);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => $property === 'value',
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity-reference field list.
   */
  private function referenceField(EntityInterface $entity, int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => in_array($property, ['entity', 'target_id'], TRUE),
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => $target_id,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds an account mock.
   *
   * @param int $id
   *   Account ID.
   * @param string[] $roles
   *   Role IDs.
   */
  private function mockAccount(int $id, array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

}
