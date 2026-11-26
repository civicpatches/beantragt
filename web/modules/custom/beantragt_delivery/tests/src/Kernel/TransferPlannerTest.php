<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_delivery\TransferGateInterface;
use Drupal\beantragt_delivery\Service\TransferGates;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Gateway\FitConnectDeliveryGateway;
use Drupal\beantragt_delivery\Gateway\MockDeliveryGateway;
use Drupal\beantragt_delivery\Gateway\WebhookDeliveryGateway;
use Drupal\beantragt_delivery\Plugin\QueueWorker\DeliveryQueueWorker;
use Drupal\beantragt_delivery\Service\DataContractValidator;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryEnvelopeBuilder;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\DeliveryPolicyResolver;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\beantragt_delivery\Service\FitConnectMetadataDocumentBuilder;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionApiClient;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionMetadataBuilder;
use Drupal\beantragt_delivery\Service\MappedPayloadBuilder;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Service\TransferAttachmentSelector;
use Drupal\beantragt_delivery\Service\TransferPayloadRepository;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\beantragt_delivery\Service\ApplicationDeliveryPurger;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\beantragt_delivery\Service\TransferFailures;
use Drupal\beantragt_delivery\Service\TransferPlanningRecovery;
use Drupal\beantragt_delivery\Controller\TransferController;
use Drupal\beantragt_delivery\EventSubscriber\StatusTriggerSubscriber;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;
use Drupal\beantragt_delivery\Service\IntegrationFormCatalog;
use Drupal\beantragt_delivery\Controller\IntegrationController;
use Drupal\beantragt_delivery\Controller\DeliveryController;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\UserInterface;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Psr\Http\Message\RequestInterface;

/**
 * Tests immutable planning, encrypted payloads and SQL approval transitions.
 */
#[Group('beantragt_delivery')]
final class TransferPlannerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Planner and real SQL repositories under test.
   */
  private TransferPlanner $planner;

  /**
   * Envelope builder sharing the planner's synthetic entity manager.
   */
  private DeliveryEnvelopeBuilder $envelopeBuilder;

  /**
   * Empty by default, with explicit policy doubles in extension tests.
   */
  private TransferGates $gates;

  /**
   * Spy for value-free planner diagnostics.
   */
  private LoggerInterface $plannerLogger;

  /**
   * Real SQL integration definitions.
   */
  private IntegrationRepository $integrations;

  /**
   * Real SQL outbox repository.
   */
  private DeliveryLogRepository $logs;

  /**
   * Encrypted payload repository.
   */
  private TransferPayloadRepository $payloads;

  /**
   * Synthetic mutable entity fixtures representing persisted cases and forms.
   */
  private array $applications = [];

  /**
   * Current form revision, independently of the application's pinned revision.
   */
  private array $currentRevisions = [1 => 11, 2 => 21];

  /**
   * Explicit historical fixtures and legacy revision query results.
   */
  private array $historicalForms = [];

  /**
   * Revision query results for applications created before exact pinning.
   */
  private array $legacyRevisions = [];

  /**
   * Synthetic application field values.
   */
  private array $data = [];

  /**
   * Exact revision IDs per application.
   */
  private array $revision = [];

  /**
   * Confirmation flags per application.
   */
  private array $confirmed = [];

  /**
   * Current form tenant ownership.
   */
  private array $formScopes = [];

  /**
   * Synthetic form entities.
   */
  private array $forms = [];

  /**
   * Complete pinned-contract mapping fixture.
   */
  private array $fixture;

  /**
   * Mock entity storage boundary.
   */
  private EntityTypeManagerInterface $manager;

  /**
   * Whether the synthetic approver retains permission.
   */
  private bool $approverAllowed = TRUE;

  /**
   * Models a contending second request without claiming true parallel proof.
   */
  private bool $lockAvailable = TRUE;

  /**
   * Counts attempts to take the shared application lock.
   */
  private int $lockAcquires = 0;

  /**
   * Current workflow status id per application (trigger status checks).
   */
  private array $statusIds = [];

  /**
   * Flags of the statuses in the fixture catalogue (group 1), keyed by id.
   */
  private array $catalogue = [];

  /**
   * Constructor arguments of the planner under test, without the resolver.
   */
  private array $plannerArguments = [];

  /**
   * Optional queue failure boundary, with the normal real SQL queue by default.
   */
  private ?DatabaseQueue $queueOverride = NULL;

  /**
   * Requests recorded by the mock HTTP handler.
   */
  private array $requests = [];

  /**
   * Original environment values restored after every test.
   */
  private array $environment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY', 'TRANSFER_FIXTURE'] as $key) {
      $this->environment[$key] = getenv($key);
      putenv($key . '=' . str_repeat('synthetic-test-only-', 3));
    }
    $database = $this->container->get('database');
    require_once $this->root . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    foreach (beantragt_delivery_schema() as $table => $definition) {
      $database->schema()->createTable($table, $definition);
    }
    $this->container->get('queue')->get(DeliveryDispatcher::QUEUE_NAME)->createQueue();
    $database->schema()->createTable('application', [
      'fields' => ['id' => ['type' => 'int', 'not null' => TRUE]],
      'primary key' => ['id'],
    ]);
    foreach (['form_schema' => 'id', 'form_schema_revision' => 'revision_id'] as $table => $key) {
      $database->schema()->createTable($table, [
        'fields' => [$key => ['type' => 'int', 'not null' => TRUE]],
        'primary key' => [$key],
      ]);
      foreach ($table === 'form_schema' ? [1, 2] : [11, 12, 21, 22] as $id) {
        $database->insert($table)->fields([$key => $id])->execute();
      }
    }
    $this->fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $groups = [];
    foreach ([1, 2] as $scope) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('id')->willReturn((string) $scope);
      $group->method('uuid')->willReturn(sprintf('00000000-0000-4000-8000-%012d', $scope));
      $group->method('label')->willReturn('Synthetic tenant ' . $scope);
      $group->method('bundle')->willReturn('jur');
      $groups[$scope] = $group;
    }
    foreach ([1, 2] as $scope) {
      $this->formScopes[$scope] = $scope;
      $group_field = $this->field(fn(): GroupInterface => $groups[$this->formScopes[$scope]]);
      $schema_field = $this->field(fn(): string => json_encode($this->fixture['form'], JSON_THROW_ON_ERROR));
      $form = $this->createMock(FormSchemaInterface::class);
      $form->method('id')->willReturn((string) $scope);
      $form->method('getRevisionId')->willReturnCallback(fn(): int => $this->currentRevisions[$scope]);
      $form->method('getVersion')->willReturn(1);
      $form->method('uuid')->willReturn($this->formUuid($scope));
      $form->method('label')->willReturn('Synthetic form ' . $scope);
      $form->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_group');
      $form->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $name === 'field_schema' ? $schema_field : $group_field);
      $this->forms[$scope] = $form;
    }
    $new_schema = $this->fixture['form'];
    $new_schema['title'] = 'SYNTHETIC newer content';
    $this->historicalForms[12] = $this->historicalForm(1, 12, $new_schema);
    $this->historicalForms[22] = $this->historicalForm(2, 22, $new_schema);
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnCallback(static fn($id) => $groups[$id] ?? NULL);
    $form_storage = $this->createMock(ContentEntityStorageInterface::class);
    $form_storage->method('loadRevision')->willReturnCallback(fn($id) => $this->historicalForms[$id] ?? $this->forms[(int) floor($id / 10)] ?? NULL);
    $revision_query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'allRevisions', 'condition', 'range', 'sort'] as $method) {
      $revision_query->method($method)->willReturnSelf();
    }
    $revision_query->method('execute')->willReturnCallback(fn(): array => $this->legacyRevisions);
    $form_storage->method('getQuery')->willReturn($revision_query);
    $form_storage->method('load')->willReturnCallback(fn($id) => $this->forms[$id] ?? NULL);
    $form_storage->method('loadUnchanged')->willReturnCallback(fn($id) => $this->forms[$id] ?? NULL);
    $form_storage->method('loadByProperties')->willReturnCallback(fn(array $properties): array => array_filter($this->forms, static fn($form): bool => $form->uuid() === ($properties['uuid'] ?? '')));
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('load')->willReturnCallback(fn($id) => $this->applications[$id] ?? NULL);
    $users = [];
    foreach ([7, 8] as $uid) {
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn((string) $uid);
      $user->method('isActive')->willReturn(TRUE);
      $user->method('isAuthenticated')->willReturn(TRUE);
      $user->method('hasPermission')->willReturnCallback(fn(string $permission): bool => $this->approverAllowed && in_array($permission, [
        'approve application dispatch',
        'bypass beantragt group scope',
      ], TRUE));
      $users[$uid] = $user;
    }
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturnCallback(static fn($uid) => $users[$uid] ?? NULL);
    $user_storage->method('loadMultiple')->willReturnCallback(static fn(array $ids): array => array_intersect_key($users, array_flip($ids)));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $this->manager = $manager;
    $manager->method('getStorage')->willReturnMap([
      ['group', $group_storage],
      ['form_schema', $form_storage],
      ['application', $application_storage],
      ['user', $user_storage],
      ['workflow_status', $this->workflowStatusStorage()],
    ]);
    $manager->method('hasDefinition')->willReturnCallback(fn(string $type): bool => $type === 'workflow_status' && $this->catalogue !== []);
    $definition = $this->createMock(ContentEntityTypeInterface::class);
    $definition->method('getBaseTable')->willReturn('application');
    $manager->method('getDefinition')->with('application')->willReturn($definition);
    $registry = new SyntheticDataContractRegistry(contractDirectory: dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    $rules = new IntegrationRules($registry);
    $profiles = new IntegrationCredentialProfiles($this->container->get('config.factory'));
    $profile_data = [];
    foreach ([1, 2] as $scope) {
      $profile_data['tenant_' . $scope] = [
        'allowed_scopes' => [$scope],
        'client_id' => 'synthetic-client-' . $scope,
        'client_secret_env' => 'TRANSFER_FIXTURE',
        'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
        'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
        'metadata_schema_version' => '2.1.0',
        'confirmed_destinations' => [[
          'scope_group_id' => $scope,
          'destination_id' => $this->destination($scope),
          'service_urn' => 'urn:synthetic:service',
          'contract_key' => 'fim:S00000096',
          'contract_version' => '1.0',
        ],
        ],
      ];
    }
    $this->container->get('config.storage')->write('beantragt_delivery.settings', [
      'default_adapter' => 'fit_connect',
      'auto_enqueue' => TRUE,
      'max_attempts' => 3,
      'credential_profiles' => $profile_data,
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $time->method('getRequestTime')->willReturn(1000);
    $scope_access = new ScopeAccess($manager);
    $this->integrations = new IntegrationRepository($database, new Php(), $time, $manager, $scope_access, $profiles, $registry, $rules);
    $protection = new ApplicationDataProtectionService($manager, new NullLogger());
    $this->envelopeBuilder = new DeliveryEnvelopeBuilder(new TransferAttachmentSelector($manager, $protection));
    $this->logs = new DeliveryLogRepository($database, new Php(), $time, new NullLogger());
    $purger = new ApplicationDeliveryPurger($database, $manager,
      (new \ReflectionClass(ExternalResponseService::class))->newInstanceWithoutConstructor(),
      $this->logs, $time, $this->container->get('state'));
    $this->payloads = new TransferPayloadRepository($database, $protection, new Php(), $time, $purger);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (): bool {
      $this->lockAcquires++;
      return $this->lockAvailable;
    });
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->willReturnCallback(fn(string $name) => $this->queueOverride ?? $this->container->get('queue')->get($name));
    $this->gates = new TransferGates();
    $this->plannerLogger = $this->createMock(LoggerInterface::class);
    $this->plannerArguments = [
      $database, $manager, $scope_access, $this->integrations, $profiles, $registry,
      new MappedPayloadBuilder($registry, $rules, new DataContractValidator($registry)),
      $protection, $this->payloads, $this->logs, $this->envelopeBuilder,
      $queue_factory, $lock, $time, $this->container->get('config.factory'), NULL, $this->gates, $this->plannerLogger,
    ];
    $this->planner = new TransferPlanner(...$this->plannerArguments);
    $this->container->set('beantragt_delivery.planning_recovery', new TransferPlanningRecovery($database, $manager, $scope_access, $this->planner, $time));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $key => $value) {
      putenv($value === FALSE ? $key : $key . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Every binding is frozen and the valid canonical payload is encrypted.
   */
  public function testFrozenEncryptedBindingAndDuplicatePlanning(): void {
    $definition = $this->assignment();
    $application = $this->application(1, 1);
    $id = $this->planner->plan($application);
    $row = $this->logs->load($id);
    self::assertSame('awaiting_approval', $row['status']);
    self::assertSame('pending', $row['dispatch_approval_status']);
    self::assertSame($definition['assignment']['uuid'], $row['integration_assignment_uuid']);
    self::assertSame($definition['connection']['uuid'], $row['integration_connection_uuid']);
    self::assertSame($definition['mapping']['uuid'], $row['integration_mapping_uuid']);
    self::assertSame(1, $row['integration_mapping_version']);
    self::assertSame($definition['version']['checksum'], $row['integration_mapping_checksum']);
    self::assertSame($this->formUuid(1), $row['form_schema_uuid']);
    self::assertSame(11, $row['form_schema_revision_id']);
    self::assertSame('fim:S00000096', $row['contract_key']);
    self::assertSame('1.0', $row['contract_version']);
    self::assertSame('urn:synthetic:service', $row['service_urn']);
    self::assertSame('tenant_1', $row['connection_snapshot']['credential_profile']);
    self::assertSame($this->destination(1), $row['connection_snapshot']['destination_id']);
    self::assertArrayNotHasKey('client_secret', $row['connection_snapshot']);
    self::assertSame([], $row['validation_errors']);
    $payload = $this->payloads->verified($row);
    self::assertSame(hash('sha256', $payload), $row['payload_sha256']);
    $stored = $this->container->get('database')->select('beantragt_transfer_payload', 'p')->fields('p', ['ciphertext'])->execute()->fetchField();
    self::assertStringNotContainsString('SYNTHETIC', $stored);
    self::assertStringContainsString('__beantragt_encrypted', $stored);
    self::assertSame($id, $this->planner->plan($application));
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
    self::assertSame(1, $this->countRows('beantragt_transfer_payload'));
    self::assertSame(0, $this->queued());
    self::assertFalse($this->logs->claimFitConnectProcessing($id, 1));
  }

  /**
   * Approval is idempotent and transient retry preserves the original payload.
   */
  public function testApprovalDoubleClickAndRetry(): void {
    $this->assignment();
    $application = $this->application(1, 1);
    $id = $this->planner->plan($application);
    $row = $this->logs->load($id);
    $this->lockAvailable = FALSE;
    $this->expectCode('integration.transfer_busy', fn() => $this->planner->approve($application, $row['uuid'], 7));
    $this->lockAvailable = TRUE;
    $approved = $this->planner->approve($application, $row['uuid'], 7);
    self::assertSame('queued', $approved['status']);
    self::assertSame('approved', $approved['dispatch_approval_status']);
    self::assertSame(7, $approved['dispatch_approved_uid']);
    self::assertSame(1000, $approved['dispatch_approved_at']);
    self::assertSame($approved, $this->planner->approve($application, $row['uuid'], 7));
    $this->lockAvailable = FALSE;
    self::assertSame($approved, $this->planner->approve($application, $row['uuid'], 7));
    $this->lockAvailable = TRUE;
    self::assertSame(1, $this->queued());
    $audits = $this->container->get('database')->select('beantragt_transfer_audit', 'a')->fields('a', ['outcome'])
      ->condition('transfer_uuid', $row['uuid'])->condition('action', 'approve_dispatch')->execute()->fetchCol();
    self::assertContains('success', $audits);
    self::assertSame(2, count(array_filter($audits, static fn(string $outcome): bool => $outcome === 'noop')));
    self::assertTrue($this->logs->claimFitConnectProcessing($id, 1));
    $this->logs->markResult($id, DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
    $failed = $this->logs->load($id);
    self::assertTrue($this->planner->canRetry($failed));
    $retry = $this->planner->retry($application, $row['uuid'], 7);
    self::assertSame($row['payload_reference'], $retry['payload_reference']);
    self::assertSame($row['payload_sha256'], $retry['payload_sha256']);
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
    self::assertSame(1, $this->queued(), 'The existing delayed queue item is reused.');
    self::assertSame($retry, $this->planner->retry($application, $row['uuid'], 7));
    self::assertTrue($this->logs->claimFitConnectProcessing($id, 2));
    $this->logs->checkpointFitConnect($id, 'creating', $this->destination(1));
    $this->logs->markResult($id, DeliveryResult::failed('fit_connect.api_request_failed', 'Unclear synthetic outcome.'));
    self::assertFalse($this->planner->canRetry($this->logs->load($id)));
    $this->expectCode('integration.retry_not_allowed', fn() => $this->planner->retry($application, $row['uuid'], 7));
  }

  /**
   * Recoverable blocks leave waiting and failed rows byte-for-byte unchanged.
   */
  public function testBlockedApprovalAndRetryPreserveWaitingRows(): void {
    foreach (['connection', 'version', 'assignment'] as $index => $resource) {
      $this->currentRevisions[1] = 11;
      $definition = $this->assignment();
      $waiting_application = $this->application($index * 2 + 1, 1);
      $retry_application = $this->application($index * 2 + 2, 1);
      $waiting = $this->logs->load($this->planner->plan($waiting_application));
      $retry = $this->logs->load($this->planner->plan($retry_application));
      $this->planner->approve($retry_application, $retry['uuid'], 7);
      $this->logs->markResult($retry['id'], DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
      $retry = $this->logs->load($retry['id']);
      $queue_count = $this->queued();
      if ($resource === 'version') {
        $this->integrations->transitionVersion($definition['mapping']['uuid'], 1, 'retired');
        $code = 'integration.mapping_inactive';
      }
      else {
        $this->integrations->transition($resource, $definition[$resource]['uuid'], $resource === 'connection' ? 'disabled' : 'inactive');
        $code = $resource === 'connection' ? 'integration.connection_disabled' : 'integration.assignment_inactive';
      }
      $this->expectCode($code, fn() => $this->planner->approve($waiting_application, $waiting['uuid'], 7));
      $this->expectCode($code, fn() => $this->planner->retry($retry_application, $retry['uuid'], 7));
      foreach ([$waiting, $retry] as $original) {
        self::assertSame($original, $this->logs->load($original['id']));
      }
      $failed = $this->logs->load($waiting['id']);
      self::assertSame('pending', $failed['dispatch_approval_status']);
      self::assertNull($failed['dispatch_approved_uid']);
      self::assertNull($failed['dispatch_approved_at']);
      self::assertSame($queue_count, $this->queued());
      $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'inactive');
    }
    self::assertSame([], $this->requests);
  }

  /**
   * Scope-specific rules select separate TEST destinations without fallback.
   */
  public function testTwoTenantsAndNoGlobalFallback(): void {
    $first = $this->assignment(1, 'automatic');
    $first_application = $this->application(1, 1);
    $second_application = $this->application(2, 2);
    self::assertNull($this->planner->plan($second_application));
    self::assertSame('no_assignment', $this->planner->explain($second_application)['reason']);
    $second = $this->assignment(2, 'automatic');
    self::assertSame(0, $this->countRows('beantragt_delivery_log'), 'Activation never queues old applications.');
    $first_row = $this->logs->load($this->planner->plan($first_application));
    $second_row = $this->logs->load($this->planner->plan($second_application));
    self::assertSame($first['assignment']['uuid'], $first_row['integration_assignment_uuid']);
    self::assertSame($second['assignment']['uuid'], $second_row['integration_assignment_uuid']);
    self::assertSame($this->destination(1), $first_row['connection_snapshot']['destination_id']);
    self::assertSame($this->destination(2), $second_row['connection_snapshot']['destination_id']);
    self::assertNotSame($first_row['connection_snapshot'], $second_row['connection_snapshot']);
    self::assertSame(2, $this->queued());
    self::assertSame($this->destination(2), $this->planner->prepareSend($second_row, $second_application)['settings']->destinationId);
    $this->expectCode('integration.scope_mismatch', fn() => $this->planner->prepareSend($first_row, $second_application));
  }

  /**
   * Cases routed to an organisation use their jurisdiction form's assignment.
   */
  public function testJurisdictionFormAssignmentAppliesToCasesRoutedToItsOrganisation(): void {
    $definition = $this->assignment(1);
    $routed = $this->application(5, 1, $this->organisation(31, 1));
    self::assertSame('assigned', $this->planner->explain($routed)['reason']);
    $row = $this->logs->load($this->planner->plan($routed));
    self::assertSame($definition['assignment']['uuid'], $row['integration_assignment_uuid']);
    self::assertSame(1, $row['scope_group_id'], 'Rows carry the scope of their binding.');
    self::assertCount(1, $this->planner->transfers($routed));
    self::assertSame($this->destination(1), $this->planner->prepareSend($row, $routed, TRUE, FALSE)['settings']->destinationId);

    // A case directly in the jurisdiction keeps matching.
    self::assertSame('assigned', $this->planner->explain($this->application(7, 1))['reason']);

    // An organisation of another jurisdiction never reaches this assignment.
    $foreign = $this->application(6, 1, $this->organisation(32, 2));
    self::assertSame('scope_unresolved', $this->planner->explain($foreign)['reason']);
    self::assertFalse($this->planner->hasAssignment($foreign));
    self::assertNull($this->planner->plan($foreign));
    $this->expectCode('integration.scope_mismatch', fn() => $this->planner->prepareSend($row, $foreign, TRUE, FALSE));

    // An organisation without a jurisdiction resolves no integration scope.
    $orphan = $this->application(8, 1, $this->organisation(33, NULL));
    self::assertSame('scope_unresolved', $this->planner->explain($orphan)['reason']);
  }

  /**
   * Builds an organisation group below one of the synthetic jurisdictions.
   */
  private function organisation(int $id, ?int $jurisdiction): GroupInterface {
    $parent = $jurisdiction === NULL ? NULL : $this->manager->getStorage('group')->load($jurisdiction);
    $organisation = $this->createMock(GroupInterface::class);
    $organisation->method('id')->willReturn((string) $id);
    $organisation->method('bundle')->willReturn('org');
    $organisation->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
    $organisation->method('get')->willReturn($this->field(static fn() => $parent, static fn(): bool => $parent === NULL));
    return $organisation;
  }

  /**
   * New revisions warn for new cases and preserve covered in-flight transfers.
   */
  public function testNewPublishedRevisionDoesNotBlockCoveredTransfer(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $this->currentRevisions[1] = 12;
    $assignment = $this->integrations->load('assignment', $definition['assignment']['uuid']);
    self::assertTrue($assignment['blocked']);
    self::assertContains('integration.revision_not_covered', array_column($assignment['blocked_reasons'], 'code'));
    self::assertNotEmpty($this->planner->prepareSend($row, $application)['json']);
    self::assertSame('assigned', $this->planner->explain($application)['reason']);
    self::assertNull($this->planner->ruleRefusal($application));
    $new_application = $this->application(2, 1);
    $this->revision[2] = 12;
    self::assertSame('revision_not_covered', $this->planner->explain($new_application)['reason']);
    // The bulk forward check reads the same explanation as its refusal code.
    self::assertSame('integration.revision_not_covered', $this->planner->ruleRefusal($new_application));
  }

  /**
   * A recorded planning failure is history, not a rule that blocks a forward.
   */
  public function testRuleRefusalIgnoresPlanningHistory(): void {
    $this->assignment(1, 'approval');
    $application = $this->application(1, 1);
    $this->planner->recordPlanningFailure($application);
    self::assertSame('planning_failed', $this->planner->explain($application)['reason']);
    self::assertNull($this->planner->ruleRefusal($application));
  }

  /**
   * Reads only status planning denials of this case written after the mark.
   */
  public function testStatusPlanningDenialReadsOnlyNewerDenials(): void {
    $application = $this->application(1, 1);
    $other = $this->application(2, 1);
    self::assertSame(0, $this->planner->lastAuditId($application));
    $this->planner->audit($application, '', 7, 'plan_on_status', 'denied', 'integration.assignment_ambiguous');
    $mark = $this->planner->lastAuditId($application);
    self::assertGreaterThan(0, $mark);
    $this->planner->audit($application, '', 7, 'plan_on_status', 'success');
    $this->planner->audit($application, '', 7, 'plan', 'denied', 'integration.planning_failed');
    $this->planner->audit($application, '', 7, 'plan_on_status', 'denied', 'integration.transfer_busy');
    $this->planner->audit($other, '', 7, 'plan_on_status', 'denied', 'integration.already_submitted');
    self::assertSame('integration.transfer_busy', $this->planner->statusPlanningDenial($application, $mark));
    self::assertSame('integration.already_submitted', $this->planner->statusPlanningDenial($other, 0));
    // Without the denial remembered in this process the audit table answers,
    // newer rows only.
    $this->planner->forgetStatusPlanningDenial($application);
    self::assertSame('integration.transfer_busy', $this->planner->statusPlanningDenial($application, $mark));
    // The newer row of the other case is not this case's denial.
    self::assertNull($this->planner->statusPlanningDenial($application, $this->planner->lastAuditId($application)));
  }

  /**
   * A denial collapsed by the audit writer still names its code.
   */
  public function testStatusPlanningDenialSurvivesAuditCollapse(): void {
    $application = $this->application(1, 1);
    $this->planner->audit($application, '', 7, 'plan_on_status', 'denied', 'integration.transfer_busy');
    $mark = $this->planner->lastAuditId($application);
    // The same person, case and code within a minute: no new row.
    $this->planner->audit($application, '', 7, 'plan_on_status', 'denied', 'integration.transfer_busy');
    self::assertSame($mark, $this->planner->lastAuditId($application));
    self::assertSame('integration.transfer_busy', $this->planner->statusPlanningDenial($application, $mark));
    // A new attempt starts without the code of the previous one.
    $this->planner->forgetStatusPlanningDenial($application);
    self::assertNull($this->planner->statusPlanningDenial($application, $mark));
  }

  /**
   * A workflow-only revision keeps the rule covered for new applications.
   */
  public function testWorkflowOnlyRevisionStaysCovered(): void {
    $definition = $this->assignment(1, 'automatic');
    $this->historicalForms[12] = $this->historicalForm(1, 12, $this->fixture['form'], 2);
    $this->currentRevisions[1] = 12;
    $assignment = $this->integrations->load('assignment', $definition['assignment']['uuid']);
    self::assertNotContains('integration.revision_not_covered', array_column($assignment['blocked_reasons'], 'code'));
    $application = $this->application(2, 1);
    $this->revision[2] = 12;
    self::assertSame('assigned', $this->planner->explain($application)['reason']);
  }

  /**
   * Legacy applications retain their selected binding across lifecycle saves.
   */
  public function testLegacyApplicationKeepsItsResolvedRevisionBinding(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $this->revision[1] = 0;
    $this->legacyRevisions = [11 => 1];
    $row = $this->logs->load($this->planner->plan($application));
    self::assertSame(11, $row['form_schema_revision_id']);
    $this->historicalForms[12] = $this->historicalForm(1, 12, $this->fixture['form']);
    $this->currentRevisions[1] = 12;
    $this->legacyRevisions = [12 => 1, 11 => 1];
    self::assertNotEmpty($this->planner->prepareSend($row, $application)['json']);
    self::assertSame(11, $this->logs->load($row['id'])['form_schema_revision_id']);
    $new_application = $this->application(2, 1);
    $this->revision[2] = 0;
    $new = $this->logs->load($this->planner->plan($new_application));
    self::assertSame(12, $new['form_schema_revision_id']);
    self::assertSame('queued', $new['status']);
    $changed = $this->fixture['form'];
    $changed['title'] = 'SYNTHETIC distinct content';
    $this->historicalForms[12] = $this->historicalForm(1, 12, $changed);
    $ambiguous = $this->application(3, 1);
    $this->revision[3] = 0;
    self::assertNull($this->planner->plan($ambiguous));
    self::assertSame('revision_not_covered', $this->planner->explain($ambiguous)['reason']);
    $this->historicalForms[11] = $this->historicalForm(1, 11, $this->fixture['form'], 2);
    $this->expectCode('integration.revision_not_covered', fn() => $this->planner->prepareSend($row, $application));
  }

  /**
   * Unconfirmed email and unregistered revisions cannot generate an outbox row.
   */
  public function testConfirmationAndExplicitRevisionAdoption(): void {
    $definition = $this->assignment();
    $application = $this->application(1, 1);
    $this->confirmed[1] = FALSE;
    self::assertNull($this->planner->plan($application));
    $this->confirmed[1] = TRUE;
    $this->revision[1] = 12;
    self::assertNull($this->planner->plan($application));
    self::assertSame('revision_not_covered', $this->planner->explain($application)['reason']);
    $adoption = $this->integrations->adoptRevision($definition['assignment']['uuid'], 12, 7);
    self::assertTrue($adoption['compatibility']['compatible']);
    self::assertSame('draft', $adoption['draft']['status']);
    self::assertSame([11, 12], $adoption['draft']['compatible_revisions']);
    self::assertSame(2, $adoption['next']['activate_mapping_version']);
    self::assertSame([11], $this->integrations->version($definition['mapping']['uuid'], 1)['compatible_revisions']);
    self::assertSame(1, $this->integrations->load('assignment', $definition['assignment']['uuid'])['mapping_version']);
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    self::assertSame(0, $this->queued());
  }

  /**
   * The HTTP adoption route only drafts, strips creators and audits both rows.
   */
  public function testAdoptionRouteDraftsAndAuditsWithoutSwitching(): void {
    $definition = $this->assignment();
    $uuid = $definition['assignment']['uuid'];
    $events = [];
    $logger = new class($events) extends AbstractLogger {

      public function __construct(private array &$events) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->events[] = json_decode($context['@event'], TRUE, 512, JSON_THROW_ON_ERROR);
      }

    };
    $controller = $this->integrationController($logger);
    $response = $controller->handle($this->adoptionRequest($uuid, '{"data":{"revision_id":12}}'));
    self::assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    self::assertTrue($data['compatibility']['compatible']);
    self::assertSame('draft', $data['draft']['status']);
    self::assertSame([11, 12], $data['draft']['compatible_revisions']);
    self::assertArrayNotHasKey('uid', $data['draft']);
    self::assertSame($uuid, $data['next']['replace_assignment_uuid']);
    $assignment = $this->integrations->load('assignment', $uuid);
    self::assertSame(1, $assignment['mapping_version']);
    self::assertSame('active', $assignment['status']);
    $audited = array_map(
      static fn(array $event): array => [$event['resource'], $event['action'], $event['uuid'], $event['version']],
      $events,
    );
    self::assertSame([
      ['assignment', 'adopt_revision', $uuid, NULL],
      ['mapping', 'adopt_revision', $definition['mapping']['uuid'], 2],
    ], $audited);
    $invalid = [
      '{"data":{"revision_id":"12"}}',
      '{"data":{"revision_id":0}}',
      '{"data":{"revision_id":12,"mapping_version":2}}',
    ];
    foreach ($invalid as $body) {
      $response = $controller->handle($this->adoptionRequest($uuid, $body));
      self::assertSame(422, $response->getStatusCode(), $body);
      self::assertSame('integration.input_invalid', json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }
  }

  /**
   * Multiple services on the same form fail visibly instead of choosing first.
   */
  public function testAmbiguousAssignmentsCreateOneNonRetryableFailure(): void {
    $this->assignment(1, 'automatic');
    $this->assignment(1, 'automatic', 'urn:synthetic:another');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    self::assertSame('failed', $row['status']);
    self::assertStringStartsWith('integration.assignment_ambiguous:', $row['lastError']);
    self::assertNull($row['payload_reference']);
    self::assertFalse($this->planner->canRetry($row));
    self::assertSame('assignment_ambiguous', $this->planner->explain($application)['reason']);
    self::assertSame(0, $this->queued());
    self::assertSame($row['id'], $this->planner->plan($application));
  }

  /**
   * Invalid payloads remain internal and cannot be approved or retried.
   */
  public function testInvalidPayloadsNeverEnterTheQueue(): void {
    $this->assignment();
    foreach (['missing', 'type', 'code'] as $index => $scenario) {
      $id = $index + 1;
      $application = $this->application($id, 1);
      if ($scenario === 'missing') {
        unset($this->data[$id]['synthetic_field_1']);
      }
      elseif ($scenario === 'type') {
        $this->data[$id]['synthetic_field_1'] = 123;
      }
      else {
        $this->data[$id]['synthetic_field_21'] = 'SYNTHETIC PRIVATE OPTION';
      }
      $original = $application->getData();
      $row = $this->logs->load($this->planner->plan($application));
      self::assertSame('failed', $row['status'], $scenario);
      self::assertStringStartsWith('integration.payload_invalid:', $row['lastError']);
      self::assertNotEmpty($row['validation_errors']);
      self::assertStringNotContainsString('SYNTHETIC PRIVATE OPTION', json_encode($row['validation_errors']));
      self::assertNotEmpty(array_column($row['validation_errors'], 'source'));
      self::assertNull($row['payload_reference']);
      self::assertFalse($this->planner->canRetry($row));
      self::assertSame($original, $application->getData());
      $this->expectCode('integration.dispatch_not_approvable', fn() => $this->planner->approve($application, $row['uuid'], 7));
    }
    self::assertSame(0, $this->queued());
    self::assertSame(0, $this->countRows('beantragt_transfer_payload'));
  }

  /**
   * Emergency stops prevent any prepared transfer from reaching its gateway.
   */
  public function testLifecycleChangesBlockWaitingTransfers(): void {
    foreach (['connection', 'assignment', 'version'] as $index => $resource) {
      $definition = $this->assignment(1, 'automatic', 'urn:synthetic:service' . $index);
      $application = $this->application($index + 1, 1);
      $row = $this->logs->load($this->planner->plan($application));
      if ($resource === 'version') {
        $this->integrations->transitionVersion($definition['mapping']['uuid'], 1, 'retired');
        $code = 'integration.mapping_inactive';
      }
      else {
        $this->integrations->transition($resource, $definition[$resource]['uuid'], $resource === 'connection' ? 'disabled' : 'inactive');
        $code = $resource === 'connection' ? 'integration.connection_disabled' : 'integration.assignment_inactive';
      }
      $this->expectCode($code, fn() => $this->planner->prepareSend($row, $application));
      $this->assertGatewayBlocked($row, $application, $code);
      self::assertSame($row['payload_sha256'], $this->logs->load($row['id'])['payload_sha256']);
      if ($resource !== 'assignment') {
        $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'inactive');
      }
    }
  }

  /**
   * Changed bindings cannot redirect rows and tampered payloads fail shut.
   */
  public function testBindingChangesAndPayloadTamperingFailClosed(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $database = $this->container->get('database');
    $database->update('beantragt_integration_connection')->fields(['destination_id' => $this->destination(2)])
      ->condition('uuid', $definition['connection']['uuid'])->execute();
    $this->expectCode('integration.binding_changed', fn() => $this->planner->prepareSend($row, $application));
    $this->assertGatewayBlocked($row, $application, 'integration.binding_changed');
    self::assertSame($row['connection_snapshot'], $this->logs->load($row['id'])['connection_snapshot']);
    $database->update('beantragt_integration_connection')->fields(['destination_id' => $this->destination(1)])
      ->condition('uuid', $definition['connection']['uuid'])->execute();
    $this->data[1]['synthetic_field_1'] = 'CHANGED SYNTHETIC';
    $this->expectCode('integration.application_changed', fn() => $this->planner->prepareSend($row, $application));
    $this->data[1] = $this->syntheticData();
    $this->revision[1] = 12;
    $this->expectCode('integration.revision_not_covered', fn() => $this->planner->prepareSend($row, $application));
    $this->revision[1] = 11;
    $database->update('beantragt_integration_mapping_version')->fields(['checksum' => str_repeat('0', 64)])
      ->condition('mapping_uuid', $definition['mapping']['uuid'])->execute();
    $this->assertGatewayBlocked($row, $application, 'integration.mapping_checksum_mismatch');
    $database->update('beantragt_integration_mapping_version')->fields(['checksum' => $definition['version']['checksum']])
      ->condition('mapping_uuid', $definition['mapping']['uuid'])->execute();
    $this->integrations->createVersion($definition['mapping']['uuid'], [
      'form_schema_uuid' => $this->formUuid(1),
      'compatible_revisions' => [11],
      'rules' => $this->fixture['rules'],
    ], 7);
    $this->integrations->transitionVersion($definition['mapping']['uuid'], 2, 'active');
    $database->update('beantragt_integration_assignment')->fields(['mapping_version' => 2])
      ->condition('uuid', $definition['assignment']['uuid'])->execute();
    $this->assertGatewayBlocked($row, $application, 'integration.binding_changed');
    $database->update('beantragt_integration_assignment')->fields(['mapping_version' => 1])
      ->condition('uuid', $definition['assignment']['uuid'])->execute();
    $database->update('beantragt_transfer_payload')->fields(['ciphertext' => '{}'])->condition('uuid', $row['payload_reference'])->execute();
    $this->expectCode('integration.payload_integrity_failed', fn() => $this->planner->prepareSend($row, $application));
    $this->assertGatewayBlocked($row, $application, 'integration.payload_integrity_failed');
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
  }

  /**
   * Revoked dispatch permission invalidates approval before any external call.
   */
  public function testDispatchPermissionIsRecheckedBeforeApprovalAndSend(): void {
    $this->assignment();
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $this->approverAllowed = FALSE;
    $this->expectCode('integration.access_denied', fn() => $this->planner->approve($application, $row['uuid'], 7));
    self::assertSame('awaiting_approval', $this->logs->load($row['id'])['status']);
    self::assertSame(0, $this->queued());
    $this->approverAllowed = TRUE;
    $this->planner->approve($application, $row['uuid'], 7);
    $this->logs->claimFitConnectProcessing($row['id'], 1);
    $this->approverAllowed = FALSE;
    $result = $this->gateway([])->deliver($this->envelopeBuilder->build($application), $row['id']);
    self::assertSame('integration.dispatch_approver_unauthorized', $result->errorCode);
    self::assertSame([], $this->requests);
  }

  /**
   * A real bound gateway sends precisely the encrypted canonical frozen bytes.
   */
  public function testBoundGatewayEncryptsFrozenPayloadAndNeverRepeatsSubmission(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    self::assertTrue($this->logs->claimFitConnectProcessing($row['id'], 1));
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $submission = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $case = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    $gateway = $this->gateway([
      $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $this->jsonResponse($this->destinationMetadata($row)),
      $this->jsonResponse($public),
      $this->jsonResponse(['submissionId' => $submission, 'caseId' => $case], 201),
      $this->jsonResponse([]),
      $this->jsonResponse(['events' => []]),
    ]);
    $envelope = $this->envelopeBuilder->build($application);
    $result = $gateway->deliver($envelope, $row['id']);
    self::assertSame('', $result->errorCode);
    self::assertTrue($result->isAccepted());
    self::assertCount(6, $this->requests);
    $create = json_decode((string) $this->requests[3]->getBody(), TRUE);
    self::assertSame($this->destination(1), $create['destinationId']);
    self::assertSame('urn:synthetic:service', $create['publicService']['identifier']);
    $finalize = json_decode((string) $this->requests[4]->getBody(), TRUE);
    $jwe = (new CompactSerializer())->unserialize($finalize['encryptedData']);
    $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
    self::assertTrue($decrypter->decryptUsingKey($jwe, $key, 0));
    self::assertSame($this->payloads->verified($row), $jwe->getPayload());
    $saved = $this->logs->load($row['id']);
    self::assertSame('submitted', $saved['metadata']['fitConnect']['phase']);
    self::assertSame($row['payload_sha256'], $saved['metadata']['fitConnect']['payloadSha256']);
    self::assertSame('fit_connect.submission_already_started', $gateway->deliver($envelope, $row['id'])->errorCode);
    self::assertCount(6, $this->requests);
  }

  /**
   * Missing destination service/schema is rejected before submission creation.
   */
  public function testDestinationMetadataMustStillOfferBoundContract(): void {
    $this->assignment(1, 'automatic');
    foreach (['service', 'schema', 'disabled'] as $index => $scenario) {
      $application = $this->application($index + 1, 1);
      $row = $this->logs->load($this->planner->plan($application));
      $this->logs->claimFitConnectProcessing($row['id'], 1);
      $destination = $this->destinationMetadata($row);
      if ($scenario === 'service') {
        $destination['publicServices'][0]['identifier'] = 'urn:synthetic:foreign';
        $code = 'integration.destination_service_mismatch';
      }
      elseif ($scenario === 'schema') {
        $destination['publicServices'][0]['submissionSchemas'][0]['schemaUri'] = 'urn:synthetic:foreign';
        $code = 'integration.destination_contract_mismatch';
      }
      else {
        $destination['status'] = 'inactive';
        $code = 'fit_connect.destination_inactive';
      }
      $this->requests = [];
      $result = $this->gateway([
        $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
        $this->jsonResponse($destination),
      ])->deliver($this->envelopeBuilder->build($application), $row['id']);
      self::assertSame($code, $result->errorCode);
      self::assertCount(2, $this->requests);
      self::assertArrayNotHasKey('fitConnect', $this->logs->load($row['id'])['metadata']);
    }
  }

  /**
   * Disabling during HTTP stops the next request before submission creation.
   */
  public function testConnectionDisabledBetweenHttpStepsStopsImmediately(): void {
    $definition = $this->assignment(1, 'automatic');
    foreach ([1, 2] as $stop_after) {
      $application = $this->application($stop_after, 1);
      $row = $this->logs->load($this->planner->plan($application));
      self::assertTrue($this->logs->claimFitConnectProcessing($row['id'], 1));
      $responses = [
        $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
        $this->jsonResponse($this->destinationMetadata($row)),
      ];
      $response = $responses[$stop_after - 1];
      $responses[$stop_after - 1] = function () use ($definition, $response): Response {
        $this->integrations->transition('connection', $definition['connection']['uuid'], 'disabled');
        return $response;
      };
      $this->requests = [];
      $gateway = $this->gateway(array_slice($responses, 0, $stop_after));
      $result = $gateway->deliver($this->envelopeBuilder->build($application), $row['id']);
      self::assertSame('integration.connection_disabled', $result->errorCode);
      self::assertSame('failed', $result->status);
      self::assertCount($stop_after, $this->requests);
      self::assertArrayNotHasKey('fitConnect', $this->logs->load($row['id'])['metadata']);
      $this->integrations->transition('connection', $definition['connection']['uuid'], 'active');
    }
  }

  /**
   * Removed profiles, revoked tenants and production URLs fail closed.
   */
  public function testOperatorProfileChangesNeverFallBackToGlobalConfiguration(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $storage = $this->container->get('config.storage');
    $original = $storage->read('beantragt_delivery.settings');
    foreach (['scope', 'destination', 'production', 'removed'] as $scenario) {
      $settings = $original;
      if ($scenario === 'scope') {
        $settings['credential_profiles']['tenant_1']['allowed_scopes'] = [2];
        $code = 'integration.credential_profile_not_found';
      }
      elseif ($scenario === 'destination') {
        $settings['credential_profiles']['tenant_1']['confirmed_destinations'] = [];
        $code = 'integration.destination_unconfirmed';
      }
      elseif ($scenario === 'production') {
        $settings['credential_profiles']['tenant_1']['token_url'] = 'https://production.invalid/token';
        $code = 'fit_connect.test_only';
      }
      else {
        unset($settings['credential_profiles']['tenant_1']);
        $code = 'integration.credential_profile_not_found';
      }
      $storage->write('beantragt_delivery.settings', $settings);
      $this->container->get('config.factory')->reset('beantragt_delivery.settings');
      $this->assertGatewayBlocked($row, $application, $code);
      self::assertSame($row['connection_snapshot'], $this->logs->load($row['id'])['connection_snapshot']);
    }
  }

  /**
   * Creates a bound gateway whose only HTTP handler is the supplied mock queue.
   */
  private function gateway(array $responses): FitConnectDeliveryGateway {
    $mock = new MockHandler($responses);
    $handler = function (RequestInterface $request, array $options) use ($mock) {
      $this->requests[] = $request;
      return $mock($request, $options);
    };
    $http = new Client(['handler' => HandlerStack::create($handler)]);
    return new FitConnectDeliveryGateway(
      $this->container->get('config.factory'), new FitConnectAccessTokenClient($http),
      new FitConnectSubmissionMetadataBuilder(), new FitConnectSubmissionApiClient($http),
      new FitConnectMetadataDocumentBuilder(), new FitConnectJweEncrypter(), $this->manager,
      $this->logs, new ApplicationDataProtectionService($this->manager, new NullLogger()),
      NULL, $this->planner,
    );
  }

  /**
   * A queue attempt parks a live configuration block without consuming budget.
   */
  public function testDispatcherParksAndExplicitResumeReleasesTransfer(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $this->integrations->transition('connection', $definition['connection']['uuid'], 'disabled');
    $dispatcher = $this->dispatcher($this->gateway([]));
    $result = $dispatcher->dispatchLog($row['id']);
    self::assertSame('integration.connection_disabled', $result->errorCode);
    $blocked = $this->logs->load($row['id']);
    self::assertSame('blocked', $blocked['status']);
    self::assertSame(0, $blocked['attempts']);
    self::assertSame(0, $blocked['automatic_attempts']);
    self::assertFalse($dispatcher->shouldRetryLog($row['id'], $result));
    self::assertTrue($this->planner->canResume($blocked));
    self::assertSame([], $this->requests);
    $this->expectCode('integration.connection_disabled', fn() => $this->planner->resume($application, $row['uuid'], 7));
    self::assertSame($blocked, $this->logs->load($row['id']));
    $this->integrations->transition('connection', $definition['connection']['uuid'], 'active');
    self::assertSame('blocked', $this->logs->load($row['id'])['status']);
    $worker = new DeliveryQueueWorker([], DeliveryDispatcher::QUEUE_NAME, [], $dispatcher);
    $worker->processItem(['logId' => $row['id']]);
    self::assertSame([], $this->requests);
    $resumed = $this->planner->resume($application, $row['uuid'], 7);
    self::assertSame('queued', $resumed['status']);
    self::assertSame(1, $resumed['manual_resumes']);
    self::assertSame($row['payload_sha256'], $resumed['payload_sha256']);
    self::assertSame(1, $this->queued());
    $this->expectCode('integration.resume_not_allowed', fn() => $this->planner->resume($application, $row['uuid'], 7));
  }

  /**
   * Human retry survives exhausted automatic attempts and deduplicates items.
   */
  public function testManualRetryBudgetIsIndependentAndQueueIsDeduplicated(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $database = $this->container->get('database');
    for ($manual = 0; $manual < 10; $manual++) {
      $this->logs->markResult($row['id'], DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
      $database->update('beantragt_delivery_log')->fields(['automatic_attempts' => 3, 'attempts' => ($manual + 1) * 3])->condition('id', $row['id'])->execute();
      $failed = $this->logs->load($row['id']);
      self::assertFalse($this->planner->canAutoRequeue($failed));
      self::assertTrue($this->planner->canRetry($failed));
      $retry = $this->planner->retry($application, $row['uuid'], 7);
      self::assertSame($manual + 1, $retry['manual_resumes']);
      self::assertSame(0, $retry['automatic_attempts']);
      self::assertSame(1, $this->queued());
    }
    $this->logs->markResult($row['id'], DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
    self::assertFalse($this->planner->canRetry($this->logs->load($row['id'])));
    $this->expectCode('integration.retry_not_allowed', fn() => $this->planner->retry($application, $row['uuid'], 7));
    self::assertSame(1, $this->queued());
  }

  /**
   * Every recovery mutation rechecks the acting user's current permission.
   */
  public function testRecoveryRechecksFreshActorAuthorization(): void {
    $this->assignment(1, 'automatic');
    $retry_application = $this->application(1, 1);
    $resume_application = $this->application(2, 1);
    $replan_application = $this->application(3, 1);
    $retry = $this->logs->load($this->planner->plan($retry_application));
    $resume = $this->logs->load($this->planner->plan($resume_application));
    $replan = $this->logs->load($this->planner->plan($replan_application));
    $this->logs->markResult($retry['id'], DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
    $this->logs->markBlocked($resume['id'], 'integration.connection_disabled');
    $this->logs->markResult($replan['id'], DeliveryResult::failed('integration.payload_invalid', 'Synthetic invalid data.'));
    $rows = [$retry, $resume, $replan];
    $before = array_map(fn(array $row): array => $this->logs->load($row['id']), $rows);
    $this->approverAllowed = FALSE;
    $this->expectCode('integration.access_denied', fn() => $this->planner->retry($retry_application, $retry['uuid'], 7));
    $this->expectCode('integration.access_denied', fn() => $this->planner->resume($resume_application, $resume['uuid'], 7));
    $this->expectCode('integration.access_denied', fn() => $this->planner->replan($replan_application, $replan['uuid'], 7));
    $create_application = $this->application(4, 1);
    $this->expectCode('integration.access_denied', fn() => $this->planner->createTransfer($create_application, 7));
    self::assertSame($before, array_map(fn(array $row): array => $this->logs->load($row['id']), $rows));
    self::assertSame(3, $this->countRows('beantragt_delivery_log'));
    self::assertSame(3, $this->queued());
    $this->approverAllowed = TRUE;
    $this->expectCode('integration.access_denied', fn() => $this->planner->retry($retry_application, $retry['uuid']));
    self::assertSame('queued', $this->planner->retry($retry_application, $retry['uuid'], 7)['status']);
  }

  /**
   * Manual recovery cannot race a worker that has not deleted its leased item.
   */
  public function testLeasedQueueItemPreventsRetryAndResumeUntilReleased(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $queue = $this->container->get('queue')->get(DeliveryDispatcher::QUEUE_NAME);
    foreach (['retry', 'resume'] as $operation) {
      $item = $queue->claimItem();
      self::assertNotFalse($item);
      if ($operation === 'retry') {
        $this->logs->markResult($row['id'], DeliveryResult::failed('fit_connect.token_request_failed', 'Synthetic timeout.'));
      }
      else {
        $this->logs->markBlocked($row['id'], 'integration.connection_disabled');
      }
      $before = $this->logs->load($row['id']);
      $this->expectCode('integration.transfer_busy', fn() => $this->planner->{$operation}($application, $row['uuid'], 7));
      self::assertSame($before, $this->logs->load($row['id']));
      self::assertSame(1, $this->queued());
      $queue->releaseItem($item);
      $recovered = $this->planner->{$operation}($application, $row['uuid'], 7);
      self::assertSame('queued', $recovered['status']);
      self::assertSame(1, $this->queued());
    }
    self::assertSame(2, $this->logs->load($row['id'])['manual_resumes']);
  }

  /**
   * A data edit fails waiting transfers at once, but never a running send.
   */
  public function testDataEditFailsWaitingTransfersButNeverRunningSends(): void {
    $this->assignment();
    $waiting = $this->application(1, 1);
    $approved = $this->application(2, 1);
    $running = $this->application(3, 1);
    // Without a waiting transfer an edit never contends for the shared lock.
    $this->data[3]['synthetic_field_1'] = 'CORRECTED SYNTHETIC';
    $acquires = $this->lockAcquires;
    self::assertSame([], $this->planner->invalidateChangedData($running, 7));
    self::assertSame($acquires, $this->lockAcquires);
    $this->data[3] = $this->syntheticData();
    $waiting_row = $this->logs->load($this->planner->plan($waiting));
    $approved_row = $this->planner->approve($approved, $this->logs->load($this->planner->plan($approved))['uuid'], 7);
    $running_row = $this->planner->approve($running, $this->logs->load($this->planner->plan($running))['uuid'], 7);
    self::assertSame('queued', $approved_row['status']);
    $meta = json_decode($this->transferController()->listTransfers($waiting)->getContent(), TRUE)['meta'];
    self::assertSame([[
      'uuid' => $waiting_row['uuid'],
      'connection' => 'Synthetic TEST connection 1',
      'status' => 'awaiting_approval',
    ],
    ], $meta['pendingTransfers']);
    self::assertSame([], $this->planner->invalidateChangedData($waiting, 7), 'Unchanged data keeps the transfer.');
    foreach ([1, 2, 3] as $id) {
      $this->data[$id]['synthetic_field_1'] = 'CORRECTED SYNTHETIC';
    }

    // A held application lock means a dispatcher may own the case: warn only.
    $this->lockAvailable = FALSE;
    self::assertSame([], $this->planner->invalidateChangedData($waiting, 7));
    self::assertSame($waiting_row, $this->logs->load($waiting_row['id']));
    $this->lockAvailable = TRUE;

    // A claimed send is never rewritten; the gateway fences it before HTTP.
    self::assertTrue($this->logs->claimFitConnectProcessing($running_row['id'], 1));
    $claimed = $this->logs->load($running_row['id']);
    self::assertSame([], $this->planner->invalidateChangedData($running, 7));
    self::assertSame($claimed, $this->logs->load($running_row['id']));

    // A leased queue item belongs to a worker until it is released.
    $queue = $this->container->get('queue')->get(DeliveryDispatcher::QUEUE_NAME);
    $item = $queue->claimItem();
    self::assertSame($approved_row['id'], $item->data['logId']);
    self::assertSame([], $this->planner->invalidateChangedData($approved, 7));
    self::assertSame('queued', $this->logs->load($approved_row['id'])['status']);
    $queue->releaseItem($item);

    foreach ([[$waiting, $waiting_row], [$approved, $approved_row]] as [$application, $row]) {
      self::assertSame([$row['uuid']], $this->planner->invalidateChangedData($application, 7));
      $failed = $this->logs->load($row['id']);
      self::assertSame('failed', $failed['status']);
      self::assertSame('integration.application_changed', TransferFailures::code($failed));
      self::assertNull($failed['payload_reference']);
      self::assertTrue($this->planner->canReplan($failed, $application));
      $audit = $this->container->get('database')->select('beantragt_transfer_audit', 'a')
        ->fields('a', ['uid', 'outcome', 'code'])
        ->condition('transfer_uuid', $row['uuid'])->condition('action', 'invalidate')->execute()->fetchAssoc();
      self::assertSame([
        'uid' => 7,
        'outcome' => 'success',
        'code' => 'integration.application_changed',
      ], ['uid' => (int) $audit['uid']] + $audit);
    }
    $listed = json_decode($this->transferController()->listTransfers($waiting)->getContent(), TRUE);
    self::assertSame([], $listed['meta']['pendingTransfers']);
    self::assertSame('integration.application_changed', $listed['data'][0]['last_error_code']);
    self::assertTrue($listed['data'][0]['meta']['canReplan']);
    self::assertFalse($listed['data'][0]['meta']['replanRestricted']);
    // Viewers without the dispatch permission learn that someone else can act.
    $viewer = json_decode($this->transferController(FALSE)->listTransfers($waiting)->getContent(), TRUE)['data'][0]['meta'];
    self::assertFalse($viewer['canReplan']);
    self::assertTrue($viewer['replanRestricted']);
    self::assertSame([], $this->planner->invalidateChangedData($approved, 7), 'A failed row is history and stays unchanged.');

    // The left-over queue item ends without a send or a second state change.
    $before = $this->logs->load($approved_row['id']);
    $result = $this->dispatcher($this->gateway([]))->dispatchLog($approved_row['id']);
    self::assertSame('integration.application_changed', $result->errorCode);
    self::assertSame($before, $this->logs->load($approved_row['id']));
    self::assertSame([], $this->requests);

    // Healing: replan from current data, then a fresh dispatch approval.
    $new = $this->planner->replan($waiting, $waiting_row['uuid'], 7);
    self::assertSame('awaiting_approval', $new['status']);
    self::assertSame('queued', $this->planner->approve($waiting, $new['uuid'], 7)['status']);
  }

  /**
   * First-use queue DDL precedes planning; enqueue failures roll back safely.
   */
  public function testFirstQueueFailureRollsBackPayloadAndQueuedRow(): void {
    $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $database = $this->container->get('database');
    self::assertFalse($database->schema()->tableExists('queue'));
    $this->queueOverride = $this->getMockBuilder(DatabaseQueue::class)
      ->disableOriginalConstructor()->onlyMethods(['createItem'])->getMock();
    $this->queueOverride->expects($this->once())->method('createItem')->willThrowException(new \RuntimeException('Synthetic queue failure.'));
    $row = $this->planner->createTransfer($application, 7);
    self::assertTrue($database->schema()->tableExists('queue'));
    self::assertSame('blocked', $row['status']);
    self::assertStringStartsWith('integration.planning_failed:', $row['lastError']);
    self::assertNull($row['payload_reference']);
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
    self::assertSame(0, $this->countRows('beantragt_transfer_payload'));
    self::assertSame(0, $this->queued());
    $audits = $database->select('beantragt_transfer_audit', 'a')->fields('a', ['outcome', 'code'])
      ->condition('application_id', 1)->condition('action', 'create_transfer')->execute()->fetchAll(FetchAs::Associative);
    self::assertSame([['outcome' => 'denied', 'code' => 'integration.planning_failed']], $audits);
  }

  /**
   * Corrected data creates a new transfer, preserving history and checkpoints.
   */
  public function testReplanUsesCurrentDataAndKeepsOldTransferHistory(): void {
    $definition = $this->assignment();
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $this->data[1]['synthetic_field_1'] = 'CORRECTED SYNTHETIC';
    $this->expectCode('integration.application_changed', fn() => $this->planner->approve($application, $row['uuid'], 7));
    $old = $this->logs->load($row['id']);
    self::assertTrue($this->planner->canReplan($old));
    $new = $this->planner->replan($application, $row['uuid'], 7);
    self::assertNotSame($old['uuid'], $new['uuid']);
    self::assertSame('awaiting_approval', $new['status']);
    self::assertSame($definition['assignment']['uuid'], $new['integration_assignment_uuid']);
    self::assertNotSame($old['payload_sha256'], $new['payload_sha256']);
    self::assertSame($old, $this->logs->load($old['id']));
    $this->expectCode('integration.replan_not_allowed', fn() => $this->planner->replan($application, $row['uuid'], 7));
    self::assertSame(2, $this->countRows('beantragt_delivery_log'));
    $this->expectCode('integration.replan_not_allowed', fn() => $this->planner->replan($application, $new['uuid'], 7));
    $this->logs->markProcessing($old['id'], 1);
    $this->logs->checkpointFitConnect($old['id'], 'creating', $this->destination(1));
    $this->logs->markResult($old['id'], DeliveryResult::failed('integration.application_changed', 'Synthetic stale data.'));
    self::assertFalse($this->planner->canReplan($this->logs->load($old['id'])));
    $invalid_application = $this->application(2, 1);
    unset($this->data[2]['synthetic_field_1']);
    $invalid = $this->logs->load($this->planner->plan($invalid_application));
    self::assertTrue($this->planner->canReplan($invalid));
    $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'inactive');
    $replacement = $this->assignment();
    $this->data[2] = $this->syntheticData();
    $replanned = $this->planner->replan($invalid_application, $invalid['uuid'], 7);
    self::assertSame($replacement['assignment']['uuid'], $replanned['integration_assignment_uuid']);
    self::assertSame('awaiting_approval', $replanned['status']);
    self::assertSame($invalid, $this->logs->load($invalid['id']));
    $checkpoint_application = $this->application(3, 1);
    $checkpoint = $this->logs->load($this->planner->plan($checkpoint_application));
    $this->logs->markProcessing($checkpoint['id'], 1);
    $this->logs->checkpointFitConnect($checkpoint['id'], 'creating', $this->destination(1));
    $this->logs->markResult($checkpoint['id'], DeliveryResult::failed('integration.application_changed', 'Synthetic stale data.'));
    // A submission checkpoint locks in the delivery destination.
    $this->expectCode('integration.already_submitted', fn() => $this->planner->replan($checkpoint_application, $checkpoint['uuid'], 7));
  }

  /**
   * Planning refuses unconfirmed destinations before retaining personal data.
   */
  public function testUnconfirmedDestinationPlansBlockedWithoutPayload(): void {
    $this->assignment();
    $application = $this->application(1, 1);
    $storage = $this->container->get('config.storage');
    $original = $storage->read('beantragt_delivery.settings');
    $settings = $original;
    $settings['credential_profiles']['tenant_1']['confirmed_destinations'] = [];
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $row = $this->logs->load($this->planner->plan($application));
    self::assertSame('blocked', $row['status']);
    self::assertStringStartsWith('integration.destination_unconfirmed:', $row['lastError']);
    self::assertNull($row['payload_reference']);
    self::assertSame(0, $this->countRows('beantragt_transfer_payload'));
    self::assertSame(0, $this->queued());
    $storage->write('beantragt_delivery.settings', $original);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $resumed = $this->planner->resume($application, $row['uuid'], 7);
    self::assertSame('awaiting_approval', $resumed['status']);
    self::assertNotNull($resumed['payload_reference']);
    self::assertSame(0, $this->queued());
  }

  /**
   * Deactivation silences hooks, manual enqueue and old fallback queue items.
   */
  public function testInactiveAssignmentSuppressesEveryFallbackTrigger(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $old_mock = $this->logs->createQueued($application, $this->envelopeBuilder->build($application), 'mock', 'synthetic');
    $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'inactive');
    $storage = $this->container->get('config.storage');
    $settings = $storage->read('beantragt_delivery.settings');
    $settings['enabled'] = TRUE;
    $settings['default_adapter'] = 'mock';
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $dispatcher = $this->dispatcher($this->gateway([]));
    self::assertNull($dispatcher->autoEnqueue($application));
    self::assertSame('assignment_blocked', $this->planner->explain($application)['reason']);
    foreach (['mock', 'webhook'] as $adapter) {
      try {
        $dispatcher->enqueue($application, $adapter);
        self::fail('Inactive assignment must suppress manual fallback.');
      }
      catch (\InvalidArgumentException $exception) {
        self::assertSame('An integration assignment controls delivery; use case transfers.', $exception->getMessage());
      }
    }
    require_once $this->root . '/modules/custom/beantragt_delivery/beantragt_delivery.module';
    $this->container->set('entity_type.manager', $this->manager);
    $this->container->set('beantragt_delivery.dispatcher', $dispatcher);
    beantragt_delivery_application_insert($application);
    beantragt_delivery_beantragt_application_confirmed($application);
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
    self::assertSame(0, $this->queued());
    $result = $dispatcher->dispatchLog($old_mock);
    self::assertSame('integration.assignment_controls_delivery', $result->errorCode);
    self::assertFalse($dispatcher->shouldRetryLog($old_mock, $result));
    self::assertSame([], $this->requests);
  }

  /**
   * A migration-marked legacy row still traverses dispatcher and old gateway.
   */
  public function testLegacyRowRunsThroughDispatcherWithoutNewBinding(): void {
    $application = $this->application(1, 1);
    $id = $this->logs->createQueued($application, $this->envelopeBuilder->build($application), 'mock', 'synthetic');
    $this->container->get('database')->update('beantragt_delivery_log')->fields([
      'adapter' => 'fit_connect',
      'legacy_eligible' => 1,
    ])->condition('id', $id)->execute();
    $storage = $this->container->get('config.storage');
    $settings = $storage->read('beantragt_delivery.settings');
    $settings['fit_connect'] = [
      'environment' => 'TEST',
      'client_id' => 'synthetic-legacy',
      'client_secret_env' => 'TRANSFER_FIXTURE',
      'destination_id' => $this->destination(1),
      'service_urn' => 'urn:synthetic:legacy',
      'ars' => '000000000000',
      'schema_uri' => 'urn:beantragt:dev:musterantrag-bescheid:v1',
      'schema_mime_type' => 'application/json',
      'metadata_schema_version' => '2.1.0',
      'use_routing_api' => FALSE,
    ];
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256'])->toPublic()->all();
    $key['key_ops'] = ['wrapKey'];
    $gateway = $this->gateway([
      $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $this->jsonResponse(['status' => 'active', 'metadataVersions' => ['2.1.0'], 'encryptionKid' => 'synthetic-key']),
      $this->jsonResponse($key),
      $this->jsonResponse([
        'submissionId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'caseId' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
      ], 201),
      $this->jsonResponse([]),
      $this->jsonResponse(['events' => []]),
    ]);
    $result = $this->dispatcher($gateway)->dispatchLog($id);
    self::assertTrue($result->isAccepted(), $result->errorCode . ': ' . json_encode($result->metadata));
    self::assertSame(1, $this->logs->load($id)['attempts']);
    self::assertSame('accepted', $this->logs->load($id)['status']);
    self::assertCount(6, $this->requests);
  }

  /**
   * Wires the real dispatcher with a mock-only network boundary.
   */
  private function dispatcher(FitConnectDeliveryGateway $gateway, ?SemanticStatusChanger $changer = NULL): DeliveryDispatcher {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $time->method('getRequestTime')->willReturn(1000);
    return new DeliveryDispatcher($this->manager, $this->container->get('queue'),
      $this->container->get('config.factory'), $this->logs, $this->envelopeBuilder,
      new DeliveryPolicyResolver(), new MockDeliveryGateway(NULL, TRUE),
      new WebhookDeliveryGateway($this->container->get('config.factory'), new Client(['handler' => new MockHandler([])]), $time),
      $gateway, new NullLogger(), $lock, $this->planner, $changer, $this->integrations);
  }

  /**
   * Proves an invalid binding stops the real gateway before any HTTP request.
   */
  private function assertGatewayBlocked(array $row, ApplicationInterface $application, string $code): void {
    $this->logs->markProcessing($row['id'], 1);
    $this->requests = [];
    $result = $this->gateway([])->deliver($this->envelopeBuilder->build($application), $row['id']);
    self::assertSame($code, $result->errorCode);
    self::assertSame([], $this->requests);
  }

  /**
   * Returns matching synthetic destination metadata for a bound transfer.
   */
  private function destinationMetadata(array $row): array {
    return [
      'status' => 'active',
      'metadataVersions' => ['2.1.0'],
      'encryptionKid' => 'synthetic-key',
      'publicServices' => [[
        'identifier' => $row['service_urn'],
        'submissionSchemas' => [[
          'schemaUri' => $row['connection_snapshot']['schema_uri'],
          'mimeType' => 'application/json',
        ],
        ],
      ],
      ],
    ];
  }

  /**
   * Creates a deterministic mock API response without network access.
   */
  private function jsonResponse(array $data, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * Every advertised false mutation flag is enforced by the actual controller.
   */
  public function testFalseFlagsGuardRoutesAndRecoverableDenialsAreAudited(): void {
    $application = $this->application(1, 1);
    $controller = $this->transferController();
    self::assertFalse($this->planner->canCreateTransfer($application));
    self::assertSame(409, $controller->createTransfer($application, $this->mutationRequest())->getStatusCode());
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    $definition = $this->assignment();
    $row = $this->logs->load($this->planner->plan($application));
    self::assertFalse($this->planner->canCreateTransfer($application));
    self::assertFalse($this->planner->canResume($row));
    self::assertFalse($this->planner->canReplan($row, $application));
    self::assertSame(409, $controller->createTransfer($application, $this->mutationRequest())->getStatusCode());
    self::assertSame(409, $controller->resume($application, $row['uuid'], $this->mutationRequest())->getStatusCode());
    self::assertSame(409, $controller->replan($application, $row['uuid'], $this->mutationRequest())->getStatusCode());
    self::assertSame($row, $this->logs->load($row['id']));
    self::assertSame(0, $this->queued());
    $this->integrations->transition('connection', $definition['connection']['uuid'], 'disabled');
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $response = $controller->approveDispatch($application, $row['uuid'], $this->mutationRequest());
      self::assertSame(409, $response->getStatusCode());
      self::assertSame('integration.connection_disabled', json_decode($response->getContent(), TRUE)['error']['code']);
    }
    self::assertSame($row, $this->logs->load($row['id']));
    $audit = $this->container->get('database')->select('beantragt_transfer_audit', 'a')->fields('a', ['code'])
      ->condition('action', 'approve_dispatch')->condition('outcome', 'denied')->execute()->fetchCol();
    self::assertSame(['integration.connection_disabled'], $audit, 'Repeated denied requests retain one durable value-free event per minute.');
  }

  /**
   * Accepted and checkpointed legacy rows fence every new submission route.
   */
  public function testAllHistoricalRowsFenceDuplicateCreation(): void {
    $this->assignment();
    $controller = $this->transferController();
    foreach (['accepted', 'checkpoint'] as $index => $state) {
      $application = $this->application($index + 1, 1);
      // Historical FIT rows predate bindings; the repository refuses to create
      // new unbound rows, so write one the way older releases left them.
      $id = $this->logs->createQueued($application, $this->envelopeBuilder->build($application), 'mock', $this->destination(1));
      $this->container->get('database')->update('beantragt_delivery_log')->fields(['adapter' => 'fit_connect'])->condition('id', $id)->execute();
      if ($state === 'accepted') {
        $this->container->get('database')->update('beantragt_delivery_log')->fields(['status' => 'accepted'])->condition('id', $id)->execute();
      }
      else {
        $this->logs->markProcessing($id, 1);
        $this->logs->checkpointFitConnect($id, 'creating', $this->destination(1));
      }
      self::assertFalse($this->planner->canCreateTransfer($application));
      $response = $controller->createTransfer($application, $this->mutationRequest());
      self::assertSame(409, $response->getStatusCode());
      self::assertSame('integration.already_submitted', json_decode($response->getContent(), TRUE)['error']['code']);
      self::assertCount(1, $this->logs->listForApplication($application));
      $this->expectCode('integration.already_submitted', fn() => $this->planner->plan($application));
    }
    self::assertSame(0, $this->queued());
  }

  /**
   * All classified permanent failures have a new-binding recovery policy.
   */
  public function testPermanentClassCanReplanWithoutRevivingOldPayload(): void {
    $this->assignment();
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    foreach (TransferFailures::PERMANENT as $code) {
      $candidate = [...$row, 'status' => 'failed', 'lastError' => $code];
      self::assertSame(!in_array($code, ['integration.application_anonymised', 'integration.already_submitted'], TRUE), $this->planner->canReplan($candidate), $code);
      $candidate['metadata']['fitConnect'] = ['state' => 'creating'];
      self::assertFalse($this->planner->canReplan($candidate), $code . ' must respect a checkpoint.');
    }
    $this->logs->markResult($row['id'], DeliveryResult::failed('integration.binding_changed', 'Synthetic admin edit.'));
    $new = $this->planner->replan($application, $row['uuid'], 7);
    self::assertNotSame($row['uuid'], $new['uuid']);
    $old = $this->logs->load($row['id']);
    self::assertNull($old['payload_reference']);
    self::assertSame($row['payload_sha256'], $old['payload_sha256']);
    self::assertSame(1, $this->countRows('beantragt_transfer_payload'));
  }

  /**
   * A committed intent survives a missing callback and two recovery passes.
   */
  public function testLostPostCommitPlanningRecoversExactlyOnce(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $recovery = new TransferPlanningRecovery($this->container->get('database'), $this->manager,
      new ScopeAccess($this->manager), $this->planner, $time);
    $transaction = $this->container->get('database')->startTransaction();
    $recovery->record($application);
    unset($transaction);
    // Simulate process death: intentionally do not execute the post-commit
    // planner, then run the exact service invoked by hook_cron twice.
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    self::assertSame(1, $recovery->pendingCount(1));
    self::assertSame(1, $recovery->recover());
    self::assertSame(0, $recovery->recover());
    self::assertSame(1, $this->countRows('beantragt_delivery_log'));
    self::assertSame(1, $this->queued());
    self::assertSame(0, $recovery->pendingCount(1));
    $stopped = $this->application(2, 1);
    $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'inactive');
    $recovery->record($stopped);
    $this->integrations->transition('assignment', $definition['assignment']['uuid'], 'active');
    $recovery->recover();
    self::assertSame([], $this->logs->listForApplication($stopped), 'Activation cannot turn a recorded internal-only decision into an automatic transfer.');
  }

  /**
   * A rule that plans on a status or by staff ignores the submission.
   */
  public function testTriggerMismatchPlansNothingAndNeverFallsBack(): void {
    $definition = $this->assignment(1, 'automatic');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    self::assertNull($this->planner->plan($application));
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    self::assertSame(0, $this->countRows('beantragt_transfer_audit'));
    $explained = $this->planner->explain($application);
    self::assertSame('trigger_deferred', $explained['reason']);
    self::assertSame(['mode' => 'status', 'flag' => 'hand_over'], $explained['trigger']);
    self::assertSame([
      'mode' => 'status',
      'flag' => 'hand_over',
      'assignment_uuid' => $definition['assignment']['uuid'],
      'dispatch_mode' => 'automatic',
    ], $this->planner->triggerOf($application));

    // The automatic path never substitutes mock or webhook for a deferred rule.
    $storage = $this->container->get('config.storage');
    $settings = $storage->read('beantragt_delivery.settings');
    $settings['enabled'] = TRUE;
    $settings['default_adapter'] = 'mock';
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    self::assertNull($this->dispatcher($this->gateway([]))->autoEnqueue($application));
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));

    // A catalogue without a hand-over status is reported, not guessed.
    $this->statusIds[1] = 31;
    $this->catalogue = [31 => ['received']];
    $missing = $this->planner->explain($application);
    self::assertSame('trigger_status_missing', $missing['reason']);
    self::assertSame([['code' => 'integration.coupling_status_missing']], $missing['reasons']);
    $this->catalogue = [31 => ['received'], 32 => ['hand_over']];
    self::assertSame('trigger_deferred', $this->planner->explain($application)['reason']);

    // A manual rule waits for staff; staff plan in every mode.
    $this->setTrigger($definition, 'manual');
    self::assertNull($this->planner->plan($application, NULL, 0, 'status'));
    self::assertSame(['mode' => 'manual', 'flag' => 'hand_over'], $this->planner->explain($application)['trigger']);
    self::assertTrue($this->planner->canCreateTransfer($application));
    $created = $this->planner->createTransfer($application, 7);
    self::assertSame('queued', $created['status']);
    self::assertSame('assigned', $this->planner->explain($application)['reason']);
  }

  /**
   * A status trigger asks the workflow that governs the application.
   *
   * The catalogue holds the hand-over status, but the form's workflow does
   * not: no status change could ever start the transfer, so the case page
   * says "status missing" instead of "pending".
   */
  public function testStatusTriggerAsksTheGoverningWorkflow(): void {
    $definition = $this->assignment(1, 'automatic');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    $this->statusIds[1] = 31;
    $this->catalogue = [31 => ['received'], 32 => ['hand_over']];
    $governing = [31];
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('governingStatusIds')->willReturnCallback(function (ApplicationInterface $asked, ?int $group) use ($application, &$governing): array {
      self::assertSame($application, $asked);
      self::assertSame(1, $group);
      return $governing;
    });
    $planner = new TransferPlanner(...[...$this->plannerArguments, $resolver]);
    $missing = $planner->explain($application);
    self::assertSame('trigger_status_missing', $missing['reason']);
    self::assertSame([['code' => 'integration.coupling_status_missing']], $missing['reasons']);
    // Without the resolver the catalogue-wide answer stays as before.
    self::assertSame('trigger_deferred', $this->planner->explain($application)['reason']);
    // A form whose workflow holds the status waits for it.
    $governing = [31, 32];
    self::assertSame('trigger_deferred', $planner->explain($application)['reason']);
  }

  /**
   * Bulk status planning waits even when the setter may approve dispatch.
   */
  public function testBulkStatusNeverAutoApprovesAndProjectsTriggerPolicy(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1, NULL, 'bulk');
    $before = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertFalse($before['meta']['trigger']['autoApprove']);
    self::assertNull($before['meta']['trigger']['blockedBy']);
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('planningRefusal')->willReturnCallback(static fn($a, $trigger): ?string => $trigger === 'staff' && $a->get('field_origin')->value === 'bulk' ? 'integration.handover_status_required' : NULL);
    $gate->expects(self::once())->method('onPlanning');
    $this->gates->addGate($gate);
    self::assertFalse($this->planner->canCreateTransfer($application));
    $restricted = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertSame([['code' => 'integration.handover_status_required']], $restricted['meta']['delivery']['reasons']);
    $ordinary = $this->application(2, 1);
    $ordinary_projection = json_decode($this->transferController()->listTransfers($ordinary)->getContent(), TRUE);
    self::assertSame([], $ordinary_projection['meta']['delivery']['reasons']);
    $this->expectCode('integration.handover_status_required', fn() => $this->planner->createTransfer($application, 7));
    $id = $this->planner->planOnStatus($application, 7);
    self::assertSame('awaiting_approval', $this->logs->load($id)['status']);
    self::assertSame($id, $this->planner->planOnStatus($application, 7));
    self::assertSame(0, $this->queued());
    $projection = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertFalse($projection['meta']['canCreateTransfer']);
    self::assertSame([], $projection['meta']['delivery']['reasons']);
    self::assertNull($projection['data'][0]['meta']['approvalRefusal']);
    self::assertTrue($projection['data'][0]['meta']['canApproveDispatch']);
  }

  /**
   * A refusal and a throwing observer never invent a failed transfer plan.
   */
  public function testPlanningRefusalAndObserverFailure(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    $stopped = TRUE;
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('planningRefusal')->willReturnCallback(static function () use (&$stopped): ?string {
      return $stopped ? 'integration.handover_agent_stopped' : NULL;
    });
    $gate->expects(self::exactly(2))->method('onPlanning')->willThrowException(new \RuntimeException('must not escape'));
    $this->gates->addGate($gate);
    self::assertNull($this->planner->planOnStatus($application, 7));
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    $projection = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertSame('integration.handover_agent_stopped', $projection['meta']['trigger']['blockedBy']);
    $stopped = FALSE;
    self::assertNotNull($this->planner->planOnStatus($application, 7));
    self::assertNotSame('planning_failed', $this->planner->explain($application)['reason']);
  }

  /**
   * Approval refusals roll back and send-time refusals park for human resume.
   */
  public function testApprovalGatesAtApprovalSendAndResume(): void {
    $this->assignment(1, 'approval');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $refuse = TRUE;
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('approvalRefusal')->willReturnCallback(static function () use (&$refuse): ?string {
      return $refuse ? 'integration.four_eyes_required' : NULL;
    });
    $gate->expects(self::exactly(2))->method('onApproved')->with($application, self::callback(static fn(array $approved): bool => $approved['dispatch_approval_status'] === 'approved'), 7);
    $this->gates->addGate($gate);
    $this->expectCode('integration.four_eyes_required', fn() => $this->planner->approve($application, $row['uuid'], 7));
    self::assertSame('pending', $this->logs->load($row['id'])['dispatch_approval_status']);
    $projection = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertSame('integration.four_eyes_required', $projection['data'][0]['meta']['approvalRefusal']);
    self::assertFalse($projection['data'][0]['meta']['canApproveDispatch']);
    $refuse = FALSE;
    $this->planner->approve($application, $row['uuid'], 7);
    $refuse = TRUE;
    $dispatcher = $this->dispatcher($this->gateway([]));
    self::assertSame('integration.four_eyes_required', $dispatcher->dispatchLog($row['id'])->errorCode);
    self::assertSame('blocked', $this->logs->load($row['id'])['status']);
    $projection = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE);
    self::assertFalse($projection['data'][0]['meta']['canResume']);
    $this->expectCode('integration.four_eyes_required', fn() => $this->planner->resume($application, $row['uuid'], 7));
    $refuse = FALSE;
    self::assertSame('queued', $this->planner->resume($application, $row['uuid'], 7)['status']);
  }

  /**
   * An approval observer failure rolls back approval, audit and queue writes.
   */
  public function testApprovalObserverRollsBack(): void {
    $this->assignment(1, 'approval');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('onApproved')->willThrowException(new \RuntimeException('rollback'));
    $this->gates->addGate($gate);
    $response = $this->transferController()->approveDispatch($application, $row['uuid'], $this->mutationRequest());
    self::assertSame(500, $response->getStatusCode());
    self::assertSame(['error' => ['code' => 'integration.internal_error']], json_decode($response->getContent(), TRUE));
    $audit = $this->container->get('database')->select('beantragt_transfer_audit', 'a')->fields('a', ['code'])
      ->condition('transfer_uuid', $row['uuid'])->condition('action', 'approve_dispatch')->condition('outcome', 'denied')->execute()->fetchCol();
    self::assertSame(['integration.internal_error'], $audit);
    self::assertSame('awaiting_approval', $this->logs->load($row['id'])['status']);
    self::assertSame('pending', $this->logs->load($row['id'])['dispatch_approval_status']);
    self::assertSame(0, $this->queued());
  }

  /**
   * Retry parks a newly forbidden stored approval for another person's resume.
   */
  public function testRetryAfterFourEyesSwitchOnParksAndSecondPersonSends(): void {
    $this->assignment(1, 'approval');
    $application = $this->application(1, 1, NULL, 'bulk');
    $row = $this->logs->load($this->planner->plan($application));
    $four_eyes = FALSE;
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('approvalRefusal')->willReturnCallback(static function ($a, $r, int $uid) use (&$four_eyes): ?string {
      return $four_eyes && $uid === 7 ? 'integration.four_eyes_required' : NULL;
    });
    $this->gates->addGate($gate);
    $this->planner->approve($application, $row['uuid'], 7);
    $failure = new ConnectException('synthetic timeout', new GuzzleRequest('POST', 'https://synthetic.invalid/token'));
    $result = $this->dispatcher($this->gateway([$failure]))->dispatchLog($row['id']);
    self::assertSame('fit_connect.token_request_failed', $result->errorCode);
    self::assertSame('failed', $this->logs->load($row['id'])['status']);
    $four_eyes = TRUE;
    foreach ([7, 8] as $uid) {
      $projection = json_decode($this->transferController(TRUE, $uid)->listTransfers($application)->getContent(), TRUE);
      self::assertFalse($projection['data'][0]['meta']['canRetry']);
    }
    $response = $this->transferController(TRUE, 8)->retry($application, $row['uuid'], $this->mutationRequest());
    self::assertSame(409, $response->getStatusCode());
    self::assertSame(['error' => ['code' => 'integration.four_eyes_required']], json_decode($response->getContent(), TRUE));
    $blocked = $this->logs->load($row['id']);
    self::assertSame('blocked', $blocked['status']);
    self::assertSame($row['payload_sha256'], $blocked['payload_sha256']);
    self::assertSame(7, $blocked['dispatch_approved_uid']);
    $projection = json_decode($this->transferController(TRUE, 8)->listTransfers($application)->getContent(), TRUE);
    self::assertTrue($projection['data'][0]['meta']['canResume']);
    $this->expectCode('integration.four_eyes_required', fn() => $this->planner->resume($application, $row['uuid'], 7));
    $resumed = $this->planner->resume($application, $row['uuid'], 8);
    self::assertSame(8, $resumed['dispatch_approved_uid']);
    self::assertSame('queued', $resumed['status']);
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $gateway = $this->gateway([
      $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $this->jsonResponse($this->destinationMetadata($row)),
      $this->jsonResponse($public),
      $this->jsonResponse([
        'submissionId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'caseId' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
      ], 201),
      $this->jsonResponse([]),
      $this->jsonResponse(['events' => []]),
    ]);
    self::assertTrue($this->dispatcher($gateway)->dispatchLog($row['id'])->isAccepted());
    self::assertSame('accepted', $this->logs->load($row['id'])['status']);
  }

  /**
   * Unexpected status-approval failures are value-free and durably audited.
   */
  public function testStatusApprovalUnexpectedFailureIsNormalizedAndAudited(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('approvalRefusal')->willThrowException(new \RuntimeException('private failure detail'));
    $this->gates->addGate($gate);
    $this->plannerLogger->expects(self::once())->method('error')->with(self::anything(), ['@class' => \RuntimeException::class]);
    try {
      $this->planner->planOnStatus($application, 7);
      self::fail('Unexpected approval failure escaped normalization.');
    }
    catch (IntegrationException $exception) {
      self::assertSame('integration.internal_error', $exception->errorCode);
      self::assertSame(500, $exception->status);
      self::assertNull($exception->getPrevious());
    }
    $row = $this->logs->listForApplication($application, 100, TRUE)[0];
    self::assertSame('awaiting_approval', $row['status']);
    $audit = $this->container->get('database')->select('beantragt_transfer_audit', 'a')->fields('a', ['code'])
      ->condition('transfer_uuid', $row['uuid'])->condition('action', 'plan_on_status')->condition('outcome', 'denied')->execute()->fetchCol();
    self::assertSame(['integration.internal_error'], $audit);
    self::assertSame(0, $this->queued());
  }

  /**
   * A normalized collector failure also leaves a denied status audit.
   */
  public function testStatusApprovalObserverFailureStaysNormalized(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    $gate = $this->createMock(TransferGateInterface::class);
    $gate->method('onApproved')->willThrowException(new \RuntimeException('private observer detail'));
    $this->gates->addGate($gate);
    try {
      $this->planner->planOnStatus($application, 7);
      self::fail('Internal approval failure was swallowed.');
    }
    catch (IntegrationException $exception) {
      self::assertSame('integration.internal_error', $exception->errorCode);
      self::assertSame(500, $exception->status);
    }
    self::assertSame('awaiting_approval', $this->logs->listForApplication($application, 100, TRUE)[0]['status']);
    $audit = $this->container->get('database')->select('beantragt_transfer_audit', 'a')->fields('a', ['code'])
      ->condition('application_id', 1)->condition('action', 'plan_on_status')->condition('outcome', 'denied')->execute()->fetchCol();
    self::assertSame(['integration.internal_error'], $audit);
    self::assertSame(0, $this->queued());
  }

  /**
   * Batch reads restrict ids and states and never expose raw error text.
   */
  public function testTransferBatchReader(): void {
    $this->assignment(1, 'approval');
    $a = $this->application(1, 1);
    $b = $this->application(2, 1);
    $row = $this->logs->load($this->planner->plan($a));
    $this->planner->plan($b);
    self::assertSame([], $this->planner->transfersForApplications([], ['awaiting_approval']));
    self::assertSame([], $this->planner->transfersForApplications([1], []));
    self::assertSame([], $this->planner->transfersForApplications([1], ['failed']));
    $database = $this->container->get('database');
    foreach ([
      'integration.four_eyes_required: private detail' => 'integration.four_eyes_required',
      'private detail' => 'integration.internal_error',
    ] as $raw => $code) {
      $database->update('beantragt_delivery_log')->fields(['last_error' => $raw])->condition('id', $row['id'])->execute();
      $rows = $this->planner->transfersForApplications([1], ['awaiting_approval']);
      self::assertCount(1, $rows);
      self::assertSame(['uuid', 'application_id', 'status', 'code', 'changed'], array_keys($rows[0]));
      self::assertSame(1, $rows[0]['application_id']);
      self::assertSame($code, $rows[0]['code']);
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->planner->transfersForApplications(range(1, 501), ['awaiting_approval']);
  }

  /**
   * Status trigger matrix: dispatch mode times the person's dispatch right.
   */
  public function testStatusTriggerPlansAndApprovesWithTheSettersRight(): void {
    require_once $this->root . '/modules/custom/beantragt_delivery/beantragt_delivery.module';
    $this->container->set('entity_type.manager', $this->manager);
    $this->container->set('beantragt_delivery.transfer_planner', $this->planner);
    $audit = fn(int $application_id): array => $this->container->get('database')->select('beantragt_transfer_audit', 'a')
      ->fields('a', ['action', 'outcome', 'uid'])->condition('application_id', $application_id)->orderBy('id')->execute()->fetchAll(FetchAs::Associative);

    // automatic: queued at once, whoever set the status.
    $automatic = $this->assignment(1, 'automatic');
    $this->setTrigger($automatic, 'status');
    $application = $this->application(1, 1);
    _beantragt_delivery_plan_on_status_after_commit($application, 7);
    $row = $this->logs->listForApplication($application, 100, TRUE)[0];
    self::assertSame('queued', $row['status']);
    self::assertSame(1, $this->queued());
    self::assertSame([['action' => 'plan_on_status', 'outcome' => 'success', 'uid' => '7']], $audit(1));
    // Setting the trigger status again creates no second transfer.
    _beantragt_delivery_plan_on_status_after_commit($application, 7);
    self::assertCount(1, $this->logs->listForApplication($application, 100, TRUE));
    self::assertSame('noop', $audit(1)[1]['outcome']);
    $this->integrations->transition('assignment', $automatic['assignment']['uuid'], 'inactive');

    // approval, with the right: the status change releases the dispatch.
    $approval = $this->assignment(1, 'approval');
    $this->setTrigger($approval, 'status');
    $approved = $this->application(2, 1);
    _beantragt_delivery_plan_on_status_after_commit($approved, 7);
    $row = $this->logs->listForApplication($approved, 100, TRUE)[0];
    self::assertSame('queued', $row['status']);
    self::assertSame('approved', $row['dispatch_approval_status']);
    self::assertSame(7, (int) $row['dispatch_approved_uid']);
    self::assertSame(['plan_on_status', 'approve_dispatch'], array_column($audit(2), 'action'));

    // approval, without the right: the transfer waits for "Versand freigeben".
    $this->approverAllowed = FALSE;
    $waiting = $this->application(3, 1);
    _beantragt_delivery_plan_on_status_after_commit($waiting, 7);
    $row = $this->logs->listForApplication($waiting, 100, TRUE)[0];
    self::assertSame('awaiting_approval', $row['status']);
    self::assertSame('pending', $row['dispatch_approval_status']);
    self::assertSame(['plan_on_status'], array_column($audit(3), 'action'));

    // An unconfirmed application is never transferred.
    $this->approverAllowed = TRUE;
    $unconfirmed = $this->application(4, 1);
    $this->confirmed[4] = FALSE;
    _beantragt_delivery_plan_on_status_after_commit($unconfirmed, 7);
    self::assertSame([], $this->logs->listForApplication($unconfirmed, 100, TRUE));
  }

  /**
   * Only a person entering the trigger status of a status rule triggers.
   */
  public function testStatusTriggerSubscriberConditions(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $storage = $this->container->get('config.storage');
    $settings = $storage->read('beantragt_delivery.settings');
    $settings['enabled'] = TRUE;
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $scheduled = [];
    $subscriber = new class($this->container->get('config.factory'), $this->planner, new NullLogger(), $scheduled) extends StatusTriggerSubscriber {

      /**
       * Records scheduled calls instead of planning.
       */
      public function __construct($config, $planner, $logger, private array &$calls) {
        parent::__construct($config, $planner, $logger);
      }

      /**
       * {@inheritdoc}
       */
      protected function schedule(ApplicationInterface $application, int $actor_uid): void {
        $this->calls[] = [(int) $application->id(), $actor_uid];
      }

    };
    $status = function (array $flags): WorkflowStatusInterface {
      $status = $this->createMock(WorkflowStatusInterface::class);
      $status->method('hasSemanticFlag')->willReturnCallback(static fn(string $flag): bool => in_array($flag, $flags, TRUE));
      return $status;
    };
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $fire = function (ApplicationInterface $application, array $from, array $to) use ($subscriber, $status, $account): void {
      $subscriber->onWorkflowTransition(new WorkflowTransitionEvent($application, 'from', 'to', $account, 'From', 'To', $status($from), $status($to)));
    };

    // A person sets the hand-over status: scheduled with that person.
    $fire($this->application(1, 1), [], ['hand_over']);
    self::assertSame([[1, 7]], $scheduled);
    // Between two hand-over statuses, or to another status: nothing.
    $fire($this->application(2, 1), ['hand_over'], ['hand_over']);
    $fire($this->application(3, 1), ['received'], ['in_case_system']);
    // Automatic changes and administrative resets never trigger.
    $system = $this->application(4, 1);
    $system->method('getSystemStatusChange')->willReturn(SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);
    $fire($system, [], ['hand_over']);
    $reset = $this->application(5, 1);
    $reset->method('isWorkflowStatusResetAllowed')->willReturn(TRUE);
    $fire($reset, [], ['hand_over']);
    // Unconfirmed applications never trigger.
    $unconfirmed = $this->application(6, 1);
    $this->confirmed[6] = FALSE;
    $fire($unconfirmed, [], ['hand_over']);
    self::assertSame([[1, 7]], $scheduled);
    // A rule planning on submission ignores the status.
    $this->setTrigger($definition, 'submission');
    $fire($this->application(7, 1), [], ['hand_over']);
    self::assertSame([[1, 7]], $scheduled);
    // Delivery switched off: nothing.
    $this->setTrigger($definition, 'status');
    $settings['enabled'] = FALSE;
    $storage->write('beantragt_delivery.settings', $settings);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    $fire($this->application(8, 1), [], ['hand_over']);
    self::assertSame([[1, 7]], $scheduled);
  }

  /**
   * Intents follow the rule's trigger; recovery rechecks the setter's right.
   */
  public function testPlanningIntentsFollowTheTrigger(): void {
    $definition = $this->assignment(1, 'approval');
    $this->setTrigger($definition, 'status');
    $application = $this->application(1, 1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $recovery = new TransferPlanningRecovery($this->container->get('database'), $this->manager,
      new ScopeAccess($this->manager), $this->planner, $time);
    $intent = fn(): array => (array) $this->container->get('database')->select('beantragt_transfer_plan', 'p')->fields('p')
      ->condition('application_id', 1)->execute()->fetchAssoc();

    // The submission of a status-triggered rule is decided at once.
    $recovery->record($application);
    self::assertSame('decided', $intent()['status']);
    self::assertSame('integration.trigger_deferred', $intent()['code']);
    self::assertSame(0, $recovery->recover());
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));

    // A status intent whose trigger status was left (rollback, moved on)
    // never sends: neither checksum guard notices a reverted status.
    $this->catalogue = [31 => ['received'], 32 => ['hand_over']];
    $this->statusIds[1] = 31;
    $recovery->record($application, 'status', 7);
    self::assertSame('pending', $intent()['status']);
    self::assertSame(0, $recovery->recover());
    self::assertSame('decided', $intent()['status']);
    self::assertSame('integration.trigger_reverted', $intent()['code']);
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));

    // A lost status trigger is recovered while the status still holds; the
    // right is checked live.
    $this->statusIds[1] = 32;
    $recovery->record($application, 'status', 7);
    self::assertSame('pending', $intent()['status']);
    self::assertSame('status', $intent()['trigger_source']);
    self::assertSame(7, (int) $intent()['actor_uid']);
    $this->approverAllowed = FALSE;
    self::assertSame(1, $recovery->recover());
    $row = $this->logs->listForApplication($application, 100, TRUE)[0];
    self::assertSame('awaiting_approval', $row['status']);
    self::assertSame('decided', $intent()['status']);
  }

  /**
   * Leaving the trigger status revokes a pending status intent at once.
   */
  public function testLeavingTheTriggerStatusRevokesThePendingIntent(): void {
    $definition = $this->assignment(1, 'automatic');
    $this->setTrigger($definition, 'status');
    $this->catalogue = [31 => ['received'], 32 => ['hand_over']];
    $this->statusIds[1] = 32;
    $application = $this->application(1, 1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $recovery = new TransferPlanningRecovery($this->container->get('database'), $this->manager,
      new ScopeAccess($this->manager), $this->planner, $time);
    $intent = fn(): array => (array) $this->container->get('database')->select('beantragt_transfer_plan', 'p')->fields('p')
      ->condition('application_id', 1)->execute()->fetchAssoc();
    $subscriber = new StatusTriggerSubscriber($this->container->get('config.factory'), $this->planner, new NullLogger(), $recovery);
    $status = function (array $flags): WorkflowStatusInterface {
      $status = $this->createMock(WorkflowStatusInterface::class);
      $status->method('hasSemanticFlag')->willReturnCallback(static fn(string $flag): bool => in_array($flag, $flags, TRUE));
      return $status;
    };
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);

    // A pending submission intent is not a status intent: it stays.
    $recovery->record($application);
    $this->container->get('database')->update('beantragt_transfer_plan')->fields(['status' => 'pending', 'code' => ''])->condition('application_id', 1)->execute();
    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent($application, 'from', 'to', $account, 'From', 'To', $status(['hand_over']), $status(['received'])));
    self::assertSame('pending', $intent()['status']);

    // The planning of the status trigger failed transiently; then a rollback
    // (an administrative reset) leaves the trigger status.
    $recovery->record($application, 'status', 7);
    $reset = $this->createMock(ApplicationInterface::class);
    $reset->method('id')->willReturn('1');
    $reset->method('isWorkflowStatusResetAllowed')->willReturn(TRUE);
    $this->statusIds[1] = 31;
    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent($reset, 'from', 'to', $account, 'From', 'To', $status(['hand_over']), $status(['received'])));
    self::assertSame('decided', $intent()['status']);
    self::assertSame('integration.trigger_reverted', $intent()['code']);
    // Even if the case returns to the trigger status later, cron does not
    // send on the old intent.
    $this->statusIds[1] = 32;
    self::assertSame(0, $recovery->recover());
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
  }

  /**
   * The accepted transfer passes the connection's closure and follow-up.
   */
  public function testAcceptedTransferPassesClosureRuleAndFollowUp(): void {
    $definition = $this->assignment(1, 'automatic');
    $this->integrations->updatePresentation($definition['connection']['uuid'], [
      'closure_rule' => 'on_receipt',
      'follow_up' => ['receipt' => 'closed'],
    ]);
    $application = $this->application(1, 1);
    $id = $this->planner->plan($application);
    $row = $this->logs->load($id);
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $gateway = $this->gateway([
      $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $this->jsonResponse($this->destinationMetadata($row)),
      $this->jsonResponse($public),
      $this->jsonResponse([
        'submissionId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'caseId' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
      ], 201),
      $this->jsonResponse([]),
      $this->jsonResponse(['events' => []]),
    ]);
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::once())->method('caseSystemAcceptedOutcome')->with(
      self::anything(),
      'on_receipt',
      [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'filed' => 'filed',
        'decision' => 'decision_issued',
        'receipt' => 'closed',
      ],
    )->willReturn(['changed' => TRUE, 'unchanged' => NULL]);
    $result = $this->dispatcher($gateway, $changer)->dispatchLog($id);
    self::assertTrue($result->isAccepted());
  }

  /**
   * An accepted transfer that left the start status says why (S9b).
   *
   * The changer's reason lands on the accepted row; a moved case, or one
   * without a reason, leaves no note.
   */
  public function testAcceptedTransferRecordsWhyTheStatusStayed(): void {
    $this->assignment(1, 'automatic');
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    foreach ([1 => 'no_transition', 2 => NULL] as $application_id => $reason) {
      $id = $this->planner->plan($this->application($application_id, 1));
      $row = $this->logs->load($id);
      $gateway = $this->gateway([
        $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
        $this->jsonResponse($this->destinationMetadata($row)),
        $this->jsonResponse($public),
        $this->jsonResponse([
          'submissionId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
          'caseId' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        ], 201),
        $this->jsonResponse([]),
        $this->jsonResponse(['events' => []]),
      ]);
      $changer = $this->createMock(SemanticStatusChanger::class);
      $changer->expects(self::once())->method('caseSystemAcceptedOutcome')
        ->willReturn(['changed' => FALSE, 'unchanged' => $reason]);
      self::assertTrue($this->dispatcher($gateway, $changer)->dispatchLog($id)->isAccepted());
      $metadata = $this->logs->load($id)['metadata'];
      if ($reason === NULL) {
        self::assertArrayNotHasKey('acceptance', $metadata);
      }
      else {
        self::assertSame(['statusChanged' => FALSE, 'reason' => $reason], $metadata['acceptance']);
      }
    }
    // A row that is not accepted takes no note.
    $failed = $this->planner->plan($this->application(3, 1));
    $this->logs->recordAcceptanceStatus($failed, 'no_transition');
    self::assertArrayNotHasKey('acceptance', $this->logs->load($failed)['metadata']);
  }

  /**
   * A failed or refused transfer never moves the status.
   *
   * Only an accepted transfer reaches the status changer: a transport error
   * before the submission and a submission the destination refuses leave
   * the application where it is.
   */
  public function testFailedOrRefusedTransferMovesNoStatus(): void {
    $this->assignment(1, 'automatic');
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::never())->method('caseSystemAcceptedOutcome');
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    foreach (['transport error' => 1, 'refused submission' => 2] as $scenario => $application_id) {
      $id = $this->planner->plan($this->application($application_id, 1));
      $row = $this->logs->load($id);
      $responses = $scenario === 'transport error'
        ? [$this->jsonResponse(['error' => 'unavailable'], 503)]
        : [
          $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
          $this->jsonResponse($this->destinationMetadata($row)),
          $this->jsonResponse($public),
          $this->jsonResponse(['detail' => 'refused'], 422),
        ];
      $result = $this->dispatcher($this->gateway($responses), $changer)->dispatchLog($id);
      self::assertFalse($result->isAccepted(), $scenario);
      self::assertNotSame('accepted', $this->logs->load($id)['status'], $scenario);
    }
  }

  /**
   * Writes a trigger straight into the rule, bypassing the catalogue check.
   */
  private function setTrigger(array $definition, string $mode): void {
    $this->container->get('database')->update('beantragt_integration_assignment')
      ->fields(['transfer_trigger' => $mode, 'trigger_flag' => 'hand_over'])
      ->condition('uuid', $definition['assignment']['uuid'])->execute();
  }

  /**
   * Workflow status storage over the synthetic catalogue of group 1.
   */
  private function workflowStatusStorage(): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $flag = NULL;
    foreach (['accessCheck', 'range', 'count'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('condition')->willReturnCallback(function (string $field, mixed $value) use ($query, &$flag): QueryInterface {
      if ($field === 'field_semantic_flags') {
        $flag = $value;
      }
      return $query;
    });
    $query->method('execute')->willReturnCallback(function () use (&$flag): int {
      return count(array_filter($this->catalogue, static fn(array $flags): bool => in_array($flag, $flags, TRUE)));
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->willReturnCallback(fn($id): ?WorkflowStatusInterface => $this->fixtureStatus((int) $id));
    $storage->method('loadMultiple')->willReturnCallback(fn(array $ids): array => array_filter(array_combine($ids, array_map(fn($id): ?WorkflowStatusInterface => $this->fixtureStatus((int) $id), $ids))));
    return $storage;
  }

  /**
   * One status of the synthetic catalogue, or NULL.
   */
  private function fixtureStatus(int $id): ?WorkflowStatusInterface {
    if (!isset($this->catalogue[$id])) {
      return NULL;
    }
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('getGroupId')->willReturn(1);
    $status->method('hasSemanticFlag')->willReturnCallback(fn(string $flag): bool => in_array($flag, $this->catalogue[$id] ?? [], TRUE));
    return $status;
  }

  /**
   * Both routes apply the identical tenant-move disclosure boundary.
   */
  public function testBothHistoryRoutesUseOneNeutralProjection(): void {
    $this->assignment();
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $this->container->get('database')->update('beantragt_delivery_log')->fields(['scope_group_id' => 2])->condition('id', $row['id'])->execute();
    $current = json_decode($this->transferController()->listTransfers($application)->getContent(), TRUE)['data'];
    $legacy = new DeliveryController($this->logs, $this->dispatcher($this->gateway([])), new ScopeAccess($this->manager));
    $old = json_decode($legacy->listDeliveries($application)->getContent(), TRUE)['data'];
    self::assertSame($current, $old);
    self::assertSame('transferred_by_another_tenant', $old[0]['transport_status']);
    foreach (['connection', 'mapping', 'approval', 'target', 'metadata', 'externalId', 'receipt', 'lastError'] as $key) {
      self::assertArrayNotHasKey($key, $old[0]);
    }
  }

  /**
   * Constructs the management handler with fixed synthetic authentication.
   */
  private function integrationController(LoggerInterface $logger): IntegrationController {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturn(TRUE);
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $scopes = new ScopeAccess($this->manager);
    return new IntegrationController($this->integrations, $scopes, new SyntheticDataContractRegistry(contractDirectory: dirname(__DIR__, 2) . '/fixtures/synthetic-contracts'),
      new IntegrationCredentialProfiles($this->container->get('config.factory')), $account, $csrf,
      new IntegrationAudit($logger, $time, $account, $this->container->get('database')),
      new IntegrationFormCatalog($this->manager, $this->container->get('database'), $scopes, $account));
  }

  /**
   * Builds the adoption route request as the static route declares it.
   */
  private function adoptionRequest(string $uuid, string $body): Request {
    $path = '/api/admin/integrations/assignments/' . $uuid . '/adopt-revision';
    $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    $request->headers->set('X-CSRF-Token', 'synthetic-token');
    $request->attributes->add([
      'uuid' => $uuid,
      'integration_resource' => 'assignment',
      'integration_operation' => 'adopt_revision',
    ]);
    return $request;
  }

  /**
   * Constructs the real route handler with fixed synthetic authentication.
   */
  private function transferController(bool $may_approve = TRUE, int $uid = 7): TransferController {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn((string) $uid);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $may_approve || $permission !== 'approve application dispatch');
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(TRUE);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $audit = new IntegrationAudit(new NullLogger(), $time, $account, $this->container->get('database'));
    return new TransferController($this->planner, new ScopeAccess($this->manager), $account, $csrf, $audit,
      $this->manager, new ExternalResponseRepository($this->container->get('database')), NULL, $this->gates);
  }

  /**
   * A recoverable failure after POST is failed reconciliation, never parking.
   */
  public function testCheckpointFailureKeepsAttemptAndCannotResume(): void {
    $definition = $this->assignment(1, 'automatic');
    $application = $this->application(1, 1);
    $row = $this->logs->load($this->planner->plan($application));
    $key = JWKFactory::createRSAKey(4096, ['kid' => 'synthetic-key', 'alg' => 'RSA-OAEP-256']);
    $public = $key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $gateway = $this->gateway([
      $this->jsonResponse(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $this->jsonResponse($this->destinationMetadata($row)),
      $this->jsonResponse($public),
      function () use ($definition): Response {
        $this->integrations->transition('connection', $definition['connection']['uuid'], 'disabled');
        return $this->jsonResponse([
          'submissionId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
          'caseId' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        ], 201);
      },
    ]);
    $dispatcher = $this->dispatcher($gateway);
    $result = $dispatcher->dispatchLog($row['id']);
    $failed = $this->logs->load($row['id']);
    self::assertSame('integration.connection_disabled', $result->errorCode);
    self::assertSame('failed', $failed['status']);
    self::assertSame(1, $failed['attempts']);
    self::assertSame(1, $failed['automatic_attempts']);
    self::assertNotEmpty($failed['metadata']['fitConnect']);
    self::assertFalse($this->planner->canResume($failed));
    self::assertFalse($this->planner->canRetry($failed));
    self::assertFalse($this->planner->canReplan($failed));
    self::assertFalse($dispatcher->shouldRetryLog($row['id'], $result));
    self::assertCount(4, $this->requests);
  }

  /**
   * Both mock route modes report the assignment stop as an ordinary conflict.
   */
  public function testMockRouteReportsAssignmentConflict(): void {
    $this->environment['BEANTRAGT_ENV'] = getenv('BEANTRAGT_ENV');
    putenv('BEANTRAGT_ENV=test');
    $this->assignment();
    $application = $this->application(1, 1);
    $controller = new DeliveryController($this->logs, $this->dispatcher($this->gateway([])), new ScopeAccess($this->manager));
    foreach ([FALSE, TRUE] as $process) {
      $request = Request::create('/synthetic-mock', 'POST', content: json_encode(['process' => $process], JSON_THROW_ON_ERROR));
      $response = $controller->enqueueMock($application, $request);
      self::assertSame(409, $response->getStatusCode());
      self::assertSame('integration.assignment_controls_delivery', json_decode($response->getContent(), TRUE)['error']['code']);
    }
    self::assertSame(0, $this->countRows('beantragt_delivery_log'));
    self::assertSame(0, $this->queued());
  }

  /**
   * Makes a CSRF-authenticated request with no writable binding parameters.
   */
  private function mutationRequest(): Request {
    return Request::create('/synthetic-transfer', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'synthetic']);
  }

  /**
   * Creates a valid real SQL assignment without enqueuing existing cases.
   */
  private function assignment(int $scope = 1, string $mode = 'approval', string $service = 'urn:synthetic:service'): array {
    $connection = $this->integrations->create('connection', [
      'scope_group_id' => $scope,
      'label' => 'Synthetic TEST connection ' . $scope,
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'tenant_' . $scope,
      'destination_id' => $this->destination($scope),
      'service_urn' => $service,
      'service_name' => 'Synthetic service',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], 7);
    $this->integrations->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->integrations->create('mapping', [
      'scope_group_id' => $scope,
      'label' => 'Synthetic mapping',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], 7);
    $version = $this->integrations->createVersion($mapping['uuid'], [
      'form_schema_uuid' => $this->formUuid($scope),
      'compatible_revisions' => [$scope * 10 + 1],
      'rules' => $this->fixture['rules'],
    ], 7);
    $this->integrations->transitionVersion($mapping['uuid'], 1, 'active');
    $assignment = $this->integrations->create('assignment', [
      'scope_group_id' => $scope,
      'form_schema_uuid' => $this->formUuid($scope),
      'compatible_revisions' => [$scope * 10 + 1],
      'service_urn' => $service,
      'connection_uuid' => $connection['uuid'],
      'mapping_uuid' => $mapping['uuid'],
      'mapping_version' => 1,
      'dispatch_mode' => $mode,
    ], 7);
    $this->integrations->transition('assignment', $assignment['uuid'], 'active');
    return compact('connection', 'mapping', 'version', 'assignment');
  }

  /**
   * Builds mutable in-memory entity values with a real base row for locks.
   */
  private function application(int $id, int $scope, ?GroupInterface $organisation = NULL, string $origin = 'web'): ApplicationInterface {
    $this->data[$id] = $this->syntheticData();
    $this->revision[$id] = $scope * 10 + 1;
    $this->confirmed[$id] = TRUE;
    $form = $this->forms[$scope];
    $fields = [
      'field_schema' => $this->field(static fn() => $form),
      'field_schema_revision_id' => $this->field(fn() => $this->revision[$id], fn(): bool => $this->revision[$id] === 0),
      'field_group' => $form->get('field_group'),
      'field_origin' => $this->field(static fn() => $origin),
    ];
    if ($organisation !== NULL) {
      // Jurisdiction-direct forms route their cases to an organisation.
      $fields['field_organisation'] = $this->field(static fn() => $organisation);
    }
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn((string) $id);
    $application->method('uuid')->willReturn(sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id));
    $application->method('getReferenceNumber')->willReturn('SYNTHETIC-' . $id);
    $application->method('getSchemaVersion')->willReturn(1);
    $application->method('getCreatedTime')->willReturn(1789689600);
    $application->method('access')->willReturn(TRUE);
    // Access results add the entity as a cacheable dependency.
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:' . $id]);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $application->method('getData')->willReturnCallback(fn() => json_encode($this->data[$id], JSON_THROW_ON_ERROR));
    $application->method('isEmailConfirmed')->willReturnCallback(fn() => $this->confirmed[$id]);
    $application->method('getWorkflowStatusId')->willReturnCallback(fn(): ?int => $this->statusIds[$id] ?? NULL);
    $application->method('hasField')->willReturnCallback(static fn($name) => isset($fields[$name]));
    $application->method('get')->willReturnCallback(static fn($name) => $fields[$name]);
    $this->applications[$id] = $application;
    $this->container->get('database')->insert('application')->fields(['id' => $id])->execute();
    return $application;
  }

  /**
   * Creates distinct immutable historical content at the mocked entity edge.
   */
  private function historicalForm(int $scope, int $revision, array $schema, int $version = 1): FormSchemaInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn((string) $scope);
    $form->method('uuid')->willReturn($this->formUuid($scope));
    $form->method('getRevisionId')->willReturn($revision);
    $form->method('getVersion')->willReturn($version);
    $form->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_group');
    $schema_field = $this->field(static fn(): string => json_encode($schema, JSON_THROW_ON_ERROR));
    $group_field = $this->forms[$scope]->get('field_group');
    $form->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $name === 'field_schema' ? $schema_field : $group_field);
    return $form;
  }

  /**
   * Generates only synthetic data matching every shipped source field.
   */
  private function syntheticData(): array {
    $data = [];
    $fields = array_column($this->fixture['form']['sections'][0]['fields'], NULL, 'id');
    foreach ($this->fixture['rules']['rules'] as $rule) {
      $field = $fields[$rule['source']];
      $data[$rule['source']] = match ($field['type']) {
        'checkbox' => TRUE,
        'number' => 1.0,
        'date' => '2026-09-18',
        'select' => array_key_first($rule['valueMap']),
        default => str_contains($field['label'], 'Postleitzahl') ? '12345' : (str_contains($field['label'], 'E-Mail') ? 'synthetic@example.invalid' : 'SYNTHETIC'),
      };
    }
    return $data;
  }

  /**
   * Creates a reference or scalar field with live fixture values.
   */
  private function field(callable $value, ?callable $empty = NULL): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturnCallback($empty ?? static fn(): bool => FALSE);
    $field->method('__isset')->willReturn(TRUE);
    $field->method('__get')->willReturnCallback($value);
    return $field;
  }

  /**
   * Counts actual durable queue rows.
   */
  private function queued(): int {
    return $this->container->get('queue')->get(DeliveryDispatcher::QUEUE_NAME)->numberOfItems();
  }

  /**
   * Counts rows in a named test-owned table.
   */
  private function countRows(string $table): int {
    return (int) $this->container->get('database')->select($table, 't')->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the test form UUID for one tenant.
   */
  private function formUuid(int $scope): string {
    return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $scope);
  }

  /**
   * Returns a distinct synthetic TEST destination per tenant.
   */
  private function destination(int $scope): string {
    return sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $scope);
  }

  /**
   * Asserts a stable value-free failure code.
   */
  private function expectCode(string $code, callable $operation): void {
    try {
      $operation();
      self::fail('Expected ' . $code);
    }
    catch (IntegrationException $exception) {
      self::assertSame($code, $exception->errorCode);
    }
  }

}
