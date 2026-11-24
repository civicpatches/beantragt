<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the audit trail of protected application reads.
 */
#[CoversClass(ProtectedAccessLog::class)]
#[Group('beantragt_application')]
class ProtectedAccessLogTest extends UnitTestCase {

  /**
   * A first read inserts one row without any value.
   */
  public function testRecordInsertsWhenNoRecentRead(): void {
    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())->method('fields')->with([
      'uid' => 8,
      'application_uuid' => '0f6061fa-92c0-47f4-87af-226de71d2aed',
      'subject_type' => 'application',
      'subject_uuid' => '0f6061fa-92c0-47f4-87af-226de71d2aed',
      'reference' => 'MS-BB-2026-00001',
      'channel' => 'staff_api',
      'created' => 1000,
    ])->willReturnSelf();
    $insert->expects($this->once())->method('execute');
    $database = $this->databaseWithRecent(FALSE);
    $database->expects($this->once())->method('insert')->with(ProtectedAccessLog::TABLE)->willReturn($insert);

    (new ProtectedAccessLog($database, $this->time(1000)))->record($this->application(), $this->account(), 'staff_api');
  }

  /**
   * Re-reads within the dedupe window do not add rows.
   */
  public function testRecordSkipsRecentRead(): void {
    $database = $this->databaseWithRecent('42');
    $database->expects($this->never())->method('insert');

    (new ProtectedAccessLog($database, $this->time(1000)))->record($this->application(), $this->account(), 'admin_data');
  }

  /**
   * Filename reads cannot hide later reveals; reveals still cover list reads.
   */
  #[DataProvider('revealChannels')]
  public function testAttachmentListDeduplicationIsDirectional(string $channel): void {
    $rows = [];
    $database = $this->databaseRecordingRows($rows);
    (new ProtectedAccessLog($database, $this->time(1000)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(1001)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(1002)))->record($this->application(), $this->account(), $channel);
    $this->assertSame(['attachment_list', $channel], array_column($rows, 'channel'));

    // After the window expires, a reveal must also suppress a later list read.
    (new ProtectedAccessLog($database, $this->time(2000)))->record($this->application(), $this->account(), $channel);
    (new ProtectedAccessLog($database, $this->time(2001)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(2002)))->record($this->application(), $this->account(), $channel);
    $this->assertSame(['attachment_list', $channel, $channel], array_column($rows, 'channel'));
  }

  /**
   * Provides channels that reveal application data.
   */
  public static function revealChannels(): array {
    return ['admin data' => ['admin_data'], 'staff api' => ['staff_api']];
  }

  /**
   * Purge deletes rows older than the retention period.
   */
  public function testPurgeUsesRetentionCutoff(): void {
    $delete = $this->createMock(Delete::class);
    $delete->expects($this->once())->method('condition')
      ->with('created', 40_000_000 - ProtectedAccessLog::RETENTION_SECONDS, '<')
      ->willReturnSelf();
    $delete->method('execute')->willReturn(3);
    $database = $this->createMock(Connection::class);
    $database->method('delete')->with(ProtectedAccessLog::TABLE)->willReturn($delete);

    $this->assertSame(3, (new ProtectedAccessLog($database, $this->time(40_000_000)))->purge());
  }

  /**
   * Bulk subjects have no fabricated application identity and fixed channels.
   */
  #[DataProvider('bulkChannels')]
  public function testBulkSubjectRecord(string $type, string $channel): void {
    $uuid = '0f6061fa-92c0-47f4-87af-226de71d2aed';
    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())->method('fields')->with([
      'uid' => 8,
      'application_uuid' => '',
      'subject_type' => $type,
      'subject_uuid' => $uuid,
      'reference' => '',
      'channel' => $channel,
      'created' => 1000,
    ])->willReturnSelf();
    $insert->expects($this->once())->method('execute');
    $database = $this->createMock(Connection::class);
    $database->method('insert')->with(ProtectedAccessLog::TABLE)->willReturn($insert);
    (new ProtectedAccessLog($database, $this->time(1000)))->recordSubject($type, $uuid, $this->account(), $channel);
  }

  /**
   * Database failures propagate to the audited disclosure boundary.
   */
  #[DataProvider('bulkChannels')]
  public function testBulkSubjectWriteFailure(string $type, string $channel): void {
    $database = $this->createMock(Connection::class);
    $database->method('insert')->willThrowException(new \RuntimeException('Audit unavailable'));
    $this->expectExceptionMessage('Audit unavailable');
    (new ProtectedAccessLog($database, $this->time(1000)))->recordSubject($type, '0f6061fa-92c0-47f4-87af-226de71d2aed', $this->account(), $channel);
  }

  /**
   * Rejects unknown subject/channel combinations before inserting an audit row.
   */
  public function testInvalidBulkChannel(): void {
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('insert');
    $this->expectException(\InvalidArgumentException::class);
    (new ProtectedAccessLog($database, $this->time(1000)))->recordSubject('bulk_case', '0f6061fa-92c0-47f4-87af-226de71d2aed', $this->account(), 'bulk_item_download');
  }

  /**
   * Exact contract pairs.
   */
  public static function bulkChannels(): array {
    return [['bulk_case', 'bulk_case_view'], ['bulk_item', 'bulk_item_download']];
  }

  /**
   * Builds a connection whose dedupe lookup returns the given value.
   */
  private function databaseWithRecent(string|false $recent): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($recent);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    return $database;
  }

  /**
   * Models query conditions against the rows written by the service.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Captured rows.
   */
  private function databaseRecordingRows(array &$rows): Connection {
    $conditions = [];
    $fields = [];
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('condition')->willReturnCallback(
      static function (string $field, mixed $value, ?string $operator = NULL) use (&$conditions, $select): SelectInterface {
        $conditions[] = [$field, $value, $operator ?? '='];
        return $select;
      },
    );
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturnCallback(static function () use (&$rows, &$conditions): string|false {
      foreach ($rows as $index => $row) {
        foreach ($conditions as [$field, $value, $operator]) {
          $matches = match ($operator) {
            '=' => $row[$field] === $value,
            '!=' => $row[$field] !== $value,
            '>=' => $row[$field] >= $value,
            default => throw new \LogicException('Unsupported test query operator.'),
          };
          if (!$matches) {
            continue 2;
          }
        }
        return (string) ($index + 1);
      }
      return FALSE;
    });
    $select->method('execute')->willReturn($statement);
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(static function (array $values) use (&$fields, $insert): Insert {
      $fields = $values;
      return $insert;
    });
    $insert->method('execute')->willReturnCallback(static function () use (&$rows, &$fields): void {
      $rows[] = $fields;
    });
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturnCallback(static function () use (&$conditions, $select): SelectInterface {
      $conditions = [];
      return $select;
    });
    $database->method('insert')->willReturn($insert);
    return $database;
  }

  /**
   * Builds a clock.
   */
  private function time(int $now): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $time->method('getCurrentTime')->willReturn($now);
    return $time;
  }

  /**
   * Builds an application double.
   */
  private function application(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('0f6061fa-92c0-47f4-87af-226de71d2aed');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    return $application;
  }

  /**
   * Builds an account double.
   */
  private function account(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(8);
    return $account;
  }

}
