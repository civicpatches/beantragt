<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectCaseRepository;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
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
use Drupal\Component\Uuid\Php;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use GuzzleHttp\Client;
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
use Psr\Log\NullLogger;

/**
 * Verifies encrypted per-case key durability and immutable transport bindings.
 */
#[Group('beantragt_delivery')]
final class FitConnectCaseRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];
  /**
   * The real repository backed by the isolated test database.
   */
  private FitConnectCaseRepository $cases;
  /**
   * Original wrapping-key environment value, restored after each test.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=base64:' . base64_encode(str_repeat('test-only-key', 4)));
    $database = $this->container->get('database');
    foreach (FitConnectCaseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->cases = new FitConnectCaseRepository($database);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' . ($this->originalKey === FALSE ? '' : '=' . $this->originalKey));
    parent::tearDown();
  }

  /**
   * Case keys survive reloads without exposing private material.
   */
  public function testKeyIsDurableEncryptedAndPreparedOnlyOnce(): void {
    $application = $this->application();
    $public = $this->cases->prepare($application, 7, $this->settings());
    self::assertArrayNotHasKey('d', $public);
    self::assertArrayNotHasKey('p', $public);
    $row = $this->cases->load(['log_id' => 7]);
    $private = $this->cases->privateKey($row);
    self::assertSame($public['n'], $private['n']);
    self::assertArrayHasKey('d', $private);
    foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $component) {
      self::assertStringNotContainsString($private[$component], $row['private_key']);
      self::assertStringNotContainsString($private[$component], $row['public_key']);
    }
    self::assertSame($public, $this->cases->prepare($application, 7, $this->settings()));
    $this->cases->bind(7, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $reloaded = (new FitConnectCaseRepository($this->container->get('database')))->load(['log_id' => 7]);
    self::assertSame($private, $this->cases->privateKey($reloaded));
    self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $reloaded['case_id']);
  }

  /**
   * Ciphertext and binding context are authenticated together.
   */
  public function testTamperingWithEncryptedKeyOrAuthenticatedContextFails(): void {
    $this->cases->prepare($this->application(), 7, $this->settings());
    $row = $this->cases->load(['log_id' => 7]);
    foreach ([
      'log_id', 'application_uuid', 'scope_hash', 'client_hash',
      'destination_id', 'process_standard', 'private_key',
    ] as $field) {
      $altered = $row;
      $altered[$field] = $field === 'log_id' ? 8 : 'tampered';
      try {
        $this->cases->privateKey($altered);
        self::fail('Authenticated context alteration accepted: ' . $field);
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=base64:' . base64_encode(str_repeat('wrong-key', 5)));
    $this->expectException(\RuntimeException::class);
    $this->cases->privateKey($row);
  }

  /**
   * Original application and transport boundaries remain immutable.
   */
  public function testBindingRejectsApplicationTenantClientAndDestinationChanges(): void {
    $this->cases->prepare($this->application(), 7, $this->settings());
    $row = $this->cases->load(['log_id' => 7]);
    foreach ([
      [$this->application(2), $this->settings()],
      [$this->application(1, 99), $this->settings()],
      [$this->application(), $this->settings(['client_id' => 'another-client'])],
      [$this->application(), $this->settings(['destination_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])],
    ] as [$application, $settings]) {
      try {
        $this->cases->assertBinding($row, $application, $settings);
        self::fail('Changed original delivery binding was accepted.');
      }
      catch (FitConnectTransportException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Internal delegation preserves the original case binding.
   */
  public function testBindingSurvivesOrganisationReassignmentWithinJurisdiction(): void {
    $original = $this->application(1, 10, 11, 12);
    $public = $this->cases->prepare($original, 7, $this->settings());
    $row = $this->cases->load(['log_id' => 7]);
    $reassigned = $this->application(1, 10, 99, 100);
    $this->cases->assertBinding($row, $reassigned, $this->settings());
    self::assertSame($public, $this->cases->prepare($reassigned, 7, $this->settings()));
    self::assertSame($public['n'], $this->cases->privateKey($row)['n']);
  }

  /**
   * Acknowledgements deduplicate and disappear with their application.
   */
  public function testAcknowledgementDurabilityDedupAndDeletion(): void {
    $application = $this->application();
    $this->cases->prepare($application, 7, $this->settings());
    $this->cases->bind(7, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
    $binding = $this->cases->load(['log_id' => 7]);
    $reply = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $tags = ['metadata' => 'tag1', 'data' => 'tag2'];
    $this->cases->pendingAck($reply, $binding, $tags);
    $this->cases->pendingAck($reply, $binding, $tags);
    self::assertCount(1, $this->cases->pendingAcks());
    try {
      $this->cases->pendingAck($reply, $binding, ['metadata' => 'wrong']);
      self::fail('Conflicting technical acceptance was accepted.');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }
    $this->cases->accepted($reply, 'signed-set');
    $this->cases->pendingAck($reply, $binding, $tags);
    self::assertSame([], $this->cases->pendingAcks());
    $this->cases->cleanupForApplication($application);
    self::assertNull($this->cases->load(['log_id' => 7]));
    self::assertSame(0, (int) $this->container->get('database')->select('beantragt_fit_reply_ack')->countQuery()->execute()->fetchField());
  }

  /**
   * Key generation fails closed without application encryption material.
   */
  public function testMissingWrappingKeyFailsBeforePublicKeyPersistence(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    try {
      $this->cases->prepare($this->application(), 7, $this->settings());
      self::fail('Unprotected reply key was persisted.');
    }
    catch (\RuntimeException) {
      self::assertNull($this->cases->load(['log_id' => 7]));
    }
  }

  /**
   * Real dispatch advertises a durable public case key and binds returned IDs.
   */
  public function testGatewayAdvertisesOnlyDurablyStoredPublicReplyKey(): void {
    $fixture = $this->dispatchWithReplyServices([
      [
        'identifier' => 'urn:test:service',
        'replyChannels' => [
          'fitConnect' => [
            'processStandards' => ['urn:beantragt:process:decision:1'],
          ],
        ],
      ],
    ]);
    self::assertSame('accepted', $fixture['result']->status);
    self::assertCount(6, $fixture['requests']);
    $before_post = $fixture['requests'][3]['binding'];
    self::assertIsArray($before_post);
    self::assertNull($before_post['case_id']);
    self::assertNull($before_post['submission_id']);
    $private = $this->cases->privateKey($before_post);
    self::assertArrayHasKey('d', $private);
    self::assertStringNotContainsString($private['d'], $before_post['private_key']);
    $before_finalize = $fixture['requests'][4]['binding'];
    self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $before_finalize['submission_id']);
    self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $before_finalize['case_id']);
    $payload = json_decode((string) $fixture['requests'][4]['request']->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $jwe = (new CompactSerializer())->unserialize($payload['encryptedMetadata']);
    $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
    self::assertTrue($decrypter->decryptUsingKey($jwe, $fixture['receiverKey'], 0));
    $metadata = json_decode($jwe->getPayload(), TRUE, flags: JSON_THROW_ON_ERROR);
    $channel = $metadata['replyChannel']['fitConnect'];
    self::assertSame(['urn:beantragt:process:decision:1'], $channel['processStandards']);
    self::assertSame(json_decode($before_post['public_key'], TRUE), $channel['encryptionPublicKey']);
    $public = $channel['encryptionPublicKey'];
    self::assertSame('RSA', $public['kty']);
    self::assertSame('RSA-OAEP-256', $public['alg']);
    self::assertSame(['wrapKey'], $public['key_ops']);
    self::assertSame(512, strlen(base64_decode(strtr($public['n'], '-_', '+/'), TRUE)));
    self::assertGreaterThanOrEqual(128, ord(base64_decode(strtr($public['n'], '-_', '+/'), TRUE)[0]));
    foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $parameter) {
      self::assertArrayNotHasKey($parameter, $public);
      self::assertStringNotContainsString($private[$parameter], json_encode($metadata, JSON_THROW_ON_ERROR));
    }
    self::assertSame($private, $this->cases->privateKey($before_finalize));
  }

  /**
   * Missing support on the selected service cannot create a remote submission.
   */
  public function testGatewayRejectsUnsupportedReplyServiceBeforePost(): void {
    foreach ([
      [],
      [[
        'identifier' => 'urn:test:service',
        'replyChannels' => [
          'fitConnect' => [
            'processStandards' => ['urn:other:process'],
          ],
        ],
      ],
      ],
      [[
        'identifier' => 'urn:other:service',
        'replyChannels' => [
          'fitConnect' => [
            'processStandards' => ['urn:beantragt:process:decision:1'],
          ],
        ],
      ],
      ],
    ] as $services) {
      $fixture = $this->dispatchWithReplyServices($services);
      self::assertSame('fit_connect.reply_channel_unsupported', $fixture['result']->errorCode);
      self::assertCount(3, $fixture['requests']);
      self::assertNull($this->cases->load(['log_id' => $fixture['logId']]));
      foreach ($fixture['requests'] as $observed) {
        self::assertFalse($observed['request']->getMethod() === 'POST' && str_ends_with($observed['request']->getUri()->getPath(), '/submissions'));
      }
    }
  }

  /**
   * Dispatches using real persistence and crypto with exclusively offline HTTP.
   */
  private function dispatchWithReplyServices(array $services): array {
    $database = $this->container->get('database');
    if (!$database->schema()->tableExists('beantragt_delivery_log')) {
      require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
      $database->schema()->createTable('beantragt_delivery_log', beantragt_delivery_schema()['beantragt_delivery_log']);
    }
    $application = $this->application();
    $data = '{"synthetic":true}';
    $application->method('getData')->willReturn($data);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('getCreatedTime')->willReturn(1789689600);
    $envelope = new DeliveryEnvelope(1, $application->uuid(), 'DEMO-115', 'schema-1', 'Demo', 1, hash('sha256', $data), 1, 0);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1789689600);
    $time->method('getCurrentTime')->willReturn(1789689600);
    $logs = new DeliveryLogRepository($database, new Php(), $time, new NullLogger());
    $log_id = $logs->createQueued($application, $envelope, 'mock', 'TEST');
    // Synthetic pre-migration fixture, never a new legacy producer.
    $database->update('beantragt_delivery_log')->fields(['adapter' => 'fit_connect', 'legacy_eligible' => 1])->condition('id', $log_id)->execute();
    $logs->markProcessing($log_id, 1);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('fit_connect')->willReturn([
      'environment' => 'TEST',
      'client_id' => 'test-client',
      'client_secret_env' => 'UNIT_FIT_GATEWAY_SECRET',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'destination_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
      'service_urn' => 'urn:test:service',
      'ars' => '000000000000',
      'schema_uri' => 'urn:test:schema',
      'reply_enabled' => TRUE,
      'reply_process_standard' => 'urn:beantragt:process:decision:1',
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('beantragt_delivery.settings')->willReturn($config);
    static $receiver_key;
    if ($receiver_key === NULL) {
      $receiver_key = JWKFactory::createRSAKey(4096, ['kid' => 'test-destination-key', 'alg' => 'RSA-OAEP-256']);
    }
    $public = $receiver_key->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $json = static fn(array $body, int $status = 200): Response => new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
    $mock = new MockHandler([
      $json(['access_token' => 'synthetic-token', 'expires_in' => 1800, 'token_type' => 'Bearer']),
      $json([
        'status' => 'active',
        'metadataVersions' => ['2.1.0'],
        'encryptionKid' => 'test-destination-key',
        'publicServices' => $services,
      ]),
      $json($public),
      $json([
        'submissionId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'caseId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      ], 201),
      $json([]),
      $json(['eventLog' => []]),
    ]);
    $requests = [];
    $handler = function (RequestInterface $request, array $options) use ($mock, $log_id, &$requests) {
      $requests[] = ['request' => $request, 'binding' => $this->cases->load(['log_id' => $log_id])];
      return $mock($request, $options);
    };
    $http = new Client(['handler' => HandlerStack::create($handler)]);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $gateway = new FitConnectDeliveryGateway(
      $factory, new FitConnectAccessTokenClient($http), new FitConnectSubmissionMetadataBuilder(),
      new FitConnectSubmissionApiClient($http), new FitConnectMetadataDocumentBuilder(),
      new FitConnectJweEncrypter(), $manager, $logs,
      new ApplicationDataProtectionService($manager, new NullLogger()), $this->cases,
    );
    $original_secret = getenv('UNIT_FIT_GATEWAY_SECRET');
    putenv('UNIT_FIT_GATEWAY_SECRET=synthetic-secret');
    try {
      $result = $gateway->deliver($envelope, $log_id);
    }
    finally {
      putenv('UNIT_FIT_GATEWAY_SECRET' . ($original_secret === FALSE ? '' : '=' . $original_secret));
    }
    return ['result' => $result, 'requests' => $requests, 'receiverKey' => $receiver_key, 'logId' => $log_id];
  }

  /**
   * Creates a synthetic application with explicit tenant references.
   */
  private function application(int $id = 1, int $jurisdiction = 10, int $organisation = 0, int $group = 0): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($id);
    $application->method('uuid')->willReturn(sprintf('11111111-1111-4111-8111-%012d', $id));
    $ids = ['field_jurisdiction' => $jurisdiction, 'field_organisation' => $organisation, 'field_group' => $group];
    $application->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($ids[$field]));
    $fields = [];
    foreach ($ids as $name => $target) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('__get')->with('target_id')->willReturn($target);
      $field->method('__isset')->willReturn(TRUE);
      $fields[$name] = $field;
    }
    $application->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    return $application;
  }

  /**
   * Builds TEST configuration using a synthetic config factory.
   */
  private function settings(array $overrides = []): FitConnectSettings {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('fit_connect')->willReturn($overrides + [
      'environment' => 'TEST',
      'client_id' => 'test-client',
      'destination_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
      'reply_enabled' => TRUE,
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('beantragt_delivery.settings')->willReturn($config);
    return FitConnectSettings::fromConfig($factory);
  }

}
