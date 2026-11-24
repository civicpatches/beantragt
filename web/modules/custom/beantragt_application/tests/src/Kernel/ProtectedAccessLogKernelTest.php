<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cover sheet prints are audited at the application without hiding reads.
 */
#[Group('beantragt_application')]
final class ProtectedAccessLogKernelTest extends GroupKernelTestBase {

  private const APPLICATION_UUID = '0f6061fa-92c0-47f4-87af-226de71d2aed';
  private const COVER_UUID = '5b1c6a3e-3f0e-4f43-9a0b-2d8f2f6c9a11';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('beantragt_application', [ProtectedAccessLog::TABLE]);
  }

  /**
   * A cover print needs the application and fills its uuid and reference.
   */
  public function testCoverPrintNeedsApplicationAndFillsIt(): void {
    $log = new ProtectedAccessLog($this->container->get('database'), $this->time(1000));
    try {
      $log->recordSubject('cover_code', self::COVER_UUID, $this->account(), 'cover_printed');
      $this->fail('A cover print without an application must be refused.');
    }
    catch (\InvalidArgumentException) {
    }
    try {
      $log->recordSubject('cover_code', self::COVER_UUID, $this->account(), 'bulk_case_view', $this->application());
      $this->fail('A cover code with a foreign channel must be refused.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->assertSame([], $this->rows());

    $log->recordSubject('cover_code', self::COVER_UUID, $this->account(), 'cover_printed', $this->application());
    $rows = $this->rows();
    $this->assertCount(1, $rows);
    $this->assertSame('cover_code', $rows[0]['subject_type']);
    $this->assertSame(self::COVER_UUID, $rows[0]['subject_uuid']);
    $this->assertSame(self::APPLICATION_UUID, $rows[0]['application_uuid']);
    $this->assertSame('MS-BB-2026-00001', $rows[0]['reference']);
    $this->assertSame('cover_printed', $rows[0]['channel']);
  }

  /**
   * A read right after a cover print writes its own application row.
   */
  public function testReadAfterCoverPrintIsNotDeduped(): void {
    $log = new ProtectedAccessLog($this->container->get('database'), $this->time(1000));
    $log->recordSubject('cover_code', self::COVER_UUID, $this->account(), 'cover_printed', $this->application());
    $log->record($this->application(), $this->account(), 'nachreichung_card');
    $log->record($this->application(), $this->account(), 'nachreichung_card');
    $rows = $this->rows();
    $this->assertSame(['cover_code', 'application'], array_column($rows, 'subject_type'));
    // Bulk subjects still carry no application.
    $log->recordSubject('bulk_case', self::COVER_UUID, $this->account(), 'bulk_case_view');
    $this->assertSame('', $this->rows()[2]['application_uuid']);
  }

  /**
   * The database lookup distinguishes filename lists from later reveals.
   */
  #[DataProvider('revealChannels')]
  public function testAttachmentListDeduplicationIsDirectional(string $channel): void {
    $database = $this->container->get('database');
    (new ProtectedAccessLog($database, $this->time(1000)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(1001)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(1002)))->record($this->application(), $this->account(), $channel);
    $this->assertSame(['attachment_list', $channel], array_column($this->rows(), 'channel'));

    (new ProtectedAccessLog($database, $this->time(2000)))->record($this->application(), $this->account(), $channel);
    (new ProtectedAccessLog($database, $this->time(2001)))->record($this->application(), $this->account(), 'attachment_list');
    (new ProtectedAccessLog($database, $this->time(2002)))->record($this->application(), $this->account(), $channel);
    $this->assertSame(['attachment_list', $channel, $channel], array_column($this->rows(), 'channel'));
  }

  /**
   * Provides channels that reveal application data.
   */
  public static function revealChannels(): array {
    return ['admin data' => ['admin_data'], 'staff api' => ['staff_api']];
  }

  /**
   * All audit rows in insertion order.
   *
   * @return array<int, array<string, string>>
   *   Rows.
   */
  private function rows(): array {
    return array_map(static fn (object $row): array => (array) $row, $this->container->get('database')
      ->select(ProtectedAccessLog::TABLE, 'l')
      ->fields('l', ['subject_type', 'subject_uuid', 'application_uuid', 'reference', 'channel'])
      ->orderBy('id')->execute()->fetchAll());
  }

  /**
   * Fixed clock.
   */
  private function time(int $now): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $time->method('getCurrentTime')->willReturn($now);
    return $time;
  }

  /**
   * Staff account double.
   */
  private function account(): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(8);
    return $account;
  }

  /**
   * Application double.
   */
  private function application(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn(self::APPLICATION_UUID);
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    return $application;
  }

}
