<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Service\FitConnectCaseRepository;
use Drupal\beantragt_delivery\Service\FitConnectReplyApiClient;
use Drupal\beantragt_delivery\Service\FitConnectReplyPoller;
use Drupal\beantragt_delivery\Service\FitConnectReplyVerifier;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests bounded, isolated profile polling using mocked HTTP exclusively.
 */
#[Group('beantragt_delivery')]
final class BoundReplyPollingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Case bindings under test.
   */
  private FitConnectCaseRepository $cases;

  /**
   * Selected synthetic sender IDs observed by mocked token requests.
   */
  private array $senders = [];

  /**
   * Per-context offsets observed by mocked list requests.
   */
  private array $offsets = [];

  /**
   * Isolated polling lock keys.
   */
  private array $locks = [];

  /**
   * Original synthetic-only environment value.
   */
  private string|false $originalSecret;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalSecret = getenv('POLL_FIXTURE');
    putenv('POLL_FIXTURE=synthetic-only');
    require_once dirname(__DIR__, 3) . '/beantragt_delivery.install';
    $database = $this->container->get('database');
    foreach (beantragt_delivery_schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->cases = new FitConnectCaseRepository($database);
    $this->storeConfig(['fit_connect' => ['reply_enabled' => FALSE]]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalSecret === FALSE ? 'POLL_FIXTURE' : 'POLL_FIXTURE=' . $this->originalSecret);
    parent::tearDown();
  }

  /**
   * Two isolated bound contexts poll while global replies remain disabled.
   */
  public function testProfilesPollWithoutGlobalFallbackAndDeduplicateContexts(): void {
    $this->boundCase(1, 10, 'synthetic_a');
    $this->boundCase(2, 10, 'synthetic_a');
    $this->boundCase(3, 20, 'synthetic_b');
    $poller = $this->poller();
    $first = $poller->poll(2);
    $this->assertTrue($first['enabled']);
    $this->assertSame(0, $first['failed']);
    $this->assertSame(['synthetic_a', 'synthetic_b'], $this->senders);
    $this->assertSame([0, 0], $this->offsets);
    $this->assertCount(2, array_unique($this->locks));
    $this->assertNotContains('beantragt_delivery:reply_poll', $this->locks);
    $poller->poll(2);
    $this->assertSame([0, 0, 2, 2], $this->offsets);
    $this->assertSame(0, $this->container->get('state')->get('beantragt_delivery.reply_offset', 0));
  }

  /**
   * Revoked profile grants cause no HTTP and never fall back to another sender.
   */
  public function testRevokedProfileIsSkippedBeforeAnyHttp(): void {
    $this->boundCase(1, 10, 'synthetic_a');
    $config = $this->container->get('config.factory')->get('beantragt_delivery.settings')->getRawData();
    $config['credential_profiles']['synthetic_a']['allowed_scopes'] = [99];
    $this->storeConfig($config);
    $result = $this->poller()->poll();
    $this->assertSame(1, $result['failed']);
    $this->assertSame([], $this->senders);
    $this->assertSame([], $this->offsets);
  }

  /**
   * A context budget and local cursor bound work and prevent starvation.
   */
  public function testContextBudgetRotatesAcrossBindings(): void {
    for ($index = 1; $index <= 7; $index++) {
      $this->boundCase($index, $index, 'synthetic_' . $index);
    }
    $poller = $this->poller();
    $poller->poll();
    $this->assertCount(5, $this->senders);
    $this->assertSame(5, $this->container->get('state')->get('beantragt_delivery.reply_context_cursor'));
    $poller->poll();
    $this->assertCount(7, $this->senders);
    $this->assertSame('synthetic_6', $this->senders[5]);
    $this->assertSame('synthetic_7', $this->senders[6]);
    $this->assertSame([1], $this->cases->boundContextLogIds(1));
    $this->assertSame([7], $this->cases->boundContextLogIds(100, 6));
  }

  /**
   * Ten thousand same-context cases do not consume the next context's budget.
   */
  public function testLargeContextCannotStarveLaterContexts(): void {
    $this->boundCase(1, 10, 'synthetic_a');
    $database = $this->container->get('database');
    $log = $database->select('beantragt_delivery_log', 'd')->fields('d')->condition('id', 1)->execute()->fetchAssoc();
    $case = $database->select('beantragt_fit_case', 'c')->fields('c')->condition('log_id', 1)->execute()->fetchAssoc();
    $logs = $database->insert('beantragt_delivery_log')->fields(array_keys($log));
    $cases = $database->insert('beantragt_fit_case')->fields(array_keys($case));
    for ($id = 2; $id <= 10000; $id++) {
      $log['id'] = $id;
      $log['uuid'] = sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $id);
      $case['log_id'] = $id;
      $case['case_id'] = sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $id);
      $logs->values(array_values($log));
      $cases->values(array_values($case));
    }
    $logs->execute();
    $cases->execute();
    $this->boundCase(10001, 20, 'synthetic_b');
    $this->assertSame([1, 10001], $this->cases->boundContextLogIds());
    $this->poller()->poll();
    $this->assertSame(['synthetic_a', 'synthetic_b'], $this->senders);
    $database->update('beantragt_fit_case')->fields(['private_key' => ''])->condition('log_id', 10001)->execute();
    $this->assertSame([1], $this->cases->boundContextLogIds());
  }

  /**
   * A foreign context is filtered before application loads or reply downloads.
   */
  public function testForeignReplyCannotUseTheSelectedProfile(): void {
    $this->boundCase(1, 10, 'synthetic_a');
    $this->boundCase(2, 20, 'synthetic_b');
    $result = $this->poller(TRUE)->poll();
    $this->assertSame(2, $result['skipped']);
    $this->assertSame(0, $result['failed']);
    $this->assertSame(0, $result['imported']);
    $this->assertSame(0, $result['accepted']);
  }

  /**
   * A large foreign acknowledgement backlog cannot starve a selected context.
   */
  public function testPendingAcknowledgementsAreFilteredBeforeTheBatchLimit(): void {
    $this->boundCase(1, 10, 'synthetic_a');
    $this->boundCase(2, 20, 'synthetic_b');
    $database = $this->container->get('database');
    for ($index = 1; $index <= 52; $index++) {
      $database->insert('beantragt_fit_reply_ack')->fields([
        'reply_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $index),
        'case_id' => sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $index === 52 ? 1 : 2),
        'application_id' => $index === 52 ? 1 : 2,
        'tags' => '{}',
        'state' => 'pending',
        'acceptance_set' => '',
      ])->execute();
    }
    $logs = new DeliveryLogRepository($database, $this->container->get('uuid'), $this->container->get('datetime.time'), new NullLogger());
    $this->assertCount(1, $this->cases->pendingAcks($logs->load(1)));
    $this->assertCount(50, $this->cases->pendingAcks($logs->load(2)));
    $this->assertSame([], $this->cases->pendingAcks(NULL, TRUE));
  }

  /**
   * Inserts only synthetic frozen contexts; no keys or external calls required.
   */
  private function boundCase(int $id, int $scope, string $profile): void {
    $destination = sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $scope);
    $snapshot = [
      'credential_profile' => $profile,
      'destination_id' => $destination,
      'environment' => 'test',
      'schema_uri' => 'urn:synthetic:schema',
      'reply_process_standard' => 'urn:beantragt:process:decision:1',
    ];
    $database = $this->container->get('database');
    $database->insert('beantragt_delivery_log')->fields([
      'id' => $id,
      'uuid' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $id),
      'application_id' => $id,
      'application_uuid' => sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id),
      'adapter' => 'fit_connect',
      'status' => 'accepted',
      'data_hash' => str_repeat('a', 64),
      'created' => 1,
      'changed' => 1,
      'scope_group_id' => $scope,
      'integration_connection_uuid' => sprintf('dddddddd-dddd-4ddd-8ddd-%012d', $scope),
      'service_urn' => 'urn:synthetic:service',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
      'connection_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ])->execute();
    $database->insert('beantragt_fit_case')->fields([
      'log_id' => $id,
      'application_id' => $id,
      'application_uuid' => sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id),
      'scope_hash' => str_repeat('a', 64),
      'client_hash' => str_repeat('b', 64),
      'destination_id' => $destination,
      'case_id' => sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $id),
      'submission_id' => sprintf('ffffffff-ffff-4fff-8fff-%012d', $id),
      'process_standard' => 'urn:beantragt:process:decision:1',
      'private_key' => 'synthetic-unused',
      'public_key' => 'synthetic-unused',
    ])->execute();
    $config = $this->container->get('config.factory')->get('beantragt_delivery.settings')->getRawData();
    $config['credential_profiles'][$profile] = [
      'label' => 'Synthetic profile',
      'client_id' => $profile,
      'client_secret_env' => 'POLL_FIXTURE',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'metadata_schema_version' => '2.1.0',
      'allowed_scopes' => [$scope],
      'confirmed_destinations' => [[
        'scope_group_id' => $scope,
        'destination_id' => $destination,
        'service_urn' => 'urn:synthetic:service',
        'contract_key' => 'fim:S00000096',
        'contract_version' => '1.0',
      ],
      ],
    ];
    $this->storeConfig($config);
  }

  /**
   * Supplies operator fixtures without installing unrelated module services.
   */
  private function storeConfig(array $data): void {
    $this->container->get('config.storage')->write('beantragt_delivery.settings', $data);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
  }

  /**
   * Constructs real clients around an HTTP mock that permits only three routes.
   */
  private function poller(bool $foreign_replies = FALSE): FitConnectReplyPoller {
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($foreign_replies): Response {
      $path = parse_url($url, PHP_URL_PATH);
      $json = static fn(array $data): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
      if (str_ends_with($path, '/token')) {
        $sender = $options['form_params']['client_id'];
        $this->senders[] = $sender;
        return $json(['access_token' => $sender, 'expires_in' => 3600]);
      }
      if (str_ends_with($path, '/.well-known/jwks.json')) {
        return $json(['keys' => []]);
      }
      if (str_ends_with($path, '/v2/replies')) {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $offset = (int) $query['offset'];
        $this->offsets[] = $offset;
        $other = str_contains($options['headers']['Authorization'], 'synthetic_a') ? 2 : 1;
        $replies = $foreign_replies ? [[
          'replyId' => 'aaaaaaaa-aaaa-4aaa-8aaa-000000000001',
          'caseId' => sprintf('eeeeeeee-eeee-4eee-8eee-%012d', $other),
        ],
        ] : [];
        return $json([
          'offset' => $offset,
          'limit' => (int) $query['limit'],
          'count' => count($replies),
          'totalCount' => 100,
          'replies' => $replies,
        ]);
      }
      throw new \LogicException('Unexpected synthetic test HTTP request.');
    });
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (string $key): bool {
      $this->locks[] = $key;
      return TRUE;
    });
    $mutation = new ApplicationDependentMutationLock($manager, $lock);
    $database = $this->container->get('database');
    $uuid = $this->container->get('uuid');
    $time = $this->container->get('datetime.time');
    $responses = new ExternalResponseService(
      new ExternalResponseRepository($database), $manager,
      $this->createMock(FileSystemInterface::class), $this->createMock(FileRepositoryInterface::class),
      $this->createMock(AttachmentScanServiceInterface::class), $mutation,
      $uuid, $time, $this->createMock(NotificationMailer::class),
    );
    $factory = $this->container->get('config.factory');
    return new FitConnectReplyPoller(
      $factory, new FitConnectAccessTokenClient($http), new FitConnectReplyApiClient($http),
      new FitConnectReplyVerifier(), $this->cases, $responses, $manager,
      $mutation, $lock, new DeliveryLogRepository($database, $uuid, $time, new NullLogger()),
      $this->container->get('state'), new IntegrationCredentialProfiles($factory),
    );
  }

}
