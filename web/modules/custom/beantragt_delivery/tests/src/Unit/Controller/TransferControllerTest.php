<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Controller;

use Drupal\beantragt_delivery\Service\TransferHistoryProjection;
use Symfony\Component\Yaml\Yaml;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Controller\TransferController;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Protects staff transfer metadata and controller-level dispatch guards.
 */
#[Group('beantragt_delivery')]
final class TransferControllerTest extends UnitTestCase {

  /**
   * The synthetic application under test.
   */
  private ApplicationInterface $application;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $this->application = $this->createMock(ApplicationInterface::class);
    $this->application->method('getCacheContexts')->willReturn([]);
    $this->application->method('getCacheTags')->willReturn([]);
    $this->application->method('getCacheMaxAge')->willReturn(0);
    $this->application->method('access')->willReturn(TRUE);
  }

  /**
   * Applicants may edit their case but still cannot inspect transfers.
   */
  public function testCitizenCannotInspectOrMutateTransfers(): void {
    $controller = $this->controller(FALSE);
    $responses = [
      $controller->listTransfers($this->application),
      $controller->createTransfer($this->application, Request::create('/transfers', 'POST')),
      $controller->approveDispatch($this->application, 'synthetic-transfer', Request::create('/approve', 'POST')),
      $controller->retry($this->application, 'synthetic-transfer', Request::create('/retry', 'POST')),
      $controller->resume($this->application, 'synthetic-transfer', Request::create('/resume', 'POST')),
      $controller->replan($this->application, 'synthetic-transfer', Request::create('/replan', 'POST')),
    ];
    foreach ($responses as $response) {
      self::assertSame(403, $response->getStatusCode());
      self::assertSame(['error' => ['code' => 'integration.access_denied']], json_decode($response->getContent(), TRUE));
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
  }

  /**
   * Direct controller calls cannot bypass the route's CSRF requirement.
   */
  public function testMissingCsrfFailsBeforePlanner(): void {
    $controller = $this->controller(TRUE);
    self::assertSame(403, $controller->createTransfer($this->application, Request::create('/transfers', 'POST'))->getStatusCode());
    foreach (['approveDispatch', 'retry', 'resume', 'replan'] as $method) {
      $response = $controller->{$method}($this->application, 'synthetic-transfer', Request::create('/transfer', 'POST'));
      self::assertSame(403, $response->getStatusCode());
      self::assertSame('integration.csrf_invalid', json_decode($response->getContent(), TRUE)['error']['code']);
    }
  }

  /**
   * Staff output excludes payloads, hashes, profiles and arbitrary diagnostics.
   */
  public function testProjectionIsClosedAndMockIsExplicit(): void {
    $controller = $this->controller(FALSE);
    $project = new \ReflectionMethod($controller, 'project');
    $row = [
      'uuid' => 'synthetic-transfer',
      'adapter' => 'mock',
      'status' => 'accepted',
      'integration_connection_uuid' => 'synthetic-connection',
      'integration_mapping_uuid' => 'synthetic-mapping',
      'integration_mapping_version' => 2,
      'connection_snapshot' => [
        'label' => 'Synthetic connection',
        'credential_profile' => 'PRIVATE_PROFILE',
        'client_secret_env' => 'PRIVATE_ENV',
      ],
      'payload_reference' => 'PRIVATE_REFERENCE',
      'payload_sha256' => 'PRIVATE_HASH',
      'lastError' => 'integration.payload_invalid: PRIVATE_MESSAGE',
      'validation_errors' => [
        [
          'pointer' => '/field',
          'keyword' => 'required',
          'code' => 'integration.required',
          'source' => 'source-field',
          'value' => 'PRIVATE_VALUE',
        ],
      ],
      'externalId' => '',
      'metadata' => [
        'credential' => 'PRIVATE_METADATA',
        'fitConnect' => ['submissionId' => 'synthetic-submission', 'caseId' => 'synthetic-case'],
        'destinationVerified' => [
          'name' => 'Synthetic destination',
          'service_urn' => 'urn:synthetic:service',
          'schema_uri' => 'urn:synthetic:schema',
          'checked_at' => 100,
          'unexpected' => 'PRIVATE_EVIDENCE',
        ],
      ],
    ];
    $data = $project->invoke($controller, $this->application, $row);
    self::assertTrue($data['is_mock']);
    self::assertSame('mock_test_only', $data['transport_status']);
    self::assertSame('not_inferred_from_transport', $data['recipient_approval']);
    self::assertSame('integration.payload_invalid', $data['last_error_code']);
    self::assertSame('source-field', $data['validation_errors'][0]['source']);
    self::assertSame(2, $data['mapping']['version']);
    self::assertSame('synthetic-submission', $data['receipts']['submission_id']);
    self::assertSame('synthetic-case', $data['receipts']['case_id']);
    self::assertSame([
      'name' => 'Synthetic destination',
      'service_urn' => 'urn:synthetic:service',
      'schema_uri' => 'urn:synthetic:schema',
      'checked_at' => 100,
    ], $data['connection']['destination_verification']);
    $row['externalId'] = 'terminal-submission';
    self::assertSame('terminal-submission', $project->invoke($controller, $this->application, $row)['receipts']['submission_id']);
    self::assertSame([
      'approvalRefusal' => NULL,
      'canApproveDispatch' => FALSE,
      'canRetry' => FALSE,
      'canResume' => FALSE,
      'canReplan' => FALSE,
      'replanRestricted' => FALSE,
      'statusUnchanged' => NULL,
    ], $data['meta']);
    self::assertStringNotContainsString('PRIVATE_', json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * After a tenant move only a neutral history marker crosses the boundary.
   */
  public function testForeignTransferAndAnonymisedHistoryAreValueFree(): void {
    $controller = $this->controller(TRUE);
    $project = new \ReflectionMethod($controller, 'project');
    $row = [
      'id' => 3,
      'uuid' => 'transfer',
      'status' => 'blocked',
      'adapter' => 'fit_connect',
      'scope_group_id' => 99,
      'created' => 1,
      'changed' => 2,
      'processed' => NULL,
      'dispatch_approved_uid' => 7,
      'connection_snapshot' => ['label' => 'PRIVATE_TENANT', 'destination_id' => 'PRIVATE_DESTINATION'],
      'integration_mapping_uuid' => 'PRIVATE_MAPPING',
      'metadata' => ['attachmentManifest' => [['id' => 'PRIVATE_ATTACHMENT']]],
    ];
    $foreign = $project->invoke($controller, $this->application, $row);
    self::assertSame([
      'uuid' => 'transfer',
      'status' => 'blocked',
      'created' => 1,
      'changed' => 2,
      'processed' => NULL,
      'meta' => ['canApproveDispatch' => FALSE, 'canRetry' => FALSE, 'canResume' => FALSE, 'canReplan' => FALSE],
      'foreign_scope' => TRUE,
      'transport_status' => 'transferred_by_another_tenant',
    ], $foreign);
    $row['scope_group_id'] = 12;
    $row['lastError'] = 'integration.application_anonymised';
    $erased = $project->invoke($controller, $this->application, $row);
    self::assertTrue($erased['anonymised']);
    self::assertSame('integration.application_anonymised', $erased['last_error_code']);
    self::assertArrayNotHasKey('approval', $erased);
    self::assertArrayNotHasKey('external_responses', $erased);
    self::assertStringNotContainsString('PRIVATE_', json_encode([$foreign, $erased], JSON_THROW_ON_ERROR));
  }

  /**
   * Only explicitly selected attachment identifiers and sizes are projected.
   */
  public function testAttachmentProjectionRequiresFrozenOptIn(): void {
    $controller = $this->controller(FALSE);
    $project = new \ReflectionMethod($controller, 'project');
    $row = [
      'uuid' => 'transfer',
      'status' => 'accepted',
      'adapter' => 'mock',
      'connection_snapshot' => ['send_attachments' => FALSE],
      'metadata' => ['attachmentManifest' => [['id' => 'attachment', 'size' => 42, 'filename' => 'PRIVATE_NAME']]],
    ];
    self::assertSame([], $project->invoke($controller, $this->application, $row)['attachments']);
    $row['connection_snapshot']['send_attachments'] = TRUE;
    $data = $project->invoke($controller, $this->application, $row);
    self::assertTrue($data['send_attachments']);
    self::assertSame([['id' => 'attachment', 'size' => 42]], $data['attachments']);
    self::assertStringNotContainsString('PRIVATE_', json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * Every recovery route uses the same independent approval security gates.
   */
  public function testRecoveryRoutesRequirePermissionCsrfAndApplicationAccess(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 4) . '/beantragt_delivery.routing.yml');
    foreach (['approve', 'retry', 'resume', 'replan', 'create'] as $action) {
      $route = $routes['beantragt_delivery.transfer_' . $action];
      self::assertSame(['POST'], $route['methods']);
      self::assertSame('approve application dispatch', $route['requirements']['_permission']);
      self::assertSame('application.update', $route['requirements']['_entity_access']);
      self::assertSame('TRUE', $route['requirements']['_csrf_request_header_token']);
      self::assertSame('TRUE', $route['requirements']['_user_is_logged_in']);
    }
  }

  /**
   * Uses real guards and an uninitialized planner to prove early exit.
   */
  private function controller(bool $staff): TransferController {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('isAuthenticated')->willReturn(TRUE);
    $permissions = ['approve application dispatch', 'bypass beantragt group scope'];
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $staff && in_array($permission, $permissions, TRUE));
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($user);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('12');
    $group->method('bundle')->willReturn('jur');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturn(TRUE);
    $field->method('__get')->with('entity')->willReturn($group);
    $this->application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
    $this->application->method('get')->with('field_jurisdiction')->willReturn($field);
    $controller = (new \ReflectionClass(TransferController::class))->newInstanceWithoutConstructor();
    foreach ([
      'account' => $account,
      'gates' => NULL,
      'scopeAccess' => new ScopeAccess($manager),
      'history' => new TransferHistoryProjection(new ScopeAccess($manager)),
      'entities' => $manager,
      'csrfToken' => $this->createMock(CsrfTokenGenerator::class),
      'audit' => new IntegrationAudit(new NullLogger(), $this->createMock(TimeInterface::class), $account),
      'responses' => (new \ReflectionClass(ExternalResponseRepository::class))->newInstanceWithoutConstructor(),
    ] as $name => $value) {
      (new \ReflectionProperty($controller, $name))->setValue($controller, $value);
    }
    return $controller;
  }

}
