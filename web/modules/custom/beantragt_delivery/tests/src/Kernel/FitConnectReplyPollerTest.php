<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Service\ProtectedResponseAccessException;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use PHPUnit\Framework\Attributes\Group;

use Drupal\beantragt_delivery\Service\FitConnectCaseRepository;
use Drupal\beantragt_delivery\Service\FitConnectReplyPoller;
use Drupal\beantragt_delivery\Service\FitConnectReplyApiClient;
use Drupal\beantragt_delivery\Service\FitConnectReplyVerifier;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\Component\Uuid\Php;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Log\NullLogger;

/**
 * Exercises the complete reply pipeline with real cryptography and local SQL.
 */
#[Group('beantragt_delivery')]
final class FitConnectReplyPollerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file'];
  private const REPLY = '11111111-1111-4111-8111-111111111111';
  private const CASE_ID = '22222222-2222-4222-8222-222222222222';
  private const ATTACHMENT = '33333333-3333-4333-8333-333333333333';
  private const EVENT = 'https://schema.fitko.de/fit-connect/events/';
  /**
   * Repository test fixture.
   */
  private ExternalResponseRepository $repository;
  /**
   * Cases test fixture.
   */
  private FitConnectCaseRepository $cases;
  /**
   * Application test fixture.
   */
  private ApplicationInterface $application;
  /**
   * Factory test fixture.
   */
  private ConfigFactoryInterface $factory;
  /**
   * Manager test fixture.
   */
  private EntityTypeManagerInterface $manager;
  /**
   * Mutation lock test fixture.
   */
  private ApplicationDependentMutationLock $mutationLock;
  /**
   * Lock test fixture.
   */
  private LockBackendInterface $lock;
  /**
   * Accept calls test fixture.
   */
  private int $acceptCalls = 0;
  /**
   * Attachment calls test fixture.
   */
  private int $attachmentCalls = 0;
  /**
   * Original key test fixture.
   */
  private string|false $originalKey;

  /**
   * Keys merged into the decrypted reply data of the fixture.
   */
  private array $replyData = [];

  /**
   * Whether the operator trusts the case system's release flag.
   */
  private bool $trustRelease = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    mkdir($this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=base64:' . base64_encode(str_repeat('test-only-key', 4)));
    putenv('US115_TEST_CLIENT_SECRET=synthetic-only');
    require_once dirname(__DIR__, 3) . '/beantragt_delivery.install';
    $database = $this->container->get('database');
    foreach (beantragt_delivery_schema() + ExternalResponseRepository::schema() + FitConnectCaseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->repository = new ExternalResponseRepository($database);
    $this->cases = new FitConnectCaseRepository($database);
    $this->application = $this->createMock(ApplicationInterface::class);
    $this->application->method('id')->willReturn(1);
    $this->application->method('uuid')->willReturn('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $this->application->method('getOwnerId')->willReturn(7);
    $this->application->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_jurisdiction');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->willReturn(10);
    $field->method('__isset')->willReturn(TRUE);
    $this->application->method('get')->willReturn($field);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('fit_connect')->willReturnCallback(fn(): array => [
      'environment' => 'TEST',
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'US115_TEST_CLIENT_SECRET',
      'destination_id' => self::CASE_ID,
      'reply_enabled' => TRUE,
      'trust_external_release' => $this->trustRelease,
    ]);
    $this->factory = $this->createMock(ConfigFactoryInterface::class);
    $this->factory->method('get')->willReturn($config);
    $this->cases->prepare($this->application, 7, FitConnectSettings::fromConfig($this->factory));
    $submission = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $this->cases->bind(7, $submission, self::CASE_ID);
    $database->insert('beantragt_delivery_log')->fields([
      'id' => 7,
      'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
      'application_id' => 1,
      'application_uuid' => $this->application->uuid(),
      'adapter' => 'fit_connect',
      'status' => 'accepted',
      'data_hash' => str_repeat('a', 64),
      'created' => 1,
      'changed' => 1,
      'metadata' => json_encode(['fitConnect' => ['caseId' => self::CASE_ID, 'submissionId' => $submission]]),
    ])->execute();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' . ($this->originalKey === FALSE ? '' : '=' . $this->originalKey));
    putenv('US115_TEST_CLIENT_SECRET');
    parent::tearDown();
  }

  /**
   * Test import duplicate and recovery of lost acceptance response.
   */
  public function testImportDuplicateAndRecoveryOfLostAcceptanceResponse(): void {
    $service = $this->service(TRUE, FALSE, 0);
    $poller = $this->poller($service, FALSE, TRUE);
    $first = $poller->poll();
    self::assertSame(1, $first['imported']);
    self::assertSame(1, $first['failed']);
    self::assertCount(1, $this->cases->pendingAcks());
    self::assertCount(1, $service->listForApplication($this->application, TRUE));
    self::assertSame([], $service->listForApplication($this->application, FALSE));
    $second = $poller->poll();
    self::assertSame(1, $second['accepted']);
    self::assertSame(0, $second['failed']);
    self::assertCount(1, $service->listForApplication($this->application, TRUE));
    self::assertSame([], $this->cases->pendingAcks());
    self::assertSame(1, $this->acceptCalls);
  }

  /**
   * Imported PDFs are protected, denied without permission, and audited.
   */
  public function testImportedPdfRequiresProtectedAccessAndAuditsDownloads(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('notice')->with(self::anything(), self::callback(static fn(array $context): bool => $context['@channel'] === 'external_response_download'));
    $protection = new ApplicationDataProtectionService($this->createMock(EntityTypeManagerInterface::class), $logger);
    $service = $this->service(TRUE, FALSE, 0, protection: $protection);
    self::assertSame(1, $this->poller($service)->poll()['imported']);
    $response = $service->listForApplication($this->application, TRUE)[0];
    self::assertSame('1', (string) $this->repository->findExternal('fit_connect', self::REPLY)['contains_protected']);
    $document = $this->repository->documents($response['id'])[0];
    $directory = 'private://external_responses/' . $this->application->uuid() . '/' . $response['id'];
    mkdir($directory, 0770, TRUE);
    file_put_contents($directory . '/bescheid.pdf', '%PDF-1.7 synthetic');
    foreach ([[99, FALSE, []], [99, TRUE, []], [7, FALSE, ['service_account']]] as [$uid, $allowed, $roles]) {
      $reader = $this->createMock(AccountInterface::class);
      $reader->method('id')->willReturn($uid);
      $reader->method('getRoles')->willReturn($roles);
      $reader->method('hasPermission')->with(ExternalResponseService::PROTECTED_PERMISSION)->willReturn($allowed);
      try {
        $file = $service->download($this->application, $response['id'], $document['uuid'], TRUE, $reader);
        self::assertTrue($allowed, 'Protected PDF was disclosed without permission.');
        self::assertSame(12, $file->id());
      }
      catch (ProtectedResponseAccessException) {
        self::assertFalse($allowed);
      }
    }
  }

  /**
   * A connection that leaves closing to staff never closes on a notice.
   */
  public function testManualClosureRuleLeavesStatusToStaff(): void {
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::never())->method('caseSystemResponded');
    $poller = $this->poller($this->service(TRUE, FALSE, 0, $changer), integrations: $this->connectionWithRule('manual'));
    self::assertSame(1, $poller->poll()['imported']);
  }

  /**
   * Under on_receipt the acceptance closed already; a reply changes nothing.
   */
  public function testReceiptClosureRuleDoesNotCloseOnResponse(): void {
    $this->replyData = ['outcome' => 'approved'];
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::never())->method('caseSystemResponded');
    $poller = $this->poller($this->service(TRUE, FALSE, 0, $changer), integrations: $this->connectionWithRule('on_receipt'));
    self::assertSame(1, $poller->poll()['imported']);
    $stored = $this->repository->findExternal('fit_connect', self::REPLY);
    self::assertSame('approved', $stored['outcome']);
    self::assertSame('decision', $stored['kind']);
  }

  /**
   * The outcome and the follow-up flags of the connection reach the change.
   */
  public function testOutcomeAndFollowUpOfTheConnectionReachTheStatusChange(): void {
    $this->replyData = ['outcome' => 'rejected', 'releaseStatus' => 'pending'];
    $follow_up = [
      'approved' => 'approved',
      'rejected' => 'decision_issued',
      'filed' => 'filed',
      'decision' => 'decision_issued',
      'receipt' => 'filed',
    ];
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::once())->method('caseSystemResponded')
      ->with($this->application, 'rejected', FALSE, $follow_up, 'fit_connect')->willReturn(TRUE);
    $poller = $this->poller($this->service(TRUE, FALSE, 0, $changer), integrations: $this->connectionWithRule('on_response', ['follow_up' => ['rejected' => 'decision_issued']]));
    self::assertSame(1, $poller->poll()['imported']);
    $response = $this->service(TRUE, FALSE, 0)->listForApplication($this->application, TRUE)[0];
    self::assertSame('rejected', $response['outcome']);
    self::assertSame('decision', $response['kind']);
  }

  /**
   * A filing receipt stays with staff even when releases are trusted.
   */
  public function testFilingReceiptIsNeverReleasedOrAnnounced(): void {
    $this->trustRelease = TRUE;
    $this->replyData = ['outcome' => 'filed', 'releaseStatus' => 'pending'];
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::once())->method('caseSystemResponded')
      ->with($this->application, 'filed', FALSE, self::anything(), 'fit_connect')->willReturn(TRUE);
    $service = $this->service(TRUE, FALSE, 0, $changer);
    self::assertSame(1, $this->poller($service)->poll()['imported']);
    $staff = $service->listForApplication($this->application, TRUE);
    self::assertCount(1, $staff);
    self::assertSame('filing_receipt', $staff[0]['kind']);
    self::assertSame('filed', $staff[0]['outcome']);
    self::assertSame('pending', $staff[0]['status']);
    self::assertSame([], $service->listForApplication($this->application, FALSE));
    try {
      $service->release($this->application, $staff[0]['id']);
      self::fail('A filing receipt must not be releasable.');
    }
    catch (\DomainException) {
      self::assertNull($this->repository->findExternal('fit_connect', self::REPLY)['released']);
    }
  }

  /**
   * Trusted released replies with an outcome are released, by post.
   *
   * An approval is a decision, so delivery goes by
   * post, so the portal copy announces no email.
   */
  public function testTrustedApprovalIsReleasedByPostWithoutMail(): void {
    $this->trustRelease = TRUE;
    $this->replyData = ['outcome' => 'approved'];
    $service = $this->service(TRUE, TRUE, 0);
    self::assertSame(1, $this->poller($service)->poll()['imported']);
    $citizen = $service->listForApplication($this->application, FALSE);
    self::assertCount(1, $citizen);
    self::assertSame('postal', $citizen[0]['notificationState']);
    self::assertSame('postal', $citizen[0]['deliveryRoute']);
    self::assertTrue($citizen[0]['isDecision']);
  }

  /**
   * A filing receipt marked as released breaks the contract and is refused.
   */
  public function testReleasedFilingReceiptFailsVerification(): void {
    $this->replyData = ['outcome' => 'filed', 'releaseStatus' => 'released'];
    $result = $this->poller($this->service(TRUE, FALSE, 0))->poll();
    self::assertSame(1, $result['failed']);
    self::assertSame(0, $result['imported']);
    self::assertNull($this->repository->findExternal('fit_connect', self::REPLY));
  }

  /**
   * By default a notice from the sending connection closes the procedure.
   */
  public function testResponseClosureRuleClosesOnce(): void {
    $changer = $this->createMock(SemanticStatusChanger::class);
    // A reply without outcome (contract 1 before the outcome key) behaves as
    // before: no outcome, default follow-up (decision_issued).
    $changer->expects(self::once())->method('caseSystemResponded')
      ->with($this->application, NULL, FALSE, IntegrationRepository::presentation(NULL)['follow_up'], 'fit_connect')->willReturn(TRUE);
    $poller = $this->poller($this->service(TRUE, FALSE, 0, $changer), integrations: $this->connectionWithRule('on_response'));
    self::assertSame(1, $poller->poll()['imported']);
    // A replay of the same reply stores nothing and closes nothing again.
    $poller->poll();
  }

  /**
   * Binds the fixture transfer to a connection with the given closure rule.
   */
  private function connectionWithRule(string $rule, array $presentation = []): IntegrationRepository {
    $database = $this->container->get('database');
    $uuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $database->update('beantragt_delivery_log')->fields(['integration_connection_uuid' => $uuid])->condition('id', 7)->execute();
    $database->insert('beantragt_integration_connection')->fields([
      'uuid' => $uuid,
      'scope_group_id' => 10,
      'created' => 1,
      'changed' => 1,
      'uid' => 1,
      'contract_key' => 'synthetic',
      'contract_version' => '1',
      'label' => 'E-Akte',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'default',
      'destination_id' => self::CASE_ID,
      'service_urn' => 'urn:de:fim:leika:leistung:99000000000000',
      'service_name' => 'Ablage',
      'status' => 'active',
      'presentation' => json_encode(['closure_rule' => $rule] + $presentation),
    ])->execute();
    $registry = new SyntheticDataContractRegistry(contractDirectory: dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    return new IntegrationRepository($database, new Php(), $this->createMock(TimeInterface::class), $this->manager, new ScopeAccess($this->manager), new IntegrationCredentialProfiles($this->container->get('config.factory')), $registry, new IntegrationRules($registry));
  }

  /**
   * Test unsafe document is neither persisted nor accepted.
   */
  public function testUnsafeDocumentIsNeitherPersistedNorAccepted(): void {
    $poller = $this->poller($this->service(FALSE, FALSE, 0));
    $result = $poller->poll();
    self::assertSame(1, $result['failed']);
    self::assertSame(0, $result['imported']);
    self::assertSame(0, $this->acceptCalls);
    self::assertSame([], $this->repository->list(1, TRUE));
    self::assertSame([], $this->cases->pendingAcks());
  }

  /**
   * Erased cases are explicitly skipped before downloading authority replies.
   */
  public function testAnonymizedApplicationIsExplicitlySkipped(): void {
    $this->application->method('getData')->willReturn('{"_anonymized":true}');
    $poller = $this->poller($this->service(TRUE, FALSE, 0));
    $result = $poller->poll();
    self::assertSame(1, $result['skipped']);
    self::assertSame(0, $result['failed']);
    self::assertSame(0, $result['imported']);
    self::assertSame(0, $this->attachmentCalls);
    self::assertSame(0, $this->acceptCalls);
    self::assertSame([], $this->repository->list(1, TRUE));
  }

  /**
   * Test tampered envelope is neither persisted nor accepted.
   */
  public function testTamperedEnvelopeIsNeitherPersistedNorAccepted(): void {
    $poller = $this->poller($this->service(FALSE, FALSE, 0), TRUE);
    $result = $poller->poll();
    self::assertSame(1, $result['failed']);
    self::assertSame(0, $result['imported']);
    self::assertSame(0, $this->acceptCalls);
    self::assertSame([], $this->repository->list(1, TRUE));
  }

  /**
   * Test invalid attachment manifests are rejected before downloads.
   */
  public function testInvalidAttachmentManifestsAreRejectedBeforeDownloads(): void {
    $bad_manifests = [
      'not-a-list',
      ['named' => self::ATTACHMENT],
      [self::ATTACHMENT, self::ATTACHMENT],
      array_map(static fn(int $i): string => sprintf('33333333-3333-4333-8333-%012d', $i), range(1, 11)),
      ['invalid-uuid'],
      [],
    ];
    foreach ($bad_manifests as $manifest) {
      $mutate = static function (array &$envelope) use ($manifest): void {
        $envelope['attachments'] = $manifest;
      };
      $poller = $this->poller($this->service(FALSE, FALSE, 0), mutate_envelope: $mutate);
      $result = $poller->poll();
      self::assertSame(1, $result['failed']);
      self::assertSame(0, $result['imported']);
      self::assertSame(0, $this->attachmentCalls);
      self::assertSame(0, $this->acceptCalls);
    }
    self::assertSame([], $this->repository->list(1, TRUE));
    self::assertSame([], $this->cases->pendingAcks());
  }

  /**
   * Test wrong acceptance tags never mark response accepted.
   */
  public function testWrongAcceptanceTagsNeverMarkResponseAccepted(): void {
    $service = $this->service(TRUE, FALSE, 0);
    $poller = $this->poller($service, wrong_acceptance_tags: TRUE);
    $first = $poller->poll();
    self::assertSame(1, $first['imported']);
    self::assertSame(0, $first['accepted']);
    self::assertSame(1, $first['failed']);
    self::assertCount(1, $this->cases->pendingAcks());
    // The next call encounters the wrong signed receipt in event history.
    $second = $poller->poll();
    self::assertSame(0, $second['accepted']);
    self::assertSame(1, $second['failed']);
    self::assertCount(1, $this->cases->pendingAcks());
    self::assertCount(1, $service->listForApplication($this->application, TRUE));
  }

  /**
   * Test acceptance verifier binds expected authentication tags.
   */
  public function testAcceptanceVerifierBindsExpectedAuthenticationTags(): void {
    $fixture = $this->fixture();
    $claims = $fixture['claims'];
    $claims['events'] = [self::EVENT . 'accept-reply' => ['authenticationTags' => $fixture['tags']]];
    $set = $this->sign($claims);
    $verifier = new FitConnectReplyVerifier();
    $settings = FitConnectSettings::fromConfig($this->factory);
    $verifier->verifyAcceptance($set, $fixture['jwks'], $settings, self::REPLY, self::CASE_ID, $fixture['tags']);
    $wrong = $fixture['tags'];
    $wrong['data'] = rtrim(strtr(base64_encode(str_repeat('x', 16)), '+/', '-_'), '=');
    $this->expectException(FitConnectTransportException::class);
    $verifier->verifyAcceptance($set, $fixture['jwks'], $settings, self::REPLY, self::CASE_ID, $wrong);
  }

  /**
   * Poller.
   */
  private function poller(ExternalResponseService $responses, bool $tamper = FALSE, bool $lose_acceptance = FALSE, ?callable $mutate_envelope = NULL, bool $wrong_acceptance_tags = FALSE, ?IntegrationRepository $integrations = NULL): FitConnectReplyPoller {
    $fixture = $this->fixture();
    if ($tamper) {
      $fixture['envelope']['encryptedData'] .= 'x';
    }
    if ($mutate_envelope !== NULL) {
      $mutate_envelope($fixture['envelope']);
    }
    $acceptance_claims = $fixture['claims'];
    $acceptance_claims['events'] = [self::EVENT . 'accept-reply' => ['authenticationTags' => $fixture['tags']]];
    if ($wrong_acceptance_tags) {
      $acceptance_claims['events'][self::EVENT . 'accept-reply']['authenticationTags']['data'] = rtrim(strtr(base64_encode(str_repeat('x', 16)), '+/', '-_'), '=');
    }
    $acceptance = $this->sign($acceptance_claims);
    $remote_accepted = FALSE;
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($fixture, $acceptance, $lose_acceptance, &$remote_accepted): Response {
      $path = parse_url($url, PHP_URL_PATH);
      $json = static fn(array $value): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($value, JSON_THROW_ON_ERROR));
      $page = static fn(string $key, array $rows): array => [
        $key => $rows,
        'offset' => 0,
        'limit' => 50,
        'count' => count($rows),
        'totalCount' => count($rows),
      ];
      if ($path === '/token') {
        return $json(['access_token' => 'synthetic-token', 'token_type' => 'Bearer', 'expires_in' => 3600]);
      }
      if (str_ends_with($path, '/.well-known/jwks.json')) {
        return $json($fixture['jwks']);
      }
      if (str_ends_with($path, '/v2/replies')) {
        return $json($page('replies', [['replyId' => self::REPLY, 'caseId' => self::CASE_ID]]));
      }
      if (str_contains($path, '/v2/cases/')) {
        return $json(['caseId' => self::CASE_ID, 'destinationId' => self::CASE_ID]);
      }
      if (str_ends_with($path, '/events')) {
        return $json($page('eventLog', $remote_accepted ? [...$fixture['events'], $acceptance] : $fixture['events']));
      }
      if (str_ends_with($path, '/accept')) {
        self::assertNotEmpty($this->repository->findExternal('fit_connect', self::REPLY));
        self::assertSame($fixture['tags'], $options['json']['authenticationTags']);
        $this->acceptCalls++;
        $remote_accepted = TRUE;
        return $lose_acceptance ? new Response(500) : new Response(200, ['Content-Type' => 'application/jose'], $acceptance);
      }
      if (str_contains($path, '/attachments/')) {
        $this->attachmentCalls++;
        return new Response(200, ['Content-Type' => 'application/jose'], $fixture['attachments'][self::ATTACHMENT]);
      }
      if (str_ends_with($path, '/v2/replies/' . self::REPLY)) {
        return $json($fixture['envelope']);
      }
      throw new \LogicException('Unexpected test request path.');
    });
    $logs = new DeliveryLogRepository($this->container->get('database'), $this->createMock(UuidInterface::class), $this->createMock(TimeInterface::class), new NullLogger());
    return new FitConnectReplyPoller($this->factory, new FitConnectAccessTokenClient($http), new FitConnectReplyApiClient($http), new FitConnectReplyVerifier(), $this->cases, $responses, $this->manager, $this->mutationLock, $this->lock, $logs, $this->container->get('state'), NULL, $integrations);
  }

  /**
   * Service.
   */
  private function service(bool $clean, bool $sent, int $notifications, ?SemanticStatusChanger $changer = NULL, ?ApplicationDataProtectionService $protection = NULL): ExternalResponseService {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(12);
    $file->method('getFilename')->willReturn('bescheid.pdf');
    $file->method('getSize')->willReturn(50);
    $file->method('getFileUri')->willReturnCallback(fn(): string => 'private://external_responses/' . $this->application->uuid() . '/' . $this->repository->findExternal('fit_connect', self::REPLY)['uuid'] . '/bescheid.pdf');
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(12)->willReturn($file);
    $app_storage = $this->createMock(EntityStorageInterface::class);
    $app_storage->method('load')->with(1)->willReturn($this->application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $this->manager = $manager;
    $manager->method('getStorage')->willReturnMap([['application', $app_storage], ['file', $file_storage]]);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->method('writeData')->willReturn($file);
    if (!$clean) {
      $file_repository->expects(self::never())->method('writeData');
    }
    $fs = $this->createMock(FileSystemInterface::class);
    $fs->method('prepareDirectory')->willReturn(TRUE);
    $scanner = $this->createMock(AttachmentScanServiceInterface::class);
    $scanner->method('scan')->willReturn(new AttachmentScanResult($clean ? 'clean' : 'quarantined'));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $this->lock = $lock;
    $uuid = $this->createMock(UuidInterface::class);
    $i = 0;
    $uuid->method('generate')->willReturnCallback(static function () use (&$i): string {
      return 'uuid-' . ++$i;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects(self::exactly($notifications))->method('sendCitizenNotificationWithResult')->with($this->application, 'external_response_available')->willReturn($sent);
    $this->mutationLock = new ApplicationDependentMutationLock($manager, $lock);
    return new ExternalResponseService($this->repository, $manager, $fs, $file_repository, $scanner, $this->mutationLock, $uuid, $time, $mailer, $changer, $protection);
  }

  /**
   * Fixture.
   */
  private function fixture(?callable $mutate = NULL): array {
    $private = $this->encryptionKey();
    $public = $private->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $pdf = "%PDF-1.7\nSynthetic decision\n%%EOF\n";
    $data = [
      'type' => 'decision',
      'title' => 'Synthetischer Bescheid',
      'externalReference' => 'DEMO-115',
      'releaseStatus' => 'released',
    ];
    $data = array_replace($data, $this->replyData);
    $metadata = [
      '$schema' => FitConnectReplyVerifier::METADATA_SCHEMA,
      'contentStructure' => [
        'data' => [
          'submissionSchema' => [
            'schemaUri' => FitConnectReplyVerifier::BUSINESS_SCHEMA,
            'mimeType' => 'application/json',
          ],
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', json_encode($data, JSON_THROW_ON_ERROR))],
        ],
        'attachments' => [[
          'attachmentId' => self::ATTACHMENT,
          'purpose' => 'report',
          'filename' => 'bescheid.pdf',
          'mimeType' => 'application/pdf',
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', $pdf)],
        ],
        ],
      ],
    ];
    if ($mutate !== NULL) {
      $previous_data = $data;
      $previous_pdf = $pdf;
      $mutate($metadata, $data, $pdf);
      if ($data !== $previous_data) {
        $metadata['contentStructure']['data']['hash']['content'] = hash('sha512', json_encode($data, JSON_THROW_ON_ERROR));
      }
      if ($pdf !== $previous_pdf) {
        $metadata['contentStructure']['attachments'][0]['hash']['content'] = hash('sha512', $pdf);
      }
    }
    $encrypter = new FitConnectJweEncrypter();
    $envelope = [
      'replyId' => self::REPLY,
      'caseId' => self::CASE_ID,
      'attachments' => [self::ATTACHMENT],
      'encryptedMetadata' => $encrypter->encrypt(json_encode($metadata, JSON_THROW_ON_ERROR), $public),
      'encryptedData' => $encrypter->encrypt(json_encode($data, JSON_THROW_ON_ERROR), $public),
    ];
    $attachments = [self::ATTACHMENT => $encrypter->encrypt($pdf, $public, 'application/pdf')];
    $tags = [
      'metadata' => explode('.', $envelope['encryptedMetadata'])[4],
      'data' => explode('.', $envelope['encryptedData'])[4],
      'attachments' => [self::ATTACHMENT => explode('.', $attachments[self::ATTACHMENT])[4]],
    ];
    $claims = [
      '$schema' => 'https://schema.fitko.de/fit-connect/set-payload/1.2.2/set-payload.schema.json',
      'iss' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'sub' => 'reply:' . self::REPLY,
      'txn' => 'case:' . self::CASE_ID,
      'jti' => '44444444-4444-4444-8444-444444444444',
      'iat' => time(),
      'events' => [self::EVENT . 'submit-reply' => ['authenticationTags' => $tags]],
    ];
    $signing_public = $this->signingKey()->toPublic()->all();
    $signing_public['key_ops'] = ['verify'];
    return [
      'envelope' => $envelope,
      'attachments' => $attachments,
      'events' => [$this->sign($claims)],
      'jwks' => ['keys' => [$signing_public]],
      'private' => $private->all(),
      'claims' => $claims,
      'tags' => $tags,
      'pdf' => $pdf,
    ];
  }

  /**
   * Signs service events with a freshly generated ephemeral test key.
   */
  private function sign(array $claims): string {
    $jws = (new JWSBuilder(new AlgorithmManager([new PS512()])))
      ->create()->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
      ->addSignature($this->signingKey(), ['typ' => 'secevent+jwt', 'alg' => 'PS512', 'kid' => 'unit-signing-key'])->build();
    return (new CompactSerializer())->serialize($jws, 0);
  }

  /**
   * Reuses a runtime-only encryption key across tampering examples.
   */
  private function encryptionKey(): JWK {
    return new JWK($this->cases->privateKey($this->cases->load(['log_id' => 7])));
  }

  /**
   * Reuses a runtime-only signing key across tampering examples.
   */
  private function signingKey(): JWK {
    static $key;
    if ($key === NULL) {
      $key = JWKFactory::createRSAKey(3072, ['kid' => 'unit-signing-key', 'alg' => 'PS512']);
    }
    return $key;
  }

}
