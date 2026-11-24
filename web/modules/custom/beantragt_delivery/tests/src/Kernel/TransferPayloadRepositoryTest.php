<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\TransferPayloadRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Proves encrypted payload integrity, owner isolation and retention cleanup.
 */
#[CoversClass(TransferPayloadRepository::class)]
#[Group('beantragt_delivery')]
final class TransferPayloadRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Protected payload repository under test.
   */
  private TransferPayloadRepository $repository;

  /**
   * Original encryption key restored after each test.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('s', 32));
    $database = $this->container->get('database');
    foreach (TransferPayloadRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    // Minimal owner/reference tables exercise the repository's SQL joins.
    $database->schema()->createTable('application', [
      'fields' => ['id' => ['type' => 'int', 'not null' => TRUE]],
      'primary key' => ['id'],
    ]);
    $database->schema()->createTable('beantragt_delivery_log', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'payload_reference' => ['type' => 'varchar_ascii', 'length' => 36],
        'application_id' => ['type' => 'int'],
        'payload_sha256' => ['type' => 'varchar_ascii', 'length' => 64],
        'status' => ['type' => 'varchar_ascii', 'length' => 32],
      ],
      'primary key' => ['id'],
    ]);
    $database->insert('application')->fields(['id' => 1])->execute();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(200000);
    $this->repository = new TransferPayloadRepository(
      $database,
      new ApplicationDataProtectionService($this->container->get('entity_type.manager'), new NullLogger()),
      $this->container->get('uuid'),
      $time,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * SQL contains only randomized ciphertext; verified retries preserve bytes.
   */
  public function testEncryptedStoragePreservesExactBytes(): void {
    $json = '{"synthetic":"SYNTHETIC PRIVATE VALUE","zero":0.0}';
    $reference = $this->repository->store(1, $json);
    $second = $this->repository->store(1, $json);
    $ciphertexts = $this->container->get('database')->select('beantragt_transfer_payload', 'p')->fields('p', ['ciphertext'])->execute()->fetchCol();
    $this->assertCount(2, $ciphertexts);
    $this->assertNotSame($reference, $second);
    $this->assertNotSame($ciphertexts[0], $ciphertexts[1]);
    $this->assertStringNotContainsString('SYNTHETIC PRIVATE VALUE', implode('', $ciphertexts));
    $this->assertStringNotContainsString($json, implode('', $ciphertexts));
    $row = $this->row($reference, $json);
    $this->assertSame($json, $this->repository->verified($row));
    $this->assertSame($json, $this->repository->verified($row));
  }

  /**
   * Missing/foreign references, hash changes and corrupt envelopes fail closed.
   */
  public function testVerificationRejectsTamperingAndForeignOwners(): void {
    $json = '{"synthetic":"SYNTHETIC PRIVATE VALUE"}';
    $reference = $this->repository->store(1, $json);
    $row = $this->row($reference, $json);
    $this->assertIntegrityFailure(array_replace($row, ['payload_reference' => 'missing']));
    $this->assertIntegrityFailure(array_replace($row, ['applicationId' => 2]));
    $this->assertIntegrityFailure(array_replace($row, ['payload_sha256' => hash('sha256', 'different')]));
    $this->assertIntegrityFailure(array_diff_key($row, ['payload_sha256' => TRUE]));
    $database = $this->container->get('database');
    $ciphertext = $database->select('beantragt_transfer_payload', 'p')->fields('p', ['ciphertext'])->condition('uuid', $reference)->execute()->fetchField();
    $protected = json_decode($ciphertext, TRUE, 32, JSON_THROW_ON_ERROR);
    $protected['payload']['ciphertext'] = base64_encode('SYNTHETIC INVALID CIPHERTEXT');
    foreach ([json_encode($protected, JSON_THROW_ON_ERROR), '{"payload":"SYNTHETIC PRIVATE VALUE"}', 'invalid-json'] as $corrupt) {
      $database->update('beantragt_transfer_payload')->fields(['ciphertext' => $corrupt])->condition('uuid', $reference)->execute();
      $this->assertIntegrityFailure($row);
    }
  }

  /**
   * Ciphertext and public hash copying cannot change authenticated IDs.
   */
  public function testCiphertextCannotBeTransplanted(): void {
    $database = $this->container->get('database');
    $first = $this->repository->store(1, '{"synthetic":"first"}');
    $second = $this->repository->store(2, '{"synthetic":"second"}');
    $ciphertext = $database->select('beantragt_transfer_payload', 'p')->fields('p', ['ciphertext'])->condition('uuid', $second)->execute()->fetchField();
    $database->update('beantragt_transfer_payload')->fields(['ciphertext' => $ciphertext])->condition('uuid', $first)->execute();
    $this->assertIntegrityFailure($this->row($first, '{"synthetic":"second"}'));
    $database->update('beantragt_transfer_payload')->fields(['application_id' => 1])->condition('uuid', $second)->execute();
    $this->assertIntegrityFailure($this->row($second, '{"synthetic":"second"}'));
  }

  /**
   * Only the explicit update admits a valid pre-context record, idempotently.
   */
  public function testExplicitLegacyContextMigration(): void {
    $json = '{"synthetic":"legacy"}';
    $reference = $this->repository->store(1, $json);
    $protection = new ApplicationDataProtectionService($this->container->get('entity_type.manager'), new NullLogger());
    $protected = $protection->protectData(['payload' => $json], [
      'sections' => [['fields' => [['id' => 'payload', 'type' => 'text', 'sensitive' => TRUE]]]],
    ]);
    $database = $this->container->get('database');
    $database->update('beantragt_transfer_payload')->fields(['ciphertext' => json_encode($protected, JSON_THROW_ON_ERROR)])->condition('uuid', $reference)->execute();
    $database->insert('beantragt_delivery_log')->fields([
      'application_id' => 1,
      'payload_reference' => $reference,
      'payload_sha256' => hash('sha256', $json),
    ])->execute();
    $this->assertIntegrityFailure($this->row($reference, $json));
    $this->repository->migrateLegacyContext();
    $this->repository->migrateLegacyContext();
    $this->assertSame($json, $this->repository->verified($this->row($reference, $json)));
    $this->repository->deleteReference($reference);
    $this->repository->deleteReference($reference);
    $this->assertSame(0, $this->countPayloads());
  }

  /**
   * Application deletion removes all its payloads and preserves other owners.
   */
  public function testApplicationDeletionIsScopedAndIdempotent(): void {
    $first = $this->repository->store(1, '{}');
    $this->repository->store(1, '{}');
    $other = $this->repository->store(2, '{}');
    $this->repository->deleteForApplicationId(1);
    $this->repository->deleteForApplicationId(1);
    $this->assertIntegrityFailure($this->row($first, '{}'));
    $this->assertSame('{}', $this->repository->verified($this->row($other, '{}', 2)));
    $this->assertSame(1, $this->countPayloads());
  }

  /**
   * Creates the frozen binding needed by payload verification.
   */
  private function row(string $reference, string $json, int $application_id = 1): array {
    return [
      'payload_reference' => $reference,
      'payload_sha256' => hash('sha256', $json),
      'applicationId' => $application_id,
    ];
  }

  /**
   * Asserts a stable, value-free fail-closed result.
   */
  private function assertIntegrityFailure(array $row): void {
    try {
      $this->repository->verified($row);
      $this->fail('Invalid payload was unexpectedly accepted.');
    }
    catch (IntegrationException $exception) {
      $this->assertSame('integration.payload_integrity_failed', $exception->errorCode);
      $this->assertSame('integration.payload_integrity_failed', $exception->getMessage());
      $this->assertSame([], $exception->errors);
    }
  }

  /**
   * Counts remaining protected records.
   */
  private function countPayloads(): int {
    return (int) $this->container->get('database')->select('beantragt_transfer_payload', 'p')->countQuery()->execute()->fetchField();
  }

}
