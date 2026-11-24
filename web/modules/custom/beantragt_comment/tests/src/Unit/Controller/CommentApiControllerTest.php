<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_comment\Unit\Controller;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_comment\Controller\CommentApiController;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the application comment API controller.
 */
#[CoversClass(CommentApiController::class)]
#[Group('beantragt_comment')]
final class CommentApiControllerTest extends UnitTestCase {

  /**
   * Public comment access requires a verified account address or UID ownership.
   */
  public function testPublicCommentsRequireVerifiedAccountMail(): void {
    $account = $this->account(42, ['authenticated'], [], 'citizen@example.test');
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('getOwnerId')->willReturn(99);
    $mailbox = $this->createMock(ConfirmedApplicationMailbox::class);
    $mailbox->expects($this->exactly(2))->method('allowsRead')->with($application, $account)->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $mailbox->expects($this->once())->method('auditRead')->with($application, $account);
    $method = new \ReflectionMethod(CommentApiController::class, 'canAccessApplicationComments');
    $controller = $this->controller(confirmed_mailbox: $mailbox);
    $this->assertFalse($method->invoke($controller, $application));
    $this->assertTrue($method->invoke($controller, $application));
  }

  /**
   * Anonymous v1 comment reads return a JSON auth error.
   */
  public function testListV1RejectsAnonymous(): void {
    $this->installContainer($this->account(0), $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertSame('auth.required', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Service accounts cannot use v1 citizen comment endpoints.
   */
  public function testListV1RejectsServiceAccount(): void {
    $account = $this->account(42, ['authenticated', 'service_account'], [
      'view application_comment entities',
    ]);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Staff profiles are not allowed through the public citizen comment API.
   */
  public function testListV1RejectsStaffProfile(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view application_comment entities',
    ]);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Public v1 comment reads require an explicit jurisdiction scope.
   */
  public function testListV1RejectsMissingWorkspaceScope(): void {
    $account = $this->account(7, ['authenticated'], [
      'view application_comment entities',
    ]);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['limit' => '20']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('workspace.required', $payload['error']['code']);
  }

  /**
   * A different citizen cannot read another citizen's comments.
   */
  public function testListV1HidesApplicationFromDifferentCitizen(): void {
    $account = $this->account(9, ['authenticated'], [
      'view application_comment entities',
    ]);
    $this->installContainer(
      $account,
      $this->entityTypeManager($this->storageForApplication($this->application()), $this->createMock(EntityStorageInterface::class), $this->groupStorage()),
    );

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('application.not_found', $payload['error']['code']);
  }

  /**
   * Comment mutations require the same CSRF header as other session writes.
   */
  public function testAddV1RejectsMissingCsrfToken(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $response = $this->controller()->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt'], [], [], [], [], [], '{}'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('csrf.invalid', $payload['error']['code']);
  }

  /**
   * Invalid CSRF headers are rejected before comment creation.
   */
  public function testAddV1RejectsInvalidCsrfToken(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $this->installContainer($account, $this->createMock(EntityTypeManagerInterface::class));

    $request = new Request(['jurisdiction' => 'musterstadt'], [], [], [], [], [], '{}');
    $request->headers->set('X-CSRF-Token', 'invalid-csrf');

    $response = $this->controller($this->validCsrfToken())->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('csrf.invalid', $payload['error']['code']);
  }

  /**
   * The v1 list returns UUID-only comment projections.
   */
  public function testListV1ReturnsExternalCommentProjection(): void {
    $account = $this->account(7, ['authenticated'], [
      'view application_comment entities',
    ]);
    $application_storage = $this->storageForApplication($this->application());
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $query_conditions = [];
    $comment_storage->method('getQuery')->willReturn($this->query([5 => 5], $query_conditions));
    $comment_storage->method('loadMultiple')->with([5 => 5])->willReturn([
      5 => $this->comment(),
    ]);

    $this->installContainer(
      $account,
      $this->entityTypeManager($application_storage, $comment_storage, $this->groupStorage()),
    );

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('application_comment', $payload['data'][0]['type']);
    $this->assertSame('46f0622f-7b19-42d4-8be9-cb8d1f99bc27', $payload['data'][0]['id']);
    $this->assertSame('Danke fuer die Rueckmeldung.', $payload['data'][0]['body']);
    $this->assertSame('citizen', $payload['data'][0]['authorRole']);
    $this->assertSame('public', $payload['data'][0]['visibility']);
    $this->assertSame([], $payload['data'][0]['attachments']);
    $this->assertArrayNotHasKey('entityId', $payload['data'][0]);
    $this->assertSame('applicationComments', $payload['meta']['resource']);
    $this->assertSame(1, $payload['meta']['count']);
    $this->assertQueryCondition($query_conditions, 'field_visibility', 'public');
  }

  /**
   * The v1 application lookup delegates workspace scoping to the shared guard.
   */
  public function testListV1AppliesResolvedOrganisationWorkspaceScope(): void {
    $account = $this->account(7, ['authenticated'], [
      'view application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');
    $application_storage = $this->storageForApplication($this->application());
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->method('getQuery')->willReturn($this->query([]));
    $comment_storage->method('loadMultiple')->with([])->willReturn([]);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->expects($this->exactly(2))
      ->method('getQuery')
      ->willReturnOnConsecutiveCalls(
        $this->query([3 => 3]),
        $this->query([4 => 4]),
      );
    $group_storage->method('load')
      ->willReturnCallback(static fn(int|string $id): ?GroupInterface => match ((int) $id) {
        3 => $jurisdiction,
        4 => $organisation,
        default => NULL,
      });

    $this->installContainer(
      $account,
      $this->entityTypeManager($application_storage, $comment_storage, $group_storage),
    );

    $tenant_scoped_entity_query = $this->createMock(TenantScopedEntityQuery::class);
    $tenant_scoped_entity_query->expects($this->once())
      ->method('applyWorkspaceScope')
      ->with(
        $this->isInstanceOf(QueryInterface::class),
        'application',
        $this->identicalTo($jurisdiction),
        $this->identicalTo($organisation),
      );

    $response = $this->controller(
      tenant_scoped_entity_query: $tenant_scoped_entity_query,
    )->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'bauamt',
      ]),
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * The v1 lookup uses the legacy group when the explicit office is absent.
   */
  public function testListV1WorkspaceScopeIncludesLegacyGroupFallback(): void {
    $account = $this->account(7, ['authenticated'], [
      'view application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');
    $query_conditions = [];
    $condition_groups = [];

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')
      ->willReturn($this->queryWithConditionGroups([44 => 44], $query_conditions, $condition_groups));
    $application_storage->method('load')->with(44)->willReturn($this->application());

    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->method('getQuery')->willReturn($this->query([]));
    $comment_storage->method('loadMultiple')->with([])->willReturn([]);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->expects($this->exactly(2))
      ->method('getQuery')
      ->willReturnOnConsecutiveCalls(
        $this->query([3 => 3]),
        $this->query([4 => 4]),
      );
    $group_storage->method('load')
      ->willReturnCallback(static fn(int|string $id): ?GroupInterface => match ((int) $id) {
        3 => $jurisdiction,
        4 => $organisation,
        default => NULL,
      });

    $manager = $this->entityTypeManager($application_storage, $comment_storage, $group_storage);
    $this->installContainer($account, $manager);

    $response = $this->controller(
      tenant_scoped_entity_query: new TenantScopedEntityQuery(
        $manager,
        $this->fieldManagerWithApplicationScopeFields(),
      ),
    )->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'bauamt',
      ]),
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertQueryCondition($query_conditions, 'uuid', '9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $legacy_groups = array_values(array_filter(
      $condition_groups,
      static fn (array $group): bool => $group['type'] === 'AND'
        && ($group['conditions'][0][0] ?? NULL) === 'field_group',
    ));
    $this->assertCount(1, $legacy_groups);
    $this->assertSame(['field_group', [4], 'IN'], array_slice($legacy_groups[0]['conditions'][0], 0, 3));
    $this->assertSame(['field_organisation'], $legacy_groups[0]['notExists']);
  }

  /**
   * The v1 citizen list never exposes internal staff notes.
   */
  public function testListV1HidesInternalComments(): void {
    $account = $this->account(7, ['authenticated'], [
      'view application_comment entities',
    ]);
    $application_storage = $this->storageForApplication($this->application());
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $query_conditions = [];
    $comment_storage->method('getQuery')->willReturn($this->query([], $query_conditions));
    $comment_storage->method('loadMultiple')->with([])->willReturn([]);

    $this->installContainer(
      $account,
      $this->entityTypeManager($application_storage, $comment_storage, $this->groupStorage()),
    );

    $response = $this->controller()->listV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request(['jurisdiction' => 'musterstadt']),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $payload['data']);
    $this->assertSame(0, $payload['meta']['count']);
    $this->assertQueryCondition($query_conditions, 'field_visibility', 'public');
  }

  /**
   * The v1 create endpoint stores sanitized text comments.
   */
  public function testAddV1CreatesSanitizedTextComment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $created_comment = $this->comment(body: 'Hallo Amt');
    $created_comment->expects($this->once())->method('save');

    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->with([
        'field_application' => 44,
        'field_body' => 'Hallo Amt',
        'field_author_role' => 'citizen',
        'field_visibility' => 'public',
        'uid' => 7,
        'status' => 1,
      ])
      ->willReturn($created_comment);

    $this->installContainer(
      $account,
      $this->entityTypeManager($this->storageForApplication($this->application()), $comment_storage, $this->groupStorage()),
    );

    $request = new Request(
      ['jurisdiction' => 'musterstadt'],
      [],
      [],
      [],
      [],
      [],
      json_encode([
        'data' => [
          'type' => 'application_comment',
          'attributes' => [
            'body' => '<strong>Hallo Amt</strong>',
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->never())->method('violationForJurisdiction');

    $response = $this->controller(
      $this->validCsrfToken(),
      attachment_storage_quota: $quota,
    )->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertSame('applicationComment', $payload['meta']['resource']);
    $this->assertSame('Hallo Amt', $payload['data']['body']);
    $this->assertSame('46f0622f-7b19-42d4-8be9-cb8d1f99bc27', $payload['data']['id']);
  }

  /**
   * Legacy comment creation only links files scoped to the same application.
   */
  public function testAddIgnoresAttachmentFromDifferentApplicationDirectory(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $foreign_file = $this->createMock(FileInterface::class);
    $foreign_file->method('getOwnerId')->willReturn(7);
    $foreign_file->method('getFileUri')->willReturn('private://application_comments/45/nachweis.pdf');
    $foreign_file->expects($this->never())->method('setPermanent');
    $foreign_file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->once())->method('load')->with(321)->willReturn($foreign_file);

    $created_comment = $this->comment(body: 'Bitte pruefen.');
    $created_comment->expects($this->once())->method('save');

    $created_values = NULL;
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($created_comment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->application()),
        $comment_storage,
        $this->groupStorage(),
        file_storage: $file_storage,
      ),
    );

    $response = $this->controller()->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => 'Bitte pruefen.',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertIsArray($created_values);
    $this->assertArrayNotHasKey('field_attachments', $created_values);
  }

  /**
   * Attachment-only messages cannot use files belonging to another application.
   */
  public function testAttachmentOnlyRejectsForeignAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $foreign_file = $this->createMock(FileInterface::class);
    $foreign_file->method('getOwnerId')->willReturn(7);
    $foreign_file->method('getFileUri')->willReturn('private://application_comments/45/nachweis.pdf');
    $foreign_file->expects($this->never())->method('setPermanent');
    $foreign_file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->once())->method('load')->with(321)->willReturn($foreign_file);

    $created_comment = $this->comment(body: 'Bitte pruefen.');
    $created_comment->expects($this->never())->method('save');

    $created_values = NULL;
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($created_comment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->application()),
        $comment_storage,
        $this->groupStorage(),
        file_storage: $file_storage,
      ),
    );

    $response = $this->controller()->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => '',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $this->assertIsArray($created_values);
    $this->assertArrayNotHasKey('field_attachments', $created_values);
  }

  /**
   * Attachment-only messages cannot claim uploads owned by another user.
   */
  public function testAttachmentOnlyRejectsAnotherUsersAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $foreign_file = $this->createMock(FileInterface::class);
    $foreign_file->method('getOwnerId')->willReturn(8);
    $foreign_file->method('getFileUri')->willReturn('private://application_comments/44/nachweis.pdf');
    $foreign_file->expects($this->never())->method('setPermanent');
    $foreign_file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->once())->method('load')->with(321)->willReturn($foreign_file);

    $created_comment = $this->comment(body: '');
    $created_comment->expects($this->never())->method('save');
    $created_comment->expects($this->never())->method('set')->with('field_attachments', $this->anything());

    $created_values = NULL;
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($created_comment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->application()),
        $comment_storage,
        $this->groupStorage(),
        file_storage: $file_storage,
      ),
    );

    $response = $this->controller()->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => '',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $this->assertIsArray($created_values);
    $this->assertArrayNotHasKey('field_attachments', $created_values);
  }

  /**
   * Legacy claims honor the lock shared with cleanup and v1 claims.
   */
  #[DataProvider('lockedAttachmentBodyProvider')]
  public function testLegacyAddRejectsAttachmentWhileClaimLockIsHeld(string $body): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $application = $this->application();
    $path = $this->pngFile();

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getOwnerId')->willReturn(7);
    $file->method('getFileUri')->willReturn('private://application_comments/44/nachreichung.png');
    $file->method('getMimeType')->willReturn('image/png');
    $file->method('getFilename')->willReturn('nachreichung.png');
    $file->method('getSize')->willReturn(68);
    $file->expects($this->never())->method('setPermanent');
    $file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->once())->method('load')->with(321)->willReturn($file);
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'field_file' => 321,
        'field_field_id' => 'comment_attachment',
        'field_upload_status' => 'uploaded',
      ])
      ->willReturn([$this->commentAttachment('unused-legacy-token')]);

    $comment = $this->comment(body: 'Bitte prüfen.');
    $comment->expects($this->never())->method('save');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())->method('create')->willReturn($comment);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')
      ->with('private://application_comments/44/nachreichung.png')
      ->willReturn($path);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_comment.attachment_claim.77', 30.0)
      ->willReturn(FALSE);
    $lock->expects($this->never())->method('release');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $comment_storage,
        $this->groupStorage(),
        $attachment_storage,
        $file_storage,
      ),
    );

    $response = $this->controller(lock: $lock, file_system: $file_system)->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => $body,
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );
    @unlink($path);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(
      ['error' => 'Attachment claim is currently being processed.'],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Supplies the body for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function lockedAttachmentBodyProvider(): array {
    return [
      'with text' => ['Bitte prüfen.'],
      'attachment only' => [''],
    ];
  }

  /**
   * A valid attachment-only message stores an empty body after claim checks.
   */
  public function testAttachmentOnlyStoresEmptyBodyWithValidAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $application = $this->application();
    $path = $this->pngFile();

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getOwnerId')->willReturn(7);
    $file->method('getFileUri')->willReturn('private://application_comments/44/nachreichung.png');
    $file->method('getMimeType')->willReturn('image/png');
    $file->method('getFilename')->willReturn('nachreichung.png');
    $file->method('getSize')->willReturn(68);
    $file->expects($this->once())->method('setPermanent');
    $file->expects($this->once())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->exactly(2))->method('load')->with(321)->willReturn($file);
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->expects($this->exactly(2))
      ->method('loadByProperties')
      ->with([
        'field_file' => 321,
        'field_field_id' => 'comment_attachment',
        'field_upload_status' => 'uploaded',
      ])
      ->willReturn([$this->commentAttachment('unused-legacy-token')]);

    $comment = $this->comment(body: '');
    $comment->expects($this->once())->method('save');
    $comment->expects($this->once())->method('set')->with('field_attachments', [['target_id' => 321]])->willReturnSelf();
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())->method('create')->with($this->callback(static fn(array $values): bool => $values['field_body'] === ''))->willReturn($comment);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')
      ->with('private://application_comments/44/nachreichung.png')
      ->willReturn($path);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $comment_storage,
        $this->groupStorage(),
        $attachment_storage,
        $file_storage,
      ),
    );

    $response = $this->controller(file_system: $file_system, time: $this->fixedTime())->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => '',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );
    @unlink($path);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('', json_decode((string) $response->getContent(), TRUE)['data']['body']);
  }

  /**
   * Legacy comment claims reload files after acquiring the claim lock.
   */
  public function testLegacyAddRejectsFileRemovedBeforeClaimLock(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $application = $this->application();
    $path = $this->pngFile();

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getOwnerId')->willReturn(7);
    $file->method('getFileUri')->willReturn('private://application_comments/44/nachreichung.png');
    $file->method('getMimeType')->willReturn('image/png');
    $file->method('getFilename')->willReturn('nachreichung.png');
    $file->method('getSize')->willReturn(68);
    $file->expects($this->never())->method('setPermanent');
    $file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->exactly(2))
      ->method('load')
      ->with(321)
      ->willReturnOnConsecutiveCalls($file, NULL);
    $file_storage->expects($this->once())->method('resetCache')->with([321]);
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn([$this->commentAttachment('unused-legacy-token')]);
    $attachment_storage->expects($this->once())->method('resetCache')->with([77]);

    $comment = $this->comment(body: 'Bitte prüfen.');
    $comment->expects($this->never())->method('save');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())->method('create')->willReturn($comment);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')
      ->with('private://application_comments/44/nachreichung.png')
      ->willReturn($path);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_comment.attachment_claim.77', 30.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_comment.attachment_claim.77');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $comment_storage,
        $this->groupStorage(),
        $attachment_storage,
        $file_storage,
      ),
    );

    $response = $this->controller(lock: $lock, file_system: $file_system)->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => 'Bitte prüfen.',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );
    @unlink($path);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(
      ['error' => 'Attachment claim is invalid.'],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Legacy comment reads deny staff outside the application workspace.
   */
  public function testLegacyListDeniesStaffOutsideApplicationWorkspace(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->never())->method('getQuery');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationInWorkspace($jurisdiction, $organisation)),
        $comment_storage,
        $this->groupStorage($jurisdiction, $organisation),
        relationship_storage: $this->relationshipStorage([]),
      ),
    );

    $response = $this->controller()->list('44');

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Legacy comment creates deny staff outside the application workspace.
   */
  public function testLegacyAddDeniesStaffOutsideApplicationWorkspace(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'create application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->never())->method('create');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationInWorkspace($jurisdiction, $organisation)),
        $comment_storage,
        $this->groupStorage($jurisdiction, $organisation),
        relationship_storage: $this->relationshipStorage([]),
      ),
    );

    $response = $this->controller()->add(
      '44',
      new Request([], [], [], [], [], [], '{"body":"Bitte pruefen."}'),
    );

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Legacy comment attachment uploads deny staff outside the workspace.
   */
  public function testLegacyUploadDeniesStaffOutsideApplicationWorkspace(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'create application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationInWorkspace($jurisdiction, $organisation)),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage($jurisdiction, $organisation),
        relationship_storage: $this->relationshipStorage([]),
      ),
    );

    $response = $this->controller()->upload(
      '44',
      new Request(),
    );

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Legacy comment attachment uploads reject malware signatures before save.
   */
  public function testLegacyUploadRejectsEicarAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->never())->method('writeData');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationWithSchema()),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage(),
      ),
    );

    $request = Request::create(
      '/api/comments/44/upload',
      'POST',
      [],
      [],
      ['file' => new UploadedFile($this->eicarPdfFile(), 'nachreichung.pdf', 'application/pdf', NULL, TRUE)],
    );

    $response = $this->controller(
      file_repository: $file_repository,
    )->upload(
      '44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Attachment was quarantined by malware scanning.', $payload['error']);
  }

  /**
   * Legacy comment creation rejects stale unscanned EICAR file ids.
   */
  public function testLegacyAddRejectsEicarAttachmentId(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getOwnerId')->willReturn(7);
    $file->method('getFileUri')->willReturn('private://application_comments/44/nachreichung.pdf');
    $file->method('getMimeType')->willReturn('application/pdf');
    $file->method('getFilename')->willReturn('nachreichung.pdf');
    $file->expects($this->never())->method('setPermanent');
    $file->expects($this->never())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->once())->method('load')->with(321)->willReturn($file);
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('realpath')
      ->with('private://application_comments/44/nachreichung.pdf')
      ->willReturn($this->eicarPdfFile());

    $created_comment = $this->comment(body: 'Bitte pruefen.');
    $created_comment->expects($this->never())->method('save');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->willReturn($created_comment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->application()),
        $comment_storage,
        $this->groupStorage(),
        file_storage: $file_storage,
      ),
    );

    $response = $this->controller(file_system: $file_system)->add(
      '44',
      new Request(
        [],
        [],
        [],
        [],
        [],
        [],
        json_encode([
          'body' => 'Bitte pruefen.',
          'attachment_ids' => [321],
        ], JSON_THROW_ON_ERROR),
      ),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Attachment was quarantined by malware scanning.', $payload['error']);
  }

  /**
   * Legacy comment attachment downloads deny staff outside the workspace.
   */
  public function testDownloadAttachmentDeniesStaffOutsideApplicationWorkspace(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationInWorkspace($jurisdiction, $organisation)),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage($jurisdiction, $organisation),
        file_storage: $this->createMock(EntityStorageInterface::class),
        relationship_storage: $this->relationshipStorage([]),
      ),
    );

    $this->expectException(NotFoundHttpException::class);
    $this->controller()->downloadAttachment(
      '44',
      'bdd888bb-33a4-4d60-8252-28c1e0c22004',
    );
  }

  /**
   * Jurisdiction-only cases require a jurisdiction admin for staff messages.
   */
  public function testLegacyListDeniesJurisdictionOnlyMember(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view application_comment entities']);
    $jurisdiction = $this->group(3, 'jur');
    $application = $this->applicationInWorkspace($jurisdiction, NULL);
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->never())->method('getQuery');
    $this->installContainer($account, $this->entityTypeManager(
      $this->storageForApplication($application), $comment_storage,
      $this->groupStorage($jurisdiction),
      relationship_storage: $this->relationshipStorage([1 => ['jur-member']]),
    ));
    $this->assertSame(Response::HTTP_NOT_FOUND, $this->controller()->list('44')->getStatusCode());
  }

  /**
   * Platform admins with group-scope bypass may use legacy comments.
   */
  public function testLegacyListAllowsGroupScopeBypassStaff(): void {
    $account = $this->account(21, ['authenticated', 'department_admin'], [
      'view application_comment entities',
      'bypass beantragt group scope',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $organisation = $this->group(4, 'org');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->method('getQuery')->willReturn($this->query([5 => 5]));
    $comment = $this->comment(visibility: 'internal');
    $comment->method('id')->willReturn(5);
    $comment_storage->method('loadMultiple')->with([5 => 5])->willReturn([
      5 => $comment,
    ]);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationInWorkspace($jurisdiction, $organisation)),
        $comment_storage,
        $this->groupStorage($jurisdiction, $organisation),
        relationship_storage: $this->relationshipStorage([]),
      ),
    );

    $response = $this->controller()->list('44');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('internal', $payload['data'][0]['visibility']);
    $this->assertSame(5, $payload['data'][0]['id']);
    $this->assertSame('46f0622f-7b19-42d4-8be9-cb8d1f99bc27', $payload['data'][0]['uuid']);
  }

  /**
   * Comment mutations are rate-limited before creating entities.
   */
  public function testAddV1RejectsRateLimitedCommentMutation(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->never())->method('create');

    $this->installContainer(
      $account,
      $this->entityTypeManager($this->storageForApplication($this->application()), $comment_storage, $this->groupStorage()),
    );

    $request = new Request(
      ['jurisdiction' => 'musterstadt'],
      [],
      [],
      [],
      [],
      ['REMOTE_ADDR' => '198.51.100.77'],
      json_encode([
        'data' => [
          'type' => 'application_comment',
          'attributes' => [
            'body' => 'Zu viele Kommentare',
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_comment.v1_create', $this->anything(), $this->anything(), $this->anything())
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $response = $this->controller(
      $this->validCsrfToken(),
      flood: $flood,
    )->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
  }

  /**
   * The v1 upload endpoint creates a claimable comment attachment.
   */
  public function testUploadV1CreatesClaimableCommentAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('setTemporary');
    $file->expects($this->once())->method('setOwnerId')->with(7)->willReturnSelf();
    $file->expects($this->once())->method('save');
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('nachreichung.png');
    $file->method('getSize')->willReturn(68);

    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->once())
      ->method('writeData')
      ->with(
        $this->callback(static fn(string $data): bool => str_starts_with($data, "\x89PNG\r\n\x1A\n")),
        'private://application_comments/44/comment-attachments/nachreichung.png',
        $this->anything(),
      )
      ->willReturn($file);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->once())
      ->method('prepareDirectory')
      ->with('private://application_comments/44/comment-attachments', $this->anything())
      ->willReturn(TRUE);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationWithSchema()),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage(),
        $attachment_storage,
      ),
    );

    $request = Request::create(
      '/api/v1/applications/9a67f32b-93cc-48c5-8899-9d5470f57f44/comment-attachments?jurisdiction=musterstadt',
      'POST',
      [],
      [],
      ['file' => new UploadedFile($this->pngFile(), 'nachreichung.png', 'image/png', NULL, TRUE)],
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      file_system: $file_system,
      file_repository: $file_repository,
      time: $this->fixedTime(),
    )->uploadV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('comment_attachment', $payload['data']['type']);
    $this->assertSame('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $payload['data']['id']);
    $this->assertSame('nachreichung.png', $payload['data']['filename']);
    $this->assertSame('image/png', $payload['data']['mimeType']);
    $this->assertSame('clean', $payload['data']['scanStatus']);
    $this->assertIsArray($created_values);
    $this->assertSame(['target_id' => 12], $created_values['field_schema']);
    $this->assertSame(['target_id' => 44], $created_values['field_application']);
    $this->assertSame('comment_attachment', $created_values['field_field_id']);
    $this->assertSame(['target_id' => 321], $created_values['field_file']);
    $this->assertSame('uploaded', $created_values['field_upload_status']);
    $this->assertSame('clean', $created_values['field_scan_status']);
    $this->assertSame(7, $created_values['uid']);
    $this->assertSame(hash('sha256', $payload['data']['claimToken']), $created_values['field_claim_token_hash']);
  }

  /**
   * Comment attachment uploads cannot cross application deletion.
   */
  public function testUploadV1RejectsApplicationDeletionLock(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $application = $this->applicationWithSchema();
    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage(),
      ),
    );

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.delete.44', 300.0)
      ->willReturn(FALSE);

    $request = Request::create(
      '/api/v1/applications/9a67f32b-93cc-48c5-8899-9d5470f57f44/comment-attachments?jurisdiction=musterstadt',
      'POST',
      [],
      [],
      ['file' => new UploadedFile($this->pngFile(), 'nachreichung.png', 'image/png', NULL, TRUE)],
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      lock: $lock,
    )->uploadV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.application_locked', $payload['error']['code']);
  }

  /**
   * The v1 upload endpoint rejects files that would exceed tenant storage.
   */
  public function testUploadV1RejectsTenantStorageQuotaViolation(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $application = $this->applicationWithSchemaInWorkspace($jurisdiction);

    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->never())->method('writeData');

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->never())->method('prepareDirectory');

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->once())
      ->method('violationForJurisdiction')
      ->with($this->identicalTo($jurisdiction), 68)
      ->willReturn([
        'maxStorageBytes' => 1048576,
        'usedStorageBytes' => 1048576,
        'nextSizeBytes' => 68,
      ]);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage($jurisdiction),
      ),
    );

    $request = Request::create(
      '/api/v1/applications/9a67f32b-93cc-48c5-8899-9d5470f57f44/comment-attachments?jurisdiction=musterstadt',
      'POST',
      [],
      [],
      ['file' => new UploadedFile($this->pngFile(), 'nachreichung.png', 'image/png', NULL, TRUE)],
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      file_system: $file_system,
      file_repository: $file_repository,
      attachment_storage_quota: $quota,
    )->uploadV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('attachment.tenant_storage_quota_exceeded', $payload['error']['code']);
  }

  /**
   * The v1 upload endpoint quarantines malware signatures.
   */
  public function testUploadV1QuarantinesEicarCommentAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('setTemporary');
    $file->expects($this->once())->method('setOwnerId')->with(7)->willReturnSelf();
    $file->expects($this->once())->method('save');
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('nachreichung.pdf');
    $file->method('getSize')->willReturn(96);

    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->once())
      ->method('writeData')
      ->with(
        $this->callback(static fn(string $data): bool => str_starts_with($data, "%PDF-1.4")),
        'private://application_comments/44/comment-attachments/nachreichung.pdf',
        $this->anything(),
      )
      ->willReturn($file);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->once())
      ->method('prepareDirectory')
      ->with('private://application_comments/44/comment-attachments', $this->anything())
      ->willReturn(TRUE);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->applicationWithSchema()),
        $this->createMock(EntityStorageInterface::class),
        $this->groupStorage(),
        $attachment_storage,
      ),
    );

    $request = Request::create(
      '/api/v1/applications/9a67f32b-93cc-48c5-8899-9d5470f57f44/comment-attachments?jurisdiction=musterstadt',
      'POST',
      [],
      [],
      ['file' => new UploadedFile($this->eicarPdfFile(), 'nachreichung.pdf', 'application/pdf', NULL, TRUE)],
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      file_system: $file_system,
      file_repository: $file_repository,
      time: $this->fixedTime(),
    )->uploadV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('attachment.quarantined', $payload['error']['code']);
    $this->assertSame('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $payload['error']['details']['attachmentId']);
    $this->assertSame('quarantined', $payload['error']['details']['scanStatus']);
    $this->assertArrayNotHasKey('data', $payload);
    $this->assertIsArray($created_values);
    $this->assertSame(['target_id' => 44], $created_values['field_application']);
    $this->assertNull($created_values['field_claim_token_hash']);
    $this->assertSame('quarantined', $created_values['field_scan_status']);
  }

  /**
   * Creating a v1 comment can consume uploaded attachment claims.
   */
  public function testAddV1ClaimsUploadedCommentAttachment(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);

    $tmp = $this->pngFile();
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('nachreichung.png');
    $file->method('getSize')->willReturn(68);
    $file->method('getFileUri')->willReturn($tmp);
    $file->expects($this->once())->method('setPermanent');
    $file->expects($this->once())->method('setOwnerId')->with(7)->willReturnSelf();
    $file->expects($this->once())->method('save');
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(321)->willReturn($file);

    $attachment = $this->commentAttachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $attachment->expects($this->exactly(4))->method('set')->willReturnSelf();
    $attachment->expects($this->once())->method('setOwnerId')->with(7)->willReturnSelf();
    $attachment->expects($this->once())->method('save');
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);
    $attachment_storage->expects($this->once())->method('resetCache')->with([77]);

    $created_comment = $this->comment(body: 'Hier ist der Nachweis.');
    $created_comment->expects($this->once())->method('save');
    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->once())
      ->method('create')
      ->with([
        'field_application' => 44,
        'field_body' => 'Hier ist der Nachweis.',
        'field_author_role' => 'citizen',
        'field_visibility' => 'public',
        'uid' => 7,
        'status' => 1,
        'field_attachments' => [
          ['target_id' => 321],
        ],
      ])
      ->willReturn($created_comment);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturn(TRUE);
    $lock->expects($this->exactly(2))->method('release');

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($this->application()),
        $comment_storage,
        $this->groupStorage(),
        $attachment_storage,
        $file_storage,
      ),
    );

    $request = new Request(
      ['jurisdiction' => 'musterstadt'],
      [],
      [],
      [],
      [],
      [],
      json_encode([
        'data' => [
          'type' => 'application_comment',
          'attributes' => [
            'body' => '<strong>Hier ist der Nachweis.</strong>',
            'attachments' => [
              [
                'attachmentId' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
                'claimToken' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      lock: $lock,
      time: $this->fixedTime(),
    )->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('Hier ist der Nachweis.', $payload['data']['body']);
  }

  /**
   * Creating a v1 comment rejects attachment claims exceeding tenant storage.
   */
  public function testAddV1RejectsTenantStorageQuotaViolation(): void {
    $account = $this->account(7, ['authenticated'], [
      'create application_comment entities',
    ]);
    $jurisdiction = $this->group(3, 'jur');
    $application = $this->applicationWithSchemaInWorkspace($jurisdiction);

    $tmp = $this->pngFile();
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFileUri')->willReturn($tmp);
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(321)->willReturn($file);

    $attachment = $this->commentAttachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);
    $attachment_storage->expects($this->once())->method('resetCache')->with([77]);

    $comment_storage = $this->createMock(EntityStorageInterface::class);
    $comment_storage->expects($this->never())->method('create');

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturn(TRUE);
    $lock->expects($this->exactly(2))->method('release');

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->once())
      ->method('violationForJurisdiction')
      ->with($this->identicalTo($jurisdiction), 0)
      ->willReturn([
        'maxStorageBytes' => 1048576,
        'usedStorageBytes' => 1048576,
        'nextSizeBytes' => 68,
      ]);

    $this->installContainer(
      $account,
      $this->entityTypeManager(
        $this->storageForApplication($application),
        $comment_storage,
        $this->groupStorage($jurisdiction),
        $attachment_storage,
        $file_storage,
      ),
    );

    $request = new Request(
      ['jurisdiction' => 'musterstadt'],
      [],
      [],
      [],
      [],
      [],
      json_encode([
        'data' => [
          'type' => 'application_comment',
          'attributes' => [
            'body' => 'Hier ist der Nachweis.',
            'attachments' => [
              [
                'attachmentId' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
                'claimToken' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    );
    $request->headers->set('X-CSRF-Token', 'valid-csrf');

    $response = $this->controller(
      $this->validCsrfToken(),
      lock: $lock,
      time: $this->fixedTime(),
      attachment_storage_quota: $quota,
    )->addV1(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('attachment.tenant_storage_quota_exceeded', $payload['error']['code']);
  }

  /**
   * Installs the Drupal service container required by ControllerBase.
   */
  private function installContainer(AccountInterface $account, EntityTypeManagerInterface $entity_type_manager): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates the controller under test.
   */
  private function controller(
    ?CsrfTokenGenerator $csrf_token = NULL,
    ?LockBackendInterface $lock = NULL,
    ?FileSystemInterface $file_system = NULL,
    ?FileRepositoryInterface $file_repository = NULL,
    ?TimeInterface $time = NULL,
    ?FloodInterface $flood = NULL,
    ?Connection $database = NULL,
    ?AttachmentScanServiceInterface $attachment_scanner = NULL,
    ?AttachmentStorageQuota $attachment_storage_quota = NULL,
    ?TenantScopedEntityQuery $tenant_scoped_entity_query = NULL,
    ?ConfirmedApplicationMailbox $confirmed_mailbox = NULL,
  ): CommentApiController {
    return new CommentApiController(
      $this->createMock(LoggerInterface::class),
      $file_system ?? $this->createMock(FileSystemInterface::class),
      $file_repository ?? $this->createMock(FileRepositoryInterface::class),
      $tenant_scoped_entity_query ?? $this->tenantScopedEntityQuery(),
      $csrf_token,
      $lock,
      $time,
      $flood,
      $database,
      $attachment_scanner,
      $attachment_storage_quota,
      confirmedMailbox: $confirmed_mailbox,
    );
  }

  /**
   * Creates a tenant-scoped query service test double.
   */
  private function tenantScopedEntityQuery(): TenantScopedEntityQuery {
    $service = $this->createMock(TenantScopedEntityQuery::class);
    $service->method('applyWorkspaceScope')
      ->willReturnCallback(
        static function (
          QueryInterface $query,
          string $_entity_type_id,
          GroupInterface $jurisdiction,
          ?GroupInterface $organisation = NULL,
        ): void {
          if ($organisation instanceof GroupInterface) {
            $query->condition('field_organisation', (int) $organisation->id());
            return;
          }
          $query->condition('field_jurisdiction', (int) $jurisdiction->id());
        },
      );
    return $service;
  }

  /**
   * Creates a mock account.
   *
   * @param int $uid
   *   Account uid.
   * @param string[] $roles
   *   Role IDs.
   * @param string[] $permissions
   *   Granted permission strings.
   * @param string $email
   *   Account email.
   */
  private function account(int $uid, array $roles = ['anonymous'], array $permissions = [], string $email = ''): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    $account->method('getEmail')->willReturn($email);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Creates a mock CSRF token generator accepting the standard REST token key.
   */
  private function validCsrfToken(): CsrfTokenGenerator {
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')
      ->willReturnCallback(
        static fn(string $token, string $seed): bool => $token === 'valid-csrf'
          && in_array($seed, [CsrfRequestHeaderAccessCheck::TOKEN_KEY, 'rest'], TRUE),
      );
    return $csrf;
  }

  /**
   * Creates an entity type manager for application, comments and group storage.
   */
  private function entityTypeManager(
    EntityStorageInterface $application_storage,
    EntityStorageInterface $comment_storage,
    EntityStorageInterface $group_storage,
    ?EntityStorageInterface $attachment_storage = NULL,
    ?EntityStorageInterface $file_storage = NULL,
    ?EntityStorageInterface $relationship_storage = NULL,
  ): EntityTypeManagerInterface {
    $attachment_storage ??= $this->createMock(EntityStorageInterface::class);
    $file_storage ??= $this->createMock(EntityStorageInterface::class);
    $relationship_storage ??= $this->createMock(EntityStorageInterface::class);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')
      ->willReturnCallback(
        static fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
          'application' => $application_storage,
          'application_comment' => $comment_storage,
          'group' => $group_storage,
          'application_attachment' => $attachment_storage,
          'file' => $file_storage,
          'group_relationship' => $relationship_storage,
          default => throw new \InvalidArgumentException($entity_type_id),
        },
      );
    return $manager;
  }

  /**
   * Creates storage loading the test application by UUID query.
   */
  private function storageForApplication(ApplicationInterface $application): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $storage->method('load')->with(44)->willReturn($application);
    return $storage;
  }

  /**
   * Creates group storage for resolving the required jurisdiction slug.
   */
  private function groupStorage(?GroupInterface $jurisdiction = NULL, ?GroupInterface $organisation = NULL): EntityStorageInterface {
    $jurisdiction ??= $this->group(3, 'jur');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([3 => 3]));
    $storage->method('load')
      ->willReturnCallback(static fn(int|string $id): ?GroupInterface => match ((int) $id) {
        3 => $jurisdiction,
        4 => $organisation,
        default => NULL,
      });
    return $storage;
  }

  /**
   * Creates group relationship storage for current-user membership lookups.
   *
   * @param array<int, string[]> $memberships
   *   Relationship IDs mapped to group role IDs.
   */
  private function relationshipStorage(array $memberships): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')
      ->willReturn($this->query(array_combine(array_keys($memberships), array_keys($memberships)) ?: []));
    $storage->method('load')
      ->willReturnCallback(fn(int|string $id): ?GroupRelationshipInterface => isset($memberships[(int) $id])
        ? $this->membership($memberships[(int) $id])
        : NULL);
    return $storage;
  }

  /**
   * Creates a group membership relationship mock.
   *
   * @param string[] $role_ids
   *   Group role IDs.
   */
  private function membership(array $role_ids): GroupRelationshipInterface {
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('hasField')->with('group_roles')->willReturn(TRUE);
    $relationship->method('get')->with('group_roles')->willReturn(
      $this->fieldValues(array_map(static fn(string $role_id): array => ['target_id' => $role_id], $role_ids)),
    );
    return $relationship;
  }

  /**
   * Creates an entity query mock.
   *
   * @param array<int, int> $ids
   *   Entity query result IDs.
   * @param array<int, array<int, mixed>>|null $conditions
   *   Optional sink for recorded conditions.
   */
  private function query(array $ids, ?array &$conditions = NULL): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$arguments) use (&$conditions, $query): QueryInterface {
        if ($conditions !== NULL) {
          $conditions[] = $arguments;
        }
        return $query;
      },
    );
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Creates an entity query mock that records nested condition groups.
   *
   * @param array<int, int> $ids
   *   Entity query result IDs.
   * @param array<int, array<int, mixed>> $conditions
   *   Sink for recorded query conditions.
   * @param array<int, array{type: string, conditions: array<int, array<int, mixed>>, notExists: string[]}> $condition_groups
   *   Sink for recorded condition groups.
   */
  private function queryWithConditionGroups(array $ids, array &$conditions, array &$condition_groups): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$arguments) use (&$conditions, $query): QueryInterface {
        $conditions[] = $arguments;
        return $query;
      },
    );
    $query->method('andConditionGroup')
      ->willReturnCallback(
        function () use (&$condition_groups): ConditionInterface {
          return $this->conditionGroup('AND', $condition_groups);
        },
      );
    $query->method('orConditionGroup')
      ->willReturnCallback(
        function () use (&$condition_groups): ConditionInterface {
          return $this->conditionGroup('OR', $condition_groups);
        },
      );
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Creates a condition group mock that records condition calls.
   *
   * @param string $type
   *   The condition group type.
   * @param array<int, array{type: string, conditions: array<int, array<int, mixed>>, notExists: string[]}> $condition_groups
   *   Sink for recorded condition groups.
   */
  private function conditionGroup(string $type, array &$condition_groups): ConditionInterface {
    $index = count($condition_groups);
    $condition_groups[$index] = [
      'type' => $type,
      'conditions' => [],
      'notExists' => [],
    ];

    $group = $this->createMock(ConditionInterface::class);
    $group->method('condition')->willReturnCallback(
      static function (...$arguments) use (&$condition_groups, $index, $group): ConditionInterface {
        $condition_groups[$index]['conditions'][] = $arguments;
        return $group;
      },
    );
    $group->method('notExists')->willReturnCallback(
      static function (string $field) use (&$condition_groups, $index, $group): ConditionInterface {
        $condition_groups[$index]['notExists'][] = $field;
        return $group;
      },
    );
    $group->method('getConjunction')->willReturn($type);
    return $group;
  }

  /**
   * Asserts that a query recorded a field/value condition.
   *
   * @param array<int, array<int, mixed>> $conditions
   *   Recorded condition calls.
   * @param string $field
   *   Expected field name.
   * @param mixed $value
   *   Expected condition value.
   */
  private function assertQueryCondition(array $conditions, string $field, mixed $value): void {
    foreach ($conditions as $condition) {
      if (($condition[0] ?? NULL) === $field && ($condition[1] ?? NULL) === $value) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail(sprintf('Failed asserting query condition %s = %s exists.', $field, (string) $value));
  }

  /**
   * Creates a field manager exposing application tenant scope fields.
   */
  private function fieldManagerWithApplicationScopeFields(): EntityFieldManagerInterface {
    $definition = $this->createMock(FieldStorageDefinitionInterface::class);
    $manager = $this->createMock(EntityFieldManagerInterface::class);
    $manager->method('getFieldStorageDefinitions')
      ->with('application')
      ->willReturn([
        'field_organisation' => $definition,
        'field_jurisdiction' => $definition,
        'field_group' => $definition,
      ]);
    return $manager;
  }

  /**
   * Creates an application mock owned by the test citizen.
   */
  private function application(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->method('getOwnerId')->willReturn(7);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    return $application;
  }

  /**
   * Creates an application mock with explicit workspace scope fields.
   */
  private function applicationInWorkspace(GroupInterface $jurisdiction, ?GroupInterface $organisation): ApplicationInterface {
    $fields = [
      'field_jurisdiction' => $this->fieldTarget((int) $jurisdiction->id()),
      'field_organisation' => $organisation ? $this->fieldTarget((int) $organisation->id()) : $this->emptyField(),
    ];
    $application = $this->application();
    $application->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $application->method('get')
      ->willReturnCallback(fn(string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $application;
  }

  /**
   * Creates an application mock with a schema reference.
   */
  private function applicationWithSchema(): ApplicationInterface {
    $application = $this->application();
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_schema');
    $application->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      default => $this->emptyField(),
    });
    return $application;
  }

  /**
   * Creates an application mock with schema and jurisdiction scope fields.
   */
  private function applicationWithSchemaInWorkspace(GroupInterface $jurisdiction): ApplicationInterface {
    $fields = [
      'field_schema' => $this->fieldTarget(12),
      'field_jurisdiction' => $this->fieldReference($jurisdiction),
    ];
    $application = $this->application();
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $application->method('get')
      ->willReturnCallback(fn(string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $application;
  }

  /**
   * Creates a comment mock.
   */
  private function comment(string $body = 'Danke fuer die Rueckmeldung.', string $visibility = 'public'): ApplicationCommentInterface {
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->method('uuid')->willReturn('46f0622f-7b19-42d4-8be9-cb8d1f99bc27');
    $comment->method('getBody')->willReturn($body);
    $comment->method('getAuthorRole')->willReturn('citizen');
    $comment->method('getVisibility')->willReturn($visibility);
    $comment->method('isInternal')->willReturn($visibility === 'internal');
    $comment->method('getCreatedTime')->willReturn(1781431500);
    $comment->method('hasField')->with('field_attachments')->willReturn(FALSE);
    $comment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_attachments' => $this->emptyField(),
      default => $this->emptyField(),
    });
    return $comment;
  }

  /**
   * Creates an application attachment claim mock.
   */
  private function commentAttachment(string $claim_token): ApplicationAttachmentInterface {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->method('getOwnerId')->willReturn(7);
    $attachment->method('hasField')
      ->willReturnCallback(
        static fn(string $field): bool => in_array(
          $field,
          ['field_analysis_metadata', 'field_scan_status', 'field_size'],
          TRUE,
        ),
      );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(44),
      'field_field_id' => $this->fieldValue('comment_attachment'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_claim_expires' => $this->fieldValue(1781434800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', $claim_token)),
      'field_file' => $this->fieldTarget(321),
      'field_size' => $this->fieldValue(68),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_metadata' => $this->fieldValue('{"context":"application_comment","applicationUuid":"9a67f32b-93cc-48c5-8899-9d5470f57f44"}'),
      default => $this->emptyField(),
    });
    return $attachment;
  }

  /**
   * Builds a field list exposing a target_id property.
   */
  private function fieldTarget(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => $property === 'target_id');
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'target_id' ? $target_id : NULL,
    );
    return $field;
  }

  /**
   * Builds a field list exposing a referenced entity and target_id.
   */
  private function fieldReference(object $entity): FieldItemListInterface {
    $target_id = method_exists($entity, 'id') ? (int) $entity->id() : 0;
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => in_array($property, ['entity', 'target_id'], TRUE));
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => $target_id,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds a field list exposing a scalar value property.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $property): bool => $property === 'value');
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds a field list exposing getValue().
   *
   * @param mixed[] $values
   *   Field values.
   */
  private function fieldValues(array $values): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($values === []);
    $field->method('getValue')->willReturn($values);
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  /**
   * Creates a fixed request time service.
   */
  private function fixedTime(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781431200);
    return $time;
  }

  /**
   * Creates a temporary PNG test file.
   */
  private function pngFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'beantragt_comment_attachment_');
    $this->assertIsString($path);
    file_put_contents($path, "\x89PNG\r\n\x1A\n" . str_repeat("\0", 60));
    return $path;
  }

  /**
   * Creates a temporary MIME-valid PDF with the EICAR test signature.
   */
  private function eicarPdfFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'beantragt_comment_eicar_');
    $this->assertIsString($path);
    file_put_contents($path, "%PDF-1.4\nX5O!P%@AP[4\\PZX54(P^)7CC)7}" . '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' . "\n");
    return $path;
  }

  /**
   * Creates a minimal group entity mock.
   */
  private function group(int $id, string $bundle = 'jur'): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')->willReturn(FALSE);
    return $group;
  }

}
