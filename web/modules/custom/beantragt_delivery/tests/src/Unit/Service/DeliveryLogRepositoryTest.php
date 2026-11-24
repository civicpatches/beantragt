<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests delivery log normalization.
 *
 * @group beantragt_delivery
 */
final class DeliveryLogRepositoryTest extends UnitTestCase {

  /**
   * Internal load keeps dataHash for snapshot dispatch.
   */
  public function testLoadIncludesDataHashForInternalDispatch(): void {
    $repository = $this->repositoryForSelectRows([$this->rawRow()]);

    $row = $repository->load(8);

    $this->assertIsArray($row);
    $this->assertSame($this->rawRow()['data_hash'], $row['dataHash']);
    $this->assertSame('fit_connect', $row['adapter']);
    $this->assertSame('MS-BB-2026-00121', $row['metadata']['referenceNumber']);
  }

  /**
   * Public/API list output omits dataHash while keeping non-sensitive metadata.
   */
  public function testListForApplicationOmitsDataHash(): void {
    $repository = $this->repositoryForSelectRows([$this->rawRow()]);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(165);

    $rows = $repository->listForApplication($application);

    $this->assertCount(1, $rows);
    $this->assertArrayNotHasKey('dataHash', $rows[0]);
    $this->assertSame('fit_connect', $rows[0]['adapter']);
    $this->assertSame('MS-BB-2026-00121', $rows[0]['metadata']['referenceNumber']);
  }

  /**
   * Application deletion removes every associated delivery log.
   */
  public function testDeleteForApplicationId(): void {
    $delete = $this->createMock(Delete::class);
    $delete->expects($this->once())
      ->method('condition')
      ->with('application_id', 165)
      ->willReturnSelf();
    $delete->expects($this->once())->method('execute')->willReturn(2);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('delete')
      ->with('beantragt_delivery_log')
      ->willReturn($delete);

    $repository = new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertSame(2, $repository->deleteForApplicationId(165));
  }

  /**
   * Invalid application IDs cannot trigger an unscoped delete query.
   */
  public function testDeleteForApplicationIdRejectsNonPositiveId(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('delete');

    $repository = new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertSame(0, $repository->deleteForApplicationId(0));
  }

  /**
   * Builds a repository that returns raw rows from select queries.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Raw database rows.
   */
  private function repositoryForSelectRows(array $rows): DeliveryLogRepository {
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn(new DeliveryLogStatement($rows));

    $database = $this->createMock(Connection::class);
    $database->method('select')->with('beantragt_delivery_log', 'd')->willReturn($select);

    return new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a raw delivery-log database row.
   *
   * @return array<string, mixed>
   *   Raw row.
   */
  private function rawRow(): array {
    return [
      'id' => 8,
      'uuid' => '619aafd3-102e-4fbc-b632-fcfe37ab74a9',
      'application_id' => 165,
      'application_uuid' => '12d7fb9f-b397-4229-afa2-3a9e1659bad6',
      'adapter' => 'fit_connect',
      'target' => '6d6d6add-bd50-40b1-884c-8008f200803b',
      'status' => DeliveryLogRepository::STATUS_FAILED,
      'attempts' => 1,
      'data_hash' => hash('sha256', '{"field":"queued-value"}'),
      'external_id' => '',
      'receipt' => '',
      'last_error' => 'fit_connect.missing_configuration: FIT-Connect configuration is incomplete.',
      'metadata' => json_encode([
        'referenceNumber' => 'MS-BB-2026-00121',
        'schema' => [
          'id' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
          'label' => 'Smoke Antrag: Buergerbuero',
          'version' => 1,
        ],
        'fieldCount' => 4,
        'attachmentCount' => 0,
      ], JSON_THROW_ON_ERROR),
      'created' => 1781700353,
      'changed' => 1781700353,
      'processed' => 1781700353,
    ];
  }

}

/**
 * Minimal iterable database statement for repository unit tests.
 */
final class DeliveryLogStatement implements StatementInterface, \IteratorAggregate {

  /**
   * Next row used by fetch methods.
   */
  private int $fetchIndex = 0;

  /**
   * Constructs the statement.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Result rows.
   */
  public function __construct(
    private readonly array $rows,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryString() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectionTarget(): string {
    return 'default';
  }

  /**
   * {@inheritdoc}
   */
  public function rowCount() {
    return count($this->rows);
  }

  /**
   * {@inheritdoc}
   */
  public function setFetchMode($mode, $a1 = NULL, $a2 = []) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch($mode = NULL, $cursor_orientation = NULL, $cursor_offset = NULL) {
    return $this->fetchAssoc();
  }

  /**
   * {@inheritdoc}
   */
  public function fetchField($index = 0) {
    $row = $this->fetchAssoc();
    if ($row === FALSE) {
      return FALSE;
    }
    return array_values($row)[$index] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchObject(?string $class_name = NULL, array $constructor_arguments = []) {
    $row = $this->fetchAssoc();
    return $row === FALSE ? FALSE : (object) $row;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc() {
    return $this->rows[$this->fetchIndex++] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAll($mode = NULL, $column_index = NULL, $constructor_arguments = NULL) {
    return $this->rows;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchCol($index = 0) {
    return array_map(static fn(array $row): mixed => array_values($row)[$index] ?? NULL, $this->rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    $keyed = [];
    foreach ($this->rows as $row) {
      $values = array_values($row);
      $keyed[$values[$key_index] ?? ''] = $values[$value_index] ?? NULL;
    }
    return $keyed;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchAllAssoc($key, $fetch = NULL) {
    $assoc = [];
    foreach ($this->rows as $row) {
      $assoc[(string) ($row[$key] ?? '')] = $row;
    }
    return $assoc;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Traversable {
    foreach ($this->rows as $row) {
      yield (object) $row;
    }
  }

}
