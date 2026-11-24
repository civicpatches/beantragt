<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\Controller\PublicApplicationApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ProfileConsentOffer;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_application\Service\ApplicantProfileSwitch;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\PublicApplicationAttachmentClaimService;
use Drupal\beantragt_application\Service\PublicApplicationIdempotencyService;
use Drupal\beantragt_application\Service\PublicApplicationSubmissionValidator;
use Drupal\beantragt_compute_api\ComputedFieldEvaluatorInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the public v1 application submission API controller.
 */
#[CoversClass(PublicApplicationApiController::class)]
#[Group('beantragt_application')]
final class PublicApplicationApiControllerTest extends UnitTestCase {

  /**
   * Original service user environment value.
   */
  private string|false $originalServiceUser;

  /**
   * Original service password environment value.
   */
  private string|false $originalServicePassword;

  /**
   * Original application data encryption key environment value.
   */
  private string|false $originalDataEncryptionKey;

  /**
   * Form storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Application storage mock.
   */
  private EntityStorageInterface $applicationStorage;

  /**
   * User storage mock.
   */
  private EntityStorageInterface $userStorage;

  /**
   * Application attachment storage mock.
   */
  private EntityStorageInterface $attachmentStorage;

  /**
   * File storage mock.
   */
  private EntityStorageInterface $fileStorage;

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Flood control mock.
   */
  private FloodInterface $flood;

  /**
   * Database connection mock.
   */
  private Connection $database;

  /**
   * Database transaction mock.
   */
  private Transaction $transaction;

  /**
   * Database transaction manager mock.
   */
  private TransactionManagerInterface $transactionManager;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Time service mock.
   */
  private TimeInterface $time;

  /**
   * Lock backend mock.
   */
  private LockBackendInterface $lock;

  /**
   * Idempotency key/value store mock.
   */
  private KeyValueStoreExpirableInterface $idempotencyStore;

  /**
   * Controller under test.
   */
  private PublicApplicationApiController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalServiceUser = getenv('NUXT_DRUPAL_SERVICE_USER');
    $this->originalServicePassword = getenv('NUXT_DRUPAL_SERVICE_PASSWORD');
    $this->originalDataEncryptionKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    new Settings(['hash_salt' => 'beantragt-public-application-test-salt']);

    $this->formStorage = $this->createMock(EntityStorageInterface::class);
    $this->applicationStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);
    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->database = $this->createMock(Connection::class);
    $this->transactionManager = $this->createMock(TransactionManagerInterface::class);
    $this->database->method('transactionManager')->willReturn($this->transactionManager);
    $this->transaction = new Transaction($this->database, 'beantragt_public_application_test', 'tx1');
    $this->database->method('startTransaction')->willReturn($this->transaction);
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1781431200);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->idempotencyStore = $this->createMock(KeyValueStoreExpirableInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
          fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
            'form_schema' => $this->formStorage,
            'application' => $this->applicationStorage,
            'user' => $this->userStorage,
            'application_attachment' => $this->attachmentStorage,
            'file' => $this->fileStorage,
            'group' => $this->groupStorage,
            default => throw new \InvalidArgumentException($entity_type_id),
          }
        );

    $this->controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $this->flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $this->lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_USER', $this->originalServiceUser);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_PASSWORD', $this->originalServicePassword);
    $this->restoreEnv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY', $this->originalDataEncryptionKey);

    parent::tearDown();
  }

  /**
   * Controller creation does not require optional compute services.
   */
  public function testCreateDoesNotRequireComputeServiceWhenComputeModuleIsOff(): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->expects($this->once())
      ->method('moduleExists')
      ->with('beantragt_compute')
      ->willReturn(FALSE);

    $keyvalue_expirable = new class($this->idempotencyStore) {

      public function __construct(private readonly KeyValueStoreExpirableInterface $store) {}

      /**
       * Returns the mocked expirable key/value store.
       */
      public function get(string $collection): KeyValueStoreExpirableInterface {
        return $this->store;
      }

    };

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('database', $this->database);
    $container->set('flood', $this->flood);
    $container->set('logger.channel.beantragt_application', $this->createMock(LoggerInterface::class));
    $container->set('datetime.time', $this->time);
    $container->set('lock', $this->lock);
    $container->set('keyvalue.expirable', $keyvalue_expirable);
    $container->set('config.factory', $this->configFactory());
    $container->set('module_handler', $module_handler);
    $container->set('beantragt_application.attachment_storage_quota', $this->createMock(AttachmentStorageQuota::class));
    $container->set('beantragt_application.public_submission_validator', new PublicApplicationSubmissionValidator());
    $container->set(
      'beantragt_application.public_idempotency',
      new PublicApplicationIdempotencyService($this->idempotencyStore, $this->lock, $this->time),
    );
    $container->set(
      'beantragt_application.public_attachment_claims',
      new PublicApplicationAttachmentClaimService($this->entityTypeManager, $this->configFactory(), $this->time, $this->lock),
    );

    $controller = PublicApplicationApiController::create($container);

    $this->assertInstanceOf(PublicApplicationApiController::class, $controller);
  }

  /**
   * Application creation stores bounded public data and returns v1 envelope.
   */
  public function testCreateApplicationPersistsPublicData(): void {
    $jurisdiction = $this->mockGroup('jur', 3, NULL, 'musterstadt');
    $jurisdiction->method('getUntranslated')->willReturnSelf();
    $jurisdiction->method('label')->willReturn('Musterstadt');
    $organisation = $this->mockGroup('org', 4, $jurisdiction, 'citizen-services');
    $organisation->method('getUntranslated')->willReturnSelf();
    $organisation->method('label')->willReturn('Bürgerbüro');
    $form = $this->mockForm($this->schemaJson(), $jurisdiction, $organisation);
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->with(['name' => 'nuxt_service'])->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())
      ->method('setSubmitStatusUrl')
      ->with($this->callback(static function (?string $url): bool {
        return is_string($url)
          && preg_match('#^https://beantragt\.example/musterstadt/citizen-services/form/fbc3b086-ac53-412e-9bbf-ccb03f286923/status/[A-Za-z0-9_-]{32,96}$#', $url) === 1;
      }))
      ->willReturnSelf();
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $application->method('getCreatedTime')->willReturn(strtotime('2026-11-21T13:24:05Z'));
    $receipt_fields = [
      'field_receipt_form_title' => $this->fieldValue('Antrag auf eine Bescheinigung'),
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $this->entityReference($organisation),
    ];
    $application->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($receipt_fields[$field]));
    $application->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $receipt_fields[$field]);
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with(
              $this->callback(
                  function (array $values): bool {
                      $data = json_decode((string) $values['field_data'], TRUE);
                      $this->assertSame('Ada Lovelace', $data['full_name']);
                      $this->assertSame('ada@example.org', $data['email']);
                      $this->assertSame('A', $data['kind']);
                      $this->assertSame('Only for A', $data['details']);
                      $this->assertArrayNotHasKey('unknown', $data);
                      $this->assertArrayNotHasKey('applicant_email', $data);
                      $this->assertSame(['target_id' => 12], $values['field_schema']);
                      $this->assertSame(['target_id' => 3], $values['field_jurisdiction']);
                      $this->assertSame(['target_id' => 4], $values['field_organisation']);
                      $this->assertSame(42, $values['uid']);
                      $workflow_attributes = json_decode((string) $values['field_workflow_attributes'], TRUE);
                      $this->assertSame(['submissionLocale' => 'en'], $workflow_attributes);
                      $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $values['field_public_status_token_hash']);
                      $this->assertFalse($values['field_email_confirmed']);
                      $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $values['field_email_confirmation_token_hash']);
                      $this->assertSame(1781431200 + (72 * 3600), $values['field_email_confirmation_expires']);
                      return TRUE;
                  }
              )
          )
      ->willReturn($application);

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->never())->method('violationForJurisdiction');
    $controller = new PublicApplicationApiController(
      $this->entityTypeManager,
      $this->database,
      $this->flood,
      $this->createMock(LoggerInterface::class),
      $this->time,
      $this->lock,
      $this->idempotencyStore,
      $this->configFactory(),
      NULL,
      $quota,
    );

    $response = $controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'locale' => 'en',
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                      'details' => 'Only for A',
                      'unknown' => 'ignored',
                      'applicant_email' => 'must-not-be-trusted@example.org',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertSame('application', $payload['meta']['resource']);
    $this->assertSame(['required' => TRUE], $payload['confirmation']);
    $this->assertSame('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f', $payload['data']['id']);
    $this->assertSame('MS-BB-2026-00001', $payload['data']['referenceNumber']);
    $this->assertSame([
      'referenceNumber' => 'MS-BB-2026-00001',
      'formTitle' => 'Antrag auf eine Bescheinigung',
      'submittedAt' => '2026-11-21T13:24:05Z',
      'jurisdictionName' => 'Musterstadt',
      'organisationName' => 'Bürgerbüro',
    ], $payload['data']['receipt']);
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,96}$/', $payload['data']['statusToken']);
    $this->assertStringStartsWith('/api/v1/status/', $payload['data']['statusUrl']);
  }

  /**
   * Trusted authenticated submissions bypass email confirmation.
   */
  public function testCreateApplicationAuthenticatedSubmissionBypassesConfirmation(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $jurisdiction = $this->mockGroup('jur', 3, NULL, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, $jurisdiction, 'citizen-services');
    $form = $this->mockForm($this->schemaJson(), $jurisdiction, $organisation);
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);

    $citizen_user = $this->createMock(AccountInterface::class);
    $citizen_user->method('id')->willReturn(99);
    $citizen_user->method('getRoles')->willReturn(['authenticated']);
    $this->userStorage->method('loadByProperties')
      ->with(['uuid' => '7d362857-0cc2-48f5-a75e-04a270de1840'])
      ->willReturn([$citizen_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('setSubmitStatusUrl')->willReturnSelf();
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00004');
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values): bool {
        $this->assertSame(99, $values['uid']);
        $this->assertTrue($values['field_email_confirmed']);
        $this->assertNull($values['field_email_confirmation_token_hash']);
        $this->assertNull($values['field_email_confirmation_expires']);
        return TRUE;
      }))
      ->willReturn($application);

    $payload = $this->validApplicationPayload();
    $payload['data']['attributes']['ownerUuid'] = '7d362857-0cc2-48f5-a75e-04a270de1840';
    $response = $this->controller->createApplication(
      $this->jsonRequest(
        $payload,
        '198.51.100.22',
        [
          'X-Beantragt-Service-Authorization' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
          'X-Beantragt-Rate-Limit-Identifier' => str_repeat('ab', 16),
        ],
      ),
    );
    $response_payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['required' => FALSE], $response_payload['confirmation']);
  }

  /**
   * Computed values are recomputed before conditional required validation.
   */
  public function testCreateApplicationRejectsForgedComputedConditionalBypass(): void {
    $form = $this->mockForm($this->schemaJsonWithComputedRequiredDependency(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);

    $computed_evaluator = $this->createMock(ComputedFieldEvaluatorInterface::class);
    $computed_evaluator->method('computedFieldIds')->willReturn(['approval_tier']);
    $computed_evaluator->expects($this->once())
      ->method('evaluate')
      ->with($this->isType('array'), $this->callback(static fn(array $data): bool => ($data['approval_tier'] ?? NULL) === 'basic'))
      ->willReturn(['approval_tier' => 'approval_required']);

    $payload = $this->validApplicationPayload();
    $payload['data']['attributes']['data']['amount'] = 9000;
    $payload['data']['attributes']['data']['approval_tier'] = 'basic';
    unset($payload['data']['attributes']['data']['details']);

    $response = $this->controllerWithComputedEvaluator($computed_evaluator)
      ->createApplication($this->jsonRequest($payload));
    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application.validation_failed', $body['error']['code']);
    $this->assertSame('approval_reason', $body['error']['details']['field']);
    $this->assertSame('required', $body['error']['details']['issue']);
  }

  /**
   * Jurisdiction-direct forms are submitted into the default organisation.
   */
  public function testCreateApplicationAssignsDefaultOrganisationForJurisdictionDirectForm(): void {
    $jurisdiction = $this->mockGroup('jur', 3, NULL, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, $jurisdiction, 'citizen-services');
    $form = $this->mockForm($this->schemaJson(), $jurisdiction, NULL);
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->with(['type' => 'org'])->willReturn([$organisation]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->with(['name' => 'nuxt_service'])->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())
      ->method('setSubmitStatusUrl')
      ->with($this->callback(static function (?string $url): bool {
        return is_string($url)
          && preg_match('#^https://beantragt\.example/musterstadt/form/fbc3b086-ac53-412e-9bbf-ccb03f286923/status/[A-Za-z0-9_-]{32,96}$#', $url) === 1;
      }))
      ->willReturnSelf();
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00002');
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values): bool {
        $this->assertSame(['target_id' => 3], $values['field_jurisdiction']);
        $this->assertSame(['target_id' => 4], $values['field_organisation']);
        return TRUE;
      }))
      ->willReturn($application);

    $response = $this->controller->createApplication($this->jsonRequest($this->validApplicationPayload()));

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Jurisdiction-direct forms do not fall back to arbitrary organisations.
   */
  public function testCreateApplicationDoesNotAssignArbitraryOrganisationWhenDefaultIsMissing(): void {
    $jurisdiction = $this->mockGroup('jur', 3);
    $other_organisation = $this->mockGroup('org', 5, $jurisdiction, 'central-administration');
    $form = $this->mockForm($this->schemaJson(), $jurisdiction, NULL);
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->with(['type' => 'org'])->willReturn([$other_organisation]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->with(['name' => 'nuxt_service'])->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00003');
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(function (array $values): bool {
        $this->assertSame(['target_id' => 3], $values['field_jurisdiction']);
        $this->assertArrayNotHasKey('field_organisation', $values);
        return TRUE;
      }))
      ->willReturn($application);

    $response = $this->controller->createApplication($this->jsonRequest($this->validApplicationPayload()));

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Idempotent retries replay the first successful application response.
   */
  public function testCreateApplicationReplaysIdempotentSubmission(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $this->applicationStorage->expects($this->once())->method('create')->willReturn($application);

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturn(TRUE);
    $flood->expects($this->exactly(2))
      ->method('register');
    $controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $this->lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );

    $records = [];
    $this->idempotencyStore->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$records): mixed {
        return $records[$key] ?? $default;
      });
    $this->idempotencyStore->method('setWithExpire')
      ->willReturnCallback(
        static function (string $key, array $record, int $expire) use (&$records): void {
          self::assertSame(900, $expire);
          self::assertArrayHasKey('payloadHash', $record);
          self::assertArrayHasKey('response', $record);
          self::assertSame(['confirmation' => ['required' => TRUE]], $record['topLevel']);
          self::assertSame(Response::HTTP_CREATED, $record['status']);
          $records[$key] = $record;
        }
      );

    $payload = $this->validApplicationPayload();
    $first_response = $controller->createApplication(
      $this->jsonRequest($payload, '198.51.100.22', ['Idempotency-Key' => str_repeat('replay-fixture-', 3)]),
    );
    $first_payload = json_decode((string) $first_response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $first_response->getStatusCode());
    $this->assertCount(1, $records);
    $record = reset($records);
    $this->assertIsArray($record);
    $this->assertIsArray($record['response']['statusToken']);
    $this->assertIsArray($record['response']['statusUrl']);
    $this->assertTrue($record['response']['statusToken']['__beantragt_encrypted_idempotency_status_token']);
    $this->assertTrue($record['response']['statusUrl']['__beantragt_encrypted_idempotency_status_token']);
    $this->assertStringNotContainsString(
      (string) $first_payload['data']['statusToken'],
      json_encode($record, JSON_THROW_ON_ERROR),
    );

    $second_response = $controller->createApplication(
      $this->jsonRequest($this->validApplicationPayload(), '198.51.100.22', ['Idempotency-Key' => str_repeat('replay-fixture-', 3)]),
    );
    $second_payload = json_decode((string) $second_response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CREATED, $second_response->getStatusCode());
    $this->assertSame($first_payload['data'], $second_payload['data']);
    $this->assertSame($first_payload['confirmation'], $second_payload['confirmation']);
    $this->assertSame(TRUE, $second_payload['meta']['idempotency']['replayed']);
    $this->assertSame('true', $second_response->headers->get('Idempotency-Replayed'));
  }

  /**
   * Reusing an idempotency key with a different payload fails closed.
   */
  public function testCreateApplicationRejectsIdempotencyKeyPayloadConflict(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $this->applicationStorage->expects($this->once())->method('create')->willReturn($application);

    $records = [];
    $this->idempotencyStore->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$records): mixed {
        return $records[$key] ?? $default;
      });
    $this->idempotencyStore->method('setWithExpire')
      ->willReturnCallback(
        static function (string $key, array $record, int $expire) use (&$records): void {
          $records[$key] = $record;
        }
      );

    $this->controller->createApplication(
      $this->jsonRequest($this->validApplicationPayload(), '198.51.100.22', ['Idempotency-Key' => str_repeat('conflict-fixture-', 3)]),
    );

    $conflicting_payload = $this->validApplicationPayload();
    $conflicting_payload['data']['attributes']['data']['kind'] = 'B';
    unset($conflicting_payload['data']['attributes']['data']['details']);
    $response = $this->controller->createApplication(
      $this->jsonRequest($conflicting_payload, '198.51.100.22', ['Idempotency-Key' => str_repeat('conflict-fixture-', 3)]),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('idempotency.conflict', $payload['error']['code']);
  }

  /**
   * Concurrent creates with the same idempotency key are rejected before save.
   */
  public function testCreateApplicationRejectsIdempotentSubmissionInProgress(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with($this->stringStartsWith('beantragt_application.idempotency.'), 30.0)
      ->willReturn(FALSE);
    $lock->expects($this->never())->method('release');
    $controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $this->flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );

    $response = $controller->createApplication(
      $this->jsonRequest($this->validApplicationPayload(), '198.51.100.22', ['Idempotency-Key' => str_repeat('storage-fixture-', 3)]),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('idempotency.in_progress', $payload['error']['code']);
  }

  /**
   * Required file fields need validated attachment claims.
   */
  public function testCreateApplicationRequiresAttachmentClaimsForRequiredFileFields(): void {
    $form = $this->mockForm($this->schemaJsonWithFileField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'evidence' => 'scan.pdf',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('application.validation_failed', $payload['error']['code']);
    $this->assertSame('evidence', $payload['error']['details']['field']);
    $this->assertSame('required', $payload['error']['details']['issue']);
  }

  /**
   * Authenticated attachment claims must belong to the submitting owner.
   */
  public function testCreateApplicationRejectsAuthenticatedAttachmentClaimFromDifferentOwner(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $schema = json_decode($this->schemaJsonWithFileField(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['auth']['mode'] = 'required';
    $form = $this->mockForm((string) json_encode($schema, JSON_THROW_ON_ERROR), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $citizen_user = $this->createMock(AccountInterface::class);
    $citizen_user->method('id')->willReturn(99);
    $citizen_user->method('getRoles')->willReturn(['authenticated']);
    $this->userStorage->method('loadByProperties')->with(['uuid' => '7d362857-0cc2-48f5-a75e-04a270de1840'])->willReturn([$citizen_user]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(123);
    $attachment->method('getOwnerId')->willReturn(100);
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('evidence'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_file' => $this->fieldTarget(321),
      'field_claim_expires' => $this->fieldValue(1781434800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', 'valid-claim-token-0123456789abcdef')),
      'field_original_filename' => $this->fieldValue('scan.pdf'),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_size' => $this->fieldValue(1234),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);
    $this->applicationStorage->expects($this->never())->method('create');

    try {
      $response = $this->controller->createApplication(
            $this->jsonRequest(
                [
                  'data' => [
                    'type' => 'application',
                    'attributes' => [
                      'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                      'consentAccepted' => TRUE,
                      'ownerUuid' => '7d362857-0cc2-48f5-a75e-04a270de1840',
                      'identity' => [
                        'fullName' => 'Ada Lovelace',
                        'email' => 'ada@example.org',
                      ],
                      'data' => [
                        'full_name' => 'Ada Lovelace',
                        'email' => 'ada@example.org',
                        'kind' => 'A',
                      ],
                      'attachments' => [
                        [
                          'fieldId' => 'evidence',
                          'attachmentId' => '1fded50b-9249-4af7-88a5-4ea2245a10bb',
                          'claimToken' => 'valid-claim-token-0123456789abcdef',
                        ],
                      ],
                    ],
                  ],
                ],
                '198.51.100.22',
                [
                  'X-Beantragt-Service-Authorization' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
                  'X-Beantragt-Rate-Limit-Identifier' => str_repeat('ab', 16),
                ],
            )
        );
    }
    finally {
      @unlink($tmp);
    }
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('attachment.claim_invalid', $payload['error']['code']);
    $this->assertSame('1fded50b-9249-4af7-88a5-4ea2245a10bb', $payload['error']['details']['attachmentId']);
  }

  /**
   * Quarantined attachment claims cannot create applications.
   */
  public function testCreateApplicationRejectsQuarantinedAttachmentClaim(): void {
    $form = $this->mockForm($this->schemaJsonWithFileField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(123);
    $attachment->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('evidence'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_file' => $this->fieldTarget(321),
      'field_claim_expires' => $this->fieldValue(1781434800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', 'valid-claim-token-0123456789abcdef')),
      'field_original_filename' => $this->fieldValue('scan.pdf'),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_size' => $this->fieldValue(1234),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      'field_scan_status' => $this->fieldValue('quarantined'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);
    $this->applicationStorage->expects($this->never())->method('create');

    try {
      $response = $this->controller->createApplication(
            $this->jsonRequest(
                [
                  'data' => [
                    'type' => 'application',
                    'attributes' => [
                      'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                      'consentAccepted' => TRUE,
                      'identity' => [
                        'fullName' => 'Ada Lovelace',
                        'email' => 'ada@example.org',
                      ],
                      'data' => [
                        'full_name' => 'Ada Lovelace',
                        'email' => 'ada@example.org',
                        'kind' => 'A',
                      ],
                      'attachments' => [
                        [
                          'fieldId' => 'evidence',
                          'attachmentId' => '1fded50b-9249-4af7-88a5-4ea2245a10bb',
                          'claimToken' => 'valid-claim-token-0123456789abcdef',
                        ],
                      ],
                    ],
                  ],
                ],
            )
        );
    }
    finally {
      @unlink($tmp);
    }
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('attachment.quarantined', $payload['error']['code']);
    $this->assertSame('1fded50b-9249-4af7-88a5-4ea2245a10bb', $payload['error']['details']['attachmentId']);
  }

  /**
   * File field size policies are enforced again during submit.
   */
  public function testCreateApplicationRejectsAttachmentOverFieldSizeLimit(): void {
    $schema = json_decode($this->schemaJsonWithFileField(), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($schema['sections'][0]['fields'] as &$field) {
      if (($field['id'] ?? NULL) === 'evidence') {
        $field['validation']['maxSizeBytes'] = 100;
      }
    }
    unset($field);
    $form = $this->mockForm((string) json_encode($schema, JSON_THROW_ON_ERROR), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->claimableAttachment(size: 1234);
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);
    $this->applicationStorage->expects($this->never())->method('create');

    try {
      $response = $this->controller->createApplication($this->jsonRequest($this->payloadWithAttachmentClaim()));
    }
    finally {
      @unlink($tmp);
    }
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.file_too_large', $payload['error']['code']);
    $this->assertSame('evidence', $payload['error']['details']['field']);
  }

  /**
   * Top-level file field count policies are enforced during submit.
   */
  public function testCreateApplicationRejectsAttachmentOverFieldMaxFiles(): void {
    $schema = json_decode($this->schemaJsonWithFileField(), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($schema['sections'][0]['fields'] as &$field) {
      if (($field['id'] ?? NULL) === 'evidence') {
        $field['maxFiles'] = 1;
      }
    }
    unset($field);
    $form = $this->mockForm((string) json_encode($schema, JSON_THROW_ON_ERROR), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachments = [
      '1fded50b-9249-4af7-88a5-4ea2245a10bb' => $this->claimableAttachment(size: 100, id: 123),
      '2fded50b-9249-4af7-88a5-4ea2245a10bb' => $this->claimableAttachment(size: 100, id: 124),
    ];
    $this->attachmentStorage->method('loadByProperties')
      ->willReturnCallback(
        static fn(array $properties): array => isset($attachments[(string) ($properties['uuid'] ?? '')])
          ? [$attachments[(string) $properties['uuid']]]
          : [],
      );
    $this->applicationStorage->expects($this->never())->method('create');

    $payload = $this->payloadWithAttachmentClaim();
    $payload['data']['attributes']['attachments'][] = [
      'fieldId' => 'evidence',
      'attachmentId' => '2fded50b-9249-4af7-88a5-4ea2245a10bb',
      'claimToken' => 'valid-claim-token-0123456789abcdef',
    ];

    try {
      $response = $this->controller->createApplication($this->jsonRequest($payload));
    }
    finally {
      @unlink($tmp);
    }
    $response_payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.too_many_for_field', $response_payload['error']['code']);
    $this->assertSame('evidence', $response_payload['error']['details']['field']);
  }

  /**
   * Aggregate application attachment quotas are enforced before save.
   */
  public function testCreateApplicationRejectsAttachmentOverAggregateLimit(): void {
    $form = $this->mockForm($this->schemaJsonWithFileField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->claimableAttachment(size: 1234);
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);
    $this->applicationStorage->expects($this->never())->method('create');

    $controller = new PublicApplicationApiController(
      $this->entityTypeManager,
      $this->database,
      $this->flood,
      $this->createMock(LoggerInterface::class),
      $this->time,
      $this->lock,
      $this->idempotencyStore,
      $this->configFactory(1000),
    );

    try {
      $response = $controller->createApplication($this->jsonRequest($this->payloadWithAttachmentClaim()));
    }
    finally {
      @unlink($tmp);
    }
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.total_size_exceeded', $payload['error']['code']);
    $this->assertSame(1000, $payload['error']['details']['limit']);
  }

  /**
   * Tenant storage quotas are enforced again during submit claim binding.
   */
  public function testCreateApplicationRejectsAttachmentOverTenantStorageQuota(): void {
    $jurisdiction = $this->mockGroup('jur', 3);
    $form = $this->mockForm($this->schemaJsonWithFileField(), $jurisdiction, $this->mockGroup('org', 4, $jurisdiction));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->claimableAttachment(size: 1234);
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);
    $this->applicationStorage->expects($this->never())->method('create');

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->once())
      ->method('violationForJurisdiction')
      ->with($jurisdiction, 0)
      ->willReturn([
        'limit' => 1000,
        'used' => 900,
        'projected' => 2134,
        'warningThresholdPercent' => 80,
      ]);

    $controller = new PublicApplicationApiController(
      $this->entityTypeManager,
      $this->database,
      $this->flood,
      $this->createMock(LoggerInterface::class),
      $this->time,
      $this->lock,
      $this->idempotencyStore,
      $this->configFactory(),
      NULL,
      $quota,
    );

    try {
      $response = $controller->createApplication($this->jsonRequest($this->payloadWithAttachmentClaim()));
    }
    finally {
      @unlink($tmp);
    }
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.tenant_storage_quota_exceeded', $payload['error']['code']);
    $this->assertSame(2134, $payload['error']['details']['projected']);
  }

  /**
   * Submitted protection marker keys are rejected before persistence.
   */
  public function testCreateApplicationRejectsSubmittedProtectionMarkerKeys(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'data' => [
                      '__beantragt_redacted' => TRUE,
                      'full_name' => 'Ada Lovelace',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('application.invalid_key', $payload['error']['code']);
  }

  /**
   * Invalid submission locales are rejected before persistence.
   */
  public function testCreateApplicationRejectsInvalidSubmissionLocale(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'locale' => 'fr',
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('locale.invalid', $payload['error']['code']);
  }

  /**
   * Submitted object values cannot impersonate protected data envelopes.
   */
  public function testCreateApplicationRejectsSubmittedObjectValues(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'data' => [
                      'full_name' => [
                        '__beantragt_encrypted' => TRUE,
                      ],
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('application.object_unsupported', $payload['error']['code']);
  }

  /**
   * Number fields reject non-numeric strings instead of casting them to zero.
   */
  public function testCreateApplicationRejectsInvalidNumberFieldFormat(): void {
    $form = $this->mockForm($this->schemaJsonWithNumberField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'amount' => 'not-a-number',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('application.validation_failed', $payload['error']['code']);
    $this->assertSame('amount', $payload['error']['details']['field']);
    $this->assertSame('number_format', $payload['error']['details']['issue']);
  }

  /**
   * Request bodies over the public v1 byte limit are rejected explicitly.
   */
  public function testCreateApplicationRejectsOversizedBody(): void {
    $response = $this->controller->createApplication(
          Request::create(
              '/api/v1/applications',
              'POST',
              [],
              [],
              [],
              [
                'REMOTE_ADDR' => '198.51.100.22',
                'CONTENT_TYPE' => 'application/json',
              ],
              '{"data":"' . str_repeat('x', 262145) . '"}',
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('request.too_large', $payload['error']['code']);
  }

  /**
   * Supplemental identity is rejected instead of silently truncated.
   */
  public function testCreateApplicationRejectsOverlongSupplementalIdentity(): void {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'] = array_values(
          array_filter(
              $schema['sections'][0]['fields'],
              static fn(array $field): bool => ($field['id'] ?? NULL) !== 'full_name',
          )
      );
    $form = $this->mockForm((string) json_encode($schema, JSON_THROW_ON_ERROR), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => str_repeat('A', 201),
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('identity.full_name_too_long', $payload['error']['code']);
  }

  /**
   * Overlong applicant emails are rejected before persistence.
   */
  public function testCreateApplicationRejectsOverlongApplicantEmail(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => str_repeat('a', 245) . '@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('identity.email_too_long', $payload['error']['code']);
  }

  /**
   * Sensitive identity-like fields are not duplicated into synthetic keys.
   */
  public function testCreateApplicationDoesNotDuplicateSensitiveIdentityFields(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('b', 32));
    $form = $this->mockForm($this->schemaJsonWithSensitiveIdentityField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $created_values = [];
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with(
              $this->callback(
                  function (array $values) use (&$created_values): bool {
                      $created_values = $values;
                      return TRUE;
                  }
              )
          )
      ->willReturn($application);

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $created_values['field_data'], TRUE);
    $this->assertSame('Ada Lovelace', $data['full_name']);
    $this->assertSame('ada@example.org', $data['email']);
    $this->assertArrayNotHasKey('applicant_full_name', $data);
    $this->assertArrayNotHasKey('applicant_email', $data);
    $this->assertArrayNotHasKey('field_applicant_email', $created_values);
  }

  /**
   * Sensitive non-file facts store applicant email only as lookup hash.
   */
  public function testCreateApplicationHashesApplicantEmailForProtectedIdentityLinkage(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('b', 32));
    $form = $this->mockForm($this->schemaJsonWithSensitiveCaseFact(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $created_values = [];
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with(
              $this->callback(
                  function (array $values) use (&$created_values): bool {
                      $created_values = $values;
                      return TRUE;
                  }
              )
          )
      ->willReturn($application);

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ADA@Example.Org',
                      'kind' => 'A',
                      'diagnosis' => 'IfSG-Meldung',
                    ],
                  ],
                ],
              ]
          )
      );

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $created_values['field_data'], TRUE);
    $this->assertSame('Ada Lovelace', $data['full_name']);
    $this->assertSame('ADA@Example.Org', $data['email']);
    $this->assertSame('IfSG-Meldung', $data['diagnosis']);
    $this->assertArrayNotHasKey('field_applicant_email', $created_values);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $created_values['field_applicant_email_hash']);
  }

  /**
   * Sensitive email schema fields are not used as applicant owner email.
   */
  public function testCreateApplicationDoesNotTrustSensitiveEmailFieldAsOwner(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('b', 32));
    $form = $this->mockForm($this->schemaJsonWithSensitiveStringEmailField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $this->applicationStorage->expects($this->once())
      ->method('create')
      ->with(
              $this->callback(
                  function (array $values): bool {
                      $data = json_decode((string) $values['field_data'], TRUE);
                      $this->assertSame('Ada Lovelace', $data['full_name']);
                      $this->assertSame('third-party@example.org', $data['email']);
                      $this->assertArrayNotHasKey('applicant_email', $data);
                      $this->assertArrayNotHasKey('field_applicant_email', $values);
                      $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $values['field_applicant_email_hash']);
                      return TRUE;
                  }
              )
          )
      ->willReturn($application);

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'third-party@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
  }

  /**
   * PCRE pattern runtime failures do not pass validation.
   */
  public function testCreateApplicationRejectsInvalidValidationPattern(): void {
    $form = $this->mockForm($this->schemaJsonWithInvalidPatternField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                      'code' => 'anything',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('application.validation_failed', $payload['error']['code']);
    $this->assertSame('code', $payload['error']['details']['field']);
    $this->assertSame('pattern', $payload['error']['details']['issue']);
  }

  /**
   * Select and radio fields without options fail closed.
   */
  public function testCreateApplicationRejectsSelectFieldWithoutOptions(): void {
    $form = $this->mockForm($this->schemaJsonWithSelectWithoutOptions(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('application.validation_failed', $payload['error']['code']);
    $this->assertSame('kind', $payload['error']['details']['field']);
    $this->assertSame('options_missing', $payload['error']['details']['issue']);
  }

  /**
   * Array values are rejected before they can be persisted unbounded.
   */
  public function testCreateApplicationRejectsOversizedArrayValues(): void {
    $form = $this->mockForm($this->schemaJsonWithCheckboxField(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'topics' => array_fill(0, 101, 'A'),
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('application.array_too_large', $payload['error']['code']);
    $this->assertSame('topics', $payload['error']['details']['field']);
  }

  /**
   * Public submissions fail closed when the service owner is not configured.
   */
  public function testCreateApplicationRequiresConfiguredServiceAccountOwner(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=');
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->applicationStorage->expects($this->never())->method('create');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('application.owner_unavailable', $payload['error']['code']);
  }

  /**
   * A missing generated reference number rolls back the saved application.
   */
  public function testCreateApplicationRollsBackWhenReferenceNumberMissing(): void {
    $form = $this->mockForm($this->schemaJson(), $this->mockGroup('jur', 3), $this->mockGroup('org', 4));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->once())->method('save');
    $application->method('getReferenceNumber')->willReturn('');
    $this->applicationStorage->method('create')->willReturn($application);
    $this->transactionManager->expects($this->once())
      ->method('rollback')
      ->with('beantragt_public_application_test', 'tx1');

    $response = $this->controller->createApplication(
          $this->jsonRequest(
              [
                'data' => [
                  'type' => 'application',
                  'attributes' => [
                    'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
                    'consentAccepted' => TRUE,
                    'identity' => [
                      'fullName' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                    ],
                    'data' => [
                      'full_name' => 'Ada Lovelace',
                      'email' => 'ada@example.org',
                      'kind' => 'A',
                    ],
                  ],
                ],
              ]
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('application.save_failed', $payload['error']['code']);
  }

  /**
   * Public submission rate limits use the stable v1 envelope.
   */
  public function testCreateApplicationRateLimitUsesV1Envelope(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.public_create', 10, 60, '198.51.100.22')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');
    $controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $this->lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );

    $response = $controller->createApplication($this->jsonRequest([], '198.51.100.22'));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Signed Nuxt proxy submissions can carry a stable public flood identifier.
   */
  public function testCreateApplicationRateLimitAcceptsSignedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $proxy_identifier = str_repeat('ab', 16);
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.public_create', 10, 60, 'proxy:' . $proxy_identifier)
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');
    $controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $this->lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );

    $response = $controller->createApplication(
          $this->jsonRequest(
              [],
              '198.51.100.22',
              [
                'X-Beantragt-Service-Authorization' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
                'X-Beantragt-Rate-Limit-Identifier' => $proxy_identifier,
              ],
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
  }

  /**
   * Unsigned proxy flood headers are ignored.
   */
  public function testCreateApplicationRateLimitRejectsSpoofedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.public_create', 10, 60, '198.51.100.22')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');
    $controller = new PublicApplicationApiController(
          $this->entityTypeManager,
          $this->database,
          $flood,
          $this->createMock(LoggerInterface::class),
          $this->time,
          $this->lock,
          $this->idempotencyStore,
          $this->configFactory(),
      );

    $response = $controller->createApplication(
          $this->jsonRequest(
              [],
              '198.51.100.22',
              [
                'X-Beantragt-Service-Authorization' => 'Basic ' . base64_encode('nuxt_service:wrong-secret'),
                'X-Beantragt-Rate-Limit-Identifier' => str_repeat('ab', 16),
              ],
          )
      );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
  }

  /**
   * Effective profile consent stores a provable record of the shown text.
   */
  public function testCreateApplicationStoresProfileConsentRecordWhenSwitchIsOn(): void {
    $result = $this->submitWithConsents(['profileConsent' => TRUE], TRUE);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $values = $result['values'];
    $this->assertTrue($values['field_profile_consent']);
    $this->assertFalse($values['field_bank_consent']);
    $this->assertArrayNotHasKey('field_bank_consent_record', $values);
    $record = json_decode((string) $values['field_profile_consent_record'], TRUE);
    $this->assertSame(1781431200, $record['time']);
    $this->assertSame('en', $record['locale']);
    $this->assertSame(['person.full_name', 'person.email'], $record['fields']);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_CONTACT), $record['textVersion']);
    $this->assertSame(ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'en', 'Bürgerbüro', $record['textVersion'], $record['fields']), $record['text']);
    $this->assertStringContainsString('Bürgerbüro', $record['text']);
    $this->assertSame(ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'en', 'Bürgerbüro', $record['textVersion'], $record['fields']), $record['textHash']);
    $this->assertSame([
      'type' => 'org',
      'id' => 4,
      'uuid' => '6a3c2c8e-0000-4000-8000-000000000004',
      'label' => 'Bürgerbüro',
    ], $record['scope']);
    $this->assertSame('public_submit', $record['source']);
  }

  /**
   * The delivery route follows the form offer and never causes a 400.
   *
   * @param array<string, mixed> $attributes
   *   Submission attributes.
   * @param string|null $offer
   *   Computed field_delivery_offer, NULL for a form without the field.
   * @param array{route: string, source: string, offered: string[], reason: ?string} $expected
   *   The stored route and route_chosen event.
   */
  #[DataProvider('deliveryRouteSubmissions')]
  public function testCreateApplicationStoresDeliveryRouteChoice(array $attributes, ?string $offer, array $expected): void {
    $result = $this->submitWithConsents($attributes, TRUE, delivery_offer: $offer);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertSame($expected['route'], $result['values']['field_delivery_route']);
    $this->assertSame([[
      'event' => 'route_chosen',
      'at' => 1781431200,
      'route' => $expected['route'],
      'source' => $expected['source'],
      'offered' => $expected['offered'],
      'reason' => $expected['reason'],
    ],
    ], json_decode((string) $result['values']['field_delivery_record'], TRUE));
    $this->assertStringNotContainsString('delivery', $result['body']);
  }

  /**
   * Attributes, offer and expected choice.
   *
   * @return iterable<string, array{array<string, mixed>, ?string, array<string, mixed>}>
   *   Cases.
   */
  public static function deliveryRouteSubmissions(): iterable {
    $offer = '{"configured":true,"routes":["portal","postal"],"default":"portal"}';
    $offered = ['portal', 'postal'];
    $default = static fn (array $routes, string $reason): array => [
      'route' => 'portal',
      'source' => 'form_default',
      'offered' => $routes,
      'reason' => $reason,
    ];
    yield 'offered' => [
      ['deliveryRoute' => 'postal'],
      $offer,
      ['route' => 'postal', 'source' => 'public_submit', 'offered' => $offered, 'reason' => NULL],
    ];
    yield 'outside the offer' => [['deliveryRoute' => 'bundid'], $offer, $default($offered, 'not_offered')];
    yield 'absent' => [[], $offer, $default($offered, 'not_requested')];
    yield 'malformed number' => [['deliveryRoute' => 42], $offer, $default($offered, 'not_offered')];
    yield 'malformed object' => [['deliveryRoute' => ['postal']], $offer, $default($offered, 'not_offered')];
    yield 'unconfigured form' => [['deliveryRoute' => 'postal'], '{"configured":false}', $default([], 'not_configured')];
    yield 'malformed offer' => [['deliveryRoute' => 'postal'], '{"configured":true}', $default([], 'not_configured')];
    yield 'form without the field' => [['deliveryRoute' => 'postal'], NULL, $default([], 'not_configured')];
  }

  /**
   * Consent sent to a jurisdiction with the switch off is not stored.
   */
  public function testCreateApplicationIgnoresProfileConsentWhenSwitchIsOff(): void {
    $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], FALSE, $this->schemaJsonWithIbanField());

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertFalse($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
    $this->assertArrayNotHasKey('field_profile_consent_record', $result['values']);
  }

  /**
   * Absent or non-boolean consent attributes never fail the submission.
   */
  public function testCreateApplicationTreatsAbsentOrMalformedConsentAsNoConsent(): void {
    foreach ([[], ['profileConsent' => 'yes', 'bankConsent' => 1], ['profileConsent' => NULL]] as $attributes) {
      $result = $this->submitWithConsents($attributes, TRUE, $this->schemaJsonWithIbanField());

      $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
      $this->assertFalse($result['values']['field_profile_consent']);
      $this->assertFalse($result['values']['field_bank_consent']);
    }
  }

  /**
   * Without the bank switch only the contact consent counts.
   */
  public function testCreateApplicationIgnoresBankConsentWhenBankSwitchIsOff(): void {
    $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField(), FALSE, 'Bürgerbüro', 'Stadt Musterstadt', TRUE, FALSE);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertTrue($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
    $this->assertArrayNotHasKey('field_bank_consent_record', $result['values']);
  }

  /**
   * Bank consent needs an IBAN field on the form and the contact consent.
   */
  public function testCreateApplicationStoresBankConsentOnlyWithIbanFieldAndContactConsent(): void {
    $with_iban = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField());
    $this->assertSame(Response::HTTP_CREATED, $with_iban['status'], $with_iban['body']);
    $this->assertTrue($with_iban['values']['field_bank_consent']);
    $record = json_decode((string) $with_iban['values']['field_bank_consent_record'], TRUE);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_BANK), $record['textVersion']);
    $this->assertSame(ProfileConsentText::hash(ProfileConsentText::KIND_BANK, 'en', 'Bürgerbüro', $record['textVersion'], $record['fields']), $record['textHash']);

    $without_iban = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE);
    $this->assertTrue($without_iban['values']['field_profile_consent']);
    $this->assertFalse($without_iban['values']['field_bank_consent']);

    $sensitive_iban = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField(TRUE));
    $this->assertFalse($sensitive_iban['values']['field_bank_consent']);

    $bank_only = $this->submitWithConsents(['bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField());
    $this->assertFalse($bank_only['values']['field_profile_consent']);
    $this->assertFalse($bank_only['values']['field_bank_consent']);
  }

  /**
   * Jurisdiction-direct forms name the jurisdiction, not the default sink.
   *
   * The citizen sees the jurisdiction on /{jur}/form/{id}; the default
   * organisation that receives the application is not shown, so the stored
   * text and hash must name the jurisdiction.
   */
  public function testCreateApplicationNamesJurisdictionForJurisdictionDirectForm(): void {
    $result = $this->submitWithConsents(['profileConsent' => TRUE], TRUE, NULL, TRUE);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertTrue($result['values']['field_profile_consent']);
    $record = json_decode((string) $result['values']['field_profile_consent_record'], TRUE);
    $this->assertSame([
      'type' => 'jur',
      'id' => 3,
      'uuid' => '6a3c2c8e-0000-4000-8000-000000000003',
      'label' => 'Stadt Musterstadt',
    ], $record['scope']);
    $this->assertSame(ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'en', 'Stadt Musterstadt', $record['textVersion'], $record['fields']), $record['text']);
    $this->assertSame(ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'en', 'Stadt Musterstadt', $record['textVersion'], $record['fields']), $record['textHash']);
    $this->assertStringNotContainsString('Bürgerbüro', $record['text']);
  }

  /**
   * The computed form offer and the application record match byte for byte.
   */
  public function testOfferedConsentStelleRendersStoredConsentText(): void {
    $cases = [
      'organisation form' => [FALSE, 'Bürgerbüro', 'Stadt Musterstadt', 'Bürgerbüro'],
      'jurisdiction form' => [TRUE, 'Bürgerbüro', 'Stadt Musterstadt', 'Stadt Musterstadt'],
      'organisation label with whitespace' => [FALSE, " Bürger\t büro\u{00A0}", 'Stadt Musterstadt', 'Bürger büro'],
      'jurisdiction label with whitespace' => [TRUE, 'Bürgerbüro', "Stadt  Musterstadt\u{00A0}", 'Stadt Musterstadt'],
    ];
    foreach ($cases as $case => [$jurisdiction_direct, $organisation_label, $jurisdiction_label, $expected_stelle]) {
      $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField(), $jurisdiction_direct, $organisation_label, $jurisdiction_label);
      $this->assertSame(Response::HTTP_CREATED, $result['status'], $case . ': ' . $result['body']);
      $offer = ProfileConsentScope::offerForForm($result['form']);
      $this->assertTrue($offer['profileSyncEligible'], $case);
      $this->assertTrue($offer['bankConsentEligible'], $case);
      $this->assertSame($expected_stelle, $offer['consentStelle'], $case);

      $projection = json_decode((string) ProfileConsentOffer::projection($result['form'], new ApplicantProfileSwitch()), TRUE, 512, JSON_THROW_ON_ERROR);
      foreach (['contact' => 'field_profile_consent_record', 'bank' => 'field_bank_consent_record'] as $kind => $record_field) {
        $record = json_decode((string) $result['values'][$record_field], TRUE, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expected_stelle, $record['scope']['label'], $case);
        $public = $projection['en'][$kind];
        $this->assertSame($public, array_intersect_key($record, $public), $case);
        $this->assertSame(ProfileConsentText::hash($kind, 'en', $expected_stelle, $record['textVersion'], $record['fields']), $record['textHash'], $case);
      }
    }
  }

  /**
   * A second form offers a different contact list and separate bank fields.
   */
  public function testConsentFieldsMatchTheCurrentForm(): void {
    $schema = json_decode($this->schemaJsonWithIbanField(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'phone',
      'type' => 'tel',
      'label' => 'Telefon',
      'crm_key' => 'person.phone',
    ];
    $schema['sections'][0]['fields'][] = [
      'id' => 'dependent_name',
      'type' => 'text',
      'label' => 'Name einer weiteren Person',
      'crm_key' => 'dependent.full_name',
    ];
    $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, json_encode($schema, JSON_THROW_ON_ERROR));
    $contact = json_decode((string) $result['values']['field_profile_consent_record'], TRUE, 512, JSON_THROW_ON_ERROR);
    $bank = json_decode((string) $result['values']['field_bank_consent_record'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['person.full_name', 'person.email', 'person.phone'], $contact['fields']);
    $this->assertStringContainsString('full name, email address, phone number.', $contact['text']);
    $this->assertStringNotContainsString('IBAN', $contact['text']);
    $this->assertSame(['person.iban'], $bank['fields']);
    $this->assertStringNotContainsString('account holder', $bank['text']);

    $stale = $this->submitWithConsents(['profileConsent' => TRUE, 'profileConsentOfferId' => $contact['id']], TRUE);
    $this->assertSame(Response::HTTP_CREATED, $stale['status']);
    $this->assertFalse($stale['values']['field_profile_consent']);
  }

  /**
   * A consent without the echo of the shown text is not stored.
   */
  public function testCreateApplicationIgnoresProfileConsentWithoutEcho(): void {
    $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField(), FALSE, 'Bürgerbüro', 'Stadt Musterstadt', FALSE);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertFalse($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
    $this->assertArrayNotHasKey('field_profile_consent_record', $result['values']);
  }

  /**
   * Stale, malformed or cross-locale offers leave both consents unset.
   */
  public function testCreateApplicationIgnoresProfileConsentWithStaleOffer(): void {
    $stale = [
      'unknown offer' => ['profileConsentOfferId' => str_repeat('a', 64)],
      'offer is not a string' => ['profileConsentOfferId' => ['invalid']],
      'different locale' => ['locale' => 'de'],
    ];
    foreach ($stale as $case => $override) {
      $result = $this->submitWithConsents($override + ['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, $this->schemaJsonWithIbanField());

      $this->assertSame(Response::HTTP_CREATED, $result['status'], $case . ': ' . $result['body']);
      $this->assertFalse($result['values']['field_profile_consent'], $case);
      $this->assertFalse($result['values']['field_bank_consent'], $case);
      $this->assertArrayNotHasKey('field_profile_consent_record', $result['values'], $case);
    }
  }

  /**
   * Legacy echoes do not prove a form-specific offer.
   */
  public function testCreateApplicationAcceptsLegacySubmissionWithoutSavingConsent(): void {
    $result = $this->submitWithConsents([
      'profileConsent' => TRUE,
      'bankConsent' => TRUE,
      'consentTextVersion' => '2026-10-20.3',
      'bankConsentTextVersion' => '2026-10-20.2',
      'consentStelle' => 'Bürgerbüro',
    ], TRUE, $this->schemaJsonWithIbanField(), echo: FALSE);

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertFalse($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
  }

  /**
   * A stale bank offer drops only the bank consent.
   */
  public function testCreateApplicationKeepsContactConsentWhenOnlyBankOfferIsStale(): void {
    $result = $this->submitWithConsents([
      'profileConsent' => TRUE,
      'bankConsent' => TRUE,
      'bankConsentOfferId' => str_repeat('a', 64),
    ], TRUE, $this->schemaJsonWithIbanField());

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertTrue($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
    $this->assertArrayNotHasKey('field_bank_consent_record', $result['values']);
  }

  /**
   * Forms with protected identity linkage never store a profile consent.
   */
  public function testCreateApplicationIgnoresProfileConsentOnLinkageProtectedForm(): void {
    $schema = json_decode($this->schemaJsonWithIbanField(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'health_note',
      'type' => 'text',
      'label' => 'Health note',
      'sensitive' => TRUE,
    ];
    $result = $this->submitWithConsents(['profileConsent' => TRUE, 'bankConsent' => TRUE], TRUE, (string) json_encode($schema, JSON_THROW_ON_ERROR));

    $this->assertSame(Response::HTTP_CREATED, $result['status'], $result['body']);
    $this->assertFalse($result['values']['field_profile_consent']);
    $this->assertFalse($result['values']['field_bank_consent']);
    $this->assertArrayNotHasKey('field_profile_consent_record', $result['values']);
    $this->assertArrayNotHasKey('field_bank_consent_record', $result['values']);
  }

  /**
   * Submits an application against a jurisdiction with a given switch state.
   *
   * @return array{status: int, body: string, values: array<string, mixed>, form: \Drupal\beantragt_schema\Entity\FormSchemaInterface}
   *   Response status, body, the captured entity create values and the form.
   */
  private function submitWithConsents(array $consent_attributes, bool $switch_on, ?string $schema = NULL, bool $jurisdiction_direct = FALSE, string $organisation_label = 'Bürgerbüro', string $jurisdiction_label = 'Stadt Musterstadt', bool $echo = TRUE, bool $bank_switch_on = TRUE, ?string $delivery_offer = NULL): array {
    $config = (string) json_encode([
      'applicant_profile' => [
        'enabled' => $switch_on,
        'scope' => 'organisation',
        'bankConsent' => $bank_switch_on,
      ],
    ]);
    $jurisdiction = $this->mockLabelledGroup('jur', 3, NULL, 'musterstadt', $jurisdiction_label, $config);
    $organisation = $this->mockLabelledGroup('org', 4, $jurisdiction, 'citizen-services', $organisation_label);
    $form = $this->mockForm($schema ?? $this->schemaJson(), $jurisdiction, $jurisdiction_direct ? NULL : $organisation, $delivery_offer);
    $form_storage = $this->createMock(EntityStorageInterface::class);
    $form_storage->method('loadByProperties')->willReturn([$form]);
    // The organisation doubles as the default organisation sink.
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadByProperties')->willReturn([$organisation]);
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $service_user = $this->createMock(AccountInterface::class);
    $service_user->method('id')->willReturn(42);
    $service_user->method('getRoles')->willReturn(['authenticated', 'service_account']);
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('loadByProperties')->willReturn([$service_user]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'form_schema' => $form_storage,
        'application' => $application_storage,
        'user' => $user_storage,
        'group' => $group_storage,
        default => $this->createMock(EntityStorageInterface::class),
      }
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('setSubmitStatusUrl')->willReturnSelf();
    $application->method('uuid')->willReturn('d01e79f8-11a8-4a01-8b4d-67f0f1f5e81f');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $captured = [];
    $application_storage->method('create')->willReturnCallback(
      static function (array $values) use (&$captured, $application): ApplicationInterface {
        $captured = $values;
        return $application;
      }
    );

    $controller = new PublicApplicationApiController(
      $entity_type_manager,
      $this->database,
      $this->flood,
      $this->createMock(LoggerInterface::class),
      $this->time,
      $this->lock,
      $this->idempotencyStore,
      $this->configFactory(),
    );
    $data = [
      'full_name' => 'Ada Lovelace',
      'email' => 'ada@example.org',
      'kind' => 'B',
    ];
    if ($schema !== NULL && str_contains($schema, 'person.iban')) {
      $data['iban'] = 'DE89370400440532013000';
    }
    if ($echo) {
      $offers = json_decode((string) ProfileConsentOffer::projection($form, new ApplicantProfileSwitch()), TRUE, 512, JSON_THROW_ON_ERROR);
      $consent_attributes += [
        'profileConsentOfferId' => $offers['en']['contact']['id'] ?? NULL,
        'bankConsentOfferId' => $offers['en']['bank']['id'] ?? NULL,
      ];
    }
    $response = $controller->createApplication($this->jsonRequest([
      'data' => [
        'type' => 'application',
        'attributes' => $consent_attributes + [
          'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
          'consentAccepted' => TRUE,
          'locale' => 'en',
          'data' => $data,
        ],
      ],
    ]));

    return [
      'status' => $response->getStatusCode(),
      'body' => (string) $response->getContent(),
      'values' => $captured,
      'form' => $form,
    ];
  }

  /**
   * Builds a group mock with label, UUID and optional jurisdiction config.
   */
  private function mockLabelledGroup(string $bundle, int $id, ?GroupInterface $jurisdiction, string $slug, string $label, ?string $config = NULL): GroupInterface {
    $fields = [
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->entityReference($jurisdiction) : $this->emptyField(),
      'field_slug' => $this->fieldValue($slug),
    ];
    if ($config !== NULL) {
      $fields['field_jur_config'] = $this->fieldValue($config);
    }
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn(sprintf('6a3c2c8e-0000-4000-8000-%012d', $id));
    $group->method('label')->willReturn($label);
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasTranslation')->willReturn(FALSE);
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $group;
  }

  /**
   * Builds the default schema fixture plus an IBAN field.
   */
  private function schemaJsonWithIbanField(bool $sensitive = FALSE): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'iban',
      'type' => 'text',
      'label' => 'IBAN',
      'crm_key' => 'person.iban',
      'sensitive' => $sensitive,
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds a JSON request.
   */
  private function jsonRequest(array $payload, string $ip = '198.51.100.22', array $headers = []): Request {
    $request = Request::create(
          '/api/v1/applications',
          'POST',
          [],
          [],
          [],
          [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
          ],
          (string) json_encode($payload, JSON_THROW_ON_ERROR),
      );
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $request;
  }

  /**
   * Builds a form schema mock.
   */
  private function mockForm(string $schema, GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL, ?string $delivery_offer = NULL): FormSchemaInterface {
    $fields = [
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $organisation instanceof GroupInterface ? $this->entityReference($organisation) : $this->emptyField(),
      'field_group' => $this->emptyField(),
    ];
    if ($delivery_offer !== NULL) {
      // The computed offer of beantragt_docgen; absent when docgen is off.
      $fields['field_delivery_offer'] = $this->fieldValue($delivery_offer);
    }
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn(12);
    $form->method('uuid')->willReturn('fbc3b086-ac53-412e-9bbf-ccb03f286923');
    $form->method('isPublished')->willReturn(TRUE);
    $form->method('getSchemaStatus')->willReturn('published');
    $form->method('getPublicationScope')->willReturn('internet');
    $form->method('getSchema')->willReturn($schema);
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $form;
  }

  /**
   * Builds the controller with a computed field evaluator override.
   */
  private function controllerWithComputedEvaluator(ComputedFieldEvaluatorInterface $computed_evaluator): PublicApplicationApiController {
    return new PublicApplicationApiController(
      $this->entityTypeManager,
      $this->database,
      $this->flood,
      $this->createMock(LoggerInterface::class),
      $this->time,
      $this->lock,
      $this->idempotencyStore,
      $this->configFactory(),
      $computed_evaluator,
    );
  }

  /**
   * Builds config factory mock with group defaults.
   */
  private function configFactory(int $attachment_max_total_bytes = 52428800): ConfigFactoryInterface {
    $group_config = $this->createMock(ImmutableConfig::class);
    $group_config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'default_organisation_slug' => 'citizen-services',
        'frontend_base_url' => 'https://beantragt.example/',
        default => NULL,
      }
    );

    $application_config = $this->createMock(ImmutableConfig::class);
    $application_config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'attachment_max_total_bytes_per_application' => $attachment_max_total_bytes,
        default => NULL,
      }
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturnCallback(
        static fn(string $name): ImmutableConfig => match ($name) {
          'beantragt_group.settings' => $group_config,
          'beantragt_application.settings' => $application_config,
          default => $application_config,
        }
      );
    return $factory;
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(string $bundle, int $id, ?GroupInterface $jurisdiction = NULL, string $slug = ''): GroupInterface {
    $fields = [
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->entityReference($jurisdiction) : $this->emptyField(),
      'field_slug' => $slug !== '' ? $this->fieldValue($slug) : $this->emptyField(),
    ];
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('id')->willReturn($id);
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $group;
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
   * Builds a field list exposing a target_id property.
   */
  private function fieldTarget(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
          static fn(string $property): bool => $property === 'target_id',
      );
    $field->method('__get')->willReturnCallback(
          static fn(string $property): mixed => $property === 'target_id' ? $target_id : NULL,
      );
    return $field;
  }

  /**
   * Builds a field list exposing a scalar value property.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
          static fn(string $property): bool => $property === 'value',
      );
    $field->method('__get')->willReturnCallback(
          static fn(string $property): mixed => $property === 'value' ? $value : NULL,
      );
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
   * Builds a valid public application request payload.
   *
   * @return array<string, mixed>
   *   Public v1 application payload.
   */
  private function validApplicationPayload(): array {
    return [
      'data' => [
        'type' => 'application',
        'attributes' => [
          'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
          'consentAccepted' => TRUE,
          'identity' => [
            'fullName' => 'Ada Lovelace',
            'email' => 'ada@example.org',
          ],
          'locale' => 'en',
          'data' => [
            'full_name' => 'Ada Lovelace',
            'email' => 'ada@example.org',
            'kind' => 'A',
            'details' => 'Only for A',
          ],
        ],
      ],
    ];
  }

  /**
   * Builds schema fixture JSON.
   */
  private function schemaJson(): string {
    return (string) json_encode(
          [
            'title' => 'Smoke Antrag',
            'auth' => [
              'mode' => 'optional',
              'collect' => [
                'fullName' => 'required',
                'email' => 'required',
              ],
            ],
            'sections' => [
            [
              'id' => 'person',
              'title' => 'Person',
              'fields' => [
            [
              'id' => 'full_name',
              'type' => 'text',
              'label' => 'Name',
              'required' => TRUE,
              'crm_key' => 'person.full_name',
            ],
            [
              'id' => 'email',
              'type' => 'email',
              'label' => 'E-Mail',
              'required' => TRUE,
              'crm_key' => 'person.email',
            ],
            [
              'id' => 'kind',
              'type' => 'select',
              'label' => 'Art',
              'options' => ['A', 'B'],
            ],
            [
              'id' => 'details',
              'type' => 'textarea',
              'label' => 'Details',
              'conditionalOn' => [
                'fieldId' => 'kind',
                'value' => 'A',
              ],
            ],
              ],
            ],
            ],
          ], JSON_THROW_ON_ERROR
      );
  }

  /**
   * Builds schema fixture JSON with a file field.
   */
  private function schemaJsonWithFileField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'evidence',
      'type' => 'file',
      'label' => 'Nachweis',
      'required' => TRUE,
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds a valid v1 application payload with one attachment claim.
   *
   * @return array<string, mixed>
   *   Request payload.
   */
  private function payloadWithAttachmentClaim(): array {
    return [
      'data' => [
        'type' => 'application',
        'attributes' => [
          'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
          'consentAccepted' => TRUE,
          'identity' => [
            'fullName' => 'Ada Lovelace',
            'email' => 'ada@example.org',
          ],
          'data' => [
            'full_name' => 'Ada Lovelace',
            'email' => 'ada@example.org',
            'kind' => 'A',
          ],
          'attachments' => [
            [
              'fieldId' => 'evidence',
              'attachmentId' => '1fded50b-9249-4af7-88a5-4ea2245a10bb',
              'claimToken' => 'valid-claim-token-0123456789abcdef',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds a valid claimable application attachment mock.
   */
  private function claimableAttachment(int $size, int $id = 123): ApplicationAttachmentInterface {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn($id);
    $attachment->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('evidence'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_file' => $this->fieldTarget(321),
      'field_claim_expires' => $this->fieldValue(1781434800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', 'valid-claim-token-0123456789abcdef')),
      'field_original_filename' => $this->fieldValue('scan.pdf'),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_size' => $this->fieldValue($size),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    return $attachment;
  }

  /**
   * Builds schema fixture JSON with a number field.
   */
  private function schemaJsonWithNumberField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'amount',
      'type' => 'number',
      'label' => 'Betrag',
      'validation' => [
        'min' => 1,
      ],
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with a computed dependency.
   */
  private function schemaJsonWithComputedRequiredDependency(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'amount',
      'type' => 'number',
      'label' => 'Betrag',
      'required' => TRUE,
    ];
    $schema['sections'][0]['fields'][] = [
      'id' => 'approval_tier',
      'type' => 'text',
      'label' => 'Freigabestufe',
      'computed' => [
        'fn' => 'approvalTier',
        'args' => ['amount'],
      ],
    ];
    $schema['sections'][0]['fields'][] = [
      'id' => 'approval_reason',
      'type' => 'textarea',
      'label' => 'Freigabegrund',
      'required' => TRUE,
      'conditionalOn' => [
        'fieldId' => 'approval_tier',
        'value' => 'approval_required',
      ],
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with an option field missing options.
   */
  private function schemaJsonWithSelectWithoutOptions(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    unset($schema['sections'][0]['fields'][2]['options']);
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with a checkbox option field.
   */
  private function schemaJsonWithCheckboxField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'topics',
      'type' => 'checkbox',
      'label' => 'Topics',
      'options' => ['A', 'B'],
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with a sensitive identity-like field.
   */
  private function schemaJsonWithSensitiveIdentityField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['auth'] = [
      'mode' => 'anonymous',
      'collect' => [
        'email' => 'none',
      ],
    ];
    unset($schema['sections'][0]['fields'][0]['crm_key']);
    $schema['sections'][0]['fields'][0]['sensitive'] = TRUE;
    unset($schema['sections'][0]['fields'][1]['crm_key']);
    $schema['sections'][0]['fields'][1]['sensitive'] = TRUE;
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with a string-sensitive email field.
   */
  private function schemaJsonWithSensitiveStringEmailField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['auth'] = [
      'mode' => 'anonymous',
      'collect' => [
        'email' => 'none',
      ],
    ];
    unset($schema['sections'][0]['fields'][1]['crm_key']);
    $schema['sections'][0]['fields'][1]['sensitive'] = 'true';
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with a sensitive non-file case fact.
   */
  private function schemaJsonWithSensitiveCaseFact(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'diagnosis',
      'type' => 'text',
      'label' => 'Diagnose',
      'sensitive' => TRUE,
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with an invalid regex pattern.
   */
  private function schemaJsonWithInvalidPatternField(): string {
    $schema = json_decode($this->schemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][] = [
      'id' => 'code',
      'type' => 'text',
      'label' => 'Code',
      'validation' => [
        'pattern' => '*',
      ],
    ];
    return (string) json_encode($schema, JSON_THROW_ON_ERROR);
  }

  /**
   * Restores an environment variable.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }
    putenv($name . '=' . $value);
  }

}
