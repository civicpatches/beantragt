<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Gateway;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Gateway\FitConnectDeliveryGateway;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\beantragt_delivery\Service\FitConnectMetadataDocumentBuilder;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionApiClient;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionMetadataBuilder;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Tests encrypted dispatch and crash-safe outbox guards without real HTTP.
 *
 * @group beantragt_delivery
 */
final class FitConnectDeliveryGatewayTest extends UnitTestCase {

  private const APPLICATION_UUID = '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce';
  private const SUBMISSION_ID = 'c7c83388-6eea-4186-bcdb-e5f9feaa9703';
  private const CASE_ID = 'febbf346-86c5-42bb-9e8b-6b11c4841f2f';
  private const ATTACHMENT_ID = '11d08429-f6e6-483e-85a5-cb09d31a7f12';
  private const ATTACHMENT_BYTES = "%PDF-1.7\nsynthetic-private-attachment\0\xff";
  private const DATA = '{"field":"private-test-value"}';

  /**
   * Runtime-only receiver key used to verify the gateway's encrypted bytes.
   */
  private static ?JWK $receiverKey = NULL;

  /**
   * The persisted application JSON, optionally protected at rest.
   */
  private string $storedData = self::DATA;

  /**
   * Saved environment, never printed.
   *
   * @var array<string, string|false>
   */
  private array $environment = [];

  /**
   * Mutable database row used by the real repository.
   *
   * @var array<string, mixed>
   */
  private array $row = [];

  /**
   * Requests observed by the offline HTTP handler.
   *
   * @var list<\Psr\Http\Message\RequestInterface>
   */
  private array $requests = [];

  /**
   * Outbox phases observed when requests actually execute.
   *
   * @var list<string>
   */
  private array $requestPhases = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'CLIENT_ID', 'CLIENT_SECRET', 'DESTINATION_ID', 'SERVICE_URN', 'ARS',
      'SCHEMA_URI', 'ENVIRONMENT', 'TOKEN_URL', 'SUBMISSION_API_BASE',
    ] as $suffix) {
      $name = 'FIT_CONNECT_' . $suffix;
      $this->environment[$name] = getenv($name);
      putenv($name);
    }
    putenv('FIT_CONNECT_CLIENT_SECRET=synthetic-test-secret');
    $this->row = [
      'id' => 8,
      'application_id' => 10,
      'application_uuid' => self::APPLICATION_UUID,
      'adapter' => 'fit_connect',
      'legacy_eligible' => 1,
      'status' => DeliveryLogRepository::STATUS_PROCESSING,
      'metadata' => '{}',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Missing configuration fails before application or network access.
   */
  public function testMissingConfigurationFailsClosed(): void {
    $result = $this->gateway([], [])->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.missing_configuration', $result->errorCode);
    $this->assertContains('client_id', $result->metadata['missing']);
    $this->assertContains('destination_id', $result->metadata['missing']);
    $this->assertSame([], $this->requests);
    $this->assertSafe($result->metadata);
  }

  /**
   * Production is blocked before even a token request.
   */
  public function testProductionGuard(): void {
    $config = $this->completeConfig();
    $config['submission_api_base'] = 'https://prod.fit-connect.fitko.net/submission-api';
    $result = $this->gateway([], $config)->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.test_only', $result->errorCode);
    $this->assertSame([], $this->requests);
  }

  /**
   * Delivery cannot bypass its durable outbox context.
   */
  public function testLogContextRequired(): void {
    $result = $this->gateway([])->deliver($this->envelope());
    $this->assertSame('fit_connect.log_context_required', $result->errorCode);
    $this->assertSame([], $this->requests);
  }

  /**
   * Inactive destinations and invalid keys never create a submission.
   *
   * @dataProvider rejectedDestinationProvider
   */
  public function testDestinationValidation(array $destination, ?array $key, string $error): void {
    $responses = [$this->tokenResponse(), $this->jsonResponse($destination)];
    if ($key !== NULL) {
      $responses[] = $this->jsonResponse($key);
    }
    $result = $this->gateway($responses)->deliver($this->envelope(), 8);
    $this->assertSame($error, $result->errorCode);
    foreach ($this->requests as $request) {
      $this->assertFalse($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/submissions'));
    }
    $this->assertSame('{}', $this->row['metadata']);
  }

  /**
   * Destination and key guard cases.
   */
  public static function rejectedDestinationProvider(): array {
    $destination = ['status' => 'active', 'metadataVersions' => ['2.1.0'], 'encryptionKid' => 'test-key'];
    $key = [
      'kty' => 'RSA',
      'alg' => 'RSA-OAEP-256',
      'key_ops' => ['wrapKey'],
      'kid' => 'test-key',
      'e' => 'AQAB',
      'n' => rtrim(strtr(base64_encode(str_repeat("\xff", 512)), '+/', '-_'), '='),
    ];
    return [
      'inactive' => [array_replace($destination, ['status' => 'inactive']), NULL, 'fit_connect.destination_inactive'],
      'version' => [
        array_replace($destination, ['metadataVersions' => ['2.0.0']]),
        NULL, 'fit_connect.metadata_version_unsupported',
      ],
      'algorithm' => [$destination, array_replace($key, ['alg' => 'RSA-OAEP']), 'fit_connect.invalid_encryption_key'],
      'operation' => [
        $destination, array_replace($key, ['key_ops' => ['verify']]),
        'fit_connect.invalid_encryption_key',
      ],
      'size' => [$destination, array_replace($key, ['n' => 'AQAB']), 'fit_connect.encryption_key_too_small'],
    ];
  }

  /**
   * A complete offline exchange encrypts payloads and checkpoints before PUT.
   */
  public function testEncryptedDispatchCheckpointsAndSafeResult(): void {
    $responses = [
      ...$this->preparationResponses(), $this->createdResponse(),
      $this->jsonResponse([]), $this->jsonResponse(['eventLog' => []]),
    ];
    $result = $this->gateway($responses)->deliver($this->envelope(), 8);
    $this->assertSame('accepted', $result->status, $result->errorCode);
    $this->assertSame(self::SUBMISSION_ID, $result->externalId);
    $this->assertSame(self::CASE_ID, $result->receipt);
    $this->assertSame(['', '', '', 'creating', 'created', 'submitted'], $this->requestPhases);
    $created = json_decode((string) $this->requests[3]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('destination-123', $created['destinationId']);
    $this->assertSame([], $created['announcedAttachments']);
    $finalized = json_decode((string) $this->requests[4]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    foreach (['encryptedMetadata', 'encryptedData'] as $field) {
      $parts = explode('.', $finalized[$field]);
      $this->assertCount(5, $parts);
      $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => 'test-key', 'cty' => 'application/json'], $header);
    }
    $persisted = json_decode($this->row['metadata'], TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(self::SUBMISSION_ID, $persisted['fitConnect']['submissionId']);
    $this->assertSame(self::CASE_ID, $persisted['fitConnect']['caseId']);
    $this->assertSame('submitted', $persisted['fitConnect']['phase']);
    $this->assertSafe($persisted);
    $this->assertSafe($result->metadata);
    $this->assertSafe($finalized);
  }

  /**
   * Claimed clean private attachments are encrypted and announced consistently.
   */
  public function testAttachmentDispatch(): void {
    $this->assertNotContains('private', stream_get_wrappers());
    vfsStream::setup('delivery', NULL, ['proof.pdf' => self::ATTACHMENT_BYTES]);
    $this->assertTrue(stream_wrapper_register('private', FitConnectPrivateTestStream::class));
    try {
      $gateway = $this->gateway([
        ...$this->preparationResponses(), $this->createdResponse(),
        new Response(204), $this->jsonResponse([]), $this->jsonResponse(['eventLog' => []]),
      ], attachment: []);
      $result = $gateway->deliver($this->envelope(TRUE), 8);
      $this->assertSame('accepted', $result->status, $result->errorCode);
      $this->assertSame(['', '', '', 'creating', 'created', 'created', 'submitted'], $this->requestPhases);
      $created = json_decode((string) $this->requests[3]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame([self::ATTACHMENT_ID], $created['announcedAttachments']);
      $upload = $this->requests[4];
      $this->assertSame('PUT', $upload->getMethod());
      $this->assertStringEndsWith('/attachments/' . self::ATTACHMENT_ID, $upload->getUri()->getPath());
      $this->assertSame('application/jose', $upload->getHeaderLine('Content-Type'));
      $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
      $encrypted = (new CompactSerializer())->unserialize((string) $upload->getBody());
      $this->assertSame('application/pdf', $encrypted->getSharedProtectedHeaderParameter('cty'));
      $this->assertFalse($encrypted->hasSharedProtectedHeaderParameter('zip'));
      $this->assertTrue($decrypter->decryptUsingKey($encrypted, self::$receiverKey, 0));
      $this->assertSame(self::ATTACHMENT_BYTES, $encrypted->getPayload());
      $body = json_decode((string) $this->requests[5]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
      $metadata = (new CompactSerializer())->unserialize($body['encryptedMetadata']);
      $this->assertTrue($decrypter->decryptUsingKey($metadata, self::$receiverKey, 0));
      $document = json_decode($metadata->getPayload(), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame([
        'attachmentId' => self::ATTACHMENT_ID,
        'hash' => ['type' => 'sha512', 'content' => hash('sha512', self::ATTACHMENT_BYTES)],
        'purpose' => 'attachment',
        'mimeType' => 'application/pdf',
        'filename' => 'proof.pdf',
      ], $document['contentStructure']['attachments'][0]);
      $this->assertSafe($result->metadata);
      $this->assertSafe(json_decode($this->row['metadata'], TRUE, flags: JSON_THROW_ON_ERROR));
    }
    finally {
      stream_wrapper_unregister('private');
    }
  }

  /**
   * Foreign or unscanned attachments abort before credentials or network use.
   */
  public function testAttachmentOwnershipAndScanGuards(): void {
    foreach ([['applicationId' => 999], ['scanStatus' => 'pending']] as $attachment) {
      $result = $this->gateway([], attachment: $attachment)->deliver($this->envelope(TRUE), 8);
      $this->assertSame('fit_connect.attachment_unavailable', $result->errorCode);
      $this->assertSame([], $this->requests);
      $this->assertSame('{}', $this->row['metadata']);
    }
  }

  /**
   * Protected field storage is revealed only inside the encrypted transport.
   */
  public function testAtRestEncryptionIsRemovedBeforeDelivery(): void {
    $name = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';
    $this->environment[$name] = getenv($name);
    putenv($name . '=' . base64_encode(random_bytes(48)));
    $protection = new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $schema = ['sections' => [['fields' => [['id' => 'field', 'sensitive' => TRUE]]]]];
    $this->storedData = $protection->protectDataJson(self::DATA, json_encode($schema, JSON_THROW_ON_ERROR));
    $this->assertStringNotContainsString('private-test-value', $this->storedData);
    $gateway = $this->gateway([
      ...$this->preparationResponses(), $this->createdResponse(),
      $this->jsonResponse([]), $this->jsonResponse(['eventLog' => []]),
    ]);
    $result = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('accepted', $result->status, $result->errorCode);
    $body = json_decode((string) $this->requests[4]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
    $data = (new CompactSerializer())->unserialize($body['encryptedData']);
    $this->assertTrue($decrypter->decryptUsingKey($data, self::$receiverKey, 0));
    $this->assertSame(self::DATA, $data->getPayload());
    $metadata = (new CompactSerializer())->unserialize($body['encryptedMetadata']);
    $this->assertTrue($decrypter->decryptUsingKey($metadata, self::$receiverKey, 0));
    $document = json_decode($metadata->getPayload(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(hash('sha512', self::DATA), $document['contentStructure']['data']['hash']['content']);
    $this->assertSafe(json_decode($this->row['metadata'], TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Unknown POST outcomes fail closed on retry, without another network call.
   */
  public function testCreateFailureThenRetryNeverRepeatsPost(): void {
    $gateway = $this->gateway([...$this->preparationResponses(), new Response(503, [], 'sensitive-remote-error')]);
    $first = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.api_rejected', $first->errorCode);
    $repository = $this->repository();
    $repository->markResult(8, $first);
    $repository->markProcessing(8, 2);
    $second = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.submission_already_started', $second->errorCode);
    $this->assertCount(4, $this->requests);
    $this->assertSame('creating', json_decode($this->row['metadata'], TRUE)['fitConnect']['phase']);
    $this->assertSafe($first->metadata);
    $this->assertStringNotContainsString('sensitive-remote-error', $first->errorMessage);
  }

  /**
   * Failures after create preserve IDs for reconciliation and suppress retries.
   */
  public function testFinalizeFailureRetainsIdsAndSuppressesRetry(): void {
    $gateway = $this->gateway([...$this->preparationResponses(), $this->createdResponse(), new Response(500)]);
    $first = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.api_rejected', $first->errorCode);
    $repository = $this->repository();
    $repository->markResult(8, $first);
    $repository->markProcessing(8, 2);
    $second = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('fit_connect.submission_already_started', $second->errorCode);
    $this->assertSame(self::SUBMISSION_ID, $second->metadata['submissionId']);
    $this->assertSame(self::CASE_ID, $second->metadata['caseId']);
    $this->assertCount(5, $this->requests);
  }

  /**
   * Event lookup failure cannot erase a finalized transport acceptance.
   */
  public function testEventsFailurePreservesAcceptance(): void {
    $gateway = $this->gateway([
      ...$this->preparationResponses(), $this->createdResponse(),
      $this->jsonResponse([]), new Response(503, [], 'sensitive-remote-error'),
    ]);
    $result = $gateway->deliver($this->envelope(), 8);
    $this->assertSame('accepted', $result->status);
    $this->assertSame(self::SUBMISSION_ID, $result->externalId);
    $this->assertSame(self::CASE_ID, $result->receipt);
    $this->assertSame('fit_connect.api_rejected', $result->metadata['events']['errorCode']);
    $this->assertSame(503, $result->metadata['events']['statusCode']);
    $this->assertSame('submitted', json_decode($this->row['metadata'], TRUE)['fitConnect']['phase']);
    $this->assertSafe($result->metadata);
    $this->assertStringNotContainsString('sensitive-remote-error', json_encode($result->metadata, JSON_THROW_ON_ERROR));
  }

  /**
   * Preflight reports supported TEST capability without fetching credentials.
   */
  public function testPreflight(): void {
    $preflight = $this->gateway([])->preflight();
    $this->assertTrue($preflight['transportConfigReady']);
    $this->assertTrue($preflight['encryptedSubmissionPackageReady']);
    $this->assertSame([], $preflight['missing']);
    $this->assertSame([], $this->requests);
    $this->assertSafe($preflight);
  }

  /**
   * Builds all real services with mocked database, entities and HTTP only.
   */
  private function gateway(array $responses, ?array $config_values = NULL, ?array $attachment = NULL): FitConnectDeliveryGateway {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('fit_connect')->willReturn($config_values ?? $this->completeConfig());
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('beantragt_delivery.settings')->willReturn($config);
    $mock = new MockHandler($responses);
    $handler = function (RequestInterface $request, array $options) use ($mock) {
      $this->requests[] = $request;
      $metadata = json_decode($this->row['metadata'], TRUE);
      $this->requestPhases[] = $metadata['fitConnect']['phase'] ?? '';
      return $mock($request, $options);
    };
    $http = new Client(['handler' => HandlerStack::create($handler)]);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn(self::APPLICATION_UUID);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('getData')->willReturn($this->storedData);
    $application->method('getCreatedTime')->willReturn(1789689600);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(10)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    if ($attachment === NULL) {
      $manager->method('getStorage')->with('application')->willReturn($storage);
    }
    else {
      $attachment_entity = $this->createMock(ApplicationAttachmentInterface::class);
      $values = [
        'field_application' => ['target_id' => $attachment['applicationId'] ?? 10],
        'field_scan_status' => ['value' => $attachment['scanStatus'] ?? 'clean'],
        'field_file' => ['target_id' => 23],
        'field_original_filename' => ['value' => 'proof.pdf'],
        'field_mime_type' => ['value' => 'application/pdf'],
      ];
      $attachment_entity->method('get')->willReturnCallback(function (string $name) use ($values) {
        $field = $this->createMock(FieldItemListInterface::class);
        $field->method('__get')->willReturnCallback(static fn(string $key) => $values[$name][$key] ?? NULL);
        return $field;
      });
      $attachment_storage = $this->createMock(EntityStorageInterface::class);
      $attachment_storage->expects($this->once())->method('loadByProperties')->with([
        'uuid' => self::ATTACHMENT_ID,
        'field_application' => 10,
      ])->willReturn([$attachment_entity]);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn('private://delivery/proof.pdf');
      $file_storage = $this->createMock(EntityStorageInterface::class);
      $valid = ($attachment['applicationId'] ?? 10) === 10 && ($attachment['scanStatus'] ?? 'clean') === 'clean';
      $file_storage->expects($valid ? $this->once() : $this->never())->method('load')->with(23)->willReturn($file);
      $manager->method('getStorage')->willReturnMap([
        ['application', $storage], ['application_attachment', $attachment_storage], ['file', $file_storage],
      ]);
    }
    return new FitConnectDeliveryGateway($factory, new FitConnectAccessTokenClient($http), new FitConnectSubmissionMetadataBuilder(), new FitConnectSubmissionApiClient($http), new FitConnectMetadataDocumentBuilder(), new FitConnectJweEncrypter(), $manager, $this->repository(), new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)));
  }

  /**
   * In-memory query doubles exercise the real persistence implementation.
   */
  private function repository(): DeliveryLogRepository {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturnCallback(fn(): array => $this->row);
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'range'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('execute')->willReturn($statement);
    $update = $this->createMock(Update::class);
    $update->method('condition')->willReturnSelf();
    $update->method('fields')->willReturnCallback(function (array $fields) use ($update) {
      $this->row = array_replace($this->row, $fields);
      return $update;
    });
    $update->method('execute')->willReturn(1);
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $database->method('update')->willReturn($update);
    return new DeliveryLogRepository($database, $this->createMock(UuidInterface::class), $this->createMock(TimeInterface::class), $this->createMock(LoggerInterface::class));
  }

  /**
   * Returns a public RSA key generated only for this offline test process.
   */
  private function preparationResponses(): array {
    self::$receiverKey ??= JWKFactory::createRSAKey(4096, ['kid' => 'test-key', 'alg' => 'RSA-OAEP-256']);
    $jwk = self::$receiverKey->toPublic()->all();
    $jwk['key_ops'] = ['wrapKey'];
    return [
      $this->tokenResponse(),
      $this->jsonResponse([
        'status' => 'active',
        'metadataVersions' => ['2.1.0'],
        'encryptionKid' => 'test-key',
      ]),
      $this->jsonResponse($jwk),
    ];
  }

  /**
   * Builds a mock OAuth response.
   */
  private function tokenResponse(): Response {
    return $this->jsonResponse([
      'access_token' => 'synthetic-test-token',
      'expires_in' => 1800,
      'token_type' => 'Bearer',
    ]);
  }

  /**
   * Builds a mock create response.
   */
  private function createdResponse(): Response {
    return $this->jsonResponse(['submissionId' => self::SUBMISSION_ID, 'caseId' => self::CASE_ID], 201);
  }

  /**
   * Builds a mock JSON response.
   */
  private function jsonResponse(array $body, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Ensures transient secrets and payloads never enter a safe projection.
   */
  private function assertSafe(array $value): void {
    $serialized = json_encode($value, JSON_THROW_ON_ERROR);
    foreach ([
      'synthetic-test-secret', 'synthetic-test-token', 'private-test-value',
      'synthetic-private-attachment', 'proof.pdf', 'clientSecret"', 'access_token',
    ] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $serialized);
    }
  }

  /**
   * Builds complete non-secret TEST configuration.
   */
  private function completeConfig(): array {
    return [
      'environment' => 'TEST',
      'client_id' => 'sender-client',
      'client_secret_env' => 'FIT_CONNECT_CLIENT_SECRET',
      'destination_id' => 'destination-123',
      'service_urn' => 'urn:de:fim:leika:leistung:512431',
      'ars' => '000000000000',
      'schema_uri' => 'urn:beantragt:dev:musterantrag-bescheid:v1',
      'schema_mime_type' => 'application/json',
      'metadata_schema_version' => '2.1.0',
      'use_routing_api' => FALSE,
    ];
  }

  /**
   * Builds the payload-free queued snapshot.
   */
  private function envelope(bool $attachment = FALSE): DeliveryEnvelope {
    $manifest = $attachment ? [[
      'id' => self::ATTACHMENT_ID,
      'size' => strlen(self::ATTACHMENT_BYTES),
    ],
    ] : [];
    return new DeliveryEnvelope(
      10, self::APPLICATION_UUID, 'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951', 'Hundesteuer', 1,
      hash('sha256', $this->storedData), 1, $attachment ? 1 : 0, [], $manifest,
    );
  }

}

/**
 * Maps the private URI scheme to a strictly in-memory filesystem in tests.
 */
final class FitConnectPrivateTestStream extends vfsStreamWrapper {

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- PHP stream wrapper API.
  public function stream_open($path, $mode, $options, $opened_path) {
    return parent::stream_open(str_replace('private://', 'vfs://', $path), $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- PHP stream wrapper API.
  public function url_stat($path, $flags) {
    return parent::url_stat(str_replace('private://', 'vfs://', $path), $flags);
  }

}
