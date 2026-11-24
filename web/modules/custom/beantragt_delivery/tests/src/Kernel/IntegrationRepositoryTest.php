<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * Exercises SQL version immutability, scope binding and active uniqueness.
 */
#[Group('beantragt_delivery')]
final class IntegrationRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];
  /**
   * The SQL repository under test.
   */
  private IntegrationRepository $repository;

  /**
   * Mutable fixture scope used to model a later form transfer.
   */
  private int $formScope = 1;

  /**
   * Current form revision can advance independently of covered transfers.
   */
  private int $currentRevision = 1;

  /**
   * Explicit historical revision fixtures for lifecycle/content differences.
   */
  private array $historicalForms = [];
  private const FORM = '11111111-1111-4111-8111-111111111111';

  /**
   * Number of statuses with the queried flag in the scope (coupling check).
   */
  private int $flaggedStatuses = 0;

  /**
   * Group ids the coupling check searched in.
   */
  private array $searchedGroups = [];

  /**
   * Constructor arguments of the repository under test, without the resolver.
   */
  private array $repositoryArguments = [];

  /**
   * The organisation the form belongs to; NULL = the jurisdiction itself.
   */
  private ?GroupInterface $formOrganisation = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $database = $this->container->get('database');
    foreach (IntegrationRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturnCallback(fn(): string => (string) $this->formScope);
    $group->method('bundle')->willReturn('jur');
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturn($group);
    $group_field = $this->createMock(FieldItemListInterface::class);
    $group_field->method('isEmpty')->willReturn(FALSE);
    $group_field->method('__isset')->willReturn(TRUE);
    $group_field->method('__get')->with('entity')->willReturnCallback(fn(): GroupInterface => $this->formOrganisation ?? $group);
    $schema_field = $this->createMock(FieldItemListInterface::class);
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE);
    $schema_field->method('__get')->with('value')->willReturn(json_encode($fixture['form'], JSON_THROW_ON_ERROR));
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('uuid')->willReturn(self::FORM);
    $form->method('id')->willReturn('10');
    $form->method('getVersion')->willReturn(1);
    $form->method('getRevisionId')->willReturnCallback(fn(): int => $this->currentRevision);
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_group');
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $field === 'field_schema' ? $schema_field : $group_field);
    $form_storage = $this->createMock(ContentEntityStorageInterface::class);
    $form_storage->method('loadRevision')->willReturnCallback(fn(int $revision): FormSchemaInterface => $this->historicalForms[$revision] ?? $form);
    $form_storage->method('load')->willReturn($form);
    $form_storage->method('loadUnchanged')->willReturn($form);
    $form_storage->method('loadByProperties')->willReturn([$form]);
    // Organisation 5 belongs to jurisdiction 1.
    $organisations = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition'] as $method) {
      $organisations->method($method)->willReturnSelf();
    }
    $organisations->method('execute')->willReturn([5 => '5']);
    $group_storage->method('getQuery')->willReturn($organisations);
    $statuses = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'range', 'count'] as $method) {
      $statuses->method($method)->willReturnSelf();
    }
    $statuses->method('condition')->willReturnCallback(function (string $field, mixed $value) use ($statuses): QueryInterface {
      if ($field === 'field_group') {
        $this->searchedGroups = $value;
      }
      return $statuses;
    });
    $statuses->method('execute')->willReturnCallback(fn(): int => $this->flaggedStatuses);
    $status_storage = $this->createMock(EntityStorageInterface::class);
    $status_storage->method('getQuery')->willReturn($statuses);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([
      ['group', $group_storage],
      ['form_schema', $form_storage],
      ['workflow_status', $status_storage],
    ]);
    $manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $type): bool => in_array($type, ['group', 'workflow_status'], TRUE));
    $registry = new SyntheticDataContractRegistry(contractDirectory: dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    $profiles = new IntegrationCredentialProfiles($this->container->get('config.factory'));
    $this->container->get('config.storage')->write('beantragt_delivery.settings', [
      'credential_profiles' => [
        'default' => [
          'all_scopes' => TRUE,
          'allowed_scopes' => [],
          'label' => 'Synthetic test operator',
          'client_id' => 'fake-client',
          'client_secret_env' => 'FAKE_US116_SECRET',
          'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
          'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
          'metadata_schema_version' => '2.1.0',
        ],
      ],
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(100);
    $this->repositoryArguments = [
      $database,
      new Php(),
      $time,
      $manager,
      new ScopeAccess($manager),
      $profiles,
      $registry,
      new IntegrationRules($registry),
    ];
    $this->repository = new IntegrationRepository(...$this->repositoryArguments);
  }

  /**
   * Test connection validation and local check.
   */
  public function testConnectionValidationAndLocalCheck(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    self::assertSame('draft', $connection['status']);
    self::assertNull($connection['last_check_result']);
    $checked = $this->repository->checkConnection($connection['uuid']);
    self::assertEquals(['checked' => 'local', 'valid' => TRUE, 'errors' => []], $checked['last_check_result']);
    $active = $this->repository->transition('connection', $connection['uuid'], 'active');
    self::assertSame('active', $active['status']);
    $this->expectCode('integration.definition_immutable', fn() => $this->repository->update('connection', $active['uuid'], [

      'label' => 'Changed',
    ]));
    $this->expectCode('integration.unknown_key', fn() => $this->repository->create('connection', $this->connection() + [
      'token_url' => 'https://attacker.invalid',
    ], 1));
  }

  /**
   * Display texts and the closure rule stay editable on active connections.
   */
  public function testPresentationIsEditableOnActiveConnectionAndValidated(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    // Every connection is coupled both ways: stored data without follow-up
    // flags yields the defaults.
    self::assertSame([
      'handover_label' => ['de' => '', 'en' => ''],
      'response_label' => ['de' => '', 'en' => ''],
      'approved_label' => ['de' => '', 'en' => ''],
      'rejected_label' => ['de' => '', 'en' => ''],
      'filed_label' => ['de' => '', 'en' => ''],
      'closure_rule' => 'on_response',
      'follow_up' => [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'filed' => 'filed',
        'decision' => 'decision_issued',
        'receipt' => 'filed',
      ],
    ], $connection['presentation']);
    $this->repository->transition('connection', $connection['uuid'], 'active');

    $updated = $this->repository->updatePresentation($connection['uuid'], [
      'handover_label' => ['de' => 'In der E-Akte abgelegt', 'en' => 'Filed in the e-file'],
      'closure_rule' => 'manual',
    ]);
    self::assertSame('active', $updated['status']);
    self::assertSame(['de' => 'In der E-Akte abgelegt', 'en' => 'Filed in the e-file'], $updated['presentation']['handover_label']);
    self::assertSame(['de' => '', 'en' => ''], $updated['presentation']['response_label']);
    $worded = $this->repository->updatePresentation($connection['uuid'], ['approved_label' => ['de' => 'Festsetzung']]);
    self::assertSame(['de' => 'Festsetzung', 'en' => ''], $worded['presentation']['approved_label']);
    $this->expectCode('integration.input_invalid', fn() => $this->repository->updatePresentation($connection['uuid'], ['approved_label' => ['de' => '<b>Festsetzung</b>']]));
    self::assertSame('manual', $updated['presentation']['closure_rule']);
    // A partial change keeps the other parts.
    $again = $this->repository->updatePresentation($connection['uuid'], ['handover_label' => ['de' => 'Abgelegt']]);
    // A language left out keeps its stored text.
    self::assertSame(['de' => 'Abgelegt', 'en' => 'Filed in the e-file'], $again['presentation']['handover_label']);
    self::assertSame(['de' => '', 'en' => ''], $again['presentation']['response_label']);
    self::assertSame('manual', $again['presentation']['closure_rule']);

    // Closure on receipt and follow-up flags; a missing event keeps its flag.
    $coupled = $this->repository->updatePresentation($connection['uuid'], [
      'closure_rule' => 'on_receipt',
      'follow_up' => ['receipt' => 'closed', 'rejected' => 'decision_issued'],
    ]);
    self::assertSame('on_receipt', $coupled['presentation']['closure_rule']);
    self::assertSame([
      'approved' => 'approved',
      'rejected' => 'decision_issued',
      'filed' => 'filed',
      'decision' => 'decision_issued',
      'receipt' => 'closed',
    ], $coupled['presentation']['follow_up']);
    $kept = $this->repository->updatePresentation($connection['uuid'], ['follow_up' => ['filed' => 'closed']]);
    self::assertSame('closed', $kept['presentation']['follow_up']['receipt']);
    self::assertSame('closed', $kept['presentation']['follow_up']['filed']);
    // Stored garbage falls back to the defaults per event.
    self::assertSame('approved', IntegrationRepository::presentation(['follow_up' => ['approved' => 'rejected']])['follow_up']['approved']);
    foreach ([
      [['follow_up' => ['approved' => 'rejected']], '/follow_up/approved'],
      [['follow_up' => ['receipt' => 'decision_issued']], '/follow_up/receipt'],
      [['follow_up' => ['accepted' => 'in_case_system']], '/follow_up/accepted'],
      [['follow_up' => 'filed'], '/follow_up'],
    ] as [$invalid, $pointer]) {
      try {
        $this->repository->updatePresentation($connection['uuid'], $invalid);
        self::fail('Invalid follow-up accepted: ' . $pointer);
      }
      catch (IntegrationException $exception) {
        self::assertSame('integration.input_invalid', $exception->errorCode);
        self::assertSame($pointer, $exception->errors[0]['pointer']);
      }
    }

    foreach ([
      ['closure_rule' => 'on_acceptance'],
      ['handover_label' => ['de' => '<b>Abgelegt</b>']],
      ['handover_label' => ['de' => "Zeile\nzwei"]],
      ['handover_label' => ['de' => str_repeat('x', 121)]],
      ['handover_label' => ['de' => ' Abgelegt']],
      ['handover_label' => ['fr' => 'Classé']],
      ['handover_label' => 'Abgelegt'],
    ] as $invalid) {
      $this->expectCode('integration.input_invalid', fn() => $this->repository->updatePresentation($connection['uuid'], $invalid));
    }
    $this->expectCode('integration.unknown_key', fn() => $this->repository->updatePresentation($connection['uuid'], ['label' => 'Renamed']));
    self::assertSame('Synthetic TEST connection', $this->repository->load('connection', $connection['uuid'])['label']);
  }

  /**
   * Test draft versions and active retired immutability.
   */
  public function testDraftVersionsAndActiveRetiredImmutability(): void {
    $mapping = $this->mapping();
    $first = $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    self::assertSame(1, $first['version']);
    self::assertSame(hash('sha256', IntegrationRules::canonical($first['rules'])), $first['checksum']);
    $second = $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    self::assertSame(2, $second['version']);
    $this->activateFixture($mapping['uuid']);
    $this->expectCode('integration.mapping_version_immutable', fn() => $this->repository->updateVersion($mapping['uuid'], 1, [

      'rules' => [
        'version' => 1,
        'rules' => [],
      ],
    ]));
    $retired = $this->repository->transitionVersion($mapping['uuid'], 1, 'retired');
    self::assertSame('retired', $retired['status']);
    self::assertSame($first['checksum'], $retired['checksum']);
    $this->expectCode('integration.mapping_version_immutable', fn() => $this->repository->updateVersion($mapping['uuid'], 1, []));
    $this->expectCode('integration.mapping_version_immutable', fn() => $this->repository->transitionVersion($mapping['uuid'], 1, 'active'));
  }

  /**
   * Test assignment unique fence and duplicate activation.
   */
  public function testAssignmentUniqueFenceAndDuplicateActivation(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->activateFixture($mapping['uuid']);
    $data = $this->assignment($connection['uuid'], $mapping['uuid']);
    $one = $this->repository->create('assignment', $data, 1);
    $two = $this->repository->create('assignment', $data, 1);
    self::assertSame('active', $this->repository->transition('assignment', $one['uuid'], 'active')['status']);
    self::assertSame('active', $this->repository->transition('assignment', $one['uuid'], 'active')['status']);
    $this->expectCode('integration.assignment_conflict', fn() => $this->repository->transition('assignment', $two['uuid'], 'active'));
    self::assertSame('draft', $this->repository->load('assignment', $two['uuid'])['status']);
    $this->repository->transition('assignment', $one['uuid'], 'inactive');
    self::assertSame('active', $this->repository->transition('assignment', $two['uuid'], 'active')['status']);
  }

  /**
   * Competing repository activations return one success and a domain conflict.
   */
  public function testConcurrentRepositoryActivations(): void {
    $database = $this->container->get('database');
    if ($database->driver() !== 'mysql') {
      $this->markTestSkipped('The local competing-process fixture requires MySQL.');
    }
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $data = $this->assignment($connection['uuid'], $mapping['uuid']);
    $one = $this->repository->create('assignment', $data, 1);
    $two = $this->repository->create('assignment', $data, 1);
    $transaction = $database->startTransaction();
    self::assertSame('active', $this->repository->transition('assignment', $one['uuid'], 'active')['status']);
    $script = <<<'PHP'
require $argv[1] . '/core/tests/bootstrap.php';
require $argv[2];
$options = json_decode($argv[3], true, 64, JSON_THROW_ON_ERROR);
\Drupal\Core\Database\Database::addConnectionInfo('competing', 'default', $options);
$database = \Drupal\Core\Database\Database::getConnection('default', 'competing');
$fixture = new \Drupal\Tests\beantragt_delivery\Kernel\IntegrationRepositoryWorker('fixture');
$repository = $fixture->build($database);
echo "ready\n";
flush();
try {
  $repository->transition('assignment', $argv[4], 'active');
  echo "unexpected_success\n";
  exit(1);
}
catch (\Drupal\beantragt_delivery\Exception\IntegrationException $e) {
  echo json_encode(['status' => $e->status, 'code' => $e->errorCode]);
  exit($e->status === 409 && $e->errorCode === 'integration.assignment_conflict' ? 0 : 2);
}
PHP;
    $process = new Process([
      PHP_BINARY, '-r', $script, $this->root,
      __DIR__ . '/IntegrationRepositoryWorker.php',
      json_encode($database->getConnectionOptions(), JSON_THROW_ON_ERROR),
      $two['uuid'],
    ], timeout: 20);
    $process->start();
    try {
      self::assertTrue($process->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'ready')), $process->getErrorOutput());
      usleep(100000);
      self::assertTrue($process->isRunning());
      unset($transaction);
      self::assertSame(0, $process->wait(), $process->getErrorOutput() . $process->getOutput());
      self::assertStringContainsString('integration.assignment_conflict', $process->getOutput());
      self::assertSame('draft', $this->repository->load('assignment', $two['uuid'])['status']);
    }
    finally {
      $process->stop();
      if (isset($transaction)) {
        $transaction->rollBack();
      }
    }
  }

  /**
   * Test cross scope references and guessed form are rejected.
   */
  public function testCrossScopeReferencesAndGuessedFormAreRejected(): void {
    $connection = $this->repository->create('connection', $this->connection() + [], 1);
    $foreign_data = $this->connection();
    $foreign_data['scope_group_id'] = 2;
    $foreign = $this->repository->create('connection', $foreign_data, 1);
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->create('assignment', $this->assignment($foreign['uuid'], $mapping['uuid']), 1));
    $foreign_mapping = $this->repository->create('mapping', [
      'scope_group_id' => 2,
      'label' => 'Synthetic foreign mapping',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], 1);
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->create('assignment', $this->assignment($connection['uuid'], $foreign_mapping['uuid']), 1));
    $missing = '99999999-9999-4999-8999-999999999999';
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->create('assignment', $this->assignment($missing, $mapping['uuid']), 1));
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->create('assignment', $this->assignment($connection['uuid'], $missing), 1));
    $unknown_version = $this->assignment($connection['uuid'], $mapping['uuid']);
    $unknown_version['mapping_version'] = 99;
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->create('assignment', $unknown_version, 1));
    $data = $this->versionData();
    $data['form_schema_uuid'] = '22222222-2222-4222-8222-222222222222';
    $this->expectCode('integration.form_schema_not_found', fn() => $this->repository->createVersion($mapping['uuid'], $data, 1));
    self::assertCount(1, $this->repository->list('connection', 1));
    self::assertSame($connection['uuid'], $this->repository->list('connection', 1)[0]['uuid']);
  }

  /**
   * Disabling stays possible after operator removal or a form transfer.
   */
  public function testDeactivationAfterPrerequisitesDisappear(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->activateFixture($mapping['uuid']);
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    $this->formScope = 2;
    self::assertSame('inactive', $this->repository->transition('assignment', $assignment['uuid'], 'inactive')['status']);
    $this->container->get('config.storage')->write('beantragt_delivery.settings', ['credential_profiles' => []]);
    $this->container->get('config.factory')->reset('beantragt_delivery.settings');
    self::assertSame('disabled', $this->repository->transition('connection', $connection['uuid'], 'disabled')['status']);
    self::assertNull($this->container->get('database')->select('beantragt_integration_assignment', 'i')->fields('i', [
      'active_key',
    ])->condition('uuid', $assignment['uuid'])->execute()->fetchField());
  }

  /**
   * The real contract rejects incomplete definitions.
   */
  public function testRealContractCannotActivateIncompleteOrArrayMappings(): void {
    $mapping = $this->mapping();
    $data = $this->versionData();
    $data['rules'] = ['version' => 1, 'rules' => []];
    $this->repository->createVersion($mapping['uuid'], $data, 1);
    try {
      $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
      self::fail('An incomplete mapping must never activate.');
    }
    catch (IntegrationException $e) {
      self::assertSame('integration.mapping_incomplete', $e->errorCode);
      self::assertContains('integration.required_target_missing', array_column($e->errors, 'code'));
      self::assertContains('integration.repeating_source_unsupported', array_column($e->errors, 'code'));
    }
    self::assertSame('draft', $this->repository->version($mapping['uuid'], 1)['status']);
    self::assertTrue($this->repository->compatibility($mapping['uuid'], 1, 2)['compatible']);
    self::assertTrue($this->repository->compatibility($mapping['uuid'], 1, 2)['registered']);
  }

  /**
   * Active references prevent identity edits but never prevent emergency stops.
   */
  public function testReferencedLifecycleAndAtomicReplacement(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->mapping();
    $version = $this->repository->createVersion(strtoupper($mapping['uuid']), $this->versionData(), 1);
    self::assertSame($mapping['uuid'], $version['mapping_uuid']);
    self::assertTrue($this->repository->checkVersion($mapping['uuid'], 1)['valid']);
    $active_version = $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    self::assertSame($version['checksum'], $active_version['checksum']);
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    self::assertFalse($this->repository->load('assignment', $assignment['uuid'])['blocked']);
    $disabled = $this->repository->transition('connection', $connection['uuid'], 'disabled');
    self::assertSame($assignment['uuid'], $disabled['used_by'][0]['assignment_uuid']);
    self::assertTrue($this->repository->load('assignment', $assignment['uuid'])['blocked']);
    $this->expectCode('integration.in_use', fn() => $this->repository->update('connection', $connection['uuid'], [
      'destination_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ]));
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $replacement = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $this->expectCode('integration.input_invalid', fn() => $this->repository->replaceActive($assignment['uuid'], $replacement['uuid'] . ' '));
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->replaceActive($assignment['uuid'], '99999999-9999-4999-8999-999999999999'));
    $database = $this->container->get('database');
    $database->update('beantragt_integration_assignment')->fields(['scope_group_id' => 2])->condition('uuid', $replacement['uuid'])->execute();
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->replaceActive($assignment['uuid'], $replacement['uuid']));
    $database->update('beantragt_integration_assignment')->fields(['scope_group_id' => 1])->condition('uuid', $replacement['uuid'])->execute();
    self::assertSame('active', $this->repository->replaceActive($assignment['uuid'], $replacement['uuid'])['status']);
    self::assertSame('inactive', $this->repository->load('assignment', $assignment['uuid'])['status']);
    $retired = $this->repository->transitionVersion($mapping['uuid'], 1, 'retired');
    self::assertSame(IntegrationRules::canonical($active_version['rules']), IntegrationRules::canonical($retired['rules']));
    self::assertSame($active_version['checksum'], $retired['checksum']);
    self::assertTrue($this->repository->load('assignment', $replacement['uuid'])['blocked']);
    $this->repository->transition('connection', $connection['uuid'], 'disabled');
    $this->expectCode('integration.connection_invalid', fn() => $this->repository->transition('connection', $connection['uuid'], 'active'));
  }

  /**
   * Canonical identifiers cannot bypass the active assignment uniqueness fence.
   */
  public function testCanonicalIdentifiersAndUnsafeStrings(): void {
    foreach ([
      'urn:fake:service ',
      ' urn:fake:service',
      "urn:fake:ser\nvice",
      '<script>bad</script>',
      'urn:missing',
    ] as $urn) {
      $data = $this->connection();
      $data['service_urn'] = $urn;
      $this->expectCode('integration.input_invalid', fn() => $this->repository->create('connection', $data, 1));
    }
    foreach (["Fake\nlabel", '<b>Fake</b>', ' Fake label'] as $label) {
      $data = $this->connection();
      $data['label'] = $label;
      $this->expectCode('integration.input_invalid', fn() => $this->repository->create('connection', $data, 1));
    }
    $data = $this->connection();
    $data['service_urn'] = 'URN:FAKE:SERVICE';
    $data['destination_id'] = strtoupper($data['destination_id']);
    $connection = $this->repository->create('connection', $data, 1);
    self::assertSame('urn:fake:service', $connection['service_urn']);
    self::assertSame(strtolower($data['destination_id']), $connection['destination_id']);
    self::assertSame($connection['uuid'], $this->repository->load('connection', strtoupper($connection['uuid']))['uuid']);
  }

  /**
   * Cross-scope body references fail on updates and activation as on creation.
   */
  public function testCrossScopeUpdateAndActivation(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $foreign_data = $this->connection();
    $foreign_data['scope_group_id'] = 2;
    $foreign = $this->repository->create('connection', $foreign_data, 1);
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->update('assignment', $assignment['uuid'], [
      'connection_uuid' => $foreign['uuid'],
    ]));
    $foreign_mapping = $this->repository->create('mapping', [
      'scope_group_id' => 2,
      'label' => 'Foreign',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], 1);
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->update('assignment', $assignment['uuid'], [
      'mapping_uuid' => $foreign_mapping['uuid'],
    ]));
    $this->container->get('database')->update('beantragt_integration_connection')->fields([
      'scope_group_id' => 2,
    ])->condition('uuid', $connection['uuid'])->execute();
    $this->expectCode('integration.reference_not_found', fn() => $this->repository->transition('assignment', $assignment['uuid'], 'active'));
    $this->formScope = 2;
    $this->expectCode('integration.form_schema_not_found', fn() => $this->repository->updateVersion($mapping['uuid'], 1, $this->versionData()));
    $this->expectCode('integration.form_schema_not_found', fn() => $this->repository->transitionVersion($mapping['uuid'], 1, 'active'));
    $this->container->get('database')->update('beantragt_integration_mapping_version')->fields(['scope_group_id' => 2])->condition('mapping_uuid', $mapping['uuid'])->execute();
    $this->expectCode('integration.mapping_version_not_found', fn() => $this->repository->version($mapping['uuid'], 1));
    $this->expectCode('integration.mapping_version_not_found', fn() => $this->repository->updateVersion($mapping['uuid'], 1, []));
    $this->expectCode('integration.mapping_version_not_found', fn() => $this->repository->transitionVersion($mapping['uuid'], 1, 'active'));
    $this->expectCode('integration.mapping_version_not_found', fn() => $this->repository->checkVersion($mapping['uuid'], 1));
    self::assertSame([], $this->repository->load('mapping', $mapping['uuid'])['versions']);
  }

  /**
   * Corruption blocks increases of risk while leaving stop and read paths open.
   */
  public function testImmutableChecksumVerification(): void {
    foreach (['checksum' => str_repeat('0', 64), 'rules' => '{invalid', 'compatible_revisions' => '{invalid'] as $column => $corrupt) {
      $mapping = $this->mapping();
      $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
      $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
      $connection = $this->repository->create('connection', $this->connection(), 1);
      $this->repository->transition('connection', $connection['uuid'], 'active');
      $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
      $this->repository->transition('assignment', $assignment['uuid'], 'active');
      $database = $this->container->get('database');
      $database->update('beantragt_integration_mapping_version')->fields([$column => $corrupt])
        ->condition('mapping_uuid', $mapping['uuid'])->execute();
      $version = $this->repository->version($mapping['uuid'], 1);
      self::assertSame('failed', $version['integrity']);
      self::assertArrayNotHasKey('rules', $version);
      self::assertSame('failed', $this->repository->load('mapping', $mapping['uuid'])['versions'][0]['integrity']);
      self::assertNotEmpty($this->repository->list('assignment', 1));
      self::assertContains('integration.mapping_checksum_mismatch', array_column($this->repository->load('assignment', $assignment['uuid'])['blocked_reasons'], 'code'));
      self::assertSame('failed', $this->repository->readTransferDefinition(1, $assignment['uuid'])['version']['integrity']);
      $transaction = $database->startTransaction();
      $this->expectCode('integration.mapping_checksum_mismatch', fn() => $this->repository->lockTransferDefinition(1, $assignment['uuid']));
      unset($transaction);
      self::assertSame('inactive', $this->repository->transition('assignment', $assignment['uuid'], 'inactive')['status']);
      $this->expectCode('integration.mapping_checksum_mismatch', fn() => $this->repository->transition('assignment', $assignment['uuid'], 'active'));
      $this->expectCode('integration.mapping_checksum_mismatch', fn() => $this->repository->checkVersion($mapping['uuid'], 1));
      $this->expectCode('integration.mapping_checksum_mismatch', fn() => $this->repository->compatibility($mapping['uuid'], 1, 2));
      // Replacing OUT of a corrupt version remains possible; the successor's
      // own mapping must pass every normal activation check.
      $database->update('beantragt_integration_assignment')->fields(['status' => 'active'])
        ->condition('uuid', $assignment['uuid'])->execute();
      $replacement_mapping = $this->mapping();
      $this->repository->createVersion($replacement_mapping['uuid'], $this->versionData(), 1);
      $this->repository->transitionVersion($replacement_mapping['uuid'], 1, 'active');
      $replacement = $this->repository->create('assignment', $this->assignment($connection['uuid'], $replacement_mapping['uuid']), 1);
      self::assertSame('active', $this->repository->replaceActive($assignment['uuid'], $replacement['uuid'])['status']);
      $this->repository->transition('assignment', $replacement['uuid'], 'inactive');
      self::assertSame('retired', $this->repository->transitionVersion($mapping['uuid'], 1, 'retired')['status']);
      self::assertSame('disabled', $this->repository->transition('connection', $connection['uuid'], 'disabled')['status']);
    }
  }

  /**
   * Advisory definition reads perform no tenant-wide lock-table write.
   */
  public function testReadDefinitionNeverTakesScopeLock(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $database = $this->container->get('database');
    $database->delete('beantragt_integration_scope_lock')->execute();
    $transaction = $database->startTransaction();
    self::assertSame($assignment['uuid'], $this->repository->readTransferDefinition(1, $assignment['uuid'])['assignment']['uuid']);
    self::assertSame(0, (int) $database->select('beantragt_integration_scope_lock')->countQuery()->execute()->fetchField());
    unset($transaction);
  }

  /**
   * New revision warnings do not prevent re-enabling covered old assignments.
   */
  public function testCurrentRevisionWarningDoesNotPreventConnectionRecovery(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    $this->currentRevision = 2;
    // A publish/archive revision with identical version and bytes is covered.
    self::assertNotContains('integration.revision_not_covered', array_column($this->repository->load('assignment', $assignment['uuid'])['blocked_reasons'], 'code'));
    self::assertTrue($this->repository->compatibility($mapping['uuid'], 1, 2)['registered']);
    self::assertNotContains('integration.revision_not_covered', array_column($this->repository->list('assignment', 1)[0]['blocked_reasons'], 'code'));
    // A workflow-only change raises the form version but keeps the fields,
    // so the transfer data is the same and the rule stays covered.
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE);
    $this->historicalForms[2] = $this->historicalForm($fixture['form'], 2);
    self::assertNotContains('integration.revision_not_covered', array_column($this->repository->load('assignment', $assignment['uuid'])['blocked_reasons'], 'code'));
    self::assertTrue($this->repository->compatibility($mapping['uuid'], 1, 2)['registered']);
    $this->expectCode('integration.revision_already_covered', fn() => $this->repository->adoptRevision($assignment['uuid'], 2, 1));
    $this->historicalForms[2] = $this->historicalForm(['title' => 'SYNTHETIC changed schema'], 1);
    // Even the same version is uncovered when the content bytes differ.
    self::assertContains('integration.revision_not_covered', array_column($this->repository->load('assignment', $assignment['uuid'])['blocked_reasons'], 'code'));
    self::assertFalse($this->repository->compatibility($mapping['uuid'], 1, 2)['registered']);
    self::assertContains('integration.revision_not_covered', array_column($this->repository->list('assignment', 1)[0]['blocked_reasons'], 'code'));
    $this->repository->transition('connection', $connection['uuid'], 'disabled');
    self::assertSame('active', $this->repository->transition('connection', $connection['uuid'], 'active')['status']);
  }

  /**
   * Repeated adoption reuses one version and already-covered revisions fail.
   */
  public function testAdoptRevisionIsIdempotent(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    // Adoption applies to new content, never identical publish/archive copies.
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE);
    $fixture['form']['title'] = 'SYNTHETIC updated title';
    $this->historicalForms[2] = $this->historicalForm($fixture['form'], 2);
    $first = $this->repository->adoptRevision($assignment['uuid'], 2, 1);
    $second = $this->repository->adoptRevision($assignment['uuid'], 2, 1);
    self::assertSame($first['draft']['version'], $second['draft']['version']);
    self::assertCount(2, $this->repository->load('mapping', $mapping['uuid'])['versions']);
    // A retry after the version went live reuses it instead of minting another.
    $this->repository->transitionVersion($mapping['uuid'], $first['draft']['version'], 'active');
    $retry = $this->repository->adoptRevision($assignment['uuid'], 2, 1);
    self::assertSame($first['draft']['version'], $retry['draft']['version']);
    self::assertSame('active', $retry['draft']['status']);
    self::assertCount(2, $this->repository->load('mapping', $mapping['uuid'])['versions']);
    $this->expectCode('integration.revision_already_covered', fn() => $this->repository->adoptRevision($assignment['uuid'], 1, 1));
    self::assertSame('draft', $this->repository->version($mapping['uuid'], 1)['status']);
  }

  /**
   * Attachment forwarding is an explicit boolean and an active immutable rule.
   */
  public function testAttachmentConsentIsExplicit(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $data = $this->assignment($connection['uuid'], $mapping['uuid']);
    $assignment = $this->repository->create('assignment', $data, 1);
    self::assertFalse($assignment['send_attachments']);
    $this->expectCode('integration.input_invalid', fn() => $this->repository->update('assignment', $assignment['uuid'], ['send_attachments' => 'true']));
    self::assertTrue($this->repository->update('assignment', $assignment['uuid'], ['send_attachments' => TRUE])['send_attachments']);
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    $this->expectCode('integration.definition_immutable', fn() => $this->repository->update('assignment', $assignment['uuid'], ['send_attachments' => FALSE]));
  }

  /**
   * The transfer trigger stays editable on active rules and is validated.
   */
  public function testCouplingIsEditableOnActiveRuleAndChecksTriggerStatus(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    // Existing behaviour by default: plan on submission.
    self::assertSame('submission', $assignment['transfer_trigger']);
    self::assertSame('hand_over', $assignment['trigger_flag']);
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    $bound = $this->repository->readTransferDefinition(1, $assignment['uuid']);

    // A status trigger needs a status with the flag in the jurisdiction or
    // one of its organisations.
    $this->flaggedStatuses = 0;
    try {
      $this->repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status']);
      self::fail('A status trigger without trigger status was accepted.');
    }
    catch (IntegrationException $exception) {
      self::assertSame('integration.coupling_status_missing', $exception->errorCode);
      self::assertSame(422, $exception->status);
      self::assertSame('/trigger_flag', $exception->errors[0]['pointer']);
    }
    self::assertSame([1, 5], $this->searchedGroups);
    self::assertSame('submission', $this->repository->load('assignment', $assignment['uuid'])['transfer_trigger']);

    $this->flaggedStatuses = 1;
    $coupled = $this->repository->updateCoupling($assignment['uuid'], [
      'transfer_trigger' => 'status',
      'trigger_flag' => 'hand_over',
    ]);
    self::assertSame('active', $coupled['status']);
    self::assertSame('status', $coupled['transfer_trigger']);
    self::assertSame('manual', $this->repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'manual'])['transfer_trigger']);
    // The trigger is no binding field: frozen transfers keep their checksum.
    $after = $this->repository->readTransferDefinition(1, $assignment['uuid']);
    self::assertSame($bound['version']['checksum'], $after['version']['checksum']);
    unset($bound['assignment']['transfer_trigger'], $bound['assignment']['changed'], $after['assignment']['transfer_trigger'], $after['assignment']['changed']);
    self::assertSame($bound['assignment'], $after['assignment']);

    foreach ([
      [['transfer_trigger' => 'on_status'], '/transfer_trigger'],
      [['trigger_flag' => 'in_case_system'], '/trigger_flag'],
      [['transfer_trigger' => 7], '/transfer_trigger'],
    ] as [$invalid, $pointer]) {
      try {
        $this->repository->updateCoupling($assignment['uuid'], $invalid);
        self::fail('Invalid coupling accepted: ' . $pointer);
      }
      catch (IntegrationException $exception) {
        self::assertSame('integration.input_invalid', $exception->errorCode);
        self::assertSame($pointer, $exception->errors[0]['pointer']);
      }
    }
    $this->expectCode('integration.unknown_key', fn() => $this->repository->updateCoupling($assignment['uuid'], ['dispatch_mode' => 'automatic']));
    // Drafts take the trigger on creation, with the same checks.
    $this->flaggedStatuses = 0;
    $this->expectCode('integration.coupling_status_missing', fn() => $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']) + ['transfer_trigger' => 'status'], 1));
  }

  /**
   * Only the status trigger asks the workflow of the rule's form.
   *
   * A status trigger needs the hand-over and the in-case-system status. A
   * trigger on submission or by staff needs neither form status. Acceptance
   * moves the case to the standard's in-progress status. The jurisdiction
   * supplies the statuses and the form workflow decides eligibility.
   */
  public function testTriggerAsksTheFormWorkflow(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $this->flaggedStatuses = 1;
    $present = ['hand_over' => FALSE, 'in_case_system' => FALSE];
    $asked = [];
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('formWorkflowHasFlag')->willReturnCallback(function (FormSchemaInterface $form, array $groups, string $flag) use (&$present, &$asked): bool {
      $asked[] = [$form->uuid(), $groups, $flag];
      return $present[$flag] ?? FALSE;
    });
    $repository = new IntegrationRepository(...[...$this->repositoryArguments, NULL, $resolver]);

    // Status trigger without the function: both flags missing, on save.
    $refusal = $this->refusal(fn() => $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']) + ['transfer_trigger' => 'status'], 1));
    self::assertSame([
      'pointer' => '/trigger_flag',
      'code' => 'integration.coupling_status_missing',
      'feature' => 'handOver',
      'missing_flags' => ['hand_over', 'in_case_system'],
      'form_has_organisation' => FALSE,
      'moment' => 'save',
    ], $refusal->errors[0]);
    // A form without organisation: the jurisdiction and its organisations.
    self::assertSame([[self::FORM, [1, 5], 'hand_over'], [self::FORM, [1, 5], 'in_case_system']], $asked);

    // A trigger on submission (the default) saves without the function, on a
    // form without organisation, and the form is not asked.
    $asked = [];
    $assignment = $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    self::assertSame('submission', $assignment['transfer_trigger']);
    self::assertSame([], $repository->load('assignment', $assignment['uuid'])['blocked_reasons']);
    self::assertSame('manual', $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'manual'])['transfer_trigger']);
    self::assertSame([], $asked);

    // The in-case-system status alone cannot satisfy the status trigger.
    $refusal = $this->refusal(fn() => $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status']));
    self::assertSame(['hand_over', 'in_case_system'], $refusal->errors[0]['missing_flags']);
    self::assertSame('manual', $repository->load('assignment', $assignment['uuid'])['transfer_trigger']);
    $present['in_case_system'] = TRUE;
    $refusal = $this->refusal(fn() => $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status']));
    self::assertSame(['hand_over'], $refusal->errors[0]['missing_flags']);
    self::assertSame('save', $refusal->errors[0]['moment']);
    self::assertSame('manual', $repository->load('assignment', $assignment['uuid'])['transfer_trigger']);
    $present['hand_over'] = TRUE;
    self::assertSame('status', $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status'])['transfer_trigger']);

    // A form of an organisation: only that organisation's workflow is asked.
    $jurisdiction_field = $this->createMock(FieldItemListInterface::class);
    $jurisdiction_field->method('isEmpty')->willReturn(FALSE);
    $jurisdiction_field->method('__isset')->willReturn(TRUE);
    $jurisdiction_field->method('__get')->with('entity')->willReturn($this->repositoryArguments[3]->getStorage('group')->load(1));
    $organisation = $this->createMock(GroupInterface::class);
    $organisation->method('id')->willReturn('5');
    $organisation->method('bundle')->willReturn('org');
    $organisation->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_jurisdiction');
    $organisation->method('get')->with('field_jurisdiction')->willReturn($jurisdiction_field);
    $this->formOrganisation = $organisation;
    $asked = [];
    self::assertSame('manual', $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'manual'])['transfer_trigger']);
    // A manual rule asks nothing, also not for its projection.
    self::assertSame([], $asked);
    self::assertSame('status', $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status'])['transfer_trigger']);
    self::assertSame([[self::FORM, [5], 'hand_over'], [self::FORM, [5], 'in_case_system']], array_values(array_unique($asked, SORT_REGULAR)));
  }

  /**
   * Activation is refused with the moment "activation"; the rule shows why.
   *
   * The requirement applies to status triggers. A rule whose form lost the
   * hand-over status after activation carries
   * the reason in blocked_reasons (the form and rule pages link to the
   * form's workflow); it stays a management warning, so transfers still
   * plan.
   */
  public function testActivationRefusalAndBlockedReason(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $present = TRUE;
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('formWorkflowHasFlag')->willReturnCallback(function () use (&$present): bool {
      return $present;
    });
    $repository = new IntegrationRepository(...[...$this->repositoryArguments, NULL, $resolver]);
    $assignment = $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']) + ['transfer_trigger' => 'status'], 1);
    self::assertSame([], $repository->load('assignment', $assignment['uuid'])['blocked_reasons']);

    // The form switched the function off before the activation.
    $present = FALSE;
    $refusal = $this->refusal(fn() => $repository->transition('assignment', $assignment['uuid'], 'active'));
    self::assertSame('activation', $refusal->errors[0]['moment']);
    self::assertSame('handOver', $refusal->errors[0]['feature']);
    $projected = $repository->load('assignment', $assignment['uuid']);
    self::assertTrue($projected['blocked']);
    self::assertSame([
      [
        'pointer' => '/trigger_flag',
        'code' => 'integration.coupling_status_missing',
        'feature' => 'handOver',
        'missing_flags' => ['hand_over', 'in_case_system'],
        'form_has_organisation' => FALSE,
      ],
    ], $projected['blocked_reasons']);

    // Active, then the function goes: the rule stays active and says so.
    $present = TRUE;
    $repository->transition('assignment', $assignment['uuid'], 'active');
    $present = FALSE;
    self::assertSame('active', $repository->load('assignment', $assignment['uuid'])['status']);
    self::assertSame('integration.coupling_status_missing', $repository->load('assignment', $assignment['uuid'])['blocked_reasons'][0]['code']);
    // Switching it off is always possible.
    self::assertSame('inactive', $repository->transition('assignment', $assignment['uuid'], 'inactive')['status']);
  }

  /**
   * Rules on submission or by staff need nothing of the form.
   *
   * The review's case 2d: an active rule on a form without the function is
   * copied for a new form revision and swapped in; it saves, activates and
   * replaces, also on a form without organisation, and carries no blocked
   * reason. A status trigger still requires the hand-over status.
   */
  public function testRulesWithoutStatusTriggerNeedNoFunction(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->expects(self::never())->method('formWorkflowHasFlag');
    $repository = new IntegrationRepository(...[...$this->repositoryArguments, NULL, $resolver]);

    $active = $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    self::assertSame('active', $repository->transition('assignment', $active['uuid'], 'active')['status']);
    self::assertSame([], $repository->load('assignment', $active['uuid'])['blocked_reasons']);
    // The copy for the new revision, by staff, swapped in.
    $copy = $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']) + ['transfer_trigger' => 'manual'], 1);
    self::assertSame('active', $repository->replaceActive($active['uuid'], $copy['uuid'])['status']);
    self::assertSame('inactive', $repository->load('assignment', $active['uuid'])['status']);
    self::assertSame([], $repository->load('assignment', $copy['uuid'])['blocked_reasons']);
    self::assertFalse($repository->load('assignment', $copy['uuid'])['blocked']);
  }

  /**
   * A form outside the rule's scope never decides the rule check.
   *
   * Its workflow belongs to another tenant: the jurisdiction-wide catalogue
   * check of the status trigger stands and the resolver is not asked.
   */
  public function testStatusTriggerOfForeignFormUsesTheScopeCatalogue(): void {
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $asked = 0;
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('formWorkflowHasFlag')->willReturnCallback(function () use (&$asked): bool {
      $asked++;
      return TRUE;
    });
    $repository = new IntegrationRepository(...[...$this->repositoryArguments, NULL, $resolver]);
    $assignment = $repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    // The form moved to another jurisdiction.
    $this->formScope = 2;
    $asked = 0;
    $this->flaggedStatuses = 0;
    $this->expectCode('integration.coupling_status_missing', fn() => $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status']));
    $this->flaggedStatuses = 1;
    self::assertSame('status', $repository->updateCoupling($assignment['uuid'], ['transfer_trigger' => 'status'])['transfer_trigger']);
    self::assertSame([1, 5], $this->searchedGroups);
    self::assertSame(0, $asked);
  }

  /**
   * Returns the integration refusal an operation throws.
   */
  private function refusal(callable $operation): IntegrationException {
    try {
      $operation();
    }
    catch (IntegrationException $exception) {
      return $exception;
    }
    self::fail('Expected an integration refusal.');
  }

  /**
   * Version growth and resource lists have explicit bounds.
   */
  public function testQuotasAndVersionSummaryProjection(): void {
    $mapping = $this->mapping();
    for ($version = 1; $version <= 50; $version++) {
      $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    }
    $this->expectCode('integration.quota_exceeded', fn() => $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1));
    $list = $this->repository->listResult('mapping', 1);
    self::assertSame(['total' => 1, 'truncated' => FALSE], $list['meta']);
    self::assertCount(50, $list['data'][0]['versions']);
    self::assertArrayNotHasKey('rules', $list['data'][0]['versions'][0]);
    self::assertArrayHasKey('rules', $this->repository->load('mapping', $mapping['uuid'])['versions'][0]);
  }

  /**
   * Quotas reject additional resources and list metadata exposes truncation.
   */
  public function testResourceQuotasAndActiveFirstLists(): void {
    $connection = $this->repository->create('connection', $this->connection(), 1);
    $this->repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $this->mapping();
    $this->repository->createVersion($mapping['uuid'], $this->versionData(), 1);
    $this->repository->transitionVersion($mapping['uuid'], 1, 'active');
    $assignment = $this->repository->create('assignment', $this->assignment($connection['uuid'], $mapping['uuid']), 1);
    $database = $this->container->get('database');
    foreach (['connection' => 200, 'mapping' => 200, 'assignment' => 400] as $resource => $limit) {
      $table = 'beantragt_integration_' . $resource;
      $template = $database->select($table, 'i')->fields('i')->range(0, 1)->execute()->fetchAssoc();
      $insert = $database->insert($table)->fields(array_keys($template));
      for ($index = 1; $index < $limit; $index++) {
        $copy = $template;
        $copy['uuid'] = sprintf('00000000-0000-4000-8000-%012d', $index);
        if (isset($copy['status'])) {
          $copy['status'] = 'draft';
        }
        $insert->values($copy);
      }
      $insert->execute();
      $data = match ($resource) {
        'connection' => $this->connection(),
        'mapping' => [
          'scope_group_id' => 1,
          'label' => 'Overflow',
          'contract_key' => 'fim:S00000096',
          'contract_version' => '1.0',
        ],
        'assignment' => $this->assignment($connection['uuid'], $mapping['uuid']),
      };
      $this->expectCode('integration.quota_exceeded', fn() => $this->repository->create($resource, $data, 1));
    }
    $this->repository->transition('assignment', $assignment['uuid'], 'active');
    $list = $this->repository->listResult('assignment', 1);
    self::assertSame(['total' => 400, 'truncated' => TRUE], $list['meta']);
    self::assertCount(200, $list['data']);
    self::assertSame($assignment['uuid'], $list['data'][0]['uuid']);
    self::assertSame($mapping['uuid'], $this->repository->listResult('mapping', 1)['data'][0]['uuid']);
  }

  /**
   * A test-only scalar contract activates through the real repository path.
   */
  public function testSyntheticScalarContractActivation(): void {
    $root = sys_get_temp_dir() . '/integration-scalar-' . bin2hex(random_bytes(8));
    $directory = $root . '/fim/S00000096/1.0';
    mkdir($directory, 0700, TRUE);
    $schema = [
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      '$id' => 'urn:synthetic:scalar',
      'type' => 'object',
      'required' => ['name'],
      'properties' => ['name' => ['type' => 'string']],
    ];
    $bytes = json_encode($schema, JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/schema.json', $bytes);
    $manifest = [
      'key' => 'fim:S00000096',
      'version' => '1.0',
      'sourceUri' => 'urn:synthetic:scalar',
      'sha256' => hash('sha256', $bytes),
    ];
    file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
      require_once __DIR__ . '/IntegrationRepositoryWorker.php';
      $fixture = new IntegrationRepositoryWorker('fixture');
      $repository = $fixture->build($this->container->get('database'), $root, $manifest);
      $mapping = $repository->create('mapping', [
        'scope_group_id' => 1,
        'label' => 'Synthetic scalar mapping',
        'contract_key' => 'fim:S00000096',
        'contract_version' => '1.0',
      ], 1);
      $data = $this->versionData();
      $data['rules'] = ['version' => 1, 'rules' => [['source' => 'synthetic_field_1', 'target' => '/name']]];
      $repository->createVersion($mapping['uuid'], $data, 1);
      self::assertTrue($repository->checkVersion($mapping['uuid'], 1)['valid']);
      self::assertSame('active', $repository->transitionVersion($mapping['uuid'], 1, 'active')['status']);
    }
    finally {
      unlink($directory . '/schema.json');
      unlink($directory . '/manifest.json');
      rmdir($directory);
      for ($level = 1; $level <= 3; $level++) {
        rmdir(dirname($directory, $level));
      }
    }
  }

  /**
   * Expect code.
   */
  private function expectCode(string $code, callable $call): void {
    try {
      $call();
      self::fail('Expected ' . $code);
    }
    catch (IntegrationException $e) {
      self::assertSame($code, $e->errorCode);
    }
  }

  /**
   * Creates a historical content revision in the fixture's tenant.
   */
  private function historicalForm(array $schema, int $version): FormSchemaInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('1');
    $group->method('bundle')->willReturn('jur');
    $group_field = $this->createMock(FieldItemListInterface::class);
    $group_field->method('isEmpty')->willReturn(FALSE);
    $group_field->method('__isset')->willReturn(TRUE);
    $group_field->method('__get')->with('entity')->willReturn($group);
    $schema_field = $this->createMock(FieldItemListInterface::class);
    $schema_field->method('__get')->with('value')->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('uuid')->willReturn(self::FORM);
    $form->method('id')->willReturn('10');
    $form->method('getVersion')->willReturn($version);
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_group');
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $field === 'field_schema' ? $schema_field : $group_field);
    return $form;
  }

  /**
   * Mapping.
   */
  private function mapping(): array {
    return $this->repository->create('mapping', [
      'scope_group_id' => 1,
      'label' => 'Synthetic mapping',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], 1);
  }

  /**
   * Connection.
   */
  private function connection(): array {
    return [
      'scope_group_id' => 1,
      'label' => 'Synthetic TEST connection',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'default',
      'destination_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      'service_urn' => 'urn:fake:service',
      'service_name' => 'Synthetic service',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ];
  }

  /**
   * Version data.
   */
  private function versionData(): array {
    return [
      'form_schema_uuid' => self::FORM,
      'compatible_revisions' => [1],
      'rules' => json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE)['rules'],
    ];
  }

  /**
   * Assignment.
   */
  private function assignment(string $connection, string $mapping): array {
    return [
      'scope_group_id' => 1,
      'form_schema_uuid' => self::FORM,
      'compatible_revisions' => [1],
      'service_urn' => 'urn:fake:service',
      'connection_uuid' => $connection,
      'mapping_uuid' => $mapping,
      'mapping_version' => 1,
      'dispatch_mode' => 'approval',
    ];
  }

  /**
   * Activates the complete synthetic FIM mapping through the repository.
   */
  private function activateFixture(string $mapping): void {
    $this->repository->transitionVersion($mapping, 1, 'active');
  }

}
